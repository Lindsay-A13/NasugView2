<?php
session_start();
$conn = new mysqli("localhost","root","","nasugview2");
if($conn->connect_error) die("DB Error");

if(isset($_GET['delete'])){
    $id=intval($_GET['delete']);
    $conn->query("DELETE FROM negosyo_center_users WHERE id=$id");
    header("Location: manage_users.php");
    exit();
}

$users=$conn->query("SELECT * FROM negosyo_center_users ORDER BY id DESC");
?>

<!DOCTYPE html>
<html>
<head>
<title>Manage Users</title>

<link href="../bootstrap5/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

<style>
body{
    font-family:'Poppins', sans-serif;
    background:#f0f4ff;
}

/* Card wrapper */
.card{
    border-radius:18px;
    padding:2rem;
    background:#fff;
    border-left:6px solid #001a47;
    box-shadow:0 8px 25px rgba(0,0,0,0.08);
}

/* Table styles */
.table th{
    background: linear-gradient(135deg,#001a47,#00308a);
    color:white;
    border:none;
    padding:1rem;
    font-weight:600;
    font-size:0.9rem;
}

.table td{
    padding:1rem;
    vertical-align:middle;
    border-bottom:1px solid #f1f3f4;
}

/* Action buttons like events */
.action-buttons{
    display:flex;
    gap:0.5rem;
}

.btn-action{
    width:36px;
    height:36px;
    border-radius:10px;
    display:flex;
    align-items:center;
    justify-content:center;
    border:none;
    background: linear-gradient(135deg,#001a47,#00308a);
    color:white;
    transition: all 0.3s ease;
}

.btn-action:hover{
    transform:translateY(-2px);
    box-shadow:0 4px 12px rgba(0,0,0,.2);
}

/* Search input */
#searchInput{
    margin-bottom:1rem;
}
</style>
</head>

<body>

<div class="container mt-4">

<div class="card">

    <div class="d-flex justify-content-between mb-3">
        <h4 class="mb-0"><i class="fas fa-users me-2"></i>Manage Users</h4>
        <input type="text" id="searchInput" class="form-control w-25" placeholder="Search users...">
    </div>

    <table class="table table-hover mb-0" id="usersTable">
        <thead>
        <tr>
            <th width="60">ID</th>
            <th>Name</th>
            <th>Username</th>
            <th width="120">Role</th>
            <th width="140">Actions</th>
        </tr>
        </thead>

        <tbody>
        <?php while($u=$users->fetch_assoc()): ?>
        <tr>
            <td><?= $u['id'] ?></td>
            <td><?= $u['fname']." ".$u['lname'] ?></td>
            <td><?= $u['username'] ?></td>
            <td><span class="badge bg-primary"><?= $u['designation'] ?></span></td>
            <td>
                <div class="action-buttons">
                    <a href="view_user.php?id=<?= $u['id'] ?>" class="btn-action" title="View">
                        <i class="fas fa-eye"></i>
                    </a>
                    <a href="edit_user.php?id=<?= $u['id'] ?>" class="btn-action" title="Edit">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="?delete=<?= $u['id'] ?>" 
                       onclick="return confirm('Delete this user?')" 
                       class="btn-action" title="Delete">
                        <i class="fas fa-trash"></i>
                    </a>
                </div>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>

</div>
</div>

<script>
const rows = document.querySelectorAll('#usersTable tbody tr');
document.getElementById('searchInput').addEventListener('input', function(){
    const val=this.value.toLowerCase();
    rows.forEach(r=>{
        r.style.display=r.textContent.toLowerCase().includes(val)?'':'none';
    });
});
</script>

</body>
</html>