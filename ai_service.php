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
    $host = ai_strip_www((string)$parts['host']);
    $path = (string)($parts['path'] ?? '/');
    $path = $path === '' ? '/' : $path;
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
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

function ai_should_scan_url(string $url, string $websiteDomain): bool {
    if ($url === '' || ai_host_from_value($url) !== $websiteDomain) {
        return false;
    }
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
    return !preg_match('/\.(?:jpg|jpeg|png|gif|webp|svg|ico|css|js|pdf|zip|rar|7z|mp4|mp3|avi|mov|woff|woff2|ttf|eot)$/i', $path);
}

function ai_url_priority(string $url): int {
    $value = strtolower($url);
    if (preg_match('/(faq|faqs|frequently-asked|questions|help|support)/', $value)) {
        return 0;
    }
    if (preg_match('/(about|service|course|program|pricing|contact)/', $value)) {
        return 1;
    }
    return 2;
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
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
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
            return ['success' => false, 'status' => $status, 'html' => '', 'url' => $finalUrl, 'content_type' => $contentType, 'content_length' => strlen((string)$html), 'error' => 'URL did not return HTML content.'];
        }
        return ['success' => true, 'status' => $status, 'html' => substr((string)$html, 0, 1200000), 'url' => $finalUrl, 'content_type' => $contentType, 'content_length' => strlen((string)$html), 'error' => ''];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
            'timeout' => 25,
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
    return ['success' => true, 'status' => $status, 'html' => substr((string)$html, 0, 1200000), 'url' => $finalUrl, 'content_type' => '', 'content_length' => strlen((string)$html), 'error' => ''];
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
        return ['success' => $body !== false && $status >= 200 && $status < 400, 'status' => $status, 'body' => (string)$body];
    }

    $context = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true, 'header' => "User-Agent: VaniAI-Scanner/1.0\r\n"]]);
    $body = @file_get_contents($url, false, $context);
    return ['success' => $body !== false, 'status' => $body !== false ? 200 : 0, 'body' => (string)$body];
}

function ai_sitemap_candidates(string $websiteUrl): array {
    $parts = parse_url($websiteUrl);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return [];
    }
    $root = strtolower((string)$parts['scheme']) . '://' . (string)$parts['host'];
    return [
        $root . '/sitemap.xml',
        $root . '/sitemap_index.xml',
        $root . '/robots.txt'
    ];
}

function ai_urls_from_sitemap_xml(string $xml, string $websiteDomain, int $limit = 120): array {
    $urls = [];
    if (preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $xml, $matches)) {
        foreach ($matches[1] as $loc) {
            $url = trim(html_entity_decode((string)$loc, ENT_QUOTES, 'UTF-8'));
            if (ai_should_scan_url($url, $websiteDomain)) {
                $urls[ai_normalize_page_url($url) ?: $url] = $url;
            }
            if (count($urls) >= $limit) {
                break;
            }
        }
    }
    return array_values($urls);
}

function ai_discover_sitemap_urls(string $websiteUrl, string $websiteDomain, int $limit = 120): array {
    $sitemapUrls = [];
    $pageUrls = [];

    foreach (ai_sitemap_candidates($websiteUrl) as $candidate) {
        $raw = ai_fetch_raw_url($candidate);
        if (empty($raw['success']) || trim($raw['body']) === '') {
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
        foreach (ai_urls_from_sitemap_xml($raw['body'], $websiteDomain, $limit) as $url) {
            $pageUrls[ai_normalize_page_url($url) ?: $url] = $url;
        }
    }

    foreach (array_unique($sitemapUrls) as $sitemapUrl) {
        $raw = ai_fetch_raw_url($sitemapUrl);
        if (empty($raw['success'])) {
            continue;
        }
        foreach (ai_urls_from_sitemap_xml($raw['body'], $websiteDomain, $limit) as $url) {
            $pageUrls[ai_normalize_page_url($url) ?: $url] = $url;
            if (count($pageUrls) >= $limit) {
                break 2;
            }
        }
    }

    $urls = array_values($pageUrls);
    usort($urls, function ($a, $b) {
        return ai_url_priority((string)$a) <=> ai_url_priority((string)$b);
    });
    return array_slice($urls, 0, $limit);
}

function ai_parse_html_page(string $html, string $baseUrl, string $websiteDomain, int $linkLimit = 80): array {
    $title = '';
    $links = [];
    $cleanText = '';
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
    foreach (ai_dedupe_faqs($faqs) as $faq) {
        $question = (string)$faq['question'];
        $payload = [
            'scan_job_id' => $jobId,
            'customer_id' => $customerId,
            'page_url' => $pageUrl,
            'question' => $question,
            'answer' => (string)$faq['answer'],
            'source' => (string)($faq['source'] ?? 'ai'),
            'updated_at' => ai_now()
        ];
        $existing = ai_safe_rows(supabase(
            'GET',
            'ai_website_faqs?select=id&customer_id=eq.' . urlencode($customerId)
                . '&question=eq.' . urlencode($question)
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
    $userPrompt = "Analyze this website page and return exactly this JSON object shape. Capture enough detail for a chatbot to answer visitor questions accurately. Keep each string concise but do not omit important facts. Maximum 12 items per array, maximum 8 FAQ candidates, and no value longer than 900 characters.\n"
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
