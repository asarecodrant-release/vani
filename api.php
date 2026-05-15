<?php
ini_set('display_errors', 1);
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
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
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

// ==========================
// INPUT SAFE PARSER
// ==========================
function getJSON() {
    $raw = file_get_contents("php://input");
    return json_decode($raw, true) ?? [];
}

// ==========================
$action = $_GET['action'] ?? '';
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

    echo json_encode([
        "status" => "theme_updated",
        "theme_color" => $data['theme_color'],
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

    if (empty($data['customer_id']) || empty($data['faqs'])) {
        echo json_encode(["error" => "Missing FAQ data"]);
        exit;
    }

    $rows = [];

    foreach ($data['faqs'] as $faq) {
        if (!empty($faq['question']) && !empty($faq['answer'])) {
            $rows[] = [
                "customer_id" => $data['customer_id'],
                "question" => $faq['question'],
                "answer" => $faq['answer']
            ];
        }
    }

    if (empty($rows)) {
        echo json_encode(["error" => "No valid FAQs"]);
        exit;
    }

    $res = supabase("POST", "faq_questions", $rows);

    echo json_encode([
        "status" => "faq_saved",
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

    $faqs = supabase(
        "GET",
        "faq_questions?customer_id=eq." . trim($customer_id)
    );

    $faqs = $faqs['data'] ?? [];

    $input = strtolower(trim($message));
    $reply = null;

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
            break;
        }
    }

    if (!$reply) {
        $reply = "Sorry, I don't have an answer for that yet.";
    }

    echo json_encode(["reply" => $reply]);
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
     // 👇 ADD THIS RIGHT HERE (near top)
    session_start();

    $_SESSION['email'] = trim($data['email'] ?? '');
    $_SESSION['customer_id'] = trim($data['customer_id'] ?? '');
    $_SESSION['website_name'] = trim($data['website_name'] ?? '');
    $_SESSION['business_type'] = trim($data['business_type'] ?? '');

    require "email.php";

    $customer_id = trim($data['customer_id'] ?? '');
    $email = trim($data['email'] ?? '');
    $website_name = trim($data['website_name'] ?? '');

    if (!$customer_id || !$email) {

        echo json_encode([
            "success" => false,
            "message" => "Missing customer_id or email"
        ]);

        exit;
    }

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