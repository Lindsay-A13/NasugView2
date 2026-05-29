<?php
session_start();

require_once "config/db.php";
require_once "config/cart_count.php";

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])){
    $user_id = (int) $_SESSION['user_id'];
    $business_id = (int) ($_POST['business_id'] ?? 0);

    if(isset($_POST['update_review'])){
        $review_id = (int) ($_POST['review_id'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');
        $anonymous = isset($_POST['anonymous']) ? 1 : 0;
        $uses_rating = (int) ($_POST['uses_rating'] ?? 0) === 1;
        $rating = $uses_rating ? (int) ($_POST['experience_rating'] ?? 0) : null;

        if($review_id > 0 && $business_id > 0 && $comment !== '' && (!$uses_rating || ($rating >= 1 && $rating <= 5))){
            $current_images = [];
            $image_stmt = $conn->prepare("
                SELECT images
                FROM reviews
                WHERE id = ? AND user_id = ? AND business_id = ?
            ");
            $image_stmt->bind_param("iii", $review_id, $user_id, $business_id);
            $image_stmt->execute();
            $image_row = $image_stmt->get_result()->fetch_assoc();
            $image_stmt->close();

            if(!$image_row){
                header("Location: businessdetails.php?id=" . $business_id . "&review=invalid");
                exit();
            }

            $current_images = array_values(array_filter(array_map('trim', explode(',', (string) ($image_row['images'] ?? '')))));

            $posted_keep_images = $_POST['keep_images'] ?? [];
            if(!is_array($posted_keep_images)){
                $posted_keep_images = [];
            }

            $keep_images = [];
            foreach($posted_keep_images as $keep_image){
                $keep_image = basename(trim((string) $keep_image));
                if($keep_image !== '' && in_array($keep_image, $current_images, true)){
                    $keep_images[] = $keep_image;
                }
            }
            $keep_images = array_values(array_unique($keep_images));

            $upload_dir = __DIR__ . "/uploads/reviews/";
            if(!is_dir($upload_dir)){
                mkdir($upload_dir, 0777, true);
            }

            $new_images = [];
            $remaining_slots = max(0, 4 - count($keep_images));

            if($remaining_slots > 0 && isset($_FILES['images']) && !empty($_FILES['images']['name'][0])){
                $total_files = min(count($_FILES['images']['name']), $remaining_slots);

                for($i = 0; $i < $total_files; $i++){
                    if($_FILES['images']['error'][$i] === UPLOAD_ERR_OK){
                        $ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
                        $allowed = ['jpg', 'jpeg', 'png', 'webp'];

                        if(in_array($ext, $allowed, true)){
                            $file_name = time() . "_" . uniqid() . "." . $ext;
                            $target = $upload_dir . $file_name;

                            if(move_uploaded_file($_FILES['images']['tmp_name'][$i], $target)){
                                $new_images[] = $file_name;
                            }
                        }
                    }
                }
            }

            $final_images = array_merge($keep_images, $new_images);
            $images_string = count($final_images) > 0 ? implode(',', $final_images) : null;

            $stmt = $conn->prepare("
                UPDATE reviews
                SET experience_rating = ?, comment = ?, images = ?, is_anonymous = ?
                WHERE id = ? AND user_id = ? AND business_id = ?
            ");
            $stmt->bind_param("issiiii", $rating, $comment, $images_string, $anonymous, $review_id, $user_id, $business_id);
            $stmt->execute();
            $stmt->close();

            foreach(array_diff($current_images, $final_images) as $removed_image){
                $removed_path = $upload_dir . basename($removed_image);
                if(is_file($removed_path)){
                    unlink($removed_path);
                }
            }

            header("Location: businessdetails.php?id=" . $business_id . "&review=updated");
            exit();
        }

        header("Location: businessdetails.php?id=" . $business_id . "&review=invalid");
        exit();
    }

    if(isset($_POST['delete_review'])){
        $review_id = (int) ($_POST['review_id'] ?? 0);

        if($review_id > 0 && $business_id > 0){
            $stmt = $conn->prepare("
                DELETE FROM reviews
                WHERE id = ? AND user_id = ? AND business_id = ?
            ");
            $stmt->bind_param("iii", $review_id, $user_id, $business_id);
            $stmt->execute();
            $stmt->close();
        }

        header("Location: businessdetails.php?id=" . $business_id . "&review=deleted");
        exit();
    }

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
$reviewStatus = $_GET['review'] ?? '';
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
.header{display:flex;justify-content:flex-end;align-items:center;padding:12px 20px;border-bottom:1px solid #e5e7eb;position:sticky;top:0;background:#fff;z-index:1000}
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
.review-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.review-action-btn{display:inline-flex;align-items:center;gap:7px;border:1px solid #cbd5e1;background:#fff;color:#001a47;border-radius:10px;padding:8px 12px;font:inherit;font-weight:600;cursor:pointer}
.review-action-btn.danger{border-color:#fecaca;color:#b91c1c}
.review-status{margin-bottom:14px;padding:12px 14px;border-radius:10px;font-size:14px}
.review-status.success{background:#ecfdf3;color:#166534;border:1px solid #bbf7d0}
.review-status.error{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:3000;align-items:center;justify-content:center;padding:18px}
.modal-overlay.active{display:flex}
.modal-content{width:min(100%,480px);background:#fff;border-radius:16px;padding:20px;box-shadow:0 18px 45px rgba(0,0,0,.22)}
.modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
.modal-title{margin:0;color:#001a47;font-size:18px}
.modal-close{width:36px;height:36px;border:none;border-radius:50%;background:#f1f5f9;color:#334155;cursor:pointer}
.edit-stars{display:flex;gap:7px;margin-bottom:12px;color:#cbd5e1;font-size:24px}
.edit-stars .active{color:#001a47}
.edit-review-text{width:100%;min-height:130px;border:1px solid #cbd5e1;border-radius:12px;padding:12px;font:inherit;resize:vertical}
.edit-image-list{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:12px}
.edit-image-item{position:relative;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#f8fafc}
.edit-image-item img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block}
.edit-image-item label{position:absolute;inset:auto 6px 6px 6px;display:flex;align-items:center;justify-content:center;gap:5px;background:rgba(255,255,255,.92);border-radius:8px;padding:5px;font-size:12px;color:#b91c1c;font-weight:600}
.edit-file-label{display:inline-flex;align-items:center;gap:8px;margin-top:12px;padding:9px 14px;border:1px solid #cbd5e1;border-radius:10px;color:#001a47;background:#fff;font-weight:600;cursor:pointer}
.edit-image-note{margin-top:8px;font-size:13px;color:#64748b}
.modal-check{display:flex;align-items:center;gap:8px;margin:12px 0;color:#334155}
.modal-actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:16px}
.empty-state{padding:16px;border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc;color:#64748b}
@media (max-width:768px){
  .container{padding:16px;padding-bottom:120px}
  .hero-card{grid-template-columns:1fr;gap:20px;padding:18px}
  .hero-card,.info-grid,.section-block,.reviews-section{width:min(100%,720px);margin-left:auto;margin-right:auto}
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
<?php if($reviewStatus === 'updated' || $reviewStatus === 'deleted'): ?>
<div class="review-status success">
<?= $reviewStatus === 'updated' ? 'Your review has been updated.' : 'Your review has been deleted.' ?>
</div>
<?php elseif($reviewStatus === 'invalid'): ?>
<div class="review-status error">Unable to save your review. Please complete the required fields and try again.</div>
<?php endif; ?>
<?php if($reviews->num_rows > 0): ?>
<div class="review-list">
<?php while($review = $reviews->fetch_assoc()): ?>
<?php
$isOwnReview = isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $review['user_id'];
$reviewUsesRating = $review['experience_rating'] !== null && $review['experience_rating'] !== '';
?>
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

<?php if($isOwnReview): ?>
<div class="review-actions">
<button
    type="button"
    class="review-action-btn edit-review-btn"
    data-review-id="<?= (int) $review['id'] ?>"
    data-business-id="<?= (int) $business['b_id'] ?>"
    data-rating="<?= $reviewUsesRating ? (int) $review['experience_rating'] : '' ?>"
    data-uses-rating="<?= $reviewUsesRating ? '1' : '0' ?>"
    data-comment="<?= htmlspecialchars($review['comment'] ?? '', ENT_QUOTES) ?>"
    data-images="<?= htmlspecialchars($review['images'] ?? '', ENT_QUOTES) ?>"
    data-anonymous="<?= (int) $review['is_anonymous'] ?>"
>
    <i class="fa fa-pen"></i> Edit
</button>
<form method="POST" style="margin:0;" onsubmit="return confirm('Delete your review?');">
    <input type="hidden" name="business_id" value="<?= (int) $business['b_id'] ?>">
    <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
    <button type="submit" name="delete_review" class="review-action-btn danger">
        <i class="fa fa-trash"></i> Delete
    </button>
</form>
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

<div class="modal-overlay" id="editReviewModal">
<div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="editReviewTitle">
<div class="modal-head">
<h3 class="modal-title" id="editReviewTitle">Edit your review</h3>
<button type="button" class="modal-close" id="closeEditReview" aria-label="Close"><i class="fa fa-times"></i></button>
</div>
<form method="POST" id="editReviewForm" enctype="multipart/form-data">
<input type="hidden" name="business_id" id="editBusinessId">
<input type="hidden" name="review_id" id="editReviewId">
<input type="hidden" name="uses_rating" id="editUsesRating">
<input type="hidden" name="experience_rating" id="editRatingInput">
<div class="edit-stars" id="editStars" aria-label="Review rating">
<?php for($i=1;$i<=5;$i++): ?>
<i class="fa fa-star" data-rating="<?= $i ?>"></i>
<?php endfor; ?>
</div>
<textarea name="comment" id="editComment" class="edit-review-text" required></textarea>
<div class="edit-image-list" id="editImageList"></div>
<label class="edit-file-label">
<i class="fa fa-camera"></i> Add Photos
<input type="file" name="images[]" id="editImagesInput" accept="image/*" multiple hidden>
</label>
<div class="edit-image-note">You can keep, remove, or add photos. Maximum 4 images per review.</div>
<label class="modal-check">
<input type="checkbox" name="anonymous" id="editAnonymous">
Post as Anonymous
</label>
<div class="modal-actions">
<button type="button" class="review-action-btn" id="cancelEditReview">Cancel</button>
<button type="submit" name="update_review" class="action-btn primary">Save Changes</button>
</div>
</form>
</div>
</div>

<?php include 'bottom_nav.php'; ?>
<script>
const editReviewModal = document.getElementById("editReviewModal");
const editReviewId = document.getElementById("editReviewId");
const editBusinessId = document.getElementById("editBusinessId");
const editUsesRating = document.getElementById("editUsesRating");
const editRatingInput = document.getElementById("editRatingInput");
const editComment = document.getElementById("editComment");
const editAnonymous = document.getElementById("editAnonymous");
const editStars = document.getElementById("editStars");
const editImageList = document.getElementById("editImageList");
const editImagesInput = document.getElementById("editImagesInput");

function setEditRating(value){
    editRatingInput.value = value || "";
    editStars.querySelectorAll("i").forEach((star) => {
        star.classList.toggle("active", Number(star.dataset.rating) <= Number(value));
    });
}

function closeEditReviewModal(){
    editReviewModal.classList.remove("active");
}

function renderEditImages(images){
    editImageList.innerHTML = "";

    images.forEach((image) => {
        const item = document.createElement("div");
        item.className = "edit-image-item";

        const img = document.createElement("img");
        img.src = "uploads/reviews/" + image;
        img.alt = "Review image";

        const label = document.createElement("label");
        const checkbox = document.createElement("input");
        checkbox.type = "checkbox";
        checkbox.name = "keep_images[]";
        checkbox.value = image;
        checkbox.checked = true;

        label.appendChild(checkbox);
        label.appendChild(document.createTextNode("Keep"));
        item.appendChild(img);
        item.appendChild(label);
        editImageList.appendChild(item);
    });
}

function keptImageCount(){
    return editImageList.querySelectorAll('input[name="keep_images[]"]:checked').length;
}

document.querySelectorAll(".edit-review-btn").forEach((button) => {
    button.addEventListener("click", () => {
        const usesRating = button.dataset.usesRating === "1";
        const images = (button.dataset.images || "")
            .split(",")
            .map((image) => image.trim())
            .filter(Boolean);

        editReviewId.value = button.dataset.reviewId;
        editBusinessId.value = button.dataset.businessId;
        editUsesRating.value = usesRating ? "1" : "0";
        editComment.value = button.dataset.comment || "";
        editAnonymous.checked = button.dataset.anonymous === "1";
        editImagesInput.value = "";
        renderEditImages(images);
        editStars.style.display = usesRating ? "flex" : "none";
        setEditRating(usesRating ? button.dataset.rating : "");
        editReviewModal.classList.add("active");
        editComment.focus();
    });
});

editStars.querySelectorAll("i").forEach((star) => {
    star.addEventListener("click", () => setEditRating(star.dataset.rating));
});

editImagesInput.addEventListener("change", function(){
    const remainingSlots = 4 - keptImageCount();

    if(this.files.length > remainingSlots){
        alert("You can add up to " + remainingSlots + " more image" + (remainingSlots === 1 ? "." : "s."));
        this.value = "";
    }
});

document.getElementById("editReviewForm").addEventListener("submit", function(event){
    if(keptImageCount() + editImagesInput.files.length > 4){
        event.preventDefault();
        alert("Maximum 4 images per review.");
    }
});

document.getElementById("closeEditReview").addEventListener("click", closeEditReviewModal);
document.getElementById("cancelEditReview").addEventListener("click", closeEditReviewModal);
editReviewModal.addEventListener("click", (event) => {
    if(event.target === editReviewModal){
        closeEditReviewModal();
    }
});
</script>
</body>
</html>
