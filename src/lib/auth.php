<?php
declare(strict_types=1);

function local_request(): bool { return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true); }
function cookie_options(int $expires): array {
    $path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return ['expires' => $expires, 'path' => rtrim($path, '/') . '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax'];
}
function web_bootstrap(?string $scriptNonce = null): void {
    initialize();
    header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    $scriptPolicy = "'self'";
    if ($scriptNonce !== null) $scriptPolicy .= " 'nonce-" . $scriptNonce . "'";
    header("Content-Security-Policy: default-src 'self'; script-src $scriptPolicy; style-src 'self'; img-src 'self'; connect-src 'self'; manifest-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
    $sessions = data_dir() . '/sessions';
    if (!is_dir($sessions) && !mkdir($sessions, 0700, true) && !is_dir($sessions)) throw new RuntimeException('Sitzung kann nicht angelegt werden.');
    session_save_path($sessions); session_name('familienkalender_session');
    $options = cookie_options(0); unset($options['expires']); $options['lifetime'] = 0;
    session_set_cookie_params($options); session_start(); $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function new_user(string $id, string $name, string $email): array {
    return ['id' => $id, 'name' => $name, 'email' => $email, 'password_hash' => password_hash(INITIAL_PASSWORD, PASSWORD_DEFAULT),
        'must_change_password' => true, 'auth_version' => bin2hex(random_bytes(16)), 'remember_tokens' => [], 'reset' => null];
}
function validate_password(string $password): void {
    if (strlen($password) < 10 || strlen($password) > 72) throw new InvalidArgumentException('Bitte ein Passwort mit mindestens 10 Zeichen und höchstens 72 Bytes wählen.');
    if (hash_equals(INITIAL_PASSWORD, $password)) throw new InvalidArgumentException('Bitte ein eigenes Passwort statt des veröffentlichten Startpassworts wählen.');
}
function invalidate_auth(array &$user, string $password): void {
    validate_password($password); $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    $user['auth_version'] = bin2hex(random_bytes(16)); $user['remember_tokens'] = []; $user['reset'] = null; $user['must_change_password'] = false;
}
function remember_expiry(): int { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . REMEMBER_YEARS . ' years')->getTimestamp(); }
function find_user(array $s, string $identity): ?array {
    $identity = strtolower(trim($identity));
    foreach ($s['users'] as $user) if (strtolower($user['id']) === $identity || strtolower($user['email']) === $identity) return $user;
    return null;
}
function authenticated_user(array $s): ?array {
    $user = $s['users'][$_SESSION['uid'] ?? ''] ?? null;
    $sessionUser = $user && isset($_SESSION['auth']) && hash_equals($user['auth_version'], $_SESSION['auth']) ? $user : null;
    $cookie = $_COOKIE[COOKIE_NAME] ?? '';
    if (!preg_match('/^([a-z0-9_-]{1,40})\.([a-f0-9]{24})\.([a-f0-9]{64})$/D', $cookie, $m)) return $sessionUser;
    if ($sessionUser && $sessionUser['id'] !== $m[1]) return $sessionUser;
    $user = $s['users'][$m[1]] ?? null; $token = $user['remember_tokens'][$m[2]] ?? null;
    if (!$user || $user['must_change_password'] || !$token || $token['expires'] <= time() || !hash_equals($token['hash'], hash('sha256', $m[3]))) return $sessionUser;
    if (($token['renewed_at'] ?? 0) <= time() - 86400) {
        $valid = update_json('setup', function (&$current) use ($m, $user) {
            $account = $current['users'][$m[1]] ?? null; $token = $account['remember_tokens'][$m[2]] ?? null;
            if (!$account || !hash_equals($account['auth_version'], $user['auth_version']) || !$token || $token['expires'] <= time() || !hash_equals($token['hash'], hash('sha256', $m[3]))) return false;
            $current['users'][$m[1]]['remember_tokens'][$m[2]]['expires'] = remember_expiry();
            $current['users'][$m[1]]['remember_tokens'][$m[2]]['renewed_at'] = time(); return true;
        });
        if (!$valid) return null;
    }
    if (!$sessionUser) session_regenerate_id(true);
    $_SESSION['uid'] = $user['id']; $_SESSION['auth'] = $user['auth_version'];
    setcookie(COOKIE_NAME, $cookie, cookie_options(time() + COOKIE_DAYS * 86400)); return $user;
}
function sign_in(string $id, string $expectedVersion, bool $remember): void {
    $selector = bin2hex(random_bytes(12)); $secret = bin2hex(random_bytes(32));
    $user = update_json('setup', function (&$s) use ($id, $expectedVersion, $remember, $selector, $secret) {
        if (!isset($s['users'][$id]) || !hash_equals($s['users'][$id]['auth_version'], $expectedVersion)) throw new InvalidArgumentException('Der Zugang hat sich geändert. Bitte erneut anmelden.');
        $u = &$s['users'][$id]; $u['remember_tokens'] = array_filter($u['remember_tokens'], fn($t) => $t['expires'] > time());
        if ($remember && !$u['must_change_password']) {
            $u['remember_tokens'][$selector] = ['hash' => hash('sha256', $secret), 'expires' => remember_expiry(), 'renewed_at' => time()];
            $u['remember_tokens'] = array_slice($u['remember_tokens'], -20, null, true);
        }
        return $u;
    });
    session_regenerate_id(true); $_SESSION['uid'] = $id; $_SESSION['auth'] = $user['auth_version'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32)); $_SESSION['remember'] = $remember;
    $persistent = $remember && !$user['must_change_password'];
    setcookie(COOKIE_NAME, $persistent ? "$id.$selector.$secret" : '', cookie_options($persistent ? time() + COOKIE_DAYS * 86400 : time() - 3600));
}
function require_fresh_user(array $s, array $actor): array {
    $user = $s['users'][$actor['id']] ?? null;
    if (!$user || !hash_equals($user['auth_version'], $actor['auth_version'])) throw new InvalidArgumentException('Dein Zugang hat sich geändert. Bitte neu anmelden.');
    return $user;
}
