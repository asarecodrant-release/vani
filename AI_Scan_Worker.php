<?php
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/ai_service.php';

header('Content-Type: application/json');

function ai_worker_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$configuredToken = ai_env('AI_WORKER_TOKEN');
$providedToken = trim((string)($_SERVER['HTTP_X_WORKER_TOKEN'] ?? $_POST['worker_token'] ?? $_GET['worker_token'] ?? ''));
$tokenAuthenticated = $configuredToken !== '' && hash_equals($configuredToken, $providedToken);

if (!$tokenAuthenticated && !is_authenticated_user()) {
    ai_worker_json(['success' => false, 'error' => 'Authentication required.'], 401);
}
if (!$tokenAuthenticated) {
    $csrf = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionCsrf = (string)($_SESSION['ai_worker_csrf'] ?? '');
    if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        ai_worker_json(['success' => false, 'error' => 'Invalid worker token.'], 403);
    }
}

$scanId = trim((string)($_POST['scan'] ?? $_GET['scan'] ?? $_SESSION['ai_scan_job_id'] ?? ''));
if ($scanId === '') {
    ai_worker_json(['success' => false, 'error' => 'Scan id is required.'], 400);
}

$customerId = '';
if ($tokenAuthenticated) {
    $scan = ai_get_scan_job_by_id($scanId);
    $customerId = (string)($scan['customer_id'] ?? '');
} else {
    $email = authenticated_email();
    $customerId = (string)($_SESSION['setup_customer_id'] ?? '');
    if ($customerId === '') {
        $botRows = ai_safe_rows(supabase(
            'GET',
            'chatbot_signups?select=customer_id&email=eq.' . urlencode($email) . '&order=created_at.desc&limit=1'
        ));
        $customerId = (string)($botRows[0]['customer_id'] ?? '');
    }
    if ($customerId === '') {
        ai_worker_json(['success' => false, 'error' => 'Customer was not found.'], 404);
    }
    $scan = ai_get_scan_job_for_customer($scanId, $customerId);
}
if (empty($scan) || $customerId === '') {
    ai_worker_json(['success' => false, 'error' => 'Scan job was not found.'], 404);
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'status');
$result = ['success' => true];

if ($action === 'queue_test') {
    $result = ai_enqueue_external_job($scanId, 'scan', 5);
} elseif ($action === 'scan_batch') {
    $result = ai_process_scan_job_batch($scanId, $customerId, (int)ai_env('AI_CRAWL_BATCH_SIZE', '4'));
} elseif ($action === 'summarize_batch') {
    if (!ai_is_configured()) {
        $result = ['success' => false, 'error' => 'AI provider is not configured.'];
    } else {
        $result = ai_summarize_scan_job_batch($scanId, $customerId, (int)ai_env('AI_SUMMARY_BATCH_SIZE', '2'));
    }
} elseif ($action === 'summarize_page') {
    $pageId = trim((string)($_POST['page_id'] ?? $_GET['page_id'] ?? ''));
    if (!ai_is_configured()) {
        $result = ['success' => false, 'error' => 'AI provider is not configured.'];
    } elseif ((string)($scan['status'] ?? '') === 'paused') {
        $result = ['success' => true, 'paused' => true, 'error' => 'Workflow is paused.'];
    } else {
        $result = ai_summarize_scanned_page($pageId, $customerId);
    }
} elseif ($action === 'pause_scan') {
    ai_pause_scan_job($scanId, $customerId);
    $result = ['success' => true, 'error' => ''];
} elseif ($action === 'resume_scan') {
    ai_resume_scan_job($scanId, $customerId);
    if (ai_external_queue_enabled()) {
        ai_enqueue_external_job($scanId, 'scan', 5);
    }
    $result = ['success' => true, 'error' => ''];
} elseif ($action === 'backfill_faqs') {
    $result = ['success' => true, 'error' => 'FAQ capture is disabled for the summarization workflow.'];
} elseif ($action === 'add_page') {
    $pageUrl = trim((string)($_POST['page_url'] ?? ''));
    $result = ai_capture_single_page($scanId, $customerId, (string)($scan['website_domain'] ?? ''), $pageUrl);
} elseif ($action === 'save_summary') {
    $pageId = trim((string)($_POST['page_id'] ?? ''));
    $result = ai_update_page_summary($pageId, $customerId, (string)($_POST['summary_text'] ?? ''));
} elseif ($action === 'save_faq') {
    $result = ai_update_faq(
        trim((string)($_POST['faq_id'] ?? '')),
        $customerId,
        (string)($_POST['question'] ?? ''),
        (string)($_POST['answer'] ?? '')
    );
} elseif ($action === 'add_faq') {
    $question = ai_clean_customer_text((string)($_POST['question'] ?? ''), 800);
    $answer = ai_clean_customer_text((string)($_POST['answer'] ?? ''), 3000);
    if ($question === '' || $answer === '') {
        $result = ['success' => false, 'error' => 'Question and answer are required.'];
    } else {
        $signature = ai_faq_signature($question);
        $existing = ai_safe_rows(supabase(
            'GET',
            'ai_website_faqs?select=id,question&customer_id=eq.' . urlencode($customerId) . '&limit=2000'
        ));
        foreach ($existing as $row) {
            if (ai_faq_signature((string)($row['question'] ?? '')) === $signature) {
                ai_worker_json(['success' => false, 'error' => 'That FAQ question already exists.']);
            }
        }
        supabase('POST', 'ai_website_faqs', [[
            'scan_job_id' => $scanId,
            'customer_id' => $customerId,
            'page_url' => (string)($_POST['page_url'] ?? $scan['website_url'] ?? ''),
            'question' => $question,
            'answer' => $answer,
            'source' => 'manual'
        ]]);
        $result = ['success' => true, 'error' => ''];
    }
}

$freshScan = ai_get_scan_job_for_customer($scanId, $customerId);
$counts = ai_scan_job_counts($scanId, $customerId);

ai_worker_json(array_merge($result, [
    'scan' => [
        'id' => $scanId,
        'status' => (string)($freshScan['status'] ?? $scan['status'] ?? ''),
        'pages_requested' => (int)($freshScan['pages_requested'] ?? $scan['pages_requested'] ?? 0),
        'pages_scanned' => (int)($freshScan['pages_scanned'] ?? 0),
        'pages_failed' => (int)($freshScan['pages_failed'] ?? 0),
        'error_message' => (string)($freshScan['error_message'] ?? ''),
    ],
    'counts' => $counts,
    'diagnostics' => ai_scan_diagnostics($scanId, $customerId),
    'pages' => ai_scan_review_pages($scanId, $customerId),
    'faqs' => ai_scan_review_faqs($scanId, $customerId),
]));
