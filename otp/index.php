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
</style>
</head>
<body>
  <main class="otp-shell">
    <div class="brand">
      <div class="mark">V</div>
      <div>
        <h1>Verify mobile number</h1>
        <p>Secure verification runs through MSG91.</p>
      </div>
    </div>

    <div class="field">
      <label for="phoneInput">Mobile number</label>
      <input id="phoneInput" type="tel" inputmode="tel" autocomplete="tel" placeholder="+919876543210">
    </div>

    <div class="actions">
      <button class="primary" id="startOtpBtn" type="button">Send OTP</button>
      <button class="ghost" id="cancelBtn" type="button">Cancel</button>
    </div>

    <div id="statusBox" class="notice">Enter your mobile number with country code.</div>
  </main>

<script type="text/javascript">
const msg91WidgetId = <?php echo json_encode($msg91WidgetId); ?>;
const msg91TokenAuth = <?php echo json_encode($msg91TokenAuth); ?>;
const params = new URLSearchParams(window.location.search);
const requestId = params.get("request_id") || "";
const requestedParentOrigin = params.get("parent_origin") || "";
const parentOrigin = /^https?:\/\/[^/]+$/i.test(requestedParentOrigin) ? requestedParentOrigin : "*";

const phoneInput = document.getElementById("phoneInput");
const startOtpBtn = document.getElementById("startOtpBtn");
const cancelBtn = document.getElementById("cancelBtn");
const statusBox = document.getElementById("statusBox");

phoneInput.value = (params.get("phone") || "").trim();

function cleanPhone(value) {
  return value.trim().replace(/[^\d+]/g, "").replace(/(?!^)\+/g, "");
}

function validPhone(value) {
  return /^\+?[1-9][0-9]{7,14}$/.test(value);
}

function msg91Identifier(phone) {
  return cleanPhone(phone).replace(/^\+/, "");
}

function setStatus(message, type = "notice") {
  statusBox.textContent = message;
  statusBox.className = "notice" + (type === "error" ? " error" : type === "success" ? " success" : "");
}

function setBusy(isBusy) {
  startOtpBtn.disabled = isBusy;
  startOtpBtn.textContent = isBusy ? "Opening..." : "Send OTP";
}

function post(status, extra = {}) {
  window.parent?.postMessage({
    source: "vani-mobile-otp",
    request_id: requestId,
    status,
    ...extra
  }, parentOrigin);
}

function extractAccessToken(data) {
  const message = typeof data?.message === "string" ? data.message : "";
  return data?.access_token
    || data?.["access-token"]
    || data?.accessToken
    || data?.token
    || data?.jwt
    || data?.message?.access_token
    || data?.message?.["access-token"]
    || data?.data?.access_token
    || data?.data?.["access-token"]
    || (/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test(message) ? message : null)
    || null;
}

function errorText(error, fallback) {
  if (!error) return fallback;
  if (typeof error === "string") return error;
  return error.message || error.error || error.reason || error.description || fallback;
}

var configuration = {
  widgetId: msg91WidgetId,
  tokenAuth: msg91TokenAuth,
  identifier: "",
  exposeMethods: false,
  success: (data) => {
    console.log("success response", data);
    const accessToken = extractAccessToken(data);
    if (!accessToken) {
      setBusy(false);
      setStatus("OTP verified, but MSG91 did not return an access token.", "error");
      return;
    }

    setStatus("Mobile number verified.", "success");
    post("verified", {
      phone: cleanPhone(phoneInput.value),
      msg91_access_token: accessToken,
      msg91_response: data
    });
  },
  failure: (error) => {
    console.log("failure reason", error);
    setBusy(false);
    phoneInput.disabled = false;
    setStatus(errorText(error, "Mobile verification failed. Try again."), "error");
  }
};

function startOtp() {
  const phone = cleanPhone(phoneInput.value);
  phoneInput.value = phone;

  if (!msg91WidgetId || !msg91TokenAuth) {
    setStatus("MSG91 verification is not configured. Please contact support.", "error");
    return;
  }

  if (!validPhone(phone)) {
    setStatus("Enter a valid mobile number with country code.", "error");
    return;
  }

  if (typeof window.initSendOTP !== "function") {
    setStatus("MSG91 OTP provider is still loading. Please wait.", "error");
    return;
  }

  configuration.identifier = msg91Identifier(phone);
  setBusy(true);
  phoneInput.disabled = true;
  setStatus("Complete the MSG91 OTP verification window.", "success");
  window.initSendOTP(configuration);
}

startOtpBtn.addEventListener("click", startOtp);
cancelBtn.addEventListener("click", () => post("cancelled"));
phoneInput.addEventListener("input", () => {
  const cleaned = cleanPhone(phoneInput.value);
  if (phoneInput.value !== cleaned) phoneInput.value = cleaned;
});
phoneInput.addEventListener("keydown", event => {
  if (event.key === "Enter") startOtp();
});

if (!msg91WidgetId || !msg91TokenAuth) {
  startOtpBtn.disabled = true;
  setStatus("MSG91 verification is not configured. Please contact support.", "error");
}
</script>
<script type="text/javascript">
(function loadOtpScript(urls) {
  let i = 0;
  function attempt() {
    const s = document.createElement("script");
    s.src = urls[i];
    s.async = true;
    s.onload = () => {
      if (typeof window.initSendOTP === "function") {
        setStatus("MSG91 is ready. Enter your number and send OTP.", "notice");
      }
    };
    s.onerror = () => {
      i++;
      if (i < urls.length) {
        attempt();
      } else {
        setStatus("Failed to load MSG91 OTP provider.", "error");
      }
    };
    document.head.appendChild(s);
  }
  attempt();
})([
  "https://verify.msg91.com/otp-provider.js",
  "https://verify.phone91.com/otp-provider.js"
]);
</script>
</body>
</html>
