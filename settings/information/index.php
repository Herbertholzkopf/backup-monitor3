<?php
/**
 * WISSENSDATENBANK
 * 
 * Pfad:    /settings/information/index.php
 * Includes: ../../includes/styles.css, ../../includes/app.js
 */

$config = require_once '../../config.php';
$conn = new mysqli($config['server'], $config['user'], $config['password'], $config['database']);
if ($conn->connect_error) { die('Verbindungsfehler: ' . $conn->connect_error); }
$conn->set_charset('utf8mb4');

function getCategories($conn) {
    $result = $conn->query("SELECT DISTINCT category FROM instructions WHERE category IS NOT NULL ORDER BY category");
    $categories = [];
    while ($row = $result->fetch_assoc()) $categories[] = $row['category'];
    return $categories;
}

function getEntriesByCategory($conn, $category) {
    $stmt = $conn->prepare("SELECT id, title FROM instructions WHERE category = ? ORDER BY title");
    $stmt->bind_param("s", $category);
    $stmt->execute();
    $result = $stmt->get_result();
    $entries = [];
    while ($row = $result->fetch_assoc()) $entries[] = $row;
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

$activeId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$activeEntry = $activeId ? getEntry($conn, $activeId) : null;
$categories = getCategories($conn);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wissensdatenbank – Backup-Monitor</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="../../includes/styles.css" rel="stylesheet">
    <style>
        .kb-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 1.5rem;
            min-height: calc(100vh - 10rem);
        }

        .kb-sidebar {
            background: #ffffff;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--color-gray-200);
            padding: 1.25rem;
            overflow-y: auto;
            max-height: calc(100vh - 10rem);
            position: sticky;
            top: 1.5rem;
        }

        .kb-category {
            margin-bottom: 1.25rem;
        }

        .kb-category:last-child {
            margin-bottom: 0;
        }

        .kb-category-title {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--color-gray-500);
            margin-bottom: 0.5rem;
            padding: 0 0.5rem;
        }

        .kb-link {
            display: block;
            padding: 0.5rem 0.75rem;
            border-radius: var(--border-radius-sm);
            font-size: 0.875rem;
            color: var(--color-gray-700);
            text-decoration: none;
            transition: all var(--transition-fast);
            margin-bottom: 0.125rem;
        }

        .kb-link:hover {
            background-color: var(--color-gray-100);
            color: var(--color-gray-900);
        }

        .kb-link.active {
            background-color: var(--color-primary);
            color: #ffffff;
        }

        .kb-link.active:hover {
            background-color: var(--color-primary-hover);
        }

        .kb-content {
            background: #ffffff;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--color-gray-200);
            padding: 2rem;
        }

        .kb-content h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--color-gray-800);
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--color-gray-200);
        }

        /* Prose-Styling für den Wissensdatenbank-Inhalt */
        .kb-prose { line-height: 1.7; color: var(--color-gray-700); }
        .kb-prose h2 { font-size: 1.25rem; font-weight: 600; color: var(--color-gray-800); margin: 1.5rem 0 0.75rem; }
        .kb-prose h3 { font-size: 1.1rem; font-weight: 600; color: var(--color-gray-800); margin: 1.25rem 0 0.5rem; }
        .kb-prose p { margin-bottom: 0.75rem; }
        .kb-prose ul, .kb-prose ol { margin: 0.75rem 0; padding-left: 1.5rem; }
        .kb-prose li { margin-bottom: 0.25rem; }
        .kb-prose code { background: var(--color-gray-100); padding: 0.125rem 0.375rem; border-radius: var(--border-radius-sm); font-size: 0.875rem; }
        .kb-prose pre { background: var(--color-gray-50); padding: 1rem; border-radius: var(--border-radius); overflow-x: auto; margin: 1rem 0; border: 1px solid var(--color-gray-200); }
        .kb-prose a { color: var(--color-primary); text-decoration: underline; }
        .kb-prose img { max-width: 100%; border-radius: var(--border-radius); margin: 1rem 0; }
        .kb-prose table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        .kb-prose th, .kb-prose td { padding: 0.5rem 0.75rem; border: 1px solid var(--color-gray-200); text-align: left; }
        .kb-prose th { background: var(--color-gray-50); font-weight: 600; }

        @media (max-width: 768px) {
            .kb-layout {
                grid-template-columns: 1fr;
            }
            .kb-sidebar {
                position: static;
                max-height: none;
            }
        }
    </style>
</head>
<body>
    <div class="container mx-auto px-4 py-6">

        <header class="page-header">
            <a href="../" class="back-button"><i class="fas fa-arrow-left"></i></a>
            <div class="page-header-title">
                <h1>Wissensdatenbank</h1>
                <p>Anleitungen und Erklärungen</p>
            </div>
        </header>

        <div class="kb-layout">
            <!-- Sidebar -->
            <div class="kb-sidebar custom-scroll">
                <?php foreach ($categories as $category): ?>
                    <div class="kb-category">
                        <div class="kb-category-title"><?= htmlspecialchars($category) ?></div>
                        <?php foreach (getEntriesByCategory($conn, $category) as $entry): ?>
                            <a href="?id=<?= $entry['id'] ?>"
                               class="kb-link <?= ($activeId == $entry['id']) ? 'active' : '' ?>">
                                <?= htmlspecialchars($entry['title']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Content -->
            <div class="kb-content">
                <?php if ($activeEntry): ?>
                    <h1><?= htmlspecialchars($activeEntry['title']) ?></h1>
                    <div class="kb-prose">
                        <?= $activeEntry['content'] ?>
                    </div>
                    <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--color-gray-200); font-size: 0.8125rem; color: var(--color-gray-400);">
                        <span class="badge badge-primary"><?= htmlspecialchars($activeEntry['category']) ?></span>
                        &middot; Erstellt: <?= date('d.m.Y H:i', strtotime($activeEntry['created_at'])) ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-book-open"></i>
                        <p>Willkommen in der Wissensdatenbank</p>
                        <span>Bitte wähle einen Eintrag aus der linken Navigation aus.</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <script src="../../includes/app.js"></script>
</body>
</html>
<?php $conn->close(); ?>