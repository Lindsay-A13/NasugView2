<?php

session_start();

require_once "config/db.php";
require_once "config/cart_count.php";
/* ================= FOLLOW / UNFOLLOW ================= */

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])){

    $user_id = $_SESSION['user_id'];
    $business_id = $_POST['business_id'] ?? 0;

    // FOLLOW
    if(isset($_POST['follow'])){
        $stmt = $conn->prepare("
            INSERT IGNORE INTO business_followers (user_id, business_id)
            VALUES (?, ?)
        ");
        $stmt->bind_param("ii", $user_id, $business_id);
        $stmt->execute();
    }

    // UNFOLLOW
    if(isset($_POST['unfollow'])){
        $stmt = $conn->prepare("
            DELETE FROM business_followers
            WHERE user_id = ? AND business_id = ?
        ");
        $stmt->bind_param("ii", $user_id, $business_id);
        $stmt->execute();
    }

    // Refresh to avoid resubmit
    header("Location: businessdetails.php?id=" . $business_id);
    exit();
}

$business_id = $_GET['id'] ?? null;

if($business_id && isset($_SESSION['user_id'], $_SESSION['account_type'])){

    $user_id = $_SESSION['user_id'];
    $account_type = $_SESSION['account_type'];

    $consumer_id = null;
    $owner_id = null;

    /* 🚫 DO NOT TRACK IF OWNER IS VIEWING THEIR OWN BUSINESS */
    if(!($account_type === "business_owner" && $user_id == $business_id)){

        if($account_type === "consumer"){
            $consumer_id = $user_id;
        }

        if($account_type === "business_owner"){
            $owner_id = $user_id;
        }

        $stmt = $conn->prepare("
        INSERT INTO business_visits (business_id, consumer_id, owner_id)
        SELECT ?, ?, ?
        WHERE NOT EXISTS (
            SELECT 1 FROM business_visits
            WHERE business_id = ?
            AND DATE(visited_at) = CURDATE()
            AND (
                (consumer_id IS NOT NULL AND consumer_id = ?)
                OR
                (owner_id IS NOT NULL AND owner_id = ?)
            )
        )
        ");

        $stmt->bind_param(
            "iiiiii",
            $business_id,
            $consumer_id,
            $owner_id,
            $business_id,
            $consumer_id,
            $owner_id
        );

        $stmt->execute();
    }
}

$id = $_GET['id'] ?? 0;

if(!$id){
    die("Business not found.");
}

/* LOAD BUSINESS */
$stmt = $conn->prepare("
    SELECT b_id, business_name, description, phone, address, business_photo
    FROM business_owner
    WHERE b_id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$business = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* ================= FOLLOW CHECK ================= */
$isOwner = false;
$isFollowing = false;

if(isset($_SESSION['user_id'])){
    $logged_user_id = $_SESSION['user_id'];

    // ✅ FIX OWNER CHECK (SIMPLE & WORKING)
    if($logged_user_id == $id){
        $isOwner = true;
    }

    // ✅ FOLLOW CHECK
    $checkFollow = $conn->prepare("
        SELECT id FROM business_followers
        WHERE user_id = ? AND business_id = ?
    ");
    $checkFollow->bind_param("ii", $logged_user_id, $id);
    $checkFollow->execute();
    $resultFollow = $checkFollow->get_result();

    if($resultFollow->num_rows > 0){
        $isFollowing = true;
    }
}

if(!$business){
    die("Business not found.");
}

/* LOAD PRODUCTS */
$product_stmt = $conn->prepare("
    SELECT id, name, price, image
    FROM inventory
    WHERE owner_id = ?
    ORDER BY created_at DESC
");
$product_stmt->bind_param("i", $id);
$product_stmt->execute();
$products = $product_stmt->get_result();

/* LOAD SERVICES */
$service_stmt = $conn->prepare("
    SELECT id, name, price, image, duration
    FROM services
    WHERE owner_id = ?
    ORDER BY created_at DESC
");
$service_stmt->bind_param("i", $id);
$service_stmt->execute();
$services = $service_stmt->get_result();

/* LOAD REVIEWS */
$review_stmt = $conn->prepare("
    SELECT r.*, c.fname, c.lname
    FROM reviews r
    JOIN consumers c ON r.user_id = c.c_id
    WHERE r.business_id = ? 
    AND r.is_hidden = 0
    ORDER BY r.created_at DESC
");

if(!$review_stmt){
    die("Review query failed: " . $conn->error);
}

$review_stmt->bind_param("i", $id);
$review_stmt->execute();
$reviews = $review_stmt->get_result();
function maskName($name){
    $length = strlen($name);

    if($length <= 2){
        return $name;
    }

    return substr($name, 0, 1) .
           str_repeat('*', $length - 2) .
           substr($name, -1);
}

$mapLink = "https://www.google.com/maps/search/?api=1&query=" . urlencode($business['address']);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($business['business_name']) ?> | NasugView</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
*{box-sizing:border-box;}
body{margin:0;font-family:"Segoe UI", Arial, sans-serif;background:#fff;color:#333;}
.header{display:flex;justify-content:space-between;align-items:center;padding:12px 20px;border-bottom:1px solid #e5e7eb;background:#fff;}
.logo img{height:36px;}
.cart-btn{position:relative;background:rgba(0,26,71,0.08);padding:8px;border-radius:50%;color:#001a47;text-decoration:none;}
.cart-badge{position:absolute;top:-4px;right:-4px;background:#e74c3c;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:50%;}
.container{max-width:1000px;margin:0 auto;padding:20px 16px 120px 16px;}
.business-card{border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;background:#fff;}
.business-image{width:100%;height:300px;object-fit:cover;}
.business-content{padding:20px;}
.business-name{font-size:26px;font-weight:600;}
.info-list{display:flex;flex-direction:column;gap:10px;margin-bottom:18px;}
.info-item{display:flex;gap:10px;font-size:14px;align-items:center;}
.info-item i{color:#001a47;width:16px;}
.description{margin-bottom:20px;line-height:1.6;}

.products-section{margin-top:30px;}
.section-title{font-size:20px;font-weight:600;margin-bottom:16px;}

.products-grid{
display:grid;
grid-template-columns:repeat(auto-fill,minmax(180px,1fr));
gap:16px;
}

.product-card{
border:1px solid #e5e7eb;
border-radius:12px;
overflow:hidden;
background:#fff;
}

.product-img{
width:100%;
height:160px;
object-fit:cover;
}

.product-info{padding:10px;}
.product-name{font-size:14px;font-weight:600;}
.product-price{font-size:13px;color:#001a47;margin-top:4px;}

.review-card{
border:1px solid #e5e7eb;
border-radius:16px;
padding:18px;
margin-bottom:18px;
background:#ffffff;
box-shadow:0 2px 8px rgba(0,0,0,0.04);
transition:0.2s ease;
}

.review-card:hover{
box-shadow:0 4px 16px rgba(0,0,0,0.08);
}

.review-images{
display:flex;
gap:10px;
flex-wrap:wrap;
margin-top:8px;
}

.review-images img{
width:80px;
height:80px;
object-fit:cover;
border-radius:10px;
}

@media (max-width:600px){
.container{padding:16px 14px 120px 14px;}
.business-image{height:200px;}
.products-grid{grid-template-columns:repeat(2,1fr);}
.product-img{height:120px;}
}
</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="header">
<a href="index.php" class="logo">
<img src="assets/images/logo.png">
</a>

<a href="cart.php" class="cart-btn">
<i class="fa fa-shopping-cart"></i>
<?php if($cartCount > 0): ?>
<span class="cart-badge"><?= $cartCount ?></span>
<?php endif; ?>
</a>
</div>

<div class="container">

<!-- BUSINESS CARD -->
<div class="business-card">
<?php
$cover = !empty($business['business_photo']) 
    ? "uploads/business_cover/" . $business['business_photo']
    : "assets/images/logo.png";
?>

<img src="<?= $cover ?>" class="business-image">
<div class="business-content">
<div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">

<div class="business-name"><?= htmlspecialchars($business['business_name']) ?></div>

<?php if(!$isOwner && isset($_SESSION['user_id'])): ?><form method="POST" style="margin:0;">
    <input type="hidden" name="business_id" value="<?= $business['b_id'] ?>">

    <?php if($isFollowing): ?>
        <button type="submit" name="unfollow"
        style="background:#ccc;color:#000;padding:8px 14px;border:none;border-radius:8px;cursor:pointer;font-weight:600;">
        Following
        </button>
    <?php else: ?>
        <button type="submit" name="follow"
        style="background:#001a47;color:#fff;padding:8px 14px;border:none;border-radius:8px;cursor:pointer;font-weight:600;">
        Follow
        </button>
    <?php endif; ?>
</form>
<?php endif; ?>

</div>
<div class="info-list">
<div class="info-item">
<i class="fa fa-location-dot"></i>
<a href="<?= $mapLink ?>" target="_blank"><?= htmlspecialchars($business['address']) ?></a>
</div>

<div class="info-item">
<i class="fa fa-phone"></i>
<?= htmlspecialchars($business['phone']) ?>
</div>
</div>

<div class="description"><?= htmlspecialchars($business['description']) ?></div>

<div style="margin-top:15px; display:flex; gap:10px;">
<a href="submitreview.php?business_id=<?= $business['b_id'] ?>&type=yes"
style="flex:1;text-align:center;background:#001a47;color:#fff;padding:12px;border-radius:10px;text-decoration:none;font-weight:600;">
Yes
</a>

<a href="submitreview.php?business_id=<?= $business['b_id'] ?>&type=no"
style="flex:1;text-align:center;border:2px solid #001a47;color:#001a47;padding:10px;border-radius:10px;text-decoration:none;font-weight:600;">
No
</a>
</div>

</div>
</div>

<!-- PRODUCTS -->
<div class="products-section">
<div class="section-title">Products</div>

<div class="products-grid">
<?php if($products->num_rows > 0): ?>
<?php while($row = $products->fetch_assoc()): ?>
<a href="productdetails.php?id=<?= $row['id'] ?>" style="text-decoration:none;color:inherit;">
<div class="product-card">
<img src="uploads/product/<?= $row['image'] ?: 'default_product.jpg' ?>" class="product-img">
<div class="product-info">
<div class="product-name"><?= htmlspecialchars($row['name']) ?></div>
<div class="product-price">₱<?= number_format($row['price'],2) ?></div>
</div>
</div>
</a>
<?php endwhile; ?>
<?php else: ?>
<p>No products available.</p>
<?php endif; ?>
</div>
</div>

<!-- SERVICES -->
<?php if($services->num_rows > 0): ?>
<div class="products-section">
<div class="section-title">Services</div>

<div class="products-grid">
<?php while($row = $services->fetch_assoc()): ?>
<a href="servicedetails.php?id=<?= $row['id'] ?>" style="text-decoration:none;color:inherit;">
<div class="product-card">

<img src="uploads/services/<?= $row['image'] ?: 'default_service.jpg' ?>" class="product-img">

<div class="product-info">
<div class="product-name"><?= htmlspecialchars($row['name']) ?></div>

<div class="product-price">
₱<?= number_format($row['price'],2) ?>
</div>

<div style="font-size:12px;color:#666;margin-top:4px;">
<?= htmlspecialchars($row['duration']) ?> mins
</div>

</div>
</div>
</a>
<?php endwhile; ?>
</div>

</div>
<?php endif; ?>

<!-- REVIEWS -->
<div class="products-section">
<div class="section-title">Reviews</div>

<?php if($reviews->num_rows > 0): ?>
<?php while($review = $reviews->fetch_assoc()): ?>
<div class="review-card">

<div style="font-weight:600;">
<?php
$fname = $review['fname'] ?? '';
$lname = $review['lname'] ?? '';

if($review['is_anonymous'] == 1){

    $maskedFname = maskName($fname);
    $maskedLname = maskName($lname);

    echo htmlspecialchars(trim($maskedFname . ' ' . $maskedLname));

} else {

    echo htmlspecialchars(trim($fname . ' ' . $lname));

}
?>
</div>

<?php if(!empty($review['experience_rating'])): ?>
<div style="margin:5px 0;">
<?php for($i=1;$i<=5;$i++): ?>
<i class="fa fa-star" style="color:<?= $i <= $review['experience_rating'] ? '#001a47' : '#ddd' ?>"></i>
<?php endfor; ?>
</div>
<?php endif; ?>

<div><?= nl2br(htmlspecialchars($review['comment'])) ?></div>

<?php if(!empty($review['images'])): ?>
<?php 
$imgs = json_decode($review['images'], true);

if(is_array($imgs) && count($imgs) > 0): 
?>
<div class="review-images">
    <?php foreach($imgs as $img): ?>
        <img src="<?= htmlspecialchars($img) ?>">
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

</div>
<?php endwhile; ?>
<?php else: ?>
<p style="color:#666;">No reviews yet.</p>
<?php endif; ?>

</div>

<?php include 'bottom_nav.php'; ?>


</body>
</html>
