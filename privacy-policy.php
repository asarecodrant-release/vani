<?php
$updatedAt = 'May 23, 2026';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Privacy Policy - Vani AI</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
.hero p{max-width:780px;margin-top:18px;color:#cbd5e1;font-size:18px;line-height:1.8}
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
.note{margin-top:28px;padding:16px;border-radius:14px;background:rgba(99,102,241,.12);border:1px solid rgba(129,140,248,.24);color:#ddd6fe}
body:not(.dark) .note{color:#4338ca;background:#eef2ff;border-color:#c7d2fe}
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
    <span class="eyebrow">Privacy</span>
    <h1>Privacy Policy</h1>
    <p>This policy explains what information Vani AI by Codrant collects, why we collect it, how we use it, and the choices customers and website visitors have.</p>
  </div>

  <div class="policy-shell">
    <aside class="toc" aria-label="Privacy sections">
      <a href="#scope">Scope</a>
      <a href="#data">Information We Collect</a>
      <a href="#use">How We Use Data</a>
      <a href="#leads">Leads & Conversations</a>
      <a href="#sharing">Sharing</a>
      <a href="#security">Security</a>
      <a href="#retention">Retention</a>
      <a href="#rights">Your Rights</a>
      <a href="#contact">Contact</a>
    </aside>

    <article class="content-card">
      <section id="scope">
        <h2>1. Scope</h2>
        <p>This Privacy Policy applies to the Vani AI website, chatbot setup pages, customer dashboard, chatbot widget, subscription and wallet features, API integrations, webhook support, and related support communications operated by Codrant.</p>
      </section>

      <section id="data">
        <h2>2. Information We Collect</h2>
        <ul>
          <li>Account details such as name, email address, phone number, business type, website domain, and login details.</li>
          <li>Chatbot configuration such as FAQs, answers, bot name, theme color, avatar, website settings, allowed domains, webhook URLs, and notification preferences.</li>
          <li>Lead details captured through chatbot forms, including name, email, mobile number, OTP verification status, WhatsApp redirect status, source page, and related metadata.</li>
          <li>Conversation and analytics data such as user questions, bot replies, matched FAQ ID, page URL, device/browser data, session details, country/city where available, and timestamps.</li>
          <li>Billing information such as selected plan, wallet balance, wallet transactions, Razorpay customer reference, recurring mandate/token reference, payment status, and invoice/payment metadata.</li>
          <li>Support details that you send through contact forms, email, tickets, human handoff, or other support channels.</li>
        </ul>
      </section>

      <section id="use">
        <h2>3. How We Use Information</h2>
        <ul>
          <li>To create accounts, configure chatbots, provide the dashboard, and operate chatbot widgets on customer websites.</li>
          <li>To answer FAQs, capture leads, verify OTP leads, redirect to WhatsApp when enabled, and create support tickets when human handoff is enabled.</li>
          <li>To process subscription plans, wallet deductions, refunds where applicable, recurring mandates, invoices, and payment-related support.</li>
          <li>To provide analytics, reports, API access, webhook delivery, troubleshooting, fraud prevention, security monitoring, and service improvement.</li>
          <li>To send important service notices, setup emails, billing alerts, support replies, and policy updates.</li>
        </ul>
      </section>

      <section id="leads">
        <h2>4. Leads, Conversations, and Customer Responsibility</h2>
        <p>Customers control what data their chatbot asks from website visitors. Customers are responsible for displaying appropriate notices and obtaining any consent required on their own website or app before collecting personal information through the chatbot.</p>
        <p>Vani AI acts as a service provider for customer chatbot data. Customers should not collect sensitive personal data, payment card numbers, government IDs, medical data, or confidential information unless they have a lawful basis and written approval from Codrant.</p>
      </section>

      <section id="sharing">
        <h2>5. When We Share Information</h2>
        <p>We do not sell personal data. We may share information with service providers only as needed to run Vani AI, such as hosting, database, email delivery, OTP providers, payment gateway providers, analytics/security tools, and support systems. We may also disclose information if required by law, to enforce our terms, or to protect users, customers, Codrant, or the public.</p>
      </section>

      <section id="payments">
        <h2>6. Payments and Razorpay</h2>
        <p>Payments and recurring mandate authorisation are handled through Razorpay or another payment provider we enable. Vani AI stores payment references, statuses, customer IDs, and mandate/token references needed to manage billing. We do not store full card numbers, CVV, or complete bank card credentials on our servers.</p>
      </section>

      <section id="security">
        <h2>7. Security</h2>
        <p>We use reasonable technical and organisational safeguards to protect data, including access controls, API key hashing, session controls, database permissions, and service monitoring. No internet service can be guaranteed completely secure, so customers should protect their credentials, API keys, webhook secrets, and website integrations.</p>
      </section>

      <section id="retention">
        <h2>8. Data Retention</h2>
        <p>We keep data for as long as needed to provide the service, comply with legal and tax requirements, resolve disputes, prevent abuse, and maintain records. Customers may request deletion or export of applicable chatbot data, subject to legal, billing, security, and backup retention requirements.</p>
      </section>

      <section id="rights">
        <h2>9. Your Choices and Rights</h2>
        <p>Depending on applicable law, you may request access, correction, deletion, export, restriction, or withdrawal of consent for personal data. We may need to verify your identity and account ownership before acting on a request.</p>
      </section>

      <section id="children">
        <h2>10. Children</h2>
        <p>Vani AI is intended for business use and is not directed to children. Customers should not knowingly use the chatbot to collect personal information from children without proper legal authority and consent.</p>
      </section>

      <section id="changes">
        <h2>11. Changes to This Policy</h2>
        <p>We may update this Privacy Policy when our services, legal requirements, or business practices change. The updated date at the top of this page will reflect the latest version.</p>
      </section>

      <section id="contact">
        <h2>12. Contact</h2>
        <p>For privacy questions or requests, contact Codrant at <a href="mailto:info@codrant.com">info@codrant.com</a>.</p>
        <p class="note">Last updated: <?php echo htmlspecialchars($updatedAt); ?>. Please have qualified legal counsel review this policy before public launch.</p>
      </section>
    </article>
  </div>
</main>

<footer>© <?php echo date("Y"); ?> Vani AI by Codrant</footer>
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
setTheme(localStorage.getItem("vani-index-theme") || "bright");
themeToggle?.addEventListener("click", () => setTheme(document.body.classList.contains("dark") ? "bright" : "dark"));
</script>
</body>
</html>
