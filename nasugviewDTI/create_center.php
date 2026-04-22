<?php
session_start();

// ==============================
// Database connection
// ==============================
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "nasugview2";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// ==============================
// Initialize variables
// ==============================
$success = "";
$error = "";

$branch_name = "";
$municipality = "";
$province = "";
$address = "";
$contact_number = "";
$email = "";
$officer = "";

// ==============================
// Handle form submission
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $branch_name     = $conn->real_escape_string($_POST['branch_name']);
    $municipality    = $conn->real_escape_string($_POST['municipality']);
    $province        = $conn->real_escape_string($_POST['province']);
    $address         = $conn->real_escape_string($_POST['address']);
    $contact_number  = $conn->real_escape_string($_POST['contact_number']);
    $email           = $conn->real_escape_string($_POST['email']);
    $officer         = $conn->real_escape_string($_POST['officer']);

    if($branch_name && $municipality && $province){

        $stmt = $conn->prepare("
            INSERT INTO negosyo_centers
            (branch_name, municipality, province, address, contact_number, email, officer_in_charge)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "sssssss",
            $branch_name,
            $municipality,
            $province,
            $address,
            $contact_number,
            $email,
            $officer
        );

        if($stmt->execute()){
            $success = "Negosyo Center added successfully!";
            $branch_name = $municipality = $province = $address = $contact_number = $email = $officer = "";
        }else{
            $error = "Error: " . $stmt->error;
        }

        $stmt->close();

    }else{
        $error = "Please fill all required fields.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Negosyo Center</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
background:#f0f4ff;
font-family:'Poppins',sans-serif;
padding:30px;
}

.card{
max-width:900px;
margin:auto;
padding:2rem;
border-radius:18px;
border-left:7px solid #001a47;
box-shadow:0 8px 30px rgba(0,0,0,0.08);
}

.card h3{
color:#001a47;
font-weight:700;
margin-bottom:1.5rem;
}

.form-control{
border-radius:10px;
height:44px;
}

textarea.form-control{
height:120px;
resize:none;
}

.btn-submit{
background:#001a47;
color:white;
border:none;
border-radius:10px;
padding:10px 24px;
font-weight:600;
}

.btn-submit:hover{
background:#00308a;
}

</style>
</head>
<body>

<div class="card">

<h3><i class="fas fa-store"></i> Add Negosyo Center</h3>

<?php if($success): ?>
<div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<?php if($error): ?>
<div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<form method="POST">

<div class="row g-3">

<div class="col-md-6">
<label class="form-label">Branch Name *</label>
<input type="text" name="branch_name" class="form-control" value="<?php echo htmlspecialchars($branch_name); ?>" required>
</div>

<div class="col-md-6">
<label class="form-label">Municipality *</label>
<input type="text" name="municipality" class="form-control" value="<?php echo htmlspecialchars($municipality); ?>" required>
</div>

<div class="col-md-6">
<label class="form-label">Province *</label>
<input type="text" name="province" class="form-control" value="<?php echo htmlspecialchars($province); ?>" required>
</div>

<div class="col-md-6">
<label class="form-label">Officer in Charge</label>
<input type="text" name="officer" class="form-control" value="<?php echo htmlspecialchars($officer); ?>">
</div>

<div class="col-md-6">
<label class="form-label">Contact Number</label>
<input type="text" name="contact_number" class="form-control" value="<?php echo htmlspecialchars($contact_number); ?>">
</div>

<div class="col-md-6">
<label class="form-label">Email</label>
<input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>">
</div>

<div class="col-12">
<label class="form-label">Address</label>
<textarea name="address" class="form-control"><?php echo htmlspecialchars($address); ?></textarea>
</div>

</div>

<div class="mt-3">
<button type="submit" class="btn btn-submit">
<i class="fas fa-plus"></i> Add Negosyo Center
</button>

<a href="negosyocentersetup.php" class="btn btn-secondary ms-2">
<i class="fas fa-arrow-left"></i> Back
</a>
</div>

</form>

</div>

</body>
</html>