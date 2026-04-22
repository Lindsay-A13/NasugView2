<?php
session_start();

$conn = new mysqli("localhost","root","","nasugview2");

// Get event ID safely
$id = intval($_GET['id']);

// Flag for successful update
$updated = false;

// Handle POST request to update event
if($_SERVER["REQUEST_METHOD"] == "POST"){

    $title = $_POST['title'] ?? '';
    $start = $_POST['start'] ?? '';
    $end   = $_POST['end'] ?? '';
    $address = $_POST['address'] ?? '';
    $mode = $_POST['mode'] ?? '';
    $speaker = $_POST['speaker'] ?? '';
    $audience = $_POST['audience'] ?? '';
    $budget = $_POST['budget'] ?? '';
    $funding = $_POST['funding'] ?? '';
    $description = $_POST['description'] ?? '';

    // SQL without 'category' since your DB doesn't have it
    $stmt = $conn->prepare("
        UPDATE events SET
        title=?, start_date_and_time=?, end_date_and_time=?, address=?, 
        mode_of_delivery=?, speaker=?, audience=?, 
        budget=?, funding_source=?, description=?
        WHERE id=?
    ");

    // Bind params correctly (10 strings + 1 int)
    $stmt->bind_param(
        "ssssssssssi",
        $title, $start, $end, $address, $mode, $speaker,
        $audience, $budget, $funding, $description, $id
    );

    $stmt->execute();

    $updated = true; // mark successful update
}

// Fetch event safely
$event = $conn->query("SELECT * FROM events WHERE id=$id")->fetch_assoc() ?? [];
?>

<!DOCTYPE html>
<html>
<head>
<title>Edit Event</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<style>
body { padding: 30px; background: #f0f4ff; font-family: 'Poppins', sans-serif; }
.form-control { border-radius: 10px; }
.btn-primary { background: linear-gradient(135deg,#001a47,#00308a); border: none; box-shadow:0 10px 22px rgba(0,26,71,0.18); }
.btn-primary:hover { background: linear-gradient(135deg,#00308a,#001a47); transform:translateY(-2px); box-shadow:0 14px 28px rgba(0,26,71,0.24); }
.btn-secondary { border-radius: 10px; border: none; color: #fff; background: linear-gradient(135deg,#001a47,#00308a); box-shadow:0 10px 22px rgba(0,26,71,0.18); }
.btn-secondary:hover { background: linear-gradient(135deg,#00308a,#001a47); color: #fff; transform:translateY(-2px); box-shadow:0 14px 28px rgba(0,26,71,0.24); }
</style>
</head>
<body>

<h3>Edit Event</h3>

<form method="POST">

    <input class="form-control mb-2" name="title" value="<?= htmlspecialchars($event['title'] ?? '') ?>" placeholder="Event Title" required>

    <input type="datetime-local" class="form-control mb-2" name="start"
    value="<?= isset($event['start_date_and_time']) ? date('Y-m-d\TH:i', strtotime($event['start_date_and_time'])) : '' ?>">

    <input type="datetime-local" class="form-control mb-2" name="end"
    value="<?= isset($event['end_date_and_time']) ? date('Y-m-d\TH:i', strtotime($event['end_date_and_time'])) : '' ?>">

    <input class="form-control mb-2" name="address" value="<?= htmlspecialchars($event['address'] ?? '') ?>" placeholder="Address / Venue">
    <input class="form-control mb-2" name="mode" value="<?= htmlspecialchars($event['mode_of_delivery'] ?? '') ?>" placeholder="Mode of Delivery">
    <input class="form-control mb-2" name="speaker" value="<?= htmlspecialchars($event['speaker'] ?? '') ?>" placeholder="Resource Speaker">
    <input class="form-control mb-2" name="audience" value="<?= htmlspecialchars($event['audience'] ?? '') ?>" placeholder="Target Audience">
    <input class="form-control mb-2" name="budget" value="<?= htmlspecialchars($event['budget'] ?? '') ?>" placeholder="Budget">
    <input class="form-control mb-2" name="funding" value="<?= htmlspecialchars($event['funding_source'] ?? '') ?>" placeholder="Funding Source">
    <textarea class="form-control mb-3" name="description" placeholder="Description / Remarks"><?= htmlspecialchars($event['description'] ?? '') ?></textarea>

    <button class="btn btn-primary">Update Event</button>
    <a href="events.php" class="btn btn-secondary">Cancel</a>

</form>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php if($updated): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Event Updated!',
    text: 'The event has been successfully updated.',
    confirmButtonColor: '#001a47'
}).then(() => {
    window.location.href = 'events.php';
});
</script>
<?php endif; ?>

</body>
</html>
