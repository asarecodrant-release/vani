<?php
require_once __DIR__ . '/ai_service.php';

header('Content-Type: application/json');

function ai_cron_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$configuredToken = ai_env('AI_WORKER_TOKEN');
$providedToken = trim((string)($_SERVER['HTTP_X_WORKER_TOKEN'] ?? $_POST['worker_token'] ?? $_GET['worker_token'] ?? ''));
if ($configuredToken === '' || !hash_equals($configuredToken, $providedToken)) {
    ai_cron_json(['success' => false, 'error' => 'Worker token required.'], 401);
}

$maxJobs = max(1, min(20, (int)($_POST['jobs'] ?? $_GET['jobs'] ?? ai_env('AI_WORKER_JOB_LIMIT', '8'))));
$scanUnits = 0;
$summaryUnits = 0;
$details = [];
$recovered = ai_recover_stuck_scan_jobs();

foreach (ai_active_scan_jobs($maxJobs) as $scan) {
    $result = ai_process_scan_job_batch(
        (string)$scan['id'],
        (string)$scan['customer_id'],
        (int)ai_env('AI_CRAWL_BATCH_SIZE', '8')
    );
    $scanUnits += (int)($result['processed'] ?? 0);
    $details[] = ['type' => 'scan', 'id' => (string)$scan['id'], 'result' => $result];
}

if (ai_is_configured()) {
    foreach (ai_completed_scan_jobs($maxJobs) as $scan) {
        $result = ai_summarize_scan_job_batch(
            (string)$scan['id'],
            (string)$scan['customer_id'],
            (int)ai_env('AI_SUMMARY_BATCH_SIZE', '2')
        );
        $summaryUnits += (int)($result['summarized'] ?? 0);
        if ($summaryUnits > 0 || (int)($result['failed'] ?? 0) > 0 || (int)($result['deferred'] ?? 0) > 0) {
            $details[] = ['type' => 'summary', 'id' => (string)$scan['id'], 'result' => $result];
        }
    }
}

ai_cron_json([
    'success' => true,
    'recovered_stuck_jobs' => $recovered,
    'scan_units' => $scanUnits,
    'summary_units' => $summaryUnits,
    'details' => $details,
]);
