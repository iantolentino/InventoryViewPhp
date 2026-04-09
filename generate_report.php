<?php
require_once 'config.php';

$currentYear=date('Y');
$currentMonth=date('n');

$months=[
'January','February','March','April','May','June',
'July','August','September','October','November','December'
];

$yearOptions='';
for($y=2020;$y<=$currentYear+1;$y++){
$selected=($y==$currentYear)?'selected':'';
$yearOptions.="<option value='$y' $selected>$y</option>";
}

$monthOptions='';
for($m=1;$m<=12;$m++){
$selected=($m==$currentMonth)?'selected':'';
$monthOptions.="<option value='$m' $selected>".$months[$m-1]."</option>";
}
?>
<!DOCTYPE html>
<html>
<head>
<title>EMRIS Inventory</title>
<style>
body{background:#121212;color:white;font-family:Segoe UI}
.container{width:400px;margin:auto;margin-top:120px;background:#1e1e1e;padding:25px}
select{width:100%;padding:10px;margin-bottom:15px;background:#2c2c2c;color:white}
button{padding:12px;width:100%;background:#667eea;color:white;border:0}
.loading{display:none;text-align:center;margin-top:10px}
.loading.show{display:block}
#error{color:red;margin-bottom:10px}
</style>
</head>

<body>

<div class="container">

<h3>Inventory CSV Generator</h3>

<div id="error"></div>

<label>Year:</label>
<select id="year">
<option value="">Select</option>
<?php echo $yearOptions;?>
</select>

<label>Month:</label>
<select id="month">
<option value="">Select</option>
<option value="all">Full Year</option>
<?php echo $monthOptions;?>
</select>

<label>
<input type="checkbox" id="include_zero" value="1">
Include Zero Items
</label>

<br><br>

<button onclick="downloadCSV()">
Download CSV
</button>

<div class="loading" id="loading">
Preparing CSV...
</div>

</div>

<script>

function downloadCSV(){

let year=document.getElementById('year').value;
let month=document.getElementById('month').value;
let include_zero=document.getElementById('include_zero').checked?1:0;
let err=document.getElementById('error');

if(!year||!month){
err.innerText="Select Year and Month";
return;
}

// 🔥 Download Trigger ONLY
window.location.href=
"inventory_download_csv.php"+
"?year="+year+
"&month="+month+
"&include_zero="+include_zero;

// ✅ stop everything immediately
document.getElementById('loading').classList.remove('show');

}
</script>

</body>
</html>