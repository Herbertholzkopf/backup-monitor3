# script-runner anpassen


import sys
import os
import pymysql
import re
from datetime import datetime
from html.parser import HTMLParser

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

class MailStoreHTMLParser(HTMLParser):
    """
    Parser für MailStore HTML Reports
    Extrahiert die Statusinformationen aus der Archivierungsstatistiken-Tabelle
    """
    def __init__(self):
        super().__init__()
        self.in_archiving_stats = False
        self.in_table = False
        self.in_td = False
        self.in_status_cell = False
        self.current_row = []
        self.statuses = []
        self.td_count = 0
        self.found_header = False
        
    def handle_starttag(self, tag, attrs):
        # Prüfe ob wir im Archivierungsstatistiken-Bereich sind
        if tag == 'div':
            for attr in attrs:
                if attr[0] == 'style' and 'color: #e65f1e' in attr[1]:
                    # Wir sind in einem orange Header
                    self.in_archiving_stats = False  # Reset
                    
        if tag == 'table' and self.found_header:
            self.in_table = True
            self.found_header = False
            
        if tag == 'tr' and self.in_table:
            self.current_row = []
            self.td_count = 0
            
        if tag == 'td' and self.in_table:
            self.in_td = True
            self.td_count += 1
            # Die 6. Spalte ist "Letztes Ergebnis"
            if self.td_count == 6:
                self.in_status_cell = True
                
    def handle_endtag(self, tag):
        if tag == 'table' and self.in_table:
            self.in_table = False
            self.in_archiving_stats = False
            
        if tag == 'td':
            self.in_td = False
            self.in_status_cell = False
            
        if tag == 'tr' and self.in_table and len(self.current_row) > 0:
            # Nur Zeilen mit tatsächlichen Daten berücksichtigen
            if len(self.current_row) >= 6:
                # Prüfe ob es eine Datenzeile ist (nicht der Header)
                last_col = self.current_row[-1].strip()
                if last_col in ['Erfolgreich', 'Succeeded', 'Warnung', 'Warning', 'Fehlgeschlagen', 'Failed']:
                    self.statuses.append(last_col)
                    
    def handle_data(self, data):
        # Prüfe ob wir den Header "Archivierungsstatistiken" gefunden haben
        if 'Archivierungsstatistiken' in data:
            self.in_archiving_stats = True
            self.found_header = True
            
        if self.in_td and self.in_table:
            self.current_row.append(data.strip())

def extract_mailstore_status(html_content):
    """
    Extrahiert den Status aus dem MailStore HTML Report
    Gibt den schlechtesten Status zurück (error > warning > success)
    """
    parser = MailStoreHTMLParser()
    parser.feed(html_content)
    
    # Status-Mapping
    status_map = {
        'Erfolgreich': 'success',
        'Succeeded': 'success',
        'Warnung': 'warning',
        'Warning': 'warning',
        'Fehlgeschlagen': 'error',
        'Failed': 'error'
    }
    
    # Konvertiere alle gefundenen Status
    found_statuses = []
    for status in parser.statuses:
        if status in status_map:
            found_statuses.append(status_map[status])
    
    if not found_statuses:
        print("  Keine Archivierungsstatistiken gefunden")
        return None
        
    # Bestimme den schlechtesten Status
    if 'error' in found_statuses:
        return 'error'
    elif 'warning' in found_statuses:
        return 'warning'
    elif 'success' in found_statuses:
        return 'success'
    else:
        return None

def extract_date_from_mail(html_content):
    """
    Extrahiert das Datum aus dem MailStore Report
    Sucht nach dem Datum in den Archivierungsstatistiken
    """
    # Suche nach Datum im Format (DD.MM.YYYY)
    date_pattern = r'Archivierungsstatistiken.*?\((\d{2}\.\d{2}\.\d{4})\)'
    match = re.search(date_pattern, html_content, re.DOTALL)
    
    if match:
        date_str = match.group(1)
        try:
            return datetime.strptime(date_str, '%d.%m.%Y')
        except ValueError:
            return None
    
    # Alternative: Suche nach "Letzte Ausführung" Datum
    exec_pattern = r'(\d{2}\.\d{2}\.\d{4})\s+\d{2}:\d{2}:\d{2}'
    matches = re.findall(exec_pattern, html_content)
    if matches:
        try:
            # Nimm das erste gefundene Datum
            return datetime.strptime(matches[0], '%d.%m.%Y')
        except ValueError:
            pass
    
    return None

def process_mailstore_mails(connection):
    print("Starting MailStore backup mail processing...")
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
                # Check if backup job is MailStore type
                cursor.execute("""
                    SELECT backup_type 
                    FROM backup_jobs 
                    WHERE id = %s
                """, (mail['backup_job_id'],))
                job = cursor.fetchone()

                if not job or 'MailStore' not in job['backup_type']:
                    continue

                # Process mail content
                print(f"Processing mail ID {mail['id']}")
                
                content = mail['content']
                subject = mail['subject']
                
                # Extract status from HTML content
                status = extract_mailstore_status(content)
                
                # Extract date
                backup_date = extract_date_from_mail(content)
                
                if status:
                    print(f"  Status: {status}")
                    print(f"  Date: {backup_date}")
                    
                    # Update backup_results
                    cursor.execute("""
                        UPDATE backup_results 
                        SET status = %s,
                            date = %s,
                            time = %s
                        WHERE mail_id = %s
                    """, (
                        status,
                        backup_date.date() if backup_date else None,
                        backup_date.time() if backup_date else None,
                        mail['id']
                    ))

                    # Mark mail as processed
                    cursor.execute("""
                        UPDATE mails 
                        SET result_processed = TRUE 
                        WHERE id = %s
                    """, (mail['id'],))

                    connection.commit()
                    print(f"Mail ID {mail['id']} processed successfully\n")
                else:
                    print(f"  Warnung: Kein Status gefunden für Mail ID {mail['id']}")

    except Exception as e:
        print(f"Error processing mails: {e}")
        connection.rollback()

def main():
    connection = connect_to_database()
    try:
        process_mailstore_mails(connection)
    finally:
        connection.close()

if __name__ == "__main__":
    main()