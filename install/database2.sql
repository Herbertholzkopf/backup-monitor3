-- ============================================================
-- KONSOLIDIERTER INDEX-PLAN für backup_monitor3
-- ============================================================
-- Erstellt aus Analyse von database.sql + database2.sql
-- 
-- Abschnitte:
--   1. Redundante Indizes entfernen (spart Schreibperformance)
--   2. Fehlende Indizes anlegen
--   3. Optional: FULLTEXT für Mail-Inhaltssuche
--
-- WICHTIG: Vor dem Ausführen prüfen welche Indizes schon existieren:
--   SHOW INDEX FROM mails;
--   SHOW INDEX FROM backup_results;
--   SHOW INDEX FROM backup_jobs;
--   SHOW INDEX FROM customers;
--   SHOW INDEX FROM instructions;
--   SHOW INDEX FROM mail_filter;
-- ============================================================

USE backup_monitor3;


-- ────────────────────────────────────────────────────────────
-- 1. REDUNDANTE INDIZES ENTFERNEN
-- ────────────────────────────────────────────────────────────
-- Jeder unnötige Index kostet bei INSERT/UPDATE/DELETE,
-- weil MySQL ihn bei jeder Schreiboperation mitpflegen muss.
-- Bei 86.000+ Mails summiert sich das.

-- idx_result_processed ist Subset von idx_processed_created
-- (Composite-Index auf (result_processed, created_at) deckt
--  Einzelabfragen auf result_processed automatisch ab)
ALTER TABLE mails DROP INDEX idx_result_processed;

-- idx_created ist Subset von idx_processed_created
-- (created_at ist die zweite Spalte im Composite)
ALTER TABLE mails DROP INDEX idx_created;

-- idx_job_date ist Subset von idx_job_date_time
-- (backup_job_id, date) ist Prefix von (backup_job_id, date, time)
ALTER TABLE backup_results DROP INDEX idx_job_date;


-- ────────────────────────────────────────────────────────────
-- 2. FEHLENDE INDIZES ANLEGEN
-- ────────────────────────────────────────────────────────────

-- ─── mails ───

-- KRITISCH: Hauptsortierung auf der All-Mails-Seite
-- Ohne diesen Index: Full Table Scan + filesort über 86.000 Zeilen
ALTER TABLE mails ADD INDEX idx_date (date DESC);

-- Composite für Datumsfilter + Sortierung
-- Deckt ab: WHERE date >= ? AND date <= ? ORDER BY date DESC
ALTER TABLE mails ADD INDEX idx_date_range (date, result_processed);


-- ─── backup_jobs ───

-- Für LIKE-Suche und Sortierung nach Job-Name auf der All-Mails-Seite
ALTER TABLE backup_jobs ADD INDEX idx_name (name);


-- ─── instructions ───

-- Für Filterung nach Kategorie (wahrscheinlich häufigste Abfrage)
ALTER TABLE instructions ADD INDEX idx_category (category);

-- Für Suche nach Titel
ALTER TABLE instructions ADD INDEX idx_title (title(100));


-- ─── mail_filter ───

-- Für die Abfrage aktiver Filter (WHERE is_active = TRUE)
ALTER TABLE mail_filter ADD INDEX idx_is_active (is_active);

-- Composite: Aktive Filter mit ihren Suchbegriffen
-- Deckt ab: WHERE is_active = 1 → dann sofort Zugriff auf search_terms
ALTER TABLE mail_filter ADD INDEX idx_active_search (
    is_active,
    search_term_mail(50),
    search_term_subject(50)
);


-- ────────────────────────────────────────────────────────────
-- 3. OPTIONAL: FULLTEXT-INDEX für Mail-Inhaltssuche
-- ────────────────────────────────────────────────────────────
-- Falls ihr DOCH im Mail-Inhalt suchen wollt/müsst:
-- Ein FULLTEXT-Index ist ~100x schneller als LIKE '%...%'
-- auf MEDIUMTEXT.
--
-- ACHTUNG:
-- - Das Erstellen dauert bei 86.000 Mails ein paar Minuten!
-- - Verbraucht zusätzlichen Speicherplatz
-- - Funktioniert ab MySQL 5.6 mit InnoDB
--
-- Syntax für die Suche danach:
--   WHERE MATCH(sender_email, subject) AGAINST('suchbegriff' IN BOOLEAN MODE)
--
-- Variante A: Nur Absender + Betreff (schnell, klein)
-- ALTER TABLE mails ADD FULLTEXT INDEX ft_mails_meta (sender_email, subject);
--
-- Variante B: Inkl. Content (langsamer zu erstellen, aber mächtig)
-- ALTER TABLE mails ADD FULLTEXT INDEX ft_mails_full (sender_email, subject, content);
--
-- Wenn ihr Variante B nutzt, kann die Suche in der PHP-Seite so aussehen:
--
--   if (!empty($search)) {
--       $where_parts[] = "MATCH(m.sender_email, m.subject, m.content) AGAINST(? IN BOOLEAN MODE)";
--       $bind_types .= 's';
--       $bind_values[] = $search;
--   }


-- ────────────────────────────────────────────────────────────
-- ZUSAMMENFASSUNG
-- ────────────────────────────────────────────────────────────
-- 
-- ✓ BEHALTEN (aus database2.sql):
--   mails:          idx_job_processed, idx_sender, idx_subject, idx_processed_created
--   backup_results: idx_job_date_time, idx_date_status, idx_mail
--   backup_jobs:    idx_customer
--   customers:      idx_name, idx_number
--   status_duration: idx_status, idx_job_status
--
-- ✗ ENTFERNT (redundant):
--   mails:          idx_result_processed, idx_created
--   backup_results: idx_job_date
--
-- ★ NEU HINZUGEFÜGT:
--   mails:          idx_date, idx_date_range
--   backup_jobs:    idx_name
--   instructions:   idx_category, idx_title
--   mail_filter:    idx_is_active, idx_active_search
--
-- Gesamt: 3 entfernt, 7 hinzugefügt = netto +4 Indizes
-- ────────────────────────────────────────────────────────────