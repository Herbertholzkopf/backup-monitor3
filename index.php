<?php
/**
 * BACKUP-MONITOR — Dashboard-Seite
 * 
 * Pfad:    /index.php (Root-Verzeichnis)
 * Includes: config.php, includes/styles.css, includes/app.js
 */

// ==========================================
// INCLUDES & DB
// ==========================================
$config = require_once 'config.php';
date_default_timezone_set('Europe/Berlin');

$conn = new mysqli(
    $config['server'],
    $config['user'], 
    $config['password'],
    $config['database']
);

if ($conn->connect_error) {
    die('Verbindungsfehler: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// ==========================================
// AJAX HANDLER (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'update_note':
            $id = intval($_POST['id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Ungültige ID']);
                exit;
            }
            $stmt = $conn->prepare("UPDATE backup_results SET note = ? WHERE id = ?");
            $stmt->bind_param("si", $note, $id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Notiz gespeichert']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Fehler beim Speichern']);
            }
            exit;

        case 'get_mail_content':
            $mail_id = intval($_POST['mail_id'] ?? 0);
            if ($mail_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Ungültige Mail-ID']);
                exit;
            }
            $stmt = $conn->prepare("SELECT content, subject, sender_email FROM mails WHERE id = ?");
            $stmt->bind_param("i", $mail_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                echo json_encode([
                    'success' => true,
                    'content' => $row['content'],
                    'subject' => $row['subject'],
                    'sender'  => $row['sender_email']
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Mail nicht gefunden']);
            }
            exit;
    }
    exit;
}

// ==========================================
// DATEN LADEN
// ==========================================

// Zeitliche Einschränkung (letzte 30 Tage)
$daysToShow = 30;
$dateLimit = date('Y-m-d', strtotime("-$daysToShow days"));

// Gesamtstatistiken
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
    'success' => 0,
    'warning' => 0,
    'error' => 0
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

$countGesamt = $stats['success'] + $stats['warning'] + $stats['error'];
$countOhneStatus = $stats['total_backup_jobs'] - $countGesamt;

// Alle verfügbaren Backup-Typen für den Filter
$backupTypesQuery = "SELECT DISTINCT backup_type FROM backup_jobs WHERE backup_type IS NOT NULL AND backup_type != '' ORDER BY backup_type";
$backupTypesResult = $conn->query($backupTypesQuery);
$backupTypes = [];
while ($row = $backupTypesResult->fetch_assoc()) {
    $backupTypes[] = $row['backup_type'];
}

// Dashboard-Daten abrufen
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
            
            if (!isset($dashboardData[$customerId]['jobs'][$jobId])) {
                $dashboardData[$customerId]['jobs'][$jobId] = [
                    'job_id' => $row['job_id'],
                    'job_name' => $row['job_name'],
                    'backup_type' => $row['backup_type'],
                    'current_status' => $row['job_current_status'] ?? 'none',
                    'results' => []
                ];
            }

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

$dashboardData = array_values($dashboardData);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup-Monitor</title>
    <!-- ===== Zentrale Einbindung ===== -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="includes/styles.css" rel="stylesheet">

    <!-- ===== Seiten-spezifische Styles ===== -->
    <style>
        /* Backup-Ergebnis Quadrate (30-Tage-Grid) */
        .results-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
        }

        .result-square {
            width: 2rem;
            height: 2rem;
            border-radius: var(--border-radius-sm);
            position: relative;
            cursor: pointer;
            transition: transform var(--transition-fast), box-shadow var(--transition-fast);
        }

        .result-square:hover {
            transform: scale(1.15);
            box-shadow: var(--shadow);
        }

        .result-square.bg-success { background-color: var(--color-success); }
        .result-square.bg-warning { background-color: var(--color-warning); }
        .result-square.bg-error   { background-color: var(--color-danger); }
        .result-square.bg-empty   { background-color: var(--color-gray-200); }

        .result-count {
            position: absolute;
            top: -0.5rem;
            right: -0.5rem;
            background-color: var(--color-primary);
            color: #ffffff;
            width: 1.125rem;
            height: 1.125rem;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.625rem;
            font-weight: 600;
        }

        /* Hover-Tooltip für Datum über den Quadraten */
        .hover-date {
            position: absolute;
            background: var(--color-gray-800);
            color: #ffffff;
            padding: 0.25rem 0.5rem;
            border-radius: var(--border-radius-sm);
            font-size: 0.75rem;
            pointer-events: none;
            z-index: 1000;
            white-space: nowrap;
            display: none;
        }

        /* Klickbarer Tooltip (Ergebnis-Details) */
        .result-tooltip {
            display: none;
            position: absolute;
            background: #ffffff;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--color-gray-200);
            padding: 1rem;
            width: 22rem;
            z-index: var(--z-dropdown);
        }

        .result-tooltip .result-details {
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--color-gray-200);
        }

        .result-tooltip .result-details:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        /* Stat-Card Übersicht: Zwei Werte nebeneinander */
        .stat-overview-row {
            display: flex;
            align-items: baseline;
            gap: 0.75rem;
        }

        .stat-overview-label {
            font-size: 0.8125rem;
            color: var(--color-gray-500);
            padding-bottom: 0.125rem;
        }

        /* Job-Container innerhalb der Kundenkarten */
        .job-container {
            margin-bottom: 1.25rem;
        }

        .job-container:last-child {
            margin-bottom: 0;
        }

        .job-container.hidden {
            display: none;
        }

        .customer-card.hidden {
            display: none;
        }

        /* Backup-Typ Filter Dropdown */
        .filter-container {
            position: relative;
        }

        .filter-dropdown {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            background: #ffffff;
            border: 1px solid var(--color-gray-200);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            min-width: 220px;
            z-index: var(--z-dropdown);
            display: none;
        }

        .filter-dropdown.show {
            display: block;
        }

        .filter-dropdown-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--color-gray-200);
            font-weight: 600;
            font-size: 0.875rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .filter-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            cursor: pointer;
            transition: background-color var(--transition-fast);
        }

        .filter-option:hover {
            background-color: var(--color-gray-50);
        }

        .filter-option input[type="checkbox"] {
            width: 1rem;
            height: 1rem;
            accent-color: var(--color-primary);
        }

        .filter-option label {
            cursor: pointer;
            font-size: 0.875rem;
            flex: 1;
        }

        /* Suchmodus Toggle */
        .search-mode-toggle {
            display: flex;
            overflow: hidden;
            border-radius: var(--border-radius);
            border: 1px solid var(--color-gray-200);
        }

        .search-mode-toggle label {
            display: flex;
            align-items: center;
            padding: 0.5rem 1rem;
            cursor: pointer;
            background-color: var(--color-gray-50);
            color: var(--color-gray-500);
            font-size: 0.875rem;
            font-weight: 500;
            transition: all var(--transition-fast);
            border-left: 1px solid var(--color-gray-200);
        }

        .search-mode-toggle label:first-of-type {
            border-left: none;
        }

        .search-mode-toggle input[type="radio"] {
            display: none;
        }

        .search-mode-toggle input[type="radio"]:checked + label {
            background-color: var(--color-primary);
            color: #ffffff;
        }

        .search-mode-toggle input[type="radio"]:not(:checked) + label:hover {
            background-color: var(--color-gray-200);
        }

        /* Filter-Button aktiver Zustand */
        .filter-btn-active {
            background-color: var(--color-primary) !important;
            color: #ffffff !important;
            border-color: var(--color-primary) !important;
        }

        /* Mail-Modal: pre-Formatierung */
        #mailModalBody pre {
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 0.875rem;
        }

        @media (max-width: 768px) {
            .result-tooltip {
                width: calc(100vw - 2rem);
                left: 50% !important;
                transform: translateX(-50%);
            }
        }

        /* 5-Spalten Grid responsive */
        @media (max-width: 1280px) {
            .stat-cards { grid-template-columns: repeat(3, 1fr) !important; }
        }
        @media (max-width: 768px) {
            .stat-cards { grid-template-columns: repeat(2, 1fr) !important; }
        }
        @media (max-width: 480px) {
            .stat-cards { grid-template-columns: 1fr !important; }
        }

        /* Ohne-Status Karte: active-filter Style */
        .stat-card.clickable.active-filter#card-none {
            border: 2px solid var(--color-gray-500);
            background-color: var(--color-gray-100);
        }

        /* Mail-Inhalt Iframe Sandbox */
        .mail-iframe {
            width: 100%;
            border: none;
            min-height: 300px;
            border-radius: var(--border-radius-sm);
            background: #ffffff;
        }
    </style>
</head>
<body>

    <div class="container mx-auto px-4 py-6">

        <!-- ============================================================
             SEITEN-HEADER
             ============================================================ -->
        <header class="page-header">
            <a href="https://manage.phd-it.de" class="back-button" title="Zur Kundenverwaltung">
                <i class="fas fa-users"></i>
            </a>
            <div class="page-header-title">
                <h1>Backup Monitor</h1>
                <p><?= $stats['total_status_messages'] ?> Statusmeldungen (<?= $daysToShow ?> Tage)</p>
            </div>
            <a href="./settings" class="btn btn-primary">
                <i class="fas fa-cog"></i> Einstellungen
            </a>
        </header>


        <!-- ============================================================
             STATUS-KACHELN
             ============================================================ -->
        <div class="stat-cards" style="grid-template-columns: repeat(5, 1fr);">
            <!-- Backup-Jobs (NICHT klickbar) -->
            <div class="stat-card">
                <div class="stat-card-content">
                    <div>
                        <p class="stat-card-label">Backup-Jobs</p>
                        <p class="stat-card-value text-gray-800"><?= $stats['total_backup_jobs'] ?></p>
                    </div>
                    <div class="stat-card-icon bg-blue-50">
                        <i class="fas fa-server text-blue-500"></i>
                    </div>
                </div>
            </div>

            <!-- Erfolgreich (klickbar) -->
            <div class="stat-card stat-card--success clickable"
                 onclick="filterByStatus('success')" id="card-success">
                <span class="filter-indicator">Filter aktiv</span>
                <div class="stat-card-content">
                    <div>
                        <p class="stat-card-label">Erfolgreich</p>
                        <p class="stat-card-value text-green-600"><?= $stats['success'] ?></p>
                    </div>
                    <div class="stat-card-icon bg-green-50">
                        <i class="fas fa-check-circle text-green-500"></i>
                    </div>
                </div>
            </div>

            <!-- Warnungen (klickbar) -->
            <div class="stat-card stat-card--warning clickable"
                 onclick="filterByStatus('warning')" id="card-warning">
                <span class="filter-indicator">Filter aktiv</span>
                <div class="stat-card-content">
                    <div>
                        <p class="stat-card-label">Warnungen</p>
                        <p class="stat-card-value text-orange-500"><?= $stats['warning'] ?></p>
                    </div>
                    <div class="stat-card-icon bg-orange-50">
                        <i class="fas fa-exclamation-triangle text-orange-500"></i>
                    </div>
                </div>
            </div>

            <!-- Fehler (klickbar) -->
            <div class="stat-card stat-card--danger clickable"
                 onclick="filterByStatus('error')" id="card-error">
                <span class="filter-indicator">Filter aktiv</span>
                <div class="stat-card-content">
                    <div>
                        <p class="stat-card-label">Fehler</p>
                        <p class="stat-card-value text-red-600"><?= $stats['error'] ?></p>
                    </div>
                    <div class="stat-card-icon bg-red-50">
                        <i class="fas fa-times-circle text-red-500"></i>
                    </div>
                </div>
            </div>

            <!-- Ohne Status (klickbar) -->
            <div class="stat-card clickable" style="border-color: transparent;"
                 onclick="filterByStatus('none')" id="card-none">
                <span class="filter-indicator" style="background-color: var(--color-gray-500); color: #fff;">Filter aktiv</span>
                <div class="stat-card-content">
                    <div>
                        <p class="stat-card-label">Ohne Status</p>
                        <p class="stat-card-value text-gray-500"><?= $countOhneStatus ?></p>
                    </div>
                    <div class="stat-card-icon bg-gray-100">
                        <i class="fas fa-question-circle text-gray-400"></i>
                    </div>
                </div>
            </div>
        </div>


        <!-- ============================================================
             SUCHLEISTE MIT FILTER
             ============================================================ -->
        <div class="content-card mb-6" style="padding: 1rem;">
            <div class="search-bar-inner">
                <div class="search-input-wrapper">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="searchInput" class="search-input"
                           placeholder="Nach Kundenname oder Kundennummer suchen..." autocomplete="off">
                </div>

                <!-- Suchmodus Toggle -->
                <div class="search-mode-toggle">
                    <input type="radio" name="searchMode" id="modeCustomer" value="customer" checked>
                    <label for="modeCustomer"><i class="fas fa-user mr-1"></i> Kunden</label>
                    <input type="radio" name="searchMode" id="modeJobs" value="jobs">
                    <label for="modeJobs"><i class="fas fa-briefcase mr-1"></i> Jobs</label>
                </div>

                <!-- Backup-Typ Filter -->
                <div class="filter-container">
                    <button class="btn btn-outline" id="filterBtn">
                        <i class="fas fa-filter"></i>
                        <span id="filterBtnText">Backup-Typ</span>
                    </button>
                    
                    <div class="filter-dropdown" id="filterDropdown">
                        <div class="filter-dropdown-header">
                            <span>Backup-Typen</span>
                            <button class="btn btn-ghost btn-sm" id="filterResetBtn" style="padding: 0;">Zurücksetzen</button>
                        </div>
                        <div class="filter-options custom-scroll" style="max-height: 300px; overflow-y: auto;">
                            <?php foreach ($backupTypes as $type): ?>
                            <div class="filter-option">
                                <input type="checkbox" id="filter_<?= htmlspecialchars($type) ?>"
                                       value="<?= htmlspecialchars($type) ?>" class="backup-type-filter">
                                <label for="filter_<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Zähler -->
            <div id="searchCounter" style="font-size: 0.8125rem; color: var(--color-gray-500); margin-top: 0.5rem;"></div>
        </div>


        <!-- ============================================================
             KUNDENLISTE
             ============================================================ -->
        <?php foreach ($dashboardData as $customerData): ?>
            <div class="content-card mb-4 customer-card"
                 data-customer-name="<?= htmlspecialchars(strtolower($customerData['customer']['name'])) ?>"
                 data-customer-number="<?= htmlspecialchars(strtolower($customerData['customer']['number'])) ?>">

                <div class="section-header" style="margin-bottom: 1.25rem;">
                    <h2 class="section-title"><?= htmlspecialchars($customerData['customer']['name']) ?></h2>
                    <span class="text-sm text-gray-400">(<?= htmlspecialchars($customerData['customer']['number']) ?>)</span>
                </div>

                <?php foreach ($customerData['jobs'] as $job): ?>
                    <div class="job-container"
                         data-job-name="<?= htmlspecialchars(strtolower($job['job_name'])) ?>"
                         data-backup-type="<?= htmlspecialchars($job['backup_type']) ?>"
                         data-current-status="<?= htmlspecialchars($job['current_status']) ?>">

                        <div class="flex items-center gap-2 mb-2">
                            <span class="font-medium text-gray-700"><?= htmlspecialchars($job['job_name']) ?></span>
                            <span class="badge badge-primary"><?= htmlspecialchars($job['backup_type']) ?></span>
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
                            foreach ($job['results'] as $r) {
                                $date = $r['date'];
                                if (!isset($groupedResults[$date])) {
                                    $groupedResults[$date] = [
                                        'results' => [],
                                        'status' => $r['status'],
                                        'time' => $r['time']
                                    ];
                                }
                                $groupedResults[$date]['results'][] = $r;
                                if ($r['time'] > $groupedResults[$date]['time']) {
                                    $groupedResults[$date]['status'] = $r['status'];
                                    $groupedResults[$date]['time'] = $r['time'];
                                }
                            }

                            foreach ($dates as $date):
                                if (isset($groupedResults[$date])):
                                    $gr = $groupedResults[$date];
                                    $colorClass = $gr['status'] === 'success' ? 'bg-success' :
                                                 ($gr['status'] === 'warning' ? 'bg-warning' : 'bg-error');
                            ?>
                                <div class="result-square <?= $colorClass ?>"
                                     data-date="<?= $date ?>"
                                     onclick="showResultTooltip(this, <?= htmlspecialchars(json_encode($gr['results'])) ?>)">
                                    <?php if (count($gr['results']) > 1): ?>
                                        <div class="result-count"><?= count($gr['results']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="result-square bg-empty" data-date="<?= $date ?>"></div>
                            <?php endif;
                            endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

    </div><!-- /container -->


    <!-- ============================================================
         TOOLTIP (Ergebnis-Details, positioniert per JS)
         ============================================================ -->
    <div id="resultTooltip" class="result-tooltip"></div>


    <!-- ============================================================
         MODAL: Mail-Inhalt anzeigen
         ============================================================ -->
    <div class="modal" id="mailModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">
                        <i class="fas fa-envelope text-blue-500"></i>
                        <span id="mailModalTitleText">Mail-Inhalt</span>
                    </h3>
                    <button class="modal-close" onclick="closeModal('mailModal')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body custom-scroll" id="mailModalBody">
                    <!-- wird per JS befüllt -->
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline" onclick="closeModal('mailModal')">Schließen</button>
                </div>
            </div>
        </div>
    </div>


    <!-- ============================================================
         FOOTER
         ============================================================ -->
    <footer class="app-footer">
        Made with ❤️ by <a href="https://github.com/Herbertholzkopf/">Andreas Koller - 59h Arbeitszeit (Stand 18.03.2026)</a>
    </footer>


    <!-- ============================================================
         JAVASCRIPT
         ============================================================ -->
    <script src="includes/app.js"></script>
    <script>
    /* ==========================================
       SEITEN-SPEZIFISCHES JAVASCRIPT
       ==========================================
       Nur Logik die NUR auf dieser Seite gebraucht wird.
       Alles Gemeinsame kommt aus app.js.
    */

    // --- Status-Filter für Stat-Cards ---
    let activeStatusFilter = '';

    function filterByStatus(status) {
        activeStatusFilter = (activeStatusFilter === status) ? '' : status;

        document.querySelectorAll('.stat-card.clickable').forEach(card => {
            card.classList.remove('active-filter');
        });

        if (activeStatusFilter) {
            const activeCard = document.getElementById('card-' + activeStatusFilter);
            if (activeCard) activeCard.classList.add('active-filter');
        }

        // filterAndSearch lebt im DOMContentLoaded-Scope, daher über window aufrufen
        if (typeof window.filterAndSearch === 'function') {
            window.filterAndSearch();
        }
    }


    // --- Ergebnis-Tooltip ---
    let tooltipLocked = false;

    function formatSize(sizeMB) {
        if (!sizeMB) return '';
        const sizeGB = sizeMB / 1024;
        return sizeGB >= 1
            ? sizeGB.toFixed(2) + ' GB'
            : parseFloat(sizeMB).toFixed(2) + ' MB';
    }

    function showResultTooltip(element, results) {
        const tooltip = document.getElementById('resultTooltip');
        const rect = element.getBoundingClientRect();

        const statusMap = {
            success: { label: 'Erfolgreich', badge: 'badge-success' },
            warning: { label: 'Warnung',     badge: 'badge-warning' },
            error:   { label: 'Fehler',      badge: 'badge-danger' }
        };

        let html = '';
        results.forEach(r => {
            const st = statusMap[r.status] || { label: r.status, badge: 'badge-gray' };
            html += `
                <div class="result-details">
                    <div class="detail-grid" style="margin-bottom: 0.75rem;">
                        <span class="label">Datum:</span>
                        <span class="value">${escHtml(r.date)}</span>
                        <span class="label">Zeit:</span>
                        <span class="value">${escHtml(r.time)}</span>
                        <span class="label">Status:</span>
                        <span class="value"><span class="badge ${st.badge}">${st.label}</span></span>
                        ${r.size_mb ? `
                            <span class="label">Größe:</span>
                            <span class="value">${formatSize(r.size_mb)}</span>
                        ` : ''}
                        ${r.duration_minutes ? `
                            <span class="label">Dauer:</span>
                            <span class="value">${r.duration_minutes} min</span>
                        ` : ''}
                    </div>
                    <div class="space-y-2">
                        <textarea class="form-textarea" style="min-height: 3rem; font-size: 0.8125rem;"
                                  placeholder="Notiz..."
                                  data-result-id="${r.id}"
                                  onkeydown="handleNoteKeydown(event, this)">${escHtml(r.note || '')}</textarea>
                        <div class="flex gap-2">
                            <button class="btn btn-primary btn-sm"
                                    onclick="saveResultNote(${r.id}, this.parentElement.previousElementSibling.value)">
                                <i class="fas fa-save"></i> Speichern
                            </button>
                            ${r.mail_id ? `
                                <button class="btn btn-secondary btn-sm"
                                        onclick="openMailModal(${r.mail_id}, ${r.id})">
                                    <i class="fas fa-envelope"></i> Mail
                                </button>
                            ` : ''}
                        </div>
                    </div>
                </div>`;
        });

        tooltip.innerHTML = html;
        tooltip.style.display = 'block';

        // Positionierung
        const tooltipWidth = tooltip.offsetWidth;
        const viewportWidth = window.innerWidth;

        let left = rect.left + window.scrollX;
        if (left + tooltipWidth > viewportWidth - 20) {
            left = viewportWidth - tooltipWidth - 20;
        }

        tooltip.style.top = (rect.bottom + window.scrollY + 10) + 'px';
        tooltip.style.left = left + 'px';

        tooltipLocked = true;
    }

    function handleNoteKeydown(event, textarea) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            saveResultNote(textarea.dataset.resultId, textarea.value);
        }
    }

    async function saveResultNote(resultId, note) {
        const result = await apiCall('update_note', {
            id: resultId,
            note: note
        });
        // apiCall zeigt automatisch Erfolgs-/Fehlermeldung
    }

    // Tooltip schließen bei Klick außerhalb
    document.addEventListener('click', (e) => {
        const tooltip = document.getElementById('resultTooltip');
        if (tooltipLocked) {
            tooltipLocked = false;
            return;
        }
        if (tooltip && !tooltip.contains(e.target) && !e.target.classList.contains('result-square')) {
            tooltip.style.display = 'none';
        }
    });


    // --- Mail-Modal ---
    async function openMailModal(mailId, resultId) {
        openModal('mailModal');
        showLoading('mailModalBody', 'Mail wird geladen...');
        document.getElementById('mailModalTitleText').textContent = 'Mail-Inhalt (Result #' + resultId + ' | Mail #' + mailId + ')';

        const result = await apiCall('get_mail_content', { mail_id: mailId });

        if (result.success) {
            const containsHTML = /<[a-z][\s\S]*>/i.test(result.content);

            let contentHtml;
            if (containsHTML) {
                // HTML-Mails in ein Iframe sandboxen, damit deren CSS nicht rausleakt
                const escaped = result.content
                    .replace(/&/g, '&amp;')
                    .replace(/"/g, '&quot;');
                contentHtml = '<iframe class="mail-iframe" sandbox="allow-same-origin" srcdoc="' + escaped + '" onload="this.style.height = this.contentDocument.body.scrollHeight + 20 + \'px\'"></iframe>';
            } else {
                contentHtml = '<pre style="white-space: pre-wrap; font-family: monospace; font-size: 0.875rem;">' + escHtml(result.content) + '</pre>';
            }

            hideLoading('mailModalBody', `
                <div class="space-y-4">
                    <div class="modal-card">
                        <h4 class="modal-card-title"><i class="fas fa-info-circle"></i> Info</h4>
                        <div class="detail-grid">
                            <span class="label">Betreff:</span>
                            <span class="value">${escHtml(result.subject || 'Kein Betreff')}</span>
                            <span class="label">Absender:</span>
                            <span class="value">${escHtml(result.sender || 'Keine Absenderadresse')}</span>
                        </div>
                    </div>
                    <div class="modal-card">
                        <h4 class="modal-card-title"><i class="fas fa-envelope-open-text"></i> Inhalt</h4>
                        ${contentHtml}
                    </div>
                </div>
            `);
        } else {
            hideLoading('mailModalBody', `
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>Fehler beim Laden des Mail-Inhalts</p>
                </div>
            `);
        }
    }


    // --- Suche & Filter ---
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput    = document.getElementById('searchInput');
        const customerCards  = document.querySelectorAll('.customer-card');
        const searchCounter  = document.getElementById('searchCounter');
        const filterBtn      = document.getElementById('filterBtn');
        const filterDropdown = document.getElementById('filterDropdown');
        const filterResetBtn = document.getElementById('filterResetBtn');
        const filterBtnText  = document.getElementById('filterBtnText');
        const backupTypeFilters = document.querySelectorAll('.backup-type-filter');
        const modeCustomer   = document.getElementById('modeCustomer');
        const modeJobs       = document.getElementById('modeJobs');

        // Prefill from URL
        const params = new URLSearchParams(window.location.search);
        const prefill = params.get('search');
        if (prefill) searchInput.value = prefill;

        function updatePlaceholder() {
            searchInput.placeholder = modeCustomer.checked
                ? 'Nach Kundenname oder Kundennummer suchen...'
                : 'Nach Jobname suchen...';
        }

        // Suchmodus wechseln
        modeCustomer.addEventListener('change', () => { updatePlaceholder(); filterAndSearch(); });
        modeJobs.addEventListener('change', () => { updatePlaceholder(); filterAndSearch(); });

        // Filter-Dropdown
        filterBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            filterDropdown.classList.toggle('show');
        });

        document.addEventListener('click', (e) => {
            if (!filterDropdown.contains(e.target) && e.target !== filterBtn) {
                filterDropdown.classList.remove('show');
            }
        });

        filterResetBtn.addEventListener('click', () => {
            backupTypeFilters.forEach(cb => cb.checked = false);
            filterBtn.classList.remove('filter-btn-active');
            filterBtnText.textContent = 'Backup-Typ';
            filterAndSearch();
        });

        backupTypeFilters.forEach(cb => {
            cb.addEventListener('change', () => {
                const count = document.querySelectorAll('.backup-type-filter:checked').length;
                if (count > 0) {
                    filterBtn.classList.add('filter-btn-active');
                    filterBtnText.textContent = 'Filter (' + count + ')';
                } else {
                    filterBtn.classList.remove('filter-btn-active');
                    filterBtnText.textContent = 'Backup-Typ';
                }
                filterAndSearch();
            });
        });

        // Sucheingabe
        searchInput.addEventListener('input', filterAndSearch);

        // Initialisierung
        updatePlaceholder();
        filterAndSearch();

        // Global verfügbar machen für filterByStatus()
        window.filterAndSearch = filterAndSearch;

        // --- Hauptfunktion: Suche + Filter ---
        function filterAndSearch() {
            const searchTerm = searchInput.value.toLowerCase().trim();
            const isCustomerMode = modeCustomer.checked;

            const activeTypeFilters = [];
            backupTypeFilters.forEach(cb => {
                if (cb.checked) activeTypeFilters.push(cb.value.toLowerCase());
            });

            customerCards.forEach(card => {
                const customerName   = card.dataset.customerName || '';
                const customerNumber = card.dataset.customerNumber || '';
                const jobs = card.querySelectorAll('.job-container');

                let cardVisible = false;
                let hasVisibleJobs = false;

                if (isCustomerMode) {
                    const customerMatches = searchTerm === '' ||
                        customerName.includes(searchTerm) ||
                        customerNumber.includes(searchTerm);

                    if (customerMatches) {
                        jobs.forEach(job => {
                            const jobType   = (job.dataset.backupType || '').toLowerCase();
                            const jobStatus = (job.dataset.currentStatus || '').toLowerCase();

                            const matchType   = activeTypeFilters.length === 0 || activeTypeFilters.includes(jobType);
                            const matchStatus = !activeStatusFilter || jobStatus === activeStatusFilter;

                            if (matchType && matchStatus) {
                                job.classList.remove('hidden');
                                hasVisibleJobs = true;
                            } else {
                                job.classList.add('hidden');
                            }
                        });
                        cardVisible = hasVisibleJobs || jobs.length === 0;
                    } else {
                        jobs.forEach(job => job.classList.add('hidden'));
                    }
                } else {
                    jobs.forEach(job => {
                        const jobName   = (job.dataset.jobName || '').toLowerCase();
                        const jobType   = (job.dataset.backupType || '').toLowerCase();
                        const jobStatus = (job.dataset.currentStatus || '').toLowerCase();

                        const matchSearch = searchTerm === '' || jobName.includes(searchTerm);
                        const matchType   = activeTypeFilters.length === 0 || activeTypeFilters.includes(jobType);
                        const matchStatus = !activeStatusFilter || jobStatus === activeStatusFilter;

                        if (matchSearch && matchType && matchStatus) {
                            job.classList.remove('hidden');
                            hasVisibleJobs = true;
                        } else {
                            job.classList.add('hidden');
                        }
                    });
                    cardVisible = hasVisibleJobs;
                }

                card.classList.toggle('hidden', !cardVisible);
            });

            updateCounter();
        }

        function updateCounter() {
            const totalCards   = customerCards.length;
            const visibleCards = document.querySelectorAll('.customer-card:not(.hidden)').length;
            const totalJobs    = document.querySelectorAll('.job-container').length;
            const visibleJobs  = document.querySelectorAll('.job-container:not(.hidden)').length;

            const searchTerm        = searchInput.value.trim();
            const hasActiveTypeFilter = document.querySelectorAll('.backup-type-filter:checked').length > 0;
            const hasAnyFilter      = searchTerm !== '' || hasActiveTypeFilter || activeStatusFilter;

            if (!hasAnyFilter) {
                searchCounter.textContent = '';
            } else {
                let parts = [];
                if (searchTerm) parts.push('Suche: "' + searchTerm + '"');
                if (activeStatusFilter) {
                    const labels = { success: 'Erfolgreich', warning: 'Warnungen', error: 'Fehler' };
                    parts.push('Status: ' + (labels[activeStatusFilter] || activeStatusFilter));
                }
                if (hasActiveTypeFilter) {
                    parts.push(document.querySelectorAll('.backup-type-filter:checked').length + ' Backup-Typ(en)');
                }
                searchCounter.textContent = visibleCards + ' von ' + totalCards + ' Kunden | ' +
                    visibleJobs + ' von ' + totalJobs + ' Jobs | Filter: ' + parts.join(', ');
            }

            // "Keine Ergebnisse" Anzeige
            let noResults = document.querySelector('.no-results-state');
            if (visibleCards === 0 && hasAnyFilter) {
                if (!noResults) {
                    noResults = document.createElement('div');
                    noResults.className = 'empty-state no-results-state';
                    noResults.innerHTML = '<i class="fas fa-search"></i><p>Keine Ergebnisse gefunden</p><span>Passe deine Suchkriterien oder Filter an.</span>';
                    const first = document.querySelector('.customer-card');
                    if (first) first.parentNode.insertBefore(noResults, first);
                }
            } else if (noResults) {
                noResults.remove();
            }
        }
    });


    // --- Hover-Tooltip (Datum) ---
    document.addEventListener('DOMContentLoaded', function() {
        const hoverTooltip = document.createElement('div');
        hoverTooltip.className = 'hover-date';
        document.body.appendChild(hoverTooltip);

        document.addEventListener('mouseover', function(e) {
            if (e.target.classList.contains('result-square') && e.target.dataset.date) {
                const dateObj = new Date(e.target.dataset.date);
                const weekday = dateObj.toLocaleDateString('de-DE', { weekday: 'long' });
                const formatted = dateObj.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });

                hoverTooltip.textContent = weekday + ' – ' + formatted;
                hoverTooltip.style.display = 'block';

                const rect = e.target.getBoundingClientRect();
                const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                hoverTooltip.style.left = (rect.left + window.scrollX) + 'px';
                hoverTooltip.style.top = (rect.top + scrollTop - 28) + 'px';
            }
        });

        document.addEventListener('mouseout', function(e) {
            if (e.target.classList.contains('result-square')) {
                hoverTooltip.style.display = 'none';
            }
        });
    });
    </script>

</body>
</html>