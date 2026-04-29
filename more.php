<?php
require_once "config/session.php";
require_once "config/cart_count.php";

$user_type = $_SESSION['account_type'] ?? 'consumer';

/* HANDLE LOGOUT */
if(isset($_GET['logout'])){

    /* CLEAR SESSION */
    $_SESSION = [];
    session_destroy();

    /* CLEAR REMEMBER COOKIES */
    if(isset($_COOKIE['remember_email'])){
        setcookie("remember_email", "", time()-3600, "/");
    }

    if(isset($_COOKIE['remember_password'])){
        setcookie("remember_password", "", time()-3600, "/");
    }

    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>More</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="assets/css/responsive.css"/>

<style>

html, body {
    margin: 0;
    padding: 0;
    width: 100%;
    overflow-x: hidden;
}

body {
    font-family: Arial, sans-serif;
    background: #fff;
}

.container {
    max-width: 1100px;
    margin: auto;
    padding: 20px 20px 100px;
}

.page-title {
    font-size: 22px;
    font-weight: bold;
    color: #001a47;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.page-title img{
    height:42px;
    border-radius:6px;
}

/* CART BUTTON */
.cart-btn {
    text-decoration: none;
    color: #001a47;
    background: rgba(0, 26, 71, 0.08);
    padding: 10px;
    border-radius: 50%;
    font-size: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.option {
    display: flex;
    align-items: center;
    padding: 15px 0;
    border-bottom: 1px solid #eee;
    cursor: pointer;
    text-decoration: none;
}

.option i {
    font-size: 22px;
    color: #001a47;
    margin-right: 15px;
    width: 26px;
    text-align: center;
}

.option span {
    font-size: 16px;
    color: #001a47;
    font-weight: 500;
}

.logout-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    background: #001a47;
    color: #fff;
    padding: 14px;
    border-radius: 10px;
    margin-top: 35px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
}

.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.35);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 999;
}

.modal {
    width: 90%;
    max-width: 420px;
    background: #fff;
    border-radius: 14px;
    padding: 25px;
    text-align: center;
}

.modal h3 {
    margin-top: 0;
    color: #001a47;
    font-size: 20px;
}

.modal p {
    font-size: 16px;
    margin-bottom: 25px;
}

.modal-actions {
    display: flex;
    gap: 10px;
}

.modal-actions button {
    flex: 1;
    padding: 12px;
    border-radius: 8px;
    border: none;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
}

.btn-cancel {
    background: #eee;
    color: #555;
}

.btn-confirm {
    background: #001a47;
    color: #fff;
}
.cart-btn {
      position: relative;
      background: rgba(0, 26, 71, 0.08);
      padding: 8px;
      border-radius: 50%;
      color: #001a47;
      font-size: 18px;
      text-decoration: none;
    }

    .cart-badge {
      position: absolute;
      top: -4px;
      right: -4px;
      background: #e74c3c;
      color: #fff;
      font-size: 11px;
      font-weight: bold;
      padding: 2px 6px;
      border-radius: 50%;
    }

</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="container">

    <div class="page-title">
        <a href="cart.php" class="cart-btn">
    <i class="fa fa-shopping-cart"></i>

    <?php if($cartCount > 0): ?>
        <span class="cart-badge">
            <?= $cartCount ?>
        </span>
    <?php endif; ?>
</a>

    </div>

    <!-- ALWAYS SHOW -->
    <a href="profile.php" class="option">
        <i class="fa-regular fa-user"></i>
        <span>Profile</span>
    </a>

    <?php if($user_type === "consumer"): ?>

<a href="orders.php" class="option">
    <i class="fa fa-cart-shopping"></i>
    <span>My Orders</span>
</a>

<?php elseif($user_type === "business_owner"): ?>

<?php endif; ?>

    <?php if($user_type === "consumer"): ?>

       <a href="calendar.php" class="option">
    <i class="fa-regular fa-calendar"></i>
    <span>Event Calendar</span>
</a>


        <a href="#" class="option">
    <i class="fa fa-heart"></i>
    <span>Favorites</span>
</a>

        <a href="settings.php" class="option">
            <i class="fa fa-gear"></i>
            <span>Settings</span>
    </a>

    <?php endif; ?>


    <?php if($user_type === "business_owner"): ?>

        <!-- BUSINESS OWNER ONLY -->

         <a href="business_profile.php" class="option">
        <i class="fa-solid fa-store"></i>
        <span>Business Profile</span>
    </a>

    <a href="order_list.php" class="option">
    <i class="fa fa-cart-shopping"></i>
    <span>Consumer Orders</span>

      <a href="creviews.php" class="option">
    <i class="fa fa-star"></i>
    <span>Consumer Reviews</span>
</a>
</a>
         <a href="profdashboard.php" class="option">
            <i class="fa fa-chart-column"></i>
            <span>Professional Dashboard</span>
</a>

       <a href="inventory.php" class="option">
    <i class="fa-regular fa-file-lines"></i>
    <span>Inventory</span>
</a>


      <a href="calendar.php" class="option">
    <i class="fa-regular fa-calendar"></i>
    <span>Event Calendar</span>
</a>

        <a href="settings.php" class="option">
            <i class="fa fa-gear"></i>
            <span>Settings</span>
    </a>

    <?php endif; ?>


    <div class="logout-btn" onclick="openLogout()">
        <i class="fa fa-right-from-bracket"></i> Logout
    </div>

</div>

<!-- LOGOUT MODAL -->
<div class="modal-overlay" id="logoutModal">
    <div class="modal">
        <h3>Logout</h3>
        <p>Are you sure you want to log out?</p>

        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeLogout()">Cancel</button>
            <button class="btn-confirm" onclick="window.location.href='more.php?logout=1'">Yes</button>
        </div>
    </div>
</div>

<?php include 'bottom_nav.php'; ?>

<script>

function openLogout(){
    document.getElementById('logoutModal').style.display = 'flex';
}

function closeLogout(){
    document.getElementById('logoutModal').style.display = 'none';
}

</script>

</body>
</html>
