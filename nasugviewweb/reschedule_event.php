<?php
session_start();
header('Content-Type: application/json');

$conn = new mysqli("localhost","root","","nasugview2");

$data = json_decode(file_get_contents("php://input"), true);

$id = intval($data['id']);
$start = $data['start'];
$end   = $data['end'];

$stmt = $conn->prepare("UPDATE events SET start_date_and_time=?, end_date_and_time=? WHERE id=?");
$stmt->bind_param("ssi",$start,$end,$id);

if($stmt->execute()){
    echo json_encode(["success"=>true]);
}else{
    echo json_encode(["success"=>false,"error"=>"Reschedule failed"]);
}
?>
