<?php
require_once __DIR__ . '/../core.php';

$msg91WidgetId = $_ENV['MSG91_WIDGET_ID'] ?? getenv('MSG91_WIDGET_ID') ?: '';
$msg91TokenAuth = $_ENV['MSG91_TOKEN_AUTH'] ?? getenv('MSG91_TOKEN_AUTH') ?: '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mobile Verification</title>
<style>
*{box-sizing:border-box}
body{
  margin:0;
  min-height:100vh;
  display:grid;
  place-items:center;
  background:#f8fafc;
  color:#0f172a;
  font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
.otp-shell{width:100%;max-width:420px;padding:24px;display:grid;gap:18px}
.brand{display:flex;align-items:center;gap:10px}
.mark{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;color:#fff;font-weight:800;background:linear-gradient(135deg,#6366f1,#ec4899)}
h1{font-size:22px;line-height:1.2;margin:0}
p{margin:0;color:#64748b;line-height:1.55;font-size:14px}
.field{display:grid;gap:8px}
label{font-size:13px;font-weight:700;color:#475569}
input{width:100%;border:1px solid #dbe3ef;border-radius:12px;padding:12px 13px;font:inherit;color:#0f172a;outline:none;background:#fff}
input:focus{border-color:#818cf8;box-shadow:0 0 0 3px rgba(99,102,241,.16)}
.actions{display:flex;gap:10px;flex-wrap:wrap}
button{border:0;border-radius:12px;min-height:42px;padding:0 14px;font:inherit;font-weight:800;cursor:pointer}
.primary{background:linear-gradient(135deg,#6366f1,#ec4899);color:#fff}
.ghost{background:#fff;color:#334155;border:1px solid #dbe3ef}
button:disabled{opacity:.6;cursor:not-allowed}
.notice{padding:12px 13px;border-radius:12px;background:#eef2ff;color:#3730a3;font-size:13px;line-height:1.45}
.error{background:#fee2e2;color:#991b1b}
.success{background:#dcfce7;color:#166534}
#recaptcha-container{min-height:1px}
</style>
</head>
<body>
  <main class="otp-shell">
    <div class="brand">
      <div class="mark">V</div>
      <div>
        <h1>Verify mobile number</h1>
        <p>Secure verification runs on vani.codrant.com.</p>
      </div>
    </div>

    <div class="field">
      <label for="phoneInput">Mobile number</label>
      <input id="phoneInput" type="tel" inputmode="tel" autocomplete="tel" placeholder="+919876543210">
    </div>

    <div class="actions" id="sendActions">
      <button class="primary" id="sendOtpBtn" type="button">Send OTP</button>
      <button class="ghost" id="cancelBtn" type="button">Cancel</button>
    </div>

    <div id="statusBox" class="notice">Enter your mobile number with country code.</div>

    <!-- MSG91 Widget renders its own OTP input here -->
    <div id="captcha-container" style="margin-top: 20px;"></div>
  </main>

<script>
const msg91WidgetId = <?php echo json_encode($msg91WidgetId); ?>;
const msg91TokenAuth = <?php echo json_encode($msg91TokenAuth); ?>;
const params = new URLSearchParams(window.location.search);
const requestId = params.get("request_id") || "";
const requestedParentOrigin = params.get("parent_origin") || "";
const parentOrigin = /^https?:\/\/[^/]+$/i.test(requestedParentOrigin) ? requestedParentOrigin : "*";
const initialPhone = (params.get("phone") || "").trim();

const phoneInput = document.getElementById("phoneInput");
const sendOtpBtn = document.getElementById("sendOtpBtn");
const cancelBtn = document.getElementById("cancelBtn");
const statusBox = document.getElementById("statusBox");

let msg91Ready = false;
let currentReqId = null;

phoneInput.value = initialPhone;

if (!msg91WidgetId || !msg91TokenAuth) {
  sendOtpBtn.disabled = true;
  setStatus("MSG91 verification is not configured. Please contact support.", "error");
}

function validPhone(value) {
  return /^\+?[1-9][0-9]{7,14}$/.test(value);
}

function cleanPhone(value) {
  return value.trim().replace(/[^\d+]/g, "").replace(/(?!^)\+/g, "");
}

function setStatus(message, type = "notice") {
  statusBox.textContent = message;
  statusBox.className = "notice" + (type === "error" ? " error" : type === "success" ? " success" : "");
}

function post(status, extra = {}) {
  window.parent?.postMessage({
    source: "vani-mobile-otp",
    request_id: requestId,
    status,
    ...extra
  }, parentOrigin);
}

function withTimeout(promise, milliseconds, message) {
  let timer;
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(message)), milliseconds);
  });
  return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

function extractReqId(data) {
  return data?.reqId || data?.req_id || data?.request_id || data?.message || data?.data?.reqId || null;
}

function extractAccessToken(data) {
  return data?.access_token || data?.accessToken || data?.token || data?.jwt || data?.message?.access_token || data?.data?.access_token || null;
}

function initMsg91Provider() {
  if (typeof window.initSendOTP !== "function") {
    console.error("initSendOTP not available");
    setStatus("MSG91 OTP provider could not load.", "error");
    return;
  }
  
  try {
    console.log("Initializing MSG91 with config...");
    window.initSendOTP(window.configuration);
    console.log("MSG91 initSendOTP called successfully");
    
    // Widget renders its own UI and only exposes verifyOtp
    // We need to call the widget's render method or trigger via configuration
    let attempts = 0;
    const checkReady = setInterval(() => {
      attempts++;
      
      // The widget only needs verifyOtp - that's what gets exposed
      const verifyOtpReady = typeof window.verifyOtp === "function";
      
      if (attempts % 20 === 0) {
        console.log(`Attempt ${attempts}: verifyOtp=${typeof window.verifyOtp}`);
      }
      
      if (verifyOtpReady) {
        msg91Ready = true;
        clearInterval(checkReady);
        console.log("✅ MSG91 widget ready!");
        console.log("Widget renders its own UI - look for input form on page");
        setStatus("Widget is ready. Waiting for OTP input...", "notice");
      } else if (attempts > 200) {
        clearInterval(checkReady);
        console.error("❌ MSG91 widget not ready after 20 seconds");
        setStatus("MSG91 widget failed to initialize.", "error");
      }
    }, 100);
  } catch (error) {
    console.error("Error initializing MSG91:", error);
    setStatus("Failed to initialize MSG91 OTP provider.", "error");
  }
}

function handleVerifiedData(data) {
  console.log("Verified data received:", data);
  const accessToken = extractAccessToken(data);
  if (!accessToken) {
    console.error("MSG91 verified without access token:", data);
    setStatus("OTP verified, but MSG91 did not return an access token.", "error");
    return;
  }
  const phone = cleanPhone(phoneInput.value);
  setStatus("Mobile number verified.", "success");
  post("verified", {
    phone,
    msg91_access_token: accessToken,
    msg91_response: data
  });
}

window.configuration = {
  widgetId: msg91WidgetId,
  tokenAuth: msg91TokenAuth,
  identifier: "",
  exposeMethods: true,
  captchaRenderId: "captcha-container",
  success: handleVerifiedData,
  failure: (error) => {
    console.error("MSG91 OTP failure:", error);
    setStatus("Mobile verification failed. Try again.", "error");
  }
};

async function handleSendOtp() {
  const phone = cleanPhone(phoneInput.value);
  phoneInput.value = phone;
  
  if (!validPhone(phone)) {
    setStatus("Enter a valid mobile number with country code.", "error");
    console.log("Invalid phone format:", phone);
    return;
  }
  
  if (!msg91Ready) {
    setStatus("MSG91 widget is still loading. Please wait...", "error");
    console.log("Widget not ready");
    return;
  }

  console.log("MSG91 widget will handle OTP sending. Phone set to:", phone);
  
  // MSG91 widget renders its own embedded form for OTP
  // Update configuration with the phone number and trigger widget
  window.configuration.identifier = phone;
  
  console.log("Configuration updated with phone:", phone);
  console.log("Look for OTP input form from MSG91 widget");
  
  setStatus("MSG91 widget loaded. Enter OTP below.", "success");
  sendOtpBtn.style.display = "none";
  phoneInput.disabled = true;
  
  // The widget will render and handle OTP sending internally
}

async function handleVerifyOtp() {
  // MSG91 widget handles OTP submission internally
  // The widget will call the success/failure callbacks
  console.log("OTP verification is handled by MSG91 widget internally");
  setStatus("MSG91 widget is processing your OTP...", "notice");
}

sendOtpBtn.addEventListener("click", handleSendOtp);
cancelBtn.addEventListener("click", () => post("cancelled"));

phoneInput.addEventListener("input", (event) => {
  const cleaned = cleanPhone(phoneInput.value);
  if (phoneInput.value !== cleaned) {
    phoneInput.value = cleaned;
  }
});

phoneInput.addEventListener("keydown", event => {
  if (event.key === "Enter") handleSendOtp();
});
</script>
<script type="text/javascript">
// Additional debugging for MSG91 script loading
window.addEventListener("error", (event) => {
  console.error("Global error:", event.message, event.filename, event.lineno);
});

window.addEventListener("load", () => {
  console.log("=== Page Fully Loaded ===");
  console.log("window.sendOtp available:", typeof window.sendOtp);
  console.log("window.verifyOtp available:", typeof window.verifyOtp);
  console.log("window.initSendOTP available:", typeof window.initSendOTP);
});

// Log when script loads
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    console.log("DOM Content Loaded");
  });
}
</script>
<script type="text/javascript" onload="initMsg91Provider()" onerror="console.error('FAILED TO LOAD MSG91 OTP PROVIDER SCRIPT')" src="https://verify.msg91.com/otp-provider.js"></script>
</body>
</html>
