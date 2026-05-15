<?php

//require 'vendor/autoload.php';

// ======================================
// LOAD ENV
// ======================================
if (file_exists(__DIR__ . '/.env')) {

    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}
// ======================================
// SUPABASE CONFIG
// ======================================
$SUPABASE_URL =
    $_ENV['SUPABASE_URL']
    ?? getenv('SUPABASE_URL');

$SUPABASE_KEY =
    $_ENV['SUPABASE_KEY']
    ?? getenv('SUPABASE_KEY');

function supabase($method, $endpoint, $data = null) {

    global $SUPABASE_URL, $SUPABASE_KEY;

    $url = $SUPABASE_URL . "/rest/v1/" . $endpoint;

    $headers = [
        "Content-Type: application/json",
        "apikey: $SUPABASE_KEY",
        "Authorization: Bearer $SUPABASE_KEY",
        "Prefer: return=representation"
    ];

    $options = [
        "http" => [
            "method" => $method,
            "header" => implode("\r\n", $headers),
            "ignore_errors" => true
        ]
    ];

    if ($data) {

        $options["http"]["content"] =
            json_encode($data);
    }

    $context =
        stream_context_create($options);

    $response =
        file_get_contents(
            $url,
            false,
            $context
        );

    $status = 0;

    if (isset($http_response_header[0])) {

        preg_match(
            '{HTTP/\S*\s(\d{3})}',
            $http_response_header[0],
            $match
        );

        $status =
            intval($match[1] ?? 0);
    }

    return [
        "status" => $status,
        "data" => json_decode($response, true),
        "raw" => $response
    ];
}

// ======================================
// GENERATE UUID
// ======================================
function generateUUID() {

    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

// ======================================
// GOOGLE LOGIN AJAX
// ======================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['google_email'])
) {

    header("Content-Type: application/json");

    $googleEmail = trim($_POST['google_email']);

    if (!$googleEmail) {

        echo json_encode([
            "success" => false
        ]);

        exit;
    }

    // ======================================
    // CHECK USER EXISTS
    // ======================================
    $check = supabase(
        "GET",
        "customers?email=eq." .
        urlencode($googleEmail) .
        "&limit=1"
    );

    $user = $check['data'][0] ?? null;

    // ======================================
    // NEW GOOGLE USER
    // ======================================
    if (!$user) {

        $customerId = generateUUID();

        $randomPassword =
            password_hash(
                bin2hex(random_bytes(8)),
                PASSWORD_DEFAULT
            );

        supabase(
            "POST",
            "customers",
            [[
                "id" => $customerId,
                "email" => $googleEmail,
                "password" => $randomPassword
            ]]
        );

        $user = [
            "id" => $customerId,
            "email" => $googleEmail
        ];

        $_SESSION['first_google_login'] = true;

    } else {

        $_SESSION['first_google_login'] = false;
    }

    // ======================================
    // LOGIN SESSION
    // ======================================
    $_SESSION['customer_id'] = $user['id'];
    $_SESSION['email'] = $user['email'];

    echo json_encode([
        "success" => true,
        "first_login" =>
            $_SESSION['first_google_login']
    ]);

    exit;
}