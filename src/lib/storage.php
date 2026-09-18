<?php
declare(strict_types=1);

function data_dir(): string { return defined('CALENDAR_DATA_DIR') ? CALENDAR_DATA_DIR : dirname(__DIR__) . '/data'; }

function read_json(string $name, array $fallback = []): array {
    $path = data_dir() . '/' . $name . '.json';
    if (!is_file($path)) return $fallback;
    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException('Die Datendatei konnte nicht gelesen werden.');
    $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException('Ungültige Datendatei.');
    return $value;
}
/** All writers share one lock; replacement keeps readers from seeing partial JSON. */
function update_json(string $name, callable $callback, array $fallback = []): mixed {
    $lock = fopen(data_dir() . '/storage.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Datenspeicher nicht beschreibbar.');
    $temp = null;
    try {
        $value = read_json($name, $fallback);
        $result = $callback($value);
        $temp = tempnam(data_dir(), '.write-');
        if ($temp === false) throw new RuntimeException('Datenspeicher nicht beschreibbar.');
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temp, $json) !== strlen($json) || !rename($temp, data_dir() . '/' . $name . '.json')) {
            throw new RuntimeException('Speichern fehlgeschlagen.');
        }
        $temp = null;
        return $result;
    } finally {
        if ($temp && is_file($temp)) unlink($temp);
        flock($lock, LOCK_UN); fclose($lock);
    }
}
function rate_limit(string $key, int $max, int $seconds): void {
    $allowed = update_json('limits', function (&$limits) use ($key, $max, $seconds) {
        $now = time();
        $limits = array_filter($limits, fn($entry) => $entry['until'] > $now);
        $entry = $limits[$key] ?? ['count' => 0, 'until' => $now + $seconds];
        if ($entry['count'] >= $max) return false;
        $entry['count']++; $limits[$key] = $entry;
        return true;
    });
    if (!$allowed) throw new InvalidArgumentException('Zu viele Versuche. Bitte später erneut versuchen.');
}
function text_value(mixed $input, int $max, string $field): string {
    if (!is_string($input) || strlen($input) > $max) throw new InvalidArgumentException("Ungültiger Wert: $field.");
    return trim($input);
}
function integer_value(mixed $input, int $min, int $max, string $field): int {
    if (filter_var($input, FILTER_VALIDATE_INT) === false || (int)$input < $min || (int)$input > $max) throw new InvalidArgumentException("Ungültiger Wert: $field.");
    return (int)$input;
}
