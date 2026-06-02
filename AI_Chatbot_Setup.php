<?php
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/ai_service.php';

if (!is_authenticated_user()) {
    $_SESSION['auth_return_to'] = 'AI_Chatbot_Setup.php';
    header('Location: login.php?setup=ai_chatbot&return_to=AI_Chatbot_Setup.php');
    exit;
}

if (!empty($_SESSION['must_reset_password'])) {
    header('Location: forgot-password.php?forced=1');
    exit;
}

$websiteUrl = '';
$error = '';
$success = '';
$scanResult = null;
$savedCustomerId = '';
$scanJobId = '';
$aiConfig = ai_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $websiteUrl = trim((string)($_POST['website_url'] ?? ''));
    $normalizedWebsite = ai_normalize_website_input($websiteUrl);

    if (empty($normalizedWebsite['success'])) {
        $error = (string)$normalizedWebsite['error'];
    } else {
        $websiteUrl = (string)$normalizedWebsite['url'];
        $websiteDomain = (string)$normalizedWebsite['domain'];
        $email = authenticated_email();
        $signup = ai_get_or_create_chatbot_signup($email, $websiteDomain);

        if (empty($signup['success'])) {
            $error = (string)$signup['error'];
        } else {
            $savedCustomerId = (string)$signup['customer_id'];
            $_SESSION['setup_email'] = $email;
            $_SESSION['setup_customer_id'] = $savedCustomerId;
            $_SESSION['setup_website_name'] = $websiteDomain;
            $_SESSION['setup_business_type'] = 'AI Website';
            $_SESSION['ai_chatbot_website_url'] = $websiteUrl;

            $scanJob = ai_create_scan_job($savedCustomerId, $email, $websiteUrl, $websiteDomain, 5);
            if (empty($scanJob['success'])) {
                $error = (string)$scanJob['error'];
            } else {
                $scanJobId = (string)$scanJob['job_id'];
                if (!ai_is_configured()) {
                    $scanResult = [
                        'success' => true,
                        'status' => 'pending',
                        'pages_scanned' => 0,
                        'pages_failed' => 0
                    ];
                    $success = 'Website saved and AI scan job created. Add AI_API_KEY to run the scan.';
                } else {
                    $scanResult = ai_process_scan_job($scanJobId, $savedCustomerId, $websiteUrl, $websiteDomain, 5);
                    if (!empty($scanResult['success'])) {
                        if (!empty($scanResult['ai_error'])) {
                            $success = 'Website pages were saved, but AI summaries were skipped because the provider denied access. Scan job: ' . $scanJobId;
                        } else {
                            $success = 'Website saved and initial AI scan completed for ' . (int)$scanResult['pages_scanned'] . ' page(s).';
                        }
                    } else {
                        $error = 'Website was saved, but the initial scan could not complete. Scan job: ' . $scanJobId;
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/logo_img.png">
<title>AI Chatbot Setup - Vani AI</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/public-theme.css">
<script defer src="js/public-theme.js"></script>
<style>
*{
  margin:0;
  padding:0;
  box-sizing:border-box;
  font-family:'Inter',sans-serif;
}

body{
  min-height:100vh;
  background:
    radial-gradient(circle at 15% 14%,rgba(0,140,255,.24),transparent 34%),
    radial-gradient(circle at 88% 10%,rgba(0,255,209,.12),transparent 30%),
    linear-gradient(135deg,#01030a 0%,#03111f 48%,#02040a 100%);
  color:#f8fafc;
}

.page-shell{
  width:100%;
  max-width:1120px;
  margin:0 auto;
  padding:44px 20px 70px;
}

.setup-grid{
  display:grid;
  grid-template-columns:minmax(0,1.05fr) minmax(320px,.95fr);
  gap:30px;
  align-items:center;
}

.intro{
  display:grid;
  gap:18px;
}

.eyebrow{
  display:inline-flex;
  width:max-content;
  align-items:center;
  min-height:36px;
  padding:0 14px;
  border-radius:999px;
  background:rgba(56,189,248,.12);
  border:1px solid rgba(56,189,248,.24);
  color:#7dd3fc;
  font-size:13px;
  font-weight:800;
}

.intro h1{
  font-size:clamp(34px,6vw,62px);
  line-height:1.05;
  letter-spacing:0;
}

.intro p{
  max-width:660px;
  color:#cbd5e1;
  font-size:17px;
  line-height:1.75;
}

.feature-strip{
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:10px;
  max-width:680px;
  margin-top:6px;
}

.feature-strip span{
  min-height:54px;
  display:flex;
  align-items:center;
  padding:0 12px;
  border-radius:8px;
  background:rgba(15,23,42,.72);
  border:1px solid rgba(148,163,184,.18);
  color:#dbeafe;
  font-size:13px;
  font-weight:700;
}

.setup-card{
  background:linear-gradient(145deg,rgba(2,12,28,.86),rgba(5,28,48,.74));
  border:1px solid rgba(56,189,248,.2);
  box-shadow:0 28px 80px rgba(0,0,0,.34);
  border-radius:16px;
  padding:28px;
}

.account-row{
  padding:14px;
  border-radius:10px;
  background:rgba(15,23,42,.78);
  border:1px solid rgba(148,163,184,.16);
  color:#cbd5e1;
  font-size:13px;
  line-height:1.6;
  margin-bottom:22px;
}

.account-row strong{
  color:#f8fafc;
  word-break:break-word;
}

.setup-card h2{
  font-size:24px;
  margin-bottom:8px;
}

.setup-card p{
  color:#cbd5e1;
  line-height:1.65;
  font-size:14px;
  margin-bottom:18px;
}

label{
  display:block;
  color:#e5e7eb;
  font-size:13px;
  font-weight:800;
  margin-bottom:8px;
}

input{
  width:100%;
  min-height:50px;
  padding:0 14px;
  border-radius:10px;
  border:1px solid rgba(148,163,184,.28);
  background:rgba(255,255,255,.95);
  color:#0f172a;
  font-size:15px;
  outline:none;
}

input:focus{
  border-color:#38bdf8;
  box-shadow:0 0 0 4px rgba(56,189,248,.18);
}

.hint{
  color:#94a3b8;
  font-size:12px;
  line-height:1.5;
  margin-top:8px;
}

.form-actions{
  display:flex;
  gap:10px;
  align-items:center;
  margin-top:20px;
}

.primary-btn,
.ghost-btn{
  min-height:48px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:0 18px;
  border-radius:10px;
  font-weight:800;
  text-decoration:none;
  border:0;
  cursor:pointer;
}

.primary-btn{
  background:linear-gradient(135deg,#38bdf8,#2563eb,#0f172a);
  color:#fff;
  flex:1;
}

.ghost-btn{
  background:rgba(15,23,42,.78);
  border:1px solid rgba(148,163,184,.2);
  color:#e5e7eb;
}

.message{
  margin-bottom:16px;
  padding:12px 14px;
  border-radius:10px;
  font-size:13px;
  line-height:1.5;
  font-weight:700;
}

.message.error{
  color:#fecaca;
  background:rgba(239,68,68,.12);
  border:1px solid rgba(248,113,113,.26);
}

.message.success{
  color:#bbf7d0;
  background:rgba(34,197,94,.12);
  border:1px solid rgba(74,222,128,.24);
}

.login-note{
  margin-top:16px;
  padding-top:16px;
  border-top:1px solid rgba(148,163,184,.16);
  color:#94a3b8;
  font-size:12px;
  line-height:1.6;
}

.ai-config-note{
  margin-bottom:18px;
  padding:12px 14px;
  border-radius:10px;
  background:rgba(56,189,248,.1);
  border:1px solid rgba(56,189,248,.2);
  color:#bae6fd;
  font-size:12px;
  line-height:1.55;
}

.ai-config-note strong{
  color:#f8fafc;
}

.scan-summary{
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:10px;
  margin:0 0 16px;
}

.scan-summary span{
  min-height:58px;
  padding:10px 12px;
  border-radius:8px;
  background:rgba(15,23,42,.78);
  border:1px solid rgba(148,163,184,.18);
  color:#94a3b8;
  font-size:12px;
  line-height:1.35;
}

.scan-summary strong{
  display:block;
  color:#f8fafc;
  font-size:15px;
  margin-top:4px;
  word-break:break-word;
}

@media(max-width:860px){
  .setup-grid{
    grid-template-columns:1fr;
  }

  .feature-strip{
    grid-template-columns:1fr;
  }
}

@media(max-width:520px){
  .page-shell{
    padding:26px 14px 48px;
  }

  .setup-card{
    padding:22px;
  }

  .form-actions{
    display:grid;
  }

  .scan-summary{
    grid-template-columns:1fr;
  }
}
</style>
</head>
<body>

<?php include 'navbar.php'; ?>

<main class="page-shell">
  <section class="setup-grid">
    <div class="intro">
      <span class="eyebrow">AI website scanner</span>
      <h1>Connect your website to create an AI chatbot.</h1>
      <p>
        Submit the website you want Vani AI to scan. We will use this as the starting point for page-wise summaries, category detection, and the later chatbot knowledge base.
      </p>
      <div class="feature-strip" aria-label="AI chatbot setup steps">
        <span>Website URL validation</span>
        <span>Page-wise scan preparation</span>
        <span>Authenticated customer only</span>
      </div>
    </div>

    <div class="setup-card">
      <div class="account-row">
        Logged in as<br>
        <strong><?php echo htmlspecialchars(authenticated_email(), ENT_QUOTES, 'UTF-8'); ?></strong>
      </div>

      <h2>Website details</h2>
      <p>Enter the full website URL. Only valid HTTP or HTTPS URLs are accepted.</p>

      <div class="ai-config-note">
        AI provider: <strong><?php echo htmlspecialchars($aiConfig['provider'], ENT_QUOTES, 'UTF-8'); ?></strong>
        &nbsp; Model: <strong><?php echo htmlspecialchars($aiConfig['model'], ENT_QUOTES, 'UTF-8'); ?></strong>
        <?php if (!ai_is_configured()): ?>
          <br>Set <strong>AI_API_KEY</strong> in your environment before running the scan step.
        <?php endif; ?>
      </div>

      <?php if ($error !== ''): ?>
        <div class="message error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <?php if ($success !== ''): ?>
        <div class="message success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <?php if ($scanResult !== null): ?>
        <div class="scan-summary" aria-label="AI scan summary">
          <span>Status<strong><?php echo htmlspecialchars((string)$scanResult['status'], ENT_QUOTES, 'UTF-8'); ?></strong></span>
          <span>Pages scanned<strong><?php echo htmlspecialchars((string)$scanResult['pages_scanned'], ENT_QUOTES, 'UTF-8'); ?></strong></span>
          <span>Scan job<strong><?php echo htmlspecialchars($scanJobId, ENT_QUOTES, 'UTF-8'); ?></strong></span>
        </div>
      <?php endif; ?>

      <form method="POST" novalidate>
        <label for="websiteUrl">Customer website URL</label>
        <input
          type="url"
          id="websiteUrl"
          name="website_url"
          placeholder="https://example.com"
          value="<?php echo htmlspecialchars($websiteUrl, ENT_QUOTES, 'UTF-8'); ?>"
          inputmode="url"
          autocomplete="url"
          required
          pattern="https?://.+"
        >
        <div class="hint">Example: https://yourcompany.com</div>

        <div class="form-actions">
          <button class="primary-btn" type="submit">Submit website</button>
          <a class="ghost-btn" href="index.php">Back</a>
        </div>
      </form>

      <div class="login-note">
        This AI setup page is protected. Customers must login with Google or create an account before submitting a website.
      </div>
    </div>
  </section>
</main>

</body>
</html>
