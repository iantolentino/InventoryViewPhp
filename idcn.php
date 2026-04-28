<?php
require_once 'config.php';

/*
|--------------------------------------------------------------------------
| Input validation
|--------------------------------------------------------------------------
*/
$year         = isset($_GET['year'])         ? trim($_GET['year'])  : date('Y');
$month        = isset($_GET['month'])        ? trim($_GET['month']) : date('n');
$include_zero = isset($_GET['include_zero']) ? 1                    : 0;

if (!is_numeric($year)) die('Invalid year');
if (!is_numeric($month) && strtolower($month) !== 'all') die('Invalid month');

$monthNames = [
    1=>'January', 2=>'February', 3=>'March',    4=>'April',
    5=>'May',     6=>'June',     7=>'July',      8=>'August',
    9=>'September',10=>'October',11=>'November',12=>'December',
];

/*
|--------------------------------------------------------------------------
| Date range
|--------------------------------------------------------------------------
*/
if (strtolower($month) === 'all') {
    $start_date  = $year . '-01-01';
    $end_date    = $year . '-12-31';
    $reportLabel = 'FullYear-' . $year;
} else {
    $month       = (int) $month;
    $start_date  = sprintf('%04d-%02d-01', $year, $month);
    $end_date    = date('Y-m-t', strtotime($start_date));
    $reportLabel = $monthNames[$month] . '-' . $year;
}

$filename = 'InventoryReport_' . $reportLabel . '.csv';

$conn = getDBConnection();
$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| 1. Fetch items
|    FIX: WHERE i.deleted_at IS NULL — deleted items are excluded.
|--------------------------------------------------------------------------
*/
$items = [];

$res = $conn->query("
    SELECT
        i.id,
        i.fa_code,
        i.mat_type,
        i.prmode,
        i.description,
        COALESCE(
            (SELECT price
             FROM pricelists p
             WHERE p.item_id = i.id
             ORDER BY p.id DESC
             LIMIT 1),
            0
        ) AS price
    FROM items i
    WHERE i.deleted_at IS NULL
    ORDER BY i.fa_code
");

while ($row = $res->fetch_assoc()) {
    $items[(int) $row['id']] = $row;
}

/*
|--------------------------------------------------------------------------
| 2. Item eligibility gate
|    Only items that have at least one receiving OR issuance up to the
|    end of the period are eligible.  This list updates automatically
|    every day as new entries are created — no manual refresh needed.
|
|    FIX: deleted_at IS NULL — soft-deleted receivings no longer grant
|         eligibility.
|--------------------------------------------------------------------------
*/
$validItems = [];

$stmt = $conn->prepare("
    SELECT DISTINCT item_id
    FROM receivings
    WHERE received_date <= ?
      AND deleted_at IS NULL
");
$stmt->bind_param('s', $end_date);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) {
    $validItems[(int) $row['item_id']] = true;
}
$stmt->close();

$stmt = $conn->prepare("
    SELECT DISTINCT item_id
    FROM issuances
    WHERE created_at <= ?
");
$stmt->bind_param('s', $end_date);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) {
    $validItems[(int) $row['item_id']] = true;
}
$stmt->close();

/*
|--------------------------------------------------------------------------
| 3. Beginning stock — latest snapshot before the period
|
|    Daily accuracy note:
|    Because receivings are entered with a received_date for each day,
|    this query always picks up the most recent entry before today's
|    month, giving an up-to-date carry-over balance automatically.
|
|    FIX: deleted_at IS NULL on both the sub-query and the outer query.
|--------------------------------------------------------------------------
*/
$beginnings = [];
$beg_dates  = [];

$stmt = $conn->prepare("
    SELECT r.item_id, r.beg_stock, r.received_date
    FROM receivings r
    INNER JOIN (
        SELECT item_id, MAX(received_date) AS d
        FROM receivings
        WHERE received_date < ?
          AND deleted_at IS NULL
        GROUP BY item_id
    ) x
      ON  r.item_id       = x.item_id
      AND r.received_date = x.d
    WHERE r.deleted_at IS NULL
");
$stmt->bind_param('s', $start_date);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) {
    $id              = (int) $row['item_id'];
    $beginnings[$id] = (float) $row['beg_stock'];
    $beg_dates[$id]  = $row['received_date'];
}
$stmt->close();

/*
|--------------------------------------------------------------------------
| 4. Receivings within the period
|    FIX: deleted_at IS NULL — deleted entries no longer add to quantities.
|--------------------------------------------------------------------------
*/
$rec = [];

$stmt = $conn->prepare("
    SELECT
        item_id,
        SUM(quantity)   AS qty,
        SUM(other_in)   AS oi,
        SUM(wip)        AS wp,
        SUM(other_out)  AS oo,
        SUM(return_qty) AS rt
    FROM receivings
    WHERE received_date >= ?
      AND received_date <= ?
      AND deleted_at IS NULL
    GROUP BY item_id
");
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) {
    $rec[(int) $row['item_id']] = $row;
}
$stmt->close();

/*
|--------------------------------------------------------------------------
| 5. Issuances within the period
|--------------------------------------------------------------------------
*/
$iss = [];

$stmt = $conn->prepare("
    SELECT item_id, SUM(quantity) AS t
    FROM issuances
    WHERE created_at >= ?
      AND created_at <= ?
    GROUP BY item_id
");
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) {
    $iss[(int) $row['item_id']] = (float) $row['t'];
}
$stmt->close();
$conn->close();

/*
|--------------------------------------------------------------------------
| 6. Stream CSV output
|--------------------------------------------------------------------------
*/
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel opens accented characters correctly
fwrite($out, "\xEF\xBB\xBF");

// Header row
fputcsv($out, [
    'FA Code',
    'Material Type',
    'PR Mode',
    'Description',
    'Price',
    'Beg Stock',
    'Beg Stock Date',
    'Received',
    'Other In',
    'WIP',
    'Return',
    'Issued',
    'Other Out',
    'End Stock',
    'End Cost',
]);

// Data rows
foreach ($items as $id => $i) {

    // Skip items with no warehouse history
    if (!isset($validItems[$id])) continue;

    $beg   = $beginnings[$id]    ?? 0;
    $bdate = $beg_dates[$id]     ?? '';
    $qty   = (float) ($rec[$id]['qty'] ?? 0);
    $oi    = (float) ($rec[$id]['oi']  ?? 0);
    $wp    = (float) ($rec[$id]['wp']  ?? 0);
    $oo    = (float) ($rec[$id]['oo']  ?? 0);
    $rt    = (float) ($rec[$id]['rt']  ?? 0);
    $issue = (float) ($iss[$id]        ?? 0);
    $price = (float) $i['price'];

    /*
    | include_zero:
    |   OFF  → skip rows where every movement AND the opening balance
    |          are all zero (truly inactive items in this period).
    |          Depleted items (end = 0 but had activity) still appear.
    |   ON   → output every eligible item regardless of values.
    |          Useful for a full physical-count master sheet.
    */
    if (!$include_zero
        && $beg == 0 && $qty == 0 && $oi  == 0
        && $wp  == 0 && $rt  == 0 && $issue == 0 && $oo == 0) {
        continue;
    }

    $end  = $beg + $qty + $oi + $wp + $rt - $issue - $oo;
    if ($end < 0) $end = 0;

    $cost = $end * $price;

    fputcsv($out, [
        $i['fa_code'],
        $i['mat_type'],
        $i['prmode'],
        $i['description'],
        number_format($price, 2, '.', ''),
        number_format($beg,   2, '.', ''),
        $bdate,
        number_format($qty,   2, '.', ''),
        number_format($oi,    2, '.', ''),
        number_format($wp,    2, '.', ''),
        number_format($rt,    2, '.', ''),
        number_format($issue, 2, '.', ''),
        number_format($oo,    2, '.', ''),
        number_format($end,   2, '.', ''),
        number_format($cost,  2, '.', ''),
    ]);
}

fclose($out);
exit;
