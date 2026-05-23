<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ==========================
// FORCE CORS FIRST
// ==========================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// ==========================
// HANDLE PREFLIGHT
// ==========================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? '';

// ==========================
// HEALTH CHECK / KEEP-ALIVE
// ==========================
if ($action === "ping" || $action === "health") {
    echo json_encode([
        "success" => true,
        "status" => "ok",
        "service" => "vani",
        "timestamp" => gmdate("c")
    ]);
    exit;
}

// ==========================
// SHOW ERRORS TEMPORARILY
// ==========================
ini_set('display_errors', 0);
error_reporting(E_ALL);

// ==========================
// LOAD CORE
// ==========================
require "core.php";
require_once __DIR__ . "/session-auth.php";
require_once __DIR__ . "/billing.php";

// ==========================
// INPUT SAFE PARSER
// ==========================
function getJSON() {
    $raw = file_get_contents("php://input");
    return json_decode($raw, true) ?? [];
}

function request_source_url(array $data = []): string {
    $value = trim((string)($data['source_url'] ?? $data['current_url'] ?? $_GET['source_url'] ?? $_GET['current_url'] ?? ''));
    if ($value !== '') {
        return $value;
    }
    return trim((string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''));
}

function host_from_value(string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '';
    }
    if (!preg_match('{^https?://}i', $value)) {
        $value = 'https://' . $value;
    }
    $host = parse_url($value, PHP_URL_HOST);
    $host = strtolower((string)$host);
    $host = preg_replace('/^www\./', '', $host);
    return rtrim($host, '.');
}

function valid_website_domain_from_value(string $value): string {
    $host = host_from_value($value);
    if ($host === '' || strlen($host) > 253 || strpos($host, '.') === false) {
        return '';
    }
    if (!preg_match('/^(?:[a-z0-9-]{1,63}\.)+[a-z]{2,63}$/i', $host)) {
        return '';
    }
    foreach (explode('.', $host) as $label) {
        if ($label === '' || $label[0] === '-' || substr($label, -1) === '-') {
            return '';
        }
    }
    return $host;
}

function domain_list(string $domains): array {
    $parts = preg_split('/[\s,]+/', $domains);
    $clean = [];
    foreach ($parts as $part) {
        $host = host_from_value((string)$part);
        if ($host !== '') {
            $clean[$host] = true;
        }
    }
    return array_keys($clean);
}

function host_matches_domain(string $host, string $domain): bool {
    if ($host === '' || $domain === '') {
        return false;
    }
    $suffix = '.' . $domain;
    return $host === $domain || substr($host, -strlen($suffix)) === $suffix;
}

function chatbot_access_result(array $settings, array $signup, string $sourceUrl, bool $allowedDomainsAvailable = true): array {
    $host = host_from_value($sourceUrl);
    $websiteVerificationEnabled = filter_var($settings['website_verification_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $allowedDomainsEnabled = $allowedDomainsAvailable && filter_var($settings['allowed_domains_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!$websiteVerificationEnabled && !$allowedDomainsEnabled) {
        return ["allowed" => true, "message" => ""];
    }

    if ($websiteVerificationEnabled) {
        $websiteHost = host_from_value((string)($signup['website_name'] ?? ''));
        if ($host === '' || $websiteHost === '' || !host_matches_domain($host, $websiteHost)) {
            return ["allowed" => false, "message" => "This website is not verified for this chatbot."];
        }
    }

    if ($allowedDomainsEnabled) {
        $matchesAllowedDomain = false;
        foreach (domain_list((string)($settings['allowed_domains'] ?? '')) as $domain) {
            if (host_matches_domain($host, $domain)) {
                $matchesAllowedDomain = true;
                break;
            }
        }
        if (!$matchesAllowedDomain) {
            return ["allowed" => false, "message" => "This domain is not allowed for this chatbot."];
        }
    }

    return ["allowed" => true, "message" => ""];
}

function safe_rows(array $response): array {
    $data = $response['data'] ?? null;
    return is_array($data) ? $data : [];
}

require_once __DIR__ . "/invoice_helpers.php";

function is_uuid_value(string $value): bool {
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
}

function billing_account_filter(string $customerId = '', string $email = ''): string {
    $customerId = trim($customerId);
    if ($customerId !== '') {
        return "customer_id=eq." . urlencode($customerId);
    }
    return "email=eq." . urlencode($email);
}

function billing_account_for_email(string $email): array {
    $rows = safe_rows(supabase(
        "GET",
        "billing_accounts?select=*&email=eq." . urlencode($email) . "&order=created_at.desc&limit=1"
    ));
    if (!empty($rows[0])) {
        $before = $rows[0];
        enforce_billing_free_transition($rows[0]);
        $status = (string)($before['subscription_status'] ?? 'free');
        $periodEnd = (string)($before['current_period_end'] ?? '');
        $walletBalance = (int)($before['wallet_balance_paise'] ?? 0);
        if (($status === 'cancelled' && $walletBalance <= 0) || ($status === 'active' && $periodEnd !== '' && strtotime($periodEnd) < time())) {
            $freshRows = safe_rows(supabase(
                "GET",
                "billing_accounts?select=*&email=eq." . urlencode($email) . "&limit=1"
            ));
            return $freshRows[0] ?? $before;
        }
        return $before;
    }
    $res = supabase("POST", "billing_accounts", [[
        "email" => $email,
        "wallet_balance_paise" => 0,
        "current_plan" => "free",
        "subscription_status" => "free"
    ]]);
    return $res['data'][0] ?? [
        "email" => $email,
        "wallet_balance_paise" => 0,
        "current_plan" => "free",
        "subscription_status" => "free"
    ];
}

function billing_legacy_account_has_value(array $account): bool {
    $plan = (string)($account['current_plan'] ?? 'free');
    $status = (string)($account['subscription_status'] ?? 'free');
    return $plan !== 'free'
        || $status !== 'free'
        || (int)($account['wallet_balance_paise'] ?? 0) > 0
        || trim((string)($account['saved_payment_method_reference'] ?? '')) !== ''
        || trim((string)($account['saved_payment_method_customer_id'] ?? '')) !== '';
}

function billing_legacy_owner_customer_id(string $email): string {
    if ($email === '') {
        return '';
    }
    $orders = safe_rows(supabase(
        "GET",
        "billing_orders?select=customer_id,status,created_at&email=eq." . urlencode($email) . "&customer_id=not.is.null&status=eq.paid&order=created_at.desc&limit=10"
    ));
    foreach ($orders as $order) {
        $customerId = trim((string)($order['customer_id'] ?? ''));
        if ($customerId !== '') {
            return $customerId;
        }
    }
    return '';
}

function billing_customer_has_paid_order(string $email, string $customerId): bool {
    if ($email === '' || $customerId === '') {
        return false;
    }
    $rows = safe_rows(supabase(
        "GET",
        "billing_orders?select=id&email=eq." . urlencode($email) . "&customer_id=eq." . urlencode($customerId) . "&status=eq.paid&limit=1"
    ));
    return !empty($rows);
}

function billing_email_has_assigned_paid_account(string $email, string $exceptCustomerId = ''): bool {
    if ($email === '') {
        return false;
    }
    $rows = safe_rows(supabase(
        "GET",
        "billing_accounts?select=*&email=eq." . urlencode($email) . "&customer_id=not.is.null&limit=20"
    ));
    foreach ($rows as $row) {
        $rowCustomerId = trim((string)($row['customer_id'] ?? ''));
        if ($rowCustomerId !== $exceptCustomerId && billing_legacy_account_has_value($row)) {
            return true;
        }
    }
    return false;
}

function billing_email_bot_count(string $email): int {
    if ($email === '') {
        return 0;
    }
    $rows = safe_rows(supabase(
        "GET",
        "chatbot_signups?select=customer_id&email=eq." . urlencode($email) . "&limit=2"
    ));
    return count($rows);
}

function billing_adopt_legacy_email_account(string $customerId, string $email, array $customerAccount = []): array {
    if ($customerId === '' || $email === '') {
        return $customerAccount;
    }
    $legacyOwnerCustomerId = billing_legacy_owner_customer_id($email);
    if ($legacyOwnerCustomerId !== '' && $legacyOwnerCustomerId !== $customerId) {
        return $customerAccount;
    }
    if ($legacyOwnerCustomerId === '' && billing_email_has_assigned_paid_account($email, $customerId)) {
        return $customerAccount;
    }
    if ($legacyOwnerCustomerId === '' && billing_email_bot_count($email) > 1) {
        return $customerAccount;
    }
    $legacyRows = safe_rows(supabase(
        "GET",
        "billing_accounts?select=*&email=eq." . urlencode($email) . "&customer_id=is.null&order=created_at.desc&limit=5"
    ));
    $legacy = [];
    foreach ($legacyRows as $row) {
        if (billing_legacy_account_has_value($row)) {
            $legacy = $row;
            break;
        }
    }
    if (empty($legacy)) {
        return $customerAccount;
    }

    $copyFields = [
        "wallet_balance_paise",
        "current_plan",
        "subscription_status",
        "auto_recharge_enabled",
        "auto_recharge_threshold_paise",
        "auto_recharge_amount_paise",
        "saved_payment_method_status",
        "saved_payment_method_reference",
        "saved_payment_method_customer_id",
        "saved_payment_method_contact",
        "last_auto_recharge_attempt_at",
        "current_period_start",
        "current_period_end"
    ];

    if (empty($customerAccount)) {
        $claim = supabase("PATCH", "billing_accounts?id=eq." . urlencode((string)$legacy['id']), [
            "customer_id" => $customerId
        ]);
        if ($claim['status'] >= 200 && $claim['status'] < 300 && !empty($claim['data'][0])) {
            $customerAccount = $claim['data'][0];
        }
    } elseif (!billing_legacy_account_has_value($customerAccount)) {
        $payload = ["email" => $email];
        foreach ($copyFields as $field) {
            if (array_key_exists($field, $legacy)) {
                $payload[$field] = $legacy[$field];
            }
        }
        $copy = supabase("PATCH", "billing_accounts?customer_id=eq." . urlencode($customerId), $payload);
        if ($copy['status'] >= 200 && $copy['status'] < 300 && !empty($copy['data'][0])) {
            $customerAccount = $copy['data'][0];
        }
    }

    supabase("PATCH", "wallet_transactions?email=eq." . urlencode($email) . "&customer_id=is.null", [
        "customer_id" => $customerId
    ]);
    supabase("PATCH", "billing_orders?email=eq." . urlencode($email) . "&customer_id=is.null", [
        "customer_id" => $customerId
    ]);

    return $customerAccount;
}

function billing_free_account_snapshot(string $customerId, string $email): array {
    return [
        "customer_id" => $customerId,
        "email" => $email,
        "wallet_balance_paise" => 0,
        "current_plan" => "free",
        "subscription_status" => "free",
        "auto_recharge_enabled" => false,
        "saved_payment_method_status" => "missing"
    ];
}

function billing_repair_misassigned_account(string $customerId, string $email, array $account): array {
    if ($customerId === '' || $email === '' || !billing_legacy_account_has_value($account)) {
        return $account;
    }
    $ownerCustomerId = billing_legacy_owner_customer_id($email);
    if ($ownerCustomerId === '' || $ownerCustomerId === $customerId) {
        return $account;
    }
    if (billing_customer_has_paid_order($email, $customerId)) {
        return $account;
    }

    $copyFields = [
        "wallet_balance_paise",
        "current_plan",
        "subscription_status",
        "auto_recharge_enabled",
        "auto_recharge_threshold_paise",
        "auto_recharge_amount_paise",
        "saved_payment_method_status",
        "saved_payment_method_reference",
        "saved_payment_method_customer_id",
        "saved_payment_method_contact",
        "last_auto_recharge_attempt_at",
        "current_period_start",
        "current_period_end"
    ];
    $payload = ["customer_id" => $ownerCustomerId, "email" => $email];
    foreach ($copyFields as $field) {
        if (array_key_exists($field, $account)) {
            $payload[$field] = $account[$field];
        }
    }

    $ownerRows = safe_rows(supabase(
        "GET",
        "billing_accounts?select=*&customer_id=eq." . urlencode($ownerCustomerId) . "&limit=1"
    ));
    if (empty($ownerRows[0])) {
        supabase("POST", "billing_accounts", [$payload]);
    } elseif (!billing_legacy_account_has_value($ownerRows[0])) {
        supabase("PATCH", "billing_accounts?customer_id=eq." . urlencode($ownerCustomerId), $payload);
    }

    supabase("PATCH", "billing_accounts?customer_id=eq." . urlencode($customerId), [
        "wallet_balance_paise" => 0,
        "current_plan" => "free",
        "subscription_status" => "free",
        "auto_recharge_enabled" => false,
        "auto_recharge_threshold_paise" => 0,
        "auto_recharge_amount_paise" => 0,
        "saved_payment_method_status" => "missing",
        "saved_payment_method_reference" => null,
        "saved_payment_method_customer_id" => null,
        "saved_payment_method_contact" => null
    ]);
    disable_paid_service_toggles_for_customer($customerId, 'subscription_owner_mismatch');
    return billing_free_account_snapshot($customerId, $email);
}

function billing_account_for_customer(string $customerId): array {
    $customerId = trim($customerId);
    if ($customerId === '') {
        return [];
    }
    $rows = safe_rows(supabase(
        "GET",
        "billing_accounts?select=*&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    if (!empty($rows[0])) {
        $email = trim((string)($rows[0]['email'] ?? ''));
        if (!billing_legacy_account_has_value($rows[0]) && $email !== '') {
            $rows[0] = billing_adopt_legacy_email_account($customerId, $email, $rows[0]);
        }
        $rows[0] = billing_repair_misassigned_account($customerId, $email, $rows[0]);
        $before = $rows[0];
        enforce_billing_free_transition($rows[0]);
        $status = (string)($before['subscription_status'] ?? 'free');
        $periodEnd = (string)($before['current_period_end'] ?? '');
        $walletBalance = (int)($before['wallet_balance_paise'] ?? 0);
        if (($status === 'cancelled' && $walletBalance <= 0) || ($status === 'active' && $periodEnd !== '' && strtotime($periodEnd) < time())) {
            $freshRows = safe_rows(supabase(
                "GET",
                "billing_accounts?select=*&customer_id=eq." . urlencode($customerId) . "&limit=1"
            ));
            return $freshRows[0] ?? $before;
        }
        return $before;
    }
    $email = billing_email_for_customer($customerId);
    if ($email === '') {
        return [];
    }
    $adopted = billing_adopt_legacy_email_account($customerId, $email);
    if (!empty($adopted)) {
        return $adopted;
    }
    $res = supabase("POST", "billing_accounts", [[
        "customer_id" => $customerId,
        "email" => $email,
        "wallet_balance_paise" => 0,
        "current_plan" => "free",
        "subscription_status" => "free"
    ]]);
    return $res['data'][0] ?? [
        "customer_id" => $customerId,
        "email" => $email,
        "wallet_balance_paise" => 0,
        "current_plan" => "free",
        "subscription_status" => "free"
    ];
}

function customer_ids_for_billing_email(string $email): array {
    if ($email === '') {
        return [];
    }
    $rows = safe_rows(supabase(
        "GET",
        "chatbot_signups?select=customer_id&email=eq." . urlencode($email)
    ));
    return array_values(array_filter(array_map(fn($row) => trim((string)($row['customer_id'] ?? '')), $rows)));
}

function disable_paid_service_toggles_for_customer(string $customerId, string $reason = 'free_plan'): void {
    if ($customerId === '') {
        return;
    }
    supabase("PATCH", "lead_generation_settings?customer_id=eq." . urlencode($customerId), [
        "verify_email_otp" => false,
        "verify_mobile_otp" => false,
        "redirect_whatsapp" => false,
        "service_tier" => "free",
        "whatsapp_redirect_stopped_at" => gmdate('Y-m-d\TH:i:s\Z'),
        "whatsapp_redirect_stopped_reason" => $reason
    ]);
    supabase("PATCH", "chatbot_settings?customer_id=eq." . urlencode($customerId), [
        "handoff_enabled" => false,
        "allowed_domains_enabled" => false,
        "webhook_url" => null,
        "webhook_secret" => null
    ]);
}

function disable_paid_service_toggles_for_email(string $email, string $reason = 'free_plan'): void {
    foreach (customer_ids_for_billing_email($email) as $customerId) {
        supabase("PATCH", "lead_generation_settings?customer_id=eq." . urlencode($customerId), [
            "verify_email_otp" => false,
            "verify_mobile_otp" => false,
            "redirect_whatsapp" => false,
            "service_tier" => "free",
            "whatsapp_redirect_stopped_at" => gmdate('Y-m-d\TH:i:s\Z'),
            "whatsapp_redirect_stopped_reason" => $reason
        ]);
        supabase("PATCH", "chatbot_settings?customer_id=eq." . urlencode($customerId), [
            "handoff_enabled" => false,
            "allowed_domains_enabled" => false,
            "webhook_url" => null,
            "webhook_secret" => null
        ]);
    }
}

function downgrade_billing_account_to_free(string $customerIdOrEmail, string $reason = 'wallet_empty'): void {
    if ($customerIdOrEmail === '') {
        return;
    }
    $isCustomerId = is_uuid_value($customerIdOrEmail);
    supabase("PATCH", "billing_accounts?" . ($isCustomerId ? "customer_id=eq." : "email=eq.") . urlencode($customerIdOrEmail), [
        "current_plan" => "free",
        "subscription_status" => "free",
        "auto_recharge_enabled" => false,
        "saved_payment_method_status" => "failed",
        "saved_payment_method_reference" => null
    ]);
    if ($isCustomerId) {
        disable_paid_service_toggles_for_customer($customerIdOrEmail, $reason);
    } else {
        disable_paid_service_toggles_for_email($customerIdOrEmail, $reason);
    }
}

function mark_auto_payment_failed_keep_wallet_access(string $email, array $account, string $reason = 'auto_payment_failed'): void {
    $customerId = trim((string)($account['customer_id'] ?? ''));
    if ($email === '' && $customerId === '') {
        return;
    }
    $walletBalance = (int)($account['wallet_balance_paise'] ?? 0);
    if ($walletBalance <= 0) {
        downgrade_billing_account_to_free($customerId ?: $email, $reason);
        return;
    }
    supabase("PATCH", "billing_accounts?" . billing_account_filter($customerId, $email), [
        "subscription_status" => "cancelled",
        "auto_recharge_enabled" => false,
        "saved_payment_method_status" => "failed",
        "saved_payment_method_reference" => null
    ]);
}

function enforce_billing_free_transition(array $account): void {
    $email = trim((string)($account['email'] ?? ''));
    $customerId = trim((string)($account['customer_id'] ?? ''));
    if ($email === '' && $customerId === '') {
        return;
    }
    $status = (string)($account['subscription_status'] ?? 'free');
    $periodEnd = (string)($account['current_period_end'] ?? '');
    $walletBalance = (int)($account['wallet_balance_paise'] ?? 0);
    if ($status === 'cancelled' && $walletBalance <= 0) {
        downgrade_billing_account_to_free($customerId ?: $email, 'wallet_empty');
        return;
    }
    if ($status === 'active' && $periodEnd !== '' && strtotime($periodEnd) < time()) {
        downgrade_billing_account_to_free($customerId ?: $email, 'plan_expired');
    }
}

function billing_active_plan_for_email(string $email): string {
    return billing_active_plan_from_account(billing_account_for_email($email));
}

function enforce_free_when_wallet_empty_for_account(array $account): void {
    $email = trim((string)($account['email'] ?? ''));
    $customerId = trim((string)($account['customer_id'] ?? ''));
    if ($email === '' && $customerId === '') {
        return;
    }
    if ((string)($account['subscription_status'] ?? '') === 'cancelled' && (int)($account['wallet_balance_paise'] ?? 0) <= 0) {
        supabase("PATCH", "billing_accounts?" . billing_account_filter($customerId, $email), [
            "current_plan" => "free",
            "subscription_status" => "free",
            "auto_recharge_enabled" => false
        ]);
    }
}

function faq_active_limit_for_customer(string $customerId): int {
    $account = billing_account_for_customer($customerId);
    enforce_free_when_wallet_empty_for_account($account);
    return billing_faq_limit(billing_active_plan_from_account($account));
}

function faq_active_query_suffix(string $customerId, string $order = "id.asc"): string {
    $limit = faq_active_limit_for_customer($customerId);
    $suffix = "&order=" . rawurlencode($order);
    if ($limit !== PHP_INT_MAX) {
        $suffix .= "&limit=" . max(0, $limit);
    }
    return $suffix;
}

function billing_email_for_customer(string $customerId): string {
    $rows = safe_rows(supabase(
        "GET",
        "chatbot_signups?select=email&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    return trim((string)($rows[0]['email'] ?? ''));
}

function authenticated_customer_access(string $customerId): bool {
    if (!is_authenticated_user() || $customerId === '') {
        return false;
    }
    return strcasecmp(billing_email_for_customer($customerId), authenticated_email()) === 0;
}

function webhook_deliver_for_customer(string $customerId, string $event, array $data = []): array {
    if ($customerId === '') {
        return ["success" => false, "message" => "Missing customer_id"];
    }
    $activePlan = billing_active_plan_from_account(billing_account_for_customer($customerId));
    if (!billing_feature_enabled($activePlan, 'webhook_support')) {
        return ["success" => false, "message" => "Webhook support requires an active paid plan"];
    }
    $settingsRows = safe_rows(supabase(
        "GET",
        "chatbot_settings?select=webhook_url,webhook_secret&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    $settings = $settingsRows[0] ?? [];
    $url = trim((string)($settings['webhook_url'] ?? ''));
    if ($url === '' || !preg_match('{^https://\S+$}i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ["success" => false, "message" => "Save a valid HTTPS webhook URL first"];
    }

    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $payload = [
        "event" => $event,
        "event_id" => bin2hex(random_bytes(16)),
        "customer_id" => $customerId,
        "created_at" => $timestamp,
        "data" => $data
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ["success" => false, "message" => "Webhook payload could not be encoded"];
    }

    $headers = [
        "Content-Type: application/json",
        "User-Agent: Vani-Webhook/1.0",
        "X-Vani-Event: " . $event,
        "X-Vani-Timestamp: " . $timestamp
    ];
    $secret = trim((string)($settings['webhook_secret'] ?? ''));
    if ($secret !== '') {
        $headers[] = "X-Vani-Signature: sha256=" . hash_hmac('sha256', $timestamp . "." . $json, $secret);
    }

    $context = stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => implode("\r\n", $headers),
            "content" => $json,
            "timeout" => 3,
            "ignore_errors" => true
        ]
    ]);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('{HTTP/\S+\s(\d{3})}', $http_response_header[0], $match)) {
        $status = (int)($match[1] ?? 0);
    }
    return [
        "success" => $response !== false && $status >= 200 && $status < 300,
        "status_code" => $status,
        "message" => $response !== false && $status >= 200 && $status < 300 ? "Webhook delivered" : "Webhook endpoint did not return a 2xx response"
    ];
}

function customer_api_key_rows(string $customerId): array {
    return safe_rows(supabase(
        "GET",
        "customer_api_keys?select=id,name,key_prefix,allowed_ips,allowed_origins,rate_limit_per_day,last_used_at,revoked_at,created_at&customer_id=eq." . urlencode($customerId) . "&order=created_at.desc"
    ));
}

function split_access_list(string $value): array {
    $parts = preg_split('/[\s,]+/', trim($value));
    $clean = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part !== '') {
            $clean[$part] = true;
        }
    }
    return array_keys($clean);
}

function request_api_key(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $header, $match)) {
        return trim($match[1]);
    }
    return trim((string)($_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? ''));
}

function request_ip_address(): string {
    $value = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
    return trim(explode(',', $value)[0] ?? $value);
}

function request_origin_value(): string {
    $value = trim((string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''));
    if ($value === '') {
        return '';
    }
    $scheme = parse_url($value, PHP_URL_SCHEME);
    $host = parse_url($value, PHP_URL_HOST);
    $port = parse_url($value, PHP_URL_PORT);
    if ($scheme && $host) {
        return strtolower($scheme . '://' . $host . ($port ? ':' . $port : ''));
    }
    return $value;
}

function log_customer_api_usage(string $customerId, ?int $apiKeyId, string $endpoint, int $statusCode): void {
    supabase("POST", "customer_api_usage_logs", [[
        "customer_id" => $customerId,
        "api_key_id" => $apiKeyId,
        "endpoint" => $endpoint,
        "ip_address" => substr(request_ip_address(), 0, 120),
        "origin" => substr(request_origin_value(), 0, 500),
        "status_code" => $statusCode
    ]]);
}

function validate_customer_api_request(string $endpoint): array {
    $apiKey = request_api_key();
    if ($apiKey === '' || !preg_match('/^vani_live_([a-f0-9]{12})_[a-f0-9]{48}$/', $apiKey, $match)) {
        return ["success" => false, "status" => 401, "message" => "Missing or invalid API key"];
    }

    $keyPrefix = $match[1];
    $rows = safe_rows(supabase(
        "GET",
        "customer_api_keys?select=*&key_prefix=eq." . urlencode($keyPrefix) . "&limit=1"
    ));
    $row = $rows[0] ?? [];
    $customerId = (string)($row['customer_id'] ?? '');
    $keyId = isset($row['id']) ? (int)$row['id'] : null;

    if (empty($row) || !empty($row['revoked_at']) || !password_verify($apiKey, (string)($row['key_hash'] ?? ''))) {
        if ($customerId !== '') {
            log_customer_api_usage($customerId, $keyId, $endpoint, 401);
        }
        return ["success" => false, "status" => 401, "message" => "Invalid or revoked API key"];
    }

    $activePlan = billing_active_plan_from_account(billing_account_for_customer($customerId));
    if (!billing_feature_enabled($activePlan, 'api_access')) {
        log_customer_api_usage($customerId, $keyId, $endpoint, 403);
        return ["success" => false, "status" => 403, "message" => "API access requires Business plan"];
    }

    $allowedIps = split_access_list((string)($row['allowed_ips'] ?? ''));
    $requestIp = request_ip_address();
    if (!empty($allowedIps) && !in_array($requestIp, $allowedIps, true)) {
        log_customer_api_usage($customerId, $keyId, $endpoint, 403);
        return ["success" => false, "status" => 403, "message" => "This IP address is not allowed"];
    }

    $allowedOrigins = split_access_list((string)($row['allowed_origins'] ?? ''));
    $origin = request_origin_value();
    if (!empty($allowedOrigins) && !in_array($origin, $allowedOrigins, true)) {
        log_customer_api_usage($customerId, $keyId, $endpoint, 403);
        return ["success" => false, "status" => 403, "message" => "This origin is not allowed"];
    }

    $rateLimit = max(1, (int)($row['rate_limit_per_day'] ?? 1000));
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
    $usageRows = safe_rows(supabase(
        "GET",
        "customer_api_usage_logs?select=id&api_key_id=eq." . urlencode((string)$keyId) . "&created_at=gte." . urlencode($since) . "&limit=" . urlencode((string)($rateLimit + 1))
    ));
    if (count($usageRows) >= $rateLimit) {
        log_customer_api_usage($customerId, $keyId, $endpoint, 429);
        return ["success" => false, "status" => 429, "message" => "Daily API rate limit reached"];
    }

    supabase("PATCH", "customer_api_keys?id=eq." . urlencode((string)$keyId), [
        "last_used_at" => gmdate('Y-m-d\TH:i:s\Z')
    ]);
    log_customer_api_usage($customerId, $keyId, $endpoint, 200);
    return ["success" => true, "status" => 200, "customer_id" => $customerId, "api_key_id" => $keyId, "active_plan" => $activePlan];
}

function customer_api_limit(int $default = 100, int $max = 500): int {
    $limit = (int)($_GET['limit'] ?? $default);
    return max(1, min($max, $limit));
}

function customer_api_offset(): int {
    return max(0, (int)($_GET['offset'] ?? 0));
}

function customer_api_date_filters(string $field = 'created_at'): string {
    $filters = '';
    $from = trim((string)($_GET['date_from'] ?? ''));
    $to = trim((string)($_GET['date_to'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $filters .= '&' . $field . '=gte.' . urlencode($from . 'T00:00:00Z');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $filters .= '&' . $field . '=lte.' . urlencode($to . 'T23:59:59Z');
    }
    return $filters;
}

function customer_api_rows(string $endpoint, int $defaultLimit = 100, int $maxLimit = 500): array {
    $limit = customer_api_limit($defaultLimit, $maxLimit);
    $offset = customer_api_offset();
    $separator = str_contains($endpoint, '?') ? '&' : '?';
    return safe_rows(supabase(
        "GET",
        $endpoint . $separator . "limit=" . urlencode((string)$limit) . "&offset=" . urlencode((string)$offset)
    ));
}

function customer_api_json(array $validation, string $resource, array $payload = []): void {
    http_response_code((int)$validation['status']);
    if (empty($validation['success'])) {
        echo json_encode(["success" => false, "resource" => $resource, "message" => $validation['message'] ?? "API request failed"]);
        exit;
    }
    echo json_encode(array_merge([
        "success" => true,
        "resource" => $resource,
        "customer_id" => $validation['customer_id'],
        "active_plan" => $validation['active_plan']
    ], $payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function count_rows(array $rows, callable $callback): int {
    return count(array_filter($rows, $callback));
}

function customer_api_analytics_payload(string $customerId): array {
    $dateFilters = customer_api_date_filters('created_at');
    $conversations = safe_rows(supabase(
        "GET",
        "chatbot_conversations?select=id,user_question,bot_response,matched_faq_id,status,is_answered,user_id,session_id,source_url,device_type,browser_name,country_name,city,response_time_ms,created_at&customer_id=eq." . urlencode($customerId) . $dateFilters . "&order=created_at.desc&limit=1000"
    ));
    $leads = safe_rows(supabase(
        "GET",
        "lead_generation_leads?select=id,email,phone_number,source_url,email_otp_verified,mobile_otp_verified,verification_quality,created_at&customer_id=eq." . urlencode($customerId) . $dateFilters . "&order=created_at.desc&limit=1000"
    ));
    $sessions = safe_rows(supabase(
        "GET",
        "chatbot_sessions?select=id,user_id,session_id,current_page,source_url,device_type,browser_name,country_name,city,duration_seconds,message_count,last_seen_at,ended_at,created_at&customer_id=eq." . urlencode($customerId) . $dateFilters . "&order=created_at.desc&limit=1000"
    ));

    $dailyCounts = [];
    $sourcePages = [];
    $devices = [];
    $browsers = [];
    $countries = [];
    $uniqueUsers = [];
    $responseTimes = [];
    $topQuestions = [];
    $answeredCount = 0;
    $unansweredCount = 0;

    foreach ($conversations as $row) {
        $created = (string)($row['created_at'] ?? '');
        if ($created !== '') {
            $day = substr($created, 0, 10);
            $dailyCounts[$day] = ($dailyCounts[$day] ?? 0) + 1;
        }
        $answered = strtolower((string)($row['status'] ?? '')) === 'answered' || !empty($row['is_answered']);
        $answered ? $answeredCount++ : $unansweredCount++;
        $sourceUrl = trim((string)($row['source_url'] ?? ''));
        $sourceLabel = $sourceUrl !== '' ? (parse_url($sourceUrl, PHP_URL_PATH) ?: $sourceUrl) : 'Unknown page';
        if (!isset($sourcePages[$sourceLabel])) {
            $sourcePages[$sourceLabel] = ["page" => $sourceLabel, "conversations" => 0, "leads" => 0, "answered" => 0];
        }
        $sourcePages[$sourceLabel]['conversations']++;
        if ($answered) {
            $sourcePages[$sourceLabel]['answered']++;
        }
        $question = trim((string)($row['user_question'] ?? ''));
        if ($question !== '') {
            $key = strtolower($question);
            $topQuestions[$key] = [
                "question" => $question,
                "count" => ($topQuestions[$key]['count'] ?? 0) + 1,
                "answered" => ($topQuestions[$key]['answered'] ?? 0) + ($answered ? 1 : 0)
            ];
        }
        $device = trim((string)($row['device_type'] ?? ''));
        if ($device !== '') {
            $devices[$device] = ($devices[$device] ?? 0) + 1;
        }
        $browser = trim((string)($row['browser_name'] ?? ''));
        if ($browser !== '') {
            $browsers[$browser] = ($browsers[$browser] ?? 0) + 1;
        }
        $country = trim((string)($row['country_name'] ?? ''));
        if ($country !== '') {
            $countries[$country] = ($countries[$country] ?? 0) + 1;
        }
        $userId = trim((string)($row['user_id'] ?? ''));
        if ($userId !== '') {
            $uniqueUsers[$userId] = true;
        }
        $responseTime = (int)($row['response_time_ms'] ?? 0);
        if ($responseTime > 0) {
            $responseTimes[] = $responseTime;
        }
    }

    foreach ($leads as $lead) {
        $sourceUrl = trim((string)($lead['source_url'] ?? ''));
        $sourceLabel = $sourceUrl !== '' ? (parse_url($sourceUrl, PHP_URL_PATH) ?: $sourceUrl) : 'Unknown page';
        if (!isset($sourcePages[$sourceLabel])) {
            $sourcePages[$sourceLabel] = ["page" => $sourceLabel, "conversations" => 0, "leads" => 0, "answered" => 0];
        }
        $sourcePages[$sourceLabel]['leads']++;
    }

    foreach ($sessions as $session) {
        $userId = trim((string)($session['user_id'] ?? ''));
        if ($userId !== '') {
            $uniqueUsers[$userId] = true;
        }
    }

    uasort($topQuestions, fn($a, $b) => $b['count'] <=> $a['count']);
    uasort($sourcePages, fn($a, $b) => $b['conversations'] <=> $a['conversations']);
    ksort($dailyCounts);
    arsort($devices);
    arsort($browsers);
    arsort($countries);

    $conversationCount = count($conversations);
    $leadCount = count($leads);
    $verifiedLeadCount = count_rows($leads, fn($lead) => !empty($lead['email_otp_verified']) || !empty($lead['mobile_otp_verified']) || (string)($lead['verification_quality'] ?? '') === 'real');

    return [
        "summary" => [
            "conversations" => $conversationCount,
            "answered" => $answeredCount,
            "unanswered" => $unansweredCount,
            "answer_rate_percent" => $conversationCount ? round(($answeredCount / max(1, $conversationCount)) * 100) : 0,
            "leads" => $leadCount,
            "verified_leads" => $verifiedLeadCount,
            "lead_conversion_percent" => $conversationCount ? round(($leadCount / max(1, $conversationCount)) * 100) : 0,
            "unique_users" => count($uniqueUsers),
            "avg_response_time_ms" => !empty($responseTimes) ? round(array_sum($responseTimes) / count($responseTimes)) : 0
        ],
        "daily_counts" => $dailyCounts,
        "devices" => $devices,
        "browsers" => $browsers,
        "countries" => $countries,
        "top_questions" => array_values(array_slice(array_map(fn($item) => [
            "question" => $item['question'],
            "count" => $item['count'],
            "success_rate_percent" => $item['count'] ? round((($item['answered'] ?? 0) / max(1, $item['count'])) * 100) : 0
        ], $topQuestions), 0, 25)),
        "source_pages" => array_values(array_slice(array_map(fn($page) => [
            "page" => $page['page'],
            "conversations" => $page['conversations'],
            "leads" => $page['leads'],
            "success_rate_percent" => $page['conversations'] ? round(($page['answered'] / max(1, $page['conversations'])) * 100) : 0
        ], $sourcePages), 0, 25))
    ];
}

function create_handoff_ticket_if_enabled(string $customerId, array $settings, string $activePlan, string $question, string $botResponse, string $sourceUrl = '', string $userId = '', ?int $conversationId = null): void {
    if (!billing_feature_enabled($activePlan, 'human_handoff') || !filter_var($settings['handoff_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        return;
    }
    $notificationEmail = trim((string)($settings['handoff_email'] ?? ''));
    if (!filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $ticketPayload = [
        "customer_id" => $customerId,
        "conversation_id" => $conversationId,
        "user_id" => $userId !== '' ? $userId : null,
        "user_question" => $question,
        "bot_response" => $botResponse,
        "source_url" => $sourceUrl !== '' ? $sourceUrl : null,
        "status" => "open",
        "notification_email" => $notificationEmail,
        "email_sent" => false,
        "metadata" => (object)["created_by" => "human_handoff"]
    ];
    $ticketRes = supabase("POST", "support_tickets", [$ticketPayload]);
    $ticketId = $ticketRes['data'][0]['id'] ?? null;

    require_once __DIR__ . '/email.php';
    $subject = "New unanswered chatbot question";
    $html = "<p>The chatbot could not answer this question and created a support ticket.</p>"
        . "<p><strong>Question:</strong><br>" . htmlspecialchars($question, ENT_QUOTES, 'UTF-8') . "</p>"
        . "<p><strong>Bot response:</strong><br>" . htmlspecialchars($botResponse, ENT_QUOTES, 'UTF-8') . "</p>";
    if ($sourceUrl !== '') {
        $html .= "<p><strong>Source:</strong> " . htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8') . "</p>";
    }
    if ($ticketId) {
        $html .= "<p><strong>Ticket ID:</strong> " . htmlspecialchars((string)$ticketId, ENT_QUOTES, 'UTF-8') . "</p>";
    }
    $sent = sendBrevoEmail($notificationEmail, $subject, $html);
    if ($sent && $ticketId) {
        supabase("PATCH", "support_tickets?id=eq." . urlencode((string)$ticketId), [
            "email_sent" => true
        ]);
    }
}

function razorpay_credentials(): array {
    return [
        $_ENV['RAZORPAY_KEY_ID'] ?? getenv('RAZORPAY_KEY_ID') ?: '',
        $_ENV['RAZORPAY_KEY_SECRET'] ?? getenv('RAZORPAY_KEY_SECRET') ?: ''
    ];
}

function razorpay_request(string $method, string $endpoint, array $payload = []): array {
    [$keyId, $keySecret] = razorpay_credentials();
    if ($keyId === '' || $keySecret === '') {
        return ["status" => 500, "data" => [], "raw" => "Razorpay credentials missing"];
    }
    $options = [
        "http" => [
            "method" => $method,
            "header" => implode("\r\n", [
                "Content-Type: application/json",
                "Authorization: Basic " . base64_encode($keyId . ":" . $keySecret)
            ]),
            "ignore_errors" => true
        ]
    ];
    if (!empty($payload)) {
        $options["http"]["content"] = json_encode($payload);
    }
    $raw = file_get_contents("https://api.razorpay.com/v1/" . ltrim($endpoint, "/"), false, stream_context_create($options));
    $status = 0;
    if (isset($http_response_header[0])) {
        preg_match('{HTTP/\S*\s(\d{3})}', $http_response_header[0], $match);
        $status = intval($match[1] ?? 0);
    }
    return ["status" => $status, "data" => json_decode((string)$raw, true), "raw" => $raw];
}

function razorpay_auto_recharge_wallet(string $email, string $customerId, array $account, string $planId): array {
    $autoRecharge = wallet_auto_recharge_context($account, $planId);
    $amountPaise = (int)$autoRecharge['amount_paise'];
    $token = trim((string)($autoRecharge['payment_method_reference'] ?? ''));
    $razorpayCustomerId = trim((string)($account['saved_payment_method_customer_id'] ?? ''));
    $contact = trim((string)($account['saved_payment_method_contact'] ?? ''));

    if (empty($autoRecharge['enabled']) || $amountPaise <= 0) {
        return ["success" => false, "message" => "Auto recharge is not enabled"];
    }
    if ($autoRecharge['payment_method_status'] !== 'active' || $token === '' || $razorpayCustomerId === '') {
        return ["success" => false, "requires_payment_method" => true, "message" => "No active Razorpay recurring payment method is available"];
    }

    $receipt = substr("auto_" . $planId . "_" . time() . "_" . bin2hex(random_bytes(3)), 0, 40);
    $order = razorpay_request("POST", "orders", [
        "amount" => $amountPaise,
        "currency" => "INR",
        "payment_capture" => true,
        "receipt" => $receipt,
        "notes" => [
            "email" => $email,
            "customer_id" => $customerId,
            "plan_id" => $planId,
            "order_type" => "wallet_auto_recharge"
        ]
    ]);
    if ($order['status'] < 200 || $order['status'] >= 300 || empty($order['data']['id'])) {
        return ["success" => false, "message" => "Auto recharge order could not be created", "debug" => $order];
    }

    supabase("POST", "billing_orders", [[
        "email" => $email,
        "customer_id" => $customerId ?: null,
        "plan_id" => $planId,
        "order_type" => "wallet",
        "amount_paise" => $amountPaise,
        "currency" => "INR",
        "status" => "created",
        "razorpay_order_id" => $order['data']['id'],
        "receipt" => $receipt,
        "metadata" => (object)["auto_recharge" => true]
    ]]);

    $payment = razorpay_request("POST", "payments/create/recurring", [
        "email" => $email,
        "contact" => $contact,
        "amount" => $amountPaise,
        "currency" => "INR",
        "order_id" => $order['data']['id'],
        "customer_id" => $razorpayCustomerId,
        "token" => $token,
        "recurring" => true,
        "description" => "Vani wallet auto recharge",
        "notes" => [
            "email" => $email,
            "customer_id" => $customerId,
            "plan_id" => $planId
        ]
    ]);
    $paymentId = (string)($payment['data']['razorpay_payment_id'] ?? $payment['data']['id'] ?? '');
    $paymentStatus = (string)($payment['data']['status'] ?? '');
    if ($payment['status'] < 200 || $payment['status'] >= 300 || $paymentId === '') {
        supabase("PATCH", "billing_orders?razorpay_order_id=eq." . urlencode((string)$order['data']['id']), [
            "status" => "failed",
            "metadata" => (object)["auto_recharge" => true, "payment_response" => $payment['data'] ?? []]
        ]);
        supabase("PATCH", "billing_accounts?" . billing_account_filter($customerId, $email), [
            "subscription_status" => ((int)($account['wallet_balance_paise'] ?? 0) > 0 ? "cancelled" : "free"),
            "auto_recharge_enabled" => false,
            "saved_payment_method_status" => "failed",
            "saved_payment_method_reference" => null
        ]);
        if ((int)($account['wallet_balance_paise'] ?? 0) <= 0) {
            downgrade_billing_account_to_free($customerId ?: $email, 'auto_payment_failed');
        }
        return ["success" => false, "message" => "Auto recharge payment failed", "debug" => $payment];
    }

    if (!in_array($paymentStatus, ['captured', 'authorized'], true)) {
        supabase("PATCH", "billing_orders?razorpay_order_id=eq." . urlencode((string)$order['data']['id']), [
            "razorpay_payment_id" => $paymentId,
            "metadata" => (object)["auto_recharge" => true, "payment_status" => $paymentStatus]
        ]);
        mark_auto_payment_failed_keep_wallet_access($email, $account, 'auto_payment_pending_or_failed');
        return ["success" => false, "pending" => true, "message" => "Auto recharge payment is pending", "payment_status" => $paymentStatus];
    }

    $accountAfterOrder = $customerId !== '' ? billing_account_for_customer($customerId) : billing_account_for_email($email);
    $newBalance = (int)($accountAfterOrder['wallet_balance_paise'] ?? 0) + $amountPaise;
    supabase("PATCH", "billing_accounts?" . billing_account_filter($customerId, $email), [
        "wallet_balance_paise" => $newBalance,
        "saved_payment_method_status" => "active"
    ]);
    supabase("POST", "wallet_transactions", [[
        "email" => $email,
        "customer_id" => $customerId ?: null,
        "transaction_type" => "credit",
        "amount_paise" => $amountPaise,
        "balance_after_paise" => $newBalance,
        "description" => "Auto wallet recharge: " . billing_plan($planId)['name'],
        "reference_type" => "razorpay_auto_recharge",
        "reference_id" => $paymentId,
        "metadata" => (object)["plan_id" => $planId, "threshold_paise" => $autoRecharge['threshold_paise']]
    ]]);
    supabase("PATCH", "billing_orders?razorpay_order_id=eq." . urlencode((string)$order['data']['id']), [
        "status" => "paid",
        "razorpay_payment_id" => $paymentId,
        "paid_at" => gmdate('Y-m-d\TH:i:s\Z')
    ]);

    $invoice = create_customer_invoice(
        $customerId,
        $email,
        $planId,
        $amountPaise,
        $paymentId,
        (string)$order['data']['id'],
        'auto_recharge',
        ["source" => "wallet_auto_recharge", "threshold_paise" => $autoRecharge['threshold_paise']]
    );
    if (!empty($invoice)) {
        send_customer_invoice_email($invoice);
    }

    return ["success" => true, "amount_paise" => $amountPaise, "balance_after_paise" => $newBalance, "payment_id" => $paymentId];
}

function wallet_credit_subscription(string $email, string $customerId, string $planId, int $amountPaise, string $paymentId): void {
    $account = $customerId !== '' ? billing_account_for_customer($customerId) : billing_account_for_email($email);
    $balance = (int)($account['wallet_balance_paise'] ?? 0) + $amountPaise;
    $periodStart = gmdate('Y-m-d\TH:i:s\Z');
    $periodEnd = gmdate('Y-m-d\TH:i:s\Z', strtotime('+30 days'));
    $autoRechargeRule = billing_auto_recharge_rule($planId);
    supabase("PATCH", "billing_accounts?" . billing_account_filter($customerId, $email), [
        "wallet_balance_paise" => $balance,
        "current_plan" => $planId,
        "subscription_status" => "active",
        "auto_recharge_enabled" => $planId !== 'free',
        "auto_recharge_threshold_paise" => (int)$autoRechargeRule['threshold_paise'],
        "auto_recharge_amount_paise" => (int)$autoRechargeRule['amount_paise'],
        "current_period_start" => $periodStart,
        "current_period_end" => $periodEnd
    ]);
    supabase("POST", "wallet_transactions", [[
        "email" => $email,
        "customer_id" => $customerId ?: null,
        "transaction_type" => "credit",
        "amount_paise" => $amountPaise,
        "balance_after_paise" => $balance,
        "description" => "Subscription amount added to wallet: " . billing_plan($planId)['name'],
        "reference_type" => "razorpay_payment",
        "reference_id" => $paymentId,
        "metadata" => (object)["plan_id" => $planId]
    ]]);
}

function wallet_auto_recharge_context(array $account, string $planId): array {
    $rule = billing_auto_recharge_rule($planId);
    $threshold = (int)($account['auto_recharge_threshold_paise'] ?? 0) ?: (int)$rule['threshold_paise'];
    $amount = (int)($account['auto_recharge_amount_paise'] ?? 0) ?: (int)$rule['amount_paise'];
    return [
        "enabled" => filter_var($account['auto_recharge_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        "threshold_paise" => $threshold,
        "amount_paise" => $amount,
        "payment_method_status" => (string)($account['saved_payment_method_status'] ?? 'missing'),
        "payment_method_reference" => (string)($account['saved_payment_method_reference'] ?? ''),
        "payment_method_customer_id" => (string)($account['saved_payment_method_customer_id'] ?? '')
    ];
}

function wallet_adjust_balance(string $email, string $customerId, int $amountPaise, string $transactionType, string $description, string $referenceType, string $referenceId, array $metadata = []): array {
    if ($email === '' || $amountPaise <= 0) {
        return ["success" => true, "charged" => false, "message" => "No wallet adjustment required"];
    }
    $account = $customerId !== '' ? billing_account_for_customer($customerId) : billing_account_for_email($email);
    $balance = (int)($account['wallet_balance_paise'] ?? 0);
    if ($transactionType === 'debit' && $balance < $amountPaise) {
        $planId = billing_active_plan_from_account($account);
        $autoRecharge = wallet_auto_recharge_context($account, $planId);
        supabase("PATCH", "billing_accounts?" . billing_account_filter($customerId, $email), [
            "last_auto_recharge_attempt_at" => gmdate('Y-m-d\TH:i:s\Z')
        ]);
        $recharge = razorpay_auto_recharge_wallet($email, $customerId, $account, $planId);
        if (!empty($recharge['success'])) {
            $account = $customerId !== '' ? billing_account_for_customer($customerId) : billing_account_for_email($email);
            $balance = (int)($account['wallet_balance_paise'] ?? 0);
        } else {
            mark_auto_payment_failed_keep_wallet_access($email, $account, 'auto_payment_failed');
            return [
                "success" => false,
                "charged" => false,
                "auto_recharge" => $autoRecharge,
                "auto_recharge_result" => $recharge,
                "requires_payment_method" => !empty($recharge['requires_payment_method']) || empty($autoRecharge['enabled']) || $autoRecharge['payment_method_status'] !== 'active',
                "message" => $recharge['message'] ?? "Insufficient wallet balance"
            ];
        }
        if ($balance < $amountPaise) {
            return ["success" => false, "charged" => false, "auto_recharge" => $autoRecharge, "auto_recharge_result" => $recharge, "message" => "Insufficient wallet balance after auto recharge"];
        }
    }
    $newBalance = $transactionType === 'credit' ? $balance + $amountPaise : $balance - $amountPaise;
    supabase("PATCH", "billing_accounts?" . billing_account_filter($customerId, $email), [
        "wallet_balance_paise" => $newBalance
    ]);
    $txn = supabase("POST", "wallet_transactions", [[
        "email" => $email,
        "customer_id" => $customerId ?: null,
        "transaction_type" => $transactionType,
        "amount_paise" => $amountPaise,
        "balance_after_paise" => $newBalance,
        "description" => $description,
        "reference_type" => $referenceType,
        "reference_id" => $referenceId,
        "metadata" => (object)$metadata
    ]]);
    if ($txn['status'] < 200 || $txn['status'] >= 300) {
        supabase("PATCH", "billing_accounts?" . billing_account_filter($customerId, $email), [
            "wallet_balance_paise" => $balance
        ]);
        return [
            "success" => false,
            "charged" => false,
            "message" => "Wallet transaction could not be recorded",
            "debug" => $txn
        ];
    }
    if ($transactionType === 'debit' && $newBalance <= 0 && (string)($account['subscription_status'] ?? '') === 'cancelled') {
        downgrade_billing_account_to_free($customerId ?: $email, 'wallet_empty');
    }
    return [
        "success" => true,
        "charged" => true,
        "balance_after_paise" => $newBalance,
        "transaction" => $txn['data'][0] ?? null,
        "debug" => $txn
    ];
}

function wallet_debit_usage(string $email, string $customerId, int $amountPaise, string $description, string $referenceType, string $referenceId, array $metadata = []): array {
    return wallet_adjust_balance($email, $customerId, $amountPaise, 'debit', $description, $referenceType, $referenceId, $metadata);
}

function wallet_credit_usage(string $email, string $customerId, int $amountPaise, string $description, string $referenceType, string $referenceId, array $metadata = []): array {
    return wallet_adjust_balance($email, $customerId, $amountPaise, 'credit', $description, $referenceType, $referenceId, $metadata);
}

function wallet_debit_without_auto_recharge(string $email, string $customerId, int $amountPaise, string $description, string $referenceType, string $referenceId, array $metadata = []): array {
    if ($email === '' || $amountPaise <= 0) {
        return ["success" => true, "charged" => false, "message" => "No wallet charge required"];
    }
    $account = $customerId !== '' ? billing_account_for_customer($customerId) : billing_account_for_email($email);
    $balance = (int)($account['wallet_balance_paise'] ?? 0);
    if ($balance < $amountPaise) {
        return [
            "success" => false,
            "charged" => false,
            "balance_paise" => $balance,
            "required_paise" => $amountPaise,
            "message" => "Wallet balance is less than " . billing_rupees($amountPaise)
        ];
    }
    $newBalance = $balance - $amountPaise;
    supabase("PATCH", "billing_accounts?" . billing_account_filter($customerId, $email), [
        "wallet_balance_paise" => $newBalance
    ]);
    $txn = supabase("POST", "wallet_transactions", [[
        "email" => $email,
        "customer_id" => $customerId ?: null,
        "transaction_type" => "debit",
        "amount_paise" => $amountPaise,
        "balance_after_paise" => $newBalance,
        "description" => $description,
        "reference_type" => $referenceType,
        "reference_id" => $referenceId,
        "metadata" => (object)$metadata
    ]]);
    if ($txn['status'] < 200 || $txn['status'] >= 300) {
        supabase("PATCH", "billing_accounts?" . billing_account_filter($customerId, $email), [
            "wallet_balance_paise" => $balance
        ]);
        return ["success" => false, "charged" => false, "message" => "Wallet transaction could not be recorded", "debug" => $txn];
    }
    if ($newBalance <= 0 && (string)($account['subscription_status'] ?? '') === 'cancelled') {
        downgrade_billing_account_to_free($customerId ?: $email, 'wallet_empty');
    }
    return [
        "success" => true,
        "charged" => true,
        "balance_after_paise" => $newBalance,
        "transaction" => $txn['data'][0] ?? null,
        "debug" => $txn
    ];
}

function wallet_record_zero_debit(string $email, string $customerId, string $description, string $referenceType, string $referenceId, array $metadata = []): void {
    if ($email === '') {
        return;
    }
    $account = $customerId !== '' ? billing_account_for_customer($customerId) : billing_account_for_email($email);
    supabase("POST", "wallet_transactions", [[
        "email" => $email,
        "customer_id" => $customerId ?: null,
        "transaction_type" => "debit",
        "amount_paise" => 0,
        "balance_after_paise" => (int)($account['wallet_balance_paise'] ?? 0),
        "description" => $description,
        "reference_type" => $referenceType,
        "reference_id" => $referenceId,
        "metadata" => (object)$metadata
    ]]);
}

function send_whatsapp_redirect_stopped_email(string $toEmail, string $websiteName = ''): void {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    require_once __DIR__ . '/email.php';
    $siteText = $websiteName !== '' ? " of " . htmlspecialchars($websiteName, ENT_QUOTES, 'UTF-8') : "";
    $html = "<p>Your WhatsApp redirection service from chatbot" . $siteText . " is stopped due to insufficient wallet balance.</p>"
        . "<p>Please recharge your wallet to turn WhatsApp redirection ON again.</p>";
    sendBrevoEmail($toEmail, "WhatsApp redirection stopped due to insufficient wallet balance", $html);
}

function stop_whatsapp_redirect_for_insufficient_balance(string $customerId, string $billingEmail, int $amountPaise): void {
    $signupRows = safe_rows(supabase(
        "GET",
        "chatbot_signups?select=website_name,email&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    $websiteName = (string)($signupRows[0]['website_name'] ?? '');
    $email = $billingEmail ?: (string)($signupRows[0]['email'] ?? '');
    supabase("PATCH", "lead_generation_settings?customer_id=eq." . urlencode($customerId), [
        "redirect_whatsapp" => false,
        "whatsapp_redirect_stopped_at" => gmdate('Y-m-d\TH:i:s\Z'),
        "whatsapp_redirect_stopped_reason" => "insufficient_wallet_balance",
        "whatsapp_redirect_failed_charge_amount_paise" => $amountPaise
    ]);
    wallet_record_zero_debit(
        $email,
        $customerId,
        "WhatsApp Redirect renewal skipped: ₹0 charged because wallet balance was insufficient. Service turned OFF.",
        "whatsapp_redirect_addon_failed",
        $customerId,
        ["required_paise" => $amountPaise, "reason" => "insufficient_wallet_balance"]
    );
    send_whatsapp_redirect_stopped_email($email, $websiteName);
}

function profile_for_billing_email(string $email): array {
    $rows = safe_rows(supabase(
        "GET",
        "customer_profiles?select=first_name,last_name,country_code,mobile_number&email=eq." . urlencode($email) . "&limit=1"
    ));
    return $rows[0] ?? [];
}

function normalize_razorpay_customer_name(string $email, array $profile, string $inputName = ''): string {
    $profileName = trim((string)($profile['first_name'] ?? '') . " " . (string)($profile['last_name'] ?? ''));
    $name = trim($inputName) ?: $profileName;
    if ($name === '') {
        $name = preg_replace('/@.*/', '', $email) ?: $email;
    }
    $name = substr($name, 0, 50);
    return strlen($name) < 3 ? "Vani Customer" : $name;
}

function normalize_razorpay_contact(array $profile, string $inputContact = ''): string {
    $contact = trim($inputContact);
    if ($contact === '') {
        $contact = trim((string)($profile['country_code'] ?? '') . (string)($profile['mobile_number'] ?? ''));
    }
    $contact = preg_replace('/[^\d+]/', '', $contact);
    if ($contact !== '' && $contact[0] !== '+') {
        $contact = "+91" . ltrim($contact, "0");
    }
    return $contact;
}

function ensure_razorpay_customer_for_account(string $email, array $account, string $name = '', string $contact = ''): array {
    $existingCustomerId = trim((string)($account['saved_payment_method_customer_id'] ?? ''));
    if ($existingCustomerId !== '') {
        return [
            "success" => true,
            "razorpay_customer_id" => $existingCustomerId,
            "contact" => (string)($account['saved_payment_method_contact'] ?? $contact),
            "existing" => true
        ];
    }

    $profile = profile_for_billing_email($email);
    $customerName = normalize_razorpay_customer_name($email, $profile, $name);
    $customerContact = normalize_razorpay_contact($profile, $contact);
    if ($customerContact === '' || strlen($customerContact) > 15 || !preg_match('/^\+\d{8,14}$/', $customerContact)) {
        return ["success" => false, "message" => "Enter a valid mobile number with country code, for example +919876543210"];
    }

    $customer = razorpay_request("POST", "customers", [
        "name" => $customerName,
        "email" => $email,
        "contact" => $customerContact,
        "fail_existing" => "0",
        "notes" => [
            "source" => "vani_dashboard",
            "purpose" => "wallet_auto_recharge"
        ]
    ]);

    if ($customer['status'] < 200 || $customer['status'] >= 300 || empty($customer['data']['id'])) {
        return [
            "success" => false,
            "message" => $customer['data']['error']['description'] ?? "Razorpay customer could not be created",
            "debug" => $customer['data'] ?? []
        ];
    }

    $razorpayCustomerId = (string)$customer['data']['id'];
    $update = supabase("PATCH", "billing_accounts?" . billing_account_filter((string)($account['customer_id'] ?? ''), $email), [
        "saved_payment_method_customer_id" => $razorpayCustomerId,
        "saved_payment_method_contact" => $customerContact,
        "saved_payment_method_status" => (string)($account['saved_payment_method_status'] ?? 'missing') ?: "missing"
    ]);
    if ($update['status'] < 200 || $update['status'] >= 300) {
        return [
            "success" => false,
            "message" => "Razorpay customer was created but could not be saved in billing account",
            "razorpay_customer_id" => $razorpayCustomerId,
            "debug" => $update
        ];
    }

    return [
        "success" => true,
        "razorpay_customer_id" => $razorpayCustomerId,
        "contact" => $customerContact,
        "existing" => false
    ];
}

// ==========================
// ==========================

if ($action === "list_customer_api_keys") {
    $customerId = trim((string)($_GET['customer_id'] ?? ''));
    if (!authenticated_customer_access($customerId)) {
        echo json_encode(["success" => false, "message" => "Access denied"]);
        exit;
    }
    echo json_encode(["success" => true, "keys" => customer_api_key_rows($customerId)]);
    exit;
}

if ($action === "create_customer_api_key") {
    $data = getJSON();
    $customerId = trim((string)($data['customer_id'] ?? $_GET['customer_id'] ?? ''));
    if (!authenticated_customer_access($customerId)) {
        echo json_encode(["success" => false, "message" => "Access denied"]);
        exit;
    }
    $activePlan = billing_active_plan_from_account(billing_account_for_customer($customerId));
    if (!billing_feature_enabled($activePlan, 'api_access')) {
        echo json_encode(["success" => false, "requires_business" => true, "message" => "API access requires Business plan"]);
        exit;
    }

    $name = trim((string)($data['name'] ?? 'API key'));
    $name = $name !== '' ? substr($name, 0, 80) : 'API key';
    $rateLimit = max(1, min(100000, (int)($data['rate_limit_per_day'] ?? 1000)));
    $allowedIps = implode("\n", split_access_list((string)($data['allowed_ips'] ?? '')));
    $allowedOrigins = implode("\n", split_access_list((string)($data['allowed_origins'] ?? '')));
    $keyPrefix = bin2hex(random_bytes(6));
    $apiKey = "vani_live_" . $keyPrefix . "_" . bin2hex(random_bytes(24));

    $res = supabase("POST", "customer_api_keys", [[
        "customer_id" => $customerId,
        "name" => $name,
        "key_prefix" => $keyPrefix,
        "key_hash" => password_hash($apiKey, PASSWORD_DEFAULT),
        "allowed_ips" => $allowedIps !== '' ? $allowedIps : null,
        "allowed_origins" => $allowedOrigins !== '' ? $allowedOrigins : null,
        "rate_limit_per_day" => $rateLimit
    ]]);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        echo json_encode(["success" => false, "message" => "API key could not be created", "debug" => $res]);
        exit;
    }
    echo json_encode(["success" => true, "api_key" => $apiKey, "keys" => customer_api_key_rows($customerId)]);
    exit;
}

if ($action === "revoke_customer_api_key") {
    $data = getJSON();
    $customerId = trim((string)($data['customer_id'] ?? $_GET['customer_id'] ?? ''));
    $keyId = trim((string)($data['key_id'] ?? ''));
    if (!authenticated_customer_access($customerId) || $keyId === '') {
        echo json_encode(["success" => false, "message" => "Access denied"]);
        exit;
    }
    $res = supabase(
        "PATCH",
        "customer_api_keys?id=eq." . urlencode($keyId) . "&customer_id=eq." . urlencode($customerId),
        ["revoked_at" => gmdate('Y-m-d\TH:i:s\Z')]
    );
    if ($res['status'] < 200 || $res['status'] >= 300) {
        echo json_encode(["success" => false, "message" => "API key could not be revoked", "debug" => $res]);
        exit;
    }
    echo json_encode(["success" => true, "keys" => customer_api_key_rows($customerId)]);
    exit;
}

if ($action === "test_webhook") {
    $data = getJSON();
    $customerId = trim((string)($data['customer_id'] ?? $_GET['customer_id'] ?? ''));
    if (!authenticated_customer_access($customerId)) {
        echo json_encode(["success" => false, "message" => "Access denied"]);
        exit;
    }
    $result = webhook_deliver_for_customer($customerId, "webhook.test", [
        "message" => "This is a test webhook from Vani.",
        "sent_from" => "dashboard"
    ]);
    echo json_encode($result);
    exit;
}

if ($action === "customer_api_ping") {
    $validation = validate_customer_api_request("customer_api_ping");
    http_response_code((int)$validation['status']);
    echo json_encode($validation['success']
        ? ["success" => true, "message" => "API key is valid", "customer_id" => $validation['customer_id'], "active_plan" => $validation['active_plan']]
        : ["success" => false, "message" => $validation['message']]
    );
    exit;
}

if ($action === "customer_api_leads") {
    $validation = validate_customer_api_request("customer_api_leads");
    if (empty($validation['success'])) {
        customer_api_json($validation, "leads");
    }
    $customerId = (string)$validation['customer_id'];
    $rows = customer_api_rows(
        "lead_generation_leads?select=id,user_id,conversation_id,name,email,phone_number,location_text,latitude,longitude,source_url,whatsapp_redirected,email_otp_verified,mobile_otp_verified,notification_email_sent,verification_quality,metadata,created_at&customer_id=eq." . urlencode($customerId) . customer_api_date_filters('created_at') . "&order=created_at.desc"
    );
    customer_api_json($validation, "leads", ["count" => count($rows), "data" => $rows]);
}

if ($action === "customer_api_conversations") {
    $validation = validate_customer_api_request("customer_api_conversations");
    if (empty($validation['success'])) {
        customer_api_json($validation, "conversations");
    }
    $customerId = (string)$validation['customer_id'];
    $rows = customer_api_rows(
        "chatbot_conversations?select=id,user_question,bot_response,matched_faq_id,status,is_answered,user_id,session_id,source_url,referrer_url,device_type,browser_name,browser_version,os_name,country_code,country_name,city,timezone,locale,screen_width,screen_height,response_time_ms,created_at&customer_id=eq." . urlencode($customerId) . customer_api_date_filters('created_at') . "&order=created_at.desc"
    );
    customer_api_json($validation, "conversations", ["count" => count($rows), "data" => $rows]);
}

if ($action === "customer_api_faqs") {
    $validation = validate_customer_api_request("customer_api_faqs");
    if (empty($validation['success'])) {
        customer_api_json($validation, "faqs");
    }
    $customerId = (string)$validation['customer_id'];
    $rows = customer_api_rows(
        "faq_questions?select=id,question,answer,category,created_at&customer_id=eq." . urlencode($customerId) . "&order=id.desc",
        100,
        1000
    );
    customer_api_json($validation, "faqs", ["count" => count($rows), "data" => $rows]);
}

if ($action === "customer_api_wallet") {
    $validation = validate_customer_api_request("customer_api_wallet");
    if (empty($validation['success'])) {
        customer_api_json($validation, "wallet");
    }
    $customerId = (string)$validation['customer_id'];
    $billingEmail = billing_email_for_customer($customerId);
    $account = billing_account_for_customer($customerId);
    $transactions = customer_api_rows(
        "wallet_transactions?select=id,transaction_type,amount_paise,balance_after_paise,description,reference_type,reference_id,metadata,created_at&customer_id=eq." . urlencode($customerId) . customer_api_date_filters('created_at') . "&order=created_at.desc"
    );
    customer_api_json($validation, "wallet", [
        "account" => [
            "email" => $billingEmail,
            "wallet_balance_paise" => (int)($account['wallet_balance_paise'] ?? 0),
            "wallet_balance_rupees" => (($account['wallet_balance_paise'] ?? 0) / 100),
            "current_plan" => $account['current_plan'] ?? 'free',
            "subscription_status" => $account['subscription_status'] ?? 'free',
            "current_period_start" => $account['current_period_start'] ?? null,
            "current_period_end" => $account['current_period_end'] ?? null
        ],
        "transaction_count" => count($transactions),
        "transactions" => $transactions
    ]);
}

if ($action === "customer_api_profile") {
    $validation = validate_customer_api_request("customer_api_profile");
    if (empty($validation['success'])) {
        customer_api_json($validation, "profile");
    }
    $customerId = (string)$validation['customer_id'];
    $billingEmail = billing_email_for_customer($customerId);
    $profile = safe_rows(supabase(
        "GET",
        "customer_profiles?select=email,first_name,last_name,avatar_url,country_code,mobile_number,address_line1,address_line2,city,state_region,country,postal_code,location_notes,created_at,updated_at&email=eq." . urlencode($billingEmail) . "&limit=1"
    ));
    $signup = safe_rows(supabase(
        "GET",
        "chatbot_signups?select=customer_id,email,website_name,business_type,theme_color,created_at&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    $settings = safe_rows(supabase(
        "GET",
        "chatbot_settings?select=bot_name,welcome_message,theme_color,theme_pattern,position,avatar_url,language,is_active,website_verification_enabled,allowed_domains_enabled,allowed_domains,live_chat_actions_enabled,faq_actions_enabled,faq_category_menu_enabled,webhook_url,verification_status,created_at,updated_at&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    customer_api_json($validation, "profile", [
        "profile" => $profile[0] ?? null,
        "bot" => $signup[0] ?? null,
        "settings" => $settings[0] ?? null
    ]);
}

if ($action === "customer_api_analytics") {
    $validation = validate_customer_api_request("customer_api_analytics");
    if (empty($validation['success'])) {
        customer_api_json($validation, "analytics");
    }
    customer_api_json($validation, "analytics", customer_api_analytics_payload((string)$validation['customer_id']));
}

if ($action === "billing_plans") {
    if (!is_authenticated_user()) {
        echo json_encode(["success" => false, "message" => "Login required"]);
        exit;
    }
    $data = getJSON();
    $customerId = trim((string)($data['customer_id'] ?? $_GET['customer_id'] ?? ''));
    if (!authenticated_customer_access($customerId)) {
        echo json_encode(["success" => false, "message" => "Access denied"]);
        exit;
    }
    $account = billing_account_for_customer($customerId);
    [$keyId] = razorpay_credentials();
    echo json_encode([
        "success" => true,
        "razorpay_key_id" => $keyId,
        "account" => $account,
        "active_plan" => billing_active_plan_from_account($account),
        "plans" => billing_plans()
    ]);
    exit;
}

if ($action === "create_razorpay_customer") {
    if (!is_authenticated_user()) {
        echo json_encode(["success" => false, "message" => "Login required"]);
        exit;
    }

    $data = getJSON();
    $email = authenticated_email();
    $customerId = trim((string)($data['customer_id'] ?? ''));
    if (!authenticated_customer_access($customerId)) {
        echo json_encode(["success" => false, "message" => "Access denied"]);
        exit;
    }
    $account = billing_account_for_customer($customerId);
    $customer = ensure_razorpay_customer_for_account(
        $email,
        $account,
        (string)($data['name'] ?? ''),
        (string)($data['contact'] ?? '')
    );
    if (empty($customer['success'])) {
        echo json_encode($customer);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => !empty($customer['existing']) ? "Razorpay customer is already linked" : "Razorpay customer created and linked",
        "razorpay_customer_id" => $customer['razorpay_customer_id'],
        "contact" => $customer['contact'],
        "saved_payment_method_status" => "missing"
    ]);
    exit;
}

if ($action === "create_auto_recharge_mandate_order") {
    if (!is_authenticated_user()) {
        echo json_encode(["success" => false, "message" => "Login required"]);
        exit;
    }

    $data = getJSON();
    $customerId = trim((string)($data['customer_id'] ?? ''));
    $requestedPlanId = trim((string)($data['plan_id'] ?? ''));
    $email = authenticated_email();
    if (!authenticated_customer_access($customerId)) {
        echo json_encode(["success" => false, "message" => "Access denied"]);
        exit;
    }
    $account = billing_account_for_customer($customerId);
    $planId = $requestedPlanId !== '' ? $requestedPlanId : billing_active_plan_from_account($account);
    if (!in_array($planId, billing_plan_ids(), true) || $planId === 'free') {
        echo json_encode(["success" => false, "message" => "Select Starter, Growth, or Business plan"]);
        exit;
    }
    if ($planId === 'free') {
        echo json_encode(["success" => false, "message" => "Activate a paid plan before setting up auto recharge"]);
        exit;
    }
    $customer = ensure_razorpay_customer_for_account(
        $email,
        $account,
        (string)($data['name'] ?? ''),
        (string)($data['contact'] ?? '')
    );
    if (empty($customer['success'])) {
        echo json_encode($customer);
        exit;
    }
    $account = billing_account_for_customer($customerId);
    $razorpayCustomerId = (string)$customer['razorpay_customer_id'];

    $autoRechargeRule = billing_auto_recharge_rule($planId);
    $autoRecharge = [
        "threshold_paise" => (int)$autoRechargeRule['threshold_paise'],
        "amount_paise" => (int)$autoRechargeRule['amount_paise']
    ];
    $amountPaise = (int)$autoRecharge['amount_paise'];
    if ($amountPaise <= 0) {
        echo json_encode(["success" => false, "message" => "Auto recharge amount is not configured"]);
        exit;
    }

    $receipt = substr("mandate_" . $planId . "_" . time() . "_" . bin2hex(random_bytes(3)), 0, 40);
    $order = razorpay_request("POST", "orders", [
        "amount" => $amountPaise,
        "currency" => "INR",
        "payment_capture" => true,
        "receipt" => $receipt,
        "notes" => [
            "email" => $email,
            "customer_id" => $customerId,
            "plan_id" => $planId,
            "order_type" => "auto_recharge_mandate",
            "initial_plan_purchase" => $requestedPlanId !== '',
            "razorpay_customer_id" => $razorpayCustomerId
        ]
    ]);
    if ($order['status'] < 200 || $order['status'] >= 300 || empty($order['data']['id'])) {
        echo json_encode(["success" => false, "message" => "Mandate authorization order could not be created", "debug" => $order]);
        exit;
    }

    $storedOrder = supabase("POST", "billing_orders", [[
        "email" => $email,
        "customer_id" => $customerId ?: null,
        "plan_id" => $planId,
        "order_type" => "mandate",
        "amount_paise" => $amountPaise,
        "currency" => "INR",
        "status" => "created",
        "razorpay_order_id" => $order['data']['id'],
        "receipt" => $receipt,
        "metadata" => (object)[
            "auto_recharge" => true,
            "initial_plan_purchase" => $requestedPlanId !== '',
            "threshold_paise" => $autoRecharge['threshold_paise'],
            "razorpay_customer_id" => $razorpayCustomerId
        ]
    ]]);
    if ($storedOrder['status'] < 200 || $storedOrder['status'] >= 300) {
        echo json_encode([
            "success" => false,
            "message" => "Mandate order was created in Razorpay but could not be saved. Run the latest Supabase schema migration and try again.",
            "debug" => $storedOrder
        ]);
        exit;
    }

    [$keyId] = razorpay_credentials();
    echo json_encode([
        "success" => true,
        "key_id" => $keyId,
        "order" => $order['data'],
        "razorpay_customer_id" => $razorpayCustomerId,
        "contact" => $customer['contact'] ?? ($account['saved_payment_method_contact'] ?? ''),
        "amount_paise" => $amountPaise,
        "threshold_paise" => $autoRecharge['threshold_paise'],
        "plan" => billing_plan($planId)
    ]);
    exit;
}

if ($action === "verify_auto_recharge_mandate") {
    if (!is_authenticated_user()) {
        echo json_encode(["success" => false, "message" => "Login required"]);
        exit;
    }

    $data = getJSON();
    $orderId = trim((string)($data['razorpay_order_id'] ?? ''));
    $paymentId = trim((string)($data['razorpay_payment_id'] ?? ''));
    $signature = trim((string)($data['razorpay_signature'] ?? ''));
    [, $secret] = razorpay_credentials();
    if (!$orderId || !$paymentId || !$signature || !$secret) {
        echo json_encode(["success" => false, "message" => "Missing mandate verification data"]);
        exit;
    }

    $expected = hash_hmac('sha256', $orderId . "|" . $paymentId, $secret);
    if (!hash_equals($expected, $signature)) {
        supabase("PATCH", "billing_orders?razorpay_order_id=eq." . urlencode($orderId), ["status" => "failed"]);
        echo json_encode(["success" => false, "message" => "Mandate signature verification failed"]);
        exit;
    }

    $rows = safe_rows(supabase(
        "GET",
        "billing_orders?select=*&razorpay_order_id=eq." . urlencode($orderId) . "&email=eq." . urlencode(authenticated_email()) . "&limit=1"
    ));
    $order = $rows[0] ?? [];
    if (empty($order) || ($order['order_type'] ?? '') !== 'mandate' || ($order['status'] ?? '') === 'paid') {
        echo json_encode(["success" => false, "message" => "Mandate order not found or already processed"]);
        exit;
    }

    $payment = razorpay_request("GET", "payments/" . rawurlencode($paymentId) . "?expand[]=token", []);
    if ($payment['status'] < 200 || $payment['status'] >= 300 || empty($payment['data']['id'])) {
        echo json_encode(["success" => false, "message" => "Mandate payment could not be fetched", "debug" => $payment]);
        exit;
    }
    $paymentData = $payment['data'];
    if (($paymentData['order_id'] ?? '') !== $orderId) {
        echo json_encode(["success" => false, "message" => "Mandate payment does not match this order"]);
        exit;
    }

    $tokenId = (string)($paymentData['token_id'] ?? $paymentData['token']['id'] ?? '');
    if ($tokenId === '') {
        echo json_encode([
            "success" => false,
            "message" => "Razorpay did not return a recurring token. Make sure recurring payments are enabled on your Razorpay account and the customer used a supported card/debit-card method.",
            "debug" => $paymentData
        ]);
        exit;
    }

    $email = authenticated_email();
    $customerId = trim((string)($order['customer_id'] ?? ''));
    $account = $customerId !== '' ? billing_account_for_customer($customerId) : billing_account_for_email($email);
    $amountPaise = (int)$order['amount_paise'];
    $planId = (string)$order['plan_id'];
    $autoRechargeRule = billing_auto_recharge_rule($planId);
    $periodStart = gmdate('Y-m-d\TH:i:s\Z');
    $periodEnd = gmdate('Y-m-d\TH:i:s\Z', strtotime('+30 days'));
    $paymentStatus = (string)($paymentData['status'] ?? '');
    $balanceAfter = (int)($account['wallet_balance_paise'] ?? 0);
    $credited = false;
    if ($amountPaise > 0 && ($paymentStatus === 'captured' || !empty($paymentData['captured']))) {
        $balanceAfter += $amountPaise;
        supabase("POST", "wallet_transactions", [[
            "email" => $email,
            "customer_id" => ($order['customer_id'] ?? null) ?: null,
            "transaction_type" => "credit",
            "amount_paise" => $amountPaise,
            "balance_after_paise" => $balanceAfter,
            "description" => "Auto recharge mandate authorization funded wallet: " . billing_plan($planId)['name'],
            "reference_type" => "razorpay_mandate_authorization",
            "reference_id" => $paymentId,
            "metadata" => (object)["plan_id" => $planId, "token_id" => $tokenId]
        ]]);
        $credited = true;
    }

    supabase("PATCH", "billing_accounts?" . billing_account_filter($customerId, $email), [
        "wallet_balance_paise" => $balanceAfter,
        "current_plan" => $planId,
        "subscription_status" => "active",
        "auto_recharge_threshold_paise" => (int)$autoRechargeRule['threshold_paise'],
        "auto_recharge_amount_paise" => (int)$autoRechargeRule['amount_paise'],
        "auto_recharge_enabled" => true,
        "saved_payment_method_status" => "active",
        "saved_payment_method_reference" => $tokenId,
        "saved_payment_method_customer_id" => (string)($paymentData['customer_id'] ?? $account['saved_payment_method_customer_id'] ?? ''),
        "saved_payment_method_contact" => (string)($paymentData['contact'] ?? $account['saved_payment_method_contact'] ?? ''),
        "current_period_start" => $periodStart,
        "current_period_end" => $periodEnd
    ]);

    supabase("PATCH", "billing_orders?razorpay_order_id=eq." . urlencode($orderId), [
        "status" => "paid",
        "razorpay_payment_id" => $paymentId,
        "razorpay_signature" => $signature,
        "paid_at" => gmdate('Y-m-d\TH:i:s\Z'),
        "metadata" => (object)[
            "auto_recharge" => true,
            "token_id" => $tokenId,
            "payment_status" => $paymentStatus,
            "wallet_credited" => $credited
        ]
    ]);

    $invoice = [];
    if ($credited) {
        $invoice = create_customer_invoice(
            $customerId,
            $email,
            $planId,
            $amountPaise,
            $paymentId,
            $orderId,
            !empty(((array)($order['metadata'] ?? []))['initial_plan_purchase']) ? 'subscription' : 'auto_recharge',
            ["source" => "razorpay_mandate_authorization", "token_id" => $tokenId]
        );
        if (!empty($invoice)) {
            send_customer_invoice_email($invoice);
        }
    }

    echo json_encode([
        "success" => true,
        "message" => "Auto recharge mandate authorized",
        "token_id" => $tokenId,
        "wallet_credited" => $credited,
        "invoice" => !empty($invoice) ? ["invoice_number" => $invoice['invoice_number'] ?? null] : null,
        "account" => $customerId !== '' ? billing_account_for_customer($customerId) : billing_account_for_email($email)
    ]);
    exit;
}

if ($action === "cancel_chatbot_subscription") {
    if (!is_authenticated_user()) {
        echo json_encode(["success" => false, "message" => "Login required"]);
        exit;
    }

    $data = getJSON();
    $email = authenticated_email();
    $customerId = trim((string)($data['customer_id'] ?? ''));
    if ($customerId !== '' && !authenticated_customer_access($customerId)) {
        echo json_encode(["success" => false, "message" => "Access denied"]);
        exit;
    }

    $account = billing_account_for_customer($customerId);
    $activePlan = billing_active_plan_from_account($account);
    if ((string)($account['subscription_status'] ?? 'free') === 'cancelled') {
        echo json_encode(["success" => true, "message" => "Auto payment is already stopped. Remaining wallet balance can still be used.", "account" => $account]);
        exit;
    }
    if ($activePlan === 'free' && (($account['subscription_status'] ?? 'free') !== 'active')) {
        echo json_encode(["success" => true, "message" => "Subscription is already stopped", "account" => $account]);
        exit;
    }

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $walletBalance = (int)($account['wallet_balance_paise'] ?? 0);
    $billingUpdate = supabase("PATCH", "billing_accounts?" . billing_account_filter($customerId, $email), [
        "current_plan" => $walletBalance > 0 ? $activePlan : "free",
        "subscription_status" => $walletBalance > 0 ? "cancelled" : "free",
        "auto_recharge_enabled" => false,
        "saved_payment_method_status" => "revoked",
        "saved_payment_method_reference" => null,
        "current_period_end" => $now
    ]);
    if ($billingUpdate['status'] < 200 || $billingUpdate['status'] >= 300) {
        echo json_encode(["success" => false, "message" => "Subscription could not be cancelled", "debug" => $billingUpdate]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => $walletBalance > 0
            ? "Auto payment stopped. Remaining wallet balance can still be used on the current plan until it reaches zero."
            : "Auto payment stopped. Wallet is empty, so the account is now on Free service.",
        "account" => billing_account_for_customer($customerId)
    ]);
    exit;
}

if ($action === "create_razorpay_order") {
    if (!is_authenticated_user()) {
        echo json_encode(["success" => false, "message" => "Login required"]);
        exit;
    }
    echo json_encode([
        "success" => false,
        "message" => "One-time plan payments are disabled. Automatic recurring payment authorization is mandatory for Starter, Growth, and Business plans."
    ]);
    exit;
    $data = getJSON();
    $planId = trim((string)($data['plan_id'] ?? ''));
    $customerId = trim((string)($data['customer_id'] ?? ''));
    if (!in_array($planId, billing_plan_ids(), true) || $planId === 'free') {
        echo json_encode(["success" => false, "message" => "Select a paid plan"]);
        exit;
    }
    $plan = billing_plan($planId);
    $amountPaise = (int)$plan['price_paise'];
    $email = authenticated_email();
    $receipt = substr("sub_" . $planId . "_" . time() . "_" . bin2hex(random_bytes(3)), 0, 40);
    $order = razorpay_request("POST", "orders", [
        "amount" => $amountPaise,
        "currency" => "INR",
        "receipt" => $receipt,
        "notes" => [
            "email" => $email,
            "customer_id" => $customerId,
            "plan_id" => $planId,
            "order_type" => "subscription"
        ]
    ]);
    if ($order['status'] < 200 || $order['status'] >= 300 || empty($order['data']['id'])) {
        echo json_encode(["success" => false, "message" => "Razorpay order could not be created", "debug" => $order]);
        exit;
    }
    supabase("POST", "billing_orders", [[
        "email" => $email,
        "customer_id" => $customerId ?: null,
        "plan_id" => $planId,
        "order_type" => "subscription",
        "amount_paise" => $amountPaise,
        "currency" => "INR",
        "status" => "created",
        "razorpay_order_id" => $order['data']['id'],
        "receipt" => $receipt,
        "metadata" => (object)["plan_name" => $plan['name']]
    ]]);
    [$keyId] = razorpay_credentials();
    echo json_encode([
        "success" => true,
        "key_id" => $keyId,
        "order" => $order['data'],
        "plan" => $plan
    ]);
    exit;
}

if ($action === "verify_razorpay_payment") {
    if (!is_authenticated_user()) {
        echo json_encode(["success" => false, "message" => "Login required"]);
        exit;
    }
    echo json_encode([
        "success" => false,
        "message" => "One-time plan payment verification is disabled. Use automatic recurring payment authorization."
    ]);
    exit;
    $data = getJSON();
    $orderId = trim((string)($data['razorpay_order_id'] ?? ''));
    $paymentId = trim((string)($data['razorpay_payment_id'] ?? ''));
    $signature = trim((string)($data['razorpay_signature'] ?? ''));
    [, $secret] = razorpay_credentials();
    if (!$orderId || !$paymentId || !$signature || !$secret) {
        echo json_encode(["success" => false, "message" => "Missing payment verification data"]);
        exit;
    }
    $expected = hash_hmac('sha256', $orderId . "|" . $paymentId, $secret);
    if (!hash_equals($expected, $signature)) {
        supabase("PATCH", "billing_orders?razorpay_order_id=eq." . urlencode($orderId), ["status" => "failed"]);
        echo json_encode(["success" => false, "message" => "Payment signature verification failed"]);
        exit;
    }
    $rows = safe_rows(supabase(
        "GET",
        "billing_orders?select=*&razorpay_order_id=eq." . urlencode($orderId) . "&email=eq." . urlencode(authenticated_email()) . "&limit=1"
    ));
    $order = $rows[0] ?? [];
    if (empty($order) || ($order['status'] ?? '') === 'paid') {
        echo json_encode(["success" => false, "message" => "Payment order not found or already processed"]);
        exit;
    }
    $planId = (string)$order['plan_id'];
    $amountPaise = (int)$order['amount_paise'];
    $email = authenticated_email();
    $customerId = (string)($order['customer_id'] ?? '');
    wallet_credit_subscription($email, $customerId, $planId, $amountPaise, $paymentId);
    supabase("PATCH", "billing_orders?razorpay_order_id=eq." . urlencode($orderId), [
        "status" => "paid",
        "razorpay_payment_id" => $paymentId,
        "razorpay_signature" => $signature,
        "paid_at" => gmdate('Y-m-d\TH:i:s\Z')
    ]);
    echo json_encode([
        "success" => true,
        "message" => "Payment verified and premium activated",
        "active_plan" => $planId,
        "account" => billing_account_for_email($email)
    ]);
    exit;
}


// ==========================
// SIGNUP
// ==========================
if ($action === "signup") {

    $data = getJSON();

    if (
        empty($data['customer_id']) ||
        empty($data['website_name']) ||
        empty($data['email']) ||
        empty($data['business_type'])
    ) {
        echo json_encode(["error" => "Missing fields"]);
        exit;
    }

    $websiteDomain = valid_website_domain_from_value((string)$data['website_name']);
    if ($websiteDomain === '') {
        echo json_encode([
            "error" => "Invalid website domain",
            "message" => "Enter a valid website domain, for example example.com, example.in, or example.co.in."
        ]);
        exit;
    }

    $res = supabase("POST", "chatbot_signups", [[
        "customer_id" => $data['customer_id'],
        "website_name" => $websiteDomain,
        "email" => $data['email'],
        "business_type" => $data['business_type'],
        "theme_color" => "#007bff"
    ]]);

    echo json_encode([
        "status" => "signup_done",
        "debug" => $res
    ]);
    exit;
}


// ==========================
// UPDATE THEME
// ==========================
if ($action === "update_theme") {

    $data = getJSON();

    if (
        empty($data['customer_id']) ||
        empty($data['theme_color'])
    ) {
        echo json_encode(["error" => "Missing data"]);
        exit;
    }

    $res = supabase(
        "PATCH",
        "chatbot_signups?customer_id=eq." . trim($data['customer_id']),
        [
            "theme_color" => $data['theme_color']
        ]
    );

    $settingsPayload = [
        "customer_id" => trim($data['customer_id']),
        "theme_color" => $data['theme_color']
    ];

    if (!empty($data['avatar_url'])) {
        $settingsPayload["avatar_url"] = $data['avatar_url'];
    }

    $existingSettings = supabase(
        "GET",
        "chatbot_settings?select=id&customer_id=eq." . urlencode(trim($data['customer_id'])) . "&limit=1"
    );

    if (!empty($existingSettings['data'])) {
        supabase(
            "PATCH",
            "chatbot_settings?customer_id=eq." . urlencode(trim($data['customer_id'])),
            $settingsPayload
        );
    } else {
        supabase(
            "POST",
            "chatbot_settings",
            [$settingsPayload]
        );
    }

    echo json_encode([
        "status" => "theme_updated",
        "theme_color" => $data['theme_color'],
        "avatar_url" => $data['avatar_url'] ?? '',
        "debug" => $res
    ]);
    exit;
}


// ==========================
// GET THEME
// ==========================
if ($action === "get_theme") {

    $customer_id = $_GET['customer_id'] ?? '';

    if (!$customer_id) {
        echo json_encode(["error" => "Missing customer_id"]);
        exit;
    }

    $res = supabase(
        "GET",
        "chatbot_signups?select=theme_color&customer_id=eq." . trim($customer_id)
    );

    $color = "#007bff";

    if (!empty($res['data'][0]['theme_color'])) {
        $color = $res['data'][0]['theme_color'];
    }

    echo json_encode(["theme_color" => $color]);
    exit;
}


// ==========================
// ADD FAQ
// ==========================
if ($action === "add_faq") {

    $data = getJSON();
    $customer_id = trim($data['customer_id'] ?? '');

    if (empty($customer_id) || empty($data['faqs'])) {
        echo json_encode(["error" => "Missing FAQ data"]);
        exit;
    }

    $existingFaqs = supabase(
        "GET",
        "faq_questions?select=id&customer_id=eq." . urlencode($customer_id)
    );

    $existingCount = is_array($existingFaqs['data'] ?? null) ? count($existingFaqs['data']) : 0;
    $activePlan = billing_active_plan_from_account(billing_account_for_customer($customer_id));
    $faqLimit = billing_faq_limit($activePlan);

    $rows = [];

    foreach ($data['faqs'] as $faq) {
        if (!empty($faq['question']) && !empty($faq['answer'])) {
            $row = [
                "customer_id" => $customer_id,
                "question" => $faq['question'],
                "answer" => $faq['answer']
            ];
            if (!empty($faq['category'])) {
                $row["category"] = $faq['category'];
            }
            $rows[] = $row;
        }
    }

    if (empty($rows)) {
        echo json_encode(["error" => "No valid FAQs"]);
        exit;
    }

    if ($existingCount + count($rows) > $faqLimit) {
        echo json_encode([
            "success" => false,
            "requires_premium" => true,
            "message" => "Your current plan allows up to " . ($faqLimit === PHP_INT_MAX ? "unlimited" : $faqLimit) . " FAQs. Upgrade to add more."
        ]);
        exit;
    }

    $res = supabase("POST", "faq_questions", $rows);

    echo json_encode([
        "success" => ($res['status'] >= 200 && $res['status'] < 300),
        "status" => "faq_saved",
        "debug" => $res
    ]);
    exit;
}

// ==========================
// BULK ADD FAQ
// ==========================
if ($action === "bulk_add_faq") {

    $data = getJSON();
    $customer_id = trim((string)($data['customer_id'] ?? ''));
    $incomingFaqs = is_array($data['faqs'] ?? null) ? $data['faqs'] : [];

    if ($customer_id === '' || empty($incomingFaqs)) {
        echo json_encode(["success" => false, "message" => "Missing FAQ data"]);
        exit;
    }

    if (!authenticated_customer_access($customer_id)) {
        echo json_encode(["success" => false, "message" => "Access denied"]);
        exit;
    }

    $existingFaqs = supabase(
        "GET",
        "faq_questions?select=id&customer_id=eq." . urlencode($customer_id)
    );
    $existingCount = is_array($existingFaqs['data'] ?? null) ? count($existingFaqs['data']) : 0;
    $activePlan = billing_active_plan_from_account(billing_account_for_customer($customer_id));
    $faqLimit = billing_faq_limit($activePlan);
    $limitLabel = $faqLimit === PHP_INT_MAX ? "Unlimited" : (string)$faqLimit;
    $saved = [];
    $failed = [];
    $acceptedCount = 0;

    foreach ($incomingFaqs as $index => $faq) {
        $sourceRow = (int)($faq['row'] ?? ($index + 2));
        $question = trim((string)($faq['question'] ?? ''));
        $answer = trim((string)($faq['answer'] ?? ''));
        $category = trim((string)($faq['category'] ?? 'General')) ?: 'General';

        if ($question === '' || $answer === '') {
            $failed[] = [
                "row" => $sourceRow,
                "question" => $question,
                "answer" => $answer,
                "category" => $category,
                "reason" => "Question and answer are required"
            ];
            continue;
        }

        if ($faqLimit !== PHP_INT_MAX && ($existingCount + $acceptedCount) >= $faqLimit) {
            $failed[] = [
                "row" => $sourceRow,
                "question" => $question,
                "answer" => $answer,
                "category" => $category,
                "reason" => "Plan FAQ limit reached. Current plan allows " . $limitLabel . " FAQs."
            ];
            continue;
        }

        $res = supabase("POST", "faq_questions", [[
            "customer_id" => $customer_id,
            "question" => $question,
            "answer" => $answer,
            "category" => $category
        ]]);

        if ($res['status'] >= 200 && $res['status'] < 300 && !empty($res['data'][0])) {
            $acceptedCount++;
            $saved[] = [
                "row" => $sourceRow,
                "id" => $res['data'][0]['id'] ?? null,
                "question" => $question,
                "answer" => $answer,
                "category" => $category
            ];
        } else {
            $failed[] = [
                "row" => $sourceRow,
                "question" => $question,
                "answer" => $answer,
                "category" => $category,
                "reason" => "Database save failed"
            ];
        }
    }

    echo json_encode([
        "success" => true,
        "saved_count" => count($saved),
        "failed_count" => count($failed),
        "existing_count" => $existingCount,
        "faq_limit" => $limitLabel,
        "active_plan" => $activePlan,
        "saved" => $saved,
        "failed" => $failed
    ]);
    exit;
}


// ==========================
// UPDATE FAQ
// ==========================
if ($action === "update_faq") {

    $data = getJSON();
    $customer_id = trim($data['customer_id'] ?? '');
    $faq_id = trim((string)($data['id'] ?? ''));
    $question = trim($data['question'] ?? '');
    $answer = trim($data['answer'] ?? '');
    $category = trim($data['category'] ?? 'General');

    if (!$customer_id || !$faq_id || !$question || !$answer) {
        echo json_encode([
            "success" => false,
            "message" => "Question and answer are required"
        ]);
        exit;
    }

    $existing = supabase(
        "GET",
        "faq_questions?select=id&id=eq." . urlencode($faq_id) . "&customer_id=eq." . urlencode($customer_id) . "&limit=1"
    );

    if (empty($existing['data'])) {
        echo json_encode([
            "success" => false,
            "message" => "FAQ not found"
        ]);
        exit;
    }

    $res = supabase(
        "PATCH",
        "faq_questions?id=eq." . urlencode($faq_id) . "&customer_id=eq." . urlencode($customer_id),
        [
            "question" => $question,
            "answer" => $answer,
            "category" => $category ?: "General"
        ]
    );

    echo json_encode([
        "success" => ($res['status'] >= 200 && $res['status'] < 300 && !empty($res['data'])),
        "message" => empty($res['data']) ? "FAQ was not updated in the database" : "FAQ updated",
        "debug" => $res
    ]);
    exit;
}


// ==========================
// DELETE FAQ
// ==========================
if ($action === "delete_faq") {

    $data = getJSON();
    $customer_id = trim($data['customer_id'] ?? '');
    $faq_id = trim((string)($data['id'] ?? ''));

    if (!$customer_id || !$faq_id) {
        echo json_encode([
            "success" => false,
            "message" => "Missing FAQ"
        ]);
        exit;
    }

    $existing = supabase(
        "GET",
        "faq_questions?select=id&id=eq." . urlencode($faq_id) . "&customer_id=eq." . urlencode($customer_id) . "&limit=1"
    );

    if (empty($existing['data'])) {
        echo json_encode([
            "success" => false,
            "message" => "FAQ not found"
        ]);
        exit;
    }

    $res = supabase(
        "DELETE",
        "faq_questions?id=eq." . urlencode($faq_id) . "&customer_id=eq." . urlencode($customer_id)
    );

    $check = supabase(
        "GET",
        "faq_questions?select=id&id=eq." . urlencode($faq_id) . "&customer_id=eq." . urlencode($customer_id) . "&limit=1"
    );

    $deleted = ($res['status'] >= 200 && $res['status'] < 300 && empty($check['data']));

    echo json_encode([
        "success" => $deleted,
        "message" => $deleted ? "FAQ deleted" : "FAQ was not deleted from the database",
        "debug" => $res
    ]);
    exit;
}

// ==========================
// DELETE CHATBOT
// ==========================
if ($action === "delete_chatbot") {

    $data = getJSON();
    $customer_id = trim((string)($data['customer_id'] ?? ''));
    $confirmText = trim((string)($data['confirm_text'] ?? ''));

    if ($customer_id === '' || $confirmText !== 'DELETE') {
        echo json_encode([
            "success" => false,
            "message" => "Type DELETE to confirm chatbot deletion"
        ]);
        exit;
    }

    if (!authenticated_customer_access($customer_id)) {
        echo json_encode([
            "success" => false,
            "message" => "Access denied"
        ]);
        exit;
    }

    $email = authenticated_email();
    $existing = supabase(
        "GET",
        "chatbot_signups?select=customer_id,website_name&customer_id=eq." . urlencode($customer_id) . "&email=eq." . urlencode($email) . "&limit=1"
    );

    if (empty($existing['data'])) {
        echo json_encode([
            "success" => false,
            "message" => "Chatbot not found"
        ]);
        exit;
    }

    $res = supabase(
        "DELETE",
        "chatbot_signups?customer_id=eq." . urlencode($customer_id) . "&email=eq." . urlencode($email)
    );

    $check = supabase(
        "GET",
        "chatbot_signups?select=customer_id&customer_id=eq." . urlencode($customer_id) . "&email=eq." . urlencode($email) . "&limit=1"
    );

    $deleted = ($res['status'] >= 200 && $res['status'] < 300 && empty($check['data']));
    if (!$deleted) {
        $permissionDenied = in_array((int)$res['status'], [401, 403], true);
        echo json_encode([
            "success" => false,
            "message" => $permissionDenied
                ? "Chatbot could not be deleted because Supabase delete permission is missing for chatbot_signups. Run the latest supabase-dashboard-schema.sql."
                : "Chatbot was not deleted from the database",
            "debug" => $res
        ]);
        exit;
    }

    $remaining = supabase(
        "GET",
        "chatbot_signups?select=customer_id&email=eq." . urlencode($email) . "&order=created_at.desc&limit=1"
    );
    $nextCustomerId = trim((string)($remaining['data'][0]['customer_id'] ?? ''));

    echo json_encode([
        "success" => true,
        "message" => "Chatbot deleted",
        "redirect" => $nextCustomerId !== '' ? "dashboard.php?bot=" . rawurlencode($nextCustomerId) : "dashboard.php"
    ]);
    exit;
}


// ==========================
// SAVE LEAD GENERATION SETTINGS
// ==========================
if ($action === "save_lead_generation_settings") {

    $data = getJSON();
    $customer_id = trim($data['customer_id'] ?? '');

    if (!$customer_id) {
        echo json_encode([
            "success" => false,
            "message" => "Missing customer_id"
        ]);
        exit;
    }

    $notification_email = trim($data['notification_email'] ?? '');
    $whatsapp_mobile_number = trim($data['whatsapp_mobile_number'] ?? '');
    $notify_lead_by_email = filter_var($data['notify_lead_by_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $redirect_whatsapp = filter_var($data['redirect_whatsapp'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $collect_email = filter_var($data['collect_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $collect_mobile = filter_var($data['collect_mobile'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $verify_email_otp = filter_var($data['verify_email_otp'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $verify_mobile_otp = filter_var($data['verify_mobile_otp'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($verify_email_otp) {
        $collect_email = false;
    }
    if ($verify_mobile_otp) {
        $collect_mobile = false;
    }
    $billingEmail = is_authenticated_user() ? authenticated_email() : billing_email_for_customer($customer_id);
    $activePlan = billing_active_plan_from_account(billing_account_for_customer($customer_id));

    if ($verify_email_otp && !billing_feature_enabled($activePlan, 'email_otp')) {
        echo json_encode(["success" => false, "requires_premium" => true, "message" => "Email OTP requires a premium plan."]);
        exit;
    }

    if ($verify_mobile_otp && !billing_feature_enabled($activePlan, 'mobile_otp')) {
        echo json_encode(["success" => false, "requires_premium" => true, "message" => "Mobile OTP requires an active paid plan."]);
        exit;
    }

    if ($redirect_whatsapp && !billing_feature_enabled($activePlan, 'whatsapp_redirect')) {
        echo json_encode(["success" => false, "requires_premium" => true, "message" => "WhatsApp Redirect requires an active paid plan."]);
        exit;
    }

    if ($notify_lead_by_email && !filter_var($notification_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            "success" => false,
            "message" => "Enter a valid notification email"
        ]);
        exit;
    }

    if ($redirect_whatsapp && !preg_match('/^\+?[1-9]\d{7,14}$/', $whatsapp_mobile_number)) {
        echo json_encode([
            "success" => false,
            "message" => "Enter a valid WhatsApp mobile number"
        ]);
        exit;
    }

    $existingResponse = supabase(
        "GET",
        "lead_generation_settings?select=*&customer_id=eq." . urlencode($customer_id) . "&limit=1"
    );
    $existingRows = is_array($existingResponse['data'] ?? null) ? $existingResponse['data'] : [];
    $existingSettings = $existingRows[0] ?? [];
    $wasWhatsappEnabled = filter_var($existingSettings['redirect_whatsapp'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $whatsappBillingUpdate = [];
    $walletActivity = null;
    $whatsappStateChanged = ($redirect_whatsapp !== $wasWhatsappEnabled);
    $now = time();
    $whatsappToggleDay = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    $existingToggleDay = trim((string)($existingSettings['whatsapp_redirect_toggle_date'] ?? ''));
    $existingToggleCount = $existingToggleDay === $whatsappToggleDay ? (int)($existingSettings['whatsapp_redirect_toggle_count'] ?? 0) : 0;
    $lockedUntil = trim((string)($existingSettings['whatsapp_redirect_locked_until'] ?? ''));
    $lockedUntilTime = $lockedUntil !== '' ? strtotime($lockedUntil) : 0;

    if ($whatsappStateChanged && $lockedUntilTime && $now < $lockedUntilTime) {
        echo json_encode([
            "success" => false,
            "whatsapp_redirect_locked" => true,
            "whatsapp_redirect_locked_until" => gmdate('Y-m-d\TH:i:s\Z', $lockedUntilTime),
            "message" => "WhatsApp redirection is locked for 24 hours because it has already been changed 3 times."
        ]);
        exit;
    }

    if ($whatsappStateChanged) {
        $newToggleCount = $existingToggleCount + 1;
        $whatsappBillingUpdate["whatsapp_redirect_toggle_date"] = $whatsappToggleDay;
        $whatsappBillingUpdate["whatsapp_redirect_toggle_count"] = $newToggleCount;
        if ($newToggleCount >= 3) {
            $whatsappBillingUpdate["whatsapp_redirect_locked_until"] = gmdate('Y-m-d\TH:i:s\Z', $now + 24 * 3600);
        } elseif ($lockedUntilTime && $now >= $lockedUntilTime) {
            $whatsappBillingUpdate["whatsapp_redirect_locked_until"] = null;
        }
    }

    if ($redirect_whatsapp) {
        $amountPaise = billing_wallet_charge_paise($activePlan, 'whatsapp_redirect_addon');
        $lastChargedAt = trim((string)($existingSettings['whatsapp_redirect_charged_at'] ?? ''));
        $lastRefundedAt = trim((string)($existingSettings['whatsapp_redirect_refunded_at'] ?? ''));
        $periodEnd = trim((string)($existingSettings['whatsapp_redirect_period_end'] ?? ''));
        $periodEndTime = $periodEnd !== '' ? strtotime($periodEnd) : 0;
        $needsCharge = $amountPaise > 0 && ($lastChargedAt === '' || $lastRefundedAt !== '' || !$periodEndTime || time() >= $periodEndTime);
        if ($needsCharge) {
            $billingAccount = billing_account_for_customer($customer_id);
            if ((int)($billingAccount['wallet_balance_paise'] ?? 0) < $amountPaise) {
                if ($wasWhatsappEnabled) {
                    stop_whatsapp_redirect_for_insufficient_balance($customer_id, $billingEmail, $amountPaise);
                    $redirect_whatsapp = false;
                    $whatsappBillingUpdate = array_merge($whatsappBillingUpdate, [
                        "redirect_whatsapp" => false,
                        "whatsapp_redirect_stopped_at" => gmdate('Y-m-d\TH:i:s\Z'),
                        "whatsapp_redirect_stopped_reason" => "insufficient_wallet_balance",
                        "whatsapp_redirect_failed_charge_amount_paise" => $amountPaise
                    ]);
                    $walletActivity = "whatsapp_redirect_failed_zero";
                } else {
                    echo json_encode([
                        "success" => false,
                        "requires_wallet_recharge" => true,
                        "message" => "Wallet balance must be at least " . billing_rupees($amountPaise) . " to turn ON WhatsApp Redirect."
                    ]);
                    exit;
                }
            }
            if ($redirect_whatsapp === false) {
                // Renewal could not be paid, so the service has been turned off.
            } elseif ((int)($billingAccount['wallet_balance_paise'] ?? 0) < $amountPaise) {
                echo json_encode([
                    "success" => false,
                    "requires_wallet_recharge" => true,
                    "message" => "Wallet balance must be at least " . billing_rupees($amountPaise) . " to turn ON WhatsApp Redirect."
                ]);
                exit;
            } else {
            $charge = wallet_debit_without_auto_recharge(
                $billingEmail,
                $customer_id,
                $amountPaise,
                "WhatsApp Redirect monthly add-on",
                "whatsapp_redirect_addon",
                $customer_id,
                ["plan_id" => $activePlan, "billing_period_days" => 30, "refund_window_minutes" => 60]
            );
            if (empty($charge['success'])) {
                echo json_encode([
                    "success" => false,
                    "requires_wallet_recharge" => true,
                    "message" => "Wallet balance must be at least " . billing_rupees($amountPaise) . " to turn ON WhatsApp Redirect."
                ]);
                exit;
            }
            $chargedAt = gmdate('Y-m-d\TH:i:s\Z');
            $whatsappBillingUpdate = array_merge($whatsappBillingUpdate, [
                "whatsapp_redirect_charged_at" => $chargedAt,
                "whatsapp_redirect_refund_deadline" => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
                "whatsapp_redirect_period_end" => gmdate('Y-m-d\TH:i:s\Z', time() + 30 * 86400),
                "whatsapp_redirect_charge_txn_id" => $charge['transaction']['id'] ?? null,
                "whatsapp_redirect_charge_amount_paise" => $amountPaise,
                "whatsapp_redirect_refunded_at" => null,
                "whatsapp_redirect_stopped_at" => null,
                "whatsapp_redirect_stopped_reason" => null,
                "whatsapp_redirect_failed_charge_amount_paise" => null
            ]);
            $walletActivity = "whatsapp_redirect_debit";
            }
        }
    } elseif (!$redirect_whatsapp && $wasWhatsappEnabled) {
        $whatsappBillingUpdate = array_merge($whatsappBillingUpdate, [
            "whatsapp_redirect_stopped_at" => gmdate('Y-m-d\TH:i:s\Z'),
            "whatsapp_redirect_stopped_reason" => "customer_turned_off",
            "whatsapp_redirect_failed_charge_amount_paise" => null
        ]);
        $chargedAt = trim((string)($existingSettings['whatsapp_redirect_charged_at'] ?? ''));
        $refundDeadline = trim((string)($existingSettings['whatsapp_redirect_refund_deadline'] ?? ''));
        $refundedAt = trim((string)($existingSettings['whatsapp_redirect_refunded_at'] ?? ''));
        $deadlineTime = $refundDeadline !== '' ? strtotime($refundDeadline) : 0;
        $amountPaise = (int)($existingSettings['whatsapp_redirect_charge_amount_paise'] ?? 0);
        if ($chargedAt !== '' && $refundedAt === '' && $deadlineTime && time() <= $deadlineTime && $amountPaise > 0) {
            $refund = wallet_credit_usage(
                $billingEmail,
                $customer_id,
                $amountPaise,
                "WhatsApp Redirect add-on refund",
                "whatsapp_redirect_refund",
                $customer_id,
                [
                    "plan_id" => $activePlan,
                    "original_charge_txn_id" => $existingSettings['whatsapp_redirect_charge_txn_id'] ?? null
                ]
            );
            if (!empty($refund['success'])) {
                $whatsappBillingUpdate["whatsapp_redirect_refunded_at"] = gmdate('Y-m-d\TH:i:s\Z');
                $walletActivity = "whatsapp_redirect_refund";
            }
        }
    }

    $payload = array_merge([
        "customer_id" => $customer_id,
        "is_enabled" => filter_var($data['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        "collect_location" => filter_var($data['collect_location'] ?? false, FILTER_VALIDATE_BOOLEAN),
        "collect_email" => $collect_email,
        "collect_mobile" => $collect_mobile,
        "verify_email_otp" => $verify_email_otp,
        "notify_lead_by_email" => $notify_lead_by_email,
        "notification_email" => $notification_email !== '' ? $notification_email : null,
        "redirect_whatsapp" => $redirect_whatsapp,
        "whatsapp_mobile_number" => $whatsapp_mobile_number !== '' ? $whatsapp_mobile_number : null,
        "verify_mobile_otp" => $verify_mobile_otp,
        "service_tier" => $verify_mobile_otp ? "paid" : "free"
    ], $whatsappBillingUpdate);

    if (!empty($existingRows)) {
        $res = supabase(
            "PATCH",
            "lead_generation_settings?customer_id=eq." . urlencode($customer_id),
            $payload
        );
    } else {
        $res = supabase(
            "POST",
            "lead_generation_settings",
            [$payload]
        );
    }

    $saved = ($res['status'] >= 200 && $res['status'] < 300);
    if (!$saved && $redirect_whatsapp && !empty($charge['success'] ?? false)) {
        wallet_credit_usage(
            $billingEmail,
            $customer_id,
            $amountPaise,
            "WhatsApp Redirect add-on refund after settings save failure",
            "whatsapp_redirect_refund",
            $customer_id,
            [
                "plan_id" => $activePlan,
                "original_charge_txn_id" => $charge['transaction']['id'] ?? null,
                "reason" => "settings_save_failed"
            ]
        );
        $walletActivity = "whatsapp_redirect_refund";
    }

    echo json_encode([
        "success" => $saved,
        "message" => $saved ? "Lead generation settings saved" : "Lead generation settings could not be saved",
        "wallet_activity" => $walletActivity,
        "whatsapp_redirect_locked" => !empty($whatsappBillingUpdate["whatsapp_redirect_locked_until"]),
        "whatsapp_redirect_locked_until" => $whatsappBillingUpdate["whatsapp_redirect_locked_until"] ?? ($lockedUntilTime && time() < $lockedUntilTime ? gmdate('Y-m-d\TH:i:s\Z', $lockedUntilTime) : null),
        "debug" => $res
    ]);
    exit;
}


// ==========================
// CHAT
// ==========================
if ($action === "chat") {

    $data = getJSON();

    $customer_id = $data['customer_id'] ?? $_GET['customer_id'] ?? '';
    $message = $data['message'] ?? '';
    $selectedFaqId = (int)($data['faq_id'] ?? $data['question_id'] ?? 0);

    if (!$customer_id || !$message) {
        echo json_encode(["error" => "Missing customer_id or message"]);
        exit;
    }

    $settings = supabase(
        "GET",
        "chatbot_settings?select=*&customer_id=eq." . urlencode(trim($customer_id)) . "&limit=1"
    );

    $settingsRow = $settings['data'][0] ?? [];
    $rawActive = $settingsRow['is_active'] ?? true;
    $isActive = is_bool($rawActive) ? $rawActive : ((string)$rawActive !== 'false');

    if (!$isActive) {
        echo json_encode(["reply" => "Chatbot is currently turned off. Please contact customer support."]);
        exit;
    }

    $signup = supabase(
        "GET",
        "chatbot_signups?select=website_name&customer_id=eq." . urlencode(trim($customer_id)) . "&limit=1"
    );
    $activePlan = billing_active_plan_from_account(billing_account_for_customer(trim($customer_id)));
    $access = chatbot_access_result($settingsRow, $signup['data'][0] ?? [], request_source_url($data), billing_feature_enabled($activePlan, 'allowed_domains'));
    if (!$access['allowed']) {
        echo json_encode(["reply" => $access['message'] ?: "This chatbot is not enabled for this website.", "status" => "blocked"]);
        exit;
    }

    $faqs = supabase(
        "GET",
        "faq_questions?customer_id=eq." . urlencode(trim($customer_id)) . faq_active_query_suffix(trim($customer_id))
    );

    $faqs = $faqs['data'] ?? [];

    $reply = null;
    $matchedQuestionId = null;

    if ($selectedFaqId > 0) {
        $selectedFaqRows = safe_rows(supabase(
            "GET",
            "faq_questions?select=id,question,answer&customer_id=eq." . urlencode(trim($customer_id)) . "&id=eq." . urlencode((string)$selectedFaqId) . "&limit=1"
        ));
        if (!empty($selectedFaqRows[0])) {
            $activeRows = safe_rows(supabase(
                "GET",
                "faq_questions?select=id&customer_id=eq." . urlencode(trim($customer_id)) . faq_active_query_suffix(trim($customer_id))
            ));
            $activeIds = array_flip(array_map(fn($row) => (string)($row['id'] ?? ''), $activeRows));
            if (isset($activeIds[(string)$selectedFaqRows[0]['id']])) {
                $reply = $selectedFaqRows[0]['answer'] ?? null;
                $matchedQuestionId = $selectedFaqRows[0]['id'] ?? null;
            }
        }
    }

    $input = strtolower(trim($message));
    if ($reply === null || $reply === '') {
        foreach ($faqs as $faq) {

            $q = strtolower(trim($faq['question'] ?? ''));

            if (!$q) continue;

            similar_text($input, $q, $percent);

            if (
                $input === $q ||
                strpos($input, $q) !== false ||
                strpos($q, $input) !== false ||
                $percent > 70
            ) {
                $reply = $faq['answer'];
                $matchedQuestionId = $faq['id'] ?? null;
                break;
            }
        }
    }

    $answered = (bool)$matchedQuestionId;
    if (!$reply) {
        $reply = "Sorry, I don't have an answer for that yet. Please contact customer support for help.";
    }

    $conversationRes = supabase(
        "POST",
        "chatbot_conversations",
        [[
            "customer_id" => trim($customer_id),
            "user_question" => $message,
            "bot_response" => $reply,
            "matched_faq_id" => $matchedQuestionId,
            "status" => $answered ? "answered" : "unanswered",
            "is_answered" => $answered,
            "source_url" => request_source_url($data)
        ]]
    );
    if (!$answered) {
        create_handoff_ticket_if_enabled(
            trim($customer_id),
            $settingsRow,
            $activePlan,
            $message,
            $reply,
            request_source_url($data),
            '',
            isset($conversationRes['data'][0]['id']) ? (int)$conversationRes['data'][0]['id'] : null
        );
    }

    echo json_encode(["reply" => $reply]);
    exit;
}

// ==========================
// SAVE FAQ ACTION SUGGESTION
// ==========================
if ($action === "save_faq_action") {

    $data = getJSON();
    $customer_id = trim($data['customer_id'] ?? '');
    $faq_id = trim((string)($data['faq_id'] ?? ''));
    $label = trim((string)($data['label'] ?? ''));
    $action_type = trim((string)($data['action_type'] ?? 'link'));
    $action_value = trim((string)($data['action_value'] ?? ''));
    $display_order = max(0, min(999, (int)($data['display_order'] ?? 0)));
    $is_active = array_key_exists('is_active', $data) ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN) : true;

    if (!$customer_id || !$faq_id || !$label || !$action_value) {
        echo json_encode(["success" => false, "message" => "FAQ, label, and action value are required"]);
        exit;
    }

    $activePlan = billing_active_plan_from_account(billing_account_for_customer($customer_id));
    if (!billing_feature_enabled($activePlan, 'faq_action_suggestions')) {
        echo json_encode(["success" => false, "requires_paid" => true, "message" => "FAQ Action Suggestions requires Starter, Growth, or Business plan"]);
        exit;
    }

    $settingsRows = safe_rows(supabase(
        "GET",
        "chatbot_settings?select=faq_actions_enabled&customer_id=eq." . urlencode($customer_id) . "&limit=1"
    ));
    if (empty($settingsRows[0]) || !filter_var($settingsRows[0]['faq_actions_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        echo json_encode(["success" => false, "message" => "Turn ON FAQ Action Suggestions first"]);
        exit;
    }

    $allowedActionTypes = ['link', 'whatsapp', 'event', 'call', 'email', 'download', 'coupon', 'booking', 'map', 'form', 'track_order', 'category'];
    if (!in_array($action_type, $allowedActionTypes, true)) {
        echo json_encode(["success" => false, "message" => "Invalid action type"]);
        exit;
    }
    if (in_array($action_type, ['link', 'download', 'booking', 'track_order'], true) && !preg_match('{^https://\S+$}i', $action_value)) {
        echo json_encode(["success" => false, "message" => "This action must use a secure https:// URL"]);
        exit;
    }
    if ($action_type === 'whatsapp' && !preg_match('/^\+?[1-9]\d{7,15}$/', $action_value)) {
        echo json_encode(["success" => false, "message" => "WhatsApp number must include country code"]);
        exit;
    }
    if ($action_type === 'call' && !preg_match('/^\+?[1-9]\d{7,15}$/', $action_value)) {
        echo json_encode(["success" => false, "message" => "Call number must include country code"]);
        exit;
    }
    if ($action_type === 'email' && !filter_var($action_value, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["success" => false, "message" => "Enter a valid email address"]);
        exit;
    }
    if ($action_type === 'event' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_.:-]{1,80}$/', $action_value)) {
        echo json_encode(["success" => false, "message" => "Event name can use letters, numbers, dash, dot, underscore, or colon"]);
        exit;
    }
    if (in_array($action_type, ['coupon', 'form', 'category', 'map'], true) && strlen($action_value) > 300) {
        echo json_encode(["success" => false, "message" => "Action value is too long"]);
        exit;
    }

    $faq = supabase(
        "GET",
        "faq_questions?select=id&id=eq." . urlencode($faq_id) . "&customer_id=eq." . urlencode($customer_id) . "&limit=1"
    );

    if (empty($faq['data'])) {
        echo json_encode(["success" => false, "message" => "FAQ not found"]);
        exit;
    }

    $payload = [
        "customer_id" => $customer_id,
        "faq_id" => (int)$faq_id,
        "label" => $label,
        "action_type" => $action_type,
        "action_value" => $action_value,
        "display_order" => $display_order,
        "is_active" => $is_active
    ];

    $res = supabase("POST", "faq_action_suggestions", [$payload]);

    echo json_encode([
        "success" => ($res['status'] >= 200 && $res['status'] < 300),
        "action" => $res['data'][0] ?? null,
        "debug" => $res
    ]);
    exit;
}

// ==========================
// DELETE FAQ ACTION SUGGESTION
// ==========================
if ($action === "delete_faq_action") {

    $data = getJSON();
    $customer_id = trim($data['customer_id'] ?? '');
    $id = trim((string)($data['id'] ?? ''));

    if (!$customer_id || !$id) {
        echo json_encode(["success" => false, "message" => "Missing FAQ action"]);
        exit;
    }

    $res = supabase(
        "DELETE",
        "faq_action_suggestions?id=eq." . urlencode($id) . "&customer_id=eq." . urlencode($customer_id)
    );

    echo json_encode(["success" => ($res['status'] >= 200 && $res['status'] < 300), "debug" => $res]);
    exit;
}

// ==========================
// SAVE SCHEDULED FAQ ACTIONS
// ==========================
if ($action === "save_scheduled_faq_actions") {
    $data = getJSON();
    $customer_id = trim($data['customer_id'] ?? '');
    $actions = is_array($data['actions'] ?? null) ? array_slice($data['actions'], 0, 3) : [];

    if (!$customer_id) {
        echo json_encode(["success" => false, "message" => "Select a bot first"]);
        exit;
    }

    $activePlan = billing_active_plan_from_account(billing_account_for_customer($customer_id));
    if (!billing_feature_enabled($activePlan, 'faq_action_suggestions')) {
        echo json_encode(["success" => false, "requires_paid" => true, "message" => "FAQ Action Suggestions requires Starter, Growth, or Business plan"]);
        exit;
    }

    $settingsRows = safe_rows(supabase(
        "GET",
        "chatbot_settings?select=faq_actions_enabled&customer_id=eq." . urlencode($customer_id) . "&limit=1"
    ));
    if (empty($settingsRows[0]) || !filter_var($settingsRows[0]['faq_actions_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        echo json_encode(["success" => false, "message" => "Turn ON FAQ Action Suggestions first"]);
        exit;
    }

    $allowedActionTypes = ['link', 'whatsapp', 'event', 'call', 'email', 'download', 'coupon', 'booking', 'map', 'form', 'track_order', 'category'];
    $rows = [];
    foreach ($actions as $index => $row) {
        $slotNo = max(1, min(3, (int)($row['slot_no'] ?? ($index + 1))));
        $triggerAfter = max(1, min(50, (int)($row['trigger_after_questions'] ?? 0)));
        $label = trim((string)($row['label'] ?? ''));
        $actionType = trim((string)($row['action_type'] ?? 'link'));
        $actionValue = trim((string)($row['action_value'] ?? ''));
        $isActive = filter_var($row['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$isActive && $label === '' && $actionValue === '') {
            continue;
        }
        if ($isActive && (!$triggerAfter || !$label || !$actionValue)) {
            echo json_encode(["success" => false, "message" => "Enabled scheduled actions need question count, label, and action value"]);
            exit;
        }
        if (!in_array($actionType, $allowedActionTypes, true)) {
            echo json_encode(["success" => false, "message" => "Invalid action type in option " . $slotNo]);
            exit;
        }
        if (in_array($actionType, ['link', 'download', 'booking', 'track_order'], true) && $actionValue !== '' && !preg_match('{^https://\S+$}i', $actionValue)) {
            echo json_encode(["success" => false, "message" => "Option " . $slotNo . " must use a secure https:// URL"]);
            exit;
        }
        if (in_array($actionType, ['whatsapp', 'call'], true) && $actionValue !== '' && !preg_match('/^\+?[1-9]\d{7,15}$/', $actionValue)) {
            echo json_encode(["success" => false, "message" => "Option " . $slotNo . " phone number must include country code"]);
            exit;
        }
        if ($actionType === 'email' && $actionValue !== '' && !filter_var($actionValue, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(["success" => false, "message" => "Option " . $slotNo . " needs a valid email address"]);
            exit;
        }
        if ($actionType === 'event' && $actionValue !== '' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_.:-]{1,80}$/', $actionValue)) {
            echo json_encode(["success" => false, "message" => "Option " . $slotNo . " event name is invalid"]);
            exit;
        }

        if ($label !== '' || $actionValue !== '') {
            $rows[] = [
                "customer_id" => $customer_id,
                "slot_no" => $slotNo,
                "trigger_after_questions" => $triggerAfter,
                "label" => $label !== '' ? $label : "Continue",
                "action_type" => $actionType,
                "action_value" => $actionValue,
                "is_active" => $isActive
            ];
        }
    }

    supabase("DELETE", "faq_scheduled_action_suggestions?customer_id=eq." . urlencode($customer_id));
    $res = !empty($rows)
        ? supabase("POST", "faq_scheduled_action_suggestions", $rows)
        : ["status" => 200, "data" => []];

    echo json_encode([
        "success" => ($res['status'] >= 200 && $res['status'] < 300),
        "message" => ($res['status'] >= 200 && $res['status'] < 300) ? "Scheduled FAQ actions saved" : "Scheduled FAQ actions could not be saved",
        "debug" => $res
    ]);
    exit;
}


// ==========================
// SAVE DASHBOARD SETTINGS
// ==========================
if ($action === "save_dashboard_settings") {

    $data = getJSON();
    $customer_id = trim($data['customer_id'] ?? '');

    if (!$customer_id) {
        echo json_encode([
            "success" => false,
            "message" => "Missing customer_id"
        ]);
        exit;
    }

    $activePlan = billing_active_plan_from_account(billing_account_for_customer($customer_id));
    if (!billing_feature_enabled($activePlan, 'allowed_domains')) {
        if (array_key_exists('allowed_domains_enabled', $data)) {
            $data['allowed_domains_enabled'] = false;
        }
    }
    if (!billing_feature_enabled($activePlan, 'webhook_support')) {
        unset($data['webhook_url'], $data['webhook_secret']);
    } elseif (!empty($data['webhook_url']) && !preg_match('{^https://\S+$}i', (string)$data['webhook_url'])) {
        echo json_encode(["success" => false, "message" => "Webhook URL must start with https://"]);
        exit;
    }
    if (!billing_feature_enabled($activePlan, 'human_handoff')) {
        if (!empty($data['handoff_enabled'])) {
            echo json_encode(["success" => false, "requires_growth" => true, "message" => "You need Growth or Business plan to ON this functionality"]);
            exit;
        }
        unset($data['handoff_enabled'], $data['handoff_email']);
    } elseif (!empty($data['handoff_enabled']) && !filter_var((string)($data['handoff_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["success" => false, "message" => "Enter a valid support email"]);
        exit;
    }
    if (!billing_feature_enabled($activePlan, 'live_chat_actions')) {
        if (!empty($data['live_chat_actions_enabled'])) {
            echo json_encode(["success" => false, "requires_business" => true, "message" => "Live Chat Actions requires Business plan"]);
            exit;
        }
        unset($data['live_chat_actions_enabled']);
    }
    if (!billing_feature_enabled($activePlan, 'faq_action_suggestions')) {
        if (!empty($data['faq_actions_enabled'])) {
            echo json_encode(["success" => false, "requires_paid" => true, "message" => "FAQ Action Suggestions requires Starter, Growth, or Business plan"]);
            exit;
        }
        unset($data['faq_actions_enabled']);
    }

    $allowed = [
        "bot_name",
        "welcome_message",
        "theme_color",
        "theme_pattern",
        "position",
        "avatar_url",
        "language",
        "is_active",
        "api_key",
        "rate_limit",
        "notification_preference",
        "website_verification_enabled",
        "allowed_domains_enabled",
        "allowed_domains",
        "webhook_url",
        "webhook_secret",
        "handoff_enabled",
        "handoff_email",
        "live_chat_actions_enabled",
        "faq_actions_enabled",
        "faq_category_menu_enabled",
        "verification_status"
    ];

    $payload = ["customer_id" => $customer_id];

    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) {
            $payload[$key] = $data[$key];
        }
    }

    if (count($payload) === 1) {
        echo json_encode([
            "success" => false,
            "message" => "No settings provided"
        ]);
        exit;
    }

    $existing = supabase(
        "GET",
        "chatbot_settings?select=id&customer_id=eq." . urlencode($customer_id) . "&limit=1"
    );

    if (!empty($existing['data'])) {
        $res = supabase(
            "PATCH",
            "chatbot_settings?customer_id=eq." . urlencode($customer_id),
            $payload
        );
    } else {
        $res = supabase(
            "POST",
            "chatbot_settings",
            [$payload]
        );
    }

    if (!empty($data['theme_color'])) {
        supabase(
            "PATCH",
            "chatbot_signups?customer_id=eq." . urlencode($customer_id),
            ["theme_color" => $data['theme_color']]
        );
    }

    echo json_encode([
        "success" => ($res['status'] >= 200 && $res['status'] < 300),
        "debug" => $res
    ]);
    exit;
}

// ==========================
// SAVE CUSTOMER PROFILE
// ==========================
if ($action === "save_customer_profile") {

    if (!is_authenticated_user()) {
        echo json_encode([
            "success" => false,
            "message" => "Login required"
        ]);
        exit;
    }

    $data = getJSON();
    $email = authenticated_email();
    $requestedEmail = trim($data['email'] ?? $email);

    if ($requestedEmail !== $email) {
        echo json_encode([
            "success" => false,
            "message" => "Profile email cannot be changed here"
        ]);
        exit;
    }

    $allowed = [
        "first_name",
        "last_name",
        "avatar_url",
        "country_code",
        "mobile_number",
        "address_line1",
        "address_line2",
        "city",
        "state_region",
        "country",
        "postal_code",
        "location_notes"
    ];

    $payload = [
        "email" => $email
    ];

    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) {
            $payload[$key] = trim((string)$data[$key]);
        }
    }

    $existing = supabase(
        "GET",
        "customer_profiles?select=id&email=eq." . urlencode($email) . "&limit=1"
    );

    if (!empty($existing['data'])) {
        $profileRes = supabase(
            "PATCH",
            "customer_profiles?email=eq." . urlencode($email),
            $payload
        );
    } else {
        $profileRes = supabase(
            "POST",
            "customer_profiles",
            [$payload]
        );
    }

    $passwordMessage = null;
    $newPassword = (string)($data['new_password'] ?? '');

    if ($newPassword !== '') {
        if (strlen($newPassword) < 8) {
            echo json_encode([
                "success" => false,
                "message" => "Password must be at least 8 characters"
            ]);
            exit;
        }

        $passwordRes = supabase(
            "PATCH",
            "customers?email=eq." . urlencode($email),
            [
                "password" => password_hash($newPassword, PASSWORD_DEFAULT)
            ]
        );

        $passwordMessage = ($passwordRes['status'] >= 200 && $passwordRes['status'] < 300)
            ? "password_updated"
            : "password_update_failed";
    }

    echo json_encode([
        "success" => ($profileRes['status'] >= 200 && $profileRes['status'] < 300),
        "password" => $passwordMessage,
        "debug" => $profileRes
    ]);
    exit;
}


// ==========================
// TOP FAQS (RANKED)
// ==========================
if ($action === "get_top_faqs") {

    $customer_id = $_GET['customer_id'] ?? '';

    if (!$customer_id) {
        echo json_encode(["error" => "Missing customer_id"]);
        exit;
    }

    $usage = supabase(
        "GET",
        "faq_usage?select=question_id&customer_id=eq." . trim($customer_id)
    );

    $usageRows = $usage['data'] ?? [];
    $counts = [];

    foreach ($usageRows as $row) {
        $qid = $row['question_id'] ?? 0;
        if (!$qid) continue;
        if (!isset($counts[$qid])) $counts[$qid] = 0;
        $counts[$qid]++;
    }

    arsort($counts);
    $topIds = array_slice(array_keys($counts), 0, 5);

    if (empty($topIds)) {
        $res = supabase(
            "GET",
            "faq_questions?select=id,question&customer_id=eq." . urlencode(trim($customer_id)) . faq_active_query_suffix(trim($customer_id)) . "&limit=5"
        );
        echo json_encode(["data" => $res['data'] ?? []]);
        exit;
    }

    $idList = implode(",", $topIds);

    $res = supabase(
        "GET",
        "faq_questions?select=id,question"
        . "&customer_id=eq." . urlencode(trim($customer_id))
        . "&id=in.(" . $idList . ")"
    );

    $questions = $res['data'] ?? [];
    $activeRows = safe_rows(supabase(
        "GET",
        "faq_questions?select=id&customer_id=eq." . urlencode(trim($customer_id)) . faq_active_query_suffix(trim($customer_id))
    ));
    $activeIds = array_flip(array_map(fn($row) => (string)($row['id'] ?? ''), $activeRows));
    $questions = array_values(array_filter($questions, fn($row) => isset($activeIds[(string)($row['id'] ?? '')])));

    usort($questions, function($a, $b) use ($counts) {
        return ($counts[$b['id']] ?? 0) - ($counts[$a['id']] ?? 0);
    });

    echo json_encode(["data" => $questions]);
    exit;
}


// ==========================
// SEARCH FAQS
// ==========================
if ($action === "search_faqs") {

    $customer_id = $_GET['customer_id'] ?? '';
    $q = $_GET['q'] ?? '';

    if (!$customer_id) {
        echo json_encode(["error" => "Missing customer_id"]);
        exit;
    }

    $query =
        "faq_questions?select=id,question"
        . "&customer_id=eq." . urlencode(trim($customer_id));

    if (!empty($q)) {
        $query .= "&question=ilike.*" . urlencode($q) . "*";
    }
    $query .= faq_active_query_suffix(trim($customer_id));

    $res = supabase("GET", $query);

    echo json_encode(["data" => $res['data'] ?? []]);
    exit;
}


// ==========================
// TRACK FAQ USAGE
// ==========================
if ($action === "track_faq_usage") {

    $data = getJSON();

    if (
        empty($data['customer_id']) ||
        empty($data['question_id']) ||
        empty($data['user_id'])
    ) {
        echo json_encode(["error" => "Missing data"]);
        exit;
    }

    $check = supabase(
        "GET",
        "faq_usage?select=id"
        . "&customer_id=eq." . urlencode(trim($data['customer_id']))
        . "&question_id=eq." . intval($data['question_id'])
        . "&user_id=eq." . urlencode(trim($data['user_id']))
        . "&limit=1"
    );

    if (!empty($check['data'])) {
        echo json_encode(["status" => "already_tracked"]);
        exit;
    }

    $res = supabase(
        "POST",
        "faq_usage",
        [[
            "customer_id" => trim($data['customer_id']),
            "question_id" => intval($data['question_id']),
            "user_id" => trim($data['user_id'])
        ]]
    );

    echo json_encode([
        "status" => "tracked",
        "debug" => $res
    ]);
    exit;
}


// ==========================
// CREATE CUSTOMER
// ==========================
if ($action === "create_customer") {
    echo json_encode([
        "customer_id" => generateUUID()
    ]);
    exit;
}


// ==========================
// GET PRELOADED FAQS
// ==========================
if ($action === "get_preloaded_faqs") {

    $category = $_GET['category'] ?? '';

    if (!$category) {
        echo json_encode([
            "success" => false,
            "message" => "Missing category"
        ]);
        exit;
    }

    $res = supabase(
        "GET",
        "pre_loaded_question?select=question,answer"
        . "&category=eq." . urlencode(trim($category))
        . "&order=id.asc"
    );

    echo json_encode([
        "success" => true,
        "faqs" => $res['data'] ?? [],
        "debug" => $res
    ]);
    exit;
}


// ==========================
// CREATE ACCOUNT (FIXED)
// ==========================

if ($action === "create_account") {
	
    $data = getJSON();
    require "email.php";

    $customer_id = trim($data['customer_id'] ?? '');
    $email = trim($data['email'] ?? '');
    $raw_website_name = trim($data['website_name'] ?? '');
    $website_name = $raw_website_name !== '' ? valid_website_domain_from_value($raw_website_name) : '';
    $business_type = trim($data['business_type'] ?? '');

    if (!$customer_id || !$email) {

        echo json_encode([
            "success" => false,
            "message" => "Missing customer_id or email"
        ]);

        exit;
    }

    if ($raw_website_name !== '' && $website_name === '') {
        echo json_encode([
            "success" => false,
            "message" => "Enter a valid website domain, for example example.com, example.in, or example.co.in."
        ]);
        exit;
    }

    $_SESSION['setup_email'] = $email;
    $_SESSION['setup_customer_id'] = $customer_id;
    $_SESSION['setup_website_name'] = $website_name;
    $_SESSION['setup_business_type'] = $business_type;

    // ==========================
    // CHECK EXISTING CUSTOMER
    // ==========================
    $existing = supabase(
        "GET",
        "customers?email=eq."
        . urlencode($email)
        . "&limit=1"
    );

    $isExistingUser = false;
    $password = null;

    // ==========================
    // EXISTING USER
    // ==========================
    if (!empty($existing['data'])) {

        $insertCustomer = [
            "status" => "existing_used"
        ];

        $isExistingUser = true;

    } else {

        // ==========================
        // NEW USER
        // ==========================
        $password =
            bin2hex(random_bytes(4)) . "@AI";

        $insertCustomer = supabase(
            "POST",
            "customers",
            [[
               // "id" => $customer_id,

                "email" => $email,

                "password" => password_hash(
                    $password,
                    PASSWORD_DEFAULT
                )
            ]]
        );

        $isExistingUser = false;
    }

    // ==========================
    // INSERT BOT TYPE
    // ==========================
    $insertbot_type = supabase(
        "POST",
        "customer_bot_type",
        [[
            "customer_id" => $customer_id,
            "bot_type" => "Free"
        ]]
    );

    // ==========================
    // SEND EMAIL
    // ==========================
    $emailSent = sendWelcomeEmail(
        $email,
        $customer_id,
        $website_name,
        $password,
        $isExistingUser
    );

    if (!$emailSent) {
        $response = [
            "success" => false,
            "message" => "Account was created, but email could not be sent. Please contact support or try again.",
            "email_sent" => false,
            "customer_id" => $customer_id,
            "existing_user" => $isExistingUser
        ];

        if (in_array($_SERVER['SERVER_NAME'] ?? '', ['127.0.0.1', 'localhost'], true)) {
            $response["mail_error"] = $GLOBALS['MAIL_LAST_ERROR'] ?? '';
        }

        echo json_encode($response);
        exit;
    }

    echo json_encode([

        "success" => true,

        "customer_insert" => $insertCustomer,

        "bottype_insert" => $insertbot_type,

        "email_sent" => $emailSent,

        "customer_id" => $customer_id,

        "existing_user" => $isExistingUser

    ]);

    exit;
}


// ==========================
// DEFAULT
// ==========================
echo json_encode([
    "error" => "Invalid action"
]);
