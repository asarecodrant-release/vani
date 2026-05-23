<?php

$autoloadPath = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoloadPath)) {
    require $autoloadPath;
}

if (class_exists(Dotenv\Dotenv::class) && is_readable(__DIR__ . '/.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
} elseif (is_readable(__DIR__ . '/.env')) {
    foreach (parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW) ?: [] as $key => $value) {
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
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
