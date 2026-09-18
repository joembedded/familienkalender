<?php
declare(strict_types=1);

const COOKIE_NAME = 'familienkalender_login';
const COOKIE_DAYS = 400;
const REMEMBER_YEARS = 100;
const INITIAL_PASSWORD = 'familienkalender';

require __DIR__ . '/storage.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/calendar.php';
require __DIR__ . '/mail.php';

function config(): array {
    static $config;
    if ($config !== null) return $config;
    $path = defined('CALENDAR_CONFIG') ? CALENDAR_CONFIG : dirname(__DIR__) . '/config.php';
    if (!is_file($path)) throw new RuntimeException('Bitte zuerst config.example.php als private config.php einrichten.');
    $value = require $path;
    if (!is_array($value)) throw new RuntimeException('Die private Konfiguration ist ungültig.');
    foreach (['group_name', 'sender_email', 'setup_key', 'initial_members'] as $key) {
        if (empty($value[$key])) throw new RuntimeException('Die private Konfiguration ist unvollständig: ' . $key);
    }
    if (!filter_var($value['sender_email'], FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-zA-Z0-9.!_+@-]+$/D', $value['sender_email'])) throw new RuntimeException('Ungültiger Mailabsender.');
    if (strlen($value['setup_key']) < 24 || str_contains($value['setup_key'], 'BITTE-ERSETZEN')) throw new RuntimeException('Bitte einen eigenen Einrichtungscode mit mindestens 24 Zeichen in config.php eintragen.');
    $value += ['base_url' => '', 'background_image' => '', 'timezone' => 'Europe/Berlin', 'mail_enabled' => false];
    if (!in_array($value['timezone'], DateTimeZone::listIdentifiers(), true)) throw new RuntimeException('Ungültige Zeitzone.');
    if ($value['base_url'] !== '' && (!filter_var($value['base_url'], FILTER_VALIDATE_URL) || !in_array(parse_url($value['base_url'], PHP_URL_SCHEME), ['http', 'https'], true))) throw new RuntimeException('Ungültige Kalenderadresse.');
    if ($value['background_image'] !== '' && !preg_match('~^assets/[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp)$~D', $value['background_image'])) throw new RuntimeException('Das Hintergrundbild muss in assets liegen.');
    return $config = $value;
}
function initialize(): void {
    $c = config();
    if (!is_dir(data_dir()) || !is_writable(data_dir())) throw new RuntimeException('Der PHP-Benutzer benötigt Schreibrechte auf data.');
    if (!is_file(data_dir() . '/setup.json')) {
        update_json('setup', function (&$s) use ($c) {
            if ($s) return;
            $users = []; $emails = [];
            foreach ($c['initial_members'] as $id => $member) {
                if (!preg_match('/^[a-z0-9_-]{1,40}$/D', (string)$id)) throw new RuntimeException('Ungültige Mitgliedskennung.');
                $name = text_value($member['name'] ?? '', 100, 'Name'); $email = valid_email($member['email'] ?? '');
                if ($name === '' || in_array(strtolower($email), $emails, true)) throw new RuntimeException('Mitglieder brauchen Namen und unterschiedliche Mailadressen.');
                $emails[] = strtolower($email); $users[$id] = new_user((string)$id, $name, $email);
            }
            if (!$users) throw new RuntimeException('Mindestens ein Mitglied ist erforderlich.');
            $s = ['timezone' => $c['timezone'], 'leap_day' => 'feb28', 'mail_enabled' => (bool)$c['mail_enabled'], 'users' => $users];
        });
    }
    if (!is_file(data_dir() . '/termine.json')) {
        update_json('termine', function (&$events) {
            if ($events) return;
            $seed = json_decode(file_get_contents(dirname(__DIR__) . '/data/termine.example.json'), true, 512, JSON_THROW_ON_ERROR);
            $events = array_map('validate_event', $seed);
        });
    }
}
function setup(): array { return read_json('setup'); }
function today(?array $settings = null): DateTimeImmutable {
    return new DateTimeImmutable('today', new DateTimeZone(($settings ?? setup())['timezone']));
}
function valid_email(mixed $value): string {
    $email = text_value($value, 254, 'E-Mail');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) throw new InvalidArgumentException('Bitte eine gültige Mailadresse eingeben.');
    return $email;
}
function public_user(array $user): array {
    return array_intersect_key($user, array_flip(['id', 'name', 'email', 'must_change_password']));
}
