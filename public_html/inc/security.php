<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';

function start_secure_session(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;

  session_name(SESSION_NAME);
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Strict',
  ]);
  session_start();
}

function csrf_token(): string {
  start_secure_session();
  if (empty($_SESSION['csrf']) || empty($_SESSION['csrf_ts']) || (time() - $_SESSION['csrf_ts']) > CSRF_TTL_SECONDS) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_ts'] = time();
  }
  return $_SESSION['csrf'];
}

function require_csrf(string $token): void {
  start_secure_session();
  if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
    json_response(['ok'=>false,'error'=>'csrf'], 403);
  }
}

function rate_limit(string $bucket): void {
  $key = $bucket . '|' . ip_hash();
  $pdo = db();

  $pdo->beginTransaction();
  $stmt = $pdo->prepare("SELECT hit_count, UNIX_TIMESTAMP(last_hit) AS last_hit_unix FROM rate_limits WHERE key_name = ? FOR UPDATE");
  $stmt->execute([$key]);
  $row = $stmt->fetch();

  $now = time();
  if (!$row) {
    $ins = $pdo->prepare("INSERT INTO rate_limits (key_name, hit_count, last_hit) VALUES (?, 1, NOW())");
    $ins->execute([$key]);
    $pdo->commit();
    return;
  }

  $last = (int)$row['last_hit_unix'];
  $count = (int)$row['hit_count'];

  if (($now - $last) > RATE_LIMIT_WINDOW_SECONDS) {
    $upd = $pdo->prepare("UPDATE rate_limits SET hit_count = 1, last_hit = NOW() WHERE key_name = ?");
    $upd->execute([$key]);
    $pdo->commit();
    return;
  }

  if ($count >= RATE_LIMIT_MAX_HITS) {
    $pdo->commit();
    json_response(['ok'=>false,'error'=>'rate_limited'], 429);
  }

  $upd = $pdo->prepare("UPDATE rate_limits SET hit_count = hit_count + 1, last_hit = NOW() WHERE key_name = ?");
  $upd->execute([$key]);
  $pdo->commit();
}

function require_admin(): array {
  start_secure_session();
  if (empty($_SESSION['admin_email'])) {
    header('Location: /admin/login.php');
    exit;
  }
  return ['email' => $_SESSION['admin_email']];
}
