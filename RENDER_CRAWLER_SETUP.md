# Render Crawler Setup

## Services

Use two Render services:

1. PHP web service
   - Runs the Vani app and PHP crawler.
   - Calls the Node render service only when a page looks JavaScript-heavy.

2. Node web service
   - Runs `playwright-render-server.js`.
   - Renders JS-heavy pages with Chromium.

Optional third service:

3. Node Redis/BullMQ crawler queue
   - Runs `crawler-queue-worker.js`.
   - Moves scan and summarization work out of browser page requests.
   - See `REDIS_QUEUE_SETUP.md`.

## Node Render Service

Build command:

```bash
npm install && npx playwright install chromium
```

Start command:

```bash
npm run render:server
```

Environment variables:

```env
AI_RENDER_TOKEN=use-a-long-random-secret
AI_RENDER_HOST=0.0.0.0
AI_RENDER_MAX_HTML_BYTES=1500000
```

Render provides `PORT` automatically. The server uses `PORT` first.

Health URL for UptimeRobot:

```text
https://your-node-render-service.onrender.com/health
```

Render request endpoint:

```text
POST https://your-node-render-service.onrender.com/
Header: X-Render-Token: your-secret
Body: {"url":"https://example.com","timeout_ms":12000}
```

## PHP Service Env

Set these on the PHP Render service:

```env
AI_RENDER_ENDPOINT=https://your-node-render-service.onrender.com/
AI_RENDER_TOKEN=use-the-same-long-random-secret
AI_RENDER_TIMEOUT_MS=8000
AI_RENDER_MIN_TEXT_LENGTH=600
AI_RENDER_MAX_PER_REQUEST=2
AI_RENDER_MAX_PRIORITY=35
AI_WORKER_TOKEN=another-long-random-secret
AI_CRAWL_BATCH_SIZE=4
AI_CRAWL_CONCURRENCY=4
AI_CRAWL_MAX_BATCH_SIZE=12
AI_CRAWL_BATCH_DELAY_MS=100
AI_CRAWL_MAX_URL_LENGTH=220
AI_CRAWL_MAX_PATH_DEPTH=5
AI_CRAWL_MAX_LINKS_PER_PAGE=8
AI_HTTP_CONNECT_TIMEOUT=6
AI_HTTP_PAGE_TIMEOUT=14
```

Optional URL filtering overrides:

```env
# Comma or newline separated. Strings or regex patterns are accepted.
AI_CRAWL_BLOCK_PATTERNS=/\/search\b/i,/\/listings\b/i,?checkin=
AI_CRAWL_ALLOW_PATTERNS=/\/important-custom-page\b/i,/\/docs\b/i
```

Default filtering already blocks common crawler traps:

- search/filter/sort/pagination URLs
- marketplace/listing/search result URLs
- login/cart/checkout/account URLs
- tracking query params
- deep paths and long ID-like paths

## UptimeRobot

Create monitors for:

```text
https://your-php-service.onrender.com/
https://your-node-render-service.onrender.com/health
https://your-php-service.onrender.com/AI_Cron_Worker.php?worker_token=YOUR_AI_WORKER_TOKEN
```

The cron worker URL actively processes queued crawler jobs.

## Notes

- Playwright/Chromium can be memory-heavy on Render free.
- If the Node service crashes, the PHP crawler still works for normal HTML pages.
- If JS-heavy pages return very low text, check that `AI_RENDER_ENDPOINT` and `AI_RENDER_TOKEN` match.
