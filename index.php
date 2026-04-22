<?php
session_start();

/* CHECK IF USER IS LOGGED IN */
if (isset($_SESSION['user_id'])) {

    header("Location: login.php");
    exit();

} else {

    header("Location: home.php");
    exit();

}
?>
