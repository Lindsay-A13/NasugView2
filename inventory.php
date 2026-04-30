<?php
require_once "config/session.php";
require_once "config/db.php";

if($_SESSION['account_type'] !== "business_owner"){
    header("Location: more.php");
    exit;
}

$owner_id = $_SESSION['user_id'];

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

/* ADD SERVICE */
if(isset($_POST['add_service'])){

$name = $_POST['service_name'];
$desc = $_POST['service_description'];
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

$service_id = $stmt->insert_id;

/* SAVE MATERIALS */
if(isset($_POST['material_id'])){

for($i=0;$i<count($_POST['material_id']);$i++){

$mat = $_POST['material_id'][$i];
$qty = $_POST['material_qty'][$i];

$stmt2=$conn->prepare("
INSERT INTO service_materials
(service_id,inventory_id,quantity)
VALUES (?,?,?)
");

$stmt2->bind_param(
"iii",
$service_id,
$mat,
$qty
);

$stmt2->execute();

}

}

header("Location: inventory.php?tab=services");
exit;

}


/* UPDATE SERVICE */
if(isset($_POST['update_service'])){

$id=$_POST['service_id'];

$name=$_POST['service_name'];
$desc=$_POST['service_description'];
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

/* DELETE OLD MATERIALS */
$stmt=$conn->prepare("
DELETE FROM service_materials
WHERE service_id=?
");

$stmt->bind_param("i",$id);
$stmt->execute();

/* INSERT NEW MATERIALS */
if(isset($_POST['material_id'])){

for($i=0;$i<count($_POST['material_id']);$i++){

$mat=$_POST['material_id'][$i];
$qty=$_POST['material_qty'][$i];

$stmt=$conn->prepare("
INSERT INTO service_materials
(service_id,inventory_id,quantity)
VALUES (?,?,?)
");

$stmt->bind_param("iii",$id,$mat,$qty);
$stmt->execute();

}

}

header("Location: inventory.php?tab=services");
exit;

}




/* ADD INVENTORY */
if(isset($_POST['add_inventory'])){

    $name = $_POST['name'];
    $desc = $_POST['description'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    $category = $_POST['category_id'];
    $expiration = $_POST['expiration_date'] ?: NULL;

    $image_name = null;

    if(!empty($_FILES['image']['name'])){

        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $image_name = time().rand().".".$ext;

        move_uploaded_file(
            $_FILES['image']['tmp_name'],
            "uploads/product/".$image_name
        );

    }

    $stmt = $conn->prepare("
        INSERT INTO inventory
        (owner_id,name,description,price,stock,category_id,expiration_date,image)
        VALUES (?,?,?,?,?,?,?,?)
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

    header("Location: inventory.php?tab=list");
    exit;
}



/* UPDATE INVENTORY */
if(isset($_POST['update_inventory'])){

    $id = $_POST['id'];
    $name = $_POST['name'];
    $desc = $_POST['description'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    $category = $_POST['category_id'];
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
            unlink("uploads/product/".$old['image']);
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
        SET name=?, description=?, price=?, stock=?, category_id=?, expiration_date=?, image=?
        WHERE id=? AND owner_id=?
    ");

    $stmt->bind_param(
        "ssdiissii",
        $name,
        $desc,
        $price,
        $stock,
        $category,
        $expiration,
        $image_name,
        $id,
        $owner_id
    );

    $stmt->execute();

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
        unlink("uploads/product/".$img['image']);
    }

    $stmt=$conn->prepare("
        DELETE FROM inventory
        WHERE id=? AND owner_id=?
    ");

    $stmt->bind_param("ii",$id,$owner_id);
    $stmt->execute();

    header("Location: inventory.php?tab=list");
    exit;
}

/* UPDATE INVENTORY */
if(isset($_POST['update_inventory'])){

    $id = $_POST['id'];
    $name = $_POST['name'];
    $desc = $_POST['description'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    $category = $_POST['category_id'];
    $expiration = $_POST['expiration_date'] ?: NULL;

    /* GET OLD IMAGE */
    $stmt = $conn->prepare("
        SELECT image FROM inventory
        WHERE id=? AND owner_id=?
    ");
    $stmt->bind_param("ii",$id,$owner_id);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();

    $image_name = $old['image'];

    /* IF NEW IMAGE */
    if(!empty($_FILES['image']['name'])){

        if($old['image']){
            unlink("uploads/product/".$old['image']);
        }

        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $image_name = time().rand().".".$ext;

        move_uploaded_file(
            $_FILES['image']['tmp_name'],
            "uploads/product/".$image_name
        );
    }

    /* UPDATE QUERY */
    $stmt = $conn->prepare("
        UPDATE inventory
        SET name=?, description=?, price=?, stock=?, category_id=?, expiration_date=?, image=?, type=?
        WHERE id=? AND owner_id=?
    ");

    $stmt->bind_param(
        "ssdiisssii",
        $name,
        $desc,
        $price,
        $stock,
        $category,
        $expiration,
        $image_name,
        $type,
        $id,
        $owner_id
    );

    $stmt->execute();

    header("Location: inventory.php?tab=list");
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
ORDER BY i.created_at DESC
");

$inv_stmt->bind_param("i",$owner_id);
$inv_stmt->execute();

$inventory=$inv_stmt->get_result();

/* LOAD INVENTORY FOR MATERIAL SELECT */
$inv_material_stmt=$conn->prepare("
SELECT id,name FROM inventory
WHERE owner_id=?
ORDER BY name ASC
");

$inv_material_stmt->bind_param("i",$owner_id);
$inv_material_stmt->execute();

$inventory_materials=$inv_material_stmt->get_result();

/* LOAD SERVICES */
$svc_stmt = $conn->prepare("
SELECT 
s.*,
GROUP_CONCAT(
CONCAT(sm.inventory_id,':',sm.quantity)
SEPARATOR '||'
) as material_data
FROM services s
LEFT JOIN service_materials sm ON sm.service_id = s.id
WHERE s.owner_id=?
GROUP BY s.id
ORDER BY s.created_at DESC
");

$svc_stmt->bind_param("i",$owner_id);
$svc_stmt->execute();

$services = $svc_stmt->get_result();

/* LOAD EXPIRATIONS */
$exp_stmt = $conn->prepare("
SELECT 
i.*,
c.name as category
FROM inventory i
LEFT JOIN inventory_categories c
ON i.category_id=c.id
WHERE 
i.owner_id=?
AND i.expiration_date IS NOT NULL
AND i.expiration_date!=''
ORDER BY i.expiration_date ASC
");

$exp_stmt->bind_param("i",$owner_id);
$exp_stmt->execute();

$expirations = $exp_stmt->get_result();


$tab=$_GET['tab'] ?? "list";
?>

<!DOCTYPE html>
<html>
<head>

<meta name="viewport" content="width=device-width,initial-scale=1">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/inventory.css">
<link rel="stylesheet" href="assets/css/responsive.css">


<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body class="inventory-page">
<?php include 'mobile_back_button.php'; ?>

<div class="header"></div>


<div class="tabs">

<a href="?tab=list" class="tab <?= $tab=='list'?'active':'' ?>">Product</a>
<a href="?tab=services" class="tab <?= $tab=='services'?'active':'' ?>">Services</a>
<a href="?tab=categories" class="tab <?= $tab=='categories'?'active':'' ?>">Categories</a>
<a href="?tab=exp" class="tab <?= $tab=='exp'?'active':'' ?>">Expirations</a>

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
<th>Date Added</th>
<th>Action</th>
</tr>
</thead>

<tbody>

<?php while($row=$inventory->fetch_assoc()): ?>

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

<div class="action-wrapper">

<div class="action-btn"
onclick='openViewModal(
<?= json_encode($row["name"]) ?>,
<?= json_encode($row["description"]) ?>,
<?= json_encode($row["category"]) ?>,
<?= json_encode($row["price"]) ?>,
<?= json_encode($row["stock"]) ?>,
<?= json_encode($row["expiration_date"]) ?>,
<?= json_encode($row["image"]) ?>
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
<?= json_encode($row["expiration_date"]) ?>
)'>
<i class="fa-solid fa-ellipsis-vertical"></i>
</div>

</div>

</td>

</tr>


<?php endwhile; ?>

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

<form method="POST" class="category-form">

<input
name="category"
placeholder="Enter new category"
required
>

<button name="add_category">
Add Category
</button>

</form>

<div class="table-card">

<table>



<tbody>

<?php
$categories->data_seek(0);
while($cat=$categories->fetch_assoc()):
?>

<tr>
<td><?= htmlspecialchars($cat['name']) ?></td>
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
<?= intval($svc['duration']) ?> mins
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
<?= json_encode($svc["image"]) ?>,
<?= json_encode($svc["material_data"]) ?>

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
<?= json_encode($svc["image"]) ?>,
<?= json_encode($svc["material_data"]) ?>
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

<?php if($tab=="exp"): ?>

<div class="table-card">

<table id="expirationTable">

<thead>
<tr>
<th>Image</th>
<th>Name</th>
<th>Category</th>
<th>Stock</th>
<th>Expiration Date</th>
<th>Status</th>
<th>Action</th>
</tr>
</thead>

<tbody>

<?php while($row=$expirations->fetch_assoc()): 

$today=date("Y-m-d");

if($row['expiration_date'] < $today){
$status="Expired";
$color="#ef4444";
}
elseif($row['expiration_date'] <= date("Y-m-d", strtotime("+7 days"))){
$status="Expiring Soon";
$color="#f59e0b";
}
else{
$status="Good";
$color="#10b981";
}

?>

<tr>

<td>
<?php if($row['image']): ?>
<img src="uploads/product/<?= $row['image'] ?>" class="product-img">
<?php endif; ?>
</td>

<td><?= htmlspecialchars($row['name']) ?></td>

<td><?= htmlspecialchars($row['category']) ?></td>

<td><?= $row['stock'] ?></td>

<td><?= date("M d, Y", strtotime($row['expiration_date'])) ?></td>

<td>
<span style="background:<?= $color ?>;color:#fff;padding:4px 10px;border-radius:8px;">
<?= $status ?>
</span>
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
<?= json_encode($row["image"]) ?>
)'>
<i class="fa-regular fa-eye"></i>
</div>
</div>
</td>

</tr>

<?php endwhile; ?>

<?php if($expirations->num_rows==0): ?>
<tr>
<td colspan="7" style="text-align:center;padding:40px;color:#888;">
No expiration items found
</td>
</tr>
<?php endif; ?>

</tbody>

</table>

</div>

<?php endif; ?>



</div>





<!-- ADD MODAL -->
<div class="modal" id="modal">

<div class="modal-content">

<form method="POST" enctype="multipart/form-data" id="inventoryForm">

<input type="hidden" name="id" id="edit_id">


<!-- PRODUCT NAME -->
<input 
name="name" 
id="edit_name" 
placeholder="Product Name" 
required
>


<!-- DESCRIPTION -->
<textarea 
name="description" 
id="description_field"
>N/A</textarea>


<!-- PRICE -->
<input 
name="price" 
id="edit_price" 
type="number" 
step="0.01" 
placeholder="Price" 
required
>


<!-- STOCK -->
<input 
name="stock" 
id="edit_stock" 
type="number" 
placeholder="Stock"
required
>


<!-- CATEGORY -->
<select 
name="category_id" 
id="edit_category" 
required
onchange="toggleExpirationField()"
>

<option value="" disabled selected>Select Category</option>

<?php
$categories->data_seek(0);
while($cat=$categories->fetch_assoc()):
?>

<option 
value="<?= $cat['id'] ?>" 
data-name="<?= strtolower($cat['name']) ?>"
>
<?= htmlspecialchars($cat['name']) ?>
</option>

<?php endwhile; ?>

</select>


<!-- EXPIRATION DATE (HIDDEN BY DEFAULT) -->
<div id="expirationWrapper" style="display:none;">
<label>Expiration Date</label>
<input type="date" name="expiration_date" id="edit_exp">
</div>

<!-- IMAGE -->
<input type="file" name="image">


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
<span id="view_service_duration">0</span> mins
</div>

<div style="margin-bottom:8px;">
<b>Materials Used:</b><br>
<div id="view_service_materials" style="margin-top:5px;"></div>
</div>

</div>
</div>

<!-- SERVICE MODAL -->
<div class="modal" id="serviceModal">

<div class="modal-content">

<form method="POST" enctype="multipart/form-data" id="serviceForm">

<input type="hidden" name="service_id" id="edit_service_id">

<input
name="service_name"
id="edit_service_name"
placeholder="Service Name"
required
>

<textarea
name="service_description"
id="edit_service_description"
placeholder="Description"
>N/A</textarea>

<input
name="service_price"
id="edit_service_price"
type="number"
step="0.01"
placeholder="Price"
required
>

<input
name="service_duration"
id="edit_service_duration"
type="number"
placeholder="Duration (minutes)"
required
>

<input
type="file"
name="service_image"
accept="image/*"
>

<label>Materials Used</label>

<div id="materialsContainer"></div>

<button type="button" onclick="addMaterialRow()" class="add-btn">
+ Add Material
</button>

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

document.getElementById("description_field").value = "N/A";

document.getElementById("edit_id").value = "";

document.getElementById("expirationWrapper").style.display="none";

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
function openViewModal(name,desc,cat,price,stock,exp,img){

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

if(img && img !== "null"){
image.src = "uploads/product/" + img;
image.style.display = "block";
}else{
image.style.display = "none";
}

}

function openEditServiceModal(id,name,desc,price,duration,image,materials){

const modal = document.getElementById("serviceModal");

modal.classList.add("show");

/* SET BASIC VALUES */
document.getElementById("edit_service_id").value=id;
document.getElementById("edit_service_name").value=name;
document.getElementById("edit_service_description").value=desc;
document.getElementById("edit_service_price").value=price;
document.getElementById("edit_service_duration").value=duration;

/* CHANGE BUTTON */
const btn = document.getElementById("serviceSubmitBtn");

btn.innerText="Update Service";
btn.name="update_service";

/* LOAD MATERIALS */
const container=document.getElementById("materialsContainer");

container.innerHTML="";

if(materials){

const list=materials.split("||");

list.forEach(function(item){

const parts=item.split(":");

const inventory_id=parts[0];
const qty=parts[1];

const row=document.createElement("div");

row.className="material-row";

row.innerHTML=`
<select name="material_id[]" required>

<option value="">Select Material</option>

<?php
$inventory_materials->data_seek(0);
while($item=$inventory_materials->fetch_assoc()){
echo '<option value="'.$item['id'].'">'.htmlspecialchars($item['name']).'</option>';
}
?>


</select>

<input
type="number"
name="material_qty[]"
placeholder="Quantity"
min="1"
required
>

<button
type="button"
onclick="removeMaterialRow(this)"
class="material-remove"
>
<i class="fa-solid fa-xmark"></i>
</button>
`;

container.appendChild(row);

row.querySelector("select").value=inventory_id;
row.querySelector("input").value=qty;

});

}

}



function openEditModal(id,name,desc,price,stock,cat,exp){

const modal = document.getElementById("modal");

modal.classList.add("show");

document.getElementById("edit_id").value = id;
document.getElementById("edit_name").value = name;
document.getElementById("description_field").value = desc;
document.getElementById("edit_price").value = price;
document.getElementById("edit_stock").value = stock;
document.getElementById("edit_category").value = cat;
document.getElementById("edit_exp").value = exp;

const btn = document.getElementById("submitBtn");

btn.innerText = "Update Inventory";
btn.name = "update_inventory";

}


let selectedRow = null;

function openDropdown(event,id,name,desc,price,stock,cat,exp){

event.stopPropagation();

selectedRow = {id,name,desc,price,stock,cat,exp};

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
selectedRow.exp
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
function addMaterialRow(){

const container=document.getElementById("materialsContainer");

const row=document.createElement("div");

row.className="material-row";

row.innerHTML=`
<select name="material_id[]" required>

<option value="">Select Material</option>

<?php
$inventory_materials->data_seek(0);
while($item=$inventory_materials->fetch_assoc()){
echo '<option value="'.$item['id'].'">'.htmlspecialchars($item['name']).'</option>';
}
?>

</select>

<input
type="number"
name="material_qty[]"
placeholder="Quantity"
min="1"
required
>

<button
type="button"
onclick="removeMaterialRow(this)"
class="material-remove"
>
<i class="fa-solid fa-xmark"></i>
</button>
`;

container.appendChild(row);

}


function removeMaterialRow(button){

const container =
document.getElementById("materialsContainer");

if(container.children.length > 1){

button.parentElement.remove();

}

}

function openViewServiceModal(name,desc,price,duration,image,materials){

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

/* MATERIALS */
const container = document.getElementById("view_service_materials");

container.innerHTML = "";

if(materials && materials !== "null"){

const list = materials.split("||");

list.forEach(function(item){

const div = document.createElement("div");

div.style.padding = "6px 10px";
div.style.marginBottom = "5px";
div.style.border = "1px solid #eee";
div.style.borderRadius = "6px";
div.style.background = "#fafafa";

div.innerText = item;

container.appendChild(div);

});

}else{

container.innerHTML = "<span style='color:#888;'>No materials</span>";

}

}


function openServiceDropdown(event,id,name,desc,price,duration,image,materials){

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
id,name,desc,price,duration,image,materials
);


dropdown.style.display="none";

};

}


function toggleExpirationField(){

const select = document.getElementById("edit_category");

if(!select.value){
document.getElementById("expirationWrapper").style.display = "none";
return;
}

const selectedOption = select.options[select.selectedIndex];

const categoryName = selectedOption.getAttribute("data-name");

const wrapper = document.getElementById("expirationWrapper");

if(
categoryName === "food" ||
categoryName === "consumable" ||
categoryName.includes("food") ||
categoryName.includes("consumable")
){
wrapper.style.display = "block";
}else{
wrapper.style.display = "none";
document.getElementById("edit_exp").value = "";
}

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
        <?= json_encode($editProduct['expiration_date']) ?>
    );

});
</script>
<?php endif; ?>


</body>
</html>
