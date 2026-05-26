(function () {
  const script = document.currentScript || document.querySelector("script[src*='widget']");
  const customerId = script?.getAttribute("data-id") ||
    script?.getAttribute("data-key") ||
    script?.getAttribute("data-customer-id");
  const sourceUrl = script?.getAttribute("data-source-url") || window.location.href;
  const queryParams = new URLSearchParams(window.location.search || "");
  const forceOpenHint = script?.getAttribute("data-force-open") === "1" ||
    queryParams.get("open") === "1";
  const openByDefaultHint = script?.getAttribute("data-open-default") === "1" ||
    queryParams.get("open_hint") === "1" ||
    forceOpenHint;
  let sourcePath = window.location.pathname || window.location.href;
  try {
    sourcePath = new URL(sourceUrl).pathname || sourceUrl;
  } catch (error) {}

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
  let activeFaqActions = [];
  let activeFaqActionContext = {};
  let selectedFaqCategory = "";
  let chatOpenAnimationTimer = null;
  let suppressNextInputFocusLoad = false;

  function notifyFrameState(open = false, forceClosed = false) {
    if (window.parent === window) return;
    window.parent.postMessage({
      type: "vani:frame-state",
      customer_id: customerId,
      open,
      default_open: forceClosed ? false : isEnabled(config.chat_open_by_default),
      position: config.position === "left" ? "left" : "right"
    }, "*");
  }

  if (openByDefaultHint) {
    notifyFrameState(true);
  }

  let userId = localStorage.getItem("vani_widget_user_id");
  if (!userId) {
    userId = window.crypto?.randomUUID
      ? window.crypto.randomUUID()
      : "user-" + Date.now() + "-" + Math.random().toString(16).slice(2);
    localStorage.setItem("vani_widget_user_id", userId);
  }

  function selectedFaqCategoryStorageKey() {
    return `vani_selected_faq_category_${customerId}_${userId}`;
  }

  function loadSelectedFaqCategory() {
    try {
      return localStorage.getItem(selectedFaqCategoryStorageKey()) || "";
    } catch (error) {
      return "";
    }
  }

  function saveSelectedFaqCategory(category) {
    selectedFaqCategory = String(category || "").trim();
    try {
      if (selectedFaqCategory) {
        localStorage.setItem(selectedFaqCategoryStorageKey(), selectedFaqCategory);
      } else {
        localStorage.removeItem(selectedFaqCategoryStorageKey());
      }
    } catch (error) {}
  }

  function userInputEnabled() {
    return !(config.user_input_enabled === false || config.user_input_enabled === 0 || config.user_input_enabled === "0" || config.user_input_enabled === "false");
  }

  function fullFaqListQueryFlag() {
    return userInputEnabled() ? "" : "&all=1";
  }

  function compactSuggestionView() {
    return window.matchMedia("(max-width: 768px)").matches;
  }

  async function api(action, method = "GET", body = null, query = "") {
    try {
      const response = await fetch(`${apiBase}?action=${action}${query}`, {
        method,
        headers: {"Content-Type": "application/json"},
        body: body ? JSON.stringify(body) : null
      });
      const rawPayload = await response.text();
      let payload = {};
      try {
        payload = rawPayload ? JSON.parse(rawPayload) : {};
      } catch (parseError) {
        payload = {
          success: false,
          message: response.ok ? "Unexpected API response." : "Payment service returned an unexpected error."
        };
      }
      if (!response.ok) {
        console.warn("Vani widget API returned an error:", action, payload);
      }
      if (payload?.success && payload?.lead && [
        "create_lead",
        "create_lead_send_email_otp",
        "verify_lead_email_otp",
        "verify_lead_mobile_msg91"
      ].includes(action)) {
        emitLiveAction("leadCaptured", {
          action,
          lead: safeLeadPayload(payload.lead)
        });
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

  function themeBackground() {
    return config.theme_color || "#6366f1";
  }

  function themeAccent() {
    const bg = themeBackground();
    const match = String(bg).match(/#[0-9a-f]{6}/i);
    return match ? match[0] : "#6366f1";
  }

  function patternBackground(pattern) {
    if (!pattern || pattern === "none") return "";
    if (pattern === "dots") return "radial-gradient(rgba(99,102,241,.18) 1px, transparent 1px)";
    if (pattern === "grid") return "linear-gradient(rgba(99,102,241,.10) 1px, transparent 1px), linear-gradient(90deg, rgba(99,102,241,.10) 1px, transparent 1px)";
    if (pattern === "diagonal") return "repeating-linear-gradient(45deg, rgba(99,102,241,.10) 0 2px, transparent 2px 10px)";
    if (pattern === "waves") return "radial-gradient(ellipse at top, rgba(6,182,212,.16), transparent 45%), radial-gradient(ellipse at bottom, rgba(99,102,241,.16), transparent 48%)";
    const num = Number(String(pattern).replace("pattern-", "")) || 1;
    const angle = (num * 17) % 180;
    const hue = (num * 37) % 360;
    return `repeating-linear-gradient(${angle}deg, hsla(${hue},70%,55%,.10) 0 2px, transparent 2px ${8 + (num % 9)}px), radial-gradient(circle at ${20 + (num % 60)}% ${20 + ((num * 3) % 60)}%, hsla(${(hue + 80) % 360},70%,55%,.12), transparent 28%)`;
  }

  function liveActionsEnabled() {
    return isEnabled(config.live_chat_actions_enabled) && isEnabled(config.billing?.live_chat_actions);
  }

  function emitLiveAction(name, detail = {}) {
    if (!liveActionsEnabled()) return;
    const payload = {
      customer_id: customerId,
      user_id: userId,
      session_id: sessionId,
      source_url: sourceUrl,
      timestamp: new Date().toISOString(),
      ...detail
    };
    window.dispatchEvent(new CustomEvent(`vani:${name}`, {detail: payload}));
    window.dispatchEvent(new CustomEvent("vani:liveAction", {
      detail: {event: `vani:${name}`, ...payload}
    }));
  }

  function safeLeadPayload(lead = {}) {
    return {
      id: lead.id || null,
      email: lead.email || "",
      phone_number: lead.phone_number || "",
      email_otp_verified: isEnabled(lead.email_otp_verified),
      mobile_otp_verified: isEnabled(lead.mobile_otp_verified),
      source_url: lead.source_url || "",
      created_at: lead.created_at || ""
    };
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
  const scheduledActionStateKey = `vani_scheduled_action_state_${customerId}_${sessionId}`;

  function loadScheduledActionState() {
    try {
      return Object.assign({step: 0, count: 0}, JSON.parse(sessionStorage.getItem(scheduledActionStateKey) || "{}"));
    } catch (e) {
      return {step: 0, count: 0};
    }
  }

  function saveScheduledActionState(state) {
    try { sessionStorage.setItem(scheduledActionStateKey, JSON.stringify(state)); } catch (e) {}
  }

  function nextScheduledFaqAction() {
    const actions = Array.isArray(config.scheduled_faq_actions)
      ? config.scheduled_faq_actions.filter(action => action && action.label && action.action_value && Number(action.trigger_after_questions || 0) > 0)
      : [];
    if (!actions.length) return null;
    const state = loadScheduledActionState();
    const step = Math.max(0, Math.min(actions.length - 1, Number(state.step || 0)));
    const action = actions[step] || actions[0];
    const count = Number(state.count || 0) + 1;
    const triggerAfter = Math.max(1, Number(action.trigger_after_questions || 1));
    if (count >= triggerAfter) {
      saveScheduledActionState({step: (step + 1) % actions.length, count: 0});
      return Object.assign({}, action, {scheduled: true});
    }
    saveScheduledActionState({step, count});
    return null;
  }

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
      source_url: sourceUrl,
      current_page: sourcePath,
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
      source_url: sourceUrl,
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
    const isUser = type === "user";
    const row = document.createElement("div");
    row.className = `vani-message-row ${isUser ? "vani-message-user" : "vani-message-bot"}`;
    const avatar = document.createElement("span");
    const botAvatarUrl = resolveAssetUrl(config.avatar_url);
    if (isUser || !botAvatarUrl) {
      avatar.textContent = isUser ? "You" : "AI";
    } else {
      const avatarImage = document.createElement("img");
      avatarImage.src = botAvatarUrl;
      avatarImage.alt = "";
      css(avatarImage, {
        width: "100%",
        height: "100%",
        objectFit: "contain",
        display: "block"
      });
      avatarImage.onerror = () => {
        avatarImage.remove();
        avatar.textContent = "AI";
      };
      avatar.appendChild(avatarImage);
    }
    const bubble = document.createElement("div");
    bubble.textContent = text;
    bubble.className = "vani-message-bubble";
    css(row, {
      display: "flex",
      alignItems: "flex-end",
      gap: "8px",
      margin: "9px 0",
      justifyContent: isUser ? "flex-end" : "flex-start",
      animation: "vaniMessageIn .24s ease both"
    });
    css(avatar, {
      width: "26px",
      height: "26px",
      borderRadius: "999px",
      display: isUser ? "none" : "inline-flex",
      alignItems: "center",
      justifyContent: "center",
      flex: "0 0 auto",
      overflow: "hidden",
      padding: botAvatarUrl && !isUser ? "3px" : "0",
      fontSize: "10px",
      fontWeight: "800",
      color: "#fff",
      background: botAvatarUrl && !isUser ? "#fff" : themeBackground(),
      border: botAvatarUrl && !isUser ? "1px solid #e2e8f0" : "0",
      boxShadow: "0 8px 18px rgba(15,23,42,.16)"
    });
    css(bubble, {
      padding: "10px 12px",
      borderRadius: isUser ? "16px 16px 5px 16px" : "16px 16px 16px 5px",
      maxWidth: "82%",
      fontSize: "14px",
      lineHeight: "1.5",
      whiteSpace: "pre-wrap",
      wordBreak: "break-word",
      background: isUser ? themeBackground() : "rgba(255,255,255,.96)",
      color: isUser ? "#fff" : "#0f172a",
      border: isUser ? "0" : "1px solid #e2e8f0",
      boxShadow: isUser ? "0 10px 24px rgba(79,70,229,.22)" : "0 10px 26px rgba(15,23,42,.07)"
    });
    if (!isUser) row.appendChild(avatar);
    row.appendChild(bubble);
    messages.appendChild(row);
    messages.scrollTop = messages.scrollHeight;
  }

  function chatMessagesContainer() {
    return document.querySelector("[data-vani-messages]");
  }

  function addThinkingMessage(messages) {
    const botName = (config.bot_name || "Chatbot").trim() || "Chatbot";
    const row = document.createElement("div");
    row.className = "vani-message-row vani-message-bot vani-thinking-row";
    const avatar = document.createElement("span");
    const botAvatarUrl = resolveAssetUrl(config.avatar_url);
    if (botAvatarUrl) {
      const avatarImage = document.createElement("img");
      avatarImage.src = botAvatarUrl;
      avatarImage.alt = "";
      css(avatarImage, {
        width: "100%",
        height: "100%",
        objectFit: "contain",
        display: "block"
      });
      avatarImage.onerror = () => {
        avatarImage.remove();
        avatar.textContent = "AI";
      };
      avatar.appendChild(avatarImage);
    } else {
      avatar.textContent = "AI";
    }
    const bubble = document.createElement("div");
    const label = document.createElement("span");
    label.textContent = `${botName} is thinking`;
    const dots = document.createElement("span");
    dots.className = "vani-thinking-dots";
    dots.appendChild(document.createElement("i"));
    dots.appendChild(document.createElement("i"));
    dots.appendChild(document.createElement("i"));
    css(row, {
      display: "flex",
      alignItems: "flex-end",
      gap: "8px",
      margin: "9px 0",
      justifyContent: "flex-start",
      animation: "vaniMessageIn .24s ease both"
    });
    css(avatar, {
      width: "26px",
      height: "26px",
      borderRadius: "999px",
      display: "inline-flex",
      alignItems: "center",
      justifyContent: "center",
      flex: "0 0 auto",
      overflow: "hidden",
      padding: botAvatarUrl ? "3px" : "0",
      fontSize: "10px",
      fontWeight: "800",
      color: "#fff",
      background: botAvatarUrl ? "#fff" : themeBackground(),
      border: botAvatarUrl ? "1px solid #e2e8f0" : "0",
      boxShadow: "0 8px 18px rgba(15,23,42,.16)"
    });
    css(bubble, {
      padding: "10px 12px",
      borderRadius: "16px 16px 16px 5px",
      maxWidth: "82%",
      fontSize: "13px",
      lineHeight: "1.5",
      color: "#64748b",
      background: "rgba(255,255,255,.96)",
      border: "1px solid #e2e8f0",
      boxShadow: "0 10px 26px rgba(15,23,42,.07)",
      display: "inline-flex",
      alignItems: "center",
      gap: "7px"
    });
    bubble.appendChild(label);
    bubble.appendChild(dots);
    row.appendChild(avatar);
    row.appendChild(bubble);
    messages.appendChild(row);
    messages.scrollTop = messages.scrollHeight;
    return row;
  }

  function renderCategorySwitcher(suggestionsBox, currentCategory, onChangeCategory) {
    if (!suggestionsBox || !currentCategory || typeof onChangeCategory !== "function") return;
    const button = document.createElement("button");
    button.type = "button";
    button.className = "vani-suggestion-card";
    css(button, {
      width: "100%",
      border: "1px solid rgba(99,102,241,.22)",
      borderRadius: "13px",
      background: "rgba(255,255,255,.96)",
      color: "#0f172a",
      padding: "9px 10px",
      textAlign: "left",
      cursor: "pointer",
      display: "flex",
      alignItems: "center",
      justifyContent: "space-between",
      gap: "10px",
      boxShadow: "0 8px 22px rgba(15,23,42,.06)",
      transition: "transform .16s ease, box-shadow .16s ease, border-color .16s ease, background .16s ease"
    });
    const label = document.createElement("span");
    label.textContent = currentCategory;
    css(label, {
      minWidth: "0",
      overflow: "hidden",
      textOverflow: "ellipsis",
      whiteSpace: "nowrap",
      fontSize: "12px",
      fontWeight: "800"
    });
    const action = document.createElement("span");
    action.textContent = "Change category";
    css(action, {
      flex: "0 0 auto",
      color: themeAccent(),
      fontSize: "12px",
      fontWeight: "800"
    });
    button.appendChild(label);
    button.appendChild(action);
    button.onmouseenter = () => {
      button.style.background = "#fff";
      button.style.borderColor = themeAccent();
      button.style.boxShadow = "0 12px 28px rgba(15,23,42,.12)";
      button.style.transform = "translateY(-1px)";
    };
    button.onmouseleave = () => {
      button.style.background = "rgba(255,255,255,.96)";
      button.style.borderColor = "rgba(99,102,241,.22)";
      button.style.boxShadow = "0 8px 22px rgba(15,23,42,.06)";
      button.style.transform = "translateY(0)";
    };
    button.onclick = onChangeCategory;
    suggestionsBox.appendChild(button);
  }

  function renderSuggestions(suggestionsBox, input, items, options = {}) {
    suggestionsBox.innerHTML = "";
    const includeFaqs = options.includeFaqs !== false;
    const hasActions = activeFaqActions.length > 0;
    const compactView = compactSuggestionView();
    const visibleItems = includeFaqs
      ? (compactView && userInputEnabled() ? items.slice(0, 3) : items)
      : [];
    const showCategorySwitcher = !!(options.showCategorySwitcher && options.currentCategory && options.onChangeCategory);
    suggestionsBox.style.display = hasActions || visibleItems.length || showCategorySwitcher ? "grid" : "none";
    suggestionsBox.style.maxHeight = compactView ? "150px" : "210px";

    if (showCategorySwitcher) {
      renderCategorySwitcher(suggestionsBox, options.currentCategory, options.onChangeCategory);
    }

    if (hasActions) {
      const actionGroup = document.createElement("div");
      actionGroup.className = "vani-action-panel";
      css(actionGroup, {
        display: "grid",
        gap: "8px",
        padding: "10px",
        borderRadius: "16px",
        background: "linear-gradient(180deg,rgba(255,255,255,.86),rgba(248,250,252,.72))",
        border: "1px solid rgba(199,210,254,.75)",
        boxShadow: "0 14px 34px rgba(15,23,42,.10)",
        marginBottom: visibleItems.length ? "7px" : "0"
      });
      const heading = document.createElement("div");
      heading.textContent = "Recommended next step";
      css(heading, {
        color: "#64748b",
        fontSize: "11px",
        fontWeight: "800",
        letterSpacing: ".03em",
        textTransform: "uppercase"
      });
      actionGroup.appendChild(heading);
      activeFaqActions.forEach((action, index) => {
        const button = document.createElement("button");
        button.type = "button";
        button.textContent = action.label || "Continue";
        button.className = "vani-suggestion-card";
        button.style.animationDelay = `${Math.min(index * 35, 180)}ms`;
        css(button, {
          width: "100%",
          minHeight: "38px",
          border: "1px solid rgba(99,102,241,.22)",
          borderRadius: "14px",
          background: themeBackground(),
          color: "#fff",
          cursor: "pointer",
          fontWeight: "800",
          fontSize: "13px",
          padding: "10px 12px",
          textAlign: "center",
          boxShadow: "0 10px 26px rgba(79,70,229,.14)",
          animation: index === 0 ? "vaniSuggestionIn .22s ease both, vaniActionPulse 2.2s ease-in-out infinite" : "vaniSuggestionIn .22s ease both",
          transition: "transform .16s ease, box-shadow .16s ease, filter .16s ease"
        });
        button.onmouseenter = () => {
          button.style.transform = "translateY(-1px)";
          button.style.filter = "brightness(1.04)";
          button.style.boxShadow = "0 16px 34px rgba(79,70,229,.22)";
        };
        button.onmouseleave = () => {
          button.style.transform = "translateY(0)";
          button.style.filter = "brightness(1)";
          button.style.boxShadow = "0 10px 26px rgba(79,70,229,.14)";
        };
        button.onclick = () => handleFaqAction(action, activeFaqActionContext, suggestionsBox, input);
        actionGroup.appendChild(button);
      });
      suggestionsBox.appendChild(actionGroup);
    }

    visibleItems.forEach((item, index) => {
      const option = document.createElement("button");
      option.type = "button";
      option.className = "vani-suggestion-card";
      option.style.animationDelay = `${Math.min(index * 35, 180)}ms`;
      const icon = document.createElement("span");
      icon.textContent = "?";
      css(icon, {
        display: "inline-flex",
        width: compactView ? "20px" : "22px",
        height: compactView ? "20px" : "22px",
        borderRadius: "999px",
        background: themeBackground(),
        color: "#fff",
        alignItems: "center",
        justifyContent: "center",
        fontSize: compactView ? "11px" : "12px",
        fontWeight: "800",
        flex: "0 0 auto"
      });
      const content = document.createElement("span");
      css(content, { flex: "1", minWidth: "0" });
      const title = document.createElement("span");
      title.textContent = item.question || "";
      css(title, {
        display: "block",
        color: "#0f172a",
        fontWeight: "700",
        fontSize: compactView ? "12px" : "13px",
        lineHeight: compactView ? "1.25" : "1.35",
        overflow: compactView ? "hidden" : "visible",
        textOverflow: compactView ? "ellipsis" : "clip",
        whiteSpace: compactView ? "nowrap" : "normal"
      });
      const hint = document.createElement("span");
      hint.textContent = "Suggested answer";
      css(hint, {
        display: compactView ? "none" : "block",
        color: "#64748b",
        fontSize: "11px",
        lineHeight: "1.35",
        marginTop: "2px"
      });
      content.appendChild(title);
      content.appendChild(hint);
      option.appendChild(icon);
      option.appendChild(content);
      css(option, {
        width: "100%",
        border: "1px solid #e2e8f0",
        borderRadius: compactView ? "11px" : "13px",
        background: "rgba(255,255,255,.92)",
        color: "#0f172a",
        padding: compactView ? "7px 9px" : "9px 10px",
        textAlign: "left",
        cursor: "pointer",
        fontSize: "13px",
        display: "flex",
        alignItems: "center",
        gap: compactView ? "8px" : "9px",
        boxShadow: "0 8px 22px rgba(15,23,42,.06)",
        transition: "transform .16s ease, box-shadow .16s ease, border-color .16s ease, background .16s ease"
      });
      option.onmouseenter = () => {
        option.style.background = "#fff";
        option.style.borderColor = themeAccent();
        option.style.boxShadow = "0 12px 28px rgba(15,23,42,.12)";
        option.style.transform = "translateY(-1px)";
      };
      option.onmouseleave = () => {
        option.style.background = "rgba(255,255,255,.92)";
        option.style.borderColor = "#e2e8f0";
        option.style.boxShadow = "0 8px 22px rgba(15,23,42,.06)";
        option.style.transform = "translateY(0)";
      };
      option.onclick = async () => {
        input.value = item.question;
        if (item.default_faq_key) {
          delete input.dataset.selectedFaqId;
          input.dataset.selectedDefaultFaqKey = item.default_faq_key;
        } else {
          await trackUsage(item.id);
          input.dataset.selectedFaqId = item.id || "";
          delete input.dataset.selectedDefaultFaqKey;
        }
        window.sendMessage();
      };
      suggestionsBox.appendChild(option);
    });
  }

  function renderCategoryMenu(suggestionsBox, input, categories, options = {}) {
    suggestionsBox.innerHTML = "";
    saveSelectedFaqCategory("");
    const visibleCategories = Array.isArray(categories) ? categories.filter(item => item && item.category) : [];
    suggestionsBox.style.display = visibleCategories.length ? "grid" : "none";
    if (!visibleCategories.length) return;

    const panel = document.createElement("div");
    panel.className = "vani-action-panel";
    css(panel, {
      display: "grid",
      gap: "8px",
      padding: "10px",
      borderRadius: "16px",
      background: "linear-gradient(180deg,rgba(255,255,255,.86),rgba(248,250,252,.72))",
      border: "1px solid rgba(199,210,254,.75)",
      boxShadow: "0 14px 34px rgba(15,23,42,.10)"
    });
    const heading = document.createElement("div");
    heading.textContent = "Browse FAQs by category";
    css(heading, {
      color: "#64748b",
      fontSize: "11px",
      fontWeight: "800",
      letterSpacing: ".03em",
      textTransform: "uppercase"
    });
    panel.appendChild(heading);

    visibleCategories.forEach((item, index) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "vani-suggestion-card";
      button.style.animationDelay = `${Math.min(index * 35, 180)}ms`;
      const label = document.createElement("span");
      label.textContent = item.category;
      const count = document.createElement("small");
      count.textContent = `${Number(item.count || 0)} FAQs`;
      button.appendChild(label);
      button.appendChild(count);
      css(button, {
        width: "100%",
        border: "1px solid #e2e8f0",
        borderRadius: "13px",
        background: "rgba(255,255,255,.92)",
        color: "#0f172a",
        padding: "10px 11px",
        textAlign: "left",
        cursor: "pointer",
        display: "grid",
        gap: "2px",
        boxShadow: "0 8px 22px rgba(15,23,42,.06)",
        transition: "transform .16s ease, box-shadow .16s ease, border-color .16s ease, background .16s ease"
      });
      if (label) css(label, {fontWeight: "800", fontSize: "13px", lineHeight: "1.35"});
      if (count) css(count, {color: "#64748b", fontSize: "11px", lineHeight: "1.35"});
      button.onmouseenter = () => {
        button.style.background = "#fff";
        button.style.borderColor = themeAccent();
        button.style.boxShadow = "0 12px 28px rgba(15,23,42,.12)";
        button.style.transform = "translateY(-1px)";
      };
      button.onmouseleave = () => {
        button.style.background = "rgba(255,255,255,.92)";
        button.style.borderColor = "#e2e8f0";
        button.style.boxShadow = "0 8px 22px rgba(15,23,42,.06)";
        button.style.transform = "translateY(0)";
      };
      button.onclick = () => showFaqCategory(item.category, suggestionsBox, input, options);
      panel.appendChild(button);
    });

    suggestionsBox.appendChild(panel);
  }

  async function showPublicCategoryMenu(suggestionsBox, input) {
    const response = await api("get_faq_categories", "GET", null, `&customer_id=${encodeURIComponent(customerId)}`);
    renderCategoryMenu(suggestionsBox, input, response.data || [], {
      onChangeCategory: () => showPublicCategoryMenu(suggestionsBox, input)
    });
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

  function cleanPhone(value) {
    return String(value || "").replace(/[^\d+]/g, "").replace(/(?!^)\+/g, "").replace(/\D+/g, "");
  }

  function openHttps(value) {
    if (/^https:\/\//i.test(value)) window.open(value, "_blank", "noopener,noreferrer");
  }

  function loadRazorpayCheckout() {
    if (window.Razorpay) return Promise.resolve(true);
    return new Promise(resolve => {
      const existing = document.querySelector("script[data-vani-razorpay]");
      if (existing) {
        existing.addEventListener("load", () => resolve(true), {once: true});
        existing.addEventListener("error", () => resolve(false), {once: true});
        return;
      }
      const script = document.createElement("script");
      script.src = "https://checkout.razorpay.com/v1/checkout.js";
      script.async = true;
      script.dataset.vaniRazorpay = "true";
      script.onload = () => resolve(true);
      script.onerror = () => resolve(false);
      document.head.appendChild(script);
    });
  }

  function openRazorpayOnParent(options) {
    if (window.parent === window) return Promise.resolve(null);
    return new Promise(resolve => {
      const requestId = "rzp_" + Date.now() + "_" + Math.random().toString(16).slice(2);
      const timeout = window.setTimeout(() => {
        window.removeEventListener("message", handleResult);
        resolve({success: false, message: "Razorpay response was not received yet. If money was deducted, the business can verify it from the dashboard."});
      }, 300000);
      function handleResult(event) {
        const data = event.data || {};
        if (event.source !== window.parent || data.type !== "vani:razorpay-result" || data.customer_id !== customerId || data.request_id !== requestId) return;
        window.clearTimeout(timeout);
        window.removeEventListener("message", handleResult);
        resolve(data);
      }
      window.addEventListener("message", handleResult);
      window.parent.postMessage({
        type: "vani:open-razorpay",
        customer_id: customerId,
        request_id: requestId,
        options
      }, "*");
    });
  }

  function showInlineActionNotice(suggestionsBox, text, tone = "success") {
    if (!suggestionsBox) return;
    suggestionsBox.innerHTML = "";
    suggestionsBox.style.display = "grid";
    const notice = document.createElement("div");
    notice.textContent = text;
    css(notice, {
      padding: "11px 12px",
      borderRadius: "14px",
      background: tone === "success" ? "rgba(34,197,94,.12)" : "rgba(239,68,68,.1)",
      border: tone === "success" ? "1px solid rgba(34,197,94,.22)" : "1px solid rgba(239,68,68,.2)",
      color: tone === "success" ? "#166534" : "#991b1b",
      fontWeight: "700",
      fontSize: "12px",
      lineHeight: "1.45"
    });
    suggestionsBox.appendChild(notice);
  }

  function normalizedFeedbackActionType(actionType) {
    if (["download", "booking", "track_order"].includes(actionType)) return "link";
    return actionType;
  }

  function faqFeedbackEnabledFor(action) {
    if (!isEnabled(config.faq_feedback_enabled)) return false;
    const actionIds = Array.isArray(config.faq_feedback_action_ids) ? config.faq_feedback_action_ids.map(String) : [];
    return actionIds.includes(String(action?.id || ""));
  }

  function clearActiveFaqActionState() {
    activeFaqActions = [];
    activeFaqActionContext = {};
  }

  function renderFaqActionFeedback(action, context = {}, suggestionsBox = null) {
    if (!suggestionsBox || !faqFeedbackEnabledFor(action)) return;
    suggestionsBox.style.display = "grid";
    const panel = document.createElement("div");
    panel.className = "vani-action-panel";
    css(panel, {
      display: "grid",
      gap: "8px",
      padding: "10px",
      borderRadius: "16px",
      background: "rgba(255,255,255,.96)",
      border: "1px solid rgba(199,210,254,.75)",
      boxShadow: "0 14px 34px rgba(15,23,42,.10)",
      marginTop: "7px"
    });
    const title = document.createElement("strong");
    title.textContent = "Was this action helpful?";
    css(title, {color: "#0f172a", fontSize: "13px"});
    const submitFeedback = async (value, container) => {
      if (container) {
        container.querySelectorAll("button,input,textarea").forEach(item => item.disabled = true);
      }
      await api("submit_faq_action_feedback", "POST", {
        customer_id: customerId,
        user_id: userId,
        session_id: sessionId,
        source_url: sourceUrl,
        faq_id: action.faq_id || context.matched_faq_id || null,
        action_id: action.id || null,
        action_type: normalizedFeedbackActionType(action.action_type || "link"),
        feedback_value: value
      });
      if ((action.action_type || "") === "payment") {
        clearActiveFaqActionState();
        if (suggestionsBox) {
          suggestionsBox.innerHTML = "";
          suggestionsBox.style.display = "none";
        }
        const messageBox = chatMessagesContainer();
        if (messageBox) addMessage(messageBox, "How can I help you further?", "bot");
        return;
      }
      title.textContent = "Thanks for the feedback.";
      if (container) container.remove();
    };
    const feedbackType = config.faq_feedback_type || "labels";
    const grid = document.createElement("div");
    const addChoiceButton = (value, label = value) => {
      const button = document.createElement("button");
      button.type = "button";
      button.textContent = label;
      css(button, {
        border: "1px solid #e2e8f0",
        borderRadius: "10px",
        background: "#fff",
        color: "#0f172a",
        cursor: "pointer",
        fontSize: "11px",
        fontWeight: "800",
        padding: "8px 6px"
      });
      button.onclick = () => submitFeedback(value, grid);
      grid.appendChild(button);
    };
    if (feedbackType === "stars") {
      css(grid, {display: "grid", gridTemplateColumns: "repeat(5, minmax(0, 1fr))", gap: "6px"});
      ["1 star", "2 stars", "3 stars", "4 stars", "5 stars"].forEach((value, index) => addChoiceButton(value, "★".repeat(index + 1)));
    } else if (feedbackType === "emoji") {
      css(grid, {display: "grid", gridTemplateColumns: "repeat(5, minmax(0, 1fr))", gap: "6px"});
      [["Very happy", "😄"], ["Happy", "🙂"], ["Neutral", "😐"], ["Unhappy", "🙁"], ["Need help", "🙏"]].forEach(([value, label]) => addChoiceButton(value, label));
    } else if (feedbackType === "slider") {
      css(grid, {display: "grid", gap: "8px"});
      const slider = document.createElement("input");
      slider.type = "range";
      slider.min = "1";
      slider.max = "10";
      slider.value = "8";
      const valueLabel = document.createElement("div");
      valueLabel.textContent = `Satisfaction: ${slider.value}/10`;
      css(valueLabel, {fontSize: "12px", color: "#475569", fontWeight: "800"});
      slider.oninput = () => valueLabel.textContent = `Satisfaction: ${slider.value}/10`;
      const submit = document.createElement("button");
      submit.type = "button";
      submit.textContent = "Send feedback";
      css(submit, {border: "0", borderRadius: "10px", background: themeBackground(), color: "#fff", cursor: "pointer", fontWeight: "800", padding: "9px 10px"});
      submit.onclick = () => submitFeedback(`Satisfaction ${slider.value}/10`, grid);
      grid.appendChild(slider);
      grid.appendChild(valueLabel);
      grid.appendChild(submit);
    } else if (feedbackType === "comment") {
      css(grid, {display: "grid", gap: "8px"});
      const textarea = document.createElement("textarea");
      textarea.placeholder = "Write your feedback...";
      textarea.rows = 3;
      css(textarea, {width: "100%", boxSizing: "border-box", border: "1px solid #e2e8f0", borderRadius: "10px", padding: "9px 10px", font: "inherit", fontSize: "12px", resize: "vertical"});
      const submit = document.createElement("button");
      submit.type = "button";
      submit.textContent = "Send feedback";
      css(submit, {border: "0", borderRadius: "10px", background: themeBackground(), color: "#fff", cursor: "pointer", fontWeight: "800", padding: "9px 10px"});
      submit.onclick = () => {
        const value = textarea.value.trim();
        if (value) submitFeedback(value, grid);
      };
      grid.appendChild(textarea);
      grid.appendChild(submit);
    } else {
      css(grid, {display: "grid", gridTemplateColumns: "repeat(5, minmax(0, 1fr))", gap: "6px"});
      ["Great", "Helpful", "Okay", "Poor", "Need help"].forEach(value => addChoiceButton(value));
    }
    panel.appendChild(title);
    panel.appendChild(grid);
    suggestionsBox.appendChild(panel);
  }

  function showFaqActionFeedback(action, context = {}, suggestionsBox = null, delay = 0) {
    if (!faqFeedbackEnabledFor(action)) return;
    window.setTimeout(() => renderFaqActionFeedback(action, context, suggestionsBox), delay);
  }

  async function copyText(value, suggestionsBox) {
    try {
      await navigator.clipboard.writeText(value);
      showInlineActionNotice(suggestionsBox, "Code copied. You can paste it at checkout.");
    } catch (e) {
      showInlineActionNotice(suggestionsBox, `Copy this code: ${value}`);
    }
  }

  async function showFaqCategory(value, suggestionsBox, input, options = {}) {
    if (!suggestionsBox || !input) return;
    saveSelectedFaqCategory(value);
    const response = await api("get_faqs_by_category", "GET", null, `&customer_id=${encodeURIComponent(customerId)}&category=${encodeURIComponent(value)}${fullFaqListQueryFlag()}`);
    const items = Array.isArray(response.data) ? response.data : [];
    if (!options.preserveActions) {
      activeFaqActions = [];
      activeFaqActionContext = {};
    }
    if (!items.length) {
      showInlineActionNotice(suggestionsBox, "No active FAQs found in this category.", "error");
      renderCategorySwitcher(
        suggestionsBox,
        selectedFaqCategory,
        options.onChangeCategory || (() => showPublicCategoryMenu(suggestionsBox, input))
      );
      return;
    }
    renderSuggestions(suggestionsBox, input, items, {
      showCategorySwitcher: true,
      currentCategory: selectedFaqCategory,
      onChangeCategory: options.onChangeCategory || (() => showPublicCategoryMenu(suggestionsBox, input))
    });
  }

  function renderFaqActionForm(action, context, suggestionsBox) {
    if (!suggestionsBox) return;
    activeFaqActions = [];
    activeFaqActionContext = {};
    suggestionsBox.innerHTML = "";
    suggestionsBox.style.display = "grid";
    const panel = document.createElement("form");
    panel.className = "vani-action-panel";
    css(panel, {
      display: "grid",
      gap: "8px",
      padding: "11px",
      borderRadius: "16px",
      background: "rgba(255,255,255,.94)",
      border: "1px solid rgba(199,210,254,.75)",
      boxShadow: "0 14px 34px rgba(15,23,42,.10)"
    });
    const title = document.createElement("strong");
    title.textContent = action.action_value || action.label || "Send request";
    css(title, {fontSize: "13px", color: "#0f172a"});
    const nameInput = document.createElement("input");
    const emailInput = document.createElement("input");
    const phoneInput = document.createElement("input");
    const messageInput = document.createElement("textarea");
    [
      [nameInput, "Your name"],
      [emailInput, "Email address"],
      [phoneInput, "Mobile number"],
      [messageInput, "Message"]
    ].forEach(([field, placeholder]) => {
      field.placeholder = placeholder;
      css(field, {
        width: "100%",
        boxSizing: "border-box",
        border: "1px solid #e2e8f0",
        borderRadius: "12px",
        padding: "9px 10px",
        font: "inherit",
        fontSize: "12px",
        outline: "none",
        resize: "vertical"
      });
    });
    emailInput.type = "email";
    phoneInput.type = "tel";
    messageInput.rows = 2;
    const submit = document.createElement("button");
    submit.type = "submit";
    submit.textContent = "Send request";
    css(submit, {
      border: "0",
      borderRadius: "12px",
      background: themeBackground(),
      color: "#fff",
      cursor: "pointer",
      fontWeight: "800",
      padding: "10px 12px"
    });
    const status = document.createElement("div");
    css(status, {
      display: "none",
      padding: "8px 10px",
      borderRadius: "10px",
      fontSize: "12px",
      fontWeight: "700",
      lineHeight: "1.4"
    });
    const setStatus = (text, ok = false) => {
      status.textContent = text;
      status.style.display = "block";
      status.style.background = ok ? "rgba(34,197,94,.12)" : "rgba(239,68,68,.1)";
      status.style.color = ok ? "#166534" : "#991b1b";
      status.style.border = ok ? "1px solid rgba(34,197,94,.22)" : "1px solid rgba(239,68,68,.2)";
    };
    panel.onsubmit = async event => {
      event.preventDefault();
      const message = messageInput.value.trim();
      if (!message) {
        setStatus("Please enter a message before sending.");
        return;
      }
      submit.disabled = true;
      submit.textContent = "Sending...";
      const response = await api("submit_faq_action_form", "POST", {
        customer_id: customerId,
        user_id: userId,
        action_id: action.id || null,
        faq_id: action.faq_id || context.matched_faq_id || null,
        action_label: action.label || "FAQ action form",
        name: nameInput.value.trim(),
        email: emailInput.value.trim(),
        phone: phoneInput.value.trim(),
        message,
        source_url: sourceUrl
      });
      if (response.success) {
        setStatus("Request sent. The business team can follow up from their dashboard.", true);
        showFaqActionFeedback(action, context, suggestionsBox, 200);
        submit.textContent = "Sent";
        nameInput.disabled = true;
        emailInput.disabled = true;
        phoneInput.disabled = true;
        messageInput.disabled = true;
      } else {
        submit.disabled = false;
        submit.textContent = "Send request";
        setStatus(response.message || "Request could not be sent.");
      }
    };
    panel.appendChild(title);
    panel.appendChild(nameInput);
    panel.appendChild(emailInput);
    panel.appendChild(phoneInput);
    panel.appendChild(messageInput);
    panel.appendChild(submit);
    panel.appendChild(status);
    suggestionsBox.appendChild(panel);
  }

  function renderPaymentForm(action, context, suggestionsBox) {
    if (!suggestionsBox) return;
    clearActiveFaqActionState();
    const paymentAction = Array.isArray(config.payment_actions)
      ? config.payment_actions.find(item => String(item.id || "") === String(action.action_value || ""))
      : null;
    const isUpiPayment = (paymentAction?.payment_method || action.payment_method || "").toLowerCase() === "upi";
    const collectPayerEmail = !(config.payment_collect_payer_email === false || config.payment_collect_payer_email === 0 || config.payment_collect_payer_email === "0" || config.payment_collect_payer_email === "false");
    const collectPayerPhone = !(config.payment_collect_payer_phone === false || config.payment_collect_payer_phone === 0 || config.payment_collect_payer_phone === "0" || config.payment_collect_payer_phone === "false");
    const verifyPayerEmailOtp = collectPayerEmail && isEnabled(config.payment_verify_payer_email_otp);
    const verifyPayerPhoneOtp = collectPayerPhone && isEnabled(config.payment_verify_payer_phone_otp);
    suggestionsBox.innerHTML = "";
    suggestionsBox.style.display = "grid";
    const panel = document.createElement("form");
    panel.className = "vani-action-panel";
    css(panel, {display: "grid", gap: "8px", padding: "11px", borderRadius: "16px", background: "rgba(255,255,255,.95)", border: "1px solid rgba(199,210,254,.75)", boxShadow: "0 14px 34px rgba(15,23,42,.10)"});
    const title = document.createElement("strong");
    title.textContent = action.label || "Pay now";
    css(title, {fontSize: "13px", color: "#0f172a"});
    const nameInput = document.createElement("input");
    const emailInput = document.createElement("input");
    const phoneInput = document.createElement("input");
    const emailOtpInput = document.createElement("input");
    let emailOtpLeadId = "";
    let emailVerifiedValue = "";
    let phoneVerifiedValue = "";
    const contactFields = [[nameInput, "Your name"]];
    if (collectPayerEmail) contactFields.push([emailInput, "Email address"]);
    if (collectPayerPhone) contactFields.push([phoneInput, "Mobile number"]);
    contactFields.forEach(([field, placeholder]) => {
      field.placeholder = placeholder;
      css(field, {width: "100%", boxSizing: "border-box", border: "1px solid #e2e8f0", borderRadius: "12px", padding: "9px 10px", font: "inherit", fontSize: "12px", outline: "none"});
    });
    emailInput.type = "email";
    phoneInput.type = "tel";
    emailOtpInput.type = "text";
    emailOtpInput.inputMode = "numeric";
    emailOtpInput.maxLength = 6;
    emailOtpInput.placeholder = "Email OTP";
    css(emailOtpInput, {display: "none", width: "100%", boxSizing: "border-box", border: "1px solid #c7d2fe", borderRadius: "12px", padding: "9px 10px", font: "inherit", fontSize: "12px", outline: "none"});
    const submit = document.createElement("button");
    submit.type = "submit";
    submit.textContent = "Continue to payment";
    css(submit, {border: "0", borderRadius: "12px", background: themeBackground(), color: "#fff", cursor: "pointer", fontWeight: "800", padding: "10px 12px"});
    const status = document.createElement("div");
    css(status, {display: "none", padding: "8px 10px", borderRadius: "10px", fontSize: "12px", fontWeight: "700"});
    const setStatus = (text, ok = false) => {
      status.textContent = text;
      status.style.display = "block";
      status.style.background = ok ? "rgba(34,197,94,.12)" : "rgba(239,68,68,.1)";
      status.style.color = ok ? "#166534" : "#991b1b";
    };
    const renderUpiReferenceForm = transactionId => {
      if (!transactionId || panel.querySelector(".vani-upi-reference-form")) return;
      const referenceWrap = document.createElement("div");
      referenceWrap.className = "vani-upi-reference-form";
      css(referenceWrap, {display: "grid", gap: "8px", padding: "10px", borderRadius: "14px", background: "rgba(240,253,244,.9)", border: "1px solid rgba(34,197,94,.22)"});
      const help = document.createElement("small");
      help.textContent = "After payment, enter your UPI transaction ID so the business can verify and confirm on your mobile number.";
      css(help, {color: "#166534", fontWeight: "700", lineHeight: "1.45"});
      const referenceInput = document.createElement("input");
      referenceInput.placeholder = "UPI transaction ID";
      css(referenceInput, {width: "100%", boxSizing: "border-box", border: "1px solid #bbf7d0", borderRadius: "12px", padding: "9px 10px", font: "inherit", fontSize: "12px", outline: "none"});
      const referenceButton = document.createElement("button");
      referenceButton.type = "button";
      referenceButton.textContent = "Submit transaction ID";
      css(referenceButton, {border: "0", borderRadius: "12px", background: "#16a34a", color: "#fff", cursor: "pointer", fontWeight: "800", padding: "10px 12px"});
      referenceButton.onclick = async () => {
        const upiReference = referenceInput.value.trim();
        if (!upiReference) {
          setStatus("Enter the UPI transaction ID after payment.");
          referenceInput.focus();
          return;
        }
        referenceButton.disabled = true;
        referenceButton.textContent = "Saving...";
        const saveResponse = await api("submit_upi_transaction_reference", "POST", {
          customer_id: customerId,
          transaction_id: transactionId,
          upi_reference: upiReference
        });
        referenceButton.disabled = false;
        referenceButton.textContent = "Submit transaction ID";
        if (!saveResponse.success) {
          setStatus(saveResponse.message || "Transaction ID could not be saved.");
          return;
        }
        referenceInput.disabled = true;
        referenceButton.disabled = true;
        referenceButton.textContent = "Submitted";
        setStatus(saveResponse.message || "Transaction ID saved. The business will verify and confirm.", true);
      };
      referenceWrap.appendChild(help);
      referenceWrap.appendChild(referenceInput);
      referenceWrap.appendChild(referenceButton);
      panel.insertBefore(referenceWrap, status);
    };
    const openUpiLink = (link, transactionId = "") => {
      window.location.href = link;
      setStatus("UPI app opened. Payment will remain pending until the business verifies it.", true);
      submit.disabled = false;
      submit.textContent = "Open UPI options again";
      if (config.upi_transaction_id_required !== false && config.upi_transaction_id_required !== 0 && config.upi_transaction_id_required !== "0" && config.upi_transaction_id_required !== "false") {
        renderUpiReferenceForm(transactionId);
      }
      showFaqActionFeedback(action, context, suggestionsBox, 250);
    };
    const upiIntentLink = (upiLink, packageName = "") => {
      const intentPath = upiLink.replace(/^upi:\/\//i, "");
      return `intent://${intentPath}#Intent;scheme=upi;${packageName ? `package=${packageName};` : ""}end`;
    };
    const renderUpiChoices = (upiLink, transactionId = "") => {
      let choices = panel.querySelector(".vani-upi-choice-grid");
      if (choices) choices.remove();
      choices = document.createElement("div");
      choices.className = "vani-upi-choice-grid";
      css(choices, {display: "grid", gridTemplateColumns: "1fr 1fr", gap: "8px"});
      const apps = [
        ["Google Pay", "com.google.android.apps.nbu.paisa.user"],
        ["PhonePe", "com.phonepe.app"],
        ["Paytm", "net.one97.paytm"],
        ["BHIM", "in.org.npci.upiapp"]
      ];
      apps.forEach(([label, packageName]) => {
        const button = document.createElement("button");
        button.type = "button";
        button.textContent = label;
        css(button, {border: "1px solid #e2e8f0", borderRadius: "12px", background: "#fff", color: "#0f172a", cursor: "pointer", fontWeight: "800", padding: "9px 10px", fontSize: "12px"});
        button.onclick = () => openUpiLink(upiIntentLink(upiLink, packageName), transactionId);
        choices.appendChild(button);
      });
      const other = document.createElement("button");
      other.type = "button";
      other.textContent = "Other UPI app";
      css(other, {gridColumn: "1 / -1", border: "0", borderRadius: "12px", background: themeBackground(), color: "#fff", cursor: "pointer", fontWeight: "800", padding: "10px 12px"});
      other.onclick = () => openUpiLink(upiIntentLink(upiLink), transactionId);
      choices.appendChild(other);
      panel.insertBefore(choices, submit);
    };
    const finishSuccessfulPayment = message => {
      setStatus(message, true);
      const messageBox = chatMessagesContainer();
      if (messageBox) addMessage(messageBox, message, "bot");
      if (faqFeedbackEnabledFor(action)) {
        clearActiveFaqActionState();
        suggestionsBox.innerHTML = "";
        suggestionsBox.style.display = "grid";
        window.setTimeout(() => renderFaqActionFeedback(action, context, suggestionsBox), 250);
        return;
      }
      clearActiveFaqActionState();
      let remaining = 5;
      submit.disabled = true;
      submit.textContent = `Closing in ${remaining}s`;
      const timer = window.setInterval(() => {
        remaining -= 1;
        if (remaining > 0) {
          submit.textContent = `Closing in ${remaining}s`;
          return;
        }
        window.clearInterval(timer);
        panel.remove();
        if (!suggestionsBox.children.length) {
          suggestionsBox.style.display = "none";
        }
        const messageBox = chatMessagesContainer();
        if (messageBox) addMessage(messageBox, "How can I help you further?", "bot");
      }, 1000);
    };
    panel.onsubmit = async event => {
      event.preventDefault();
      if (!nameInput.value.trim()) {
        setStatus("Enter your name to continue.");
        nameInput.focus();
        return;
      }
      const payerEmail = collectPayerEmail ? emailInput.value.trim() : "";
      const payerPhone = collectPayerPhone ? normalizePhone(phoneInput.value.trim()) : "";
      if (collectPayerEmail && payerEmail && !validEmail(payerEmail)) {
        setStatus("Enter a valid email address.");
        emailInput.focus();
        return;
      }
      if (verifyPayerEmailOtp) {
        if (!payerEmail || !validEmail(payerEmail)) {
          setStatus("Enter a valid email address to verify.");
          emailInput.focus();
          return;
        }
        if (emailVerifiedValue !== payerEmail) {
          if (!emailOtpLeadId) {
            submit.disabled = true;
            submit.textContent = "Sending email OTP...";
            const emailRes = await api("create_lead_send_email_otp", "POST", {
              customer_id: customerId,
              user_id: userId,
              email: payerEmail,
              source_url: sourceUrl,
              suppress_notification: true
            });
            submit.disabled = false;
            submit.textContent = "Verify email OTP";
            if (!emailRes.success || !emailRes.lead) {
              setStatus(emailRes.email_error || emailRes.message || "Could not send email OTP. Try again.");
              return;
            }
            emailOtpLeadId = emailRes.lead.id || "";
            emailInput.disabled = true;
            emailOtpInput.style.display = "block";
            emailOtpInput.focus();
            setStatus("Email OTP sent. Enter it to continue.", true);
            return;
          }
          const emailOtp = emailOtpInput.value.trim();
          if (!/^[0-9]{6}$/.test(emailOtp)) {
            setStatus("Enter the 6-digit email OTP.");
            emailOtpInput.focus();
            return;
          }
          submit.disabled = true;
          submit.textContent = "Verifying email...";
          const verifyEmailRes = await api("verify_lead_email_otp", "POST", {
            customer_id: customerId,
            lead_id: emailOtpLeadId,
            otp: emailOtp,
            suppress_notification: true,
            notification_event: "payment"
          });
          submit.disabled = false;
          if (!verifyEmailRes.success) {
            submit.textContent = "Verify email OTP";
            setStatus(verifyEmailRes.message || "Email OTP verification failed.");
            return;
          }
          emailVerifiedValue = payerEmail;
          emailOtpInput.disabled = true;
          emailOtpInput.style.display = "none";
          submit.textContent = "Continue to payment";
          setStatus("Email verified.", true);
        }
      }
      if (verifyPayerPhoneOtp) {
        if (!payerPhone || !validPhone(payerPhone)) {
          setStatus("Enter a valid mobile number with country code to verify.");
          phoneInput.focus();
          return;
        }
        if (phoneVerifiedValue !== payerPhone) {
          submit.disabled = true;
          submit.textContent = "Verifying mobile...";
          try {
            const verified = await openMobileOtpWidget(payerPhone);
            const mobileRes = await api("verify_lead_mobile_msg91", "POST", {
              customer_id: customerId,
              user_id: userId,
              phone_number: verified.phone || payerPhone,
              msg91_access_token: verified.msg91_access_token || "",
              source_url: sourceUrl,
              msg91_response: verified.msg91_response || null,
              suppress_notification: true
            });
            if (!mobileRes.success || !mobileRes.lead) {
              submit.disabled = false;
              submit.textContent = "Continue to payment";
              setStatus(mobileRes.message || "Mobile OTP verification failed.");
              return;
            }
            const verifiedPhone = normalizePhone(mobileRes.lead.phone_number || verified.phone || payerPhone);
            phoneInput.value = verifiedPhone;
            phoneVerifiedValue = verifiedPhone;
            setStatus("Mobile number verified.", true);
          } catch (error) {
            submit.disabled = false;
            submit.textContent = "Continue to payment";
            setStatus("Mobile OTP verification was not completed.");
            return;
          }
        }
      }
      submit.disabled = true;
      submit.textContent = "Preparing...";
      const createPayload = {
        customer_id: customerId,
        payment_action_id: action.action_value,
        faq_action_id: action.id || null,
        faq_id: action.faq_id || context.matched_faq_id || null,
        user_id: userId,
        session_id: sessionId,
        source_url: sourceUrl,
        payer_name: nameInput.value.trim(),
        payer_email: collectPayerEmail ? emailInput.value.trim() : "",
        payer_phone: collectPayerPhone ? phoneInput.value.trim() : ""
      };
      const orderResponse = await api("create_customer_payment_order", "POST", createPayload);
      if (!orderResponse.success) {
        submit.disabled = false;
        submit.textContent = "Continue to payment";
        setStatus(orderResponse.message || "Payment order could not be created.");
        return;
      }
      if (orderResponse.payment_method === "upi" || orderResponse.upi_link) {
        if (!orderResponse.upi_link) {
          submit.disabled = false;
          submit.textContent = "Open UPI app";
          setStatus(orderResponse.message || "UPI payment could not be started.");
          return;
        }
        renderUpiChoices(orderResponse.upi_link, orderResponse.transaction?.id || "");
        setStatus("Choose the UPI app you want to use.", true);
        submit.disabled = false;
        submit.textContent = "Refresh UPI options";
        return;
      }
      if (!orderResponse.order?.id) {
        submit.disabled = false;
        submit.textContent = "Continue to payment";
        setStatus(orderResponse.message || "Payment order could not be created.");
        return;
      }
      const paymentAction = orderResponse.payment_action || {};
      const checkoutOptions = {
        key: orderResponse.key_id,
        amount: paymentAction.amount_paise,
        currency: paymentAction.currency || "INR",
        name: orderResponse.business_name || config.bot_name || "Payment",
        description: paymentAction.description || paymentAction.label || action.label || "Payment",
        order_id: orderResponse.order?.id,
        prefill: {name: nameInput.value.trim(), email: collectPayerEmail ? emailInput.value.trim() : "", contact: collectPayerPhone ? phoneInput.value.trim() : ""},
        theme: {color: themeAccent()}
      };
      const verifyRazorpayPayment = async response => {
        const successText = orderResponse.success_message || "Payment received. Thank you.";
        setStatus("Payment completed. Confirming it now...", true);
        const verify = await api("verify_customer_payment", "POST", {
          customer_id: customerId,
          razorpay_order_id: response.razorpay_order_id,
          razorpay_payment_id: response.razorpay_payment_id,
          razorpay_signature: response.razorpay_signature,
          payer_name: nameInput.value.trim(),
          payer_email: collectPayerEmail ? emailInput.value.trim() : "",
          payer_phone: collectPayerPhone ? phoneInput.value.trim() : ""
        });
        if (verify.success) {
          const message = verify.message || successText;
          finishSuccessfulPayment(message);
        } else {
          submit.disabled = false;
          submit.textContent = "Try payment again";
          setStatus(verify.message || "Payment verification failed.");
        }
      };
      if (window.parent !== window) {
        const parentResult = await openRazorpayOnParent(checkoutOptions);
        if (parentResult?.success && parentResult.response) {
          await verifyRazorpayPayment(parentResult.response);
        } else {
          if (parentResult?.error || parentResult?.payment_id) {
            await api("record_customer_payment_failure", "POST", {
              customer_id: customerId,
              razorpay_order_id: orderResponse.order?.id || "",
              razorpay_payment_id: parentResult?.error?.metadata?.payment_id || parentResult?.payment_id || "",
              message: parentResult?.message || "Payment failed or was cancelled.",
              error: parentResult?.error || null,
              payer_name: nameInput.value.trim(),
              payer_email: collectPayerEmail ? emailInput.value.trim() : "",
              payer_phone: collectPayerPhone ? phoneInput.value.trim() : ""
            });
          }
          submit.disabled = false;
          submit.textContent = "Continue to payment";
          setStatus(parentResult?.message || (parentResult?.dismissed ? "Payment window closed before completion." : "Payment checkout could not be opened."));
        }
        return;
      }
      const loaded = await loadRazorpayCheckout();
      if (!loaded || !window.Razorpay) {
        submit.disabled = false;
        submit.textContent = "Continue to payment";
        setStatus("Payment checkout could not be loaded.");
        return;
      }
      try {
        let checkoutSettled = false;
        let checkout = null;
        const closeCheckout = () => {
          try {
            if (checkout && typeof checkout.close === "function") checkout.close();
          } catch (error) {}
        };
        checkout = new Razorpay({
          ...checkoutOptions,
          handler: response => {
            checkoutSettled = true;
            closeCheckout();
            window.setTimeout(() => verifyRazorpayPayment(response), 300);
          },
          modal: {ondismiss: () => {
            if (checkoutSettled) return;
            submit.disabled = false;
            submit.textContent = "Continue to payment";
            setStatus("Payment window closed before completion.");
          }}
        });
        if (typeof checkout.on === "function") {
          checkout.on("payment.failed", async response => {
            checkoutSettled = true;
            const message = response?.error?.description || "Payment failed or was cancelled.";
            closeCheckout();
            window.setTimeout(async () => {
              await api("record_customer_payment_failure", "POST", {
                customer_id: customerId,
                razorpay_order_id: orderResponse.order?.id || "",
                razorpay_payment_id: response?.error?.metadata?.payment_id || "",
                message,
                error: response?.error || null,
                payer_name: nameInput.value.trim(),
                payer_email: collectPayerEmail ? emailInput.value.trim() : "",
                payer_phone: collectPayerPhone ? phoneInput.value.trim() : ""
              });
              submit.disabled = false;
              submit.textContent = "Try payment again";
              setStatus(message);
              const messageBox = chatMessagesContainer();
              if (messageBox) addMessage(messageBox, message, "bot");
            }, 300);
          });
        }
        checkout.open();
      } catch (error) {
        submit.disabled = false;
        submit.textContent = "Continue to payment";
        setStatus(error?.message || "Payment checkout could not be opened.");
      }
    };
    panel.appendChild(title);
    panel.appendChild(nameInput);
    if (collectPayerEmail) panel.appendChild(emailInput);
    if (verifyPayerEmailOtp) panel.appendChild(emailOtpInput);
    if (collectPayerPhone) panel.appendChild(phoneInput);
    panel.appendChild(submit);
    panel.appendChild(status);
    suggestionsBox.appendChild(panel);
  }

  function handleFaqAction(action, context = {}, suggestionsBox = null, input = null) {
    const actionType = action.action_type || "link";
    const value = String(action.action_value || "").trim();
    if (actionType !== "form" && actionType !== "category" && actionType !== "payment") {
      activeFaqActions = [];
      activeFaqActionContext = {};
    }
    if (suggestionsBox && input && !["form", "category", "coupon", "payment"].includes(actionType)) {
      suggestionsBox.style.transition = "opacity .16s ease, transform .16s ease";
      suggestionsBox.style.opacity = "0";
      suggestionsBox.style.transform = "translateY(4px)";
      window.setTimeout(() => {
        renderSuggestions(suggestionsBox, input, [], {includeFaqs: false});
        suggestionsBox.style.opacity = "1";
        suggestionsBox.style.transform = "translateY(0)";
      }, 150);
    }
    emitLiveAction("faqActionClicked", {
      action_id: action.id || null,
      faq_id: action.faq_id || context.matched_faq_id || null,
      label: action.label || "",
      action_type: actionType,
      action_value: value,
      message: context.message || ""
    });
    if (["link", "download", "booking", "track_order"].includes(actionType)) {
      openHttps(value);
      showFaqActionFeedback(action, context, suggestionsBox, 190);
      return;
    }
    if (actionType === "whatsapp") {
      const phone = cleanPhone(value);
      if (/^[1-9][0-9]{7,14}$/.test(phone)) window.open(`https://wa.me/${phone}`, "_blank", "noopener,noreferrer");
      showFaqActionFeedback(action, context, suggestionsBox, 190);
      return;
    }
    if (actionType === "call") {
      const phone = cleanPhone(value);
      if (/^[1-9][0-9]{7,14}$/.test(phone)) window.open(`tel:+${phone}`, "_blank");
      showFaqActionFeedback(action, context, suggestionsBox, 190);
      return;
    }
    if (actionType === "email") {
      if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) window.open(`mailto:${value}`, "_blank");
      return;
    }
    if (actionType === "coupon") {
      copyText(value, suggestionsBox);
      showFaqActionFeedback(action, context, suggestionsBox, 120);
      return;
    }
    if (actionType === "map") {
      if (/^https:\/\//i.test(value)) {
        openHttps(value);
      } else {
        window.open(`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(value)}`, "_blank", "noopener,noreferrer");
      }
      return;
    }
    if (actionType === "form") {
      renderFaqActionForm(action, context, suggestionsBox);
      return;
    }
    if (actionType === "payment") {
      renderPaymentForm(action, context, suggestionsBox);
      return;
    }
    if (actionType === "category") {
      showFaqCategory(value, suggestionsBox, input);
      return;
    }
    if (actionType === "event") {
      window.dispatchEvent(new CustomEvent(`vani:${value}`, {
        detail: {
          customer_id: customerId,
          user_id: userId,
          session_id: sessionId,
          source_url: sourceUrl,
          action,
          context
        }
      }));
    }
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
      `&customer_id=${encodeURIComponent(customerId)}&current_url=${encodeURIComponent(sourceUrl)}`
    );
    config = cfg || {};
    selectedFaqCategory = loadSelectedFaqCategory();

    if (config.is_active === false || config.access_allowed === false) {
      notifyFrameState(false, true);
      return;
    }

    const color = themeBackground();
    const position = config.position === "left" ? "left" : "right";
    const sideStyles = position === "left" ? {left: "20px"} : {right: "20px"};
    const greetingSideStyles = position === "left" ? {left: "90px"} : {right: "90px"};
    const avatarUrl = resolveAssetUrl(config.avatar_url);
    const greetingText = (config.welcome_message || defaultGreeting).trim() || defaultGreeting;
    notifyFrameState(isEnabled(config.chat_open_by_default) || forceOpenHint);
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
      @keyframes vaniMessageIn {
        from { opacity: 0; transform: translateY(10px) scale(.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
      }
      @keyframes vaniSuggestionIn {
        from { opacity: 0; transform: translateY(8px) scale(.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
      }
      @keyframes vaniChatBoxIn {
        from { opacity: 0; transform: translateY(14px) scale(.97); }
        to { opacity: 1; transform: translateY(0) scale(1); }
      }
      @keyframes vaniActionPanelIn {
        from { opacity: 0; transform: translateY(12px); filter: blur(2px); }
        to { opacity: 1; transform: translateY(0); filter: blur(0); }
      }
      @keyframes vaniActionPulse {
        0%, 100% { box-shadow: 0 10px 26px rgba(79,70,229,.10); }
        50% { box-shadow: 0 14px 34px rgba(79,70,229,.18); }
      }
      @keyframes vaniThinkingDot {
        0%, 80%, 100% { opacity: .25; transform: translateY(0); }
        40% { opacity: 1; transform: translateY(-2px); }
      }
      .vani-suggestion-card {
        animation: vaniSuggestionIn .22s ease both;
      }
      .vani-action-panel {
        animation: vaniActionPanelIn .26s ease both;
      }
      .vani-thinking-dots {
        display: inline-flex;
        gap: 3px;
        align-items: center;
      }
      .vani-thinking-dots i {
        width: 4px;
        height: 4px;
        border-radius: 999px;
        background: currentColor;
        animation: vaniThinkingDot 1.15s ease-in-out infinite;
      }
      .vani-thinking-dots i:nth-child(2) { animation-delay: .14s; }
      .vani-thinking-dots i:nth-child(3) { animation-delay: .28s; }
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
      overflow: "visible",
      padding: avatarUrl ? "5px" : "0",
      zIndex: "999999",
      boxShadow: "0 12px 28px rgba(255, 255, 255, 0)"
    });
    css(icon, sideStyles);

    if (avatarUrl) {
      const iconImage = document.createElement("img");
      iconImage.src = avatarUrl;
      iconImage.alt = "";
      css(iconImage, {
        width: "100%",
        height: "100%",
        borderRadius: "0",
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
          overflow: "hidden",
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
      fontFamily: "Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
      transformOrigin: position === "left" ? "bottom left" : "bottom right"
    });
    css(box, sideStyles);

    box.innerHTML = `
      <div data-vani-header style="padding:11px 12px 11px 14px;color:#fff;background:${color};font-weight:700;display:flex;align-items:center;gap:10px;">
        <span data-vani-title style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
        <button data-vani-close type="button" aria-label="Close chat" title="Close chat" style="width:30px;height:30px;border:1px solid rgba(255,255,255,.38);border-radius:999px;background:rgba(255,255,255,.16);color:#fff;cursor:pointer;font-size:20px;line-height:1;display:flex;align-items:center;justify-content:center;padding:0;">×</button>
      </div>
      <div data-vani-messages style="flex:1 1 72px;min-height:52px;overflow:auto;padding:14px;background:linear-gradient(180deg,#f8fafc 0%,#eef2ff 100%);scroll-behavior:smooth;"></div>
      <div data-vani-suggestions style="flex:0 0 auto;max-height:210px;overflow:auto;background:transparent;border-top:0;padding:8px;display:grid;gap:7px;"></div>
      <div data-vani-lead-prompt style="display:none;padding:10px;border-top:1px solid #e5e7eb;background:#fff;"></div>
      <div data-vani-whatsapp-action style="display:none;padding:10px;border-top:1px solid #e5e7eb;background:#fff;"></div>
      <div data-vani-input-row style="display:flex;border-top:1px solid #e5e7eb;background:#fff;">
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
    const inputRow = box.querySelector("[data-vani-input-row]");
    const closeBtn = box.querySelector("[data-vani-close]");
    const suggestionsBox = box.querySelector("[data-vani-suggestions]");
    const whatsappAction = box.querySelector("[data-vani-whatsapp-action]");
    box.querySelector("[data-vani-title]").textContent = config.bot_name || "Chat Support";
    const embeddedWidget = window.parent !== window;
    let userScrolledMessages = false;

    function messagesNearBottom() {
      if (!messages) return true;
      return messages.scrollHeight - messages.scrollTop - messages.clientHeight < 80;
    }

    function scrollMessagesToLatest({force = false} = {}) {
      if (!messages || (!force && userScrolledMessages)) return;
      messages.scrollTop = messages.scrollHeight;
    }

    messages?.addEventListener("scroll", () => {
      userScrolledMessages = !messagesNearBottom();
    }, {passive: true});

    function scrollLatestAfterKeyboardChange() {
      if (box.style.display !== "flex") return;
      window.requestAnimationFrame(() => scrollMessagesToLatest());
      window.setTimeout(() => scrollMessagesToLatest(), 120);
    }

    function scrollLatestWhileTyping() {
      if (box.style.display !== "flex") return;
      userScrolledMessages = false;
      window.requestAnimationFrame(() => scrollMessagesToLatest({force: true}));
    }

    input.addEventListener("focus", scrollLatestAfterKeyboardChange);
    window.visualViewport?.addEventListener("resize", scrollLatestAfterKeyboardChange);
    window.visualViewport?.addEventListener("scroll", scrollLatestAfterKeyboardChange);

    function applyEmbeddedLayout(open) {
      if (!embeddedWidget) return;
      if (open) {
        icon.style.display = "flex";
        greeting.style.display = "none";
        css(icon, {
          bottom: "20px",
          left: position === "left" ? "20px" : "auto",
          right: position === "right" ? "20px" : "auto"
        });
        css(box, {
          bottom: "90px",
          left: position === "left" ? "20px" : "auto",
          right: position === "right" ? "20px" : "auto",
          width: "min(360px, calc(100vw - 28px))",
          height: "min(520px, calc(100vh - 118px))",
          boxSizing: "border-box"
        });
        return;
      }

      icon.style.display = "flex";
      greeting.style.display = "block";
      css(icon, {
        bottom: "20px",
        left: position === "left" ? "20px" : "auto",
        right: position === "right" ? "20px" : "auto"
      });
      css(greeting, {
        bottom: "30px",
        left: position === "left" ? "90px" : "auto",
        right: position === "right" ? "90px" : "auto"
      });
      css(box, {
        bottom: "90px",
        left: position === "left" ? "20px" : "auto",
        right: position === "right" ? "20px" : "auto",
        width: "min(360px, calc(100vw - 28px))",
        height: "min(520px, calc(100vh - 118px))"
      });
    }

    applyEmbeddedLayout(false);

    if (!userInputEnabled() && inputRow) {
      inputRow.style.display = "none";
      input.setAttribute("aria-hidden", "true");
      input.tabIndex = -1;
      sendBtn.tabIndex = -1;
    }
    const patternCss = patternBackground(config.theme_pattern || "none");
    if (messages && patternCss) {
      messages.style.backgroundImage = `${patternCss}, linear-gradient(180deg,#f8fafc 0%,#eef2ff 100%)`;
      messages.style.backgroundSize = "18px 18px, cover";
    }
    let debounce;
    let clearActiveActionTypingTimer;

    addMessage(messages, greetingText, "bot");

    async function loadCategoryMenu() {
      await showPublicCategoryMenu(suggestionsBox, input);
    }

    async function loadSelectedCategoryFaqs(options = {}) {
      if (!selectedFaqCategory) return false;
      await showFaqCategory(selectedFaqCategory, suggestionsBox, input, {
        onChangeCategory: loadCategoryMenu,
        preserveActions: !!options.preserveActions
      });
      return true;
    }

    async function loadTop(options = {}) {
      if (activeFaqActions.length && userInputEnabled()) {
        renderSuggestions(suggestionsBox, input, [], {
          includeFaqs: false,
          showCategorySwitcher: isEnabled(config.faq_category_menu_enabled),
          currentCategory: selectedFaqCategory,
          onChangeCategory: loadCategoryMenu
        });
        return;
      }
      if (isEnabled(config.faq_category_menu_enabled)) {
        if (await loadSelectedCategoryFaqs({preserveActions: !!options.preserveActions})) return;
        await loadCategoryMenu();
        return;
      }
      const response = await api("get_top_faqs", "GET", null, `&customer_id=${encodeURIComponent(customerId)}${fullFaqListQueryFlag()}`);
      renderSuggestions(suggestionsBox, input, response.data || [], {
        showCategorySwitcher: isEnabled(config.faq_category_menu_enabled),
        currentCategory: selectedFaqCategory,
        onChangeCategory: loadCategoryMenu
      });
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
        const leadRes = await api("create_lead", "POST", {
          customer_id: customerId,
          user_id: userId,
          source_url: sourceUrl,
          whatsapp_redirected: true
        });
        emitLiveAction("whatsappClicked", {
          phone_number: phone,
          lead_id: leadRes?.lead?.id || null
        });

        if (isMobileDevice()) {
          window.open(`https://wa.me/${phone}`, "_blank", "noopener,noreferrer");
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
            source_url: sourceUrl,
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
                source_url: sourceUrl,
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
            source_url: sourceUrl,
            verification_quality: verifyEmailOtp || verifyMobileOtp ? 'poor' : 'poor'
          });
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
            ? await api("create_lead_send_email_otp", "POST", { customer_id: customerId, user_id: userId, email, source_url: sourceUrl })
            : await api("create_lead", "POST", { customer_id: customerId, user_id: userId, email, source_url: sourceUrl, verification_quality: "poor" });
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
                source_url: sourceUrl,
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
            source_url: sourceUrl,
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
      const selectedFaqId = input.dataset.selectedFaqId || "";
      const selectedDefaultFaqKey = input.dataset.selectedDefaultFaqKey || "";

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
      emitLiveAction("messageSent", {
        message,
        faq_id: selectedFaqId || null,
        default_faq_key: selectedDefaultFaqKey || null
      });
      activeFaqActions = [];
      activeFaqActionContext = {};
      window.clearTimeout(clearActiveActionTypingTimer);
      input.value = "";
      delete input.dataset.selectedFaqId;
      delete input.dataset.selectedDefaultFaqKey;
      suggestionsBox.innerHTML = "";
      suggestionsBox.style.display = "none";
      sessionChatStartedAt = sessionChatStartedAt || new Date().toISOString();
      sessionMessageCount++;
      const requestStartedAt = Date.now();
      const thinkingMessage = addThinkingMessage(messages);

      const response = await api("chat", "POST", {
        customer_id: customerId,
        message,
        faq_id: selectedFaqId,
        default_faq_key: selectedDefaultFaqKey,
        user_id: userId,
        session_id: sessionId,
        source_url: sourceUrl,
        started_at: sessionChatStartedAt,
        duration_seconds: sessionDurationSeconds(),
        message_count: sessionMessageCount,
        analytics: analyticsPayload({
          response_time_ms: Date.now() - requestStartedAt
        })
      });

      thinkingMessage.remove();
      addMessage(messages, response.reply || "No response", "bot");
      const scheduledAction = nextScheduledFaqAction();
      activeFaqActions = scheduledAction
        ? [scheduledAction]
        : (Array.isArray(response.actions) ? response.actions.filter(action => action && action.label && action.action_value) : []);
      activeFaqActionContext = {
        message,
        matched_faq_id: response.matched_faq_id || null
      };
      if (!userInputEnabled()) {
        await loadTop({preserveActions: true});
      } else if (isEnabled(config.faq_category_menu_enabled) && selectedFaqCategory) {
        await loadSelectedCategoryFaqs({preserveActions: true});
      } else {
        renderSuggestions(suggestionsBox, input, Array.isArray(response.default_suggestions) ? response.default_suggestions : [], {
          includeFaqs: true,
          showCategorySwitcher: isEnabled(config.faq_category_menu_enabled),
          currentCategory: selectedFaqCategory,
          onChangeCategory: loadCategoryMenu
        });
      }
      emitLiveAction(response.answered ? "faqAnswered" : "unknownQuestion", {
        message,
        reply: response.reply || "",
        answered: !!response.answered,
        matched_faq_id: response.matched_faq_id || null
      });
      trackWidgetSessionSoon({started_at: sessionChatStartedAt});
    };

    function openChat() {
      if (box.style.display === "flex") {
        notifyFrameState(true);
        return;
      }
      window.clearTimeout(chatOpenAnimationTimer);
      box.style.display = "flex";
      applyEmbeddedLayout(true);
      box.style.opacity = "0";
      box.style.transform = "translateY(14px) scale(.97)";
      box.style.animation = "vaniChatBoxIn .28s cubic-bezier(.2,.8,.2,1) forwards";
      greeting.style.display = "none";
      icon.setAttribute("aria-label", "Close chat");
      notifyFrameState(true);
      chatOpenAnimationTimer = window.setTimeout(() => {
        box.style.opacity = "1";
        box.style.transform = "translateY(0) scale(1)";
        box.style.animation = "";
      }, 320);
      sessionOpenedAt = sessionOpenedAt || new Date().toISOString();
      emitLiveAction("chatOpened", {opened_at: sessionOpenedAt});
      trackWidgetSessionSoon({opened_at: sessionOpenedAt});
      loadTop();
      if (userInputEnabled()) {
        suppressNextInputFocusLoad = true;
        input.focus();
      }
      renderWhatsAppAction();
      renderLeadPrompt();
    }

    function closeChat() {
      window.clearTimeout(chatOpenAnimationTimer);
      box.style.display = "none";
      box.style.opacity = "1";
      box.style.transform = "";
      box.style.animation = "";
      greeting.style.display = "block";
      icon.setAttribute("aria-label", "Open chat");
      applyEmbeddedLayout(false);
      notifyFrameState(false, true);
      trackWidgetSessionSoon();
    }

    icon.onclick = () => {
      const open = box.style.display === "flex";
      if (open) {
        closeChat();
      } else {
        openChat();
      }
    };
    greeting.onclick = icon.onclick;
    closeBtn.onclick = closeChat;

    input.addEventListener("focus", () => {
      if (suppressNextInputFocusLoad) {
        suppressNextInputFocusLoad = false;
        return;
      }
      if (userInputEnabled()) loadTop();
    });
    input.addEventListener("input", () => {
      if (!userInputEnabled()) return;
      scrollLatestWhileTyping();
      delete input.dataset.selectedFaqId;
      if (input.value.trim()) {
        window.clearTimeout(clearActiveActionTypingTimer);
        clearActiveActionTypingTimer = window.setTimeout(() => {
          const query = input.value.trim();
          const actionPanel = suggestionsBox.querySelector(".vani-action-panel");
          if (actionPanel) {
            actionPanel.style.transition = "opacity .28s ease, transform .28s ease, max-height .28s ease, margin .28s ease, padding .28s ease";
            actionPanel.style.opacity = "0";
            actionPanel.style.transform = "translateY(-6px)";
            actionPanel.style.maxHeight = `${actionPanel.scrollHeight}px`;
            window.requestAnimationFrame(() => {
              actionPanel.style.maxHeight = "0";
              actionPanel.style.marginTop = "0";
              actionPanel.style.marginBottom = "0";
              actionPanel.style.paddingTop = "0";
              actionPanel.style.paddingBottom = "0";
              actionPanel.style.overflow = "hidden";
            });
          }
          clearActiveFaqActionState();
          window.setTimeout(() => {
            if (query) {
              searchFaqs(query);
            } else {
              renderSuggestions(suggestionsBox, input, [], {includeFaqs: false});
            }
          }, actionPanel ? 300 : 0);
        }, 3000);
      } else {
        window.clearTimeout(clearActiveActionTypingTimer);
      }
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

    window.addEventListener("message", event => {
      const data = event.data || {};
      if (data.customer_id !== customerId) return;
      if (data.type === "vani:close-chat") {
        if (box.style.display === "flex") closeChat();
        return;
      }
      if (data.type !== "vani:request-frame-state") return;
      if (isEnabled(config.chat_open_by_default) && box.style.display !== "flex") {
        openChat();
        return;
      }
      notifyFrameState(box.style.display === "flex" || isEnabled(config.chat_open_by_default));
    });

    if (isEnabled(config.chat_open_by_default) || forceOpenHint) {
      openChat();
      window.setTimeout(() => {
        openChat();
      }, 250);
      window.setTimeout(() => notifyFrameState(true), 900);
    }

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
