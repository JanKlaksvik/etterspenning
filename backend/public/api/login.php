<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/http.php';
require_once __DIR__ . '/../../lib/auth.php';

apply_cors();
require_method('POST');

$input = json_input();
if ($input === []) {
    $input = $_POST;
}

$email = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['password'] ?? '');

if ($email === '' || $password === '') {
    esp_debug_log('login.input.invalid', [
        'email' => $email,
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
    ]);
    json_error(422, 'Email and password are required');
}

try {
    ensure_users_role_column();

    $sql =
        'SELECT u.id, u.company_id, u.full_name, u.email, u.password_hash, u.role, u.is_active,
                c.name AS company_name, c.tier' .
        company_logo_select_sql('c') .
        ' FROM users u
          INNER JOIN companies c ON c.id = u.company_id
          WHERE u.email = :email
          LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();

    if (!$row || (int) $row['is_active'] !== 1 || !password_verify($password, (string) $row['password_hash'])) {
        esp_debug_log('login.auth.failed', [
            'email' => $email,
            'user_found' => (bool) $row,
            'is_active' => $row ? (int) ($row['is_active'] ?? 0) : null,
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
        json_error(401, 'Invalid email or password');
    }

    $session = login_with_user_row($row);
    esp_debug_log('login.auth.success', [
        'email' => $email,
        'user_id' => (int) ($session['userId'] ?? 0),
        'company_id' => (int) ($session['companyId'] ?? 0),
        'session_id' => session_id(),
    ]);

    audit_log(
        (int) $session['userId'],
        'auth.login',
        'user',
        (string) $session['userId'],
        ['email' => $email]
    );

    json_response(200, [
        'ok' => true,
        'authenticated' => true,
        'session' => $session,
    ]);
} catch (Throwable $e) {
    error_log('backend/public/api/login.php failed: ' . $e->getMessage());
    esp_debug_log('login.exception', [
        'email' => $email,
        'message' => $e->getMessage(),
        'type' => get_class($e),
    ]);
    json_error(500, 'Login failed');
}
