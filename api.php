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

// ==========================
// INPUT SAFE PARSER
// ==========================
function getJSON() {
    $raw = file_get_contents("php://input");
    return json_decode($raw, true) ?? [];
}

// ==========================
// ==========================


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
    $freeFaqLimit = 25;

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

    if ($existingCount + count($rows) > $freeFaqLimit) {
        echo json_encode([
            "success" => false,
            "requires_premium" => true,
            "message" => "Free plan includes up to 25 FAQs"
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
        "chatbot_settings?select=is_active&customer_id=eq." . urlencode(trim($customer_id)) . "&limit=1"
    );

    $rawActive = $settings['data'][0]['is_active'] ?? true;
    $isActive = is_bool($rawActive) ? $rawActive : ((string)$rawActive !== 'false');

    if (!$isActive) {
        echo json_encode(["reply" => "Chatbot is currently turned off. Please contact customer support."]);
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
            "is_answered" => (bool)$matchedQuestionId
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
