<?php

require_once __DIR__ . "/session-auth.php";

header("Content-Type: application/json");

require "core.php";

$data = json_decode(
    file_get_contents("php://input"),
    true
);

$credential = $data['credential'] ?? '';

if (!$credential) {

    echo json_encode([
        "success" => false,
        "message" => "Missing credential"
    ]);

    exit;
}


$googleClientId =
    $_ENV['GOOGLE_CLIENT_ID']
    ?? getenv('GOOGLE_CLIENT_ID')
    ?: '970273381861-ar6734p4c2hl3pn0g58segkgccfvoirv.apps.googleusercontent.com';

$client = new Google_Client([
    'client_id' => $googleClientId
]);

$payload = $client->verifyIdToken($credential);

if (!$payload) {

    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);

    exit;
}

$email = $payload['email'] ?? '';

if (!$email) {

    echo json_encode([
        "success" => false,
        "message" => "Google email not found"
    ]);

    exit;
}


// =====================================
// CHECK CUSTOMER
// =====================================
$check = supabase(
    "GET",
    "customers?email=eq." . urlencode($email) . "&limit=1"
);

$firstLogin = false;

if (!empty($check['data'])) {

    $customer = $check['data'][0];

    set_authenticated_user(
        $customer,
        "google"
    );

} else {

    $firstLogin = true;
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

echo json_encode([
    "success" => true,
    "first_login" => $firstLogin
]);
