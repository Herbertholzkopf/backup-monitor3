# Dieses Skript wendet die definierten Mail-Filter an und löscht E-Mails, die den Filterkriterien entsprechen

import os
import sys
import pymysql
import logging
from datetime import datetime

# Konfigurationsdateien einbinden
sys.path.append(os.path.join(os.path.dirname(__file__), 'config'))
import database

# Logging einrichten
log_directory = os.path.join(os.path.dirname(__file__), 'logs')
os.makedirs(log_directory, exist_ok=True)
log_file = os.path.join(log_directory, f'mail_filter_{datetime.now().strftime("%Y%m%d")}.log')

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler(log_file),
        logging.StreamHandler()
    ]
)

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
        logging.error(f"Fehler beim Verbinden mit der Datenbank: {e}")
        sys.exit(1)

def get_active_filters(connection):
    """Aktive Filter aus der Datenbank abrufen"""
    try:
        with connection.cursor() as cursor:
            cursor.execute("""
                SELECT id, name, search_term_mail, search_term_subject, search_term_text, 
                       search_term_text2, match_type
                FROM mail_filter 
                WHERE is_active = TRUE
            """)
            return cursor.fetchall()
    except Exception as e:
        logging.error(f"Fehler beim Abrufen der Filter: {e}")
        return []

def build_where_clause(filter_data):
    """WHERE-Klausel basierend auf den Filterkriterien erstellen"""
    conditions = []
    params = []
    
    # Für jeden nicht-leeren Suchbegriff eine Bedingung hinzufügen
    if filter_data.get('search_term_mail'):
        conditions.append("sender_email LIKE %s")
        params.append(f"%{filter_data['search_term_mail']}%")
    
    if filter_data.get('search_term_subject'):
        conditions.append("subject LIKE %s")
        params.append(f"%{filter_data['search_term_subject']}%")
    
    if filter_data.get('search_term_text'):
        conditions.append("content LIKE %s")
        params.append(f"%{filter_data['search_term_text']}%")
    
    if filter_data.get('search_term_text2'):
        conditions.append("content LIKE %s")
        params.append(f"%{filter_data['search_term_text2']}%")
    
    # Wenn keine Bedingungen vorhanden sind, leere Liste zurückgeben
    if not conditions:
        return "", []
    
    # Je nach match_type die Bedingungen mit AND oder OR verknüpfen
    operator = " AND " if filter_data['match_type'] == 'ALL' else " OR "
    where_clause = operator.join(conditions)
    
    return where_clause, params

def apply_filter(connection, filter_data):
    """Filter anwenden und passende E-Mails löschen"""
    try:
        where_clause, params = build_where_clause(filter_data)
        
        if not where_clause:
            logging.warning(f"Filter '{filter_data['name']}' (ID: {filter_data['id']}) hat keine gültigen Suchkriterien")
            return 0
        
        with connection.cursor() as cursor:
            # Anzahl der zu löschenden E-Mails ermitteln (für Log)
            count_query = f"SELECT COUNT(*) as count FROM mails WHERE {where_clause}"
            cursor.execute(count_query, params)
            count_result = cursor.fetchone()
            affected_count = count_result['count'] if count_result else 0
            
            # Wenn E-Mails gefunden wurden, diese löschen
            if affected_count > 0:
                delete_query = f"DELETE FROM mails WHERE {where_clause}"
                cursor.execute(delete_query, params)
                
                # Last_used Zeitstempel aktualisieren
                cursor.execute("""
                    UPDATE mail_filter 
                    SET last_used = NOW() 
                    WHERE id = %s
                """, (filter_data['id'],))
                
                connection.commit()
                
                logging.info(f"Filter '{filter_data['name']}' (ID: {filter_data['id']}) angewendet: {affected_count} E-Mail(s) gelöscht")
            else:
                logging.info(f"Filter '{filter_data['name']}' (ID: {filter_data['id']}) angewendet: Keine passenden E-Mails gefunden")
            
            return affected_count
            
    except Exception as e:
        logging.error(f"Fehler beim Anwenden des Filters '{filter_data['name']}' (ID: {filter_data['id']}): {e}")
        connection.rollback()
        return 0

def process_mail_filters():
    """Hauptfunktion zum Verarbeiten aller aktiven Filter"""
    connection = connect_to_database()
    total_deleted = 0
    
    try:
        logging.info("Starte Anwendung der Mail-Filter...")
        
        # Aktive Filter abrufen
        active_filters = get_active_filters(connection)
        logging.info(f"{len(active_filters)} aktive Filter gefunden")
        
        # Jeden Filter anwenden
        for filter_data in active_filters:
            deleted_count = apply_filter(connection, filter_data)
            total_deleted += deleted_count
        
        logging.info(f"Filter-Anwendung abgeschlossen. Insgesamt {total_deleted} E-Mail(s) gelöscht")
        
    except Exception as e:
        logging.error(f"Unerwarteter Fehler bei der Verarbeitung der Filter: {e}")
    finally:
        connection.close()
        
    return total_deleted

if __name__ == "__main__":
    process_mail_filters()