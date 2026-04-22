<?php
require_once "config/session.php";
require_once "config/db.php";

if($_SESSION['account_type'] !== "business_owner"){
    header("Location: more.php");
    exit;
}

$owner_id = $_SESSION['user_id'];

/* LOAD BUSINESS DATA */
$stmt = $conn->prepare("
    SELECT business_name, description, phone, business_photo, address
    FROM business_owner
    WHERE b_id = ?
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();
$stmt->close();

/* UPDATE PROFILE */
if(isset($_POST['save'])){

    $business_name = trim($_POST['business_name']);
    $description   = trim($_POST['description']);
    $phone         = trim($_POST['phone']);
    $address       = trim($_POST['address']);

    $cover_name = $data['business_photo'];

    if(!empty($_FILES['cover']['name'])){
        $cover_name = time()."_cover_".$_FILES['cover']['name'];
        move_uploaded_file($_FILES['cover']['tmp_name'], "uploads/business_cover/".$cover_name);
    }

    $update = $conn->prepare("
        UPDATE business_owner
        SET business_name=?, description=?, phone=?, address=?, business_photo=?
        WHERE b_id=?
    ");

    $update->bind_param(
        "sssssi",
        $business_name,
        $description,
        $phone,
        $address,
        $cover_name,
        $owner_id
    );

    $update->execute();
    $update->close();

    header("Location: business_profile.php");
    exit;
}

/* LOAD PRODUCTS */
$product_stmt = $conn->prepare("
    SELECT id, name, description, price, stock, image
    FROM inventory
    WHERE owner_id = ? AND type = 'product'
    ORDER BY created_at DESC
");
$product_stmt->bind_param("i", $owner_id);
$product_stmt->execute();
$products = $product_stmt->get_result();


$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Business Profile</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
*{box-sizing:border-box;}
body{margin:0;font-family:Arial;background:#ffff;}

.container{max-width:1100px;margin:auto;padding:20px; padding-bottom:110px;}

.card{
    background:#fff;
    border-radius:16px;
    overflow:hidden;
    box-shadow:0 5px 15px rgba(0,0,0,0.05);
    position:relative;
}

.cover{
    width:100%;
    height:280px;
    object-fit:cover;
}

.content{padding:30px;}

.business-name{
    font-size:24px;
    font-weight:bold;
    color:#001a47;
    margin-bottom:15px;
}

.info{margin:10px 0;color:#444;}
.description{margin-top:15px;color:#555;line-height:1.6;}

.edit-btn{
    position:absolute;
    top:20px;
    right:20px;
    background:#001a47;
    width:44px;
    height:44px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    box-shadow:0 3px 10px rgba(0,0,0,0.15);
}

.edit-btn i{
    color:#ffff;
    font-size:18px;
}

/* PRODUCTS */
.products-section{margin-top:40px;}
.products-title{
    font-size:22px;
    font-weight:700;
    margin-bottom:20px;
    color:#001a47;
}
.products-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
    gap:20px;
}
.product-card{
    background:#fff;
    border-radius:14px;
    overflow:hidden;
    box-shadow:0 5px 12px rgba(0,0,0,0.06);
}
.product-img{
    width:100%;
    height:180px;
    object-fit:cover;
}
.product-content{padding:15px;}
.product-name{font-weight:600;margin-bottom:8px;}
.product-price{color:#001a47;font-weight:700;margin-bottom:5px;}
.product-stock{font-size:13px;color:#666;}

/* MODAL */
.modal-overlay{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.45);
    display:none;
    align-items:center;
    justify-content:center;
    padding:25px;
    z-index:999;
}

.modal{
    background:#fff;
    width:100%;
    max-width:520px;
    border-radius:18px;
    padding:35px;
}

.modal h3{
    margin:0 0 25px;
    color:#001a47;
}

.form-group{margin-bottom:20px;}

.modal input,
.modal textarea{
    width:100%;
    padding:14px;
    border:1px solid #ddd;
    border-radius:12px;
    font-size:15px;
}

.modal textarea{height:120px;resize:none;}

.modal button{
    width:100%;
    padding:16px;
    border:none;
    border-radius:14px;
    background:#001a47;
    color:#fff;
    font-weight:600;
    cursor:pointer;
}
</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>
<body>
<?php include 'mobile_back_button.php'; ?>

<div class="container">

    <div class="card">
<?php
$cover = "assets/images/logo.png";

if(!empty($data['business_photo'])){
    $path = "uploads/business_cover/".$data['business_photo'];

    if(file_exists($path)){
        $cover = $path;
    }
}
?>

<img src="<?php echo $cover; ?>" class="cover">
        <div class="edit-btn" onclick="openModal()">
            <i class="fa fa-pen"></i>
        </div>

        <div class="content">
            <div class="business-name">
                <?php echo htmlspecialchars($data['business_name']); ?>
            </div>

            <div class="info">
                <i class="fa fa-location-dot"></i>
                <?php echo htmlspecialchars($data['address'] ?? 'No address'); ?>
            </div>

            <div class="info">
                <i class="fa fa-phone"></i>
                <?php echo htmlspecialchars($data['phone'] ?? 'No phone'); ?>
            </div>

            <div class="description">
                <?php echo htmlspecialchars($data['description'] ?? 'No description yet.'); ?>
            </div>
        </div>
    </div>

    <!-- PRODUCTS -->
<div class="products-section">
    <div class="products-title">Products</div>

    <div class="products-grid">
        <?php if($products->num_rows > 0): ?>
            <?php while($row = $products->fetch_assoc()): ?>

                <a href="inventory.php?edit_id=<?php echo $row['id']; ?>&tab=list"
                   style="text-decoration:none; color:inherit;">

                    <div class="product-card">
                        <img src="uploads/product/<?php echo $row['image'] ?: 'default_product.jpg'; ?>" class="product-img">

                        <div class="product-content">
                            <div class="product-name">
                                <?php echo htmlspecialchars($row['name']); ?>
                            </div>

                            <div class="product-price">
                                ₱<?php echo number_format($row['price'],2); ?>
                            </div>

                            <div class="product-stock">
                                Stock: <?php echo $row['stock']; ?>
                            </div>
                        </div>
                    </div>

                </a>

            <?php endwhile; ?>
        <?php else: ?>
            <p>No products yet.</p>
        <?php endif; ?>
    </div>
</div>

</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <h3>Edit Business Information</h3>

        <form method="POST" enctype="multipart/form-data">

            <div class="form-group">

    <label style="font-weight:600; display:block; margin-bottom:8px;">
        Change Cover Photo
    </label>

    <div style="position:relative;">

        <input type="text"
               id="fileName"
               placeholder="No file chosen"
               readonly
               style="
               width:100%;
               padding:14px;
               border:1px solid #ddd;
               border-radius:12px;
               font-size:15px;
               padding-right:50px;
               ">

        <label for="coverInput"
               style="
               position:absolute;
               right:10px;
               top:50%;
               transform:translateY(-50%);
               cursor:pointer;
               color:#001a47;
               font-size:18px;
               ">
            <i class="fa fa-image"></i>
        </label>

        <input type="file"
               name="cover"
               id="coverInput"
               style="display:none;"
               onchange="showFileName(this)">

    </div>

</div>

            <div class="form-group">
                <input type="text" name="business_name"
                       value="<?php echo htmlspecialchars($data['business_name']); ?>"
                       required>
            </div>

            <div class="form-group">
                <input type="text" name="address"
                       value="<?php echo htmlspecialchars($data['address'] ?? ''); ?>"
                       placeholder="Address">
            </div>

            <div class="form-group">
                <input type="text" name="phone"
                       value="<?php echo htmlspecialchars($data['phone'] ?? ''); ?>"
                       placeholder="Phone">
            </div>

            <div class="form-group">
                <textarea name="description"
                          placeholder="Description"><?php echo htmlspecialchars($data['description'] ?? ''); ?></textarea>
            </div>

            <button type="submit" name="save">Save Changes</button>
        </form>
    </div>
</div>

<script>
function openModal(){
    document.getElementById("editModal").style.display = "flex";
}

window.onclick = function(e){
    if(e.target.id === "editModal"){
        document.getElementById("editModal").style.display = "none";
    }
}

function showFileName(input){
    if(input.files.length > 0){
        document.getElementById("fileName").value = input.files[0].name;
    }
}
</script>

<?php include 'bottom_nav.php'; ?>


</body>
</html>
