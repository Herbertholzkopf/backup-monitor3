#!/usr/bin/env python3

import imaplib
import email
from email.header import decode_header
import datetime
import pymysql
import os
import sys

# Konfigurationsdateien einbinden
sys.path.append(os.path.join(os.path.dirname(__file__), 'config'))
import mail
import database

def connect_to_mail():
    try:
        imap = imaplib.IMAP4_SSL(mail.MAIL_SERVER)
        imap.login(mail.MAIL_USER, mail.MAIL_PASSWORD)
        return imap
    except Exception as e:
        print(f"Fehler beim Verbinden mit dem Mailserver: {e}")
        sys.exit(1)

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

def process_mailbox(imap, db_connection):
    status, messages = imap.select(mail.MAIL_FOLDER)
    if status != 'OK':
        print(f"Fehler beim Öffnen des Mailordners {mail.MAIL_FOLDER}")
        return

    result, data = imap.search(None, 'ALL')
    mail_ids = data[0].split()

    print(f"Anzahl der gefundenen E-Mails: {len(mail_ids)}")

    for num in mail_ids:
        result, message_data = imap.fetch(num, '(RFC822)')
        if result != 'OK':
            print(f"Fehler beim Abrufen der E-Mail {num}")
            continue

        print(f"Verarbeite E-Mail Nummer: {num.decode()}")

        raw_email = message_data[0][1]
        email_message = email.message_from_bytes(raw_email)

        # Absender
        sender = email_message.get('From')
        sender_email = email.utils.parseaddr(sender)[1]

        # Betreff
        subject, encoding = decode_header(email_message.get('Subject'))[0]
        if isinstance(subject, bytes):
            subject = subject.decode(encoding if encoding else 'utf-8')

        # Datum
        date_tuple = email.utils.parsedate_tz(email_message.get('Date'))
        if date_tuple:
            local_date = datetime.datetime.fromtimestamp(
                email.utils.mktime_tz(date_tuple)
            )
        else:
            local_date = datetime.datetime.now()

        # Inhalt
        content = ''
        if email_message.is_multipart():
            # Alle Teile der E-Mail durchlaufen
            for part in email_message.walk():
                content_type = part.get_content_type()
                content_disposition = str(part.get('Content-Disposition'))
                
                # Anhänge überspringen
                if part.get_content_maintype() == 'multipart':
                    continue
                if 'attachment' in content_disposition:
                    continue

                # Textinhalt extrahieren
                if content_type == 'text/plain':
                    charset = part.get_content_charset()
                    part_content = part.get_payload(decode=True).decode(charset if charset else 'utf-8', errors='replace')
                    content += part_content + '\n'
                elif content_type == 'text/html':
                    # Optional: HTML-Inhalt in Text umwandeln
                    charset = part.get_content_charset()
                    html_content = part.get_payload(decode=True).decode(charset if charset else 'utf-8', errors='replace')
                    # HTML zu Text konvertieren (falls gewünscht)
                    try:
                        from bs4 import BeautifulSoup
                        soup = BeautifulSoup(html_content, 'html.parser')
                        text_content = soup.get_text()
                        content += text_content + '\n'
                    except ImportError:
                        # Falls BeautifulSoup nicht installiert ist, HTML-Inhalt als Text speichern
                        content += html_content + '\n'
        else:
            content_type = email_message.get_content_type()
            if content_type == 'text/plain':
                charset = email_message.get_content_charset()
                content = email_message.get_payload(decode=True).decode(charset if charset else 'utf-8', errors='replace')
            elif content_type == 'text/html':
                charset = email_message.get_content_charset()
                html_content = email_message.get_payload(decode=True).decode(charset if charset else 'utf-8', errors='replace')
                # HTML zu Text konvertieren (falls gewünscht)
                try:
                    from bs4 import BeautifulSoup
                    soup = BeautifulSoup(html_content, 'html.parser')
                    content = soup.get_text()
                except ImportError:
                    content = html_content

        # Debugging-Ausgabe vor dem Speichern
        # print(f"Inhalt vor dem Speichern:\n{content}\n{'-'*50}")

        # Speichern in der Datenbank
        print("Speichere Daten in die Datenbank...", flush=True)
        try:
            with db_connection.cursor() as cursor:
                sql = "INSERT INTO mails (sender_email, date, subject, content) VALUES (%s, %s, %s, %s)"
                cursor.execute(sql, (sender_email, local_date, subject, content))
            db_connection.commit()
        except Exception as e:
            print(f"Fehler beim Speichern in der Datenbank: {e}")
            continue

        # Löschen der E-Mail
        imap.store(num, '+FLAGS', '\\Deleted')

    # E-Mails endgültig löschen
    imap.expunge()

def main():
    imap = connect_to_mail()
    db_connection = connect_to_database()
    process_mailbox(imap, db_connection)
    imap.logout()
    db_connection.close()

if __name__ == "__main__":
    main()