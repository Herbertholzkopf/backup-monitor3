-- === KRITISCHE INDIZES FÜR SOFORTIGE PERFORMANCE-VERBESSERUNG ===
-- Führen Sie diese Befehle in Ihrer MySQL Datenbank aus

USE backup_monitor3;

-- 1. WICHTIGSTER INDEX: Für die häufigste Abfrage
ALTER TABLE backup_results ADD INDEX idx_job_date_time (backup_job_id, date DESC, time DESC);

-- 2. Index für die runs_count Subquery
ALTER TABLE backup_results ADD INDEX idx_job_date (backup_job_id, date);

-- 3. Index für Foreign Key Lookups
ALTER TABLE backup_jobs ADD INDEX idx_customer (customer_id);

-- 4. Index für Status-Abfragen
ALTER TABLE status_duration ADD INDEX idx_status (current_status);

-- 5. Composite Index für Datum-basierte Abfragen
ALTER TABLE backup_results ADD INDEX idx_date_status (date, status);

-- 6. Index für Mail-Lookups
ALTER TABLE backup_results ADD INDEX idx_mail (mail_id);

-- === OPTIONAL: Weitere Indizes für spezielle Abfragen ===

-- Für die Kundensuche
ALTER TABLE customers ADD INDEX idx_name (name);
ALTER TABLE customers ADD INDEX idx_number (number);

-- Für Status-Duration Queries
ALTER TABLE status_duration ADD INDEX idx_job_status (backup_job_id, current_status);

-- Kritisch für unprocessed-mails
ALTER TABLE mails ADD INDEX idx_result_processed (result_processed);
ALTER TABLE mails ADD INDEX idx_job_processed (job_found, result_processed);

-- Für die Suche
ALTER TABLE mails ADD INDEX idx_sender (sender_email);
ALTER TABLE mails ADD INDEX idx_subject (subject(100));
ALTER TABLE mails ADD INDEX idx_created (created_at);

-- Kombinierter Index für die häufigste Abfrage
ALTER TABLE mails ADD INDEX idx_processed_created (result_processed, created_at DESC);