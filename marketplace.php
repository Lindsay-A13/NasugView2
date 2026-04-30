<?php
session_start();
require_once "config/db.php";
require_once "config/cart_count.php";

function ensureBusinessLocationColumns(mysqli $conn): void {
    $requiredColumns = [
        "latitude" => "ALTER TABLE business_owner ADD COLUMN latitude DECIMAL(10,7) NULL AFTER address",
        "longitude" => "ALTER TABLE business_owner ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude"
    ];

    foreach($requiredColumns as $column => $sql){
        $check = $conn->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'business_owner'
              AND COLUMN_NAME = ?
            LIMIT 1
        ");

        if(!$check){
            continue;
        }

        $check->bind_param("s", $column);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if(!$exists){
            $conn->query($sql);
        }
    }
}

ensureBusinessLocationColumns($conn);

$createProductReviewsTableSql = "
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

if(!$conn->query($createProductReviewsTableSql)){
    die("Unable to prepare product reviews: " . $conn->error);
}

/* LOAD CATEGORIES */
$cat_stmt = $conn->prepare("
    SELECT category_id, category_name
    FROM categories
    ORDER BY category_name ASC
");
if(!$cat_stmt){ die("Category Query Error: " . $conn->error); }
$cat_stmt->execute();
$categories = $cat_stmt->get_result();

/* LOAD PRODUCTS WITH INVENTORY CATEGORY */
$product_stmt = $conn->prepare("
    SELECT 
        i.id,
        i.name,
        i.description,
        i.type,
        i.price,
        i.image,
        i.owner_id,
        ic.id AS inventory_category_id,
        ic.name AS inventory_category_name,
        c.category_id,
        c.category_name,
        b.business_name,
        b.address,
        b.latitude,
        b.longitude,
        COALESCE(pr.avg_rating, 0) AS avg_rating,
        COALESCE(pr.total_reviews, 0) AS total_reviews
    FROM inventory i
    INNER JOIN business_owner b
        ON i.owner_id = b.b_id
    INNER JOIN categories c
        ON b.category_id = c.category_id
    INNER JOIN inventory_categories ic
        ON i.category_id = ic.id
    LEFT JOIN (
        SELECT product_id, ROUND(AVG(rating),1) AS avg_rating, COUNT(*) AS total_reviews
        FROM product_reviews
        GROUP BY product_id
    ) pr
        ON pr.product_id = i.id
    ORDER BY i.created_at DESC
");
if(!$product_stmt){ die("Product Query Error: " . $conn->error); }
$product_stmt->execute();
$products = $product_stmt->get_result();

/* LOAD SERVICES */
$service_stmt = $conn->prepare("
    SELECT 
        s.id,
        s.name,
        s.description,
        s.price,
        s.image,
        s.duration,
        b.business_name,
        b.address,
        b.latitude,
        b.longitude,
        b.b_id AS owner_id,
        c.category_id,
        c.category_name,
        COALESCE(br.avg_rating, 0) AS avg_rating,
        COALESCE(br.total_reviews, 0) AS total_reviews
    FROM services s
    INNER JOIN business_owner b
        ON s.owner_id = b.b_id
    INNER JOIN categories c
        ON b.category_id = c.category_id
    LEFT JOIN (
        SELECT business_id, ROUND(AVG(experience_rating),1) AS avg_rating, COUNT(*) AS total_reviews
        FROM reviews
        GROUP BY business_id
    ) br
        ON br.business_id = b.b_id
    ORDER BY s.created_at DESC
");

if(!$service_stmt){ die("Service Query Error: " . $conn->error); }

$service_stmt->execute();
$services = $service_stmt->get_result();


/* SORT OPTION */
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'name';
$orderBy = "b.business_name ASC";
if($sort === "rating"){
    $orderBy = "avg_rating DESC";
}

/* LOAD BUSINESSES */
$business_stmt = $conn->prepare("
    SELECT 
        b.b_id,
        b.business_name,
        b.business_photo,
        b.address,
        b.latitude,
        b.longitude,
        b.category_id,
        c.category_name,
        ROUND(AVG(r.experience_rating),1) AS avg_rating,
        COUNT(r.id) AS total_reviews
    FROM business_owner b
    LEFT JOIN reviews r
        ON r.business_id = b.b_id
    LEFT JOIN categories c
        ON b.category_id = c.category_id
    GROUP BY b.b_id
    ORDER BY $orderBy
");
if(!$business_stmt){
    die("Business Query Error: " . $conn->error);
}
$business_stmt->execute();
$businesses = $business_stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NasugView - Marketplace</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="assets/css/responsive.css"/>

<style>
*,*::before,*::after{box-sizing:border-box}
html,body{margin:0;padding:0;overflow-x:hidden;font-family:Arial,sans-serif;background:#fff;color:#000}
.container{max-width:1100px;margin:auto;padding-bottom:96px}

.topbar{display:flex;align-items:center;padding:14px 20px 8px;gap:10px}

.search-bar{flex:1;display:flex;align-items:center;background:#f0f0f0;border:1px solid #ddd;border-radius:24px;padding:10px 14px}
.search-bar input{border:none;outline:none;background:transparent;flex:1;color:#000}
.search-bar i{color:#001a47}

.cart-btn{position:relative;background:rgba(0,26,71,0.08);padding:10px;border-radius:50%;color:#001a47;font-size:18px;text-decoration:none;border:1px solid rgba(0,26,71,0.08)}
.cart-badge{position:absolute;top:-4px;right:-4px;background:#dc2626;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:50%}

.category-bar{padding:10px 20px;display:flex;gap:10px;overflow-x:auto}
.category-bar::-webkit-scrollbar{display:none}
.category-btn{flex:0 0 auto;padding:10px 18px;border-radius:999px;border:1px solid #ddd;background:#f5f5f5;cursor:pointer;font-size:14px;white-space:nowrap;transition:.2s;color:#000}
.category-btn.active{background:#001a47;color:#fff;border-color:#001a47}

.star-bar{padding:4px 20px 12px;display:flex;gap:10px;overflow-x:auto}
.star-bar::-webkit-scrollbar{display:none}
.star-btn{padding:8px 12px;border-radius:999px;border:1px solid #ddd;background:#f9f9f9;cursor:pointer;color:#000}
.star-btn.active{background:#001a47;color:#fff;border-color:#001a47}
.star-btn i{color:#001a47}
.star-btn.active i{color:#fff}

.sort-row{padding:0 20px 12px;display:flex;justify-content:flex-end;align-items:center;gap:8px}
.sort-row span{font-size:13px;color:#555}
.sort-row select{padding:9px 12px;border-radius:12px;border:1px solid #ccc;background:#fff;color:#000}

.section-block{margin-top:8px}
.section-title{font-size:18px;font-weight:800;color:#001a47;margin:18px 20px 12px}

.marketplace-grid,.business-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;padding:0 20px;width:100%}

.marketplace-card,.product,.business{background:#fff;border-radius:16px;overflow:hidden;border:1px solid #ccc;text-decoration:none;color:inherit;box-shadow:0 8px 20px rgba(0,0,0,.08);min-width:0}
.marketplace-card img,.product img,.business img{display:block;width:100%;height:190px;object-fit:cover;background:#f4f4f4}

.marketplace-body,.product-body,.business-body{padding:12px 12px 14px}
.marketplace-name,.product-name,.business-name{font-weight:700;font-size:15px;line-height:1.25;color:#001a47;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.marketplace-subtitle{font-size:12px;color:#666;margin-top:4px;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}
.meta-line{font-size:12px;color:#666;margin-top:4px;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}

.marketplace-meta{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:8px}
.marketplace-price{font-size:14px;font-weight:700;color:#001a47}
.marketplace-tag{font-size:11px;font-weight:700;color:#001a47;background:rgba(0,26,71,0.08);border-radius:999px;padding:5px 8px;white-space:nowrap}
.product-body > div:nth-child(3){font-size:14px;font-weight:700;color:#001a47;margin-top:8px}
.product-body > div[style*="font-size:12px;color:#888"]{display:inline-flex;margin-top:8px;padding:5px 8px;border-radius:999px;background:rgba(0,26,71,0.08);color:#001a47 !important;font-size:11px !important;font-weight:700}

.stars{display:flex;align-items:center;gap:2px;font-size:13px;margin-top:8px;color:#001a47}
.stars i{color:#d6d6d6}
.stars i.fa-star{color:#001a47}
.rating-line{display:flex;align-items:center;gap:6px;margin-top:8px;flex-wrap:wrap}
.rating-line .stars{margin-top:0}
.rating-value{font-size:13px;color:#777;font-weight:600}

.distance-line{font-size:12px;line-height:1.35;color:#0f766e;margin-top:6px;font-weight:600}

.hidden{display:none}

@media (max-width: 768px){
  .topbar{padding:14px 0 8px}
  .category-bar,.star-bar,.sort-row,.marketplace-grid,.business-grid{padding-left:0 !important;padding-right:0 !important}
  .section-title{margin-left:0 !important;margin-right:0 !important}
  .sort-row{justify-content:flex-end}
  .star-bar{justify-content:center}
  .marketplace-grid,.business-grid{grid-template-columns:repeat(2,minmax(0,1fr)) !important;gap:14px}
  .marketplace-card img,.product img,.business img{height:156px !important;aspect-ratio:auto !important}
  .marketplace-name,.product-name,.business-name{font-size:14px}
}

@media (max-width: 420px){
  .topbar{gap:8px}
  .marketplace-grid,.business-grid{gap:12px}
  .marketplace-body,.product-body,.business-body{padding:10px 10px 12px}
  .marketplace-card img,.product img,.business img{height:142px !important}
}
</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="container">

<div class="topbar">
  <div class="search-bar">
    <input type="text" id="searchInput" placeholder="Search...">
    <i class="fa fa-search"></i>
  </div>
  <a href="cart.php" class="cart-btn">
    <i class="fa fa-shopping-cart"></i>
    <?php if($cartCount>0): ?>
      <span class="cart-badge"><?= $cartCount ?></span>
    <?php endif; ?>
  </a>
</div>

<!-- CATEGORY BAR -->
<div class="category-bar">
  <button class="category-btn active" data-category="all">All</button>
  <button class="category-btn" data-category-toggle="nearby">Nearby</button>
  <?php while($cat=$categories->fetch_assoc()): ?>
    <?php if(strtolower(trim($cat['category_name'])) === 'nearby') continue; ?>
    <button class="category-btn" data-category="<?= $cat['category_id']; ?>">
      <?= htmlspecialchars($cat['category_name']); ?>
    </button>
  <?php endwhile; ?>
</div>

<!-- STAR FILTER -->
<div class="star-bar">
<?php for($i=1;$i<=5;$i++): ?>
  <button class="star-btn" data-rating="<?= $i ?>">
    <?php for($s=1;$s<=5;$s++): ?>
      <i class="fa <?= $s <= $i ? 'fa-star' : 'fa-regular fa-star' ?>"></i>
    <?php endfor; ?>
  </button>
<?php endfor; ?>
</div>

<div class="sort-row">
  <span>Sort:</span>
  <select id="priceSort">
    <option value="">Price</option>
    <option value="low">Low → High</option>
    <option value="high">High → Low</option>
  </select>
</div>

<!-- PRODUCTS -->
<div class="section-title">Products</div>
<div class="grid marketplace-grid">
<?php while($row=$products->fetch_assoc()): ?>
<a href="productdetails.php?id=<?= $row['id']; ?>"
   class="product marketplace-card"
   data-price="<?= $row['price']; ?>"
   data-name="<?= strtolower($row['name']); ?>"
   data-description="<?= strtolower($row['description']); ?>"
   data-type="<?= strtolower($row['type']); ?>"
   data-category="<?= $row['category_id']; ?>"
   data-categoryname="<?= strtolower($row['category_name']); ?>"
   data-productcategory="<?= strtolower($row['inventory_category_name']); ?>"
   data-business="<?= strtolower($row['business_name']); ?>"
   data-address="<?= htmlspecialchars($row['address'] ?? '', ENT_QUOTES); ?>"
   data-latitude="<?= htmlspecialchars($row['latitude'] ?? '', ENT_QUOTES); ?>"
   data-longitude="<?= htmlspecialchars($row['longitude'] ?? '', ENT_QUOTES); ?>"
   data-owner="<?= $row['owner_id']; ?>"
   data-rating="<?= round($row['avg_rating']); ?>">
    <img src="uploads/product/<?= $row['image'] ?: 'default_product.jpg'; ?>" alt="<?= htmlspecialchars($row['name']); ?>">
    <div class="product-body marketplace-body">
      <div class="product-name marketplace-name"><?= htmlspecialchars($row['name']); ?></div>
      <div class="meta-line"><?= htmlspecialchars($row['business_name']); ?></div>
      <div>₱<?= number_format($row['price'],2); ?></div>
      <div class="rating-line">
      <div class="stars">
        <?php $rating = round($row['avg_rating']); for($i=1;$i<=5;$i++): ?>
          <i class="fa <?= $i <= $rating ? 'fa-star' : 'fa-regular fa-star' ?>"></i>
        <?php endfor; ?>
      </div>
      <div class="rating-value">
        <?= $row['avg_rating'] ? $row['avg_rating'] : '0.0' ?>
        (<?= (int) $row['total_reviews']; ?>)
      </div>
      </div>
      <div class="distance-line" data-distance-label>Distance unavailable</div>
    </div>
</a>
<?php endwhile; ?>
</div>

<div id="noProductsMsg" 
     style="display:none;padding:20px;text-align:center;color:#888;font-size:14px;">
  No products found in this category.
</div>

<!-- SERVICES -->
<div class="section-title">Services</div>

<div class="grid marketplace-grid">
<?php if($services->num_rows > 0): ?>
<?php while($row=$services->fetch_assoc()): ?>
<a href="servicedetails.php?id=<?= $row['id']; ?>"
   class="product marketplace-card"
   data-price="<?= $row['price']; ?>"
   data-name="<?= strtolower($row['name']); ?>"
   data-description="<?= strtolower($row['description']); ?>"
   data-type="service"
   data-category="<?= $row['category_id']; ?>"
   data-categoryname="<?= strtolower($row['category_name']); ?>"
   data-business="<?= strtolower($row['business_name']); ?>"
   data-address="<?= htmlspecialchars($row['address'] ?? '', ENT_QUOTES); ?>"
   data-latitude="<?= htmlspecialchars($row['latitude'] ?? '', ENT_QUOTES); ?>"
   data-longitude="<?= htmlspecialchars($row['longitude'] ?? '', ENT_QUOTES); ?>"
   data-owner="<?= $row['owner_id']; ?>"
   data-rating="<?= round($row['avg_rating']); ?>">

    <img src="uploads/services/<?= $row['image'] ?: 'default_service.jpg'; ?>" alt="<?= htmlspecialchars($row['name']); ?>">

    <div class="product-body marketplace-body">
        <div class="product-name marketplace-name"><?= htmlspecialchars($row['name']); ?></div>

        <!-- BUSINESS NAME -->
        <div class="meta-line">
            <?= htmlspecialchars($row['business_name']); ?>
        </div>

        <div>₱<?= number_format($row['price'],2); ?></div>

        <div class="rating-line">
        <div class="stars">
            <?php $rating = round($row['avg_rating']); for($i=1;$i<=5;$i++): ?>
              <i class="fa <?= $i <= $rating ? 'fa-star' : 'fa-regular fa-star' ?>"></i>
            <?php endfor; ?>
        </div>
        <div class="rating-value">
            <?= $row['avg_rating'] ? $row['avg_rating'] : '0.0' ?>
            (<?= (int) $row['total_reviews']; ?>)
        </div>
        </div>

        <!-- DURATION -->
        <div style="font-size:12px;color:#888;">
            <?= $row['duration']; ?> mins (duration)
        </div>
        <div class="distance-line" data-distance-label>Distance unavailable</div>
    </div>

</a>
<?php endwhile; ?>
<?php else: ?>
<p style="padding:10px;color:#888;">No services available.</p>
<?php endif; ?>
</div>

<div id="noServicesMsg" 
     style="display:none;padding:20px;text-align:center;color:#888;font-size:14px;">
  No services found in this category.
</div>

<!-- BUSINESSES -->
<div class="section-title">Businesses</div>

<div class="business-grid marketplace-grid">
<?php while($biz=$businesses->fetch_assoc()): ?>
<a href="businessdetails.php?id=<?= $biz['b_id']; ?>"
   class="business marketplace-card"
   data-id="<?= $biz['b_id']; ?>"
   data-name="<?= strtolower($biz['business_name']); ?>"
   data-category="<?= $biz['category_id']; ?>"
   data-categoryname="<?= strtolower($biz['category_name']); ?>"
   data-address="<?= htmlspecialchars($biz['address'] ?? '', ENT_QUOTES); ?>"
   data-latitude="<?= htmlspecialchars($biz['latitude'] ?? '', ENT_QUOTES); ?>"
   data-longitude="<?= htmlspecialchars($biz['longitude'] ?? '', ENT_QUOTES); ?>"
   data-rating="<?= round($biz['avg_rating']); ?>">
<?php 
$img = !empty($biz['business_photo']) 
       ? "uploads/business_cover/" . $biz['business_photo']
       : "assets/images/default-cover.png";
?>
<img src="<?= $img; ?>" alt="<?= htmlspecialchars($biz['business_name']); ?>">    <div class="business-body marketplace-body">
      <div class="business-name marketplace-name">
        <?= htmlspecialchars($biz['business_name']); ?>
      </div>
      <div class="rating-line">
      <div class="stars">
      <?php 
      $rating = round($biz['avg_rating']);
      for($i=1;$i<=5;$i++):
      ?>
        <i class="fa <?= $i <= $rating ? 'fa-star' : 'fa-regular fa-star' ?>"></i>
      <?php endfor; ?>
      </div>
      <div class="rating-value">
        <?= $biz['avg_rating'] ? $biz['avg_rating'] : '0.0' ?>
        (<?= $biz['total_reviews']; ?>)
      </div>
      </div>
      <div class="distance-line" data-distance-label>Distance unavailable</div>
    </div>
</a>
<?php endwhile; ?>
</div>

<!-- EMPTY MESSAGE (PLACE HERE) -->
<div id="noBusinessesMsg"
     style="display:none;padding:20px;text-align:center;color:#888;font-size:14px;">
  No businesses found in this category.
</div>

</div>

<?php include 'bottom_nav.php'; ?>

<script>
const searchInput = document.getElementById('searchInput');
const categoryBtns = document.querySelectorAll('.category-btn');
const starBtns = document.querySelectorAll('.star-btn');
const priceSort = document.getElementById('priceSort');

if(priceSort && priceSort.options.length >= 3){
  priceSort.options[1].text = "Low to High";
  priceSort.options[2].text = "High to Low";
}

let activeCategory = "all";
let activeRating = null;
let nearbyOnly = false;
let userCoords = null;
const geocodeCacheKey = "marketplaceGeocodeCache";
let geocodeCache = {};
const originalCardOrder = new Map();

try{
  geocodeCache = JSON.parse(localStorage.getItem(geocodeCacheKey) || "{}");
}catch(e){
  geocodeCache = {};
}

function normalizeAddress(address){
  const clean = (address || "").trim();
  if(!clean) return "";

  const lower = clean.toLowerCase();

  if(lower.includes("philippines")){
    return clean;
  }

  if(lower.includes("batangas")){
    return clean + ", Philippines";
  }

  return clean + ", Batangas, Philippines";
}

function setDistanceLabel(card, text){
  const label = card.querySelector("[data-distance-label]");
  if(label){
    label.textContent = text;
  }
}

function updateCategoryButtonState(){
  categoryBtns.forEach(btn => {
    const category = btn.dataset.category || "";
    const toggle = btn.dataset.categoryToggle || "";

    if(toggle === "nearby"){
      btn.classList.toggle("active", nearbyOnly);
      return;
    }

    if(category === "all"){
      btn.classList.toggle("active", activeCategory === "all");
      return;
    }

    btn.classList.toggle("active", activeCategory === category);
  });
}

function rememberOriginalOrder(){
  document.querySelectorAll('.grid, .business-grid').forEach(grid => {
    const items = Array.from(grid.children).filter(item =>
      item.classList.contains('product') || item.classList.contains('business')
    );

    items.forEach((item, index) => {
      if(!originalCardOrder.has(item)){
        originalCardOrder.set(item, index);
      }
    });
  });
}

function getCardCoords(card){
  const lat = parseFloat(card.dataset.latitude || "");
  const lon = parseFloat(card.dataset.longitude || "");

  if(Number.isFinite(lat) && Number.isFinite(lon)){
    return {lat, lon};
  }

  return null;
}

function toRadians(value){
  return value * (Math.PI / 180);
}

function calculateDistanceKm(lat1, lon1, lat2, lon2){
  const earthRadiusKm = 6371;
  const dLat = toRadians(lat2 - lat1);
  const dLon = toRadians(lon2 - lon1);
  const a =
    Math.sin(dLat / 2) * Math.sin(dLat / 2) +
    Math.cos(toRadians(lat1)) * Math.cos(toRadians(lat2)) *
    Math.sin(dLon / 2) * Math.sin(dLon / 2);
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  return earthRadiusKm * c;
}

async function geocodeAddress(address){
  const normalized = normalizeAddress(address);
  if(!normalized){
    return null;
  }

  if(geocodeCache[normalized]){
    return geocodeCache[normalized];
  }

  try{
    const response = await fetch(
      "https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=" + encodeURIComponent(normalized)
    );
    const data = await response.json();

    if(Array.isArray(data) && data.length > 0){
      const coords = {
        lat: parseFloat(data[0].lat),
        lon: parseFloat(data[0].lon)
      };

      geocodeCache[normalized] = coords;
      localStorage.setItem(geocodeCacheKey, JSON.stringify(geocodeCache));
      return coords;
    }
  }catch(error){
    console.error("Geocode failed:", error);
  }

  return null;
}

function sortGridByDistance(gridSelector, itemSelector){
  document.querySelectorAll(gridSelector).forEach(grid => {
    const items = Array.from(grid.querySelectorAll(itemSelector));

    items.sort((a,b) => {
      const distanceA = parseFloat(a.dataset.distance || "999999");
      const distanceB = parseFloat(b.dataset.distance || "999999");
      return distanceA - distanceB;
    });

    items.forEach(item => grid.appendChild(item));
  });
}

function sortNearbyCards(){
  sortGridByDistance('.grid', '.product');
  sortGridByDistance('.business-grid', '.business');
}

function restoreDefaultOrder(){
  document.querySelectorAll('.grid, .business-grid').forEach(grid => {
    const items = Array.from(grid.children).filter(item =>
      item.classList.contains('product') || item.classList.contains('business')
    );

    items.sort((a, b) => {
      const orderA = originalCardOrder.has(a) ? originalCardOrder.get(a) : 999999;
      const orderB = originalCardOrder.has(b) ? originalCardOrder.get(b) : 999999;
      return orderA - orderB;
    });

    items.forEach(item => grid.appendChild(item));
  });
}

function requestDistanceLabels(text){
  document.querySelectorAll('.product, .business').forEach(card => {
    setDistanceLabel(card, text);
  });
}

function updateDistanceState(card, distanceKm){
  if(distanceKm === null){
    delete card.dataset.distance;
    setDistanceLabel(card, "Distance unavailable");
    return;
  }

  card.dataset.distance = distanceKm.toFixed(2);
  setDistanceLabel(card, distanceKm.toFixed(1) + " km away");
}

function loadNearbyDistances(){
  if(!navigator.geolocation){
    requestDistanceLabels("Location not supported");
    return;
  }

  requestDistanceLabels("Getting distance...");

  navigator.geolocation.getCurrentPosition(async position => {
    userCoords = {
      lat: position.coords.latitude,
      lon: position.coords.longitude
    };

    const cards = document.querySelectorAll('.product, .business');

    for(const card of cards){
      let coords = getCardCoords(card);

      if(!coords){
        coords = await geocodeAddress(card.dataset.address || "");
      }

      if(!coords){
        updateDistanceState(card, null);
        continue;
      }

      const distanceKm = calculateDistanceKm(
        userCoords.lat,
        userCoords.lon,
        coords.lat,
        coords.lon
      );

      updateDistanceState(card, distanceKm);
    }

    if(activeCategory === "nearby"){
      sortNearbyCards();
    }

    applyFilters();
  }, () => {
    requestDistanceLabels("Enable location to show distance");
  }, {
    enableHighAccuracy: true,
    timeout: 10000,
    maximumAge: 300000
  });
}

function applyFilters(){

  const value = searchInput.value.toLowerCase();

  let visibleProducts = 0;
  let visibleServices = 0;
  let visibleBusinesses = 0;

  /* FILTER PRODUCTS */
  document.querySelectorAll('.product').forEach(item => {

    let show = true;

    const name = item.dataset.name || "";
    const desc = item.dataset.description || "";
    const type = item.dataset.type || "";
    const categoryName = item.dataset.categoryname || "";
    const productCategory = item.dataset.productcategory || "";
    const businessName = item.dataset.business || "";

    if(
      !name.includes(value) &&
      !desc.includes(value) &&
      !type.includes(value) &&
      !categoryName.includes(value) &&
      !productCategory.includes(value) &&
      !businessName.includes(value)
    ){
      show = false;
    }

    if(activeCategory !== "all" &&
       item.dataset.category !== activeCategory){
        show = false;
    }

    if(nearbyOnly && !item.dataset.distance){
      show = false;
    }

    if(activeRating &&
       parseInt(item.dataset.rating || "0", 10) !== parseInt(activeRating, 10)){
        show = false;
    }

    item.classList.toggle('hidden', !show);

    if(show){
      if(item.dataset.type === "service"){
        visibleServices++;
      } else {
        visibleProducts++;
      }
    }

  });

  /* FILTER BUSINESSES */
  document.querySelectorAll('.business').forEach(item => {

    let show = true;

    const name = item.dataset.name || "";
    const categoryName = item.dataset.categoryname || "";

    if(
      !name.includes(value) &&
      !categoryName.includes(value)
    ){
      show = false;
    }

    if(activeCategory !== "all" &&
       item.dataset.category !== activeCategory){
        show = false;
    }

    if(nearbyOnly && !item.dataset.distance){
      show = false;
    }

    if(activeRating &&
       parseInt(item.dataset.rating) !== parseInt(activeRating)){
        show = false;
    }

    item.classList.toggle('hidden', !show);

    if(show) visibleBusinesses++;

  });

  document.getElementById("noProductsMsg").style.display =
      visibleProducts === 0 ? "block" : "none";

  document.getElementById("noServicesMsg").style.display =
      visibleServices === 0 ? "block" : "none";

  document.getElementById("noBusinessesMsg").style.display =
      visibleBusinesses === 0 ? "block" : "none";

}

searchInput.addEventListener("keyup", applyFilters);

categoryBtns.forEach(btn => {
  btn.addEventListener("click", function(){
    const clickedCategory = this.dataset.category || "";
    const clickedToggle = this.dataset.categoryToggle || "";

    if(clickedToggle === "nearby"){
      nearbyOnly = !nearbyOnly;

      if(nearbyOnly){
        sortNearbyCards();
      } else if(activeCategory === "all"){
        restoreDefaultOrder();
      }

      updateCategoryButtonState();
      applyFilters();
      return;
    }

    activeCategory = clickedCategory === "all" ? "all" : clickedCategory;

    if(nearbyOnly){
      sortNearbyCards();
    } else {
      restoreDefaultOrder();
    }

    updateCategoryButtonState();
    applyFilters();

  });
});

starBtns.forEach(btn => {
  btn.addEventListener("click", function(){

    if(activeRating === this.dataset.rating){
      this.classList.remove("active");
      activeRating = null;
    } else {
      starBtns.forEach(b => b.classList.remove("active"));
      this.classList.add("active");
      activeRating = this.dataset.rating;
    }

    applyFilters();

  });
});

priceSort.addEventListener("change", () => {
  sortByPrice();
});

function sortByPrice(){

  const type = priceSort.value;
  if(!type) return;

  const grids = document.querySelectorAll('.grid');

  grids.forEach(grid => {

    const items = Array.from(grid.querySelectorAll('.product'));

    items.sort((a,b)=>{
      const priceA = parseFloat(a.dataset.price);
      const priceB = parseFloat(b.dataset.price);

      return type === "low" 
        ? priceA - priceB 
        : priceB - priceA;
    });

    items.forEach(item => grid.appendChild(item));

  });

}

rememberOriginalOrder();
updateCategoryButtonState();
loadNearbyDistances();
</script>

</body>
</html>
