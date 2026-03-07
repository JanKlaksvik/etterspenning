<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../lib/http.php';
require_once __DIR__ . '/../../../lib/auth.php';

apply_cors();
$auth = require_admin();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$companyId = (int) $auth['companyId'];

if ($method === 'GET') {
    try {
        $stmt = db()->prepare('SELECT id, name, tier FROM companies WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $companyId]);
        $company = $stmt->fetch();

        if (!$company) {
            json_error(404, 'Company not found');
        }

        $tier = normalize_tier((string) $company['tier']);

        json_response(200, [
            'ok' => true,
            'company' => [
                'id' => (int) $company['id'],
                'name' => (string) $company['name'],
                'tier' => $tier,
                'tierLabel' => tier_label($tier),
                'modules' => modules_for_tier($tier),
            ],
        ]);
    } catch (Throwable $e) {
        json_error(500, 'Could not fetch company tier');
    }
}

if ($method === 'POST') {
    $input = json_input();
    if ($input === []) {
        $input = $_POST;
    }

    $tierInput = strtolower(trim((string) ($input['tier'] ?? '')));
    if (!in_array($tierInput, ['level1', 'level2'], true)) {
        json_error(422, 'tier must be level1 or level2');
    }
    $tier = normalize_tier($tierInput);

    try {
        $stmt = db()->prepare('UPDATE companies SET tier = :tier WHERE id = :id');
        $stmt->execute([
            'tier' => $tier,
            'id' => $companyId,
        ]);

        audit_log(
            (int) $auth['userId'],
            'admin.company.tier.update',
            'company',
            (string) $companyId,
            ['tier' => $tier]
        );

        $session = refresh_session_from_db();

        json_response(200, [
            'ok' => true,
            'tier' => $tier,
            'tierLabel' => tier_label($tier),
            'modules' => modules_for_tier($tier),
            'session' => $session,
        ]);
    } catch (Throwable $e) {
        json_error(500, 'Could not update tier');
    }
}

json_error(405, 'Method not allowed');
