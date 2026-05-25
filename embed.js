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
  const configUrl = new URL("widget_api.php", scriptUrl);
  configUrl.searchParams.set("action", "get_widget_config");
  configUrl.searchParams.set("customer_id", customerId);
  configUrl.searchParams.set("current_url", window.location.href);

  const iframe = document.createElement("iframe");
  iframe.title = "Vani AI chatbot";
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
    width: "360px",
    height: "132px",
    maxWidth: "100vw",
    maxHeight: "100dvh",
    border: "0",
    background: "transparent",
    colorScheme: "normal",
    zIndex: "2147483647"
  });

  function applyFrameState(state) {
    const position = state.position === "left" ? "left" : "right";
    iframe.style.left = position === "left" ? "0" : "auto";
    iframe.style.right = position === "right" ? "0" : "auto";

    if (state.open || state.default_open) {
      iframe.style.width = "min(410px, 100vw)";
      iframe.style.height = "min(660px, 100dvh)";
    } else {
      iframe.style.width = "min(360px, 100vw)";
      iframe.style.height = "132px";
    }
  }

  async function loadInitialConfig() {
    try {
      const response = await fetch(configUrl.toString(), {cache: "no-store"});
      const config = await response.json();
      if (config?.chat_open_by_default) {
        frameUrl.searchParams.set("open", "1");
        applyFrameState({
          open: true,
          default_open: true,
          position: config.position
        });
      }
    } catch (error) {}
    iframe.src = frameUrl.toString();
  }

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
    loadInitialConfig();
    window.setTimeout(requestFrameState, 500);
  }

  if (document.body) {
    mount();
  } else {
    document.addEventListener("DOMContentLoaded", mount, {once: true});
  }
})();
