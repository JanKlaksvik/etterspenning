<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/config.php';

$pdo = db();

$pdo->exec("DELETE FROM account_requests
  WHERE status='pending' AND created_at < (NOW() - INTERVAL " . (int)RETENTION_PENDING_DAYS . " DAY)");

$pdo->exec("DELETE FROM account_requests
  WHERE status='rejected' AND created_at < (NOW() - INTERVAL " . (int)RETENTION_REJECTED_DAYS . " DAY)");

$pdo->exec("DELETE FROM waiting_list
  WHERE created_at < (NOW() - INTERVAL 365 DAY)");
