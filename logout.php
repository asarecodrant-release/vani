<?php
require_once __DIR__ . '/session-auth.php';

clear_remembered_device();

// Unset all session variables
$_SESSION = [];

// Destroy session
session_destroy();

$next = (string)($_GET['next'] ?? '');
$allowed = ['index.php', 'login.php'];
$target = 'index.php';
if ($next !== '') {
    $path = parse_url($next, PHP_URL_PATH) ?: '';
    $file = basename($path);
    if (in_array($file, $allowed, true)) {
        $query = parse_url($next, PHP_URL_QUERY);
        $target = $file . ($query ? '?' . $query : '');
    }
}

// Redirect to homepage or a safe local page
header("Location: " . $target);
exit();
