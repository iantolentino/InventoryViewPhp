<?php
require_once 'config.php';

$currentYear  = (int) date('Y');
$currentMonth = (int) date('n');

$monthNames = [
    1=>'January', 2=>'February',  3=>'March',     4=>'April',
    5=>'May',     6=>'June',      7=>'July',       8=>'August',
    9=>'September',10=>'October', 11=>'November', 12=>'December',
];

$yearOptions = '';
for ($y = 2020; $y <= $currentYear + 1; $y++) {
    $sel = ($y === $currentYear) ? ' selected' : '';
    $yearOptions .= "<option value=\"{$y}\"{$sel}>{$y}</option>";
}

$monthOptions = '';
foreach ($monthNames as $num => $name) {
    $sel = ($num === $currentMonth) ? ' selected' : '';
    $monthOptions .= "<option value=\"{$num}\"{$sel}>{$name}</option>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>EMRIS — Inventory Report</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: #0f0f1a;
    color: #e0e0f0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
}

.card {
    background: #1a1a2e;
    border: 1px solid #2a2a4a;
    border-radius: 16px;
    padding: 36px 32px;
    width: 380px;
    box-shadow: 0 8px 32px rgba(0,0,0,.5);
}

.logo {
    text-align: center;
    margin-bottom: 28px;
}
.logo .icon {
    font-size: 36px;
    display: block;
    margin-bottom: 6px;
}
.logo h2 {
    font-size: 18px;
    font-weight: 700;
    letter-spacing: .5px;
    color: #fff;
}
.logo p {
    font-size: 12px;
    color: #667;
    margin-top: 4px;
}

.field { margin-bottom: 16px; }
.field label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: #888;
    margin-bottom: 6px;
}
.field select,
.field input[type=text] {
    width: 100%;
    padding: 10px 12px;
    background: #12122a;
    border: 1px solid #2a2a4a;
    border-radius: 8px;
    color: #e0e0f0;
    font-size: 14px;
    outline: none;
    transition: border-color .15s;
}
.field select:focus,
.field input[type=text]:focus { border-color: #667eea; }

.checkbox-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 22px;
    cursor: pointer;
}
.checkbox-wrap input[type=checkbox] {
    width: 16px;
    height: 16px;
    accent-color: #667eea;
    cursor: pointer;
}
.checkbox-wrap span {
    font-size: 13px;
    color: #aab;
}

.btn-row { display: flex; gap: 10px; }

.btn {
    flex: 1;
    padding: 12px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: opacity .15s, transform .1s;
}
.btn:active { transform: scale(.97); }
.btn:hover  { opacity: .9; }

.btn-csv  { background: #667eea; color: #fff; }
.btn-view { background: #2a2a4a; color: #bbc; border: 1px solid #3a3a6a; }

.status {
    text-align: center;
    margin-top: 14px;
    font-size: 12px;
    color: #667;
    min-height: 18px;
}
.status.active { color: #a0d4a0; }
.status.error  { color: #f87171; }

.divider {
    border: none;
    border-top: 1px solid #2a2a4a;
    margin: 20px 0;
}

.footer-note {
    text-align: center;
    font-size: 11px;
    color: #555;
}
</style>
</head>
<body>

<div class="card">

    <div class="logo">
        <span class="icon">📦</span>
        <h2>EMRIS Inventory</h2>
        <p>Select a period to generate a report</p>
    </div>

    <div id="errorMsg" class="status error"></div>

    <div class="field">
        <label>Year</label>
        <select id="year">
            <option value="">— Select Year —</option>
            <?php echo $yearOptions; ?>
        </select>
    </div>

    <div class="field">
        <label>Month</label>
        <select id="month">
            <option value="">— Select Month —</option>
            <option value="all">Full Year</option>
            <?php echo $monthOptions; ?>
        </select>
    </div>

    <label class="checkbox-wrap">
        <input type="checkbox" id="include_zero" value="1">
        <span>Include zero-balance items (full master list)</span>
    </label>

    <div class="btn-row">
        <button class="btn btn-view" onclick="openView()">👁 View</button>
        <button class="btn btn-csv"  onclick="downloadCSV()">⬇ Download CSV</button>
    </div>

    <div id="status" class="status"></div>

    <hr class="divider">

    <p class="footer-note">Data updates daily — reflects today's entries automatically.</p>

</div>

<script>
function getParams() {
    const year         = document.getElementById('year').value;
    const month        = document.getElementById('month').value;
    const include_zero = document.getElementById('include_zero').checked ? 1 : 0;
    const err          = document.getElementById('errorMsg');

    if (!year || !month) {
        err.textContent = 'Please select both a year and a month.';
        return null;
    }
    err.textContent = '';
    return `year=${year}&month=${month}&include_zero=${include_zero}`;
}

function downloadCSV() {
    const p = getParams();
    if (!p) return;

    const status = document.getElementById('status');
    status.textContent = 'Preparing CSV…';
    status.className   = 'status active';

    window.location.href = 'inventory_download_csv.php?' + p;

    // Clear message after a moment
    setTimeout(() => {
        status.textContent = '';
        status.className   = 'status';
    }, 3000);
}

function openView() {
    const p = getParams();
    if (!p) return;
    window.open('inventory_view.php?' + p, '_blank');
}
</script>

</body>
</html>
