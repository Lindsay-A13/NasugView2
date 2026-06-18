<?php
/* DATABASE CONNECTION FILE */
/* USE: require_once "db.php"; */

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "nasugview2";

/* CREATE CONNECTION */
$conn = new mysqli($servername, $username, $password, $dbname);

/* CHECK CONNECTION */
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* SET UTF8 */
$conn->set_charset("utf8mb4");

/* USE PHILIPPINE TIME FOR PHP AND MYSQL SESSION TIMESTAMPS */
date_default_timezone_set("Asia/Manila");
$conn->query("SET time_zone = '+08:00'");
?>
