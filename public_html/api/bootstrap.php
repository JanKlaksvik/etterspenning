<?php
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/util.php';
require_once __DIR__ . '/../inc/security.php';

// Force JSON responses for API
header('X-Content-Type-Options: nosniff');
