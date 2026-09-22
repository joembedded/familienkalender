<?php

declare(strict_types=1);
require __DIR__ . '/lib/app.php';

function cron_log(string $message): void
{
    $path = data_dir() . '/cron.log';
    $oldPath = data_dir() . '/cron_old.log';
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    $size = is_file($path) ? filesize($path) : 0;
    if ($size !== false && $size + strlen($entry) > 50 * 1024) {
        if (is_file($oldPath)) @unlink($oldPath);
        @rename($path, $oldPath);
    }
    @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
}

$cli = PHP_SAPI === 'cli';
try {
    if (!$cli) {
        $setupKey = $_GET['setup_key'] ?? '';
        if (!is_string($setupKey) || !hash_equals(config()['setup_key'], $setupKey)) {
            http_response_code(403);
            exit('Nicht autorisiert.');
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
    }
    initialize();
    $options = $cli ? getopt('', ['dry-run', 'date:']) : [];
    $date = today();
    if (isset($options['date'])) {
        if (!isset($options['dry-run'])) throw new InvalidArgumentException('--date ist nur zusammen mit --dry-run erlaubt.');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $options['date'], $date->getTimezone());
        if (!$parsed || $parsed->format('Y-m-d') !== $options['date']) throw new InvalidArgumentException('Datum muss YYYY-MM-DD sein.');
        $date = $parsed;
    }
    $result = run_reminders($date, isset($options['dry-run']));
    $output = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    cron_log($output);
    echo $output . PHP_EOL;
    if (($result['failed'] ?? 0) > 0) exit(1);
} catch (Throwable $e) {
    cron_log('FEHLER: ' . $e->getMessage());
    if ($cli) fwrite(STDERR, $e->getMessage() . PHP_EOL);
    else {
        error_log('Familienkalender-CRON: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erinnerungslauf fehlgeschlagen.'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    exit(1);
}
