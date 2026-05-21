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

function widget_billing_plan_for_customer(string $customerId): string {
    $signup = widget_get_signup($customerId);
    $email = trim((string)($signup['email'] ?? ''));
    if ($email === '') {
        return 'free';
    }
    $rows = widget_safe_rows(supabase(
        "GET",
        "billing_accounts?select=*&email=eq." . urlencode($email) . "&limit=1"
    ));
    return billing_active_plan_from_account($rows[0] ?? []);
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
    if ($eventType === 'identity') {
        $subject = "New verified lead captured";
        $html = "<p>A lead completed identity verification.</p>";
    } elseif ($eventType === 'mobile') {
        $subject = "New lead mobile captured";
        $html = "<p>A lead shared their mobile number: <strong>" . htmlspecialchars($leadPhone) . "</strong></p>";
    } else {
        $subject = "New lead email captured";
        $html = "<p>A lead shared their email: <strong>" . htmlspecialchars($leadEmail) . "</strong></p>";
    }
    if ($leadEmail !== '') {
        $html .= "<p>Email: " . htmlspecialchars($leadEmail) . "</p>";
    }
    if ($leadPhone !== '') {
        $html .= "<p>Phone: " . htmlspecialchars($leadPhone) . "</p>";
    }
    if (!empty($lead['source_url'])) {
        $html .= "<p>Source: " . htmlspecialchars((string)$lead['source_url']) . "</p>";
    }

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

function widget_access_result(array $settings, array $signup, string $sourceUrl): array {
    $host = widget_host_from_value($sourceUrl);
    $websiteVerificationEnabled = widget_bool($settings['website_verification_enabled'] ?? false);
    $allowedDomainsEnabled = widget_bool($settings['allowed_domains_enabled'] ?? false);

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
    $access = widget_access_result($settings, $signup, widget_request_source_url());
    if (($settings['verification_status'] ?? '') !== $access['status']) {
        supabase(
            "PATCH",
            "chatbot_settings?customer_id=eq." . urlencode($customerId),
            ["verification_status" => $access['status']]
        );
    }
    $themeColor = $settings['theme_color'] ?? $signup['theme_color'] ?? '#6366f1';
    $botName = $settings['bot_name'] ?? $signup['website_name'] ?? 'Chat Support';

    widget_json_response([
        "success" => true,
        "theme_color" => $themeColor,
        "bot_name" => $botName,
        "welcome_message" => $settings['welcome_message'] ?? 'Hi, how can I help you today?',
        "avatar_url" => $settings['avatar_url'] ?? '',
        "position" => $settings['position'] ?? 'right',
        "language" => $settings['language'] ?? 'English',
        "is_active" => widget_bool($settings['is_active'] ?? true, true) && $access['allowed'],
        "billing" => [
            "active_plan" => $activePlan,
            "email_otp" => billing_feature_enabled($activePlan, 'email_otp'),
            "mobile_otp" => billing_feature_enabled($activePlan, 'mobile_otp'),
            "whatsapp_redirect" => billing_feature_enabled($activePlan, 'whatsapp_redirect')
        ],
        "website_verification_enabled" => widget_bool($settings['website_verification_enabled'] ?? false),
        "allowed_domains_enabled" => widget_bool($settings['allowed_domains_enabled'] ?? false),
        "allowed_domains" => $settings['allowed_domains'] ?? '',
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

    $access = widget_access_result($settings, widget_get_signup($customerId), widget_request_source_url($data));
    if (!$access['allowed']) {
        widget_json_response([
            "success" => true,
            "reply" => $access['message'] ?: "This chatbot is not enabled for this website.",
            "status" => "blocked"
        ]);
    }

    $faqs = widget_safe_rows(supabase(
        "GET",
        "faq_questions?select=id,question,answer&customer_id=eq." . urlencode($customerId)
    ));

    $input = strtolower($message);
    $reply = null;
    $matchedFaqId = null;

    foreach ($faqs as $faq) {
        $question = strtolower(trim((string)($faq['question'] ?? '')));
        if (!$question) {
            continue;
        }

        similar_text($input, $question, $percent);
        if (strpos($input, $question) !== false || strpos($question, $input) !== false || $percent > 55) {
            $reply = (string)($faq['answer'] ?? '');
            $matchedFaqId = $faq['id'] ?? null;
            break;
        }
    }

    $answered = $reply !== null && $reply !== '';
    if (!$answered) {
        $reply = "Sorry, I don't have an answer for that yet. Please contact customer support for help.";
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
        supabase("POST", "chatbot_conversations", [$conversationPayload]);
    }

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
        "matched_faq_id" => $matchedFaqId
    ]);
}

if ($action === "get_top_faqs") {
    $customerId = widget_customer_id();
    if (!$customerId) {
        widget_json_response(["success" => false, "message" => "Missing customer_id"], 400);
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
            "faq_questions?select=id,question&customer_id=eq." . urlencode($customerId) . "&limit=5"
        );
        widget_json_response(["success" => true, "data" => widget_safe_rows($res)]);
    }

    $res = supabase(
        "GET",
        "faq_questions?select=id,question&customer_id=eq." . urlencode($customerId) . "&id=in.(" . implode(",", $topIds) . ")"
    );

    $questions = widget_safe_rows($res);
    usort($questions, fn($a, $b) => ($counts[$b['id']] ?? 0) <=> ($counts[$a['id']] ?? 0));
    widget_json_response(["success" => true, "data" => $questions]);
}

if ($action === "track_widget_session") {
    $data = widget_get_json();
    $res = widget_save_session($data);
    widget_json_response([
        "success" => ($res['status'] >= 200 && $res['status'] < 300),
        "debug" => $res
    ]);
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
        widget_json_response(["success" => false, "requires_premium" => true, "message" => "Mobile OTP requires Growth plan or higher."], 403);
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

    $res = widget_save_lead($customerId, $userId, [
        "phone_number" => $verifiedPhone,
        "source_url" => $sourceUrl ?: null,
        "mobile_otp_verified" => true,
        "verification_quality" => "real",
        "metadata" => [
            "mobile_otp_status" => "verified",
            "otp_provider" => "msg91",
            "msg91_verified_phone" => $verifiedPhone,
            "msg91_verify_response" => $verifyData,
            "msg91_widget_response" => $widgetResponse
        ]
    ]);
    $ok = ($res['status'] >= 200 && $res['status'] < 300);
    $lead = $res['data'][0] ?? null;
    $notified = (!$suppressNotification && $ok && $lead) ? widget_notify_lead_by_email($customerId, $lead, 'mobile') : false;
    widget_json_response(["success" => $ok, "lead" => $lead, "notified" => $notified, "debug" => $res]);
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

    // Mark verified
    $update = [
        "email_otp_verified" => true,
        "verification_quality" => 'real',
        "metadata" => (object)array_filter($meta, function($k){ return $k !== 'email_otp' && $k !== 'email_otp_expires_at'; }, ARRAY_FILTER_USE_KEY)
    ];

    $up = supabase("PATCH", "lead_generation_leads?id=eq." . $leadId, $update);
    $ok = ($up['status'] >= 200 && $up['status'] < 300);

    $notified = (!$suppressNotification && $ok) ? widget_notify_lead_by_email($customerId, array_merge($row, $update), $notificationEvent) : false;

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

    widget_json_response([
        "success" => ($res['status'] >= 200 && $res['status'] < 300),
        "status" => "tracked"
    ]);
}

widget_json_response([
    "success" => false,
    "message" => "Invalid action"
], 404);
