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
$selectedBotId = trim((string)($_GET['bot'] ?? ''));

if ($selectedBotId === '') {
    deny_api_integration_access();
}

$botRows = api_doc_rows(supabase(
    "GET",
    "chatbot_signups?select=customer_id,email,website_name&customer_id=eq." . urlencode($selectedBotId) . "&email=eq." . urlencode($email) . "&limit=1"
));

if (empty($botRows[0])) {
    deny_api_integration_access();
}

$billingRows = api_doc_rows(supabase(
    "GET",
    "billing_accounts?select=*&customer_id=eq." . urlencode($selectedBotId) . "&limit=1"
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
$dashboardUrl = 'dashboard.php?bot=' . urlencode($selectedBotId) . '#install';

$endpoints = [
    ['action' => 'customer_api_ping', 'name' => 'API key test', 'method' => 'GET', 'filters' => 'None', 'access' => 'Key validity, customer ID, active plan'],
    ['action' => 'customer_api_leads', 'name' => 'Leads', 'method' => 'GET', 'filters' => 'limit, offset, date_from, date_to', 'access' => 'Names, email, mobile, location, source URL, OTP verification status, WhatsApp redirect status, metadata, created date'],
    ['action' => 'customer_api_conversations', 'name' => 'Conversations', 'method' => 'GET', 'filters' => 'limit, offset, date_from, date_to', 'access' => 'Questions, bot replies, matched FAQ ID, status, session/user IDs, source/referrer URLs, browser/device/location analytics, response time, created date'],
    ['action' => 'customer_api_faqs', 'name' => 'FAQs', 'method' => 'GET', 'filters' => 'limit, offset', 'access' => 'FAQ ID, question, answer, category, created date'],
    ['action' => 'customer_api_feedback', 'name' => 'Feedback received', 'method' => 'GET', 'filters' => 'limit, offset, date_from, date_to', 'access' => 'Collected FAQ action feedback, feedback type/value, FAQ/action IDs, visitor/session IDs, source URL, created date'],
    ['action' => 'customer_api_payment_settings', 'name' => 'Payment settings', 'method' => 'GET', 'filters' => 'None', 'access' => 'Payment collection status, provider, public Razorpay key ID, business name, success message. Razorpay secret is never returned'],
    ['action' => 'customer_api_payment_actions', 'name' => 'Payment buttons', 'method' => 'GET', 'filters' => 'limit, offset', 'access' => 'Payment Button ID, label, description, method, amount, currency, UPI display fields, active status, created/updated date'],
    ['action' => 'customer_api_payment_transactions', 'name' => 'Payment transactions', 'method' => 'GET', 'filters' => 'limit, offset, date_from, date_to', 'access' => 'Payment attempts, status, method, amount, payer details, source URL, Razorpay order/payment IDs, paid date, created date'],
    ['action' => 'customer_api_wallet', 'name' => 'Wallet data', 'method' => 'GET', 'filters' => 'limit, offset, date_from, date_to', 'access' => 'Wallet balance, current plan, subscription status, billing period, wallet transaction history'],
    ['action' => 'customer_api_profile', 'name' => 'Profile data', 'method' => 'GET', 'filters' => 'None', 'access' => 'Customer profile, bot signup details, public bot settings, integration settings'],
    ['action' => 'customer_api_analytics', 'name' => 'Analytics', 'method' => 'GET', 'filters' => 'date_from, date_to', 'access' => 'Summary metrics, daily counts, feedback analysis, payment analysis, devices, browsers, countries, top questions, source page performance']
];

$apiKeyRows = api_doc_rows(supabase(
    "GET",
    "customer_api_keys?select=id,name,key_prefix,allowed_ips,allowed_origins,rate_limit_per_day,last_used_at,revoked_at,created_at&customer_id=eq." . urlencode($selectedBotId) . "&order=created_at.desc"
));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/logo_img.png">
<title>API Integration Guide - Vani AI</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:Inter,sans-serif}
:root{--bg:#f8fafd;--panel:#fff;--panel-soft:rgba(255,255,255,.86);--ink:#202124;--muted:#5f6368;--line:#dadce0;--brand:#1a73e8;--brand-2:#34a853;--good:#188038;--bad:#d93025;--hero-bg:#f8fafd;--thead:#f1f3f4;--inline-bg:#e8f0fe;--inline-ink:#1967d2;--shadow:0 1px 3px rgba(60,64,67,.15)}
body.dark{--bg:#020617;--panel:#0f172a;--panel-soft:rgba(15,23,42,.74);--ink:#e5e7eb;--muted:#94a3b8;--line:rgba(148,163,184,.24);--brand:#818cf8;--brand-2:#22d3ee;--good:#86efac;--bad:#fca5a5;--hero-bg:#111827;--thead:#111827;--inline-bg:rgba(129,140,248,.18);--inline-ink:#dbeafe;--shadow:0 18px 45px rgba(0,0,0,.28)}
body{background:linear-gradient(180deg,var(--hero-bg) 0,var(--bg) 260px);color:var(--ink);min-height:100vh}
.container{width:100%;max-width:1180px;margin:0 auto;padding:24px 20px 54px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:24px}
.brand{display:flex;align-items:center;gap:12px;text-decoration:none;color:var(--ink);font-weight:800;font-size:20px}
.brand img{width:44px;height:44px;object-fit:contain}
.actions{display:flex;gap:10px;flex-wrap:wrap}
.btn,.ghost{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border-radius:12px;padding:0 14px;text-decoration:none;font-weight:800;border:1px solid transparent}
.btn{background:linear-gradient(135deg,var(--brand),var(--brand-2));color:#fff}
.ghost{background:var(--panel-soft);border-color:var(--line);color:var(--ink)}
.hero{display:grid;gap:12px;margin-bottom:22px}
.eyebrow{text-transform:uppercase;letter-spacing:.08em;font-size:12px;font-weight:800;color:var(--brand)}
h1{font-size:42px;line-height:1.08;max-width:860px}
.hero p{font-size:17px;line-height:1.7;color:var(--muted);max-width:860px}
.grid{display:grid;grid-template-columns:280px minmax(0,1fr);gap:18px;align-items:start}
.sidebar{position:sticky;top:16px;display:grid;gap:8px}
.sidebar a{padding:10px 12px;border:1px solid var(--line);background:var(--panel-soft);border-radius:12px;color:var(--ink);text-decoration:none;font-weight:700;font-size:14px}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:20px;box-shadow:var(--shadow);margin-bottom:16px}
.panel h2{font-size:24px;margin-bottom:12px}.panel h3{font-size:18px;margin:18px 0 10px}
.panel p,.panel li{color:var(--muted);line-height:1.65;font-size:15px}
ul,ol{padding-left:20px;display:grid;gap:8px}
code{display:block;background:#111827;color:#e5e7eb;border-radius:14px;padding:14px;white-space:pre-wrap;word-break:break-word;line-height:1.55;font-size:13px}
.inline-code{display:inline;padding:2px 6px;border-radius:7px;background:var(--inline-bg);color:var(--inline-ink);font-size:.92em}
table{width:100%;border-collapse:collapse;min-width:720px}.table-wrap{overflow:auto;border-radius:14px;border:1px solid var(--line)}
th,td{text-align:left;padding:13px 14px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);background:var(--thead)}
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
      <button class="ghost" type="button" id="themeToggle">Dark Mode</button>
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
      <a href="#feedback-payments">Feedback & Payments</a>
      <a href="#webhooks">Webhooks</a>
      <a href="#live-actions">Live Chat Actions</a>
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
        <h3>Your Created API Keys</h3>
        <p>Use the full API key copied at creation time in place of <span class="inline-code">CUSTOMER_API_KEY</span>. This guide only shows safe key prefixes so customers can identify which key belongs to each integration.</p>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Name</th><th>Safe prefix</th><th>Rate limit</th><th>Restrictions</th><th>Last used</th><th>Status</th></tr></thead>
            <tbody>
              <?php if (empty($apiKeyRows)): ?>
                <tr><td colspan="6">No API keys created yet. Go back to Dashboard -> Integration and create one first.</td></tr>
              <?php endif; ?>
              <?php foreach ($apiKeyRows as $keyRow): ?>
                <?php $revoked = !empty($keyRow['revoked_at']); ?>
                <tr>
                  <td><?php echo h($keyRow['name'] ?? 'API key'); ?></td>
                  <td><span class="inline-code"><?php echo h(($keyRow['key_prefix'] ?? '') . '...'); ?></span></td>
                  <td><?php echo h($keyRow['rate_limit_per_day'] ?? ''); ?>/day</td>
                  <td>
                    <?php if (!empty($keyRow['allowed_ips'])): ?>IPs: <?php echo h($keyRow['allowed_ips']); ?><br><?php endif; ?>
                    <?php if (!empty($keyRow['allowed_origins'])): ?>Origins: <?php echo h($keyRow['allowed_origins']); ?><?php endif; ?>
                    <?php if (empty($keyRow['allowed_ips']) && empty($keyRow['allowed_origins'])): ?>No IP/origin restriction<?php endif; ?>
                  </td>
                  <td><?php echo h($keyRow['last_used_at'] ?? 'Never'); ?></td>
                  <td><span class="tag"><?php echo h($revoked ? 'Revoked' : 'Active'); ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel" id="endpoints">
        <h2>4. Read-only Data Endpoints</h2>
        <p>All customer API endpoints are read-only, scoped to the selected bot/customer ID, and require a valid Business API key. Use this section as the full endpoint reference instead of copying endpoint URLs from the dashboard.</p>
        <p>Base URL:</p>
        <code><?php echo h($apiBase); ?>ENDPOINT_NAME</code>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Purpose</th><th>Method</th><th>Full URL</th><th>Supported filters</th><th>Data returned</th></tr></thead>
            <tbody>
              <?php foreach ($endpoints as $endpoint): ?>
                <tr>
                  <td><?php echo h($endpoint['name']); ?></td>
                  <td><span class="tag"><?php echo h($endpoint['method']); ?></span></td>
                  <td><span class="inline-code"><?php echo h($apiBase . $endpoint['action']); ?></span></td>
                  <td><?php echo h($endpoint['filters']); ?></td>
                  <td><?php echo h($endpoint['access']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="callout" style="margin-top:14px">Customers should start with <span class="inline-code">customer_api_ping</span>, then connect only the endpoints their own system actually needs.</div>
      </section>

      <section class="panel" id="filters">
        <h2>5. Pagination And Date Filters</h2>
        <p>Use pagination for all list endpoints. Date filters are supported for leads, conversations, feedback, payment transactions, wallet transactions, and analytics.</p>
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
        <h3>Fetch feedback received</h3>
        <code>curl -H "Authorization: Bearer CUSTOMER_API_KEY" "<?php echo h($apiBase); ?>customer_api_feedback&limit=100&date_from=2026-05-01&date_to=2026-05-31"</code>
        <h3>Fetch payment transactions</h3>
        <code>curl -H "Authorization: Bearer CUSTOMER_API_KEY" "<?php echo h($apiBase); ?>customer_api_payment_transactions&limit=100&date_from=2026-05-01&date_to=2026-05-31"</code>
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

      <section class="panel" id="feedback-payments">
        <h2>7. Feedback And Payment Collection API</h2>
        <p>The Feedback Received tab and Payments Collection tab can be connected to the customer API. These endpoints are useful when customers want their own CRM, BI dashboard, finance tool, or support system to pull the same data shown in Vani.</p>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Dashboard area</th><th>Endpoint</th><th>Use it for</th><th>Important security note</th></tr></thead>
            <tbody>
              <tr><td>Feedback Received</td><td><span class="inline-code">customer_api_feedback</span></td><td>Export visitor feedback, FAQ/action IDs, source URL, session/user IDs, and dates.</td><td>Read-only. Requires Business API key.</td></tr>
              <tr><td>Payments Collection setup</td><td><span class="inline-code">customer_api_payment_settings</span></td><td>Check whether payment collection is enabled and read public payment configuration.</td><td>Razorpay key secret is never returned.</td></tr>
              <tr><td>Payment Buttons</td><td><span class="inline-code">customer_api_payment_actions</span></td><td>Sync Payment Button IDs, labels, methods, amount, currency, UPI display fields, and active status.</td><td>Use Payment Button ID when mapping payments to external systems.</td></tr>
              <tr><td>Payment Transactions</td><td><span class="inline-code">customer_api_payment_transactions</span></td><td>Pull payment attempts, paid/pending/failed status, payer details, source page, and gateway references.</td><td>Payments go directly to the customer gateway/UPI account; Vani does not expose gateway secret keys.</td></tr>
              <tr><td>Analytics</td><td><span class="inline-code">customer_api_analytics</span></td><td>Pull BI-ready feedback and payment summaries with daily counts, revenue, statuses, methods, and conversion.</td><td>Analytics respects the API key scope and date filters.</td></tr>
            </tbody>
          </table>
        </div>
        <h3>Payment settings response example</h3>
        <code>{
  "success": true,
  "resource": "payment_settings",
  "settings": {
    "is_enabled": true,
    "provider": "razorpay",
    "business_name": "Customer Business",
    "razorpay_key_id": "rzp_live_xxxxx",
    "razorpay_key_secret_configured": true,
    "success_message": "Payment received. Thank you."
  }
}</code>
        <h3>Payment transaction response example</h3>
        <code>{
  "success": true,
  "resource": "payment_transactions",
  "count": 1,
  "data": [{
    "payment_action_id": 42,
    "status": "paid",
    "payment_method": "razorpay",
    "amount_paise": 49900,
    "amount_rupees": 499,
    "currency": "INR",
    "payer_email": "buyer@example.com",
    "source_url": "https://customer-site.com/pricing",
    "razorpay_payment_id": "pay_xxxxx",
    "paid_at": "2026-05-23T10:30:00Z"
  }]
}</code>
        <div class="callout">For security, the customer API is designed for reading/syncing data. Creating payment buttons, changing payment settings, and gateway verification remain dashboard/widget controlled flows.</div>
      </section>

      <section class="panel" id="webhooks">
        <h2>8. Webhooks</h2>
        <p>Use webhooks when the customer wants Vani to push events to their system instead of polling the API repeatedly.</p>
        <ol>
          <li>Open <strong>Dashboard → Integration</strong>.</li>
          <li>Add a HTTPS webhook URL.</li>
          <li>Add a webhook secret for signature verification.</li>
          <li>Save and test the receiving endpoint from the customer’s backend.</li>
        </ol>
        <h3>Events sent by Vani</h3>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Event</th><th>When it is sent</th></tr></thead>
            <tbody>
              <tr><td><span class="inline-code">conversation.answered</span></td><td>A visitor question is answered by a matched FAQ.</td></tr>
              <tr><td><span class="inline-code">webhook.test</span></td><td>The customer clicks Test webhook in Dashboard -> Integration.</td></tr>
              <tr><td><span class="inline-code">conversation.unanswered</span></td><td>Vani cannot answer the visitor question.</td></tr>
              <tr><td><span class="inline-code">support_ticket.created</span></td><td>Human handoff is ON and an unanswered question creates a ticket.</td></tr>
              <tr><td><span class="inline-code">lead.created</span></td><td>A lead record is saved from email, mobile, location, or WhatsApp redirect flow.</td></tr>
              <tr><td><span class="inline-code">lead.email_otp_sent</span></td><td>An email OTP lead is created and OTP sending is attempted.</td></tr>
              <tr><td><span class="inline-code">lead.email_otp_verified</span></td><td>A visitor successfully verifies email OTP.</td></tr>
              <tr><td><span class="inline-code">lead.mobile_otp_verified</span></td><td>A visitor successfully verifies mobile OTP.</td></tr>
              <tr><td><span class="inline-code">whatsapp.redirect_clicked</span></td><td>A visitor clicks the WhatsApp redirect button.</td></tr>
              <tr><td><span class="inline-code">faq.selected</span></td><td>A visitor selects a suggested FAQ question.</td></tr>
            </tbody>
          </table>
        </div>
        <h3>Webhook request format</h3>
        <code>POST https://customer-domain.com/webhooks/vani
Content-Type: application/json
X-Vani-Event: conversation.answered
X-Vani-Timestamp: 2026-05-23T10:30:00Z
X-Vani-Signature: sha256=HMAC_SIGNATURE</code>
        <h3>Example payload</h3>
        <code>{
  "event": "lead.email_otp_verified",
  "event_id": "unique-event-id",
  "customer_id": "<?php echo h($selectedBotId); ?>",
  "created_at": "2026-05-23T10:30:00Z",
  "data": {
    "lead": {
      "id": 123,
      "email": "lead@example.com",
      "email_otp_verified": true,
      "source_url": "https://example.com/pricing"
    }
  }
}</code>
        <h3>Signature verification</h3>
        <p>If a webhook secret is set, Vani signs every payload using HMAC SHA-256. The signed string is:</p>
        <code>X-Vani-Timestamp + "." + raw_request_body</code>
        <p>Compare the expected signature with the <span class="inline-code">X-Vani-Signature</span> header before trusting the payload.</p>
        <code>const crypto = require("crypto");

const expected = "sha256=" + crypto
  .createHmac("sha256", process.env.VANI_WEBHOOK_SECRET)
  .update(req.headers["x-vani-timestamp"] + "." + rawBody)
  .digest("hex");

if (expected !== req.headers["x-vani-signature"]) {
  throw new Error("Invalid Vani webhook signature");
}</code>
        <div class="callout">Webhook URLs must use HTTPS. Vani sends the webhook after saving the event in its own database. If the customer endpoint is down, chatbot functionality continues normally.</div>
      </section>

      <section class="panel" id="live-actions">
        <h2>9. Live Chat Actions</h2>
        <p>Use Live Chat Actions when the customer's website should react immediately while a visitor is chatting. This is a Business Plan feature and works only when the switch is ON in <strong>Dashboard → Integration</strong>.</p>
        <ol>
          <li>Open <strong>Dashboard → Integration</strong>.</li>
          <li>Turn ON <strong>Live Chat Actions</strong>.</li>
          <li>Save the setting.</li>
          <li>Add JavaScript event listeners on the customer's website after the Vani widget script.</li>
          <li>Use the event payload to show forms, highlight sections, trigger CRM logic, track conversions, or open custom UI.</li>
        </ol>
        <h3>Available browser events</h3>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Event</th><th>When it fires</th><th>Common use</th></tr></thead>
            <tbody>
              <tr><td><span class="inline-code">vani:chatOpened</span></td><td>Visitor opens the chat widget.</td><td>Start engagement tracking or show page help.</td></tr>
              <tr><td><span class="inline-code">vani:messageSent</span></td><td>Visitor sends a message or selects a suggested FAQ.</td><td>React to keywords, show relevant page sections.</td></tr>
              <tr><td><span class="inline-code">vani:faqAnswered</span></td><td>Vani answers using a matched FAQ.</td><td>Track solved questions or move the visitor deeper into the funnel.</td></tr>
              <tr><td><span class="inline-code">vani:unknownQuestion</span></td><td>Vani cannot find an answer.</td><td>Open support form, ticket form, or human handoff prompt.</td></tr>
              <tr><td><span class="inline-code">vani:leadCaptured</span></td><td>Email, mobile, location, OTP, or WhatsApp lead data is saved.</td><td>Trigger conversion tracking or update the page state.</td></tr>
              <tr><td><span class="inline-code">vani:whatsappClicked</span></td><td>Visitor clicks the WhatsApp redirect button.</td><td>Track WhatsApp intent or show a fallback message.</td></tr>
            </tbody>
          </table>
        </div>
        <h3>Copy-paste example</h3>
        <code>window.addEventListener("vani:unknownQuestion", function(event) {
  console.log("Unanswered question:", event.detail.message);
  document.querySelector("#support-form")?.classList.add("show");
});

window.addEventListener("vani:leadCaptured", function(event) {
  console.log("Lead captured:", event.detail.lead);
});

window.addEventListener("vani:liveAction", function(event) {
  console.log("Any Vani live event:", event.detail.event, event.detail);
});</code>
        <h3>Payload fields</h3>
        <ul>
          <li><span class="inline-code">customer_id</span>, <span class="inline-code">user_id</span>, <span class="inline-code">session_id</span>, <span class="inline-code">source_url</span>, and <span class="inline-code">timestamp</span> are included on every event.</li>
          <li>Message events include <span class="inline-code">message</span>, <span class="inline-code">reply</span>, <span class="inline-code">answered</span>, and <span class="inline-code">matched_faq_id</span> where available.</li>
          <li>Lead events include safe lead fields such as email, phone number, OTP verification flags, source URL, and created date.</li>
        </ul>
        <div class="callout">These are browser events. They are best for front-end website behavior. For secure backend automation, use webhooks or server-side API calls.</div>
      </section>

      <section class="panel" id="errors">
        <h2>10. Error Codes</h2>
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
        <h2>11. Security Checklist</h2>
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
