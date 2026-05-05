<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/cart_count.php";
require_once "config/notifications_helper.php";

$user_id = $_SESSION['user_id'];
$account_type = $_SESSION['account_type'];

if (isset($_GET['read'])) {
    $update = $conn->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE user_id = ? AND account_type = ?
    ");

    if ($update) {
        $update->bind_param("is", $user_id, $account_type);
        $update->execute();
        $update->close();
    }

    header("Location: notifications.php");
    exit;
}

syncNotificationsForUser($conn, (int) $user_id, (string) $account_type);

$notifCount = unreadNotificationCount($conn, (int) $user_id, (string) $account_type);
$allowedTitles = allowedNotificationTitles((string) $account_type);
$titlePlaceholders = implode(",", array_fill(0, count($allowedTitles), "?"));

$listStmt = $conn->prepare("
    SELECT *
    FROM notifications
    WHERE user_id = ? AND account_type = ?
      AND title IN ($titlePlaceholders)
    ORDER BY created_at DESC, id DESC
");

if (!$listStmt) {
    die("Prepare failed: " . $conn->error);
}

$listParams = array_merge([(int) $user_id, (string) $account_type], $allowedTitles);
$listTypes = "is" . str_repeat("s", count($allowedTitles));
$listBindArgs = [$listTypes];

foreach ($listParams as $key => $value) {
    $listBindArgs[] = &$listParams[$key];
}

call_user_func_array([$listStmt, "bind_param"], $listBindArgs);
$listStmt->execute();
$result = $listStmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}
$listStmt->close();

function groupLabel($date)
{
    $today = date("Y-m-d");
    $yesterday = date("Y-m-d", strtotime("-1 day"));

    if ($date === $today) {
        return "TODAY";
    }

    if ($date === $yesterday) {
        return "YESTERDAY";
    }

    return date("F d, Y", strtotime($date));
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="assets/css/responsive.css"/>
<style>
*,*::before,*::after{box-sizing:border-box;}
body{margin:0;font-family:Arial;background:#f5f7fb;}
.container{max-width:1100px;margin:auto;padding:18px 20px 90px;}
.header-row{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:nowrap;}
.header-main{display:flex;align-items:center;gap:12px;min-width:0;flex:1;}
.page-back-btn{width:42px;height:42px;border:none;border-radius:999px;background:rgba(255,255,255,0.96);color:#001a47;box-shadow:0 6px 18px rgba(0,0,0,.16);display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:18px;flex:0 0 42px;}
.page-title{font-size:22px;font-weight:bold;color:#001a47;}
.header-actions{display:flex;align-items:center;gap:10px;min-width:0;}
.header-action-link{text-decoration:none;}
.read-btn{font-size:13px;text-decoration:none;color:#001a47;font-weight:600;}
.icon-btn{position:relative;background:rgba(0,26,71,0.08);padding:10px;border-radius:50%;color:#001a47;}
.badge{position:absolute;top:-4px;right:-4px;background:#dc3545;color:#fff;font-size:11px;padding:2px 6px;border-radius:50%;}
.group-label{font-weight:bold;margin:22px 0 10px;color:#344054;font-size:13px;letter-spacing:.6px;}
.notif-card{display:block;background:#fff;padding:14px 16px;border-radius:12px;margin-bottom:10px;text-decoration:none;color:inherit;box-shadow:0 3px 12px rgba(0,0,0,.05);overflow:hidden;}
.notif-card.unread{background:#eef4ff;border:1px solid #b7cdf7;}
.notif-title{font-weight:700;color:#001a47;margin-bottom:6px;}
.notif-message{color:#344054;line-height:1.45;word-break:break-word;}
.notif-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;font-size:12px;color:#667085;margin-top:10px;}
.notif-footer span:last-child{margin-left:auto;text-align:right;}
.empty-state{padding:40px 0;text-align:center;color:#667085;}
.top-icon{position:relative;background:rgba(0,26,71,0.08);padding:10px;border-radius:50%;color:#001a47;font-size:18px;text-decoration:none;display:flex;align-items:center;justify-content:center;}
.top-icon .badge{right:-5px;}
@media (max-width:768px){
    .container{width:100%;padding-top:16px !important;padding-left:16px !important;padding-right:16px !important;}
    .header-row{align-items:center;}
    .header-main{gap:10px;}
    .page-title{font-size:18px;line-height:1.2;word-break:break-word;}
    .header-actions{margin-left:0;justify-self:end;gap:8px;flex-wrap:wrap;justify-content:flex-end;}
    .read-btn{display:none;}
    .top-icon{width:40px;height:40px;flex:0 0 40px;}
    .notif-card{padding:14px;}
    .notif-footer{gap:8px;}
}
</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>
<body>
<div class="container">
    <div class="header-row">
        <div class="header-main">
            <a
                href="home.php"
                class="page-back-btn"
                aria-label="Go back"
                onclick="if(window.history.length > 1){ window.history.back(); return false; }"
            >
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="page-title">Notifications</div>
        </div>

        <div class="header-actions">
            <?php if ($account_type === "consumer"): ?>
                <a href="cart.php" class="top-icon header-action-link">
                    <i class="fa fa-shopping-cart"></i>
                    <?php if ($cartCount > 0): ?>
                        <span class="badge"><?= (int) $cartCount ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <?php if ($notifCount > 0): ?>
                <a href="notifications.php?read=1" class="read-btn">Mark all as read</a>
            <?php endif; ?>

            <a href="notifications.php?read=1" class="top-icon header-action-link" title="Mark all as read" aria-label="Mark all as read">
                <i class="fa fa-check-double"></i>
                <?php if ($notifCount > 0): ?>
                    <span class="badge"><?= $notifCount ?></span>
                <?php endif; ?>
            </a>
        </div>
    </div>

    <?php if (empty($notifications)): ?>
        <div class="empty-state">No notifications yet.</div>
    <?php else: ?>
        <?php $currentGroup = ""; ?>
        <?php foreach ($notifications as $notif): ?>
            <?php
            $dateOnly = date("Y-m-d", strtotime($notif['created_at']));
            $group = groupLabel($dateOnly);
            $time = date("h:i A", strtotime($notif['created_at']));
            $date = date("m/d/Y", strtotime($notif['created_at']));
            $targetLink = notificationLink((string) ($notif['title'] ?? ''), (string) $account_type);
            ?>

            <?php if ($group !== $currentGroup): ?>
                <div class="group-label"><?= htmlspecialchars($group) ?></div>
                <?php $currentGroup = $group; ?>
            <?php endif; ?>

            <a href="<?= htmlspecialchars($targetLink) ?>" class="notif-card <?= ((int) $notif['is_read'] === 0) ? 'unread' : '' ?>">
                <div class="notif-title"><?= htmlspecialchars($notif['title']) ?></div>
                <div class="notif-message"><?= htmlspecialchars($notif['message']) ?></div>
                <div class="notif-footer">
                    <span><?= htmlspecialchars($time) ?></span>
                    <span><?= htmlspecialchars($date) ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include 'bottom_nav.php'; ?>

</body>
</html>
