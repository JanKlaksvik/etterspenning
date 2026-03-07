<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/http.php';
require_once __DIR__ . '/../../lib/auth.php';

apply_cors();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'POST') {
    json_error(405, 'Method not allowed');
}

$auth = require_auth();
$companyId = (int) ($auth['companyId'] ?? 0);
$userId = (int) ($auth['userId'] ?? 0);

if ($companyId <= 0) {
    json_error(400, 'Invalid company scope');
}

ensure_company_project_store_table();

if ($method === 'GET') {
    $stmt = db()->prepare(
        'SELECT projects_json, updated_at
         FROM company_project_store
         WHERE company_id = :company_id
         LIMIT 1'
    );
    $stmt->execute(['company_id' => $companyId]);
    $row = $stmt->fetch();

    $projects = [];
    if ($row && isset($row['projects_json'])) {
        $decoded = json_decode((string) $row['projects_json'], true);
        if (is_array($decoded)) {
            $projects = $decoded;
        }
    }

    json_response(200, [
        'ok' => true,
        'projects' => $projects,
        'updatedAt' => $row['updated_at'] ?? null,
    ]);
}

$input = json_input();
$projects = $input['projects'] ?? null;
if (!is_array($projects)) {
    json_error(422, 'projects array is required');
}

$encoded = json_encode($projects, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($encoded)) {
    json_error(500, 'Could not encode projects');
}

$stmt = db()->prepare(
    'INSERT INTO company_project_store (company_id, projects_json, updated_by_user_id)
     VALUES (:company_id, :projects_json, :updated_by_user_id)
     ON DUPLICATE KEY UPDATE
       projects_json = VALUES(projects_json),
       updated_by_user_id = VALUES(updated_by_user_id),
       updated_at = CURRENT_TIMESTAMP'
);

$stmt->execute([
    'company_id' => $companyId,
    'projects_json' => $encoded,
    'updated_by_user_id' => $userId > 0 ? $userId : null,
]);

audit_log(
    $userId > 0 ? $userId : null,
    'projects.replace',
    'company',
    (string) $companyId,
    ['projectCount' => count($projects)]
);

json_response(200, [
    'ok' => true,
    'projectCount' => count($projects),
]);

function ensure_company_project_store_table(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS company_project_store (
            company_id INT UNSIGNED NOT NULL,
            projects_json LONGTEXT NOT NULL,
            updated_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (company_id),
            CONSTRAINT fk_company_project_store_company
              FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            CONSTRAINT fk_company_project_store_updated_by
              FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensured = true;
}
