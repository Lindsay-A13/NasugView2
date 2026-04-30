<?php
session_start();

require_once "config/db.php";
require_once "config/cart_count.php";

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])){
    $user_id = (int) $_SESSION['user_id'];
    $business_id = (int) ($_POST['business_id'] ?? 0);

    if(isset($_POST['follow'])){
        $stmt = $conn->prepare("
            INSERT IGNORE INTO business_followers (user_id, business_id)
            VALUES (?, ?)
        ");
        $stmt->bind_param("ii", $user_id, $business_id);
        $stmt->execute();
        $stmt->close();
    }

    if(isset($_POST['unfollow'])){
        $stmt = $conn->prepare("
            DELETE FROM business_followers
            WHERE user_id = ? AND business_id = ?
        ");
        $stmt->bind_param("ii", $user_id, $business_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: businessdetails.php?id=" . $business_id);
    exit();
}

$business_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if($business_id > 0 && isset($_SESSION['user_id'], $_SESSION['account_type'])){
    $user_id = (int) $_SESSION['user_id'];
    $account_type = (string) $_SESSION['account_type'];
    $consumer_id = null;
    $owner_id = null;

    if(!($account_type === "business_owner" && $user_id === $business_id)){
        if($account_type === "consumer"){
            $consumer_id = $user_id;
        }

        if($account_type === "business_owner"){
            $owner_id = $user_id;
        }

        $visit_stmt = $conn->prepare("
            INSERT INTO business_visits (business_id, consumer_id, owner_id)
            SELECT ?, ?, ?
            WHERE NOT EXISTS (
                SELECT 1
                FROM business_visits
                WHERE business_id = ?
                  AND DATE(visited_at) = CURDATE()
                  AND (
                    (consumer_id IS NOT NULL AND consumer_id = ?)
                    OR
                    (owner_id IS NOT NULL AND owner_id = ?)
                  )
            )
        ");

        $visit_stmt->bind_param(
            "iiiiii",
            $business_id,
            $consumer_id,
            $owner_id,
            $business_id,
            $consumer_id,
            $owner_id
        );
        $visit_stmt->execute();
        $visit_stmt->close();
    }
}

$id = $business_id;

if($id <= 0){
    die("Business not found.");
}

$stmt = $conn->prepare("
    SELECT
        b.b_id,
        b.business_name,
        b.description,
        b.phone,
        b.address,
        b.business_photo,
        COALESCE(ROUND(AVG(r.experience_rating), 1), 0) AS avg_rating,
        COUNT(r.id) AS total_reviews
    FROM business_owner b
    LEFT JOIN reviews r
        ON r.business_id = b.b_id
        AND r.is_hidden = 0
    WHERE b.b_id = ?
    GROUP BY
        b.b_id,
        b.business_name,
        b.description,
        b.phone,
        b.address,
        b.business_photo
");
$stmt->bind_param("i", $id);
$stmt->execute();
$business = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$business){
    die("Business not found.");
}

$isOwner = false;
$isFollowing = false;

if(isset($_SESSION['user_id'])){
    $logged_user_id = (int) $_SESSION['user_id'];

    if($logged_user_id === $id){
        $isOwner = true;
    }

    $follow_check = $conn->prepare("
        SELECT id
        FROM business_followers
        WHERE user_id = ? AND business_id = ?
    ");
    $follow_check->bind_param("ii", $logged_user_id, $id);
    $follow_check->execute();
    $isFollowing = $follow_check->get_result()->num_rows > 0;
    $follow_check->close();
}

$product_stmt = $conn->prepare("
    SELECT id, name, price, image, stock, type
    FROM inventory
    WHERE owner_id = ?
    ORDER BY created_at DESC
");
$product_stmt->bind_param("i", $id);
$product_stmt->execute();
$products = $product_stmt->get_result();

$service_stmt = $conn->prepare("
    SELECT id, name, price, image, duration
    FROM services
    WHERE owner_id = ?
    ORDER BY created_at DESC
");
$service_stmt->bind_param("i", $id);
$service_stmt->execute();
$services = $service_stmt->get_result();

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

$followers_stmt = $conn->prepare("
    SELECT COUNT(*) AS total_followers
    FROM business_followers
    WHERE business_id = ?
");
$followers_stmt->bind_param("i", $id);
$followers_stmt->execute();
$followers = $followers_stmt->get_result()->fetch_assoc();
$followers_stmt->close();

function maskName($name){
    $length = strlen($name);

    if($length <= 2){
        return $name;
    }

    return substr($name, 0, 1) .
        str_repeat('*', $length - 2) .
        substr($name, -1);
}

$productCount = $products->num_rows;
$serviceCount = $services->num_rows;
$followerCount = (int) ($followers['total_followers'] ?? 0);
$avgRating = (float) ($business['avg_rating'] ?? 0);
$totalReviews = (int) ($business['total_reviews'] ?? 0);
$roundedAvgRating = (int) round($avgRating);
$mapLink = "https://www.google.com/maps/search/?api=1&query=" . urlencode($business['address'] ?? '');
$cover = !empty($business['business_photo'])
    ? "uploads/business_cover/" . $business['business_photo']
    : "assets/images/default-cover.png";
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($business['business_name']) ?> | NasugView</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="assets/css/responsive.css"/>

<style>
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:#fff;color:#0f172a}
.header{display:flex;justify-content:space-between;align-items:center;padding:12px 20px;border-bottom:1px solid #e5e7eb;position:sticky;top:0;background:#fff;z-index:1000}
.cart-btn,.cart-btn:visited{position:relative;background:rgba(0,26,71,0.08);padding:8px;border-radius:50%;color:#001a47;text-decoration:none}
.cart-badge{position:absolute;top:-4px;right:-4px;background:#e74c3c;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:50%}
.container{max-width:1100px;margin:auto;padding:20px;padding-bottom:130px}
.hero-card{display:grid;grid-template-columns:1.05fr .95fr;gap:28px;background:#fff;padding:24px;border-radius:16px;box-shadow:0 8px 22px rgba(0,0,0,.08)}
.business-image{width:100%;height:100%;min-height:320px;object-fit:cover;border-radius:14px;background:#f8fafc}
.eyebrow{font-size:13px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#64748b;margin-bottom:10px}
.business-name{margin:0 0 10px;font-size:32px;line-height:1.15;color:#001a47}
.business-meta{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px}
.meta-chip{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;font-size:13px;color:#334155}
.business-description{line-height:1.7;color:#334155;margin-bottom:18px;white-space:pre-wrap}
.rating-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px}
.rating-stars{display:flex;gap:4px;color:#001a47}
.rating-text{font-size:14px;color:#64748b}
.action-row,.feedback-row{display:flex;gap:12px;flex-wrap:wrap}
.feedback-row{margin-top:12px}
.action-btn,.follow-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 16px;border-radius:12px;text-decoration:none;font-weight:600;border:none;cursor:pointer;font:inherit}
.action-btn.primary,.follow-btn.follow{background:#001a47;color:#fff}
.action-btn.secondary,.follow-btn.following{border:1px solid #cbd5e1;color:#001a47;background:#fff}
.action-btn.outline{border:1px solid #cbd5e1;color:#334155;background:#fff}
.info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:28px}
.panel{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px}
.panel h2{margin:0 0 14px;font-size:18px;color:#001a47}
.details-list{display:flex;flex-direction:column;gap:10px}
.details-item{display:flex;gap:10px;align-items:flex-start;font-size:14px;color:#334155}
.details-item i{width:16px;color:#001a47;margin-top:2px}
.section-block,.reviews-section{margin-top:28px}
.section-title{margin:0 0 16px;font-size:20px;color:#001a47}
.listing-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px}
.listing-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden;text-decoration:none;color:inherit;box-shadow:0 6px 18px rgba(0,0,0,.05)}
.listing-image{width:100%;height:170px;object-fit:cover;background:#f8fafc}
.listing-body{padding:14px}
.listing-name{font-size:16px;font-weight:700;color:#001a47;line-height:1.3}
.listing-subtitle{font-size:13px;color:#64748b;margin-top:6px}
.listing-price{font-size:16px;font-weight:700;color:#001a47;margin-top:10px}
.review-list{display:flex;flex-direction:column;gap:14px}
.review-card{border:1px solid #e5e7eb;border-radius:16px;padding:18px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.review-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:10px}
.review-user{font-weight:700;color:#001a47}
.review-date{font-size:13px;color:#64748b}
.review-comment{line-height:1.6;color:#334155;white-space:pre-wrap}
.review-images{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px;max-width:340px}
.review-images img{width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:12px;background:#f8fafc}
.empty-state{padding:16px;border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc;color:#64748b}
@media (max-width:768px){
  .container{padding:16px;padding-bottom:120px}
  .hero-card{grid-template-columns:1fr;gap:20px;padding:18px}
  .business-image{min-height:240px}
  .business-name{font-size:28px}
  .info-grid{grid-template-columns:1fr}
  .listing-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="header">
<a href="cart.php" class="cart-btn">
<i class="fa fa-shopping-cart"></i>
<?php if($cartCount > 0): ?>
<span class="cart-badge"><?= $cartCount ?></span>
<?php endif; ?>
</a>
</div>

<div class="container">
<div class="hero-card">
<div>
<img src="<?= htmlspecialchars($cover) ?>" class="business-image" alt="<?= htmlspecialchars($business['business_name']) ?>">
</div>

<div>
<div class="eyebrow">Business</div>
<h1 class="business-name"><?= htmlspecialchars($business['business_name']) ?></h1>

<div class="business-meta">
<div class="meta-chip"><i class="fa fa-users"></i><?= $followerCount ?> follower<?= $followerCount === 1 ? '' : 's' ?></div>
<div class="meta-chip"><i class="fa fa-box"></i><?= $productCount ?> product<?= $productCount === 1 ? '' : 's' ?></div>
<div class="meta-chip"><i class="fa fa-briefcase"></i><?= $serviceCount ?> service<?= $serviceCount === 1 ? '' : 's' ?></div>
</div>

<div class="rating-row">
<div class="rating-stars" aria-label="<?= $totalReviews > 0 ? number_format($avgRating, 1) : '0.0' ?> out of 5 stars">
<?php for($i=1;$i<=5;$i++): ?>
<i class="fa <?= $i <= $roundedAvgRating ? 'fa-star' : 'fa-regular fa-star' ?>"></i>
<?php endfor; ?>
</div>
<div class="rating-text">
<?= $totalReviews > 0 ? number_format($avgRating, 1) : '0.0' ?> (<?= $totalReviews ?> review<?= $totalReviews === 1 ? '' : 's' ?>)
</div>
</div>

<div class="business-description"><?= htmlspecialchars($business['description'] ?: 'No business description available yet.') ?></div>

<div class="action-row">
<?php if(!$isOwner && isset($_SESSION['user_id'])): ?>
<form method="POST" style="margin:0;">
<input type="hidden" name="business_id" value="<?= $business['b_id'] ?>">
<?php if($isFollowing): ?>
<button type="submit" name="unfollow" class="follow-btn following">
<i class="fa fa-check"></i> Following
</button>
<?php else: ?>
<button type="submit" name="follow" class="follow-btn follow">
<i class="fa fa-plus"></i> Follow
</button>
<?php endif; ?>
</form>
<?php endif; ?>

<?php if(!empty($business['address'])): ?>
<a href="<?= htmlspecialchars($mapLink) ?>" target="_blank" class="action-btn secondary">
<i class="fa fa-location-dot"></i> Open Map
</a>
<?php endif; ?>
</div>

<div class="feedback-row">
<a href="submitreview.php?business_id=<?= $business['b_id'] ?>&type=yes" class="action-btn primary">
<i class="fa fa-thumbs-up"></i> Recommend
</a>
<a href="submitreview.php?business_id=<?= $business['b_id'] ?>&type=no" class="action-btn outline">
<i class="fa fa-thumbs-down"></i> Not Recommend
</a>
</div>
</div>
</div>

<div class="info-grid">
<div class="panel">
<h2>Contact & Location</h2>
<div class="details-list">
<?php if(!empty($business['address'])): ?>
<div class="details-item"><i class="fa fa-location-dot"></i><span><?= htmlspecialchars($business['address']) ?></span></div>
<?php endif; ?>
<?php if(!empty($business['phone'])): ?>
<div class="details-item"><i class="fa fa-phone"></i><span><?= htmlspecialchars($business['phone']) ?></span></div>
<?php endif; ?>
<?php if(empty($business['address']) && empty($business['phone'])): ?>
<div class="empty-state">No contact details were added for this business yet.</div>
<?php endif; ?>
</div>
</div>

<div class="panel">
<h2>Business Snapshot</h2>
<div class="details-list">
<div class="details-item"><i class="fa fa-star"></i><span><?= $totalReviews > 0 ? number_format($avgRating, 1) : '0.0' ?> average rating</span></div>
<div class="details-item"><i class="fa fa-comment"></i><span><?= $totalReviews ?> public review<?= $totalReviews === 1 ? '' : 's' ?></span></div>
<div class="details-item"><i class="fa fa-box"></i><span><?= $productCount ?> listed product<?= $productCount === 1 ? '' : 's' ?></span></div>
<div class="details-item"><i class="fa fa-briefcase"></i><span><?= $serviceCount ?> listed service<?= $serviceCount === 1 ? '' : 's' ?></span></div>
</div>
</div>
</div>

<div class="section-block">
<h2 class="section-title">Products</h2>
<?php if($productCount > 0): ?>
<div class="listing-grid">
<?php while($row = $products->fetch_assoc()): ?>
<a href="productdetails.php?id=<?= $row['id'] ?>" class="listing-card">
<img src="uploads/product/<?= htmlspecialchars($row['image'] ?: 'default_product.jpg') ?>" class="listing-image" alt="<?= htmlspecialchars($row['name']) ?>">
<div class="listing-body">
<div class="listing-name"><?= htmlspecialchars($row['name']) ?></div>
<div class="listing-subtitle">
<?= (int) ($row['stock'] ?? 0) ?> in stock<?php if(!empty($row['type'])): ?> • <?= htmlspecialchars(ucfirst($row['type'])) ?><?php endif; ?>
</div>
<div class="listing-price">&#8369;<?= number_format((float) $row['price'], 2) ?></div>
</div>
</a>
<?php endwhile; ?>
</div>
<?php else: ?>
<div class="empty-state">No products are available from this business yet.</div>
<?php endif; ?>
</div>

<div class="section-block">
<h2 class="section-title">Services</h2>
<?php if($serviceCount > 0): ?>
<div class="listing-grid">
<?php while($row = $services->fetch_assoc()): ?>
<a href="servicedetails.php?id=<?= $row['id'] ?>" class="listing-card">
<img src="uploads/services/<?= htmlspecialchars($row['image'] ?: 'default_service.jpg') ?>" class="listing-image" alt="<?= htmlspecialchars($row['name']) ?>">
<div class="listing-body">
<div class="listing-name"><?= htmlspecialchars($row['name']) ?></div>
<div class="listing-subtitle"><?= (int) $row['duration'] ?> mins</div>
<div class="listing-price">&#8369;<?= number_format((float) $row['price'], 2) ?></div>
</div>
</a>
<?php endwhile; ?>
</div>
<?php else: ?>
<div class="empty-state">No services are available from this business yet.</div>
<?php endif; ?>
</div>

<div class="reviews-section">
<h2 class="section-title">Reviews</h2>
<?php if($reviews->num_rows > 0): ?>
<div class="review-list">
<?php while($review = $reviews->fetch_assoc()): ?>
<div class="review-card">
<div class="review-top">
<div>
<div class="review-user">
<?php
$fname = $review['fname'] ?? '';
$lname = $review['lname'] ?? '';

if((int) $review['is_anonymous'] === 1){
    echo htmlspecialchars(trim(maskName($fname) . ' ' . maskName($lname)));
} else {
    echo htmlspecialchars(trim($fname . ' ' . $lname));
}
?>
</div>
<div class="review-date"><?= date("F d, Y", strtotime($review['created_at'])) ?></div>
</div>

<?php if(!empty($review['experience_rating'])): ?>
<div class="rating-stars">
<?php for($i=1;$i<=5;$i++): ?>
<i class="fa <?= $i <= (int) $review['experience_rating'] ? 'fa-star' : 'fa-regular fa-star' ?>"></i>
<?php endfor; ?>
</div>
<?php endif; ?>
</div>

<div class="review-comment"><?= htmlspecialchars($review['comment']) ?></div>

<?php
$reviewImages = array_values(array_filter(array_map('trim', explode(',', (string) ($review['images'] ?? '')))));
if(count($reviewImages) > 0):
?>
<div class="review-images">
<?php foreach($reviewImages as $img): ?>
<img src="uploads/reviews/<?= htmlspecialchars($img) ?>" alt="Review image" onerror="this.style.display='none'">
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php endwhile; ?>
</div>
<?php else: ?>
<div class="empty-state">No reviews yet. Be the first to share feedback for this business.</div>
<?php endif; ?>
</div>
</div>

<?php include 'bottom_nav.php'; ?>
</body>
</html>
