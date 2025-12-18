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
    WHERE sd.current_status != 'success' AND bj.include_in_report = TRUE
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

def generate_email_safe_html(status_counts, problematic_backups):
    """Erstellt ein für E-Mail-Clients optimiertes HTML-Template"""
    today = datetime.now().strftime('%d.%m.%Y')
    
    # Erstelle das HTML mit Outlook-kompatiblen Tabellen
    html = f"""
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
    <html xmlns="http://www.w3.org/1999/xhtml">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Backup Status Bericht</title>
    </head>
    <body style="margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; background-color: #F3F4F6;">
        <!-- Main Container -->
        <table border="0" cellpadding="0" cellspacing="0" width="95%" style="margin: 0 auto; padding: 10px;">
            <tr>
                <td>
                    <!-- Header Card -->
                    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #FFFFFF; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);">
                        <!-- Title Row -->
                        <tr>
                            <td style="padding: 20px; border-bottom: 1px solid #E5E7EB;">
                                <h1 style="margin: 0; font-size: 24px; font-weight: 600; color: #111827;">Backup Status Bericht vom {today}</h1>
                            </td>
                        </tr>
                        
                        <!-- Dashboard Button -->
                        <tr>
                            <td style="padding: 0 20px 10px; text-align: right;">
                                <a href="https://backup.phd-it.de" style="display: inline-block; background-color: #2563EB; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 500; margin-top: 10px;">Zum Backup-Dashboard</a>
                            </td>
                        </tr>
                        
                        <!-- Summary Stats -->
                        <tr>
                            <td style="padding: 20px;">
                                <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                    <tr>
                                        <!-- Success Count -->
                                        <td width="25%" style="padding: 10px;">
                                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #FFFFFF; border-radius: 8px; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); padding: 10px;">
                                                <tr>
                                                    <td style="font-size: 14px; color: #6B7280; padding-bottom: 4px;">Erfolgreich</td>
                                                </tr>
                                                <tr>
                                                    <td style="font-size: 28px; font-weight: 700; color: #059669;">{status_counts['success']}</td>
                                                </tr>
                                            </table>
                                        </td>
                                        
                                        <!-- Warning Count -->
                                        <td width="25%" style="padding: 10px;">
                                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #FFFFFF; border-radius: 8px; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); padding: 10px;">
                                                <tr>
                                                    <td style="font-size: 14px; color: #6B7280; padding-bottom: 4px;">Warnungen</td>
                                                </tr>
                                                <tr>
                                                    <td style="font-size: 28px; font-weight: 700; color: #D97706;">{status_counts['warning']}</td>
                                                </tr>
                                            </table>
                                        </td>
                                        
                                        <!-- Error Count -->
                                        <td width="25%" style="padding: 10px;">
                                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #FFFFFF; border-radius: 8px; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); padding: 10px;">
                                                <tr>
                                                    <td style="font-size: 14px; color: #6B7280; padding-bottom: 4px;">Fehler</td>
                                                </tr>
                                                <tr>
                                                    <td style="font-size: 28px; font-weight: 700; color: #DC2626;">{status_counts['error']}</td>
                                                </tr>
                                            </table>
                                        </td>
                                        
                                        <!-- None Count -->
                                        <td width="25%" style="padding: 10px;">
                                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #FFFFFF; border-radius: 8px; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); padding: 10px;">
                                                <tr>
                                                    <td style="font-size: 14px; color: #6B7280; padding-bottom: 4px;">Kein Status</td>
                                                </tr>
                                                <tr>
                                                    <td style="font-size: 28px; font-weight: 700; color: #6B7280;">{status_counts['none']}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
    """
    
    # Füge die Tabelle der problematischen Backups hinzu
    if problematic_backups:
        html += """
                        <!-- Problematic Backups Table -->
                        <tr>
                            <td style="padding: 0 10px 20px 10px;">
                                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse; font-size: 14px;">
                                    <!-- Table Header -->
                                    <tr style="background-color: #F9FAFB;">
                                        <th style="padding: 10px; text-align: left; font-weight: 500; color: #6B7280; border-bottom: 1px solid #E5E7EB; text-transform: uppercase; letter-spacing: 0.05em;">Kunde</th>
                                        <th style="padding: 10px; text-align: left; font-weight: 500; color: #6B7280; border-bottom: 1px solid #E5E7EB; text-transform: uppercase; letter-spacing: 0.05em;">Backup-Job</th>
                                        <th style="padding: 10px; text-align: center; font-weight: 500; color: #6B7280; border-bottom: 1px solid #E5E7EB; text-transform: uppercase; letter-spacing: 0.05em;">Status</th>
                                        <th style="padding: 10px; text-align: center; font-weight: 500; color: #6B7280; border-bottom: 1px solid #E5E7EB; text-transform: uppercase; letter-spacing: 0.05em;">Dauer</th>
                                        <th style="padding: 10px; text-align: right; font-weight: 500; color: #6B7280; border-bottom: 1px solid #E5E7EB; text-transform: uppercase; letter-spacing: 0.05em;">Zuletzt</th>
                                    </tr>
        """
        
        # Füge für jeden problematischen Backup eine Zeile hinzu
        for i, backup in enumerate(problematic_backups):
            # Alternating row background
            bg_color = "#FFFFFF" if i % 2 == 0 else "#F9FAFB"
            
            # Status styling anhand des Status
            if backup['current_status'] == 'warning':
                status_bg = "#FFFBEB"
                status_color = "#D97706"
                status_text = "Warnung"
            elif backup['current_status'] == 'error':
                status_bg = "#FEF2F2"
                status_color = "#DC2626"
                status_text = "Fehler"
            else:  # 'none'
                status_bg = "#F9FAFB"
                status_color = "#6B7280"
                status_text = "Kein Status"
            
            last_backup = format_date(backup['last_backup_date'])
            days_display = "über 30 Tage" if backup['days_in_status'] >= 31 else f"{backup['days_in_status']} Tage"
            
            html += f"""
                                    <!-- Table Row -->
                                    <tr style="background-color: {bg_color};">
                                        <td style="padding: 10px; border-bottom: 1px solid #E5E7EB; font-weight: 500; color: #111827;">{backup['customer_name']}</td>
                                        <td style="padding: 10px; border-bottom: 1px solid #E5E7EB; color: #374151;">{backup['job_name']}</td>
                                        <td style="padding: 10px; border-bottom: 1px solid #E5E7EB; text-align: center;">
                                            <span style="display: inline-block; padding: 4px 8px; font-size: 12px; font-weight: 500; border-radius: 4px; background-color: {status_bg}; color: {status_color};">{status_text}</span>
                                        </td>
                                        <td style="padding: 10px; border-bottom: 1px solid #E5E7EB; text-align: center; font-weight: 500;">{days_display}</td>
                                        <td style="padding: 10px; border-bottom: 1px solid #E5E7EB; text-align: right;">{last_backup}</td>
                                    </tr>
            """
        
        html += """
                                </table>
                            </td>
                        </tr>
        """
    else:
        html += """
                        <!-- No Problematic Backups Message -->
                        <tr>
                            <td style="padding: 20px; text-align: center; color: #6B7280;">
                                Keine problematischen Backup-Jobs gefunden.
                            </td>
                        </tr>
        """
    
    # Schließe die Tabellen und das HTML
    html += """
                    </table>
                    
                    <!-- Footer -->
                    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-top: 20px;">
                        <tr>
                            <td style="text-align: center; padding: 12px; font-size: 12px; color: #6B7280;">
                                Dieser Bericht wurde automatisch generiert. Bei Fragen wende dich an die phd IT-Systeme GmbH.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
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
        html_report = generate_email_safe_html(status_counts, problematic_backups)
        
        # E-Mail immer versenden, unabhängig von problematischen Backups
        recipients = [
            'andreas.koller@phd-it-systeme.de',
            'dominik.schmidt@phd-it-systeme.de',
            'joshua.lux@phd-it-systeme.de',
            'technik@phd-it-systeme.de'
        ]
        subject = f"Backup Status Bericht: {status_counts['warning']} Warnungen, {status_counts['error']} Fehler, {status_counts['none']} ohne Status"
        
        send_email(subject, html_report, recipients)
        logger.info("Backup Status E-Mail Bericht versendet.")
        
        logger.info("Backup Status E-Mail Bericht erfolgreich abgeschlossen")
    except Exception as e:
        logger.error(f"Fehler im Backup Status E-Mail Bericht: {e}")
    finally:
        conn.close()
        logger.info("Datenbankverbindung geschlossen")

if __name__ == "__main__":
    main()