<?php
require_once __DIR__ . '/session-auth.php';

if (!is_authenticated_user()) {
    header("Location: login.php");
    exit;
}
?>

<h1>Welcome to Dashboard</h1>

<p>
Account ID:
<?php echo htmlspecialchars(authenticated_user_id()); ?>
</p>

<p>
Email:
<?php echo htmlspecialchars(authenticated_email()); ?>
</p>

<a href="logout.php">Logout</a>
