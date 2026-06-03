<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/cart_count.php";

$stmt = $conn->prepare("
    SELECT
        i.id,
        i.name,
        i.description,
        i.price,
        i.image,
        i.created_at,
        COALESCE(ic.name, 'Uncategorized') AS category_name,
        b.b_id AS business_id,
        b.business_name
    FROM inventory i
    INNER JOIN business_owner b ON i.owner_id = b.b_id
    LEFT JOIN inventory_categories ic ON i.category_id = ic.id
    ORDER BY i.created_at DESC
");

if(!$stmt){
    die("Product Query Error: " . $conn->error);
}

$stmt->execute();
$products = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>All Products | NasugView</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/responsive.css">
<style>
*{box-sizing:border-box;}
body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:#f8fafc;color:#0f172a;}
.page{max-width:1120px;margin:0 auto;padding:18px 14px 110px;}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px;}
.back-link{display:inline-flex;align-items:center;gap:8px;color:#001a47;text-decoration:none;font-weight:700;}
.cart-link{position:relative;color:#001a47;text-decoration:none;font-size:20px;}
.cart-badge{position:absolute;top:-8px;right:-10px;background:#ef4444;color:#fff;border-radius:999px;font-size:11px;padding:2px 6px;font-weight:700;}
h1{font-size:24px;margin:0 0 16px;color:#001a47;}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;}
.card{display:block;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;text-decoration:none;color:inherit;box-shadow:0 4px 12px rgba(15,23,42,.05);}
.card img{width:100%;height:150px;object-fit:cover;background:#e5e7eb;}
.body{padding:12px;}
.name{font-weight:800;color:#001a47;margin-bottom:5px;}
.meta{font-size:12px;color:#64748b;margin-bottom:6px;}
.price{font-weight:800;color:#0f172a;}
.empty{padding:28px;text-align:center;background:#fff;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;}
</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>
<body>
<?php include "mobile_back_button.php"; ?>
<div class="page">
    <div class="topbar">
        <a href="marketplace.php" class="back-link"><i class="fa fa-arrow-left"></i> Marketplace</a>
        <a href="cart.php" class="cart-link">
            <i class="fa fa-shopping-cart"></i>
            <?php if($cartCount > 0): ?><span class="cart-badge"><?= $cartCount ?></span><?php endif; ?>
        </a>
    </div>

    <h1>All Products</h1>

    <?php if($products->num_rows > 0): ?>
    <div class="grid">
        <?php while($row = $products->fetch_assoc()): ?>
        <a href="productdetails.php?id=<?= (int) $row['id'] ?>" class="card">
            <img src="uploads/product/<?= htmlspecialchars($row['image'] ?: 'default_product.jpg') ?>" alt="<?= htmlspecialchars($row['name']) ?>">
            <div class="body">
                <div class="name"><?= htmlspecialchars($row['name']) ?></div>
                <div class="meta"><?= htmlspecialchars($row['category_name']) ?> • <?= htmlspecialchars($row['business_name']) ?></div>
                <div class="price">&#8369;<?= number_format((float) $row['price'], 2) ?></div>
            </div>
        </a>
        <?php endwhile; ?>
    </div>
    <?php else: ?>
    <div class="empty">No products available.</div>
    <?php endif; ?>
</div>
<?php include "bottom_nav.php"; ?>
</body>
</html>
