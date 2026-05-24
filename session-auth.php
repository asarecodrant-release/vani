<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('supabase')) {
    require_once __DIR__ . '/core.php';
}

const VANI_REMEMBER_COOKIE = 'vani_remember_device';
const VANI_REMEMBER_SECONDS = 12 * 60 * 60;

function auth_cookie_secure(): bool {
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function auth_safe_rows(array $response): array {
    $data = $response['data'] ?? null;
    return is_array($data) ? $data : [];
}

function remember_cookie_options(int $expires): array {
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => auth_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax'
    ];
}

function clear_remembered_device(): void {
    $cookie = (string)($_COOKIE[VANI_REMEMBER_COOKIE] ?? '');
    if (strpos($cookie, ':') !== false) {
        [$selector] = explode(':', $cookie, 2);
        $selector = preg_replace('/[^a-f0-9]/i', '', $selector);
        if ($selector !== '') {
            supabase('DELETE', 'customer_remember_tokens?selector=eq.' . urlencode($selector));
        }
    }
    setcookie(VANI_REMEMBER_COOKIE, '', remember_cookie_options(time() - 3600));
    unset($_COOKIE[VANI_REMEMBER_COOKIE]);
}

function remember_authenticated_device(array $user): void {
    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($email === '') {
        return;
    }

    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + VANI_REMEMBER_SECONDS;

    $res = supabase('POST', 'customer_remember_tokens', [[
        'email' => $email,
        'selector' => $selector,
        'token_hash' => password_hash($validator, PASSWORD_DEFAULT),
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires),
        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)
    ]]);

    if ($res['status'] >= 200 && $res['status'] < 300) {
        setcookie(VANI_REMEMBER_COOKIE, $selector . ':' . $validator, remember_cookie_options($expires));
        $_COOKIE[VANI_REMEMBER_COOKIE] = $selector . ':' . $validator;
    }
}

function set_authenticated_user(array $user, string $provider = 'password'): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $email = trim($user['email'] ?? '');
    $userId = trim((string)($user['id'] ?? ''));

    $_SESSION['is_logged_in'] = true;
    $_SESSION['auth_email'] = $email;
    $_SESSION['auth_user_id'] = $userId;
    $_SESSION['auth_provider'] = $provider;
    $_SESSION['must_reset_password'] = !empty($user['must_reset_password']);

    // Kept for older templates that display the logged-in email.
    $_SESSION['email'] = $email;
}

function restore_remembered_user(): bool {
    if (!empty($_SESSION['is_logged_in']) && !empty($_SESSION['auth_email'])) {
        return true;
    }

    $cookie = (string)($_COOKIE[VANI_REMEMBER_COOKIE] ?? '');
    if (strpos($cookie, ':') === false) {
        return false;
    }

    [$selector, $validator] = explode(':', $cookie, 2);
    $selector = preg_replace('/[^a-f0-9]/i', '', $selector);
    if ($selector === '' || $validator === '') {
        clear_remembered_device();
        return false;
    }

    $tokenRows = auth_safe_rows(supabase(
        'GET',
        'customer_remember_tokens?select=*&selector=eq.' . urlencode($selector) . '&limit=1'
    ));
    $token = $tokenRows[0] ?? [];
    if (empty($token) || strtotime((string)($token['expires_at'] ?? '')) < time()) {
        clear_remembered_device();
        return false;
    }
    if (!password_verify($validator, (string)($token['token_hash'] ?? ''))) {
        clear_remembered_device();
        return false;
    }

    $email = strtolower(trim((string)($token['email'] ?? '')));
    $userRows = auth_safe_rows(supabase(
        'GET',
        'customers?select=*&email=eq.' . urlencode($email) . '&limit=1'
    ));
    if (empty($userRows[0])) {
        clear_remembered_device();
        return false;
    }

    set_authenticated_user($userRows[0], 'remembered_device');
    supabase('PATCH', 'customer_remember_tokens?selector=eq.' . urlencode($selector), [
        'last_used_at' => gmdate('Y-m-d\TH:i:s\Z')
    ]);
    return true;
}

function is_authenticated_user(): bool {
    return !empty($_SESSION['is_logged_in'])
        && !empty($_SESSION['auth_email']);
}

function authenticated_email(): string {
    return is_authenticated_user()
        ? (string)$_SESSION['auth_email']
        : '';
}

function authenticated_user_id(): string {
    return is_authenticated_user()
        ? (string)($_SESSION['auth_user_id'] ?? '')
        : '';
}

function clear_setup_session(): void {
    unset(
        $_SESSION['setup_email'],
        $_SESSION['setup_customer_id'],
        $_SESSION['setup_website_name'],
        $_SESSION['setup_business_type']
    );
}

restore_remembered_user();
?>
