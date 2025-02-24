# Suche in der mails Datenbank nach mit job_found "true" und result_processed "false"
# In der backup_results Datenbank dann nach der mail_id suchen
# Dann in der backup_job_id suchen und überprüfen, ob es der backup_type "Synaxon managed Backup" ist, wenn nicht , überspringen und den nächsten Eintrag in der mails Datenbank prüfen.
#
# Wenn dann mal eine Mail gefunden wird, bei der alle Bedingungen erfüllt sind (job_found "true" und result_processed "false" und backup_type "Synaxon managed Backup"), dann:
#
# - wenn der Betreff der Mail "Statusbericht" enthält, dann setze result_processed auf True und überspringe die Mail & lösche den Eintrag in der backup_results Tabelle
#
# - Betreff nach "erfolgreich" oder "WARNUNGEN" durchsuchen -> setzte den Status in backup_results auf 'success' oder 'warning'
# - date aus der mail-Tabelle in der backup_results in die Felder date und time speichern
# - suche in der mail-Tabelle im Text der Mail (content) nach der Zeit "Backup-Dauer 00:10:04" und speichere die Zeit in der backup_results in das Feld "duration_minutes"
# - suche in der mail-Tabelle im Text der Mail (content) nach dem Plan-Namen "Plan-Name Frankenbreche Stöhr - Backup täglich PC SRV01" und Speichere in der backup_results Tabelle in das note-Feld
#
# - Setzt result_processed in der mails-Tabelle auf True

import sys
import os
import pymysql
import re
from datetime import datetime

# Database connection
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

def process_duration(content):
    """
    Extrahiert die Backup-Dauer aus dem E-Mail-Inhalt.
    """
    # Vereinfachter Ansatz: Finde die Position von "Backup-Dauer" und suche dann 
    # nach der nächsten Zeitangabe im Format HH:MM:SS
    backup_dauer_pos = content.find("Backup-Dauer")
    if backup_dauer_pos > -1:
        # Betrachte die nächsten 50 Zeichen nach "Backup-Dauer"
        search_area = content[backup_dauer_pos:backup_dauer_pos + 50]
        
        # Finde die erste Zeitangabe im Format HH:MM:SS
        time_match = re.search(r'(\d{2}):(\d{2}):(\d{2})', search_area)
        if time_match:
            hours = int(time_match.group(1))
            minutes = int(time_match.group(2))
            seconds = int(time_match.group(3))
            
            # Berechne die Gesamtdauer in Minuten
            total_minutes = hours * 60 + minutes + (1 if seconds >= 30 else 0)
            return total_minutes
    
    # Wenn keine Zeitangabe gefunden wurde, versuche es mit den alten Mustern als Fallback
    fallback_patterns = [
        r'Backup-Dauer\s+(\d{2}):(\d{2}):(\d{2})',
        r'Backup-Dauer.*?(\d{2}):(\d{2}):(\d{2})',
        r'Backup-Dauer[^\d]*(\d{2}):(\d{2}):(\d{2})'
    ]
    
    for pattern in fallback_patterns:
        match = re.search(pattern, content, re.DOTALL)
        if match:
            hours = int(match.group(1))
            minutes = int(match.group(2))
            seconds = int(match.group(3))
            total_minutes = hours * 60 + minutes + (1 if seconds >= 30 else 0)
            return total_minutes
    
    return None

def process_plan_name(content):
    match = re.search(r'Plan-Name ([^\n]+)', content)
    if match:
        return match.group(1).strip()
    return None

def process_synaxon_mails(connection):
    print("Starting Synaxon backup mail processing...")
    try:
        with connection.cursor() as cursor:
            cursor.execute("""
                SELECT m.*, br.backup_job_id 
                FROM mails m
                JOIN backup_results br ON br.mail_id = m.id
                WHERE m.job_found = TRUE 
                AND m.result_processed = FALSE
            """)
            mails = cursor.fetchall()

            for mail in mails:
                cursor.execute("""
                    SELECT backup_type 
                    FROM backup_jobs 
                    WHERE id = %s
                """, (mail['backup_job_id'],))
                job = cursor.fetchone()

                if not job or job['backup_type'] != 'Synaxon managed Backup':
                    continue

                # Neue Prüfung auf "Statusbericht" im Betreff
                if "Statusbericht" in mail['subject']:
                    print(f"Statusbericht found in mail ID {mail['id']} - Deleting backup result")
                    # Backup-Result löschen
                    cursor.execute("""
                        DELETE FROM backup_results 
                        WHERE mail_id = %s
                    """, (mail['id'],))
                    
                    # Mail als verarbeitet markieren
                    cursor.execute("""
                        UPDATE mails 
                        SET result_processed = TRUE 
                        WHERE id = %s
                    """, (mail['id'],))
                    
                    connection.commit()
                    continue

                # Originaler Code für die "normale" Verarbeitung
                status = 'error'
                subject_lower = mail['subject'].lower()
                if 'erfolgreich' in subject_lower:
                    status = 'success'
                elif 'warnungen' in subject_lower:
                    status = 'warning'

                print(f"Processing mail ID {mail['id']} - Status: {status}")
                duration = process_duration(mail['content'])
                plan_name = process_plan_name(mail['content'])
                mail_date = mail['date']

                cursor.execute("""
                    UPDATE backup_results 
                    SET status = %s,
                        date = %s,
                        time = %s,
                        duration_minutes = %s,
                        note = %s
                    WHERE mail_id = %s
                """, (
                    status,
                    mail_date.date(),
                    mail_date.time(),
                    duration,
                    plan_name,
                    mail['id']
                ))

                cursor.execute("""
                    UPDATE mails 
                    SET result_processed = TRUE 
                    WHERE id = %s
                """, (mail['id'],))

                connection.commit()
                print(f"Mail ID {mail['id']} processed successfully - Duration: {duration} minutes\n")

    except Exception as e:
        print(f"Error processing mails: {e}")
        connection.rollback()

def main():
    connection = connect_to_database()
    try:
        process_synaxon_mails(connection)
    finally:
        connection.close()

if __name__ == "__main__":
    main()