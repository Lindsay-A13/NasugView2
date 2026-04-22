<?php
session_start();

/* =============================
   DATABASE CONNECTION
============================= */
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "nasugview2";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

/* =============================
   CHECK LOGIN
============================= */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* =============================
   FETCH ADMIN NAME
============================= */
$id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT username, fname, lname FROM negosyo_center_users WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result_admin = $stmt->get_result();

$admin_fullname = "Admin";
if ($row_admin = $result_admin->fetch_assoc()) {
    $admin_fullname = trim($row_admin['fname'].' '.$row_admin['lname']);
}

/* =============================
   FETCH BUSINESSES
============================= */
$sql = "SELECT business_name, fname, lname, address, gender, description, phone 
        FROM business_owner 
        ORDER BY business_name ASC";

$result = $conn->query($sql);
if (!$result) die("SQL Error: " . $conn->error);

$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Businesses - NasugView</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
:root {
    --primary-color:#001a47;
    --secondary-color:#f8f9fa;
    --gradient-end:#00308a;
    --sidebar-width:250px;
}

body {
    font-family:'Poppins',sans-serif;
    background:var(--secondary-color);
    margin:0;
}

/* Sidebar should sit on the left, fixed */
.sidebar {
    position: fixed;
    top:0;
    left:0;
    width: var(--sidebar-width);
    height:100%;
    z-index: 1000;
}

/* Main content pushed right of sidebar */
.main-content {
    margin-left: var(--sidebar-width);
    padding:2rem;
    min-height:100vh;
    position: relative;
    z-index:1;
    background: var(--secondary-color);
}

.users-table-container{
    background:white;
    border-radius:20px;
    padding:2rem;
    box-shadow:0 5px 25px rgba(0,0,0,0.08);
}

.table th{
    background:linear-gradient(135deg,var(--primary-color),var(--gradient-end));
    color:white;
    font-weight:600;
}

.btn-action{
    width:36px;
    height:36px;
    border-radius:10px;
    display:flex;
    align-items:center;
    justify-content:center;
    border:none;
    color:white;
}

.btn-view{background:#17a2b8;}
.btn-edit{background:#ffc107;}

.search-icon{
    position:absolute;
    top:10px;
    left:10px;
    color:#888;
}

.search-box input{
    padding-left:35px;
}
</style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main-content">

    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <div>
            <h2 class="fw-bold text-primary">Businesses</h2>
            <p class="text-muted mb-0">Manage and monitor all businesses</p>
        </div>

        <div class="search-box position-relative" style="width:250px;">
            <i class="fas fa-search search-icon"></i>
            <input id="searchInput" type="text" class="form-control" placeholder="Search businesses...">
        </div>
    </div>

    <!-- TABLE -->
    <div class="users-table-container">
        <div class="table-responsive">
            <table class="table table-hover" id="businessTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Business Name</th>
                        <th>Owner Name</th>
                        <th>Address</th>
                        <th>Gender</th>
                        <th>Description</th>
                        <th>Phone</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if($result->num_rows > 0){
                    $count = 1;
                    while($row = $result->fetch_assoc()){
                        $owner = trim($row['fname'].' '.$row['lname']);
                        echo "<tr>";
                        echo "<td>".$count++."</td>";
                        echo "<td>".htmlspecialchars($row['business_name'])."</td>";
                        echo "<td>".htmlspecialchars($owner)."</td>";
                        echo "<td>".htmlspecialchars($row['address'])."</td>";
                        echo "<td>".htmlspecialchars($row['gender'])."</td>";
                        echo "<td>".htmlspecialchars($row['description'] ?: '-')."</td>";
                        echo "<td>".htmlspecialchars($row['phone'] ?: '-')."</td>";
                        echo "<td>
                                <div class='d-flex gap-2'>
                                    <button class='btn-action btn-view'><i class='fas fa-eye'></i></button>
                                    <button class='btn-action btn-edit'><i class='fas fa-edit'></i></button>
                                </div>
                              </td>";
                        echo "</tr>";
                    }
                } else {
                    echo '<tr><td colspan="8" class="text-center text-muted">No businesses found.</td></tr>';
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
document.getElementById("searchInput").addEventListener("keyup", function() {
    let filter = this.value.toLowerCase();
    let rows = document.querySelectorAll("#businessTable tbody tr");

    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(filter) ? "" : "none";
    });
});
</script>

</body>
</html>