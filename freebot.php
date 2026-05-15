<?php
session_start();

// ======================================
// CLEAR OLD CUSTOMER ID
// ======================================
unset($_SESSION['customer_id']);

// ======================================
// SAFE SESSION VALUES
// ======================================
$loggedInEmail = $_SESSION['email'] ?? '';
$loggedInCustomerId = '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
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
</head>

<body>

<?php include 'navbar.php'; ?>
<div class="page-wrapper">
<div class="container">
<div class="card">

<h2>🚀 Create Your Chatbot</h2>

<form id="signupForm">

  <input 
    type="text" 
    id="websiteName" 
    placeholder="🌐 Enter your website name (e.g. MyShop)"
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

const API = "api.php";

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

document.getElementById("signupForm")
.addEventListener("submit", async (e) => {

  e.preventDefault();

  const overlay = document.getElementById("loadingOverlay");
  const btn = document.getElementById("submitBtn");

  overlay.style.display = "flex";
  btn.disabled = true;
  btn.innerText = "Processing...";

  const website =
    document.getElementById("websiteName").value.trim();

  const email =
    document.getElementById("email").value.trim();

  const cid =
    document.getElementById("customerId").value.trim();

  let business =
    document.getElementById("businessType").value;

  if (business === "Other") {
    business = document.getElementById("otherBusinessType").value.trim();
  }

  try {

    const signupRes = await fetch(`${API}?action=signup`, {
      method: "POST",
      headers: {"Content-Type":"application/json"},
      body: JSON.stringify({
        customer_id: cid,
        email: email,
        website_name: website,
        business_type: business
      })
    });

    const accRes = await fetch(`${API}?action=create_account`, {
      method: "POST",
      headers: {"Content-Type":"application/json"},
      body: JSON.stringify({
        customer_id: cid,
        email: email,
        website_name: website
      })
    });

    const accData = await accRes.json();

    if (!accData.success) {
      overlay.style.display = "none";
      btn.disabled = false;
      btn.innerText = "Continue →";
      alert("Account creation failed");
      return;
    }

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
</body>
</html>