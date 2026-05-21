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
body{background:linear-gradient(135deg,#f8fafc,#eef2ff,#fdf2f8);color:#0f172a;min-height:100vh}
.container{width:100%;max-width:1180px;margin:auto;padding:0 20px}
nav{padding:16px 0}
.nav-inner{display:flex;align-items:center;justify-content:space-between;gap:16px}
.logo img{height:76px;width:auto}
.nav-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.nav-link,.nav-btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border-radius:12px;height:42px;padding:0 16px;font-weight:700}
.nav-link{color:#334155;background:#fff;border:1px solid rgba(99,102,241,.14)}
.nav-btn{color:#fff;background:linear-gradient(135deg,#6366f1,#8b5cf6);box-shadow:0 10px 24px rgba(99,102,241,.24)}
.hero{padding:34px 0 20px;text-align:center}
.eyebrow{display:inline-flex;color:#4f46e5;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px}
h1{font-size:48px;line-height:1.12;max-width:840px;margin:0 auto}
.hero p{max-width:780px;margin:18px auto 0;color:#475569;font-size:18px;line-height:1.7}
.pricing-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:16px;margin:34px 0 42px;align-items:stretch}
.card{grid-column:span 2;background:rgba(255,255,255,.86);border:1px solid rgba(99,102,241,.16);border-radius:18px;padding:18px;display:grid;gap:14px;box-shadow:0 18px 45px rgba(15,23,42,.08)}
.card.featured{grid-column:span 3;padding:24px;border-color:rgba(34,197,94,.5);box-shadow:0 24px 55px rgba(34,197,94,.16);transform:scale(1.02)}
.head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.tag{font-size:12px;font-weight:800;border-radius:999px;padding:6px 9px;background:#eef2ff;color:#4f46e5}
.tag.good{background:#dcfce7;color:#15803d}
h2{font-size:20px}
.price{font-size:34px;font-weight:800}
.card.featured .price{font-size:40px}
.price small{font-size:14px;color:#64748b;font-weight:700}
.features{display:grid;gap:9px;color:#1f2937;font-size:14px;line-height:1.45}
.features span:before{content:"";display:inline-block;width:7px;height:7px;border-radius:50%;background:#22c55e;margin-right:8px}
table{width:100%;border-collapse:collapse;font-size:13px}
td,th{padding:8px 0;border-bottom:1px solid #e5e7eb;text-align:left}
td:last-child,th:last-child{text-align:right;font-weight:800}
.note{padding:12px 14px;border-radius:12px;background:#f8fafc;border:1px solid #e5e7eb;color:#475569;line-height:1.55;font-size:14px}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:auto}
@media(max-width:900px){.pricing-grid{grid-template-columns:1fr}.card,.card.featured{grid-column:auto;transform:none}h1{font-size:36px}.nav-inner{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<nav>
  <div class="container nav-inner">
    <a class="logo" href="index.php"><img src="images/logo.png" alt="Vani AI Logo"></a>
    <div class="nav-actions">
      <a class="nav-link" href="index.php">Home</a>
      <a class="nav-link" href="login.php">Login</a>
      <a class="nav-btn" href="signup.php">Get Started</a>
    </div>
  </div>
</nav>

<main class="container">
  <section class="hero">
    <span class="eyebrow">Subscription Plans</span>
    <h1>Start small, verify real leads, and scale your chatbot as demand grows.</h1>
    <p>Each monthly plan includes dashboard access and FAQ capacity. Wallet charges apply for OTP verification, paid lead usage, and WhatsApp redirection. WhatsApp redirection is ₹99 per 30 days on every plan.</p>
  </section>

  <section class="pricing-grid">
    <article class="card">
      <div class="head"><div><span class="eyebrow">Starter</span><h2>Starter Plan</h2></div><span class="tag">Small</span></div>
      <div class="price">₹199<small>/month</small></div>
      <div class="features"><span>100 FAQ answers</span><span>Email OTP verification</span><span>Mobile OTP service</span><span>Wallet recharge for paid usage</span><span>WhatsApp add-on at ₹99 / 30 days</span></div>
      <table><tr><th>Wallet action</th><th>Charge</th></tr><tr><td>Fresh Email Lead</td><td>₹5</td></tr><tr><td>Repeat Email Lead</td><td>₹1</td></tr><tr><td>Fresh Mobile OTP Lead</td><td>₹10</td></tr><tr><td>Repeat Mobile OTP</td><td>₹2</td></tr></table>
      <div class="actions"><a class="nav-btn" href="signup.php">Choose Starter</a></div>
    </article>

    <article class="card featured">
      <div class="head"><div><span class="eyebrow">Growth</span><h2>Growth Plan</h2></div><span class="tag good">Popular</span></div>
      <div class="price">₹499<small>/month</small></div>
      <div class="features"><span>300 FAQ answers</span><span>Email and Mobile OTP verification</span><span>Lead dashboard for captured contacts</span><span>Better wallet rates for regular lead flow</span><span>WhatsApp add-on at ₹99 / 30 days</span></div>
      <table><tr><th>Wallet action</th><th>Charge</th></tr><tr><td>Fresh Email Lead</td><td>₹4</td></tr><tr><td>Repeat Email Lead</td><td>₹1</td></tr><tr><td>Fresh Mobile Lead</td><td>₹8</td></tr><tr><td>Repeat Mobile Lead</td><td>₹2</td></tr></table>
      <div class="actions"><a class="nav-btn" href="signup.php">Choose Growth</a></div>
    </article>

    <article class="card">
      <div class="head"><div><span class="eyebrow">Business</span><h2>Business Plan</h2></div><span class="tag">Scale</span></div>
      <div class="price">₹999<small>/month</small></div>
      <div class="features"><span>1000 FAQ answers</span><span>Email + Mobile combined verification</span><span>Advanced analytics</span><span>CSV export</span><span>WhatsApp add-on at ₹99 / 30 days</span></div>
      <table><tr><th>Wallet action</th><th>Charge</th></tr><tr><td>Fresh Combined Lead</td><td>₹10</td></tr><tr><td>Repeat Combined Lead</td><td>₹3</td></tr><tr><td>Re-activated after 30 days</td><td>₹10</td></tr></table>
      <div class="actions"><a class="nav-btn" href="signup.php">Choose Business</a></div>
    </article>

    <article class="card">
      <div class="head"><div><span class="eyebrow">Automation</span><h2>Pro Automation</h2></div><span class="tag">Advanced</span></div>
      <div class="price">₹2499<small>/month</small></div>
      <div class="features"><span>Unlimited FAQ capacity</span><span>AI chatbot with API access</span><span>Automation workflows</span><span>Webhook support</span><span>WhatsApp add-on at ₹99 / 30 days</span></div>
      <table><tr><th>Wallet action</th><th>Charge</th></tr><tr><td>Fresh Email Lead</td><td>₹3</td></tr><tr><td>Fresh Mobile Lead</td><td>₹6</td></tr><tr><td>Fresh Combined Lead</td><td>₹8</td></tr><tr><td>Repeat Leads</td><td>₹1-₹2</td></tr></table>
      <div class="actions"><a class="nav-btn" href="signup.php">Choose Automation</a></div>
    </article>

    <article class="card">
      <div class="head"><div><span class="eyebrow">Enterprise</span><h2>Enterprise Plan</h2></div><span class="tag">Custom</span></div>
      <div class="price">₹4999+<small>/month</small></div>
      <div class="features"><span>White-label platform</span><span>Custom branding</span><span>Dedicated support</span><span>CRM integration</span><span>WhatsApp add-on at ₹99 / 30 days</span></div>
      <div class="note">Wallet and OTP rates can be negotiated for large lead volume. WhatsApp redirection remains billed monthly at ₹99 per 30 days.</div>
      <div class="actions"><a class="nav-btn" href="signup.php">Talk to Sales</a></div>
    </article>
  </section>
</main>
</body>
</html>
