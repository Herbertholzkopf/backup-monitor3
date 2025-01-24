# Suche in der mails Datenbank nach mit job_found "true" und result_processed "false"
# In der backup_results Datenbank dann nach der mail_id suchen
# Dann in der backup_job_id suchen und überprüfen, ob es der backup_type "Proxmox" ist, wenn nicht , überspringen und den nächsten Eintrag in der mails Datenbank prüfen.
#
# Wenn dann mal eine Mail gefunden wird, bei der alle Bedingungen erfüllt sind (job_found "true" und result_processed "false" und backup_type "Proxmox"), dann:
# - Betreff nach "successful" oder "failed" durchsuchen -> setzte den Status in backup_results auf "success" oder "error"
# - date aus der mail-Tabelle in der backup_results in die Felder date und time speichern
# - suche in der mail-Tabelle im Text der Mail (content) nach der Zeit "Total running time: 9m 28s" und speichere die Zeit in der backup_results in das Feld "duration_minutes"
# - suche in der mail-Tabelle im Text der Mail (content) nach der Größe "Total size: 486.986 GiB" und speichere die Größe in der backup_results in das Feld "size_mb"
#
# - Setzt result_processed in der mails-Tabelle auf True




import sys
import os
import pymysql
import re
from datetime import datetime

# Konfigurationsdateien einbinden
sys.path.append(os.path.join(os.path.dirname(__file__), 'config'))
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
    match = re.search(r'Total running time: (\d+)m (\d+)s', content)
    if match:
        minutes = int(match.group(1))
        seconds = int(match.group(2))
        return minutes + (1 if seconds >= 30 else 0)  # Round up if seconds >= 30
    return None

def process_size(content):
    match = re.search(r'Total size: ([\d.]+) ([GM]iB)', content)
    if match:
        size = float(match.group(1))
        unit = match.group(2)
        if unit == 'GiB':
            return size * 1024  # Convert to MiB
        return size
    return None

def process_proxmox_mails(connection):
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
                # Check if backup job is Proxmox type
                cursor.execute("""
                    SELECT backup_type 
                    FROM backup_jobs 
                    WHERE id = %s
                """, (mail['backup_job_id'],))
                job = cursor.fetchone()

                if not job or job['backup_type'] != 'Proxmox':
                    continue

                # Process mail content
                status = 'success' if 'successful' in mail['subject'].lower() else 'error'
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

    except Exception as e:
        print(f"Error processing mails: {e}")
        connection.rollback()

def main():
    connection = connect_to_database()
    try:
        process_proxmox_mails(connection)
    finally:
        connection.close()

if __name__ == "__main__":
    main()