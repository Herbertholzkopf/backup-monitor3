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
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unverarbeitete E-Mails</title>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-hover: #1d4ed8;
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
            max-width: 1200px;
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
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 1rem;
        }

        th {
            background-color: var(--background-color);
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid var(--border-color);
        }

        td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        tr:hover {
            background-color: var(--background-color);
        }

        button {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease-in-out;
            background-color: var(--primary-color);
            color: white;
        }

        button:hover {
            background-color: var(--primary-hover);
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
            
            table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Unverarbeitete E-Mails</h1>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Absender</th>
                        <th>Betreff</th>
                        <th>Empfangen am</th>
                        <th>Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $result = $conn->query("
                        SELECT id, sender_email, subject, date, created_at, content 
                        FROM mails 
                        WHERE processed = FALSE 
                        ORDER BY created_at DESC
                    ");

                    while ($row = $result->fetch_assoc()) {
                        $mailData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                        
                        echo "<tr>";
                        echo "<td>{$row['sender_email']}</td>";
                        echo "<td>{$row['subject']}</td>";
                        echo "<td>" . date('d.m.Y H:i:s', strtotime($row['created_at'])) . "</td>";
                        echo "<td><button onclick='showMailDetails($mailData)'>Ansehen</button></td>";
                        echo "</tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal für Mail-Details -->
    <div id="mailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>E-Mail Details</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="mail-info">
                    <p><strong>Absender:</strong> <span id="modalSender"></span></p>
                    <p><strong>Betreff:</strong> <span id="modalSubject"></span></p>
                    <p><strong>Empfangen am:</strong> <span id="modalDate"></span></p>
                </div>
                <div class="mail-content" id="modalContent"></div>
            </div>
        </div>
    </div>

    <script>
        function showMailDetails(mailData) {
            document.getElementById('modalSender').textContent = mailData.sender_email;
            document.getElementById('modalSubject').textContent = mailData.subject;
            document.getElementById('modalDate').textContent = new Date(mailData.created_at).toLocaleString('de-DE');
            document.getElementById('modalContent').textContent = mailData.content;
            document.getElementById('mailModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('mailModal').style.display = 'none';
        }

        // Schließen des Modals wenn außerhalb geklickt wird
        window.onclick = function(event) {
            if (event.target == document.getElementById('mailModal')) {
                closeModal();
            }
        }
    </script>
</body>
</html>
<?php
$conn->close();
?>