
const tabs = document.querySelectorAll(".tab-btn");
const panels = document.querySelectorAll(".tab-panel");
const toast = document.getElementById("toast");
const themeToggle = document.getElementById("themeToggle");
const navToggle = document.getElementById("navToggle");
const accountToggle = document.getElementById("accountToggle");
const drawerOverlay = document.getElementById("drawerOverlay");
const accountToggleText = accountToggle?.textContent || "";
let currentFaqCount = 0;
const freeFaqLimit = 25;
const faqLimitIsUnlimited = false;
const faqLimitLabel = "25";
const selectedCustomerId = "";
const billingEmail = "codex@test.local";
const leadPaidFeatures = {"email_otp":false,"mobile_otp":false,"whatsapp_redirect":false};
const businessFeatures = {"api_access":false,"webhook_support":false,"human_handoff":false,"allowed_domains":false,"live_chat_actions":false,"faq_action_suggestions":false};
const leadWalletCharges = {"fresh_email_lead":0,"repeat_email_lead":0,"reactivated_email_lead":0,"fresh_mobile_lead":0,"repeat_mobile_lead":0,"reactivated_mobile_lead":0,"whatsapp_redirect_addon":0};
const whatsappRedirectLockedOn = false;
const whatsappRedirectLocked = false;
const walletBalancePaise = 0;
const whatsappRedirectChargePaise = 0;
const analyticsReport = {"bot_name":"Vani Bot","range_label":"Last 30 days","date_from":"2026-04-24","date_to":"2026-05-23","previous_date_from":"2026-03-23","previous_date_to":"2026-04-22","summary":{"total_conversations":0,"total_messages":0,"unique_visitors":0,"answered_queries_percent":0,"unanswered_queries_percent":0,"avg_response_time_ms":0,"leads_collected":0,"unique_leads":0,"real_unique_leads":0,"weak_unique_leads":0,"otp_verified_leads":0,"active_chatbots":1,"most_active_page":"No data yet","returning_users_percent":0,"avg_conversation_duration":"No data yet"},"comparison":{"current":{"conversations":0,"messages":0,"answer_rate":0,"unanswered":0,"leads":0,"verified_leads":0,"lead_conversion":0,"visitors":0,"avg_response_time_ms":0},"previous":{"conversations":0,"messages":0,"answer_rate":0,"unanswered":0,"leads":0,"verified_leads":0,"lead_conversion":0,"visitors":0,"avg_response_time_ms":0}},"daily_counts":[],"hour_counts":[],"devices":[],"browsers":[],"countries":[],"cities":[],"lead_periods":[{"label":"Weekly","days":7,"count":0},{"label":"Monthly","days":30,"count":0},{"label":"Quarterly","days":90,"count":0},{"label":"Six months","days":182,"count":0},{"label":"Yearly","days":365,"count":0}],"unique_leads":[],"top_questions":[],"unanswered_questions":[],"conversations":[],"source_pages":[]};
analyticsReport.summary = analyticsReport.summary || {};
analyticsReport.comparison = analyticsReport.comparison || {current: {}, previous: {}};
analyticsReport.daily_counts = analyticsReport.daily_counts || {};
analyticsReport.hour_counts = analyticsReport.hour_counts || {};
analyticsReport.devices = analyticsReport.devices || {};
analyticsReport.browsers = analyticsReport.browsers || {};
analyticsReport.countries = analyticsReport.countries || {};
analyticsReport.cities = analyticsReport.cities || {};
analyticsReport.unique_leads = analyticsReport.unique_leads || [];
analyticsReport.top_questions = analyticsReport.top_questions || [];
analyticsReport.unanswered_questions = analyticsReport.unanswered_questions || [];
analyticsReport.conversations = analyticsReport.conversations || [];
analyticsReport.source_pages = analyticsReport.source_pages || [];

function setDrawer(type, open) {
  const isNav = type === "nav";
  document.body.classList.toggle("nav-open", isNav && open);
  document.body.classList.toggle("account-open", !isNav && open);
  drawerOverlay?.classList.toggle("show", open);
  navToggle?.setAttribute("aria-expanded", String(isNav && open));
  accountToggle?.setAttribute("aria-expanded", String(!isNav && open));
  accountToggle?.setAttribute("aria-label", !isNav && open ? "Close account menu" : "Open account menu");
  if (accountToggle) accountToggle.textContent = !isNav && open ? "x" : accountToggleText;
}

function closeDrawers() {
  document.body.classList.remove("nav-open", "account-open");
  drawerOverlay?.classList.remove("show");
  navToggle?.setAttribute("aria-expanded", "false");
  accountToggle?.setAttribute("aria-expanded", "false");
  accountToggle?.setAttribute("aria-label", "Open account menu");
  if (accountToggle) accountToggle.textContent = accountToggleText;
}

navToggle?.addEventListener("click", () => {
  setDrawer("nav", !document.body.classList.contains("nav-open"));
});

accountToggle?.addEventListener("click", () => {
  setDrawer("account", !document.body.classList.contains("account-open"));
});

drawerOverlay?.addEventListener("click", closeDrawers);

document.addEventListener("keydown", event => {
  if (event.key === "Escape") closeDrawers();
});

function showToast(text) {
  toast.textContent = text;
  toast.classList.add("show");
  setTimeout(() => toast.classList.remove("show"), 1800);
}

function htmlEscape(value) {
  return String(value ?? "").replace(/[&<>"']/g, char => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[char]));
}

function openTab(id, updateHash = true) {
  tabs.forEach(tab => tab.classList.toggle("active", tab.dataset.tab === id));
  panels.forEach(panel => panel.classList.toggle("active", panel.id === id));
  document.querySelector(`.tab-btn[data-tab="${id}"]`)?.scrollIntoView({
    block: "nearest",
    inline: "nearest",
    behavior: "smooth"
  });
  if (updateHash && location.hash !== "#" + id) history.replaceState(null, "", "#" + id);
  closeDrawers();
}

tabs.forEach(tab => tab.addEventListener("click", () => openTab(tab.dataset.tab)));

function bindBillingRefresh() {
  document.getElementById("refreshBillingBtn")?.addEventListener("click", async event => {
    const button = event.currentTarget;
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = "Refreshing...";
    try {
      const response = await fetch(window.location.pathname + window.location.search, {cache: "no-store"});
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, "text/html");
      const freshBilling = doc.getElementById("billing");
      const currentBilling = document.getElementById("billing");
      if (!freshBilling || !currentBilling) throw new Error("Billing tab not found");
      currentBilling.innerHTML = freshBilling.innerHTML;
      bindBillingRefresh();
      bindRazorpayCustomerSetup();
      bindAutoRechargeMandate();
      showToast("Billing refreshed");
    } catch (error) {
      button.disabled = false;
      button.textContent = originalText;
      showToast("Billing could not be refreshed");
    }
  });
}

function bindRazorpayCustomerSetup() {
  document.getElementById("createRazorpayCustomerBtn")?.addEventListener("click", async event => {
    const button = event.currentTarget;
    if (button.disabled) return;
    const nameInput = document.getElementById("razorpayCustomerNameInput");
    const contactInput = document.getElementById("razorpayCustomerContactInput");
    const name = nameInput?.value.trim() || "";
    const contact = contactInput?.value.trim() || "";
    if (!selectedCustomerId) return showToast("Select or create a bot first");
    if (name.length < 3) return showToast("Enter customer name");
    if (!contact) return showToast("Enter mobile number with country code");
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = "Creating...";
    try {
      const response = await fetch("/api.php?action=create_razorpay_customer", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({customer_id: selectedCustomerId, name, contact})
      });
      const data = await response.json();
      if (!data.success) {
        button.disabled = false;
        button.textContent = originalText;
        return showToast(data.message || "Razorpay customer could not be created");
      }
      document.getElementById("razorpayCustomerIdText").textContent = data.razorpay_customer_id || "Linked";
      document.getElementById("razorpayCustomerContactText").textContent = data.contact || contact;
      const tag = document.getElementById("razorpayCustomerStatusTag");
      if (tag) {
        tag.textContent = "Linked";
        tag.classList.remove("bad");
        tag.classList.add("good");
      }
      if (nameInput) nameInput.disabled = true;
      if (contactInput) contactInput.disabled = true;
      button.textContent = "Customer Linked";
      showToast("Razorpay customer linked");
    } catch (error) {
      button.disabled = false;
      button.textContent = originalText;
      showToast("Razorpay customer could not be created");
    }
  });
}

function bindAutoRechargeMandate() {
  document.getElementById("authorizeAutoRechargeBtn")?.addEventListener("click", async event => {
    const button = event.currentTarget;
    if (button.disabled) return;
    if (!selectedCustomerId) return showToast("Select or create a bot first");
    if (!window.Razorpay) return showToast("Razorpay checkout could not be loaded");
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = "Creating mandate...";
    try {
      const orderResponse = await fetch("/api.php?action=create_auto_recharge_mandate_order", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({customer_id: selectedCustomerId})
      });
      const orderData = await orderResponse.json().catch(() => ({}));
      if (!orderData.success) {
        button.disabled = false;
        button.textContent = originalText;
        if (orderData.requires_customer) document.getElementById("createRazorpayCustomerBtn")?.focus();
        return showToast(orderData.message || "Mandate order could not be created");
      }
      button.disabled = false;
      button.textContent = originalText;
      const checkout = new Razorpay({
        key: orderData.key_id,
        amount: orderData.order.amount,
        currency: orderData.order.currency || "INR",
        name: "Vani AI",
        description: `${orderData.plan.name} auto wallet recharge mandate`,
        order_id: orderData.order.id,
        customer_id: orderData.razorpay_customer_id,
        recurring: true,
        remember_customer: true,
        prefill: {
          email: billingEmail,
          contact: orderData.contact || ""
        },
        readonly: {email: true},
        theme: {color: "#6366f1"},
        handler: async response => {
          showToast("Verifying mandate...");
          const verifyResponse = await fetch("/api.php?action=verify_auto_recharge_mandate", {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify(response)
          });
          const verifyData = await verifyResponse.json().catch(() => ({}));
          if (!verifyData.success) {
            showToast(verifyData.message || "Mandate verification failed");
            return;
          }
          document.getElementById("autoRechargeMandateStatusText").textContent = "Active";
          document.getElementById("autoRechargeTokenText").textContent = verifyData.token_id ? `${verifyData.token_id.slice(0, 10)}...` : "Saved";
          const tag = document.getElementById("autoRechargeMandateStatusTag");
          if (tag) {
            tag.textContent = "Ready";
            tag.classList.remove("bad");
            tag.classList.add("good");
          }
          button.disabled = true;
          button.textContent = "Auto Recharge Ready";
          showToast(verifyData.wallet_credited ? "Mandate active and wallet credited" : "Mandate active");
          setTimeout(() => location.reload(), 900);
        }
      });
      checkout.on("payment.failed", response => {
        showToast(response.error?.description || "Mandate authorization failed");
      });
      checkout.open();
    } catch (error) {
      button.disabled = false;
      button.textContent = originalText;
      showToast("Mandate setup could not be started");
    }
  });
}

bindBillingRefresh();
bindRazorpayCustomerSetup();
bindAutoRechargeMandate();

function openAnalyticsTab(id, updateHash = true) {
  let target = document.getElementById(id) ? id : "analytics-overview";
  const targetButton = document.querySelector(`.analytics-tab-btn[data-analytics-tab="${target}"]`);
  if (targetButton?.dataset.premiumLock) {
    alert(targetButton.dataset.premiumLock);
    target = "analytics-overview";
    openTab("subscription");
  }
  document.querySelectorAll(".analytics-tab-btn").forEach(tab => {
    tab.classList.toggle("active", tab.dataset.analyticsTab === target);
  });
  document.querySelectorAll(".analytics-subpanel").forEach(panel => {
    panel.classList.toggle("active", panel.id === target);
  });
  if (updateHash) history.replaceState(null, "", "#analytics/" + target.replace("analytics-", ""));
}

document.querySelectorAll(".analytics-tab-btn").forEach(tab => {
  tab.addEventListener("click", () => {
    if (tab.dataset.premiumLock) {
      alert(tab.dataset.premiumLock);
      openTab("subscription");
      return;
    }
    openAnalyticsTab(tab.dataset.analyticsTab);
  });
});

function openFaqSubtab(target) {
  if (!document.getElementById(target)) return;
  document.querySelectorAll(".faq-subtab-btn").forEach(item => {
    item.classList.toggle("active", item.dataset.faqSubtab === target);
  });
  document.querySelectorAll("#faqs .faq-subpanel").forEach(panel => {
    panel.classList.toggle("active", panel.id === target);
  });
}

document.querySelectorAll(".faq-subtab-btn").forEach(tab => {
  tab.addEventListener("click", () => {
    openFaqSubtab(tab.dataset.faqSubtab || "faq-subpanel-options");
  });
});

function openIntegrationSubtab(target) {
  if (!document.getElementById(target)) return;
  document.querySelectorAll(".integration-subtab-btn").forEach(item => {
    item.classList.toggle("active", item.dataset.integrationSubtab === target);
  });
  document.querySelectorAll("#install .integration-subpanel").forEach(panel => {
    panel.classList.toggle("active", panel.id === target);
  });
}

document.querySelectorAll(".integration-subtab-btn").forEach(tab => {
  tab.addEventListener("click", () => {
    openIntegrationSubtab(tab.dataset.integrationSubtab || "integration-subpanel-install");
  });
});

const analyticsHash = location.hash.startsWith("#analytics/") ? location.hash.split("/")[1] : "";
if (analyticsHash) {
  openTab("analytics", false);
  openAnalyticsTab("analytics-" + analyticsHash, false);
}

function analyticsEntries(objectValue) {
  return Object.entries(objectValue || {}).filter(([, value]) => Number(value) > 0);
}

function formatDelta(current, previous) {
  if (!previous && !current) return {text: "No trend yet", state: "flat"};
  if (!previous) return {text: "+100% vs earlier", state: ""};
  const pct = Math.round(((current - previous) / Math.max(1, previous)) * 100);
  return {text: `${pct >= 0 ? "+" : ""}${pct}% vs earlier`, state: pct > 0 ? "" : (pct < 0 ? "down" : "flat")};
}

function updateAnalyticsDeltas() {
  const current = analyticsReport.comparison?.current || {};
  const previous = analyticsReport.comparison?.previous || {};
  const lowerIsBetter = new Set(["avg_response_time_ms", "unanswered"]);
  document.querySelectorAll("[data-kpi]").forEach(card => {
    const key = card.dataset.kpi;
    const badge = card.querySelector(".bi-delta");
    if (!key || !badge || !(key in current)) return;
    const delta = formatDelta(Number(current[key] || 0), Number(previous[key] || 0));
    const isDown = delta.state === "down";
    const isFlat = delta.state === "flat";
    badge.textContent = delta.text;
    badge.classList.toggle("flat", isFlat);
    badge.classList.toggle("down", lowerIsBetter.has(key) ? (!isFlat && !isDown) : isDown);
  });
}

function chartPalette() {
  return {
    brand: getComputedStyle(document.documentElement).getPropertyValue("--brand").trim() || "#6366f1",
    brand2: getComputedStyle(document.documentElement).getPropertyValue("--brand-2").trim() || "#06b6d4"
  };
}

function renderLineChart(targetId, dataObject) {
  const target = document.getElementById(targetId);
  if (!target) return;
  const entries = Object.entries(dataObject || {});
  if (!entries.length) {
    target.innerHTML = '<div class="bi-chart-empty">No trend data yet</div>';
    return;
  }
  const width = 720;
  const height = 280;
  const pad = {top: 22, right: 18, bottom: 38, left: 42};
  const values = entries.map(([, value]) => Number(value) || 0);
  const maxValue = Math.max(1, ...values);
  const xStep = entries.length > 1 ? (width - pad.left - pad.right) / (entries.length - 1) : 0;
  const points = entries.map(([, value], index) => {
    const x = pad.left + (entries.length > 1 ? index * xStep : (width - pad.left - pad.right) / 2);
    const y = height - pad.bottom - ((Number(value) || 0) / maxValue) * (height - pad.top - pad.bottom);
    return [x, y];
  });
  const path = points.map(([x, y], index) => `${index ? "L" : "M"} ${x.toFixed(1)} ${y.toFixed(1)}`).join(" ");
  const area = `${path} L ${points[points.length - 1][0].toFixed(1)} ${height - pad.bottom} L ${points[0][0].toFixed(1)} ${height - pad.bottom} Z`;
  const labels = entries.filter((_, index) => index === 0 || index === entries.length - 1 || index % Math.ceil(entries.length / 5) === 0);
  target.innerHTML = `
    <svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Conversation trend chart">
      <defs>
        <linearGradient id="biLineGradient" x1="0" x2="1"><stop offset="0" stop-color="${chartPalette().brand}"/><stop offset="1" stop-color="${chartPalette().brand2}"/></linearGradient>
        <linearGradient id="biAreaGradient" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="${chartPalette().brand}" stop-opacity=".24"/><stop offset="1" stop-color="${chartPalette().brand2}" stop-opacity=".02"/></linearGradient>
      </defs>
      <line class="axis" x1="${pad.left}" y1="${height - pad.bottom}" x2="${width - pad.right}" y2="${height - pad.bottom}"></line>
      <line class="axis" x1="${pad.left}" y1="${pad.top}" x2="${pad.left}" y2="${height - pad.bottom}"></line>
      ${[0.25,0.5,0.75].map(t => `<line class="grid" x1="${pad.left}" y1="${pad.top + t * (height - pad.top - pad.bottom)}" x2="${width - pad.right}" y2="${pad.top + t * (height - pad.top - pad.bottom)}"></line>`).join("")}
      <path class="area" d="${area}"></path>
      <path class="line" d="${path}"></path>
      ${points.map(([x, y]) => `<circle class="point" cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="3"></circle>`).join("")}
      ${labels.map(([label], index) => `<text x="${pad.left + (entries.length > 1 ? entries.findIndex(([key]) => key === label) * xStep : 0)}" y="${height - 14}" text-anchor="${index === 0 ? "start" : "middle"}">${htmlEscape(label.slice(5) || label)}</text>`).join("")}
      <text x="12" y="${pad.top + 4}">${maxValue}</text>
      <text x="18" y="${height - pad.bottom}">0</text>
    </svg>`;
}

function renderBarChart(targetId, dataObject) {
  const target = document.getElementById(targetId);
  if (!target) return;
  const entries = analyticsEntries(dataObject).slice(0, 7);
  if (!entries.length) {
    target.innerHTML = '<div class="bi-chart-empty">No mix data yet</div>';
    return;
  }
  const width = 420;
  const height = 280;
  const pad = {top: 20, right: 22, bottom: 34, left: 98};
  const maxValue = Math.max(1, ...entries.map(([, value]) => Number(value) || 0));
  const rowHeight = (height - pad.top - pad.bottom) / entries.length;
  target.innerHTML = `
    <svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Device mix chart">
      <defs><linearGradient id="biBarGradient" x1="0" x2="1"><stop offset="0" stop-color="${chartPalette().brand}"/><stop offset="1" stop-color="${chartPalette().brand2}"/></linearGradient></defs>
      ${entries.map(([label, value], index) => {
        const y = pad.top + index * rowHeight + 7;
        const barWidth = Math.max(4, (Number(value) / maxValue) * (width - pad.left - pad.right));
        return `<text x="8" y="${y + 16}">${htmlEscape(String(label).slice(0, 14))}</text><rect class="bar" x="${pad.left}" y="${y}" width="${barWidth.toFixed(1)}" height="${Math.max(13, rowHeight - 14).toFixed(1)}"></rect><text x="${pad.left + barWidth + 8}" y="${y + 16}">${htmlEscape(value)}</text>`;
      }).join("")}
    </svg>`;
}

const countryCoordinates = {
  "india": [78.96, 20.59],
  "united states": [-95.71, 37.09],
  "usa": [-95.71, 37.09],
  "us": [-95.71, 37.09],
  "canada": [-106.35, 56.13],
  "united kingdom": [-3.44, 55.38],
  "uk": [-3.44, 55.38],
  "australia": [133.78, -25.27],
  "germany": [10.45, 51.17],
  "france": [2.21, 46.23],
  "spain": [-3.75, 40.46],
  "italy": [12.57, 41.87],
  "netherlands": [5.29, 52.13],
  "brazil": [-51.93, -14.24],
  "mexico": [-102.55, 23.63],
  "china": [104.2, 35.86],
  "japan": [138.25, 36.2],
  "singapore": [103.82, 1.35],
  "united arab emirates": [53.85, 23.42],
  "uae": [53.85, 23.42],
  "saudi arabia": [45.08, 23.89],
  "south africa": [22.94, -30.56],
  "nigeria": [8.68, 9.08],
  "kenya": [37.91, -0.02],
  "russia": [105.32, 61.52],
  "indonesia": [113.92, -0.79],
  "malaysia": [101.98, 4.21],
  "philippines": [121.77, 12.88],
  "thailand": [100.99, 15.87],
  "vietnam": [108.28, 14.06],
  "pakistan": [69.35, 30.38],
  "bangladesh": [90.36, 23.68],
  "sri lanka": [80.77, 7.87],
  "nepal": [84.12, 28.39]
};

function normalizeCountryName(value) {
  return String(value || "").trim().toLowerCase();
}

function projectCountry(lon, lat, width, height) {
  return [
    ((lon + 180) / 360) * width,
    ((90 - lat) / 180) * height
  ];
}

function renderCountryMap() {
  const target = document.getElementById("biCountryMap");
  const legend = document.getElementById("biCountryMapLegend");
  if (!target) return;
  const entries = analyticsEntries(analyticsReport.countries).sort((a, b) => Number(b[1]) - Number(a[1]));
  if (!entries.length) {
    target.innerHTML = '<div class="bi-chart-empty">No country data yet</div>';
    if (legend) legend.innerHTML = "";
    return;
  }
  const width = 900;
  const height = 430;
  const maxValue = Math.max(1, ...entries.map(([, value]) => Number(value) || 0));
  const land = [
    "M126 115 C188 72 264 84 310 125 C270 160 211 162 164 188 C125 174 93 148 126 115 Z",
    "M240 206 C292 174 363 186 403 236 C357 295 274 306 219 265 C203 239 214 220 240 206 Z",
    "M430 116 C500 83 596 95 657 145 C620 180 536 181 480 166 C448 158 420 143 430 116 Z",
    "M478 183 C548 166 620 190 654 245 C602 277 523 270 480 232 C458 212 455 193 478 183 Z",
    "M581 255 C624 239 682 263 702 313 C667 363 602 350 574 304 C564 285 566 267 581 255 Z",
    "M690 168 C757 135 822 153 852 210 C817 244 749 237 704 207 C682 192 677 178 690 168 Z",
    "M705 298 C759 270 826 288 852 342 C824 383 750 382 708 342 C692 325 690 309 705 298 Z"
  ];
  const bubbles = entries.map(([country, count]) => {
    const coords = countryCoordinates[normalizeCountryName(country)];
    if (!coords) return "";
    const [x, y] = projectCountry(coords[0], coords[1], width, height);
    const radius = 8 + Math.sqrt(Number(count) / maxValue) * 24;
    return `<g data-drill="country" data-value="${htmlEscape(country)}"><circle class="map-bubble" cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="${radius.toFixed(1)}"><title>${htmlEscape(country)}: ${htmlEscape(count)}</title></circle><text x="${x.toFixed(1)}" y="${(y - radius - 6).toFixed(1)}" text-anchor="middle">${htmlEscape(count)}</text></g>`;
  }).join("");
  target.innerHTML = `
    <svg viewBox="0 0 ${width} ${height}" role="img" aria-label="World map country counts">
      ${[1,2,3,4].map(i => `<line class="map-grid" x1="0" x2="${width}" y1="${i * height / 5}" y2="${i * height / 5}"></line>`).join("")}
      ${[1,2,3,4,5].map(i => `<line class="map-grid" y1="0" y2="${height}" x1="${i * width / 6}" x2="${i * width / 6}"></line>`).join("")}
      ${land.map(path => `<path class="land" d="${path}"></path>`).join("")}
      ${bubbles}
    </svg>`;
  if (legend) {
    legend.innerHTML = entries.slice(0, 8).map(([country, count]) => `<span data-drill="country" data-value="${htmlEscape(country)}"><i></i>${htmlEscape(country)}: ${htmlEscape(count)}</span>`).join("");
  }
}

function closeAnalyticsDrill() {
  const drawer = document.getElementById("analyticsDrillDrawer");
  drawer?.classList.remove("active");
  drawer?.setAttribute("aria-hidden", "true");
}

function conversationMatches(row, type, value) {
  if (type === "answered") return !!row.answered;
  if (type === "slow") return Number(row.response_time_ms || 0) >= Number(analyticsReport.summary?.avg_response_time_ms || 0);
  if (type === "question") return String(row.question || "") === value;
  if (type === "page") return String(row.source_page || "") === value;
  if (type === "country") return String(row.country || "").toLowerCase() === String(value || "").toLowerCase();
  return true;
}

function drillRowsFor(type, value) {
  if (type === "leads") return analyticsReport.unique_leads || [];
  if (type === "countries") return analyticsEntries(analyticsReport.countries).map(([country, count]) => ({country, count}));
  return (analyticsReport.conversations || []).filter(row => conversationMatches(row, type, value));
}

function drillTitle(type, value) {
  const labels = {
    conversations: "Conversation Details",
    answered: "Answered Conversations",
    slow: "Slowest Conversations",
    question: "Question Drill Down",
    page: "Page Drill Down",
    country: "Country Drill Down",
    countries: "Country Distribution",
    leads: "Lead Details"
  };
  return value ? `${labels[type] || "Analytics Details"}: ${value}` : (labels[type] || "Analytics Details");
}

function renderDrillItems(type, rows) {
  if (!rows.length) return '<p class="empty">No matching data in the selected range.</p>';
  if (type === "leads") {
    return `<div class="drill-list">${rows.slice(0, 80).map(row => `<div class="drill-item"><strong>${htmlEscape(row.lead_type || "Lead")} lead</strong><small>${htmlEscape(row.email || "-")} | ${htmlEscape(row.phone_number || "-")}</small><small>Captures: ${htmlEscape(row.total_records || 0)} | WhatsApp: ${htmlEscape(row.whatsapp_redirect_count || 0)} | Last seen: ${htmlEscape(row.last_seen || "-")}</small></div>`).join("")}</div>`;
  }
  if (type === "countries") {
    return `<div class="drill-list">${rows.map(row => `<div class="drill-item"><strong>${htmlEscape(row.country)}</strong><small>${htmlEscape(row.count)} tracked sessions/conversations</small></div>`).join("")}</div>`;
  }
  return `<div class="drill-list">${rows.slice(0, 80).map(row => `<div class="drill-item"><strong>${htmlEscape(row.question || "Conversation")}</strong><small>${htmlEscape(row.created_at || "")} | ${htmlEscape(row.source_page || "Unknown page")} | ${htmlEscape(row.country || "Unknown country")}</small><small>Status: ${htmlEscape(row.status || (row.answered ? "answered" : "unanswered"))} | Response: ${htmlEscape(row.response_time_ms || "-")}ms</small></div>`).join("")}</div>`;
}

function openAnalyticsDrill(type, value = "") {
  const drawer = document.getElementById("analyticsDrillDrawer");
  const title = document.getElementById("analyticsDrillTitle");
  const subtitle = document.getElementById("analyticsDrillSubtitle");
  const body = document.getElementById("analyticsDrillBody");
  if (!drawer || !title || !body) return;
  const rows = drillRowsFor(type, value);
  title.textContent = drillTitle(type, value);
  if (subtitle) subtitle.textContent = `${analyticsReport.date_from} to ${analyticsReport.date_to} | Previous: ${analyticsReport.previous_date_from} to ${analyticsReport.previous_date_to}`;
  body.innerHTML = `
    <div class="drill-summary">
      <span>Rows<strong>${htmlEscape(rows.length)}</strong></span>
      <span>Current period<strong>${htmlEscape(analyticsReport.range_label || "")}</strong></span>
      <span>Bot<strong>${htmlEscape(analyticsReport.bot_name || "Vani")}</strong></span>
    </div>
    ${renderDrillItems(type, rows)}
  `;
  drawer.classList.add("active");
  drawer.setAttribute("aria-hidden", "false");
}

document.addEventListener("click", event => {
  if (!(event.target instanceof Element)) return;
  const trigger = event.target.closest("[data-drill]");
  if (!trigger) return;
  event.preventDefault();
  openAnalyticsDrill(trigger.dataset.drill || "conversations", trigger.dataset.value || "");
});

document.getElementById("closeAnalyticsDrillBtn")?.addEventListener("click", closeAnalyticsDrill);
document.getElementById("analyticsDrillDrawer")?.addEventListener("click", event => {
  if (event.target.id === "analyticsDrillDrawer") closeAnalyticsDrill();
});

function renderAnalyticsCommandCenter() {
  try {
    updateAnalyticsDeltas();
    renderLineChart("biConversationTrend", analyticsReport.daily_counts);
    renderBarChart("biDeviceMix", Object.keys(analyticsReport.devices || {}).length ? analyticsReport.devices : analyticsReport.browsers);
    renderCountryMap();
  } catch (error) {
    console.error("Analytics render failed", error);
  }
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", renderAnalyticsCommandCenter, {once: true});
} else {
  renderAnalyticsCommandCenter();
}

document.querySelectorAll("[data-jump]").forEach(btn => {
  btn.addEventListener("click", event => {
    const target = btn.dataset.jump;
    if (target) {
      event.preventDefault();
      openTab(target);
      if (target === "faqs") {
        openFaqSubtab("faq-subpanel-qa");
      }
      if (btn.dataset.question) {
        document.getElementById("faqQuestion").value = btn.dataset.question;
      }
      window.scrollTo({top:0, behavior:"smooth"});
    }
  });
});

document.querySelectorAll(".copy-btn").forEach(btn => {
  btn.addEventListener("click", async () => {
    const text = btn.dataset.copy || document.getElementById("embedCode")?.textContent || "";
    if (!text.trim()) return showToast("Nothing to copy yet");
    await navigator.clipboard.writeText(text);
    showToast("Copied to clipboard");
  });
});

function reportFileBase() {
  const bot = (analyticsReport.bot_name || "vani").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
  return `${bot || "vani"}-analytics-${analyticsReport.date_from}-to-${analyticsReport.date_to}`;
}

function downloadBlob(filename, content, type) {
  const blob = new Blob([content], {type});
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function csvValue(value) {
  const text = String(value ?? "");
  return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

function rowsToCsv(rows) {
  return rows.map(row => row.map(csvValue).join(",")).join("\n");
}

function analyticsCsv() {
  const rows = [
    ["Section", "Metric", "Value"],
    ...Object.entries(analyticsReport.summary || {}).map(([key, value]) => ["Summary", key, value]),
    [],
    ["Daily Counts", "Date", "Conversations"],
    ...Object.entries(analyticsReport.daily_counts || {}).map(([date, count]) => ["Daily Counts", date, count]),
    [],
    ["Hourly Counts", "Hour", "Queries"],
    ...Object.entries(analyticsReport.hour_counts || {}).map(([hour, count]) => ["Hourly Counts", `${hour}:00`, count]),
    [],
    ["Devices", "Device", "Count"],
    ...Object.entries(analyticsReport.devices || {}).map(([device, count]) => ["Devices", device, count]),
    [],
    ["Browsers", "Browser", "Count"],
    ...Object.entries(analyticsReport.browsers || {}).map(([browser, count]) => ["Browsers", browser, count]),
    [],
    ["Countries", "Country", "Count"],
    ...Object.entries(analyticsReport.countries || {}).map(([country, count]) => ["Countries", country, count]),
    [],
    ["Top Questions", "Question", "Count", "Success Rate"],
    ...(analyticsReport.top_questions || []).map(item => ["Top Questions", item.question, item.count, `${item.success_rate}%`]),
    [],
    ["Unanswered Questions", "Question", "Source Page", "Date"],
    ...(analyticsReport.unanswered_questions || []).map(item => ["Unanswered Questions", item.question, item.source_page, item.date]),
    [],
    ["Unique Leads", "Lead Type", "Email", "Mobile Number", "Email OTP Count", "Mobile OTP Count", "Total Captures", "WhatsApp Clicks", "Source Pages", "Location", "First Seen", "Last Seen"],
    ...(analyticsReport.unique_leads || []).map(item => ["Unique Leads", item.lead_type, item.email, item.phone_number, item.email_otp_count, item.mobile_otp_count, item.total_records, item.whatsapp_redirect_count, item.source_pages, item.location, item.first_seen, item.last_seen]),
    [],
    ["Lead Periods", "Period", "Days", "Unique Leads"],
    ...(analyticsReport.lead_periods || []).map(item => ["Lead Periods", item.label, item.days, item.count]),
    [],
    ["Source Pages", "Page", "Conversations", "Leads", "Success Rate"],
    ...(analyticsReport.source_pages || []).map(item => ["Source Pages", item.page, item.conversations, item.leads, `${item.success_rate}%`])
  ];
  return rowsToCsv(rows);
}

function analyticsReportHtml() {
  const esc = value => String(value ?? "").replace(/[&<>"']/g, char => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[char]));
  const summaryRows = Object.entries(analyticsReport.summary || {})
    .map(([key, value]) => `<tr><th>${esc(key.replace(/_/g, " "))}</th><td>${esc(value)}</td></tr>`).join("");
  const table = (title, headers, rows) => `
    <h2>${esc(title)}</h2>
    <table><thead><tr>${headers.map(header => `<th>${esc(header)}</th>`).join("")}</tr></thead>
    <tbody>${rows.length ? rows.join("") : `<tr><td colspan="${headers.length}">No data</td></tr>`}</tbody></table>`;
  return `<!doctype html>
<html><head><meta charset="utf-8"><title>Vani Analytics Report</title>
<style>body{font-family:Arial,sans-serif;margin:32px;color:#111827}h1{margin-bottom:4px}p{color:#4b5563}table{width:100%;border-collapse:collapse;margin:16px 0 28px}th,td{text-align:left;border:1px solid #e5e7eb;padding:9px 10px;font-size:13px}th{background:#f8fafc}.muted{color:#64748b}</style>
</head><body>
<h1>Vani Analytics Report</h1>
<p>${esc(analyticsReport.bot_name)} | ${esc(analyticsReport.range_label)} | ${esc(analyticsReport.date_from)} to ${esc(analyticsReport.date_to)}</p>
<h2>Summary</h2><table><tbody>${summaryRows}</tbody></table>
${table("Top Questions", ["Question", "Count", "Success Rate"], (analyticsReport.top_questions || []).map(item => `<tr><td>${esc(item.question)}</td><td>${esc(item.count)}</td><td>${esc(item.success_rate)}%</td></tr>`))}
${table("Unanswered Questions", ["Question", "Source Page", "Date"], (analyticsReport.unanswered_questions || []).map(item => `<tr><td>${esc(item.question)}</td><td>${esc(item.source_page)}</td><td>${esc(item.date)}</td></tr>`))}
${table("Unique Leads", ["Type", "Email", "Mobile", "Email OTP", "Mobile OTP", "Captures", "WhatsApp", "Source Pages", "First Seen", "Last Seen"], (analyticsReport.unique_leads || []).map(item => `<tr><td>${esc(item.lead_type)}</td><td>${esc(item.email)}</td><td>${esc(item.phone_number)}</td><td>${esc(item.email_otp_count)}</td><td>${esc(item.mobile_otp_count)}</td><td>${esc(item.total_records)}</td><td>${esc(item.whatsapp_redirect_count)}</td><td>${esc(item.source_pages)}</td><td>${esc(item.first_seen)}</td><td>${esc(item.last_seen)}</td></tr>`))}
${table("Source Pages", ["Page", "Conversations", "Leads", "Success Rate"], (analyticsReport.source_pages || []).map(item => `<tr><td>${esc(item.page)}</td><td>${esc(item.conversations)}</td><td>${esc(item.leads)}</td><td>${esc(item.success_rate)}%</td></tr>`))}
<p class="muted">Generated from the dashboard data currently loaded in your browser.</p>
</body></html>`;
}

document.getElementById("exportAnalyticsCsvBtn")?.addEventListener("click", () => {
  downloadBlob(`${reportFileBase()}.csv`, analyticsCsv(), "text/csv;charset=utf-8");
  showToast("CSV report downloaded");
});

document.getElementById("downloadAnalyticsReportBtn")?.addEventListener("click", () => {
  downloadBlob(`${reportFileBase()}.html`, analyticsReportHtml(), "text/html;charset=utf-8");
  showToast("Report downloaded");
});

document.getElementById("downloadWeeklyReportBtn")?.addEventListener("click", () => {
  downloadBlob(`${reportFileBase()}-weekly.html`, analyticsReportHtml(), "text/html;charset=utf-8");
  showToast("Weekly report downloaded");
});

document.getElementById("downloadMonthlyReportBtn")?.addEventListener("click", () => {
  downloadBlob(`${reportFileBase()}-monthly.html`, analyticsReportHtml(), "text/html;charset=utf-8");
  showToast("Monthly report downloaded");
});

document.getElementById("printAnalyticsReportBtn")?.addEventListener("click", () => {
  const reportWindow = window.open("", "_blank");
  if (!reportWindow) return showToast("Allow popups to print the report");
  reportWindow.document.write(analyticsReportHtml());
  reportWindow.document.close();
  reportWindow.focus();
  reportWindow.print();
});

async function startPlanCheckout(planId, button) {
  if (!planId) {
    showToast("Select a subscription plan first");
    return;
  }
  if (!selectedCustomerId) {
    showToast("Select or create a bot before subscribing");
    return;
  }
  if (!window.Razorpay) {
    showToast("Razorpay checkout could not be loaded");
    return;
  }
  const paymentMode = document.querySelector('input[name="subscriptionPaymentMode"]:checked')?.value || "one_time";
  const customerName = document.getElementById("subscriptionAutoPayNameInput")?.value.trim() || "";
  const customerContact = document.getElementById("subscriptionAutoPayContactInput")?.value.trim() || "";
  if (paymentMode === "auto" && customerName.length < 3) {
    showToast("Enter customer name for automatic payment");
    document.getElementById("subscriptionAutoPayNameInput")?.focus();
    return;
  }
  if (paymentMode === "auto" && !customerContact) {
    showToast("Enter mobile number with country code");
    document.getElementById("subscriptionAutoPayContactInput")?.focus();
    return;
  }
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = paymentMode === "auto" ? "Creating auto payment..." : "Creating order...";

  const createAction = paymentMode === "auto" ? "create_razorpay_subscription_checkout" : "create_razorpay_order";
  const orderResponse = await fetch(`/api.php?action=${createAction}`, {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({
      plan_id: planId,
      customer_id: selectedCustomerId,
      name: customerName,
      contact: customerContact
    })
  });
  const orderData = await orderResponse.json().catch(() => ({}));
  button.disabled = false;
  button.textContent = originalText;

  if (!orderData.success) {
    showToast(orderData.message || "Payment could not be started");
    return;
  }

  const checkoutOptions = {
    key: orderData.key_id,
    name: "Vani AI",
    description: `${orderData.plan.name} ${paymentMode === "auto" ? "subscription with automatic payment" : "subscription"}`,
    remember_customer: true,
    prefill: {
      name: customerName,
      email: billingEmail,
      contact: orderData.contact || customerContact
    },
    readonly: {email: true},
    theme: {color: "#6366f1"},
    handler: async response => {
      showToast(paymentMode === "auto" ? "Verifying automatic payment..." : "Verifying payment...");
      const verifyAction = paymentMode === "auto" ? "verify_razorpay_subscription_payment" : "verify_razorpay_payment";
      const verifyResponse = await fetch(`/api.php?action=${verifyAction}`, {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify(response)
      });
      const verifyData = await verifyResponse.json().catch(() => ({}));
      if (!verifyData.success) {
        showToast(verifyData.message || "Payment verification failed");
        return;
      }
      showToast(paymentMode === "auto" ? "Plan activated with automatic payment" : "Plan activated");
      setTimeout(() => location.reload(), 900);
    }
  };
  if (paymentMode === "auto") {
    checkoutOptions.subscription_id = orderData.subscription_id;
  } else {
    checkoutOptions.amount = orderData.order.amount;
    checkoutOptions.currency = orderData.order.currency || "INR";
    checkoutOptions.order_id = orderData.order.id;
  }
  const checkout = new Razorpay(checkoutOptions);
  checkout.on("payment.failed", response => {
    showToast(response.error?.description || "Payment authorization failed");
  });
  checkout.open();
}

const subscriptionPlanLabels = {
  starter: {name: "Starter Plan", price: "₹199/month"},
  growth: {name: "Growth Plan", price: "₹499/month"},
  business: {name: "Business Plan", price: "₹999/month"}
};
let selectedSubscriptionPlanId = "";

function selectSubscriptionPlan(planId) {
  selectedSubscriptionPlanId = planId;
  document.querySelectorAll(".pricing-card").forEach(card => {
    const button = card.querySelector(".billing-plan-btn");
    const isSelected = button?.dataset.planId === planId;
    card.classList.toggle("plan-selected", isSelected);
    if (button) button.textContent = isSelected ? "Selected" : "Buy Subscription";
  });
  const panel = document.getElementById("subscriptionCheckoutPanel");
  const plan = subscriptionPlanLabels[planId] || {name: "Selected plan", price: ""};
  document.getElementById("selectedSubscriptionPlanName").textContent = plan.name;
  document.getElementById("selectedSubscriptionPlanPrice").textContent = plan.price;
  panel?.classList.add("active");
  panel?.scrollIntoView({behavior: "smooth", block: "nearest"});
}

document.querySelectorAll(".billing-plan-btn").forEach(button => {
  button.addEventListener("click", () => selectSubscriptionPlan(button.dataset.planId));
});

document.getElementById("continueSubscriptionPaymentBtn")?.addEventListener("click", event => {
  startPlanCheckout(selectedSubscriptionPlanId, event.currentTarget);
});

document.getElementById("cancelSubscriptionBtn")?.addEventListener("click", async event => {
  const button = event.currentTarget;
  if (button.disabled) return;
  const confirmed = confirm("Stop automatic payment now? Your remaining wallet balance will continue working on the current plan until it reaches zero, then the account will move to Free service.");
  if (!confirmed) return;
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = "Stopping service...";
  try {
    const response = await fetch("/api.php?action=cancel_chatbot_subscription", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({customer_id: selectedCustomerId || ""})
    });
    const data = await response.json().catch(() => ({}));
    if (!data.success) {
      button.disabled = false;
      button.textContent = originalText;
      showToast(data.message || "Subscription could not be cancelled");
      return;
    }
    document.getElementById("subscriptionServiceStatusTag").textContent = "Wallet Active";
    document.getElementById("subscriptionServiceStatusTag").classList.add("good");
    document.getElementById("subscriptionServiceStatusTag").classList.remove("bad");
    button.textContent = "Auto Payment Stopped";
    showToast("Auto payment stopped");
    setTimeout(() => location.reload(), 900);
  } catch (error) {
    button.disabled = false;
    button.textContent = originalText;
    showToast("Subscription could not be cancelled");
  }
});

themeToggle.addEventListener("click", () => {
  const dark = !document.body.classList.contains("dark");
  document.body.classList.toggle("dark", dark);
  themeToggle.textContent = dark ? "Bright" : "Dark";
  localStorage.setItem("vani_dashboard_theme", dark ? "dark" : "bright");
});

if (localStorage.getItem("vani_dashboard_theme") === "dark") {
  document.body.classList.add("dark");
  themeToggle.textContent = "Bright";
}

function formatLastActivityForBrowser() {
  const lastActivityText = document.getElementById("lastActivityText");
  const lastActivityZone = document.getElementById("lastActivityZone");
  const raw = lastActivityText?.dataset.lastActivity || "";
  if (!raw) return;

  const normalized = /z$|[+-]\d{2}:?\d{2}$/i.test(raw) ? raw : raw + "Z";
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) return;

  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || "your browser timezone";
  lastActivityText.textContent = new Intl.DateTimeFormat(undefined, {
    year: "numeric",
    month: "short",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    timeZoneName: "short"
  }).format(date);
  if (lastActivityZone) lastActivityZone.textContent = `Latest tracked conversation in ${timezone}.`;
}

formatLastActivityForBrowser();

const leadGenerationEnabled = document.getElementById("leadGenerationEnabled");
const leadServiceOptions = document.getElementById("leadServiceOptions");
const leadCollectLocationToggle = document.getElementById("leadCollectLocationToggle");
const leadCollectEmailToggle = document.getElementById("leadCollectEmailToggle");
const leadCollectMobileToggle = document.getElementById("leadCollectMobileToggle");
const leadEmailOtpToggle = document.getElementById("leadEmailOtpToggle");
const whatsappLeadToggle = document.getElementById("whatsappLeadToggle");
const whatsappLeadNumber = document.getElementById("whatsappLeadNumber");
const whatsappLeadHelp = document.getElementById("whatsappLeadHelp");
const leadEmailNotifyToggle = document.getElementById("leadEmailNotifyToggle");
const leadNotificationEmail = document.getElementById("leadNotificationEmail");
const leadNotificationEmailHelp = document.getElementById("leadNotificationEmailHelp");
const leadMobileOtpToggle = document.getElementById("leadMobileOtpToggle");

function validateWhatsappLeadNumber(showMessage = false) {
  if (!whatsappLeadNumber || !whatsappLeadHelp) return true;
  const value = whatsappLeadNumber.value.trim();
  const required = !!whatsappLeadToggle?.checked;
  const valid = (!required && !value) || /^\+?[1-9]\d{7,14}$/.test(value);
  whatsappLeadHelp.classList.toggle("error", !valid);
  whatsappLeadNumber.setAttribute("aria-invalid", String(!valid));
  whatsappLeadHelp.textContent = valid
    ? "Use country code and digits only, for example +919876543210."
    : "Enter a valid mobile number with country code and 8 to 15 digits.";
  if (!valid && showMessage) showToast("Enter a valid WhatsApp mobile number");
  return valid;
}

function validateLeadNotificationEmail(showMessage = false) {
  if (!leadNotificationEmail || !leadNotificationEmailHelp) return true;
  const value = leadNotificationEmail.value.trim();
  const required = !!leadEmailNotifyToggle?.checked;
  const valid = (!required && !value) || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
  leadNotificationEmailHelp.classList.toggle("error", !valid);
  leadNotificationEmail.setAttribute("aria-invalid", String(!valid));
  leadNotificationEmailHelp.textContent = valid
    ? "Lead notifications can be sent to this email address."
    : "Enter a valid email address for lead notifications.";
  if (!valid && showMessage) showToast("Enter a valid notification email");
  return valid;
}

function updateLeadGenerationUI() {
  const enabled = !!leadGenerationEnabled?.checked;
  leadServiceOptions?.classList.toggle("lead-disabled", !enabled);
  leadServiceOptions?.querySelectorAll("input, button").forEach(control => {
    control.disabled = !enabled;
  });
  syncOtpCollectionLocks();
}

function syncOtpCollectionLocks() {
  const enabled = !!leadGenerationEnabled?.checked;
  if (leadEmailOtpToggle?.checked && leadCollectEmailToggle) {
    leadCollectEmailToggle.checked = false;
  }
  if (leadMobileOtpToggle?.checked && leadCollectMobileToggle) {
    leadCollectMobileToggle.checked = false;
  }
  if (leadCollectEmailToggle) {
    leadCollectEmailToggle.disabled = !enabled || !!leadEmailOtpToggle?.checked;
    leadCollectEmailToggle.title = leadEmailOtpToggle?.checked ? "Turn off Email OTP before collecting email without OTP." : "";
  }
  if (leadCollectMobileToggle) {
    leadCollectMobileToggle.disabled = !enabled || !!leadMobileOtpToggle?.checked;
    leadCollectMobileToggle.title = leadMobileOtpToggle?.checked ? "Turn off Mobile OTP before collecting mobile without OTP." : "";
  }
  if (whatsappLeadToggle && whatsappRedirectLockedOn) {
    whatsappLeadToggle.checked = true;
    whatsappLeadToggle.disabled = true;
    whatsappLeadToggle.title = "WhatsApp redirection is locked ON for today after 3 changes.";
  }
}

leadGenerationEnabled?.addEventListener("change", () => {
  updateLeadGenerationUI();
  showToast(leadGenerationEnabled.checked ? "Lead generation enabled" : "Lead generation disabled");
});

function requireLeadPaidFeature(feature, control, message) {
  if (!control?.checked || leadPaidFeatures[feature]) return true;
  control.checked = false;
  showToast(message);
  openTab("subscription");
  return false;
}

function walletChargeText(key) {
  const paise = Number(leadWalletCharges[key] || 0);
  if (!paise) return "included";
  return `₹${Number((paise / 100).toFixed(2)).toString()}`;
}

function paidServiceAlert(message) {
  alert(message);
}

function formatDuration(seconds) {
  const total = Math.max(0, Number(seconds) || 0);
  const hours = Math.floor(total / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  const secs = total % 60;
  return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

function startWhatsappLockTimer() {
  const timer = document.getElementById("whatsappLockTimer");
  if (!timer) return;
  let remaining = Number(timer.dataset.remainingSeconds || 0);
  if (remaining <= 0) {
    timer.textContent = "";
    return;
  }
  const render = () => {
    timer.textContent = remaining > 0 ? formatDuration(remaining) : "00:00:00";
    if (remaining <= 0) {
      clearInterval(interval);
      window.location.reload();
    }
    remaining -= 1;
  };
  const interval = setInterval(render, 1000);
  render();
}

leadEmailOtpToggle?.addEventListener("change", () => {
  if (!requireLeadPaidFeature("email_otp", leadEmailOtpToggle, "Email OTP requires an active subscription")) return;
  if (leadCollectEmailToggle) leadCollectEmailToggle.checked = false;
  syncOtpCollectionLocks();
  if (leadEmailOtpToggle.checked) {
    paidServiceAlert(`Email OTP service is ON. Wallet deductions will apply after successful verification: fresh email lead ${walletChargeText("fresh_email_lead")}, repeat email lead ${walletChargeText("repeat_email_lead")}, email lead after 30 days ${walletChargeText("reactivated_email_lead")}.`);
  }
  if (!leadEmailOtpToggle.checked) showToast("Email will be saved without OTP");
});

leadMobileOtpToggle?.addEventListener("change", () => {
  if (!requireLeadPaidFeature("mobile_otp", leadMobileOtpToggle, "Mobile OTP requires an active paid plan")) return;
  if (leadCollectMobileToggle) leadCollectMobileToggle.checked = false;
  syncOtpCollectionLocks();
  if (leadMobileOtpToggle.checked) {
    paidServiceAlert(`Mobile OTP service is ON. Wallet deductions will apply after successful verification: fresh mobile lead ${walletChargeText("fresh_mobile_lead")}, repeat mobile OTP ${walletChargeText("repeat_mobile_lead")}, mobile lead after 30 days ${walletChargeText("reactivated_mobile_lead")}.`);
  }
  if (!leadMobileOtpToggle.checked) showToast("Mobile number will be saved without OTP");
});

whatsappLeadNumber?.addEventListener("input", () => {
  whatsappLeadNumber.value = whatsappLeadNumber.value.replace(/[^\d+]/g, "").replace(/(?!^)\+/g, "");
  validateWhatsappLeadNumber(false);
});

whatsappLeadNumber?.addEventListener("blur", () => validateWhatsappLeadNumber(false));

whatsappLeadToggle?.addEventListener("change", () => {
  if (!requireLeadPaidFeature("whatsapp_redirect", whatsappLeadToggle, "WhatsApp Redirect requires an active paid plan")) return;
  if (whatsappLeadToggle.checked) {
    if (walletBalancePaise < whatsappRedirectChargePaise) {
      whatsappLeadToggle.checked = false;
      showToast("Wallet balance must be at least ₹99 to turn ON WhatsApp Redirect");
      openTab("billing");
      return;
    }
    const charge = walletChargeText("whatsapp_redirect_addon");
    paidServiceAlert(charge === "included" ? "WhatsApp redirection service is ON. This service is included in your current plan and will be saved automatically." : `WhatsApp redirection service is ON. ${charge} will be deducted from your wallet after this change is saved. This add-on is valid for 30 days and renews every 30 days only if wallet balance is at least ₹99. If you turn it off within 1 hour, the amount will be refunded to your wallet.`);
  }
  validateWhatsappLeadNumber(false);
});

leadNotificationEmail?.addEventListener("input", () => validateLeadNotificationEmail(false));

leadNotificationEmail?.addEventListener("blur", () => validateLeadNotificationEmail(false));

leadEmailNotifyToggle?.addEventListener("change", () => validateLeadNotificationEmail(false));

let leadSetupSaveTimer = null;
let leadSetupSaving = false;
let leadSetupSaveQueued = false;

function leadSetupPayload() {
  return {
    customer_id: document.getElementById("settingsCustomerId")?.value || "",
    is_enabled: !!leadGenerationEnabled?.checked,
    collect_location: !!leadCollectLocationToggle?.checked,
    collect_email: !!leadCollectEmailToggle?.checked,
    collect_mobile: !!leadCollectMobileToggle?.checked,
    verify_email_otp: !!leadEmailOtpToggle?.checked,
    notify_lead_by_email: !!leadEmailNotifyToggle?.checked,
    notification_email: leadNotificationEmail?.value.trim() || "",
    redirect_whatsapp: !!whatsappLeadToggle?.checked,
    whatsapp_mobile_number: whatsappLeadNumber?.value.trim() || "",
    verify_mobile_otp: !!leadMobileOtpToggle?.checked
  };
}

async function saveLeadSetup({button = null, live = false} = {}) {
  if (leadEmailOtpToggle?.checked && !requireLeadPaidFeature("email_otp", leadEmailOtpToggle, "Email OTP requires an active subscription")) return;
  if (leadMobileOtpToggle?.checked && !requireLeadPaidFeature("mobile_otp", leadMobileOtpToggle, "Mobile OTP requires an active paid plan")) return;
  if (whatsappLeadToggle?.checked && !requireLeadPaidFeature("whatsapp_redirect", whatsappLeadToggle, "WhatsApp Redirect requires an active paid plan")) return;
  if (whatsappLeadToggle?.checked && walletBalancePaise < whatsappRedirectChargePaise) {
    whatsappLeadToggle.checked = false;
    showToast("Wallet balance must be at least ₹99 to turn ON WhatsApp Redirect");
    openTab("billing");
    return;
  }
  if (leadGenerationEnabled?.checked && whatsappLeadToggle?.checked && !validateWhatsappLeadNumber(true)) {
    whatsappLeadNumber?.focus();
    return;
  }
  if (leadGenerationEnabled?.checked && leadEmailNotifyToggle?.checked && !validateLeadNotificationEmail(true)) {
    leadNotificationEmail?.focus();
    return;
  }

  if (leadSetupSaving) {
    leadSetupSaveQueued = true;
    return;
  }
  leadSetupSaving = true;
  const originalText = button?.textContent || "";
  if (button) {
    button.disabled = true;
    button.textContent = "Saving...";
  } else if (live) {
    showToast("Saving lead setup...");
  }

  let data = {};
  try {
    const response = await fetch("/api.php?action=save_lead_generation_settings", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify(leadSetupPayload())
    });
    data = await response.json().catch(() => ({}));
  } catch (error) {
    data = {success: false, message: "Lead generation settings could not be saved"};
  } finally {
    leadSetupSaving = false;
    if (button) {
      button.disabled = false;
      button.textContent = originalText || "Save WhatsApp number";
    }
    if (leadSetupSaveQueued) {
      leadSetupSaveQueued = false;
      scheduleLeadSetupSave();
    }
  }

  if (!data.success) {
    if (data.whatsapp_redirect_locked && whatsappLeadToggle) {
      whatsappLeadToggle.checked = true;
      whatsappLeadToggle.disabled = true;
      setTimeout(() => window.location.reload(), 900);
    }
    showToast(data.message || "Lead generation settings could not be saved");
    return;
  }

  if (data.wallet_activity || data.whatsapp_redirect_locked) {
    if (data.wallet_activity) openTab("billing");
    showToast(data.wallet_activity ? "Wallet transaction saved. Refreshing Billing tab..." : "WhatsApp redirection locked for 24 hours. Refreshing...");
    setTimeout(() => window.location.reload(), 900);
    return;
  }

  showToast(live ? "Lead setup saved automatically" : "Lead generation settings saved");
}

function scheduleLeadSetupSave() {
  clearTimeout(leadSetupSaveTimer);
  leadSetupSaveTimer = setTimeout(() => saveLeadSetup({live: true}), 250);
}

document.getElementById("saveLeadSetupBtn")?.addEventListener("click", event => {
  saveLeadSetup({button: event.currentTarget});
});

document.querySelectorAll(".lead-toggle").forEach(toggle => {
  toggle.addEventListener("change", scheduleLeadSetupSave);
});

updateLeadGenerationUI();
startWhatsappLockTimer();

document.querySelectorAll(".swatch").forEach(swatch => {
  swatch.addEventListener("click", () => {
    const colorInput = document.getElementById("themeColorInput");
    colorInput.value = rgbToHex(getComputedStyle(swatch).backgroundColor);
    if (setupAutosaveReady) scheduleSetupAutosave();
  });
});

let setupAutosaveTimer = null;
let setupAutosaveReady = false;
let setupAutosaveSaving = false;
let setupAutosaveQueued = false;
let setupAutosaveToastState = "";

const themePresets = [
  "#6366f1","#06b6d4","#10b981","#ec4899","#f59e0b","#ef4444","#111827","#7c3aed",
  "linear-gradient(135deg,#6366f1,#06b6d4)","linear-gradient(135deg,#10b981,#0ea5e9)","linear-gradient(135deg,#f97316,#ec4899)","linear-gradient(135deg,#111827,#6366f1)",
  "linear-gradient(90deg,#4f46e5,#7c3aed,#ec4899)","linear-gradient(135deg,#0f172a,#0891b2,#22c55e)","linear-gradient(180deg,#f59e0b,#ef4444,#7c2d12)","radial-gradient(circle,#06b6d4,#4f46e5)",
  "linear-gradient(135deg,#0f172a,#334155,#64748b,#06b6d4)","linear-gradient(135deg,#dc2626,#f97316,#facc15,#22c55e,#06b6d4,#2563eb,#7c3aed,#db2777)"
];
const patternStyles = {
  none: "none",
  dots: "radial-gradient(rgba(99,102,241,.22) 1px, transparent 1px)",
  grid: "linear-gradient(rgba(99,102,241,.12) 1px, transparent 1px), linear-gradient(90deg, rgba(99,102,241,.12) 1px, transparent 1px)",
  diagonal: "repeating-linear-gradient(45deg, rgba(99,102,241,.12) 0 2px, transparent 2px 10px)",
  waves: "radial-gradient(ellipse at top, rgba(6,182,212,.18), transparent 45%), radial-gradient(ellipse at bottom, rgba(99,102,241,.18), transparent 48%)"
};
for (let i = 1; i <= 45; i++) {
  const angle = (i * 17) % 180;
  const hue = (i * 37) % 360;
  patternStyles[`pattern-${i}`] = `repeating-linear-gradient(${angle}deg, hsla(${hue},70%,55%,.12) 0 2px, transparent 2px ${8 + (i % 9)}px), radial-gradient(circle at ${20 + (i % 60)}% ${20 + ((i * 3) % 60)}%, hsla(${(hue + 80) % 360},70%,55%,.14), transparent 28%)`;
}

function setThemeValue(value) {
  const input = document.getElementById("themeColorInput");
  const preview = document.getElementById("themePreviewBox");
  if (input) input.value = value;
  if (preview) preview.style.background = value;
  document.querySelectorAll(".theme-color-chip").forEach(chip => chip.classList.toggle("active", chip.dataset.theme === value));
  if (setupAutosaveReady) scheduleSetupAutosave();
}

function buildGradientTheme() {
  const type = document.getElementById("themeGradientType")?.value || "linear";
  const direction = document.getElementById("themeGradientDirection")?.value || "135deg";
  const colors = Array.from(document.querySelectorAll(".themeGradientColor")).map(input => input.value).filter(Boolean).slice(0, 8);
  return type === "radial" ? `radial-gradient(${direction === "circle" ? "circle" : "ellipse"},${colors.join(",")})` : `linear-gradient(${direction},${colors.join(",")})`;
}

function applyPatternPreview(pattern) {
  const preview = document.getElementById("themePreviewBox");
  if (!preview) return;
  const theme = document.getElementById("themeColorInput")?.value || "#6366f1";
  const patternCss = patternStyles[pattern] || "none";
  preview.style.backgroundImage = patternCss === "none" ? theme : `${patternCss}, ${theme}`;
  preview.style.backgroundSize = pattern === "grid" || pattern === "dots" ? "18px 18px, 18px 18px, cover" : "cover";
}

function initThemeDesigner() {
  const grid = document.getElementById("themeColorGrid");
  const patternGrid = document.getElementById("themePatternGrid");
  if (!grid || !patternGrid) return;
  themePresets.forEach(theme => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "theme-color-chip";
    button.dataset.theme = theme;
    button.style.background = theme;
    button.addEventListener("click", () => {
      setThemeValue(theme);
      applyPatternPreview(document.getElementById("themePatternInput")?.value || "none");
    });
    grid.appendChild(button);
  });
  Object.entries(patternStyles).forEach(([key, pattern]) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "pattern-chip";
    button.dataset.pattern = key;
    button.title = key;
    button.style.backgroundImage = pattern === "none" ? "none" : pattern;
    button.style.backgroundSize = "18px 18px, cover";
    button.addEventListener("click", () => {
      document.getElementById("themePatternInput").value = key;
      document.querySelectorAll(".pattern-chip").forEach(chip => chip.classList.toggle("active", chip.dataset.pattern === key));
      applyPatternPreview(key);
      if (setupAutosaveReady) scheduleSetupAutosave();
    });
    patternGrid.appendChild(button);
  });
  document.getElementById("themeSolidColorInput")?.addEventListener("input", event => {
    setThemeValue(event.target.value);
    applyPatternPreview(document.getElementById("themePatternInput")?.value || "none");
  });
  document.querySelectorAll(".themeGradientColor,#themeGradientType,#themeGradientDirection").forEach(control => {
    control.addEventListener("input", () => {
      setThemeValue(buildGradientTheme());
      applyPatternPreview(document.getElementById("themePatternInput")?.value || "none");
    });
    control.addEventListener("change", () => {
      setThemeValue(buildGradientTheme());
      applyPatternPreview(document.getElementById("themePatternInput")?.value || "none");
    });
  });
  setThemeValue(document.getElementById("themeColorInput")?.value || "#6366f1");
  const currentPattern = document.getElementById("themePatternInput")?.value || "none";
  document.querySelectorAll(".pattern-chip").forEach(chip => chip.classList.toggle("active", chip.dataset.pattern === currentPattern));
  applyPatternPreview(currentPattern);
}
initThemeDesigner();
updateDashboardSetupPreview(setupSettingsPayload());
setupAutosaveReady = true;

document.querySelectorAll("input[name='dashboardBotImage']").forEach(input => {
  input.addEventListener("change", () => {
    const preview = document.getElementById("selectedBotImagePreview");
    if (preview && input.checked) preview.src = input.value;
    scheduleSetupAutosave();
  });
});

function rgbToHex(rgb) {
  const values = rgb.match(/\d+/g).map(Number);
  return "#" + values.slice(0, 3).map(v => v.toString(16).padStart(2, "0")).join("");
}

document.getElementById("faqSearch")?.addEventListener("input", event => {
  const q = event.target.value.toLowerCase();
  document.querySelectorAll("#faqTable tbody tr").forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(q) ? "" : "none";
  });
});

async function addFaq(customerId, question, answer, category) {
  const response = await fetch("/api.php?action=add_faq", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, faqs: [{question, answer, category}]})
  });

  const data = await response.json().catch(() => ({}));
  return data;
}

let temporaryBulkFaqReport = null;

function updateFaqCountUi() {
  const tag = document.getElementById("faqCountTag");
  if (tag) tag.textContent = `${currentFaqCount}/${faqLimitLabel} FAQs`;
}

function faqRowHtml(faq) {
  return `<tr data-faq-id="${htmlEscape(faq.id || "")}">
    <td>
      <span class="faq-display">${htmlEscape(faq.question || "")}</span>
      <textarea class="faq-edit-field faq-question-input" aria-label="FAQ question">${htmlEscape(faq.question || "")}</textarea>
    </td>
    <td>
      <span class="faq-display">${htmlEscape(faq.answer || "")}</span>
      <textarea class="faq-edit-field faq-answer-input" aria-label="FAQ answer">${htmlEscape(faq.answer || "")}</textarea>
    </td>
    <td>
      <span class="tag faq-display">${htmlEscape(faq.category || "General")}</span>
      <input class="faq-edit-field faq-category-input" value="${htmlEscape(faq.category || "General")}" aria-label="FAQ category">
    </td>
    <td>
      <div class="faq-actions">
        <button class="ghost-btn faq-edit-btn" type="button">Edit</button>
        <button class="pill-btn faq-save-btn faq-edit-field" type="button">Save</button>
        <button class="ghost-btn faq-cancel-btn faq-edit-field" type="button">Cancel</button>
        <button class="danger-btn faq-delete-btn" type="button">Delete</button>
      </div>
    </td>
  </tr>`;
}

function appendBulkFaqRows(savedRows) {
  const body = document.querySelector("#faqTable tbody");
  if (!body || !Array.isArray(savedRows)) return;
  body.insertAdjacentHTML("afterbegin", savedRows.map(faqRowHtml).join(""));
}

function requireXlsx() {
  if (!window.XLSX) {
    showToast("Excel tools could not be loaded. Please refresh and try again.");
    return false;
  }
  return true;
}

function downloadWorkbook(filename, sheets) {
  if (!requireXlsx()) return;
  const workbook = XLSX.utils.book_new();
  Object.entries(sheets).forEach(([name, rows]) => {
    XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet(rows), name.slice(0, 31));
  });
  XLSX.writeFile(workbook, filename);
}

document.getElementById("downloadFaqSampleBtn")?.addEventListener("click", event => {
  event.preventDefault();
  downloadWorkbook("vani-faq-upload-sample.xlsx", {
    "FAQs": [
      ["Question", "Answer", "Category"],
      ["What payment methods do you accept?", "We accept UPI, credit card, debit card, and net banking.", "Payments"],
      ["How can I contact support?", "You can contact our support team from the contact page or WhatsApp button.", "Support"]
    ]
  });
});

function excelHeaderIndex(headers, names) {
  return headers.findIndex(header => names.includes(String(header || "").trim().toLowerCase()));
}

async function parseFaqExcel(file) {
  if (!requireXlsx()) return [];
  const buffer = await file.arrayBuffer();
  const workbook = XLSX.read(buffer, {type: "array"});
  const sheet = workbook.Sheets[workbook.SheetNames[0]];
  const rows = XLSX.utils.sheet_to_json(sheet, {header: 1, defval: ""});
  if (rows.length < 2) return [];
  const headers = rows[0].map(value => String(value || "").trim().toLowerCase());
  const questionIndex = excelHeaderIndex(headers, ["question", "faq question", "questions"]);
  const answerIndex = excelHeaderIndex(headers, ["answer", "faq answer", "answers"]);
  const categoryIndex = excelHeaderIndex(headers, ["category", "faq category", "categories"]);
  if (questionIndex < 0 || answerIndex < 0) {
    throw new Error("Excel must include Question and Answer columns");
  }
  return rows.slice(1).map((row, index) => {
    const question = String(row[questionIndex] || "").trim();
    const answer = String(row[answerIndex] || "").trim();
    const rawCategory = categoryIndex >= 0 ? String(row[categoryIndex] || "").trim() : "";
    return {
      row: index + 2,
      question,
      answer,
      category: rawCategory || "General",
      hasAnyValue: !!(question || answer || rawCategory)
    };
  }).filter(item => item.hasAnyValue).map(({hasAnyValue, ...item}) => item);
}

function reportRowsForExport(report, type) {
  const rows = [["Status", "Excel Row", "Question", "Answer", "Category", "Reason"]];
  const source = type === "saved" ? report.saved || [] : report.failed || [];
  source.forEach(item => rows.push([
    type === "saved" ? "Saved" : "Failed",
    item.row || "",
    item.question || "",
    item.answer || "",
    item.category || "General",
    item.reason || ""
  ]));
  return rows;
}

function reportTable(title, rows, status) {
  const body = rows.length
    ? rows.map(item => `<tr><td>${htmlEscape(item.row || "")}</td><td>${htmlEscape(item.question || "")}</td><td>${htmlEscape(item.category || "General")}</td><td>${htmlEscape(item.reason || status)}</td></tr>`).join("")
    : `<tr><td colspan="4" class="empty">No ${htmlEscape(title.toLowerCase())} rows.</td></tr>`;
  return `<div>
    <h3>${htmlEscape(title)}</h3>
    <div class="bulk-report-table"><table><thead><tr><th>Excel Row</th><th>Question</th><th>Category</th><th>Status / Reason</th></tr></thead><tbody>${body}</tbody></table></div>
  </div>`;
}

function showBulkFaqReport(report) {
  temporaryBulkFaqReport = report;
  const modal = document.getElementById("bulkFaqReportModal");
  const body = document.getElementById("bulkFaqReportBody");
  if (!modal || !body) return;
  body.innerHTML = `
    <div class="bulk-report-summary">
      <div class="panel metric"><span>Saved</span><strong>${htmlEscape(report.saved_count || 0)}</strong><small>Inserted into FAQ database.</small></div>
      <div class="panel metric"><span>Failed</span><strong>${htmlEscape(report.failed_count || 0)}</strong><small>Not saved. Check reasons below.</small></div>
      <div class="panel metric"><span>Plan Limit</span><strong>${htmlEscape(report.faq_limit || faqLimitLabel)}</strong><small>${htmlEscape(report.active_plan || "plan")} plan.</small></div>
    </div>
    <div class="panel-actions" style="padding-top:0">
      <button class="pill-btn" type="button" id="exportBulkFaqReportBtn">Export report Excel</button>
    </div>
    ${reportTable("Successfully Uploaded And Saved", report.saved || [], "Saved")}
    ${reportTable("Failed Rows", report.failed || [], "Failed")}
  `;
  modal.classList.add("active");
  modal.setAttribute("aria-hidden", "false");
  document.getElementById("exportBulkFaqReportBtn")?.addEventListener("click", () => {
    if (!temporaryBulkFaqReport) return showToast("No temporary report to export");
    downloadWorkbook("vani-bulk-faq-upload-report.xlsx", {
      "Successful": reportRowsForExport(temporaryBulkFaqReport, "saved"),
      "Failed": reportRowsForExport(temporaryBulkFaqReport, "failed")
    });
  });
}

function closeBulkFaqReport() {
  temporaryBulkFaqReport = null;
  const modal = document.getElementById("bulkFaqReportModal");
  const body = document.getElementById("bulkFaqReportBody");
  if (body) body.innerHTML = "";
  if (modal) {
    modal.classList.remove("active");
    modal.setAttribute("aria-hidden", "true");
  }
}

document.getElementById("closeBulkFaqReportBtn")?.addEventListener("click", closeBulkFaqReport);

document.getElementById("bulkFaqUploadBtn")?.addEventListener("click", async event => {
  const customerId = document.getElementById("faqCustomerId")?.value || "";
  const fileInput = document.getElementById("bulkFaqFileInput");
  const file = fileInput?.files?.[0];
  if (!customerId) return showToast("Select a bot first");
  if (!file) return showToast("Choose an Excel file");
  if (!/\.(xlsx|xls)$/i.test(file.name)) {
    if (fileInput) fileInput.value = "";
    return showToast("Only Excel files are accepted");
  }
  if (!faqLimitIsUnlimited && currentFaqCount >= freeFaqLimit) {
    showToast("Your current FAQ plan limit is already reached");
    openTab("subscription");
    return;
  }

  const button = event.currentTarget;
  button.disabled = true;
  button.textContent = "Uploading...";
  try {
    const faqs = await parseFaqExcel(file);
    if (!faqs.length) throw new Error("No FAQ rows found in Excel");
    const response = await fetch("/api.php?action=bulk_add_faq", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({customer_id: customerId, faqs})
    });
    const report = await response.json().catch(() => ({}));
    if (!report.success) throw new Error(report.message || "Bulk upload failed");
    appendBulkFaqRows(report.saved || []);
    currentFaqCount += Number(report.saved_count || 0);
    updateFaqCountUi();
    showBulkFaqReport(report);
    if (fileInput) fileInput.value = "";
    showToast("Bulk FAQ upload completed");
  } catch (error) {
    showToast(error.message || "Bulk FAQ upload failed");
  } finally {
    button.disabled = false;
    button.textContent = "Upload Excel FAQs";
  }
});

document.getElementById("faqForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  const customerId = document.getElementById("faqCustomerId").value;
  const question = document.getElementById("faqQuestion").value.trim();
  const answer = document.getElementById("faqAnswer").value.trim();
  const category = document.getElementById("faqCategory").value.trim() || "General";
  if (!customerId) return showToast("Select a bot first");
  if (!question || !answer) return showToast("Question and answer are required");
  if (currentFaqCount >= freeFaqLimit) {
    showToast("Upgrade to add more FAQs");
    openTab("subscription");
    return;
  }

  const saved = await addFaq(customerId, question, answer, category);
  if (saved.requires_premium) {
    showToast(saved.message || "Upgrade to add more FAQs");
    openTab("subscription");
    return;
  }
  if (saved.error || saved.success === false) {
    showToast("FAQ could not be saved");
    return;
  }
  showToast("FAQ added");
  setTimeout(() => location.reload(), 700);
});

document.querySelectorAll(".outsideFaqForm").forEach(form => {
  form.addEventListener("submit", async event => {
    event.preventDefault();
    const customerId = form.querySelector(".outsideCustomerId")?.value || "";
    const question = form.querySelector(".outsideQuestion")?.value.trim() || "";
    const answer = form.querySelector(".outsideAnswer")?.value.trim() || "";
    const category = form.querySelector(".outsideCategory")?.value.trim() || "General";
    const button = form.querySelector("button[type='submit']");

    if (!customerId) return showToast("Select a bot first");
    if (!question || !answer) return showToast("Question and answer are required");
    if (currentFaqCount >= freeFaqLimit) {
      showToast("Upgrade to add more FAQs");
      openTab("subscription");
      return;
    }

    if (button) {
      button.disabled = true;
      button.textContent = "Saving...";
    }

    const saved = await addFaq(customerId, question, answer, category);
    if (saved.requires_premium) {
      if (button) {
        button.disabled = false;
        button.textContent = "Add to FAQs";
      }
      showToast(saved.message || "Upgrade to add more FAQs");
      openTab("subscription");
      return;
    }
    if (saved.error || saved.success === false) {
      if (button) {
        button.disabled = false;
        button.textContent = "Add to FAQs";
      }
      showToast("FAQ could not be saved");
      return;
    }

    form.style.opacity = ".65";
    form.querySelectorAll("input, textarea, button").forEach(input => input.disabled = true);
    if (button) button.textContent = "Added";
    currentFaqCount++;
    showToast("Added to FAQs");
  });
});

function setFaqRowEditing(row, editing) {
  row.classList.toggle("editing", editing);
}

document.getElementById("faqTable")?.addEventListener("click", async event => {
  const button = event.target.closest("button");
  const row = event.target.closest("tr[data-faq-id]");
  if (!button || !row) return;

  const customerId = document.getElementById("faqCustomerId").value;
  const faqId = row.dataset.faqId || "";
  const questionInput = row.querySelector(".faq-question-input");
  const answerInput = row.querySelector(".faq-answer-input");
  const categoryInput = row.querySelector(".faq-category-input");

  if (button.classList.contains("faq-edit-btn")) {
    setFaqRowEditing(row, true);
    questionInput?.focus();
    return;
  }

  if (button.classList.contains("faq-cancel-btn")) {
    questionInput.value = row.children[0].querySelector(".faq-display").textContent.trim();
    answerInput.value = row.children[1].querySelector(".faq-display").textContent.trim();
    categoryInput.value = row.children[2].querySelector(".faq-display").textContent.trim();
    setFaqRowEditing(row, false);
    return;
  }

  if (button.classList.contains("faq-save-btn")) {
    const question = questionInput.value.trim();
    const answer = answerInput.value.trim();
    const category = categoryInput.value.trim() || "General";
    if (!question || !answer) return showToast("Question and answer are required");

    button.disabled = true;
    button.textContent = "Saving...";
    const response = await fetch("/api.php?action=update_faq", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({customer_id: customerId, id: faqId, question, answer, category})
    });
    const data = await response.json().catch(() => ({}));
    button.disabled = false;
    button.textContent = "Save";

    if (!data.success) return showToast(data.message || "FAQ could not be updated");

    row.children[0].querySelector(".faq-display").textContent = question;
    row.children[1].querySelector(".faq-display").textContent = answer;
    row.children[2].querySelector(".faq-display").textContent = category;
    setFaqRowEditing(row, false);
    showToast("FAQ updated");
    return;
  }

  if (button.classList.contains("faq-delete-btn")) {
    if (!confirm("Delete this FAQ?")) return;
    button.disabled = true;
    button.textContent = "Deleting...";
    const response = await fetch("/api.php?action=delete_faq", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({customer_id: customerId, id: faqId})
    });
    const data = await response.json().catch(() => ({}));
    if (!data.success) {
      button.disabled = false;
      button.textContent = "Delete";
      return showToast(data.message || "FAQ could not be deleted");
    }
    row.remove();
    currentFaqCount = Math.max(0, currentFaqCount - 1);
    showToast("FAQ deleted");
  }
});

async function saveFaqActionsToggle({live = false} = {}) {
  const toggle = document.getElementById("faqActionsToggle");
  if (!toggle) return;
  if (toggle.checked && !businessFeatures.faq_action_suggestions) {
    toggle.checked = false;
    alert("FAQ Action Suggestions requires Starter, Growth, or Business plan");
    openTab("subscription");
    return;
  }
  const saved = await saveDashboardSettings({
    faq_actions_enabled: businessFeatures.faq_action_suggestions && !!toggle.checked
  });
  if (saved && live) {
    showToast(toggle.checked ? "FAQ Action Suggestions enabled" : "FAQ Action Suggestions disabled");
  }
}

document.getElementById("faqActionsToggle")?.addEventListener("change", () => {
  saveFaqActionsToggle({live: true});
});

document.getElementById("faqCategoryMenuToggle")?.addEventListener("change", async event => {
  const saved = await saveDashboardSettings({
    faq_category_menu_enabled: !!event.currentTarget.checked
  });
  if (saved) {
    showToast(event.currentTarget.checked ? "FAQ category menu enabled" : "FAQ category menu disabled");
  }
});

const faqActionHelp = {
  link: ["https://example.com/product", "Use a secure https:// page, service, or product URL."],
  whatsapp: ["+919876543210", "Use a WhatsApp number with country code."],
  call: ["+919876543210", "Use a phone number with country code. The visitor's phone dialer will open."],
  email: ["support@example.com", "Use the email address where the visitor should send the message."],
  download: ["https://example.com/brochure.pdf", "Use a secure https:// file URL for PDF, catalog, menu, brochure, or price list."],
  coupon: ["WELCOME10", "Enter the coupon or code. The widget will copy it to the visitor's clipboard."],
  booking: ["https://calendly.com/your-business/demo", "Use a secure https:// booking link."],
  map: ["https://maps.google.com/?q=Your+Store or full address", "Use a Google Maps link or a full address."],
  form: ["Callback request", "Enter the form title or purpose. The widget will show name, email, mobile, and message fields."],
  track_order: ["https://example.com/track-order", "Use a secure https:// tracking or status page URL."],
  category: ["Pricing", "Enter the FAQ category name to show related FAQs in the chatbot."],
  event: ["openPricing", "Enter a website event name. Your site can listen for window event vani:openPricing."]
};

function updateFaqActionHelp() {
  const type = document.getElementById("faqActionType")?.value || "link";
  const valueInput = document.getElementById("faqActionValue");
  const help = document.getElementById("faqActionValueHelp");
  const info = faqActionHelp[type] || faqActionHelp.link;
  if (valueInput) valueInput.placeholder = info[0];
  if (help) help.textContent = info[1];
}

document.getElementById("faqActionType")?.addEventListener("change", updateFaqActionHelp);
updateFaqActionHelp();

document.getElementById("faqActionForm")?.addEventListener("submit", async event => {
  event.preventDefault();
  if (!businessFeatures.faq_action_suggestions) {
    showToast("FAQ Action Suggestions requires Starter, Growth, or Business plan");
    openTab("subscription");
    return;
  }
  if (!document.getElementById("faqActionsToggle")?.checked) {
    showToast("Turn ON FAQ Action Suggestions first");
    return;
  }
  const button = event.currentTarget.querySelector("button[type='submit']");
  const customerId = document.getElementById("faqActionCustomerId")?.value || "";
  const faqId = document.getElementById("faqActionFaqId")?.value || "";
  const label = document.getElementById("faqActionLabel")?.value.trim() || "";
  const actionType = document.getElementById("faqActionType")?.value || "link";
  const actionValue = document.getElementById("faqActionValue")?.value.trim() || "";
  const displayOrder = Number(document.getElementById("faqActionOrder")?.value || 0);
  if (!customerId) return showToast("Select a bot first");
  if (!faqId) return showToast("Select FAQ");
  if (!label) return showToast("Enter button label");
  if (!actionValue) return showToast("Enter action value");
  button.disabled = true;
  button.textContent = "Saving...";
  const response = await fetch("/api.php?action=save_faq_action", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({
      customer_id: customerId,
      faq_id: faqId,
      label,
      action_type: actionType,
      action_value: actionValue,
      display_order: displayOrder,
      is_active: true
    })
  });
  const data = await response.json().catch(() => ({}));
  button.disabled = false;
  button.textContent = "Add action";
  if (!data.success) {
    showToast(data.message || "FAQ action could not be saved");
    if (data.requires_paid) openTab("subscription");
    return;
  }
  showToast("FAQ action saved");
  setTimeout(() => location.reload(), 700);
});

document.getElementById("faqActionList")?.addEventListener("click", async event => {
  const button = event.target.closest(".faq-action-delete-btn");
  const card = event.target.closest("[data-faq-action-id]");
  if (!button || !card) return;
  if (!confirm("Delete this FAQ action?")) return;
  const customerId = document.getElementById("faqActionCustomerId")?.value || "";
  button.disabled = true;
  button.textContent = "Deleting...";
  const response = await fetch("/api.php?action=delete_faq_action", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, id: card.dataset.faqActionId || ""})
  });
  const data = await response.json().catch(() => ({}));
  if (!data.success) {
    button.disabled = false;
    button.textContent = "Delete";
    return showToast(data.message || "FAQ action could not be deleted");
  }
  card.remove();
  showToast("FAQ action deleted");
});

document.getElementById("saveScheduledFaqActionsBtn")?.addEventListener("click", async event => {
  if (!businessFeatures.faq_action_suggestions) {
    showToast("FAQ Action Suggestions requires Starter, Growth, or Business plan");
    openTab("subscription");
    return;
  }
  if (!document.getElementById("faqActionsToggle")?.checked) {
    showToast("Turn ON FAQ Action Suggestions first");
    return;
  }
  const customerId = document.getElementById("faqActionCustomerId")?.value || "";
  if (!customerId) return showToast("Select a bot first");
  const button = event.currentTarget;
  const actions = Array.from(document.querySelectorAll(".scheduled-faq-action-card")).map(card => ({
    slot_no: Number(card.dataset.slotNo || 0),
    trigger_after_questions: Number(card.querySelector(".scheduledActionAfter")?.value || 0),
    label: card.querySelector(".scheduledActionLabel")?.value.trim() || "",
    action_type: card.querySelector(".scheduledActionType")?.value || "link",
    action_value: card.querySelector(".scheduledActionValue")?.value.trim() || "",
    is_active: !!card.querySelector(".scheduledActionActive")?.checked
  }));
  button.disabled = true;
  button.textContent = "Saving...";
  const response = await fetch("/api.php?action=save_scheduled_faq_actions", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, actions})
  });
  const data = await response.json().catch(() => ({}));
  button.disabled = false;
  button.textContent = "Save schedule";
  if (!data.success) {
    if (data.requires_paid) openTab("subscription");
    return showToast(data.message || "Scheduled FAQ actions could not be saved");
  }
  showToast("Scheduled FAQ actions saved");
});

async function saveDashboardSettings(extraPayload, options = {}) {
  const {silent = false, successMessage = "Settings saved", errorMessage = "Settings could not be saved"} = options;
  const customerId = document.getElementById("settingsCustomerId")?.value || "";
  if (!customerId) {
    if (!silent) showToast("Select a bot first");
    return false;
  }

  let data = {};
  try {
    const response = await fetch("/api.php?action=save_dashboard_settings", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({customer_id: customerId, ...extraPayload})
    });
    data = await response.json().catch(() => ({}));
  } catch (error) {
    data = {success: false};
  }
  if (!data.success) {
    if (!silent) showToast(errorMessage);
    return false;
  }
  if (!silent) showToast(successMessage);
  return true;
}

function setupSettingsPayload() {
  return {
    bot_name: document.getElementById("botNameInput")?.value.trim() || "",
    welcome_message: document.getElementById("welcomeInput")?.value.trim() || "",
    theme_color: document.getElementById("themeColorInput")?.value || "#6366f1",
    theme_pattern: document.getElementById("themePatternInput")?.value || "none",
    avatar_url: document.querySelector("input[name='dashboardBotImage']:checked")?.value || "",
    position: document.getElementById("positionInput")?.value || "right",
    language: document.getElementById("languageInput")?.value || "English"
  };
}

function updateDashboardSetupPreview(payload) {
  try {
    const botName = payload.bot_name || "Vani Bot";
    const themeColor = payload.theme_color || "#6366f1";
    const themePattern = payload.theme_pattern || "none";
    const avatarUrl = payload.avatar_url || "";
    const welcomeMessage = payload.welcome_message || "Hi, how can I help you today?";
    document.getElementById("overviewBotNameText")?.replaceChildren(document.createTextNode(botName));
    document.getElementById("sidebarBotNameText")?.replaceChildren(document.createTextNode(botName));
    const deleteButton = document.getElementById("deleteChatbotBtn");
    if (deleteButton) deleteButton.dataset.botName = botName;
    document.getElementById("overviewThemeMessage")?.replaceChildren(document.createTextNode(welcomeMessage));
    const patternCss = typeof patternStyles !== "undefined" ? (patternStyles[themePattern] || "none") : "none";
    ["overviewThemeBubble", "overviewThemeTyping"].forEach(id => {
      const bubble = document.getElementById(id);
      if (!bubble) return;
      bubble.style.background = themeColor;
      bubble.style.backgroundImage = patternCss === "none" ? "" : `${patternCss}, ${themeColor}`;
      bubble.style.backgroundSize = themePattern === "grid" || themePattern === "dots" ? "18px 18px, 18px 18px, cover" : "cover";
    });
    const overviewImage = document.getElementById("overviewBotImagePreview");
    if (overviewImage && avatarUrl) overviewImage.src = avatarUrl;
    if (typeof analyticsReport !== "undefined" && analyticsReport) analyticsReport.bot_name = botName;
  } catch (error) {
    console.error("Setup dashboard preview failed", error);
  }
}

function updateSetupAutosaveStatus(text, state = "") {
  const status = document.getElementById("setupAutosaveStatus");
  if (!status) return;
  status.textContent = text;
  status.classList.toggle("error", state === "error");
}

async function saveSetupSettingsAutomatically() {
  if (!setupAutosaveReady) return;
  if (setupAutosaveSaving) {
    setupAutosaveQueued = true;
    return;
  }
  setupAutosaveSaving = true;
  updateSetupAutosaveStatus("Saving changes...");
  try {
    if (setupAutosaveToastState !== "saving") {
      showToast("Saving changes...");
      setupAutosaveToastState = "saving";
    }
    const payload = setupSettingsPayload();
    const saved = await saveDashboardSettings(payload, {silent: true});
    if (saved) updateDashboardSetupPreview(payload);
    updateSetupAutosaveStatus(saved ? "All changes saved automatically." : "Could not save changes. Please try again.", saved ? "" : "error");
    showToast(saved ? "Changes saved" : "Changes could not be saved");
    setupAutosaveToastState = saved ? "saved" : "error";
  } catch (error) {
    console.error("Setup autosave failed", error);
    updateSetupAutosaveStatus("Could not save changes. Please try again.", "error");
    setupAutosaveToastState = "error";
  } finally {
    setupAutosaveSaving = false;
    if (setupAutosaveQueued) {
      setupAutosaveQueued = false;
      scheduleSetupAutosave();
    }
  }
}

function scheduleSetupAutosave() {
  if (!setupAutosaveReady) return;
  clearTimeout(setupAutosaveTimer);
  updateSetupAutosaveStatus("Changes pending...");
  setupAutosaveToastState = "";
  setupAutosaveTimer = setTimeout(saveSetupSettingsAutomatically, 650);
}

function setOverviewActiveUI(isActive) {
  const statusText = document.getElementById("overviewStatusText");
  const statusHelp = document.getElementById("overviewStatusHelp");
  const activeSwitch = document.getElementById("overviewActiveSwitch");
  const activeInput = document.getElementById("activeInput");
  if (statusText) {
    statusText.textContent = isActive ? "Active" : "Inactive";
    statusText.classList.toggle("inactive", !isActive);
  }
  if (statusHelp) {
    statusHelp.textContent = isActive ? "Chatbot is on for customers." : "Chatbot is off for customers.";
  }
  if (activeSwitch) activeSwitch.checked = isActive;
  if (activeInput) activeInput.value = isActive ? "true" : "false";
}

document.getElementById("overviewActiveSwitch")?.addEventListener("change", async event => {
  const isActive = event.target.checked;
  setOverviewActiveUI(isActive);
  const saved = await saveDashboardSettings({is_active: isActive});
  if (!saved) setOverviewActiveUI(!isActive);
});

document.getElementById("deleteChatbotBtn")?.addEventListener("click", async event => {
  const customerId = selectedCustomerId || "";
  if (!customerId) {
    showToast("Select a bot first");
    return;
  }

  const botName = event.currentTarget.dataset.botName || "this chatbot";
  const warning = [
    `Delete ${botName}?`,
    "",
    "This will permanently delete this chatbot and its setup, FAQs, conversations, leads, API keys, and support tickets.",
    "This action cannot be undone."
  ].join("\n");
  if (!confirm(warning)) return;

  const typed = prompt('Type DELETE to permanently delete this chatbot.');
  if (typed !== "DELETE") {
    showToast("Chatbot deletion cancelled");
    return;
  }

  const button = event.currentTarget;
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = "Deleting...";
  const response = await fetch("/api.php?action=delete_chatbot", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, confirm_text: typed})
  });
  const data = await response.json().catch(() => ({}));
  if (!data.success) {
    button.disabled = false;
    button.textContent = originalText;
    showToast(data.message || "Chatbot could not be deleted");
    return;
  }
  showToast("Chatbot deleted");
  setTimeout(() => {
    window.location.href = data.redirect || "dashboard.php";
  }, 700);
});

document.getElementById("transferSubscriptionBtn")?.addEventListener("click", async event => {
  const sourceCustomerId = selectedCustomerId || "";
  const targetCustomerId = document.getElementById("transferSubscriptionTarget")?.value || "";
  if (!sourceCustomerId) return showToast("Select a bot first");
  if (!targetCustomerId) return showToast("Select target chatbot");
  const targetText = document.getElementById("transferSubscriptionTarget")?.selectedOptions?.[0]?.textContent?.trim() || "the selected chatbot";
  const warning = [
    "Transfer subscription?",
    "",
    `The current plan and wallet balance will move to ${targetText}.`,
    "This chatbot will move to Free service and paid toggles will be turned off here."
  ].join("\n");
  if (!confirm(warning)) return;

  const button = event.currentTarget;
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = "Transferring...";
  const response = await fetch("/api.php?action=transfer_chatbot_subscription", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({
      source_customer_id: sourceCustomerId,
      target_customer_id: targetCustomerId
    })
  });
  const data = await response.json().catch(() => ({}));
  if (!data.success) {
    button.disabled = false;
    button.textContent = originalText;
    showToast(data.message || "Subscription could not be transferred");
    return;
  }
  showToast("Subscription transferred");
  setTimeout(() => {
    window.location.href = `dashboard.php?bot=${encodeURIComponent(targetCustomerId)}#subscription`;
  }, 800);
});

["botNameInput", "welcomeInput"].forEach(id => {
  document.getElementById(id)?.addEventListener("input", scheduleSetupAutosave);
});

["positionInput", "languageInput"].forEach(id => {
  document.getElementById(id)?.addEventListener("change", scheduleSetupAutosave);
});

document.getElementById("saveSettingsBtn")?.addEventListener("click", () => {
  const isActive = document.getElementById("activeInput")?.value === "true";
  saveDashboardSettings({
    api_key: document.getElementById("apiKeyInput")?.value.trim() || "",
    rate_limit: Number(document.getElementById("rateLimitInput")?.value || 100),
    is_active: isActive,
    notification_preference: document.getElementById("notificationInput")?.value || "email",
    allowed_domains: document.getElementById("domainsInput")?.value.trim() || ""
  }).then(saved => {
    if (saved) setOverviewActiveUI(isActive);
  });
});

let integrationAutosaveTimer = null;
let integrationAutosaveSaving = false;
let integrationAutosaveQueued = false;

function updateIntegrationAutosaveStatus(text, state = "") {
  const status = document.getElementById("integrationAutosaveStatus");
  if (!status) return;
  status.textContent = text;
  status.classList.toggle("error", state === "error");
}

function integrationSettingsPayload() {
  const websiteVerificationEnabled = !!document.getElementById("websiteVerificationToggle")?.checked;
  const allowedDomainsEnabled = businessFeatures.allowed_domains && !!document.getElementById("allowedDomainsToggle")?.checked;
  const allowedDomains = document.getElementById("allowedDomainsInput")?.value.trim() || "";
  return {
    website_verification_enabled: websiteVerificationEnabled,
    allowed_domains_enabled: allowedDomainsEnabled,
    allowed_domains: allowedDomains,
    verification_status: websiteVerificationEnabled ? "Pending" : "Disabled"
  };
}

async function saveIntegrationSettingsAutomatically() {
  if (integrationAutosaveSaving) {
    integrationAutosaveQueued = true;
    return;
  }
  integrationAutosaveSaving = true;
  try {
    const payload = integrationSettingsPayload();

    if (payload.allowed_domains_enabled && !payload.allowed_domains) {
      updateIntegrationAutosaveStatus("Add at least one allowed domain to save.", "error");
      showToast("Add at least one allowed domain");
      document.getElementById("allowedDomainsInput")?.focus();
      return;
    }

    updateIntegrationAutosaveStatus("Saving changes...");
    const saved = await saveDashboardSettings(payload, {silent: true});

    if (saved) {
      const statusText = document.getElementById("verificationStatusText");
      if (statusText) statusText.textContent = payload.verification_status;
    }
    updateIntegrationAutosaveStatus(saved ? "All changes saved automatically." : "Could not save changes. Please try again.", saved ? "" : "error");
    showToast(saved ? "Integration settings saved" : "Integration settings could not be saved");
  } catch (error) {
    console.error("Integration autosave failed", error);
    updateIntegrationAutosaveStatus("Could not save changes. Please try again.", "error");
  } finally {
    integrationAutosaveSaving = false;
    if (integrationAutosaveQueued) {
      integrationAutosaveQueued = false;
      scheduleIntegrationAutosave();
    }
  }
}

function scheduleIntegrationAutosave() {
  clearTimeout(integrationAutosaveTimer);
  updateIntegrationAutosaveStatus("Changes pending...");
  integrationAutosaveTimer = setTimeout(saveIntegrationSettingsAutomatically, 650);
}

["websiteVerificationToggle", "allowedDomainsToggle"].forEach(id => {
  document.getElementById(id)?.addEventListener("change", scheduleIntegrationAutosave);
});

document.getElementById("allowedDomainsInput")?.addEventListener("input", scheduleIntegrationAutosave);

function validateHumanHandoffEmail(showMessage = false) {
  const input = document.getElementById("humanHandoffEmailInput");
  const value = input?.value.trim() || "";
  const valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
  if (!valid && showMessage) showToast("Enter a valid support email");
  return valid;
}

async function saveHumanHandoffSettings({live = false} = {}) {
  const toggle = document.getElementById("humanHandoffToggle");
  const emailInput = document.getElementById("humanHandoffEmailInput");
  if (!toggle || !emailInput) return;
  if (toggle.checked && !businessFeatures.human_handoff) {
    toggle.checked = false;
    alert("You need Growth or Business plan to ON this functionality");
    openTab("subscription");
    return;
  }
  if (toggle.checked && !validateHumanHandoffEmail(true)) {
    emailInput.focus();
    return;
  }
  const saved = await saveDashboardSettings({
    handoff_enabled: !!toggle.checked,
    handoff_email: emailInput.value.trim()
  });
  if (saved && live) {
    showToast(toggle.checked ? "Human handoff enabled" : "Human handoff disabled");
  }
}

document.getElementById("humanHandoffToggle")?.addEventListener("change", () => {
  saveHumanHandoffSettings({live: true});
});

document.getElementById("humanHandoffEmailInput")?.addEventListener("blur", () => validateHumanHandoffEmail(false));

document.getElementById("saveHumanHandoffBtn")?.addEventListener("click", () => {
  saveHumanHandoffSettings();
});

document.getElementById("saveWebhookBtn")?.addEventListener("click", async event => {
  if (!businessFeatures.webhook_support) {
    showToast("Webhook support requires an active paid plan");
    return;
  }
  const button = event.currentTarget;
  const webhookUrl = document.getElementById("webhookUrlInput")?.value.trim() || "";
  const webhookSecret = document.getElementById("webhookSecretInput")?.value.trim() || "";
  if (webhookUrl && !/^https:\/\/[^\s]+$/i.test(webhookUrl)) {
    showToast("Webhook URL must start with https://");
    document.getElementById("webhookUrlInput")?.focus();
    return;
  }
  button.disabled = true;
  button.textContent = "Saving...";
  await saveDashboardSettings({
    webhook_url: webhookUrl,
    webhook_secret: webhookSecret
  });
  button.disabled = false;
  button.textContent = "Save webhook";
});

document.getElementById("testWebhookBtn")?.addEventListener("click", async event => {
  if (!businessFeatures.webhook_support) {
    showToast("Webhook support requires an active paid plan");
    return;
  }
  const customerId = document.getElementById("settingsCustomerId")?.value || "";
  if (!customerId) return showToast("Select a bot first");
  const button = event.currentTarget;
  button.disabled = true;
  button.textContent = "Testing...";
  const response = await fetch("/api.php?action=test_webhook", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId})
  });
  const data = await response.json().catch(() => ({}));
  button.disabled = false;
  button.textContent = "Test webhook";
  showToast(data.message || (data.success ? "Webhook delivered" : "Webhook test failed"));
});

async function saveLiveChatActionsSettings({live = false} = {}) {
  const toggle = document.getElementById("liveChatActionsToggle");
  if (!toggle) return;
  if (toggle.checked && !businessFeatures.live_chat_actions) {
    toggle.checked = false;
    alert("Live Chat Actions requires Business plan");
    openTab("subscription");
    return;
  }
  const saved = await saveDashboardSettings({
    live_chat_actions_enabled: businessFeatures.live_chat_actions && !!toggle.checked
  });
  if (saved && live) {
    showToast(toggle.checked ? "Live Chat Actions enabled" : "Live Chat Actions disabled");
  }
}

document.getElementById("liveChatActionsToggle")?.addEventListener("change", () => {
  saveLiveChatActionsSettings({live: true});
});

document.getElementById("saveLiveChatActionsBtn")?.addEventListener("click", () => {
  saveLiveChatActionsSettings();
});

function apiKeyRowsHtml(keys) {
  if (!keys.length) {
    return `<tr><td colspan="6" class="empty">No API keys created yet.</td></tr>`;
  }
  return keys.map(key => {
    const revoked = !!key.revoked_at;
    return `<tr data-api-key-id="${htmlEscape(key.id || "")}">
      <td>${htmlEscape(key.name || "API key")}</td>
      <td><code class="api-key-code">${htmlEscape((key.key_prefix || "") + "...")}</code></td>
      <td>${htmlEscape(key.rate_limit_per_day || "")}/day</td>
      <td>${htmlEscape(key.last_used_at || "Never")}</td>
      <td><span class="tag ${revoked ? "bad" : "good"}"><span class="status-dot ${revoked ? "off" : ""}"></span>${revoked ? "Revoked" : "Active"}</span></td>
      <td>${revoked ? `<span class="muted">No action</span>` : `<button class="danger-btn revoke-api-key-btn" type="button">Revoke</button>`}</td>
    </tr>`;
  }).join("");
}

function renderApiKeys(keys) {
  const body = document.getElementById("apiKeysTableBody");
  if (!body) return;
  body.innerHTML = apiKeyRowsHtml(keys || []);
}

async function refreshApiKeys() {
  const customerId = document.getElementById("settingsCustomerId")?.value || "";
  if (!customerId) return;
  const response = await fetch(`/api.php?action=list_customer_api_keys&customer_id=${encodeURIComponent(customerId)}`);
  const data = await response.json().catch(() => ({}));
  if (data.success) renderApiKeys(data.keys || []);
}

document.getElementById("createApiKeyBtn")?.addEventListener("click", async event => {
  if (!businessFeatures.api_access) {
    showToast("API access requires Business plan");
    return;
  }
  const customerId = document.getElementById("settingsCustomerId")?.value || "";
  if (!customerId) return showToast("Select a bot first");
  const button = event.currentTarget;
  button.disabled = true;
  button.textContent = "Creating...";
  const payload = {
    customer_id: customerId,
    name: document.getElementById("apiKeyNameInput")?.value.trim() || "API key",
    rate_limit_per_day: Number(document.getElementById("apiKeyRateLimitInput")?.value || 1000),
    allowed_ips: document.getElementById("apiKeyAllowedIpsInput")?.value.trim() || "",
    allowed_origins: document.getElementById("apiKeyAllowedOriginsInput")?.value.trim() || ""
  };
  const response = await fetch("/api.php?action=create_customer_api_key", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify(payload)
  });
  const data = await response.json().catch(() => ({}));
  button.disabled = false;
  button.textContent = "Create API key";
  if (!data.success) {
    showToast(data.message || "API key could not be created");
    return;
  }
  const reveal = document.getElementById("newApiKeyReveal");
  const code = document.getElementById("newApiKeyCode");
  const copyBtn = document.getElementById("copyNewApiKeyBtn");
  if (reveal && code && copyBtn) {
    reveal.classList.add("active");
    code.textContent = data.api_key || "";
    copyBtn.dataset.copy = data.api_key || "";
  }
  renderApiKeys(data.keys || []);
  showToast("API key created");
});

document.getElementById("apiKeysTableBody")?.addEventListener("click", async event => {
  const button = event.target.closest(".revoke-api-key-btn");
  if (!button) return;
  const row = button.closest("tr");
  const keyId = row?.dataset.apiKeyId || "";
  const customerId = document.getElementById("settingsCustomerId")?.value || "";
  if (!keyId || !customerId) return;
  if (!confirm("Revoke this API key? Existing integrations using it will stop working.")) return;
  button.disabled = true;
  button.textContent = "Revoking...";
  const response = await fetch("/api.php?action=revoke_customer_api_key", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({customer_id: customerId, key_id: keyId})
  });
  const data = await response.json().catch(() => ({}));
  if (!data.success) {
    button.disabled = false;
    button.textContent = "Revoke";
    showToast(data.message || "API key could not be revoked");
    return;
  }
  renderApiKeys(data.keys || []);
  showToast("API key revoked");
});

function updateProfileAvatarPreview(value) {
  const preview = document.getElementById("profileAvatarPreview");
  const firstName = document.getElementById("firstNameInput")?.value.trim() || "";
  const fallback = (firstName || document.getElementById("profileEmailInput")?.value || "V").charAt(0).toUpperCase();
  preview.textContent = "";
  if (value && value.startsWith("http")) {
    const img = document.createElement("img");
    img.src = value;
    img.alt = "Profile avatar";
    preview.appendChild(img);
  } else {
    preview.textContent = value || fallback;
  }
}

document.getElementById("profileAvatarInput")?.addEventListener("input", event => {
  updateProfileAvatarPreview(event.target.value.trim());
});

document.getElementById("generateAvatarBtn")?.addEventListener("click", () => {
  const firstName = document.getElementById("firstNameInput").value.trim();
  const lastName = document.getElementById("lastNameInput").value.trim();
  const email = document.getElementById("profileEmailInput").value.trim();
  const initials = ((firstName.charAt(0) || email.charAt(0) || "V") + (lastName.charAt(0) || "")).toUpperCase();
  document.getElementById("profileAvatarInput").value = initials;
  updateProfileAvatarPreview(initials);
});

document.getElementById("saveProfileBtn")?.addEventListener("click", async () => {
  const newPassword = document.getElementById("newPasswordInput").value;
  const confirmPassword = document.getElementById("confirmPasswordInput").value;

  if (newPassword || confirmPassword) {
    if (newPassword !== confirmPassword) return showToast("Passwords do not match");
    if (newPassword.length < 8) return showToast("Password needs at least 8 characters");
  }

  const response = await fetch("/api.php?action=save_customer_profile", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({
      email: document.getElementById("profileEmailInput").value.trim(),
      first_name: document.getElementById("firstNameInput").value.trim(),
      last_name: document.getElementById("lastNameInput").value.trim(),
      avatar_url: document.getElementById("profileAvatarInput").value.trim(),
      country_code: document.getElementById("countryCodeInput").value.trim(),
      mobile_number: document.getElementById("mobileInput").value.trim(),
      address_line1: document.getElementById("address1Input").value.trim(),
      address_line2: document.getElementById("address2Input").value.trim(),
      city: document.getElementById("cityInput").value.trim(),
      state_region: document.getElementById("stateInput").value.trim(),
      country: document.getElementById("countryInput").value.trim(),
      postal_code: document.getElementById("postalInput").value.trim(),
      location_notes: document.getElementById("locationInput").value.trim(),
      new_password: newPassword
    })
  });

  const data = await response.json().catch(() => ({}));
  if (!data.success) {
    showToast(data.message || "Profile could not be saved");
    return;
  }
  document.getElementById("newPasswordInput").value = "";
  document.getElementById("confirmPasswordInput").value = "";
  showToast(data.password ? "Profile and password saved" : "Profile saved");
});

const hash = location.hash.replace("#", "");
if (hash && !hash.includes("/") && document.getElementById(hash)) openTab(hash);

