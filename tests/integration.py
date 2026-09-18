"""Apache integration checks in a disposable, local-only installation."""
import argparse
import http.cookiejar
import json
from pathlib import Path
import shutil
import subprocess
import time
import urllib.error
import urllib.request
import uuid

parser = argparse.ArgumentParser()
parser.add_argument('--base-url', default='http://localhost/familienkalender/')
parser.add_argument('--keep-fixture', action='store_true')
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
fixture = root / 'output' / ('http-' + uuid.uuid4().hex)
base = args.base_url.rstrip('/') + '/output/' + fixture.name + '/'
shutil.copytree(root / 'src', fixture, ignore=shutil.ignore_patterns('config.php', 'setup.json', 'termine.json', 'versand.json', 'limits.json', 'sessions', '*.lock', 'hintergrund.jpg', 'private-*'))
(fixture.parent / '.htaccess').write_text('Require all denied\n', encoding='utf-8')
with (fixture / '.htaccess').open('a', encoding='utf-8') as handle: handle.write('\nRequire local\n')
config = (fixture / 'config.example.php').read_text(encoding='utf-8').replace('BITTE-ERSETZEN-DURCH-EINEN-ZUFAELLIGEN-CODE', 'local-test-key-' * 4)
(fixture / 'config.php').write_text(config, encoding='utf-8')
(fixture / 'remote.php').write_text("<?php $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; require __DIR__ . '/api.php';", encoding='utf-8')
setup_path = fixture / 'data/setup.json'
password_mama = 'Mama-persoenlich-2026'
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

mama, papa = Client(), Client()
anonymous = mama.call()
check(not anonymous['authenticated'] and 'members' not in anonymous and 'events' not in anonymous, 'Keine privaten Daten ohne Anmeldung')
mama.call('save', expected=403, csrf=False)
check(True, 'CSRF-Schutz')
remote = Client('remote.php'); remote.call()
remote.call('login', expected=403, identity='papa', password='familienkalender')
remote.call('login', identity='papa', password='familienkalender', setup_key='local-test-key-' * 4)
check(remote.call()['user']['must_change_password'], 'Remote-Erstanmeldung benötigt privaten Einrichtungscode')
mama.call('login', identity='mama', password='familienkalender')
pending = mama.call()
check(pending['user']['must_change_password'] and 'events' not in pending and 'members' not in pending, 'Erstanmeldung bleibt bis Passwortwechsel eingeschränkt')
mama.call('export', expected=403)
mama.call('save', expected=403, event={})
mama.call('settings', expected=403)
mama.call('add_member', expected=403)
check(True, 'Eingeschränkte Sitzung kann weder Termine noch Mitglieder ändern')
mama.call('profile', expected=400, current_password='familienkalender', new_password='familienkalender')
mama.call('profile', current_password='familienkalender', new_password=password_mama)
current = mama.call()
check(len(current['events']) == 3 and len(current['members']) == 2 and not current['user']['must_change_password'], 'Persönliches Passwort schaltet Kalender frei')
check(all('password_hash' not in u and 'remember_tokens' not in u and 'auth_version' not in u for u in current['members']), 'Keine fremden Zugangsdaten in der Mitgliederliste')
papa.call()
papa.call('login', expected=401, identity='papa', password=password_mama)
papa.call('login', identity='papa', password='familienkalender')
papa.call('profile', current_password='familienkalender', new_password=password_papa)
check(papa.call()['user']['id'] == 'papa', 'Eigenes Passwort und eigenes Konto für Papa')
cookie = next(c for c in mama.cookies if c.name == 'familienkalender_login')
check(cookie.expires > time.time() + 399 * 86400 and 'HttpOnly' in cookie._rest and cookie._rest.get('SameSite') == 'Lax', 'Dauerhafter HttpOnly-Cookie mit SameSite')
restarted = Client(); restarted.cookies.set_cookie(cookie)
check(restarted.call()['user']['id'] == 'mama', 'Dauerhafter Cookie stellt das richtige Konto wieder her')
stale = mama.call()
event = dict(name='Familientreffen <Test>', day=31, month=12, year='', occasion='Treffen', notes='Gruß & Grüße', repeat='yearly', enabled=True, remind_before=7)
mama.call('save', revision=stale['revision'], event=event)
shared = papa.call(); created = next(e for e in shared['events'] if e['name'] == event['name'])
check(created['notes'] == event['notes'], 'Papa sieht Mamas Termin und Notizen')
papa.call('save', expected=409, revision=stale['revision'], id=created['id'], event=event)
event['notes'] = 'Von Papa bearbeitet'
papa.call('save', revision=shared['revision'], id=created['id'], event=event)
check(next(e for e in mama.call()['events'] if e['id'] == created['id'])['notes'] == event['notes'], 'Gleiche Bearbeitungsrechte und Konfliktschutz')
papa.call('delete', revision=papa.call()['revision'], id=created['id'])
check(len(mama.call()['events']) == 3, 'Gemeinsames Löschen')
settings = papa.call()['settings']; settings['leap_day'] = 'mar1'
papa.call('settings', **settings)
check(mama.call()['settings']['leap_day'] == 'mar1', 'Gemeinsame Einstellungen für alle')
mama.call('profile', expected=400, current_password='falsch', email='changed@familie.xyz')
mama.call('profile', expected=400, current_password=password_mama, email='papa@familie.xyz')
check(mama.call()['user']['email'] == 'mama@familie.xyz', 'Eigene Mailänderung geschützt, doppelte Mail verhindert')
mama.call('profile', current_password=password_mama, new_password=password_mama + '-neu')
check(papa.call()['authenticated'] and not restarted.call()['authenticated'], 'Passwortwechsel widerruft nur Sitzungen des betreffenden Kontos')
password_mama += '-neu'
papa.call('add_member', current_password=password_papa, name='Kind', email='kind@familie.xyz')
member = next(m for m in mama.call()['members'] if m['email'] == 'kind@familie.xyz')
check(member['must_change_password'], 'Alle Mitglieder können weitere gleichberechtigte Konten anlegen')
child = Client(); child.call(); child.call('login', identity='kind@familie.xyz', password='familienkalender')
mama.call('remove_member', current_password=password_mama, id=member['id'])
check(not child.call()['authenticated'], 'Entfernen beendet auch bestehende Sitzungen')
papa.call('remove_member', expected=400, current_password=password_papa, id='papa')
check(True, 'Eigenes und damit letztes Konto kann nicht entfernt werden')
# Inject a known reset code only into the disposable fixture; no mail is sent.
data = json.loads(setup_path.read_text(encoding='utf-8'))
code_hash = subprocess.check_output(['php', '-r', 'echo password_hash("12345678", PASSWORD_DEFAULT);'], text=True)
data['users']['mama']['reset'] = dict(hash=code_hash, expires=int(time.time()) + 900, attempts=0)
setup_path.write_text(json.dumps(data, ensure_ascii=False), encoding='utf-8')
reset = Client(); reset.call()
reset.call('reset', expected=400, identity='papa', code='12345678', password='Anderes-Passwort-2026')
reset.call('reset', identity='mama', code='12345678', password='Mamas-neues-Passwort-2026')
check(reset.call()['user']['id'] == 'mama' and papa.call()['authenticated'] and not mama.call()['authenticated'], 'Reset-Code gehört nur zu einem Konto und lässt andere Konten angemeldet')
reset.call('reset', expected=400, identity='mama', code='12345678', password='Noch-ein-Passwort-2026')
check(True, 'Reset-Code nur einmal verwendbar')
check(len(papa.call('export')) == 3, 'Authentifizierter JSON-Export')
for path in ['config.php', 'config.example.php', 'data/setup.json', 'data/termine.json', 'lib/app.php', 'cron.php']:
    try:
        urllib.request.urlopen(base + path)
        raise AssertionError('Oeffentlich erreichbar: ' + path)
    except urllib.error.HTTPError as error: check(error.code == 403, 'HTTP-Sperre: ' + path)
print(f'\n{checks} HTTP-Pruefungen erfolgreich. Keine echten Mails verschickt.')
if args.keep_fixture:
    print('Browser-Testseite:', base)
else:
    if fixture.is_symlink() or fixture.resolve().parent != (root / 'output').resolve(): raise RuntimeError('Unerwarteter Testpfad')
    shutil.rmtree(fixture)
