# Redis/BullMQ Crawler Queue Setup

This adds a true external queue without replacing the Supabase tables. Supabase remains the source of truth for scan jobs, pages, FAQs, diagnostics, and locks. Redis/BullMQ only schedules background work.

## Services

Use these services:

1. PHP web service
   - Existing dashboard/app.
   - Creates scan jobs and sends them to the queue.

2. Node queue worker service
   - Runs `npm run queue:worker`.
   - Owns BullMQ and Redis.
   - Calls the PHP `AI_Scan_Worker.php` endpoint using `AI_WORKER_TOKEN`.

3. Redis
   - Recommended: Upstash Redis or Render Redis.

4. Optional Node Playwright render service
   - Can remain separate with `npm run render:server`.

## Node Queue Worker

Render service settings:

```text
Runtime: Node
Build Command: npm install
Start Command: npm run queue:worker
```

Environment:

```env
REDIS_URL=rediss://default:YOUR_PASSWORD@YOUR_REDIS_HOST:6379
AI_PHP_BASE_URL=https://your-php-service.onrender.com
AI_WORKER_TOKEN=the-same-worker-token-used-by-php
AI_QUEUE_TOKEN=another-long-random-secret
AI_QUEUE_NAME=vani-ai-crawler
AI_QUEUE_SCAN_CONCURRENCY=2
AI_QUEUE_SUMMARY_CONCURRENCY=2
AI_QUEUE_JOB_ATTEMPTS=3
AI_QUEUE_BACKOFF_MS=5000
AI_QUEUE_MAX_SCAN_LOOPS=200
AI_QUEUE_MAX_SUMMARY_LOOPS=300
```

Health check:

```text
https://your-node-queue-service.onrender.com/health
```

## PHP Service

Set these on the PHP Render service:

```env
AI_QUEUE_ENDPOINT=https://your-node-queue-service.onrender.com
AI_QUEUE_TOKEN=the-same-queue-token-used-by-node
AI_WORKER_TOKEN=the-same-worker-token-used-by-node
```

When `AI_QUEUE_ENDPOINT` is configured, the setup page sends new scan jobs to BullMQ. The review page still polls live status, but it does not auto-run crawl batches in the browser.

If the queue is unavailable, the existing Supabase/browser worker fallback still works.

## Flow

1. Customer submits website in `AI_Chatbot_Setup.php`.
2. PHP creates the scan job and seeds URLs in Supabase.
3. PHP POSTs `{ scan_id, type: "scan" }` to the Node queue service.
4. BullMQ worker calls `AI_Scan_Worker.php?action=scan_batch`.
5. When crawling completes, BullMQ enqueues a `summary` job.
6. Summary worker calls `AI_Scan_Worker.php?action=summarize_batch`.
7. `AI_Summarize.php` polls live status and updates progress, diagnostics, pages, and FAQs.

## Notes

- Keep `AI_WORKER_TOKEN` private.
- Keep `AI_QUEUE_TOKEN` private.
- Upstash Redis free tier is fine for early testing.
- If Render free sleeps, use UptimeRobot on the queue worker `/health` endpoint.
- Start with low concurrency on Render free. Increase only after testing memory and timeouts.
