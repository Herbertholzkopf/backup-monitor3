#!/usr/bin/env python3
import os
import sys
import pymysql
import smtplib
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from datetime import datetime
import logging

# Konfigurationsdateien einbinden
sys.path.append(os.path.join(os.path.dirname(__file__), '../config'))
import database
import mail

# Konfiguration des Loggings
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('daily_status_mail.log'),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger('backup_status_report')

def connect_to_database():
    """Stellt eine Verbindung zur Datenbank her"""
    try:
        connection = pymysql.connect(
            host=database.DB_HOST,
            user=database.DB_USER,
            password=database.DB_PASSWORD,
            database=database.DB_NAME,
            charset='utf8mb4',
            cursorclass=pymysql.cursors.DictCursor
        )
        logger.info("Datenbankverbindung erfolgreich hergestellt")
        return connection
    except Exception as e:
        logger.error(f"Fehler beim Verbinden mit der Datenbank: {e}")
        sys.exit(1)

def get_backup_status_count(conn):
    """Zählt die Anzahl der Backup-Jobs nach Status"""
    cursor = conn.cursor()
    query = """
    SELECT 
        SUM(CASE WHEN current_status = 'success' THEN 1 ELSE 0 END) AS success_count,
        SUM(CASE WHEN current_status = 'warning' THEN 1 ELSE 0 END) AS warning_count,
        SUM(CASE WHEN current_status = 'error' THEN 1 ELSE 0 END) AS error_count,
        SUM(CASE WHEN current_status = 'none' THEN 1 ELSE 0 END) AS none_count
    FROM status_duration
    """
    cursor.execute(query)
    result = cursor.fetchone()
    cursor.close()
    
    return {
        'success': result['success_count'] or 0,
        'warning': result['warning_count'] or 0,
        'error': result['error_count'] or 0,
        'none': result['none_count'] or 0
    }

def get_problematic_backups(conn):
    """Holt alle problematischen Backup-Jobs (alles außer 'success')"""
    cursor = conn.cursor()
    query = """
    SELECT 
        c.name AS customer_name,
        bj.name AS job_name,
        sd.current_status,
        sd.days_in_status,
        sd.last_backup_date
    FROM status_duration sd
    JOIN backup_jobs bj ON sd.backup_job_id = bj.id
    JOIN customers c ON bj.customer_id = c.id
    WHERE sd.current_status != 'success'
    ORDER BY 
        CASE 
            WHEN sd.current_status = 'none' THEN 1
            WHEN sd.current_status = 'error' THEN 2
            WHEN sd.current_status = 'warning' THEN 3
            ELSE 4
        END,
        sd.days_in_status DESC
    """
    cursor.execute(query)
    results = cursor.fetchall()
    cursor.close()
    
    return results

def generate_status_html(status):
    """Generiert HTML für den Status mit passender Farbe und Text"""
    status_map = {
        'warning': {
            'color': 'bg-yellow-100 text-yellow-800 border-yellow-200',
            'text': 'Warnung'
        },
        'error': {
            'color': 'bg-red-100 text-red-800 border-red-200',
            'text': 'Fehler'
        },
        'none': {
            'color': 'bg-gray-100 text-gray-800 border-gray-200',
            'text': 'Kein Status'
        }
    }
    
    style = status_map.get(status, {'color': '', 'text': status})
    
    return f'<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {style["color"]}">{style["text"]}</span>'

def format_date(date_str):
    """Formatiert ein Datum in ein lesbares Format"""
    if not date_str:
        return "Noch nie"
    
    try:
        date_obj = datetime.strptime(str(date_str), '%Y-%m-%d')
        return date_obj.strftime('%d.%m.%Y')
    except:
        return str(date_str)

def generate_html_report(status_counts, problematic_backups):
    """Erstellt einen HTML-Bericht"""
    today = datetime.now().strftime('%d.%m.%Y')
    
    html = f"""
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Backup Status Bericht</title>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
            
            body {{
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                color: #374151;
                line-height: 1.5;
                padding: 2rem;
                max-width: 800px;
                margin: 0 auto;
                background-color: #f9fafb;
            }}
            
            .container {{
                background-color: #ffffff;
                border-radius: 0.5rem;
                box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
                padding: 2rem;
            }}
            
            h1 {{
                font-size: 1.5rem;
                font-weight: 600;
                color: #111827;
                margin-bottom: 1.5rem;
            }}
            
            .summary {{
                display: flex;
                justify-content: space-between;
                margin-bottom: 2rem;
                flex-wrap: wrap;
                gap: 1rem;
            }}
            
            .status-card {{
                background-color: #ffffff;
                border-radius: 0.375rem;
                box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
                padding: 1rem;
                min-width: 150px;
                border-left: 4px solid #e5e7eb;
                flex-grow: 1;
            }}
            
            .status-card.success {{
                border-color: #10b981;
            }}
            
            .status-card.warning {{
                border-color: #f59e0b;
            }}
            
            .status-card.error {{
                border-color: #ef4444;
            }}
            
            .status-card.none {{
                border-color: #6b7280;
            }}
            
            .status-count {{
                font-size: 1.875rem;
                font-weight: 700;
                color: #111827;
                margin-bottom: 0.25rem;
            }}
            
            .status-label {{
                font-size: 0.875rem;
                color: #6b7280;
            }}
            
            table {{
                width: 100%;
                border-collapse: collapse;
            }}
            
            thead {{
                background-color: #f3f4f6;
            }}
            
            th {{
                padding: 0.75rem 1rem;
                text-align: left;
                font-size: 0.875rem;
                font-weight: 500;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: #6b7280;
                border-bottom: 1px solid #e5e7eb;
            }}
            
            td {{
                padding: 1rem;
                vertical-align: middle;
                border-bottom: 1px solid #e5e7eb;
            }}
            
            tr:last-child td {{
                border-bottom: none;
            }}
            
            .customer-name {{
                font-weight: 600;
                color: #111827;
            }}
            
            .job-name {{
                color: #4b5563;
            }}
            
            .badge {{
                display: inline-flex;
                align-items: center;
                padding: 0.25rem 0.75rem;
                border-radius: 9999px;
                font-size: 0.75rem;
                font-weight: 500;
            }}
            
            .badge-warning {{
                background-color: #fef3c7;
                color: #92400e;
                border: 1px solid #fde68a;
            }}
            
            .badge-error {{
                background-color: #fee2e2;
                color: #b91c1c;
                border: 1px solid #fecaca;
            }}
            
            .badge-none {{
                background-color: #f3f4f6;
                color: #4b5563;
                border: 1px solid #e5e7eb;
            }}
            
            .days {{
                font-weight: 500;
                text-align: center;
            }}
            
            .footer {{
                margin-top: 2rem;
                font-size: 0.875rem;
                color: #6b7280;
                text-align: center;
            }}
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Backup Status Bericht vom {today}</h1>
            
            <div class="summary">
                <div class="status-card success">
                    <div class="status-count">{status_counts['success']}</div>
                    <div class="status-label">Erfolgreich</div>
                </div>
                <div class="status-card warning">
                    <div class="status-count">{status_counts['warning']}</div>
                    <div class="status-label">Warnung</div>
                </div>
                <div class="status-card error">
                    <div class="status-count">{status_counts['error']}</div>
                    <div class="status-label">Fehler</div>
                </div>
                <div class="status-card none">
                    <div class="status-count">{status_counts['none']}</div>
                    <div class="status-label">Kein Status</div>
                </div>
            </div>
    """
    
    if problematic_backups:
        html += """
            <table>
                <thead>
                    <tr>
                        <th>Kunde</th>
                        <th>Backup-Job</th>
                        <th>Status</th>
                        <th>Dauer</th>
                        <th>Letztes Backup</th>
                    </tr>
                </thead>
                <tbody>
        """
        
        for backup in problematic_backups:
            status_html = ''
            if backup['current_status'] == 'warning':
                status_html = '<span class="badge badge-warning">Warnung</span>'
            elif backup['current_status'] == 'error':
                status_html = '<span class="badge badge-error">Fehler</span>'
            elif backup['current_status'] == 'none':
                status_html = '<span class="badge badge-none">Kein Status</span>'
            
            last_backup = format_date(backup['last_backup_date'])
            
            html += f"""
                    <tr>
                        <td class="customer-name">{backup['customer_name']}</td>
                        <td class="job-name">{backup['job_name']}</td>
                        <td>{status_html}</td>
                        <td class="days">{backup['days_in_status']} Tage</td>
                        <td>{last_backup}</td>
                    </tr>
            """
        
        html += """
                </tbody>
            </table>
        """
    else:
        html += """
            <div style="text-align: center; padding: 2rem; color: #4b5563;">
                Keine problematischen Backup-Jobs gefunden.
            </div>
        """
    
    html += """
            <div class="footer">
                Dieser Bericht wurde automatisch generiert. Bei Fragen wenden Sie sich bitte an Ihren Administrator.
            </div>
        </div>
    </body>
    </html>
    """
    
    return html

def send_email(subject, html_content, recipients):
    """Sendet eine HTML-formatierte E-Mail"""
    try:
        msg = MIMEMultipart('alternative')
        msg['Subject'] = subject
        msg['From'] = mail.MAIL_USER
        msg['To'] = ', '.join(recipients)
        
        # HTML-Teil
        html_part = MIMEText(html_content, 'html')
        msg.attach(html_part)
        
        # Nur SMTP_SSL auf Port 465 für IONOS verwenden
        try:
            logger.info(f"Verbinde zu {mail.MAIL_SERVER_SMTP} über SSL auf Port 465...")
            server = smtplib.SMTP_SSL(mail.MAIL_SERVER_SMTP, 465, timeout=30)
            server.login(mail.MAIL_USER, mail.MAIL_PASSWORD)
            
            # E-Mail senden
            server.sendmail(mail.MAIL_USER, recipients, msg.as_string())
            server.quit()
            
            logger.info(f"E-Mail erfolgreich über SSL gesendet an: {', '.join(recipients)}")
            return True
        except Exception as ssl_error:
            logger.error(f"Fehler beim Senden über SMTP_SSL (Port 465): {ssl_error}")
            return False
    except Exception as e:
        logger.error(f"Allgemeiner Fehler beim E-Mail-Versand: {e}")
        return False

def main():
    """Hauptfunktion"""
    logger.info("Starte Backup Status E-Mail Bericht")
    
    # Verbindung zur Datenbank herstellen
    conn = connect_to_database()
    
    try:
        # Status-Zusammenfassung abrufen
        status_counts = get_backup_status_count(conn)
        logger.info(f"Status-Zusammenfassung: Erfolg={status_counts['success']}, Warnung={status_counts['warning']}, Fehler={status_counts['error']}, Kein Status={status_counts['none']}")
        
        # Problematische Backups abrufen
        problematic_backups = get_problematic_backups(conn)
        logger.info(f"Anzahl problematischer Backups: {len(problematic_backups)}")
        
        # HTML-Bericht generieren
        html_report = generate_html_report(status_counts, problematic_backups)
        
        # Nur E-Mail senden, wenn es problematische Backups gibt
        if problematic_backups:
            # E-Mail versenden
            recipients = ['technik@phd-it-systeme.de']  # Hier Empfänger eintragen
            subject = f"Backup Status Bericht: {status_counts['warning']} Warnungen, {status_counts['error']} Fehler, {status_counts['none']} ohne Status"
            
            send_email(subject, html_report, recipients)
        else:
            logger.info("Keine problematischen Backups gefunden, keine E-Mail versendet.")
        
        logger.info("Backup Status E-Mail Bericht erfolgreich abgeschlossen")
    except Exception as e:
        logger.error(f"Fehler im Backup Status E-Mail Bericht: {e}")
    finally:
        conn.close()
        logger.info("Datenbankverbindung geschlossen")

if __name__ == "__main__":
    main()