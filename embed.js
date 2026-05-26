(function () {
  const script = document.currentScript || document.querySelector("script[src*='embed.js']");
  const customerId = script?.getAttribute("data-id") ||
    script?.getAttribute("data-key") ||
    script?.getAttribute("data-customer-id");
  const openByDefaultHint = script?.getAttribute("data-open-default") === "1" ||
    script?.getAttribute("data-open") === "1";

  if (!customerId) {
    console.error("Vani embed: missing data-id");
    return;
  }

  const scriptUrl = new URL(script.src, window.location.href);
  const frameUrl = new URL("widget-frame.php", scriptUrl);
  frameUrl.searchParams.set("id", customerId);
  frameUrl.searchParams.set("source_url", window.location.href);
  if (openByDefaultHint) {
    frameUrl.searchParams.set("open_hint", "1");
  }

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
    width: openByDefaultHint ? "min(400px, 100vw)" : "340px",
    height: openByDefaultHint ? "min(610px, 100dvh)" : "106px",
    maxWidth: "100vw",
    maxHeight: "100dvh",
    border: "0",
    background: "transparent",
    colorScheme: "normal",
    zIndex: "2147483647",
    transition: "width .28s cubic-bezier(.2,.8,.2,1), height .28s cubic-bezier(.2,.8,.2,1)"
  });

  let frameState = {open: openByDefaultHint, default_open: openByDefaultHint, position: "right"};
  let razorpayLoadPromise = null;
  let openHintLoaded = openByDefaultHint;

  function loadRazorpayCheckout() {
    if (window.Razorpay) return Promise.resolve(true);
    if (razorpayLoadPromise) return razorpayLoadPromise;
    razorpayLoadPromise = new Promise(resolve => {
      const checkoutScript = document.createElement("script");
      checkoutScript.src = "https://checkout.razorpay.com/v1/checkout.js";
      checkoutScript.async = true;
      checkoutScript.onload = () => resolve(!!window.Razorpay);
      checkoutScript.onerror = () => resolve(false);
      document.head.appendChild(checkoutScript);
    });
    return razorpayLoadPromise;
  }

  function sendRazorpayResult(requestId, payload) {
    iframe.contentWindow?.postMessage({
      type: "vani:razorpay-result",
      customer_id: customerId,
      request_id: requestId,
      ...payload
    }, scriptUrl.origin);
  }

  function applyFrameState(state = frameState) {
    frameState = {...frameState, ...state};
    const position = state.position === "left" ? "left" : "right";
    iframe.style.left = position === "left" ? "0" : "auto";
    iframe.style.right = position === "right" ? "0" : "auto";

    if (frameState.open || frameState.default_open) {
      iframe.style.bottom = "0";
      iframe.style.width = "min(400px, 100vw)";
      iframe.style.height = "min(610px, 100dvh)";
    } else {
      iframe.style.bottom = "0";
      iframe.style.width = "min(340px, 100vw)";
      iframe.style.height = "106px";
    }
  }

  function requestOpenHint() {
    if (openHintLoaded) return;
    openHintLoaded = true;
    const hintUrl = new URL("widget_api.php", scriptUrl);
    hintUrl.searchParams.set("action", "get_open_hint");
    hintUrl.searchParams.set("customer_id", customerId);
    hintUrl.searchParams.set("current_url", window.location.href);
    fetch(hintUrl.toString(), {headers: {"Accept": "application/json"}})
      .then(response => response.ok ? response.json() : null)
      .then(data => {
        if (!data?.success || !data.open) return;
        applyFrameState({
          open: true,
          default_open: true,
          position: data.position === "left" ? "left" : "right"
        });
      })
      .catch(() => {});
  }

  requestOpenHint();

  function requestChatClose() {
    iframe.contentWindow?.postMessage({
      type: "vani:close-chat",
      customer_id: customerId
    }, scriptUrl.origin);
  }

  document.addEventListener("pointerdown", (event) => {
    if (!(frameState.open || frameState.default_open)) return;
    if (event.target === iframe) return;
    requestChatClose();
  }, true);

  window.addEventListener("message", (event) => {
    if (event.origin !== scriptUrl.origin) return;
    const data = event.data || {};
    if (data.type === "vani:open-razorpay" && data.customer_id === customerId) {
      const requestId = data.request_id || "";
      loadRazorpayCheckout().then(loaded => {
        if (!loaded || !window.Razorpay) {
          sendRazorpayResult(requestId, {success: false, message: "Razorpay checkout could not be loaded on this website."});
          return;
        }
        try {
          let settled = false;
          let checkout = null;
          const closeCheckout = () => {
            try {
              if (checkout && typeof checkout.close === "function") checkout.close();
            } catch (error) {}
          };
          checkout = new Razorpay({
            ...(data.options || {}),
            handler: response => {
              settled = true;
              closeCheckout();
              window.setTimeout(() => sendRazorpayResult(requestId, {success: true, response}), 300);
            },
            modal: {ondismiss: () => {
              if (settled) return;
              settled = true;
              sendRazorpayResult(requestId, {success: false, dismissed: true, message: "Payment window closed before completion."});
            }}
          });
          if (typeof checkout.on === "function") {
            checkout.on("payment.failed", response => {
              settled = true;
              closeCheckout();
              window.setTimeout(() => {
                sendRazorpayResult(requestId, {
                  success: false,
                  message: response?.error?.description || "Payment failed in Razorpay checkout.",
                  error: response?.error || null
                });
              }, 300);
            });
          }
          checkout.open();
        } catch (error) {
          sendRazorpayResult(requestId, {success: false, message: error?.message || "Razorpay checkout could not be opened."});
        }
      });
      return;
    }
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
