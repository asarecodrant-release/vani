<?php
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/billing.php';

if (!is_authenticated_user()) {
    header("Location: login.php");
    exit;
}

$email = authenticated_email();
$accountId = authenticated_user_id();
$selectedBotId = trim($_GET['bot'] ?? '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$widgetUrl = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/widget.js';
$customerApiBaseUrl = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/api.php?action=';
$customerApiUrl = $customerApiBaseUrl . 'customer_api_ping';
$botImages = glob(__DIR__ . '/images/botimg_*') ?: [];
$botImages = array_values(array_filter($botImages, 'is_file'));
natcasesort($botImages);
$botImages = array_map(fn($path) => 'images/' . basename($path), $botImages);

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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
            "webhook_url" => null,
            "webhook_secret" => null
        ]);
    }
}

function dashboard_billing_account_has_value(array $account): bool {
    return (string)($account['current_plan'] ?? 'free') !== 'free'
        || (string)($account['subscription_status'] ?? 'free') !== 'free'
        || (int)($account['wallet_balance_paise'] ?? 0) > 0
        || trim((string)($account['saved_payment_method_reference'] ?? '')) !== ''
        || trim((string)($account['saved_payment_method_customer_id'] ?? '')) !== '';
}

function dashboard_adopt_legacy_billing_account(string $customerId, string $email, array $customerAccount = []): array {
    if ($customerId === '' || $email === '') {
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

function date_in_range(array $row, string $field, string $from, string $to): bool {
    $date = substr((string)($row[$field] ?? ''), 0, 10);
    if ($date === '') {
        return false;
    }
    return $date >= $from && $date <= $to;
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

$bots = safe_data(supabase(
    "GET",
    "chatbot_signups?select=*&email=eq." . urlencode($email) . "&order=created_at.desc"
));

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

if (empty($selectedBot) && $selectedBotId) {
    $fallbackBot = safe_data(supabase(
        "GET",
        "chatbot_signups?select=*&customer_id=eq." . urlencode($selectedBotId) . "&limit=1"
    ));
    $selectedBot = $fallbackBot[0] ?? [];
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
        "lead_generation_leads?select=*&customer_id=eq." . urlencode($selectedBotId) . "&order=created_at.desc&limit=100"
    ))
    : [];

$profileRows = safe_data(supabase(
    "GET",
    "customer_profiles?select=*&email=eq." . urlencode($email) . "&limit=1"
));

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
}

$walletTransactionRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "wallet_transactions?select=*&customer_id=eq." . urlencode($selectedBotId) . "&order=created_at.desc&limit=100"
    ))
    : [];

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

$todayAllQueries = count(array_filter($conversationRows, fn($row) => date_in_range($row, 'created_at', $analyticsToday, $analyticsToday)));
$yesterdayAllQueries = count(array_filter($conversationRows, fn($row) => date_in_range($row, 'created_at', $analyticsYesterday, $analyticsYesterday)));
$last7AllQueries = count(array_filter($conversationRows, fn($row) => date_in_range($row, 'created_at', gmdate('Y-m-d', time() - (6 * 86400)), $analyticsToday)));
$last30AllQueries = count(array_filter($conversationRows, fn($row) => date_in_range($row, 'created_at', gmdate('Y-m-d', time() - (29 * 86400)), $analyticsToday)));

$conversationRows = array_values(array_filter($conversationRows, fn($row) => date_in_range($row, 'created_at', $analyticsFrom, $analyticsTo)));
$usageRows = array_values(array_filter($usageRows, fn($row) => date_in_range($row, 'created_at', $analyticsFrom, $analyticsTo)));
$leadRows = array_values(array_filter($leadRows, fn($row) => date_in_range($row, 'created_at', $analyticsFrom, $analyticsTo)));
$sessionRows = array_values(array_filter($sessionRows, fn($row) => date_in_range($row, 'created_at', $analyticsFrom, $analyticsTo)));

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
$autoRechargeRule = billing_auto_recharge_rule($activePlanId);
$autoRechargeThresholdPaise = (int)($billingAccount['auto_recharge_threshold_paise'] ?? 0) ?: (int)$autoRechargeRule['threshold_paise'];
$autoRechargeAmountPaise = (int)($billingAccount['auto_recharge_amount_paise'] ?? 0) ?: (int)$autoRechargeRule['amount_paise'];
$autoRechargeEnabled = filter_var($billingAccount['auto_recharge_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$savedPaymentMethodStatus = (string)($billingAccount['saved_payment_method_status'] ?? 'missing');
$savedPaymentCustomerId = (string)($billingAccount['saved_payment_method_customer_id'] ?? '');
$savedPaymentContact = (string)($billingAccount['saved_payment_method_contact'] ?? '');
$savedPaymentMethodReference = (string)($billingAccount['saved_payment_method_reference'] ?? '');
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
$dailyChartCounts = $dailyCounts;
ksort($dailyChartCounts);
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
$chatbotImage = first_value($settings, ['avatar_url'], $botImages[0] ?? '');
$botName = first_value($settings, ['bot_name'], first_value($selectedBot, ['website_name'], 'Vani Bot'));
$welcomeMessage = first_value($settings, ['welcome_message'], 'Hi, how can I help you today?');
$position = first_value($settings, ['position'], 'right');
$language = first_value($settings, ['language'], 'English');
$rawActive = $settings['is_active'] ?? true;
$isActive = is_bool($rawActive) ? $rawActive : ((string)$rawActive !== 'false');
$websiteVerificationEnabled = filter_var($settings['website_verification_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$allowedDomainsEnabled = filter_var($settings['allowed_domains_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$allowedDomains = first_value($settings, ['allowed_domains'], '');
$handoffEnabled = filter_var($settings['handoff_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$handoffEmail = first_value($settings, ['handoff_email'], $email);
$verificationStatus = first_value($settings, ['verification_status'], 'Pending');
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
$razorpayCustomerName = $displayName ?: $email;
$profileContactValue = preg_replace('/[^\d+]/', '', (string)($profile['country_code'] ?? '+91') . (string)($profile['mobile_number'] ?? ''));
if ($profileContactValue !== '' && $profileContactValue[0] !== '+') {
    $profileContactValue = '+91' . ltrim($profileContactValue, '0');
}
$razorpayCustomerContact = $savedPaymentContact ?: $profileContactValue;
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
  background:linear-gradient(135deg,#0f172a,#1e1b4b,#3b0764);
}
a{text-decoration:none;color:inherit}
button,input,select,textarea{font:inherit}
button{touch-action:manipulation}
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
.user-menu{display:flex;align-items:center;gap:10px;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:7px 10px}
.avatar{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:700;background:linear-gradient(135deg,var(--brand),var(--brand-2))}
.user-text{max-width:180px;min-width:0}
.user-text strong,.user-text span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.user-text strong{font-size:13px}.user-text span{font-size:12px;color:var(--muted)}
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
.bot-picker{display:grid;gap:10px;padding:18px;border-radius:18px;background:rgba(255,255,255,.58);border:1px solid var(--line)}
body.dark .bot-picker{background:rgba(15,23,42,.56)}
.bot-picker label,.field label{font-size:13px;font-weight:700;color:var(--muted)}
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
.quick-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.action-card{padding:18px;display:grid;gap:10px;align-content:start}
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
.field{display:grid;gap:8px;min-width:0}
.field.full{grid-column:1/-1}
.panel-actions{grid-column:1/-1;display:flex;justify-content:flex-end;gap:10px;min-width:0;padding-top:4px}
.section-body > .panel-actions{padding-top:16px}
.swatches{display:flex;gap:10px;flex-wrap:wrap}
.swatch{width:34px;height:34px;border-radius:10px;border:2px solid rgba(255,255,255,.8);box-shadow:0 4px 10px rgba(15,23,42,.12);cursor:pointer}
.bot-image-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(72px,1fr));gap:10px}
.bot-image-option{border:1px solid var(--line);background:var(--panel-strong);border-radius:14px;padding:8px;cursor:pointer;display:grid;place-items:center}
.bot-image-option img{width:100%;aspect-ratio:1;object-fit:contain}
.bot-image-option input{position:absolute;opacity:0;pointer-events:none}
.bot-image-option:has(input:checked){border-color:rgba(99,102,241,.72);box-shadow:0 0 0 3px rgba(99,102,241,.14)}
.selected-bot-image{width:64px;height:64px;object-fit:contain;border-radius:16px;border:1px solid var(--line);background:var(--panel-strong);padding:8px}
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
td .ghost-btn{white-space:normal}
.tag{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800;background:rgba(99,102,241,.12);color:var(--brand)}
.tag.good{background:rgba(34,197,94,.13);color:#15803d}.tag.bad{background:rgba(239,68,68,.12);color:#b91c1c}
.embed-box{position:relative}
code{display:block;white-space:pre-wrap;word-break:break-all;padding:16px;border-radius:14px;background:#111827;color:#e5e7eb;font-size:13px;line-height:1.6}
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
.api-key-reveal{display:none;margin-top:10px}
.api-key-reveal.active{display:block}
.api-key-code{font-size:12px}
.status-dot{width:9px;height:9px;border-radius:50%;display:inline-block;background:#22c55e;margin-right:7px}
.status-dot.off{background:#ef4444}
.critical-save-note{margin-top:14px;padding:13px 15px;border-radius:12px;border:1px solid rgba(220,38,38,.35);background:rgba(254,226,226,.75);color:#b91c1c;font-size:17px;font-weight:800;line-height:1.45}
body.dark .critical-save-note{background:rgba(127,29,29,.22);border-color:rgba(248,113,113,.38);color:#fecaca}
.analytics-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.analytics-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.filter-chip{border:1px solid var(--line);background:var(--panel-strong);color:var(--ink);border-radius:999px;padding:8px 12px;font-size:13px;font-weight:700;text-decoration:none}
.filter-chip.active{background:linear-gradient(135deg,var(--brand),var(--brand-2));border-color:transparent;color:#fff}
.analytics-filter-form{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-top:16px}
.analytics-filter-form .field{min-width:150px}
.analytics-filter-form .pill-btn{min-height:42px}
.analytics-tabs{display:flex;gap:8px;flex-wrap:wrap}
.analytics-tab-btn{border:1px solid var(--line);background:var(--panel-strong);color:var(--ink);border-radius:999px;padding:9px 13px;font-size:13px;font-weight:800;cursor:pointer}
.analytics-tab-btn.active{background:linear-gradient(135deg,var(--brand),var(--brand-2));border-color:transparent;color:#fff}
.analytics-subpanel{display:none;gap:18px}
.analytics-subpanel.active{display:grid}
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
.pricing-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:16px;margin-top:18px;align-items:stretch}
.pricing-card{grid-column:span 2;padding:16px;display:grid;gap:12px;align-content:start}
.pricing-card.featured{grid-column:span 2;padding:22px;border-color:rgba(34,197,94,.55);box-shadow:0 18px 42px rgba(34,197,94,.16);transform:scale(1.02);z-index:1}
.pricing-card.current-plan{border-color:rgba(99,102,241,.7);box-shadow:0 14px 34px rgba(99,102,241,.16)}
.current-plan-note{padding:9px 11px;border-radius:10px;background:rgba(99,102,241,.12);color:#4f46e5;font-size:13px;font-weight:800}
.pricing-card.featured .price{font-size:36px}
.pricing-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.price{font-size:30px;font-weight:800}
.price small{font-size:13px;color:var(--muted);font-weight:700}
.feature-list{display:grid;gap:8px;color:var(--ink);font-size:14px}
.feature-list span:before{content:"";display:inline-block;width:7px;height:7px;border-radius:50%;background:#22c55e;margin-right:8px}
.wallet-table{min-width:0}
.wallet-table table{min-width:0}
.wallet-table th,.wallet-table td{padding:9px 0;font-size:13px}
.wallet-table th:last-child,.wallet-table td:last-child{text-align:right}
.outside-faq-list{display:grid;gap:14px}
.outside-faq-card{padding:16px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.42);display:grid;gap:14px}
body.dark .outside-faq-card{background:rgba(15,23,42,.44)}
.outside-faq-meta{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.outside-faq-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.outside-faq-grid .field.full{grid-column:1/-1}
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
  body.dark .top-actions{background:rgba(15,23,42,.92)}
  body.account-open .top-actions{transform:translateX(0);visibility:visible;pointer-events:auto}
  .top-actions .pill-btn,.top-actions .ghost-btn{width:100%;justify-content:center}
  .top-actions > .user-menu{display:grid;justify-items:center;text-align:center;padding:16px}
  .top-actions .user-text{display:block;max-width:100%}
  .top-actions .user-text strong,.top-actions .user-text span{white-space:normal;word-break:break-word}
  .topbar-left{flex:1}
  .page-title{min-width:0}
  .page-title p{display:none}
  .metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
  .overview-hero,.split,.profile-grid{grid-template-columns:1fr}
  .profile-photo{justify-items:start;grid-template-columns:auto 1fr;align-items:center}
}
@media(max-width:720px){
  .topbar{padding:12px 14px}
  .mobile-toggle{width:40px;height:40px}
  body.account-open #accountToggle{top:12px;right:14px}
  .content{padding:14px;gap:16px}
  .panel{border-radius:18px}
  .section-head{align-items:flex-start;flex-direction:column;padding:16px 16px 0}
  .section-body{padding:16px}
  .overview-hero h2{font-size:28px}
  .metrics,.quick-actions,.form-grid,.outside-faq-grid,.lead-grid,.analytics-grid,.analytics-grid.two,.funnel,.pricing-grid,.security-grid{grid-template-columns:1fr}
  .panel-actions{justify-content:stretch}
  .panel-actions .pill-btn,.panel-actions .ghost-btn,.panel-actions .danger-btn{width:100%}
  .user-menu{justify-content:space-between}
  select,input,textarea{font-size:16px}
  table{min-width:640px}
  th,td{padding:11px 12px}
  .table-wrap{width:100%;max-width:100%;border-radius:0}
  .inline-row input,.inline-row .ghost-btn{flex:1 1 100%;width:100%}
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
  code{font-size:12px;padding:13px}
  table{min-width:560px}
  th{font-size:11px}
  td{font-size:13px}
  .inline-row{display:grid;grid-template-columns:1fr}
  .lead-master,.lead-section-head,.lead-option-top{align-items:flex-start}
  .lead-master{display:grid}
  .toast{left:14px;right:14px;bottom:14px;text-align:center}
}
</style>
</head>
<body>
<div class="dashboard-shell">
  <div class="drawer-overlay" id="drawerOverlay" aria-hidden="true"></div>
  <aside class="sidebar">
    <a class="brand" href="index.php">
      <img src="images/logo_img.png" alt="Vani AI">
      <strong>VANI AI</strong>
    </a>
    <div class="nav-tabs" role="tablist">
      <button class="tab-btn active" data-tab="overview">Dashboard</button>
      <button class="tab-btn" data-tab="setup">Chatbot Setup</button>
      <button class="tab-btn" data-tab="faqs">FAQ Management</button>
      <button class="tab-btn" data-tab="outside-faqs">Outside FAQs</button>
      <!-- Conversations tab hidden for now; keep this code for later.
      <button class="tab-btn" data-tab="logs">Conversations</button>
      -->
      <button class="tab-btn" data-tab="analytics">Analytics</button>
      <button class="tab-btn" data-tab="install">Integration</button>
      <!-- Bot Settings tab hidden for now; keep this code for later.
      <button class="tab-btn" data-tab="bot-settings">Bot Settings</button>
      -->
      <button class="tab-btn" data-tab="lead-generation">Lead Generation Setup</button>
      <button class="tab-btn" data-tab="subscription">Subscription</button>
      <button class="tab-btn" data-tab="profile">Profile</button>
      <button class="tab-btn" data-tab="billing">Billing</button>
      <a class="tab-btn" href="test-chatbot.php?bot=<?php echo h(urlencode($selectedBotId)); ?>">Test Chatbot</a>
    </div>
    <div class="sidebar-footer">
      <small>Current bot</small>
      <strong><?php echo h($botName); ?></strong>
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
          <h1>Chatbot Dashboard</h1>
          <!--<p>Overview, setup, FAQs, logs, analytics, install, settings, and billing.</p>-->
        </div>
      </div>
      <button class="mobile-toggle" id="accountToggle" type="button" aria-label="Open account menu" aria-expanded="false">⋯</button>
      <div class="top-actions">
        <button class="ghost-btn" id="themeToggle" type="button">Dark</button>
        <a class="ghost-btn" href="index.php">Home</a>
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
        <div class="panel overview-hero">
          <div>
            <span class="eyebrow">Your Chatbot</span>
            <h2><?php echo h($botName); ?></h2>
            <p>You are currently configuring the bot for the mentioned website.</p>
          </div>
          <form class="bot-picker" method="get" action="dashboard.php">
            <label for="bot">Select Website bot</label>
            <select id="bot" name="bot" onchange="this.form.submit()">
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
            <span>Chatbot Status</span>
            <strong id="overviewStatusText" class="status-dot <?php echo $isActive ? '' : 'inactive'; ?>"><?php echo $isActive ? 'Active' : 'Inactive'; ?></strong>
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
            <span>Theme Color</span>
            <strong style="color:<?php echo h($themeColor); ?>"><?php echo h($themeColor); ?></strong>
            <?php if ($chatbotImage): ?><img class="selected-bot-image" style="margin-top:10px" src="<?php echo h($chatbotImage); ?>" alt="Selected chatbot image"><?php endif; ?>
            <small>Used by the chatbot box.</small>
          </div>
        </div>

        <div class="split">
          <div class="panel section-body">
            <h3>Popular Questions</h3>
            <p class="muted" style="margin:10px 0 14px">Trending questions customers asked that matched your FAQs.</p>
            <?php if (empty($topFaqQuestionCounts)): ?><p class="empty">No repeated FAQ questions yet.</p><?php endif; ?>
            <?php foreach (array_slice($topFaqQuestionCounts, 0, 5) as $item): ?>
              <div class="inline-row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:10px 0"><span><?php echo h($item['question']); ?></span><strong><?php echo h($item['count']); ?></strong></div>
            <?php endforeach; ?>
          </div>
          <button class="panel metric metric-link" type="button" data-jump="outside-faqs">
            <h3>Questions Outside FAQs</h3>
            <strong><?php echo h($unansweredCount); ?></strong>
            <small>Questions the bot could not answer. Open this list to edit and add answers to FAQs.</small>
          </button>
        </div>

        <div class="quick-actions">
          <div class="panel action-card">
            <h3>Add FAQ</h3>
            <p>Add a new question and answer to improve bot responses.</p>
            <button class="pill-btn" type="button" data-jump="faqs">Add FAQ</button>
          </div>
          <div class="panel action-card">
            <h3>Copy embed script</h3>
            <p>Install this bot on your website with one script tag.</p>
            <button class="pill-btn copy-btn" type="button" data-copy="<?php echo h($embedCode); ?>">Copy script</button>
          </div>
          <!-- Settings shortcut hidden while Bot Settings tab is hidden; keep this code for later.
          <div class="panel action-card">
            <h3>Settings</h3>
            <p>Change status, domains, notifications, and data controls.</p>
            <button class="pill-btn" type="button" data-jump="bot-settings">Open settings</button>
          </div>
          -->
        </div>
      </section>

      <section class="tab-panel" id="setup">
        <div class="panel">
          <div class="section-head"><h3>Chatbot Setup</h3></div>
          <div class="section-body form-grid">
            <input type="hidden" id="settingsCustomerId" value="<?php echo h($selectedBotId); ?>">
            <div class="field"><label>Bot Name</label><input id="botNameInput" value="<?php echo h($botName); ?>"></div>
            <div class="field"><label>Position</label><select id="positionInput"><option <?php echo $position === 'right' ? 'selected' : ''; ?>>right</option><option <?php echo $position === 'left' ? 'selected' : ''; ?>>left</option></select></div>
            <div class="field full"><label>Welcome Message</label><textarea id="welcomeInput"><?php echo h($welcomeMessage); ?></textarea></div>
            <div class="field"><label>Theme color</label><input id="themeColorInput" type="color" value="<?php echo h($themeColor); ?>"></div>
            <div class="field"><label>Language</label><select id="languageInput"><option><?php echo h($language); ?></option><option>English</option><option>Hindi</option><option>Spanish</option><option>French</option></select></div>
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
            <div class="field full">
              <label>Quick colors</label>
              <div class="swatches">
                <button class="swatch" style="background:#6366f1" type="button" title="Indigo"></button>
                <button class="swatch" style="background:#06b6d4" type="button" title="Cyan"></button>
                <button class="swatch" style="background:#10b981" type="button" title="Green"></button>
                <button class="swatch" style="background:#ec4899" type="button" title="Pink"></button>
                <button class="swatch" style="background:#f59e0b" type="button" title="Amber"></button>
              </div>
            </div>
            <div class="panel-actions"><button class="pill-btn" type="button" id="saveSetupBtn">Save setup</button></div>
          </div>
        </div>
      </section>

      <section class="tab-panel" id="faqs">
        <div class="panel">
          <div class="section-head"><h3>FAQ Management</h3><span class="tag"><?php echo h($faqCount); ?>/<?php echo h($freeFaqLimit); ?> FAQs</span></div>
          <div class="section-body">
            <?php if ($faqFreezeActive): ?>
              <div class="notice" style="margin-bottom:16px">
                <strong><?php echo h($activePlan['name']); ?> FAQ limit active:</strong><br>
                Your first <?php echo h($displayFaqLimit); ?> FAQs are active. <?php echo h($frozenFaqCount); ?> extra FAQs are frozen and saved here. Starter unfreezes 100, Growth unfreezes 300, and Business unfreezes all FAQs.
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
          <div class="section-head" style="padding:0">
            <div>
              <span class="eyebrow">Analytics</span>
              <h3 style="margin-top:8px">Performance Dashboard</h3>
              <p class="muted" style="margin-top:6px">Showing <?php echo h($analyticsRangeLabel); ?>: <?php echo h($analyticsFrom); ?> to <?php echo h($analyticsTo); ?></p>
            </div>
            <div class="filter-bar">
              <a class="filter-chip <?php echo $analyticsRange === 'today' ? 'active' : ''; ?>" href="<?php echo h(analytics_url('today', $selectedBotId)); ?>">Today: <?php echo h($todayAllQueries); ?></a>
              <a class="filter-chip <?php echo $analyticsRange === 'yesterday' ? 'active' : ''; ?>" href="<?php echo h(analytics_url('yesterday', $selectedBotId)); ?>">Yesterday: <?php echo h($yesterdayAllQueries); ?></a>
              <a class="filter-chip <?php echo $analyticsRange === '7_days' ? 'active' : ''; ?>" href="<?php echo h(analytics_url('7_days', $selectedBotId)); ?>">7 days: <?php echo h($last7AllQueries); ?></a>
              <a class="filter-chip <?php echo $analyticsRange === '30_days' ? 'active' : ''; ?>" href="<?php echo h(analytics_url('30_days', $selectedBotId)); ?>">30 days: <?php echo h($last30AllQueries); ?></a>
              <a class="filter-chip <?php echo $analyticsRange === 'custom' ? 'active' : ''; ?>" href="<?php echo h(analytics_url('custom', $selectedBotId, $analyticsFrom, $analyticsTo)); ?>">Custom range</a>
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
            <button class="pill-btn" type="submit">Apply</button>
          </form>
        </div>

        <div class="panel section-body">
          <div class="analytics-tabs" role="tablist" aria-label="Analytics sections">
            <button class="analytics-tab-btn active" type="button" data-analytics-tab="analytics-overview" <?php echo $canUsePartialAnalytics ? '' : 'data-premium-lock="Growth subscription required"'; ?>>Overview</button>
            <button class="analytics-tab-btn" type="button" data-analytics-tab="analytics-conversations" <?php echo $canUsePartialAnalytics ? '' : 'data-premium-lock="Growth subscription required"'; ?>>Conversations</button>
            <button class="analytics-tab-btn" type="button" data-analytics-tab="analytics-faq" <?php echo $canUsePartialAnalytics ? '' : 'data-premium-lock="Growth subscription required"'; ?>>FAQ Insights</button>
            <button class="analytics-tab-btn" type="button" data-analytics-tab="analytics-leads" <?php echo $canUsePartialAnalytics ? '' : 'data-premium-lock="Growth subscription required"'; ?>>Leads</button>
            <button class="analytics-tab-btn" type="button" data-analytics-tab="analytics-pages" <?php echo $canUseAdvancedAnalytics ? '' : 'data-premium-lock="Business subscription required"'; ?>>Pages</button>
            <button class="analytics-tab-btn" type="button" data-analytics-tab="analytics-realtime" <?php echo $canUseAdvancedAnalytics ? '' : 'data-premium-lock="Business subscription required"'; ?>>Real-Time</button>
            <button class="analytics-tab-btn" type="button" data-analytics-tab="analytics-reports" <?php echo $canExportReports ? '' : 'data-premium-lock="Business subscription required"'; ?>>Reports</button>
          </div>
        </div>

        <?php if (!$canUsePartialAnalytics): ?>
        <div class="panel section-body">
          <div class="notice"><strong>Growth subscription required:</strong><br>Analytics access starts on Growth. Upgrade to view Overview, Conversations, FAQ Insights, and Leads.</div>
        </div>
        <?php else: ?>
        <div class="analytics-subpanel active" id="analytics-overview">
        <div class="metrics">
          <div class="panel metric"><span>Total Conversations</span><strong><?php echo h($conversationCount); ?></strong><small>Tracked chat sessions/queries.</small></div>
          <div class="panel metric"><span>Total Messages</span><strong><?php echo h($totalMessages); ?></strong><small>User messages currently tracked.</small></div>
          <div class="panel metric"><span>Unique Visitors</span><strong><?php echo h($uniqueVisitorCount); ?></strong><small>Based on widget user IDs.</small></div>
          <div class="panel metric"><span>Answered Queries</span><strong><?php echo h($accuracy); ?>%</strong><small><?php echo h($answeredCount); ?> answered.</small></div>
          <div class="panel metric"><span>Unanswered Queries</span><strong><?php echo h($unansweredPercent); ?>%</strong><small><?php echo h($unansweredCount); ?> need FAQ improvement.</small></div>
          <div class="panel metric"><span>Avg Response Time</span><strong><?php echo $avgResponseTimeMs ? h($avgResponseTimeMs) . 'ms' : 'No data'; ?></strong><small>Measured by the widget API.</small></div>
          <div class="panel metric"><span>Leads Collected</span><strong><?php echo h($leadCount); ?></strong><small><?php echo h($leadConversionRate); ?>% conversion from conversations.</small></div>
          <div class="panel metric"><span>OTP Verified Leads</span><strong><?php echo h($verifiedLeadCount); ?></strong><small><?php echo h($otpVerifiedLeadPercent); ?>% of collected leads.</small></div>
          <div class="panel metric"><span>Active Chatbots</span><strong><?php echo h($activeChatbotCount); ?></strong><small>Selected bot status.</small></div>
          <div class="panel metric"><span>Most Active Page</span><strong style="font-size:18px"><?php echo h($mostActivePage); ?></strong><small>Highest tracked conversation source.</small></div>
          <div class="panel metric"><span>Returning Users</span><strong><?php echo h($returningUsersPercent); ?>%</strong><small><?php echo h($returningVisitorCount); ?> visitors returned.</small></div>
          <div class="panel metric"><span>Avg Conversation Duration</span><strong><?php echo h($avgConversationDuration); ?></strong><small>Based on widget session duration.</small></div>
        </div>
        </div>

        <div class="analytics-subpanel" id="analytics-conversations">
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
          <div class="panel section-body">
            <h3>Country / City Analytics</h3>
            <div class="mini-chart">
              <?php if (empty($countryCounts) && empty($cityCounts)): ?><p class="empty">No location data yet. Country is estimated from browser locale; city needs geolocation or IP lookup later.</p><?php endif; ?>
              <?php foreach (array_slice($countryCounts, 0, 4, true) as $country => $count): ?>
                <div class="bar-row"><span><?php echo h($country); ?></span><div class="bar-track"><div class="bar-fill" style="width:<?php echo h(round(($count / max(1, max($countryCounts))) * 100)); ?>%"></div></div><strong><?php echo h($count); ?></strong></div>
              <?php endforeach; ?>
              <?php foreach (array_slice($cityCounts, 0, 3, true) as $city => $count): ?>
                <div class="bar-row"><span><?php echo h($city); ?></span><div class="bar-track"><div class="bar-fill" style="width:<?php echo h(round(($count / max(1, max($cityCounts))) * 100)); ?>%"></div></div><strong><?php echo h($count); ?></strong></div>
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

        <div class="analytics-subpanel" id="analytics-leads">
        <div class="analytics-grid two">
          <div class="panel section-body">
            <h3>Accuracy / Resolution</h3>
            <div class="mini-chart">
              <div class="bar-row"><span>Answered</span><div class="bar-track"><div class="bar-fill" style="width:<?php echo h($accuracy); ?>%"></div></div><strong><?php echo h($accuracy); ?>%</strong></div>
              <div class="bar-row"><span>Fallback</span><div class="bar-track"><div class="bar-fill" style="width:<?php echo h($fallbackRate); ?>%"></div></div><strong><?php echo h($fallbackRate); ?>%</strong></div>
              <div class="bar-row"><span>Escalation</span><div class="bar-track"><div class="bar-fill" style="width:<?php echo h($escalationRate); ?>%"></div></div><strong><?php echo h($escalationRate); ?>%</strong></div>
              <div class="bar-row"><span>Handoff</span><div class="bar-track"><div class="bar-fill" style="width:<?php echo h($handoffRate); ?>%"></div></div><strong><?php echo h($handoffRate); ?>%</strong></div>
              <div class="bar-row"><span>Abandon</span><div class="bar-track"><div class="bar-fill" style="width:<?php echo h($abandonmentRate); ?>%"></div></div><strong><?php echo h($abandonmentRate); ?>%</strong></div>
              <div class="bar-row"><span>Satisfaction</span><div class="bar-track"><div class="bar-fill" style="width:<?php echo h($satisfactionPercent); ?>%"></div></div><strong><?php echo h($satisfactionPercent); ?>%</strong></div>
            </div>
          </div>
          <div class="panel section-body">
            <h3>Lead Generation Funnel</h3>
            <div class="funnel">
              <div class="funnel-step"><span>Visitors</span><strong><?php echo h($uniqueVisitorCount); ?></strong></div>
              <div class="funnel-step"><span>Chat Opened</span><strong><?php echo h($chatOpenedCount); ?></strong></div>
              <div class="funnel-step"><span>Started Chat</span><strong><?php echo h($conversationCount); ?></strong></div>
              <div class="funnel-step"><span>Shared Contact</span><strong><?php echo h($leadCount); ?></strong></div>
              <div class="funnel-step"><span>OTP Verified</span><strong><?php echo h($verifiedLeadCount); ?></strong></div>
            </div>
            <div class="mini-chart">
              <div class="inline-row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:10px 0"><span>Email collected</span><strong><?php echo h($emailLeadCount); ?></strong></div>
              <div class="inline-row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:10px 0"><span>Phone collected</span><strong><?php echo h($phoneLeadCount); ?></strong></div>
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
          <div class="panel section-body"><h3>Export & Reports</h3><?php if (!$canExportReports): ?><div class="notice" style="margin-top:12px"><strong>Subscription required:</strong><br>CSV export and downloadable reports are available on Business plan and higher.</div><?php else: ?><div class="report-actions" style="margin-top:12px"><button class="ghost-btn" type="button" id="exportAnalyticsCsvBtn">Export CSV</button><button class="ghost-btn" type="button" id="downloadAnalyticsReportBtn">Download report</button><button class="ghost-btn" type="button" id="printAnalyticsReportBtn">Print / Save PDF</button><button class="ghost-btn" type="button" id="downloadWeeklyReportBtn">Weekly report</button><button class="ghost-btn" type="button" id="downloadMonthlyReportBtn">Monthly report</button></div><?php endif; ?></div>
          <div class="panel section-body"><h3>Notifications / Alerts</h3><div class="mini-chart"><div class="notice">Fallback rate: <?php echo h($fallbackRate); ?>%</div><div class="notice">Trending unanswered questions: <?php echo h($unansweredCount); ?></div><div class="notice">Lead conversion: <?php echo h($leadConversionRate); ?>%</div></div></div>
        </div>
        </div>
        <?php endif; ?>
      </section>

      <section class="tab-panel" id="install">
        <div class="panel">
          <div class="section-head"><h3>Integration / Install</h3></div>
          <div class="section-body form-grid">
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

            <div class="panel-actions full">
              <button class="pill-btn" type="button" id="saveIntegrationBtn">Save integration settings</button>
            </div>

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
                  <p class="muted">Step-by-step reference for API keys, endpoints, filters, sample requests, webhooks, errors, and security.</p>
                  <?php if ($canUseBusinessApi): ?>
                    <a class="pill-btn" href="api_integration.php">Open API guide</a>
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
                  <button class="pill-btn" type="button" id="saveWebhookBtn" <?php echo $canUseWebhook ? '' : 'disabled'; ?>>Save webhook</button>
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
              <div class="security-grid">
                <div class="security-card">
                  <h4>Customer API example</h4>
                  <code class="api-key-code">curl -H "Authorization: Bearer CUSTOMER_API_KEY" "<?php echo h($customerApiUrl); ?>"</code>
                </div>
                <div class="security-card">
                  <h4>Read-only data endpoints</h4>
                  <div class="mini-chart">
                    <?php foreach ([
                      'customer_api_leads' => 'Leads',
                      'customer_api_conversations' => 'Conversations',
                      'customer_api_faqs' => 'FAQs',
                      'customer_api_wallet' => 'Wallet data',
                      'customer_api_profile' => 'Profile data',
                      'customer_api_analytics' => 'Analytics'
                    ] as $endpoint => $label): ?>
                      <div class="inline-row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:8px 0">
                        <span><?php echo h($label); ?></span>
                        <code class="api-key-code"><?php echo h($customerApiBaseUrl . $endpoint); ?></code>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <small class="input-help">Use limit, offset, date_from, and date_to where supported.</small>
                </div>
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

            <div class="field full">
              <label>Install snippet</label>
              <div class="embed-box"><code id="embedCode"><?php echo h($embedCode ?: 'Create or select a bot to generate the embed script.'); ?></code></div>
              <div class="panel-actions">
                <button class="pill-btn copy-btn" type="button" data-copy="<?php echo h($embedCode); ?>">Copy JS snippet</button>
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
          <span class="eyebrow">Subscription</span>
          <h2 style="margin:8px 0 10px">Subscription Plans</h2>
          <p class="muted">Choose the monthly plan that fits your FAQ limit, lead verification, analytics, and integration needs.</p>

          <div class="metrics" style="margin-top:18px">
            <div class="panel metric"><span>Current plan</span><strong><?php echo h($activePlan['name']); ?></strong><small><?php echo h($faqCount); ?>/<?php echo $planFaqLimit === PHP_INT_MAX ? 'Unlimited' : h($planFaqLimit); ?> FAQs used.</small></div>
            <div class="panel metric"><span>Best default</span><strong>Growth</strong><small>Most local businesses will fit this tier.</small></div>
          </div>

          <div class="notice" style="margin-top:18px">
            <strong>Mandatory automatic payment setup</strong><br>
            Every paid plan starts with Razorpay recurring authorization. The first payment adds the plan amount to your wallet and saves the approved token for future automatic wallet recharges.
            <div class="form-grid" style="margin-top:14px">
              <div class="field">
                <label for="subscriptionAutoPayNameInput">Customer name</label>
                <input id="subscriptionAutoPayNameInput" value="<?php echo h($razorpayCustomerName); ?>" autocomplete="name">
              </div>
              <div class="field">
                <label for="subscriptionAutoPayContactInput">Mobile number with country code</label>
                <input id="subscriptionAutoPayContactInput" value="<?php echo h($razorpayCustomerContact); ?>" placeholder="+919876543210" autocomplete="tel">
              </div>
            </div>
          </div>

          <div class="pricing-grid">
            <div class="panel pricing-card <?php echo $activePlanId === 'starter' ? 'current-plan' : ''; ?>">
              <div class="pricing-head"><div><span class="eyebrow">Starter</span><h3>Starter Plan</h3></div><span class="tag">Small</span></div>
              <?php if ($activePlanId === 'starter'): ?><div class="current-plan-note">Current plan</div><?php endif; ?>
              <div class="price">₹199<small>/month</small></div>
              <div class="feature-list"><span>100 FAQ answers for small websites</span><span>Email and Mobile OTP verification for real leads</span><span>WhatsApp Redirect add-on billed at ₹99 / 30 days</span><span>Webhook support</span><span>Auto wallet recharge: below ₹50, recharge ₹199</span></div>
              <div class="wallet-table"><table><thead><tr><th>Wallet action</th><th>Charge</th></tr></thead><tbody><tr><td>Fresh Email OTP Lead</td><td>₹6</td></tr><tr><td>Repeat Email OTP Verification</td><td>₹2</td></tr><tr><td>Fresh Mobile OTP Lead</td><td>₹12</td></tr><tr><td>Repeat Mobile OTP Verification</td><td>₹3</td></tr><tr><td>WhatsApp Redirect</td><td>Add-on ₹99, refundable if cancelled within 1 hour</td></tr></tbody></table></div>
              <small class="muted">Validity of Fresh Email and Mobile OTP Leads is 30 days from last user verification.</small>
              <button class="pill-btn billing-plan-btn" type="button" data-plan-id="starter">Start Auto Payment</button>
              <small class="muted">Best for portfolios, coaches, and small businesses.</small>
            </div>

            <div class="panel pricing-card featured <?php echo $activePlanId === 'growth' ? 'current-plan' : ''; ?>">
              <div class="pricing-head"><div><span class="eyebrow">Growth</span><h3>Growth Plan</h3></div><span class="tag good">Popular</span></div>
              <?php if ($activePlanId === 'growth'): ?><div class="current-plan-note">Current plan</div><?php endif; ?>
              <div class="price">₹499<small>/month</small></div>
              <div class="feature-list"><span>300 FAQ answers for growing local businesses</span><span>Email and Mobile OTP verification for real leads</span><span>WhatsApp Redirect add-on billed at ₹99 / 30 days</span><span>Webhook support</span><span>Auto wallet recharge: below ₹100, recharge ₹499</span><span>Partial Analytics dashboard for tracking captured contacts</span><span>Better wallet rates than Starter on email and mobile leads</span><span>Analytics access: Overview, Conversations, FAQ Insights, Leads</span></div>
              <div class="wallet-table"><table><thead><tr><th>Wallet action</th><th>Charge</th></tr></thead><tbody><tr><td>Fresh Email OTP Lead</td><td>₹5</td></tr><tr><td>Repeat Email OTP Verification</td><td>₹1</td></tr><tr><td>Fresh Mobile OTP Lead</td><td>₹10</td></tr><tr><td>Repeat Mobile OTP Verification</td><td>₹2</td></tr><tr><td>WhatsApp Redirect</td><td>Add-on ₹99, refundable if cancelled within 1 hour</td></tr></tbody></table></div>
              <small class="muted">Validity of Fresh Email and Mobile OTP Leads is 30 days from last user verification.</small>
              <button class="pill-btn billing-plan-btn" type="button" data-plan-id="growth">Start Auto Payment</button>
              <small class="muted">Best for local businesses, agencies, and service providers.</small>
            </div>

            <div class="panel pricing-card <?php echo $activePlanId === 'business' ? 'current-plan' : ''; ?>">
              <div class="pricing-head"><div><span class="eyebrow">Business</span><h3>Business Plan</h3></div><span class="tag">Scale</span></div>
              <?php if ($activePlanId === 'business'): ?><div class="current-plan-note">Current plan</div><?php endif; ?>
              <div class="price">₹999<small>/month</small></div>
              <div class="feature-list"><span>Unlimited FAQ capacity for larger businesses</span><span>Email and Mobile combined OTP verification for real leads</span><span>WhatsApp Redirect add-on billed at ₹99 / 30 days</span><span>Webhook support</span><span>Auto wallet recharge: below ₹200, recharge ₹999</span><span>Complete Analytics dashboard for tracking captured contacts</span><span>Access for API Integration, Migrate or save data in your database via API</span><span>Advanced Analytics: Overview, Conversations, FAQ Insights, Leads, Pages, Real-Time, Reports Download</span><span>Chat can run only allowed domains</span></div>
              <div class="wallet-table"><table><thead><tr><th>Wallet action</th><th>Charge</th></tr></thead><tbody><tr><td>Fresh Email OTP Lead</td><td>₹5</td></tr><tr><td>Repeat Email OTP Verification</td><td>₹1</td></tr><tr><td>Fresh Mobile OTP Lead</td><td>₹10</td></tr><tr><td>Repeat Mobile OTP Verification</td><td>₹2</td></tr><tr><td>WhatsApp Redirect</td><td>Add-on ₹99, refundable if cancelled within 1 hour</td></tr></tbody></table></div>
              <small class="muted">Validity of Fresh Email and Mobile OTP Leads is 30 days from last user verification.</small>
              <button class="pill-btn billing-plan-btn" type="button" data-plan-id="business">Start Auto Payment</button>
              <small class="muted">Best for real estate, education institutes, marketing agencies, SaaS businesses, and larger teams.</small>
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
              <?php if ($isCancelledWalletAccess): ?>You will continue on <?php echo h($activePlan['name']); ?> until the wallet reaches zero, then the account will move to Free service.<?php endif; ?>
            </p>
            <div class="panel-actions">
              <button class="danger-btn" type="button" id="cancelSubscriptionBtn" <?php echo $activePlanId === 'free' || $isCancelledWalletAccess ? 'disabled' : ''; ?>>
                <?php echo h($isCancelledWalletAccess ? 'Auto Payment Stopped' : ($activePlanId === 'free' ? 'Free Service Active' : 'Unsubscribe Auto Payment')); ?>
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
              <div class="field"><label>New password</label><input id="newPasswordInput" type="password" placeholder="Minimum 8 characters" autocomplete="new-password"></div>
              <div class="field"><label>Confirm password</label><input id="confirmPasswordInput" type="password" placeholder="Repeat new password" autocomplete="new-password"></div>
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
              <p class="muted">Complete summary of wallet credits and deductions for subscription payments, OTP verifications, leads, WhatsApp redirects, and other paid usage.</p>
            </div>
            <button class="ghost-btn" type="button" id="refreshBillingBtn">Refresh Billing</button>
          </div>

          <div class="metrics" style="margin-top:18px">
            <div class="panel metric"><span>Wallet balance</span><strong><?php echo h(billing_rupees($billingWalletPaise)); ?></strong><small>Available for paid usage.</small></div>
            <div class="panel metric"><span>Current plan</span><strong><?php echo h($activePlan['name']); ?></strong><small>Subscription status: <?php echo h($isCancelledWalletAccess ? 'cancelled, wallet access active' : $subscriptionStatus); ?></small></div>
            <div class="panel metric"><span>Billing model</span><strong>Hybrid</strong><small>Monthly subscription plus usage wallet.</small></div>
            <div class="panel metric"><span>Total credited</span><strong><?php echo h(billing_rupees($walletCreditPaise)); ?></strong><small>Money added to wallet.</small></div>
            <div class="panel metric"><span>Total deducted</span><strong><?php echo h(billing_rupees($walletDebitPaise)); ?></strong><small>Paid feature usage.</small></div>
            <div class="panel metric"><span>Transactions</span><strong><?php echo h(count($walletTransactionRows)); ?></strong><small>Latest wallet activity.</small></div>
          </div>

          <div class="split" style="margin-top:18px">
            <div class="notice"><strong>Monthly subscription:</strong><br>Fixed platform fee for dashboard access, FAQ limits, analytics, and plan features.</div>
            <div class="notice"><strong>Wallet usage:</strong><br>Usage charges deduct automatically for OTP verification, leads, WhatsApp redirects, and other paid services.</div>
          </div>
          <div class="notice" style="margin-top:18px">
            <strong>Auto wallet recharge:</strong><br>
            <?php if ($activePlanId === 'free'): ?>
              Auto recharge starts after a paid plan is active.
            <?php else: ?>
              <?php echo h($activePlan['name']); ?> rule: when wallet balance is below <?php echo h(billing_rupees($autoRechargeThresholdPaise)); ?>, recharge <?php echo h(billing_rupees($autoRechargeAmountPaise)); ?> automatically.
              Status: <?php echo h($autoRechargeEnabled ? 'Enabled' : 'Disabled'); ?>.
              Saved payment method: <?php echo h(ucfirst($savedPaymentMethodStatus)); ?>.
              <?php if ($savedPaymentMethodStatus !== 'active'): ?>Mandatory auto charging is not ready until a Razorpay recurring token/mandate is saved. Paid wallet deductions will fail if the balance is insufficient.<?php endif; ?>
            <?php endif; ?>
          </div>
          <?php if ($whatsappStoppedReason === 'insufficient_wallet_balance'): ?>
            <div class="notice" style="margin-top:18px">
              <strong>WhatsApp Redirect stopped:</strong><br>
              Renewal charge shown as <?php echo h(billing_rupees(0)); ?> because wallet balance was below <?php echo h(billing_rupees($whatsappFailedChargePaise ?: $whatsappChargePaise)); ?>. WhatsApp redirection is OFF until you recharge wallet and turn it ON again.
            </div>
          <?php endif; ?>

          <div class="notice" style="margin-top:18px">
            <div class="section-head" style="padding:0;align-items:flex-start">
              <div>
                <strong>Automatic payment authorization</strong><br>
                Customer ID: <?php echo h($savedPaymentCustomerId ?: 'Not created'); ?>.
                Contact: <?php echo h($savedPaymentContact ?: 'Not linked'); ?>.<br>
                Token status:
                <span id="autoRechargeMandateStatusText"><?php echo h(ucfirst($savedPaymentMethodStatus)); ?></span>.
                Token:
                <span id="autoRechargeTokenText"><?php echo h($savedPaymentMethodReference ? substr($savedPaymentMethodReference, 0, 10) . '...' : 'Not authorized'); ?></span>.
              </div>
              <span class="tag <?php echo $savedPaymentMethodStatus === 'active' ? 'good' : 'bad'; ?>" id="autoRechargeMandateStatusTag"><?php echo h($savedPaymentMethodStatus === 'active' ? 'Ready' : 'Authorize'); ?></span>
            </div>
            <p class="muted" style="margin-top:12px">
              This authorization is now mandatory during plan purchase. Choose Starter, Growth, or Business from the Subscription tab to approve automatic payment and activate the plan.
            </p>
          </div>

          <div class="table-wrap" style="margin-top:18px">
            <table>
              <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Amount</th><th>Balance After</th><th>Reference</th></tr></thead>
              <tbody>
                <?php if (empty($walletTransactionRows)): ?>
                  <tr><td colspan="6" class="empty">No wallet transactions yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($walletTransactionRows as $txn): ?>
                  <?php $type = (string)($txn['transaction_type'] ?? ''); ?>
                  <tr>
                    <td><?php echo h($txn['created_at'] ?? ''); ?></td>
                    <td><span class="tag <?php echo $type === 'credit' ? 'good' : 'bad'; ?>"><?php echo h(ucfirst($type)); ?></span></td>
                    <td><?php echo h($txn['description'] ?? ''); ?></td>
                    <td><?php echo h(($type === 'debit' ? '-' : '+') . billing_rupees((int)($txn['amount_paise'] ?? 0))); ?></td>
                    <td><?php echo h(billing_rupees((int)($txn['balance_after_paise'] ?? 0))); ?></td>
                    <td><?php echo h(($txn['reference_type'] ?? '') . ' ' . ($txn['reference_id'] ?? '')); ?></td>
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
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
const tabs = document.querySelectorAll(".tab-btn");
const panels = document.querySelectorAll(".tab-panel");
const toast = document.getElementById("toast");
const themeToggle = document.getElementById("themeToggle");
const navToggle = document.getElementById("navToggle");
const accountToggle = document.getElementById("accountToggle");
const drawerOverlay = document.getElementById("drawerOverlay");
const accountToggleText = accountToggle?.textContent || "";
let currentFaqCount = <?php echo json_encode($faqCount); ?>;
const freeFaqLimit = <?php echo json_encode($freeFaqLimit); ?>;
const selectedCustomerId = <?php echo json_encode($selectedBotId); ?>;
const billingEmail = <?php echo json_encode($email); ?>;
const leadPaidFeatures = <?php echo json_encode([
  "email_otp" => $canUseEmailOtp,
  "mobile_otp" => $canUseMobileOtp,
  "whatsapp_redirect" => $canUseWhatsappRedirect
]); ?>;
const businessFeatures = <?php echo json_encode([
  "api_access" => $canUseBusinessApi,
  "webhook_support" => $canUseWebhook,
  "human_handoff" => $canUseHumanHandoff,
  "allowed_domains" => $canUseAllowedDomains
]); ?>;
const leadWalletCharges = <?php echo json_encode([
  "fresh_email_lead" => billing_wallet_charge_paise($activePlanId, "fresh_email_lead"),
  "repeat_email_lead" => billing_wallet_charge_paise($activePlanId, "repeat_email_lead"),
  "reactivated_email_lead" => billing_wallet_charge_paise($activePlanId, "reactivated_email_lead"),
  "fresh_mobile_lead" => billing_wallet_charge_paise($activePlanId, "fresh_mobile_lead"),
  "repeat_mobile_lead" => billing_wallet_charge_paise($activePlanId, "repeat_mobile_lead"),
  "reactivated_mobile_lead" => billing_wallet_charge_paise($activePlanId, "reactivated_mobile_lead"),
  "whatsapp_redirect_addon" => billing_wallet_charge_paise($activePlanId, "whatsapp_redirect_addon")
]); ?>;
const whatsappRedirectLockedOn = <?php echo json_encode($whatsappRedirectLockedOn); ?>;
const whatsappRedirectLocked = <?php echo json_encode($whatsappRedirectLocked); ?>;
const walletBalancePaise = <?php echo json_encode($billingWalletPaise); ?>;
const whatsappRedirectChargePaise = <?php echo json_encode($whatsappChargePaise); ?>;
const analyticsReport = <?php echo json_encode([
  "bot_name" => $botName,
  "range_label" => $analyticsRangeLabel,
  "date_from" => $analyticsFrom,
  "date_to" => $analyticsTo,
  "summary" => [
    "total_conversations" => $conversationCount,
    "total_messages" => $totalMessages,
    "unique_visitors" => $uniqueVisitorCount,
    "answered_queries_percent" => $accuracy,
    "unanswered_queries_percent" => $unansweredPercent,
    "avg_response_time_ms" => $avgResponseTimeMs,
    "leads_collected" => $leadCount,
    "otp_verified_leads" => $verifiedLeadCount,
    "active_chatbots" => $activeChatbotCount,
    "most_active_page" => $mostActivePage,
    "returning_users_percent" => $returningUsersPercent,
    "avg_conversation_duration" => $avgConversationDuration
  ],
  "daily_counts" => $dailyChartCounts,
  "hour_counts" => $hourChartCounts,
  "devices" => $deviceCounts,
  "browsers" => $browserCounts,
  "countries" => $countryCounts,
  "cities" => $cityCounts,
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
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

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
  tabs.forEach(tab => tab.classList.toggle("active", tab.dataset.tab === id));
  panels.forEach(panel => panel.classList.toggle("active", panel.id === id));
  document.querySelector(`.tab-btn[data-tab="${id}"]`)?.scrollIntoView({
    block: "nearest",
    inline: "nearest",
    behavior: "smooth"
  });
  if (updateHash && location.hash !== "#" + id) history.replaceState(null, "", "#" + id);
  closeDrawers();
}

tabs.forEach(tab => tab.addEventListener("click", () => openTab(tab.dataset.tab)));

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
        method: {card: true, netbanking: false, wallet: false, upi: false},
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
      checkout.on("payment.failed", response => {
        showToast(response.error?.description || "Mandate authorization failed");
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

function analyticsCsv() {
  const rows = [
    ["Section", "Metric", "Value"],
    ...Object.entries(analyticsReport.summary || {}).map(([key, value]) => ["Summary", key, value]),
    [],
    ["Daily Counts", "Date", "Conversations"],
    ...Object.entries(analyticsReport.daily_counts || {}).map(([date, count]) => ["Daily Counts", date, count]),
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
    ["Top Questions", "Question", "Count", "Success Rate"],
    ...(analyticsReport.top_questions || []).map(item => ["Top Questions", item.question, item.count, `${item.success_rate}%`]),
    [],
    ["Unanswered Questions", "Question", "Source Page", "Date"],
    ...(analyticsReport.unanswered_questions || []).map(item => ["Unanswered Questions", item.question, item.source_page, item.date]),
    [],
    ["Source Pages", "Page", "Conversations", "Leads", "Success Rate"],
    ...(analyticsReport.source_pages || []).map(item => ["Source Pages", item.page, item.conversations, item.leads, `${item.success_rate}%`])
  ];
  return rowsToCsv(rows);
}

function analyticsReportHtml() {
  const esc = value => String(value ?? "").replace(/[&<>"']/g, char => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[char]));
  const summaryRows = Object.entries(analyticsReport.summary || {})
    .map(([key, value]) => `<tr><th>${esc(key.replace(/_/g, " "))}</th><td>${esc(value)}</td></tr>`).join("");
  const table = (title, headers, rows) => `
    <h2>${esc(title)}</h2>
    <table><thead><tr>${headers.map(header => `<th>${esc(header)}</th>`).join("")}</tr></thead>
    <tbody>${rows.length ? rows.join("") : `<tr><td colspan="${headers.length}">No data</td></tr>`}</tbody></table>`;
  return `<!doctype html>
<html><head><meta charset="utf-8"><title>Vani Analytics Report</title>
<style>body{font-family:Arial,sans-serif;margin:32px;color:#111827}h1{margin-bottom:4px}p{color:#4b5563}table{width:100%;border-collapse:collapse;margin:16px 0 28px}th,td{text-align:left;border:1px solid #e5e7eb;padding:9px 10px;font-size:13px}th{background:#f8fafc}.muted{color:#64748b}</style>
</head><body>
<h1>Vani Analytics Report</h1>
<p>${esc(analyticsReport.bot_name)} | ${esc(analyticsReport.range_label)} | ${esc(analyticsReport.date_from)} to ${esc(analyticsReport.date_to)}</p>
<h2>Summary</h2><table><tbody>${summaryRows}</tbody></table>
${table("Top Questions", ["Question", "Count", "Success Rate"], (analyticsReport.top_questions || []).map(item => `<tr><td>${esc(item.question)}</td><td>${esc(item.count)}</td><td>${esc(item.success_rate)}%</td></tr>`))}
${table("Unanswered Questions", ["Question", "Source Page", "Date"], (analyticsReport.unanswered_questions || []).map(item => `<tr><td>${esc(item.question)}</td><td>${esc(item.source_page)}</td><td>${esc(item.date)}</td></tr>`))}
${table("Source Pages", ["Page", "Conversations", "Leads", "Success Rate"], (analyticsReport.source_pages || []).map(item => `<tr><td>${esc(item.page)}</td><td>${esc(item.conversations)}</td><td>${esc(item.leads)}</td><td>${esc(item.success_rate)}%</td></tr>`))}
<p class="muted">Generated from the dashboard data currently loaded in your browser.</p>
</body></html>`;
}

document.getElementById("exportAnalyticsCsvBtn")?.addEventListener("click", () => {
  downloadBlob(`${reportFileBase()}.csv`, analyticsCsv(), "text/csv;charset=utf-8");
  showToast("CSV report downloaded");
});

document.getElementById("downloadAnalyticsReportBtn")?.addEventListener("click", () => {
  downloadBlob(`${reportFileBase()}.html`, analyticsReportHtml(), "text/html;charset=utf-8");
  showToast("Report downloaded");
});

document.getElementById("downloadWeeklyReportBtn")?.addEventListener("click", () => {
  downloadBlob(`${reportFileBase()}-weekly.html`, analyticsReportHtml(), "text/html;charset=utf-8");
  showToast("Weekly report downloaded");
});

document.getElementById("downloadMonthlyReportBtn")?.addEventListener("click", () => {
  downloadBlob(`${reportFileBase()}-monthly.html`, analyticsReportHtml(), "text/html;charset=utf-8");
  showToast("Monthly report downloaded");
});

document.getElementById("printAnalyticsReportBtn")?.addEventListener("click", () => {
  const reportWindow = window.open("", "_blank");
  if (!reportWindow) return showToast("Allow popups to print the report");
  reportWindow.document.write(analyticsReportHtml());
  reportWindow.document.close();
  reportWindow.focus();
  reportWindow.print();
});

async function startPlanCheckout(planId, button) {
  if (!selectedCustomerId) {
    showToast("Select or create a bot before subscribing");
    return;
  }
  if (!window.Razorpay) {
    showToast("Razorpay checkout could not be loaded");
    return;
  }
  const autoPayName = document.getElementById("subscriptionAutoPayNameInput")?.value.trim() || "";
  const autoPayContact = document.getElementById("subscriptionAutoPayContactInput")?.value.trim() || "";
  if (autoPayName.length < 3) {
    showToast("Enter customer name for automatic payment");
    document.getElementById("subscriptionAutoPayNameInput")?.focus();
    return;
  }
  if (!autoPayContact) {
    showToast("Enter mobile number with country code");
    document.getElementById("subscriptionAutoPayContactInput")?.focus();
    return;
  }
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = "Creating auto payment...";

  const orderResponse = await fetch("/api.php?action=create_auto_recharge_mandate_order", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({
      plan_id: planId,
      customer_id: selectedCustomerId,
      name: autoPayName,
      contact: autoPayContact
    })
  });
  const orderData = await orderResponse.json().catch(() => ({}));
  button.disabled = false;
  button.textContent = originalText;

  if (!orderData.success) {
    showToast(orderData.message || "Automatic payment could not be started");
    return;
  }

  const checkout = new Razorpay({
    key: orderData.key_id,
    amount: orderData.order.amount,
    currency: orderData.order.currency || "INR",
    name: "Vani AI",
    description: `${orderData.plan.name} mandatory auto payment`,
    order_id: orderData.order.id,
    customer_id: orderData.razorpay_customer_id,
    recurring: true,
    remember_customer: true,
    method: {card: true, netbanking: false, wallet: false, upi: false},
    prefill: {
      name: autoPayName,
      email: billingEmail,
      contact: orderData.contact || autoPayContact
    },
    readonly: {email: true},
    theme: {color: "#6366f1"},
    handler: async response => {
      showToast("Verifying automatic payment...");
      const verifyResponse = await fetch("/api.php?action=verify_auto_recharge_mandate", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify(response)
      });
      const verifyData = await verifyResponse.json().catch(() => ({}));
      if (!verifyData.success) {
        showToast(verifyData.message || "Automatic payment verification failed");
        return;
      }
      showToast("Plan activated with automatic payment");
      setTimeout(() => location.reload(), 900);
    }
  });
  checkout.on("payment.failed", response => {
    showToast(response.error?.description || "Automatic payment authorization failed");
  });
  checkout.open();
}

document.querySelectorAll(".billing-plan-btn").forEach(button => {
  button.addEventListener("click", () => startPlanCheckout(button.dataset.planId, button));
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

themeToggle.addEventListener("click", () => {
  const dark = !document.body.classList.contains("dark");
  document.body.classList.toggle("dark", dark);
  themeToggle.textContent = dark ? "Bright" : "Dark";
  localStorage.setItem("vani_dashboard_theme", dark ? "dark" : "bright");
});

if (localStorage.getItem("vani_dashboard_theme") === "dark") {
  document.body.classList.add("dark");
  themeToggle.textContent = "Bright";
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
  if (!requireLeadPaidFeature("email_otp", leadEmailOtpToggle, "Email OTP requires an active subscription")) return;
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
  if (leadEmailOtpToggle?.checked && !requireLeadPaidFeature("email_otp", leadEmailOtpToggle, "Email OTP requires an active subscription")) return;
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
  });
});

document.querySelectorAll("input[name='dashboardBotImage']").forEach(input => {
  input.addEventListener("change", () => {
    const preview = document.getElementById("selectedBotImagePreview");
    if (preview && input.checked) preview.src = input.value;
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

async function saveDashboardSettings(extraPayload) {
  const customerId = document.getElementById("settingsCustomerId")?.value || "";
  if (!customerId) {
    showToast("Select a bot first");
    return false;
  }

  const response = await fetch("/api.php?action=save_dashboard_settings", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, ...extraPayload})
  });

  const data = await response.json().catch(() => ({}));
  if (!data.success) {
    showToast("Settings could not be saved");
    return false;
  }
  showToast("Settings saved");
  return true;
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

document.getElementById("saveSetupBtn")?.addEventListener("click", () => {
  saveDashboardSettings({
    bot_name: document.getElementById("botNameInput").value.trim(),
    welcome_message: document.getElementById("welcomeInput").value.trim(),
    theme_color: document.getElementById("themeColorInput").value,
    avatar_url: document.querySelector("input[name='dashboardBotImage']:checked")?.value || "",
    position: document.getElementById("positionInput").value,
    language: document.getElementById("languageInput").value
  });
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

document.getElementById("saveIntegrationBtn")?.addEventListener("click", async event => {
  const button = event.currentTarget;
  const websiteVerificationEnabled = !!document.getElementById("websiteVerificationToggle")?.checked;
  const allowedDomainsEnabled = businessFeatures.allowed_domains && !!document.getElementById("allowedDomainsToggle")?.checked;
  const allowedDomains = document.getElementById("allowedDomainsInput")?.value.trim() || "";

  if (allowedDomainsEnabled && !allowedDomains) {
    showToast("Add at least one allowed domain");
    document.getElementById("allowedDomainsInput")?.focus();
    return;
  }

  button.disabled = true;
  button.textContent = "Saving...";
  const saved = await saveDashboardSettings({
    website_verification_enabled: websiteVerificationEnabled,
    allowed_domains_enabled: allowedDomainsEnabled,
    allowed_domains: allowedDomains,
    verification_status: websiteVerificationEnabled ? "Pending" : "Disabled"
  });
  button.disabled = false;
  button.textContent = "Save integration settings";

  if (saved) {
    const statusText = document.getElementById("verificationStatusText");
    if (statusText) statusText.textContent = websiteVerificationEnabled ? "Pending" : "Disabled";
  }
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
  const newPassword = document.getElementById("newPasswordInput").value;
  const confirmPassword = document.getElementById("confirmPasswordInput").value;

  if (newPassword || confirmPassword) {
    if (newPassword !== confirmPassword) return showToast("Passwords do not match");
    if (newPassword.length < 8) return showToast("Password needs at least 8 characters");
  }

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
      location_notes: document.getElementById("locationInput").value.trim(),
      new_password: newPassword
    })
  });

  const data = await response.json().catch(() => ({}));
  if (!data.success) {
    showToast(data.message || "Profile could not be saved");
    return;
  }
  document.getElementById("newPasswordInput").value = "";
  document.getElementById("confirmPasswordInput").value = "";
  showToast(data.password ? "Profile and password saved" : "Profile saved");
});

const hash = location.hash.replace("#", "");
if (hash && !hash.includes("/") && document.getElementById(hash)) openTab(hash);
</script>
</body>
</html>
