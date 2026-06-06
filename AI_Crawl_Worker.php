<?php
require_once __DIR__ . '/ai_service.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$started = time();
$maxSeconds = max(10, (int)ai_env('AI_WORKER_MAX_SECONDS', '55'));
$jobLimit = max(1, min(20, (int)ai_env('AI_WORKER_JOB_LIMIT', '8')));
$sleepMs = max(0, (int)ai_env('AI_WORKER_IDLE_SLEEP_MS', '500'));
$didWork = 0;

function ai_worker_log(string $message): void {
    echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL;
}

while (time() - $started < $maxSeconds) {
    $roundWork = 0;

    foreach (ai_active_scan_jobs($jobLimit) as $scan) {
        if (time() - $started >= $maxSeconds) {
            break;
        }
        $result = ai_process_scan_job_batch(
            (string)$scan['id'],
            (string)$scan['customer_id'],
            (int)ai_env('AI_CRAWL_BATCH_SIZE', '4')
        );
        $processed = (int)($result['processed'] ?? 0);
        $roundWork += $processed;
        $didWork += $processed;
        ai_worker_log('scan ' . $scan['id'] . ' status=' . (string)($result['status'] ?? '') . ' processed=' . $processed);
    }

    if (ai_is_configured()) {
        foreach (ai_completed_scan_jobs($jobLimit) as $scan) {
            if (time() - $started >= $maxSeconds) {
                break;
            }
            $result = ai_summarize_scan_job_batch(
                (string)$scan['id'],
                (string)$scan['customer_id'],
                (int)ai_env('AI_SUMMARY_BATCH_SIZE', '2')
            );
            $summarized = (int)($result['summarized'] ?? 0);
            $roundWork += $summarized;
            $didWork += $summarized;
            if ($summarized > 0 || (int)($result['failed'] ?? 0) > 0 || (int)($result['deferred'] ?? 0) > 0) {
                ai_worker_log('summary ' . $scan['id'] . ' summarized=' . $summarized . ' failed=' . (int)($result['failed'] ?? 0) . ' deferred=' . (int)($result['deferred'] ?? 0));
            }
        }
    }

    if ($roundWork === 0) {
        if ($sleepMs <= 0) {
            break;
        }
        usleep($sleepMs * 1000);
    }
}

ai_worker_log('worker done units=' . $didWork);
