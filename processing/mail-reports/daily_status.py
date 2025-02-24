# Dieses Skript sollte täglich ausgeführt werden!
# Es speichert in der Datenbank, wie lange ein bestimmter Backup-Status bereits existiert,
# indem es sich die letzte Aktualisierung (Datum & Status) abruft und diese mit dem aktuellen 
# Status vergleicht und dann den "days_in_status" passend erhöht.
# (falls das Skript einen Tag lang nicht ausgeführt worden ist und an diesem Tag ein anderer 
# Status war, wird dieser der Counter nicht zurückgesetzt!!!)

#!/usr/bin/env python3
import os
import sys
import pymysql
from datetime import datetime, timedelta
import logging

# Konfigurationsdateien einbinden
sys.path.append(os.path.join(os.path.dirname(__file__), '../config'))
import database

# Konfiguration des Loggings
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('daily_status.log'),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger('daily_status')

def connect_to_database():
    """Stellt eine Verbindung zur Datenbank her"""
    try:
        connection = pymysql.connect(
            host=database.DB_HOST,
            user=database.DB_USER,
            password=database.DB_PASSWORD,
            database=database.DB_NAME,
            charset='utf8mb4',
            cursorclass=pymysql.cursors.DictCursor
        )
        logger.info("Datenbankverbindung erfolgreich hergestellt")
        return connection
    except Exception as e:
        logger.error(f"Fehler beim Verbinden mit der Datenbank: {e}")
        sys.exit(1)

def get_all_backup_jobs(conn):
    """Holt alle Backup-Jobs aus der Datenbank"""
    cursor = conn.cursor()
    query = """
    SELECT bj.id, bj.name, c.name as customer_name, bj.customer_id
    FROM backup_jobs bj
    JOIN customers c ON bj.customer_id = c.id
    """
    cursor.execute(query)
    jobs = cursor.fetchall()
    cursor.close()
    return jobs

def get_latest_backup_result(conn, job_id):
    """Holt das neueste Backup-Ergebnis für einen bestimmten Job"""
    cursor = conn.cursor()
    query = """
    SELECT id, status, date, time
    FROM backup_results
    WHERE backup_job_id = %s
    ORDER BY date DESC, time DESC
    LIMIT 1
    """
    cursor.execute(query, (job_id,))
    result = cursor.fetchone()
    cursor.close()
    return result

def get_current_status_duration(conn, job_id):
    """Holt den aktuellen Status und dessen Dauer aus der status_duration Tabelle"""
    cursor = conn.cursor()
    query = """
    SELECT id, current_status, days_in_status, last_update, last_backup_date
    FROM status_duration
    WHERE backup_job_id = %s
    """
    cursor.execute(query, (job_id,))
    result = cursor.fetchone()
    cursor.close()
    return result

def update_status_duration(conn, job_id, status, last_backup_date=None):
    """Aktualisiert oder erstellt einen Eintrag in der status_duration Tabelle"""
    today = datetime.now().date()
    current_status = get_current_status_duration(conn, job_id)
    
    cursor = conn.cursor()
    
    if current_status:
        # Bereits vorhandener Eintrag
        if current_status['current_status'] == status:
            # Status ist gleich geblieben, erhöhe die Anzahl der Tage
            days_in_status = (today - current_status['last_update']).days + current_status['days_in_status']
            query = """
            UPDATE status_duration
            SET days_in_status = %s, last_update = %s
            WHERE backup_job_id = %s
            """
            cursor.execute(query, (days_in_status, today, job_id))
        else:
            # Status hat sich geändert, setze Tage zurück auf 1
            query = """
            UPDATE status_duration
            SET current_status = %s, days_in_status = 1, last_update = %s, last_backup_date = %s
            WHERE backup_job_id = %s
            """
            cursor.execute(query, (status, today, last_backup_date, job_id))
    else:
        # Neuer Eintrag
        query = """
        INSERT INTO status_duration (backup_job_id, current_status, days_in_status, last_update, last_backup_date)
        VALUES (%s, %s, %s, %s, %s)
        """
        cursor.execute(query, (job_id, status, 1, today, last_backup_date))
    
    conn.commit()
    cursor.close()

def check_missing_backups(conn):
    """Prüft auf Backup-Jobs ohne aktuelle Backups"""
    today = datetime.now().date()
    cursor = conn.cursor()
    
    # Alle status_duration Einträge holen, die 'none' als Status haben
    query = """
    SELECT sd.id, sd.backup_job_id, sd.days_in_status, sd.last_backup_date,
           bj.name as job_name, c.name as customer_name
    FROM status_duration sd
    JOIN backup_jobs bj ON sd.backup_job_id = bj.id
    JOIN customers c ON bj.customer_id = c.id
    WHERE sd.current_status = 'none'
    """
    cursor.execute(query)
    none_statuses = cursor.fetchall()
    
    # Für jeden 'none' Status die Tage aktualisieren
    for status in none_statuses:
        days_in_status = (today - datetime.strptime(str(status['last_update']), '%Y-%m-%d').date()).days + status['days_in_status']
        update_query = """
        UPDATE status_duration
        SET days_in_status = %s, last_update = %s
        WHERE id = %s
        """
        cursor.execute(update_query, (days_in_status, today, status['id']))
    
    conn.commit()
    cursor.close()

def process_all_jobs(conn):
    """Verarbeitet alle Backup-Jobs"""
    jobs = get_all_backup_jobs(conn)
    today = datetime.now().date()
    
    for job in jobs:
        logger.info(f"Verarbeite Job: {job['name']} (ID: {job['id']}) für Kunde: {job['customer_name']}")
        
        # Neuestes Backup-Ergebnis holen
        latest_result = get_latest_backup_result(conn, job['id'])
        
        if latest_result:
            # Datum des letzten Backups
            backup_date = latest_result['date']
            
            # Prüfe ob das Backup älter als 3 Tage ist
            days_since_backup = (today - backup_date).days
            
            if days_since_backup > 3:
                # Kein aktuelles Backup seit über 3 Tagen
                logger.warning(f"Job {job['name']}: Kein aktuelles Backup seit {days_since_backup} Tagen!")
                update_status_duration(conn, job['id'], 'none', backup_date)
            else:
                # Aktuelles Backup vorhanden, Status übernehmen
                status = latest_result['status']
                logger.info(f"Job {job['name']}: Aktueller Status ist {status} (Backup vom {backup_date})")
                update_status_duration(conn, job['id'], status, backup_date)
        else:
            # Noch nie ein Backup für diesen Job
            logger.warning(f"Job {job['name']}: Noch nie ein Backup durchgeführt!")
            update_status_duration(conn, job['id'], 'none')
    
    # Prüfe auf fehlende Backups
    check_missing_backups(conn)

def generate_daily_report(conn):
    """Erstellt einen täglichen Bericht über problematische Backup-Zustände"""
    cursor = conn.cursor()
    
    query = """
    SELECT sd.backup_job_id, sd.current_status, sd.days_in_status, sd.last_backup_date,
           bj.name as job_name, c.name as customer_name
    FROM status_duration sd
    JOIN backup_jobs bj ON sd.backup_job_id = bj.id
    JOIN customers c ON bj.customer_id = c.id
    WHERE (sd.current_status = 'warning' AND sd.days_in_status >= 1)
       OR (sd.current_status = 'error' AND sd.days_in_status >= 1)
       OR (sd.current_status = 'none' AND sd.days_in_status >= 3)
    ORDER BY sd.days_in_status DESC, sd.current_status
    """
    
    cursor.execute(query)
    problematic_backups = cursor.fetchall()
    cursor.close()
    
    if not problematic_backups:
        logger.info("Keine problematischen Backup-Zustände gefunden.")
        return "Alle Backups sind in Ordnung."
    
    report = "Täglicher Backup-Statusbericht:\n\n"
    
    for backup in problematic_backups:
        status_text = {
            'warning': 'Warnung',
            'error': 'Fehler',
            'none': 'Kein Backup'
        }.get(backup['current_status'], backup['current_status'])
        
        last_backup = f"Letztes Backup: {backup['last_backup_date']}" if backup['last_backup_date'] else "Noch nie ein Backup durchgeführt"
        
        report += f"Kunde: {backup['customer_name']}\n"
        report += f"Backup-Job: {backup['job_name']}\n"
        report += f"Status: {status_text} (seit {backup['days_in_status']} Tagen)\n"
        report += f"{last_backup}\n\n"
    
    logger.info(f"Report generiert mit {len(problematic_backups)} problematischen Backup-Zuständen")
    return report

def main():
    """Hauptfunktion"""
    logger.info("Starte Backup Status Monitor")
    
    # Verbindung zur Datenbank herstellen
    conn = connect_to_database()
    
    try:
        # Alle Jobs verarbeiten
        process_all_jobs(conn)
        
        # Täglichen Bericht erstellen
        report = generate_daily_report(conn)
        logger.info("Täglicher Bericht erstellt")
        
        # Hier könnte man den Bericht per E-Mail versenden
        # send_email_report(report)
        
        logger.info("Backup Status Monitor erfolgreich abgeschlossen")
    except Exception as e:
        logger.error(f"Fehler im Backup Status Monitor: {e}")
    finally:
        conn.close()
        logger.info("Datenbankverbindung geschlossen")

if __name__ == "__main__":
    main()