<?php
session_start();

/* DATABASE CONNECTION */
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "nasugview2";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) die("Database connection failed: " . $conn->connect_error);

/* GET LOGGED IN ADMIN INFO */
if (isset($_SESSION['user_id'])) {
    $id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, fname, lname FROM negosyo_center_users WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $fname = trim($row['fname']);
        $lname = trim($row['lname']);
        $username = trim($row['username']);
        $admin_fullname = ($fname || $lname) ? trim($fname.' '.$lname) : $username;
    }
}

/* DASHBOARD COUNTS */
$totalUsers = $conn->query("SELECT COUNT(*) as total FROM negosyo_center_users")->fetch_assoc()['total'] ?? 0;
$totalAdmins = $conn->query("SELECT COUNT(*) as total FROM negosyo_center_users WHERE designation='Admin'")->fetch_assoc()['total'] ?? 0;
$totalStaff = $conn->query("SELECT COUNT(*) as total FROM negosyo_center_users WHERE designation='Staff'")->fetch_assoc()['total'] ?? 0;
$totalAttendees = $conn->query("SELECT COUNT(*) as total FROM event_registrations")
                       ->fetch_assoc()['total'] ?? 0; 
/* ===== MEETING ATTENDEES DATA ===== */
$meetingLabels = [];
$meetingCounts = [];

$meetingQuery = "
    SELECT DATE(created_at) as meeting_date, COUNT(*) as total
    FROM event_registrations
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at) ASC
";

$meetingResult = $conn->query($meetingQuery);

if ($meetingResult) {
    while ($row = $meetingResult->fetch_assoc()) {
        $meetingLabels[] = date("M d", strtotime($row['meeting_date']));
        $meetingCounts[] = (int)$row['total'];
    }
}
/* EVENTS DATA BY MONTH */
$month = $_GET['month'] ?? '';

$where = [];
if ($month) {
    $where[] = "DATE_FORMAT(created_at, '%Y-%m') = '$month'";
}

$whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

$sql = "SELECT DATE_FORMAT(created_at, '%Y-%m') as event_month, COUNT(*) as total
        FROM events
        $whereSQL
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY DATE_FORMAT(created_at, '%Y-%m') ASC";
$result = $conn->query($sql);

// Initialize all months with 0 count
$allMonths = [];
for ($m = 1; $m <= 12; $m++) {
    $monthNum = str_pad($m, 2, '0', STR_PAD_LEFT);
    $allMonths[$monthNum] = 0;
}

// Fill counts from database
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $monthNum = date('m', strtotime($row['event_month'] . '-01'));
        $allMonths[$monthNum] = (int)$row['total'];
    }
}

// Labels and counts for chart
$monthLabels = [];
$counts = [];
foreach ($allMonths as $num => $count) {
    $monthLabels[] = date('M', mktime(0,0,0,$num,1));
    $counts[] = $count;
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
.content-wrapper{
    width:100%;
    max-width:100%;
    margin:0;
    padding:0 10px;
}
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
.stats-grid{
    display:grid;
    grid-template-columns: repeat(5, 1fr);
    gap:1.5rem;
    margin-bottom:2rem;
}
.floating-shapes { position:absolute; top:0; left:0; right:0; bottom:0; pointer-events:none; overflow:hidden; z-index:0; }
.shape { position:absolute; border-radius:50%; background: rgba(189, 187, 219, 0.14); animation: float 6s ease-in-out infinite; }
.shape-1 { width: 80px; height: 80px; top: 10%; right: 10%; animation-delay: 0s; }
.shape-2 { width: 60px; height: 60px; bottom: 5%; right: 80%; animation-delay: 1s; }
@keyframes float { 0%, 100% { transform: translateY(0) rotate(0deg); } 50% { transform: translateY(-20px) rotate(180deg); } }

.chart-wrapper { overflow-x: auto; padding-bottom: 10px; }
#eventsChart{
    height:240px !important;
    width:100% !important;
}

.chart-small{
    height:320px;
    width:100%;

}

.blue-card{
    background: linear-gradient(135deg,#0d2f6b,#001a47);
    color:white;
    border-radius:18px;
    padding:22px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
}

.blue-card h2{
    font-size:42px;
    font-weight:700;
    margin:0;
}

.stats-row{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
    gap:1.5rem;
    margin-bottom:2rem;
}

.dashboard-split{
    display:grid;
    grid-template-columns:2fr 1fr;
    gap:1.5rem;
    margin-bottom:2rem;
}

#municipalityChart,
#meetingChart{
    height:250px !important;
}

.white-card{
    background:white;
    border-radius:18px;
    padding:20px;
    box-shadow:0 5px 20px rgba(0,0,0,.06);
}

.leading-item{
    display:flex;
    align-items:center;
    margin-bottom:18px;
}

.rank{
    font-size:26px;
    font-weight:700;
    width:35px;
}

.counter{
    transition: all .4s ease;
}
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

    <div class="dashboard-card">
        <div class="card-icon">
            <i class="fas fa-calendar-alt"></i>
        </div>
        <h6 class="card-title">Total Events</h6>
        <div class="card-value counter" data-target="4">0</div>
    </div>

    <div class="dashboard-card">
    <div class="card-icon">
        <i class="fas fa-user-check"></i>
    </div>
    <h6 class="card-title">Total Attendees</h6>
    <div class="card-value counter" data-target="<?php echo $totalAttendees; ?>">0</div>
</div>

</div>

<div class="stats-grid">

  

</div>
</div>


<div class="dashboard-split">

    <div class="white-card">
        <div class="d-flex justify-content-between mb-2">
            <h6>Number of invited Attendees by Municipality</h6>

            <select class="form-select form-select-sm" style="width:150px">
                <option>Month</option>
                <option>January</option>
                <option>February</option>
                <option>March</option>
                <option>April</option>
                <option>May</option>
                <option>June</option>
                <option>July</option>
                <option>August</option>
                <option>September</option>
                <option>October</option>
                <option>November</option>
                <option>December</option>
            </select>

        </div>

        <canvas id="municipalityChart"></canvas>
    </div>


    <div>

        <div class="white-card mb-3">
            <h6>Leading Businesses</h6>

            <div class="leading-item">
                <div class="rank">1</div>
                Chez Deo Nasugbu
            </div>

            <div class="leading-item">
                <div class="rank">2</div>
                Tali Beach
            </div>

            <div class="leading-item">
                <div class="rank">3</div>
                Kainan sa Dalampasigan
            </div>

        </div>


        <div class="white-card">
            <div class="d-flex justify-content-between mb-2">
                <h6>Meeting attendees</h6>

                <select class="form-select form-select-sm" style="width:150px">
                    <option>Month</option>
                </select>
            </div>

            <canvas id="meetingChart"></canvas>
        </div>

    </div>

</div>
<div class="white-card chart-small">
                <div class="row align-items-center mb-3">
                <div class="col-md-6">
                    <h6 class="card-title">Events Created (Monthly)</h6>
                </div>
                <div class="col-md-6 text-end">
                    <!-- Single Month Picker -->
                    <form id="filterForm" class="d-flex justify-content-end gap-2">
                        <input type="month" name="month" class="form-control form-control-sm" value="<?php echo $month; ?>">
                        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    </form>
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
    responsive: true,
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

document.getElementById('filterForm').addEventListener('submit', function(e){
    e.preventDefault();
    const params = new URLSearchParams(new FormData(this));
    window.location.href = window.location.pathname + '?' + params.toString();
});

/* ===== ANIMATION COUNTER ===== */
const counters = document.querySelectorAll('.counter');

counters.forEach(counter => {
    const updateCount = () => {
        const target = +counter.getAttribute('data-target');
        const count = +counter.innerText;
        const increment = target / 80;

        if(count < target){
            counter.innerText = Math.ceil(count + increment);
            setTimeout(updateCount, 15);
        } else {
            counter.innerText = target;
        }
    };
    updateCount();
});


/* ===== MUNICIPALITY CHART ===== */
new Chart(document.getElementById('municipalityChart'), {
    type: 'line',
    data: {
        labels: ['Event 1','Event 2','Event 3','Event 4'],
        datasets: [
            {
                label:'Nasugbu',
                data:[18,20,31,28],
                borderColor:'#1b5e20',
                tension:.4
            },
            {
                label:'Calatagan',
                data:[12,33,14,25],
                borderColor:'#0d47a1',
                tension:.4
            },
            {
                label:'Balayan',
                data:[27,9,26,13],
                borderColor:'#c62828',
                tension:.4
            }
        ]
    },
    options:{
        plugins:{legend:{position:'top'}},
        scales:{y:{beginAtZero:true}}
    }
});


/* ===== MEETING CHART (REAL DATA) ===== */
new Chart(document.getElementById('meetingChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($meetingLabels); ?>,
        datasets: [{
            label:'Attendees',
            data: <?php echo json_encode($meetingCounts); ?>,
            backgroundColor:[
                '#0d2f6b',
                '#00308a',
                '#001a47',
                '#021f5a',
                '#123a8c',
                '#0b2c63'
            ]
        }]
    },
    options:{
        responsive:true,
        maintainAspectRatio:false,
        plugins:{
            legend:{display:false}
        },
        scales:{
            y:{
                beginAtZero:true,
                ticks:{stepSize:1}
            }
        }
    }
});
</script>

</body>
</html>