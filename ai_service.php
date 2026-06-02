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

function ai_now(): string {
    return gmdate('Y-m-d\TH:i:s\Z');
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

function ai_patch_scan_job(string $jobId, array $payload): void {
    $payload['updated_at'] = ai_now();
    supabase('PATCH', 'ai_scan_jobs?id=eq.' . urlencode($jobId), $payload);
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
    $host = strtolower((string)$parts['host']);
    $path = (string)($parts['path'] ?? '/');
    $path = $path === '' ? '/' : $path;
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return rtrim($scheme . '://' . $host . $path . $query, '/');
}

function ai_should_scan_url(string $url, string $websiteDomain): bool {
    if ($url === '' || ai_host_from_value($url) !== $websiteDomain) {
        return false;
    }
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
    return !preg_match('/\.(?:jpg|jpeg|png|gif|webp|svg|ico|css|js|pdf|zip|rar|7z|mp4|mp3|avi|mov|woff|woff2|ttf|eot)$/i', $path);
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
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: VaniAI-Scanner/1.0\r\nAccept: text/html,application/xhtml+xml\r\n",
            'ignore_errors' => true,
            'timeout' => 20,
            'max_redirects' => 5,
        ],
    ]);
    $html = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('{HTTP/\S*\s(\d{3})}', $http_response_header[0], $match)) {
        $status = (int)($match[1] ?? 0);
    }
    if ($html === false || $status < 200 || $status >= 400) {
        return ['success' => false, 'status' => $status, 'html' => '', 'error' => 'Could not fetch page.'];
    }
    return ['success' => true, 'status' => $status, 'html' => substr((string)$html, 0, 900000), 'error' => ''];
}

function ai_parse_html_page(string $html, string $baseUrl, string $websiteDomain, int $linkLimit = 20): array {
    $title = '';
    $links = [];
    $cleanText = '';

    if (class_exists(DOMDocument::class)) {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $titleNode = $dom->getElementsByTagName('title')->item(0);
        $title = $titleNode ? trim($titleNode->textContent) : '';

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
    } else {
        $withoutScripts = preg_replace('/<(script|style|noscript|svg)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?: $html;
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match)) {
            $title = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES, 'UTF-8'));
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

    return [
        'title' => substr($title, 0, 500),
        'clean_text' => substr($cleanText, 0, 120000),
        'links' => array_keys($links),
    ];
}

function ai_save_scanned_page(string $jobId, string $customerId, array $payload): void {
    $normalizedUrl = (string)$payload['normalized_url'];
    $existing = ai_safe_rows(supabase(
        'GET',
        'ai_website_pages?select=id&customer_id=eq.' . urlencode($customerId) . '&normalized_url=eq.' . urlencode($normalizedUrl) . '&limit=1'
    ));
    $payload['scan_job_id'] = $jobId;
    $payload['customer_id'] = $customerId;
    $payload['updated_at'] = ai_now();

    if (!empty($existing[0]['id'])) {
        supabase('PATCH', 'ai_website_pages?id=eq.' . urlencode((string)$existing[0]['id']), $payload);
    } else {
        supabase('POST', 'ai_website_pages', [$payload]);
    }
}

function ai_provider_access_denied(string $error): bool {
    return stripos($error, '403') !== false
        || stripos($error, 'denied access') !== false
        || stripos($error, 'permission') !== false
        || stripos($error, 'forbidden') !== false;
}

function ai_process_scan_job(string $jobId, string $customerId, string $websiteUrl, string $websiteDomain, int $maxPages = 5): array {
    $maxPages = max(1, min(10, $maxPages));
    ai_patch_scan_job($jobId, [
        'status' => 'running',
        'started_at' => ai_now(),
        'pages_requested' => $maxPages,
        'error_message' => null
    ]);

    $queue = [ai_normalize_page_url($websiteUrl) ?: $websiteUrl];
    $seen = [];
    $scanned = 0;
    $failed = 0;
    $aiDisabledReason = '';

    while (!empty($queue) && $scanned < $maxPages) {
        $url = array_shift($queue);
        $normalized = ai_normalize_page_url($url);
        if ($normalized === '' || isset($seen[$normalized])) {
            continue;
        }
        $seen[$normalized] = true;

        $fetch = ai_fetch_page($url);
        if (empty($fetch['success'])) {
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

        $parsed = ai_parse_html_page((string)$fetch['html'], $url, $websiteDomain);
        foreach ($parsed['links'] as $link) {
            if (!isset($seen[$link]) && count($queue) < $maxPages * 3) {
                $queue[] = $link;
            }
        }

        $cleanText = (string)$parsed['clean_text'];
        $summary = [
            'success' => false,
            'data' => null,
            'error' => $aiDisabledReason,
            'raw_text' => ''
        ];
        $embedding = ['success' => false, 'embedding' => [], 'error' => ''];
        if ($aiDisabledReason === '') {
            $summary = ai_summarize_page($url, (string)$parsed['title'], $cleanText);
            if (!empty($summary['success'])) {
                $embeddingText = json_encode($summary['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $cleanText;
                $embedding = ai_create_embedding(substr($embeddingText, 0, 12000));
                if (empty($embedding['success']) && ai_provider_access_denied((string)($embedding['error'] ?? ''))) {
                    $aiDisabledReason = (string)$embedding['error'];
                }
            } elseif (ai_provider_access_denied((string)($summary['error'] ?? ''))) {
                $aiDisabledReason = (string)$summary['error'];
            }
        }

        $aiErrors = [];
        if (empty($summary['success'])) {
            $aiErrors[] = (string)($summary['error'] ?? 'Page summary failed.');
        }
        if (!empty($summary['success']) && empty($embedding['success'])) {
            $aiErrors[] = (string)($embedding['error'] ?? 'Embedding failed.');
        }

        ai_save_scanned_page($jobId, $customerId, [
            'url' => $url,
            'normalized_url' => $normalized,
            'page_title' => (string)$parsed['title'],
            'page_status' => empty($summary['success']) ? 'fetched' : 'summarized',
            'http_status' => (int)$fetch['status'],
            'content_hash' => hash('sha256', $cleanText),
            'clean_text' => $cleanText,
            'summary_json' => !empty($summary['success']) ? $summary['data'] : null,
            'embedding' => !empty($embedding['success']) ? $embedding['embedding'] : null,
            'ai_error' => implode(' ', array_filter($aiErrors)),
            'fetched_at' => ai_now(),
            'summarized_at' => !empty($summary['success']) ? ai_now() : null
        ]);

        $scanned++;
    }

    $status = $scanned > 0 ? 'completed' : 'failed';
    ai_patch_scan_job($jobId, [
        'status' => $status,
        'pages_scanned' => $scanned,
        'pages_failed' => $failed,
        'completed_at' => ai_now(),
        'error_message' => $status === 'failed' ? 'No pages could be scanned.' : ($aiDisabledReason !== '' ? $aiDisabledReason : null)
    ]);

    return [
        'success' => $status === 'completed',
        'pages_scanned' => $scanned,
        'pages_failed' => $failed,
        'status' => $status,
        'ai_error' => $aiDisabledReason
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
    $decoded = json_decode($text, true);

    if (!is_array($decoded)) {
        $jsonStart = strpos($text, '{');
        $jsonEnd = strrpos($text, '}');
        if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
            $decoded = json_decode(substr($text, $jsonStart, $jsonEnd - $jsonStart + 1), true);
        }
    }

    return [
        'success' => is_array($decoded),
        'data' => is_array($decoded) ? $decoded : null,
        'error' => is_array($decoded) ? '' : 'AI response was not valid JSON.',
        'raw_text' => $text,
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

    $systemPrompt = 'You extract structured business knowledge from website pages. Return only valid JSON.';
    $userPrompt = "Analyze this website page and return JSON with these keys: url, page_title, page_type, summary, key_facts, services, pricing_info, locations, contact_info, faq_candidates, target_audience, entities.\n\n"
        . "URL: {$url}\n"
        . "Title: {$title}\n\n"
        . "Page text:\n" . substr($cleanText, 0, 30000);

    return ai_decode_json_result(ai_generate_text($systemPrompt, $userPrompt, [
        'json' => true,
        'temperature' => 0.1,
        'max_output_tokens' => 2500,
    ]));
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
