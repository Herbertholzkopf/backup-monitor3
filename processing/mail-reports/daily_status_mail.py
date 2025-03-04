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
    """Erstellt einen HTML-Bericht im Tailwind CSS Design"""
    today = datetime.now().strftime('%d.%m.%Y')
    
    html = f"""
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Backup Status Bericht</title>
        <style>
            * {{
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }}
            
            body {{
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                color: #1f2937;
                line-height: 1.5;
                background-color: #f9fafb;
            }}
            
            .bg-white {{
                background-color: #ffffff;
            }}
            
            .bg-gray-50 {{
                background-color: #f9fafb;
            }}
            
            .bg-gray-100 {{
                background-color: #f3f4f6;
            }}
            
            .text-gray-500 {{
                color: #6b7280;
            }}
            
            .text-gray-600 {{
                color: #4b5563;
            }}
            
            .text-gray-700 {{
                color: #374151;
            }}
            
            .text-gray-900 {{
                color: #111827;
            }}
            
            .font-medium {{
                font-weight: 500;
            }}
            
            .font-semibold {{
                font-weight: 600;
            }}
            
            .font-bold {{
                font-weight: 700;
            }}
            
            .text-sm {{
                font-size: 0.875rem;
            }}
            
            .text-lg {{
                font-size: 1.125rem;
            }}
            
            .text-xl {{
                font-size: 1.25rem;
            }}
            
            .text-2xl {{
                font-size: 1.5rem;
            }}
            
            .text-3xl {{
                font-size: 1.875rem;
            }}
            
            .text-4xl {{
                font-size: 2.25rem;
            }}
            
            .rounded {{
                border-radius: 0.25rem;
            }}
            
            .rounded-md {{
                border-radius: 0.375rem;
            }}
            
            .rounded-lg {{
                border-radius: 0.5rem;
            }}
            
            .rounded-xl {{
                border-radius: 0.75rem;
            }}
            
            .rounded-full {{
                border-radius: 9999px;
            }}
            
            .shadow-sm {{
                box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            }}
            
            .shadow {{
                box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            }}
            
            .shadow-md {{
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            }}
            
            .p-2 {{
                padding: 0.5rem;
            }}
            
            .p-4 {{
                padding: 1rem;
            }}
            
            .p-6 {{
                padding: 1.5rem;
            }}
            
            .p-8 {{
                padding: 2rem;
            }}
            
            .px-2 {{
                padding-left: 0.5rem;
                padding-right: 0.5rem;
            }}
            
            .px-3 {{
                padding-left: 0.75rem;
                padding-right: 0.75rem;
            }}
            
            .px-4 {{
                padding-left: 1rem;
                padding-right: 1rem;
            }}
            
            .py-1 {{
                padding-top: 0.25rem;
                padding-bottom: 0.25rem;
            }}
            
            .py-2 {{
                padding-top: 0.5rem;
                padding-bottom: 0.5rem;
            }}
            
            .py-3 {{
                padding-top: 0.75rem;
                padding-bottom: 0.75rem;
            }}
            
            .py-4 {{
                padding-top: 1rem;
                padding-bottom: 1rem;
            }}
            
            .m-auto {{
                margin: auto;
            }}
            
            .mx-auto {{
                margin-left: auto;
                margin-right: auto;
            }}
            
            .mt-2 {{
                margin-top: 0.5rem;
            }}
            
            .mt-4 {{
                margin-top: 1rem;
            }}
            
            .mt-6 {{
                margin-top: 1.5rem;
            }}
            
            .mt-8 {{
                margin-top: 2rem;
            }}
            
            .mb-2 {{
                margin-bottom: 0.5rem;
            }}
            
            .mb-4 {{
                margin-bottom: 1rem;
            }}
            
            .mb-6 {{
                margin-bottom: 1.5rem;
            }}
            
            .flex {{
                display: flex;
            }}
            
            .items-center {{
                align-items: center;
            }}
            
            .justify-between {{
                justify-content: space-between;
            }}
            
            .grid {{
                display: grid;
            }}
            
            .grid-cols-1 {{
                grid-template-columns: repeat(1, minmax(0, 1fr));
            }}
            
            .grid-cols-2 {{
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }}
            
            .grid-cols-4 {{
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }}
            
            .gap-2 {{
                gap: 0.5rem;
            }}
            
            .gap-4 {{
                gap: 1rem;
            }}
            
            .gap-6 {{
                gap: 1.5rem;
            }}
            
            .border {{
                border-width: 1px;
                border-style: solid;
            }}
            
            .border-t {{
                border-top-width: 1px;
                border-top-style: solid;
            }}
            
            .border-b {{
                border-bottom-width: 1px;
                border-bottom-style: solid;
            }}
            
            .border-gray-200 {{
                border-color: #e5e7eb;
            }}
            
            .border-l-4 {{
                border-left-width: 4px;
                border-left-style: solid;
            }}
            
            .border-green-500 {{
                border-color: #10b981;
            }}
            
            .border-yellow-500 {{
                border-color: #f59e0b;
            }}
            
            .border-red-500 {{
                border-color: #ef4444;
            }}
            
            .border-gray-500 {{
                border-color: #6b7280;
            }}
            
            .w-full {{
                width: 100%;
            }}
            
            .max-w-md {{
                max-width: 28rem;
            }}
            
            .max-w-lg {{
                max-width: 32rem;
            }}
            
            .max-w-xl {{
                max-width: 36rem;
            }}
            
            .max-w-2xl {{
                max-width: 42rem;
            }}
            
            .max-w-3xl {{
                max-width: 48rem;
            }}
            
            .max-w-4xl {{
                max-width: 56rem;
            }}
            
            .text-center {{
                text-align: center;
            }}
            
            .text-right {{
                text-align: right;
            }}
            
            .text-green-500 {{
                color: #10b981;
            }}
            
            .text-yellow-500 {{
                color: #f59e0b;
            }}
            
            .text-red-500 {{
                color: #ef4444;
            }}
            
            .bg-green-50 {{
                background-color: #ecfdf5;
            }}
            
            .bg-yellow-50 {{
                background-color: #fffbeb;
            }}
            
            .bg-red-50 {{
                background-color: #fef2f2;
            }}
            
            .bg-gray-50 {{
                background-color: #f9fafb;
            }}
            
            .text-green-700 {{
                color: #047857;
            }}
            
            .text-yellow-700 {{
                color: #b45309;
            }}
            
            .text-red-700 {{
                color: #b91c1c;
            }}
            
            .uppercase {{
                text-transform: uppercase;
            }}
            
            .tracking-wide {{
                letter-spacing: 0.025em;
            }}
            
            .container {{
                width: 100%;
                max-width: 48rem;
                margin-left: auto;
                margin-right: auto;
            }}
            
            .whitespace-nowrap {{
                white-space: nowrap;
            }}
            
            .overflow-hidden {{
                overflow: hidden;
            }}
            
            @media (min-width: 640px) {{
                .sm\\:grid-cols-2 {{
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }}
                
                .sm\\:grid-cols-4 {{
                    grid-template-columns: repeat(4, minmax(0, 1fr));
                }}
            }}
        </style>
    </head>
    <body style="margin: 0; padding: 0; background-color: #f3f4f6;">
        <div style="max-width: 90%; margin: 0 auto; padding: 10px;">
            <!-- Header -->
            <div style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); margin-bottom: 24px; overflow: hidden;">
                <div style="padding: 16px; border-bottom: 1px solid #e5e7eb;">
                    <h1 style="margin: 0; font-size: 28px; font-weight: 600; color: #111827;">Backup Status Bericht vom {today}</h1>
                </div>
                
                <!-- Dashboard Button -->
                <div style="text-align: right; padding: 16px 20px 16px 20px;">
                    <a href="http://192.168.47.14/backup-monitor3" style="display: inline-block; background-color: #2563eb; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 500;">Zum Backup-Dashboard</a>
                </div>
                
                <!-- Status Summary -->
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; padding: 20px;">
                    <!-- Success Status -->
                    <div style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); padding: 16px 20px;">
                        <div style="font-size: 14px; color: #6b7280; margin-bottom: 4px;">Erfolgreich</div>
                        <div style="font-size: 28px; font-weight: 700; color: #059669;">{status_counts['success']}</div>
                    </div>
                    
                    <!-- Warning Status -->
                    <div style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); padding: 16px 20px;">
                        <div style="font-size: 14px; color: #6b7280; margin-bottom: 4px;">Warnungen</div>
                        <div style="font-size: 28px; font-weight: 700; color: #d97706;">{status_counts['warning']}</div>
                    </div>
                    
                    <!-- Error Status -->
                    <div style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); padding: 16px 20px;">
                        <div style="font-size: 14px; color: #6b7280; margin-bottom: 4px;">Fehler</div>
                        <div style="font-size: 28px; font-weight: 700; color: #dc2626;">{status_counts['error']}</div>
                    </div>
                    
                    <!-- None Status -->
                    <div style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); padding: 16px 20px;">
                        <div style="font-size: 14px; color: #6b7280; margin-bottom: 4px;">Kein Status</div>
                        <div style="font-size: 28px; font-weight: 700; color: #6b7280;">{status_counts['none']}</div>
                    </div>
                </div>
    """
    
    if problematic_backups:
        html += """
                <!-- Problematic Backups Table -->
                <div style="padding: 0 8px 12px 8px;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                        <thead>
                            <tr style="background-color: #f9fafb;">
                                <th style="padding: 10px; text-align: left; font-weight: 500; color: #6b7280; border-bottom: 1px solid #e5e7eb; text-transform: uppercase; letter-spacing: 0.05em;">Kunde</th>
                                <th style="padding: 10px; text-align: left; font-weight: 500; color: #6b7280; border-bottom: 1px solid #e5e7eb; text-transform: uppercase; letter-spacing: 0.05em;">Backup-Job</th>
                                <th style="padding: 10px; text-align: center; font-weight: 500; color: #6b7280; border-bottom: 1px solid #e5e7eb; text-transform: uppercase; letter-spacing: 0.05em;">Status</th>
                                <th style="padding: 10px; text-align: center; font-weight: 500; color: #6b7280; border-bottom: 1px solid #e5e7eb; text-transform: uppercase; letter-spacing: 0.05em;">Dauer</th>
                                <th style="padding: 10px; text-align: right; font-weight: 500; color: #6b7280; border-bottom: 1px solid #e5e7eb; text-transform: uppercase; letter-spacing: 0.05em;">Zuletzt</th>
                            </tr>
                        </thead>
                        <tbody>
        """
        
        for i, backup in enumerate(problematic_backups):
            # Alternating row background
            bg_color = "#ffffff" if i % 2 == 0 else "#f9fafb"
            
            # Status badge styling
            status_badge = ""
            if backup['current_status'] == 'warning':
                status_badge = """<span style="display: inline-block; padding: 4px 8px; font-size: 12px; font-weight: 500; border-radius: 4px; background-color: rgba(245, 158, 11, 0.1); color: #d97706;">Warnung</span>"""
            elif backup['current_status'] == 'error':
                status_badge = """<span style="display: inline-block; padding: 4px 8px; font-size: 12px; font-weight: 500; border-radius: 4px; background-color: rgba(239, 68, 68, 0.1); color: #dc2626;">Fehler</span>"""
            elif backup['current_status'] == 'none':
                status_badge = """<span style="display: inline-block; padding: 4px 8px; font-size: 12px; font-weight: 500; border-radius: 4px; background-color: rgba(107, 114, 128, 0.1); color: #6b7280;">Kein Status</span>"""
            
            last_backup = format_date(backup['last_backup_date'])

            # wenn der Wert der Tage größer als 30 ist, wird "über 30 Tage" angezeigt
            days_display = "über 30 Tage" if backup['days_in_status'] >= 31 else f"{backup['days_in_status']} Tage"
            
            html += f"""
                            <tr style="background-color: {bg_color};">
                                <td style="padding: 8px 10px; border-bottom: 1px solid #e5e7eb; font-weight: 500; color: #111827;">{backup['customer_name']}</td>
                                <td style="padding: 8px 10px; border-bottom: 1px solid #e5e7eb; color: #374151;">{backup['job_name']}</td>
                                <td style="padding: 8px 10px; border-bottom: 1px solid #e5e7eb; text-align: center;">{status_badge}</td>
                                <td style="padding: 8px 10px; border-bottom: 1px solid #e5e7eb; text-align: center; font-weight: 500;">{days_display}</td>
                                <td style="padding: 8px 10px; border-bottom: 1px solid #e5e7eb; text-align: right;">{last_backup}</td>
                            </tr>
            """
        
        html += """
                        </tbody>
                    </table>
                </div>
        """
    else:
        html += """
                <div style="padding: 24px; text-align: center; color: #6b7280;">
                    Keine problematischen Backup-Jobs gefunden.
                </div>
        """
    
    html += """
            </div>
            
            <!-- Footer -->
            <div style="text-align: center; padding: 12px; font-size: 12px; color: #6b7280;">
                Dieser Bericht wurde automatisch generiert. Bei Fragen wende dich an die phd IT-Systeme GmbH.
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
        
        # High Priority Header hinzufügen
        msg['X-Priority'] = '1'
        msg['X-MSMail-Priority'] = 'High'
        msg['Importance'] = 'High'
        
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
            recipients = [
                'technik@phd-it-systeme.de',
                'andreas.koller@phd-it-systeme.de',
                'dominik.schmidt@phd-it-systeme.de',
                'joshua.lux@phd-it-systeme.de'
            ]
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