
<?php
// inventory_view.php - HTML table to validate inventory calcs (By FA Code)
// Requirements: config.php must expose getDBConnection()

require_once 'config.php';

// ----------------------
// Inputs
// ----------------------
$year         = $_GET['year']        ?? date('Y');
$month        = $_GET['month']       ?? date('n');   // may be 'all'
$fa_filter    = trim($_GET['fa_code'] ?? '');        // filter by FA Code (LIKE search)
$include_zero = isset($_GET['include_zero']) ? 1 : 0;

// Validate basic inputs
if (!is_numeric($year)) {
    http_response_code(400);
    die("Invalid 'year' parameter.");
}
if (!is_numeric($month) && strtolower((string)$month) !== 'all') {
    http_response_code(400);
    die("Invalid 'month' parameter.");
}

// Date range
if (strtolower((string)$month) === 'all') {
    $start_date = "{$year}-01-01";
    $end_date   = "{$year}-12-31";
    $period     = "Year {$year}";
} else {
    $start_date = "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
    $end_date   = date("Y-m-t", strtotime($start_date)); // last day of month
    $month_name = date("F", strtotime($start_date));
    $period     = "{$month_name} {$year}";
}

$conn = getDBConnection();
if (!$conn) { die("DB connection failed."); }
$conn->set_charset('utf8mb4');

// ----------------------
// Fetch Items (with latest price by id DESC)
// Optional FA Code filter
// ----------------------
$items_sql = "
    SELECT DISTINCT
        i.id,
        i.fa_code,
        i.mat_type,
        i.prmode,
        i.description,
        COALESCE((
            SELECT price FROM pricelists p
            WHERE p.item_id = i.id
            ORDER BY p.id DESC
            LIMIT 1
        ), 0) AS price
    FROM items i
    /** WHERE_CLAUSE **/
    ORDER BY i.fa_code
";

$where  = "";
$params = [];
$types  = "";

if ($fa_filter !== '') {
    $where = "WHERE i.fa_code LIKE ?";
    $params[] = "%{$fa_filter}%";
    $types .= "s";
}

$items_sql = str_replace("/** WHERE_CLAUSE **/", $where, $items_sql);
$stmt_items = $conn->prepare($items_sql);
if (!$stmt_items) { die("Items prepare failed: " . htmlspecialchars($conn->error)); }
if ($types !== "") { $stmt_items->bind_param($types, ...$params); }
$stmt_items->execute();
$res_items = $stmt_items->get_result();

$items = [];
while ($row = $res_items->fetch_assoc()) {
    $items[$row['id']] = $row; // index by item_id
}
$stmt_items->close();

// If no items found, show minimal page
if (empty($items)) {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Inventory Test View</title>
        <style>
            body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; }
            .container { max-width: 1200px; margin: 24px auto; padding: 0 16px; }
            .card { border: 1px solid #ddd; border-radius: 8px; padding: 16px; }
            .form-row { display: flex; gap: 12px; flex-wrap: wrap; }
            label { font-weight: 600; }
            input[type="text"], select { padding: 6px 8px; }
            button { padding: 8px 12px; }
            .muted { color: #666; }
        </style>
    </head>
    <body>
    <div class="container">
        <h2>Inventory Test View (By FA Code)</h2>
        <div class="card">
            <form method="get" class="form-row">
                <div>
                    <label>Year</label><br>
                    <input type="text" name="year" value="<?php echo htmlspecialchars($year); ?>">
                </div>
                <div>
                    <label>Month</label><br>
                    <select name="month">
                        <option value="all" <?php echo strtolower((string)$month)==='all'?'selected':''; ?>>All</option>
                        <?php
                        for ($m=1; $m<=12; $m++) {
                            $sel = ((string)$month === (string)$m) ? 'selected' : '';
                            echo "<option value=\"{$m}\" {$sel}>".date('F', mktime(0,0,0,$m,1))."</option>";
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label>FA Code (LIKE)</label><br>
                    <input type="text" name="fa_code" value="<?php echo htmlspecialchars($fa_filter); ?>" placeholder="e.g., FA-001">
                </div>
                <div style="align-self: end;">
                    <label><input type="checkbox" name="include_zero" value="1" <?php echo $include_zero ? 'checked' : ''; ?>> Include zero rows</label>
                </div>
                <div style="align-self: end;">
                    <button type="submit">Run</button>
                </div>
            </form>
        </div>

        <p class="muted">Period: <strong><?php echo htmlspecialchars($period); ?></strong> (<?php echo htmlspecialchars($start_date); ?> to <?php echo htmlspecialchars($end_date); ?>)</p>
        <p>No items matched the FA Code filter.</p>
    </div>
    </body>
    </html>
    <?php
    $conn->close();
    exit;
}

// ----------------------
// Beginning Stock (OLD SYSTEM STYLE):
// Latest non-zero receivings.beg_stock BEFORE $start_date per item.
// We also capture the source date to show it in the UI.
// ----------------------
$beginning_stocks = [];    // value
$beginning_sources = [];   // source date (e.g., 2025-12-22)

$query_beg_open = "
    SELECT item_id, beg_stock, received_date, id
    FROM receivings
    WHERE received_date < ?
      AND COALESCE(beg_stock, 0) > 0
    ORDER BY item_id, received_date DESC, id DESC
";
$stmt_beg = $conn->prepare($query_beg_open);
if (!$stmt_beg) { $conn->close(); die("Beginning prep failed: " . htmlspecialchars($conn->error)); }
$stmt_beg->bind_param("s", $start_date);
$stmt_beg->execute();
$res_beg = $stmt_beg->get_result();

// Iterate and take the first row per item_id (latest by date/id)
while ($row = $res_beg->fetch_assoc()) {
    $iid = (int)$row['item_id'];
    if (!array_key_exists($iid, $beginning_stocks)) {
        $beginning_stocks[$iid] = (float)$row['beg_stock'];
        $beginning_sources[$iid] = $row['received_date']; // e.g., "2025-12-22"
    }
}
$stmt_beg->close();

// ----------------------
// Receivings within period (SUM of components)
// ----------------------
$receivings_data = [];
$query_rec = "SELECT item_id,
                     COALESCE(SUM(quantity),   0) AS qty,
                     COALESCE(SUM(other_in),   0) AS other_in,
                     COALESCE(SUM(wip),        0) AS wip,
                     COALESCE(SUM(other_out),  0) AS other_out,
                     COALESCE(SUM(return_qty), 0) AS returns
              FROM receivings
              WHERE received_date >= ? AND received_date <= ?
              GROUP BY item_id";
$stmt_rec = $conn->prepare($query_rec);
if (!$stmt_rec) { $conn->close(); die("Receivings prep failed: " . htmlspecialchars($conn->error)); }
$stmt_rec->bind_param("ss", $start_date, $end_date);
$stmt_rec->execute();
$res_rec = $stmt_rec->get_result();
while ($row = $res_rec->fetch_assoc()) {
    $receivings_data[$row['item_id']] = $row;
}
$stmt_rec->close();

// ----------------------
// Issuances within period
// ----------------------
$issuances_data = [];
$query_iss = "SELECT item_id, COALESCE(SUM(quantity), 0) AS total
              FROM issuances
              WHERE created_at >= ? AND created_at <= ?
              GROUP BY item_id";
$stmt_iss = $conn->prepare($query_iss);
if (!$stmt_iss) { $conn->close(); die("Issuances prep failed: " . htmlspecialchars($conn->error)); }
$stmt_iss->bind_param("ss", $start_date, $end_date);
$stmt_iss->execute();
$res_iss = $stmt_iss->get_result();
while ($row = $res_iss->fetch_assoc()) {
    $issuances_data[$row['item_id']] = (float)$row['total'];
}
$stmt_iss->close();

// ----------------------
// Build rows (apply include_zero)
// ----------------------
$rows = [];
$total_items = 0;
$total_value = 0.0;

foreach ($items as $item_id => $item) {
    // BEGINNING = latest non-zero beg_stock before start_date (from receivings)
    $beg_stock = $beginning_stocks[$item_id] ?? 0.0;
    $beg_src   = $beginning_sources[$item_id] ?? null;

    $received  = 0.0;
    $other_in  = 0.0;
    $wip       = 0.0;
    $other_out = 0.0;
    $returns   = 0.0;

    if (isset($receivings_data[$item_id])) {
        $rec       = $receivings_data[$item_id];
        $received  = (float)$rec['qty'];
        $other_in  = (float)$rec['other_in'];
        $wip       = (float)$rec['wip'];
        $other_out = (float)$rec['other_out'];
        $returns   = (float)$rec['returns'];
    }

    $issued = $issuances_data[$item_id] ?? 0.0;
    $price  = (float)$item['price'];

    if (!$include_zero
        && $beg_stock == 0.0 && $received == 0.0 && $other_in == 0.0 && $wip == 0.0
        && $returns == 0.0 && $issued == 0.0 && $other_out == 0.0) {
        continue;
    }

    // Detailed Ending formula (same as program logic)
    $end_stock = $beg_stock + $received + $other_in + $wip + $returns - $issued - $other_out;
    if ($end_stock < 0.0) { $end_stock = 0.0; }
    $end_cost  = $end_stock * $price;

    $rows[] = [
        'fa_code'     => $item['fa_code'],
        'mat_type'    => $item['mat_type'],
        'prmode'      => $item['prmode'],
        'description' => $item['description'],
        'price'       => $price,
        'beg_stock'   => $beg_stock,
        'beg_src'     => $beg_src,    // date we used for opening
        'received'    => $received,
        'other_in'    => $other_in,
        'wip'         => $wip,
        'returns'     => $returns,
        'issued'      => $issued,
        'other_out'   => $other_out,
        'end_stock'   => $end_stock,
        'end_cost'    => $end_cost,
    ];

    $total_items++;
    $total_value += $end_cost;
}

// Sorting by FA Code (already ordered, but ensure stable)
usort($rows, function($a, $b) {
    return strcmp($a['fa_code'], $b['fa_code']);
});

// ----------------------
// Render HTML
// ----------------------
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Inventory Test View (Table by FA Code)</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; }
        .container { max-width: 1400px; margin: 24px auto; padding: 0 16px; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 16px; }
        .form-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
        label { font-weight: 600; }
        input[type="text"], select { padding: 6px 8px; }
        button { padding: 8px 12px; }
        table { border-collapse: collapse; width: 100%; margin-top: 16px; font-size: 14px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: right; }
        th:nth-child(1), td:nth-child(1),
        th:nth-child(2), td:nth-child(2),
        th:nth-child(4), td:nth-child(4) { text-align: left; }
        thead th { background: #f7f7f7; position: sticky; top: 0; z-index: 1; }
        .muted { color: #666; }
        .summary { margin-top: 12px; font-weight: 600; }
        .note { font-size: 12px; color: #555; }
        .beg-source { display:block; font-size:11px; color:#777; margin-top:2px; }
    </style>
</head>
<body>
<div class="container">
    <h2>Inventory Test View (By FA Code)</h2>

    <div class="card">
        <form method="get" class="form-row">
            <div>
                <label>Year</label><br>
                <input type="text" name="year" value="<?php echo htmlspecialchars($year); ?>">
            </div>
            <div>
                <label>Month</label><br>
                <select name="month">
                    <option value="all" <?php echo strtolower((string)$month)==='all'?'selected':''; ?>>All</option>
                    <?php
                    for ($m=1; $m<=12; $m++) {
                        $sel = ((string)$month === (string)$m) ? 'selected' : '';
                        echo "<option value=\"{$m}\" {$sel}>".date('F', mktime(0,0,0,$m,1))."</option>";
                    }
                    ?>
                </select>
            </div>
            <div>
                <label>FA Code (LIKE)</label><br>
                <input type="text" name="fa_code" value="<?php echo htmlspecialchars($fa_filter); ?>" placeholder="e.g., FA-001">
            </div>
            <div style="align-self: end;">
                <label><input type="checkbox" name="include_zero" value="1" <?php echo $include_zero ? 'checked' : ''; ?>> Include zero rows</label>
            </div>
            <div style="align-self: end;">
                <button type="submit">Run</button>
            </div>
        </form>

        <p class="muted">Period: <strong><?php echo htmlspecialchars($period); ?></strong> (<?php echo htmlspecialchars($start_date); ?> to <?php echo htmlspecialchars($end_date); ?>)</p>

        <table>
            <thead>
                <tr>
                    <th>FA Code</th>
                    <th>Material Type</th>
                    <th>PR Mode</th>
                    <th>Description</th>
                    <th>Unit Price</th>
                    <th>Beginning Stock</th>
                    <th>Received</th>
                    <th>Other In</th>
                    <th>WIP In</th>
                    <th>Returns</th>
                    <th>Issued</th>
                    <th>Other Out</th>
                    <th>Ending Stock</th>
                    <th>Ending Cost</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if (empty($rows)) {
                echo '<tr><td colspan="14" style="text-align:center;color:#666;">No data for selected filters.</td></tr>';
            } else {
                foreach ($rows as $r) {
                    echo "<tr>";
                    echo "<td>".htmlspecialchars($r['fa_code'])."</td>";
                    echo "<td>".htmlspecialchars($r['mat_type'])."</td>";
                    echo "<td>".htmlspecialchars($r['prmode'])."</td>";
                    echo "<td>".htmlspecialchars($r['description'])."</td>";
                    echo "<td>".number_format($r['price'], 2)."</td>";

                    // Beginning Stock (with source date shown)
                    $title = $r['beg_src'] ? ("Opening beg_stock as of ".$r['beg_src']) : "No opening beg_stock found before start date";
                    echo "<td title=\"".htmlspecialchars($title)."\">".number_format($r['beg_stock'], 2);
                    if (!empty($r['beg_src'])) {
                        echo "<span class='beg-source'>as of ".htmlspecialchars($r['beg_src'])."</span>";
                    }
                    echo "</td>";

                    echo "<td>".number_format($r['received'], 2)."</td>";
                    echo "<td>".number_format($r['other_in'], 2)."</td>";
                    echo "<td>".number_format($r['wip'], 2)."</td>";
                    echo "<td>".number_format($r['returns'], 2)."</td>";
                    echo "<td>".number_format($r['issued'], 2)."</td>";
                    echo "<td>".number_format($r['other_out'], 2)."</td>";
                    echo "<td>".number_format($r['end_stock'], 2)."</td>";
                    echo "<td>".number_format($r['end_cost'], 2)."</td>";
                    echo "</tr>";
                }
            }
            ?>
            </tbody>
        </table>

        <p class="summary">
            Total Items: <?php echo number_format($total_items); ?> &nbsp; | &nbsp;
            Total Inventory Value: <?php echo number_format($total_value, 2); ?>
        </p>
        <p class="note">
            <strong>Beginning Stock</strong> is the latest non-zero <code>receivings.beg_stock</code> before the period start (e.g., 12-22-2025 → 14).<br>
            <strong>Ending Stock</strong> = Beginning + Received + Other In + WIP In + Returns − Issued − Other Out (floored at 0).<br>
            <strong>Ending Cost</strong> = Ending Stock × Unit Price (latest by <em>pricelists.id DESC</em>).
        </p>
    </div>
</div>
</body>
</html>
<?php
$conn->close();
