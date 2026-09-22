# Familienkalender

Gemeinsamer Kalender für Geburtstage, Hochzeitstage und andere Anlässe – mit eigenen Konten, gleichen Rechten für alle und täglichen Erinnerungen per Mail. Termine lassen sich suchen, bearbeiten und als JSON exportieren.

![Vorschau des Familienkalenders](img/screenshot.png)

## Kalender erstmals einrichten

Nur **papa** wird beim ersten Start angelegt. Nach der Installation mit der Kennung `papa` (oder seiner konfigurierten Mailadresse) und dem Startpasswort `familienkalender` anmelden. Auf dem Server zusätzlich den langen privaten `setup_key` aus `src/config.php` unter **Erste Anmeldung auf dem Server? → Privater Einrichtungscode** einfügen. Direkt auf localhost entfällt dieses Zusatzfeld. Danach muss Papa ein eigenes Passwort mit mindestens 10 Zeichen setzen.

Weitere Mitglieder werden anschließend über **Mitglieder** per E-Mail eingeladen. Es gibt keine freie Registrierung.

## Neue Mitglieder einladen

Ein bestehendes Mitglied öffnet **Mitglieder**, trägt Name und Mailadresse ein und bestätigt mit seinem eigenen Passwort. Das neue Mitglied erhält eine Einladungsmail; bei Bedarf lässt sie sich im Mitgliederfenster erneut senden.

So richtet das eingeladene Mitglied seinen Zugang ein:

1. **Seite öffnen:** Den Link aus der Einladungsmail aufrufen.
2. **Zugang eingeben:** Die eigene Mailadresse und das Startpasswort aus der Mail eintragen, normalerweise `familienkalender`.
3. **Einrichtungscode einsetzen:** **Erste Anmeldung auf dem Server?** aufklappen und den vollständigen privaten Einrichtungscode aus der Mail in **Privater Einrichtungscode** kopieren. Gemeint ist die lange Zeichenfolge (z. B. 32 Hex-Zeichen; die Länge kann abweichen), für die `DEIN_PRIVATER_EINRICHTUNGSCODE` als Platzhalter steht. Dann **Kalender öffnen** wählen.
4. **Eigenes Passwort wählen:** Unter **Bisheriges Startpasswort** nochmals das Passwort aus Schritt 2 eingeben. Ein neues Passwort mit mindestens 10 Zeichen wählen, wiederholen und mit **Passwort festlegen & starten** speichern.
5. **Fertig:** Du bist jetzt Vollmitglied mit allen Rechten und kannst Termine, Mitglieder und gemeinsame Einstellungen verwalten. Künftig reichen Mailadresse und eigenes Passwort.

Der lange Einrichtungscode aus `config.php` wird für die erste Serveranmeldung mit Startpasswort benötigt. Der **achtstellige Reset-Code** existiert zusätzlich: **Passwort vergessen?** verschickt ihn an die hinterlegte Mailadresse. Er gilt 15 Minuten, nur einmal und wird unter **Reset-Code eingeben** verwendet. Bei späteren Anmeldungen reichen Mailadresse/Kennung und eigenes Passwort. **Dauerhaft angemeldet bleiben** stellt den Zugang auch nach einem Browserneustart wieder her; Abmelden widerruft diesen Browserzugang, ein Passwortwechsel die bisherigen Zugänge des Kontos.

Auf dem lokalen XAMPP ist kein Mailversand eingerichtet. Auf `https://flexgate.org/terminkalender/familie_wickenh/` steht der Mailversand zur Verfügung. Lokal angelegte Einladungen können dort nach Übernahme der Daten unter **Mitglieder → Einladung senden** erneut versendet werden. Passwort-Reset per E-Mail funktioniert erst auf dem Server.

## Installation

Voraussetzungen: **PHP 8.1+ auf einem 64-Bit-System**, Apache, JavaScript und ein für PHP `mail()` eingerichteter Maildienst. Keine Datenbank und kein Build nötig. Alle Betriebsdateien liegen in **`src/`**.

1. `src/config.example.php` nach `src/config.php` kopieren. Gruppennamen, Absender, `base_url` und ausschließlich Papas Konto unter `initial_members` anpassen; ein Hintergrundbild ist optional.
2. Einen privaten Einrichtungscode erzeugen und als `setup_key` eintragen:

   ```sh
   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
   ```

3. Für tägliche Erinnerungen `mail_enabled` auf `true` setzen und PHP Schreibrechte auf `src/data/` geben.
4. Den Inhalt von `src/` einschließlich aller `.htaccess`-Dateien und der privaten `config.php` auf den Webserver kopieren. HTTPS verwenden. Apache muss die `.htaccess`-Regeln auswerten; bei Nginx entsprechende Zugriffssperren einrichten. `config.php`, `data/` und `lib/` dürfen nicht öffentlich abrufbar sein.
5. Webseite öffnen: Papas Konto und Beispieltermine werden automatisch angelegt. Mit `papa`, Startpasswort und Einrichtungscode anmelden und ein eigenes Passwort setzen. Direkt über `localhost` entfällt der Einrichtungscode.

Lokal mit XAMPP beispielsweise `http://localhost/wrk/familienkalender/src/` öffnen. `initial_members`, `timezone` und `mail_enabled` werden nur beim ersten Anlegen von `data/setup.json` übernommen; spätere Änderungen erfolgen in der Oberfläche.

## Bestehende Daten und Anmeldefehler

Beim alten Einzelkonto-Format mit `owner`/`email` statt `users` migriert die Anwendung den bisherigen Eigentümer anhand seiner Mailadresse zum konfigurierten Konto `papa`. Sie sichert vorher die alte Konfiguration als `data/setup.legacy-*.json`. Passwort, Name, Mailadresse, Kalendereinstellungen und Termine bleiben erhalten. Alte Sitzungen, Dauer-Cookies und Reset-Codes werden verworfen; einmal mit `papa` oder der bisherigen Mailadresse und dem **bisherigen persönlichen Passwort** neu anmelden. Das Startpasswort gilt nur bei einer neuen Installation oder wenn es bisher noch nicht geändert wurde. Passt die alte Mailadresse nicht zur Konfiguration, stoppt die Migration mit einem Hinweis.

Die öffentliche Anmeldeseite und `api.php?action=state` antworten auch ohne Anmeldung mit HTTP 200. HTTP 401 bei `login` bedeutet falsche Kennung oder falsches Passwort; bei geschützten Aktionen fehlt die Anmeldung. Ein fehlender Einrichtungscode bei der ersten Serveranmeldung führt zu HTTP 403. `setup.json` nicht löschen, um Anmeldeprobleme zu beheben: Dort liegen die bestehenden Konten und Passwörter.

## Erinnerungen per Cron

Täglich um 07:00 Uhr ausführen (Serverpfad anpassen; Zeitzone von Scheduler und Kalender auf `Europe/Berlin` abstimmen):

```cron
0 7 * * * /usr/bin/php /var/www/familienkalender/cron.php >> /var/log/familienkalender.log 2>&1
```

Der ausführende Benutzer benötigt Lese- und Schreibrechte auf `data/`. Alternativ kann ein HTTPS-Cron-Dienst wie Cron-Light täglich diese URL aufrufen:

```text
https://kalender.example.org/cron.php?setup_key=DEIN_PRIVATER_EINRICHTUNGSCODE
```

Den Platzhalter durch den privaten `setup_key` ersetzen. Ohne gültigen Code antwortet der Endpunkt mit HTTP 403. Die URL geheim halten; wegen möglicher URL-Protokolle ist PHP-CLI-Cron vorzuziehen.

Pro Aufruf schreibt `cron.php` einen Eintrag in `data/cron.log`, welches wenn > 50 KiB zu `data/cron_old.log` rotiert wird.

Jedes Mitglied erhält eine eigene Sammelmail mit den fälligen Erinnerungen. Erfolgreiche Sendungen werden protokolliert; erneute Aufrufe wiederholen nur offene Sendungen. Ausgefallene Tage werden nicht nachgeholt. Eine Testmail lässt sich unter **Einstellungen → Testmail an mich senden** auslösen.

Versandvorschau ohne echte Mails:

```sh
php src/cron.php --dry-run
php src/cron.php --dry-run --date=2027-02-03
```

## Daten, Updates und Tests

`src/config.php`, Laufzeitdaten unter `src/data/` und private Bilder gehören nicht ins öffentliche Repository. `.gitignore` enthält dafür Regeln. Regelmäßig `config.php` und `data/` sichern und bei Updates erhalten; der JSON-Export sichert nur Termine.

```sh
php tests/run.php
python tests/integration.py --base-url http://localhost/wrk/familienkalender/
```

Die Tests verwenden isolierte Daten und simulierten Mailversand. Die HTTP-Tests benötigen Apache unter der angegebenen Projektadresse.

## Lizenz

[MIT](LICENSE)
