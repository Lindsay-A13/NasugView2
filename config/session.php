<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* REDIRECT IF NOT LOGGED IN */
if (!isset($_SESSION['user_id']) || !isset($_SESSION['account_type'])) {
    header("Location: ../login.php");
    exit();
}

/* INCLUDE DATABASE */
require_once __DIR__ . "/db.php";

$user_id = $_SESSION['user_id'];
$account_type = $_SESSION['account_type'];

/* LOAD USER FROM CORRECT TABLE */
if ($account_type === "consumer") {

    $stmt = $conn->prepare("
        SELECT username, fname, lname, email
        FROM consumers
        WHERE c_id = ?
    ");

} else {

    $stmt = $conn->prepare("
        SELECT username, fname, lname, email
        FROM business_owner
        WHERE b_id = ?
    ");

}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {

    $user = $result->fetch_assoc();

    $username = $user['username'];
    $fname    = $user['fname'];
    $lname    = $user['lname'];
    $email    = $user['email'];

} else {

    session_destroy();
    header("Location: ../login.php");
    exit();

}

$stmt->close();

?>
