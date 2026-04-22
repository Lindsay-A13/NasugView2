<?php

require_once "config/session.php";
require_once "config/db.php";


if(!isset($_FILES['photo']) || $_FILES['photo']['error'] !== 0){
    die("No file uploaded.");
}

if(!isset($_POST['type'])){
    die("Invalid request.");
}


$type = $_POST['type'];


/* SET DESTINATION */

if($type === "avatar"){

    $folder = "uploads/profile/";
    $column = "profile_picture";

}else if($type === "cover"){

    $folder = "uploads/cover/";
    $column = "cover_photo";

}else{

    die("Invalid type.");
}


/* GENERATE SAFE FILE NAME */

$ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);

$filename = "user_" . $user_id . "_" . time() . "." . $ext;

$destination = $folder . $filename;


/* MOVE FILE */

if(!move_uploaded_file($_FILES['photo']['tmp_name'], $destination)){
    die("Upload failed.");
}


/* UPDATE DATABASE */

if($account_type === "consumer"){

    $stmt = $conn->prepare("
        UPDATE consumers
        SET $column = ?
        WHERE c_id = ?
    ");

}else{

    $stmt = $conn->prepare("
        UPDATE business_owner
        SET $column = ?
        WHERE b_id = ?
    ");

}


$stmt->bind_param("si", $filename, $user_id);

if(!$stmt->execute()){
    die("Database update failed: " . $stmt->error);
}

$stmt->close();


/* REDIRECT BACK */

header("Location: profile.php");
exit;

?>
