<?php
/**
 * UNVERARBEITETE E-MAILS
 * 
 * Pfad:    /settings/unprocessed-mails/index.php
 * Includes: ../../includes/styles.css, ../../includes/app.js
 */

$config = require_once '../../config.php';
$conn = new mysqli($config['server'], $config['user'], $config['password'], $config['database']);
if ($conn->connect_error) { die('Verbindungsfehler: ' . $conn->connect_error); }
$conn->set_charset('utf8mb4');
if (!isset($_SESSION)) { session_start(); }

// AJAX Handler für Mail-Content
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_mail_content') {
    $response = ['success' => false];
    if (isset($_POST['mail_id'])) {
        $mail_id = (int)$_POST['mail_id'];
        $result = $conn->query("SELECT content, subject, sender_email, created_at FROM mails WHERE id = $mail_id");
        if ($result && $row = $result->fetch_assoc()) {
            $response['success'] = true;
            $response['content'] = $row['content'];
            $response['subject'] = $row['subject'];
            $response['sender'] = $row['sender_email'];
            $response['created_at'] = $row['created_at'];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// POST-Verarbeitung
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'assign_job':
                $mail_id = (int)$_POST['mail_id'];
                $backup_job_id = (int)$_POST['backup_job_id'];
                $conn->query("INSERT INTO backup_results (backup_job_id, mail_id) VALUES ($backup_job_id, $mail_id)");
                $conn->query("UPDATE mails SET job_found = TRUE, result_processed = FALSE WHERE id = $mail_id");
                $_SESSION['message'] = "E-Mail erfolgreich einem Backup-Job zugewiesen. Die Verarbeitung erfolgt automatisch.";
                $_SESSION['message_type'] = "success";
                break;

            case 'delete_mail':
                $mail_id = (int)$_POST['mail_id'];
                $conn->query("DELETE FROM mails WHERE id = $mail_id");
                $_SESSION['message'] = "E-Mail erfolgreich gelöscht.";
                $_SESSION['message_type'] = "success";
                break;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$sort_by = isset($_GET['sort_by']) ? $conn->real_escape_string($_GET['sort_by']) : 'created_at';
$sort_order = isset($_GET['sort_order']) ? $conn->real_escape_string($_GET['sort_order']) : 'DESC';
if (!in_array($sort_by, ['sender_email', 'subject', 'created_at'])) $sort_by = 'created_at';
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'DESC';

$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$items_per_page = max(50, min(200, isset($_GET['items_per_page']) ? (int)$_GET['items_per_page'] : 50));
$offset = ($page - 1) * $items_per_page;

$sql_base = "FROM mails WHERE result_processed = FALSE";
if (!empty($search)) $sql_base .= " AND (sender_email LIKE '%$search%' OR subject LIKE '%$search%' OR content LIKE '%$search%')";

$total_items = $conn->query("SELECT COUNT(*) AS total " . $sql_base)->fetch_assoc()['total'];
$total_pages = ceil($total_items / $items_per_page);
if ($page > $total_pages && $total_pages > 0) { $page = $total_pages; $offset = ($page - 1) * $items_per_page; }
$result = $conn->query("SELECT id, sender_email, subject, date, created_at $sql_base ORDER BY $sort_by $sort_order LIMIT $offset, $items_per_page");

$customers = [];
$cr = $conn->query("SELECT id, name FROM customers ORDER BY name");
while ($c = $cr->fetch_assoc()) $customers[$c['id']] = $c['name'];

$backup_jobs_by_customer = [];
$jr = $conn->query("SELECT id, customer_id, name FROM backup_jobs ORDER BY name");
while ($j = $jr->fetch_assoc()) {
    if (!isset($backup_jobs_by_customer[$j['customer_id']])) $backup_jobs_by_customer[$j['customer_id']] = [];
    $backup_jobs_by_customer[$j['customer_id']][$j['id']] = $j['name'];
}

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
    <title>Unverarbeitete Mails – Backup-Monitor</title>
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
        .truncate-lg { max-width: 500px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .mail-iframe { width: 100%; border: none; min-height: 300px; border-radius: var(--border-radius-sm); background: #fff; }
    </style>
</head>
<body>
    <div class="container mx-auto px-4 py-6">

        <header class="page-header">
            <a href="../" class="back-button"><i class="fas fa-arrow-left"></i></a>
            <div class="page-header-title">
                <h1>Unverarbeitete E-Mails</h1>
                <p><?= $total_items ?> Mails</p>
            </div>
        </header>

        <!-- Suchleiste -->
        <div class="content-card mb-6" style="padding: 1rem;">
            <div class="search-bar-inner">
                <form method="get" style="flex: 1; display: flex;">
                    <div class="search-input-wrapper" style="flex: 1;">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" name="search" class="search-input" placeholder="Absender, Betreff oder Inhalt suchen..." value="<?= htmlspecialchars($search) ?>">
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

        <!-- E-Mail Liste -->
        <div class="content-card mb-6">
            <div class="section-header"><h2 class="section-title">Unverarbeitete E-Mails</h2></div>
            <?php if ($total_items == 0): ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><p>Keine unverarbeiteten E-Mails gefunden.</p><?php if (!empty($search)): ?><span>Versuche die Filtereinstellungen zu ändern.</span><?php endif; ?></div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table compact">
                        <thead><tr>
                            <th><a href="<?= getSortLink('sender_email', $sort_by, $sort_order) ?>" style="color:inherit;text-decoration:none;">Absender<?= getSortIndicator('sender_email', $sort_by, $sort_order) ?></a></th>
                            <th><a href="<?= getSortLink('subject', $sort_by, $sort_order) ?>" style="color:inherit;text-decoration:none;">Betreff<?= getSortIndicator('subject', $sort_by, $sort_order) ?></a></th>
                            <th><a href="<?= getSortLink('created_at', $sort_by, $sort_order) ?>" style="color:inherit;text-decoration:none;">Empfangen<?= getSortIndicator('created_at', $sort_by, $sort_order) ?></a></th>
                            <th class="text-right">Aktionen</th>
                        </tr></thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()):
                                $mailData = ['id' => $row['id'], 'sender_email' => $row['sender_email'], 'subject' => $row['subject'], 'created_at' => $row['created_at']];
                                $mailDataJson = htmlspecialchars(json_encode($mailData), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr class="clickable" onclick='showMailDetails(<?= $mailDataJson ?>)'>
                                <td><div class="truncate"><?= htmlspecialchars($row['sender_email']) ?></div></td>
                                <td><div class="truncate-lg"><span class="font-medium text-gray-900"><?= htmlspecialchars($row['subject']) ?></span></div></td>
                                <td><?= date('d.m.Y H:i', strtotime($row['created_at'])) ?></td>
                                <td class="text-right" onclick="event.stopPropagation();" style="white-space: nowrap;">
                                    <button onclick='showAssignModal(<?= $row["id"] ?>)' class="btn btn-outline btn-sm" title="Zuweisen"><i class="fas fa-link"></i></button>
                                    <button onclick='confirmDeleteMail(<?= $row["id"] ?>, <?= json_encode($row["subject"]) ?>)' class="btn-inline delete" title="Löschen"><i class="fas fa-trash"></i></button>
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

    <!-- MODAL: Mail-Details -->
    <div class="modal" id="mailModal"><div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h3 class="modal-title"><i class="fas fa-envelope text-blue-500"></i> E-Mail Details</h3><button class="modal-close" onclick="closeModal('mailModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body custom-scroll" id="mailModalBody">
            <!-- wird per JS befüllt -->
        </div>
        <div class="modal-footer spread">
            <div class="flex gap-2">
                <button type="button" class="btn btn-secondary btn-sm" id="modalAssignBtn"><i class="fas fa-link"></i> Zuweisen</button>
                <button type="button" class="btn btn-danger btn-sm" id="modalDeleteBtn"><i class="fas fa-trash"></i> Löschen</button>
            </div>
            <button class="btn btn-outline" onclick="closeModal('mailModal')">Schließen</button>
        </div>
    </div></div></div>

    <!-- MODAL: Job-Zuweisung -->
    <div class="modal chooser" id="assignModal"><div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h3 class="modal-title"><i class="fas fa-link text-blue-500"></i> Backup-Job zuweisen</h3><button class="modal-close" onclick="closeModal('assignModal')"><i class="fas fa-times"></i></button></div>
        <form method="post"><input type="hidden" name="action" value="assign_job"><input type="hidden" name="mail_id" id="assign_mail_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Kunde <span class="required">*</span></label>
                    <select id="assign_customer_id" class="form-select" required onchange="loadBackupJobs()">
                        <option value="">– Bitte Kunden wählen –</option>
                        <?php foreach ($customers as $id => $name): ?>
                            <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Backup-Job <span class="required">*</span></label>
                    <select id="backup_job_id" name="backup_job_id" class="form-select" required>
                        <option value="">Bitte zuerst Kunden wählen...</option>
                    </select>
                </div>
                <p style="font-size: 0.8125rem; color: var(--color-gray-500); margin-top: 0.5rem;">
                    <i class="fas fa-info-circle"></i> Nach der Zuweisung wird die E-Mail automatisch vom System verarbeitet.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('assignModal')">Abbrechen</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-link"></i> Zuweisen</button>
            </div>
        </form>
    </div></div></div>

    <script src="../../includes/app.js"></script>
    <script>
    <?php if ($jsMsg): ?>document.addEventListener('DOMContentLoaded', () => showNotification('<?= $jsMsg ?>', '<?= $jsMsgType ?>'));<?php endif; ?>

    let currentMailId = null;

    // Mail-Details laden und in Modal anzeigen (mit iframe für HTML-Mails)
    async function showMailDetails(mailData) {
        currentMailId = mailData.id;
        openModal('mailModal');
        showLoading('mailModalBody', 'Mail wird geladen...');

        try {
            const formData = new FormData();
            formData.append('action', 'get_mail_content');
            formData.append('mail_id', mailData.id);
            const response = await fetch('', { method: 'POST', body: formData });
            const result = await response.json();

            if (!result.success) throw new Error('Laden fehlgeschlagen');

            const containsHTML = /<[a-z][\s\S]*>/i.test(result.content);
            let contentHtml;
            if (containsHTML) {
                const escaped = result.content.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
                contentHtml = '<iframe class="mail-iframe" sandbox="allow-same-origin" srcdoc="' + escaped + '" onload="this.style.height=this.contentDocument.body.scrollHeight+20+\'px\'"></iframe>';
            } else {
                contentHtml = '<pre style="white-space:pre-wrap;font-family:monospace;font-size:0.875rem;background:var(--color-gray-50);padding:1rem;border-radius:var(--border-radius-sm);">' + escHtml(result.content) + '</pre>';
            }

            hideLoading('mailModalBody', `
                <div class="space-y-4">
                    <div class="modal-card">
                        <h4 class="modal-card-title"><i class="fas fa-info-circle"></i> Info</h4>
                        <div class="detail-grid">
                            <span class="label">Absender:</span>
                            <span class="value">${escHtml(result.sender || '–')}</span>
                            <span class="label">Betreff:</span>
                            <span class="value">${escHtml(result.subject || '–')}</span>
                            <span class="label">Empfangen:</span>
                            <span class="value">${new Date(result.created_at).toLocaleString('de-DE')}</span>
                        </div>
                    </div>
                    <div class="modal-card">
                        <h4 class="modal-card-title"><i class="fas fa-envelope-open-text"></i> Inhalt</h4>
                        ${contentHtml}
                    </div>
                </div>
            `);
        } catch (error) {
            hideLoading('mailModalBody', '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Fehler beim Laden des Mail-Inhalts</p></div>');
        }

        // Footer-Buttons mit aktueller Mail-ID verbinden
        document.getElementById('modalAssignBtn').onclick = function() {
            closeModal('mailModal');
            showAssignModal(currentMailId);
        };
        document.getElementById('modalDeleteBtn').onclick = function() {
            closeModal('mailModal');
            confirmDeleteMail(currentMailId, mailData.subject);
        };
    }

    function showAssignModal(mailId) {
        document.getElementById('assign_mail_id').value = mailId;
        // Reset der Selects
        document.getElementById('assign_customer_id').value = '';
        document.getElementById('backup_job_id').innerHTML = '<option value="">Bitte zuerst Kunden wählen...</option>';
        openModal('assignModal');
    }

    function confirmDeleteMail(mailId, subject) {
        showConfirm('E-Mail "' + subject + '" wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.', () => {
            const f = document.createElement('form'); f.method = 'post';
            f.innerHTML = '<input type="hidden" name="action" value="delete_mail"><input type="hidden" name="mail_id" value="' + mailId + '">';
            document.body.appendChild(f); f.submit();
        });
    }

    // Backup-Jobs nach Kundenwahl laden
    const backupJobsByCustomer = <?= json_encode($backup_jobs_by_customer) ?>;

    function loadBackupJobs() {
        const customerId = document.getElementById('assign_customer_id').value;
        const select = document.getElementById('backup_job_id');
        select.innerHTML = '';

        if (!customerId) {
            select.innerHTML = '<option value="">Bitte zuerst Kunden wählen...</option>';
            return;
        }

        select.innerHTML = '<option value="">– Bitte Job wählen –</option>';
        if (backupJobsByCustomer[customerId]) {
            for (const [jobId, jobName] of Object.entries(backupJobsByCustomer[customerId])) {
                const opt = document.createElement('option');
                opt.value = jobId;
                opt.textContent = jobName;
                select.appendChild(opt);
            }
        }
    }
    </script>
</body>
</html>
<?php $conn->close(); ?>