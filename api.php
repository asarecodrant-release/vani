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

function chatbot_access_result(array $settings, array $signup, string $sourceUrl): array {
    $host = host_from_value($sourceUrl);
    $websiteVerificationEnabled = filter_var($settings['website_verification_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $allowedDomainsEnabled = filter_var($settings['allowed_domains_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

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

function billing_account_for_email(string $email): array {
    $rows = safe_rows(supabase(
        "GET",
        "billing_accounts?select=*&email=eq." . urlencode($email) . "&limit=1"
    ));
    if (!empty($rows[0])) {
        return $rows[0];
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

function billing_active_plan_for_email(string $email): string {
    return billing_active_plan_from_account(billing_account_for_email($email));
}

function billing_email_for_customer(string $customerId): string {
    $rows = safe_rows(supabase(
        "GET",
        "chatbot_signups?select=email&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    return trim((string)($rows[0]['email'] ?? ''));
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

function wallet_credit_subscription(string $email, string $customerId, string $planId, int $amountPaise, string $paymentId): void {
    $account = billing_account_for_email($email);
    $balance = (int)($account['wallet_balance_paise'] ?? 0) + $amountPaise;
    $periodStart = gmdate('Y-m-d\TH:i:s\Z');
    $periodEnd = gmdate('Y-m-d\TH:i:s\Z', strtotime('+30 days'));
    supabase("PATCH", "billing_accounts?email=eq." . urlencode($email), [
        "wallet_balance_paise" => $balance,
        "current_plan" => $planId,
        "subscription_status" => "active",
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

// ==========================
// ==========================

if ($action === "billing_plans") {
    if (!is_authenticated_user()) {
        echo json_encode(["success" => false, "message" => "Login required"]);
        exit;
    }
    $email = authenticated_email();
    $account = billing_account_for_email($email);
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

if ($action === "create_razorpay_order") {
    if (!is_authenticated_user()) {
        echo json_encode(["success" => false, "message" => "Login required"]);
        exit;
    }
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

    $res = supabase("POST", "chatbot_signups", [[
        "customer_id" => $data['customer_id'],
        "website_name" => $data['website_name'],
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
    $billingEmail = is_authenticated_user() ? authenticated_email() : billing_email_for_customer($customer_id);
    $activePlan = $billingEmail ? billing_active_plan_for_email($billingEmail) : 'free';
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
    $billingEmail = is_authenticated_user() ? authenticated_email() : billing_email_for_customer($customer_id);
    $activePlan = $billingEmail ? billing_active_plan_for_email($billingEmail) : 'free';

    if ($verify_email_otp && !billing_feature_enabled($activePlan, 'email_otp')) {
        echo json_encode(["success" => false, "requires_premium" => true, "message" => "Email OTP requires a premium plan."]);
        exit;
    }

    if ($verify_mobile_otp && !billing_feature_enabled($activePlan, 'mobile_otp')) {
        echo json_encode(["success" => false, "requires_premium" => true, "message" => "Mobile OTP requires Growth plan or higher."]);
        exit;
    }

    if ($redirect_whatsapp && !billing_feature_enabled($activePlan, 'whatsapp_redirect')) {
        echo json_encode(["success" => false, "requires_premium" => true, "message" => "WhatsApp Redirect requires Growth plan or higher."]);
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

    $payload = [
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
    ];

    $existing = supabase(
        "GET",
        "lead_generation_settings?select=id&customer_id=eq." . urlencode($customer_id) . "&limit=1"
    );

    if (!empty($existing['data'])) {
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

    echo json_encode([
        "success" => ($res['status'] >= 200 && $res['status'] < 300),
        "message" => ($res['status'] >= 200 && $res['status'] < 300) ? "Lead generation settings saved" : "Lead generation settings could not be saved",
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
    $access = chatbot_access_result($settingsRow, $signup['data'][0] ?? [], request_source_url($data));
    if (!$access['allowed']) {
        echo json_encode(["reply" => $access['message'] ?: "This chatbot is not enabled for this website.", "status" => "blocked"]);
        exit;
    }

    $faqs = supabase(
        "GET",
        "faq_questions?customer_id=eq." . trim($customer_id)
    );

    $faqs = $faqs['data'] ?? [];

    $input = strtolower(trim($message));
    $reply = null;
    $matchedQuestionId = null;

    foreach ($faqs as $faq) {

        $q = strtolower(trim($faq['question'] ?? ''));

        if (!$q) continue;

        similar_text($input, $q, $percent);

        if (
            strpos($input, $q) !== false ||
            strpos($q, $input) !== false ||
            $percent > 55
        ) {
            $reply = $faq['answer'];
            $matchedQuestionId = $faq['id'] ?? null;
            break;
        }
    }

    if (!$reply) {
        $reply = "Sorry, I don't have an answer for that yet. Please contact customer support for help.";
    }

    supabase(
        "POST",
        "chatbot_conversations",
        [[
            "customer_id" => trim($customer_id),
            "user_question" => $message,
            "bot_response" => $reply,
            "matched_faq_id" => $matchedQuestionId,
            "status" => $matchedQuestionId ? "answered" : "unanswered",
            "is_answered" => (bool)$matchedQuestionId,
            "source_url" => request_source_url($data)
        ]]
    );

    echo json_encode(["reply" => $reply]);
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

    $allowed = [
        "bot_name",
        "welcome_message",
        "theme_color",
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
            "faq_questions?select=id,question&customer_id=eq." . trim($customer_id) . "&limit=5"
        );
        echo json_encode(["data" => $res['data'] ?? []]);
        exit;
    }

    $idList = implode(",", $topIds);

    $res = supabase(
        "GET",
        "faq_questions?select=id,question"
        . "&customer_id=eq." . trim($customer_id)
        . "&id=in.(" . $idList . ")"
    );

    $questions = $res['data'] ?? [];

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
        . "&customer_id=eq." . trim($customer_id);

    if (!empty($q)) {
        $query .= "&question=ilike.*" . urlencode($q) . "*";
    }

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
    $website_name = trim($data['website_name'] ?? '');
    $business_type = trim($data['business_type'] ?? '');

    if (!$customer_id || !$email) {

        echo json_encode([
            "success" => false,
            "message" => "Missing customer_id or email"
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
