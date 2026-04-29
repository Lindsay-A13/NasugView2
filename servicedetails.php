<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/cart_count.php";

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if($id <= 0){
    die("Service not found.");
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
        b.business_photo,
        ROUND(AVG(r.experience_rating),1) AS avg_rating,
        COUNT(r.id) AS total_reviews
    FROM services s
    INNER JOIN business_owner b
        ON s.owner_id = b.b_id
    LEFT JOIN reviews r
        ON r.business_id = b.b_id
    WHERE s.id = ?
    GROUP BY
        s.id,
        s.name,
        s.description,
        s.price,
        s.duration,
        s.image,
        b.b_id,
        b.business_name,
        b.description,
        b.phone,
        b.address,
        b.business_photo
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

$materialsStmt = $conn->prepare("
    SELECT
        i.id,
        i.name,
        i.type,
        sm.quantity
    FROM service_materials sm
    INNER JOIN inventory i
        ON sm.inventory_id = i.id
    WHERE sm.service_id = ?
    ORDER BY i.name ASC
");

if(!$materialsStmt){
    die("Service Materials Query Error: " . $conn->error);
}

$materialsStmt->bind_param("i", $id);
$materialsStmt->execute();
$materials = $materialsStmt->get_result();

$roundedAvgRating = (int) round((float) ($service['avg_rating'] ?? 0));
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
.header{display:flex;justify-content:space-between;align-items:center;padding:12px 20px;border-bottom:1px solid #e5e7eb;position:sticky;top:0;background:#fff;z-index:1000;}
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
.materials-list{display:flex;flex-direction:column;gap:10px;}
.material-item{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;font-size:14px;}
.material-name{font-weight:600;color:#0f172a;}
.material-meta{color:#64748b;}
.empty-state{padding:14px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc;color:#64748b;font-size:14px;}
@media (max-width:768px){
  .container{padding:16px;padding-bottom:120px;}
  .service-card{grid-template-columns:1fr;gap:20px;padding:18px;}
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
        <div class="rating-stars" aria-label="<?= $service['avg_rating'] ? $service['avg_rating'] : '0.0' ?> out of 5 stars">
          <?php for($i=1;$i<=5;$i++): ?>
          <i class="fa <?= $i <= $roundedAvgRating ? 'fa-star' : 'fa-regular fa-star' ?>"></i>
          <?php endfor; ?>
        </div>
        <div class="rating-text">
          <?= $service['avg_rating'] ? $service['avg_rating'] : '0.0' ?> (<?= (int) $service['total_reviews'] ?> review<?= (int) $service['total_reviews'] === 1 ? '' : 's' ?>)
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
      <h2>Materials Used</h2>
      <?php if($materials->num_rows > 0): ?>
      <div class="materials-list">
        <?php while($material = $materials->fetch_assoc()): ?>
        <div class="material-item">
          <div>
            <div class="material-name"><?= htmlspecialchars($material['name']) ?></div>
            <div class="material-meta"><?= htmlspecialchars($material['type'] ?? 'Item') ?></div>
          </div>
          <div class="material-meta">Qty: <?= (int) $material['quantity'] ?></div>
        </div>
        <?php endwhile; ?>
      </div>
      <?php else: ?>
      <div class="empty-state">No linked materials were added for this service.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include 'bottom_nav.php'; ?>
</body>
</html>
