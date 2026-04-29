<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/cart_count.php";

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$consumer_id = (int) $_SESSION['user_id'];
$account_type = $_SESSION['account_type'] ?? '';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if($id <= 0){
    die("Product not found.");
}

$createReviewsTableSql = "
CREATE TABLE IF NOT EXISTS product_reviews (
    id INT(11) NOT NULL AUTO_INCREMENT,
    product_id INT(11) NOT NULL,
    user_id INT(11) NOT NULL,
    rating INT(11) NOT NULL,
    comment TEXT NOT NULL,
    is_anonymous TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_product_review (product_id, user_id),
    KEY idx_product_reviews_product (product_id),
    KEY idx_product_reviews_user (user_id)
)";

if(!$conn->query($createReviewsTableSql)){
    die("Unable to prepare product reviews: " . $conn->error);
}

$stmt = $conn->prepare("
    SELECT inventory.*, business_owner.business_name
    FROM inventory
    JOIN business_owner ON inventory.owner_id = business_owner.b_id
    WHERE inventory.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$product){
    die("Product not found.");
}

if(isset($_POST['submit_review'])){
    if($account_type !== 'consumer'){
        header("Location: productdetails.php?id=".$id."&review=invalid_user");
        exit;
    }

    $rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
    $comment = trim($_POST['comment'] ?? '');
    $isAnonymous = isset($_POST['anonymous']) ? 1 : 0;

    if($rating < 1 || $rating > 5 || $comment === ''){
        header("Location: productdetails.php?id=".$id."&review=invalid");
        exit;
    }

    $reviewStmt = $conn->prepare("
        INSERT INTO product_reviews (product_id, user_id, rating, comment, is_anonymous)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            rating = VALUES(rating),
            comment = VALUES(comment),
            is_anonymous = VALUES(is_anonymous),
            updated_at = CURRENT_TIMESTAMP
    ");
    $reviewStmt->bind_param("iiisi", $id, $consumer_id, $rating, $comment, $isAnonymous);
    $reviewSaved = $reviewStmt->execute();
    $reviewStmt->close();

    header("Location: productdetails.php?id=".$id."&review=".($reviewSaved ? "saved" : "failed"));
    exit;
}

/* AJAX ADD TO CART */
if(isset($_POST['ajax_add'])){
    $qty = max(1, (int) ($_POST['quantity'] ?? 1));
    $user_id = (int) $_SESSION['user_id'];

    $check = $conn->prepare("
        SELECT id, quantity
        FROM cart
        WHERE consumer_id=?
        AND account_type=?
        AND product_id=?
    ");
    $check->bind_param("isi", $user_id, $account_type, $id);
    $check->execute();
    $result = $check->get_result();

    if($result->num_rows > 0){
        $row = $result->fetch_assoc();
        $newQty = $row['quantity'] + $qty;

        $update = $conn->prepare("
            UPDATE cart
            SET quantity=?
            WHERE id=?
        ");
        $update->bind_param("ii", $newQty, $row['id']);
        $update->execute();
        $update->close();

        echo "updated";
    } else {
        $insert = $conn->prepare("
            INSERT INTO cart
            (consumer_id, account_type, business_id, product_id, quantity, price)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insert->bind_param(
            "isiiid",
            $user_id,
            $account_type,
            $product['owner_id'],
            $id,
            $qty,
            $product['price']
        );
        $insert->execute();
        $insert->close();

        echo "inserted";
    }

    $check->close();
    exit;
}

$reviewSummaryStmt = $conn->prepare("
    SELECT COUNT(*) AS total_reviews, ROUND(AVG(rating), 1) AS avg_rating
    FROM product_reviews
    WHERE product_id = ?
");
$reviewSummaryStmt->bind_param("i", $id);
$reviewSummaryStmt->execute();
$reviewSummary = $reviewSummaryStmt->get_result()->fetch_assoc();
$reviewSummaryStmt->close();

$totalReviews = (int) ($reviewSummary['total_reviews'] ?? 0);
$avgRating = $reviewSummary['avg_rating'] !== null ? (float) $reviewSummary['avg_rating'] : 0.0;
$roundedAvgRating = (int) round($avgRating);

$userReviewStmt = $conn->prepare("
    SELECT rating, comment, is_anonymous
    FROM product_reviews
    WHERE product_id = ? AND user_id = ?
    LIMIT 1
");
$userReviewStmt->bind_param("ii", $id, $consumer_id);
$userReviewStmt->execute();
$userReview = $userReviewStmt->get_result()->fetch_assoc();
$userReviewStmt->close();

$productReviewsStmt = $conn->prepare("
    SELECT
        pr.*,
        c.fname,
        c.lname,
        c.username,
        c.profile_picture
    FROM product_reviews pr
    LEFT JOIN consumers c ON pr.user_id = c.c_id
    WHERE pr.product_id = ?
    ORDER BY pr.updated_at DESC, pr.created_at DESC
");
$productReviewsStmt->bind_param("i", $id);
$productReviewsStmt->execute();
$productReviews = $productReviewsStmt->get_result();

$reviewStatus = $_GET['review'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($product['name']) ?> | NasugView</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="assets/css/responsive.css"/>

<style>
body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:#fff;}
.header{display:flex;justify-content:space-between;align-items:center;padding:12px 20px;border-bottom:1px solid #e5e7eb;position:sticky;top:0;background:#fff;z-index:1000;}
.logo img{height:38px;}
.cart-btn,.cart-btn:visited{position:relative;background:rgba(0,26,71,0.08);padding:8px;border-radius:50%;color:#001a47;font-size:18px;text-decoration:none;}
.cart-badge{position:absolute;top:-4px;right:-4px;background:#e74c3c;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:50%;}
.cart-shake{animation:cartShake .4s ease;}
@keyframes cartShake{0%{transform:rotate(0)}25%{transform:rotate(-10deg)}50%{transform:rotate(10deg)}75%{transform:rotate(-5deg)}100%{transform:rotate(0)}}
.fly-image{position:fixed;z-index:9999;pointer-events:none;border-radius:12px;transition:all .6s cubic-bezier(.4,-0.3,.6,1.4);}
.container{max-width:1100px;margin:auto;padding:20px;padding-bottom:140px;}
.product-card{display:grid;grid-template-columns:1fr 1fr;gap:30px;background:#fff;padding:25px;border-radius:14px;box-shadow:0 8px 22px rgba(0,0,0,.08);}
.product-image{width:100%;border-radius:12px;object-fit:cover;}
.category{color:#555;margin:10px 0;}
.price{font-size:26px;color:#001a47;font-weight:bold;}
.description{margin:15px 0;line-height:1.5;}
.btn-cart{width:100%;padding:14px;border:none;border-radius:10px;background:#001a47;color:#fff;cursor:pointer;}
.reviews-section{margin-top:30px;background:#fff;padding:20px;border-radius:14px;box-shadow:0 6px 18px rgba(0,0,0,.06);}
.reviews-header{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:16px;}
.review-summary{display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.review-score{font-size:30px;font-weight:700;color:#001a47;line-height:1;}
.review-meta{display:flex;flex-direction:column;gap:4px;}
.review-stars{display:flex;gap:3px;color:#001a47;}
.review-count{font-size:14px;color:#64748b;}
.btn-rate{background:#001a47;color:#fff;border:none;padding:10px 18px;border-radius:10px;cursor:pointer;font-size:14px;font-weight:600;}
.review-status{margin-bottom:14px;padding:12px 14px;border-radius:10px;font-size:14px;}
.review-status.success{background:#ecfdf3;color:#166534;border:1px solid #bbf7d0;}
.review-status.error{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;}
.review-list{display:flex;flex-direction:column;gap:14px;}
.review-item{border:1px solid #e5e7eb;border-radius:14px;padding:16px;}
.review-top{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px;}
.review-user{display:flex;align-items:center;gap:12px;}
.review-avatar{width:42px;height:42px;border-radius:50%;object-fit:cover;background:#f1f5f9;}
.review-name{font-weight:600;color:#0f172a;}
.review-date{font-size:13px;color:#64748b;margin-top:2px;}
.review-rating{display:flex;gap:3px;color:#001a47;}
.review-comment{line-height:1.6;color:#334155;white-space:pre-wrap;}
.empty-reviews{padding:18px;border:1px dashed #cbd5e1;border-radius:14px;text-align:center;color:#64748b;background:#f8fafc;}
.modal{position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;justify-content:center;align-items:center;padding:16px;z-index:3000;overflow-y:auto;}
.modal.active{display:flex;}
.modal-content{width:100%;max-width:420px;background:#fff;border-radius:16px;padding:18px;box-shadow:0 8px 30px rgba(0,0,0,.15);position:relative;max-height:calc(100vh - 32px);overflow-y:auto;}
.modal-close{position:absolute;top:10px;right:12px;font-size:18px;cursor:pointer;color:#666;}
.modal-subtitle{font-size:14px;color:#64748b;margin:-4px 0 16px;}
.stars{display:flex;gap:6px;font-size:24px;margin-bottom:12px;}
.stars i{color:#d1d5db;cursor:pointer;transition:color .15s ease,transform .15s ease;}
.stars i.active{color:#001a47;}
.stars i:hover{transform:scale(1.06);}
.modal textarea{width:100%;height:110px;border-radius:10px;border:1px solid #e5e7eb;padding:12px;margin-bottom:12px;resize:none;font:inherit;box-sizing:border-box;}
.anonymous-toggle{display:flex;align-items:center;gap:8px;margin-bottom:14px;color:#334155;font-size:14px;}
.submit-review{width:100%;padding:12px;border:none;border-radius:12px;background:#001a47;color:#fff;font-weight:600;cursor:pointer;}
@media (max-width:768px){
  .product-card{grid-template-columns:1fr;gap:20px;padding:18px;}
  .container{padding:16px;padding-bottom:130px;}
}
</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>
<body>
<?php include 'mobile_back_button.php'; ?>

<div class="header">
<a href="cart.php" class="cart-btn" id="cartIcon">
    <i class="fa fa-shopping-cart"></i>
    <span class="cart-badge" id="cartBadge" style="<?= $cartCount > 0 ? '' : 'display:none;' ?>">
        <?= $cartCount ?>
    </span>
</a>
</div>

<div class="container">

<div class="product-card">

<img src="uploads/product/<?= htmlspecialchars($product['image'] ?: 'default_product.jpg') ?>"
class="product-image"
id="productImage"
alt="<?= htmlspecialchars($product['name']) ?>">

<div class="product-info">

<h1><?= htmlspecialchars($product['name']) ?></h1>

<div class="category">
Business: <?= htmlspecialchars($product['business_name']) ?>
</div>

<div class="price">
&#8369;<?= number_format((float) $product['price'], 2) ?>
</div>

<div class="description">
<?= htmlspecialchars($product['description'] ?? '') ?>
</div>

<div style="margin-bottom:10px;display:flex;align-items:center;gap:10px;">
<button type="button" onclick="decreaseQty()" style="width:32px;height:32px;border:none;border-radius:6px;background:#eee;">-</button>
<span id="qty" style="font-size:16px;font-weight:600;">1</span>
<button type="button" onclick="increaseQty()" style="width:32px;height:32px;border:none;border-radius:6px;background:#eee;">+</button>
</div>

<button type="button" class="btn-cart" onclick="addToCartAnimation()">
<i class="fa fa-cart-plus"></i> Add to Cart
</button>

</div>
</div>

<div class="reviews-section">
<div class="reviews-header">
<div class="review-summary">
<div class="review-score"><?= $totalReviews > 0 ? number_format($avgRating, 1) : '0.0' ?></div>
<div class="review-meta">
<div class="review-stars" aria-label="<?= $totalReviews > 0 ? number_format($avgRating, 1) : '0.0' ?> out of 5 stars">
<?php for($i = 1; $i <= 5; $i++): ?>
<i class="fa <?= $i <= $roundedAvgRating ? 'fa-star' : 'fa-regular fa-star' ?>"></i>
<?php endfor; ?>
</div>
<div class="review-count"><?= $totalReviews ?> review<?= $totalReviews === 1 ? '' : 's' ?></div>
</div>
</div>
<button type="button" class="btn-rate" onclick="openModal()">
<?= $userReview ? 'Edit Your Review' : 'Rate & Review' ?>
</button>
</div>

<?php if($reviewStatus === 'saved'): ?>
<div class="review-status success">Your review has been saved.</div>
<?php elseif($reviewStatus === 'invalid' || $reviewStatus === 'invalid_user' || $reviewStatus === 'failed'): ?>
<div class="review-status error">
<?= $reviewStatus === 'invalid_user' ? 'Only consumer accounts can post product reviews.' : 'Unable to save your review. Please complete the rating and comment fields and try again.' ?>
</div>
<?php endif; ?>

<div class="review-list">
<?php if($productReviews->num_rows > 0): ?>
<?php while($review = $productReviews->fetch_assoc()): ?>
<?php
$displayName = 'Anonymous';
if((int) $review['is_anonymous'] !== 1){
    $fullName = trim(($review['fname'] ?? '').' '.($review['lname'] ?? ''));
    $displayName = $fullName !== '' ? $fullName : ($review['username'] ?? 'Customer');
}

$avatar = 'assets/images/default-profile.png';
if((int) $review['is_anonymous'] !== 1 && !empty($review['profile_picture'])){
    $avatar = 'uploads/profile/'.$review['profile_picture'];
}
?>
<div class="review-item">
<div class="review-top">
<div class="review-user">
<img src="<?= htmlspecialchars($avatar) ?>" class="review-avatar" alt="<?= htmlspecialchars($displayName) ?>">
<div>
<div class="review-name"><?= htmlspecialchars($displayName) ?></div>
<div class="review-date"><?= date("F d, Y", strtotime($review['updated_at'] ?? $review['created_at'])) ?></div>
</div>
</div>
<div class="review-rating">
<?php for($i = 1; $i <= 5; $i++): ?>
<i class="fa <?= $i <= (int) $review['rating'] ? 'fa-star' : 'fa-regular fa-star' ?>"></i>
<?php endfor; ?>
</div>
</div>
<div class="review-comment"><?= nl2br(htmlspecialchars($review['comment'])) ?></div>
</div>
<?php endwhile; ?>
<?php else: ?>
<div class="empty-reviews">
No reviews yet. Share the first review for this product.
</div>
<?php endif; ?>
</div>
</div>

</div>

<div class="modal" id="modal">
<div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="reviewModalTitle">
<i class="fa fa-times modal-close" onclick="closeModal()"></i>
<h4 id="reviewModalTitle" style="margin-top:0;"><?= $userReview ? 'Edit your review' : 'Rate this product' ?></h4>
<div class="modal-subtitle">Tell other customers what you think about <?= htmlspecialchars($product['name']) ?>.</div>

<form method="POST" action="productdetails.php?id=<?= $id ?>">
<input type="hidden" name="submit_review" value="1">
<input type="hidden" name="rating" id="ratingInput" value="<?= (int) ($userReview['rating'] ?? 0) ?>">

<div class="stars" id="stars">
<?php for($i = 1; $i <= 5; $i++): ?>
<i class="fa fa-star <?= ((int) ($userReview['rating'] ?? 0) >= $i) ? 'active' : '' ?>" data-val="<?= $i ?>"></i>
<?php endfor; ?>
</div>

<textarea name="comment" id="reviewComment" placeholder="Write your review..." required><?= htmlspecialchars($userReview['comment'] ?? '') ?></textarea>

<label class="anonymous-toggle">
<input type="checkbox" name="anonymous" <?= !empty($userReview['is_anonymous']) ? 'checked' : '' ?>>
Post as Anonymous
</label>

<button type="submit" class="submit-review">Submit Review</button>
</form>
</div>
</div>

<script>
let quantity = 1;

function increaseQty(){
  quantity++;
  document.getElementById("qty").innerText = quantity;
}

function decreaseQty(){
  if(quantity > 1){
    quantity--;
    document.getElementById("qty").innerText = quantity;
  }
}

function addToCartAnimation(){
  const productImg = document.getElementById("productImage");
  const cartIcon = document.getElementById("cartIcon");
  const cartBadge = document.getElementById("cartBadge");

  const imgRect = productImg.getBoundingClientRect();
  const cartRect = cartIcon.getBoundingClientRect();
  const centerX = window.innerWidth / 2 - imgRect.width / 4;
  const centerY = window.innerHeight / 2 - imgRect.height / 4;

  const flyImg = productImg.cloneNode(true);
  flyImg.classList.add("fly-image");
  flyImg.style.top = imgRect.top + "px";
  flyImg.style.left = imgRect.left + "px";
  flyImg.style.width = imgRect.width + "px";
  flyImg.style.height = imgRect.height + "px";

  document.body.appendChild(flyImg);

  setTimeout(() => {
    flyImg.style.top = centerY + "px";
    flyImg.style.left = centerX + "px";
    flyImg.style.width = imgRect.width * 0.6 + "px";
    flyImg.style.height = imgRect.height * 0.6 + "px";
    flyImg.style.transform = "scale(1.2)";
  }, 50);

  setTimeout(() => {
    flyImg.style.top = cartRect.top + "px";
    flyImg.style.left = cartRect.left + "px";
    flyImg.style.width = "20px";
    flyImg.style.height = "20px";
    flyImg.style.opacity = "0.3";
    flyImg.style.transform = "scale(0.5)";
  }, 500);

  fetch("", {
    method: "POST",
    headers: {"Content-Type": "application/x-www-form-urlencoded"},
    body: "ajax_add=1&quantity=" + quantity
  })
  .then(res => res.text())
  .then(data => {
    setTimeout(() => {
      flyImg.remove();

      cartIcon.classList.add("cart-shake");
      setTimeout(() => {
        cartIcon.classList.remove("cart-shake");
      }, 400);

      if(data.trim() === "inserted"){
        let currentCount = parseInt(cartBadge.innerText, 10) || 0;
        let newCount = currentCount + 1;

        cartBadge.innerText = newCount;
        cartBadge.style.display = "inline-block";
        cartBadge.style.transform = "scale(1.8)";
        cartBadge.style.transition = "0.25s ease";

        setTimeout(() => {
          cartBadge.style.transform = "scale(1)";
        }, 250);
      }
    }, 1000);
  });
}

const modal = document.getElementById("modal");
const modalContent = modal.querySelector(".modal-content");
const ratingInput = document.getElementById("ratingInput");

function openModal(){
  modal.classList.add("active");
  document.body.style.overflow = "hidden";
}

function closeModal(){
  modal.classList.remove("active");
  document.body.style.overflow = "auto";
}

modal.addEventListener("click", function(e){
  if(e.target === modal){
    closeModal();
  }
});

modalContent.addEventListener("click", function(e){
  e.stopPropagation();
});

document.addEventListener("keydown", function(e){
  if(e.key === "Escape" && modal.classList.contains("active")){
    closeModal();
  }
});

document.querySelectorAll("#stars i").forEach(star => {
  star.addEventListener("click", () => {
    const val = Number(star.dataset.val);
    ratingInput.value = val;
    document.querySelectorAll("#stars i").forEach(s => {
      s.classList.toggle("active", Number(s.dataset.val) <= val);
    });
  });
});

<?php if($reviewStatus === 'invalid' || $reviewStatus === 'invalid_user' || $reviewStatus === 'failed'): ?>
openModal();
<?php endif; ?>
</script>

<?php include 'bottom_nav.php'; ?>
</body>
</html>
