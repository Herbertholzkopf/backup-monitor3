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

// Funktionen
function getCategories($conn) {
    $sql = "SELECT DISTINCT category FROM instructions WHERE category IS NOT NULL ORDER BY category";
    $result = $conn->query($sql);
    $categories = [];
    while($row = $result->fetch_assoc()) {
        $categories[] = $row['category'];
    }
    return $categories;
}

function getEntriesByCategory($conn, $category) {
    $stmt = $conn->prepare("SELECT id, title FROM instructions WHERE category = ? ORDER BY title");
    $stmt->bind_param("s", $category);
    $stmt->execute();
    $result = $stmt->get_result();
    $entries = [];
    while($row = $result->fetch_assoc()) {
        $entries[] = $row;
    }
    $stmt->close();
    return $entries;
}

function getEntry($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM instructions WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $entry = $result->fetch_assoc();
    $stmt->close();
    return $entry;
}

// Aktiven Eintrag ermitteln
$activeId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$activeEntry = $activeId ? getEntry($conn, $activeId) : null;

// Kategorien laden
$categories = getCategories($conn);
?>

<!DOCTYPE html>
<html lang="de" class="overflow-x-hidden">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wissensdatenbank</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-light: #dbeafe;
            --text-color: #1f2937;
            --border-color: #e5e7eb;
        }
        
        body {
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        
        html, body {
            max-width: 100%;
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
        
        .container {
            width: 100%;
            margin: 0;
            padding: 0;
        }
        
        header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.5rem;
            background-color: #f9fafb;
            padding: 0.5rem;
            border-radius: 0.5rem;
            width: 100%;
        }
        
        header h1 {
            margin-right: auto;
            font-size: 1.5rem;
            font-weight: 700;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container">
        <!-- Header mit Zurück-Button -->
        <header>
            <a href="../" class="btn-back">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1>Wissensdatenbank</h1>
        </header>
        
        <div class="flex min-h-screen mt-1 w-full">
            <!-- Sidebar -->
            <div class="w-64 bg-white shadow-lg overflow-y-auto rounded-tr-lg rounded-tl-lg">
                <div class="p-4">
                    <?php foreach($categories as $category): ?>
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-gray-700 mb-2"><?= htmlspecialchars($category) ?></h2>
                            <div class="space-y-1">
                                <?php 
                                $entries = getEntriesByCategory($conn, $category);
                                foreach($entries as $entry):
                                ?>
                                    <a href="?id=<?= $entry['id'] ?>" 
                                       class="block p-2 rounded <?= ($activeId == $entry['id']) ? 'bg-blue-600 text-white hover:bg-blue-700 hover:text-white' : 'hover:bg-gray-100' ?>">
                                        <?= htmlspecialchars($entry['title']) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Content Area -->
            <div class="flex-1 p-4 w-full">
                <?php if ($activeEntry): ?>
                    <div class="bg-white rounded-lg shadow-lg p-6 w-full">
                        <h1 class="text-2xl font-bold mb-4"><?= htmlspecialchars($activeEntry['title']) ?></h1>
                        <div class="prose max-w-none">
                            <?= $activeEntry['content'] ?>
                        </div>
                        <div class="mt-4 text-sm text-gray-500">
                            Kategorie: <?= htmlspecialchars($activeEntry['category']) ?><br>
                            Erstellt: <?= date('d.m.Y H:i', strtotime($activeEntry['created_at'])) ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-lg shadow-lg p-6 w-full">
                        <h1 class="text-xl font-semibold text-gray-700">Willkommen in der Wissensdatenbank</h1>
                        <p class="mt-2 text-gray-600">Bitte wählen Sie einen Eintrag aus der linken Navigationsleiste aus.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>

<?php
$conn->close();
?>