<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

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

    try {

        $email = trim(
            $_POST['google_email']
            ?? ''
        );

        if (!$email) {

            echo json_encode([
                "success" => false,
                "message" => "Missing email"
            ]);

            exit;
        }

        // ======================================
        // CHECK EXISTING USER
        // ======================================

        $res = supabase(
            "GET",
            "customers?email=eq."
            . urlencode($email)
            . "&limit=1"
        );

        $user =
            $res['data'][0]
            ?? null;

        // ======================================
        // NEW USER
        // ======================================

        if (!$user) {

        $randomPassword =
            bin2hex(random_bytes(8));

        $insert = supabase(
            "POST",
            "customers",
            [[
                "email" => $email,

                "password" =>
                    password_hash(
                        $randomPassword,
                        PASSWORD_DEFAULT
                    )
            ]]
        );

            $_SESSION['email'] = $email;

            echo json_encode([

                "success" => true,

                "first_login" => true

            ]);

            exit;
        }

        // ======================================
        // EXISTING USER
        // ======================================

        $_SESSION['customer_id'] =
            $user['id'];

        $_SESSION['email'] =
            $user['email'];

        echo json_encode([

            "success" => true,

            "first_login" => false,

            "customer_id" =>
                $user['id']
        ]);

        exit;

    } catch (Exception $e) {

        echo json_encode([

            "success" => false,

            "message" =>
                $e->getMessage()
        ]);

        exit;
    }
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

                $_SESSION['customer_id'] =
                    $user['id'];

                $_SESSION['email'] =
                    $user['email'];

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
    linear-gradient(
      135deg,
      #eef2ff,
      #f5f3ff,
      #fdf2f8
    );

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
  filter:blur(90px);
  opacity:0.45;
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
    rgba(255,255,255,0.78);

  backdrop-filter:blur(20px);

  border:
    1px solid rgba(255,255,255,0.5);

  border-radius:28px;

  padding:42px 34px;

  box-shadow:
    0 12px 45px rgba(0,0,0,0.08);
}

.logo{
  text-align:center;
  margin-bottom:18px;
}

.logo img{
  height:68px;
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

  color:#64748b;

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

  color:#334155;
}

.input-group input{

  width:100%;

  padding:15px 16px;

  border-radius:14px;

  border:1px solid #e2e8f0;

  background:white;

  outline:none;

  font-size:14px;
}

.input-group input:focus{

  border-color:#6366f1;

  box-shadow:
    0 0 0 4px
    rgba(99,102,241,0.12);
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

  color:#64748b;

  font-size:14px;
}

.footer a{

  color:#6366f1;

  text-decoration:none;

  font-weight:600;
}

.error{

  background:#fee2e2;

  color:#b91c1c;

  padding:14px;

  border-radius:12px;

  margin-bottom:20px;

  font-size:14px;
}

</style>

</head>

<body>

<div class="bg-circle bg1"></div>
<div class="bg-circle bg2"></div>

<div class="container">

<div class="card">

<div class="logo">

<img
src="images/logo.png"
alt="Vani AI"
>

</div>

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

<a href="signup.php">
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

    fetch("login.php", {

        method: "POST",

        headers: {

            "Content-Type":
            "application/x-www-form-urlencoded"
        },

        body:
            "google_email=" +
            encodeURIComponent(
                data.email
            )
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