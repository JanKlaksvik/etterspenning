<?php
declare(strict_types=1);

function esp_debug_log(string $event, array $context = []): void
{
    try {
        $root = dirname(__DIR__);
        $dir = $root . '/tmp';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }

        $file = $dir . '/auth-debug.log';
        $payload = [
            'ts' => gmdate('c'),
            'event' => $event,
            'context' => $context,
        ];
        $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }

        file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // Never break app flow because of debug logging.
    }
}
