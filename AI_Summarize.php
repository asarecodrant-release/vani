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

$pages = ai_safe_rows(supabase(
    'GET',
    'ai_website_pages?select=*&scan_job_id=eq.' . urlencode($scanId)
        . '&customer_id=eq.' . urlencode($customerId)
        . '&order=created_at.asc&limit=100'
));

$faqs = ai_safe_rows(supabase(
    'GET',
    'ai_website_faqs?select=*&scan_job_id=eq.' . urlencode($scanId)
        . '&customer_id=eq.' . urlencode($customerId)
        . '&order=created_at.asc&limit=500'
));

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
$lastActivity = ai_time_label($diagScan['updated_at'] ?? '');
$progressRequested = max(1, (int)($diagScan['pages_requested'] ?? 0));
$progressDone = (int)($diagCounts['scanned'] ?? 0) + (int)($diagCounts['failed'] ?? 0);
$progressPercent = min(100, (int)round(($progressDone / $progressRequested) * 100));
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
.progress-strip{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid var(--line);background:var(--panel);border-radius:8px;padding:12px 14px;margin-bottom:16px}
.progress-strip strong{display:block;font-size:14px}
.progress-strip span{color:var(--muted);font-size:13px}
.progress-bar{height:8px;flex:1;min-width:120px;border-radius:999px;background:var(--soft);overflow:hidden}
.progress-bar i{display:block;height:100%;width:0;background:#2563eb;transition:width .25s ease}
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
<main class="shell" data-scan-id="<?php echo ai_h($scanId); ?>" data-scan-status="<?php echo ai_h($scan['status'] ?? ''); ?>" data-worker-csrf="<?php echo ai_h($workerCsrf); ?>">
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
    <div>
      <strong id="workerTitle">Website scan <?php echo ai_h($scan['status'] ?? 'pending'); ?></strong>
      <span id="workerText">Captured <?php echo count($pages); ?> of <?php echo (int)($scan['pages_requested'] ?? 0); ?> requested pages.</span>
    </div>
    <div class="progress-bar" aria-hidden="true"><i id="workerBar"></i></div>
  </div>

  <details class="panel diagnostics" <?php echo !empty($diagnostics['failed']) || !empty($diagnostics['waiting_retry']) ? 'open' : ''; ?>>
    <summary>
      <span>Crawler Diagnostics</span>
      <span class="diag-live <?php echo $workerActive ? 'is-running' : ''; ?>" id="diagLive">
        <i class="diag-spinner" aria-hidden="true"></i>
        <span class="diag-live-text" id="diagLiveText"><?php echo $workerActive ? 'Crawler working' : 'Crawler idle'; ?></span>
        <span class="diag-pill <?php echo $workerActive ? '' : 'warn'; ?>" id="diagLivePill"><?php echo $workerActive ? 'Worker active' : 'No active lock'; ?></span>
      </span>
    </summary>

    <div class="diag-grid">
      <div class="diag-card"><span>Status</span><strong><?php echo ai_h($diagScan['status'] ?? ''); ?></strong></div>
      <div class="diag-card"><span>Progress</span><strong><?php echo (int)$progressPercent; ?>%</strong></div>
      <div class="diag-card"><span>Last Activity</span><strong><?php echo ai_h($lastActivity); ?></strong></div>
      <div class="diag-card"><span>Worker</span><strong><?php echo ai_h(($diagScan['worker_id'] ?? '') ?: '-'); ?></strong></div>
      <div class="diag-card"><span>Requested</span><strong><?php echo (int)($diagScan['pages_requested'] ?? 0); ?></strong></div>
      <div class="diag-card"><span>Pending</span><strong><?php echo (int)($diagCounts['pending'] ?? 0); ?></strong></div>
      <div class="diag-card"><span>Fetched</span><strong><?php echo (int)($diagCounts['fetched'] ?? 0); ?></strong></div>
      <div class="diag-card"><span>Summarized</span><strong><?php echo (int)($diagCounts['summarized'] ?? 0); ?></strong></div>
      <div class="diag-card"><span>Failed</span><strong><?php echo (int)($diagCounts['failed'] ?? 0); ?></strong></div>
      <div class="diag-card"><span>Lock Expires</span><strong><?php echo ai_h(ai_time_label($diagScan['locked_until'] ?? '')); ?></strong></div>
      <div class="diag-card"><span>Crawl Batch</span><strong><?php echo (int)$diagnostics['settings']['crawl_batch_size']; ?> / <?php echo (int)$diagnostics['settings']['crawl_concurrency']; ?></strong></div>
      <div class="diag-card"><span>Render Service</span><strong><?php echo !empty($diagnostics['settings']['render_enabled']) ? 'Enabled' : 'Off'; ?></strong></div>
    </div>

    <div class="diag-actions">
      <button type="button" id="runScanBatchBtn">Run scan batch</button>
      <button type="button" id="runSummaryBatchBtn">Run summary batch</button>
      <button class="btn" type="button" onclick="location.reload()">Refresh diagnostics</button>
    </div>

    <div class="diag-section">
      <h3>Next Pending URLs</h3>
      <table class="diag-table">
        <thead><tr><th>URL</th><th>Attempts</th><th>Next Retry</th><th>Error</th></tr></thead>
        <tbody>
        <?php foreach ($diagnostics['pending'] as $row): ?>
          <tr>
            <td class="diag-url" title="<?php echo ai_h($row['url'] ?? ''); ?>"><?php echo ai_h(ai_short_url_label((string)($row['url'] ?? ''))); ?></td>
            <td><?php echo ai_h($row['crawl_attempts'] ?? '0'); ?></td>
            <td><?php echo ai_h(ai_time_label($row['next_retry_at'] ?? '')); ?></td>
            <td class="diag-error"><?php echo ai_h($row['ai_error'] ?? ''); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($diagnostics['pending'])): ?><tr><td colspan="4" class="muted">No pending pages.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="diag-section">
      <h3>Failed Pages</h3>
      <table class="diag-table">
        <thead><tr><th>URL</th><th>HTTP</th><th>Attempts</th><th>Error</th><th>Updated</th></tr></thead>
        <tbody>
        <?php foreach ($diagnostics['failed'] as $row): ?>
          <tr>
            <td class="diag-url" title="<?php echo ai_h($row['url'] ?? ''); ?>"><?php echo ai_h(ai_short_url_label((string)($row['url'] ?? ''))); ?></td>
            <td><?php echo ai_h($row['http_status'] ?? ''); ?></td>
            <td><?php echo ai_h($row['crawl_attempts'] ?? '0'); ?></td>
            <td class="diag-error"><?php echo ai_h($row['ai_error'] ?? ''); ?></td>
            <td><?php echo ai_h(ai_time_label($row['updated_at'] ?? '')); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($diagnostics['failed'])): ?><tr><td colspan="5" class="muted">No failed pages.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="diag-section">
      <h3>Retry Waits</h3>
      <table class="diag-table">
        <thead><tr><th>Type</th><th>URL</th><th>Attempts</th><th>Next Retry</th><th>Error</th></tr></thead>
        <tbody>
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
        <thead><tr><th>Status</th><th>URL</th><th>HTTP</th><th>Text/Bytes</th><th>Links</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($diagnostics['recent_fetched'] as $row): ?>
          <tr>
            <td><span class="diag-pill"><?php echo ai_h($row['page_status'] ?? ''); ?></span></td>
            <td class="diag-url" title="<?php echo ai_h($row['url'] ?? ''); ?>"><?php echo ai_h(ai_short_url_label((string)($row['url'] ?? ''))); ?></td>
            <td><?php echo ai_h($row['http_status'] ?? ''); ?></td>
            <td><?php echo ai_h($row['content_length'] ?? '0'); ?></td>
            <td><?php echo ai_h($row['discovered_links_count'] ?? '0'); ?></td>
            <td><?php echo ai_h(ai_time_label($row['fetched_at'] ?? $row['updated_at'] ?? '')); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($diagnostics['recent_fetched'])): ?><tr><td colspan="6" class="muted">No fetched pages yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="diag-section">
      <h3>Content Quality Watchlist</h3>
      <table class="diag-table">
        <thead><tr><th>URL</th><th>Status</th><th>Clean Text</th><th>Signal</th></tr></thead>
        <tbody>
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
    <div class="metric"><span>Captured FAQs</span><strong><?php echo count($faqs); ?></strong></div>
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
          <span><?php echo count($pages); ?> page<?php echo count($pages) === 1 ? '' : 's'; ?> captured</span>
          <span>Scroll tabs sideways to review all pages</span>
        </div>
        <div class="page-tabs" role="tablist" aria-label="Captured pages">
          <?php foreach ($pages as $index => $page): ?>
            <?php $tabTitle = trim((string)($page['page_title'] ?? '')) ?: (parse_url((string)$page['url'], PHP_URL_PATH) ?: 'Untitled'); ?>
            <button class="page-tab-label js-page-tab <?php echo $index === 0 ? 'is-selected' : ''; ?>" type="button" data-tab-target="page-panel-<?php echo (int)$index; ?>" title="<?php echo ai_h(($index + 1) . '. ' . $tabTitle); ?>">
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
      <?php foreach ($pages as $index => $page): ?>
        <?php $summaryText = ai_summary_text_from_page($page); ?>
        <article id="page-panel-<?php echo (int)$index; ?>" class="page-item page-panel <?php echo $index === 0 ? 'is-active' : ''; ?>">
          <strong class="page-title"><?php echo ai_h($page['page_title'] ?: parse_url((string)$page['url'], PHP_URL_PATH) ?: 'Untitled page'); ?></strong>
          <div class="url"><?php echo ai_h($page['url']); ?></div>
          <span class="status"><?php echo ai_h($page['page_status']); ?></span>
          <p class="muted">
            HTTP <?php echo ai_h($page['http_status'] ?? ''); ?>,
            <?php echo ai_h($page['content_length'] ?? 0); ?> bytes,
            <?php echo ai_h($page['discovered_links_count'] ?? 0); ?> links found
          </p>

          <label>Editable summary</label>
          <textarea form="save-summary-<?php echo (int)$index; ?>" name="summary_text" placeholder="Summarize this page to generate editable summary."><?php echo ai_h($summaryText); ?></textarea>
          <div class="summary-actions">
            <form id="save-summary-<?php echo (int)$index; ?>" method="POST">
              <input type="hidden" name="action" value="save_summary">
              <input type="hidden" name="page_id" value="<?php echo ai_h($page['id']); ?>">
              <button type="submit">Save summary</button>
            </form>
            <form method="POST">
              <input type="hidden" name="action" value="summarize_page">
              <input type="hidden" name="page_id" value="<?php echo ai_h($page['id']); ?>">
              <button type="submit">Summarize this page</button>
            </form>
          </div>
          <?php if (!empty($page['ai_error'])): ?><p class="muted"><?php echo ai_h($page['ai_error']); ?></p><?php endif; ?>
        </article>
      <?php endforeach; ?>
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
    </aside>
  </div>
</main>
<script>
document.querySelectorAll(".js-page-tab").forEach((tab) => {
  tab.addEventListener("click", () => {
    const target = tab.dataset.tabTarget;
    document.querySelectorAll(".js-page-tab").forEach((item) => item.classList.remove("is-selected"));
    document.querySelectorAll(".page-panel").forEach((panel) => panel.classList.remove("is-active"));
    tab.classList.add("is-selected");
    document.getElementById(target)?.classList.add("is-active");
  });
});

const shell = document.querySelector(".shell");
const scanId = shell?.dataset.scanId || "";
const workerCsrf = shell?.dataset.workerCsrf || "";
const workerTitle = document.getElementById("workerTitle");
const workerText = document.getElementById("workerText");
const workerBar = document.getElementById("workerBar");
const capturedMetric = document.getElementById("capturedMetric");
const summarizedMetric = document.getElementById("summarizedMetric");
const scanStatusMetric = document.getElementById("scanStatusMetric");
const summarizeAllBtn = document.getElementById("summarizeAllBtn");
const runScanBatchBtn = document.getElementById("runScanBatchBtn");
const runSummaryBatchBtn = document.getElementById("runSummaryBatchBtn");
const diagLive = document.getElementById("diagLive");
const diagLiveText = document.getElementById("diagLiveText");
const diagLivePill = document.getElementById("diagLivePill");
let scanBusy = false;
let summaryBusy = false;

function shortUrlLabel(url = "") {
  try {
    const parsed = new URL(url);
    return `${parsed.pathname || "/"}${parsed.search || ""}`;
  } catch (error) {
    return url || "";
  }
}

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
  const requested = Number(scan.pages_requested || 0);
  const captured = Number(counts.scanned || 0);
  const summarized = Number(counts.summarized || 0);
  const failed = Number(counts.failed || 0);
  const totalDone = captured + failed;
  const percent = requested > 0 ? Math.min(100, Math.round((totalDone / requested) * 100)) : 0;
  if (workerBar) workerBar.style.width = `${percent}%`;
  if (capturedMetric) capturedMetric.textContent = String(captured);
  if (summarizedMetric) summarizedMetric.textContent = String(summarized);
  if (scanStatusMetric) scanStatusMetric.textContent = scan.status || "";
  if (workerTitle) workerTitle.textContent = mode === "summary" ? "Summarization running" : `Website scan ${scan.status || "pending"}`;
  if (workerText) {
    workerText.textContent = mode === "summary"
      ? `Summarized ${summarized} page(s). ${captured - summarized} fetched page(s) still need summaries.`
      : `Captured ${captured} page(s), failed ${failed}, ${Number(counts.pending || 0)} still queued.`;
  }
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

async function callWorker(action) {
  const form = new FormData();
  form.append("scan", scanId);
  form.append("action", action);
  form.append("csrf", workerCsrf);
  const res = await fetch("AI_Scan_Worker.php", { method: "POST", body: form });
  return res.json();
}

async function processScanBatch() {
  if (!scanId || scanBusy) return;
  scanBusy = true;
  setDiagnosticsLive(true, "Scanning batch...", "Working");
  try {
    const data = await callWorker("scan_batch");
    updateWorkerUi(data, "scan");
    const scan = data.scan || {};
    const counts = data.counts || {};
    const reloadKey = `ai-scan-reloaded-${scanId}`;
    if ((scan.status === "completed" || scan.status === "failed") && Number(counts.total || 0) !== <?php echo count($pages); ?> && !sessionStorage.getItem(reloadKey)) {
      sessionStorage.setItem(reloadKey, "1");
      location.reload();
      return;
    }
    if (scan.status === "pending" || scan.status === "running") {
      setTimeout(processScanBatch, 900);
    }
  } catch (error) {
    if (workerText) workerText.textContent = "Worker could not update scan progress. Refresh to retry.";
    setDiagnosticsLive(false, "Worker update failed", "Error");
  } finally {
    scanBusy = false;
  }
}

async function processSummaryBatches() {
  if (!scanId || summaryBusy) return;
  summaryBusy = true;
  if (summarizeAllBtn) summarizeAllBtn.disabled = true;
  try {
    let keepGoing = true;
    while (keepGoing) {
      const data = await callWorker("summarize_batch");
      updateWorkerUi(data, "summary");
      keepGoing = Boolean(data.remaining) && !data.error;
      await new Promise((resolve) => setTimeout(resolve, 350));
    }
    sessionStorage.removeItem(`ai-scan-reloaded-${scanId}`);
    location.reload();
  } catch (error) {
    if (workerText) workerText.textContent = "Summarization worker failed. Try again.";
  } finally {
    summaryBusy = false;
    if (summarizeAllBtn) summarizeAllBtn.disabled = false;
  }
}

summarizeAllBtn?.addEventListener("click", processSummaryBatches);
runScanBatchBtn?.addEventListener("click", async () => {
  runScanBatchBtn.disabled = true;
  setDiagnosticsLive(true, "Scanning batch...", "Working");
  try {
    const data = await callWorker("scan_batch");
    updateWorkerUi(data, "scan");
    location.reload();
  } finally {
    runScanBatchBtn.disabled = false;
  }
});
runSummaryBatchBtn?.addEventListener("click", async () => {
  runSummaryBatchBtn.disabled = true;
  try {
    const data = await callWorker("summarize_batch");
    updateWorkerUi(data, "summary");
    location.reload();
  } finally {
    runSummaryBatchBtn.disabled = false;
  }
});
if (shell?.dataset.scanStatus === "pending" || shell?.dataset.scanStatus === "running") {
  processScanBatch();
}
</script>
</body>
</html>
