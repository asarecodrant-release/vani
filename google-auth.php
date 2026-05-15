<?php

session_start();

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


// =====================================
// DECODE JWT TOKEN
// =====================================
$parts = explode(".", $credential);

if (count($parts) !== 3) {

    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);

    exit;
}

$payload = json_decode(
    base64_decode(str_replace(
        ['-', '_'],
        ['+', '/'],
        $parts[1]
    )),
    true
);

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

echo json_encode([
    "success" => true
]);