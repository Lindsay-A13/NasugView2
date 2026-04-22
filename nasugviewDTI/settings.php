
<?php
session_start();

// Database connection
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "nasugview2";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Check if admin is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$admin_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Fetch admin info (FIXED SAFE VERSION)
$admin = [
    'admin_id' => $admin_id,
    'username' => '',
    'fname' => '',
    'lname' => '',
    'fullname' => 'User',
    'email' => '',
    'designation' => '',
    'profile_picture' => ''
];

$stmt = $conn->prepare("SELECT dti_id, username, email, fname, lname, designation, profile_picture FROM dti_user WHERE dti_id=?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {

    $admin['admin_id'] = $row['dti_id'];
    $admin['username'] = $row['username'];
    $admin['fname'] = $row['fname'];
    $admin['lname'] = $row['lname'];
    $admin['email'] = $row['email'];
    $admin['designation'] = $row['designation'];
    $admin['profile_picture'] = $row['profile_picture'];

    $admin['fullname'] = trim($row['fname'] . ' ' . $row['lname']);
}

$stmt->close();

$admin_fullname = $admin['fullname'] ?: 'User';
$designation = $admin['designation'] ?: 'No Designation';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $fname = trim($_POST['fname']);
    $lname = trim($_POST['lname']);
    $email = trim($_POST['email']);

    if (!$fname || !$lname || !$email) {
        $error = "Full name and email are required.";
    } else {

        // Handle profile image upload
        if (isset($_FILES['profile_img']) && $_FILES['profile_img']['error'] === UPLOAD_ERR_OK) {

            $fileTmpPath = $_FILES['profile_img']['tmp_name'];
            $fileName = $_FILES['profile_img']['name'];
            $fileNameCmps = explode(".", $fileName);
            $fileExtension = strtolower(end($fileNameCmps));

            $allowedExtensions = ['jpg','jpeg','png','gif'];

            if (in_array($fileExtension, $allowedExtensions)) {

                $newFileName = "profile_" . $admin_id . "." . $fileExtension;

                $uploadDir = './uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $destPath = $uploadDir . $newFileName;

                if (move_uploaded_file($fileTmpPath, $destPath)) {

                    $stmt = $conn->prepare("UPDATE dti_user SET profile_picture=? WHERE dti_id=?");
                    $stmt->bind_param("si", $newFileName, $admin_id);
                    $stmt->execute();
                    $stmt->close();

                    $admin['profile_picture'] = $newFileName;

                } else {
                    $error = "There was an error uploading the image.";
                }

            } else {
                $error = "Allowed file types: jpg, jpeg, png, gif";
            }
        }

        // Update profile info
        $stmt = $conn->prepare("UPDATE dti_user SET fname=?, lname=?, email=? WHERE dti_id=?");
        $stmt->bind_param("sssi", $fname, $lname, $email, $admin_id);

        if ($stmt->execute()) {

            $success = "Profile updated successfully!";

            $admin['fname'] = $fname;
            $admin['lname'] = $lname;
            $admin['fullname'] = $fname . ' ' . $lname;
            $admin['email'] = $email;

            $admin_fullname = $admin['fullname'];

        } else {
            $error = "Failed to update profile.";
        }

        $stmt->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Settings - NasugView</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>

:root {
--primary-color:#001a47;
--secondary-color:#f8f9fa;
--gradient-start:#001a47;
--gradient-end:#00308a;
--sidebar-width:250px;
}

body{
margin:0;
padding:0;
font-family:'Poppins',sans-serif;
background:linear-gradient(135deg,var(--gradient-start),var(--gradient-end));
min-height:100vh;
overflow-x:hidden;
}

.sidebar{
background:linear-gradient(180deg,var(--gradient-start),var(--gradient-end));
color:white;
height:100vh;
padding:0;
box-shadow:4px 0 20px rgba(0,0,0,0.1);
position:fixed;
top:0;
left:0;
width:var(--sidebar-width);
z-index:1000;
overflow-y:auto;
}

.main-content{
margin-left:var(--sidebar-width);
min-height:100vh;
background-color:var(--secondary-color);
}

.content-wrapper{
padding:2rem;
max-width:1200px;
margin:0 auto;
}

.page-title h1{
font-weight:700;
color:var(--primary-color);
margin-bottom:1rem;
}

.page-title p{
color:#6c757d;
margin-bottom:0;
}

.settings-container{
display:grid;
grid-template-columns:320px 1fr;
gap:2rem;
}

.profile-sidebar{
background:white;
border-radius:20px;
padding:2.5rem;
box-shadow:0 5px 25px rgba(0,0,0,0.08);
text-align:center;
position:sticky;
top:2rem;
}

.profile-picture{
width:150px;
height:150px;
border-radius:50%;
margin:0 auto 1.5rem;
overflow:hidden;
border:5px solid #e8f0fe;
box-shadow:0 8px 25px rgba(0,0,0,0.15);
display:flex;
align-items:center;
justify-content:center;
font-weight:bold;
font-size:3rem;
}

.profile-picture img{
width:100%;
height:100%;
object-fit:cover;
}

.profile-info h4{
margin-bottom:0.5rem;
color:var(--primary-color);
font-weight:700;
}

.profile-info p{
color:#6c757d;
margin-bottom:1rem;
word-break:break-word;
}

.admin-id{
background:#f8f9ff;
border:1px solid #e8f0fe;
border-radius:10px;
padding:0.75rem;
font-size:0.9rem;
color:var(--primary-color);
font-weight:600;
}

.settings-form{
background:white;
border-radius:20px;
padding:2.5rem;
box-shadow:0 5px 25px rgba(0,0,0,0.08);
}

.section-title{
font-weight:700;
color:var(--primary-color);
margin-bottom:1.5rem;
font-size:1.3rem;
display:flex;
align-items:center;
gap:10px;
padding-bottom:1rem;
border-bottom:2px solid #f1f3f4;
}

.form-control{
border-radius:12px;
border:2px solid #e8f0fe;
padding:0.75rem 1rem;
background:#fafbfc;
}

.form-control:focus{
border-color:var(--primary-color);
background:white;
box-shadow:0 0 0 0.2rem rgba(0,26,71,0.15);
}

.form-actions{
display:flex;
gap:1rem;
margin-top:2rem;
}

.btn-save{
background:linear-gradient(135deg,var(--primary-color),var(--gradient-end));
border:none;
border-radius:12px;
padding:1rem 2rem;
color:white;
font-weight:600;
}

.btn-reset{
background:transparent;
border:2px solid #6c757d;
color:#6c757d;
border-radius:12px;
padding:1rem 2rem;
font-weight:600;
}

.alert{
border-radius:12px;
padding:1rem 1.5rem;
margin-bottom:1.5rem;
}

</style>
</head>

<body>

<?php include 'sidebar.php'; ?>

<div class="container-fluid">
<div class="row">
<div class="col main-content">

<div class="content-wrapper">

<div class="page-header">
<div class="page-title">
<h1>Admin Settings</h1>
<p>Manage your profile information and account settings</p>
</div>
</div>

<?php if($error): ?>
<div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<?php if($success): ?>
<div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<div class="settings-container">

<div class="profile-sidebar">

<div class="profile-picture">

<?php if (!empty($admin['profile_picture']) && file_exists('./uploads/'.$admin['profile_picture'])): ?>

<img id="profilePreview" src="<?php echo './uploads/'.$admin['profile_picture']; ?>">

<?php else: ?>

<div id="profileInitial" style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:linear-gradient(135deg,var(--primary-color),var(--gradient-end));color:white;border-radius:50%;font-size:3rem;">
<?php echo strtoupper(substr($admin['fname'],0,1)); ?>
</div>

<?php endif; ?>

</div>

<div class="profile-info">
<h4><?php echo htmlspecialchars($admin['fullname']); ?></h4>
<p><?php echo htmlspecialchars($admin['designation']); ?></p><p><?php echo $admin['email']; ?></p>

<div class="admin-id">
<i class="fas fa-id-card me-2"></i>
Admin ID: <?php echo $admin['admin_id']; ?>
</div>

</div>

<a href="logout.php" class="btn-save">Logout</a>

</div>

<div class="settings-form">

<form method="POST" enctype="multipart/form-data">

<div class="section-title">
<i class="fas fa-user"></i>
Profile Information
</div>

<div class="mb-3">
<label class="form-label">First Name *</label>
<input type="text" class="form-control" name="fname" value="<?php echo $admin['fname']; ?>" required>
</div>

<div class="mb-3">
<label class="form-label">Last Name *</label>
<input type="text" class="form-control" name="lname" value="<?php echo $admin['lname']; ?>" required>
</div>

<div class="mb-3">
<label class="form-label">Email *</label>
<input type="email" class="form-control" name="email" value="<?php echo $admin['email']; ?>" required>
</div>

<div class="mb-3">
<label class="form-label">Profile Image</label>
<input type="file" class="form-control" name="profile_img" accept="image/*" onchange="previewImage(event)">
</div>

<div class="form-actions">
<button type="submit" class="btn-save">
<i class="fas fa-save me-2"></i>
Save Changes
</button>

<button type="reset" class="btn-reset">
<i class="fas fa-undo me-2"></i>
Reset
</button>
</div>

</form>

</div>

</div>

</div>

</div>
</div>
</div>

<script>

function previewImage(event){

const reader=new FileReader();

reader.onload=function(){

const img=document.getElementById('profilePreview');

if(img){
img.src=reader.result;
img.style.display='block';
}

const initial=document.getElementById('profileInitial');

if(initial){
initial.style.display='none';
}

};

reader.readAsDataURL(event.target.files[0]);

}

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

