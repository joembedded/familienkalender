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
    check(count($s['users']) === 2 && count($events) === 3, 'Zwei Konten und drei öffentliche Beispiele');
    $invitation = invitation_message($s['users']['mama']);
    check(str_contains($invitation['body'], $config['setup_key']) && str_contains($invitation['body'], INITIAL_PASSWORD) && str_contains($invitation['body'], $config['base_url']), 'Einladung enthält Zugangsdaten und Kalenderadresse');
    check($s['users']['mama']['password_hash'] !== $s['users']['papa']['password_hash'] && password_verify(INITIAL_PASSWORD, $s['users']['mama']['password_hash']), 'Getrennte Passwort-Hashes und gültiges Startpasswort');
    check($s['users']['mama']['must_change_password'] && $s['users']['papa']['must_change_password'], 'Passwortwechsel für beide erforderlich');
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
    check(str_contains($preview['recipients'][0]['body'], 'Guten Morgen, Mama!') && str_contains($preview['recipients'][1]['body'], 'Guten Morgen, Papa!'), 'Persönliche Ansprache je Mitglied');
    $calls = [];
    $partial = run_reminders(day('2027-02-03'), false, function($to) use (&$calls) { $calls[] = $to; return $to === 'mama@familie.xyz'; });
    check($partial['status'] === 'partial' && $partial['sent'] === 1 && $partial['failed'] === 1 && count($calls) === 2, 'Ein Empfänger erfolgreich, anderer fehlgeschlagen');
    $calls = []; $retry = run_reminders(day('2027-02-03'), false, function($to) use (&$calls) { $calls[] = $to; return true; });
    check($retry['sent'] === 1 && $calls === ['papa@familie.xyz'], 'Wiederholung nur an den fehlgeschlagenen Empfänger');
    check(run_reminders(day('2027-02-03'), false, fn() => throw new RuntimeException('Doppelte Mail'))['status'] === 'empty', 'Keine doppelten Erinnerungen');
    update_json('setup', function (&$s) { $s['mail_enabled'] = false; });
    check(run_reminders(day('2027-07-03'), false, fn() => throw new RuntimeException('Pause'))['status'] === 'paused', 'Gemeinsame Versandpause');
    $before = setup(); update_json('setup', function (&$s) { invalidate_auth($s['users']['mama'], 'Mamas-eigenes-Testpasswort'); }); $after = setup();
    check($before['users']['papa'] === $after['users']['papa'] && !$after['users']['mama']['must_change_password'], 'Passwortwechsel betrifft nur ein Konto');
    $invalid = false; try { validate_password(INITIAL_PASSWORD); } catch (InvalidArgumentException $e) { $invalid = true; }
    check($invalid, 'Veröffentlichtes Startpasswort kann nicht als eigenes Passwort gewählt werden');
    check(remember_expiry() > time() + 99 * 366 * 86400, 'Login-Tokens länger als 99 Jahre gültig');
    $public = public_user($after['users']['mama']);
    check(!isset($public['password_hash'], $public['auth_version']) && !array_key_exists('remember_tokens', $public), 'Öffentliche Kontodaten enthalten keine Zugangstokens');
    echo "\n$checks Prüfungen erfolgreich. Keine echten Mails verschickt.\n";
} finally { foreach (glob($dir . '/*') as $file) if (is_file($file)) unlink($file); rmdir($dir); }
