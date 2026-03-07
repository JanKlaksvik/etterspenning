<?php
declare(strict_types=1);

function load_env_file(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $env = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($key !== '') {
            $env[$key] = $value;
        }
    }

    return $env;
}

function env_value(array $fileEnv, string $key, string $default = ''): string
{
    $runtime = getenv($key);
    if ($runtime !== false && $runtime !== '') {
        return (string) $runtime;
    }

    if (array_key_exists($key, $fileEnv) && $fileEnv[$key] !== '') {
        return (string) $fileEnv[$key];
    }

    return $default;
}

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $root = dirname(__DIR__);
    $fileEnv = load_env_file($root . '/.env');

    $sessionHours = (int) env_value($fileEnv, 'SESSION_TTL_HOURS', '12');
    if ($sessionHours < 1) {
        $sessionHours = 1;
    }

    $config = [
        'app_env' => env_value($fileEnv, 'APP_ENV', 'production'),
        'db' => [
            'host' => env_value($fileEnv, 'DB_HOST', '127.0.0.1'),
            'port' => (int) env_value($fileEnv, 'DB_PORT', '3306'),
            'name' => env_value($fileEnv, 'DB_NAME', 'etterspenning'),
            'user' => env_value($fileEnv, 'DB_USER', ''),
            'pass' => env_value($fileEnv, 'DB_PASS', ''),
        ],
        'session_ttl_hours' => $sessionHours,
        'session_storage' => env_value($fileEnv, 'SESSION_STORAGE', 'db'),
        'session_cookie_domain' => env_value($fileEnv, 'SESSION_COOKIE_DOMAIN', ''),
        'cors_origin' => env_value($fileEnv, 'CORS_ORIGIN', ''),
        'mail_from_email' => env_value($fileEnv, 'MAIL_FROM_EMAIL', ''),
        'mail_from_name' => env_value($fileEnv, 'MAIL_FROM_NAME', 'Etterspenning.no'),
        'mail_reply_to' => env_value($fileEnv, 'MAIL_REPLY_TO', ''),
    ];

    return $config;
}
