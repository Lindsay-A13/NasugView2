<?php
require "db.php"; // your DB connection

header('Content-Type: application/json');

try {
    // Validate required fields
    $required = ['username','fname','lname','designation','negosyocenter','municipality'];
    foreach($required as $field){
        if(empty($_POST[$field])){
            throw new Exception("Missing field: $field");
        }
    }

    $username      = $_POST['username'];
    $fname         = $_POST['fname'];
    $lname         = $_POST['lname'];
    $designation   = $_POST['designation'];
    $negosyocenter = $_POST['negosyocenter'];
    $municipality  = strtolower($_POST['municipality']);
    $contact       = $_POST['contact'] ?? '';
    $center_id     = !empty($_POST['center_id']) ? (int)$_POST['center_id'] : NULL;

    // Generate email & temp password
    $email = "negosyocenter{$municipality}@gmail.com";
    $temp  = substr(md5(rand()),0,8);
    $hash  = password_hash($temp,PASSWORD_DEFAULT);

    // Prepare statement
    $stmt = $conn->prepare("
        INSERT INTO negosyo_center_users
        (email, username, password, fname, lname, designation, negosyocenter, contact, profile_img, center_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, '', ?)
    ");

    if(!$stmt){
        throw new Exception("Prepare failed: ".$conn->error);
    }

    $stmt->bind_param(
        "ssssssssi",
        $email,
        $username,
        $hash,
        $fname,
        $lname,
        $designation,
        $negosyocenter,
        $contact,
        $center_id
    );

    if(!$stmt->execute()){
        throw new Exception("Database insert failed: ".$stmt->error);
    }

    $stmt->close();

    // Return success + temp password (email sending can be added later)
    echo json_encode(["success"=>true,"temp"=>$temp]);

} catch(Exception $e){
    echo json_encode(["success"=>false,"error"=>$e->getMessage()]);
}