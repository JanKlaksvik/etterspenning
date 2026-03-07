<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/http.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/debug-log.php';

apply_cors();
require_method('GET');

function db_health_check(): array
{
    $pdoMysqlLoaded = extension_loaded('pdo_mysql');
    if (!$pdoMysqlLoaded) {
        return [
            'pdo_mysql' => false,
            'db' => false,
        ];
    }

    try {
        db()->query('SELECT 1');
        return [
            'pdo_mysql' => true,
            'db' => true,
        ];
    } catch (Throwable $e) {
        error_log('backend/public/api/health.php db check failed: ' . $e->getMessage());
        esp_debug_log('health.db.failed', [
            'message' => $e->getMessage(),
            'type' => get_class($e),
        ]);
        return [
            'pdo_mysql' => true,
            'db' => false,
        ];
    }
}

function session_health_check(): bool
{
    try {
        start_secure_session();
        if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
            return false;
        }
        $_SESSION['esp_health_probe'] = time();
        return true;
    } catch (Throwable $e) {
        error_log('backend/public/api/health.php session check failed: ' . $e->getMessage());
        esp_debug_log('health.session.failed', [
            'message' => $e->getMessage(),
            'type' => get_class($e),
            'save_path' => (string) ini_get('session.save_path'),
            'session_id' => session_id(),
        ]);
        return false;
    }
}

json_response(200, [
    'ok' => true,
    'service' => 'etterspenning-backend',
    'timestamp' => gmdate('c'),
    'checks' => array_merge(
        db_health_check(),
        ['session' => session_health_check()]
    ),
]);
