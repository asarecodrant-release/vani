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

    <div class="field" id="otpField" style="display:none">
      <label for="otpInput">OTP code</label>
      <input id="otpInput" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6-digit code">
    </div>

    <div class="actions" id="verifyActions" style="display:none">
      <button class="primary" id="verifyOtpBtn" type="button">Verify OTP</button>
      <button class="ghost" id="resendBtn" type="button">Resend</button>
    </div>

    <div id="statusBox" class="notice">Enter your mobile number with country code.</div>
    <div id="captcha-container"></div>
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
const otpInput = document.getElementById("otpInput");
const sendOtpBtn = document.getElementById("sendOtpBtn");
const verifyOtpBtn = document.getElementById("verifyOtpBtn");
const resendBtn = document.getElementById("resendBtn");
const cancelBtn = document.getElementById("cancelBtn");
const otpField = document.getElementById("otpField");
const verifyActions = document.getElementById("verifyActions");
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
    setStatus("MSG91 OTP provider could not load.", "error");
    return;
  }
  window.initSendOTP(window.configuration);
  msg91Ready = true;
}

function handleVerifiedData(data) {
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

async function sendOtp() {
  const phone = cleanPhone(phoneInput.value);
  phoneInput.value = phone;
  if (!validPhone(phone)) {
    setStatus("Enter a valid mobile number with country code.", "error");
    return;
  }
  if (!msg91Ready || typeof window.sendOtp !== "function") {
    setStatus("MSG91 OTP provider is still loading. Try again in a moment.", "error");
    return;
  }

  sendOtpBtn.disabled = true;
  resendBtn.disabled = true;
  setStatus("Sending OTP...");
  try {
    const data = await withTimeout(
      new Promise((resolve, reject) => window.sendOtp(phone, resolve, reject)),
      45000,
      "MSG91 OTP send timed out"
    );
    currentReqId = extractReqId(data);
    otpField.style.display = "grid";
    verifyActions.style.display = "flex";
    otpInput.focus();
    setStatus("OTP sent. Enter the code.", "success");
  } catch (error) {
    console.error("MSG91 phone OTP send failed:", error);
    setStatus("Could not send OTP. Check the number and try again.", "error");
  } finally {
    sendOtpBtn.disabled = false;
    resendBtn.disabled = false;
  }
}

async function verifyOtp() {
  const code = otpInput.value.trim();
  if (!/^[0-9]{6}$/.test(code)) {
    setStatus("Enter the 6-digit OTP code.", "error");
    return;
  }
  if (typeof window.verifyOtp !== "function") {
    setStatus("MSG91 OTP provider is still loading. Try again in a moment.", "error");
    return;
  }

  verifyOtpBtn.disabled = true;
  setStatus("Verifying OTP...");
  try {
    const verifyCall = currentReqId
      ? new Promise((resolve, reject) => window.verifyOtp(code, resolve, reject, currentReqId))
      : new Promise((resolve, reject) => window.verifyOtp(code, resolve, reject));
    const data = await withTimeout(
      verifyCall,
      45000,
      "MSG91 OTP verify timed out"
    );
    handleVerifiedData(data);
  } catch (error) {
    console.error("MSG91 phone OTP verify failed:", error);
    setStatus("Invalid or expired OTP. Try again.", "error");
  } finally {
    verifyOtpBtn.disabled = false;
  }
}

sendOtpBtn.addEventListener("click", sendOtp);
resendBtn.addEventListener("click", sendOtp);
verifyOtpBtn.addEventListener("click", verifyOtp);
cancelBtn.addEventListener("click", () => post("cancelled"));
phoneInput.addEventListener("input", (event) => {
  const cleaned = cleanPhone(phoneInput.value);
  if (phoneInput.value !== cleaned) {
    phoneInput.value = cleaned;
  }
});
otpInput.addEventListener("keydown", event => {
  if (event.key === "Enter") verifyOtp();
});
phoneInput.addEventListener("keydown", event => {
  if (event.key === "Enter") sendOtp();
});
</script>
<script type="text/javascript" onload="initMsg91Provider()" src="https://verify.msg91.com/otp-provider.js"></script>
</body>
</html>
