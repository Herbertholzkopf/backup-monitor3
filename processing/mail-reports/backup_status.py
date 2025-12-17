# Dieses Skript sollte täglich ausgeführt werden!
# Es speichert in der Datenbank, wie lange ein bestimmter Backup-Status bereits existiert,
# indem es sich die letzte Aktualisierung (Datum & Status) abruft und diese mit dem aktuellen 
# Status vergleicht und dann den "days_in_status" passend erhöht.
# (falls das Skript einen Tag lang nicht ausgeführt worden ist und an diesem Tag ein anderer 
# Status war, wird der Counter nicht zurückgesetzt!!!)

#!/usr/bin/env python3
import os
import sys
import pymysql
import datetime

# Datenbankverbindung
sys.path.append(os.path.join(os.path.dirname(__file__), '../config'))
import database

def connect_to_database():
    try:
        connection = pymysql.connect(
            host=database.DB_HOST,
            user=database.DB_USER,
            password=database.DB_PASSWORD,
            database=database.DB_NAME,
            charset='utf8mb4',
            cursorclass=pymysql.cursors.DictCursor
        )
        return connection
    except Exception as e:
        print(f"Fehler beim Verbinden mit der Datenbank: {e}")
        sys.exit(1)

def get_all_backup_jobs(connection):
    """Alle Backup-Job-IDs aus der Datenbank holen"""
    with connection.cursor() as cursor:
        cursor.execute("SELECT id FROM backup_jobs")
        return [job['id'] for job in cursor.fetchall()]

def get_status_for_period(connection, job_id, start_date, end_date):
    """Den neuesten Backup-Status für einen bestimmten Job und Zeitraum holen"""
    with connection.cursor() as cursor:
        query = """
        SELECT status, date 
        FROM backup_results
        WHERE backup_job_id = %s
        AND date BETWEEN %s AND %s
        ORDER BY date DESC, time DESC
        LIMIT 1
        """
        cursor.execute(query, (job_id, start_date, end_date))
        result = cursor.fetchone()
        return result

def update_status_duration(connection, job_id, current_status, days_in_status, last_backup_date=None):
    """Die status_duration Tabelle mit den aktuellen Statusinformationen aktualisieren"""
    today = datetime.date.today()
    
    with connection.cursor() as cursor:
        # Prüfen, ob bereits ein Eintrag für diesen Job existiert
        cursor.execute(
            "SELECT id FROM status_duration WHERE backup_job_id = %s", 
            (job_id,)
        )
        existing_entry = cursor.fetchone()
        
        if existing_entry:
            # Bestehenden Eintrag aktualisieren
            query = """
            UPDATE status_duration
            SET current_status = %s,
                days_in_status = %s,
                last_update = %s,
                last_backup_date = %s
            WHERE backup_job_id = %s
            """
            cursor.execute(query, (
                current_status, 
                days_in_status, 
                today, 
                last_backup_date, 
                job_id
            ))
        else:
            # Neuen Eintrag hinzufügen
            query = """
            INSERT INTO status_duration
            (backup_job_id, current_status, days_in_status, last_update, last_backup_date)
            VALUES (%s, %s, %s, %s, %s)
            """
            cursor.execute(query, (
                job_id, 
                current_status, 
                days_in_status, 
                today, 
                last_backup_date
            ))
    
    connection.commit()

def analyze_job_status(connection, job_id):
    """Die Statushistorie für einen bestimmten Backup-Job analysieren"""
    today = datetime.date.today()
    
    # Status der letzten 24 Stunden prüfen
    yesterday = today - datetime.timedelta(days=1)
    latest_status = get_status_for_period(connection, job_id, yesterday, today)
    
    if latest_status is None:
        current_status = 'none'
        last_backup_date = None
    else:
        current_status = latest_status['status']
        last_backup_date = latest_status['date']
    
    # Tage mit dem gleichen Status zählen
    days_in_status = 1
    
    # Bis zu 30 Tage zurückprüfen
    for day in range(1, 30):
        start_date = today - datetime.timedelta(days=day+1)
        end_date = today - datetime.timedelta(days=day)
        
        prev_status = get_status_for_period(connection, job_id, start_date, end_date)
        
        # Logik basierend auf dem aktuellen Status
        if current_status == 'none':
            # Wenn der aktuelle Status 'none' ist, zählen wir Tage ohne Status
            if prev_status is None:
                days_in_status += 1
            else:
                # Ein Tag mit Status gefunden, Kette unterbrochen
                break
        elif current_status in ('warning', 'error'):
            # Für Warnungen oder Fehler zählen wir Tage mit demselben Status
            if prev_status is not None and prev_status['status'] == current_status:
                days_in_status += 1
            else:
                # Anderer Status oder kein Status gefunden, Kette unterbrochen
                break
        else:  # 'success'
            # Für Erfolg zählen wir Tage mit demselben Status
            if prev_status is not None and prev_status['status'] == current_status:
                days_in_status += 1
            else:
                # Anderer Status oder kein Status gefunden, Kette unterbrochen
                break
    
    # Auf 31 Tage begrenzen, wenn der Status länger als 30 Tage gleich ist
    if days_in_status >= 30:
        days_in_status = 31
    
    # Datenbank aktualisieren
    update_status_duration(connection, job_id, current_status, days_in_status, last_backup_date)
    
    return {
        'job_id': job_id,
        'status': current_status,
        'days': days_in_status,
        'last_backup_date': last_backup_date
    }

def main():
    """Hauptfunktion zum Ausführen der Statusanalyse"""
    connection = connect_to_database()
    try:
        job_ids = get_all_backup_jobs(connection)
        
        print(f"Analysiere Status von {len(job_ids)} Backup-Jobs...")
        for job_id in job_ids:
            result = analyze_job_status(connection, job_id)
            status_text = {
                'success': 'Erfolg',
                'warning': 'Warnung',
                'error': 'Fehler',
                'none': 'Kein Status'
            }.get(result['status'], result['status'])
            
            print(f"Job {result['job_id']}: {status_text} seit {result['days']} Tagen")
        
        print("Analyse abgeschlossen!")
    finally:
        connection.close()

if __name__ == "__main__":
    main()