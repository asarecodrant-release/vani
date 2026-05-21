(function () {
  const script = document.currentScript || document.querySelector("script[src*='widget']");
  const customerId = script?.getAttribute("data-id") ||
    script?.getAttribute("data-key") ||
    script?.getAttribute("data-customer-id");

  if (!customerId) {
    console.error("Vani widget: missing data-id");
    return;
  }

  const apiBase = "https://vani.codrant.com/widget_api.php";
  const imageBase = "https://cdn.jsdelivr.net/gh/asarecodrant-release/vani@main/";
  const defaultGreeting = "Hi, how can I help you today?";
  const msg91OtpScriptUrls = [
    "https://verify.msg91.com/otp-provider.js",
    "https://verify.phone91.com/otp-provider.js"
  ];
  const countryDialCodes = [
    ["IN", "+91", "India"],
    ["US", "+1", "United States"],
    ["GB", "+44", "United Kingdom"],
    ["AE", "+971", "United Arab Emirates"],
    ["CA", "+1", "Canada"],
    ["AU", "+61", "Australia"],
    ["SG", "+65", "Singapore"],
    ["MY", "+60", "Malaysia"],
    ["NP", "+977", "Nepal"],
    ["BD", "+880", "Bangladesh"],
    ["LK", "+94", "Sri Lanka"],
    ["PK", "+92", "Pakistan"],
    ["SA", "+966", "Saudi Arabia"],
    ["QA", "+974", "Qatar"],
    ["KW", "+965", "Kuwait"],
    ["OM", "+968", "Oman"],
    ["ZA", "+27", "South Africa"],
    ["DE", "+49", "Germany"],
    ["FR", "+33", "France"],
    ["NL", "+31", "Netherlands"]
  ];
  let config = {};
  let msg91OtpScriptPromise = null;

  let userId = localStorage.getItem("vani_widget_user_id");
  if (!userId) {
    userId = window.crypto?.randomUUID
      ? window.crypto.randomUUID()
      : "user-" + Date.now() + "-" + Math.random().toString(16).slice(2);
    localStorage.setItem("vani_widget_user_id", userId);
  }

  async function api(action, method = "GET", body = null, query = "") {
    try {
      const response = await fetch(`${apiBase}?action=${action}${query}`, {
        method,
        headers: {"Content-Type": "application/json"},
        body: body ? JSON.stringify(body) : null
      });
      const payload = await response.json();
      if (!response.ok) {
        console.warn("Vani widget API returned an error:", action, payload);
      }
      return payload;
    } catch (error) {
      console.error("Vani widget API error:", error);
      return {};
    }
  }

  // Lead flow state per customer
  function leadStorageKey(customerId) {
    return `vani_lead_state_${customerId}_${userId}`;
  }

  function loadLeadState(customerId) {
    const defaults = {
      verified: false,
      leadId: null,
      email: null,
      phone: null,
      locationSaved: false,
      emailSaved: false,
      emailVerified: false,
      mobileSaved: false,
      mobileVerified: false,
      expecting: null
    };
    try {
      const raw = localStorage.getItem(leadStorageKey(customerId));
      const state = raw ? Object.assign(defaults, JSON.parse(raw)) : defaults;
      if (state.verified && !state.emailVerified) {
        state.emailVerified = true;
        state.emailSaved = true;
      }
      return state;
    } catch (e) { return defaults; }
  }

  function saveLeadState(customerId, state) {
    try { localStorage.setItem(leadStorageKey(customerId), JSON.stringify(state)); } catch (e) {}
  }

  function isEnabled(value) {
    return value === true || value === 1 || value === "1" || value === "true";
  }

  function sessionStorageKey(customerId) {
    return `vani_widget_session_id_${customerId}`;
  }

  let sessionId = sessionStorage.getItem(sessionStorageKey(customerId));
  if (!sessionId) {
    sessionId = window.crypto?.randomUUID
      ? window.crypto.randomUUID()
      : "session-" + Date.now() + "-" + Math.random().toString(16).slice(2);
    sessionStorage.setItem(sessionStorageKey(customerId), sessionId);
  }
  const sessionStartedAt = Date.now();
  let sessionOpenedAt = null;
  let sessionChatStartedAt = null;
  let sessionMessageCount = 0;

  function browserInfo() {
    const ua = navigator.userAgent || "";
    const rules = [
      ["Edge", /Edg\/([\d.]+)/],
      ["Chrome", /Chrome\/([\d.]+)/],
      ["Safari", /Version\/([\d.]+).*Safari/],
      ["Firefox", /Firefox\/([\d.]+)/],
      ["Opera", /OPR\/([\d.]+)/]
    ];
    for (const [name, pattern] of rules) {
      const match = ua.match(pattern);
      if (match) return {name, version: match[1] || ""};
    }
    return {name: "Other", version: ""};
  }

  function osName() {
    const ua = navigator.userAgent || "";
    if (/windows/i.test(ua)) return "Windows";
    if (/android/i.test(ua)) return "Android";
    if (/iphone|ipad|ipod/i.test(ua)) return "iOS";
    if (/mac os|macintosh/i.test(ua)) return "macOS";
    if (/linux/i.test(ua)) return "Linux";
    return "Other";
  }

  function deviceType() {
    const ua = navigator.userAgent || "";
    if (/ipad|tablet/i.test(ua)) return "Tablet";
    if (/mobi|android|iphone|ipod/i.test(ua)) return "Mobile";
    return "Desktop";
  }

  function localeRegion() {
    const locale = navigator.language || "";
    const region = locale.match(/-([A-Z]{2})\b/i)?.[1]?.toUpperCase() || "";
    let country = "";
    try {
      if (region && Intl.DisplayNames) {
        country = new Intl.DisplayNames([locale || "en"], {type: "region"}).of(region) || "";
      }
    } catch (error) {}
    return {locale, region, country};
  }

  function analyticsPayload(extra = {}) {
    const browser = browserInfo();
    const region = localeRegion();
    return {
      session_id: sessionId,
      source_url: window.location.href,
      current_page: window.location.pathname || window.location.href,
      referrer_url: document.referrer || "",
      device_type: deviceType(),
      browser_name: browser.name,
      browser_version: browser.version,
      os_name: osName(),
      country_code: region.region,
      country_name: region.country,
      city: "",
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || "",
      locale: region.locale,
      screen_width: window.screen?.width || window.innerWidth || 0,
      screen_height: window.screen?.height || window.innerHeight || 0,
      ...extra
    };
  }

  function sessionDurationSeconds() {
    return Math.max(0, Math.round((Date.now() - sessionStartedAt) / 1000));
  }

  async function trackWidgetSession(extra = {}) {
    const payload = {
      customer_id: customerId,
      user_id: userId,
      session_id: sessionId,
      source_url: window.location.href,
      duration_seconds: sessionDurationSeconds(),
      message_count: sessionMessageCount,
      analytics: analyticsPayload(),
      ...extra
    };
    await api("track_widget_session", "POST", payload);
  }

  function trackWidgetSessionSoon(extra = {}) {
    trackWidgetSession(extra).catch(() => {});
  }

  function css(node, styles) {
    Object.assign(node.style, styles);
  }

  function resolveAssetUrl(value) {
    const path = (value || "").trim();
    if (!path) return "";
    if (/^https?:\/\//i.test(path)) return path;

    try {
      return new URL(path.replace(/^\/+/, ""), imageBase).href;
    } catch (error) {
      console.warn("Vani widget: could not resolve asset URL", value);
      return path;
    }
  }

  function addMessage(messages, text, type) {
    const bubble = document.createElement("div");
    bubble.textContent = text;
    css(bubble, {
      margin: "7px 0",
      padding: "10px 12px",
      borderRadius: "12px",
      maxWidth: "82%",
      fontSize: "14px",
      lineHeight: "1.45",
      whiteSpace: "pre-wrap",
      wordBreak: "break-word",
      background: type === "user" ? (config.theme_color || "#6366f1") : "#eef2ff",
      color: type === "user" ? "#fff" : "#0f172a",
      marginLeft: type === "user" ? "auto" : "0"
    });
    messages.appendChild(bubble);
    messages.scrollTop = messages.scrollHeight;
  }

  function renderSuggestions(suggestionsBox, input, items) {
    suggestionsBox.innerHTML = "";
    items.forEach(item => {
      const option = document.createElement("button");
      option.type = "button";
      option.textContent = item.question;
      css(option, {
        width: "100%",
        border: "0",
        borderBottom: "1px solid #e5e7eb",
        background: "transparent",
        color: "#0f172a",
        padding: "9px 10px",
        textAlign: "left",
        cursor: "pointer",
        fontSize: "13px"
      });
      option.onmouseenter = () => option.style.background = "#f8fafc";
      option.onmouseleave = () => option.style.background = "transparent";
      option.onclick = async () => {
        input.value = item.question;
        await trackUsage(item.id);
        window.sendMessage();
      };
      suggestionsBox.appendChild(option);
    });
  }

  const tracked = new Set();

  async function trackUsage(questionId) {
    if (!questionId || tracked.has(questionId)) return;
    tracked.add(questionId);
    await api("track_faq_usage", "POST", {
      customer_id: customerId,
      question_id: questionId,
      user_id: userId
    });
  }

  async function boot() {
    const cfg = await api(
      "get_widget_config",
      "GET",
      null,
      `&customer_id=${encodeURIComponent(customerId)}&current_url=${encodeURIComponent(window.location.href)}`
    );
    config = cfg || {};

    if (config.is_active === false || config.access_allowed === false) {
      return;
    }

    const color = config.theme_color || "#6366f1";
    const position = config.position === "left" ? "left" : "right";
    const sideStyles = position === "left" ? {left: "20px"} : {right: "20px"};
    const greetingSideStyles = position === "left" ? {left: "90px"} : {right: "90px"};
    const avatarUrl = resolveAssetUrl(config.avatar_url);
    const greetingText = (config.welcome_message || defaultGreeting).trim() || defaultGreeting;
    trackWidgetSessionSoon();

    // Add breathing animation
    const style = document.createElement("style");
    style.textContent = `
      @keyframes breathing {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-8px); }
      }
      .vani-breathing-icon {
        animation: breathing 3s ease-in-out infinite;
      }
      .vani-breathing-greeting {
        animation: breathing 3s ease-in-out infinite;
      }
    `;
    document.head.appendChild(style);

    const icon = document.createElement("button");
    icon.type = "button";
    icon.setAttribute("aria-label", "Open chat");
    icon.classList.add("vani-breathing-icon");
    css(icon, {
      position: "fixed",
      bottom: "20px",
      width: "66px",
      height: "66px",
      border: "0",
      borderRadius: "50%",
      background: avatarUrl ? "transparent" : color,
      color: avatarUrl ? color : "#fff",
      cursor: "pointer",
      display: "flex",
      alignItems: "center",
      justifyContent: "center",
      fontWeight: "700",
      overflow: "hidden",
      padding: avatarUrl ? "0" : "0",
      zIndex: "999999",
      boxShadow: "0 12px 28px rgba(255, 255, 255, 0)"
    });
    css(icon, sideStyles);

    if (avatarUrl) {
      const iconImage = document.createElement("img");
      iconImage.src = avatarUrl;
      iconImage.alt = "";
      css(iconImage, {
        width: "80%",
        height: "100%",
        borderRadius: "0%",
        objectFit: "contain",
        display: "block",
        background: "transparent",
        boxShadow: "none"
      });
      iconImage.onerror = () => {
        iconImage.remove();
        icon.textContent = "Chat";
        css(icon, {
          background: color,
          color: "#fff",
          padding: "0"
        });
      };
      icon.appendChild(iconImage);
    } else {
      icon.textContent = "Chat";
    }

    const greeting = document.createElement("button");
    greeting.type = "button";
    greeting.textContent = greetingText;
    greeting.setAttribute("aria-label", "Open chat");
    greeting.classList.add("vani-breathing-greeting");
    css(greeting, {
      position: "fixed",
      bottom: "30px",
      maxWidth: "min(240px, calc(100vw - 118px))",
      border: "1px solid #e5e7eb",
      borderRadius: "14px",
      background: "#fff",
      color: "#0f172a",
      padding: "10px 12px",
      cursor: "pointer",
      fontSize: "14px",
      lineHeight: "1.35",
      textAlign: "left",
      zIndex: "999998",
      boxShadow: "0 12px 28px rgb(255, 255, 255)",
      fontFamily: "Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
    });
    css(greeting, greetingSideStyles);

    const box = document.createElement("div");
    css(box, {
      position: "fixed",
      bottom: "90px",
      width: "min(360px, calc(100vw - 28px))",
      height: "min(520px, calc(100vh - 118px))",
      background: "#fff",
      borderRadius: "16px",
      display: "none",
      flexDirection: "column",
      overflow: "hidden",
      zIndex: "999999",
      boxShadow: "0 18px 48px rgba(15,23,42,.22)",
      border: "1px solid #e5e7eb",
      fontFamily: "Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
    });
    css(box, sideStyles);

    box.innerHTML = `
      <div data-vani-header style="padding:13px 14px;color:#fff;background:${color};font-weight:700;display:flex;align-items:center;gap:10px;">
        <span data-vani-title></span>
      </div>
      <div data-vani-messages style="flex:1;overflow:auto;padding:12px;background:#f8fafc;"></div>
      <div data-vani-suggestions style="max-height:132px;overflow:auto;background:#fff;border-top:1px solid #e5e7eb;"></div>
      <div data-vani-lead-prompt style="display:none;padding:10px;border-top:1px solid #e5e7eb;background:#fff;"></div>
      <div data-vani-whatsapp-action style="display:none;padding:10px;border-top:1px solid #e5e7eb;background:#fff;"></div>
      <div style="display:flex;border-top:1px solid #e5e7eb;background:#fff;">
        <input data-vani-input placeholder="Type message..." style="flex:1;min-width:0;padding:12px;border:0;outline:0;font:inherit;">
        <button data-vani-send type="button" style="padding:0 15px;background:${color};color:#fff;border:0;font-weight:700;cursor:pointer;">Send</button>
      </div>
    `;

    document.body.appendChild(icon);
    document.body.appendChild(greeting);
    document.body.appendChild(box);

    const messages = box.querySelector("[data-vani-messages]");
    const input = box.querySelector("[data-vani-input]");
    const sendBtn = box.querySelector("[data-vani-send]");
    const suggestionsBox = box.querySelector("[data-vani-suggestions]");
    const whatsappAction = box.querySelector("[data-vani-whatsapp-action]");
    box.querySelector("[data-vani-title]").textContent = config.bot_name || "Chat Support";
    let debounce;

    addMessage(messages, greetingText, "bot");

    async function loadTop() {
      const response = await api("get_top_faqs", "GET", null, `&customer_id=${encodeURIComponent(customerId)}`);
      renderSuggestions(suggestionsBox, input, response.data || []);
    }

    async function searchFaqs(query) {
      if (!query) {
        await loadTop();
        return;
      }
      const response = await api(
        "search_faqs",
        "GET",
        null,
        `&customer_id=${encodeURIComponent(customerId)}&q=${encodeURIComponent(query)}`
      );
      renderSuggestions(suggestionsBox, input, response.data || []);
    }

    function leadFlowComplete(leadCfg, leadState) {
      if (!isEnabled(leadCfg.is_enabled)) return true;
      const needsLocation = isEnabled(leadCfg.collect_location);
      const verifyEmailOtp = isEnabled(leadCfg.verify_email_otp);
      const verifyMobileOtp = isEnabled(leadCfg.verify_mobile_otp);
      const needsEmail = isEnabled(leadCfg.collect_email) || verifyEmailOtp;
      const needsMobile = isEnabled(leadCfg.collect_mobile) || verifyMobileOtp;
      return (!needsLocation || leadState.locationSaved) &&
        (!needsEmail || (verifyEmailOtp ? leadState.emailVerified : leadState.emailSaved)) &&
        (!needsMobile || (verifyMobileOtp ? leadState.mobileVerified : leadState.mobileSaved));
    }

    function validEmail(value) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    }

    function validPhone(value) {
      return /^\+?[1-9][0-9]{7,14}$/.test(value);
    }

    function normalizePhone(value) {
      return (value || "").trim().replace(/[^\d+]/g, "").replace(/(?!^)\+/g, "");
    }

    function whatsappPhone(value) {
      return normalizePhone(value).replace(/\D+/g, "");
    }

    function isMobileDevice() {
      return /Android|iPhone|iPad|iPod|IEMobile|Mobile/i.test(navigator.userAgent || "");
    }

    function nestedValue(data, keys) {
      if (typeof data === "string") {
        return data.trim();
      }
      for (const key of keys) {
        const parts = key.split(".");
        let value = data;
        for (const part of parts) {
          if (!value || typeof value !== "object" || !(part in value)) {
            value = null;
            break;
          }
          value = value[part];
        }
        if (value !== null && value !== undefined && value !== "") {
          return String(value).trim();
        }
      }
      return "";
    }

    function findNestedToken(data) {
      const wantedKeys = new Set(["accesstoken", "jwttoken", "token"]);
      const jwtPattern = /^eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/;
      const seen = new Set();

      function visit(value, key = "") {
        if (value === null || value === undefined || seen.has(value)) return "";
        if (typeof value === "string") {
          const text = value.trim();
          if (wantedKeys.has(key.toLowerCase().replace(/[^a-z0-9]/g, "")) || jwtPattern.test(text)) {
            return text;
          }
          return "";
        }
        if (typeof value !== "object") return "";
        seen.add(value);
        for (const [childKey, childValue] of Object.entries(value)) {
          const found = visit(childValue, childKey);
          if (found) return found;
        }
        return "";
      }

      return visit(data);
    }

    function msg91AccessToken(data) {
      return nestedValue(data || {}, [
        "access-token",
        "accessToken",
        "access_token",
        "accessToken.access-token",
        "jwt_token",
        "jwtToken",
        "jwt",
        "token",
        "data.access-token",
        "data.accessToken",
        "data.access_token",
        "data.jwt_token",
        "data.jwtToken",
        "data.jwt",
        "data.token"
      ]) || findNestedToken(data);
    }

    function msg91Identifier(data) {
      return nestedValue(data || {}, [
        "identifier",
        "mobile",
        "phone",
        "phone_number",
        "mobile_number",
        "mobileNumber",
        "contact",
        "contact_point",
        "contactPoint",
        "userIdentifier",
        "user_identifier",
        "data.identifier",
        "data.mobile",
        "data.phone",
        "data.phone_number",
        "data.mobile_number",
        "data.mobileNumber",
        "data.contact",
        "data.contact_point",
        "data.contactPoint",
        "user.identifier",
        "user.mobile",
        "user.phone",
        "user.phone_number",
        "user.mobile_number",
        "user.mobileNumber"
      ]);
    }

    function msg91RequestId(data) {
      return nestedValue(data || {}, [
        "reqId",
        "req_id",
        "requestId",
        "request_id",
        "data.reqId",
        "data.req_id",
        "data.requestId",
        "data.request_id"
      ]);
    }

    function promptWrap() {
      const wrap = document.createElement("div");
      css(wrap, { display: "flex", gap: "8px", alignItems: "center" });
      return wrap;
    }

    function promptInput(type, placeholder) {
      const inputNode = document.createElement("input");
      inputNode.type = type;
      inputNode.placeholder = placeholder;
      css(inputNode, { padding: "8px", border: "1px solid #e5e7eb", borderRadius: "10px", marginRight: "8px", flex: "1", minWidth: "0" });
      return inputNode;
    }

    function promptButton(text) {
      const button = document.createElement("button");
      button.type = "button";
      button.textContent = text;
      css(button, { padding: "8px 12px", borderRadius: "10px", border: "1px solid #e5e7eb", background: "#fff", cursor: "pointer", whiteSpace: "nowrap" });
      return button;
    }

    function renderWhatsAppAction() {
      if (!whatsappAction) return;
      const leadCfg = config.lead_generation || {};
      const phone = whatsappPhone(leadCfg.whatsapp_mobile_number || "");
      if (!isEnabled(leadCfg.redirect_whatsapp) || !validPhone(phone)) {
        whatsappAction.style.display = "none";
        whatsappAction.innerHTML = "";
        return;
      }

      whatsappAction.innerHTML = "";
      whatsappAction.style.display = "block";

      const button = document.createElement("button");
      button.type = "button";
      button.textContent = "Contact with us on WhatsApp";
      css(button, {
        width: "100%",
        border: "0",
        borderRadius: "10px",
        background: "#25D366",
        color: "#fff",
        padding: "10px 12px",
        cursor: "pointer",
        fontWeight: "700",
        fontSize: "14px",
        lineHeight: "1.25",
        boxShadow: "0 8px 18px rgba(37, 211, 102, .25)"
      });
      button.onmouseenter = () => button.style.background = "#1ebe5d";
      button.onmouseleave = () => button.style.background = "#25D366";
      button.onclick = async () => {
        await api("create_lead", "POST", {
          customer_id: customerId,
          user_id: userId,
          source_url: window.location.href,
          whatsapp_redirected: true
        });

        if (isMobileDevice()) {
          window.location.href = `https://wa.me/${phone}`;
          return;
        }

        const launchedAt = Date.now();
        const link = document.createElement("a");
        link.href = `whatsapp://send?phone=${phone}`;
        link.style.display = "none";
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => {
          if (Date.now() - launchedAt < 2400 && !document.hidden) {
            addMessage(messages, "You cannot go to WhatsApp from here because this is a mobile function.", "bot");
          }
        }, 1800);
      };

      whatsappAction.appendChild(button);
    }

    function requiredPromptButton(text) {
      const button = promptButton("");
      const label = document.createElement("span");
      const required = document.createElement("span");
      label.textContent = text;
      required.textContent = " *";
      css(required, { color: "#dc2626", fontWeight: "800" });
      button.appendChild(label);
      button.appendChild(required);
      return button;
    }

    function populateCountrySelect(select) {
      countryDialCodes.forEach(([iso, dialCode, country]) => {
        const option = document.createElement("option");
        option.value = dialCode;
        option.textContent = `${iso} ${dialCode}`;
        option.title = country;
        select.appendChild(option);
      });
      select.value = "+91";
    }

    function loadMsg91OtpProvider() {
      if (typeof window.initSendOTP === "function") {
        return Promise.resolve();
      }
      if (msg91OtpScriptPromise) {
        return msg91OtpScriptPromise;
      }

      msg91OtpScriptPromise = new Promise((resolve, reject) => {
        let i = 0;
        function attempt() {
          const scriptNode = document.createElement("script");
          scriptNode.src = msg91OtpScriptUrls[i];
          scriptNode.async = true;
          scriptNode.onload = () => {
            if (typeof window.initSendOTP === "function") {
              resolve();
              return;
            }
            reject(new Error("MSG91 OTP provider did not initialize"));
          };
          scriptNode.onerror = () => {
            i++;
            if (i < msg91OtpScriptUrls.length) {
              attempt();
              return;
            }
            reject(new Error("MSG91 OTP provider could not be loaded"));
          };
          document.head.appendChild(scriptNode);
        }
        attempt();
      });

      return msg91OtpScriptPromise;
    }

    function openMobileOtpWidget(phone = "") {
      return new Promise((resolve, reject) => {
        const msg91Cfg = config.msg91_widget || {};
        if (!msg91Cfg.configured || !msg91Cfg.widget_id || !msg91Cfg.token_auth) {
          reject(new Error("MSG91 widget is not configured"));
          return;
        }

        const timeout = window.setTimeout(() => {
          cleanup();
          reject(new Error("Mobile OTP timed out"));
        }, 10 * 60 * 1000);

        function cleanup() {
          window.clearTimeout(timeout);
          overlay.remove();
          if (previousSendOtp === undefined) {
            try { delete window.sendOtp; } catch (e) {}
          } else {
            window.sendOtp = previousSendOtp;
          }
          if (previousVerifyOtp === undefined) {
            try { delete window.verifyOtp; } catch (e) {}
          } else {
            window.verifyOtp = previousVerifyOtp;
          }
        }

        const previousSendOtp = window.sendOtp;
        const previousVerifyOtp = window.verifyOtp;
        const overlay = document.createElement("div");
        const dialog = document.createElement("div");
        const title = document.createElement("div");
        const phoneRow = document.createElement("div");
        const countrySelect = document.createElement("select");
        const phoneInput = promptInput("tel", "98765 43210");
        const otpInput = promptInput("text", "Enter OTP");
        const captchaMount = document.createElement("div");
        const status = document.createElement("div");
        const actions = document.createElement("div");
        const sendOtpBtn = promptButton("Send OTP");
        const verifyOtpBtn = promptButton("Verify OTP");
        const closeBtn = promptButton("Cancel");
        const captchaId = "vani-msg91-captcha-" + Date.now() + "-" + Math.random().toString(16).slice(2);
        let activePhone = "";
        let requestId = "";

        function setStatus(message, isError = false) {
          status.textContent = message;
          status.style.color = isError ? "#dc2626" : "#475569";
        }

        css(overlay, {
          position: "fixed",
          inset: "0",
          background: "rgba(15,23,42,.48)",
          zIndex: "1000000",
          display: "grid",
          placeItems: "center",
          padding: "18px"
        });
        css(dialog, {
          width: "min(380px, 100%)",
          background: "#fff",
          borderRadius: "16px",
          padding: "16px",
          boxShadow: "0 20px 60px rgba(15,23,42,.32)",
          fontFamily: "Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
        });
        css(title, { fontWeight: "700", color: "#0f172a", marginBottom: "12px" });
        css(phoneRow, { display: "flex", gap: "8px", alignItems: "center" });
        css(countrySelect, {
          width: "112px",
          flex: "0 0 112px",
          padding: "8px",
          border: "1px solid #e5e7eb",
          borderRadius: "10px",
          background: "#fff",
          color: "#0f172a",
          font: "inherit",
          boxSizing: "border-box"
        });
        css(phoneInput, { width: "100%", marginRight: "0", boxSizing: "border-box" });
        css(otpInput, { width: "100%", marginRight: "0", boxSizing: "border-box", display: "none", marginTop: "10px" });
        css(captchaMount, { marginTop: "10px" });
        css(status, { minHeight: "18px", marginTop: "10px", fontSize: "13px", lineHeight: "1.35" });
        css(actions, { display: "flex", gap: "8px", justifyContent: "flex-end", marginTop: "12px" });
        title.textContent = "Mobile verification";
        phoneInput.inputMode = "tel";
        phoneInput.autocomplete = "tel-national";
        otpInput.inputMode = "numeric";
        otpInput.maxLength = 8;
        captchaMount.id = captchaId;
        verifyOtpBtn.style.display = "none";
        populateCountrySelect(countrySelect);
        closeBtn.onclick = () => {
          cleanup();
          reject(new Error("Mobile OTP cancelled"));
        };
        sendOtpBtn.onclick = () => {
          const nationalNumber = (phoneInput.value || "").replace(/\D+/g, "");
          const normalized = normalizePhone(countrySelect.value + nationalNumber);
          if (!validPhone(normalized)) {
            setStatus("Enter a valid mobile number.", true);
            return;
          }
          if (typeof window.sendOtp !== "function") {
            setStatus("MSG91 OTP is still loading. Try again.", true);
            return;
          }
          activePhone = normalized;
          sendOtpBtn.disabled = true;
          setStatus("Sending OTP...");
          window.sendOtp(
            activePhone.replace(/^\+/, ""),
            data => {
              requestId = msg91RequestId(data);
              countrySelect.disabled = true;
              phoneInput.disabled = true;
              otpInput.style.display = "block";
              sendOtpBtn.style.display = "none";
              verifyOtpBtn.style.display = "inline-block";
              verifyOtpBtn.disabled = false;
              otpInput.focus();
              setStatus("OTP sent. Enter it to verify.");
            },
            error => {
              sendOtpBtn.disabled = false;
              console.error("MSG91 send OTP failed:", error);
              setStatus("Could not send OTP. Try again.", true);
            }
          );
        };
        verifyOtpBtn.onclick = () => {
          const otp = (otpInput.value || "").trim();
          if (!/^[0-9]{4,8}$/.test(otp)) {
            setStatus("Enter the OTP sent to your mobile.", true);
            return;
          }
          if (typeof window.verifyOtp !== "function") {
            setStatus("MSG91 OTP is still loading. Try again.", true);
            return;
          }
          verifyOtpBtn.disabled = true;
          setStatus("Verifying OTP...");
          window.verifyOtp(
            otp,
            data => {
              const accessToken = msg91AccessToken(data);
              cleanup();
              if (!accessToken) {
                reject(new Error("MSG91 did not return an access token"));
                return;
              }
              resolve({
                phone: activePhone,
                msg91_access_token: accessToken,
                msg91_response: data || {}
              });
            },
            error => {
              verifyOtpBtn.disabled = false;
              console.error("MSG91 verify OTP failed:", error);
              setStatus("OTP verification failed. Check the code and try again.", true);
            },
            requestId || undefined
          );
        };

        actions.appendChild(closeBtn);
        actions.appendChild(sendOtpBtn);
        actions.appendChild(verifyOtpBtn);
        dialog.appendChild(title);
        phoneRow.appendChild(countrySelect);
        phoneRow.appendChild(phoneInput);
        dialog.appendChild(phoneRow);
        dialog.appendChild(otpInput);
        dialog.appendChild(captchaMount);
        dialog.appendChild(status);
        dialog.appendChild(actions);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        loadMsg91OtpProvider()
          .then(() => {
            window.initSendOTP({
              widgetId: msg91Cfg.widget_id,
              tokenAuth: msg91Cfg.token_auth,
              identifier: phone ? phone.replace(/^\+/, "") : "",
              exposeMethods: true,
              captchaRenderId: captchaId,
              success: data => {
                const accessToken = msg91AccessToken(data);
                const verifiedPhone = normalizePhone(msg91Identifier(data) || phone);
                if (!accessToken) {
                  return;
                }
                cleanup();
                resolve({
                  phone: verifiedPhone || activePhone,
                  msg91_access_token: accessToken,
                  msg91_response: data || {}
                });
              },
              failure: error => {
                console.error("MSG91 mobile OTP failed:", error);
              }
            });
            setStatus("Enter your mobile number to receive OTP.");
            phoneInput.focus();
          })
          .catch(error => {
            cleanup();
            reject(error);
          });
      });
    }

    function openIdentityOtpWidget() {
      return new Promise((resolve, reject) => {
        const msg91Cfg = config.msg91_widget || {};
        if (!msg91Cfg.configured || !msg91Cfg.widget_id || !msg91Cfg.token_auth) {
          reject(new Error("MSG91 widget is not configured"));
          return;
        }

        const previousSendOtp = window.sendOtp;
        const previousVerifyOtp = window.verifyOtp;
        const overlay = document.createElement("div");
        const dialog = document.createElement("div");
        const title = document.createElement("div");
        const emailInput = promptInput("email", "Email address");
        const phoneRow = document.createElement("div");
        const countrySelect = document.createElement("select");
        const phoneInput = promptInput("tel", "98765 43210");
        const emailOtpInput = promptInput("text", "Email OTP");
        const mobileOtpInput = promptInput("text", "Mobile OTP");
        const captchaMount = document.createElement("div");
        const status = document.createElement("div");
        const actions = document.createElement("div");
        const sendOtpBtn = promptButton("Send OTPs");
        const verifyOtpBtn = promptButton("Verify Identity");
        const closeBtn = promptButton("Cancel");
        const captchaId = "vani-msg91-identity-captcha-" + Date.now() + "-" + Math.random().toString(16).slice(2);
        let activeEmail = "";
        let activePhone = "";
        let requestId = "";
        let leadId = null;

        const timeout = window.setTimeout(() => {
          cleanup();
          reject(new Error("Identity verification timed out"));
        }, 10 * 60 * 1000);

        function cleanup() {
          window.clearTimeout(timeout);
          overlay.remove();
          if (previousSendOtp === undefined) {
            try { delete window.sendOtp; } catch (e) {}
          } else {
            window.sendOtp = previousSendOtp;
          }
          if (previousVerifyOtp === undefined) {
            try { delete window.verifyOtp; } catch (e) {}
          } else {
            window.verifyOtp = previousVerifyOtp;
          }
        }

        function setStatus(message, isError = false) {
          status.textContent = message;
          status.style.color = isError ? "#dc2626" : "#475569";
        }

        css(overlay, {
          position: "fixed",
          inset: "0",
          background: "rgba(15,23,42,.48)",
          zIndex: "1000000",
          display: "grid",
          placeItems: "center",
          padding: "18px"
        });
        css(dialog, {
          width: "min(410px, 100%)",
          background: "#fff",
          borderRadius: "16px",
          padding: "16px",
          boxShadow: "0 20px 60px rgba(15,23,42,.32)",
          fontFamily: "Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
        });
        css(title, { fontWeight: "700", color: "#0f172a", marginBottom: "12px" });
        css(emailInput, { width: "100%", marginRight: "0", boxSizing: "border-box", marginBottom: "10px" });
        css(phoneRow, { display: "flex", gap: "8px", alignItems: "center" });
        css(countrySelect, {
          width: "112px",
          flex: "0 0 112px",
          padding: "8px",
          border: "1px solid #e5e7eb",
          borderRadius: "10px",
          background: "#fff",
          color: "#0f172a",
          font: "inherit",
          boxSizing: "border-box"
        });
        css(phoneInput, { width: "100%", marginRight: "0", boxSizing: "border-box" });
        css(emailOtpInput, { width: "100%", marginRight: "0", boxSizing: "border-box", display: "none", marginTop: "10px" });
        css(mobileOtpInput, { width: "100%", marginRight: "0", boxSizing: "border-box", display: "none", marginTop: "10px" });
        css(captchaMount, { marginTop: "10px" });
        css(status, { minHeight: "18px", marginTop: "10px", fontSize: "13px", lineHeight: "1.35" });
        css(actions, { display: "flex", gap: "8px", justifyContent: "flex-end", marginTop: "12px" });

        title.textContent = "Verify your identity";
        emailInput.autocomplete = "email";
        phoneInput.inputMode = "tel";
        phoneInput.autocomplete = "tel-national";
        emailOtpInput.inputMode = "numeric";
        emailOtpInput.maxLength = 6;
        mobileOtpInput.inputMode = "numeric";
        mobileOtpInput.maxLength = 8;
        captchaMount.id = captchaId;
        verifyOtpBtn.style.display = "none";
        populateCountrySelect(countrySelect);

        closeBtn.onclick = () => {
          cleanup();
          reject(new Error("Identity verification cancelled"));
        };

        sendOtpBtn.onclick = async () => {
          const email = (emailInput.value || "").trim();
          const nationalNumber = (phoneInput.value || "").replace(/\D+/g, "");
          const phone = normalizePhone(countrySelect.value + nationalNumber);
          if (!validEmail(email)) {
            setStatus("Enter a valid email address.", true);
            return;
          }
          if (!validPhone(phone)) {
            setStatus("Enter a valid mobile number.", true);
            return;
          }
          if (typeof window.sendOtp !== "function") {
            setStatus("MSG91 OTP is still loading. Try again.", true);
            return;
          }

          activeEmail = email;
          activePhone = phone;
          sendOtpBtn.disabled = true;
          setStatus("Sending OTPs...");

          const emailRes = await api("create_lead_send_email_otp", "POST", {
            customer_id: customerId,
            user_id: userId,
            email,
            source_url: window.location.href,
            suppress_notification: true
          });
          if (!emailRes.success || !emailRes.lead) {
            sendOtpBtn.disabled = false;
            setStatus(emailRes.email_error || emailRes.message || "Could not send email OTP. Try again.", true);
            return;
          }

          leadId = emailRes.lead.id || null;
          window.sendOtp(
            activePhone.replace(/^\+/, ""),
            data => {
              requestId = msg91RequestId(data);
              emailInput.disabled = true;
              countrySelect.disabled = true;
              phoneInput.disabled = true;
              emailOtpInput.style.display = "block";
              mobileOtpInput.style.display = "block";
              sendOtpBtn.style.display = "none";
              verifyOtpBtn.style.display = "inline-block";
              verifyOtpBtn.disabled = false;
              emailOtpInput.focus();
              setStatus("OTPs sent. Enter both codes to verify.");
            },
            error => {
              sendOtpBtn.disabled = false;
              console.error("MSG91 send OTP failed:", error);
              setStatus("Could not send mobile OTP. Try again.", true);
            }
          );
        };

        verifyOtpBtn.onclick = () => {
          const emailOtp = (emailOtpInput.value || "").trim();
          const mobileOtp = (mobileOtpInput.value || "").trim();
          if (!/^[0-9]{6}$/.test(emailOtp)) {
            setStatus("Enter the 6-digit email OTP.", true);
            return;
          }
          if (!/^[0-9]{4,8}$/.test(mobileOtp)) {
            setStatus("Enter the mobile OTP.", true);
            return;
          }
          if (!leadId) {
            setStatus("Email verification session was not created. Send OTPs again.", true);
            return;
          }
          if (typeof window.verifyOtp !== "function") {
            setStatus("MSG91 OTP is still loading. Try again.", true);
            return;
          }

          verifyOtpBtn.disabled = true;
          setStatus("Verifying identity...");
          window.verifyOtp(
            mobileOtp,
            async data => {
              const accessToken = msg91AccessToken(data);
              if (!accessToken) {
                verifyOtpBtn.disabled = false;
                setStatus("Mobile verification did not return an access token.", true);
                return;
              }

              const mobileRes = await api("verify_lead_mobile_msg91", "POST", {
                customer_id: customerId,
                user_id: userId,
                phone_number: activePhone,
                msg91_access_token: accessToken,
                source_url: window.location.href,
                msg91_response: data || null,
                suppress_notification: true
              });
              if (!mobileRes.success || !mobileRes.lead) {
                verifyOtpBtn.disabled = false;
                setStatus(mobileRes.message || "Mobile verification could not be saved. Try again.", true);
                return;
              }

              const emailRes = await api("verify_lead_email_otp", "POST", {
                customer_id: customerId,
                lead_id: leadId,
                otp: emailOtp,
                notification_event: "identity"
              });
              if (!emailRes.success) {
                verifyOtpBtn.disabled = false;
                setStatus(emailRes.message || "Email OTP verification failed. Try again.", true);
                return;
              }

              cleanup();
              resolve({
                lead: mobileRes.lead,
                email: activeEmail,
                phone: mobileRes.lead.phone_number || activePhone
              });
            },
            error => {
              verifyOtpBtn.disabled = false;
              console.error("MSG91 verify OTP failed:", error);
              setStatus("Mobile OTP verification failed. Check the code and try again.", true);
            },
            requestId || undefined
          );
        };

        actions.appendChild(closeBtn);
        actions.appendChild(sendOtpBtn);
        actions.appendChild(verifyOtpBtn);
        phoneRow.appendChild(countrySelect);
        phoneRow.appendChild(phoneInput);
        dialog.appendChild(title);
        dialog.appendChild(emailInput);
        dialog.appendChild(phoneRow);
        dialog.appendChild(emailOtpInput);
        dialog.appendChild(mobileOtpInput);
        dialog.appendChild(captchaMount);
        dialog.appendChild(status);
        dialog.appendChild(actions);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        loadMsg91OtpProvider()
          .then(() => {
            window.initSendOTP({
              widgetId: msg91Cfg.widget_id,
              tokenAuth: msg91Cfg.token_auth,
              exposeMethods: true,
              captchaRenderId: captchaId,
              success: () => {},
              failure: error => {
                console.error("MSG91 identity OTP failed:", error);
              }
            });
            setStatus("Enter your email and mobile number to receive OTPs.");
            emailInput.focus();
          })
          .catch(error => {
            cleanup();
            reject(error);
          });
      });
    }

    // Lead prompt UI: creates one lead early and updates it as contact details are verified or collected.
    async function renderLeadPrompt() {
      const leadCfg = (config.lead_generation || {});
      const leadState = loadLeadState(customerId);
      const prompt = box.querySelector("[data-vani-lead-prompt]");
      if (!prompt) return;
      prompt.innerHTML = "";
      if (!isEnabled(leadCfg.is_enabled)) {
        prompt.style.display = "none";
        return;
      }
      prompt.style.display = "block";
      const collectLocation = isEnabled(leadCfg.collect_location);
      const collectEmail = isEnabled(leadCfg.collect_email);
      const collectMobile = isEnabled(leadCfg.collect_mobile);
      const verifyEmailOtp = isEnabled(leadCfg.verify_email_otp);
      const verifyMobileOtp = isEnabled(leadCfg.verify_mobile_otp);

      if (collectLocation && !leadState.locationSaved) {
        addMessage(messages, "Requesting your location...", "bot");
        if (!navigator.geolocation) {
          addMessage(messages, "Geolocation not supported in this browser.", "bot");
          return;
        }
        navigator.geolocation.getCurrentPosition(async pos => {
          const lat = pos.coords.latitude;
          const lon = pos.coords.longitude;
          const locText = `Lat:${lat.toFixed(5)} Lon:${lon.toFixed(5)}`;
          const saveRes = await api("create_lead", "POST", {
            customer_id: customerId,
            user_id: userId,
            location_text: locText,
            latitude: lat,
            longitude: lon,
            source_url: window.location.href,
            verification_quality: verifyEmailOtp || verifyMobileOtp ? 'poor' : 'poor'
          });
          console.log('create_lead response:', saveRes);
          if (saveRes && saveRes.success) {
            leadState.locationSaved = true;
            leadState.leadId = saveRes.lead?.id || leadState.leadId;
            saveLeadState(customerId, leadState);
            addMessage(messages, "Location saved.", "bot");
            prompt.style.display = "none";
            renderLeadPrompt();
          } else {
            const serverMsg = (saveRes && (saveRes.message || (saveRes.debug && saveRes.debug.raw)))
              ? (saveRes.message || (typeof saveRes.debug.raw === 'string' ? saveRes.debug.raw.slice(0,200) : JSON.stringify(saveRes.debug)))
              : 'Server error';
            console.error('create_lead failed', saveRes);
            addMessage(messages, "Could not save location: " + serverMsg, "bot");
          }
        }, err => {
          addMessage(messages, "Location access denied or unavailable.", "bot");
        }, { enableHighAccuracy: true, timeout: 10000 });
        return;
      }

      if (verifyEmailOtp && verifyMobileOtp && (!leadState.emailVerified || !leadState.mobileVerified)) {
        const verifyBtn = requiredPromptButton("Please Verify Identity");
        css(verifyBtn, { width: "100%" });
        verifyBtn.onclick = async () => {
          verifyBtn.disabled = true;
          try {
            const verified = await openIdentityOtpWidget();
            leadState.leadId = verified.lead?.id || leadState.leadId;
            leadState.email = verified.email || leadState.email;
            leadState.phone = verified.phone || leadState.phone;
            leadState.emailSaved = true;
            leadState.emailVerified = true;
            leadState.mobileSaved = true;
            leadState.mobileVerified = true;
            leadState.verified = true;
            leadState.expecting = null;
            saveLeadState(customerId, leadState);
            addMessage(messages, "Identity verified.", "bot");
            renderLeadPrompt();
          } catch (error) {
            console.error("Identity verification failed:", error);
            addMessage(messages, "Identity verification was not completed. Try again.", "bot");
          } finally {
            verifyBtn.disabled = false;
          }
        };
        prompt.appendChild(verifyBtn);
        return;
      }

      if ((collectEmail || verifyEmailOtp) && !(verifyEmailOtp ? leadState.emailVerified : leadState.emailSaved)) {
        // If awaiting OTP entry
        if (leadState.expecting === 'otp') {
          const otpInput = promptInput("text", "Enter 6-digit OTP");
          const verifyBtn = promptButton("Verify OTP");
          verifyBtn.onclick = async () => {
            const otp = otpInput.value.trim();
            if (!/^[0-9]{6}$/.test(otp)) return addMessage(messages, "Enter a 6-digit code.", "bot");
            verifyBtn.disabled = true;
            const res = await api("verify_lead_email_otp", "POST", { customer_id: customerId, lead_id: leadState.leadId, otp });
            verifyBtn.disabled = false;
            if (res.success) {
              leadState.verified = true;
              leadState.emailSaved = true;
              leadState.emailVerified = true;
              leadState.expecting = null;
              saveLeadState(customerId, leadState);
              addMessage(messages, "Email verified.", "bot");
              renderLeadPrompt();
            } else {
              addMessage(messages, res.message || "Invalid or expired OTP. Try again.", "bot");
            }
          };
          const wrap = promptWrap();
          wrap.appendChild(otpInput);
          wrap.appendChild(verifyBtn);
          prompt.appendChild(wrap);
          return;
        }

        const emailInput = promptInput("email", "Your email address");
        const sendBtn = promptButton(verifyEmailOtp ? "Send OTP" : "Save email");
        sendBtn.onclick = async () => {
          const email = (emailInput.value || "").trim();
          if (!validEmail(email)) return addMessage(messages, "Enter a valid email address.", "bot");
          sendBtn.disabled = true;
          const res = verifyEmailOtp
            ? await api("create_lead_send_email_otp", "POST", { customer_id: customerId, user_id: userId, email, source_url: window.location.href })
            : await api("create_lead", "POST", { customer_id: customerId, user_id: userId, email, source_url: window.location.href, verification_quality: "poor" });
          sendBtn.disabled = false;
          if (res.success && res.lead) {
            leadState.leadId = res.lead.id || leadState.leadId;
            leadState.email = email;
            leadState.emailSaved = true;
            leadState.emailVerified = !verifyEmailOtp;
            leadState.verified = !verifyEmailOtp;
            leadState.expecting = verifyEmailOtp ? 'otp' : null;
            saveLeadState(customerId, leadState);
            addMessage(messages, verifyEmailOtp ? "OTP sent to your email. Enter it below to verify." : "Email saved.", "bot");
            renderLeadPrompt();
          } else {
            const errorMessage = res.email_error || res.message || "Could not send OTP to that email. Try again later.";
            addMessage(messages, errorMessage, "bot");
          }
        };
        const wrap = promptWrap();
        wrap.appendChild(emailInput);
        wrap.appendChild(sendBtn);
        prompt.appendChild(wrap);
        return;
      }

      if ((collectMobile || verifyMobileOtp) && !(verifyMobileOtp ? leadState.mobileVerified : leadState.mobileSaved)) {
        if (verifyMobileOtp) {
          const verifyBtn = requiredPromptButton("Please Verify Identity");
          css(verifyBtn, { width: "100%" });
          verifyBtn.onclick = async () => {
            verifyBtn.disabled = true;
            try {
              const verified = await openMobileOtpWidget();
              const res = await api("verify_lead_mobile_msg91", "POST", {
                customer_id: customerId,
                user_id: userId,
                phone_number: verified.phone || "",
                msg91_access_token: verified.msg91_access_token || "",
                source_url: window.location.href,
                msg91_response: verified.msg91_response || null
              });
              if (res.success && res.lead) {
                leadState.leadId = res.lead.id || leadState.leadId;
                leadState.phone = res.lead.phone_number || verified.phone || "";
                leadState.mobileSaved = true;
                leadState.mobileVerified = true;
                leadState.expecting = null;
                saveLeadState(customerId, leadState);
                addMessage(messages, "Mobile number verified.", "bot");
                renderLeadPrompt();
              } else {
                addMessage(messages, res.message || "Mobile verified, but could not save it. Try again.", "bot");
              }
            } catch (error) {
              console.error("MSG91 mobile OTP failed:", error);
              addMessage(messages, "Mobile verification was not completed. Try again.", "bot");
            } finally {
              verifyBtn.disabled = false;
            }
          };
          prompt.appendChild(verifyBtn);
          return;
        }

        const phoneInput = promptInput("tel", "Your mobile number");
        phoneInput.inputMode = "tel";
        const saveBtn = promptButton("Save mobile");
        saveBtn.onclick = async () => {
          const phone = normalizePhone(phoneInput.value);
          if (!validPhone(phone)) return addMessage(messages, "Enter a valid mobile number with country code.", "bot");
          saveBtn.disabled = true;
          const res = await api("create_lead", "POST", {
            customer_id: customerId,
            user_id: userId,
            phone_number: phone,
            source_url: window.location.href,
            mobile_otp_verified: false,
            verification_quality: "poor"
          });
          saveBtn.disabled = false;
          if (res.success && res.lead) {
            leadState.leadId = res.lead.id || leadState.leadId;
            leadState.phone = phone;
            leadState.mobileSaved = true;
            leadState.mobileVerified = false;
            saveLeadState(customerId, leadState);
            addMessage(messages, "Mobile number saved.", "bot");
            renderLeadPrompt();
          } else {
            addMessage(messages, res.message || "Could not save mobile number. Try again.", "bot");
          }
        };
        const wrap = promptWrap();
        wrap.appendChild(phoneInput);
        wrap.appendChild(saveBtn);
        prompt.appendChild(wrap);
        return;
      }

      // Nothing required
      prompt.style.display = "none";
    }

    window.sendMessage = async function sendMessage() {
      const message = input.value.trim();
      if (!message) return;

      const leadCfg = (config.lead_generation || {});
      const leadState = loadLeadState(customerId);

      // If lead generation requires action, show visual prompt and block chat
      if (!leadFlowComplete(leadCfg, leadState)) {
        renderLeadPrompt();
        addMessage(messages, "Please complete the verification using the controls above the input.", "bot");
        return;
      }

      // proceed with normal chat
      addMessage(messages, message, "user");
      input.value = "";
      suggestionsBox.innerHTML = "";
      sessionChatStartedAt = sessionChatStartedAt || new Date().toISOString();
      sessionMessageCount++;
      const requestStartedAt = Date.now();

      const response = await api("chat", "POST", {
        customer_id: customerId,
        message,
        user_id: userId,
        session_id: sessionId,
        source_url: window.location.href,
        started_at: sessionChatStartedAt,
        duration_seconds: sessionDurationSeconds(),
        message_count: sessionMessageCount,
        analytics: analyticsPayload({
          response_time_ms: Date.now() - requestStartedAt
        })
      });

      addMessage(messages, response.reply || "No response", "bot");
      trackWidgetSessionSoon({started_at: sessionChatStartedAt});
    };

    icon.onclick = () => {
      const open = box.style.display === "flex";
      box.style.display = open ? "none" : "flex";
      greeting.style.display = open ? "block" : "none";
      if (!open) {
        sessionOpenedAt = sessionOpenedAt || new Date().toISOString();
        trackWidgetSessionSoon({opened_at: sessionOpenedAt});
        input.focus();
        loadTop();
        renderWhatsAppAction();
        renderLeadPrompt();
      } else {
        trackWidgetSessionSoon();
      }
    };
    greeting.onclick = icon.onclick;

    input.addEventListener("focus", loadTop);
    input.addEventListener("input", () => {
      clearTimeout(debounce);
      debounce = setTimeout(() => searchFaqs(input.value.trim()), 180);
    });
    input.addEventListener("keydown", event => {
      if (event.key === "Enter") {
        event.preventDefault();
        window.sendMessage();
      }
    });
    sendBtn.onclick = window.sendMessage;

    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "hidden") {
        trackWidgetSessionSoon();
      }
    });
    window.addEventListener("beforeunload", () => {
      trackWidgetSessionSoon({ended_at: new Date().toISOString()});
    });
  }

  boot();
})();
