<?php
declare(strict_types=1);
require __DIR__ . '/lib/app.php';
$cli = PHP_SAPI === 'cli';
try {
    if (!$cli) {
        $setupKey = $_GET['setup_key'] ?? '';
        if (!is_string($setupKey) || !hash_equals(config()['setup_key'], $setupKey)) { http_response_code(403); exit('Nicht autorisiert.'); }
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store, private');
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
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (($result['failed'] ?? 0) > 0) exit(1);
} catch (Throwable $e) {
    if ($cli) fwrite(STDERR, $e->getMessage() . PHP_EOL);
    else { error_log('Familienkalender-CRON: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Erinnerungslauf fehlgeschlagen.'], JSON_UNESCAPED_UNICODE) . PHP_EOL; }
    exit(1);
}
