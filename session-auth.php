<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
?>
