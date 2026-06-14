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
    $summaryJson = $page['summary_json'] ?? '';
    $summary = [];
    if (is_array($summaryJson)) {
        $summary = $summaryJson;
    } elseif (is_string($summaryJson) && $summaryJson !== '') {
        $summary = json_decode($summaryJson, true) ?: [];
    }
    return ai_clean_customer_text((string)($summary['summary'] ?? ''), 2200);
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
    }

    // Implementation of PRG pattern to prevent form resubmission
    if ($message !== '' || $error !== '') {
        $_SESSION['ai_flash_message'] = $message;
        $_SESSION['ai_flash_error'] = $error;
        header('Location: AI_Summarize.php' . ($scanId ? '?scan=' . urlencode($scanId) : ''));
        exit;
    }
}

$message = $_SESSION['ai_flash_message'] ?? '';
$error = $_SESSION['ai_flash_error'] ?? '';
unset($_SESSION['ai_flash_message'], $_SESSION['ai_flash_error']);

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
button,.btn,.icon-btn{min-height:42px;border:0;border-radius:8px;padding:0 14px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
button{background:#2563eb;color:#fff}
.btn{background:#e2e8f0;color:#0f172a}
.icon-btn{background:#e2e8f0;color:#0f172a}
.grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,390px);gap:18px;align-items:start;grid-auto-flow:dense}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:18px;box-shadow:0 12px 34px rgba(15,23,42,.06)}
.tools-panel h3{margin:18px 0 10px;font-size:14px;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted)}
.export-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}
.search-box { position: relative; flex: 1; }
.search-results-list { position: absolute; top: 100%; left: 0; right: 0; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; z-index: 100; max-height: 200px; overflow-y: auto; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-top: 4px; display: none; }
.search-suggestion { padding: 10px 12px; cursor: pointer; font-size: 13px; border-bottom: 1px solid var(--line); }
.search-suggestion:last-child { border-bottom: 0; }
.search-suggestion:hover { background: var(--soft); }
.pages-panel{min-width:0}
.faq-panel{position:static;max-height:none;min-width:0}
.faq-panel h2{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:12px;min-width:0}
.faq-panel h2>span:first-child{min-width:0;overflow-wrap:anywhere}
.faq-panel-tools{display:flex;align-items:center;gap:8px;flex:0 0 auto}
.faq-add-form{display:grid;gap:8px;padding:12px;border:1px solid var(--line);border-radius:8px;background:var(--soft);margin-bottom:12px}
.faq-add-form label{display:block;font-size:11px;font-weight:900;text-transform:uppercase;color:var(--muted)}
.faq-add-form input,.faq-add-form textarea{width:100%}
.faq-add-form textarea{min-height:92px;resize:vertical}
.faq-list{display:grid;gap:10px}
.faq-list,.faq-item,.faq-add-form,.faq-edit-form{min-width:0}
.faq-item{border:1px solid var(--line);border-radius:8px;background:var(--soft);padding:12px;overflow:hidden}
.faq-item strong{display:block;font-size:14px;line-height:1.35;overflow-wrap:anywhere;word-break:break-word}
.faq-item p{margin:8px 0 0;color:var(--muted);font-size:13px;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}
.faq-meta{display:flex;justify-content:space-between;gap:8px;margin-top:10px;color:var(--muted);font-size:11px;font-weight:800;min-width:0}
.faq-meta span:last-child{min-width:0;overflow:hidden;text-overflow:ellipsis;overflow-wrap:anywhere;word-break:break-all}
.faq-source{display:inline-flex;align-items:center;min-height:22px;border-radius:999px;background:#dcfce7;color:#166534;padding:0 8px;flex:0 0 auto}
.faq-edit-form{display:grid;gap:8px;margin-top:8px}
.faq-edit-form textarea{min-height:86px}
.faq-empty{border:1px dashed var(--line);border-radius:8px;padding:14px;color:var(--muted);font-size:13px;line-height:1.5}
.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:18px}
.metric{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:14px}
.metric span{display:block;color:var(--muted);font-size:12px;font-weight:800}
.metric strong{display:block;margin-top:6px;font-size:22px}
.message{margin-bottom:14px;padding:12px 14px;border-radius:8px;font-weight:800}
.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}
.refresh-toast{position:fixed;left:50%;top:86px;z-index:80;display:none;align-items:center;gap:10px;min-height:44px;max-width:calc(100vw - 28px);transform:translateX(-50%);border:1px solid var(--line);border-radius:8px;background:var(--panel);box-shadow:0 16px 40px rgba(15,23,42,.16);padding:0 14px;color:var(--ink);font-size:13px;font-weight:900}
.refresh-toast.is-visible{display:flex}
.refresh-toast i{width:16px;height:16px;border:2px solid rgba(37,99,235,.24);border-top-color:#2563eb;border-radius:999px;animation:diag-spin .8s linear infinite}
.refresh-toast.is-done i{border-color:#16a34a;background:#16a34a;animation:none}
.page-tabs-wrap{position:relative;margin-bottom:16px}
.page-tabs-meta{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:8px;color:var(--muted);font-size:12px;font-weight:800}
.page-tabs{display:flex;gap:8px;overflow-x:auto;overflow-y:hidden;max-width:100%;padding:4px 0 12px;border-bottom:1px solid var(--line);scroll-snap-type:x proximity;scrollbar-width:thin}
.page-tab-label{min-height:38px;display:inline-flex;align-items:center;gap:8px;flex:0 0 auto;max-width:210px;padding:0 12px;border:1px solid var(--line);border-radius:8px;background:var(--soft);color:var(--ink);font-size:13px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;scroll-snap-align:start}
.page-tab-label.is-selected{background:#2563eb;border-color:#2563eb;color:#fff}
.tab-number{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;border-radius:999px;background:rgba(148,163,184,.2);font-size:12px}
.page-tab-label.is-selected .tab-number{background:rgba(255,255,255,.22);color:#fff}
.tab-title{overflow:hidden;text-overflow:ellipsis}
.add-tab{min-width:42px;justify-content:center;font-size:20px}
.page-nav-tools{display:flex;gap:10px;margin-bottom:16px;align-items:center;flex-wrap:wrap}
.page-nav-tools .search-box{flex:1;min-width:200px}
.page-select-menu{flex:2;min-height:42px;background:var(--field);color:var(--ink);border:1px solid var(--line);border-radius:8px;padding:0 10px;font-weight:800;min-width:200px}
.page-panel{display:none}
.page-panel.is-active{display:block}
.page-panel.is-filtered{display:none !important}
.page-item{padding:6px 0}
.page-title{display:block;color:var(--ink);font-size:18px;line-height:1.35;margin-bottom:4px}
.url{color:var(--link);word-break:break-all;font-size:13px}
.status{display:inline-flex;margin:8px 0;padding:4px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px;font-weight:800}
textarea,input{width:100%;border:1px solid var(--line);border-radius:8px;padding:10px 12px;font:inherit;background:var(--field);color:var(--ink)}
textarea{min-height:120px;resize:vertical;line-height:1.5}
.row{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.summary-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;align-items:center}
.summary-actions form{margin:0}
.manual-page{border-top:1px solid var(--line);padding:14px 0}
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
.diag-error-summary{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.err-cat-pill{padding:6px 12px;border-radius:6px;background:var(--soft);border:1px solid var(--line);font-size:11px;font-weight:900;text-transform:uppercase;display:flex;gap:8px;align-items:center}
.err-cat-pill b{color:#991b1b;font-size:13px}
body.dark .err-cat-pill b{color:#fca5a5}
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
.diag-toggle-btn{min-height:32px;padding:0 10px;font-size:12px}
@keyframes diag-spin{to{transform:rotate(360deg)}}
@media(max-width:1100px){.page-tab-label{max-width:170px}}
@media(max-width:980px){.grid,.metrics,.diag-grid{grid-template-columns:1fr}.faq-panel{position:static;max-height:none}.top{flex-direction:column;align-items:stretch}.page-tab-label{max-width:180px}.shell{padding:26px 14px 56px}.diag-table{display:block;overflow-x:auto;white-space:nowrap}}
@media(max-width:640px){.page-nav-tools{flex-direction:column;align-items:stretch}.page-nav-tools .search-box,.page-select-menu{flex:none;width:100%}}
@media(max-width:560px){.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.panel{padding:14px}.top h1{font-size:26px}.page-tabs-meta{flex-direction:column;align-items:flex-start;gap:6px}.page-tab-label{max-width:150px}.summary-actions button,.summary-actions form{width:100%}.summary-actions button{width:100%}.faq-meta{flex-direction:column;align-items:flex-start;gap:4px}.faq-add-form button,.faq-edit-form button{width:100%}.refresh-toast{top:74px;width:calc(100vw - 28px);justify-content:center}}
</style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="refresh-toast" id="refreshToast" role="status" aria-live="polite">
  <i aria-hidden="true"></i>
  <span id="refreshToastText">Refreshing...</span>
</div>
<main class="shell" data-scan-id="<?php echo ai_h($scanId); ?>" data-scan-status="<?php echo ai_h($scan['status'] ?? ''); ?>" data-summary-paused="<?php echo !empty($scan['summary_paused']) ? '1' : '0'; ?>" data-worker-csrf="<?php echo ai_h($workerCsrf); ?>" data-external-queue="<?php echo $externalQueueEnabled ? '1' : '0'; ?>">
  <div class="top">
    <div>
      <h1>Review captured website pages</h1>
      <p><?php echo ai_h($scan['website_domain'] ?? ''); ?> pages are captured first. Customer-ready summaries can be reviewed and edited here.</p>
    </div>
    <div class="actions">
      <!-- <button class="btn" type="button" id="refreshLiveBtn">Refresh</button> -->
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
        <button class="btn diag-toggle-btn" type="button" id="scanPauseToggleBtn"><?php echo (string)($scan['status'] ?? '') === 'paused' ? 'Resume' : 'Pause'; ?></button>
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
      <button type="button" id="runScanBatchBtn" disabled>Re-scan</button>
    </div>

    <div class="diag-section">
      <h3>Active Error Summary</h3>
      <div id="diagErrorSummary" class="diag-error-summary"></div>
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

  <details class="panel diagnostics summary-diagnostics">
    <summary>
      <span>Page Summarization Diagnostic</span>
      <span class="diag-live" id="summaryDiagLive">
        <i class="diag-spinner" aria-hidden="true"></i>
        <span class="diag-live-text" id="summaryDiagLiveText"><?php echo !empty($scan['summary_paused']) ? 'Summarization paused' : ($summaryDone >= $summaryTotal && $summaryTotal > 0 ? 'All captured pages summarized' : 'Waiting for captured pages'); ?></span>
        <span class="diag-pill <?php echo $summaryDone >= $summaryTotal && $summaryTotal > 0 && empty($scan['summary_paused']) ? '' : 'warn'; ?>" id="summaryDiagLivePill"><?php echo !empty($scan['summary_paused']) ? 'Paused' : ((int)$summaryDone . ' / ' . (int)$summaryTotal); ?></span>
        <button class="btn diag-toggle-btn" type="button" id="summaryPauseToggleBtn"><?php echo !empty($scan['summary_paused']) ? 'Resume' : 'Pause'; ?></button>
      </span>
    </summary>

    <div class="diag-grid">
      <div class="diag-card"><span>Captured For Summary</span><strong id="summaryDiagTotal"><?php echo (int)$summaryTotal; ?></strong></div>
      <div class="diag-card"><span>Summarized</span><strong id="summaryDiagDone"><?php echo (int)$summaryDone; ?></strong></div>
      <div class="diag-card"><span>Waiting</span><strong id="summaryDiagPending"><?php echo (int)($diagCounts['summary_pending'] ?? 0); ?></strong></div>
      <div class="diag-card"><span>Progress</span><strong id="summaryDiagPercent"><?php echo (int)$summaryPercent; ?>%</strong></div>
      <div class="diag-card"><span>Summary Batch</span><strong><?php echo (int)$diagnostics['settings']['summary_batch_size']; ?></strong></div>
    </div>

    <div class="diag-section">
      <h3>Pages Waiting For Summary</h3>
      <table class="diag-table">
        <thead><tr><th>URL</th><th>Status</th><th>Attempts</th><th>Next Retry</th><th>Error</th></tr></thead>
        <tbody id="summaryPendingRows">
        <?php foreach ($pages as $row): ?>
          <?php if ((string)($row['page_status'] ?? '') !== 'fetched') { continue; } ?>
          <tr>
            <td class="diag-url" title="<?php echo ai_h($row['url'] ?? ''); ?>"><?php echo ai_h(ai_short_url_label((string)($row['url'] ?? ''))); ?></td>
            <td><span class="diag-pill warn">waiting</span></td>
            <td><?php echo ai_h($row['summary_attempts'] ?? '0'); ?></td>
            <td><?php echo ai_h(ai_time_label($row['summary_next_retry_at'] ?? '')); ?></td>
            <td class="diag-error"><?php echo ai_h($row['ai_error'] ?? ''); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ((int)($diagCounts['summary_pending'] ?? 0) === 0): ?><tr><td colspan="5" class="muted">No captured pages are waiting for summary.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="diag-section">
      <h3>Recently Summarized Pages</h3>
      <table class="diag-table">
        <thead><tr><th>URL</th><th>Status</th><th>Attempts</th><th>Summarized</th><th>Error</th></tr></thead>
        <tbody id="summaryRecentRows">
        <?php foreach ($diagnostics['recent_summarized'] as $row): ?>
          <tr>
            <td class="diag-url" title="<?php echo ai_h($row['url'] ?? ''); ?>"><?php echo ai_h(ai_short_url_label((string)($row['url'] ?? ''))); ?></td>
            <td><span class="diag-pill"><?php echo ai_h($row['page_status'] ?? 'summarized'); ?></span></td>
            <td><?php echo ai_h($row['summary_attempts'] ?? '0'); ?></td>
            <td><?php echo ai_h(ai_time_label($row['summarized_at'] ?? $row['updated_at'] ?? '')); ?></td>
            <td class="diag-error"><?php echo ai_h($row['ai_error'] ?? ''); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($diagnostics['recent_summarized'])): ?><tr><td colspan="5" class="muted">No pages summarized yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </details>

  <div class="metrics">
    <div class="metric"><span>Captured Pages</span><strong id="capturedMetric"><?php echo count($pages); ?></strong></div>
    <div class="metric"><span>Captured FAQ</span><strong id="faqMetric"><?php echo count($faqs); ?></strong></div>
    <div class="metric"><span>Summarized</span><strong id="summarizedMetric"><?php echo $summarizedCount; ?></strong></div>
    <div class="metric"><span>Scan Status</span><strong id="scanStatusMetric"><?php echo ai_h($scan['status'] ?? ''); ?></strong></div>
  </div>

  <div class="grid">
    <section class="panel pages-panel">
      <h2>Pages</h2>
      <?php if (empty($pages)): ?>
        <p class="muted" id="noPagesMsg">No pages were captured. Check scan diagnostics or try a sitemap/manual content source.</p>
      <?php endif; ?>
      <div class="page-tabs-wrap" id="pageNavWrap">
        <div class="page-tabs-meta">
          <span id="pageTabsCount"><?php echo count($pages); ?> page<?php echo count($pages) === 1 ? '' : 's'; ?> captured</span>
          <span>Search or select a page to review</span>
        </div>
        <div class="page-nav-tools">
          <div class="search-box">
            <input type="text" id="pageSearch" placeholder="Search pages...">
            <div id="pageSearchResults" class="search-results-list"></div>
          </div>
          <select id="pageSelect" class="page-select-menu" aria-label="Select page to review">
          </select>
        </div>
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
            <form id="save-summary-<?php echo ai_h($page['id']); ?>" method="POST" class="js-live-worker-form">
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
      <h2>
        <span>Captured FAQ</span>
        <span class="faq-panel-tools">
          <span class="diag-pill" id="faqCountPill"><?php echo count($faqs); ?></span>
        </span>
      </h2>
      <form method="POST" class="faq-add-form js-live-worker-form">
        <input type="hidden" name="action" value="add_faq">
        <input type="hidden" name="page_url" value="<?php echo ai_h($scan['website_url'] ?? ''); ?>">
        <div>
          <label for="faqQuestion">Question</label>
          <input id="faqQuestion" name="question" placeholder="Enter a new FAQ question">
        </div>
        <div>
          <label for="faqAnswer">Answer</label>
          <textarea id="faqAnswer" name="answer" placeholder="Enter the answer"></textarea>
        </div>
        <button type="submit">Add FAQ</button>
      </form>
      <div class="faq-list" id="faqList" style="max-height: 500px; overflow-y: auto; padding-right: 8px;">
        <?php if (empty($faqs)): ?>
          <div class="faq-empty" id="faqEmpty">No FAQ pairs captured yet. The crawler will add them here when it finds FAQ markup or FAQ-like pages.</div>
        <?php endif; ?>
        <?php foreach ($faqs as $faq): ?>
          <article class="faq-item" data-faq-id="<?php echo ai_h($faq['id'] ?? ''); ?>">
            <strong><?php echo ai_h($faq['question'] ?? ''); ?></strong>
            <p><?php echo nl2br(ai_h($faq['answer'] ?? '')); ?></p>
            <div class="faq-meta">
              <span class="faq-source"><?php echo ai_h($faq['source'] ?? 'crawl'); ?></span>
              <span title="<?php echo ai_h($faq['page_url'] ?? ''); ?>"><?php echo ai_h(ai_short_url_label((string)($faq['page_url'] ?? ''))); ?></span>
            </div>
            <form method="POST" class="faq-edit-form js-live-worker-form">
              <input type="hidden" name="action" value="save_faq">
              <input type="hidden" name="faq_id" value="<?php echo ai_h($faq['id'] ?? ''); ?>">
              <input name="question" value="<?php echo ai_h($faq['question'] ?? ''); ?>">
              <textarea name="answer"><?php echo ai_h($faq['answer'] ?? ''); ?></textarea>
              <button type="submit">Save FAQ</button>
              <button type="button" class="danger-btn js-delete-faq" data-faq-id="<?php echo ai_h($faq['id'] ?? ''); ?>" style="margin-top:8px; width:100%;">Delete FAQ</button>
            </form>
          </article>
        <?php endforeach; ?>
      </div>
    </aside>

    <section class="panel add-missed-section">
      <h2>Add missed page URL</h2>
      <form method="POST" class="manual-page js-live-worker-form">
        <input type="hidden" name="action" value="add_page">
        <input name="page_url" placeholder="https://example.com/missed-page">
        <p class="muted">We will try to crawl this page. If readable text is not captured, you can still add the summary manually below.</p>
        <div class="row"><button type="submit">Add page</button></div>
      </form>
    </section>

    <section class="panel tools-panel">
      <h2>Tools & Export</h2>
      <p class="muted" style="margin-bottom: 20px;">Generate additional content or export your captured data.</p>
      <div style="display: grid; gap: 20px;">
        <div>
          <button type="button" id="backfillFaqsBtn" class="btn" style="width:100%; justify-content:center; margin-bottom:10px;">
            Generate FAQs from Summaries
          </button>
          <p class="muted" style="font-size:12px;">Uses AI to create FAQ pairs from your page summaries.</p>
        </div>
        <hr style="border:0; border-top:1px solid var(--line); margin: 5px 0;">
        <div>
          <h3 style="margin-top: 0; font-size: 14px; font-weight: 800; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em;">Export Data</h3>
          <div class="export-actions" style="margin-top: 12px; display: flex; gap: 10px;">
            <a href="AI_Export.php?scan=<?php echo urlencode($scanId); ?>&format=excel" class="btn" style="flex:1; text-align:center;">Excel (CSV)</a>
            <button type="button" id="exportPdfBtn" class="btn" style="flex:1; text-align:center;">PDF Report</button>
          </div>
        </div>
      </div>
    </section>
  </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js"></script>
<script>
const shell = document.querySelector(".shell");
const scanId = shell?.dataset.scanId || "";
const workerCsrf = shell?.dataset.workerCsrf || "";
const externalQueueEnabled = shell?.dataset.externalQueue === "1";
let currentScanStatus = shell?.dataset.scanStatus || "";
let currentSummaryPaused = shell?.dataset.summaryPaused === "1";
let scanPauseOverride = currentScanStatus === "paused";
const pageTabs = document.getElementById("pageTabs");
const pagePanels = document.getElementById("pagePanels");
const pageTabsCount = document.getElementById("pageTabsCount");
const pageSelect = document.getElementById("pageSelect");
const pageSearch = document.getElementById("pageSearch");
const crawlTitle = document.getElementById("crawlTitle");
const crawlText = document.getElementById("crawlText");
const crawlBar = document.getElementById("crawlBar");
const crawlPercent = document.getElementById("crawlPercent");
const summaryTitle = document.getElementById("summaryTitle");
const summaryText = document.getElementById("summaryText");
const summaryBar = document.getElementById("summaryBar");
const summaryPercent = document.getElementById("summaryPercent");
const capturedMetric = document.getElementById("capturedMetric");
const faqMetric = document.getElementById("faqMetric");
const faqCountPill = document.getElementById("faqCountPill");
const faqList = document.getElementById("faqList");
const summarizedMetric = document.getElementById("summarizedMetric");
const scanStatusMetric = document.getElementById("scanStatusMetric");
const summarizeAllBtn = document.getElementById("summarizeAllBtn");
const runScanBatchBtn = document.getElementById("runScanBatchBtn");
const backfillFaqsBtn = document.getElementById("backfillFaqsBtn");
const refreshLiveBtn = document.getElementById("refreshLiveBtn");
const refreshToast = document.getElementById("refreshToast");
const refreshToastText = document.getElementById("refreshToastText");
const scanPauseToggleBtn = document.getElementById("scanPauseToggleBtn");
const summaryPauseToggleBtn = document.getElementById("summaryPauseToggleBtn");
const diagLive = document.getElementById("diagLive");
const diagLiveText = document.getElementById("diagLiveText");
const diagLivePill = document.getElementById("diagLivePill");
const summaryDiagLive = document.getElementById("summaryDiagLive");
const summaryDiagLiveText = document.getElementById("summaryDiagLiveText");
const summaryDiagLivePill = document.getElementById("summaryDiagLivePill");
let scanBusy = false;
let summaryBusy = false;
let liveStatusBusy = false;
let liveStatusPromise = null;
let autoWorkflowBusy = false;
let autoWorkflowDone = false;
let scanPauseRequestBusy = false;
let summaryControlsFrozen = false;
let latestPagesList = <?php echo json_encode($pages); ?>;
let latestFaqsList = <?php echo json_encode($faqs); ?>;
const websiteDomain = <?php echo json_encode($scan['website_domain'] ?? ''); ?>;
const summarizingPages = new Set();
const skippedSummaryPages = new Set();

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

let refreshToastTimer = 0;

function showRefreshToast(message = "Refreshing...", isDone = false, visibleMs = 1000) {
  if (!refreshToast || !refreshToastText) return;
  window.clearTimeout(refreshToastTimer);
  refreshToastText.textContent = message;
  refreshToast.classList.toggle("is-done", Boolean(isDone));
  refreshToast.classList.add("is-visible");
  if (visibleMs > 0) {
    refreshToastTimer = window.setTimeout(() => {
      refreshToast.classList.remove("is-visible", "is-done");
    }, visibleMs);
  }
}

function escapeHtml(value = "") {
  return String(value ?? "").replace(/[&<>"']/g, (char) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;"
  }[char]));
}

function cleanDisplayText(value = "") {
  const wrapper = document.createElement("div");
  wrapper.innerHTML = String(value ?? "")
    .replace(/<(br|\/p|\/div|\/li|\/tr|\/h[1-6])\b[^>]*>/gi, "\n")
    .replace(/<(script|style|noscript|svg)\b[^>]*>.*?<\/\1>/gis, " ");
  return (wrapper.textContent || wrapper.innerText || "")
    .replace(/[ \t\r\f\v]+/g, " ")
    .replace(/\n\s+/g, "\n")
    .replace(/\n{3,}/g, "\n\n")
    .trim();
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
  if (summary && typeof summary === "object" && typeof summary.summary === "string") return cleanDisplayText(summary.summary);
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
  document.querySelectorAll(".page-panel").forEach((panel) => panel.classList.remove("is-active"));
  document.getElementById(target)?.classList.add("is-active");
  if (pageId && pageSelect) pageSelect.value = pageId;
}

pageSelect?.addEventListener("change", (e) => {
  const pageId = e.target.value;
  if (pageId) selectPageTab(`page-panel-${pageId}`, pageId);
});

pageSearch?.addEventListener("input", (e) => {
    const q = (e.target.value || "").toLowerCase();
    const resultsNode = document.getElementById("pageSearchResults");
    if (!resultsNode) return;

    if (!q) {
      resultsNode.innerHTML = "";
      resultsNode.style.display = "none";
      return;
    }

    const matches = latestPagesList.filter(p => 
      (p.page_title || "").toLowerCase().includes(q) || 
      (p.url || "").toLowerCase().includes(q)
    );

    resultsNode.innerHTML = "";
    if (matches.length > 0) {
      resultsNode.style.display = "block";
      matches.forEach(p => {
        const item = document.createElement("div");
        item.className = "search-suggestion";
        item.textContent = pageTitle(p);
        item.onclick = () => {
          selectPageTab(`page-panel-${p.id}`, p.id);
          pageSearch.value = "";
          resultsNode.style.display = "none";
        };
        resultsNode.appendChild(item);
      });
    } else {
      resultsNode.style.display = "none";
    }
});

document.addEventListener("click", (event) => {
  if (!event.target.closest(".search-box")) {
    const resultsNode = document.getElementById("pageSearchResults");
    if (resultsNode) resultsNode.style.display = "none";
  }
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

function setSummaryDiagnosticsLive(isRunning, text, pillText) {
  summaryDiagLive?.classList.toggle("is-running", Boolean(isRunning));
  if (summaryDiagLiveText) summaryDiagLiveText.textContent = text || (isRunning ? "Summarizing captured page" : "Summarizer idle");
  if (summaryDiagLivePill) {
    summaryDiagLivePill.textContent = pillText || (isRunning ? "Working" : "Idle");
    summaryDiagLivePill.classList.toggle("warn", !isRunning);
  }
}

function setScanPauseToggleState(status = "") {
  const value = String(status || "").toLowerCase();
  currentScanStatus = value;
  if (!scanPauseToggleBtn) return;
  if (value === "completed" || value === "failed") {
    scanPauseToggleBtn.disabled = true;
    scanPauseToggleBtn.textContent = "Done";
    return;
  }
  scanPauseToggleBtn.disabled = false;
  scanPauseToggleBtn.textContent = value === "paused" ? "Resume" : "Pause";
}

function setSummaryPauseToggleState(isPaused = false, isCompleted = false) {
  currentSummaryPaused = Boolean(isPaused);
  if (!summaryPauseToggleBtn) return;
  if (isCompleted) {
    summaryPauseToggleBtn.disabled = true;
    summaryPauseToggleBtn.textContent = "Done";
    return;
  }
  summaryPauseToggleBtn.disabled = false;
  summaryPauseToggleBtn.textContent = currentSummaryPaused ? "Resume" : "Pause";
}

function setSummaryControlsCompleted(isCompleted) {
  if (isCompleted) {
    summaryControlsFrozen = true;
    if (summarizeAllBtn) {
      summarizeAllBtn.disabled = true;
      summarizeAllBtn.textContent = "Summarization completed";
    }
    return;
  }

  if (summaryControlsFrozen) return;
  if (summarizeAllBtn && !summaryBusy) {
    summarizeAllBtn.disabled = false;
    summarizeAllBtn.textContent = "Summarize all pages";
  }
}

function isScanPaused() {
  return scanPauseOverride || String(currentScanStatus || "").toLowerCase() === "paused";
}

function updateWorkerUi(data, mode = "scan") {
  const counts = data?.counts || {};
  const scan = data?.scan || {};
  const rawScanStatus = String(scan.status || "");
  if (rawScanStatus.toLowerCase() === "completed" || rawScanStatus.toLowerCase() === "failed") {
    scanPauseOverride = false;
  } else if (rawScanStatus.toLowerCase() === "paused") {
    scanPauseOverride = true;
  }
  const displayScanStatus = scanPauseRequestBusy && currentScanStatus === "paused" && rawScanStatus.toLowerCase() !== "paused"
    ? "paused"
    : (scanPauseOverride && rawScanStatus.toLowerCase() !== "paused" ? "paused" : rawScanStatus);
  const captured = Number(counts.scanned || 0);
  const summarized = Number(counts.summarized || 0);
  const failed = Number(counts.failed || 0);
  const crawlDone = Number(counts.crawl_done ?? (captured + failed));
  const crawlTotal = Number(counts.crawl_total ?? counts.total ?? 0);
  const summaryTotal = Number(counts.summary_total ?? captured);
  const summaryDone = Number(counts.summary_done ?? summarized);
  const summaryPending = Number(counts.summary_pending ?? (captured - summarized));
  const summaryIsComplete = summaryTotal > 0 && summaryPending <= 0 && summaryDone >= summaryTotal;
  const summaryPaused = Boolean(scan.summary_paused);
  const crawlPct = Number(counts.crawl_percent ?? (crawlTotal > 0 ? Math.min(100, Math.round((crawlDone / crawlTotal) * 100)) : 0));
  const summaryPct = Number(counts.summary_percent ?? (summaryTotal > 0 ? Math.min(100, Math.round((summaryDone / summaryTotal) * 100)) : 0));
  if (crawlBar) crawlBar.style.width = `${crawlPct}%`;
  if (summaryBar) summaryBar.style.width = `${summaryPct}%`;
  if (crawlPercent) crawlPercent.textContent = `${crawlPct}%`;
  if (summaryPercent) summaryPercent.textContent = `${summaryPct}%`;
  if (capturedMetric) capturedMetric.textContent = String(captured);
  if (summarizedMetric) summarizedMetric.textContent = String(summarized);
  if (scanStatusMetric) scanStatusMetric.textContent = displayScanStatus;
  setScanPauseToggleState(displayScanStatus);
  setSummaryPauseToggleState(summaryPaused, summaryIsComplete);
  if (crawlTitle) crawlTitle.textContent = `Crawling ${displayScanStatus || "pending"}`;
  if (crawlText) crawlText.textContent = `${crawlDone} of ${crawlTotal} queued page(s) crawled. ${failed} failed, ${Number(counts.pending || 0)} still queued.`;
  if (summaryTitle) summaryTitle.textContent = summaryPaused ? "Summarization paused" : (summaryIsComplete ? "Summarization completed" : (mode === "summary" ? "Summarization running" : "Summarizing"));
  if (summaryText) summaryText.textContent = `${summaryDone} of ${summaryTotal} captured page(s) summarized. ${summaryPending} waiting.`;
  setSummaryControlsCompleted(summaryIsComplete);
  if (summaryPaused) {
    setSummaryDiagnosticsLive(false, "Summarization paused", "Paused");
  } else {
    setSummaryDiagnosticsLive(
      mode === "summary" && summaryPending > 0,
      summaryDone >= summaryTotal && summaryTotal > 0 ? "All captured pages summarized" : `${Number(counts.summary_pending ?? (captured - summarized))} page(s) waiting for summary`,
      `${summaryDone} / ${summaryTotal}`
    );
  }
  if (mode === "scan") {
    const activeUrl = data?.active_url || "";
    const processed = Number(data?.processed || 0);
    if (activeUrl) {
      setDiagnosticsLive(displayScanStatus === "pending" || displayScanStatus === "running", `Scanning: ${shortUrlLabel(activeUrl)}`, `${processed} processed`);
    } else if (data?.waiting_for_retry) {
      setDiagnosticsLive(false, "Waiting for retry window", "Retry wait");
    } else {
      setDiagnosticsLive(displayScanStatus === "pending" || displayScanStatus === "running", displayScanStatus === "completed" ? "Crawler complete" : "Crawler working", displayScanStatus || "");
    }
  }
  if (runScanBatchBtn) {
    runScanBatchBtn.disabled = (displayScanStatus.toLowerCase() !== "completed");
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
      <form id="save-summary-${escapeHtml(page.id)}" method="POST" class="js-live-worker-form">
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

function renderFaqItem(faq = {}) {
  const id = escapeHtml(faq.id || "");
  return `<article class="faq-item" data-faq-id="${id}">
    <strong>${escapeHtml(faq.question || "")}</strong>
    <p>${escapeHtml(faq.answer || "").replace(/\n/g, "<br>")}</p>
    <div class="faq-meta">
      <span class="faq-source">${escapeHtml(faq.source || "crawl")}</span>
      <span title="${escapeHtml(faq.page_url || "")}">${escapeHtml(shortUrlLabel(faq.page_url || ""))}</span>
    </div>
    <form method="POST" class="faq-edit-form js-live-worker-form">
      <input type="hidden" name="action" value="save_faq">
      <input type="hidden" name="faq_id" value="${id}">
      <input name="question" value="${escapeHtml(faq.question || "")}">
      <textarea name="answer">${escapeHtml(faq.answer || "")}</textarea>
      <button type="submit">Save FAQ</button>
      <button type="button" class="danger-btn js-delete-faq" data-faq-id="${id}" style="margin-top:8px; width:100%;">Delete FAQ</button>
    </form>
  </article>`;
}

function updateFaqs(faqs = []) {
  if (!faqList) return;
  if (faqMetric) faqMetric.textContent = String(faqs.length);
  if (faqCountPill) faqCountPill.textContent = String(faqs.length);
  if (!Array.isArray(faqs) || faqs.length === 0) {
    faqList.innerHTML = '<div class="faq-empty" id="faqEmpty">No FAQ pairs captured yet. The crawler will add them here when it finds FAQ markup or FAQ-like pages.</div>';
    return;
  }

  const active = document.activeElement;
  const activeFaq = active?.closest?.(".faq-item")?.dataset?.faqId || "";
  const activeName = active?.getAttribute?.("name") || "";
  faqs.forEach((faq) => {
    if (!faq?.id) return;
    let item = faqList.querySelector(`.faq-item[data-faq-id="${cssEscape(faq.id)}"]`);
    if (!item) {
      const wrapper = document.createElement("div");
      wrapper.innerHTML = renderFaqItem(faq).trim();
      item = wrapper.firstElementChild;
      faqList.appendChild(item);
      return;
    }
    item.querySelector("strong").textContent = faq.question || "";
    item.querySelector("p").textContent = faq.answer || "";
    const source = item.querySelector(".faq-source");
    if (source) source.textContent = faq.source || "crawl";
    const url = item.querySelector(".faq-meta span:last-child");
    if (url) {
      url.textContent = shortUrlLabel(faq.page_url || "");
      url.title = faq.page_url || "";
    }
    if (activeFaq !== String(faq.id) || activeName !== "question") {
      const questionInput = item.querySelector('input[name="question"]');
      if (questionInput) questionInput.value = faq.question || "";
    }
    if (activeFaq !== String(faq.id) || activeName !== "answer") {
      const answerInput = item.querySelector('textarea[name="answer"]');
      if (answerInput) answerInput.value = faq.answer || "";
    }
  });
  faqList.querySelector("#faqEmpty")?.remove();
}

function updatePages(pages = []) {
  if (!pageSelect || !pagePanels) return;
  const activePanel = document.querySelector(".page-panel.is-active");
  const activePageId = activePanel?.dataset.pageId || "";
  const q = pageSearch?.value.toLowerCase() || "";
  
  // Keep options in sync
  const currentSelectVal = pageSelect.value;
  pageSelect.innerHTML = "";
  
  pages.forEach((page, index) => {
    const title = pageTitle(page);
    const opt = document.createElement("option");
    opt.value = page.id;
    opt.text = `${index + 1}. ${title}`;
    opt.title = page.url || "";
    if (q && !opt.text.toLowerCase().includes(q) && !opt.title.toLowerCase().includes(q)) {
      opt.hidden = true;
    }
    pageSelect.appendChild(opt);

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
  if (currentSelectVal) pageSelect.value = currentSelectVal;

  if (pages.length > 0 && !document.querySelector(".page-panel.is-active")) {
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

  // Categorize errors
  const errorMap = new Map();
  const allErrors = [...(diagnostics.failed || []), ...(diagnostics.waiting_retry || []), ...(diagnostics.waiting_summary_retry || []), ...(diagnostics.logs || [])];
  allErrors.forEach(err => {
    const msg = String(err.ai_error || err.message || err.http_status || "").toLowerCase();
    if (!msg || msg === "0" || msg === "200") return;
    let cat = "Other Issues";
    if (msg.includes("404") || msg.includes("not found")) cat = "404 Not Found";
    else if (msg.includes("timeout") || msg.includes("timed out")) cat = "Connectivity/Timeouts";
    else if (msg.includes("ai provider") || msg.includes("ai config")) cat = "AI Service Config";
    else if (msg.includes("token limit") || msg.includes("max tokens")) cat = "AI Token Limits";
    else if (msg.includes("403") || msg.includes("forbidden") || msg.includes("access denied")) cat = "Access Denied (403)";
    else if (msg.includes("500") || msg.includes("server error")) cat = "Remote Server Errors";
    errorMap.set(cat, (errorMap.get(cat) || 0) + 1);
  });
  const summaryNode = document.getElementById("diagErrorSummary");
  if (summaryNode) {
    if (errorMap.size === 0) {
      summaryNode.innerHTML = '<div class="muted">No active issues detected.</div>';
    } else {
      summaryNode.innerHTML = Array.from(errorMap.entries()).map(([cat, count]) => 
        `<div class="err-cat-pill"><span>${escapeHtml(cat)}</span> <b>${count}</b></div>`
      ).join("");
    }
  }
}

function updateSummaryDiagnostics(data = {}) {
  const diagnostics = data.diagnostics || {};
  const counts = diagnostics.counts || data.counts || {};
  const pages = Array.isArray(data.pages) ? data.pages : [];
  const pendingPages = pages.filter((page) => page.page_status === "fetched");
  const setText = (id, value) => { const node = document.getElementById(id); if (node) node.textContent = value; };
  setText("summaryDiagTotal", counts.summary_total ?? pendingPages.length);
  setText("summaryDiagDone", counts.summary_done ?? counts.summarized ?? 0);
  setText("summaryDiagPending", counts.summary_pending ?? pendingPages.length);
  setText("summaryDiagPercent", `${Number(counts.summary_percent || 0)}%`);
  const pendingRows = document.getElementById("summaryPendingRows");
  if (pendingRows) pendingRows.innerHTML = tableRows(pendingPages, [
    { render: (r) => escapeHtml(shortUrlLabel(r.url || "")), title: (r) => r.url || "", className: "diag-url" },
    { render: () => '<span class="diag-pill warn">waiting</span>' },
    { render: (r) => escapeHtml(r.summary_attempts ?? "0") },
    { render: (r) => escapeHtml(timeLabel(r.summary_next_retry_at || "")) },
    { render: (r) => escapeHtml(r.ai_error || ""), className: "diag-error" }
  ], "No captured pages are waiting for summary.");

  const recentRows = document.getElementById("summaryRecentRows");
  if (recentRows) recentRows.innerHTML = tableRows(diagnostics.recent_summarized || [], [
    { render: (r) => escapeHtml(shortUrlLabel(r.url || "")), title: (r) => r.url || "", className: "diag-url" },
    { render: (r) => `<span class="diag-pill">${escapeHtml(r.page_status || "summarized")}</span>` },
    { render: (r) => escapeHtml(r.summary_attempts ?? "0") },
    { render: (r) => escapeHtml(timeLabel(r.summarized_at || r.updated_at || "")) },
    { render: (r) => escapeHtml(r.ai_error || ""), className: "diag-error" }
  ], "No pages summarized yet.");
}

function applyLiveData(data = {}, mode = "scan") {
  updateWorkerUi(data, mode);
  updateDiagnostics(data);
  updateSummaryDiagnostics(data);
  if (data.pages) latestPagesList = data.pages;
  if (data.faqs) latestFaqsList = data.faqs;
  updatePages(data.pages || []);
  updateFaqs(data.faqs || []);
  if (capturedMetric && data.pages) capturedMetric.textContent = String(data.pages.length);
}

async function processScanBatch() {
  if (!scanId || scanBusy || isScanPaused()) return;
  scanBusy = true;
  setDiagnosticsLive(true, "Scanning batch...", "Working");
  try {
    const data = await callWorker("scan_batch");
    applyLiveData(data, "scan");
    if (!summaryBusy && nextFetchedPage(data)) {
      summarizeNextFetchedPage(data).catch(() => {});
    }
  } catch (error) {
    if (crawlText) crawlText.textContent = "Worker could not update scan progress. Refresh to retry.";
    setDiagnosticsLive(false, "Worker update failed", "Error");
  } finally {
    scanBusy = false;
  }
}

function crawlComplete(data = {}) {
  const counts = data.counts || data.diagnostics?.counts || {};
  const scan = data.scan || data.diagnostics?.scan || {};
  const total = Number(counts.total || counts.crawl_total || 0);
  const pending = Number(counts.pending || 0);
  const queuedLinks = Number(data.queued_links || 0);
  return total > 0 && pending <= 0 && queuedLinks <= 0 && scan.status === "completed";
}

async function refreshWorkflowStatus(mode = "scan") {
  const data = await callWorker("status");
  applyLiveData(data, mode);
  return data;
}

function nextFetchedPage(data = {}) {
  const pages = Array.isArray(data.pages) ? data.pages : [];
  return pages.find((page) => page?.id && page.page_status === "fetched" && !summarizingPages.has(String(page.id)) && !skippedSummaryPages.has(String(page.id)));
}

function summaryComplete(data = {}) {
  const counts = data.counts || data.diagnostics?.counts || {};
  const total = Number(counts.summary_total ?? counts.scanned ?? 0);
  const pending = Number(counts.summary_pending ?? 0);
  const done = Number(counts.summary_done ?? counts.summarized ?? 0);
  return total > 0 && pending <= 0 && done >= total;
}

async function summarizeNextFetchedPage(data = {}) {
  const page = nextFetchedPage(data);
  const scan = data.scan || data.diagnostics?.scan || {};
  if (!page?.id || summaryBusy || Boolean(scan.summary_paused) || currentSummaryPaused) return data;

  summaryBusy = true;
  summarizingPages.add(String(page.id));
  if (summarizeAllBtn) summarizeAllBtn.disabled = true;
  setSummaryDiagnosticsLive(true, `Summarizing: ${shortUrlLabel(page.url || "")}`, "Working");
  try {
    const latest = await callWorker("summarize_page", { page_id: page.id });
    if (!latest.success) skippedSummaryPages.add(String(page.id));
    applyLiveData(latest, "summary");
    return latest;
  } catch (error) {
    if (summaryText) summaryText.textContent = "Summarization worker failed. Retrying live workflow.";
    return data;
  } finally {
    summarizingPages.delete(String(page.id));
    summaryBusy = false;
    if (!summaryControlsFrozen && summarizeAllBtn) summarizeAllBtn.disabled = false;
  }
}

async function scanAllPagesFirst() {
  let data = await refreshWorkflowStatus("scan");
  let idleRounds = 0;
  while (!crawlComplete(data)) {
    if (scanBusy) {
      await wait(500);
      data = await refreshWorkflowStatus("scan");
      continue;
    }

    scanBusy = true;
    setDiagnosticsLive(true, "Scanning all pages...", "Working");
    try {
      data = await callWorker("scan_batch");
      applyLiveData(data, "scan");
    } finally {
      scanBusy = false;
    }

    const processed = Number(data.processed || 0);
    const pending = Number(data.counts?.pending || data.diagnostics?.counts?.pending || 0);
    idleRounds = processed === 0 && pending > 0 ? idleRounds + 1 : 0;
    await wait(idleRounds > 2 ? 1800 : 700);
    data = await refreshWorkflowStatus("scan");
  }
  return data;
}

async function processSummaryBatches() {
  await liveWorkflowLoop(true);
}

async function liveWorkflowLoop(force = false) {
  if (!scanId || autoWorkflowBusy || (autoWorkflowDone && !force)) return;
  autoWorkflowBusy = true;
  try {
    let latest = await refreshWorkflowStatus("scan");
    let idleRounds = 0;
    while (true) {
      const scan = latest.scan || latest.diagnostics?.scan || {};
      const counts = latest.counts || latest.diagnostics?.counts || {};
      if (String(scan.status || "").toLowerCase() === "paused") {
        scanPauseOverride = true;
        setDiagnosticsLive(false, "Crawler paused", "Paused");
      }
      if ((scan.status === "failed" || scan.status === "completed") && Number(counts.total || 0) === 0) {
        autoWorkflowDone = true;
        setDiagnosticsLive(false, scan.status === "failed" ? "Crawler failed" : "Crawler complete", scan.status || "Done");
        setSummaryDiagnosticsLive(false, "No captured pages to summarize", "Idle");
        break;
      }

      const summaryPaused = Boolean(scan.summary_paused);
      if (summaryPaused) {
        setSummaryDiagnosticsLive(false, "Summarization paused", "Paused");
      }

      const waitingSummary = summaryPaused ? null : nextFetchedPage(latest);
      if (waitingSummary && !summaryBusy) {
        summarizeNextFetchedPage(latest).catch(() => {});
      }

      const pages = Array.isArray(latest.pages) ? latest.pages : [];
      const blockedSummaryCount = pages.filter((page) => page?.id && page.page_status === "fetched" && skippedSummaryPages.has(String(page.id))).length;
      if (crawlComplete(latest) && blockedSummaryCount > 0 && blockedSummaryCount === pages.filter((page) => page.page_status === "fetched").length) {
        autoWorkflowDone = true;
        setSummaryDiagnosticsLive(false, "Some pages need summary retry", "Retry needed");
        break;
      }

      if (isScanPaused()) {
        await wait(1000);
        latest = await refreshWorkflowStatus("scan");
        continue;
      }

      if (!crawlComplete(latest)) {
        scanBusy = true;
        setDiagnosticsLive(true, externalQueueEnabled ? "Scanning next pages with browser fallback..." : "Scanning next pages...", "Working");
        try {
          latest = await callWorker("scan_batch");
          applyLiveData(latest, "scan");
        } finally {
          scanBusy = false;
        }
        if (!summaryBusy && !Boolean(latest.scan?.summary_paused) && nextFetchedPage(latest)) {
          summarizeNextFetchedPage(latest).catch(() => {});
        }
        idleRounds = Number(latest.processed || 0) === 0 ? idleRounds + 1 : 0;
        await wait(idleRounds > 2 ? 1600 : 650);
        continue;
      }

      if (crawlComplete(latest) && summaryComplete(latest)) {
        autoWorkflowDone = true;
        setDiagnosticsLive(false, "Crawler complete", "Completed");
        setSummaryDiagnosticsLive(false, "All captured pages summarized", "Completed");
        setSummaryControlsCompleted(true);
        break;
      }

      if (crawlComplete(latest) && waitingSummary && !summaryBusy) {
        summarizeNextFetchedPage(latest).catch(() => {});
      }
      await wait(900);
      latest = await refreshWorkflowStatus("scan");
    }
  } finally {
    autoWorkflowBusy = false;
  }
}

summarizeAllBtn?.addEventListener("click", processSummaryBatches);
scanPauseToggleBtn?.addEventListener("click", async (event) => {
  event.preventDefault();
  event.stopPropagation();
  if (!scanId || scanPauseRequestBusy) return;
  const wasPaused = currentScanStatus === "paused";
  const action = wasPaused ? "resume_scan" : "pause_scan";
  scanPauseRequestBusy = true;
  currentScanStatus = wasPaused ? "running" : "paused";
  setScanPauseToggleState(currentScanStatus);
  setDiagnosticsLive(!wasPaused, wasPaused ? "Crawler resuming" : "Crawler paused", wasPaused ? "Working" : "Paused");
  scanPauseToggleBtn.disabled = true;
  try {
    const data = await callWorker(action);
    applyLiveData(data, "scan");
    scanPauseOverride = String(data?.scan?.status || "").toLowerCase() === "paused";
    if (wasPaused) {
      autoWorkflowDone = false;
      if (!autoWorkflowBusy) {
        liveWorkflowLoop(true);
      }
    }
  } finally {
    scanPauseRequestBusy = false;
    if (currentScanStatus !== "completed" && currentScanStatus !== "failed") {
      scanPauseToggleBtn.disabled = false;
    }
  }
});
summaryPauseToggleBtn?.addEventListener("click", async (event) => {
  event.preventDefault();
  event.stopPropagation();
  if (!scanId) return;
  const wasPaused = currentSummaryPaused;
  const action = wasPaused ? "resume_summary" : "pause_summary";
  currentSummaryPaused = !wasPaused;
  setSummaryPauseToggleState(currentSummaryPaused, false);
  setSummaryDiagnosticsLive(false, wasPaused ? "Summarization resuming" : "Summarization paused", wasPaused ? "Working" : "Paused");
  summaryPauseToggleBtn.disabled = true;
  try {
    const data = await callWorker(action);
    applyLiveData(data, "summary");
    scanPauseOverride = String(data?.scan?.status || "").toLowerCase() === "paused";
    if (wasPaused) {
      autoWorkflowDone = false;
      if (!autoWorkflowBusy) {
        liveWorkflowLoop(true);
      }
    }
  } finally {
    if (!summaryControlsFrozen) {
      summaryPauseToggleBtn.disabled = false;
    }
  }
});
runScanBatchBtn?.addEventListener("click", async () => {
  if (currentScanStatus !== "completed") return;
  if (!confirm("This will delete all currently captured pages and restart the scan from the beginning. Captured FAQs will be preserved. Are you sure?")) {
    return;
  }
  runScanBatchBtn.disabled = true;
  setDiagnosticsLive(true, "Resetting and restarting scan...", "Working");
  
  summarizingPages.clear();
  skippedSummaryPages.clear();
  autoWorkflowDone = false;

  try {
    const data = await callWorker("restart_scan");
    if (pagePanels) pagePanels.innerHTML = "";
    applyLiveData(data, "scan");
    scanPauseOverride = String(data?.scan?.status || "").toLowerCase() === "paused";
    liveWorkflowLoop(true);
  } finally {
    runScanBatchBtn.disabled = false;
  }
});
refreshLiveBtn?.addEventListener("click", async () => {
  if (!scanId) return;
  const originalText = refreshLiveBtn.textContent;
  refreshLiveBtn.disabled = true;
  refreshLiveBtn.textContent = "Refreshing...";
  showRefreshToast("Refreshing...", false, 0);
  try {
    await refreshLiveStatus({ force: true });
    showRefreshToast("Refresh done", true, 1000);
  } catch (error) {
    showRefreshToast("Refresh failed", true, 1400);
  } finally {
    refreshLiveBtn.disabled = false;
    refreshLiveBtn.textContent = originalText;
  }
});
liveWorkflowLoop();

document.addEventListener("submit", async (event) => {
  const form = event.target.closest(".js-summarize-page-form");
  if (!form) return;
  event.preventDefault();
  if (summaryBusy || currentSummaryPaused) return;
  const button = form.querySelector("button");
  const pageId = form.querySelector("input[name='page_id']")?.value || "";
  if (button) {
    button.disabled = true;
    button.textContent = "Summarizing...";
  }
  try {
    const data = await callWorker("summarize_page", { page_id: pageId });
    if (data.success) skippedSummaryPages.delete(String(pageId));
    applyLiveData(data, "summary");
    selectPageTab(`page-panel-${pageId}`, pageId);
  } finally {
    if (button) {
      button.disabled = false;
      button.textContent = "Summarize this page";
    }
  }
});

document.addEventListener("click", async (event) => {
  const deleteBtn = event.target.closest(".js-delete-faq");
  if (!deleteBtn) return;
  
  const faqId = deleteBtn.dataset.faqId;
  if (!faqId || !confirm("Are you sure you want to delete this FAQ?")) return;

  const originalText = deleteBtn.textContent;
  deleteBtn.disabled = true;
  deleteBtn.textContent = "Deleting...";

  try {
    const data = await callWorker("delete_faq", { faq_id: faqId });
    applyLiveData(data, "summary");
  } finally {
    // If it wasn't removed from DOM, reset button
    if (document.contains(deleteBtn)) {
      deleteBtn.disabled = false;
      deleteBtn.textContent = originalText;
    }
  }
});

backfillFaqsBtn?.addEventListener("click", async () => {
  if (!scanId || scanBusy || summaryBusy) return;
  
  const originalText = backfillFaqsBtn.textContent;
  backfillFaqsBtn.disabled = true;
  backfillFaqsBtn.textContent = "Generating FAQs...";
  
  try {
    const data = await callWorker("backfill_faqs");
    if (data.success) {
      alert(`Success! Generated ${data.created || 0} new FAQ pairs from ${data.processed || 0} summarized pages.`);
      applyLiveData(data, "summary");
    } else {
      alert(data.error || "Failed to generate FAQs.");
    }
  } finally {
    backfillFaqsBtn.disabled = false;
    backfillFaqsBtn.textContent = originalText;
  }
});

document.addEventListener("submit", async (event) => {
  const form = event.target.closest(".js-live-worker-form");
  if (!form) return;
  event.preventDefault();
  const action = form.querySelector("input[name='action']")?.value || "";
  if (!action) return;
  const button = form.querySelector("button[type='submit']");
  const originalText = button?.textContent || "";
  if (button) {
    button.disabled = true;
    button.textContent = "Saving...";
  }
  const extra = {};
  new FormData(form).forEach((value, key) => {
    if (key !== "action") extra[key] = value;
  });
  try {
    const data = await callWorker(action, extra);
    const isAddPage = (action === "add_page");
    applyLiveData(data, isAddPage ? "scan" : "summary");

    if (action === "add_page") {
      if (data.success) {
        form.reset();
      } else if (data.error) {
        alert(data.error);
      }
      if (data.page_id) {
        selectPageTab(`page-panel-${data.page_id}`, data.page_id);
        document.getElementById(`page-panel-${data.page_id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
      await liveWorkflowLoop(true);
    }
  } finally {
    if (button) {
      button.disabled = false;
      button.textContent = originalText;
    }
  }
});

async function refreshLiveStatus(options = {}) {
  const force = Boolean(options.force);
  if (!scanId) return null;
  if (liveStatusPromise) {
    if (!force) return liveStatusPromise;
    await liveStatusPromise.catch(() => null);
  }
  liveStatusBusy = true;
  liveStatusPromise = (async () => {
    const data = await callWorker("status");
    if (!data || data.success === false) {
      throw new Error(data?.error || "Status refresh failed.");
    }
    applyLiveData(data, summaryBusy ? "summary" : "scan");
    if ((data?.scan?.status || "").toLowerCase() === "running" && !isScanPaused() && !autoWorkflowBusy && !scanPauseRequestBusy) {
      autoWorkflowDone = false;
      liveWorkflowLoop(true);
    }
    return data;
  })();
  try {
    return await liveStatusPromise;
  } finally {
    liveStatusBusy = false;
    liveStatusPromise = null;
  }
}

setInterval(() => {
  refreshLiveStatus().catch(() => {});
}, 2500);

/**
 * Professional PDF Export Logic
 */
function aiSummaryReportHtml() {
  const esc = (v) => String(v ?? "").replace(/[&<>"']/g, (c) => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c]));
  const date = new Date().toLocaleString();

  let pagesHtml = "";
  latestPagesList.forEach((page, i) => {
    const summary = summaryTextFromPage(page);
    if (page.page_status === 'pending' || page.page_status === 'failed') return;
    
    pagesHtml += `
      <div class="page-entry">
        <div class="page-entry-header">
           <span class="page-badge">PAGE ${i+1}</span>
           <h3 class="page-title-text">${esc(pageTitle(page))}</h3>
           <div class="page-link-text">${esc(page.url)}</div>
        </div>
        <div class="page-entry-body">
          <div class="section-label">PAGE SUMMARY</div>
          <div class="summary-text-content">${esc(summary || "No summary available.")}</div>
          <div class="page-stats-footer">Captured: ${esc(page.fetched_at || page.updated_at || 'n/a')} | HTTP ${esc(page.http_status || 'n/a')}</div>
        </div>
      </div>
    `;
  });

  let faqsHtml = "";
  if (latestFaqsList.length > 0) {
    latestFaqsList.forEach((faq) => {
      faqsHtml += `
        <div class="faq-entry">
          <div class="faq-question-text"><strong>Q:</strong> ${esc(faq.question)}</div>
          <div class="faq-answer-text"><strong>A:</strong> ${esc(faq.answer)}</div>
        </div>
      `;
    });
  } else {
    faqsHtml = '<p class="empty-msg">No FAQs captured for this website yet.</p>';
  }

  return `
    <style>
      .report-container { font-family: 'Inter', system-ui, sans-serif; color: #0f172a; line-height: 1.5; background: #fff; }
      .report-cover { text-align: center; padding: 60px 20px; border-bottom: 4px solid #2563eb; background: #f8fafc; margin-bottom: 40px; }
      .report-cover h1 { font-size: 32px; color: #2563eb; margin: 20px 0 10px; font-weight: 800; letter-spacing: -0.02em; }
      .report-cover p { color: #64748b; font-size: 16px; margin: 0; }
      .report-logo-img { width: 80px; height: auto; }
      
      .report-meta-grid { display: flex; justify-content: center; gap: 40px; margin-top: 30px; }
      .report-meta-item { text-align: left; }
      .report-meta-item span { display: block; font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; }
      .report-meta-item strong { display: block; font-size: 14px; color: #1e293b; }

      .section-heading { font-size: 22px; color: #2563eb; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; margin: 40px 0 20px; font-weight: 800; }
      
      .page-entry { margin-bottom: 30px; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; page-break-inside: avoid; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
      .page-entry-header { background: #f1f5f9; padding: 15px 20px; border-bottom: 1px solid #e2e8f0; }
      .page-badge { display: inline-block; padding: 2px 8px; background: #dbeafe; color: #2563eb; font-size: 10px; font-weight: 900; border-radius: 4px; margin-bottom: 5px; }
      .page-title-text { margin: 0; font-size: 18px; font-weight: 700; color: #0f172a; }
      .page-link-text { font-size: 12px; color: #3b82f6; margin-top: 4px; text-decoration: none; word-break: break-all; }
      
      .page-entry-body { padding: 20px; }
      .section-label { font-size: 11px; font-weight: 900; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px; }
      .summary-text-content { font-size: 14px; color: #334155; white-space: pre-wrap; text-align: justify; }
      .page-stats-footer { margin-top: 15px; font-size: 11px; color: #94a3b8; padding-top: 10px; border-top: 1px solid #f1f5f9; }
      
      .faq-entry { padding: 15px 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 15px; page-break-inside: avoid; }
      .faq-question-text { font-size: 15px; font-weight: 700; color: #1e293b; margin-bottom: 8px; display: flex; gap: 8px; }
      .faq-answer-text { font-size: 14px; line-height: 1.6; color: #475569; display: flex; gap: 8px; padding-left: 5px; }
      .faq-question-text strong, .faq-answer-text strong { color: #2563eb; }

      .report-footer-area { margin-top: 60px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 20px; }
      .empty-msg { text-align: center; color: #64748b; font-style: italic; padding: 20px; }
      .page-break { page-break-after: always; }
    </style>
    <div class="report-container">
      <div class="report-cover">
        <img src="images/logo_img.png" class="report-logo-img">
        <h1>Website Intelligence Report</h1>
        <p>Summary and FAQ Analysis by Vani AI</p>
        <div class="report-meta-grid">
          <div class="report-meta-item"><span>Website</span><strong>${esc(websiteDomain)}</strong></div>
          <div class="report-meta-item"><span>Report Date</span><strong>${esc(date.split(',')[0])}</strong></div>
          <div class="report-meta-item"><span>Pages Scanned</span><strong>${latestPagesList.length}</strong></div>
        </div>
      </div>

      <h2 class="section-heading">Knowledge Base Summary</h2>
      <div class="report-content-body">
        ${pagesHtml || '<p class="empty-msg">No summarized content available for this report.</p>'}
      </div>

      <div class="page-break"></div>

      <h2 class="section-heading">Captured FAQ Items</h2>
      <div class="faq-report-body">
        ${faqsHtml}
      </div>

      <div class="report-footer-area">
        This report was automatically generated by Vani AI. For more details, visit vani.codrant.com.
      </div>
    </div>
  `;
}

async function generateProfessionalPdf() {
  if (typeof html2pdf === 'undefined') {
    alert("PDF generator is still loading. Please wait a moment.");
    return;
  }

  const exportBtn = document.getElementById("exportPdfBtn");
  const originalLabel = exportBtn.innerHTML;
  exportBtn.disabled = true;
  exportBtn.innerHTML = "Generating PDF...";
  showRefreshToast("Generating report...", false, 0);

  try {
    const container = document.createElement("div");
    container.innerHTML = aiSummaryReportHtml();
    
    const options = {
      margin: [15, 15, 15, 15],
      filename: `Vani-AI-Content-Report-${websiteDomain.replace(/\./g, '-')}.pdf`,
      image: { type: 'jpeg', quality: 0.98 },
      html2canvas: { scale: 1.5, useCORS: true, letterRendering: true, backgroundColor: '#ffffff' },
      jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
      pagebreak: { mode: ['css', 'legacy'] }
    };

    await html2pdf().set(options).from(container).save();
    showRefreshToast("Report generated!", true, 2000);
  } catch (err) {
    console.error("PDF generation failed:", err);
    showRefreshToast("Export failed.", false, 3000);
  } finally {
    exportBtn.disabled = false;
    exportBtn.innerHTML = originalLabel;
  }
}

document.getElementById("exportPdfBtn")?.addEventListener("click", generateProfessionalPdf);
</script>
</body>
</html>
