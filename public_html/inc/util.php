<?php
function json_response($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function client_ip(): string {
  // Basic; behind reverse proxy you may need X-Forwarded-For handling (carefully).
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function ip_hash(): string {
  return hash('sha256', (client_ip() . '|' . IP_SALT));
}

function user_agent(): string {
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
  return mb_substr($ua, 0, 255);
}

function require_post_json(): array {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    json_response(['ok'=>false,'error'=>'invalid_json'], 400);
  }
  return $data;
}

function clean_str($v, int $max = 200): string {
  $s = trim((string)$v);
  $s = preg_replace('/\s+/', ' ', $s);
  return mb_substr($s, 0, $max);
}

function clean_email($v): string {
  $e = strtolower(trim((string)$v));
  $e = mb_substr($e, 0, 255);
  if (!filter_var($e, FILTER_VALIDATE_EMAIL)) return '';
  return $e;
}
