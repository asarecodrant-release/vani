<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/session-auth.php";

header("Content-Type: text/html; charset=UTF-8");
header(
    "Cross-Origin-Opener-Policy: same-origin-allow-popups"
);

require "core.php";
require_once __DIR__ . "/email.php";

$error = "";
$resetMessage = "";
$resetError = "";
$showReset = false;
$googleClientId =
    $_ENV['GOOGLE_CLIENT_ID']
    ?? getenv('GOOGLE_CLIENT_ID')
    ?: '970273381861-ar6734p4c2hl3pn0g58segkgccfvoirv.apps.googleusercontent.com';

function login_safe_rows(array $response): array {
    return is_array($response['data'] ?? null) ? $response['data'] : [];
}

function login_customer_password_update(string $email, string $passwordHash): array {
    $payload = ["password" => $passwordHash, "must_reset_password" => false];
    $res = supabase("PATCH", "customers?email=eq." . urlencode($email), $payload);
    if ($res['status'] >= 200 && $res['status'] < 300) {
        return $res;
    }
    if (strpos(strtolower((string)($res['raw'] ?? '')), 'must_reset_password') !== false) {
        return supabase("PATCH", "customers?email=eq." . urlencode($email), ["password" => $passwordHash]);
    }
    return $res;
}

function login_user_has_chatbot(string $email): bool {
    if ($email === '') {
        return false;
    }
    return !empty(login_safe_rows(supabase(
        "GET",
        "chatbot_signups?select=customer_id&email=eq." . urlencode($email) . "&limit=1"
    )));
}

function login_user_has_pending_subscription(string $email): bool {
    if ($email === '') {
        return false;
    }
    $rows = login_safe_rows(supabase(
        "GET",
        "billing_accounts?select=current_plan,subscription_status,wallet_balance_paise&email=eq." . urlencode($email) . "&customer_id=is.null&limit=1"
    ));
    $account = $rows[0] ?? [];
    return (string)($account['current_plan'] ?? 'free') !== 'free'
        || (string)($account['subscription_status'] ?? 'free') !== 'free'
        || (int)($account['wallet_balance_paise'] ?? 0) > 0;
}

function login_redirect_after_success(string $email): void {
    if (!empty($_SESSION['must_reset_password'])) {
        header("Location: login.php?reset=1&forced=1");
        exit;
    }
    if (!login_user_has_chatbot($email)) {
        $params = ["notice" => "select_product"];
        if (login_user_has_pending_subscription($email)) {
            $params["reset_password"] = "1";
        }
        header("Location: index.php?" . http_build_query($params));
        exit;
    }
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && (string)($_GET['subscription'] ?? '') === 'success') {
    $resetMessage = "Subscription activated. Check your email for login details, then reset your password after logging in.";
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && (string)($_GET['reset'] ?? '') === '1') {
    $showReset = true;
    $resetMessage = (string)($_GET['forced'] ?? '') === '1'
        ? "For security, reset your temporary password before continuing."
        : "Enter your email below to reset the temporary password.";
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && (string)($_GET['setup'] ?? '') === 'incomplete') {
    $resetMessage = "Please login to continue your unfinished chatbot setup.";
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && (string)($_GET['upgrade'] ?? '') === '1') {
    $resetMessage = "Login to upgrade your existing chatbot from Dashboard > Subscription.";
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && is_authenticated_user() && !$showReset) {
    login_redirect_after_success(authenticated_email());
}

// ======================================
// GOOGLE LOGIN
// ======================================
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['google_email'])
) {

    header("Content-Type: application/json");

    echo json_encode([
        "success" => false,
        "message" => "Google credential is required"
    ]);

    exit;

}

function sendPasswordResetCodeEmail(string $toEmail, string $code): bool {
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $html = '
    <!doctype html>
    <html>
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" type="image/png" href="images/logo_img.png">
    </head>
    <body style="margin:0;padding:0;background:#eef2ff;font-family:Inter,Arial,sans-serif;color:#0f172a;">
      <div style="padding:30px 14px;background:radial-gradient(circle at top left,rgba(99,102,241,.28),transparent 34%),radial-gradient(circle at 90% 0,rgba(236,72,153,.22),transparent 32%),linear-gradient(135deg,#f8fafc,#eef2ff,#faf5ff);">
        <div style="max-width:620px;margin:0 auto;background:#ffffff;border:1px solid rgba(148,163,184,.24);border-radius:24px;overflow:hidden;box-shadow:0 24px 70px rgba(15,23,42,.13);">
          <div style="padding:34px 30px;background:linear-gradient(135deg,#6366f1,#8b5cf6,#ec4899);color:#ffffff;">
            <div style="display:inline-block;padding:7px 11px;border-radius:999px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.24);font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">Vani by Codrant</div>
            <h1 style="margin:16px 0 8px;font-size:30px;line-height:1.22;">Reset your dashboard password</h1>
            <p style="margin:0;color:rgba(255,255,255,.88);font-size:15px;line-height:1.7;">Use the verification code below to securely update your customer account password.</p>
          </div>
          <div style="padding:32px 30px;">
            <p style="margin:0 0 18px;color:#475569;font-size:15px;line-height:1.7;">This code is valid for <strong style="color:#0f172a;">15 minutes</strong>. Enter it on the Vani login page along with your new password.</p>
            <div style="margin:24px 0;padding:22px;border-radius:18px;background:linear-gradient(135deg,#eef2ff,#fdf2f8);border:1px solid #c7d2fe;text-align:center;">
              <div style="font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#6366f1;margin-bottom:8px;">Verification Code</div>
              <div style="font-size:38px;line-height:1;font-weight:900;letter-spacing:10px;color:#4f46e5;">' . $safeCode . '</div>
            </div>
            <div style="padding:16px;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0;color:#64748b;font-size:13px;line-height:1.7;">
              <strong style="display:block;color:#0f172a;margin-bottom:4px;">Security note</strong>
              If you did not request this password reset, ignore this email. Your password will not change unless this code is verified.
            </div>
            <p style="margin:22px 0 0;color:#94a3b8;font-size:12px;line-height:1.6;text-align:center;">Vani AI from Codrant<br>https://vani.codrant.com</p>
          </div>
        </div>
      </div>
    </body>
    </html>';

    return sendBrevoEmail($toEmail, "Your Vani password reset code", $html);
}

// ======================================
// FORGOT PASSWORD / RESET WITH CODE
// ======================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['reset_action'])) {
    $showReset = true;
    $resetAction = (string)($_POST['reset_action'] ?? '');

    if ($resetAction === 'request') {
        $resetEmail = strtolower(trim((string)($_POST['reset_email'] ?? '')));
        if (!filter_var($resetEmail, FILTER_VALIDATE_EMAIL)) {
            $resetError = "Enter a valid email address.";
        } else {
            $res = supabase(
                "GET",
                "customers?email=eq." . urlencode($resetEmail) . "&limit=1"
            );
            $user = $res['data'][0] ?? null;
            if ($user) {
                $code = (string)random_int(100000, 999999);
                $_SESSION['password_reset'] = [
                    'email' => $resetEmail,
                    'code_hash' => password_hash($code, PASSWORD_DEFAULT),
                    'expires_at' => time() + 15 * 60,
                    'attempts' => 0
                ];
                if (!sendPasswordResetCodeEmail($resetEmail, $code)) {
                    $resetError = "Reset email could not be sent. Please try again.";
                } else {
                    $resetMessage = "Verification code sent to your email.";
                }
            } else {
                unset($_SESSION['password_reset']);
                $resetMessage = "If this email exists, a verification code has been sent.";
            }
        }
    }

    if ($resetAction === 'confirm') {
        $resetState = $_SESSION['password_reset'] ?? [];
        $code = trim((string)($_POST['reset_code'] ?? ''));
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (empty($resetState['email']) || empty($resetState['code_hash']) || (int)($resetState['expires_at'] ?? 0) < time()) {
            unset($_SESSION['password_reset']);
            $resetError = "Verification code expired. Please request a new code.";
        } elseif ((int)($resetState['attempts'] ?? 0) >= 5) {
            unset($_SESSION['password_reset']);
            $resetError = "Too many invalid attempts. Please request a new code.";
        } elseif (!password_verify($code, (string)$resetState['code_hash'])) {
            $_SESSION['password_reset']['attempts'] = (int)($resetState['attempts'] ?? 0) + 1;
            $resetError = "Invalid verification code.";
        } elseif (strlen($newPassword) < 8) {
            $resetError = "Password must be at least 8 characters.";
        } elseif ($newPassword !== $confirmPassword) {
            $resetError = "Passwords do not match.";
        } else {
            $passwordRes = login_customer_password_update((string)$resetState['email'], password_hash($newPassword, PASSWORD_DEFAULT));
            if ($passwordRes['status'] >= 200 && $passwordRes['status'] < 300) {
                unset($_SESSION['password_reset']);
                if (is_authenticated_user() && authenticated_email() === (string)$resetState['email']) {
                    $_SESSION['must_reset_password'] = false;
                }
                $showReset = false;
                $resetMessage = "Password reset successful. You can log in now.";
            } else {
                $resetError = "Password could not be reset. Please try again.";
            }
        }
    }
}

// ======================================
// NORMAL LOGIN
// ======================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_POST['reset_action'])) {

    $email = trim(
        $_POST['email']
        ?? ''
    );

    $password = trim(
        $_POST['password']
        ?? ''
    );

    if (!$email || !$password) {

        $error =
            "Please fill all fields.";

    } else {

        $res = supabase(
            "GET",
            "customers?email=eq."
            . urlencode($email)
            . "&limit=1"
        );

        $user =
            $res['data'][0]
            ?? null;

        if (!$user) {

            $error =
                "Invalid email or password.";

        } else {

            if (
                password_verify(
                    $password,
                    $user['password']
                )
            ) {

                set_authenticated_user(
                    $user,
                    "password"
                );

                login_redirect_after_success((string)$user['email']);

            } else {

                $error =
                    "Invalid email or password.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
name="viewport"
content="width=device-width, initial-scale=1.0"
>

<title>Login - Vani AI</title>

<link
href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap"
rel="stylesheet"
>

<script
src="https://accounts.google.com/gsi/client"
async
defer
></script>

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
    radial-gradient(circle at top left,rgba(99,102,241,.35),transparent 34%),
    radial-gradient(circle at 88% 8%,rgba(236,72,153,.24),transparent 30%),
    linear-gradient(135deg,#020617 0%,#08111f 48%,#111827 100%);

  display:flex;
  align-items:center;
  justify-content:center;

  padding:20px;

  overflow-x:hidden;

  position:relative;
}

.bg-circle{
  position:absolute;
  border-radius:50%;
  filter:blur(100px);
  opacity:0.3;
  z-index:1;
}

.bg1{
  width:320px;
  height:320px;
  background:#8b5cf6;
  top:-100px;
  left:-100px;
}

.bg2{
  width:360px;
  height:360px;
  background:#ec4899;
  bottom:-120px;
  right:-120px;
}

.container{
  width:100%;
  max-width:430px;
  position:relative;
  z-index:2;
}

.card{

  background:
    linear-gradient(145deg,rgba(15,23,42,.9),rgba(30,41,59,.72));

  backdrop-filter:blur(20px);

  border:
    1px solid rgba(129,140,248,.24);

  border-radius:28px;

  padding:42px 34px;

  box-shadow:
    0 24px 72px rgba(0,0,0,.36),
    inset 0 1px 0 rgba(255,255,255,.05);
}

.logo{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:12px;
  position:relative;
  margin-bottom:18px;
  text-decoration:none;
  color:#f8fafc;
  font-size:24px;
  font-weight:800;
  letter-spacing:0;
  padding:7px 10px 9px 6px;
  border-radius:16px;
  background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(236,72,153,.1));
  border:1px solid rgba(129,140,248,.18);
  width:max-content;
  margin-left:auto;
  margin-right:auto;
}

.logo img{
  width:58px;
  height:58px;
  object-fit:contain;
  filter:drop-shadow(0 0 18px rgba(99,102,241,.7)) drop-shadow(0 0 24px rgba(236,72,153,.28));
}

.logo span{
  background:linear-gradient(90deg,#ffffff,#c4b5fd 48%,#f9a8d4);
  -webkit-background-clip:text;
  -webkit-text-fill-color:transparent;
  filter:drop-shadow(0 0 14px rgba(129,140,248,.28));
}

h1{

  text-align:center;

  font-size:32px;

  margin-bottom:10px;

  background:
    linear-gradient(
      90deg,
      #6366f1,
      #ec4899
    );

  -webkit-background-clip:text;
  -webkit-text-fill-color:transparent;
}

.subtitle{

  text-align:center;

  color:#cbd5e1;

  font-size:15px;

  line-height:1.7;

  margin-bottom:30px;
}

.input-group{
  margin-bottom:18px;
}

.input-group label{

  display:block;

  margin-bottom:8px;

  font-size:14px;

  font-weight:600;

  color:#e5e7eb;
}

.input-group input{

  width:100%;

  padding:15px 16px;

  border-radius:14px;

  border:1px solid rgba(148,163,184,.24);

  background:rgba(2,6,23,.52);
  color:#f8fafc;

  outline:none;

  font-size:14px;
}

.input-group input:focus{

  border-color:#a78bfa;

  box-shadow:
    0 0 0 4px
    rgba(167,139,250,.14);
}

.login-btn{

  width:100%;

  padding:15px;

  border:none;

  border-radius:14px;

  background:
    linear-gradient(
      90deg,
      #6366f1,
      #8b5cf6,
      #ec4899
    );

  color:white;

  font-size:15px;

  font-weight:600;

  cursor:pointer;

  margin-top:8px;
}

.link-btn{
  border:0;
  background:transparent;
  color:#c4b5fd;
  font-weight:700;
  cursor:pointer;
  padding:0;
  font-size:14px;
}

.form-help{
  display:flex;
  justify-content:flex-end;
  margin:-6px 0 12px;
}

.reset-panel{
  display:none;
  margin-top:22px;
  padding-top:22px;
  border-top:1px solid rgba(148,163,184,.22);
}

.reset-panel.active{
  display:block;
}

.reset-title{
  color:#f8fafc;
  font-size:18px;
  margin-bottom:8px;
}

.reset-copy{
  color:#cbd5e1;
  font-size:13px;
  line-height:1.6;
  margin-bottom:16px;
}

.google-wrapper{

  width:100%;

  display:flex;

  justify-content:center;

  margin-bottom:22px;
}

.footer{

  margin-top:26px;

  text-align:center;

  color:#cbd5e1;

  font-size:14px;
}

.footer a{

  color:#c4b5fd;

  text-decoration:none;

  font-weight:600;
}

.error{

  background:rgba(239,68,68,.12);

  color:#fecaca;
  border:1px solid rgba(239,68,68,.3);

  padding:14px;

  border-radius:12px;

  margin-bottom:20px;

  font-size:14px;
}

.success{
  background:rgba(34,197,94,.12);
  color:#bbf7d0;
  border:1px solid rgba(34,197,94,.28);
  padding:14px;
  border-radius:12px;
  margin-bottom:20px;
  font-size:14px;
}

.page-actions{
  position:fixed;
  top:18px;
  right:18px;
  display:flex;
  align-items:center;
  gap:10px;
  z-index:50;
}

.home-link{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:42px;
  padding:0 15px;
  border-radius:12px;
  background:rgba(15,23,42,.72);
  border:1px solid rgba(129,140,248,.24);
  color:#e5e7eb;
  text-decoration:none;
  font-weight:700;
  box-shadow:0 12px 28px rgba(15,23,42,.08);
}

.page-actions .site-menu-trigger{
  background:rgba(15,23,42,.72);
  color:#e5e7eb;
  border-color:rgba(129,140,248,.24);
}

@media(max-width:900px){
  .home-link{
    display:none;
  }

  .page-actions{
    top:14px;
    right:14px;
  }
}

</style>

</head>

<body>

<div class="page-actions">
  <a class="home-link" href="index.php">Home</a>
  <button class="site-menu-trigger" type="button" aria-label="Open menu" aria-expanded="false">
    <span></span>
    <span></span>
    <span></span>
  </button>
</div>

<?php include_once __DIR__ . '/site-menu.php'; ?>

<div class="bg-circle bg1"></div>
<div class="bg-circle bg2"></div>

<div class="container">

<div class="card">

<a class="logo" href="index.php" aria-label="Vani AI home">

<img
src="images/logo_img.png"
alt="Vani AI"
>
<span>Vani AI</span>

</a>

<h1>Welcome Back</h1>

<p class="subtitle">
Login to manage your AI chatbot dashboard
</p>

<!-- GOOGLE LOGIN -->

<div class="google-wrapper">

<div
id="g_id_onload"

data-client_id="<?php echo htmlspecialchars($googleClientId, ENT_QUOTES, 'UTF-8'); ?>"

data-callback="handleCredentialResponse"
data-auto_prompt="true"
data-auto_select="true"
data-context="signin"
data-ux_mode="popup"
data-itp_support="true"
></div>

<div
class="g_id_signin"

data-type="standard"

data-size="large"

data-theme="outline"

data-text="continue_with"

data-shape="pill"

data-logo_alignment="center"

data-width="300"
></div>

</div>

<?php if($error): ?>

<div class="error">
<?php echo htmlspecialchars($error); ?>
</div>

<?php endif; ?>

<?php if($resetMessage): ?>

<div class="success">
<?php echo htmlspecialchars($resetMessage); ?>
</div>

<?php endif; ?>

<form method="POST">

<div class="input-group">

<label>Email Address</label>

<input
type="email"
name="email"
placeholder="Enter your email"
autocomplete="email"
required
>

</div>

<div class="input-group">

<label>Password</label>

<input
type="password"
name="password"
placeholder="Enter your password"
autocomplete="current-password"
required
>

</div>

<div class="form-help">
  <button class="link-btn" type="button" id="showResetBtn">Forgot password?</button>
</div>

<button
type="submit"
class="login-btn"
>
Login →
</button>

</form>

<div class="reset-panel <?php echo $showReset ? 'active' : ''; ?>" id="resetPanel">
  <h2 class="reset-title">Reset Password</h2>
  <p class="reset-copy">Enter your account email. We will send a verification code before changing the password.</p>

  <?php if($resetError): ?>
    <div class="error"><?php echo htmlspecialchars($resetError); ?></div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="reset_action" value="request">
    <div class="input-group">
      <label>Email Address</label>
      <input type="email" name="reset_email" placeholder="Enter your account email" autocomplete="email" required>
    </div>
    <button type="submit" class="login-btn">Send verification code</button>
  </form>

  <form method="POST" style="margin-top:18px">
    <input type="hidden" name="reset_action" value="confirm">
    <div class="input-group">
      <label>Verification Code</label>
      <input type="text" name="reset_code" placeholder="6-digit code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required>
    </div>
    <div class="input-group">
      <label>New Password</label>
      <input type="password" name="new_password" placeholder="Minimum 8 characters" autocomplete="new-password" required>
    </div>
    <div class="input-group">
      <label>Confirm Password</label>
      <input type="password" name="confirm_password" placeholder="Repeat new password" autocomplete="new-password" required>
    </div>
    <button type="submit" class="login-btn">Verify & Reset Password</button>
  </form>
</div>

<div class="footer">

Don't have an account?

<a href="index.php">
Get Started
</a>

</div>

</div>
</div>

<script>

function parseJwt(token) {

    try {

        return JSON.parse(
            atob(
                token.split('.')[1]
            )
        );

    } catch(e) {

        return null;
    }
}

document.getElementById("showResetBtn")?.addEventListener("click", () => {
    const panel = document.getElementById("resetPanel");
    if (!panel) return;
    panel.classList.toggle("active");
    if (panel.classList.contains("active")) {
        panel.scrollIntoView({behavior: "smooth", block: "nearest"});
        panel.querySelector("input[name='reset_email']")?.focus();
    }
});

// ======================================
// GOOGLE LOGIN SUCCESS
// ======================================

function handleCredentialResponse(
    response
) {

    const data =
        parseJwt(response.credential);

    if (!data || !data.email) {

        alert(
            "Google login failed"
        );

        return;
    }

    fetch("google-auth.php", {

        method: "POST",

        headers: {

            "Content-Type":
            "application/json"
        },

        body: JSON.stringify({
            credential: response.credential
        })
    })

    .then(async (res) => {

        const text =
            await res.text();

        try {

            return JSON.parse(text);

        } catch (e) {

            console.error(
                "INVALID JSON:",
                text
            );

            throw e;
        }
    })

    .then(data => {

        if (data.success) {

            if (data.first_login) {

                window.location.href =
                    "index.php";

            } else {

                window.location.href =
                    "dashboard.php";
            }

        } else {

            alert(
                data.message ||
                "Google login failed"
            );
        }
    })

    .catch(err => {

        console.error(err);

        alert(
            "Something went wrong"
        );
    });
}

</script>

</body>
</html>
