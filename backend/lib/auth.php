<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/debug-log.php';

const ADMIN_LOGIN_EMAIL = 'admin@example.com';
const USER_ROLE_ADMIN = 'admin';
const USER_ROLE_PROJECT_MANAGER = 'project_manager';
const USER_ROLE_ONSITE_USER = 'onsite_user';
const USER_ROLE_LEGACY_USER = 'user';

function normalize_tier(string $tier): string
{
    return $tier === 'level2' ? 'level2' : 'level1';
}

function normalize_login_email(string $email): string
{
    return strtolower(trim($email));
}

function normalize_user_role(string $role, string $email = ''): string
{
    $normalizedEmail = normalize_login_email($email);
    if ($normalizedEmail === ADMIN_LOGIN_EMAIL) {
        return USER_ROLE_ADMIN;
    }

    $candidate = strtolower(trim($role));
    if ($candidate === USER_ROLE_ONSITE_USER) {
        return USER_ROLE_ONSITE_USER;
    }

    if ($candidate === USER_ROLE_PROJECT_MANAGER || $candidate === USER_ROLE_LEGACY_USER || $candidate === '') {
        return USER_ROLE_PROJECT_MANAGER;
    }

    return USER_ROLE_PROJECT_MANAGER;
}

function ensure_users_role_column(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    try {
        db()->exec(
            "ALTER TABLE users
             MODIFY COLUMN role ENUM('admin','project_manager','onsite_user','user')
             NOT NULL DEFAULT 'project_manager'"
        );
    } catch (Throwable $e) {
        // Keep backward compatibility if ALTER is not allowed on host.
    }

    $ensured = true;
}

function company_logo_column_exists(): bool
{
    try {
        $stmt = db()->query("SHOW COLUMNS FROM companies LIKE 'logo_data'");
        return (bool) ($stmt && $stmt->fetch());
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_company_logo_column(): bool
{
    if (company_logo_column_exists()) {
        return true;
    }

    try {
        db()->exec("ALTER TABLE companies ADD COLUMN logo_data MEDIUMTEXT NULL AFTER tier");
    } catch (Throwable $e) {
        // Ignore errors and fallback to no-logo mode if schema cannot be changed.
    }

    try {
        $stmt = db()->query("SHOW COLUMNS FROM companies LIKE 'logo_data'");
        return (bool) ($stmt && $stmt->fetch());
    } catch (Throwable $e) {
        return false;
    }
}

function company_logo_select_sql(string $companyAlias = 'c'): string
{
    if (company_logo_column_exists()) {
        return ', ' . $companyAlias . '.logo_data AS company_logo';
    }

    return ", '' AS company_logo";
}

function tier_label(string $tier): string
{
    return normalize_tier($tier) === 'level2' ? 'Level 2' : 'Level 1';
}

function modules_for_role(string $role, string $tier): array
{
    $normalizedRole = normalize_user_role($role);
    if ($normalizedRole === USER_ROLE_ONSITE_USER) {
        return [
            'projectHandling' => false,
            'grouting' => true,
            'stressing' => true,
            'jackData' => true,
            'jackControl' => false,
        ];
    }

    // Project managers and system admin keep full module scope.
    return [
        'projectHandling' => true,
        'grouting' => true,
        'stressing' => true,
        'jackData' => true,
        'jackControl' => true,
    ];
}

function inferred_cookie_domain(): string
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    if ($host === '') {
        return '';
    }

    // Share auth across apex + subdomains for production domain.
    if ($host === 'etterspenning.no' || substr($host, -17) === '.etterspenning.no') {
        return '.etterspenning.no';
    }

    return '';
}

function request_host_without_port(): string
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    return trim($host);
}

function normalize_cookie_domain_value(string $candidate): string
{
    $domain = strtolower(trim($candidate));
    if ($domain === '') {
        return '';
    }

    // Reject obvious invalid values (scheme/path/whitespace/IPs/single-label hostnames).
    if (strpos($domain, '://') !== false || strpos($domain, '/') !== false || preg_match('/\s/', $domain)) {
        return '';
    }

    $root = ltrim($domain, '.');
    if ($root === '') {
        return '';
    }

    if (filter_var($root, FILTER_VALIDATE_IP)) {
        return '';
    }

    if (strpos($root, '.') === false) {
        return '';
    }

    return ($domain[0] ?? '') === '.' ? ('.' . $root) : $root;
}

function cookie_domain_matches_host(string $cookieDomain, string $host): bool
{
    $normalizedHost = strtolower(trim($host));
    $root = ltrim(strtolower(trim($cookieDomain)), '.');

    if ($normalizedHost === '' || $root === '') {
        return false;
    }

    if ($normalizedHost === $root) {
        return true;
    }

    return substr($normalizedHost, -strlen('.' . $root)) === ('.' . $root);
}

function session_storage_path_from_ini(string $rawPath): string
{
    $path = trim($rawPath);
    if ($path === '') {
        return '';
    }

    // Some setups use "N;/path" or "N;MODE;/path".
    $parts = explode(';', $path);
    $candidate = trim((string) end($parts));
    return $candidate !== '' ? $candidate : $path;
}

function ensure_writable_directory(string $path): bool
{
    if ($path === '') {
        return false;
    }

    if (!is_dir($path)) {
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            return false;
        }
    }

    if (!is_writable($path)) {
        return false;
    }

    $probeFile = @tempnam($path, 'esp_');
    if ($probeFile === false) {
        return false;
    }

    $targetDir = realpath($path);
    $probeDir = realpath(dirname($probeFile));
    if ($targetDir === false || $probeDir === false || $probeDir !== $targetDir) {
        @unlink($probeFile);
        return false;
    }
    unlink($probeFile);

    return true;
}

function ensure_session_save_path(): void
{
    $saveHandler = strtolower(trim((string) ini_get('session.save_handler')));
    if ($saveHandler !== '' && $saveHandler !== 'files') {
        esp_debug_log('session.save_path.skip_non_files_handler', [
            'save_handler' => $saveHandler,
            'save_path' => (string) ini_get('session.save_path'),
        ]);
        return;
    }

    $currentRaw = (string) ini_get('session.save_path');
    $currentPath = session_storage_path_from_ini($currentRaw);
    if ($currentPath !== '' && ensure_writable_directory($currentPath)) {
        return;
    }

    $candidates = [
        rtrim(sys_get_temp_dir(), '/\\') . '/esp_php_sessions',
        dirname(__DIR__) . '/tmp/php_sessions',
    ];

    foreach ($candidates as $candidate) {
        if (!ensure_writable_directory($candidate)) {
            continue;
        }
        if (ini_set('session.save_path', $candidate) !== false) {
            error_log('Using fallback session.save_path: ' . $candidate);
            esp_debug_log('session.save_path.fallback', ['path' => $candidate]);
            return;
        }
    }

    error_log('No writable session.save_path available. Current value: ' . $currentRaw);
    esp_debug_log('session.save_path.unwritable', ['current' => $currentRaw]);
}

function ensure_db_session_table(): bool
{
    static $ready = null;
    if (is_bool($ready)) {
        return $ready;
    }

    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS app_sessions (
                session_id VARCHAR(128) NOT NULL PRIMARY KEY,
                session_data MEDIUMBLOB NOT NULL,
                expires_at INT UNSIGNED NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_app_sessions_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $ready = true;
    } catch (Throwable $e) {
        error_log('Failed to ensure app_sessions table: ' . $e->getMessage());
        esp_debug_log('session.db.table.ensure_failed', [
            'message' => $e->getMessage(),
            'type' => get_class($e),
        ]);
        $ready = false;
    }

    return $ready;
}

function register_db_session_handler(): bool
{
    static $registered = null;
    if (is_bool($registered)) {
        return $registered;
    }

    $cfg = app_config();
    $storageMode = strtolower(trim((string) ($cfg['session_storage'] ?? 'db')));
    if ($storageMode === 'files') {
        $registered = false;
        return false;
    }

    if (!ensure_db_session_table()) {
        $registered = false;
        return false;
    }

    $handler = new class implements SessionHandlerInterface {
        public function open(string $savePath, string $sessionName): bool
        {
            return true;
        }

        public function close(): bool
        {
            return true;
        }

        public function read(string $id): string
        {
            try {
                $stmt = db()->prepare(
                    'SELECT session_data, expires_at
                     FROM app_sessions
                     WHERE session_id = :session_id
                     LIMIT 1'
                );
                $stmt->execute(['session_id' => $id]);
                $row = $stmt->fetch();
                if (!$row) {
                    return '';
                }

                if ((int) ($row['expires_at'] ?? 0) < time()) {
                    $this->destroy($id);
                    return '';
                }

                return (string) ($row['session_data'] ?? '');
            } catch (Throwable $e) {
                error_log('Session read failed: ' . $e->getMessage());
                esp_debug_log('session.db.read_failed', [
                    'message' => $e->getMessage(),
                    'type' => get_class($e),
                ]);
                return '';
            }
        }

        public function write(string $id, string $data): bool
        {
            try {
                $expiresAt = time() + (int) ini_get('session.gc_maxlifetime');
                $stmt = db()->prepare(
                    'INSERT INTO app_sessions (session_id, session_data, expires_at)
                     VALUES (:session_id, :session_data, :expires_at)
                     ON DUPLICATE KEY UPDATE
                       session_data = VALUES(session_data),
                       expires_at = VALUES(expires_at),
                       updated_at = CURRENT_TIMESTAMP'
                );
                $stmt->bindValue('session_id', $id, PDO::PARAM_STR);
                $stmt->bindValue('session_data', $data, PDO::PARAM_LOB);
                $stmt->bindValue('expires_at', $expiresAt, PDO::PARAM_INT);
                return $stmt->execute();
            } catch (Throwable $e) {
                error_log('Session write failed: ' . $e->getMessage());
                esp_debug_log('session.db.write_failed', [
                    'message' => $e->getMessage(),
                    'type' => get_class($e),
                ]);
                return false;
            }
        }

        public function destroy(string $id): bool
        {
            try {
                $stmt = db()->prepare('DELETE FROM app_sessions WHERE session_id = :session_id');
                return $stmt->execute(['session_id' => $id]);
            } catch (Throwable $e) {
                error_log('Session destroy failed: ' . $e->getMessage());
                esp_debug_log('session.db.destroy_failed', [
                    'message' => $e->getMessage(),
                    'type' => get_class($e),
                ]);
                return false;
            }
        }

        public function gc(int $max_lifetime): int|false
        {
            try {
                $stmt = db()->prepare('DELETE FROM app_sessions WHERE expires_at < :now');
                $stmt->execute(['now' => time()]);
                return $stmt->rowCount();
            } catch (Throwable $e) {
                error_log('Session gc failed: ' . $e->getMessage());
                esp_debug_log('session.db.gc_failed', [
                    'message' => $e->getMessage(),
                    'type' => get_class($e),
                ]);
                return false;
            }
        }
    };

    if (!session_set_save_handler($handler, true)) {
        esp_debug_log('session.db.register_failed', []);
        $registered = false;
        return false;
    }

    esp_debug_log('session.db.registered', [
        'storage_mode' => $storageMode,
    ]);
    $registered = true;
    return true;
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() === 'esp_session') {
            return;
        }
        // Another session was auto-started by host config. Close it and start ours.
        session_write_close();
    }

    $httpsFlag = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $forwardedSsl = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
    $isHttps = ($httpsFlag !== '' && $httpsFlag !== 'off')
        || $forwardedProto === 'https'
        || $forwardedSsl === 'on';

    $cfg = app_config();
    $cookieDomain = trim((string) ($cfg['session_cookie_domain'] ?? ''));
    if ($cookieDomain === '') {
        $cookieDomain = inferred_cookie_domain();
    }
    $cookieDomain = normalize_cookie_domain_value($cookieDomain);

    $requestHost = request_host_without_port();
    if ($cookieDomain !== '' && !cookie_domain_matches_host($cookieDomain, $requestHost)) {
        error_log(
            'Ignoring session cookie domain "' . $cookieDomain .
            '" for host "' . $requestHost . '"'
        );
        esp_debug_log('session.cookie_domain.ignored', [
            'cookieDomain' => $cookieDomain,
            'requestHost' => $requestHost,
        ]);
        $cookieDomain = '';
    }

    $cookieParams = [
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if ($cookieDomain !== '') {
        $cookieParams['domain'] = $cookieDomain;
    }

    $usingDbSessionHandler = register_db_session_handler();
    if (!$usingDbSessionHandler) {
        ensure_session_save_path();
    }

    session_name('esp_session');
    session_set_cookie_params($cookieParams);

    $incomingSessionId = (string) ($_COOKIE['esp_session'] ?? '');
    if ($incomingSessionId !== '' && preg_match('/^[A-Za-z0-9,-]{16,128}$/', $incomingSessionId) === 1) {
        session_id($incomingSessionId);
    }

    session_start();
    esp_debug_log('session.start.state', [
        'incoming_cookie_present' => $incomingSessionId !== '',
        'incoming_cookie_length' => strlen($incomingSessionId),
        'session_name' => session_name(),
        'session_id' => session_id(),
        'status' => session_status(),
        'save_handler' => (string) ini_get('session.save_handler'),
        'save_path' => (string) ini_get('session.save_path'),
        'db_handler' => $usingDbSessionHandler,
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        esp_debug_log('session.start.failed', [
            'save_path' => (string) ini_get('session.save_path'),
            'cookie_domain' => $cookieDomain,
            'secure' => $isHttps,
            'host' => $requestHost,
        ]);
        throw new RuntimeException('Failed to start PHP session');
    }
}

function logout_session(): void
{
    start_secure_session();

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function build_session_payload(array $row, string $loginAt, int $expiresTs): array
{
    $tier = normalize_tier((string) ($row['tier'] ?? 'level1'));
    $email = normalize_login_email((string) ($row['email'] ?? ''));
    $dbRole = (string) ($row['role'] ?? USER_ROLE_PROJECT_MANAGER);
    $role = normalize_user_role($dbRole, $email);

    return [
        'type' => 'company_access',
        'userId' => (int) $row['id'],
        'name' => (string) $row['full_name'],
        'email' => $email,
        'role' => $role,
        'companyId' => (int) $row['company_id'],
        'companyName' => (string) $row['company_name'],
        'companyLogo' => (string) ($row['company_logo'] ?? ''),
        'tier' => $tier,
        'tierLabel' => tier_label($tier),
        'modules' => modules_for_role($role, $tier),
        'loginAt' => $loginAt,
        'expiresAt' => gmdate('c', $expiresTs),
    ];
}

function login_with_user_row(array $row): array
{
    start_secure_session();

    if (!session_regenerate_id(true)) {
        esp_debug_log('session.regenerate.failed', [
            'user_id' => (int) ($row['id'] ?? 0),
            'email' => normalize_login_email((string) ($row['email'] ?? '')),
        ]);
        throw new RuntimeException('Failed to regenerate PHP session id');
    }

    $cfg = app_config();
    $ttlHours = (int) ($cfg['session_ttl_hours'] ?? 12);
    $expiresTs = time() + ($ttlHours * 3600);

    $session = build_session_payload($row, gmdate('c'), $expiresTs);

    $_SESSION['esp_auth'] = $session;
    $_SESSION['esp_auth_expires_ts'] = $expiresTs;

    // Persist session state immediately so follow-up API calls can read it right away.
    session_write_close();
    esp_debug_log('session.login.persisted', [
        'user_id' => (int) ($row['id'] ?? 0),
        'email' => normalize_login_email((string) ($row['email'] ?? '')),
        'expires_ts' => $expiresTs,
    ]);

    return $session;
}

function session_user(): ?array
{
    start_secure_session();

    $expiresTs = (int) ($_SESSION['esp_auth_expires_ts'] ?? 0);
    if ($expiresTs < time()) {
        logout_session();
        return null;
    }

    $auth = $_SESSION['esp_auth'] ?? null;
    if (!is_array($auth)) {
        return null;
    }

    return $auth;
}

function refresh_session_from_db(): ?array
{
    ensure_users_role_column();

    $cfg = app_config();
    $ttlHours = (int) ($cfg['session_ttl_hours'] ?? 12);

    $auth = session_user();
    if ($auth === null) {
        return null;
    }

    $sql =
        'SELECT u.id, u.company_id, u.full_name, u.email, u.role, u.is_active, c.name AS company_name, c.tier' .
        company_logo_select_sql('c') .
        ' FROM users u
          INNER JOIN companies c ON c.id = u.company_id
          WHERE u.id = :id
          LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute(['id' => (int) $auth['userId']]);
    $row = $stmt->fetch();

    if (!$row || (int) $row['is_active'] !== 1) {
        logout_session();
        return null;
    }

    $expiresTs = (int) ($_SESSION['esp_auth_expires_ts'] ?? 0);
    if ($expiresTs < time()) {
        $expiresTs = time() + ($ttlHours * 3600);
        $_SESSION['esp_auth_expires_ts'] = $expiresTs;
    }

    $loginAt = (string) ($auth['loginAt'] ?? gmdate('c'));
    $session = build_session_payload($row, $loginAt, $expiresTs);
    $_SESSION['esp_auth'] = $session;

    return $session;
}

function require_auth(): array
{
    $auth = refresh_session_from_db();
    if ($auth === null) {
        json_error(401, 'Not authenticated');
    }

    return $auth;
}

function require_admin(): array
{
    $auth = require_auth();
    $email = normalize_login_email((string) ($auth['email'] ?? ''));
    if (($auth['role'] ?? '') !== 'admin' || $email !== ADMIN_LOGIN_EMAIL) {
        json_error(403, 'Admin role required');
    }

    return $auth;
}

function audit_log(?int $actorUserId, string $action, string $entityType, ?string $entityId = null, ?array $details = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, details_json)
             VALUES (:actor_user_id, :action, :entity_type, :entity_id, :details_json)'
        );

        $stmt->execute([
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details_json' => $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable $e) {
        // Avoid breaking auth flow if audit insert fails.
    }
}
