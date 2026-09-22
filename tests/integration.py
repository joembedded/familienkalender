"""Apache integration checks in a disposable, local-only installation."""
import argparse
import http.cookiejar
import json
import re
from pathlib import Path
import shutil
import time
import urllib.error
import urllib.request
import uuid

parser = argparse.ArgumentParser()
parser.add_argument('--base-url', default='http://localhost/wrk/familienkalender/')
parser.add_argument('--keep-fixture', action='store_true')
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
fixture = root / 'output' / ('http-' + uuid.uuid4().hex)
base = args.base_url.rstrip('/') + '/output/' + fixture.name + '/'
shutil.copytree(root / 'src', fixture, ignore=shutil.ignore_patterns('config.php', 'setup.json', 'termine.json', 'versand.json', 'limits.json', 'sessions', '*.lock', 'hintergrund.jpg', 'private-*', 'setup.legacy-*', '*.log'))
(fixture.parent / '.htaccess').write_text('Require all denied\n', encoding='utf-8')
with (fixture / '.htaccess').open('a', encoding='utf-8') as handle: handle.write('\nRequire local\n')
config = (fixture / 'config.example.php').read_text(encoding='utf-8').replace('BITTE-ERSETZEN-DURCH-EINEN-ZUFAELLIGEN-CODE', 'local-test-key-' * 4)
(fixture / 'config.php').write_text(config, encoding='utf-8')
(fixture / 'remote.php').write_text("<?php $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; require __DIR__ . '/api.php';", encoding='utf-8')
# All mail in this disposable installation goes to an outbox, never to PHP mail().
mail_path = fixture / 'lib/mail.php'
mail_source = mail_path.read_text(encoding='utf-8').replace('function send_mail(', 'function unused_real_send_mail(', 1)
mail_source += """
function send_mail(string $to, string $subject, string $body): bool {
    if (is_file(data_dir() . '/fail-mail')) return false;
    update_json('outbox', function (&$messages) use ($to, $subject, $body) {
        $messages[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    });
    return true;
}
"""
mail_path.write_text(mail_source, encoding='utf-8')
setup_path = fixture / 'data/setup.json'
password_laura = 'Laura-persoenlich-2026'
password_papa = 'Papa-persoenlich-2026'

class Client:
    def __init__(self, endpoint='api.php'):
        self.endpoint = endpoint
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))
        self.csrf = ''
    def call(self, action='state', expected=200, csrf=True, **kwargs):
        get = action in ('state', 'export')
        headers = {'Content-Type': 'application/json'}
        if csrf: headers['X-CSRF-Token'] = self.csrf
        req = urllib.request.Request(base + self.endpoint + ('?action=' + action if get else ''),
            data=None if get else json.dumps(dict(action=action, **kwargs)).encode(), headers=headers)
        try: response = self.opener.open(req)
        except urllib.error.HTTPError as error: response = error
        raw = response.read().decode()
        assert response.status == expected, (action, response.status, raw)
        result = json.loads(raw)
        if isinstance(result, dict) and result.get('csrf'): self.csrf = result['csrf']
        return result

checks = 0
def check(ok, label):
    global checks
    assert ok, label
    checks += 1
    print('OK:', label)

laura, papa = Client(), Client()
anonymous = laura.call()
check(not anonymous['authenticated'] and 'members' not in anonymous and 'events' not in anonymous, 'Keine privaten Daten ohne Anmeldung')
check(list(json.loads(setup_path.read_text(encoding='utf-8'))['users']) == ['papa'], 'Neue Installation enthält nur Papa')
laura.call('export', expected=401)
laura.call('login', expected=401, identity='ute', password='familienkalender')
laura.call('save', expected=403, csrf=False)
check(True, 'CSRF-Schutz')
remote = Client('remote.php'); remote.call()
remote.call('login', expected=403, identity='papa', password='familienkalender')
remote.call('login', identity='papa', password='familienkalender', setup_key='local-test-key-' * 4)
check(remote.call()['user']['must_change_password'], 'Remote-Erstanmeldung benötigt privaten Einrichtungscode')
papa.call()
papa.call('login', identity='papa', password='familienkalender')
papa.call('profile', current_password='familienkalender', new_password=password_papa)
papa.call('add_member', current_password=password_papa, name='Laura', email='laura@familie.xyz')
invitation = json.loads((fixture / 'data/outbox.json').read_text(encoding='utf-8'))[-1]
check(invitation['to'] == 'laura@familie.xyz' and 'familienkalender' in invitation['body'] and 'local-test-key-' * 4 in invitation['body'], 'Einladung enthält Startpasswort und langen Einrichtungscode')
laura_id = next(m['id'] for m in papa.call()['members'] if m['email'] == 'laura@familie.xyz')
papa.call('resend_invitation', id=laura_id)
check(len(json.loads((fixture / 'data/outbox.json').read_text(encoding='utf-8'))) == 2, 'Einladung erneut senden')
laura.call('login', identity='laura@familie.xyz', password='familienkalender')
pending = laura.call()
check(pending['user']['must_change_password'] and 'events' not in pending and 'members' not in pending, 'Erstanmeldung bleibt bis Passwortwechsel eingeschränkt')
laura.call('export', expected=403)
laura.call('save', expected=403, event={})
laura.call('settings', expected=403)
laura.call('add_member', expected=403)
check(True, 'Eingeschränkte Sitzung kann weder Termine noch Mitglieder ändern')
laura.call('profile', expected=400, current_password='familienkalender', new_password='familienkalender')
laura.call('profile', current_password='familienkalender', new_password=password_laura)
current = laura.call()
check(len(current['events']) == 3 and len(current['members']) == 2 and not current['user']['must_change_password'], 'Persönliches Passwort schaltet Kalender frei')
check(all('password_hash' not in u and 'remember_tokens' not in u and 'auth_version' not in u for u in current['members']), 'Keine fremden Zugangsdaten in der Mitgliederliste')
papa.call()
papa.call('login', expected=401, identity='papa', password=password_laura)
papa.call('login', identity='papa', password=password_papa)
check(papa.call()['user']['id'] == 'papa', 'Eigenes Passwort und eigenes Konto für Papa')
cookie = next(c for c in laura.cookies if c.name == 'familienkalender_login')
check(cookie.expires > time.time() + 399 * 86400 and 'HttpOnly' in cookie._rest and cookie._rest.get('SameSite') == 'Lax', 'Dauerhafter HttpOnly-Cookie mit SameSite')
restarted = Client(); restarted.cookies.set_cookie(cookie)
check(restarted.call()['user']['id'] == laura_id, 'Dauerhafter Cookie stellt das richtige Konto wieder her')
stale = laura.call()
event = dict(name='Familientreffen <Test>', day=31, month=12, year='', occasion='Treffen', notes='Gruß & Grüße', repeat='yearly', enabled=True, remind_before=7)
laura.call('save', revision=stale['revision'], event=event)
shared = papa.call(); created = next(e for e in shared['events'] if e['name'] == event['name'])
check(created['notes'] == event['notes'], 'Papa sieht Lauras Termin und Notizen')
papa.call('save', expected=409, revision=stale['revision'], id=created['id'], event=event)
event['notes'] = 'Von Papa bearbeitet'
papa.call('save', revision=shared['revision'], id=created['id'], event=event)
check(next(e for e in laura.call()['events'] if e['id'] == created['id'])['notes'] == event['notes'], 'Gleiche Bearbeitungsrechte und Konfliktschutz')
papa.call('delete', revision=papa.call()['revision'], id=created['id'])
check(len(laura.call()['events']) == 3, 'Gemeinsames Löschen')
settings = papa.call()['settings']; settings['leap_day'] = 'mar1'
papa.call('settings', **settings)
check(laura.call()['settings']['leap_day'] == 'mar1', 'Gemeinsame Einstellungen für alle')
laura.call('profile', expected=400, current_password='falsch', email='changed@familie.xyz')
laura.call('profile', expected=400, current_password=password_laura, email='papa@familie.xyz')
check(laura.call()['user']['email'] == 'laura@familie.xyz', 'Eigene Mailänderung geschützt, doppelte Mail verhindert')
laura.call('profile', current_password=password_laura, new_password=password_laura + '-neu')
check(papa.call()['authenticated'] and not restarted.call()['authenticated'], 'Passwortwechsel widerruft nur Sitzungen des betreffenden Kontos')
password_laura += '-neu'
(fixture / 'data/fail-mail').touch()
failed_invite = papa.call('add_member', current_password=password_papa, name='Kind', email='kind@familie.xyz')
check('abgelehnt' in failed_invite['message'], 'Fehlgeschlagene Einladung wird gemeldet')
(fixture / 'data/fail-mail').unlink()
member = next(m for m in laura.call()['members'] if m['email'] == 'kind@familie.xyz')
papa.call('resend_invitation', id=member['id'])
check(member['must_change_password'], 'Alle Mitglieder können weitere gleichberechtigte Konten anlegen')
child = Client(); child.call(); child.call('login', identity='kind@familie.xyz', password='familienkalender')
laura.call('remove_member', current_password=password_laura, id=member['id'])
check(not child.call()['authenticated'], 'Entfernen beendet auch bestehende Sitzungen')
papa.call('remove_member', expected=400, current_password=password_papa, id='papa')
check(True, 'Eigenes und damit letztes Konto kann nicht entfernt werden')
# Exercise password recovery with simulated mail delivery.
recovery = Client(); recovery.call()
(fixture / 'data/fail-mail').touch()
recovery.call('recover', expected=503, identity='laura@familie.xyz')
check(json.loads(setup_path.read_text(encoding='utf-8'))['users'][laura_id]['reset'] is None, 'Versandfehler verwirft nicht zugestellten Reset-Code')
(fixture / 'data/fail-mail').unlink()
recovery.call('recover', identity='laura@familie.xyz')
reset_mail = json.loads((fixture / 'data/outbox.json').read_text(encoding='utf-8'))[-1]
reset_code = re.search(r'(?m)^([0-9]{8})$', reset_mail['body']).group(1)
check(reset_mail['to'] == 'laura@familie.xyz', 'Achtstelliger Reset-Code wird an das richtige Mitglied versandt')
reset = Client(); reset.call()
reset.call('reset', expected=400, identity='papa', code=reset_code, password='Anderes-Passwort-2026')
reset.call('reset', identity='laura@familie.xyz', code=reset_code, password='Lauras-neues-Passwort-2026')
check(reset.call()['user']['id'] == laura_id and papa.call()['authenticated'] and not laura.call()['authenticated'], 'Reset-Code gehört nur zu einem Konto und lässt andere Konten angemeldet')
reset.call('reset', expected=400, identity='laura@familie.xyz', code=reset_code, password='Noch-ein-Passwort-2026')
check(True, 'Reset-Code nur einmal verwendbar')
check(len(papa.call('export')) == 3, 'Authentifizierter JSON-Export')
old_cookie = next(c for c in papa.cookies if c.name == 'familienkalender_login')
papa.call('logout')
logged_out = Client(); logged_out.cookies.set_cookie(old_cookie)
check(not papa.call()['authenticated'] and not logged_out.call()['authenticated'], 'Abmelden widerruft auch den dauerhaften Browserzugang')

# Reproduce the real 401 regression using the former single-owner file format.
data = json.loads(setup_path.read_text(encoding='utf-8'))
owner = data['users']['papa']
legacy = dict(owner=owner['name'], email=owner['email'], password_hash=owner['password_hash'],
    auth_version=owner['auth_version'], remember_tokens=owner['remember_tokens'], reset=None,
    timezone=data['timezone'], mail_enabled=data['mail_enabled'], leap_day=data['leap_day'])
events_before = (fixture / 'data/termine.json').read_bytes()
setup_path.write_text(json.dumps(legacy), encoding='utf-8')
migrated = Client('remote.php'); migrated.call()
migrated.call('login', identity='papa', password=password_papa)
check(migrated.call()['user']['id'] == 'papa' and len(migrated.call()['members']) == 1, 'Migriertes Einzelkonto kann sich als Papa mit bisherigem Passwort ohne Einrichtungscode anmelden')
check((fixture / 'data/termine.json').read_bytes() == events_before, 'HTTP-Migration erhält Termine unverändert')
backup = next((fixture / 'data').glob('setup.legacy-*.json'))
check(json.loads(backup.read_text(encoding='utf-8')) == legacy, 'HTTP-Migration sichert Originaldaten')
page = urllib.request.urlopen(base)
check(page.status == 200 and 'assets/app.js' in page.read().decode(), 'Anmeldeseite liefert HTTP 200 und lädt die Oberfläche')
for path in ['config.php', 'config.example.php', 'data/setup.json', 'data/termine.json', 'lib/app.php', 'cron.php']:
    try:
        urllib.request.urlopen(base + path)
        raise AssertionError('Oeffentlich erreichbar: ' + path)
    except urllib.error.HTTPError as error: check(error.code == 403, 'HTTP-Sperre: ' + path)
try:
    urllib.request.urlopen(base + 'data/' + backup.name)
    raise AssertionError('Legacy backup is public')
except urllib.error.HTTPError as error: check(error.code == 403, 'HTTP-Sperre für Migrationssicherung')
print(f'\n{checks} HTTP-Pruefungen erfolgreich. Keine echten Mails verschickt.')
if args.keep_fixture:
    print('Browser-Testseite:', base)
else:
    if fixture.is_symlink() or fixture.resolve().parent != (root / 'output').resolve(): raise RuntimeError('Unerwarteter Testpfad')
    shutil.rmtree(fixture)
