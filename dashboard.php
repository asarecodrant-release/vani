<?php
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/billing.php';

if (!is_authenticated_user()) {
    header("Location: login.php");
    exit;
}

if (!empty($_SESSION['must_reset_password'])) {
    header("Location: forgot-password.php?forced=1");
    exit;
}

$email = strtolower(trim(authenticated_email()));
$accountId = authenticated_user_id();
$selectedBotId = trim($_GET['bot'] ?? '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$widgetUrl = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/embed.js';
$botImages = glob(__DIR__ . '/images/botimg_*') ?: [];
$botImages = array_values(array_filter($botImages, 'is_file'));
natcasesort($botImages);
$botImages = array_map(fn($path) => 'images/' . basename($path), $botImages);
$brandLogoDataUri = '';
foreach ([__DIR__ . '/images/logo_img.png', __DIR__ . '/images/logo.png'] as $logoPath) {
    if (is_readable($logoPath)) {
        $extension = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = $extension === 'svg' ? 'image/svg+xml' : ($extension === 'webp' ? 'image/webp' : ($extension === 'jpg' || $extension === 'jpeg' ? 'image/jpeg' : 'image/png'));
        $brandLogoDataUri = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($logoPath));
        break;
    }
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dashboard_parse_utc_datetime($value): ?DateTimeImmutable {
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }
    try {
        $hasTimezone = (bool)preg_match('/(?:z|[+-]\d{2}:?\d{2})$/i', $raw);
        $date = $hasTimezone
            ? new DateTimeImmutable($raw)
            : new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable $error) {
        return null;
    }
}

function dashboard_billing_date_fallback($value): string {
    $date = dashboard_parse_utc_datetime($value);
    return $date ? $date->format('M j, Y, g:i A') . ' UTC' : 'Time not recorded';
}

function dashboard_billing_reference_display(array $txn): array {
    $referenceType = trim((string)($txn['reference_type'] ?? ''));
    $referenceId = trim((string)($txn['reference_id'] ?? ''));
    $labels = [
        'razorpay_payment' => 'Subscription payment',
        'razorpay_auto_recharge' => 'Automatic wallet recharge',
        'razorpay_mandate_authorization' => 'Automatic payment authorization',
        'lead_email_otp' => 'Email OTP lead verification',
        'lead_mobile_otp' => 'Mobile OTP lead verification',
        'whatsapp_redirect_addon' => 'WhatsApp Redirect renewal',
        'whatsapp_redirect_addon_failed' => 'WhatsApp Redirect renewal skipped'
    ];
    $label = $labels[$referenceType] ?? ($referenceType !== '' ? ucwords(str_replace('_', ' ', $referenceType)) : 'Billing transaction');
    $detailLabel = 'Reference ID';
    if (strpos($referenceType, 'razorpay_') === 0) {
        $detailLabel = 'Payment ID';
    } elseif (strpos($referenceType, 'lead_') === 0) {
        $detailLabel = 'Lead ID';
    } elseif (strpos($referenceType, 'whatsapp_redirect') === 0) {
        $detailLabel = 'Customer ID';
    }
    return [
        'label' => $label,
        'detail' => $referenceId !== '' ? $detailLabel . ': ' . $referenceId : ''
    ];
}

function dashboard_billing_plan_help_text(string $planId, array $plan, int $autoRechargeThresholdPaise, int $autoRechargeAmountPaise): string {
    $planName = (string)($plan['name'] ?? 'Free');
    $pricePaise = (int)($plan['price_paise'] ?? 0);
    if ($planId === 'free' || $pricePaise <= 0) {
        return 'Free plan: paid wallet deductions are not active. Recharge the wallet with Starter, Growth, or Business to unlock paid lead verification, WhatsApp Redirect, analytics, and higher FAQ limits.';
    }
    $emailFresh = billing_rupees(billing_wallet_charge_paise($planId, 'fresh_email_lead'));
    $emailRepeat = billing_rupees(billing_wallet_charge_paise($planId, 'repeat_email_lead'));
    $mobileFresh = billing_rupees(billing_wallet_charge_paise($planId, 'fresh_mobile_lead'));
    $mobileRepeat = billing_rupees(billing_wallet_charge_paise($planId, 'repeat_mobile_lead'));
    $whatsapp = billing_rupees(billing_wallet_charge_paise($planId, 'whatsapp_redirect_addon'));
    return $planName . ' plan: minimum wallet recharge ' . billing_rupees($pricePaise) . ' unlocks the plan benefits and is credited to the wallet. Usage then deducts from wallet: fresh Email OTP lead ' . $emailFresh . ', repeat Email OTP verification ' . $emailRepeat . ', fresh Mobile OTP lead ' . $mobileFresh . ', repeat Mobile OTP verification ' . $mobileRepeat . ', and WhatsApp Redirect ' . $whatsapp . ' for 30 days while enabled. Auto recharge rule: when wallet goes below ' . billing_rupees($autoRechargeThresholdPaise) . ', recharge ' . billing_rupees($autoRechargeAmountPaise) . ' automatically if auto payment is authorized.';
}

function js_json($value): string {
    $json = json_encode(
        $value,
        JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
    return $json === false ? '{}' : $json;
}

function safe_data(array $response): array {
    $data = $response['data'] ?? null;
    if (!is_array($data)) {
        return [];
    }
    if ($data === []) {
        return [];
    }
    if (array_keys($data) !== range(0, count($data) - 1)) {
        return [];
    }
    return $data;
}

function first_value(array $row, array $keys, string $fallback = ''): string {
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return (string)$row[$key];
        }
    }
    return $fallback;
}

function dashboard_theme_color_input_value(string $value): string {
    return preg_match('/^#[0-9a-f]{6}$/i', trim($value)) ? trim($value) : '#6366f1';
}

function dashboard_disable_paid_service_toggles(array $bots, string $reason): void {
    foreach ($bots as $bot) {
        $customerId = trim((string)($bot['customer_id'] ?? ''));
        if ($customerId === '') {
            continue;
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
            "live_chat_actions_enabled" => false,
            "faq_actions_enabled" => false,
            "faq_category_menu_enabled" => false,
            "webhook_url" => null,
            "webhook_secret" => null
        ]);
    }
}

function dashboard_normalize_lead_phone(string $phone): string {
    return preg_replace('/\D+/', '', $phone) ?: '';
}

function dashboard_lead_period_count(array $rows, int $days): int {
    $cutoff = strtotime('-' . max(1, $days - 1) . ' days', strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC'));
    $seen = [];
    foreach ($rows as $row) {
        $createdAt = strtotime((string)($row['created_at'] ?? '')) ?: 0;
        if ($createdAt < $cutoff) {
            continue;
        }
        $email = strtolower(trim((string)($row['email'] ?? '')));
        $phone = dashboard_normalize_lead_phone((string)($row['phone_number'] ?? ''));
        $key = $email !== '' ? 'email:' . $email : ($phone !== '' ? 'phone:' . $phone : 'lead:' . (string)($row['id'] ?? spl_object_id((object)$row)));
        $seen[$key] = true;
    }
    return count($seen);
}

function dashboard_billing_account_has_value(array $account): bool {
    return (string)($account['current_plan'] ?? 'free') !== 'free'
        || (string)($account['subscription_status'] ?? 'free') !== 'free'
        || (int)($account['wallet_balance_paise'] ?? 0) > 0
        || trim((string)($account['saved_payment_method_reference'] ?? '')) !== ''
        || trim((string)($account['saved_payment_method_customer_id'] ?? '')) !== '';
}

function dashboard_legacy_owner_customer_id(string $email): string {
    if ($email === '') {
        return '';
    }
    $orders = safe_data(supabase(
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

function dashboard_customer_has_paid_order(string $email, string $customerId): bool {
    if ($email === '' || $customerId === '') {
        return false;
    }
    return !empty(safe_data(supabase(
        "GET",
        "billing_orders?select=id&email=eq." . urlencode($email) . "&customer_id=eq." . urlencode($customerId) . "&status=eq.paid&limit=1"
    )));
}

function dashboard_email_has_assigned_paid_account(string $email, string $exceptCustomerId = ''): bool {
    if ($email === '') {
        return false;
    }
    $rows = safe_data(supabase(
        "GET",
        "billing_accounts?select=*&email=eq." . urlencode($email) . "&customer_id=not.is.null&limit=20"
    ));
    foreach ($rows as $row) {
        $rowCustomerId = trim((string)($row['customer_id'] ?? ''));
        if ($rowCustomerId !== $exceptCustomerId && dashboard_billing_account_has_value($row)) {
            return true;
        }
    }
    return false;
}

function dashboard_email_bot_count(string $email): int {
    if ($email === '') {
        return 0;
    }
    return count(safe_data(supabase(
        "GET",
        "chatbot_signups?select=customer_id&email=eq." . urlencode($email) . "&limit=2"
    )));
}

function dashboard_adopt_legacy_billing_account(string $customerId, string $email, array $customerAccount = []): array {
    if ($customerId === '' || $email === '') {
        return $customerAccount;
    }
    $legacyOwnerCustomerId = dashboard_legacy_owner_customer_id($email);
    if ($legacyOwnerCustomerId !== '' && $legacyOwnerCustomerId !== $customerId) {
        return $customerAccount;
    }
    if ($legacyOwnerCustomerId === '' && dashboard_email_has_assigned_paid_account($email, $customerId)) {
        return $customerAccount;
    }
    if ($legacyOwnerCustomerId === '' && dashboard_email_bot_count($email) > 1) {
        return $customerAccount;
    }
    $legacyRows = safe_data(supabase(
        "GET",
        "billing_accounts?select=*&email=eq." . urlencode($email) . "&customer_id=is.null&order=created_at.desc&limit=5"
    ));
    $legacy = [];
    foreach ($legacyRows as $row) {
        if (dashboard_billing_account_has_value($row)) {
            $legacy = $row;
            break;
        }
    }
    if (empty($legacy)) {
        return $customerAccount;
    }
    if (empty($customerAccount)) {
        $claim = supabase("PATCH", "billing_accounts?id=eq." . urlencode((string)$legacy['id']), [
            "customer_id" => $customerId
        ]);
        if ($claim['status'] >= 200 && $claim['status'] < 300 && !empty($claim['data'][0])) {
            $customerAccount = $claim['data'][0];
        }
    } elseif (!dashboard_billing_account_has_value($customerAccount)) {
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
    supabase("PATCH", "wallet_transactions?email=eq." . urlencode($email) . "&customer_id=is.null", [
        "customer_id" => $customerId
    ]);
    supabase("PATCH", "billing_orders?email=eq." . urlencode($email) . "&customer_id=is.null", [
        "customer_id" => $customerId
    ]);
    return $customerAccount;
}

function dashboard_free_billing_snapshot(string $customerId, string $email): array {
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

function dashboard_repair_misassigned_billing_account(string $customerId, string $email, array $account): array {
    if ($customerId === '' || $email === '' || !dashboard_billing_account_has_value($account)) {
        return $account;
    }
    $ownerCustomerId = dashboard_legacy_owner_customer_id($email);
    if ($ownerCustomerId === '' || $ownerCustomerId === $customerId) {
        return $account;
    }
    if (dashboard_customer_has_paid_order($email, $customerId)) {
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
    $ownerRows = safe_data(supabase("GET", "billing_accounts?select=*&customer_id=eq." . urlencode($ownerCustomerId) . "&limit=1"));
    if (empty($ownerRows[0])) {
        supabase("POST", "billing_accounts", [$payload]);
    } elseif (!dashboard_billing_account_has_value($ownerRows[0])) {
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
    dashboard_disable_paid_service_toggles([["customer_id" => $customerId]], 'subscription_owner_mismatch');
    return dashboard_free_billing_snapshot($customerId, $email);
}

function date_in_range(array $row, string $field, string $from, string $to): bool {
    $date = substr((string)($row[$field] ?? ''), 0, 10);
    if ($date === '') {
        return false;
    }
    return $date >= $from && $date <= $to;
}

function analytics_summary_for_period(array $conversationRows, array $leadRows, array $sessionRows, string $from, string $to): array {
    $periodConversations = array_values(array_filter($conversationRows, fn($row) => date_in_range($row, 'created_at', $from, $to)));
    $periodLeads = array_values(array_filter($leadRows, fn($row) => date_in_range($row, 'created_at', $from, $to)));
    $periodSessions = array_values(array_filter($sessionRows, fn($row) => date_in_range($row, 'created_at', $from, $to)));
    $answered = 0;
    $responseTimes = [];
    $visitors = [];
    $messageTotal = 0;

    foreach ($periodConversations as $row) {
        if (filter_var($row['is_answered'] ?? false, FILTER_VALIDATE_BOOLEAN) || (string)($row['status'] ?? '') === 'answered') {
            $answered++;
        }
        if (isset($row['response_time_ms']) && is_numeric($row['response_time_ms'])) {
            $responseTimes[] = (int)$row['response_time_ms'];
        }
        $visitor = trim((string)($row['user_id'] ?? $row['session_id'] ?? ''));
        if ($visitor !== '') {
            $visitors[$visitor] = true;
        }
    }

    foreach ($periodSessions as $session) {
        $messageTotal += max(0, (int)($session['message_count'] ?? 0));
        $visitor = trim((string)($session['user_id'] ?? $session['session_id'] ?? ''));
        if ($visitor !== '') {
            $visitors[$visitor] = true;
        }
    }

    $conversationCount = count($periodConversations);
    $leadCount = count($periodLeads);
    $verifiedLeadCount = count(array_filter($periodLeads, function ($lead) {
        return filter_var($lead['email_otp_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var($lead['mobile_otp_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (string)($lead['verification_quality'] ?? '') === 'real';
    }));

    return [
        'conversations' => $conversationCount,
        'messages' => max($conversationCount, $messageTotal),
        'visitors' => count($visitors),
        'answer_rate' => $conversationCount > 0 ? round(($answered / max(1, $conversationCount)) * 100) : 0,
        'unanswered_rate' => $conversationCount > 0 ? round((($conversationCount - $answered) / max(1, $conversationCount)) * 100) : 0,
        'avg_response_time_ms' => !empty($responseTimes) ? round(array_sum($responseTimes) / count($responseTimes)) : 0,
        'leads' => $leadCount,
        'verified_leads' => $verifiedLeadCount,
        'lead_conversion' => $conversationCount > 0 ? round(($leadCount / max(1, $conversationCount)) * 100) : 0
    ];
}

function analytics_delta_html(float $current, float $previous, string $suffix = '', bool $lowerIsBetter = false): string {
    if ($previous <= 0 && $current <= 0) {
        return '<span class="metric-delta flat">No previous data</span>';
    }
    if ($previous <= 0) {
        return '<span class="metric-delta">+100%' . h($suffix) . ' vs previous</span>';
    }
    $change = round((($current - $previous) / max(1, $previous)) * 100);
    $isGood = $lowerIsBetter ? $change < 0 : $change > 0;
    $class = $change === 0 ? 'flat' : ($isGood ? 'good' : 'bad');
    $sign = $change > 0 ? '+' : '';
    return '<span class="metric-delta ' . h($class) . '">' . h($sign . $change . '%' . $suffix) . ' vs previous</span>';
}

function dashboard_feedback_is_positive(string $value): bool {
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return false;
    }
    if (preg_match('/([1-5])\s*stars?/', $normalized, $matches)) {
        return (int)$matches[1] >= 4;
    }
    if (preg_match('/satisfaction\s+(\d+)/', $normalized, $matches)) {
        return (int)$matches[1] >= 7;
    }
    return in_array($normalized, ['great', 'helpful', 'very happy', 'happy'], true);
}

function dashboard_feedback_display_value(string $value): string {
    $value = trim($value);
    return $value !== '' ? $value : 'No value';
}

function analytics_url(string $range, string $selectedBotId, string $from = '', string $to = ''): string {
    $params = ['analytics_range' => $range];
    if ($selectedBotId !== '') {
        $params['bot'] = $selectedBotId;
    }
    if ($range === 'custom') {
        if ($from !== '') {
            $params['date_from'] = $from;
        }
        if ($to !== '') {
            $params['date_to'] = $to;
        }
    }
    return 'dashboard.php?' . http_build_query($params) . '#analytics';
}

$allowedAnalyticsRanges = ['today', 'yesterday', '7_days', '30_days', 'custom'];
$analyticsRange = in_array($_GET['analytics_range'] ?? '', $allowedAnalyticsRanges, true)
    ? $_GET['analytics_range']
    : '30_days';
$analyticsToday = gmdate('Y-m-d');
$analyticsYesterday = gmdate('Y-m-d', time() - 86400);
$analyticsFrom = gmdate('Y-m-d', time() - (29 * 86400));
$analyticsTo = $analyticsToday;

if ($analyticsRange === 'today') {
    $analyticsFrom = $analyticsToday;
} elseif ($analyticsRange === 'yesterday') {
    $analyticsFrom = $analyticsYesterday;
    $analyticsTo = $analyticsYesterday;
} elseif ($analyticsRange === '7_days') {
    $analyticsFrom = gmdate('Y-m-d', time() - (6 * 86400));
} elseif ($analyticsRange === 'custom') {
    $customFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : $analyticsFrom;
    $customTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'] ?? '') ? $_GET['date_to'] : $analyticsToday;
    $analyticsFrom = min($customFrom, $customTo);
    $analyticsTo = max($customFrom, $customTo);
}

$billingFromInput = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['billing_from'] ?? '') ? (string)$_GET['billing_from'] : '';
$billingToInput = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['billing_to'] ?? '') ? (string)$_GET['billing_to'] : '';
if ($billingFromInput !== '' && $billingToInput !== '' && $billingFromInput > $billingToInput) {
    [$billingFromInput, $billingToInput] = [$billingToInput, $billingFromInput];
}
$billingFilterActive = $billingFromInput !== '' || $billingToInput !== '';
$billingRangeParts = [];
if ($billingFromInput !== '') {
    $billingRangeParts[] = 'From ' . $billingFromInput;
}
if ($billingToInput !== '') {
    $billingRangeParts[] = 'To ' . $billingToInput;
}
$billingRangeLabel = $billingFilterActive ? implode(' ', $billingRangeParts) : 'Showing latest wallet transactions';

$feedbackFromInput = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['feedback_from'] ?? '') ? (string)$_GET['feedback_from'] : '';
$feedbackToInput = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['feedback_to'] ?? '') ? (string)$_GET['feedback_to'] : '';
if ($feedbackFromInput !== '' && $feedbackToInput !== '' && $feedbackFromInput > $feedbackToInput) {
    [$feedbackFromInput, $feedbackToInput] = [$feedbackToInput, $feedbackFromInput];
}
$feedbackFilterActive = $feedbackFromInput !== '' || $feedbackToInput !== '';
$feedbackRangeParts = [];
if ($feedbackFromInput !== '') {
    $feedbackRangeParts[] = 'From ' . $feedbackFromInput;
}
if ($feedbackToInput !== '') {
    $feedbackRangeParts[] = 'To ' . $feedbackToInput;
}
$feedbackRangeLabel = $feedbackFilterActive ? implode(' ', $feedbackRangeParts) : 'Showing latest feedback';

$bots = safe_data(supabase(
    "GET",
    "chatbot_signups?select=*&email=eq." . urlencode($email) . "&order=created_at.desc"
));

if (empty($bots)) {
    header("Location: index.php?notice=select_product");
    exit;
}

if (!$selectedBotId && !empty($bots[0]['customer_id'])) {
    $selectedBotId = (string)$bots[0]['customer_id'];
}

$selectedBot = [];
foreach ($bots as $bot) {
    if (($bot['customer_id'] ?? '') === $selectedBotId) {
        $selectedBot = $bot;
        break;
    }
}

if (empty($selectedBot)) {
    $selectedBot = $bots[0] ?? [];
    $selectedBotId = (string)($selectedBot['customer_id'] ?? '');
}

$faqs = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "faq_questions?select=*&customer_id=eq." . urlencode($selectedBotId) . "&order=id.desc"
    ))
    : [];

$usageRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "faq_usage?select=*&customer_id=eq." . urlencode($selectedBotId)
    ))
    : [];

$conversationRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "chatbot_conversations?select=*&customer_id=eq." . urlencode($selectedBotId) . "&order=created_at.desc&limit=500"
    ))
    : [];

$sessionRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "chatbot_sessions?select=*&customer_id=eq." . urlencode($selectedBotId) . "&order=last_seen_at.desc&limit=500"
    ))
    : [];

$settingsRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "chatbot_settings?select=*&customer_id=eq." . urlencode($selectedBotId) . "&limit=1"
    ))
    : [];

$leadSettingsRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "lead_generation_settings?select=*&customer_id=eq." . urlencode($selectedBotId) . "&limit=1"
    ))
    : [];

$leadRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "lead_generation_leads?select=*&customer_id=eq." . urlencode($selectedBotId) . "&order=created_at.desc&limit=5000"
    ))
    : [];

$profileRows = safe_data(supabase(
    "GET",
    "customer_profiles?select=*&email=eq." . urlencode($email) . "&limit=1"
));
if (empty($profileRows)) {
    $profileCreate = supabase("POST", "customer_profiles", [[
        "email" => strtolower($email)
    ]]);
    if ($profileCreate['status'] >= 200 && $profileCreate['status'] < 300 && !empty($profileCreate['data'][0])) {
        $profileRows = [$profileCreate['data'][0]];
    } else {
        $profileRows = safe_data(supabase(
            "GET",
            "customer_profiles?select=*&email=eq." . urlencode($email) . "&limit=1"
        ));
    }
}

$billingAccountRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "billing_accounts?select=*&customer_id=eq." . urlencode($selectedBotId) . "&limit=1"
    ))
    : [];

if ($selectedBotId) {
    $existingBillingAccount = $billingAccountRows[0] ?? [];
    if (empty($existingBillingAccount) || !dashboard_billing_account_has_value($existingBillingAccount)) {
        $adoptedBillingAccount = dashboard_adopt_legacy_billing_account($selectedBotId, $email, $existingBillingAccount);
        if (!empty($adoptedBillingAccount)) {
            $billingAccountRows = [$adoptedBillingAccount];
        }
    }
    if (!empty($billingAccountRows[0])) {
        $billingAccountRows[0] = dashboard_repair_misassigned_billing_account($selectedBotId, $email, $billingAccountRows[0]);
    }
}

$walletTransactionQuery = $selectedBotId
    ? "wallet_transactions?select=*&customer_id=eq." . urlencode($selectedBotId)
    : '';
if ($walletTransactionQuery !== '') {
    if ($billingFromInput !== '') {
        $walletTransactionQuery .= "&created_at=gte." . urlencode($billingFromInput . "T00:00:00Z");
    }
    if ($billingToInput !== '') {
        $walletTransactionQuery .= "&created_at=lte." . urlencode($billingToInput . "T23:59:59Z");
    }
    $walletTransactionQuery .= "&order=created_at.desc&limit=" . ($billingFilterActive ? "1000" : "100");
}
$walletTransactionRows = $walletTransactionQuery !== ''
    ? safe_data(supabase("GET", $walletTransactionQuery))
    : [];

$selectedBusinessType = trim((string)($selectedBot['business_type'] ?? ''));
$themeSetupIncomplete = $selectedBotId !== '' && empty($settingsRows);
$faqSetupIncomplete = $selectedBotId !== '' && empty($faqs);
$chatbotSetupIncomplete = $themeSetupIncomplete || $faqSetupIncomplete;
$suggestedFaqRows = ($faqSetupIncomplete && $selectedBusinessType !== '' && strcasecmp($selectedBusinessType, 'Other') !== 0)
    ? safe_data(supabase(
        "GET",
        "pre_loaded_question?select=question,answer&category=eq." . urlencode($selectedBusinessType) . "&order=id.asc"
    ))
    : [];
if ($chatbotSetupIncomplete && $selectedBotId !== '') {
    $_SESSION['setup_email'] = $email;
    $_SESSION['setup_customer_id'] = $selectedBotId;
    $_SESSION['setup_website_name'] = (string)($selectedBot['website_name'] ?? '');
    $_SESSION['setup_business_type'] = $selectedBusinessType;
}

$apiKeyRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "customer_api_keys?select=*&customer_id=eq." . urlencode($selectedBotId) . "&order=created_at.desc"
    ))
    : [];

$apiUsageRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "customer_api_usage_logs?select=*&customer_id=eq." . urlencode($selectedBotId) . "&order=created_at.desc&limit=50"
    ))
    : [];

$faqActionRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "faq_action_suggestions?select=*&customer_id=eq." . urlencode($selectedBotId) . "&order=display_order.asc,created_at.desc"
    ))
    : [];
$faqActionById = [];
foreach ($faqActionRows as $actionRow) {
    $faqActionById[(string)($actionRow['id'] ?? '')] = $actionRow;
}

$paymentSettingsRows = $selectedBotId
    ? safe_data(supabase("GET", "customer_payment_settings?select=*&customer_id=eq." . urlencode($selectedBotId) . "&limit=1"))
    : [];
$paymentSettings = $paymentSettingsRows[0] ?? [];
$paymentActionRows = $selectedBotId
    ? safe_data(supabase("GET", "customer_payment_actions?select=*&customer_id=eq." . urlencode($selectedBotId) . "&order=created_at.desc"))
    : [];
$paymentTransactionRows = $selectedBotId
    ? safe_data(supabase("GET", "customer_payment_transactions?select=*&customer_id=eq." . urlencode($selectedBotId) . "&order=created_at.desc&limit=500"))
    : [];
$paymentActionById = [];
foreach ($paymentActionRows as $paymentActionRow) {
    $paymentActionById[(string)($paymentActionRow['id'] ?? '')] = $paymentActionRow;
}

$allFeedbackRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "faq_action_feedback?select=*&customer_id=eq." . urlencode($selectedBotId) . "&order=created_at.desc&limit=5000"
    ))
    : [];
$feedbackDisplayRows = array_values(array_filter($allFeedbackRows, function ($row) use ($feedbackFromInput, $feedbackToInput) {
    if ($feedbackFromInput === '' && $feedbackToInput === '') {
        return true;
    }
    $from = $feedbackFromInput !== '' ? $feedbackFromInput : '0000-01-01';
    $to = $feedbackToInput !== '' ? $feedbackToInput : '9999-12-31';
    return date_in_range($row, 'created_at', $from, $to);
}));

$scheduledFaqActionRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "faq_scheduled_action_suggestions?select=*&customer_id=eq." . urlencode($selectedBotId) . "&order=slot_no.asc"
    ))
    : [];
$scheduledFaqActionsBySlot = [];
foreach ($scheduledFaqActionRows as $row) {
    $scheduledFaqActionsBySlot[(int)($row['slot_no'] ?? 0)] = $row;
}

$todayAllQueries = count(array_filter($conversationRows, fn($row) => date_in_range($row, 'created_at', $analyticsToday, $analyticsToday)));
$yesterdayAllQueries = count(array_filter($conversationRows, fn($row) => date_in_range($row, 'created_at', $analyticsYesterday, $analyticsYesterday)));
$last7AllQueries = count(array_filter($conversationRows, fn($row) => date_in_range($row, 'created_at', gmdate('Y-m-d', time() - (6 * 86400)), $analyticsToday)));
$last30AllQueries = count(array_filter($conversationRows, fn($row) => date_in_range($row, 'created_at', gmdate('Y-m-d', time() - (29 * 86400)), $analyticsToday)));

$allConversationRows = $conversationRows;
$allSessionRows = $sessionRows;
$allLeadRows = $leadRows;
$analyticsRangeDays = max(1, (int)(((strtotime($analyticsTo) ?: time()) - (strtotime($analyticsFrom) ?: time())) / 86400) + 1);
$previousAnalyticsTo = gmdate('Y-m-d', strtotime($analyticsFrom . ' -1 day'));
$previousAnalyticsFrom = gmdate('Y-m-d', strtotime($previousAnalyticsTo . ' -' . ($analyticsRangeDays - 1) . ' days'));
$analyticsCurrentSummary = analytics_summary_for_period($allConversationRows, $allLeadRows, $allSessionRows, $analyticsFrom, $analyticsTo);
$analyticsPreviousSummary = analytics_summary_for_period($allConversationRows, $allLeadRows, $allSessionRows, $previousAnalyticsFrom, $previousAnalyticsTo);
$conversationRows = array_values(array_filter($conversationRows, fn($row) => date_in_range($row, 'created_at', $analyticsFrom, $analyticsTo)));
$usageRows = array_values(array_filter($usageRows, fn($row) => date_in_range($row, 'created_at', $analyticsFrom, $analyticsTo)));
$leadRows = array_values(array_filter($leadRows, fn($row) => date_in_range($row, 'created_at', $analyticsFrom, $analyticsTo)));
$sessionRows = array_values(array_filter($sessionRows, fn($row) => date_in_range($row, 'created_at', $analyticsFrom, $analyticsTo)));
$feedbackRows = array_values(array_filter($allFeedbackRows, fn($row) => date_in_range($row, 'created_at', $analyticsFrom, $analyticsTo)));

$settings = $settingsRows[0] ?? [];
$leadSettings = $leadSettingsRows[0] ?? [];
$profile = $profileRows[0] ?? [];
$billingAccount = $billingAccountRows[0] ?? [];
$billingStatus = (string)($billingAccount['subscription_status'] ?? 'free');
$billingPeriodEnd = (string)($billingAccount['current_period_end'] ?? '');
$billingWalletRaw = (int)($billingAccount['wallet_balance_paise'] ?? 0);
if (
    ($billingStatus === 'cancelled' && $billingWalletRaw <= 0) ||
    ($billingStatus === 'active' && $billingPeriodEnd !== '' && strtotime($billingPeriodEnd) < time())
) {
    $transitionReason = $billingStatus === 'cancelled' ? 'wallet_empty' : 'plan_expired';
    supabase("PATCH", "billing_accounts?customer_id=eq." . urlencode($selectedBotId), [
        "current_plan" => "free",
        "subscription_status" => "free",
        "auto_recharge_enabled" => false,
        "saved_payment_method_status" => "failed",
        "saved_payment_method_reference" => null
    ]);
    dashboard_disable_paid_service_toggles($selectedBot ? [$selectedBot] : [], $transitionReason);
    $billingAccountRows = safe_data(supabase("GET", "billing_accounts?select=*&customer_id=eq." . urlencode($selectedBotId) . "&limit=1"));
    $billingAccount = $billingAccountRows[0] ?? $billingAccount;
    if ($selectedBotId) {
        $settingsRows = safe_data(supabase("GET", "chatbot_settings?select=*&customer_id=eq." . urlencode($selectedBotId) . "&limit=1"));
        $leadSettingsRows = safe_data(supabase("GET", "lead_generation_settings?select=*&customer_id=eq." . urlencode($selectedBotId) . "&limit=1"));
        $settings = $settingsRows[0] ?? [];
        $leadSettings = $leadSettingsRows[0] ?? [];
    }
}
$activePlanId = billing_active_plan_from_account($billingAccount);
$activePlan = billing_plan($activePlanId);
$billingWalletPaise = (int)($billingAccount['wallet_balance_paise'] ?? 0);
$subscriptionStatus = (string)($billingAccount['subscription_status'] ?? 'free');
$isCancelledWalletAccess = $subscriptionStatus === 'cancelled' && $activePlanId !== 'free' && $billingWalletPaise > 0;
$planFaqLimit = billing_faq_limit($activePlanId);
$canUseAdvancedAnalytics = billing_feature_enabled($activePlanId, 'advanced_analytics');
$canUsePartialAnalytics = billing_feature_enabled($activePlanId, 'partial_analytics') || $canUseAdvancedAnalytics;
$canExportReports = billing_feature_enabled($activePlanId, 'export_reports');
$canUseEmailOtp = billing_feature_enabled($activePlanId, 'email_otp');
$canUseMobileOtp = billing_feature_enabled($activePlanId, 'mobile_otp');
$canUseWhatsappRedirect = billing_feature_enabled($activePlanId, 'whatsapp_redirect');
$canUseBusinessApi = billing_feature_enabled($activePlanId, 'api_access');
$canUseWebhook = billing_feature_enabled($activePlanId, 'webhook_support');
$canUseHumanHandoff = billing_feature_enabled($activePlanId, 'human_handoff');
$canUseAllowedDomains = billing_feature_enabled($activePlanId, 'allowed_domains');
$canUseLiveChatActions = billing_feature_enabled($activePlanId, 'live_chat_actions');
$canUseFaqActionSuggestions = billing_feature_enabled($activePlanId, 'faq_action_suggestions');
$canUseFaqFeedback = billing_feature_enabled($activePlanId, 'faq_feedback');
$canUsePaymentCollection = billing_feature_enabled($activePlanId, 'payment_collection');
$autoRechargeRule = billing_auto_recharge_rule($activePlanId);
$autoRechargeThresholdPaise = (int)($billingAccount['auto_recharge_threshold_paise'] ?? 0) ?: (int)$autoRechargeRule['threshold_paise'];
$autoRechargeAmountPaise = (int)($billingAccount['auto_recharge_amount_paise'] ?? 0) ?: (int)$autoRechargeRule['amount_paise'];
$autoRechargeEnabled = filter_var($billingAccount['auto_recharge_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$savedPaymentMethodStatus = (string)($billingAccount['saved_payment_method_status'] ?? 'missing');
$savedPaymentCustomerId = (string)($billingAccount['saved_payment_method_customer_id'] ?? '');
$savedPaymentContact = (string)($billingAccount['saved_payment_method_contact'] ?? '');
$savedPaymentMethodReference = (string)($billingAccount['saved_payment_method_reference'] ?? '');
$billingPlanHelpText = dashboard_billing_plan_help_text($activePlanId, $activePlan, $autoRechargeThresholdPaise, $autoRechargeAmountPaise);
$walletCreditPaise = array_sum(array_map(fn($row) => ($row['transaction_type'] ?? '') === 'credit' ? (int)($row['amount_paise'] ?? 0) : 0, $walletTransactionRows));
$walletDebitPaise = array_sum(array_map(fn($row) => ($row['transaction_type'] ?? '') === 'debit' ? (int)($row['amount_paise'] ?? 0) : 0, $walletTransactionRows));
$faqCount = count($faqs);
$freeFaqLimit = $planFaqLimit === PHP_INT_MAX ? 999999 : $planFaqLimit;
$displayFaqLimit = $planFaqLimit === PHP_INT_MAX ? $faqCount : $planFaqLimit;
$faqActiveIds = [];
foreach (array_slice(array_values(array_reverse($faqs)), 0, $displayFaqLimit) as $activeFaqRow) {
    $faqActiveIds[(string)($activeFaqRow['id'] ?? '')] = true;
}
$frozenFaqCount = $planFaqLimit === PHP_INT_MAX ? 0 : max(0, $faqCount - $planFaqLimit);
$faqFreezeActive = $frozenFaqCount > 0;
$conversationCount = count($conversationRows);
$today = gmdate('Y-m-d');
$todayQueries = 0;
$lastActivity = '';
$answeredCount = 0;
$unansweredCount = 0;
$dailyCounts = [];
$dailyAnsweredCounts = [];
$dailyUnansweredCounts = [];
$dailyLeadCounts = [];
$hourCounts = [];
$topQuestionCounts = [];
$faqById = [];
$topFaqQuestionCounts = [];
$outsideFaqQuestions = [];
$sourcePageStats = [];
$uniqueVisitors = [];
$returningVisitors = [];
$deviceCounts = [];
$browserCounts = [];
$countryCounts = [];
$cityCounts = [];
$locationPointRows = [];
$cityClusterRows = [];
$responseTimes = [];
$sessionDurations = [];
$sessionMessageTotal = 0;
$yesterdayQueries = 0;
$last7Queries = 0;
$last30Queries = 0;
$chatOpenedCount = count($conversationRows);
$nowTs = time();
$yesterday = gmdate('Y-m-d', $nowTs - 86400);
$last7Cutoff = gmdate('Y-m-d', $nowTs - (6 * 86400));
$last30Cutoff = gmdate('Y-m-d', $nowTs - (29 * 86400));

foreach ($faqs as $faq) {
    if (isset($faq['id'])) {
        $faqById[(string)$faq['id']] = (string)($faq['question'] ?? '');
    }
}

foreach ($conversationRows as $row) {
    $created = (string)($row['created_at'] ?? '');
    if ($created && substr($created, 0, 10) === $today) {
        $todayQueries++;
    }
    if ($created && substr($created, 0, 10) === $yesterday) {
        $yesterdayQueries++;
    }
    if ($created) {
        $createdDay = substr($created, 0, 10);
        if ($createdDay >= $last7Cutoff) {
            $last7Queries++;
        }
        if ($createdDay >= $last30Cutoff) {
            $last30Queries++;
        }
    }
    if (!$lastActivity || strcmp($created, $lastActivity) > 0) {
        $lastActivity = $created;
    }
    $status = strtolower((string)($row['status'] ?? ''));
    $answered = ($status === 'answered') || !empty($row['is_answered']);
    if ($answered) {
        $answeredCount++;
    } else {
        $unansweredCount++;
    }
    if ($created) {
        $day = substr($created, 0, 10);
        $hour = substr($created, 11, 2);
        $dailyCounts[$day] = ($dailyCounts[$day] ?? 0) + 1;
        if ($answered) {
            $dailyAnsweredCounts[$day] = ($dailyAnsweredCounts[$day] ?? 0) + 1;
        } else {
            $dailyUnansweredCounts[$day] = ($dailyUnansweredCounts[$day] ?? 0) + 1;
        }
        if ($hour !== '') {
            $hourCounts[$hour] = ($hourCounts[$hour] ?? 0) + 1;
        }
    }
    $question = trim((string)($row['user_question'] ?? $row['question'] ?? ''));
    $sourceUrl = trim((string)($row['source_url'] ?? ''));
    $sourceLabel = $sourceUrl !== '' ? parse_url($sourceUrl, PHP_URL_PATH) : '';
    $sourceLabel = $sourceLabel ?: ($sourceUrl ?: 'Unknown page');
    if (!isset($sourcePageStats[$sourceLabel])) {
        $sourcePageStats[$sourceLabel] = [
            'page' => $sourceLabel,
            'conversations' => 0,
            'leads' => 0,
            'answered' => 0
        ];
    }
    $sourcePageStats[$sourceLabel]['conversations']++;
    if ($answered) {
        $sourcePageStats[$sourceLabel]['answered']++;
    }
    $visitorId = trim((string)($row['user_id'] ?? ''));
    if ($visitorId !== '') {
        $uniqueVisitors[$visitorId] = true;
        $returningVisitors[$visitorId] = ($returningVisitors[$visitorId] ?? 0) + 1;
    }
    $device = first_value($row, ['device_type'], '');
    if ($device !== '') {
        $deviceCounts[$device] = ($deviceCounts[$device] ?? 0) + 1;
    }
    $browser = first_value($row, ['browser_name'], '');
    if ($browser !== '') {
        $browserCounts[$browser] = ($browserCounts[$browser] ?? 0) + 1;
    }
    $country = first_value($row, ['country_name', 'country_code'], '');
    if ($country !== '') {
        $countryCounts[$country] = ($countryCounts[$country] ?? 0) + 1;
    }
    $city = first_value($row, ['city'], '');
    if ($city !== '') {
        $cityCounts[$city] = ($cityCounts[$city] ?? 0) + 1;
    }
    $responseTime = (int)($row['response_time_ms'] ?? 0);
    if ($responseTime > 0) {
        $responseTimes[] = $responseTime;
    }
    if ($question !== '') {
        $key = strtolower($question);
        $topQuestionCounts[$key] = [
            'question' => $question,
            'count' => ($topQuestionCounts[$key]['count'] ?? 0) + 1,
            'answered' => ($topQuestionCounts[$key]['answered'] ?? 0) + ($answered ? 1 : 0)
        ];
        if (!$answered) {
            $outsideFaqQuestions[] = [
                'question' => $question,
                'source_page' => $sourceLabel,
                'bot_response' => (string)($row['bot_response'] ?? $row['response'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? '')
            ];
        }
    }
    $matchedFaqId = (string)($row['matched_faq_id'] ?? $row['question_id'] ?? '');
    if ($matchedFaqId !== '' && isset($faqById[$matchedFaqId])) {
        $faqQuestion = $faqById[$matchedFaqId];
        $topFaqQuestionCounts[$matchedFaqId] = [
            'question' => $faqQuestion,
            'count' => ($topFaqQuestionCounts[$matchedFaqId]['count'] ?? 0)
        ];
    }
}

foreach ($leadRows as $lead) {
    $leadCreated = (string)($lead['created_at'] ?? '');
    if ($leadCreated !== '') {
        $leadDay = substr($leadCreated, 0, 10);
        $dailyLeadCounts[$leadDay] = ($dailyLeadCounts[$leadDay] ?? 0) + 1;
    }
    $sourceUrl = trim((string)($lead['source_url'] ?? ''));
    $sourceLabel = $sourceUrl !== '' ? parse_url($sourceUrl, PHP_URL_PATH) : '';
    $sourceLabel = $sourceLabel ?: ($sourceUrl ?: 'Unknown page');
    if (!isset($sourcePageStats[$sourceLabel])) {
        $sourcePageStats[$sourceLabel] = [
            'page' => $sourceLabel,
            'conversations' => 0,
            'leads' => 0,
            'answered' => 0
        ];
    }
    $sourcePageStats[$sourceLabel]['leads']++;

    $latitude = $lead['latitude'] ?? null;
    $longitude = $lead['longitude'] ?? null;
    if (is_numeric($latitude) && is_numeric($longitude)) {
        $lat = (float)$latitude;
        $lon = (float)$longitude;
        if ($lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180) {
            $leadCountry = first_value($lead, ['country_name', 'country_code'], '');
            $leadCity = first_value($lead, ['city', 'location_text'], '');
            $locationLabel = $leadCity !== '' ? $leadCity : ($leadCountry !== '' ? $leadCountry : 'Saved location');
            $locationPointRows[] = [
                'name' => $locationLabel,
                'city' => $leadCity,
                'country' => $leadCountry,
                'lat' => $lat,
                'lon' => $lon,
                'source_page' => $sourceLabel,
                'date' => substr($leadCreated, 0, 10)
            ];

            $clusterKey = strtolower(trim(($leadCountry ?: 'unknown') . '|' . ($leadCity ?: round($lat, 2) . ',' . round($lon, 2))));
            if (!isset($cityClusterRows[$clusterKey])) {
                $cityClusterRows[$clusterKey] = [
                    'name' => $locationLabel,
                    'city' => $leadCity,
                    'country' => $leadCountry,
                    'lat' => $lat,
                    'lon' => $lon,
                    'count' => 0
                ];
            }
            $cityClusterRows[$clusterKey]['count']++;
        }
    }
}

foreach ($sessionRows as $session) {
    $sessionId = trim((string)($session['session_id'] ?? ''));
    $visitorId = trim((string)($session['user_id'] ?? ''));
    if ($visitorId !== '') {
        $uniqueVisitors[$visitorId] = true;
    }
    $duration = (int)($session['duration_seconds'] ?? 0);
    if ($duration > 0) {
        $sessionDurations[] = $duration;
    }
    $sessionMessageTotal += max(0, (int)($session['message_count'] ?? 0));
    $device = first_value($session, ['device_type'], '');
    if ($device !== '') {
        $deviceCounts[$device] = ($deviceCounts[$device] ?? 0) + 1;
    }
    $browser = first_value($session, ['browser_name'], '');
    if ($browser !== '') {
        $browserCounts[$browser] = ($browserCounts[$browser] ?? 0) + 1;
    }
    $country = first_value($session, ['country_name', 'country_code'], '');
    if ($country !== '') {
        $countryCounts[$country] = ($countryCounts[$country] ?? 0) + 1;
    }
    $city = first_value($session, ['city'], '');
    if ($city !== '') {
        $cityCounts[$city] = ($cityCounts[$city] ?? 0) + 1;
    }
}

if (!$conversationCount && !empty($usageRows)) {
    $conversationCount = count($usageRows);
    $answeredCount = count($usageRows);
    foreach ($usageRows as $row) {
        $created = (string)($row['created_at'] ?? '');
        if ($created && substr($created, 0, 10) === $today) {
            $todayQueries++;
        }
        if ($created && (!$lastActivity || strcmp($created, $lastActivity) > 0)) {
            $lastActivity = $created;
        }
    }
}

if (!empty($usageRows)) {
    foreach ($usageRows as $row) {
        $questionId = (string)($row['question_id'] ?? '');
        if ($questionId !== '' && isset($faqById[$questionId])) {
            $topFaqQuestionCounts[$questionId] = [
                'question' => $faqById[$questionId],
                'count' => ($topFaqQuestionCounts[$questionId]['count'] ?? 0) + 1
            ];
        }
    }
}

$accuracy = $conversationCount > 0
    ? round(($answeredCount / max(1, $conversationCount)) * 100)
    : ($faqCount > 0 ? 100 : 0);

$unansweredPercent = $conversationCount > 0
    ? round(($unansweredCount / max(1, $conversationCount)) * 100)
    : 0;

uasort($topQuestionCounts, fn($a, $b) => $b['count'] <=> $a['count']);
uasort($topFaqQuestionCounts, fn($a, $b) => $b['count'] <=> $a['count']);
uasort($sourcePageStats, fn($a, $b) => $b['conversations'] <=> $a['conversations']);
arsort($deviceCounts);
arsort($browserCounts);
arsort($countryCounts);
arsort($cityCounts);
uasort($cityClusterRows, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
$dailyChartCounts = $dailyCounts;
ksort($dailyChartCounts);
$dailyAnsweredChartCounts = $dailyAnsweredCounts;
ksort($dailyAnsweredChartCounts);
$dailyUnansweredChartCounts = $dailyUnansweredCounts;
ksort($dailyUnansweredChartCounts);
$dailyLeadChartCounts = $dailyLeadCounts;
ksort($dailyLeadChartCounts);
$hourChartCounts = $hourCounts;
ksort($hourChartCounts);
arsort($hourCounts);
$peakUsage = !empty($hourCounts) ? array_key_first($hourCounts) . ":00" : "Not enough data";
$uniqueVisitorCount = count($uniqueVisitors);
$returningVisitorCount = count(array_filter($returningVisitors, fn($count) => $count > 1));
$returningUsersPercent = $uniqueVisitorCount > 0 ? round(($returningVisitorCount / max(1, $uniqueVisitorCount)) * 100) : 0;
$totalMessages = max($conversationCount, $sessionMessageTotal);
$leadCount = count($leadRows);
$verifiedLeadCount = 0;
$emailLeadCount = 0;
$phoneLeadCount = 0;
$mostActivePage = !empty($sourcePageStats) ? array_values($sourcePageStats)[0]['page'] : 'No data yet';
$activeChatbotCount = filter_var($settings['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

foreach ($leadRows as $lead) {
    $emailVerified = filter_var($lead['email_otp_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $mobileVerified = filter_var($lead['mobile_otp_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($emailVerified || $mobileVerified || (string)($lead['verification_quality'] ?? '') === 'real') {
        $verifiedLeadCount++;
    }
    if (!empty($lead['email'])) {
        $emailLeadCount++;
    }
    if (!empty($lead['phone_number'])) {
        $phoneLeadCount++;
    }
}

$leadPeriodStats = [
    ['label' => 'Weekly', 'days' => 7, 'count' => dashboard_lead_period_count($allLeadRows, 7)],
    ['label' => 'Monthly', 'days' => 30, 'count' => dashboard_lead_period_count($allLeadRows, 30)],
    ['label' => 'Quarterly', 'days' => 90, 'count' => dashboard_lead_period_count($allLeadRows, 90)],
    ['label' => 'Six months', 'days' => 182, 'count' => dashboard_lead_period_count($allLeadRows, 182)],
    ['label' => 'Yearly', 'days' => 365, 'count' => dashboard_lead_period_count($allLeadRows, 365)]
];

$uniqueLeadMap = [];
$leadEmailIndex = [];
$leadPhoneIndex = [];
foreach ($leadRows as $lead) {
    $email = strtolower(trim((string)($lead['email'] ?? '')));
    $phone = dashboard_normalize_lead_phone((string)($lead['phone_number'] ?? ''));
    $emailKey = $email !== '' && isset($leadEmailIndex[$email]) ? $leadEmailIndex[$email] : '';
    $phoneKey = $phone !== '' && isset($leadPhoneIndex[$phone]) ? $leadPhoneIndex[$phone] : '';
    if ($emailKey !== '' && $phoneKey !== '' && $emailKey !== $phoneKey && isset($uniqueLeadMap[$emailKey], $uniqueLeadMap[$phoneKey])) {
        $uniqueLeadMap[$emailKey]['email'] = $uniqueLeadMap[$emailKey]['email'] ?: $uniqueLeadMap[$phoneKey]['email'];
        $uniqueLeadMap[$emailKey]['phone_number'] = $uniqueLeadMap[$emailKey]['phone_number'] ?: $uniqueLeadMap[$phoneKey]['phone_number'];
        $uniqueLeadMap[$emailKey]['email_otp_count'] += (int)$uniqueLeadMap[$phoneKey]['email_otp_count'];
        $uniqueLeadMap[$emailKey]['mobile_otp_count'] += (int)$uniqueLeadMap[$phoneKey]['mobile_otp_count'];
        $uniqueLeadMap[$emailKey]['total_records'] += (int)$uniqueLeadMap[$phoneKey]['total_records'];
        $uniqueLeadMap[$emailKey]['whatsapp_redirect_count'] += (int)$uniqueLeadMap[$phoneKey]['whatsapp_redirect_count'];
        $uniqueLeadMap[$emailKey]['source_pages'] = array_replace($uniqueLeadMap[$emailKey]['source_pages'], $uniqueLeadMap[$phoneKey]['source_pages']);
        $uniqueLeadMap[$emailKey]['location'] = $uniqueLeadMap[$emailKey]['location'] ?: $uniqueLeadMap[$phoneKey]['location'];
        $uniqueLeadMap[$emailKey]['lead_type'] = ($uniqueLeadMap[$emailKey]['lead_type'] === 'Real' || $uniqueLeadMap[$phoneKey]['lead_type'] === 'Real') ? 'Real' : 'Weak';
        if ($uniqueLeadMap[$emailKey]['first_seen'] === '' || ($uniqueLeadMap[$phoneKey]['first_seen'] !== '' && strcmp($uniqueLeadMap[$phoneKey]['first_seen'], $uniqueLeadMap[$emailKey]['first_seen']) < 0)) {
            $uniqueLeadMap[$emailKey]['first_seen'] = $uniqueLeadMap[$phoneKey]['first_seen'];
        }
        if ($uniqueLeadMap[$emailKey]['last_seen'] === '' || ($uniqueLeadMap[$phoneKey]['last_seen'] !== '' && strcmp($uniqueLeadMap[$phoneKey]['last_seen'], $uniqueLeadMap[$emailKey]['last_seen']) > 0)) {
            $uniqueLeadMap[$emailKey]['last_seen'] = $uniqueLeadMap[$phoneKey]['last_seen'];
        }
        foreach ($leadPhoneIndex as $indexedPhone => $indexedKey) {
            if ($indexedKey === $phoneKey) {
                $leadPhoneIndex[$indexedPhone] = $emailKey;
            }
        }
        unset($uniqueLeadMap[$phoneKey]);
        $phoneKey = $emailKey;
    }
    $key = $emailKey !== '' ? $emailKey : $phoneKey;
    if ($key === '') {
        $key = $email !== '' ? 'email:' . $email : ($phone !== '' ? 'phone:' . $phone : 'lead:' . (string)($lead['id'] ?? count($uniqueLeadMap)));
        $uniqueLeadMap[$key] = [
            'lead_type' => 'Weak',
            'email' => '',
            'phone_number' => '',
            'email_otp_count' => 0,
            'mobile_otp_count' => 0,
            'total_records' => 0,
            'whatsapp_redirect_count' => 0,
            'source_pages' => [],
            'location' => '',
            'first_seen' => '',
            'last_seen' => ''
        ];
    }
    if ($email !== '') {
        $leadEmailIndex[$email] = $key;
        $uniqueLeadMap[$key]['email'] = $uniqueLeadMap[$key]['email'] ?: (string)($lead['email'] ?? '');
    }
    if ($phone !== '') {
        $leadPhoneIndex[$phone] = $key;
        $uniqueLeadMap[$key]['phone_number'] = $uniqueLeadMap[$key]['phone_number'] ?: (string)($lead['phone_number'] ?? '');
    }
    $emailVerified = filter_var($lead['email_otp_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $mobileVerified = filter_var($lead['mobile_otp_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($emailVerified) {
        $uniqueLeadMap[$key]['email_otp_count']++;
    }
    if ($mobileVerified) {
        $uniqueLeadMap[$key]['mobile_otp_count']++;
    }
    if ($emailVerified || $mobileVerified || (string)($lead['verification_quality'] ?? '') === 'real') {
        $uniqueLeadMap[$key]['lead_type'] = 'Real';
    }
    if (filter_var($lead['whatsapp_redirected'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $uniqueLeadMap[$key]['whatsapp_redirect_count']++;
    }
    $sourceUrl = trim((string)($lead['source_url'] ?? ''));
    $sourceLabel = $sourceUrl !== '' ? (parse_url($sourceUrl, PHP_URL_PATH) ?: $sourceUrl) : '';
    if ($sourceLabel !== '') {
        $uniqueLeadMap[$key]['source_pages'][$sourceLabel] = true;
    }
    $location = first_value($lead, ['location_text', 'city', 'country_name'], '');
    if ($location !== '' && $uniqueLeadMap[$key]['location'] === '') {
        $uniqueLeadMap[$key]['location'] = $location;
    }
    $created = (string)($lead['created_at'] ?? '');
    if ($created !== '') {
        if ($uniqueLeadMap[$key]['first_seen'] === '' || strcmp($created, $uniqueLeadMap[$key]['first_seen']) < 0) {
            $uniqueLeadMap[$key]['first_seen'] = $created;
        }
        if ($uniqueLeadMap[$key]['last_seen'] === '' || strcmp($created, $uniqueLeadMap[$key]['last_seen']) > 0) {
            $uniqueLeadMap[$key]['last_seen'] = $created;
        }
    }
    $uniqueLeadMap[$key]['total_records']++;
}
$uniqueLeadRows = array_values($uniqueLeadMap);
usort($uniqueLeadRows, fn($a, $b) => strcmp((string)($b['last_seen'] ?? ''), (string)($a['last_seen'] ?? '')));
$uniqueLeadCount = count($uniqueLeadRows);
$weakLeadCount = count(array_filter($uniqueLeadRows, fn($row) => ($row['lead_type'] ?? '') === 'Weak'));
$realUniqueLeadCount = $uniqueLeadCount - $weakLeadCount;

$feedbackCount = count($feedbackRows);
$feedbackDisplayCount = count($feedbackDisplayRows);
$feedbackPositiveCount = 0;
$feedbackUniqueUsers = [];
$feedbackValueCounts = [];
$feedbackActionTypeCounts = [];
$feedbackDailyCounts = [];
$recentFeedbackRows = array_slice($feedbackRows, 0, 25);
foreach ($feedbackRows as $feedbackRow) {
    $feedbackValue = dashboard_feedback_display_value((string)($feedbackRow['feedback_value'] ?? ''));
    $feedbackValueCounts[$feedbackValue] = ($feedbackValueCounts[$feedbackValue] ?? 0) + 1;
    if (dashboard_feedback_is_positive($feedbackValue)) {
        $feedbackPositiveCount++;
    }
    $feedbackUser = trim((string)($feedbackRow['user_id'] ?? $feedbackRow['session_id'] ?? ''));
    if ($feedbackUser !== '') {
        $feedbackUniqueUsers[$feedbackUser] = true;
    }
    $actionId = (string)($feedbackRow['action_id'] ?? '');
    $actionLabel = '';
    if ($actionId !== '' && isset($faqActionById[$actionId])) {
        $actionLabel = trim((string)($faqActionById[$actionId]['label'] ?? ''));
    }
    if ($actionLabel === '') {
        $actionLabel = trim((string)($feedbackRow['action_type'] ?? ''));
    }
    $actionLabel = $actionLabel !== '' ? $actionLabel : 'Unknown action';
    $feedbackActionTypeCounts[$actionLabel] = ($feedbackActionTypeCounts[$actionLabel] ?? 0) + 1;
    $created = (string)($feedbackRow['created_at'] ?? '');
    if ($created !== '') {
        $day = substr($created, 0, 10);
        $feedbackDailyCounts[$day] = ($feedbackDailyCounts[$day] ?? 0) + 1;
    }
}
arsort($feedbackValueCounts);
arsort($feedbackActionTypeCounts);
ksort($feedbackDailyCounts);
$feedbackPositiveRate = $feedbackCount > 0 ? round(($feedbackPositiveCount / max(1, $feedbackCount)) * 100) : 0;
$feedbackTopValue = !empty($feedbackValueCounts) ? array_key_first($feedbackValueCounts) : 'No feedback yet';

$leadConversionRate = $conversationCount > 0 ? round(($leadCount / max(1, $conversationCount)) * 100) : 0;
$otpVerifiedLeadPercent = $leadCount > 0 ? round(($verifiedLeadCount / max(1, $leadCount)) * 100) : 0;
$avgResponseTimeMs = !empty($responseTimes) ? round(array_sum($responseTimes) / count($responseTimes)) : 0;
$avgConversationDurationSeconds = !empty($sessionDurations) ? round(array_sum($sessionDurations) / count($sessionDurations)) : 0;
$avgConversationDuration = $avgConversationDurationSeconds > 0
    ? gmdate($avgConversationDurationSeconds >= 3600 ? 'H:i:s' : 'i:s', $avgConversationDurationSeconds)
    : 'No data yet';
$activeUsersNow = 0;
$now = time();
foreach ($sessionRows as $session) {
    $lastSeen = strtotime((string)($session['last_seen_at'] ?? '')) ?: 0;
    $endedAt = (string)($session['ended_at'] ?? '');
    if ($lastSeen && ($now - $lastSeen) <= 300 && $endedAt === '') {
        $activeUsersNow++;
    }
}
$sessionsOpened = count(array_filter($sessionRows, fn($session) => !empty($session['opened_at'])));
$sessionsStarted = count(array_filter($sessionRows, fn($session) => !empty($session['started_at']) || (int)($session['message_count'] ?? 0) > 0));
$chatOpenedCount = $sessionsOpened ?: $chatOpenedCount;
$bounceAfterOpenRate = $sessionsOpened > 0 ? round((($sessionsOpened - $sessionsStarted) / max(1, $sessionsOpened)) * 100) : 0;
$fallbackRate = $unansweredPercent;
$escalationRate = 0;
$handoffRate = 0;
$abandonmentRate = 0;
$satisfactionPercent = $accuracy;
$avgMessagesPerConversation = $sessionsStarted > 0
    ? round($sessionMessageTotal / max(1, $sessionsStarted), 1)
    : ($conversationCount > 0 ? round($totalMessages / max(1, $conversationCount), 1) : 0);
$chatOpenRate = $uniqueVisitorCount > 0 ? round(($sessionsOpened / max(1, $uniqueVisitorCount)) * 100) : 0;
$maxDailyCount = !empty($dailyChartCounts) ? max($dailyChartCounts) : 0;
$maxHourCount = !empty($hourChartCounts) ? max($hourChartCounts) : 0;
$themeColor = first_value($selectedBot, ['theme_color'], '#6366f1');
$themeColor = first_value($settings, ['theme_color'], $themeColor);
$themeColorInputValue = dashboard_theme_color_input_value($themeColor);
$themePattern = first_value($settings, ['theme_pattern'], 'none');
$chatbotImage = first_value($settings, ['avatar_url'], $botImages[0] ?? '');
$botName = first_value($settings, ['bot_name'], first_value($selectedBot, ['website_name'], 'Vani Bot'));
$welcomeMessage = first_value($settings, ['welcome_message'], 'Hi, how can I help you today?');
$position = first_value($settings, ['position'], 'right');
$language = first_value($settings, ['language'], 'English');
$rawActive = $settings['is_active'] ?? true;
$isActive = is_bool($rawActive) ? $rawActive : ((string)$rawActive !== 'false');
$chatOpenByDefault = filter_var($settings['chat_open_by_default'] ?? false, FILTER_VALIDATE_BOOLEAN);
$userInputEnabled = array_key_exists('user_input_enabled', $settings)
    ? filter_var($settings['user_input_enabled'], FILTER_VALIDATE_BOOLEAN)
    : true;
$websiteVerificationEnabled = filter_var($settings['website_verification_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$allowedDomainsEnabled = filter_var($settings['allowed_domains_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$allowedDomains = first_value($settings, ['allowed_domains'], '');
$handoffEnabled = filter_var($settings['handoff_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$handoffEmail = first_value($settings, ['handoff_email'], $email);
$liveChatActionsEnabled = filter_var($settings['live_chat_actions_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$faqActionsEnabled = filter_var($settings['faq_actions_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$faqCategoryMenuEnabled = filter_var($settings['faq_category_menu_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$faqFeedbackEnabled = filter_var($settings['faq_feedback_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$faqFeedbackType = (string)($settings['faq_feedback_type'] ?? 'labels');
if (!in_array($faqFeedbackType, ['stars', 'emoji', 'labels', 'slider', 'comment'], true)) {
    $faqFeedbackType = 'labels';
}
$faqFeedbackActionIds = $settings['faq_feedback_action_ids'] ?? [];
if (is_string($faqFeedbackActionIds)) {
    $decodedFeedbackActionIds = json_decode($faqFeedbackActionIds, true);
    $faqFeedbackActionIds = is_array($decodedFeedbackActionIds) ? $decodedFeedbackActionIds : [];
}
$faqFeedbackActionIds = array_values(array_filter(array_map('strval', is_array($faqFeedbackActionIds) ? $faqFeedbackActionIds : []), fn($id) => preg_match('/^\d+$/', $id)));
$faqFeedbackEmailEnabled = filter_var($settings['faq_feedback_email_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
if (!$canUseFaqFeedback) {
    $faqFeedbackEnabled = false;
    $faqFeedbackEmailEnabled = false;
    $faqFeedbackActionIds = [];
}
$verificationStatus = first_value($settings, ['verification_status'], 'Pending');
$faqById = [];
foreach ($faqs as $faq) {
    $faqById[(string)($faq['id'] ?? '')] = $faq;
}
$faqActionById = [];
foreach ($faqActionRows as $actionRow) {
    $faqActionById[(string)($actionRow['id'] ?? '')] = $actionRow;
}
$websiteName = first_value($selectedBot, ['website_name'], '');
$leadEnabled = filter_var($leadSettings['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadCollectLocation = filter_var($leadSettings['collect_location'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadCollectEmail = filter_var($leadSettings['collect_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadCollectMobile = filter_var($leadSettings['collect_mobile'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadVerifyEmailOtp = filter_var($leadSettings['verify_email_otp'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadNotifyByEmail = filter_var($leadSettings['notify_lead_by_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadRedirectWhatsapp = filter_var($leadSettings['redirect_whatsapp'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadVerifyMobileOtp = filter_var($leadSettings['verify_mobile_otp'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadNotificationEmail = first_value($leadSettings, ['notification_email'], $email);
$leadWhatsappNumber = first_value($leadSettings, ['whatsapp_mobile_number'], '');
$paymentsEnabled = filter_var($paymentSettings['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$paymentRazorpayEnabled = filter_var($paymentSettings['razorpay_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$paymentRazorpayTermsAccepted = filter_var($paymentSettings['razorpay_terms_accepted'] ?? false, FILTER_VALIDATE_BOOLEAN);
$paymentUpiEnabled = filter_var($paymentSettings['upi_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$paymentUpiTransactionIdRequired = filter_var($paymentSettings['upi_transaction_id_required'] ?? true, FILTER_VALIDATE_BOOLEAN);
$paymentUpiTermsAccepted = filter_var($paymentSettings['upi_terms_accepted'] ?? false, FILTER_VALIDATE_BOOLEAN);
$paymentCollectPayerEmail = filter_var($paymentSettings['collect_payer_email'] ?? true, FILTER_VALIDATE_BOOLEAN);
$paymentCollectPayerPhone = filter_var($paymentSettings['collect_payer_phone'] ?? true, FILTER_VALIDATE_BOOLEAN);
$paymentVerifyPayerEmailOtp = filter_var($paymentSettings['verify_payer_email_otp'] ?? false, FILTER_VALIDATE_BOOLEAN);
$paymentVerifyPayerPhoneOtp = filter_var($paymentSettings['verify_payer_phone_otp'] ?? false, FILTER_VALIDATE_BOOLEAN);
$paymentBusinessName = first_value($paymentSettings, ['business_name'], $websiteName ?: $botName);
$paymentRazorpayKeyId = first_value($paymentSettings, ['razorpay_key_id'], '');
$paymentRazorpaySecretSaved = trim((string)($paymentSettings['razorpay_key_secret'] ?? '')) !== '';
$paymentSuccessMessage = first_value($paymentSettings, ['success_message'], 'Payment received. Thank you.');
if (!$canUsePaymentCollection) {
    $paymentsEnabled = false;
    $paymentRazorpayEnabled = false;
    $paymentUpiEnabled = false;
}
$paymentPaidTotalPaise = array_sum(array_map(fn($row) => ($row['status'] ?? '') === 'paid' ? (int)($row['amount_paise'] ?? 0) : 0, $paymentTransactionRows));
$paymentPaidCount = count(array_filter($paymentTransactionRows, fn($row) => ($row['status'] ?? '') === 'paid'));
$paymentCreatedCount = count(array_filter($paymentTransactionRows, fn($row) => ($row['status'] ?? '') === 'created'));
$paymentFailedCount = count(array_filter($paymentTransactionRows, fn($row) => ($row['status'] ?? '') === 'failed'));
$paymentUpiPendingCount = count(array_filter($paymentTransactionRows, fn($row) => ($row['payment_method'] ?? '') === 'upi' && ($row['status'] ?? '') === 'created'));
$paymentAnalyticsRows = array_values(array_filter($paymentTransactionRows, fn($row) => date_in_range($row, 'created_at', $analyticsFrom, $analyticsTo)));
$paymentAnalyticsCount = count($paymentAnalyticsRows);
$paymentAnalyticsPaidCount = 0;
$paymentAnalyticsPendingCount = 0;
$paymentAnalyticsFailedCount = 0;
$paymentAnalyticsRevenuePaise = 0;
$paymentAnalyticsUniquePayers = [];
$paymentDailyCounts = [];
$paymentDailyRevenuePaise = [];
$paymentStatusCounts = [];
$paymentMethodCounts = [];
$paymentActionCounts = [];
foreach ($paymentAnalyticsRows as $paymentRow) {
    $status = strtolower((string)($paymentRow['status'] ?? 'created'));
    $method = strtoupper((string)($paymentRow['payment_method'] ?? 'razorpay'));
    $amountPaise = (int)($paymentRow['amount_paise'] ?? 0);
    $paymentStatusCounts[$status] = ($paymentStatusCounts[$status] ?? 0) + 1;
    $paymentMethodCounts[$method] = ($paymentMethodCounts[$method] ?? 0) + 1;
    if ($status === 'paid') {
        $paymentAnalyticsPaidCount++;
        $paymentAnalyticsRevenuePaise += $amountPaise;
    } elseif ($status === 'failed') {
        $paymentAnalyticsFailedCount++;
    } else {
        $paymentAnalyticsPendingCount++;
    }
    $payerKey = strtolower(trim((string)($paymentRow['payer_email'] ?? '')));
    if ($payerKey === '') {
        $payerKey = trim((string)($paymentRow['payer_phone'] ?? $paymentRow['user_id'] ?? $paymentRow['session_id'] ?? ''));
    }
    if ($payerKey !== '') {
        $paymentAnalyticsUniquePayers[$payerKey] = true;
    }
    $actionId = (string)($paymentRow['payment_action_id'] ?? '');
    $actionLabel = $actionId !== '' && isset($paymentActionById[$actionId])
        ? trim((string)($paymentActionById[$actionId]['label'] ?? ''))
        : '';
    $actionLabel = $actionLabel !== '' ? $actionLabel : 'Deleted payment button';
    $paymentActionCounts[$actionLabel] = ($paymentActionCounts[$actionLabel] ?? 0) + 1;
    $created = (string)($paymentRow['created_at'] ?? '');
    if ($created !== '') {
        $day = substr($created, 0, 10);
        $paymentDailyCounts[$day] = ($paymentDailyCounts[$day] ?? 0) + 1;
        if ($status === 'paid') {
            $paymentDailyRevenuePaise[$day] = ($paymentDailyRevenuePaise[$day] ?? 0) + $amountPaise;
        }
    }
}
ksort($paymentDailyCounts);
ksort($paymentDailyRevenuePaise);
arsort($paymentStatusCounts);
arsort($paymentMethodCounts);
arsort($paymentActionCounts);
$paymentAnalyticsConversionRate = $paymentAnalyticsCount > 0 ? round(($paymentAnalyticsPaidCount / max(1, $paymentAnalyticsCount)) * 100) : 0;
$paymentTopAction = !empty($paymentActionCounts) ? array_key_first($paymentActionCounts) : 'No payment data yet';
$recentPaymentAnalyticsRows = array_slice($paymentAnalyticsRows, 0, 50);
$nowTimestamp = time();
$todayInIndia = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$whatsappToggleDate = (string)($leadSettings['whatsapp_redirect_toggle_date'] ?? '');
$whatsappToggleCount = $whatsappToggleDate === $todayInIndia ? (int)($leadSettings['whatsapp_redirect_toggle_count'] ?? 0) : 0;
$whatsappLockedUntil = (string)($leadSettings['whatsapp_redirect_locked_until'] ?? '');
$whatsappLockedUntilTime = $whatsappLockedUntil !== '' ? (strtotime($whatsappLockedUntil) ?: 0) : 0;
$whatsappRedirectLocked = $whatsappLockedUntilTime > $nowTimestamp;
$whatsappRedirectLockedOn = $leadRedirectWhatsapp && $whatsappRedirectLocked;
$whatsappLockSecondsRemaining = $whatsappRedirectLocked ? max(0, $whatsappLockedUntilTime - $nowTimestamp) : 0;
$whatsappChargePaise = billing_wallet_charge_paise($activePlanId, 'whatsapp_redirect_addon');
$whatsappWalletCanEnable = $billingWalletPaise >= $whatsappChargePaise;
$whatsappStoppedReason = (string)($leadSettings['whatsapp_redirect_stopped_reason'] ?? '');
$whatsappFailedChargePaise = (int)($leadSettings['whatsapp_redirect_failed_charge_amount_paise'] ?? 0);
$embedCode = $selectedBotId ? '<script src="' . $widgetUrl . '" data-id="' . $selectedBotId . '"></script>' : '';
$profileFirstName = first_value($profile, ['first_name'], '');
$profileLastName = first_value($profile, ['last_name'], '');
$displayName = trim($profileFirstName . ' ' . $profileLastName);
$razorpayCustomerName = $displayName;
$profileNeedsSetup = trim($profileFirstName) === ''
    || trim((string)($profile['country_code'] ?? '')) === ''
    || trim((string)($profile['mobile_number'] ?? '')) === '';
$profilePromptKey = 'vani_profile_prompt_dismissed_' . substr(hash('sha256', strtolower($email)), 0, 16);
$profileMobileNumber = preg_replace('/\D+/', '', (string)($profile['mobile_number'] ?? ''));
$profileCountryCode = preg_replace('/[^\d+]/', '', (string)($profile['country_code'] ?? ''));
$profileContactValue = '';
if ($profileMobileNumber !== '') {
    $profileContactValue = $profileCountryCode . $profileMobileNumber;
    if ($profileContactValue !== '' && $profileContactValue[0] !== '+') {
        $profileContactValue = '+' . ltrim($profileContactValue, '+0');
    }
}
$defaultFaqContactText = trim(($profileContactValue !== '' ? 'phone ' . $profileContactValue : '') . ($profileContactValue !== '' && $handoffEmail !== '' ? ' or ' : '') . ($handoffEmail !== '' ? 'email ' . $handoffEmail : ''));
if ($defaultFaqContactText === '') {
    $defaultFaqContactText = 'your support team';
}
$defaultFaqDefinitions = [
    'fallback_contact' => [
        'question' => 'If chatbot failed to answer question',
        'answer' => 'I could not find the right answer for this question. Please contact ' . $defaultFaqContactText . ' and our team will help you.',
        'note' => 'Used automatically when no FAQ or enabled default FAQ matches the visitor question.'
    ],
    'contact_support' => [
        'question' => 'How can I contact support?',
        'answer' => 'You can contact our support team through ' . $defaultFaqContactText . '.',
        'note' => 'Answers direct support-contact questions.'
    ],
    'business_hours' => [
        'question' => 'What are your business hours?',
        'answer' => 'Our team will confirm the current business hours for you. Please contact ' . $defaultFaqContactText . ' for the latest availability.',
        'note' => 'Useful when hours are not yet added as a custom FAQ.'
    ],
    'location_service_area' => [
        'question' => 'Where are you located or which areas do you serve?',
        'answer' => 'Please contact ' . $defaultFaqContactText . ' and our team will share the correct location or service area details.',
        'note' => 'Covers basic location or service-area questions.'
    ],
    'human_agent' => [
        'question' => 'Can I talk to a human?',
        'answer' => 'Yes. Please contact ' . $defaultFaqContactText . ' and a team member will assist you.',
        'note' => 'Gives a clear path when the visitor asks for a real person.'
    ]
];
$defaultFaqSettingsRaw = $settings['default_faq_settings'] ?? [];
if (is_string($defaultFaqSettingsRaw)) {
    $decodedDefaultFaqSettings = json_decode($defaultFaqSettingsRaw, true);
    $defaultFaqSettingsRaw = is_array($decodedDefaultFaqSettings) ? $decodedDefaultFaqSettings : [];
}
if (!is_array($defaultFaqSettingsRaw)) {
    $defaultFaqSettingsRaw = [];
}
$defaultFaqSettings = [];
foreach ($defaultFaqDefinitions as $defaultFaqKey => $definition) {
    $hasSavedDefaultFaq = array_key_exists($defaultFaqKey, $defaultFaqSettingsRaw);
    $savedDefaultFaq = $hasSavedDefaultFaq ? $defaultFaqSettingsRaw[$defaultFaqKey] : [];
    $savedDefaultFaq = is_array($savedDefaultFaq) ? $savedDefaultFaq : ['enabled' => $savedDefaultFaq];
    $defaultFaqSettings[$defaultFaqKey] = [
        'enabled' => array_key_exists('enabled', $savedDefaultFaq)
            ? filter_var($savedDefaultFaq['enabled'], FILTER_VALIDATE_BOOLEAN)
            : true,
        'question' => trim((string)($savedDefaultFaq['question'] ?? '')) !== '' ? trim((string)$savedDefaultFaq['question']) : $definition['question'],
        'answer' => trim((string)($savedDefaultFaq['answer'] ?? '')) !== '' ? trim((string)$savedDefaultFaq['answer']) : $definition['answer']
    ];
}
$razorpayCustomerContact = $profileContactValue;
$initialSource = $profileFirstName ?: $email;
$initials = strtoupper(substr($initialSource, 0, 1));
$analyticsRangeLabel = [
    'today' => 'Today',
    'yesterday' => 'Yesterday',
    '7_days' => 'Last 7 days',
    '30_days' => 'Last 30 days',
    'custom' => 'Custom range'
][$analyticsRange] ?? 'Last 30 days';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/logo_img.png">
<title>Vani Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif}
:root{
  --brand:#6366f1;
  --brand-2:#ec4899;
  --ink:#0f172a;
  --muted:#64748b;
  --line:rgba(148,163,184,.24);
  --panel:rgba(255,255,255,.78);
  --panel-strong:#fff;
  --soft:#f8fafc;
  --shadow:0 18px 45px rgba(15,23,42,.09);
  --scroll-track:rgba(226,232,240,.58);
  --scroll-thumb:linear-gradient(180deg,rgba(99,102,241,.72),rgba(236,72,153,.68));
  --scroll-thumb-solid:#818cf8;
  --scroll-thumb-hover:#6366f1;
}
html{
  -webkit-text-size-adjust:100%;
  overflow-x:hidden;
}
body{
  min-height:100vh;
  color:var(--ink);
  background:linear-gradient(135deg,#f0f9ff,#eef2ff,#faf5ff);
  overflow-x:hidden;
}
body.dark{
  --ink:#e5e7eb;
  --muted:#a5b4fc;
  --line:rgba(226,232,240,.13);
  --panel:rgba(15,23,42,.82);
  --panel-strong:#111827;
  --soft:#0f172a;
  --shadow:0 18px 45px rgba(0,0,0,.24);
  --scroll-track:rgba(15,23,42,.54);
  --scroll-thumb:linear-gradient(180deg,rgba(129,140,248,.86),rgba(236,72,153,.76));
  --scroll-thumb-solid:#818cf8;
  --scroll-thumb-hover:#c084fc;
  background:linear-gradient(135deg,#0f172a,#1e1b4b,#3b0764);
}
a{text-decoration:none;color:inherit}
button,input,select,textarea{font:inherit}
button{touch-action:manipulation}
html{scrollbar-width:thin;scrollbar-color:var(--scroll-thumb-solid) var(--scroll-track)}
body,.nav-tabs,.table-wrap,.profile-prompt,.pattern-grid,.bi-drilldown-body,.suggested-faq-list,.bulk-report-modal,.bulk-report-table,.sidebar,.top-actions{scrollbar-width:thin;scrollbar-color:var(--scroll-thumb-solid) transparent}
::-webkit-scrollbar{width:11px;height:11px}
::-webkit-scrollbar-track{background:var(--scroll-track)}
::-webkit-scrollbar-thumb{border:3px solid transparent;border-radius:999px;background:var(--scroll-thumb);background-clip:padding-box;box-shadow:inset 0 0 0 1px rgba(255,255,255,.2)}
::-webkit-scrollbar-thumb:hover{background:var(--scroll-thumb-hover);background-clip:padding-box}
::-webkit-scrollbar-corner{background:transparent}
.nav-tabs::-webkit-scrollbar,.table-wrap::-webkit-scrollbar,.profile-prompt::-webkit-scrollbar,.pattern-grid::-webkit-scrollbar,.bi-drilldown-body::-webkit-scrollbar,.suggested-faq-list::-webkit-scrollbar,.bulk-report-modal::-webkit-scrollbar,.bulk-report-table::-webkit-scrollbar,.sidebar::-webkit-scrollbar,.top-actions::-webkit-scrollbar{width:8px;height:8px}
.nav-tabs::-webkit-scrollbar-track,.table-wrap::-webkit-scrollbar-track,.profile-prompt::-webkit-scrollbar-track,.pattern-grid::-webkit-scrollbar-track,.bi-drilldown-body::-webkit-scrollbar-track,.suggested-faq-list::-webkit-scrollbar-track,.bulk-report-modal::-webkit-scrollbar-track,.bulk-report-table::-webkit-scrollbar-track,.sidebar::-webkit-scrollbar-track,.top-actions::-webkit-scrollbar-track{background:transparent}
.nav-tabs::-webkit-scrollbar-thumb,.table-wrap::-webkit-scrollbar-thumb,.profile-prompt::-webkit-scrollbar-thumb,.pattern-grid::-webkit-scrollbar-thumb,.bi-drilldown-body::-webkit-scrollbar-thumb,.suggested-faq-list::-webkit-scrollbar-thumb,.bulk-report-modal::-webkit-scrollbar-thumb,.bulk-report-table::-webkit-scrollbar-thumb,.sidebar::-webkit-scrollbar-thumb,.top-actions::-webkit-scrollbar-thumb{border:2px solid transparent}
.dashboard-shell{min-height:100vh;display:grid;grid-template-columns:260px minmax(0,1fr);width:100%;overflow-x:hidden}
.drawer-overlay{
  position:fixed;
  inset:0;
  background:rgba(15,23,42,.38);
  opacity:0;
  pointer-events:none;
  transition:.25s ease;
  z-index:35;
}
.drawer-overlay.show{
  opacity:1;
  pointer-events:auto;
}
.sidebar{
  position:sticky;top:0;height:100vh;padding:24px 18px;
  display:flex;flex-direction:column;gap:0;overflow:hidden;
  background:rgba(255,255,255, 0.9);backdrop-filter:blur(18px);
  border-right:1px solid var(--line);
}
body.dark .sidebar{background:rgba(15,23,42,.66)}
.brand{display:flex;align-items:center;gap:12px;position:relative;margin-bottom:26px;padding:7px 10px 9px 6px;border-radius:16px;background:linear-gradient(135deg,rgba(79,70,229,.12),rgba(236,72,153,.1));border:1px solid rgba(129,140,248,.16)}
.brand img{width:58px;height:auto;filter:drop-shadow(0 0 18px rgba(99,102,241,.65)) drop-shadow(0 0 24px rgba(236,72,153,.24))}
.brand strong{font-size:20px;background:linear-gradient(90deg,#ffffff,#c4b5fd 48%,#f9a8d4);-webkit-background-clip:text;-webkit-text-fill-color:transparent;filter:drop-shadow(0 0 14px rgba(129,140,248,.28))}
.nav-tabs{display:grid;gap:8px;overflow-y:auto;padding-right:4px;padding-bottom:12px;min-height:0}
.tab-btn{
  border:0;background:transparent;color:var(--muted);padding:12px 14px;border-radius:12px;
  display:flex;align-items:center;gap:10px;text-align:left;cursor:pointer;font-weight:600;
}
.tab-btn:hover,.tab-btn.active{background:rgba(99,102,241,.11);color:var(--brand)}
.sidebar-footer{margin-top:14px;padding:14px;border:1px solid var(--line);border-radius:16px;background:var(--panel);flex:0 0 auto}
.sidebar-footer small{display:block;color:var(--muted);line-height:1.6}
.dashboard-mode-actions{display:flex;align-items:center;gap:10px;min-width:0;max-width:100%;flex-wrap:nowrap}
.nadara-pill{min-height:38px;border-radius:13px;padding:0 16px;border:1px solid rgba(34,197,94,.46);background:linear-gradient(135deg,rgba(34,197,94,.18),rgba(22,163,74,.28));color:#15803d;font-size:14px;font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 10px 24px rgba(34,197,94,.16);white-space:nowrap}
.nadara-pill:before{content:"";width:9px;height:9px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.14)}
body.dark .nadara-pill{color:#86efac;background:linear-gradient(135deg,rgba(34,197,94,.16),rgba(21,128,61,.28));border-color:rgba(74,222,128,.34)}
.ai-upgrade-btn{min-height:44px;max-width:100%;border-radius:13px;padding:6px 18px;border:1px solid rgba(234,179,8,.72);background:linear-gradient(135deg,#fef08a,#facc15,#d97706);color:#050505;font-size:14px;font-weight:950;display:inline-flex;flex-direction:column;align-items:center;justify-content:center;gap:1px;line-height:1.12;box-shadow:0 12px 26px rgba(234,179,8,.28);white-space:nowrap;text-align:center}
.ai-upgrade-btn small{font-size:10px;font-weight:800;line-height:1.15}
.ai-upgrade-btn:hover{transform:translateY(-1px);box-shadow:0 16px 34px rgba(234,179,8,.34)}
.mobile-ai-upgrade{display:none}
.main{min-width:0;width:100%;max-width:100vw;overflow-x:hidden}
.topbar{
  height:78px;display:flex;align-items:center;justify-content:space-between;gap:16px;
  padding:0 28px;border-bottom:1px solid var(--line);background:rgba(255, 255, 255, 0.9);
  backdrop-filter:blur(18px);position:sticky;top:0;z-index:10;
}
body.dark .topbar{background:rgba(15,23,42,.66)}
.topbar-left{display:flex;align-items:center;gap:12px;min-width:0}
.mobile-toggle{display:none;width:42px;height:42px;border-radius:12px;border:1px solid var(--line);background:var(--panel);color:var(--ink);font-weight:800;cursor:pointer;align-items:center;justify-content:center}
.page-title h1{font-size:24px;letter-spacing:0}
.page-title p{color:var(--muted);font-size:13px;margin-top:4px}
.top-actions{display:flex;align-items:center;gap:10px;flex-wrap:nowrap;justify-content:flex-end;min-width:0}
.user-menu{display:flex;align-items:center;gap:10px;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:7px 10px;min-width:0}
.avatar{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:700;background:linear-gradient(135deg,var(--brand),var(--brand-2));flex:0 0 36px}
.user-text{max-width:180px;min-width:0;flex:1 1 auto}
.user-text strong,.user-text span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.user-text strong{font-size:13px}.user-text span{font-size:12px;color:var(--muted)}
.user-menu .ghost-btn{flex:0 0 auto;white-space:nowrap}
.pill-btn,.ghost-btn,.danger-btn{
  min-height:40px;border:0;border-radius:12px;padding:0 14px;font-weight:700;cursor:pointer;
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
}
.pill-btn{color:#fff;background:linear-gradient(135deg,var(--brand),var(--brand-2));box-shadow:0 10px 22px rgba(99,102,241,.22)}
.ghost-btn{color:var(--ink);background:var(--panel);border:1px solid var(--line)}
.danger-btn{color:#b91c1c;background:#fee2e2;border:1px solid #fecaca}
.content{padding:28px;display:grid;gap:22px;min-width:0;max-width:100%}
.panel{
  background:var(--panel);border:1px solid rgba(255,255,255,.48);border-radius:22px;
  box-shadow:var(--shadow);backdrop-filter:blur(16px);
  min-width:0;max-width:100%;
}
body.dark .panel{border-color:var(--line)}
.overview-hero{padding:24px;display:grid;grid-template-columns:1.3fr .7fr;gap:20px;align-items:center}
.eyebrow{font-size:12px;font-weight:800;color:var(--brand);text-transform:uppercase;letter-spacing:.08em}
.overview-hero h2{font-size:34px;line-height:1.18;margin:9px 0;background:linear-gradient(90deg,var(--brand),var(--brand-2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.overview-hero p{color:var(--muted);line-height:1.7}
.subscription-transfer-card{margin-top:22px;padding:22px;display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,.8fr);gap:22px;align-items:stretch;border-color:rgba(99,102,241,.2);background:linear-gradient(135deg,rgba(99,102,241,.08),rgba(255,255,255,.78))}
body.dark .subscription-transfer-card{background:linear-gradient(135deg,rgba(99,102,241,.16),rgba(15,23,42,.84))}
.subscription-transfer-card h3{margin:7px 0 9px;font-size:22px}
.subscription-transfer-card .transfer-copy{display:flex;flex-direction:column;gap:12px}
.subscription-transfer-card .transfer-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:auto}
.subscription-transfer-card .transfer-summary span{display:block;padding:12px;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.55);color:var(--muted);font-size:13px;line-height:1.4}
body.dark .subscription-transfer-card .transfer-summary span{background:rgba(15,23,42,.52)}
.subscription-transfer-card .transfer-summary strong{display:block;margin-top:4px;color:var(--ink);font-size:16px}
.subscription-transfer-card .transfer-form{display:grid;gap:12px;padding:16px;border:1px solid var(--line);border-radius:18px;background:var(--panel)}
.subscription-transfer-card .transfer-form .field{margin:0}
.subscription-transfer-card .transfer-warning{padding:12px 14px;border:1px solid rgba(185,28,28,.18);border-radius:14px;background:rgba(254,226,226,.58);font-size:13px;color:#7f1d1d;line-height:1.55}
.subscription-transfer-card .transfer-warning strong{color:#b91c1c}
body.dark .subscription-transfer-card .transfer-warning{background:rgba(127,29,29,.24);color:#fecaca}
body.dark .subscription-transfer-card .transfer-warning strong{color:#fecaca}
.subscription-transfer-card .pill-btn{width:100%;min-height:46px}
.setup-autosave-actions{align-items:center;justify-content:flex-end}
.setup-autosave-actions .input-help{margin:0}
.bot-picker{display:grid;gap:10px;padding:18px;border-radius:18px;background:rgba(255,255,255,.58);border:1px solid var(--line)}
body.dark .bot-picker{background:rgba(15,23,42,.56)}
.bot-picker-head{display:flex;align-items:center;justify-content:space-between;gap:10px}
.bot-picker label,.field label{font-size:13px;font-weight:700;color:var(--muted)}
.delete-bot-mini{min-height:30px;border-radius:9px;padding:0 9px;font-size:12px;white-space:nowrap}
select,input,textarea{
  width:100%;border:1px solid var(--line);background:var(--panel-strong);color:var(--ink);
  border-radius:12px;padding:12px 13px;outline:none;
}
textarea{min-height:92px;resize:vertical}
select:focus,input:focus,textarea:focus{box-shadow:0 0 0 3px rgba(99,102,241,.15);border-color:rgba(99,102,241,.55)}
.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.metric{padding:18px}
.metric-link{width:100%;text-align:left;color:inherit;cursor:pointer}
.metric-link:hover{border-color:rgba(99,102,241,.4);transform:translateY(-1px)}
.metric span{display:block;color:var(--muted);font-size:13px;font-weight:700}
.metric strong{display:block;font-size:28px;margin-top:8px}
.metric small{display:block;color:var(--muted);margin-top:7px;line-height:1.4}
.metric-status-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.metric .metric-status-head span{display:inline-flex;align-items:center}
.overview-status-pill{display:inline-flex;align-items:center;gap:7px;color:var(--ink);font-size:13px;font-weight:900;line-height:1;margin:0}
.overview-status-pill:before{content:"";width:10px;height:10px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.14)}
.overview-status-pill.inactive:before{background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.14)}
.metric-delta{display:inline-flex;width:fit-content;margin-top:8px;border-radius:999px;padding:4px 8px;font-size:12px;font-weight:900;background:rgba(34,197,94,.12);color:#15803d}
.metric-delta.bad{background:rgba(239,68,68,.12);color:#b91c1c}
.metric-delta.flat{background:rgba(148,163,184,.14);color:var(--muted)}
.chatbot-theme-preview{margin-top:12px;border:1px solid var(--line);border-radius:16px;overflow:hidden;background:var(--panel-strong);box-shadow:0 16px 34px rgba(15,23,42,.11)}
.chatbot-theme-header{display:flex;align-items:center;gap:10px;padding:10px 11px;color:#fff;font-weight:800;min-width:0}
.chatbot-theme-avatar{width:34px;height:34px;object-fit:contain;border-radius:12px;border:1px solid rgba(255,255,255,.38);background:rgba(255,255,255,.92);padding:5px;flex:0 0 auto}
.chatbot-theme-title{min-width:0;display:grid;gap:1px}
.chatbot-theme-title strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;line-height:1.2}
.chatbot-theme-title small{display:block;color:rgba(255,255,255,.78);font-size:10px;font-weight:800;line-height:1.2}
.chatbot-theme-close{width:26px;height:26px;margin-left:auto;border-radius:999px;border:1px solid rgba(255,255,255,.35);display:grid;place-items:center;background:rgba(255,255,255,.14);font-size:16px;line-height:1}
.chatbot-theme-body{display:grid;gap:9px;padding:12px;background:linear-gradient(180deg,#f8fafc 0%,#eef2ff 100%);min-height:132px}
.chatbot-theme-row{display:flex;align-items:flex-end;gap:8px;min-width:0}
.chatbot-theme-row.compact{padding-left:42px}
.chatbot-theme-mini-avatar{width:32px;height:32px;object-fit:contain;border-radius:11px;border:1px solid var(--line);background:#fff;padding:5px;flex:0 0 auto}
.chatbot-theme-bubble{min-height:38px;max-width:min(220px,100%);padding:9px 11px;border-radius:15px 15px 15px 5px;background:#fff!important;color:#0f172a;font-size:12px;font-weight:750;line-height:1.35;box-shadow:0 10px 24px rgba(15,23,42,.08);border:1px solid rgba(226,232,240,.9);overflow:hidden}
.chatbot-theme-bubble div{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.chatbot-theme-dots{display:flex;gap:4px;align-items:center}
.chatbot-theme-dots i{width:6px;height:6px;border-radius:999px;background:var(--brand);display:block;opacity:.78}
.chatbot-theme-input{display:flex;align-items:center;border-top:1px solid var(--line);background:#fff}
.chatbot-theme-input span{flex:1;min-width:0;padding:10px 11px;color:#94a3b8;font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chatbot-theme-input b{align-self:stretch;display:grid;place-items:center;padding:0 12px;color:#fff;font-size:12px;font-weight:900}
body.dark .chatbot-theme-preview{background:#111827}
body.dark .chatbot-theme-body{background:linear-gradient(180deg,#111827 0%,#172554 100%)}
body.dark .chatbot-theme-input{background:#0f172a}
.popular-questions-metric{display:grid;align-content:start;gap:9px}
.popular-question-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;border-bottom:1px solid var(--line);padding:7px 0}
.popular-question-row:last-child{border-bottom:0}
.popular-question-row em{font-style:normal;color:var(--ink);font-size:13px;line-height:1.35;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.popular-question-row strong{display:inline-flex;margin:0;align-items:center;justify-content:center;min-width:28px;height:24px;border-radius:999px;background:rgba(99,102,241,.12);color:var(--brand);font-size:12px}
.status-dot{display:inline-flex;align-items:center;gap:8px}
.status-dot:before{content:"";width:10px;height:10px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.14)}
.status-dot.inactive:before{background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.14)}
.status-toggle-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px}
.switch{position:relative;display:inline-flex;align-items:center;width:54px;height:30px;flex:0 0 auto}
.switch input{position:absolute;opacity:0;pointer-events:none}
.switch-slider{position:absolute;inset:0;border-radius:999px;background:#cbd5e1;cursor:pointer;transition:.2s ease}
.switch-slider:before{content:"";position:absolute;width:24px;height:24px;left:3px;top:3px;border-radius:50%;background:#fff;box-shadow:0 3px 8px rgba(15,23,42,.2);transition:.2s ease}
.switch input:checked + .switch-slider{background:#22c55e}
.switch input:checked + .switch-slider:before{transform:translateX(24px)}
.switch input:focus-visible + .switch-slider{box-shadow:0 0 0 3px rgba(99,102,241,.25)}
.action-card{padding:18px;display:grid;gap:10px;align-content:start}
.action-card.danger-zone{border-color:rgba(239,68,68,.28);background:rgba(254,226,226,.46)}
body.dark .action-card.danger-zone{background:rgba(127,29,29,.18);border-color:rgba(248,113,113,.28)}
.action-card h3,.section-head h3{font-size:17px}
.action-card p,.muted{color:var(--muted);line-height:1.6;font-size:14px}
.tab-panel{display:none;gap:18px;min-width:0;max-width:100%}
.tab-panel.active{display:grid}
.section-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:20px 20px 0}
.section-body{padding:20px;min-width:0;max-width:100%}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;min-width:0}
.profile-grid{display:grid;grid-template-columns:180px minmax(0,1fr);gap:20px;align-items:start;min-width:0}
.profile-photo{display:grid;gap:12px;justify-items:center;padding:18px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.42)}
body.dark .profile-photo{background:rgba(15,23,42,.44)}
.profile-avatar{width:112px;height:112px;border-radius:50%;display:grid;place-items:center;color:#fff;font-size:36px;font-weight:800;background:linear-gradient(135deg,var(--brand),var(--brand-2));overflow:hidden}
.profile-avatar img{width:100%;height:100%;object-fit:cover}
.security-note{grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px;border:1px solid var(--line);border-radius:14px;background:rgba(99,102,241,.08);color:var(--muted);font-size:13px;line-height:1.5}
.profile-prompt-backdrop{position:fixed;inset:0;z-index:150;display:none;place-items:center;padding:20px;background:rgba(15,23,42,.5);backdrop-filter:blur(12px)}
.profile-prompt-backdrop.active{display:grid}
.profile-prompt{width:min(620px,100%);max-height:92vh;overflow:auto;background:var(--panel-strong);border:1px solid var(--line);border-radius:22px;box-shadow:0 24px 80px rgba(15,23,42,.32);padding:22px;color:var(--ink)}
.profile-prompt-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:18px}
.profile-prompt-head h3{font-size:22px;margin:6px 0 6px}
.profile-prompt-badge{display:inline-flex;width:42px;height:42px;border-radius:14px;align-items:center;justify-content:center;color:#fff;font-weight:900;background:linear-gradient(135deg,var(--brand),var(--brand-2));box-shadow:0 12px 24px rgba(99,102,241,.22)}
.profile-prompt-close{width:36px;height:36px;border-radius:12px;border:1px solid var(--line);background:var(--panel);color:var(--ink);font-size:20px;line-height:1;cursor:pointer}
.profile-prompt-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.profile-prompt-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px;flex-wrap:wrap}
.field{display:grid;gap:8px;min-width:0}
.field.full{grid-column:1/-1}
.panel-actions{grid-column:1/-1;display:flex;justify-content:flex-end;gap:10px;min-width:0;padding-top:4px}
.section-body > .panel-actions{padding-top:16px}
.swatches{display:flex;gap:10px;flex-wrap:wrap}
.swatch{width:34px;height:34px;border-radius:10px;border:2px solid rgba(255,255,255,.8);box-shadow:0 4px 10px rgba(15,23,42,.12);cursor:pointer}
.theme-designer{display:grid;gap:16px;padding:16px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.42)}
body.dark .theme-designer{background:rgba(15,23,42,.44)}
.theme-preview-box{min-height:92px;border-radius:18px;border:1px solid var(--line);box-shadow:inset 0 1px 0 rgba(255,255,255,.26),0 16px 34px rgba(15,23,42,.12);display:grid;place-items:center;color:#fff;font-weight:900;text-shadow:0 1px 12px rgba(0,0,0,.36);overflow:hidden}
.theme-controls{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.theme-color-grid,.pattern-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(42px,1fr));gap:8px}
.theme-color-chip{height:42px;border-radius:12px;border:2px solid rgba(255,255,255,.85);cursor:pointer;box-shadow:0 7px 16px rgba(15,23,42,.13)}
.pattern-grid{max-height:230px;overflow:auto;padding-right:3px}
.pattern-chip{height:44px;border-radius:12px;border:1px solid var(--line);cursor:pointer;background-color:var(--panel-strong)}
.theme-color-chip.active,.pattern-chip.active{outline:3px solid rgba(99,102,241,.24);border-color:rgba(99,102,241,.82)}
.bot-image-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(72px,1fr));gap:10px}
.bot-image-option{border:1px solid var(--line);background:var(--panel-strong);border-radius:14px;padding:8px;cursor:pointer;display:grid;place-items:center}
.bot-image-option img{width:100%;aspect-ratio:1;object-fit:contain}
.bot-image-option input{position:absolute;opacity:0;pointer-events:none}
.bot-image-option:has(input:checked){border-color:rgba(99,102,241,.72);box-shadow:0 0 0 3px rgba(99,102,241,.14)}
.selected-bot-image{width:64px;height:64px;object-fit:contain;border-radius:16px;border:1px solid var(--line);background:var(--panel-strong);padding:8px}
.dashboard-loading{position:fixed;inset:0;z-index:120;display:none;place-items:center;padding:24px;background:rgba(15,23,42,.42);backdrop-filter:blur(10px)}
.dashboard-loading.active{display:grid}
body.dashboard-loading-active{overflow:hidden}
.dashboard-loading-card{width:min(360px,calc(100vw - 40px));padding:22px;border:1px solid var(--line);border-radius:18px;background:var(--panel);box-shadow:var(--shadow);display:grid;gap:12px;text-align:center;justify-items:center}
.dashboard-loading-spinner{width:42px;height:42px;border-radius:50%;border:4px solid rgba(99,102,241,.18);border-top-color:var(--brand);animation:dashboardSpin .8s linear infinite}
.dashboard-loading-card strong{font-size:18px}
.dashboard-loading-card span{color:var(--muted);font-size:14px;line-height:1.5}
@keyframes dashboardSpin{to{transform:rotate(360deg)}}
.table-wrap{
  width:100%;
  max-width:100%;
  min-width:0;
  overflow-x:auto;
  overflow-y:hidden;
  -webkit-overflow-scrolling:touch;
  border-radius:0 0 18px 18px;
}
table{width:100%;border-collapse:collapse;min-width:720px}
th,td{text-align:left;padding:13px 14px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
td{font-size:14px;color:var(--ink);overflow-wrap:anywhere}
td small{display:block;margin-top:4px}
td .ghost-btn{white-space:normal}
.tag{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800;background:rgba(99,102,241,.12);color:var(--brand)}
.tag.good{background:rgba(34,197,94,.13);color:#15803d}.tag.bad{background:rgba(239,68,68,.12);color:#b91c1c}
.embed-box{position:relative}
code{display:block;white-space:pre-wrap;word-break:break-all;padding:16px;border-radius:14px;background:#111827;color:#e5e7eb;font-size:13px;line-height:1.6}
.easy-install-grid{grid-column:1/-1;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px}
.easy-install-card{padding:16px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.42);display:grid;gap:10px;align-content:start;min-width:0}
body.dark .easy-install-card{background:rgba(15,23,42,.38)}
.easy-install-icon{width:42px;height:42px;border-radius:14px;display:grid;place-items:center;color:#fff;font-weight:900;background:linear-gradient(135deg,var(--brand),var(--brand-2));box-shadow:0 10px 20px rgba(99,102,241,.18)}
.easy-install-card h4{font-size:15px;margin:0}
.easy-install-card p{font-size:13px;line-height:1.55;color:var(--muted);margin:0}
.easy-install-card .ghost-btn,.easy-install-card .pill-btn{width:100%;justify-content:center;min-height:40px}
.install-guide{grid-column:1/-1;padding:16px;border:1px solid var(--line);border-radius:18px;background:rgba(99,102,241,.07);display:none}
.install-guide.active{display:grid;gap:10px}
.install-guide ol{padding-left:20px;color:var(--muted);line-height:1.7;font-size:14px}
.install-guide strong{color:var(--ink)}
.inline-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;min-width:0;max-width:100%}
.inline-row > *{min-width:0}
.inline-row input{flex:1 1 220px;width:auto}
.faq-actions{display:flex;gap:8px;flex-wrap:wrap}
.faq-edit-field{display:none}
tr.editing .faq-display{display:none}
tr.editing .faq-edit-field{display:block}
tr.editing .faq-edit-btn{display:none}
.split{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;min-width:0}
.empty{padding:28px;text-align:center;color:var(--muted)}
.notice{padding:14px 16px;border-radius:14px;background:rgba(99,102,241,.1);border:1px solid rgba(99,102,241,.18);color:var(--ink);line-height:1.6}
.security-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.security-card{border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.42);padding:16px;display:grid;gap:12px;min-width:0}
body.dark .security-card{background:rgba(15,23,42,.44)}
.security-card h4{font-size:15px}
.security-card .muted{font-size:13px}
#install .section-body{align-items:start}
#install .security-card{align-content:start}
@media(min-width:1181px){
  #install .section-body.form-grid{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
  #install .section-body.form-grid > .field.full:nth-child(1){grid-column:1/-1}
  #install .section-body.form-grid > .field.full:nth-child(2),
  #install .section-body.form-grid > .field.full:nth-child(3){grid-column:span 1;align-self:stretch;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.34);padding:16px}
  body.dark #install .section-body.form-grid > .field.full:nth-child(2),
  body.dark #install .section-body.form-grid > .field.full:nth-child(3){background:rgba(15,23,42,.36)}
  #install .section-body.form-grid > .panel-actions.full{grid-column:1/-1;justify-content:flex-end}
  #install .security-grid{grid-template-columns:minmax(300px,.9fr) minmax(420px,1.1fr);align-items:start}
  #install .security-grid .security-card:nth-child(2){grid-column:2;grid-row:1 / span 3}
  #install .security-grid .security-card:nth-child(1){grid-column:1;grid-row:1}
  #install .security-grid .security-card:nth-child(3){grid-column:1;grid-row:2}
  #install .security-grid .security-card:nth-child(4){grid-column:1;grid-row:3}
  #install .security-card .pill-btn,
  #install .security-card .ghost-btn{width:fit-content}
  #install .security-card textarea{min-height:76px}
  .easy-install-grid{grid-template-columns:repeat(5,minmax(0,1fr))}
}
.api-key-reveal{display:none;margin-top:10px}
.api-key-reveal.active{display:block}
.api-key-code{font-size:12px}
.status-dot{width:9px;height:9px;border-radius:50%;display:inline-block;background:#22c55e;margin-right:7px}
.status-dot.off{background:#ef4444}
.critical-save-note{margin-top:14px;padding:13px 15px;border-radius:12px;border:1px solid rgba(220,38,38,.35);background:rgba(254,226,226,.75);color:#b91c1c;font-size:17px;font-weight:800;line-height:1.45}
body.dark .critical-save-note{background:rgba(127,29,29,.22);border-color:rgba(248,113,113,.38);color:#fecaca}
.analytics-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.analytics-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}
.analytics-map-panel{grid-column:1/-1}
.world-map-chart{min-height:420px;width:100%;border:1px solid var(--line);border-radius:18px;background:linear-gradient(180deg,rgba(99,102,241,.08),rgba(6,182,212,.05));margin-top:14px;overflow:hidden}
.world-map-fallback{display:grid;gap:10px;margin-top:12px}
.world-map-fallback.compact{grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
.world-map-fallback .bar-row{grid-template-columns:minmax(90px,.45fr) minmax(0,1fr) 44px}
.map-controls{display:flex;gap:12px;align-items:end;justify-content:space-between;flex-wrap:wrap;margin-top:14px}
.map-controls .field{min-width:220px;margin:0}
.map-note{font-size:12px;color:var(--muted);line-height:1.5;max-width:520px}
.analytics-head{align-items:center}
.analytics-title-block{display:grid;gap:10px;min-width:0}
.analytics-period-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.analytics-period-card{display:grid;gap:3px;padding:10px 12px;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.44);min-width:210px}
body.dark .analytics-period-card{background:rgba(15,23,42,.38)}
.analytics-period-card span{font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:900;letter-spacing:.05em}
.analytics-period-card strong{font-size:13px;color:var(--ink);line-height:1.35}
.analytics-head-actions{display:grid;gap:12px;justify-items:end;align-self:center;min-width:260px}
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.filter-chip{border:1px solid var(--line);background:var(--panel-strong);color:var(--ink);border-radius:999px;padding:8px 12px;font-size:13px;font-weight:700;text-decoration:none}
.filter-chip.active{background:linear-gradient(135deg,var(--brand),var(--brand-2));border-color:transparent;color:#fff}
.analytics-filter-form{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-top:16px}
.analytics-filter-form .field{min-width:150px}
.analytics-filter-form .pill-btn{min-height:42px}
.analytics-filter-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-left:auto}
.analytics-filter-actions .analytics-pdf-report-btn{white-space:nowrap}
.analytics-tabs{display:flex;gap:8px;flex-wrap:wrap}
.analytics-tab-btn{border:1px solid var(--line);background:var(--panel-strong);color:var(--ink);border-radius:999px;padding:9px 13px;font-size:13px;font-weight:800;cursor:pointer}
.analytics-tab-btn.active{background:linear-gradient(135deg,var(--brand),var(--brand-2));border-color:transparent;color:#fff}
.payment-subtabs{display:flex;gap:8px;flex-wrap:wrap}
.payment-subtab-btn{border:1px solid var(--line);background:var(--panel-strong);color:var(--muted);border-radius:12px;min-height:38px;padding:0 13px;font-weight:800;cursor:pointer}
.payment-subtab-btn:hover,.payment-subtab-btn.active{background:rgba(99,102,241,.12);color:var(--brand);border-color:rgba(99,102,241,.34)}
.payment-subpanel{display:none}
.payment-subpanel.active{display:block}
.analytics-subpanel{display:none;gap:18px}
.analytics-subpanel.active{display:grid}
.bi-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.bi-kpi{padding:16px;border:1px solid var(--line);border-radius:18px;background:linear-gradient(180deg,rgba(255,255,255,.7),rgba(255,255,255,.36));display:grid;gap:8px;min-width:0;cursor:pointer}
body.dark .bi-kpi{background:linear-gradient(180deg,rgba(15,23,42,.78),rgba(15,23,42,.42))}
.bi-kpi span{font-size:12px;color:var(--muted);font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.bi-kpi strong{font-size:26px;line-height:1.05;overflow-wrap:anywhere}
.bi-kpi small{color:var(--muted);line-height:1.45}
.bi-dashboard-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(320px,.8fr);gap:16px}
.bi-dashboard-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}
.bi-panel{padding:16px;min-width:0}
.bi-panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
.bi-panel-head h3{font-size:17px}
.bi-chart{width:100%;min-height:310px;border:1px solid var(--line);border-radius:16px;background:rgba(255,255,255,.28)}
body.dark .bi-chart{background:rgba(15,23,42,.28)}
.bi-chart.compact{min-height:250px}
.bi-chart.tall{min-height:430px}
.bi-alert-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.bi-alert{padding:14px;border:1px solid var(--line);border-radius:16px;background:rgba(255,255,255,.38);display:grid;gap:6px;min-width:0}
body.dark .bi-alert{background:rgba(15,23,42,.36)}
.bi-alert strong{font-size:15px}.bi-alert span{font-size:13px;color:var(--muted);line-height:1.45}
.bi-alert.good{border-color:rgba(34,197,94,.34);background:rgba(34,197,94,.09)}
.bi-alert.warn{border-color:rgba(245,158,11,.38);background:rgba(245,158,11,.1)}
.bi-alert.bad{border-color:rgba(239,68,68,.34);background:rgba(239,68,68,.09)}
.bi-drilldown{display:none;position:fixed;right:20px;top:86px;bottom:20px;width:min(520px,calc(100vw - 40px));z-index:70;border:1px solid var(--line);border-radius:18px;background:var(--panel);box-shadow:var(--shadow);overflow:hidden}
.bi-drilldown.open{display:grid;grid-template-rows:auto 1fr}
.bi-drilldown-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px;border-bottom:1px solid var(--line)}
.bi-drilldown-body{overflow:auto;padding:16px}
.bi-drilldown table{min-width:0}
.bi-drilldown .ghost-btn{padding:8px 10px}
.mini-chart{display:grid;gap:10px;margin-top:12px}
.bar-row{display:grid;grid-template-columns:minmax(82px,.45fr) minmax(0,1fr) 44px;gap:10px;align-items:center;font-size:13px;color:var(--muted)}
.bar-track{height:12px;border-radius:999px;background:rgba(148,163,184,.2);overflow:hidden}
.bar-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,var(--brand),var(--brand-2));min-width:3px}
.trend-line{height:180px;display:flex;align-items:flex-end;gap:10px;border-left:1px solid var(--line);border-bottom:1px solid var(--line);padding:12px 8px 0;margin-top:14px}
.trend-column{flex:1;display:grid;align-content:end;gap:8px;min-width:0;text-align:center}
.trend-bar{border-radius:8px 8px 0 0;background:linear-gradient(180deg,var(--brand),var(--brand-2));min-height:4px}
.trend-label{font-size:11px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.funnel{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-top:12px}
.funnel-step{padding:12px;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.36);display:grid;gap:6px}
body.dark .funnel-step{background:rgba(15,23,42,.38)}
.funnel-step strong{font-size:20px}
.funnel-step span{font-size:12px;color:var(--muted);font-weight:700}
.report-actions{display:flex;gap:10px;flex-wrap:wrap}
.active-subscription-banner{margin-top:18px;padding:18px;border:1px solid rgba(99,102,241,.22);border-radius:18px;background:linear-gradient(135deg,rgba(99,102,241,.13),rgba(6,182,212,.1));display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.active-subscription-banner h3{font-size:22px;margin-top:4px}
.active-subscription-banner small{display:block;color:var(--muted);line-height:1.5;margin-top:4px}
.active-subscription-banner .tag{align-self:flex-start}
body.dark .active-subscription-banner{background:linear-gradient(135deg,rgba(99,102,241,.2),rgba(6,182,212,.14))}
.subscription-wallet-note{margin-top:18px;padding:16px 18px;border:1px solid rgba(34,197,94,.24);border-radius:18px;background:linear-gradient(135deg,rgba(34,197,94,.12),rgba(6,182,212,.09));color:var(--ink);line-height:1.65}
.subscription-wallet-note strong{display:block;font-size:17px;margin-bottom:4px}
body.dark .subscription-wallet-note{background:linear-gradient(135deg,rgba(34,197,94,.16),rgba(6,182,212,.12))}
.billing-filter{margin-top:18px;display:grid;grid-template-columns:repeat(2,minmax(150px,1fr)) auto auto;gap:12px;align-items:end;padding:14px;border:1px solid var(--line);border-radius:16px;background:rgba(255,255,255,.42)}
body.dark .billing-filter{background:rgba(15,23,42,.44)}
.billing-filter .field{gap:6px}
.billing-filter .ghost-btn,.billing-filter .pill-btn{min-height:44px}
.billing-filter-summary{grid-column:1/-1;margin:0}
.pricing-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:16px;margin-top:18px;align-items:stretch}
.pricing-card{grid-column:span 2;padding:16px;display:grid;gap:12px;align-content:start}
.pricing-card.plan-selected{border-color:rgba(99,102,241,.82);box-shadow:0 0 0 3px rgba(99,102,241,.14),0 18px 42px rgba(99,102,241,.16)}
.pricing-card.featured{grid-column:span 2;padding:22px;border-color:rgba(34,197,94,.55);box-shadow:0 18px 42px rgba(34,197,94,.16);transform:scale(1.02);z-index:1}
.pricing-card.current-plan{border-color:rgba(99,102,241,.7);box-shadow:0 14px 34px rgba(99,102,241,.16)}
.current-plan-note{padding:9px 11px;border-radius:10px;background:rgba(99,102,241,.12);color:#4f46e5;font-size:13px;font-weight:800}
.pricing-card.featured .price{font-size:36px}
.pricing-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.price{font-size:30px;font-weight:800}
.price small{font-size:13px;color:var(--muted);font-weight:700}
.payment-choice-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}
.payment-choice{position:relative;display:grid;gap:7px;padding:14px 14px 14px 42px;border:1px solid var(--line);border-radius:14px;background:var(--panel-strong);cursor:pointer}
.payment-choice input{position:absolute;left:14px;top:17px;accent-color:var(--brand)}
.payment-choice strong{font-size:14px}
.payment-choice small{color:var(--muted);line-height:1.45}
.payment-choice:has(input:checked){border-color:rgba(99,102,241,.65);box-shadow:0 0 0 3px rgba(99,102,241,.12)}
.subscription-checkout-panel{display:none;margin-top:18px}
.subscription-checkout-panel.active{display:block}
.subscription-checkout-panel .section-head{padding:0}
.feature-list{display:grid;gap:8px;font-size:14px}
.feature-list span{display:grid;grid-template-columns:18px minmax(0,1fr);gap:8px;align-items:start}
.feature-list span:before{display:inline-grid;place-items:center;width:18px;height:18px;border-radius:999px;font-size:12px;font-weight:900;line-height:1}
.feature-list .is-included{color:#15803d}
.feature-list .is-included:before{content:"\2713";background:rgba(34,197,94,.14);color:#16a34a}
.feature-list .is-excluded{color:#b91c1c}
.feature-list .is-excluded:before{content:"\00D7";background:rgba(239,68,68,.13);color:#dc2626}
body.dark .feature-list .is-included{color:#86efac}
body.dark .feature-list .is-excluded{color:#fecaca}
.wallet-table{min-width:0}
.wallet-table table{min-width:0}
.wallet-table th,.wallet-table td{padding:9px 0;font-size:13px}
.wallet-table th:last-child,.wallet-table td:last-child{text-align:right}
@media(min-width:721px){
  .pricing-card{display:flex;flex-direction:column}
  .pricing-card .billing-plan-btn{margin-top:auto}
  .pricing-card .billing-plan-btn + .muted{min-height:48px}
}
.outside-faq-list{display:grid;gap:14px}
.outside-faq-card{padding:16px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.42);display:grid;gap:14px}
body.dark .outside-faq-card{background:rgba(15,23,42,.44)}
.outside-faq-meta{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.outside-faq-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.outside-faq-grid .field.full{grid-column:1/-1}
.faq-subtabs,.integration-subtabs{display:flex;gap:8px;flex-wrap:wrap;padding:0 20px 18px;border-bottom:1px solid var(--line)}
.faq-subtab-btn,.integration-subtab-btn{border:1px solid var(--line);background:var(--panel-strong);color:var(--muted);border-radius:12px;min-height:38px;padding:0 13px;font-weight:800;cursor:pointer}
.faq-subtab-btn:hover,.faq-subtab-btn.active,.integration-subtab-btn:hover,.integration-subtab-btn.active{background:rgba(99,102,241,.12);color:var(--brand);border-color:rgba(99,102,241,.34)}
.faq-subpanel,.integration-subpanel{display:none}
.faq-subpanel.active,.integration-subpanel.active{display:block}
.faq-action-section{margin-top:16px;border-top:1px solid var(--line)}
.faq-action-list{display:grid;gap:12px;margin-top:14px}
.faq-action-card{padding:14px;border:1px solid var(--line);border-radius:16px;background:rgba(255,255,255,.42);display:grid;gap:12px}
body.dark .faq-action-card{background:rgba(15,23,42,.44)}
.help-tip{position:relative;display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;line-height:1;border-radius:999px;border:1px solid rgba(99,102,241,.35);background:rgba(99,102,241,.12);color:var(--brand);font-size:13px;font-weight:900;cursor:help;margin-left:8px;vertical-align:middle;text-align:center}
.help-tip:after{content:attr(data-tip);position:absolute;left:50%;bottom:calc(100% + 10px);transform:translateX(-50%) translateY(4px);width:min(320px,calc(100vw - 48px));padding:12px 13px;border-radius:12px;background:var(--panel-strong);border:1px solid var(--line);box-shadow:0 16px 34px rgba(15,23,42,.16);color:var(--ink);font-size:12px;font-weight:600;line-height:1.55;text-align:left;opacity:0;pointer-events:none;transition:.16s ease;z-index:9999;white-space:normal;text-transform:none;letter-spacing:0}
.help-tip:hover:after,.help-tip:focus-visible:after{opacity:1;transform:translateX(-50%) translateY(0)}
.billing-model-metric{position:relative;overflow:visible}
.billing-model-metric:has(.billing-help-tip:hover),.billing-model-metric:has(.billing-help-tip:focus-visible),.billing-model-metric:hover{z-index:3000}
.billing-model-head{display:flex!important;align-items:center;gap:8px;position:relative;width:fit-content;max-width:100%;overflow:visible}
.billing-help-tip{background:rgba(245,158,11,.18);border-color:rgba(245,158,11,.62);color:#b45309;margin-left:0;flex:0 0 auto;font-size:13px;line-height:1}
.billing-help-tip:after{left:0;right:auto;bottom:auto;top:calc(100% + 10px);transform:translateY(-4px);width:min(420px,calc(100vw - 64px));max-height:min(56vh,360px);overflow:auto;z-index:10000}
.billing-help-tip:hover:after,.billing-help-tip:focus-visible:after{transform:translateY(0)}
body.dark .billing-help-tip{background:rgba(245,158,11,.22);color:#fbbf24;border-color:rgba(251,191,36,.7)}
.faq-action-grid{display:grid;grid-template-columns:1.2fr 1fr 1.4fr .7fr auto;gap:10px;align-items:end}
.faq-action-grid .field{min-width:0}
.bulk-faq-card{margin-bottom:16px;padding:16px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.42);display:grid;gap:14px}
body.dark .bulk-faq-card{background:rgba(15,23,42,.44)}
.setup-recovery-card{margin-bottom:16px;padding:16px;border:1px solid rgba(245,158,11,.34);border-radius:18px;background:rgba(245,158,11,.10);display:grid;gap:12px}
.setup-recovery-card strong{font-size:17px}
.suggested-faq-list{display:grid;gap:8px;max-height:260px;overflow:auto;padding-right:4px}
.suggested-faq-item{padding:10px 12px;border:1px solid var(--line);border-radius:12px;background:var(--panel-strong);display:grid;gap:4px}
.suggested-faq-item span{font-weight:800;font-size:13px}
.suggested-faq-item small{color:var(--muted);line-height:1.45}
.bulk-faq-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.bulk-faq-actions input[type=file]{max-width:360px}
.default-faq-list{display:grid;gap:12px}
.default-faq-card{padding:16px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.42);display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;align-items:start}
body.dark .default-faq-card{background:rgba(15,23,42,.44)}
.default-faq-card h4{font-size:16px;margin-bottom:7px;color:var(--ink)}
.default-faq-card p{color:var(--muted);line-height:1.65;font-size:14px}
.default-faq-card small{display:block;margin-top:8px;color:var(--muted);line-height:1.45}
.default-faq-fields{display:grid;gap:10px}
.default-faq-fields textarea{min-height:76px}
.default-faq-edit-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.default-faq-edit-status{color:var(--muted);font-size:12px;font-weight:800}
.default-faq-status{display:flex;align-items:center;gap:10px;justify-content:flex-end}
.default-faq-status span{font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.bulk-report-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.5);backdrop-filter:blur(8px);z-index:70;display:none;align-items:center;justify-content:center;padding:20px}
.bulk-report-backdrop.active{display:flex}
.bulk-report-modal{width:min(980px,100%);max-height:88vh;overflow:auto;background:var(--panel-strong);color:var(--ink);border:1px solid var(--line);border-radius:20px;box-shadow:0 24px 70px rgba(15,23,42,.28)}
.bulk-report-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:18px;border-bottom:1px solid var(--line)}
.bulk-report-body{padding:18px;display:grid;gap:16px}
.bulk-report-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.bulk-report-summary .metric{box-shadow:none;border:1px solid var(--line)}
.bulk-report-table{max-height:280px;overflow:auto;border:1px solid var(--line);border-radius:14px}
.bulk-report-table table{min-width:720px}
.upi-consent-backdrop{position:fixed;inset:0;z-index:160;display:none;place-items:center;padding:20px;background:rgba(15,23,42,.58);backdrop-filter:blur(12px)}
.upi-consent-backdrop.active{display:grid}
.upi-consent-card{width:min(680px,100%);max-height:90vh;overflow:auto;background:var(--panel-strong);color:var(--ink);border:1px solid var(--line);border-radius:20px;box-shadow:0 26px 80px rgba(15,23,42,.34);padding:22px}
.upi-consent-card h3{font-size:22px;margin-bottom:8px}
.upi-consent-card p,.upi-consent-card li{color:var(--muted);line-height:1.62;font-size:14px}
.upi-consent-card ul{display:grid;gap:8px;margin:14px 0 0;padding-left:18px}
.upi-consent-check{display:flex;align-items:flex-start;gap:10px;margin-top:16px;padding:12px;border:1px solid rgba(245,158,11,.28);border-radius:14px;background:rgba(245,158,11,.1)}
.upi-consent-check input{width:auto;margin-top:3px;flex:0 0 auto}
.upi-consent-check span{font-size:13px;line-height:1.55;color:var(--ink);font-weight:700}
.upi-consent-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px;flex-wrap:wrap}
.razorpay-consent-backdrop{position:fixed;inset:0;z-index:160;display:none;place-items:center;padding:20px;background:rgba(15,23,42,.58);backdrop-filter:blur(12px)}
.razorpay-consent-backdrop.active{display:grid}
.razorpay-consent-card{width:min(680px,100%);max-height:90vh;overflow:auto;background:var(--panel-strong);color:var(--ink);border:1px solid var(--line);border-radius:20px;box-shadow:0 26px 80px rgba(15,23,42,.34);padding:22px}
.razorpay-consent-card h3{font-size:22px;margin-bottom:8px}
.razorpay-consent-card p,.razorpay-consent-card li{color:var(--muted);line-height:1.62;font-size:14px}
.razorpay-consent-card ul{display:grid;gap:8px;margin:14px 0 0;padding-left:18px}
.razorpay-consent-check{display:flex;align-items:flex-start;gap:10px;margin-top:16px;padding:12px;border:1px solid rgba(99,102,241,.28);border-radius:14px;background:rgba(99,102,241,.1)}
.razorpay-consent-check input{width:auto;margin-top:3px;flex:0 0 auto}
.razorpay-consent-check span{font-size:13px;line-height:1.55;color:var(--ink);font-weight:700}
.razorpay-consent-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px;flex-wrap:wrap}
.lead-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.lead-master{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.42);margin-top:16px}
body.dark .lead-master{background:rgba(15,23,42,.44)}
.lead-section{display:grid;gap:14px;align-content:start}
.lead-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;border-bottom:1px solid var(--line);padding-bottom:12px}
.lead-option{display:grid;gap:12px;padding:14px;border:1px solid var(--line);border-radius:16px;background:rgba(255,255,255,.36)}
body.dark .lead-option{background:rgba(15,23,42,.38)}
.lead-option-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.lead-option h4{font-size:15px}
.lead-option small{display:block;color:var(--muted);line-height:1.5;margin-top:5px}
.lead-disabled{opacity:.56}
.input-help{font-size:12px;color:var(--muted);line-height:1.5}
.input-help.error{color:#b91c1c}
.input-help.full{grid-column:1/-1}
.required-mark{color:#dc2626;font-weight:900}
.field input.input-error{border-color:#dc2626;box-shadow:0 0 0 3px rgba(220,38,38,.12)}
.toast{position:fixed;right:24px;bottom:24px;background:#111827;color:#fff;border-radius:12px;padding:12px 14px;box-shadow:0 12px 30px rgba(0,0,0,.25);opacity:0;transform:translateY(10px);pointer-events:none;transition:.25s}
.toast.show{opacity:1;transform:translateY(0)}
@media(max-width:1440px){
  .dashboard-shell{grid-template-columns:240px 1fr}
  .sidebar{padding:20px 14px}
  .tab-btn{padding:11px 12px;font-size:14px}
  .topbar{padding:0 20px;gap:12px}
  .page-title h1{font-size:22px}
  .top-actions{gap:8px}
  .pill-btn,.ghost-btn,.danger-btn{padding:0 12px}
  .content{padding:22px}
  .metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
  .easy-install-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media(max-width:1180px){
  .dashboard-shell{grid-template-columns:1fr}
  .drawer-overlay{display:block}
  .mobile-toggle{display:inline-flex;flex:0 0 auto}
  body.nav-open,body.account-open{overflow:hidden}
  .sidebar{
    position:fixed;
    top:0;
    left:0;
    width:min(320px,86vw);
    height:100dvh;
    padding:18px;
    z-index:45;
    border-right:1px solid var(--line);
    border-bottom:0;
    transform:translateX(-105%);
    transition:transform .25s ease;
    overflow-y:auto;
    display:block;
  }
  body.nav-open .sidebar{transform:translateX(0)}
  .brand{margin-bottom:18px}
  .brand img{width:50px}
  .nav-tabs{display:grid;gap:8px;overflow:visible;padding:0}
  .tab-btn{white-space:normal;min-height:44px;flex:auto;width:100%}
  .sidebar-footer{display:none}
  .topbar{position:sticky;top:0;height:auto;min-height:72px;z-index:25;padding:14px 18px}
  body.account-open .topbar{z-index:55}
  .top-actions{
    position:fixed;
    top:0;
    right:0;
    width:min(320px,86vw);
    max-width:100vw;
    height:100dvh;
    z-index:45;
    padding:72px 18px 18px;
    background:rgba(255,255,255,.9);
    border-left:1px solid var(--line);
    backdrop-filter:blur(18px);
    display:grid;
    align-content:start;
    gap:12px;
    transform:translateX(100%);
    transition:transform .25s ease;
    box-shadow:-18px 0 45px rgba(15,23,42,.12);
    visibility:hidden;
    pointer-events:none;
  }
  body.account-open #accountToggle{
    position:fixed;
    top:14px;
    right:18px;
    z-index:60;
  }
  #accountToggle{border-radius:50%;background:linear-gradient(135deg,var(--brand),var(--brand-2));color:#fff;border:0;font-size:13px}
  body.dark .top-actions{background:rgba(15,23,42,.92)}
  body.account-open .top-actions{transform:translateX(0);visibility:visible;pointer-events:auto}
  .top-actions .pill-btn,.top-actions .ghost-btn{width:100%;justify-content:center}
  .desktop-ai-upgrade{display:inline-flex}
  .mobile-ai-upgrade{display:none}
  .top-actions > .user-menu{display:grid;grid-template-columns:auto minmax(0,1fr);justify-items:stretch;text-align:left;padding:16px;gap:12px;width:100%}
  .top-actions > .user-menu .avatar{align-self:center}
  .top-actions .user-text{display:block;max-width:100%}
  .top-actions .user-text strong,.top-actions .user-text span{white-space:normal;word-break:break-word}
  .top-actions > .user-menu .ghost-btn{grid-column:1 / -1;width:100%;justify-content:center}
  .topbar-left{flex:1}
  .page-title{min-width:0}
  .page-title p{display:none}
  .metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
  .easy-install-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .overview-hero,.subscription-transfer-card,.split,.profile-grid{grid-template-columns:1fr}
  .profile-photo{justify-items:start;grid-template-columns:auto 1fr;align-items:center}
}
@media(max-width:720px){
  .topbar{padding:12px 14px;gap:8px}
  .topbar-left{gap:8px;min-width:0}
  .dashboard-mode-actions{width:100%;display:flex;align-items:center;gap:7px;min-width:0}
  .nadara-pill{min-height:36px;padding:0 10px;font-size:12px;gap:6px;flex:0 0 auto}
  .nadara-pill:before{width:8px;height:8px;box-shadow:0 0 0 3px rgba(34,197,94,.14)}
  .ai-upgrade-btn{min-height:40px;padding:5px 9px;font-size:12px;flex:1 1 auto;min-width:0;white-space:normal}
  .ai-upgrade-btn small{font-size:9px}
  .mobile-toggle{width:40px;height:40px}
  body.account-open #accountToggle{top:12px;right:14px}
  .content{padding:14px;gap:16px}
  .panel{border-radius:18px}
  .section-head{align-items:flex-start;flex-direction:column;padding:16px 16px 0}
  .section-body{padding:16px}
  .faq-subtabs,.integration-subtabs,.payment-subtabs{display:grid;grid-template-columns:1fr;padding:0 16px 16px}
  .faq-subtab-btn,.integration-subtab-btn,.payment-subtab-btn{width:100%}
  .overview-hero h2{font-size:28px}
  .metrics,.form-grid,.theme-controls,.outside-faq-grid,.faq-action-grid,.lead-grid,.analytics-grid,.analytics-grid.two,.bi-kpi-grid,.bi-dashboard-grid,.bi-dashboard-grid.three,.bi-alert-grid,.funnel,.pricing-grid,.security-grid,.bulk-report-summary,.payment-choice-grid,.billing-filter{grid-template-columns:1fr}
  .panel-actions{justify-content:stretch}
  .panel-actions .pill-btn,.panel-actions .ghost-btn,.panel-actions .danger-btn{width:100%}
  .subscription-transfer-card{padding:18px}
  .subscription-transfer-card .transfer-summary{grid-template-columns:1fr}
  .user-menu{justify-content:space-between}
  select,input,textarea{font-size:16px}
  table{min-width:640px}
  th,td{padding:11px 12px}
  .table-wrap{width:100%;max-width:100%;border-radius:0}
  .inline-row input,.inline-row .ghost-btn{flex:1 1 100%;width:100%}
  .billing-help-tip:after{position:fixed;left:16px;right:16px;top:auto;bottom:20px;width:auto;max-height:52vh;transform:translateY(10px)}
  .billing-help-tip:hover:after,.billing-help-tip:focus-visible:after{transform:translateY(0)}
  .default-faq-card{grid-template-columns:1fr}
  .default-faq-status{justify-content:space-between}
}
@media(max-width:480px){
  .sidebar{padding:12px}
  .brand{margin-bottom:10px}
  .brand img{width:40px}
  .brand strong{font-size:18px}
  .tab-btn{padding:10px 12px;font-size:14px;min-height:40px}
  .page-title h1{font-size:21px}
  .page-title p{font-size:12px;line-height:1.45}
  .overview-hero{padding:18px}
  .overview-hero h2{font-size:24px}
  .overview-hero p,.action-card p,.muted{font-size:13px}
  .metric{padding:15px}
  .metric strong{font-size:24px}
  .metric strong[style]{font-size:14px !important}
  .pill-btn,.ghost-btn,.danger-btn{min-height:42px;padding:0 12px;font-size:14px}
  .profile-photo{grid-template-columns:1fr;justify-items:center}
  .profile-avatar{width:96px;height:96px}
  .profile-prompt-grid{grid-template-columns:1fr}
  .profile-prompt-actions{display:grid}
  code{font-size:12px;padding:13px}
  table{min-width:560px}
  th{font-size:11px}
  td{font-size:13px}
  .inline-row{display:grid;grid-template-columns:1fr}
  .lead-master,.lead-section-head,.lead-option-top{align-items:flex-start}
  .lead-master{display:grid}
  .easy-install-grid{grid-template-columns:1fr}
  .toast{left:14px;right:14px;bottom:14px;text-align:center}
}
</style>
</head>
<body>
<div class="dashboard-shell">
  <div class="drawer-overlay" id="drawerOverlay" aria-hidden="true"></div>
  <div class="dashboard-loading" id="dashboardLoadingOverlay" aria-hidden="true">
    <div class="dashboard-loading-card" role="status" aria-live="polite">
      <div class="dashboard-loading-spinner" aria-hidden="true"></div>
      <strong>Loading chatbot dashboard</strong>
      <span>Please wait while the selected chatbot data loads.</span>
    </div>
  </div>
  <aside class="sidebar">
    <a class="brand" href="index.php">
      <img src="images/logo_img.png" alt="Vani AI">
      <strong>Vani AI</strong>
    </a>
    <div class="nav-tabs" role="tablist">
      <button class="tab-btn chatbot-nav-item active" data-tab="overview">Dashboard</button>
      <button class="tab-btn chatbot-nav-item" data-tab="setup">Chatbot Setup</button>
      <button class="tab-btn chatbot-nav-item" data-tab="faqs">FAQ Management</button>
      <button class="tab-btn chatbot-nav-item" data-tab="outside-faqs">Outside FAQs</button>
      <button class="tab-btn chatbot-nav-item" data-tab="feedback-received" <?php echo $canUseFaqFeedback ? '' : 'data-premium-lock="This feature is only for Growth or Business users. Please recharge your wallet with appropriate plan."'; ?>>Feedback Received</button>
      <button class="tab-btn chatbot-nav-item" data-tab="payments-collection">Payments Collection</button>
      <!-- Conversations tab hidden for now; keep this code for later.
      <button class="tab-btn" data-tab="logs">Conversations</button>
      -->
      <button class="tab-btn chatbot-nav-item" data-tab="analytics">Analytics</button>
      <button class="tab-btn chatbot-nav-item" data-tab="install">Integration</button>
      <!-- Bot Settings tab hidden for now; keep this code for later.
      <button class="tab-btn" data-tab="bot-settings">Bot Settings</button>
      -->
      <button class="tab-btn chatbot-nav-item" data-tab="lead-generation">Lead Generation Setup</button>
      <button class="tab-btn chatbot-nav-item" data-tab="subscription">Wallet Plans</button>
      <button class="tab-btn chatbot-nav-item" data-tab="profile">Profile</button>
      <button class="tab-btn chatbot-nav-item" data-tab="billing">Billing</button>
      <a class="tab-btn chatbot-nav-item" href="test-chatbot.php?bot=<?php echo h(urlencode($selectedBotId)); ?>">Test Chatbot</a>
    </div>
    <div class="sidebar-footer">
      <small>Current bot</small>
      <strong id="sidebarBotNameText"><?php echo h($botName); ?></strong>
      <!-- Bot ID hidden for now; keep this code for later.
      <small>ID: <?php echo h($selectedBotId ?: 'No bot found'); ?></small>
      -->
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <div class="topbar-left">
        <button class="mobile-toggle" id="navToggle" type="button" aria-label="Open dashboard menu" aria-expanded="false">☰</button>
        <div class="page-title">
          <div class="dashboard-mode-actions" aria-label="Dashboard type">
            <span class="nadara-pill">Narada</span>
            <a class="ai-upgrade-btn desktop-ai-upgrade" href="AI_Dashboard_Onboarding.php?bot=<?php echo h(urlencode($selectedBotId)); ?>"><span>Upgrade to Vaasu</span><small>AI driven chatbot</small></a>
          </div>
          <!--<p>Overview, setup, FAQs, logs, analytics, install, settings, and billing.</p>-->
        </div>
      </div>
      <button class="mobile-toggle" id="accountToggle" type="button" aria-label="Open account menu" aria-expanded="false"><?php echo h($initials); ?></button>
      <div class="top-actions">
        <a class="ai-upgrade-btn mobile-ai-upgrade" href="AI_Dashboard_Onboarding.php?bot=<?php echo h(urlencode($selectedBotId)); ?>"><span>Upgrade to Vaasu</span><small>AI driven chatbot</small></a>
        <button class="ghost-btn" id="themeToggle" type="button">Dark</button>
        <a class="ghost-btn" href="index.php">Home</a>
        <a class="ghost-btn" href="Customer_Manual.php">Customer Manual</a>
        <a class="ghost-btn" href="#profile" data-jump="profile">Profile</a>
        <a class="pill-btn" href="index.php">Create New bot</a>
        <div class="user-menu">
          <div class="avatar"><?php echo h($initials); ?></div>
          <div class="user-text">
            <strong><?php echo h($displayName ?: $email); ?></strong>
            <!--<span><?php echo h($accountId ?: 'Customer'); ?></span>-->
          </div>
          <a class="ghost-btn" href="logout.php">Logout</a>
        </div>
      </div>
    </header>

    <div class="content">
      <?php if (!$selectedBotId): ?>
        <section class="panel empty">
          <h2>No chatbot found yet</h2>
          <p class="muted">Create your first chatbot to unlock the dashboard overview and quick actions.</p>
          <div style="margin-top:18px"><a class="pill-btn" href="freebot.php">Create chatbot</a></div>
        </section>
      <?php endif; ?>

      <section class="tab-panel active" id="overview">
        <?php if ($chatbotSetupIncomplete): ?>
          <div class="panel setup-recovery-card">
            <strong>Finish your chatbot setup</strong>
            <p class="muted">
              This chatbot was created but setup is not complete yet.
              <?php if ($themeSetupIncomplete): ?>Theme setup is pending.<?php endif; ?>
              <?php if ($faqSetupIncomplete): ?>FAQs are pending.<?php endif; ?>
              <?php if ($selectedBusinessType !== ''): ?>We found your business type as <?php echo h($selectedBusinessType); ?> and prepared matching FAQ suggestions.<?php endif; ?>
            </p>
            <div class="inline-row">
              <?php if ($themeSetupIncomplete): ?><a class="pill-btn" href="theme-selection.php">Complete Theme</a><?php endif; ?>
              <a class="ghost-btn" href="#faqs" data-jump="faqs">Open FAQ Management</a>
            </div>
          </div>
        <?php endif; ?>
        <div class="panel overview-hero">
          <div>
            <span class="eyebrow">Your Chatbot</span>
            <h2 id="overviewBotNameText"><?php echo h($botName); ?></h2>
            <p>You are currently configuring the bot for the mentioned website.</p>
          </div>
          <form class="bot-picker" id="botPickerForm" method="get" action="dashboard.php">
            <div class="bot-picker-head">
              <label for="bot">Select Website bot</label>
              <button class="danger-btn delete-bot-mini" type="button" id="deleteChatbotBtn" data-bot-name="<?php echo h($botName); ?>">Delete</button>
            </div>
            <select id="bot" name="bot">
              <?php if (empty($bots)): ?>
                <option value="">No bots available</option>
              <?php endif; ?>
              <?php foreach ($bots as $bot): ?>
                <?php $cid = (string)($bot['customer_id'] ?? ''); ?>
                <option value="<?php echo h($cid); ?>" <?php echo $cid === $selectedBotId ? 'selected' : ''; ?>>
                  <?php echo h(($bot['website_name'] ?? 'Bot') . ' - ' . "🤖 "); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small class="muted">Select the appropriate chatbot from those created by: <?php echo h($email); ?></small>
          </form>
        </div>

        <div class="metrics">
          <div class="panel metric">
            <div class="metric-status-head">
              <span>Chatbot Status</span>
              <strong id="overviewStatusText" class="overview-status-pill <?php echo $isActive ? '' : 'inactive'; ?>"><?php echo $isActive ? 'Active' : 'Inactive'; ?></strong>
            </div>
            <div class="status-toggle-row">
              <small id="overviewStatusHelp"><?php echo $isActive ? 'Chatbot is on for customers.' : 'Chatbot is off for customers.'; ?></small>
              <label class="switch" title="Turn chatbot on or off">
                <input id="overviewActiveSwitch" type="checkbox" <?php echo $isActive ? 'checked' : ''; ?> aria-label="Turn chatbot on or off">
                <span class="switch-slider"></span>
              </label>
            </div>
          </div>
          <div class="panel metric"><span>Total FAQs</span><strong><?php echo h($faqCount); ?></strong><small>Free plan limit: <?php echo h($freeFaqLimit); ?> FAQs.</small></div>
          <div class="panel metric"><span>Total Conversations</span><strong><?php echo h($conversationCount); ?></strong><small>Meaning: Total number of chat sessions started by users</small></div>
          <div class="panel metric"><span>Today's Queries</span><strong><?php echo h($todayQueries); ?></strong><small><?php echo h(gmdate('M d, Y')); ?> UTC</small></div>
          <div class="panel metric"><span>Response Accuracy</span><strong><?php echo h($accuracy); ?>%</strong><small>Basic answered vs total estimate.</small></div>
          <div class="panel metric"><span>Last Activity</span><strong id="lastActivityText" data-last-activity="<?php echo h($lastActivity); ?>" style="font-size:18px"><?php echo h($lastActivity ?: 'No activity yet'); ?></strong><small id="lastActivityZone">Latest tracked conversation.</small></div>
          <div class="panel metric">
            <span>Chatbot Theme</span>
            <div class="chatbot-theme-preview" aria-label="Selected chatbot theme preview">
              <div class="chatbot-theme-header" id="overviewThemeBubble" style="background:<?php echo h($themeColor); ?>">
                <?php if ($chatbotImage): ?>
                  <img class="chatbot-theme-avatar" id="overviewBotImagePreview" src="<?php echo h($chatbotImage); ?>" alt="Selected chatbot image">
                <?php endif; ?>
                <div class="chatbot-theme-title">
                  <strong id="overviewThemeTitle"><?php echo h($botName); ?></strong>
                  <small>Online now</small>
                </div>
                <span class="chatbot-theme-close" aria-hidden="true">×</span>
              </div>
              <div class="chatbot-theme-body">
                <div class="chatbot-theme-row">
                  <?php if ($chatbotImage): ?><img class="chatbot-theme-mini-avatar" id="overviewBotMiniImagePreview" src="<?php echo h($chatbotImage); ?>" alt=""><?php endif; ?>
                  <div class="chatbot-theme-bubble">
                    <div id="overviewThemeMessage"><?php echo h($welcomeMessage); ?></div>
                  </div>
                </div>
                <div class="chatbot-theme-row compact">
                  <div class="chatbot-theme-bubble">
                    <span class="chatbot-theme-dots" aria-hidden="true"><i></i><i></i><i></i></span>
                  </div>
                </div>
              </div>
              <div class="chatbot-theme-input">
                <span>Type message...</span>
                <b id="overviewThemeTyping" style="background:<?php echo h($themeColor); ?>">Send</b>
              </div>
            </div>
            <small>Preview of the selected widget style.</small>
          </div>
          <div class="panel metric popular-questions-metric">
            <span>Popular Questions</span>
            <?php if (empty($topFaqQuestionCounts)): ?><p class="empty">No repeated FAQ questions yet.</p><?php endif; ?>
            <?php foreach (array_slice($topFaqQuestionCounts, 0, 5) as $item): ?>
              <div class="popular-question-row"><em><?php echo h($item['question']); ?></em><strong><?php echo h($item['count']); ?></strong></div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="panel subscription-transfer-card">
          <div class="transfer-copy">
            <div>
              <span class="eyebrow">Wallet Plan Transfer</span>
              <h3>Move this plan to another chatbot</h3>
              <p class="muted">Transfer the current paid wallet plan and wallet balance from this chatbot to another chatbot created under <?php echo h($email); ?>.</p>
            </div>
            <p class="transfer-warning"><strong>Important:</strong> this is a transfer, not sharing. After transfer, this chatbot moves to Free service and paid toggles are turned off here.</p>
            <div class="transfer-summary">
              <span>Current plan<strong><?php echo h($activePlan['name']); ?></strong></span>
              <span>Wallet balance<strong><?php echo h(billing_rupees($billingWalletPaise)); ?></strong></span>
            </div>
          </div>
          <div class="transfer-form">
            <div class="field">
              <label for="transferSubscriptionTarget">Transfer to chatbot</label>
              <select id="transferSubscriptionTarget" <?php echo $activePlanId === 'free' ? 'disabled' : ''; ?>>
                <option value="">Select target chatbot</option>
                <?php foreach ($bots as $bot): ?>
                  <?php $cid = (string)($bot['customer_id'] ?? ''); ?>
                  <?php if ($cid === '' || $cid === $selectedBotId) { continue; } ?>
                  <option value="<?php echo h($cid); ?>"><?php echo h(($bot['website_name'] ?? 'Bot') . ' - ' . $cid); ?></option>
                <?php endforeach; ?>
              </select>
              <small class="input-help">
                <?php echo $activePlanId === 'free' ? 'No paid plan is available to transfer.' : 'Target chatbot must be on Free service.'; ?>
              </small>
            </div>
            <button class="pill-btn" type="button" id="transferSubscriptionBtn" <?php echo $activePlanId === 'free' || count($bots) < 2 ? 'disabled' : ''; ?>>Transfer Subscription</button>
          </div>
        </div>
      </section>

      <section class="tab-panel" id="setup">
        <div class="panel">
          <div class="section-head"><h3>Chatbot Setup</h3></div>
          <div class="section-body form-grid">
            <input type="hidden" id="settingsCustomerId" value="<?php echo h($selectedBotId); ?>">
            <div class="field"><label>Bot Name</label><input id="botNameInput" value="<?php echo h($botName); ?>"></div>
            <div class="field"><label>Position</label><select id="positionInput"><option <?php echo $position === 'right' ? 'selected' : ''; ?>>right</option><option <?php echo $position === 'left' ? 'selected' : ''; ?>>left</option></select></div>
            <div class="field">
              <label>Open chat by default</label>
              <div class="inline-row" style="justify-content:space-between">
                <span class="input-help">Open the chatbot message box automatically when visitors land on the website.</span>
                <label class="switch" title="Open chatbot by default">
                  <input id="chatOpenDefaultToggle" type="checkbox" <?php echo $chatOpenByDefault ? 'checked' : ''; ?> aria-label="Open chatbot by default">
                  <span class="switch-slider"></span>
                </label>
              </div>
            </div>
            <div class="field">
              <label>User typing field</label>
              <div class="inline-row" style="justify-content:space-between">
                <span class="input-help">When OFF, visitors choose from FAQ category and FAQ question buttons only.</span>
                <label class="switch" title="Allow visitors to type messages">
                  <input id="userInputEnabledToggle" type="checkbox" <?php echo $userInputEnabled ? 'checked' : ''; ?> aria-label="Allow visitors to type messages">
                  <span class="switch-slider"></span>
                </label>
              </div>
            </div>
            <div class="field full"><label>Welcome Message</label><textarea id="welcomeInput"><?php echo h($welcomeMessage); ?></textarea></div>
            <div class="field"><label>Language</label><select id="languageInput"><option><?php echo h($language); ?></option><option>English</option><option>Hindi</option><option>Spanish</option><option>French</option></select></div>
            <div class="field full">
              <label>Theme color</label>
              <div class="theme-designer">
                <input id="themeColorInput" type="hidden" value="<?php echo h($themeColor); ?>">
                <input id="themePatternInput" type="hidden" value="<?php echo h($themePattern); ?>">
                <div class="theme-preview-box" id="themePreviewBox" style="background:<?php echo h($themeColor); ?>">Selected theme</div>
                <div class="theme-controls">
                  <div class="field">
                    <label>Solid color</label>
                    <input id="themeSolidColorInput" type="color" value="<?php echo h($themeColorInputValue); ?>">
                  </div>
                  <div class="field">
                    <label>Gradient type</label>
                    <select id="themeGradientType"><option value="linear">Linear</option><option value="radial">Circular</option></select>
                  </div>
                  <div class="field">
                    <label>Direction</label>
                    <select id="themeGradientDirection"><option value="135deg">Diagonal</option><option value="90deg">Left to right</option><option value="180deg">Top to bottom</option><option value="45deg">Soft angle</option><option value="circle">Circle</option></select>
                  </div>
                  <div class="field">
                    <label>Gradient colors</label>
                    <div class="theme-color-grid">
                      <input class="themeGradientColor" type="color" value="#6366f1">
                      <input class="themeGradientColor" type="color" value="#06b6d4">
                      <input class="themeGradientColor" type="color" value="#10b981">
                      <input class="themeGradientColor" type="color" value="#f59e0b">
                      <input class="themeGradientColor" type="color" value="#ef4444">
                      <input class="themeGradientColor" type="color" value="#ec4899">
                      <input class="themeGradientColor" type="color" value="#7c3aed">
                      <input class="themeGradientColor" type="color" value="#111827">
                    </div>
                  </div>
                </div>
                <div class="field full">
                  <label>Quick theme boxes</label>
                  <div class="theme-color-grid" id="themeColorGrid"></div>
                </div>
                <div class="field full">
                  <label>Pattern theme</label>
                  <div class="pattern-grid" id="themePatternGrid"></div>
                </div>
              </div>
            </div>
            <div class="field full">
              <label>Chatbot image</label>
              <?php if ($chatbotImage): ?>
                <img class="selected-bot-image" id="selectedBotImagePreview" src="<?php echo h($chatbotImage); ?>" alt="Selected chatbot image">
              <?php endif; ?>
              <div class="bot-image-grid" id="dashboardBotImageGrid">
                <?php foreach ($botImages as $index => $image): ?>
                  <label class="bot-image-option" title="Chatbot image <?php echo h($index + 1); ?>">
                    <input type="radio" name="dashboardBotImage" value="<?php echo h($image); ?>" <?php echo $image === $chatbotImage || (!$chatbotImage && $index === 0) ? 'checked' : ''; ?>>
                    <img src="<?php echo h($image); ?>" alt="Chatbot image <?php echo h($index + 1); ?>">
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="panel-actions setup-autosave-actions">
              <span class="input-help" id="setupAutosaveStatus">Changes save automatically.</span>
            </div>
          </div>
        </div>
      </section>

      <section class="tab-panel" id="faqs">
        <div class="panel">
          <div class="section-head"><h3>FAQ Management</h3><span class="tag" id="faqCountTag"><?php echo h($faqCount); ?>/<?php echo h($freeFaqLimit); ?> FAQs</span></div>
          <div class="faq-subtabs" role="tablist" aria-label="FAQ Management sections">
            <button class="faq-subtab-btn active" type="button" data-faq-subtab="faq-subpanel-options">Options</button>
            <button class="faq-subtab-btn" type="button" data-faq-subtab="faq-subpanel-default">Default FAQs</button>
            <button class="faq-subtab-btn" type="button" data-faq-subtab="faq-subpanel-qa">FAQ Q&amp;A</button>
            <button class="faq-subtab-btn" type="button" data-faq-subtab="faq-subpanel-scheduled">Scheduled Actions</button>
            <button class="faq-subtab-btn" type="button" data-faq-subtab="faq-subpanel-feedback-type" <?php echo $canUseFaqFeedback ? '' : 'data-premium-lock="This feature is only for Growth or Business users. Please recharge your wallet with appropriate plan."'; ?>>Collect Feedback From Users</button>
          </div>
          <div class="section-body faq-action-section faq-subpanel active" id="faq-subpanel-options" style="border-top:0;margin-top:0">
            <div class="inline-row" style="justify-content:space-between;gap:16px;margin-bottom:14px">
              <div>
                <h3>FAQ Category Public Menu</h3>
                <small class="input-help">When ON, the chatbot first shows FAQ categories so visitors can browse by category before asking a question.</small>
              </div>
              <label class="switch" title="Enable FAQ category public menu">
                <input id="faqCategoryMenuToggle" type="checkbox" <?php echo $faqCategoryMenuEnabled ? 'checked' : ''; ?> aria-label="Enable FAQ category public menu">
                <span class="switch-slider"></span>
              </label>
            </div>
            <div class="inline-row" style="justify-content:space-between;gap:16px;margin-bottom:14px">
              <div>
                <h3>FAQ Action Suggestions</h3>
                <small class="input-help">Show action buttons after a matched FAQ answer, such as call, email, WhatsApp, booking, coupon, map, form, or related FAQ category.</small>
                <?php if (!$canUseFaqActionSuggestions): ?><small class="input-help error">Starter, Growth, or Business plan required.</small><?php endif; ?>
              </div>
              <label class="switch" title="Enable FAQ action suggestions">
                <input id="faqActionsToggle" type="checkbox" <?php echo $faqActionsEnabled && $canUseFaqActionSuggestions ? 'checked' : ''; ?> <?php echo $canUseFaqActionSuggestions ? '' : 'disabled'; ?> aria-label="Enable FAQ action suggestions">
                <span class="switch-slider"></span>
              </label>
            </div>

            <form id="faqActionForm" class="faq-action-card">
              <input type="hidden" id="faqActionCustomerId" value="<?php echo h($selectedBotId); ?>">
              <div class="faq-action-grid">
                <div class="field">
                  <label>FAQ</label>
                  <select id="faqActionFaqId" <?php echo $canUseFaqActionSuggestions ? '' : 'disabled'; ?>>
                    <option value="">Select FAQ</option>
                    <?php foreach ($faqs as $faq): ?>
                      <option value="<?php echo h($faq['id'] ?? ''); ?>"><?php echo h($faq['question'] ?? ''); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="field">
                  <label>Button label</label>
                  <input id="faqActionLabel" placeholder="Book demo" maxlength="80" <?php echo $canUseFaqActionSuggestions ? '' : 'disabled'; ?>>
                </div>
                <div class="field">
                  <label>Action type</label>
                  <select id="faqActionType" <?php echo $canUseFaqActionSuggestions ? '' : 'disabled'; ?>>
                    <option value="link">Open page / product page</option>
                    <option value="whatsapp">Open WhatsApp</option>
                    <option value="call">Call now</option>
                    <option value="email">Send email</option>
                    <option value="download">Download file</option>
                    <option value="coupon">Copy coupon/code</option>
                    <option value="booking">Book appointment</option>
                    <option value="map">Open map location</option>
                    <option value="form">Show enquiry form</option>
                    <option value="track_order">Track order / status link</option>
                    <option value="category">Show FAQ category</option>
                    <option value="payment">Collect payment</option>
                    <option value="event">Website event</option>
                  </select>
                </div>
                <div class="field">
                  <label>Order</label>
                  <input id="faqActionOrder" type="number" min="0" max="999" value="0" <?php echo $canUseFaqActionSuggestions ? '' : 'disabled'; ?>>
                </div>
                <button class="pill-btn" type="submit" <?php echo $canUseFaqActionSuggestions ? '' : 'disabled'; ?>>Add action</button>
                <div class="field full" style="grid-column:1/-1">
                  <label>Action value</label>
                  <input id="faqActionValue" list="paymentActionValueList" placeholder="https://example.com/demo, +919876543210, or customEventName" <?php echo $canUseFaqActionSuggestions ? '' : 'disabled'; ?>>
                  <datalist id="paymentActionValueList">
                    <?php foreach ($paymentActionRows as $paymentAction): ?>
                      <?php if (filter_var($paymentAction['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN)): ?>
                        <option value="<?php echo h($paymentAction['id'] ?? ''); ?>"><?php echo h(($paymentAction['label'] ?? 'Payment') . ' - ' . billing_rupees((int)($paymentAction['amount_paise'] ?? 0))); ?></option>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </datalist>
                  <small class="input-help" id="faqActionValueHelp">Choose an action type to see the required value format.</small>
                </div>
              </div>
            </form>

            <div class="faq-action-list" id="faqActionList">
              <?php if (empty($faqActionRows)): ?><p class="empty">No FAQ actions configured yet.</p><?php endif; ?>
              <?php foreach ($faqActionRows as $actionRow): ?>
                <?php $linkedFaq = $faqById[(string)($actionRow['faq_id'] ?? '')] ?? []; ?>
                <?php $actionActive = filter_var($actionRow['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN); ?>
                <div class="faq-action-card" data-faq-action-id="<?php echo h($actionRow['id'] ?? ''); ?>">
                  <div class="faq-action-grid">
                    <div><label>FAQ</label><strong><?php echo h($linkedFaq['question'] ?? 'Deleted FAQ'); ?></strong></div>
                    <div><label>Label</label><span><?php echo h($actionRow['label'] ?? ''); ?></span></div>
                    <div><label>Type</label><span class="tag"><?php echo h($actionRow['action_type'] ?? 'link'); ?></span></div>
                    <div><label>Status</label><span class="tag <?php echo $actionActive ? 'good' : 'bad'; ?>"><?php echo $actionActive ? 'Active' : 'Off'; ?></span></div>
                    <button class="danger-btn faq-action-delete-btn" type="button">Delete</button>
                    <div style="grid-column:1/-1"><small class="input-help"><?php echo h($actionRow['action_value'] ?? ''); ?></small></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="faq-action-card" style="display:none">
              <div class="inline-row" style="justify-content:space-between;gap:16px">
                <div>
                  <h3>FAQ Action Feedback</h3>
                  <small class="input-help">Ask visitors for quick feedback after they use selected FAQ action suggestions.</small>
                </div>
                <label class="switch" title="Enable FAQ action feedback">
                  <input id="faqFeedbackToggleLegacy" type="checkbox" <?php echo $faqFeedbackEnabled ? 'checked' : ''; ?> aria-label="Enable FAQ action feedback">
                  <span class="switch-slider"></span>
                </label>
              </div>
              <div class="faq-action-list" style="margin-top:14px">
                <?php if (empty($faqActionRows)): ?>
                  <p class="empty">Add FAQ Action Suggestions above, then select which actions should collect feedback.</p>
                <?php endif; ?>
                <?php foreach ($faqActionRows as $actionRow): ?>
                  <?php $linkedFaq = $faqById[(string)($actionRow['faq_id'] ?? '')] ?? []; ?>
                  <?php $actionId = (string)($actionRow['id'] ?? ''); ?>
                  <div class="lead-option">
                    <div class="inline-row" style="justify-content:space-between;gap:12px">
                      <div>
                        <strong><?php echo h($actionRow['label'] ?? 'FAQ action'); ?></strong>
                        <small class="input-help"><?php echo h($linkedFaq['question'] ?? 'Deleted FAQ'); ?> · <?php echo h($actionRow['action_type'] ?? 'link'); ?></small>
                      </div>
                      <label class="switch" title="Collect feedback after this action">
                        <input class="faqFeedbackActionLegacy" type="checkbox" value="<?php echo h($actionId); ?>" <?php echo in_array($actionId, $faqFeedbackActionIds, true) ? 'checked' : ''; ?> aria-label="Collect feedback after <?php echo h($actionRow['label'] ?? 'FAQ action'); ?>">
                        <span class="switch-slider"></span>
                      </label>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

          </div>

          <div class="section-body faq-subpanel" id="faq-subpanel-default">
            <div class="inline-row" style="justify-content:space-between;gap:16px;margin-bottom:16px">
              <div>
                <h3>Default FAQs</h3>
                <small class="input-help">These built-in answers work even before the customer adds detailed FAQs. Turn OFF any default answer that should not appear in the chatbot.</small>
              </div>
              <span class="tag">Auto-saved</span>
            </div>
            <div class="default-faq-list" id="defaultFaqList">
              <?php foreach ($defaultFaqDefinitions as $defaultFaqKey => $defaultFaq): ?>
                <?php $savedDefaultFaq = $defaultFaqSettings[$defaultFaqKey] ?? ['enabled' => true, 'question' => $defaultFaq['question'], 'answer' => $defaultFaq['answer']]; ?>
                <?php $defaultFaqEnabled = !empty($savedDefaultFaq['enabled']); ?>
                <div class="default-faq-card" data-default-faq-key="<?php echo h($defaultFaqKey); ?>" data-saved-question="<?php echo h($savedDefaultFaq['question']); ?>" data-saved-answer="<?php echo h($savedDefaultFaq['answer']); ?>">
                  <div class="default-faq-fields">
                    <div class="field">
                      <label>Question</label>
                      <input class="defaultFaqQuestion" value="<?php echo h($savedDefaultFaq['question']); ?>" maxlength="240">
                    </div>
                    <div class="field">
                      <label>Answer</label>
                      <textarea class="defaultFaqAnswer" maxlength="1200"><?php echo h($savedDefaultFaq['answer']); ?></textarea>
                    </div>
                    <div class="default-faq-edit-actions">
                      <button class="pill-btn defaultFaqSaveBtn" type="button" disabled>Save changes</button>
                      <span class="default-faq-edit-status">Saved</span>
                    </div>
                    <small><?php echo h($defaultFaq['note']); ?></small>
                  </div>
                  <div class="default-faq-status">
                    <span class="default-faq-state"><?php echo $defaultFaqEnabled ? 'ON' : 'OFF'; ?></span>
                    <label class="switch" title="Turn this default FAQ on or off">
                      <input class="defaultFaqToggle" type="checkbox" <?php echo $defaultFaqEnabled ? 'checked' : ''; ?> aria-label="Enable default FAQ: <?php echo h($defaultFaq['question']); ?>">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="section-body faq-subpanel" id="faq-subpanel-scheduled">
            <div class="faq-action-card">
              <div class="inline-row" style="justify-content:space-between;gap:16px">
                <div>
                  <h3>Scheduled FAQ Action Suggestions <span class="help-tip" tabindex="0" aria-label="How scheduled FAQ action suggestions work" data-tip="Use this when you want to promote an action after a visitor asks a set number of questions. Set three slots, such as 3, 5, and 5. After the 3rd answer the first action appears. After 5 more questions the second action appears. After 5 more questions the third action appears. Then the cycle starts again. If the visitor ignores the action and asks another question, it disappears.">?</span></h3>
                  <small class="input-help">Show one customer action after a visitor completes a configured number of questions. The three slots repeat in order, for example 3, then 5, then 5 questions.</small>
                  <small class="input-help">Available for Starter, Growth, and Business plans.</small>
                  <?php if (!$canUseFaqActionSuggestions): ?><small class="input-help error">Starter, Growth, or Business plan required.</small><?php endif; ?>
                </div>
                <button class="pill-btn" type="button" id="saveScheduledFaqActionsBtn" <?php echo $canUseFaqActionSuggestions ? '' : 'disabled'; ?>>Save schedule</button>
              </div>
              <div class="faq-action-list" id="scheduledFaqActionList">
                <?php for ($slot = 1; $slot <= 3; $slot++): ?>
                  <?php $scheduled = $scheduledFaqActionsBySlot[$slot] ?? []; ?>
                  <div class="faq-action-card scheduled-faq-action-card" data-slot-no="<?php echo h($slot); ?>">
                    <div class="faq-action-grid">
                      <div class="field">
                        <label>Option <?php echo h($slot); ?> after questions</label>
                        <input class="scheduledActionAfter" type="number" min="1" max="50" value="<?php echo h($scheduled['trigger_after_questions'] ?? ($slot === 1 ? 3 : 5)); ?>" <?php echo $canUseFaqActionSuggestions ? '' : 'disabled'; ?>>
                      </div>
                      <div class="field">
                        <label>Button label</label>
                        <input class="scheduledActionLabel" maxlength="80" placeholder="Book demo" value="<?php echo h($scheduled['label'] ?? ''); ?>" <?php echo $canUseFaqActionSuggestions ? '' : 'disabled'; ?>>
                      </div>
                      <div class="field">
                        <label>Action type</label>
                        <select class="scheduledActionType" <?php echo $canUseFaqActionSuggestions ? '' : 'disabled'; ?>>
                          <?php $scheduledType = (string)($scheduled['action_type'] ?? 'link'); ?>
                          <?php foreach (['link' => 'Open page / product page', 'whatsapp' => 'Open WhatsApp', 'call' => 'Call now', 'email' => 'Send email', 'download' => 'Download file', 'coupon' => 'Copy coupon/code', 'booking' => 'Book appointment', 'map' => 'Open map location', 'form' => 'Show enquiry form', 'track_order' => 'Track order / status link', 'category' => 'Show FAQ category', 'payment' => 'Collect payment', 'event' => 'Website event'] as $type => $label): ?>
                            <option value="<?php echo h($type); ?>" <?php echo $scheduledType === $type ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="field">
                        <label>Status</label>
                        <label class="switch" title="Enable this scheduled action">
                          <input class="scheduledActionActive" type="checkbox" <?php echo filter_var($scheduled['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'checked' : ''; ?> <?php echo $canUseFaqActionSuggestions ? '' : 'disabled'; ?> aria-label="Enable scheduled action <?php echo h($slot); ?>">
                          <span class="switch-slider"></span>
                        </label>
                      </div>
                      <div class="field full" style="grid-column:1/-1">
                        <label>Action value</label>
                        <input class="scheduledActionValue" placeholder="https://example.com/demo, +919876543210, or coupon code" value="<?php echo h($scheduled['action_value'] ?? ''); ?>" <?php echo $canUseFaqActionSuggestions ? '' : 'disabled'; ?>>
                        <small class="input-help">If enabled, label and value are required. The visitor sees this action after the configured question count.</small>
                      </div>
                    </div>
                  </div>
                <?php endfor; ?>
              </div>
            </div>
          </div>
          <?php if ($canUseFaqFeedback): ?>
          <div class="section-body faq-subpanel" id="faq-subpanel-feedback-type">
            <div class="faq-action-card">
              <div class="inline-row" style="justify-content:space-between;gap:16px">
                <div>
                  <h3>FAQ Action Feedback</h3>
                  <small class="input-help">Ask visitors for quick feedback after they use selected FAQ action suggestions.</small>
                </div>
                <label class="switch" title="Enable FAQ action feedback">
                  <input id="faqFeedbackToggle" type="checkbox" <?php echo $faqFeedbackEnabled ? 'checked' : ''; ?> aria-label="Enable FAQ action feedback">
                  <span class="switch-slider"></span>
                </label>
              </div>
              <div class="faq-action-grid" style="margin-top:14px">
                <?php
                  $feedbackTypes = [
                      'stars' => ['Star rating', 'Visitors choose 1 to 5 stars.'],
                      'emoji' => ['Smiles / emojis', 'Visitors choose a quick mood reaction.'],
                      'labels' => ['Great / Helpful / Okay', 'Visitors choose from five clear text options.'],
                      'slider' => ['Satisfaction slider', 'Visitors drag a 1 to 10 satisfaction bar.'],
                      'comment' => ['Comment feedback', 'Visitors type a short written comment.']
                  ];
                ?>
                <?php foreach ($feedbackTypes as $typeKey => [$typeLabel, $typeHelp]): ?>
                  <label class="lead-option" style="cursor:pointer">
                    <div class="inline-row" style="justify-content:space-between;gap:12px">
                      <div>
                        <strong><?php echo h($typeLabel); ?></strong>
                        <small class="input-help"><?php echo h($typeHelp); ?></small>
                      </div>
                      <input class="faqFeedbackType" type="radio" name="faqFeedbackType" value="<?php echo h($typeKey); ?>" <?php echo $faqFeedbackType === $typeKey ? 'checked' : ''; ?> aria-label="<?php echo h($typeLabel); ?>">
                    </div>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="faq-action-card">
              <h3>Select FAQ actions for feedback</h3>
              <small class="input-help">Feedback appears only after the selected FAQ Action Suggestions are clicked or submitted.</small>
              <div class="faq-action-list" style="margin-top:14px">
                <?php if (empty($faqActionRows)): ?>
                  <p class="empty">Add FAQ Action Suggestions in Options, then select which actions should collect feedback.</p>
                <?php endif; ?>
                <?php foreach ($faqActionRows as $actionRow): ?>
                  <?php $linkedFaq = $faqById[(string)($actionRow['faq_id'] ?? '')] ?? []; ?>
                  <?php $actionId = (string)($actionRow['id'] ?? ''); ?>
                  <div class="lead-option">
                    <div class="inline-row" style="justify-content:space-between;gap:12px">
                      <div>
                        <strong><?php echo h($actionRow['label'] ?? 'FAQ action'); ?></strong>
                        <small class="input-help"><?php echo h($linkedFaq['question'] ?? 'Deleted FAQ'); ?> | <?php echo h($actionRow['action_type'] ?? 'link'); ?></small>
                      </div>
                      <label class="switch" title="Collect feedback after this action">
                        <input class="faqFeedbackAction" type="checkbox" value="<?php echo h($actionId); ?>" <?php echo in_array($actionId, $faqFeedbackActionIds, true) ? 'checked' : ''; ?> aria-label="Collect feedback after <?php echo h($actionRow['label'] ?? 'FAQ action'); ?>">
                        <span class="switch-slider"></span>
                      </label>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <div class="faq-subpanel" id="faq-subpanel-qa">
          <div class="section-body">
            <?php if ($faqFreezeActive): ?>
              <div class="notice" style="margin-bottom:16px">
                <strong><?php echo h($activePlan['name']); ?> FAQ limit active:</strong><br>
                Your first <?php echo h($displayFaqLimit); ?> FAQs are active. <?php echo h($frozenFaqCount); ?> extra FAQs are frozen and saved here. Starter unfreezes 100, Growth unfreezes 300, and Business unfreezes all FAQs.
              </div>
            <?php endif; ?>
            <div class="bulk-faq-card">
              <div class="inline-row" style="justify-content:space-between;gap:16px">
                <div>
                  <h3>Bulk Upload FAQs</h3>
                  <small class="input-help">Upload Excel only. Use columns: Question, Answer, Category. Starter saves up to 100 total FAQs, Growth up to 300 total FAQs, and Business saves all uploaded FAQs.</small>
                  <small class="input-help">After upload, a temporary report appears. Closing it clears the report from this page. Export is available in Excel format only.</small>
                </div>
                <a class="ghost-btn" href="#" id="downloadFaqSampleBtn">Download sample Excel</a>
              </div>
              <div class="bulk-faq-actions">
                <input id="bulkFaqFileInput" type="file" accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel">
                <button class="pill-btn" type="button" id="bulkFaqUploadBtn">Upload Excel FAQs</button>
              </div>
            </div>
            <?php if ($faqSetupIncomplete): ?>
              <div class="setup-recovery-card">
                <strong>FAQ setup is pending</strong>
                <p class="muted">
                  <?php if ($selectedBusinessType !== ''): ?>
                    These suggested FAQs are based on the business type selected during chatbot creation: <?php echo h($selectedBusinessType); ?>.
                  <?php else: ?>
                    Add FAQs below to complete the chatbot setup.
                  <?php endif; ?>
                </p>
                <?php if (!empty($suggestedFaqRows)): ?>
                  <div class="suggested-faq-list" id="suggestedFaqList">
                    <?php foreach ($suggestedFaqRows as $suggestedFaq): ?>
                      <div class="suggested-faq-item" data-question="<?php echo h($suggestedFaq['question'] ?? ''); ?>" data-answer="<?php echo h($suggestedFaq['answer'] ?? ''); ?>" data-category="<?php echo h($selectedBusinessType ?: 'General'); ?>">
                        <span><?php echo h($suggestedFaq['question'] ?? ''); ?></span>
                        <small><?php echo h($suggestedFaq['answer'] ?? ''); ?></small>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <div class="inline-row">
                    <button class="pill-btn" type="button" id="addSuggestedFaqsBtn">Add Suggested FAQs</button>
                    <small class="input-help">You can edit these FAQs after adding them.</small>
                  </div>
                <?php else: ?>
                  <small class="input-help">No ready-made FAQ template was found for this business type. You can add your FAQs manually below.</small>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <form id="faqForm" class="form-grid">
              <input type="hidden" id="faqCustomerId" value="<?php echo h($selectedBotId); ?>">
              <div class="field"><label>Question</label><input id="faqQuestion" placeholder="What do you want customers to ask?"></div>
              <div class="field"><label>Category</label><input id="faqCategory" placeholder="General"></div>
              <div class="field full"><label>Answer</label><textarea id="faqAnswer" placeholder="Write a helpful answer"></textarea></div>
              <div class="field full"><button class="pill-btn" type="submit">Add FAQ</button></div>
            </form>
          </div>
          <div class="section-body" style="padding-top:0">
            <div class="inline-row" style="margin-bottom:14px">
              <input id="faqSearch" placeholder="Search FAQs">
            </div>
            <div class="table-wrap">
              <table id="faqTable">
                <thead><tr><th>Question</th><th>Answer</th><th>Category</th><th>Actions</th></tr></thead>
                <tbody>
                  <?php foreach ($faqs as $faq): ?>
                    <?php $faqFrozen = $faqFreezeActive && empty($faqActiveIds[(string)($faq['id'] ?? '')]); ?>
                    <tr data-faq-id="<?php echo h($faq['id'] ?? ''); ?>" <?php echo $faqFrozen ? 'data-frozen="true"' : ''; ?>>
                      <td>
                        <span class="faq-display"><?php echo h($faq['question'] ?? ''); ?> <?php if ($faqFrozen): ?><span class="tag bad">Frozen</span><?php endif; ?></span>
                        <textarea class="faq-edit-field faq-question-input" aria-label="FAQ question"><?php echo h($faq['question'] ?? ''); ?></textarea>
                      </td>
                      <td>
                        <span class="faq-display"><?php echo h($faq['answer'] ?? ''); ?></span>
                        <textarea class="faq-edit-field faq-answer-input" aria-label="FAQ answer"><?php echo h($faq['answer'] ?? ''); ?></textarea>
                      </td>
                      <td>
                        <span class="tag faq-display"><?php echo h($faq['category'] ?? 'General'); ?></span>
                        <input class="faq-edit-field faq-category-input" value="<?php echo h($faq['category'] ?? 'General'); ?>" aria-label="FAQ category">
                      </td>
                      <td>
                        <div class="faq-actions">
                          <button class="ghost-btn faq-edit-btn" type="button">Edit</button>
                          <button class="pill-btn faq-save-btn faq-edit-field" type="button">Save</button>
                          <button class="ghost-btn faq-cancel-btn faq-edit-field" type="button">Cancel</button>
                          <button class="danger-btn faq-delete-btn" type="button">Delete</button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          </div>
        </div>
      </section>

      <section class="tab-panel" id="outside-faqs">
        <div class="panel">
          <div class="section-head">
            <div>
              <h3>Questions Outside FAQs</h3>
              <p class="muted">Review questions the chatbot could not answer, edit the question if needed, write the right answer, and save it into FAQs.</p>
            </div>
            <span class="tag bad"><?php echo h($unansweredCount); ?> unanswered</span>
          </div>
          <div class="section-body">
            <div class="notice" style="margin-bottom:16px">
              <div class="inline-row" style="justify-content:space-between;gap:16px">
                <div>
                  <strong>Human handoff / ticket creation</strong><br>
                  <span class="muted">When the bot cannot answer, create a support ticket and email the question to your team.</span>
                  <?php if (!$canUseHumanHandoff): ?><br><small class="input-help error">Growth or Business plan required.</small><?php endif; ?>
                </div>
                <label class="switch" title="Enable human handoff">
                  <input id="humanHandoffToggle" type="checkbox" <?php echo $handoffEnabled && $canUseHumanHandoff ? 'checked' : ''; ?> aria-label="Enable human handoff">
                  <span class="switch-slider"></span>
                </label>
              </div>
              <div class="field" style="margin-top:12px">
                <label>Support email</label>
                <div class="inline-row">
                  <input id="humanHandoffEmailInput" type="email" value="<?php echo h($handoffEmail); ?>" placeholder="<?php echo h($email); ?>" <?php echo $canUseHumanHandoff ? '' : 'disabled'; ?>>
                  <button class="pill-btn" type="button" id="saveHumanHandoffBtn" <?php echo $canUseHumanHandoff ? '' : 'disabled'; ?>>Save handoff</button>
                </div>
                <small class="input-help">Tickets are created only for unanswered chatbot questions and only while this switch is ON.</small>
              </div>
            </div>
            <?php if (empty($outsideFaqQuestions)): ?>
              <p class="empty">No outside-FAQ questions yet.</p>
            <?php else: ?>
              <div class="outside-faq-list">
                <?php foreach ($outsideFaqQuestions as $index => $item): ?>
                  <form class="outside-faq-card outsideFaqForm">
                    <div class="outside-faq-meta">
                      <span class="tag bad">Needs answer</span>
                      <small class="muted"><?php echo h($item['created_at'] ?: 'Time not recorded'); ?></small>
                    </div>
                    <?php if (!empty($item['bot_response'])): ?>
                      <div class="notice"><strong>Bot response:</strong><br><?php echo h($item['bot_response']); ?></div>
                    <?php endif; ?>
                    <div class="outside-faq-grid">
                      <input type="hidden" class="outsideCustomerId" value="<?php echo h($selectedBotId); ?>">
                      <div class="field full">
                        <label>Edit question</label>
                        <input class="outsideQuestion" value="<?php echo h($item['question']); ?>" aria-label="Edit unanswered customer question">
                      </div>
                      <div class="field full">
                        <label>Answer for this question</label>
                        <textarea class="outsideAnswer" placeholder="Write the answer customers should receive next time"></textarea>
                      </div>
                      <div class="field">
                        <label>Category</label>
                        <input class="outsideCategory" value="General">
                      </div>
                      <div class="field">
                        <label>&nbsp;</label>
                        <button class="pill-btn" type="submit">Add to FAQs</button>
                      </div>
                    </div>
                  </form>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <?php if ($canUseFaqFeedback): ?>
      <section class="tab-panel" id="feedback-received">
        <div class="panel section-body">
          <div class="section-head" style="padding:0">
            <div>
              <span class="eyebrow">Feedback Received</span>
              <h3 style="margin-top:8px">Collected User Feedback</h3>
              <p class="muted">Feedback submitted after FAQ Action Suggestions appears here.</p>
            </div>
            <div class="inline-row" style="justify-content:flex-end;gap:12px">
              <span class="tag"><?php echo h($feedbackDisplayCount); ?> shown</span>
              <label class="switch" title="Receive feedback via email">
                <input id="feedbackEmailToggle" type="checkbox" <?php echo $faqFeedbackEmailEnabled ? 'checked' : ''; ?> aria-label="Receive feedback via email">
                <span class="switch-slider"></span>
              </label>
            </div>
          </div>
          <small class="input-help">Receive Feedback via email sends new feedback to the chatbot owner email.</small>

          <form class="analytics-filter-form" method="get" action="dashboard.php#feedback-received" style="margin-top:18px">
            <?php if ($selectedBotId): ?><input type="hidden" name="bot" value="<?php echo h($selectedBotId); ?>"><?php endif; ?>
            <input type="hidden" name="analytics_range" value="<?php echo h($analyticsRange); ?>">
            <input type="hidden" name="date_from" value="<?php echo h($analyticsFrom); ?>">
            <input type="hidden" name="date_to" value="<?php echo h($analyticsTo); ?>">
            <div class="field">
              <label>From date</label>
              <input type="date" name="feedback_from" value="<?php echo h($feedbackFromInput); ?>">
            </div>
            <div class="field">
              <label>To date</label>
              <input type="date" name="feedback_to" value="<?php echo h($feedbackToInput); ?>">
            </div>
            <div class="analytics-filter-actions">
              <button class="pill-btn" type="submit">Apply</button>
              <a class="ghost-btn" href="dashboard.php?<?php echo h(http_build_query(array_filter(['bot' => $selectedBotId], fn($value) => $value !== ''))); ?>#feedback-received">Clear</a>
            </div>
          </form>
          <p class="muted" style="margin:10px 0 0"><?php echo h($feedbackRangeLabel); ?></p>
        </div>

        <div class="metrics">
          <div class="panel metric"><span>Total Feedback</span><strong><?php echo h($feedbackDisplayCount); ?></strong><small>Responses matching this filter.</small></div>
          <div class="panel metric"><span>All-Time Feedback</span><strong><?php echo h(count($allFeedbackRows)); ?></strong><small>Total collected for this chatbot.</small></div>
          <div class="panel metric"><span>Email Alerts</span><strong><?php echo $faqFeedbackEmailEnabled ? 'ON' : 'OFF'; ?></strong><small>New feedback email notifications.</small></div>
          <div class="panel metric"><span>Top Feedback</span><strong style="font-size:18px"><?php echo h($feedbackTopValue); ?></strong><small>Based on the analytics date range.</small></div>
        </div>

        <div class="panel section-body">
          <div class="section-head" style="padding:0 0 14px">
            <div>
              <h3>Feedback List</h3>
              <p class="muted">Use the date filter above to review a specific period.</p>
            </div>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Date</th><th>Feedback</th><th>FAQ</th><th>Action</th><th>Type</th><th>User</th><th>Source Page</th></tr></thead>
              <tbody>
                <?php if (empty($feedbackDisplayRows)): ?><tr><td colspan="7" class="empty">No feedback received for this filter.</td></tr><?php endif; ?>
                <?php foreach ($feedbackDisplayRows as $feedbackRow): ?>
                  <?php
                    $feedbackFaq = $faqById[(string)($feedbackRow['faq_id'] ?? '')] ?? [];
                    $feedbackAction = $faqActionById[(string)($feedbackRow['action_id'] ?? '')] ?? [];
                    $sourceUrl = trim((string)($feedbackRow['source_url'] ?? ''));
                    $sourceLabel = $sourceUrl !== '' ? (parse_url($sourceUrl, PHP_URL_PATH) ?: $sourceUrl) : '-';
                    $userLabel = trim((string)($feedbackRow['user_id'] ?? '')) ?: (trim((string)($feedbackRow['session_id'] ?? '')) ?: '-');
                  ?>
                  <tr>
                    <td><?php echo h(substr((string)($feedbackRow['created_at'] ?? ''), 0, 16) ?: '-'); ?></td>
                    <td><span class="tag <?php echo dashboard_feedback_is_positive((string)($feedbackRow['feedback_value'] ?? '')) ? 'good' : ''; ?>"><?php echo h(dashboard_feedback_display_value((string)($feedbackRow['feedback_value'] ?? ''))); ?></span></td>
                    <td><?php echo h($feedbackFaq['question'] ?? 'Deleted FAQ'); ?></td>
                    <td><?php echo h($feedbackAction['label'] ?? '-'); ?></td>
                    <td><?php echo h($feedbackRow['action_type'] ?? ($feedbackAction['action_type'] ?? '-')); ?></td>
                    <td><?php echo h($userLabel); ?></td>
                    <td><?php echo h($sourceLabel); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <section class="tab-panel" id="payments-collection">
        <div class="panel section-body">
          <div class="section-head" style="padding:0">
            <div>
              <span class="eyebrow">Payments Collection</span>
              <h3 style="margin-top:8px">Collect Payments Directly To Customer</h3>
              <p class="muted">Connect this chatbot customer's own Razorpay account. Visitor payments go to that customer's Razorpay account, not to Vani AI.</p>
            </div>
            <span class="tag <?php echo $paymentsEnabled ? 'good' : 'bad'; ?>"><?php echo $paymentsEnabled ? 'Enabled' : 'Off'; ?></span>
          </div>
        </div>

        <div class="metrics">
          <div class="panel metric"><span>Collected</span><strong><?php echo h(billing_rupees($paymentPaidTotalPaise)); ?></strong><small>Successful visitor payments.</small></div>
          <div class="panel metric"><span>Paid Orders</span><strong><?php echo h($paymentPaidCount); ?></strong><small>Captured payments.</small></div>
          <div class="panel metric"><span>Pending Orders</span><strong><?php echo h($paymentCreatedCount); ?></strong><small>Created but not verified yet.</small></div>
          <div class="panel metric"><span>UPI Pending</span><strong><?php echo h($paymentUpiPendingCount); ?></strong><small>Manual verification needed.</small></div>
        </div>

        <div class="panel section-body">
          <div class="payment-subtabs" role="tablist" aria-label="Payment collection sections">
            <button class="payment-subtab-btn active" type="button" data-payment-subtab="payment-subpanel-setup">Payment Setup</button>
            <button class="payment-subtab-btn" type="button" data-payment-subtab="payment-subpanel-razorpay">Razorpay Checkout</button>
            <button class="payment-subtab-btn" type="button" data-payment-subtab="payment-subpanel-upi">UPI Redirect</button>
            <button class="payment-subtab-btn" type="button" data-payment-subtab="payment-subpanel-buttons">Payment Buttons</button>
            <button class="payment-subtab-btn" type="button" data-payment-subtab="payment-subpanel-transactions">Transactions</button>
          </div>
        </div>

        <div class="payment-subpanel active" id="payment-subpanel-setup">
          <div class="panel section-body">
            <div class="section-head" style="padding:0 0 14px">
              <div>
                <h3>Payment Setup</h3>
                <p class="muted">Switch ON payment collection globally. Name is always collected. Choose whether the chatbot should also ask visitors for email, mobile number, or both before payment.</p>
              </div>
            </div>
            <form id="paymentSettingsForm" class="form-grid">
              <input type="hidden" id="paymentCustomerId" value="<?php echo h($selectedBotId); ?>">
              <div class="field">
                <label>Enable payment collection</label>
                <label class="switch" title="Enable payment collection">
                  <input id="paymentEnabledToggle" type="checkbox" <?php echo $paymentsEnabled && $canUsePaymentCollection ? 'checked' : ''; ?> <?php echo $canUsePaymentCollection ? '' : 'data-premium-lock="This feature is only for Growth or Business users. Please recharge your wallet with appropriate plan."'; ?> aria-label="Enable payment collection">
                  <span class="switch-slider"></span>
                </label>
                <?php if (!$canUsePaymentCollection): ?><small class="input-help error">Growth or Business plan required to switch ON payment collection.</small><?php endif; ?>
              </div>
              <div class="field"><label>Business name on checkout</label><input id="paymentBusinessNameInput" value="<?php echo h($paymentBusinessName); ?>" placeholder="<?php echo h($botName); ?>"></div>
              <div class="field">
                <label>Ask for email</label>
                <label class="switch" title="Ask visitor for email before payment">
                  <input id="paymentCollectEmailToggle" type="checkbox" <?php echo $paymentCollectPayerEmail ? 'checked' : ''; ?> <?php echo $canUsePaymentCollection ? '' : 'disabled'; ?> aria-label="Ask visitor for email before payment">
                  <span class="switch-slider"></span>
                </label>
                <small class="input-help">Name is always required. Email is useful for receipts and follow-up.</small>
              </div>
              <div class="field">
                <label>Verify payment email by OTP</label>
                <label class="switch" title="Verify visitor email before payment">
                  <input id="paymentVerifyEmailOtpToggle" type="checkbox" <?php echo $paymentVerifyPayerEmailOtp ? 'checked' : ''; ?> <?php echo ($canUsePaymentCollection && $canUseEmailOtp) ? '' : 'disabled'; ?> aria-label="Verify payment email by OTP">
                  <span class="switch-slider"></span>
                </label>
                <small class="input-help <?php echo $canUseEmailOtp ? '' : 'error'; ?>"><?php echo $canUseEmailOtp ? 'Requires Ask for email to be ON. Uses wallet Email OTP charges.' : 'Email OTP requires an active paid plan.'; ?></small>
              </div>
              <div class="field">
                <label>Ask for mobile number</label>
                <label class="switch" title="Ask visitor for mobile number before payment">
                  <input id="paymentCollectPhoneToggle" type="checkbox" <?php echo $paymentCollectPayerPhone ? 'checked' : ''; ?> <?php echo $canUsePaymentCollection ? '' : 'disabled'; ?> aria-label="Ask visitor for mobile number before payment">
                  <span class="switch-slider"></span>
                </label>
                <small class="input-help">Mobile number helps the business confirm payment or resolve issues.</small>
              </div>
              <div class="field">
                <label>Verify payment mobile by OTP</label>
                <label class="switch" title="Verify visitor mobile before payment">
                  <input id="paymentVerifyPhoneOtpToggle" type="checkbox" <?php echo $paymentVerifyPayerPhoneOtp ? 'checked' : ''; ?> <?php echo ($canUsePaymentCollection && $canUseMobileOtp) ? '' : 'disabled'; ?> aria-label="Verify payment mobile by OTP">
                  <span class="switch-slider"></span>
                </label>
                <small class="input-help <?php echo $canUseMobileOtp ? '' : 'error'; ?>"><?php echo $canUseMobileOtp ? 'Requires Ask for mobile number to be ON. Uses wallet Mobile OTP charges.' : 'Mobile OTP requires an active paid plan.'; ?></small>
              </div>
              <div class="field full"><button class="pill-btn" type="submit">Save payment setup</button></div>
            </form>
          </div>
        </div>

        <div class="payment-subpanel" id="payment-subpanel-razorpay">
          <div class="panel section-body">
            <div class="section-head" style="padding:0 0 14px">
              <div>
                <h3>Razorpay Checkout Setup</h3>
                <p class="muted">Enable Razorpay Checkout and save the customer's Razorpay credentials for checkout buttons.</p>
              </div>
            </div>
            <form id="paymentRazorpaySettingsForm" class="form-grid">
              <div class="field">
                <label>Enable Razorpay Checkout</label>
                <label class="switch" title="Enable Razorpay Checkout">
                  <input id="paymentRazorpayEnabledToggle" type="checkbox" <?php echo $paymentRazorpayEnabled && $canUsePaymentCollection ? 'checked' : ''; ?> <?php echo $canUsePaymentCollection ? '' : 'disabled'; ?> aria-label="Enable Razorpay Checkout">
                  <span class="switch-slider"></span>
                </label>
                <small class="input-help">Requires Razorpay Key ID and Key Secret.</small>
              </div>
              <div class="field"><label>Razorpay Key ID</label><input id="paymentKeyIdInput" value="<?php echo h($paymentRazorpayKeyId); ?>" placeholder="rzp_live_xxxxx"></div>
              <div class="field"><label>Razorpay Key Secret</label><input id="paymentKeySecretInput" type="password" placeholder="<?php echo $paymentRazorpaySecretSaved ? 'Saved. Leave blank to keep existing secret.' : 'Enter Razorpay key secret'; ?>"></div>
              <div class="field full"><label>Success message</label><input id="paymentSuccessMessageInput" value="<?php echo h($paymentSuccessMessage); ?>" placeholder="Payment received. Thank you."></div>
              <div class="field full"><button class="pill-btn" type="submit">Save Razorpay checkout</button></div>
            </form>
          </div>
          <div class="panel section-body">
            <h3>Create Razorpay Checkout Button</h3>
            <p class="muted" style="margin:8px 0 14px">Use this for card, netbanking, UPI through Razorpay, and wallet checkout. Razorpay Key ID and Key Secret must be saved above first.</p>
            <form class="form-grid payment-action-form" data-payment-method="razorpay">
              <div class="field"><label>Label</label><input data-payment-field="label" placeholder="Pay booking amount"></div>
              <div class="field"><label>Amount (INR)</label><input data-payment-field="amount" type="number" min="1" step="1" placeholder="999"></div>
              <div class="field full"><label>Description</label><textarea data-payment-field="description" placeholder="Advance payment for appointment or order"></textarea></div>
              <div class="field"><label>Status</label><label class="switch"><input data-payment-field="active" type="checkbox" checked><span class="switch-slider"></span></label></div>
              <div class="field"><label>&nbsp;</label><button class="pill-btn" type="submit">Add Razorpay button</button></div>
            </form>
          </div>
        </div>

        <div class="payment-subpanel" id="payment-subpanel-upi">
          <div class="panel section-body">
            <div class="section-head" style="padding:0 0 14px">
              <div>
                <h3>UPI Redirect Setup</h3>
                <p class="muted">Enable UPI Redirect for simple UPI app payment buttons. Each UPI button still needs its own UPI ID.</p>
              </div>
            </div>
            <form id="paymentUpiSettingsForm" class="form-grid">
              <div class="field">
                <label>Enable UPI Redirect</label>
                <label class="switch" title="Enable UPI Redirect">
                  <input id="paymentUpiEnabledToggle" type="checkbox" <?php echo $paymentUpiEnabled && $canUsePaymentCollection ? 'checked' : ''; ?> <?php echo $canUsePaymentCollection ? '' : 'disabled'; ?> aria-label="Enable UPI Redirect">
                  <span class="switch-slider"></span>
                </label>
                <small class="input-help">Uses a UPI ID on each UPI payment button.</small>
              </div>
              <div class="field">
                <label>Ask for UPI transaction ID</label>
                <label class="switch" title="Ask visitor to submit UPI transaction ID after payment">
                  <input id="paymentUpiTransactionIdToggle" type="checkbox" <?php echo $paymentUpiTransactionIdRequired ? 'checked' : ''; ?> <?php echo $canUsePaymentCollection ? '' : 'disabled'; ?> aria-label="Ask visitor to submit UPI transaction ID">
                  <span class="switch-slider"></span>
                </label>
                <small class="input-help">After opening a UPI app, ask the visitor to enter their UPI transaction ID for manual verification.</small>
              </div>
              <div class="field full"><button class="pill-btn" type="submit">Save UPI redirect</button></div>
            </form>
          </div>
          <div class="panel section-body">
            <h3>Create UPI Redirect Button</h3>
            <p class="muted" style="margin:8px 0 14px">Use this for the simplest UPI app redirect. Payments are created as pending and should be manually verified by the business.</p>
            <form class="form-grid payment-action-form" data-payment-method="upi">
              <div class="field"><label>Label</label><input data-payment-field="label" placeholder="Pay via UPI"></div>
              <div class="field"><label>Amount (INR)</label><input data-payment-field="amount" type="number" min="1" step="1" placeholder="999"></div>
              <div class="field"><label>UPI ID</label><input data-payment-field="upi_id" placeholder="business@upi"></div>
              <div class="field"><label>UPI payee name</label><input data-payment-field="upi_payee_name" placeholder="<?php echo h($paymentBusinessName); ?>"></div>
              <div class="field full"><label>UPI note</label><input data-payment-field="upi_note" placeholder="Booking advance"></div>
              <div class="field full"><label>Description</label><textarea data-payment-field="description" placeholder="Advance payment for appointment or order"></textarea></div>
              <div class="field"><label>Status</label><label class="switch"><input data-payment-field="active" type="checkbox" checked><span class="switch-slider"></span></label></div>
              <div class="field"><label>&nbsp;</label><button class="pill-btn" type="submit">Add UPI button</button></div>
            </form>
          </div>
        </div>

        <div class="payment-subpanel" id="payment-subpanel-buttons">
          <div class="panel section-body">
            <h3>Payment Buttons</h3>
            <div class="field" style="margin:12px 0">
              <label>Create Make Payment action for FAQ</label>
              <select id="paymentFaqActionFaqId">
                <option value="">Select FAQ</option>
                <?php foreach ($faqs as $faq): ?>
                  <option value="<?php echo h($faq['id'] ?? ''); ?>"><?php echo h($faq['question'] ?? ''); ?></option>
                <?php endforeach; ?>
              </select>
              <small class="input-help">Select a FAQ, then use Create Make Payment Action on a payment button below.</small>
            </div>
            <div class="mini-chart" id="paymentActionList">
              <?php if (empty($paymentActionRows)): ?><p class="empty">No payment buttons yet.</p><?php endif; ?>
              <?php foreach ($paymentActionRows as $paymentAction): ?>
                <?php $paymentActionActive = filter_var($paymentAction['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN); ?>
                <div class="lead-option" data-payment-action-id="<?php echo h($paymentAction['id'] ?? ''); ?>" data-payment-action-label="<?php echo h($paymentAction['label'] ?? 'Payment'); ?>">
                  <div class="inline-row" style="justify-content:space-between;gap:12px">
                    <div>
                      <strong><?php echo h($paymentAction['label'] ?? 'Payment'); ?></strong>
                      <small class="input-help">ID <?php echo h($paymentAction['id'] ?? ''); ?> | <?php echo h(strtoupper((string)($paymentAction['payment_method'] ?? 'razorpay'))); ?> | <?php echo h(billing_rupees((int)($paymentAction['amount_paise'] ?? 0))); ?> | <?php echo $paymentActionActive ? 'Active' : 'Inactive'; ?> | <?php echo h($paymentAction['description'] ?? ''); ?></small>
                    </div>
                    <div class="inline-row" style="gap:8px">
                      <label class="switch" title="Enable or disable this payment button">
                        <input class="payment-action-active-toggle" type="checkbox" <?php echo $paymentActionActive ? 'checked' : ''; ?> aria-label="Enable payment button <?php echo h($paymentAction['label'] ?? 'Payment'); ?>">
                        <span class="switch-slider"></span>
                      </label>
                      <button class="ghost-btn payment-action-copy-btn" type="button">Copy Payment ID</button>
                      <button class="pill-btn payment-action-create-faq-btn" type="button">Create Make Payment Action</button>
                      <button class="danger-btn payment-action-delete-btn" type="button">Delete</button>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="payment-subpanel" id="payment-subpanel-transactions">
        <div class="panel section-body">
          <h3>Payment Transactions</h3>
          <div class="table-wrap" style="margin-top:14px">
            <table id="paymentTransactionsTable">
              <thead><tr><th>Date</th><th>Status</th><th>Method</th><th>Amount</th><th>Payment Button</th><th>Payer</th><th>Reference</th><th>Source</th><th>Actions</th></tr></thead>
              <tbody>
                <?php if (empty($paymentTransactionRows)): ?><tr><td colspan="9" class="empty">No visitor payments yet.</td></tr><?php endif; ?>
                <?php foreach ($paymentTransactionRows as $paymentTxn): ?>
                  <?php $paymentAction = $paymentActionById[(string)($paymentTxn['payment_action_id'] ?? '')] ?? []; ?>
                  <?php
                    $paymentMethod = (string)($paymentTxn['payment_method'] ?? 'razorpay');
                    $paymentStatus = (string)($paymentTxn['status'] ?? 'created');
                    $paymentMetadata = is_array($paymentTxn['metadata'] ?? null) ? $paymentTxn['metadata'] : [];
                    $upiReference = $paymentMethod === 'upi' ? ('VANI' . preg_replace('/\D+/', '', (string)($paymentTxn['id'] ?? ''))) : '';
                    $customerUpiReference = trim((string)($paymentMetadata['customer_upi_transaction_id'] ?? ''));
                    $paymentReference = $paymentMethod === 'upi'
                      ? trim(($customerUpiReference !== '' ? $customerUpiReference . ' | ' : '') . $upiReference)
                      : ($paymentTxn['razorpay_payment_id'] ?? ($paymentTxn['razorpay_order_id'] ?? '-'));
                    $payerDisplay = trim(implode(' ', array_filter([
                      (string)($paymentTxn['payer_name'] ?? ''),
                      (string)($paymentTxn['payer_phone'] ?? ''),
                      (string)($paymentTxn['payer_email'] ?? '')
                    ]))) ?: '-';
                  ?>
                  <tr data-payment-transaction-id="<?php echo h($paymentTxn['id'] ?? ''); ?>">
                    <td><?php echo h(substr((string)($paymentTxn['created_at'] ?? ''), 0, 16)); ?></td>
                    <td><span class="tag <?php echo $paymentStatus === 'paid' ? 'good' : ($paymentStatus === 'failed' ? 'bad' : ''); ?>"><?php echo h($paymentStatus); ?></span></td>
                    <td><?php echo h(strtoupper($paymentMethod)); ?></td>
                    <td><?php echo h(billing_rupees((int)($paymentTxn['amount_paise'] ?? 0))); ?></td>
                    <td><?php echo h($paymentAction['label'] ?? 'Deleted payment button'); ?></td>
                    <td><?php echo h($payerDisplay); ?></td>
                    <td><?php echo h($paymentReference); ?></td>
                    <td><?php echo h($paymentTxn['source_url'] ?? '-'); ?></td>
                    <td>
                      <?php if ($paymentMethod === 'upi' && $paymentStatus === 'created'): ?>
                        <div class="inline-row" style="gap:8px">
                          <button class="pill-btn payment-transaction-status-btn" type="button" data-payment-status="paid">Mark Paid</button>
                          <button class="danger-btn payment-transaction-status-btn" type="button" data-payment-status="failed">Mark Failed</button>
                        </div>
                      <?php else: ?>
                        <span class="muted">-</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        </div>
      </section>

      <!-- Conversations tab content hidden for now; keep this code for later.
      <section class="tab-panel" id="logs">
        <div class="panel">
          <div class="section-head"><h3>Conversations / Logs</h3><span class="tag"><?php echo h($conversationCount); ?> total</span></div>
          <div class="section-body">
            <div class="table-wrap">
              <table>
                <thead><tr><th>User Question</th><th>Bot Response</th><th>Timestamp</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                  <?php if (empty($conversationRows)): ?>
                    <tr><td colspan="5" class="empty">No conversation logs yet. Run the SQL script and update chat logging to begin storing unanswered queries.</td></tr>
                  <?php endif; ?>
                  <?php foreach ($conversationRows as $row): ?>
                    <?php $answered = strtolower((string)($row['status'] ?? '')) === 'answered' || !empty($row['is_answered']); ?>
                    <tr>
                      <td><?php echo h($row['user_question'] ?? $row['question'] ?? ''); ?></td>
                      <td><?php echo h($row['bot_response'] ?? $row['response'] ?? ''); ?></td>
                      <td><?php echo h($row['created_at'] ?? ''); ?></td>
                      <td><span class="tag <?php echo $answered ? 'good' : 'bad'; ?>"><?php echo $answered ? 'Answered' : 'Unanswered'; ?></span></td>
                      <td><button class="ghost-btn" type="button" data-question="<?php echo h($row['user_question'] ?? $row['question'] ?? ''); ?>" data-jump="faqs">Add this as FAQ</button></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>
      -->

      <section class="tab-panel" id="analytics">
        <div class="panel section-body">
          <div class="section-head analytics-head" style="padding:0">
            <div class="analytics-title-block">
              <span class="eyebrow">Analytics</span>
              <h3 style="margin-top:8px">Performance Dashboard</h3>
              <div class="analytics-period-row">
                <div class="analytics-period-card"><span>Current analysis</span><strong><?php echo h($analyticsRangeLabel); ?> | <?php echo h($analyticsFrom); ?> to <?php echo h($analyticsTo); ?></strong></div>
                <div class="analytics-period-card"><span>Previous comparison</span><strong><?php echo h($previousAnalyticsFrom); ?> to <?php echo h($previousAnalyticsTo); ?></strong></div>
              </div>
            </div>
            <div class="analytics-head-actions">
              <div class="filter-bar">
                <a class="filter-chip <?php echo $analyticsRange === 'today' ? 'active' : ''; ?>" href="<?php echo h(analytics_url('today', $selectedBotId)); ?>">Today: <?php echo h($todayAllQueries); ?></a>
                <a class="filter-chip <?php echo $analyticsRange === 'yesterday' ? 'active' : ''; ?>" href="<?php echo h(analytics_url('yesterday', $selectedBotId)); ?>">Yesterday: <?php echo h($yesterdayAllQueries); ?></a>
                <a class="filter-chip <?php echo $analyticsRange === '7_days' ? 'active' : ''; ?>" href="<?php echo h(analytics_url('7_days', $selectedBotId)); ?>">7 days: <?php echo h($last7AllQueries); ?></a>
                <a class="filter-chip <?php echo $analyticsRange === '30_days' ? 'active' : ''; ?>" href="<?php echo h(analytics_url('30_days', $selectedBotId)); ?>">30 days: <?php echo h($last30AllQueries); ?></a>
                <a class="filter-chip <?php echo $analyticsRange === 'custom' ? 'active' : ''; ?>" href="<?php echo h(analytics_url('custom', $selectedBotId, $analyticsFrom, $analyticsTo)); ?>">Custom range</a>
              </div>
            </div>
          </div>
          <form class="analytics-filter-form" method="get" action="dashboard.php">
            <?php if ($selectedBotId): ?><input type="hidden" name="bot" value="<?php echo h($selectedBotId); ?>"><?php endif; ?>
            <input type="hidden" name="analytics_range" value="custom">
            <div class="field">
              <label>From</label>
              <input type="date" name="date_from" value="<?php echo h($analyticsFrom); ?>">
            </div>
            <div class="field">
              <label>To</label>
              <input type="date" name="date_to" value="<?php echo h($analyticsTo); ?>">
            </div>
            <div class="analytics-filter-actions">
              <button class="pill-btn" type="submit">Apply</button>
              <button class="pill-btn analytics-pdf-report-btn" type="button" <?php echo $canExportReports ? '' : 'data-premium-lock="Business wallet plan required"'; ?>>Export Current Analysis in PDF</button>
            </div>
          </form>
        </div>

        <div class="panel section-body">
          <div class="analytics-tabs" role="tablist" aria-label="Analytics sections">
            <button class="analytics-tab-btn active" type="button" data-analytics-tab="analytics-overview" <?php echo $canUsePartialAnalytics ? '' : 'data-premium-lock="Growth wallet plan required"'; ?>>Overview</button>
            <button class="analytics-tab-btn" type="button" data-analytics-tab="analytics-conversations" <?php echo $canUsePartialAnalytics ? '' : 'data-premium-lock="Growth wallet plan required"'; ?>>Conversations</button>
            <button class="analytics-tab-btn" type="button" data-analytics-tab="analytics-faq" <?php echo $canUsePartialAnalytics ? '' : 'data-premium-lock="Growth wallet plan required"'; ?>>FAQ Insights</button>
            <button class="analytics-tab-btn" type="button" data-analytics-tab="analytics-feedback" <?php echo $canUsePartialAnalytics ? '' : 'data-premium-lock="Growth wallet plan required"'; ?>>Feedback</button>
            <button class="analytics-tab-btn" type="button" data-analytics-tab="analytics-payments" <?php echo $canUsePaymentCollection ? '' : 'data-premium-lock="Payment Analysis is only for Growth or Business users. Please recharge your wallet with appropriate plan."'; ?>>Payment Analysis</button>
            <button class="analytics-tab-btn" type="button" data-analytics-tab="analytics-leads" <?php echo $canUsePartialAnalytics ? '' : 'data-premium-lock="Growth wallet plan required"'; ?>>Leads</button>
            <button class="analytics-tab-btn" type="button" data-analytics-tab="analytics-pages" <?php echo $canUseAdvancedAnalytics ? '' : 'data-premium-lock="Business wallet plan required"'; ?>>Pages</button>
            <button class="analytics-tab-btn" type="button" data-analytics-tab="analytics-realtime" <?php echo $canUseAdvancedAnalytics ? '' : 'data-premium-lock="Business wallet plan required"'; ?>>Real-Time</button>
            <button class="analytics-tab-btn" type="button" data-analytics-tab="analytics-reports" <?php echo $canExportReports ? '' : 'data-premium-lock="Business wallet plan required"'; ?>>Reports</button>
          </div>
        </div>

        <?php if (!$canUsePartialAnalytics): ?>
        <div class="panel section-body">
          <div class="notice"><strong>Growth wallet plan required:</strong><br>Analytics access starts on Growth. Recharge to view Overview, Conversations, FAQ Insights, and Leads.</div>
        </div>
        <?php else: ?>
        <div class="analytics-subpanel active" id="analytics-overview">
        <div class="bi-kpi-grid">
          <div class="bi-kpi" data-drilldown-type="summary" data-drilldown-key="conversations"><span>Conversations</span><strong><?php echo h($conversationCount); ?></strong><small><?php echo analytics_delta_html($analyticsCurrentSummary['conversations'], $analyticsPreviousSummary['conversations']); ?></small></div>
          <div class="bi-kpi" data-drilldown-type="summary" data-drilldown-key="answer_rate"><span>Answer Rate</span><strong><?php echo h($accuracy); ?>%</strong><small><?php echo h($answeredCount); ?> answered / <?php echo h($unansweredCount); ?> unanswered</small></div>
          <div class="bi-kpi" data-drilldown-type="summary" data-drilldown-key="leads"><span>Lead Conversion</span><strong><?php echo h($leadConversionRate); ?>%</strong><small><?php echo h($leadCount); ?> leads from selected range</small></div>
          <div class="bi-kpi" data-drilldown-type="summary" data-drilldown-key="visitors"><span>Visitors</span><strong><?php echo h($uniqueVisitorCount); ?></strong><small><?php echo h($returningUsersPercent); ?>% returning users</small></div>
        </div>

        <div class="bi-alert-grid">
          <div class="bi-alert <?php echo $unansweredPercent > 30 ? 'bad' : ($unansweredPercent > 10 ? 'warn' : 'good'); ?>"><strong>Answer Health</strong><span><?php echo h($unansweredPercent); ?>% unanswered queries in this period.</span></div>
          <div class="bi-alert <?php echo $leadConversionRate >= 10 ? 'good' : ($leadConversionRate > 0 ? 'warn' : 'bad'); ?>"><strong>Lead Capture</strong><span><?php echo h($leadConversionRate); ?>% conversion from conversations to raw leads.</span></div>
          <div class="bi-alert <?php echo $avgResponseTimeMs && $avgResponseTimeMs <= 1500 ? 'good' : ($avgResponseTimeMs ? 'warn' : ''); ?>"><strong>Response Time</strong><span><?php echo $avgResponseTimeMs ? h($avgResponseTimeMs) . 'ms average widget response.' : 'No response time data tracked yet.'; ?></span></div>
        </div>

        <div class="bi-dashboard-grid">
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Conversation, Answer & Lead Trend</h3><span class="tag"><?php echo h($analyticsRangeLabel); ?></span></div>
            <div class="bi-chart tall" id="analyticsTrendChart" data-chart-title="Conversation trend"></div>
          </div>
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Conversion Funnel</h3><span class="tag"><?php echo h($leadConversionRate); ?>%</span></div>
            <div class="bi-chart tall" id="analyticsFunnelChart" data-chart-title="Lead funnel"></div>
          </div>
        </div>

        <div class="bi-dashboard-grid three">
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Device Mix</h3><span class="tag"><?php echo h(count($deviceCounts)); ?> types</span></div>
            <div class="bi-chart compact" id="analyticsDeviceChart" data-chart-title="Device mix"></div>
          </div>
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Top Questions</h3><span class="tag"><?php echo h(count($topQuestionCounts)); ?> tracked</span></div>
            <div class="bi-chart compact" id="analyticsQuestionChart" data-chart-title="Top questions"></div>
          </div>
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Source Pages</h3><span class="tag"><?php echo h(count($sourcePageStats)); ?> pages</span></div>
            <div class="bi-chart compact" id="analyticsPageChart" data-chart-title="Source pages"></div>
          </div>
        </div>

        <div class="metrics">
          <div class="panel metric"><span>Total Conversations</span><strong><?php echo h($conversationCount); ?></strong><small>Tracked chat sessions/queries.</small><?php echo analytics_delta_html($analyticsCurrentSummary['conversations'], $analyticsPreviousSummary['conversations']); ?></div>
          <div class="panel metric"><span>Total Messages</span><strong><?php echo h($totalMessages); ?></strong><small>User messages currently tracked.</small><?php echo analytics_delta_html($analyticsCurrentSummary['messages'], $analyticsPreviousSummary['messages']); ?></div>
          <div class="panel metric"><span>Unique Visitors</span><strong><?php echo h($uniqueVisitorCount); ?></strong><small>Based on widget user IDs.</small><?php echo analytics_delta_html($analyticsCurrentSummary['visitors'], $analyticsPreviousSummary['visitors']); ?></div>
          <div class="panel metric"><span>Answered Queries</span><strong><?php echo h($accuracy); ?>%</strong><small><?php echo h($answeredCount); ?> answered.</small><?php echo analytics_delta_html($analyticsCurrentSummary['answer_rate'], $analyticsPreviousSummary['answer_rate']); ?></div>
          <div class="panel metric"><span>Unanswered Queries</span><strong><?php echo h($unansweredPercent); ?>%</strong><small><?php echo h($unansweredCount); ?> need FAQ improvement.</small><?php echo analytics_delta_html($analyticsCurrentSummary['unanswered_rate'], $analyticsPreviousSummary['unanswered_rate'], '', true); ?></div>
          <div class="panel metric"><span>Avg Response Time</span><strong><?php echo $avgResponseTimeMs ? h($avgResponseTimeMs) . 'ms' : 'No data'; ?></strong><small>Measured by the widget API.</small><?php echo analytics_delta_html($analyticsCurrentSummary['avg_response_time_ms'], $analyticsPreviousSummary['avg_response_time_ms'], '', true); ?></div>
          <div class="panel metric"><span>Leads Collected</span><strong><?php echo h($leadCount); ?></strong><small><?php echo h($leadConversionRate); ?>% conversion from conversations.</small><?php echo analytics_delta_html($analyticsCurrentSummary['leads'], $analyticsPreviousSummary['leads']); ?></div>
          <div class="panel metric"><span>OTP Verified Leads</span><strong><?php echo h($verifiedLeadCount); ?></strong><small><?php echo h($otpVerifiedLeadPercent); ?>% of collected leads.</small><?php echo analytics_delta_html($analyticsCurrentSummary['verified_leads'], $analyticsPreviousSummary['verified_leads']); ?></div>
          <div class="panel metric"><span>Active Chatbots</span><strong><?php echo h($activeChatbotCount); ?></strong><small>Selected bot status.</small></div>
          <div class="panel metric"><span>Most Active Page</span><strong style="font-size:18px"><?php echo h($mostActivePage); ?></strong><small>Highest tracked conversation source.</small></div>
          <div class="panel metric"><span>Returning Users</span><strong><?php echo h($returningUsersPercent); ?>%</strong><small><?php echo h($returningVisitorCount); ?> visitors returned.</small></div>
          <div class="panel metric"><span>Avg Conversation Duration</span><strong><?php echo h($avgConversationDuration); ?></strong><small>Based on widget session duration.</small></div>
        </div>
        </div>

        <div class="analytics-subpanel" id="analytics-conversations">
        <div class="bi-dashboard-grid">
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Hourly Usage</h3><span class="tag">Peak <?php echo h($peakUsage); ?></span></div>
            <div class="bi-chart" id="analyticsHourChart" data-chart-title="Hourly usage"></div>
          </div>
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Browser Breakdown</h3><span class="tag"><?php echo h(count($browserCounts)); ?> browsers</span></div>
            <div class="bi-chart" id="analyticsBrowserChart" data-chart-title="Browser breakdown"></div>
          </div>
        </div>

        <div class="analytics-grid two">
          <div class="panel section-body">
            <h3>Conversations Trend</h3>
            <p class="muted" style="margin:10px 0 0">Date vs conversations.</p>
            <?php if (empty($dailyChartCounts)): ?><p class="empty">No conversation trend data yet.</p><?php endif; ?>
            <?php if (!empty($dailyChartCounts)): ?>
              <div class="trend-line">
                <?php foreach (array_slice($dailyChartCounts, -14, null, true) as $day => $count): ?>
                  <div class="trend-column">
                    <div class="trend-bar" style="height:<?php echo h(max(4, round(($count / max(1, $maxDailyCount)) * 150))); ?>px"></div>
                    <span class="trend-label"><?php echo h(substr($day, 5)); ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="panel section-body">
            <h3>Peak Usage Hours</h3>
            <p class="muted" style="margin:10px 0 0">Hour vs number of queries. Peak: <?php echo h($peakUsage); ?>.</p>
            <div class="mini-chart">
              <?php if (empty($hourChartCounts)): ?><p class="empty">No hourly usage data yet.</p><?php endif; ?>
              <?php foreach (array_slice($hourChartCounts, 0, 12, true) as $hour => $count): ?>
                <div class="bar-row"><span><?php echo h($hour); ?>:00</span><div class="bar-track"><div class="bar-fill" style="width:<?php echo h(round(($count / max(1, $maxHourCount)) * 100)); ?>%"></div></div><strong><?php echo h($count); ?></strong></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="analytics-grid">
          <div class="panel section-body">
            <h3>Device Analytics</h3>
            <div class="mini-chart">
              <?php if (empty($deviceCounts)): ?><p class="empty">No device data yet. New widget sessions will populate this.</p><?php endif; ?>
              <?php foreach (array_slice($deviceCounts, 0, 5, true) as $device => $count): ?>
                <div class="bar-row"><span><?php echo h($device); ?></span><div class="bar-track"><div class="bar-fill" style="width:<?php echo h(round(($count / max(1, max($deviceCounts))) * 100)); ?>%"></div></div><strong><?php echo h($count); ?></strong></div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="panel section-body">
            <h3>Browser Analytics</h3>
            <div class="mini-chart">
              <?php if (empty($browserCounts)): ?><p class="empty">No browser data yet. New widget sessions will populate this.</p><?php endif; ?>
              <?php foreach (array_slice($browserCounts, 0, 5, true) as $browser => $count): ?>
                <div class="bar-row"><span><?php echo h($browser); ?></span><div class="bar-track"><div class="bar-fill" style="width:<?php echo h(round(($count / max(1, max($browserCounts))) * 100)); ?>%"></div></div><strong><?php echo h($count); ?></strong></div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="panel section-body analytics-map-panel">
            <h3>Country World Map</h3>
            <p class="muted" style="margin:10px 0 0">Country-level distribution for tracked widget sessions in the selected range.</p>
            <div class="map-controls">
              <div class="field">
                <label>Focus country</label>
                <select id="analyticsCountryFocus">
                  <option value="">All countries</option>
                  <?php foreach (array_keys($countryCounts) as $country): ?>
                    <option value="<?php echo h($country); ?>"><?php echo h($country); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="map-note">Red dots show saved user locations from latitude/longitude lead data. Larger dots mean more users clustered around the same city/location.</div>
            </div>
            <div class="world-map-chart" id="analyticsWorldMap" aria-label="World map of country counts"></div>
            <div class="world-map-fallback compact" id="analyticsWorldMapFallback">
              <?php if (empty($countryCounts) && empty($cityCounts) && empty($cityClusterRows)): ?><p class="empty">No location data yet. Country is estimated from browser locale; red dots need users to share location.</p><?php endif; ?>
              <?php foreach (array_slice($countryCounts, 0, 8, true) as $country => $count): ?>
                <div class="bar-row"><span><?php echo h($country); ?></span><div class="bar-track"><div class="bar-fill" style="width:<?php echo h(round(($count / max(1, max($countryCounts))) * 100)); ?>%"></div></div><strong><?php echo h($count); ?></strong></div>
              <?php endforeach; ?>
              <?php foreach (array_slice($cityCounts, 0, 4, true) as $city => $count): ?>
                <div class="bar-row"><span><?php echo h($city); ?></span><div class="bar-track"><div class="bar-fill" style="width:<?php echo h(round(($count / max(1, max($cityCounts))) * 100)); ?>%"></div></div><strong><?php echo h($count); ?></strong></div>
              <?php endforeach; ?>
              <?php foreach (array_slice($cityClusterRows, 0, 6, true) as $cluster): ?>
                <div class="bar-row"><span><?php echo h($cluster['name']); ?></span><div class="bar-track"><div class="bar-fill" style="width:<?php echo h(round(((int)($cluster['count'] ?? 0) / max(1, max(array_column($cityClusterRows, 'count') ?: [1]))) * 100)); ?>%"></div></div><strong><?php echo h($cluster['count'] ?? 0); ?></strong></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        </div>

        <div class="analytics-subpanel" id="analytics-faq">
        <div class="analytics-grid two">
          <div class="panel">
            <div class="section-head"><h3>Most Asked Questions</h3><span class="tag"><?php echo h(count($topQuestionCounts)); ?> tracked</span></div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Question</th><th>Count</th><th>Success Rate</th></tr></thead>
                <tbody>
                  <?php if (empty($topQuestionCounts)): ?><tr><td colspan="3" class="empty">No asked questions yet.</td></tr><?php endif; ?>
                  <?php foreach (array_slice($topQuestionCounts, 0, 8) as $item): ?>
                    <?php $questionSuccess = $item['count'] > 0 ? round((($item['answered'] ?? 0) / max(1, $item['count'])) * 100) : 0; ?>
                    <tr><td><?php echo h($item['question']); ?></td><td><?php echo h($item['count']); ?></td><td><?php echo h($questionSuccess); ?>%</td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <div class="panel">
            <div class="section-head"><h3>Unanswered Questions</h3><span class="tag bad"><?php echo h($unansweredCount); ?> open</span></div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>User Question</th><th>Source Page</th><th>Date</th><th>Action</th></tr></thead>
                <tbody>
                  <?php if (empty($outsideFaqQuestions)): ?><tr><td colspan="4" class="empty">No unanswered questions yet.</td></tr><?php endif; ?>
                  <?php foreach (array_slice($outsideFaqQuestions, 0, 8) as $item): ?>
                    <tr>
                      <td><?php echo h($item['question']); ?></td>
                      <td><?php echo h($item['source_page'] ?? 'Unknown page'); ?></td>
                      <td><?php echo h(substr((string)($item['created_at'] ?? ''), 0, 10)); ?></td>
                      <td><button class="ghost-btn" type="button" data-question="<?php echo h($item['question']); ?>" data-jump="faqs">Add to FAQ</button></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        </div>

        <div class="analytics-subpanel" id="analytics-feedback">
        <div class="bi-kpi-grid">
          <div class="bi-kpi"><span>Total Feedback</span><strong><?php echo h($feedbackCount); ?></strong><small>Collected in selected analytics range.</small></div>
          <div class="bi-kpi"><span>Positive Feedback</span><strong><?php echo h($feedbackPositiveRate); ?>%</strong><small><?php echo h($feedbackPositiveCount); ?> positive responses.</small></div>
          <div class="bi-kpi"><span>Unique Users</span><strong><?php echo h(count($feedbackUniqueUsers)); ?></strong><small>Based on user or session IDs.</small></div>
          <div class="bi-kpi"><span>Top Feedback</span><strong><?php echo h($feedbackTopValue); ?></strong><small>Most selected feedback value.</small></div>
        </div>

        <div class="bi-dashboard-grid">
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Feedback Trend</h3><span class="tag"><?php echo h($analyticsRangeLabel); ?></span></div>
            <div class="bi-chart" id="analyticsFeedbackTrendChart" data-chart-title="Feedback trend"></div>
          </div>
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Feedback Values</h3><span class="tag"><?php echo h(count($feedbackValueCounts)); ?> values</span></div>
            <div class="bi-chart" id="analyticsFeedbackValueChart" data-chart-title="Feedback values"></div>
          </div>
        </div>

        <div class="analytics-grid two">
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Actions Getting Feedback</h3><span class="tag"><?php echo h(count($feedbackActionTypeCounts)); ?> actions</span></div>
            <div class="bi-chart" id="analyticsFeedbackActionChart" data-chart-title="Feedback by action"></div>
          </div>
          <div class="panel section-body">
            <h3>Recent Feedback</h3>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Date</th><th>Feedback</th><th>Action</th><th>Source</th></tr></thead>
                <tbody>
                  <?php if (empty($recentFeedbackRows)): ?><tr><td colspan="4" class="empty">No feedback in this analytics range.</td></tr><?php endif; ?>
                  <?php foreach ($recentFeedbackRows as $feedbackRow): ?>
                    <?php
                      $feedbackAction = $faqActionById[(string)($feedbackRow['action_id'] ?? '')] ?? [];
                      $sourceUrl = trim((string)($feedbackRow['source_url'] ?? ''));
                      $sourceLabel = $sourceUrl !== '' ? (parse_url($sourceUrl, PHP_URL_PATH) ?: $sourceUrl) : '-';
                    ?>
                    <tr>
                      <td><?php echo h(substr((string)($feedbackRow['created_at'] ?? ''), 0, 10) ?: '-'); ?></td>
                      <td><?php echo h(dashboard_feedback_display_value((string)($feedbackRow['feedback_value'] ?? ''))); ?></td>
                      <td><?php echo h($feedbackAction['label'] ?? ($feedbackRow['action_type'] ?? '-')); ?></td>
                      <td><?php echo h($sourceLabel); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        </div>

        <div class="analytics-subpanel" id="analytics-payments">
        <div class="bi-kpi-grid">
          <div class="bi-kpi"><span>Revenue Collected</span><strong><?php echo h(billing_rupees($paymentAnalyticsRevenuePaise)); ?></strong><small>Paid visitor payments in selected range.</small></div>
          <div class="bi-kpi"><span>Payment Conversion</span><strong><?php echo h($paymentAnalyticsConversionRate); ?>%</strong><small><?php echo h($paymentAnalyticsPaidCount); ?> paid / <?php echo h($paymentAnalyticsCount); ?> attempts.</small></div>
          <div class="bi-kpi"><span>Pending Payments</span><strong><?php echo h($paymentAnalyticsPendingCount); ?></strong><small>Includes UPI manual verification.</small></div>
          <div class="bi-kpi"><span>Top Payment Button</span><strong><?php echo h($paymentTopAction); ?></strong><small>Most used payment action.</small></div>
        </div>

        <div class="bi-dashboard-grid">
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Payment Revenue Trend</h3><span class="tag"><?php echo h($analyticsRangeLabel); ?></span></div>
            <div class="bi-chart" id="analyticsPaymentRevenueChart" data-chart-title="Payment revenue trend"></div>
          </div>
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Payment Status</h3><span class="tag"><?php echo h($paymentAnalyticsCount); ?> attempts</span></div>
            <div class="bi-chart" id="analyticsPaymentStatusChart" data-chart-title="Payment status"></div>
          </div>
        </div>

        <div class="bi-dashboard-grid">
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Payment Methods</h3><span class="tag"><?php echo h(count($paymentMethodCounts)); ?> methods</span></div>
            <div class="bi-chart" id="analyticsPaymentMethodChart" data-chart-title="Payment methods"></div>
          </div>
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Payment Buttons</h3><span class="tag"><?php echo h(count($paymentActionCounts)); ?> buttons</span></div>
            <div class="bi-chart" id="analyticsPaymentActionChart" data-chart-title="Payment buttons"></div>
          </div>
        </div>

        <div class="panel section-body">
          <h3>Recent Payment Activity</h3>
          <div class="table-wrap" style="margin-top:14px">
            <table>
              <thead><tr><th>Date</th><th>Status</th><th>Method</th><th>Amount</th><th>Payment Button</th><th>Payer</th><th>Reference</th></tr></thead>
              <tbody>
                <?php if (empty($recentPaymentAnalyticsRows)): ?><tr><td colspan="7" class="empty">No payment activity in this analytics range.</td></tr><?php endif; ?>
                <?php foreach ($recentPaymentAnalyticsRows as $paymentRow): ?>
                  <?php $paymentAction = $paymentActionById[(string)($paymentRow['payment_action_id'] ?? '')] ?? []; ?>
                  <tr>
                    <td><?php echo h(substr((string)($paymentRow['created_at'] ?? ''), 0, 16)); ?></td>
                    <td><span class="tag <?php echo ($paymentRow['status'] ?? '') === 'paid' ? 'good' : (($paymentRow['status'] ?? '') === 'failed' ? 'bad' : ''); ?>"><?php echo h($paymentRow['status'] ?? 'created'); ?></span></td>
                    <td><?php echo h(strtoupper((string)($paymentRow['payment_method'] ?? 'razorpay'))); ?></td>
                    <td><?php echo h(billing_rupees((int)($paymentRow['amount_paise'] ?? 0))); ?></td>
                    <td><?php echo h($paymentAction['label'] ?? 'Deleted payment button'); ?></td>
                    <td><?php echo h(trim(($paymentRow['payer_name'] ?? '') . ' ' . ($paymentRow['payer_email'] ?? '')) ?: '-'); ?></td>
                    <td><?php echo h($paymentRow['razorpay_payment_id'] ?? ($paymentRow['razorpay_order_id'] ?? '-')); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        </div>

        <div class="analytics-subpanel" id="analytics-leads">
        <div class="bi-dashboard-grid">
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Lead Capture Trend</h3><span class="tag"><?php echo h($leadCount); ?> raw leads</span></div>
            <div class="bi-chart" id="analyticsLeadTrendChart" data-chart-title="Lead capture trend"></div>
          </div>
          <div class="panel bi-panel">
            <div class="bi-panel-head"><h3>Lead Quality</h3><span class="tag"><?php echo h($otpVerifiedLeadPercent); ?>% verified</span></div>
            <div class="bi-chart" id="analyticsLeadQualityChart" data-chart-title="Lead quality"></div>
          </div>
        </div>

        <div class="metrics">
          <div class="panel metric"><span>Unique Leads</span><strong><?php echo h($uniqueLeadCount); ?></strong><small>Deduplicated by email or mobile number for the selected date range.</small></div>
          <div class="panel metric"><span>Real Leads</span><strong><?php echo h($realUniqueLeadCount); ?></strong><small>Email or mobile OTP verified.</small></div>
          <div class="panel metric"><span>Weak Leads</span><strong><?php echo h($weakLeadCount); ?></strong><small>Contact captured without OTP verification.</small></div>
          <div class="panel metric"><span>Email Contacts</span><strong><?php echo h($emailLeadCount); ?></strong><small>Raw email captures in selected range.</small></div>
          <div class="panel metric"><span>Mobile Contacts</span><strong><?php echo h($phoneLeadCount); ?></strong><small>Raw mobile captures in selected range.</small></div>
          <div class="panel metric"><span>Lead Conversion</span><strong><?php echo h($leadConversionRate); ?>%</strong><small>Raw leads from conversations.</small></div>
        </div>

        <div class="panel section-body">
          <h3>Lead Generated Data</h3>
          <p class="muted" style="margin:10px 0 14px">Duplicate emails and mobile numbers are merged here. Use the date filter above to change the selected range.</p>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Lead type</th><th>Email</th><th>Mobile Number</th><th>Email OTP Count</th><th>Mobile OTP Count</th><th>Total Captures</th><th>WhatsApp Clicks</th><th>Source Pages</th><th>Location</th><th>First Seen</th><th>Last Seen</th></tr></thead>
              <tbody>
                <?php if (empty($uniqueLeadRows)): ?><tr><td colspan="11" class="empty">No leads captured in this date range.</td></tr><?php endif; ?>
                <?php foreach ($uniqueLeadRows as $lead): ?>
                  <tr>
                    <td><span class="tag <?php echo ($lead['lead_type'] ?? '') === 'Real' ? 'good' : 'bad'; ?>"><?php echo h($lead['lead_type'] ?? 'Weak'); ?></span></td>
                    <td><?php echo h($lead['email'] ?: '-'); ?></td>
                    <td><?php echo h($lead['phone_number'] ?: '-'); ?></td>
                    <td><?php echo h($lead['email_otp_count'] ?? 0); ?></td>
                    <td><?php echo h($lead['mobile_otp_count'] ?? 0); ?></td>
                    <td><?php echo h($lead['total_records'] ?? 0); ?></td>
                    <td><?php echo h($lead['whatsapp_redirect_count'] ?? 0); ?></td>
                    <td><?php echo h(implode(', ', array_keys($lead['source_pages'] ?? [])) ?: '-'); ?></td>
                    <td><?php echo h($lead['location'] ?: '-'); ?></td>
                    <td><?php echo h(substr((string)($lead['first_seen'] ?? ''), 0, 10) ?: '-'); ?></td>
                    <td><?php echo h(substr((string)($lead['last_seen'] ?? ''), 0, 10) ?: '-'); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="analytics-grid two">
          <div class="panel section-body">
            <h3>Lead Generation Periods</h3>
            <div class="mini-chart">
              <?php foreach ($leadPeriodStats as $period): ?>
                <div class="bar-row"><span><?php echo h($period['label']); ?></span><div class="bar-track"><div class="bar-fill" style="width:<?php echo h(round(($period['count'] / max(1, max(array_column($leadPeriodStats, 'count')))) * 100)); ?>%"></div></div><strong><?php echo h($period['count']); ?></strong></div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="panel section-body">
            <h3>Lead Generation Funnel</h3>
            <div class="funnel">
              <div class="funnel-step"><span>Visitors</span><strong><?php echo h($uniqueVisitorCount); ?></strong></div>
              <div class="funnel-step"><span>Chat Opened</span><strong><?php echo h($chatOpenedCount); ?></strong></div>
              <div class="funnel-step"><span>Started Chat</span><strong><?php echo h($conversationCount); ?></strong></div>
              <div class="funnel-step"><span>Shared Contact</span><strong><?php echo h($uniqueLeadCount); ?></strong></div>
              <div class="funnel-step"><span>OTP Verified</span><strong><?php echo h($realUniqueLeadCount); ?></strong></div>
            </div>
          </div>
        </div>
        </div>

        <div class="analytics-subpanel" id="analytics-pages">
        <div class="analytics-grid two">
          <div class="panel section-body">
            <h3>User Engagement</h3>
            <div class="mini-chart">
              <div class="inline-row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:10px 0"><span>Avg messages per conversation</span><strong><?php echo h($avgMessagesPerConversation); ?></strong></div>
              <div class="inline-row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:10px 0"><span>Returning visitors</span><strong><?php echo h($returningUsersPercent); ?>%</strong></div>
              <div class="inline-row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:10px 0"><span>Chat open rate</span><strong><?php echo h($chatOpenRate); ?>%</strong></div>
              <div class="inline-row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:10px 0"><span>Bounce after chatbot open</span><strong><?php echo h($bounceAfterOpenRate); ?>%</strong></div>
            </div>
          </div>
          <div class="panel">
            <div class="section-head"><h3>Source Page Analytics</h3><span class="tag"><?php echo h(count($sourcePageStats)); ?> pages</span></div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Page URL</th><th>Conversations</th><th>Leads</th><th>Success %</th></tr></thead>
                <tbody>
                  <?php if (empty($sourcePageStats)): ?><tr><td colspan="4" class="empty">No source page data yet.</td></tr><?php endif; ?>
                  <?php foreach (array_slice($sourcePageStats, 0, 8) as $page): ?>
                    <?php $pageSuccess = $page['conversations'] > 0 ? round(($page['answered'] / max(1, $page['conversations'])) * 100) : 0; ?>
                    <tr><td><?php echo h($page['page']); ?></td><td><?php echo h($page['conversations']); ?></td><td><?php echo h($page['leads']); ?></td><td><?php echo h($pageSuccess); ?>%</td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        </div>

        <div class="analytics-subpanel" id="analytics-realtime">
        <div class="analytics-grid">
          <div class="panel section-body"><h3>Real-Time Analytics</h3><p class="muted" style="margin-top:10px">Active users currently chatting: <strong><?php echo h($activeUsersNow); ?></strong></p><p class="muted">Current page: <strong><?php echo h($mostActivePage); ?></strong></p></div>
        </div>
        </div>

        <div class="analytics-subpanel" id="analytics-reports">
        <div class="analytics-grid two">
          <div class="panel section-body"><h3>Export & Reports</h3><?php if (!$canExportReports): ?><div class="notice" style="margin-top:12px"><strong>Subscription required:</strong><br>CSV export and downloadable reports are available on Business plan and higher.</div><?php else: ?><div class="report-actions" style="margin-top:12px"><button class="ghost-btn" type="button" id="exportAnalyticsCsvBtn">Export CSV</button><button class="ghost-btn" type="button" id="downloadAnalyticsReportBtn">Download branded report</button><button class="ghost-btn" type="button" id="printAnalyticsReportBtn">Print / Save PDF</button><button class="ghost-btn" type="button" id="downloadWeeklyReportBtn">Weekly report</button><button class="ghost-btn" type="button" id="downloadMonthlyReportBtn">Monthly report</button></div><?php endif; ?></div>
          <div class="panel section-body"><h3>Notifications / Alerts</h3><div class="mini-chart"><div class="notice">Fallback rate: <?php echo h($fallbackRate); ?>%</div><div class="notice">Trending unanswered questions: <?php echo h($unansweredCount); ?></div><div class="notice">Lead conversion: <?php echo h($leadConversionRate); ?>%</div></div></div>
        </div>
        </div>
        <?php endif; ?>
        <aside class="bi-drilldown" id="analyticsDrilldown" aria-live="polite">
          <div class="bi-drilldown-head">
            <div>
              <span class="eyebrow">Drill-down</span>
              <h3 id="analyticsDrilldownTitle">Analytics detail</h3>
            </div>
            <button class="ghost-btn" type="button" id="closeAnalyticsDrilldownBtn">Close</button>
          </div>
          <div class="bi-drilldown-body" id="analyticsDrilldownBody"></div>
        </aside>
      </section>

      <section class="tab-panel" id="install">
        <div class="panel">
          <div class="section-head"><h3>Integration / Install</h3></div>
          <div class="integration-subtabs" role="tablist" aria-label="Integration sections">
            <button class="integration-subtab-btn active" type="button" data-integration-subtab="integration-subpanel-install">Install &amp; Domains</button>
            <button class="integration-subtab-btn" type="button" data-integration-subtab="integration-subpanel-api">API Keys</button>
            <button class="integration-subtab-btn" type="button" data-integration-subtab="integration-subpanel-events">Webhooks &amp; Live Actions</button>
          </div>
          <div class="section-body form-grid integration-subpanel active" id="integration-subpanel-install">
            <div class="easy-install-grid">
              <div class="easy-install-card">
                <span class="easy-install-icon">WP</span>
                <h4>WordPress</h4>
                <p>Download a ready plugin ZIP for this chatbot and upload it from WordPress admin.</p>
                <?php if ($selectedBotId): ?>
                  <a class="pill-btn" href="/api.php?action=download_wordpress_plugin&amp;customer_id=<?php echo h(urlencode($selectedBotId)); ?>">Download Plugin</a>
                <?php else: ?>
                  <button class="pill-btn" type="button" disabled>Select bot first</button>
                <?php endif; ?>
              </div>
              <div class="easy-install-card">
                <span class="easy-install-icon">W</span>
                <h4>Wix</h4>
                <p>Add Vani AI through Wix custom code on all pages near the end of body.</p>
                <button class="ghost-btn install-guide-btn" type="button" data-guide="wix">View Steps</button>
              </div>
              <div class="easy-install-card">
                <span class="easy-install-icon">S</span>
                <h4>Shopify</h4>
                <p>Paste the secure snippet into theme code before the closing body tag.</p>
                <button class="ghost-btn install-guide-btn" type="button" data-guide="shopify">View Steps</button>
              </div>
              <div class="easy-install-card">
                <span class="easy-install-icon">G</span>
                <h4>Google Tag Manager</h4>
                <p>Install with a Custom HTML tag and trigger it on all pages.</p>
                <button class="ghost-btn install-guide-btn" type="button" data-guide="gtm">View Steps</button>
              </div>
              <div class="easy-install-card">
                <span class="easy-install-icon">HTML</span>
                <h4>Custom Website</h4>
                <p>Use the universal secure iframe snippet for any HTML, PHP, React, or static site.</p>
                <button class="ghost-btn copy-btn" type="button" data-copy="<?php echo h($embedCode); ?>">Copy Snippet</button>
              </div>
            </div>

            <div class="install-guide" id="installGuideBox" aria-live="polite"></div>

            <div class="field full">
              <label>Secure iframe install snippet</label>
              <div class="embed-box"><code id="embedCode"><?php echo h($embedCode ?: 'Create or select a bot to generate the embed script.'); ?></code></div>
              <div class="panel-actions">
                <button class="pill-btn copy-btn" type="button" data-copy="<?php echo h($embedCode); ?>">Copy secure snippet</button>
              </div>
              <small class="input-help">This loader creates a sandboxed iframe. The chatbot runs inside Vani AI's isolated frame and the page snippet only mounts and resizes that frame.</small>
              <div class="notice" style="margin-top:12px">
                <strong>Security policy note:</strong> If the customer's website uses a strict Content Security Policy, allow Vani AI in <code>script-src</code>, <code>frame-src</code>, and <code>connect-src</code> for <code>https://vani.codrant.com</code>. Some website builders or checkout pages may restrict custom scripts or iframes.
              </div>
            </div>

            <div class="field full">
              <div class="inline-row" style="justify-content:space-between;gap:16px">
                <div>
                  <label>Website verification</label>
                  <small class="input-help">When enabled, this bot only loads on the website connected with this bot.</small>
                </div>
                <label class="switch" title="Enable website verification">
                  <input id="websiteVerificationToggle" type="checkbox" <?php echo $websiteVerificationEnabled ? 'checked' : ''; ?> aria-label="Enable website verification">
                  <span class="switch-slider"></span>
                </label>
              </div>
              <div class="notice" style="margin-top:12px">
                <strong>Status:</strong> <span id="verificationStatusText"><?php echo h($verificationStatus); ?></span><br>
                <strong>Bot website:</strong> <?php echo h($websiteName ?: 'Not set'); ?>
              </div>
            </div>

            <div class="field full">
              <div class="inline-row" style="justify-content:space-between;gap:16px">
                <div>
                  <label>Allowed domains</label>
                  <small class="input-help">When enabled, this bot only works on the domains listed below.</small>
                  <?php if (!$canUseAllowedDomains): ?><small class="input-help error">Business plan required.</small><?php endif; ?>
                </div>
                <label class="switch" title="Enable allowed domains">
                  <input id="allowedDomainsToggle" type="checkbox" <?php echo $allowedDomainsEnabled && $canUseAllowedDomains ? 'checked' : ''; ?> <?php echo $canUseAllowedDomains ? '' : 'disabled'; ?> aria-label="Enable allowed domains">
                  <span class="switch-slider"></span>
                </label>
              </div>
              <textarea id="allowedDomainsInput" placeholder="example.com&#10;www.example.com" <?php echo $canUseAllowedDomains ? '' : 'disabled'; ?>><?php echo h($allowedDomains); ?></textarea>
              <small class="input-help">Add one domain per line. You can also separate domains with commas.</small>
            </div>

            <small class="input-help full" id="integrationAutosaveStatus">Install and domain settings save automatically.</small>
          </div>

          <div class="section-body form-grid integration-subpanel" id="integration-subpanel-api">
            <div class="field full">
              <div class="section-head" style="padding:0">
                <div>
                  <h3>Customer API Security</h3>
                  <p class="muted">Create customer-safe API keys, restrict where they can be used, and rotate them without exposing admin secrets.</p>
                </div>
                <span class="tag <?php echo $canUseBusinessApi ? 'good' : 'bad'; ?>"><?php echo $canUseBusinessApi ? 'Business API enabled' : 'Business API required'; ?></span>
              </div>
              <?php if (!$canUseBusinessApi): ?>
                <div class="notice"><strong>Business plan required:</strong><br>API keys can be created after upgrading to Business. Webhooks are still available on paid plans.</div>
              <?php endif; ?>

              <div class="security-grid">
                <div class="security-card">
                  <h4>API integration guide</h4>
                  <p class="muted">Step-by-step reference for API keys, feedback, payment collection, analytics, filters, sample requests, webhooks, errors, and security.</p>
                  <?php if ($canUseBusinessApi): ?>
                    <a class="pill-btn" href="api_integration.php?bot=<?php echo h(urlencode($selectedBotId)); ?>">Open API guide</a>
                  <?php else: ?>
                    <button class="pill-btn" type="button" disabled>Business plan required</button>
                  <?php endif; ?>
                </div>

                <div class="security-card">
                  <h4>Create API key</h4>
                  <p class="muted">The full key is shown once. Only a hash is stored after creation.</p>
                  <div class="field">
                    <label>Key label</label>
                    <input id="apiKeyNameInput" value="Production key" maxlength="80" <?php echo $canUseBusinessApi ? '' : 'disabled'; ?>>
                  </div>
                  <div class="field">
                    <label>Daily rate limit</label>
                    <input id="apiKeyRateLimitInput" type="number" min="1" max="100000" value="<?php echo h(first_value($settings, ['rate_limit'], '1000')); ?>" <?php echo $canUseBusinessApi ? '' : 'disabled'; ?>>
                  </div>
                  <div class="field">
                    <label>Allowed server IPs</label>
                    <textarea id="apiKeyAllowedIpsInput" placeholder="203.0.113.10&#10;198.51.100.24" <?php echo $canUseBusinessApi ? '' : 'disabled'; ?>></textarea>
                    <small class="input-help">Optional. Add one IP per line or separate with commas.</small>
                  </div>
                  <div class="field">
                    <label>Allowed origins</label>
                    <textarea id="apiKeyAllowedOriginsInput" placeholder="https://example.com&#10;https://app.example.com" <?php echo $canUseBusinessApi ? '' : 'disabled'; ?>></textarea>
                    <small class="input-help">Optional. Use this when calls come from a browser app.</small>
                  </div>
                  <button class="pill-btn" type="button" id="createApiKeyBtn" <?php echo $canUseBusinessApi ? '' : 'disabled'; ?>>Create API key</button>
                  <div class="api-key-reveal" id="newApiKeyReveal">
                    <small class="input-help">Copy this now. It will not be shown again.</small>
                    <code class="api-key-code" id="newApiKeyCode"></code>
                    <button class="ghost-btn copy-btn" type="button" id="copyNewApiKeyBtn" data-copy="">Copy API key</button>
                  </div>
                </div>
              </div>
            </div>

            <div class="field full">
              <div class="section-head" style="padding:0">
                <div>
                  <h3>API Keys</h3>
                  <p class="muted">Rotate keys regularly and revoke keys that are no longer in use.</p>
                </div>
              </div>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Name</th><th>Prefix</th><th>Rate limit</th><th>Last used</th><th>Status</th><th>Action</th></tr></thead>
                  <tbody id="apiKeysTableBody">
                    <?php if (empty($apiKeyRows)): ?><tr><td colspan="6" class="empty">No API keys created yet.</td></tr><?php endif; ?>
                    <?php foreach ($apiKeyRows as $keyRow): ?>
                      <?php $revoked = !empty($keyRow['revoked_at']); ?>
                      <tr data-api-key-id="<?php echo h($keyRow['id'] ?? ''); ?>">
                        <td><?php echo h($keyRow['name'] ?? 'API key'); ?></td>
                        <td><code class="api-key-code"><?php echo h(($keyRow['key_prefix'] ?? '') . '...'); ?></code></td>
                        <td><?php echo h($keyRow['rate_limit_per_day'] ?? ''); ?>/day</td>
                        <td><?php echo h($keyRow['last_used_at'] ?? 'Never'); ?></td>
                        <td><span class="tag <?php echo $revoked ? 'bad' : 'good'; ?>"><span class="status-dot <?php echo $revoked ? 'off' : ''; ?>"></span><?php echo $revoked ? 'Revoked' : 'Active'; ?></span></td>
                        <td><?php if (!$revoked): ?><button class="danger-btn revoke-api-key-btn" type="button">Revoke</button><?php else: ?><span class="muted">No action</span><?php endif; ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="field full">
              <div class="security-card">
                <h4>Recent API usage</h4>
                <div class="mini-chart" id="apiUsageList">
                  <?php if (empty($apiUsageRows)): ?><p class="empty">No API usage logged yet.</p><?php endif; ?>
                  <?php foreach (array_slice($apiUsageRows, 0, 6) as $usageRow): ?>
                    <div class="inline-row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:8px 0">
                      <span><?php echo h(($usageRow['endpoint'] ?? 'API') . ' - ' . ($usageRow['status_code'] ?? '')); ?></span>
                      <small class="muted"><?php echo h($usageRow['created_at'] ?? ''); ?></small>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>

          <div class="section-body form-grid integration-subpanel" id="integration-subpanel-events">
            <div class="field full">
              <div class="security-grid">
                <div class="security-card">
                  <h4>Webhook destination</h4>
                  <p class="muted">Send verified leads and important events to your customer's system.</p>
                  <?php if (!$canUseWebhook): ?><small class="input-help error">Active paid plan required.</small><?php endif; ?>
                  <div class="field">
                    <label>Webhook URL</label>
                    <input id="webhookUrlInput" value="<?php echo h(first_value($settings, ['webhook_url'], '')); ?>" placeholder="https://example.com/webhooks/vani" <?php echo $canUseWebhook ? '' : 'disabled'; ?>>
                  </div>
                  <div class="field">
                    <label>Webhook secret</label>
                    <input id="webhookSecretInput" value="<?php echo h(first_value($settings, ['webhook_secret'], '')); ?>" placeholder="Optional signing secret" <?php echo $canUseWebhook ? '' : 'disabled'; ?>>
                    <small class="input-help">Use this to verify webhook signatures on your server.</small>
                  </div>
                  <div class="inline-row">
                    <button class="pill-btn" type="button" id="saveWebhookBtn" <?php echo $canUseWebhook ? '' : 'disabled'; ?>>Save webhook</button>
                    <button class="ghost-btn" type="button" id="testWebhookBtn" <?php echo $canUseWebhook ? '' : 'disabled'; ?>>Test webhook</button>
                  </div>
                </div>

                <div class="security-card">
                  <div class="inline-row" style="justify-content:space-between;gap:14px">
                    <div>
                      <h4>Live Chat Actions</h4>
                      <p class="muted">Let the customer's website react instantly to chatbot events such as chat open, messages, FAQ answers, unknown questions, lead capture, and WhatsApp clicks.</p>
                      <?php if (!$canUseLiveChatActions): ?><small class="input-help error">Business plan required.</small><?php endif; ?>
                    </div>
                    <label class="switch" title="Enable Live Chat Actions">
                      <input id="liveChatActionsToggle" type="checkbox" <?php echo $liveChatActionsEnabled && $canUseLiveChatActions ? 'checked' : ''; ?> <?php echo $canUseLiveChatActions ? '' : 'disabled'; ?> aria-label="Enable Live Chat Actions">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                  <small class="input-help">When ON, the widget dispatches safe browser events on the customer's website. When OFF, no live website events are emitted.</small>
                  <button class="pill-btn" type="button" id="saveLiveChatActionsBtn" <?php echo $canUseLiveChatActions ? '' : 'disabled'; ?>>Save Live Chat Actions</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Bot Settings tab content hidden for now; keep this code for later.
      <section class="tab-panel" id="bot-settings">
        <div class="panel">
          <div class="section-head"><h3>Bot Settings</h3></div>
          <div class="section-body form-grid">
            <div class="field"><label>API key</label><input id="apiKeyInput" value="<?php echo h(first_value($settings, ['api_key'], '')); ?>" placeholder="Not required for free plan"></div>
            <div class="field"><label>Rate limit</label><input id="rateLimitInput" type="number" min="1" value="<?php echo h(first_value($settings, ['rate_limit'], '100')); ?>"></div>
            <div class="field"><label>Enable chatbot</label><select id="activeInput"><option value="true" <?php echo $isActive ? 'selected' : ''; ?>>Enabled</option><option value="false" <?php echo !$isActive ? 'selected' : ''; ?>>Disabled</option></select></div>
            <div class="field"><label>Notification preferences</label><select id="notificationInput"><option value="weekly_summary">Email weekly summary</option><option value="unanswered_only">Important unanswered queries only</option><option value="off">Off</option></select></div>
            <div class="field full"><label>Allowed domains</label><textarea id="domainsInput" placeholder="example.com"><?php echo h(first_value($settings, ['allowed_domains'], '')); ?></textarea></div>
            <div class="field full"><button class="danger-btn" type="button" data-save-note="Delete data request">Delete data</button></div>
            <div class="panel-actions"><button class="pill-btn" type="button" id="saveSettingsBtn">Save bot settings</button></div>
          </div>
        </div>
      </section>
      -->

      <section class="tab-panel" id="lead-generation">
        <div class="panel">
          <div class="section-head">
            <div>
              <h3>Lead Generation Setup</h3>
              <p class="muted">Control what customer information the chatbot asks for before handing over a lead.</p>
              <div class="critical-save-note">IMPORTANT: Toggle changes save automatically. After editing the WhatsApp mobile number, use the Save WhatsApp number button beside that field.</div>
            </div>
          </div>
          <div class="section-body">
            <div class="lead-master">
              <div>
                <span class="eyebrow">Lead capture</span>
                <h3 style="margin-top:8px">Enable lead generation</h3>
                <p class="muted">Turn this on when you want the chatbot to collect contact details from users.</p>
              </div>
              <label class="switch" title="Enable lead generation">
                <input id="leadGenerationEnabled" class="lead-toggle" type="checkbox" <?php echo $leadEnabled ? 'checked' : ''; ?> aria-label="Enable lead generation">
                <span class="switch-slider"></span>
              </label>
            </div>

            <div class="lead-grid" id="leadServiceOptions" style="margin-top:16px">
              <div class="lead-section">
                <div class="lead-section-head">
                  <div>
                    <span class="eyebrow">Free Service</span>
                    <h3 style="margin-top:8px">Customer verification will be poor</h3>
                  </div>
                  <span class="tag">Free</span>
                </div>

                <div class="lead-option">
                  <div class="lead-option-top">
                    <div>
                      <h4>Get user location</h4>
                      <small>Ask users for their location during the chat flow.</small>
                    </div>
                    <label class="switch" title="Get user location">
                      <input id="leadCollectLocationToggle" class="lead-toggle" type="checkbox" <?php echo $leadCollectLocation ? 'checked' : ''; ?> aria-label="Get user location">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                </div>

                <div class="lead-option">
                  <div class="lead-option-top">
                    <div>
                      <h4>Collect email without OTP</h4>
                      <small>Ask users for an email address and save it without sending a verification code.</small>
                    </div>
                    <label class="switch" title="Collect email without OTP">
                      <input id="leadCollectEmailToggle" class="lead-toggle" type="checkbox" <?php echo $leadCollectEmail ? 'checked' : ''; ?> aria-label="Collect email without OTP">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                </div>

                <div class="lead-option">
                  <div class="lead-option-top">
                    <div>
                      <h4>Collect mobile without OTP</h4>
                      <small>Ask users for a phone number and save it without OTP verification.</small>
                    </div>
                    <label class="switch" title="Collect mobile without OTP">
                      <input id="leadCollectMobileToggle" class="lead-toggle" type="checkbox" <?php echo $leadCollectMobile ? 'checked' : ''; ?> aria-label="Collect mobile without OTP">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                </div>

                <div class="lead-option">
                  <div class="lead-option-top">
                    <div>
                      <h4>Notify lead by email</h4>
                      <small>Send an email notification when lead details are captured.</small>
                    </div>
                    <label class="switch" title="Notify lead by email">
                      <input id="leadEmailNotifyToggle" class="lead-toggle" type="checkbox" <?php echo $leadNotifyByEmail ? 'checked' : ''; ?> aria-label="Notify lead by email">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                  <div class="field">
                    <label>Notification email</label>
                    <input id="leadNotificationEmail" type="email" value="<?php echo h($leadNotificationEmail); ?>" placeholder="<?php echo h($email); ?>" autocomplete="email">
                    <small class="input-help" id="leadNotificationEmailHelp">Lead notifications can be sent to this email address.</small>
                  </div>
                </div>

                <!-- WhatsApp redirect moved to Paid Service -->
              </div>

              <div class="lead-section">
                <div class="lead-section-head">
                  <div>
                    <span class="eyebrow">Paid Service</span>
                    <h3 style="margin-top:8px">Real leads</h3>
                  </div>
                  <span class="tag good">Paid</span>
                </div>

                <div class="lead-option">
                  <div class="lead-option-top">
                    <div>
                      <h4>Collect email with OTP</h4>
                      <small>Verify the lead with an OTP sent to the user's email address.</small>
                      <?php if (!$canUseEmailOtp): ?><small class="input-help error">Subscription required.</small><?php endif; ?>
                    </div>
                    <label class="switch" title="Collect email with OTP">
                      <input id="leadEmailOtpToggle" class="lead-toggle" type="checkbox" <?php echo $leadVerifyEmailOtp ? 'checked' : ''; ?> aria-label="Collect email with OTP">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                </div>

                <div class="lead-option">
                  <div class="lead-option-top">
                    <div>
                      <h4>Mobile OTP verification</h4>
                      <small>Verify the lead with an OTP sent to the user's mobile number.</small>
                      <?php if (!$canUseMobileOtp): ?><small class="input-help error">Active paid plan required.</small><?php endif; ?>
                    </div>
                    <label class="switch" title="Mobile OTP verification">
                      <input id="leadMobileOtpToggle" class="lead-toggle" type="checkbox" <?php echo $leadVerifyMobileOtp ? 'checked' : ''; ?> aria-label="Mobile OTP verification">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                </div>

                <div class="lead-option">
                  <div class="lead-option-top">
                    <div>
                      <h4>Redirect to WhatsApp Business</h4>
                      <small>Send users to the customer's WhatsApp Business account after lead capture.</small>
                      <small class="input-help <?php echo $whatsappRedirectLocked ? 'error' : ''; ?>">WhatsApp redirection can be turned ON or OFF only 3 times per day. <?php echo $whatsappRedirectLocked ? 'It will be activated again after ' : h((string)max(0, 3 - $whatsappToggleCount)) . ' changes left today.'; ?><span id="whatsappLockTimer" data-remaining-seconds="<?php echo h((string)$whatsappLockSecondsRemaining); ?>"></span></small>
                      <small class="input-help <?php echo !$whatsappWalletCanEnable || $whatsappStoppedReason === 'insufficient_wallet_balance' ? 'error' : ''; ?>">WhatsApp Redirect costs ₹99 for 30 days. <?php echo !$whatsappWalletCanEnable ? 'Wallet balance must be at least ₹99 to turn this ON.' : 'Renewal deducts ₹99 every 30 days while ON.'; ?> <?php if ($whatsappStoppedReason === 'insufficient_wallet_balance'): ?>Last renewal charge was ₹0 and the service was turned OFF due to insufficient wallet balance.<?php endif; ?></small>
                      <?php if (!$canUseWhatsappRedirect): ?><small class="input-help error">Active paid plan required.</small><?php endif; ?>
                    </div>
                    <label class="switch" title="Redirect to WhatsApp Business">
                      <input id="whatsappLeadToggle" class="lead-toggle" type="checkbox" <?php echo $leadRedirectWhatsapp ? 'checked' : ''; ?> <?php echo ($whatsappRedirectLockedOn || (!$leadRedirectWhatsapp && !$whatsappWalletCanEnable)) ? 'disabled' : ''; ?> aria-label="Redirect to WhatsApp Business">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                  <div class="field">
                    <label>WhatsApp Business mobile number</label>
                    <div class="inline-row">
                      <input id="whatsappLeadNumber" type="tel" inputmode="tel" value="<?php echo h($leadWhatsappNumber); ?>" placeholder="+919876543210" autocomplete="tel" maxlength="16">
                      <button class="pill-btn" type="button" id="saveLeadSetupBtn">Save WhatsApp number</button>
                    </div>
                    <small class="input-help" id="whatsappLeadHelp">Use country code and digits only, for example +919876543210.</small>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="tab-panel" id="subscription">
        <div class="panel section-body">
          <span class="eyebrow">Wallet Recharge</span>
          <h2 style="margin:8px 0 10px">Hybrid Wallet Plans</h2>
          <p class="muted">Recharge your wallet with a minimum amount to unlock FAQ limits, lead verification, analytics, and integration benefits. Usage charges deduct as your customers use paid services.</p>

          <div class="subscription-wallet-note">
            <strong>100% recharge amount is credited to your wallet.</strong>
            Recharge with Starter, Growth, or Business to unlock that plan's benefits. The wallet is then used as-you-go for real usage, mainly when new website visitors verify by Email OTP or Mobile OTP, and for paid add-ons such as WhatsApp Redirect.
          </div>

          <div class="metrics" style="margin-top:18px">
            <div class="panel metric"><span>Current plan</span><strong><?php echo h($activePlan['name']); ?></strong><small><?php echo h($faqCount); ?>/<?php echo $planFaqLimit === PHP_INT_MAX ? 'Unlimited' : h($planFaqLimit); ?> FAQs used.</small></div>
          </div>

          <div class="pricing-grid">
            <div class="panel pricing-card <?php echo $activePlanId === 'starter' ? 'current-plan' : ''; ?>">
              <div class="pricing-head"><div><span class="eyebrow">Starter</span><h3>Starter Plan</h3></div><span class="tag">Small</span></div>
              <?php if ($activePlanId === 'starter'): ?><div class="current-plan-note">Current plan</div><?php endif; ?>
              <div class="price">₹199<small>minimum recharge</small></div>
              <div class="feature-list"><span class="is-included">100 FAQ answers for small websites</span><span class="is-included">Email and Mobile OTP verification for real leads</span><span class="is-included">Dedicated WhatsApp button and many more action items for FAQs</span><span class="is-included">Webhook support</span><span class="is-included">FAQ Action Suggestions</span><span class="is-included">Auto wallet recharge: below ₹50, recharge ₹199</span><span class="is-excluded">Live Chat Actions for real-time website reactions</span><span class="is-excluded">API Integration to migrate or save data in your database</span><span class="is-excluded">Analytics dashboard access</span><span class="is-excluded">Chat can run only on allowed domains</span></div>
              <button class="pill-btn billing-plan-btn" type="button" data-plan-id="starter">Recharge Wallet</button>
              <small class="muted">Best for portfolios, coaches, and small businesses.</small>
            </div>

            <div class="panel pricing-card featured <?php echo $activePlanId === 'growth' ? 'current-plan' : ''; ?>">
              <div class="pricing-head"><div><span class="eyebrow">Growth</span><h3>Growth Plan</h3></div><span class="tag good">Popular</span></div>
              <?php if ($activePlanId === 'growth'): ?><div class="current-plan-note">Current plan</div><?php endif; ?>
              <div class="price">₹499<small>minimum recharge</small></div>
              <div class="feature-list"><span class="is-included">300 FAQ capacity for growing businesses</span><span class="is-included">Email and Mobile OTP verification for real leads</span><span class="is-included">Dedicated WhatsApp button and many more action items for FAQs</span><span class="is-included">Webhook support</span><span class="is-included">FAQ Action Suggestions</span><span class="is-included">Auto wallet recharge: below ₹100, recharge ₹499</span><span class="is-included">Analytics access: Overview, Conversations, FAQ Insights, Leads</span><span class="is-included">Better wallet rates than Starter on email and mobile leads</span><span class="is-excluded">Live Chat Actions for real-time website reactions</span><span class="is-excluded">API Integration to migrate or save data in your database</span><span class="is-excluded">Chat can run only on allowed domains</span></div>
              <button class="pill-btn billing-plan-btn" type="button" data-plan-id="growth">Recharge Wallet</button>
              <small class="muted">Best for local businesses, agencies, and service providers.</small>
            </div>

            <div class="panel pricing-card <?php echo $activePlanId === 'business' ? 'current-plan' : ''; ?>">
              <div class="pricing-head"><div><span class="eyebrow">Business</span><h3>Business Plan</h3></div><span class="tag">Scale</span></div>
              <?php if ($activePlanId === 'business'): ?><div class="current-plan-note">Current plan</div><?php endif; ?>
              <div class="price">₹999<small>minimum recharge</small></div>
              <div class="feature-list"><span class="is-included">Unlimited FAQ capacity for larger businesses</span><span class="is-included">Email and Mobile combined widget</span><span class="is-included">Dedicated WhatsApp button and many more action items for FAQs</span><span class="is-included">Webhook support</span><span class="is-included">FAQ Action Suggestions</span><span class="is-included">Live Chat Actions for real-time website reactions</span><span class="is-included">Auto wallet recharge: below ₹200, recharge ₹999</span><span class="is-included">API Integration to migrate or save data in your database</span><span class="is-included">Advanced Analytics: Overview, Conversations, FAQ Insights, Leads, Pages, Real-Time, Reports Download</span><span class="is-included">Chat can run only on allowed domains</span></div>
              <button class="pill-btn billing-plan-btn" type="button" data-plan-id="business">Recharge Wallet</button>
              <small class="muted">Best for real estate, education institutes, marketing agencies, SaaS businesses, and larger teams.</small>
            </div>

          </div>

          <div class="notice subscription-checkout-panel" id="subscriptionCheckoutPanel">
            <div class="section-head">
              <div>
                <strong>Complete wallet recharge</strong><br>
                <span class="muted">Selected wallet plan: <span id="selectedSubscriptionPlanName">None</span>. Choose one-time recharge or auto payment authorization, then continue to Razorpay.</span>
              </div>
              <span class="tag good" id="selectedSubscriptionPlanPrice">Select a plan</span>
            </div>
            <div class="payment-choice-grid">
              <label class="payment-choice">
                <input type="radio" name="subscriptionPaymentMode" value="one_time" checked>
                <strong>One-time payment</strong>
                <small>Pay only for this purchase. No automatic card/debit-card recharge token will be saved.</small>
              </label>
              <label class="payment-choice">
                <input type="radio" name="subscriptionPaymentMode" value="auto">
                <strong>Auto payment</strong>
                <small>Use Razorpay recurring authorization so future wallet recharges can run automatically.</small>
              </label>
            </div>
            <div class="form-grid" style="margin-top:14px">
              <div class="field">
                <label for="subscriptionAutoPayNameInput">Customer name <span class="required-mark">*</span></label>
                <input id="subscriptionAutoPayNameInput" value="<?php echo h($razorpayCustomerName); ?>" autocomplete="name" required aria-required="true">
              </div>
              <div class="field">
                <label for="subscriptionAutoPayContactInput">Mobile number with country code <span class="required-mark">*</span></label>
                <input id="subscriptionAutoPayContactInput" value="<?php echo h($razorpayCustomerContact); ?>" placeholder="+919876543210" autocomplete="tel" required aria-required="true">
              </div>
              <small class="input-help full" id="subscriptionRequiredFieldsHelp"><span class="required-mark">*</span> Customer name and mobile number are required for wallet recharge. They prefill only after the Profile tab has saved these details.</small>
            </div>
            <div class="panel-actions">
              <button class="pill-btn" type="button" id="continueSubscriptionPaymentBtn">Continue to Payment</button>
            </div>
          </div>

          <div class="notice" style="margin-top:18px">
            <div class="section-head" style="padding:0;align-items:flex-start">
              <div>
                <strong>Stop automatic payment</strong><br>
                <span class="muted">Unsubscribe from future auto payments. Remaining wallet balance stays usable on your current plan until it reaches zero.</span>
              </div>
              <span class="tag <?php echo $activePlanId === 'free' ? 'bad' : 'good'; ?>" id="subscriptionServiceStatusTag"><?php echo h($isCancelledWalletAccess ? 'Wallet Active' : ($activePlanId === 'free' ? 'Free' : 'Active')); ?></span>
            </div>
            <p class="muted" style="margin-top:12px">
              Associated plan: <?php echo h($activePlan['name']); ?>. Wallet balance: <?php echo h(billing_rupees($billingWalletPaise)); ?>. Auto payment status: <?php echo h($isCancelledWalletAccess ? 'Stopped' : ucfirst($savedPaymentMethodStatus)); ?>.
              <?php if ($activePlanId !== 'free' && $savedPaymentMethodStatus !== 'active' && !$isCancelledWalletAccess): ?>This wallet recharge was completed without automatic payment, so there is no auto payment to unsubscribe.<?php endif; ?>
              <?php if ($isCancelledWalletAccess): ?>You will continue on <?php echo h($activePlan['name']); ?> until the wallet reaches zero, then the account will move to Free service.<?php endif; ?>
            </p>
            <div class="panel-actions">
              <button class="danger-btn" type="button" id="cancelSubscriptionBtn" <?php echo $activePlanId === 'free' || $isCancelledWalletAccess || $savedPaymentMethodStatus !== 'active' ? 'disabled' : ''; ?>>
                <?php echo h($isCancelledWalletAccess ? 'Auto Payment Stopped' : ($activePlanId === 'free' ? 'Free Service Active' : ($savedPaymentMethodStatus !== 'active' ? 'No Auto Payment Saved' : 'Unsubscribe Auto Payment'))); ?>
              </button>
            </div>
          </div>
        </div>
      </section>

      <section class="tab-panel" id="profile">
        <div class="panel">
          <div class="section-head">
            <div>
              <h3>Customer Profile</h3>
              <p class="muted">This is your account identity. It is separate from chatbot setup and bot settings.</p>
            </div>
          </div>
          <div class="section-body profile-grid">
            <div class="profile-photo">
              <div class="profile-avatar" id="profileAvatarPreview">
                <?php if (!empty($profile['avatar_url'])): ?>
                  <img src="<?php echo h($profile['avatar_url']); ?>" alt="Profile avatar">
                <?php else: ?>
                  <?php echo h($initials); ?>
                <?php endif; ?>
              </div>
              <div class="field">
                <label>Image URL or avatar</label>
                <input id="profileAvatarInput" value="<?php echo h($profile['avatar_url'] ?? ''); ?>" placeholder="https://example.com/photo.jpg">
                <button class="ghost-btn" type="button" id="generateAvatarBtn">Create avatar</button>
              </div>
            </div>

            <div class="form-grid">
              <div class="field"><label>First name</label><input id="firstNameInput" value="<?php echo h($profileFirstName); ?>" autocomplete="given-name"></div>
              <div class="field"><label>Last name</label><input id="lastNameInput" value="<?php echo h($profileLastName); ?>" autocomplete="family-name"></div>
              <div class="field full"><label>Account email</label><input id="profileEmailInput" value="<?php echo h($email); ?>" readonly></div>
              <div class="field"><label>Country code</label><input id="countryCodeInput" list="countryCodeList" value="<?php echo h($profile['country_code'] ?? '+91'); ?>" placeholder="+91" title="Type any country calling code"></div>
              <div class="field"><label>Mobile number</label><input id="mobileInput" value="<?php echo h($profile['mobile_number'] ?? ''); ?>" placeholder="9876543210" autocomplete="tel"></div>
              <div class="field full"><label>Address line 1</label><input id="address1Input" value="<?php echo h($profile['address_line1'] ?? ''); ?>" autocomplete="address-line1"></div>
              <div class="field full"><label>Address line 2</label><input id="address2Input" value="<?php echo h($profile['address_line2'] ?? ''); ?>" autocomplete="address-line2"></div>
              <div class="field"><label>City</label><input id="cityInput" value="<?php echo h($profile['city'] ?? ''); ?>" autocomplete="address-level2"></div>
              <div class="field"><label>State / Region</label><input id="stateInput" value="<?php echo h($profile['state_region'] ?? ''); ?>" autocomplete="address-level1"></div>
              <div class="field"><label>Country</label><input id="countryInput" value="<?php echo h($profile['country'] ?? 'India'); ?>" autocomplete="country-name"></div>
              <div class="field"><label>Postal code</label><input id="postalInput" value="<?php echo h($profile['postal_code'] ?? ''); ?>" autocomplete="postal-code"></div>
              <div class="field full"><label>Location notes</label><textarea id="locationInput" placeholder="Office, branch, timezone, preferred contact hours"><?php echo h($profile['location_notes'] ?? ''); ?></textarea></div>
              <div class="security-note">
                <span>Password changes are handled through secure email verification.</span>
                <a class="ghost-btn" href="forgot-password.php">Change password</a>
              </div>
            </div>
            <div class="panel-actions"><button class="pill-btn" type="button" id="saveProfileBtn">Save profile</button></div>
          </div>
        </div>
        <datalist id="countryCodeList">
          <option value="+1">United States / Canada</option>
          <option value="+44">United Kingdom</option>
          <option value="+91">India</option>
          <option value="+971">United Arab Emirates</option>
        </datalist>
      </section>

      <section class="tab-panel" id="billing">
        <div class="panel section-body">
          <div class="section-head" style="padding:0">
            <div>
              <span class="eyebrow">Billing</span>
              <h2 style="margin:8px 0 10px">Wallet Transactions</h2>
              <p class="muted">Complete summary of wallet credits and deductions for wallet recharges, OTP verifications, leads, WhatsApp redirects, and other paid usage.</p>
            </div>
            <div class="panel-actions" style="margin:0">
              <a class="ghost-btn" href="invoices.php?bot=<?php echo urlencode($selectedBotId); ?>">Invoices</a>
              <button class="ghost-btn" type="button" id="refreshBillingBtn">Refresh Billing</button>
            </div>
          </div>

          <div class="metrics" style="margin-top:18px">
            <div class="panel metric"><span>Wallet balance</span><strong><?php echo h(billing_rupees($billingWalletPaise)); ?></strong><small>Available for paid usage.</small></div>
            <div class="panel metric"><span>Current plan</span><strong><?php echo h($activePlan['name']); ?></strong><small>Wallet plan status: <?php echo h($isCancelledWalletAccess ? 'cancelled, wallet access active' : $subscriptionStatus); ?></small></div>
            <div class="panel metric billing-model-metric"><span class="billing-model-head">Billing model <span class="help-tip billing-help-tip" tabindex="0" aria-label="How billing works for your current plan" data-tip="<?php echo h($billingPlanHelpText); ?>">?</span></span><strong>Hybrid</strong><small>Wallet recharge plus usage deductions.</small></div>
            <div class="panel metric"><span>Total credited</span><strong><?php echo h(billing_rupees($walletCreditPaise)); ?></strong><small>Money added to wallet.</small></div>
            <div class="panel metric"><span>Total deducted</span><strong><?php echo h(billing_rupees($walletDebitPaise)); ?></strong><small>Paid feature usage.</small></div>
            <div class="panel metric"><span>Transactions</span><strong><?php echo h(count($walletTransactionRows)); ?></strong><small>Latest wallet activity.</small></div>
          </div>

          <?php if ($whatsappStoppedReason === 'insufficient_wallet_balance'): ?>
            <div class="notice" style="margin-top:18px">
              <strong>WhatsApp Redirect stopped:</strong><br>
              Renewal charge shown as <?php echo h(billing_rupees(0)); ?> because wallet balance was below <?php echo h(billing_rupees($whatsappFailedChargePaise ?: $whatsappChargePaise)); ?>. WhatsApp redirection is OFF until you recharge wallet and turn it ON again.
            </div>
          <?php endif; ?>

          <form class="billing-filter" method="get" action="dashboard.php#billing">
            <?php if ($selectedBotId !== ''): ?><input type="hidden" name="bot" value="<?php echo h($selectedBotId); ?>"><?php endif; ?>
            <input type="hidden" name="analytics_range" value="<?php echo h($analyticsRange); ?>">
            <?php if ($analyticsRange === 'custom'): ?>
              <input type="hidden" name="date_from" value="<?php echo h($analyticsFrom); ?>">
              <input type="hidden" name="date_to" value="<?php echo h($analyticsTo); ?>">
            <?php endif; ?>
            <div class="field">
              <label for="billingFromInput">From date</label>
              <input id="billingFromInput" type="date" name="billing_from" value="<?php echo h($billingFromInput); ?>">
            </div>
            <div class="field">
              <label for="billingToInput">To date</label>
              <input id="billingToInput" type="date" name="billing_to" value="<?php echo h($billingToInput); ?>">
            </div>
            <button class="pill-btn" type="submit">Apply</button>
            <a class="ghost-btn" href="dashboard.php<?php echo $selectedBotId !== '' ? '?' . http_build_query(['bot' => $selectedBotId]) : ''; ?>#billing">Clear</a>
            <p class="muted billing-filter-summary"><?php echo h($billingRangeLabel); ?>. Totals and transactions below use this billing date filter.</p>
          </form>

          <div class="table-wrap" style="margin-top:18px">
            <table>
              <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Amount</th><th>Balance After</th><th>Reference</th></tr></thead>
              <tbody>
                <?php if (empty($walletTransactionRows)): ?>
                  <tr><td colspan="6" class="empty">No wallet transactions yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($walletTransactionRows as $txn): ?>
                  <?php
                    $type = (string)($txn['transaction_type'] ?? '');
                    $billingReference = dashboard_billing_reference_display($txn);
                  ?>
                  <tr>
                    <td>
                      <span class="billing-date" data-billing-date="<?php echo h($txn['created_at'] ?? ''); ?>"><?php echo h(dashboard_billing_date_fallback($txn['created_at'] ?? '')); ?></span>
                      <small class="muted billing-date-zone">Shown in UTC until your timezone loads.</small>
                    </td>
                    <td><span class="tag <?php echo $type === 'credit' ? 'good' : 'bad'; ?>"><?php echo h(ucfirst($type)); ?></span></td>
                    <td><?php echo h($txn['description'] ?? ''); ?></td>
                    <td><?php echo h(($type === 'debit' ? '-' : '+') . billing_rupees((int)($txn['amount_paise'] ?? 0))); ?></td>
                    <td><?php echo h(billing_rupees((int)($txn['balance_after_paise'] ?? 0))); ?></td>
                    <td>
                      <strong><?php echo h($billingReference['label']); ?></strong>
                      <?php if ($billingReference['detail'] !== ''): ?><small class="muted"><?php echo h($billingReference['detail']); ?></small><?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>
  </main>
</div>
<div class="toast" id="toast">Copied</div>
<div class="razorpay-consent-backdrop" id="razorpayConsentModal" aria-hidden="true">
  <div class="razorpay-consent-card" role="dialog" aria-modal="true" aria-labelledby="razorpayConsentTitle">
    <h3 id="razorpayConsentTitle">Razorpay Checkout consent</h3>
    <p>Razorpay Checkout lets visitors pay directly through the Razorpay account and credentials configured by you for this chatbot.</p>
    <ul>
      <li>You are responsible for using your own valid Razorpay account, keeping credentials secure, and complying with Razorpay rules, payment laws, tax rules, refund rules, and customer protection requirements.</li>
      <li>You are responsible for the products, services, amounts, descriptions, fulfillment, refunds, chargebacks, disputes, and customer communication related to payments collected through your Razorpay account.</li>
      <li>Codrant and Vani AI do not receive any benefit from your customer payments and are not responsible for failed payments, disputes, refunds, chargebacks, fraud, misleading claims, delivery issues, or misuse by your business.</li>
      <li>You must not mislead visitors, collect unlawful payments, or use Razorpay Checkout for fraudulent activity. If misuse, fraud, or customer harm is identified, Codrant may permanently block your account and chatbot, preserve evidence, and pursue legal action where appropriate.</li>
    </ul>
    <label class="razorpay-consent-check">
      <input id="razorpayConsentCheckbox" type="checkbox">
      <span>I understand and accept full responsibility for Razorpay payment collection, customer disputes, refunds, fulfillment, compliance, credentials, and all payment-related communication for this chatbot.</span>
    </label>
    <div class="razorpay-consent-actions">
      <button class="ghost-btn" type="button" id="razorpayConsentCancelBtn">Cancel</button>
      <button class="pill-btn" type="button" id="razorpayConsentAcceptBtn">Accept and enable Razorpay</button>
    </div>
  </div>
</div>
<div class="upi-consent-backdrop" id="upiConsentModal" aria-hidden="true">
  <div class="upi-consent-card" role="dialog" aria-modal="true" aria-labelledby="upiConsentTitle">
    <h3 id="upiConsentTitle">UPI Redirect consent</h3>
    <p>UPI Redirect opens the visitor's UPI app and records the payment as pending. It does not automatically verify whether money was received.</p>
    <ul>
      <li>You are responsible for manually verifying every UPI payment before confirming an order, booking, delivery, or service.</li>
      <li>You may ask visitors to submit their UPI transaction ID to help with manual verification.</li>
      <li>Codrant and Vani AI do not receive any benefit from payments collected through your UPI ID and are not responsible for failed payments, disputes, fraud, refunds, delivery issues, misleading claims, or misuse by your business.</li>
      <li>You must not mislead visitors, collect unlawful payments, or use UPI Redirect for fraudulent activity. If misuse, fraud, or customer harm is identified, Codrant may permanently block your account and chatbot, preserve evidence, and pursue legal action where appropriate.</li>
    </ul>
    <label class="upi-consent-check">
      <input id="upiConsentCheckbox" type="checkbox">
      <span>I understand and accept full responsibility for UPI payment collection, manual verification, customer communication, disputes, refunds, and compliance for this chatbot.</span>
    </label>
    <div class="upi-consent-actions">
      <button class="ghost-btn" type="button" id="upiConsentCancelBtn">Cancel</button>
      <button class="pill-btn" type="button" id="upiConsentAcceptBtn">Accept and enable UPI</button>
    </div>
  </div>
</div>
<?php if ($profileNeedsSetup): ?>
<div class="profile-prompt-backdrop" id="profileSetupPrompt" aria-hidden="true">
  <div class="profile-prompt" role="dialog" aria-modal="true" aria-labelledby="profileSetupTitle">
    <div class="profile-prompt-head">
      <div>
        <span class="profile-prompt-badge">V</span>
        <h3 id="profileSetupTitle">Complete your profile</h3>
        <p class="muted">Add the basic details used for wallet recharge, billing, and support contact. You can close this now and finish it later from the Profile tab.</p>
      </div>
      <button class="profile-prompt-close" type="button" id="closeProfilePromptBtn" aria-label="Close profile setup">x</button>
    </div>
    <div class="profile-prompt-grid">
      <div class="field"><label>First name</label><input id="promptFirstNameInput" value="<?php echo h($profileFirstName); ?>" autocomplete="given-name"></div>
      <div class="field"><label>Last name</label><input id="promptLastNameInput" value="<?php echo h($profileLastName); ?>" autocomplete="family-name"></div>
      <div class="field"><label>Country code</label><input id="promptCountryCodeInput" list="countryCodeList" value="<?php echo h($profile['country_code'] ?? '+91'); ?>" placeholder="+91"></div>
      <div class="field"><label>Mobile number</label><input id="promptMobileInput" value="<?php echo h($profile['mobile_number'] ?? ''); ?>" placeholder="9876543210" autocomplete="tel"></div>
      <div class="field"><label>City</label><input id="promptCityInput" value="<?php echo h($profile['city'] ?? ''); ?>" autocomplete="address-level2"></div>
      <div class="field"><label>Country</label><input id="promptCountryInput" value="<?php echo h($profile['country'] ?? 'India'); ?>" autocomplete="country-name"></div>
    </div>
    <div class="profile-prompt-actions">
      <button class="ghost-btn" type="button" id="profilePromptLaterBtn">Later</button>
      <button class="pill-btn" type="button" id="saveProfilePromptBtn">Save basic profile</button>
    </div>
  </div>
</div>
<?php endif; ?>
<div class="bulk-report-backdrop" id="bulkFaqReportModal" aria-hidden="true">
  <div class="bulk-report-modal" role="dialog" aria-modal="true" aria-labelledby="bulkFaqReportTitle">
    <div class="bulk-report-head">
      <div>
        <h3 id="bulkFaqReportTitle">Bulk FAQ Upload Report</h3>
        <p class="muted">This report is temporary. Closing this window clears it from the page. Export is available in Excel format only.</p>
      </div>
      <button class="ghost-btn" type="button" id="closeBulkFaqReportBtn">Close</button>
    </div>
    <div class="bulk-report-body" id="bulkFaqReportBody"></div>
  </div>
</div>
<script defer src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script defer src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js"></script>
<script>
const tabs = document.querySelectorAll(".tab-btn");
const panels = document.querySelectorAll(".tab-panel");
const toast = document.getElementById("toast");
const themeToggle = document.getElementById("themeToggle");
const navToggle = document.getElementById("navToggle");
const accountToggle = document.getElementById("accountToggle");
const drawerOverlay = document.getElementById("drawerOverlay");
const accountToggleText = accountToggle?.textContent || "";
const dashboardLoadingOverlay = document.getElementById("dashboardLoadingOverlay");
const razorpayConsentModal = document.getElementById("razorpayConsentModal");
const razorpayConsentCheckbox = document.getElementById("razorpayConsentCheckbox");
const razorpayConsentAcceptBtn = document.getElementById("razorpayConsentAcceptBtn");
const razorpayConsentCancelBtn = document.getElementById("razorpayConsentCancelBtn");
const upiConsentModal = document.getElementById("upiConsentModal");
const upiConsentCheckbox = document.getElementById("upiConsentCheckbox");
const upiConsentAcceptBtn = document.getElementById("upiConsentAcceptBtn");
const upiConsentCancelBtn = document.getElementById("upiConsentCancelBtn");
const profileNeedsSetup = <?php echo js_json($profileNeedsSetup); ?>;
const profilePromptKey = <?php echo js_json($profilePromptKey); ?>;
let upiTermsAccepted = <?php echo js_json($paymentUpiTermsAccepted); ?>;
let razorpayTermsAccepted = <?php echo js_json($paymentRazorpayTermsAccepted); ?>;
let currentFaqCount = <?php echo js_json($faqCount); ?>;
const freeFaqLimit = <?php echo js_json($freeFaqLimit); ?>;
const faqLimitIsUnlimited = <?php echo js_json($planFaqLimit === PHP_INT_MAX); ?>;
const faqLimitLabel = <?php echo js_json($planFaqLimit === PHP_INT_MAX ? 'Unlimited' : (string)$planFaqLimit); ?>;
const selectedCustomerId = <?php echo js_json($selectedBotId); ?>;
const billingEmail = <?php echo js_json($email); ?>;
const leadPaidFeatures = <?php echo js_json([
  "email_otp" => $canUseEmailOtp,
  "mobile_otp" => $canUseMobileOtp,
  "whatsapp_redirect" => $canUseWhatsappRedirect
]); ?>;
const leadOtpAlreadyEnabled = <?php echo js_json([
  "email" => $leadVerifyEmailOtp && $canUseEmailOtp,
  "mobile" => $leadVerifyMobileOtp && $canUseMobileOtp
]); ?>;
const businessFeatures = <?php echo js_json([
  "api_access" => $canUseBusinessApi,
  "webhook_support" => $canUseWebhook,
  "human_handoff" => $canUseHumanHandoff,
  "allowed_domains" => $canUseAllowedDomains,
  "live_chat_actions" => $canUseLiveChatActions,
  "faq_action_suggestions" => $canUseFaqActionSuggestions,
  "faq_feedback" => $canUseFaqFeedback,
  "payment_collection" => $canUsePaymentCollection
]); ?>;
const paymentActions = <?php echo js_json(array_values(array_map(fn($row) => [
  "id" => (string)($row["id"] ?? ""),
  "label" => (string)($row["label"] ?? ""),
  "amount_paise" => (int)($row["amount_paise"] ?? 0),
  "payment_method" => (string)($row["payment_method"] ?? "razorpay"),
  "is_active" => filter_var($row["is_active"] ?? true, FILTER_VALIDATE_BOOLEAN)
], $paymentActionRows))); ?>;
const leadWalletCharges = <?php echo js_json([
  "fresh_email_lead" => billing_wallet_charge_paise($activePlanId, "fresh_email_lead"),
  "repeat_email_lead" => billing_wallet_charge_paise($activePlanId, "repeat_email_lead"),
  "reactivated_email_lead" => billing_wallet_charge_paise($activePlanId, "reactivated_email_lead"),
  "fresh_mobile_lead" => billing_wallet_charge_paise($activePlanId, "fresh_mobile_lead"),
  "repeat_mobile_lead" => billing_wallet_charge_paise($activePlanId, "repeat_mobile_lead"),
  "reactivated_mobile_lead" => billing_wallet_charge_paise($activePlanId, "reactivated_mobile_lead"),
  "whatsapp_redirect_addon" => billing_wallet_charge_paise($activePlanId, "whatsapp_redirect_addon")
]); ?>;
const whatsappRedirectLockedOn = <?php echo js_json($whatsappRedirectLockedOn); ?>;
const whatsappRedirectLocked = <?php echo js_json($whatsappRedirectLocked); ?>;
const walletBalancePaise = <?php echo js_json($billingWalletPaise); ?>;
const whatsappRedirectChargePaise = <?php echo js_json($whatsappChargePaise); ?>;
const vaniBrandLogo = <?php echo js_json($brandLogoDataUri); ?>;
const analyticsReport = <?php echo js_json([
  "bot_name" => $botName,
  "range_label" => $analyticsRangeLabel,
  "range_key" => $analyticsRange,
  "date_from" => $analyticsFrom,
  "date_to" => $analyticsTo,
  "previous_date_from" => $previousAnalyticsFrom,
  "previous_date_to" => $previousAnalyticsTo,
  "summary" => [
    "total_conversations" => $conversationCount,
    "total_messages" => $totalMessages,
    "unique_visitors" => $uniqueVisitorCount,
    "answered_queries_percent" => $accuracy,
    "unanswered_queries_percent" => $unansweredPercent,
    "avg_response_time_ms" => $avgResponseTimeMs,
    "leads_collected" => $leadCount,
    "unique_leads" => $uniqueLeadCount,
    "real_unique_leads" => $realUniqueLeadCount,
    "weak_unique_leads" => $weakLeadCount,
    "otp_verified_leads" => $verifiedLeadCount,
    "feedback_received" => $feedbackCount,
    "positive_feedback_percent" => $feedbackPositiveRate,
    "unique_feedback_users" => count($feedbackUniqueUsers),
    "payment_revenue" => billing_rupees($paymentAnalyticsRevenuePaise),
    "payment_attempts" => $paymentAnalyticsCount,
    "paid_payments" => $paymentAnalyticsPaidCount,
    "pending_payments" => $paymentAnalyticsPendingCount,
    "failed_payments" => $paymentAnalyticsFailedCount,
    "payment_conversion_percent" => $paymentAnalyticsConversionRate,
    "unique_payers" => count($paymentAnalyticsUniquePayers),
    "active_chatbots" => $activeChatbotCount,
    "most_active_page" => $mostActivePage,
    "returning_users_percent" => $returningUsersPercent,
    "avg_conversation_duration" => $avgConversationDuration
  ],
  "comparison" => [
    "current" => $analyticsCurrentSummary,
    "previous" => $analyticsPreviousSummary
  ],
  "daily_counts" => $dailyChartCounts,
  "daily_answered_counts" => $dailyAnsweredChartCounts,
  "daily_unanswered_counts" => $dailyUnansweredChartCounts,
  "daily_lead_counts" => $dailyLeadChartCounts,
  "daily_feedback_counts" => $feedbackDailyCounts,
  "daily_payment_counts" => $paymentDailyCounts,
  "daily_payment_revenue_paise" => $paymentDailyRevenuePaise,
  "hour_counts" => $hourChartCounts,
  "devices" => $deviceCounts,
  "browsers" => $browserCounts,
  "countries" => $countryCounts,
  "cities" => $cityCounts,
  "location_points" => array_values($locationPointRows),
  "city_clusters" => array_values($cityClusterRows),
  "funnel" => [
    ["label" => "Visitors", "value" => $uniqueVisitorCount],
    ["label" => "Chat Opened", "value" => $chatOpenedCount],
    ["label" => "Started Chat", "value" => $conversationCount],
    ["label" => "Shared Contact", "value" => $uniqueLeadCount],
    ["label" => "OTP Verified", "value" => $realUniqueLeadCount]
  ],
  "lead_quality" => [
    "real" => $realUniqueLeadCount,
    "weak" => $weakLeadCount,
    "email" => $emailLeadCount,
    "mobile" => $phoneLeadCount
  ],
  "lead_periods" => $leadPeriodStats,
  "feedback_values" => $feedbackValueCounts,
  "feedback_actions" => $feedbackActionTypeCounts,
  "payment_statuses" => $paymentStatusCounts,
  "payment_methods" => $paymentMethodCounts,
  "payment_actions" => $paymentActionCounts,
  "recent_payments" => array_values(array_map(function ($payment) use ($paymentActionById) {
    $action = $paymentActionById[(string)($payment['payment_action_id'] ?? '')] ?? [];
    return [
      "date" => substr((string)($payment["created_at"] ?? ""), 0, 10),
      "status" => (string)($payment["status"] ?? "created"),
      "method" => strtoupper((string)($payment["payment_method"] ?? "razorpay")),
      "amount" => billing_rupees((int)($payment["amount_paise"] ?? 0)),
      "amount_paise" => (int)($payment["amount_paise"] ?? 0),
      "payment_button" => (string)($action["label"] ?? "Deleted payment button"),
      "payer" => trim((string)($payment["payer_name"] ?? "") . " " . (string)($payment["payer_email"] ?? "")),
      "reference" => (string)($payment["razorpay_payment_id"] ?? ($payment["razorpay_order_id"] ?? "")),
      "source_page" => (string)($payment["source_url"] ?? "")
    ];
  }, array_slice($recentPaymentAnalyticsRows, 0, 100))),
  "recent_feedback" => array_values(array_map(function ($feedback) use ($faqActionById) {
    $action = $faqActionById[(string)($feedback['action_id'] ?? '')] ?? [];
    return [
      "date" => substr((string)($feedback["created_at"] ?? ""), 0, 10),
      "feedback" => dashboard_feedback_display_value((string)($feedback["feedback_value"] ?? "")),
      "action" => (string)($action["label"] ?? ($feedback["action_type"] ?? "")),
      "source_page" => (string)($feedback["source_url"] ?? "")
    ];
  }, array_slice($recentFeedbackRows, 0, 100))),
  "unique_leads" => array_values(array_map(fn($lead) => [
    "lead_type" => $lead["lead_type"] ?? "Weak",
    "email" => $lead["email"] ?? "",
    "phone_number" => $lead["phone_number"] ?? "",
    "email_otp_count" => $lead["email_otp_count"] ?? 0,
    "mobile_otp_count" => $lead["mobile_otp_count"] ?? 0,
    "total_records" => $lead["total_records"] ?? 0,
    "whatsapp_redirect_count" => $lead["whatsapp_redirect_count"] ?? 0,
    "source_pages" => implode(", ", array_keys($lead["source_pages"] ?? [])),
    "location" => $lead["location"] ?? "",
    "first_seen" => substr((string)($lead["first_seen"] ?? ""), 0, 10),
    "last_seen" => substr((string)($lead["last_seen"] ?? ""), 0, 10)
  ], array_slice($uniqueLeadRows, 0, 500))),
  "top_questions" => array_values(array_map(fn($item) => [
    "question" => $item["question"] ?? "",
    "count" => $item["count"] ?? 0,
    "success_rate" => !empty($item["count"]) ? round((($item["answered"] ?? 0) / max(1, $item["count"])) * 100) : 0
  ], array_slice($topQuestionCounts, 0, 25))),
  "unanswered_questions" => array_values(array_map(fn($item) => [
    "question" => $item["question"] ?? "",
    "source_page" => $item["source_page"] ?? "Unknown page",
    "date" => substr((string)($item["created_at"] ?? ""), 0, 10)
  ], array_slice($outsideFaqQuestions, 0, 25))),
  "source_pages" => array_values(array_map(fn($page) => [
    "page" => $page["page"] ?? "",
    "conversations" => $page["conversations"] ?? 0,
    "leads" => $page["leads"] ?? 0,
    "success_rate" => !empty($page["conversations"]) ? round((($page["answered"] ?? 0) / max(1, $page["conversations"])) * 100) : 0
  ], array_slice($sourcePageStats, 0, 25)))
]); ?>;

function setDrawer(type, open) {
  const isNav = type === "nav";
  document.body.classList.toggle("nav-open", isNav && open);
  document.body.classList.toggle("account-open", !isNav && open);
  drawerOverlay?.classList.toggle("show", open);
  navToggle?.setAttribute("aria-expanded", String(isNav && open));
  accountToggle?.setAttribute("aria-expanded", String(!isNav && open));
  accountToggle?.setAttribute("aria-label", !isNav && open ? "Close account menu" : "Open account menu");
  if (accountToggle) accountToggle.textContent = !isNav && open ? "x" : accountToggleText;
}

function closeDrawers() {
  document.body.classList.remove("nav-open", "account-open");
  drawerOverlay?.classList.remove("show");
  navToggle?.setAttribute("aria-expanded", "false");
  accountToggle?.setAttribute("aria-expanded", "false");
  accountToggle?.setAttribute("aria-label", "Open account menu");
  if (accountToggle) accountToggle.textContent = accountToggleText;
}

function showDashboardLoading(message = "Please wait while the selected chatbot data loads.") {
  if (!dashboardLoadingOverlay) return;
  const messageNode = dashboardLoadingOverlay.querySelector("span");
  if (messageNode) messageNode.textContent = message;
  dashboardLoadingOverlay.classList.add("active");
  dashboardLoadingOverlay.setAttribute("aria-hidden", "false");
  document.body.classList.add("dashboard-loading-active");
}

window.addEventListener("pageshow", () => {
  dashboardLoadingOverlay?.classList.remove("active");
  dashboardLoadingOverlay?.setAttribute("aria-hidden", "true");
  document.body.classList.remove("dashboard-loading-active");
});

navToggle?.addEventListener("click", () => {
  setDrawer("nav", !document.body.classList.contains("nav-open"));
});

accountToggle?.addEventListener("click", () => {
  setDrawer("account", !document.body.classList.contains("account-open"));
});

drawerOverlay?.addEventListener("click", closeDrawers);

document.addEventListener("keydown", event => {
  if (event.key === "Escape") closeDrawers();
});

function showToast(text) {
  toast.textContent = text;
  toast.classList.add("show");
  setTimeout(() => toast.classList.remove("show"), 1800);
}

function htmlEscape(value) {
  return String(value ?? "").replace(/[&<>"']/g, char => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[char]));
}

function openTab(id, updateHash = true) {
  const targetTab = document.querySelector(`.tab-btn[data-tab="${id}"]`);
  if (targetTab?.dataset.premiumLock) {
    alert(targetTab.dataset.premiumLock);
    openTab("subscription", updateHash);
    return;
  }
  tabs.forEach(tab => tab.classList.toggle("active", tab.dataset.tab === id));
  panels.forEach(panel => panel.classList.toggle("active", panel.id === id));
  targetTab?.scrollIntoView({
    block: "nearest",
    inline: "nearest",
    behavior: "smooth"
  });
  if (updateHash && location.hash !== "#" + id) history.replaceState(null, "", "#" + id);
  closeDrawers();
  if (id === "analytics") setTimeout(renderAnalyticsVisuals, 80);
}

tabs.forEach(tab => tab.addEventListener("click", () => openTab(tab.dataset.tab)));

document.getElementById("bot")?.addEventListener("change", event => {
  const select = event.currentTarget;
  const form = document.getElementById("botPickerForm");
  if (!form || !select.value) return;
  showDashboardLoading("Loading the selected chatbot dashboard. Please do not close or click anything.");
  requestAnimationFrame(() => {
    setTimeout(() => form.submit(), 60);
  });
});

function bindBillingRefresh() {
  document.getElementById("refreshBillingBtn")?.addEventListener("click", async event => {
    const button = event.currentTarget;
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = "Refreshing...";
    try {
      const response = await fetch(window.location.pathname + window.location.search, {cache: "no-store"});
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, "text/html");
      const freshBilling = doc.getElementById("billing");
      const currentBilling = document.getElementById("billing");
      if (!freshBilling || !currentBilling) throw new Error("Billing tab not found");
      currentBilling.innerHTML = freshBilling.innerHTML;
      bindBillingRefresh();
      bindRazorpayCustomerSetup();
      bindAutoRechargeMandate();
      formatBillingDatesForBrowser();
      showToast("Billing refreshed");
    } catch (error) {
      button.disabled = false;
      button.textContent = originalText;
      showToast("Billing could not be refreshed");
    }
  });
}

function bindRazorpayCustomerSetup() {
  document.getElementById("createRazorpayCustomerBtn")?.addEventListener("click", async event => {
    const button = event.currentTarget;
    if (button.disabled) return;
    const nameInput = document.getElementById("razorpayCustomerNameInput");
    const contactInput = document.getElementById("razorpayCustomerContactInput");
    const name = nameInput?.value.trim() || "";
    const contact = contactInput?.value.trim() || "";
    if (!selectedCustomerId) return showToast("Select or create a bot first");
    if (name.length < 3) return showToast("Enter customer name");
    if (!contact) return showToast("Enter mobile number with country code");
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = "Creating...";
    try {
      const response = await fetch("/api.php?action=create_razorpay_customer", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({customer_id: selectedCustomerId, name, contact})
      });
      const data = await response.json();
      if (!data.success) {
        button.disabled = false;
        button.textContent = originalText;
        return showToast(data.message || "Razorpay customer could not be created");
      }
      document.getElementById("razorpayCustomerIdText").textContent = data.razorpay_customer_id || "Linked";
      document.getElementById("razorpayCustomerContactText").textContent = data.contact || contact;
      const tag = document.getElementById("razorpayCustomerStatusTag");
      if (tag) {
        tag.textContent = "Linked";
        tag.classList.remove("bad");
        tag.classList.add("good");
      }
      if (nameInput) nameInput.disabled = true;
      if (contactInput) contactInput.disabled = true;
      button.textContent = "Customer Linked";
      showToast("Razorpay customer linked");
    } catch (error) {
      button.disabled = false;
      button.textContent = originalText;
      showToast("Razorpay customer could not be created");
    }
  });
}

function bindAutoRechargeMandate() {
  document.getElementById("authorizeAutoRechargeBtn")?.addEventListener("click", async event => {
    const button = event.currentTarget;
    if (button.disabled) return;
    if (!selectedCustomerId) return showToast("Select or create a bot first");
    if (!window.Razorpay) return showToast("Razorpay checkout could not be loaded");
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = "Creating mandate...";
    try {
      const orderResponse = await fetch("/api.php?action=create_auto_recharge_mandate_order", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({customer_id: selectedCustomerId})
      });
      const orderData = await orderResponse.json().catch(() => ({}));
      if (!orderData.success) {
        button.disabled = false;
        button.textContent = originalText;
        if (orderData.requires_customer) document.getElementById("createRazorpayCustomerBtn")?.focus();
        return showToast(orderData.message || "Mandate order could not be created");
      }
      button.disabled = false;
      button.textContent = originalText;
      const checkout = new Razorpay({
        key: orderData.key_id,
        amount: orderData.order.amount,
        currency: orderData.order.currency || "INR",
        name: "Vani AI",
        description: `${orderData.plan.name} auto wallet recharge mandate`,
        order_id: orderData.order.id,
        customer_id: orderData.razorpay_customer_id,
        recurring: true,
        remember_customer: true,
        prefill: {
          email: billingEmail,
          contact: orderData.contact || ""
        },
        readonly: {email: true},
        theme: {color: "#6366f1"},
        handler: async response => {
          showToast("Verifying mandate...");
          const verifyResponse = await fetch("/api.php?action=verify_auto_recharge_mandate", {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify(response)
          });
          const verifyData = await verifyResponse.json().catch(() => ({}));
          if (!verifyData.success) {
            showToast(verifyData.message || "Mandate verification failed");
            return;
          }
          document.getElementById("autoRechargeMandateStatusText").textContent = "Active";
          document.getElementById("autoRechargeTokenText").textContent = verifyData.token_id ? `${verifyData.token_id.slice(0, 10)}...` : "Saved";
          const tag = document.getElementById("autoRechargeMandateStatusTag");
          if (tag) {
            tag.textContent = "Ready";
            tag.classList.remove("bad");
            tag.classList.add("good");
          }
          button.disabled = true;
          button.textContent = "Auto Recharge Ready";
          showToast(verifyData.wallet_credited ? "Mandate active and wallet credited" : "Mandate active");
          setTimeout(() => location.reload(), 900);
        }
      });
      checkout.on("payment.failed", async response => {
        await recordRazorpayFailure({order_id: orderData.order.id}, response, "auto_recharge_mandate");
        showToast(razorpayFailureMessage(response, "Mandate authorization failed"));
      });
      checkout.open();
    } catch (error) {
      button.disabled = false;
      button.textContent = originalText;
      showToast("Mandate setup could not be started");
    }
  });
}

bindBillingRefresh();
bindRazorpayCustomerSetup();
bindAutoRechargeMandate();

function openAnalyticsTab(id, updateHash = true) {
  let target = document.getElementById(id) ? id : "analytics-overview";
  const targetButton = document.querySelector(`.analytics-tab-btn[data-analytics-tab="${target}"]`);
  if (targetButton?.dataset.premiumLock) {
    alert(targetButton.dataset.premiumLock);
    target = "analytics-overview";
    openTab("subscription");
  }
  document.querySelectorAll(".analytics-tab-btn").forEach(tab => {
    tab.classList.toggle("active", tab.dataset.analyticsTab === target);
  });
  document.querySelectorAll(".analytics-subpanel").forEach(panel => {
    panel.classList.toggle("active", panel.id === target);
  });
  if (updateHash) history.replaceState(null, "", "#analytics/" + target.replace("analytics-", ""));
  setTimeout(renderAnalyticsVisuals, 80);
}

document.querySelectorAll(".analytics-tab-btn").forEach(tab => {
  tab.addEventListener("click", () => {
    if (tab.dataset.premiumLock) {
      alert(tab.dataset.premiumLock);
      openTab("subscription");
      return;
    }
    openAnalyticsTab(tab.dataset.analyticsTab);
  });
});

function openFaqSubtab(target) {
  const targetButton = document.querySelector(`.faq-subtab-btn[data-faq-subtab="${target}"]`);
  if (targetButton?.dataset.premiumLock) {
    alert(targetButton.dataset.premiumLock);
    openTab("subscription");
    return;
  }
  if (!document.getElementById(target)) return;
  document.querySelectorAll(".faq-subtab-btn").forEach(item => {
    item.classList.toggle("active", item.dataset.faqSubtab === target);
  });
  document.querySelectorAll("#faqs .faq-subpanel").forEach(panel => {
    panel.classList.toggle("active", panel.id === target);
  });
}

document.querySelectorAll(".faq-subtab-btn").forEach(tab => {
  tab.addEventListener("click", () => {
    openFaqSubtab(tab.dataset.faqSubtab || "faq-subpanel-options");
  });
});

function collectDefaultFaqSettings(draftCard = null) {
  const settings = {};
  document.querySelectorAll(".default-faq-card").forEach(card => {
    const key = card.dataset.defaultFaqKey || "";
    const toggle = card.querySelector(".defaultFaqToggle");
    const question = card.querySelector(".defaultFaqQuestion");
    const answer = card.querySelector(".defaultFaqAnswer");
    if (!key || !toggle || !question || !answer) return;
    const useDraft = draftCard && card === draftCard;
    settings[key] = {
      enabled: !!toggle.checked,
      question: useDraft ? question.value.trim() : (card.dataset.savedQuestion || question.value.trim()),
      answer: useDraft ? answer.value.trim() : (card.dataset.savedAnswer || answer.value.trim())
    };
  });
  return settings;
}

async function saveDefaultFaqSettings({changedToggle = null, draftCard = null} = {}) {
  if (draftCard) {
    const question = draftCard.querySelector(".defaultFaqQuestion")?.value.trim() || "";
    const answer = draftCard.querySelector(".defaultFaqAnswer")?.value.trim() || "";
    if (!question || !answer) {
      showToast("Default FAQ question and answer are required");
      return false;
    }
  }
  const saved = await saveDashboardSettings(
    {default_faq_settings: collectDefaultFaqSettings(draftCard)},
    {
      successMessage: "Default FAQs saved",
      errorMessage: "Default FAQs could not be saved"
    }
  );
  if (!saved && changedToggle) {
    changedToggle.checked = !changedToggle.checked;
    const state = changedToggle.closest(".default-faq-card")?.querySelector(".default-faq-state");
    if (state) state.textContent = changedToggle.checked ? "ON" : "OFF";
  }
  if (saved && draftCard) {
    const question = draftCard.querySelector(".defaultFaqQuestion")?.value.trim() || "";
    const answer = draftCard.querySelector(".defaultFaqAnswer")?.value.trim() || "";
    draftCard.dataset.savedQuestion = question;
    draftCard.dataset.savedAnswer = answer;
    const saveButton = draftCard.querySelector(".defaultFaqSaveBtn");
    const status = draftCard.querySelector(".default-faq-edit-status");
    if (saveButton) saveButton.disabled = true;
    if (status) status.textContent = "Saved";
  }
  return saved;
}

document.querySelectorAll(".defaultFaqToggle").forEach(toggle => {
  toggle.addEventListener("change", event => {
    const card = event.currentTarget.closest(".default-faq-card");
    const state = card?.querySelector(".default-faq-state");
    if (state) state.textContent = event.currentTarget.checked ? "ON" : "OFF";
    saveDefaultFaqSettings({changedToggle: event.currentTarget});
  });
});

document.querySelectorAll(".defaultFaqQuestion,.defaultFaqAnswer").forEach(input => {
  input.addEventListener("input", () => {
    const card = input.closest(".default-faq-card");
    const saveButton = card?.querySelector(".defaultFaqSaveBtn");
    const status = card?.querySelector(".default-faq-edit-status");
    if (saveButton) saveButton.disabled = false;
    if (status) status.textContent = "Unsaved changes";
  });
});

document.querySelectorAll(".defaultFaqSaveBtn").forEach(button => {
  button.addEventListener("click", () => {
    const card = button.closest(".default-faq-card");
    if (!card) return;
    const status = card.querySelector(".default-faq-edit-status");
    if (status) status.textContent = "Saving...";
    saveDefaultFaqSettings({draftCard: card});
  });
});

function openIntegrationSubtab(target) {
  if (!document.getElementById(target)) return;
  document.querySelectorAll(".integration-subtab-btn").forEach(item => {
    item.classList.toggle("active", item.dataset.integrationSubtab === target);
  });
  document.querySelectorAll("#install .integration-subpanel").forEach(panel => {
    panel.classList.toggle("active", panel.id === target);
  });
}

document.querySelectorAll(".integration-subtab-btn").forEach(tab => {
  tab.addEventListener("click", () => {
    openIntegrationSubtab(tab.dataset.integrationSubtab || "integration-subpanel-install");
  });
});

const installGuides = {
  wix: {
    title: "Install on Wix",
    steps: [
      "Open your Wix dashboard and go to Settings > Custom Code.",
      "Choose Add Custom Code and paste the Vani AI secure iframe snippet.",
      "Set the code to load on All Pages and place it at the end of Body.",
      "Save, publish the website, then test the chatbot from your live domain."
    ]
  },
  shopify: {
    title: "Install on Shopify",
    steps: [
      "Open Shopify Admin and go to Online Store > Themes.",
      "Choose Edit code for the active theme.",
      "Open layout/theme.liquid and paste the Vani AI snippet before the closing body tag.",
      "Save the theme and open your storefront to confirm the chatbot appears."
    ]
  },
  gtm: {
    title: "Install with Google Tag Manager",
    steps: [
      "Open Google Tag Manager and create a new Custom HTML tag.",
      "Paste the Vani AI secure iframe snippet into the HTML field.",
      "Set the trigger to All Pages.",
      "Preview, confirm the chatbot loads, then publish the GTM container."
    ]
  }
};

document.querySelectorAll(".install-guide-btn").forEach(button => {
  button.addEventListener("click", () => {
    const guide = installGuides[button.dataset.guide || ""];
    const box = document.getElementById("installGuideBox");
    if (!guide || !box) return;
    box.innerHTML = `<strong>${htmlEscape(guide.title)}</strong><ol>${guide.steps.map(step => `<li>${htmlEscape(step)}</li>`).join("")}</ol>`;
    box.classList.add("active");
    box.scrollIntoView({block: "nearest", behavior: "smooth"});
  });
});

const analyticsHash = location.hash.startsWith("#analytics/") ? location.hash.split("/")[1] : "";
if (analyticsHash) {
  openTab("analytics", false);
  openAnalyticsTab("analytics-" + analyticsHash, false);
}

document.querySelectorAll("[data-jump]").forEach(btn => {
  btn.addEventListener("click", event => {
    const target = btn.dataset.jump;
    if (target) {
      event.preventDefault();
      openTab(target);
      if (target === "faqs") {
        openFaqSubtab("faq-subpanel-qa");
      }
      if (btn.dataset.question) {
        document.getElementById("faqQuestion").value = btn.dataset.question;
      }
      window.scrollTo({top:0, behavior:"smooth"});
    }
  });
});

document.querySelectorAll(".copy-btn").forEach(btn => {
  btn.addEventListener("click", async () => {
    const text = btn.dataset.copy || document.getElementById("embedCode")?.textContent || "";
    if (!text.trim()) return showToast("Nothing to copy yet");
    await navigator.clipboard.writeText(text);
    showToast("Copied to clipboard");
  });
});

function reportFileBase() {
  const bot = (analyticsReport.bot_name || "vani").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
  return `${bot || "vani"}-analytics-${analyticsReport.date_from}-to-${analyticsReport.date_to}`;
}

function downloadBlob(filename, content, type) {
  const blob = new Blob([content], {type});
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function csvValue(value) {
  const text = String(value ?? "");
  return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

function rowsToCsv(rows) {
  return rows.map(row => row.map(csvValue).join(",")).join("\n");
}

async function recordRazorpayFailure(reference, response, context = "checkout") {
  const error = response?.error || {};
  try {
    await fetch("/api.php?action=record_razorpay_failure", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({
        context,
        razorpay_order_id: reference?.order_id || error?.metadata?.order_id || "",
        razorpay_subscription_id: reference?.subscription_id || error?.metadata?.subscription_id || "",
        razorpay_payment_id: error?.metadata?.payment_id || "",
        error
      })
    });
  } catch (failureLogError) {
    console.warn("Razorpay failure logging skipped", failureLogError);
  }
}

function razorpayFailureMessage(response, fallback = "Payment could not be completed") {
  const error = response?.error || {};
  const reason = String(error.reason || error.code || "").toLowerCase();
  const description = String(error.description || "").trim();
  let detail = description || fallback;
  if (/insufficient|balance|fund/.test(reason + " " + description.toLowerCase())) {
    detail = "The card or account may not have enough balance. Please use another card or payment method.";
  } else if (/declin|bank|issuer/.test(reason + " " + description.toLowerCase())) {
    detail = "Your bank declined this payment. Please contact your bank or try another card.";
  } else if (/expired/.test(reason + " " + description.toLowerCase())) {
    detail = "This card appears to be expired. Please use another card.";
  } else if (/auth|otp|3d|verification|pin/.test(reason + " " + description.toLowerCase())) {
    detail = "Bank verification was not completed. Please retry and complete the OTP, PIN, or 3D Secure step.";
  } else if (/timeout|network|temporar|server|gateway/.test(reason + " " + description.toLowerCase())) {
    detail = "The payment gateway or network had a temporary issue. Please retry after a moment.";
  } else if (/cancel/.test(reason + " " + description.toLowerCase())) {
    detail = "The payment was cancelled before completion.";
  } else if (/card|method|instrument/.test(reason + " " + description.toLowerCase())) {
    detail = "This card or payment method could not be used. Please try another card or payment method.";
  }
  return `${detail} No wallet amount was added.`;
}

function currentAnalyticsFilterState() {
  const activeSection = document.querySelector(".analytics-tab-btn.active")?.textContent?.trim() || "Overview";
  const countryFocus = selectedAnalyticsCountry();
  return {
    bot: analyticsReport.bot_name || "Selected chatbot",
    range: analyticsReport.range_label || "",
    range_key: analyticsReport.range_key || "",
    date_from: analyticsReport.date_from || "",
    date_to: analyticsReport.date_to || "",
    previous_date_from: analyticsReport.previous_date_from || "",
    previous_date_to: analyticsReport.previous_date_to || "",
    country_focus: countryFocus || "All countries",
    exported_section: activeSection
  };
}

const analyticsCharts = new Map();

function analyticsThemeColors() {
  const style = getComputedStyle(document.body);
  return {
    ink: style.getPropertyValue("--ink").trim() || "#0f172a",
    muted: style.getPropertyValue("--muted").trim() || "#64748b",
    line: style.getPropertyValue("--line").trim() || "rgba(148,163,184,.35)",
    brand: style.getPropertyValue("--brand").trim() || "#6366f1",
    brand2: style.getPropertyValue("--brand-2").trim() || "#06b6d4"
  };
}

function analyticsEntries(objectValue, limit = 12) {
  return Object.entries(objectValue || {})
    .map(([name, value]) => ({name, value: Number(value) || 0}))
    .filter(item => item.value > 0)
    .sort((a, b) => b.value - a.value)
    .slice(0, limit);
}

function analyticsDateSeries() {
  const dateSet = new Set([
    ...Object.keys(analyticsReport.daily_counts || {}),
    ...Object.keys(analyticsReport.daily_answered_counts || {}),
    ...Object.keys(analyticsReport.daily_unanswered_counts || {}),
    ...Object.keys(analyticsReport.daily_lead_counts || {}),
    ...Object.keys(analyticsReport.daily_feedback_counts || {}),
    ...Object.keys(analyticsReport.daily_payment_counts || {}),
    ...Object.keys(analyticsReport.daily_payment_revenue_paise || {})
  ]);
  const dates = Array.from(dateSet).sort();
  return {
    dates,
    conversations: dates.map(date => Number(analyticsReport.daily_counts?.[date] || 0)),
    answered: dates.map(date => Number(analyticsReport.daily_answered_counts?.[date] || 0)),
    unanswered: dates.map(date => Number(analyticsReport.daily_unanswered_counts?.[date] || 0)),
    leads: dates.map(date => Number(analyticsReport.daily_lead_counts?.[date] || 0)),
    feedback: dates.map(date => Number(analyticsReport.daily_feedback_counts?.[date] || 0)),
    payments: dates.map(date => Number(analyticsReport.daily_payment_counts?.[date] || 0)),
    paymentRevenue: dates.map(date => Number(analyticsReport.daily_payment_revenue_paise?.[date] || 0) / 100)
  };
}

function chartElement(id) {
  const el = document.getElementById(id);
  if (!el || !el.offsetWidth) return null;
  return el;
}

function emptyChart(el, message = "No analytics data yet.") {
  el.innerHTML = `<div class="empty">${htmlEscape(message)}</div>`;
}

function setAnalyticsChart(id, option, emptyCheck) {
  const el = chartElement(id);
  if (!el) return;
  if (emptyCheck) {
    emptyChart(el);
    return;
  }
  if (!window.echarts) {
    emptyChart(el, "Chart library could not be loaded.");
    return;
  }
  const colors = analyticsThemeColors();
  let chart = analyticsCharts.get(id);
  if (!chart) {
    chart = echarts.init(el);
    analyticsCharts.set(id, chart);
  }
  chart.setOption({
    textStyle: {color: colors.ink, fontFamily: "inherit"},
    color: [colors.brand, colors.brand2, "#22c55e", "#f59e0b", "#ec4899", "#14b8a6"],
    grid: {left: 42, right: 18, top: 42, bottom: 42, containLabel: true},
    tooltip: {trigger: "axis", confine: true},
    legend: {top: 8, textStyle: {color: colors.muted}},
    ...option
  }, true);
  chart.off("click");
  chart.on("click", () => {
    const drillType = id === "analyticsQuestionChart" ? "questions" : (id === "analyticsPageChart" ? "pages" : "summary");
    const detail = analyticsDrilldownContent(drillType, id);
    openAnalyticsDrilldown(detail.title, detail.html);
  });
}

function renderAnalyticsBICharts() {
  const colors = analyticsThemeColors();
  const series = analyticsDateSeries();
  setAnalyticsChart("analyticsTrendChart", {
    xAxis: {type: "category", data: series.dates, axisLabel: {color: colors.muted}},
    yAxis: {type: "value", axisLabel: {color: colors.muted}, splitLine: {lineStyle: {color: colors.line}}},
    dataZoom: series.dates.length > 12 ? [{type: "inside"}, {type: "slider", height: 18, bottom: 8}] : [],
    series: [
      {name: "Conversations", type: "line", smooth: true, areaStyle: {opacity: .12}, data: series.conversations},
      {name: "Answered", type: "bar", stack: "answers", data: series.answered},
      {name: "Unanswered", type: "bar", stack: "answers", data: series.unanswered},
      {name: "Leads", type: "line", smooth: true, data: series.leads}
    ]
  }, !series.dates.length);

  const funnel = (analyticsReport.funnel || []).map(item => ({name: item.label, value: Number(item.value) || 0}));
  setAnalyticsChart("analyticsFunnelChart", {
    tooltip: {trigger: "item", formatter: "{b}: {c}", confine: true},
    series: [{
      type: "funnel",
      left: "8%",
      right: "8%",
      top: 42,
      bottom: 18,
      sort: "none",
      label: {formatter: "{b}\n{c}", color: colors.ink, fontWeight: 700},
      itemStyle: {borderColor: "rgba(255,255,255,.75)", borderWidth: 1},
      data: funnel
    }]
  }, !funnel.some(item => item.value > 0));

  const devices = analyticsEntries(analyticsReport.devices, 8);
  setAnalyticsChart("analyticsDeviceChart", {
    tooltip: {trigger: "item", formatter: "{b}: {c} ({d}%)", confine: true},
    legend: {bottom: 0, type: "scroll", textStyle: {color: colors.muted}},
    series: [{type: "pie", radius: ["42%", "70%"], center: ["50%", "46%"], label: {formatter: "{b}\n{c}"}, data: devices}]
  }, !devices.length);

  const questions = (analyticsReport.top_questions || []).slice(0, 8).map(item => ({name: item.question, value: Number(item.count) || 0}));
  setAnalyticsChart("analyticsQuestionChart", {
    grid: {left: 110, right: 18, top: 22, bottom: 24},
    xAxis: {type: "value", axisLabel: {color: colors.muted}, splitLine: {lineStyle: {color: colors.line}}},
    yAxis: {type: "category", data: questions.map(item => item.name), axisLabel: {color: colors.muted, width: 96, overflow: "truncate"}},
    series: [{name: "Questions", type: "bar", data: questions.map(item => item.value), label: {show: true, position: "right"}}]
  }, !questions.length);

  const pages = (analyticsReport.source_pages || []).slice(0, 8);
  setAnalyticsChart("analyticsPageChart", {
    grid: {left: 112, right: 18, top: 32, bottom: 28},
    tooltip: {trigger: "axis", axisPointer: {type: "shadow"}, confine: true},
    xAxis: {type: "value", axisLabel: {color: colors.muted}, splitLine: {lineStyle: {color: colors.line}}},
    yAxis: {type: "category", data: pages.map(item => item.page), axisLabel: {color: colors.muted, width: 100, overflow: "truncate"}},
    series: [
      {name: "Conversations", type: "bar", data: pages.map(item => Number(item.conversations) || 0)},
      {name: "Leads", type: "bar", data: pages.map(item => Number(item.leads) || 0)}
    ]
  }, !pages.length);

  const hourEntries = Object.entries(analyticsReport.hour_counts || {}).sort(([a], [b]) => Number(a) - Number(b));
  setAnalyticsChart("analyticsHourChart", {
    xAxis: {type: "category", data: hourEntries.map(([hour]) => `${hour}:00`), axisLabel: {color: colors.muted}},
    yAxis: {type: "value", axisLabel: {color: colors.muted}, splitLine: {lineStyle: {color: colors.line}}},
    series: [{name: "Queries", type: "bar", data: hourEntries.map(([, value]) => Number(value) || 0), label: {show: true, position: "top"}}]
  }, !hourEntries.length);

  const browsers = analyticsEntries(analyticsReport.browsers, 8);
  setAnalyticsChart("analyticsBrowserChart", {
    tooltip: {trigger: "item", formatter: "{b}: {c} ({d}%)", confine: true},
    legend: {bottom: 0, type: "scroll", textStyle: {color: colors.muted}},
    series: [{type: "pie", radius: "68%", center: ["50%", "46%"], label: {formatter: "{b}\n{c}"}, data: browsers}]
  }, !browsers.length);

  setAnalyticsChart("analyticsLeadTrendChart", {
    xAxis: {type: "category", data: series.dates, axisLabel: {color: colors.muted}},
    yAxis: {type: "value", axisLabel: {color: colors.muted}, splitLine: {lineStyle: {color: colors.line}}},
    series: [{name: "Leads", type: "line", smooth: true, areaStyle: {opacity: .16}, data: series.leads}]
  }, !series.leads.some(value => value > 0));

  const leadQuality = analyticsReport.lead_quality || {};
  const leadQualityRows = [
    {name: "Real Leads", value: Number(leadQuality.real) || 0},
    {name: "Weak Leads", value: Number(leadQuality.weak) || 0},
    {name: "Email Contacts", value: Number(leadQuality.email) || 0},
    {name: "Mobile Contacts", value: Number(leadQuality.mobile) || 0}
  ];
  setAnalyticsChart("analyticsLeadQualityChart", {
    xAxis: {type: "category", data: leadQualityRows.map(item => item.name), axisLabel: {color: colors.muted, interval: 0, rotate: 18}},
    yAxis: {type: "value", axisLabel: {color: colors.muted}, splitLine: {lineStyle: {color: colors.line}}},
    series: [{name: "Lead Quality", type: "bar", data: leadQualityRows.map(item => item.value), label: {show: true, position: "top"}}]
  }, !leadQualityRows.some(item => item.value > 0));

  setAnalyticsChart("analyticsFeedbackTrendChart", {
    xAxis: {type: "category", data: series.dates, axisLabel: {color: colors.muted}},
    yAxis: {type: "value", axisLabel: {color: colors.muted}, splitLine: {lineStyle: {color: colors.line}}},
    series: [{name: "Feedback", type: "line", smooth: true, areaStyle: {opacity: .16}, data: series.feedback}]
  }, !series.feedback.some(value => value > 0));

  const feedbackValues = analyticsEntries(analyticsReport.feedback_values, 10);
  setAnalyticsChart("analyticsFeedbackValueChart", {
    tooltip: {trigger: "item", formatter: "{b}: {c} ({d}%)", confine: true},
    legend: {bottom: 0, type: "scroll", textStyle: {color: colors.muted}},
    series: [{type: "pie", radius: ["42%", "70%"], center: ["50%", "46%"], label: {formatter: "{b}\n{c}"}, data: feedbackValues}]
  }, !feedbackValues.length);

  const feedbackActions = analyticsEntries(analyticsReport.feedback_actions, 10);
  setAnalyticsChart("analyticsFeedbackActionChart", {
    grid: {left: 116, right: 18, top: 28, bottom: 28},
    xAxis: {type: "value", axisLabel: {color: colors.muted}, splitLine: {lineStyle: {color: colors.line}}},
    yAxis: {type: "category", data: feedbackActions.map(item => item.name), axisLabel: {color: colors.muted, width: 104, overflow: "truncate"}},
    series: [{name: "Feedback", type: "bar", data: feedbackActions.map(item => item.value), label: {show: true, position: "right"}}]
  }, !feedbackActions.length);

  setAnalyticsChart("analyticsPaymentRevenueChart", {
    xAxis: {type: "category", data: series.dates, axisLabel: {color: colors.muted}},
    yAxis: [
      {type: "value", name: "Revenue", axisLabel: {color: colors.muted}, splitLine: {lineStyle: {color: colors.line}}},
      {type: "value", name: "Attempts", axisLabel: {color: colors.muted}, splitLine: {show: false}}
    ],
    series: [
      {name: "Revenue", type: "bar", data: series.paymentRevenue, label: {show: true, position: "top", formatter: params => params.value ? `₹${params.value}` : ""}},
      {name: "Attempts", type: "line", yAxisIndex: 1, smooth: true, data: series.payments}
    ]
  }, !series.paymentRevenue.some(value => value > 0) && !series.payments.some(value => value > 0));

  const paymentStatuses = analyticsEntries(analyticsReport.payment_statuses, 8);
  setAnalyticsChart("analyticsPaymentStatusChart", {
    tooltip: {trigger: "item", formatter: "{b}: {c} ({d}%)", confine: true},
    legend: {bottom: 0, type: "scroll", textStyle: {color: colors.muted}},
    series: [{type: "pie", radius: ["42%", "70%"], center: ["50%", "46%"], label: {formatter: "{b}\n{c}"}, data: paymentStatuses}]
  }, !paymentStatuses.length);

  const paymentMethods = analyticsEntries(analyticsReport.payment_methods, 8);
  setAnalyticsChart("analyticsPaymentMethodChart", {
    tooltip: {trigger: "item", formatter: "{b}: {c} ({d}%)", confine: true},
    legend: {bottom: 0, type: "scroll", textStyle: {color: colors.muted}},
    series: [{type: "pie", radius: "68%", center: ["50%", "46%"], label: {formatter: "{b}\n{c}"}, data: paymentMethods}]
  }, !paymentMethods.length);

  const paymentActions = analyticsEntries(analyticsReport.payment_actions, 10);
  setAnalyticsChart("analyticsPaymentActionChart", {
    grid: {left: 118, right: 18, top: 28, bottom: 28},
    xAxis: {type: "value", axisLabel: {color: colors.muted}, splitLine: {lineStyle: {color: colors.line}}},
    yAxis: {type: "category", data: paymentActions.map(item => item.name), axisLabel: {color: colors.muted, width: 106, overflow: "truncate"}},
    series: [{name: "Payments", type: "bar", data: paymentActions.map(item => item.value), label: {show: true, position: "right"}}]
  }, !paymentActions.length);
}

function renderAnalyticsVisuals() {
  renderAnalyticsBICharts();
  renderAnalyticsWorldMap();
  analyticsCharts.forEach(chart => chart.resize());
}

window.addEventListener("resize", () => {
  analyticsCharts.forEach(chart => chart.resize());
});

function analyticsTable(headers, rows) {
  if (!rows.length) return '<p class="empty">No matching analytics rows yet.</p>';
  return `<div class="table-wrap"><table><thead><tr>${headers.map(header => `<th>${htmlEscape(header)}</th>`).join("")}</tr></thead><tbody>${rows.join("")}</tbody></table></div>`;
}

function openAnalyticsDrilldown(title, html) {
  const drawer = document.getElementById("analyticsDrilldown");
  const heading = document.getElementById("analyticsDrilldownTitle");
  const body = document.getElementById("analyticsDrilldownBody");
  if (!drawer || !heading || !body) return;
  heading.textContent = title;
  body.innerHTML = html;
  drawer.classList.add("open");
}

function analyticsDrilldownContent(type, key) {
  if (type === "summary") {
    const current = analyticsReport.comparison?.current || {};
    const previous = analyticsReport.comparison?.previous || {};
    const rows = Object.keys(current).map(metric => `<tr><td>${htmlEscape(metric.replace(/_/g, " "))}</td><td>${htmlEscape(current[metric])}</td><td>${htmlEscape(previous[metric] ?? 0)}</td></tr>`);
    return {
      title: key === "leads" ? "Lead comparison" : "Period comparison",
      html: analyticsTable(["Metric", "Current", "Previous"], rows)
    };
  }
  if (type === "questions") {
    const rows = (analyticsReport.top_questions || []).map(item => `<tr><td>${htmlEscape(item.question)}</td><td>${htmlEscape(item.count)}</td><td>${htmlEscape(item.success_rate)}%</td></tr>`);
    return {title: "Question drill-down", html: analyticsTable(["Question", "Count", "Success"], rows)};
  }
  if (type === "pages") {
    const rows = (analyticsReport.source_pages || []).map(item => `<tr><td>${htmlEscape(item.page)}</td><td>${htmlEscape(item.conversations)}</td><td>${htmlEscape(item.leads)}</td><td>${htmlEscape(item.success_rate)}%</td></tr>`);
    return {title: "Page drill-down", html: analyticsTable(["Page", "Conversations", "Leads", "Success"], rows)};
  }
  return {title: "Analytics detail", html: '<p class="empty">No drill-down data available.</p>'};
}

document.querySelectorAll("[data-drilldown-type]").forEach(item => {
  item.addEventListener("click", () => {
    const detail = analyticsDrilldownContent(item.dataset.drilldownType, item.dataset.drilldownKey || "");
    openAnalyticsDrilldown(detail.title, detail.html);
  });
});

document.getElementById("closeAnalyticsDrilldownBtn")?.addEventListener("click", () => {
  document.getElementById("analyticsDrilldown")?.classList.remove("open");
});

function worldMapCountryName(name) {
  const aliases = {
    "usa": "United States of America",
    "us": "United States of America",
    "united states": "United States of America",
    "united states of america": "United States of America",
    "uk": "United Kingdom",
    "uae": "United Arab Emirates",
    "russia": "Russia",
    "south korea": "Korea",
    "korea, republic of": "Korea",
    "north korea": "Dem. Rep. Korea",
    "vietnam": "Vietnam",
    "viet nam": "Vietnam",
    "iran": "Iran",
    "syria": "Syria",
    "tanzania": "Tanzania",
    "democratic republic of the congo": "Dem. Rep. Congo",
    "congo": "Congo",
    "czech republic": "Czech Rep.",
    "laos": "Lao PDR",
    "brunei": "Brunei",
    "bolivia": "Bolivia",
    "venezuela": "Venezuela"
  };
  const key = String(name || "").trim().toLowerCase();
  return aliases[key] || String(name || "").trim();
}

let analyticsWorldMapChart = null;
let analyticsWorldMapJson = null;
let analyticsWorldMapResizeBound = false;

function selectedAnalyticsCountry() {
  return document.getElementById("analyticsCountryFocus")?.value || "";
}

function sameWorldMapCountry(a, b) {
  return worldMapCountryName(a).toLowerCase() === worldMapCountryName(b).toLowerCase();
}

async function renderAnalyticsWorldMap() {
  const mapEl = document.getElementById("analyticsWorldMap");
  const fallback = document.getElementById("analyticsWorldMapFallback");
  if (!mapEl) return;
  if (!mapEl.offsetWidth) return;
  const focusedCountry = selectedAnalyticsCountry();
  const countryEntries = Object.entries(analyticsReport.countries || {})
    .filter(([, count]) => Number(count) > 0)
    .filter(([country]) => !focusedCountry || sameWorldMapCountry(country, focusedCountry))
    .sort((a, b) => Number(b[1]) - Number(a[1]));
  const cityClusters = (analyticsReport.city_clusters || [])
    .filter(item => !focusedCountry || (item.country && sameWorldMapCountry(item.country, focusedCountry)))
    .filter(item => Number.isFinite(Number(item.lat)) && Number.isFinite(Number(item.lon)))
    .map(item => ({
      name: item.name || item.city || item.country || "Saved location",
      value: [Number(item.lon), Number(item.lat), Number(item.count) || 1],
      country: item.country || "",
      city: item.city || "",
      count: Number(item.count) || 1
    }));
  if (!countryEntries.length) {
    mapEl.style.display = cityClusters.length ? "" : "none";
    if (!cityClusters.length) return;
  } else {
    mapEl.style.display = "";
  }
  if (!window.echarts) {
    mapEl.innerHTML = '<div class="empty">World map library could not be loaded. Showing country list below.</div>';
    return;
  }
  try {
    if (!analyticsWorldMapJson) {
      const response = await fetch("https://cdn.jsdelivr.net/npm/echarts@4.9.0/map/json/world.json", {cache: "force-cache"});
      if (!response.ok) throw new Error("World GeoJSON could not be loaded");
      analyticsWorldMapJson = await response.json();
      echarts.registerMap("world", analyticsWorldMapJson);
    }
    const chart = analyticsWorldMapChart || echarts.init(mapEl);
    analyticsWorldMapChart = chart;
    const maxValue = Math.max(1, ...countryEntries.map(([, count]) => Number(count) || 0));
    const maxCluster = Math.max(1, ...cityClusters.map(item => item.count));
    chart.setOption({
      backgroundColor: "transparent",
      tooltip: {
        trigger: "item",
        formatter: params => {
          if (params.seriesType === "scatter") {
            return `${htmlEscape(params.name || "Saved location")}: ${htmlEscape(params.data?.count || params.value?.[2] || 1)} users`;
          }
          return `${htmlEscape(params.name || "Unknown")}: ${htmlEscape(params.value || 0)}`;
        }
      },
      visualMap: {
        min: 0,
        max: maxValue,
        left: 12,
        bottom: 12,
        text: ["High", "Low"],
        calculable: true,
        inRange: {color: ["#dbeafe", "#93c5fd", "#6366f1", "#ec4899"]},
        textStyle: {color: getComputedStyle(document.body).getPropertyValue("--muted").trim() || "#64748b"}
      },
      geo: {
        map: "world",
        roam: true,
        zoom: focusedCountry ? 1.45 : 1,
        label: {show: false},
        itemStyle: {
          areaColor: "rgba(99,102,241,.08)",
          borderColor: "rgba(99,102,241,.25)"
        },
        emphasis: {itemStyle: {areaColor: "#f59e0b"}}
      },
      series: [{
        name: "Country sessions",
        type: "map",
        map: "world",
        geoIndex: 0,
        roam: false,
        selectedMode: false,
        label: {
          show: true,
          color: "#0f172a",
          fontSize: 11,
          fontWeight: 700,
          formatter: params => Number(params.value) > 0 ? Number(params.value) : ""
        },
        emphasis: {
          label: {show: true},
          itemStyle: {areaColor: "#f59e0b"}
        },
        itemStyle: {
          areaColor: "rgba(99,102,241,.08)",
          borderColor: "rgba(99,102,241,.25)"
        },
        data: countryEntries.map(([country, count]) => ({
          name: worldMapCountryName(country),
          value: Number(count) || 0,
          originalName: country
        }))
      }, {
        name: "Saved user locations",
        type: "scatter",
        coordinateSystem: "geo",
        symbol: "circle",
        symbolSize: value => Math.max(9, Math.min(34, 8 + (Number(value?.[2] || 1) / maxCluster) * 24)),
        itemStyle: {
          color: "#ef4444",
          borderColor: "#ffffff",
          borderWidth: 2,
          shadowBlur: 10,
          shadowColor: "rgba(239,68,68,.45)"
        },
        label: {
          show: true,
          formatter: params => Number(params.data?.count || 0) > 1 ? String(params.data.count) : "",
          color: "#991b1b",
          fontSize: 11,
          fontWeight: 900,
          position: "right"
        },
        data: cityClusters
      }]
    }, true);
    if (fallback) fallback.classList.remove("compact");
    if (!analyticsWorldMapResizeBound) {
      analyticsWorldMapResizeBound = true;
      window.addEventListener("resize", () => analyticsWorldMapChart?.resize());
    }
  } catch (error) {
    console.error("World map render failed", error);
    mapEl.innerHTML = '<div class="empty">World map could not be loaded. Showing country list below.</div>';
  }
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", () => setTimeout(renderAnalyticsVisuals, 0), {once: true});
} else {
  setTimeout(renderAnalyticsVisuals, 0);
}

document.getElementById("analyticsCountryFocus")?.addEventListener("change", () => {
  const countryFocus = selectedAnalyticsCountry();
  if (countryFocus) {
    sessionStorage.setItem("analyticsCountryFocus", countryFocus);
  } else {
    sessionStorage.removeItem("analyticsCountryFocus");
  }
  renderAnalyticsWorldMap();
});

const savedAnalyticsCountryFocus = sessionStorage.getItem("analyticsCountryFocus") || "";
const analyticsCountryFocusSelect = document.getElementById("analyticsCountryFocus");
if (analyticsCountryFocusSelect && savedAnalyticsCountryFocus && Array.from(analyticsCountryFocusSelect.options).some(option => option.value === savedAnalyticsCountryFocus)) {
  analyticsCountryFocusSelect.value = savedAnalyticsCountryFocus;
}

function analyticsCsv() {
  const rows = [
    ["Section", "Metric", "Value"],
    ...Object.entries(analyticsReport.summary || {}).map(([key, value]) => ["Summary", key, value]),
    [],
    ["Daily Counts", "Date", "Conversations"],
    ...Object.entries(analyticsReport.daily_counts || {}).map(([date, count]) => ["Daily Counts", date, count]),
    [],
    ["Daily Feedback", "Date", "Feedback"],
    ...Object.entries(analyticsReport.daily_feedback_counts || {}).map(([date, count]) => ["Daily Feedback", date, count]),
    [],
    ["Daily Payment Revenue", "Date", "Revenue Paise"],
    ...Object.entries(analyticsReport.daily_payment_revenue_paise || {}).map(([date, amount]) => ["Daily Payment Revenue", date, amount]),
    [],
    ["Daily Payment Attempts", "Date", "Attempts"],
    ...Object.entries(analyticsReport.daily_payment_counts || {}).map(([date, count]) => ["Daily Payment Attempts", date, count]),
    [],
    ["Hourly Counts", "Hour", "Queries"],
    ...Object.entries(analyticsReport.hour_counts || {}).map(([hour, count]) => ["Hourly Counts", `${hour}:00`, count]),
    [],
    ["Devices", "Device", "Count"],
    ...Object.entries(analyticsReport.devices || {}).map(([device, count]) => ["Devices", device, count]),
    [],
    ["Browsers", "Browser", "Count"],
    ...Object.entries(analyticsReport.browsers || {}).map(([browser, count]) => ["Browsers", browser, count]),
    [],
    ["Countries", "Country", "Count"],
    ...Object.entries(analyticsReport.countries || {}).map(([country, count]) => ["Countries", country, count]),
    [],
    ["City Location Clusters", "Location", "Country", "Latitude", "Longitude", "Users"],
    ...(analyticsReport.city_clusters || []).map(item => ["City Location Clusters", item.name, item.country, item.lat, item.lon, item.count]),
    [],
    ["Top Questions", "Question", "Count", "Success Rate"],
    ...(analyticsReport.top_questions || []).map(item => ["Top Questions", item.question, item.count, `${item.success_rate}%`]),
    [],
    ["Unanswered Questions", "Question", "Source Page", "Date"],
    ...(analyticsReport.unanswered_questions || []).map(item => ["Unanswered Questions", item.question, item.source_page, item.date]),
    [],
    ["Unique Leads", "Lead Type", "Email", "Mobile Number", "Email OTP Count", "Mobile OTP Count", "Total Captures", "WhatsApp Clicks", "Source Pages", "Location", "First Seen", "Last Seen"],
    ...(analyticsReport.unique_leads || []).map(item => ["Unique Leads", item.lead_type, item.email, item.phone_number, item.email_otp_count, item.mobile_otp_count, item.total_records, item.whatsapp_redirect_count, item.source_pages, item.location, item.first_seen, item.last_seen]),
    [],
    ["Lead Periods", "Period", "Days", "Unique Leads"],
    ...(analyticsReport.lead_periods || []).map(item => ["Lead Periods", item.label, item.days, item.count]),
    [],
    ["Feedback Values", "Feedback", "Count"],
    ...Object.entries(analyticsReport.feedback_values || {}).map(([value, count]) => ["Feedback Values", value, count]),
    [],
    ["Feedback Actions", "Action", "Count"],
    ...Object.entries(analyticsReport.feedback_actions || {}).map(([action, count]) => ["Feedback Actions", action, count]),
    [],
    ["Recent Feedback", "Date", "Feedback", "Action", "Source Page"],
    ...(analyticsReport.recent_feedback || []).map(item => ["Recent Feedback", item.date, item.feedback, item.action, item.source_page]),
    [],
    ["Payment Statuses", "Status", "Count"],
    ...Object.entries(analyticsReport.payment_statuses || {}).map(([status, count]) => ["Payment Statuses", status, count]),
    [],
    ["Payment Methods", "Method", "Count"],
    ...Object.entries(analyticsReport.payment_methods || {}).map(([method, count]) => ["Payment Methods", method, count]),
    [],
    ["Payment Buttons", "Button", "Count"],
    ...Object.entries(analyticsReport.payment_actions || {}).map(([action, count]) => ["Payment Buttons", action, count]),
    [],
    ["Recent Payments", "Date", "Status", "Method", "Amount", "Payment Button", "Payer", "Reference", "Source Page"],
    ...(analyticsReport.recent_payments || []).map(item => ["Recent Payments", item.date, item.status, item.method, item.amount, item.payment_button, item.payer, item.reference, item.source_page]),
    [],
    ["Source Pages", "Page", "Conversations", "Leads", "Success Rate"],
    ...(analyticsReport.source_pages || []).map(item => ["Source Pages", item.page, item.conversations, item.leads, `${item.success_rate}%`])
  ];
  return rowsToCsv(rows);
}

function analyticsReportHtml(options = {}) {
  const esc = value => String(value ?? "").replace(/[&<>"']/g, char => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[char]));
  const label = value => String(value || "").replace(/_/g, " ").replace(/\b\w/g, char => char.toUpperCase());
  const number = value => Number(value || 0).toLocaleString("en-IN");
  const percent = value => `${number(value)}%`;
  const summary = analyticsReport.summary || {};
  const chartImages = options.chartImages || {};
  const filters = options.filters || currentAnalyticsFilterState();
  const comparison = analyticsReport.comparison || {};
  const current = comparison.current || {};
  const previous = comparison.previous || {};
  const generatedAt = new Date().toLocaleString();
  const daily = analyticsDateSeries();
  const hourRows = Object.entries(analyticsReport.hour_counts || {}).sort(([a], [b]) => Number(a) - Number(b)).map(([name, value]) => ({name: `${name}:00`, value: Number(value) || 0}));
  const leadQuality = analyticsReport.lead_quality || {};
  const leadQualityRows = [
    {name: "Real Leads", value: Number(leadQuality.real) || 0},
    {name: "Weak Leads", value: Number(leadQuality.weak) || 0},
    {name: "Email Contacts", value: Number(leadQuality.email) || 0},
    {name: "Mobile Contacts", value: Number(leadQuality.mobile) || 0}
  ];
  const paymentStatusRows = analyticsEntries(analyticsReport.payment_statuses, 8);
  const paymentMethodRows = analyticsEntries(analyticsReport.payment_methods, 8);
  const paymentActionRows = analyticsEntries(analyticsReport.payment_actions, 10);
  const maxDaily = Math.max(1, ...daily.conversations, ...daily.answered, ...daily.unanswered, ...daily.leads, ...daily.feedback, ...daily.payments);
  const logo = vaniBrandLogo ? `<img src="${vaniBrandLogo}" alt="Vani AI">` : `<strong>Vani AI</strong>`;
  const card = (title, value, note = "") => `<div class="kpi"><span>${esc(title)}</span><strong>${esc(value)}</strong><small>${esc(note)}</small></div>`;
  const alertCard = (title, value, note, status = "") => `<div class="alert ${status}"><strong>${esc(title)}</strong><b>${esc(value)}</b><span>${esc(note)}</span></div>`;
  const executiveNotes = [
    `${number(summary.total_conversations)} conversations were tracked for the selected ${analyticsReport.range_label} filter.`,
    `${number(summary.answered_queries_percent)}% answer rate and ${number(summary.unanswered_queries_percent)}% unanswered rate show current FAQ coverage.`,
    `${number(summary.leads_collected)} raw leads were captured, with ${number(summary.real_unique_leads)} real verified leads.`,
    `${summary.payment_revenue || "Rs0.00"} payment revenue was collected from ${number(summary.paid_payments)} paid payments.`,
    `Current period is compared against ${filters.previous_date_from} to ${filters.previous_date_to}.`,
    `Country focus for the map is ${filters.country_focus}.`
  ];
  const summaryRows = Object.entries(summary)
    .map(([key, value]) => `<tr><th>${esc(label(key))}</th><td>${esc(value)}</td></tr>`).join("");
  const comparisonRows = Object.keys(current).map(key => {
    const currentValue = Number(current[key] || 0);
    const previousValue = Number(previous[key] || 0);
    const change = previousValue > 0 ? Math.round(((currentValue - previousValue) / Math.max(1, previousValue)) * 100) : (currentValue > 0 ? 100 : 0);
    const changeClass = change > 0 ? "good" : (change < 0 ? "bad" : "");
    return `<tr><td>${esc(label(key))}</td><td>${esc(currentValue)}</td><td>${esc(previousValue)}</td><td><span class="delta ${changeClass}">${change > 0 ? "+" : ""}${esc(change)}%</span></td></tr>`;
  }).join("");
  const trendBars = daily.dates.length
    ? daily.dates.map((date, index) => {
        const conversations = daily.conversations[index] || 0;
        const answered = daily.answered[index] || 0;
        const unanswered = daily.unanswered[index] || 0;
        const leads = daily.leads[index] || 0;
        return `<div class="trend-item"><div class="trend-stack"><i title="Conversations" style="height:${Math.max(4, Math.round((conversations / maxDaily) * 120))}px"></i><em title="Answered" style="height:${answered ? Math.max(4, Math.round((answered / maxDaily) * 120)) : 0}px"></em><u title="Unanswered" style="height:${unanswered ? Math.max(4, Math.round((unanswered / maxDaily) * 120)) : 0}px"></u><b title="Leads" style="height:${leads ? Math.max(4, Math.round((leads / maxDaily) * 120)) : 0}px"></b></div><span>${esc(date.slice(5))}</span></div>`;
      }).join("")
    : `<p class="empty">No trend data available for this period.</p>`;
  const funnel = (analyticsReport.funnel || []).map((item, index, items) => {
    const first = Math.max(1, Number(items[0]?.value || 0));
    const width = Math.max(12, Math.round((Number(item.value || 0) / first) * 100));
    return `<div class="funnel-row"><span>${esc(item.label)}</span><div><i style="width:${width}%"></i></div><strong>${esc(number(item.value))}</strong></div>`;
  }).join("");
  const breakdown = (title, objectValue) => {
    const rows = analyticsEntries(objectValue, 8);
    const maxValue = Math.max(1, ...rows.map(item => item.value));
    return `<section class="panel page-break-avoid"><h2>${esc(title)}</h2>${rows.length ? rows.map(item => `<div class="bar-row"><span>${esc(item.name)}</span><div><i style="width:${Math.round((item.value / maxValue) * 100)}%"></i></div><strong>${esc(number(item.value))}</strong></div>`).join("") : `<p class="empty">No data</p>`}</section>`;
  };
  const barList = (title, rows) => {
    const filtered = rows.filter(item => Number(item.value) > 0);
    const maxValue = Math.max(1, ...filtered.map(item => Number(item.value) || 0));
    return `<section class="panel page-break-avoid"><h2>${esc(title)}</h2>${filtered.length ? filtered.map(item => `<div class="bar-row"><span>${esc(item.name)}</span><div><i style="width:${Math.round(((Number(item.value) || 0) / maxValue) * 100)}%"></i></div><strong>${esc(number(item.value))}</strong></div>`).join("") : `<p class="empty">No data</p>`}</section>`;
  };
  const chartImage = (title, key) => chartImages[key]
    ? `<section class="panel page-break-avoid"><h2>${esc(title)}</h2><img class="chart-shot" src="${chartImages[key]}" alt="${esc(title)}"></section>`
    : "";
  const table = (title, headers, rows) => `
    <section class="panel page-break-avoid"><h2>${esc(title)}</h2>
    <table><thead><tr>${headers.map(header => `<th>${esc(header)}</th>`).join("")}</tr></thead>
    <tbody>${rows.length ? rows.join("") : `<tr><td colspan="${headers.length}" class="empty-cell">No data</td></tr>`}</tbody></table></section>`;
  return `<!doctype html>
<html><head><meta charset="utf-8"><title>Vani Analytics Report</title>
<style>
@page{size:A4;margin:16mm}
*{box-sizing:border-box}body{font-family:Inter,Segoe UI,Arial,sans-serif;margin:0;color:#111827;background:#f8fafc;line-height:1.45}
.report{max-width:1120px;margin:0 auto;padding:28px}.cover{color:#fff;border-radius:24px;padding:28px;background:linear-gradient(135deg,#111827,#4338ca 58%,#0891b2);display:grid;gap:22px;box-shadow:0 18px 45px rgba(15,23,42,.18)}
.brand{display:flex;align-items:center;gap:14px}.brand img{width:54px;height:54px;object-fit:contain;border-radius:14px;background:#fff;padding:6px}.brand strong{font-size:22px}.brand span{display:block;font-size:12px;color:rgba(255,255,255,.78);font-weight:700;text-transform:uppercase;letter-spacing:.08em}
.cover h1{font-size:34px;margin:0}.cover p{margin:0;color:rgba(255,255,255,.82)}.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.meta div{padding:12px;border:1px solid rgba(255,255,255,.18);border-radius:14px;background:rgba(255,255,255,.1)}.meta span{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,.7)}.meta strong{display:block;margin-top:4px}.filter-strip{display:flex;gap:10px;flex-wrap:wrap}.filter-strip span{border:1px solid rgba(255,255,255,.2);border-radius:999px;background:rgba(255,255,255,.12);padding:8px 11px;font-size:12px;font-weight:800}
.section-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:18px 0}.kpi,.panel{background:#fff;border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 8px 24px rgba(15,23,42,.06)}.kpi{padding:16px;display:grid;gap:7px}.kpi span{font-size:11px;color:#64748b;text-transform:uppercase;font-weight:800;letter-spacing:.05em}.kpi strong{font-size:25px;color:#111827}.kpi small{color:#64748b}
.panel{padding:18px;margin:16px 0}.panel h2{font-size:18px;margin:0 0 14px}.two{display:grid;grid-template-columns:1.25fr .75fr;gap:16px}.three{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.empty{color:#64748b;padding:18px;text-align:center}.empty-cell{text-align:center;color:#64748b}.alert-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:18px 0}.alert{padding:16px;border:1px solid #e2e8f0;border-radius:18px;background:#fff;display:grid;gap:5px}.alert strong{font-size:13px}.alert b{font-size:24px}.alert span{color:#64748b;font-size:12px}.alert.good{border-color:#bbf7d0;background:#f0fdf4}.alert.warn{border-color:#fde68a;background:#fffbeb}.alert.bad{border-color:#fecaca;background:#fef2f2}
.trend{height:190px;display:flex;align-items:end;gap:8px;border-left:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;padding:12px 8px 0}.trend-item{flex:1;display:grid;gap:7px;min-width:0;text-align:center}.trend-stack{height:130px;display:flex;align-items:end;justify-content:center;gap:3px}.trend-stack i,.trend-stack b,.trend-stack em,.trend-stack u{display:block;width:8px;border-radius:8px 8px 0 0;text-decoration:none}.trend-stack i{background:#4f46e5}.trend-stack em{background:#22c55e}.trend-stack u{background:#f59e0b}.trend-stack b{background:#06b6d4}.trend-item span{font-size:10px;color:#64748b;white-space:nowrap}
.funnel-row,.bar-row{display:grid;grid-template-columns:120px 1fr 58px;gap:10px;align-items:center;margin:10px 0;font-size:12px;color:#475569}.funnel-row div,.bar-row div{height:13px;border-radius:999px;background:#e2e8f0;overflow:hidden}.funnel-row i,.bar-row i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#4f46e5,#06b6d4)}.funnel-row strong,.bar-row strong{text-align:right;color:#111827}
table{width:100%;border-collapse:separate;border-spacing:0;margin-top:8px;overflow:hidden;border:1px solid #e2e8f0;border-radius:14px}th,td{text-align:left;border-bottom:1px solid #e2e8f0;padding:9px 10px;font-size:12px;vertical-align:top;word-break:break-word}th{background:#f1f5f9;color:#475569;text-transform:uppercase;font-size:10px;letter-spacing:.05em}tr:last-child td{border-bottom:0}.delta{font-weight:800;color:#64748b}.delta.good{color:#15803d}.delta.bad{color:#b91c1c}
.chart-shot{display:block;width:100%;max-height:420px;object-fit:contain;border:1px solid #e2e8f0;border-radius:14px;background:#fff}.summary-list{display:grid;gap:10px;margin:0;padding:0;list-style:none}.summary-list li{padding:12px 14px;border:1px solid #e2e8f0;border-radius:14px;background:#f8fafc;color:#334155}.footer{display:flex;justify-content:space-between;gap:16px;color:#64748b;font-size:11px;margin-top:20px;border-top:1px solid #e2e8f0;padding-top:12px}.legend{display:flex;gap:14px;flex-wrap:wrap;color:#64748b;font-size:12px}.legend i{display:inline-block;width:10px;height:10px;border-radius:999px;margin-right:5px}.legend .c{background:#4f46e5}.legend .a{background:#22c55e}.legend .u{background:#f59e0b}.legend .l{background:#06b6d4}
@media print{body{background:#fff}.report{padding:0}.cover,.kpi,.panel{box-shadow:none}.page-break-avoid{break-inside:avoid}.two,.three,.section-grid,.meta{break-inside:avoid}}
</style>
</head><body>
<main class="report">
<section class="cover">
  <div class="brand">${logo}<div><strong>Vani AI</strong><span>Analytics report</span></div></div>
  <div><h1>Performance Dashboard Report</h1><p>BI-style snapshot generated from the dashboard data currently loaded in your browser.</p></div>
  <div class="meta"><div><span>Chatbot</span><strong>${esc(filters.bot)}</strong></div><div><span>Range</span><strong>${esc(filters.range)}</strong></div><div><span>Period</span><strong>${esc(filters.date_from)} to ${esc(filters.date_to)}</strong></div><div><span>Generated</span><strong>${esc(generatedAt)}</strong></div></div>
  <div class="filter-strip"><span>Applied filter: ${esc(filters.range)}</span><span>From ${esc(filters.date_from)}</span><span>To ${esc(filters.date_to)}</span><span>Previous period: ${esc(filters.previous_date_from)} to ${esc(filters.previous_date_to)}</span><span>Country focus: ${esc(filters.country_focus)}</span><span>Exported from: ${esc(filters.exported_section)}</span></div>
</section>
<section class="panel page-break-avoid"><h2>Executive Summary</h2><ul class="summary-list">${executiveNotes.map(note => `<li>${esc(note)}</li>`).join("")}</ul></section>
<section class="section-grid">
${card("Conversations", number(summary.total_conversations), "Tracked chat sessions and queries")}
${card("Messages", number(summary.total_messages), "User messages currently tracked")}
${card("Visitors", number(summary.unique_visitors), `${number(summary.returning_users_percent)}% returning users`)}
${card("Answer Rate", percent(summary.answered_queries_percent), `${number(summary.unanswered_queries_percent)}% unanswered`)}
${card("Leads", number(summary.leads_collected), `${number(summary.unique_leads)} unique leads`)}
${card("OTP Verified", number(summary.otp_verified_leads), `${number(summary.real_unique_leads)} real leads`)}
${card("Payment Revenue", summary.payment_revenue || "Rs0.00", `${number(summary.paid_payments)} paid payments`)}
${card("Payment Conversion", percent(summary.payment_conversion_percent), `${number(summary.payment_attempts)} attempts`)}
${card("Avg Response", summary.avg_response_time_ms ? `${number(summary.avg_response_time_ms)}ms` : "No data", "Widget API response time")}
${card("Avg Duration", summary.avg_conversation_duration || "No data", "Widget session duration")}
</section>
<section class="alert-grid">
${alertCard("Answer Health", `${number(summary.unanswered_queries_percent)}%`, "Unanswered queries in the selected filter.", Number(summary.unanswered_queries_percent) > 30 ? "bad" : (Number(summary.unanswered_queries_percent) > 10 ? "warn" : "good"))}
${alertCard("Lead Capture", `${number(current.lead_conversion || 0)}%`, "Conversation to lead conversion for this period.", Number(current.lead_conversion || 0) >= 10 ? "good" : (Number(current.lead_conversion || 0) > 0 ? "warn" : "bad"))}
${alertCard("Response Time", summary.avg_response_time_ms ? `${number(summary.avg_response_time_ms)}ms` : "No data", "Average response time tracked by widget API.", summary.avg_response_time_ms && Number(summary.avg_response_time_ms) <= 1500 ? "good" : (summary.avg_response_time_ms ? "warn" : ""))}
</section>
<section class="two">
  <div class="panel"><h2>Conversation, Answer and Lead Trend</h2><div class="legend"><span><i class="c"></i>Conversations</span><span><i class="a"></i>Answered</span><span><i class="u"></i>Unanswered</span><span><i class="l"></i>Leads</span></div><div class="trend">${trendBars}</div></div>
  <div class="panel"><h2>Conversion Funnel</h2>${funnel || `<p class="empty">No funnel data</p>`}</div>
</section>
${chartImage("Live BI Trend Chart", "analyticsTrendChart")}
${chartImage("Live Conversion Funnel", "analyticsFunnelChart")}
${chartImage("Payment Revenue Trend", "analyticsPaymentRevenueChart")}
${chartImage("Payment Status Chart", "analyticsPaymentStatusChart")}
${chartImage("Payment Method Chart", "analyticsPaymentMethodChart")}
${chartImage("Payment Button Chart", "analyticsPaymentActionChart")}
${chartImage("World Map", "analyticsWorldMap")}
<section class="three">
  ${breakdown("Device Mix", analyticsReport.devices)}
  ${breakdown("Browser Breakdown", analyticsReport.browsers)}
  ${breakdown("Country Distribution", analyticsReport.countries)}
</section>
<section class="three">
  ${barList("Hourly Usage", hourRows)}
  ${barList("Lead Quality", leadQualityRows)}
  ${breakdown("City Distribution", analyticsReport.cities)}
</section>
<section class="panel page-break-avoid"><h2>Previous Period Comparison</h2><table><thead><tr><th>Metric</th><th>Current</th><th>Previous</th><th>Change</th></tr></thead><tbody>${comparisonRows || `<tr><td colspan="4" class="empty-cell">No comparison data</td></tr>`}</tbody></table></section>
<section class="panel page-break-avoid"><h2>Complete Summary</h2><table><tbody>${summaryRows}</tbody></table></section>
${table("Top Questions", ["Question", "Count", "Success Rate"], (analyticsReport.top_questions || []).map(item => `<tr><td>${esc(item.question)}</td><td>${esc(item.count)}</td><td>${esc(item.success_rate)}%</td></tr>`))}
${table("Unanswered Questions", ["Question", "Source Page", "Date"], (analyticsReport.unanswered_questions || []).map(item => `<tr><td>${esc(item.question)}</td><td>${esc(item.source_page)}</td><td>${esc(item.date)}</td></tr>`))}
${table("City Location Clusters", ["Location", "Country", "Latitude", "Longitude", "Users"], (analyticsReport.city_clusters || []).map(item => `<tr><td>${esc(item.name)}</td><td>${esc(item.country || "-")}</td><td>${esc(item.lat)}</td><td>${esc(item.lon)}</td><td>${esc(item.count)}</td></tr>`))}
${table("Unique Leads", ["Type", "Email", "Mobile", "Email OTP", "Mobile OTP", "Captures", "WhatsApp", "Source Pages", "Location", "First Seen", "Last Seen"], (analyticsReport.unique_leads || []).map(item => `<tr><td>${esc(item.lead_type)}</td><td>${esc(item.email)}</td><td>${esc(item.phone_number)}</td><td>${esc(item.email_otp_count)}</td><td>${esc(item.mobile_otp_count)}</td><td>${esc(item.total_records)}</td><td>${esc(item.whatsapp_redirect_count)}</td><td>${esc(item.source_pages)}</td><td>${esc(item.location)}</td><td>${esc(item.first_seen)}</td><td>${esc(item.last_seen)}</td></tr>`))}
${table("Recent Feedback", ["Date", "Feedback", "Action", "Source Page"], (analyticsReport.recent_feedback || []).map(item => `<tr><td>${esc(item.date)}</td><td>${esc(item.feedback)}</td><td>${esc(item.action)}</td><td>${esc(item.source_page)}</td></tr>`))}
<section class="three">
  ${barList("Payment Statuses", paymentStatusRows)}
  ${barList("Payment Methods", paymentMethodRows)}
  ${barList("Payment Buttons", paymentActionRows)}
</section>
${table("Recent Payments", ["Date", "Status", "Method", "Amount", "Payment Button", "Payer", "Reference", "Source Page"], (analyticsReport.recent_payments || []).map(item => `<tr><td>${esc(item.date)}</td><td>${esc(item.status)}</td><td>${esc(item.method)}</td><td>${esc(item.amount)}</td><td>${esc(item.payment_button)}</td><td>${esc(item.payer || "-")}</td><td>${esc(item.reference || "-")}</td><td>${esc(item.source_page || "-")}</td></tr>`))}
${table("Source Pages", ["Page", "Conversations", "Leads", "Success Rate"], (analyticsReport.source_pages || []).map(item => `<tr><td>${esc(item.page)}</td><td>${esc(item.conversations)}</td><td>${esc(item.leads)}</td><td>${esc(item.success_rate)}%</td></tr>`))}
<div class="footer"><span>Vani AI Analytics | Branded customer dashboard report</span><span>${esc(reportFileBase())}</span></div>
</main>
</body></html>`;
}

document.getElementById("exportAnalyticsCsvBtn")?.addEventListener("click", () => {
  downloadBlob(`${reportFileBase()}.csv`, analyticsCsv(), "text/csv;charset=utf-8");
  showToast("CSV report downloaded");
});

document.getElementById("downloadAnalyticsReportBtn")?.addEventListener("click", () => {
  downloadBlob(`${reportFileBase()}.html`, analyticsReportHtml({filters: currentAnalyticsFilterState()}), "text/html;charset=utf-8");
  showToast("Branded report downloaded");
});

document.getElementById("downloadWeeklyReportBtn")?.addEventListener("click", () => {
  downloadBlob(`${reportFileBase()}-weekly.html`, analyticsReportHtml({filters: currentAnalyticsFilterState()}), "text/html;charset=utf-8");
  showToast("Weekly report downloaded");
});

document.getElementById("downloadMonthlyReportBtn")?.addEventListener("click", () => {
  downloadBlob(`${reportFileBase()}-monthly.html`, analyticsReportHtml({filters: currentAnalyticsFilterState()}), "text/html;charset=utf-8");
  showToast("Monthly report downloaded");
});

function waitForAnalyticsPaint(delay = 450) {
  return new Promise(resolve => setTimeout(resolve, delay));
}

function refreshDashboardAfterPdfExport() {
  const countryFocus = selectedAnalyticsCountry();
  if (countryFocus) sessionStorage.setItem("analyticsCountryFocus", countryFocus);
  showToast("Refreshing dashboard...");
  setTimeout(() => window.location.reload(), 900);
}

async function captureAnalyticsChartImages() {
  const activeButton = document.querySelector(".analytics-tab-btn.active");
  const activeTab = activeButton?.dataset.analyticsTab || "analytics-overview";
  const images = {};
  const captureChart = (key, chart) => {
    try {
      chart?.resize();
      const image = chart?.getDataURL?.({type: "png", pixelRatio: 2, backgroundColor: "#ffffff"});
      if (image) images[key] = image;
    } catch (error) {
      console.warn("Analytics chart capture skipped", key, error);
    }
  };

  openAnalyticsTab("analytics-overview", false);
  await waitForAnalyticsPaint();
  renderAnalyticsBICharts();
  await waitForAnalyticsPaint(250);
  ["analyticsTrendChart", "analyticsFunnelChart", "analyticsDeviceChart", "analyticsQuestionChart", "analyticsPageChart"].forEach(id => {
    captureChart(id, analyticsCharts.get(id));
  });

  openAnalyticsTab("analytics-conversations", false);
  await waitForAnalyticsPaint();
  renderAnalyticsBICharts();
  await renderAnalyticsWorldMap();
  await waitForAnalyticsPaint(350);
  ["analyticsHourChart", "analyticsBrowserChart"].forEach(id => {
    captureChart(id, analyticsCharts.get(id));
  });
  captureChart("analyticsWorldMap", analyticsWorldMapChart);

  openAnalyticsTab("analytics-leads", false);
  await waitForAnalyticsPaint();
  renderAnalyticsBICharts();
  await waitForAnalyticsPaint(250);
  ["analyticsLeadTrendChart", "analyticsLeadQualityChart"].forEach(id => {
    captureChart(id, analyticsCharts.get(id));
  });

  openAnalyticsTab("analytics-feedback", false);
  await waitForAnalyticsPaint();
  renderAnalyticsBICharts();
  await waitForAnalyticsPaint(250);
  ["analyticsFeedbackTrendChart", "analyticsFeedbackValueChart", "analyticsFeedbackActionChart"].forEach(id => {
    captureChart(id, analyticsCharts.get(id));
  });

  openAnalyticsTab("analytics-payments", false);
  await waitForAnalyticsPaint();
  renderAnalyticsBICharts();
  await waitForAnalyticsPaint(250);
  ["analyticsPaymentRevenueChart", "analyticsPaymentStatusChart", "analyticsPaymentMethodChart", "analyticsPaymentActionChart"].forEach(id => {
    captureChart(id, analyticsCharts.get(id));
  });

  openAnalyticsTab(activeTab, false);
  await waitForAnalyticsPaint(100);
  renderAnalyticsVisuals();
  return images;
}

async function analyticsReportHtmlWithCharts() {
  const filters = currentAnalyticsFilterState();
  const chartImages = await captureAnalyticsChartImages();
  return analyticsReportHtml({chartImages, filters});
}

async function downloadAnalyticsPdfReport(button = null) {
  if (!window.html2pdf) {
    showToast("PDF engine could not be loaded. Opening print view.");
    await printAnalyticsPdfReport();
    return;
  }
  const originalText = button?.textContent || "";
  if (button) {
    button.disabled = true;
    button.textContent = "Preparing PDF...";
  }
  try {
    const html = await analyticsReportHtmlWithCharts();
    const container = document.createElement("div");
    container.innerHTML = html;
    const report = container.querySelector(".report") || container;
    document.body.appendChild(container);
    container.style.position = "fixed";
    container.style.left = "-10000px";
    container.style.top = "0";
    container.style.width = "1120px";
    await html2pdf()
      .set({
        margin: [10, 10, 10, 10],
        filename: `${reportFileBase()}.pdf`,
        image: {type: "jpeg", quality: 0.96},
        html2canvas: {scale: 2, useCORS: true, backgroundColor: "#ffffff"},
        jsPDF: {unit: "mm", format: "a4", orientation: "portrait"},
        pagebreak: {mode: ["css", "legacy"]}
      })
      .from(report)
      .save();
    container.remove();
    showToast("PDF report downloaded");
    refreshDashboardAfterPdfExport();
  } catch (error) {
    console.error("PDF download failed", error);
    showToast("PDF download failed. Opening print view.");
    await printAnalyticsPdfReport();
  } finally {
    if (button) {
      button.disabled = false;
      button.textContent = originalText;
    }
  }
}

async function printAnalyticsPdfReport() {
  const reportWindow = window.open("", "_blank");
  if (!reportWindow) return showToast("Allow popups to print the report");
  reportWindow.document.write(await analyticsReportHtmlWithCharts());
  reportWindow.document.close();
  reportWindow.focus();
  setTimeout(() => reportWindow.print(), 350);
  refreshDashboardAfterPdfExport();
}

document.querySelectorAll(".analytics-pdf-report-btn").forEach(button => {
  button.addEventListener("click", () => {
    if (button.dataset.premiumLock) {
      alert(button.dataset.premiumLock);
      openTab("subscription");
      return;
    }
    downloadAnalyticsPdfReport(button);
  });
});

document.getElementById("printAnalyticsReportBtn")?.addEventListener("click", () => {
  printAnalyticsPdfReport();
});

async function startPlanCheckout(planId, button) {
  if (!planId) {
    showToast("Select a wallet plan first");
    return;
  }
  if (!selectedCustomerId) {
    showToast("Select or create a bot before recharging");
    return;
  }
  if (!window.Razorpay) {
    showToast("Razorpay checkout could not be loaded");
    return;
  }
  const paymentMode = document.querySelector('input[name="subscriptionPaymentMode"]:checked')?.value || "one_time";
  const nameInput = document.getElementById("subscriptionAutoPayNameInput");
  const contactInput = document.getElementById("subscriptionAutoPayContactInput");
  const helpText = document.getElementById("subscriptionRequiredFieldsHelp");
  const customerName = nameInput?.value.trim() || "";
  const customerContact = contactInput?.value.trim() || "";
  nameInput?.classList.toggle("input-error", customerName.length < 3);
  contactInput?.classList.toggle("input-error", !/^\+?[1-9]\d{7,14}$/.test(customerContact));
  helpText?.classList.toggle("error", customerName.length < 3 || !/^\+?[1-9]\d{7,14}$/.test(customerContact));
  if (customerName.length < 3) {
    showToast("Customer name is required for wallet recharge");
    nameInput?.focus();
    return;
  }
  if (!/^\+?[1-9]\d{7,14}$/.test(customerContact)) {
    showToast("Customer mobile number with country code is required");
    contactInput?.focus();
    return;
  }
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = paymentMode === "auto" ? "Creating auto payment..." : "Creating order...";

  const createAction = paymentMode === "auto" ? "create_razorpay_subscription_checkout" : "create_razorpay_order";
  const orderResponse = await fetch(`/api.php?action=${createAction}`, {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({
      plan_id: planId,
      customer_id: selectedCustomerId,
      name: customerName,
      contact: customerContact
    })
  });
  const orderData = await orderResponse.json().catch(() => ({}));
  button.disabled = false;
  button.textContent = originalText;

  if (!orderData.success) {
    showToast(orderData.message || "Payment could not be started");
    return;
  }

  const checkoutOptions = {
    key: orderData.key_id,
    name: "Vani AI",
    description: `${orderData.plan.name} ${paymentMode === "auto" ? "wallet recharge with automatic payment" : "wallet recharge"}`,
    remember_customer: true,
    prefill: {
      name: customerName,
      email: billingEmail,
      contact: orderData.contact || customerContact
    },
    readonly: {email: true},
    theme: {color: "#6366f1"},
    handler: async response => {
      showToast(paymentMode === "auto" ? "Verifying automatic payment..." : "Verifying payment...");
      const verifyAction = paymentMode === "auto" ? "verify_razorpay_subscription_payment" : "verify_razorpay_payment";
      const verifyResponse = await fetch(`/api.php?action=${verifyAction}`, {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify(response)
      });
      const verifyData = await verifyResponse.json().catch(() => ({}));
      if (!verifyData.success) {
        showToast(verifyData.message || "Payment verification failed");
        return;
      }
      showToast(paymentMode === "auto" ? "Plan activated with automatic payment" : "Plan activated");
      setTimeout(() => location.reload(), 900);
    }
  };
  if (paymentMode === "auto") {
    checkoutOptions.subscription_id = orderData.subscription_id;
  } else {
    checkoutOptions.amount = orderData.order.amount;
    checkoutOptions.currency = orderData.order.currency || "INR";
    checkoutOptions.order_id = orderData.order.id;
  }
  const checkout = new Razorpay(checkoutOptions);
  checkout.on("payment.failed", async response => {
    await recordRazorpayFailure({
      order_id: orderData.order?.id || "",
      subscription_id: orderData.subscription_id || ""
    }, response, paymentMode === "auto" ? "wallet_recharge_auto_payment" : "wallet_recharge_one_time");
    showToast(razorpayFailureMessage(response, "Payment authorization failed"));
  });
  checkout.open();
}

const subscriptionPlanLabels = {
  starter: {name: "Starter Plan", price: "₹199 minimum recharge"},
  growth: {name: "Growth Plan", price: "₹499 minimum recharge"},
  business: {name: "Business Plan", price: "₹999 minimum recharge"}
};
let selectedSubscriptionPlanId = "";

function selectSubscriptionPlan(planId) {
  selectedSubscriptionPlanId = planId;
  document.querySelectorAll(".pricing-card").forEach(card => {
    const button = card.querySelector(".billing-plan-btn");
    const isSelected = button?.dataset.planId === planId;
    card.classList.toggle("plan-selected", isSelected);
    if (button) button.textContent = isSelected ? "Selected" : "Recharge Wallet";
  });
  const panel = document.getElementById("subscriptionCheckoutPanel");
  const plan = subscriptionPlanLabels[planId] || {name: "Selected plan", price: ""};
  document.getElementById("selectedSubscriptionPlanName").textContent = plan.name;
  document.getElementById("selectedSubscriptionPlanPrice").textContent = plan.price;
  panel?.classList.add("active");
  panel?.scrollIntoView({behavior: "smooth", block: "nearest"});
}

document.querySelectorAll(".billing-plan-btn").forEach(button => {
  button.addEventListener("click", () => selectSubscriptionPlan(button.dataset.planId));
});

document.getElementById("continueSubscriptionPaymentBtn")?.addEventListener("click", event => {
  startPlanCheckout(selectedSubscriptionPlanId, event.currentTarget);
});

["subscriptionAutoPayNameInput", "subscriptionAutoPayContactInput"].forEach((id) => {
  document.getElementById(id)?.addEventListener("input", (event) => {
    event.currentTarget.classList.remove("input-error");
    document.getElementById("subscriptionRequiredFieldsHelp")?.classList.remove("error");
  });
});

document.getElementById("cancelSubscriptionBtn")?.addEventListener("click", async event => {
  const button = event.currentTarget;
  if (button.disabled) return;
  const confirmed = confirm("Stop automatic payment now? Your remaining wallet balance will continue working on the current plan until it reaches zero, then the account will move to Free service.");
  if (!confirmed) return;
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = "Stopping service...";
  try {
    const response = await fetch("/api.php?action=cancel_chatbot_subscription", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({customer_id: selectedCustomerId || ""})
    });
    const data = await response.json().catch(() => ({}));
    if (!data.success) {
      button.disabled = false;
      button.textContent = originalText;
      showToast(data.message || "Subscription could not be cancelled");
      return;
    }
    document.getElementById("subscriptionServiceStatusTag").textContent = "Wallet Active";
    document.getElementById("subscriptionServiceStatusTag").classList.add("good");
    document.getElementById("subscriptionServiceStatusTag").classList.remove("bad");
    button.textContent = "Auto Payment Stopped";
    showToast("Auto payment stopped");
    setTimeout(() => location.reload(), 900);
  } catch (error) {
    button.disabled = false;
    button.textContent = originalText;
    showToast("Subscription could not be cancelled");
  }
});

themeToggle?.addEventListener("click", () => {
  const dark = !document.body.classList.contains("dark");
  document.body.classList.toggle("dark", dark);
  if (themeToggle) themeToggle.textContent = dark ? "Bright" : "Dark";
  const themeValue = dark ? "dark" : "bright";
  localStorage.setItem("vani-index-theme", themeValue);
  localStorage.setItem("vani_dashboard_theme", themeValue);
  localStorage.setItem("vani_setup_theme", themeValue);
});

const dashboardTheme = localStorage.getItem("vani-index-theme") || localStorage.getItem("vani_dashboard_theme") || localStorage.getItem("vani_setup_theme") || "dark";
if (dashboardTheme !== "bright") {
  document.body.classList.add("dark");
  if (themeToggle) themeToggle.textContent = "Bright";
} else if (themeToggle) {
  themeToggle.textContent = "Dark";
}

function formatLastActivityForBrowser() {
  const lastActivityText = document.getElementById("lastActivityText");
  const lastActivityZone = document.getElementById("lastActivityZone");
  const raw = lastActivityText?.dataset.lastActivity || "";
  if (!raw) return;

  const normalized = /z$|[+-]\d{2}:?\d{2}$/i.test(raw) ? raw : raw + "Z";
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) return;

  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || "your browser timezone";
  lastActivityText.textContent = new Intl.DateTimeFormat(undefined, {
    year: "numeric",
    month: "short",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    timeZoneName: "short"
  }).format(date);
  if (lastActivityZone) lastActivityZone.textContent = `Latest tracked conversation in ${timezone}.`;
}

formatLastActivityForBrowser();

function formatBillingDatesForBrowser() {
  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || "";
  const formatter = new Intl.DateTimeFormat(undefined, {
    year: "numeric",
    month: "short",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    timeZoneName: "short"
  });
  document.querySelectorAll(".billing-date[data-billing-date]").forEach((dateNode) => {
    const raw = dateNode.dataset.billingDate || "";
    if (!raw) return;
    const normalized = /z$|[+-]\d{2}:?\d{2}$/i.test(raw) ? raw : raw + "Z";
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return;
    dateNode.textContent = formatter.format(date);
    dateNode.title = timezone ? `Shown in ${timezone}` : "Shown in your browser timezone";
    const zoneNode = dateNode.parentElement?.querySelector(".billing-date-zone");
    if (zoneNode) {
      zoneNode.textContent = timezone ? `Shown in ${timezone}` : "Shown in your browser timezone";
    }
  });
}

formatBillingDatesForBrowser();

const leadGenerationEnabled = document.getElementById("leadGenerationEnabled");
const leadServiceOptions = document.getElementById("leadServiceOptions");
const leadCollectLocationToggle = document.getElementById("leadCollectLocationToggle");
const leadCollectEmailToggle = document.getElementById("leadCollectEmailToggle");
const leadCollectMobileToggle = document.getElementById("leadCollectMobileToggle");
const leadEmailOtpToggle = document.getElementById("leadEmailOtpToggle");
const whatsappLeadToggle = document.getElementById("whatsappLeadToggle");
const whatsappLeadNumber = document.getElementById("whatsappLeadNumber");
const whatsappLeadHelp = document.getElementById("whatsappLeadHelp");
const leadEmailNotifyToggle = document.getElementById("leadEmailNotifyToggle");
const leadNotificationEmail = document.getElementById("leadNotificationEmail");
const leadNotificationEmailHelp = document.getElementById("leadNotificationEmailHelp");
const leadMobileOtpToggle = document.getElementById("leadMobileOtpToggle");

function validateWhatsappLeadNumber(showMessage = false) {
  if (!whatsappLeadNumber || !whatsappLeadHelp) return true;
  const value = whatsappLeadNumber.value.trim();
  const required = !!whatsappLeadToggle?.checked;
  const valid = (!required && !value) || /^\+?[1-9]\d{7,14}$/.test(value);
  whatsappLeadHelp.classList.toggle("error", !valid);
  whatsappLeadNumber.setAttribute("aria-invalid", String(!valid));
  whatsappLeadHelp.textContent = valid
    ? "Use country code and digits only, for example +919876543210."
    : "Enter a valid mobile number with country code and 8 to 15 digits.";
  if (!valid && showMessage) showToast("Enter a valid WhatsApp mobile number");
  return valid;
}

function validateLeadNotificationEmail(showMessage = false) {
  if (!leadNotificationEmail || !leadNotificationEmailHelp) return true;
  const value = leadNotificationEmail.value.trim();
  const required = !!leadEmailNotifyToggle?.checked;
  const valid = (!required && !value) || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
  leadNotificationEmailHelp.classList.toggle("error", !valid);
  leadNotificationEmail.setAttribute("aria-invalid", String(!valid));
  leadNotificationEmailHelp.textContent = valid
    ? "Lead notifications can be sent to this email address."
    : "Enter a valid email address for lead notifications.";
  if (!valid && showMessage) showToast("Enter a valid notification email");
  return valid;
}

function updateLeadGenerationUI() {
  const enabled = !!leadGenerationEnabled?.checked;
  leadServiceOptions?.classList.toggle("lead-disabled", !enabled);
  leadServiceOptions?.querySelectorAll("input, button").forEach(control => {
    control.disabled = !enabled;
  });
  syncOtpCollectionLocks();
}

function syncOtpCollectionLocks() {
  const enabled = !!leadGenerationEnabled?.checked;
  if (leadEmailOtpToggle?.checked && leadCollectEmailToggle) {
    leadCollectEmailToggle.checked = false;
  }
  if (leadMobileOtpToggle?.checked && leadCollectMobileToggle) {
    leadCollectMobileToggle.checked = false;
  }
  if (leadCollectEmailToggle) {
    leadCollectEmailToggle.disabled = !enabled || !!leadEmailOtpToggle?.checked;
    leadCollectEmailToggle.title = leadEmailOtpToggle?.checked ? "Turn off Email OTP before collecting email without OTP." : "";
  }
  if (leadCollectMobileToggle) {
    leadCollectMobileToggle.disabled = !enabled || !!leadMobileOtpToggle?.checked;
    leadCollectMobileToggle.title = leadMobileOtpToggle?.checked ? "Turn off Mobile OTP before collecting mobile without OTP." : "";
  }
  if (whatsappLeadToggle && whatsappRedirectLockedOn) {
    whatsappLeadToggle.checked = true;
    whatsappLeadToggle.disabled = true;
    whatsappLeadToggle.title = "WhatsApp redirection is locked ON for today after 3 changes.";
  }
}

leadGenerationEnabled?.addEventListener("change", () => {
  updateLeadGenerationUI();
  showToast(leadGenerationEnabled.checked ? "Lead generation enabled" : "Lead generation disabled");
});

function requireLeadPaidFeature(feature, control, message) {
  if (!control?.checked || leadPaidFeatures[feature]) return true;
  control.checked = false;
  showToast(message);
  openTab("subscription");
  return false;
}

function walletChargeText(key) {
  const paise = Number(leadWalletCharges[key] || 0);
  if (!paise) return "included";
  return `₹${Number((paise / 100).toFixed(2)).toString()}`;
}

function paidServiceAlert(message) {
  alert(message);
}

function formatDuration(seconds) {
  const total = Math.max(0, Number(seconds) || 0);
  const hours = Math.floor(total / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  const secs = total % 60;
  return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

function startWhatsappLockTimer() {
  const timer = document.getElementById("whatsappLockTimer");
  if (!timer) return;
  let remaining = Number(timer.dataset.remainingSeconds || 0);
  if (remaining <= 0) {
    timer.textContent = "";
    return;
  }
  const render = () => {
    timer.textContent = remaining > 0 ? formatDuration(remaining) : "00:00:00";
    if (remaining <= 0) {
      clearInterval(interval);
      window.location.reload();
    }
    remaining -= 1;
  };
  const interval = setInterval(render, 1000);
  render();
}

leadEmailOtpToggle?.addEventListener("change", () => {
  if (!requireLeadPaidFeature("email_otp", leadEmailOtpToggle, "Email OTP requires an active wallet plan")) return;
  if (leadCollectEmailToggle) leadCollectEmailToggle.checked = false;
  syncOtpCollectionLocks();
  if (leadEmailOtpToggle.checked) {
    paidServiceAlert(`Email OTP service is ON. Wallet deductions will apply after successful verification: fresh email lead ${walletChargeText("fresh_email_lead")}, repeat email lead ${walletChargeText("repeat_email_lead")}, email lead after 30 days ${walletChargeText("reactivated_email_lead")}.`);
  }
  if (!leadEmailOtpToggle.checked) showToast("Email will be saved without OTP");
});

leadMobileOtpToggle?.addEventListener("change", () => {
  if (!requireLeadPaidFeature("mobile_otp", leadMobileOtpToggle, "Mobile OTP requires an active paid plan")) return;
  if (leadCollectMobileToggle) leadCollectMobileToggle.checked = false;
  syncOtpCollectionLocks();
  if (leadMobileOtpToggle.checked) {
    paidServiceAlert(`Mobile OTP service is ON. Wallet deductions will apply after successful verification: fresh mobile lead ${walletChargeText("fresh_mobile_lead")}, repeat mobile OTP ${walletChargeText("repeat_mobile_lead")}, mobile lead after 30 days ${walletChargeText("reactivated_mobile_lead")}.`);
  }
  if (!leadMobileOtpToggle.checked) showToast("Mobile number will be saved without OTP");
});

whatsappLeadNumber?.addEventListener("input", () => {
  whatsappLeadNumber.value = whatsappLeadNumber.value.replace(/[^\d+]/g, "").replace(/(?!^)\+/g, "");
  validateWhatsappLeadNumber(false);
});

whatsappLeadNumber?.addEventListener("blur", () => validateWhatsappLeadNumber(false));

whatsappLeadToggle?.addEventListener("change", () => {
  if (!requireLeadPaidFeature("whatsapp_redirect", whatsappLeadToggle, "WhatsApp Redirect requires an active paid plan")) return;
  if (whatsappLeadToggle.checked) {
    if (walletBalancePaise < whatsappRedirectChargePaise) {
      whatsappLeadToggle.checked = false;
      showToast("Wallet balance must be at least ₹99 to turn ON WhatsApp Redirect");
      openTab("billing");
      return;
    }
    const charge = walletChargeText("whatsapp_redirect_addon");
    paidServiceAlert(charge === "included" ? "WhatsApp redirection service is ON. This service is included in your current plan and will be saved automatically." : `WhatsApp redirection service is ON. ${charge} will be deducted from your wallet after this change is saved. This add-on is valid for 30 days and renews every 30 days only if wallet balance is at least ₹99. If you turn it off within 1 hour, the amount will be refunded to your wallet.`);
  }
  validateWhatsappLeadNumber(false);
});

leadNotificationEmail?.addEventListener("input", () => validateLeadNotificationEmail(false));

leadNotificationEmail?.addEventListener("blur", () => validateLeadNotificationEmail(false));

leadEmailNotifyToggle?.addEventListener("change", () => validateLeadNotificationEmail(false));

let leadSetupSaveTimer = null;
let leadSetupSaving = false;
let leadSetupSaveQueued = false;

function leadSetupPayload() {
  return {
    customer_id: document.getElementById("settingsCustomerId")?.value || "",
    is_enabled: !!leadGenerationEnabled?.checked,
    collect_location: !!leadCollectLocationToggle?.checked,
    collect_email: !!leadCollectEmailToggle?.checked,
    collect_mobile: !!leadCollectMobileToggle?.checked,
    verify_email_otp: !!leadEmailOtpToggle?.checked,
    notify_lead_by_email: !!leadEmailNotifyToggle?.checked,
    notification_email: leadNotificationEmail?.value.trim() || "",
    redirect_whatsapp: !!whatsappLeadToggle?.checked,
    whatsapp_mobile_number: whatsappLeadNumber?.value.trim() || "",
    verify_mobile_otp: !!leadMobileOtpToggle?.checked
  };
}

async function saveLeadSetup({button = null, live = false} = {}) {
  if (leadEmailOtpToggle?.checked && !requireLeadPaidFeature("email_otp", leadEmailOtpToggle, "Email OTP requires an active wallet plan")) return;
  if (leadMobileOtpToggle?.checked && !requireLeadPaidFeature("mobile_otp", leadMobileOtpToggle, "Mobile OTP requires an active paid plan")) return;
  if (whatsappLeadToggle?.checked && !requireLeadPaidFeature("whatsapp_redirect", whatsappLeadToggle, "WhatsApp Redirect requires an active paid plan")) return;
  if (whatsappLeadToggle?.checked && walletBalancePaise < whatsappRedirectChargePaise) {
    whatsappLeadToggle.checked = false;
    showToast("Wallet balance must be at least ₹99 to turn ON WhatsApp Redirect");
    openTab("billing");
    return;
  }
  if (leadGenerationEnabled?.checked && whatsappLeadToggle?.checked && !validateWhatsappLeadNumber(true)) {
    whatsappLeadNumber?.focus();
    return;
  }
  if (leadGenerationEnabled?.checked && leadEmailNotifyToggle?.checked && !validateLeadNotificationEmail(true)) {
    leadNotificationEmail?.focus();
    return;
  }

  if (leadSetupSaving) {
    leadSetupSaveQueued = true;
    return;
  }
  leadSetupSaving = true;
  const originalText = button?.textContent || "";
  if (button) {
    button.disabled = true;
    button.textContent = "Saving...";
  } else if (live) {
    showToast("Saving lead setup...");
  }

  let data = {};
  try {
    const response = await fetch("/api.php?action=save_lead_generation_settings", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify(leadSetupPayload())
    });
    data = await response.json().catch(() => ({}));
  } catch (error) {
    data = {success: false, message: "Lead generation settings could not be saved"};
  } finally {
    leadSetupSaving = false;
    if (button) {
      button.disabled = false;
      button.textContent = originalText || "Save WhatsApp number";
    }
    if (leadSetupSaveQueued) {
      leadSetupSaveQueued = false;
      scheduleLeadSetupSave();
    }
  }

  if (!data.success) {
    if (data.whatsapp_redirect_locked && whatsappLeadToggle) {
      whatsappLeadToggle.checked = true;
      whatsappLeadToggle.disabled = true;
      setTimeout(() => window.location.reload(), 900);
    }
    showToast(data.message || "Lead generation settings could not be saved");
    return;
  }

  if (data.wallet_activity || data.whatsapp_redirect_locked) {
    if (data.wallet_activity) openTab("billing");
    showToast(data.wallet_activity ? "Wallet transaction saved. Refreshing Billing tab..." : "WhatsApp redirection locked for 24 hours. Refreshing...");
    setTimeout(() => window.location.reload(), 900);
    return;
  }

  showToast(live ? "Lead setup saved automatically" : "Lead generation settings saved");
}

function scheduleLeadSetupSave() {
  clearTimeout(leadSetupSaveTimer);
  leadSetupSaveTimer = setTimeout(() => saveLeadSetup({live: true}), 250);
}

document.getElementById("saveLeadSetupBtn")?.addEventListener("click", event => {
  saveLeadSetup({button: event.currentTarget});
});

document.querySelectorAll(".lead-toggle").forEach(toggle => {
  toggle.addEventListener("change", scheduleLeadSetupSave);
});

updateLeadGenerationUI();
startWhatsappLockTimer();

document.querySelectorAll(".swatch").forEach(swatch => {
  swatch.addEventListener("click", () => {
    const colorInput = document.getElementById("themeColorInput");
    colorInput.value = rgbToHex(getComputedStyle(swatch).backgroundColor);
    if (setupAutosaveReady) scheduleSetupAutosave();
  });
});

let setupAutosaveTimer = null;
let setupAutosaveReady = false;
let setupAutosaveSaving = false;
let setupAutosaveQueued = false;
let setupAutosaveToastState = "";

const themePresets = [
  "#6366f1","#06b6d4","#10b981","#ec4899","#f59e0b","#ef4444","#111827","#7c3aed",
  "linear-gradient(135deg,#6366f1,#06b6d4)","linear-gradient(135deg,#10b981,#0ea5e9)","linear-gradient(135deg,#f97316,#ec4899)","linear-gradient(135deg,#111827,#6366f1)",
  "linear-gradient(90deg,#4f46e5,#7c3aed,#ec4899)","linear-gradient(135deg,#0f172a,#0891b2,#22c55e)","linear-gradient(180deg,#f59e0b,#ef4444,#7c2d12)","radial-gradient(circle,#06b6d4,#4f46e5)",
  "linear-gradient(135deg,#0f172a,#334155,#64748b,#06b6d4)","linear-gradient(135deg,#dc2626,#f97316,#facc15,#22c55e,#06b6d4,#2563eb,#7c3aed,#db2777)"
];
const patternStyles = {
  none: "none",
  dots: "radial-gradient(rgba(99,102,241,.22) 1px, transparent 1px)",
  grid: "linear-gradient(rgba(99,102,241,.12) 1px, transparent 1px), linear-gradient(90deg, rgba(99,102,241,.12) 1px, transparent 1px)",
  diagonal: "repeating-linear-gradient(45deg, rgba(99,102,241,.12) 0 2px, transparent 2px 10px)",
  waves: "radial-gradient(ellipse at top, rgba(6,182,212,.18), transparent 45%), radial-gradient(ellipse at bottom, rgba(99,102,241,.18), transparent 48%)"
};
for (let i = 1; i <= 45; i++) {
  const angle = (i * 17) % 180;
  const hue = (i * 37) % 360;
  patternStyles[`pattern-${i}`] = `repeating-linear-gradient(${angle}deg, hsla(${hue},70%,55%,.12) 0 2px, transparent 2px ${8 + (i % 9)}px), radial-gradient(circle at ${20 + (i % 60)}% ${20 + ((i * 3) % 60)}%, hsla(${(hue + 80) % 360},70%,55%,.14), transparent 28%)`;
}

function setThemeValue(value) {
  const input = document.getElementById("themeColorInput");
  const preview = document.getElementById("themePreviewBox");
  if (input) input.value = value;
  if (preview) preview.style.background = value;
  document.querySelectorAll(".theme-color-chip").forEach(chip => chip.classList.toggle("active", chip.dataset.theme === value));
  if (setupAutosaveReady) scheduleSetupAutosave();
}

function buildGradientTheme() {
  const type = document.getElementById("themeGradientType")?.value || "linear";
  const direction = document.getElementById("themeGradientDirection")?.value || "135deg";
  const colors = Array.from(document.querySelectorAll(".themeGradientColor")).map(input => input.value).filter(Boolean).slice(0, 8);
  return type === "radial" ? `radial-gradient(${direction === "circle" ? "circle" : "ellipse"},${colors.join(",")})` : `linear-gradient(${direction},${colors.join(",")})`;
}

function applyPatternPreview(pattern) {
  const preview = document.getElementById("themePreviewBox");
  if (!preview) return;
  const theme = document.getElementById("themeColorInput")?.value || "#6366f1";
  const patternCss = patternStyles[pattern] || "none";
  preview.style.backgroundImage = patternCss === "none" ? theme : `${patternCss}, ${theme}`;
  preview.style.backgroundSize = pattern === "grid" || pattern === "dots" ? "18px 18px, 18px 18px, cover" : "cover";
}

function initThemeDesigner() {
  const grid = document.getElementById("themeColorGrid");
  const patternGrid = document.getElementById("themePatternGrid");
  if (!grid || !patternGrid) return;
  themePresets.forEach(theme => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "theme-color-chip";
    button.dataset.theme = theme;
    button.style.background = theme;
    button.addEventListener("click", () => {
      setThemeValue(theme);
      applyPatternPreview(document.getElementById("themePatternInput")?.value || "none");
    });
    grid.appendChild(button);
  });
  Object.entries(patternStyles).forEach(([key, pattern]) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "pattern-chip";
    button.dataset.pattern = key;
    button.title = key;
    button.style.backgroundImage = pattern === "none" ? "none" : pattern;
    button.style.backgroundSize = "18px 18px, cover";
    button.addEventListener("click", () => {
      document.getElementById("themePatternInput").value = key;
      document.querySelectorAll(".pattern-chip").forEach(chip => chip.classList.toggle("active", chip.dataset.pattern === key));
      applyPatternPreview(key);
      if (setupAutosaveReady) scheduleSetupAutosave();
    });
    patternGrid.appendChild(button);
  });
  document.getElementById("themeSolidColorInput")?.addEventListener("input", event => {
    setThemeValue(event.target.value);
    applyPatternPreview(document.getElementById("themePatternInput")?.value || "none");
  });
  document.querySelectorAll(".themeGradientColor,#themeGradientType,#themeGradientDirection").forEach(control => {
    control.addEventListener("input", () => {
      setThemeValue(buildGradientTheme());
      applyPatternPreview(document.getElementById("themePatternInput")?.value || "none");
    });
    control.addEventListener("change", () => {
      setThemeValue(buildGradientTheme());
      applyPatternPreview(document.getElementById("themePatternInput")?.value || "none");
    });
  });
  setThemeValue(document.getElementById("themeColorInput")?.value || "#6366f1");
  const currentPattern = document.getElementById("themePatternInput")?.value || "none";
  document.querySelectorAll(".pattern-chip").forEach(chip => chip.classList.toggle("active", chip.dataset.pattern === currentPattern));
  applyPatternPreview(currentPattern);
}
initThemeDesigner();
updateDashboardSetupPreview(setupSettingsPayload());
setupAutosaveReady = true;

document.querySelectorAll("input[name='dashboardBotImage']").forEach(input => {
  input.addEventListener("change", () => {
    const preview = document.getElementById("selectedBotImagePreview");
    if (preview && input.checked) preview.src = input.value;
    scheduleSetupAutosave();
  });
});

function rgbToHex(rgb) {
  const values = rgb.match(/\d+/g).map(Number);
  return "#" + values.slice(0, 3).map(v => v.toString(16).padStart(2, "0")).join("");
}

document.getElementById("faqSearch")?.addEventListener("input", event => {
  const q = event.target.value.toLowerCase();
  document.querySelectorAll("#faqTable tbody tr").forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(q) ? "" : "none";
  });
});

async function addFaq(customerId, question, answer, category) {
  const response = await fetch("/api.php?action=add_faq", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, faqs: [{question, answer, category}]})
  });

  const data = await response.json().catch(() => ({}));
  return data;
}

let temporaryBulkFaqReport = null;

function updateFaqCountUi() {
  const tag = document.getElementById("faqCountTag");
  if (tag) tag.textContent = `${currentFaqCount}/${faqLimitLabel} FAQs`;
}

function faqRowHtml(faq) {
  return `<tr data-faq-id="${htmlEscape(faq.id || "")}">
    <td>
      <span class="faq-display">${htmlEscape(faq.question || "")}</span>
      <textarea class="faq-edit-field faq-question-input" aria-label="FAQ question">${htmlEscape(faq.question || "")}</textarea>
    </td>
    <td>
      <span class="faq-display">${htmlEscape(faq.answer || "")}</span>
      <textarea class="faq-edit-field faq-answer-input" aria-label="FAQ answer">${htmlEscape(faq.answer || "")}</textarea>
    </td>
    <td>
      <span class="tag faq-display">${htmlEscape(faq.category || "General")}</span>
      <input class="faq-edit-field faq-category-input" value="${htmlEscape(faq.category || "General")}" aria-label="FAQ category">
    </td>
    <td>
      <div class="faq-actions">
        <button class="ghost-btn faq-edit-btn" type="button">Edit</button>
        <button class="pill-btn faq-save-btn faq-edit-field" type="button">Save</button>
        <button class="ghost-btn faq-cancel-btn faq-edit-field" type="button">Cancel</button>
        <button class="danger-btn faq-delete-btn" type="button">Delete</button>
      </div>
    </td>
  </tr>`;
}

function appendBulkFaqRows(savedRows) {
  const body = document.querySelector("#faqTable tbody");
  if (!body || !Array.isArray(savedRows)) return;
  body.insertAdjacentHTML("afterbegin", savedRows.map(faqRowHtml).join(""));
}

function requireXlsx() {
  if (!window.XLSX) {
    showToast("Excel tools could not be loaded. Please refresh and try again.");
    return false;
  }
  return true;
}

function downloadWorkbook(filename, sheets) {
  if (!requireXlsx()) return;
  const workbook = XLSX.utils.book_new();
  Object.entries(sheets).forEach(([name, rows]) => {
    XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet(rows), name.slice(0, 31));
  });
  XLSX.writeFile(workbook, filename);
}

document.getElementById("downloadFaqSampleBtn")?.addEventListener("click", event => {
  event.preventDefault();
  downloadWorkbook("vani-faq-upload-sample.xlsx", {
    "FAQs": [
      ["Question", "Answer", "Category"],
      ["What payment methods do you accept?", "We accept UPI, credit card, debit card, and net banking.", "Payments"],
      ["How can I contact support?", "You can contact our support team from the contact page or WhatsApp button.", "Support"]
    ]
  });
});

function excelHeaderIndex(headers, names) {
  return headers.findIndex(header => names.includes(String(header || "").trim().toLowerCase()));
}

async function parseFaqExcel(file) {
  if (!requireXlsx()) return [];
  const buffer = await file.arrayBuffer();
  const workbook = XLSX.read(buffer, {type: "array"});
  const sheet = workbook.Sheets[workbook.SheetNames[0]];
  const rows = XLSX.utils.sheet_to_json(sheet, {header: 1, defval: ""});
  if (rows.length < 2) return [];
  const headers = rows[0].map(value => String(value || "").trim().toLowerCase());
  const questionIndex = excelHeaderIndex(headers, ["question", "faq question", "questions"]);
  const answerIndex = excelHeaderIndex(headers, ["answer", "faq answer", "answers"]);
  const categoryIndex = excelHeaderIndex(headers, ["category", "faq category", "categories"]);
  if (questionIndex < 0 || answerIndex < 0) {
    throw new Error("Excel must include Question and Answer columns");
  }
  return rows.slice(1).map((row, index) => {
    const question = String(row[questionIndex] || "").trim();
    const answer = String(row[answerIndex] || "").trim();
    const rawCategory = categoryIndex >= 0 ? String(row[categoryIndex] || "").trim() : "";
    return {
      row: index + 2,
      question,
      answer,
      category: rawCategory || "General",
      hasAnyValue: !!(question || answer || rawCategory)
    };
  }).filter(item => item.hasAnyValue).map(({hasAnyValue, ...item}) => item);
}

function reportRowsForExport(report, type) {
  const rows = [["Status", "Excel Row", "Question", "Answer", "Category", "Reason"]];
  const source = type === "saved" ? report.saved || [] : report.failed || [];
  source.forEach(item => rows.push([
    type === "saved" ? "Saved" : "Failed",
    item.row || "",
    item.question || "",
    item.answer || "",
    item.category || "General",
    item.reason || ""
  ]));
  return rows;
}

function reportTable(title, rows, status) {
  const body = rows.length
    ? rows.map(item => `<tr><td>${htmlEscape(item.row || "")}</td><td>${htmlEscape(item.question || "")}</td><td>${htmlEscape(item.category || "General")}</td><td>${htmlEscape(item.reason || status)}</td></tr>`).join("")
    : `<tr><td colspan="4" class="empty">No ${htmlEscape(title.toLowerCase())} rows.</td></tr>`;
  return `<div>
    <h3>${htmlEscape(title)}</h3>
    <div class="bulk-report-table"><table><thead><tr><th>Excel Row</th><th>Question</th><th>Category</th><th>Status / Reason</th></tr></thead><tbody>${body}</tbody></table></div>
  </div>`;
}

function showBulkFaqReport(report) {
  temporaryBulkFaqReport = report;
  const modal = document.getElementById("bulkFaqReportModal");
  const body = document.getElementById("bulkFaqReportBody");
  if (!modal || !body) return;
  body.innerHTML = `
    <div class="bulk-report-summary">
      <div class="panel metric"><span>Saved</span><strong>${htmlEscape(report.saved_count || 0)}</strong><small>Inserted into FAQ database.</small></div>
      <div class="panel metric"><span>Failed</span><strong>${htmlEscape(report.failed_count || 0)}</strong><small>Not saved. Check reasons below.</small></div>
      <div class="panel metric"><span>Plan Limit</span><strong>${htmlEscape(report.faq_limit || faqLimitLabel)}</strong><small>${htmlEscape(report.active_plan || "plan")} plan.</small></div>
    </div>
    <div class="panel-actions" style="padding-top:0">
      <button class="pill-btn" type="button" id="exportBulkFaqReportBtn">Export report Excel</button>
    </div>
    ${reportTable("Successfully Uploaded And Saved", report.saved || [], "Saved")}
    ${reportTable("Failed Rows", report.failed || [], "Failed")}
  `;
  modal.classList.add("active");
  modal.setAttribute("aria-hidden", "false");
  document.getElementById("exportBulkFaqReportBtn")?.addEventListener("click", () => {
    if (!temporaryBulkFaqReport) return showToast("No temporary report to export");
    downloadWorkbook("vani-bulk-faq-upload-report.xlsx", {
      "Successful": reportRowsForExport(temporaryBulkFaqReport, "saved"),
      "Failed": reportRowsForExport(temporaryBulkFaqReport, "failed")
    });
  });
}

function closeBulkFaqReport() {
  temporaryBulkFaqReport = null;
  const modal = document.getElementById("bulkFaqReportModal");
  const body = document.getElementById("bulkFaqReportBody");
  if (body) body.innerHTML = "";
  if (modal) {
    modal.classList.remove("active");
    modal.setAttribute("aria-hidden", "true");
  }
}

document.getElementById("closeBulkFaqReportBtn")?.addEventListener("click", closeBulkFaqReport);

document.getElementById("bulkFaqUploadBtn")?.addEventListener("click", async event => {
  const customerId = document.getElementById("faqCustomerId")?.value || "";
  const fileInput = document.getElementById("bulkFaqFileInput");
  const file = fileInput?.files?.[0];
  if (!customerId) return showToast("Select a bot first");
  if (!file) return showToast("Choose an Excel file");
  if (!/\.(xlsx|xls)$/i.test(file.name)) {
    if (fileInput) fileInput.value = "";
    return showToast("Only Excel files are accepted");
  }
  if (!faqLimitIsUnlimited && currentFaqCount >= freeFaqLimit) {
    showToast("Your current FAQ plan limit is already reached");
    openTab("subscription");
    return;
  }

  const button = event.currentTarget;
  button.disabled = true;
  button.textContent = "Uploading...";
  try {
    const faqs = await parseFaqExcel(file);
    if (!faqs.length) throw new Error("No FAQ rows found in Excel");
    const response = await fetch("/api.php?action=bulk_add_faq", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({customer_id: customerId, faqs})
    });
    const report = await response.json().catch(() => ({}));
    if (!report.success) throw new Error(report.message || "Bulk upload failed");
    appendBulkFaqRows(report.saved || []);
    currentFaqCount += Number(report.saved_count || 0);
    updateFaqCountUi();
    showBulkFaqReport(report);
    if (fileInput) fileInput.value = "";
    showToast("Bulk FAQ upload completed");
  } catch (error) {
    showToast(error.message || "Bulk FAQ upload failed");
  } finally {
    button.disabled = false;
    button.textContent = "Upload Excel FAQs";
  }
});

document.getElementById("addSuggestedFaqsBtn")?.addEventListener("click", async event => {
  const customerId = document.getElementById("faqCustomerId")?.value || "";
  const items = Array.from(document.querySelectorAll("#suggestedFaqList .suggested-faq-item"));
  if (!customerId) return showToast("Select a bot first");
  if (!items.length) return showToast("No suggested FAQs available");
  const faqs = items.map(item => ({
    question: item.dataset.question || "",
    answer: item.dataset.answer || "",
    category: item.dataset.category || "General"
  })).filter(item => item.question && item.answer);
  if (!faqs.length) return showToast("No valid suggested FAQs available");
  if (!faqLimitIsUnlimited && currentFaqCount + faqs.length > freeFaqLimit) {
    showToast("Your current plan limit cannot save all suggested FAQs");
    openTab("subscription");
    return;
  }
  const button = event.currentTarget;
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = "Adding FAQs...";
  try {
    const response = await fetch("/api.php?action=bulk_add_faq", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({customer_id: customerId, faqs})
    });
    const report = await response.json().catch(() => ({}));
    if (!report.success) throw new Error(report.message || "Suggested FAQs could not be added");
    appendBulkFaqRows(report.saved || []);
    currentFaqCount += Number(report.saved_count || 0);
    updateFaqCountUi();
    document.querySelector("#suggestedFaqList")?.closest(".setup-recovery-card")?.remove();
    showToast("Suggested FAQs added");
  } catch (error) {
    button.disabled = false;
    button.textContent = originalText;
    showToast(error.message || "Suggested FAQs could not be added");
  }
});

document.getElementById("faqForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  const customerId = document.getElementById("faqCustomerId").value;
  const question = document.getElementById("faqQuestion").value.trim();
  const answer = document.getElementById("faqAnswer").value.trim();
  const category = document.getElementById("faqCategory").value.trim() || "General";
  if (!customerId) return showToast("Select a bot first");
  if (!question || !answer) return showToast("Question and answer are required");
  if (currentFaqCount >= freeFaqLimit) {
    showToast("Upgrade to add more FAQs");
    openTab("subscription");
    return;
  }

  const saved = await addFaq(customerId, question, answer, category);
  if (saved.requires_premium) {
    showToast(saved.message || "Upgrade to add more FAQs");
    openTab("subscription");
    return;
  }
  if (saved.error || saved.success === false) {
    showToast("FAQ could not be saved");
    return;
  }
  showToast("FAQ added");
  setTimeout(() => location.reload(), 700);
});

document.querySelectorAll(".outsideFaqForm").forEach(form => {
  form.addEventListener("submit", async event => {
    event.preventDefault();
    const customerId = form.querySelector(".outsideCustomerId")?.value || "";
    const question = form.querySelector(".outsideQuestion")?.value.trim() || "";
    const answer = form.querySelector(".outsideAnswer")?.value.trim() || "";
    const category = form.querySelector(".outsideCategory")?.value.trim() || "General";
    const button = form.querySelector("button[type='submit']");

    if (!customerId) return showToast("Select a bot first");
    if (!question || !answer) return showToast("Question and answer are required");
    if (currentFaqCount >= freeFaqLimit) {
      showToast("Upgrade to add more FAQs");
      openTab("subscription");
      return;
    }

    if (button) {
      button.disabled = true;
      button.textContent = "Saving...";
    }

    const saved = await addFaq(customerId, question, answer, category);
    if (saved.requires_premium) {
      if (button) {
        button.disabled = false;
        button.textContent = "Add to FAQs";
      }
      showToast(saved.message || "Upgrade to add more FAQs");
      openTab("subscription");
      return;
    }
    if (saved.error || saved.success === false) {
      if (button) {
        button.disabled = false;
        button.textContent = "Add to FAQs";
      }
      showToast("FAQ could not be saved");
      return;
    }

    form.style.opacity = ".65";
    form.querySelectorAll("input, textarea, button").forEach(input => input.disabled = true);
    if (button) button.textContent = "Added";
    currentFaqCount++;
    showToast("Added to FAQs");
  });
});

function setFaqRowEditing(row, editing) {
  row.classList.toggle("editing", editing);
}

document.getElementById("faqTable")?.addEventListener("click", async event => {
  const button = event.target.closest("button");
  const row = event.target.closest("tr[data-faq-id]");
  if (!button || !row) return;

  const customerId = document.getElementById("faqCustomerId").value;
  const faqId = row.dataset.faqId || "";
  const questionInput = row.querySelector(".faq-question-input");
  const answerInput = row.querySelector(".faq-answer-input");
  const categoryInput = row.querySelector(".faq-category-input");

  if (button.classList.contains("faq-edit-btn")) {
    setFaqRowEditing(row, true);
    questionInput?.focus();
    return;
  }

  if (button.classList.contains("faq-cancel-btn")) {
    questionInput.value = row.children[0].querySelector(".faq-display").textContent.trim();
    answerInput.value = row.children[1].querySelector(".faq-display").textContent.trim();
    categoryInput.value = row.children[2].querySelector(".faq-display").textContent.trim();
    setFaqRowEditing(row, false);
    return;
  }

  if (button.classList.contains("faq-save-btn")) {
    const question = questionInput.value.trim();
    const answer = answerInput.value.trim();
    const category = categoryInput.value.trim() || "General";
    if (!question || !answer) return showToast("Question and answer are required");

    button.disabled = true;
    button.textContent = "Saving...";
    const response = await fetch("/api.php?action=update_faq", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({customer_id: customerId, id: faqId, question, answer, category})
    });
    const data = await response.json().catch(() => ({}));
    button.disabled = false;
    button.textContent = "Save";

    if (!data.success) return showToast(data.message || "FAQ could not be updated");

    row.children[0].querySelector(".faq-display").textContent = question;
    row.children[1].querySelector(".faq-display").textContent = answer;
    row.children[2].querySelector(".faq-display").textContent = category;
    setFaqRowEditing(row, false);
    showToast("FAQ updated");
    return;
  }

  if (button.classList.contains("faq-delete-btn")) {
    if (!confirm("Delete this FAQ?")) return;
    button.disabled = true;
    button.textContent = "Deleting...";
    const response = await fetch("/api.php?action=delete_faq", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({customer_id: customerId, id: faqId})
    });
    const data = await response.json().catch(() => ({}));
    if (!data.success) {
      button.disabled = false;
      button.textContent = "Delete";
      return showToast(data.message || "FAQ could not be deleted");
    }
    row.remove();
    currentFaqCount = Math.max(0, currentFaqCount - 1);
    showToast("FAQ deleted");
  }
});

async function saveFaqActionsToggle({live = false} = {}) {
  const toggle = document.getElementById("faqActionsToggle");
  if (!toggle) return;
  if (toggle.checked && !businessFeatures.faq_action_suggestions) {
    toggle.checked = false;
    alert("FAQ Action Suggestions requires Starter, Growth, or Business plan");
    openTab("subscription");
    return;
  }
  const saved = await saveDashboardSettings({
    faq_actions_enabled: businessFeatures.faq_action_suggestions && !!toggle.checked
  });
  if (saved && live) {
    showToast(toggle.checked ? "FAQ Action Suggestions enabled" : "FAQ Action Suggestions disabled");
  }
}

document.getElementById("faqActionsToggle")?.addEventListener("change", () => {
  saveFaqActionsToggle({live: true});
});

document.getElementById("faqCategoryMenuToggle")?.addEventListener("change", async event => {
  const saved = await saveDashboardSettings({
    faq_category_menu_enabled: !!event.currentTarget.checked
  });
  if (saved) {
    showToast(event.currentTarget.checked ? "FAQ category menu enabled" : "FAQ category menu disabled");
  }
});

async function saveFaqFeedbackSettings({live = false} = {}) {
  if (!businessFeatures.faq_feedback) {
    showToast("FAQ feedback requires Growth or Business plan");
    return;
  }
  const enabled = !!document.getElementById("faqFeedbackToggle")?.checked;
  const feedbackType = document.querySelector(".faqFeedbackType:checked")?.value || "labels";
  const actionIds = Array.from(document.querySelectorAll(".faqFeedbackAction:checked")).map(input => input.value);
  const saved = await saveDashboardSettings({
    faq_feedback_enabled: enabled,
    faq_feedback_type: feedbackType,
    faq_feedback_action_ids: actionIds
  });
  if (saved && live) {
    showToast(enabled ? "FAQ feedback enabled" : "FAQ feedback disabled");
  }
}

document.getElementById("faqFeedbackToggle")?.addEventListener("change", () => {
  saveFaqFeedbackSettings({live: true});
});

document.querySelectorAll(".faqFeedbackAction").forEach(input => {
  input.addEventListener("change", () => {
    saveFaqFeedbackSettings({live: true});
  });
});

document.querySelectorAll(".faqFeedbackType").forEach(input => {
  input.addEventListener("change", () => {
    saveFaqFeedbackSettings({live: true});
  });
});

document.getElementById("feedbackEmailToggle")?.addEventListener("change", async event => {
  if (!businessFeatures.faq_feedback) {
    event.currentTarget.checked = false;
    showToast("Feedback Received requires Growth or Business plan");
    return;
  }
  const enabled = !!event.currentTarget.checked;
  const saved = await saveDashboardSettings({faq_feedback_email_enabled: enabled});
  if (saved) {
    showToast(enabled ? "Feedback email alerts enabled" : "Feedback email alerts disabled");
  }
});

async function savePaymentSettings(button = null, savedLabel = "Save payment setup", options = {}) {
  const shouldReload = options.reload !== false;
  const requireConsent = options.requireConsent !== false;
  const successMessage = options.successMessage || "Payment setup saved";
  const paymentToggle = document.getElementById("paymentEnabledToggle");
  if (paymentToggle?.checked && !businessFeatures.payment_collection) {
    paymentToggle.checked = false;
    alert("This feature is only for Growth or Business users. Please recharge your wallet with appropriate plan.");
    openTab("subscription");
    return false;
  }
  const customerId = document.getElementById("paymentCustomerId")?.value || "";
  if (!customerId) {
    showToast("Select a bot first");
    return false;
  }
  const razorpayToggle = document.getElementById("paymentRazorpayEnabledToggle");
  if (requireConsent && razorpayToggle?.checked && !razorpayTermsAccepted) {
    openRazorpayConsentModal();
    return false;
  }
  const upiToggle = document.getElementById("paymentUpiEnabledToggle");
  if (requireConsent && upiToggle?.checked && !upiTermsAccepted) {
    openUpiConsentModal();
    return false;
  }
  if (button) {
    button.disabled = true;
    button.textContent = "Saving...";
  }
  try {
    const response = await fetch("/api.php?action=save_payment_settings", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({
        customer_id: customerId,
        is_enabled: !!document.getElementById("paymentEnabledToggle")?.checked,
        razorpay_enabled: !!document.getElementById("paymentRazorpayEnabledToggle")?.checked,
        razorpay_terms_accepted: razorpayTermsAccepted,
        upi_enabled: !!document.getElementById("paymentUpiEnabledToggle")?.checked,
        upi_transaction_id_required: !!document.getElementById("paymentUpiTransactionIdToggle")?.checked,
        upi_terms_accepted: upiTermsAccepted,
        business_name: document.getElementById("paymentBusinessNameInput")?.value.trim() || "",
        collect_payer_email: !!document.getElementById("paymentCollectEmailToggle")?.checked,
        collect_payer_phone: !!document.getElementById("paymentCollectPhoneToggle")?.checked,
        verify_payer_email_otp: !!document.getElementById("paymentVerifyEmailOtpToggle")?.checked,
        verify_payer_phone_otp: !!document.getElementById("paymentVerifyPhoneOtpToggle")?.checked,
        razorpay_key_id: document.getElementById("paymentKeyIdInput")?.value.trim() || "",
        razorpay_key_secret: document.getElementById("paymentKeySecretInput")?.value.trim() || "",
        success_message: document.getElementById("paymentSuccessMessageInput")?.value.trim() || ""
      })
    });
    const data = await response.json().catch(() => ({}));
    if (!data.success) {
      showToast(data.message || "Payment setup could not be saved");
      return false;
    }
    showToast(successMessage);
    if (shouldReload) setTimeout(() => location.reload(), 700);
    return true;
  } catch (error) {
    showToast("Payment setup could not be saved");
    return false;
  } finally {
    if (button) {
      button.disabled = false;
      button.textContent = savedLabel;
    }
  }
}

document.getElementById("paymentSettingsForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  await savePaymentSettings(event.currentTarget.querySelector("button[type='submit']"), "Save payment setup");
});

document.getElementById("paymentRazorpaySettingsForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  await savePaymentSettings(event.currentTarget.querySelector("button[type='submit']"), "Save Razorpay checkout");
});

document.getElementById("paymentUpiSettingsForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  await savePaymentSettings(event.currentTarget.querySelector("button[type='submit']"), "Save UPI redirect");
});

async function autosavePaymentSwitch(toggle, message) {
  if (!toggle) return;
  const previous = !toggle.checked;
  toggle.disabled = true;
  const saved = await savePaymentSettings(null, "Save payment setup", {
    reload: false,
    requireConsent: false,
    successMessage: message || "Payment setup saved"
  });
  toggle.disabled = false;
  if (!saved) toggle.checked = previous;
}

async function confirmPaymentOtpToggle(toggle, kind) {
  if (!toggle) return;
  if (!toggle.checked) {
    await autosavePaymentSwitch(toggle, kind === "email" ? "Payment email OTP disabled" : "Payment mobile OTP disabled");
    return;
  }
  const collectToggle = document.getElementById(kind === "email" ? "paymentCollectEmailToggle" : "paymentCollectPhoneToggle");
  if (collectToggle && !collectToggle.checked) {
    collectToggle.checked = true;
  }
  const featureKey = kind === "email" ? "email_otp" : "mobile_otp";
  if (!leadPaidFeatures[featureKey]) {
    toggle.checked = false;
    showToast(kind === "email" ? "Email OTP requires an active paid plan" : "Mobile OTP requires an active paid plan");
    return;
  }
  if (leadOtpAlreadyEnabled[kind]) {
    const label = kind === "email" ? "email" : "mobile number";
    const confirmed = confirm(`Lead Generation is already verifying the user's ${label} with OTP before payment. Enable this only if you want to verify the ${label} again during payment.`);
    if (!confirmed) {
      toggle.checked = false;
      return;
    }
  }
  await autosavePaymentSwitch(toggle, kind === "email" ? "Payment email OTP enabled" : "Payment mobile OTP enabled");
}

document.getElementById("paymentEnabledToggle")?.addEventListener("change", async event => {
  if (event.currentTarget.checked && !businessFeatures.payment_collection) {
    event.currentTarget.checked = false;
    alert("This feature is only for Growth or Business users. Please recharge your wallet with appropriate plan.");
    openTab("subscription");
    return;
  }
  await autosavePaymentSwitch(event.currentTarget, event.currentTarget.checked ? "Payment collection enabled" : "Payment collection disabled");
});

document.getElementById("paymentCollectEmailToggle")?.addEventListener("change", async event => {
  if (!event.currentTarget.checked) {
    const verifyToggle = document.getElementById("paymentVerifyEmailOtpToggle");
    if (verifyToggle) verifyToggle.checked = false;
  }
  await autosavePaymentSwitch(event.currentTarget, event.currentTarget.checked ? "Email collection enabled" : "Email collection disabled");
});

document.getElementById("paymentCollectPhoneToggle")?.addEventListener("change", async event => {
  if (!event.currentTarget.checked) {
    const verifyToggle = document.getElementById("paymentVerifyPhoneOtpToggle");
    if (verifyToggle) verifyToggle.checked = false;
  }
  await autosavePaymentSwitch(event.currentTarget, event.currentTarget.checked ? "Mobile number collection enabled" : "Mobile number collection disabled");
});

document.getElementById("paymentVerifyEmailOtpToggle")?.addEventListener("change", async event => {
  await confirmPaymentOtpToggle(event.currentTarget, "email");
});

document.getElementById("paymentVerifyPhoneOtpToggle")?.addEventListener("change", async event => {
  await confirmPaymentOtpToggle(event.currentTarget, "mobile");
});

document.getElementById("paymentUpiTransactionIdToggle")?.addEventListener("change", async event => {
  await autosavePaymentSwitch(event.currentTarget, event.currentTarget.checked ? "UPI transaction ID prompt enabled" : "UPI transaction ID prompt disabled");
});

function openRazorpayConsentModal() {
  if (!razorpayConsentModal) return;
  if (razorpayConsentCheckbox) razorpayConsentCheckbox.checked = false;
  razorpayConsentModal.classList.add("active");
  razorpayConsentModal.setAttribute("aria-hidden", "false");
  razorpayConsentCheckbox?.focus();
}

function closeRazorpayConsentModal() {
  razorpayConsentModal?.classList.remove("active");
  razorpayConsentModal?.setAttribute("aria-hidden", "true");
}

document.getElementById("paymentRazorpayEnabledToggle")?.addEventListener("change", async event => {
  if (event.currentTarget.checked) {
    razorpayTermsAccepted = false;
    openRazorpayConsentModal();
    return;
  }
  await autosavePaymentSwitch(event.currentTarget, "Razorpay Checkout disabled");
});

razorpayConsentCancelBtn?.addEventListener("click", () => {
  const razorpayToggle = document.getElementById("paymentRazorpayEnabledToggle");
  if (razorpayToggle) razorpayToggle.checked = false;
  closeRazorpayConsentModal();
});

razorpayConsentAcceptBtn?.addEventListener("click", async () => {
  if (!razorpayConsentCheckbox?.checked) {
    showToast("Please accept the Razorpay Checkout terms to continue");
    return;
  }
  razorpayTermsAccepted = true;
  const razorpayToggle = document.getElementById("paymentRazorpayEnabledToggle");
  if (razorpayToggle) razorpayToggle.checked = true;
  closeRazorpayConsentModal();
  await autosavePaymentSwitch(razorpayToggle, "Razorpay Checkout enabled");
});

function openUpiConsentModal() {
  if (!upiConsentModal) return;
  if (upiConsentCheckbox) upiConsentCheckbox.checked = false;
  upiConsentModal.classList.add("active");
  upiConsentModal.setAttribute("aria-hidden", "false");
  upiConsentCheckbox?.focus();
}

function closeUpiConsentModal() {
  upiConsentModal?.classList.remove("active");
  upiConsentModal?.setAttribute("aria-hidden", "true");
}

document.getElementById("paymentUpiEnabledToggle")?.addEventListener("change", async event => {
  if (event.currentTarget.checked) {
    upiTermsAccepted = false;
    openUpiConsentModal();
    return;
  }
  await autosavePaymentSwitch(event.currentTarget, "UPI Redirect disabled");
});

upiConsentCancelBtn?.addEventListener("click", () => {
  const upiToggle = document.getElementById("paymentUpiEnabledToggle");
  if (upiToggle) upiToggle.checked = false;
  closeUpiConsentModal();
});

upiConsentAcceptBtn?.addEventListener("click", async () => {
  if (!upiConsentCheckbox?.checked) {
    showToast("Please accept the UPI Redirect terms to continue");
    return;
  }
  upiTermsAccepted = true;
  const upiToggle = document.getElementById("paymentUpiEnabledToggle");
  if (upiToggle) upiToggle.checked = true;
  closeUpiConsentModal();
  await autosavePaymentSwitch(upiToggle, "UPI Redirect enabled");
});

function openPaymentSubtab(target) {
  const id = document.getElementById(target) ? target : "payment-subpanel-setup";
  document.querySelectorAll(".payment-subtab-btn").forEach(button => {
    button.classList.toggle("active", button.dataset.paymentSubtab === id);
  });
  document.querySelectorAll(".payment-subpanel").forEach(panel => {
    panel.classList.toggle("active", panel.id === id);
  });
}

document.querySelectorAll(".payment-subtab-btn").forEach(button => {
  button.addEventListener("click", () => openPaymentSubtab(button.dataset.paymentSubtab || "payment-subpanel-setup"));
});

async function submitPaymentActionForm(event) {
  event.preventDefault();
  const customerId = document.getElementById("paymentCustomerId")?.value || "";
  const form = event.currentTarget;
  const method = form.dataset.paymentMethod || "razorpay";
  const label = form.querySelector('[data-payment-field="label"]')?.value.trim() || "";
  const amount = form.querySelector('[data-payment-field="amount"]')?.value || "";
  const upiId = form.querySelector('[data-payment-field="upi_id"]')?.value.trim() || "";
  if (!customerId) return showToast("Select a bot first");
  if (!label || !amount) return showToast("Payment label and amount are required");
  if (method === "upi" && !upiId) return showToast("UPI ID is required");
  const button = form.querySelector("button[type='submit']");
  const originalText = button?.textContent || "Add payment button";
  button.disabled = true;
  button.textContent = "Saving...";
  const response = await fetch("/api.php?action=save_payment_action", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({
      customer_id: customerId,
      payment_method: method,
      label,
      amount_rupees: amount,
      description: form.querySelector('[data-payment-field="description"]')?.value.trim() || "",
      upi_id: upiId,
      upi_payee_name: form.querySelector('[data-payment-field="upi_payee_name"]')?.value.trim() || "",
      upi_note: form.querySelector('[data-payment-field="upi_note"]')?.value.trim() || "",
      is_active: !!form.querySelector('[data-payment-field="active"]')?.checked
    })
  });
  const data = await response.json().catch(() => ({}));
  button.disabled = false;
  button.textContent = originalText;
  if (!data.success) return showToast(data.message || "Payment button could not be saved");
  showToast("Payment button saved");
  setTimeout(() => location.reload(), 700);
}

document.querySelectorAll(".payment-action-form").forEach(form => {
  form.addEventListener("submit", submitPaymentActionForm);
});

document.getElementById("paymentActionList")?.addEventListener("click", async event => {
  const row = event.target.closest("[data-payment-action-id]");
  if (!row) return;
  const activeToggle = event.target.closest(".payment-action-active-toggle");
  if (activeToggle) {
    const customerId = document.getElementById("paymentCustomerId")?.value || "";
    const previousState = !activeToggle.checked;
    activeToggle.disabled = true;
    const response = await fetch("/api.php?action=update_payment_action_status", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({
        customer_id: customerId,
        id: row.dataset.paymentActionId || "",
        is_active: !!activeToggle.checked
      })
    });
    const data = await response.json().catch(() => ({}));
    activeToggle.disabled = false;
    if (!data.success) {
      activeToggle.checked = previousState;
      return showToast(data.message || "Payment button status could not be updated");
    }
    showToast(activeToggle.checked ? "Payment button activated" : "Payment button deactivated");
    setTimeout(() => location.reload(), 500);
    return;
  }
  const copyButton = event.target.closest(".payment-action-copy-btn");
  if (copyButton) {
    await navigator.clipboard.writeText(row.dataset.paymentActionId || "");
    showToast("Payment ID copied");
    return;
  }
  const createButton = event.target.closest(".payment-action-create-faq-btn");
  if (createButton) {
    if (!businessFeatures.faq_action_suggestions) {
      showToast("FAQ Action Suggestions requires Starter, Growth, or Business plan");
      openTab("subscription");
      return;
    }
    if (!document.getElementById("faqActionsToggle")?.checked) {
      showToast("Turn ON FAQ Action Suggestions first");
      openTab("faqs");
      openFaqSubtab("faq-subpanel-options");
      return;
    }
    const customerId = document.getElementById("paymentCustomerId")?.value || "";
    const faqId = document.getElementById("paymentFaqActionFaqId")?.value || "";
    if (!customerId) return showToast("Select a bot first");
    if (!faqId) return showToast("Select FAQ for Make Payment action");
    createButton.disabled = true;
    createButton.textContent = "Creating...";
    const response = await fetch("/api.php?action=save_faq_action", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({
        customer_id: customerId,
        faq_id: faqId,
        label: "Make Payment",
        action_type: "payment",
        action_value: row.dataset.paymentActionId || "",
        display_order: 0,
        is_active: true
      })
    });
    const data = await response.json().catch(() => ({}));
    createButton.disabled = false;
    createButton.textContent = "Create Make Payment Action";
    if (!data.success) return showToast(data.message || "Make Payment action could not be created");
    showToast("Make Payment action created");
    setTimeout(() => location.reload(), 700);
    return;
  }
  const button = event.target.closest(".payment-action-delete-btn");
  if (!button) return;
  if (!confirm("Delete this payment button?")) return;
  const customerId = document.getElementById("paymentCustomerId")?.value || "";
  button.disabled = true;
  button.textContent = "Deleting...";
  const response = await fetch("/api.php?action=delete_payment_action", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, id: row.dataset.paymentActionId || ""})
  });
  const data = await response.json().catch(() => ({}));
  if (!data.success) {
    button.disabled = false;
    button.textContent = "Delete";
    return showToast(data.message || "Payment button could not be deleted");
  }
  row.remove();
  showToast("Payment button deleted");
});

document.getElementById("paymentTransactionsTable")?.addEventListener("click", async event => {
  const button = event.target.closest(".payment-transaction-status-btn");
  if (!button) return;
  const row = button.closest("[data-payment-transaction-id]");
  const customerId = document.getElementById("paymentCustomerId")?.value || "";
  const status = button.dataset.paymentStatus || "";
  if (!row || !customerId || !status) return;
  if (!confirm(`Mark this UPI payment as ${status}?`)) return;
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = "Saving...";
  const response = await fetch("/api.php?action=update_payment_transaction_status", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({
      customer_id: customerId,
      id: row.dataset.paymentTransactionId || "",
      status
    })
  });
  const data = await response.json().catch(() => ({}));
  button.disabled = false;
  button.textContent = originalText;
  if (!data.success) return showToast(data.message || "Payment status could not be updated");
  showToast(status === "paid" ? "UPI payment marked paid" : "UPI payment marked failed");
  setTimeout(() => location.reload(), 500);
});

const faqActionHelp = {
  link: ["https://example.com/product", "Use a secure https:// page, service, or product URL."],
  whatsapp: ["+919876543210", "Use a WhatsApp number with country code."],
  call: ["+919876543210", "Use a phone number with country code. The visitor's phone dialer will open."],
  email: ["support@example.com", "Use the email address where the visitor should send the message."],
  download: ["https://example.com/brochure.pdf", "Use a secure https:// file URL for PDF, catalog, menu, brochure, or price list."],
  coupon: ["WELCOME10", "Enter the coupon or code. The widget will copy it to the visitor's clipboard."],
  booking: ["https://calendly.com/your-business/demo", "Use a secure https:// booking link."],
  map: ["https://maps.google.com/?q=Your+Store or full address", "Use a Google Maps link or a full address."],
  form: ["Callback request", "Enter the form title or purpose. The widget will show name, email, mobile, and message fields."],
  track_order: ["https://example.com/track-order", "Use a secure https:// tracking or status page URL."],
  category: ["Pricing", "Enter the FAQ category name to show related FAQs in the chatbot."],
  payment: [paymentActions.find(action => action.is_active)?.id || "Create a payment button first", "Enter/select the Payment Button ID from Payments Collection. The visitor will pay directly to the customer's Razorpay account."],
  event: ["openPricing", "Enter a website event name. Your site can listen for window event vani:openPricing."]
};

function updateFaqActionHelp() {
  const type = document.getElementById("faqActionType")?.value || "link";
  const valueInput = document.getElementById("faqActionValue");
  const help = document.getElementById("faqActionValueHelp");
  const info = faqActionHelp[type] || faqActionHelp.link;
  if (valueInput) valueInput.placeholder = info[0];
  if (help) help.textContent = info[1];
  if (type === "payment" && valueInput && paymentActions.find(action => action.is_active)) {
    valueInput.value = valueInput.value || paymentActions.find(action => action.is_active).id;
  }
}

document.getElementById("faqActionType")?.addEventListener("change", updateFaqActionHelp);
updateFaqActionHelp();

document.getElementById("faqActionForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  if (!businessFeatures.faq_action_suggestions) {
    showToast("FAQ Action Suggestions requires Starter, Growth, or Business plan");
    openTab("subscription");
    return;
  }
  if (!document.getElementById("faqActionsToggle")?.checked) {
    showToast("Turn ON FAQ Action Suggestions first");
    return;
  }
  const button = event.currentTarget.querySelector("button[type='submit']");
  const customerId = document.getElementById("faqActionCustomerId")?.value || "";
  const faqId = document.getElementById("faqActionFaqId")?.value || "";
  const label = document.getElementById("faqActionLabel")?.value.trim() || "";
  const actionType = document.getElementById("faqActionType")?.value || "link";
  const actionValue = document.getElementById("faqActionValue")?.value.trim() || "";
  const displayOrder = Number(document.getElementById("faqActionOrder")?.value || 0);
  if (!customerId) return showToast("Select a bot first");
  if (!faqId) return showToast("Select FAQ");
  if (!label) return showToast("Enter button label");
  if (!actionValue) return showToast("Enter action value");
  button.disabled = true;
  button.textContent = "Saving...";
  const response = await fetch("/api.php?action=save_faq_action", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({
      customer_id: customerId,
      faq_id: faqId,
      label,
      action_type: actionType,
      action_value: actionValue,
      display_order: displayOrder,
      is_active: true
    })
  });
  const data = await response.json().catch(() => ({}));
  button.disabled = false;
  button.textContent = "Add action";
  if (!data.success) {
    showToast(data.message || "FAQ action could not be saved");
    if (data.requires_paid) openTab("subscription");
    return;
  }
  showToast("FAQ action saved");
  setTimeout(() => location.reload(), 700);
});

document.getElementById("faqActionList")?.addEventListener("click", async event => {
  const button = event.target.closest(".faq-action-delete-btn");
  const card = event.target.closest("[data-faq-action-id]");
  if (!button || !card) return;
  if (!confirm("Delete this FAQ action?")) return;
  const customerId = document.getElementById("faqActionCustomerId")?.value || "";
  button.disabled = true;
  button.textContent = "Deleting...";
  const response = await fetch("/api.php?action=delete_faq_action", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, id: card.dataset.faqActionId || ""})
  });
  const data = await response.json().catch(() => ({}));
  if (!data.success) {
    button.disabled = false;
    button.textContent = "Delete";
    return showToast(data.message || "FAQ action could not be deleted");
  }
  card.remove();
  showToast("FAQ action deleted");
});

document.getElementById("saveScheduledFaqActionsBtn")?.addEventListener("click", async event => {
  if (!businessFeatures.faq_action_suggestions) {
    showToast("FAQ Action Suggestions requires Starter, Growth, or Business plan");
    openTab("subscription");
    return;
  }
  if (!document.getElementById("faqActionsToggle")?.checked) {
    showToast("Turn ON FAQ Action Suggestions first");
    return;
  }
  const customerId = document.getElementById("faqActionCustomerId")?.value || "";
  if (!customerId) return showToast("Select a bot first");
  const button = event.currentTarget;
  const actions = Array.from(document.querySelectorAll(".scheduled-faq-action-card")).map(card => ({
    slot_no: Number(card.dataset.slotNo || 0),
    trigger_after_questions: Number(card.querySelector(".scheduledActionAfter")?.value || 0),
    label: card.querySelector(".scheduledActionLabel")?.value.trim() || "",
    action_type: card.querySelector(".scheduledActionType")?.value || "link",
    action_value: card.querySelector(".scheduledActionValue")?.value.trim() || "",
    is_active: !!card.querySelector(".scheduledActionActive")?.checked
  }));
  button.disabled = true;
  button.textContent = "Saving...";
  const response = await fetch("/api.php?action=save_scheduled_faq_actions", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, actions})
  });
  const data = await response.json().catch(() => ({}));
  button.disabled = false;
  button.textContent = "Save schedule";
  if (!data.success) {
    if (data.requires_paid) openTab("subscription");
    return showToast(data.message || "Scheduled FAQ actions could not be saved");
  }
  showToast("Scheduled FAQ actions saved");
});

async function saveDashboardSettings(extraPayload, options = {}) {
  const {silent = false, successMessage = "Settings saved", errorMessage = "Settings could not be saved"} = options;
  const customerId = document.getElementById("settingsCustomerId")?.value || "";
  if (!customerId) {
    if (!silent) showToast("Select a bot first");
    return false;
  }

  let data = {};
  try {
    const response = await fetch("/api.php?action=save_dashboard_settings", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({customer_id: customerId, ...extraPayload})
    });
    data = await response.json().catch(() => ({}));
  } catch (error) {
    data = {success: false};
  }
  if (!data.success) {
    if (!silent) showToast(errorMessage);
    return false;
  }
  if (!silent) showToast(successMessage);
  return true;
}

function setupSettingsPayload() {
  return {
    bot_name: document.getElementById("botNameInput")?.value.trim() || "",
    welcome_message: document.getElementById("welcomeInput")?.value.trim() || "",
    theme_color: document.getElementById("themeColorInput")?.value || "#6366f1",
    theme_pattern: document.getElementById("themePatternInput")?.value || "none",
    avatar_url: document.querySelector("input[name='dashboardBotImage']:checked")?.value || "",
    position: document.getElementById("positionInput")?.value || "right",
    language: document.getElementById("languageInput")?.value || "English",
    chat_open_by_default: !!document.getElementById("chatOpenDefaultToggle")?.checked,
    user_input_enabled: !!document.getElementById("userInputEnabledToggle")?.checked
  };
}

function updateDashboardSetupPreview(payload) {
  const botName = payload.bot_name || "Vani Bot";
  const themeColor = payload.theme_color || "#6366f1";
  const themePattern = payload.theme_pattern || "none";
  const avatarUrl = payload.avatar_url || "";
  const welcomeMessage = payload.welcome_message || "Hi, how can I help you today?";
  document.getElementById("overviewBotNameText")?.replaceChildren(document.createTextNode(botName));
  document.getElementById("sidebarBotNameText")?.replaceChildren(document.createTextNode(botName));
  document.getElementById("overviewThemeTitle")?.replaceChildren(document.createTextNode(botName));
  const deleteButton = document.getElementById("deleteChatbotBtn");
  if (deleteButton) deleteButton.dataset.botName = botName;
  document.getElementById("overviewThemeMessage")?.replaceChildren(document.createTextNode(welcomeMessage));
  const patternCss = typeof patternStyles !== "undefined" ? (patternStyles[themePattern] || "none") : "none";
  ["overviewThemeBubble", "overviewThemeTyping"].forEach(id => {
    const bubble = document.getElementById(id);
    if (!bubble) return;
    bubble.style.background = themeColor;
    bubble.style.backgroundImage = patternCss === "none" ? "" : `${patternCss}, ${themeColor}`;
    bubble.style.backgroundSize = themePattern === "grid" || themePattern === "dots" ? "18px 18px, 18px 18px, cover" : "cover";
  });
  const overviewImage = document.getElementById("overviewBotImagePreview");
  if (overviewImage && avatarUrl) overviewImage.src = avatarUrl;
  const overviewMiniImage = document.getElementById("overviewBotMiniImagePreview");
  if (overviewMiniImage && avatarUrl) overviewMiniImage.src = avatarUrl;
  if (analyticsReport) analyticsReport.bot_name = botName;
}

function updateSetupAutosaveStatus(text, state = "") {
  const status = document.getElementById("setupAutosaveStatus");
  if (!status) return;
  status.textContent = text;
  status.classList.toggle("error", state === "error");
}

async function saveSetupSettingsAutomatically() {
  if (!setupAutosaveReady) return;
  if (setupAutosaveSaving) {
    setupAutosaveQueued = true;
    return;
  }
  setupAutosaveSaving = true;
  updateSetupAutosaveStatus("Saving changes...");
  if (setupAutosaveToastState !== "saving") {
    showToast("Saving changes...");
    setupAutosaveToastState = "saving";
  }
  const payload = setupSettingsPayload();
  const saved = await saveDashboardSettings(payload, {silent: true});
  setupAutosaveSaving = false;
  if (setupAutosaveQueued) {
    setupAutosaveQueued = false;
    scheduleSetupAutosave();
    return;
  }
  if (saved) updateDashboardSetupPreview(payload);
  updateSetupAutosaveStatus(saved ? "All changes saved automatically." : "Could not save changes. Please try again.", saved ? "" : "error");
  showToast(saved ? "Changes saved" : "Changes could not be saved");
  setupAutosaveToastState = saved ? "saved" : "error";
}

function scheduleSetupAutosave() {
  if (!setupAutosaveReady) return;
  clearTimeout(setupAutosaveTimer);
  updateSetupAutosaveStatus("Changes pending...");
  setupAutosaveToastState = "";
  setupAutosaveTimer = setTimeout(saveSetupSettingsAutomatically, 650);
}

function setOverviewActiveUI(isActive) {
  const statusText = document.getElementById("overviewStatusText");
  const statusHelp = document.getElementById("overviewStatusHelp");
  const activeSwitch = document.getElementById("overviewActiveSwitch");
  const activeInput = document.getElementById("activeInput");
  if (statusText) {
    statusText.textContent = isActive ? "Active" : "Inactive";
    statusText.classList.toggle("inactive", !isActive);
  }
  if (statusHelp) {
    statusHelp.textContent = isActive ? "Chatbot is on for customers." : "Chatbot is off for customers.";
  }
  if (activeSwitch) activeSwitch.checked = isActive;
  if (activeInput) activeInput.value = isActive ? "true" : "false";
}

document.getElementById("overviewActiveSwitch")?.addEventListener("change", async event => {
  const isActive = event.target.checked;
  setOverviewActiveUI(isActive);
  const saved = await saveDashboardSettings({is_active: isActive});
  if (!saved) setOverviewActiveUI(!isActive);
});

document.getElementById("deleteChatbotBtn")?.addEventListener("click", async event => {
  const customerId = selectedCustomerId || "";
  if (!customerId) {
    showToast("Select a bot first");
    return;
  }

  const botName = event.currentTarget.dataset.botName || "this chatbot";
  const warning = [
    `Delete ${botName}?`,
    "",
    "This will permanently delete this chatbot and its setup, FAQs, conversations, leads, API keys, and support tickets.",
    "This action cannot be undone."
  ].join("\n");
  if (!confirm(warning)) return;

  const typed = prompt('Type DELETE to permanently delete this chatbot.');
  if (typed !== "DELETE") {
    showToast("Chatbot deletion cancelled");
    return;
  }

  const button = event.currentTarget;
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = "Deleting...";
  const response = await fetch("/api.php?action=delete_chatbot", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, confirm_text: typed})
  });
  const data = await response.json().catch(() => ({}));
  if (!data.success) {
    button.disabled = false;
    button.textContent = originalText;
    showToast(data.message || "Chatbot could not be deleted");
    return;
  }
  showToast("Chatbot deleted");
  setTimeout(() => {
    window.location.href = data.redirect || "dashboard.php";
  }, 700);
});

document.getElementById("transferSubscriptionBtn")?.addEventListener("click", async event => {
  const sourceCustomerId = selectedCustomerId || "";
  const targetCustomerId = document.getElementById("transferSubscriptionTarget")?.value || "";
  if (!sourceCustomerId) return showToast("Select a bot first");
  if (!targetCustomerId) return showToast("Select target chatbot");
  const targetText = document.getElementById("transferSubscriptionTarget")?.selectedOptions?.[0]?.textContent?.trim() || "the selected chatbot";
  const warning = [
    "Transfer wallet plan?",
    "",
    `The current plan and wallet balance will move to ${targetText}.`,
    "This chatbot will move to Free service and paid toggles will be turned off here."
  ].join("\n");
  if (!confirm(warning)) return;

  const button = event.currentTarget;
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = "Transferring...";
  const response = await fetch("/api.php?action=transfer_chatbot_subscription", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({
      source_customer_id: sourceCustomerId,
      target_customer_id: targetCustomerId
    })
  });
  const data = await response.json().catch(() => ({}));
  if (!data.success) {
    button.disabled = false;
    button.textContent = originalText;
    showToast(data.message || "Wallet plan could not be transferred");
    return;
  }
  showToast("Subscription transferred");
  setTimeout(() => {
    window.location.href = `dashboard.php?bot=${encodeURIComponent(targetCustomerId)}#subscription`;
  }, 800);
});

["botNameInput", "welcomeInput"].forEach(id => {
  document.getElementById(id)?.addEventListener("input", scheduleSetupAutosave);
});

["positionInput", "languageInput", "chatOpenDefaultToggle", "userInputEnabledToggle"].forEach(id => {
  document.getElementById(id)?.addEventListener("change", scheduleSetupAutosave);
});

document.getElementById("saveSettingsBtn")?.addEventListener("click", () => {
  const isActive = document.getElementById("activeInput").value === "true";
  saveDashboardSettings({
    api_key: document.getElementById("apiKeyInput").value.trim(),
    rate_limit: Number(document.getElementById("rateLimitInput").value || 100),
    is_active: isActive,
    notification_preference: document.getElementById("notificationInput").value,
    allowed_domains: document.getElementById("domainsInput").value.trim()
  }).then(saved => {
    if (saved) setOverviewActiveUI(isActive);
  });
});

let integrationAutosaveTimer = null;
let integrationAutosaveSaving = false;
let integrationAutosaveQueued = false;

function updateIntegrationAutosaveStatus(text, state = "") {
  const status = document.getElementById("integrationAutosaveStatus");
  if (!status) return;
  status.textContent = text;
  status.classList.toggle("error", state === "error");
}

function integrationSettingsPayload() {
  const websiteVerificationEnabled = !!document.getElementById("websiteVerificationToggle")?.checked;
  const allowedDomainsEnabled = businessFeatures.allowed_domains && !!document.getElementById("allowedDomainsToggle")?.checked;
  const allowedDomains = document.getElementById("allowedDomainsInput")?.value.trim() || "";
  return {
    websiteVerificationEnabled,
    allowedDomainsEnabled,
    allowedDomains,
    payload: {
      website_verification_enabled: websiteVerificationEnabled,
      allowed_domains_enabled: allowedDomainsEnabled,
      allowed_domains: allowedDomains,
      verification_status: websiteVerificationEnabled ? "Pending" : "Disabled"
    }
  };
}

async function saveIntegrationSettingsAutomatically({live = false} = {}) {
  const {websiteVerificationEnabled, allowedDomainsEnabled, allowedDomains, payload} = integrationSettingsPayload();
  if (allowedDomainsEnabled && !allowedDomains) {
    updateIntegrationAutosaveStatus("Add at least one allowed domain to enable domain restriction.", "error");
    showToast("Add at least one allowed domain");
    document.getElementById("allowedDomainsInput")?.focus();
    return;
  }

  if (integrationAutosaveSaving) {
    integrationAutosaveQueued = true;
    return;
  }
  integrationAutosaveSaving = true;
  updateIntegrationAutosaveStatus("Saving integration settings...");
  if (live) showToast("Saving integration settings...");
  const saved = await saveDashboardSettings(payload, {silent: true});
  integrationAutosaveSaving = false;

  if (saved) {
    const statusText = document.getElementById("verificationStatusText");
    if (statusText) statusText.textContent = websiteVerificationEnabled ? "Pending" : "Disabled";
    updateIntegrationAutosaveStatus("Integration settings saved automatically.");
    if (live) showToast("Integration settings saved");
  } else {
    updateIntegrationAutosaveStatus("Could not save integration settings. Please try again.", "error");
    if (live) showToast("Integration settings could not be saved");
  }

  if (integrationAutosaveQueued) {
    integrationAutosaveQueued = false;
    scheduleIntegrationAutosave({live});
  }
}

function scheduleIntegrationAutosave({live = false} = {}) {
  clearTimeout(integrationAutosaveTimer);
  updateIntegrationAutosaveStatus("Integration changes pending...");
  integrationAutosaveTimer = setTimeout(() => saveIntegrationSettingsAutomatically({live}), 600);
}

document.getElementById("websiteVerificationToggle")?.addEventListener("change", () => {
  scheduleIntegrationAutosave({live: true});
});

document.getElementById("allowedDomainsToggle")?.addEventListener("change", event => {
  if (event.currentTarget.checked && !businessFeatures.allowed_domains) {
    event.currentTarget.checked = false;
    alert("Allowed domains requires Business plan");
    openTab("subscription");
    return;
  }
  scheduleIntegrationAutosave({live: true});
});

document.getElementById("allowedDomainsInput")?.addEventListener("input", () => {
  scheduleIntegrationAutosave();
});

function validateHumanHandoffEmail(showMessage = false) {
  const input = document.getElementById("humanHandoffEmailInput");
  const value = input?.value.trim() || "";
  const valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
  if (!valid && showMessage) showToast("Enter a valid support email");
  return valid;
}

async function saveHumanHandoffSettings({live = false} = {}) {
  const toggle = document.getElementById("humanHandoffToggle");
  const emailInput = document.getElementById("humanHandoffEmailInput");
  if (!toggle || !emailInput) return;
  if (toggle.checked && !businessFeatures.human_handoff) {
    toggle.checked = false;
    alert("You need Growth or Business plan to ON this functionality");
    openTab("subscription");
    return;
  }
  if (toggle.checked && !validateHumanHandoffEmail(true)) {
    emailInput.focus();
    return;
  }
  const saved = await saveDashboardSettings({
    handoff_enabled: !!toggle.checked,
    handoff_email: emailInput.value.trim()
  });
  if (saved && live) {
    showToast(toggle.checked ? "Human handoff enabled" : "Human handoff disabled");
  }
}

document.getElementById("humanHandoffToggle")?.addEventListener("change", () => {
  saveHumanHandoffSettings({live: true});
});

document.getElementById("humanHandoffEmailInput")?.addEventListener("blur", () => validateHumanHandoffEmail(false));

document.getElementById("saveHumanHandoffBtn")?.addEventListener("click", () => {
  saveHumanHandoffSettings();
});

document.getElementById("saveWebhookBtn")?.addEventListener("click", async event => {
  if (!businessFeatures.webhook_support) {
    showToast("Webhook support requires an active paid plan");
    return;
  }
  const button = event.currentTarget;
  const webhookUrl = document.getElementById("webhookUrlInput")?.value.trim() || "";
  const webhookSecret = document.getElementById("webhookSecretInput")?.value.trim() || "";
  if (webhookUrl && !/^https:\/\/[^\s]+$/i.test(webhookUrl)) {
    showToast("Webhook URL must start with https://");
    document.getElementById("webhookUrlInput")?.focus();
    return;
  }
  button.disabled = true;
  button.textContent = "Saving...";
  await saveDashboardSettings({
    webhook_url: webhookUrl,
    webhook_secret: webhookSecret
  });
  button.disabled = false;
  button.textContent = "Save webhook";
});

document.getElementById("testWebhookBtn")?.addEventListener("click", async event => {
  if (!businessFeatures.webhook_support) {
    showToast("Webhook support requires an active paid plan");
    return;
  }
  const customerId = document.getElementById("settingsCustomerId")?.value || "";
  if (!customerId) return showToast("Select a bot first");
  const button = event.currentTarget;
  button.disabled = true;
  button.textContent = "Testing...";
  const response = await fetch("/api.php?action=test_webhook", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId})
  });
  const data = await response.json().catch(() => ({}));
  button.disabled = false;
  button.textContent = "Test webhook";
  showToast(data.message || (data.success ? "Webhook delivered" : "Webhook test failed"));
});

async function saveLiveChatActionsSettings({live = false} = {}) {
  const toggle = document.getElementById("liveChatActionsToggle");
  if (!toggle) return;
  if (toggle.checked && !businessFeatures.live_chat_actions) {
    toggle.checked = false;
    alert("Live Chat Actions requires Business plan");
    openTab("subscription");
    return;
  }
  const saved = await saveDashboardSettings({
    live_chat_actions_enabled: businessFeatures.live_chat_actions && !!toggle.checked
  });
  if (saved && live) {
    showToast(toggle.checked ? "Live Chat Actions enabled" : "Live Chat Actions disabled");
  }
}

document.getElementById("liveChatActionsToggle")?.addEventListener("change", () => {
  saveLiveChatActionsSettings({live: true});
});

document.getElementById("saveLiveChatActionsBtn")?.addEventListener("click", () => {
  saveLiveChatActionsSettings();
});

function apiKeyRowsHtml(keys) {
  if (!keys.length) {
    return `<tr><td colspan="6" class="empty">No API keys created yet.</td></tr>`;
  }
  return keys.map(key => {
    const revoked = !!key.revoked_at;
    return `<tr data-api-key-id="${htmlEscape(key.id || "")}">
      <td>${htmlEscape(key.name || "API key")}</td>
      <td><code class="api-key-code">${htmlEscape((key.key_prefix || "") + "...")}</code></td>
      <td>${htmlEscape(key.rate_limit_per_day || "")}/day</td>
      <td>${htmlEscape(key.last_used_at || "Never")}</td>
      <td><span class="tag ${revoked ? "bad" : "good"}"><span class="status-dot ${revoked ? "off" : ""}"></span>${revoked ? "Revoked" : "Active"}</span></td>
      <td>${revoked ? `<span class="muted">No action</span>` : `<button class="danger-btn revoke-api-key-btn" type="button">Revoke</button>`}</td>
    </tr>`;
  }).join("");
}

function renderApiKeys(keys) {
  const body = document.getElementById("apiKeysTableBody");
  if (!body) return;
  body.innerHTML = apiKeyRowsHtml(keys || []);
}

async function refreshApiKeys() {
  const customerId = document.getElementById("settingsCustomerId")?.value || "";
  if (!customerId) return;
  const response = await fetch(`/api.php?action=list_customer_api_keys&customer_id=${encodeURIComponent(customerId)}`);
  const data = await response.json().catch(() => ({}));
  if (data.success) renderApiKeys(data.keys || []);
}

document.getElementById("createApiKeyBtn")?.addEventListener("click", async event => {
  if (!businessFeatures.api_access) {
    showToast("API access requires Business plan");
    return;
  }
  const customerId = document.getElementById("settingsCustomerId")?.value || "";
  if (!customerId) return showToast("Select a bot first");
  const button = event.currentTarget;
  button.disabled = true;
  button.textContent = "Creating...";
  const payload = {
    customer_id: customerId,
    name: document.getElementById("apiKeyNameInput")?.value.trim() || "API key",
    rate_limit_per_day: Number(document.getElementById("apiKeyRateLimitInput")?.value || 1000),
    allowed_ips: document.getElementById("apiKeyAllowedIpsInput")?.value.trim() || "",
    allowed_origins: document.getElementById("apiKeyAllowedOriginsInput")?.value.trim() || ""
  };
  const response = await fetch("/api.php?action=create_customer_api_key", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify(payload)
  });
  const data = await response.json().catch(() => ({}));
  button.disabled = false;
  button.textContent = "Create API key";
  if (!data.success) {
    showToast(data.message || "API key could not be created");
    return;
  }
  const reveal = document.getElementById("newApiKeyReveal");
  const code = document.getElementById("newApiKeyCode");
  const copyBtn = document.getElementById("copyNewApiKeyBtn");
  if (reveal && code && copyBtn) {
    reveal.classList.add("active");
    code.textContent = data.api_key || "";
    copyBtn.dataset.copy = data.api_key || "";
  }
  renderApiKeys(data.keys || []);
  showToast("API key created");
});

document.getElementById("apiKeysTableBody")?.addEventListener("click", async event => {
  const button = event.target.closest(".revoke-api-key-btn");
  if (!button) return;
  const row = button.closest("tr");
  const keyId = row?.dataset.apiKeyId || "";
  const customerId = document.getElementById("settingsCustomerId")?.value || "";
  if (!keyId || !customerId) return;
  if (!confirm("Revoke this API key? Existing integrations using it will stop working.")) return;
  button.disabled = true;
  button.textContent = "Revoking...";
  const response = await fetch("/api.php?action=revoke_customer_api_key", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, key_id: keyId})
  });
  const data = await response.json().catch(() => ({}));
  if (!data.success) {
    button.disabled = false;
    button.textContent = "Revoke";
    showToast(data.message || "API key could not be revoked");
    return;
  }
  renderApiKeys(data.keys || []);
  showToast("API key revoked");
});

function updateProfileAvatarPreview(value) {
  const preview = document.getElementById("profileAvatarPreview");
  const firstName = document.getElementById("firstNameInput")?.value.trim() || "";
  const fallback = (firstName || document.getElementById("profileEmailInput")?.value || "V").charAt(0).toUpperCase();
  preview.textContent = "";
  if (value && value.startsWith("http")) {
    const img = document.createElement("img");
    img.src = value;
    img.alt = "Profile avatar";
    preview.appendChild(img);
  } else {
    preview.textContent = value || fallback;
  }
}

document.getElementById("profileAvatarInput")?.addEventListener("input", event => {
  updateProfileAvatarPreview(event.target.value.trim());
});

document.getElementById("generateAvatarBtn")?.addEventListener("click", () => {
  const firstName = document.getElementById("firstNameInput").value.trim();
  const lastName = document.getElementById("lastNameInput").value.trim();
  const email = document.getElementById("profileEmailInput").value.trim();
  const initials = ((firstName.charAt(0) || email.charAt(0) || "V") + (lastName.charAt(0) || "")).toUpperCase();
  document.getElementById("profileAvatarInput").value = initials;
  updateProfileAvatarPreview(initials);
});

document.getElementById("saveProfileBtn")?.addEventListener("click", async () => {
  const response = await saveCustomerProfileFromMainForm();
  if (!response.success) {
    showToast(response.message || "Profile could not be saved");
    return;
  }
  showToast("Profile saved");
});

async function saveCustomerProfileFromMainForm() {
  const response = await fetch("/api.php?action=save_customer_profile", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({
      email: document.getElementById("profileEmailInput").value.trim(),
      first_name: document.getElementById("firstNameInput").value.trim(),
      last_name: document.getElementById("lastNameInput").value.trim(),
      avatar_url: document.getElementById("profileAvatarInput").value.trim(),
      country_code: document.getElementById("countryCodeInput").value.trim(),
      mobile_number: document.getElementById("mobileInput").value.trim(),
      address_line1: document.getElementById("address1Input").value.trim(),
      address_line2: document.getElementById("address2Input").value.trim(),
      city: document.getElementById("cityInput").value.trim(),
      state_region: document.getElementById("stateInput").value.trim(),
      country: document.getElementById("countryInput").value.trim(),
      postal_code: document.getElementById("postalInput").value.trim(),
      location_notes: document.getElementById("locationInput").value.trim()
    })
  });

  const data = await response.json().catch(() => ({}));
  return data;
}

function setProfilePromptOpen(open) {
  const prompt = document.getElementById("profileSetupPrompt");
  if (!prompt) return;
  prompt.classList.toggle("active", open);
  prompt.setAttribute("aria-hidden", open ? "false" : "true");
}

function dismissProfilePrompt() {
  try { localStorage.setItem(profilePromptKey, "1"); } catch (error) {}
  setProfilePromptOpen(false);
  showToast("Please complete your profile from the Profile tab when ready.");
}

function copyPromptProfileToMainForm() {
  const pairs = [
    ["promptFirstNameInput", "firstNameInput"],
    ["promptLastNameInput", "lastNameInput"],
    ["promptCountryCodeInput", "countryCodeInput"],
    ["promptMobileInput", "mobileInput"],
    ["promptCityInput", "cityInput"],
    ["promptCountryInput", "countryInput"]
  ];
  pairs.forEach(([fromId, toId]) => {
    const from = document.getElementById(fromId);
    const to = document.getElementById(toId);
    if (from && to) to.value = from.value.trim();
  });
}

document.getElementById("closeProfilePromptBtn")?.addEventListener("click", dismissProfilePrompt);
document.getElementById("profilePromptLaterBtn")?.addEventListener("click", dismissProfilePrompt);
document.getElementById("saveProfilePromptBtn")?.addEventListener("click", async event => {
  const button = event.currentTarget;
  const firstName = document.getElementById("promptFirstNameInput")?.value.trim() || "";
  const mobile = document.getElementById("promptMobileInput")?.value.trim() || "";
  if (firstName.length < 2) return showToast("Enter your first name");
  if (!/^\d{7,15}$/.test(mobile.replace(/\D/g, ""))) return showToast("Enter a valid mobile number");
  copyPromptProfileToMainForm();
  button.disabled = true;
  button.textContent = "Saving...";
  const data = await saveCustomerProfileFromMainForm();
  button.disabled = false;
  button.textContent = "Save basic profile";
  if (!data.success) return showToast(data.message || "Profile could not be saved");
  try { localStorage.removeItem(profilePromptKey); } catch (error) {}
  setProfilePromptOpen(false);
  showToast("Profile saved");
});

if (profileNeedsSetup) {
  let dismissed = false;
  try { dismissed = localStorage.getItem(profilePromptKey) === "1"; } catch (error) {}
  if (!dismissed) {
    window.addEventListener("load", () => setTimeout(() => setProfilePromptOpen(true), 450));
  }
}

const hash = location.hash.replace("#", "");
if (hash && !hash.includes("/") && document.getElementById(hash)) openTab(hash);
</script>
</body>
</html>
