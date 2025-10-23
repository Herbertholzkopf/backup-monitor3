# script-runner anpassen
# Verbesserte Version mit Unterstützung für deutsche und englische Mails

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
    Unterstützt sowohl deutsche als auch englische Mails
    """
    def __init__(self):
        super().__init__()
        self.in_archiving_stats = False
        self.in_jobs_section = False
        self.in_table = False
        self.in_td = False
        self.in_status_cell = False
        self.current_row = []
        self.statuses = []
        self.td_count = 0
        self.found_header = False
        self.table_type = None  # 'archiving' oder 'jobs'
        
    def handle_starttag(self, tag, attrs):
        # Prüfe ob wir im Archivierungsstatistiken-Bereich sind
        if tag == 'div':
            for attr in attrs:
                if attr[0] == 'style' and 'color: #e65f1e' in attr[1]:
                    # Wir sind in einem orange Header
                    self.in_archiving_stats = False  # Reset
                    self.in_jobs_section = False
                    
        if tag == 'table' and (self.found_header or self.in_jobs_section):
            self.in_table = True
            self.found_header = False
            
        if tag == 'tr' and self.in_table:
            self.current_row = []
            self.td_count = 0
            
        if tag == 'td' and self.in_table:
            self.in_td = True
            self.td_count += 1
            # Die 6. Spalte ist "Letztes Ergebnis" / "Last Result"
            if self.td_count == 6:
                self.in_status_cell = True
                
    def handle_endtag(self, tag):
        if tag == 'table' and self.in_table:
            self.in_table = False
            self.in_archiving_stats = False
            self.in_jobs_section = False
            
        if tag == 'td':
            self.in_td = False
            self.in_status_cell = False
            
        if tag == 'tr' and self.in_table and len(self.current_row) > 0:
            # Nur Zeilen mit tatsächlichen Daten berücksichtigen
            if len(self.current_row) >= 6:
                # Prüfe ob es eine Datenzeile ist (nicht der Header)
                last_col = self.current_row[-1].strip()
                # Unterstütze sowohl deutsche als auch englische Status
                if last_col in ['Erfolgreich', 'Succeeded', 'Warnung', 'Warning', 'Fehlgeschlagen', 'Failed']:
                    self.statuses.append(last_col)
                    
    def handle_data(self, data):
        # Prüfe ob wir den Header "Archivierungsstatistiken" oder "Archiving Statistics" gefunden haben
        if 'Archivierungsstatistiken' in data or 'Archiving Statistics' in data:
            self.in_archiving_stats = True
            self.found_header = True
            self.table_type = 'archiving'
            
        # Prüfe auch auf Jobs-Sektion (für beide Sprachen)
        if ('Jobs' in data and '(' in data and ')' in data):
            self.in_jobs_section = True
            self.table_type = 'jobs'
            
        if self.in_td and self.in_table:
            self.current_row.append(data.strip())

def extract_mailstore_status(html_content):
    """
    Extrahiert den Status aus dem MailStore HTML Report
    Gibt den schlechtesten Status zurück (error > warning > success)
    Unterstützt sowohl deutsche als auch englische Status-Werte
    """
    parser = MailStoreHTMLParser()
    parser.feed(html_content)
    
    # Status-Mapping für beide Sprachen
    status_map = {
        # Deutsche Status
        'Erfolgreich': 'success',
        'Warnung': 'warning',
        'Fehlgeschlagen': 'error',
        # Englische Status
        'Succeeded': 'success',
        'Warning': 'warning',
        'Failed': 'error'
    }
    
    # Konvertiere alle gefundenen Status
    found_statuses = []
    for status in parser.statuses:
        if status in status_map:
            found_statuses.append(status_map[status])
    
    if not found_statuses:
        print("  Keine Archivierungsstatistiken gefunden / No archiving statistics found")
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
    Unterstützt sowohl deutsche als auch englische Formate
    """
    # Suche nach Datum im Format (DD.MM.YYYY) - für deutsche und englische Mails
    # Deutsche: "Archivierungsstatistiken (DD.MM.YYYY)"
    # Englische: "Archiving Statistics (DD.MM.YYYY)" oder "Jobs (DD.MM.YYYY)"
    patterns = [
        r'Archivierungsstatistiken.*?\((\d{2}\.\d{2}\.\d{4})\)',
        r'Archiving Statistics.*?\((\d{2}\.\d{2}\.\d{4})\)',
        r'Jobs.*?\((\d{2}\.\d{2}\.\d{4})\)'
    ]
    
    for pattern in patterns:
        match = re.search(pattern, html_content, re.DOTALL)
        if match:
            date_str = match.group(1)
            try:
                return datetime.strptime(date_str, '%d.%m.%Y')
            except ValueError:
                continue
    
    # Alternative: Suche nach "Letzte Ausführung" oder "Last Execution" Datum
    exec_pattern = r'(\d{2}\.\d{2}\.\d{4})\s+\d{2}:\d{2}:\d{2}'
    matches = re.findall(exec_pattern, html_content)
    if matches:
        try:
            # Nimm das erste gefundene Datum
            return datetime.strptime(matches[0], '%d.%m.%Y')
        except ValueError:
            pass
    
    return None

def detect_language(html_content):
    """
    Erkennt die Sprache des Reports
    Gibt 'de' für Deutsch oder 'en' für Englisch zurück
    """
    # Suche nach sprachspezifischen Markern
    if 'Archivierungsstatistiken' in html_content or 'Letztes Ergebnis' in html_content:
        return 'de'
    elif 'Archiving Statistics' in html_content or 'Last Result' in html_content:
        return 'en'
    else:
        # Default auf Englisch, da das die internationale Version ist
        return 'en'

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
                
                # Detect language
                language = detect_language(content)
                print(f"  Sprache/Language: {'Deutsch' if language == 'de' else 'English'}")
                
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
                    print(f"  Warning: No status found for Mail ID {mail['id']}")

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