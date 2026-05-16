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
$widgetUrl = "https://cdn.jsdelivr.net/gh/codrant-code/chbdd@main/widget36.js";

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

$profileRows = safe_data(supabase(
    "GET",
    "customer_profiles?select=*&email=eq." . urlencode($email) . "&limit=1"
));

$settings = $settingsRows[0] ?? [];
$profile = $profileRows[0] ?? [];
$faqCount = count($faqs);
$conversationCount = count($conversationRows);
$today = gmdate('Y-m-d');
$todayQueries = 0;
$lastActivity = '';
$answeredCount = 0;
$unansweredCount = 0;
$dailyCounts = [];
$hourCounts = [];
$topQuestionCounts = [];

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

$accuracy = $conversationCount > 0
    ? round(($answeredCount / max(1, $conversationCount)) * 100)
    : ($faqCount > 0 ? 100 : 0);

$unansweredPercent = $conversationCount > 0
    ? round(($unansweredCount / max(1, $conversationCount)) * 100)
    : 0;

uasort($topQuestionCounts, fn($a, $b) => $b['count'] <=> $a['count']);
arsort($hourCounts);
$peakUsage = !empty($hourCounts) ? array_key_first($hourCounts) . ":00" : "Not enough data";
$themeColor = first_value($selectedBot, ['theme_color'], '#6366f1');
$botName = first_value($settings, ['bot_name'], first_value($selectedBot, ['website_name'], 'Vani Bot'));
$welcomeMessage = first_value($settings, ['welcome_message'], 'Hi, how can I help you today?');
$position = first_value($settings, ['position'], 'right');
$language = first_value($settings, ['language'], 'English');
$rawActive = $settings['is_active'] ?? true;
$isActive = is_bool($rawActive) ? $rawActive : ((string)$rawActive !== 'false');
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
.dashboard-shell{min-height:100vh;display:grid;grid-template-columns:260px 1fr}
.sidebar{
  position:sticky;top:0;height:100vh;padding:24px 18px;
  background:rgba(255,255,255,.58);backdrop-filter:blur(18px);
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
.main{min-width:0}
.topbar{
  height:78px;display:flex;align-items:center;justify-content:space-between;gap:16px;
  padding:0 28px;border-bottom:1px solid var(--line);background:rgba(255,255,255,.58);
  backdrop-filter:blur(18px);position:sticky;top:0;z-index:10;
}
body.dark .topbar{background:rgba(15,23,42,.66)}
.page-title h1{font-size:24px;letter-spacing:0}
.page-title p{color:var(--muted);font-size:13px;margin-top:4px}
.top-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.user-menu{display:flex;align-items:center;gap:10px;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:7px 10px}
.avatar{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:700;background:linear-gradient(135deg,var(--brand),var(--brand-2))}
.user-text{max-width:180px}
.user-text strong,.user-text span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.user-text strong{font-size:13px}.user-text span{font-size:12px;color:var(--muted)}
.pill-btn,.ghost-btn,.danger-btn{
  min-height:40px;border:0;border-radius:12px;padding:0 14px;font-weight:700;cursor:pointer;
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
}
.pill-btn{color:#fff;background:linear-gradient(135deg,var(--brand),var(--brand-2));box-shadow:0 10px 22px rgba(99,102,241,.22)}
.ghost-btn{color:var(--ink);background:var(--panel);border:1px solid var(--line)}
.danger-btn{color:#b91c1c;background:#fee2e2;border:1px solid #fecaca}
.content{padding:28px;display:grid;gap:22px}
.panel{
  background:var(--panel);border:1px solid rgba(255,255,255,.48);border-radius:22px;
  box-shadow:var(--shadow);backdrop-filter:blur(16px);
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
.metric span{display:block;color:var(--muted);font-size:13px;font-weight:700}
.metric strong{display:block;font-size:28px;margin-top:8px}
.metric small{display:block;color:var(--muted);margin-top:7px;line-height:1.4}
.status-dot{display:inline-flex;align-items:center;gap:8px}
.status-dot:before{content:"";width:10px;height:10px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.14)}
.status-dot.inactive:before{background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.14)}
.quick-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.action-card{padding:18px;display:grid;gap:10px;align-content:start}
.action-card h3,.section-head h3{font-size:17px}
.action-card p,.muted{color:var(--muted);line-height:1.6;font-size:14px}
.tab-panel{display:none;gap:18px}
.tab-panel.active{display:grid}
.section-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:20px 20px 0}
.section-body{padding:20px}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.profile-grid{display:grid;grid-template-columns:180px 1fr;gap:20px;align-items:start}
.profile-photo{display:grid;gap:12px;justify-items:center;padding:18px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.42)}
body.dark .profile-photo{background:rgba(15,23,42,.44)}
.profile-avatar{width:112px;height:112px;border-radius:50%;display:grid;place-items:center;color:#fff;font-size:36px;font-weight:800;background:linear-gradient(135deg,var(--brand),var(--brand-2));overflow:hidden}
.profile-avatar img{width:100%;height:100%;object-fit:cover}
.field{display:grid;gap:8px}
.field.full{grid-column:1/-1}
.swatches{display:flex;gap:10px;flex-wrap:wrap}
.swatch{width:34px;height:34px;border-radius:10px;border:2px solid rgba(255,255,255,.8);box-shadow:0 4px 10px rgba(15,23,42,.12);cursor:pointer}
.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse;min-width:720px}
th,td{text-align:left;padding:13px 14px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
td{font-size:14px;color:var(--ink)}
.tag{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800;background:rgba(99,102,241,.12);color:var(--brand)}
.tag.good{background:rgba(34,197,94,.13);color:#15803d}.tag.bad{background:rgba(239,68,68,.12);color:#b91c1c}
.embed-box{position:relative}
code{display:block;white-space:pre-wrap;word-break:break-all;padding:16px;border-radius:14px;background:#111827;color:#e5e7eb;font-size:13px;line-height:1.6}
.inline-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.split{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.empty{padding:28px;text-align:center;color:var(--muted)}
.notice{padding:14px 16px;border-radius:14px;background:rgba(99,102,241,.1);border:1px solid rgba(99,102,241,.18);color:var(--ink);line-height:1.6}
.toast{position:fixed;right:24px;bottom:24px;background:#111827;color:#fff;border-radius:12px;padding:12px 14px;box-shadow:0 12px 30px rgba(0,0,0,.25);opacity:0;transform:translateY(10px);pointer-events:none;transition:.25s}
.toast.show{opacity:1;transform:translateY(0)}
@media(max-width:1100px){
  .dashboard-shell{grid-template-columns:1fr}
  .sidebar{position:sticky;top:0;height:auto;padding:14px 16px;z-index:20;border-right:0;border-bottom:1px solid var(--line)}
  .brand{margin-bottom:12px}
  .brand img{width:46px}
  .nav-tabs{display:flex;overflow-x:auto;gap:8px;padding-bottom:4px;scrollbar-width:thin}
  .tab-btn{white-space:nowrap;min-height:42px;flex:0 0 auto}
  .sidebar-footer{display:none}
  .metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
  .overview-hero,.split,.profile-grid{grid-template-columns:1fr}
  .profile-photo{justify-items:start;grid-template-columns:auto 1fr;align-items:center}
}
@media(max-width:720px){
  .topbar{height:auto;align-items:stretch;flex-direction:column;padding:14px 16px;position:relative}
  .top-actions{display:grid;grid-template-columns:1fr 1fr;align-items:stretch}
  .top-actions > .user-menu{grid-column:1/-1}
  .top-actions .pill-btn,.top-actions .ghost-btn{width:100%}
  .content{padding:14px;gap:16px}
  .panel{border-radius:18px}
  .section-head{align-items:flex-start;flex-direction:column}
  .overview-hero h2{font-size:28px}
  .metrics,.quick-actions,.form-grid{grid-template-columns:1fr}
  .user-text{display:none}
  .user-menu{justify-content:space-between}
  table{min-width:640px}
  th,td{padding:11px 12px}
}
@media(max-width:480px){
  .sidebar{padding:12px}
  .brand strong{font-size:18px}
  .tab-btn{padding:10px 12px;font-size:14px}
  .page-title h1{font-size:21px}
  .overview-hero{padding:18px}
  .overview-hero h2{font-size:24px}
  .metric strong{font-size:24px}
  .top-actions{grid-template-columns:1fr}
  .profile-photo{grid-template-columns:1fr;justify-items:center}
  .profile-avatar{width:96px;height:96px}
  .toast{left:14px;right:14px;bottom:14px;text-align:center}
}
</style>
</head>
<body>
<div class="dashboard-shell">
  <aside class="sidebar">
    <a class="brand" href="dashboard.php">
      <img src="images/logo.png" alt="Vani AI">
      <strong>Vani</strong>
    </a>
    <div class="nav-tabs" role="tablist">
      <button class="tab-btn active" data-tab="overview">Dashboard</button>
      <button class="tab-btn" data-tab="setup">Chatbot Setup</button>
      <button class="tab-btn" data-tab="faqs">FAQ Management</button>
      <button class="tab-btn" data-tab="logs">Conversations</button>
      <button class="tab-btn" data-tab="analytics">Analytics</button>
      <button class="tab-btn" data-tab="install">Integration</button>
      <button class="tab-btn" data-tab="bot-settings">Bot Settings</button>
      <button class="tab-btn" data-tab="profile">Profile</button>
      <button class="tab-btn" data-tab="billing">Billing</button>
    </div>
    <div class="sidebar-footer">
      <small>Current bot</small>
      <strong><?php echo h($botName); ?></strong>
      <small>ID: <?php echo h($selectedBotId ?: 'No bot found'); ?></small>
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <div class="page-title">
        <h1>Customer Dashboard</h1>
        <p>Overview, setup, FAQs, logs, analytics, install, settings, and billing.</p>
      </div>
      <div class="top-actions">
        <button class="ghost-btn" id="themeToggle" type="button">Dark</button>
        <a class="ghost-btn" href="#profile" data-jump="profile">Profile setting</a>
        <a class="pill-btn" href="freebot.php">Create bot</a>
        <div class="user-menu">
          <div class="avatar"><?php echo h($initials); ?></div>
          <div class="user-text">
            <strong><?php echo h($displayName ?: $email); ?></strong>
            <span><?php echo h($accountId ?: 'Customer'); ?></span>
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
            <span class="eyebrow">Important</span>
            <h2><?php echo h($botName); ?></h2>
            <p>Your customer ID is your bot ID. Every dashboard metric and action below is currently scoped to this selected bot.</p>
          </div>
          <form class="bot-picker" method="get" action="dashboard.php">
            <label for="bot">Select customer bot</label>
            <select id="bot" name="bot" onchange="this.form.submit()">
              <?php if (empty($bots)): ?>
                <option value="">No bots available</option>
              <?php endif; ?>
              <?php foreach ($bots as $bot): ?>
                <?php $cid = (string)($bot['customer_id'] ?? ''); ?>
                <option value="<?php echo h($cid); ?>" <?php echo $cid === $selectedBotId ? 'selected' : ''; ?>>
                  <?php echo h(($bot['website_name'] ?? 'Bot') . ' - ' . $cid); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small class="muted">Loaded from chatbot_signups for <?php echo h($email); ?></small>
          </form>
        </div>

        <div class="metrics">
          <div class="panel metric"><span>Chatbot Status</span><strong class="status-dot <?php echo $isActive ? '' : 'inactive'; ?>"><?php echo $isActive ? 'Active' : 'Inactive'; ?></strong><small>Enable or disable in Bot Settings.</small></div>
          <div class="panel metric"><span>Total FAQs</span><strong><?php echo h($faqCount); ?></strong><small>Free plan limit: 100 FAQs.</small></div>
          <div class="panel metric"><span>Total Conversations</span><strong><?php echo h($conversationCount); ?></strong><small>From logs, falling back to FAQ usage.</small></div>
          <div class="panel metric"><span>Today's Queries</span><strong><?php echo h($todayQueries); ?></strong><small><?php echo h(gmdate('M d, Y')); ?> UTC</small></div>
          <div class="panel metric"><span>Response Accuracy</span><strong><?php echo h($accuracy); ?>%</strong><small>Basic answered vs total estimate.</small></div>
          <div class="panel metric"><span>Last Activity</span><strong style="font-size:18px"><?php echo h($lastActivity ?: 'No activity yet'); ?></strong><small>Latest tracked conversation.</small></div>
          <div class="panel metric"><span>Theme Color</span><strong style="color:<?php echo h($themeColor); ?>"><?php echo h($themeColor); ?></strong><small>Used by the chat widget.</small></div>
          <div class="panel metric"><span>Bot ID</span><strong style="font-size:15px;word-break:break-all"><?php echo h($selectedBotId ?: 'Not set'); ?></strong><small>Same as customer_id.</small></div>
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
          <div class="panel action-card">
            <h3>Settings</h3>
            <p>Change status, domains, notifications, and data controls.</p>
            <button class="pill-btn" type="button" data-jump="bot-settings">Open settings</button>
          </div>
        </div>
      </section>

      <section class="tab-panel" id="setup">
        <div class="panel">
          <div class="section-head"><h3>Chatbot Setup</h3><button class="pill-btn" type="button" id="saveSetupBtn">Save setup</button></div>
          <div class="section-body form-grid">
            <input type="hidden" id="settingsCustomerId" value="<?php echo h($selectedBotId); ?>">
            <div class="field"><label>Bot Name</label><input id="botNameInput" value="<?php echo h($botName); ?>"></div>
            <div class="field"><label>Position</label><select id="positionInput"><option <?php echo $position === 'right' ? 'selected' : ''; ?>>right</option><option <?php echo $position === 'left' ? 'selected' : ''; ?>>left</option></select></div>
            <div class="field full"><label>Welcome Message</label><textarea id="welcomeInput"><?php echo h($welcomeMessage); ?></textarea></div>
            <div class="field"><label>Theme color</label><input id="themeColorInput" type="color" value="<?php echo h($themeColor); ?>"></div>
            <div class="field"><label>Language</label><select id="languageInput"><option><?php echo h($language); ?></option><option>English</option><option>Hindi</option><option>Spanish</option><option>French</option></select></div>
            <div class="field full"><label>Avatar/logo upload</label><input type="file" accept="image/*"></div>
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
          </div>
        </div>
      </section>

      <section class="tab-panel" id="faqs">
        <div class="panel">
          <div class="section-head"><h3>FAQ Management</h3><span class="tag"><?php echo h($faqCount); ?> FAQs</span></div>
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
              <button class="ghost-btn" type="button" data-save-note="Bulk upload">Bulk upload - future</button>
            </div>
            <div class="table-wrap">
              <table id="faqTable">
                <thead><tr><th>Question</th><th>Answer</th><th>Category</th><th>Actions</th></tr></thead>
                <tbody>
                  <?php foreach ($faqs as $faq): ?>
                    <tr>
                      <td><?php echo h($faq['question'] ?? ''); ?></td>
                      <td><?php echo h($faq['answer'] ?? ''); ?></td>
                      <td><span class="tag"><?php echo h($faq['category'] ?? 'General'); ?></span></td>
                      <td><button class="ghost-btn" type="button" data-save-note="Edit/delete FAQ">Edit / Delete</button></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <section class="tab-panel" id="logs">
        <div class="panel">
          <div class="section-head"><h3>Conversations / Logs</h3><span class="tag"><?php echo h($conversationCount); ?> total</span></div>
          <div class="section-body table-wrap">
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
      </section>

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
          <div class="section-head"><h3>Integration / Install</h3><button class="pill-btn copy-btn" type="button" data-copy="<?php echo h($embedCode); ?>">Copy JS snippet</button></div>
          <div class="section-body">
            <div class="embed-box"><code id="embedCode"><?php echo h($embedCode ?: 'Create or select a bot to generate the embed script.'); ?></code></div>
            <div class="split" style="margin-top:16px">
              <div class="notice"><strong>Website verification status:</strong><br><?php echo h(first_value($settings, ['verification_status'], 'Pending')); ?></div>
              <div class="notice"><strong>Allowed domains:</strong><br><?php echo h(first_value($settings, ['allowed_domains'], 'Add domains in Bot Settings')); ?></div>
            </div>
          </div>
        </div>
      </section>

      <section class="tab-panel" id="bot-settings">
        <div class="panel">
          <div class="section-head"><h3>Bot Settings</h3><button class="pill-btn" type="button" id="saveSettingsBtn">Save bot settings</button></div>
          <div class="section-body form-grid">
            <div class="field"><label>API key</label><input id="apiKeyInput" value="<?php echo h(first_value($settings, ['api_key'], '')); ?>" placeholder="Not required for free plan"></div>
            <div class="field"><label>Rate limit</label><input id="rateLimitInput" type="number" min="1" value="<?php echo h(first_value($settings, ['rate_limit'], '100')); ?>"></div>
            <div class="field"><label>Enable chatbot</label><select id="activeInput"><option value="true" <?php echo $isActive ? 'selected' : ''; ?>>Enabled</option><option value="false" <?php echo !$isActive ? 'selected' : ''; ?>>Disabled</option></select></div>
            <div class="field"><label>Notification preferences</label><select id="notificationInput"><option value="weekly_summary">Email weekly summary</option><option value="unanswered_only">Important unanswered queries only</option><option value="off">Off</option></select></div>
            <div class="field full"><label>Allowed domains</label><textarea id="domainsInput" placeholder="example.com"><?php echo h(first_value($settings, ['allowed_domains'], '')); ?></textarea></div>
            <div class="field full"><button class="danger-btn" type="button" data-save-note="Delete data request">Delete data</button></div>
          </div>
        </div>
      </section>

      <section class="tab-panel" id="profile">
        <div class="panel">
          <div class="section-head">
            <div>
              <h3>Customer Profile</h3>
              <p class="muted">This is your account identity. It is separate from chatbot setup and bot settings.</p>
            </div>
            <button class="pill-btn" type="button" id="saveProfileBtn">Save profile</button>
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
          <p class="muted">Free Plan - 100 FAQs limit. Upgrade options can be added here when paid plans are ready.</p>
          <div class="metrics" style="margin-top:18px">
            <div class="panel metric"><span>Plan</span><strong>Free</strong><small>No credit card required.</small></div>
            <div class="panel metric"><span>FAQ usage</span><strong><?php echo h($faqCount); ?>/100</strong><small><?php echo h(max(0, 100 - $faqCount)); ?> FAQs remaining.</small></div>
            <div class="panel metric"><span>Bot type</span><strong>Free</strong><small>From customer_bot_type.</small></div>
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

function showToast(text) {
  toast.textContent = text;
  toast.classList.add("show");
  setTimeout(() => toast.classList.remove("show"), 1800);
}

function openTab(id) {
  tabs.forEach(tab => tab.classList.toggle("active", tab.dataset.tab === id));
  panels.forEach(panel => panel.classList.toggle("active", panel.id === id));
  if (location.hash !== "#" + id) history.replaceState(null, "", "#" + id);
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

document.querySelectorAll("[data-save-note]").forEach(btn => {
  btn.addEventListener("click", () => showToast(btn.dataset.saveNote + " UI is ready. Connect save API next."));
});

document.querySelectorAll(".swatch").forEach(swatch => {
  swatch.addEventListener("click", () => {
    const colorInput = document.getElementById("themeColorInput");
    colorInput.value = rgbToHex(getComputedStyle(swatch).backgroundColor);
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

document.getElementById("faqForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  const customerId = document.getElementById("faqCustomerId").value;
  const question = document.getElementById("faqQuestion").value.trim();
  const answer = document.getElementById("faqAnswer").value.trim();
  const category = document.getElementById("faqCategory").value.trim() || "General";
  if (!customerId) return showToast("Select a bot first");
  if (!question || !answer) return showToast("Question and answer are required");

  const response = await fetch("/api.php?action=add_faq", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, faqs: [{question, answer, category}]})
  });

  const data = await response.json().catch(() => ({}));
  if (data.error || data.success === false) {
    showToast("FAQ could not be saved");
    return;
  }
  showToast("FAQ added");
  setTimeout(() => location.reload(), 700);
});

async function saveDashboardSettings(extraPayload) {
  const customerId = document.getElementById("settingsCustomerId")?.value || "";
  if (!customerId) return showToast("Select a bot first");

  const response = await fetch("/api.php?action=save_dashboard_settings", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, ...extraPayload})
  });

  const data = await response.json().catch(() => ({}));
  if (!data.success) {
    showToast("Settings could not be saved");
    return;
  }
  showToast("Settings saved");
}

document.getElementById("saveSetupBtn")?.addEventListener("click", () => {
  saveDashboardSettings({
    bot_name: document.getElementById("botNameInput").value.trim(),
    welcome_message: document.getElementById("welcomeInput").value.trim(),
    theme_color: document.getElementById("themeColorInput").value,
    position: document.getElementById("positionInput").value,
    language: document.getElementById("languageInput").value
  });
});

document.getElementById("saveSettingsBtn")?.addEventListener("click", () => {
  saveDashboardSettings({
    api_key: document.getElementById("apiKeyInput").value.trim(),
    rate_limit: Number(document.getElementById("rateLimitInput").value || 100),
    is_active: document.getElementById("activeInput").value === "true",
    notification_preference: document.getElementById("notificationInput").value,
    allowed_domains: document.getElementById("domainsInput").value.trim()
  });
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
