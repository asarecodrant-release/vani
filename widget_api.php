<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/widget_core.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/invoice_helpers.php';

$action = $_GET['action'] ?? '';
$MSG91_WIDGET_ID = $_ENV['MSG91_WIDGET_ID'] ?? getenv('MSG91_WIDGET_ID') ?: '';
$MSG91_TOKEN_AUTH = $_ENV['MSG91_TOKEN_AUTH'] ?? getenv('MSG91_TOKEN_AUTH') ?: '';
$MSG91_AUTH_KEY = $_ENV['MSG91_AUTH_KEY'] ?? getenv('MSG91_AUTH_KEY') ?: '';

function widget_customer_id(array $data = []): string {
    return trim((string)($data['customer_id'] ?? $_GET['customer_id'] ?? ''));
}

function widget_get_settings(string $customerId): array {
    $rows = widget_safe_rows(supabase(
        "GET",
        "chatbot_settings?select=*&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    return $rows[0] ?? [];
}

function widget_get_signup(string $customerId): array {
    $rows = widget_safe_rows(supabase(
        "GET",
        "chatbot_signups?select=website_name,theme_color,email&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    return $rows[0] ?? [];
}

function widget_customer_payment_settings(string $customerId): array {
    $rows = widget_safe_rows(supabase(
        "GET",
        "customer_payment_settings?select=*&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    return $rows[0] ?? [];
}

function widget_customer_razorpay_request(array $paymentSettings, string $method, string $endpoint, array $payload = []): array {
    $keyId = trim((string)($paymentSettings['razorpay_key_id'] ?? ''));
    $secret = app_decrypt_secret((string)($paymentSettings['razorpay_key_secret'] ?? ''));
    if ($keyId === '' || $secret === '') {
        return ["status" => 500, "data" => [], "raw" => "Customer Razorpay credentials missing"];
    }
    $headers = "Content-Type: application/json\r\nAuthorization: Basic " . base64_encode($keyId . ":" . $secret) . "\r\n";
    $options = [
        "http" => [
            "method" => $method,
            "header" => $headers,
            "ignore_errors" => true,
            "timeout" => 20
        ]
    ];
    if (!empty($payload)) {
        $options["http"]["content"] = json_encode($payload);
    }
    $raw = file_get_contents("https://api.razorpay.com/v1/" . ltrim($endpoint, "/"), false, stream_context_create($options));
    $statusLine = $http_response_header[0] ?? "HTTP/1.1 500";
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $status = (int)($matches[1] ?? 500);
    $data = json_decode((string)$raw, true);
    return ["status" => $status, "data" => is_array($data) ? $data : [], "raw" => $raw];
}

function widget_faq_action_suggestions(string $customerId, $faqId, array $settings, string $activePlan): array {
    $faqId = (int)$faqId;
    if ($customerId === '' || $faqId <= 0) {
        return [];
    }
    if (!widget_bool($settings['faq_actions_enabled'] ?? false) || !billing_feature_enabled($activePlan, 'faq_action_suggestions')) {
        return [];
    }
    $rows = widget_safe_rows(supabase(
        "GET",
        "faq_action_suggestions?select=id,faq_id,label,action_type,action_value,display_order&customer_id=eq." . urlencode($customerId) . "&faq_id=eq." . urlencode((string)$faqId) . "&is_active=eq.true&order=display_order.asc,created_at.asc&limit=5"
    ));
    return array_values(array_map(fn($row) => [
        "id" => $row["id"] ?? null,
        "faq_id" => $row["faq_id"] ?? $faqId,
        "label" => (string)($row["label"] ?? ""),
        "action_type" => (string)($row["action_type"] ?? "link"),
        "action_value" => (string)($row["action_value"] ?? "")
    ], $rows));
}

function widget_scheduled_faq_actions(string $customerId, array $settings, string $activePlan): array {
    if (!widget_bool($settings['faq_actions_enabled'] ?? false) || !billing_feature_enabled($activePlan, 'faq_action_suggestions')) {
        return [];
    }
    $rows = widget_safe_rows(supabase(
        "GET",
        "faq_scheduled_action_suggestions?select=id,slot_no,trigger_after_questions,label,action_type,action_value&customer_id=eq." . urlencode($customerId) . "&is_active=eq.true&order=slot_no.asc&limit=3"
    ));
    return array_values(array_map(fn($row) => [
        "id" => "scheduled-" . (string)($row["id"] ?? $row["slot_no"] ?? ""),
        "slot_no" => (int)($row["slot_no"] ?? 0),
        "trigger_after_questions" => max(1, (int)($row["trigger_after_questions"] ?? 1)),
        "label" => (string)($row["label"] ?? ""),
        "action_type" => (string)($row["action_type"] ?? "link"),
        "action_value" => (string)($row["action_value"] ?? ""),
        "scheduled" => true
    ], $rows));
}

function widget_default_faq_settings_map($raw): array {
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    return is_array($raw) ? $raw : [];
}

function widget_default_faq_contact_parts(string $customerId, array $settings, array $signup): array {
    $email = trim((string)($settings['handoff_email'] ?? ''));
    if ($email === '') {
        $email = trim((string)($signup['email'] ?? ''));
    }
    $phone = '';
    $signupEmail = strtolower(trim((string)($signup['email'] ?? '')));
    if ($signupEmail !== '') {
        $profileRows = widget_safe_rows(supabase(
            "GET",
            "customer_profiles?select=country_code,mobile_number&email=eq." . urlencode($signupEmail) . "&limit=1"
        ));
        $profile = $profileRows[0] ?? [];
        $mobile = preg_replace('/\D+/', '', (string)($profile['mobile_number'] ?? ''));
        $code = preg_replace('/[^\d+]/', '', (string)($profile['country_code'] ?? ''));
        if ($mobile !== '') {
            $phone = $code . $mobile;
            if ($phone !== '' && $phone[0] !== '+') {
                $phone = '+' . ltrim($phone, '+0');
            }
        }
    }
    return [
        "email" => $email,
        "phone" => $phone,
        "contact" => trim(($phone !== '' ? "phone " . $phone : '') . ($phone !== '' && $email !== '' ? " or " : '') . ($email !== '' ? "email " . $email : ''))
    ];
}

function widget_default_faq_definitions(array $contact): array {
    $contactText = $contact['contact'] !== '' ? $contact['contact'] : 'the support team';
    return [
        "fallback_contact" => [
            "question" => "What happens if the chatbot cannot answer my question?",
            "answer" => "I could not find the right answer for this question. Please contact " . $contactText . " and our team will help you."
        ],
        "contact_support" => [
            "question" => "How can I contact support?",
            "answer" => "You can contact our support team through " . $contactText . "."
        ],
        "business_hours" => [
            "question" => "What are your business hours?",
            "answer" => "Our team will confirm the current business hours for you. Please contact " . $contactText . " for the latest availability."
        ],
        "location_service_area" => [
            "question" => "Where are you located or which areas do you serve?",
            "answer" => "Please contact " . $contactText . " and our team will share the correct location or service area details."
        ],
        "human_agent" => [
            "question" => "Can I talk to a human?",
            "answer" => "Yes. Please contact " . $contactText . " and a team member will assist you."
        ]
    ];
}

function widget_default_faq_with_saved_values(array $definition, $saved): array {
    if (!is_array($saved)) {
        $saved = ["enabled" => $saved];
    }
    $question = trim((string)($saved['question'] ?? ''));
    $answer = trim((string)($saved['answer'] ?? ''));
    return [
        "enabled" => array_key_exists('enabled', $saved) ? filter_var($saved['enabled'], FILTER_VALIDATE_BOOLEAN) : true,
        "question" => $question !== '' ? $question : (string)$definition['question'],
        "answer" => $answer !== '' ? $answer : (string)$definition['answer']
    ];
}

function widget_default_faq_items(string $customerId, array $settings, array $signup): array {
    $states = widget_default_faq_settings_map($settings['default_faq_settings'] ?? []);
    $defs = widget_default_faq_definitions(widget_default_faq_contact_parts($customerId, $settings, $signup));
    $items = [];
    foreach ($defs as $key => $definition) {
        $item = widget_default_faq_with_saved_values($definition, $states[$key] ?? []);
        if (!$item['enabled']) {
            continue;
        }
        $items[$key] = $item;
    }
    return $items;
}

function widget_webhook_deliver(string $customerId, string $event, array $data = [], array $settings = [], string $activePlan = ''): bool {
    if ($customerId === '') {
        return false;
    }
    if (empty($settings)) {
        $settings = widget_get_settings($customerId);
    }
    if ($activePlan === '') {
        $activePlan = widget_billing_plan_for_customer($customerId);
    }
    if (!billing_feature_enabled($activePlan, 'webhook_support')) {
        return false;
    }
    $url = trim((string)($settings['webhook_url'] ?? ''));
    if ($url === '' || !preg_match('{^https://\S+$}i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
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
        return false;
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
    return $response !== false && $status >= 200 && $status < 300;
}

function widget_billing_plan_for_customer(string $customerId): string {
    $account = widget_billing_account_for_customer($customerId);
    $status = (string)($account['subscription_status'] ?? 'free');
    $periodEnd = (string)($account['current_period_end'] ?? '');
    $walletBalance = (int)($account['wallet_balance_paise'] ?? 0);
    if (($status === 'cancelled' && $walletBalance <= 0) || ($status === 'active' && $periodEnd !== '' && strtotime($periodEnd) < time())) {
        widget_downgrade_account_to_free($customerId, $status === 'cancelled' ? 'wallet_empty' : 'plan_expired');
        $account["current_plan"] = "free";
        $account["subscription_status"] = "free";
    }
    return billing_active_plan_from_account($account);
}

function widget_faq_active_query_suffix(string $customerId, string $order = "id.asc"): string {
    $account = widget_billing_account_for_customer($customerId);
    if ((string)($account['subscription_status'] ?? '') === 'cancelled' && (int)($account['wallet_balance_paise'] ?? 0) <= 0 && $customerId !== '') {
        widget_downgrade_account_to_free($customerId, 'wallet_empty');
        $account["current_plan"] = "free";
        $account["subscription_status"] = "free";
    }
    $limit = billing_faq_limit(billing_active_plan_from_account($account));
    $suffix = "&order=" . rawurlencode($order);
    if ($limit !== PHP_INT_MAX) {
        $suffix .= "&limit=" . max(0, $limit);
    }
    return $suffix;
}

function widget_billing_email_for_customer(string $customerId): string {
    $signup = widget_get_signup($customerId);
    return trim((string)($signup['email'] ?? ''));
}

function widget_billing_legacy_account_has_value(array $account): bool {
    return (string)($account['current_plan'] ?? 'free') !== 'free'
        || (string)($account['subscription_status'] ?? 'free') !== 'free'
        || (int)($account['wallet_balance_paise'] ?? 0) > 0
        || trim((string)($account['saved_payment_method_reference'] ?? '')) !== ''
        || trim((string)($account['saved_payment_method_customer_id'] ?? '')) !== '';
}

function widget_legacy_owner_customer_id(string $email): string {
    if ($email === '') {
        return '';
    }
    $orders = widget_safe_rows(supabase(
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

function widget_customer_has_paid_order(string $email, string $customerId): bool {
    if ($email === '' || $customerId === '') {
        return false;
    }
    return !empty(widget_safe_rows(supabase(
        "GET",
        "billing_orders?select=id&email=eq." . urlencode($email) . "&customer_id=eq." . urlencode($customerId) . "&status=eq.paid&limit=1"
    )));
}

function widget_email_has_assigned_paid_account(string $email, string $exceptCustomerId = ''): bool {
    if ($email === '') {
        return false;
    }
    $rows = widget_safe_rows(supabase(
        "GET",
        "billing_accounts?select=*&email=eq." . urlencode($email) . "&customer_id=not.is.null&limit=20"
    ));
    foreach ($rows as $row) {
        $rowCustomerId = trim((string)($row['customer_id'] ?? ''));
        if ($rowCustomerId !== $exceptCustomerId && widget_billing_legacy_account_has_value($row)) {
            return true;
        }
    }
    return false;
}

function widget_email_bot_count(string $email): int {
    if ($email === '') {
        return 0;
    }
    return count(widget_safe_rows(supabase(
        "GET",
        "chatbot_signups?select=customer_id&email=eq." . urlencode($email) . "&limit=2"
    )));
}

function widget_adopt_legacy_billing_account(string $customerId, string $email, array $customerAccount = []): array {
    if ($customerId === '' || $email === '') {
        return $customerAccount;
    }
    $legacyOwnerCustomerId = widget_legacy_owner_customer_id($email);
    if ($legacyOwnerCustomerId !== '' && $legacyOwnerCustomerId !== $customerId) {
        return $customerAccount;
    }
    if ($legacyOwnerCustomerId === '' && widget_email_has_assigned_paid_account($email, $customerId)) {
        return $customerAccount;
    }
    if ($legacyOwnerCustomerId === '' && widget_email_bot_count($email) > 1) {
        return $customerAccount;
    }
    $legacyRows = widget_safe_rows(supabase(
        "GET",
        "billing_accounts?select=*&email=eq." . urlencode($email) . "&customer_id=is.null&order=created_at.desc&limit=5"
    ));
    $legacy = [];
    foreach ($legacyRows as $row) {
        if (widget_billing_legacy_account_has_value($row)) {
            $legacy = $row;
            break;
        }
    }
    if (empty($legacy)) {
        return $customerAccount;
    }
    if (empty($customerAccount)) {
        $claim = supabase("PATCH", "billing_accounts?id=eq." . urlencode((string)$legacy['id']), ["customer_id" => $customerId]);
        if ($claim['status'] >= 200 && $claim['status'] < 300 && !empty($claim['data'][0])) {
            $customerAccount = $claim['data'][0];
        }
    } elseif (!widget_billing_legacy_account_has_value($customerAccount)) {
        $payload = ["email" => $email];
        foreach ([
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
        ] as $field) {
            if (array_key_exists($field, $legacy)) {
                $payload[$field] = $legacy[$field];
            }
        }
        $copy = supabase("PATCH", "billing_accounts?customer_id=eq." . urlencode($customerId), $payload);
        if ($copy['status'] >= 200 && $copy['status'] < 300 && !empty($copy['data'][0])) {
            $customerAccount = $copy['data'][0];
        }
    }
    supabase("PATCH", "wallet_transactions?email=eq." . urlencode($email) . "&customer_id=is.null", ["customer_id" => $customerId]);
    supabase("PATCH", "billing_orders?email=eq." . urlencode($email) . "&customer_id=is.null", ["customer_id" => $customerId]);
    return $customerAccount;
}

function widget_free_billing_snapshot(string $customerId, string $email): array {
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

function widget_repair_misassigned_billing_account(string $customerId, string $email, array $account): array {
    if ($customerId === '' || $email === '' || !widget_billing_legacy_account_has_value($account)) {
        return $account;
    }
    $ownerCustomerId = widget_legacy_owner_customer_id($email);
    if ($ownerCustomerId === '' || $ownerCustomerId === $customerId) {
        return $account;
    }
    if (widget_customer_has_paid_order($email, $customerId)) {
        return $account;
    }
    $payload = ["customer_id" => $ownerCustomerId, "email" => $email];
    foreach ([
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
    ] as $field) {
        if (array_key_exists($field, $account)) {
            $payload[$field] = $account[$field];
        }
    }
    $ownerRows = widget_safe_rows(supabase("GET", "billing_accounts?select=*&customer_id=eq." . urlencode($ownerCustomerId) . "&limit=1"));
    if (empty($ownerRows[0])) {
        supabase("POST", "billing_accounts", [$payload]);
    } elseif (!widget_billing_legacy_account_has_value($ownerRows[0])) {
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
    widget_downgrade_account_to_free($customerId, 'subscription_owner_mismatch');
    return widget_free_billing_snapshot($customerId, $email);
}

function widget_billing_account_for_customer(string $customerId): array {
    $customerId = trim($customerId);
    if ($customerId === '') {
        return [];
    }
    $rows = widget_safe_rows(supabase(
        "GET",
        "billing_accounts?select=*&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    if (!empty($rows[0])) {
        $email = trim((string)($rows[0]['email'] ?? ''));
        if (!widget_billing_legacy_account_has_value($rows[0]) && $email !== '') {
            $rows[0] = widget_adopt_legacy_billing_account($customerId, $email, $rows[0]);
        }
        $rows[0] = widget_repair_misassigned_billing_account($customerId, $email, $rows[0]);
        return $rows[0];
    }
    $email = widget_billing_email_for_customer($customerId);
    if ($email === '') {
        return [];
    }
    $adopted = widget_adopt_legacy_billing_account($customerId, $email);
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

function widget_billing_account_filter(string $customerId, string $email = ''): string {
    return $customerId !== '' ? "customer_id=eq." . urlencode($customerId) : "email=eq." . urlencode($email);
}

function widget_customer_ids_for_billing_email(string $email): array {
    if ($email === '') {
        return [];
    }
    $rows = widget_safe_rows(supabase(
        "GET",
        "chatbot_signups?select=customer_id&email=eq." . urlencode($email)
    ));
    return array_values(array_filter(array_map(fn($row) => trim((string)($row['customer_id'] ?? '')), $rows)));
}

function widget_disable_paid_service_toggles_for_email(string $email, string $reason = 'free_plan'): void {
    foreach (widget_customer_ids_for_billing_email($email) as $customerId) {
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
            "live_chat_actions_enabled" => false,
            "faq_actions_enabled" => false,
            "faq_category_menu_enabled" => false,
            "webhook_url" => null,
            "webhook_secret" => null
        ]);
    }
}

function widget_downgrade_account_to_free(string $customerId, string $reason = 'wallet_empty'): void {
    if ($customerId === '') {
        return;
    }
    supabase("PATCH", "billing_accounts?customer_id=eq." . urlencode($customerId), [
        "current_plan" => "free",
        "subscription_status" => "free",
        "auto_recharge_enabled" => false,
        "saved_payment_method_status" => "failed",
        "saved_payment_method_reference" => null
    ]);
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
        "live_chat_actions_enabled" => false,
        "faq_actions_enabled" => false,
        "faq_category_menu_enabled" => false,
        "webhook_url" => null,
        "webhook_secret" => null
    ]);
}

function widget_mark_auto_payment_failed_keep_wallet_access(string $email, array $account, string $reason = 'auto_payment_failed'): void {
    $customerId = trim((string)($account['customer_id'] ?? ''));
    if ($email === '' && $customerId === '') {
        return;
    }
    if ((int)($account['wallet_balance_paise'] ?? 0) <= 0) {
        widget_downgrade_account_to_free($customerId, $reason);
        return;
    }
    supabase("PATCH", "billing_accounts?" . widget_billing_account_filter($customerId, $email), [
        "subscription_status" => "cancelled",
        "auto_recharge_enabled" => false,
        "saved_payment_method_status" => "failed",
        "saved_payment_method_reference" => null
    ]);
}

function widget_razorpay_request(string $method, string $endpoint, array $payload = []): array {
    $keyId = $_ENV['RAZORPAY_KEY_ID'] ?? getenv('RAZORPAY_KEY_ID') ?: '';
    $keySecret = $_ENV['RAZORPAY_KEY_SECRET'] ?? getenv('RAZORPAY_KEY_SECRET') ?: '';
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

function widget_auto_recharge_wallet(string $email, string $customerId, array $account, string $planId): array {
    $rule = billing_auto_recharge_rule($planId);
    $amountPaise = (int)($account['auto_recharge_amount_paise'] ?? 0) ?: (int)$rule['amount_paise'];
    $thresholdPaise = (int)($account['auto_recharge_threshold_paise'] ?? 0) ?: (int)$rule['threshold_paise'];
    $enabled = filter_var($account['auto_recharge_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $token = trim((string)($account['saved_payment_method_reference'] ?? ''));
    $razorpayCustomerId = trim((string)($account['saved_payment_method_customer_id'] ?? ''));
    $contact = trim((string)($account['saved_payment_method_contact'] ?? ''));
    $methodStatus = (string)($account['saved_payment_method_status'] ?? 'missing');

    if (!$enabled || $amountPaise <= 0) {
        return ["success" => false, "message" => "Auto recharge is not enabled"];
    }
    if ($methodStatus !== 'active' || $token === '' || $razorpayCustomerId === '') {
        return ["success" => false, "requires_payment_method" => true, "message" => "No active Razorpay recurring payment method is available"];
    }

    $receipt = substr("auto_" . $planId . "_" . time() . "_" . bin2hex(random_bytes(3)), 0, 40);
    $order = widget_razorpay_request("POST", "orders", [
        "amount" => $amountPaise,
        "currency" => "INR",
        "payment_capture" => true,
        "receipt" => $receipt,
        "notes" => ["email" => $email, "customer_id" => $customerId, "plan_id" => $planId, "order_type" => "wallet_auto_recharge"]
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

    $payment = widget_razorpay_request("POST", "payments/create/recurring", [
        "email" => $email,
        "contact" => $contact,
        "amount" => $amountPaise,
        "currency" => "INR",
        "order_id" => $order['data']['id'],
        "customer_id" => $razorpayCustomerId,
        "token" => $token,
        "recurring" => true,
        "description" => "Vani wallet auto recharge",
        "notes" => ["email" => $email, "customer_id" => $customerId, "plan_id" => $planId]
    ]);
    $paymentId = (string)($payment['data']['razorpay_payment_id'] ?? $payment['data']['id'] ?? '');
    $paymentStatus = (string)($payment['data']['status'] ?? '');
    if ($payment['status'] < 200 || $payment['status'] >= 300 || $paymentId === '') {
        supabase("PATCH", "billing_orders?razorpay_order_id=eq." . urlencode((string)$order['data']['id']), ["status" => "failed"]);
        widget_mark_auto_payment_failed_keep_wallet_access($email, $account, 'auto_payment_failed');
        return ["success" => false, "message" => "Auto recharge payment failed", "debug" => $payment];
    }
    if (!in_array($paymentStatus, ['captured', 'authorized'], true)) {
        supabase("PATCH", "billing_orders?razorpay_order_id=eq." . urlencode((string)$order['data']['id']), [
            "razorpay_payment_id" => $paymentId,
            "metadata" => (object)["auto_recharge" => true, "payment_status" => $paymentStatus]
        ]);
        widget_mark_auto_payment_failed_keep_wallet_access($email, $account, 'auto_payment_pending_or_failed');
        return ["success" => false, "pending" => true, "message" => "Auto recharge payment is pending", "payment_status" => $paymentStatus];
    }

    $rows = widget_safe_rows(supabase("GET", "billing_accounts?select=*&customer_id=eq." . urlencode($customerId) . "&limit=1"));
    $newBalance = (int)(($rows[0] ?? [])['wallet_balance_paise'] ?? 0) + $amountPaise;
    supabase("PATCH", "billing_accounts?customer_id=eq." . urlencode($customerId), [
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
        "metadata" => (object)["plan_id" => $planId, "threshold_paise" => $thresholdPaise]
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
        ["source" => "widget_wallet_auto_recharge", "threshold_paise" => $thresholdPaise]
    );
    if (!empty($invoice)) {
        send_customer_invoice_email($invoice);
    }

    return ["success" => true, "amount_paise" => $amountPaise, "balance_after_paise" => $newBalance, "payment_id" => $paymentId];
}

function widget_debit_wallet(string $email, string $customerId, int $amountPaise, string $description, string $referenceType, string $referenceId, array $metadata = []): array {
    if ($email === '' || $amountPaise <= 0) {
        return ["success" => true, "charged" => false, "message" => "No charge required"];
    }
    $account = widget_billing_account_for_customer($customerId);
    $balance = (int)($account['wallet_balance_paise'] ?? 0);
    if ($balance < $amountPaise) {
        $planId = billing_active_plan_from_account($account);
        $rule = billing_auto_recharge_rule($planId);
        $autoRecharge = [
            "enabled" => filter_var($account['auto_recharge_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            "threshold_paise" => (int)($account['auto_recharge_threshold_paise'] ?? 0) ?: (int)$rule['threshold_paise'],
            "amount_paise" => (int)($account['auto_recharge_amount_paise'] ?? 0) ?: (int)$rule['amount_paise'],
            "payment_method_status" => (string)($account['saved_payment_method_status'] ?? 'missing')
        ];
        supabase("PATCH", "billing_accounts?customer_id=eq." . urlencode($customerId), [
            "last_auto_recharge_attempt_at" => gmdate('Y-m-d\TH:i:s\Z')
        ]);
        $recharge = widget_auto_recharge_wallet($email, $customerId, $account, $planId);
        if (!empty($recharge['success'])) {
            $account = widget_billing_account_for_customer($customerId);
            $balance = (int)($account['wallet_balance_paise'] ?? 0);
        } else {
            widget_mark_auto_payment_failed_keep_wallet_access($email, $account, 'auto_payment_failed');
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
    $newBalance = $balance - $amountPaise;
    supabase("PATCH", "billing_accounts?customer_id=eq." . urlencode($customerId), [
        "wallet_balance_paise" => $newBalance
    ]);
    supabase("POST", "wallet_transactions", [[
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
    if ($newBalance <= 0 && (string)($account['subscription_status'] ?? '') === 'cancelled') {
        widget_downgrade_account_to_free($customerId, 'wallet_empty');
    }
    return ["success" => true, "charged" => true, "balance_after_paise" => $newBalance];
}

function widget_debit_wallet_without_auto_recharge(string $email, string $customerId, int $amountPaise, string $description, string $referenceType, string $referenceId, array $metadata = []): array {
    if ($email === '' || $amountPaise <= 0) {
        return ["success" => true, "charged" => false, "message" => "No charge required"];
    }
    $account = widget_billing_account_for_customer($customerId);
    $balance = (int)($account['wallet_balance_paise'] ?? 0);
    if ($balance < $amountPaise) {
        return ["success" => false, "charged" => false, "balance_paise" => $balance, "required_paise" => $amountPaise, "message" => "Insufficient wallet balance"];
    }
    $newBalance = $balance - $amountPaise;
    supabase("PATCH", "billing_accounts?customer_id=eq." . urlencode($customerId), [
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
        supabase("PATCH", "billing_accounts?customer_id=eq." . urlencode($customerId), [
            "wallet_balance_paise" => $balance
        ]);
        return ["success" => false, "charged" => false, "message" => "Wallet transaction could not be recorded", "debug" => $txn];
    }
    if ($newBalance <= 0 && (string)($account['subscription_status'] ?? '') === 'cancelled') {
        widget_downgrade_account_to_free($customerId, 'wallet_empty');
    }
    return ["success" => true, "charged" => true, "balance_after_paise" => $newBalance, "transaction" => $txn['data'][0] ?? null];
}

function widget_record_zero_debit(string $email, string $customerId, string $description, string $referenceType, string $referenceId, array $metadata = []): void {
    if ($email === '') {
        return;
    }
    $rows = widget_safe_rows(supabase(
        "GET",
        "billing_accounts?select=wallet_balance_paise&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    supabase("POST", "wallet_transactions", [[
        "email" => $email,
        "customer_id" => $customerId ?: null,
        "transaction_type" => "debit",
        "amount_paise" => 0,
        "balance_after_paise" => (int)(($rows[0] ?? [])['wallet_balance_paise'] ?? 0),
        "description" => $description,
        "reference_type" => $referenceType,
        "reference_id" => $referenceId,
        "metadata" => (object)$metadata
    ]]);
}

function widget_send_whatsapp_redirect_stopped_email(string $toEmail, string $websiteName = ''): void {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    require_once __DIR__ . '/email.php';
    $siteText = $websiteName !== '' ? " of " . htmlspecialchars($websiteName, ENT_QUOTES, 'UTF-8') : "";
    $html = "<p>Your WhatsApp redirection service from chatbot" . $siteText . " is stopped due to insufficient wallet balance.</p>"
        . "<p>Please recharge your wallet to turn WhatsApp redirection ON again.</p>";
    sendBrevoEmail($toEmail, "WhatsApp redirection stopped due to insufficient wallet balance", $html);
}

function widget_renew_whatsapp_redirect_if_due(string $customerId, array $leadSettings, string $billingEmail, string $activePlan, array $signup): array {
    if (!widget_bool($leadSettings['redirect_whatsapp'] ?? false) || !billing_feature_enabled($activePlan, 'whatsapp_redirect')) {
        return $leadSettings;
    }
    $amountPaise = billing_wallet_charge_paise($activePlan, 'whatsapp_redirect_addon');
    $periodEnd = trim((string)($leadSettings['whatsapp_redirect_period_end'] ?? ''));
    $periodEndTime = $periodEnd !== '' ? strtotime($periodEnd) : 0;
    if ($amountPaise <= 0 || ($periodEndTime && time() < $periodEndTime)) {
        return $leadSettings;
    }
    $charge = widget_debit_wallet_without_auto_recharge(
        $billingEmail,
        $customerId,
        $amountPaise,
        "WhatsApp Redirect 30-day renewal",
        "whatsapp_redirect_addon",
        $customerId,
        ["plan_id" => $activePlan, "billing_period_days" => 30, "renewal" => true]
    );
    if (!empty($charge['success'])) {
        $chargedAt = gmdate('Y-m-d\TH:i:s\Z');
        $updates = [
            "whatsapp_redirect_charged_at" => $chargedAt,
            "whatsapp_redirect_refund_deadline" => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            "whatsapp_redirect_period_end" => gmdate('Y-m-d\TH:i:s\Z', time() + 30 * 86400),
            "whatsapp_redirect_charge_txn_id" => $charge['transaction']['id'] ?? null,
            "whatsapp_redirect_charge_amount_paise" => $amountPaise,
            "whatsapp_redirect_refunded_at" => null,
            "whatsapp_redirect_stopped_at" => null,
            "whatsapp_redirect_stopped_reason" => null,
            "whatsapp_redirect_failed_charge_amount_paise" => null
        ];
        supabase("PATCH", "lead_generation_settings?customer_id=eq." . urlencode($customerId), $updates);
        return array_merge($leadSettings, $updates);
    }
    $updates = [
        "redirect_whatsapp" => false,
        "whatsapp_redirect_stopped_at" => gmdate('Y-m-d\TH:i:s\Z'),
        "whatsapp_redirect_stopped_reason" => "insufficient_wallet_balance",
        "whatsapp_redirect_failed_charge_amount_paise" => $amountPaise
    ];
    supabase("PATCH", "lead_generation_settings?customer_id=eq." . urlencode($customerId), $updates);
    widget_record_zero_debit(
        $billingEmail,
        $customerId,
        "WhatsApp Redirect renewal skipped: ₹0 charged because wallet balance was insufficient. Service turned OFF.",
        "whatsapp_redirect_addon_failed",
        $customerId,
        ["required_paise" => $amountPaise, "reason" => "insufficient_wallet_balance"]
    );
    widget_send_whatsapp_redirect_stopped_email($billingEmail, (string)($signup['website_name'] ?? ''));
    return array_merge($leadSettings, $updates);
}

function widget_last_verification_time(array $lead, string $metadataKey): int {
    $meta = is_array($lead['metadata'] ?? null) ? $lead['metadata'] : [];
    $verifiedAt = strtotime((string)($meta[$metadataKey] ?? '')) ?: 0;
    if ($verifiedAt) {
        return $verifiedAt;
    }
    return strtotime((string)($lead['created_at'] ?? '')) ?: 0;
}

function widget_wallet_lead_charge_note(string $chargeKey): string {
    if (strpos($chargeKey, 'reactivated_') === 0) {
        return 'This person had not verified again for the last 30 days, so this is billed as a fresh/reactivated lead.';
    }
    if (strpos($chargeKey, 'repeat_') === 0) {
        return 'Repeat verification within 30 days.';
    }
    return 'Fresh lead verification.';
}

function widget_wallet_lead_charge_description(string $channel, string $chargeKey): string {
    $labels = [
        'fresh_email_lead' => 'Fresh Email OTP Lead',
        'repeat_email_lead' => 'Repeat Email OTP Verification',
        'reactivated_email_lead' => 'Reactivated Email OTP Lead',
        'fresh_mobile_lead' => 'Fresh Mobile OTP Lead',
        'repeat_mobile_lead' => 'Repeat Mobile OTP Verification',
        'reactivated_mobile_lead' => 'Reactivated Mobile OTP Lead'
    ];
    $description = $channel . ' OTP verification - ' . ($labels[$chargeKey] ?? str_replace('_', ' ', $chargeKey));
    if (strpos($chargeKey, 'reactivated_') === 0) {
        $description .= ' (not verified for last 30 days)';
    }
    return $description;
}

function widget_charge_email_otp_lead(string $customerId, array $lead): array {
    $meta = is_array($lead['metadata'] ?? null) ? $lead['metadata'] : [];
    if (!empty($meta['wallet_email_otp_charged_at'])) {
        return ["success" => true, "charged" => false, "message" => "Already charged"];
    }
    $email = widget_billing_email_for_customer($customerId);
    $planId = widget_billing_plan_for_customer($customerId);
    $leadEmail = trim((string)($lead['email'] ?? ''));
    $leadId = (string)($lead['id'] ?? '');
    $existingLeads = $leadEmail !== '' ? widget_safe_rows(supabase(
        "GET",
        "lead_generation_leads?select=id,created_at,metadata,email_otp_verified&customer_id=eq." . urlencode($customerId) . "&email=eq." . urlencode($leadEmail) . "&order=created_at.asc"
    )) : [];
    $olderVerified = array_values(array_filter($existingLeads, fn($row) => (string)($row['id'] ?? '') !== $leadId && !empty($row['email_otp_verified'])));
    $chargeKey = 'fresh_email_lead';
    if (!empty($olderVerified)) {
        $last = end($olderVerified);
        $lastVerified = widget_last_verification_time($last, 'wallet_email_otp_charged_at');
        $chargeKey = ($lastVerified && (time() - $lastVerified) > 30 * 86400) ? 'reactivated_email_lead' : 'repeat_email_lead';
    }
    $amountPaise = billing_wallet_charge_paise($planId, $chargeKey);
    $charge = widget_debit_wallet($email, $customerId, $amountPaise, widget_wallet_lead_charge_description('Email', $chargeKey), "lead_email_otp", $leadId, [
        "plan_id" => $planId,
        "charge_key" => $chargeKey,
        "lead_email" => $leadEmail,
        "billing_note" => widget_wallet_lead_charge_note($chargeKey)
    ]);
    if (!$charge['success']) {
        return $charge;
    }
    $meta['wallet_email_otp_charged_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $meta['wallet_email_otp_charge_key'] = $chargeKey;
    $meta['wallet_email_otp_amount_paise'] = $amountPaise;
    $meta['wallet_email_otp_charge_note'] = widget_wallet_lead_charge_note($chargeKey);
    supabase("PATCH", "lead_generation_leads?id=eq." . urlencode($leadId), [
        "metadata" => (object)$meta
    ]);
    $charge['metadata'] = $meta;
    return $charge;
}

function widget_charge_mobile_otp_lead(string $customerId, array $lead): array {
    $meta = is_array($lead['metadata'] ?? null) ? $lead['metadata'] : [];
    if (!empty($meta['wallet_mobile_otp_charged_at'])) {
        return ["success" => true, "charged" => false, "message" => "Already charged", "metadata" => $meta];
    }
    $email = widget_billing_email_for_customer($customerId);
    $planId = widget_billing_plan_for_customer($customerId);
    $leadPhone = preg_replace('/\D+/', '', (string)($lead['phone_number'] ?? ''));
    $leadId = (string)($lead['id'] ?? '');
    $existingLeads = $leadPhone !== '' ? widget_safe_rows(supabase(
        "GET",
        "lead_generation_leads?select=id,created_at,metadata,phone_number,mobile_otp_verified&customer_id=eq." . urlencode($customerId) . "&order=created_at.asc"
    )) : [];
    $olderVerified = array_values(array_filter($existingLeads, function($row) use ($leadId, $leadPhone) {
        $rowPhone = preg_replace('/\D+/', '', (string)($row['phone_number'] ?? ''));
        return (string)($row['id'] ?? '') !== $leadId && $rowPhone === $leadPhone && !empty($row['mobile_otp_verified']);
    }));
    $chargeKey = 'fresh_mobile_lead';
    if (!empty($olderVerified)) {
        $last = end($olderVerified);
        $lastVerified = widget_last_verification_time($last, 'wallet_mobile_otp_charged_at');
        $chargeKey = ($lastVerified && (time() - $lastVerified) > 30 * 86400) ? 'reactivated_mobile_lead' : 'repeat_mobile_lead';
    }
    $amountPaise = billing_wallet_charge_paise($planId, $chargeKey);
    $charge = widget_debit_wallet($email, $customerId, $amountPaise, widget_wallet_lead_charge_description('Mobile', $chargeKey), "lead_mobile_otp", $leadId, [
        "plan_id" => $planId,
        "charge_key" => $chargeKey,
        "lead_phone" => $leadPhone,
        "billing_note" => widget_wallet_lead_charge_note($chargeKey)
    ]);
    if (!$charge['success']) {
        return $charge;
    }
    $meta['wallet_mobile_otp_charged_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $meta['wallet_mobile_otp_charge_key'] = $chargeKey;
    $meta['wallet_mobile_otp_amount_paise'] = $amountPaise;
    $meta['wallet_mobile_otp_charge_note'] = widget_wallet_lead_charge_note($chargeKey);
    supabase("PATCH", "lead_generation_leads?id=eq." . urlencode($leadId), [
        "metadata" => (object)$meta
    ]);
    $charge['metadata'] = $meta;
    return $charge;
}

function widget_get_lead_settings(string $customerId): array {
    $rows = widget_safe_rows(supabase(
        "GET",
        "lead_generation_settings?select=*&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    return $rows[0] ?? [];
}

function widget_existing_lead(string $customerId, string $userId): array {
    $rows = widget_safe_rows(supabase(
        "GET",
        "lead_generation_leads?select=*&customer_id=eq." . urlencode($customerId) . "&user_id=eq." . urlencode($userId) . "&limit=1"
    ));
    return $rows[0] ?? [];
}

function widget_lead_metadata(array $lead): array {
    $meta = $lead['metadata'] ?? [];
    return is_array($meta) ? $meta : [];
}

function widget_save_lead(string $customerId, string $userId, array $fields): array {
    $existing = widget_existing_lead($customerId, $userId);
    $metadata = $fields['metadata'] ?? null;
    unset($fields['metadata']);

    if (is_array($metadata)) {
        $fields['metadata'] = (object)array_merge(widget_lead_metadata($existing), $metadata);
    }

    if (!empty($existing['id'])) {
        $res = supabase(
            "PATCH",
            "lead_generation_leads?id=eq." . urlencode((string)$existing['id']) . "&customer_id=eq." . urlencode($customerId),
            $fields
        );
    } else {
        $fields = array_merge([
            "customer_id" => $customerId,
            "user_id" => $userId,
            "whatsapp_redirected" => false,
            "email_otp_verified" => false,
            "mobile_otp_verified" => false,
            "notification_email_sent" => false,
            "verification_quality" => "poor",
            "metadata" => (object)[]
        ], $fields);
        $res = supabase("POST", "lead_generation_leads", [$fields]);
    }

    return $res;
}

function widget_lead_notification_charge_key(array $lead, string $eventType): string {
    $meta = widget_lead_metadata($lead);
    if ($eventType === 'mobile') {
        return (string)($meta['wallet_mobile_otp_charge_key'] ?? '');
    }
    if ($eventType === 'email') {
        return (string)($meta['wallet_email_otp_charge_key'] ?? '');
    }
    return (string)($meta['wallet_email_otp_charge_key'] ?? $meta['wallet_mobile_otp_charge_key'] ?? '');
}

function widget_lead_notification_type_label(string $chargeKey, string $eventType): string {
    if (strpos($chargeKey, 'reactivated_') === 0) {
        return 'Reactivated fresh lead';
    }
    if (strpos($chargeKey, 'fresh_') === 0 || $chargeKey === '') {
        return 'Fresh lead';
    }
    return $eventType === 'mobile' ? 'Mobile lead' : 'Email lead';
}

function widget_lead_notification_note(string $chargeKey): string {
    if (strpos($chargeKey, 'reactivated_') === 0) {
        return 'This person has not verified for the last 30 days, so Vani treated and billed this as a fresh/reactivated lead.';
    }
    if (strpos($chargeKey, 'fresh_') === 0 || $chargeKey === '') {
        return 'This is a fresh lead captured by your Vani chatbot.';
    }
    return 'This was a repeat lead within 30 days.';
}

function widget_lead_notification_html(array $lead, string $eventType, string $leadTypeLabel, string $note): string {
    $leadEmail = trim((string)($lead['email'] ?? ''));
    $leadPhone = trim((string)($lead['phone_number'] ?? ''));
    $sourceUrl = trim((string)($lead['source_url'] ?? ''));
    $createdAt = trim((string)($lead['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z')));
    $channel = $eventType === 'mobile' ? 'Mobile OTP' : ($eventType === 'identity' ? 'Identity verification' : 'Email OTP');

    $rows = '';
    foreach ([
        'Lead type' => $leadTypeLabel,
        'Channel' => $channel,
        'Email' => $leadEmail,
        'Mobile' => $leadPhone,
        'Source' => $sourceUrl,
        'Captured at' => $createdAt
    ] as $label => $value) {
        if ($value === '') {
            continue;
        }
        $rows .= '<tr><td style="padding:12px 0;color:#64748b;font-size:13px;font-weight:700;border-bottom:1px solid #e5e7eb;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td><td style="padding:12px 0;color:#0f172a;font-size:14px;font-weight:800;text-align:right;border-bottom:1px solid #e5e7eb;word-break:break-word;">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }

    return '<!doctype html><html><body style="margin:0;padding:0;background:#f6f8fc;font-family:Arial,sans-serif;color:#0f172a;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f8fc;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:20px;overflow:hidden;box-shadow:0 20px 48px rgba(15,23,42,.08);">'
        . '<tr><td style="padding:26px 28px;background:linear-gradient(135deg,#4f46e5,#0891b2);color:#ffffff;">'
        . '<div style="font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;opacity:.86;">Vani AI Lead Alert</div>'
        . '<h1 style="margin:10px 0 6px;font-size:28px;line-height:1.2;">Congratulations, you have a new lead</h1>'
        . '<p style="margin:0;font-size:15px;line-height:1.6;opacity:.92;">Your chatbot captured a qualified visitor contact.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:26px 28px;">'
        . '<div style="display:inline-block;padding:7px 11px;border-radius:999px;background:#eef2ff;color:#4f46e5;font-size:12px;font-weight:800;">' . htmlspecialchars($leadTypeLabel, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<p style="margin:18px 0 18px;color:#334155;font-size:15px;line-height:1.7;">' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">' . $rows . '</table>'
        . '<div style="margin-top:22px;padding:14px 16px;border-radius:14px;background:#ecfeff;border:1px solid #bae6fd;color:#164e63;font-size:14px;line-height:1.6;">Follow up quickly while the visitor intent is fresh.</div>'
        . '</td></tr>'
        . '<tr><td style="padding:18px 28px;background:#f8fafc;color:#64748b;font-size:12px;line-height:1.6;">This email was sent by Vani AI from Codrant because lead notification is enabled for your chatbot.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function widget_notify_lead_by_email(string $customerId, array $lead, string $eventType = 'lead'): bool {
    $leadId = (int)($lead['id'] ?? 0);
    if ($leadId) {
        $latest = widget_safe_rows(supabase(
            "GET",
            "lead_generation_leads?select=email,phone_number,source_url,metadata&id=eq." . $leadId . "&customer_id=eq." . urlencode($customerId) . "&limit=1"
        ));
        if (!empty($latest[0])) {
            $lead = array_merge($lead, $latest[0]);
        }
    }

    $leadEmail = trim((string)($lead['email'] ?? ''));
    $leadPhone = trim((string)($lead['phone_number'] ?? ''));
    if (!$leadId) {
        return false;
    }

    $eventType = in_array($eventType, ['email', 'mobile', 'identity'], true) ? $eventType : ($leadPhone !== '' ? 'mobile' : 'email');
    if (($eventType === 'email' && $leadEmail === '') ||
        ($eventType === 'mobile' && $leadPhone === '') ||
        ($eventType === 'identity' && $leadEmail === '' && $leadPhone === '')) {
        return false;
    }

    $meta = widget_lead_metadata($lead);
    $chargeKey = widget_lead_notification_charge_key($lead, $eventType);
    if (strpos($chargeKey, 'repeat_') === 0) {
        return false;
    }
    $flag = $eventType . '_notification_email_sent';
    if (widget_bool($meta[$flag] ?? false)) {
        return false;
    }

    $leadSettings = widget_get_lead_settings($customerId);
    if (!widget_bool($leadSettings['notify_lead_by_email'] ?? false)) {
        return false;
    }

    $notificationEmail = trim((string)($leadSettings['notification_email'] ?? ''));
    if ($notificationEmail === '') {
        return false;
    }

    require_once __DIR__ . '/email.php';
    $leadTypeLabel = widget_lead_notification_type_label($chargeKey, $eventType);
    $note = widget_lead_notification_note($chargeKey);
    $subject = "Congratulations, new " . strtolower($leadTypeLabel) . " captured";
    $html = widget_lead_notification_html($lead, $eventType, $leadTypeLabel, $note);

    $sent = sendBrevoEmail($notificationEmail, $subject, $html);
    if ($sent) {
        $meta[$flag] = true;
        $meta[$flag . '_at'] = gmdate('Y-m-d\TH:i:s\Z');
        supabase("PATCH", "lead_generation_leads?id=eq." . $leadId, [
            "notification_email_sent" => true,
            "metadata" => (object)$meta
        ]);
    }

    return $sent;
}

function widget_create_handoff_ticket_if_enabled(string $customerId, array $settings, string $question, string $botResponse, string $sourceUrl = '', string $userId = '', ?int $conversationId = null): void {
    $activePlan = widget_billing_plan_for_customer($customerId);
    if (!billing_feature_enabled($activePlan, 'human_handoff') || !widget_bool($settings['handoff_enabled'] ?? false)) {
        return;
    }
    $notificationEmail = trim((string)($settings['handoff_email'] ?? ''));
    if (!filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $ticketRes = supabase("POST", "support_tickets", [[
        "customer_id" => $customerId,
        "conversation_id" => $conversationId,
        "user_id" => $userId !== '' ? $userId : null,
        "user_question" => $question,
        "bot_response" => $botResponse,
        "source_url" => $sourceUrl !== '' ? $sourceUrl : null,
        "status" => "open",
        "notification_email" => $notificationEmail,
        "email_sent" => false,
        "metadata" => (object)["created_by" => "widget_human_handoff"]
    ]]);
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
    if ($ticketId) {
        widget_webhook_deliver($customerId, "support_ticket.created", [
            "ticket" => [
                "id" => $ticketId,
                "conversation_id" => $conversationId,
                "user_id" => $userId !== '' ? $userId : null,
                "user_question" => $question,
                "bot_response" => $botResponse,
                "source_url" => $sourceUrl !== '' ? $sourceUrl : null,
                "status" => "open",
                "notification_email" => $notificationEmail,
                "email_sent" => $sent
            ]
        ], $settings, $activePlan);
    }
}

function widget_notify_feedback_by_email(string $customerId, array $feedback, array $settings = []): bool {
    if (!widget_bool($settings['faq_feedback_email_enabled'] ?? false)) {
        return false;
    }

    $signup = widget_get_signup($customerId);
    $notificationEmail = trim((string)($signup['email'] ?? ''));
    if (!filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $faqQuestion = '';
    $faqId = (int)($feedback['faq_id'] ?? 0);
    if ($faqId > 0) {
        $faqRows = widget_safe_rows(supabase(
            "GET",
            "faq_questions?select=question&id=eq." . urlencode((string)$faqId) . "&customer_id=eq." . urlencode($customerId) . "&limit=1"
        ));
        $faqQuestion = (string)($faqRows[0]['question'] ?? '');
    }

    $actionLabel = '';
    $actionId = (int)($feedback['action_id'] ?? 0);
    if ($actionId > 0) {
        $actionRows = widget_safe_rows(supabase(
            "GET",
            "faq_action_suggestions?select=label,action_type&id=eq." . urlencode((string)$actionId) . "&customer_id=eq." . urlencode($customerId) . "&limit=1"
        ));
        if (!empty($actionRows[0])) {
            $actionLabel = trim((string)($actionRows[0]['label'] ?? ''));
            if ($actionLabel === '') {
                $actionLabel = trim((string)($actionRows[0]['action_type'] ?? ''));
            }
        }
    }

    $rows = [
        'Feedback' => (string)($feedback['feedback_value'] ?? ''),
        'FAQ' => $faqQuestion,
        'Action' => $actionLabel ?: (string)($feedback['action_type'] ?? ''),
        'Source page' => (string)($feedback['source_url'] ?? ''),
        'Session' => (string)($feedback['session_id'] ?? ''),
        'User ID' => (string)($feedback['user_id'] ?? ''),
        'Received at' => gmdate('Y-m-d H:i:s') . ' UTC'
    ];

    $table = '';
    foreach ($rows as $label => $value) {
        if (trim($value) === '') {
            continue;
        }
        $table .= '<tr><td style="padding:11px 0;color:#64748b;font-size:13px;font-weight:700;border-bottom:1px solid #e5e7eb;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td><td style="padding:11px 0;color:#0f172a;font-size:14px;font-weight:800;text-align:right;border-bottom:1px solid #e5e7eb;word-break:break-word;">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }

    require_once __DIR__ . '/email.php';
    $websiteName = trim((string)($signup['website_name'] ?? 'your chatbot'));
    $subject = 'New chatbot feedback received';
    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f6f8fc;font-family:Arial,sans-serif;color:#0f172a;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f8fc;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:20px;overflow:hidden;box-shadow:0 20px 48px rgba(15,23,42,.08);">'
        . '<tr><td style="padding:26px 28px;background:linear-gradient(135deg,#4f46e5,#0891b2);color:#ffffff;">'
        . '<div style="font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;opacity:.86;">Vani AI Feedback</div>'
        . '<h1 style="margin:10px 0 6px;font-size:26px;line-height:1.2;">New feedback from ' . htmlspecialchars($websiteName, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p style="margin:0;font-size:15px;line-height:1.6;opacity:.92;">A visitor responded after an FAQ action suggestion.</p>'
        . '</td></tr><tr><td style="padding:26px 28px;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">' . $table . '</table>'
        . '</td></tr><tr><td style="padding:18px 28px;background:#f8fafc;color:#64748b;font-size:12px;line-height:1.6;">This email was sent because Receive Feedback via email is enabled for this chatbot.</td></tr>'
        . '</table></td></tr></table></body></html>';

    return sendBrevoEmail($notificationEmail, $subject, $html);
}

function widget_msg91_verify_access_token(string $accessToken): array {
    global $MSG91_AUTH_KEY;
    if ($MSG91_AUTH_KEY === '') {
        return ["status" => 0, "data" => [], "raw" => "Missing MSG91_AUTH_KEY"];
    }
    $payload = json_encode([
        "authkey" => $MSG91_AUTH_KEY,
        "access-token" => $accessToken
    ]);
    $context = stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Content-Type: application/json\r\nAccept: application/json\r\n",
            "content" => $payload,
            "ignore_errors" => true
        ]
    ]);
    $url = "https://control.msg91.com/api/v5/widget/verifyAccessToken";
    $raw = file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0])) {
        preg_match('{HTTP/\S*\s(\d{3})}', $http_response_header[0], $match);
        $status = intval($match[1] ?? 0);
    }
    $data = json_decode((string)$raw, true);
    return ["status" => $status, "data" => is_array($data) ? $data : [], "raw" => $raw];
}

function widget_nested_value(array $data, array $keys): string {
    foreach ($keys as $key) {
        $parts = explode('.', $key);
        $value = $data;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                $value = null;
                break;
            }
            $value = $value[$part];
        }
        if ($value !== null && $value !== '') {
            return trim((string)$value);
        }
    }
    return '';
}

function widget_find_value_by_keys($value, array $keys): string {
    if (!is_array($value)) {
        return '';
    }

    $wanted = array_flip(array_map(
        fn($key) => strtolower(preg_replace('/[^a-z0-9]/i', '', $key)),
        $keys
    ));

    foreach ($value as $key => $child) {
        $normalizedKey = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$key));
        if (isset($wanted[$normalizedKey]) && $child !== null && $child !== '' && !is_array($child)) {
            return trim((string)$child);
        }
    }

    foreach ($value as $child) {
        if (is_array($child)) {
            $found = widget_find_value_by_keys($child, $keys);
            if ($found !== '') {
                return $found;
            }
        }
    }

    return '';
}

function widget_jwt_payload(string $jwt): array {
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return [];
    }

    $payload = strtr($parts[1], '-_', '+/');
    $padding = strlen($payload) % 4;
    if ($padding) {
        $payload .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        return [];
    }

    $data = json_decode($decoded, true);
    return is_array($data) ? $data : [];
}

function widget_request_source_url(array $data = []): string {
    $value = trim((string)($data['source_url'] ?? $data['current_url'] ?? $_GET['source_url'] ?? $_GET['current_url'] ?? ''));
    if ($value !== '') {
        return $value;
    }
    return trim((string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''));
}

function widget_host_from_value(string $value): string {
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

function widget_domain_list(string $domains): array {
    $parts = preg_split('/[\s,]+/', $domains);
    $clean = [];
    foreach ($parts as $part) {
        $host = widget_host_from_value((string)$part);
        if ($host !== '') {
            $clean[$host] = true;
        }
    }
    return array_keys($clean);
}

function widget_host_matches_domain(string $host, string $domain): bool {
    if ($host === '' || $domain === '') {
        return false;
    }
    $suffix = '.' . $domain;
    return $host === $domain || substr($host, -strlen($suffix)) === $suffix;
}

function widget_access_result(array $settings, array $signup, string $sourceUrl, bool $allowedDomainsAvailable = true): array {
    $host = widget_host_from_value($sourceUrl);
    $websiteVerificationEnabled = widget_bool($settings['website_verification_enabled'] ?? false);
    $allowedDomainsEnabled = $allowedDomainsAvailable && widget_bool($settings['allowed_domains_enabled'] ?? false);

    if (!$websiteVerificationEnabled && !$allowedDomainsEnabled) {
        return [
            "allowed" => true,
            "status" => $settings['verification_status'] ?? 'Disabled',
            "host" => $host,
            "message" => ""
        ];
    }

    if ($websiteVerificationEnabled) {
        $websiteHost = widget_host_from_value((string)($signup['website_name'] ?? ''));
        if ($host === '' || $websiteHost === '' || !widget_host_matches_domain($host, $websiteHost)) {
            return [
                "allowed" => false,
                "status" => "Failed",
                "host" => $host,
                "message" => "This website is not verified for this chatbot."
            ];
        }
    }

    if ($allowedDomainsEnabled) {
        $domains = widget_domain_list((string)($settings['allowed_domains'] ?? ''));
        $matchesAllowedDomain = false;
        foreach ($domains as $domain) {
            if (widget_host_matches_domain($host, $domain)) {
                $matchesAllowedDomain = true;
                break;
            }
        }
        if (!$matchesAllowedDomain) {
            return [
                "allowed" => false,
                "status" => $websiteVerificationEnabled ? "Verified" : ($settings['verification_status'] ?? 'Disabled'),
                "host" => $host,
                "message" => "This domain is not allowed for this chatbot."
            ];
        }
    }

    return [
        "allowed" => true,
        "status" => $websiteVerificationEnabled ? "Verified" : ($settings['verification_status'] ?? 'Disabled'),
        "host" => $host,
        "message" => ""
    ];
}

function widget_int_or_null($value): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? (int)$value : null;
}

function widget_string_or_null($value, int $max = 500): ?string {
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }
    return substr($text, 0, $max);
}

function widget_geo_headers(): array {
    $countryCode = $_SERVER['HTTP_CF_IPCOUNTRY']
        ?? $_SERVER['HTTP_X_VERCEL_IP_COUNTRY']
        ?? $_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY']
        ?? '';
    $countryName = $_SERVER['HTTP_X_VERCEL_IP_COUNTRY_REGION']
        ?? $_SERVER['HTTP_X_APPENGINE_COUNTRY']
        ?? '';
    $city = $_SERVER['HTTP_CF_IPCITY']
        ?? $_SERVER['HTTP_X_VERCEL_IP_CITY']
        ?? $_SERVER['HTTP_X_APPENGINE_CITY']
        ?? '';

    return [
        "country_code" => widget_string_or_null($countryCode, 8),
        "country_name" => widget_string_or_null($countryName ?: $countryCode, 120),
        "city" => widget_string_or_null(urldecode((string)$city), 120)
    ];
}

function widget_analytics_payload(array $data): array {
    $analytics = is_array($data['analytics'] ?? null) ? $data['analytics'] : [];
    $geo = widget_geo_headers();
    $payload = [
        "session_id" => widget_string_or_null($data['session_id'] ?? $analytics['session_id'] ?? '', 120),
        "referrer_url" => widget_string_or_null($analytics['referrer_url'] ?? '', 1000),
        "device_type" => widget_string_or_null($analytics['device_type'] ?? '', 40),
        "browser_name" => widget_string_or_null($analytics['browser_name'] ?? '', 80),
        "browser_version" => widget_string_or_null($analytics['browser_version'] ?? '', 40),
        "os_name" => widget_string_or_null($analytics['os_name'] ?? '', 80),
        "country_code" => $geo['country_code'] ?: widget_string_or_null($analytics['country_code'] ?? '', 8),
        "country_name" => $geo['country_name'] ?: widget_string_or_null($analytics['country_name'] ?? '', 120),
        "city" => $geo['city'] ?: widget_string_or_null($analytics['city'] ?? '', 120),
        "timezone" => widget_string_or_null($analytics['timezone'] ?? '', 120),
        "locale" => widget_string_or_null($analytics['locale'] ?? '', 80),
        "screen_width" => widget_int_or_null($analytics['screen_width'] ?? null),
        "screen_height" => widget_int_or_null($analytics['screen_height'] ?? null),
        "response_time_ms" => widget_int_or_null($analytics['response_time_ms'] ?? null)
    ];

    return array_filter($payload, fn($value) => $value !== null);
}

function widget_session_payload(array $data): array {
    $analytics = is_array($data['analytics'] ?? null) ? $data['analytics'] : $data;
    $geo = widget_geo_headers();
    $payload = [
        "customer_id" => widget_string_or_null($data['customer_id'] ?? '', 80),
        "session_id" => widget_string_or_null($data['session_id'] ?? $analytics['session_id'] ?? '', 120),
        "user_id" => widget_string_or_null($data['user_id'] ?? '', 120),
        "source_url" => widget_string_or_null($data['source_url'] ?? $analytics['source_url'] ?? '', 1000),
        "referrer_url" => widget_string_or_null($analytics['referrer_url'] ?? '', 1000),
        "current_page" => widget_string_or_null($analytics['current_page'] ?? '', 1000),
        "device_type" => widget_string_or_null($analytics['device_type'] ?? '', 40),
        "browser_name" => widget_string_or_null($analytics['browser_name'] ?? '', 80),
        "browser_version" => widget_string_or_null($analytics['browser_version'] ?? '', 40),
        "os_name" => widget_string_or_null($analytics['os_name'] ?? '', 80),
        "country_code" => $geo['country_code'] ?: widget_string_or_null($analytics['country_code'] ?? '', 8),
        "country_name" => $geo['country_name'] ?: widget_string_or_null($analytics['country_name'] ?? '', 120),
        "city" => $geo['city'] ?: widget_string_or_null($analytics['city'] ?? '', 120),
        "timezone" => widget_string_or_null($analytics['timezone'] ?? '', 120),
        "locale" => widget_string_or_null($analytics['locale'] ?? '', 80),
        "screen_width" => widget_int_or_null($analytics['screen_width'] ?? null),
        "screen_height" => widget_int_or_null($analytics['screen_height'] ?? null),
        "duration_seconds" => widget_int_or_null($data['duration_seconds'] ?? null),
        "message_count" => widget_int_or_null($data['message_count'] ?? null)
    ];
    foreach (["opened_at", "started_at", "ended_at"] as $key) {
        if (!empty($data[$key])) {
            $payload[$key] = $data[$key];
        }
    }
    $payload["last_seen_at"] = gmdate('Y-m-d\TH:i:s\Z');

    return array_filter($payload, fn($value) => $value !== null && $value !== '');
}

function widget_save_session(array $data): array {
    $payload = widget_session_payload($data);
    $customerId = $payload['customer_id'] ?? '';
    $sessionId = $payload['session_id'] ?? '';
    if ($customerId === '' || $sessionId === '') {
        return ["status" => 400, "data" => [], "raw" => "Missing customer_id or session_id"];
    }

    $existing = widget_safe_rows(supabase(
        "GET",
        "chatbot_sessions?select=id&customer_id=eq." . urlencode($customerId) . "&session_id=eq." . urlencode($sessionId) . "&limit=1"
    ));

    if (!empty($existing[0]['id'])) {
        return supabase(
            "PATCH",
            "chatbot_sessions?id=eq." . urlencode((string)$existing[0]['id']),
            $payload
        );
    }

    return supabase("POST", "chatbot_sessions", [$payload]);
}

if ($action === "get_widget_config" || $action === "get_theme") {
    $customerId = widget_customer_id();
    if (!$customerId) {
        widget_json_response(["success" => false, "message" => "Missing customer_id"], 400);
    }

    $settings = widget_get_settings($customerId);
    $signup = widget_get_signup($customerId);
    $leadSettings = widget_get_lead_settings($customerId);
    $activePlan = widget_billing_plan_for_customer($customerId);
    $leadSettings = widget_renew_whatsapp_redirect_if_due($customerId, $leadSettings, (string)($signup['email'] ?? ''), $activePlan, $signup);
    $access = widget_access_result($settings, $signup, widget_request_source_url(), billing_feature_enabled($activePlan, 'allowed_domains'));
    if (($settings['verification_status'] ?? '') !== $access['status']) {
        supabase(
            "PATCH",
            "chatbot_settings?customer_id=eq." . urlencode($customerId),
            ["verification_status" => $access['status']]
        );
    }
    $themeColor = $settings['theme_color'] ?? $signup['theme_color'] ?? '#6366f1';
    $themePattern = trim((string)($settings['theme_pattern'] ?? 'none')) ?: 'none';
    $botName = trim((string)($settings['bot_name'] ?? ''));
    if ($botName === '') {
        $botName = trim((string)($signup['website_name'] ?? ''));
    }
    if ($botName === '') {
        $botName = 'Chat Support';
    }
    $faqFeedbackActionIds = $settings['faq_feedback_action_ids'] ?? [];
    if (is_string($faqFeedbackActionIds)) {
        $decodedFeedbackActionIds = json_decode($faqFeedbackActionIds, true);
        $faqFeedbackActionIds = is_array($decodedFeedbackActionIds) ? $decodedFeedbackActionIds : [];
    }
    $faqFeedbackActionIds = array_values(array_filter(array_map('strval', is_array($faqFeedbackActionIds) ? $faqFeedbackActionIds : []), fn($id) => preg_match('/^\d+$/', $id)));
    $faqFeedbackType = (string)($settings['faq_feedback_type'] ?? 'labels');
    if (!in_array($faqFeedbackType, ['stars', 'emoji', 'labels', 'slider', 'comment'], true)) {
        $faqFeedbackType = 'labels';
    }
    $canUseFaqFeedback = billing_feature_enabled($activePlan, 'faq_feedback');
    if (!$canUseFaqFeedback) {
        $faqFeedbackActionIds = [];
    }
    $paymentSettings = widget_customer_payment_settings($customerId);
    $canUsePaymentCollection = billing_feature_enabled($activePlan, 'payment_collection');
    $paymentCollectionEnabled = $canUsePaymentCollection && widget_bool($paymentSettings['is_enabled'] ?? false);
    $razorpayCollectionEnabled = $paymentCollectionEnabled && widget_bool($paymentSettings['razorpay_enabled'] ?? false);
    $upiCollectionEnabled = $paymentCollectionEnabled && widget_bool($paymentSettings['upi_enabled'] ?? false);
    $paymentActions = $paymentCollectionEnabled ? widget_safe_rows(supabase(
        "GET",
        "customer_payment_actions?select=id,label,amount_paise,currency,payment_method,is_active&customer_id=eq." . urlencode($customerId) . "&is_active=eq.true&order=created_at.desc&limit=100"
    )) : [];
    $paymentActions = array_values(array_filter($paymentActions, function ($row) use ($razorpayCollectionEnabled, $upiCollectionEnabled) {
        $method = (string)($row['payment_method'] ?? 'razorpay');
        return $method === 'upi' ? $upiCollectionEnabled : $razorpayCollectionEnabled;
    }));

    widget_json_response([
        "success" => true,
        "theme_color" => $themeColor,
        "theme_pattern" => $themePattern,
        "bot_name" => $botName,
        "welcome_message" => $settings['welcome_message'] ?? 'Hi, how can I help you today?',
        "avatar_url" => $settings['avatar_url'] ?? '',
        "position" => $settings['position'] ?? 'right',
        "language" => $settings['language'] ?? 'English',
        "is_active" => widget_bool($settings['is_active'] ?? true, true) && $access['allowed'],
        "chat_open_by_default" => widget_bool($settings['chat_open_by_default'] ?? false),
        "user_input_enabled" => widget_bool($settings['user_input_enabled'] ?? true, true),
        "billing" => [
            "active_plan" => $activePlan,
            "email_otp" => billing_feature_enabled($activePlan, 'email_otp'),
            "mobile_otp" => billing_feature_enabled($activePlan, 'mobile_otp'),
            "whatsapp_redirect" => billing_feature_enabled($activePlan, 'whatsapp_redirect'),
            "allowed_domains" => billing_feature_enabled($activePlan, 'allowed_domains'),
            "live_chat_actions" => billing_feature_enabled($activePlan, 'live_chat_actions'),
            "faq_action_suggestions" => billing_feature_enabled($activePlan, 'faq_action_suggestions'),
            "faq_feedback" => $canUseFaqFeedback,
            "payment_collection" => $canUsePaymentCollection
        ],
        "website_verification_enabled" => widget_bool($settings['website_verification_enabled'] ?? false),
        "allowed_domains_enabled" => widget_bool($settings['allowed_domains_enabled'] ?? false) && billing_feature_enabled($activePlan, 'allowed_domains'),
        "allowed_domains" => $settings['allowed_domains'] ?? '',
        "live_chat_actions_enabled" => widget_bool($settings['live_chat_actions_enabled'] ?? false) && billing_feature_enabled($activePlan, 'live_chat_actions'),
        "faq_actions_enabled" => widget_bool($settings['faq_actions_enabled'] ?? false) && billing_feature_enabled($activePlan, 'faq_action_suggestions'),
        "faq_category_menu_enabled" => widget_bool($settings['faq_category_menu_enabled'] ?? false),
        "faq_feedback_enabled" => $canUseFaqFeedback && widget_bool($settings['faq_feedback_enabled'] ?? false),
        "faq_feedback_type" => $faqFeedbackType,
        "faq_feedback_action_ids" => $faqFeedbackActionIds,
        "faq_feedback_email_enabled" => $canUseFaqFeedback && widget_bool($settings['faq_feedback_email_enabled'] ?? false),
        "payment_collection_enabled" => $paymentCollectionEnabled,
        "razorpay_payment_enabled" => $razorpayCollectionEnabled,
        "upi_payment_enabled" => $upiCollectionEnabled,
        "upi_transaction_id_required" => widget_bool($paymentSettings['upi_transaction_id_required'] ?? true, true),
        "payment_collect_payer_email" => widget_bool($paymentSettings['collect_payer_email'] ?? true, true),
        "payment_collect_payer_phone" => widget_bool($paymentSettings['collect_payer_phone'] ?? true, true),
        "payment_verify_payer_email_otp" => widget_bool($paymentSettings['verify_payer_email_otp'] ?? false) && billing_feature_enabled($activePlan, 'email_otp'),
        "payment_verify_payer_phone_otp" => widget_bool($paymentSettings['verify_payer_phone_otp'] ?? false) && billing_feature_enabled($activePlan, 'mobile_otp'),
        "payment_actions" => array_values(array_map(fn($row) => [
            "id" => (string)($row['id'] ?? ''),
            "label" => (string)($row['label'] ?? 'Payment'),
            "amount_paise" => (int)($row['amount_paise'] ?? 0),
            "currency" => (string)($row['currency'] ?? 'INR'),
            "payment_method" => (string)($row['payment_method'] ?? 'razorpay')
        ], $paymentActions)),
        "scheduled_faq_actions" => widget_scheduled_faq_actions($customerId, $settings, $activePlan),
        "verification_status" => $access['status'],
        "access_allowed" => $access['allowed'],
        "access_message" => $access['message'],
        "lead_generation" => [
            "is_enabled" => widget_bool($leadSettings['is_enabled'] ?? false),
            "collect_location" => widget_bool($leadSettings['collect_location'] ?? false),
            "collect_email" => widget_bool($leadSettings['collect_email'] ?? false),
            "collect_mobile" => widget_bool($leadSettings['collect_mobile'] ?? false),
            "verify_email_otp" => widget_bool($leadSettings['verify_email_otp'] ?? false) && billing_feature_enabled($activePlan, 'email_otp'),
            "notify_lead_by_email" => widget_bool($leadSettings['notify_lead_by_email'] ?? false),
            "redirect_whatsapp" => widget_bool($leadSettings['redirect_whatsapp'] ?? false) && billing_feature_enabled($activePlan, 'whatsapp_redirect'),
            "whatsapp_mobile_number" => $leadSettings['whatsapp_mobile_number'] ?? '',
            "verify_mobile_otp" => widget_bool($leadSettings['verify_mobile_otp'] ?? false) && billing_feature_enabled($activePlan, 'mobile_otp'),
            "service_tier" => $leadSettings['service_tier'] ?? 'free'
        ],
        "msg91_widget" => [
            "widget_id" => $MSG91_WIDGET_ID,
            "token_auth" => $MSG91_TOKEN_AUTH,
            "configured" => ($MSG91_WIDGET_ID !== '' && $MSG91_TOKEN_AUTH !== '')
        ]
    ]);
}

if ($action === "chat") {
    $data = widget_get_json();
    $requestStartedAt = microtime(true);
    $customerId = widget_customer_id($data);
    $message = trim((string)($data['message'] ?? ''));
    $selectedFaqId = (int)($data['faq_id'] ?? $data['question_id'] ?? 0);
    $selectedDefaultFaqKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($data['default_faq_key'] ?? '')));
    $userId = trim((string)($data['user_id'] ?? ''));
    $sourceUrl = trim((string)($data['source_url'] ?? ''));

    if (!$customerId || !$message) {
        widget_json_response(["success" => false, "message" => "Missing customer_id or message"], 400);
    }

    $settings = widget_get_settings($customerId);
    if (!widget_bool($settings['is_active'] ?? true, true)) {
        widget_json_response([
            "success" => true,
            "reply" => "Chatbot is currently turned off. Please contact customer support.",
            "status" => "inactive"
        ]);
    }

    $signup = widget_get_signup($customerId);
    $activePlan = widget_billing_plan_for_customer($customerId);
    $access = widget_access_result($settings, $signup, widget_request_source_url($data), billing_feature_enabled($activePlan, 'allowed_domains'));
    if (!$access['allowed']) {
        widget_json_response([
            "success" => true,
            "reply" => $access['message'] ?: "This chatbot is not enabled for this website.",
            "status" => "blocked"
        ]);
    }

    $faqs = widget_safe_rows(supabase(
        "GET",
        "faq_questions?select=id,question,answer&customer_id=eq." . urlencode($customerId) . widget_faq_active_query_suffix($customerId)
    ));

    $reply = null;
    $matchedFaqId = null;
    $matchedDefaultFaqKey = null;

    $defaultFaqItems = widget_default_faq_items($customerId, $settings, $signup);
    if ($selectedDefaultFaqKey !== '' && !empty($defaultFaqItems[$selectedDefaultFaqKey])) {
        $reply = (string)$defaultFaqItems[$selectedDefaultFaqKey]['answer'];
        $matchedDefaultFaqKey = $selectedDefaultFaqKey;
    }

    if (($reply === null || $reply === '') && $selectedFaqId > 0) {
        $selectedRows = widget_safe_rows(supabase(
            "GET",
            "faq_questions?select=id,question,answer&customer_id=eq." . urlencode($customerId) . "&id=eq." . urlencode((string)$selectedFaqId) . "&limit=1"
        ));
        if (!empty($selectedRows[0])) {
            $activeRows = widget_safe_rows(supabase(
                "GET",
                "faq_questions?select=id&customer_id=eq." . urlencode($customerId) . widget_faq_active_query_suffix($customerId)
            ));
            $activeIds = array_flip(array_map(fn($row) => (string)($row['id'] ?? ''), $activeRows));
            if (isset($activeIds[(string)$selectedRows[0]['id']])) {
                $reply = (string)($selectedRows[0]['answer'] ?? '');
                $matchedFaqId = $selectedRows[0]['id'] ?? null;
            }
        }
    }

    $input = strtolower($message);
    if ($reply === null || $reply === '') {
        foreach ($faqs as $faq) {
            $question = strtolower(trim((string)($faq['question'] ?? '')));
            if (!$question) {
                continue;
            }

            similar_text($input, $question, $percent);
            if ($input === $question || strpos($input, $question) !== false || strpos($question, $input) !== false || $percent > 70) {
                $reply = (string)($faq['answer'] ?? '');
                $matchedFaqId = $faq['id'] ?? null;
                break;
            }
        }
    }

    $answered = $reply !== null && $reply !== '';
    $defaultSuggestions = [];
    if (!$answered) {
        $fallback = $defaultFaqItems['fallback_contact'] ?? null;
        $reply = $fallback['answer'] ?? "Sorry, I don't have an answer for that yet. Please contact customer support for help.";
        foreach ($defaultFaqItems as $key => $item) {
            if ($key === 'fallback_contact') {
                continue;
            }
            $defaultSuggestions[] = [
                "default_faq_key" => $key,
                "question" => (string)$item['question']
            ];
        }
    }

    $conversationPayload = [
        "customer_id" => $customerId,
        "user_question" => $message,
        "bot_response" => $reply,
        "matched_faq_id" => $matchedFaqId,
        "status" => $answered ? "answered" : "unanswered",
        "is_answered" => $answered,
        "user_id" => $userId,
        "source_url" => $sourceUrl
    ];
    $conversationPayload = array_merge($conversationPayload, widget_analytics_payload($data));
    $conversationPayload["response_time_ms"] = (int)round((microtime(true) - $requestStartedAt) * 1000);

    $conversationRes = supabase("POST", "chatbot_conversations", [$conversationPayload]);
    $conversationId = isset($conversationRes['data'][0]['id']) ? (int)$conversationRes['data'][0]['id'] : null;
    if ($conversationRes['status'] >= 400) {
        unset(
            $conversationPayload["session_id"],
            $conversationPayload["referrer_url"],
            $conversationPayload["device_type"],
            $conversationPayload["browser_name"],
            $conversationPayload["browser_version"],
            $conversationPayload["os_name"],
            $conversationPayload["country_code"],
            $conversationPayload["country_name"],
            $conversationPayload["city"],
            $conversationPayload["timezone"],
            $conversationPayload["locale"],
            $conversationPayload["screen_width"],
            $conversationPayload["screen_height"],
            $conversationPayload["response_time_ms"]
        );
        $fallbackConversationRes = supabase("POST", "chatbot_conversations", [$conversationPayload]);
        $conversationId = isset($fallbackConversationRes['data'][0]['id']) ? (int)$fallbackConversationRes['data'][0]['id'] : $conversationId;
    }
    if ($conversationId) {
        widget_webhook_deliver($customerId, $answered ? "conversation.answered" : "conversation.unanswered", [
            "conversation" => array_merge($conversationPayload, ["id" => $conversationId])
        ], $settings, $activePlan);
    }

    if (!$answered) {
        widget_create_handoff_ticket_if_enabled($customerId, $settings, $message, $reply, $sourceUrl, $userId, $conversationId);
    }

    $faqActions = $answered && $matchedFaqId
        ? widget_faq_action_suggestions($customerId, $matchedFaqId, $settings, $activePlan)
        : [];

    if (!empty($data['session_id'])) {
        widget_save_session(array_merge($data, [
            "started_at" => $data['started_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            "message_count" => (int)($data['message_count'] ?? 1)
        ]));
    }

    widget_json_response([
        "success" => true,
        "reply" => $reply,
        "answered" => $answered,
        "matched_faq_id" => $matchedFaqId,
        "matched_default_faq_key" => $matchedDefaultFaqKey,
        "default_suggestions" => $defaultSuggestions,
        "actions" => $faqActions
    ]);
}

if ($action === "get_top_faqs") {
    $customerId = widget_customer_id();
    $showAll = widget_bool($_GET['all'] ?? false);
    if (!$customerId) {
        widget_json_response(["success" => false, "message" => "Missing customer_id"], 400);
    }

    if ($showAll) {
        $res = supabase(
            "GET",
            "faq_questions?select=id,question&customer_id=eq." . urlencode($customerId) . widget_faq_active_query_suffix($customerId, "category.asc,question.asc")
        );
        widget_json_response(["success" => true, "data" => widget_safe_rows($res)]);
    }

    $usageRows = widget_safe_rows(supabase(
        "GET",
        "faq_usage?select=question_id&customer_id=eq." . urlencode($customerId)
    ));

    $counts = [];
    foreach ($usageRows as $row) {
        $questionId = (string)($row['question_id'] ?? '');
        if ($questionId !== '') {
            $counts[$questionId] = ($counts[$questionId] ?? 0) + 1;
        }
    }

    arsort($counts);
    $topIds = array_slice(array_keys($counts), 0, 5);

    if (empty($topIds)) {
        $res = supabase(
            "GET",
            "faq_questions?select=id,question&customer_id=eq." . urlencode($customerId) . widget_faq_active_query_suffix($customerId) . "&limit=5"
        );
        widget_json_response(["success" => true, "data" => widget_safe_rows($res)]);
    }

    $res = supabase(
        "GET",
        "faq_questions?select=id,question&customer_id=eq." . urlencode($customerId) . "&id=in.(" . implode(",", $topIds) . ")"
    );

    $questions = widget_safe_rows($res);
    $activeRows = widget_safe_rows(supabase(
        "GET",
        "faq_questions?select=id&customer_id=eq." . urlencode($customerId) . widget_faq_active_query_suffix($customerId)
    ));
    $activeIds = array_flip(array_map(fn($row) => (string)($row['id'] ?? ''), $activeRows));
    $questions = array_values(array_filter($questions, fn($row) => isset($activeIds[(string)($row['id'] ?? '')])));
    usort($questions, fn($a, $b) => ($counts[$b['id']] ?? 0) <=> ($counts[$a['id']] ?? 0));
    widget_json_response(["success" => true, "data" => $questions]);
}

if ($action === "get_faq_categories") {
    $customerId = widget_customer_id();
    if (!$customerId) {
        widget_json_response(["success" => false, "message" => "Missing customer_id"], 400);
    }

    $rows = widget_safe_rows(supabase(
        "GET",
        "faq_questions?select=category&customer_id=eq." . urlencode($customerId) . widget_faq_active_query_suffix($customerId, "category.asc")
    ));
    $categories = [];
    foreach ($rows as $row) {
        $category = trim((string)($row['category'] ?? 'General')) ?: 'General';
        $key = strtolower($category);
        if (!isset($categories[$key])) {
            $categories[$key] = [
                "category" => $category,
                "count" => 0
            ];
        }
        $categories[$key]["count"]++;
    }

    widget_json_response(["success" => true, "data" => array_values($categories)]);
}

if ($action === "get_faqs_by_category") {
    $customerId = widget_customer_id();
    $category = trim((string)($_GET['category'] ?? ''));
    $showAll = widget_bool($_GET['all'] ?? false);
    if (!$customerId || $category === '') {
        widget_json_response(["success" => false, "message" => "Missing customer_id or category"], 400);
    }

    $res = supabase(
        "GET",
        "faq_questions?select=id,question,category&customer_id=eq." . urlencode($customerId) . "&category=eq." . urlencode($category) . widget_faq_active_query_suffix($customerId)
    );
    $rows = widget_safe_rows($res);
    widget_json_response(["success" => true, "data" => $showAll ? $rows : array_slice($rows, 0, 6)]);
}

if ($action === "track_widget_session") {
    $data = widget_get_json();
    $res = widget_save_session($data);
    widget_json_response([
        "success" => ($res['status'] >= 200 && $res['status'] < 300),
        "debug" => $res
    ]);
}

if ($action === "submit_faq_action_form") {
    $data = widget_get_json();
    $customerId = widget_customer_id($data);
    $name = trim((string)($data['name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $message = trim((string)($data['message'] ?? ''));
    $actionLabel = trim((string)($data['action_label'] ?? 'FAQ action form'));
    $sourceUrl = trim((string)($data['source_url'] ?? ''));
    $userId = trim((string)($data['user_id'] ?? ''));

    if (!$customerId || $message === '') {
        widget_json_response(["success" => false, "message" => "Message is required"], 400);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        widget_json_response(["success" => false, "message" => "Enter a valid email"], 400);
    }

    $ticketPayload = [
        "customer_id" => $customerId,
        "user_id" => $userId !== '' ? $userId : null,
        "user_question" => $message,
        "bot_response" => $actionLabel,
        "source_url" => $sourceUrl !== '' ? $sourceUrl : null,
        "status" => "open",
        "email_sent" => false,
        "metadata" => (object)[
            "created_by" => "faq_action_form",
            "name" => $name,
            "email" => $email,
            "phone" => $phone,
            "action_id" => $data['action_id'] ?? null,
            "faq_id" => $data['faq_id'] ?? null
        ]
    ];
    $res = supabase("POST", "support_tickets", [$ticketPayload]);
    $ok = $res['status'] >= 200 && $res['status'] < 300;
    if ($ok) {
        widget_webhook_deliver($customerId, "faq_action.form_submitted", [
            "ticket" => $res['data'][0] ?? $ticketPayload
        ]);
    }
    widget_json_response(["success" => $ok, "ticket" => $res['data'][0] ?? null]);
}

if ($action === "submit_faq_action_feedback") {
    $data = widget_get_json();
    $customerId = widget_customer_id($data);
    $feedbackValue = trim((string)($data['feedback_value'] ?? ''));
    if (!$customerId || $feedbackValue === '') {
        widget_json_response(["success" => false, "message" => "Missing feedback"], 400);
    }
    $activePlan = widget_billing_plan_for_customer($customerId);
    $settings = widget_get_settings($customerId);
    if (!billing_feature_enabled($activePlan, 'faq_feedback') || !widget_bool($settings['faq_feedback_enabled'] ?? false)) {
        widget_json_response(["success" => false, "requires_growth" => true, "message" => "FAQ feedback requires Growth or Business plan"], 403);
    }

    $payload = [
        "customer_id" => $customerId,
        "faq_id" => !empty($data['faq_id']) ? (int)$data['faq_id'] : null,
        "action_id" => !empty($data['action_id']) && is_numeric($data['action_id']) ? (int)$data['action_id'] : null,
        "action_type" => trim((string)($data['action_type'] ?? '')),
        "feedback_value" => substr($feedbackValue, 0, 80),
        "user_id" => trim((string)($data['user_id'] ?? '')) ?: null,
        "session_id" => trim((string)($data['session_id'] ?? '')) ?: null,
        "source_url" => trim((string)($data['source_url'] ?? '')) ?: null
    ];
    $res = supabase("POST", "faq_action_feedback", [$payload]);
    $ok = $res['status'] >= 200 && $res['status'] < 300;
    $feedback = $res['data'][0] ?? $payload;
    $emailSent = $ok ? widget_notify_feedback_by_email($customerId, $feedback, $settings) : false;
    widget_json_response([
        "success" => $ok,
        "feedback" => $res['data'][0] ?? null,
        "email_sent" => $emailSent
    ]);
}

if ($action === "create_customer_payment_order") {
    $data = widget_get_json();
    $customerId = widget_customer_id($data);
    $paymentActionId = (int)($data['payment_action_id'] ?? $data['action_value'] ?? 0);
    if (!$customerId || $paymentActionId <= 0) {
        widget_json_response(["success" => false, "message" => "Missing payment action"], 400);
    }
    $settings = widget_customer_payment_settings($customerId);
    $activePlan = billing_active_plan_from_account(widget_billing_account_for_customer($customerId));
    if (!billing_feature_enabled($activePlan, 'payment_collection')) {
        widget_json_response(["success" => false, "requires_growth" => true, "message" => "Payment collection requires Growth or Business plan"], 403);
    }
    if (!widget_bool($settings['is_enabled'] ?? false)) {
        widget_json_response(["success" => false, "message" => "Payment collection is not enabled"], 403);
    }
    $paymentActions = widget_safe_rows(supabase(
        "GET",
        "customer_payment_actions?select=*&id=eq." . urlencode((string)$paymentActionId) . "&customer_id=eq." . urlencode($customerId) . "&is_active=eq.true&limit=1"
    ));
    $paymentAction = $paymentActions[0] ?? [];
    if (empty($paymentAction)) {
        widget_json_response(["success" => false, "message" => "Payment button not found"], 404);
    }
    $paymentMethod = (string)($paymentAction['payment_method'] ?? 'razorpay');
    if ($paymentMethod === 'upi' && !widget_bool($settings['upi_enabled'] ?? false)) {
        widget_json_response(["success" => false, "payment_method" => "upi", "message" => "UPI Redirect is not enabled for this chatbot"], 403);
    }
    if ($paymentMethod !== 'upi' && !widget_bool($settings['razorpay_enabled'] ?? false)) {
        widget_json_response(["success" => false, "payment_method" => "razorpay", "message" => "Razorpay Checkout is not enabled for this chatbot"], 403);
    }
    if ($paymentMethod === 'upi') {
        $amountPaise = (int)($paymentAction['amount_paise'] ?? 0);
        $upiId = trim((string)($paymentAction['upi_id'] ?? ''));
        if ($upiId === '') {
            widget_json_response(["success" => false, "message" => "UPI ID is not configured"], 400);
        }
        $upiPayee = trim((string)($paymentAction['upi_payee_name'] ?? '')) ?: trim((string)($settings['business_name'] ?? ''));
        $upiNote = trim((string)($paymentAction['upi_note'] ?? '')) ?: trim((string)($paymentAction['description'] ?? $paymentAction['label'] ?? 'Payment'));
        $txn = supabase("POST", "customer_payment_transactions", [[
            "customer_id" => $customerId,
            "payment_action_id" => $paymentActionId,
            "faq_action_id" => !empty($data['faq_action_id']) ? (int)$data['faq_action_id'] : null,
            "faq_id" => !empty($data['faq_id']) ? (int)$data['faq_id'] : null,
            "user_id" => trim((string)($data['user_id'] ?? '')) ?: null,
            "session_id" => trim((string)($data['session_id'] ?? '')) ?: null,
            "source_url" => trim((string)($data['source_url'] ?? '')) ?: null,
            "payer_name" => trim((string)($data['payer_name'] ?? '')) ?: null,
            "payer_email" => trim((string)($data['payer_email'] ?? '')) ?: null,
            "payer_phone" => trim((string)($data['payer_phone'] ?? '')) ?: null,
            "amount_paise" => $amountPaise,
            "currency" => (string)($paymentAction['currency'] ?? 'INR'),
            "status" => "created",
            "payment_method" => "upi",
            "metadata" => (object)["upi_id" => $upiId, "manual_verification_required" => true, "payment_action_label" => $paymentAction['label'] ?? 'Payment']
        ]]);
        if ($txn['status'] < 200 || $txn['status'] >= 300) {
            widget_json_response([
                "success" => false,
                "payment_method" => "upi",
                "message" => "UPI payment record could not be created. Please run the latest database migration and try again."
            ], 500);
        }
        $txnRow = $txn['data'][0] ?? [];
        $upiReference = 'VANI' . preg_replace('/\D+/', '', (string)($txnRow['id'] ?? time()));
        $upiLink = 'upi://pay?pa=' . rawurlencode($upiId)
            . '&pn=' . rawurlencode($upiPayee ?: 'Payment')
            . '&am=' . rawurlencode(number_format($amountPaise / 100, 2, '.', ''))
            . '&cu=' . rawurlencode((string)($paymentAction['currency'] ?? 'INR'))
            . '&tr=' . rawurlencode($upiReference)
            . '&tn=' . rawurlencode(trim($upiNote . ' ' . $upiReference));
        widget_json_response([
            "success" => true,
            "payment_method" => "upi",
            "upi_link" => $upiLink,
            "transaction" => $txnRow,
            "payment_action" => [
                "id" => (string)$paymentActionId,
                "label" => (string)($paymentAction['label'] ?? 'Payment'),
                "description" => (string)($paymentAction['description'] ?? ''),
                "amount_paise" => $amountPaise,
                "currency" => (string)($paymentAction['currency'] ?? 'INR')
            ],
            "success_message" => "UPI app opened. Payment will show as pending until the business verifies it."
        ]);
    }
    $amountPaise = (int)($paymentAction['amount_paise'] ?? 0);
    if (trim((string)($settings['razorpay_key_id'] ?? '')) === '' || app_decrypt_secret((string)($settings['razorpay_key_secret'] ?? '')) === '') {
        widget_json_response(["success" => false, "message" => "Razorpay payment setup is incomplete"], 400);
    }
    $receipt = substr("pay_" . $customerId . "_" . time() . "_" . bin2hex(random_bytes(3)), 0, 40);
    $order = widget_customer_razorpay_request($settings, "POST", "orders", [
        "amount" => $amountPaise,
        "currency" => (string)($paymentAction['currency'] ?? 'INR'),
        "receipt" => $receipt,
        "payment_capture" => true,
        "notes" => [
            "customer_id" => $customerId,
            "payment_action_id" => (string)$paymentActionId,
            "source" => "vani_widget_customer_payment"
        ]
    ]);
    if ($order['status'] < 200 || $order['status'] >= 300 || empty($order['data']['id'])) {
        widget_json_response(["success" => false, "message" => $order['data']['error']['description'] ?? "Payment order could not be created"], 500);
    }
    $txn = supabase("POST", "customer_payment_transactions", [[
        "customer_id" => $customerId,
        "payment_action_id" => $paymentActionId,
        "faq_action_id" => !empty($data['faq_action_id']) ? (int)$data['faq_action_id'] : null,
        "faq_id" => !empty($data['faq_id']) ? (int)$data['faq_id'] : null,
        "user_id" => trim((string)($data['user_id'] ?? '')) ?: null,
        "session_id" => trim((string)($data['session_id'] ?? '')) ?: null,
        "source_url" => trim((string)($data['source_url'] ?? '')) ?: null,
        "payer_name" => trim((string)($data['payer_name'] ?? '')) ?: null,
        "payer_email" => trim((string)($data['payer_email'] ?? '')) ?: null,
        "payer_phone" => trim((string)($data['payer_phone'] ?? '')) ?: null,
        "amount_paise" => $amountPaise,
        "currency" => (string)($paymentAction['currency'] ?? 'INR'),
        "status" => "created",
        "payment_method" => "razorpay",
        "razorpay_order_id" => (string)$order['data']['id'],
        "metadata" => (object)["payment_action_label" => $paymentAction['label'] ?? 'Payment']
    ]]);
    if ($txn['status'] < 200 || $txn['status'] >= 300) {
        widget_json_response([
            "success" => false,
            "payment_method" => "razorpay",
            "message" => "Razorpay order was created but could not be saved. Please run the latest database migration and try again."
        ], 500);
    }
    widget_json_response([
        "success" => true,
        "key_id" => (string)($settings['razorpay_key_id'] ?? ''),
        "order" => $order['data'],
        "payment_action" => [
            "id" => (string)$paymentActionId,
            "label" => (string)($paymentAction['label'] ?? 'Payment'),
            "description" => (string)($paymentAction['description'] ?? ''),
            "amount_paise" => $amountPaise,
            "currency" => (string)($paymentAction['currency'] ?? 'INR')
        ],
        "business_name" => (string)($settings['business_name'] ?? 'Payment'),
        "success_message" => (string)($settings['success_message'] ?? 'Payment received. Thank you.')
    ]);
}

if ($action === "submit_upi_transaction_reference") {
    $data = widget_get_json();
    $customerId = widget_customer_id($data);
    $transactionId = trim((string)($data['transaction_id'] ?? ''));
    $upiReference = trim((string)($data['upi_reference'] ?? ''));
    if (!$customerId || $transactionId === '' || $upiReference === '') {
        widget_json_response(["success" => false, "message" => "Enter the UPI transaction ID"], 400);
    }
    $upiReference = substr($upiReference, 0, 120);
    $rows = widget_safe_rows(supabase(
        "GET",
        "customer_payment_transactions?select=id,metadata,payer_phone&customer_id=eq." . urlencode($customerId)
        . "&id=eq." . urlencode($transactionId)
        . "&payment_method=eq.upi&limit=1"
    ));
    $txn = $rows[0] ?? [];
    if (empty($txn)) {
        widget_json_response(["success" => false, "message" => "UPI payment record not found"], 404);
    }
    $metadata = is_array($txn['metadata'] ?? null) ? $txn['metadata'] : [];
    $metadata['customer_upi_transaction_id'] = $upiReference;
    $metadata['customer_upi_transaction_id_submitted_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $res = supabase(
        "PATCH",
        "customer_payment_transactions?customer_id=eq." . urlencode($customerId) . "&id=eq." . urlencode($transactionId),
        ["metadata" => (object)$metadata]
    );
    widget_json_response([
        "success" => $res['status'] >= 200 && $res['status'] < 300,
        "message" => "UPI transaction ID saved. The business will verify and confirm.",
        "transaction" => $res['data'][0] ?? null
    ]);
}

if ($action === "verify_customer_payment") {
    $data = widget_get_json();
    $customerId = widget_customer_id($data);
    $orderId = trim((string)($data['razorpay_order_id'] ?? ''));
    $paymentId = trim((string)($data['razorpay_payment_id'] ?? ''));
    $signature = trim((string)($data['razorpay_signature'] ?? ''));
    $settings = widget_customer_payment_settings($customerId);
    $secret = app_decrypt_secret((string)($settings['razorpay_key_secret'] ?? ''));
    if (!$customerId || !$orderId || !$paymentId || !$signature || $secret === '') {
        widget_json_response(["success" => false, "message" => "Missing payment verification data"], 400);
    }
    $expected = hash_hmac('sha256', $orderId . "|" . $paymentId, $secret);
    if (!hash_equals($expected, $signature)) {
        supabase("PATCH", "customer_payment_transactions?razorpay_order_id=eq." . urlencode($orderId) . "&customer_id=eq." . urlencode($customerId), ["status" => "failed"]);
        widget_json_response(["success" => false, "message" => "Payment signature verification failed"], 400);
    }
    $payment = widget_customer_razorpay_request($settings, "GET", "payments/" . rawurlencode($paymentId), []);
    if ($payment['status'] < 200 || $payment['status'] >= 300 || empty($payment['data']['id']) || (($payment['data']['order_id'] ?? '') !== $orderId)) {
        widget_json_response(["success" => false, "message" => "Payment could not be verified"], 400);
    }
    $paymentStatus = (string)($payment['data']['status'] ?? '');
    $captured = $paymentStatus === 'captured' || !empty($payment['data']['captured']);
    $txnRows = widget_safe_rows(supabase(
        "GET",
        "customer_payment_transactions?select=metadata&razorpay_order_id=eq." . urlencode($orderId) . "&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    $metadata = is_array($txnRows[0]['metadata'] ?? null) ? $txnRows[0]['metadata'] : [];
    $metadata['payment_status'] = $paymentStatus;
    $metadata['razorpay_payment_method'] = (string)($payment['data']['method'] ?? '');
    supabase("PATCH", "customer_payment_transactions?razorpay_order_id=eq." . urlencode($orderId) . "&customer_id=eq." . urlencode($customerId), [
        "status" => $captured ? "paid" : "failed",
        "razorpay_payment_id" => $paymentId,
        "razorpay_signature" => $signature,
        "payer_name" => trim((string)($data['payer_name'] ?? '')) ?: null,
        "payer_email" => trim((string)($data['payer_email'] ?? '')) ?: null,
        "payer_phone" => trim((string)($data['payer_phone'] ?? '')) ?: null,
        "paid_at" => $captured ? gmdate('Y-m-d\TH:i:s\Z') : null,
        "metadata" => (object)$metadata
    ]);
    widget_json_response(["success" => $captured, "message" => $captured ? (string)($settings['success_message'] ?? 'Payment received. Thank you.') : "Payment is not captured yet"]);
}

// Create a lead record (generic) - used for location or simple lead saves
if ($action === "create_lead") {
    $data = widget_get_json();
    $customerId = widget_customer_id($data);
    $userId = trim((string)($data['user_id'] ?? ''));
    if (!$customerId || !$userId) widget_json_response(["success" => false, "message" => "Missing customer_id or user_id"], 400);

    $payload = [];
    foreach (["name", "email", "phone_number", "location_text", "source_url", "verification_quality"] as $key) {
        if (array_key_exists($key, $data)) {
            $payload[$key] = $data[$key];
        }
    }
    foreach (["whatsapp_redirected", "email_otp_verified", "mobile_otp_verified"] as $key) {
        if (array_key_exists($key, $data)) {
            $payload[$key] = !!$data[$key];
        }
    }
    if (isset($data['latitude'])) $payload["latitude"] = (float)$data['latitude'];
    if (isset($data['longitude'])) $payload["longitude"] = (float)$data['longitude'];
    if (isset($data['metadata']) && is_array($data['metadata'])) $payload["metadata"] = $data['metadata'];

    $res = widget_save_lead($customerId, $userId, $payload);
    $ok = ($res['status'] >= 200 && $res['status'] < 300);
    $lead = $res['data'][0] ?? null;
    $notified = [];
    if ($ok && $lead && !empty($payload['email'])) {
        $notified['email'] = widget_notify_lead_by_email($customerId, $lead, 'email');
    }
    if ($ok && $lead && !empty($payload['phone_number'])) {
        $notified['mobile'] = widget_notify_lead_by_email($customerId, $lead, 'mobile');
    }
    if ($ok && $lead) {
        widget_webhook_deliver($customerId, "lead.created", [
            "lead" => $lead,
            "source" => !empty($payload['whatsapp_redirected']) ? "whatsapp_redirect" : "widget"
        ]);
        if (!empty($payload['whatsapp_redirected'])) {
            widget_webhook_deliver($customerId, "whatsapp.redirect_clicked", [
                "lead" => $lead,
                "source_url" => $payload['source_url'] ?? null
            ]);
        }
    }
    widget_json_response(["success" => $ok, "debug" => $res, "lead" => $lead, "notified" => $notified]);
}

// Create a lead for email OTP verification and send OTP email
if ($action === "create_lead_send_email_otp") {
    $data = widget_get_json();
    $customerId = widget_customer_id($data);
    $toEmail = trim((string)($data['email'] ?? ''));
    $userId = trim((string)($data['user_id'] ?? ''));
    $suppressNotification = widget_bool($data['suppress_notification'] ?? false);
    if (!$customerId || !$userId || !$toEmail) widget_json_response(["success" => false, "message" => "Missing customer_id, user_id or email"], 400);
    if (!billing_feature_enabled(widget_billing_plan_for_customer($customerId), 'email_otp')) {
        widget_json_response(["success" => false, "requires_premium" => true, "message" => "Email OTP requires an active premium plan."], 403);
    }

    require_once __DIR__ . '/email.php';

    $otp = str_pad((string)rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + 600); // 10 minutes

    $metadata = [
        'email_otp' => $otp,
        'email_otp_expires_at' => $expiresAt
    ];

    $payload = [
        "customer_id" => $customerId,
        "user_id" => $userId,
        "email" => $toEmail,
        "email_otp_verified" => false,
        "notification_email_sent" => false,
        "verification_quality" => 'poor',
        "metadata" => $metadata,
        "source_url" => $data['source_url'] ?? null
    ];

    unset($payload["customer_id"], $payload["user_id"], $payload["notification_email_sent"]);
    $res = widget_save_lead($customerId, $userId, $payload);
    $ok = ($res['status'] >= 200 && $res['status'] < 300);
    $lead = $res['data'][0] ?? null;

    // Send OTP email
    $sent = false;
    $emailError = null;
    if ($ok && $lead) {
        $subject = "Your verification code";
        $html = "<p>Your verification code is <strong>" . htmlspecialchars($otp) . "</strong>. It expires in 10 minutes.</p>";
        $sent = sendBrevoEmail($toEmail, $subject, $html);
        if (!$sent) {
            $emailError = $GLOBALS['MAIL_LAST_ERROR'] ?? 'Unknown email error';
        }
    } else {
        if (!$ok && !$lead) {
            $emailError = 'Lead record could not be created.';
        }
    }
    $notified = (!$suppressNotification && $ok && $lead && $sent) ? widget_notify_lead_by_email($customerId, $lead, 'email') : false;
    if ($ok && $lead) {
        widget_webhook_deliver($customerId, "lead.email_otp_sent", [
            "lead" => $lead,
            "otp_sent" => $sent
        ]);
    }

    widget_json_response(["success" => ($ok && $sent), "lead" => $lead, "otp_sent" => $sent, "email_error" => $emailError, "notified" => $notified, "debug" => $res]);
}

// Verify MSG91 widget access token and save a verified mobile lead.
if ($action === "verify_lead_mobile_msg91") {
    $data = widget_get_json();
    $customerId = widget_customer_id($data);
    $userId = trim((string)($data['user_id'] ?? ''));
    $phone = trim((string)($data['phone_number'] ?? ''));
    $accessToken = trim((string)($data['msg91_access_token'] ?? ''));
    $sourceUrl = trim((string)($data['source_url'] ?? ''));
    $widgetResponse = is_array($data['msg91_response'] ?? null) ? $data['msg91_response'] : [];
    $suppressNotification = widget_bool($data['suppress_notification'] ?? false);

    if (!$customerId || !$userId || !$accessToken) {
        widget_json_response(["success" => false, "message" => "Missing mobile verification data"], 400);
    }
    if (!billing_feature_enabled(widget_billing_plan_for_customer($customerId), 'mobile_otp')) {
        widget_json_response(["success" => false, "requires_premium" => true, "message" => "Mobile OTP requires an active paid plan."], 403);
    }

    $lookup = widget_msg91_verify_access_token($accessToken);
    if ($lookup['status'] < 200 || $lookup['status'] >= 300) {
        widget_json_response(["success" => false, "message" => "MSG91 access token could not be verified", "debug" => $lookup], 400);
    }

    $verifyData = $lookup['data'];
    $identifierKeys = [
        'mobile',
        'phone',
        'phone_number',
        'mobile_number',
        'mobileNumber',
        'identifier',
        'contact',
        'contact_point',
        'contactPoint',
        'userIdentifier',
        'user_identifier'
    ];
    $verifiedPhone = widget_nested_value($verifyData, [
        'mobile',
        'phone',
        'phone_number',
        'mobile_number',
        'mobileNumber',
        'identifier',
        'contact',
        'contact_point',
        'contactPoint',
        'data.mobile',
        'data.phone',
        'data.phone_number',
        'data.mobile_number',
        'data.mobileNumber',
        'data.identifier',
        'data.contact',
        'data.contact_point',
        'data.contactPoint',
        'user.mobile',
        'user.phone',
        'user.phone_number',
        'user.mobile_number',
        'user.mobileNumber',
        'user.identifier'
    ]);
    if ($verifiedPhone === '') {
        $verifiedPhone = widget_find_value_by_keys($verifyData, $identifierKeys);
    }
    if ($verifiedPhone === '') {
        $verifiedPhone = widget_find_value_by_keys($widgetResponse, $identifierKeys);
    }
    if ($verifiedPhone === '') {
        $verifiedPhone = widget_find_value_by_keys(widget_jwt_payload($accessToken), $identifierKeys);
    }
    if ($verifiedPhone === '' && $phone !== '') {
        $verifiedPhone = $phone;
    }

    $normalizedInput = preg_replace('/\D+/', '', $phone);
    $normalizedVerified = preg_replace('/\D+/', '', $verifiedPhone);
    if ($normalizedVerified === '') {
        widget_json_response(["success" => false, "message" => "MSG91 did not return a verified mobile number"], 400);
    }
    if ($normalizedInput !== '' && $normalizedInput !== $normalizedVerified) {
        widget_json_response(["success" => false, "message" => "Verified phone number does not match"], 400);
    }

    $leadPayload = [
        "phone_number" => $verifiedPhone,
        "source_url" => $sourceUrl ?: null,
        "mobile_otp_verified" => false,
        "verification_quality" => "real",
        "metadata" => [
            "mobile_otp_status" => "verified",
            "otp_provider" => "msg91",
            "msg91_verified_phone" => $verifiedPhone,
            "msg91_verify_response" => $verifyData,
            "msg91_widget_response" => $widgetResponse
        ]
    ];
    $res = widget_save_lead($customerId, $userId, $leadPayload);
    $ok = ($res['status'] >= 200 && $res['status'] < 300);
    $lead = $res['data'][0] ?? null;
    if (!$ok || !$lead) {
        widget_json_response(["success" => $ok, "lead" => $lead, "notified" => false, "debug" => $res]);
    }

    $walletCharge = widget_charge_mobile_otp_lead($customerId, $lead);
    if (empty($walletCharge['success'])) {
        widget_json_response([
            "success" => false,
            "requires_wallet_recharge" => true,
            "message" => $walletCharge['message'] ?? "Wallet could not be charged for mobile OTP verification"
        ], 402);
    }
    $meta = is_array($walletCharge['metadata'] ?? null) ? $walletCharge['metadata'] : widget_lead_metadata($lead);
    $verifyRes = supabase("PATCH", "lead_generation_leads?id=eq." . urlencode((string)($lead['id'] ?? '')) . "&customer_id=eq." . urlencode($customerId), [
        "mobile_otp_verified" => true,
        "metadata" => (object)$meta
    ]);
    $ok = ($verifyRes['status'] >= 200 && $verifyRes['status'] < 300);
    if ($ok && !empty($verifyRes['data'][0])) {
        $lead = $verifyRes['data'][0];
    }
    $notified = (!$suppressNotification && $ok && $lead) ? widget_notify_lead_by_email($customerId, $lead, 'mobile') : false;
    if ($ok && $lead) {
        widget_webhook_deliver($customerId, "lead.mobile_otp_verified", [
            "lead" => $lead
        ]);
    }
    widget_json_response(["success" => $ok, "lead" => $lead, "notified" => $notified, "debug" => $verifyRes]);
}

// Verify OTP for a lead email
if ($action === "verify_lead_email_otp") {
    $data = widget_get_json();
    $customerId = widget_customer_id($data);
    $leadId = isset($data['lead_id']) ? (int)$data['lead_id'] : 0;
    $entered = trim((string)($data['otp'] ?? ''));
    $suppressNotification = widget_bool($data['suppress_notification'] ?? false);
    $notificationEvent = trim((string)($data['notification_event'] ?? 'email'));
    if (!$customerId || !$leadId || $entered === '') widget_json_response(["success" => false, "message" => "Missing data"], 400);

    $res = supabase("GET", "lead_generation_leads?select=*&id=eq." . $leadId . "&customer_id=eq." . urlencode($customerId) . "&limit=1");
    $row = $res['data'][0] ?? null;
    if (!$row) widget_json_response(["success" => false, "message" => "Lead not found"], 404);

    $meta = $row['metadata'] ?? [];
    $expected = isset($meta['email_otp']) ? (string)$meta['email_otp'] : '';
    $expires = isset($meta['email_otp_expires_at']) ? (string)$meta['email_otp_expires_at'] : '';
    $now = gmdate('Y-m-d\TH:i:s\Z');

    if ($expected === '' || $entered !== $expected) {
        widget_json_response(["success" => false, "message" => "Invalid OTP"], 400);
    }
    if ($expires && $now > $expires) {
        widget_json_response(["success" => false, "message" => "OTP expired"], 400);
    }

    $walletCharge = widget_charge_email_otp_lead($customerId, $row);
    if (empty($walletCharge['success'])) {
        widget_json_response([
            "success" => false,
            "requires_wallet_recharge" => true,
            "message" => $walletCharge['message'] ?? "Wallet could not be charged for email OTP verification"
        ], 402);
    }

    // Mark verified
    $meta = is_array($walletCharge['metadata'] ?? null) ? $walletCharge['metadata'] : (is_array($row['metadata'] ?? null) ? $row['metadata'] : []);
    $update = [
        "email_otp_verified" => true,
        "verification_quality" => 'real',
        "metadata" => (object)array_filter($meta, function($k){ return $k !== 'email_otp' && $k !== 'email_otp_expires_at'; }, ARRAY_FILTER_USE_KEY)
    ];

    $up = supabase("PATCH", "lead_generation_leads?id=eq." . $leadId, $update);
    $ok = ($up['status'] >= 200 && $up['status'] < 300);

    $notified = (!$suppressNotification && $ok) ? widget_notify_lead_by_email($customerId, array_merge($row, $update), $notificationEvent) : false;
    if ($ok) {
        widget_webhook_deliver($customerId, "lead.email_otp_verified", [
            "lead" => array_merge($row, $update)
        ]);
    }

    widget_json_response(["success" => $ok, "notified" => $notified]);
}

if ($action === "search_faqs") {
    $customerId = widget_customer_id();
    $q = trim((string)($_GET['q'] ?? ''));
    if (!$customerId) {
        widget_json_response(["success" => false, "message" => "Missing customer_id"], 400);
    }

    $query = "faq_questions?select=id,question&customer_id=eq." . urlencode($customerId);
    if ($q !== '') {
        $query .= "&question=ilike.*" . urlencode($q) . "*";
    }
    $query .= widget_faq_active_query_suffix($customerId);

    $res = supabase("GET", $query);
    widget_json_response(["success" => true, "data" => widget_safe_rows($res)]);
}

if ($action === "track_faq_usage") {
    $data = widget_get_json();
    $customerId = widget_customer_id($data);
    $questionId = (int)($data['question_id'] ?? 0);
    $userId = trim((string)($data['user_id'] ?? ''));

    if (!$customerId || !$questionId || !$userId) {
        widget_json_response(["success" => false, "message" => "Missing tracking data"], 400);
    }

    $check = supabase(
        "GET",
        "faq_usage?select=id&customer_id=eq." . urlencode($customerId) . "&question_id=eq." . $questionId . "&user_id=eq." . urlencode($userId) . "&limit=1"
    );

    if (!empty($check['data'])) {
        widget_json_response(["success" => true, "status" => "already_tracked"]);
    }

    $res = supabase("POST", "faq_usage", [[
        "customer_id" => $customerId,
        "question_id" => $questionId,
        "user_id" => $userId
    ]]);
    if ($res['status'] >= 200 && $res['status'] < 300) {
        widget_webhook_deliver($customerId, "faq.selected", [
            "faq_id" => $questionId,
            "user_id" => $userId
        ]);
    }

    widget_json_response([
        "success" => ($res['status'] >= 200 && $res['status'] < 300),
        "status" => "tracked"
    ]);
}

widget_json_response([
    "success" => false,
    "message" => "Invalid action"
], 404);
