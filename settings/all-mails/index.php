<?php
// Config einbinden
$config = require_once '../../config.php';

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

// Such- und Filterparameter verarbeiten
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$customer_filter = isset($_GET['customer_filter']) ? (int)$_GET['customer_filter'] : 0;
$backup_job_filter = isset($_GET['backup_job_filter']) ? (int)$_GET['backup_job_filter'] : 0;
$status_filter = isset($_GET['status_filter']) ? $conn->real_escape_string($_GET['status_filter']) : '';
$date_from = isset($_GET['date_from']) ? $conn->real_escape_string($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? $conn->real_escape_string($_GET['date_to']) : '';
$processed_filter = isset($_GET['processed_filter']) ? $conn->real_escape_string($_GET['processed_filter']) : '';

$sort_by = isset($_GET['sort_by']) ? $conn->real_escape_string($_GET['sort_by']) : 'date';
$sort_order = isset($_GET['sort_order']) ? $conn->real_escape_string($_GET['sort_order']) : 'DESC';

// Gültige Sortierfelder prüfen
$valid_sort_fields = ['mail_date', 'sender_email', 'subject', 'backup_job_name', 'customer_name', 'backup_status'];
if (!in_array($sort_by, $valid_sort_fields)) {
    $sort_by = 'mail_date';
}

// Gültige Sortierreihenfolge prüfen
$valid_sort_orders = ['ASC', 'DESC'];
if (!in_array($sort_order, $valid_sort_orders)) {
    $sort_order = 'DESC';
}

// Paginierung
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$items_per_page = isset($_GET['items_per_page']) ? (int)$_GET['items_per_page'] : 50;
$offset = ($page - 1) * $items_per_page;

// Standardwerte für ungültige Eingaben
if ($page < 1) $page = 1;
if ($items_per_page < 50) $items_per_page = 50;
if ($items_per_page > 200) $items_per_page = 200;

// Kunden abrufen für Filter
$customers_result = $conn->query("SELECT id, name FROM customers ORDER BY name");
$customers = [];
while ($row = $customers_result->fetch_assoc()) {
    $customers[$row['id']] = $row['name'];
}

// Backup-Jobs abrufen für Filter
$jobs_result = $conn->query("SELECT id, name FROM backup_jobs ORDER BY name");
$jobs = [];
while ($row = $jobs_result->fetch_assoc()) {
    $jobs[$row['id']] = $row['name'];
}

// Basis-SQL-Abfrage für E-Mails mit LEFT JOINs
$sql_base = "
    FROM mails m
    LEFT JOIN backup_results br ON br.mail_id = m.id
    LEFT JOIN backup_jobs bj ON br.backup_job_id = bj.id OR bj.id IS NULL
    LEFT JOIN customers c ON bj.customer_id = c.id OR c.id IS NULL
    WHERE 1=1
";

// Suchfilter anwenden
if (!empty($search)) {
    $sql_base .= " AND (
        m.sender_email LIKE '%$search%' OR 
        m.subject LIKE '%$search%' OR 
        m.content LIKE '%$search%' OR
        bj.name LIKE '%$search%' OR
        c.name LIKE '%$search%'
    )";
}

// Kundenfilter anwenden
if ($customer_filter > 0) {
    $sql_base .= " AND c.id = $customer_filter";
}

// Backup-Job-Filter anwenden
if ($backup_job_filter > 0) {
    $sql_base .= " AND bj.id = $backup_job_filter";
}

// Status-Filter anwenden
if (!empty($status_filter)) {
    $sql_base .= " AND br.status = '$status_filter'";
}

// Datum-Filter anwenden
if (!empty($date_from)) {
    $sql_base .= " AND m.date >= '$date_from 00:00:00'";
}
if (!empty($date_to)) {
    $sql_base .= " AND m.date <= '$date_to 23:59:59'";
}

// Verarbeitet-Filter anwenden
if ($processed_filter === '1') {
    $sql_base .= " AND m.result_processed = 1";
} elseif ($processed_filter === '0') {
    $sql_base .= " AND m.result_processed = 0";
}

// Gesamtanzahl der Ergebnisse für Paginierung ermitteln
$count_sql = "SELECT COUNT(DISTINCT m.id) AS total " . $sql_base;
$count_result = $conn->query($count_sql);
$total_items = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_items / $items_per_page);

// Wenn aktuelle Seite größer als Gesamtseitenanzahl, zur letzten Seite wechseln
if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
    $offset = ($page - 1) * $items_per_page;
}

// Daten mit Paginierung und Sortierung abrufen
$sql = "
    SELECT 
        m.id as mail_id,
        m.date as mail_date,
        m.sender_email,
        m.subject,
        m.result_processed,
        m.job_found,
        m.content,
        m.created_at as mail_created_at,
        bj.id as backup_job_id,
        bj.name as backup_job_name,
        bj.note as backup_job_note,
        bj.search_term_mail,
        bj.search_term_subject,
        bj.search_term_text,
        bj.search_term_text2,
        c.id as customer_id,
        c.name as customer_name,
        br.id as backup_result_id,
        br.status as backup_status,
        br.date as backup_date,
        br.time as backup_time,
        br.note as backup_note,
        br.size_mb,
        br.duration_minutes
    FROM (
        SELECT DISTINCT m.id, m.date, c.name as c_name, bj.name as bj_name, br.status
        $sql_base
        ORDER BY " . ($sort_by === 'customer_name' ? 'c_name' : 
                    ($sort_by === 'backup_job_name' ? 'bj_name' : 
                    ($sort_by === 'backup_status' ? 'br.status' : 
                    ($sort_by === 'mail_date' ? 'm.date' : "m.$sort_by")))) . " $sort_order
        LIMIT $offset, $items_per_page
    ) AS filtered_ids
    JOIN mails m ON m.id = filtered_ids.id
    LEFT JOIN backup_results br ON br.mail_id = m.id
    LEFT JOIN backup_jobs bj ON br.backup_job_id = bj.id OR bj.id IS NULL
    LEFT JOIN customers c ON bj.customer_id = c.id OR c.id IS NULL
";
$result = $conn->query($sql);

// Funktion zum Generieren von Sortierlinks
function getSortLink($field, $current_sort_by, $current_sort_order) {
    $params = $_GET;
    $params['sort_by'] = $field;
    $params['sort_order'] = ($current_sort_by === $field && $current_sort_order === 'ASC') ? 'DESC' : 'ASC';
    
    return '?' . http_build_query($params);
}

// Funktion zum Generieren von Paginierungslinks
function getPaginationLink($page_num) {
    $params = $_GET;
    $params['page'] = $page_num;
    
    return '?' . http_build_query($params);
}

// Funktion zum Generieren von Filter-Links
function getFilterLink($param_name, $param_value) {
    $params = $_GET;
    if ($param_value === '') {
        unset($params[$param_name]);
    } else {
        $params[$param_name] = $param_value;
    }
    $params['page'] = 1; // Bei Filteränderung zurück zur ersten Seite
    
    return '?' . http_build_query($params);
}

// Funktion zum Anzeigen des Sortierungspfeils
function getSortIndicator($field, $current_sort_by, $current_sort_order) {
    if ($current_sort_by === $field) {
        return ($current_sort_order === 'ASC') ? ' ▲' : ' ▼';
    }
    return '';
}

// Session-Nachrichten initialisieren, falls nicht vorhanden
if (!isset($_SESSION)) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Mail Übersicht</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-light: #dbeafe;
            --secondary-color: #4b5563;
            --danger-color: #dc2626;
            --danger-hover: #b91c1c;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --background-color: #f9fafb;
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
            padding: 1rem;
        }

        .container {
            width: 100%;
            margin: 0 auto;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-color);
        }

        h2 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-color);
        }

        .card {
            background: var(--card-background);
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1rem;
            padding: 1rem;
            background-color: var(--card-background);
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }

        .search-box {
            flex: 1;
            min-width: 200px;
            display: flex;
        }

        .search-box input {
            flex: 1;
            border-radius: 0.375rem 0 0 0.375rem;
            border-right: none;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            font-size: 0.875rem;
        }

        .search-box button {
            border-radius: 0 0.375rem 0.375rem 0;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-group select, .filter-group input {
            min-width: 150px;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            font-size: 0.875rem;
        }

        button, .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease-in-out;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-secondary:hover {
            background-color: var(--text-color);
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 1rem;
        }

        th {
            background-color: var(--background-color);
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        th a {
            color: var(--text-color);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }

        tr {
            cursor: pointer;
        }

        tr:hover {
            background-color: var(--primary-light);
        }

        .truncate {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 500;
            text-align: center;
        }

        .badge-success {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .badge-warning {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .badge-error {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
        }

        .badge-secondary {
            background-color: rgba(75, 85, 99, 0.1);
            color: var(--secondary-color);
        }

        .text-success {
            color: var(--success-color);
        }

        .text-danger {
            color: var(--danger-color);
        }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .pagination-info {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .pagination-controls {
            display: flex;
            gap: 0.25rem;
        }

        .pagination-controls a, .pagination-controls span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2rem;
            height: 2rem;
            padding: 0 0.5rem;
            border-radius: 0.375rem;
            background-color: var(--card-background);
            color: var(--text-color);
            text-decoration: none;
            font-size: 0.875rem;
            border: 1px solid var(--border-color);
        }

        .pagination-controls a:hover {
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .pagination-controls .active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: var(--card-background);
            padding: 1.5rem;
            border-radius: 0.5rem;
            width: 90%;
            max-width: 1000px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-section {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background-color: var(--background-color);
            border-radius: 0.375rem;
        }

        .modal-section h3 {
            margin-bottom: 1rem;
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-color);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }

        .info-item {
            margin-bottom: 0.5rem;
        }

        .info-item strong {
            display: inline-block;
            min-width: 120px;
            margin-right: 0.5rem;
        }

        .mail-content {
            background-color: white;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            padding: 1rem;
            white-space: pre-wrap;
            font-family: monospace;
            max-height: 300px;
            overflow-y: auto;
            margin-top: 0.5rem;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            color: white;
        }

        .status-success {
            background-color: var(--success-color);
        }

        .status-warning {
            background-color: var(--warning-color);
        }

        .status-error {
            background-color: var(--error-color);
        }

        .responsive-table {
            overflow-x: auto;
        }

        .filter-section {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .date-inputs {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .advanced-filters {
            display: none;
            padding-top: 1rem;
            border-top: 1px dashed var(--border-color);
            margin-top: 0.5rem;
        }
        
        .advanced-filters.show {
            display: block;
        }

        @media (max-width: 768px) {
            .filter-controls {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .search-box, .filter-group {
                width: 100%;
            }
            
            .card {
                padding: 1rem;
            }
            
            table th, table td {
                padding: 0.5rem;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>E-Mail Übersicht</h1>
        </header>

        <!-- Filter und Suchleiste -->
        <div class="filter-controls">
            <form method="get" class="search-box">
                <input type="text" name="search" placeholder="Suche..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i>
                </button>
            </form>
            
            <div class="filter-group">
                <label for="customer_filter">Kunde:</label>
                <select id="customer_filter" onchange="window.location=this.value">
                    <option value="<?= getFilterLink('customer_filter', '') ?>">Alle Kunden</option>
                    <?php foreach ($customers as $id => $name): ?>
                        <option value="<?= getFilterLink('customer_filter', $id) ?>" <?= $customer_filter == $id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="processed_filter">Verarbeitet:</label>
                <select id="processed_filter" onchange="window.location=this.value">
                    <option value="<?= getFilterLink('processed_filter', '') ?>">Alle</option>
                    <option value="<?= getFilterLink('processed_filter', '1') ?>" <?= $processed_filter === '1' ? 'selected' : '' ?>>Ja</option>
                    <option value="<?= getFilterLink('processed_filter', '0') ?>" <?= $processed_filter === '0' ? 'selected' : '' ?>>Nein</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="status_filter">Status:</label>
                <select id="status_filter" onchange="window.location=this.value">
                    <option value="<?= getFilterLink('status_filter', '') ?>">Alle Status</option>
                    <option value="<?= getFilterLink('status_filter', 'success') ?>" <?= $status_filter == 'success' ? 'selected' : '' ?>>Erfolgreich</option>
                    <option value="<?= getFilterLink('status_filter', 'warning') ?>" <?= $status_filter == 'warning' ? 'selected' : '' ?>>Warnung</option>
                    <option value="<?= getFilterLink('status_filter', 'error') ?>" <?= $status_filter == 'error' ? 'selected' : '' ?>>Fehler</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="items_per_page">Anzeigen:</label>
                <select id="items_per_page" onchange="window.location=this.value">
                    <?php foreach ([50, 100, 200] as $value): ?>
                        <option value="<?= getFilterLink('items_per_page', $value) ?>" <?= $items_per_page == $value ? 'selected' : '' ?>>
                            <?= $value ?> pro Seite
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button type="button" id="advanced-filters-toggle" class="btn btn-secondary btn-sm">
                <i class="fas fa-filter"></i> Erweiterte Filter
            </button>
            
            <?php if (!empty($search) || $customer_filter > 0 || $backup_job_filter > 0 || 
                      !empty($status_filter) || !empty($date_from) || !empty($date_to) || 
                      $processed_filter !== ''): ?>
                <a href="?" class="btn btn-secondary btn-sm">
                    <i class="fas fa-times"></i> Filter zurücksetzen
                </a>
            <?php endif; ?>
            
            <div id="advanced-filters" class="advanced-filters <?= (!empty($date_from) || !empty($date_to) || $backup_job_filter > 0) ? 'show' : '' ?>">
                <div class="filter-section">
                    <div class="filter-group">
                        <label for="date_from">Datum von:</label>
                        <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>" onchange="applyDateFilter()">
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_to">Datum bis:</label>
                        <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>" onchange="applyDateFilter()">
                    </div>
                    
                    <div class="filter-group">
                        <label for="backup_job_filter">Backup-Job:</label>
                        <select id="backup_job_filter" onchange="window.location=this.value">
                            <option value="<?= getFilterLink('backup_job_filter', '') ?>">Alle Jobs</option>
                            <?php foreach ($jobs as $id => $name): ?>
                                <option value="<?= getFilterLink('backup_job_filter', $id) ?>" <?= $backup_job_filter == $id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- E-Mail Liste -->
        <div class="card">
            <h2>E-Mail Liste</h2>
            
            <?php if ($total_items == 0): ?>
                <p>Keine E-Mails gefunden. <?= !empty($search) || $customer_filter > 0 || $backup_job_filter > 0 || !empty($status_filter) || !empty($date_from) || !empty($date_to) || $processed_filter !== '' ? 'Versuche die Filtereinstellungen zu ändern.' : '' ?></p>
            <?php else: ?>
                <div class="responsive-table">
                    <table>
                        <thead>
                            <tr>
                                <th><a href="<?= getSortLink('mail_date', $sort_by, $sort_order) ?>">Datum<?= getSortIndicator('mail_date', $sort_by, $sort_order) ?></a></th>
                                <th><a href="<?= getSortLink('sender_email', $sort_by, $sort_order) ?>">Absender<?= getSortIndicator('sender_email', $sort_by, $sort_order) ?></a></th>
                                <th><a href="<?= getSortLink('subject', $sort_by, $sort_order) ?>">Betreff<?= getSortIndicator('subject', $sort_by, $sort_order) ?></a></th>
                                <th><a href="<?= getSortLink('backup_job_name', $sort_by, $sort_order) ?>">Backup-Job<?= getSortIndicator('backup_job_name', $sort_by, $sort_order) ?></a></th>
                                <th><a href="<?= getSortLink('customer_name', $sort_by, $sort_order) ?>">Kunde<?= getSortIndicator('customer_name', $sort_by, $sort_order) ?></a></th>
                                <th><a href="<?= getSortLink('backup_status', $sort_by, $sort_order) ?>">Status<?= getSortIndicator('backup_status', $sort_by, $sort_order) ?></a></th>
                                <th>Verarbeitet</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): 
                                // JSON für JavaScript kodieren
                                $row_data = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                            ?>
                                <tr onclick='showDetails(<?= $row_data ?>)'>
                                    <td><?= date('d.m.Y H:i', strtotime($row['mail_date'])) ?></td>
                                    <td class="truncate" title="<?= htmlspecialchars($row['sender_email']) ?>">
                                        <?= htmlspecialchars($row['sender_email']) ?>
                                    </td>
                                    <td class="truncate" title="<?= htmlspecialchars($row['subject']) ?>">
                                        <?= htmlspecialchars($row['subject']) ?>
                                    </td>
                                    <td>
                                        <?= $row['backup_job_name'] ? htmlspecialchars($row['backup_job_name']) : 
                                            ($row['job_found'] ? '<span class="badge badge-secondary">Zugeordnet</span>' : '-') ?>
                                    </td>
                                    <td><?= $row['customer_name'] ? htmlspecialchars($row['customer_name']) : '-' ?></td>
                                    <td>
                                        <?php if ($row['backup_status']): ?>
                                            <span class="badge badge-<?= $row['backup_status'] === 'success' ? 'success' : 
                                                                     ($row['backup_status'] === 'warning' ? 'warning' : 'error') ?>">
                                                <?= $row['backup_status'] === 'success' ? 'Erfolgreich' : 
                                                   ($row['backup_status'] === 'warning' ? 'Warnung' : 'Fehler') ?>
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $row['result_processed'] ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-danger"></i>' ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginierung -->
                <div class="pagination">
                    <div class="pagination-info">
                        Zeige <?= ($offset + 1) ?>-<?= min($offset + $items_per_page, $total_items) ?> von <?= $total_items ?> Einträgen
                    </div>
                    <div class="pagination-controls">
                        <?php if ($page > 1): ?>
                            <a href="<?= getPaginationLink(1) ?>"><i class="fas fa-angle-double-left"></i></a>
                            <a href="<?= getPaginationLink($page - 1) ?>"><i class="fas fa-angle-left"></i></a>
                        <?php endif; ?>
                        
                        <?php
                        $range = 2;
                        $start_page = max(1, $page - $range);
                        $end_page = min($total_pages, $page + $range);
                        
                        if ($start_page > 1) {
                            echo '<span>...</span>';
                        }
                        
                        for ($i = $start_page; $i <= $end_page; $i++) {
                            if ($i == $page) {
                                echo "<span class=\"active\">$i</span>";
                            } else {
                                echo "<a href=\"" . getPaginationLink($i) . "\">$i</a>";
                            }
                        }
                        
                        if ($end_page < $total_pages) {
                            echo '<span>...</span>';
                        }
                        
                        if ($page < $total_pages): ?>
                            <a href="<?= getPaginationLink($page + 1) ?>"><i class="fas fa-angle-right"></i></a>
                            <a href="<?= getPaginationLink($total_pages) ?>"><i class="fas fa-angle-double-right"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal für E-Mail Details -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>E-Mail Details</h2>
                <button type="button" class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            
            <!-- E-Mail Informationen -->
            <div class="modal-section">
                <h3>E-Mail Informationen</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <strong>Datum:</strong> <span id="mailDate"></span>
                    </div>
                    <div class="info-item">
                        <strong>Absender:</strong> <span id="mailSender"></span>
                    </div>
                    <div class="info-item">
                        <strong>Betreff:</strong> <span id="mailSubject"></span>
                    </div>
                    <div class="info-item">
                        <strong>Verarbeitet:</strong> <span id="mailProcessed"></span>
                    </div>
                </div>
                <div class="info-item">
                    <strong>Inhalt:</strong>
                    <div class="mail-content" id="mailContent"></div>
                </div>
            </div>

            <!-- Backup-Job Informationen -->
            <div class="modal-section">
                <h3>Backup-Job Informationen</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <strong>Name:</strong> <span id="jobName"></span>
                    </div>
                    <div class="info-item">
                        <strong>Kunde:</strong> <span id="customerName"></span>
                    </div>
                    <div class="info-item">
                        <strong>E-Mail Suchwort:</strong> <span id="searchTermMail"></span>
                    </div>
                    <div class="info-item">
                        <strong>Betreff Suchwort:</strong> <span id="searchTermSubject"></span>
                    </div>
                    <div class="info-item">
                        <strong>Text Suchwort 1:</strong> <span id="searchTermText"></span>
                    </div>
                    <div class="info-item">
                        <strong>Text Suchwort 2:</strong> <span id="searchTermText2"></span>
                    </div>
                </div>
                <div class="info-item">
                    <strong>Notiz:</strong> <span id="jobNote"></span>
                </div>
            </div>

            <!-- Backup-Ergebnis Informationen -->
            <div class="modal-section">
                <h3>Backup-Ergebnis</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <strong>Status:</strong> <span id="backupStatus"></span>
                    </div>
                    <div class="info-item">
                        <strong>Datum:</strong> <span id="backupDate"></span>
                    </div>
                    <div class="info-item">
                        <strong>Uhrzeit:</strong> <span id="backupTime"></span>
                    </div>
                    <div class="info-item">
                        <strong>Größe:</strong> <span id="backupSize"></span>
                    </div>
                    <div class="info-item">
                        <strong>Dauer:</strong> <span id="backupDuration"></span>
                    </div>
                </div>
                <div class="info-item">
                    <strong>Notiz:</strong> <span id="backupNote"></span>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle für erweiterte Filter
        document.getElementById('advanced-filters-toggle').addEventListener('click', function() {
            var advancedFilters = document.getElementById('advanced-filters');
            advancedFilters.classList.toggle('show');
        });
        
        // Datum-Filter anwenden
        function applyDateFilter() {
            var dateFrom = document.getElementById('date_from').value;
            var dateTo = document.getElementById('date_to').value;
            
            var url = new URL(window.location.href);
            
            if (dateFrom) {
                url.searchParams.set('date_from', dateFrom);
            } else {
                url.searchParams.delete('date_from');
            }
            
            if (dateTo) {
                url.searchParams.set('date_to', dateTo);
            } else {
                url.searchParams.delete('date_to');
            }
            
            // Zurück zur ersten Seite bei Filteränderung
            url.searchParams.set('page', 1);
            
            window.location.href = url.toString();
        }
        
        // E-Mail Details anzeigen
        function showDetails(data) {
            // E-Mail Informationen
            document.getElementById('mailDate').textContent = new Date(data.mail_date).toLocaleString('de-DE');
            document.getElementById('mailSender').textContent = data.sender_email || '-';
            document.getElementById('mailSubject').textContent = data.subject || '-';
            document.getElementById('mailProcessed').textContent = data.result_processed == 1 ? 'Ja' : 'Nein';
            document.getElementById('mailContent').textContent = data.content || 'Kein Inhalt verfügbar';

            // Backup-Job Informationen
            document.getElementById('jobName').textContent = data.backup_job_name || '-';
            document.getElementById('customerName').textContent = data.customer_name || '-';
            document.getElementById('jobNote').textContent = data.backup_job_note || '-';
            document.getElementById('searchTermMail').textContent = data.search_term_mail || '-';
            document.getElementById('searchTermSubject').textContent = data.search_term_subject || '-';
            document.getElementById('searchTermText').textContent = data.search_term_text || '-';
            document.getElementById('searchTermText2').textContent = data.search_term_text2 || '-';

            // Backup-Ergebnis Informationen
            const status = data.backup_status;
            const statusSpan = document.getElementById('backupStatus');
            if (status) {
                let statusText = status === 'success' ? 'Erfolgreich' : 
                                (status === 'warning' ? 'Warnung' : 'Fehler');
                let statusClass = status === 'success' ? 'success' : 
                                (status === 'warning' ? 'warning' : 'error');
                statusSpan.innerHTML = `<span class="status-badge status-${statusClass}">${statusText}</span>`;
            } else {
                statusSpan.textContent = '-';
            }

            document.getElementById('backupDate').textContent = data.backup_date ? 
                new Date(data.backup_date).toLocaleDateString('de-DE') : '-';
            document.getElementById('backupTime').textContent = data.backup_time || '-';
            document.getElementById('backupNote').textContent = data.backup_note || '-';
            document.getElementById('backupSize').textContent = data.size_mb ? 
                `${data.size_mb} MB` : '-';
            document.getElementById('backupDuration').textContent = data.duration_minutes ? 
                `${data.duration_minutes} Minuten` : '-';

            document.getElementById('detailModal').style.display = 'block';
        }

        // Modal schließen
        function closeModal() {
            document.getElementById('detailModal').style.display = 'none';
        }

        // Schließen des Modals wenn außerhalb geklickt wird
        window.onclick = function(event) {
            if (event.target == document.getElementById('detailModal')) {
                closeModal();
            }
        }
    </script>
</body>
</html>
<?php
$conn->close();
?>