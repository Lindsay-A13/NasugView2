<?php
session_start();
$conn = new mysqli("localhost","root","","nasugview2");

$success="";

if($_SERVER['REQUEST_METHOD']=="POST"){
    $password=password_hash($_POST['password'],PASSWORD_DEFAULT);

    $stmt=$conn->prepare("INSERT INTO negosyo_center_users(fname,lname,username,password,designation)
                          VALUES(?,?,?,?, 'Admin')");
    $stmt->bind_param("ssss",$_POST['fname'],$_POST['lname'],$_POST['username'],$password);
    $stmt->execute();

    $success="Admin added successfully!";
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Add Admin</title>

<link href="../bootstrap5/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

<style>
body{
    font-family:'Poppins', sans-serif;
    background:#f0f4ff;
}

.header{
    background:#001a47;
    color:white;
    padding:15px 25px;
    border-radius:0 0 10px 10px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.card{
    border-radius:18px;
    padding:2rem;
    background:#fff;
    border-left:6px solid #001a47;
    box-shadow:0 8px 25px rgba(0,0,0,0.08);
    max-width:500px;
    margin:auto;
    margin-top:4rem;
}

.form-control{
    border-radius:10px;
    border:1px solid #d6e4ff;
    box-shadow:0 0 0 3px rgba(0,26,71,0.08);
    padding:8px 10px;
    height:44px;
}

.form-control:focus{
    border-color:#001a47;
    box-shadow:0 0 0 4px rgba(0,26,71,0.25);
}

.btn-submit{
    background: linear-gradient(135deg,#001a47,#00308a);
    color:white;
    border-radius:10px;
    padding:10px 24px;
    font-weight:600;
    border:none;
}

.btn-submit:hover{
    background: linear-gradient(135deg,#00308a,#001a47);
}

.alert{
    border-radius:12px;
    padding:1rem 1.5rem;
}
</style>
</head>

<body>

<div class="header">
    <h5><i class="fas fa-user-plus me-2"></i>Add Admin</h5>
    <a href="dashboard.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
</div>

<div class="card">

    <?php if($success): ?>
        <div class="alert alert-success text-center"><?= $success ?></div>
    <?php endif; ?>

    <form method="POST">

        <div class="mb-3">
            <label class="form-label">First Name</label>
            <input name="fname" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Last Name</label>
            <input name="lname" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Username</label>
            <input name="username" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>

        <button class="btn-submit w-100"><i class="fas fa-plus me-1"></i>Create Admin</button>

    </form>

</div>

</body>
</html>