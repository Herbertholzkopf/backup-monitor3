# Dieses Skript ordnet die Mails den Backup-Jobs zu und speichert die Zuordnung in der backup_results Datenbank

# Das Skript sucht in der mails Datenbank nach Mails, die noch nicht einem Backup-Job zugeordnet wurden (also den job_found = FALSE haben)
# Dann werden aus der Tabelle backup_jobs der Job herausgesucht, welcher den passenden search_term_mail (Mailadresse), search_term_subject 
# (Betreff) und search_term_text (Text-Inhalt in der Mail) (und wenn vorhanden search_term_text2 (zweiter Text-Inhalt in der Mail)) hat.

# Wenn ein passender Job gefunden wurde, wird in der backup_results Datenbank ein neuer Eintrag erstellt, der die mail_id und die backup_job_id enthält.

# Dann wird der job_found Wert in der mails Datenbank auf TRUE gesetzt.


import os
import sys
import pymysql

# Konfigurationsdateien einbinden
sys.path.append(os.path.join(os.path.dirname(__file__), 'config'))
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

def process_unassigned_mails():
    connection = connect_to_database()
    try:
        with connection.cursor() as cursor:
            # Unverarbeitete Mails abrufen
            cursor.execute("""
                SELECT id, sender_email, subject, content 
                FROM mails 
                WHERE job_found = FALSE
            """)
            unprocessed_mails = cursor.fetchall()

            for mail in unprocessed_mails:
                # Passenden Backup-Job suchen
                cursor.execute("""
                    SELECT id FROM backup_jobs 
                    WHERE search_term_mail = %s 
                    AND (
                        %s LIKE CONCAT('%%', search_term_subject, '%%')
                        OR search_term_subject IS NULL
                    )
                    AND (
                        %s LIKE CONCAT('%%', search_term_text, '%%')
                        OR search_term_text IS NULL
                    )
                    AND (
                        %s LIKE CONCAT('%%', search_term_text2, '%%')
                        OR search_term_text2 IS NULL
                    )
                """, (mail['sender_email'], mail['subject'], mail['content'], mail['content']))
                
                matching_job = cursor.fetchone()
                
                if matching_job:
                    # Eintrag in backup_results erstellen
                    cursor.execute("""
                        INSERT INTO backup_results (mail_id, backup_job_id)
                        VALUES (%s, %s)
                    """, (mail['id'], matching_job['id']))
                    
                    # Mail als verarbeitet markieren
                    cursor.execute("""
                        UPDATE mails 
                        SET job_found = TRUE 
                        WHERE id = %s
                    """, (mail['id']))
                    
                    connection.commit()
                    print(f"Mail {mail['id']} wurde Job {matching_job['id']} zugeordnet")

    except Exception as e:
        print(f"Fehler bei der Verarbeitung: {e}")
    finally:
        connection.close()

if __name__ == "__main__":
    process_unassigned_mails()