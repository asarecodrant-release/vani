<?php
$updatedAt = 'May 26, 2026';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/logo_img.png">
<title>Terms & Conditions - Vani AI</title>
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
}
.container{width:100%;max-width:1120px;margin:0 auto;padding:0 20px}
nav{padding:18px 0}
.nav-inner{display:flex;align-items:center;justify-content:space-between;gap:18px}
.brand{display:inline-flex;align-items:center;gap:12px;position:relative;text-decoration:none;color:#f8fafc;font-weight:800;font-size:23px;padding:7px 10px 9px 6px;border-radius:16px;background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(236,72,153,.1));border:1px solid rgba(129,140,248,.18)}
.brand img{width:54px;height:54px;object-fit:contain;filter:drop-shadow(0 0 18px rgba(99,102,241,.7)) drop-shadow(0 0 24px rgba(236,72,153,.28))}
.brand span{background:linear-gradient(90deg,#ffffff,#c4b5fd 48%,#f9a8d4);-webkit-background-clip:text;-webkit-text-fill-color:transparent;filter:drop-shadow(0 0 14px rgba(129,140,248,.28))}
.nav-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:flex-end}
.nav-link{color:#cbd5e1;text-decoration:none;font-weight:700;border:1px solid rgba(129,140,248,.24);padding:11px 15px;border-radius:12px;background:rgba(15,23,42,.72)}
.hero{padding:52px 0 28px}
.eyebrow{display:inline-flex;color:#c4b5fd;border:1px solid rgba(129,140,248,.34);background:rgba(15,23,42,.72);border-radius:999px;padding:9px 14px;font-size:14px;font-weight:700}
h1{margin-top:22px;font-size:clamp(36px,7vw,64px);line-height:1.08;letter-spacing:0;color:#fff}
.hero p{max-width:780px;margin-top:18px;color:#cbd5e1;font-size:18px;line-height:1.8}
.terms-shell{display:grid;grid-template-columns:280px 1fr;gap:24px;align-items:start;padding:24px 0 70px}
.toc,.content-card{background:linear-gradient(145deg,rgba(15,23,42,.88),rgba(30,41,59,.7));border:1px solid rgba(129,140,248,.24);box-shadow:0 22px 55px rgba(0,0,0,.32);border-radius:18px}
.toc{position:sticky;top:18px;padding:20px;display:grid;gap:10px}
.toc a{color:#cbd5e1;text-decoration:none;font-weight:700;font-size:14px;padding:9px 10px;border-radius:10px}
.toc a:hover{background:rgba(129,140,248,.12);color:#fff}
.content-card{padding:34px}
section+section{margin-top:30px;padding-top:28px;border-top:1px solid rgba(148,163,184,.18)}
h2{font-size:24px;color:#f8fafc;margin-bottom:12px}
p,li{color:#cbd5e1;line-height:1.78;font-size:15px}
ul{padding-left:20px;display:grid;gap:8px}
.note{margin-top:28px;padding:16px;border-radius:14px;background:rgba(99,102,241,.12);border:1px solid rgba(129,140,248,.24);color:#ddd6fe}
footer{padding:24px 0 40px;color:#94a3b8;text-align:center;font-size:14px}
@media(max-width:992px){
  .terms-shell{grid-template-columns:1fr}
  .toc{position:static}
  .content-card{padding:24px}
  .brand{font-size:20px}
  .brand img{width:46px;height:46px}
  .nav-link{display:none}
}
</style>
</head>
<body>
<nav>
  <div class="container nav-inner">
    <a class="brand" href="index.php"><img src="images/logo_img.png" alt="Vani AI"><span>Vani AI</span></a>
    <div class="nav-actions">
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
  <div class="hero">
    <span class="eyebrow">Legal</span>
    <h1>Terms & Conditions</h1>
    <p>These terms explain how customers may use Vani AI chatbot services, including the free FAQ chatbot, website chatbot widgets, dashboard tools, and future AI-powered chatbot features provided by Codrant.</p>
  </div>

  <div class="terms-shell">
    <aside class="toc" aria-label="Terms sections">
      <a href="#acceptance">Acceptance</a>
      <a href="#services">Services</a>
      <a href="#accounts">Accounts</a>
      <a href="#customer-data">Customer Data</a>
      <a href="#feedback-payments">Feedback & Payments</a>
      <a href="#acceptable-use">Acceptable Use</a>
      <a href="#fees">Fees</a>
      <a href="#ip">Intellectual Property</a>
      <a href="#ai">AI Outputs</a>
      <a href="#liability">Liability</a>
      <a href="#law">Governing Law</a>
    </aside>

    <article class="content-card">
      <section id="acceptance">
        <h2>1. Acceptance of Terms</h2>
        <p>By accessing this website, creating an account, downloading a chatbot package, or using any Vani AI service, you agree to these Terms & Conditions. If you are using the service for a company, you confirm that you are authorised to accept these terms on its behalf.</p>
      </section>

      <section id="services">
        <h2>2. Services We Provide</h2>
        <p>Vani AI provides chatbot tools for business websites. Services may include FAQ-based chatbot setup, chatbot widgets, customer dashboards, subscription plans, onboarding support, and AI-powered chatbot features when released.</p>
        <ul>
          <li>The free FAQ chatbot is provided as a lightweight website support tool.</li>
          <li>AI-powered features may generate probabilistic responses and should be reviewed for business-critical use.</li>
          <li>The website integration is provided through a small Vani AI loader snippet that creates an iframe-based chatbot from Codrant's hosted systems. You are responsible for adding the snippet only on websites where you have authority and for configuring any required Content Security Policy, website-builder, tag-manager, or platform permissions needed to allow the script and iframe to load.</li>
          <li>We may improve, update, suspend, or discontinue features when required for security, performance, or business reasons.</li>
        </ul>
        <p>For dashboard handling, setup workflow, feature usage, and operating guidance, see the <a href="Customer_Manual.php">Customer Manual</a>.</p>
      </section>

      <section id="accounts">
        <h2>3. Accounts and Security</h2>
        <p>You are responsible for keeping your login credentials confidential and for all activity under your account. You must provide accurate information, maintain a secure website integration, and notify us promptly if you suspect unauthorised use.</p>
      </section>

      <section id="customer-data">
        <h2>4. Customer Data and Website Content</h2>
        <p>You retain ownership of the FAQs, website details, branding, prompts, documents, and other content you provide. You grant Codrant a limited permission to use that data only to configure, operate, support, secure, and improve the Vani AI services.</p>
        <p>You are responsible for ensuring that your customer data, FAQs, and chatbot usage comply with applicable privacy, consumer protection, intellectual property, and sector-specific laws.</p>
      </section>

      <section id="feedback-payments">
        <h2>5. Feedback and Customer Payment Collection</h2>
        <p>If you enable chatbot feedback collection, you are responsible for deciding which feedback types to collect, when to ask visitors for feedback, and whether your own website privacy notice or consent flow must be updated. You must not use feedback tools to collect sensitive personal data unless you have a lawful basis and appropriate visitor notice.</p>
        <p>If you enable payment collection, visitor payments are intended to go directly to your own Razorpay account, UPI ID, or other enabled payment provider. You are responsible for your payment button labels, prices, descriptions, taxes, invoices to your buyers, refunds, chargebacks, customer support, delivery of goods or services, and compliance with payment provider rules and applicable law.</p>
        <p>Codrant provides the chatbot payment workflow, dashboard records, and analytics tools. Codrant is not the merchant of record for payments collected by you from your website visitors unless a separate written agreement says otherwise. You must keep your payment credentials accurate and secure, and you must not use payment collection for unlawful, misleading, prohibited, or high-risk transactions.</p>
      </section>

      <section id="acceptable-use">
        <h2>6. Acceptable Use</h2>
        <ul>
          <li>Do not use the service for unlawful, misleading, abusive, harmful, or discriminatory content.</li>
          <li>Do not attempt to reverse engineer, overload, scan, disrupt, or bypass security controls.</li>
          <li>Do not upload malware, secrets, payment card data, government IDs, or sensitive personal data unless we have expressly agreed in writing.</li>
          <li>Do not use chatbot payment buttons to sell restricted products or services, misrepresent prices, collect payments without authority, or bypass payment gateway or UPI rules.</li>
          <li>Do not represent chatbot outputs as professional legal, medical, financial, or emergency advice.</li>
        </ul>
      </section>

      <section id="fees">
        <h2>7. Fees, Plans, and Cancellation</h2>
        <p>Paid plans, if purchased, are billed according to the selected subscription or written order. Taxes, gateway charges, and third-party service charges may apply. Unless a written plan states otherwise, fees already paid are non-refundable except where required by applicable law or where Codrant approves a refund at its discretion.</p>
        <p>Fees paid by you to Codrant for Vani AI plans, wallet balance, or platform usage are separate from payments your own website visitors make to you through customer payment collection features. Visitor payment disputes, refunds, product delivery, and buyer support are your responsibility.</p>
      </section>

      <section id="ip">
        <h2>8. Intellectual Property</h2>
        <p>Vani AI software, code, workflows, visual designs, platform content, documentation, and trademarks are owned by Codrant or its licensors. You may use the service only for your internal business website support needs and may not copy, resell, sublicense, or create a competing service from it.</p>
      </section>

      <section id="ai">
        <h2>9. AI Responses and Accuracy</h2>
        <p>AI-generated responses may be incomplete, inaccurate, or unsuitable for a specific situation. You are responsible for configuring FAQs, reviewing chatbot behaviour, and deciding whether a response should be used. Vani AI is a support automation tool and does not replace human review for critical business decisions.</p>
      </section>

      <section id="availability">
        <h2>10. Availability and Third-Party Services</h2>
        <p>We work to keep the service reliable, but availability may be affected by maintenance, hosting providers, AI providers, email providers, payment systems, internet outages, or events outside our control. We are not responsible for failures caused by third-party platforms or customer-side website changes.</p>
      </section>

      <section id="termination">
        <h2>11. Suspension and Termination</h2>
        <p>We may suspend or terminate access if you breach these terms, fail to pay applicable fees, create security risk, misuse the service, or use the service in a way that may harm Codrant, customers, users, or third parties. You may stop using the service at any time.</p>
      </section>

      <section id="liability">
        <h2>12. Disclaimers and Limitation of Liability</h2>
        <p>The service is provided on an "as is" and "as available" basis to the maximum extent permitted by law. Codrant does not guarantee uninterrupted service, error-free outputs, increased sales, or a specific business outcome.</p>
        <p>To the maximum extent permitted by applicable law, Codrant will not be liable for indirect, incidental, special, consequential, punitive, or loss-of-profit damages. Our aggregate liability for claims relating to the service will not exceed the amount paid by you for the service during the three months before the claim arose, except where liability cannot legally be limited.</p>
      </section>

      <section id="law">
        <h2>13. Governing Law and Jurisdiction</h2>
        <p>These terms are governed by the laws of India. Subject to applicable law, courts located in Pune, Maharashtra will have jurisdiction over disputes relating to these terms or the Vani AI services.</p>
      </section>

      <section id="contact">
        <h2>14. Contact</h2>
        <p>For questions about these terms, contact Codrant at info@codrant.com or visit our contact page.</p>
        <p class="note">Last updated: <?php echo htmlspecialchars($updatedAt); ?>. This page is a practical business terms template and should be reviewed by qualified legal counsel before production launch.</p>
      </section>
    </article>
  </div>
</main>

<footer>© <?php echo date("Y"); ?> Vani AI by Codrant</footer>
</body>
</html>
