<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/billing.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/logo_img.png">
<title>Vani Wallet Recharge Plans</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/public-theme.css">
<script defer src="js/public-theme.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:Inter,sans-serif}
body{
  background:
    radial-gradient(circle at top left,rgba(99,102,241,.34),transparent 34%),
    radial-gradient(circle at 85% 0,rgba(236,72,153,.22),transparent 28%),
    linear-gradient(135deg,#020617 0%,#08111f 46%,#111827 100%);
  color:#e5e7eb;
  min-height:100vh;
}
.container{width:100%;max-width:1180px;margin:auto;padding:0 20px}
nav{padding:16px 0}
.nav-inner{display:flex;align-items:center;justify-content:space-between;gap:16px}
.logo{display:inline-flex;align-items:center;gap:12px;position:relative;text-decoration:none;color:#f8fafc;font-size:23px;font-weight:800;white-space:nowrap;padding:7px 10px 9px 6px;border-radius:16px;background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(236,72,153,.1));border:1px solid rgba(129,140,248,.18)}
.logo img{width:54px;height:54px;object-fit:contain;filter:drop-shadow(0 0 18px rgba(99,102,241,.7)) drop-shadow(0 0 24px rgba(236,72,153,.28))}
.logo span{background:linear-gradient(90deg,#ffffff,#c4b5fd 48%,#f9a8d4);-webkit-background-clip:text;-webkit-text-fill-color:transparent;filter:drop-shadow(0 0 14px rgba(129,140,248,.28))}
.nav-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.nav-link,.nav-btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border-radius:12px;height:42px;padding:0 16px;font-weight:700}
.nav-link{color:#e5e7eb;background:rgba(15,23,42,.72);border:1px solid rgba(129,140,248,.24)}
.nav-btn{color:#fff;background:linear-gradient(135deg,#6366f1,#8b5cf6);box-shadow:0 10px 24px rgba(99,102,241,.24)}
.hero{padding:34px 0 20px;text-align:center}
.eyebrow{display:inline-flex;color:#c4b5fd;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px}
h1{font-size:48px;line-height:1.12;max-width:840px;margin:0 auto}
.hero p{max-width:780px;margin:18px auto 0;color:#cbd5e1;font-size:18px;line-height:1.7}
.wallet-note{max-width:900px;margin:22px auto 0;padding:17px 20px;border:1px solid rgba(34,197,94,.28);border-radius:18px;background:linear-gradient(135deg,rgba(34,197,94,.13),rgba(8,145,178,.1));color:#e5e7eb;line-height:1.65;text-align:left}
.wallet-note strong{display:block;margin-bottom:5px;font-size:17px;color:#fff}
.pricing-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:16px;margin:34px 0 42px;align-items:stretch}
.card{grid-column:span 2;background:linear-gradient(145deg,rgba(15,23,42,.88),rgba(30,41,59,.7));border:1px solid rgba(129,140,248,.24);border-radius:18px;padding:18px;display:grid;gap:14px;box-shadow:0 22px 55px rgba(0,0,0,.32),inset 0 1px 0 rgba(255,255,255,.04)}
.card.featured{grid-column:span 2;padding:24px;border-color:rgba(34,197,94,.5);box-shadow:0 24px 70px rgba(34,197,94,.18);transform:scale(1.02)}
.head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.tag{font-size:12px;font-weight:800;border-radius:999px;padding:6px 9px;background:rgba(99,102,241,.16);color:#c4b5fd}
.tag.good{background:rgba(34,197,94,.16);color:#86efac}
h2{font-size:20px}
.price{font-size:34px;font-weight:800}
.card.featured .price{font-size:40px}
.price small{font-size:14px;color:#94a3b8;font-weight:700}
.features{display:grid;gap:9px;font-size:14px;line-height:1.45}
.features span{display:grid;grid-template-columns:18px minmax(0,1fr);gap:8px;align-items:start}
.features span:before{display:inline-grid;place-items:center;width:18px;height:18px;border-radius:999px;font-size:12px;font-weight:900;line-height:1}
.features .is-included{color:#d1fae5}
.features .is-included:before{content:"\2713";background:rgba(34,197,94,.16);color:#4ade80}
.features .is-excluded{color:#fecaca}
.features .is-excluded:before{content:"\00D7";background:rgba(239,68,68,.16);color:#f87171}
table{width:100%;border-collapse:collapse;font-size:13px}
td,th{padding:8px 0;border-bottom:1px solid rgba(148,163,184,.22);text-align:left;color:#cbd5e1}
td:last-child,th:last-child{text-align:right;font-weight:800}
.note{padding:12px 14px;border-radius:12px;background:rgba(15,23,42,.6);border:1px solid rgba(148,163,184,.22);color:#cbd5e1;line-height:1.55;font-size:14px}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:auto}
.choose-plan-btn{border:0;cursor:pointer}
.checkout-panel{display:none;margin:0 0 46px;padding:22px;border:1px solid rgba(129,140,248,.28);border-radius:20px;background:linear-gradient(145deg,rgba(15,23,42,.92),rgba(30,41,59,.78));box-shadow:0 22px 60px rgba(0,0,0,.28)}
.checkout-panel.active{display:block}
.checkout-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:16px}
.checkout-head h2{font-size:24px}
.checkout-head p{margin-top:6px;color:#cbd5e1;line-height:1.6}
.checkout-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr)) auto;gap:12px;align-items:end}
.field{display:grid;gap:7px}
.field label{font-size:13px;font-weight:800;color:#e5e7eb}
.required{color:#f87171}
.field input{width:100%;height:46px;border-radius:12px;border:1px solid rgba(148,163,184,.32);background:rgba(15,23,42,.72);color:#fff;padding:0 13px;font-size:15px;outline:0}
.field input:focus{border-color:#a5b4fc;box-shadow:0 0 0 3px rgba(99,102,241,.24)}
.field input.error{border-color:#f87171;box-shadow:0 0 0 3px rgba(248,113,113,.18)}
.checkout-help{grid-column:1/-1;color:#cbd5e1;font-size:13px;line-height:1.55}
.otp-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:end}
.otp-row button{height:46px;white-space:nowrap}
.checkout-help.error{color:#fecaca}
.checkout-status{display:none;grid-column:1/-1;padding:12px 14px;border-radius:12px;background:rgba(99,102,241,.14);border:1px solid rgba(129,140,248,.24);color:#e0e7ff;line-height:1.55}
.checkout-status.show{display:block}
@media(max-width:992px){.pricing-grid,.checkout-form{grid-template-columns:1fr}.card,.card.featured{grid-column:auto;transform:none}h1{font-size:36px}.nav-inner{align-items:center;flex-direction:row}.nav-link{display:none}.logo{font-size:20px}.logo img{width:46px;height:46px}.checkout-head{display:grid}}
</style>
</head>
<body>
<nav>
  <div class="container nav-inner">
    <a class="logo" href="index.php"><img src="images/logo_img.png" alt="Vani AI Logo"><span>Vani AI</span></a>
    <div class="nav-actions">
      <a class="nav-link" href="index.php">Home</a>
      <a class="nav-link" href="login.php">Login</a>
      <button class="site-menu-trigger" type="button" aria-label="Open menu" aria-expanded="false">
        <span></span>
        <span></span>
        <span></span>
      </button>
    </div>
  </div>
</nav>

<?php include_once __DIR__ . '/site-menu.php'; ?>

<main class="container">
  <section class="hero">
    <span class="eyebrow">Wallet Recharge Plans</span>
    <h1>Start small, verify real leads, and scale your chatbot as demand grows.</h1>
    <p>Recharge the wallet with a minimum amount to unlock FAQ capacity, paid lead verification, analytics, and integration benefits. Wallet charges apply as your customers use OTP verification and WhatsApp redirection. WhatsApp redirection is ₹99 per 30 days on every plan.</p>
    <div class="wallet-note">
      <strong>100% recharge amount is credited to the customer's wallet.</strong>
      Recharge with Starter, Growth, or Business to unlock that plan's benefits. The wallet is then used as-you-go based on real usage, mainly when new website visitors verify by Email OTP or Mobile OTP, and for paid add-ons such as WhatsApp Redirect.
    </div>
  </section>

  <section class="pricing-grid">
    <article class="card">
      <div class="head"><div><span class="eyebrow">Starter</span><h2>Starter Plan</h2></div><span class="tag">Small</span></div>
      <div class="price">₹199<small>minimum recharge</small></div>
      <div class="features"><span class="is-included">100 FAQ answers for small websites</span><span class="is-included">Email and Mobile OTP verification for real leads</span><span class="is-included">Dedicated WhatsApp button and many more action items for FAQs</span><span class="is-included">Webhook support</span><span class="is-included">FAQ Action Suggestions</span><span class="is-included">Auto wallet recharge: below ₹50, recharge ₹199</span><span class="is-excluded">Live Chat Actions for real-time website reactions</span><span class="is-excluded">API Integration to migrate or save data in your database</span><span class="is-excluded">Analytics dashboard access</span><span class="is-excluded">Chat can run only on allowed domains</span></div>
      <div class="actions"><button class="nav-btn choose-plan-btn" type="button" data-plan-id="starter" data-plan-name="Starter Plan" data-plan-price="₹199 minimum recharge">Recharge Starter</button></div>
    </article>

    <article class="card featured">
      <div class="head"><div><span class="eyebrow">Growth</span><h2>Growth Plan</h2></div><span class="tag good">Popular</span></div>
      <div class="price">₹499<small>minimum recharge</small></div>
      <div class="features"><span class="is-included">300 FAQ capacity for growing businesses</span><span class="is-included">Email and Mobile OTP verification for real leads</span><span class="is-included">Dedicated WhatsApp button and many more action items for FAQs</span><span class="is-included">Webhook support</span><span class="is-included">FAQ Action Suggestions</span><span class="is-included">Auto wallet recharge: below ₹100, recharge ₹499</span><span class="is-included">Analytics access: Overview, Conversations, FAQ Insights, Leads</span><span class="is-included">Better wallet rates than Starter on email and mobile leads</span><span class="is-excluded">Live Chat Actions for real-time website reactions</span><span class="is-excluded">API Integration to migrate or save data in your database</span><span class="is-excluded">Chat can run only on allowed domains</span></div>
      <div class="actions"><button class="nav-btn choose-plan-btn" type="button" data-plan-id="growth" data-plan-name="Growth Plan" data-plan-price="₹499 minimum recharge">Recharge Growth</button></div>
    </article>

    <article class="card">
      <div class="head"><div><span class="eyebrow">Business</span><h2>Business Plan</h2></div><span class="tag">Scale</span></div>
      <div class="price">₹999<small>minimum recharge</small></div>
      <div class="features"><span class="is-included">Unlimited FAQ capacity for larger businesses</span><span class="is-included">Email and Mobile combined widget</span><span class="is-included">Dedicated WhatsApp button and many more action items for FAQs</span><span class="is-included">Webhook support</span><span class="is-included">FAQ Action Suggestions</span><span class="is-included">Live Chat Actions for real-time website reactions</span><span class="is-included">Auto wallet recharge: below ₹200, recharge ₹999</span><span class="is-included">API Integration to migrate or save data in your database</span><span class="is-included">Advanced Analytics: Overview, Conversations, FAQ Insights, Leads, Pages, Real-Time, Reports Download</span><span class="is-included">Chat can run only on allowed domains</span></div>
      <div class="actions"><button class="nav-btn choose-plan-btn" type="button" data-plan-id="business" data-plan-name="Business Plan" data-plan-price="₹999 minimum recharge">Recharge Business</button></div>
    </article>
  </section>

  <section class="checkout-panel" id="publicCheckoutPanel">
    <div class="checkout-head">
      <div>
        <span class="eyebrow">Secure Checkout</span>
        <h2>Recharge <span id="checkoutPlanName">Selected Plan</span></h2>
        <p>Enter customer details for billing and account creation. After successful payment, login details will be sent to the email address below.</p>
      </div>
      <span class="tag good" id="checkoutPlanPrice">Select a plan</span>
    </div>
    <form class="checkout-form" id="publicSubscriptionForm">
      <div class="field">
        <label for="publicCustomerName">Customer name <span class="required">*</span></label>
        <input id="publicCustomerName" autocomplete="name" required>
      </div>
      <div class="field">
        <label for="publicCustomerEmail">Customer email <span class="required">*</span></label>
        <input id="publicCustomerEmail" type="email" autocomplete="email" required>
      </div>
      <div class="field">
        <label for="publicCustomerPhone">Mobile number with country code <span class="required">*</span></label>
        <input id="publicCustomerPhone" type="tel" inputmode="tel" placeholder="+919876543210" autocomplete="tel" required>
      </div>
      <div class="field">
        <label for="publicEmailOtp">Email OTP <span class="required">*</span></label>
        <div class="otp-row">
          <input id="publicEmailOtp" inputmode="numeric" maxlength="6" placeholder="6-digit code" required>
          <button class="nav-link" type="button" id="sendPublicOtpBtn">Send OTP</button>
        </div>
      </div>
      <button class="nav-btn" type="submit" id="publicPayBtn">Recharge Now</button>
      <p class="checkout-help" id="publicCheckoutHelp"><span class="required">*</span> These details and email OTP verification are required to create your Vani AI account and activate your wallet plan benefits.</p>
      <div class="checkout-status" id="publicCheckoutStatus"></div>
    </form>
  </section>
</main>
<script defer src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
let selectedPublicPlan = "";
const checkoutPanel = document.getElementById("publicCheckoutPanel");
const checkoutStatus = document.getElementById("publicCheckoutStatus");
const checkoutHelp = document.getElementById("publicCheckoutHelp");

function setCheckoutStatus(message, show = true) {
  if (!checkoutStatus) return;
  checkoutStatus.textContent = message;
  checkoutStatus.classList.toggle("show", show);
}

async function recordPublicRazorpayFailure(orderId, response) {
  const error = response?.error || {};
  try {
    await fetch("/api.php?action=record_razorpay_failure", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({
        context: "public_wallet_recharge",
        razorpay_order_id: orderId || error?.metadata?.order_id || "",
        razorpay_payment_id: error?.metadata?.payment_id || "",
        error
      })
    });
  } catch (failureLogError) {
    console.warn("Razorpay failure logging skipped", failureLogError);
  }
}

function publicRazorpayFailureMessage(response) {
  const error = response?.error || {};
  const reason = String(error.reason || error.code || "").toLowerCase();
  const description = String(error.description || "").trim();
  let detail = description || "Payment could not be completed.";
  if (/insufficient|balance|fund/.test(reason + " " + description.toLowerCase())) {
    detail = "The card or account may not have enough balance. Please use another card or payment method.";
  } else if (/declin|bank|issuer/.test(reason + " " + description.toLowerCase())) {
    detail = "Your bank declined this payment. Please contact your bank or try another card.";
  } else if (/expired/.test(reason + " " + description.toLowerCase())) {
    detail = "This card appears to be expired. Please use another card.";
  } else if (/auth|otp|3d|verification|pin/.test(reason + " " + description.toLowerCase())) {
    detail = "Bank verification was not completed. Please retry and complete the OTP, PIN, or 3D Secure step.";
  } else if (/timeout|network|temporar|server|gateway/.test(reason + " " + description.toLowerCase())) {
    detail = "The payment gateway or network had a temporary issue. Please retry after a moment.";
  } else if (/cancel/.test(reason + " " + description.toLowerCase())) {
    detail = "The payment was cancelled before completion.";
  } else if (/card|method|instrument/.test(reason + " " + description.toLowerCase())) {
    detail = "This card or payment method could not be used. Please try another card or payment method.";
  }
  return `${detail} No wallet amount was added.`;
}

function validatePublicCheckout() {
  const nameInput = document.getElementById("publicCustomerName");
  const emailInput = document.getElementById("publicCustomerEmail");
  const phoneInput = document.getElementById("publicCustomerPhone");
  const nameValid = (nameInput?.value.trim() || "").length >= 3;
  const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput?.value.trim() || "");
  const phoneValid = /^\+?[1-9]\d{7,14}$/.test(phoneInput?.value.trim() || "");
  const otpInput = document.getElementById("publicEmailOtp");
  const otpValid = /^\d{6}$/.test(otpInput?.value.trim() || "");
  nameInput?.classList.toggle("error", !nameValid);
  emailInput?.classList.toggle("error", !emailValid);
  phoneInput?.classList.toggle("error", !phoneValid);
  otpInput?.classList.toggle("error", !otpValid);
  checkoutHelp?.classList.toggle("error", !(nameValid && emailValid && phoneValid && otpValid));
  return nameValid && emailValid && phoneValid && otpValid;
}

document.querySelectorAll(".choose-plan-btn").forEach((button) => {
  button.addEventListener("click", () => {
    selectedPublicPlan = button.dataset.planId || "";
    document.getElementById("checkoutPlanName").textContent = button.dataset.planName || "Selected Plan";
    document.getElementById("checkoutPlanPrice").textContent = button.dataset.planPrice || "";
    checkoutPanel?.classList.add("active");
    setCheckoutStatus("", false);
    checkoutPanel?.scrollIntoView({behavior: "smooth", block: "nearest"});
    document.getElementById("publicCustomerName")?.focus();
  });
});

document.getElementById("sendPublicOtpBtn")?.addEventListener("click", async (event) => {
  const email = document.getElementById("publicCustomerEmail")?.value.trim() || "";
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    setCheckoutStatus("Enter a valid customer email before sending OTP.");
    document.getElementById("publicCustomerEmail")?.focus();
    return;
  }
  const button = event.currentTarget;
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = "Sending...";
  const response = await fetch("/api.php?action=send_email_otp", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({email, flow: "public_subscription"})
  });
  const data = await response.json().catch(() => ({}));
  button.disabled = false;
  button.textContent = originalText;
  setCheckoutStatus(data.message || (data.success ? "Verification code sent." : "OTP could not be sent."));
});

["publicCustomerName", "publicCustomerEmail", "publicCustomerPhone"].forEach((id) => {
  document.getElementById(id)?.addEventListener("input", (event) => {
    event.currentTarget.classList.remove("error");
    checkoutHelp?.classList.remove("error");
  });
});

document.getElementById("publicSubscriptionForm")?.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (!selectedPublicPlan) {
    setCheckoutStatus("Please select a plan first.");
    return;
  }
  if (!validatePublicCheckout()) {
    setCheckoutStatus("Please enter customer name, valid email, and mobile number with country code.");
    return;
  }
  if (!window.Razorpay) {
    setCheckoutStatus("Razorpay checkout could not be loaded. Please refresh and try again.");
    return;
  }
  const button = document.getElementById("publicPayBtn");
  const originalText = button.textContent;
  const payload = {
    plan_id: selectedPublicPlan,
    name: document.getElementById("publicCustomerName").value.trim(),
    email: document.getElementById("publicCustomerEmail").value.trim(),
    contact: document.getElementById("publicCustomerPhone").value.trim(),
    email_otp: document.getElementById("publicEmailOtp").value.trim()
  };
  button.disabled = true;
  button.textContent = "Creating order...";
  setCheckoutStatus("Creating secure payment order...");
  try {
    const orderResponse = await fetch("/api.php?action=create_public_razorpay_order", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify(payload)
    });
    const orderData = await orderResponse.json().catch(() => ({}));
    button.disabled = false;
    button.textContent = originalText;
    if (!orderData.success) {
      if (orderData.requires_login && orderData.login_url) {
        setCheckoutStatus(orderData.message || "Please login to upgrade your existing chatbot.");
        setTimeout(() => { window.location.href = orderData.login_url; }, 900);
        return;
      }
      setCheckoutStatus(orderData.message || "Payment could not be started.");
      return;
    }
    const checkout = new Razorpay({
      key: orderData.key_id,
      amount: orderData.order.amount,
      currency: orderData.order.currency || "INR",
      name: "Vani AI",
      description: `${orderData.plan.name} wallet recharge`,
      order_id: orderData.order.id,
      remember_customer: true,
      prefill: orderData.prefill || payload,
      theme: {color: "#6366f1"},
      handler: async (response) => {
        setCheckoutStatus("Payment received. Activating wallet benefits and sending login email...");
        const verifyResponse = await fetch("/api.php?action=verify_public_razorpay_payment", {
          method: "POST",
          headers: {"Content-Type": "application/json"},
          body: JSON.stringify(response)
        });
        const verifyData = await verifyResponse.json().catch(() => ({}));
        if (!verifyData.success) {
          setCheckoutStatus(verifyData.message || "Payment verification failed.");
          return;
        }
        setCheckoutStatus("Wallet recharge successful. Please check your email for login details.");
        setTimeout(() => {
          window.location.href = "login.php?subscription=success";
        }, 1300);
      }
    });
    checkout.on("payment.failed", async (response) => {
      await recordPublicRazorpayFailure(orderData.order.id, response);
      setCheckoutStatus(publicRazorpayFailureMessage(response));
    });
    checkout.open();
  } catch (error) {
    button.disabled = false;
    button.textContent = originalText;
    setCheckoutStatus("Something went wrong. Please try again.");
  }
});
</script>
</body>
</html>
