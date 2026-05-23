<?php
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/invoice_helpers.php';

if (!is_authenticated_user()) {
    header('Location: login.php');
    exit;
}

$email = authenticated_email();
$selectedBotId = trim((string)($_GET['bot'] ?? ''));

$bots = safe_rows(supabase(
    'GET',
    'chatbot_signups?select=customer_id,website_name,business_type,created_at&email=eq.' . urlencode($email) . '&order=created_at.desc'
));
if ($selectedBotId === '' && !empty($bots[0]['customer_id'])) {
    $selectedBotId = (string)$bots[0]['customer_id'];
}

$selectedBot = [];
foreach ($bots as $bot) {
    if ((string)($bot['customer_id'] ?? '') === $selectedBotId) {
        $selectedBot = $bot;
        break;
    }
}

if ($selectedBotId !== '' && empty($selectedBot)) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (($_GET['download'] ?? '') !== '') {
    $invoiceId = trim((string)$_GET['download']);
    $rows = safe_rows(supabase(
        'GET',
        'customer_invoices?select=*&id=eq.' . urlencode($invoiceId) . '&customer_id=eq.' . urlencode($selectedBotId) . '&limit=1'
    ));
    $invoice = $rows[0] ?? [];
    if (empty($invoice)) {
        http_response_code(404);
        echo 'Invoice not found';
        exit;
    }
    $context = invoice_customer_context($selectedBotId, (string)$invoice['email']);
    $pdf = invoice_pdf_binary($invoice, $context);
    $filename = (string)($invoice['pdf_filename'] ?? ($invoice['invoice_number'] . '.pdf'));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

$invoiceRows = $selectedBotId
    ? safe_rows(supabase(
        'GET',
        'customer_invoices?select=*&customer_id=eq.' . urlencode($selectedBotId) . '&order=created_at.desc&limit=200'
    ))
    : [];

$totalPaid = array_sum(array_map(fn($row) => (int)($row['total_paise'] ?? 0), $invoiceRows));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoices - Vani AI</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',sans-serif}
body{min-height:100vh;background:radial-gradient(circle at top left,rgba(99,102,241,.34),transparent 34%),radial-gradient(circle at 85% 0,rgba(236,72,153,.22),transparent 28%),linear-gradient(135deg,#020617 0%,#08111f 46%,#111827 100%);color:#e5e7eb;transition:.25s ease}
body:not(.dark){background:radial-gradient(circle at top left,rgba(99,102,241,.16),transparent 32%),radial-gradient(circle at 85% 0,rgba(236,72,153,.12),transparent 26%),linear-gradient(135deg,#f8fafc 0%,#eef2ff 48%,#fdf2f8 100%);color:#334155}
.container{width:100%;max-width:1120px;margin:0 auto;padding:0 20px}
nav{padding:18px 0}
.nav-inner{display:flex;align-items:center;justify-content:space-between;gap:18px}
.brand{display:inline-flex;align-items:center;gap:12px;text-decoration:none;font-weight:800;font-size:23px;padding:7px 10px 9px 6px;border-radius:16px;background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(236,72,153,.1));border:1px solid rgba(129,140,248,.18)}
.brand img{width:54px;height:54px;object-fit:contain;filter:drop-shadow(0 0 18px rgba(99,102,241,.7))}
.brand span{background:linear-gradient(90deg,#fff,#c4b5fd 48%,#f9a8d4);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
body:not(.dark) .brand span{background:linear-gradient(90deg,#4f46e5,#ec4899);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.nav-actions{display:flex;align-items:center;gap:12px}
.theme-btn,.ghost-btn{border:1px solid rgba(129,140,248,.28);border-radius:12px;background:rgba(15,23,42,.72);color:#f8fafc;font-weight:800;padding:11px 14px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
body:not(.dark) .theme-btn,body:not(.dark) .ghost-btn{background:#fff;color:#334155;border-color:rgba(99,102,241,.16)}
.hero{padding:52px 0 26px}
.eyebrow{display:inline-flex;color:#c4b5fd;border:1px solid rgba(129,140,248,.34);background:rgba(15,23,42,.72);border-radius:999px;padding:9px 14px;font-size:14px;font-weight:700}
body:not(.dark) .eyebrow{color:#4f46e5;background:rgba(255,255,255,.78);border-color:rgba(99,102,241,.18)}
h1{margin-top:22px;font-size:clamp(36px,7vw,62px);line-height:1.08;color:#fff}
body:not(.dark) h1{color:#0f172a}
.hero p{max-width:780px;margin-top:18px;color:#cbd5e1;font-size:18px;line-height:1.8}
body:not(.dark) .hero p{color:#475569}
.metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin:10px 0 24px}
.panel{background:linear-gradient(145deg,rgba(15,23,42,.88),rgba(30,41,59,.7));border:1px solid rgba(129,140,248,.24);box-shadow:0 22px 55px rgba(0,0,0,.25);border-radius:18px}
body:not(.dark) .panel{background:rgba(255,255,255,.86);border-color:rgba(99,102,241,.16);box-shadow:0 22px 55px rgba(15,23,42,.1)}
.metric{padding:20px}.metric span{display:block;color:#94a3b8;font-size:13px;font-weight:800;text-transform:uppercase}.metric strong{display:block;margin-top:8px;font-size:24px;color:#fff}body:not(.dark) .metric strong{color:#111827}.metric small{display:block;margin-top:6px;color:#94a3b8}
.toolbar{padding:20px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
select{border:1px solid rgba(148,163,184,.24);background:rgba(2,6,23,.52);color:#f8fafc;border-radius:12px;padding:12px 14px;min-width:260px}
body:not(.dark) select{background:#fff;color:#111827;border-color:#cbd5e1}
.invoice-list{display:grid;gap:14px;padding-bottom:70px}
.invoice-row{padding:18px 20px;display:grid;grid-template-columns:1.1fr .9fr .7fr auto;gap:14px;align-items:center}
.invoice-row h3{font-size:17px;color:#fff;margin-bottom:6px}body:not(.dark) .invoice-row h3{color:#111827}
.muted{color:#94a3b8;font-size:13px;line-height:1.6}
.amount{font-size:20px;font-weight:800;color:#c4b5fd}body:not(.dark) .amount{color:#4f46e5}
.tag{display:inline-flex;border-radius:999px;padding:7px 10px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#bbf7d0;font-size:12px;font-weight:800;text-transform:uppercase}
body:not(.dark) .tag{color:#166534;background:#dcfce7}
.empty{padding:34px;text-align:center}
footer{padding:24px 0 40px;color:#94a3b8;text-align:center;font-size:14px}
@media(max-width:850px){.metrics{grid-template-columns:1fr}.invoice-row{grid-template-columns:1fr}.toolbar{align-items:stretch}.ghost-btn,select{width:100%}}
</style>
</head>
<body class="dark">
<nav>
  <div class="container nav-inner">
    <a class="brand" href="index.php"><img src="images/logo_img.png" alt="Vani AI"><span>VANI AI</span></a>
    <div class="nav-actions">
      <button class="theme-btn" type="button" id="themeToggle">Bright Mode</button>
      <button class="site-menu-trigger" type="button" aria-label="Open menu" aria-expanded="false"><span></span><span></span><span></span></button>
    </div>
  </div>
</nav>
<?php include_once __DIR__ . '/site-menu.php'; ?>

<main class="container">
  <div class="hero">
    <span class="eyebrow">Billing</span>
    <h1>Invoices</h1>
    <p>Download official Codrant invoices for VANI AI subscription purchases and automatic wallet recharge renewals. Each invoice belongs only to the selected chatbot customer ID.</p>
  </div>

  <div class="metrics">
    <div class="panel metric"><span>Invoices</span><strong><?php echo h(count($invoiceRows)); ?></strong><small>Total invoices for this chatbot.</small></div>
    <div class="panel metric"><span>Total paid</span><strong><?php echo h(invoice_money($totalPaid)); ?></strong><small>Paid invoice value.</small></div>
    <div class="panel metric"><span>Selected bot</span><strong><?php echo h($selectedBot['website_name'] ?? 'No bot'); ?></strong><small><?php echo h($selectedBotId ?: 'Create a chatbot first'); ?></small></div>
  </div>

  <div class="panel toolbar">
    <form method="GET" action="invoices.php">
      <select name="bot" onchange="this.form.submit()">
        <?php foreach ($bots as $bot): $cid = (string)($bot['customer_id'] ?? ''); ?>
          <option value="<?php echo h($cid); ?>" <?php echo $cid === $selectedBotId ? 'selected' : ''; ?>><?php echo h(($bot['website_name'] ?? 'Chatbot') . ' - ' . substr($cid, 0, 8)); ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <a class="ghost-btn" href="dashboard.php?bot=<?php echo urlencode($selectedBotId); ?>#billing">Back to Billing</a>
  </div>

  <div class="invoice-list">
    <?php if (empty($invoiceRows)): ?>
      <div class="panel empty">
        <h3>No invoices yet</h3>
        <p class="muted">Invoices will appear here automatically after subscription purchase or renewal payment.</p>
      </div>
    <?php endif; ?>
    <?php foreach ($invoiceRows as $invoice): $plan = billing_plan((string)($invoice['plan_id'] ?? 'free')); ?>
      <div class="panel invoice-row">
        <div>
          <h3><?php echo h($invoice['invoice_number'] ?? 'Invoice'); ?></h3>
          <p class="muted"><?php echo h($plan['name']); ?> Plan • <?php echo h(ucwords(str_replace('_', ' ', (string)($invoice['invoice_type'] ?? 'subscription')))); ?></p>
        </div>
        <div class="muted">
          Date: <?php echo h(substr((string)($invoice['created_at'] ?? ''), 0, 10)); ?><br>
          Payment: <?php echo h($invoice['payment_reference'] ?? '-'); ?>
        </div>
        <div>
          <div class="amount"><?php echo h(invoice_money((int)($invoice['total_paise'] ?? 0))); ?></div>
          <span class="tag"><?php echo h($invoice['status'] ?? 'paid'); ?></span>
        </div>
        <a class="ghost-btn" href="invoices.php?bot=<?php echo urlencode($selectedBotId); ?>&download=<?php echo urlencode((string)$invoice['id']); ?>">Download PDF</a>
      </div>
    <?php endforeach; ?>
  </div>
</main>

<footer>© <?php echo date('Y'); ?> Vani AI by Codrant</footer>
<script>
const themeToggle = document.getElementById("themeToggle");
function setTheme(mode) {
  const dark = mode === "dark";
  document.body.classList.toggle("dark", dark);
  if (themeToggle) {
    themeToggle.textContent = dark ? "Bright Mode" : "Dark Mode";
    themeToggle.setAttribute("aria-pressed", String(dark));
  }
  localStorage.setItem("vani-index-theme", dark ? "dark" : "bright");
}
setTheme(localStorage.getItem("vani-index-theme") || "dark");
themeToggle?.addEventListener("click", () => setTheme(document.body.classList.contains("dark") ? "bright" : "dark"));
</script>
</body>
</html>
