<?php
// Datenbank-Konfiguration auslesen
function getDatabaseConfig() {
    $config = [];
    $pythonFile = '/var/www/backup-monitor2/config/database.py';
    if (file_exists($pythonFile)) {
        $content = file_get_contents($pythonFile);
        preg_match("/DB_HOST = '(.+)'/", $content, $matches);
        $config['host'] = $matches[1] ?? 'localhost';
        preg_match("/DB_USER = '(.+)'/", $content, $matches);
        $config['user'] = $matches[1] ?? '';
        preg_match("/DB_PASSWORD = '(.+)'/", $content, $matches);
        $config['password'] = $matches[1] ?? '';
        preg_match("/DB_NAME = '(.+)'/", $content, $matches);
        $config['database'] = $matches[1] ?? '';
    }
    return $config;
}

$config = getDatabaseConfig();
$conn = new mysqli($config['host'], $config['user'], $config['password'], $config['database']);

if ($conn->connect_error) {
    die("Verbindungsfehler: " . $conn->connect_error);
}

// SQL-Query mit LEFT JOINs für alle Verknüpfungen
$query = "
    SELECT 
        m.id as mail_id,
        m.date as mail_date,
        m.sender_email,
        m.subject,
        m.processed,
        m.content,
        m.created_at as mail_created_at,
        bj.id as backup_job_id,
        bj.name as backup_job_name,
        bj.note as backup_job_note,
        bj.search_term_mail,
        bj.search_term_subject,
        bj.search_term_text,
        bj.search_term_text2,
        c.name as customer_name,
        br.status as backup_status,
        br.date as backup_date,
        br.time as backup_time,
        br.note as backup_note,
        br.size_mb,
        br.duration_minutes
    FROM mails m
    LEFT JOIN backup_results br ON br.mail_id = m.id
    LEFT JOIN backup_jobs bj ON br.backup_job_id = bj.id
    LEFT JOIN customers c ON bj.customer_id = c.id
    ORDER BY m.date DESC
";

$result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alle E-Mails</title>
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
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 2rem;
            color: var(--text-color);
        }

        .card {
            background: var(--card-background);
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        th, td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            background-color: var(--background-color);
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        tr {
            cursor: pointer;
        }

        tr:hover {
            background-color: var(--background-color);
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
            padding: 2rem;
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
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-section {
            margin-bottom: 2rem;
            padding: 1rem;
            background-color: var(--background-color);
            border-radius: 0.375rem;
        }

        .modal-section h3 {
            margin-bottom: 1rem;
            font-size: 1.25rem;
            color: var(--text-color);
        }

        .modal-section p {
            margin: 0.5rem 0;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
            color: white;
        }

        .badge-success {
            background-color: var(--success-color);
        }

        .badge-warning {
            background-color: var(--warning-color);
        }

        .badge-error {
            background-color: var(--error-color);
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
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-color);
            cursor: pointer;
        }

        .close-btn:hover {
            color: var(--primary-color);
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            
            .card {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Alle E-Mails</h1>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Absender</th>
                        <th>Betreff</th>
                        <th>Backup-Job</th>
                        <th>Kunde</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    while ($row = $result->fetch_assoc()) {
                        $rowData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                        
                        echo "<tr onclick='showDetails($rowData)'>";
                        echo "<td>" . date('d.m.Y H:i', strtotime($row['mail_date'])) . "</td>";
                        echo "<td>{$row['sender_email']}</td>";
                        echo "<td>{$row['subject']}</td>";
                        echo "<td>" . ($row['backup_job_name'] ?? '-') . "</td>";
                        echo "<td>" . ($row['customer_name'] ?? '-') . "</td>";
                        echo "</tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal für Details -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>E-Mail Details</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            
            <!-- E-Mail Details -->
            <div class="modal-section">
                <h3>E-Mail Informationen</h3>
                <p><strong>Datum:</strong> <span id="mailDate"></span></p>
                <p><strong>Absender:</strong> <span id="mailSender"></span></p>
                <p><strong>Betreff:</strong> <span id="mailSubject"></span></p>
                <p><strong>Verarbeitet:</strong> <span id="mailProcessed"></span></p>
                <p><strong>Inhalt:</strong></p>
                <div class="mail-content" id="mailContent"></div>
            </div>

            <!-- Backup-Job Details -->
            <div class="modal-section">
                <h3>Backup-Job Informationen</h3>
                <p><strong>Name:</strong> <span id="jobName"></span></p>
                <p><strong>Kunde:</strong> <span id="customerName"></span></p>
                <p><strong>Notiz:</strong> <span id="jobNote"></span></p>
                <p><strong>E-Mail Suchwort:</strong> <span id="searchTermMail"></span></p>
                <p><strong>Betreff Suchwort:</strong> <span id="searchTermSubject"></span></p>
                <p><strong>Text Suchwort 1:</strong> <span id="searchTermText"></span></p>
                <p><strong>Text Suchwort 2:</strong> <span id="searchTermText2"></span></p>
            </div>

            <!-- Backup-Ergebnis Details -->
            <div class="modal-section">
                <h3>Backup-Ergebnis</h3>
                <p><strong>Status:</strong> <span id="backupStatus"></span></p>
                <p><strong>Datum:</strong> <span id="backupDate"></span></p>
                <p><strong>Uhrzeit:</strong> <span id="backupTime"></span></p>
                <p><strong>Notiz:</strong> <span id="backupNote"></span></p>
                <p><strong>Größe:</strong> <span id="backupSize"></span></p>
                <p><strong>Dauer:</strong> <span id="backupDuration"></span></p>
            </div>
        </div>
    </div>

    <script>
        function showDetails(data) {
            // E-Mail Informationen
            document.getElementById('mailDate').textContent = new Date(data.mail_date).toLocaleString('de-DE');
            document.getElementById('mailSender').textContent = data.sender_email;
            document.getElementById('mailSubject').textContent = data.subject;
            document.getElementById('mailProcessed').textContent = data.processed === '1' || data.processed === true ? 'Ja' : 'Nein';
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
                statusSpan.innerHTML = `<span class="badge badge-${status === 'success' ? 'success' : 
                                                                   status === 'warning' ? 'warning' : 
                                                                   'error'}">${status}</span>`;
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