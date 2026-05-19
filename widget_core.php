<?php
require_once __DIR__ . '/core.php';

function widget_json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function widget_get_json(): array {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function widget_safe_rows(array $response): array {
    $data = $response['data'] ?? null;
    return is_array($data) ? $data : [];
}

function widget_bool($value, bool $fallback = false): bool {
    if ($value === null || $value === '') {
        return $fallback;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}
