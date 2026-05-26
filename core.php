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

function app_secret_encryption_key(): string {
    $raw = trim((string)($_ENV['APP_ENCRYPTION_KEY'] ?? getenv('APP_ENCRYPTION_KEY') ?: ''));
    if ($raw === '') {
        $raw = trim((string)($_ENV['PAYMENT_SECRET_ENCRYPTION_KEY'] ?? getenv('PAYMENT_SECRET_ENCRYPTION_KEY') ?: ''));
    }
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^base64:(.+)$/', $raw, $matches)) {
        $decoded = base64_decode($matches[1], true);
        if ($decoded !== false && strlen($decoded) >= 32) {
            return substr($decoded, 0, 32);
        }
    }
    return hash('sha256', $raw, true);
}

function app_encrypt_secret(string $plainText): string {
    $plainText = trim($plainText);
    if ($plainText === '') {
        return '';
    }
    $key = app_secret_encryption_key();
    if ($key === '') {
        return '';
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipherText === false || $tag === '') {
        return '';
    }
    return 'enc:v1:' . base64_encode($iv . $tag . $cipherText);
}

function app_decrypt_secret(string $storedValue): string {
    $storedValue = trim($storedValue);
    if ($storedValue === '') {
        return '';
    }
    if (strpos($storedValue, 'enc:v1:') !== 0) {
        return $storedValue;
    }
    $key = app_secret_encryption_key();
    if ($key === '') {
        return '';
    }
    $packed = base64_decode(substr($storedValue, 7), true);
    if ($packed === false || strlen($packed) <= 28) {
        return '';
    }
    $iv = substr($packed, 0, 12);
    $tag = substr($packed, 12, 16);
    $cipherText = substr($packed, 28);
    $plainText = openssl_decrypt($cipherText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plainText === false ? '' : $plainText;
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
