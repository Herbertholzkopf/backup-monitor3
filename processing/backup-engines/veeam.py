# Suche in der mails Datenbank nach mit job_found "true" und result_processed "false"
# In der backup_results Datenbank dann nach der mail_id suchen
# Dann in der backup_job_id suchen und überprüfen, ob es der backup_type "Veeam Backup & Replication" ist, wenn nicht , überspringen und den nächsten Eintrag in der mails Datenbank prüfen.
#
# Wenn dann mal eine Mail gefunden wird, bei der alle Bedingungen erfüllt sind (job_found "true" und result_processed "false" und backup_type "Veeam Backup & Replication"), dann:
# - Betreff nach "Success" oder "Warning" oder "Failed" durchsuchen -> setzte den Status in backup_results auf 'success' oder 'warning' oder 'error'
# - date aus der mail-Tabelle in der backup_results in die Felder date und time speichern
# - suche in der mail-Tabelle im Text der Mail (content) nach der Zeit "Duration0:07:26" und speichere die Zeit in der backup_results in das Feld "duration_minutes"
# - suche in der mail-Tabelle im Text der Mail (content) nach der Größe "Backup size29,8 GB" und speichere die Größe in der backup_results in das Feld "size_mb"
# - suche in der mail-Tabelle im Text der Mail (content) nach den verschiedenen VMs die gesichert worden sind und Speichere deren Namen und das Ergebnis (z.B. "Success") in der backup_results Tabelle in das note-Feld
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
    match = re.search(r'Duration(\d+):(\d+):(\d+)', content)
    if match:
        hours = int(match.group(1))
        minutes = int(match.group(2))
        seconds = int(match.group(3))
        total_minutes = hours * 60 + minutes + (1 if seconds >= 30 else 0)
        return total_minutes
    return None

def process_size(content):
    match = re.search(r'Backup size([\d,]+)\s*([MGT]B)', content)
    if match:
        size = float(match.group(1).replace(',', '.'))
        unit = match.group(2)
        if unit == 'GB':
            return size * 1024  # Convert to MB
        elif unit == 'TB':
            return size * 1024 * 1024  # Convert to MB
        return size
    return None

def process_vm_results(content):
    vm_results = []
    vm_section_started = False
    veeam_line_found = False
    
    for line in content.split('\n'):
        if 'Veeam Backup & Replication' in line:
            veeam_line_found = True
            break
        if 'NameStatusStart' in line:
            vm_section_started = True
            continue
        if vm_section_started and line.strip() and not veeam_line_found:
            for status in ['Success', 'Warning', 'Failed']:
                if status in line:
                    vm_name = line.split(status)[0].strip()
                    vm_results.append(f"{vm_name}: {status}")
                    break
    
    return "\n".join(vm_results) if vm_results else None

def process_veeam_mails(connection):
    print("Starting Veeam backup mail processing...")
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
                # Check if backup job is Veeam type
                cursor.execute("""
                    SELECT backup_type 
                    FROM backup_jobs 
                    WHERE id = %s
                """, (mail['backup_job_id'],))
                job = cursor.fetchone()

                if not job or job['backup_type'] != 'Veeam Backup & Replication':
                    continue

                # Process mail content
                status = 'error'
                subject_lower = mail['subject'].lower()
                if 'success' in subject_lower:
                    status = 'success'
                elif 'warning' in subject_lower:
                    status = 'warning'

                print(f"Processing mail ID {mail['id']} - Status: {status}")
                duration = process_duration(mail['content'])
                size = process_size(mail['content'])
                vm_notes = process_vm_results(mail['content'])
                mail_date = mail['date']

                # Update backup_results
                cursor.execute("""
                    UPDATE backup_results 
                    SET status = %s,
                        date = %s,
                        time = %s,
                        duration_minutes = %s,
                        size_mb = %s,
                        note = %s
                    WHERE mail_id = %s
                """, (
                    status,
                    mail_date.date(),
                    mail_date.time(),
                    duration,
                    size,
                    vm_notes,
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
        process_veeam_mails(connection)
    finally:
        connection.close()

if __name__ == "__main__":
    main()