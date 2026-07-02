<?php
require_once "config/session.php";
require_once "config/db.php";

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

if($_SESSION['account_type'] !== "business_owner"){
    header("Location: more.php");
    exit;
}

$owner_id = $_SESSION['user_id'];
$profile_error = "";

/* LOAD CATEGORIES */
$categories = [];
$category_stmt = $conn->prepare("
    SELECT category_id, category_name
    FROM categories
    ORDER BY category_name ASC
");

if($category_stmt){
    $category_stmt->execute();
    $category_result = $category_stmt->get_result();

    while($category = $category_result->fetch_assoc()){
        $categories[] = $category;
    }

    $category_stmt->close();
}

/* LOAD BUSINESS DATA */
$stmt = $conn->prepare("
    SELECT
        b.business_name,
        b.description,
        b.phone,
        b.business_photo,
        b.address,
        b.latitude,
        b.longitude,
        b.category_id,
        c.category_name
    FROM business_owner b
    LEFT JOIN categories c
        ON b.category_id = c.category_id
    WHERE b.b_id = ?
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
    $category_id   = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
    $latitude      = trim($_POST['latitude'] ?? '');
    $longitude     = trim($_POST['longitude'] ?? '');

    $valid_category_ids = array_map(static function(array $category): int {
        return (int) $category['category_id'];
    }, $categories);

    if($category_id > 0 && !in_array($category_id, $valid_category_ids, true)){
        $category_id = 0;
    }

    $latitude = $latitude !== '' ? (string) ((float) $latitude) : '';
    $longitude = $longitude !== '' ? (string) ((float) $longitude) : '';

    $cover_name = $data['business_photo'];

    if(!empty($_FILES['cover']['name'])){
        $cover = $_FILES['cover'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
        $max_cover_size = 5 * 1024 * 1024;
        $image_info = null;

        if($cover['error'] !== UPLOAD_ERR_OK){
            $profile_error = "Cover photo upload failed. Please choose another image.";
        }else{
            $image_info = getimagesize($cover['tmp_name']);

            if($cover['size'] > $max_cover_size){
                $profile_error = "Cover photo must be 5MB or smaller.";
            }else if($image_info === false || !in_array($image_info['mime'], $allowed_types, true)){
                $profile_error = "Cover photo must be a JPG, PNG, or WebP image.";
            }else if($image_info[0] < 1200 || $image_info[1] < 400){
                $profile_error = "Cover photo must be at least 1200px wide and 400px tall for a clear display.";
            }else{
                $extension = strtolower(pathinfo($cover['name'], PATHINFO_EXTENSION));
                $safe_extension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';
                $cover_name = time()."_cover_".bin2hex(random_bytes(4)).".".$safe_extension;

                if(!move_uploaded_file($cover['tmp_name'], "uploads/business_cover/".$cover_name)){
                    $profile_error = "Cover photo could not be saved. Please try again.";
                    $cover_name = $data['business_photo'];
                }
            }
        }
    }

    if($profile_error === ""){
        $update = $conn->prepare("
            UPDATE business_owner
            SET business_name=?, description=?, phone=?, address=?,
                category_id = NULLIF(?, 0),
                latitude=NULLIF(?, ''),
                longitude=NULLIF(?, ''),
                business_photo=?
            WHERE b_id=?
        ");

        $update->bind_param(
            "ssssisssi",
            $business_name,
            $description,
            $phone,
            $address,
            $category_id,
            $latitude,
            $longitude,
            $cover_name,
            $owner_id
        );

        $update->execute();
        $update->close();

        header("Location: business_profile.php");
        exit;
    }
}

/* LOAD PRODUCT CATEGORIES */
$product_categories = [];
$selected_product_category = isset($_GET['product_category']) ? (int) $_GET['product_category'] : 0;

$product_category_stmt = $conn->prepare("
    SELECT id, name
    FROM inventory_categories
    WHERE owner_id = ?
    ORDER BY name ASC
");

if($product_category_stmt){
    $product_category_stmt->bind_param("i", $owner_id);
    $product_category_stmt->execute();
    $product_category_result = $product_category_stmt->get_result();

    while($category = $product_category_result->fetch_assoc()){
        $product_categories[] = $category;
    }

    $product_category_stmt->close();
}

$valid_product_category_ids = array_map(static function(array $category): int {
    return (int) $category['id'];
}, $product_categories);

if($selected_product_category > 0 && !in_array($selected_product_category, $valid_product_category_ids, true)){
    $selected_product_category = 0;
}

/* LOAD PRODUCTS */
if($selected_product_category > 0){
    $product_stmt = $conn->prepare("
        SELECT i.id, i.name, i.description, i.price, i.stock, i.image, COALESCE(c.name, 'Uncategorized') AS category_name
        FROM inventory i
        LEFT JOIN inventory_categories c
            ON i.category_id = c.id
        WHERE i.owner_id = ? AND i.type = 'product' AND i.category_id = ?
        ORDER BY i.name ASC
    ");
    $product_stmt->bind_param("ii", $owner_id, $selected_product_category);
}else{
    $product_stmt = $conn->prepare("
        SELECT i.id, i.name, i.description, i.price, i.stock, i.image, COALESCE(c.name, 'Uncategorized') AS category_name
        FROM inventory i
        LEFT JOIN inventory_categories c
            ON i.category_id = c.id
        WHERE i.owner_id = ? AND i.type = 'product'
        ORDER BY i.name ASC
    ");
    $product_stmt->bind_param("i", $owner_id);
}

$product_stmt->execute();
$products = $product_stmt->get_result();


?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Business Profile</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<link rel="stylesheet" href="assets/css/responsive.css"/>

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
.profile-map-wrap{margin-top:18px;}
.profile-map-label{
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:10px;
    color:#334155;
    font-size:14px;
    font-weight:600;
}
.profile-map{
    height:220px;
    border-radius:14px;
    overflow:hidden;
    border:1px solid #dbe3ee;
    background:#eef2f7;
}
.profile-map-empty{
    padding:18px;
    border:1px dashed #cbd5e1;
    border-radius:14px;
    background:#f8fafc;
    color:#64748b;
    font-size:14px;
}

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
.products-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    margin-bottom:20px;
}
.products-title{
    font-size:22px;
    font-weight:700;
    margin-bottom:0;
    color:#001a47;
}
.products-filter{
    display:flex;
    align-items:center;
    gap:10px;
}
.products-filter label{
    font-size:13px;
    font-weight:600;
    color:#475569;
}
.products-filter select{
    min-width:210px;
    padding:10px 12px;
    border:1px solid #d6dce6;
    border-radius:10px;
    background:#fff;
    color:#0f172a;
    font-size:14px;
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
.product-category{
    display:inline-flex;
    max-width:100%;
    margin-top:8px;
    padding:5px 8px;
    border-radius:999px;
    background:#eef2f7;
    color:#334155;
    font-size:12px;
    font-weight:600;
}

/* MODAL */
.modal-overlay{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.45);
    display:none;
    align-items:center;
    justify-content:center;
    padding:18px;
    z-index:999;
    overflow-y:auto;
}

.modal{
    background:#fff;
    width:100%;
    max-width:520px;
    border-radius:18px;
    padding:28px;
    max-height:min(92vh, 860px);
    overflow-y:auto;
    margin:auto;
}

.modal h3{
    margin:0 0 25px;
    color:#001a47;
}

.form-group{margin-bottom:20px;}

.modal input,
.modal select,
.modal textarea{
    width:100%;
    padding:14px;
    border:1px solid #ddd;
    border-radius:12px;
    font-size:15px;
}

.modal textarea{height:120px;resize:none;}

.location-panel{
    padding:16px;
    border:1px solid #e5e7eb;
    border-radius:14px;
    background:#f8fafc;
}

.location-panel h4{
    margin:0 0 8px;
    color:#001a47;
    font-size:15px;
}

.location-help{
    margin:0 0 12px;
    font-size:13px;
    color:#64748b;
    line-height:1.5;
}

.map-toolbar{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:12px;
}

.map-toolbar button{
    width:auto;
    padding:10px 14px;
    border-radius:10px;
    font-size:14px;
}

.map-toolbar .secondary-btn{
    background:#e2e8f0;
    color:#0f172a;
}

#pinMap{
    height:280px;
    border-radius:12px;
    overflow:hidden;
    border:1px solid #cbd5e1;
}

.coord-preview{
    margin-top:10px;
    font-size:13px;
    color:#475569;
}
.upload-guidelines{
    margin:10px 0 0;
    padding:12px 14px;
    border:1px solid #dbe3ee;
    border-radius:12px;
    background:#f8fafc;
    color:#475569;
    font-size:13px;
    line-height:1.5;
}
.upload-guidelines strong{
    display:block;
    margin-bottom:4px;
    color:#001a47;
}
.upload-guidelines ul{
    margin:6px 0 0 18px;
    padding:0;
}
.upload-error{
    margin:0 0 16px;
    padding:12px 14px;
    border:1px solid #fecaca;
    border-radius:12px;
    background:#fef2f2;
    color:#b42318;
    font-size:14px;
    font-weight:600;
}
.file-error{
    display:none;
    margin-top:8px;
    color:#b42318;
    font-size:13px;
    font-weight:600;
}

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

@media (max-width:768px){
    .container{padding:14px;padding-bottom:110px;}
    .content{padding:20px 16px;}
    .cover{height:220px;}
    .profile-map{height:180px;}
    .products-header{
        align-items:stretch;
        flex-direction:column;
    }
    .products-filter{
        align-items:stretch;
        flex-direction:column;
        gap:6px;
    }
    .products-filter select{
        width:100%;
        min-width:0;
    }
    .modal-overlay{
        align-items:flex-end;
        padding:10px;
    }
    .modal{
        max-width:none;
        border-radius:18px 18px 0 0;
        padding:18px 16px 22px;
        max-height:calc(100vh - 12px);
    }
    .modal h3{margin-bottom:18px;font-size:20px;}
    .modal input,
    .modal textarea{
        font-size:16px;
        padding:13px;
    }
    #pinMap{height:240px;}
    .map-toolbar button{
        flex:1 1 180px;
    }
}
</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>
<body>
<?php include 'mobile_back_button.php'; ?>

<div class="container">

    <div class="card">
<?php
$cover = "assets/images/default-cover.png";

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
                <i class="fa fa-phone"></i>
                <?php echo htmlspecialchars($data['phone'] ?? 'No phone'); ?>
            </div>

            <div class="info">
                <i class="fa fa-layer-group"></i>
                <?php echo htmlspecialchars($data['category_name'] ?? 'No category selected'); ?>
            </div>

            <div class="description">
                <?php echo htmlspecialchars($data['description'] ?? 'No description yet.'); ?>
            </div>

            <div class="profile-map-wrap">
                <div class="profile-map-label">
                    <i class="fa fa-map-pin"></i>
                    Business Pin
                </div>
                <?php if(!empty($data['latitude']) && !empty($data['longitude'])): ?>
                    <div id="profileMap" class="profile-map"></div>
                <?php else: ?>
                    <div class="profile-map-empty">No pin placed yet. Use Edit Business Information to set the business location on the map.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<!-- PRODUCTS -->
<div class="products-section">
    <div class="products-header">
        <div class="products-title">Products</div>

        <form method="GET" class="products-filter">
            <label for="productCategoryFilter">Category</label>
            <select name="product_category" id="productCategoryFilter" onchange="this.form.submit()">
                <option value="0">All Categories</option>
                <?php foreach($product_categories as $category): ?>
                    <option value="<?php echo (int) $category['id']; ?>"
                        <?php echo $selected_product_category === (int) $category['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($category['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

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

                            <div class="product-category">
                                <?php echo htmlspecialchars($row['category_name']); ?>
                            </div>
                        </div>
                    </div>

                </a>

            <?php endwhile; ?>
        <?php else: ?>
            <p><?php echo $selected_product_category > 0 ? 'No products found in this category.' : 'No products yet.'; ?></p>
        <?php endif; ?>
    </div>
</div>

</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <h3>Edit Business Information</h3>

        <form method="POST" enctype="multipart/form-data">
            <?php if($profile_error !== ""): ?>
                <div class="upload-error"><?php echo htmlspecialchars($profile_error); ?></div>
            <?php endif; ?>

            <div class="form-group">

    <label style="font-weight:600; display:block; margin-bottom:8px;">
        Change Cover Photo
    </label>

    <div class="upload-guidelines">
        <strong>Cover photo requirements for a clear image</strong>
        <ul>
            <li>Use a landscape photo, at least 1200px wide and 400px tall.</li>
            <li>Recommended size: 1600px by 600px or larger.</li>
            <li>Accepted formats: JPG, PNG, or WebP.</li>
            <li>Maximum file size: 5MB.</li>
            <li>Keep important text or logos near the center because the cover may crop on mobile.</li>
        </ul>
    </div>

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
               accept="image/jpeg,image/png,image/webp"
               style="display:none;"
               onchange="showFileName(this)">

    </div>
    <div class="file-error" id="coverFileError"></div>

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
                <select name="category_id">
                    <option value="">Select Business Category</option>
                    <?php foreach($categories as $category): ?>
                        <option value="<?php echo (int) $category['category_id']; ?>"
                            <?php echo (int) ($data['category_id'] ?? 0) === (int) $category['category_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category['category_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <div class="location-panel">
                    <h4>Business Pin</h4>
                    <p class="location-help">Click on the map to place the exact business pin. Marketplace distance will use this location.</p>

                    <div class="map-toolbar">
                        <button type="button" onclick="useCurrentLocation()">Use Current Location</button>
                        <button type="button" class="secondary-btn" onclick="clearPin()">Clear Pin</button>
                    </div>

                    <div id="pinMap"></div>
                    <div class="coord-preview" id="coordPreview">No map pin selected.</div>

                    <input type="hidden" name="latitude" id="latitudeInput"
                           value="<?php echo htmlspecialchars($data['latitude'] ?? ''); ?>">
                    <input type="hidden" name="longitude" id="longitudeInput"
                           value="<?php echo htmlspecialchars($data['longitude'] ?? ''); ?>">
                </div>
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
let pinMap;
let pinMarker = null;
let profileMap = null;
const latitudeInput = document.getElementById("latitudeInput");
const longitudeInput = document.getElementById("longitudeInput");
const coordPreview = document.getElementById("coordPreview");

function openModal(){
    document.getElementById("editModal").style.display = "flex";
    setTimeout(() => {
        initPinMap();
        if(pinMap){
            pinMap.invalidateSize();
        }
    }, 50);
}

window.onclick = function(e){
    if(e.target.id === "editModal"){
        document.getElementById("editModal").style.display = "none";
    }
}

function setCoverFileError(message){
    const errorEl = document.getElementById("coverFileError");
    errorEl.textContent = message;
    errorEl.style.display = message ? "block" : "none";
}

function resetCoverInput(input){
    input.value = "";
    document.getElementById("fileName").value = "";
}

function showFileName(input){
    setCoverFileError("");

    if(input.files.length === 0){
        document.getElementById("fileName").value = "";
        return;
    }

    const file = input.files[0];
    const allowedTypes = ["image/jpeg", "image/png", "image/webp"];
    const maxSize = 5 * 1024 * 1024;

    if(!allowedTypes.includes(file.type)){
        setCoverFileError("Choose a JPG, PNG, or WebP image.");
        resetCoverInput(input);
        return;
    }

    if(file.size > maxSize){
        setCoverFileError("Cover photo must be 5MB or smaller.");
        resetCoverInput(input);
        return;
    }

    const image = new Image();
    const objectUrl = URL.createObjectURL(file);

    image.onload = function(){
        URL.revokeObjectURL(objectUrl);

        if(image.width < 1200 || image.height < 400){
            setCoverFileError("Use an image at least 1200px wide and 400px tall.");
            resetCoverInput(input);
            return;
        }

        document.getElementById("fileName").value = file.name;
    };

    image.onerror = function(){
        URL.revokeObjectURL(objectUrl);
        setCoverFileError("This file could not be read as an image.");
        resetCoverInput(input);
    };

    image.src = objectUrl;
}

function updateCoordPreview(lat, lng){
    if(lat === null || lng === null){
        coordPreview.textContent = "No map pin selected.";
        return;
    }

    coordPreview.textContent = "Selected pin: " + Number(lat).toFixed(6) + ", " + Number(lng).toFixed(6);
}

function setPin(lat, lng, recenter = true){
    latitudeInput.value = Number(lat).toFixed(7);
    longitudeInput.value = Number(lng).toFixed(7);

    if(pinMarker){
        pinMarker.setLatLng([lat, lng]);
    }else{
        pinMarker = L.marker([lat, lng], {draggable:true}).addTo(pinMap);
        pinMarker.on("dragend", function(e){
            const pos = e.target.getLatLng();
            setPin(pos.lat, pos.lng, false);
        });
    }

    if(recenter){
        pinMap.setView([lat, lng], 16);
    }

    updateCoordPreview(lat, lng);
}

function clearPin(){
    latitudeInput.value = "";
    longitudeInput.value = "";

    if(pinMarker){
        pinMap.removeLayer(pinMarker);
        pinMarker = null;
    }

    updateCoordPreview(null, null);
}

function useCurrentLocation(){
    if(!navigator.geolocation){
        alert("Geolocation is not supported on this device.");
        return;
    }

    navigator.geolocation.getCurrentPosition(position => {
        setPin(position.coords.latitude, position.coords.longitude);
    }, () => {
        alert("Unable to get your current location.");
    }, {
        enableHighAccuracy: true,
        timeout: 10000,
        maximumAge: 300000
    });
}

function initPinMap(){
    if(pinMap){
        return;
    }

    const defaultLat = parseFloat(latitudeInput.value || "14.0667");
    const defaultLng = parseFloat(longitudeInput.value || "120.6333");
    const hasSavedPin = latitudeInput.value !== "" && longitudeInput.value !== "";

    pinMap = L.map("pinMap");

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: "&copy; OpenStreetMap"
    }).addTo(pinMap);

    pinMap.setView([defaultLat, defaultLng], hasSavedPin ? 16 : 13);

    pinMap.on("click", function(e){
        setPin(e.latlng.lat, e.latlng.lng, false);
    });

    if(hasSavedPin){
        setPin(defaultLat, defaultLng, false);
    }else{
        updateCoordPreview(null, null);
    }
}
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
function initProfileMap(){
    const mapEl = document.getElementById("profileMap");

    if(!mapEl || profileMap){
        return;
    }

    const lat = parseFloat("<?php echo htmlspecialchars((string) ($data['latitude'] ?? '')); ?>");
    const lng = parseFloat("<?php echo htmlspecialchars((string) ($data['longitude'] ?? '')); ?>");

    if(!Number.isFinite(lat) || !Number.isFinite(lng)){
        return;
    }

    profileMap = L.map("profileMap", {
        zoomControl: false,
        dragging: true,
        scrollWheelZoom: false
    });

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: "&copy; OpenStreetMap"
    }).addTo(profileMap);

    profileMap.setView([lat, lng], 16);
    L.marker([lat, lng]).addTo(profileMap);

    setTimeout(() => {
        profileMap.invalidateSize();
    }, 50);
}

initProfileMap();

<?php if($profile_error !== ""): ?>
openModal();
<?php endif; ?>
</script>

<?php include 'bottom_nav.php'; ?>


</body>
</html>
