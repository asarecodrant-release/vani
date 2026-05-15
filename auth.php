<?php
require_once __DIR__ . '/session-auth.php';

function is_logged_in() {
    return is_authenticated_user();
}

function require_login() {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit;
    }
}
?>
