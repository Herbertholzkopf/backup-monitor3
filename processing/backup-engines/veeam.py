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

def process_duration(content, backup_type):
    # Versuche verschiedene Muster für Duration im HTML
    
    # 1. Standard-Format mit <b>-Tags
    match = re.search(r'<b>Duration</b>.*?(\d+):(\d+):(\d+)', content, re.DOTALL | re.IGNORECASE)
    
    # 2. Format mit Tabellenzellen ohne <b>-Tags
    if not match:
        match = re.search(r'Duration.*?</td>.*?(\d+):(\d+):(\d+)', content, re.DOTALL | re.IGNORECASE)
    
    # 3. Spezielles Format für Configuration Backup (3.txt)
    if not match:
        match = re.search(r'<td[^>]*>Duration</td><td[^>]*>(\d+):(\d+):(\d+)</td>', content, re.DOTALL | re.IGNORECASE)
    
    # 4. Einfaches Klartext-Format für Veeam Agent Mails
    if not match:
        match = re.search(r'Duration\s*(\d+):(\d+):(\d+)', content, re.IGNORECASE)
    
    if match:
        hours = int(match.group(1))
        minutes = int(match.group(2))
        seconds = int(match.group(3))
        total_minutes = hours * 60 + minutes + (1 if seconds >= 30 else 0)
        return total_minutes
    return None

def process_size(content, backup_type):
    # Versuche verschiedene Muster für Backup-Größe
    
    # 1. Standard-Format mit <b>-Tags
    match = re.search(r'<b>Backup size</b>.*?([\d.,]+)\s*([KMGT]?B)', content, re.DOTALL | re.IGNORECASE)
    
    # 2. Format mit Tabellenzellen ohne <b>-Tags
    if not match:
        match = re.search(r'Backup size.*?</td>.*?([\d.,]+)\s*([KMGT]?B)', content, re.DOTALL | re.IGNORECASE)
    
    # 3. Spezielles Format für Configuration Backup (3.txt)
    if not match:
        match = re.search(r'<td[^>]*>Backup size</td><td[^>]*>([\d.,]+)\s*([KMGT]?B)</td>', content, re.DOTALL | re.IGNORECASE)
    
    # 4. Einfaches Klartext-Format für Veeam Agent Mails
    if not match:
        match = re.search(r'Backup size\s*([\d.,]+)\s*([KMGT]?B)', content, re.IGNORECASE)
    
    if match:
        # Normalisiere Größenwert (ersetze Komma durch Punkt für Dezimalzahlen)
        size_str = match.group(1)
        size_str = size_str.replace(',', '.')
        
        try:
            size = float(size_str)
            unit = match.group(2).upper()
            
            # Konvertiere in MB
            if unit == 'B':
                return size / (1024 * 1024)  # B zu MB
            elif unit == 'KB':
                return size / 1024  # KB zu MB
            elif unit == 'GB':
                return size * 1024  # GB zu MB
            elif unit == 'TB':
                return size * 1024 * 1024  # TB zu MB
            else:  # Nehme MB an
                return size
        except ValueError:
            return None
    
    return None

def process_vm_results(content, backup_type):
    vm_results = []
    
    # Für Veeam Backup & Replication (alte Methode)
    if 'Veeam Backup & Replication' in backup_type:
        # Versuche zunächst die alte Methode
        vm_section_started = False
        
        for line in content.split('\n'):
            if 'NameStatusStart' in line:
                vm_section_started = True
                continue
            if vm_section_started and line.strip() and not 'Veeam Backup & Replication' in line:
                for status in ['Success', 'Warning', 'Failed']:
                    if status in line:
                        parts = line.split(status)
                        if len(parts) > 0:
                            vm_name = parts[0].strip()
                            vm_results.append(f"{vm_name}: {status}")
                            break
        
        # Wenn keine VMs gefunden wurden, versuche HTML-Parsing
        if not vm_results:
            matches = re.finditer(r'<td nowrap="" style="[^"]*">([^<]+)</td>\s*<td nowrap="" style="[^"]*"><span style="[^"]*">(Success|Warning|Failed)</span>', content)
            for match in matches:
                vm_name = match.group(1)
                status = match.group(2)
                vm_results.append(f"{vm_name}: {status}")
    
    # Für Veeam Agent
    else:
        # Suche nach Computernamen und Status in Veeam Agent E-Mails
        matches = re.finditer(r'<td nowrap="" style="[^"]*">([^<]+)</td>\s*<td nowrap="" style="[^"]*"><span style="[^"]*">(Success|Warning|Failed)</span>', content)
        for match in matches:
            vm_name = match.group(1)
            status = match.group(2)
            vm_results.append(f"{vm_name}: {status}")
    
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

                # Überprüfen, ob es sich um eine Veeam-Typ handelt (entweder Backup & Replication oder Agent)
                if not job or not job['backup_type'].startswith('Veeam'):
                    continue

                # Process mail content
                status = 'error'
                subject_lower = mail['subject'].lower()
                if 'success' in subject_lower:
                    status = 'success'
                elif 'warning' in subject_lower:
                    status = 'warning'

                print(f"Processing mail ID {mail['id']} - Status: {status} - Type: {job['backup_type']}")
                
                # Übergeben des Backup-Typs an die Verarbeitungsfunktionen
                duration = process_duration(mail['content'], job['backup_type'])
                size = process_size(mail['content'], job['backup_type'])
                vm_notes = process_vm_results(mail['content'], job['backup_type'])
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