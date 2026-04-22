<?php
session_start();
require 'db.php';

// FETCH USERS
$users = $conn->query("
SELECT u.*, n.branch_name, n.municipality, n.province
FROM negosyo_center_users u
LEFT JOIN negosyo_centers n ON u.center_id = n.id
");

// FETCH CENTERS
$centers = $conn->query("SELECT * FROM negosyo_centers ORDER BY branch_name ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Management - NasugView</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

<style>
body{font-family:Poppins;background:#f0f4ff;}
.main-content{margin-left:250px;padding:2rem;}
.card{border-left:6px solid #001a47;border-radius:18px;padding:2rem;background:#fff;box-shadow:0 8px 25px rgba(0,0,0,0.08);}
.table th{background:linear-gradient(135deg,#001a47,#00308a);color:white;}
.btn-submit{background:#001a47;color:#fff;border-radius:10px;padding:8px 20px;border:none;}
.btn-submit:hover{background:#00308a;}
.btn-action{width:36px;height:36px;border-radius:10px;background:#001a47;color:#fff;border:none;display:flex;align-items:center;justify-content:center;}
.action-buttons{display:flex;gap:5px;}
</style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main-content">
<div class="card">

<div class="d-flex justify-content-between mb-3">
<input type="text" id="searchInput" class="form-control" placeholder="Search user...">
<button class="btn btn-submit ms-2" data-bs-toggle="modal" data-bs-target="#addUserModal">
<i class="fas fa-plus"></i> Add User
</button>
</div>

<table class="table table-hover" id="userTable">
<thead>
<tr>
<th>Name</th><th>Email</th><th>Designation</th><th>Center</th><th>Municipality</th><th>Province</th><th>Actions</th>
</tr>
</thead>
<tbody>
<?php while($u=$users->fetch_assoc()): ?>
<tr>
<td><?= htmlspecialchars($u['fname']." ".$u['lname']) ?></td>
<td><?= htmlspecialchars($u['email']) ?></td>
<td><?= htmlspecialchars($u['designation']) ?></td>
<td><?= htmlspecialchars($u['branch_name']) ?></td>
<td><?= htmlspecialchars($u['municipality']) ?></td>
<td><?= htmlspecialchars($u['province']) ?></td>
<td>
<div class="action-buttons">
<button class="btn-action editBtn"
data-id="<?= $u['id'] ?>"
data-fname="<?= htmlspecialchars($u['fname']) ?>"
data-lname="<?= htmlspecialchars($u['lname']) ?>"
data-username="<?= htmlspecialchars($u['username']) ?>"
data-designation="<?= htmlspecialchars($u['designation']) ?>"
data-contact="<?= htmlspecialchars($u['contact']) ?>"
title="Edit User">
<i class="fas fa-edit"></i>
</button>

<button class="btn-action deleteUser" data-id="<?= $u['id'] ?>" title="Delete User">
<i class="fas fa-trash"></i>
</button>
</div>
</td>
</tr>
<?php endwhile; ?>
</tbody>
</table>

</div>
</div>

<!-- ADD USER MODAL -->
<div class="modal fade" id="addUserModal">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header"><h5>Create User</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<form id="addUserForm">
<div class="modal-body">
<input name="fname" class="form-control mb-2" placeholder="First Name" required>
<input name="lname" class="form-control mb-2" placeholder="Last Name" required>
<input name="username" class="form-control mb-2" placeholder="Username" required>
<input name="designation" class="form-control mb-2" placeholder="Designation">
<input name="contact" class="form-control mb-2" placeholder="Contact">

<select name="center_id" class="form-control mb-2" required>
<option value="">Select Center</option>
<?php
$centers = $conn->query("SELECT * FROM negosyo_centers ORDER BY branch_name ASC");
while($c=$centers->fetch_assoc()):
?>
<option value="<?= $c['id'] ?>"><?= $c['branch_name']." - ".$c['municipality'] ?></option>
<?php endwhile; ?>
</select>

<input name="negosyocenter" class="form-control mb-2" placeholder="Negosyo Center Name" required>
<input name="municipality" class="form-control mb-2" placeholder="Municipality" required>
</div>

<div class="modal-footer">
<button type="submit" class="btn btn-submit">Create</button>
</div>
</form>

</div>
</div>
</div>

<!-- EDIT USER MODAL -->
<div class="modal fade" id="editUserModal">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header"><h5>Edit User</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<form id="editUserForm">
<input type="hidden" name="id" id="edit_id">
<div class="modal-body">
<input id="edit_fname" name="fname" class="form-control mb-2" placeholder="First Name">
<input id="edit_lname" name="lname" class="form-control mb-2" placeholder="Last Name">
<input id="edit_username" name="username" class="form-control mb-2" placeholder="Username">
<input id="edit_designation" name="designation" class="form-control mb-2" placeholder="Designation">
<input id="edit_contact" name="contact" class="form-control mb-2" placeholder="Contact">
</div>

<div class="modal-footer">
<button class="btn btn-submit">Update</button>
</div>
</form>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// SEARCH
document.getElementById('searchInput').addEventListener('keyup',function(){
let v=this.value.toLowerCase();
document.querySelectorAll('#userTable tbody tr').forEach(r=>{
r.style.display=r.textContent.toLowerCase().includes(v)?'':'none';
});
});

// ADD USER
document.getElementById('addUserForm').addEventListener('submit',function(e){


// EDIT USER OPEN
document.querySelectorAll('.editBtn').forEach(btn=>{
btn.onclick=function(){
edit_id.value=this.dataset.id;
edit_fname.value=this.dataset.fname;
edit_lname.value=this.dataset.lname;
edit_username.value=this.dataset.username;
edit_designation.value=this.dataset.designation;
edit_contact.value=this.dataset.contact;
new bootstrap.Modal(editUserModal).show();
};
});

// UPDATE USER
document.getElementById('editUserForm').addEventListener('submit',function(e){
e.preventDefault();
fetch('update_user.php',{method:'POST',body:new FormData(this)})
.then(()=>location.reload());
});

// DELETE USER
document.querySelectorAll('.deleteUser').forEach(btn=>{
btn.onclick=function(){
Swal.fire({title:'Delete user?',showCancelButton:true}).then(r=>{
if(r.isConfirmed){
fetch('delete_user.php',{method:'POST',body:JSON.stringify({id:this.dataset.id})})
.then(()=>location.reload());
}
});
};
});
</script>

</body>
</html>