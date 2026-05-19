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

$action = $_GET['action'] ?? '';

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
        "chatbot_signups?select=website_name,theme_color&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    return $rows[0] ?? [];
}

function widget_get_lead_settings(string $customerId): array {
    $rows = widget_safe_rows(supabase(
        "GET",
        "lead_generation_settings?select=*&customer_id=eq." . urlencode($customerId) . "&limit=1"
    ));
    return $rows[0] ?? [];
}

if ($action === "get_widget_config" || $action === "get_theme") {
    $customerId = widget_customer_id();
    if (!$customerId) {
        widget_json_response(["success" => false, "message" => "Missing customer_id"], 400);
    }

    $settings = widget_get_settings($customerId);
    $signup = widget_get_signup($customerId);
    $leadSettings = widget_get_lead_settings($customerId);
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
        "is_active" => widget_bool($settings['is_active'] ?? true, true),
        "lead_generation" => [
            "is_enabled" => widget_bool($leadSettings['is_enabled'] ?? false),
            "collect_location" => widget_bool($leadSettings['collect_location'] ?? false),
            "verify_email_otp" => widget_bool($leadSettings['verify_email_otp'] ?? false),
            "notify_lead_by_email" => widget_bool($leadSettings['notify_lead_by_email'] ?? false),
            "redirect_whatsapp" => widget_bool($leadSettings['redirect_whatsapp'] ?? false),
            "whatsapp_mobile_number" => $leadSettings['whatsapp_mobile_number'] ?? '',
            "verify_mobile_otp" => widget_bool($leadSettings['verify_mobile_otp'] ?? false),
            "service_tier" => $leadSettings['service_tier'] ?? 'free'
        ]
    ]);
}

if ($action === "chat") {
    $data = widget_get_json();
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

    supabase("POST", "chatbot_conversations", [[
        "customer_id" => $customerId,
        "user_question" => $message,
        "bot_response" => $reply,
        "matched_faq_id" => $matchedFaqId,
        "status" => $answered ? "answered" : "unanswered",
        "is_answered" => $answered,
        "user_id" => $userId,
        "source_url" => $sourceUrl
    ]]);

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
