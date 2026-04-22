<?php
require_once "config/session.php";
require_once "config/db.php";


/* ================= DASHBOARD PROTECTION ================= */

if($_SESSION['account_type'] !== "business_owner"){
    header("Location: more.php");
    exit;
}

/* BUSINESS OWNER ID (IMPORTANT: DO NOT MIX WITH VIEW ID) */
$business_id = $_SESSION['user_id'];


/* ================= FILTER ================= */

$selectedYear  = isset($_GET['year'])  ? (int)$_GET['year']  : date("Y");
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : date("n");

if($selectedMonth < 1 || $selectedMonth > 12){
    $selectedMonth = date("n");
}


/* ================= YEARS ================= */

$years=[];
for($y=2026;$y>=2020;$y--){
    $years[]=$y;
}


/* ================= MONTHLY SALES ================= */

$monthlySales=array_fill(1,12,0);
$monthlyFollowers = array_fill(1,12,0);

$stmt=$conn->prepare("
SELECT MONTH(created_at) month,
SUM(price*quantity) total
FROM orders
WHERE business_id=?
AND status='Completed'
AND YEAR(created_at)=?
GROUP BY MONTH(created_at)
");

$stmt->bind_param("ii",$business_id,$selectedYear);
$stmt->execute();
$res=$stmt->get_result();

while($row=$res->fetch_assoc()){
    $monthlySales[$row['month']]=$row['total'];
}

/* ================= MONTHLY FOLLOWERS ================= */
$followerMonthlyStmt = $conn->prepare("
SELECT MONTH(created_at) month, COUNT(*) total
FROM business_followers
WHERE business_id = ?
AND YEAR(created_at) = ?
GROUP BY MONTH(created_at)
");

$followerMonthlyStmt->bind_param("ii", $business_id, $selectedYear);
$followerMonthlyStmt->execute();
$resFollowers = $followerMonthlyStmt->get_result();

while($row = $resFollowers->fetch_assoc()){
    $monthlyFollowers[$row['month']] = $row['total'];
}


/* ================= DAILY SALES ================= */

$daysInMonth=cal_days_in_month(CAL_GREGORIAN,$selectedMonth,$selectedYear);

$dailySales=[];
$dailyOrders=[];
$dailyCustomers=[];

for($i=1;$i<=$daysInMonth;$i++){
    $dailySales[$i]=0;
    $dailyOrders[$i]=0;
    $dailyCustomers[$i]=0;
}

$stmt2=$conn->prepare("
SELECT 
DAY(created_at) day,
SUM(price*quantity) total,
COUNT(DISTINCT order_code) orders_count,
COUNT(DISTINCT consumer_id) customers
FROM orders
WHERE business_id=?
AND status='Completed'
AND YEAR(created_at)=?
AND MONTH(created_at)=?
GROUP BY DAY(created_at)
");

$stmt2->bind_param("iii",$business_id,$selectedYear,$selectedMonth);
$stmt2->execute();
$res2=$stmt2->get_result();

while($row=$res2->fetch_assoc()){
    $d=$row['day'];
    $dailySales[$d]=$row['total'];
    $dailyOrders[$d]=$row['orders_count'];
    $dailyCustomers[$d]=$row['customers'];
}


/* ================= MONTH TOTALS ================= */

$totalStmt=$conn->prepare("
SELECT 
SUM(price*quantity) total_sales,
COUNT(DISTINCT order_code) total_orders,
COUNT(DISTINCT consumer_id) total_customers
FROM orders
WHERE business_id=?
AND status='Completed'
AND YEAR(created_at)=?
AND MONTH(created_at)=?
");

$totalStmt->bind_param("iii",$business_id,$selectedYear,$selectedMonth);
$totalStmt->execute();
$totals=$totalStmt->get_result()->fetch_assoc();

$totalSales=$totals['total_sales'] ?? 0;
$totalOrders=$totals['total_orders'] ?? 0;
$totalCustomers=$totals['total_customers'] ?? 0;


/* ================= REAL VISITORS ================= */

$visitorStmt=$conn->prepare("
SELECT COUNT(*) AS visitors
FROM business_visits
WHERE business_id=?
AND YEAR(visited_at)=?
AND MONTH(visited_at)=?
");

$visitorStmt->bind_param("iii",$business_id,$selectedYear,$selectedMonth);
$visitorStmt->execute();

$totalVisitors=$visitorStmt->get_result()->fetch_assoc()['visitors'] ?? 0;

/* ================= FOLLOWERS ================= */
$followerStmt = $conn->prepare("
SELECT COUNT(*) AS followers
FROM business_followers
WHERE business_id = ?
");

$followerStmt->bind_param("i", $business_id);
$followerStmt->execute();

$totalFollowers = $followerStmt->get_result()->fetch_assoc()['followers'] ?? 0;


/* DAILY PRODUCTS */
$selectedDay = isset($_GET['day']) ? (int)$_GET['day'] : null;

$dailyProducts = [];
$dailyTotalRevenue = 0;

/* DAILY GRAPH DATA (PER HOUR) */
$hourlySales = array_fill(0,24,0);

if($selectedDay){

    /* PRODUCTS QUERY */
    $dailyStmt=$conn->prepare("
    SELECT 
    i.name AS product_name,
    SUM(o.quantity) AS total_sold,
    SUM(o.price * o.quantity) AS revenue
    FROM orders o
    JOIN inventory i ON i.id = o.product_id
    WHERE o.business_id=?
    AND o.status='Completed'
    AND YEAR(o.created_at)=?
    AND MONTH(o.created_at)=?
    AND DAY(o.created_at)=?
    GROUP BY o.product_id
    ORDER BY revenue DESC
    ");

    $dailyStmt->bind_param("iiii",$business_id,$selectedYear,$selectedMonth,$selectedDay);
    $dailyStmt->execute();
    $resDaily=$dailyStmt->get_result();

    while($row=$resDaily->fetch_assoc()){
        $dailyProducts[]=$row;
        $dailyTotalRevenue += $row['revenue'];
    }

    /* HOURLY SALES QUERY */
    $hourStmt = $conn->prepare("
    SELECT 
    HOUR(created_at) hr,
    SUM(price*quantity) total
    FROM orders
    WHERE business_id=?
    AND status='Completed'
    AND YEAR(created_at)=?
    AND MONTH(created_at)=?
    AND DAY(created_at)=?
    GROUP BY HOUR(created_at)
    ");

    $hourStmt->bind_param("iiii",$business_id,$selectedYear,$selectedMonth,$selectedDay);
    $hourStmt->execute();
    $resHour = $hourStmt->get_result();

    while($row=$resHour->fetch_assoc()){
        $hourlySales[(int)$row['hr']] = $row['total'];
    }

}


/* TOP PRODUCTS */
$topProducts=[];

$topStmt=$conn->prepare("
SELECT 
i.name AS product_name,
SUM(o.quantity) AS total_sold,
SUM(o.price * o.quantity) AS revenue
FROM orders o
JOIN inventory i ON i.id = o.product_id
WHERE o.business_id=?
AND o.status='Completed'
AND YEAR(o.created_at)=?
AND MONTH(o.created_at)=?
GROUP BY o.product_id
ORDER BY total_sold DESC
LIMIT 5
");

if(!$topStmt){
die("Prepare failed: ".$conn->error);
}

$topStmt->bind_param("iii",$business_id,$selectedYear,$selectedMonth);
$topStmt->execute();
$resTop=$topStmt->get_result();

while($row=$resTop->fetch_assoc()){
$topProducts[]=$row;
}

$monthName=date("F",mktime(0,0,0,$selectedMonth,1));

$todayDay=date("j");
$todayMonth=date("n");
$todayYear=date("Y");
$totalCustomers=$totals['total_customers'] ?? 0;
$genderStmt=$conn->prepare("
SELECT
COUNT(DISTINCT CASE WHEN c.gender='Male' THEN o.consumer_id END) AS men,
COUNT(DISTINCT CASE WHEN c.gender='Female' THEN o.consumer_id END) AS women
FROM orders o
JOIN consumers c ON c.c_id = o.consumer_id
WHERE o.business_id = ?
AND o.status = 'Completed'
AND YEAR(o.created_at) = ?
AND MONTH(o.created_at) = ?
");

$genderStmt->bind_param("iii",$business_id,$selectedYear,$selectedMonth);
$genderStmt->execute();

$genderRes=$genderStmt->get_result()->fetch_assoc();

$totalMen=$genderRes['men'] ?? 0;
$totalWomen=$genderRes['women'] ?? 0;
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Professional Dashboard</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>

:root{
--primary:#001a47;
--bg:#F4F7FB;
}

/* ===== FIX SCROLL ROOT ===== */
html, body{
margin:0;
padding:0;
min-height:100%;
overflow-x:hidden;
overflow-y:auto;
font-family:"Segoe UI",sans-serif;
background:#F4F7FB;
}

body{
background:#eef2f7;
}

/* ===== HEADER ===== */
.header{
position:relative;
background:#fff;
padding:20px 40px;
border-bottom:1px solid #e5e7eb;
}

.logo{
position:absolute;
left:40px;
top:50%;
transform:translateY(-50%);
height:40px;
}

.dashboard-title{
text-align:center;
font-size:22px;
font-weight:600;
color:#001a47;
}

/* ===== CONTAINER FIX ===== */
.container{
width:100%;
max-width:100%;
margin:0;
padding:20px 40px;
box-sizing:border-box;
}

/* ===== ADD REAL SPACE BELOW CONTENT ===== */
.page-end-space{
height:100px;
}

/* ===== SECTION TITLE ===== */
.section-title{
font-size:22px;
font-weight:600;
margin:20px 0 10px;
color:#001a47;
}

/* ===== GRID ===== */
.dashboard-grid{
display:grid;
gap:15px;
margin:0;
}

.dashboard-grid .card{
height:100%;
}

@media(min-width:900px){
.dashboard-grid{
grid-template-columns:1.5fr 2fr; /* left smaller, right bigger */
align-items:stretch;
}
}

/* ===== CARD ===== */
.card{
background:#fff;
padding:20px;
border-radius:16px;
box-shadow:0 8px 20px rgba(0,0,0,0.05);
}

.card table{
width:100%;
}

/* ===== CARD HEADER ===== */
.card-header{
display:flex;
justify-content:center;
align-items:center;
gap:10px;
margin-bottom:8px;
}

/* ===== GRAPH ===== */
.chart-container{
height:250px;
}

/* ===== METRICS ===== */
.metrics{
display:grid;
grid-template-columns:repeat(5,1fr);
gap:15px;
margin-top:20px;
}

.metric{
background:#f1f3f7;
padding:15px;
border-radius:12px;
text-align:center;
cursor:pointer;
transition:0.2s;
}

.metric:hover{
background:#dce5ff;
}

.metric h3{
margin:0;
font-size:20px;
color:#001a47;
}

/* ===== CALENDAR ===== */
.calendar{
display:grid;
grid-template-columns:repeat(7,1fr);
gap:8px;
}

.calendar div{
padding:10px;
text-align:center;
background:#f1f3f7;
border-radius:8px;
font-size:13px;
cursor:pointer;
}

.calendar div:hover{
background:#dce5ff;
}

.today{
background:#001a47 !important;
color:#fff;
}

.selected-day{
background:#3f6bff !important;
color:#fff;
}

/* ===== BUTTON ===== */
button{
padding:6px 12px;
border:none;
border-radius:6px;
background:#001a47;
color:#fff;
cursor:pointer;
}

/* ===== DROPDOWN ===== */
.card-header form select{
appearance:none;
padding:8px 35px 8px 12px;
font-size:14px;
border:1px solid #d1d5db;
border-radius:8px;
background:#fff;
color:#001a47;
cursor:pointer;
background-image:url("data:image/svg+xml;utf8,<svg fill='%23001a47' height='20' viewBox='0 0 20 20' width='20'><path d='M5 7l5 5 5-5'/></svg>");
background-repeat:no-repeat;
background-position:right 10px center;
background-size:14px;
}

/* ===== CUSTOMERS ===== */
.customers-card{
display:flex;
flex-direction:column;
align-items:center;
justify-content:center;
}

.gender-count{
display:flex;
align-items:center;
gap:15px;
margin-bottom:6px;
}

.gender-block{
text-align:center;
}

.gender-block h3{
margin:0;
font-size:20px;
color:#001a47;
}

.gender-block span{
font-size:12px;
color:#666;
}

.divider{
width:1px;
height:30px;
background:#d1d5db;
}

.gender-label{
font-size:13px;
color:#001a47;
margin-top:4px;
font-weight:600;
}
.card table tr:hover{
background:#f9fbff;
}

</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="header">
<img src="assets/images/logo.png" class="logo">
<div class="dashboard-title">Professional Dashboard</div>
</div>

<div class="container">

<div class="section-title"></div>

<div class="dashboard-grid">

<!-- ✅ LEFT: DAILY PRODUCT SALES -->
<div class="card">

<div class="card-header">
<span>Daily Product Sales</span>
</div>

<?php if(!$selectedDay): ?>

<div style="text-align:center;padding:30px;color:#999;">
Select a day from calendar
</div>

<?php else: ?>

<table style="width:100%;border-collapse:collapse;">

<tr style="text-align:left;border-bottom:1px solid #eee;">
<th style="padding:10px;">Product</th>
<th style="padding:10px;">Sold</th>
<th style="padding:10px;">Revenue</th>
</tr>

<?php if(empty($dailyProducts)): ?>
<tr>
<td colspan="3" style="text-align:center;padding:20px;color:#999;">
No sales on this day
</td>
</tr>
<?php else: ?>

<?php foreach($dailyProducts as $p): ?>
<tr style="border-bottom:1px solid #eee;">
<td style="padding:10px;">
<?= htmlspecialchars($p['product_name']) ?>
</td>
<td style="padding:10px;">
<?= $p['total_sold'] ?>
</td>
<td style="padding:10px;font-weight:600;color:#001a47;">
₱<?= number_format($p['revenue'],2) ?>
</td>
</tr>
<?php endforeach; ?>

<tr style="background:#f1f3f7;font-weight:600;">
<td colspan="2" style="padding:10px;text-align:right;">TOTAL</td>
<td style="padding:10px;color:#001a47;">
₱<?= number_format($dailyTotalRevenue,2) ?>
</td>
</tr>

<?php endif; ?>

</table>

<?php endif; ?>

</div>


<!-- ✅ RIGHT: GRAPH -->
<div class="card">

<div class="card-header">
<span>Monthly Sales</span>

<form method="GET">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<select name="year" onchange="this.form.submit()">
<?php foreach($years as $year): ?>
<option value="<?= $year ?>" <?= $year==$selectedYear?'selected':'' ?>><?= $year ?></option>
<?php endforeach; ?>
</select>
</form>

</div>

<div class="chart-container">
<canvas id="chart"></canvas>
</div>

<div style="margin-top:15px;font-weight:600;color:#001a47;">
<span id="summaryText">Month Total: ₱<?= number_format($totalSales,2) ?></span>
</div>

<div class="metrics">

<div class="metric" id="visitorsCard">
<h3><?= $totalVisitors ?></h3>
<span>Visitors</span>
</div>

<div class="metric" id="followersCard">
<h3><?= $totalFollowers ?></h3>
<span>Followers</span>
</div>

<div class="metric" id="ordersCard">
<h3 id="orders"><?= $totalOrders ?></h3>
<span>Orders</span>
</div>

<div class="metric customers-card" id="customersCard">

<div class="gender-count">

<div class="gender-block">
<h3><?= $totalMen ?></h3>
<span>Male</span>
</div>

<div class="divider"></div>

<div class="gender-block">
<h3><?= $totalWomen ?></h3>
<span>Female</span>
</div>

</div>

<div class="gender-label">
Consumers
</div>

</div>

<div class="metric" id="salesCard">
<h3 id="sales">₱<?= number_format($totalSales,2) ?></h3>
<span>Sales</span>
</div>

</div>

</div>


<!-- ✅ BELOW RIGHT: CALENDAR -->
<div class="card">

<div class="card-header">

<button onclick="changeMonth(-1)">&lt;</button>
<strong><?= $monthName ?> <?= $selectedYear ?></strong>
<button onclick="changeMonth(1)">&gt;</button>

</div>

<div class="calendar">

<?php for($i=1;$i<=$daysInMonth;$i++): 

$todayClass="";
if($i==$todayDay && $selectedMonth==$todayMonth && $selectedYear==$todayYear){
$todayClass="today";
}

?>

<div 
class="<?= $todayClass ?>"
onclick="selectDay(<?= $i ?>)"
data-day="<?= $i ?>"
>
<strong><?= $i ?></strong><br>
₱<?= number_format($dailySales[$i]) ?>
</div>

<?php endfor; ?>

</div>

</div>

</div>
</div>

<div class="container">
    <div class="section-title">Top Products</div>

<div class="card">

<?php if(!empty($topProducts)): ?>
<div style="margin-bottom:10px;font-weight:600;color:#001a47;">
🔥 Best Seller: <?= htmlspecialchars($topProducts[0]['product_name']) ?>
</div>
<?php endif; ?>

<table style="width:100%;border-collapse:collapse;">
<tr style="text-align:left;border-bottom:1px solid #eee;">
<th style="padding:10px;">Product</th>
<th style="padding:10px;">Sold</th>
<th style="padding:10px;">Revenue</th>
</tr>

<?php if(empty($topProducts)): ?>
<tr>
<td colspan="3" style="text-align:center;padding:30px;color:#999;">
<div style="font-size:14px;">No sales data available</div>
<div style="font-size:12px;color:#bbb;margin-top:5px;">
Try selecting another month or wait for new orders
</div>
</td>
</tr>
<?php else: ?>

<?php foreach($topProducts as $p): ?>
<tr style="border-bottom:1px solid #eee;">
<td style="padding:10px;"><?= htmlspecialchars($p['product_name']) ?></td>
<td style="padding:10px;"><?= $p['total_sold'] ?></td>
<td style="padding:10px;">₱<?= number_format($p['revenue'],2) ?></td>
</tr>
<?php endforeach; ?>

<?php endif; ?>

</table>

</div>

</div>

<?php include 'bottom_nav.php'; ?>


<script>

const dailySales = <?= json_encode($dailySales) ?>;
const dailyOrders = <?= json_encode($dailyOrders) ?>;
const dailyCustomers = <?= json_encode($dailyCustomers) ?>;

const monthlySalesData = <?= json_encode(array_values($monthlySales)) ?>;
const monthlyFollowersData = <?= json_encode(array_values($monthlyFollowers)) ?>;
const hourlySalesData = <?= json_encode(array_values($hourlySales)) ?>;
const selectedDayFromPHP = <?= $selectedDay ? $selectedDay : 'null' ?>;
const monthSales = <?= $totalSales ?>;
const monthFollowers = <?= $totalFollowers ?>;
const monthOrders = <?= $totalOrders ?>;
const monthCustomers = <?= $totalCustomers ?>;
const monthVisitors = <?= $totalVisitors ?>;

let selectedDay = null;
let activeMetric = "sales";

/* ===== GRAPH ===== */
const ctx = document.getElementById("chart");

let chart = new Chart(ctx,{
type:"line",
data:{
labels: selectedDayFromPHP 
? Array.from({length:24},(_,i)=>i+":00") 
: ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"],
datasets:[{
label: selectedDayFromPHP ? "Daily Sales (Hourly)" : "Sales",
data: selectedDayFromPHP ? hourlySalesData : monthlySalesData,
borderColor:"#001a47",
backgroundColor:"rgba(0,26,71,0.15)",
fill:true,
tension:0.4
}]
},
options:{
responsive:true,
maintainAspectRatio:false,
plugins:{legend:{display:false}}
}
});

/* ===== UPDATE GRAPH ===== */
function updateGraph(type){

activeMetric = type;

let data = [];
let label = "";
let labels = [];

if(selectedDayFromPHP){

labels = Array.from({length:24},(_,i)=>i+":00");

if(type === "sales"){
data = hourlySalesData;
label = "Daily Sales";
}

if(type === "orders"){
data = Array(24).fill(0);
label = "Orders";
}

if(type === "customers"){
data = Array(24).fill(0);
label = "Customers";
}

if(type === "visitors"){
data = Array(24).fill(0);
label = "Visitors";
}

}else{

labels = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];

if(type === "sales"){
data = monthlySalesData;
label = "Sales";
}

if(type === "followers"){
data = monthlyFollowersData;
label = "Followers";
}

if(type === "orders"){
data = Object.values(<?= json_encode($monthlySales) ?>).map(v=>v>0?1:0);
label = "Orders";
}

if(type === "customers"){
data = Object.values(<?= json_encode($monthlySales) ?>).map(v=>v>0?1:0);
label = "Customers";
}

if(type === "visitors"){
data = Object.values(<?= json_encode($monthlySales) ?>).map(v=>v>0?1:0);
label = "Visitors";
}

}

chart.data.labels = labels;
chart.data.datasets[0].data = data;
chart.data.datasets[0].label = label;
chart.update();

}

/* ===== CLICKABLE CARDS ===== */
document.querySelectorAll(".metric").forEach((card,index)=>{

card.addEventListener("click",()=>{

document.querySelectorAll(".metric").forEach(c=>c.style.background="#f1f3f7");
card.style.background="#dce5ff";

if(index === 0) updateGraph("visitors");
if(index === 1) updateGraph("followers");
if(index === 2) updateGraph("orders");
if(index === 3) updateGraph("customers");
if(index === 4) updateGraph("sales");

});

});

/* ===== CALENDAR ===== */
function selectDay(day){
window.location.href = "?month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&day=" + day;
}

/* ===== CHANGE MONTH ===== */
function changeMonth(step){

let month = <?= $selectedMonth ?> + step;
let year  = <?= $selectedYear ?>;

if(month < 1){
month = 12;
year--;
}

if(month > 12){
month = 1;
year++;
}

window.location.href = "?month="+month+"&year="+year;

}

</script>
<div class="page-end-space"></div>
</body>
</html>
