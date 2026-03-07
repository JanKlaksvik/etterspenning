<?php
require_once __DIR__ . '/../bootstrap.php';
start_secure_session();
$data = require_post_json();
require_csrf($data['csrf'] ?? '');

$admin = require_admin();
$id = (int)($data['id'] ?? 0);
if ($id <= 0) json_response(['ok'=>false,'error'=>'bad_id'], 400);

$pdo = db();
$pdo->beginTransaction();

$st = $pdo->prepare("SELECT * FROM account_requests WHERE id=? FOR UPDATE");
$st->execute([$id]);
$req = $st->fetch();
if (!$req) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'not_found'], 404); }

$now = date('Y-m-d H:i:s');

$upd = $pdo->prepare("UPDATE account_requests SET status='approved', reviewed_at=?, reviewed_by=? WHERE id=?");
$upd->execute([$now, $admin['email'], $id]);

$upsert = $pdo->prepare("
  INSERT INTO approved_users (email,name,company,country,role,approved_at,approved_by)
  VALUES (?,?,?,?,?,?,?)
  ON DUPLICATE KEY UPDATE
    name=VALUES(name), company=VALUES(company), country=VALUES(country), role=VALUES(role),
    approved_at=VALUES(approved_at), approved_by=VALUES(approved_by)
");
$upsert->execute([
  $req['email'], $req['name'], $req['company'], $req['country'], $req['role'], $now, $admin['email']
]);

$a = $pdo->prepare("INSERT INTO audit_log (actor, action, target_table, target_id, details) VALUES (?,?,?,?,JSON_OBJECT('email',?))");
$a->execute([$admin['email'], 'approve', 'account_requests', (string)$id, $req['email']]);

$pdo->commit();
json_response(['ok'=>true]);
