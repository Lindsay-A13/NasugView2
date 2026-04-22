<?php
require_once "config/db.php";

$city_id = intval($_GET['city_id']);

$stmt = $conn->prepare("
    SELECT * FROM barangays 
    WHERE city_id = ?
    ORDER BY name ASC
");

$stmt->bind_param("i", $city_id);
$stmt->execute();

$res = $stmt->get_result();

$data = [];

while($row = $res->fetch_assoc()){
    $data[] = $row;
}

echo json_encode($data);