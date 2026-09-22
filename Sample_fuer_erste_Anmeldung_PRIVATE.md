# Private Installation – (nicht veröffentlichen) - Hier nur ein SAMPLE

Lokal: http://localhost/wrk/familienkalender/src/

Ziel: https://flexgate.org/terminkalender/familie_XXXXX/

Absender: erinnerung@xxx.yyy

Papa: papa@yyy.yyy · Kennung papa

Startpasswort für Papa bei einer neuen Installation: `familienkalender`
Privater Einrichtungscode für die erste Anmeldung auf dem Server: `DEIN_PRIVATER_EINRICHTUNGSCODE` (in config.php durch einen zufälligen Code mit mindestens 24 Zeichen ersetzen)

Nur Papa wird initial angelegt. Weitere Mitglieder werden anschließend über die Oberfläche per E-Mail eingeladen. Lokal auf XAMPP ist kein Mailversand eingerichtet.

Den Code vertraulich weitergeben. Er liegt auch in src/config.php. Bei der lokalen Anmeldung über localhost ist er nicht nötig. Jedes Mitglied muss vor dem Zugriff auf Termine sein eigenes Passwort setzen.

Den Inhalt von src einschließlich der versteckten .htaccess-Dateien, config.php und assets/hintergrund.jpg nach /terminkalender/familie_XXXXX/ kopieren. Bei der ersten Bereitstellung können auch data/setup.json und data/termine.json mitgenommen werden. Bei späteren Updates die Serverdaten und Konten erhalten. Nur config.php enthält die dauerhaft gelesenen Serverwerte; initial_members wird nur bei fehlender setup.json importiert.

CRON täglich morgens mit dem PHP-CLI-Befehl für den vollständigen Serverpfad zu cron.php einrichten. Lokal werden keine echten Mails getestet.

