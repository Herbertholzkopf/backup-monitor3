-- ============================================================
-- INDEX für Dashboard (start-index.php)
-- ============================================================
-- Dieser Index fehlt für die optimierte Dashboard-Hauptquery.
-- 
-- Vor dem Ausführen prüfen:
--   SHOW INDEX FROM backup_results;
-- ============================================================

USE backup_monitor3;

-- Die Dashboard-Query filtert auf br.date >= $dateLimit (letzte 30 Tage).
-- Ohne Index muss MySQL alle backup_results scannen und nach Datum filtern.
-- Prüfe ob dieser Index bereits durch die consolidated_indexes.sql 
-- oder database2.sql angelegt wurde — falls ja, überspringen.
ALTER TABLE backup_results ADD INDEX idx_date (date);

-- Falls idx_date_status aus database2.sql bereits existiert,
-- deckt dieser auch Abfragen nur auf date ab (date ist die erste Spalte).
-- In dem Fall ist idx_date redundant und kann weggelassen werden.
-- Prüfe mit: SHOW INDEX FROM backup_results WHERE Key_name = 'idx_date_status';