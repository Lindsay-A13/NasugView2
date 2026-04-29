<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/notifications_helper.php";

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$business_id = isset($_GET['business_id']) ? intval($_GET['business_id']) : 0;
$type = isset($_GET['type']) ? $_GET['type'] : 'yes';
$isSuggestion = ($type === 'no');

$stmt = $conn->prepare("SELECT business_name,address FROM business_owner WHERE b_id=?");
$stmt->bind_param("i",$business_id);
$stmt->execute();
$result = $stmt->get_result();
$business = $result->fetch_assoc();

if(!$business){
    die("Business not found.");
}

$name = $business['business_name'];
$address = $business['address'];

if($_SERVER['REQUEST_METHOD'] === 'POST'){

$rating = null;

if(!$isSuggestion){
$rating = isset($_POST['experience_rating']) ? intval($_POST['experience_rating']) : null;
}

$comment = trim($_POST['comment']);
$anonymous = isset($_POST['anonymous']) ? 1 : 0;
$user_id = $_SESSION['user_id'];

if(empty($comment)){
die("Comment required");
}

$imagePaths = [];
$maxImages = 4;

$uploadDir = __DIR__."/uploads/reviews/";

if(!is_dir($uploadDir)){
mkdir($uploadDir,0777,true);
}

if(isset($_FILES['images']) && !empty($_FILES['images']['name'][0])){

$totalFiles = count($_FILES['images']['name']);
$totalFiles = min($totalFiles,$maxImages);

for($i=0;$i<$totalFiles;$i++){

if($_FILES['images']['error'][$i] === 0){

$ext = strtolower(pathinfo($_FILES['images']['name'][$i],PATHINFO_EXTENSION));
$allowed = ['jpg','jpeg','png','webp'];

if(in_array($ext,$allowed)){

$fileName = time()."_".uniqid().".".$ext;
$target = $uploadDir.$fileName;

if(move_uploaded_file($_FILES['images']['tmp_name'][$i],$target)){
$imagePaths[] = $fileName;
}

}

}

}

}

$imagesString = !empty($imagePaths) ? implode(',',$imagePaths) : null;

$stmt = $conn->prepare("
INSERT INTO reviews
(business_id,user_id,experience_rating,comment,images,is_anonymous)
VALUES (?,?,?,?,?,?)
");

$stmt->bind_param(
"iiissi",
$business_id,
$user_id,
$rating,
$comment,
$imagesString,
$anonymous
);

if($stmt->execute()){
insertNotification(
    $conn,
    $business_id,
    "business_owner",
    "New Review",
    (((int) $anonymous === 1) ? "An anonymous customer" : notificationDisplayName($fname ?? '', $lname ?? '', $_SESSION['username'] ?? 'A customer'))
    . ' left a new review: "'
    . notificationSnippet($comment)
    . '"'
);
header("Location: businessdetails.php?id=".$business_id);
exit;
}else{
die("Insert error: ".$stmt->error);
}

}

?>

<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Submit Review</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="assets/css/responsive.css"/>

<style>

body{
font-family:Segoe UI;
margin:0;
background:#fff;
}

.container{
padding:20px;
padding-bottom:120px;
}

.title{
font-size:20px;
font-weight:600;
color:#001a47;
}

.location{
color:#666;
margin-bottom:20px;
}

.stars{
display:flex;
gap:8px;
margin-bottom:15px;
}

.star{
font-size:28px;
color:#ccc;
cursor:pointer;
}

.star.active{
color:#001a47;
}

textarea{
width:100%;
min-height:140px;
padding:16px;
border-radius:16px;
border:1px solid #d1d5db;
font-size:15px;
resize:none;
margin-top:10px;
}

.image-preview{
margin-top:15px;
display:flex;
gap:10px;
flex-wrap:wrap;
}

.image-preview img{
width:90px;
height:90px;
object-fit:cover;
border-radius:10px;
}

.file-label{
display:inline-flex;
align-items:center;
gap:8px;
padding:10px 18px;
border-radius:25px;
background:#fff;
border:2px solid #001a47;
color:#001a47;
font-weight:600;
cursor:pointer;
margin-top:15px;
}

.btn{
padding:10px 22px;
background:#001a47;
color:#fff;
border:none;
border-radius:25px;
cursor:pointer;
font-weight:600;
margin-left:10px;
}

</style>

<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="container">

<div class="title">
<?= $isSuggestion ? "We're sorry to hear that about " : "Do you recommend " ?>
<?= htmlspecialchars($name) ?>?
</div>

<div class="location">📍 <?= htmlspecialchars($address) ?></div>

<form method="POST" enctype="multipart/form-data">

<?php if(!$isSuggestion): ?>

<input type="hidden" name="experience_rating" id="experience_rating">

<div class="stars">
<i class="fa fa-star star"></i>
<i class="fa fa-star star"></i>
<i class="fa fa-star star"></i>
<i class="fa fa-star star"></i>
<i class="fa fa-star star"></i>
</div>

<?php endif; ?>

<div style="margin:10px 0;">
<input type="checkbox" name="anonymous"> Post as Anonymous
</div>

<textarea name="comment" required placeholder="Share your experience..."></textarea>

<div class="image-preview" id="preview"></div>

<label class="file-label">
<i class="fa fa-camera"></i> Add Photos
<input type="file" name="images[]" id="images" accept="image/*" multiple hidden>
</label>

<button class="btn" type="submit">Share</button>

</form>

</div>

<script>

const stars=document.querySelectorAll(".star");
const ratingInput=document.getElementById("experience_rating");

stars.forEach((star,index)=>{
star.onclick=()=>{
ratingInput.value=index+1;
stars.forEach((s,i)=>s.classList.toggle("active",i<=index));
};
});

const input=document.getElementById("images");
const preview=document.getElementById("preview");

input.addEventListener("change",function(){

preview.innerHTML="";

const files=this.files;

if(files.length>4){
alert("Maximum 4 images");
this.value="";
return;
}

for(let i=0;i<files.length;i++){

const reader=new FileReader();

reader.onload=function(e){

const img=document.createElement("img");
img.src=e.target.result;
preview.appendChild(img);

};

reader.readAsDataURL(files[i]);

}

});

</script>

<?php include 'bottom_nav.php'; ?>

</body>
</html>
