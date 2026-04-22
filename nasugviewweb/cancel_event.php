<?php
session_start();
header('Content-Type: application/json');

$conn = new mysqli("localhost","root","","nasugview2");

$data = json_decode(file_get_contents("php://input"), true);
$id = intval($data['id']);

$stmt = $conn->prepare("UPDATE events SET status='Canceled' WHERE id=?");
$stmt->bind_param("i",$id);

if($stmt->execute()){
    echo json_encode(["success"=>true]);
}else{
    echo json_encode(["success"=>false,"error"=>"Cancel failed"]);
}
?>
