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

def is_html_content(content):
    """
    Determine if the content is HTML based on the presence of HTML tags
    """
    return content.strip().startswith('<html>') or '<html>' in content.lower()

def extract_status(content, subject):
    """
    Extract backup status from the content or subject
    Returns 'success' or 'error'
    """
    # First check subject
    if subject and 'erfolgreich' in subject.lower():
        return 'success'
    elif subject and ('fehlgeschlagen' in subject.lower() or 'fehler' in subject.lower()):
        return 'error'
    
    # Then check content
    if 'erfolgreich abgeschlossen' in content:
        return 'success'
    elif 'fehlgeschlagen' in content or 'fehler' in content:
        return 'error'
    
    # Default to error if we can't determine status
    return 'error'

def extract_times(content):
    """
    Extract start and end times from the content
    Returns a tuple of (start_date, end_date)
    """
    start_date = None
    end_date = None
    
    # Extract start and end times
    if is_html_content(content):
        # For HTML content using regex
        start_pattern = r'<td[^>]*>Backup starten:[^<]*</td>\s*<td[^>]*>([^<]+)'
        end_pattern = r'<td[^>]*>Backup abgeschlossen:[^<]*</td>\s*<td[^>]*>([^<(]+)'
        
        start_match = re.search(start_pattern, content)
        if start_match:
            start_date = parse_date_time(start_match.group(1).strip())
        
        end_match = re.search(end_pattern, content)
        if end_match:
            end_date = parse_date_time(end_match.group(1).strip())
        
        # Alternative pattern for some HTML formats
        if not start_date:
            alt_start_pattern = r'Backup starten:\s*(\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}:\d{2})'
            alt_start_match = re.search(alt_start_pattern, content)
            if alt_start_match:
                start_date = parse_date_time(alt_start_match.group(1))
        
        if not end_date:
            alt_end_pattern = r'Backup abgeschlossen:\s*(\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}:\d{2})'
            alt_end_match = re.search(alt_end_pattern, content)
            if alt_end_match:
                end_date = parse_date_time(alt_end_match.group(1))
    else:
        # For plain text content
        start_pattern = r'Backup starten:\s+(\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}:\d{2})'
        end_pattern = r'Backup abgeschlossen:\s+(\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}:\d{2})'
        
        start_match = re.search(start_pattern, content)
        if start_match:
            start_date = parse_date_time(start_match.group(1))
        
        end_match = re.search(end_pattern, content)
        if end_match:
            end_date = parse_date_time(end_match.group(1))
    
    return (start_date, end_date)

def parse_date_time(date_str):
    """
    Parse date strings in the format DD.MM.YYYY HH:MM:SS
    """
    try:
        return datetime.strptime(date_str, '%d.%m.%Y %H:%M:%S')
    except ValueError:
        try:
            # Try alternative format if first fails
            return datetime.strptime(date_str, '%d.%m.%Y %H:%M')
        except ValueError:
            return None

def extract_duration(content):
    """
    Extract duration in minutes from the content
    """
    # Regular expression to match duration format
    if is_html_content(content):
        duration_pattern = r'(?:<td[^>]*>Backup abgeschlossen:[^<]*</td>\s*<td[^>]*>[^(]*\(|\()\s*(\d+)\s*(?:Stunde|Stunden),\s*(\d+)\s*(?:Minute|Minuten),\s*(\d+)\s*(?:Sekunde|Sekunden)'
    else:
        duration_pattern = r'\((\d+)\s*(?:Stunde|Stunden),\s*(\d+)\s*(?:Minute|Minuten),\s*(\d+)\s*(?:Sekunde|Sekunden)\)'
    
    match = re.search(duration_pattern, content)
    if match:
        hours = int(match.group(1))
        minutes = int(match.group(2))
        seconds = int(match.group(3))
        
        # Calculate total minutes
        total_minutes = hours * 60 + minutes
        
        # Round up if seconds >= 30
        if seconds >= 30:
            total_minutes += 1
        
        return total_minutes
    
    return None

def extract_size(content):
    """
    Extract the size of copied data in MB
    """
    # Try to find the size information
    if is_html_content(content):
        # Pattern for specific HTML table format where label and value are in separate TD tags
        size_pattern = r'<td[^>]*>\s*Größe der kopierten Daten:\s*</td>\s*<td[^>]*>\s*([\d,.]+)\s*([KMGT]B)\s*</td>'
        match = re.search(size_pattern, content)
        if match:
            return parse_size(f"{match.group(1)} {match.group(2)}")
        
        # Alternative pattern for row-based detection
        alt_pattern = r'Größe der kopierten Daten:.*?</td>.*?<td[^>]*>\s*([\d,.]+)\s*([KMGT]B)'
        alt_match = re.search(alt_pattern, content, re.DOTALL)
        if alt_match:
            return parse_size(f"{alt_match.group(1)} {alt_match.group(2)}")
        
        # Fallback pattern for simple text
        fallback_pattern = r'Größe der kopierten Daten:\s+([\d.,]+)\s*([KMGT]B)'
        fallback_match = re.search(fallback_pattern, content)
        if fallback_match:
            return parse_size(f"{fallback_match.group(1)} {fallback_match.group(2)}")
    else:
        # For plain text content
        size_pattern = r'Größe der kopierten Daten:\s+([\d.,]+)\s*([KMGT]B)'
        match = re.search(size_pattern, content)
        if match:
            return parse_size(f"{match.group(1)} {match.group(2)}")
    
    return None

def parse_size(size_str):
    """
    Parse size string and convert to MB
    """
    # Replace comma with dot for decimal point (German format)
    size_str = size_str.replace(',', '.')
    
    # Extract the numeric value and unit
    match = re.search(r'([\d.]+)\s*([KMGT]B)', size_str)
    if match:
        value = float(match.group(1))
        unit = match.group(2)
        
        # Convert to MB
        if unit == 'KB':
            return value / 1024
        elif unit == 'MB':
            return value
        elif unit == 'GB':
            return value * 1024
        elif unit == 'TB':
            return value * 1024 * 1024
    
    return None

def process_iperius_mails(connection):
    print("Starting Iperius backup mail processing...")
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
                # Check if backup job is Iperius Backup type
                cursor.execute("""
                    SELECT backup_type 
                    FROM backup_jobs 
                    WHERE id = %s
                """, (mail['backup_job_id'],))
                job = cursor.fetchone()

                if not job or 'Iperius' not in job['backup_type']:
                    continue

                # Process mail content
                print(f"Processing mail ID {mail['id']}")
                
                content = mail['content']
                subject = mail['subject']
                
                status = extract_status(content, subject)
                start_date, end_date = extract_times(content)
                duration = extract_duration(content)
                size = extract_size(content)
                
                print(f"  Status: {status}")
                print(f"  Start: {start_date}")
                print(f"  End: {end_date}")
                print(f"  Duration: {duration} minutes")
                print(f"  Size: {size} MB")

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
                    end_date.date() if end_date else None,
                    end_date.time() if end_date else None,
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
                print(f"Mail ID {mail['id']} processed successfully\n")

    except Exception as e:
        print(f"Error processing mails: {e}")
        connection.rollback()

def main():
    connection = connect_to_database()
    try:
        process_iperius_mails(connection)
    finally:
        connection.close()

if __name__ == "__main__":
    main()