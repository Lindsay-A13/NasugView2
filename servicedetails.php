<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/cart_count.php";

$consumer_id = (int) $_SESSION['user_id'];
$account_type = $_SESSION['account_type'] ?? '';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if($id <= 0){
    die("Service not found.");
}

$createReviewsTableSql = "
CREATE TABLE IF NOT EXISTS service_reviews (
    id INT(11) NOT NULL AUTO_INCREMENT,
    service_id INT(11) NOT NULL,
    user_id INT(11) NOT NULL,
    rating INT(11) NOT NULL,
    comment TEXT NOT NULL,
    is_anonymous TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_service_review (service_id, user_id),
    KEY idx_service_reviews_service (service_id),
    KEY idx_service_reviews_user (user_id)
)";

if(!$conn->query($createReviewsTableSql)){
    die("Unable to prepare service reviews: " . $conn->error);
}

$serviceStmt = $conn->prepare("
    SELECT
        s.id,
        s.name,
        s.description,
        s.price,
        s.duration,
        s.image,
        b.b_id AS business_id,
        b.business_name,
        b.description AS business_description,
        b.phone,
        b.address,
        b.business_photo
    FROM services s
    INNER JOIN business_owner b
        ON s.owner_id = b.b_id
    WHERE s.id = ?
    LIMIT 1
");

if(!$serviceStmt){
    die("Service Query Error: " . $conn->error);
}

$serviceStmt->bind_param("i", $id);
$serviceStmt->execute();
$service = $serviceStmt->get_result()->fetch_assoc();
$serviceStmt->close();

if(!$service){
    die("Service not found.");
}

if(isset($_POST['submit_review'])){
    if($account_type !== 'consumer'){
        header("Location: servicedetails.php?id=" . $id . "&review=invalid_user");
        exit;
    }

    $rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
    $comment = trim($_POST['comment'] ?? '');
    $isAnonymous = isset($_POST['anonymous']) ? 1 : 0;

    if($rating < 1 || $rating > 5 || $comment === ''){
        header("Location: servicedetails.php?id=" . $id . "&review=invalid");
        exit;
    }

    $reviewStmt = $conn->prepare("
        INSERT INTO service_reviews (service_id, user_id, rating, comment, is_anonymous)
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

    header("Location: servicedetails.php?id=" . $id . "&review=" . ($reviewSaved ? "saved" : "failed"));
    exit;
}

$reviewSummaryStmt = $conn->prepare("
    SELECT COUNT(*) AS total_reviews, ROUND(AVG(rating), 1) AS avg_rating
    FROM service_reviews
    WHERE service_id = ?
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
    FROM service_reviews
    WHERE service_id = ? AND user_id = ?
    LIMIT 1
");
$userReviewStmt->bind_param("ii", $id, $consumer_id);
$userReviewStmt->execute();
$userReview = $userReviewStmt->get_result()->fetch_assoc();
$userReviewStmt->close();

$serviceReviewsStmt = $conn->prepare("
    SELECT
        sr.*,
        c.fname,
        c.lname,
        c.username,
        c.profile_picture
    FROM service_reviews sr
    LEFT JOIN consumers c ON sr.user_id = c.c_id
    WHERE sr.service_id = ?
    ORDER BY sr.updated_at DESC, sr.created_at DESC
");
$serviceReviewsStmt->bind_param("i", $id);
$serviceReviewsStmt->execute();
$serviceReviews = $serviceReviewsStmt->get_result();

$reviewStatus = $_GET['review'] ?? '';
$serviceImage = !empty($service['image'])
    ? "uploads/services/" . $service['image']
    : "uploads/services/default_service.jpg";
$businessImage = !empty($service['business_photo'])
    ? "uploads/business_cover/" . $service['business_photo']
    : "assets/images/default-cover.png";
$mapLink = "https://www.google.com/maps/search/?api=1&query=" . urlencode($service['address'] ?? '');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($service['name']) ?> | NasugView</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="assets/css/responsive.css"/>

<style>
body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:#fff;color:#0f172a;}
.header{display:flex;justify-content:flex-end;align-items:center;padding:12px 20px;border-bottom:1px solid #e5e7eb;position:sticky;top:0;background:#fff;z-index:1000;}
.logo img{height:38px;}
.cart-btn,.cart-btn:visited{position:relative;background:rgba(0,26,71,0.08);padding:8px;border-radius:50%;color:#001a47;font-size:18px;text-decoration:none;}
.cart-badge{position:absolute;top:-4px;right:-4px;background:#e74c3c;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:50%;}
.container{max-width:1100px;margin:auto;padding:20px;padding-bottom:130px;}
.service-card{display:grid;grid-template-columns:1.1fr .9fr;gap:28px;background:#fff;padding:24px;border-radius:16px;box-shadow:0 8px 22px rgba(0,0,0,.08);}
.service-image{width:100%;height:100%;min-height:320px;object-fit:cover;border-radius:14px;background:#f8fafc;}
.eyebrow{font-size:13px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#64748b;margin-bottom:10px;}
.service-title{margin:0 0 10px;font-size:32px;line-height:1.15;color:#001a47;}
.service-meta{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px;}
.meta-chip{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;font-size:13px;color:#334155;}
.service-price{font-size:30px;font-weight:700;color:#001a47;margin:10px 0 14px;}
.service-description{line-height:1.7;color:#334155;margin-bottom:18px;white-space:pre-wrap;}
.rating-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px;}
.rating-stars{display:flex;gap:4px;color:#001a47;}
.rating-text{font-size:14px;color:#64748b;}
.action-row{display:flex;gap:12px;flex-wrap:wrap;}
.action-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 16px;border-radius:12px;text-decoration:none;font-weight:600;}
.action-btn.primary{background:#001a47;color:#fff;}
.action-btn.secondary{border:1px solid #cbd5e1;color:#001a47;background:#fff;}
.info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:28px;}
.panel{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;}
.panel h2{margin:0 0 14px;font-size:18px;color:#001a47;}
.business-card{display:flex;gap:14px;align-items:flex-start;}
.business-photo{width:84px;height:84px;border-radius:12px;object-fit:cover;background:#f8fafc;}
.business-name{font-size:18px;font-weight:700;color:#001a47;margin-bottom:6px;}
.business-copy{font-size:14px;line-height:1.6;color:#475569;}
.details-list{display:flex;flex-direction:column;gap:10px;}
.details-item{display:flex;gap:10px;align-items:flex-start;font-size:14px;color:#334155;}
.details-item i{width:16px;color:#001a47;margin-top:2px;}
.empty-state{padding:14px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc;color:#64748b;font-size:14px;}
.reviews-section{margin-top:28px;background:#fff;padding:20px;border-radius:16px;box-shadow:0 6px 18px rgba(0,0,0,.06);}
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
  .container{padding:16px;padding-bottom:120px;}
  .service-card{grid-template-columns:1fr;gap:20px;padding:18px;}
  .service-card,.info-grid,.reviews-section{width:min(100%,720px);margin-left:auto;margin-right:auto;}
  .service-image{min-height:240px;}
  .service-title{font-size:26px;}
  .info-grid{grid-template-columns:1fr;}
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
  <div class="service-card">
    <div>
      <img src="<?= htmlspecialchars($serviceImage) ?>" class="service-image" alt="<?= htmlspecialchars($service['name']) ?>">
    </div>

    <div>
      <div class="eyebrow">Service</div>
      <h1 class="service-title"><?= htmlspecialchars($service['name']) ?></h1>

      <div class="service-meta">
        <div class="meta-chip"><i class="fa fa-store"></i><?= htmlspecialchars($service['business_name']) ?></div>
        <div class="meta-chip"><i class="fa fa-clock"></i><?= (int) $service['duration'] ?> mins</div>
      </div>

      <div class="service-price">&#8369;<?= number_format((float) $service['price'], 2) ?></div>

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

      <div class="service-description"><?= htmlspecialchars($service['description'] ?? 'No description available for this service yet.') ?></div>

      <div class="action-row">
        <a href="businessdetails.php?id=<?= $service['business_id'] ?>" class="action-btn primary">
          <i class="fa fa-store"></i> Visit Business
        </a>
        <?php if(!empty($service['address'])): ?>
        <a href="<?= htmlspecialchars($mapLink) ?>" target="_blank" class="action-btn secondary">
          <i class="fa fa-location-dot"></i> Open Map
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="info-grid">
    <div class="panel">
      <h2>Business Info</h2>
      <div class="business-card">
        <img src="<?= htmlspecialchars($businessImage) ?>" class="business-photo" alt="<?= htmlspecialchars($service['business_name']) ?>">
        <div>
          <div class="business-name"><?= htmlspecialchars($service['business_name']) ?></div>
          <div class="business-copy"><?= htmlspecialchars($service['business_description'] ?? 'No business description available.') ?></div>
        </div>
      </div>

      <div class="details-list" style="margin-top:16px;">
        <?php if(!empty($service['phone'])): ?>
        <div class="details-item"><i class="fa fa-phone"></i><span><?= htmlspecialchars($service['phone']) ?></span></div>
        <?php endif; ?>
        <?php if(!empty($service['address'])): ?>
        <div class="details-item"><i class="fa fa-location-dot"></i><span><?= htmlspecialchars($service['address']) ?></span></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <h2>Service Details</h2>
      <div class="details-list">
        <div class="details-item"><i class="fa fa-clock"></i><span><?= (int) $service['duration'] ?> minute<?= (int) $service['duration'] === 1 ? '' : 's' ?></span></div>
        <div class="details-item"><i class="fa fa-money-bill-wave"></i><span>&#8369;<?= number_format((float) $service['price'], 2) ?></span></div>
        <div class="details-item"><i class="fa fa-star"></i><span><?= $totalReviews > 0 ? number_format($avgRating, 1) : '0.0' ?> average rating</span></div>
      </div>
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

      <?php if($account_type === 'consumer'): ?>
      <button type="button" class="btn-rate" onclick="openModal()">
        <?= $userReview ? 'Edit Your Review' : 'Rate & Review' ?>
      </button>
      <?php endif; ?>
    </div>

    <?php if($reviewStatus === 'saved'): ?>
    <div class="review-status success">Your review has been saved.</div>
    <?php elseif($reviewStatus === 'invalid' || $reviewStatus === 'invalid_user' || $reviewStatus === 'failed'): ?>
    <div class="review-status error">
      <?= $reviewStatus === 'invalid_user' ? 'Only consumer accounts can post service reviews.' : 'Unable to save your review. Please complete the rating and comment fields and try again.' ?>
    </div>
    <?php endif; ?>

    <div class="review-list">
      <?php if($serviceReviews->num_rows > 0): ?>
      <?php while($review = $serviceReviews->fetch_assoc()): ?>
      <?php
      $displayName = 'Anonymous';
      if((int) $review['is_anonymous'] !== 1){
          $fullName = trim(($review['fname'] ?? '') . ' ' . ($review['lname'] ?? ''));
          $displayName = $fullName !== '' ? $fullName : ($review['username'] ?? 'Customer');
      }

      $avatar = 'assets/images/avatar.jpg';
      if((int) $review['is_anonymous'] !== 1 && !empty($review['profile_picture'])){
          $avatar = 'uploads/profile/' . $review['profile_picture'];
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
      <div class="empty-reviews">No reviews yet. Share the first review for this service.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modal" id="modal">
  <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="reviewModalTitle">
    <i class="fa fa-times modal-close" onclick="closeModal()"></i>
    <h4 id="reviewModalTitle" style="margin-top:0;"><?= $userReview ? 'Edit your review' : 'Rate this service' ?></h4>
    <div class="modal-subtitle">Tell other customers what you think about <?= htmlspecialchars($service['name']) ?>.</div>

    <form method="POST" action="servicedetails.php?id=<?= $id ?>">
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

<?php include 'bottom_nav.php'; ?>
<script>
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
</body>
</html>
