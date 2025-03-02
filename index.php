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

// Daten für das Dashboard abrufen
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
        m.content AS mail_content,  /* Neue Zeile */
        (
            SELECT COUNT(*)
            FROM backup_results br2
            WHERE br2.backup_job_id = bj.id
            AND br2.date = br.date
        ) as runs_count
    FROM customers c
    LEFT JOIN backup_jobs bj ON c.id = bj.customer_id
    LEFT JOIN backup_results br ON bj.id = br.backup_job_id
    LEFT JOIN mails m ON br.mail_id = m.id  /* Neue Zeile */
    ORDER BY c.name, bj.name, br.date DESC, br.time DESC
";

$result = $conn->query($query);

// Daten strukturieren
$dashboardData = [];
$stats = ['total' => 0, 'success' => 0, 'warning' => 0, 'error' => 0];

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
                    'mail_content' => $row['mail_content']
                ];

                // Statistiken aktualisieren
                $stats['total']++;
                if ($row['status']) {
                    $stats[$row['status']]++;
                }
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
            color: white;
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

        .stat-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            margin-top: 0.25rem;
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
            <div class="stat-card">
                <div class="stat-label">Gesamt</div>
                <div class="stat-value"><?php echo $stats['total']; ?></div>
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
            
            // Generiere eine eindeutige ID für jedes Mail-Content Element
            const mailContentsMap = new Map();
            results.forEach((result, index) => {
                if (result.mail_content) {
                    mailContentsMap.set(`mail-${result.id}`, result.mail_content);
                }
            });
            
            let tooltipContent = '';
            
            // Versteckte Div-Container für Mail-Contents
            tooltipContent += '<div id="mail-contents" style="display: none;">';
            mailContentsMap.forEach((content, id) => {
                tooltipContent += `<div id="${id}">${content}</div>`;
            });
            tooltipContent += '</div>';
            
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
                                ${result.mail_content ? `
                                    <button class="show-mail-btn" onclick="showMailContent('mail-${result.id}')">
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

        function escapeJSString(str) {
        return str.replace(/[\\"']/g, '\\$&')
                 .replace(/\u0000/g, '\\0')
                 .replace(/\n/g, '\\n')
                 .replace(/\r/g, '\\r')
                 .replace(/[\x00-\x1f\x7f-\x9f]/g, '');
        }

        function showMailContent(mailId) {
            const contentElement = document.getElementById(mailId);
            if (!contentElement) return;
            
            const content = contentElement.innerHTML;
            const mailModal = document.createElement('div');
            mailModal.className = 'mail-modal';
            
            // Prüfen ob der Content HTML enthält
            const containsHTML = /<[a-z][\s\S]*>/i.test(content);
            
            // Content entsprechend aufbereiten
            const processedContent = containsHTML ? 
                content : 
                content
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            
            mailModal.innerHTML = `
                <div class="mail-modal-content">
                    <div class="mail-modal-header">
                        <h3>Mail Inhalt</h3>
                        <button onclick="this.closest('.mail-modal').remove()">&times;</button>
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
            const saveButton = textarea.nextElementSibling;
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
    </script>

<footer class="fixed bottom-0 left-0 w-full bg-white border-t border-gray-200 py-4 z-10">
    <div class="container mx-auto text-center">
        Made with ❤️ by <a href="https://github.com/Herbertholzkopf/" class="footer-link">Andreas Koller</a>
    </div>
</footer>

</body>
</html>