<?php
require_once "config/db.php";

$res = $conn->query("SELECT * FROM provinces ORDER BY name ASC");

$data = [];

while($row = $res->fetch_assoc()){
    $data[] = $row;
}

echo json_encode($data);