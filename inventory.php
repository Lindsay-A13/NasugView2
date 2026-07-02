<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/product_options_helper.php";

if($_SESSION['account_type'] !== "business_owner"){
    header("Location: more.php");
    exit;
}

$owner_id = $_SESSION['user_id'];

function ensureInventoryColumnExists(mysqli $conn, string $column, string $alterSql): void
{
    $check = $conn->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory'
          AND COLUMN_NAME = ?
        LIMIT 1
    ");

    if(!$check){
        return;
    }

    $check->bind_param("s", $column);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();

    if(!$exists){
        $conn->query($alterSql);
    }
}

ensureInventoryColumnExists(
    $conn,
    "last_added_at",
    "ALTER TABLE inventory ADD COLUMN last_added_at DATETIME NULL AFTER created_at"
);
$conn->query("UPDATE inventory SET last_added_at = created_at WHERE last_added_at IS NULL");
ensureProductOptionsSupport($conn);

$edit_id = $_GET['edit_id'] ?? 0;
$editProduct = null;

if($edit_id){

    $stmt = $conn->prepare("
        SELECT *
        FROM inventory
        WHERE id=? AND owner_id=?
    ");

    $stmt->bind_param("ii", $edit_id, $owner_id);
    $stmt->execute();

    $editProduct = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // auto switch to list tab
    $tab = "list";
}

/* CREATE CATEGORY */
if(isset($_POST['add_category'])){

    $cat = trim($_POST['category']);

    if($cat != ""){
        $stmt = $conn->prepare("
            INSERT INTO inventory_categories
            (owner_id, name)
            VALUES (?,?)
        ");
        $stmt->bind_param("is",$owner_id,$cat);
        $stmt->execute();
    }

    header("Location: inventory.php?tab=categories");
    exit;
}

/* UPDATE CATEGORY */
if(isset($_POST['update_category'])){

    $category_id = (int) ($_POST['category_id'] ?? 0);
    $cat = trim($_POST['category'] ?? '');

    if($category_id > 0 && $cat != ""){
        $stmt = $conn->prepare("
            UPDATE inventory_categories
            SET name=?
            WHERE id=? AND owner_id=?
        ");
        $stmt->bind_param("sii", $cat, $category_id, $owner_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: inventory.php?tab=categories");
    exit;
}

/* ADD SERVICE */
if(isset($_POST['add_service'])){

$name = $_POST['service_name'];
$desc = trim($_POST['service_description'] ?? '');
$desc = $desc === '' ? null : $desc;
$price = $_POST['service_price'];
$duration = $_POST['service_duration'];

$image_name = NULL;

if(!empty($_FILES['service_image']['name'])){

$ext = pathinfo($_FILES['service_image']['name'], PATHINFO_EXTENSION);

$image_name = time().rand().".".$ext;

move_uploaded_file(
$_FILES['service_image']['tmp_name'],
"uploads/services/".$image_name
);

}

$stmt = $conn->prepare("
INSERT INTO services
(owner_id,name,description,price,duration,image)
VALUES (?,?,?,?,?,?)
");

$stmt->bind_param(
"issdis",
$owner_id,
$name,
$desc,
$price,
$duration,
$image_name
);

$stmt->execute();

header("Location: inventory.php?tab=services");
exit;

}


/* UPDATE SERVICE */
if(isset($_POST['update_service'])){

$id=$_POST['service_id'];

$name=$_POST['service_name'];
$desc=trim($_POST['service_description'] ?? '');
$desc=$desc === '' ? null : $desc;
$price=$_POST['service_price'];
$duration=$_POST['service_duration'];

/* GET OLD IMAGE */
$stmt=$conn->prepare("
SELECT image FROM services
WHERE id=? AND owner_id=?
");

$stmt->bind_param("ii",$id,$owner_id);
$stmt->execute();

$old=$stmt->get_result()->fetch_assoc();

$image_name=$old['image'];

/* NEW IMAGE */
if(!empty($_FILES['service_image']['name'])){

if($old['image']){
unlink("uploads/services/".$old['image']);
}

$ext=pathinfo($_FILES['service_image']['name'],PATHINFO_EXTENSION);

$image_name=time().rand().".".$ext;

move_uploaded_file(
$_FILES['service_image']['tmp_name'],
"uploads/services/".$image_name
);

}

/* UPDATE SERVICE */
$stmt=$conn->prepare("
UPDATE services
SET name=?,description=?,price=?,duration=?,image=?
WHERE id=? AND owner_id=?
");

$stmt->bind_param(
"ssdisii",
$name,
$desc,
$price,
$duration,
$image_name,
$id,
$owner_id
);

$stmt->execute();

header("Location: inventory.php?tab=services");
exit;

}




/* ADD INVENTORY */
if(isset($_POST['add_inventory'])){

    $name = trim($_POST['name']);
    $desc = trim($_POST['description'] ?? '');
    $desc = $desc === '' ? null : $desc;
    $price = $_POST['price'];
    $stock = (int) $_POST['stock'];
    $variants = normalizeProductVariantInput($_POST);
    if(!empty($variants)){
        $stock = 0;
        foreach($variants as $variant){
            $stock += (int) $variant['stock'];
        }
    }
    $category = $_POST['category_id'];
    $expiration = $_POST['expiration_date'] ?: NULL;

    $image_name = null;
    $uploaded_image = null;

    if(!empty($_FILES['image']['name'])){

        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $uploaded_image = time().rand().".".$ext;

        move_uploaded_file(
            $_FILES['image']['tmp_name'],
            "uploads/product/".$uploaded_image
        );

    }

    $existing_stmt = $conn->prepare("
        SELECT id, image
        FROM inventory
        WHERE owner_id=?
          AND LOWER(TRIM(name)) = LOWER(TRIM(?))
        ORDER BY created_at ASC, id ASC
        LIMIT 1
    ");
    $existing_stmt->bind_param("is", $owner_id, $name);
    $existing_stmt->execute();
    $existingProduct = $existing_stmt->get_result()->fetch_assoc();
    $existing_stmt->close();

    if($existingProduct){
        if($uploaded_image){
            $uploaded_path = "uploads/product/".$uploaded_image;
            if(file_exists($uploaded_path)){
                unlink($uploaded_path);
            }
        }

        header("Location: inventory.php?tab=list&duplicate_product=".rawurlencode($name));
        exit;
    }

    $image_name = $uploaded_image;

    $stmt = $conn->prepare("
        INSERT INTO inventory
        (owner_id,name,description,price,stock,category_id,expiration_date,image,last_added_at)
        VALUES (?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)
    ");

    $stmt->bind_param(
        "issdiiss",
        $owner_id,
        $name,
        $desc,
        $price,
        $stock,
        $category,
        $expiration,
        $image_name
    );

    $stmt->execute();
    $productId = (int) $stmt->insert_id;
    $stmt->close();

    if($productId > 0){
        storeProductImages($conn, $productId, $_FILES['images'] ?? [], $image_name);
        syncProductVariants($conn, $productId, $variants);
    }

    header("Location: inventory.php?tab=list");
    exit;
}



/* UPDATE INVENTORY */
if(isset($_POST['update_inventory'])){

    $id = (int) $_POST['id'];
    $name = trim($_POST['name']);
    $desc = trim($_POST['description'] ?? '');
    $desc = $desc === '' ? null : $desc;
    $price = $_POST['price'];
    $stock = (int) $_POST['stock'];
    $variants = normalizeProductVariantInput($_POST);
    if(!empty($variants)){
        $stock = 0;
        foreach($variants as $variant){
            $stock += (int) $variant['stock'];
        }
    }
    $category = (int) $_POST['category_id'];
    $expiration = $_POST['expiration_date'] ?: NULL;

    $stmt = $conn->prepare("
        SELECT image FROM inventory
        WHERE id=? AND owner_id=?
    ");
    $stmt->bind_param("ii",$id,$owner_id);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();

    $image_name = $old['image'];

    if(!empty($_FILES['image']['name'])){

        if($old['image']){
            $deleteImageRow = $conn->prepare("DELETE FROM product_images WHERE product_id=? AND image=?");
            $deleteImageRow->bind_param("is", $id, $old['image']);
            $deleteImageRow->execute();
            $deleteImageRow->close();

            $oldImagePath = "uploads/product/".$old['image'];
            if(file_exists($oldImagePath)){
                unlink($oldImagePath);
            }
        }

        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $image_name = time().rand().".".$ext;

        move_uploaded_file(
            $_FILES['image']['tmp_name'],
            "uploads/product/".$image_name
        );
    }

    $stmt = $conn->prepare("
        UPDATE inventory
        SET name=?,
            description=?,
            price=?,
            last_added_at=CASE
                WHEN COALESCE(stock, 0) <> ? THEN CURRENT_TIMESTAMP
                ELSE last_added_at
            END,
            stock=?,
            category_id=?,
            expiration_date=?,
            image=?
        WHERE id=? AND owner_id=?
    ");

    $stmt->bind_param(
        "ssdiiissii",
        $name,
        $desc,
        $price,
        $stock,
        $stock,
        $category,
        $expiration,
        $image_name,
        $id,
        $owner_id
    );

    $stmt->execute();
    $stmt->close();

    storeProductImages($conn, $id, $_FILES['images'] ?? [], $image_name);
    syncProductVariants($conn, $id, $variants);

    header("Location: inventory.php?tab=list");
    exit;
}



/* DELETE */
if(isset($_GET['delete'])){

    $id = $_GET['delete'];

    $stmt=$conn->prepare("
        SELECT image FROM inventory
        WHERE id=? AND owner_id=?
    ");

    $stmt->bind_param("ii",$id,$owner_id);
    $stmt->execute();

    $img=$stmt->get_result()->fetch_assoc();

    if($img && $img['image']){
        $imagePath = "uploads/product/".$img['image'];
        if(file_exists($imagePath)){
            unlink($imagePath);
        }
    }

    $image_stmt = $conn->prepare("SELECT image FROM product_images WHERE product_id=?");
    $image_stmt->bind_param("i", $id);
    $image_stmt->execute();
    $extraImages = $image_stmt->get_result();
    while($extra = $extraImages->fetch_assoc()){
        $imagePath = "uploads/product/".$extra['image'];
        if($imagePath !== "uploads/product/".($img['image'] ?? '') && file_exists($imagePath)){
            unlink($imagePath);
        }
    }
    $image_stmt->close();

    $cleanupImages = $conn->prepare("DELETE FROM product_images WHERE product_id=?");
    $cleanupImages->bind_param("i", $id);
    $cleanupImages->execute();
    $cleanupImages->close();

    $cleanupVariants = $conn->prepare("DELETE FROM product_variants WHERE product_id=?");
    $cleanupVariants->bind_param("i", $id);
    $cleanupVariants->execute();
    $cleanupVariants->close();

    $stmt=$conn->prepare("
        DELETE FROM inventory
        WHERE id=? AND owner_id=?
    ");

    $stmt->bind_param("ii",$id,$owner_id);
    $stmt->execute();

    header("Location: inventory.php?tab=list");
    exit;
}

/* DELETE CATEGORY */
if(isset($_GET['delete_category'])){

    $category_id = (int) $_GET['delete_category'];

    if($category_id > 0){
        $clear = $conn->prepare("
            UPDATE inventory
            SET category_id=NULL
            WHERE category_id=? AND owner_id=?
        ");
        $clear->bind_param("ii", $category_id, $owner_id);
        $clear->execute();
        $clear->close();

        $stmt = $conn->prepare("
            DELETE FROM inventory_categories
            WHERE id=? AND owner_id=?
        ");
        $stmt->bind_param("ii", $category_id, $owner_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: inventory.php?tab=categories");
    exit;
}

/* DELETE SERVICE */
if(isset($_GET['delete_service'])){

$id=$_GET['delete_service'];

$stmt=$conn->prepare("
DELETE FROM services
WHERE id=? AND owner_id=?
");

$stmt->bind_param("ii",$id,$owner_id);

$stmt->execute();

header("Location: inventory.php?tab=services");
exit;

}



/* LOAD CATEGORIES */
$cat_stmt=$conn->prepare("
SELECT * FROM inventory_categories
WHERE owner_id=?
ORDER BY name ASC
");

$cat_stmt->bind_param("i",$owner_id);
$cat_stmt->execute();

$categories=$cat_stmt->get_result();


/* LOAD INVENTORY */
$inv_stmt=$conn->prepare("
SELECT i.*,c.name as category
FROM inventory i
LEFT JOIN inventory_categories c
ON i.category_id=c.id
WHERE i.owner_id=?
ORDER BY COALESCE(i.last_added_at, i.created_at) DESC, i.created_at DESC
");

$inv_stmt->bind_param("i",$owner_id);
$inv_stmt->execute();

$inventory=$inv_stmt->get_result();
$inventoryRows = [];
$productIds = [];
while($row = $inventory->fetch_assoc()){
    $inventoryRows[] = $row;
    $productIds[] = (int) $row['id'];
}

$productImagesById = [];
$productVariantsById = [];
if(!empty($productIds)){
    $idsSql = implode(",", array_map("intval", $productIds));

    $imagesResult = $conn->query("
        SELECT product_id, image
        FROM product_images
        WHERE product_id IN ($idsSql)
        ORDER BY sort_order ASC, id ASC
    ");
    if($imagesResult){
        while($imageRow = $imagesResult->fetch_assoc()){
            $productImagesById[(int) $imageRow['product_id']][] = $imageRow['image'];
        }
    }

    $variantsResult = $conn->query("
        SELECT id, product_id, color, size, price, stock
        FROM product_variants
        WHERE product_id IN ($idsSql)
        ORDER BY id ASC
    ");
    if($variantsResult){
        while($variantRow = $variantsResult->fetch_assoc()){
            $productVariantsById[(int) $variantRow['product_id']][] = $variantRow;
        }
    }
}

/* LOAD SERVICES */
$svc_stmt = $conn->prepare("
SELECT s.*
FROM services s
WHERE s.owner_id=?
ORDER BY s.created_at DESC
");

$svc_stmt->bind_param("i",$owner_id);
$svc_stmt->execute();

$services = $svc_stmt->get_result();

$tab=$_GET['tab'] ?? "list";
if(!in_array($tab, ["list", "services", "categories"], true)){
    $tab = "list";
}
$duplicateProductName = trim($_GET['duplicate_product'] ?? '');
$editProductVariants = [];
if($editProduct){
    $editVariantStmt = $conn->prepare("
        SELECT id, product_id, color, size, price, stock
        FROM product_variants
        WHERE product_id=?
        ORDER BY id ASC
    ");
    if($editVariantStmt){
        $editId = (int) $editProduct['id'];
        $editVariantStmt->bind_param("i", $editId);
        $editVariantStmt->execute();
        $editVariantRows = $editVariantStmt->get_result();
        while($variant = $editVariantRows->fetch_assoc()){
            $editProductVariants[] = $variant;
        }
        $editVariantStmt->close();
    }
}
?>

<!DOCTYPE html>
<html>
<head>

<meta name="viewport" content="width=device-width,initial-scale=1">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/inventory.css">
<link rel="stylesheet" href="assets/css/responsive.css">

<style>
.modal-content label{
display:block;
margin:10px 0 5px;
font-size:13px;
font-weight:600;
color:#334155;
}

.duplicate-warning-modal{
align-items:center !important;
justify-content:center !important;
padding:16px !important;
}

.duplicate-warning-modal .modal-content{
width:100% !important;
max-width:380px !important;
border-radius:12px !important;
padding:20px !important;
padding-bottom:20px !important;
box-sizing:border-box;
}

.duplicate-warning-modal .modal-content::after{
display:none !important;
}

.duplicate-warning-modal button{
width:auto !important;
min-width:72px;
padding:11px 18px !important;
margin:0 !important;
}

#modal,
#serviceModal,
#viewModal,
#viewServiceModal{
align-items:center !important;
justify-content:center !important;
padding:18px !important;
z-index:1000001 !important;
}

#modal .modal-content,
#serviceModal .modal-content{
width:100% !important;
max-width:620px !important;
max-height:calc(100vh - 80px) !important;
overflow-y:auto !important;
border-radius:12px !important;
padding:20px !important;
padding-bottom:26px !important;
box-sizing:border-box !important;
}

#viewModal .modal-content,
#viewServiceModal .modal-content{
width:100% !important;
max-width:430px !important;
max-height:calc(100vh - 80px) !important;
overflow-y:auto !important;
border-radius:12px !important;
box-sizing:border-box !important;
}

#inventoryForm{
gap:10px !important;
}

#inventoryForm input,
#inventoryForm select,
#inventoryForm textarea{
font-size:14px !important;
padding:10px 12px !important;
}

#inventoryForm textarea{
min-height:74px !important;
resize:vertical !important;
}

#inventoryForm #submitBtn{
width:100% !important;
margin-top:4px !important;
padding:12px !important;
font-size:14px !important;
}

.variant-section{
margin:14px 0;
padding:12px;
border:1px solid #e5e7eb;
border-radius:10px;
background:#f8fafc;
}

.variant-heading{
display:flex;
justify-content:space-between;
align-items:center;
gap:10px;
font-size:13px;
font-weight:700;
color:#001a47;
}

.variant-heading button{
width:auto !important;
margin:0 !important;
padding:8px 10px !important;
border-radius:8px !important;
font-size:12px !important;
}

.variant-help{
margin-top:6px;
font-size:12px;
line-height:1.4;
color:#64748b;
}

.variant-row{
display:grid;
grid-template-columns:1fr 1fr 1fr 90px 36px;
gap:8px;
align-items:end;
margin-top:10px;
}

.variant-row input{
padding:9px !important;
font-size:13px !important;
}

.variant-remove{
height:36px;
border:0;
border-radius:8px;
background:#fee2e2;
color:#b91c1c;
cursor:pointer;
}

.view-gallery{
display:grid;
grid-template-columns:repeat(4,1fr);
gap:8px;
margin:-5px 0 15px;
}

.view-gallery img{
width:100%;
height:58px;
object-fit:cover;
border-radius:8px;
border:1px solid #e5e7eb;
}

.view-variants{
display:flex;
flex-direction:column;
gap:6px;
margin-top:6px;
}

.view-variant-row{
padding:8px 10px;
border:1px solid #e5e7eb;
border-radius:8px;
background:#f8fafc;
font-size:13px;
color:#334155;
}

@media(max-width:768px){
#modal,
#serviceModal,
#viewModal,
#viewServiceModal{
align-items:flex-end !important;
padding:0 !important;
}

#modal .modal-content,
#serviceModal .modal-content,
#viewModal .modal-content,
#viewServiceModal .modal-content{
max-width:100% !important;
max-height:calc(100vh - 18px) !important;
border-radius:16px 16px 0 0 !important;
padding:16px !important;
padding-bottom:24px !important;
}

.variant-row{
grid-template-columns:1fr 1fr;
}
}
</style>

<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body class="inventory-page">
<?php include 'mobile_back_button.php'; ?>

<div class="header"></div>


<div class="tabs">

<a href="?tab=list" class="tab <?= $tab=='list'?'active':'' ?>">Product</a>
<a href="?tab=services" class="tab <?= $tab=='services'?'active':'' ?>">Services</a>
<a href="?tab=categories" class="tab <?= $tab=='categories'?'active':'' ?>">Categories</a>

</div>



<div class="container">

<?php if($tab=="list"): ?>

<div class="inventory-toolbar" style="
margin-bottom:15px;
display:flex;
justify-content:space-between;
align-items:center;
gap:10px;
flex-wrap:wrap;
">

<div class="inventory-filters" style="display:flex; gap:10px; flex:1;">

<input
type="text"
id="searchInput"
placeholder="Search product..."
class="inventory-search"
style="
padding:10px;
border:1px solid #ddd;
border-radius:8px;
width:200px;
"
onkeyup="filterTable()"
>

<select
id="categoryFilter"
class="inventory-filter"
style="
padding:10px;
border:1px solid #ddd;
border-radius:8px;
"
onchange="filterTable()"
>

<option value="">All Categories</option>

<?php
$categories->data_seek(0);
while($cat=$categories->fetch_assoc()):
?>

<option value="<?= strtolower($cat['name']) ?>">
<?= htmlspecialchars($cat['name']) ?>
</option>

<?php endwhile; ?>

</select>

</div>


<button onclick="openModal()" class="add-btn">
+ Add Product
</button>

</div>



<div class="table-card">

<table id="inventoryTable">

<thead>
<tr>
<th>Image</th>
<th>Name</th>
<th>Category</th>
<th>Price</th>
<th>Stock</th>
<th>First Added</th>
<th>Latest Added</th>
<th>Action</th>
</tr>
</thead>

<tbody>

<?php foreach($inventoryRows as $row): ?>
<?php
$rowImages = $productImagesById[(int) $row['id']] ?? [];
if(empty($rowImages) && !empty($row['image'])){
    $rowImages[] = $row['image'];
}
$rowVariants = $productVariantsById[(int) $row['id']] ?? [];
?>

<tr>

<td>
<?php if($row['image']): ?>
<img src="uploads/product/<?= $row['image'] ?>" class="product-img">
<?php endif; ?>
</td>

<td><?= htmlspecialchars($row['name']) ?></td>

<td><?= htmlspecialchars($row['category']) ?></td>

<td>₱<?= number_format($row['price'],2) ?></td>

<td><?= $row['stock'] ?></td>

<td>
<?= date("M d, Y", strtotime($row['created_at'])) ?>
</td>

<td>
<?= date("M d, Y", strtotime($row['last_added_at'] ?? $row['created_at'])) ?>
</td>

<td>

<div class="action-wrapper">

<div class="action-btn"
onclick='openViewModal(
<?= json_encode($row["name"]) ?>,
<?= json_encode($row["description"]) ?>,
<?= json_encode($row["category"]) ?>,
<?= json_encode($row["price"]) ?>,
<?= json_encode($row["stock"]) ?>,
<?= json_encode($row["expiration_date"]) ?>,
<?= json_encode($rowImages) ?>,
<?= json_encode($rowVariants) ?>
)'>
<i class="fa-regular fa-eye"></i>
</div>

<div class="action-btn"
onclick='openDropdown(
event,
<?= $row["id"] ?>,
<?= json_encode($row["name"]) ?>,
<?= json_encode($row["description"]) ?>,
<?= $row["price"] ?>,
<?= $row["stock"] ?>,
<?= $row["category_id"] ?>,
<?= json_encode($row["expiration_date"]) ?>,
<?= json_encode($rowVariants) ?>
)'>
<i class="fa-solid fa-ellipsis-vertical"></i>
</div>

</div>

</td>

</tr>


<?php endforeach; ?>

<tr id="noResultsRow" style="display:none;">
<td colspan="8" style="text-align:center; padding:20px; color:#888;">
No products found
</td>
</tr>

</tbody>

</table>

</div>


<?php endif; ?>
<?php if($tab=="categories"): ?>

<form method="POST" class="category-form" id="categoryForm">

<input type="hidden" name="category_id" id="category_id">

<label for="category_name">Category Name</label>
<input
name="category"
id="category_name"
placeholder="Enter new category"
required
>

<button name="add_category" id="categorySubmitBtn">
Add Category
</button>

<button type="button" class="category-cancel-btn" id="categoryCancelBtn" onclick="resetCategoryForm()" style="display:none;">
Cancel
</button>

</form>

<div class="table-card">

<table>

<thead>
<tr>
<th>Category</th>
<th>Action</th>
</tr>
</thead>

<tbody>

<?php
$categories->data_seek(0);
while($cat=$categories->fetch_assoc()):
?>

<tr>
<td><?= htmlspecialchars($cat['name']) ?></td>
<td>
<div class="action-wrapper">
<div class="action-btn" onclick='editCategory(<?= (int) $cat["id"] ?>, <?= json_encode($cat["name"]) ?>)'>
<i class="fa-solid fa-pen"></i>
</div>
<a
href="?delete_category=<?= (int) $cat['id'] ?>"
class="action-btn category-delete-action"
onclick="return confirm('Delete this category? Products using it will become uncategorized.');"
>
<i class="fa-solid fa-trash"></i>
</a>
</div>
</td>
</tr>

<?php endwhile; ?>

<?php if($categories->num_rows==0): ?>
<tr>
<td style="text-align:center;padding:30px;color:#888;">
No categories yet
</td>
</tr>
<?php endif; ?>

</tbody>

</table>

</div>

<?php endif; ?>


<?php if($tab=="services"): ?>

<div class="service-toolbar" style="
margin-bottom:15px;
display:flex;
justify-content:space-between;
align-items:center;
gap:10px;
flex-wrap:wrap;
">

<input
type="text"
id="serviceSearchInput"
placeholder="Search service..."
class="service-search"
style="
padding:10px;
border:1px solid #ddd;
border-radius:8px;
width:220px;
"
onkeyup="filterServiceTable()"
>

<button onclick="openServiceModal()" class="add-btn">
+ Add Service
</button>

</div>



<div class="table-card">

<table id="serviceTable">

<thead>
<tr>
<th>Image</th>
<th>Name</th>
<th>Description</th>
<th>Price</th>
<th>Duration</th>
<th>Date Added</th>
<th>Action</th>
</tr>
</thead>

<tbody>

<?php
$services->data_seek(0);
while($svc=$services->fetch_assoc()):
?>

<tr>

<td>
<?php if($svc['image']): ?>
<img src="uploads/services/<?= htmlspecialchars($svc['image']) ?>"
style="
width:55px;
height:55px;
object-fit:cover;
border-radius:8px;
border:1px solid #eee;
">
<?php else: ?>
<span style="color:#aaa;">—</span>
<?php endif; ?>
</td>

<td>
<?= htmlspecialchars($svc['name']) ?>
</td>

<td>
<?= htmlspecialchars($svc['description']) ?>
</td>

<td>
₱<?= number_format($svc['price'],2) ?>
</td>

<td>
<?= intval($svc['duration']) ?> hour<?= intval($svc['duration']) === 1 ? '' : 's' ?>
</td>

<td>
<?= date("M d, Y", strtotime($svc['created_at'])) ?>
</td>

<td>

<div class="action-wrapper">

<div class="action-btn"
onclick='openViewServiceModal(
<?= json_encode($svc["name"]) ?>,
<?= json_encode($svc["description"]) ?>,
<?= json_encode($svc["price"]) ?>,
<?= json_encode($svc["duration"]) ?>,
<?= json_encode($svc["image"]) ?>

)'
>
<i class="fa-regular fa-eye"></i>
</div>

<div class="action-btn"
onclick='openServiceDropdown(
event,
<?= $svc["id"] ?>,
<?= json_encode($svc["name"]) ?>,
<?= json_encode($svc["description"]) ?>,
<?= json_encode($svc["price"]) ?>,
<?= json_encode($svc["duration"]) ?>,
<?= json_encode($svc["image"]) ?>
)'

>
<i class="fa-solid fa-ellipsis-vertical"></i>
</div>

</div>

</td>

</tr>

<?php endwhile; ?>

<?php if($services->num_rows==0): ?>
<tr>
<td colspan="7" style="
text-align:center;
padding:40px 20px;
color:#888;
font-size:15px;
">
No services yet
</td>
</tr>
<?php endif; ?>

</tbody>

</table>

</div>


<?php endif; ?>

</div>



<?php if($duplicateProductName !== ''): ?>
<!-- DUPLICATE PRODUCT WARNING MODAL -->
<div class="modal duplicate-warning-modal show" id="duplicateProductModal">

<div class="modal-content">

<h3 style="margin:0 0 10px;color:#b42318;font-size:18px;">Duplicate product name</h3>

<p style="margin:0 0 16px;color:#334155;line-height:1.45;">
"<?= htmlspecialchars($duplicateProductName) ?>" already exists in your inventory. Use Edit to update its stock instead of adding another product with the same name.
</p>

<button type="button" onclick="document.getElementById('duplicateProductModal').classList.remove('show')">
OK
</button>

</div>
</div>
<?php endif; ?>





<!-- ADD MODAL -->
<div class="modal" id="modal">

<div class="modal-content">

<form method="POST" enctype="multipart/form-data" id="inventoryForm">

<input type="hidden" name="id" id="edit_id">


<!-- PRODUCT NAME -->
<label for="edit_name">Product Name</label>
<input 
name="name" 
id="edit_name" 
placeholder="Product Name" 
required
>


<!-- DESCRIPTION -->
<label for="description_field">Description</label>
<textarea 
name="description" 
id="description_field"
placeholder="Description"
></textarea>


<!-- PRICE -->
<label for="edit_price">Price</label>
<input 
name="price" 
id="edit_price" 
type="number" 
step="0.01" 
placeholder="Price" 
required
>


<!-- STOCK -->
<label for="edit_stock">Stock</label>
<input 
name="stock" 
id="edit_stock" 
type="number" 
placeholder="Stock"
required
>

<div class="variant-section">
<div class="variant-heading">
<span>Variations</span>
<button type="button" onclick="addVariantRow()">+ Add Variation</button>
</div>
<div class="variant-help">Use this for products with options like T-shirt colors and sizes. If variations are added, total stock comes from the variation rows.</div>
<div id="variantRows"></div>
</div>


<!-- CATEGORY -->
<label for="edit_category">Category</label>
<select 
name="category_id" 
id="edit_category" 
required
>

<option value="" disabled selected>Select Category</option>

<?php
$categories->data_seek(0);
while($cat=$categories->fetch_assoc()):
?>

<option 
value="<?= $cat['id'] ?>"
>
<?= htmlspecialchars($cat['name']) ?>
</option>

<?php endwhile; ?>

</select>


<!-- OPTIONAL EXPIRATION DATE -->
<div id="expirationWrapper">
<label>Expiration Date <span style="color:#64748b;font-weight:400;">(optional)</span></label>
<input type="date" name="expiration_date" id="edit_exp">
</div>

<!-- IMAGE -->
<label for="edit_image">Product Image</label>
<input type="file" name="image" id="edit_image" accept="image/*">

<label for="extra_images">Additional Pictures</label>
<input type="file" name="images[]" id="extra_images" accept="image/*" multiple>


<!-- BUTTON -->
<button name="add_inventory" id="submitBtn">
Add Product
</button>


</form>

</div>
</div>



<!-- VIEW MODAL -->
<div class="modal" id="viewModal">

<div class="modal-content">

<img id="view_image"
style="
width:100%;
height:180px;
object-fit:cover;
border-radius:10px;
margin-bottom:15px;
display:none;
">

<div id="view_gallery" class="view-gallery"></div>

<div style="margin-bottom:8px;">
<b>Name:</b><br>
<span id="view_name">-</span>
</div>

<div style="margin-bottom:8px;">
<b>Description:</b><br>
<span id="view_description">-</span>
</div>

<div style="margin-bottom:8px;">
<b>Category:</b><br>
<span id="view_category">-</span>
</div>

<div style="margin-bottom:8px;">
<b>Price:</b><br>
₱<span id="view_price">0.00</span>
</div>

<div style="margin-bottom:8px;">
<b>Stock:</b><br>
<span id="view_stock">0</span>
</div>

<div>
<b>Expiration:</b><br>
<span id="view_exp">None</span>
</div>

<div style="margin-top:8px;">
<b>Variations:</b><br>
<div id="view_variants" class="view-variants">None</div>
</div>

</div>
</div>

<!-- SERVICE VIEW MODAL -->
<div class="modal" id="viewServiceModal">

<div class="modal-content">

<img id="view_service_image"
style="
width:100%;
height:180px;
object-fit:cover;
border-radius:10px;
margin-bottom:15px;
display:none;
">

<div style="margin-bottom:8px;">
<b>Name:</b><br>
<span id="view_service_name">-</span>
</div>

<div style="margin-bottom:8px;">
<b>Description:</b><br>
<span id="view_service_description">-</span>
</div>

<div style="margin-bottom:8px;">
<b>Price:</b><br>
₱<span id="view_service_price">0.00</span>
</div>

<div style="margin-bottom:8px;">
<b>Duration:</b><br>
<span id="view_service_duration">0</span> hour(s)
</div>

</div>
</div>

<!-- SERVICE MODAL -->
<div class="modal" id="serviceModal">

<div class="modal-content">

<form method="POST" enctype="multipart/form-data" id="serviceForm">

<input type="hidden" name="service_id" id="edit_service_id">

<label for="edit_service_name">Service Name</label>
<input
name="service_name"
id="edit_service_name"
placeholder="Service Name"
required
>

<label for="edit_service_description">Description</label>
<textarea
name="service_description"
id="edit_service_description"
placeholder="Description"
></textarea>

<label for="edit_service_price">Price</label>
<input
name="service_price"
id="edit_service_price"
type="number"
step="0.01"
placeholder="Price"
required
>

<label for="edit_service_duration">Duration</label>
<input
name="service_duration"
id="edit_service_duration"
type="number"
placeholder="Duration (hours)"
required
>

<label for="service_image">Service Image</label>
<input
type="file"
name="service_image"
id="service_image"
accept="image/*"
>

<button name="add_service" id="serviceSubmitBtn">
Add Service
</button>



</form>



</div>
</div>


<?php include 'bottom_nav.php'; ?>



<script>

function openModal(){

const modal = document.getElementById("modal");

modal.classList.add("show");

document.getElementById("inventoryForm").reset();

document.getElementById("description_field").value = "";

document.getElementById("edit_id").value = "";

document.getElementById("edit_exp").value = "";
clearVariantRows();

const btn = document.getElementById("submitBtn");

btn.innerText = "Add Product";

btn.name = "add_inventory";

}



/* CLOSE MODAL WHEN CLICK OUTSIDE */
window.onclick = function(e){

if(e.target.classList.contains("modal")){
e.target.classList.remove("show");
}

if(!e.target.closest(".action-wrapper")){
document.querySelectorAll(".dropdown").forEach(function(drop){
drop.classList.remove("show");
});
}

};

function openServiceModal(){

const modal = document.getElementById("serviceModal");

modal.classList.add("show");

document.getElementById("serviceForm").reset();

document.getElementById("edit_service_id").value="";

document.getElementById("edit_service_description").value="";

const btn = document.getElementById("serviceSubmitBtn");

btn.innerText="Add Service";

btn.name="add_service";

}




/* FIX THREE DOTS DROPDOWN */
function toggleDropdown(button){

    const dropdown = button.nextElementSibling;

    document.querySelectorAll(".dropdown").forEach(function(d){
        if(d !== dropdown){
            d.classList.remove("show");
        }
    });

    dropdown.classList.toggle("show");

}



/* VIEW MODAL */
function clearVariantRows(){
const rows = document.getElementById("variantRows");
if(rows){
rows.innerHTML = "";
}
}

function addVariantRow(variant){
const rows = document.getElementById("variantRows");
if(!rows) return;

const row = document.createElement("div");
row.className = "variant-row";
row.innerHTML = `
<input name="variant_color[]" placeholder="Color" value="${escapeAttr(variant && variant.color ? variant.color : "")}">
<input name="variant_size[]" placeholder="Size" value="${escapeAttr(variant && variant.size ? variant.size : "")}">
<input name="variant_price[]" type="number" step="0.01" placeholder="Price" value="${escapeAttr(variant && variant.price !== null && variant.price !== undefined ? variant.price : "")}">
<input name="variant_stock[]" type="number" min="0" placeholder="Stock" value="${escapeAttr(variant && variant.stock !== null && variant.stock !== undefined ? variant.stock : "")}">
<button type="button" class="variant-remove" onclick="this.closest('.variant-row').remove()"><i class="fa-solid fa-xmark"></i></button>
`;
rows.appendChild(row);
}

function escapeAttr(value){
return String(value)
.replace(/&/g, "&amp;")
.replace(/"/g, "&quot;")
.replace(/</g, "&lt;")
.replace(/>/g, "&gt;");
}

function openViewModal(name,desc,cat,price,stock,exp,images,variants){

const modal = document.getElementById("viewModal");

modal.classList.add("show");

/* SET VALUES */
document.getElementById("view_name").innerText = name || "-";
document.getElementById("view_description").innerText = desc || "-";
document.getElementById("view_category").innerText = cat || "-";
document.getElementById("view_price").innerText = price || "0.00";
document.getElementById("view_stock").innerText = stock || "0";
document.getElementById("view_exp").innerText = exp || "None";

/* IMAGE */
const image = document.getElementById("view_image");
const gallery = document.getElementById("view_gallery");
let imageList = Array.isArray(images) ? images : (images ? [images] : []);

if(imageList.length > 0){
image.src = "uploads/product/" + imageList[0];
image.style.display = "block";
}else{
image.style.display = "none";
}

gallery.innerHTML = "";
imageList.slice(1).forEach(function(img){
const thumb = document.createElement("img");
thumb.src = "uploads/product/" + img;
thumb.alt = "";
thumb.onclick = function(){
image.src = this.src;
image.style.display = "block";
};
gallery.appendChild(thumb);
});

const variantWrap = document.getElementById("view_variants");
variantWrap.innerHTML = "";
if(Array.isArray(variants) && variants.length > 0){
variants.forEach(function(variant){
const label = [variant.color, variant.size].filter(Boolean).join(" / ") || "Default";
const priceText = variant.price !== null && variant.price !== undefined && variant.price !== "" ? " - ₱" + Number(variant.price).toFixed(2) : "";
const div = document.createElement("div");
div.className = "view-variant-row";
div.textContent = label + priceText + " - Stock: " + (parseInt(variant.stock, 10) || 0);
variantWrap.appendChild(div);
});
}else{
variantWrap.textContent = "None";
}

}

function openEditServiceModal(id,name,desc,price,duration,image){

const modal = document.getElementById("serviceModal");

modal.classList.add("show");

/* SET BASIC VALUES */
document.getElementById("edit_service_id").value=id;
document.getElementById("edit_service_name").value=name;
document.getElementById("edit_service_description").value=desc || "";
document.getElementById("edit_service_price").value=price;
document.getElementById("edit_service_duration").value=duration;

/* CHANGE BUTTON */
const btn = document.getElementById("serviceSubmitBtn");

btn.innerText="Update Service";
btn.name="update_service";

}



function openEditModal(id,name,desc,price,stock,cat,exp,variants){

const modal = document.getElementById("modal");

modal.classList.add("show");

document.getElementById("edit_id").value = id;
document.getElementById("edit_name").value = name;
document.getElementById("description_field").value = desc || "";
document.getElementById("edit_price").value = price;
document.getElementById("edit_stock").value = stock;
document.getElementById("edit_category").value = cat;
document.getElementById("edit_exp").value = exp;
clearVariantRows();
if(Array.isArray(variants)){
variants.forEach(function(variant){
addVariantRow(variant);
});
}

const btn = document.getElementById("submitBtn");

btn.innerText = "Update Inventory";
btn.name = "update_inventory";

}


let selectedRow = null;

function openDropdown(event,id,name,desc,price,stock,cat,exp,variants){

event.stopPropagation();

selectedRow = {id,name,desc,price,stock,cat,exp,variants};

const dropdown = document.getElementById("globalDropdown");

dropdown.style.display = "block";

/* get screen size */
const screenHeight = window.innerHeight;
const screenWidth = window.innerWidth;

/* dropdown size */
const dropdownHeight = 120;
const dropdownWidth = 180;

/* click position */
let top = event.clientY;
let left = event.clientX;

/* check if near bottom */
if(top + dropdownHeight > screenHeight){
top = top - dropdownHeight;
}

/* check if near right */
if(left + dropdownWidth > screenWidth){
left = screenWidth - dropdownWidth - 10;
}

/* prevent negative */
if(top < 10){
top = 10;
}

if(left < 10){
left = 10;
}

/* apply position */
dropdown.style.top = top + "px";
dropdown.style.left = left + "px";

/* EDIT */
document.getElementById("dropdownEdit").onclick = function(){

openEditModal(
selectedRow.id,
selectedRow.name,
selectedRow.desc,
selectedRow.price,
selectedRow.stock,
selectedRow.cat,
selectedRow.exp,
selectedRow.variants
);

dropdown.style.display="none";

};

/* DELETE */
document.getElementById("dropdownDelete").href =
"?delete=" + selectedRow.id;

}



/* CLOSE WHEN CLICK OUTSIDE */
document.addEventListener("click", function(){
document.getElementById("globalDropdown").style.display="none";
});

function filterTable(){

const search =
document.getElementById("searchInput")
.value.toLowerCase().trim();

const category =
document.getElementById("categoryFilter")
.value.toLowerCase().trim();

const rows =
document.querySelectorAll("#inventoryTable tbody tr");

let visibleCount = 0;
let hasFilter = (search !== "" || category !== "");

rows.forEach(function(row){

if(row.id === "noResultsRow") return;

const name =
row.children[1].innerText.toLowerCase();

const cat =
row.children[2].innerText.toLowerCase();

const matchSearch =
name.includes(search);

const matchCategory =
category === "" || cat.includes(category);

if(matchSearch && matchCategory){

row.style.display="";
visibleCount++;

}else{

row.style.display="none";

}

});

const noRow =
document.getElementById("noResultsRow");

/* SHOW ONLY IF FILTERING AND NO MATCH */
if(hasFilter && visibleCount === 0){

noRow.style.display="";

}else{

noRow.style.display="none";

}

}
function openViewServiceModal(name,desc,price,duration,image){

const modal = document.getElementById("viewServiceModal");

modal.classList.add("show");

/* BASIC INFO */
document.getElementById("view_service_name").innerText = name || "-";
document.getElementById("view_service_description").innerText = desc || "-";
document.getElementById("view_service_price").innerText = price || "0.00";
document.getElementById("view_service_duration").innerText = duration || "0";

/* IMAGE */
const img = document.getElementById("view_service_image");

if(image && image !== "null"){
img.src = "uploads/services/" + image;
img.style.display = "block";
}else{
img.style.display = "none";
}

}


function openServiceDropdown(event,id,name,desc,price,duration,image){

event.stopPropagation();

const dropdown = document.getElementById("globalDropdown");

dropdown.style.display="block";
dropdown.style.top=event.clientY+"px";
dropdown.style.left=(event.clientX-180)+"px";

/* DELETE */
document.getElementById("dropdownDelete").href =
"?delete_service="+id;

/* EDIT */
document.getElementById("dropdownEdit").onclick=function(){

openEditServiceModal(
id,name,desc,price,duration,image
);


dropdown.style.display="none";

};

}


function filterServiceTable(){

const search=document
.getElementById("serviceSearchInput")
.value.toLowerCase();

const rows=document
.querySelectorAll("#serviceTable tbody tr");

let visible=0;

rows.forEach(function(row){

const name=row.children[1].innerText.toLowerCase();
const desc=row.children[2].innerText.toLowerCase();

if(name.includes(search) || desc.includes(search)){

row.style.display="";
visible++;

}else{

row.style.display="none";

}

});

}

function editCategory(id, name){
    const idInput = document.getElementById("category_id");
    const nameInput = document.getElementById("category_name");
    const submitBtn = document.getElementById("categorySubmitBtn");
    const cancelBtn = document.getElementById("categoryCancelBtn");

    if(!idInput || !nameInput || !submitBtn || !cancelBtn){
        return;
    }

    idInput.value = id;
    nameInput.value = name;
    nameInput.focus();
    submitBtn.name = "update_category";
    submitBtn.textContent = "Update Category";
    cancelBtn.style.display = "inline-flex";
}

function resetCategoryForm(){
    const idInput = document.getElementById("category_id");
    const nameInput = document.getElementById("category_name");
    const submitBtn = document.getElementById("categorySubmitBtn");
    const cancelBtn = document.getElementById("categoryCancelBtn");

    if(!idInput || !nameInput || !submitBtn || !cancelBtn){
        return;
    }

    idInput.value = "";
    nameInput.value = "";
    submitBtn.name = "add_category";
    submitBtn.textContent = "Add Category";
    cancelBtn.style.display = "none";
}

</script>



<!-- GLOBAL FLOATING DROPDOWN -->
<div class="dropdown" id="globalDropdown">

<div class="dropdown-item" id="dropdownEdit">
<i class="fa-solid fa-pen"></i>
Edit
</div>

<div class="dropdown-divider"></div>

<a href="#" id="dropdownDelete" class="dropdown-item delete">
<i class="fa-solid fa-trash"></i>
Delete
</a>

</div>

<?php if($editProduct): ?>
<script>
window.addEventListener("DOMContentLoaded", function(){

    openEditModal(
        <?= json_encode($editProduct['id']) ?>,
        <?= json_encode($editProduct['name']) ?>,
        <?= json_encode($editProduct['description']) ?>,
        <?= json_encode($editProduct['price']) ?>,
        <?= json_encode($editProduct['stock']) ?>,
        <?= json_encode($editProduct['category_id']) ?>,
        <?= json_encode($editProduct['expiration_date']) ?>,
        <?= json_encode($editProductVariants) ?>
    );

});
</script>
<?php endif; ?>


</body>
</html>
