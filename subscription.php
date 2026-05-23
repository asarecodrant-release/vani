<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/billing.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vani Subscription Plans</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:Inter,sans-serif}
body{
  background:
    radial-gradient(circle at top left,rgba(99,102,241,.34),transparent 34%),
    radial-gradient(circle at 85% 0,rgba(236,72,153,.22),transparent 28%),
    linear-gradient(135deg,#020617 0%,#08111f 46%,#111827 100%);
  color:#e5e7eb;
  min-height:100vh;
}
.container{width:100%;max-width:1180px;margin:auto;padding:0 20px}
nav{padding:16px 0}
.nav-inner{display:flex;align-items:center;justify-content:space-between;gap:16px}
.logo{display:inline-flex;align-items:center;gap:12px;position:relative;text-decoration:none;color:#f8fafc;font-size:23px;font-weight:800;white-space:nowrap;padding:7px 10px 9px 6px;border-radius:16px;background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(236,72,153,.1));border:1px solid rgba(129,140,248,.18)}
.logo img{width:54px;height:54px;object-fit:contain;filter:drop-shadow(0 0 18px rgba(99,102,241,.7)) drop-shadow(0 0 24px rgba(236,72,153,.28))}
.logo span{background:linear-gradient(90deg,#ffffff,#c4b5fd 48%,#f9a8d4);-webkit-background-clip:text;-webkit-text-fill-color:transparent;filter:drop-shadow(0 0 14px rgba(129,140,248,.28))}
.nav-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.nav-link,.nav-btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border-radius:12px;height:42px;padding:0 16px;font-weight:700}
.nav-link{color:#e5e7eb;background:rgba(15,23,42,.72);border:1px solid rgba(129,140,248,.24)}
.nav-btn{color:#fff;background:linear-gradient(135deg,#6366f1,#8b5cf6);box-shadow:0 10px 24px rgba(99,102,241,.24)}
.hero{padding:34px 0 20px;text-align:center}
.eyebrow{display:inline-flex;color:#c4b5fd;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px}
h1{font-size:48px;line-height:1.12;max-width:840px;margin:0 auto}
.hero p{max-width:780px;margin:18px auto 0;color:#cbd5e1;font-size:18px;line-height:1.7}
.pricing-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:16px;margin:34px 0 42px;align-items:stretch}
.card{grid-column:span 2;background:linear-gradient(145deg,rgba(15,23,42,.88),rgba(30,41,59,.7));border:1px solid rgba(129,140,248,.24);border-radius:18px;padding:18px;display:grid;gap:14px;box-shadow:0 22px 55px rgba(0,0,0,.32),inset 0 1px 0 rgba(255,255,255,.04)}
.card.featured{grid-column:span 2;padding:24px;border-color:rgba(34,197,94,.5);box-shadow:0 24px 70px rgba(34,197,94,.18);transform:scale(1.02)}
.head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.tag{font-size:12px;font-weight:800;border-radius:999px;padding:6px 9px;background:rgba(99,102,241,.16);color:#c4b5fd}
.tag.good{background:rgba(34,197,94,.16);color:#86efac}
h2{font-size:20px}
.price{font-size:34px;font-weight:800}
.card.featured .price{font-size:40px}
.price small{font-size:14px;color:#94a3b8;font-weight:700}
.features{display:grid;gap:9px;color:#cbd5e1;font-size:14px;line-height:1.45}
.features span:before{content:"";display:inline-block;width:7px;height:7px;border-radius:50%;background:#22c55e;margin-right:8px}
table{width:100%;border-collapse:collapse;font-size:13px}
td,th{padding:8px 0;border-bottom:1px solid rgba(148,163,184,.22);text-align:left;color:#cbd5e1}
td:last-child,th:last-child{text-align:right;font-weight:800}
.note{padding:12px 14px;border-radius:12px;background:rgba(15,23,42,.6);border:1px solid rgba(148,163,184,.22);color:#cbd5e1;line-height:1.55;font-size:14px}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:auto}
@media(max-width:992px){.pricing-grid{grid-template-columns:1fr}.card,.card.featured{grid-column:auto;transform:none}h1{font-size:36px}.nav-inner{align-items:center;flex-direction:row}.nav-link,.nav-btn{display:none}.logo{font-size:20px}.logo img{width:46px;height:46px}}
</style>
</head>
<body>
<nav>
  <div class="container nav-inner">
    <a class="logo" href="index.php"><img src="images/logo_img.png" alt="Vani AI Logo"><span>Vani AI</span></a>
    <div class="nav-actions">
      <a class="nav-link" href="index.php">Home</a>
      <a class="nav-link" href="login.php">Login</a>
      <button class="site-menu-trigger" type="button" aria-label="Open menu" aria-expanded="false">
        <span></span>
        <span></span>
        <span></span>
      </button>
    </div>
  </div>
</nav>

<?php include_once __DIR__ . '/site-menu.php'; ?>

<main class="container">
  <section class="hero">
    <span class="eyebrow">Subscription Plans</span>
    <h1>Start small, verify real leads, and scale your chatbot as demand grows.</h1>
    <p>Each monthly plan includes FAQ capacity and paid lead verification. Wallet charges apply for OTP verification and WhatsApp redirection. WhatsApp redirection is ₹99 per 30 days on every plan.</p>
  </section>

  <section class="pricing-grid">
    <article class="card">
      <div class="head"><div><span class="eyebrow">Starter</span><h2>Starter Plan</h2></div><span class="tag">Small</span></div>
      <div class="price">₹199<small>/month</small></div>
      <div class="features"><span>100 FAQ answers for small websites</span><span>Email and Mobile OTP verification for real leads</span><span>WhatsApp Redirect add-on billed at ₹99 / 30 days</span><span>Webhook support</span><span>Auto wallet recharge: below ₹50, recharge ₹199</span></div>
      <table><tr><th>Wallet action</th><th>Charge</th></tr><tr><td>Fresh Email OTP Lead</td><td>₹6</td></tr><tr><td>Repeat Email OTP Verification</td><td>₹2</td></tr><tr><td>Fresh Mobile OTP Lead</td><td>₹12</td></tr><tr><td>Repeat Mobile OTP Verification</td><td>₹3</td></tr><tr><td>WhatsApp Redirect</td><td>Add-on ₹99, refundable if cancelled within 1 hour</td></tr></table>
      <div class="note">Validity of Fresh Email and Mobile OTP Leads is 30 days from last user verification.</div>
      <div class="actions"><a class="nav-btn" href="login.php">Choose Starter</a></div>
    </article>

    <article class="card featured">
      <div class="head"><div><span class="eyebrow">Growth</span><h2>Growth Plan</h2></div><span class="tag good">Popular</span></div>
      <div class="price">₹499<small>/month</small></div>
      <div class="features"><span>300 FAQ answers for growing local businesses</span><span>Email and Mobile OTP verification for real leads</span><span>WhatsApp Redirect add-on billed at ₹99 / 30 days</span><span>Webhook support</span><span>Auto wallet recharge: below ₹100, recharge ₹499</span><span>Partial Analytics dashboard for tracking captured contacts</span><span>Better wallet rates than Starter on email and mobile leads</span><span>Analytics access: Overview, Conversations, FAQ Insights, Leads</span></div>
      <table><tr><th>Wallet action</th><th>Charge</th></tr><tr><td>Fresh Email OTP Lead</td><td>₹5</td></tr><tr><td>Repeat Email OTP Verification</td><td>₹1</td></tr><tr><td>Fresh Mobile OTP Lead</td><td>₹10</td></tr><tr><td>Repeat Mobile OTP Verification</td><td>₹2</td></tr><tr><td>WhatsApp Redirect</td><td>Add-on ₹99, refundable if cancelled within 1 hour</td></tr></table>
      <div class="note">Validity of Fresh Email and Mobile OTP Leads is 30 days from last user verification.</div>
      <div class="actions"><a class="nav-btn" href="login.php">Choose Growth</a></div>
    </article>

    <article class="card">
      <div class="head"><div><span class="eyebrow">Business</span><h2>Business Plan</h2></div><span class="tag">Scale</span></div>
      <div class="price">₹999<small>/month</small></div>
      <div class="features"><span>Unlimited FAQ capacity for larger businesses</span><span>Email and Mobile combined OTP verification for real leads</span><span>WhatsApp Redirect add-on billed at ₹99 / 30 days</span><span>Webhook support</span><span>Live Chat Actions for real-time website reactions</span><span>Auto wallet recharge: below ₹200, recharge ₹999</span><span>Complete Analytics dashboard for tracking captured contacts</span><span>Access for API Integration, Migrate or save data in your database via API</span><span>Advanced Analytics: Overview, Conversations, FAQ Insights, Leads, Pages, Real-Time, Reports Download</span><span>Chat can run only allowed domains</span></div>
      <table><tr><th>Wallet action</th><th>Charge</th></tr><tr><td>Fresh Email OTP Lead</td><td>₹5</td></tr><tr><td>Repeat Email OTP Verification</td><td>₹1</td></tr><tr><td>Fresh Mobile OTP Lead</td><td>₹10</td></tr><tr><td>Repeat Mobile OTP Verification</td><td>₹2</td></tr><tr><td>WhatsApp Redirect</td><td>Add-on ₹99, refundable if cancelled within 1 hour</td></tr></table>
      <div class="note">Validity of Fresh Email and Mobile OTP Leads is 30 days from last user verification.</div>
      <div class="actions"><a class="nav-btn" href="login.php">Choose Business</a></div>
    </article>
  </section>
</main>
</body>
</html>
