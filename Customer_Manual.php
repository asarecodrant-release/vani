<?php
require_once __DIR__ . '/session-auth.php';
$updatedAt = 'May 26, 2026';
$manualDashboardUrl = is_authenticated_user() ? 'dashboard.php' : 'login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/logo_img.png">
<title>Customer Manual - Vani AI</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/public-theme.css">
<script defer src="js/public-theme.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:Inter,Arial,sans-serif}
:root{--bg:#f8fafc;--panel:#fff;--ink:#111827;--muted:#64748b;--line:#e2e8f0;--brand:#4f46e5;--brand2:#0891b2;--soft:#eef2ff;--good:#15803d;--warn:#b45309;--bad:#b91c1c;--shadow:0 14px 40px rgba(15,23,42,.08)}
body{background:linear-gradient(180deg,#eef2ff 0,#f8fafc 320px);color:var(--ink);line-height:1.65}
.container{max-width:1220px;margin:0 auto;padding:24px 20px 70px}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px}
.brand{display:flex;align-items:center;gap:12px;color:var(--ink);text-decoration:none;font-weight:900;font-size:20px}
.brand img{width:46px;height:46px;object-fit:contain}
.actions{display:flex;gap:10px;flex-wrap:wrap}
.btn,.ghost{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border-radius:12px;padding:0 15px;text-decoration:none;font-weight:850;border:1px solid transparent;cursor:pointer}
.btn{background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff}
.ghost{background:rgba(255,255,255,.76);border-color:var(--line);color:var(--ink)}
.manual-link-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:14px 0 24px}.manual-link-grid a{min-height:44px;border:1px solid var(--line);border-radius:13px;background:rgba(255,255,255,.78);display:inline-flex;align-items:center;justify-content:center;text-align:center;padding:8px 10px;color:var(--ink);text-decoration:none;font-size:13px;font-weight:850}
.hero{display:grid;gap:12px;margin:18px 0 24px}.eyebrow{text-transform:uppercase;letter-spacing:.08em;color:var(--brand);font-weight:900;font-size:12px}.hero h1{font-size:42px;line-height:1.08;max-width:920px}.hero p{max-width:900px;color:var(--muted);font-size:17px}
.layout{display:grid;grid-template-columns:282px minmax(0,1fr);gap:18px;align-items:start}.toc{position:sticky;top:16px;display:grid;gap:8px}.toc a{padding:10px 12px;border:1px solid var(--line);background:rgba(255,255,255,.78);border-radius:12px;color:var(--ink);text-decoration:none;font-weight:750;font-size:14px}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:22px;margin-bottom:16px;box-shadow:var(--shadow)}.panel h2{font-size:24px;margin-bottom:10px}.panel h3{font-size:17px;margin:18px 0 8px}.panel p,.panel li{color:var(--muted);font-size:15px}.panel ul,.panel ol{display:grid;gap:8px;padding-left:21px}.tag{display:inline-flex;align-items:center;border-radius:999px;background:rgba(79,70,229,.1);color:var(--brand);padding:5px 10px;font-size:12px;font-weight:900}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.three{grid-template-columns:repeat(3,minmax(0,1fr))}.card{border:1px solid var(--line);border-radius:14px;padding:15px;background:#fff}.card strong{display:block;margin-bottom:5px}.note{padding:14px 16px;border-radius:14px;background:#ecfeff;border:1px solid #a5f3fc;color:#155e75;font-weight:700}.warn{background:#fffbeb;border-color:#fde68a;color:#92400e}
table{width:100%;border-collapse:collapse;min-width:720px}.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:14px;margin-top:10px}th,td{text-align:left;border-bottom:1px solid var(--line);padding:11px 12px;vertical-align:top;font-size:13px}th{background:#f1f5f9;color:#475569;text-transform:uppercase;letter-spacing:.05em;font-size:11px}tr:last-child td{border-bottom:0}
.mock{border:1px solid #c7d2fe;border-radius:18px;overflow:hidden;background:#fff;margin:14px 0}.mock-head{display:flex;gap:8px;align-items:center;justify-content:space-between;padding:12px 14px;background:#f8fafc;border-bottom:1px solid var(--line)}.mock-tabs{display:flex;gap:7px;flex-wrap:wrap}.mock-tab{height:26px;border-radius:999px;background:#e0e7ff;color:#3730a3;padding:4px 10px;font-size:12px;font-weight:850}.mock-body{padding:15px;display:grid;gap:12px}.mock-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.mock-kpi{border:1px solid var(--line);border-radius:12px;padding:10px;background:#fff}.mock-kpi span{display:block;color:var(--muted);font-size:11px;text-transform:uppercase;font-weight:850}.mock-kpi b{font-size:20px}.mock-chart{height:120px;border-radius:12px;background:linear-gradient(135deg,#eef2ff,#ecfeff);border:1px solid #dbeafe;display:flex;align-items:end;gap:8px;padding:12px}.bar{width:18px;border-radius:8px 8px 0 0;background:linear-gradient(180deg,var(--brand),var(--brand2))}.mock-list{display:grid;gap:8px}.mock-row{display:grid;grid-template-columns:120px 1fr 60px;gap:10px;align-items:center;font-size:12px;color:#475569}.track{height:9px;background:#e2e8f0;border-radius:999px;overflow:hidden}.fill{height:100%;background:linear-gradient(90deg,var(--brand),var(--brand2));border-radius:999px}
.steps{counter-reset:step;display:grid;gap:10px}.step{position:relative;padding:13px 14px 13px 48px;border:1px solid var(--line);border-radius:14px;background:#fff}.step:before{counter-increment:step;content:counter(step);position:absolute;left:14px;top:14px;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;background:var(--brand);color:#fff;font-size:12px;font-weight:900}.step strong{display:block}
code{display:block;background:#111827;color:#e5e7eb;border-radius:14px;padding:13px;white-space:pre-wrap;word-break:break-word;font-size:13px;line-height:1.55}.inline{display:inline;background:#eef2ff;color:#3730a3;border-radius:7px;padding:2px 6px;font-weight:750}
@media(max-width:900px){.layout,.grid,.three,.manual-link-grid{grid-template-columns:1fr}.toc{position:static}.hero h1{font-size:32px}.topbar{align-items:flex-start;flex-direction:column}.mock-kpis{grid-template-columns:repeat(2,1fr)}table{min-width:640px}}
@media print{body{background:#fff}.topbar,.toc,.no-print{display:none!important}.container{max-width:none;padding:0}.layout{display:block}.panel,.card,.mock{box-shadow:none;break-inside:avoid}.panel{border-color:#d1d5db}.hero{margin-top:0}.hero h1{font-size:30px}}
</style>
</head>
<body class="vani-public-theme">
<?php include 'navbar.php'; ?>
<div class="container">
  <div class="topbar">
    <a class="brand" href="index.php"><img src="images/logo_img.png" alt="Vani AI"><span>Vani AI Manual</span></a>
    <div class="actions no-print">
      <button class="btn" type="button" onclick="window.print()">Download PDF</button>
      <a class="ghost" href="<?php echo htmlspecialchars($manualDashboardUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo is_authenticated_user() ? 'Open Dashboard' : 'Login for Dashboard'; ?></a>
    </div>
  </div>

  <section class="hero">
    <span class="eyebrow">Customer Dashboard Guide</span>
    <h1>Build, publish, measure, and improve your website chatbot.</h1>
    <p>This manual explains every major Vani AI dashboard tab, sub-tab, setting, metric, and recommended workflow so a customer can create a high-performing FAQ chatbot for their website.</p>
    <p><span class="tag">Last updated <?php echo htmlspecialchars($updatedAt, ENT_QUOTES, 'UTF-8'); ?></span></p>
  </section>

  <nav class="manual-link-grid no-print" aria-label="Manual page shortcuts">
    <a href="index.php">Home</a>
    <a href="<?php echo htmlspecialchars($manualDashboardUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo is_authenticated_user() ? 'Dashboard' : 'Login' ?></a>
    <a href="freebot.php">Create Chatbot</a>
    <a href="theme-selection.php">Theme Selection</a>
    <a href="faq-setup.php">FAQ Setup</a>
    <a href="complete-setup.php">Complete Setup</a>
    <a href="test-chatbot.php">Test Chatbot</a>
    <a href="subscription.php">Subscription Plans</a>
    <a href="terms.php">Terms</a>
    <a href="privacy-policy.php">Privacy</a>
    <a href="cancellation-refund-policy.php">Cancellation & Refund</a>
    <a href="contact.php">Contact</a>
  </nav>

  <div class="layout">
    <nav class="toc" aria-label="Manual sections">
      <a href="#quick-start">Quick Start</a>
      <a href="#dashboard">Dashboard</a>
      <a href="#setup">Chatbot Setup</a>
      <a href="#faq">FAQ Management</a>
      <a href="#outside">Outside FAQs</a>
      <a href="#feedback">Feedback Received</a>
      <a href="#payments">Payments Collection</a>
      <a href="#analytics">Analytics</a>
      <a href="#integration">Integration</a>
      <a href="#leads">Lead Generation</a>
      <a href="#wallet">Wallet Plans</a>
      <a href="#profile-billing">Profile & Billing</a>
      <a href="#testing">Testing</a>
      <a href="#best-practices">Best Practices</a>
    </nav>

    <main>
      <section class="panel" id="quick-start">
        <h2>1. Quick Start Workflow</h2>
        <div class="steps">
          <div class="step"><strong>Select or create your chatbot.</strong><p>Use the dashboard bot selector if you manage more than one website chatbot.</p></div>
          <div class="step"><strong>Configure Chatbot Setup.</strong><p>Set bot name, greeting, avatar, theme, position, open behavior, and user typing field.</p></div>
          <div class="step"><strong>Add FAQs and categories.</strong><p>Create FAQ Q&A, organize by category, enable FAQ menu, and add action suggestions where useful.</p></div>
          <div class="step"><strong>Enable optional features.</strong><p>Lead generation, feedback collection, payments, webhooks, analytics, and API depend on your plan.</p></div>
          <div class="step"><strong>Install the snippet.</strong><p>Paste the one-line Vani script on your website before the closing body tag, or use WordPress/Wix/Shopify/GTM instructions.</p></div>
          <div class="step"><strong>Test like a visitor.</strong><p>Open Test Chatbot, ask real questions, verify suggestions, leads, payments, feedback, and mobile keyboard behavior.</p></div>
        </div>
      </section>

      <section class="panel">
        <h2>Interface Preview</h2>
        <p>These sample visuals are simplified guide images. Your dashboard may look slightly different depending on plan, bot, and data.</p>
        <div class="mock">
          <div class="mock-head"><strong>Analytics BI Preview</strong><div class="mock-tabs"><span class="mock-tab">Overview</span><span class="mock-tab">Payment Analysis</span><span class="mock-tab">Reports</span></div></div>
          <div class="mock-body">
            <div class="mock-kpis"><div class="mock-kpi"><span>Conversations</span><b>248</b></div><div class="mock-kpi"><span>Answer Rate</span><b>86%</b></div><div class="mock-kpi"><span>Leads</span><b>31</b></div><div class="mock-kpi"><span>Revenue</span><b>Rs12,450</b></div></div>
            <div class="mock-chart"><i class="bar" style="height:40%"></i><i class="bar" style="height:70%"></i><i class="bar" style="height:52%"></i><i class="bar" style="height:88%"></i><i class="bar" style="height:66%"></i><i class="bar" style="height:92%"></i></div>
          </div>
        </div>
      </section>

      <section class="panel" id="dashboard">
        <h2>2. Dashboard Tab</h2>
        <p>The Dashboard tab is the control room for the selected chatbot. It summarizes current setup, usage, activity, plan status, and important shortcuts.</p>
        <div class="grid">
          <div class="card"><strong>Use it for</strong><p>Checking whether the chatbot is active, reviewing important metrics, jumping to setup areas, and confirming the selected bot.</p></div>
          <div class="card"><strong>Key habit</strong><p>Before changing anything, confirm the selected chatbot belongs to the website you are editing.</p></div>
        </div>
      </section>

      <section class="panel" id="setup">
        <h2>3. Chatbot Setup Tab</h2>
        <p>Use this tab to control the visitor-facing look and behavior of the chatbot.</p>
        <div class="table-wrap"><table><thead><tr><th>Setting</th><th>Meaning</th><th>Recommendation</th></tr></thead><tbody>
          <tr><td>Bot name</td><td>Name shown inside the chatbot.</td><td>Use your business or support team name.</td></tr>
          <tr><td>Welcome message</td><td>First greeting visitors see.</td><td>Keep it friendly and specific, for example "Hi, how can I help you today?"</td></tr>
          <tr><td>Theme color/pattern</td><td>Brand styling for bubble, buttons, and highlights.</td><td>Match your website accent color.</td></tr>
          <tr><td>Avatar</td><td>Image used in the chatbot bubble/header.</td><td>Use a logo or support avatar with clear contrast.</td></tr>
          <tr><td>Position</td><td>Left or right placement on the website.</td><td>Choose the side that does not cover important website buttons.</td></tr>
          <tr><td>Chat open by default</td><td>Opens chatbot automatically for visitors.</td><td>Use carefully on dense pages; manual open is often cleaner.</td></tr>
          <tr><td>User typing field</td><td>Allows visitors to type custom questions.</td><td>Keep ON for support chat. If OFF, visitors navigate through FAQs/categories.</td></tr>
        </tbody></table></div>
      </section>

      <section class="panel" id="faq">
        <h2>4. FAQ Management Tab</h2>
        <p>This is where the actual chatbot knowledge and visitor action buttons are built.</p>
        <h3>Options Sub-tab</h3>
        <ul>
          <li><strong>FAQ Action Suggestions:</strong> enables buttons under answers, such as call, WhatsApp, link, coupon, map, category, form, payment, booking, download, event, or track order.</li>
          <li><strong>Category menu:</strong> lets visitors browse FAQ categories instead of typing.</li>
          <li><strong>Payment action value:</strong> use the Payment Button ID from Payments Collection when creating a Make Payment action.</li>
        </ul>
        <h3>Default FAQs Sub-tab</h3>
        <p>Default FAQs provide fallback answers for common questions like contact support, business hours, location/service area, and human agent requests. Enable or edit them before launch.</p>
        <h3>FAQ Q&A Sub-tab</h3>
        <p>Add the real questions visitors ask and clear answers. Use categories such as Pricing, Delivery, Appointment, Refund, Support, or Product Info.</p>
        <h3>Scheduled Actions Sub-tab</h3>
        <p>Scheduled actions show action suggestions after a chosen number of visitor questions. This is useful for prompting "Book now", "Talk on WhatsApp", or "Make Payment" after engagement.</p>
        <h3>Collect Feedback From Users Sub-tab</h3>
        <p>Growth and Business customers can choose which FAQ actions should ask for feedback and choose the feedback type.</p>
        <div class="table-wrap"><table><thead><tr><th>Feedback type</th><th>Visitor experience</th><th>Best use</th></tr></thead><tbody>
          <tr><td>Stars</td><td>Visitor selects a star rating.</td><td>Simple quality rating.</td></tr>
          <tr><td>Emoji/smiles</td><td>Visitor selects a feeling.</td><td>Fast mobile-friendly satisfaction signal.</td></tr>
          <tr><td>Labels</td><td>Great, Helpful, Okay, Poor, Need help.</td><td>Support quality review.</td></tr>
          <tr><td>Slider</td><td>Visitor slides satisfaction value.</td><td>More granular satisfaction score.</td></tr>
          <tr><td>Comment</td><td>Visitor writes feedback.</td><td>Detailed feedback, but review for sensitive data.</td></tr>
        </tbody></table></div>
      </section>

      <section class="panel" id="outside">
        <h2>5. Outside FAQs Tab</h2>
        <p>This tab lists questions the chatbot could not answer properly. Treat this as your FAQ improvement queue.</p>
        <ul>
          <li>Review unanswered questions weekly.</li>
          <li>Convert repeated visitor questions into new FAQ Q&A entries.</li>
          <li>Check source page to understand where the visitor was confused.</li>
          <li>Use this data to improve website copy as well as chatbot answers.</li>
        </ul>
      </section>

      <section class="panel" id="feedback">
        <h2>6. Feedback Received Tab</h2>
        <p>Growth and Business customers can view collected feedback from visitors.</p>
        <div class="grid">
          <div class="card"><strong>List view</strong><p>Shows date, feedback value, related FAQ/action, and source page.</p></div>
          <div class="card"><strong>Date filters</strong><p>Use filters to review feedback by today, week, month, or custom period.</p></div>
          <div class="card"><strong>Email switch</strong><p>Enable Receive Feedback via Email if your team wants alerts.</p></div>
          <div class="card"><strong>Upgrade gating</strong><p>Starter users see an upgrade alert for this feature.</p></div>
        </div>
      </section>

      <section class="panel" id="payments">
        <h2>7. Payments Collection Tab</h2>
        <p>Growth and Business customers can collect payments from website visitors directly to their own Razorpay account or UPI ID.</p>
        <div class="note warn">Visitor payments go to the customer's own payment account, not to Vani AI. The customer is responsible for pricing, taxes, invoices, refunds, delivery, and disputes.</div>
        <h3>Payment Setup</h3>
        <ul>
          <li><strong>Enable payment collection:</strong> global ON/OFF switch for chatbot payments.</li>
          <li><strong>Business name:</strong> name shown on checkout/payment context.</li>
          <li><strong>Razorpay Key ID and Secret:</strong> required only for Razorpay Checkout buttons. The secret is encrypted on the server.</li>
          <li><strong>Success message:</strong> message shown after successful Razorpay verification.</li>
        </ul>
        <h3>Payment Buttons</h3>
        <ul>
          <li><strong>Razorpay Checkout:</strong> creates an order and verifies successful payment automatically.</li>
          <li><strong>UPI Redirect:</strong> opens an installed UPI app with payee, amount, currency, and note. UPI payments remain pending until the business verifies manually.</li>
          <li><strong>Copy Payment ID:</strong> copies the button ID for FAQ payment actions.</li>
          <li><strong>Create Make Payment Action:</strong> creates the FAQ action automatically for a selected FAQ.</li>
        </ul>
        <h3>Payment Activity</h3>
        <p>Review status, method, amount, payment button, payer, gateway reference, and source page. UPI created records should be reconciled by the business.</p>
      </section>

      <section class="panel" id="analytics">
        <h2>8. Analytics Tab</h2>
        <p>Analytics turns chatbot activity into BI-style metrics. Growth includes core analytics. Business unlocks advanced pages/reports exports.</p>
        <div class="table-wrap"><table><thead><tr><th>Sub-tab</th><th>What it shows</th><th>Plan</th></tr></thead><tbody>
          <tr><td>Overview</td><td>Conversations, messages, visitors, answer rate, unanswered rate, leads, OTP verified leads, response time, duration, funnel, device mix, top questions, source pages.</td><td>Growth+</td></tr>
          <tr><td>Conversations</td><td>Hourly usage, browser breakdown, conversation trend, device analytics, raw conversation patterns.</td><td>Growth+</td></tr>
          <tr><td>FAQ Insights</td><td>Top questions, unanswered questions, FAQ performance, answer coverage.</td><td>Growth+</td></tr>
          <tr><td>Feedback</td><td>Feedback trend, feedback values, actions getting feedback, recent feedback.</td><td>Growth+</td></tr>
          <tr><td>Payment Analysis</td><td>Revenue collected, conversion, pending payments, top payment button, revenue trend, status, methods, payment buttons, recent payment activity.</td><td>Growth/Business</td></tr>
          <tr><td>Leads</td><td>Lead capture trend, lead quality, unique leads, real/weak leads, email/mobile contacts, WhatsApp clicks.</td><td>Growth+</td></tr>
          <tr><td>Pages</td><td>Source page performance, conversations, leads, success rate, location/page behavior.</td><td>Business</td></tr>
          <tr><td>Real-Time</td><td>Recent live sessions and current activity signals.</td><td>Business</td></tr>
          <tr><td>Reports</td><td>CSV, branded report, print/save PDF, weekly and monthly report downloads.</td><td>Business</td></tr>
        </tbody></table></div>
        <h3>Important Metrics</h3>
        <div class="grid three">
          <div class="card"><strong>Answer Rate</strong><p>Percentage of questions answered by available FAQs or matched logic.</p></div>
          <div class="card"><strong>Unanswered Rate</strong><p>Questions needing new FAQs or better wording.</p></div>
          <div class="card"><strong>Lead Conversion</strong><p>Leads collected compared with conversations.</p></div>
          <div class="card"><strong>Real Leads</strong><p>Email or mobile OTP verified leads.</p></div>
          <div class="card"><strong>Payment Conversion</strong><p>Paid payments compared with payment attempts.</p></div>
          <div class="card"><strong>Returning Users</strong><p>Visitors who came back based on widget identifiers.</p></div>
        </div>
      </section>

      <section class="panel" id="integration">
        <h2>9. Integration Tab</h2>
        <p>This tab helps you install the chatbot safely and connect it to external systems.</p>
        <h3>Install & Domains Sub-tab</h3>
        <ul>
          <li><strong>WordPress:</strong> download plugin ZIP and upload it in WordPress admin.</li>
          <li><strong>Wix:</strong> add the snippet through Wix custom code on all pages.</li>
          <li><strong>Shopify:</strong> paste snippet before the closing body tag in theme code.</li>
          <li><strong>Google Tag Manager:</strong> use a Custom HTML tag triggered on all pages.</li>
          <li><strong>Custom website:</strong> paste the secure iframe loader snippet.</li>
          <li><strong>Website verification:</strong> only load chatbot on the connected website.</li>
          <li><strong>Allowed domains:</strong> Business feature to restrict the chatbot to approved domains.</li>
        </ul>
        <p>Strict customer websites may need CSP allow rules:</p>
        <code>script-src https://vani.codrant.com;
frame-src https://vani.codrant.com;
connect-src https://vani.codrant.com;</code>
        <h3>API Keys Sub-tab</h3>
        <p>Business customers can create customer-safe API keys, set daily rate limits, restrict server IPs/origins, revoke keys, and open the API guide. API access is read-only and scoped to the selected bot.</p>
        <h3>Webhooks & Live Actions Sub-tab</h3>
        <p>Configure webhook URL and secret for backend event delivery, then enable live chat actions when the customer's website should react in the browser to chatbot events.</p>
      </section>

      <section class="panel" id="leads">
        <h2>10. Lead Generation Setup Tab</h2>
        <p>Lead generation turns visitor interest into contact records.</p>
        <ul>
          <li><strong>Enable lead generation:</strong> master switch for lead capture.</li>
          <li><strong>Collect email/mobile/location:</strong> choose which fields to request.</li>
          <li><strong>Email OTP and Mobile OTP:</strong> verifies real leads based on plan availability.</li>
          <li><strong>Notify lead by email:</strong> sends lead notifications to your team.</li>
          <li><strong>WhatsApp redirect:</strong> sends visitors to WhatsApp and tracks clicks where enabled.</li>
          <li><strong>Human handoff:</strong> creates support tickets for unanswered questions when configured.</li>
        </ul>
      </section>

      <section class="panel" id="wallet">
        <h2>11. Wallet Plans Tab</h2>
        <p>Plans control limits, feature access, and wallet usage. Recharge wallet to unlock the selected plan benefits.</p>
        <div class="table-wrap"><table><thead><tr><th>Plan</th><th>Main use</th><th>Includes</th></tr></thead><tbody>
          <tr><td>Free</td><td>Basic trial/testing.</td><td>Limited FAQ capacity and basic chatbot setup.</td></tr>
          <tr><td>Starter</td><td>Small websites.</td><td>More FAQs, OTP verification, WhatsApp redirect, webhook support, FAQ action suggestions.</td></tr>
          <tr><td>Growth</td><td>Growing businesses.</td><td>Human handoff, feedback collection, payment collection, core analytics, better wallet rates.</td></tr>
          <tr><td>Business</td><td>Advanced operations.</td><td>Unlimited FAQ capacity, API access, advanced analytics, reports, allowed domains, live chat actions.</td></tr>
        </tbody></table></div>
        <p>Auto recharge can authorize future wallet recharges when balance drops below the plan threshold. You can stop automatic payment from the Wallet Plans area.</p>
      </section>

      <section class="panel" id="profile-billing">
        <h2>12. Profile and Billing Tabs</h2>
        <div class="grid">
          <div class="card"><strong>Profile</strong><p>Manage business/customer profile fields such as first name, last name, mobile, country, address, location notes, and avatar where available.</p></div>
          <div class="card"><strong>Billing</strong><p>Review wallet transactions, invoices, plan status, payment references, and billing history. Use this for finance reconciliation.</p></div>
        </div>
      </section>

      <section class="panel" id="testing">
        <h2>13. Test Chatbot</h2>
        <p>Use Test Chatbot before publishing major changes.</p>
        <ul>
          <li>Ask common questions exactly how visitors ask them.</li>
          <li>Test on desktop, tablet, and mobile.</li>
          <li>Open the mobile keyboard and verify suggestions, latest answer visibility, and scrolling.</li>
          <li>Test FAQ actions: links, call, WhatsApp, forms, category, coupon, map, and payment.</li>
          <li>Confirm feedback is collected after the configured action.</li>
          <li>Confirm leads, OTP, WhatsApp clicks, payments, and analytics appear in dashboard.</li>
        </ul>
      </section>

      <section class="panel" id="best-practices">
        <h2>14. Best Practices for a Strong Chatbot</h2>
        <div class="grid">
          <div class="card"><strong>Write natural questions</strong><p>Add FAQs in the same language your customers use, not only internal business wording.</p></div>
          <div class="card"><strong>Keep answers short</strong><p>Use direct answers first, then action buttons for next steps.</p></div>
          <div class="card"><strong>Use categories</strong><p>Categories help when user typing is OFF or visitors prefer browsing.</p></div>
          <div class="card"><strong>Add action buttons</strong><p>Use WhatsApp, call, booking, payment, coupon, or map actions to move visitors forward.</p></div>
          <div class="card"><strong>Review Outside FAQs</strong><p>Unanswered questions are your best signal for improving coverage.</p></div>
          <div class="card"><strong>Measure weekly</strong><p>Use Analytics, Feedback, Leads, and Payment Analysis to improve business outcomes.</p></div>
        </div>
        <h3>Launch Checklist</h3>
        <ul>
          <li>Bot name, greeting, avatar, theme, position configured.</li>
          <li>At least 20-50 important FAQs added and categorized.</li>
          <li>Default FAQs reviewed.</li>
          <li>Lead generation, feedback, payments, and webhooks enabled only if needed.</li>
          <li>Snippet installed on website and CSP/domain settings checked.</li>
          <li>Mobile and desktop tested.</li>
          <li>Analytics and outside FAQs reviewed after launch.</li>
        </ul>
      </section>
    </main>
  </div>
</div>
</body>
</html>
