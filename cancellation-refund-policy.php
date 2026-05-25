<?php
$updatedAt = 'May 23, 2026';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/logo_img.png">
<title>Cancellation & Refund Policy - Vani AI</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/public-theme.css">
<script defer src="js/public-theme.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif}
body{
  min-height:100vh;
  background:
    radial-gradient(circle at top left,rgba(99,102,241,.34),transparent 34%),
    radial-gradient(circle at 85% 0,rgba(236,72,153,.22),transparent 28%),
    linear-gradient(135deg,#020617 0%,#08111f 46%,#111827 100%);
  color:#e5e7eb;
  transition:.25s ease;
}
body:not(.dark){
  background:
    radial-gradient(circle at top left,rgba(99,102,241,.16),transparent 32%),
    radial-gradient(circle at 85% 0,rgba(236,72,153,.12),transparent 26%),
    linear-gradient(135deg,#f8fafc 0%,#eef2ff 48%,#fdf2f8 100%);
  color:#334155;
}
.container{width:100%;max-width:1120px;margin:0 auto;padding:0 20px}
nav{padding:18px 0}
.nav-inner{display:flex;align-items:center;justify-content:space-between;gap:18px}
.brand{display:inline-flex;align-items:center;gap:12px;position:relative;text-decoration:none;color:#f8fafc;font-weight:800;font-size:23px;padding:7px 10px 9px 6px;border-radius:16px;background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(236,72,153,.1));border:1px solid rgba(129,140,248,.18)}
.brand img{width:54px;height:54px;object-fit:contain;filter:drop-shadow(0 0 18px rgba(99,102,241,.7)) drop-shadow(0 0 24px rgba(236,72,153,.28))}
.brand span{background:linear-gradient(90deg,#ffffff,#c4b5fd 48%,#f9a8d4);-webkit-background-clip:text;-webkit-text-fill-color:transparent;filter:drop-shadow(0 0 14px rgba(129,140,248,.28))}
body:not(.dark) .brand span{background:linear-gradient(90deg,#4f46e5,#ec4899);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.nav-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:flex-end}
.theme-btn{border:1px solid rgba(129,140,248,.28);border-radius:12px;background:rgba(15,23,42,.72);color:#f8fafc;font-weight:800;padding:11px 14px;cursor:pointer}
body:not(.dark) .theme-btn{background:#fff;color:#334155;border-color:rgba(99,102,241,.16)}
.hero{padding:52px 0 28px}
.eyebrow{display:inline-flex;color:#c4b5fd;border:1px solid rgba(129,140,248,.34);background:rgba(15,23,42,.72);border-radius:999px;padding:9px 14px;font-size:14px;font-weight:700}
body:not(.dark) .eyebrow{color:#4f46e5;background:rgba(255,255,255,.78);border-color:rgba(99,102,241,.18)}
h1{margin-top:22px;font-size:clamp(36px,7vw,64px);line-height:1.08;letter-spacing:0;color:#fff}
body:not(.dark) h1{color:#0f172a}
.hero p{max-width:800px;margin-top:18px;color:#cbd5e1;font-size:18px;line-height:1.8}
body:not(.dark) .hero p{color:#475569}
.policy-shell{display:grid;grid-template-columns:280px 1fr;gap:24px;align-items:start;padding:24px 0 70px}
.toc,.content-card{background:linear-gradient(145deg,rgba(15,23,42,.88),rgba(30,41,59,.7));border:1px solid rgba(129,140,248,.24);box-shadow:0 22px 55px rgba(0,0,0,.32);border-radius:18px}
body:not(.dark) .toc,body:not(.dark) .content-card{background:rgba(255,255,255,.84);border-color:rgba(99,102,241,.16);box-shadow:0 22px 55px rgba(15,23,42,.12)}
.toc{position:sticky;top:18px;padding:20px;display:grid;gap:10px}
.toc a{color:#cbd5e1;text-decoration:none;font-weight:700;font-size:14px;padding:9px 10px;border-radius:10px}
.toc a:hover{background:rgba(129,140,248,.12);color:#fff}
body:not(.dark) .toc a{color:#475569}
body:not(.dark) .toc a:hover{background:#eef2ff;color:#4f46e5}
.content-card{padding:34px}
section+section{margin-top:30px;padding-top:28px;border-top:1px solid rgba(148,163,184,.18)}
h2{font-size:24px;color:#f8fafc;margin-bottom:12px}
body:not(.dark) h2{color:#111827}
p,li{color:#cbd5e1;line-height:1.78;font-size:15px}
body:not(.dark) p,body:not(.dark) li{color:#475569}
ul{padding-left:20px;display:grid;gap:8px}
.highlight{margin:18px 0;padding:16px;border-radius:14px;background:rgba(99,102,241,.12);border:1px solid rgba(129,140,248,.24);color:#ddd6fe}
body:not(.dark) .highlight{color:#4338ca;background:#eef2ff;border-color:#c7d2fe}
a{color:#c4b5fd}
body:not(.dark) a{color:#4f46e5}
footer{padding:24px 0 40px;color:#94a3b8;text-align:center;font-size:14px}
@media(max-width:992px){
  .policy-shell{grid-template-columns:1fr}
  .toc{position:static}
  .content-card{padding:24px}
  .brand{font-size:20px}
  .brand img{width:46px;height:46px}
}
</style>
</head>
<body>
<nav>
  <div class="container nav-inner">
    <a class="brand" href="index.php"><img src="images/logo_img.png" alt="Vani AI"><span>Vani AI</span></a>
    <div class="nav-actions">
      <button class="theme-btn" type="button" id="themeToggle">Bright Mode</button>
      <button class="site-menu-trigger" type="button" aria-label="Open menu" aria-expanded="false"><span></span><span></span><span></span></button>
    </div>
  </div>
</nav>

<?php include_once __DIR__ . '/site-menu.php'; ?>

<main class="container">
  <div class="hero">
    <span class="eyebrow">Billing Policy</span>
    <h1>Cancellation & Refund Policy</h1>
    <p>This policy explains how customers can stop Vani AI chatbot subscriptions, how wallet balance is handled, and when refunds may apply.</p>
  </div>

  <div class="policy-shell">
    <aside class="toc" aria-label="Cancellation and refund sections">
      <a href="#scope">Scope</a>
      <a href="#subscription">Subscriptions</a>
      <a href="#wallet">Wallet Balance</a>
      <a href="#autopay">Auto Payment</a>
      <a href="#whatsapp">WhatsApp Redirect</a>
      <a href="#refunds">Refunds</a>
      <a href="#failed">Failed Payments</a>
      <a href="#request">How to Request</a>
      <a href="#contact">Contact</a>
    </aside>

    <article class="content-card">
      <section id="scope">
        <h2>1. Scope</h2>
        <p>This Cancellation & Refund Policy applies to Vani AI chatbot plans, wallet recharge amounts, lead verification charges, WhatsApp Redirect add-ons, auto-payment mandates, API access, dashboard access, and related paid services provided by Codrant.</p>
      </section>

      <section id="subscription">
        <h2>2. Subscription Cancellation</h2>
        <p>Customers may unsubscribe from the chatbot service from the Subscription tab in the dashboard, where available, or by contacting support. Cancellation stops future automatic payment attempts for that chatbot customer ID.</p>
        <div class="highlight">Plans and wallet balances are attached to the selected chatbot customer ID. If one email owns multiple chatbots, cancelling one chatbot does not automatically cancel the others.</div>
        <ul>
          <li>After cancellation, the chatbot may continue to use the remaining wallet balance for the plan it was associated with.</li>
          <li>When wallet balance becomes zero, the chatbot moves to Free service.</li>
          <li>Paid toggles and paid services may be turned off automatically when the chatbot moves to Free service.</li>
          <li>If the plan validity expires and monthly subscription has stopped, Free plan limits apply.</li>
        </ul>
      </section>

      <section id="wallet">
        <h2>3. Wallet Balance and Usage Charges</h2>
        <p>Wallet balance is used for eligible paid actions such as OTP lead verification, WhatsApp Redirect add-on charges, and other plan-based usage. Wallet deductions already consumed for successful service usage are normally non-refundable.</p>
        <ul>
          <li>Fresh and repeat OTP verification lead charges are billed according to the active plan pricing.</li>
          <li>Wallet transaction history is shown in the Billing tab.</li>
          <li>If a customer unsubscribes, remaining wallet balance can still be used until it reaches zero, subject to the product rules then in effect.</li>
        </ul>
      </section>

      <section id="autopay">
        <h2>4. Mandatory Auto Payment / Recurring Mandate</h2>
        <p>Paid plans require automatic payment authorisation through the supported payment gateway. If the saved card, debit card, bank mandate, or payment token expires, is revoked, or is stopped by the customer or bank, Vani AI may keep the chatbot active only until the wallet balance reaches zero.</p>
        <p>Once the wallet balance reaches zero after auto-payment failure or cancellation, the chatbot is moved to Free service and paid service toggles may be disabled.</p>
      </section>

      <section id="whatsapp">
        <h2>5. WhatsApp Redirect Add-on</h2>
        <p>WhatsApp Redirect is billed at ₹99 for 30 days from the day the service is turned ON, where the feature is available for the active plan.</p>
        <ul>
          <li>If the customer turns OFF WhatsApp Redirect before the next 30-day renewal, the next ₹99 renewal deduction will not happen.</li>
          <li>If wallet balance is less than ₹99 at renewal time, the renewal charge will be ₹0, the WhatsApp Redirect service may be turned OFF automatically, and the customer may be notified by email.</li>
          <li>The customer cannot turn ON WhatsApp Redirect if wallet balance is less than ₹99.</li>
          <li>WhatsApp Redirect add-on is refundable if cancelled within 1 hour, subject to successful refund eligibility checks and payment/wallet status.</li>
        </ul>
      </section>

      <section id="refunds">
        <h2>6. Refund Policy</h2>
        <p>Refunds are reviewed based on the type of charge, usage, technical status, and applicable law. Except where required by law or expressly stated in this policy, paid charges are not automatically refundable.</p>
        <ul>
          <li>Consumed wallet charges for OTP leads, verified leads, chatbot usage, API usage, or completed service delivery are generally non-refundable.</li>
          <li>Duplicate charges caused by a verified payment gateway error may be refunded after verification.</li>
          <li>WhatsApp Redirect add-on may be refunded if cancelled within 1 hour of activation or renewal.</li>
          <li>Refunds, when approved, may be returned to the original payment method or credited back to wallet, depending on the charge type and gateway rules.</li>
          <li>Gateway fees, taxes, third-party charges, or bank charges may be non-refundable where applicable.</li>
        </ul>
      </section>

      <section id="failed">
        <h2>7. Failed, Pending, or Disputed Payments</h2>
        <p>If a payment is failed, pending, reversed, disputed, charged back, or flagged by the payment gateway, we may delay activation, suspend paid access, disable auto recharge, or move the chatbot to Free service when wallet balance is exhausted.</p>
        <p>Customers should not dispute valid payments without first contacting support, as chargebacks may result in account review or temporary suspension.</p>
      </section>

      <section id="request">
        <h2>8. How to Request Cancellation or Refund</h2>
        <p>To request help, email <a href="mailto:info@codrant.com">info@codrant.com</a> with your registered email address, chatbot customer ID, website domain, payment reference if available, and a short explanation of the issue.</p>
        <p>Refund review timelines may depend on payment gateway records, wallet transaction verification, bank timelines, and support volume.</p>
      </section>

      <section id="changes">
        <h2>9. Policy Updates</h2>
        <p>Codrant may update this policy when pricing, plan rules, payment gateway requirements, legal requirements, or product functionality changes. The latest version posted on this page will apply from the updated date unless otherwise required by law.</p>
      </section>

      <section id="contact">
        <h2>10. Contact</h2>
        <p>For cancellation, refund, or billing questions, contact Codrant at <a href="mailto:info@codrant.com">info@codrant.com</a>.</p>
        <p class="highlight">Last updated: <?php echo htmlspecialchars($updatedAt); ?>. Please have qualified legal counsel review this policy before public launch.</p>
      </section>
    </article>
  </div>
</main>

<footer>© <?php echo date("Y"); ?> Vani AI by Codrant</footer>
</body>
</html>
