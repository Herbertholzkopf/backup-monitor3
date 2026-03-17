<?php
/**
 * BACKUP-JOBS VERWALTUNG
 * 
 * Pfad:    /settings/backup-jobs/index.php
 * Includes: ../../includes/styles.css, ../../includes/app.js
 */

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
                $customer_id = (int)$_POST['customer_id'];
                $name = $conn->real_escape_string($_POST['name']);
                $note = $conn->real_escape_string($_POST['note']);
                $backup_type = $conn->real_escape_string($_POST['backup_type']);
                $search_term_mail = $conn->real_escape_string($_POST['search_term_mail']);
                $search_term_subject = $conn->real_escape_string($_POST['search_term_subject']);
                $search_term_text = $conn->real_escape_string($_POST['search_term_text']);
                $search_term_text2 = $conn->real_escape_string($_POST['search_term_text2'] ?? '');
                $include_in_report = isset($_POST['include_in_report']) ? 1 : 0;
                $ignore_hours = !empty($_POST['ignore_no_status_updates_for_x_hours']) ? (int)$_POST['ignore_no_status_updates_for_x_hours'] : 'NULL';
                $conn->query("INSERT INTO backup_jobs (customer_id, name, note, backup_type, search_term_mail, search_term_subject, search_term_text, search_term_text2, include_in_report, ignore_no_status_updates_for_x_hours) VALUES ($customer_id, '$name', '$note', '$backup_type', '$search_term_mail', '$search_term_subject', '$search_term_text', '$search_term_text2', $include_in_report, $ignore_hours)");
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
                $include_in_report = isset($_POST['include_in_report']) ? 1 : 0;
                $ignore_hours = !empty($_POST['ignore_no_status_updates_for_x_hours']) ? (int)$_POST['ignore_no_status_updates_for_x_hours'] : 'NULL';
                $conn->query("UPDATE backup_jobs SET customer_id=$customer_id, name='$name', note='$note', backup_type='$backup_type', search_term_mail='$search_term_mail', search_term_subject='$search_term_subject', search_term_text='$search_term_text', search_term_text2='$search_term_text2', include_in_report=$include_in_report, ignore_no_status_updates_for_x_hours=$ignore_hours WHERE id=$id");
                $_SESSION['message'] = "Backup-Job erfolgreich aktualisiert.";
                $_SESSION['message_type'] = "success";
                break;
            case 'delete':
                $id = (int)$_POST['id'];
                $conn->query("DELETE FROM backup_jobs WHERE id=$id");
                $_SESSION['message'] = "Backup-Job erfolgreich gelöscht.";
                $_SESSION['message_type'] = "success";
                break;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$customer_filter = isset($_GET['customer_filter']) ? (int)$_GET['customer_filter'] : 0;
$backup_type_filter = isset($_GET['backup_type_filter']) ? $conn->real_escape_string($_GET['backup_type_filter']) : '';
$sort_by = isset($_GET['sort_by']) ? $conn->real_escape_string($_GET['sort_by']) : 'name';
$sort_order = isset($_GET['sort_order']) ? $conn->real_escape_string($_GET['sort_order']) : 'ASC';
if (!in_array($sort_by, ['name', 'customer_name', 'backup_type'])) $sort_by = 'name';
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'ASC';

$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$items_per_page = max(50, min(200, isset($_GET['items_per_page']) ? (int)$_GET['items_per_page'] : 50));
$offset = ($page - 1) * $items_per_page;

$backup_types = [];
$btr = $conn->query("SELECT DISTINCT backup_type FROM backup_jobs WHERE backup_type != '' ORDER BY backup_type");
while ($t = $btr->fetch_assoc()) $backup_types[] = $t['backup_type'];

$sql_base = "FROM backup_jobs b LEFT JOIN customers c ON b.customer_id = c.id WHERE 1=1";
if (!empty($search)) $sql_base .= " AND (b.name LIKE '%$search%' OR c.name LIKE '%$search%' OR b.backup_type LIKE '%$search%' OR b.note LIKE '%$search%' OR b.search_term_mail LIKE '%$search%' OR b.search_term_subject LIKE '%$search%' OR b.search_term_text LIKE '%$search%')";
if ($customer_filter > 0) $sql_base .= " AND b.customer_id = $customer_filter";
if (!empty($backup_type_filter)) $sql_base .= " AND b.backup_type = '$backup_type_filter'";

$total_items = $conn->query("SELECT COUNT(*) AS total " . $sql_base)->fetch_assoc()['total'];
$total_pages = ceil($total_items / $items_per_page);
if ($page > $total_pages && $total_pages > 0) { $page = $total_pages; $offset = ($page - 1) * $items_per_page; }

$order_col = ($sort_by === 'customer_name') ? 'c.name' : "b.$sort_by";
$result = $conn->query("SELECT b.*, c.name as customer_name $sql_base ORDER BY $order_col $sort_order LIMIT $offset, $items_per_page");

$customers = [];
$cr = $conn->query("SELECT id, name FROM customers ORDER BY name");
while ($c = $cr->fetch_assoc()) $customers[$c['id']] = $c['name'];

function getSortLink($f, $csb, $cso) { $p = $_GET; $p['sort_by'] = $f; $p['sort_order'] = ($csb === $f && $cso === 'ASC') ? 'DESC' : 'ASC'; return '?' . http_build_query($p); }
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
    <title>Backup-Jobs – Backup-Monitor</title>
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
        .truncate { max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .toggle-switch { display: flex; align-items: center; justify-content: space-between; }
        .toggle-switch input[type="checkbox"] { width: 44px; height: 24px; appearance: none; background-color: var(--color-gray-300); border-radius: 12px; position: relative; cursor: pointer; transition: background-color 0.2s; flex-shrink: 0; }
        .toggle-switch input[type="checkbox"]:checked { background-color: var(--color-success); }
        .toggle-switch input[type="checkbox"]::before { content: ''; position: absolute; width: 20px; height: 20px; border-radius: 50%; background: #fff; top: 2px; left: 2px; transition: transform 0.2s; }
        .toggle-switch input[type="checkbox"]:checked::before { transform: translateX(20px); }
        .form-hint { font-size: 0.75rem; color: var(--color-gray-500); margin-top: 0.25rem; line-height: 1.4; }
    </style>
</head>
<body>
    <div class="container mx-auto px-4 py-6">

        <header class="page-header">
            <a href="../" class="back-button"><i class="fas fa-arrow-left"></i></a>
            <div class="page-header-title">
                <h1>Backup-Jobs Verwaltung</h1>
                <p><?= $total_items ?> Jobs</p>
            </div>
            <button onclick="openModal('addModal')" class="btn btn-primary"><i class="fas fa-plus"></i> Neuen Job anlegen</button>
        </header>

        <div class="content-card mb-6" style="padding: 1rem;">
            <div class="search-bar-inner">
                <form method="get" style="flex: 1; display: flex;">
                    <div class="search-input-wrapper" style="flex: 1;">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" name="search" class="search-input" placeholder="Suche..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </form>
                <select class="form-select" style="width: auto;" onchange="window.location=this.value">
                    <option value="<?= getFilterLink('customer_filter', '') ?>">Alle Kunden</option>
                    <?php foreach ($customers as $id => $name): ?>
                        <option value="<?= getFilterLink('customer_filter', $id) ?>" <?= $customer_filter == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select" style="width: auto;" onchange="window.location=this.value">
                    <option value="<?= getFilterLink('backup_type_filter', '') ?>">Alle Typen</option>
                    <?php foreach ($backup_types as $type): ?>
                        <option value="<?= getFilterLink('backup_type_filter', $type) ?>" <?= $backup_type_filter == $type ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select" style="width: auto;" onchange="window.location=this.value">
                    <?php foreach ([50, 100, 200] as $v): ?>
                        <option value="<?= getFilterLink('items_per_page', $v) ?>" <?= $items_per_page == $v ? 'selected' : '' ?>><?= $v ?> pro Seite</option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($search) || $customer_filter > 0 || !empty($backup_type_filter)): ?>
                    <a href="?" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Zurücksetzen</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-card mb-6">
            <div class="section-header"><h2 class="section-title">Backup-Jobs Liste</h2></div>
            <?php if ($total_items == 0): ?>
                <div class="empty-state"><i class="fas fa-database"></i><p>Keine Backup-Jobs gefunden.</p></div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead><tr>
                            <th><a href="<?= getSortLink('name', $sort_by, $sort_order) ?>" style="color:inherit;text-decoration:none;">Name<?= getSortIndicator('name', $sort_by, $sort_order) ?></a></th>
                            <th><a href="<?= getSortLink('customer_name', $sort_by, $sort_order) ?>" style="color:inherit;text-decoration:none;">Kunde<?= getSortIndicator('customer_name', $sort_by, $sort_order) ?></a></th>
                            <th><a href="<?= getSortLink('backup_type', $sort_by, $sort_order) ?>" style="color:inherit;text-decoration:none;">Backup-Typ<?= getSortIndicator('backup_type', $sort_by, $sort_order) ?></a></th>
                            <th>Notiz</th>
                            <th class="text-right">Aktionen</th>
                        </tr></thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><span class="font-medium text-gray-900"><?= htmlspecialchars($row['name']) ?></span></td>
                                <td><?= htmlspecialchars($row['customer_name']) ?></td>
                                <td><?php if (!empty($row['backup_type'])): ?><span class="badge badge-primary"><?= htmlspecialchars($row['backup_type']) ?></span><?php endif; ?></td>
                                <td><?php if (!empty($row['note'])): ?><div class="truncate" title="<?= htmlspecialchars($row['note']) ?>"><?= htmlspecialchars($row['note']) ?></div><?php endif; ?></td>
                                <td class="text-right">
                                    <button onclick='editJob(<?= json_encode($row) ?>)' class="btn-inline edit" title="Bearbeiten"><i class="fas fa-edit"></i></button>
                                    <button onclick='confirmDeleteJob(<?= $row["id"] ?>, "<?= addslashes(htmlspecialchars($row["name"])) ?>")' class="btn-inline delete" title="Löschen"><i class="fas fa-trash"></i></button>
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
                        <?php $r=2;$sp=max(1,$page-$r);$ep=min($total_pages,$page+$r);if($sp>1)echo'<span>...</span>';for($i=$sp;$i<=$ep;$i++){echo($i==$page)?"<span class=\"active\">$i</span>":"<a href=\"".getPaginationLink($i)."\">$i</a>";}if($ep<$total_pages)echo'<span>...</span>'; ?>
                        <?php if ($page < $total_pages): ?><a href="<?= getPaginationLink($page + 1) ?>"><i class="fas fa-angle-right"></i></a><a href="<?= getPaginationLink($total_pages) ?>"><i class="fas fa-angle-double-right"></i></a><?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- MODAL: Neuen Backup-Job anlegen -->
    <div class="modal" id="addModal"><div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h3 class="modal-title"><i class="fas fa-plus text-blue-500"></i> Neuen Backup-Job anlegen</h3><button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button></div>
        <form method="post"><input type="hidden" name="action" value="add">
            <div class="modal-body custom-scroll">
                <div class="form-section-title">Allgemeine Daten</div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Kunde <span class="required">*</span></label><select name="customer_id" class="form-select" required><option value="">– Bitte wählen –</option><?php foreach ($customers as $id => $name): ?><option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Name <span class="required">*</span></label><input type="text" name="name" class="form-input" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Backup-Typ</label><input type="text" name="backup_type" class="form-input" list="bt_list"><datalist id="bt_list"><?php foreach ($backup_types as $t): ?><option value="<?= htmlspecialchars($t) ?>"><?php endforeach; ?></datalist></div>
                    <div class="form-group"><label class="form-label">Notiz</label><textarea name="note" class="form-textarea" style="min-height: 3rem;"></textarea></div>
                </div>
                <hr class="divider">
                <div class="form-section-title">Suchbegriffe</div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">E-Mail Suchwort <span class="required">*</span></label><input type="text" name="search_term_mail" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Betreff Suchwort <span class="required">*</span></label><input type="text" name="search_term_subject" class="form-input" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Text Suchwort 1 <span class="required">*</span></label><input type="text" name="search_term_text" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Text Suchwort 2</label><input type="text" name="search_term_text2" class="form-input"></div>
                </div>
                <hr class="divider">
                <div class="form-section-title">Mail-Berichte</div>
                <div class="form-group"><div class="toggle-switch"><label class="form-label" style="margin-bottom:0;">In Mail-Berichten auflisten</label><input type="checkbox" name="include_in_report" checked></div></div>
                <div class="form-group"><label class="form-label">Status-Timeout (Stunden)</label><input type="number" name="ignore_no_status_updates_for_x_hours" class="form-input" min="0" value="24" placeholder="24"><p class="form-hint">Wie lange ein Job seinen Status behält, bevor er als „kein Status" gilt. (3 Tage = 72h, 7 Tage = 168h, 31 Tage = 744h)</p></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('addModal')">Abbrechen</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Anlegen</button></div>
        </form>
    </div></div></div>

    <!-- MODAL: Backup-Job bearbeiten -->
    <div class="modal" id="editModal"><div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h3 class="modal-title"><i class="fas fa-edit text-blue-500"></i> Backup-Job bearbeiten</h3><button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button></div>
        <form method="post"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit_id">
            <div class="modal-body custom-scroll">
                <div class="form-section-title">Allgemeine Daten</div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Kunde <span class="required">*</span></label><select id="edit_customer_id" name="customer_id" class="form-select" required><?php foreach ($customers as $id => $name): ?><option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Name <span class="required">*</span></label><input type="text" id="edit_name" name="name" class="form-input" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Backup-Typ</label><input type="text" id="edit_backup_type" name="backup_type" class="form-input" list="ebt_list"><datalist id="ebt_list"><?php foreach ($backup_types as $t): ?><option value="<?= htmlspecialchars($t) ?>"><?php endforeach; ?></datalist></div>
                    <div class="form-group"><label class="form-label">Notiz</label><textarea id="edit_note" name="note" class="form-textarea" style="min-height: 3rem;"></textarea></div>
                </div>
                <hr class="divider">
                <div class="form-section-title">Suchbegriffe</div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">E-Mail Suchwort <span class="required">*</span></label><input type="text" id="edit_search_term_mail" name="search_term_mail" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Betreff Suchwort <span class="required">*</span></label><input type="text" id="edit_search_term_subject" name="search_term_subject" class="form-input" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Text Suchwort 1 <span class="required">*</span></label><input type="text" id="edit_search_term_text" name="search_term_text" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Text Suchwort 2</label><input type="text" id="edit_search_term_text2" name="search_term_text2" class="form-input"></div>
                </div>
                <hr class="divider">
                <div class="form-section-title">Mail-Berichte</div>
                <div class="form-group"><div class="toggle-switch"><label class="form-label" style="margin-bottom:0;">In Mail-Berichten auflisten</label><input type="checkbox" id="edit_include_in_report" name="include_in_report"></div></div>
                <div class="form-group"><label class="form-label">Status-Timeout (Stunden)</label><input type="number" id="edit_ignore_hours" name="ignore_no_status_updates_for_x_hours" class="form-input" min="0" placeholder="24"><p class="form-hint">Wie lange ein Job seinen Status behält, bevor er als „kein Status" gilt.</p></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Abbrechen</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button></div>
        </form>
    </div></div></div>

    <script src="../../includes/app.js"></script>
    <script>
    <?php if ($jsMsg): ?>document.addEventListener('DOMContentLoaded', () => showNotification('<?= $jsMsg ?>', '<?= $jsMsgType ?>'));<?php endif; ?>

    function editJob(j) {
        document.getElementById('edit_id').value = j.id;
        document.getElementById('edit_customer_id').value = j.customer_id;
        document.getElementById('edit_name').value = j.name;
        document.getElementById('edit_backup_type').value = j.backup_type || '';
        document.getElementById('edit_note').value = j.note || '';
        document.getElementById('edit_search_term_mail').value = j.search_term_mail;
        document.getElementById('edit_search_term_subject').value = j.search_term_subject;
        document.getElementById('edit_search_term_text').value = j.search_term_text;
        document.getElementById('edit_search_term_text2').value = j.search_term_text2 || '';
        document.getElementById('edit_include_in_report').checked = j.include_in_report == 1;
        document.getElementById('edit_ignore_hours').value = j.ignore_no_status_updates_for_x_hours || '';
        openModal('editModal');
    }

    function confirmDeleteJob(id, name) {
        showConfirm('Backup-Job "' + name + '" wirklich löschen? Alle zugehörigen Backup-Ergebnisse werden ebenfalls gelöscht. Die verknüpften Mails bleiben erhalten.', () => {
            const f = document.createElement('form'); f.method = 'post';
            f.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' + id + '">';
            document.body.appendChild(f); f.submit();
        });
    }
    </script>
</body>
</html>
<?php $conn->close(); ?>