<?php
declare(strict_types=1);

function validate_event(array $raw): array {
    $name = text_value($raw['name'] ?? '', 200, 'Name');
    if ($name === '') throw new InvalidArgumentException('Bitte einen Namen eintragen.');
    $month = integer_value($raw['month'] ?? 0, 1, 12, 'Monat');
    $day = integer_value($raw['day'] ?? 0, 1, 31, 'Tag');
    $year = ($raw['year'] ?? '') === '' || $raw['year'] === null ? null : integer_value($raw['year'], 1, 9999, 'Jahr');
    if (!checkdate($month, $day, $year ?? 2000)) throw new InvalidArgumentException('Dieses Datum gibt es nicht.');
    $repeat = $raw['repeat'] ?? 'yearly';
    if (!in_array($repeat, ['yearly', 'once'], true) || ($repeat === 'once' && $year === null)) throw new InvalidArgumentException('Ein einmaliger Termin braucht ein Jahr.');
    return ['id' => (string)($raw['id'] ?? bin2hex(random_bytes(12))), 'name' => $name, 'day' => $day, 'month' => $month, 'year' => $year,
        'occasion' => text_value($raw['occasion'] ?? '', 160, 'Anlass') ?: 'Geburtstag',
        'notes' => text_value($raw['notes'] ?? '', 5000, 'Notizen'), 'repeat' => $repeat,
        'remind_before' => integer_value($raw['remind_before'] ?? 0, 0, 365, 'Vorab-Erinnerung'), 'enabled' => (bool)($raw['enabled'] ?? true)];
}
function occurrence(array $e, int $year, string $leap): ?DateTimeImmutable {
    if ($e['year'] !== null && $year < $e['year']) return null;
    if ($e['repeat'] === 'once' && $e['year'] !== $year) return null;
    $month = $e['month']; $day = $e['day'];
    if (!checkdate($month, $day, $year)) {
        if ($month !== 2 || $day !== 29 || $leap === 'leap_only' || $e['repeat'] === 'once') return null;
        $month = $leap === 'mar1' ? 3 : 2; $day = $leap === 'mar1' ? 1 : 28;
    }
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), new DateTimeZone(setup()['timezone']));
}
function next_occurrence(array $e, DateTimeImmutable $from, string $leap): ?DateTimeImmutable {
    $start = max((int)$from->format('Y'), $e['year'] ?? 1);
    for ($year = $start; $year <= min(9999, $start + 8); $year++) {
        $date = occurrence($e, $year, $leap);
        if ($date && $date >= $from) return $date;
    }
    return null;
}
function enriched_events(array $events, array $s): array {
    $now = today($s); $keys = [];
    foreach ($events as $e) { $key = strtolower($e['name']) . ":{$e['month']}:{$e['day']}"; $keys[$key] = ($keys[$key] ?? 0) + 1; }
    return array_map(function ($e) use ($now, $s, $keys) {
        $next = next_occurrence($e, $now, $s['leap_day']);
        $e['next_date'] = $next?->format('Y-m-d');
        $e['days_until'] = $next ? (int)$now->diff($next)->format('%a') : null;
        $e['age'] = $next && $e['year'] !== null ? (int)$next->format('Y') - $e['year'] : null;
        $e['warnings'] = [];
        if ($keys[strtolower($e['name']) . ":{$e['month']}:{$e['day']}"] > 1) $e['warnings'][] = 'Name und Datum mehrfach vorhanden';
        if ($e['occasion'] === 'Geburtstag' && $e['year'] !== null && (int)$now->format('Y') - $e['year'] > 120) $e['warnings'][] = 'Geburtsjahr bitte prüfen';
        return $e;
    }, $events);
}
function revision(array $events): string { return hash('sha256', json_encode($events, JSON_THROW_ON_ERROR)); }
function due_reminders(array $events, array $s, DateTimeImmutable $date): array {
    $due = [];
    foreach ($events as $e) {
        if (!$e['enabled']) continue;
        foreach (array_unique([0, $e['remind_before']]) as $offset) {
            $target = $date->modify("+$offset days");
            $occ = occurrence($e, (int)$target->format('Y'), $s['leap_day']);
            if ($occ && $occ->format('Y-m-d') === $target->format('Y-m-d')) {
                $due[] = ['event' => $e, 'date' => $occ->format('Y-m-d'), 'offset' => $offset, 'key' => $e['id'] . ':' . $occ->format('Y-m-d') . ':' . $offset];
            }
        }
    }
    usort($due, fn($a, $b) => [$a['offset'], $a['event']['name']] <=> [$b['offset'], $b['event']['name']]);
    return $due;
}
