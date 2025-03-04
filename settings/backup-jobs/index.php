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

// POST-Verarbeitung
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $customer_id = (int)$_POST['customer_id'];
                $name = $conn->real_escape_string($_POST['name']);
                $note = $conn->real_escape_string($_POST['note']);
                $backup_type = $conn->real_escape_string($_POST['backup_type']);
                $search_term_mail = $conn->real_escape_string($_POST['search_term_mail']);
                $search_term_subject = $conn->real_escape_string($_POST['search_term_subject']);
                $search_term_text = $conn->real_escape_string($_POST['search_term_text']);
                $search_term_text2 = $conn->real_escape_string($_POST['search_term_text2'] ?? '');
                
                $sql = "INSERT INTO backup_jobs (customer_id, name, note, backup_type, 
                        search_term_mail, search_term_subject, search_term_text, search_term_text2) 
                        VALUES ($customer_id, '$name', '$note', '$backup_type', 
                        '$search_term_mail', '$search_term_subject', '$search_term_text', '$search_term_text2')";
                $conn->query($sql);
                
                $_SESSION['message'] = "Backup-Job erfolgreich erstellt.";
                $_SESSION['message_type'] = "success";
                break;

            case 'edit':
                $id = (int)$_POST['id'];
                $customer_id = (int)$_POST['customer_id'];
                $name = $conn->real_escape_string($_POST['name']);
                $note = $conn->real_escape_string($_POST['note']);
                $backup_type = $conn->real_escape_string($_POST['backup_type']);
                $search_term_mail = $conn->real_escape_string($_POST['search_term_mail']);
                $search_term_subject = $conn->real_escape_string($_POST['search_term_subject']);
                $search_term_text = $conn->real_escape_string($_POST['search_term_text']);
                $search_term_text2 = $conn->real_escape_string($_POST['search_term_text2'] ?? '');

                $sql = "UPDATE backup_jobs SET 
                        customer_id=$customer_id, 
                        name='$name', 
                        note='$note', 
                        backup_type='$backup_type',
                        search_term_mail='$search_term_mail',
                        search_term_subject='$search_term_subject',
                        search_term_text='$search_term_text',
                        search_term_text2='$search_term_text2'
                        WHERE id=$id";
                $conn->query($sql);
                
                $_SESSION['message'] = "Backup-Job erfolgreich aktualisiert.";
                $_SESSION['message_type'] = "success";
                break;

            case 'delete':
                $id = (int)$_POST['id'];
                $sql = "DELETE FROM backup_jobs WHERE id=$id";
                $conn->query($sql);
                
                $_SESSION['message'] = "Backup-Job erfolgreich gelöscht.";
                $_SESSION['message_type'] = "success";
                break;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Such- und Filterparameter verarbeiten
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$customer_filter = isset($_GET['customer_filter']) ? (int)$_GET['customer_filter'] : 0;
$backup_type_filter = isset($_GET['backup_type_filter']) ? $conn->real_escape_string($_GET['backup_type_filter']) : '';
$sort_by = isset($_GET['sort_by']) ? $conn->real_escape_string($_GET['sort_by']) : 'name';
$sort_order = isset($_GET['sort_order']) ? $conn->real_escape_string($_GET['sort_order']) : 'ASC';

// Gültige Sortierfelder prüfen
$valid_sort_fields = ['name', 'customer_name', 'backup_type'];
if (!in_array($sort_by, $valid_sort_fields)) {
    $sort_by = 'name';
}

// Gültige Sortierreihenfolge prüfen
$valid_sort_orders = ['ASC', 'DESC'];
if (!in_array($sort_order, $valid_sort_orders)) {
    $sort_order = 'ASC';
}

// Paginierung
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$items_per_page = isset($_GET['items_per_page']) ? (int)$_GET['items_per_page'] : 10;
$offset = ($page - 1) * $items_per_page;

// Standardwerte für ungültige Eingaben
if ($page < 1) $page = 1;
if ($items_per_page < 50) $items_per_page = 50;
if ($items_per_page > 200) $items_per_page = 200;

// Backup-Typen für Filter abrufen
$backup_types_result = $conn->query("SELECT DISTINCT backup_type FROM backup_jobs WHERE backup_type != '' ORDER BY backup_type");
$backup_types = [];
while ($type = $backup_types_result->fetch_assoc()) {
    $backup_types[] = $type['backup_type'];
}

// Basis-SQL-Abfrage für Backup-Jobs
$sql_base = "
    FROM backup_jobs b 
    LEFT JOIN customers c ON b.customer_id = c.id 
    WHERE 1=1
";

// Suchfilter anwenden
if (!empty($search)) {
    $sql_base .= " AND (
        b.name LIKE '%$search%' OR 
        c.name LIKE '%$search%' OR 
        b.backup_type LIKE '%$search%' OR 
        b.note LIKE '%$search%' OR
        b.search_term_mail LIKE '%$search%' OR
        b.search_term_subject LIKE '%$search%' OR
        b.search_term_text LIKE '%$search%'
    )";
}

// Kundenfilter anwenden
if ($customer_filter > 0) {
    $sql_base .= " AND b.customer_id = $customer_filter";
}

// Backup-Typ-Filter anwenden
if (!empty($backup_type_filter)) {
    $sql_base .= " AND b.backup_type = '$backup_type_filter'";
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
    SELECT b.*, c.name as customer_name 
    $sql_base
    ORDER BY " . ($sort_by === 'customer_name' ? 'c.name' : "b.$sort_by") . " $sort_order
    LIMIT $offset, $items_per_page
";
$result = $conn->query($sql);

// Kunden abrufen
$customers_result = $conn->query("SELECT id, name FROM customers ORDER BY name");
$customers = [];
while ($customer = $customers_result->fetch_assoc()) {
    $customers[$customer['id']] = $customer['name'];
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
    <title>Backup-Jobs Verwaltung</title>
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

        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        @media (min-width: 1024px) {
            .grid {
                grid-template-columns: 350px 1fr;
            }
        }

        .card {
            background: var(--card-background);
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
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

        input[type="text"], textarea, select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            font-size: 0.875rem;
            transition: border-color 0.15s ease-in-out;
        }

        input[type="text"]:focus, textarea:focus, select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        textarea {
            min-height: 80px;
            resize: vertical;
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
            border-right: none;
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

        .truncate {
            max-width: 150px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .actions {
            display: flex;
            gap: 0.5rem;
            white-space: nowrap;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 500;
            background-color: var(--primary-light);
            color: var(--primary-color);
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
            max-width: 600px;
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

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
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

        .btn-back {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.5rem;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.15s ease-in-out;
            border: 1px solid var(--border-color);
        }

        .btn-back:hover {
            color: var(--primary-color);
            border-color: var(--primary-color);
            background-color: rgba(37, 99, 235, 0.05);
        }

        header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        /* Ändere das bisherige header-Flexbox-Verhalten */
        header h1 {
            margin-right: auto;
        }

    </style>
</head>
<body>
    <div class="container">
    <header>
        <a href="../" class="btn-back">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h1>Backup-Jobs Verwaltung</h1>
        <button type="button" class="btn btn-primary" onclick="showAddModal()">
            <i class="fas fa-plus"></i> Neuen Job anlegen
        </button>
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
                <label for="backup_type_filter">Backup-Typ:</label>
                <select id="backup_type_filter" onchange="window.location=this.value">
                    <option value="<?= getFilterLink('backup_type_filter', '') ?>">Alle Typen</option>
                    <?php foreach ($backup_types as $type): ?>
                        <option value="<?= getFilterLink('backup_type_filter', $type) ?>" <?= $backup_type_filter == $type ? 'selected' : '' ?>>
                            <?= htmlspecialchars($type) ?>
                        </option>
                    <?php endforeach; ?>
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
            
            <?php if (!empty($search) || $customer_filter > 0 || !empty($backup_type_filter)): ?>
                <a href="?" class="btn btn-secondary btn-sm">
                    <i class="fas fa-times"></i> Filter zurücksetzen
                </a>
            <?php endif; ?>
        </div>

        <!-- Backup-Jobs Liste -->
        <div class="card">
            <h2>Backup-Jobs Liste</h2>
            
            <?php if ($total_items == 0): ?>
                <p>Keine Backup-Jobs gefunden. <?= !empty($search) || $customer_filter > 0 || !empty($backup_type_filter) ? 'Versuche die Filtereinstellungen zu ändern.' : '' ?></p>
            <?php else: ?>
                <div class="responsive-table">
                    <table>
                        <thead>
                            <tr>
                                <th><a href="<?= getSortLink('name', $sort_by, $sort_order) ?>">Name<?= getSortIndicator('name', $sort_by, $sort_order) ?></a></th>
                                <th><a href="<?= getSortLink('customer_name', $sort_by, $sort_order) ?>">Kunde<?= getSortIndicator('customer_name', $sort_by, $sort_order) ?></a></th>
                                <th><a href="<?= getSortLink('backup_type', $sort_by, $sort_order) ?>">Backup-Typ<?= getSortIndicator('backup_type', $sort_by, $sort_order) ?></a></th>
                                <th>Notiz</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['name']) ?></td>
                                    <td><?= htmlspecialchars($row['customer_name']) ?></td>
                                    <td>
                                        <?php if (!empty($row['backup_type'])): ?>
                                            <span class="badge"><?= htmlspecialchars($row['backup_type']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['note'])): ?>
                                            <div class="truncate" title="<?= htmlspecialchars($row['note']) ?>">
                                                <?= htmlspecialchars($row['note']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="actions">
                                        <button class="btn btn-primary btn-sm" onclick='editJob(<?= json_encode($row) ?>)'>
                                            <i class="fas fa-edit"></i> Bearbeiten
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick='confirmDelete(<?= $row["id"] ?>, "<?= addslashes(htmlspecialchars($row["name"])) ?>")'>
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

        <!-- Modal für neuen Backup-Job -->
        <div id="addModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Neuen Backup-Job anlegen</h2>
                    <button type="button" class="close-modal" onclick="closeModal('addModal')">&times;</button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label for="customer_id" class="required">Kunde:</label>
                        <select id="customer_id" name="customer_id" required>
                            <option value="">Bitte wählen...</option>
                            <?php foreach ($customers as $id => $name): ?>
                                <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="name" class="required">Name des Backup-Jobs:</label>
                        <input type="text" id="name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="backup_type">Backup-Typ:</label>
                        <input type="text" id="backup_type" name="backup_type" list="backup_type_list">
                        <datalist id="backup_type_list">
                            <?php foreach ($backup_types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label for="note">Notiz:</label>
                        <textarea id="note" name="note"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="search_term_mail" class="required">E-Mail Suchwort:</label>
                        <input type="text" id="search_term_mail" name="search_term_mail" required>
                    </div>
                    <div class="form-group">
                        <label for="search_term_subject" class="required">Betreff Suchwort:</label>
                        <input type="text" id="search_term_subject" name="search_term_subject" required>
                    </div>
                    <div class="form-group">
                        <label for="search_term_text" class="required">Text Suchwort 1:</label>
                        <input type="text" id="search_term_text" name="search_term_text" required>
                    </div>
                    <div class="form-group">
                        <label for="search_term_text2">Text Suchwort 2 (optional):</label>
                        <input type="text" id="search_term_text2" name="search_term_text2">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Backup-Job anlegen</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal für Bearbeiten -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Backup-Job bearbeiten</h2>
                    <button type="button" class="close-modal" onclick="closeModal('editModal')">&times;</button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="form-group">
                        <label for="edit_customer_id" class="required">Kunde:</label>
                        <select id="edit_customer_id" name="customer_id" required>
                            <?php foreach ($customers as $id => $name): ?>
                                <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_name" class="required">Name des Backup-Jobs:</label>
                        <input type="text" id="edit_name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_backup_type">Backup-Typ:</label>
                        <input type="text" id="edit_backup_type" name="backup_type" list="edit_backup_type_list">
                        <datalist id="edit_backup_type_list">
                            <?php foreach ($backup_types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label for="edit_note">Notiz:</label>
                        <textarea id="edit_note" name="note"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="edit_search_term_mail" class="required">E-Mail Suchwort:</label>
                        <input type="text" id="edit_search_term_mail" name="search_term_mail" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_search_term_subject" class="required">Betreff Suchwort:</label>
                        <input type="text" id="edit_search_term_subject" name="search_term_subject" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_search_term_text" class="required">Text Suchwort 1:</label>
                        <input type="text" id="edit_search_term_text" name="search_term_text" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_search_term_text2">Text Suchwort 2 (optional):</label>
                        <input type="text" id="edit_search_term_text2" name="search_term_text2">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Änderungen speichern</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Modal für Löschen -->
        <div id="deleteModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Backup-Job löschen</h2>
                    <button type="button" class="close-modal" onclick="closeModal('deleteModal')">&times;</button>
                </div>
                <p>Sind Sie sicher, dass Sie den Backup-Job "<span id="delete_job_name"></span>" löschen möchten?</p>
                <p>Diese Aktion kann nicht rückgängig gemacht werden.</p>
                <form method="post">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="delete_id">
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Abbrechen</button>
                        <button type="submit" class="btn btn-danger">Löschen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Funktion zum Anzeigen des Add-Modals
        function showAddModal() {
            document.getElementById('addModal').style.display = 'block';
        }
        
        // Funktion zum Anzeigen des Edit-Modals mit vorausgefüllten Daten
        function editJob(job) {
            document.getElementById('edit_id').value = job.id;
            document.getElementById('edit_customer_id').value = job.customer_id;
            document.getElementById('edit_name').value = job.name;
            document.getElementById('edit_backup_type').value = job.backup_type;
            document.getElementById('edit_note').value = job.note;
            document.getElementById('edit_search_term_mail').value = job.search_term_mail;
            document.getElementById('edit_search_term_subject').value = job.search_term_subject;
            document.getElementById('edit_search_term_text').value = job.search_term_text;
            document.getElementById('edit_search_term_text2').value = job.search_term_text2 || '';
            document.getElementById('editModal').style.display = 'block';
        }
        
        // Funktion zum Anzeigen des Lösch-Bestätigungsdialogs
        function confirmDelete(id, name) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_job_name').textContent = name;
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