<?php

require 'vendor/autoload.php';
require 'core.php';

session_start();

// =====================================
// LOAD ENV
// =====================================
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// =====================================
// GOOGLE CLIENT
// =====================================
$client = new Google_Client();

$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);

$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);

$client->setRedirectUri(
    $_ENV['GOOGLE_REDIRECT_URI']
);

$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

$client->setAccessToken($token);

$oauth = new Google_Service_Oauth2($client);

$user = $oauth->userinfo->get();

$email = $user->email;
$name  = $user->name;


// =====================================
// CHECK EXISTING USER
// =====================================
$check = supabase(
    "GET",
    "customers?email=eq." . urlencode($email) . "&limit=1"
);

if (!empty($check['data'])) {

    $customer = $check['data'][0];

    $_SESSION['customer_id'] = $customer['id'];
    $_SESSION['email'] = $customer['email'];

} else {

    $customer_id = uniqid("cus_");

    supabase(
        "POST",
        "customers",
        [[
            "id" => $customer_id,
            "email" => $email,
            "password" => ""
        ]]
    );

    $_SESSION['customer_id'] = $customer_id;
    $_SESSION['email'] = $email;
}

header("Location: dashboard.php");
exit;