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

def extract_status_counts(content):
    """
    Extract the status counts (Succeeded, Partially Succeeded, Failed) from the content.
    Returns a dict with 'succeeded', 'partial', 'failed' counts.
    """
    counts = {
        'succeeded': 0,
        'partial': 0,
        'failed': 0
    }
    
    # Pattern for HTML content - look for the status cells
    # The structure is: <p class="status--number ...">NUMBER</p> followed by <p ...>Label</p>
    
    # Pattern for Succeeded
    succeeded_pattern = r'status--cell--success.*?<p[^>]*class="status--number[^"]*"[^>]*>\s*(\d+)\s*</p>'
    succeeded_match = re.search(succeeded_pattern, content, re.DOTALL | re.IGNORECASE)
    if succeeded_match:
        counts['succeeded'] = int(succeeded_match.group(1))
    
    # Pattern for Partially Succeeded (warning)
    partial_pattern = r'status--cell--warning.*?<p[^>]*class="status--number[^"]*"[^>]*>\s*(\d+)\s*</p>'
    partial_match = re.search(partial_pattern, content, re.DOTALL | re.IGNORECASE)
    if partial_match:
        counts['partial'] = int(partial_match.group(1))
    
    # Pattern for Failed
    failed_pattern = r'status--cell--failed.*?<p[^>]*class="status--number[^"]*"[^>]*>\s*(\d+)\s*</p>'
    failed_match = re.search(failed_pattern, content, re.DOTALL | re.IGNORECASE)
    if failed_match:
        counts['failed'] = int(failed_match.group(1))
    
    # Fallback: Try plain text patterns if HTML patterns don't match
    if counts['succeeded'] == 0 and counts['partial'] == 0 and counts['failed'] == 0:
        # Plain text fallback
        plain_succeeded = re.search(r'(\d+)\s*\n\s*Succeeded', content)
        plain_partial = re.search(r'(\d+)\s*\n\s*Partially Succeeded', content)
        plain_failed = re.search(r'(\d+)\s*\n\s*Failed', content)
        
        if plain_succeeded:
            counts['succeeded'] = int(plain_succeeded.group(1))
        if plain_partial:
            counts['partial'] = int(plain_partial.group(1))
        if plain_failed:
            counts['failed'] = int(plain_failed.group(1))
    
    return counts

def determine_status(counts):
    """
    Determine the overall backup status based on the counts.
    Returns 'success', 'warning', or 'error'.
    
    Logic: Use the "worst" status - if any failed -> error, if any partial -> warning
    """
    if counts['failed'] > 0:
        return 'error'
    elif counts['partial'] > 0:
        return 'warning'
    elif counts['succeeded'] > 0:
        return 'success'
    else:
        # If we can't determine any counts, default to error
        return 'error'

def extract_report_date(content):
    """
    Extract the report date from the content.
    Supports two formats:
    - Mail1 format (plain text): "Daily Report17 December 2025" or "Daily Report17\nDecember 2025"
    - Mail2 format (HTML only): "<b>Daily Report</b>...18 December 2025" (date in separate cell)
    Returns a datetime object or None.
    """
    day = None
    month_str = None
    year = None
    
    # Month mapping
    months = {
        'january': 1, 'february': 2, 'march': 3, 'april': 4,
        'may': 5, 'june': 6, 'july': 7, 'august': 8,
        'september': 9, 'october': 10, 'november': 11, 'december': 12
    }
    
    # Try HTML format first (works for both mail formats)
    # Pattern: <b>Daily Report</b> followed by date somewhere after
    html_pattern = r'<b>Daily Report</b>.*?(\d{1,2})\s+(\w+)\s+(\d{4})'
    html_match = re.search(html_pattern, content, re.DOTALL | re.IGNORECASE)
    
    if html_match:
        day = int(html_match.group(1))
        month_str = html_match.group(2)
        year = int(html_match.group(3))
    else:
        # Fallback: Try plain text format (Daily Report + date combined)
        plain_pattern = r'Daily\s*Report\s*(\d{1,2})\s*(\w+)\s*(\d{4})'
        plain_match = re.search(plain_pattern, content, re.IGNORECASE)
        
        if plain_match:
            day = int(plain_match.group(1))
            month_str = plain_match.group(2)
            year = int(plain_match.group(3))
    
    # Convert to datetime if we found all parts
    if day and month_str and year:
        month = months.get(month_str.lower())
        if month:
            try:
                return datetime(year, month, day)
            except ValueError:
                pass
    
    return None

def extract_total_tasks(content):
    """
    Extract the total number of tasks from the content.
    Returns an integer or None.
    """
    # Pattern for "Total Tasks4" or "Total Tasks 4"
    pattern = r'Total\s*Tasks\s*(\d+)'
    match = re.search(pattern, content, re.IGNORECASE)
    
    if match:
        return int(match.group(1))
    
    return None

def extract_account_info(content):
    """
    Extract account information from the content.
    Returns a dict with 'account' and 'mail' keys.
    """
    info = {
        'account': None,
        'mail': None
    }
    
    # Pattern for Account (appears as "Accountpronet Solution" or similar)
    account_pattern = r'Account\s*([^\n<]+?)(?:\s*Mail|$)'
    account_match = re.search(account_pattern, content)
    if account_match:
        info['account'] = account_match.group(1).strip()
    
    # Pattern for Mail address
    mail_pattern = r'Mail\s*([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})'
    mail_match = re.search(mail_pattern, content)
    if mail_match:
        info['mail'] = mail_match.group(1)
    
    return info

def process_synaxon_c2c_mails(connection):
    print("Starting Synaxon Cloud-to-Cloud backup mail processing...")
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
                # Check if backup job is Synaxon Cloud-to-Cloud Backup type
                cursor.execute("""
                    SELECT backup_type 
                    FROM backup_jobs 
                    WHERE id = %s
                """, (mail['backup_job_id'],))
                job = cursor.fetchone()

                if not job or job['backup_type'] != 'Synaxon Cloud-to-Cloud Backup':
                    continue

                # Process mail content
                print(f"Processing mail ID {mail['id']}")
                
                content = mail['content']
                subject = mail['subject']
                
                # Extract all relevant information
                status_counts = extract_status_counts(content)
                status = determine_status(status_counts)
                report_date = extract_report_date(content)
                total_tasks = extract_total_tasks(content)
                account_info = extract_account_info(content)
                
                # Extract time from mail's date field (send date of the mail)
                mail_date = mail.get('date')
                mail_time = None
                if mail_date:
                    if isinstance(mail_date, datetime):
                        mail_time = mail_date.time()
                    elif hasattr(mail_date, 'time'):
                        mail_time = mail_date.time()
                
                # Create note with status counts
                note = f"{status_counts['succeeded']} Succeeded\n{status_counts['partial']} Partially Succeeded\n{status_counts['failed']} Failed"
                
                print(f"  Status: {status}")
                print(f"  Succeeded: {status_counts['succeeded']}")
                print(f"  Partially Succeeded: {status_counts['partial']}")
                print(f"  Failed: {status_counts['failed']}")
                print(f"  Report Date: {report_date}")
                print(f"  Time (from mail): {mail_time}")
                print(f"  Total Tasks: {total_tasks}")
                if account_info['account']:
                    print(f"  Account: {account_info['account']}")

                # Update backup_results
                # Note: Synaxon C2C backups don't provide duration or size information
                # Time is taken from the mail's send date
                cursor.execute("""
                    UPDATE backup_results 
                    SET status = %s,
                        date = %s,
                        time = %s,
                        duration_minutes = NULL,
                        size_mb = NULL,
                        note = %s
                    WHERE mail_id = %s
                """, (
                    status,
                    report_date.date() if report_date else None,
                    mail_time,
                    note,
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
        process_synaxon_c2c_mails(connection)
    finally:
        connection.close()

if __name__ == "__main__":
    main()