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
    """Zählt die Anzahl der Backup-Jobs nach Status (nur Jobs mit include_in_report)"""
    cursor = conn.cursor()
    query = """
    SELECT 
        SUM(CASE WHEN sd.current_status = 'success' THEN 1 ELSE 0 END) AS success_count,
        SUM(CASE WHEN sd.current_status = 'warning' THEN 1 ELSE 0 END) AS warning_count,
        SUM(CASE WHEN sd.current_status = 'error' THEN 1 ELSE 0 END) AS error_count,
        SUM(CASE WHEN sd.current_status = 'none' THEN 1 ELSE 0 END) AS none_count
    FROM status_duration sd
    JOIN backup_jobs bj ON sd.backup_job_id = bj.id
    WHERE bj.include_in_report = TRUE
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
    """Erstellt ein für E-Mail-Clients optimiertes HTML-Template
    
    Design System Farben (aus styles.css):
        --color-primary:       #2563eb
        --color-success:       #16a34a   / bg: rgba(34,197,94,0.1) → #e8f8ef
        --color-warning:       #ea580c   / bg: rgba(249,115,22,0.1) → #fef2e8
        --color-danger:        #dc2626   / bg: rgba(239,68,68,0.1) → #fde9e9
        --color-gray-50:       #f9fafb
        --color-gray-100:      #f3f4f6
        --color-gray-200:      #e5e7eb
        --color-gray-500:      #6b7280
        --color-gray-700:      #374151
        --color-gray-800:      #1f2937
        --color-gray-900:      #111827

    Outlook Classic Kompatibilität:
        - Kein border-radius (wird ignoriert)
        - bgcolor-Attribute zusätzlich zu style
        - MSO-Conditionals für Button (VML)
        - Badges als Tabelle statt inline-block span
        - mso-line-height-rule: exactly
    """
    today = datetime.now().strftime('%d.%m.%Y')

    # Stat-Card Helper — erzeugt eine einzelne Outlook-kompatible Stat-Kachel
    def _stat_card(label, value, value_color):
        return f'''<td width="25%" valign="top" style="padding: 0 6px;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse;">
        <tr>
            <td bgcolor="#ffffff" style="background-color: #ffffff; border: 1px solid #e5e7eb; padding: 16px; font-family: Arial, Helvetica, sans-serif;">
                <p style="margin: 0; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; mso-line-height-rule: exactly; line-height: 16px;">{label}</p>
                <p style="margin: 6px 0 0; font-size: 30px; font-weight: 700; color: {value_color}; mso-line-height-rule: exactly; line-height: 36px;">{value}</p>
            </td>
        </tr>
    </table>
</td>'''

    # Badge Helper — Outlook-kompatibles Badge als Mini-Tabelle
    def _status_badge(status_text, bg_color, text_color):
        return f'''<table border="0" cellpadding="0" cellspacing="0" align="center" style="border-collapse: collapse;">
    <tr>
        <td bgcolor="{bg_color}" style="background-color: {bg_color}; padding: 4px 8px; font-size: 12px; font-weight: 500; color: {text_color}; font-family: Arial, Helvetica, sans-serif; mso-line-height-rule: exactly; line-height: 16px;">{status_text}</td>
    </tr>
</table>'''

    # --- Haupt-HTML ---
    html = f'''<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <!--[if gte mso 9]>
    <xml>
        <o:OfficeDocumentSettings>
            <o:AllowPNG/>
            <o:PixelsPerInch>96</o:PixelsPerInch>
        </o:OfficeDocumentSettings>
    </xml>
    <![endif]-->
    <title>Backup Status Bericht</title>
    <!--[if mso]>
    <style type="text/css">
        body, table, td, th, p, a {{ font-family: Arial, Helvetica, sans-serif !important; }}
        table {{ border-collapse: collapse; }}
    </style>
    <![endif]-->
</head>
<body style="margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; background-color: #f9fafb; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;" bgcolor="#f9fafb">

<!-- Outer Wrapper -->
<table border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#f9fafb" style="background-color: #f9fafb;">
    <tr>
        <td align="center" style="padding: 24px 12px;">

            <!-- Content Card -->
            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 720px; border-collapse: collapse;" bgcolor="#ffffff">

                <!-- Header -->
                <tr>
                    <td bgcolor="#ffffff" style="background-color: #ffffff; padding: 24px; border-bottom: 1px solid #e5e7eb; font-family: Arial, Helvetica, sans-serif;">
                        <h1 style="margin: 0; font-size: 20px; font-weight: 700; color: #1f2937; mso-line-height-rule: exactly; line-height: 28px;">Backup Status Bericht vom {today}</h1>
                    </td>
                </tr>

                <!-- Dashboard Button -->
                <tr>
                    <td bgcolor="#ffffff" style="background-color: #ffffff; padding: 16px 24px 8px; text-align: right; font-family: Arial, Helvetica, sans-serif;">
                        <!--[if mso]>
                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="https://backup.phd-it.de" style="height:36px;v-text-anchor:middle;width:190px;" arcsize="17%" fillcolor="#2563eb" stroke="f">
                            <w:anchorlock/>
                            <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:14px;font-weight:500;">Zum Backup-Dashboard</center>
                        </v:roundrect>
                        <![endif]-->
                        <!--[if !mso]><!-->
                        <a href="https://backup.phd-it.de" style="display: inline-block; background-color: #2563eb; color: #ffffff; padding: 8px 16px; border-radius: 12px; text-decoration: none; font-size: 14px; font-weight: 500; mso-line-height-rule: exactly; line-height: 20px;">Zum Backup-Dashboard</a>
                        <!--<![endif]-->
                    </td>
                </tr>

                <!-- Stat Cards -->
                <tr>
                    <td bgcolor="#ffffff" style="background-color: #ffffff; padding: 20px 18px; font-family: Arial, Helvetica, sans-serif;">
                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse;">
                            <tr>
                                {_stat_card("Erfolgreich", status_counts['success'], "#16a34a")}
                                {_stat_card("Warnungen",   status_counts['warning'], "#ea580c")}
                                {_stat_card("Fehler",      status_counts['error'],   "#dc2626")}
                                {_stat_card("Kein Status", status_counts['none'],    "#6b7280")}
                            </tr>
                        </table>
                    </td>
                </tr>
'''

    # --- Tabelle der problematischen Backups ---
    if problematic_backups:
        # Header-Zellen-Style (Design System: .data-table thead th)
        th_style = (
            "padding: 12px 20px; text-align: left; font-size: 11px; font-weight: 600; "
            "color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; "
            "border-bottom: 1px solid #e5e7eb; font-family: Arial, Helvetica, sans-serif; "
            "mso-line-height-rule: exactly; line-height: 16px;"
        )

        html += f'''
                <!-- Problematic Backups Table -->
                <tr>
                    <td bgcolor="#ffffff" style="background-color: #ffffff; padding: 0 24px 24px; font-family: Arial, Helvetica, sans-serif;">
                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse; font-size: 14px;">
                            <tr bgcolor="#f9fafb" style="background-color: #f9fafb;">
                                <th style="{th_style}">Kunde</th>
                                <th style="{th_style}">Backup-Job</th>
                                <th style="{th_style} text-align: center;">Status</th>
                                <th style="{th_style} text-align: center;">Dauer</th>
                            </tr>
'''

        for i, backup in enumerate(problematic_backups):
            bg_color = "#ffffff" if i % 2 == 0 else "#f9fafb"

            # Status-Farben aus dem Design System (badges)
            if backup['current_status'] == 'warning':
                badge_bg = "#fef2e8"
                badge_color = "#ea580c"
                status_text = "Warnung"
            elif backup['current_status'] == 'error':
                badge_bg = "#fde9e9"
                badge_color = "#dc2626"
                status_text = "Fehler"
            else:  # 'none'
                badge_bg = "#f3f4f6"
                badge_color = "#6b7280"
                status_text = "Kein Status"

            days_display = (
                "&uuml;ber 30 Tage" if backup['days_in_status'] >= 31
                else f"{backup['days_in_status']} Tage"
            )

            # Zellen-Style (Design System: .data-table tbody td)
            td_base = (
                f"padding: 14px 20px; border-bottom: 1px solid #f3f4f6; "
                f"font-family: Arial, Helvetica, sans-serif; font-size: 14px; "
                f"mso-line-height-rule: exactly; line-height: 20px;"
            )

            html += f'''
                            <tr bgcolor="{bg_color}" style="background-color: {bg_color};">
                                <td style="{td_base} font-weight: 500; color: #111827;">{backup['customer_name']}</td>
                                <td style="{td_base} color: #374151;">{backup['job_name']}</td>
                                <td style="{td_base} text-align: center;">
                                    {_status_badge(status_text, badge_bg, badge_color)}
                                </td>
                                <td style="{td_base} text-align: center; font-weight: 500; color: #111827;">{days_display}</td>
                            </tr>
'''

        html += '''
                        </table>
                    </td>
                </tr>
'''
    else:
        html += '''
                <!-- No Problematic Backups -->
                <tr>
                    <td bgcolor="#ffffff" style="background-color: #ffffff; padding: 24px; text-align: center; color: #6b7280; font-family: Arial, Helvetica, sans-serif; font-size: 14px;">
                        Keine problematischen Backup-Jobs gefunden.
                    </td>
                </tr>
'''

    # --- Footer + Abschluss ---
    html += '''
            </table>
            <!-- /Content Card -->

            <!-- Footer -->
            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 720px; border-collapse: collapse;">
                <tr>
                    <td style="text-align: center; padding: 16px 12px; font-size: 12px; color: #6b7280; font-family: Arial, Helvetica, sans-serif; mso-line-height-rule: exactly; line-height: 18px;">
                        Dieser Bericht wurde automatisch generiert. Bei Fragen wende dich an die phd IT-Systeme GmbH.
                    </td>
                </tr>
            </table>

        </td>
    </tr>
</table>
<!-- /Outer Wrapper -->

</body>
</html>
'''

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
            'florian.boeller@phd-it-systeme.de',
            'johannes.siegert@phd-it-systeme.de',
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