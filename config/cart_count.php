<?php
if(session_status() === PHP_SESSION_NONE){
    session_start();
}

require_once "db.php";

$cartCount = 0;

if(isset($_SESSION['user_id'])){

    $user_id = $_SESSION['user_id'];
    $account_type = $_SESSION['account_type'];

    $stmt = $conn->prepare("
        SELECT COUNT(*) as total_items
        FROM cart
        WHERE consumer_id = ?
        AND account_type = ?
    ");

    $stmt->bind_param("is", $user_id, $account_type);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $cartCount = $row['total_items'] ?? 0;

    $stmt->close();

}else{
    $cartCount = 0;
}
?>