<?php
$current = basename($_SERVER['PHP_SELF']);
require_once __DIR__ . "/config/session.php";
require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/config/notifications_helper.php";

function active($page){
    global $current;
    return $current === $page ? 'active' : '';
}

$bottomNavNotifCount = 0;

if (isset($_SESSION['user_id'], $_SESSION['account_type'])) {
    syncNotificationsForUser($conn, (int) $_SESSION['user_id'], (string) $_SESSION['account_type']);
    $bottomNavNotifCount = unreadNotificationCount($conn, (int) $_SESSION['user_id'], (string) $_SESSION['account_type']);
}
?>

<!-- FONT AWESOME (REQUIRED FOR ICONS) -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>

html, body{
    margin:0;
    padding:0;
    overflow-x:hidden;
}

.bottom-nav{

    position:fixed;
    bottom:0;
    left:0;

    width:100vw;
    height:72px;

    background:#fff;

    border-top:1px solid #ddd;

    display:flex;

    z-index:999999;
}

.nav-item{

    flex:1;

    display:flex;
    flex-direction:column;

    align-items:center;
    justify-content:center;

    text-decoration:none;

    color:#555;

    font-size:12px;
}

.nav-item i{
    font-size:22px;
    margin-bottom:4px;
}

.nav-icon{
    position:relative;
    display:flex;
    align-items:center;
    justify-content:center;
}

.nav-badge{
    position:absolute;
    top:-8px;
    right:-12px;
    min-width:18px;
    height:18px;
    padding:0 5px;
    border-radius:999px;
    background:#dc3545;
    color:#fff;
    font-size:10px;
    font-weight:700;
    line-height:18px;
    text-align:center;
}

.nav-item.active{
    color:#001a47;
    background:rgba(0,26,71,0.08);
}

</style>

<div class="bottom-nav">

<a href="home.php" class="nav-item <?=active('home.php')?>">
<i class="fa fa-house"></i>
<span>Home</span>
</a>

<a href="notifications.php" class="nav-item <?=active('notifications.php')?>">
<span class="nav-icon">
<i class="fa fa-bell"></i>
<?php if($bottomNavNotifCount > 0): ?>
<span class="nav-badge"><?= $bottomNavNotifCount > 99 ? '99+' : $bottomNavNotifCount ?></span>
<?php endif; ?>
</span>
<span>Notifications</span>
</a>

<a href="marketplace.php" class="nav-item <?=active('marketplace.php')?>">
<i class="fa fa-store"></i>
<span>Marketplace</span>
</a>

<a href="more.php" class="nav-item <?=active('more.php')?>">
<i class="fa fa-ellipsis"></i>
<span>Menu</span>
</a>

</div>
