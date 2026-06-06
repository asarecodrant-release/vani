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

if ($action === 'scan_batch') {
    $result = ai_process_scan_job_batch($scanId, $customerId, (int)ai_env('AI_CRAWL_BATCH_SIZE', '8'));
} elseif ($action === 'summarize_batch') {
    if (!ai_is_configured()) {
        $result = ['success' => false, 'error' => 'AI provider is not configured.'];
    } else {
        $result = ai_summarize_scan_job_batch($scanId, $customerId, (int)ai_env('AI_SUMMARY_BATCH_SIZE', '2'));
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
]));
