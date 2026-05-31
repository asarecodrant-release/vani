<?php
include 'config.php';
require_once __DIR__ . '/session-auth.php';

$safeReturnTo = trim((string)($_GET['return_to'] ?? ''));
if ($safeReturnTo !== '' && preg_match('/^[A-Za-z0-9_\-]+\.php(?:\?[A-Za-z0-9_\-&=%.]+)?$/', $safeReturnTo)) {
    $_SESSION['auth_return_to'] = $safeReturnTo;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $res = supabase_request("customers", "POST", [
        "email" => $email,
        "password" => $password
    ]);

    if (isset($res[0]['id'])) {

        supabase_request("customer_chatbots", "POST", [
            "customer_id" => $res[0]['id'],
            "chatbot_type" => "faq"
        ]);

        set_authenticated_user(
            $res[0],
            "password"
        );
        $returnTo = (string)($_SESSION['auth_return_to'] ?? '');
        unset($_SESSION['auth_return_to']);
        if ($returnTo !== '' && preg_match('/^[A-Za-z0-9_\-]+\.php(?:\?[A-Za-z0-9_\-&=%.]+)?$/', $returnTo)) {
            header("Location: " . $returnTo);
            exit;
        }
        header("Location: dashboard.php");
        exit;
    } else {
        echo "Signup failed";
    }
}
?>

<form method="POST">
<input type="email" name="email" required placeholder="Email">
<input type="password" name="password" required placeholder="Password">
<button>Sign Up</button>
</form>
