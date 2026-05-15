<?php
session_start();

function is_logged_in() {
    return isset($_SESSION['customer']);
}

function require_login() {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit;
    }
}
?>