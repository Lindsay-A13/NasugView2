<?php
require_once "config/session.php";
require_once "config/db.php";

/* LOAD USER INFO */

if ($account_type === "consumer") {

    $stmt = $conn->prepare("
        SELECT 
            username,
            fname,
            lname,
            bio,
            followers,
            following,
            profile_picture,
            cover_photo
        FROM consumers
        WHERE c_id = ?
    ");

    if(!$stmt){
        die("Consumers Query Error: " . $conn->error);
    }

} else {

    $stmt = $conn->prepare("
        SELECT 
            username,
            fname,
            lname,
            bio,
            followers,
            following,
            profile_picture,
            cover_photo
        FROM business_owner
        WHERE b_id = ?
    ");

    if(!$stmt){
        die("Business Owner Query Error: " . $conn->error);
    }
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows === 0){
    die("User not found");
}

$user = $result->fetch_assoc();
$stmt->close();

/* VARIABLES */

$username = $user['username'];
$fullname = $user['fname'] . " " . $user['lname'];
$bio = $user['bio'] ?: "No bio yet.";
$followers = $user['followers'];
$following = $user['following'];

$avatar = !empty($user['profile_picture'])
    ? "uploads/profile/".$user['profile_picture']
    : "assets/images/icon.png";

$cover = !empty($user['cover_photo'])
    ? "uploads/cover/".$user['cover_photo']
    : "assets/images/default-cover.png";

/* LOAD POSTS */

if ($account_type === "consumers") {

    $post_stmt = $conn->prepare("
        SELECT id, caption, image, created_at
        FROM post
        WHERE consumer_id = ?
        ORDER BY created_at DESC
    ");

} else {

    $post_stmt = $conn->prepare("
        SELECT id, caption, image, created_at
        FROM post
        WHERE business_id = ?
        ORDER BY created_at DESC
    ");
}

$post_stmt->bind_param("i", $user_id);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profile</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="assets/css/profile.css">
<link rel="stylesheet" href="assets/css/responsive.css">
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="header">
<div class="header-inner">
<img src="assets/images/logo.png">
</div>
</div>

<!-- COVER -->
<div class="cover-container" onclick="openModal('cover')">
<img src="<?= $cover ?>" class="cover-img">
</div>

<div class="profile-wrap">

<div class="avatar-row">
<img src="<?= $avatar ?>" class="avatar" onclick="openModal('avatar')">
</div>

<div class="info">
<h2><?= htmlspecialchars($fullname) ?></h2>
<div class="bio">@<?= htmlspecialchars($username) ?></div>

<div style="margin-top:8px;font-weight:600;color:#001a47;">
<?= $followers ?> Followers &nbsp;&nbsp;
<?= $following ?> Following
</div>

<div style="margin-top:6px;color:#64748b;">
<?= htmlspecialchars($bio) ?>
</div>
</div>

<!-- TABS -->
<div class="tabs">
<div class="tab active" data-tab="posts">Posts</div>
<div class="tab" data-tab="media">Media</div>
</div>

<!-- POSTS -->
<div id="posts" class="tab-content active">
<div class="grid">
<?php
$post_stmt->execute();
$post_result = $post_stmt->get_result();
while($post = $post_result->fetch_assoc()):
?>
<div class="card">
<?php if(!empty($post['image'])): ?>
<img src="uploads/<?= htmlspecialchars($post['image']) ?>">
<?php endif; ?>
<div class="cbody">
<h4><?= htmlspecialchars($post['caption']) ?></h4>
<p><?= htmlspecialchars($post['created_at']) ?></p>
</div>
</div>
<?php endwhile; ?>
</div>
</div>

<!-- MEDIA -->
<div id="media" class="tab-content">
<div class="grid">
<?php
$post_stmt->execute();
$post_result = $post_stmt->get_result();
while($post = $post_result->fetch_assoc()):
if(!empty($post['image'])):
?>
<div class="card">
<img src="uploads/<?= htmlspecialchars($post['image']) ?>">
</div>
<?php endif; endwhile; ?>
</div>
</div>

</div>

<!-- IMAGE VIEW MODAL -->
<div class="image-view-overlay" id="imageViewOverlay">
<span class="close-view" onclick="closeImageView()">&times;</span>
<img id="imageViewContent">
</div>

<!-- CHANGE MODAL -->
<div class="modal-overlay" onclick="closeModal()"></div>

<div class="modal" id="modal">
<div class="modal-option" onclick="viewImage()">View Photo</div>
<div class="modal-option" onclick="changePhoto()">Change Photo</div>
<div class="modal-option" onclick="closeModal()">Cancel</div>
</div>

<!-- PREVIEW CONFIRM MODAL -->
<div id="previewOverlay" style="
position:fixed;
top:0;left:0;right:0;bottom:0;
background:rgba(0,0,0,0.85);
display:none;
align-items:center;
justify-content:center;
z-index:3000;
">
  <div style="
  background:#fff;
  padding:20px;
  border-radius:16px;
  max-width:400px;
  width:90%;
  text-align:center;
  ">
    <h3 style="margin-top:0;color:#001a47;">Preview Photo</h3>
    <img id="previewImage" style="
    width:100%;
    max-height:300px;
    object-fit:cover;
    border-radius:12px;
    margin-bottom:15px;
    ">
    <div style="display:flex;gap:10px;justify-content:center;">
      <button onclick="confirmUpload()" style="
      padding:10px 18px;
      background:#001a47;
      color:#fff;
      border:none;
      border-radius:8px;
      cursor:pointer;
      font-weight:600;">
        Confirm Upload
      </button>
      <button onclick="cancelPreview()" style="
      padding:10px 18px;
      background:#ccc;
      border:none;
      border-radius:8px;
      cursor:pointer;
      font-weight:600;">
        Cancel
      </button>
    </div>
  </div>
</div>

<form id="uploadForm" method="POST" enctype="multipart/form-data" action="upload_photo.php">
<input type="file" name="photo" id="fileInput" hidden>
<input type="hidden" name="type" id="photoType">
</form>

<script>

let currentType="";
let selectedFile=null;

function openModal(type){
currentType=type;
document.getElementById("modal").classList.add("active");
document.querySelector(".modal-overlay").classList.add("active");
}

function closeModal(){
document.getElementById("modal").classList.remove("active");
document.querySelector(".modal-overlay").classList.remove("active");
}

function changePhoto(){
document.getElementById("photoType").value=currentType;
document.getElementById("fileInput").click();
}

document.getElementById("fileInput").onchange=function(e){
const file=e.target.files[0];
if(!file) return;

selectedFile=file;

const reader=new FileReader();
reader.onload=function(event){
document.getElementById("previewImage").src=event.target.result;
document.getElementById("previewOverlay").style.display="flex";
};
reader.readAsDataURL(file);
};

function confirmUpload(){
document.getElementById("previewOverlay").style.display="none";
document.getElementById("uploadForm").submit();
}

function cancelPreview(){
document.getElementById("previewOverlay").style.display="none";
document.getElementById("fileInput").value="";
}

function viewImage(){
let img=document.getElementById("imageViewContent");
if(currentType==="avatar") img.src="<?= $avatar ?>";
if(currentType==="cover") img.src="<?= $cover ?>";
document.getElementById("imageViewOverlay").classList.add("active");
closeModal();
}

function closeImageView(){
document.getElementById("imageViewOverlay").classList.remove("active");
}

/* TABS */

const tabs=document.querySelectorAll(".tab");
const contents=document.querySelectorAll(".tab-content");

tabs.forEach(tab=>{
tab.onclick=()=>{
tabs.forEach(t=>t.classList.remove("active"));
contents.forEach(c=>c.classList.remove("active"));
tab.classList.add("active");
document.getElementById(tab.dataset.tab).classList.add("active");
};
});

</script>

<?php include "bottom_nav.php"; ?>

</body>
</html>
