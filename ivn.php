<?php
require_once 'config.php';

/*
|--------------------------------------------------------------------------
| Inputs
|--------------------------------------------------------------------------
*/
$year         = isset($_GET['year'])      ? trim($_GET['year'])      : date('Y');
$month        = isset($_GET['month'])     ? trim($_GET['month'])     : date('n');
$fa_filter    = isset($_GET['fa_code'])   ? trim($_GET['fa_code'])   : '';
$include_zero = isset($_GET['include_zero']) ? 1 : 0;

if (!is_numeric($year)) die('Invalid year');
if (!is_numeric($month) && strtolower($month) !== 'all') die('Invalid month');

/*
|--------------------------------------------------------------------------
| Date range
|--------------------------------------------------------------------------
*/
if (strtolower($month) === 'all') {
    $start_date = $year . '-01-01';
    $end_date   = $year . '-12-31';
    $period     = 'Year ' . $year;
} else {
    $month      = (int) $month;
    $start_date = sprintf('%04d-%02d-01', $year, $month);
    $end_date   = date('Y-m-t', strtotime($start_date));
    $period     = date('F Y', strtotime($start_date));
}

$conn = getDBConnection();
$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| 1. Fetch items
|    FIX: WHERE i.deleted_at IS NULL — soft-deleted items are now excluded.
|         Previously deleted items could still appear in the report.
|--------------------------------------------------------------------------
*/
$items  = [];
$params = [];
$types  = '';

$sql = "
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
";

if ($fa_filter !== '') {
    $sql     .= " AND i.fa_code LIKE ? ";
    $params[] = '%' . $fa_filter . '%';
    $types   .= 's';
}

$sql .= " ORDER BY i.fa_code ";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) {
    $items[(int) $row['id']] = $row;
}
$stmt->close();

/*
|--------------------------------------------------------------------------
| 2. Item eligibility gate
|    Only show items that have at least one receiving OR issuance record
|    up to (and including) the end of the selected period.
|    This prevents brand-new items with zero history from cluttering the
|    report, and ensures the list updates automatically every day as new
|    receivings / issuances are entered.
|
|    FIX: deleted_at IS NULL added — deleted receivings no longer grant
|         eligibility to an item.
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
| 3. Beginning stock — latest snapshot BEFORE the period starts
|
|    How it works (daily accuracy):
|    For each item we find the receiving row with the highest received_date
|    that is still BEFORE $start_date.  Its beg_stock column is the
|    carry-over balance.  Because receivings are entered daily, this always
|    reflects the true balance up to yesterday (or whenever the last entry
|    was recorded) automatically — no manual monthly rollover needed.
|
|    FIX: deleted_at IS NULL added to both the sub-query and the outer
|         query so that soft-deleted rows cannot contaminate the balance.
|--------------------------------------------------------------------------
*/
$beg_stock = [];
$beg_src   = [];

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
    $id             = (int) $row['item_id'];
    $beg_stock[$id] = (float) $row['beg_stock'];
    $beg_src[$id]   = $row['received_date'];
}
$stmt->close();

/*
|--------------------------------------------------------------------------
| 4. Receivings within the period (daily roll-up)
|
|    Every receiving row entered for any day inside [start_date, end_date]
|    is included.  Because staff enter receivings daily, this figure is
|    always current as of today.
|
|    FIX: deleted_at IS NULL — deleted receiving entries no longer add to
|         the quantities.
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

/*
|--------------------------------------------------------------------------
| 6. Build rows
|--------------------------------------------------------------------------
*/
$rows        = [];
$total_value = 0;
$total_items = 0;

foreach ($items as $id => $i) {

    // Skip items with no warehouse history at all
    if (!isset($validItems[$id])) continue;

    $b   = $beg_stock[$id] ?? 0;
    $src = $beg_src[$id]   ?? null;
    $q   = (float) ($rec[$id]['qty'] ?? 0);
    $oi  = (float) ($rec[$id]['oi']  ?? 0);
    $wp  = (float) ($rec[$id]['wp']  ?? 0);
    $oo  = (float) ($rec[$id]['oo']  ?? 0);
    $rt  = (float) ($rec[$id]['rt']  ?? 0);
    $is  = (float) ($iss[$id]        ?? 0);

    /*
    | include_zero logic
    | ------------------
    | When UNCHECKED (default): hide rows where the item had absolutely
    |   zero beginning balance AND zero movement in the period.
    |   Items that are simply depleted (end = 0 but had activity) still
    |   appear so staff can see what was fully consumed.
    |
    | When CHECKED: show every eligible item, even if all values are 0.
    |   Useful when you need a full master list for a physical count.
    */
    if (!$include_zero
        && $b  == 0 && $q  == 0 && $oi == 0
        && $wp == 0 && $rt == 0 && $is == 0 && $oo == 0) {
        continue;
    }

    $end = $b + $q + $oi + $wp + $rt - $is - $oo;
    if ($end < 0) $end = 0;

    $cost = $end * (float) $i['price'];

    $rows[] = [
        'fa'   => $i['fa_code'],
        'mat'  => $i['mat_type'],
        'pr'   => $i['prmode'],
        'desc' => $i['description'],
        'price'=> (float) $i['price'],
        'beg'  => $b,
        'src'  => $src,
        'rec'  => $q,
        'oi'   => $oi,
        'wp'   => $wp,
        'rt'   => $rt,
        'is'   => $is,
        'oo'   => $oo,
        'end'  => $end,
        'cost' => $cost,
    ];

    $total_items++;
    $total_value += $cost;
}

usort($rows, fn($a, $b) => strcmp($a['fa'], $b['fa']));

$conn->close();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inventory View — <?php echo htmlspecialchars($period); ?></title>
<style>
/* ── reset & base ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    font-size: 13px;
    background: #f0f2f5;
    color: #1a1a2e;
}

/* ── top bar ── */
.topbar {
    background: #1a1a2e;
    color: #fff;
    padding: 14px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 8px rgba(0,0,0,.35);
}
.topbar h1 { font-size: 16px; font-weight: 600; letter-spacing: .4px; }
.topbar .period-badge {
    background: #667eea;
    border-radius: 20px;
    padding: 4px 14px;
    font-size: 12px;
    font-weight: 600;
}

/* ── filter card ── */
.filter-card {
    background: #fff;
    margin: 20px 20px 0;
    border-radius: 10px;
    padding: 16px 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    align-items: flex-end;
}
.filter-card label { display: block; font-size: 11px; font-weight: 600; color: #666; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .5px; }
.filter-card input[type=text],
.filter-card select {
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 7px 10px;
    font-size: 13px;
    background: #fafafa;
    outline: none;
    transition: border-color .15s;
}
.filter-card input[type=text]:focus,
.filter-card select:focus { border-color: #667eea; background: #fff; }
.filter-card .check-wrap {
    display: flex;
    align-items: center;
    gap: 7px;
    padding-bottom: 8px;
    cursor: pointer;
}
.filter-card .check-wrap input { width: 15px; height: 15px; cursor: pointer; accent-color: #667eea; }
.filter-card .check-wrap span { font-size: 13px; }
.btn-run {
    background: #667eea;
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 8px 22px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
}
.btn-run:hover { background: #5a6fd8; }

/* ── summary strip ── */
.summary {
    margin: 14px 20px 0;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.summary .card {
    background: #fff;
    border-radius: 10px;
    padding: 12px 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
    flex: 1;
    min-width: 180px;
}
.summary .card .label { font-size: 11px; font-weight: 600; color: #888; text-transform: uppercase; letter-spacing: .5px; }
.summary .card .value { font-size: 22px; font-weight: 700; color: #1a1a2e; margin-top: 4px; }
.summary .card .value.green { color: #16a34a; }

/* ── table wrapper ── */
.table-wrap {
    margin: 14px 20px 30px;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
    overflow: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
}

thead tr {
    background: #1a1a2e;
    color: #fff;
    position: sticky;
    top: 52px; /* below topbar */
}
thead th {
    padding: 10px 10px;
    text-align: center;
    font-weight: 600;
    letter-spacing: .3px;
    white-space: nowrap;
}
thead th.left { text-align: left; }

tbody tr { border-bottom: 1px solid #f0f0f0; transition: background .1s; }
tbody tr:hover { background: #f5f7ff; }

td {
    padding: 8px 10px;
    text-align: right;
    white-space: nowrap;
    color: #333;
}
td.left { text-align: left; }
td.fa   { font-family: monospace; font-size: 11.5px; color: #444; }
td.desc { max-width: 260px; white-space: normal; word-break: break-word; }
td.zero { color: #bbb; }

.beg-src { font-size: 10px; color: #999; margin-top: 2px; }

/* end stock: highlight negatives (shouldn't happen) and zeros */
td.end-zero { color: #f87171; font-weight: 600; }

/* cost column */
td.cost { font-weight: 600; color: #1a1a2e; }

/* ── empty state ── */
.empty {
    text-align: center;
    padding: 60px 20px;
    color: #aaa;
    font-size: 14px;
}
</style>
</head>
<body>

<!-- Top bar -->
<div class="topbar">
    <h1>📦 Inventory Report</h1>
    <span class="period-badge"><?php echo htmlspecialchars($period); ?></span>
</div>

<!-- Filter form -->
<form method="get" class="filter-card">

    <div>
        <label>Year</label>
        <input type="text" name="year" value="<?php echo htmlspecialchars($year); ?>" size="6" maxlength="4">
    </div>

    <div>
        <label>Month</label>
        <select name="month">
            <option value="all"<?php if (strtolower($_GET['month'] ?? '') === 'all') echo ' selected'; ?>>— Full Year —</option>
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?php echo $m; ?>"<?php if (isset($month) && $month == $m) echo ' selected'; ?>>
                    <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                </option>
            <?php endfor; ?>
        </select>
    </div>

    <div>
        <label>FA Code Filter</label>
        <input type="text" name="fa_code" value="<?php echo htmlspecialchars($fa_filter); ?>" placeholder="e.g. FS-CUT" size="16">
    </div>

    <label class="check-wrap">
        <input type="checkbox" name="include_zero" value="1"<?php if ($include_zero) echo ' checked'; ?>>
        <span>Include zero-balance items</span>
    </label>

    <button type="submit" class="btn-run">Run Report</button>

</form>

<!-- Summary cards -->
<div class="summary">
    <div class="card">
        <div class="label">Period</div>
        <div class="value"><?php echo htmlspecialchars($period); ?></div>
    </div>
    <div class="card">
        <div class="label">Items Shown</div>
        <div class="value"><?php echo number_format($total_items); ?></div>
    </div>
    <div class="card">
        <div class="label">Total Inventory Value</div>
        <div class="value green">¥ <?php echo number_format($total_value, 2); ?></div>
    </div>
    <div class="card">
        <div class="label">Data As Of</div>
        <div class="value" style="font-size:16px"><?php echo date('Y-m-d'); ?></div>
    </div>
</div>

<!-- Table -->
<div class="table-wrap">
<?php if (empty($rows)): ?>
    <div class="empty">No items found for the selected period and filters.</div>
<?php else: ?>
<table>
<thead>
<tr>
    <th class="left">FA Code</th>
    <th class="left">Material</th>
    <th>PR</th>
    <th class="left">Description</th>
    <th>Price</th>
    <th>Beg</th>
    <th>Rec</th>
    <th>OI</th>
    <th>WIP</th>
    <th>Ret</th>
    <th>Iss</th>
    <th>OO</th>
    <th>End</th>
    <th>Cost</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
    <td class="left fa"><?php echo htmlspecialchars($r['fa']); ?></td>
    <td class="left"><?php echo htmlspecialchars($r['mat']); ?></td>
    <td><?php echo htmlspecialchars($r['pr']); ?></td>
    <td class="left desc"><?php echo htmlspecialchars($r['desc']); ?></td>
    <td><?php echo number_format($r['price'], 2); ?></td>
    <td>
        <?php echo number_format($r['beg'], 2); ?>
        <?php if ($r['src']): ?>
            <div class="beg-src">as of <?php echo $r['src']; ?></div>
        <?php endif; ?>
    </td>
    <td><?php echo $r['rec']  == 0 ? '<span class="zero">—</span>' : number_format($r['rec'], 2); ?></td>
    <td><?php echo $r['oi']   == 0 ? '<span class="zero">—</span>' : number_format($r['oi'],  2); ?></td>
    <td><?php echo $r['wp']   == 0 ? '<span class="zero">—</span>' : number_format($r['wp'],  2); ?></td>
    <td><?php echo $r['rt']   == 0 ? '<span class="zero">—</span>' : number_format($r['rt'],  2); ?></td>
    <td><?php echo $r['is']   == 0 ? '<span class="zero">—</span>' : number_format($r['is'],  2); ?></td>
    <td><?php echo $r['oo']   == 0 ? '<span class="zero">—</span>' : number_format($r['oo'],  2); ?></td>
    <td class="<?php echo $r['end'] == 0 ? 'end-zero' : ''; ?>"><?php echo number_format($r['end'], 2); ?></td>
    <td class="cost"><?php echo number_format($r['cost'], 2); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

</body>
</html>
