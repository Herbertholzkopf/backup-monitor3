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

// Session starten
if (!isset($_SESSION)) {
    session_start();
}

// POST-Verarbeitung
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'assign_job':
                $mail_id = (int)$_POST['mail_id'];
                $backup_job_id = (int)$_POST['backup_job_id'];
                
                // Backup-Ergebnis eintragen (ohne Status, Datum, Zeit - das wird später vom Verarbeitungsskript gemacht)
                $sql = "INSERT INTO backup_results (backup_job_id, mail_id) 
                        VALUES ($backup_job_id, $mail_id)";
                $conn->query($sql);
                
                // Mail als zugewiesen markieren, aber noch nicht als verarbeitet
                $conn->query("UPDATE mails SET job_found = TRUE, result_processed = FALSE WHERE id = $mail_id");
                
                $_SESSION['message'] = "E-Mail erfolgreich einem Backup-Job zugewiesen. Die Verarbeitung erfolgt automatisch.";
                $_SESSION['message_type'] = "success";
                break;

            case 'delete_mail':
                $mail_id = (int)$_POST['mail_id'];
                $conn->query("DELETE FROM mails WHERE id = $mail_id");
                
                $_SESSION['message'] = "E-Mail erfolgreich gelöscht.";
                $_SESSION['message_type'] = "success";
                break;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Such- und Filterparameter verarbeiten
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$sort_by = isset($_GET['sort_by']) ? $conn->real_escape_string($_GET['sort_by']) : 'created_at';
$sort_order = isset($_GET['sort_order']) ? $conn->real_escape_string($_GET['sort_order']) : 'DESC';

// Gültige Sortierfelder prüfen
$valid_sort_fields = ['sender_email', 'subject', 'created_at'];
if (!in_array($sort_by, $valid_sort_fields)) {
    $sort_by = 'created_at';
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

// Basis-SQL-Abfrage für E-Mails
$sql_base = "
    FROM mails 
    WHERE result_processed = FALSE
";

// Suchfilter anwenden
if (!empty($search)) {
    $sql_base .= " AND (
        sender_email LIKE '%$search%' OR 
        subject LIKE '%$search%' OR 
        content LIKE '%$search%'
    )";
}

// Gesamtanzahl der Ergebnisse für Paginierung ermitteln
$count_sql = "SELECT COUNT(*) AS total " . $sql_base;
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
    SELECT id, sender_email, subject, date, created_at, content 
    $sql_base
    ORDER BY $sort_by $sort_order
    LIMIT $offset, $items_per_page
";
$result = $conn->query($sql);

// Kunden abrufen
$customers_result = $conn->query("
    SELECT id, name
    FROM customers
    ORDER BY name
");
$customers = [];
while ($customer = $customers_result->fetch_assoc()) {
    $customers[$customer['id']] = $customer['name'];
}

// Backup-Jobs nach Kunden gruppiert abrufen
$backup_jobs_result = $conn->query("
    SELECT id, customer_id, name
    FROM backup_jobs
    ORDER BY name
");
$backup_jobs_by_customer = [];
while ($job = $backup_jobs_result->fetch_assoc()) {
    if (!isset($backup_jobs_by_customer[$job['customer_id']])) {
        $backup_jobs_by_customer[$job['customer_id']] = [];
    }
    $backup_jobs_by_customer[$job['customer_id']][$job['id']] = $job['name'];
}

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
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unverarbeitete E-Mails</title>
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

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: var(--danger-hover);
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
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
            border: 1px solid var(--border-color);
            border-right: none;
            padding: 0.5rem;
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

        .filter-group select {
            min-width: 150px;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            font-size: 0.875rem;
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

        tr:hover {
            background-color: var(--primary-light);
        }
        
        tr[onclick] {
            cursor: pointer;
        }

        .actions {
            display: flex;
            gap: 0.5rem;
            white-space: nowrap;
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
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-body {
            margin-bottom: 1.5rem;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .mail-info {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background-color: var(--background-color);
            border-radius: 0.375rem;
        }

        .mail-info p {
            margin: 0.5rem 0;
        }

        .mail-content {
            background-color: white;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            padding: 1rem;
            white-space: pre-wrap;
            font-family: monospace;
            max-height: 400px;
            overflow-y: auto;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.375rem;
            font-weight: 500;
            color: var(--text-color);
        }

        .required::after {
            content: " *";
            color: var(--danger-color);
        }

        select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            font-size: 0.875rem;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-secondary);
            cursor: pointer;
        }

        .close-btn:hover {
            color: var(--primary-color);
        }

        .alert {
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
        }

        .alert-success {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .alert-danger {
            background-color: rgba(220, 38, 38, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }
        
        .form-info {
            padding: 0.75rem;
            margin: 0.5rem 0 1rem;
            background-color: rgba(37, 99, 235, 0.05);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 0.375rem;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .responsive-table {
            overflow-x: auto;
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
            
            .actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Unverarbeitete E-Mails</h1>
        </header>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?= $_SESSION['message_type'] ?>">
                <?= $_SESSION['message'] ?>
            </div>
            <?php 
                unset($_SESSION['message']);
                unset($_SESSION['message_type']);
            ?>
        <?php endif; ?>

        <!-- Filter und Suchleiste -->
        <div class="filter-controls">
            <form method="get" class="search-box">
                <input type="text" name="search" placeholder="Suche..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i>
                </button>
            </form>
            
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
            
            <?php if (!empty($search)): ?>
                <a href="?" class="btn btn-secondary btn-sm">
                    <i class="fas fa-times"></i> Filter zurücksetzen
                </a>
            <?php endif; ?>
        </div>

        <!-- E-Mails Liste -->
        <div class="card">
            <h2>Unverarbeitete E-Mails</h2>
            
            <?php if ($total_items == 0): ?>
                <p>Keine unverarbeiteten E-Mails gefunden. <?= !empty($search) ? 'Versuche die Filtereinstellungen zu ändern.' : '' ?></p>
            <?php else: ?>
                <div class="responsive-table">
                    <table>
                        <thead>
                            <tr>
                                <th><a href="<?= getSortLink('sender_email', $sort_by, $sort_order) ?>">Absender<?= getSortIndicator('sender_email', $sort_by, $sort_order) ?></a></th>
                                <th><a href="<?= getSortLink('subject', $sort_by, $sort_order) ?>">Betreff<?= getSortIndicator('subject', $sort_by, $sort_order) ?></a></th>
                                <th><a href="<?= getSortLink('created_at', $sort_by, $sort_order) ?>">Empfangen am<?= getSortIndicator('created_at', $sort_by, $sort_order) ?></a></th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): 
                                  $mailData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                            ?>
                                <tr onclick='showMailDetails(<?= $mailData ?>)' style="cursor: pointer;">
                                    <td><?= htmlspecialchars($row['sender_email']) ?></td>
                                    <td><?= htmlspecialchars($row['subject']) ?></td>
                                    <td><?= date('d.m.Y H:i:s', strtotime($row['created_at'])) ?></td>
                                    <td class="actions" onclick="event.stopPropagation();">
                                        <button class="btn btn-secondary btn-sm" onclick='showAssignModal(<?= $row["id"] ?>)'>
                                            <i class="fas fa-link"></i> Zuweisen
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick='confirmDelete(<?= $row["id"] ?>, "<?= addslashes(htmlspecialchars($row["subject"])) ?>")'>
                                            <i class="fas fa-trash"></i> Löschen
                                        </button>
                                    </td>
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

        <!-- Modal für Mail-Details -->
        <div id="mailModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>E-Mail Details</h2>
                    <button class="close-btn" onclick="closeModal('mailModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="mail-info">
                        <p><strong>Absender:</strong> <span id="modalSender"></span></p>
                        <p><strong>Betreff:</strong> <span id="modalSubject"></span></p>
                        <p><strong>Empfangen am:</strong> <span id="modalDate"></span></p>
                    </div>
                    <div class="mail-content" id="modalContent"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('mailModal')">Schließen</button>
                    <button type="button" class="btn btn-secondary" id="modalAssignBtn">
                        <i class="fas fa-link"></i> Zuweisen
                    </button>
                    <button type="button" class="btn btn-danger" id="modalDeleteBtn">
                        <i class="fas fa-trash"></i> Löschen
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal für Job-Zuweisung -->
        <div id="assignModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>E-Mail einem Backup-Job zuweisen</h2>
                    <button class="close-btn" onclick="closeModal('assignModal')">&times;</button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="assign_job">
                    <input type="hidden" name="mail_id" id="assign_mail_id">
                    
                    <div class="form-group">
                        <label for="customer_id" class="required">Kunde:</label>
                        <select id="customer_id" name="customer_id" required onchange="loadBackupJobs()">
                            <option value="">Bitte Kunden wählen...</option>
                            <?php foreach ($customers as $id => $name): ?>
                                <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="backup_job_id" class="required">Backup-Job:</label>
                        <select id="backup_job_id" name="backup_job_id" required>
                            <option value="">Bitte zuerst Kunden wählen...</option>
                        </select>
                    </div>
                    
                    <!-- Status wird automatisch vom Verarbeitungsskript bestimmt -->
                    <p class="form-info">Hinweis: Nach der Zuweisung wird die E-Mail automatisch vom System verarbeitet, um weitere Details wie Status, Größe und Dauer zu ermitteln.</p>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('assignModal')">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Zuweisen</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Modal für Löschen -->
        <div id="deleteModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>E-Mail löschen</h2>
                    <button class="close-btn" onclick="closeModal('deleteModal')">&times;</button>
                </div>
                <p>Sind Sie sicher, dass Sie diese E-Mail löschen möchten?</p>
                <p>Betreff: "<span id="delete_mail_subject"></span>"</p>
                <p>Diese Aktion kann nicht rückgängig gemacht werden.</p>
                <form method="post">
                    <input type="hidden" name="action" value="delete_mail">
                    <input type="hidden" name="mail_id" id="delete_mail_id">
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Abbrechen</button>
                        <button type="submit" class="btn btn-danger">Löschen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Aktuelle Mail-ID für Modals
        let currentMailId = null;
        
        // Funktion zum Anzeigen der Mail-Details
        function showMailDetails(mailData) {
            currentMailId = mailData.id;
            
            document.getElementById('modalSender').textContent = mailData.sender_email;
            document.getElementById('modalSubject').textContent = mailData.subject;
            document.getElementById('modalDate').textContent = new Date(mailData.created_at).toLocaleString('de-DE');
            document.getElementById('modalContent').textContent = mailData.content;
        
        // Event-Listener für die Modal-Buttons hinzufügen
        document.getElementById('modalAssignBtn').onclick = function() {
                closeModal('mailModal');
                showAssignModal(currentMailId);
            };
            
            document.getElementById('modalDeleteBtn').onclick = function() {
                closeModal('mailModal');
                // Mail-Betreff für die Bestätigung holen
                const subject = document.getElementById('modalSubject').textContent;
                confirmDelete(currentMailId, subject);
            };
            
            document.getElementById('mailModal').style.display = 'block';
        }
        
        // Funktion zum Anzeigen des Zuweisungs-Modals
        function showAssignModal(mailId) {
            document.getElementById('assign_mail_id').value = mailId;
            document.getElementById('assignModal').style.display = 'block';
        }
        
        // Funktion zum Anzeigen des Lösch-Bestätigungsdialogs
        function confirmDelete(mailId, subject) {
            document.getElementById('delete_mail_id').value = mailId;
            document.getElementById('delete_mail_subject').textContent = subject;
            document.getElementById('deleteModal').style.display = 'block';
        }
        
        // Funktion zum Schließen von Modals
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Schließen von Modals wenn außerhalb geklickt wird
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Backup-Jobs nach Kunden
        const backupJobsByCustomer = <?= json_encode($backup_jobs_by_customer) ?>;

        // Funktion zum Laden der Backup-Jobs basierend auf dem ausgewählten Kunden
        function loadBackupJobs() {
            const customerId = document.getElementById('customer_id').value;
            const backupJobSelect = document.getElementById('backup_job_id');
            
            // Alle vorhandenen Optionen entfernen
            backupJobSelect.innerHTML = '';
            
            // Standard-Option hinzufügen
            if (!customerId) {
                const defaultOption = document.createElement('option');
                defaultOption.value = '';
                defaultOption.textContent = 'Bitte zuerst Kunden wählen...';
                backupJobSelect.appendChild(defaultOption);
                return;
            }
            
            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = 'Bitte Job wählen...';
            backupJobSelect.appendChild(defaultOption);
            
            // Jobs für den ausgewählten Kunden hinzufügen
            if (backupJobsByCustomer[customerId]) {
                for (const [jobId, jobName] of Object.entries(backupJobsByCustomer[customerId])) {
                    const option = document.createElement('option');
                    option.value = jobId;
                    option.textContent = jobName;
                    backupJobSelect.appendChild(option);
                }
            }
        }
        
        // Automatisches Ausblenden von Alert-Meldungen nach 5 Sekunden
        document.addEventListener('DOMContentLoaded', function() {
            var alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 1s';
                    setTimeout(function() {
                        alert.style.display = 'none';
                    }, 1000);
                }, 5000);
            });
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>