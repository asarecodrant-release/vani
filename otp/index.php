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
.actions,.otp-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
button{border:0;border-radius:12px;min-height:42px;padding:0 14px;font:inherit;font-weight:800;cursor:pointer}
.primary{background:linear-gradient(135deg,#6366f1,#ec4899);color:#fff}
.ghost{background:#fff;color:#334155;border:1px solid #dbe3ef}
.link-btn{background:transparent;color:#4f46e5;border:0;padding:0;min-height:auto}
button:disabled{opacity:.6;cursor:not-allowed}
.notice{padding:12px 13px;border-radius:12px;background:#eef2ff;color:#3730a3;font-size:13px;line-height:1.45}
.error{background:#fee2e2;color:#991b1b}
.success{background:#dcfce7;color:#166534}
.otp-panel{display:none;gap:12px}
.otp-panel.active{display:grid}
.otp-row input{flex:1;min-width:160px;letter-spacing:4px;text-align:center;font-weight:800}
#captcha-container{min-height:1px}
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

    <div class="otp-panel" id="otpStep" aria-live="polite">
      <div class="field">
        <label for="otpInput">OTP code</label>
        <div class="otp-row">
          <input id="otpInput" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="000000">
          <button class="primary" id="verifyOtpBtn" type="button">Verify</button>
        </div>
      </div>
      <div class="otp-row">
        <button class="ghost" id="resendOtpBtn" type="button">Resend OTP</button>
        <button class="link-btn" id="editPhoneBtn" type="button">Change number</button>
      </div>
    </div>

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
const sendOtpBtn = document.getElementById("sendOtpBtn");
const cancelBtn = document.getElementById("cancelBtn");
const statusBox = document.getElementById("statusBox");
const otpStep = document.getElementById("otpStep");
const otpInput = document.getElementById("otpInput");
const verifyOtpBtn = document.getElementById("verifyOtpBtn");
const resendOtpBtn = document.getElementById("resendOtpBtn");
const editPhoneBtn = document.getElementById("editPhoneBtn");

let msg91Ready = false;
let currentReqId = null;
let lastIdentifier = "";

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

function msg91Identifier(phone) {
  return cleanPhone(phone).replace(/^\+/, "");
}

function setStatus(message, type = "notice") {
  statusBox.textContent = message;
  statusBox.className = "notice" + (type === "error" ? " error" : type === "success" ? " success" : "");
}

function setBusy(button, busy, busyText = "") {
  button.disabled = busy;
  if (busyText) {
    button.dataset.defaultText ||= button.textContent;
    button.textContent = busy ? busyText : button.dataset.defaultText;
  }
}

function errorText(error, fallback) {
  if (!error) return fallback;
  if (typeof error === "string") return error;
  return error.message || error.error || error.reason || error.description || fallback;
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
  const message = String(data?.message || "");
  return data?.reqId
    || data?.req_id
    || data?.request_id
    || data?.data?.reqId
    || data?.data?.req_id
    || data?.data?.request_id
    || (/^[a-z0-9_-]{12,}$/i.test(message) ? message : null);
}

function extractAccessToken(data) {
  return data?.access_token
    || data?.["access-token"]
    || data?.accessToken
    || data?.token
    || data?.jwt
    || data?.message?.access_token
    || data?.message?.["access-token"]
    || data?.data?.access_token
    || data?.data?.["access-token"]
    || null;
}

function initMsg91Provider() {
  if (typeof window.initSendOTP !== "function") {
    setStatus("MSG91 OTP provider could not load.", "error");
    return;
  }

  try {
    window.initSendOTP(window.configuration);
    let attempts = 0;
    const checkReady = setInterval(() => {
      attempts++;
      const methodsReady = typeof window.sendOtp === "function" && typeof window.verifyOtp === "function";
      if (methodsReady) {
        msg91Ready = true;
        clearInterval(checkReady);
        setStatus("MSG91 is ready. Enter your number and send OTP.", "notice");
      } else if (attempts > 200) {
        clearInterval(checkReady);
        setStatus("MSG91 widget failed to initialize.", "error");
      }
    }, 100);
  } catch (error) {
    console.error("Error initializing MSG91:", error);
    setStatus("Failed to initialize MSG91 OTP provider.", "error");
  }
}

function handleVerifiedData(data) {
  const accessToken = extractAccessToken(data);
  if (!accessToken) {
    console.error("MSG91 verified without access token:", data);
    setStatus("OTP verified, but MSG91 did not return an access token.", "error");
    return;
  }

  setStatus("Mobile number verified.", "success");
  post("verified", {
    phone: cleanPhone(phoneInput.value),
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
    setStatus(errorText(error, "Mobile verification failed. Try again."), "error");
  }
};

async function handleSendOtp() {
  const phone = cleanPhone(phoneInput.value);
  const identifier = msg91Identifier(phone);
  phoneInput.value = phone;

  if (!validPhone(phone)) {
    setStatus("Enter a valid mobile number with country code.", "error");
    return;
  }

  if (!msg91Ready) {
    setStatus("MSG91 widget is still loading. Please wait...", "error");
    return;
  }

  setBusy(sendOtpBtn, true, "Sending...");
  setStatus("Sending OTP...", "notice");

  try {
    const data = await withTimeout(new Promise((resolve, reject) => {
      window.configuration.identifier = identifier;
      window.sendOtp(identifier, resolve, reject);
    }), 30000, "MSG91 did not respond while sending OTP.");

    currentReqId = extractReqId(data);
    lastIdentifier = identifier;
    otpInput.value = "";
    otpStep.classList.add("active");
    sendOtpBtn.style.display = "none";
    phoneInput.disabled = true;
    setStatus("OTP sent. Enter the code below.", "success");
    otpInput.focus();
  } catch (error) {
    setStatus(errorText(error, "Could not send OTP. Please try again."), "error");
  } finally {
    setBusy(sendOtpBtn, false);
  }
}

async function handleVerifyOtp() {
  const otp = otpInput.value.trim();
  if (!/^[0-9]{4,8}$/.test(otp)) {
    setStatus("Enter the OTP sent to your mobile number.", "error");
    return;
  }

  if (typeof window.verifyOtp !== "function") {
    setStatus("MSG91 verification is still loading. Please wait...", "error");
    return;
  }

  setBusy(verifyOtpBtn, true, "Verifying...");
  setStatus("Verifying OTP...", "notice");

  try {
    const data = await withTimeout(new Promise((resolve, reject) => {
      window.verifyOtp(otp, resolve, reject, currentReqId || undefined);
    }), 30000, "MSG91 did not respond while verifying OTP.");
    handleVerifiedData(data);
  } catch (error) {
    setStatus(errorText(error, "Invalid OTP. Please check the code and try again."), "error");
  } finally {
    setBusy(verifyOtpBtn, false);
  }
}

async function handleResendOtp() {
  if (!lastIdentifier) {
    handleSendOtp();
    return;
  }

  setBusy(resendOtpBtn, true, "Sending...");
  setStatus("Sending a new OTP...", "notice");

  try {
    const data = await withTimeout(new Promise((resolve, reject) => {
      window.sendOtp(lastIdentifier, resolve, reject);
    }), 30000, "MSG91 did not respond while resending OTP.");
    currentReqId = extractReqId(data);
    otpInput.value = "";
    setStatus("New OTP sent. Enter the code below.", "success");
    otpInput.focus();
  } catch (error) {
    setStatus(errorText(error, "Could not resend OTP. Please try again."), "error");
  } finally {
    setBusy(resendOtpBtn, false);
  }
}

function resetPhoneEntry() {
  currentReqId = null;
  lastIdentifier = "";
  otpInput.value = "";
  otpStep.classList.remove("active");
  sendOtpBtn.style.display = "";
  phoneInput.disabled = false;
  phoneInput.focus();
  setStatus("Enter your mobile number with country code.", "notice");
}

sendOtpBtn.addEventListener("click", handleSendOtp);
verifyOtpBtn.addEventListener("click", handleVerifyOtp);
resendOtpBtn.addEventListener("click", handleResendOtp);
editPhoneBtn.addEventListener("click", resetPhoneEntry);
cancelBtn.addEventListener("click", () => post("cancelled"));

phoneInput.addEventListener("input", () => {
  const cleaned = cleanPhone(phoneInput.value);
  if (phoneInput.value !== cleaned) {
    phoneInput.value = cleaned;
  }
});

phoneInput.addEventListener("keydown", event => {
  if (event.key === "Enter") handleSendOtp();
});

otpInput.addEventListener("input", () => {
  otpInput.value = otpInput.value.replace(/\D/g, "").slice(0, 8);
});

otpInput.addEventListener("keydown", event => {
  if (event.key === "Enter") handleVerifyOtp();
});
</script>
<script type="text/javascript" onload="initMsg91Provider()" onerror="setStatus('Failed to load MSG91 OTP provider.', 'error')" src="https://verify.msg91.com/otp-provider.js"></script>
</body>
</html>
