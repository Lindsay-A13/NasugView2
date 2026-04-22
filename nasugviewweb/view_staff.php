<?php
session_start();
$conn = new mysqli("localhost","root","","nasugview2");
$staff=$conn->query("SELECT * FROM negosyo_center_users WHERE designation='Staff'");
?>

<!DOCTYPE html>
<html>
<head>
<title>Staff List</title>

<link href="../bootstrap5/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

<style>
body{
    font-family:'Poppins',sans-serif;
    background:#f0f4ff;
}

/* SAME CARD STYLE AS EVENTS */
.card{
    border-radius:18px;
    padding:2rem;
    background:#fff;
    border-left:6px solid #001a47;
    box-shadow:0 8px 25px rgba(0,0,0,0.08);
}

/* SAME TABLE STYLE AS EVENTS */
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

/* SAME ACTION BUTTONS */
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
    transition:.3s;
}

.btn-action:hover{
    transform:translateY(-2px);
    box-shadow:0 4px 12px rgba(0,0,0,.2);
}

#searchInput{
    margin-bottom:1rem;
}
</style>
</head>

<body>

<div class="container mt-4">

<!-- CARD wrapper (IMPORTANT) -->
<div class="card">

    <div class="d-flex justify-content-between mb-3">
        <h4 class="mb-0">Staff Directory</h4>
        <input type="text" id="searchInput" class="form-control w-25" placeholder="Search...">
    </div>

    <table class="table table-hover mb-0" id="staffTable">

        <thead>
        <tr>
            <th width="80">ID</th>
            <th>Name</th>
            <th>Username</th>
            <th width="140">Actions</th>
        </tr>
        </thead>

        <tbody>
        <?php while($s=$staff->fetch_assoc()): ?>
        <tr>
            <td><?= $s['id'] ?></td>
            <td><?= $s['fname']." ".$s['lname'] ?></td>
            <td><?= $s['username'] ?></td>
            <td>
                <div class="action-buttons">
                    <a href="view_staff.php?id=<?= $s['id'] ?>" class="btn-action">
                        <i class="fas fa-eye"></i>
                    </a>
                    <a href="edit_staff.php?id=<?= $s['id'] ?>" class="btn-action">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="delete_staff.php?id=<?= $s['id'] ?>" class="btn-action">
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
const rows = document.querySelectorAll('#staffTable tbody tr');
document.getElementById('searchInput').addEventListener('input', function(){
    const val=this.value.toLowerCase();
    rows.forEach(r=>{
        r.style.display=r.textContent.toLowerCase().includes(val)?'':'none';
    });
});
</script>

</body>
</html>