<?php
define('SUPABASE_URL', 'https://nwldvgafmyaagmyezena.supabase.co');
define('SUPABASE_KEY', 'sb_publishable_gWMY1sQRn3fqip0JfAQPRQ_F79rlYyZ');

function supabase_request($endpoint, $method = 'GET', $data = null) {
    $url = SUPABASE_URL . "/rest/v1/" . $endpoint;

    $headers = [
        "apikey: " . SUPABASE_KEY,
        "Authorization: Bearer " . SUPABASE_KEY,
        "Content-Type: application/json",
        "Prefer: return=representation"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}
?>