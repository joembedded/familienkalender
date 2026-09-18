<?php
declare(strict_types=1);
require __DIR__ . '/lib/app.php';
header('Content-Type: application/json; charset=utf-8');
function reply(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data + ['csrf' => $_SESSION['csrf'] ?? ''], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
}
try {
    web_bootstrap();
    $action = $_GET['action'] ?? 'state'; $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 25000) reply(['error' => 'Die Eingabe ist zu groß.'], 413);
        $input = json_decode(file_get_contents('php://input'), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($input)) reply(['error' => 'Ungültige Eingabe.'], 400);
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) reply(['error' => 'Sitzung abgelaufen. Bitte neu laden.'], 403);
        $action = $input['action'] ?? '';
    } elseif (!in_array($action, ['state', 'export'], true)) reply(['error' => 'POST erforderlich.'], 405);
    $s = setup(); $user = authenticated_user($s); $c = config();
    if ($action === 'state') {
        $state = ['authenticated' => $user !== null, 'group_name' => $c['group_name'], 'background_image' => $c['background_image'], 'local' => local_request()];
        if ($user) {
            $state['user'] = public_user($user);
            if (!$user['must_change_password']) {
                $events = read_json('termine');
                $state += ['events' => enriched_events($events, $s), 'revision' => revision($events), 'today' => today($s)->format('Y-m-d'),
                    'settings' => array_intersect_key($s, array_flip(['timezone', 'mail_enabled', 'leap_day'])),
                    'members' => array_values(array_map('public_user', $s['users'])),
                    'delivery' => array_intersect_key(read_json('versand'), array_flip(['last_run', 'last_sent', 'last_count', 'failed_count', 'status'])), 'sender' => $c['sender_email']];
            }
        }
        reply($state);
    }
    if ($action === 'login') {
        $identity = text_value($input['identity'] ?? '', 254, 'Kennung');
        rate_limit('login_ip_' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 40, 900);
        rate_limit('login_user_' . hash('sha256', strtolower($identity)), 20, 900);
        $target = find_user($s, $identity);
        if (!$target || !password_verify((string)($input['password'] ?? ''), $target['password_hash'])) reply(['error' => 'Kennung oder Passwort stimmt nicht.'], 401);
        if ($target['must_change_password'] && !local_request() && !hash_equals($c['setup_key'], (string)($input['setup_key'] ?? ''))) reply(['error' => 'Für die erste Anmeldung bitte zusätzlich den privaten Einrichtungscode eingeben.'], 403);
        sign_in($target['id'], $target['auth_version'], (bool)($input['remember'] ?? true)); reply(['ok' => true]);
    }
    if ($action === 'recover') {
        $identity = text_value($input['identity'] ?? '', 254, 'Kennung');
        rate_limit('recover_ip_' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 10, 3600);
        $target = find_user($s, $identity);
        $message = 'Falls ein passendes Konto vorhanden ist, wurde ein Code an dessen Mailadresse übergeben. Er gilt 15 Minuten.';
        if (!$target) reply(['ok' => true, 'message' => $message]);
        rate_limit('recover_user_' . $target['id'], 3, 3600);
        $code = (string)random_int(10000000, 99999999); $hash = password_hash($code, PASSWORD_DEFAULT);
        update_json('setup', function (&$current) use ($target, $hash) {
            require_fresh_user($current, $target);
            $current['users'][$target['id']]['reset'] = ['hash' => $hash, 'expires' => time() + 900, 'attempts' => 0];
        });
        $body = "Hallo {$target['name']},\n\ndein Code für ein neues Passwort lautet:\n\n$code\n\nEr gilt 15 Minuten und nur einmal. Öffne den Familienkalender und wähle „Code eingeben“.\n" . $c['base_url'] . "\n\nNicht angefordert? Ignoriere diese Mail; dein Passwort bleibt unverändert.\n\n" . $c['group_name'];
        if (!send_mail($target['email'], 'Dein Passwort-Code für den Familienkalender', $body)) {
            update_json('setup', function (&$current) use ($target, $hash) {
                if (($current['users'][$target['id']]['reset']['hash'] ?? '') === $hash) $current['users'][$target['id']]['reset'] = null;
            });
            reply(['error' => 'Die Mail konnte nicht verschickt werden. Bitte die Mailkonfiguration am Server prüfen.'], 503);
        }
        reply(['ok' => true, 'message' => $message]);
    }
    if ($action === 'reset') {
        rate_limit('reset_ip_' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 20, 900);
        $identity = text_value($input['identity'] ?? '', 254, 'Kennung'); $password = (string)($input['password'] ?? ''); validate_password($password);
        $changed = update_json('setup', function (&$current) use ($identity, $password, $input) {
            $target = find_user($current, $identity); if (!$target) return null;
            $u = &$current['users'][$target['id']]; $reset = $u['reset'];
            if (!$reset || $reset['expires'] <= time() || $reset['attempts'] >= 8) return null;
            $u['reset']['attempts']++;
            if (!password_verify((string)($input['code'] ?? ''), $reset['hash'])) return null;
            invalidate_auth($u, $password); return $u;
        });
        if (!$changed) reply(['error' => 'Kennung oder Code ist ungültig oder abgelaufen.'], 400);
        sign_in($changed['id'], $changed['auth_version'], true); reply(['ok' => true]);
    }
    if (!$user) reply(['error' => 'Bitte zuerst anmelden.'], 401);
    if ($action === 'logout') {
        $parts = explode('.', $_COOKIE[COOKIE_NAME] ?? '');
        update_json('setup', function (&$current) use ($parts, $user) {
            if (($parts[0] ?? '') === $user['id']) unset($current['users'][$user['id']]['remember_tokens'][$parts[1] ?? '']);
        });
        $_SESSION = []; session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32));
        setcookie(COOKIE_NAME, '', cookie_options(time() - 3600)); reply(['ok' => true]);
    }
    if ($action === 'profile') {
        $email = valid_email($input['email'] ?? $user['email']); $name = text_value($input['name'] ?? $user['name'], 100, 'Name');
        if ($name === '') throw new InvalidArgumentException('Bitte einen Namen angeben.');
        $new = (string)($input['new_password'] ?? '');
        $changed = update_json('setup', function (&$current) use ($input, $user, $email, $name, $new) {
            $u = require_fresh_user($current, $user);
            if (!password_verify((string)($input['current_password'] ?? ''), $u['password_hash'])) throw new InvalidArgumentException('Bitte dein aktuelles Passwort eingeben.');
            if ($u['must_change_password'] && $new === '') throw new InvalidArgumentException('Bitte zuerst dein eigenes Passwort festlegen.');
            foreach ($current['users'] as $other) if ($other['id'] !== $u['id'] && strcasecmp($other['email'], $email) === 0) throw new InvalidArgumentException('Diese Mailadresse wird bereits verwendet.');
            if ($new !== '') {
                if (password_verify($new, $u['password_hash'])) throw new InvalidArgumentException('Das neue Passwort muss sich vom bisherigen unterscheiden.');
                invalidate_auth($u, $new);
            }
            if ($email !== $u['email']) $u['reset'] = null;
            $u['email'] = $email; $u['name'] = $name; $current['users'][$u['id']] = $u; return $u;
        });
        if ($new !== '') sign_in($changed['id'], $changed['auth_version'], $_SESSION['remember'] ?? true);
        reply(['ok' => true]);
    }
    if ($user['must_change_password']) reply(['error' => 'Vor dem Kalenderzugriff bitte dein persönliches Passwort festlegen.'], 403);
    if ($action === 'save' || $action === 'delete') {
        $event = $action === 'save' ? validate_event($input['event'] ?? []) : null;
        update_json('termine', function (&$events) use ($input, $action, $event, $user) {
            require_fresh_user(setup(), $user);
            if (!hash_equals(revision($events), (string)($input['revision'] ?? ''))) throw new DomainException('Die Liste wurde inzwischen geändert. Bitte deine Eingaben kopieren, neu laden und erneut speichern.');
            $id = $input['id'] ?? ''; $index = array_search($id, array_column($events, 'id'), true);
            if ($id !== '' && $index === false) throw new InvalidArgumentException('Der Termin wurde nicht gefunden.');
            if ($action === 'delete') { if ($index === false) throw new InvalidArgumentException('Der Termin wurde nicht gefunden.'); array_splice($events, $index, 1); }
            elseif ($index === false) { $event['id'] = bin2hex(random_bytes(12)); $events[] = $event; }
            else { $event['id'] = $id; $events[$index] = $event; }
        }); reply(['ok' => true]);
    }
    if ($action === 'settings') {
        $leap = $input['leap_day'] ?? ''; $timezone = $input['timezone'] ?? '';
        if (!in_array($leap, ['feb28', 'mar1', 'leap_only'], true) || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) throw new InvalidArgumentException('Ungültige Datums-Einstellung.');
        update_json('setup', function (&$current) use ($input, $user, $leap, $timezone) {
            require_fresh_user($current, $user); $current['leap_day'] = $leap; $current['timezone'] = $timezone; $current['mail_enabled'] = (bool)($input['mail_enabled'] ?? false);
        }); reply(['ok' => true]);
    }
    if ($action === 'add_member' || $action === 'remove_member') {
        update_json('setup', function (&$current) use ($input, $user, $action) {
            $actor = require_fresh_user($current, $user);
            if (!password_verify((string)($input['current_password'] ?? ''), $actor['password_hash'])) throw new InvalidArgumentException('Bitte dein aktuelles Passwort zur Bestätigung eingeben.');
            if ($action === 'remove_member') {
                $id = (string)($input['id'] ?? '');
                if ($id === $actor['id']) throw new InvalidArgumentException('Das eigene Konto kann hier nicht entfernt werden.');
                if (!isset($current['users'][$id])) throw new InvalidArgumentException('Mitglied nicht gefunden.');
                unset($current['users'][$id]);
            } else {
                $email = valid_email($input['email'] ?? ''); $name = text_value($input['name'] ?? '', 100, 'Name');
                if ($name === '') throw new InvalidArgumentException('Bitte einen Namen angeben.');
                foreach ($current['users'] as $other) if (strcasecmp($email, $other['email']) === 0) throw new InvalidArgumentException('Diese Mailadresse wird bereits verwendet.');
                if (count($current['users']) >= 100) throw new InvalidArgumentException('Maximal 100 Mitglieder pro Kalender.');
                $id = bin2hex(random_bytes(12)); $current['users'][$id] = new_user($id, $name, $email);
            }
        }); reply(['ok' => true]);
    }
    if ($action === 'test_mail') {
        rate_limit('test_mail_' . $user['id'], 5, 3600);
        if (!send_mail($user['email'], 'Ein Hallo aus eurem Familienkalender', "Hallo {$user['name']},\n\ndie Testmail für dein Konto wurde verschickt. Die täglichen Erinnerungen gehen per CRON an alle Mitglieder.\n\n" . $c['group_name'])) reply(['error' => 'PHP mail() hat den Versand abgelehnt. Bitte SMTP bzw. Sendmail am Server prüfen.'], 503);
        reply(['ok' => true, 'message' => 'Die Testmail wurde für deine eigene Mailadresse an den Maildienst übergeben.']);
    }
    if ($action === 'export') {
        header('Content-Disposition: attachment; filename="termine-' . today($s)->format('Y-m-d') . '.json"');
        echo json_encode(read_json('termine'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
    }
    reply(['error' => 'Unbekannte Aktion.'], 400);
} catch (DomainException $e) { reply(['error' => $e->getMessage()], 409);
} catch (InvalidArgumentException | JsonException $e) { reply(['error' => $e->getMessage()], 400);
} catch (Throwable $e) { error_log('Familienkalender: ' . $e->getMessage()); reply(['error' => 'Serverkonfiguration oder Datenspeicher prüfen. Hinweise stehen in der README.'], 500); }
