<?php

require 'vendor/autoload.php';
require 'core.php';
require_once __DIR__ . "/session-auth.php";

// =====================================
// LOAD ENV
// =====================================
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

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

    set_authenticated_user(
        $customer,
        "google"
    );

} else {

    $customer_id = uniqid("cus_");
    $randomPassword = bin2hex(random_bytes(16));

    $insert = supabase(
        "POST",
        "customers",
        [[
            "id" => $customer_id,
            "email" => $email,
            "password" => password_hash(
                $randomPassword,
                PASSWORD_DEFAULT
            )
        ]]
    );

    set_authenticated_user(
        $insert['data'][0] ?? [
            "id" => $customer_id,
            "email" => $email
        ],
        "google"
    );
}

header("Location: dashboard.php");
exit;
