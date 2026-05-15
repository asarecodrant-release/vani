<?php
include 'config.php';
session_start();

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

        $_SESSION['customer'] = $res[0];
        header("Location: dashboard.php");
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