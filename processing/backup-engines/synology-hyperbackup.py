import sys
import os
import pymysql
import re
from datetime import datetime

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

def process_duration(content):
    match = re.search(r'Dauer: (\d+) Minuten (\d+) Sekunden', content)
    if match:
        minutes = int(match.group(1))
        seconds = int(match.group(2))
        return minutes + (1 if seconds >= 30 else 0)  # Aufrunden wenn Sekunden >= 30
    
    # Prüfe auch auf Format mit nur Sekunden
    match = re.search(r'Dauer: (\d+) Sekunden', content)
    if match:
        seconds = int(match.group(1))
        return 1 if seconds >= 30 else 0  # Wenn weniger als 30 Sekunden, dann 0 Minuten
    
    return None

def process_size(content):
    match = re.search(r'Erhöhte Zielgröße: ([\d.]+) ([MGT]B)', content)
    if match:
        size = float(match.group(1))
        unit = match.group(2)
        # Konvertiere alles in MB
        if unit == 'GB':
            return size * 1024
        elif unit == 'TB':
            return size * 1024 * 1024
        return size  # Wenn MB, dann direkt zurückgeben
    return None

def process_synology_mails(connection):
    print("Starting Synology backup mail processing...")
    try:
        with connection.cursor() as cursor:
            # Get unprocessed mails with found jobs
            cursor.execute("""
                SELECT m.*, br.backup_job_id 
                FROM mails m
                JOIN backup_results br ON br.mail_id = m.id
                WHERE m.job_found = TRUE 
                AND m.result_processed = FALSE
            """)
            mails = cursor.fetchall()

            for mail in mails:
                # Check if backup job is Synology type
                cursor.execute("""
                    SELECT backup_type 
                    FROM backup_jobs 
                    WHERE id = %s
                """, (mail['backup_job_id'],))
                job = cursor.fetchone()

                if not job or job['backup_type'] != 'Synology HyperBackup':
                    continue

                # Process mail content
                # Prüfe auf "erfolgreich" oder "fehlgeschlagen" im Betreff
                status = 'success' if 'erfolgreich' in mail['subject'].lower() else 'error'
                print(f"Processing mail ID {mail['id']} - Status: {status}")
                duration = process_duration(mail['content'])
                size = process_size(mail['content'])
                mail_date = mail['date']

                # Update backup_results
                cursor.execute("""
                    UPDATE backup_results 
                    SET status = %s,
                        date = %s,
                        time = %s,
                        duration_minutes = %s,
                        size_mb = %s
                    WHERE mail_id = %s
                """, (
                    status,
                    mail_date.date(),
                    mail_date.time(),
                    duration,
                    size,
                    mail['id']
                ))

                # Mark mail as processed
                cursor.execute("""
                    UPDATE mails 
                    SET result_processed = TRUE 
                    WHERE id = %s
                """, (mail['id'],))

                connection.commit()
                print(f"Mail ID {mail['id']} processed successfully - Size: {size} MB, Duration: {duration} minutes\n")

    except Exception as e:
        print(f"Error processing mails: {e}")
        connection.rollback()

def main():
    connection = connect_to_database()
    try:
        process_synology_mails(connection)
    finally:
        connection.close()

if __name__ == "__main__":
    main()
