<?php
require_once __DIR__ . '/../bootstrap.php';
start_secure_session();
require_csrf((require_post_json()['csrf'] ?? ''));

$admin = require_admin();
$pdo = db();

$pending = $pdo->query("SELECT id, created_at, name, email, company, country, role, experience, status FROM account_requests WHERE status='pending' ORDER BY created_at DESC LIMIT 200")->fetchAll();
$approved = $pdo->query("SELECT id, approved_at, email, name, company, country, role, approved_by FROM approved_users ORDER BY approved_at DESC LIMIT 200")->fetchAll();
$waitlist = $pdo->query("SELECT id, created_at, name, email, country, role FROM waiting_list ORDER BY created_at DESC LIMIT 200")->fetchAll();

json_response(['ok'=>true,'pending'=>$pending,'approved'=>$approved,'waitlist'=>$waitlist]);
