<?php
declare(strict_types=1);

function send_mail(string $to, string $subject, string $body): bool {
    $to = valid_email($to); $sender = config()['sender_email'];
    $headers = ['From' => "Familienkalender <$sender>", 'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8', 'Content-Transfer-Encoding' => 'base64'];
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?='; $body = chunk_split(base64_encode($body));
    if (PHP_OS_FAMILY === 'Windows' && trim((string)ini_get('sendmail_path')) === '') {
        $previous = ini_get('sendmail_from'); ini_set('sendmail_from', $sender);
        try { return @mail($to, $subject, $body, $headers); }
        finally { if ($previous !== false) ini_set('sendmail_from', $previous); }
    }
    return @mail($to, $subject, $body, $headers, '-f' . $sender);
}
function reminder_message(array $due, array $member, DateTimeImmutable $date): array {
    $lines = ["Guten Morgen, {$member['name']}!", '', 'Diese Anlässe stehen in eurem Familienkalender:', ''];
    foreach ($due as $r) {
        $e = $r['event']; $age = $e['year'] === null || $e['repeat'] === 'once' ? '' : ' (' . ((int)substr($r['date'], 0, 4) - $e['year']) . ' Jahre)';
        $when = $r['offset'] === 0 ? 'Heute' : 'In ' . $r['offset'] . ' Tagen';
        $lines[] = "$when: {$e['name']} – {$e['occasion']}$age, " . (new DateTimeImmutable($r['date']))->format('d.m.Y');
        if ($e['notes'] !== '') $lines[] = 'Notizen: ' . $e['notes'];
        $lines[] = '';
    }
    $lines[] = 'Einen schönen Tag!'; $lines[] = config()['group_name'];
    if (config()['base_url'] !== '') $lines[] = config()['base_url'];
    return ['subject' => 'Familien-Erinnerungen für den ' . $date->format('d.m.Y'), 'body' => implode("\n", $lines)];
}
function run_reminders(DateTimeImmutable $date, bool $dryRun = false, ?callable $transport = null): array {
    $lock = fopen(data_dir() . '/cron.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Ein Erinnerungslauf ist bereits aktiv.');
    try {
        $s = setup(); $state = read_json('versand'); $due = due_reminders(read_json('termine'), $s, $date);
        $plans = [];
        foreach ($s['users'] as $user) {
            $pending = array_values(array_filter($due, fn($r) => !isset($state['sent'][$user['id'] . ':' . $r['key']])));
            if ($pending) $plans[] = ['user' => $user, 'due' => $pending, 'message' => reminder_message($pending, $user, $date)];
        }
        if ($dryRun) return ['status' => 'preview', 'enabled' => $s['mail_enabled'], 'from' => config()['sender_email'], 'recipients' => array_map(fn($p) => ['name' => $p['user']['name'], 'to' => $p['user']['email'], 'count' => count($p['due'])] + $p['message'], $plans)];
        $sent = 0; $failed = 0;
        if ($s['mail_enabled']) foreach ($plans as $plan) {
            $send = $transport ?? 'send_mail';
            try { $ok = $send($plan['user']['email'], $plan['message']['subject'], $plan['message']['body']); }
            catch (Throwable $e) { error_log('Familienkalender-Mail: ' . $e->getMessage()); $ok = false; }
            if (!$ok) { $failed++; continue; }
            // Commit each recipient immediately; retries do not resend successful recipients.
            update_json('versand', function (&$state) use ($plan) {
                foreach ($plan['due'] as $r) $state['sent'][$plan['user']['id'] . ':' . $r['key']] = time();
                $state['last_sent'] = date(DATE_ATOM);
            });
            $sent++;
        }
        $status = !$s['mail_enabled'] ? 'paused' : ($failed ? ($sent ? 'partial' : 'failed') : ($sent ? 'sent' : 'empty'));
        update_json('versand', function (&$state) use ($date, $status, $sent, $failed) {
            $state['last_run'] = date(DATE_ATOM); $state['last_date'] = $date->format('Y-m-d'); $state['status'] = $status;
            $state['last_count'] = $sent; $state['failed_count'] = $failed;
            $state['sent'] = array_filter($state['sent'] ?? [], fn($timestamp) => $timestamp > time() - 800 * 86400);
        });
        return ['status' => $status, 'sent' => $sent, 'failed' => $failed];
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
