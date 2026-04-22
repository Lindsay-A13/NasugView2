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
// Initialize variables
// ==============================
$success = $error = "";

// Business Owners form fields
$title = $mode = $start_date = $end_date = $speaker = $budget = $address = $audience = $funding = $description = "";

// Consumers form fields
$c_title = $c_start_date = $c_end_date = $c_description = $c_address = "";

// ==============================
// Handle form submission
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $created_at = date("Y-m-d H:i:s"); // current date and time

    if(isset($_POST['form_type']) && $_POST['form_type'] == 'business') {
        // ==============================
        // Business Owners Form
        // ==============================
        $title       = $conn->real_escape_string($_POST['title']);
        $mode        = $conn->real_escape_string($_POST['mode']);
        $start_date  = $conn->real_escape_string($_POST['start_date']);
        $end_date    = $conn->real_escape_string($_POST['end_date']);
        $speaker     = $conn->real_escape_string($_POST['resource_speaker']);
        $budget      = $conn->real_escape_string($_POST['budget']);
        $address     = $conn->real_escape_string($_POST['address']);
        $audience    = $conn->real_escape_string($_POST['audience']);
        $funding     = $conn->real_escape_string($_POST['funding']);
        $description = $conn->real_escape_string($_POST['description']);

        if ($title && $mode && $start_date && $end_date) {

            $start_dt = new DateTime($start_date);
            $end_dt   = new DateTime($end_date);
            $interval = $start_dt->diff($end_dt);

            if ($interval->days > 0) {
                $hours = $interval->h;
                $duration = $interval->days . " day" . ($interval->days > 1 ? "s" : "");
                if ($hours > 0) $duration .= " " . $hours . " hr" . ($hours > 1 ? "s" : "");
            } else {
                $duration = $interval->h . " hr" . ($interval->h > 1 ? "s" : "") . " " . $interval->i . " min";
            }

            $now = new DateTime();
            if ($now < $start_dt) { $status = "For Implementation"; $remarks = "For Future"; }
            elseif ($now >= $start_dt && $now <= $end_dt) { $status = "Ongoing"; $remarks = "In Progress"; }
            else { $status = "Implemented"; $remarks = "Done"; }

            $stmt = $conn->prepare("
                INSERT INTO events
                (title, mode_of_delivery, start_date_and_time, end_date_and_time, speaker, budget, address, audience, funding_source, description, duration, status, remarks, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "ssssssssssssss",
                $title, $mode, $start_date, $end_date, $speaker, $budget,
                $address, $audience, $funding, $description, $duration, $status, $remarks, $created_at
            );

            if ($stmt->execute()) {
                $success = "Event for Business Owners created successfully!";
                $title = $mode = $start_date = $end_date = $speaker = $budget = $address = $audience = $funding = $description = "";
            } else {
                $error = "Error creating event: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Please fill all required fields for Business Owners form.";
        }

    } elseif(isset($_POST['form_type']) && $_POST['form_type'] == 'consumer') {
        // ==============================
        // Consumers Form
        // ==============================
        $c_title       = $conn->real_escape_string($_POST['c_title']);
        $c_start_date  = $conn->real_escape_string($_POST['c_start_date']);
        $c_end_date    = $conn->real_escape_string($_POST['c_end_date']);
        $c_description = $conn->real_escape_string($_POST['c_description']);
        $c_address     = $conn->real_escape_string($_POST['c_address']);

        if ($c_title && $c_start_date && $c_end_date) {
            $stmt = $conn->prepare("
                INSERT INTO events
                (title, start_date_and_time, end_date_and_time, address, description, duration, status, remarks, created_at)
                VALUES (?, ?, ?, ?, ?, '', 'For Implementation', '', ?)
            ");
            $stmt->bind_param(
                "ssssss",
                $c_title, $c_start_date, $c_end_date, $c_address, $c_description, $created_at
            );
            if ($stmt->execute()) {
                $success = "Event for Consumers created successfully!";
                $c_title = $c_start_date = $c_end_date = $c_description = $c_address = "";
            } else {
                $error = "Error creating event for Consumers: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Please fill all required fields for Consumers form.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Event - NasugView</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body { background-color: #f0f4ff; font-family: 'Poppins', sans-serif; padding: 30px 0; }
.card { max-width: 950px; margin: 0 auto; padding: 2rem; border-radius: 18px; background: #fff; border-left: 7px solid #001a47; box-shadow: 0 8px 30px rgba(0,0,0,0.08); }
.card h3 { color:#001a47; font-weight:700; margin-bottom:1.5rem; }
.row.g-3 > div { margin-bottom:16px; }
.form-control, .form-select, textarea { border-radius: 10px; border:1px solid #d6e4ff; box-shadow: 0 0 0 3px rgba(0,26,71,0.08); height:44px; padding: 8px 10px; }
textarea.form-control { height:120px; resize:none; }
.form-control:focus, .form-select:focus, textarea:focus { border-color: #001a47 !important; box-shadow: 0 0 0 4px rgba(0,26,71,0.25) !important; }
.btn-submit { background: linear-gradient(135deg,#001a47,#00308a) !important; color:#fff !important; border-radius:10px; padding:10px 24px; font-weight:600; border:none; box-shadow:0 10px 22px rgba(0,26,71,0.18); }
.btn-submit:hover { background: linear-gradient(135deg,#00308a,#001a47) !important; color:#fff !important; transform:translateY(-2px); box-shadow:0 14px 28px rgba(0,26,71,0.24); }
.btn-secondary { background: linear-gradient(135deg,#001a47,#00308a); border-radius:10px; padding:10px 22px; border:none; color:#fff; box-shadow:0 10px 22px rgba(0,26,71,0.18); }
.btn-secondary:hover { background: linear-gradient(135deg,#00308a,#001a47); color:#fff; transform:translateY(-2px); box-shadow:0 14px 28px rgba(0,26,71,0.24); }
@media (max-width:992px) { .col-md-6 { flex:0 0 100%; max-width:100%; } }
</style>
</head>
<body>

<div class="card">
    <h3><i class="fas fa-calendar-plus"></i> Create Event</h3>

    <?php if($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" id="eventTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="business-tab" data-bs-toggle="tab" data-bs-target="#business" type="button" role="tab">For Business Owners</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="consumer-tab" data-bs-toggle="tab" data-bs-target="#consumer" type="button" role="tab">For Consumers</button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- Business Owners Form -->
        <div class="tab-pane fade show active" id="business" role="tabpanel">
            <form method="POST">
                <input type="hidden" name="form_type" value="business">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($title); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Mode of Delivery <span class="text-danger">*</span></label>
                        <select name="mode" class="form-select" required>
                            <option value="">Select Mode</option>
                            <option value="Seminar" <?php echo ($mode=="Seminar")?"selected":""; ?>>Seminar</option>
                            <option value="Webinar" <?php echo ($mode=="Webinar")?"selected":""; ?>>Webinar</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Start Date & Time <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="start_date" class="form-control" value="<?php echo $start_date; ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">End Date & Time <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="end_date" class="form-control" value="<?php echo $end_date; ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Resource Speaker</label>
                        <input type="text" name="resource_speaker" class="form-control" value="<?php echo htmlspecialchars($speaker); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Budget</label>
                        <input type="number" name="budget" class="form-control" value="<?php echo htmlspecialchars($budget); ?>" min="0" step="0.01">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Target Audience</label>
                        <input type="text" name="audience" class="form-control" value="<?php echo htmlspecialchars($audience); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address / Venue</label>
                        <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($address); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Funding Source / Resource</label>
                        <input type="text" name="funding" class="form-control" value="<?php echo htmlspecialchars($funding); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description / Remarks</label>
                        <textarea name="description" class="form-control"><?php echo htmlspecialchars($description); ?></textarea>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-submit"><i class="fas fa-plus"></i> Create Event</button>
                    <a href="events.php" class="btn btn-secondary ms-2"><i class="fas fa-arrow-left"></i> Back</a>
                </div>
            </form>
        </div>

        <!-- Consumers Form -->
        <div class="tab-pane fade" id="consumer" role="tabpanel">
            <form method="POST">
                <input type="hidden" name="form_type" value="consumer">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" name="c_title" class="form-control" value="<?php echo htmlspecialchars($c_title); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Start Date & Time <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="c_start_date" class="form-control" value="<?php echo htmlspecialchars($c_start_date); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">End Date & Time <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="c_end_date" class="form-control" value="<?php echo htmlspecialchars($c_end_date); ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address / Venue</label>
                        <input type="text" name="c_address" class="form-control" value="<?php echo htmlspecialchars($c_address); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="c_description" class="form-control"><?php echo htmlspecialchars($c_description); ?></textarea>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-submit"><i class="fas fa-plus"></i> Create Event</button>
                    <a href="events.php" class="btn btn-secondary ms-2"><i class="fas fa-arrow-left"></i> Back</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
