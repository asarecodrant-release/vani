(function () {
  const script = document.currentScript || document.querySelector("script[src*='embed.js']");
  const customerId = script?.getAttribute("data-id") ||
    script?.getAttribute("data-key") ||
    script?.getAttribute("data-customer-id");

  if (!customerId) {
    console.error("Vani embed: missing data-id");
    return;
  }

  const scriptUrl = new URL(script.src, window.location.href);
  const frameUrl = new URL("widget-frame.php", scriptUrl);
  frameUrl.searchParams.set("id", customerId);
  frameUrl.searchParams.set("source_url", window.location.href);

  const iframe = document.createElement("iframe");
  iframe.title = "Vani AI chatbot";
  iframe.src = frameUrl.toString();
  iframe.loading = "eager";
  iframe.allow = "clipboard-write; geolocation";
  iframe.sandbox = [
    "allow-scripts",
    "allow-forms",
    "allow-popups",
    "allow-popups-to-escape-sandbox",
    "allow-same-origin"
  ].join(" ");
  iframe.setAttribute("data-vani-chatbot-frame", customerId);

  Object.assign(iframe.style, {
    position: "fixed",
    right: "0",
    bottom: "0",
    width: "340px",
    height: "106px",
    maxWidth: "100vw",
    maxHeight: "100dvh",
    border: "0",
    background: "transparent",
    colorScheme: "normal",
    zIndex: "2147483647",
    transition: "width .28s cubic-bezier(.2,.8,.2,1), height .28s cubic-bezier(.2,.8,.2,1)"
  });

  let frameState = {open: false, default_open: false, position: "right"};

  function keyboardOffset() {
    const viewport = window.visualViewport;
    if (!viewport) return 0;

    return Math.max(0, Math.round(window.innerHeight - viewport.height - viewport.offsetTop));
  }

  function viewportHeight() {
    const viewport = window.visualViewport;
    return Math.max(0, Math.round(viewport?.height || window.innerHeight || document.documentElement.clientHeight || 0));
  }

  function openFrameHeight() {
    const availableHeight = viewportHeight();
    const compact = window.matchMedia("(max-width: 768px)").matches;
    const hasKeyboard = compact && keyboardOffset() > 0;
    const reservedSpace = hasKeyboard ? 16 : 118;
    const minimumHeight = compact ? 360 : 360;

    return `${Math.max(minimumHeight, Math.min(520, availableHeight - reservedSpace))}px`;
  }

  function applyFrameState(state = frameState) {
    frameState = {...frameState, ...state};
    const position = state.position === "left" ? "left" : "right";
    iframe.style.left = position === "left" ? "0" : "auto";
    iframe.style.right = position === "right" ? "0" : "auto";

    if (frameState.open || frameState.default_open) {
      const compact = window.matchMedia("(max-width: 768px)").matches;
      const offset = keyboardOffset();
      iframe.style.bottom = `${compact && offset > 0 ? offset + 8 : 90}px`;
      iframe.style.width = "min(360px, calc(100vw - 28px))";
      iframe.style.height = openFrameHeight();
    } else {
      iframe.style.bottom = "0";
      iframe.style.width = "min(340px, 100vw)";
      iframe.style.height = "106px";
    }
  }

  window.addEventListener("resize", () => applyFrameState());
  window.visualViewport?.addEventListener("resize", () => applyFrameState());
  window.visualViewport?.addEventListener("scroll", () => applyFrameState());

  window.addEventListener("message", (event) => {
    if (event.origin !== scriptUrl.origin) return;
    const data = event.data || {};
    if (data.type !== "vani:frame-state" || data.customer_id !== customerId) return;
    applyFrameState(data);
  });

  function requestFrameState() {
    iframe.contentWindow?.postMessage({
      type: "vani:request-frame-state",
      customer_id: customerId
    }, scriptUrl.origin);
  }

  iframe.addEventListener("load", () => {
    requestFrameState();
    window.setTimeout(requestFrameState, 300);
    window.setTimeout(requestFrameState, 1000);
  });

  function mount() {
    if (!document.body) return;
    document.body.appendChild(iframe);
    window.setTimeout(requestFrameState, 500);
  }

  if (document.body) {
    mount();
  } else {
    document.addEventListener("DOMContentLoaded", mount, {once: true});
  }
})();
