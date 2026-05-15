<?php
session_start();

if (!isset($_SESSION['customer_id'])) {
    header("Location: login.php");
    exit;
}
?>

<h1>Welcome to Dashboard</h1>

<p>
Customer ID:
<?php echo $_SESSION['customer_id']; ?>
</p>

<p>
Email:
<?php echo $_SESSION['email']; ?>
</p>

<a href="logout.php">Logout</a>