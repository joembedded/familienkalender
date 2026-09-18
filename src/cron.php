<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Nur über PHP-CLI aufrufen.'); }
require __DIR__ . '/lib/app.php';
try {
    initialize();
    $options = getopt('', ['dry-run', 'date:']);
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
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
