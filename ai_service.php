<?php
require_once __DIR__ . '/core.php';

function ai_env(string $key, string $default = ''): string {
    return trim((string)($_ENV[$key] ?? getenv($key) ?: $default));
}

function ai_config(): array {
    $provider = strtolower(ai_env('AI_PROVIDER', 'gemini'));

    return [
        'provider' => $provider,
        'api_key' => ai_env('AI_API_KEY'),
        'model' => ai_env('AI_MODEL', $provider === 'gemini' ? 'gemini-2.5-flash' : ''),
        'base_url' => rtrim(ai_env(
            'AI_BASE_URL',
            $provider === 'gemini'
                ? 'https://generativelanguage.googleapis.com/v1beta'
                : 'https://api.openai.com/v1'
        ), '/'),
        'embedding_model' => ai_env(
            'AI_EMBEDDING_MODEL',
            $provider === 'gemini' ? 'gemini-embedding-001' : 'text-embedding-3-small'
        ),
        'timeout' => max(10, (int)ai_env('AI_TIMEOUT_SECONDS', '60')),
    ];
}

function ai_is_configured(): bool {
    $config = ai_config();
    return $config['api_key'] !== '' && $config['model'] !== '';
}

function ai_safe_rows(array $response): array {
    $data = $response['data'] ?? null;
    return is_array($data) ? $data : [];
}

function ai_supabase_custom(string $method, string $endpoint, $data = null, array $extraHeaders = []): array {
    global $SUPABASE_URL, $SUPABASE_KEY;

    $url = rtrim((string)$SUPABASE_URL, '/') . '/rest/v1/' . $endpoint;
    $headers = array_merge([
        'Content-Type: application/json',
        'apikey: ' . $SUPABASE_KEY,
        'Authorization: Bearer ' . $SUPABASE_KEY,
        'Prefer: return=representation',
    ], $extraHeaders);

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 60,
        ],
    ];
    if ($data !== null) {
        $options['http']['content'] = json_encode($data);
    }

    $response = @file_get_contents($url, false, stream_context_create($options));
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('{HTTP/\S*\s(\d{3})}', $http_response_header[0], $match)) {
        $status = (int)($match[1] ?? 0);
    }

    return [
        'status' => $status,
        'data' => json_decode((string)$response, true),
        'raw' => (string)$response,
    ];
}

function ai_supabase_upsert(string $table, array $rows, string $conflictColumns): array {
    if (empty($rows)) {
        return ['status' => 204, 'data' => [], 'raw' => ''];
    }
    return ai_supabase_custom(
        'POST',
        $table . '?on_conflict=' . urlencode($conflictColumns),
        $rows,
        ['Prefer: return=representation,resolution=merge-duplicates']
    );
}

function ai_supabase_rpc(string $functionName, array $payload = []): array {
    return ai_supabase_custom('POST', 'rpc/' . rawurlencode($functionName), $payload);
}

function ai_now(): string {
    return gmdate('Y-m-d\TH:i:s\Z');
}

function ai_seconds_from_now(int $seconds): string {
    return gmdate('Y-m-d\TH:i:s\Z', time() + $seconds);
}

function ai_db_supports_worker_columns(): bool {
    static $supported = null;
    if ($supported !== null) {
        return $supported;
    }
    $probe = supabase('GET', 'ai_scan_jobs?select=id,worker_id,locked_until&limit=1');
    $supported = ($probe['status'] ?? 0) >= 200 && ($probe['status'] ?? 0) < 300;
    return $supported;
}

function ai_db_supports_retry_columns(): bool {
    static $supported = null;
    if ($supported !== null) {
        return $supported;
    }
    $probe = supabase('GET', 'ai_website_pages?select=id,crawl_attempts,next_retry_at,summary_attempts,summary_next_retry_at&limit=1');
    $supported = ($probe['status'] ?? 0) >= 200 && ($probe['status'] ?? 0) < 300;
    return $supported;
}

function ai_db_supports_advanced_crawler_columns(): bool {
    static $supported = null;
    if ($supported !== null) {
        return $supported;
    }
    $probe = supabase('GET', 'ai_website_pages?select=id,priority,source,page_worker_id,page_locked_until&limit=1');
    $supported = ($probe['status'] ?? 0) >= 200 && ($probe['status'] ?? 0) < 300;
    return $supported;
}

function ai_db_supports_crawl_logs(): bool {
    static $supported = null;
    if ($supported !== null) {
        return $supported;
    }
    $probe = supabase('GET', 'ai_crawl_logs?select=id&limit=1');
    $supported = ($probe['status'] ?? 0) >= 200 && ($probe['status'] ?? 0) < 300;
    return $supported;
}

function ai_db_supports_page_claim_rpc(): bool {
    static $supported = null;
    if ($supported !== null) {
        return $supported;
    }
    $probe = ai_supabase_rpc('ai_claim_pending_pages', [
        'p_scan_job_id' => '00000000-0000-0000-0000-000000000000',
        'p_customer_id' => '00000000-0000-0000-0000-000000000000',
        'p_worker_id' => 'probe',
        'p_limit' => 1,
        'p_lock_seconds' => 1,
    ]);
    $status = (int)($probe['status'] ?? 0);
    $supported = $status >= 200 && $status < 300;
    return $supported;
}

function ai_db_supports_chunks(): bool {
    static $supported = null;
    if ($supported !== null) {
        return $supported;
    }
    $probe = supabase('GET', 'ai_website_chunks?select=id&limit=1');
    $supported = ($probe['status'] ?? 0) >= 200 && ($probe['status'] ?? 0) < 300;
    return $supported;
}

function ai_worker_id(): string {
    static $workerId = '';
    if ($workerId === '') {
        $workerId = gethostname() . '-' . getmypid() . '-' . substr(hash('sha256', random_bytes(16)), 0, 10);
    }
    return $workerId;
}

function ai_crawl_log(string $jobId, string $customerId, string $eventType, string $message = '', array $metadata = [], string $severity = 'info', string $url = '', string $pageId = ''): void {
    if (!ai_db_supports_crawl_logs()) {
        return;
    }
    supabase('POST', 'ai_crawl_logs', [[
        'scan_job_id' => $jobId ?: null,
        'page_id' => $pageId ?: null,
        'customer_id' => $customerId ?: null,
        'event_type' => substr($eventType, 0, 120),
        'severity' => in_array($severity, ['info', 'warning', 'error'], true) ? $severity : 'info',
        'url' => $url,
        'message' => substr($message, 0, 1000),
        'metadata' => $metadata,
    ]]);
}

function ai_strip_www(string $host): string {
    return preg_replace('/^www\./', '', strtolower(trim($host))) ?: '';
}

function ai_host_from_value(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (!preg_match('{^https?://}i', $value)) {
        $value = 'https://' . $value;
    }
    $host = (string)(parse_url($value, PHP_URL_HOST) ?: '');
    return rtrim(ai_strip_www($host), '.');
}

function ai_valid_website_domain(string $value): string {
    $host = ai_host_from_value($value);
    if ($host === '' || strlen($host) > 253 || strpos($host, '.') === false) {
        return '';
    }
    if (!preg_match('/^(?:[a-z0-9-]{1,63}\.)+[a-z]{2,63}$/i', $host)) {
        return '';
    }
    foreach (explode('.', $host) as $label) {
        if ($label === '' || $label[0] === '-' || substr($label, -1) === '-') {
            return '';
        }
    }
    return $host;
}

function ai_normalize_website_input(string $value): array {
    $value = trim($value);
    if ($value === '') {
        return ['success' => false, 'url' => '', 'domain' => '', 'error' => 'Please enter your website URL.'];
    }
    if (!preg_match('{^https?://}i', $value)) {
        $value = 'https://' . $value;
    }
    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'url' => '', 'domain' => '', 'error' => 'Please enter a valid website URL, for example https://example.com.'];
    }
    $parts = parse_url($value);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['success' => false, 'url' => '', 'domain' => '', 'error' => 'Only HTTP and HTTPS website URLs are supported.'];
    }
    $domain = ai_valid_website_domain($value);
    if ($domain === '') {
        return ['success' => false, 'url' => '', 'domain' => '', 'error' => 'Enter a valid website domain, for example example.com or example.co.in.'];
    }
    $path = (string)($parts['path'] ?? '');
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $normalizedUrl = $scheme . '://' . (string)$parts['host'] . ($path !== '' ? $path : '/') . $query;

    return ['success' => true, 'url' => $normalizedUrl, 'domain' => $domain, 'error' => ''];
}

function ai_get_or_create_chatbot_signup(string $email, string $websiteDomain): array {
    $email = strtolower(trim($email));
    $rows = ai_safe_rows(supabase(
        'GET',
        'chatbot_signups?select=customer_id,website_name,business_type&email=eq.' . urlencode($email) . '&order=created_at.desc&limit=1'
    ));

    if (!empty($rows[0]['customer_id'])) {
        $customerId = (string)$rows[0]['customer_id'];
        supabase('PATCH', 'chatbot_signups?customer_id=eq.' . urlencode($customerId), [
            'website_name' => $websiteDomain,
            'business_type' => (string)($rows[0]['business_type'] ?? '') ?: 'AI Website'
        ]);
        return ['success' => true, 'customer_id' => $customerId, 'created' => false, 'error' => ''];
    }

    $customerId = generateUUID();
    $created = supabase('POST', 'chatbot_signups', [[
        'customer_id' => $customerId,
        'website_name' => $websiteDomain,
        'email' => $email,
        'business_type' => 'AI Website',
        'theme_color' => '#007bff'
    ]]);

    if (($created['status'] ?? 0) < 200 || ($created['status'] ?? 0) >= 300) {
        return ['success' => false, 'customer_id' => '', 'created' => false, 'error' => 'Could not save website setup.'];
    }

    supabase('POST', 'customer_bot_type', [[
        'customer_id' => $customerId,
        'bot_type' => 'AI'
    ]]);

    return ['success' => true, 'customer_id' => $customerId, 'created' => true, 'error' => ''];
}

function ai_create_scan_job(string $customerId, string $email, string $websiteUrl, string $websiteDomain, int $pagesRequested): array {
    $config = ai_config();
    $jobId = generateUUID();
    $created = supabase('POST', 'ai_scan_jobs', [[
        'id' => $jobId,
        'customer_id' => $customerId,
        'email' => strtolower(trim($email)),
        'website_url' => $websiteUrl,
        'website_domain' => $websiteDomain,
        'status' => 'pending',
        'provider' => $config['provider'],
        'model' => $config['model'],
        'pages_requested' => $pagesRequested
    ]]);

    if (($created['status'] ?? 0) < 200 || ($created['status'] ?? 0) >= 300) {
        return ['success' => false, 'job_id' => '', 'error' => 'Could not create AI scan job.'];
    }

    return ['success' => true, 'job_id' => $jobId, 'error' => ''];
}

function ai_external_queue_enabled(): bool {
    return ai_env('AI_QUEUE_ENDPOINT') !== '';
}

function ai_queue_endpoint_url(): string {
    $endpoint = rtrim(ai_env('AI_QUEUE_ENDPOINT'), '/');
    if ($endpoint === '') {
        return '';
    }
    if (!preg_match('{^https?://}i', $endpoint)) {
        $endpoint = 'https://' . $endpoint;
    }
    $endpoint = preg_replace('{/(health|enqueue)$}i', '', $endpoint) ?: $endpoint;
    return rtrim($endpoint, '/') . '/enqueue';
}

function ai_enqueue_external_job(string $scanId, string $type = 'scan', int $priority = 5): array {
    $endpoint = ai_queue_endpoint_url();
    if ($endpoint === '') {
        return ['success' => false, 'queued' => false, 'error' => 'External queue is not configured.'];
    }
    $headers = [];
    $token = ai_env('AI_QUEUE_TOKEN');
    if ($token !== '') {
        $headers[] = 'X-Queue-Token: ' . $token;
    }
    $response = ai_http_json($endpoint, $headers, [
        'scan_id' => $scanId,
        'type' => $type,
        'priority' => $priority,
    ], max(3, (int)ai_env('AI_QUEUE_ENQUEUE_TIMEOUT', '8')));

    if (empty($response['ok'])) {
        return [
            'success' => false,
            'queued' => false,
            'error' => ai_response_error($response),
            'endpoint' => $endpoint,
            'raw' => $response,
        ];
    }
    return [
        'success' => true,
        'queued' => true,
        'job_id' => (string)($response['data']['job_id'] ?? ''),
        'raw' => $response,
    ];
}

function ai_patch_scan_job(string $jobId, array $payload): void {
    $payload['updated_at'] = ai_now();
    supabase('PATCH', 'ai_scan_jobs?id=eq.' . urlencode($jobId), $payload);
}

function ai_claim_scan_job(string $jobId, string $customerId, string $workerId, int $ttlSeconds = 90): bool {
    if (!ai_db_supports_worker_columns()) {
        return true;
    }
    $now = ai_now();
    $payload = [
        'worker_id' => $workerId,
        'locked_until' => ai_seconds_from_now($ttlSeconds),
        'updated_at' => $now,
    ];

    $claim = supabase(
        'PATCH',
        'ai_scan_jobs?id=eq.' . urlencode($jobId)
            . '&customer_id=eq.' . urlencode($customerId)
            . '&status=in.(pending,running)'
            . '&or=(locked_until.is.null,locked_until.lt.' . urlencode($now) . ',worker_id.eq.' . urlencode($workerId) . ')',
        $payload
    );
    return !empty(ai_safe_rows($claim));
}

function ai_release_scan_job(string $jobId, string $workerId): void {
    if (!ai_db_supports_worker_columns()) {
        return;
    }
    supabase(
        'PATCH',
        'ai_scan_jobs?id=eq.' . urlencode($jobId) . '&worker_id=eq.' . urlencode($workerId),
        ['worker_id' => null, 'locked_until' => null, 'updated_at' => ai_now()]
    );
}

function ai_extend_scan_job_lock(string $jobId, string $workerId, int $ttlSeconds = 90): void {
    if (!ai_db_supports_worker_columns()) {
        return;
    }
    supabase(
        'PATCH',
        'ai_scan_jobs?id=eq.' . urlencode($jobId) . '&worker_id=eq.' . urlencode($workerId),
        ['locked_until' => ai_seconds_from_now($ttlSeconds), 'updated_at' => ai_now()]
    );
}

function ai_active_scan_jobs(int $limit = 10): array {
    $limit = max(1, min(50, $limit));
    return ai_safe_rows(supabase(
        'GET',
        'ai_scan_jobs?select=*&status=in.(pending,running)&order=created_at.asc&limit=' . $limit
    ));
}

function ai_completed_scan_jobs(int $limit = 10): array {
    $limit = max(1, min(50, $limit));
    return ai_safe_rows(supabase(
        'GET',
        'ai_scan_jobs?select=*&status=eq.completed&order=updated_at.desc&limit=' . $limit
    ));
}

function ai_normalize_page_url(string $url): string {
    $parts = parse_url($url);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }
    $host = ai_strip_www((string)$parts['host']);
    $path = (string)($parts['path'] ?? '/');
    $path = $path === '' ? '/' : $path;
    $query = '';
    if (isset($parts['query'])) {
        parse_str((string)$parts['query'], $queryParts);
        $dropKeys = [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'fbclid', 'gclid', 'yclid', 'msclkid', 'ref', 'source',
            'sort', 'order', 'filter', 'page', 'p', 'offset', 'limit', 'start',
            'checkin', 'checkout', 'adults', 'children', 'infants', 'pets', 'guests',
            'currency', 'locale', 'language', 'q', 'query', 'search', 's'
        ];
        foreach ($dropKeys as $key) {
            unset($queryParts[$key]);
        }
        ksort($queryParts);
        $queryString = http_build_query($queryParts);
        $query = $queryString !== '' ? '?' . $queryString : '';
    }
    return rtrim($scheme . '://' . $host . $path . $query, '/');
}

function ai_url_with_host_variant(string $url, string $host): string {
    $parts = parse_url($url);
    if (empty($parts['scheme'])) {
        return '';
    }
    $path = (string)($parts['path'] ?? '/');
    $path = $path === '' ? '/' : $path;
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return strtolower((string)$parts['scheme']) . '://' . $host . $path . $query;
}

function ai_add_www_variant(string $url): string {
    $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
    if ($host === '' || stripos($host, 'www.') === 0) {
        return '';
    }
    return ai_url_with_host_variant($url, 'www.' . $host);
}

function ai_remove_www_variant(string $url): string {
    $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
    if (stripos($host, 'www.') !== 0) {
        return '';
    }
    return ai_url_with_host_variant($url, substr($host, 4));
}

function ai_env_list(string $key): array {
    $raw = ai_env($key);
    if ($raw === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $raw) ?: [])));
}

function ai_url_matches_any_pattern(string $url, array $patterns): bool {
    $url = strtolower($url);
    foreach ($patterns as $pattern) {
        $pattern = strtolower(trim((string)$pattern));
        if ($pattern === '') {
            continue;
        }
        if (@preg_match($pattern, '') !== false) {
            if (preg_match($pattern, $url)) {
                return true;
            }
            continue;
        }
        if (strpos($url, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

function ai_default_block_url_patterns(): array {
    return [
        '/[?&](?:q|query|search|s|filter|sort|order|page|p|offset|limit|start|checkin|checkout|adults|children|infants|pets|guests|currency|locale|language|utm_[^=]+|fbclid|gclid|yclid|msclkid)=/i',
        '/\/(?:search|find|results|listings|listing|rooms|stays|experiences|marketplace|category|categories|tag|tags|author|archive|feed|rss|cart|basket|checkout|login|signin|signup|register|account|profile|wishlist|wishlists|favorites|booking|book|calendar|availability|trips|hosting|host|admin|wp-admin|wp-json)\b/i',
        '/\/(?:s|users|things-to-do|luxury|plus|giftcards)(?:\/|$)/i',
        '/\/(?:rooms|listings|experiences)\/[a-z0-9-]{4,}/i',
        '/\/(?:page|p)\/\d+\b/i',
        '/\/\d{4}\/\d{2}\//i',
    ];
}

function ai_default_allow_url_patterns(): array {
    return [
        '/\/(?:about|contact|support|help|faq|faqs|pricing|plans|services|service|products|product|features|solutions|terms|privacy|refund|shipping|returns)\b/i',
    ];
}

function ai_domain_block_url_patterns(string $websiteDomain): array {
    $domain = strtolower($websiteDomain);
    if (strpos($domain, 'airbnb.') !== false || $domain === 'airbnb.com') {
        return [
            '/\/s(?:\/|$|\?)/i',
            '/\/rooms\/[0-9a-z-]+/i',
            '/\/experiences\/[0-9a-z-]+/i',
            '/\/users\/show\//i',
            '/\/wishlists?(?:\/|$)/i',
            '/\/trips(?:\/|$)/i',
            '/\/host(?:\/|$)/i',
            '/\/hosting(?:\/|$)/i',
            '/[?&](?:checkin|checkout|adults|children|infants|pets|guests|price_min|price_max|place_id|ne_lat|ne_lng|sw_lat|sw_lng)=/i',
        ];
    }
    return [];
}

function ai_should_scan_url(string $url, string $websiteDomain): bool {
    if ($url === '' || ai_host_from_value($url) !== $websiteDomain) {
        return false;
    }
    if (strlen($url) > (int)ai_env('AI_CRAWL_MAX_URL_LENGTH', '220')) {
        return false;
    }
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
    if (preg_match('/\.(?:jpg|jpeg|png|gif|webp|svg|ico|css|js|zip|rar|7z|mp4|mp3|avi|mov|woff|woff2|ttf|eot)$/i', $path)) {
        return false;
    }

    $allowPatterns = array_merge(ai_default_allow_url_patterns(), ai_env_list('AI_CRAWL_ALLOW_PATTERNS'));
    if (ai_url_matches_any_pattern($url, $allowPatterns)) {
        return true;
    }

    $blockPatterns = array_merge(ai_default_block_url_patterns(), ai_domain_block_url_patterns($websiteDomain), ai_env_list('AI_CRAWL_BLOCK_PATTERNS'));
    if (ai_url_matches_any_pattern($url, $blockPatterns)) {
        return false;
    }

    $segments = array_values(array_filter(explode('/', trim($path, '/'))));
    if (count($segments) > (int)ai_env('AI_CRAWL_MAX_PATH_DEPTH', '5')) {
        return false;
    }
    if (preg_match('/(?:^|\/)[a-f0-9]{16,}(?:\/|$)/i', $path)) {
        return false;
    }
    if (preg_match('/(?:^|\/)\d{5,}(?:\/|$)/', $path) && !ai_url_matches_any_pattern($url, $allowPatterns)) {
        return false;
    }

    return true;
}

function ai_url_priority(string $url): int {
    return ai_page_priority($url);
}

function ai_page_priority(string $url): int {
    $value = strtolower($url);
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: '/'));
    if ($path === '/' || $path === '') {
        return 0;
    }
    if (preg_match('/(faq|faqs|frequently-asked|questions|help|support)/', $value)) {
        return 5;
    }
    if (preg_match('/(contact|pricing|plans|book-demo|demo|quote|consultation)/', $value)) {
        return 10;
    }
    if (preg_match('/(about|service|services|product|products|feature|features|solution|solutions|course|program)/', $value)) {
        return 15;
    }
    if (preg_match('/(privacy|terms|refund|shipping|returns|cancellation)/', $value)) {
        return 35;
    }
    if (preg_match('/\/(?:blog|news|article)\b/', $value)) {
        return 70;
    }
    if (preg_match('/\/(?:tag|category|author|archive|page\/\d+|search|listings|rooms|stays|experiences)\b/', $value)) {
        return 80;
    }
    return 50;
}

function ai_page_source_from_url(string $url, string $default = 'crawl'): string {
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
    if (preg_match('/\.(pdf|doc|docx|txt|csv)$/i', $path)) {
        return 'document';
    }
    return $default;
}

function ai_detect_js_challenge_html(string $html): bool {
    $sample = strtolower(substr($html, 0, 5000));
    return strpos($sample, 'document.cookie') !== false && strpos($sample, 'location.href') !== false
        || strpos($sample, 'requires javascript') !== false
        || strpos($sample, 'cf-browser-verification') !== false
        || strpos($sample, 'cloudflare') !== false && strpos($sample, 'challenge') !== false
        || strpos($sample, '/aes.js') !== false;
}

function ai_diagnostic_label(array $row): string {
    $error = strtolower((string)($row['ai_error'] ?? ''));
    $status = (int)($row['http_status'] ?? 0);
    $url = strtolower((string)($row['url'] ?? ''));
    $textLength = strlen(trim((string)($row['clean_text'] ?? '')));
    if (strpos($error, 'robots') !== false) {
        return 'blocked by robots';
    }
    if (strpos($error, 'js challenge') !== false || strpos($error, 'javascript challenge') !== false || strpos($error, 'challenge') !== false) {
        return 'JS challenge';
    }
    if (strpos($error, 'timed out') !== false || strpos($error, 'timeout') !== false || strpos($error, 'operation timed') !== false) {
        return 'timeout';
    }
    if (strpos($error, 'duplicate') !== false || strpos($error, 'unchanged') !== false) {
        return 'duplicate skipped';
    }
    if (strpos($error, 'marketplace') !== false || ai_url_matches_any_pattern($url, ai_default_block_url_patterns())) {
        return 'marketplace/filter URL';
    }
    if ($status === 401 || $status === 403) {
        return 'access denied';
    }
    if ($status === 404) {
        return 'not found';
    }
    if ($status >= 500) {
        return 'server error';
    }
    if ($textLength > 0 && $textLength < 300) {
        return 'very low text';
    }
    if ($textLength >= 300 && $textLength < 900) {
        return 'short text';
    }
    return $error !== '' ? 'needs attention' : 'ok';
}

function ai_add_diagnostic_labels(array $rows): array {
    foreach ($rows as &$row) {
        if (is_array($row)) {
            $row['diagnostic_label'] = ai_diagnostic_label($row);
        }
    }
    unset($row);
    return $rows;
}

function ai_is_document_url_or_type(string $url, string $contentType = ''): bool {
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
    return preg_match('/\.(pdf|doc|docx|txt|csv)$/i', $path) === 1
        || stripos($contentType, 'pdf') !== false
        || stripos($contentType, 'msword') !== false
        || stripos($contentType, 'officedocument') !== false
        || stripos($contentType, 'text/plain') !== false
        || stripos($contentType, 'text/csv') !== false;
}

function ai_extract_document_text_from_body(string $url, string $body, string $contentType): string {
    if ($body === '') {
        return '';
    }
    if (stripos($contentType, 'text/plain') !== false || stripos($contentType, 'text/csv') !== false || preg_match('/\.(txt|csv)$/i', (string)(parse_url($url, PHP_URL_PATH) ?: ''))) {
        return substr(trim(preg_replace('/\s+/', ' ', $body) ?: ''), 0, ai_page_clean_text_limit());
    }

    $endpoint = ai_env('AI_DOCUMENT_EXTRACT_ENDPOINT');
    if ($endpoint !== '') {
        $response = ai_http_json($endpoint, [], [
            'url' => $url,
            'content_type' => $contentType,
            'content_base64' => base64_encode(substr($body, 0, ai_page_html_limit())),
        ], max(20, (int)ai_env('AI_DOCUMENT_EXTRACT_TIMEOUT', '60')));
        $text = is_array($response['data'] ?? null) ? (string)($response['data']['text'] ?? '') : '';
        if ($text !== '') {
            return substr(trim(preg_replace('/\s+/', ' ', $text) ?: ''), 0, ai_page_clean_text_limit());
        }
    }

    if (preg_match('/\.pdf$/i', (string)(parse_url($url, PHP_URL_PATH) ?: '')) || stripos($contentType, 'pdf') !== false) {
        $tool = trim((string)ai_env('AI_PDFTOTEXT_BIN', 'pdftotext'));
        if ($tool !== '') {
            $input = tempnam(sys_get_temp_dir(), 'vani_pdf_');
            $output = tempnam(sys_get_temp_dir(), 'vani_txt_');
            if ($input !== false && $output !== false) {
                file_put_contents($input, $body);
                $command = escapeshellcmd($tool) . ' -layout ' . escapeshellarg($input) . ' ' . escapeshellarg($output);
                @exec($command, $unused, $exitCode);
                $text = is_readable($output) ? (string)file_get_contents($output) : '';
                @unlink($input);
                @unlink($output);
                if ($exitCode === 0 && trim($text) !== '') {
                    return substr(trim(preg_replace('/\s+/', ' ', $text) ?: ''), 0, ai_page_clean_text_limit());
                }
            }
        }
    }

    return '';
}

function ai_resolve_url(string $baseUrl, string $href): string {
    $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
    if ($href === '' || preg_match('/^(mailto|tel|javascript):/i', $href)) {
        return '';
    }
    $href = preg_replace('/#.*$/', '', $href) ?: '';
    if ($href === '') {
        return '';
    }
    if (preg_match('{^https?://}i', $href)) {
        return $href;
    }
    if (strpos($href, '//') === 0) {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $href;
    }

    $base = parse_url($baseUrl);
    if (empty($base['scheme']) || empty($base['host'])) {
        return '';
    }
    $root = $base['scheme'] . '://' . $base['host'];
    if (strpos($href, '/') === 0) {
        return $root . $href;
    }
    $dir = preg_replace('{/[^/]*$}', '/', (string)($base['path'] ?? '/')) ?: '/';
    return $root . $dir . $href;
}

function ai_fetch_page(string $url): array {
    $headers = [
        'User-Agent: VaniAI-Scanner/1.0',
        'Accept: text/html,application/xhtml+xml',
        'Accept-Language: en-US,en;q=0.9'
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $connectTimeout = max(2, (int)ai_env('AI_HTTP_CONNECT_TIMEOUT', '6'));
        $pageTimeout = max(5, (int)ai_env('AI_HTTP_PAGE_TIMEOUT', '14'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $pageTimeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $html = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string)(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url);
        $contentType = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($html === false || $status < 200 || $status >= 400) {
            return ['success' => false, 'status' => $status, 'html' => '', 'url' => $finalUrl, 'content_type' => $contentType, 'content_length' => 0, 'error' => $curlError !== '' ? $curlError : 'Could not fetch page.'];
        }
        if ($contentType !== '' && stripos($contentType, 'html') === false && stripos($contentType, 'text/plain') === false) {
            if (ai_is_document_url_or_type($finalUrl, $contentType)) {
                $documentText = ai_extract_document_text_from_body($finalUrl, (string)$html, $contentType);
                if ($documentText !== '') {
                    return ['success' => true, 'status' => $status, 'html' => $documentText, 'url' => $finalUrl, 'content_type' => $contentType, 'content_length' => strlen((string)$html), 'error' => ''];
                }
            }
            return ['success' => false, 'status' => $status, 'html' => '', 'url' => $finalUrl, 'content_type' => $contentType, 'content_length' => strlen((string)$html), 'error' => 'URL did not return HTML content.'];
        }
        $html = ai_html_with_optional_render($finalUrl, (string)$html);
        return ['success' => true, 'status' => $status, 'html' => substr((string)$html, 0, ai_page_html_limit()), 'url' => $finalUrl, 'content_type' => $contentType, 'content_length' => strlen((string)$html), 'error' => ''];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
            'timeout' => max(5, (int)ai_env('AI_HTTP_PAGE_TIMEOUT', '14')),
            'max_redirects' => 5,
        ],
    ]);
    $html = @file_get_contents($url, false, $context);
    $status = 0;
    $finalUrl = $url;
    if (isset($http_response_header[0]) && preg_match('{HTTP/\S*\s(\d{3})}', $http_response_header[0], $match)) {
        $status = (int)($match[1] ?? 0);
    }
    foreach ($http_response_header ?? [] as $headerLine) {
        if (stripos($headerLine, 'Location:') === 0) {
            $resolved = ai_resolve_url($finalUrl, trim(substr($headerLine, 9)));
            if ($resolved !== '') {
                $finalUrl = $resolved;
            }
        }
    }
    if ($html === false || $status < 200 || $status >= 400) {
        return ['success' => false, 'status' => $status, 'html' => '', 'url' => $finalUrl, 'content_type' => '', 'content_length' => 0, 'error' => 'Could not fetch page.'];
    }
    $html = ai_html_with_optional_render($finalUrl, (string)$html);
    return ['success' => true, 'status' => $status, 'html' => substr((string)$html, 0, ai_page_html_limit()), 'url' => $finalUrl, 'content_type' => '', 'content_length' => strlen((string)$html), 'error' => ''];
}

function ai_html_with_optional_render(string $url, string $html): string {
    $endpoint = ai_env('AI_RENDER_ENDPOINT');
    if ($endpoint === '') {
        return $html;
    }
    static $rendersThisRequest = 0;
    $maxRenders = max(0, (int)ai_env('AI_RENDER_MAX_PER_REQUEST', '2'));
    if ($maxRenders === 0 || $rendersThisRequest >= $maxRenders) {
        return $html;
    }
    $isChallenge = ai_detect_js_challenge_html($html);
    $priorityThreshold = max(0, (int)ai_env('AI_RENDER_MAX_PRIORITY', '35'));
    if (!$isChallenge && ai_page_priority($url) > $priorityThreshold) {
        return $html;
    }
    $textLength = strlen(trim(strip_tags(preg_replace('/<(script|style|noscript|svg)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?: $html)));
    if (!$isChallenge && $textLength >= (int)ai_env('AI_RENDER_MIN_TEXT_LENGTH', '600')) {
        return $html;
    }

    $rendered = ai_render_page_html($url);
    if ($rendered !== '') {
        $rendersThisRequest++;
    }
    return $rendered !== '' ? $rendered : $html;
}

function ai_render_page_html(string $url): string {
    $endpoint = ai_env('AI_RENDER_ENDPOINT');
    if ($endpoint === '') {
        return '';
    }

    $headers = [];
    $renderToken = ai_env('AI_RENDER_TOKEN');
    if ($renderToken !== '') {
        $headers[] = 'X-Render-Token: ' . $renderToken;
    }
    $response = ai_http_json($endpoint, $headers, [
        'url' => $url,
        'timeout_ms' => max(3000, (int)ai_env('AI_RENDER_TIMEOUT_MS', '8000')),
    ], max(5, (int)ceil(((int)ai_env('AI_RENDER_TIMEOUT_MS', '8000')) / 1000) + 3));

    if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 400) {
        return '';
    }
    $data = $response['data'] ?? [];
    if (is_array($data) && isset($data['html'])) {
        return substr((string)$data['html'], 0, ai_page_html_limit());
    }
    return '';
}

function ai_page_html_limit(): int {
    return max(250000, min(3000000, (int)ai_env('AI_PAGE_HTML_LIMIT', '1500000')));
}

function ai_page_clean_text_limit(): int {
    return max(50000, min(500000, (int)ai_env('AI_PAGE_CLEAN_TEXT_LIMIT', '200000')));
}

function ai_fetch_pages_parallel(array $urls, int $concurrency = 4): array {
    $urls = array_values(array_unique(array_filter(array_map('strval', $urls))));
    if (empty($urls)) {
        return [];
    }
    if (!function_exists('curl_multi_init') || count($urls) === 1) {
        $results = [];
        foreach ($urls as $url) {
            $results[$url] = ai_fetch_page($url);
        }
        return $results;
    }

    $concurrency = max(1, min(8, $concurrency));
    $headers = [
        'User-Agent: VaniAI-Scanner/1.0',
        'Accept: text/html,application/xhtml+xml',
        'Accept-Language: en-US,en;q=0.9'
    ];
    $results = [];
    $pending = $urls;
    $active = [];
    $multi = curl_multi_init();

    do {
        while (count($active) < $concurrency && !empty($pending)) {
            $url = array_shift($pending);
            $ch = curl_init($url);
            $connectTimeout = max(2, (int)ai_env('AI_HTTP_CONNECT_TIMEOUT', '6'));
            $pageTimeout = max(5, (int)ai_env('AI_HTTP_PAGE_TIMEOUT', '14'));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT => $pageTimeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            curl_multi_add_handle($multi, $ch);
            $active[(int)$ch] = ['handle' => $ch, 'url' => $url];
        }

        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($multi)) {
            $ch = $info['handle'];
            $key = (int)$ch;
            $sourceUrl = (string)($active[$key]['url'] ?? '');
            $html = curl_multi_getcontent($ch);
            $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $finalUrl = (string)(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $sourceUrl);
            $contentType = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
            $error = curl_error($ch);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            unset($active[$key]);

            if ($html === false || $httpStatus < 200 || $httpStatus >= 400) {
                $results[$sourceUrl] = ['success' => false, 'status' => $httpStatus, 'html' => '', 'url' => $finalUrl, 'content_type' => $contentType, 'content_length' => 0, 'error' => $error !== '' ? $error : 'Could not fetch page.'];
                continue;
            }
            if ($contentType !== '' && stripos($contentType, 'html') === false && stripos($contentType, 'text/plain') === false) {
                if (ai_is_document_url_or_type($finalUrl, $contentType)) {
                    $documentText = ai_extract_document_text_from_body($finalUrl, (string)$html, $contentType);
                    if ($documentText !== '') {
                        $results[$sourceUrl] = ['success' => true, 'status' => $httpStatus, 'html' => $documentText, 'url' => $finalUrl, 'content_type' => $contentType, 'content_length' => strlen((string)$html), 'error' => ''];
                        continue;
                    }
                }
                $results[$sourceUrl] = ['success' => false, 'status' => $httpStatus, 'html' => '', 'url' => $finalUrl, 'content_type' => $contentType, 'content_length' => strlen((string)$html), 'error' => 'URL did not return HTML content.'];
                continue;
            }
            $html = ai_html_with_optional_render($finalUrl, (string)$html);
            $results[$sourceUrl] = ['success' => true, 'status' => $httpStatus, 'html' => substr((string)$html, 0, ai_page_html_limit()), 'url' => $finalUrl, 'content_type' => $contentType, 'content_length' => strlen((string)$html), 'error' => ''];
        }

        if ($running) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running || !empty($pending) || !empty($active));

    curl_multi_close($multi);
    return $results;
}

function ai_fetch_raw_url(string $url): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['User-Agent: VaniAI-Scanner/1.0'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $body = (string)$body;
        if (substr($body, 0, 2) === "\x1f\x8b" || preg_match('/\.gz(?:$|\?)/i', $url)) {
            $decoded = @gzdecode($body);
            if ($decoded !== false) {
                $body = $decoded;
            }
        }
        return ['success' => $body !== '' && $status >= 200 && $status < 400, 'status' => $status, 'body' => $body];
    }

    $context = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true, 'header' => "User-Agent: VaniAI-Scanner/1.0\r\n"]]);
    $body = @file_get_contents($url, false, $context);
    $body = (string)$body;
    if (substr($body, 0, 2) === "\x1f\x8b" || preg_match('/\.gz(?:$|\?)/i', $url)) {
        $decoded = @gzdecode($body);
        if ($decoded !== false) {
            $body = $decoded;
        }
    }
    return ['success' => $body !== '', 'status' => $body !== '' ? 200 : 0, 'body' => $body];
}

function ai_robots_rules(string $websiteUrl): array {
    static $cache = [];
    $parts = parse_url($websiteUrl);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return ['disallow' => [], 'crawl_delay' => 0, 'sitemaps' => []];
    }
    $root = strtolower((string)$parts['scheme']) . '://' . (string)$parts['host'];
    if (isset($cache[$root])) {
        return $cache[$root];
    }

    $raw = ai_fetch_raw_url($root . '/robots.txt');
    $rules = ['disallow' => [], 'crawl_delay' => 0, 'sitemaps' => []];
    if (empty($raw['success'])) {
        $cache[$root] = $rules;
        return $rules;
    }

    $applies = false;
    foreach (preg_split('/\R/', (string)$raw['body']) as $line) {
        $line = trim(preg_replace('/#.*/', '', $line) ?: '');
        if ($line === '' || strpos($line, ':') === false) {
            continue;
        }
        [$key, $value] = array_map('trim', explode(':', $line, 2));
        $key = strtolower($key);
        if ($key === 'user-agent') {
            $agent = strtolower($value);
            $applies = $agent === '*' || strpos($agent, 'vani') !== false || strpos($agent, 'scanner') !== false;
            continue;
        }
        if ($key === 'sitemap' && $value !== '') {
            $rules['sitemaps'][] = $value;
            continue;
        }
        if (!$applies) {
            continue;
        }
        if ($key === 'disallow' && $value !== '') {
            $rules['disallow'][] = $value;
        } elseif ($key === 'crawl-delay') {
            $rules['crawl_delay'] = max($rules['crawl_delay'], (float)$value);
        }
    }
    $cache[$root] = $rules;
    return $rules;
}

function ai_robots_allows_url(string $url, string $websiteUrl): bool {
    $rules = ai_robots_rules($websiteUrl);
    $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
    foreach ($rules['disallow'] as $pattern) {
        $pattern = trim((string)$pattern);
        if ($pattern === '') {
            continue;
        }
        if ($pattern === '/' || strpos($path, $pattern) === 0) {
            return false;
        }
    }
    return true;
}

function ai_crawl_delay_ms(string $websiteUrl): int {
    $rules = ai_robots_rules($websiteUrl);
    $robotsDelay = (int)round(((float)($rules['crawl_delay'] ?? 0)) * 1000);
    $configured = (int)ai_env('AI_CRAWL_BATCH_DELAY_MS', '250');
    return max(0, min(10000, max($configured, $robotsDelay)));
}

function ai_sitemap_candidates(string $websiteUrl): array {
    $parts = parse_url($websiteUrl);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return [];
    }
    $root = strtolower((string)$parts['scheme']) . '://' . (string)$parts['host'];
    $candidates = [
        $root . '/sitemap.xml',
        $root . '/sitemap_index.xml',
        $root . '/sitemap.xml.gz',
        $root . '/robots.txt'
    ];
    foreach (ai_robots_rules($websiteUrl)['sitemaps'] as $sitemap) {
        $candidates[] = $sitemap;
    }
    return array_values(array_unique($candidates));
}

function ai_sitemap_entries_from_xml(string $xml, string $websiteDomain, int $limit = 120): array {
    $entries = [];
    $simple = false;
    if (function_exists('simplexml_load_string')) {
        $previous = libxml_use_internal_errors(true);
        $simple = simplexml_load_string(trim($xml));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    if ($simple !== false) {
        foreach ($simple->url as $urlNode) {
            $loc = trim((string)$urlNode->loc);
            if (!ai_should_scan_url($loc, $websiteDomain)) {
                continue;
            }
            $entries[] = [
                'url' => $loc,
                'lastmod' => trim((string)$urlNode->lastmod),
                'priority' => (float)((string)$urlNode->priority ?: '0.5'),
            ];
            if (count($entries) >= $limit) {
                break;
            }
        }
    }

    if (empty($entries) && preg_match_all('/<url\b[^>]*>(.*?)<\/url>/is', $xml, $matches)) {
        foreach ($matches[1] as $block) {
            if (!preg_match('/<loc>\s*([^<]+)\s*<\/loc>/i', $block, $locMatch)) {
                continue;
            }
            $loc = trim(html_entity_decode((string)$locMatch[1], ENT_QUOTES, 'UTF-8'));
            if (!ai_should_scan_url($loc, $websiteDomain)) {
                continue;
            }
            $lastmod = preg_match('/<lastmod>\s*([^<]+)\s*<\/lastmod>/i', $block, $lastmodMatch) ? trim((string)$lastmodMatch[1]) : '';
            $priority = preg_match('/<priority>\s*([^<]+)\s*<\/priority>/i', $block, $priorityMatch) ? (float)$priorityMatch[1] : 0.5;
            $entries[] = ['url' => $loc, 'lastmod' => $lastmod, 'priority' => $priority];
            if (count($entries) >= $limit) {
                break;
            }
        }
    }

    return $entries;
}

function ai_sitemap_child_urls_from_xml(string $xml): array {
    $sitemaps = [];
    $simple = false;
    if (function_exists('simplexml_load_string')) {
        $previous = libxml_use_internal_errors(true);
        $simple = simplexml_load_string(trim($xml));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    if ($simple !== false) {
        foreach ($simple->sitemap as $sitemapNode) {
            $loc = trim((string)$sitemapNode->loc);
            if ($loc !== '') {
                $sitemaps[] = $loc;
            }
        }
    }

    if (empty($sitemaps) && preg_match_all('/<sitemap\b[^>]*>.*?<loc>\s*([^<]+)\s*<\/loc>.*?<\/sitemap>/is', $xml, $matches)) {
        foreach ($matches[1] as $loc) {
            $sitemaps[] = trim(html_entity_decode((string)$loc, ENT_QUOTES, 'UTF-8'));
        }
    }

    return array_values(array_unique(array_filter($sitemaps)));
}

function ai_urls_from_sitemap_xml(string $xml, string $websiteDomain, int $limit = 120): array {
    return array_values(array_map(function ($entry) {
        return (string)$entry['url'];
    }, ai_sitemap_entries_from_xml($xml, $websiteDomain, $limit)));
}

function ai_save_sitemap_diagnostic(string $jobId, string $customerId, string $sitemapUrl, int $httpStatus, int $urlsFound, int $childSitemapsFound, string $error = ''): void {
    static $supported = null;
    if ($supported === null) {
        $probe = supabase('GET', 'ai_sitemap_diagnostics?select=id&limit=1');
        $supported = ($probe['status'] ?? 0) >= 200 && ($probe['status'] ?? 0) < 300;
    }
    if (!$supported || $jobId === '') {
        return;
    }
    ai_supabase_upsert('ai_sitemap_diagnostics', [[
        'scan_job_id' => $jobId,
        'customer_id' => $customerId,
        'sitemap_url' => $sitemapUrl,
        'http_status' => $httpStatus,
        'urls_found' => $urlsFound,
        'child_sitemaps_found' => $childSitemapsFound,
        'error_message' => $error,
        'fetched_at' => ai_now(),
    ]], 'scan_job_id,sitemap_url');
}

function ai_discover_sitemap_urls(string $websiteUrl, string $websiteDomain, int $limit = 120, string $jobId = '', string $customerId = ''): array {
    $sitemapUrls = [];
    $pageEntries = [];

    foreach (ai_sitemap_candidates($websiteUrl) as $candidate) {
        $raw = ai_fetch_raw_url($candidate);
        if (empty($raw['success']) || trim($raw['body']) === '') {
            ai_save_sitemap_diagnostic($jobId, $customerId, $candidate, (int)($raw['status'] ?? 0), 0, 0, 'Sitemap could not be fetched.');
            continue;
        }
        if (stripos($candidate, 'robots.txt') !== false) {
            if (preg_match_all('/^Sitemap:\s*(\S+)/mi', $raw['body'], $matches)) {
                foreach ($matches[1] as $sitemap) {
                    $sitemapUrls[] = trim((string)$sitemap);
                }
            }
            continue;
        }
        $sitemapUrls[] = $candidate;
        $entries = ai_sitemap_entries_from_xml($raw['body'], $websiteDomain, $limit);
        $children = ai_sitemap_child_urls_from_xml($raw['body']);
        ai_save_sitemap_diagnostic($jobId, $customerId, $candidate, (int)($raw['status'] ?? 0), count($entries), count($children));
        foreach ($entries as $entry) {
            $pageEntries[ai_normalize_page_url((string)$entry['url']) ?: (string)$entry['url']] = $entry;
        }
        foreach ($children as $childSitemap) {
            $sitemapUrls[] = $childSitemap;
        }
    }

    $seenSitemaps = [];
    $depth = 0;
    while (!empty($sitemapUrls) && count($pageEntries) < $limit && $depth < 500) {
        $sitemapUrl = array_shift($sitemapUrls);
        $depth++;
        if ($sitemapUrl === '' || isset($seenSitemaps[$sitemapUrl])) {
            continue;
        }
        $seenSitemaps[$sitemapUrl] = true;
        $raw = ai_fetch_raw_url($sitemapUrl);
        if (empty($raw['success'])) {
            ai_save_sitemap_diagnostic($jobId, $customerId, $sitemapUrl, (int)($raw['status'] ?? 0), 0, 0, 'Sitemap could not be fetched.');
            continue;
        }
        $children = ai_sitemap_child_urls_from_xml($raw['body']);
        $entries = ai_sitemap_entries_from_xml($raw['body'], $websiteDomain, $limit);
        ai_save_sitemap_diagnostic($jobId, $customerId, $sitemapUrl, (int)($raw['status'] ?? 0), count($entries), count($children));
        foreach ($children as $childSitemap) {
            if (!isset($seenSitemaps[$childSitemap])) {
                $sitemapUrls[] = $childSitemap;
            }
        }
        foreach ($entries as $entry) {
            $pageEntries[ai_normalize_page_url((string)$entry['url']) ?: (string)$entry['url']] = $entry;
            if (count($pageEntries) >= $limit) {
                break;
            }
        }
    }

    $entries = array_values($pageEntries);
    usort($entries, function ($a, $b) {
        $priority = ai_url_priority((string)$a['url']) <=> ai_url_priority((string)$b['url']);
        if ($priority !== 0) {
            return $priority;
        }
        $sitemapPriority = ((float)($b['priority'] ?? 0.5)) <=> ((float)($a['priority'] ?? 0.5));
        if ($sitemapPriority !== 0) {
            return $sitemapPriority;
        }
        return strcmp((string)($b['lastmod'] ?? ''), (string)($a['lastmod'] ?? ''));
    });
    return array_slice(array_map(function ($entry) {
        return (string)$entry['url'];
    }, $entries), 0, $limit);
}

function ai_parse_html_page(string $html, string $baseUrl, string $websiteDomain, int $linkLimit = 80): array {
    $title = '';
    $links = [];
    $cleanText = '';
    $extraText = [];
    $detectedFaqs = [];

    if (preg_match_all('/<script\b[^>]*type\s*=\s*["\'][^"\']*ld\+json[^"\']*["\'][^>]*>(.*?)<\/script>/is', $html, $jsonScripts)) {
        foreach ($jsonScripts[1] as $scriptJson) {
            $json = json_decode(trim(html_entity_decode($scriptJson, ENT_QUOTES, 'UTF-8')), true);
            foreach (ai_faqs_from_json_ld($json) as $faq) {
                $detectedFaqs[] = $faq;
            }
        }
    }

    if (class_exists(DOMDocument::class)) {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $titleNode = $dom->getElementsByTagName('title')->item(0);
        $title = $titleNode ? trim($titleNode->textContent) : '';
        if ($title !== '') {
            $extraText[] = 'Page title: ' . $title;
        }

        foreach ($dom->getElementsByTagName('meta') as $meta) {
            $name = strtolower(trim((string)($meta->getAttribute('name') ?: $meta->getAttribute('property'))));
            if (!in_array($name, ['description', 'keywords', 'og:title', 'og:description', 'twitter:title', 'twitter:description'], true)) {
                continue;
            }
            $content = trim((string)$meta->getAttribute('content'));
            if ($content !== '') {
                $extraText[] = $name . ': ' . $content;
            }
        }

        foreach (['h1', 'h2', 'h3'] as $headingTag) {
            foreach ($dom->getElementsByTagName($headingTag) as $heading) {
                $headingText = trim(preg_replace('/\s+/', ' ', $heading->textContent) ?: '');
                if ($headingText !== '') {
                    $extraText[] = strtoupper($headingTag) . ': ' . $headingText;
                }
            }
        }

        foreach (['img' => 'alt', 'input' => 'placeholder', 'button' => 'aria-label', 'a' => 'aria-label'] as $tag => $attribute) {
            foreach ($dom->getElementsByTagName($tag) as $node) {
                $value = trim((string)$node->getAttribute($attribute));
                if ($value !== '') {
                    $extraText[] = $value;
                }
            }
        }

        foreach (['script', 'style', 'noscript', 'svg'] as $tag) {
            while (($nodes = $dom->getElementsByTagName($tag))->length > 0) {
                $node = $nodes->item(0);
                if ($node && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $cleanText = preg_replace('/\s+/', ' ', trim($dom->textContent)) ?: '';

        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $resolved = ai_resolve_url($baseUrl, (string)$anchor->getAttribute('href'));
            $normalized = ai_normalize_page_url($resolved);
            if (!ai_should_scan_url($normalized, $websiteDomain)) {
                continue;
            }
            $links[$normalized] = true;
            if (count($links) >= $linkLimit) {
                break;
            }
        }

        foreach ($dom->getElementsByTagName('details') as $details) {
            $summaryNode = $details->getElementsByTagName('summary')->item(0);
            $question = $summaryNode ? trim($summaryNode->textContent) : '';
            $answer = trim(str_replace($question, '', $details->textContent));
            if ($question !== '' && $answer !== '') {
                $detectedFaqs[] = ['question' => $question, 'answer' => $answer, 'source' => 'html'];
            }
        }
    } else {
        $withoutScripts = preg_replace('/<(script|style|noscript|svg)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?: $html;
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match)) {
            $title = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES, 'UTF-8'));
            if ($title !== '') {
                $extraText[] = 'Page title: ' . $title;
            }
        }
        if (preg_match_all('/<meta\b[^>]*(?:name|property)\s*=\s*["\'](description|keywords|og:title|og:description|twitter:title|twitter:description)["\'][^>]*content\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $html, $metaMatches, PREG_SET_ORDER)) {
            foreach ($metaMatches as $metaMatch) {
                $extraText[] = strtolower((string)$metaMatch[1]) . ': ' . html_entity_decode((string)$metaMatch[2], ENT_QUOTES, 'UTF-8');
            }
        }
        if (preg_match_all('/<h([1-3])\b[^>]*>(.*?)<\/h\1>/is', $html, $headingMatches, PREG_SET_ORDER)) {
            foreach ($headingMatches as $headingMatch) {
                $headingText = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)$headingMatch[2]), ENT_QUOTES, 'UTF-8')) ?: '');
                if ($headingText !== '') {
                    $extraText[] = 'H' . (string)$headingMatch[1] . ': ' . $headingText;
                }
            }
        }
        if (preg_match_all('/\b(?:alt|placeholder|aria-label)\s*=\s*["\']([^"\']+)["\']/i', $html, $attributeMatches)) {
            foreach ($attributeMatches[1] as $attributeText) {
                $attributeText = trim(html_entity_decode((string)$attributeText, ENT_QUOTES, 'UTF-8'));
                if ($attributeText !== '') {
                    $extraText[] = $attributeText;
                }
            }
        }
        if (preg_match_all('/<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                $resolved = ai_resolve_url($baseUrl, (string)$href);
                $normalized = ai_normalize_page_url($resolved);
                if (!ai_should_scan_url($normalized, $websiteDomain)) {
                    continue;
                }
                $links[$normalized] = true;
                if (count($links) >= $linkLimit) {
                    break;
                }
            }
        }
        $cleanText = preg_replace('/\s+/', ' ', trim(html_entity_decode(strip_tags($withoutScripts), ENT_QUOTES, 'UTF-8'))) ?: '';
    }

    $extraText = array_values(array_unique(array_filter(array_map(function ($value) {
        return trim(preg_replace('/\s+/', ' ', (string)$value) ?: '');
    }, $extraText))));
    if (!empty($extraText)) {
        $cleanText = trim(implode(' ', $extraText) . ' ' . $cleanText);
    }

    return [
        'title' => substr($title, 0, 500),
        'clean_text' => substr($cleanText, 0, ai_page_clean_text_limit()),
        'links' => array_keys($links),
        'detected_faqs' => ai_dedupe_faqs($detectedFaqs),
    ];
}

function ai_faqs_from_json_ld($json): array {
    if (!is_array($json)) {
        return [];
    }
    $items = isset($json['@graph']) && is_array($json['@graph']) ? $json['@graph'] : [$json];
    $faqs = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = $item['@type'] ?? '';
        $types = is_array($type) ? $type : [$type];
        if (!in_array('FAQPage', $types, true)) {
            continue;
        }
        foreach (($item['mainEntity'] ?? []) as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $question = trim((string)($entity['name'] ?? ''));
            $answerData = $entity['acceptedAnswer']['text'] ?? '';
            $answer = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)$answerData), ENT_QUOTES, 'UTF-8')) ?: '');
            if ($question !== '' && $answer !== '') {
                $faqs[] = ['question' => $question, 'answer' => $answer, 'source' => 'json_ld'];
            }
        }
    }
    return $faqs;
}

function ai_dedupe_faqs(array $faqs): array {
    $seen = [];
    $clean = [];
    foreach ($faqs as $faq) {
        $question = trim(preg_replace('/\s+/', ' ', (string)($faq['question'] ?? '')) ?: '');
        $answer = trim(preg_replace('/\s+/', ' ', (string)($faq['answer'] ?? '')) ?: '');
        if ($question === '' || $answer === '') {
            continue;
        }
        $key = strtolower($question);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $clean[] = [
            'question' => substr($question, 0, 800),
            'answer' => substr($answer, 0, 3000),
            'source' => (string)($faq['source'] ?? 'ai')
        ];
    }
    return $clean;
}

function ai_page_looks_like_faq(string $url, string $title, string $cleanText): bool {
    $haystack = strtolower($url . ' ' . $title . ' ' . substr($cleanText, 0, 5000));
    return preg_match('/(faq|faqs|frequently asked|frequently-asked|questions and answers|common questions)/', $haystack) === 1;
}

function ai_extract_page_faqs(string $url, string $title, string $cleanText): array {
    $cleanText = trim($cleanText);
    if ($cleanText === '') {
        return [];
    }

    $systemPrompt = 'Extract FAQ question-answer pairs from website page text. Return exactly one valid JSON object and no prose.';
    $userPrompt = "Return JSON in this shape: {\"faqs\":[{\"question\":\"string\",\"answer\":\"string\"}]}.\n"
        . "Capture every explicit FAQ question-answer pair on the page. Do not limit to two. Do not invent questions. If there are no FAQs, return {\"faqs\":[]}.\n"
        . "URL: {$url}\nTitle: {$title}\n\nPage text:\n" . substr($cleanText, 0, 50000);

    $decoded = ai_decode_json_result(ai_generate_text($systemPrompt, $userPrompt, [
        'json' => true,
        'temperature' => 0,
        'max_output_tokens' => 12000,
    ]));

    if (empty($decoded['success'])) {
        return [];
    }

    $faqs = $decoded['data']['faqs'] ?? [];
    return is_array($faqs) ? ai_dedupe_faqs(array_map(function ($faq) {
        return [
            'question' => (string)($faq['question'] ?? ''),
            'answer' => (string)($faq['answer'] ?? ''),
            'source' => 'ai'
        ];
    }, $faqs)) : [];
}

function ai_save_page_faqs(string $jobId, string $customerId, string $pageUrl, array $faqs): void {
    $rows = [];
    foreach (ai_dedupe_faqs($faqs) as $faq) {
        $rows[] = [
            'scan_job_id' => $jobId,
            'customer_id' => $customerId,
            'page_url' => $pageUrl,
            'question' => (string)$faq['question'],
            'answer' => (string)$faq['answer'],
            'source' => (string)($faq['source'] ?? 'ai'),
            'updated_at' => ai_now()
        ];
    }
    if (empty($rows)) {
        return;
    }

    $upsert = ai_supabase_upsert('ai_website_faqs', $rows, 'customer_id,page_url,question');
    if (($upsert['status'] ?? 0) >= 200 && ($upsert['status'] ?? 0) < 300) {
        return;
    }

    foreach ($rows as $payload) {
        $existing = ai_safe_rows(supabase(
            'GET',
            'ai_website_faqs?select=id&customer_id=eq.' . urlencode($customerId)
                . '&page_url=eq.' . urlencode($pageUrl)
                . '&question=eq.' . urlencode((string)$payload['question'])
                . '&limit=1'
        ));
        if (!empty($existing[0]['id'])) {
            supabase('PATCH', 'ai_website_faqs?id=eq.' . urlencode((string)$existing[0]['id']), $payload);
        } else {
            supabase('POST', 'ai_website_faqs', [$payload]);
        }
    }
}

function ai_save_scanned_page(string $jobId, string $customerId, array $payload): void {
    $normalizedUrl = (string)$payload['normalized_url'];
    $existing = ai_safe_rows(supabase(
        'GET',
        'ai_website_pages?select=id,content_hash,summary_json,embedding,page_status,summarized_at&customer_id=eq.' . urlencode($customerId) . '&normalized_url=eq.' . urlencode($normalizedUrl) . '&limit=1'
    ));
    $payload['scan_job_id'] = $jobId;
    $payload['customer_id'] = $customerId;
    $payload['updated_at'] = ai_now();

    $incomingHash = (string)($payload['content_hash'] ?? '');
    $existingHash = (string)($existing[0]['content_hash'] ?? '');
    if ($incomingHash !== '' && $incomingHash === $existingHash && !empty($existing[0]['summary_json'])) {
        $payload['summary_json'] = $existing[0]['summary_json'];
        $payload['embedding'] = $existing[0]['embedding'] ?? null;
        $payload['page_status'] = 'summarized';
        $payload['summarized_at'] = $existing[0]['summarized_at'] ?? ai_now();
        $payload['ai_error'] = '';
    }

    $upsert = ai_supabase_upsert('ai_website_pages', [$payload], 'customer_id,normalized_url');
    if (($upsert['status'] ?? 0) >= 200 && ($upsert['status'] ?? 0) < 300) {
        return;
    }

    if (!empty($existing[0]['id'])) {
        supabase('PATCH', 'ai_website_pages?id=eq.' . urlencode((string)$existing[0]['id']), $payload);
        return;
    }
    supabase('POST', 'ai_website_pages', [$payload]);
}

function ai_capture_single_page(string $jobId, string $customerId, string $websiteDomain, string $pageUrl): array {
    $normalizedInput = ai_normalize_website_input($pageUrl);
    if (empty($normalizedInput['success'])) {
        return ['success' => false, 'page_id' => '', 'error' => (string)$normalizedInput['error']];
    }
    if ((string)$normalizedInput['domain'] !== $websiteDomain) {
        return ['success' => false, 'page_id' => '', 'error' => 'Please add a page from the same website domain.'];
    }

    $url = (string)$normalizedInput['url'];
    $fetch = ai_fetch_page($url);
    $finalUrl = (string)($fetch['url'] ?? $url);
    $normalized = ai_normalize_page_url($finalUrl) ?: ai_normalize_page_url($url);

    if (empty($fetch['success'])) {
        ai_save_scanned_page($jobId, $customerId, [
            'url' => $finalUrl,
            'normalized_url' => $normalized,
            'page_status' => 'failed',
            'http_status' => (int)($fetch['status'] ?? 0),
            'ai_error' => (string)($fetch['error'] ?? 'Could not capture page.'),
            'content_type' => (string)($fetch['content_type'] ?? ''),
            'content_length' => (int)($fetch['content_length'] ?? 0),
            'html_preview' => '',
            'fetched_at' => ai_now()
        ]);
        return ['success' => false, 'page_id' => '', 'error' => (string)($fetch['error'] ?? 'Could not capture page.')];
    }

    $parsed = ai_parse_html_page((string)$fetch['html'], $finalUrl, $websiteDomain);
    $cleanText = (string)$parsed['clean_text'];
    ai_save_page_faqs($jobId, $customerId, $finalUrl, $parsed['detected_faqs']);
    ai_save_scanned_page($jobId, $customerId, [
        'url' => $finalUrl,
        'normalized_url' => $normalized,
        'page_title' => (string)$parsed['title'],
        'page_status' => 'fetched',
        'http_status' => (int)$fetch['status'],
        'content_hash' => hash('sha256', $cleanText),
        'clean_text' => $cleanText,
        'summary_json' => null,
        'embedding' => null,
        'ai_error' => '',
        'content_type' => (string)($fetch['content_type'] ?? ''),
        'content_length' => (int)($fetch['content_length'] ?? strlen($cleanText)),
        'discovered_links_count' => count($parsed['links']),
        'html_preview' => substr(strip_tags((string)$fetch['html']), 0, 1000),
        'fetched_at' => ai_now(),
        'summarized_at' => null
    ]);

    $rows = ai_safe_rows(supabase(
        'GET',
        'ai_website_pages?select=id&customer_id=eq.' . urlencode($customerId)
            . '&normalized_url=eq.' . urlencode($normalized)
            . '&limit=1'
    ));

    return [
        'success' => trim($cleanText) !== '',
        'page_id' => (string)($rows[0]['id'] ?? ''),
        'error' => trim($cleanText) === '' ? 'Page opened, but no readable text was captured. Add the summary manually.' : ''
    ];
}

function ai_enqueue_scan_urls(string $jobId, string $customerId, array $urls, int $maxPages, bool $skipExistingFinished = false): int {
    $rows = [];
    foreach ($urls as $url) {
        $normalized = ai_normalize_page_url((string)$url);
        if ($normalized === '') {
            continue;
        }
        $rows[$normalized] = [
            'scan_job_id' => $jobId,
            'customer_id' => $customerId,
            'url' => (string)$url,
            'normalized_url' => $normalized,
            'page_status' => 'pending',
            'http_status' => null,
            'ai_error' => '',
            'content_length' => 0,
            'discovered_links_count' => 0,
            'updated_at' => ai_now(),
        ];
        if (ai_db_supports_advanced_crawler_columns()) {
            $rows[$normalized]['priority'] = ai_page_priority((string)$url);
            $rows[$normalized]['source'] = ai_page_source_from_url((string)$url);
            $rows[$normalized]['page_worker_id'] = null;
            $rows[$normalized]['page_locked_until'] = null;
        }
        if (ai_db_supports_retry_columns()) {
            $rows[$normalized]['crawl_attempts'] = 0;
            $rows[$normalized]['next_retry_at'] = null;
        }
        if (count($rows) >= $maxPages) {
            break;
        }
    }
    if (empty($rows)) {
        return 0;
    }
    if ($skipExistingFinished) {
        foreach (array_keys($rows) as $normalized) {
            $existing = ai_safe_rows(supabase(
                'GET',
                'ai_website_pages?select=page_status,scan_job_id&customer_id=eq.' . urlencode($customerId)
                    . '&normalized_url=eq.' . urlencode($normalized)
                    . '&limit=1'
            ));
            $status = (string)($existing[0]['page_status'] ?? '');
            if ($status !== '' && $status !== 'pending') {
                ai_crawl_log($jobId, $customerId, 'duplicate_skipped', 'Duplicate or already processed URL skipped.', [
                    'normalized_url' => $normalized,
                    'existing_status' => $status,
                ], 'info', (string)($rows[$normalized]['url'] ?? ''));
                unset($rows[$normalized]);
            }
        }
        if (empty($rows)) {
            return 0;
        }
    }

    $upsert = ai_supabase_upsert('ai_website_pages', array_values($rows), 'customer_id,normalized_url');
    if (($upsert['status'] ?? 0) >= 200 && ($upsert['status'] ?? 0) < 300) {
        return count($rows);
    }

    foreach ($rows as $row) {
        ai_save_scanned_page($jobId, $customerId, $row);
    }
    return count($rows);
}

function ai_seed_scan_job(string $jobId, string $customerId, string $websiteUrl, string $websiteDomain, int $maxPages = 120): array {
    $maxPages = max(1, min(500, $maxPages));
    $urls = [$websiteUrl];
    $setupSitemapLimit = max(0, min($maxPages * 4, (int)ai_env('AI_SETUP_SITEMAP_SEED_LIMIT', '40')));
    foreach (ai_discover_sitemap_urls($websiteUrl, $websiteDomain, $setupSitemapLimit, $jobId, $customerId) as $sitemapPage) {
        $urls[] = $sitemapPage;
    }
    $wwwVariant = ai_add_www_variant($websiteUrl);
    if ($wwwVariant !== '') {
        $urls[] = $wwwVariant;
    }
    $nonWwwVariant = ai_remove_www_variant($websiteUrl);
    if ($nonWwwVariant !== '') {
        $urls[] = $nonWwwVariant;
    }

    $normalizedUrls = [];
    foreach ($urls as $url) {
        if (ai_should_scan_url((string)$url, $websiteDomain) && ai_robots_allows_url((string)$url, $websiteUrl)) {
            $normalizedUrls[ai_normalize_page_url((string)$url) ?: (string)$url] = (string)$url;
        }
    }
    $urls = array_values($normalizedUrls);
    usort($urls, function ($a, $b) {
        return ai_url_priority((string)$a) <=> ai_url_priority((string)$b);
    });

    $queued = ai_enqueue_scan_urls($jobId, $customerId, array_slice($urls, 0, $maxPages), $maxPages);
    ai_patch_scan_job($jobId, [
        'status' => 'pending',
        'pages_requested' => $maxPages,
        'pages_scanned' => 0,
        'pages_failed' => 0,
        'error_message' => $queued > 0 ? null : 'No crawlable URLs were discovered.',
        'updated_at' => ai_now()
    ]);

    return ['success' => $queued > 0, 'queued' => $queued, 'error' => $queued > 0 ? '' : 'No crawlable URLs were discovered.'];
}

function ai_scan_job_counts(string $jobId, string $customerId): array {
    $rows = ai_safe_rows(supabase(
        'GET',
        'ai_website_pages?select=page_status&scan_job_id=eq.' . urlencode($jobId)
            . '&customer_id=eq.' . urlencode($customerId)
            . '&limit=1000'
    ));
    $counts = ['pending' => 0, 'fetched' => 0, 'summarized' => 0, 'failed' => 0, 'total' => count($rows)];
    foreach ($rows as $row) {
        $status = (string)($row['page_status'] ?? 'pending');
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
    }
    $counts['scanned'] = $counts['fetched'] + $counts['summarized'];
    $counts['crawl_done'] = $counts['scanned'] + $counts['failed'];
    $counts['crawl_total'] = $counts['total'];
    $counts['crawl_percent'] = $counts['crawl_total'] > 0
        ? min(100, (int)round(($counts['crawl_done'] / $counts['crawl_total']) * 100))
        : 0;
    $counts['summary_total'] = $counts['scanned'];
    $counts['summary_done'] = $counts['summarized'];
    $counts['summary_pending'] = $counts['fetched'];
    $counts['summary_percent'] = $counts['summary_total'] > 0
        ? min(100, (int)round(($counts['summary_done'] / $counts['summary_total']) * 100))
        : 0;
    return $counts;
}

function ai_scan_review_pages(string $jobId, string $customerId, int $limit = 250): array {
    return ai_safe_rows(supabase(
        'GET',
        'ai_website_pages?select=*&scan_job_id=eq.' . urlencode($jobId)
            . '&customer_id=eq.' . urlencode($customerId)
            . '&page_status=in.(fetched,summarized)'
            . '&order=created_at.asc&limit=' . max(1, min(250, $limit))
    ));
}

function ai_scan_review_faqs(string $jobId, string $customerId, int $limit = 500): array {
    return ai_safe_rows(supabase(
        'GET',
        'ai_website_faqs?select=*&scan_job_id=eq.' . urlencode($jobId)
            . '&customer_id=eq.' . urlencode($customerId)
            . '&order=created_at.asc&limit=' . max(1, min(1000, $limit))
    ));
}

function ai_scan_diagnostics(string $jobId, string $customerId): array {
    $retrySupported = ai_db_supports_retry_columns();
    $advancedSupported = ai_db_supports_advanced_crawler_columns();
    $scan = ai_get_scan_job_for_customer($jobId, $customerId);
    $counts = ai_scan_job_counts($jobId, $customerId);
    $pageSelect = $retrySupported
        ? 'id,url,page_title,page_status,http_status,ai_error,content_type,content_length,discovered_links_count,crawl_attempts,next_retry_at,summary_attempts,summary_next_retry_at,fetched_at,summarized_at,updated_at'
        : 'id,url,page_title,page_status,http_status,ai_error,content_type,content_length,discovered_links_count,fetched_at,summarized_at,updated_at';
    if ($advancedSupported) {
        $pageSelect .= ',source,priority,page_worker_id,page_locked_until';
    }

    $pending = ai_safe_rows(supabase(
        'GET',
        'ai_website_pages?select=' . $pageSelect
            . '&scan_job_id=eq.' . urlencode($jobId)
            . '&customer_id=eq.' . urlencode($customerId)
            . '&page_status=eq.pending'
            . ($advancedSupported ? '&page_worker_id=is.null' : '')
            . '&order=created_at.asc&limit=10'
    ));
    $claimed = [];
    if ($advancedSupported) {
        $claimed = ai_safe_rows(supabase(
            'GET',
            'ai_website_pages?select=' . $pageSelect
                . '&scan_job_id=eq.' . urlencode($jobId)
                . '&customer_id=eq.' . urlencode($customerId)
                . '&page_status=eq.pending'
                . '&page_worker_id=not.is.null'
                . '&order=page_locked_until.desc&limit=10'
        ));
    }
    $failed = ai_safe_rows(supabase(
        'GET',
        'ai_website_pages?select=' . $pageSelect
            . '&scan_job_id=eq.' . urlencode($jobId)
            . '&customer_id=eq.' . urlencode($customerId)
            . '&page_status=eq.failed'
            . '&order=updated_at.desc&limit=10'
    ));
    $recentFetched = ai_safe_rows(supabase(
        'GET',
        'ai_website_pages?select=' . $pageSelect
            . '&scan_job_id=eq.' . urlencode($jobId)
            . '&customer_id=eq.' . urlencode($customerId)
            . '&page_status=in.(fetched,summarized)'
            . '&order=fetched_at.desc&limit=10'
    ));
    $recentSummarized = ai_safe_rows(supabase(
        'GET',
        'ai_website_pages?select=' . $pageSelect
            . '&scan_job_id=eq.' . urlencode($jobId)
            . '&customer_id=eq.' . urlencode($customerId)
            . '&page_status=eq.summarized'
            . '&order=summarized_at.desc&limit=10'
    ));
    $quality = ai_safe_rows(supabase(
        'GET',
        'ai_website_pages?select=id,url,page_title,page_status,content_length,clean_text,ai_error,updated_at'
            . '&scan_job_id=eq.' . urlencode($jobId)
            . '&customer_id=eq.' . urlencode($customerId)
            . '&page_status=in.(fetched,summarized)'
            . '&order=content_length.asc&limit=10'
    ));

    $waitingRetry = [];
    $waitingSummaryRetry = [];
    if ($retrySupported) {
        $waitingRetry = ai_safe_rows(supabase(
            'GET',
            'ai_website_pages?select=' . $pageSelect
                . '&scan_job_id=eq.' . urlencode($jobId)
                . '&customer_id=eq.' . urlencode($customerId)
                . '&page_status=eq.pending'
                . '&next_retry_at=not.is.null'
                . '&order=next_retry_at.asc&limit=10'
        ));
        $waitingSummaryRetry = ai_safe_rows(supabase(
            'GET',
            'ai_website_pages?select=' . $pageSelect
                . '&scan_job_id=eq.' . urlencode($jobId)
                . '&customer_id=eq.' . urlencode($customerId)
                . '&page_status=eq.fetched'
                . '&summary_next_retry_at=not.is.null'
            . '&order=summary_next_retry_at.asc&limit=10'
        ));
    }
    $logs = [];
    if (ai_db_supports_crawl_logs()) {
        $logs = ai_safe_rows(supabase(
            'GET',
            'ai_crawl_logs?select=event_type,severity,url,message,metadata,created_at'
                . '&scan_job_id=eq.' . urlencode($jobId)
                . '&order=created_at.desc&limit=12'
        ));
    }

    return [
        'scan' => $scan,
        'counts' => $counts,
        'pending' => ai_add_diagnostic_labels($pending),
        'claimed' => ai_add_diagnostic_labels($claimed),
        'failed' => ai_add_diagnostic_labels($failed),
        'recent_fetched' => ai_add_diagnostic_labels($recentFetched),
        'recent_summarized' => ai_add_diagnostic_labels($recentSummarized),
        'waiting_retry' => ai_add_diagnostic_labels($waitingRetry),
        'waiting_summary_retry' => ai_add_diagnostic_labels($waitingSummaryRetry),
        'quality' => ai_add_diagnostic_labels($quality),
        'logs' => $logs,
        'retry_supported' => $retrySupported,
        'settings' => [
            'crawl_batch_size' => (int)ai_env('AI_CRAWL_BATCH_SIZE', '4'),
            'crawl_concurrency' => (int)ai_env('AI_CRAWL_CONCURRENCY', '4'),
            'summary_batch_size' => (int)ai_env('AI_SUMMARY_BATCH_SIZE', '2'),
            'crawl_delay_ms' => (int)ai_env('AI_CRAWL_BATCH_DELAY_MS', '250'),
            'render_enabled' => ai_env('AI_RENDER_ENDPOINT') !== '',
        ],
    ];
}

function ai_get_scan_job_for_customer(string $jobId, string $customerId): array {
    $rows = ai_safe_rows(supabase(
        'GET',
        'ai_scan_jobs?select=*&id=eq.' . urlencode($jobId) . '&customer_id=eq.' . urlencode($customerId) . '&limit=1'
    ));
    return $rows[0] ?? [];
}

function ai_get_scan_job_by_id(string $jobId): array {
    $rows = ai_safe_rows(supabase(
        'GET',
        'ai_scan_jobs?select=*&id=eq.' . urlencode($jobId) . '&limit=1'
    ));
    return $rows[0] ?? [];
}

function ai_page_retry_payload(string $error, int $attempts): array {
    $maxAttempts = max(1, (int)ai_env('AI_CRAWL_MAX_ATTEMPTS', '3'));
    if ($attempts >= $maxAttempts) {
        return [
            'page_status' => 'failed',
            'ai_error' => $error,
            'crawl_attempts' => $attempts,
            'next_retry_at' => null,
            'fetched_at' => ai_now(),
        ];
    }
    $delay = min(900, (int)pow(3, max(1, $attempts)) * 20);
    return [
        'page_status' => 'pending',
        'ai_error' => $error,
        'crawl_attempts' => $attempts,
        'next_retry_at' => ai_seconds_from_now($delay),
        'updated_at' => ai_now(),
    ];
}

function ai_claim_pending_pages(string $jobId, string $customerId, int $batchLimit, string $workerId, bool $retrySupported): array {
    $advanced = ai_db_supports_advanced_crawler_columns();
    if ($advanced && ai_db_supports_page_claim_rpc()) {
        $claimed = ai_safe_rows(ai_supabase_rpc('ai_claim_pending_pages', [
            'p_scan_job_id' => $jobId,
            'p_customer_id' => $customerId,
            'p_worker_id' => $workerId,
            'p_limit' => max(1, min(50, $batchLimit)),
            'p_lock_seconds' => (int)ai_env('AI_PAGE_LOCK_SECONDS', '120'),
        ]));
        if (!empty($claimed)) {
            return $claimed;
        }
    }
    $now = ai_now();
    $select = $retrySupported
        ? 'id,url,normalized_url,crawl_attempts,next_retry_at'
        : 'id,url,normalized_url';
    if ($advanced) {
        $select .= ',priority,page_worker_id,page_locked_until';
    }
    $endpoint = 'ai_website_pages?select=' . $select
        . '&scan_job_id=eq.' . urlencode($jobId)
        . '&customer_id=eq.' . urlencode($customerId)
        . '&page_status=eq.pending';
    if ($retrySupported) {
        $endpoint .= '&or=(next_retry_at.is.null,next_retry_at.lte.' . urlencode($now) . ')';
    }
    if ($advanced) {
        $endpoint .= '&or=(page_locked_until.is.null,page_locked_until.lt.' . urlencode($now) . ',page_worker_id.eq.' . urlencode($workerId) . ')'
            . '&order=priority.asc,created_at.asc&limit=' . $batchLimit;
    } else {
        $endpoint .= '&order=created_at.asc&limit=' . $batchLimit;
    }

    $pages = ai_safe_rows(supabase('GET', $endpoint));
    if (!$advanced || empty($pages)) {
        return $pages;
    }

    $claimed = [];
    foreach ($pages as $page) {
        $pageId = (string)($page['id'] ?? '');
        if ($pageId === '') {
            continue;
        }
        $claim = supabase(
            'PATCH',
            'ai_website_pages?id=eq.' . urlencode($pageId)
                . '&page_status=eq.pending'
                . '&or=(page_locked_until.is.null,page_locked_until.lt.' . urlencode($now) . ',page_worker_id.eq.' . urlencode($workerId) . ')',
            [
                'page_worker_id' => $workerId,
                'page_locked_until' => ai_seconds_from_now((int)ai_env('AI_PAGE_LOCK_SECONDS', '120')),
                'updated_at' => ai_now(),
            ]
        );
        if (!empty(ai_safe_rows($claim))) {
            $page['page_worker_id'] = $workerId;
            $claimed[] = $page;
        }
    }
    return $claimed;
}

function ai_release_page_claim(string $pageId, string $workerId): void {
    if (!ai_db_supports_advanced_crawler_columns() || $pageId === '') {
        return;
    }
    supabase(
        'PATCH',
        'ai_website_pages?id=eq.' . urlencode($pageId) . '&page_worker_id=eq.' . urlencode($workerId),
        ['page_worker_id' => null, 'page_locked_until' => null, 'updated_at' => ai_now()]
    );
}

function ai_recover_stuck_scan_jobs(): int {
    if (!ai_db_supports_worker_columns()) {
        return 0;
    }
    $now = ai_now();
    $jobs = ai_safe_rows(supabase(
        'GET',
        'ai_scan_jobs?select=id,status,worker_id,locked_until&status=eq.running&locked_until=lt.' . urlencode($now) . '&limit=50'
    ));
    foreach ($jobs as $job) {
        supabase('PATCH', 'ai_scan_jobs?id=eq.' . urlencode((string)$job['id']), [
            'status' => 'pending',
            'worker_id' => null,
            'locked_until' => null,
            'error_message' => 'Recovered stale worker lock.',
            'updated_at' => ai_now(),
        ]);
    }
    if (ai_db_supports_advanced_crawler_columns()) {
        supabase('PATCH', 'ai_website_pages?page_status=eq.pending&page_locked_until=lt.' . urlencode($now), [
            'page_worker_id' => null,
            'page_locked_until' => null,
            'updated_at' => ai_now(),
        ]);
    }
    return count($jobs);
}

function ai_process_scan_job_batch(string $jobId, string $customerId, int $batchSize = 4): array {
    $scan = ai_get_scan_job_for_customer($jobId, $customerId);
    if (empty($scan)) {
        return ['success' => false, 'error' => 'Scan job was not found.'];
    }
    $workerId = ai_worker_id();
    $pageLevelClaiming = ai_db_supports_advanced_crawler_columns();
    if (!$pageLevelClaiming && !ai_claim_scan_job($jobId, $customerId, $workerId)) {
        return ['success' => true, 'status' => (string)($scan['status'] ?? 'running'), 'counts' => ai_scan_job_counts($jobId, $customerId), 'processed' => 0, 'locked' => true, 'error' => ''];
    }

    $maxPages = max(1, min(500, (int)($scan['pages_requested'] ?? 120)));
    if ((string)($scan['status'] ?? '') === 'pending') {
        ai_patch_scan_job($jobId, [
            'status' => 'running',
            'started_at' => $scan['started_at'] ?: ai_now(),
            'error_message' => null,
            'updated_at' => ai_now()
        ]);
        ai_crawl_log($jobId, $customerId, 'scan_started', 'Crawler started processing this scan.', [], 'info', (string)($scan['website_url'] ?? ''));
    }

    $retrySupported = ai_db_supports_retry_columns();
    $batchLimit = max(1, min((int)ai_env('AI_CRAWL_MAX_BATCH_SIZE', '12'), $batchSize));
    $pendingPages = ai_claim_pending_pages($jobId, $customerId, $batchLimit, $workerId, $retrySupported);

    if (empty($pendingPages)) {
        $counts = ai_scan_job_counts($jobId, $customerId);
        if ($counts['pending'] > 0) {
            ai_patch_scan_job($jobId, [
                'status' => 'running',
                'pages_scanned' => $counts['scanned'],
                'pages_failed' => $counts['failed'],
                'error_message' => null,
                'updated_at' => ai_now()
            ]);
            if (!$pageLevelClaiming) {
                ai_release_scan_job($jobId, $workerId);
            }
            return ['success' => true, 'status' => 'running', 'counts' => $counts, 'processed' => 0, 'waiting_for_retry' => true, 'error' => ''];
        }
        $status = $counts['scanned'] > 0 ? 'completed' : 'failed';
        ai_patch_scan_job($jobId, [
            'status' => $status,
            'pages_scanned' => $counts['scanned'],
            'pages_failed' => $counts['failed'],
            'completed_at' => ai_now(),
            'error_message' => $status === 'failed' ? 'No pages could be scanned.' : null,
            'updated_at' => ai_now()
        ]);
        if (!$pageLevelClaiming) {
            ai_release_scan_job($jobId, $workerId);
        }
        return ['success' => $status === 'completed', 'status' => $status, 'counts' => $counts, 'processed' => 0, 'error' => ''];
    }

    $urls = array_map(function ($page) {
        return (string)$page['url'];
    }, $pendingPages);
    $activeUrls = array_slice($urls, 0, 5);
    $fetches = ai_fetch_pages_parallel($urls, max(1, min((int)ai_env('AI_CRAWL_CONCURRENCY', '4'), $batchSize)));
    $websiteDomain = (string)$scan['website_domain'];
    $processed = 0;
    $countsBeforeLoop = ai_scan_job_counts($jobId, $customerId);
    $knownTotal = (int)$countsBeforeLoop['total'];

    foreach ($pendingPages as $page) {
        if (!$pageLevelClaiming) {
            ai_extend_scan_job_lock($jobId, $workerId);
        }
        $url = (string)$page['url'];
        $normalized = (string)$page['normalized_url'];
        $attempts = (int)($page['crawl_attempts'] ?? 0) + 1;
        $fetch = $fetches[$url] ?? ai_fetch_page($url);
        if (empty($fetch['success'])) {
            $variant = ai_add_www_variant($url) ?: ai_remove_www_variant($url);
            if ($variant !== '' && ai_should_scan_url($variant, $websiteDomain)) {
                $variantFetch = ai_fetch_page($variant);
                if (!empty($variantFetch['success'])) {
                    $fetch = $variantFetch;
                    $url = $variant;
                }
            }
            if (!empty($fetch['success'])) {
                $fetches[$url] = $fetch;
            } else {
                $payload = [
                    'url' => $url,
                    'normalized_url' => $normalized,
                    'http_status' => (int)($fetch['status'] ?? 0),
                    'ai_error' => (string)($fetch['error'] ?? 'Fetch failed.')
                ];
                if ($retrySupported) {
                    $payload = array_merge($payload, ai_page_retry_payload((string)($fetch['error'] ?? 'Fetch failed.'), $attempts));
                } else {
                    $payload['page_status'] = 'failed';
                    $payload['fetched_at'] = ai_now();
                }
                ai_save_scanned_page($jobId, $customerId, $payload);
                ai_crawl_log($jobId, $customerId, 'page_fetch_failed', (string)($fetch['error'] ?? 'Fetch failed.'), [
                    'http_status' => (int)($fetch['status'] ?? 0),
                    'attempts' => $attempts,
                ], 'warning', $url, (string)($page['id'] ?? ''));
                ai_release_page_claim((string)($page['id'] ?? ''), $workerId);
                $processed++;
                continue;
            }
        }

        $finalUrl = (string)($fetch['url'] ?? $url);
        $finalNormalized = ai_normalize_page_url($finalUrl) ?: $normalized;
        if (ai_detect_js_challenge_html((string)($fetch['html'] ?? ''))) {
            $errorMessage = 'JS challenge detected. Enable Playwright rendering or ask the site owner to whitelist the crawler.';
            $payload = [
                'url' => $finalUrl,
                'normalized_url' => $finalNormalized,
                'page_status' => 'failed',
                'http_status' => (int)($fetch['status'] ?? 0),
                'ai_error' => $errorMessage,
                'content_type' => (string)($fetch['content_type'] ?? ''),
                'content_length' => (int)($fetch['content_length'] ?? 0),
                'html_preview' => substr(strip_tags((string)($fetch['html'] ?? '')), 0, 1000),
                'fetched_at' => ai_now(),
            ];
            if ($retrySupported) {
                $payload['crawl_attempts'] = $attempts;
                $payload['next_retry_at'] = ai_seconds_from_now(31536000);
            }
            ai_save_scanned_page($jobId, $customerId, $payload);
            ai_crawl_log($jobId, $customerId, 'js_challenge_detected', $errorMessage, [
                'http_status' => (int)($fetch['status'] ?? 0),
                'render_enabled' => ai_env('AI_RENDER_ENDPOINT') !== '',
            ], 'warning', $finalUrl, (string)($page['id'] ?? ''));
            ai_release_page_claim((string)($page['id'] ?? ''), $workerId);
            $processed++;
            continue;
        }
        $parsed = ai_parse_html_page((string)$fetch['html'], $finalUrl, $websiteDomain);
        $links = $parsed['links'];
        usort($links, function ($a, $b) {
            return ai_url_priority((string)$a) <=> ai_url_priority((string)$b);
        });
        $beforeRobotsFilter = count($links);
        $links = array_values(array_filter($links, function ($link) use ($scan) {
            return ai_robots_allows_url((string)$link, (string)($scan['website_url'] ?? ''));
        }));
        if ($beforeRobotsFilter > count($links)) {
            ai_crawl_log($jobId, $customerId, 'robots_blocked', 'Some discovered links were blocked by robots.txt.', [
                'blocked_count' => $beforeRobotsFilter - count($links),
            ], 'info', $finalUrl, (string)($page['id'] ?? ''));
        }

        $remainingSlots = max(0, $maxPages - $knownTotal);
        if ($remainingSlots > 0) {
            $linksPerPage = max(1, (int)ai_env('AI_CRAWL_MAX_LINKS_PER_PAGE', '8'));
            $queuedLinks = ai_enqueue_scan_urls($jobId, $customerId, array_slice($links, 0, min($remainingSlots, $linksPerPage)), $remainingSlots, true);
            $knownTotal += $queuedLinks;
        }

        $cleanText = (string)$parsed['clean_text'];
        ai_save_page_faqs($jobId, $customerId, $finalUrl, $parsed['detected_faqs']);
        ai_save_scanned_page($jobId, $customerId, [
            'url' => $finalUrl,
            'normalized_url' => $finalNormalized,
            'page_title' => (string)$parsed['title'],
            'page_status' => 'fetched',
            'http_status' => (int)$fetch['status'],
            'content_hash' => hash('sha256', $cleanText),
            'clean_text' => $cleanText,
            'summary_json' => null,
            'embedding' => null,
            'ai_error' => '',
            'content_type' => (string)($fetch['content_type'] ?? ''),
            'content_length' => (int)($fetch['content_length'] ?? strlen($cleanText)),
            'discovered_links_count' => count($links),
            'html_preview' => substr(strip_tags((string)$fetch['html']), 0, 1000),
            'fetched_at' => ai_now(),
            'summarized_at' => null
        ]);
        ai_crawl_log($jobId, $customerId, 'page_fetched', 'Page content captured.', [
            'http_status' => (int)$fetch['status'],
            'content_type' => (string)($fetch['content_type'] ?? ''),
            'content_length' => (int)($fetch['content_length'] ?? strlen($cleanText)),
            'clean_text_length' => strlen($cleanText),
            'links_found' => count($links),
        ], 'info', $finalUrl, (string)($page['id'] ?? ''));
        ai_release_page_claim((string)($page['id'] ?? ''), $workerId);
        $processed++;
    }

    usleep(ai_crawl_delay_ms((string)($scan['website_url'] ?? '')) * 1000);
    $counts = ai_scan_job_counts($jobId, $customerId);
    $complete = $counts['pending'] === 0 || $counts['total'] >= $maxPages && $counts['pending'] === 0;
    ai_patch_scan_job($jobId, [
        'status' => $complete ? 'completed' : 'running',
        'pages_scanned' => $counts['scanned'],
        'pages_failed' => $counts['failed'],
        'completed_at' => $complete ? ai_now() : null,
        'updated_at' => ai_now()
    ]);
    if (!$pageLevelClaiming) {
        ai_release_scan_job($jobId, $workerId);
    }
    if ($complete) {
        ai_crawl_log($jobId, $customerId, 'scan_completed', 'Crawler completed scan queue.', $counts, 'info', (string)($scan['website_url'] ?? ''));
    }

    return [
        'success' => true,
        'status' => $complete ? 'completed' : 'running',
        'counts' => $counts,
        'processed' => $processed,
        'active_url' => (string)($activeUrls[0] ?? ''),
        'active_urls' => $activeUrls,
        'error' => ''
    ];
}

function ai_summarize_scan_job_batch(string $jobId, string $customerId, int $batchSize = 2): array {
    $retrySupported = ai_db_supports_retry_columns();
    $endpoint = 'ai_website_pages?select=id,page_status&scan_job_id=eq.' . urlencode($jobId)
        . '&customer_id=eq.' . urlencode($customerId)
        . '&page_status=eq.fetched'
        . '&order=created_at.asc&limit=' . max(1, min(6, $batchSize));
    if ($retrySupported) {
        $endpoint = 'ai_website_pages?select=id,page_status,summary_attempts,summary_next_retry_at&scan_job_id=eq.' . urlencode($jobId)
            . '&customer_id=eq.' . urlencode($customerId)
            . '&page_status=eq.fetched'
            . '&or=(summary_next_retry_at.is.null,summary_next_retry_at.lte.' . urlencode(ai_now()) . ')'
            . '&order=created_at.asc&limit=' . max(1, min(6, $batchSize));
    }
    $pages = ai_safe_rows(supabase('GET', $endpoint));

    $done = 0;
    $failed = 0;
    $deferred = 0;
    foreach ($pages as $page) {
        $result = ai_summarize_scanned_page((string)$page['id'], $customerId);
        if (!empty($result['success'])) {
            $done++;
            if ($retrySupported) {
                supabase('PATCH', 'ai_website_pages?id=eq.' . urlencode((string)$page['id']), [
                    'summary_attempts' => 0,
                    'summary_next_retry_at' => null,
                    'updated_at' => ai_now()
                ]);
            }
        } else {
            if ($retrySupported) {
                $attempts = (int)($page['summary_attempts'] ?? 0) + 1;
                $maxAttempts = max(1, (int)ai_env('AI_SUMMARY_MAX_ATTEMPTS', '3'));
                if ($attempts >= $maxAttempts) {
                    $failed++;
                    supabase('PATCH', 'ai_website_pages?id=eq.' . urlencode((string)$page['id']), [
                        'summary_attempts' => $attempts,
                        'summary_next_retry_at' => ai_seconds_from_now(31536000),
                        'ai_error' => (string)($result['error'] ?? 'Summary failed.'),
                        'updated_at' => ai_now()
                    ]);
                } else {
                    $deferred++;
                    $delay = min(1200, (int)pow(3, max(1, $attempts)) * 30);
                    supabase('PATCH', 'ai_website_pages?id=eq.' . urlencode((string)$page['id']), [
                        'summary_attempts' => $attempts,
                        'summary_next_retry_at' => ai_seconds_from_now($delay),
                        'ai_error' => (string)($result['error'] ?? 'Summary failed.'),
                        'updated_at' => ai_now()
                    ]);
                }
            } else {
                $failed++;
            }
        }
    }

    return [
        'success' => true,
        'summarized' => $done,
        'failed' => $failed,
        'deferred' => $deferred,
        'remaining' => count($pages) >= max(1, min(6, $batchSize)),
    ];
}

function ai_chunk_text(string $text, int $chunkSize = 1800, int $overlap = 220): array {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?: '');
    if ($text === '') {
        return [];
    }
    $chunkSize = max(500, min(4000, $chunkSize));
    $overlap = max(0, min(800, $overlap));
    $chunks = [];
    $offset = 0;
    $length = strlen($text);
    while ($offset < $length && count($chunks) < (int)ai_env('AI_MAX_CHUNKS_PER_PAGE', '24')) {
        $chunk = substr($text, $offset, $chunkSize);
        $lastStop = max(strrpos($chunk, '. '), strrpos($chunk, '? '), strrpos($chunk, '! '));
        if ($lastStop !== false && strlen($chunk) > 800) {
            $chunk = substr($chunk, 0, $lastStop + 1);
        }
        $chunk = trim($chunk);
        if ($chunk !== '') {
            $chunks[] = $chunk;
        }
        $step = max(1, strlen($chunk) - $overlap);
        $offset += $step;
    }
    return $chunks;
}

function ai_ingest_page_chunks(array $page, string $customerId): void {
    if (!ai_db_supports_chunks()) {
        return;
    }
    $pageId = (string)($page['id'] ?? '');
    $cleanText = trim((string)($page['clean_text'] ?? ''));
    if ($pageId === '' || $cleanText === '') {
        return;
    }

    supabase('DELETE', 'ai_website_chunks?page_id=eq.' . urlencode($pageId));
    $chunks = ai_chunk_text($cleanText, (int)ai_env('AI_RAG_CHUNK_SIZE', '1800'), (int)ai_env('AI_RAG_CHUNK_OVERLAP', '220'));
    $rows = [];
    foreach ($chunks as $index => $chunkText) {
        $embedding = [];
        if (ai_is_configured() && ai_env('AI_RAG_EMBED_CHUNKS', '1') !== '0') {
            $embedded = ai_create_embedding(substr($chunkText, 0, 12000));
            $embedding = !empty($embedded['success']) ? $embedded['embedding'] : [];
        }
        $rows[] = [
            'scan_job_id' => (string)($page['scan_job_id'] ?? ''),
            'page_id' => $pageId,
            'customer_id' => $customerId,
            'url' => (string)($page['url'] ?? ''),
            'chunk_index' => $index,
            'chunk_text' => $chunkText,
            'content_hash' => hash('sha256', $chunkText),
            'embedding' => !empty($embedding) ? $embedding : null,
            'updated_at' => ai_now(),
        ];
    }
    if (!empty($rows)) {
        ai_supabase_upsert('ai_website_chunks', $rows, 'page_id,chunk_index');
    }
}

function ai_provider_access_denied(string $error): bool {
    return stripos($error, '403') !== false
        || stripos($error, 'denied access') !== false
        || stripos($error, 'permission') !== false
        || stripos($error, 'forbidden') !== false;
}

function ai_get_page_for_customer(string $pageId, string $customerId): array {
    $rows = ai_safe_rows(supabase(
        'GET',
        'ai_website_pages?select=*&id=eq.' . urlencode($pageId) . '&customer_id=eq.' . urlencode($customerId) . '&limit=1'
    ));
    return $rows[0] ?? [];
}

function ai_summarize_scanned_page(string $pageId, string $customerId): array {
    $page = ai_get_page_for_customer($pageId, $customerId);
    if (empty($page)) {
        return ['success' => false, 'error' => 'Page was not found.'];
    }

    $cleanText = trim((string)($page['clean_text'] ?? ''));
    if ($cleanText === '') {
        supabase('PATCH', 'ai_website_pages?id=eq.' . urlencode($pageId), [
            'page_status' => 'failed',
            'ai_error' => 'No page context was captured.',
            'updated_at' => ai_now()
        ]);
        return ['success' => false, 'error' => 'No page context was captured.'];
    }

    $url = (string)($page['url'] ?? '');
    $title = (string)($page['page_title'] ?? '');
    $summary = ai_summarize_page($url, $title, $cleanText);
    if (empty($summary['success'])) {
        supabase('PATCH', 'ai_website_pages?id=eq.' . urlencode($pageId), [
            'page_status' => 'fetched',
            'ai_error' => (string)($summary['error'] ?? 'Summary failed.'),
            'updated_at' => ai_now()
        ]);
        return ['success' => false, 'error' => (string)($summary['error'] ?? 'Summary failed.')];
    }

    $embeddingText = json_encode($summary['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $cleanText;
    $embedding = ai_create_embedding(substr($embeddingText, 0, 12000));
    $aiErrors = [];
    if (empty($embedding['success'])) {
        $aiErrors[] = (string)($embedding['error'] ?? 'Embedding failed.');
    }

    $pageFaqs = [];
    if (!empty($summary['data']['faq_candidates']) && is_array($summary['data']['faq_candidates'])) {
        foreach ($summary['data']['faq_candidates'] as $faq) {
            $pageFaqs[] = [
                'question' => (string)($faq['question'] ?? ''),
                'answer' => (string)($faq['answer'] ?? ''),
                'source' => 'ai_summary'
            ];
        }
    }
    if (ai_page_looks_like_faq($url, $title, $cleanText)) {
        $pageFaqs = array_merge($pageFaqs, ai_extract_page_faqs($url, $title, $cleanText));
    }
    ai_save_page_faqs((string)$page['scan_job_id'], $customerId, $url, $pageFaqs);

    supabase('PATCH', 'ai_website_pages?id=eq.' . urlencode($pageId), [
        'page_status' => 'summarized',
        'summary_json' => $summary['data'],
        'embedding' => !empty($embedding['success']) ? $embedding['embedding'] : null,
        'ai_error' => implode(' ', array_filter($aiErrors)),
        'summarized_at' => ai_now(),
        'updated_at' => ai_now()
    ]);
    $page['page_status'] = 'summarized';
    $page['summary_json'] = $summary['data'];
    ai_ingest_page_chunks($page, $customerId);

    return ['success' => true, 'summary' => $summary['data'], 'error' => implode(' ', array_filter($aiErrors))];
}

function ai_update_page_context(string $pageId, string $customerId, string $cleanText): array {
    $page = ai_get_page_for_customer($pageId, $customerId);
    if (empty($page)) {
        return ['success' => false, 'error' => 'Page was not found.'];
    }
    $cleanText = trim($cleanText);
    supabase('PATCH', 'ai_website_pages?id=eq.' . urlencode($pageId), [
        'clean_text' => $cleanText,
        'content_hash' => hash('sha256', $cleanText),
        'context_edited' => true,
        'page_status' => 'fetched',
        'summary_json' => null,
        'embedding' => null,
        'summarized_at' => null,
        'updated_at' => ai_now()
    ]);
    return ['success' => true, 'error' => ''];
}

function ai_update_page_summary(string $pageId, string $customerId, string $summaryText): array {
    $page = ai_get_page_for_customer($pageId, $customerId);
    if (empty($page)) {
        return ['success' => false, 'error' => 'Page was not found.'];
    }
    $existing = is_array($page['summary_json'] ?? null) ? $page['summary_json'] : [];
    $existing['summary'] = trim($summaryText);
    $existing['manual_edit'] = true;
    supabase('PATCH', 'ai_website_pages?id=eq.' . urlencode($pageId), [
        'summary_json' => $existing,
        'summary_edited' => true,
        'page_status' => 'summarized',
        'updated_at' => ai_now()
    ]);
    return ['success' => true, 'error' => ''];
}

function ai_update_faq(string $faqId, string $customerId, string $question, string $answer): array {
    $rows = ai_safe_rows(supabase(
        'GET',
        'ai_website_faqs?select=id&customer_id=eq.' . urlencode($customerId) . '&id=eq.' . urlencode($faqId) . '&limit=1'
    ));
    if (empty($rows[0])) {
        return ['success' => false, 'error' => 'FAQ was not found.'];
    }
    supabase('PATCH', 'ai_website_faqs?id=eq.' . urlencode($faqId), [
        'question' => trim($question),
        'answer' => trim($answer),
        'source' => 'manual_edit',
        'updated_at' => ai_now()
    ]);
    return ['success' => true, 'error' => ''];
}

function ai_process_scan_job(string $jobId, string $customerId, string $websiteUrl, string $websiteDomain, int $maxPages = 30): array {
    $maxPages = max(1, min(60, $maxPages));
    ai_patch_scan_job($jobId, [
        'status' => 'running',
        'started_at' => ai_now(),
        'pages_requested' => $maxPages,
        'error_message' => null
    ]);

    $queue = [$websiteUrl];
    foreach (ai_discover_sitemap_urls($websiteUrl, $websiteDomain, $maxPages * 4) as $sitemapPage) {
        $queue[] = $sitemapPage;
    }
    $wwwVariant = ai_add_www_variant($websiteUrl);
    if ($wwwVariant !== '') {
        $queue[] = $wwwVariant;
    }
    $nonWwwVariant = ai_remove_www_variant($websiteUrl);
    if ($nonWwwVariant !== '') {
        $queue[] = $nonWwwVariant;
    }
    $seen = [];
    $attempted = [];
    $failedOnce = [];
    $scanned = 0;
    $failed = 0;
    $aiDisabledReason = '';

    while (!empty($queue) && $scanned < $maxPages) {
        $url = array_shift($queue);
        $normalized = ai_normalize_page_url($url);
        $attemptKey = strtolower($url);
        if ($normalized === '' || isset($attempted[$attemptKey]) || isset($seen[$normalized])) {
            continue;
        }
        $attempted[$attemptKey] = true;

        $fetch = ai_fetch_page($url);
        if (empty($fetch['success'])) {
            $variant = ai_add_www_variant($url) ?: ai_remove_www_variant($url);
            if ($variant !== '' && !isset($attempted[strtolower($variant)]) && empty($failedOnce[$normalized])) {
                $failedOnce[$normalized] = true;
                array_unshift($queue, $variant);
                continue;
            }
            $failed++;
            ai_save_scanned_page($jobId, $customerId, [
                'url' => $url,
                'normalized_url' => $normalized,
                'page_status' => 'failed',
                'http_status' => (int)($fetch['status'] ?? 0),
                'ai_error' => (string)($fetch['error'] ?? 'Fetch failed.'),
                'fetched_at' => ai_now()
            ]);
            continue;
        }

        $finalUrl = (string)($fetch['url'] ?? $url);
        $finalNormalized = ai_normalize_page_url($finalUrl) ?: $normalized;
        $seen[$finalNormalized] = true;

        $parsed = ai_parse_html_page((string)$fetch['html'], $finalUrl, $websiteDomain);
        $links = $parsed['links'];
        usort($links, function ($a, $b) {
            return ai_url_priority((string)$a) <=> ai_url_priority((string)$b);
        });
        foreach ($links as $link) {
            if (!isset($seen[$link]) && count($queue) < $maxPages * 3) {
                $queue[] = $link;
            }
        }

        $cleanText = (string)$parsed['clean_text'];

        $pageFaqs = $parsed['detected_faqs'];
        ai_save_page_faqs($jobId, $customerId, $finalUrl, $pageFaqs);

        ai_save_scanned_page($jobId, $customerId, [
            'url' => $finalUrl,
            'normalized_url' => $finalNormalized,
            'page_title' => (string)$parsed['title'],
            'page_status' => 'fetched',
            'http_status' => (int)$fetch['status'],
            'content_hash' => hash('sha256', $cleanText),
            'clean_text' => $cleanText,
            'summary_json' => null,
            'embedding' => null,
            'ai_error' => '',
            'content_type' => (string)($fetch['content_type'] ?? ''),
            'content_length' => (int)($fetch['content_length'] ?? strlen($cleanText)),
            'discovered_links_count' => count($links),
            'html_preview' => substr(strip_tags((string)$fetch['html']), 0, 1000),
            'fetched_at' => ai_now(),
            'summarized_at' => null
        ]);

        $scanned++;
    }

    $status = $scanned > 0 ? 'completed' : 'failed';
    ai_patch_scan_job($jobId, [
        'status' => $status,
        'pages_scanned' => $scanned,
        'pages_failed' => $failed,
        'completed_at' => ai_now(),
        'error_message' => $status === 'failed' ? 'No pages could be scanned.' : null
    ]);

    return [
        'success' => $status === 'completed',
        'pages_scanned' => $scanned,
        'pages_failed' => $failed,
        'status' => $status,
        'ai_error' => ''
    ];
}

function ai_http_json(string $url, array $headers, array $payload, int $timeout = 60): array {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", array_merge($headers, ['Content-Type: application/json'])),
            'content' => json_encode($payload),
            'ignore_errors' => true,
            'timeout' => $timeout,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('{HTTP/\S*\s(\d{3})}', $http_response_header[0], $match)) {
        $status = (int)($match[1] ?? 0);
    }

    $data = json_decode((string)$raw, true);

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'data' => is_array($data) ? $data : null,
        'raw' => (string)$raw,
    ];
}

function ai_generate_text(string $systemPrompt, string $userPrompt, array $options = []): array {
    $config = ai_config();
    if (!ai_is_configured()) {
        return [
            'success' => false,
            'text' => '',
            'error' => 'AI service is not configured. Set AI_PROVIDER, AI_MODEL, and AI_API_KEY.',
            'raw' => null,
        ];
    }

    if ($config['provider'] === 'gemini') {
        return ai_generate_text_gemini($config, $systemPrompt, $userPrompt, $options);
    }

    return ai_generate_text_openai_compatible($config, $systemPrompt, $userPrompt, $options);
}

function ai_generate_text_gemini(array $config, string $systemPrompt, string $userPrompt, array $options = []): array {
    $url = $config['base_url']
        . '/models/' . rawurlencode($config['model'])
        . ':generateContent?key=' . urlencode($config['api_key']);

    $generationConfig = [
        'temperature' => (float)($options['temperature'] ?? 0.2),
        'maxOutputTokens' => (int)($options['max_output_tokens'] ?? 2048),
    ];

    if (!empty($options['json'])) {
        $generationConfig['responseMimeType'] = 'application/json';
    }

    $response = ai_http_json($url, [], [
        'systemInstruction' => [
            'parts' => [
                ['text' => $systemPrompt],
            ],
        ],
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $userPrompt],
                ],
            ],
        ],
        'generationConfig' => $generationConfig,
    ], $config['timeout']);

    if (!$response['ok']) {
        return [
            'success' => false,
            'text' => '',
            'error' => ai_response_error($response),
            'raw' => $response,
        ];
    }

    $text = (string)($response['data']['candidates'][0]['content']['parts'][0]['text'] ?? '');

    return [
        'success' => $text !== '',
        'text' => $text,
        'error' => $text === '' ? 'AI response did not include text.' : '',
        'raw' => $response,
    ];
}

function ai_generate_text_openai_compatible(array $config, string $systemPrompt, string $userPrompt, array $options = []): array {
    $url = $config['base_url'] . '/chat/completions';
    $payload = [
        'model' => $config['model'],
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'temperature' => (float)($options['temperature'] ?? 0.2),
        'max_tokens' => (int)($options['max_output_tokens'] ?? 2048),
    ];

    if (!empty($options['json'])) {
        $payload['response_format'] = ['type' => 'json_object'];
    }

    $response = ai_http_json($url, [
        'Authorization: Bearer ' . $config['api_key'],
    ], $payload, $config['timeout']);

    if (!$response['ok']) {
        return [
            'success' => false,
            'text' => '',
            'error' => ai_response_error($response),
            'raw' => $response,
        ];
    }

    $text = (string)($response['data']['choices'][0]['message']['content'] ?? '');

    return [
        'success' => $text !== '',
        'text' => $text,
        'error' => $text === '' ? 'AI response did not include text.' : '',
        'raw' => $response,
    ];
}

function ai_response_error(array $response): string {
    $data = $response['data'] ?? [];
    $message = '';

    if (is_array($data)) {
        $message = (string)($data['error']['message'] ?? $data['message'] ?? '');
    }

    if ($message === '') {
        $message = $response['raw'] !== '' ? $response['raw'] : 'Unknown AI provider error.';
    }

    return 'AI provider error (' . (int)$response['status'] . '): ' . $message;
}

function ai_decode_json_result(array $result): array {
    if (empty($result['success'])) {
        return [
            'success' => false,
            'data' => null,
            'error' => (string)($result['error'] ?? 'AI request failed.'),
            'raw_text' => (string)($result['text'] ?? ''),
        ];
    }

    $text = trim((string)$result['text']);
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?: $text;
    $text = preg_replace('/\s*```$/', '', $text) ?: $text;
    $decoded = json_decode($text, true);

    if (!is_array($decoded)) {
        $objectStart = strpos($text, '{');
        $objectEnd = strrpos($text, '}');
        $arrayStart = strpos($text, '[');
        $arrayEnd = strrpos($text, ']');

        $candidates = [];
        if ($objectStart !== false && $objectEnd !== false && $objectEnd > $objectStart) {
            $candidates[] = substr($text, $objectStart, $objectEnd - $objectStart + 1);
        }
        if ($arrayStart !== false && $arrayEnd !== false && $arrayEnd > $arrayStart) {
            $candidates[] = substr($text, $arrayStart, $arrayEnd - $arrayStart + 1);
        }

        foreach ($candidates as $candidate) {
            $candidateDecoded = json_decode($candidate, true);
            if (is_array($candidateDecoded)) {
                $decoded = $candidateDecoded;
                break;
            }
        }
    }

    $jsonError = json_last_error_msg();

    return [
        'success' => is_array($decoded),
        'data' => is_array($decoded) ? $decoded : null,
        'error' => is_array($decoded) ? '' : 'AI response was not valid JSON (' . $jsonError . '): ' . substr($text, 0, 240),
        'raw_text' => $text,
    ];
}

function ai_fallback_page_summary(string $url, string $title, string $cleanText, string $error = ''): array {
    $cleanText = preg_replace('/\s+/', ' ', trim($cleanText)) ?: '';
    $sentences = preg_split('/(?<=[.!?])\s+/', $cleanText) ?: [];
    $summary = trim(implode(' ', array_slice(array_filter($sentences), 0, 3)));
    if ($summary === '') {
        $summary = substr($cleanText, 0, 700);
    }

    return [
        'success' => true,
        'data' => [
            'url' => $url,
            'page_title' => $title,
            'page_type' => 'other',
            'summary' => substr($summary, 0, 900),
            'key_facts' => array_slice(array_values(array_filter($sentences)), 0, 5),
            'services' => [],
            'pricing_info' => [],
            'locations' => [],
            'contact_info' => [
                'emails' => [],
                'phones' => [],
                'addresses' => []
            ],
            'faq_candidates' => [],
            'target_audience' => [],
            'entities' => [],
            'fallback_used' => true,
            'fallback_reason' => $error
        ],
        'error' => '',
        'raw_text' => ''
    ];
}

function ai_summarize_page(string $url, string $title, string $cleanText): array {
    $cleanText = trim($cleanText);
    if ($cleanText === '') {
        return [
            'success' => false,
            'data' => null,
            'error' => 'Page text is empty.',
            'raw_text' => '',
        ];
    }

    $systemPrompt = 'You extract chatbot-ready business knowledge from website pages. The output will be used as context for a customer support chatbot, so preserve answerable facts, service details, rules, prices, eligibility, locations, timings, contact details, processes, and limitations. Return exactly one valid JSON object. Do not use markdown fences, comments, prose, or trailing commas.';
    $userPrompt = "Analyze this website page and return exactly this JSON object shape. Capture enough detail for a chatbot to answer visitor questions accurately. Keep each string concise but do not omit important facts. Maximum 12 items per array, maximum 20 FAQ candidates, and no value longer than 900 characters.\n"
        . "{\n"
        . "  \"url\": \"string\",\n"
        . "  \"page_title\": \"string\",\n"
        . "  \"page_type\": \"home|about|services|pricing|contact|faq|blog|other\",\n"
        . "  \"summary\": \"chatbot-ready summary with the most important facts from this page\",\n"
        . "  \"key_facts\": [\"string\"],\n"
        . "  \"services\": [\"string\"],\n"
        . "  \"pricing_info\": [\"string\"],\n"
        . "  \"locations\": [\"string\"],\n"
        . "  \"timings\": [\"string\"],\n"
        . "  \"policies\": [\"string\"],\n"
        . "  \"steps_or_processes\": [\"string\"],\n"
        . "  \"requirements_or_eligibility\": [\"string\"],\n"
        . "  \"contact_info\": {\"emails\": [\"string\"], \"phones\": [\"string\"], \"addresses\": [\"string\"]},\n"
        . "  \"faq_candidates\": [{\"question\": \"string\", \"answer\": \"string\"}],\n"
        . "  \"target_audience\": [\"string\"],\n"
        . "  \"entities\": [\"string\"],\n"
        . "  \"answer_boundaries\": [\"things the chatbot should not claim beyond this page\"]\n"
        . "}\n\n"
        . "URL: {$url}\n"
        . "Title: {$title}\n\n"
        . "Page text:\n" . substr($cleanText, 0, 30000);

    $decoded = ai_decode_json_result(ai_generate_text($systemPrompt, $userPrompt, [
        'json' => true,
        'temperature' => 0.1,
        'max_output_tokens' => 8192,
    ]));

    if (empty($decoded['success'])) {
        return ai_fallback_page_summary($url, $title, $cleanText, (string)$decoded['error']);
    }

    return $decoded;
}

function ai_answer_with_context(string $question, string $context): array {
    $systemPrompt = 'You answer customer questions using only the supplied website context. If the answer is not in the context, say that the information is not available on the website.';
    $userPrompt = "Website context:\n{$context}\n\nQuestion: {$question}";

    return ai_generate_text($systemPrompt, $userPrompt, [
        'temperature' => 0.2,
        'max_output_tokens' => 1200,
    ]);
}

function ai_create_embedding(string $text): array {
    $config = ai_config();
    if (!ai_is_configured()) {
        return [
            'success' => false,
            'embedding' => [],
            'error' => 'AI service is not configured. Set AI_PROVIDER, AI_MODEL, and AI_API_KEY.',
            'raw' => null,
        ];
    }

    if ($config['provider'] === 'gemini') {
        $url = $config['base_url']
            . '/models/' . rawurlencode($config['embedding_model'])
            . ':embedContent?key=' . urlencode($config['api_key']);

        $response = ai_http_json($url, [], [
            'model' => 'models/' . $config['embedding_model'],
            'content' => [
                'parts' => [
                    ['text' => $text],
                ],
            ],
        ], $config['timeout']);

        $embedding = $response['data']['embedding']['values'] ?? [];
    } else {
        $response = ai_http_json($config['base_url'] . '/embeddings', [
            'Authorization: Bearer ' . $config['api_key'],
        ], [
            'model' => $config['embedding_model'],
            'input' => $text,
        ], $config['timeout']);

        $embedding = $response['data']['data'][0]['embedding'] ?? [];
    }

    if (!$response['ok']) {
        return [
            'success' => false,
            'embedding' => [],
            'error' => ai_response_error($response),
            'raw' => $response,
        ];
    }

    return [
        'success' => is_array($embedding) && !empty($embedding),
        'embedding' => is_array($embedding) ? $embedding : [],
        'error' => is_array($embedding) && !empty($embedding) ? '' : 'AI provider did not return an embedding.',
        'raw' => $response,
    ];
}
