<?php
session_start();

// Unset all session variables
$_SESSION = [];

// Destroy session
session_destroy();

// Redirect to homepage
header("Location: index.php");
exit();