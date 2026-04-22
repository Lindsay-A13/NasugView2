<?php
session_start();

/* DATABASE CONNECTION */
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "nasugview2";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) die("Database connection failed: " . $conn->connect_error);

/* DEFAULT VALUES */
$admin_fullname = "User";
$designation = "";

/* GET LOGGED IN ADMIN INFO */
if (isset($_SESSION['user_id'])) {
    $id = $_SESSION['user_id'];

    $stmt = $conn->prepare("SELECT username, fname, lname, designation FROM dti_user WHERE dti_id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $fname = trim($row['fname']);
        $lname = trim($row['lname']);
        $username = trim($row['username']);

        $admin_fullname = ($fname || $lname)
            ? trim($fname . ' ' . $lname)
            : $username;

        $designation = $row['designation']; // FIXED
    }
}
/* DASHBOARD COUNTS */
$totalUsers = $conn->query("SELECT COUNT(*) as total FROM dti_user")->fetch_assoc()['total'] ?? 0;
$totalAdmins = $conn->query("SELECT COUNT(*) as total FROM dti_user WHERE designation='Admin'")->fetch_assoc()['total'] ?? 0;
$totalStaff = $conn->query("SELECT COUNT(*) as total FROM dti_user WHERE designation='Staff'")->fetch_assoc()['total'] ?? 0;

/* EVENTS FILTERS */
$year = isset($_GET['year']) ? trim($_GET['year']) : '';
$month = isset($_GET['month']) ? trim($_GET['month']) : '';
$day = isset($_GET['day']) ? trim($_GET['day']) : '';

$year = preg_match('/^\d{4}$/', $year) ? $year : '';
$month = preg_match('/^\d{4}-\d{2}$/', $month) ? $month : '';
$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : '';

$filterType = 'year';
$filterValue = $year !== '' ? $year : date('Y');

if ($day !== '') {
    $filterType = 'day';
    $filterValue = $day;
} elseif ($month !== '') {
    $filterType = 'month';
    $filterValue = $month;
}

$chartTitle = 'Events Created (Monthly)';
$monthLabels = [];
$counts = [];
$query = '';
$params = [];
$paramTypes = '';

if ($day !== '') {
    $chartTitle = 'Events Created on ' . date('F j, Y', strtotime($day));
    $query = "SELECT DATE(created_at) AS label, COUNT(*) AS total
              FROM events
              WHERE DATE(created_at) = ?
              GROUP BY DATE(created_at)";
    $params[] = $day;
    $paramTypes = 's';
    $monthLabels = [date('M j, Y', strtotime($day))];
    $counts = [0];
} elseif ($month !== '') {
    $chartTitle = 'Events Created in ' . date('F Y', strtotime($month . '-01'));
    $daysInMonth = (int) date('t', strtotime($month . '-01'));
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $monthLabels[] = str_pad((string) $d, 2, '0', STR_PAD_LEFT);
        $counts[] = 0;
    }

    $query = "SELECT DAY(created_at) AS label, COUNT(*) AS total
              FROM events
              WHERE DATE_FORMAT(created_at, '%Y-%m') = ?
              GROUP BY DAY(created_at)
              ORDER BY DAY(created_at) ASC";
    $params[] = $month;
    $paramTypes = 's';
} else {
    $selectedYear = $year !== '' ? $year : date('Y');
    $chartTitle = 'Events Created in ' . $selectedYear;

    for ($m = 1; $m <= 12; $m++) {
        $monthLabels[] = date('M', mktime(0, 0, 0, $m, 1));
        $counts[] = 0;
    }

    $query = "SELECT MONTH(created_at) AS label, COUNT(*) AS total
              FROM events
              WHERE YEAR(created_at) = ?
              GROUP BY MONTH(created_at)
              ORDER BY MONTH(created_at) ASC";
    $params[] = $selectedYear;
    $paramTypes = 'i';
}

$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param($paramTypes, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $index = (int) $row['label'] - 1;

        if ($day !== '') {
            $counts[0] = (int) $row['total'];
            continue;
        }

        if (isset($counts[$index])) {
            $counts[$index] = (int) $row['total'];
        }
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard - NasugView</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
:root {
    --primary-color: #001a47; 
    --secondary-color: #f8f9fa; 
    --gradient-start: #001a47; 
    --gradient-end: #00308a;
}
body { margin:0; padding:0; font-family:Poppins,sans-serif; min-height:100vh; overflow-x:hidden; background:linear-gradient(135deg,var(--gradient-start)0%,var(--gradient-end)100%); }
.main-content { margin-left:250px; background:var(--secondary-color); min-height:100vh; padding:3rem 2rem; }
.content-wrapper { max-width:1400px; margin:0 auto; }
.welcome-card { background:linear-gradient(135deg,var(--primary-color),var(--gradient-end)); color:white; border-radius:20px; padding:2.5rem; margin-bottom:2rem; box-shadow:0 10px 30px rgba(0,26,71,0.3); position:relative; overflow:hidden; }
.welcome-card::before { content:''; position:absolute; top:-50%; right:-20%; width:200px; height:200px; background:rgba(255,255,255,0.1); border-radius:50%; }
.welcome-card::after { content:''; position:absolute; bottom:-30%; left:-10%; width:150px; height:150px; background:rgba(255,255,255,0.05); border-radius:50%; }
.dashboard-card { background:white; border-radius:20px; padding:2rem; margin-bottom:1.5rem; box-shadow:0 5px 25px rgba(0,0,0,0.08); border:none; transition:all 0.3s ease; position:relative; overflow:hidden; }
.dashboard-card:hover { transform:translateY(-8px); box-shadow:0 15px 35px rgba(0,0,0,0.15); }
.card-icon { width:70px; height:70px; border-radius:16px; display:flex; align-items:center; justify-content:center; margin-bottom:1.5rem; font-size:1.8rem; background:rgba(0,26,71,0.1); color:#001a47; }
.card-value { font-size:2.2rem; font-weight:700; margin:0.5rem 0; background:linear-gradient(135deg,var(--primary-color),var(--gradient-end)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.card-title { font-weight:600; color:var(--primary-color); margin-bottom:1rem; font-size:1.2rem; }
.quick-action-btn { background:linear-gradient(135deg,var(--primary-color),var(--gradient-end)); border:none; border-radius:12px; padding:1rem 1.5rem; font-weight:600; color:white; transition:all 0.3s ease; width:100%; margin-bottom:0.5rem; font-size:0.95rem; position:relative; overflow:hidden; }
.quick-action-btn:hover { transform:translateY(-3px); box-shadow:0 8px 25px rgba(0,26,71,0.3); }
.filter-input { border:1px solid rgba(0,26,71,0.2); color:var(--primary-color); background:#fff; }
.filter-input:focus { border-color:var(--primary-color); box-shadow:0 0 0 0.2rem rgba(0,26,71,0.15); }
.filter-btn { background:linear-gradient(135deg,var(--primary-color),var(--gradient-end)); border:none; color:#fff; }
.filter-btn:hover,
.filter-btn:focus,
.filter-btn:active,
.filter-btn.show { background:linear-gradient(135deg,var(--primary-color),var(--gradient-end)) !important; border-color:transparent !important; color:#fff !important; box-shadow:0 0 0 0.2rem rgba(0,26,71,0.15) !important; }
.filter-reset { border:1px solid rgba(0,26,71,0.2); color:var(--primary-color); background:#fff; }
.filter-reset:hover,
.filter-reset:focus,
.filter-reset:active { border-color:var(--primary-color) !important; color:var(--primary-color) !important; background:#fff !important; box-shadow:0 0 0 0.2rem rgba(0,26,71,0.15) !important; }
.filter-wrap { display:flex; justify-content:flex-end; }
.filter-toggle { width:42px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:12px; }
.filter-menu { min-width:220px; padding:1rem; border:none; border-radius:16px; box-shadow:0 12px 32px rgba(0,0,0,0.12); background:#fff; }
.filter-form { display:flex; flex-direction:column; gap:0.75rem; }
.filter-options { display:flex; gap:0.5rem; }
.filter-option { flex:1; border:1px solid rgba(0,26,71,0.2); background:#fff; color:var(--primary-color); border-radius:10px; padding:0.45rem 0.5rem; font-size:0.85rem; font-weight:500; }
.filter-option.active { background:linear-gradient(135deg,var(--primary-color),var(--gradient-end)); border-color:transparent; color:#fff; }
.filter-value { width:100%; }
.filter-actions { display:flex; gap:0.5rem; }
.filter-actions .btn { flex:1; }
.stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:1.5rem; margin-bottom:2rem; }
.floating-shapes { position:absolute; top:0; left:0; right:0; bottom:0; pointer-events:none; overflow:hidden; z-index:0; }
.shape { position:absolute; border-radius:50%; background: rgba(189, 187, 219, 0.14); animation: float 6s ease-in-out infinite; }
.shape-1 { width: 80px; height: 80px; top: 10%; right: 10%; animation-delay: 0s; }
.shape-2 { width: 60px; height: 60px; bottom: 5%; right: 80%; animation-delay: 1s; }
@keyframes float { 0%, 100% { transform: translateY(0) rotate(0deg); } 50% { transform: translateY(-20px) rotate(180deg); } }

.chart-wrapper { overflow-x: auto; padding-bottom: 10px; }
#eventsChart { height: 250px; min-width: 1100px; }
</style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="content-wrapper">

        <!-- Welcome Card -->
        <div class="welcome-card">
            <div class="row align-items-center position-relative" style="z-index:1;">
                <div class="col-md-8">
                    <h3 class="fw-bold mb-2">Welcome, <?php echo $fname; ?>! 👋</h3>
                    <p class="mb-0 opacity-90">Monitor and manage your system efficiently</p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="bg-white bg-opacity-10 rounded-pill px-3 py-2 d-inline-block">
                        <small><i class="fas fa-clock me-1"></i><?php echo date('l, F j, Y'); ?></small>
                    </div>
                </div>
            </div>
            <div class="floating-shapes">
                <div class="shape shape-1"></div>
                <div class="shape shape-2"></div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="dashboard-card users-card">
                <div class="card-icon"><i class="fas fa-users"></i></div>
                <h6 class="card-title">Total Users</h6>
                <div class="card-value"><?php echo number_format($totalUsers); ?></div>
                <a href="manage_users.php" class="quick-action-btn mt-3 d-block text-center">
                    <i class="fas fa-user-cog"></i> Manage Users
                </a>                     
            </div>
            <div class="dashboard-card admins-card">
                <div class="card-icon"><i class="fas fa-user-shield"></i></div>
                <h6 class="card-title">Total Admins</h6>
                <div class="card-value"><?php echo number_format($totalAdmins); ?></div>
                <a href="add_admin.php" class="quick-action-btn mt-3 d-block text-center">
                    <i class="fas fa-user-plus"></i> Add Admin
                </a>
            </div>
            <div class="dashboard-card staff-card">
                <div class="card-icon"><i class="fas fa-user-tie"></i></div>
                <h6 class="card-title">Total Staff</h6>
                <div class="card-value"><?php echo number_format($totalStaff); ?></div>
                <a href="view_staff.php" class="quick-action-btn mt-3 d-block text-center">
                    <i class="fas fa-search"></i> View Staff
                </a>
            </div>
        </div>

        <!-- Events Chart Card -->
        <div class="dashboard-card chart-wrapper">
            <div class="row align-items-center mb-3">
                <div class="col-md-6">
                    <h6 class="card-title"><?php echo htmlspecialchars($chartTitle); ?></h6>
                </div>
                <div class="col-md-6 text-end">
                    <div class="dropdown filter-wrap">
                        <button id="filterToggle" class="btn btn-sm filter-btn filter-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Filter chart">
                            <i class="fas fa-calendar-alt"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end filter-menu">
                            <form id="filterForm" class="filter-form">
                                <div class="filter-options">
                                    <button type="button" class="filter-option <?php echo $filterType === 'year' ? 'active' : ''; ?>" data-type="year">Year</button>
                                    <button type="button" class="filter-option <?php echo $filterType === 'month' ? 'active' : ''; ?>" data-type="month">Month</button>
                                    <button type="button" class="filter-option <?php echo $filterType === 'day' ? 'active' : ''; ?>" data-type="day">Day</button>
                                </div>
                                <input
                                    id="filterValue"
                                    class="form-control form-control-sm filter-input filter-value"
                                    value="<?php echo htmlspecialchars($filterValue); ?>"
                                    data-year="<?php echo htmlspecialchars($year !== '' ? $year : date('Y')); ?>"
                                    data-month="<?php echo htmlspecialchars($month); ?>"
                                    data-day="<?php echo htmlspecialchars($day); ?>"
                                >
                                <input type="hidden" id="filterType" value="<?php echo htmlspecialchars($filterType); ?>">
                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-sm filter-btn">Apply</button>
                                    <a href="dashboard.php" class="btn btn-sm filter-reset">Reset</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <canvas id="eventsChart"></canvas>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
const ctx = document.getElementById('eventsChart').getContext('2d');

const eventsChart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($monthLabels); ?>,
        datasets: [{
            label: 'Events Created',
            data: <?php echo json_encode($counts); ?>,
            backgroundColor: '#001a47',
            borderColor: '#001a47',
            borderWidth: 1,
            barThickness: 40
        }]
    },
    options: {
        responsive: false,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { backgroundColor: '#001a47', titleColor: '#fff', bodyColor: '#fff' }
        },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#001a47', font: { weight: 500 }, maxRotation: 0 } },
            y: { beginAtZero: true, ticks: { color: '#001a47', stepSize: 1 }, grid: { color: 'rgba(0,26,71,0.1)' } }
        }
    },
    plugins: [{
        id: 'bar3d',
        afterDatasetDraw(chart) {
            const {ctx} = chart;
            ctx.save();
            chart.getDatasetMeta(0).data.forEach(bar => {
                const barWidth = bar.width;
                const barHeight = bar.height;
                const xPos = bar.x - barWidth/2;
                const yPos = bar.y;
                const depth = 10;

                // Top face
                ctx.fillStyle = '#00308a';
                ctx.beginPath();
                ctx.moveTo(xPos, yPos);
                ctx.lineTo(xPos + depth, yPos - depth);
                ctx.lineTo(xPos + barWidth + depth, yPos - depth);
                ctx.lineTo(xPos + barWidth, yPos);
                ctx.closePath();
                ctx.fill();

                // Side face
                ctx.fillStyle = '#001a47';
                ctx.beginPath();
                ctx.moveTo(xPos + barWidth, yPos);
                ctx.lineTo(xPos + barWidth + depth, yPos - depth);
                ctx.lineTo(xPos + barWidth + depth, yPos - depth + barHeight);
                ctx.lineTo(xPos + barWidth, yPos + barHeight);
                ctx.closePath();
                ctx.fill();
            });
            ctx.restore();
        }
    }]
});

const filterType = document.getElementById('filterType');
const filterValue = document.getElementById('filterValue');
const filterOptions = document.querySelectorAll('.filter-option');

function syncFilterInput() {
    const selectedType = filterType.value;
    filterValue.name = selectedType;

    filterOptions.forEach(option => {
        option.classList.toggle('active', option.dataset.type === selectedType);
    });

    if (selectedType === 'year') {
        filterValue.type = 'number';
        filterValue.min = '2000';
        filterValue.max = '2100';
        filterValue.placeholder = 'Year';
        filterValue.value = filterValue.dataset.year || new Date().getFullYear();
    } else if (selectedType === 'month') {
        filterValue.type = 'month';
        filterValue.removeAttribute('min');
        filterValue.removeAttribute('max');
        filterValue.placeholder = '';
        filterValue.value = filterValue.dataset.month || '';
    } else {
        filterValue.type = 'date';
        filterValue.removeAttribute('min');
        filterValue.removeAttribute('max');
        filterValue.placeholder = '';
        filterValue.value = filterValue.dataset.day || '';
    }
}

filterOptions.forEach(option => {
    option.addEventListener('click', function () {
        filterType.value = this.dataset.type;
        syncFilterInput();
    });
});

syncFilterInput();

document.getElementById('filterForm').addEventListener('submit', function(e){
    e.preventDefault();

    filterValue.dataset.year = filterType.value === 'year' ? filterValue.value : filterValue.dataset.year;
    filterValue.dataset.month = filterType.value === 'month' ? filterValue.value : filterValue.dataset.month;
    filterValue.dataset.day = filterType.value === 'day' ? filterValue.value : filterValue.dataset.day;

    const params = new URLSearchParams();
    if (filterValue.value) {
        params.set(filterType.value, filterValue.value);
    }

    window.location.href = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
});
</script>

</body>
</html>ript>

</body>
</html>
