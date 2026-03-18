#!/usr/bin/env python3
# Dieses Skript sollte täglich ausgeführt werden!
# Es speichert in der Datenbank, wie lange ein bestimmter Backup-Status bereits existiert,
# indem es vom aktuellen Zeitpunkt in 24-Stunden-Schritten zurückspringt und prüft,
# wie viele aufeinanderfolgende Fenster denselben Status hatten.

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

def get_job_ignore_hours(connection, job_id):
    """Den ignore_no_status_updates_for_x_hours Wert für einen bestimmten Job holen"""
    with connection.cursor() as cursor:
        cursor.execute(
            "SELECT ignore_no_status_updates_for_x_hours FROM backup_jobs WHERE id = %s",
            (job_id,)
        )
        result = cursor.fetchone()
        if result and result['ignore_no_status_updates_for_x_hours'] is not None:
            return result['ignore_no_status_updates_for_x_hours']
        return 24  # Standardwert falls nicht gesetzt

def get_status_in_window(connection, job_id, window_start, window_end):
    """Den neuesten Backup-Status innerhalb eines exakten Zeitfensters holen.
    
    Nutzt TIMESTAMP(date, time) für präzise 24h-Fenster statt Kalendertage.
    window_start ist exklusiv (>), window_end ist inklusiv (<=).
    """
    with connection.cursor() as cursor:
        query = """
        SELECT status, date, time
        FROM backup_results
        WHERE backup_job_id = %s
          AND TIMESTAMP(date, time) > %s
          AND TIMESTAMP(date, time) <= %s
        ORDER BY date DESC, time DESC
        LIMIT 1
        """
        cursor.execute(query, (job_id, window_start, window_end))
        return cursor.fetchone()

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
            query = """
            UPDATE status_duration
            SET current_status = %s,
                days_in_status = %s,
                last_update = %s,
                last_backup_date = %s
            WHERE backup_job_id = %s
            """
            cursor.execute(query, (
                current_status, days_in_status, today, last_backup_date, job_id
            ))
        else:
            query = """
            INSERT INTO status_duration
            (backup_job_id, current_status, days_in_status, last_update, last_backup_date)
            VALUES (%s, %s, %s, %s, %s)
            """
            cursor.execute(query, (
                job_id, current_status, days_in_status, today, last_backup_date
            ))
    
    connection.commit()

def analyze_job_status(connection, job_id):
    """Die Statushistorie für einen bestimmten Backup-Job analysieren.
    
    Logik:
    1. Aktuellen Status bestimmen: Im Fenster (jetzt - ignore_hours) bis jetzt suchen.
       Kein Ergebnis → Status ist 'none'.
    2. In 24h-Schritten rückwärts zählen, wie viele aufeinanderfolgende Fenster
       denselben Status hatten.
    
    Beispiel (ignore_hours=24, Skript läuft am 18.03. um 08:00):
      Fenster 0 (aktuell):  17.03. 08:00 → 18.03. 08:00
      Fenster 1:            16.03. 08:00 → 17.03. 08:00
      Fenster 2:            15.03. 08:00 → 16.03. 08:00
      ...
    """
    now = datetime.datetime.now()
    ignore_hours = get_job_ignore_hours(connection, job_id)
    
    # --- Schritt 1: Aktuellen Status bestimmen ---
    window_end = now
    window_start = now - datetime.timedelta(hours=ignore_hours)
    
    latest = get_status_in_window(connection, job_id, window_start, window_end)
    
    if latest is None:
        current_status = 'none'
        last_backup_date = None
    else:
        current_status = latest['status']
        last_backup_date = latest['date']
    
    # --- Schritt 2: In 24h-Schritten rückwärts zählen ---
    days_in_status = 1  # Das aktuelle Fenster zählt bereits als 1
    
    for step in range(1, 31):
        step_end   = now - datetime.timedelta(hours=24 * step)
        step_start = now - datetime.timedelta(hours=24 * (step + 1))
        
        prev = get_status_in_window(connection, job_id, step_start, step_end)
        
        if current_status == 'none':
            # Kein aktueller Status → zähle wie viele Fenster ebenfalls leer waren
            if prev is None:
                days_in_status += 1
            else:
                break
        else:
            # Status vorhanden → zähle wie viele Fenster denselben Status hatten
            if prev is not None and prev['status'] == current_status:
                days_in_status += 1
            else:
                break
    
    # Auf 31 begrenzen (= "mehr als 30 Tage")
    if days_in_status > 30:
        days_in_status = 31
    
    # Datenbank aktualisieren
    update_status_duration(connection, job_id, current_status, days_in_status, last_backup_date)
    
    return {
        'job_id': job_id,
        'status': current_status,
        'days': days_in_status,
        'last_backup_date': last_backup_date,
        'ignore_hours': ignore_hours
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
            
            print(f"  Job {result['job_id']}: {status_text} seit {result['days']} Tag(en) "
                  f"(Toleranz: {result['ignore_hours']}h)")
        
        print("Analyse abgeschlossen!")
    finally:
        connection.close()

if __name__ == "__main__":
    main()