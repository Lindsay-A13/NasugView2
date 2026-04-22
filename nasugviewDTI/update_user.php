<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require "db.php";
header('Content-Type: application/json');

try {

    // Validate inputs
    if(empty($_POST['id'])){
        throw new Exception("Missing user ID");
    }

    $stmt = $conn->prepare("
        UPDATE dti_user
        SET fname=?, lname=?, username=?, designation=?
        WHERE dti_id=?
    ");

    if(!$stmt){
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param(
        "ssssi",
        $_POST['fname'],
        $_POST['lname'],
        $_POST['username'],
        $_POST['designation'],
        $_POST['id']
    );

    if(!$stmt->execute()){
        throw new Exception("Execute failed: " . $stmt->error);
    }

    echo json_encode(["success"=>true]);

} catch(Exception $e){
    echo json_encode(["success"=>false,"error"=>$e->getMessage()]);
}
