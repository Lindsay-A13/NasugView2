<?php
require_once "config/db.php";

$province_id = intval($_GET['province_id']);

$stmt = $conn->prepare("
    SELECT * FROM cities 
    WHERE province_id = ?
    ORDER BY name ASC
");

$stmt->bind_param("i", $province_id);
$stmt->execute();

$res = $stmt->get_result();

$data = [];

while($row = $res->fetch_assoc()){
    $data[] = $row;
}

echo json_encode($data);