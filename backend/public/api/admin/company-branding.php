<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../lib/http.php';
require_once __DIR__ . '/../../../lib/auth.php';

apply_cors();
$auth = require_admin();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function read_payload_with_fallback(): array
{
    $input = json_input();
    if ($input === []) {
        $input = $_POST;
    }

    return is_array($input) ? $input : [];
}

function fetch_company_branding_by_id(int $companyId): ?array
{
    if ($companyId < 1) {
        return null;
    }

    $sql =
        'SELECT c.id, c.name' .
        company_logo_select_sql('c') .
        ' FROM companies c
          WHERE c.id = :id
          LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute(['id' => $companyId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'logoData' => (string) ($row['company_logo'] ?? ''),
    ];
}

function fetch_company_branding_by_name(string $companyName): ?array
{
    $name = trim($companyName);
    if ($name === '') {
        return null;
    }

    $sql =
        'SELECT c.id, c.name' .
        company_logo_select_sql('c') .
        ' FROM companies c
          WHERE c.name = :name
          LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute(['name' => $name]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'logoData' => (string) ($row['company_logo'] ?? ''),
    ];
}

function resolve_company_id_for_update(int $companyId, string $companyName): int
{
    if ($companyId > 0) {
        $existing = fetch_company_branding_by_id($companyId);
        if ($existing === null) {
            json_error(404, 'Company not found');
        }

        return $companyId;
    }

    $name = trim($companyName);
    if ($name === '') {
        json_error(422, 'companyName or companyId is required');
    }

    $stmt = db()->prepare(
        'INSERT INTO companies (name, tier)
         VALUES (:name, :tier)
         ON DUPLICATE KEY UPDATE
           id = LAST_INSERT_ID(id),
           name = VALUES(name)'
    );
    $stmt->execute([
        'name' => $name,
        'tier' => 'level1',
    ]);

    $resolvedId = (int) db()->lastInsertId();
    if ($resolvedId > 0) {
        return $resolvedId;
    }

    $lookup = db()->prepare('SELECT id FROM companies WHERE name = :name LIMIT 1');
    $lookup->execute(['name' => $name]);
    $row = $lookup->fetch();
    if (!$row) {
        json_error(500, 'Could not resolve company');
    }

    return (int) $row['id'];
}

if ($method === 'GET') {
    $companyId = (int) ($_GET['companyId'] ?? 0);
    $companyName = trim((string) ($_GET['companyName'] ?? ''));

    try {
        if ($companyId > 0) {
            $company = fetch_company_branding_by_id($companyId);
        } elseif ($companyName !== '') {
            $company = fetch_company_branding_by_name($companyName);
        } else {
            $company = fetch_company_branding_by_id((int) ($auth['companyId'] ?? 0));
        }

        if ($company === null) {
            json_error(404, 'Company not found');
        }

        json_response(200, [
            'ok' => true,
            'company' => $company,
        ]);
    } catch (Throwable $e) {
        json_error(500, 'Could not fetch company branding');
    }
}

if ($method === 'POST') {
    $input = read_payload_with_fallback();
    $companyId = (int) ($input['companyId'] ?? 0);
    $companyName = trim((string) ($input['companyName'] ?? ''));
    $logoData = (string) ($input['logoData'] ?? $input['companyLogo'] ?? '');

    if ($logoData !== '' && strpos($logoData, 'data:image/') !== 0) {
        json_error(422, 'logoData must be a valid image data URL');
    }
    if (strlen($logoData) > 4_200_000) {
        json_error(422, 'logoData is too large');
    }

    if (!ensure_company_logo_column()) {
        json_error(500, 'Could not prepare database for company logo storage');
    }

    try {
        $resolvedCompanyId = resolve_company_id_for_update($companyId, $companyName);

        $stmt = db()->prepare(
            'UPDATE companies
             SET logo_data = :logo_data
             WHERE id = :id'
        );
        $stmt->execute([
            'logo_data' => $logoData !== '' ? $logoData : null,
            'id' => $resolvedCompanyId,
        ]);

        $company = fetch_company_branding_by_id($resolvedCompanyId);
        if ($company === null) {
            json_error(500, 'Could not reload company branding');
        }

        audit_log(
            (int) $auth['userId'],
            'admin.company.branding.update',
            'company',
            (string) $resolvedCompanyId,
            [
                'companyName' => (string) $company['name'],
                'logoUpdated' => $logoData !== '',
                'logoCleared' => $logoData === '',
            ]
        );

        $session = null;
        if ((int) $auth['companyId'] === (int) $resolvedCompanyId) {
            $session = refresh_session_from_db();
        }

        json_response(200, [
            'ok' => true,
            'company' => $company,
            'session' => $session,
        ]);
    } catch (Throwable $e) {
        json_error(500, 'Could not update company branding');
    }
}

json_error(405, 'Method not allowed');
