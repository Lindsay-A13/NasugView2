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

/* LOAD BUSINESS DATA */
$stmt = $conn->prepare("
    SELECT business_name, description, phone, business_photo, address, latitude, longitude
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
    $latitude      = trim($_POST['latitude'] ?? '');
    $longitude     = trim($_POST['longitude'] ?? '');

    $latitude = $latitude !== '' ? (string) ((float) $latitude) : '';
    $longitude = $longitude !== '' ? (string) ((float) $longitude) : '';

    $cover_name = $data['business_photo'];

    if(!empty($_FILES['cover']['name'])){
        $cover_name = time()."_cover_".$_FILES['cover']['name'];
        move_uploaded_file($_FILES['cover']['tmp_name'], "uploads/business_cover/".$cover_name);
    }

    $update = $conn->prepare("
        UPDATE business_owner
        SET business_name=?, description=?, phone=?, address=?,
            latitude=NULLIF(?, ''),
            longitude=NULLIF(?, ''),
            business_photo=?
        WHERE b_id=?
    ");

    $update->bind_param(
        "sssssssi",
        $business_name,
        $description,
        $phone,
        $address,
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
                <i class="fa fa-phone"></i>
                <?php echo htmlspecialchars($data['phone'] ?? 'No phone'); ?>
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

function showFileName(input){
    if(input.files.length > 0){
        document.getElementById("fileName").value = input.files[0].name;
    }
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
</script>

<?php include 'bottom_nav.php'; ?>


</body>
</html>
