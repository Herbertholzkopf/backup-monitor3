<?php
/**
 * KUNDENVERWALTUNG
 * 
 * Pfad:    /settings/customers/index.php
 * Includes: ../../includes/styles.css, ../../includes/app.js
 */

// Config einbinden
$config = require_once '../../config.php';

$conn = new mysqli($config['server'], $config['user'], $config['password'], $config['database']);
if ($conn->connect_error) { die('Verbindungsfehler: ' . $conn->connect_error); }
$conn->set_charset('utf8mb4');

if (!isset($_SESSION)) { session_start(); }

// POST-Verarbeitung
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $name = $conn->real_escape_string($_POST['name']);
                $number = $conn->real_escape_string($_POST['number']);
                $note = $conn->real_escape_string($_POST['note']);
                $conn->query("INSERT INTO customers (name, number, note) VALUES ('$name', '$number', '$note')");
                $_SESSION['message'] = "Kunde erfolgreich angelegt.";
                $_SESSION['message_type'] = "success";
                break;
            case 'edit':
                $id = (int)$_POST['id'];
                $name = $conn->real_escape_string($_POST['name']);
                $number = $conn->real_escape_string($_POST['number']);
                $note = $conn->real_escape_string($_POST['note']);
                $conn->query("UPDATE customers SET name='$name', number='$number', note='$note' WHERE id=$id");
                $_SESSION['message'] = "Kunde erfolgreich aktualisiert.";
                $_SESSION['message_type'] = "success";
                break;
            case 'delete':
                $id = (int)$_POST['id'];
                $check = $conn->query("SELECT COUNT(*) as count FROM backup_jobs WHERE customer_id = $id")->fetch_assoc();
                if ($check['count'] > 0) {
                    $_SESSION['message'] = "Kunde kann nicht gelöscht werden, da noch Backup-Jobs vorhanden sind.";
                    $_SESSION['message_type'] = "error";
                } else {
                    $conn->query("DELETE FROM customers WHERE id=$id");
                    $_SESSION['message'] = "Kunde erfolgreich gelöscht.";
                    $_SESSION['message_type'] = "success";
                }
                break;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$sort_by = isset($_GET['sort_by']) ? $conn->real_escape_string($_GET['sort_by']) : 'name';
$sort_order = isset($_GET['sort_order']) ? $conn->real_escape_string($_GET['sort_order']) : 'ASC';
if (!in_array($sort_by, ['name', 'number'])) $sort_by = 'name';
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'ASC';

$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$items_per_page = max(50, min(200, isset($_GET['items_per_page']) ? (int)$_GET['items_per_page'] : 50));
$offset = ($page - 1) * $items_per_page;

$sql_base = "FROM customers WHERE 1=1";
if (!empty($search)) $sql_base .= " AND (name LIKE '%$search%' OR number LIKE '%$search%' OR note LIKE '%$search%')";

$total_items = $conn->query("SELECT COUNT(*) AS total " . $sql_base)->fetch_assoc()['total'];
$total_pages = ceil($total_items / $items_per_page);
if ($page > $total_pages && $total_pages > 0) { $page = $total_pages; $offset = ($page - 1) * $items_per_page; }

$result = $conn->query("SELECT * $sql_base ORDER BY $sort_by $sort_order LIMIT $offset, $items_per_page");

function getSortLink($field, $csb, $cso) { $p = $_GET; $p['sort_by'] = $field; $p['sort_order'] = ($csb === $field && $cso === 'ASC') ? 'DESC' : 'ASC'; return '?' . http_build_query($p); }
function getPaginationLink($pn) { $p = $_GET; $p['page'] = $pn; return '?' . http_build_query($p); }
function getFilterLink($k, $v) { $p = $_GET; if ($v === '') unset($p[$k]); else $p[$k] = $v; $p['page'] = 1; return '?' . http_build_query($p); }
function getSortIndicator($f, $csb, $cso) { return ($csb === $f) ? (($cso === 'ASC') ? ' ▲' : ' ▼') : ''; }

$jsMsg = ''; $jsMsgType = '';
if (isset($_SESSION['message'])) { $jsMsg = addslashes($_SESSION['message']); $jsMsgType = $_SESSION['message_type'] ?? 'success'; unset($_SESSION['message']); unset($_SESSION['message_type']); }
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kundenverwaltung – Backup-Monitor</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="../../includes/styles.css" rel="stylesheet">
    <style>
        .pagination { display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem; flex-wrap: wrap; gap: 1rem; }
        .pagination-info { color: var(--color-gray-500); font-size: 0.875rem; }
        .pagination-controls { display: flex; gap: 0.25rem; }
        .pagination-controls a, .pagination-controls span { display: inline-flex; align-items: center; justify-content: center; min-width: 2rem; height: 2rem; padding: 0 0.5rem; border-radius: var(--border-radius); background: #fff; color: var(--color-gray-700); text-decoration: none; font-size: 0.875rem; border: 1px solid var(--color-gray-200); }
        .pagination-controls a:hover { background: var(--color-primary-light); color: var(--color-primary); border-color: var(--color-primary); }
        .pagination-controls .active { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }
        .truncate { max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    </style>
</head>
<body>
    <div class="container mx-auto px-4 py-6">

        <header class="page-header">
            <a href="../" class="back-button"><i class="fas fa-arrow-left"></i></a>
            <div class="page-header-title">
                <h1>Kundenverwaltung</h1>
                <p><?= $total_items ?> Kunden</p>
            </div>
            <button onclick="openModal('addModal')" class="btn btn-primary"><i class="fas fa-plus"></i> Neuen Kunden anlegen</button>
        </header>

        <div class="content-card mb-6" style="padding: 1rem;">
            <div class="search-bar-inner">
                <form method="get" style="flex: 1; display: flex;">
                    <div class="search-input-wrapper" style="flex: 1;">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" name="search" class="search-input" placeholder="Name, Nummer oder Notiz suchen..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </form>
                <select class="form-select" style="width: auto;" onchange="window.location=this.value">
                    <?php foreach ([50, 100, 200] as $v): ?>
                        <option value="<?= getFilterLink('items_per_page', $v) ?>" <?= $items_per_page == $v ? 'selected' : '' ?>><?= $v ?> pro Seite</option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($search)): ?>
                    <a href="?" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Zurücksetzen</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-card mb-6">
            <div class="section-header"><h2 class="section-title">Kundenliste</h2></div>
            <?php if ($total_items == 0): ?>
                <div class="empty-state"><i class="fas fa-users"></i><p>Keine Kunden gefunden.</p><?php if (!empty($search)): ?><span>Versuche die Filtereinstellungen zu ändern.</span><?php endif; ?></div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead><tr>
                            <th><a href="<?= getSortLink('name', $sort_by, $sort_order) ?>" style="color:inherit;text-decoration:none;">Name<?= getSortIndicator('name', $sort_by, $sort_order) ?></a></th>
                            <th><a href="<?= getSortLink('number', $sort_by, $sort_order) ?>" style="color:inherit;text-decoration:none;">Nummer<?= getSortIndicator('number', $sort_by, $sort_order) ?></a></th>
                            <th>Notiz</th>
                            <th class="text-right">Aktionen</th>
                        </tr></thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><span class="font-medium text-gray-900"><?= htmlspecialchars($row['name']) ?></span></td>
                                <td><span class="text-gray-500"><?= htmlspecialchars($row['number']) ?></span></td>
                                <td><?php if (!empty($row['note'])): ?><div class="truncate" title="<?= htmlspecialchars($row['note']) ?>"><?= htmlspecialchars($row['note']) ?></div><?php endif; ?></td>
                                <td class="text-right">
                                    <button onclick='editCustomer(<?= json_encode($row) ?>)' class="btn-inline edit" title="Bearbeiten"><i class="fas fa-edit"></i></button>
                                    <button onclick='confirmDeleteCustomer(<?= $row["id"] ?>, "<?= addslashes(htmlspecialchars($row["name"])) ?>")' class="btn-inline delete" title="Löschen"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination">
                    <div class="pagination-info">Zeige <?= ($offset + 1) ?>–<?= min($offset + $items_per_page, $total_items) ?> von <?= $total_items ?></div>
                    <div class="pagination-controls">
                        <?php if ($page > 1): ?><a href="<?= getPaginationLink(1) ?>"><i class="fas fa-angle-double-left"></i></a><a href="<?= getPaginationLink($page - 1) ?>"><i class="fas fa-angle-left"></i></a><?php endif; ?>
                        <?php $r=2; $sp=max(1,$page-$r); $ep=min($total_pages,$page+$r); if($sp>1)echo'<span>...</span>'; for($i=$sp;$i<=$ep;$i++){echo($i==$page)?"<span class=\"active\">$i</span>":"<a href=\"".getPaginationLink($i)."\">$i</a>";} if($ep<$total_pages)echo'<span>...</span>'; ?>
                        <?php if ($page < $total_pages): ?><a href="<?= getPaginationLink($page + 1) ?>"><i class="fas fa-angle-right"></i></a><a href="<?= getPaginationLink($total_pages) ?>"><i class="fas fa-angle-double-right"></i></a><?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- MODAL: Neuen Kunden anlegen -->
    <div class="modal" id="addModal"><div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h3 class="modal-title"><i class="fas fa-user-plus text-blue-500"></i> Neuen Kunden anlegen</h3><button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button></div>
        <form method="post"><input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-row"><div class="form-group"><label class="form-label">Name <span class="required">*</span></label><input type="text" name="name" class="form-input" required></div><div class="form-group"><label class="form-label">Nummer <span class="required">*</span></label><input type="text" name="number" class="form-input" required></div></div>
                <div class="form-group"><label class="form-label">Notiz</label><textarea name="note" class="form-textarea"></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('addModal')">Abbrechen</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Anlegen</button></div>
        </form>
    </div></div></div>

    <!-- MODAL: Kunde bearbeiten -->
    <div class="modal" id="editModal"><div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h3 class="modal-title"><i class="fas fa-edit text-blue-500"></i> Kunde bearbeiten</h3><button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button></div>
        <form method="post"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit_id">
            <div class="modal-body">
                <div class="form-row"><div class="form-group"><label class="form-label">Name <span class="required">*</span></label><input type="text" id="edit_name" name="name" class="form-input" required></div><div class="form-group"><label class="form-label">Nummer <span class="required">*</span></label><input type="text" id="edit_number" name="number" class="form-input" required></div></div>
                <div class="form-group"><label class="form-label">Notiz</label><textarea id="edit_note" name="note" class="form-textarea"></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Abbrechen</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button></div>
        </form>
    </div></div></div>

    <script src="../../includes/app.js"></script>
    <script>
    <?php if ($jsMsg): ?>document.addEventListener('DOMContentLoaded', () => showNotification('<?= $jsMsg ?>', '<?= $jsMsgType ?>'));<?php endif; ?>

    function editCustomer(c) {
        document.getElementById('edit_id').value = c.id;
        document.getElementById('edit_name').value = c.name;
        document.getElementById('edit_number').value = c.number;
        document.getElementById('edit_note').value = c.note || '';
        openModal('editModal');
    }

    function confirmDeleteCustomer(id, name) {
        showConfirm('Kunden "' + name + '" wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.', () => {
            const f = document.createElement('form'); f.method = 'post';
            f.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' + id + '">';
            document.body.appendChild(f); f.submit();
        });
    }
    </script>
</body>
</html>
<?php $conn->close(); ?>