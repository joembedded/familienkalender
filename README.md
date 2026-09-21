Der ausführende Benutzer muss `data/` lesen und schreiben können.

Alternativ kann ein HTTPS-Cron-Dienst den Erinnerungslauf aufrufen. Dazu den privaten Einrichtungscode als URL-Parameter `setup_key` übergeben:

```text
https://kalender.example.org/cron.php?setup_key=DEIN_PRIVATER_EINRICHTUNGSCODE
```

Der Parameter autorisiert den Aufruf und darf weder veröffentlicht noch in Browser-Lesezeichen gespeichert werden. Die URL kann in Protokolldateien des Cron-Dienstes oder Webservers erscheinen; ein echter PHP-CLI-Cron ist deshalb vorzuziehen. Ohne gültigen Code antwortet der Endpunkt mit HTTP 403. Die HTTPS-Adresse funktioniert nur, wenn `cron.php` im Webserver nicht zusätzlich gesperrt wird; die mitgelieferte Apache-Konfiguration lässt genau diesen token-geschützten Zugriff zu.
# Familienkalender

Ein kleiner gemeinsamer Kalender für Geburtstage, Hochzeitstage und andere wichtige Anlässe. Alle Mitglieder haben dieselben Rechte, melden sich aber mit eigenen Konten an. Ein täglicher CRON-Aufruf verschickt die Erinnerungen an die ganze Gruppe.

![Vorschau des Familienkalenders mit Beispielterminen](img/screenshot.png)

Die Anwendung benötigt **PHP 8.1 oder neuer auf einem 64-Bit-System**, Apache, JavaScript im Browser und einen für PHP `mail()` eingerichteten Maildienst. Keine Datenbank, kein Composer, kein npm-Build. Alle Dateien für den Betrieb liegen in **`src/`**.

## Was der Kalender kann

- Gemeinsame Termine anlegen, bearbeiten, löschen und als JSON sichern.
- Namen, Anlässe und Notizen durchsuchen; nach Monat und Anlass filtern; nach nächstem Termin, Jahreslauf oder Name sortieren.
- Jährliche und einmalige Anlässe, optionales Geburts-/Ursprungsjahr, Notizen und zusätzliche Vorab-Erinnerungen.
- Erinnerung pro Termin pausieren oder den Versand für alle gemeinsam aussetzen.
- Den 29. Februar wahlweise am 28. Februar, am 1. März oder nur in Schaltjahren berücksichtigen.
- Mitglieder hinzufügen und entfernen sowie Einladungen an noch nicht eingerichtete Konten erneut senden. Alle Mitglieder können Termine, gemeinsame Einstellungen und die Mitgliederliste verwalten. Das eigene Profil und Passwort ändert jede Person selbst.
- Dauerhafte Anmeldung, Passwortänderung und persönlicher Reset-Code per Mail.
- Ruhige, helle Farben, ein Kalender-mit-Herz-Logo und ein optionales eigenes Hintergrundfoto. Auch auf dem Smartphone nutzbar.

## Öffentliche Vorlage und private Installation

Im Repository stehen nur neutrale Vorlagen und drei Beispieltermine:

| Name / Anlass | Datum | Jahr |
| --- | --- | --- |
| Papa · Geburtstag | 3. Februar | 1964 |
| Hochzeitstag | 3. August | 1994 |
| Mama · Geburtstag | 16. September | 1964 |

Die Beispielkonten heißen `mama` und `papa`; ihre Platzhalter-Adressen sind `mama@familie.xyz` und `papa@familie.xyz`. Das gemeinsame **Startpasswort lautet `familienkalender`**. Es dient nur zur ersten Anmeldung und muss von jedem Mitglied durch ein eigenes Passwort ersetzt werden. Solange das nicht geschehen ist, sind Termine, Mitgliederliste, Export und gemeinsame Einstellungen für dieses Konto gesperrt.

Diese Dateien gehören **nicht ins öffentliche Git-Repository** und werden durch `.gitignore` ausgeschlossen:

| Privat | Inhalt |
| --- | --- |
| `src/config.php` | Tatsächlicher Absender, Kalenderadresse, Einrichtungscode und anfängliche Mitglieder |
| `src/data/setup.json` | Konten, Mailadressen, Passwort-Hashes, Login-Tokens und gemeinsame Einstellungen |
| `src/data/termine.json` | Eure persönlichen Termine und Notizen |
| `src/data/versand.json` | Versandprotokoll pro Empfänger |
| Übrige Laufzeitdateien in `src/data/` | Sitzungen, Versuchslimits und Sperren |
| `src/assets/hintergrund.jpg`, `src/assets/private-*` | Persönliches Bildmaterial |
| `PRIVATE.md`, `ausgangsbasis/`, `output/` | Private Installationsnotizen, Originalmaterial und Testausgaben |

Die öffentlichen Gegenstücke sind `src/config.example.php` und `src/data/termine.example.json`. Ein eigener JPG-Dateiname sollte mit `private-` beginnen oder ausdrücklich zu `.gitignore` hinzugefügt werden. `.gitignore` schützt keine Dateien, die früher bereits committed wurden. Deshalb vor einer Veröffentlichung `git status --short --untracked-files=all` und die für den Commit ausgewählten Änderungen ansehen.

## Erste Einrichtung

1. `src/config.example.php` nach **`src/config.php`** kopieren.
2. Dort `group_name`, `sender_email`, `base_url` und die Mailadressen unter `initial_members` anpassen. `background_image` darf leer bleiben oder beispielsweise `assets/hintergrund.jpg` enthalten.
3. Einmal einen zufälligen Einrichtungscode erzeugen und als `setup_key` eintragen:

   ```sh
   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
   ```

4. Für echte Erinnerungen `mail_enabled` auf `true` setzen. Die öffentliche Vorlage startet mit ausgeschaltetem Erinnerungsversand.
5. PHP benötigt Schreibrechte auf `src/data`. Webseite öffnen. Beim ersten Aufruf werden die drei Beispieltermine und die konfigurierten Konten automatisch in privaten JSON-Dateien angelegt.
6. Mit Kennung (`mama` oder `papa`) bzw. Mailadresse und dem Startpasswort anmelden. **Auf einem entfernten Server ist bei der ersten Anmeldung zusätzlich der private Einrichtungscode nötig.** Direkt über `localhost` am Server entfällt dieser Zusatz. Danach das eigene Passwort festlegen.

Der Einrichtungscode ist nicht öffentlich. Beim Hinzufügen eines Mitglieds geht er zusammen mit dem Startpasswort in einer Einladungsmail an dessen hinterlegte Adresse; solange das Konto noch nicht eingerichtet ist, kann die Einladung im Mitgliederfenster erneut gesendet werden. So kann niemand ein noch nicht eingerichtetes Konto allein anhand des bekannten Startpassworts übernehmen. Nach Wahl eines eigenen Passworts wird der Code für dieses Konto nicht mehr benötigt. Der achtstellige Code im Bereich „Passwort-Code eingeben“ ist dagegen ausschließlich ein zeitlich begrenzter Passwort-Reset-Code: Er wird erst nach „Passwort vergessen?“ per separater Reset-Mail erzeugt und steht nicht in der Einladungsmail.

Die Werte unter `initial_members`, `timezone` und `mail_enabled` in der Konfiguration werden nur bei der ersten Erstellung von `data/setup.json` übernommen. Spätere Kontoänderungen erfolgen in der Oberfläche. Absender, Kalenderadresse, Gruppentitel, Einrichtungscode und Bildpfad werden weiterhin aus `config.php` gelesen. Daten werden bei normalen Neustarts und Updates nicht zurückgesetzt.

## Lokal mit XAMPP

Den Projektordner unter den Apache-Webroot legen und dessen `src/`-Adresse öffnen, zum Beispiel `http://localhost/familienkalender/src/`. Apache muss die mitgelieferten `.htaccess`-Dateien auswerten dürfen. Eine lokale Mailzustellung ist zum Bearbeiten und Testen nicht nötig.

Versandvorschau ohne echte Mail und ohne Einträge im Versandprotokoll:

```sh
php src/cron.php --dry-run
php src/cron.php --dry-run --date=2027-02-03
```

Die Vorschau zeigt die personalisierte Mail für jeden fälligen Empfänger. Sie kann persönliche Daten enthalten und gehört nicht ins öffentliche Repo. Auch eine Vorschau legt bei einer ganz frischen Installation zuerst die privaten Ausgangsdaten an.

## Auf einen Webserver kopieren

**Den Inhalt von `src/` einschließlich `.htaccess`, `data/.htaccess` und `lib/.htaccess` kopieren.** Die private `config.php` und das gewünschte Foto müssen zusätzlich bzw. ausdrücklich mit übertragen werden; sie sind nicht in einem Git-Checkout oder Git-Archiv enthalten.

Wer bereits lokal Passwörter gesetzt oder Termine ergänzt hat, überträgt bei der ersten Inbetriebnahme zusätzlich `data/setup.json` und `data/termine.json`. Sitzungsdateien müssen nicht mit umziehen; unter der neuen Domain melden sich Mitglieder neu an. Soll der Server dagegen frisch mit den Beispielen starten, die Laufzeit-JSON-Dateien beim ersten Upload weglassen. Bei **späteren Updates** vorhandene `config.php` und `data/` erhalten und nicht durch lokale Testdaten überschreiben. Der `tools/`-Ordner des ursprünglichen Einzelkalenders wird nicht benötigt.

Öffentlich HTTPS verwenden. Unter Apache muss `AllowOverride All` oder eine entsprechende serverseitige Regelkonfiguration aktiv sein. Direkte Aufrufe von `config.php`, `data/setup.json`, `data/termine.json`, `lib/` und `cron.php` müssen mit 403 oder 404 scheitern. Bei Nginx müssen diese Sperren im Serverblock eingerichtet werden; `.htaccess` wirkt dort nicht.

## CRON und Mailversand

Auf einem Linux-Server beispielsweise täglich um 07:00 Uhr (Pfad anpassen):

```cron
0 7 * * * /usr/bin/php /var/www/familienkalender/cron.php >> /var/log/familienkalender.log 2>&1
```

Hier wird angenommen, dass der **Inhalt** von `src` nach `/var/www/familienkalender` kopiert wurde. CRON verwendet die Zeitzone des Schedulers; die Terminberechnung verwendet die im Kalender eingestellte Zeitzone. Für 07:00 Uhr deutscher Ortszeit beide auf `Europe/Berlin` abstimmen. Der ausführende Benutzer muss `data/` lesen und schreiben können.

### Cron-Light per HTTPS

Ein Cron-Light-Job kann den Erinnerungslauf täglich per HTTPS auslösen. Als Ziel-URL wird die öffentliche Adresse von `cron.php` verwendet; den privaten Einrichtungscode aus `config.php` als Query-Parameter `setup_key` ergänzen:

```text
https://kalender.example.org/cron.php?setup_key=DEIN_PRIVATER_EINRICHTUNGSCODE
```

In Cron-Light daher täglich die vollständige URL als HTTP-GET-Aufruf eintragen. Ein erfolgreicher Lauf liefert JSON wie `{"status":"sent","sent":2,"failed":0}`; bei ausstehenden Erinnerungen und einem fehlgeschlagenen Empfänger antwortet `cron.php` mit HTTP 500. Ohne oder mit falschem `setup_key` antwortet der Endpunkt mit HTTP 403.

Der Einrichtungscode autorisiert diesen Aufruf und darf nicht veröffentlicht, per E-Mail weitergegeben oder in Browser-Lesezeichen gespeichert werden. Die vollständige URL kann in Protokollen von Cron-Light oder des Webservers erscheinen. Deshalb ist ein echter PHP-CLI-Cron weiterhin die vorzuziehende Variante. Die mitgelieferte Apache-Konfiguration lässt den geschützten HTTPS-Aufruf von `cron.php` zu; bei Nginx muss der Zugriff auf `cron.php` entsprechend erlaubt bleiben.

Jedes Mitglied erhält eine **eigene Sammelmail** mit allen für diesen Tag fälligen Erinnerungen. Notizen stehen ebenfalls in der Mail. Eine erfolgreiche Übergabe wird sofort pro Mitglied und Anlass gespeichert. Bei einem Fehler werden die anderen Empfänger trotzdem versucht; der Prozess endet dann mit Exit-Code 1. Ein weiterer Aufruf wiederholt nur die noch nicht erfolgreich übergebenen Erinnerungen. Parallele Läufe werden durch eine Sperre verhindert. Ohne Termine gibt es keine Mail. Ausgefallene Tage werden nicht nachträglich zugestellt. Ein Prozessabbruch genau zwischen Mailannahme und Protokollierung kann dennoch eine Wiederholung verursachen.

Auch noch nicht persönlich eingerichtete Mitglieder erhalten Erinnerungen an ihre hinterlegte Adresse. Entfernte Mitglieder bekommen bei späteren Läufen keine Mails mehr. Eine bereits laufende Versandrunde verwendet die Mitgliederliste vom Beginn dieses Laufs.

`sender_email` aus der privaten Konfiguration wird sowohl im From-Header als auch als technischer Envelope-Absender verwendet. Der Maildienst des Hosters muss diesen Absender erlauben. Die Anwendung verwendet PHP `mail()` und die bestehende SMTP-/Sendmail-Konfiguration des Servers; eigene SMTP-Zugangsdaten werden nicht benötigt. Eine positive Rückmeldung bestätigt die Annahme durch den Maildienst, nicht den Eingang im Postfach. Unter **Einstellungen → Testmail an mich senden** kann jedes Mitglied seine eigene Adresse testen. Passwort-Codes und Testmails funktionieren unabhängig von der globalen Pause für tägliche Erinnerungen.

## Vorschau beim Teilen und App-Symbol

Die Seite liefert bereits im HTML-Header Beschreibung, Canonical-Adresse, Open-Graph-Tags (unter anderem für WhatsApp), eine große Twitter-/X-Karte und strukturierte Daten für Website, Seite und Vorschaubild. Die Struktur orientiert sich an der GEOprecision-Vorlage. Die Inhalte bleiben allgemein; Namen der Familie, Mailadressen und Termine werden nicht in diese Metadaten übernommen. `noindex,nofollow` bleibt für den privaten Kalender gesetzt; das ersetzt keinen Zugangsschutz.

![Vorschaubild für WhatsApp und andere soziale Dienste](src/assets/social-preview.jpg)

**WhatsApp benötigt für das Vorschaubild eine feste, absolute, öffentlich erreichbare HTTPS-URL.** Ein relativer Pfad wie `assets/social-preview.jpg`, eine Datei auf dem eigenen PC oder eine Bildadresse hinter einer Anmeldung reicht dafür nicht aus. Die Anwendung setzt die Adresse aus **`base_url` in der privaten `src/config.php`** und `assets/social-preview.jpg` zusammen. Beispiel:

```php
'base_url' => 'https://kalender.example.org/meine-familie/',
```

Damit wird `https://kalender.example.org/meine-familie/assets/social-preview.jpg` in `og:image`, `og:image:secure_url` und `twitter:image` ausgegeben. Die Domain wird bewusst nicht aus dem aktuellen HTTP-Host abgeleitet: Auch lokale Tests geben so die fest konfigurierte Zieladresse aus. Ist `base_url` leer, entfallen die absoluten Social- und Canonical-Angaben. Die konkrete Installationsadresse bleibt in `config.php`; vor einer Veröffentlichung ist deshalb keine temporär fest eingetragene private URL aus dem Quellcode zu entfernen.

Beim Hochladen **`assets/social-preview.jpg`, alle Icon-PNGs und `manifest.webmanifest`** mit übertragen. Das Bild ist ein komprimiertes JPEG mit 1200 × 630 Pixeln. Die Kalender-Startseite und das neutrale Vorschaubild müssen ohne Login abrufbar sein; persönliche Daten bleiben hinter der Kalenderanmeldung. WhatsApp-Vorschauen lassen sich erst mit einer von außen erreichbaren Installation prüfen und können je nach Client, Einstellungen und Cache ausbleiben oder zunächst das alte Bild zeigen. Nach einem Bildwechsel gegebenenfalls den Dateinamen und `$socialImagePath` in `src/lib/page-head.php` ändern.

Das Manifest enthält App-Name, Farben, Startadresse und PNG-Icons mit 192 und 512 Pixeln, zusätzlich ein Maskable-Icon. Ein Apple-Touch-Icon und ein kleines Browser-Icon sind ebenfalls enthalten. Die Manifest-Pfade sind relativ, damit das Kopieren in ein anderes Unterverzeichnis genügt. Es gibt keinen Service Worker und keinen Offline-Modus; für den Kalender ist weiterhin eine Verbindung zum Server erforderlich. Ob eine Installation bzw. „Zum Home-Bildschirm“ angeboten wird, entscheidet der Browser.

Technische Referenzen: [Open Graph](https://ogp.me/), [Web-App-Manifest](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Manifest/Reference).

## Konten und dauerhafte Anmeldung

- Passwörter werden ausschließlich als Hash gespeichert. Passwort- und Mailänderungen benötigen das aktuelle Passwort.
- Änderungen und Resets widerrufen nur die Sitzungen und dauerhaften Zugänge des betreffenden Kontos.
- Login-Tokens sind serverseitig für 100 Jahre gültig und werden bei Nutzung verlängert. Der Browser-Cookie wird bei jedem Besuch erneut für 400 Tage gesetzt. Browser können Cookies früher löschen oder die Laufzeit begrenzen; jahrzehntelange Anmeldung ohne weitere Besuche ist deshalb nicht garantiert.
- Wiederherstellungscodes gelten 15 Minuten, nur einmal und nur für das zugehörige Konto. Falsche Versuche werden begrenzt.
- Alle Konten haben dieselben Gruppenrechte. Mitgliederverwaltung benötigt zur Bestätigung das eigene Passwort. Das eigene Konto kann nicht über „Entfernen“ gelöscht werden; so bleibt immer mindestens ein Konto bestehen.
- Neue Mitglieder erhalten beim Hinzufügen eine Einladungsmail mit ihrer Mailadresse, dem Startpasswort und dem Einrichtungscode. Eine erfolgreiche Rückmeldung bestätigt nur die Annahme durch PHP `mail()`, nicht den Eingang im Postfach. Solange noch kein persönliches Passwort gesetzt wurde, kann jedes Mitglied die Einladung im Mitgliederfenster erneut senden; der Versand ist auf fünf Versuche pro Stunde und Konto begrenzt.

Falls Mailwiederherstellung nicht möglich ist, kann die Person mit Serverzugang den Hash **des einzelnen betroffenen Kontos** in `data/setup.json` ersetzen: einen neuen Hash per `password_hash()` erzeugen, `auth_version` neu zufällig setzen sowie `remember_tokens` und `reset` dieses Kontos leeren. Vorher die Datei sichern. Andere Nutzerkonten und Termine müssen dafür nicht gelöscht werden.

## Dateien und Tests

```text
src/index.php                 Oberfläche
src/api.php                   Konten, Termine und Einstellungen
src/cron.php                  Täglicher Versand / Vorschau
src/config.example.php        Öffentliche Konfigurationsvorlage
src/config.php                Private Installation, nicht in Git
src/data/termine.example.json  Öffentliche Beispieltermine
src/data/*.json                Private Laufzeitdaten, nicht in Git
src/lib/                      Speicherung, Authentifizierung, Termine, Mail
src/assets/                   CSS, JavaScript, Kalenderlogo, optionales Foto
tests/                        Isolierte Funktions- und HTTP-Tests
```

```sh
php tests/run.php
python tests/integration.py --base-url http://localhost/familienkalender/
```

Die PHP-Tests arbeiten mit temporären Daten und simuliertem Mailversand. Die HTTP-Tests benötigen Apache unter der angegebenen Projektadresse. Sie legen eine nur lokal zugängliche Testinstallation unter `output/` an und entfernen sie nach erfolgreichem Abschluss. Mit `--keep-fixture` bleibt diese für Browserprüfungen erhalten. Weder echte Zugangsdaten noch echte Mails sind für diese Tests erforderlich.

JSON-Änderungen werden unter Dateisperre über eine temporäre Datei geschrieben. Ein Versionsvergleich verhindert, dass zwei Personen gleichzeitig denselben Listenstand still überschreiben. Regelmäßige Backups von `config.php` und `data/` sind sinnvoll; der JSON-Download in der Oberfläche sichert ausschließlich die Termine.

## Lizenz

Dieses Projekt steht unter der [MIT-Lizenz](LICENSE).
