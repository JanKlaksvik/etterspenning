<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/http.php';
require_once __DIR__ . '/../../lib/auth.php';

apply_cors();
require_method('GET');

$session = refresh_session_from_db();
if ($session === null) {
    if (!empty($_COOKIE['esp_session'])) {
        $cookieValue = (string) ($_COOKIE['esp_session'] ?? '');
        esp_debug_log('me.auth.missing_with_cookie', [
            'cookie_present' => true,
            'cookie_length' => strlen($cookieValue),
            'session_id' => session_id(),
            'save_path' => (string) ini_get('session.save_path'),
            'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
        ]);
    }
    json_response(200, [
        'ok' => true,
        'authenticated' => false,
    ]);
}

json_response(200, [
    'ok' => true,
    'authenticated' => true,
    'session' => $session,
]);
