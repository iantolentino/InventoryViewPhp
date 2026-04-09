<?php
require_once 'config.php';

$year=$_GET['year'];
$month=$_GET['month'];
$include_zero=isset($_GET['include_zero'])?1:0;

$months=[
1=>'January','February','March','April','May','June',
'July','August','September','October','November','December'
];

if($month==="all"){
$start_date="{$year}-01-01";
$end_date="{$year}-12-31";
$reportMonth="FullYear";
}else{
$start_date="{$year}-".str_pad($month,2,'0',STR_PAD_LEFT)."-01";
$end_date=date("Y-m-t",strtotime($start_date));
$reportMonth=$months[intval($month)];
}

$filename="InventoryReport_".$reportMonth."-".$year.".csv";

$conn=getDBConnection();
$conn->set_charset('utf8mb4');


// =====================
// FETCH ITEMS
// =====================
$items=[];
$res=$conn->query("
SELECT DISTINCT
i.id,i.fa_code,i.mat_type,
i.prmode,i.description,
COALESCE(
(SELECT price FROM pricelists p
WHERE p.item_id=i.id
ORDER BY id DESC LIMIT 1),0) price
FROM items i
ORDER BY i.fa_code
");

while($row=$res->fetch_assoc())
$items[$row['id']]=$row;


// =====================
// ✅ BEGINNING STOCK FIX
// =====================
// MATCHES YOUR VIEW LOGIC EXACTLY

$beginnings=[];

$stmt=$conn->prepare("
SELECT r.item_id,r.beg_stock
FROM receivings r
INNER JOIN(
SELECT item_id,
MAX(received_date) latest_date
FROM receivings
WHERE received_date < ?
AND beg_stock > 0
GROUP BY item_id
)x
ON r.item_id=x.item_id
AND r.received_date=x.latest_date
");

$stmt->bind_param("s",$start_date);
$stmt->execute();
$r=$stmt->get_result();

while($row=$r->fetch_assoc()){
$beginnings[$row['item_id']]=$row['beg_stock'];
}


// =====================
// RECEIVINGS
// =====================
$rec=[];

$stmt=$conn->prepare("
SELECT item_id,
SUM(quantity)qty,
SUM(other_in)oi,
SUM(wip)wp,
SUM(other_out)oo,
SUM(return_qty)rt
FROM receivings
WHERE received_date>=?
AND received_date<=?
GROUP BY item_id
");

$stmt->bind_param("ss",$start_date,$end_date);
$stmt->execute();
$r=$stmt->get_result();

while($row=$r->fetch_assoc())
$rec[$row['item_id']]=$row;


// =====================
// ISSUANCES
// =====================
$iss=[];

$stmt=$conn->prepare("
SELECT item_id,SUM(quantity)t
FROM issuances
WHERE created_at>=?
AND created_at<=?
GROUP BY item_id
");

$stmt->bind_param("ss",$start_date,$end_date);
$stmt->execute();
$r=$stmt->get_result();

while($row=$r->fetch_assoc())
$iss[$row['item_id']]=$row['t'];


// =====================
// CSV
// =====================
header('Content-Type:text/csv');
header('Content-Disposition: attachment; filename="'.$filename.'"');

$out=fopen('php://output','w');

fputcsv($out,[
'FA_Code','Material Type','PR Mode','Item',
'Price (¥)','Beg Stock','Received','Other In',
'WIP','Return','Issued','Other Out',
'WIP Issued','End Stock','End Cost (¥)'
]);


// =====================
// INVENTORY FORMULA
// =====================

foreach($items as $id=>$i){

$beg=$beginnings[$id]??0;
$qty=$rec[$id]['qty']??0;
$oin=$rec[$id]['oi']??0;
$wp =$rec[$id]['wp']??0;
$oo =$rec[$id]['oo']??0;
$ret=$rec[$id]['rt']??0;
$issue=$iss[$id]??0;
$price=$i['price'];

if(!$include_zero&&
$beg==0&&$qty==0&&$oin==0&&$wp==0
&&$ret==0&&$issue==0&&$oo==0)
continue;

$end=$beg+$qty+$oin+$wp+$ret-$issue-$oo;
if($end<0)$end=0;

$cost=$end*$price;

fputcsv($out,[
$i['fa_code'],
$i['mat_type'],
$i['prmode'],
$i['description'],
number_format($price,2,'.',''),
number_format($beg,2,'.',''),
number_format($qty,2,'.',''),
number_format($oin,2,'.',''),
number_format($wp,2,'.',''),
number_format($ret,2,'.',''),
number_format($issue,2,'.',''),
number_format($oo,2,'.',''),
0,
number_format($end,2,'.',''),
number_format($cost,2,'.','')
]);

}

fclose($out);
exit;