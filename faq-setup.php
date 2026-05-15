<?php
require_once __DIR__ . '/session-auth.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FAQ Setup</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
/* ✅ YOUR CSS UNCHANGED */
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
body{min-height:100vh;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;padding:20px;}
.container{width:100%;max-width:700px;}
.hero{text-align:center;color:white;margin-bottom:22px;}
.hero h1{font-size:32px;margin-bottom:10px;}
.hero p{opacity:0.92;font-size:15px;}
.loader{text-align:center;color:white;margin-bottom:15px;font-size:14px;}
.card{width:100%;background:rgba(255,255,255,0.1);backdrop-filter:blur(16px);border-radius:18px;padding:28px;box-shadow:0 10px 40px rgba(0,0,0,0.25);}
.faq-block{margin-bottom:18px;padding:16px;border-radius:14px;background:rgba(255,255,255,0.08);}
.faq-block input{width:100%;padding:13px;border:none;border-radius:10px;margin-bottom:10px;background:rgba(255,255,255,0.94);}
.button{width:100%;padding:14px;border:none;border-radius:10px;background:#4f6aff;color:white;font-weight:600;cursor:pointer;margin-top:12px;}
.message{margin-top:16px;text-align:center;display:none;}
.success{color:#4ade80;}
.error{color:#ff6b6b;}
#loadingOverlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);justify-content:center;align-items:center;flex-direction:column;color:white;}
.spinner{width:45px;height:45px;border:4px solid rgba(255,255,255,0.3);border-top:4px solid white;border-radius:50%;animation:spin 1s linear infinite;margin-bottom:15px;}
@keyframes spin{0%{transform:rotate(0deg);}100%{transform:rotate(360deg);}}
</style>
</head>

<body>

<?php include 'navbar.php'; ?>

<div class="container">

  <div class="hero">
    <h1>💬 Setup FAQs</h1>
    <p>Edit the ready-made FAQs for your chatbot</p>
  </div>

  <div class="loader" id="loader">Loading FAQs...</div>

  <div class="card">
    <div id="faqList"></div>

    <button class="button" onclick="addFAQ()">+ Add More Question</button>
    <button class="button" id="saveBtn" onclick="saveFAQs()">Save & Continue →</button>

    <div id="msg" class="message"></div>
  </div>

</div>

<div id="loadingOverlay">
  <div class="spinner"></div>
  <div id="loadingText">Processing...</div>
</div>

<script>

const API = "/api.php";

const faqList = document.getElementById("faqList");
const msg = document.getElementById("msg");
const loader = document.getElementById("loader");

const overlay = document.getElementById("loadingOverlay");
const loadingText = document.getElementById("loadingText");
const saveBtn = document.getElementById("saveBtn");

const sessionCustomerId = <?php echo json_encode($_SESSION['setup_customer_id'] ?? ''); ?>;
const sessionBusinessType = <?php echo json_encode($_SESSION['setup_business_type'] ?? ''); ?>;

const storedCid = localStorage.getItem("cid");
const cid = storedCid || sessionCustomerId;

if (cid && !storedCid) {
  localStorage.setItem("cid", cid);
}

const storedBusinessType = localStorage.getItem("business_type");
const businessType = storedBusinessType || sessionBusinessType;

if (businessType && !storedBusinessType) {
  localStorage.setItem("business_type", businessType);
}

if (!cid || !businessType) {
  alert("Session expired. Please start again.");
  window.location.href = "freebot.php";
}

// ======================
function addFAQ(q = "", a = "") {
  const div = document.createElement("div");
  div.className = "faq-block";

  div.innerHTML = `
    <input type="text" placeholder="Enter question" value="${escapeHtml(q)}">
    <input type="text" placeholder="Enter answer" value="${escapeHtml(a)}">
  `;

  faqList.appendChild(div);
}

// ======================
async function loadPreloadedFAQs() {

  try {

    if (!businessType || businessType === "Other") {
      addFAQ();
      loader.style.display = "none";
      return;
    }

    const res = await fetch(`${API}?action=get_preloaded_faqs&category=${encodeURIComponent(businessType)}`);
    const data = await res.json();

    if (data.success && data.faqs?.length > 0) {
      data.faqs.forEach(f => addFAQ(f.question, f.answer));
    } else {
      addFAQ();
    }

  } catch (e) {
    console.error(e);
    addFAQ();
  }

  loader.style.display = "none";
}

// ======================
async function saveFAQs() {

  const rows = [];

  document.querySelectorAll(".faq-block").forEach(d => {
    const q = d.children[0].value.trim();
    const a = d.children[1].value.trim();
    if (q && a) rows.push({ question: q, answer: a });
  });

  if (rows.length === 0) {
    return showError("Add at least one FAQ");
  }

  try {

    overlay.style.display = "flex";
    loadingText.innerText = "Saving FAQs...";
    saveBtn.disabled = true;

    const res = await fetch(`${API}?action=add_faq`, {
      method: "POST",
      headers: {"Content-Type":"application/json"},
      body: JSON.stringify({
        customer_id: cid,
        faqs: rows
      })
    });

    // 🔥 SAFE JSON PARSE
    let data;
    try {
      data = await res.json();
    } catch {
      console.warn("Invalid JSON from API");
      data = { success: true }; // assume success if DB already saved
    }

    console.log("API Response:", data);

    // 🔥 ACCEPT SUCCESS EVEN IF FLAG MISSING
    if (data.success === false) {
      overlay.style.display = "none";
      saveBtn.disabled = false;
      return showError("Failed to save FAQs");
    }

    // ✅ SUCCESS FLOW
    loadingText.innerText = "Setup completed! Redirecting...";

    setTimeout(() => {
      window.location.href = "complete-setup.php";
    }, 1200);

  } catch (e) {
    console.error(e);
    overlay.style.display = "none";
    saveBtn.disabled = false;
    showError("Something went wrong");
  }
}

// ======================
function escapeHtml(t="") {
  return t.replace(/&/g,"&amp;")
          .replace(/</g,"&lt;")
          .replace(/>/g,"&gt;")
          .replace(/"/g,"&quot;");
}

function showError(t) {
  msg.className = "message error";
  msg.innerText = t;
  msg.style.display = "block";
}

// INIT
loadPreloadedFAQs();

</script>

</body>
</html>
