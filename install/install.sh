#!/bin/bash
# install.sh

# Farben für Ausgaben
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo "Backup-Monitor Installations Skript"
echo "================================"

# Root-Rechte prüfen
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}Bitte als root ausführen${NC}"
    exit 1
fi

# Systemaktualisierung
echo -e "${YELLOW}Systemkomponenten werden aktualisiert...${NC}"
apt-get update
apt-get upgrade -y

# Installation von Python & Python-Paketen
echo -e "${YELLOW}Python wird installiert...${NC}"
apt-get install -y python3 python3-pymysql

# Installation von weiteren Paketen
echo -e "${YELLOW}MySQL, git, unzip, Nginx, php wird installiert...${NC}"
apt-get install -y mysql-server git unzip nginx
apt-get install -y nginx php-fpm php-mysql




# Konfiguration von MySQL
echo -e "${YELLOW}MySQL Root Passwort setzen...${NC}"
read -s -p "Gewünschtes MySQL Root Passwort: " mysqlpass
echo ""

mysql --user=root <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED WITH auth_socket;
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
EOF

sudo mysql <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${mysqlpass}';
FLUSH PRIVILEGES;
EOF

if ! mysql --user=root --password="${mysqlpass}" -e "SELECT 1;" >/dev/null 2>&1; then
    echo -e "${RED}Fehler beim Setzen des MySQL Root-Passworts.${NC}"
    exit 1
fi

echo -e "${GREEN}MySQL Root-Passwort erfolgreich gesetzt.${NC}"




# Einrichtung der MySQL Datenbank
echo -e "${YELLOW}Erstelle Datenbank "backup_monitor2" und Benutzer "backup_user"...${NC}"
read -s -p "Backup-Monitor Datenbank-Benutzer Passwort: " dbpass
echo ""

if ! mysql --user=root --password="${mysqlpass}" <<EOF
CREATE DATABASE IF NOT EXISTS backup_monitor2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'backup_user'@'localhost' IDENTIFIED BY '${dbpass}';
GRANT ALL PRIVILEGES ON backup_monitor2.* TO 'backup_user'@'localhost';
FLUSH PRIVILEGES;
EOF
then
    echo -e "${RED}Fehler beim Erstellen der Datenbank und des Benutzers.${NC}"
    exit 1
fi



# Verzeichnis für das Projekt erstellen
echo -e "${YELLOW}Erstelle Projekt-Verzeichnis...${NC}"
mkdir -p /var/www/backup-monitor2

# Projekt von GitHub klonen
echo -e "${YELLOW}Klone Git Repository...${NC}"
if git clone https://github.com/Herbertholzkopf/backup-monitor2.git /var/www/backup-monitor2; then
    echo -e "${GREEN}Repository erfolgreich geklont${NC}"
else
    echo -e "${RED}Fehler beim Klonen des Repositories${NC}"
    exit 1
fi



# Importiere die Datenbankstruktur (muss nach dem Klonen passieren, da sonst die database.sql noch nicht existiert)
if [ -f "/var/www/backup-monitor2/install/database.sql" ]; then
    if mysql --user=root --password="${mysqlpass}" backup_monitor2 < /var/www/backup-monitor2/install/database.sql; then
        echo -e "${GREEN}Datenbankstruktur erfolgreich importiert.${NC}"
    else
        echo -e "${RED}Fehler beim Importieren der Datenbankstruktur.${NC}"
        exit 1
    fi
else
    echo -e "${RED}database.sql nicht gefunden unter /var/www/backup-monitor2/install/database.sql${NC}"
    exit 1
fi

echo -e "${GREEN}Datenbank und Benutzer erfolgreich erstellt.${NC}"




# Rechte des Verzeichnis anpassen
chown -R www-data:www-data /var/www/backup-monitor2
chmod -R 755 /var/www/backup-monitor2
chown -R www-data:adm /var/www/backup-monitor2/log
chmod 750 /var/www/backup-monitor2/log
chmod 640 /var/www/backup-monitor2/log/*

# Nginx Konfiguration erstellen
echo -e "${YELLOW}Konfiguriere Nginx...${NC}"
cat > /etc/nginx/sites-available/backup-monitor2 <<'EOF'
server {
    listen 80;
    server_name _;
    root /var/www/backup-monitor2;
    index index.php index.html;

    # Hauptlocation
    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    # PHP-Verarbeitung
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_intercept_errors on;
    }

    # Verhindern des Zugriffs auf versteckte Dateien
    location ~ /\.(ht|git|py|env|config) {
        deny all;
        return 404;
    }

    # Verhindern des direkten Zugriffs auf bestimmte Verzeichnisse
    location ^~ /config/ {
        deny all;
        return 404;
    }

    # Logging
    error_log /var/www/backup-monitor2/log/error.log;
    access_log /var/www/backup-monitor2/log/access.log;
}
EOF


# Nginx Site verknüpfen und aktivieren
ln -s /etc/nginx/sites-available/backup-monitor2 /etc/nginx/sites-enabled/
rm /etc/nginx/sites-enabled/default
nginx -t && systemctl restart nginx

# Dienste neu starten
echo -e "${YELLOW}Dienste werden neu gestartet...${NC}"
systemctl restart nginx

## Cron-Jobs einrichten
#echo -e "${YELLOW}Richte Cron-Jobs ein...${NC}"
#cat > /etc/cron.d/backup-monitor2 <<EOF
## Backup-Monitor Cron Jobs
#*/5 * * * * www-data php /var/www/backup-monitor2/cron/fetch_mails.php
#2-59/5 * * * * www-data php /var/www/backup-monitor2/cron/analyze_mails.php
#EOF


echo -e "${GREEN}Installation abgeschlossen!${NC}"
echo -e "${YELLOW}Mit der Einrichtung kann nun im Browser fortgefahren werden: http://ihre-domain.de/ bzw. http://ihre-domain.de/settings"


# Backup Datenbank, Benutzer und Kennwort müssen noch in die /config/database.py eingetragen werden
# Mail-Zugangsdaten müssen noch unter /config/mail.py eingetragen werden