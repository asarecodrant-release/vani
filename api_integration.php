<?php
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/billing.php';

header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
header('Pragma: no-cache', true);

function deny_api_integration_access(): void {
    http_response_code(404);
    echo 'Not found';
    exit;
}

if (!is_authenticated_user()) {
    deny_api_integration_access();
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function api_doc_rows(array $response): array {
    $data = $response['data'] ?? null;
    return is_array($data) && array_keys($data) === range(0, count($data) - 1) ? $data : [];
}

$email = authenticated_email();
$billingRows = api_doc_rows(supabase(
    "GET",
    "billing_accounts?select=*&email=eq." . urlencode($email) . "&limit=1"
));
$billingAccount = $billingRows[0] ?? [];
$activePlanId = billing_active_plan_from_account($billingAccount);

if (!billing_feature_enabled($activePlanId, 'api_access')) {
    deny_api_integration_access();
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$apiBase = $scheme . '://' . $host . $basePath . '/api.php?action=';
$dashboardUrl = 'dashboard.php#install';

$endpoints = [
    ['action' => 'customer_api_ping', 'name' => 'API key test', 'access' => 'Key validity, customer ID, active plan'],
    ['action' => 'customer_api_leads', 'name' => 'Leads', 'access' => 'Names, email, mobile, location, source URL, OTP verification status, WhatsApp redirect status, metadata, created date'],
    ['action' => 'customer_api_conversations', 'name' => 'Conversations', 'access' => 'Questions, bot replies, matched FAQ ID, status, session/user IDs, source/referrer URLs, browser/device/location analytics, response time, created date'],
    ['action' => 'customer_api_faqs', 'name' => 'FAQs', 'access' => 'FAQ ID, question, answer, category, created date'],
    ['action' => 'customer_api_wallet', 'name' => 'Wallet data', 'access' => 'Wallet balance, current plan, subscription status, billing period, wallet transaction history'],
    ['action' => 'customer_api_profile', 'name' => 'Profile data', 'access' => 'Customer profile, bot signup details, public bot settings, integration settings'],
    ['action' => 'customer_api_analytics', 'name' => 'Analytics', 'access' => 'Summary metrics, daily counts, devices, browsers, countries, top questions, source page performance']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>API Integration Guide - Vani AI</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:Inter,sans-serif}
:root{--bg:#f8fafc;--panel:#fff;--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--brand:#4f46e5;--brand-2:#0891b2;--good:#15803d;--bad:#b91c1c}
body{background:linear-gradient(180deg,#eef2ff 0,#f8fafc 260px);color:var(--ink);min-height:100vh}
.container{width:100%;max-width:1180px;margin:0 auto;padding:24px 20px 54px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:24px}
.brand{display:flex;align-items:center;gap:12px;text-decoration:none;color:var(--ink);font-weight:800;font-size:20px}
.brand img{width:44px;height:44px;object-fit:contain}
.actions{display:flex;gap:10px;flex-wrap:wrap}
.btn,.ghost{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border-radius:12px;padding:0 14px;text-decoration:none;font-weight:800;border:1px solid transparent}
.btn{background:linear-gradient(135deg,var(--brand),var(--brand-2));color:#fff}
.ghost{background:rgba(255,255,255,.72);border-color:var(--line);color:var(--ink)}
.hero{display:grid;gap:12px;margin-bottom:22px}
.eyebrow{text-transform:uppercase;letter-spacing:.08em;font-size:12px;font-weight:800;color:var(--brand)}
h1{font-size:42px;line-height:1.08;max-width:860px}
.hero p{font-size:17px;line-height:1.7;color:var(--muted);max-width:860px}
.grid{display:grid;grid-template-columns:280px minmax(0,1fr);gap:18px;align-items:start}
.sidebar{position:sticky;top:16px;display:grid;gap:8px}
.sidebar a{padding:10px 12px;border:1px solid var(--line);background:rgba(255,255,255,.74);border-radius:12px;color:var(--ink);text-decoration:none;font-weight:700;font-size:14px}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:20px;box-shadow:0 18px 45px rgba(15,23,42,.06);margin-bottom:16px}
.panel h2{font-size:24px;margin-bottom:12px}.panel h3{font-size:18px;margin:18px 0 10px}
.panel p,.panel li{color:var(--muted);line-height:1.65;font-size:15px}
ul,ol{padding-left:20px;display:grid;gap:8px}
code{display:block;background:#111827;color:#e5e7eb;border-radius:14px;padding:14px;white-space:pre-wrap;word-break:break-word;line-height:1.55;font-size:13px}
.inline-code{display:inline;padding:2px 6px;border-radius:7px;background:#e2e8f0;color:#0f172a;font-size:.92em}
table{width:100%;border-collapse:collapse;min-width:720px}.table-wrap{overflow:auto;border-radius:14px;border:1px solid var(--line)}
th,td{text-align:left;padding:13px 14px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);background:#f8fafc}
td{font-size:14px;color:var(--ink);line-height:1.55}
.tag{display:inline-flex;border-radius:999px;background:rgba(79,70,229,.1);color:var(--brand);padding:6px 10px;font-weight:800;font-size:12px}
.callout{padding:14px 16px;border-radius:14px;background:rgba(8,145,178,.1);border:1px solid rgba(8,145,178,.22);color:var(--ink);line-height:1.6}
.two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
@media(max-width:900px){.grid,.two{grid-template-columns:1fr}.sidebar{position:static}h1{font-size:34px}.topbar{align-items:flex-start;flex-direction:column}table{min-width:640px}}
</style>
</head>
<body>
<div class="container">
  <div class="topbar">
    <a class="brand" href="dashboard.php"><img src="images/logo_img.png" alt="Vani AI"><span>Vani AI API</span></a>
    <div class="actions">
      <a class="ghost" href="<?php echo h($dashboardUrl); ?>">Back to Integration</a>
      <a class="btn" href="dashboard.php#subscription">Business Plan Active</a>
    </div>
  </div>

  <section class="hero">
    <span class="eyebrow">Business API Integration</span>
    <h1>Connect Vani data securely with your customer’s own systems.</h1>
    <p>This guide explains what data is available, how to create and protect API keys, how to call each endpoint, and how to handle pagination, filters, errors, rate limits, and webhooks.</p>
  </section>

  <div class="grid">
    <nav class="sidebar">
      <a href="#access">What You Can Access</a>
      <a href="#setup">Setup Steps</a>
      <a href="#auth">Authentication</a>
      <a href="#endpoints">Endpoints</a>
      <a href="#filters">Filters</a>
      <a href="#examples">Examples</a>
      <a href="#webhooks">Webhooks</a>
      <a href="#errors">Errors</a>
      <a href="#security">Security Checklist</a>
    </nav>

    <main>
      <section class="panel" id="access">
        <h2>1. What Customers Can Access Via API</h2>
        <p>API access is read-only and scoped to the bot/customer connected to the API key. Customers cannot access other bots, admin secrets, Supabase keys, payment gateway secrets, or raw API key hashes.</p>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Data</th><th>Endpoint</th><th>Available information</th></tr></thead>
            <tbody>
              <?php foreach ($endpoints as $endpoint): ?>
                <tr>
                  <td><strong><?php echo h($endpoint['name']); ?></strong></td>
                  <td><span class="inline-code"><?php echo h($endpoint['action']); ?></span></td>
                  <td><?php echo h($endpoint['access']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel" id="setup">
        <h2>2. Setup Steps</h2>
        <ol>
          <li>Open <strong>Dashboard → Integration</strong>.</li>
          <li>Confirm the account is on the <strong>Business Plan</strong>.</li>
          <li>In <strong>Customer API Security</strong>, create an API key with a clear label, for example <strong>Production CRM Key</strong>.</li>
          <li>Set a daily rate limit. Start conservative, then increase when usage is stable.</li>
          <li>Optionally add allowed server IPs or allowed browser origins.</li>
          <li>Copy the API key immediately. It is shown only once.</li>
          <li>Call the test endpoint first, then connect leads, conversations, FAQs, wallet, profile, or analytics endpoints as needed.</li>
          <li>Revoke old keys whenever an integration changes ownership or a key may be exposed.</li>
        </ol>
      </section>

      <section class="panel" id="auth">
        <h2>3. Authentication</h2>
        <p>Every customer API request must include the API key in the Authorization header.</p>
        <code>Authorization: Bearer CUSTOMER_API_KEY</code>
        <p>You may also use <span class="inline-code">X-API-Key</span>, but the Authorization header is preferred.</p>
        <div class="callout">API keys are stored as hashes. Vani cannot show the full key again after creation. Create a new key if the original is lost.</div>
      </section>

      <section class="panel" id="endpoints">
        <h2>4. Endpoints</h2>
        <p>Base URL:</p>
        <code><?php echo h($apiBase); ?>ENDPOINT_NAME</code>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Purpose</th><th>Full URL</th></tr></thead>
            <tbody>
              <?php foreach ($endpoints as $endpoint): ?>
                <tr>
                  <td><?php echo h($endpoint['name']); ?></td>
                  <td><span class="inline-code"><?php echo h($apiBase . $endpoint['action']); ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel" id="filters">
        <h2>5. Pagination And Date Filters</h2>
        <p>Use pagination for all list endpoints. Date filters are supported for leads, conversations, wallet transactions, and analytics.</p>
        <div class="two">
          <div>
            <h3>Query parameters</h3>
            <ul>
              <li><span class="inline-code">limit</span>: number of records, default 100, maximum 500 for most endpoints.</li>
              <li><span class="inline-code">offset</span>: starting position for pagination.</li>
              <li><span class="inline-code">date_from</span>: start date in YYYY-MM-DD format.</li>
              <li><span class="inline-code">date_to</span>: end date in YYYY-MM-DD format.</li>
            </ul>
          </div>
          <div>
            <h3>Example filtered URL</h3>
            <code><?php echo h($apiBase); ?>customer_api_leads&limit=50&offset=0&date_from=2026-05-01&date_to=2026-05-31</code>
          </div>
        </div>
      </section>

      <section class="panel" id="examples">
        <h2>6. Request Examples</h2>
        <h3>Test the API key</h3>
        <code>curl -H "Authorization: Bearer CUSTOMER_API_KEY" "<?php echo h($apiBase); ?>customer_api_ping"</code>
        <h3>Fetch leads</h3>
        <code>curl -H "Authorization: Bearer CUSTOMER_API_KEY" "<?php echo h($apiBase); ?>customer_api_leads&limit=100"</code>
        <h3>Fetch analytics</h3>
        <code>curl -H "Authorization: Bearer CUSTOMER_API_KEY" "<?php echo h($apiBase); ?>customer_api_analytics&date_from=2026-05-01&date_to=2026-05-31"</code>
        <h3>JavaScript server example</h3>
        <code>const res = await fetch("<?php echo h($apiBase); ?>customer_api_conversations&limit=50", {
  headers: { Authorization: `Bearer ${process.env.VANI_API_KEY}` }
});
const data = await res.json();</code>
        <h3>Common response shape</h3>
        <code>{
  "success": true,
  "resource": "leads",
  "customer_id": "customer-uuid",
  "active_plan": "business",
  "count": 10,
  "data": []
}</code>
      </section>

      <section class="panel" id="webhooks">
        <h2>7. Webhooks</h2>
        <p>Use webhooks when the customer wants Vani to push events to their system instead of polling the API repeatedly.</p>
        <ol>
          <li>Open <strong>Dashboard → Integration</strong>.</li>
          <li>Add a HTTPS webhook URL.</li>
          <li>Add a webhook secret for signature verification.</li>
          <li>Save and test the receiving endpoint from the customer’s backend.</li>
        </ol>
        <div class="callout">Webhook URLs must use HTTPS. Customers should verify the secret before trusting the payload.</div>
      </section>

      <section class="panel" id="errors">
        <h2>8. Error Codes</h2>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Status</th><th>Meaning</th><th>Fix</th></tr></thead>
            <tbody>
              <tr><td>401</td><td>Missing, invalid, or revoked API key.</td><td>Create a new key or check the Authorization header.</td></tr>
              <tr><td>403</td><td>Business API access required, IP not allowed, or origin not allowed.</td><td>Check plan, IP whitelist, and origin whitelist.</td></tr>
              <tr><td>429</td><td>Daily rate limit reached.</td><td>Increase the key limit or reduce polling frequency.</td></tr>
              <tr><td>500</td><td>Unexpected server or database issue.</td><td>Retry later and check dashboard/server logs.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel" id="security">
        <h2>9. Security Checklist</h2>
        <ul>
          <li>Keep API keys on the customer’s backend, not in public frontend code.</li>
          <li>Use HTTPS only.</li>
          <li>Use allowed IPs for server-to-server integrations.</li>
          <li>Use allowed origins only when browser access is unavoidable.</li>
          <li>Use the smallest practical rate limit.</li>
          <li>Rotate keys regularly and revoke old keys.</li>
          <li>Never share Supabase, Razorpay, email, or admin keys with customers.</li>
          <li>Store imported lead data according to the customer’s privacy policy and local laws.</li>
        </ul>
      </section>
    </main>
  </div>
</div>
</body>
</html>
