<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../lib/http.php';
require_once __DIR__ . '/../../../lib/auth.php';

apply_cors();
$auth = require_auth();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
ensure_users_role_column();

function read_payload_with_fallback(): array
{
    $input = json_input();
    if ($input === []) {
        $input = $_POST;
    }

    return is_array($input) ? $input : [];
}

function find_user(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.company_id, u.full_name, u.email, u.role, u.is_active, c.name AS company_name
         FROM users u
         INNER JOIN companies c ON c.id = u.company_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);

    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function is_system_admin_auth(array $auth): bool
{
    $email = normalize_login_email((string) ($auth['email'] ?? ''));
    return (($auth['role'] ?? '') === USER_ROLE_ADMIN) && ($email === ADMIN_LOGIN_EMAIL);
}

function normalize_manageable_user_role(string $rawRole, string $email = ''): string
{
    return normalize_user_role($rawRole, $email);
}

function enforce_company_scope_for_target(array $auth, array $target): void
{
    if (is_system_admin_auth($auth)) {
        return;
    }

    $authCompanyId = (int) ($auth['companyId'] ?? 0);
    $targetCompanyId = (int) ($target['company_id'] ?? 0);
    if ($authCompanyId < 1 || $targetCompanyId < 1 || $authCompanyId !== $targetCompanyId) {
        json_error(403, 'Access denied for user from another company');
    }
}

function resolve_company_id(string $companyName): int
{
    $trimmed = trim($companyName);
    if ($trimmed === '') {
        json_error(422, 'companyName is required');
    }

    $stmt = db()->prepare(
        'INSERT INTO companies (name, tier)
         VALUES (:name, :tier)
         ON DUPLICATE KEY UPDATE
           id = LAST_INSERT_ID(id),
           name = VALUES(name)'
    );
    $stmt->execute([
        'name' => $trimmed,
        'tier' => 'level1',
    ]);

    $companyId = (int) db()->lastInsertId();
    if ($companyId > 0) {
        return $companyId;
    }

    $lookup = db()->prepare('SELECT id FROM companies WHERE name = :name LIMIT 1');
    $lookup->execute(['name' => $trimmed]);
    $row = $lookup->fetch();
    if (!$row) {
        json_error(500, 'Could not resolve company');
    }

    return (int) $row['id'];
}

function company_name_for_id(int $companyId): string
{
    $stmt = db()->prepare('SELECT name FROM companies WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $companyId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error(500, 'Could not resolve company name');
    }

    return trim((string) $row['name']);
}

if ($method === 'GET') {
    try {
        $params = [];

        $sql =
            'SELECT u.id, u.company_id, c.name AS company_name, u.full_name, u.email, u.role,
                    u.is_active, u.created_at, u.updated_at
             FROM users u
             INNER JOIN companies c ON c.id = u.company_id';

        if (!is_system_admin_auth($auth)) {
            $sql .= ' WHERE u.company_id = :company_id';
            $params['company_id'] = (int) ($auth['companyId'] ?? 0);
        }

        $sql .= ' ORDER BY c.name ASC, u.id DESC';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll();
        $users = array_map(
            static function (array $row): array {
                $row['role'] = normalize_manageable_user_role(
                    (string) ($row['role'] ?? USER_ROLE_PROJECT_MANAGER),
                    (string) ($row['email'] ?? '')
                );
                return $row;
            },
            is_array($rows) ? $rows : []
        );

        json_response(200, [
            'ok' => true,
            'users' => $users,
        ]);
    } catch (Throwable $e) {
        json_error(500, 'Could not fetch users');
    }
}

if ($method === 'POST') {
    $input = read_payload_with_fallback();

    $fullName = trim((string) ($input['fullName'] ?? ''));
    $email = normalize_login_email((string) ($input['email'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $companyName = trim((string) ($input['companyName'] ?? ''));
    $role = normalize_manageable_user_role((string) ($input['role'] ?? USER_ROLE_PROJECT_MANAGER), $email);

    if ($fullName === '' || $email === '' || $password === '' || $companyName === '') {
        json_error(422, 'fullName, email, companyName and password are required');
    }

    if ($email === ADMIN_LOGIN_EMAIL) {
        json_error(422, 'This email is reserved for the system admin');
    }

    if (strlen($password) < 10) {
        json_error(422, 'Password must be at least 10 characters');
    }

    if ($role === USER_ROLE_ADMIN) {
        json_error(422, 'Admin role cannot be assigned here');
    }

    try {
        if (is_system_admin_auth($auth)) {
            $companyId = resolve_company_id($companyName);
        } else {
            $companyId = (int) ($auth['companyId'] ?? 0);
            if ($companyId < 1) {
                json_error(403, 'Company scope missing');
            }
        }
        $resolvedCompanyName = company_name_for_id($companyId);
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = db()->prepare(
            'INSERT INTO users (company_id, full_name, email, password_hash, role, is_active)
             VALUES (:company_id, :full_name, :email, :password_hash, :role, 1)'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'full_name' => $fullName,
            'email' => $email,
            'password_hash' => $hash,
            'role' => $role,
        ]);

        $newId = (int) db()->lastInsertId();

        audit_log(
            (int) $auth['userId'],
            'admin.user.create',
            'user',
            (string) $newId,
            [
                'email' => $email,
                'role' => $role,
                'companyName' => $resolvedCompanyName,
            ]
        );

        json_response(201, [
            'ok' => true,
            'user' => [
                'id' => $newId,
                'company_id' => $companyId,
                'company_name' => $resolvedCompanyName,
                'full_name' => $fullName,
                'email' => $email,
                'role' => $role,
                'is_active' => 1,
            ],
        ]);
    } catch (PDOException $e) {
        $driverCode = (int) (($e->errorInfo[1] ?? 0));
        if ($driverCode === 1062) {
            json_error(409, 'Email already exists');
        }
        if (in_array($driverCode, [1265, 1366], true)) {
            json_error(500, 'Role schema is outdated. Run backend/sql/004_user_roles.sql');
        }
        json_error(500, 'Could not create user');
    } catch (Throwable $e) {
        json_error(500, 'Could not create user');
    }
}

if ($method === 'PATCH') {
    $input = json_input();

    $userId = (int) ($input['userId'] ?? 0);
    if ($userId < 1) {
        json_error(422, 'userId is required');
    }

    $target = find_user($userId);
    if ($target === null) {
        json_error(404, 'User not found');
    }
    enforce_company_scope_for_target($auth, $target);

    $fields = [];
    $params = ['id' => $userId];

    if (array_key_exists('fullName', $input)) {
        $fullName = trim((string) $input['fullName']);
        if ($fullName === '') {
            json_error(422, 'fullName cannot be empty');
        }
        $fields[] = 'full_name = :full_name';
        $params['full_name'] = $fullName;
    }

    if (array_key_exists('isActive', $input)) {
        $isActive = (int) ((bool) $input['isActive']);
        if ($userId === (int) $auth['userId'] && $isActive === 0) {
            json_error(422, 'You cannot deactivate your own user');
        }
        if (normalize_login_email((string) ($target['email'] ?? '')) === ADMIN_LOGIN_EMAIL && $isActive === 0) {
            json_error(422, 'System admin cannot be deactivated');
        }
        $fields[] = 'is_active = :is_active';
        $params['is_active'] = $isActive;
    }

    if (array_key_exists('newPassword', $input)) {
        $newPassword = (string) $input['newPassword'];
        if (strlen($newPassword) < 10) {
            json_error(422, 'newPassword must be at least 10 characters');
        }
        $fields[] = 'password_hash = :password_hash';
        $params['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    if (array_key_exists('role', $input)) {
        if (normalize_login_email((string) ($target['email'] ?? '')) === ADMIN_LOGIN_EMAIL) {
            json_error(422, 'System admin role cannot be changed');
        }

        $nextRole = normalize_manageable_user_role((string) $input['role'], (string) ($target['email'] ?? ''));
        if ($nextRole === USER_ROLE_ADMIN) {
            json_error(422, 'Admin role cannot be assigned here');
        }
        $fields[] = 'role = :role';
        $params['role'] = $nextRole;
    }

    if ($fields === []) {
        json_error(422, 'No update fields provided');
    }

    try {
        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        audit_log(
            (int) $auth['userId'],
            'admin.user.update',
            'user',
            (string) $userId,
            ['fields' => array_keys($input)]
        );

        json_response(200, [
            'ok' => true,
        ]);
    } catch (PDOException $e) {
        $driverCode = (int) (($e->errorInfo[1] ?? 0));
        if (in_array($driverCode, [1265, 1366], true)) {
            json_error(500, 'Role schema is outdated. Run backend/sql/004_user_roles.sql');
        }
        json_error(500, 'Could not update user');
    } catch (Throwable $e) {
        json_error(500, 'Could not update user');
    }
}

if ($method === 'DELETE') {
    $input = json_input();
    if ($input === []) {
        $input = $_GET;
    }

    $userId = (int) ($input['userId'] ?? 0);
    if ($userId < 1) {
        json_error(422, 'userId is required');
    }

    if ($userId === (int) $auth['userId']) {
        json_error(422, 'You cannot remove your own user');
    }

    $target = find_user($userId);
    if ($target === null) {
        json_error(404, 'User not found');
    }
    enforce_company_scope_for_target($auth, $target);

    if (normalize_login_email((string) ($target['email'] ?? '')) === ADMIN_LOGIN_EMAIL) {
        json_error(422, 'System admin cannot be removed');
    }

    try {
        $stmt = db()->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);

        if ($stmt->rowCount() < 1) {
            json_error(404, 'User not found');
        }

        audit_log(
            (int) $auth['userId'],
            'admin.user.delete',
            'user',
            (string) $userId,
            [
                'email' => (string) ($target['email'] ?? ''),
                'companyName' => (string) ($target['company_name'] ?? ''),
            ]
        );

        json_response(200, [
            'ok' => true,
            'removedUserId' => $userId,
        ]);
    } catch (Throwable $e) {
        json_error(500, 'Could not remove user');
    }
}

json_error(405, 'Method not allowed');
