<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/cart_count.php";

$topRatedOnly = isset($_GET['top_rated']);
$ratingJoin = "";
$ratingSelect = "0 AS avg_rating, 0 AS total_reviews";
$ratingOrder = "business_name ASC";
$ratingHaving = "";

if($topRatedOnly){
    $reviewTables = ["business_reviews", "reviews", "ratings"];
    $businessColumns = ["business_id", "b_id", "owner_id"];
    $ratingColumns = ["rating", "stars"];

    foreach($reviewTables as $table){
        $tableCheck = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
        if(!$tableCheck || $tableCheck->num_rows === 0){
            continue;
        }

        $businessColumn = null;
        foreach($businessColumns as $column){
            $columnCheck = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($column) . "'");
            if($columnCheck && $columnCheck->num_rows > 0){
                $businessColumn = $column;
                break;
            }
        }

        $ratingColumn = null;
        foreach($ratingColumns as $column){
            $columnCheck = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($column) . "'");
            if($columnCheck && $columnCheck->num_rows > 0){
                $ratingColumn = $column;
                break;
            }
        }

        if($businessColumn && $ratingColumn){
            $ratingJoin = "LEFT JOIN `$table` r ON r.`$businessColumn` = b.b_id";
            $ratingSelect = "ROUND(AVG(r.`$ratingColumn`), 1) AS avg_rating, COUNT(r.`$ratingColumn`) AS total_reviews";
            $ratingHaving = "HAVING total_reviews > 0";
            $ratingOrder = "avg_rating DESC, total_reviews DESC, business_name ASC";
            break;
        }
    }
}

$stmt = $conn->prepare("
    SELECT
        b.b_id,
        b.business_name,
        b.description,
        b.address,
        b.phone,
        b.business_photo,
        $ratingSelect
    FROM business_owner b
    $ratingJoin
    GROUP BY b.b_id, b.business_name, b.description, b.address, b.phone, b.business_photo
    $ratingHaving
    ORDER BY $ratingOrder
");

if(!$stmt){
    die("Business Query Error: " . $conn->error);
}

$stmt->execute();
$businesses = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $topRatedOnly ? 'Top Rated Businesses' : 'All Businesses' ?> | NasugView</title>
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
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;}
.card{display:block;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;text-decoration:none;color:inherit;box-shadow:0 4px 12px rgba(15,23,42,.05);}
.card img{width:100%;height:150px;object-fit:cover;background:#e5e7eb;}
.body{padding:12px;}
.name{font-weight:800;color:#001a47;margin-bottom:5px;}
.meta{font-size:13px;color:#64748b;line-height:1.45;}
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

    <h1><?= $topRatedOnly ? 'Top Rated Businesses' : 'All Businesses' ?></h1>

    <?php if($businesses->num_rows > 0): ?>
    <div class="grid">
        <?php while($row = $businesses->fetch_assoc()): ?>
        <?php
        $businessImage = !empty($row['business_photo'])
            ? "uploads/business_cover/" . $row['business_photo']
            : "assets/images/default-cover.png";
        ?>
        <a href="businessdetails.php?id=<?= (int) $row['b_id'] ?>" class="card">
            <img src="<?= htmlspecialchars($businessImage) ?>" alt="<?= htmlspecialchars($row['business_name']) ?>">
            <div class="body">
                <div class="name"><?= htmlspecialchars($row['business_name']) ?></div>
                <div class="meta"><?= htmlspecialchars($row['address'] ?: 'No address listed') ?></div>
                <?php if($topRatedOnly): ?>
                <div class="meta"><?= number_format((float) $row['avg_rating'], 1) ?> rating • <?= (int) $row['total_reviews'] ?> review<?= (int) $row['total_reviews'] === 1 ? '' : 's' ?></div>
                <?php endif; ?>
            </div>
        </a>
        <?php endwhile; ?>
    </div>
    <?php else: ?>
    <div class="empty"><?= $topRatedOnly ? 'No top rated businesses available yet.' : 'No businesses available.' ?></div>
    <?php endif; ?>
</div>
<?php include "bottom_nav.php"; ?>
</body>
</html>
