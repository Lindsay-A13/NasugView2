<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($admin_fullname, $designation) && isset($_SESSION['user_id'])) {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        require_once 'db.php';
    }

    $stmt = $conn->prepare("SELECT username, fname, lname, designation FROM dti_user WHERE dti_id=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $fname = trim($row['fname'] ?? '');
            $lname = trim($row['lname'] ?? '');
            $username = trim($row['username'] ?? '');

            $admin_fullname = ($fname !== '' || $lname !== '')
                ? trim($fname . ' ' . $lname)
                : ($username !== '' ? $username : 'User');
            $designation = trim($row['designation'] ?? '') ?: 'User';
        }

        $stmt->close();
    }
}

$admin_fullname = $admin_fullname ?? "User";
$designation = $designation ?? "User";
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Fonts & Icons -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

<!-- Sidebar -->
<div class="sidebar">

    <div class="sidebar-header">

        <div class="logo">
            <img src="assets/nasugviewlogoblue.png" alt="NasugView Logo" class="logo-img">
        </div>

        <div class="user-info">
            <h6><?php echo htmlspecialchars($admin_fullname); ?></h6>
            <small><?php echo htmlspecialchars($designation); ?></small>
        </div>

    </div>

    <div class="sidebar-menu">
        <ul>

            <li>
                <a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i> Dashboard
                </a>
            </li>

            <li>
                <a href="negosyocentersetup.php" class="<?php echo $current_page == 'negosyocentersetup.php' ? 'active' : ''; ?>">
                    <i class="fas fa-store"></i> Negosyo Centers
                </a>
            </li>

            <li>
                <a href="user_management.php" class="<?php echo $current_page == 'user_management.php' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> User Management
                </a>
            </li>

            <li>
                <a href="settings.php" class="<?php echo $current_page == 'settings.php' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </li>

            <li style="margin-top:2rem;">
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>

        </ul>
    </div>
</div>

<style>
:root {
    --primary-color: #001a47;
    --secondary-color: #f8f9fa;
    --gradient-start: #001a47;
    --gradient-end: #00308a;
    --sidebar-width: 250px;
}

body, .sidebar, .sidebar a, .user-info {
    font-family: 'Poppins', sans-serif;
}

.sidebar {
    background: linear-gradient(180deg, var(--gradient-start), var(--gradient-end));
    color: white;
    width: var(--sidebar-width);
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    padding: 0;
    overflow-y: auto;
    box-shadow: 4px 0 20px rgba(0,0,0,0.1);
    z-index: 1000;
}

.sidebar-header {
    background: linear-gradient(to bottom, #ffffff 0%, #ffffff 0%, #001a47 100%);
    padding: 2.5rem 1.5rem 2rem;
    position: sticky;
    top: 0;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.logo {
    display: flex;
    justify-content: center;
    align-items: center;
    margin-bottom: 1rem;
}

.logo-img {
    width: 150px;
}

.user-info {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    margin: 0;
}

.user-info h6 {
    margin: 0;
    font-weight: 600;
    font-size: 16px;
    line-height: 1.2;
}

.user-info small {
    font-size: 13px;
    opacity: 0.8;
    line-height: 1.2;
}

.sidebar-menu ul {
    list-style: none;
    padding: 1.5rem 0 0 0;
    margin: 0;
}

.sidebar-menu ul li a {
    display: flex;
    align-items: center;
    padding: 1rem 1.5rem;
    margin: 0.25rem 0;
    color: rgba(255,255,255,0.85);
    text-decoration: none;
    border-left: 4px solid transparent;
    border-radius: 0 12px 12px 0;
    font-weight: 500;
    transition: 0.3s;
}

.sidebar-menu ul li a i {
    width: 24px;
    margin-right: 12px;
    font-size: 1.2rem;
}

.sidebar-menu ul li a:hover,
.sidebar-menu ul li a.active {
    background: linear-gradient(135deg, rgba(13,47,107,0.95), rgba(0,48,138,0.95));
    border-left-color: rgba(255,255,255,0.9);
    color: white;
    transform: translateX(4px);
    box-shadow: 0 8px 20px rgba(0,26,71,0.22);
}

.sidebar::-webkit-scrollbar {
    width: 6px;
}

.sidebar::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.3);
    border-radius: 3px;
}

@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
        width: 280px;
    }
}
</style>
