<?php
require_once __DIR__ . '/bootstrap.php';
rate_limit('waitlist');

$data = require_post_json();

// Honeypot
if (!empty($data['company_website'])) json_response(['ok'=>true]); // silently ignore bots

$name = clean_str($data['name'] ?? '', 200);
$email = clean_email($data['email'] ?? '');
$country = clean_str($data['country'] ?? '', 100);
$role = clean_str($data['role'] ?? '', 120);
$note = clean_str($data['note'] ?? '', 2000);

if ($name === '' || $email === '') {
  json_response(['ok'=>false,'error'=>'missing_fields'], 400);
}

$pdo = db();
$stmt = $pdo->prepare("INSERT INTO waiting_list (name,email,country,role,note,ip_hash,user_agent) VALUES (?,?,?,?,?,?,?)");
$stmt->execute([$name,$email,$country?:null,$role?:null,$note?:null, ip_hash(), user_agent()]);

json_response(['ok'=>true]);
