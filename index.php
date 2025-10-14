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

// Daten für das Dashboard abrufen - OHNE Mail-Inhalt für bessere Performance
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
        rc.runs_count
    FROM customers c
    LEFT JOIN backup_jobs bj ON c.id = bj.customer_id
    LEFT JOIN backup_results br ON bj.id = br.backup_job_id 
        AND br.date >= '$dateLimit'  -- Zeitliche Einschränkung
    LEFT JOIN (
        -- Pre-calculate runs_count für alle relevanten Kombinationen
        SELECT 
            backup_job_id,
            date,
            COUNT(*) as runs_count
        FROM backup_results
        WHERE date >= '$dateLimit'
        GROUP BY backup_job_id, date
    ) rc ON rc.backup_job_id = bj.id AND rc.date = br.date
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
            --warning-color: #d97706;
            --error-color: #dc2626;
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

        .search-container {
        margin-bottom: 2rem;
        }
        
        .search-box {
            display: flex;
            width: 100%;
            position: relative;
        }
        
        #customerSearch {
            flex: 1;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            font-size: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        
        #customerSearch:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
        }
        
        .clear-search-btn {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--text-secondary);
            cursor: pointer;
            display: none;
        }
        
        .clear-search-btn:hover {
            color: var(--text-color);
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
            <div class="stat-card">
                <div class="stat-label">Erfolgreich</div>
                <div class="stat-value success"><?php echo $stats['success']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Warnungen</div>
                <div class="stat-value warning"><?php echo $stats['warning']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Fehler</div>
                <div class="stat-value error"><?php echo $stats['error']; ?></div>
            </div>
        </div>

        <!-- Suchleiste -->
        <div class="search-container">
            <div class="search-box">
                <input type="text" id="customerSearch" placeholder="Nach Kundenname oder Kundennummer suchen..." autocomplete="off">
                <button id="clearSearch" class="clear-search-btn">&times;</button>
            </div>
        </div>

        <!-- Kundenliste -->
        <?php foreach ($dashboardData as $customerData): ?>
            <div class="customer-card">
                <div class="customer-header">
                    <div class="customer-name"><?php echo htmlspecialchars($customerData['customer']['name']); ?></div>
                    <div class="customer-number">(<?php echo htmlspecialchars($customerData['customer']['number']); ?>)</div>
                </div>

                <?php foreach ($customerData['jobs'] as $job): ?>
                    <div class="job-container">
                    <div class="job-header">
                        <div class="job-name"><?php echo htmlspecialchars($job['job_name']); ?></div>
                        <div class="job-type"><?php echo htmlspecialchars($job['backup_type']); ?></div>
                    </div>

                    <div class="results-grid">
                        <?php 
                        // Generiere die letzten 21 Tage
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
                                <span>${parseFloat(result.size_mb).toFixed(2)} MB</span>
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
        const searchInput = document.getElementById('customerSearch');
        const clearButton = document.getElementById('clearSearch');
        const customerCards = document.querySelectorAll('.customer-card');
        
        // Zähler für sichtbare Kunden
        let visibleCounter = document.createElement('div');
        visibleCounter.className = 'search-counter';
        visibleCounter.style.color = 'var(--text-secondary)';
        visibleCounter.style.fontSize = '0.875rem';
        visibleCounter.style.marginTop = '0.5rem';
        document.querySelector('.search-container').appendChild(visibleCounter);
        
        // Update-Funktion für den Zähler
        function updateVisibleCounter() {
            const totalCards = customerCards.length;
            const visibleCards = document.querySelectorAll('.customer-card:not(.hidden)').length;
            
            if (searchInput.value.trim() === '') {
                visibleCounter.textContent = '';
            } else {
                visibleCounter.textContent = `${visibleCards} von ${totalCards} Kunden angezeigt`;
            }
            
            // "Keine Ergebnisse" Anzeige
            let noResults = document.querySelector('.no-results');
            
            if (visibleCards === 0 && searchInput.value.trim() !== '') {
                if (!noResults) {
                    noResults = document.createElement('div');
                    noResults.className = 'no-results';
                    noResults.textContent = 'Keine Kunden gefunden.';
                    
                    // Einfügen nach der Suchleiste und vor der ersten Kundenkarte
                    const firstCustomerCard = document.querySelector('.customer-card');
                    if (firstCustomerCard) {
                        firstCustomerCard.parentNode.insertBefore(noResults, firstCustomerCard);
                    }
                }
            } else if (noResults) {
                noResults.remove();
            }
        }
        
        // Suchfunktion
        function filterCustomers() {
            const searchTerm = searchInput.value.toLowerCase().trim();
            
            // Clear-Button anzeigen/verstecken
            clearButton.style.display = searchTerm ? 'block' : 'none';
            
            customerCards.forEach(card => {
                const customerName = card.querySelector('.customer-name').textContent.toLowerCase();
                const customerNumber = card.querySelector('.customer-number').textContent.toLowerCase();
                
                if (customerName.includes(searchTerm) || customerNumber.includes(searchTerm)) {
                    card.classList.remove('hidden');
                } else {
                    card.classList.add('hidden');
                }
            });
            
            updateVisibleCounter();
        }
        
        // Event-Listener
        searchInput.addEventListener('input', filterCustomers);
        
        clearButton.addEventListener('click', function() {
            searchInput.value = '';
            filterCustomers();
            searchInput.focus();
        });
        
        // Initiales Update
        filterCustomers();
    });
    </script>

<footer class="fixed bottom-0 left-0 w-full bg-white border-t border-gray-200 py-4 z-10">
    <div class="container mx-auto text-center">
        Made with ❤️ by <a href="https://github.com/Herbertholzkopf/" class="footer-link">Andreas Koller - 41h Arbeitszeit (Stand 02.05.2025)</a>
    </div>
</footer>

</body>
</html>