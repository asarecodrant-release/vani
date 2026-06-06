const http = require("http");
const { chromium } = require("playwright");

const host = process.env.AI_RENDER_HOST || "0.0.0.0";
const port = Number(process.env.PORT || process.env.AI_RENDER_PORT || 8787);
const token = process.env.AI_RENDER_TOKEN || "";
const maxHtmlBytes = Number(process.env.AI_RENDER_MAX_HTML_BYTES || 1500000);

let browserPromise;
let renderedCount = 0;

function json(res, status, payload) {
  res.writeHead(status, { "Content-Type": "application/json" });
  res.end(JSON.stringify(payload));
}

async function readBody(req) {
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  return Buffer.concat(chunks).toString("utf8");
}

async function browser() {
  if (!browserPromise) {
    browserPromise = chromium.launch({ headless: true });
  }
  return browserPromise;
}

const server = http.createServer(async (req, res) => {
  if (req.method === "GET" && (req.url === "/" || req.url === "/health")) {
    return json(res, 200, {
      ok: true,
      service: "vani-playwright-render",
      rendered_count: renderedCount,
      uptime_seconds: Math.round(process.uptime()),
    });
  }

  if (req.method !== "POST") return json(res, 405, { error: "POST required" });
  if (token && req.headers["x-render-token"] !== token) return json(res, 401, { error: "Invalid token" });

  try {
    const payload = JSON.parse(await readBody(req) || "{}");
    const url = String(payload.url || "");
    if (!/^https?:\/\//i.test(url)) return json(res, 400, { error: "Valid URL required" });

    const timeout = Math.max(3000, Math.min(30000, Number(payload.timeout_ms || 12000)));
    const b = await browser();
    const page = await b.newPage({
      userAgent: "VaniAI-Scanner/1.0",
      viewport: { width: 1365, height: 900 },
    });
    await page.goto(url, { waitUntil: "networkidle", timeout });
    await page.waitForTimeout(600);
    const html = (await page.content()).slice(0, maxHtmlBytes);
    const finalUrl = page.url();
    await page.close();
    renderedCount += 1;
    json(res, 200, { html, final_url: finalUrl || url });
  } catch (error) {
    json(res, 500, { error: error.message || "Render failed" });
  }
});

server.listen(port, host, () => {
  console.log(`Vani Playwright render service listening on http://${host}:${port}`);
});
