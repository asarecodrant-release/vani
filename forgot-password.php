<?php
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/email.php';

$message = '';
$error = '';
$showConfirm = !empty($_SESSION['password_reset']['email']);
$forced = !empty($_SESSION['must_reset_password']) || (string)($_GET['forced'] ?? '') === '1';

function fp_safe_rows(array $response): array {
    $data = $response['data'] ?? null;
    return is_array($data) ? $data : [];
}

function fp_customer_password_update(string $email, string $passwordHash): array {
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

function fp_reset_email_html(string $code): string {
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html><body style="margin:0;background:#eef2ff;font-family:Inter,Arial,sans-serif;color:#0f172a;">'
        . '<div style="padding:30px 14px;background:radial-gradient(circle at top left,rgba(99,102,241,.28),transparent 34%),radial-gradient(circle at 90% 0,rgba(236,72,153,.22),transparent 32%),linear-gradient(135deg,#f8fafc,#eef2ff,#faf5ff);">'
        . '<div style="max-width:620px;margin:0 auto;background:#ffffff;border:1px solid rgba(148,163,184,.24);border-radius:24px;overflow:hidden;box-shadow:0 24px 70px rgba(15,23,42,.13);">'
        . '<div style="padding:34px 30px;background:linear-gradient(135deg,#6366f1,#8b5cf6,#ec4899);color:#ffffff;text-align:center;">'
        . '<img src="https://vani.codrant.com/images/logo_img.png" alt="Vani AI" style="width:70px;height:70px;object-fit:contain;margin-bottom:12px;">'
        . '<div style="display:inline-block;padding:7px 11px;border-radius:999px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.24);font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">Secure Password Reset</div>'
        . '<h1 style="margin:16px 0 8px;font-size:30px;line-height:1.22;">Reset your Vani AI password</h1>'
        . '<p style="margin:0;color:rgba(255,255,255,.9);font-size:15px;line-height:1.7;">Use this verification code on the Vani AI reset page. The code is valid for 15 minutes.</p>'
        . '</div><div style="padding:32px 30px;">'
        . '<div style="margin:0 0 22px;padding:18px;border-radius:16px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;line-height:1.7;">For your safety, Vani AI will never ask for your old password. Only enter this code on the official Vani AI website.</div>'
        . '<div style="margin:24px 0;padding:24px;border-radius:18px;background:linear-gradient(135deg,#eef2ff,#fdf2f8);border:1px solid #c7d2fe;text-align:center;">'
        . '<div style="font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#6366f1;margin-bottom:10px;">Verification Code</div>'
        . '<div style="font-size:40px;line-height:1;font-weight:900;letter-spacing:10px;color:#4f46e5;">' . $safeCode . '</div>'
        . '</div>'
        . '<p style="margin:18px 0 0;color:#64748b;font-size:13px;line-height:1.7;">If you did not request this password reset, ignore this email. Your password will not change unless this code is verified.</p>'
        . '<p style="margin:24px 0 0;color:#94a3b8;font-size:12px;line-height:1.6;text-align:center;">Vani AI by Codrant<br>https://vani.codrant.com</p>'
        . '</div></div></div></body></html>';
}

function fp_send_reset_code(string $email): bool {
    $code = (string)random_int(100000, 999999);
    $_SESSION['password_reset'] = [
        'email' => $email,
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires_at' => time() + 15 * 60,
        'attempts' => 0
    ];
    return sendBrevoEmail($email, 'Reset your Vani AI password', fp_reset_email_html($code));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['reset_action'] ?? '');

    if ($action === 'request') {
        $resetEmail = strtolower(trim((string)($_POST['reset_email'] ?? '')));
        if (!filter_var($resetEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } else {
            $user = fp_safe_rows(supabase("GET", "customers?email=eq." . urlencode($resetEmail) . "&limit=1"))[0] ?? null;
            if ($user && !fp_send_reset_code($resetEmail)) {
                $error = 'Reset email could not be sent. Please try again.';
            } else {
                if (!$user) {
                    unset($_SESSION['password_reset']);
                }
                $message = 'If this email exists, a verification code has been sent.';
                $showConfirm = (bool)$user;
            }
        }
    }

    if ($action === 'confirm') {
        $resetState = $_SESSION['password_reset'] ?? [];
        $code = trim((string)($_POST['reset_code'] ?? ''));
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (empty($resetState['email']) || empty($resetState['code_hash']) || (int)($resetState['expires_at'] ?? 0) < time()) {
            unset($_SESSION['password_reset']);
            $error = 'Verification code expired. Please request a new code.';
            $showConfirm = false;
        } elseif ((int)($resetState['attempts'] ?? 0) >= 5) {
            unset($_SESSION['password_reset']);
            $error = 'Too many invalid attempts. Please request a new code.';
            $showConfirm = false;
        } elseif (!password_verify($code, (string)$resetState['code_hash'])) {
            $_SESSION['password_reset']['attempts'] = (int)($resetState['attempts'] ?? 0) + 1;
            $error = 'Invalid verification code.';
            $showConfirm = true;
        } elseif (strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters.';
            $showConfirm = true;
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
            $showConfirm = true;
        } else {
            $passwordRes = fp_customer_password_update((string)$resetState['email'], password_hash($newPassword, PASSWORD_DEFAULT));
            if ($passwordRes['status'] >= 200 && $passwordRes['status'] < 300) {
                unset($_SESSION['password_reset']);
                if (is_authenticated_user() && authenticated_email() === (string)$resetState['email']) {
                    $_SESSION['must_reset_password'] = false;
                }
                clear_remembered_device();
                unset(
                    $_SESSION['is_logged_in'],
                    $_SESSION['auth_email'],
                    $_SESSION['auth_user_id'],
                    $_SESSION['auth_provider'],
                    $_SESSION['must_reset_password'],
                    $_SESSION['email']
                );
                header('Location: login.php?password_reset=success');
                exit;
            } else {
                $error = 'Password could not be reset. Please try again.';
                $showConfirm = true;
            }
        }
    }
}

if ($forced && is_authenticated_user() && empty($_SESSION['password_reset']['email'])) {
    $message = 'For security, request a verification code and reset your temporary password before continuing.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/logo_img.png">
<title>Reset Password - Vani AI</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:Inter,sans-serif}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:radial-gradient(circle at top left,rgba(99,102,241,.35),transparent 34%),radial-gradient(circle at 88% 8%,rgba(236,72,153,.24),transparent 30%),linear-gradient(135deg,#020617 0%,#08111f 48%,#111827 100%);color:#e5e7eb}
body.bright{background:radial-gradient(circle at top left,rgba(99,102,241,.16),transparent 34%),radial-gradient(circle at 88% 8%,rgba(236,72,153,.13),transparent 30%),linear-gradient(135deg,#f8fafc 0%,#eef2ff 48%,#fdf2f8 100%);color:#334155}
.shell{width:min(960px,100%);display:grid;grid-template-columns:minmax(0,.95fr) minmax(360px,1fr);gap:22px;align-items:stretch}
.brand-panel,.card{border-radius:26px;border:1px solid rgba(129,140,248,.24);box-shadow:0 24px 72px rgba(0,0,0,.28)}
.brand-panel{padding:34px;background:linear-gradient(135deg,rgba(99,102,241,.9),rgba(139,92,246,.9),rgba(236,72,153,.86));color:#fff;display:flex;flex-direction:column;justify-content:space-between;min-height:520px}
.brand{display:flex;align-items:center;gap:12px;text-decoration:none;color:#fff;font-size:25px;font-weight:900}.brand img{width:64px;height:64px;object-fit:contain}
.brand-panel h1{font-size:42px;line-height:1.12;margin:28px 0 14px}.brand-panel p{color:rgba(255,255,255,.88);line-height:1.75}
.trust-list{display:grid;gap:10px;margin-top:22px}.trust-list span{padding:12px 14px;border-radius:14px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.18);font-size:14px;font-weight:700}
.card{padding:34px;background:linear-gradient(145deg,rgba(15,23,42,.92),rgba(30,41,59,.76));backdrop-filter:blur(20px)}
body.bright .card{background:rgba(255,255,255,.9);border-color:rgba(99,102,241,.16);box-shadow:0 24px 72px rgba(15,23,42,.13)}
.top-actions{position:fixed;top:18px;right:18px;display:flex;gap:10px;z-index:20}.ghost{min-height:42px;padding:0 14px;border-radius:12px;background:rgba(15,23,42,.72);border:1px solid rgba(129,140,248,.24);color:#e5e7eb;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;cursor:pointer}
body.bright .ghost{background:#fff;color:#334155;border-color:rgba(99,102,241,.16)}
.eyebrow{display:inline-flex;padding:8px 11px;border-radius:999px;background:rgba(99,102,241,.14);border:1px solid rgba(129,140,248,.28);color:#c4b5fd;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.08em}
body.bright .eyebrow{color:#4f46e5;background:#eef2ff;border-color:#c7d2fe}
.card h2{font-size:30px;margin:14px 0 8px;color:#f8fafc}body.bright .card h2{color:#111827}.card p{color:#cbd5e1;line-height:1.7;margin-bottom:22px}body.bright .card p{color:#475569}
.input-group{display:grid;gap:8px;margin-bottom:16px}.input-group label{font-size:14px;font-weight:800;color:#e5e7eb}body.bright .input-group label{color:#111827}.input-group input{width:100%;padding:15px 16px;border-radius:14px;border:1px solid rgba(148,163,184,.24);background:rgba(2,6,23,.52);color:#f8fafc;outline:0;font-size:14px}body.bright .input-group input{background:#fff;color:#111827;border-color:#cbd5e1}
.login-btn{width:100%;min-height:50px;border:0;border-radius:14px;background:linear-gradient(90deg,#6366f1,#8b5cf6,#ec4899);color:#fff;font-size:15px;font-weight:900;cursor:pointer}
.message,.error{padding:14px;border-radius:14px;margin-bottom:16px;line-height:1.55;font-size:14px}.message{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.28);color:#bbf7d0}.error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fecaca}body.bright .message{color:#166534;background:#dcfce7;border-color:#bbf7d0}body.bright .error{color:#991b1b;background:#fee2e2;border-color:#fecaca}
.switch-link{display:block;text-align:center;margin-top:18px;color:#c4b5fd;font-weight:800;text-decoration:none}body.bright .switch-link{color:#4f46e5}
@media(max-width:860px){body{align-items:flex-start}.shell{grid-template-columns:1fr}.brand-panel{min-height:auto}.brand-panel h1{font-size:34px}.top-actions{position:static;margin-bottom:16px;justify-content:flex-end}.page{width:100%}}
</style>
</head>
<body>
<script>
try{const t=localStorage.getItem("vani-index-theme")||"bright";document.body.classList.toggle("bright",t!=="dark");document.body.classList.toggle("dark",t==="dark");}catch(e){document.body.classList.add("bright");}
</script>
<div class="page">
  <div class="top-actions">
    <a class="ghost" href="login.php">Login</a>
    <button class="ghost" type="button" id="themeToggle">Dark Mode</button>
  </div>
  <main class="shell">
    <section class="brand-panel">
      <div>
        <a class="brand" href="index.php"><img src="images/logo_img.png" alt="Vani AI"><span>Vani AI</span></a>
        <h1>Securely reset your dashboard password.</h1>
        <p>We verify your email first, then allow a new password. This keeps existing-password and forgotten-password flows protected.</p>
      </div>
    </section>
    <section class="card">
      <span class="eyebrow">Account Security</span>
      <h2><?php echo $showConfirm ? 'Verify code' : 'Reset password'; ?></h2>
      <p><?php echo $showConfirm ? 'Enter the code from your email and set a new password.' : 'Enter your account email. We will send a verification code before changing the password.'; ?></p>
      <?php if ($message): ?><div class="message"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
      <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

      <?php if (!$showConfirm): ?>
      <form method="POST">
        <input type="hidden" name="reset_action" value="request">
        <div class="input-group">
          <label>Email Address</label>
          <input type="email" name="reset_email" value="<?php echo htmlspecialchars(authenticated_email()); ?>" placeholder="Enter your account email" autocomplete="email" required>
        </div>
        <button type="submit" class="login-btn">Send verification code</button>
      </form>
      <?php else: ?>
      <form method="POST">
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
      <form method="POST" style="margin-top:12px">
        <input type="hidden" name="reset_action" value="request">
        <input type="hidden" name="reset_email" value="<?php echo htmlspecialchars((string)($_SESSION['password_reset']['email'] ?? '')); ?>">
        <button type="submit" class="ghost" style="width:100%;justify-content:center">Resend code</button>
      </form>
      <?php endif; ?>
      <a class="switch-link" href="login.php">Back to login</a>
    </section>
  </main>
</div>
<script>
const themeToggle=document.getElementById("themeToggle");
function setTheme(mode){const dark=mode==="dark";document.body.classList.toggle("bright",!dark);document.body.classList.toggle("dark",dark);if(themeToggle){themeToggle.textContent=dark?"Bright Mode":"Dark Mode";themeToggle.setAttribute("aria-pressed",String(dark));}localStorage.setItem("vani-index-theme",dark?"dark":"bright");}
setTheme(localStorage.getItem("vani-index-theme")||"bright");
themeToggle?.addEventListener("click",()=>setTheme(document.body.classList.contains("dark")?"bright":"dark"));
</script>
</body>
</html>
