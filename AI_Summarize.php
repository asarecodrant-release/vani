<?php
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/ai_service.php';

if (!is_authenticated_user()) {
    $_SESSION['auth_return_to'] = 'AI_Summarize.php';
    header('Location: login.php?setup=ai_chatbot&return_to=AI_Summarize.php');
    exit;
}

if (!empty($_SESSION['must_reset_password'])) {
    header('Location: forgot-password.php?forced=1');
    exit;
}

function ai_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ai_summary_text_from_page(array $page): string {
    $summary = $page['summary_json'] ?? [];
    if (is_string($summary)) {
        $decoded = json_decode($summary, true);
        $summary = is_array($decoded) ? $decoded : [];
    }
    return trim((string)($summary['summary'] ?? ''));
}

function ai_short_url_label(string $url): string {
    $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
    $query = (string)(parse_url($url, PHP_URL_QUERY) ?: '');
    $label = $path . ($query !== '' ? '?' . $query : '');
    return $label === '/' ? $url : $label;
}

function ai_time_label($value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }
    $diff = time() - $ts;
    if ($diff >= 0 && $diff < 60) {
        return $diff . 's ago';
    }
    if ($diff >= 60 && $diff < 3600) {
        return floor($diff / 60) . 'm ago';
    }
    if ($diff >= 3600 && $diff < 86400) {
        return floor($diff / 3600) . 'h ago';
    }
    return gmdate('Y-m-d H:i', $ts) . ' UTC';
}

function ai_text_length_from_page(array $page): int {
    return strlen(trim((string)($page['clean_text'] ?? '')));
}

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
    header('Location: AI_Chatbot_Setup.php');
    exit;
}

$scanId = trim((string)($_GET['scan'] ?? $_SESSION['ai_scan_job_id'] ?? ''));
$scanRows = [];
if ($scanId !== '') {
    $scanRows = ai_safe_rows(supabase(
        'GET',
        'ai_scan_jobs?select=*&id=eq.' . urlencode($scanId) . '&customer_id=eq.' . urlencode($customerId) . '&limit=1'
    ));
}
if (empty($scanRows)) {
    $scanRows = ai_safe_rows(supabase(
        'GET',
        'ai_scan_jobs?select=*&customer_id=eq.' . urlencode($customerId) . '&order=created_at.desc&limit=1'
    ));
}
$scan = $scanRows[0] ?? [];
if (empty($scan)) {
    header('Location: AI_Chatbot_Setup.php');
    exit;
}
$scanId = (string)$scan['id'];
$_SESSION['ai_scan_job_id'] = $scanId;
if (empty($_SESSION['ai_worker_csrf'])) {
    $_SESSION['ai_worker_csrf'] = bin2hex(random_bytes(24));
}
$workerCsrf = (string)$_SESSION['ai_worker_csrf'];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $pageId = trim((string)($_POST['page_id'] ?? ''));

    if ($action === 'summarize_page') {
        if (!ai_is_configured()) {
            $error = 'AI provider is not configured.';
        } else {
            $result = ai_summarize_scanned_page($pageId, $customerId);
            $message = !empty($result['success']) ? 'Page summarized.' : '';
            $error = empty($result['success']) ? (string)$result['error'] : '';
        }
    } elseif ($action === 'summarize_all') {
        if (!ai_is_configured()) {
            $error = 'AI provider is not configured.';
        } else {
            $message = 'Summarization will run in the background on this page.';
        }
    } elseif ($action === 'add_page') {
        $pageUrl = trim((string)($_POST['page_url'] ?? ''));
        $result = ai_capture_single_page($scanId, $customerId, (string)$scan['website_domain'], $pageUrl);
        if (!empty($result['success'])) {
            $message = 'Page captured. You can summarize it now.';
        } elseif (!empty($result['page_id'])) {
            $message = 'Page added, but crawler could not capture readable text. Add the summary manually.';
        } else {
            $error = (string)$result['error'];
        }
    } elseif ($action === 'save_summary') {
        $result = ai_update_page_summary($pageId, $customerId, (string)($_POST['summary_text'] ?? ''));
        $message = !empty($result['success']) ? 'Summary saved.' : '';
        $error = empty($result['success']) ? (string)$result['error'] : '';
    } elseif ($action === 'save_faq') {
        $result = ai_update_faq(
            trim((string)($_POST['faq_id'] ?? '')),
            $customerId,
            (string)($_POST['question'] ?? ''),
            (string)($_POST['answer'] ?? '')
        );
        $message = !empty($result['success']) ? 'FAQ saved.' : '';
        $error = empty($result['success']) ? (string)$result['error'] : '';
    } elseif ($action === 'add_faq') {
        $question = trim((string)($_POST['question'] ?? ''));
        $answer = trim((string)($_POST['answer'] ?? ''));
        if ($question === '' || $answer === '') {
            $error = 'Question and answer are required.';
        } else {
            supabase('POST', 'ai_website_faqs', [[
                'scan_job_id' => $scanId,
                'customer_id' => $customerId,
                'page_url' => (string)($_POST['page_url'] ?? $scan['website_url'] ?? ''),
                'question' => $question,
                'answer' => $answer,
                'source' => 'manual'
            ]]);
            $message = 'FAQ added.';
        }
    }
}

$pages = ai_scan_review_pages($scanId, $customerId);
$faqs = ai_scan_review_faqs($scanId, $customerId);

$summarizedCount = 0;
foreach ($pages as $page) {
    if ((string)($page['page_status'] ?? '') === 'summarized') {
        $summarizedCount++;
    }
}
$diagnostics = ai_scan_diagnostics($scanId, $customerId);
$diagScan = $diagnostics['scan'] ?: $scan;
$diagCounts = $diagnostics['counts'];
$workerLockedUntil = (string)($diagScan['locked_until'] ?? '');
$workerActive = $workerLockedUntil !== '' && strtotime($workerLockedUntil) !== false && strtotime($workerLockedUntil) > time();
$activeScanUrl = (string)($diagnostics['claimed'][0]['url'] ?? '');
$lastActivity = ai_time_label($diagScan['updated_at'] ?? '');
$crawlPercent = (int)($diagCounts['crawl_percent'] ?? 0);
$summaryPercent = (int)($diagCounts['summary_percent'] ?? 0);
$crawlDone = (int)($diagCounts['crawl_done'] ?? 0);
$crawlTotal = (int)($diagCounts['crawl_total'] ?? 0);
$summaryDone = (int)($diagCounts['summary_done'] ?? 0);
$summaryTotal = (int)($diagCounts['summary_total'] ?? 0);
$externalQueueEnabled = ai_external_queue_enabled();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/logo_img.png">
<title>AI Summarize - Vani AI</title>
<link rel="stylesheet" href="css/public-theme.css">
<script defer src="js/public-theme.js"></script>
<style>
:root{--bg:#f6f8fb;--panel:#fff;--ink:#0f172a;--muted:#64748b;--line:#dbe3ee;--soft:#f8fafc;--link:#1d4ed8;--field:#fff}
body.dark{--bg:#07111f;--panel:#0f1b2d;--ink:#f8fafc;--muted:#a8b3c7;--line:#26364f;--soft:#15243a;--link:#7dd3fc;--field:#0b1728}
*{box-sizing:border-box;font-family:Inter,Arial,sans-serif}
body{margin:0;background:var(--bg);color:var(--ink)}
.shell{max-width:1280px;margin:0 auto;padding:34px 18px 70px}
.top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:22px}
.top h1{margin:0;font-size:32px;line-height:1.15}
.top p{margin:8px 0 0;color:var(--muted);line-height:1.6}
.actions{display:flex;gap:10px;flex-wrap:wrap}
button,.btn{min-height:42px;border:0;border-radius:8px;padding:0 14px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
button{background:#2563eb;color:#fff}
.btn{background:#e2e8f0;color:#0f172a}
.grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(340px,.75fr);gap:18px;align-items:start}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:18px;box-shadow:0 12px 34px rgba(15,23,42,.06)}
.pages-panel{min-width:0}
.faq-panel{position:sticky;top:18px;max-height:calc(100vh - 36px);overflow:auto}
.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:18px}
.metric{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:14px}
.metric span{display:block;color:var(--muted);font-size:12px;font-weight:800}
.metric strong{display:block;margin-top:6px;font-size:22px}
.message{margin-bottom:14px;padding:12px 14px;border-radius:8px;font-weight:800}
.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}
.page-tabs-wrap{position:relative;margin-bottom:16px}
.page-tabs-meta{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:8px;color:var(--muted);font-size:12px;font-weight:800}
.page-tabs{display:flex;gap:8px;overflow-x:auto;overflow-y:hidden;max-width:100%;padding:4px 0 12px;border-bottom:1px solid var(--line);scroll-snap-type:x proximity;scrollbar-width:thin}
.page-tab-label{min-height:38px;display:inline-flex;align-items:center;gap:8px;flex:0 0 auto;max-width:210px;padding:0 12px;border:1px solid var(--line);border-radius:8px;background:var(--soft);color:var(--ink);font-size:13px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;scroll-snap-align:start}
.page-tab-label.is-selected{background:#2563eb;border-color:#2563eb;color:#fff}
.tab-number{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;border-radius:999px;background:rgba(148,163,184,.2);font-size:12px}
.page-tab-label.is-selected .tab-number{background:rgba(255,255,255,.22);color:#fff}
.tab-title{overflow:hidden;text-overflow:ellipsis}
.add-tab{min-width:42px;justify-content:center;font-size:20px}
.page-panel{display:none}
.page-panel.is-active{display:block}
.page-item{padding:6px 0}
.page-title{display:block;color:var(--ink);font-size:18px;line-height:1.35;margin-bottom:4px}
.url{color:var(--link);word-break:break-all;font-size:13px}
.status{display:inline-flex;margin:8px 0;padding:4px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px;font-weight:800}
textarea,input{width:100%;border:1px solid var(--line);border-radius:8px;padding:10px 12px;font:inherit;background:var(--field);color:var(--ink)}
textarea{min-height:120px;resize:vertical;line-height:1.5}
.row{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.summary-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;align-items:center}
.summary-actions form{margin:0}
.faq,.manual-page{border-top:1px solid var(--line);padding:14px 0}
.faq textarea{min-height:96px}
.faq:first-child{border-top:0}
.muted{color:var(--muted);font-size:13px;line-height:1.5}
.progress-strip{display:grid;gap:8px;border:1px solid var(--line);background:var(--panel);border-radius:8px;padding:12px 14px;margin-bottom:16px}
.progress-row{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.progress-strip strong{display:block;font-size:14px}
.progress-strip span{color:var(--muted);font-size:13px}
.progress-bar{height:8px;width:100%;border-radius:999px;background:var(--soft);overflow:hidden}
.progress-bar i{display:block;height:100%;width:0;background:#2563eb;transition:width .25s ease}
.summary-row{margin-top:4px}
.summary-progress i{background:#16a34a}
.diagnostics{margin-bottom:18px}
.diagnostics summary{cursor:pointer;font-weight:900;display:flex;align-items:center;justify-content:space-between;gap:12px}
.diag-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}
.diag-card{border:1px solid var(--line);border-radius:8px;background:var(--soft);padding:12px;min-width:0}
.diag-card span{display:block;color:var(--muted);font-size:11px;font-weight:900;text-transform:uppercase}
.diag-card strong{display:block;margin-top:5px;font-size:16px;word-break:break-word}
.diag-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.diag-section{margin-top:16px}
.diag-section h3{margin:0 0 8px;font-size:15px}
.diag-table{width:100%;border-collapse:collapse;font-size:12px}
.diag-table th,.diag-table td{border-top:1px solid var(--line);padding:8px 6px;text-align:left;vertical-align:top}
.diag-table th{color:var(--muted);font-size:11px;text-transform:uppercase}
.diag-url{max-width:360px;word-break:break-all;color:var(--link)}
.diag-error{max-width:420px;color:#991b1b;word-break:break-word}
body.dark .diag-error{color:#fca5a5}
.diag-pill{display:inline-flex;align-items:center;min-height:22px;border-radius:999px;padding:0 8px;background:#e0f2fe;color:#075985;font-weight:900}
.diag-pill.warn{background:#fef3c7;color:#92400e}
.diag-pill.bad{background:#fee2e2;color:#991b1b}
.diag-live{display:flex;align-items:center;gap:8px;min-width:0}
.diag-spinner{display:none;width:14px;height:14px;border:2px solid rgba(37,99,235,.24);border-top-color:#2563eb;border-radius:999px;animation:diag-spin .8s linear infinite}
.diag-live.is-running .diag-spinner{display:inline-block}
.diag-live-text{display:inline-block;max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted);font-size:12px;font-weight:800}
@keyframes diag-spin{to{transform:rotate(360deg)}}
@media(max-width:1100px){.grid{grid-template-columns:minmax(0,1fr) minmax(300px,.72fr)}.page-tab-label{max-width:170px}}
@media(max-width:860px){.grid,.metrics,.diag-grid{grid-template-columns:1fr}.top{display:grid}.faq-panel{position:static;max-height:none}.page-tab-label{max-width:180px}.shell{padding:26px 14px 56px}.diag-table{display:block;overflow-x:auto;white-space:nowrap}}
@media(max-width:560px){.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.panel{padding:14px}.top h1{font-size:26px}.page-tabs-meta{display:grid}.page-tab-label{max-width:150px}.summary-actions button,.summary-actions form{width:100%}.summary-actions button{width:100%}}
</style>
</head>
<body>
<?php include 'navbar.php'; ?>
<main class="shell" data-scan-id="<?php echo ai_h($scanId); ?>" data-scan-status="<?php echo ai_h($scan['status'] ?? ''); ?>" data-worker-csrf="<?php echo ai_h($workerCsrf); ?>" data-external-queue="<?php echo $externalQueueEnabled ? '1' : '0'; ?>">
  <div class="top">
    <div>
      <h1>Review captured website pages</h1>
      <p><?php echo ai_h($scan['website_domain'] ?? ''); ?> pages are captured first. Summaries and FAQs can be reviewed and edited here.</p>
    </div>
    <div class="actions">
      <button type="button" id="summarizeAllBtn">Summarize all pages</button>
      <button class="btn" type="button" data-theme-toggle>Bright Mode</button>
      <a class="btn" href="AI_Chatbot_Setup.php">Scan another website</a>
    </div>
  </div>

  <?php if ($message !== ''): ?><div class="message success"><?php echo ai_h($message); ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="message error"><?php echo ai_h($error); ?></div><?php endif; ?>

  <div class="progress-strip" aria-live="polite">
    <div class="progress-row">
      <div>
        <strong id="crawlTitle">Crawling <?php echo ai_h($scan['status'] ?? 'pending'); ?></strong>
        <span id="crawlText"><?php echo (int)$crawlDone; ?> of <?php echo (int)$crawlTotal; ?> queued page(s) crawled.</span>
      </div>
      <strong id="crawlPercent"><?php echo (int)$crawlPercent; ?>%</strong>
    </div>
    <div class="progress-bar" aria-hidden="true"><i id="crawlBar" style="width:<?php echo (int)$crawlPercent; ?>%"></i></div>
    <div class="progress-row summary-row">
      <div>
        <strong id="summaryTitle">Summarizing</strong>
        <span id="summaryText"><?php echo (int)$summaryDone; ?> of <?php echo (int)$summaryTotal; ?> captured page(s) summarized.</span>
      </div>
      <strong id="summaryPercent"><?php echo (int)$summaryPercent; ?>%</strong>
    </div>
    <div class="progress-bar summary-progress" aria-hidden="true"><i id="summaryBar" style="width:<?php echo (int)$summaryPercent; ?>%"></i></div>
  </div>

  <details class="panel diagnostics" <?php echo !empty($diagnostics['claimed']) || !empty($diagnostics['failed']) || !empty($diagnostics['waiting_retry']) ? 'open' : ''; ?>>
    <summary>
      <span>Crawler Diagnostics</span>
      <span class="diag-live <?php echo $workerActive ? 'is-running' : ''; ?>" id="diagLive">
        <i class="diag-spinner" aria-hidden="true"></i>
        <span class="diag-live-text" id="diagLiveText"><?php echo $activeScanUrl !== '' ? 'Scanning: ' . ai_short_url_label($activeScanUrl) : ($workerActive ? 'Crawler working' : 'Crawler idle'); ?></span>
        <span class="diag-pill <?php echo $workerActive ? '' : 'warn'; ?>" id="diagLivePill"><?php echo $activeScanUrl !== '' ? 'Page locked' : ($workerActive ? 'Worker active' : 'No active lock'); ?></span>
      </span>
    </summary>

    <div class="diag-grid">
      <div class="diag-card"><span>Status</span><strong id="diagStatus"><?php echo ai_h($diagScan['status'] ?? ''); ?></strong></div>
      <div class="diag-card"><span>Crawling</span><strong id="diagCrawlPercent"><?php echo (int)$crawlPercent; ?>%</strong></div>
      <div class="diag-card"><span>Summarizing</span><strong id="diagSummaryPercent"><?php echo (int)$summaryPercent; ?>%</strong></div>
      <div class="diag-card"><span>Last Activity</span><strong id="diagLastActivity"><?php echo ai_h($lastActivity); ?></strong></div>
      <div class="diag-card"><span>Worker</span><strong id="diagWorker"><?php echo ai_h(($diagScan['worker_id'] ?? '') ?: '-'); ?></strong></div>
      <div class="diag-card"><span>Requested</span><strong id="diagRequested"><?php echo (int)($diagScan['pages_requested'] ?? 0); ?></strong></div>
      <div class="diag-card"><span>Pending</span><strong id="diagPending"><?php echo (int)($diagCounts['pending'] ?? 0); ?></strong></div>
      <div class="diag-card"><span>Fetched</span><strong id="diagFetched"><?php echo (int)($diagCounts['fetched'] ?? 0); ?></strong></div>
      <div class="diag-card"><span>Summarized</span><strong id="diagSummarized"><?php echo (int)($diagCounts['summarized'] ?? 0); ?></strong></div>
      <div class="diag-card"><span>Failed</span><strong id="diagFailed"><?php echo (int)($diagCounts['failed'] ?? 0); ?></strong></div>
      <div class="diag-card"><span>Lock Expires</span><strong id="diagLockExpires"><?php echo ai_h(ai_time_label($diagScan['locked_until'] ?? '')); ?></strong></div>
      <div class="diag-card"><span>Crawl Batch</span><strong><?php echo (int)$diagnostics['settings']['crawl_batch_size']; ?> / <?php echo (int)$diagnostics['settings']['crawl_concurrency']; ?></strong></div>
      <div class="diag-card"><span>Render Service</span><strong><?php echo !empty($diagnostics['settings']['render_enabled']) ? 'Enabled' : 'Off'; ?></strong></div>
    </div>

    <div class="diag-actions">
      <button type="button" id="runScanBatchBtn">Run scan batch</button>
      <button type="button" id="runSummaryBatchBtn">Run summary batch</button>
      <button class="btn" type="button" id="refreshLiveBtn">Refresh live data</button>
    </div>

    <div class="diag-section">
      <h3>Currently Scanning</h3>
      <table class="diag-table">
        <thead><tr><th>URL</th><th>Worker</th><th>Priority</th><th>Attempts</th><th>Lock Expires</th></tr></thead>
        <tbody id="diagClaimedRows">
        <?php foreach ($diagnostics['claimed'] as $row): ?>
          <tr>
            <td class="diag-url" title="<?php echo ai_h($row['url'] ?? ''); ?>"><?php echo ai_h(ai_short_url_label((string)($row['url'] ?? ''))); ?></td>
            <td><?php echo ai_h(($row['page_worker_id'] ?? '') ?: '-'); ?></td>
            <td><?php echo ai_h($row['priority'] ?? ''); ?></td>
            <td><?php echo ai_h($row['crawl_attempts'] ?? '0'); ?></td>
            <td><?php echo ai_h(ai_time_label($row['page_locked_until'] ?? '')); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($diagnostics['claimed'])): ?><tr><td colspan="5" class="muted">No page is currently locked by a worker.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="diag-section">
      <h3>Next Pending URLs</h3>
      <table class="diag-table">
        <thead><tr><th>URL</th><th>Signal</th><th>Attempts</th><th>Next Retry</th><th>Error</th></tr></thead>
        <tbody id="diagPendingRows">
        <?php foreach ($diagnostics['pending'] as $row): ?>
          <tr>
            <td class="diag-url" title="<?php echo ai_h($row['url'] ?? ''); ?>"><?php echo ai_h(ai_short_url_label((string)($row['url'] ?? ''))); ?></td>
            <td><span class="diag-pill"><?php echo ai_h($row['diagnostic_label'] ?? 'queued'); ?></span></td>
            <td><?php echo ai_h($row['crawl_attempts'] ?? '0'); ?></td>
            <td><?php echo ai_h(ai_time_label($row['next_retry_at'] ?? '')); ?></td>
            <td class="diag-error"><?php echo ai_h($row['ai_error'] ?? ''); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($diagnostics['pending'])): ?><tr><td colspan="5" class="muted">No pending pages.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="diag-section">
      <h3>Failed Pages</h3>
      <table class="diag-table">
        <thead><tr><th>URL</th><th>Signal</th><th>HTTP</th><th>Attempts</th><th>Error</th><th>Updated</th></tr></thead>
        <tbody id="diagFailedRows">
        <?php foreach ($diagnostics['failed'] as $row): ?>
          <tr>
            <td class="diag-url" title="<?php echo ai_h($row['url'] ?? ''); ?>"><?php echo ai_h(ai_short_url_label((string)($row['url'] ?? ''))); ?></td>
            <td><span class="diag-pill bad"><?php echo ai_h($row['diagnostic_label'] ?? 'failed'); ?></span></td>
            <td><?php echo ai_h($row['http_status'] ?? ''); ?></td>
            <td><?php echo ai_h($row['crawl_attempts'] ?? '0'); ?></td>
            <td class="diag-error"><?php echo ai_h($row['ai_error'] ?? ''); ?></td>
            <td><?php echo ai_h(ai_time_label($row['updated_at'] ?? '')); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($diagnostics['failed'])): ?><tr><td colspan="6" class="muted">No failed pages.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="diag-section">
      <h3>Retry Waits</h3>
      <table class="diag-table">
        <thead><tr><th>Type</th><th>URL</th><th>Attempts</th><th>Next Retry</th><th>Error</th></tr></thead>
        <tbody id="diagRetryRows">
        <?php foreach ($diagnostics['waiting_retry'] as $row): ?>
          <tr>
            <td><span class="diag-pill warn">crawl</span></td>
            <td class="diag-url" title="<?php echo ai_h($row['url'] ?? ''); ?>"><?php echo ai_h(ai_short_url_label((string)($row['url'] ?? ''))); ?></td>
            <td><?php echo ai_h($row['crawl_attempts'] ?? '0'); ?></td>
            <td><?php echo ai_h(ai_time_label($row['next_retry_at'] ?? '')); ?></td>
            <td class="diag-error"><?php echo ai_h($row['ai_error'] ?? ''); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($diagnostics['waiting_summary_retry'] as $row): ?>
          <tr>
            <td><span class="diag-pill warn">summary</span></td>
            <td class="diag-url" title="<?php echo ai_h($row['url'] ?? ''); ?>"><?php echo ai_h(ai_short_url_label((string)($row['url'] ?? ''))); ?></td>
            <td><?php echo ai_h($row['summary_attempts'] ?? '0'); ?></td>
            <td><?php echo ai_h(ai_time_label($row['summary_next_retry_at'] ?? '')); ?></td>
            <td class="diag-error"><?php echo ai_h($row['ai_error'] ?? ''); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($diagnostics['waiting_retry']) && empty($diagnostics['waiting_summary_retry'])): ?><tr><td colspan="5" class="muted">No pages are waiting for retry.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="diag-section">
      <h3>Recent Activity</h3>
      <table class="diag-table">
        <thead><tr><th>Status</th><th>Signal</th><th>URL</th><th>HTTP</th><th>Text/Bytes</th><th>Links</th><th>Time</th></tr></thead>
        <tbody id="diagRecentRows">
        <?php foreach ($diagnostics['recent_fetched'] as $row): ?>
          <tr>
            <td><span class="diag-pill"><?php echo ai_h($row['page_status'] ?? ''); ?></span></td>
            <td><span class="diag-pill"><?php echo ai_h($row['diagnostic_label'] ?? 'ok'); ?></span></td>
            <td class="diag-url" title="<?php echo ai_h($row['url'] ?? ''); ?>"><?php echo ai_h(ai_short_url_label((string)($row['url'] ?? ''))); ?></td>
            <td><?php echo ai_h($row['http_status'] ?? ''); ?></td>
            <td><?php echo ai_h($row['content_length'] ?? '0'); ?></td>
            <td><?php echo ai_h($row['discovered_links_count'] ?? '0'); ?></td>
            <td><?php echo ai_h(ai_time_label($row['fetched_at'] ?? $row['updated_at'] ?? '')); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($diagnostics['recent_fetched'])): ?><tr><td colspan="7" class="muted">No fetched pages yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="diag-section">
      <h3>Recent Crawler Signals</h3>
      <table class="diag-table">
        <thead><tr><th>Signal</th><th>URL</th><th>Message</th><th>Time</th></tr></thead>
        <tbody id="diagLogRows">
        <?php foreach ($diagnostics['logs'] as $row): ?>
          <tr>
            <td><span class="diag-pill <?php echo ($row['severity'] ?? '') === 'error' ? 'bad' : (($row['severity'] ?? '') === 'warning' ? 'warn' : ''); ?>"><?php echo ai_h($row['event_type'] ?? ''); ?></span></td>
            <td class="diag-url" title="<?php echo ai_h($row['url'] ?? ''); ?>"><?php echo ai_h(ai_short_url_label((string)($row['url'] ?? ''))); ?></td>
            <td class="diag-error"><?php echo ai_h($row['message'] ?? ''); ?></td>
            <td><?php echo ai_h(ai_time_label($row['created_at'] ?? '')); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($diagnostics['logs'])): ?><tr><td colspan="4" class="muted">No crawler signals yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="diag-section">
      <h3>Content Quality Watchlist</h3>
      <table class="diag-table">
        <thead><tr><th>URL</th><th>Status</th><th>Clean Text</th><th>Signal</th></tr></thead>
        <tbody id="diagQualityRows">
        <?php foreach ($diagnostics['quality'] as $row): ?>
          <?php $textLength = ai_text_length_from_page($row); ?>
          <tr>
            <td class="diag-url" title="<?php echo ai_h($row['url'] ?? ''); ?>"><?php echo ai_h(ai_short_url_label((string)($row['url'] ?? ''))); ?></td>
            <td><?php echo ai_h($row['page_status'] ?? ''); ?></td>
            <td><?php echo (int)$textLength; ?></td>
            <td>
              <?php if ($textLength < 300): ?>
                <span class="diag-pill bad">very low text</span>
              <?php elseif ($textLength < 900): ?>
                <span class="diag-pill warn">short text</span>
              <?php else: ?>
                <span class="diag-pill">ok</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($diagnostics['quality'])): ?><tr><td colspan="4" class="muted">No content quality data yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </details>

  <div class="metrics">
    <div class="metric"><span>Captured Pages</span><strong id="capturedMetric"><?php echo count($pages); ?></strong></div>
    <div class="metric"><span>Summarized</span><strong id="summarizedMetric"><?php echo $summarizedCount; ?></strong></div>
    <div class="metric"><span>Captured FAQs</span><strong id="faqMetric"><?php echo count($faqs); ?></strong></div>
    <div class="metric"><span>Scan Status</span><strong id="scanStatusMetric"><?php echo ai_h($scan['status'] ?? ''); ?></strong></div>
  </div>

  <div class="grid">
    <section class="panel pages-panel">
      <h2>Pages</h2>
      <?php if (empty($pages)): ?>
        <p class="muted">No pages were captured. Check scan diagnostics or try a sitemap/manual content source.</p>
      <?php endif; ?>
      <div class="page-tabs-wrap">
        <div class="page-tabs-meta">
          <span id="pageTabsCount"><?php echo count($pages); ?> page<?php echo count($pages) === 1 ? '' : 's'; ?> captured</span>
          <span>Scroll tabs sideways to review all pages</span>
        </div>
        <div class="page-tabs" id="pageTabs" role="tablist" aria-label="Captured pages">
          <?php foreach ($pages as $index => $page): ?>
            <?php $tabTitle = trim((string)($page['page_title'] ?? '')) ?: (parse_url((string)$page['url'], PHP_URL_PATH) ?: 'Untitled'); ?>
            <button class="page-tab-label js-page-tab <?php echo $index === 0 ? 'is-selected' : ''; ?>" type="button" data-page-id="<?php echo ai_h($page['id']); ?>" data-tab-target="page-panel-<?php echo ai_h($page['id']); ?>" title="<?php echo ai_h(($index + 1) . '. ' . $tabTitle); ?>">
              <span class="tab-number"><?php echo (int)($index + 1); ?></span>
              <span class="tab-title"><?php echo ai_h($tabTitle); ?></span>
            </button>
          <?php endforeach; ?>
          <button class="page-tab-label add-tab js-page-tab <?php echo empty($pages) ? 'is-selected' : ''; ?>" type="button" data-tab-target="page-panel-add" title="Add missed page">+</button>
        </div>
      </div>
      <div id="page-panel-add" class="add-page-panel page-panel <?php echo empty($pages) ? 'is-active' : ''; ?>">
        <form method="POST" class="manual-page">
          <input type="hidden" name="action" value="add_page">
          <label>Add missed page URL</label>
          <input name="page_url" placeholder="https://example.com/missed-page">
          <p class="muted">We will try to crawl this page. If readable text is not captured, you can still add the summary manually below.</p>
          <div class="row"><button type="submit">Add page</button></div>
        </form>
      </div>
      <div id="pagePanels">
      <?php foreach ($pages as $index => $page): ?>
        <?php $summaryText = ai_summary_text_from_page($page); ?>
        <article id="page-panel-<?php echo ai_h($page['id']); ?>" class="page-item page-panel <?php echo $index === 0 ? 'is-active' : ''; ?>" data-page-id="<?php echo ai_h($page['id']); ?>">
          <strong class="page-title"><?php echo ai_h($page['page_title'] ?: parse_url((string)$page['url'], PHP_URL_PATH) ?: 'Untitled page'); ?></strong>
          <div class="url"><?php echo ai_h($page['url']); ?></div>
          <span class="status"><?php echo ai_h($page['page_status']); ?></span>
          <p class="muted">
            HTTP <?php echo ai_h($page['http_status'] ?? ''); ?>,
            <?php echo ai_h($page['content_length'] ?? 0); ?> bytes,
            <?php echo ai_h($page['discovered_links_count'] ?? 0); ?> links found
          </p>

          <label>Editable summary</label>
          <textarea form="save-summary-<?php echo ai_h($page['id']); ?>" name="summary_text" placeholder="Summarize this page to generate editable summary."><?php echo ai_h($summaryText); ?></textarea>
          <div class="summary-actions">
            <form id="save-summary-<?php echo ai_h($page['id']); ?>" method="POST">
              <input type="hidden" name="action" value="save_summary">
              <input type="hidden" name="page_id" value="<?php echo ai_h($page['id']); ?>">
              <button type="submit">Save summary</button>
            </form>
            <form method="POST" class="js-summarize-page-form">
              <input type="hidden" name="action" value="summarize_page">
              <input type="hidden" name="page_id" value="<?php echo ai_h($page['id']); ?>">
              <button type="submit">Summarize this page</button>
            </form>
          </div>
          <?php if (!empty($page['ai_error'])): ?><p class="muted"><?php echo ai_h($page['ai_error']); ?></p><?php endif; ?>
        </article>
      <?php endforeach; ?>
      </div>
    </section>

    <aside class="panel faq-panel">
      <h2>Captured FAQs</h2>
      <p class="muted">FAQs detected from FAQ schema, HTML accordions, and AI extraction after summarizing FAQ-like pages.</p>
      <form method="POST" class="faq">
        <input type="hidden" name="action" value="add_faq">
        <input type="hidden" name="page_url" value="<?php echo ai_h($scan['website_url'] ?? ''); ?>">
        <label>Add FAQ</label>
        <input name="question" placeholder="Question">
        <textarea name="answer" placeholder="Answer"></textarea>
        <div class="row"><button type="submit">Add FAQ</button></div>
      </form>
      <div id="faqList">
        <?php foreach ($faqs as $faq): ?>
          <form method="POST" class="faq">
            <input type="hidden" name="action" value="save_faq">
            <input type="hidden" name="faq_id" value="<?php echo ai_h($faq['id']); ?>">
            <label>Question</label>
            <input name="question" value="<?php echo ai_h($faq['question']); ?>">
            <label>Answer</label>
            <textarea name="answer"><?php echo ai_h($faq['answer']); ?></textarea>
            <p class="muted">Source: <?php echo ai_h($faq['source']); ?></p>
            <div class="row"><button type="submit">Save FAQ</button></div>
          </form>
        <?php endforeach; ?>
      </div>
    </aside>
  </div>
</main>
<script>
const shell = document.querySelector(".shell");
const scanId = shell?.dataset.scanId || "";
const workerCsrf = shell?.dataset.workerCsrf || "";
const externalQueueEnabled = shell?.dataset.externalQueue === "1";
const pageTabs = document.getElementById("pageTabs");
const pagePanels = document.getElementById("pagePanels");
const pageTabsCount = document.getElementById("pageTabsCount");
const faqList = document.getElementById("faqList");
const crawlTitle = document.getElementById("crawlTitle");
const crawlText = document.getElementById("crawlText");
const crawlBar = document.getElementById("crawlBar");
const crawlPercent = document.getElementById("crawlPercent");
const summaryTitle = document.getElementById("summaryTitle");
const summaryText = document.getElementById("summaryText");
const summaryBar = document.getElementById("summaryBar");
const summaryPercent = document.getElementById("summaryPercent");
const capturedMetric = document.getElementById("capturedMetric");
const summarizedMetric = document.getElementById("summarizedMetric");
const faqMetric = document.getElementById("faqMetric");
const scanStatusMetric = document.getElementById("scanStatusMetric");
const summarizeAllBtn = document.getElementById("summarizeAllBtn");
const runScanBatchBtn = document.getElementById("runScanBatchBtn");
const runSummaryBatchBtn = document.getElementById("runSummaryBatchBtn");
const refreshLiveBtn = document.getElementById("refreshLiveBtn");
const diagLive = document.getElementById("diagLive");
const diagLiveText = document.getElementById("diagLiveText");
const diagLivePill = document.getElementById("diagLivePill");
let scanBusy = false;
let summaryBusy = false;
let liveStatusBusy = false;

function escapeHtml(value = "") {
  return String(value ?? "").replace(/[&<>"']/g, (char) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;"
  }[char]));
}

function cssEscape(value = "") {
  if (window.CSS && typeof window.CSS.escape === "function") return CSS.escape(String(value));
  return String(value).replace(/[^a-zA-Z0-9_-]/g, "\\$&");
}

function shortUrlLabel(url = "") {
  try {
    const parsed = new URL(url);
    return `${parsed.pathname || "/"}${parsed.search || ""}`;
  } catch (error) {
    return url || "";
  }
}

function summaryTextFromPage(page = {}) {
  const summary = page.summary_json || {};
  if (summary && typeof summary === "object" && typeof summary.summary === "string") return summary.summary;
  return "";
}

function pageTitle(page = {}) {
  try {
    return page.page_title || new URL(page.url || "").pathname || "Untitled page";
  } catch (error) {
    return page.page_title || "Untitled page";
  }
}

function selectPageTab(target, pageId = "") {
  document.querySelectorAll(".js-page-tab").forEach((item) => item.classList.remove("is-selected"));
  document.querySelectorAll(".page-panel").forEach((panel) => panel.classList.remove("is-active"));
  const tab = pageId ? document.querySelector(`.js-page-tab[data-page-id="${cssEscape(pageId)}"]`) : document.querySelector(`.js-page-tab[data-tab-target="${cssEscape(target)}"]`);
  tab?.classList.add("is-selected");
  document.getElementById(target)?.classList.add("is-active");
}

pageTabs?.addEventListener("click", (event) => {
  const tab = event.target.closest(".js-page-tab");
  if (!tab) return;
  selectPageTab(tab.dataset.tabTarget || "", tab.dataset.pageId || "");
});

function setDiagnosticsLive(isRunning, text, pillText) {
  diagLive?.classList.toggle("is-running", Boolean(isRunning));
  if (diagLiveText) diagLiveText.textContent = text || (isRunning ? "Crawler working" : "Crawler idle");
  if (diagLivePill) {
    diagLivePill.textContent = pillText || (isRunning ? "Worker active" : "No active lock");
    diagLivePill.classList.toggle("warn", !isRunning);
  }
}

function updateWorkerUi(data, mode = "scan") {
  const counts = data?.counts || {};
  const scan = data?.scan || {};
  const captured = Number(counts.scanned || 0);
  const summarized = Number(counts.summarized || 0);
  const failed = Number(counts.failed || 0);
  const crawlDone = Number(counts.crawl_done ?? (captured + failed));
  const crawlTotal = Number(counts.crawl_total ?? counts.total ?? 0);
  const summaryTotal = Number(counts.summary_total ?? captured);
  const summaryDone = Number(counts.summary_done ?? summarized);
  const crawlPct = Number(counts.crawl_percent ?? (crawlTotal > 0 ? Math.min(100, Math.round((crawlDone / crawlTotal) * 100)) : 0));
  const summaryPct = Number(counts.summary_percent ?? (summaryTotal > 0 ? Math.min(100, Math.round((summaryDone / summaryTotal) * 100)) : 0));
  if (crawlBar) crawlBar.style.width = `${crawlPct}%`;
  if (summaryBar) summaryBar.style.width = `${summaryPct}%`;
  if (crawlPercent) crawlPercent.textContent = `${crawlPct}%`;
  if (summaryPercent) summaryPercent.textContent = `${summaryPct}%`;
  if (capturedMetric) capturedMetric.textContent = String(captured);
  if (summarizedMetric) summarizedMetric.textContent = String(summarized);
  if (scanStatusMetric) scanStatusMetric.textContent = scan.status || "";
  if (crawlTitle) crawlTitle.textContent = `Crawling ${scan.status || "pending"}`;
  if (crawlText) crawlText.textContent = `${crawlDone} of ${crawlTotal} queued page(s) crawled. ${failed} failed, ${Number(counts.pending || 0)} still queued.`;
  if (summaryTitle) summaryTitle.textContent = mode === "summary" ? "Summarization running" : "Summarizing";
  if (summaryText) summaryText.textContent = `${summaryDone} of ${summaryTotal} captured page(s) summarized. ${Number(counts.summary_pending ?? (captured - summarized))} waiting.`;
  if (mode === "scan") {
    const activeUrl = data?.active_url || "";
    const processed = Number(data?.processed || 0);
    if (activeUrl) {
      setDiagnosticsLive(scan.status === "pending" || scan.status === "running", `Scanning: ${shortUrlLabel(activeUrl)}`, `${processed} processed`);
    } else if (data?.waiting_for_retry) {
      setDiagnosticsLive(false, "Waiting for retry window", "Retry wait");
    } else {
      setDiagnosticsLive(scan.status === "pending" || scan.status === "running", scan.status === "completed" ? "Crawler complete" : "Crawler working", scan.status || "");
    }
  }
}

async function callWorker(action, extra = {}) {
  const form = new FormData();
  form.append("scan", scanId);
  form.append("action", action);
  form.append("csrf", workerCsrf);
  Object.entries(extra).forEach(([key, value]) => form.append(key, value));
  const res = await fetch("AI_Scan_Worker.php", { method: "POST", body: form });
  return res.json();
}

function renderPageTab(page, index) {
  const title = pageTitle(page);
  return `<button class="page-tab-label js-page-tab" type="button" data-page-id="${escapeHtml(page.id)}" data-tab-target="page-panel-${escapeHtml(page.id)}" title="${escapeHtml(`${index + 1}. ${title}`)}">
    <span class="tab-number">${index + 1}</span>
    <span class="tab-title">${escapeHtml(title)}</span>
  </button>`;
}

function renderPagePanel(page) {
  const title = pageTitle(page);
  const summary = summaryTextFromPage(page);
  return `<article id="page-panel-${escapeHtml(page.id)}" class="page-item page-panel" data-page-id="${escapeHtml(page.id)}">
    <strong class="page-title">${escapeHtml(title)}</strong>
    <div class="url">${escapeHtml(page.url || "")}</div>
    <span class="status">${escapeHtml(page.page_status || "")}</span>
    <p class="muted">HTTP ${escapeHtml(page.http_status || "")}, ${escapeHtml(page.content_length || 0)} bytes, ${escapeHtml(page.discovered_links_count || 0)} links found</p>
    <label>Editable summary</label>
    <textarea form="save-summary-${escapeHtml(page.id)}" name="summary_text" placeholder="Summarize this page to generate editable summary.">${escapeHtml(summary)}</textarea>
    <div class="summary-actions">
      <form id="save-summary-${escapeHtml(page.id)}" method="POST">
        <input type="hidden" name="action" value="save_summary">
        <input type="hidden" name="page_id" value="${escapeHtml(page.id)}">
        <button type="submit">Save summary</button>
      </form>
      <form method="POST" class="js-summarize-page-form">
        <input type="hidden" name="action" value="summarize_page">
        <input type="hidden" name="page_id" value="${escapeHtml(page.id)}">
        <button type="submit">Summarize this page</button>
      </form>
    </div>
    ${page.ai_error ? `<p class="muted">${escapeHtml(page.ai_error)}</p>` : ""}
  </article>`;
}

function renderFaq(faq = {}) {
  return `<form method="POST" class="faq">
    <input type="hidden" name="action" value="save_faq">
    <input type="hidden" name="faq_id" value="${escapeHtml(faq.id || "")}">
    <label>Question</label>
    <input name="question" value="${escapeHtml(faq.question || "")}">
    <label>Answer</label>
    <textarea name="answer">${escapeHtml(faq.answer || "")}</textarea>
    <p class="muted">Source: ${escapeHtml(faq.source || "")}</p>
    <div class="row"><button type="submit">Save FAQ</button></div>
  </form>`;
}

function updateFaqs(faqs = []) {
  if (!faqList) return;
  if (faqList.contains(document.activeElement)) return;
  faqList.innerHTML = faqs.map((faq) => renderFaq(faq)).join("");
  if (faqMetric) faqMetric.textContent = String(faqs.length);
}

function updatePages(pages = []) {
  if (!pageTabs || !pagePanels) return;
  const activePanel = document.querySelector(".page-panel.is-active");
  const activePageId = activePanel?.dataset.pageId || "";
  const addTab = pageTabs.querySelector(".add-tab");
  pages.forEach((page, index) => {
    let tab = pageTabs.querySelector(`.js-page-tab[data-page-id="${cssEscape(page.id)}"]`);
    if (!tab) {
      const wrapper = document.createElement("div");
      wrapper.innerHTML = renderPageTab(page, index).trim();
      tab = wrapper.firstElementChild;
      pageTabs.insertBefore(tab, addTab);
    }
    tab.querySelector(".tab-number").textContent = String(index + 1);
    tab.querySelector(".tab-title").textContent = pageTitle(page);
    tab.title = `${index + 1}. ${pageTitle(page)}`;

    let panel = document.getElementById(`page-panel-${page.id}`);
    if (!panel) {
      const wrapper = document.createElement("div");
      wrapper.innerHTML = renderPagePanel(page).trim();
      panel = wrapper.firstElementChild;
      pagePanels.appendChild(panel);
    } else {
      panel.querySelector(".page-title").textContent = pageTitle(page);
      panel.querySelector(".url").textContent = page.url || "";
      panel.querySelector(".status").textContent = page.page_status || "";
      const muted = panel.querySelector(".muted");
      if (muted) muted.textContent = `HTTP ${page.http_status || ""}, ${page.content_length || 0} bytes, ${page.discovered_links_count || 0} links found`;
      const textarea = panel.querySelector("textarea[name='summary_text']");
      if (textarea && document.activeElement !== textarea && summaryTextFromPage(page) !== "") {
        textarea.value = summaryTextFromPage(page);
      }
    }
  });
  if (pageTabsCount) pageTabsCount.textContent = `${pages.length} page${pages.length === 1 ? "" : "s"} captured`;
  if (pages.length > 0 && (!document.querySelector(".page-panel.is-active") || activePanel?.id === "page-panel-add")) {
    selectPageTab(`page-panel-${pages[0].id}`, pages[0].id);
  } else if (activePageId) {
    selectPageTab(`page-panel-${activePageId}`, activePageId);
  }
}

function timeLabel(value = "") {
  if (!value) return "-";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString();
}

function tableRows(rows, columns, emptyText) {
  if (!rows || rows.length === 0) return `<tr><td colspan="${columns.length}" class="muted">${escapeHtml(emptyText)}</td></tr>`;
  return rows.map((row) => `<tr>${columns.map((column) => `<td class="${column.className || ""}" title="${escapeHtml(column.title ? column.title(row) : "")}">${column.render(row)}</td>`).join("")}</tr>`).join("");
}

function updateDiagnostics(data = {}) {
  const diagnostics = data.diagnostics || {};
  const scan = diagnostics.scan || data.scan || {};
  const counts = diagnostics.counts || data.counts || {};
  const setText = (id, value) => { const node = document.getElementById(id); if (node) node.textContent = value; };
  setText("diagStatus", scan.status || "");
  setText("diagCrawlPercent", `${Number(counts.crawl_percent || 0)}%`);
  setText("diagSummaryPercent", `${Number(counts.summary_percent || 0)}%`);
  setText("diagLastActivity", timeLabel(scan.updated_at || ""));
  setText("diagWorker", scan.worker_id || "-");
  setText("diagRequested", scan.pages_requested || 0);
  setText("diagPending", counts.pending || 0);
  setText("diagFetched", counts.fetched || 0);
  setText("diagSummarized", counts.summarized || 0);
  setText("diagFailed", counts.failed || 0);
  setText("diagLockExpires", timeLabel(scan.locked_until || ""));

  const claimedRows = document.getElementById("diagClaimedRows");
  if (claimedRows) claimedRows.innerHTML = tableRows(diagnostics.claimed || [], [
    { render: (r) => escapeHtml(shortUrlLabel(r.url || "")), title: (r) => r.url || "", className: "diag-url" },
    { render: (r) => escapeHtml(r.page_worker_id || "-") },
    { render: (r) => escapeHtml(r.priority ?? "") },
    { render: (r) => escapeHtml(r.crawl_attempts ?? "0") },
    { render: (r) => escapeHtml(timeLabel(r.page_locked_until || "")) }
  ], "No page is currently locked by a worker.");

  const pendingRows = document.getElementById("diagPendingRows");
  if (pendingRows) pendingRows.innerHTML = tableRows(diagnostics.pending || [], [
    { render: (r) => escapeHtml(shortUrlLabel(r.url || "")), title: (r) => r.url || "", className: "diag-url" },
    { render: (r) => `<span class="diag-pill">${escapeHtml(r.diagnostic_label || "queued")}</span>` },
    { render: (r) => escapeHtml(r.crawl_attempts ?? "0") },
    { render: (r) => escapeHtml(timeLabel(r.next_retry_at || "")) },
    { render: (r) => escapeHtml(r.ai_error || ""), className: "diag-error" }
  ], "No pending pages.");

  const failedRows = document.getElementById("diagFailedRows");
  if (failedRows) failedRows.innerHTML = tableRows(diagnostics.failed || [], [
    { render: (r) => escapeHtml(shortUrlLabel(r.url || "")), title: (r) => r.url || "", className: "diag-url" },
    { render: (r) => `<span class="diag-pill bad">${escapeHtml(r.diagnostic_label || "failed")}</span>` },
    { render: (r) => escapeHtml(r.http_status || "") },
    { render: (r) => escapeHtml(r.crawl_attempts ?? "0") },
    { render: (r) => escapeHtml(r.ai_error || ""), className: "diag-error" },
    { render: (r) => escapeHtml(timeLabel(r.updated_at || "")) }
  ], "No failed pages.");

  const retryRows = document.getElementById("diagRetryRows");
  if (retryRows) {
    const crawl = (diagnostics.waiting_retry || []).map((row) => ({...row, retry_type: "crawl"}));
    const summary = (diagnostics.waiting_summary_retry || []).map((row) => ({...row, retry_type: "summary"}));
    retryRows.innerHTML = tableRows([...crawl, ...summary], [
      { render: (r) => `<span class="diag-pill warn">${escapeHtml(r.retry_type)}</span>` },
      { render: (r) => escapeHtml(shortUrlLabel(r.url || "")), title: (r) => r.url || "", className: "diag-url" },
      { render: (r) => escapeHtml(r.retry_type === "summary" ? (r.summary_attempts ?? "0") : (r.crawl_attempts ?? "0")) },
      { render: (r) => escapeHtml(timeLabel(r.retry_type === "summary" ? r.summary_next_retry_at : r.next_retry_at)) },
      { render: (r) => escapeHtml(r.ai_error || ""), className: "diag-error" }
    ], "No pages are waiting for retry.");
  }

  const recentRows = document.getElementById("diagRecentRows");
  if (recentRows) recentRows.innerHTML = tableRows(diagnostics.recent_fetched || [], [
    { render: (r) => `<span class="diag-pill">${escapeHtml(r.page_status || "")}</span>` },
    { render: (r) => `<span class="diag-pill">${escapeHtml(r.diagnostic_label || "ok")}</span>` },
    { render: (r) => escapeHtml(shortUrlLabel(r.url || "")), title: (r) => r.url || "", className: "diag-url" },
    { render: (r) => escapeHtml(r.http_status || "") },
    { render: (r) => escapeHtml(r.content_length || "0") },
    { render: (r) => escapeHtml(r.discovered_links_count || "0") },
    { render: (r) => escapeHtml(timeLabel(r.fetched_at || r.updated_at || "")) }
  ], "No fetched pages yet.");

  const qualityRows = document.getElementById("diagQualityRows");
  if (qualityRows) qualityRows.innerHTML = tableRows(diagnostics.quality || [], [
    { render: (r) => escapeHtml(shortUrlLabel(r.url || "")), title: (r) => r.url || "", className: "diag-url" },
    { render: (r) => escapeHtml(r.page_status || "") },
    { render: (r) => escapeHtml((r.clean_text || "").length || r.content_length || 0) },
    { render: (r) => {
      const length = (r.clean_text || "").length || Number(r.content_length || 0);
      if (length < 300) return '<span class="diag-pill bad">very low text</span>';
      if (length < 900) return '<span class="diag-pill warn">short text</span>';
      return '<span class="diag-pill">ok</span>';
    } }
  ], "No content quality data yet.");

  const logRows = document.getElementById("diagLogRows");
  if (logRows) logRows.innerHTML = tableRows(diagnostics.logs || [], [
    { render: (r) => `<span class="diag-pill ${r.severity === "error" ? "bad" : (r.severity === "warning" ? "warn" : "")}">${escapeHtml(r.event_type || "")}</span>` },
    { render: (r) => escapeHtml(shortUrlLabel(r.url || "")), title: (r) => r.url || "", className: "diag-url" },
    { render: (r) => escapeHtml(r.message || ""), className: "diag-error" },
    { render: (r) => escapeHtml(timeLabel(r.created_at || "")) }
  ], "No crawler signals yet.");
}

function applyLiveData(data = {}, mode = "scan") {
  updateWorkerUi(data, mode);
  updateDiagnostics(data);
  updatePages(data.pages || []);
  if (capturedMetric && data.pages) capturedMetric.textContent = String(data.pages.length);
  if (data.faqs) updateFaqs(data.faqs);
}

async function processScanBatch() {
  if (!scanId || scanBusy) return;
  scanBusy = true;
  setDiagnosticsLive(true, "Scanning batch...", "Working");
  try {
    const data = await callWorker("scan_batch");
    applyLiveData(data, "scan");
    const scan = data.scan || {};
    if (scan.status === "pending" || scan.status === "running") {
      setTimeout(processScanBatch, 900);
    }
  } catch (error) {
    if (crawlText) crawlText.textContent = "Worker could not update scan progress. Refresh to retry.";
    setDiagnosticsLive(false, "Worker update failed", "Error");
  } finally {
    scanBusy = false;
  }
}

async function summarizeCapturedPages(preferredPageId = "") {
  if (!scanId || summaryBusy) return;
  summaryBusy = true;
  if (summarizeAllBtn) summarizeAllBtn.disabled = true;
  try {
    let latest = await callWorker("status");
    applyLiveData(latest, "summary");
    const pages = Array.isArray(latest.pages) ? [...latest.pages] : [];
    if (preferredPageId) {
      pages.sort((left, right) => {
        if (left?.id === preferredPageId) return -1;
        if (right?.id === preferredPageId) return 1;
        return 0;
      });
    }
    for (const page of pages) {
      if (!page?.id) continue;
      const data = await callWorker("summarize_page", { page_id: page.id });
      applyLiveData(data, "summary");
      await new Promise((resolve) => setTimeout(resolve, 350));
    }
  } catch (error) {
    if (summaryText) summaryText.textContent = "Summarization worker failed. Try again.";
  } finally {
    summaryBusy = false;
    if (summarizeAllBtn) summarizeAllBtn.disabled = false;
  }
}

async function processSummaryBatches() {
  await summarizeCapturedPages();
}

summarizeAllBtn?.addEventListener("click", processSummaryBatches);
runScanBatchBtn?.addEventListener("click", async () => {
  runScanBatchBtn.disabled = true;
  setDiagnosticsLive(true, "Scanning batch...", "Working");
  try {
    const data = await callWorker("scan_batch");
    applyLiveData(data, "scan");
  } finally {
    runScanBatchBtn.disabled = false;
  }
});
runSummaryBatchBtn?.addEventListener("click", async () => {
  runSummaryBatchBtn.disabled = true;
  try {
    const data = await callWorker("summarize_batch");
    applyLiveData(data, "summary");
  } finally {
    runSummaryBatchBtn.disabled = false;
  }
});
refreshLiveBtn?.addEventListener("click", refreshLiveStatus);
if (shell?.dataset.scanStatus === "pending" || shell?.dataset.scanStatus === "running") {
  processScanBatch();
}

document.addEventListener("submit", async (event) => {
  const form = event.target.closest(".js-summarize-page-form");
  if (!form) return;
  event.preventDefault();
  if (summaryBusy) return;
  const button = form.querySelector("button");
  const pageId = form.querySelector("input[name='page_id']")?.value || "";
  if (button) {
    button.disabled = true;
    button.textContent = "Summarizing...";
  }
  try {
    await summarizeCapturedPages(pageId);
    selectPageTab(`page-panel-${pageId}`, pageId);
  } finally {
    if (button) {
      button.disabled = false;
      button.textContent = "Summarize this page";
    }
  }
});

async function refreshLiveStatus() {
  if (!scanId || liveStatusBusy) return;
  liveStatusBusy = true;
  try {
    const data = await callWorker("status");
    applyLiveData(data, "scan");
  } finally {
    liveStatusBusy = false;
  }
}

setInterval(refreshLiveStatus, 2500);
</script>
</body>
</html>
