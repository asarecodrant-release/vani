# Render Crawler Setup

## Services

Use two Render services:

1. PHP web service
   - Runs the Vani app and PHP crawler.
   - Calls the Node render service only when a page looks JavaScript-heavy.

2. Node web service
   - Runs `playwright-render-server.js`.
   - Renders JS-heavy pages with Chromium.

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
AI_RENDER_TIMEOUT_MS=12000
AI_RENDER_MIN_TEXT_LENGTH=600
AI_WORKER_TOKEN=another-long-random-secret
AI_CRAWL_BATCH_SIZE=8
AI_CRAWL_CONCURRENCY=8
AI_CRAWL_MAX_BATCH_SIZE=12
AI_CRAWL_BATCH_DELAY_MS=100
```

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
