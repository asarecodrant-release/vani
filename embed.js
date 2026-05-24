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
  iframe.loading = "lazy";
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

    if (state.open) {
      iframe.style.width = "min(410px, 100vw)";
      iframe.style.height = "min(660px, 100dvh)";
    } else {
      iframe.style.width = "min(360px, 100vw)";
      iframe.style.height = "132px";
    }
  }

  window.addEventListener("message", (event) => {
    if (event.origin !== scriptUrl.origin) return;
    const data = event.data || {};
    if (data.type !== "vani:frame-state" || data.customer_id !== customerId) return;
    applyFrameState(data);
  });

  function mount() {
    if (!document.body) return;
    document.body.appendChild(iframe);
  }

  if (document.body) {
    mount();
  } else {
    document.addEventListener("DOMContentLoaded", mount, {once: true});
  }
})();
