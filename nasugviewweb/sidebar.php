<?php
// ==============================
// Sidebar.php (FIXED FOR NAME/DESIGNATION)
// DISPLAY ONLY — NO DB / NO SESSION
// ==============================

// Prevent undefined variable warnings
$admin_fullname = $admin_fullname ?? "User";
$designation    = $designation    ?? "Admin";
$current_page   = basename($_SERVER['PHP_SELF']);
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
                <a href="dashboard.php" class="<?php echo $current_page=='dashboard.php'?'active':''; ?>">
                    <i class="fas fa-chart-line"></i> Dashboard
                </a>
            </li>

            <li>
                <a href="events.php" class="<?php echo $current_page=='events.php'?'active':''; ?>">
                    <i class="fas fa-calendar-alt"></i> Events
                </a>
            </li>

            <li>
                <a href="certificate.php" class="<?php echo $current_page=='certificate.php'?'active':''; ?>">
                    <i class="fas fa-certificate"></i> Certificates
                </a>
            </li>

            <li>
                <a href="businesses.php" class="<?php echo $current_page=='businesses.php'?'active':''; ?>">
                    <i class="fas fa-briefcase"></i> Businesses
                </a>
            </li>

            <li>
                <a href="settings.php" class="<?php echo $current_page=='settings.php'?'active':''; ?>">
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

/* Sidebar layout */
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

/* Header */
.sidebar-header {
    background: linear-gradient(to bottom, #ffffff 0%, #ffffff 0%, #001a47 100%);
    padding: 2.5rem 1.5rem 2rem;  /* <-- Reduced top padding to match other pages */
    position: sticky;
    top: 0;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
}

/* Logo */
.logo {
    display:flex;
    justify-content:center;
    align-items:center;
    margin-bottom:1rem;  /* <-- smaller margin */
}

.logo-img {
    width:150px;
}

/* User Info */
.user-info {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    margin:0;
}

.user-info h6 { 
    margin:0; 
    font-weight:600; 
    font-size:16px; 
    line-height:1.2;
}

.user-info small { 
    font-size:13px; 
    opacity:0.8; 
    line-height:1.2;
}

/* Menu */
.sidebar-menu ul {
    list-style:none;
    padding:1.5rem 0 0 0;
    margin:0;
}

.sidebar-menu ul li a {
    display:flex;
    align-items:center;
    padding:1rem 1.5rem;
    margin:0.25rem 0;
    color:rgba(255,255,255,0.85);
    text-decoration:none;
    border-left:4px solid transparent;
    border-radius:0 12px 12px 0;
    font-weight:500;
    transition:0.3s;
}

.sidebar-menu ul li a i {
    width:24px;
    margin-right:12px;
    font-size:1.2rem;
}

.sidebar-menu ul li a:hover,
.sidebar-menu ul li a.active {
    background: rgba(255,255,255,0.12);
    border-left-color:#00d4ff;
    color:white;
    transform: translateX(4px);
}

/* Scrollbar */
.sidebar::-webkit-scrollbar { width:6px; }
.sidebar::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.3); border-radius:3px; }

@media (max-width:768px){
    .sidebar { transform:translateX(-100%); width:280px; }
}
</style>