<?php
// Config einbinden
$config = require_once 'config.php';

// Datenbankverbindung herstellen
$conn = new mysqli(
    $config['server'],
    $config['user'], 
    $config['password'],
    $config['database']
);

// Fehlerbehandlung
if ($conn->connect_error) {
    die('Verbindungsfehler: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// POST-Handler für Notizen-Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_note') {
    $response = ['success' => false];
    if (isset($_POST['id']) && isset($_POST['note'])) {
        $id = (int)$_POST['id'];
        $note = $conn->real_escape_string($_POST['note']);
        $sql = "UPDATE backup_results SET note = '$note' WHERE id = $id";
        if ($conn->query($sql)) {
            $response['success'] = true;
        }
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// NEUER POST-Handler für Mail-Inhalt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_mail_content') {
    $response = ['success' => false];
    if (isset($_POST['mail_id'])) {
        $mail_id = (int)$_POST['mail_id'];
        $sql = "SELECT content, subject, sender_email FROM mails WHERE id = $mail_id";
        $result = $conn->query($sql);
        if ($result && $row = $result->fetch_assoc()) {
            $response['success'] = true;
            $response['content'] = $row['content'];
            $response['subject'] = $row['subject'];
            $response['sender'] = $row['sender_email'];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// NEUE STATISTIKEN AUS status_duration TABELLE
$stats = [
    'total_status_messages' => 0,
    'total_backup_jobs' => 0,
    'success' => 0, 
    'warning' => 0, 
    'error' => 0
];

// Festlegen der zeitlichen Einschränkung für die Abfrage aus der Datenbank (letzte 30 Tage)
$daysToShow = 30;
$dateLimit = date('Y-m-d', strtotime("-$daysToShow days"));

// Holen der Gesamtstatistiken
$statsQuery = "
    SELECT 
        'total_status_messages' as stat_type, COUNT(*) as value 
    FROM backup_results
    WHERE date >= '$dateLimit'
    
    UNION ALL
    
    SELECT 
        'total_backup_jobs', COUNT(*) 
    FROM backup_jobs
    
    UNION ALL
    
    SELECT 
        CONCAT('status_', current_status), COUNT(*) 
    FROM status_duration 
    WHERE current_status IN ('success', 'warning', 'error')
    GROUP BY current_status
";

$statsResult = $conn->query($statsQuery);
$stats = [
    'total_status_messages' => 0,
    'total_backup_jobs' => 0,
    'status_success' => 0, 
    'status_warning' => 0, 
    'status_error' => 0
];

while ($row = $statsResult->fetch_assoc()) {
    if ($row['stat_type'] == 'status_success') {
        $stats['success'] = $row['value'];
    } elseif ($row['stat_type'] == 'status_warning') {
        $stats['warning'] = $row['value'];
    } elseif ($row['stat_type'] == 'status_error') {
        $stats['error'] = $row['value'];
    } else {
        $stats[$row['stat_type']] = $row['value'];
    }
}

// Alle verfügbaren Backup-Typen abrufen für den Filter
$backupTypesQuery = "SELECT DISTINCT backup_type FROM backup_jobs WHERE backup_type IS NOT NULL AND backup_type != '' ORDER BY backup_type";
$backupTypesResult = $conn->query($backupTypesQuery);
$backupTypes = [];
while ($row = $backupTypesResult->fetch_assoc()) {
    $backupTypes[] = $row['backup_type'];
}

// Daten für das Dashboard abrufen - MIT current_status aus status_duration
$query = "
    SELECT 
        c.id AS customer_id,
        c.name AS customer_name,
        c.number AS customer_number,
        c.note AS customer_note,
        c.created_at AS customer_created_at,
        bj.id AS job_id,
        bj.name AS job_name,
        bj.backup_type AS backup_type,
        br.id AS result_id,
        br.status,
        br.date,
        br.time,
        br.note AS result_note,
        br.size_mb,
        br.duration_minutes,
        br.mail_id,
        rc.runs_count,
        sd.current_status AS job_current_status
    FROM customers c
    LEFT JOIN backup_jobs bj ON c.id = bj.customer_id
    LEFT JOIN backup_results br ON bj.id = br.backup_job_id 
        AND br.date >= '$dateLimit'
    LEFT JOIN (
        SELECT 
            backup_job_id,
            date,
            COUNT(*) as runs_count
        FROM backup_results
        WHERE date >= '$dateLimit'
        GROUP BY backup_job_id, date
    ) rc ON rc.backup_job_id = bj.id AND rc.date = br.date
    LEFT JOIN status_duration sd ON bj.id = sd.backup_job_id
    ORDER BY c.name, bj.name, br.date DESC, br.time DESC
";

$result = $conn->query($query);

// Daten strukturieren
$dashboardData = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $customerId = $row['customer_id'];
        
        // Kunde initialisieren
        if (!isset($dashboardData[$customerId])) {
            $dashboardData[$customerId] = [
                'customer' => [
                    'id' => $row['customer_id'],
                    'name' => $row['customer_name'],
                    'number' => $row['customer_number'],
                    'note' => $row['customer_note'],
                    'created_at' => $row['customer_created_at']
                ],
                'jobs' => []
            ];
        }

        if ($row['job_id']) {
            $jobId = $row['job_id'];
            
            // Job initialisieren
            if (!isset($dashboardData[$customerId]['jobs'][$jobId])) {
                $dashboardData[$customerId]['jobs'][$jobId] = [
                    'job_id' => $row['job_id'],
                    'job_name' => $row['job_name'],
                    'backup_type' => $row['backup_type'],
                    'current_status' => $row['job_current_status'] ?? 'none',
                    'results' => []
                ];
            }

            // Backup-Ergebnis hinzufügen
            if ($row['result_id']) {
                $dashboardData[$customerId]['jobs'][$jobId]['results'][] = [
                    'id' => $row['result_id'],
                    'status' => $row['status'],
                    'date' => $row['date'],
                    'time' => $row['time'],
                    'note' => $row['result_note'],
                    'size_mb' => $row['size_mb'],
                    'duration_minutes' => $row['duration_minutes'],
                    'runs_count' => $row['runs_count'],
                    'mail_id' => $row['mail_id']
                ];
            }
        }
    }
}

// In Array umwandeln für einfachere Verarbeitung im Frontend
$dashboardData = array_values($dashboardData);
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup-Monitor</title>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-hover: #1d4ed8;
            --success-color: #059669;
            --success-light: #d1fae5;
            --warning-color: #d97706;
            --warning-light: #fef3c7;
            --error-color: #dc2626;
            --error-light: #fee2e2;
            --background-color: #f3f4f6;
            --card-background: #ffffff;
            --border-color: #e5e7eb;
            --text-color: #1f2937;
            --text-secondary: #6b7280;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.5;
            color: var(--text-color);
            background-color: var(--background-color);
            padding: 2rem;
            padding-bottom: 4rem;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            margin-bottom: 4rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        footer {
        position: fixed;
        bottom: 0;
        left: 0;
        width: 100%;
        text-align: center;
        background-color: white;
        border-top: 1px solid #e5e7eb;
        padding: 1rem 0;
        z-index: 100;
        color: #6b7280;
        }

        footer .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .footer-link {
            color: inherit;
            text-decoration: none;
        }

        h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-color);
        }

        .settings-btn {
            background-color: var(--primary-color);
            color: white !important;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            text-decoration: none;
            transition: background-color 0.2s;
        }

        .settings-btn:hover {
            background-color: var(--primary-hover);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-background);
            padding: 1rem;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .stat-card .detailed-stats {
            padding-top: 0.25rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            margin-top: 0.25rem;
        }
        
        /* Neue Styles für die detaillierte Statistik */
        .detailed-stats {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .detail-stat {
            display: flex;
            flex-direction: column;
        }
        
        .stat-row {
            display: flex;
            align-items: flex-end;
            gap: 0.75rem;
        }
        
        .stat-label-inline {
            font-size: 0.875rem;
            color: var(--text-secondary);
            padding-bottom: 0.25rem;
        }

        .stat-value.success { color: var(--success-color); }
        .stat-value.warning { color: var(--warning-color); }
        .stat-value.error { color: var(--error-color); }

        /* ===== ANKLICKBARE STATUS-KARTEN ===== */
        .stat-card.clickable {
            cursor: pointer;
            transition: all 0.2s ease;
            border: 2px solid transparent;
            position: relative;
        }
        
        .stat-card.clickable:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .stat-card.clickable.active-filter {
            border-width: 2px;
            border-style: solid;
        }
        
        .stat-card.clickable.active-filter.success-card {
            border-color: var(--success-color);
            background-color: var(--success-light);
        }
        
        .stat-card.clickable.active-filter.warning-card {
            border-color: var(--warning-color);
            background-color: var(--warning-light);
        }
        
        .stat-card.clickable.active-filter.error-card {
            border-color: var(--error-color);
            background-color: var(--error-light);
        }
        
        .stat-card.clickable .filter-indicator {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            font-size: 0.75rem;
            padding: 0.125rem 0.375rem;
            border-radius: 0.25rem;
            display: none;
        }
        
        .stat-card.clickable.active-filter .filter-indicator {
            display: block;
        }
        
        .stat-card.clickable.active-filter.success-card .filter-indicator {
            background-color: var(--success-color);
            color: white;
        }
        
        .stat-card.clickable.active-filter.warning-card .filter-indicator {
            background-color: var(--warning-color);
            color: white;
        }
        
        .stat-card.clickable.active-filter.error-card .filter-indicator {
            background-color: var(--error-color);
            color: white;
        }

        .customer-card {
            background: var(--card-background);
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .customer-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .customer-name {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .customer-number {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .job-container {
            margin-bottom: 1.5rem;
        }

        .job-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .job-name {
            font-weight: 500;
        }

        .job-type {
            background-color: var(--background-color);
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .results-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
        }

        .result-square {
            width: 2rem;
            height: 2rem;
            border-radius: 0.25rem;
            position: relative;
            cursor: pointer;
        }

        .result-count {
            position: absolute;
            top: -0.5rem;
            right: -0.5rem;
            background-color: var(--primary-color);
            color: white;
            width: 1rem;
            height: 1rem;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
        }

        .tooltip {
            display: none;
            position: absolute;
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 1rem;
            width: 20rem;
            z-index: 10;
        }

        .hover-date {
            position: absolute;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            pointer-events: none;
            z-index: 1000;
            white-space: nowrap;
            display: none;
        }

        .result-details {
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .detail-label {
            font-weight: 500;
        }

        .note-textarea {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 0.25rem;
            margin-bottom: 0.5rem;
            resize: vertical;
        }

        .save-note-btn {
            background-color: var(--primary-color);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 0.25rem;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .save-note-btn:hover {
            background-color: var(--primary-hover);
        }

        .buttons-container {
            position: relative;
            margin-top: 0.5rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .show-mail-btn {
            background-color: var(--text-secondary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 0.25rem;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .show-mail-btn:hover {
            background-color: var(--text-color);
        }

        .mail-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .mail-modal-content {
            background-color: white;
            border-radius: 0.5rem;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }

        .mail-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .mail-modal-header button {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
        }

        .mail-modal-header button:hover {
            color: var(--text-color);
        }

        .mail-modal-body {
            padding: 1rem;
            overflow-y: auto;
        }

        .mail-modal-body pre {
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 0.875rem;
        }

        .mail-modal-info {
            padding: 0.5rem 1rem;
            border-bottom: 1px solid var(--border-color);
            background-color: var(--background-color);
        }

        .mail-modal-info div {
            margin: 0.25rem 0;
        }

        .loading-spinner {
            text-align: center;
            padding: 2rem;
            color: var(--text-secondary);
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .tooltip {
                width: calc(100vw - 2rem);
                left: 50% !important;
                transform: translateX(-50%);
            }
        }

        /* ===== SUCHLEISTEN-STYLES ===== */
        .search-container {
            margin-bottom: 2rem;
        }
        
        .search-wrapper {
            display: flex;
            gap: 1rem;
            align-items: stretch;
        }
        
        .search-box {
            display: flex;
            flex: 1;
            position: relative;
            background: var(--card-background);
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        #searchInput {
            flex: 1;
            padding: 0.75rem 2.5rem 0.75rem 0.75rem;
            border: 1px solid var(--border-color);
            border-right: none;
            border-radius: 0.5rem 0 0 0.5rem;
            font-size: 1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        
        #searchInput:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
        }
        
        .clear-search-btn {
            position: absolute;
            right: 10.5rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--text-secondary);
            cursor: pointer;
            display: none;
            z-index: 5;
        }
        
        .clear-search-btn:hover {
            color: var(--text-color);
        }
        
        /* Suchmodus-Toggle */
        .search-mode-toggle {
            display: flex;
            border: 1px solid var(--border-color);
            border-left: none;
            border-radius: 0 0.5rem 0.5rem 0;
            overflow: hidden;
        }
        
        .search-mode-toggle label {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            cursor: pointer;
            background-color: var(--background-color);
            color: var(--text-secondary);
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
            border-left: 1px solid var(--border-color);
        }
        
        .search-mode-toggle label:first-of-type {
            border-left: none;
        }
        
        .search-mode-toggle input[type="radio"] {
            display: none;
        }
        
        .search-mode-toggle input[type="radio"]:checked + label {
            background-color: var(--primary-color);
            color: white;
        }
        
        .search-mode-toggle input[type="radio"]:not(:checked) + label:hover {
            background-color: var(--border-color);
        }
        
        /* Backup-Typ Filter */
        .filter-container {
            position: relative;
        }
        
        .filter-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: var(--card-background);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            cursor: pointer;
            font-size: 0.875rem;
            color: var(--text-color);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: all 0.2s;
            height: 100%;
            white-space: nowrap;
        }
        
        .filter-btn:hover {
            background-color: var(--background-color);
        }
        
        .filter-btn.has-filter {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .filter-btn svg {
            width: 1rem;
            height: 1rem;
        }
        
        .filter-dropdown {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            background: var(--card-background);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            min-width: 200px;
            z-index: 100;
            display: none;
        }
        
        .filter-dropdown.show {
            display: block;
        }
        
        .filter-dropdown-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            font-size: 0.875rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .filter-reset-btn {
            font-size: 0.75rem;
            color: var(--primary-color);
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
        }
        
        .filter-reset-btn:hover {
            text-decoration: underline;
        }
        
        .filter-options {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .filter-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            cursor: pointer;
            transition: background-color 0.15s;
        }
        
        .filter-option:hover {
            background-color: var(--background-color);
        }
        
        .filter-option input[type="checkbox"] {
            width: 1rem;
            height: 1rem;
            accent-color: var(--primary-color);
        }
        
        .filter-option label {
            cursor: pointer;
            font-size: 0.875rem;
            flex: 1;
        }
        
        /* Responsive Anpassungen */
        @media (max-width: 768px) {
            .search-wrapper {
                flex-direction: column;
            }
            
            .search-box {
                flex-direction: column;
            }
            
            #searchInput {
                border-radius: 0.5rem 0.5rem 0 0;
                border-right: 1px solid var(--border-color);
                border-bottom: none;
            }
            
            .search-mode-toggle {
                border-radius: 0 0 0.5rem 0.5rem;
                border-left: 1px solid var(--border-color);
                border-top: none;
            }
            
            .search-mode-toggle label:first-of-type {
                border-left: none;
            }
            
            .clear-search-btn {
                right: 0.75rem;
            }
            
            .filter-dropdown {
                left: 0;
                right: 0;
            }
        }
        
        .no-results {
            background: var(--card-background);
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            text-align: center;
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
        }
        
        /* Animation für einblenden/ausblenden der Kunden */
        .customer-card {
            transition: opacity 0.2s, transform 0.2s;
        }
        
        .customer-card.hidden {
            display: none;
        }
        
        /* Animation für einblenden/ausblenden einzelner Jobs */
        .job-container.hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>Backup Monitor</h1>
            <a href="./settings" class="settings-btn">Einstellungen</a>
        </div>

        <!-- Statistiken -->
        <div class="stats-grid">
            <div class="stat-card overview-card">
                <div class="detailed-stats" style="margin-top: -0.3rem;">
                    <div class="detail-stat">
                        <div class="stat-row">
                            <div class="stat-value" style="margin-top: 0;"><?php echo $stats['total_status_messages']; ?></div>
                            <div class="stat-label-inline">Statusmeldungen</div>
                        </div>
                    </div>
                    <div class="detail-stat">
                        <div class="stat-row">
                            <div class="stat-value" style="margin-top: 0;"><?php echo $stats['total_backup_jobs']; ?></div>
                            <div class="stat-label-inline">Backup-Jobs</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Anklickbare Status-Karten -->
            <div class="stat-card clickable success-card" data-status-filter="success" title="Klicken um nach erfolgreich zu filtern">
                <span class="filter-indicator">Aktiv</span>
                <div class="stat-label">Erfolgreich</div>
                <div class="stat-value success"><?php echo $stats['success']; ?></div>
            </div>
            <div class="stat-card clickable warning-card" data-status-filter="warning" title="Klicken um nach Warnungen zu filtern">
                <span class="filter-indicator">Aktiv</span>
                <div class="stat-label">Warnungen</div>
                <div class="stat-value warning"><?php echo $stats['warning']; ?></div>
            </div>
            <div class="stat-card clickable error-card" data-status-filter="error" title="Klicken um nach Fehlern zu filtern">
                <span class="filter-indicator">Aktiv</span>
                <div class="stat-label">Fehler</div>
                <div class="stat-value error"><?php echo $stats['error']; ?></div>
            </div>
        </div>

        <!-- Suchleiste mit Modus-Umschalter und Filter -->
        <div class="search-container">
            <div class="search-wrapper">
                <!-- Suchfeld mit Mode-Toggle -->
                <div class="search-box">
                    <input type="text" id="searchInput" placeholder="Nach Kundenname oder Kundennummer suchen..." autocomplete="off">
                    <button id="clearSearch" class="clear-search-btn">&times;</button>
                    
                    <!-- Suchmodus Toggle -->
                    <div class="search-mode-toggle">
                        <input type="radio" name="searchMode" id="modeCustomer" value="customer" checked>
                        <label for="modeCustomer">Kunden</label>
                        <input type="radio" name="searchMode" id="modeJobs" value="jobs">
                        <label for="modeJobs">Jobs</label>
                    </div>
                </div>
                
                <!-- Backup-Typ Filter -->
                <div class="filter-container">
                    <button class="filter-btn" id="filterBtn">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                        </svg>
                        <span id="filterBtnText">Backup-Typ</span>
                    </button>
                    
                    <div class="filter-dropdown" id="filterDropdown">
                        <div class="filter-dropdown-header">
                            <span>Backup-Typen</span>
                            <button class="filter-reset-btn" id="filterResetBtn">Zurücksetzen</button>
                        </div>
                        <div class="filter-options">
                            <?php foreach ($backupTypes as $type): ?>
                            <div class="filter-option">
                                <input type="checkbox" id="filter_<?php echo htmlspecialchars($type); ?>" value="<?php echo htmlspecialchars($type); ?>" class="backup-type-filter">
                                <label for="filter_<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Zähler für sichtbare Ergebnisse -->
            <div id="searchCounter" class="search-counter" style="color: var(--text-secondary); font-size: 0.875rem; margin-top: 0.5rem;"></div>
        </div>

        <!-- Kundenliste -->
        <?php foreach ($dashboardData as $customerData): ?>
            <div class="customer-card" 
                 data-customer-name="<?php echo htmlspecialchars(strtolower($customerData['customer']['name'])); ?>" 
                 data-customer-number="<?php echo htmlspecialchars(strtolower($customerData['customer']['number'])); ?>">
                <div class="customer-header">
                    <div class="customer-name"><?php echo htmlspecialchars($customerData['customer']['name']); ?></div>
                    <div class="customer-number">(<?php echo htmlspecialchars($customerData['customer']['number']); ?>)</div>
                </div>

                <?php foreach ($customerData['jobs'] as $job): ?>
                    <div class="job-container" 
                         data-job-name="<?php echo htmlspecialchars(strtolower($job['job_name'])); ?>" 
                         data-backup-type="<?php echo htmlspecialchars($job['backup_type']); ?>"
                         data-current-status="<?php echo htmlspecialchars($job['current_status']); ?>">
                    <div class="job-header">
                        <div class="job-name"><?php echo htmlspecialchars($job['job_name']); ?></div>
                        <div class="job-type"><?php echo htmlspecialchars($job['backup_type']); ?></div>
                    </div>

                    <div class="results-grid">
                        <?php 
                        // Generiere die letzten 30 Tage
                        $dates = [];
                        for ($i = 30; $i >= 0; $i--) {
                            $dates[] = date('Y-m-d', strtotime("-$i days"));
                        }

                        // Gruppiere Ergebnisse nach Datum
                        $groupedResults = [];
                        foreach ($job['results'] as $result) {
                            $date = $result['date'];
                            if (!isset($groupedResults[$date])) {
                                $groupedResults[$date] = [
                                    'results' => [],
                                    'status' => $result['status'],
                                    'time' => $result['time']
                                ];
                            }
                            $groupedResults[$date]['results'][] = $result;
                            if ($result['time'] > $groupedResults[$date]['time']) {
                                $groupedResults[$date]['status'] = $result['status'];
                                $groupedResults[$date]['time'] = $result['time'];
                            }
                        }

                        // Zeige (graue) Quadrate für alle Daten
                        foreach ($dates as $date) {
                            if (isset($groupedResults[$date])) {
                                $groupedResult = $groupedResults[$date];
                                ?>
                                <div class="result-square" 
                                    data-date="<?php echo $date; ?>"
                                    style="background-color: <?php 
                                        echo $groupedResult['status'] === 'success' ? 'var(--success-color)' : 
                                            ($groupedResult['status'] === 'warning' ? 'var(--warning-color)' : 
                                            'var(--error-color)'); 
                                    ?>"
                                    onclick="showTooltip(this, <?php echo htmlspecialchars(json_encode($groupedResult['results'])); ?>)">
                                    <?php if (count($groupedResult['results']) > 1): ?>
                                        <div class="result-count"><?php echo count($groupedResult['results']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php } else { ?>
                                <div class="result-square" style="background-color: #e5e7eb"></div>
                            <?php }
                        } ?>
                    </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <!-- Tooltip Template -->
        <div id="tooltip" class="tooltip"></div>
    </div>

    <script>
        let activeTooltip = null;
        let isTooltipLocked = false;
        let activeStatusFilter = null; // Neuer Status-Filter

        // Funktion zur Umrechnung von MB in GB mit 2 Nachkommastellen
        function formatSize(sizeMB) {
            if (!sizeMB) return '';
            const sizeGB = sizeMB / 1024;
            return sizeGB >= 1 
                ? `${sizeGB.toFixed(2)} GB`
                : `${parseFloat(sizeMB).toFixed(2)} MB`;
        }

        function showTooltip(element, results) {
            const tooltip = document.getElementById('tooltip');
            const rect = element.getBoundingClientRect();
            
            let tooltipContent = '';
                        
            // Füge die Details für jedes Ergebnis hinzu
            results.forEach((result, index) => {
                tooltipContent += `
                    <div class="result-details">
                        <div class="detail-row">
                            <span class="detail-label">Datum:</span>
                            <span>${result.date}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Zeit:</span>
                            <span>${result.time}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Status:</span>
                            <span style="color: ${
                                result.status === 'success' ? 'var(--success-color)' :
                                result.status === 'warning' ? 'var(--warning-color)' :
                                'var(--error-color)'
                            }">
                                ${result.status === 'success' ? 'Erfolgreich' :
                                result.status === 'warning' ? 'Warnung' : 'Fehler'}
                            </span>
                        </div>
                        ${result.size_mb ? `
                            <div class="detail-row">
                                <span class="detail-label">Größe:</span>
                                <span>${formatSize(result.size_mb)}</span>
                            </div>
                        ` : ''}
                        ${result.duration_minutes ? `
                            <div class="detail-row">
                                <span class="detail-label">Dauer:</span>
                                <span>${result.duration_minutes} min</span>
                            </div>
                        ` : ''}
                        <div class="buttons-container">
                            <textarea
                                class="note-textarea"
                                placeholder="Notiz..."
                                data-result-id="${result.id}"
                                onkeydown="handleNoteKeydown(event, this)"
                                onchange="saveNote(${result.id}, this.value)"
                            >${result.note || ''}</textarea>
                            <div class="action-buttons">
                                <button class="save-note-btn" onclick="saveNote(${result.id}, this.parentElement.previousElementSibling.value)">
                                    Speichern
                                </button>
                                ${result.mail_id ? `
                                    <button class="show-mail-btn" onclick="showMailContent(${result.mail_id}, ${result.id})">
                                        Mail anzeigen
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `;
            });

            tooltip.innerHTML = tooltipContent;
            tooltip.style.display = 'block';

            // Position des Tooltips berechnen
            const tooltipWidth = tooltip.offsetWidth;
            const viewportWidth = window.innerWidth;
            
            let left = rect.left + window.scrollX;
            if (left + tooltipWidth > viewportWidth - 20) {
                left = viewportWidth - tooltipWidth - 20;
            }

            tooltip.style.top = `${rect.bottom + window.scrollY + 10}px`;
            tooltip.style.left = `${left}px`;

            // Aktiven Tooltip speichern
            activeTooltip = tooltip;
            isTooltipLocked = true;
        }

        async function showMailContent(mailId, resultId) {
            // Erstelle Lade-Modal
            const loadingModal = document.createElement('div');
            loadingModal.className = 'mail-modal';
            loadingModal.innerHTML = `
                <div class="mail-modal-content">
                    <div class="loading-spinner">
                        <p>Lade Mail-Inhalt...</p>
                    </div>
                </div>
            `;
            document.body.appendChild(loadingModal);

            try {
                // Lade Mail-Inhalt per AJAX
                const formData = new FormData();
                formData.append('action', 'get_mail_content');
                formData.append('mail_id', mailId);

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (!result.success) {
                    throw new Error('Fehler beim Laden des Mail-Inhalts');
                }

                // Entferne Lade-Modal
                loadingModal.remove();

                // Erstelle Mail-Modal mit Inhalt
                const mailModal = document.createElement('div');
                mailModal.className = 'mail-modal';
                
                // Prüfen ob der Content HTML enthält
                const containsHTML = /<[a-z][\s\S]*>/i.test(result.content);
                
                // Content entsprechend aufbereiten
                const processedContent = containsHTML ? 
                    result.content : 
                    result.content
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');
                
                mailModal.innerHTML = `
                    <div class="mail-modal-content">
                        <div class="mail-modal-header">
                            <h3>Mail Inhalt (ResultID: ${resultId})</h3>
                            <button onclick="this.closest('.mail-modal').remove()">&times;</button>
                        </div>
                        <div class="mail-modal-info">
                            <div><strong>Betreff:</strong> ${result.subject || 'Kein Betreff'}</div>
                            <div><strong>Absender:</strong> ${result.sender || 'Keine Absenderadresse'}</div>
                        </div>
                        <div class="mail-modal-body">
                            ${containsHTML ? 
                                `<div class="html-content">${processedContent}</div>` : 
                                `<pre>${processedContent}</pre>`
                            }
                        </div>
                    </div>
                `;
                
                document.body.appendChild(mailModal);
                
                mailModal.addEventListener('click', (e) => {
                    if (e.target === mailModal) {
                        mailModal.remove();
                    }
                });

            } catch (error) {
                console.error('Error loading mail content:', error);
                loadingModal.remove();
                alert('Fehler beim Laden des Mail-Inhalts');
            }
        }

        // Neue Funktion für Tastatur-Events
        function handleNoteKeydown(event, textarea) {
            // Wenn ENTER gedrückt wird und nicht gleichzeitig SHIFT
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault(); // Verhindert den Zeilenumbruch
                saveNote(textarea.dataset.resultId, textarea.value);
            }
        }

        // Tooltip schließen wenn außerhalb geklickt wird
        document.addEventListener('click', (event) => {
            if (activeTooltip && !isTooltipLocked) {
                const tooltipElement = document.getElementById('tooltip');
                if (!tooltipElement.contains(event.target) && 
                    !event.target.classList.contains('result-square')) {
                    tooltipElement.style.display = 'none';
                    activeTooltip = null;
                }
            }
            isTooltipLocked = false;
        });

        async function saveNote(resultId, note) {
            const textarea = document.querySelector(`textarea[data-result-id="${resultId}"]`);
            const saveButton = textarea.nextElementSibling.querySelector('.save-note-btn');
            const originalButtonText = saveButton.textContent;
            
            try {
                saveButton.textContent = 'Speichere...';
                saveButton.disabled = true;
                
                const formData = new FormData();
                formData.append('action', 'update_note');
                formData.append('id', resultId);
                formData.append('note', note);

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                if (!result.success) {
                    throw new Error('Fehler beim Speichern der Notiz');
                }

                // Visuelle Bestätigung
                saveButton.textContent = '✓ Gespeichert';
                setTimeout(() => {
                    saveButton.textContent = originalButtonText;
                    saveButton.disabled = false;
                }, 2000);
                
            } catch (error) {
                console.error('Error saving note:', error);
                alert('Fehler beim Speichern der Notiz');
                saveButton.textContent = originalButtonText;
                saveButton.disabled = false;
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const clearButton = document.getElementById('clearSearch');
            const customerCards = document.querySelectorAll('.customer-card');
            const searchCounter = document.getElementById('searchCounter');
            const filterBtn = document.getElementById('filterBtn');
            const filterDropdown = document.getElementById('filterDropdown');
            const filterResetBtn = document.getElementById('filterResetBtn');
            const filterBtnText = document.getElementById('filterBtnText');
            const backupTypeFilters = document.querySelectorAll('.backup-type-filter');
            const modeCustomer = document.getElementById('modeCustomer');
            const modeJobs = document.getElementById('modeJobs');
            const statusCards = document.querySelectorAll('.stat-card.clickable');

            // Prefill search from URL query parameter "?search=..."
            const params = new URLSearchParams(window.location.search);
            const prefill = params.get('search');
            if (prefill) {
                searchInput.value = prefill;
            }

            // Placeholder je nach Modus aktualisieren
            function updatePlaceholder() {
                if (modeCustomer.checked) {
                    searchInput.placeholder = 'Nach Kundenname oder Kundennummer suchen...';
                } else {
                    searchInput.placeholder = 'Nach Jobname suchen...';
                }
            }

            // ===== STATUS-KARTEN KLICK-HANDLER =====
            statusCards.forEach(card => {
                card.addEventListener('click', () => {
                    const status = card.dataset.statusFilter;
                    
                    // Toggle: Wenn bereits aktiv, dann deaktivieren
                    if (activeStatusFilter === status) {
                        activeStatusFilter = null;
                        card.classList.remove('active-filter');
                    } else {
                        // Alle anderen deaktivieren
                        statusCards.forEach(c => c.classList.remove('active-filter'));
                        // Diesen aktivieren
                        activeStatusFilter = status;
                        card.classList.add('active-filter');
                    }
                    
                    filterAndSearch();
                });
            });

            // Filter-Dropdown öffnen/schließen
            filterBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                filterDropdown.classList.toggle('show');
            });

            // Dropdown schließen wenn außerhalb geklickt
            document.addEventListener('click', (e) => {
                if (!filterDropdown.contains(e.target) && e.target !== filterBtn) {
                    filterDropdown.classList.remove('show');
                }
            });

            // Filter zurücksetzen
            filterResetBtn.addEventListener('click', () => {
                backupTypeFilters.forEach(cb => cb.checked = false);
                filterBtn.classList.remove('has-filter');
                filterBtnText.textContent = 'Backup-Typ';
                filterAndSearch();
            });

            // Bei Änderung der Filter-Checkboxen
            backupTypeFilters.forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    const checkedFilters = document.querySelectorAll('.backup-type-filter:checked');
                    if (checkedFilters.length > 0) {
                        filterBtn.classList.add('has-filter');
                        filterBtnText.textContent = `Filter (${checkedFilters.length})`;
                    } else {
                        filterBtn.classList.remove('has-filter');
                        filterBtnText.textContent = 'Backup-Typ';
                    }
                    filterAndSearch();
                });
            });

            // Suchmodus wechseln
            modeCustomer.addEventListener('change', () => {
                updatePlaceholder();
                filterAndSearch();
            });
            
            modeJobs.addEventListener('change', () => {
                updatePlaceholder();
                filterAndSearch();
            });

            // Hauptfunktion für Suche und Filter
            function filterAndSearch() {
                const searchTerm = searchInput.value.toLowerCase().trim();
                const isCustomerMode = modeCustomer.checked;
                
                // Aktive Backup-Typ-Filter sammeln
                const activeTypeFilters = [];
                backupTypeFilters.forEach(cb => {
                    if (cb.checked) activeTypeFilters.push(cb.value.toLowerCase());
                });

                // Clear-Button anzeigen/verstecken
                clearButton.style.display = searchTerm ? 'block' : 'none';

                customerCards.forEach(card => {
                    const customerName = card.dataset.customerName || '';
                    const customerNumber = card.dataset.customerNumber || '';
                    const jobs = card.querySelectorAll('.job-container');
                    
                    let cardVisible = false;
                    let hasVisibleJobs = false;

                    if (isCustomerMode) {
                        // KUNDEN-MODUS: Suche nach Kundenname und Kundennummer
                        const customerMatches = searchTerm === '' || 
                            customerName.includes(searchTerm) || 
                            customerNumber.includes(searchTerm);

                        if (customerMatches) {
                            // Kunde passt zur Suche -> Jobs nach Filter filtern
                            jobs.forEach(job => {
                                const jobType = (job.dataset.backupType || '').toLowerCase();
                                const jobStatus = (job.dataset.currentStatus || '').toLowerCase();
                                
                                const jobMatchesTypeFilter = activeTypeFilters.length === 0 || activeTypeFilters.includes(jobType);
                                const jobMatchesStatusFilter = !activeStatusFilter || jobStatus === activeStatusFilter;
                                
                                if (jobMatchesTypeFilter && jobMatchesStatusFilter) {
                                    job.classList.remove('hidden');
                                    hasVisibleJobs = true;
                                } else {
                                    job.classList.add('hidden');
                                }
                            });
                            cardVisible = hasVisibleJobs || jobs.length === 0;
                        } else {
                            // Kunde passt nicht zur Suche
                            cardVisible = false;
                            jobs.forEach(job => job.classList.add('hidden'));
                        }
                    } else {
                        // JOBS-MODUS: Suche nach Jobname
                        jobs.forEach(job => {
                            const jobName = (job.dataset.jobName || '').toLowerCase();
                            const jobType = (job.dataset.backupType || '').toLowerCase();
                            const jobStatus = (job.dataset.currentStatus || '').toLowerCase();
                            
                            const jobMatchesSearch = searchTerm === '' || jobName.includes(searchTerm);
                            const jobMatchesTypeFilter = activeTypeFilters.length === 0 || activeTypeFilters.includes(jobType);
                            const jobMatchesStatusFilter = !activeStatusFilter || jobStatus === activeStatusFilter;
                            
                            if (jobMatchesSearch && jobMatchesTypeFilter && jobMatchesStatusFilter) {
                                job.classList.remove('hidden');
                                hasVisibleJobs = true;
                            } else {
                                job.classList.add('hidden');
                            }
                        });
                        cardVisible = hasVisibleJobs;
                    }

                    // Kundenkarte anzeigen/verstecken
                    if (cardVisible) {
                        card.classList.remove('hidden');
                    } else {
                        card.classList.add('hidden');
                    }
                });

                updateCounter();
            }

            // Zähler aktualisieren
            function updateCounter() {
                const totalCards = customerCards.length;
                const visibleCards = document.querySelectorAll('.customer-card:not(.hidden)').length;
                const totalJobs = document.querySelectorAll('.job-container').length;
                const visibleJobs = document.querySelectorAll('.job-container:not(.hidden)').length;
                
                const searchTerm = searchInput.value.trim();
                const hasActiveTypeFilter = document.querySelectorAll('.backup-type-filter:checked').length > 0;
                const hasAnyFilter = searchTerm !== '' || hasActiveTypeFilter || activeStatusFilter;
                
                if (!hasAnyFilter) {
                    searchCounter.textContent = '';
                } else {
                    let filterInfo = [];
                    if (searchTerm) filterInfo.push(`Suche: "${searchTerm}"`);
                    if (activeStatusFilter) {
                        const statusLabels = { success: 'Erfolgreich', warning: 'Warnungen', error: 'Fehler' };
                        filterInfo.push(`Status: ${statusLabels[activeStatusFilter]}`);
                    }
                    if (hasActiveTypeFilter) {
                        const count = document.querySelectorAll('.backup-type-filter:checked').length;
                        filterInfo.push(`${count} Backup-Typ(en)`);
                    }
                    
                    searchCounter.textContent = `${visibleCards} von ${totalCards} Kunden | ${visibleJobs} von ${totalJobs} Jobs | Filter: ${filterInfo.join(', ')}`;
                }
                
                // "Keine Ergebnisse" Anzeige
                let noResults = document.querySelector('.no-results');
                
                if (visibleCards === 0 && hasAnyFilter) {
                    if (!noResults) {
                        noResults = document.createElement('div');
                        noResults.className = 'no-results';
                        noResults.textContent = 'Keine Ergebnisse gefunden.';
                        
                        const firstCustomerCard = document.querySelector('.customer-card');
                        if (firstCustomerCard) {
                            firstCustomerCard.parentNode.insertBefore(noResults, firstCustomerCard);
                        }
                    }
                } else if (noResults) {
                    noResults.remove();
                }
            }

            // Event-Listener für Sucheingabe
            searchInput.addEventListener('input', filterAndSearch);
            
            clearButton.addEventListener('click', function() {
                searchInput.value = '';
                filterAndSearch();
                searchInput.focus();
            });

            // Initialer Aufruf
            updatePlaceholder();
            filterAndSearch();

            // Hover-Tooltip erstellen
            const hoverTooltip = document.createElement('div');
            hoverTooltip.className = 'hover-date';
            document.body.appendChild(hoverTooltip);

            // Event-Listener für alle result-square Elemente
            document.addEventListener('mouseover', function(e) {
                if (e.target.classList.contains('result-square')) {
                    const date = e.target.dataset.date;
                    if (date) {
                        const dateObj = new Date(date);
                        const weekday = dateObj.toLocaleDateString('de-DE', { weekday: 'long' });
                        const formattedDate = dateObj.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
                        
                        hoverTooltip.textContent = `${weekday} - ${formattedDate}`;
                        hoverTooltip.style.display = 'block';
                        
                        // Position berechnen mit Scroll-Offset
                        const rect = e.target.getBoundingClientRect();
                        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                        
                        hoverTooltip.style.left = (rect.left + window.scrollX) + 'px';
                        hoverTooltip.style.top = (rect.top + scrollTop - 25) + 'px';
                    }
                }
            });

            document.addEventListener('mouseout', function(e) {
                if (e.target.classList.contains('result-square')) {
                    hoverTooltip.style.display = 'none';
                }
            });
        });
    </script>

<footer class="fixed bottom-0 left-0 w-full bg-white border-t border-gray-200 py-4 z-10">
    <div class="container mx-auto text-center">
        Made with ❤️ by <a href="https://github.com/Herbertholzkopf/" class="footer-link">Andreas Koller - 54h Arbeitszeit (Stand 26.12.2025)</a>
    </div>
</footer>

</body>
</html>