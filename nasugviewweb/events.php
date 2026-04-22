<?php
session_start();

// ==============================
// Database connection
// ==============================
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "nasugview2";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// ==============================
// Fetch admin info
// ==============================
if(isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT fname, lname, designation FROM negosyo_center_users WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if($row = $result->fetch_assoc()){
        $admin_fullname = trim($row['fname'] . ' ' . $row['lname']);
        $designation    = $row['designation'];
    } else {
        $admin_fullname = "User";
        $designation    = "Unknown";
    }
} else {
    $admin_fullname = "User";
    $designation    = "Unknown";
}

// ==============================
// Fetch events with status calculation
// ==============================
$sql = "SELECT *, 
        CASE 
            WHEN NOW() < start_date_and_time THEN 'For Implementation'
            WHEN NOW() BETWEEN start_date_and_time AND end_date_and_time THEN 'Ongoing'
            WHEN NOW() > end_date_and_time THEN 'Implemented'
            ELSE status
        END AS calculated_status
        FROM events
        ORDER BY start_date_and_time DESC";

$events = $conn->query($sql);

// ==============================
// Get Events Count Dynamically
// ==============================
$totalEvents = $conn->query("SELECT COUNT(*) as total FROM events")->fetch_assoc()['total'] ?? 0;
$upcomingEvents = $conn->query("SELECT COUNT(*) as total FROM events WHERE start_date_and_time > NOW()")->fetch_assoc()['total'] ?? 0;
$pastEvents = $conn->query("SELECT COUNT(*) as total FROM events WHERE end_date_and_time < NOW()")->fetch_assoc()['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Events - NasugView</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<style>
body { font-family:'Poppins', sans-serif; background:#f0f4ff; }
.main-content { margin-left:250px; padding:2rem; min-height:100vh; }
.card { border-radius:18px; padding:2rem; background:#fff; border-left:6px solid #001a47; box-shadow:0 8px 25px rgba(0,0,0,0.08); }
.card h3 { color:#001a47; font-weight:700; margin-bottom:1.5rem; }

.form-control, .form-select, textarea { border-radius:10px; border:1px solid #d6e4ff; box-shadow:0 0 0 3px rgba(0,26,71,0.08); padding:8px 10px; height:44px; }
textarea.form-control { height:120px; resize:none; }
.form-control:focus, .form-select:focus, textarea:focus { border-color:#001a47; box-shadow:0 0 0 4px rgba(0,26,71,0.25); }

.btn-submit { background:linear-gradient(135deg,#001a47,#00308a); color:#fff; border-radius:10px; padding:10px 24px; font-weight:600; border:none; box-shadow:0 10px 22px rgba(0,26,71,0.18); }
.btn-submit:hover { background:linear-gradient(135deg,#00308a,#001a47); color:#fff; transform:translateY(-2px); box-shadow:0 14px 28px rgba(0,26,71,0.24); }

.btn-secondary { border-radius:10px; padding:10px 22px; border:none; color:#fff; background:linear-gradient(135deg,#001a47,#00308a); box-shadow:0 10px 22px rgba(0,26,71,0.18); }
.btn-secondary:hover { background:linear-gradient(135deg,#00308a,#001a47); color:#fff; transform:translateY(-2px); box-shadow:0 14px 28px rgba(0,26,71,0.24); }

.row.g-3 > div { margin-bottom:16px; }

.table th { background: linear-gradient(135deg,#001a47,#00308a); color:white; border:none; padding:1rem; font-weight:600; font-size:0.9rem; }
.table td { padding:1rem; vertical-align:middle; border-bottom:1px solid #f1f3f4; }

.action-buttons { display:flex; gap:0.5rem; align-items:center; }
.btn-action { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; border:none; transition: all 0.3s ease; background: linear-gradient(135deg,#001a47,#00308a); color:white; }
.btn-action:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,0.2); }

.dropdown-menu { min-width:150px; }

.table-full { width:100%; margin-top:1rem; }

#searchInput { margin-bottom:1rem; }

.modal-body p { margin-bottom:0.5rem; } /* simpler list look */

@media (max-width:992px) { .col-md-6 { flex:0 0 100%; max-width:100%; } }
</style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main-content">

    <!-- Events Stats -->
    <div class="row mb-4">
        <div class="col-md-4"><div class="card p-3 text-center"><h4>Total Events</h4><h2><?php echo $totalEvents; ?></h2></div></div>
        <div class="col-md-4"><div class="card p-3 text-center"><h4>Upcoming Events</h4><h2><?php echo $upcomingEvents; ?></h2></div></div>
        <div class="col-md-4"><div class="card p-3 text-center"><h4>Past Events</h4><h2><?php echo $pastEvents; ?></h2></div></div>
    </div>

    <!-- Events Table -->
    <div class="card p-3 table-full">
        <div class="d-flex justify-content-between mb-3">
            <input type="text" id="searchInput" class="form-control" placeholder="Search events...">
            <a href="create_event.php" class="btn btn-submit ms-2"><i class="fas fa-plus"></i> Create Event</a>
        </div>

        <table class="table table-hover mb-0" id="eventsTable">
            <thead>
                <tr>
                    <th>Event Title</th>
                    <th>Start & End Date</th>
                    <th>Duration</th>
                    <th>Event Code</th>
                    <th>Status</th>
                    <th>Remarks</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($event = $events->fetch_assoc()):
                    $start = strtotime($event['start_date_and_time']);
                    $end   = strtotime($event['end_date_and_time']);

                    $duration = "N/A";
                    if($end && $start) {
                        $seconds = $end - $start;
                        $days    = floor($seconds / 86400);
                        $hours   = floor(($seconds % 86400) / 3600);
                        $minutes = floor(($seconds % 3600) / 60);
                        $duration = ($days>0 ? $days."d ":"").($hours>0 ? $hours."h ":"").($minutes>0 ? $minutes."m":"");
                    }

                    $event_code = "EVT" . str_pad($event['id'], 4, "0", STR_PAD_LEFT);
                    $status = $event['calculated_status'];
                    $remarks = ($status == 'For Implementation') ? "Incoming" : (($status=='Ongoing') ? "In Progress" : "Done");
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($event['title']); ?></td>
                    <td><?php echo date("M d, Y h:i A", $start) . " - " . date("M d, Y h:i A", $end); ?></td>
                    <td><?php echo $duration; ?></td>
                    <td><?php echo $event_code; ?></td>
                    <td><?php echo htmlspecialchars($status); ?></td>
                    <td><?php echo $remarks; ?></td>
                    <td>
                        <div class="action-buttons">
                            <!-- View Button -->
                            <button class="btn-action viewBtn" 
                                data-title="<?php echo htmlspecialchars($event['title']); ?>" 
                                data-start="<?php echo date("M d, Y h:i A", $start); ?>" 
                                data-end="<?php echo date("M d, Y h:i A", $end); ?>" 
                                data-duration="<?php echo $duration; ?>" 
                                data-status="<?php echo $status; ?>" 
                                data-remarks="<?php echo $remarks; ?>"
                                data-address="<?php echo htmlspecialchars($event['address']); ?>"
                                data-mode="<?php echo htmlspecialchars($event['mode_of_delivery'] ?: 'N/A'); ?>"
                                data-speaker="<?php echo htmlspecialchars($event['speaker'] ?: 'N/A'); ?>"
                                data-audience="<?php echo htmlspecialchars($event['audience'] ?: 'N/A'); ?>"
                                data-budget="<?php echo htmlspecialchars($event['budget'] ?: 'N/A'); ?>"
                                data-funding="<?php echo htmlspecialchars($event['funding_source'] ?: 'N/A'); ?>"

                                data-description="<?php echo htmlspecialchars($event['description']); ?>"
                                data-event-id="<?php echo $event['id']; ?>"
                                title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>

                            <!-- Dropdown Actions -->
                            <div class="dropdown">
                                <button class="btn-action" data-bs-toggle="dropdown" title="More Actions"><i class="fas fa-ellipsis-h"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item downloadPDF" href="#" data-event-id="<?php echo $event['id']; ?>">Download PDF</a></li>
                                    <li><a class="dropdown-item viewPDF" href="#" data-event-id="<?php echo $event['id']; ?>">View as PDF</a></li>
                                    <li><a class="dropdown-item editEvent" href="edit_event.php?id=<?php echo $event['id']; ?>">Edit</a></li>
                                    <li><a class="dropdown-item rescheduleEvent" href="#" data-event-id="<?php echo $event['id']; ?>">Reschedule</a></li>
                                    <li><a class="dropdown-item text-warning cancelEvent" href="#" data-event-id="<?php echo $event['id']; ?>">Cancel</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item text-danger deleteEvent" href="#" data-event-id="<?php echo $event['id']; ?>">Delete</a></li>
                                </ul>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- View Details Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:#001a47; color:white;">
        <h5 class="modal-title">Event Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p><strong>Title:</strong> <span id="modalTitle"></span></p>
        <p><strong>Start:</strong> <span id="modalStart"></span></p>
        <p><strong>End:</strong> <span id="modalEnd"></span></p>
        <p><strong>Duration:</strong> <span id="modalDuration"></span></p>
        <p><strong>Status:</strong> <span id="modalStatus"></span></p>
        <p><strong>Remarks:</strong> <span id="modalRemarks"></span></p>
        <p><strong>Address / Venue:</strong> <span id="modalAddress"></span></p>
        <p><strong>Mode of Delivery:</strong> <span id="modalMode"></span></p>
        <p><strong>Resource Speaker:</strong> <span id="modalSpeaker"></span></p>
        <p><strong>Target Audience:</strong> <span id="modalAudience"></span></p>
        <p><strong>Budget:</strong> <span id="modalBudget"></span></p>
        <p><strong>Funding Source:</strong> <span id="modalFunding"></span></p>
        <p><strong>Description / Remarks:</strong> <span id="modalDescription"></span></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// =====================
// Instant Search
// =====================
const searchInput = document.getElementById('searchInput');
const tableRows = document.querySelectorAll('#eventsTable tbody tr');
searchInput.addEventListener('input', function() {
    const filter = this.value.toLowerCase();
    tableRows.forEach(row => {
        const title = row.cells[0].textContent.toLowerCase();
        row.style.display = title.includes(filter) ? '' : 'none';
    });
});

// =====================
// View Details Modal
// =====================
const viewButtons = document.querySelectorAll('.viewBtn');
const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));

viewButtons.forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('modalTitle').textContent   = btn.dataset.title;
        document.getElementById('modalStart').textContent   = btn.dataset.start;
        document.getElementById('modalEnd').textContent     = btn.dataset.end;
        document.getElementById('modalDuration').textContent= btn.dataset.duration;
        document.getElementById('modalStatus').textContent  = btn.dataset.status;
        document.getElementById('modalRemarks').textContent = btn.dataset.remarks;
        document.getElementById('modalAddress').textContent = btn.dataset.address;
        document.getElementById('modalMode').textContent    = btn.dataset.mode;
        document.getElementById('modalSpeaker').textContent = btn.dataset.speaker;
        document.getElementById('modalAudience').textContent= btn.dataset.audience;
        document.getElementById('modalBudget').textContent  = btn.dataset.budget;
        document.getElementById('modalFunding').textContent = btn.dataset.funding;
        document.getElementById('modalDescription').textContent = btn.dataset.description;
        viewModal.show();
    });
});

// =====================
// Dropdown Option Actions
// =====================
document.querySelectorAll('.downloadPDF').forEach(btn => {
    btn.addEventListener('click', e => {
        e.preventDefault();
        const eventId = btn.dataset.eventId;
        window.location.href = `download_event.php?id=${eventId}`;
    });
});

document.querySelectorAll('.viewPDF').forEach(btn => {
    btn.addEventListener('click', e => {
        e.preventDefault();
        const eventId = btn.dataset.eventId;
        window.open(`view_event_pdf.php?id=${eventId}`, '_blank');
    });
});

document.querySelectorAll('.rescheduleEvent').forEach(btn => {
    btn.addEventListener('click', e => {
        e.preventDefault();
        const eventId = btn.dataset.eventId;
        Swal.fire({
            title: 'Reschedule Event',
            html: `
                <label>Start Date & Time:</label>
                <input type="datetime-local" id="newStart" class="swal2-input">
                <label>End Date & Time:</label>
                <input type="datetime-local" id="newEnd" class="swal2-input">
            `,
            confirmButtonText: 'Update',
            showCancelButton: true,
            preConfirm: () => {
                const start = document.getElementById('newStart').value;
                const end = document.getElementById('newEnd').value;
                if(!start || !end) Swal.showValidationMessage('Please fill both dates');
                return {start, end};
            }
        }).then(result => {
            if(result.isConfirmed){
                fetch('reschedule_event.php', {
                    method:'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({id:eventId, start:result.value.start, end:result.value.end})
                })
                .then(res => res.json())
                .then(data => {
                    if(data.success) Swal.fire('Updated!','Event rescheduled.','success').then(()=> location.reload());
                    else Swal.fire('Error', data.error, 'error');
                });
            }
        });
    });
});

document.querySelectorAll('.cancelEvent').forEach(btn => {
    btn.addEventListener('click', e => {
        e.preventDefault();
        const eventId = btn.dataset.eventId;
        Swal.fire({
            title:'Cancel Event?',
            text:'This will mark the event as canceled.',
            icon:'warning',
            showCancelButton:true,
            confirmButtonText:'Yes, Cancel it'
        }).then(result=>{
            if(result.isConfirmed){
                fetch('cancel_event.php', {
                    method:'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({id:eventId})
                })
                .then(res => res.json())
                .then(data => {
                    if(data.success) Swal.fire('Canceled!','Event marked as canceled.','success').then(()=> location.reload());
                    else Swal.fire('Error', data.error, 'error');
                });
            }
        });
    });
});

document.querySelectorAll('.deleteEvent').forEach(btn => {
    btn.addEventListener('click', e => {
        e.preventDefault();
        const eventId = btn.dataset.eventId;
        Swal.fire({
            title:'Delete Event?',
            text:'This action is permanent!',
            icon:'warning',
            showCancelButton:true,
            confirmButtonText:'Yes, delete'
        }).then(result=>{
            if(result.isConfirmed){
                fetch('delete_event.php', {
                    method:'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({id:eventId})
                })
                .then(res => res.json())
                .then(data => {
                    if(data.success) Swal.fire('Deleted!','Event removed permanently.','success').then(()=> location.reload());
                    else Swal.fire('Error', data.error, 'error');
                });
            }
        });
    });
});
</script>
</body>
</html>
