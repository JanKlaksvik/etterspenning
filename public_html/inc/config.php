<?php
// DB settings (fill these)
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'YOUR_DB_NAME');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_DB_PASSWORD');

// Admin identity (used for audit labels)
define('ADMIN_LABEL', 'posttension.eu admin');

// Security
define('SESSION_NAME', 'pt_admin_sess');
define('CSRF_TTL_SECONDS', 7200);
define('RATE_LIMIT_WINDOW_SECONDS', 3600);
define('RATE_LIMIT_MAX_HITS', 8); // per IP per hour per endpoint

// GDPR retention (cron)
define('RETENTION_PENDING_DAYS', 90);
define('RETENTION_REJECTED_DAYS', 180);

// Salt for hashing IP (do not change after launch)
define('IP_SALT', 'CHANGE_ME_TO_A_LONG_RANDOM_STRING');
