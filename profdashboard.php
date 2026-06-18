<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/orders_helper.php";
require_once "config/item_reviews_helper.php";


/* ================= DASHBOARD PROTECTION ================= */

if($_SESSION['account_type'] !== "business_owner"){
    header("Location: more.php");
    exit;
}

/* BUSINESS OWNER ID (IMPORTANT: DO NOT MIX WITH VIEW ID) */
$business_id = $_SESSION['user_id'];

ensureOrderPaymentSupport($conn);


/* ================= FILTER ================= */

$allowedPeriods = ['day', 'week', 'month', 'quarter', 'year'];
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
}elseif($period === 'quarter'){
    $selectedYearValue = (int)$dateObj->format('Y');
    $selectedMonthValue = (int)$dateObj->format('n');

    if($selectedMonthValue <= 3){
        $quarterNumber = 1;
        $quarterStartMonth = 1;
        $quarterMonthCount = 3;
    }elseif($selectedMonthValue <= 6){
        $quarterNumber = 2;
        $quarterStartMonth = 4;
        $quarterMonthCount = 3;
    }elseif($selectedMonthValue <= 9){
        $quarterNumber = 3;
        $quarterStartMonth = 7;
        $quarterMonthCount = 3;
    }else{
        $quarterNumber = 4;
        $quarterStartMonth = 10;
        $quarterMonthCount = 2;
    }

    $rangeStart->setDate($selectedYearValue, $quarterStartMonth, 1)->setTime(0, 0, 0);
    $rangeEnd = clone $rangeStart;
    $rangeEnd->modify('+' . $quarterMonthCount . ' months');
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
}elseif($period === 'quarter'){
    $rangeLabelEnd = clone $rangeEnd;
    $rangeLabelEnd->modify('-1 day');
    $rangeLabel = 'Q' . $quarterNumber . ' - ' . $rangeStart->format('F') . ' to ' . $rangeLabelEnd->format('F Y');
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
    }elseif($period === 'quarter'){
        $end = clone $start;
        $end->modify(((int)$start->format('n') === 10) ? '+2 months' : '+3 months');
        $cursor = clone $start;

        while($cursor < $end){
            $labels[] = $cursor->format("M");
            $keys[] = $cursor->format("m");
            $cursor->modify("+1 month");
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
}elseif($period === 'quarter'){
    $ordersGroup = "DATE_FORMAT(created_at, '%m')";
    $visitsGroup = "DATE_FORMAT(visited_at, '%m')";
    $followersGroup = "DATE_FORMAT(created_at, '%m')";
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

/* ================= PAYMENTS ================= */

$paymentStats = [
    'cash' => ['label' => 'Cash', 'orders' => 0, 'revenue' => 0.0],
    'gcash' => ['label' => 'GCash', 'orders' => 0, 'revenue' => 0.0],
    'other' => ['label' => 'Other', 'orders' => 0, 'revenue' => 0.0]
];

$paymentStmt = $conn->prepare("
SELECT
LOWER(COALESCE(NULLIF(payment_method, ''), 'other')) AS payment_method,
COUNT(DISTINCT order_code) AS order_count,
COALESCE(SUM(price * quantity), 0) AS revenue
FROM orders
WHERE business_id = ?
AND status = 'Completed'
AND created_at >= ?
AND created_at < ?
GROUP BY LOWER(COALESCE(NULLIF(payment_method, ''), 'other'))
");

$paymentStmt->bind_param("iss", $business_id, $rangeStartSql, $rangeEndSql);
$paymentStmt->execute();
$paymentRes = $paymentStmt->get_result();

while($row = $paymentRes->fetch_assoc()){
    $method = strtolower(trim((string)($row['payment_method'] ?? 'other')));
    $key = 'other';

    if(strpos($method, 'gcash') !== false){
        $key = 'gcash';
    }elseif(strpos($method, 'cash') !== false){
        $key = 'cash';
    }

    $paymentStats[$key]['orders'] += (int)($row['order_count'] ?? 0);
    $paymentStats[$key]['revenue'] += (float)($row['revenue'] ?? 0);
}

$totalPaymentOrders = array_sum(array_column($paymentStats, 'orders'));
$totalPaymentRevenue = array_sum(array_column($paymentStats, 'revenue'));

/* ================= NEGATIVE REVIEWS ================= */

function dashboardTableExists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");

    if(!$stmt){
        return false;
    }

    $stmt->bind_param("s", $table);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

function dashboardAddRatingStats(mysqli $conn, string $sql, string $types, array $params, array &$ratingCounts): void {
    $stmt = $conn->prepare($sql);

    if(!$stmt){
        return;
    }

    $bindValues = [$types];
    foreach($params as $key => $value){
        $bindValues[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bindValues);
    $stmt->execute();
    $result = $stmt->get_result();

    while($row = $result->fetch_assoc()){
        $rating = (int)($row['rating'] ?? 0);

        if($rating >= 1 && $rating <= 5){
            $ratingCounts[$rating] += (int)($row['total'] ?? 0);
        }
    }

    $stmt->close();
}

$ratingCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

dashboardAddRatingStats(
    $conn,
    "
    SELECT experience_rating AS rating, COUNT(*) AS total
    FROM reviews
    WHERE business_id = ?
    AND experience_rating IS NOT NULL
    AND created_at >= ?
    AND created_at < ?
    GROUP BY experience_rating
    ",
    "iss",
    [$business_id, $rangeStartSql, $rangeEndSql],
    $ratingCounts
);

if(dashboardTableExists($conn, 'product_reviews')){
    dashboardAddRatingStats(
        $conn,
        "
        SELECT pr.rating, COUNT(*) AS total
        FROM product_reviews pr
        INNER JOIN inventory i ON i.id = pr.product_id
        WHERE i.owner_id = ?
        AND COALESCE(pr.updated_at, pr.created_at) >= ?
        AND COALESCE(pr.updated_at, pr.created_at) < ?
        GROUP BY pr.rating
        ",
        "iss",
        [$business_id, $rangeStartSql, $rangeEndSql],
        $ratingCounts
    );
}

if(dashboardTableExists($conn, 'service_reviews')){
    dashboardAddRatingStats(
        $conn,
        "
        SELECT sr.rating, COUNT(*) AS total
        FROM service_reviews sr
        INNER JOIN services s ON s.id = sr.service_id
        WHERE s.owner_id = ?
        AND COALESCE(sr.updated_at, sr.created_at) >= ?
        AND COALESCE(sr.updated_at, sr.created_at) < ?
        GROUP BY sr.rating
        ",
        "iss",
        [$business_id, $rangeStartSql, $rangeEndSql],
        $ratingCounts
    );
}

$totalRatings = array_sum($ratingCounts);
$positiveRatings = $ratingCounts[4] + $ratingCounts[5];
$neutralRatings = $ratingCounts[3];
$negativeRatings = $ratingCounts[1] + $ratingCounts[2];
$ratingSum = 0;

foreach($ratingCounts as $rating => $count){
    $ratingSum += $rating * $count;
}

$averageRating = $totalRatings > 0 ? $ratingSum / $totalRatings : 0.0;
$positivePercent = $totalRatings > 0 ? ($positiveRatings / $totalRatings) * 100 : 0;
$neutralPercent = $totalRatings > 0 ? ($neutralRatings / $totalRatings) * 100 : 0;
$negativePercent = $totalRatings > 0 ? ($negativeRatings / $totalRatings) * 100 : 0;

$negativeReviews = [];

$businessNegativeStmt = $conn->prepare("
SELECT
'Business' AS review_type,
b.business_name AS item_name,
r.experience_rating AS rating,
r.comment,
r.created_at,
r.is_anonymous,
c.fname,
c.lname,
c.username,
NULL AS owner_fname,
NULL AS owner_lname,
NULL AS owner_username
FROM reviews r
LEFT JOIN business_owner b ON b.b_id = r.business_id
LEFT JOIN consumers c ON c.c_id = r.user_id
WHERE r.business_id = ?
AND r.experience_rating IS NOT NULL
AND r.experience_rating <= 2
AND r.created_at >= ?
AND r.created_at < ?
ORDER BY r.created_at DESC, r.id DESC
LIMIT 12
");

if($businessNegativeStmt){
    $businessNegativeStmt->bind_param("iss", $business_id, $rangeStartSql, $rangeEndSql);
    $businessNegativeStmt->execute();
    $businessNegativeRes = $businessNegativeStmt->get_result();

    while($row = $businessNegativeRes->fetch_assoc()){
        $negativeReviews[] = $row;
    }

    $businessNegativeStmt->close();
}

if(dashboardTableExists($conn, 'product_reviews')){
    ensureItemReviewAccountType($conn, "product_reviews", "product_id", "unique_product_review");

    $productNegativeStmt = $conn->prepare("
    SELECT
    'Product' AS review_type,
    i.name AS item_name,
    pr.rating,
    pr.comment,
    COALESCE(pr.updated_at, pr.created_at) AS created_at,
    pr.is_anonymous,
    c.fname,
    c.lname,
    c.username,
    bo.fname AS owner_fname,
    bo.lname AS owner_lname,
    bo.username AS owner_username
    FROM product_reviews pr
    INNER JOIN inventory i ON i.id = pr.product_id
    LEFT JOIN consumers c
        ON c.c_id = pr.user_id
       AND COALESCE(pr.reviewer_account_type, 'consumer') = 'consumer'
    LEFT JOIN business_owner bo
        ON bo.b_id = pr.user_id
       AND pr.reviewer_account_type = 'business_owner'
    WHERE i.owner_id = ?
    AND pr.rating <= 2
    AND COALESCE(pr.updated_at, pr.created_at) >= ?
    AND COALESCE(pr.updated_at, pr.created_at) < ?
    ORDER BY COALESCE(pr.updated_at, pr.created_at) DESC, pr.id DESC
    LIMIT 12
    ");

    if($productNegativeStmt){
        $productNegativeStmt->bind_param("iss", $business_id, $rangeStartSql, $rangeEndSql);
        $productNegativeStmt->execute();
        $productNegativeRes = $productNegativeStmt->get_result();

        while($row = $productNegativeRes->fetch_assoc()){
            $negativeReviews[] = $row;
        }

        $productNegativeStmt->close();
    }
}

if(dashboardTableExists($conn, 'service_reviews')){
    ensureItemReviewAccountType($conn, "service_reviews", "service_id", "unique_service_review");

    $serviceNegativeStmt = $conn->prepare("
    SELECT
    'Service' AS review_type,
    s.name AS item_name,
    sr.rating,
    sr.comment,
    COALESCE(sr.updated_at, sr.created_at) AS created_at,
    sr.is_anonymous,
    c.fname,
    c.lname,
    c.username,
    bo.fname AS owner_fname,
    bo.lname AS owner_lname,
    bo.username AS owner_username
    FROM service_reviews sr
    INNER JOIN services s ON s.id = sr.service_id
    LEFT JOIN consumers c
        ON c.c_id = sr.user_id
       AND COALESCE(sr.reviewer_account_type, 'consumer') = 'consumer'
    LEFT JOIN business_owner bo
        ON bo.b_id = sr.user_id
       AND sr.reviewer_account_type = 'business_owner'
    WHERE s.owner_id = ?
    AND sr.rating <= 2
    AND COALESCE(sr.updated_at, sr.created_at) >= ?
    AND COALESCE(sr.updated_at, sr.created_at) < ?
    ORDER BY COALESCE(sr.updated_at, sr.created_at) DESC, sr.id DESC
    LIMIT 12
    ");

    if($serviceNegativeStmt){
        $serviceNegativeStmt->bind_param("iss", $business_id, $rangeStartSql, $rangeEndSql);
        $serviceNegativeStmt->execute();
        $serviceNegativeRes = $serviceNegativeStmt->get_result();

        while($row = $serviceNegativeRes->fetch_assoc()){
            $negativeReviews[] = $row;
        }

        $serviceNegativeStmt->close();
    }
}

usort($negativeReviews, function($a, $b){
    return strtotime($b['created_at'] ?? '') <=> strtotime($a['created_at'] ?? '');
});

$negativeReviews = array_slice($negativeReviews, 0, 8);

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
width:min(100%,520px);
min-width:520px;
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
grid-template-columns:repeat(5,minmax(0,1fr));
gap:6px;
}

.period-tabs button,
.score-card{
border:none;
cursor:pointer;
font-family:inherit;
}

.period-tabs button{
min-height:40px;
padding:9px 8px;
border-radius:10px;
background:#f1f5f9;
color:#334155;
font-weight:700;
white-space:nowrap;
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

.payment-review-grid{
display:grid;
grid-template-columns:.9fr 1.1fr;
gap:16px;
margin-bottom:18px;
}

.payment-list{
display:flex;
flex-direction:column;
gap:12px;
}

.payment-row{
display:grid;
grid-template-columns:48px minmax(0,1fr) auto;
align-items:center;
gap:12px;
padding:12px;
border:1px solid #e5e7eb;
border-radius:14px;
background:#f8fafc;
}

.payment-icon{
width:48px;
height:48px;
border-radius:12px;
display:flex;
align-items:center;
justify-content:center;
font-weight:900;
color:#fff;
background:#001a47;
}

.payment-icon.gcash{
background:#0f766e;
}

.payment-icon.other{
background:#475569;
}

.payment-name,
.review-source{
font-weight:800;
color:#001a47;
overflow-wrap:anywhere;
}

.payment-meta,
.review-meta-line{
margin-top:3px;
font-size:12px;
font-weight:700;
color:#64748b;
}

.payment-value{
text-align:right;
font-weight:900;
color:#001a47;
white-space:nowrap;
}

.payment-share{
margin-top:3px;
font-size:12px;
font-weight:800;
color:#0f766e;
}

.review-stats-grid{
display:grid;
grid-template-columns:repeat(4,minmax(0,1fr));
gap:10px;
margin-bottom:12px;
}

.review-stat{
padding:12px;
border-radius:14px;
background:#f8fafc;
border:1px solid #e5e7eb;
}

.review-stat.positive{
background:#ecfdf3;
border-color:#bbf7d0;
}

.review-stat.negative{
background:#fef2f2;
border-color:#fecaca;
}

.review-stat.neutral{
background:#f8fafc;
border-color:#cbd5e1;
}

.review-stat strong,
.review-stat span,
.review-stat small{
display:block;
}

.review-stat strong{
font-size:22px;
color:#001a47;
}

.review-stat.positive strong,
.review-stat.positive small{
color:#15803d;
}

.review-stat.negative strong,
.review-stat.negative small{
color:#b91c1c;
}

.review-stat span{
font-size:12px;
font-weight:800;
color:#475569;
}

.review-stat small{
margin-top:3px;
font-size:12px;
font-weight:800;
color:#64748b;
}

.rating-breakdown{
display:flex;
flex-direction:column;
gap:8px;
margin:12px 0;
}

.rating-breakdown-row{
display:grid;
grid-template-columns:56px minmax(0,1fr) 86px;
align-items:center;
gap:10px;
font-size:12px;
font-weight:800;
color:#475569;
}

.rating-bar{
height:9px;
border-radius:999px;
background:#e5e7eb;
overflow:hidden;
}

.rating-bar span{
display:block;
height:100%;
border-radius:999px;
background:#0f766e;
}

.rating-breakdown-row.low .rating-bar span{
background:#b91c1c;
}

.rating-breakdown-row.mid .rating-bar span{
background:#64748b;
}

.review-feed{
display:flex;
flex-direction:column;
gap:10px;
max-height:430px;
overflow:auto;
padding-right:4px;
}

.negative-review{
padding:12px;
border:1px solid #e5e7eb;
border-radius:14px;
background:#fff;
}

.negative-review-top{
display:flex;
justify-content:space-between;
align-items:flex-start;
gap:10px;
margin-bottom:8px;
}

.review-rating-badge{
flex:0 0 auto;
display:inline-flex;
align-items:center;
gap:5px;
padding:5px 8px;
border-radius:999px;
background:#fee2e2;
color:#b91c1c;
font-size:12px;
font-weight:900;
}

.review-comment-preview{
font-size:13px;
line-height:1.5;
color:#334155;
white-space:pre-wrap;
overflow-wrap:anywhere;
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
.theme-dark .buyer-breakdown strong,
.theme-dark .payment-name,
.theme-dark .payment-value,
.theme-dark .review-source{
color:#ededed !important;
}

.theme-dark .dashboard-hero p,
.theme-dark .score-card span,
.theme-dark .panel-title small,
.theme-dark .payment-meta,
.theme-dark .review-meta-line,
.theme-dark .review-comment-preview{
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
.theme-dark .insight-note,
.theme-dark .review-stat,
.theme-dark .payment-row,
.theme-dark .negative-review{
background:#1a1a1a;
}

.theme-dark .review-stat strong{
color:#ededed;
}

.theme-dark .review-stat.positive strong,
.theme-dark .review-stat.positive small{
color:#4ade80;
}

.theme-dark .review-stat.negative strong,
.theme-dark .review-stat.negative small{
color:#f87171;
}

.theme-dark .rating-bar{
background:#2d2d2d;
}

@media (max-width:900px){
.dashboard-hero,
.analytics-grid,
.content-grid,
.payment-review-grid,
.gender-card{
grid-template-columns:1fr;
display:grid;
}

.filter-panel{
min-width:0;
width:100%;
}

.score-grid{
grid-template-columns:repeat(2,minmax(0,1fr));
}
}

@media (max-width:560px){
.dashboard-hero h1{
font-size:24px;
}

.review-stats-grid{
grid-template-columns:repeat(2,minmax(0,1fr));
}

.rating-breakdown-row{
grid-template-columns:48px minmax(0,1fr);
}

.rating-breakdown-row > span:last-child{
grid-column:2;
}

.filter-panel,
.score-grid{
grid-template-columns:1fr;
}

.period-tabs{
gap:5px;
}

.period-tabs button{
font-size:13px;
padding:8px 4px;
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
            <?php foreach(['day'=>'Day','week'=>'Week','month'=>'Month','quarter'=>'Quarter','year'=>'Year'] as $value=>$label): ?>
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

<div class="payment-review-grid">
    <div class="card">
        <div class="panel-title">
            <div>
                <span>Payment Statistics</span>
                <small>Cash and GCash sales for <?= htmlspecialchars($rangeLabel) ?></small>
            </div>
        </div>

        <div class="payment-list">
            <?php foreach($paymentStats as $key => $stat): ?>
            <?php
            $paymentShare = $totalPaymentRevenue > 0 ? (((float)$stat['revenue'] / $totalPaymentRevenue) * 100) : 0;
            $iconText = $key === 'gcash' ? 'GC' : ($key === 'cash' ? 'CA' : 'OT');
            ?>
            <div class="payment-row">
                <div class="payment-icon <?= htmlspecialchars($key) ?>"><?= htmlspecialchars($iconText) ?></div>
                <div>
                    <div class="payment-name"><?= htmlspecialchars($stat['label']) ?></div>
                    <div class="payment-meta"><?= number_format((int)$stat['orders']) ?> completed order<?= (int)$stat['orders'] === 1 ? '' : 's' ?></div>
                </div>
                <div>
                    <div class="payment-value">&#8369;<?= number_format((float)$stat['revenue'], 2) ?></div>
                    <div class="payment-share"><?= number_format($paymentShare, 1) ?>%</div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="insight-note">
            <?php if($totalPaymentOrders <= 0): ?>
            No completed payments are available for this selected period.
            <?php elseif($paymentStats['gcash']['revenue'] > $paymentStats['cash']['revenue']): ?>
            GCash is the stronger payment method for this selected period.
            <?php elseif($paymentStats['cash']['revenue'] > $paymentStats['gcash']['revenue']): ?>
            Cash is the stronger payment method for this selected period.
            <?php else: ?>
            Cash and GCash sales are even for this selected period.
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="panel-title">
            <div>
                <span>Ratings & Reviews Statistics</span>
                <small>Positive, neutral, and negative ratings for <?= htmlspecialchars($rangeLabel) ?></small>
            </div>
        </div>

        <div class="review-stats-grid">
            <div class="review-stat">
                <strong><?= number_format($totalRatings) ?></strong>
                <span>Total Ratings</span>
                <small><?= $totalRatings > 0 ? number_format($averageRating, 1) . ' avg' : '0.0 avg' ?></small>
            </div>
            <div class="review-stat positive">
                <strong><?= number_format($positiveRatings) ?></strong>
                <span>Positive</span>
                <small><?= number_format($positivePercent, 1) ?>%</small>
            </div>
            <div class="review-stat neutral">
                <strong><?= number_format($neutralRatings) ?></strong>
                <span>Neutral</span>
                <small><?= number_format($neutralPercent, 1) ?>%</small>
            </div>
            <div class="review-stat negative">
                <strong><?= number_format($negativeRatings) ?></strong>
                <span>Negative</span>
                <small><?= number_format($negativePercent, 1) ?>%</small>
            </div>
        </div>

        <div class="rating-breakdown" aria-label="Rating breakdown">
            <?php for($rating = 5; $rating >= 1; $rating--): ?>
            <?php
            $ratingCount = $ratingCounts[$rating] ?? 0;
            $ratingPercent = $totalRatings > 0 ? ($ratingCount / $totalRatings) * 100 : 0;
            $ratingTone = $rating >= 4 ? 'high' : ($rating === 3 ? 'mid' : 'low');
            ?>
            <div class="rating-breakdown-row <?= htmlspecialchars($ratingTone) ?>">
                <span><?= $rating ?> star</span>
                <div class="rating-bar"><span style="width:<?= number_format($ratingPercent, 2, '.', '') ?>%"></span></div>
                <span><?= number_format($ratingCount) ?> (<?= number_format($ratingPercent, 1) ?>%)</span>
            </div>
            <?php endfor; ?>
        </div>

        <?php if(empty($negativeReviews)): ?>
        <div class="empty-cell">No negative ratings or reviews for this filter.</div>
        <?php else: ?>
        <div class="panel-title" style="margin-top:14px;">
            <div>
                <span>Recent Negative Reviews</span>
                <small>1 to 2 star comments that may need attention</small>
            </div>
        </div>
        <div class="review-feed">
            <?php foreach($negativeReviews as $review): ?>
            <?php
            $consumerName = trim(($review['fname'] ?? '') . ' ' . ($review['lname'] ?? ''));
            $ownerName = trim(($review['owner_fname'] ?? '') . ' ' . ($review['owner_lname'] ?? ''));
            $reviewerName = $consumerName !== ''
                ? $consumerName
                : ($ownerName !== '' ? $ownerName : ($review['username'] ?? ($review['owner_username'] ?? 'Customer')));
            if((int)($review['is_anonymous'] ?? 0) === 1){
                $reviewerName = 'Anonymous customer';
            }
            $reviewDate = !empty($review['created_at']) ? date("M j, Y", strtotime($review['created_at'])) : '';
            ?>
            <div class="negative-review">
                <div class="negative-review-top">
                    <div>
                        <div class="review-source"><?= htmlspecialchars($review['review_type'] . ': ' . ($review['item_name'] ?? 'Untitled')) ?></div>
                        <div class="review-meta-line"><?= htmlspecialchars($reviewerName) ?><?= $reviewDate !== '' ? ' &middot; ' . htmlspecialchars($reviewDate) : '' ?></div>
                    </div>
                    <div class="review-rating-badge">
                        <span><?= number_format((float)($review['rating'] ?? 0), 0) ?></span>
                        <span>star<?= (int)($review['rating'] ?? 0) === 1 ? '' : 's' ?></span>
                    </div>
                </div>
                <div class="review-comment-preview"><?= nl2br(htmlspecialchars((string)($review['comment'] ?? ''))) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
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
    quarter: "Months",
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
