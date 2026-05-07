<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/orders_helper.php";


/* ================= DASHBOARD PROTECTION ================= */

if($_SESSION['account_type'] !== "business_owner"){
    header("Location: more.php");
    exit;
}

/* BUSINESS OWNER ID (IMPORTANT: DO NOT MIX WITH VIEW ID) */
$business_id = $_SESSION['user_id'];

ensureOrderPaymentSupport($conn);


/* ================= FILTER ================= */

$allowedPeriods = ['day', 'week', 'month', 'year'];
$period = $_GET['period'] ?? 'day';
if(!in_array($period, $allowedPeriods, true)){
    $period = 'day';
}

$selectedDate = $_GET['date'] ?? date("Y-m-d");
$dateObj = DateTime::createFromFormat('Y-m-d', $selectedDate);
if(!$dateObj || $dateObj->format('Y-m-d') !== $selectedDate){
    $dateObj = new DateTime(date("Y-m-d"));
    $selectedDate = $dateObj->format('Y-m-d');
}

$rangeStart = clone $dateObj;
$rangeEnd = clone $dateObj;

if($period === 'day'){
    $rangeStart->setTime(0, 0, 0);
    $rangeEnd = clone $rangeStart;
    $rangeEnd->modify('+1 day');
}elseif($period === 'week'){
    $rangeStart->modify('monday this week')->setTime(0, 0, 0);
    $rangeEnd = clone $rangeStart;
    $rangeEnd->modify('+7 days');
}elseif($period === 'month'){
    $rangeStart->setDate((int)$dateObj->format('Y'), (int)$dateObj->format('m'), 1)->setTime(0, 0, 0);
    $rangeEnd = clone $rangeStart;
    $rangeEnd->modify('+1 month');
}else{
    $selectedYearValue = (int)$dateObj->format('Y');
    $rangeStart->setDate($selectedYearValue, 1, 1)->setTime(0, 0, 0);
    $rangeEnd = clone $rangeStart;
    $rangeEnd->modify('+1 year');
}

$rangeStartSql = $rangeStart->format('Y-m-d H:i:s');
$rangeEndSql = $rangeEnd->format('Y-m-d H:i:s');
$rangeLabel = $rangeStart->format('M j, Y');

if($period === 'day'){
    $rangeLabel = $rangeStart->format('M j, Y');
}elseif($period === 'week'){
    $rangeLabelEnd = clone $rangeEnd;
    $rangeLabelEnd->modify('-1 day');
    $rangeLabel = $rangeStart->format('M j') . ' - ' . $rangeLabelEnd->format('M j, Y');
}elseif($period === 'month'){
    $rangeLabel = $rangeStart->format('F Y');
}elseif($period === 'year'){
    $rangeLabel = $rangeStart->format('Y');
}

/* ================= SCORE CARDS ================= */

$totalStmt=$conn->prepare("
SELECT 
COALESCE(SUM(price*quantity),0) total_sales,
COUNT(DISTINCT order_code) total_orders,
COUNT(DISTINCT CONCAT(COALESCE(buyer_account_type, 'consumer'), ':', consumer_id)) total_buyers,
COALESCE(SUM(quantity),0) items_sold
FROM orders
WHERE business_id=?
AND status='Completed'
AND created_at >= ?
AND created_at < ?
");

$totalStmt->bind_param("iss",$business_id,$rangeStartSql,$rangeEndSql);
$totalStmt->execute();
$totals=$totalStmt->get_result()->fetch_assoc();

$totalSales=(float)($totals['total_sales'] ?? 0);
$totalOrders=(int)($totals['total_orders'] ?? 0);
$totalBuyers=(int)($totals['total_buyers'] ?? 0);
$itemsSold=(int)($totals['items_sold'] ?? 0);
$averageOrderValue = $totalOrders > 0 ? $totalSales / $totalOrders : 0;

$previousRangeEnd = clone $rangeStart;
$previousRangeStart = clone $rangeStart;
$rangeSeconds = $rangeEnd->getTimestamp() - $rangeStart->getTimestamp();
$previousRangeStart->modify("-{$rangeSeconds} seconds");
$previousRangeStartSql = $previousRangeStart->format('Y-m-d H:i:s');
$previousRangeEndSql = $previousRangeEnd->format('Y-m-d H:i:s');

$previousSalesStmt=$conn->prepare("
SELECT COALESCE(SUM(price*quantity),0) previous_sales
FROM orders
WHERE business_id=?
AND status='Completed'
AND created_at >= ?
AND created_at < ?
");

$previousSalesStmt->bind_param("iss",$business_id,$previousRangeStartSql,$previousRangeEndSql);
$previousSalesStmt->execute();
$previousSales=(float)($previousSalesStmt->get_result()->fetch_assoc()['previous_sales'] ?? 0);

$salesChangeAmount = $totalSales - $previousSales;
$salesChangePercent = $previousSales > 0 ? ($salesChangeAmount / $previousSales) * 100 : ($totalSales > 0 ? 100 : 0);
$salesTrendDirection = $salesChangeAmount > 0 ? 'up' : ($salesChangeAmount < 0 ? 'down' : 'flat');
$salesTrendText = $salesTrendDirection === 'flat'
    ? 'No change from previous period'
    : number_format(abs($salesChangePercent), 1) . '% vs previous period';

$visitorStmt=$conn->prepare("
SELECT COUNT(*) AS visitors
FROM business_visits
WHERE business_id=?
AND visited_at >= ?
AND visited_at < ?
");

$visitorStmt->bind_param("iss",$business_id,$rangeStartSql,$rangeEndSql);
$visitorStmt->execute();
$totalVisitors=(int)($visitorStmt->get_result()->fetch_assoc()['visitors'] ?? 0);

$followerStmt = $conn->prepare("
SELECT COUNT(*) AS followers
FROM business_followers
WHERE business_id = ?
AND created_at >= ?
AND created_at < ?
");

$followerStmt->bind_param("iss", $business_id, $rangeStartSql, $rangeEndSql);
$followerStmt->execute();
$totalFollowers = (int)($followerStmt->get_result()->fetch_assoc()['followers'] ?? 0);

$conversionRate = $totalVisitors > 0 ? ($totalBuyers / $totalVisitors) * 100 : 0;

$genderStmt=$conn->prepare("
SELECT
COUNT(DISTINCT CASE WHEN COALESCE(c.gender, bo.gender)='Male' THEN o.consumer_id END) AS men,
COUNT(DISTINCT CASE WHEN COALESCE(c.gender, bo.gender)='Female' THEN o.consumer_id END) AS women
FROM orders o
LEFT JOIN consumers c
    ON c.c_id = o.consumer_id
   AND (o.buyer_account_type = 'consumer' OR o.buyer_account_type IS NULL)
LEFT JOIN business_owner bo
    ON bo.b_id = o.consumer_id
   AND o.buyer_account_type = 'business_owner'
WHERE o.business_id = ?
AND o.status = 'Completed'
AND o.created_at >= ?
AND o.created_at < ?
");

$genderStmt->bind_param("iss",$business_id,$rangeStartSql,$rangeEndSql);
$genderStmt->execute();
$genderRes=$genderStmt->get_result()->fetch_assoc();

$totalMen=(int)($genderRes['men'] ?? 0);
$totalWomen=(int)($genderRes['women'] ?? 0);

/* ================= CHART SERIES ================= */

function emptyTrendSeries(string $period, DateTime $start): array {
    $labels = [];
    $keys = [];

    if($period === 'day'){
        for($i=0;$i<24;$i++){
            $labels[] = date("g A", mktime($i, 0, 0));
            $keys[] = str_pad((string)$i, 2, "0", STR_PAD_LEFT);
        }
    }elseif($period === 'week'){
        for($i=0;$i<7;$i++){
            $d = clone $start;
            $d->modify("+$i day");
            $labels[] = $d->format("M j");
            $keys[] = $d->format("Y-m-d");
        }
    }elseif($period === 'month'){
        $daysInSelectedMonth = (int)$start->format("t");
        for($i=1;$i<=$daysInSelectedMonth;$i++){
            $d = clone $start;
            $d->modify("+" . ($i - 1) . " day");
            $labels[] = $d->format("M j");
            $keys[] = $d->format("Y-m-d");
        }
    }else{
        for($i=1;$i<=12;$i++){
            $labels[] = date("M", mktime(0, 0, 0, $i, 1));
            $keys[] = str_pad((string)$i, 2, "0", STR_PAD_LEFT);
        }
    }

    return [$labels, $keys, array_fill_keys($keys, 0)];
}

[$trendLabels, $trendKeys, $salesTrendMap] = emptyTrendSeries($period, $rangeStart);
$ordersTrendMap = array_fill_keys($trendKeys, 0);
$buyersTrendMap = array_fill_keys($trendKeys, 0);
$visitorsTrendMap = array_fill_keys($trendKeys, 0);
$followersTrendMap = array_fill_keys($trendKeys, 0);

if($period === 'day'){
    $ordersGroup = "DATE_FORMAT(created_at, '%H')";
    $visitsGroup = "DATE_FORMAT(visited_at, '%H')";
    $followersGroup = "DATE_FORMAT(created_at, '%H')";
}elseif($period === 'week'){
    $ordersGroup = "DATE(created_at)";
    $visitsGroup = "DATE(visited_at)";
    $followersGroup = "DATE(created_at)";
}elseif($period === 'month'){
    $ordersGroup = "DATE(created_at)";
    $visitsGroup = "DATE(visited_at)";
    $followersGroup = "DATE(created_at)";
}else{
    $ordersGroup = "DATE_FORMAT(created_at, '%m')";
    $visitsGroup = "DATE_FORMAT(visited_at, '%m')";
    $followersGroup = "DATE_FORMAT(created_at, '%m')";
}

$trendStmt=$conn->prepare("
SELECT $ordersGroup label_key,
COALESCE(SUM(price*quantity),0) sales,
COUNT(DISTINCT order_code) orders_count,
COUNT(DISTINCT CONCAT(COALESCE(buyer_account_type, 'consumer'), ':', consumer_id)) buyers_count
FROM orders
WHERE business_id=?
AND status='Completed'
AND created_at >= ?
AND created_at < ?
GROUP BY label_key
");
$trendStmt->bind_param("iss",$business_id,$rangeStartSql,$rangeEndSql);
$trendStmt->execute();
$trendRes=$trendStmt->get_result();
while($row=$trendRes->fetch_assoc()){
    $key = (string)$row['label_key'];
    if(isset($salesTrendMap[$key])){
        $salesTrendMap[$key] = (float)$row['sales'];
        $ordersTrendMap[$key] = (int)$row['orders_count'];
        $buyersTrendMap[$key] = (int)$row['buyers_count'];
    }
}

$visitorTrendStmt=$conn->prepare("
SELECT $visitsGroup label_key, COUNT(*) total
FROM business_visits
WHERE business_id=?
AND visited_at >= ?
AND visited_at < ?
GROUP BY label_key
");
$visitorTrendStmt->bind_param("iss",$business_id,$rangeStartSql,$rangeEndSql);
$visitorTrendStmt->execute();
$visitorTrendRes=$visitorTrendStmt->get_result();
while($row=$visitorTrendRes->fetch_assoc()){
    $key = (string)$row['label_key'];
    if(isset($visitorsTrendMap[$key])){
        $visitorsTrendMap[$key] = (int)$row['total'];
    }
}

$followerTrendStmt=$conn->prepare("
SELECT $followersGroup label_key, COUNT(*) total
FROM business_followers
WHERE business_id=?
AND created_at >= ?
AND created_at < ?
GROUP BY label_key
");
$followerTrendStmt->bind_param("iss",$business_id,$rangeStartSql,$rangeEndSql);
$followerTrendStmt->execute();
$followerTrendRes=$followerTrendStmt->get_result();
while($row=$followerTrendRes->fetch_assoc()){
    $key = (string)$row['label_key'];
    if(isset($followersTrendMap[$key])){
        $followersTrendMap[$key] = (int)$row['total'];
    }
}

$salesTrend = array_values($salesTrendMap);
$ordersTrend = array_values($ordersTrendMap);
$buyersTrend = array_values($buyersTrendMap);
$visitorsTrend = array_values($visitorsTrendMap);
$followersTrend = array_values($followersTrendMap);

/* ================= PRODUCTS ================= */

$dailyProducts = [];
$dailyTotalRevenue = 0;
$dailyStmt=$conn->prepare("
SELECT 
i.name AS product_name,
SUM(o.quantity) AS total_sold,
SUM(o.price * o.quantity) AS revenue
FROM orders o
JOIN inventory i ON i.id = o.product_id
WHERE o.business_id=?
AND o.status='Completed'
AND o.created_at >= ?
AND o.created_at < ?
GROUP BY o.product_id
ORDER BY revenue DESC
");

$dailyStmt->bind_param("iss",$business_id,$rangeStartSql,$rangeEndSql);
$dailyStmt->execute();
$resDaily=$dailyStmt->get_result();

while($row=$resDaily->fetch_assoc()){
    $dailyProducts[]=$row;
    $dailyTotalRevenue += (float)$row['revenue'];
}

$topProducts=array_slice($dailyProducts, 0, 5);

/* Compatibility values for the hidden legacy dashboard block below. */
$selectedYear = (int)$dateObj->format('Y');
$selectedMonth = (int)$dateObj->format('n');
$years = range((int)date('Y'), 2020);
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $selectedMonth, $selectedYear);
$todayYear = (int) date("Y");
$todayMonth = (int) date("n");
$todayDay = (int) date("j");
$selectedDay = $period === 'day' ? (int)$dateObj->format('j') : null;
$monthName = date("F", mktime(0,0,0,$selectedMonth,1));
$totalCustomers = $totalBuyers;
$dailySales = array_fill(1, $daysInMonth, 0);
$dailyOrders = array_fill(1, $daysInMonth, 0);
$dailyCustomers = array_fill(1, $daysInMonth, 0);
$monthlySales = array_fill(1, 12, 0);
$monthlyFollowers = array_fill(1, 12, 0);
$hourlySales = array_fill(0, 24, 0);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Professional Dashboard</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="assets/css/responsive.css">

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
grid-template-columns:minmax(0,1fr);
}

.dashboard-grid .card{
height:100%;
min-width:0;
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
min-width:0;
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
flex-wrap:wrap;
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
min-width:0;
}

.metric:hover{
background:#dce5ff;
}

.metric h3{
margin:0;
font-size:20px;
color:#001a47;
overflow-wrap:anywhere;
}

.metric span{
display:block;
}

.customers-card{
display:flex;
flex-direction:column;
justify-content:center;
}

/* ===== CALENDAR ===== */
.calendar{
display:grid;
grid-template-columns:repeat(7,minmax(0,1fr));
gap:8px;
}

.calendar div{
background:#f1f3f7;
border-radius:8px;
cursor:pointer;
min-width:0;
}

.calendar-day{
aspect-ratio:1 / 1;
display:flex;
flex-direction:column;
align-items:center;
justify-content:center;
gap:4px;
padding:6px 4px;
text-align:center;
font-size:0;
line-height:0;
}

.calendar-day-number{
font-size:15px;
line-height:1;
}

.calendar-day::after,
.calendar-day-sales{
font-size:10px;
line-height:1.2;
font-weight:600;
white-space:nowrap;
overflow:hidden;
text-overflow:ellipsis;
max-width:100%;
}

.calendar-day::after{
content:attr(data-sales);
display:block;
}

.calendar div:hover{
background:#dce5ff;
}

.today{
background:#001a47 !important;
color:#fff;
}

.selected-day{
background:#d6d9df !important;
color:#1f2937;
box-shadow:inset 0 0 0 1px #b8bec8;
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
.chart-header{
justify-content:space-between;
}

.month-nav{
justify-content:space-between;
}

.month-nav strong{
flex:1;
text-align:center;
color:#001a47;
font-size:16px;
}

.summary-line{
margin-top:15px;
font-weight:600;
color:#001a47;
overflow-wrap:anywhere;
}

.table-wrap{
width:100%;
overflow-x:auto;
}

.dashboard-table{
width:100%;
border-collapse:collapse;
}

.dashboard-table th,
.dashboard-table td{
padding:10px;
}

.top-seller{
margin-bottom:10px;
font-weight:600;
color:#001a47;
overflow-wrap:anywhere;
}
.card table tr:hover{
background:#f9fbff;
}

@media (max-width:1100px){
.header{
padding:20px 24px;
}

.logo{
left:24px;
}

.container{
padding:20px 24px;
}

.metrics{
grid-template-columns:repeat(3,minmax(0,1fr));
}
}

@media (max-width:768px){
.header{
padding:16px;
display:flex;
flex-direction:column;
align-items:center;
gap:10px;
}

.logo{
position:static;
transform:none;
height:34px;
}

.dashboard-title{
font-size:20px;
}

.container{
padding:16px;
}

.section-title{
font-size:20px;
margin:16px 0 10px;
}

.card{
padding:16px;
border-radius:14px;
}

.chart-container{
height:220px;
}

.chart-header,
.month-nav{
justify-content:flex-start;
align-items:stretch;
}

.chart-header form,
.chart-header select{
width:100%;
}

.month-nav strong{
order:-1;
flex:1 1 100%;
}

.month-nav button{
flex:1 1 calc(50% - 5px);
}

.metrics{
grid-template-columns:repeat(2,minmax(0,1fr));
gap:12px;
}

.metric{
padding:14px 12px;
min-height:110px;
display:flex;
flex-direction:column;
justify-content:center;
align-items:center;
gap:6px;
}

.gender-count{
flex-direction:column;
gap:8px;
}

.divider{
width:36px;
height:1px;
}

.calendar{
grid-template-columns:repeat(7,minmax(0,1fr));
gap:6px;
}

.calendar-day{
padding:4px 2px;
gap:3px;
}

.calendar-day-number{
font-size:13px;
}

.calendar-day::after,
.calendar-day-sales{
font-size:9px;
}

.dashboard-table thead{
display:none;
}

.dashboard-table,
.dashboard-table tbody,
.dashboard-table tr,
.dashboard-table td{
display:block;
width:100%;
}

.dashboard-table tr{
padding:12px 0;
border-bottom:1px solid #eee;
}

.dashboard-table td{
display:flex;
justify-content:space-between;
align-items:flex-start;
gap:12px;
padding:6px 0 !important;
text-align:left !important;
}

.dashboard-table td::before{
content:"";
flex:0 0 88px;
font-weight:600;
color:#64748b;
}

.daily-sales-table td:nth-child(1)::before,
.top-products-table td:nth-child(1)::before{
content:"Product";
}

.daily-sales-table td:nth-child(2)::before,
.top-products-table td:nth-child(2)::before{
content:"Sold";
}

.daily-sales-table td:nth-child(3)::before,
.top-products-table td:nth-child(3)::before{
content:"Revenue";
}

.daily-sales-table tr.total-row td{
font-weight:600;
}
}

@media (max-width:520px){
.metrics{
grid-template-columns:repeat(2,minmax(0,1fr));
gap:10px;
}

.metric{
min-height:96px;
padding:12px 10px;
border-radius:10px;
}

.metric h3{
font-size:18px;
}

.metric span{
font-size:13px;
}

.customers-card{
grid-column:auto;
min-height:96px;
gap:4px;
}

.gender-count{
flex-direction:row;
justify-content:center;
align-items:flex-start;
gap:10px;
margin-bottom:2px;
}

.gender-block h3{
font-size:16px;
}

.gender-block span,
.gender-label{
font-size:11px;
line-height:1.2;
}

.divider{
width:1px;
height:22px;
}

.chart-container{
height:200px;
}
}

.legacy-hide{
display:none !important;
}

.dashboard-hero{
display:flex;
justify-content:space-between;
align-items:flex-start;
gap:24px;
margin-bottom:20px;
}

.dashboard-hero h1{
margin:4px 0 6px;
font-size:30px;
line-height:1.15;
color:#001a47;
}

.dashboard-hero p{
margin:0;
color:#64748b;
font-size:14px;
line-height:1.5;
}

.eyebrow{
font-size:12px;
font-weight:700;
letter-spacing:.08em;
text-transform:uppercase;
color:#0f766e;
}

.filter-panel{
min-width:320px;
display:grid;
grid-template-columns:1fr;
gap:10px;
padding:14px;
background:#fff;
border:1px solid #e5e7eb;
border-radius:16px;
box-shadow:0 8px 20px rgba(0,0,0,0.05);
}

.period-tabs{
grid-column:1 / -1;
display:grid;
grid-template-columns:repeat(4,1fr);
gap:6px;
}

.period-tabs button,
.score-card{
border:none;
cursor:pointer;
font-family:inherit;
}

.period-tabs button{
padding:9px 10px;
border-radius:10px;
background:#f1f5f9;
color:#334155;
font-weight:700;
}

.period-tabs button.active{
background:#001a47;
color:#fff;
box-shadow:inset 0 0 0 1px #001a47, 0 10px 18px rgba(0,26,71,.18);
}

.period-tabs button:active,
.period-tabs button:focus-visible{
background:#001a47;
color:#fff;
outline:none;
}

.filter-panel label{
display:flex;
flex-direction:column;
gap:5px;
font-size:12px;
font-weight:700;
color:#475569;
}

.date-field{
grid-column:1 / -1;
}

.date-control-row{
display:flex;
align-items:center;
gap:10px;
}

.filter-panel input{
width:100%;
padding:10px;
border:1px solid #d1d5db;
border-radius:10px;
font-size:14px;
min-height:42px;
}

.date-control-row input[type="date"]{
flex:1 1 auto;
padding:10px 12px;
font-weight:700;
background:#fff;
}

.today-link{
flex:0 0 auto;
display:inline-flex;
justify-content:center;
align-items:center;
padding:10px 16px;
min-height:42px;
border-radius:10px;
background:#eef2ff;
color:#001a47;
font-weight:700;
text-decoration:none;
white-space:nowrap;
}

.score-grid{
display:grid;
grid-template-columns:repeat(4,minmax(0,1fr));
gap:14px;
margin-bottom:18px;
}

.score-card{
text-align:left;
padding:18px;
border-radius:16px;
background:#fff;
box-shadow:0 8px 20px rgba(0,0,0,0.05);
border:1px solid #e5e7eb;
transition:.18s ease;
}

.score-card span{
display:block;
font-size:13px;
font-weight:700;
color:#64748b;
}

.score-card strong{
display:block;
margin-top:8px;
font-size:22px;
color:#001a47;
overflow-wrap:anywhere;
}

.score-trend{
display:inline-flex !important;
align-items:center;
gap:6px;
margin-top:10px;
padding:5px 8px;
border-radius:999px;
font-size:12px !important;
font-weight:800 !important;
line-height:1.2;
}

.score-trend.up{
background:#dcfce7;
color:#15803d !important;
}

.score-trend.down{
background:#fee2e2;
color:#b91c1c !important;
}

.score-trend.flat{
background:#e5e7eb;
color:#475569 !important;
}

.score-trend .trend-arrow{
font-size:13px;
line-height:1;
}

.score-card.active,
.score-card:hover{
border-color:#001a47;
background:#f8fbff;
transform:translateY(-1px);
}

.analytics-grid,
.content-grid{
display:grid;
grid-template-columns:repeat(2,minmax(0,1fr));
gap:16px;
margin-bottom:18px;
}

.panel-title{
display:flex;
justify-content:space-between;
align-items:flex-start;
gap:12px;
margin-bottom:12px;
}

.panel-title span{
display:block;
font-size:17px;
font-weight:800;
color:#001a47;
}

.panel-title small{
display:block;
margin-top:4px;
font-size:12px;
color:#64748b;
}

.chart-container.tall{
height:310px;
}

.dashboard-table th{
font-size:12px;
color:#64748b;
text-transform:uppercase;
letter-spacing:.04em;
border-bottom:1px solid #e5e7eb;
}

.dashboard-table td{
border-bottom:1px solid #eef2f7;
color:#1f2937;
}

.money{
font-weight:800;
color:#001a47 !important;
}

.empty-cell{
text-align:center;
padding:28px !important;
color:#94a3b8 !important;
}

.total-row{
background:#f8fafc;
font-weight:800;
}

.total-row td:first-child{
text-align:right;
}

.buyer-breakdown{
display:grid;
grid-template-columns:1fr 1fr;
gap:10px;
margin-top:14px;
}

.buyer-breakdown div{
padding:14px;
border-radius:14px;
background:#f8fafc;
text-align:center;
}

.buyer-breakdown strong,
.buyer-breakdown span{
display:block;
}

.buyer-breakdown strong{
font-size:22px;
color:#001a47;
}

.buyer-breakdown span{
font-size:12px;
color:#64748b;
font-weight:700;
}

.insight-note{
margin-top:12px;
padding:12px 14px;
border-radius:12px;
background:#f8fafc;
color:#334155;
font-size:13px;
line-height:1.5;
}

.gender-card{
display:grid;
grid-template-columns:1.1fr .9fr;
gap:18px;
align-items:center;
}

.gender-chart-wrap{
height:240px;
}

.theme-dark .filter-panel,
.theme-dark .score-card,
.theme-dark .card{
background:#111 !important;
border-color:#2d2d2d;
}

.theme-dark .dashboard-hero h1,
.theme-dark .score-card strong,
.theme-dark .panel-title span,
.theme-dark .money,
.theme-dark .buyer-breakdown strong{
color:#ededed !important;
}

.theme-dark .dashboard-hero p,
.theme-dark .score-card span,
.theme-dark .panel-title small{
color:#b8b8b8;
}

.theme-dark .score-trend.up{
background:rgba(22,163,74,.18);
color:#4ade80 !important;
}

.theme-dark .score-trend.down{
background:rgba(220,38,38,.18);
color:#f87171 !important;
}

.theme-dark .score-trend.flat{
background:#222;
color:#cbd5e1 !important;
}

.theme-dark .buyer-breakdown div,
.theme-dark .period-tabs button,
.theme-dark .insight-note{
background:#1a1a1a;
}

@media (max-width:900px){
.dashboard-hero,
.analytics-grid,
.content-grid,
.gender-card{
grid-template-columns:1fr;
display:grid;
}

.filter-panel{
min-width:0;
}

.score-grid{
grid-template-columns:repeat(2,minmax(0,1fr));
}
}

@media (max-width:560px){
.dashboard-hero h1{
font-size:24px;
}

.filter-panel,
.score-grid{
grid-template-columns:1fr;
}

.date-control-row{
flex-direction:column;
align-items:stretch;
}

.today-link{
width:100%;
}

.period-tabs{
grid-template-columns:repeat(2,1fr);
}

.chart-container.tall{
height:250px;
}

.gender-chart-wrap{
height:220px;
}
}

</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="header">
<div class="dashboard-title">Professional Dashboard</div>
</div>

<div class="container">

<div class="dashboard-hero">
    <div>
        <div class="eyebrow">Sales Overview</div>
        <h1><?= htmlspecialchars($rangeLabel) ?></h1>
        <p>Track revenue, buyers, orders, visitors, followers, and product performance in one dashboard.</p>
    </div>

    <form method="GET" class="filter-panel" id="dashboardFilter">
        <div class="period-tabs">
            <?php foreach(['day'=>'Day','week'=>'Week','month'=>'Month','year'=>'Year'] as $value=>$label): ?>
            <button type="submit" name="period" value="<?= $value ?>" onclick="this.form.querySelector('input[type=hidden][name=period]').value=this.value" class="<?= $period === $value ? 'active' : '' ?>">
                <?= $label ?>
            </button>
            <?php endforeach; ?>
        </div>

        <label class="date-field">
            Date
            <div class="date-control-row">
                <input type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" onchange="this.form.submit()">
                <a class="today-link" href="profdashboard.php?period=day&date=<?= date('Y-m-d') ?>">Today</a>
            </div>
        </label>

        <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
    </form>
</div>

<div class="score-grid">
    <button class="score-card active" data-metric="sales">
        <span>Total Sales</span>
        <strong>&#8369;<?= number_format($totalSales,2) ?></strong>
        <span class="score-trend <?= htmlspecialchars($salesTrendDirection) ?>">
            <?php if($salesTrendDirection === 'up'): ?>
            <span class="trend-arrow">&#9650;</span>
            <?php elseif($salesTrendDirection === 'down'): ?>
            <span class="trend-arrow">&#9660;</span>
            <?php else: ?>
            <span class="trend-arrow">-</span>
            <?php endif; ?>
            <?= htmlspecialchars($salesTrendText) ?>
        </span>
    </button>
    <button class="score-card" data-metric="orders">
        <span>Orders</span>
        <strong><?= number_format($totalOrders) ?></strong>
    </button>
    <button class="score-card" data-metric="buyers">
        <span>Buyers</span>
        <strong><?= number_format($totalBuyers) ?></strong>
    </button>
    <button class="score-card" data-metric="items">
        <span>Items Sold</span>
        <strong><?= number_format($itemsSold) ?></strong>
    </button>
    <button class="score-card" data-metric="visitors">
        <span>Visitors</span>
        <strong><?= number_format($totalVisitors) ?></strong>
    </button>
    <button class="score-card" data-metric="followers">
        <span>New Followers</span>
        <strong><?= number_format($totalFollowers) ?></strong>
    </button>
    <button class="score-card" data-metric="conversion">
        <span>Buyer Rate</span>
        <strong><?= number_format($conversionRate,1) ?>%</strong>
    </button>
    <button class="score-card" data-metric="average">
        <span>Avg. Order</span>
        <strong>&#8369;<?= number_format($averageOrderValue,2) ?></strong>
    </button>
</div>

<div class="analytics-grid">
    <div class="card chart-card">
        <div class="panel-title">
            <div>
                <span>Performance Trend</span>
                <small id="trendLabel">Sales over selected <?= htmlspecialchars($period) ?></small>
            </div>
        </div>
        <div class="chart-container tall">
            <canvas id="trendChart"></canvas>
        </div>
    </div>

    <div class="card chart-card">
        <div class="panel-title">
            <div>
                <span>Sales vs Buyers</span>
                <small>Revenue and buyer activity side by side</small>
            </div>
        </div>
        <div class="chart-container tall">
            <canvas id="barChart"></canvas>
        </div>
    </div>
</div>

<div class="content-grid">
    <div class="card">
        <div class="panel-title">
            <div>
                <span>Product Sales</span>
                <small><?= htmlspecialchars($rangeLabel) ?></small>
            </div>
        </div>

        <div class="table-wrap">
            <table class="dashboard-table daily-sales-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Sold</th>
                        <th>Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($dailyProducts)): ?>
                    <tr>
                        <td colspan="3" class="empty-cell">No sales data for this filter.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach($dailyProducts as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['product_name']) ?></td>
                        <td><?= number_format($p['total_sold']) ?></td>
                        <td class="money">&#8369;<?= number_format($p['revenue'],2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="2">TOTAL</td>
                        <td class="money">&#8369;<?= number_format($dailyTotalRevenue,2) ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="panel-title">
            <div>
                <span>Top Products</span>
                <small>Best-performing items for this filter</small>
            </div>
        </div>

        <?php if(!empty($topProducts)): ?>
        <div class="top-seller">Best Seller: <?= htmlspecialchars($topProducts[0]['product_name']) ?></div>
        <?php endif; ?>

        <div class="table-wrap">
            <table class="dashboard-table top-products-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Sold</th>
                        <th>Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($topProducts)): ?>
                    <tr>
                        <td colspan="3" class="empty-cell">No top products yet.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach($topProducts as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['product_name']) ?></td>
                        <td><?= number_format($p['total_sold']) ?></td>
                        <td class="money">&#8369;<?= number_format($p['revenue'],2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="buyer-breakdown">
            <div>
                <strong><?= number_format($totalMen) ?></strong>
                <span>Male Buyers</span>
            </div>
            <div>
                <strong><?= number_format($totalWomen) ?></strong>
                <span>Female Buyers</span>
            </div>
        </div>
    </div>
</div>

<div class="card gender-card">
    <div>
        <div class="panel-title">
            <div>
                <span>Buyer Demographics</span>
                <small>Male and female buyers for <?= htmlspecialchars($rangeLabel) ?></small>
            </div>
        </div>

        <div class="buyer-breakdown">
            <div>
                <strong><?= number_format($totalMen) ?></strong>
                <span>Male Buyers</span>
            </div>
            <div>
                <strong><?= number_format($totalWomen) ?></strong>
                <span>Female Buyers</span>
            </div>
        </div>

        <div class="insight-note">
            <?php if($totalMen === 0 && $totalWomen === 0): ?>
            No buyer gender data is available for this selected period.
            <?php elseif($totalWomen > $totalMen): ?>
            Women are the stronger buying segment in this selected period.
            <?php elseif($totalMen > $totalWomen): ?>
            Men are the stronger buying segment in this selected period.
            <?php else: ?>
            Male and female buyers are evenly split in this selected period.
            <?php endif; ?>
        </div>
    </div>

    <div class="gender-chart-wrap">
        <canvas id="genderChart"></canvas>
    </div>
</div>

</div>

<div class="container legacy-hide">

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

<div class="table-wrap">
<table class="dashboard-table daily-sales-table">

<thead>
<tr style="text-align:left;border-bottom:1px solid #eee;">
<th style="padding:10px;">Product</th>
<th style="padding:10px;">Sold</th>
<th style="padding:10px;">Revenue</th>
</tr>
</thead>

<tbody>

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

<tr class="total-row" style="background:#f1f3f7;font-weight:600;">
<td colspan="2" style="padding:10px;text-align:right;">TOTAL</td>
<td style="padding:10px;color:#001a47;">
₱<?= number_format($dailyTotalRevenue,2) ?>
</td>
</tr>

<?php endif; ?>

</tbody>
</table>
</div>

<?php endif; ?>

</div>


<!-- ✅ RIGHT: GRAPH -->
<div class="card">

<div class="card-header chart-header">
<span>Monthly Sales</span>

<form method="GET" id="yearFilterForm">
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

<div class="summary-line">
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

<div class="card-header month-nav">

<button onclick="changeMonth(-1)">&lt;</button>
<strong><?= $monthName ?> <?= $selectedYear ?></strong>
<button onclick="changeMonth(1)">&gt;</button>

</div>

<div class="calendar">

<?php for($i=1;$i<=$daysInMonth;$i++): 

$dayClasses=[];
$isToday = $i==$todayDay && $selectedMonth==$todayMonth && $selectedYear==$todayYear;
if($isToday){
$dayClasses[]="today";
}

if($i==$selectedDay && !$isToday){
$dayClasses[]="selected-day";
}

?>

<div 
class="calendar-day <?= implode(' ', $dayClasses) ?>"
onclick="selectDay(<?= $i ?>)"
data-day="<?= $i ?>"
data-sales="&#8369;<?= number_format($dailySales[$i]) ?>"
>
<strong class="calendar-day-number"><?= $i ?></strong>
₱<?= number_format($dailySales[$i]) ?>
</div>

<?php endfor; ?>

</div>

</div>

</div>
</div>

<div class="container legacy-hide">
    <div class="section-title">Top Products</div>

<div class="card">

<?php if(!empty($topProducts)): ?>
<div class="top-seller">
🔥 Best Seller: <?= htmlspecialchars($topProducts[0]['product_name']) ?>
</div>
<?php endif; ?>

<div class="table-wrap">
<table class="dashboard-table top-products-table">
<thead>
<tr style="text-align:left;border-bottom:1px solid #eee;">
<th style="padding:10px;">Product</th>
<th style="padding:10px;">Sold</th>
<th style="padding:10px;">Revenue</th>
</tr>
</thead>

<tbody>

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

</tbody>
</table>
</div>

</div>

</div>

<?php include 'bottom_nav.php'; ?>


<script>
const chartLabels = <?= json_encode($trendLabels) ?>;
const chartSeries = {
    sales: <?= json_encode($salesTrend) ?>,
    orders: <?= json_encode($ordersTrend) ?>,
    buyers: <?= json_encode($buyersTrend) ?>,
    visitors: <?= json_encode($visitorsTrend) ?>,
    followers: <?= json_encode($followersTrend) ?>
};

const metricLabels = {
    sales: "Sales",
    orders: "Orders",
    buyers: "Buyers",
    visitors: "Visitors",
    followers: "New Followers"
};

const axisLabelByPeriod = {
    day: "Hours",
    week: "Dates",
    month: "Dates",
    year: "Months"
};

const trendChart = new Chart(document.getElementById("trendChart"), {
    type: "line",
    data: {
        labels: chartLabels,
        datasets: [{
            label: "Sales",
            data: chartSeries.sales,
            borderColor: "#001a47",
            backgroundColor: "rgba(0,26,71,.12)",
            fill: true,
            tension: .35,
            pointRadius: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: {
                title: {
                    display: true,
                    text: axisLabelByPeriod["<?= htmlspecialchars($period) ?>"]
                }
            },
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: "Sales"
                }
            }
        }
    }
});

new Chart(document.getElementById("barChart"), {
    type: "bar",
    data: {
        labels: chartLabels,
        datasets: [
            {
                label: "Sales",
                data: chartSeries.sales,
                backgroundColor: "rgba(0,26,71,.78)",
                yAxisID: "y"
            },
            {
                label: "Buyers",
                data: chartSeries.buyers,
                backgroundColor: "rgba(15,118,110,.72)",
                yAxisID: "y1"
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: "bottom" } },
        scales: {
            x: {
                title: {
                    display: true,
                    text: axisLabelByPeriod["<?= htmlspecialchars($period) ?>"]
                }
            },
            y: {
                beginAtZero: true,
                position: "left",
                title: {
                    display: true,
                    text: "Sales"
                }
            },
            y1: { beginAtZero: true, position: "right", grid: { drawOnChartArea: false } }
        }
    }
});

new Chart(document.getElementById("genderChart"), {
    type: "bar",
    data: {
        labels: ["Male Buyers", "Female Buyers"],
        datasets: [{
            label: "Buyers",
            data: [<?= $totalMen ?>, <?= $totalWomen ?>],
            backgroundColor: ["#001a47", "#0f766e"],
            borderRadius: 10,
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            x: {
                grid: {
                    display: false
                }
            },
            y: {
                beginAtZero: true,
                ticks: {
                    precision: 0
                },
                title: {
                    display: true,
                    text: "Buyers"
                }
            }
        }
    }
});

document.querySelectorAll(".score-card[data-metric]").forEach(card => {
    card.addEventListener("click", () => {
        const metric = card.dataset.metric;
        if(!chartSeries[metric]) return;

        document.querySelectorAll(".score-card").forEach(item => item.classList.remove("active"));
        card.classList.add("active");

        trendChart.data.datasets[0].label = metricLabels[metric];
        trendChart.data.datasets[0].data = chartSeries[metric];
        trendChart.data.datasets[0].borderColor = metric === "sales" ? "#001a47" : "#0f766e";
        trendChart.data.datasets[0].backgroundColor = metric === "sales" ? "rgba(0,26,71,.12)" : "rgba(15,118,110,.12)";
        trendChart.options.scales.y.title.text = metricLabels[metric];
        trendChart.update();

        document.getElementById("trendLabel").textContent = metricLabels[metric] + " over selected <?= htmlspecialchars($period) ?>";
    });
});
</script>


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
const dashboardScrollStorageKey = "profdashboard_scroll_y";
const yearFilterForm = document.getElementById("yearFilterForm");

let selectedDay = null;
let activeMetric = "sales";

function saveDashboardScrollPosition(){
try{
sessionStorage.setItem(dashboardScrollStorageKey, String(window.scrollY || window.pageYOffset || 0));
}catch(error){
// Ignore storage failures and continue navigation normally.
}
}

function restoreDashboardScrollPosition(){
try{
const savedScrollY = parseInt(sessionStorage.getItem(dashboardScrollStorageKey), 10);

if(Number.isFinite(savedScrollY)){
window.scrollTo(0, savedScrollY);
sessionStorage.removeItem(dashboardScrollStorageKey);
}
}catch(error){
// Ignore storage failures and keep the default browser behavior.
}
}

restoreDashboardScrollPosition();

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
saveDashboardScrollPosition();

if(selectedDayFromPHP === day){
window.location.href = "?month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&day=0";
return;
}

window.location.href = "?month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&day=" + day;
}

/* ===== CHANGE MONTH ===== */
function changeMonth(step){

saveDashboardScrollPosition();

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

if(yearFilterForm){
yearFilterForm.addEventListener("submit", function(){
saveDashboardScrollPosition();
});
}

</script>
<div class="page-end-space"></div>
</body>
</html>
