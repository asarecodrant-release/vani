<?php
require_once __DIR__ . '/session-auth.php';

// ======================================
// CLEAR OLD SETUP CHATBOT ID
// ======================================
clear_setup_session();

// ======================================
// SAFE SESSION VALUES
// ======================================
$loggedInEmail = authenticated_email();
$loggedInCustomerId = '';
if (!empty($_SESSION['must_reset_password'])) {
    header("Location: forgot-password.php?forced=1");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/logo_img.png">
<title>Free Chatbot Signup</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  font-family: 'Inter', sans-serif;
}

body {
  min-height: 100vh;
  background: linear-gradient(135deg, #667eea, #764ba2);
  overflow-x: hidden;
}

.page-wrapper{
  display:flex;
  justify-content:center;
  align-items:flex-start;
  padding:40px 20px;
  min-height:calc(100vh - 70px);
}

.container {
  width: 100%;
  max-width: 420px;
}

.card {
  width: 100%;
  background: rgba(255, 255, 255, 0.1);
  backdrop-filter: blur(16px);
  border-radius: 16px;
  padding: 30px;
  color: white;
  box-shadow: 0 10px 40px rgba(0,0,0,0.2);
  margin-top: 10px;
}

.card h2 {
  text-align: center;
  margin-bottom: 20px;
  font-weight: 600;
}

.user-info{
  background:rgba(255,255,255,0.12);
  border:1px solid rgba(255,255,255,0.15);
  padding:14px;
  border-radius:12px;
  margin-bottom:20px;
}

.user-info p{
  font-size:13px;
  margin-bottom:6px;
  word-break:break-word;
}

.user-info span{
  font-weight:600;
}

input, select {
  width: 100%;
  padding: 12px;
  margin: 10px 0;
  border-radius: 8px;
  border: none;
  outline: none;
  font-size: 14px;
  background: rgba(255,255,255,0.9);
}
.otp-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center}
.otp-row input{margin:10px 0}
.otp-row button{width:auto;margin:10px 0;padding:12px 14px;white-space:nowrap;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.24)}

input:focus, select:focus {
  box-shadow: 0 0 0 2px #4f6aff;
}

button {
  width: 100%;
  padding: 14px;
  margin-top: 10px;
  border-radius: 8px;
  border: none;
  background: #4f6aff;
  color: white;
  font-weight: 600;
  font-size: 15px;
  cursor: pointer;
}

button:hover {
  background: #3d55e0;
}

.small {
  text-align: center;
  margin-top: 15px;
  font-size: 12px;
  opacity: 0.8;
}

/* HIDDEN CUSTOMER ID FIELD (FIX) */
.hidden {
  display: none;
}

/* LOADING OVERLAY */
#loadingOverlay{
  display:none;
  position:fixed;
  top:0;
  left:0;
  width:100%;
  height:100%;
  background:rgba(0,0,0,0.6);
  backdrop-filter: blur(5px);
  z-index:9999;
  justify-content:center;
  align-items:center;
  flex-direction:column;
  color:white;
  font-size:16px;
}

.spinner{
  width:45px;
  height:45px;
  border:4px solid rgba(255,255,255,0.3);
  border-top:4px solid white;
  border-radius:50%;
  animation:spin 1s linear infinite;
  margin-bottom:15px;
}

@keyframes spin{
  0%{transform:rotate(0deg);}
  100%{transform:rotate(360deg);}
}
  
</style>
<link rel="stylesheet" href="css/setup-theme.css">
</head>

<body>

<?php include 'navbar.php'; ?>
<button type="button" class="setup-theme-toggle" id="setupThemeToggle">
  <span class="setup-theme-swatch" aria-hidden="true"></span>
  <span data-theme-label>Dark theme</span>
</button>
<div class="page-wrapper">
<div class="container">
<div class="card">

<h2>🚀 Create Your Chatbot</h2>

<form id="signupForm">

  <input 
    type="text" 
    id="websiteName" 
    placeholder="Enter your website domain (e.g. example.com)"
    inputmode="url"
    autocomplete="url"
    required
  >

  <input 
    type="email" 
    id="email"
    value="<?php echo htmlspecialchars($loggedInEmail); ?>"
    placeholder="📧 Enter your email address"
    <?php echo $loggedInEmail ? 'readonly' : ''; ?>
    required
  >

  <!-- ✅ FIX: HIDDEN CUSTOMER ID (still used in JS/backend) -->
  <div class="otp-row" id="setupOtpRow" style="display:none;">
    <input type="text" id="emailOtp" placeholder="Enter 6-digit email OTP" inputmode="numeric" maxlength="6">
    <button type="button" id="sendSetupOtpBtn">Resend OTP</button>
  </div>
  <div class="small" id="setupOtpStatus" style="display:none; margin-top:8px;"></div>

  <input type="hidden" id="customerId">

  <select id="businessType" required>
    <option value="">🏢 Select Business Type</option>
    <option>E-commerce</option>
    <option>SaaS / Software</option>
    <option>Agency</option>
    <option>Freelancer</option>
    <option>Restaurant / Cafe</option>
    <option>Hotel / Hospitality</option>
    <option>Travel / Tourism</option>
    <option>Healthcare / Clinic</option>
    <option>Fitness / Gym</option>
    <option>Education / Coaching</option>
    <option>Real Estate</option>
    <option>Finance / Insurance</option>
    <option>Legal Services</option>
    <option>Salon / Beauty</option>
    <option>Automobile</option>
    <option>Local Business</option>
    <option>Tech Startup</option>
    <option>Non-profit</option>
    <option value="Other">Other</option>
  </select>

  <input 
    type="text" 
    id="otherBusinessType" 
    placeholder="✏️ Specify your business type"
    style="display:none;"
  >

  <button type="submit" id="submitBtn">Continue →</button>

</form>

<div class="small">
  Free forever • No credit card required
</div>

</div>
</div>
</div>

<!-- LOADING OVERLAY -->
<div id="loadingOverlay">
  <div class="spinner"></div>
  Creating your account...
  <div style="font-size:12px; margin-top:5px; opacity:0.8;">
    Preparing theme selection
  </div>
</div>

<script>

const API = "/api.php";
let setupOtpSentForEmail = "";
const isLoggedInCustomer = <?php echo $loggedInEmail ? 'true' : 'false'; ?>;

function normalizeWebsiteDomain(value) {
  let input = value.trim().toLowerCase();
  if (!input) {
    return "";
  }
  if (!/^https?:\/\//i.test(input)) {
    input = `https://${input}`;
  }
  try {
    const url = new URL(input);
    const host = url.hostname.replace(/^www\./i, "").replace(/\.$/, "");
    const labels = host.split(".");
    const validLabels = labels.every((label) =>
      label.length > 0 &&
      label.length <= 63 &&
      /^[a-z0-9-]+$/i.test(label) &&
      !label.startsWith("-") &&
      !label.endsWith("-")
    );
    if (
      host.length > 253 ||
      labels.length < 2 ||
      !/^[a-z]{2,63}$/i.test(labels[labels.length - 1]) ||
      !validLabels
    ) {
      return "";
    }
    return host;
  } catch (err) {
    return "";
  }
}

document.addEventListener("DOMContentLoaded", () => {

  const businessType = document.getElementById("businessType");
  const otherInput = document.getElementById("otherBusinessType");

  businessType.addEventListener("change", () => {
    otherInput.style.display =
      businessType.value === "Other" ? "block" : "none";
  });

  // generate CID
  localStorage.removeItem("cid");

  let generatedCID = localStorage.getItem("cid");

  if (!generatedCID) {
    generatedCID = crypto.randomUUID();
    localStorage.setItem("cid", generatedCID);
  }

  document.getElementById("customerId").value = generatedCID;

});

async function sendSetupOtpForEmail(email) {
  const emailInput = document.getElementById("email");
  const otpRow = document.getElementById("setupOtpRow");
  const otpStatus = document.getElementById("setupOtpStatus");
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    alert("Enter a valid email before sending OTP");
    emailInput.focus();
    return false;
  }
  const button = document.getElementById("sendSetupOtpBtn");
  const originalText = button.innerText;
  button.disabled = true;
  button.innerText = "Sending...";
  try {
    const res = await fetch(`${API}?action=send_email_otp`, {
      method: "POST",
      headers: {"Content-Type":"application/json"},
      body: JSON.stringify({email, flow: "freebot_signup"})
    });
    const data = await res.json();
    if (data.requires_login) {
      alert(data.message || "This email already has an account. Please login to continue.");
      window.location.href = data.login_url || "login.php?setup=incomplete";
      return false;
    }
    if (!data.success) {
      alert(data.message || "OTP could not be sent");
      return false;
    }
    setupOtpSentForEmail = email;
    otpRow.style.display = "flex";
    document.getElementById("emailOtp").required = true;
    if (otpStatus) {
      otpStatus.style.display = "block";
      otpStatus.innerText = data.message || "Verification code sent to your email. Enter it below to continue.";
    }
    document.getElementById("emailOtp").focus();
    return true;
  } catch (err) {
    alert("OTP could not be sent");
    return false;
  } finally {
    button.disabled = false;
    button.innerText = originalText;
  }
}

document.getElementById("email")?.addEventListener("input", () => {
  setupOtpSentForEmail = "";
  document.getElementById("emailOtp").value = "";
  document.getElementById("emailOtp").required = false;
  document.getElementById("setupOtpRow").style.display = "none";
  document.getElementById("setupOtpStatus").style.display = "none";
});

document.getElementById("sendSetupOtpBtn")?.addEventListener("click", async () => {
  await sendSetupOtpForEmail(document.getElementById("email").value.trim());
});

document.getElementById("signupForm")
.addEventListener("submit", async (e) => {

  e.preventDefault();

  const overlay = document.getElementById("loadingOverlay");
  const btn = document.getElementById("submitBtn");

  overlay.style.display = "flex";
  btn.disabled = true;
  btn.innerText = "Processing...";

  const websiteInput = document.getElementById("websiteName");
  const website = normalizeWebsiteDomain(websiteInput.value);

  const email =
    document.getElementById("email").value.trim();
  let emailOtp = document.getElementById("emailOtp").value.trim();

  const cid =
    document.getElementById("customerId").value.trim();

  let business =
    document.getElementById("businessType").value;

  if (business === "Other") {
    business = document.getElementById("otherBusinessType").value.trim();
  }

  if (!business) {
    overlay.style.display = "none";
    btn.disabled = false;
    btn.innerText = "Continue â†’";
    alert("Please select or enter your business type");
    return;
  }

  if (!website) {
    overlay.style.display = "none";
    btn.disabled = false;
    btn.innerText = "Continue ->";
    websiteInput.focus();
    alert("Please enter a valid website domain, like example.com or example.co.in.");
    return;
  }

  websiteInput.value = website;

  if (!isLoggedInCustomer && setupOtpSentForEmail !== email) {
    const sent = await sendSetupOtpForEmail(email);
    overlay.style.display = "none";
    btn.disabled = false;
    btn.innerText = "Verify & Continue";
    if (sent) {
      alert("We sent a verification code to your email. Enter the OTP and click Verify & Continue.");
    }
    return;
  }

  if (!isLoggedInCustomer && !/^\d{6}$/.test(emailOtp)) {
    overlay.style.display = "none";
    btn.disabled = false;
    btn.innerText = "Verify & Continue";
    alert("Please enter the 6-digit email OTP");
    document.getElementById("emailOtp").focus();
    return;
  }

  if (isLoggedInCustomer) {
    emailOtp = "";
  }

  try {

    const signupRes = await fetch(`${API}?action=signup`, {
      method: "POST",
      headers: {"Content-Type":"application/json"},
      body: JSON.stringify({
        customer_id: cid,
        email: email,
        email_otp: emailOtp,
        website_name: website,
        business_type: business
      })
    });
    const signupData = await signupRes.json();
    if (signupData.error) {
      overlay.style.display = "none";
      btn.disabled = false;
      btn.innerText = "Continue ->";
      if (signupData.requires_login) {
        alert(signupData.message || "Please login to continue chatbot setup.");
        window.location.href = signupData.login_url || "login.php?setup=incomplete";
        return;
      }
      alert(signupData.message || signupData.error);
      return;
    }

    const accRes = await fetch(`${API}?action=create_account`, {
      method: "POST",
      headers: {"Content-Type":"application/json"},
      body: JSON.stringify({
        customer_id: cid,
        email: email,
        website_name: website,
        business_type: business
      })
    });

    const accData = await accRes.json();

    if (!accData.success || accData.email_sent === false) {
      overlay.style.display = "none";
      btn.disabled = false;
      btn.innerText = "Continue →";
      alert(accData.message || "Account created, but email could not be sent. Please try again.");
      return;
    }

    localStorage.setItem("business_type", business);
    localStorage.setItem("website_name", website);
    localStorage.setItem("email", email);

    overlay.innerText = "Redirecting to theme selection...";
    window.location.href = "theme-selection.php";

  } catch (err) {
    console.error(err);
    overlay.style.display = "none";
    btn.disabled = false;
    btn.innerText = "Continue →";
    alert("Something went wrong");
  }

});

</script>
<script src="setup-theme.js"></script>
</body>
</html>
