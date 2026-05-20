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
  let config = {};

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
      return await response.json();
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
    const cfg = await api("get_widget_config", "GET", null, `&customer_id=${encodeURIComponent(customerId)}`);
    config = cfg || {};

    if (config.is_active === false) {
      return;
    }

    const color = config.theme_color || "#6366f1";
    const position = config.position === "left" ? "left" : "right";
    const sideStyles = position === "left" ? {left: "20px"} : {right: "20px"};
    const greetingSideStyles = position === "left" ? {left: "90px"} : {right: "90px"};
    const avatarUrl = resolveAssetUrl(config.avatar_url);
    const greetingText = (config.welcome_message || defaultGreeting).trim() || defaultGreeting;

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
      if (!leadCfg.is_enabled) return true;
      const needsLocation = !!leadCfg.collect_location;
      const needsEmail = !!(leadCfg.collect_email || leadCfg.verify_email_otp);
      const needsMobile = !!(leadCfg.collect_mobile || leadCfg.verify_mobile_otp);
      return (!needsLocation || leadState.locationSaved) &&
        (!needsEmail || (leadCfg.verify_email_otp ? leadState.emailVerified : leadState.emailSaved)) &&
        (!needsMobile || leadState.mobileSaved);
    }

    function validEmail(value) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    }

    function validPhone(value) {
      return /^\+?[1-9][0-9]{7,14}$/.test(value);
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

    // Lead prompt UI: creates one lead early and updates it as contact details are verified or collected.
    async function renderLeadPrompt() {
      const leadCfg = (config.lead_generation || {});
      const leadState = loadLeadState(customerId);
      const prompt = box.querySelector("[data-vani-lead-prompt]");
      if (!prompt) return;
      prompt.innerHTML = "";
      if (!leadCfg.is_enabled) {
        prompt.style.display = "none";
        return;
      }
      prompt.style.display = "block";

      if (leadCfg.collect_location && !leadState.locationSaved) {
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
            verification_quality: leadCfg.verify_email_otp || leadCfg.verify_mobile_otp ? 'poor' : 'poor'
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

      if ((leadCfg.collect_email || leadCfg.verify_email_otp) && !(leadCfg.verify_email_otp ? leadState.emailVerified : leadState.emailSaved)) {
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
        const sendBtn = promptButton(leadCfg.verify_email_otp ? "Send OTP" : "Save email");
        sendBtn.onclick = async () => {
          const email = (emailInput.value || "").trim();
          if (!validEmail(email)) return addMessage(messages, "Enter a valid email address.", "bot");
          sendBtn.disabled = true;
          const res = leadCfg.verify_email_otp
            ? await api("create_lead_send_email_otp", "POST", { customer_id: customerId, user_id: userId, email, source_url: window.location.href })
            : await api("create_lead", "POST", { customer_id: customerId, user_id: userId, email, source_url: window.location.href, verification_quality: "poor" });
          sendBtn.disabled = false;
          if (res.success && res.lead) {
            leadState.leadId = res.lead.id || leadState.leadId;
            leadState.email = email;
            leadState.emailSaved = true;
            leadState.emailVerified = !leadCfg.verify_email_otp;
            leadState.verified = !leadCfg.verify_email_otp;
            leadState.expecting = leadCfg.verify_email_otp ? 'otp' : null;
            saveLeadState(customerId, leadState);
            addMessage(messages, leadCfg.verify_email_otp ? "OTP sent to your email. Enter it below to verify." : "Email saved.", "bot");
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

      if ((leadCfg.collect_mobile || leadCfg.verify_mobile_otp) && !leadState.mobileSaved) {
        const phoneInput = promptInput("tel", "Your mobile number");
        phoneInput.inputMode = "tel";
        const saveBtn = promptButton("Save mobile");
        saveBtn.onclick = async () => {
          const phone = (phoneInput.value || "").trim().replace(/[^\d+]/g, "").replace(/(?!^)\+/g, "");
          if (!validPhone(phone)) return addMessage(messages, "Enter a valid mobile number with country code.", "bot");
          saveBtn.disabled = true;
          const res = await api("create_lead", "POST", {
            customer_id: customerId,
            user_id: userId,
            phone_number: phone,
            source_url: window.location.href,
            mobile_otp_verified: false,
            verification_quality: leadCfg.verify_mobile_otp ? "poor" : "poor",
            metadata: leadCfg.verify_mobile_otp ? { mobile_otp_status: "firebase_pending" } : {}
          });
          saveBtn.disabled = false;
          if (res.success && res.lead) {
            leadState.leadId = res.lead.id || leadState.leadId;
            leadState.phone = phone;
            leadState.mobileSaved = true;
            leadState.mobileVerified = false;
            saveLeadState(customerId, leadState);
            addMessage(messages, leadCfg.verify_mobile_otp ? "Mobile number saved. OTP verification will be connected soon." : "Mobile number saved.", "bot");
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

      const response = await api("chat", "POST", {
        customer_id: customerId,
        message,
        user_id: userId,
        source_url: window.location.href
      });

      addMessage(messages, response.reply || "No response", "bot");
    };

    icon.onclick = () => {
      const open = box.style.display === "flex";
      box.style.display = open ? "none" : "flex";
      greeting.style.display = open ? "block" : "none";
      if (!open) {
        input.focus();
        loadTop();
        renderLeadPrompt();
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
  }

  boot();
})();
