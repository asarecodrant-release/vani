const http = require("http");
const { Queue, Worker, QueueEvents } = require("bullmq");
const IORedis = require("ioredis");

const host = process.env.AI_QUEUE_HOST || "0.0.0.0";
const port = Number(process.env.PORT || process.env.AI_QUEUE_PORT || 8790);
const redisUrl = process.env.REDIS_URL || process.env.UPSTASH_REDIS_URL || "";
const queueToken = process.env.AI_QUEUE_TOKEN || "";
const phpBaseUrl = (process.env.AI_PHP_BASE_URL || "").replace(/\/+$/, "");
const workerToken = process.env.AI_WORKER_TOKEN || "";
const queueName = process.env.AI_QUEUE_NAME || "vani-ai-crawler";
const scanConcurrency = Number(process.env.AI_QUEUE_SCAN_CONCURRENCY || 2);
const summaryConcurrency = Number(process.env.AI_QUEUE_SUMMARY_CONCURRENCY || 2);
const workerConcurrency = Math.max(1, scanConcurrency, summaryConcurrency);
const maxScanLoops = Number(process.env.AI_QUEUE_MAX_SCAN_LOOPS || 200);
const maxSummaryLoops = Number(process.env.AI_QUEUE_MAX_SUMMARY_LOOPS || 300);

if (!redisUrl) throw new Error("REDIS_URL or UPSTASH_REDIS_URL is required.");
if (!phpBaseUrl) throw new Error("AI_PHP_BASE_URL is required.");
if (!workerToken) throw new Error("AI_WORKER_TOKEN is required.");

const connection = new IORedis(redisUrl, {
  maxRetriesPerRequest: null,
  enableReadyCheck: false,
});

const queue = new Queue(queueName, {
  connection,
  defaultJobOptions: {
    attempts: Number(process.env.AI_QUEUE_JOB_ATTEMPTS || 3),
    backoff: { type: "exponential", delay: Number(process.env.AI_QUEUE_BACKOFF_MS || 5000) },
    removeOnComplete: { age: 86400, count: 1000 },
    removeOnFail: { age: 604800, count: 1000 },
  },
});

const events = new QueueEvents(queueName, { connection });
let processedCount = 0;

function json(res, status, payload) {
  res.writeHead(status, { "Content-Type": "application/json" });
  res.end(JSON.stringify(payload));
}

async function readBody(req) {
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  return Buffer.concat(chunks).toString("utf8");
}

async function callPhpWorker(action, scanId, extra = {}) {
  const form = new URLSearchParams();
  form.set("action", action);
  form.set("scan", scanId);
  form.set("worker_token", workerToken);
  Object.entries(extra).forEach(([key, value]) => form.set(key, String(value)));

  const response = await fetch(`${phpBaseUrl}/AI_Scan_Worker.php`, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: form,
  });
  const text = await response.text();
  let data;
  try {
    data = JSON.parse(text);
  } catch (error) {
    throw new Error(`PHP worker returned non-JSON (${response.status}): ${text.slice(0, 300)}`);
  }
  if (!response.ok || data.success === false) {
    throw new Error(data.error || `PHP worker failed with ${response.status}`);
  }
  return data;
}

async function enqueueScan(scanId, priority = 5) {
  return queue.add(
    "scan",
    { scanId },
    { jobId: `scan:${scanId}`, priority, delay: Number(process.env.AI_QUEUE_SCAN_DELAY_MS || 0) }
  );
}

async function enqueueSummary(scanId, priority = 10) {
  return queue.add(
    "summary",
    { scanId },
    { jobId: `summary:${scanId}`, priority, delay: Number(process.env.AI_QUEUE_SUMMARY_DELAY_MS || 0) }
  );
}

async function processScanJob(job) {
  const scanId = String(job.data.scanId || "");
  if (!scanId) throw new Error("scanId is required.");

  let latest;
  for (let i = 0; i < maxScanLoops; i += 1) {
    latest = await callPhpWorker("scan_batch", scanId);
    processedCount += Number(latest.processed || 0);
    await job.updateProgress({
      phase: "crawl",
      loop: i + 1,
      processed: latest.processed || 0,
      counts: latest.counts || {},
      status: latest.scan?.status || latest.status || "",
    });

    const status = latest.scan?.status || latest.status || "";
    if (status === "completed" || status === "failed") break;
    if (Number(latest.processed || 0) === 0 && !latest.waiting_for_retry) break;
  }

  if (latest?.scan?.status === "completed" || latest?.status === "completed") {
    await enqueueSummary(scanId);
  }
  return latest || { success: true };
}

async function processSummaryJob(job) {
  const scanId = String(job.data.scanId || "");
  if (!scanId) throw new Error("scanId is required.");

  let latest;
  for (let i = 0; i < maxSummaryLoops; i += 1) {
    latest = await callPhpWorker("summarize_batch", scanId);
    processedCount += Number(latest.summarized || 0);
    await job.updateProgress({
      phase: "summary",
      loop: i + 1,
      summarized: latest.summarized || 0,
      failed: latest.failed || 0,
      deferred: latest.deferred || 0,
      counts: latest.counts || {},
    });
    if (!latest.remaining) break;
  }
  return latest || { success: true };
}

const scanWorker = new Worker(queueName, async (job) => {
  if (job.name === "scan") return processScanJob(job);
  if (job.name === "summary") return processSummaryJob(job);
  throw new Error(`Unknown job type: ${job.name}`);
}, { connection, concurrency: workerConcurrency });

scanWorker.on("failed", (job, error) => {
  console.error(`queue job failed ${job?.name || ""} ${job?.id || ""}: ${error.message}`);
});

events.on("completed", ({ jobId }) => console.log(`queue job completed ${jobId}`));
events.on("failed", ({ jobId, failedReason }) => console.error(`queue job failed ${jobId}: ${failedReason}`));

const server = http.createServer(async (req, res) => {
  if (req.method === "GET" && (req.url === "/" || req.url === "/health")) {
    const counts = await queue.getJobCounts("waiting", "active", "delayed", "completed", "failed");
    return json(res, 200, {
      ok: true,
      service: "vani-crawler-queue",
      queue: queueName,
      counts,
      processed_count: processedCount,
      uptime_seconds: Math.round(process.uptime()),
    });
  }

  if (req.method !== "POST" || req.url !== "/enqueue") {
    return json(res, 404, { error: "Not found" });
  }
  if (queueToken && req.headers["x-queue-token"] !== queueToken) {
    return json(res, 401, { error: "Invalid queue token" });
  }

  try {
    const payload = JSON.parse(await readBody(req) || "{}");
    const scanId = String(payload.scan_id || payload.scanId || "");
    const type = String(payload.type || "scan");
    const priority = Number(payload.priority || (type === "summary" ? 10 : 5));
    if (!scanId) return json(res, 400, { error: "scan_id is required" });
    const job = type === "summary" ? await enqueueSummary(scanId, priority) : await enqueueScan(scanId, priority);
    return json(res, 200, { success: true, job_id: job.id, type });
  } catch (error) {
    return json(res, 500, { success: false, error: error.message || "Queue enqueue failed" });
  }
});

async function shutdown() {
  await Promise.allSettled([
    new Promise((resolve) => server.close(resolve)),
    scanWorker.close(),
    events.close(),
    queue.close(),
    connection.quit(),
  ]);
  process.exit(0);
}

process.on("SIGTERM", shutdown);
process.on("SIGINT", shutdown);

server.listen(port, host, () => {
  console.log(`Vani crawler queue listening on http://${host}:${port}`);
});
