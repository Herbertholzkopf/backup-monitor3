USE backup_monitor3;


-- Tabelle für die eingegangenen Mails (werden durch die mail-to-database.py abgerufen und in der DB gespeichert)
-- enthaelt Absendermailadresse, Datum & Uhrzeit der Mail, Betreff und den Inhalt der Mail
-- zusätzlich gibt es einen Wert, der angibt, ob die Mail bereits verarbeitet wurden 
-- (einem Kunden mit Backup-Job zugeordnet & der Mailinhalt zu einem Ergebnis in der backup_results Tabelle geführt hat)
CREATE TABLE IF NOT EXISTS mails (
    id INT PRIMARY KEY AUTO_INCREMENT,
    date DATETIME NOT NULL,
    sender_email VARCHAR(255) NOT NULL,
    subject TEXT,
    content MEDIUMTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    job_found BOOLEAN DEFAULT FALSE,
    result_processed BOOLEAN DEFAULT FALSE
);


-- Tabelle für die angelegten Kunden
-- Die Kunden haben ein ID in der Datenbank, um Backup-Jobs Kunden zuweisen zu können
-- Außerdem natürlich einen Kundennamen, Kundennummer (kann auch mit 0 bgeninnen) und einem Notizenfeld
CREATE TABLE IF NOT EXISTS customers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    number VARCHAR(20) NOT NULL,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- Tabelle für die angelegten Backup-Jobs
-- Diese Tabelle wird genutzt, um in der Mails Tabelle nach bestimmten Begriffen (search_term) zu suchen.
-- Bei einem Backup-Job kann ein Name festgelegt werden und eine Notiz gespeichert werden
-- Backup-Art: z.B. Cloud Backup, Synology HyperBackup, Veeam Backup (soll als Badge im Dashboard beim Job angezeigt werden können)
CREATE TABLE IF NOT EXISTS backup_jobs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT,
    name VARCHAR(255) NOT NULL,
    note TEXT,
    backup_type VARCHAR(255),
    search_term_mail VARCHAR(255),
    search_term_subject VARCHAR(255),
    search_term_text VARCHAR(255),
    search_term_text2 VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);


-- enthält die Ergebnisse der verarbeiteten Mails:
-- Zuordnung zu einem Job (und zu einem Kunden, da ja der Kunden mit den Jobs verknüpft ist) [FOREIGN KEY unten], 
-- die Mail die verarbeitet wurde und zu diesem Ergebniss geführt hat [FOREIGN KEY unten],
-- Status/Ergebnis des Backups (erfolgreich, Warnung, Fehler),
-- Datum und Uhrzeit des Backups (--> wird aus der Mail-Datenbank kopiert) [ist dann zwar "doppelt" mit dem Datum aus der mail-Datenbank, aber notwendig, da alte Mails irgendwann gelöcht werden],
-- Notizenfeld für die Backup-Jobs,
-- Falls vorhanden, wird die Größe des Backups ausgelesen und hier gespeichert,
-- Falls vorhanden, wird die Dauer (in Minuten) des Backups ausgelesen und hier gespeichert
CREATE TABLE IF NOT EXISTS backup_results (
    id INT PRIMARY KEY AUTO_INCREMENT,
    backup_job_id INT,
    mail_id INT,
    status ENUM('success', 'warning', 'error'),
    date DATE,
    time TIME,
    note TEXT,
    size_mb DECIMAL(10,2),
    duration_minutes INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (backup_job_id) REFERENCES backup_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (mail_id) REFERENCES mails(id)
);