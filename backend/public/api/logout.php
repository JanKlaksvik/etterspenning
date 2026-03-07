<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/http.php';
require_once __DIR__ . '/../../lib/auth.php';

apply_cors();
require_method('POST');

$auth = session_user();
if ($auth !== null) {
    audit_log(
        (int) $auth['userId'],
        'auth.logout',
        'user',
        (string) $auth['userId']
    );
}

logout_session();

json_response(200, [
    'ok' => true,
    'authenticated' => false,
]);
