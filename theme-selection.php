<?php
require_once __DIR__ . '/session-auth.php';

$botImages = glob(__DIR__ . '/images/botimg_*') ?: [];
$botImages = array_values(array_filter($botImages, 'is_file'));
natcasesort($botImages);
$botImages = array_map(fn($path) => 'images/' . basename($path), $botImages);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/logo_img.png">
<title>Customize Chatbot</title>

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
  display: flex;
  align-items: center;
  justify-content: center;
}

.container {
  width: 100%;
  max-width: 620px;
  padding: 20px;
}

.card {
  background: rgba(255,255,255,0.1);
  backdrop-filter: blur(16px);
  border-radius: 16px;
  padding: 30px;
  color: white;
  box-shadow: 0 10px 40px rgba(0,0,0,0.25);
  animation: fadeIn 0.6s ease;
}

h1 {
  text-align: center;
  font-size: 22px;
  margin-bottom: 8px;
}

p {
  text-align: center;
  font-size: 13px;
  opacity: 0.8;
  margin-bottom: 20px;
}

/* FORM */
.form-row {
  margin-bottom: 15px;
}

label {
  font-size: 13px;
  opacity: 0.8;
}

input[type="color"],
input[type="text"] {
  width: 100%;
  margin-top: 6px;
  padding: 10px;
  border-radius: 8px;
  border: none;
  outline: none;
}

.bot-image-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(72px, 1fr));
  gap: 10px;
  margin-top: 10px;
}

.bot-image-option {
  border: 2px solid rgba(255,255,255,0.18);
  background: rgba(255,255,255,0.12);
  border-radius: 12px;
  padding: 8px;
}

.bot-image-option.active {
  border-color: white;
  background: rgba(255,255,255,0.22);
}

.bot-image-option img {
  width: 100%;
  aspect-ratio: 1;
  object-fit: contain;
  display: block;
}

/* PRESET COLORS */
.color-widget {
  margin: 18px 0 20px;
  padding: 16px;
  border-radius: 14px;
  background: rgba(255,255,255,0.1);
  border: 1px solid rgba(255,255,255,0.14);
}

.color-widget-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  margin-bottom: 14px;
}

.color-widget-title {
  display: grid;
  gap: 4px;
}

.color-widget-title strong {
  font-size: 14px;
}

.color-widget-title span,
.color-note {
  color: rgba(255,255,255,0.72);
  font-size: 12px;
  line-height: 1.5;
}

.selected-color-preview {
  width: 54px;
  height: 54px;
  border-radius: 14px;
  border: 3px solid rgba(255,255,255,0.72);
  box-shadow: 0 12px 30px rgba(0,0,0,0.22);
  flex: 0 0 auto;
}

.palette {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(92px, 1fr));
  gap: 10px;
  margin: 12px 0 14px;
}

.color-box {
  width: 100%;
  min-height: 58px;
  border-radius: 12px;
  cursor: pointer;
  transition: 0.2s;
  border: 2px solid rgba(255,255,255,0.16);
  padding: 0;
  overflow: hidden;
  position: relative;
  box-shadow: inset 0 -22px 34px rgba(0,0,0,0.18);
}

.color-box:hover {
  transform: translateY(-2px);
}

.color-box.active {
  border-color: white;
  box-shadow: 0 0 0 3px rgba(255,255,255,0.18), inset 0 -22px 34px rgba(0,0,0,0.18);
}

.color-box span {
  position: absolute;
  left: 8px;
  right: 8px;
  bottom: 7px;
  color: white;
  font-size: 11px;
  font-weight: 700;
  text-shadow: 0 1px 8px rgba(0,0,0,0.45);
}

.custom-color-row {
  display: grid;
  grid-template-columns: 126px 1fr;
  gap: 12px;
  align-items: end;
}

.native-color-trigger {
  display: grid;
  gap: 7px;
}

.native-color-trigger input[type="color"] {
  width: 100%;
  height: 46px;
  padding: 4px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,0.22);
  background: rgba(255,255,255,0.16);
}

.hex-field label {
  display: block;
}

.hex-field input {
  text-transform: uppercase;
}

@media(max-width:560px) {
  .custom-color-row {
    grid-template-columns: 1fr;
  }
}

/* PREVIEW */
.preview {
  margin: 20px 0;
  text-align: center;
}

.chat-preview {
  background: rgba(255,255,255,0.15);
  padding: 15px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  gap: 10px;
}

.preview-avatar {
  width: 46px;
  height: 46px;
  border-radius: 50%;
  background: rgba(255,255,255,0.18);
  padding: 5px;
  object-fit: contain;
}

.bubble {
  padding: 12px 16px;
  border-radius: 20px;
  color: white;
  display: inline-block;
  max-width: 200px;
  transition: 0.3s;
}

/* BUTTON */
button {
  width: 100%;
  padding: 14px;
  border-radius: 8px;
  border: none;
  background: #4f6aff;
  color: white;
  font-weight: 600;
  cursor: pointer;
  transition: 0.3s;
}

button:hover {
  background: #3d55e0;
}

button.loading {
  opacity: 0.7;
  pointer-events: none;
}

/* MESSAGE */
.message {
  margin-top: 15px;
  font-size: 13px;
  display: none;
}

.success { color: #4ade80; }
.error { color: #ff6b6b; }

/* ANIMATION */
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(20px);}
  to { opacity: 1; transform: translateY(0);}
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

<div class="container">
  <div class="card">

    <h1>🎨 Customize Your Chatbot</h1>
    <p>Choose a color that matches your brand</p>

    <div class="color-widget">
      <div class="color-widget-head">
        <div class="color-widget-title">
          <strong>Chatbot theme color</strong>
          <span>Select a preset, use the picker, or enter a HEX code.</span>
        </div>
        <div class="selected-color-preview" id="selectedColorPreview" aria-hidden="true"></div>
      </div>

      <div class="palette" id="palette" aria-label="Theme color presets">
        <button class="color-box active" type="button" data-color="#4f46e5" style="background:#4f46e5"><span>Indigo</span></button>
        <button class="color-box" type="button" data-color="#06b6d4" style="background:#06b6d4"><span>Cyan</span></button>
        <button class="color-box" type="button" data-color="#10b981" style="background:#10b981"><span>Emerald</span></button>
        <button class="color-box" type="button" data-color="#f59e0b" style="background:#f59e0b"><span>Amber</span></button>
        <button class="color-box" type="button" data-color="#ef4444" style="background:#ef4444"><span>Red</span></button>
        <button class="color-box" type="button" data-color="#ec4899" style="background:#ec4899"><span>Pink</span></button>
        <button class="color-box" type="button" data-color="#7c3aed" style="background:#7c3aed"><span>Violet</span></button>
        <button class="color-box" type="button" data-color="#0ea5e9" style="background:#0ea5e9"><span>Sky</span></button>
        <button class="color-box" type="button" data-color="#111827" style="background:#111827"><span>Graphite</span></button>
        <button class="color-box" type="button" data-color="#16a34a" style="background:#16a34a"><span>Green</span></button>
      </div>

      <div class="custom-color-row">
        <label class="native-color-trigger">
          <span>Pick any color</span>
          <input type="color" id="colorPicker" value="#4f46e5">
        </label>
        <div class="hex-field">
          <label for="hexInput">Enter color code externally</label>
          <input id="hexInput" value="#4F46E5" placeholder="#6366F1" maxlength="7" spellcheck="false">
        </div>
      </div>

      <div class="color-note" id="colorNote">Selected color: #4F46E5</div>
    </div>

    <div class="form-row">
      <label>Select chatbot image</label>
      <div class="bot-image-grid" id="botImageGrid">
        <?php foreach ($botImages as $index => $image): ?>
          <button class="bot-image-option <?php echo $index === 0 ? 'active' : ''; ?>" type="button" data-image="<?php echo htmlspecialchars($image, ENT_QUOTES, 'UTF-8'); ?>">
            <img src="<?php echo htmlspecialchars($image, ENT_QUOTES, 'UTF-8'); ?>" alt="Chatbot image option <?php echo $index + 1; ?>">
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- PREVIEW -->
    <div class="preview">
      <div class="chat-preview">
        <img id="previewAvatar" class="preview-avatar" src="<?php echo htmlspecialchars($botImages[0] ?? 'images/logo_img.png', ENT_QUOTES, 'UTF-8'); ?>" alt="Selected chatbot image">
        <div id="previewBubble" class="bubble">
          Hi 👋 How can I help you?
        </div>
      </div>
    </div>

    <button id="saveBtn">Save & Continue →</button>

    <div id="msg" class="message"></div>

  </div>
</div>

<script>
// ADD AT TOP
const sessionBusinessType = <?php echo json_encode($_SESSION['setup_business_type'] ?? ''); ?>;
const sessionCustomerId = <?php echo json_encode($_SESSION['setup_customer_id'] ?? ''); ?>;

const storedBusinessType = localStorage.getItem("business_type");
const businessType = storedBusinessType || sessionBusinessType;

if (businessType && !storedBusinessType) {
  localStorage.setItem("business_type", businessType);
}

if (!businessType) {
  alert("Session expired. Please start again.");
  window.location.href = "freebot.php";
}

const API = "/api.php";

const picker = document.getElementById("colorPicker");
const hexInput = document.getElementById("hexInput");
const preview = document.getElementById("previewBubble");
const msg = document.getElementById("msg");
const palette = document.getElementById("palette");
const saveBtn = document.getElementById("saveBtn");
const botImageGrid = document.getElementById("botImageGrid");
const previewAvatar = document.getElementById("previewAvatar");
const selectedColorPreview = document.getElementById("selectedColorPreview");
const colorNote = document.getElementById("colorNote");

const storedCid = localStorage.getItem("cid");
const cid = storedCid || sessionCustomerId;

if (cid && !storedCid) {
  localStorage.setItem("cid", cid);
}

if (!cid) {
  showError("Session expired. Please signup again.");
}

let selectedColor = "#4f46e5";
let selectedBotImage = botImageGrid?.querySelector(".bot-image-option")?.dataset.image || "";
updatePreview(selectedColor);

// =========================
// PRESET COLORS CLICK
// =========================
palette.querySelectorAll(".color-box").forEach(box => {
  box.addEventListener("click", () => {
    selectedColor = normalizeHex(box.dataset.color || "#4f46e5");
    picker.value = selectedColor;
    hexInput.value = selectedColor.toUpperCase();

    document.querySelectorAll(".color-box").forEach(b => b.classList.remove("active"));
    box.classList.add("active");

    updatePreview(selectedColor);
  });
});

// =========================
// COLOR PICKER
// =========================
picker.addEventListener("input", () => {
  selectedColor = normalizeHex(picker.value);
  hexInput.value = selectedColor.toUpperCase();
  setActiveSwatch(selectedColor);
  updatePreview(selectedColor);
});

// =========================
// HEX INPUT
// =========================
hexInput.addEventListener("input", () => {
  const val = hexInput.value.trim();

  if (isValidHex(val)) {
    selectedColor = normalizeHex(val);
    picker.value = selectedColor;
    hexInput.value = selectedColor.toUpperCase();
    setActiveSwatch(selectedColor);
    updatePreview(selectedColor);
  }
});

botImageGrid?.querySelectorAll(".bot-image-option").forEach(option => {
  option.addEventListener("click", () => {
    selectedBotImage = option.dataset.image || "";
    botImageGrid.querySelectorAll(".bot-image-option").forEach(item => item.classList.remove("active"));
    option.classList.add("active");
    if (previewAvatar && selectedBotImage) previewAvatar.src = selectedBotImage;
  });
});

// =========================
// SAVE
// =========================
saveBtn.onclick = async () => {

  if (!isValidHex(selectedColor)) {
    showError("Enter valid HEX (e.g. #4f6aff)");
    return;
  }

  saveBtn.classList.add("loading");
  saveBtn.innerText = "Saving...";

  try {
    const res = await fetch(`${API}?action=update_theme`, {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({
        customer_id: cid,
        theme_color: selectedColor,
        avatar_url: selectedBotImage,
        email_verified: true
      })
    });

    const data = await res.json();

    if (!res.ok || data.success === false || data.error) {
      showError(data.message || data.error || "Theme could not be saved. Please login and try again.");
      saveBtn.classList.remove("loading");
      saveBtn.innerText = "Save & Continue ->";
      return;
    }

    localStorage.setItem("theme", selectedColor);
    if (selectedBotImage) localStorage.setItem("chatbot_image", selectedBotImage);

    showSuccess("Theme saved!");

    setTimeout(() => {
      window.location.href = "faq-setup.php";
    }, 1000);

  } catch (err) {
    showError("Failed to save");
  }

  saveBtn.classList.remove("loading");
  saveBtn.innerText = "Save & Continue →";
};

// =========================
// HELPERS
// =========================
function updatePreview(color) {
  const normalized = normalizeHex(color);
  preview.style.background = normalized;
  if (selectedColorPreview) selectedColorPreview.style.background = normalized;
  if (colorNote) colorNote.innerText = `Selected color: ${normalized.toUpperCase()}`;
}

function isValidHex(color) {
  return /^#([0-9A-F]{3}){1,2}$/i.test(color);
}

function normalizeHex(color) {
  let value = String(color || "").trim();
  if (!value.startsWith("#")) value = `#${value}`;
  if (/^#([0-9A-F]{3})$/i.test(value)) {
    value = "#" + value.slice(1).split("").map(char => char + char).join("");
  }
  return value.toLowerCase();
}

function setActiveSwatch(color) {
  const normalized = normalizeHex(color);
  palette.querySelectorAll(".color-box").forEach(box => {
    box.classList.toggle("active", normalizeHex(box.dataset.color || "") === normalized);
  });
}

function showError(text) {
  msg.className = "message error";
  msg.innerText = text;
  msg.style.display = "block";
}

function showSuccess(text) {
  msg.className = "message success";
  msg.innerText = text;
  msg.style.display = "block";
}

</script>
<script src="setup-theme.js"></script>

</body>
</html>
