# backup-monitor3



# Installationsschritte:

Installations-Skript von GitHub herunterladen,
Skript ausführbar machen,
Skript ausführen:
```
wget https://raw.githubusercontent.com/Herbertholzkopf/backup-monitor3/refs/heads/main/install/install.sh
chmod +x install.sh
./install.sh
```

Bei der Einrichtung wird das Kennwort für die Datenbank festgelegt (root Bentzer und backup_user).
Dieser Zugang zur Datenbank muss in 2 Config-Dateien und die Mail-Account-Daten in 1 Config hinterlegt werden:
```
nano /var/www/backup-monitor3/config.php
nano /var/www/backup-monitor3/processing/config/database.py
nano /var/www/backup-monitor3/processing/config/mail.py
```



#################################
# ausführen:
```
/var/www/backup-monitor2/mail-to-database.py
```