<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$dir = sys_get_temp_dir() . '/family-calendar-' . bin2hex(random_bytes(10)); mkdir($dir, 0700);
define('CALENDAR_DATA_DIR', $dir); define('CALENDAR_CONFIG', $dir . '/config.php');
$config = require dirname(__DIR__) . '/src/config.example.php'; $config['setup_key'] = str_repeat('test-key', 6); $config['mail_enabled'] = true;
file_put_contents(CALENDAR_CONFIG, '<?php return ' . var_export($config, true) . ';');
require dirname(__DIR__) . '/src/lib/app.php';
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; echo "OK: $label\n"; }
function day(string $date): DateTimeImmutable { return new DateTimeImmutable($date, new DateTimeZone('Europe/Berlin')); }
try {
    initialize(); $s = setup(); $events = read_json('termine');
    check(array_keys($s['users']) === ['papa'] && count($events) === 3, 'Nur Papa und drei öffentliche Beispiele beim ersten Start');
    update_json('setup', function (&$s) { $s['users']['laura'] = new_user('laura', 'Laura', 'laura@familie.xyz'); });
    $s = setup();
    $invitation = invitation_message($s['users']['laura']);
    check(str_contains($invitation['body'], $config['setup_key']) && str_contains($invitation['body'], INITIAL_PASSWORD) && str_contains($invitation['body'], $config['base_url']), 'Einladung enthält Zugangsdaten und Kalenderadresse');
    check($s['users']['laura']['password_hash'] !== $s['users']['papa']['password_hash'] && password_verify(INITIAL_PASSWORD, $s['users']['laura']['password_hash']), 'Getrennte Passwort-Hashes und gültiges Startpasswort');
    check($s['users']['laura']['must_change_password'] && $s['users']['papa']['must_change_password'], 'Passwortwechsel für beide erforderlich');
    initialize(); check(setup() === $s && read_json('termine') === $events, 'Initialisierung überschreibt keine Daten');
    $event = $events[0];
    check(count(due_reminders($events, $s, day('2027-02-03'))) === 1, 'Geburtstag am richtigen Tag');
    check(count(due_reminders($events, $s, day('2027-02-04'))) === 0, 'Keine Erinnerung ohne Anlass');
    $event['enabled'] = false; check(!due_reminders([$event], $s, day('2027-02-03')), 'Pausierter Termin');
    $event = validate_event(['name' => 'Neujahr', 'day' => 2, 'month' => 1, 'year' => null, 'remind_before' => 7]);
    check(count(due_reminders([$event], $s, day('2026-12-26'))) === 1, 'Vorab-Erinnerung über den Jahreswechsel');
    $leap = validate_event(['name' => 'Schaltjahr', 'day' => 29, 'month' => 2, 'year' => 2000]);
    check(next_occurrence($leap, day('2025-01-01'), 'feb28')->format('Y-m-d') === '2025-02-28', '29. Februar auf 28. Februar');
    check(next_occurrence($leap, day('2025-01-01'), 'mar1')->format('Y-m-d') === '2025-03-01', '29. Februar auf 1. März');
    check(next_occurrence($leap, day('2097-01-01'), 'leap_only')->format('Y-m-d') === '2104-02-29', 'Schaltjahr-Lücke 2100');
    $once = validate_event(['name' => 'Einmalig', 'day' => 1, 'month' => 1, 'year' => 2026, 'repeat' => 'once']);
    check(next_occurrence($once, day('2026-01-02'), 'feb28') === null, 'Vergangene einmalige Termine wiederholen sich nicht');
    $invalid = false; try { validate_event(['name'=>'Falsch','day'=>31,'month'=>4]); } catch (InvalidArgumentException $e) { $invalid = true; }
    check($invalid, 'Unmögliches Datum wird abgewiesen');
    $preview = run_reminders(day('2027-02-03'), true);
    check(count($preview['recipients']) === 2 && !is_file($dir . '/versand.json'), 'Vorschau an beide ohne Versandprotokoll');
    check(str_contains($preview['recipients'][0]['body'], 'Guten Morgen, Papa!') && str_contains($preview['recipients'][1]['body'], 'Guten Morgen, Laura!'), 'Persönliche Ansprache je Mitglied');
    $calls = [];
    $partial = run_reminders(day('2027-02-03'), false, function($to) use (&$calls) { $calls[] = $to; return $to === 'laura@familie.xyz'; });
    check($partial['status'] === 'partial' && $partial['sent'] === 1 && $partial['failed'] === 1 && count($calls) === 2, 'Ein Empfänger erfolgreich, anderer fehlgeschlagen');
    $calls = []; $retry = run_reminders(day('2027-02-03'), false, function($to) use (&$calls) { $calls[] = $to; return true; });
    check($retry['sent'] === 1 && $calls === ['papa@familie.xyz'], 'Wiederholung nur an den fehlgeschlagenen Empfänger');
    check(run_reminders(day('2027-02-03'), false, fn() => throw new RuntimeException('Doppelte Mail'))['status'] === 'empty', 'Keine doppelten Erinnerungen');
    update_json('setup', function (&$s) { $s['mail_enabled'] = false; });
    check(run_reminders(day('2027-07-03'), false, fn() => throw new RuntimeException('Pause'))['status'] === 'paused', 'Gemeinsame Versandpause');
    $before = setup(); update_json('setup', function (&$s) { invalidate_auth($s['users']['laura'], 'Lauras-eigenes-Testpasswort'); }); $after = setup();
    check($before['users']['papa'] === $after['users']['papa'] && !$after['users']['laura']['must_change_password'], 'Passwortwechsel betrifft nur ein Konto');
    $invalid = false; try { validate_password(INITIAL_PASSWORD); } catch (InvalidArgumentException $e) { $invalid = true; }
    check($invalid, 'Veröffentlichtes Startpasswort kann nicht als eigenes Passwort gewählt werden');
    check(remember_expiry() > time() + 99 * 366 * 86400, 'Login-Tokens länger als 99 Jahre gültig');
    $public = public_user($after['users']['laura']);
    check(!isset($public['password_hash'], $public['auth_version']) && !array_key_exists('remember_tokens', $public), 'Öffentliche Kontodaten enthalten keine Zugangstokens');
    $legacy = ['owner' => 'Bisheriger Eigentümer', 'email' => 'papa@familie.xyz', 'password_hash' => password_hash('Bisheriges-Passwort-2026', PASSWORD_DEFAULT),
        'auth_version' => 'old-version', 'remember_tokens' => ['old-token' => []], 'reset' => ['old-code'],
        'timezone' => 'Europe/Vienna', 'mail_enabled' => true, 'leap_day' => 'mar1', 'setup_key' => 'old-key'];
    update_json('setup', function (&$s) use ($legacy) { $s = $legacy; });
    initialize(); $migrated = setup(); $papa = $migrated['users']['papa'];
    check(array_keys($migrated['users']) === ['papa'] && $papa['name'] === $legacy['owner'] && $papa['email'] === $legacy['email'], 'Altes Einzelkonto wird ausschließlich Papa zugeordnet');
    check($papa['password_hash'] === $legacy['password_hash'] && !$papa['must_change_password'] && password_verify('Bisheriges-Passwort-2026', $papa['password_hash']), 'Bisheriges persönliches Passwort bleibt gültig');
    check($papa['remember_tokens'] === [] && $papa['reset'] === null && $papa['auth_version'] !== $legacy['auth_version'], 'Migration verwirft alte Sitzungen und Codes');
    check($migrated['timezone'] === 'Europe/Vienna' && $migrated['mail_enabled'] && $migrated['leap_day'] === 'mar1' && read_json('termine') === $events, 'Migration erhält Einstellungen und Termine');
    $backups = glob($dir . '/setup.legacy-*.json');
    check(count($backups) === 1 && json_decode(file_get_contents($backups[0]), true) === $legacy, 'Originalkonto vor Migration vollständig gesichert');
    initialize(); check(setup() === $migrated && count(glob($dir . '/setup.legacy-*.json')) === 1, 'Migration wird nur einmal ausgeführt');
    $legacy['email'] = 'unknown@familie.xyz';
    update_json('setup', function (&$s) use ($legacy) { $s = $legacy; });
    $invalid = false; try { initialize(); } catch (RuntimeException $e) { $invalid = true; }
    check($invalid && setup() === $legacy, 'Nicht zuordenbares Altkonto bleibt unverändert');
    $legacy['email'] = 'papa@familie.xyz'; $legacy['password_hash'] = password_hash(INITIAL_PASSWORD, PASSWORD_DEFAULT);
    update_json('setup', function (&$s) use ($legacy) { $s = $legacy; });
    initialize(); check(setup()['users']['papa']['must_change_password'], 'Altes Startpasswort erfordert weiterhin persönlichen Passwortwechsel');
    echo "\n$checks Prüfungen erfolgreich. Keine echten Mails verschickt.\n";
} finally { foreach (glob($dir . '/*') as $file) if (is_file($file)) unlink($file); rmdir($dir); }
