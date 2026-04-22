<?php
require_once "config/session.php";
require_once "config/db.php";

$user_id = $_SESSION['user_id'];
$account_type = $_SESSION['account_type'];

$success = "";
$error = "";

/* LOAD USER INFO */

if($account_type === "consumer"){
    $stmt = $conn->prepare("
        SELECT username,fname,lname,email
        FROM consumers
        WHERE c_id=?
    ");
}else{
    $stmt = $conn->prepare("
        SELECT username,fname,lname,email
        FROM business_owner
        WHERE b_id=?
    ");
}

$stmt->bind_param("i",$user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();


/* UPDATE INFO */

if(isset($_POST['update_info'])){

$username = trim($_POST['username']);
$fname = trim($_POST['fname']);
$lname = trim($_POST['lname']);
$email = trim($_POST['email']);

if($account_type === "consumer"){
$stmt = $conn->prepare("
UPDATE consumers
SET username=?,fname=?,lname=?,email=?
WHERE c_id=?
");
}else{
$stmt = $conn->prepare("
UPDATE business_owner
SET username=?,fname=?,lname=?,email=?
WHERE b_id=?
");
}

$stmt->bind_param("ssssi",$username,$fname,$lname,$email,$user_id);

if($stmt->execute()){
$success="Information updated successfully.";
}else{
$error="Update failed.";
}

}


/* BUSINESS PERMIT */

if(isset($_POST['upload_permit']) && $account_type==="business_owner"){

if(isset($_FILES['permit']) && $_FILES['permit']['error']==0){

$folder="uploads/permits/";

if(!is_dir($folder)){
mkdir($folder,0777,true);
}

$file=time()."_".basename($_FILES['permit']['name']);
$path=$folder.$file;

if(move_uploaded_file($_FILES['permit']['tmp_name'],$path)){

$stmt=$conn->prepare("
UPDATE business_owner
SET business_permit=?
WHERE b_id=?
");

$stmt->bind_param("si",$path,$user_id);
$stmt->execute();

$success="Business permit uploaded.";

}else{
$error="Upload failed.";
}

}

}

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings</title>

<style>
:root{
--bg:#ffffff;
--bg-accent:none;
--surface:#ffffff;
--surface-muted:#f8faff;
--surface-soft:#eef2ff;
--text:#172033;
--muted:#64748b;
--border:#d9e2f1;
--shadow:0 18px 40px rgba(15, 23, 42, 0.08);
--primary:#4f46e5;
--primary-dark:#4338ca;
--success-bg:#ecfdf5;
--success-text:#166534;
--error-bg:#fef2f2;
--error-text:#b91c1c;
}

*{
box-sizing:border-box;
}

html,body{
margin:0;
padding:0;
min-height:100%;
}

body{
font-family:"Segoe UI",Arial,sans-serif;
background:var(--bg);
background-image:var(--bg-accent);
color:var(--text);
transition:background 0.25s ease,color 0.25s ease;
padding-bottom:110px;
}

.container{
max-width:1150px;
margin:0 auto;
padding:32px 20px 0;
}

.hero{
display:flex;
justify-content:space-between;
align-items:flex-end;
gap:20px;
padding:28px;
margin-bottom:24px;
background:linear-gradient(135deg, rgba(79, 70, 229, 0.96), rgba(14, 165, 233, 0.92));
color:#fff;
border-radius:28px;
box-shadow:var(--shadow);
}

.hero-title{
margin:0 0 8px;
font-size:32px;
font-weight:700;
}

.hero-subtitle{
margin:0;
max-width:580px;
font-size:15px;
line-height:1.6;
color:rgba(255,255,255,0.84);
}

.hero-badge{
padding:10px 16px;
border-radius:999px;
background:rgba(255,255,255,0.16);
border:1px solid rgba(255,255,255,0.18);
font-size:13px;
font-weight:600;
white-space:nowrap;
}

.flash{
padding:14px 18px;
border-radius:16px;
margin-bottom:18px;
font-size:14px;
font-weight:600;
}

.success{
background:var(--success-bg);
color:var(--success-text);
border:1px solid rgba(22, 101, 52, 0.16);
}

.error{
background:var(--error-bg);
color:var(--error-text);
border:1px solid rgba(185, 28, 28, 0.16);
}

.settings-layout{
display:grid;
grid-template-columns:1.6fr 1fr;
gap:22px;
align-items:start;
}

.stack{
display:grid;
gap:22px;
}

.section{
background:var(--surface);
border:1px solid var(--border);
border-radius:24px;
box-shadow:var(--shadow);
overflow:hidden;
}

.section-head{
padding:22px 24px 12px;
}

.section-title{
margin:0;
font-size:19px;
font-weight:700;
}

.section-desc{
margin:8px 0 0;
font-size:14px;
line-height:1.5;
color:var(--muted);
}

.card-body{
padding:0 24px 24px;
}

.grid{
display:grid;
grid-template-columns:repeat(2,minmax(0,1fr));
gap:18px;
}

.field{
display:flex;
flex-direction:column;
gap:8px;
}

.field.full{
grid-column:1 / -1;
}

label{
font-size:13px;
font-weight:700;
letter-spacing:0.02em;
color:var(--muted);
text-transform:uppercase;
}

input,select{
width:100%;
padding:13px 14px;
border:1px solid var(--border);
border-radius:14px;
background:var(--surface-muted);
color:var(--text);
font-size:15px;
outline:none;
transition:border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
}

input:focus,
select:focus{
border-color:rgba(79, 70, 229, 0.45);
box-shadow:0 0 0 4px rgba(79, 70, 229, 0.12);
background:#fff;
}

input[type="file"]{
padding:12px;
}

.actions{
display:flex;
justify-content:flex-end;
}

button{
background:var(--primary);
border:none;
color:#fff;
padding:12px 18px;
border-radius:14px;
cursor:pointer;
font-weight:700;
font-size:14px;
margin-top:18px;
transition:transform 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
box-shadow:0 12px 24px rgba(79, 70, 229, 0.22);
}

button:hover{
background:var(--primary-dark);
transform:translateY(-1px);
}

.pref-list{
display:grid;
gap:14px;
}

.pref-item{
display:flex;
justify-content:space-between;
align-items:center;
gap:16px;
padding:16px 18px;
background:var(--surface-muted);
border:1px solid var(--border);
border-radius:18px;
}

.pref-title{
margin:0 0 4px;
font-size:15px;
font-weight:700;
}

.pref-text{
margin:0;
font-size:13px;
line-height:1.5;
color:var(--muted);
}

.switch{
position:relative;
display:inline-block;
width:54px;
height:30px;
flex-shrink:0;
}

.switch input{
opacity:0;
width:0;
height:0;
position:absolute;
}

.slider{
position:absolute;
inset:0;
cursor:pointer;
background:#cbd5e1;
border-radius:999px;
transition:0.25s ease;
}

.slider:before{
position:absolute;
content:"";
height:22px;
width:22px;
left:4px;
top:4px;
background:#fff;
border-radius:50%;
transition:0.25s ease;
box-shadow:0 4px 10px rgba(15, 23, 42, 0.15);
}

.switch input:checked + .slider{
background:linear-gradient(135deg, #3a3a3a, #111111);
}

.switch input:checked + .slider:before{
transform:translateX(24px);
}

.support-list{
display:grid;
gap:14px;
}

.support-item{
padding:16px 18px;
border-radius:18px;
background:var(--surface-muted);
border:1px solid var(--border);
}

.support-label{
margin:0 0 6px;
font-size:12px;
font-weight:700;
letter-spacing:0.08em;
text-transform:uppercase;
color:var(--muted);
}

.support-value{
margin:0;
font-size:15px;
line-height:1.6;
}

.support-value a{
color:var(--primary);
text-decoration:none;
font-weight:700;
}

.support-value a:hover{
text-decoration:underline;
}

.theme-dark{
--bg:#000000;
--bg-accent:none;
--surface:#111111;
--surface-muted:#171717;
--surface-soft:#1c1c1c;
--text:#ededed;
--muted:#a3a3a3;
--border:#2d2d2d;
--shadow:0 18px 40px rgba(0, 0, 0, 0.45);
--success-bg:rgba(22, 101, 52, 0.16);
--success-text:#bbf7d0;
--error-bg:rgba(185, 28, 28, 0.18);
--error-text:#fecaca;
}

.theme-dark .hero{
background:linear-gradient(135deg, #0f0f0f, #1b1b1b);
box-shadow:0 18px 42px rgba(0, 0, 0, 0.45);
}

.theme-dark input,
.theme-dark select{
background:var(--surface-soft);
}

.theme-dark input:focus,
.theme-dark select:focus{
background:#13203a;
}

.theme-dark .bottom-nav{
background:#050505;
border-top-color:#1f1f1f;
}

.theme-dark .nav-item{
color:#9a9a9a;
}

.theme-dark .nav-item.active{
color:#fff;
background:rgba(255, 255, 255, 0.08);
}

@media(max-width:900px){
.settings-layout{
grid-template-columns:1fr;
}

.hero{
flex-direction:column;
align-items:flex-start;
}
}

@media(max-width:640px){
.container{
padding:22px 14px 0;
}

.hero{
padding:22px 18px;
border-radius:22px;
}

.hero-title{
font-size:28px;
}

.section{
border-radius:20px;
}

.section-head,
.card-body{
padding-left:18px;
padding-right:18px;
}

.grid{
grid-template-columns:1fr;
}

.actions{
justify-content:stretch;
}

button{
width:100%;
}
}

</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>

</head>
<body>
<?php include 'mobile_back_button.php'; ?>

<div class="container">

<div class="hero">
<div>
<h1 class="hero-title">Settings</h1>
<p class="hero-subtitle">Manage your account details, business requirements, display preferences, and support options in one place.</p>
</div>
<div class="hero-badge"><?php echo htmlspecialchars(ucwords(str_replace("_", " ", $account_type))); ?> Account</div>
</div>

<?php if($success): ?>
<div class="flash success"><?php echo $success; ?></div>
<?php endif; ?>

<?php if($error): ?>
<div class="flash error"><?php echo $error; ?></div>
<?php endif; ?>


<div class="settings-layout">

<div class="stack">

<div class="section">
<div class="section-head">
<h2 class="section-title">Account Settings</h2>
<p class="section-desc">Keep your public profile and contact details accurate for smoother account recovery and updates.</p>
</div>
<div class="card-body">
<form method="POST">
<div class="grid">
<div class="field">
<label>Username</label>
<input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>">
</div>

<div class="field">
<label>Email</label>
<input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>">
</div>

<div class="field">
<label>First Name</label>
<input type="text" name="fname" value="<?php echo htmlspecialchars($user['fname']); ?>">
</div>

<div class="field">
<label>Last Name</label>
<input type="text" name="lname" value="<?php echo htmlspecialchars($user['lname']); ?>">
</div>
</div>

<div class="actions">
<button type="submit" name="update_info">Update Information</button>
</div>
</form>
</div>
</div>

<?php if($account_type==="business_owner"): ?>
<div class="section">
<div class="section-head">
<h2 class="section-title">Business Permit</h2>
<p class="section-desc">Upload or replace your permit file to keep your business account verified and compliant.</p>
</div>
<div class="card-body">
<form method="POST" enctype="multipart/form-data">
<div class="field full">
<label>Upload Permit</label>
<input type="file" name="permit" accept=".jpg,.jpeg,.png,.pdf">
</div>

<div class="actions">
<button type="submit" name="upload_permit">Upload Permit</button>
</div>
</form>
</div>
</div>
<?php endif; ?>

</div>

<div class="stack">

<div class="section">
<div class="section-head">
<h2 class="section-title">Accessibility</h2>
<p class="section-desc">Adjust your display preferences for readability and a more comfortable browsing experience.</p>
</div>
<div class="card-body">
<div class="pref-list">
<div class="pref-item">
<div>
<p class="pref-title">Language</p>
<p class="pref-text">Select the interface language used in this page.</p>
</div>
<select aria-label="Language preference">
<option>English</option>
<option>Filipino</option>
</select>
</div>

<div class="pref-item">
<div>
<p class="pref-title">Dark Mode</p>
<p class="pref-text">Use a darker color palette across the settings page and bottom navigation.</p>
</div>
<label class="switch">
<input type="checkbox" id="darkToggle" aria-label="Toggle dark mode">
<span class="slider"></span>
</label>
</div>
</div>
</div>
</div>

</div>

</div>


</div>



<script>

const toggle = document.getElementById("darkToggle");
const storageKey = "darkmode";

function applyTheme(isDark){
document.body.classList.toggle("theme-dark", isDark);
document.documentElement.classList.toggle("theme-dark", isDark);
if(toggle){
toggle.checked = isDark;
}
}

applyTheme(localStorage.getItem(storageKey) === "on");

if(toggle){
toggle.addEventListener("change", function(){
const isDark = this.checked;
if(window.NVTheme){
window.NVTheme.set(isDark);
}else{
localStorage.setItem(storageKey, isDark ? "on" : "off");
applyTheme(isDark);
}
});
}

</script>
<?php include 'bottom_nav.php'; ?>

</body>
</html>
