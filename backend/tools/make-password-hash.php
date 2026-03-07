<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

$password = $argv[1] ?? '';
if ($password === '') {
    fwrite(STDERR, "Usage: php backend/tools/make-password-hash.php <password>\n");
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT) . PHP_EOL;
