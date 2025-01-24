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
                break;

            case 'delete':
                $id = (int)$_POST['id'];
                $sql = "DELETE FROM backup_jobs WHERE id=$id";
                $conn->query($sql);
                break;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup-Jobs Verwaltung</title>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-hover: #1d4ed8;
            --danger-color: #dc2626;
            --danger-hover: #b91c1c;
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

        h2 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--text-color);
        }

        .card {
            background: var(--card-background);
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
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
            font-size: 1rem;
            transition: border-color 0.15s ease-in-out;
        }

        input[type="text"]:focus, textarea:focus, select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        button {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease-in-out;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: var(--danger-hover);
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

        .actions {
            display: flex;
            gap: 0.5rem;
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
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            margin-top: 1.5rem;
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
        <h1>Backup-Jobs Verwaltung</h1>

        <!-- Formular für neue Backup-Jobs -->
        <div class="card">
            <h2>Neuen Backup-Job anlegen</h2>
            <form method="post">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label for="customer_id" class="required">Kunde:</label>
                    <select id="customer_id" name="customer_id" required>
                        <option value="">Bitte wählen...</option>
                        <?php
                        $result = $conn->query("SELECT id, name FROM customers ORDER BY name");
                        while ($row = $result->fetch_assoc()) {
                            echo "<option value='{$row['id']}'>{$row['name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="name" class="required">Name des Backup-Jobs:</label>
                    <input type="text" id="name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="backup_type">Backup-Typ:</label>
                    <input type="text" id="backup_type" name="backup_type">
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
                <button type="submit" class="btn-primary">Backup-Job anlegen</button>
            </form>
        </div>

        <!-- Backup-Jobs Liste -->
        <div class="card">
            <h2>Backup-Jobs Liste</h2>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Kunde</th>
                        <th>Backup-Typ</th>
                        <th>Notiz</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $result = $conn->query("
                        SELECT b.*, c.name as customer_name 
                        FROM backup_jobs b 
                        LEFT JOIN customers c ON b.customer_id = c.id 
                        ORDER BY b.name
                    ");
                    while ($row = $result->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td>{$row['name']}</td>";
                        echo "<td>{$row['customer_name']}</td>";
                        echo "<td>{$row['backup_type']}</td>";
                        echo "<td>{$row['note']}</td>";
                        echo "<td class='actions'>";
                        echo "<button class='btn-primary' onclick='editJob(" . json_encode($row) . ")'>Bearbeiten</button>";
                        echo "<form method='post' style='display: inline;' onsubmit='return confirm(\"Wirklich löschen?\")'>";
                        echo "<input type='hidden' name='action' value='delete'>";
                        echo "<input type='hidden' name='id' value='{$row['id']}'>";
                        echo "<button type='submit' class='btn-danger'>Löschen</button>";
                        echo "</form>";
                        echo "</td>";
                        echo "</tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal für Bearbeiten -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Backup-Job bearbeiten</h2>
                <button type="button" onclick="closeModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="form-group">
                <div class="form-group">
                    <label for="edit_customer_id" class="required">Kunde:</label>
                    <select id="edit_customer_id" name="customer_id" required>
                        <?php
                        $result = $conn->query("SELECT id, name FROM customers ORDER BY name");
                        while ($row = $result->fetch_assoc()) {
                            echo "<option value='{$row['id']}'>{$row['name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_name" class="required">Name des Backup-Jobs:</label>
                    <input type="text" id="edit_name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="edit_backup_type">Backup-Typ:</label>
                    <input type="text" id="edit_backup_type" name="backup_type">
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
                    <button type="button" class="btn-danger" onclick="closeModal()">Abbrechen</button>
                    <button type="submit" class="btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>

    <script>
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

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Schließen des Modals wenn außerhalb geklickt wird
        window.onclick = function(event) {
            if (event.target == document.getElementById('editModal')) {
                closeModal();
            }
        }
    </script>
</body>
</html>
<?php
$conn->close();
?>