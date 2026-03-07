<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function apply_cors(): void
{
    $cfg = app_config();
    $allowedOrigin = trim((string) $cfg['cors_origin']);

    if ($allowedOrigin !== '') {
        $requestOrigin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($requestOrigin === $allowedOrigin) {
            header('Access-Control-Allow-Origin: ' . $allowedOrigin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }
    }

    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function require_method(string $method): void
{
    $actual = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($actual !== strtoupper($method)) {
        json_error(405, 'Method not allowed');
    }
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    return $decoded;
}

function json_response(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(int $status, string $message, array $extra = []): void
{
    json_response($status, array_merge([
        'ok' => false,
        'error' => $message,
    ], $extra));
}
