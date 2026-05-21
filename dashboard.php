<?php
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/core.php';

if (!is_authenticated_user()) {
    header("Location: login.php");
    exit;
}

$email = authenticated_email();
$accountId = authenticated_user_id();
$selectedBotId = trim($_GET['bot'] ?? '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$widgetUrl = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/widget.js';
$botImages = glob(__DIR__ . '/images/botimg_*') ?: [];
$botImages = array_values(array_filter($botImages, 'is_file'));
natcasesort($botImages);
$botImages = array_map(fn($path) => 'images/' . basename($path), $botImages);

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function safe_data(array $response): array {
    $data = $response['data'] ?? null;
    if (!is_array($data)) {
        return [];
    }
    if ($data === []) {
        return [];
    }
    if (array_keys($data) !== range(0, count($data) - 1)) {
        return [];
    }
    return $data;
}

function first_value(array $row, array $keys, string $fallback = ''): string {
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return (string)$row[$key];
        }
    }
    return $fallback;
}

$bots = safe_data(supabase(
    "GET",
    "chatbot_signups?select=*&email=eq." . urlencode($email) . "&order=created_at.desc"
));

if (!$selectedBotId && !empty($bots[0]['customer_id'])) {
    $selectedBotId = (string)$bots[0]['customer_id'];
}

$selectedBot = [];
foreach ($bots as $bot) {
    if (($bot['customer_id'] ?? '') === $selectedBotId) {
        $selectedBot = $bot;
        break;
    }
}

if (empty($selectedBot) && $selectedBotId) {
    $fallbackBot = safe_data(supabase(
        "GET",
        "chatbot_signups?select=*&customer_id=eq." . urlencode($selectedBotId) . "&limit=1"
    ));
    $selectedBot = $fallbackBot[0] ?? [];
}

$faqs = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "faq_questions?select=*&customer_id=eq." . urlencode($selectedBotId) . "&order=id.desc"
    ))
    : [];

$usageRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "faq_usage?select=*&customer_id=eq." . urlencode($selectedBotId)
    ))
    : [];

$conversationRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "chatbot_conversations?select=*&customer_id=eq." . urlencode($selectedBotId) . "&order=created_at.desc&limit=50"
    ))
    : [];

$settingsRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "chatbot_settings?select=*&customer_id=eq." . urlencode($selectedBotId) . "&limit=1"
    ))
    : [];

$leadSettingsRows = $selectedBotId
    ? safe_data(supabase(
        "GET",
        "lead_generation_settings?select=*&customer_id=eq." . urlencode($selectedBotId) . "&limit=1"
    ))
    : [];

$profileRows = safe_data(supabase(
    "GET",
    "customer_profiles?select=*&email=eq." . urlencode($email) . "&limit=1"
));

$settings = $settingsRows[0] ?? [];
$leadSettings = $leadSettingsRows[0] ?? [];
$profile = $profileRows[0] ?? [];
$faqCount = count($faqs);
$freeFaqLimit = 25;
$conversationCount = count($conversationRows);
$today = gmdate('Y-m-d');
$todayQueries = 0;
$lastActivity = '';
$answeredCount = 0;
$unansweredCount = 0;
$dailyCounts = [];
$hourCounts = [];
$topQuestionCounts = [];
$faqById = [];
$topFaqQuestionCounts = [];
$outsideFaqQuestions = [];

foreach ($faqs as $faq) {
    if (isset($faq['id'])) {
        $faqById[(string)$faq['id']] = (string)($faq['question'] ?? '');
    }
}

foreach ($conversationRows as $row) {
    $created = (string)($row['created_at'] ?? '');
    if ($created && substr($created, 0, 10) === $today) {
        $todayQueries++;
    }
    if (!$lastActivity || strcmp($created, $lastActivity) > 0) {
        $lastActivity = $created;
    }
    $status = strtolower((string)($row['status'] ?? ''));
    $answered = ($status === 'answered') || !empty($row['is_answered']);
    if ($answered) {
        $answeredCount++;
    } else {
        $unansweredCount++;
    }
    if ($created) {
        $day = substr($created, 0, 10);
        $hour = substr($created, 11, 2);
        $dailyCounts[$day] = ($dailyCounts[$day] ?? 0) + 1;
        if ($hour !== '') {
            $hourCounts[$hour] = ($hourCounts[$hour] ?? 0) + 1;
        }
    }
    $question = trim((string)($row['user_question'] ?? $row['question'] ?? ''));
    if ($question !== '') {
        $key = strtolower($question);
        $topQuestionCounts[$key] = [
            'question' => $question,
            'count' => ($topQuestionCounts[$key]['count'] ?? 0) + 1
        ];
        if (!$answered) {
            $outsideFaqQuestions[] = [
                'question' => $question,
                'bot_response' => (string)($row['bot_response'] ?? $row['response'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? '')
            ];
        }
    }
    $matchedFaqId = (string)($row['matched_faq_id'] ?? $row['question_id'] ?? '');
    if ($matchedFaqId !== '' && isset($faqById[$matchedFaqId])) {
        $faqQuestion = $faqById[$matchedFaqId];
        $topFaqQuestionCounts[$matchedFaqId] = [
            'question' => $faqQuestion,
            'count' => ($topFaqQuestionCounts[$matchedFaqId]['count'] ?? 0)
        ];
    }
}

if (!$conversationCount && !empty($usageRows)) {
    $conversationCount = count($usageRows);
    $answeredCount = count($usageRows);
    foreach ($usageRows as $row) {
        $created = (string)($row['created_at'] ?? '');
        if ($created && substr($created, 0, 10) === $today) {
            $todayQueries++;
        }
        if ($created && (!$lastActivity || strcmp($created, $lastActivity) > 0)) {
            $lastActivity = $created;
        }
    }
}

if (!empty($usageRows)) {
    foreach ($usageRows as $row) {
        $questionId = (string)($row['question_id'] ?? '');
        if ($questionId !== '' && isset($faqById[$questionId])) {
            $topFaqQuestionCounts[$questionId] = [
                'question' => $faqById[$questionId],
                'count' => ($topFaqQuestionCounts[$questionId]['count'] ?? 0) + 1
            ];
        }
    }
}

$accuracy = $conversationCount > 0
    ? round(($answeredCount / max(1, $conversationCount)) * 100)
    : ($faqCount > 0 ? 100 : 0);

$unansweredPercent = $conversationCount > 0
    ? round(($unansweredCount / max(1, $conversationCount)) * 100)
    : 0;

uasort($topQuestionCounts, fn($a, $b) => $b['count'] <=> $a['count']);
uasort($topFaqQuestionCounts, fn($a, $b) => $b['count'] <=> $a['count']);
arsort($hourCounts);
$peakUsage = !empty($hourCounts) ? array_key_first($hourCounts) . ":00" : "Not enough data";
$themeColor = first_value($selectedBot, ['theme_color'], '#6366f1');
$chatbotImage = first_value($settings, ['avatar_url'], $botImages[0] ?? '');
$botName = first_value($settings, ['bot_name'], first_value($selectedBot, ['website_name'], 'Vani Bot'));
$welcomeMessage = first_value($settings, ['welcome_message'], 'Hi, how can I help you today?');
$position = first_value($settings, ['position'], 'right');
$language = first_value($settings, ['language'], 'English');
$rawActive = $settings['is_active'] ?? true;
$isActive = is_bool($rawActive) ? $rawActive : ((string)$rawActive !== 'false');
$websiteVerificationEnabled = filter_var($settings['website_verification_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$allowedDomainsEnabled = filter_var($settings['allowed_domains_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$allowedDomains = first_value($settings, ['allowed_domains'], '');
$verificationStatus = first_value($settings, ['verification_status'], 'Pending');
$websiteName = first_value($selectedBot, ['website_name'], '');
$leadEnabled = filter_var($leadSettings['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadCollectLocation = filter_var($leadSettings['collect_location'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadCollectEmail = filter_var($leadSettings['collect_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadCollectMobile = filter_var($leadSettings['collect_mobile'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadVerifyEmailOtp = filter_var($leadSettings['verify_email_otp'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadNotifyByEmail = filter_var($leadSettings['notify_lead_by_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadRedirectWhatsapp = filter_var($leadSettings['redirect_whatsapp'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadVerifyMobileOtp = filter_var($leadSettings['verify_mobile_otp'] ?? false, FILTER_VALIDATE_BOOLEAN);
$leadNotificationEmail = first_value($leadSettings, ['notification_email'], $email);
$leadWhatsappNumber = first_value($leadSettings, ['whatsapp_mobile_number'], '');
$embedCode = $selectedBotId ? '<script src="' . $widgetUrl . '" data-id="' . $selectedBotId . '"></script>' : '';
$profileFirstName = first_value($profile, ['first_name'], '');
$profileLastName = first_value($profile, ['last_name'], '');
$displayName = trim($profileFirstName . ' ' . $profileLastName);
$initialSource = $profileFirstName ?: $email;
$initials = strtoupper(substr($initialSource, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vani Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif}
:root{
  --brand:#6366f1;
  --brand-2:#ec4899;
  --ink:#0f172a;
  --muted:#64748b;
  --line:rgba(148,163,184,.24);
  --panel:rgba(255,255,255,.78);
  --panel-strong:#fff;
  --soft:#f8fafc;
  --shadow:0 18px 45px rgba(15,23,42,.09);
}
html{
  -webkit-text-size-adjust:100%;
  overflow-x:hidden;
}
body{
  min-height:100vh;
  color:var(--ink);
  background:linear-gradient(135deg,#f0f9ff,#eef2ff,#faf5ff);
  overflow-x:hidden;
}
body.dark{
  --ink:#e5e7eb;
  --muted:#a5b4fc;
  --line:rgba(226,232,240,.13);
  --panel:rgba(15,23,42,.82);
  --panel-strong:#111827;
  --soft:#0f172a;
  --shadow:0 18px 45px rgba(0,0,0,.24);
  background:linear-gradient(135deg,#0f172a,#1e1b4b,#3b0764);
}
a{text-decoration:none;color:inherit}
button,input,select,textarea{font:inherit}
button{touch-action:manipulation}
.dashboard-shell{min-height:100vh;display:grid;grid-template-columns:260px minmax(0,1fr);width:100%;overflow-x:hidden}
.drawer-overlay{
  position:fixed;
  inset:0;
  background:rgba(15,23,42,.38);
  opacity:0;
  pointer-events:none;
  transition:.25s ease;
  z-index:35;
}
.drawer-overlay.show{
  opacity:1;
  pointer-events:auto;
}
.sidebar{
  position:sticky;top:0;height:100vh;padding:24px 18px;
  background:rgba(255,255,255, 0.9);backdrop-filter:blur(18px);
  border-right:1px solid var(--line);
}
body.dark .sidebar{background:rgba(15,23,42,.66)}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:26px}
.brand img{width:58px;height:auto}
.brand strong{font-size:20px;background:linear-gradient(90deg,var(--brand),var(--brand-2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.nav-tabs{display:grid;gap:8px}
.tab-btn{
  border:0;background:transparent;color:var(--muted);padding:12px 14px;border-radius:12px;
  display:flex;align-items:center;gap:10px;text-align:left;cursor:pointer;font-weight:600;
}
.tab-btn:hover,.tab-btn.active{background:rgba(99,102,241,.11);color:var(--brand)}
.sidebar-footer{position:absolute;left:18px;right:18px;bottom:20px;padding:14px;border:1px solid var(--line);border-radius:16px;background:var(--panel)}
.sidebar-footer small{display:block;color:var(--muted);line-height:1.6}
.main{min-width:0;width:100%;max-width:100vw;overflow-x:hidden}
.topbar{
  height:78px;display:flex;align-items:center;justify-content:space-between;gap:16px;
  padding:0 28px;border-bottom:1px solid var(--line);background:rgba(255, 255, 255, 0.9);
  backdrop-filter:blur(18px);position:sticky;top:0;z-index:10;
}
body.dark .topbar{background:rgba(15,23,42,.66)}
.topbar-left{display:flex;align-items:center;gap:12px;min-width:0}
.mobile-toggle{display:none;width:42px;height:42px;border-radius:12px;border:1px solid var(--line);background:var(--panel);color:var(--ink);font-weight:800;cursor:pointer;align-items:center;justify-content:center}
.page-title h1{font-size:24px;letter-spacing:0}
.page-title p{color:var(--muted);font-size:13px;margin-top:4px}
.top-actions{display:flex;align-items:center;gap:10px;flex-wrap:nowrap;justify-content:flex-end;min-width:0}
.user-menu{display:flex;align-items:center;gap:10px;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:7px 10px}
.avatar{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:700;background:linear-gradient(135deg,var(--brand),var(--brand-2))}
.user-text{max-width:180px;min-width:0}
.user-text strong,.user-text span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.user-text strong{font-size:13px}.user-text span{font-size:12px;color:var(--muted)}
.pill-btn,.ghost-btn,.danger-btn{
  min-height:40px;border:0;border-radius:12px;padding:0 14px;font-weight:700;cursor:pointer;
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
}
.pill-btn{color:#fff;background:linear-gradient(135deg,var(--brand),var(--brand-2));box-shadow:0 10px 22px rgba(99,102,241,.22)}
.ghost-btn{color:var(--ink);background:var(--panel);border:1px solid var(--line)}
.danger-btn{color:#b91c1c;background:#fee2e2;border:1px solid #fecaca}
.content{padding:28px;display:grid;gap:22px;min-width:0;max-width:100%}
.panel{
  background:var(--panel);border:1px solid rgba(255,255,255,.48);border-radius:22px;
  box-shadow:var(--shadow);backdrop-filter:blur(16px);
  min-width:0;max-width:100%;
}
body.dark .panel{border-color:var(--line)}
.overview-hero{padding:24px;display:grid;grid-template-columns:1.3fr .7fr;gap:20px;align-items:center}
.eyebrow{font-size:12px;font-weight:800;color:var(--brand);text-transform:uppercase;letter-spacing:.08em}
.overview-hero h2{font-size:34px;line-height:1.18;margin:9px 0;background:linear-gradient(90deg,var(--brand),var(--brand-2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.overview-hero p{color:var(--muted);line-height:1.7}
.bot-picker{display:grid;gap:10px;padding:18px;border-radius:18px;background:rgba(255,255,255,.58);border:1px solid var(--line)}
body.dark .bot-picker{background:rgba(15,23,42,.56)}
.bot-picker label,.field label{font-size:13px;font-weight:700;color:var(--muted)}
select,input,textarea{
  width:100%;border:1px solid var(--line);background:var(--panel-strong);color:var(--ink);
  border-radius:12px;padding:12px 13px;outline:none;
}
textarea{min-height:92px;resize:vertical}
select:focus,input:focus,textarea:focus{box-shadow:0 0 0 3px rgba(99,102,241,.15);border-color:rgba(99,102,241,.55)}
.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.metric{padding:18px}
.metric-link{width:100%;text-align:left;color:inherit;cursor:pointer}
.metric-link:hover{border-color:rgba(99,102,241,.4);transform:translateY(-1px)}
.metric span{display:block;color:var(--muted);font-size:13px;font-weight:700}
.metric strong{display:block;font-size:28px;margin-top:8px}
.metric small{display:block;color:var(--muted);margin-top:7px;line-height:1.4}
.status-dot{display:inline-flex;align-items:center;gap:8px}
.status-dot:before{content:"";width:10px;height:10px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.14)}
.status-dot.inactive:before{background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.14)}
.status-toggle-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px}
.switch{position:relative;display:inline-flex;align-items:center;width:54px;height:30px;flex:0 0 auto}
.switch input{position:absolute;opacity:0;pointer-events:none}
.switch-slider{position:absolute;inset:0;border-radius:999px;background:#cbd5e1;cursor:pointer;transition:.2s ease}
.switch-slider:before{content:"";position:absolute;width:24px;height:24px;left:3px;top:3px;border-radius:50%;background:#fff;box-shadow:0 3px 8px rgba(15,23,42,.2);transition:.2s ease}
.switch input:checked + .switch-slider{background:#22c55e}
.switch input:checked + .switch-slider:before{transform:translateX(24px)}
.switch input:focus-visible + .switch-slider{box-shadow:0 0 0 3px rgba(99,102,241,.25)}
.quick-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.action-card{padding:18px;display:grid;gap:10px;align-content:start}
.action-card h3,.section-head h3{font-size:17px}
.action-card p,.muted{color:var(--muted);line-height:1.6;font-size:14px}
.tab-panel{display:none;gap:18px;min-width:0;max-width:100%}
.tab-panel.active{display:grid}
.section-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:20px 20px 0}
.section-body{padding:20px;min-width:0;max-width:100%}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;min-width:0}
.profile-grid{display:grid;grid-template-columns:180px minmax(0,1fr);gap:20px;align-items:start;min-width:0}
.profile-photo{display:grid;gap:12px;justify-items:center;padding:18px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.42)}
body.dark .profile-photo{background:rgba(15,23,42,.44)}
.profile-avatar{width:112px;height:112px;border-radius:50%;display:grid;place-items:center;color:#fff;font-size:36px;font-weight:800;background:linear-gradient(135deg,var(--brand),var(--brand-2));overflow:hidden}
.profile-avatar img{width:100%;height:100%;object-fit:cover}
.field{display:grid;gap:8px;min-width:0}
.field.full{grid-column:1/-1}
.panel-actions{grid-column:1/-1;display:flex;justify-content:flex-end;gap:10px;min-width:0;padding-top:4px}
.section-body > .panel-actions{padding-top:16px}
.swatches{display:flex;gap:10px;flex-wrap:wrap}
.swatch{width:34px;height:34px;border-radius:10px;border:2px solid rgba(255,255,255,.8);box-shadow:0 4px 10px rgba(15,23,42,.12);cursor:pointer}
.bot-image-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(72px,1fr));gap:10px}
.bot-image-option{border:1px solid var(--line);background:var(--panel-strong);border-radius:14px;padding:8px;cursor:pointer;display:grid;place-items:center}
.bot-image-option img{width:100%;aspect-ratio:1;object-fit:contain}
.bot-image-option input{position:absolute;opacity:0;pointer-events:none}
.bot-image-option:has(input:checked){border-color:rgba(99,102,241,.72);box-shadow:0 0 0 3px rgba(99,102,241,.14)}
.selected-bot-image{width:64px;height:64px;object-fit:contain;border-radius:16px;border:1px solid var(--line);background:var(--panel-strong);padding:8px}
.table-wrap{
  width:100%;
  max-width:100%;
  min-width:0;
  overflow-x:auto;
  overflow-y:hidden;
  -webkit-overflow-scrolling:touch;
  border-radius:0 0 18px 18px;
}
table{width:100%;border-collapse:collapse;min-width:720px}
th,td{text-align:left;padding:13px 14px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
td{font-size:14px;color:var(--ink);overflow-wrap:anywhere}
td .ghost-btn{white-space:normal}
.tag{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800;background:rgba(99,102,241,.12);color:var(--brand)}
.tag.good{background:rgba(34,197,94,.13);color:#15803d}.tag.bad{background:rgba(239,68,68,.12);color:#b91c1c}
.embed-box{position:relative}
code{display:block;white-space:pre-wrap;word-break:break-all;padding:16px;border-radius:14px;background:#111827;color:#e5e7eb;font-size:13px;line-height:1.6}
.inline-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;min-width:0;max-width:100%}
.inline-row > *{min-width:0}
.inline-row input{flex:1 1 220px;width:auto}
.faq-actions{display:flex;gap:8px;flex-wrap:wrap}
.faq-edit-field{display:none}
tr.editing .faq-display{display:none}
tr.editing .faq-edit-field{display:block}
tr.editing .faq-edit-btn{display:none}
.split{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;min-width:0}
.empty{padding:28px;text-align:center;color:var(--muted)}
.notice{padding:14px 16px;border-radius:14px;background:rgba(99,102,241,.1);border:1px solid rgba(99,102,241,.18);color:var(--ink);line-height:1.6}
.outside-faq-list{display:grid;gap:14px}
.outside-faq-card{padding:16px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.42);display:grid;gap:14px}
body.dark .outside-faq-card{background:rgba(15,23,42,.44)}
.outside-faq-meta{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.outside-faq-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.outside-faq-grid .field.full{grid-column:1/-1}
.lead-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.lead-master{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.42);margin-top:16px}
body.dark .lead-master{background:rgba(15,23,42,.44)}
.lead-section{display:grid;gap:14px;align-content:start}
.lead-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;border-bottom:1px solid var(--line);padding-bottom:12px}
.lead-option{display:grid;gap:12px;padding:14px;border:1px solid var(--line);border-radius:16px;background:rgba(255,255,255,.36)}
body.dark .lead-option{background:rgba(15,23,42,.38)}
.lead-option-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.lead-option h4{font-size:15px}
.lead-option small{display:block;color:var(--muted);line-height:1.5;margin-top:5px}
.lead-disabled{opacity:.56}
.input-help{font-size:12px;color:var(--muted);line-height:1.5}
.input-help.error{color:#b91c1c}
.toast{position:fixed;right:24px;bottom:24px;background:#111827;color:#fff;border-radius:12px;padding:12px 14px;box-shadow:0 12px 30px rgba(0,0,0,.25);opacity:0;transform:translateY(10px);pointer-events:none;transition:.25s}
.toast.show{opacity:1;transform:translateY(0)}
@media(max-width:1440px){
  .dashboard-shell{grid-template-columns:240px 1fr}
  .sidebar{padding:20px 14px}
  .tab-btn{padding:11px 12px;font-size:14px}
  .topbar{padding:0 20px;gap:12px}
  .page-title h1{font-size:22px}
  .top-actions{gap:8px}
  .pill-btn,.ghost-btn,.danger-btn{padding:0 12px}
  .content{padding:22px}
  .metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:1180px){
  .dashboard-shell{grid-template-columns:1fr}
  .drawer-overlay{display:block}
  .mobile-toggle{display:inline-flex;flex:0 0 auto}
  body.nav-open,body.account-open{overflow:hidden}
  .sidebar{
    position:fixed;
    top:0;
    left:0;
    width:min(320px,86vw);
    height:100dvh;
    padding:18px;
    z-index:45;
    border-right:1px solid var(--line);
    border-bottom:0;
    transform:translateX(-105%);
    transition:transform .25s ease;
    overflow-y:auto;
  }
  body.nav-open .sidebar{transform:translateX(0)}
  .brand{margin-bottom:18px}
  .brand img{width:50px}
  .nav-tabs{display:grid;gap:8px;overflow:visible;padding:0}
  .tab-btn{white-space:normal;min-height:44px;flex:auto;width:100%}
  .sidebar-footer{display:none}
  .topbar{position:sticky;top:0;height:auto;min-height:72px;z-index:25;padding:14px 18px}
  body.account-open .topbar{z-index:55}
  .top-actions{
    position:fixed;
    top:0;
    right:0;
    width:min(320px,86vw);
    max-width:100vw;
    height:100dvh;
    z-index:45;
    padding:72px 18px 18px;
    background:rgba(255,255,255,.9);
    border-left:1px solid var(--line);
    backdrop-filter:blur(18px);
    display:grid;
    align-content:start;
    gap:12px;
    transform:translateX(100%);
    transition:transform .25s ease;
    box-shadow:-18px 0 45px rgba(15,23,42,.12);
    visibility:hidden;
    pointer-events:none;
  }
  body.account-open #accountToggle{
    position:fixed;
    top:14px;
    right:18px;
    z-index:60;
  }
  body.dark .top-actions{background:rgba(15,23,42,.92)}
  body.account-open .top-actions{transform:translateX(0);visibility:visible;pointer-events:auto}
  .top-actions .pill-btn,.top-actions .ghost-btn{width:100%;justify-content:center}
  .top-actions > .user-menu{display:grid;justify-items:center;text-align:center;padding:16px}
  .top-actions .user-text{display:block;max-width:100%}
  .top-actions .user-text strong,.top-actions .user-text span{white-space:normal;word-break:break-word}
  .topbar-left{flex:1}
  .page-title{min-width:0}
  .page-title p{display:none}
  .metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
  .overview-hero,.split,.profile-grid{grid-template-columns:1fr}
  .profile-photo{justify-items:start;grid-template-columns:auto 1fr;align-items:center}
}
@media(max-width:720px){
  .topbar{padding:12px 14px}
  .mobile-toggle{width:40px;height:40px}
  body.account-open #accountToggle{top:12px;right:14px}
  .content{padding:14px;gap:16px}
  .panel{border-radius:18px}
  .section-head{align-items:flex-start;flex-direction:column;padding:16px 16px 0}
  .section-body{padding:16px}
  .overview-hero h2{font-size:28px}
  .metrics,.quick-actions,.form-grid,.outside-faq-grid,.lead-grid{grid-template-columns:1fr}
  .panel-actions{justify-content:stretch}
  .panel-actions .pill-btn,.panel-actions .ghost-btn,.panel-actions .danger-btn{width:100%}
  .user-menu{justify-content:space-between}
  select,input,textarea{font-size:16px}
  table{min-width:640px}
  th,td{padding:11px 12px}
  .table-wrap{width:100%;max-width:100%;border-radius:0}
  .inline-row input,.inline-row .ghost-btn{flex:1 1 100%;width:100%}
}
@media(max-width:480px){
  .sidebar{padding:12px}
  .brand{margin-bottom:10px}
  .brand img{width:40px}
  .brand strong{font-size:18px}
  .tab-btn{padding:10px 12px;font-size:14px;min-height:40px}
  .page-title h1{font-size:21px}
  .page-title p{font-size:12px;line-height:1.45}
  .overview-hero{padding:18px}
  .overview-hero h2{font-size:24px}
  .overview-hero p,.action-card p,.muted{font-size:13px}
  .metric{padding:15px}
  .metric strong{font-size:24px}
  .metric strong[style]{font-size:14px !important}
  .pill-btn,.ghost-btn,.danger-btn{min-height:42px;padding:0 12px;font-size:14px}
  .profile-photo{grid-template-columns:1fr;justify-items:center}
  .profile-avatar{width:96px;height:96px}
  code{font-size:12px;padding:13px}
  table{min-width:560px}
  th{font-size:11px}
  td{font-size:13px}
  .inline-row{display:grid;grid-template-columns:1fr}
  .lead-master,.lead-section-head,.lead-option-top{align-items:flex-start}
  .lead-master{display:grid}
  .toast{left:14px;right:14px;bottom:14px;text-align:center}
}
</style>
</head>
<body>
<div class="dashboard-shell">
  <div class="drawer-overlay" id="drawerOverlay" aria-hidden="true"></div>
  <aside class="sidebar">
    <a class="brand" href="dashboard.php">
      <img src="images/logo_img.png" alt="Vani AI">
      <strong>Vani AI</strong>
    </a>
    <div class="nav-tabs" role="tablist">
      <button class="tab-btn active" data-tab="overview">Dashboard</button>
      <button class="tab-btn" data-tab="setup">Chatbot Setup</button>
      <button class="tab-btn" data-tab="faqs">FAQ Management</button>
      <button class="tab-btn" data-tab="outside-faqs">Outside FAQs</button>
      <!-- Conversations tab hidden for now; keep this code for later.
      <button class="tab-btn" data-tab="logs">Conversations</button>
      -->
      <button class="tab-btn" data-tab="analytics">Analytics</button>
      <button class="tab-btn" data-tab="install">Integration</button>
      <!-- Bot Settings tab hidden for now; keep this code for later.
      <button class="tab-btn" data-tab="bot-settings">Bot Settings</button>
      -->
      <button class="tab-btn" data-tab="lead-generation">Lead Generation Setup</button>
      <button class="tab-btn" data-tab="premium">Premium</button>
      <button class="tab-btn" data-tab="profile">Profile</button>
      <button class="tab-btn" data-tab="billing">Billing</button>
      <a class="tab-btn" href="test-chatbot.php?bot=<?php echo h(urlencode($selectedBotId)); ?>">Test Chatbot</a>
    </div>
    <div class="sidebar-footer">
      <small>Current bot</small>
      <strong><?php echo h($botName); ?></strong>
      <!-- Bot ID hidden for now; keep this code for later.
      <small>ID: <?php echo h($selectedBotId ?: 'No bot found'); ?></small>
      -->
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <div class="topbar-left">
        <button class="mobile-toggle" id="navToggle" type="button" aria-label="Open dashboard menu" aria-expanded="false">☰</button>
        <div class="page-title">
          <h1>Chatbot Dashboard</h1>
          <!--<p>Overview, setup, FAQs, logs, analytics, install, settings, and billing.</p>-->
        </div>
      </div>
      <button class="mobile-toggle" id="accountToggle" type="button" aria-label="Open account menu" aria-expanded="false">⋯</button>
      <div class="top-actions">
        <button class="ghost-btn" id="themeToggle" type="button">Dark</button>
        <a class="ghost-btn" href="#profile" data-jump="profile">Profile</a>
        <a class="pill-btn" href="index.php">Create New bot</a>
        <div class="user-menu">
          <div class="avatar"><?php echo h($initials); ?></div>
          <div class="user-text">
            <strong><?php echo h($displayName ?: $email); ?></strong>
            <!--<span><?php echo h($accountId ?: 'Customer'); ?></span>-->
          </div>
          <a class="ghost-btn" href="logout.php">Logout</a>
        </div>
      </div>
    </header>

    <div class="content">
      <?php if (!$selectedBotId): ?>
        <section class="panel empty">
          <h2>No chatbot found yet</h2>
          <p class="muted">Create your first chatbot to unlock the dashboard overview and quick actions.</p>
          <div style="margin-top:18px"><a class="pill-btn" href="freebot.php">Create chatbot</a></div>
        </section>
      <?php endif; ?>

      <section class="tab-panel active" id="overview">
        <div class="panel overview-hero">
          <div>
            <span class="eyebrow">Your Chatbot</span>
            <h2><?php echo h($botName); ?></h2>
            <p>You are currently configuring the bot for the mentioned website.</p>
          </div>
          <form class="bot-picker" method="get" action="dashboard.php">
            <label for="bot">Select Website bot</label>
            <select id="bot" name="bot" onchange="this.form.submit()">
              <?php if (empty($bots)): ?>
                <option value="">No bots available</option>
              <?php endif; ?>
              <?php foreach ($bots as $bot): ?>
                <?php $cid = (string)($bot['customer_id'] ?? ''); ?>
                <option value="<?php echo h($cid); ?>" <?php echo $cid === $selectedBotId ? 'selected' : ''; ?>>
                  <?php echo h(($bot['website_name'] ?? 'Bot') . ' - ' . "🤖 "); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small class="muted">Select the appropriate chatbot from those created by: <?php echo h($email); ?></small>
          </form>
        </div>

        <div class="metrics">
          <div class="panel metric">
            <span>Chatbot Status</span>
            <strong id="overviewStatusText" class="status-dot <?php echo $isActive ? '' : 'inactive'; ?>"><?php echo $isActive ? 'Active' : 'Inactive'; ?></strong>
            <div class="status-toggle-row">
              <small id="overviewStatusHelp"><?php echo $isActive ? 'Chatbot is on for customers.' : 'Chatbot is off for customers.'; ?></small>
              <label class="switch" title="Turn chatbot on or off">
                <input id="overviewActiveSwitch" type="checkbox" <?php echo $isActive ? 'checked' : ''; ?> aria-label="Turn chatbot on or off">
                <span class="switch-slider"></span>
              </label>
            </div>
          </div>
          <div class="panel metric"><span>Total FAQs</span><strong><?php echo h($faqCount); ?></strong><small>Free plan limit: <?php echo h($freeFaqLimit); ?> FAQs.</small></div>
          <div class="panel metric"><span>Total Conversations</span><strong><?php echo h($conversationCount); ?></strong><small>Meaning: Total number of chat sessions started by users</small></div>
          <div class="panel metric"><span>Today's Queries</span><strong><?php echo h($todayQueries); ?></strong><small><?php echo h(gmdate('M d, Y')); ?> UTC</small></div>
          <div class="panel metric"><span>Response Accuracy</span><strong><?php echo h($accuracy); ?>%</strong><small>Basic answered vs total estimate.</small></div>
          <div class="panel metric"><span>Last Activity</span><strong id="lastActivityText" data-last-activity="<?php echo h($lastActivity); ?>" style="font-size:18px"><?php echo h($lastActivity ?: 'No activity yet'); ?></strong><small id="lastActivityZone">Latest tracked conversation.</small></div>
          <div class="panel metric">
            <span>Theme Color</span>
            <strong style="color:<?php echo h($themeColor); ?>"><?php echo h($themeColor); ?></strong>
            <?php if ($chatbotImage): ?><img class="selected-bot-image" style="margin-top:10px" src="<?php echo h($chatbotImage); ?>" alt="Selected chatbot image"><?php endif; ?>
            <small>Used by the chatbot box.</small>
          </div>
        </div>

        <div class="split">
          <div class="panel section-body">
            <h3>Popular Questions</h3>
            <p class="muted" style="margin:10px 0 14px">Trending questions customers asked that matched your FAQs.</p>
            <?php if (empty($topFaqQuestionCounts)): ?><p class="empty">No repeated FAQ questions yet.</p><?php endif; ?>
            <?php foreach (array_slice($topFaqQuestionCounts, 0, 5) as $item): ?>
              <div class="inline-row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:10px 0"><span><?php echo h($item['question']); ?></span><strong><?php echo h($item['count']); ?></strong></div>
            <?php endforeach; ?>
          </div>
          <button class="panel metric metric-link" type="button" data-jump="outside-faqs">
            <h3>Questions Outside FAQs</h3>
            <strong><?php echo h($unansweredCount); ?></strong>
            <small>Questions the bot could not answer. Open this list to edit and add answers to FAQs.</small>
          </button>
        </div>

        <div class="quick-actions">
          <div class="panel action-card">
            <h3>Add FAQ</h3>
            <p>Add a new question and answer to improve bot responses.</p>
            <button class="pill-btn" type="button" data-jump="faqs">Add FAQ</button>
          </div>
          <div class="panel action-card">
            <h3>Copy embed script</h3>
            <p>Install this bot on your website with one script tag.</p>
            <button class="pill-btn copy-btn" type="button" data-copy="<?php echo h($embedCode); ?>">Copy script</button>
          </div>
          <!-- Settings shortcut hidden while Bot Settings tab is hidden; keep this code for later.
          <div class="panel action-card">
            <h3>Settings</h3>
            <p>Change status, domains, notifications, and data controls.</p>
            <button class="pill-btn" type="button" data-jump="bot-settings">Open settings</button>
          </div>
          -->
        </div>
      </section>

      <section class="tab-panel" id="setup">
        <div class="panel">
          <div class="section-head"><h3>Chatbot Setup</h3></div>
          <div class="section-body form-grid">
            <input type="hidden" id="settingsCustomerId" value="<?php echo h($selectedBotId); ?>">
            <div class="field"><label>Bot Name</label><input id="botNameInput" value="<?php echo h($botName); ?>"></div>
            <div class="field"><label>Position</label><select id="positionInput"><option <?php echo $position === 'right' ? 'selected' : ''; ?>>right</option><option <?php echo $position === 'left' ? 'selected' : ''; ?>>left</option></select></div>
            <div class="field full"><label>Welcome Message</label><textarea id="welcomeInput"><?php echo h($welcomeMessage); ?></textarea></div>
            <div class="field"><label>Theme color</label><input id="themeColorInput" type="color" value="<?php echo h($themeColor); ?>"></div>
            <div class="field"><label>Language</label><select id="languageInput"><option><?php echo h($language); ?></option><option>English</option><option>Hindi</option><option>Spanish</option><option>French</option></select></div>
            <div class="field full">
              <label>Chatbot image</label>
              <?php if ($chatbotImage): ?>
                <img class="selected-bot-image" id="selectedBotImagePreview" src="<?php echo h($chatbotImage); ?>" alt="Selected chatbot image">
              <?php endif; ?>
              <div class="bot-image-grid" id="dashboardBotImageGrid">
                <?php foreach ($botImages as $index => $image): ?>
                  <label class="bot-image-option" title="Chatbot image <?php echo h($index + 1); ?>">
                    <input type="radio" name="dashboardBotImage" value="<?php echo h($image); ?>" <?php echo $image === $chatbotImage || (!$chatbotImage && $index === 0) ? 'checked' : ''; ?>>
                    <img src="<?php echo h($image); ?>" alt="Chatbot image <?php echo h($index + 1); ?>">
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="field full">
              <label>Quick colors</label>
              <div class="swatches">
                <button class="swatch" style="background:#6366f1" type="button" title="Indigo"></button>
                <button class="swatch" style="background:#06b6d4" type="button" title="Cyan"></button>
                <button class="swatch" style="background:#10b981" type="button" title="Green"></button>
                <button class="swatch" style="background:#ec4899" type="button" title="Pink"></button>
                <button class="swatch" style="background:#f59e0b" type="button" title="Amber"></button>
              </div>
            </div>
            <div class="panel-actions"><button class="pill-btn" type="button" id="saveSetupBtn">Save setup</button></div>
          </div>
        </div>
      </section>

      <section class="tab-panel" id="faqs">
        <div class="panel">
          <div class="section-head"><h3>FAQ Management</h3><span class="tag"><?php echo h($faqCount); ?>/<?php echo h($freeFaqLimit); ?> FAQs</span></div>
          <div class="section-body">
            <form id="faqForm" class="form-grid">
              <input type="hidden" id="faqCustomerId" value="<?php echo h($selectedBotId); ?>">
              <div class="field"><label>Question</label><input id="faqQuestion" placeholder="What do you want customers to ask?"></div>
              <div class="field"><label>Category</label><input id="faqCategory" placeholder="General"></div>
              <div class="field full"><label>Answer</label><textarea id="faqAnswer" placeholder="Write a helpful answer"></textarea></div>
              <div class="field full"><button class="pill-btn" type="submit">Add FAQ</button></div>
            </form>
          </div>
          <div class="section-body" style="padding-top:0">
            <div class="inline-row" style="margin-bottom:14px">
              <input id="faqSearch" placeholder="Search FAQs">
            </div>
            <div class="table-wrap">
              <table id="faqTable">
                <thead><tr><th>Question</th><th>Answer</th><th>Category</th><th>Actions</th></tr></thead>
                <tbody>
                  <?php foreach ($faqs as $faq): ?>
                    <tr data-faq-id="<?php echo h($faq['id'] ?? ''); ?>">
                      <td>
                        <span class="faq-display"><?php echo h($faq['question'] ?? ''); ?></span>
                        <textarea class="faq-edit-field faq-question-input" aria-label="FAQ question"><?php echo h($faq['question'] ?? ''); ?></textarea>
                      </td>
                      <td>
                        <span class="faq-display"><?php echo h($faq['answer'] ?? ''); ?></span>
                        <textarea class="faq-edit-field faq-answer-input" aria-label="FAQ answer"><?php echo h($faq['answer'] ?? ''); ?></textarea>
                      </td>
                      <td>
                        <span class="tag faq-display"><?php echo h($faq['category'] ?? 'General'); ?></span>
                        <input class="faq-edit-field faq-category-input" value="<?php echo h($faq['category'] ?? 'General'); ?>" aria-label="FAQ category">
                      </td>
                      <td>
                        <div class="faq-actions">
                          <button class="ghost-btn faq-edit-btn" type="button">Edit</button>
                          <button class="pill-btn faq-save-btn faq-edit-field" type="button">Save</button>
                          <button class="ghost-btn faq-cancel-btn faq-edit-field" type="button">Cancel</button>
                          <button class="danger-btn faq-delete-btn" type="button">Delete</button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <section class="tab-panel" id="outside-faqs">
        <div class="panel">
          <div class="section-head">
            <div>
              <h3>Questions Outside FAQs</h3>
              <p class="muted">Review questions the chatbot could not answer, edit the question if needed, write the right answer, and save it into FAQs.</p>
            </div>
            <span class="tag bad"><?php echo h($unansweredCount); ?> unanswered</span>
          </div>
          <div class="section-body">
            <?php if (empty($outsideFaqQuestions)): ?>
              <p class="empty">No outside-FAQ questions yet.</p>
            <?php else: ?>
              <div class="outside-faq-list">
                <?php foreach ($outsideFaqQuestions as $index => $item): ?>
                  <form class="outside-faq-card outsideFaqForm">
                    <div class="outside-faq-meta">
                      <span class="tag bad">Needs answer</span>
                      <small class="muted"><?php echo h($item['created_at'] ?: 'Time not recorded'); ?></small>
                    </div>
                    <?php if (!empty($item['bot_response'])): ?>
                      <div class="notice"><strong>Bot response:</strong><br><?php echo h($item['bot_response']); ?></div>
                    <?php endif; ?>
                    <div class="outside-faq-grid">
                      <input type="hidden" class="outsideCustomerId" value="<?php echo h($selectedBotId); ?>">
                      <div class="field full">
                        <label>Edit question</label>
                        <input class="outsideQuestion" value="<?php echo h($item['question']); ?>" aria-label="Edit unanswered customer question">
                      </div>
                      <div class="field full">
                        <label>Answer for this question</label>
                        <textarea class="outsideAnswer" placeholder="Write the answer customers should receive next time"></textarea>
                      </div>
                      <div class="field">
                        <label>Category</label>
                        <input class="outsideCategory" value="General">
                      </div>
                      <div class="field">
                        <label>&nbsp;</label>
                        <button class="pill-btn" type="submit">Add to FAQs</button>
                      </div>
                    </div>
                  </form>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <!-- Conversations tab content hidden for now; keep this code for later.
      <section class="tab-panel" id="logs">
        <div class="panel">
          <div class="section-head"><h3>Conversations / Logs</h3><span class="tag"><?php echo h($conversationCount); ?> total</span></div>
          <div class="section-body">
            <div class="table-wrap">
              <table>
                <thead><tr><th>User Question</th><th>Bot Response</th><th>Timestamp</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                  <?php if (empty($conversationRows)): ?>
                    <tr><td colspan="5" class="empty">No conversation logs yet. Run the SQL script and update chat logging to begin storing unanswered queries.</td></tr>
                  <?php endif; ?>
                  <?php foreach ($conversationRows as $row): ?>
                    <?php $answered = strtolower((string)($row['status'] ?? '')) === 'answered' || !empty($row['is_answered']); ?>
                    <tr>
                      <td><?php echo h($row['user_question'] ?? $row['question'] ?? ''); ?></td>
                      <td><?php echo h($row['bot_response'] ?? $row['response'] ?? ''); ?></td>
                      <td><?php echo h($row['created_at'] ?? ''); ?></td>
                      <td><span class="tag <?php echo $answered ? 'good' : 'bad'; ?>"><?php echo $answered ? 'Answered' : 'Unanswered'; ?></span></td>
                      <td><button class="ghost-btn" type="button" data-question="<?php echo h($row['user_question'] ?? $row['question'] ?? ''); ?>" data-jump="faqs">Add this as FAQ</button></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>
      -->

      <section class="tab-panel" id="analytics">
        <div class="split">
          <div class="panel metric"><span>Total chats</span><strong><?php echo h($conversationCount); ?></strong><small>All-time tracked chat events.</small></div>
          <div class="panel metric"><span>Unanswered questions</span><strong><?php echo h($unansweredPercent); ?>%</strong><small>Queries needing FAQ improvement.</small></div>
        </div>
        <div class="split">
          <div class="panel section-body">
            <h3>Daily / weekly usage</h3>
            <p class="muted" style="margin:10px 0 14px">Recent daily chat counts.</p>
            <?php if (empty($dailyCounts)): ?><p class="empty">No usage data yet.</p><?php endif; ?>
            <?php foreach (array_slice(array_reverse($dailyCounts, true), 0, 7, true) as $day => $count): ?>
              <div class="inline-row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:10px 0"><span><?php echo h($day); ?></span><strong><?php echo h($count); ?></strong></div>
            <?php endforeach; ?>
          </div>
          <div class="panel section-body">
            <h3>Top questions</h3>
            <p class="muted" style="margin:10px 0 14px">Most repeated customer questions.</p>
            <?php if (empty($topQuestionCounts)): ?><p class="empty">No top questions yet.</p><?php endif; ?>
            <?php foreach (array_slice($topQuestionCounts, 0, 5) as $item): ?>
              <div class="inline-row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:10px 0"><span><?php echo h($item['question']); ?></span><strong><?php echo h($item['count']); ?></strong></div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="panel metric"><span>Peak usage time</span><strong><?php echo h($peakUsage); ?></strong><small>Based on stored conversation timestamps.</small></div>
      </section>

      <section class="tab-panel" id="install">
        <div class="panel">
          <div class="section-head"><h3>Integration / Install</h3></div>
          <div class="section-body form-grid">
            <div class="field full">
              <div class="inline-row" style="justify-content:space-between;gap:16px">
                <div>
                  <label>Website verification</label>
                  <small class="input-help">When enabled, this bot only loads on the website connected with this bot.</small>
                </div>
                <label class="switch" title="Enable website verification">
                  <input id="websiteVerificationToggle" type="checkbox" <?php echo $websiteVerificationEnabled ? 'checked' : ''; ?> aria-label="Enable website verification">
                  <span class="switch-slider"></span>
                </label>
              </div>
              <div class="notice" style="margin-top:12px">
                <strong>Status:</strong> <span id="verificationStatusText"><?php echo h($verificationStatus); ?></span><br>
                <strong>Bot website:</strong> <?php echo h($websiteName ?: 'Not set'); ?>
              </div>
            </div>

            <div class="field full">
              <div class="inline-row" style="justify-content:space-between;gap:16px">
                <div>
                  <label>Allowed domains</label>
                  <small class="input-help">When enabled, this bot only works on the domains listed below.</small>
                </div>
                <label class="switch" title="Enable allowed domains">
                  <input id="allowedDomainsToggle" type="checkbox" <?php echo $allowedDomainsEnabled ? 'checked' : ''; ?> aria-label="Enable allowed domains">
                  <span class="switch-slider"></span>
                </label>
              </div>
              <textarea id="allowedDomainsInput" placeholder="example.com&#10;www.example.com"><?php echo h($allowedDomains); ?></textarea>
              <small class="input-help">Add one domain per line. You can also separate domains with commas.</small>
            </div>

            <div class="panel-actions full">
              <button class="pill-btn" type="button" id="saveIntegrationBtn">Save integration settings</button>
            </div>

            <div class="field full">
              <label>Install snippet</label>
              <div class="embed-box"><code id="embedCode"><?php echo h($embedCode ?: 'Create or select a bot to generate the embed script.'); ?></code></div>
              <div class="panel-actions">
                <button class="pill-btn copy-btn" type="button" data-copy="<?php echo h($embedCode); ?>">Copy JS snippet</button>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Bot Settings tab content hidden for now; keep this code for later.
      <section class="tab-panel" id="bot-settings">
        <div class="panel">
          <div class="section-head"><h3>Bot Settings</h3></div>
          <div class="section-body form-grid">
            <div class="field"><label>API key</label><input id="apiKeyInput" value="<?php echo h(first_value($settings, ['api_key'], '')); ?>" placeholder="Not required for free plan"></div>
            <div class="field"><label>Rate limit</label><input id="rateLimitInput" type="number" min="1" value="<?php echo h(first_value($settings, ['rate_limit'], '100')); ?>"></div>
            <div class="field"><label>Enable chatbot</label><select id="activeInput"><option value="true" <?php echo $isActive ? 'selected' : ''; ?>>Enabled</option><option value="false" <?php echo !$isActive ? 'selected' : ''; ?>>Disabled</option></select></div>
            <div class="field"><label>Notification preferences</label><select id="notificationInput"><option value="weekly_summary">Email weekly summary</option><option value="unanswered_only">Important unanswered queries only</option><option value="off">Off</option></select></div>
            <div class="field full"><label>Allowed domains</label><textarea id="domainsInput" placeholder="example.com"><?php echo h(first_value($settings, ['allowed_domains'], '')); ?></textarea></div>
            <div class="field full"><button class="danger-btn" type="button" data-save-note="Delete data request">Delete data</button></div>
            <div class="panel-actions"><button class="pill-btn" type="button" id="saveSettingsBtn">Save bot settings</button></div>
          </div>
        </div>
      </section>
      -->

      <section class="tab-panel" id="lead-generation">
        <div class="panel">
          <div class="section-head">
            <div>
              <h3>Lead Generation Setup</h3>
              <p class="muted">Control what customer information the chatbot asks for before handing over a lead.</p>
            </div>
          </div>
          <div class="section-body">
            <div class="lead-master">
              <div>
                <span class="eyebrow">Lead capture</span>
                <h3 style="margin-top:8px">Enable lead generation</h3>
                <p class="muted">Turn this on when you want the chatbot to collect contact details from users.</p>
              </div>
              <label class="switch" title="Enable lead generation">
                <input id="leadGenerationEnabled" class="lead-toggle" type="checkbox" <?php echo $leadEnabled ? 'checked' : ''; ?> aria-label="Enable lead generation">
                <span class="switch-slider"></span>
              </label>
            </div>

            <div class="lead-grid" id="leadServiceOptions" style="margin-top:16px">
              <div class="lead-section">
                <div class="lead-section-head">
                  <div>
                    <span class="eyebrow">Free Service</span>
                    <h3 style="margin-top:8px">Customer verification will be poor</h3>
                  </div>
                  <span class="tag">Free</span>
                </div>

                <div class="lead-option">
                  <div class="lead-option-top">
                    <div>
                      <h4>Get user location</h4>
                      <small>Ask users for their location during the chat flow.</small>
                    </div>
                    <label class="switch" title="Get user location">
                      <input id="leadCollectLocationToggle" class="lead-toggle" type="checkbox" <?php echo $leadCollectLocation ? 'checked' : ''; ?> aria-label="Get user location">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                </div>

                <div class="lead-option">
                  <div class="lead-option-top">
                    <div>
                      <h4>Collect email without OTP</h4>
                      <small>Ask users for an email address and save it without sending a verification code.</small>
                    </div>
                    <label class="switch" title="Collect email without OTP">
                      <input id="leadCollectEmailToggle" class="lead-toggle" type="checkbox" <?php echo $leadCollectEmail ? 'checked' : ''; ?> aria-label="Collect email without OTP">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                </div>

                <div class="lead-option">
                  <div class="lead-option-top">
                    <div>
                      <h4>Collect mobile without OTP</h4>
                      <small>Ask users for a phone number and save it without OTP verification.</small>
                    </div>
                    <label class="switch" title="Collect mobile without OTP">
                      <input id="leadCollectMobileToggle" class="lead-toggle" type="checkbox" <?php echo $leadCollectMobile ? 'checked' : ''; ?> aria-label="Collect mobile without OTP">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                </div>

                <div class="lead-option">
                  <div class="lead-option-top">
                    <div>
                      <h4>Notify lead by email</h4>
                      <small>Send an email notification when lead details are captured.</small>
                    </div>
                    <label class="switch" title="Notify lead by email">
                      <input id="leadEmailNotifyToggle" class="lead-toggle" type="checkbox" <?php echo $leadNotifyByEmail ? 'checked' : ''; ?> aria-label="Notify lead by email">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                  <div class="field">
                    <label>Notification email</label>
                    <input id="leadNotificationEmail" type="email" value="<?php echo h($leadNotificationEmail); ?>" placeholder="<?php echo h($email); ?>" autocomplete="email">
                    <small class="input-help" id="leadNotificationEmailHelp">Lead notifications can be sent to this email address.</small>
                  </div>
                </div>

                <!-- WhatsApp redirect moved to Paid Service -->
              </div>

              <div class="lead-section">
                <div class="lead-section-head">
                  <div>
                    <span class="eyebrow">Paid Service</span>
                    <h3 style="margin-top:8px">Real leads</h3>
                  </div>
                  <span class="tag good">Paid</span>
                </div>

                <div class="lead-option">
                  <div class="lead-option-top">
                    <div>
                      <h4>Collect email with OTP</h4>
                      <small>Verify the lead with an OTP sent to the user's email address.</small>
                    </div>
                    <label class="switch" title="Collect email with OTP">
                      <input id="leadEmailOtpToggle" class="lead-toggle" type="checkbox" <?php echo $leadVerifyEmailOtp ? 'checked' : ''; ?> aria-label="Collect email with OTP">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                </div>

                <div class="lead-option">
                  <div class="lead-option-top">
                    <div>
                      <h4>Mobile OTP verification</h4>
                      <small>Verify the lead with an OTP sent to the user's mobile number.</small>
                    </div>
                    <label class="switch" title="Mobile OTP verification">
                      <input id="leadMobileOtpToggle" class="lead-toggle" type="checkbox" <?php echo $leadVerifyMobileOtp ? 'checked' : ''; ?> aria-label="Mobile OTP verification">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                </div>

                <div class="lead-option">
                  <div class="lead-option-top">
                    <div>
                      <h4>Redirect to WhatsApp Business</h4>
                      <small>Send users to the customer's WhatsApp Business account after lead capture.</small>
                    </div>
                    <label class="switch" title="Redirect to WhatsApp Business">
                      <input id="whatsappLeadToggle" class="lead-toggle" type="checkbox" <?php echo $leadRedirectWhatsapp ? 'checked' : ''; ?> aria-label="Redirect to WhatsApp Business">
                      <span class="switch-slider"></span>
                    </label>
                  </div>
                  <div class="field">
                    <label>WhatsApp Business mobile number</label>
                    <input id="whatsappLeadNumber" type="tel" inputmode="tel" value="<?php echo h($leadWhatsappNumber); ?>" placeholder="+919876543210" autocomplete="tel" maxlength="16">
                    <small class="input-help" id="whatsappLeadHelp">Use country code and digits only, for example +919876543210.</small>
                  </div>
                  <button class="ghost-btn" type="button" data-save-note="WhatsApp Business integration">No WhatsApp Business account?</button>
                </div>

                <div class="notice">
                  <strong>Backend pending:</strong><br>
                  WhatsApp redirect can be connected when that integration is ready.
                </div>
              </div>
            </div>

            <div class="panel-actions">
              <button class="pill-btn" type="button" id="saveLeadSetupBtn">Save lead setup</button>
            </div>
          </div>
        </div>
      </section>

      <section class="tab-panel" id="premium">
        <div class="panel section-body">
          <span class="eyebrow">Premium</span>
          <h2 style="margin:8px 0 10px">Premium Plans</h2>
          <p class="muted">Premium plans will be added here. Upgrade is required when you need more than <?php echo h($freeFaqLimit); ?> FAQs.</p>
        </div>
      </section>

      <section class="tab-panel" id="profile">
        <div class="panel">
          <div class="section-head">
            <div>
              <h3>Customer Profile</h3>
              <p class="muted">This is your account identity. It is separate from chatbot setup and bot settings.</p>
            </div>
          </div>
          <div class="section-body profile-grid">
            <div class="profile-photo">
              <div class="profile-avatar" id="profileAvatarPreview">
                <?php if (!empty($profile['avatar_url'])): ?>
                  <img src="<?php echo h($profile['avatar_url']); ?>" alt="Profile avatar">
                <?php else: ?>
                  <?php echo h($initials); ?>
                <?php endif; ?>
              </div>
              <div class="field">
                <label>Image URL or avatar</label>
                <input id="profileAvatarInput" value="<?php echo h($profile['avatar_url'] ?? ''); ?>" placeholder="https://example.com/photo.jpg">
                <button class="ghost-btn" type="button" id="generateAvatarBtn">Create avatar</button>
              </div>
            </div>

            <div class="form-grid">
              <div class="field"><label>First name</label><input id="firstNameInput" value="<?php echo h($profileFirstName); ?>" autocomplete="given-name"></div>
              <div class="field"><label>Last name</label><input id="lastNameInput" value="<?php echo h($profileLastName); ?>" autocomplete="family-name"></div>
              <div class="field full"><label>Account email</label><input id="profileEmailInput" value="<?php echo h($email); ?>" readonly></div>
              <div class="field"><label>Country code</label><input id="countryCodeInput" list="countryCodeList" value="<?php echo h($profile['country_code'] ?? '+91'); ?>" placeholder="+91" title="Type any country calling code"></div>
              <div class="field"><label>Mobile number</label><input id="mobileInput" value="<?php echo h($profile['mobile_number'] ?? ''); ?>" placeholder="9876543210" autocomplete="tel"></div>
              <div class="field full"><label>Address line 1</label><input id="address1Input" value="<?php echo h($profile['address_line1'] ?? ''); ?>" autocomplete="address-line1"></div>
              <div class="field full"><label>Address line 2</label><input id="address2Input" value="<?php echo h($profile['address_line2'] ?? ''); ?>" autocomplete="address-line2"></div>
              <div class="field"><label>City</label><input id="cityInput" value="<?php echo h($profile['city'] ?? ''); ?>" autocomplete="address-level2"></div>
              <div class="field"><label>State / Region</label><input id="stateInput" value="<?php echo h($profile['state_region'] ?? ''); ?>" autocomplete="address-level1"></div>
              <div class="field"><label>Country</label><input id="countryInput" value="<?php echo h($profile['country'] ?? 'India'); ?>" autocomplete="country-name"></div>
              <div class="field"><label>Postal code</label><input id="postalInput" value="<?php echo h($profile['postal_code'] ?? ''); ?>" autocomplete="postal-code"></div>
              <div class="field full"><label>Location notes</label><textarea id="locationInput" placeholder="Office, branch, timezone, preferred contact hours"><?php echo h($profile['location_notes'] ?? ''); ?></textarea></div>
              <div class="field"><label>New password</label><input id="newPasswordInput" type="password" placeholder="Minimum 8 characters" autocomplete="new-password"></div>
              <div class="field"><label>Confirm password</label><input id="confirmPasswordInput" type="password" placeholder="Repeat new password" autocomplete="new-password"></div>
            </div>
            <div class="panel-actions"><button class="pill-btn" type="button" id="saveProfileBtn">Save profile</button></div>
          </div>
        </div>
        <datalist id="countryCodeList">
          <option value="+1">United States / Canada</option>
          <option value="+7">Russia / Kazakhstan</option>
          <option value="+20">Egypt</option>
          <option value="+27">South Africa</option>
          <option value="+30">Greece</option>
          <option value="+31">Netherlands</option>
          <option value="+32">Belgium</option>
          <option value="+33">France</option>
          <option value="+34">Spain</option>
          <option value="+36">Hungary</option>
          <option value="+39">Italy</option>
          <option value="+40">Romania</option>
          <option value="+41">Switzerland</option>
          <option value="+43">Austria</option>
          <option value="+44">United Kingdom</option>
          <option value="+45">Denmark</option>
          <option value="+46">Sweden</option>
          <option value="+47">Norway</option>
          <option value="+48">Poland</option>
          <option value="+49">Germany</option>
          <option value="+52">Mexico</option>
          <option value="+55">Brazil</option>
          <option value="+60">Malaysia</option>
          <option value="+61">Australia</option>
          <option value="+62">Indonesia</option>
          <option value="+63">Philippines</option>
          <option value="+64">New Zealand</option>
          <option value="+65">Singapore</option>
          <option value="+66">Thailand</option>
          <option value="+81">Japan</option>
          <option value="+82">South Korea</option>
          <option value="+84">Vietnam</option>
          <option value="+86">China</option>
          <option value="+90">Turkey</option>
          <option value="+91">India</option>
          <option value="+92">Pakistan</option>
          <option value="+93">Afghanistan</option>
          <option value="+94">Sri Lanka</option>
          <option value="+95">Myanmar</option>
          <option value="+971">United Arab Emirates</option>
          <option value="+972">Israel</option>
          <option value="+973">Bahrain</option>
          <option value="+974">Qatar</option>
          <option value="+975">Bhutan</option>
          <option value="+977">Nepal</option>
          <option value="+966">Saudi Arabia</option>
          <option value="+968">Oman</option>
          <option value="+880">Bangladesh</option>
        </datalist>
      </section>

      <section class="tab-panel" id="billing">
        <div class="panel section-body">
          <span class="eyebrow">Billing</span>
          <h2 style="margin:8px 0 10px">Free Plan</h2>
          <p class="muted">Free Plan - <?php echo h($freeFaqLimit); ?> FAQs limit. Upgrade options can be added here when paid plans are ready.</p>
          <div class="metrics" style="margin-top:18px">
            <div class="panel metric"><span>Plan</span><strong>Free</strong><small>No credit card required.</small></div>
            <div class="panel metric"><span>FAQ usage</span><strong><?php echo h($faqCount); ?>/<?php echo h($freeFaqLimit); ?></strong><small><?php echo h(max(0, $freeFaqLimit - $faqCount)); ?> FAQs remaining.</small></div>
            <div class="panel metric"><span>Bot type</span><strong>Free</strong><small>From customer_bot_type.</small></div>
            <div class="panel metric"><span>Lead email notifications</span><strong>Available</strong><small>Configure email lead alerts in Lead Generation Setup.</small></div>
            <div class="panel metric"><span>Upgrade</span><strong>Future</strong><small>Ready for paid tiers.</small></div>
          </div>
        </div>
      </section>
    </div>
  </main>
</div>
<div class="toast" id="toast">Copied</div>
<script>
const tabs = document.querySelectorAll(".tab-btn");
const panels = document.querySelectorAll(".tab-panel");
const toast = document.getElementById("toast");
const themeToggle = document.getElementById("themeToggle");
const navToggle = document.getElementById("navToggle");
const accountToggle = document.getElementById("accountToggle");
const drawerOverlay = document.getElementById("drawerOverlay");
const accountToggleText = accountToggle?.textContent || "";
let currentFaqCount = <?php echo json_encode($faqCount); ?>;
const freeFaqLimit = <?php echo json_encode($freeFaqLimit); ?>;

function setDrawer(type, open) {
  const isNav = type === "nav";
  document.body.classList.toggle("nav-open", isNav && open);
  document.body.classList.toggle("account-open", !isNav && open);
  drawerOverlay?.classList.toggle("show", open);
  navToggle?.setAttribute("aria-expanded", String(isNav && open));
  accountToggle?.setAttribute("aria-expanded", String(!isNav && open));
  accountToggle?.setAttribute("aria-label", !isNav && open ? "Close account menu" : "Open account menu");
  if (accountToggle) accountToggle.textContent = !isNav && open ? "x" : accountToggleText;
}

function closeDrawers() {
  document.body.classList.remove("nav-open", "account-open");
  drawerOverlay?.classList.remove("show");
  navToggle?.setAttribute("aria-expanded", "false");
  accountToggle?.setAttribute("aria-expanded", "false");
  accountToggle?.setAttribute("aria-label", "Open account menu");
  if (accountToggle) accountToggle.textContent = accountToggleText;
}

navToggle?.addEventListener("click", () => {
  setDrawer("nav", !document.body.classList.contains("nav-open"));
});

accountToggle?.addEventListener("click", () => {
  setDrawer("account", !document.body.classList.contains("account-open"));
});

drawerOverlay?.addEventListener("click", closeDrawers);

document.addEventListener("keydown", event => {
  if (event.key === "Escape") closeDrawers();
});

function showToast(text) {
  toast.textContent = text;
  toast.classList.add("show");
  setTimeout(() => toast.classList.remove("show"), 1800);
}

function openTab(id) {
  tabs.forEach(tab => tab.classList.toggle("active", tab.dataset.tab === id));
  panels.forEach(panel => panel.classList.toggle("active", panel.id === id));
  document.querySelector(`.tab-btn[data-tab="${id}"]`)?.scrollIntoView({
    block: "nearest",
    inline: "nearest",
    behavior: "smooth"
  });
  if (location.hash !== "#" + id) history.replaceState(null, "", "#" + id);
  closeDrawers();
}

tabs.forEach(tab => tab.addEventListener("click", () => openTab(tab.dataset.tab)));
document.querySelectorAll("[data-jump]").forEach(btn => {
  btn.addEventListener("click", event => {
    const target = btn.dataset.jump;
    if (target) {
      event.preventDefault();
      openTab(target);
      if (btn.dataset.question) {
        document.getElementById("faqQuestion").value = btn.dataset.question;
      }
      window.scrollTo({top:0, behavior:"smooth"});
    }
  });
});

document.querySelectorAll(".copy-btn").forEach(btn => {
  btn.addEventListener("click", async () => {
    const text = btn.dataset.copy || document.getElementById("embedCode")?.textContent || "";
    if (!text.trim()) return showToast("Nothing to copy yet");
    await navigator.clipboard.writeText(text);
    showToast("Copied to clipboard");
  });
});

themeToggle.addEventListener("click", () => {
  const dark = !document.body.classList.contains("dark");
  document.body.classList.toggle("dark", dark);
  themeToggle.textContent = dark ? "Bright" : "Dark";
  localStorage.setItem("vani_dashboard_theme", dark ? "dark" : "bright");
});

if (localStorage.getItem("vani_dashboard_theme") === "dark") {
  document.body.classList.add("dark");
  themeToggle.textContent = "Bright";
}

function formatLastActivityForBrowser() {
  const lastActivityText = document.getElementById("lastActivityText");
  const lastActivityZone = document.getElementById("lastActivityZone");
  const raw = lastActivityText?.dataset.lastActivity || "";
  if (!raw) return;

  const normalized = /z$|[+-]\d{2}:?\d{2}$/i.test(raw) ? raw : raw + "Z";
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) return;

  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || "your browser timezone";
  lastActivityText.textContent = new Intl.DateTimeFormat(undefined, {
    year: "numeric",
    month: "short",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    timeZoneName: "short"
  }).format(date);
  if (lastActivityZone) lastActivityZone.textContent = `Latest tracked conversation in ${timezone}.`;
}

formatLastActivityForBrowser();

document.querySelectorAll("[data-save-note]").forEach(btn => {
  btn.addEventListener("click", () => showToast(btn.dataset.saveNote + " UI is ready. Connect save API next."));
});

const leadGenerationEnabled = document.getElementById("leadGenerationEnabled");
const leadServiceOptions = document.getElementById("leadServiceOptions");
const leadCollectLocationToggle = document.getElementById("leadCollectLocationToggle");
const leadCollectEmailToggle = document.getElementById("leadCollectEmailToggle");
const leadCollectMobileToggle = document.getElementById("leadCollectMobileToggle");
const leadEmailOtpToggle = document.getElementById("leadEmailOtpToggle");
const whatsappLeadToggle = document.getElementById("whatsappLeadToggle");
const whatsappLeadNumber = document.getElementById("whatsappLeadNumber");
const whatsappLeadHelp = document.getElementById("whatsappLeadHelp");
const leadEmailNotifyToggle = document.getElementById("leadEmailNotifyToggle");
const leadNotificationEmail = document.getElementById("leadNotificationEmail");
const leadNotificationEmailHelp = document.getElementById("leadNotificationEmailHelp");
const leadMobileOtpToggle = document.getElementById("leadMobileOtpToggle");

function validateWhatsappLeadNumber(showMessage = false) {
  if (!whatsappLeadNumber || !whatsappLeadHelp) return true;
  const value = whatsappLeadNumber.value.trim();
  const required = !!whatsappLeadToggle?.checked;
  const valid = (!required && !value) || /^\+?[1-9]\d{7,14}$/.test(value);
  whatsappLeadHelp.classList.toggle("error", !valid);
  whatsappLeadNumber.setAttribute("aria-invalid", String(!valid));
  whatsappLeadHelp.textContent = valid
    ? "Use country code and digits only, for example +919876543210."
    : "Enter a valid mobile number with country code and 8 to 15 digits.";
  if (!valid && showMessage) showToast("Enter a valid WhatsApp mobile number");
  return valid;
}

function validateLeadNotificationEmail(showMessage = false) {
  if (!leadNotificationEmail || !leadNotificationEmailHelp) return true;
  const value = leadNotificationEmail.value.trim();
  const required = !!leadEmailNotifyToggle?.checked;
  const valid = (!required && !value) || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
  leadNotificationEmailHelp.classList.toggle("error", !valid);
  leadNotificationEmail.setAttribute("aria-invalid", String(!valid));
  leadNotificationEmailHelp.textContent = valid
    ? "Lead notifications can be sent to this email address."
    : "Enter a valid email address for lead notifications.";
  if (!valid && showMessage) showToast("Enter a valid notification email");
  return valid;
}

function updateLeadGenerationUI() {
  const enabled = !!leadGenerationEnabled?.checked;
  leadServiceOptions?.classList.toggle("lead-disabled", !enabled);
  leadServiceOptions?.querySelectorAll("input, button").forEach(control => {
    control.disabled = !enabled;
  });
}

leadGenerationEnabled?.addEventListener("change", () => {
  updateLeadGenerationUI();
  showToast(leadGenerationEnabled.checked ? "Lead generation enabled" : "Lead generation disabled");
});

leadEmailOtpToggle?.addEventListener("change", () => {
  if (leadCollectEmailToggle) leadCollectEmailToggle.checked = true;
  if (!leadEmailOtpToggle.checked) showToast("Email will be saved without OTP");
});

leadMobileOtpToggle?.addEventListener("change", () => {
  if (leadCollectMobileToggle) leadCollectMobileToggle.checked = true;
  if (!leadMobileOtpToggle.checked) showToast("Mobile number will be saved without OTP");
});

whatsappLeadNumber?.addEventListener("input", () => {
  whatsappLeadNumber.value = whatsappLeadNumber.value.replace(/[^\d+]/g, "").replace(/(?!^)\+/g, "");
  validateWhatsappLeadNumber(false);
});

whatsappLeadNumber?.addEventListener("blur", () => validateWhatsappLeadNumber(false));

whatsappLeadToggle?.addEventListener("change", () => validateWhatsappLeadNumber(false));

leadNotificationEmail?.addEventListener("input", () => validateLeadNotificationEmail(false));

leadNotificationEmail?.addEventListener("blur", () => validateLeadNotificationEmail(false));

leadEmailNotifyToggle?.addEventListener("change", () => validateLeadNotificationEmail(false));

document.getElementById("saveLeadSetupBtn")?.addEventListener("click", async event => {
  if (leadGenerationEnabled?.checked && whatsappLeadToggle?.checked && !validateWhatsappLeadNumber(true)) {
    whatsappLeadNumber?.focus();
    return;
  }
  if (leadGenerationEnabled?.checked && leadEmailNotifyToggle?.checked && !validateLeadNotificationEmail(true)) {
    leadNotificationEmail?.focus();
    return;
  }

  const button = event.currentTarget;
  button.disabled = true;
  button.textContent = "Saving...";

  const response = await fetch("/api.php?action=save_lead_generation_settings", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({
      customer_id: document.getElementById("settingsCustomerId")?.value || "",
      is_enabled: !!leadGenerationEnabled?.checked,
      collect_location: !!leadCollectLocationToggle?.checked,
      collect_email: !!leadCollectEmailToggle?.checked,
      collect_mobile: !!leadCollectMobileToggle?.checked,
      verify_email_otp: !!leadEmailOtpToggle?.checked,
      notify_lead_by_email: !!leadEmailNotifyToggle?.checked,
      notification_email: leadNotificationEmail?.value.trim() || "",
      redirect_whatsapp: !!whatsappLeadToggle?.checked,
      whatsapp_mobile_number: whatsappLeadNumber?.value.trim() || "",
      verify_mobile_otp: !!leadMobileOtpToggle?.checked
    })
  });

  const data = await response.json().catch(() => ({}));
  button.disabled = false;
  button.textContent = "Save lead setup";

  if (!data.success) {
    showToast(data.message || "Lead generation settings could not be saved");
    return;
  }

  showToast("Lead generation settings saved");
});

updateLeadGenerationUI();

document.querySelectorAll(".swatch").forEach(swatch => {
  swatch.addEventListener("click", () => {
    const colorInput = document.getElementById("themeColorInput");
    colorInput.value = rgbToHex(getComputedStyle(swatch).backgroundColor);
  });
});

document.querySelectorAll("input[name='dashboardBotImage']").forEach(input => {
  input.addEventListener("change", () => {
    const preview = document.getElementById("selectedBotImagePreview");
    if (preview && input.checked) preview.src = input.value;
  });
});

function rgbToHex(rgb) {
  const values = rgb.match(/\d+/g).map(Number);
  return "#" + values.slice(0, 3).map(v => v.toString(16).padStart(2, "0")).join("");
}

document.getElementById("faqSearch")?.addEventListener("input", event => {
  const q = event.target.value.toLowerCase();
  document.querySelectorAll("#faqTable tbody tr").forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(q) ? "" : "none";
  });
});

async function addFaq(customerId, question, answer, category) {
  const response = await fetch("/api.php?action=add_faq", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, faqs: [{question, answer, category}]})
  });

  const data = await response.json().catch(() => ({}));
  return data;
}

document.getElementById("faqForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  const customerId = document.getElementById("faqCustomerId").value;
  const question = document.getElementById("faqQuestion").value.trim();
  const answer = document.getElementById("faqAnswer").value.trim();
  const category = document.getElementById("faqCategory").value.trim() || "General";
  if (!customerId) return showToast("Select a bot first");
  if (!question || !answer) return showToast("Question and answer are required");
  if (currentFaqCount >= freeFaqLimit) {
    showToast("Upgrade to add more FAQs");
    openTab("premium");
    return;
  }

  const saved = await addFaq(customerId, question, answer, category);
  if (saved.requires_premium) {
    showToast(saved.message || "Upgrade to add more FAQs");
    openTab("premium");
    return;
  }
  if (saved.error || saved.success === false) {
    showToast("FAQ could not be saved");
    return;
  }
  showToast("FAQ added");
  setTimeout(() => location.reload(), 700);
});

document.querySelectorAll(".outsideFaqForm").forEach(form => {
  form.addEventListener("submit", async event => {
    event.preventDefault();
    const customerId = form.querySelector(".outsideCustomerId")?.value || "";
    const question = form.querySelector(".outsideQuestion")?.value.trim() || "";
    const answer = form.querySelector(".outsideAnswer")?.value.trim() || "";
    const category = form.querySelector(".outsideCategory")?.value.trim() || "General";
    const button = form.querySelector("button[type='submit']");

    if (!customerId) return showToast("Select a bot first");
    if (!question || !answer) return showToast("Question and answer are required");
    if (currentFaqCount >= freeFaqLimit) {
      showToast("Upgrade to add more FAQs");
      openTab("premium");
      return;
    }

    if (button) {
      button.disabled = true;
      button.textContent = "Saving...";
    }

    const saved = await addFaq(customerId, question, answer, category);
    if (saved.requires_premium) {
      if (button) {
        button.disabled = false;
        button.textContent = "Add to FAQs";
      }
      showToast(saved.message || "Upgrade to add more FAQs");
      openTab("premium");
      return;
    }
    if (saved.error || saved.success === false) {
      if (button) {
        button.disabled = false;
        button.textContent = "Add to FAQs";
      }
      showToast("FAQ could not be saved");
      return;
    }

    form.style.opacity = ".65";
    form.querySelectorAll("input, textarea, button").forEach(input => input.disabled = true);
    if (button) button.textContent = "Added";
    currentFaqCount++;
    showToast("Added to FAQs");
  });
});

function setFaqRowEditing(row, editing) {
  row.classList.toggle("editing", editing);
}

document.getElementById("faqTable")?.addEventListener("click", async event => {
  const button = event.target.closest("button");
  const row = event.target.closest("tr[data-faq-id]");
  if (!button || !row) return;

  const customerId = document.getElementById("faqCustomerId").value;
  const faqId = row.dataset.faqId || "";
  const questionInput = row.querySelector(".faq-question-input");
  const answerInput = row.querySelector(".faq-answer-input");
  const categoryInput = row.querySelector(".faq-category-input");

  if (button.classList.contains("faq-edit-btn")) {
    setFaqRowEditing(row, true);
    questionInput?.focus();
    return;
  }

  if (button.classList.contains("faq-cancel-btn")) {
    questionInput.value = row.children[0].querySelector(".faq-display").textContent.trim();
    answerInput.value = row.children[1].querySelector(".faq-display").textContent.trim();
    categoryInput.value = row.children[2].querySelector(".faq-display").textContent.trim();
    setFaqRowEditing(row, false);
    return;
  }

  if (button.classList.contains("faq-save-btn")) {
    const question = questionInput.value.trim();
    const answer = answerInput.value.trim();
    const category = categoryInput.value.trim() || "General";
    if (!question || !answer) return showToast("Question and answer are required");

    button.disabled = true;
    button.textContent = "Saving...";
    const response = await fetch("/api.php?action=update_faq", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({customer_id: customerId, id: faqId, question, answer, category})
    });
    const data = await response.json().catch(() => ({}));
    button.disabled = false;
    button.textContent = "Save";

    if (!data.success) return showToast(data.message || "FAQ could not be updated");

    row.children[0].querySelector(".faq-display").textContent = question;
    row.children[1].querySelector(".faq-display").textContent = answer;
    row.children[2].querySelector(".faq-display").textContent = category;
    setFaqRowEditing(row, false);
    showToast("FAQ updated");
    return;
  }

  if (button.classList.contains("faq-delete-btn")) {
    if (!confirm("Delete this FAQ?")) return;
    button.disabled = true;
    button.textContent = "Deleting...";
    const response = await fetch("/api.php?action=delete_faq", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({customer_id: customerId, id: faqId})
    });
    const data = await response.json().catch(() => ({}));
    if (!data.success) {
      button.disabled = false;
      button.textContent = "Delete";
      return showToast(data.message || "FAQ could not be deleted");
    }
    row.remove();
    currentFaqCount = Math.max(0, currentFaqCount - 1);
    showToast("FAQ deleted");
  }
});

async function saveDashboardSettings(extraPayload) {
  const customerId = document.getElementById("settingsCustomerId")?.value || "";
  if (!customerId) {
    showToast("Select a bot first");
    return false;
  }

  const response = await fetch("/api.php?action=save_dashboard_settings", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, ...extraPayload})
  });

  const data = await response.json().catch(() => ({}));
  if (!data.success) {
    showToast("Settings could not be saved");
    return false;
  }
  showToast("Settings saved");
  return true;
}

function setOverviewActiveUI(isActive) {
  const statusText = document.getElementById("overviewStatusText");
  const statusHelp = document.getElementById("overviewStatusHelp");
  const activeSwitch = document.getElementById("overviewActiveSwitch");
  const activeInput = document.getElementById("activeInput");
  if (statusText) {
    statusText.textContent = isActive ? "Active" : "Inactive";
    statusText.classList.toggle("inactive", !isActive);
  }
  if (statusHelp) {
    statusHelp.textContent = isActive ? "Chatbot is on for customers." : "Chatbot is off for customers.";
  }
  if (activeSwitch) activeSwitch.checked = isActive;
  if (activeInput) activeInput.value = isActive ? "true" : "false";
}

document.getElementById("overviewActiveSwitch")?.addEventListener("change", async event => {
  const isActive = event.target.checked;
  setOverviewActiveUI(isActive);
  const saved = await saveDashboardSettings({is_active: isActive});
  if (!saved) setOverviewActiveUI(!isActive);
});

document.getElementById("saveSetupBtn")?.addEventListener("click", () => {
  saveDashboardSettings({
    bot_name: document.getElementById("botNameInput").value.trim(),
    welcome_message: document.getElementById("welcomeInput").value.trim(),
    theme_color: document.getElementById("themeColorInput").value,
    avatar_url: document.querySelector("input[name='dashboardBotImage']:checked")?.value || "",
    position: document.getElementById("positionInput").value,
    language: document.getElementById("languageInput").value
  });
});

document.getElementById("saveSettingsBtn")?.addEventListener("click", () => {
  const isActive = document.getElementById("activeInput").value === "true";
  saveDashboardSettings({
    api_key: document.getElementById("apiKeyInput").value.trim(),
    rate_limit: Number(document.getElementById("rateLimitInput").value || 100),
    is_active: isActive,
    notification_preference: document.getElementById("notificationInput").value,
    allowed_domains: document.getElementById("domainsInput").value.trim()
  }).then(saved => {
    if (saved) setOverviewActiveUI(isActive);
  });
});

document.getElementById("saveIntegrationBtn")?.addEventListener("click", async event => {
  const button = event.currentTarget;
  const websiteVerificationEnabled = !!document.getElementById("websiteVerificationToggle")?.checked;
  const allowedDomainsEnabled = !!document.getElementById("allowedDomainsToggle")?.checked;
  const allowedDomains = document.getElementById("allowedDomainsInput")?.value.trim() || "";

  if (allowedDomainsEnabled && !allowedDomains) {
    showToast("Add at least one allowed domain");
    document.getElementById("allowedDomainsInput")?.focus();
    return;
  }

  button.disabled = true;
  button.textContent = "Saving...";
  const saved = await saveDashboardSettings({
    website_verification_enabled: websiteVerificationEnabled,
    allowed_domains_enabled: allowedDomainsEnabled,
    allowed_domains: allowedDomains,
    verification_status: websiteVerificationEnabled ? "Pending" : "Disabled"
  });
  button.disabled = false;
  button.textContent = "Save integration settings";

  if (saved) {
    const statusText = document.getElementById("verificationStatusText");
    if (statusText) statusText.textContent = websiteVerificationEnabled ? "Pending" : "Disabled";
  }
});

function updateProfileAvatarPreview(value) {
  const preview = document.getElementById("profileAvatarPreview");
  const firstName = document.getElementById("firstNameInput")?.value.trim() || "";
  const fallback = (firstName || document.getElementById("profileEmailInput")?.value || "V").charAt(0).toUpperCase();
  preview.textContent = "";
  if (value && value.startsWith("http")) {
    const img = document.createElement("img");
    img.src = value;
    img.alt = "Profile avatar";
    preview.appendChild(img);
  } else {
    preview.textContent = value || fallback;
  }
}

document.getElementById("profileAvatarInput")?.addEventListener("input", event => {
  updateProfileAvatarPreview(event.target.value.trim());
});

document.getElementById("generateAvatarBtn")?.addEventListener("click", () => {
  const firstName = document.getElementById("firstNameInput").value.trim();
  const lastName = document.getElementById("lastNameInput").value.trim();
  const email = document.getElementById("profileEmailInput").value.trim();
  const initials = ((firstName.charAt(0) || email.charAt(0) || "V") + (lastName.charAt(0) || "")).toUpperCase();
  document.getElementById("profileAvatarInput").value = initials;
  updateProfileAvatarPreview(initials);
});

document.getElementById("saveProfileBtn")?.addEventListener("click", async () => {
  const newPassword = document.getElementById("newPasswordInput").value;
  const confirmPassword = document.getElementById("confirmPasswordInput").value;

  if (newPassword || confirmPassword) {
    if (newPassword !== confirmPassword) return showToast("Passwords do not match");
    if (newPassword.length < 8) return showToast("Password needs at least 8 characters");
  }

  const response = await fetch("/api.php?action=save_customer_profile", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({
      email: document.getElementById("profileEmailInput").value.trim(),
      first_name: document.getElementById("firstNameInput").value.trim(),
      last_name: document.getElementById("lastNameInput").value.trim(),
      avatar_url: document.getElementById("profileAvatarInput").value.trim(),
      country_code: document.getElementById("countryCodeInput").value.trim(),
      mobile_number: document.getElementById("mobileInput").value.trim(),
      address_line1: document.getElementById("address1Input").value.trim(),
      address_line2: document.getElementById("address2Input").value.trim(),
      city: document.getElementById("cityInput").value.trim(),
      state_region: document.getElementById("stateInput").value.trim(),
      country: document.getElementById("countryInput").value.trim(),
      postal_code: document.getElementById("postalInput").value.trim(),
      location_notes: document.getElementById("locationInput").value.trim(),
      new_password: newPassword
    })
  });

  const data = await response.json().catch(() => ({}));
  if (!data.success) {
    showToast(data.message || "Profile could not be saved");
    return;
  }
  document.getElementById("newPasswordInput").value = "";
  document.getElementById("confirmPasswordInput").value = "";
  showToast(data.password ? "Profile and password saved" : "Profile saved");
});

const hash = location.hash.replace("#", "");
if (hash && document.getElementById(hash)) openTab(hash);
</script>
</body>
</html>
