<?php
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/core.php';

if (!is_authenticated_user()) {
    header("Location: login.php");
    exit;
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function safe_data(array $response): array {
    $data = $response['data'] ?? null;
    if (!is_array($data) || $data === []) {
        return [];
    }
    if (array_keys($data) !== range(0, count($data) - 1)) {
        return [];
    }
    return $data;
}

$email = authenticated_email();
$selectedBotId = trim($_GET['bot'] ?? '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$embedVersion = is_file(__DIR__ . '/embed.js') ? filemtime(__DIR__ . '/embed.js') : time();
$embedUrl = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/embed.js?v=' . $embedVersion;

$bots = safe_data(supabase(
    "GET",
    "chatbot_signups?select=customer_id,website_name&email=eq." . urlencode($email) . "&order=created_at.desc"
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

$botName = $selectedBot['website_name'] ?? 'Selected chatbot';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/logo_img.png">
<title>Test Chatbot - Vani AI</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',sans-serif}
:root{--bg-a:#f0f9ff;--bg-b:#eef2ff;--bg-c:#faf5ff;--panel:rgba(255,255,255,.82);--ink:#0f172a;--muted:#64748b;--line:rgba(148,163,184,.24);--ghost:#fff;--brand:#6366f1;--brand-2:#ec4899;--shadow:0 18px 45px rgba(15,23,42,.09)}
body.dark{--bg-a:#020617;--bg-b:#111827;--bg-c:#172554;--panel:rgba(15,23,42,.82);--ink:#e5e7eb;--muted:#94a3b8;--line:rgba(148,163,184,.24);--ghost:rgba(15,23,42,.74);--shadow:0 18px 45px rgba(0,0,0,.28)}
body{min-height:100vh;color:var(--ink);background:linear-gradient(135deg,var(--bg-a),var(--bg-b),var(--bg-c));padding:28px}
.shell{width:min(980px,100%);margin:0 auto;display:grid;gap:18px}
.top{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
.top{position:relative;z-index:2147483647}
.brand{display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit}
.brand img{width:54px;height:auto}
.brand strong{font-size:20px;background:linear-gradient(90deg,var(--brand),var(--brand-2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:22px;padding:24px;box-shadow:var(--shadow);backdrop-filter:blur(16px)}
.eyebrow{font-size:12px;font-weight:800;color:var(--brand);text-transform:uppercase;letter-spacing:.08em}
h1{font-size:32px;line-height:1.2;margin:10px 0}
.top-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
p{color:var(--muted);line-height:1.7}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
.pill-btn,.ghost-btn{min-height:42px;border-radius:12px;padding:0 14px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}
.pill-btn{color:#fff;background:linear-gradient(135deg,var(--brand),var(--brand-2));border:0}
.ghost-btn{color:var(--ink);background:var(--ghost);border:1px solid var(--line)}
.snippet{display:block;white-space:pre-wrap;word-break:break-all;margin-top:14px;padding:16px;border-radius:14px;background:#111827;color:#e5e7eb;font-size:13px;line-height:1.6}
.empty{text-align:center}
iframe[data-vani-chatbot-frame]{z-index:2147483000!important}
@media(max-width:640px){body{padding:16px}.panel{padding:18px;border-radius:18px}h1{font-size:26px}.pill-btn,.ghost-btn{width:100%}}
</style>
</head>
<body>
<main class="shell">
  <div class="top">
    <a class="brand" href="dashboard.php">
      <img src="images/logo_img.png" alt="Vani AI">
      <strong>Vani AI</strong>
    </a>
    <div class="top-actions">
      <button class="ghost-btn" type="button" id="themeToggle">Dark Mode</button>
      <a class="ghost-btn" href="dashboard.php<?php echo $selectedBotId ? '?bot=' . h(urlencode($selectedBotId)) : ''; ?>">Back to dashboard</a>
    </div>
  </div>

  <?php if (!$selectedBotId || empty($selectedBot)): ?>
    <section class="panel empty">
      <span class="eyebrow">Test chatbot</span>
      <h1>No bot selected</h1>
      <p>Create or select a chatbot from the dashboard before opening the test page.</p>
      <div class="actions"><a class="pill-btn" href="dashboard.php">Open dashboard</a></div>
    </section>
  <?php else: ?>
    <section class="panel">
      <span class="eyebrow">Test chatbot</span>
      <h1><?php echo h($botName); ?></h1>
      <p>This page loads the secure iframe chatbot embed with the selected Bot ID. Use the chat bubble on this page to test the customer experience.</p>
      <code class="snippet">&lt;script src="<?php echo h($embedUrl); ?>" data-id="<?php echo h($selectedBotId); ?>"&gt;&lt;/script&gt;</code>
    </section>
  <?php endif; ?>
</main>

<?php if ($selectedBotId && !empty($selectedBot)): ?>
<script src="<?php echo h($embedUrl); ?>" data-id="<?php echo h($selectedBotId); ?>"></script>
<?php endif; ?>
<script>
const themeToggle = document.getElementById("themeToggle");
function setTheme(theme) {
  const dark = theme === "dark";
  document.body.classList.toggle("dark", dark);
  if (themeToggle) {
    themeToggle.textContent = dark ? "Bright Mode" : "Dark Mode";
    themeToggle.setAttribute("aria-pressed", String(dark));
  }
  localStorage.setItem("vani-index-theme", dark ? "dark" : "bright");
}
setTheme(localStorage.getItem("vani-index-theme") || "bright");
themeToggle?.addEventListener("click", () => setTheme(document.body.classList.contains("dark") ? "bright" : "dark"));
</script>
</body>
</html>
