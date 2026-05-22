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

$error = "";

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

// ======================================
// NORMAL LOGIN
// ======================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {

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

                header(
                    "Location: dashboard.php"
                );

                exit;

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
<span>VANI AI</span>

</a>

<h1>Welcome Back</h1>

<p class="subtitle">
Login to manage your AI chatbot dashboard
</p>

<!-- GOOGLE LOGIN -->

<div class="google-wrapper">

<div
id="g_id_onload"

data-client_id="970273381861-ar6734p4c2hl3pn0g58segkgccfvoirv.apps.googleusercontent.com"

data-callback="handleCredentialResponse"
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

<button
type="submit"
class="login-btn"
>
Login →
</button>

</form>

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

        console.log(
            "RAW RESPONSE:",
            text
        );

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

        console.log(
            "GOOGLE LOGIN:",
            data
        );

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
