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
    width: "66px",
    height: "74px",
    maxWidth: "100vw",
    maxHeight: "100dvh",
    border: "0",
    background: "transparent",
    colorScheme: "normal",
    zIndex: "2147483647",
    transition: "width .28s cubic-bezier(.2,.8,.2,1), height .28s cubic-bezier(.2,.8,.2,1)"
  });

  let frameState = {open: false, default_open: false, position: "right"};

  function viewportKeyboardOffset() {
    const viewport = window.visualViewport;
    if (!viewport) return 0;

    return Math.max(0, Math.round(window.innerHeight - viewport.height - viewport.offsetTop));
  }

  function availableViewportHeight() {
    const viewport = window.visualViewport;
    return Math.max(0, Math.round(viewport?.height || window.innerHeight || document.documentElement.clientHeight || 0));
  }

  function applyViewportPlacement() {
    const keyboardOffset = viewportKeyboardOffset();
    const open = frameState.open || frameState.default_open;
    const availableHeight = availableViewportHeight();

    iframe.style.bottom = `${keyboardOffset}px`;

    if (open) {
      iframe.style.width = "min(410px, 100vw)";
      iframe.style.height = window.matchMedia("(max-width: 640px)").matches
        ? `${Math.max(240, Math.min(536, availableHeight - 96))}px`
        : `${Math.max(360, Math.min(610, availableHeight))}px`;
    } else {
      iframe.style.width = "66px";
      iframe.style.height = "74px";
    }
  }

  function applyFrameState(state) {
    frameState = {...frameState, ...state};
    const position = state.position === "left" ? "left" : "right";
    iframe.style.left = position === "left" ? "0" : "auto";
    iframe.style.right = position === "right" ? "0" : "auto";
    applyViewportPlacement();
  }

  window.addEventListener("resize", applyViewportPlacement);
  window.visualViewport?.addEventListener("resize", applyViewportPlacement);
  window.visualViewport?.addEventListener("scroll", applyViewportPlacement);

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
