# backup-monitor3



# Installationsschritte:

## Webserver
Projekt von GitHub herunterladen und in einen Pfad für einen Webserver legen (in diesem Fall Windows IIS)
für dieses Projekt wird PHP benötigt, mit folgenden Modulen: **pdo_mysql**, **mysqli**

## MySQL Datenbank
Richte eine MySQL Datenbank ein. 
Hier sollte ein extra Benutzer z.B. backup_user und eine Datenbank backup_monitor3 erstellt werden und die Rechte richtig vergeben werden.
(siehe dir hierfür die install.sh aus dem install-Verzeichnis an!)
Für die Einrichtung der eigentlichen Datenbank-Tabellen, sieh die die database.sql im install-Verzeichnis an.
Im settings-Verzeichnis im Ordner information ist eine instructions.sql zu finden. Diese muss in die Datenbank-Tabelle **instructions** importiert werden (enthält Informationen zur richtigen Einrichtung der Backup-Jobs)

## Konfigurationen anpassen
Die Datenbank(zugangsdaten) müssen noch in 2 Dateien korrigiert werden (1x für .py-Skripte und 1x für .php-Skripte)
im root-Verzeichnis in der config.php und in der /processing/config/database.py
im /processing/config-Verzeichnis muss auch noch die mail-py für den Empfang und Versand von Mails konfiguriert werden.
im /processing/mail-reports/daily_status_mail.py muss relativ weit unten noch die Empfängeradresse geändert werden (derzeit: technik@phd-it-systeme.de)

## Aufgabenplanung / Cron-Jobs
nahezu alle .py-Skripte müssen über eine Aufgabenplanung oder ähnliches für ein automatisches Ausführen konfiguriert werden.
| Skript | Dauer | Beschreibung |
| --- | --- | --- |
| processing/**mail-to-database.py** | z.B. alle 5 Minuten | schneidet Mails aus dem Postfach aus und speichert sie in die Datenbank |
| processing/**mail-and-job.py** | z.B. alle 5 Minuten | findet den passenden Backup-Job zur Mail & "verknüpft diese" |
| processing/backup-engines/**proxmox.py** | z.B. alle 5 Minuten | liest verschiedene Werte aus den Mails aus und speichert einen Status ab |
| processing/backup-engines/**synaxon-cloud.py** | z.B. alle 5 Minuten | liest verschiedene Werte aus den Mails aus und speichert einen Status ab |
| processing/backup-engines/**synology-hyperbackup.py** | z.B. alle 5 Minuten | liest verschiedene Werte aus den Mails aus und speichert einen Status ab |
| processing/backup-engines/**veeam.py** | z.B. alle 5 Minuten | liest verschiedene Werte aus den Mails aus und speichert einen Status ab |
| processing/mail-reports/**daily_status.py** | z.B. täglich 7:50 Uhr | speichert, wie lange ein Backupstatus schon existiert den aktuellen Wert hat |
| processing/mail-reports/**daily_status_mail.py** | z.B. täglich 8:00 Uhr | nutzt die von daily_status.py gespeicherten Werte und schickt sie per Mail |
