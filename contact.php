<?php
require_once __DIR__ . '/email.php';

$errors = [];
$sent = false;
$name = '';
$email = '';
$phone = '';
$message = '';

function clean_contact_value($value) {
    return trim(str_replace(["\r", "\n"], ' ', (string)$value));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = clean_contact_value($_POST['name'] ?? '');
    $email = clean_contact_value($_POST['email'] ?? '');
    $phone = clean_contact_value($_POST['phone'] ?? '');
    $message = trim((string)($_POST['message'] ?? ''));
    $website = trim((string)($_POST['website'] ?? ''));

    if ($website !== '') {
        $errors[] = 'Unable to submit this request.';
    }
    if ($name === '' || strlen($name) < 2) {
        $errors[] = 'Please enter your name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($message === '' || strlen($message) < 10) {
        $errors[] = 'Please enter a message with at least 10 characters.';
    }

    if (!$errors) {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safePhone = htmlspecialchars($phone ?: 'Not provided', ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        $html = '
          <h2>New Vani AI contact request</h2>
          <p><strong>Name:</strong> ' . $safeName . '</p>
          <p><strong>Email:</strong> ' . $safeEmail . '</p>
          <p><strong>Phone:</strong> ' . $safePhone . '</p>
          <p><strong>Message:</strong></p>
          <p>' . $safeMessage . '</p>
        ';

        $sent = sendBrevoEmail('info@codrant.com', 'New contact request from Vani AI', $html);
        if (!$sent) {
            $errors[] = 'Message could not be sent right now. Please email info@codrant.com directly.';
        } else {
            $name = $email = $phone = $message = '';
        }
    }
}

function field_value($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contact - Vani AI</title>
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
h1{margin-top:22px;font-size:clamp(36px,7vw,64px);line-height:1.08;color:#fff}
.hero p{max-width:760px;margin-top:18px;color:#cbd5e1;font-size:18px;line-height:1.8}
.contact-grid{display:grid;grid-template-columns:1fr 1.2fr;gap:24px;padding:24px 0 70px;align-items:start}
.panel,.form-card{background:linear-gradient(145deg,rgba(15,23,42,.88),rgba(30,41,59,.7));border:1px solid rgba(129,140,248,.24);box-shadow:0 22px 55px rgba(0,0,0,.32);border-radius:18px}
.panel{padding:28px;display:grid;gap:18px}
.detail{padding:16px;border-radius:14px;background:rgba(15,23,42,.5);border:1px solid rgba(148,163,184,.18)}
.detail strong{display:block;color:#f8fafc;margin-bottom:6px}
.detail a,.detail span{color:#cbd5e1;text-decoration:none;line-height:1.7}
.form-card{padding:30px}
form{display:grid;gap:16px}
.field{display:grid;gap:8px}
label{font-weight:700;color:#f8fafc;font-size:14px}
input,textarea{
  width:100%;
  border:1px solid rgba(148,163,184,.24);
  background:rgba(2,6,23,.52);
  color:#f8fafc;
  border-radius:12px;
  padding:14px 15px;
  font-size:15px;
  outline:none;
}
textarea{min-height:150px;resize:vertical}
input:focus,textarea:focus{border-color:#a78bfa;box-shadow:0 0 0 4px rgba(167,139,250,.14)}
.hidden-field{display:none}
button{border:none;border-radius:12px;background:linear-gradient(45deg,#6366f1,#ec4899);color:#fff;font-weight:800;padding:15px 20px;cursor:pointer;box-shadow:0 18px 42px rgba(236,72,153,.22);font-size:15px}
.alert{padding:14px 16px;border-radius:14px;line-height:1.6;font-weight:700}
.success{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#bbf7d0}
.error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fecaca}
footer{padding:24px 0 40px;color:#94a3b8;text-align:center;font-size:14px}
@media(max-width:992px){
  .contact-grid{grid-template-columns:1fr}
  .form-card,.panel{padding:24px}
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
    <span class="eyebrow">Contact Codrant</span>
    <h1>Talk to us about Vani AI</h1>
    <p>Send a message for chatbot setup, subscriptions, technical help, or business enquiries. We will reply on the email address you provide.</p>
  </div>

  <div class="contact-grid">
    <aside class="panel">
      <div class="detail">
        <strong>Email</strong>
        <a href="mailto:info@codrant.com">info@codrant.com</a>
      </div>
      <div class="detail">
        <strong>Phone</strong>
        <a href="tel:+919579246848">+91-9579246848</a>
      </div>
      <div class="detail">
        <strong>Address</strong>
        <span>Codrant, Behind Golden Care Hospital, Bhumkar Chowk, Wakad, Pune 411057.</span>
      </div>
    </aside>

    <section class="form-card">
      <?php if ($sent): ?>
        <div class="alert success">Thank you. Your message has been sent to info@codrant.com.</div>
      <?php endif; ?>
      <?php if ($errors): ?>
        <div class="alert error"><?php echo htmlspecialchars(implode(' ', $errors), ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <form method="POST" action="contact.php">
        <div class="hidden-field">
          <label>Website <input type="text" name="website" autocomplete="off"></label>
        </div>
        <div class="field">
          <label for="name">Name</label>
          <input id="name" name="name" type="text" value="<?php echo field_value($name); ?>" required>
        </div>
        <div class="field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" value="<?php echo field_value($email); ?>" required>
        </div>
        <div class="field">
          <label for="phone">Phone</label>
          <input id="phone" name="phone" type="tel" value="<?php echo field_value($phone); ?>">
        </div>
        <div class="field">
          <label for="message">Message</label>
          <textarea id="message" name="message" required><?php echo field_value($message); ?></textarea>
        </div>
        <button type="submit">Send Message</button>
      </form>
    </section>
  </div>
</main>

<footer>© <?php echo date("Y"); ?> Vani AI by Codrant</footer>
</body>
</html>
