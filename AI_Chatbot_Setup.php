<?php
require_once __DIR__ . '/session-auth.php';

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

function ai_setup_valid_url(string $url): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = (string)($parts['host'] ?? '');

    return in_array($scheme, ['http', 'https'], true) && $host !== '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $websiteUrl = trim((string)($_POST['website_url'] ?? ''));

    if ($websiteUrl === '') {
        $error = 'Please enter your website URL.';
    } elseif (!ai_setup_valid_url($websiteUrl)) {
        $error = 'Please enter a valid website URL, for example https://example.com.';
    } else {
        $_SESSION['ai_chatbot_website_url'] = $websiteUrl;
        $success = 'Website submitted successfully. The AI scanning step will come next.';
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

      <?php if ($error !== ''): ?>
        <div class="message error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <?php if ($success !== ''): ?>
        <div class="message success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
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
