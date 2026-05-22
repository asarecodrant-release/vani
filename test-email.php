<?php
require_once __DIR__ . '/session-auth.php';

if (!is_authenticated_user()) {
    http_response_code(404);
    exit;
}

http_response_code(404);
exit;
