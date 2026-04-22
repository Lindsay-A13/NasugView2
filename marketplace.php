<?php
session_start();
require_once "config/db.php";
require_once "config/cart_count.php";

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
        b.business_name
    FROM inventory i
    INNER JOIN business_owner b
        ON i.owner_id = b.b_id
    INNER JOIN categories c
        ON b.category_id = c.category_id
    INNER JOIN inventory_categories ic
        ON i.category_id = ic.id
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
        b.b_id AS owner_id,
        c.category_id,
        c.category_name
    FROM services s
    INNER JOIN business_owner b
        ON s.owner_id = b.b_id
    INNER JOIN categories c
        ON b.category_id = c.category_id
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
<title>NasugView – Marketplace</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
html,body{margin:0;padding:0;overflow-x:hidden;font-family:Arial;background:#fff}
.container{max-width:1100px;margin:auto;padding-bottom:80px}

.topbar{display:flex;align-items:center;padding:10px 20px;gap:10px}
.logo{width:100px;height:70px;object-fit:contain}

.search-bar{flex:1;display:flex;align-items:center;background:#f0f0f0;border-radius:20px;padding:8px 12px}
.search-bar input{border:none;outline:none;background:transparent;flex:1}

.cart-btn{position:relative;background:rgba(0,26,71,0.08);padding:8px;border-radius:50%;color:#001a47;font-size:18px;text-decoration:none}
.cart-badge{position:absolute;top:-4px;right:-4px;background:#e74c3c;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:50%}

.category-bar{padding:10px 20px;display:flex;gap:10px;overflow-x:auto}
.category-bar::-webkit-scrollbar{display:none}
.category-btn{flex:0 0 auto;padding:10px 18px;border-radius:25px;border:1px solid #ddd;background:#f5f5f5;cursor:pointer;font-size:14px;white-space:nowrap;transition:.2s}
.category-btn.active{background:#001a47;color:#fff;border-color:#001a47}

.star-bar{padding:5px 20px 15px;display:flex;gap:10px}
.star-btn{padding:6px 12px;border-radius:20px;border:1px solid #ddd;background:#f9f9f9;cursor:pointer}
.star-btn.active{background:#001a47;color:#fff;border-color:#001a47}
.star-btn.active i{color:#fff}

.section-title{font-size:18px;font-weight:bold;color:#001a47;margin:15px 20px}

.grid,.business-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;padding:0 20px}

.product,.business{background:#fff;border-radius:10px;overflow:hidden;border:1px solid #ccc;text-decoration:none;color:inherit}
.product img,.business img{width:100%;height:180px;object-fit:cover}
.product-body,.business-body{padding:10px}

.product-name,.business-name{font-weight:bold;font-size:14px;color:#001a47}

.stars{font-size:13px;margin-top:4px}
.stars i{color:#ccc}
.business .stars i.fa-star{color:#001a47}

.hidden{display:none}
</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="container">

<div class="topbar">
  <img src="assets/images/logo.png" class="logo">
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
  <?php while($cat=$categories->fetch_assoc()): ?>
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

<div style="padding:0 20px 10px; display:flex; justify-content:flex-end; align-items:center; gap:8px;">
  <span style="font-size:13px;color:#555;">Sort:</span>
  <select id="priceSort" style="padding:8px;border-radius:8px;border:1px solid #ccc;">
    <option value="">Price</option>
    <option value="low">Low → High</option>
    <option value="high">High → Low</option>
  </select>
</div>

<!-- PRODUCTS -->
<div class="section-title">Products</div>
<div class="grid">
<?php while($row=$products->fetch_assoc()): ?>
<a href="productdetails.php?id=<?= $row['id']; ?>"
   class="product"
   data-price="<?= $row['price']; ?>"
   data-name="<?= strtolower($row['name']); ?>"
   data-description="<?= strtolower($row['description']); ?>"
   data-type="<?= strtolower($row['type']); ?>"
   data-category="<?= $row['category_id']; ?>"
   data-categoryname="<?= strtolower($row['category_name']); ?>"
   data-productcategory="<?= strtolower($row['inventory_category_name']); ?>"
   data-business="<?= strtolower($row['business_name']); ?>"
   data-owner="<?= $row['owner_id']; ?>">
    <img src="uploads/product/<?= $row['image'] ?: 'default_product.jpg'; ?>">
    <div class="product-body">
      <div class="product-name"><?= htmlspecialchars($row['name']); ?></div>
      <div>₱<?= number_format($row['price'],2); ?></div>
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

<div class="grid">
<?php if($services->num_rows > 0): ?>
<?php while($row=$services->fetch_assoc()): ?>
<a href="#"
   class="product"
   data-price="<?= $row['price']; ?>"
   data-name="<?= strtolower($row['name']); ?>"
   data-description="<?= strtolower($row['description']); ?>"
   data-type="service"
   data-category="<?= $row['category_id']; ?>"
   data-categoryname="<?= strtolower($row['category_name']); ?>"
   data-business="<?= strtolower($row['business_name']); ?>"
   data-owner="<?= $row['owner_id']; ?>">

    <img src="uploads/services/<?= $row['image'] ?: 'default_service.jpg'; ?>">

    <div class="product-body">
        <div class="product-name"><?= htmlspecialchars($row['name']); ?></div>

        <!-- BUSINESS NAME -->
        <div style="font-size:12px;color:#666;">
            <?= htmlspecialchars($row['business_name']); ?>
        </div>

        <div>₱<?= number_format($row['price'],2); ?></div>

        <!-- DURATION -->
        <div style="font-size:12px;color:#888;">
            <?= $row['duration']; ?> mins (duration)
        </div>
    </div>

</a>
<?php endwhile; ?>
<?php else: ?>
<p style="padding:10px;color:#888;">No services available.</p>
<?php endif; ?>
</div>

<!-- BUSINESSES -->
<div class="section-title">Businesses</div>

<div class="business-grid">
<?php while($biz=$businesses->fetch_assoc()): ?>
<a href="businessdetails.php?id=<?= $biz['b_id']; ?>"
   class="business"
   data-id="<?= $biz['b_id']; ?>"
   data-name="<?= strtolower($biz['business_name']); ?>"
   data-category="<?= $biz['category_id']; ?>"
   data-categoryname="<?= strtolower($biz['category_name']); ?>"
   data-rating="<?= round($biz['avg_rating']); ?>">
<?php 
$img = !empty($biz['business_photo']) 
       ? "uploads/business_cover/" . $biz['business_photo'] 
       : "assets/images/logo.png";
?>
<img src="<?= $img; ?>">    <div class="business-body">
      <div class="business-name">
        <?= htmlspecialchars($biz['business_name']); ?>
      </div>
      <div class="stars">
      <?php 
      $rating = round($biz['avg_rating']);
      for($i=1;$i<=5;$i++):
      ?>
        <i class="fa <?= $i <= $rating ? 'fa-star' : 'fa-regular fa-star' ?>"></i>
      <?php endfor; ?>
      </div>
      <div style="font-size:12px;color:#777">
        <?= $biz['avg_rating'] ? $biz['avg_rating'] : '0.0' ?>
        (<?= $biz['total_reviews']; ?>)
      </div>
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

let activeCategory = "all";
let activeRating = null;

function applyFilters(){

  const value = searchInput.value.toLowerCase();

  let visibleProducts = 0;
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

    item.classList.toggle('hidden', !show);

    if(show) visibleProducts++;

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

    if(activeRating &&
       parseInt(item.dataset.rating) !== parseInt(activeRating)){
        show = false;
    }

    item.classList.toggle('hidden', !show);

    if(show) visibleBusinesses++;

  });

  document.getElementById("noProductsMsg").style.display =
      visibleProducts === 0 ? "block" : "none";

  document.getElementById("noBusinessesMsg").style.display =
      visibleBusinesses === 0 ? "block" : "none";

}

searchInput.addEventListener("keyup", applyFilters);

categoryBtns.forEach(btn => {
  btn.addEventListener("click", function(){

    categoryBtns.forEach(b => b.classList.remove("active"));
    this.classList.add("active");

    activeCategory = this.dataset.category;

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
</script>

</body>
</html>
