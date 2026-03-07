<?php
require_once __DIR__ . '/../bootstrap.php';
start_secure_session();
$data = require_post_json();
require_csrf($data['csrf'] ?? '');

$admin = require_admin();
$id = (int)($data['id'] ?? 0);
$note = clean_str($data['note'] ?? '', 1000);
if ($id <= 0) json_response(['ok'=>false,'error'=>'bad_id'], 400);

$pdo = db();
$pdo->beginTransaction();

$st = $pdo->prepare("SELECT email FROM account_requests WHERE id=? FOR UPDATE");
$st->execute([$id]);
$req = $st->fetch();
if (!$req) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'not_found'], 404); }

$now = date('Y-m-d H:i:s');

$upd = $pdo->prepare("UPDATE account_requests SET status='rejected', reviewed_at=?, reviewed_by=?, admin_note=? WHERE id=?");
$upd->execute([$now, $admin['email'], $note ?: null, $id]);

$a = $pdo->prepare("INSERT INTO audit_log (actor, action, target_table, target_id, details) VALUES (?,?,?,?,JSON_OBJECT('email',?, 'note',?))");
$a->execute([$admin['email'], 'reject', 'account_requests', (string)$id, $req['email'], $note]);

$pdo->commit();
json_response(['ok'=>true]);
