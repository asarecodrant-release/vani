<?php
require_once __DIR__ . '/../core.php';

$firebaseApiKey = $_ENV['FIREBASE_API_KEY'] ?? getenv('FIREBASE_API_KEY') ?: '';
$firebaseAuthDomain = $_ENV['FIREBASE_AUTH_DOMAIN'] ?? getenv('FIREBASE_AUTH_DOMAIN') ?: 'vani.codrant.com';
$firebaseProjectId = $_ENV['FIREBASE_PROJECT_ID'] ?? getenv('FIREBASE_PROJECT_ID') ?: 'vani-ab6ae';
$firebaseStorageBucket = $_ENV['FIREBASE_STORAGE_BUCKET'] ?? getenv('FIREBASE_STORAGE_BUCKET') ?: 'vani-ab6ae.firebasestorage.app';
$firebaseMessagingSenderId = $_ENV['FIREBASE_MESSAGING_SENDER_ID'] ?? getenv('FIREBASE_MESSAGING_SENDER_ID') ?: '166956136198';
$firebaseAppId = $_ENV['FIREBASE_APP_ID'] ?? getenv('FIREBASE_APP_ID') ?: '1:166956136198:web:3078dc68517358b230d087';
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
    <div id="recaptcha-container"></div>
  </main>

<script type="module">
import { initializeApp, getApp, getApps } from "https://www.gstatic.com/firebasejs/12.13.0/firebase-app.js";
import { getAuth, RecaptchaVerifier, signInWithPhoneNumber } from "https://www.gstatic.com/firebasejs/12.13.0/firebase-auth.js";

const firebaseConfig = {
  apiKey: <?php echo json_encode($firebaseApiKey); ?>,
  authDomain: <?php echo json_encode($firebaseAuthDomain); ?>,
  projectId: <?php echo json_encode($firebaseProjectId); ?>,
  storageBucket: <?php echo json_encode($firebaseStorageBucket); ?>,
  messagingSenderId: <?php echo json_encode($firebaseMessagingSenderId); ?>,
  appId: <?php echo json_encode($firebaseAppId); ?>
};

const params = new URLSearchParams(window.location.search);
const requestId = params.get("request_id") || "";
const requestedParentOrigin = params.get("parent_origin") || "";
const parentOrigin = /^https?:\/\/[^/]+$/i.test(requestedParentOrigin) ? requestedParentOrigin : "*";
const initialPhone = (params.get("phone") || "").trim();
const app = getApps().length ? getApp() : initializeApp(firebaseConfig);
const auth = getAuth(app);

const phoneInput = document.getElementById("phoneInput");
const otpInput = document.getElementById("otpInput");
const sendOtpBtn = document.getElementById("sendOtpBtn");
const verifyOtpBtn = document.getElementById("verifyOtpBtn");
const resendBtn = document.getElementById("resendBtn");
const cancelBtn = document.getElementById("cancelBtn");
const otpField = document.getElementById("otpField");
const verifyActions = document.getElementById("verifyActions");
const statusBox = document.getElementById("statusBox");

let recaptchaVerifier = null;
let confirmationResult = null;

phoneInput.value = initialPhone;

if (!firebaseConfig.apiKey) {
  sendOtpBtn.disabled = true;
  setStatus("Mobile verification is not configured. Please contact support.", "error");
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

async function getVerifier() {
  if (recaptchaVerifier) return recaptchaVerifier;
  recaptchaVerifier = new RecaptchaVerifier(auth, "recaptcha-container", {
    size: "invisible"
  });
  await recaptchaVerifier.render();
  return recaptchaVerifier;
}

function withTimeout(promise, milliseconds, message) {
  let timer;
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(message)), milliseconds);
  });
  return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

async function sendOtp() {
  const phone = cleanPhone(phoneInput.value);
  phoneInput.value = phone;
  if (!validPhone(phone)) {
    setStatus("Enter a valid mobile number with country code.", "error");
    return;
  }

  sendOtpBtn.disabled = true;
  resendBtn.disabled = true;
  setStatus("Sending OTP...");
  try {
    const verifier = await getVerifier();
    confirmationResult = await withTimeout(
      signInWithPhoneNumber(auth, phone, verifier),
      45000,
      "Firebase OTP send timed out"
    );
    otpField.style.display = "grid";
    verifyActions.style.display = "flex";
    otpInput.focus();
    setStatus("OTP sent. Enter the 6-digit code.", "success");
  } catch (error) {
    console.error("Firebase phone OTP send failed:", error);
    if (recaptchaVerifier?.clear) {
      recaptchaVerifier.clear();
      recaptchaVerifier = null;
    }
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
  if (!confirmationResult) {
    setStatus("Send the OTP first.", "error");
    return;
  }

  verifyOtpBtn.disabled = true;
  setStatus("Verifying OTP...");
  try {
    const credential = await confirmationResult.confirm(code);
    const phone = credential?.user?.phoneNumber || cleanPhone(phoneInput.value);
    setStatus("Mobile number verified.", "success");
    post("verified", {
      phone,
      firebase_uid: credential?.user?.uid || null,
      firebase_id_token: await credential.user.getIdToken()
    });
  } catch (error) {
    console.error("Firebase phone OTP verify failed:", error);
    setStatus("Invalid or expired OTP. Try again.", "error");
  } finally {
    verifyOtpBtn.disabled = false;
  }
}

sendOtpBtn.addEventListener("click", sendOtp);
resendBtn.addEventListener("click", sendOtp);
verifyOtpBtn.addEventListener("click", verifyOtp);
cancelBtn.addEventListener("click", () => post("cancelled"));
phoneInput.addEventListener("input", () => {
  phoneInput.value = cleanPhone(phoneInput.value);
});
otpInput.addEventListener("keydown", event => {
  if (event.key === "Enter") verifyOtp();
});
phoneInput.addEventListener("keydown", event => {
  if (event.key === "Enter") sendOtp();
});
</script>
</body>
</html>
