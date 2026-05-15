<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['setup_completed'] = true;
// ==========================
// KEEP EMAIL BEFORE CLEAR
// ==========================
$email = $_SESSION['email'] ?? null;

// ==========================
// CLEAR ALL SESSION DATA
// ==========================
$_SESSION = [];

// ==========================
// RESTORE ONLY EMAIL
// ==========================
if ($email) {
    $_SESSION['email'] = $email;
}

// ==========================
// OPTIONAL: REMOVE SESSION ID (SAFE RESET)
// ==========================
// NOTE: We do NOT fully destroy session,
// so user stays "logged in" via email

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Setup Complete</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
/* ✅ YOUR CSS 100% SAME */
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}

body{
  min-height:100vh;
  background:linear-gradient(135deg,#667eea,#764ba2);
  display:flex;
  align-items:center;
  justify-content:center;
  padding:20px;
}

.container{width:100%;max-width:650px;}

.hero{text-align:center;color:white;margin-bottom:20px;}
.hero h1{font-size:32px;line-height:1.3;margin-bottom:10px;}
.hero p{font-size:15px;opacity:0.9;}

.card{
  width:100%;
  background:rgba(255,255,255,0.12);
  backdrop-filter:blur(16px);
  border-radius:18px;
  padding:28px;
  color:white;
}

h3{margin-top:14px;margin-bottom:8px;font-size:15px;}

#cidBox{
  background:rgba(255,255,255,0.15);
  padding:14px;
  border-radius:10px;
  font-size:14px;
  word-break:break-word;
}

.preview{
  background:rgba(0,0,0,0.25);
  padding:16px;
  border-radius:10px;
  margin-top:5px;
}

code{
  font-size:13px;
  color:#e5e7eb;
  display:block;
  white-space:pre-wrap;
}

.button{
  width:100%;
  padding:14px;
  margin-top:18px;
  border-radius:10px;
  border:none;
  background:#4f6aff;
  color:white;
  font-weight:600;
  cursor:pointer;
}
</style>
</head>

<body>

<div class="container">

  <div class="hero">
    <h1>🎉 Your Chatbot is Ready!</h1>
    <p>Copy the code below and paste it into your website</p>
  </div>

  <div class="card">

    <h3>Your Chatbot ID</h3>
    <p id="cidBox"></p>

    <h3>Embed Code</h3>

    <div class="preview">
      <code id="codeBox"></code>
    </div>

    <button class="button" onclick="copyCode()">📋 Copy Code</button>

  </div>

</div>

<script>
const cid = localStorage.getItem("cid");

const cidBox = document.getElementById("cidBox");
const codeBox = document.getElementById("codeBox");

const widgetUrl = "https://cdn.jsdelivr.net/gh/codrant-code/chbdd@main/widget36.js";

if (!cid) {
  cidBox.innerText = "Missing customer_id";
} else {
  cidBox.innerText = cid;

  const embedCode = `<script src="${widgetUrl}" data-id="${cid}"><\/script>`;
  codeBox.innerText = embedCode;

  // OPTIONAL: load widget preview
  const script = document.createElement("script");
  script.src = widgetUrl;
  script.setAttribute("data-id", cid);
  document.body.appendChild(script);
}

function copyCode() {
  navigator.clipboard.writeText(codeBox.innerText);
  alert("Copied!");
}
    
</script>

</body>
</html>