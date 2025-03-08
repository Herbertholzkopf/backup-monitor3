# Löschen von Backup-Ergebnissen
Suche zunächst die Kunden ID
Dann den Backup-Job über die Sortierung nach Kunden ID
Lösche nun alle Einträge mit der Backup ID in der backup_results Tabelle

# Mail-Verarbeitungs-Status zurücksetzen
Jetzt kann mit folgendem Befehl alle Mails angezeigt werden, die derzeit zu keinem Eintrag in der backup_results-Tabelle gehören

USE backup_monitor3;
SELECT mails.* 
FROM mails 
LEFT JOIN backup_results ON mails.id = backup_results.mail_id
WHERE backup_results.id IS NULL;

Um jetzt allen dort angezeigten Mails automatisch den Wert von processed und job_found auf 0 zu setzen (um eine erneute Zuordnung und Verarbeitung zu durchlaufen) nutze folgenden Befehl:

USE backup_monitor3;

UPDATE mails
LEFT JOIN backup_results ON mails.id = backup_results.mail_id
SET mails.job_found = FALSE, mails.result_processed = FALSE
WHERE backup_results.id IS NULL;