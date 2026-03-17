<?php
/**
 * E-MAIL ÜBERSICHT — Alle Mails
 * 
 * Pfad:    /settings/all-mails/index.php
 * Includes: ../../includes/styles.css, ../../includes/app.js
 */

$config = require_once '../../config.php';
$conn = new mysqli($config['server'], $config['user'], $config['password'], $config['database']);
if ($conn->connect_error) { die('Verbindungsfehler: ' . $conn->connect_error); }
$conn->set_charset('utf8mb4');
if (!isset($_SESSION)) { session_start(); }

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$customer_filter = isset($_GET['customer_filter']) ? (int)$_GET['customer_filter'] : 0;
$backup_job_filter = isset($_GET['backup_job_filter']) ? (int)$_GET['backup_job_filter'] : 0;
$status_filter = isset($_GET['status_filter']) ? $conn->real_escape_string($_GET['status_filter']) : '';
$date_from = isset($_GET['date_from']) ? $conn->real_escape_string($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? $conn->real_escape_string($_GET['date_to']) : '';
$processed_filter = isset($_GET['processed_filter']) ? $conn->real_escape_string($_GET['processed_filter']) : '';
$sort_by = isset($_GET['sort_by']) ? $conn->real_escape_string($_GET['sort_by']) : 'mail_date';
$sort_order = isset($_GET['sort_order']) ? $conn->real_escape_string($_GET['sort_order']) : 'DESC';

if (!in_array($sort_by, ['mail_date', 'sender_email', 'subject', 'backup_job_name', 'customer_name', 'backup_status'])) $sort_by = 'mail_date';
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'DESC';

$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$items_per_page = max(50, min(200, isset($_GET['items_per_page']) ? (int)$_GET['items_per_page'] : 50));
$offset = ($page - 1) * $items_per_page;

$customers = []; $cr = $conn->query("SELECT id, name FROM customers ORDER BY name"); while ($r = $cr->fetch_assoc()) $customers[$r['id']] = $r['name'];
$jobs = []; $jr = $conn->query("SELECT id, name FROM backup_jobs ORDER BY name"); while ($r = $jr->fetch_assoc()) $jobs[$r['id']] = $r['name'];

$sql_base = "FROM mails m LEFT JOIN backup_results br ON br.mail_id = m.id LEFT JOIN backup_jobs bj ON br.backup_job_id = bj.id OR bj.id IS NULL LEFT JOIN customers c ON bj.customer_id = c.id OR c.id IS NULL WHERE 1=1";
if (!empty($search)) $sql_base .= " AND (m.sender_email LIKE '%$search%' OR m.subject LIKE '%$search%' OR m.content LIKE '%$search%' OR bj.name LIKE '%$search%' OR c.name LIKE '%$search%')";
if ($customer_filter > 0) $sql_base .= " AND c.id = $customer_filter";
if ($backup_job_filter > 0) $sql_base .= " AND bj.id = $backup_job_filter";
if (!empty($status_filter)) $sql_base .= " AND br.status = '$status_filter'";
if (!empty($date_from)) $sql_base .= " AND m.date >= '$date_from 00:00:00'";
if (!empty($date_to)) $sql_base .= " AND m.date <= '$date_to 23:59:59'";
if ($processed_filter === '1') $sql_base .= " AND m.result_processed = 1";
elseif ($processed_filter === '0') $sql_base .= " AND m.result_processed = 0";

$total_items = $conn->query("SELECT COUNT(DISTINCT m.id) AS total " . $sql_base)->fetch_assoc()['total'];
$total_pages = ceil($total_items / $items_per_page);
if ($page > $total_pages && $total_pages > 0) { $page = $total_pages; $offset = ($page - 1) * $items_per_page; }

$order_map = ['customer_name'=>'c_name','backup_job_name'=>'bj_name','backup_status'=>'br.status','mail_date'=>'m.date'];
$order_col = $order_map[$sort_by] ?? "m.$sort_by";
$result = $conn->query("SELECT m.id as mail_id, m.date as mail_date, m.sender_email, m.subject, m.result_processed, m.job_found, m.content, m.created_at as mail_created_at, bj.id as backup_job_id, bj.name as backup_job_name, bj.note as backup_job_note, bj.search_term_mail, bj.search_term_subject, bj.search_term_text, bj.search_term_text2, c.id as customer_id, c.name as customer_name, br.id as backup_result_id, br.status as backup_status, br.date as backup_date, br.time as backup_time, br.note as backup_note, br.size_mb, br.duration_minutes FROM (SELECT DISTINCT m.id, m.date, c.name as c_name, bj.name as bj_name, br.status $sql_base ORDER BY $order_col $sort_order LIMIT $offset, $items_per_page) AS filtered_ids JOIN mails m ON m.id = filtered_ids.id LEFT JOIN backup_results br ON br.mail_id = m.id LEFT JOIN backup_jobs bj ON br.backup_job_id = bj.id OR bj.id IS NULL LEFT JOIN customers c ON bj.customer_id = c.id OR c.id IS NULL");

function getSortLink($f, $csb, $cso) { $p = $_GET; $p['sort_by'] = $f; $p['sort_order'] = ($csb === $f && $cso === 'ASC') ? 'DESC' : 'ASC'; return '?' . http_build_query($p); }
function getPaginationLink($pn) { $p = $_GET; $p['page'] = $pn; return '?' . http_build_query($p); }
function getFilterLink($k, $v) { $p = $_GET; if ($v === '') unset($p[$k]); else $p[$k] = $v; $p['page'] = 1; return '?' . http_build_query($p); }
function getSortIndicator($f, $csb, $cso) { return ($csb === $f) ? (($cso === 'ASC') ? ' ▲' : ' ▼') : ''; }

$hasAdvanced = !empty($date_from) || !empty($date_to) || $backup_job_filter > 0;
$hasAnyFilter = !empty($search) || $customer_filter > 0 || $backup_job_filter > 0 || !empty($status_filter) || !empty($date_from) || !empty($date_to) || $processed_filter !== '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Mail Übersicht – Backup-Monitor</title>
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
        .truncate { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .advanced-filters { display: none; padding-top: 1rem; border-top: 1px dashed var(--color-gray-200); margin-top: 0.75rem; }
        .advanced-filters.show { display: flex; flex-wrap: wrap; gap: 1rem; align-items: end; }
        .mail-iframe { width: 100%; border: none; min-height: 300px; border-radius: var(--border-radius-sm); background: #fff; }
    </style>
</head>
<body>
    <div class="container mx-auto px-4 py-6">

        <header class="page-header">
            <a href="../" class="back-button"><i class="fas fa-arrow-left"></i></a>
            <div class="page-header-title">
                <h1>E-Mail Übersicht</h1>
                <p><?= $total_items ?> Mails</p>
            </div>
        </header>

        <!-- Filter -->
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
                    <?php foreach ($customers as $id => $name): ?><option value="<?= getFilterLink('customer_filter', $id) ?>" <?= $customer_filter == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?>
                </select>
                <select class="form-select" style="width: auto;" onchange="window.location=this.value">
                    <option value="<?= getFilterLink('processed_filter', '') ?>">Verarbeitet: Alle</option>
                    <option value="<?= getFilterLink('processed_filter', '1') ?>" <?= $processed_filter === '1' ? 'selected' : '' ?>>Ja</option>
                    <option value="<?= getFilterLink('processed_filter', '0') ?>" <?= $processed_filter === '0' ? 'selected' : '' ?>>Nein</option>
                </select>
                <select class="form-select" style="width: auto;" onchange="window.location=this.value">
                    <option value="<?= getFilterLink('status_filter', '') ?>">Alle Status</option>
                    <option value="<?= getFilterLink('status_filter', 'success') ?>" <?= $status_filter == 'success' ? 'selected' : '' ?>>Erfolgreich</option>
                    <option value="<?= getFilterLink('status_filter', 'warning') ?>" <?= $status_filter == 'warning' ? 'selected' : '' ?>>Warnung</option>
                    <option value="<?= getFilterLink('status_filter', 'error') ?>" <?= $status_filter == 'error' ? 'selected' : '' ?>>Fehler</option>
                </select>
                <select class="form-select" style="width: auto;" onchange="window.location=this.value">
                    <?php foreach ([50, 100, 200] as $v): ?><option value="<?= getFilterLink('items_per_page', $v) ?>" <?= $items_per_page == $v ? 'selected' : '' ?>><?= $v ?> / Seite</option><?php endforeach; ?>
                </select>
                <button type="button" id="advToggle" class="btn btn-outline btn-sm"><i class="fas fa-filter"></i> Erweitert</button>
                <?php if ($hasAnyFilter): ?><a href="?" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Zurücksetzen</a><?php endif; ?>
            </div>
            <div id="advFilters" class="advanced-filters <?= $hasAdvanced ? 'show' : '' ?>">
                <div class="form-group" style="margin-bottom:0;"><label class="form-label">Datum von</label><input type="date" id="date_from" class="form-input" value="<?= htmlspecialchars($date_from) ?>" onchange="applyDateFilter()"></div>
                <div class="form-group" style="margin-bottom:0;"><label class="form-label">Datum bis</label><input type="date" id="date_to" class="form-input" value="<?= htmlspecialchars($date_to) ?>" onchange="applyDateFilter()"></div>
                <div class="form-group" style="margin-bottom:0;"><label class="form-label">Backup-Job</label>
                    <select class="form-select" onchange="window.location=this.value">
                        <option value="<?= getFilterLink('backup_job_filter', '') ?>">Alle Jobs</option>
                        <?php foreach ($jobs as $id => $name): ?><option value="<?= getFilterLink('backup_job_filter', $id) ?>" <?= $backup_job_filter == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- E-Mail Liste -->
        <div class="content-card mb-6">
            <div class="section-header"><h2 class="section-title">E-Mail Liste</h2></div>
            <?php if ($total_items == 0): ?>
                <div class="empty-state"><i class="fas fa-envelope"></i><p>Keine E-Mails gefunden.</p><?php if ($hasAnyFilter): ?><span>Versuche die Filtereinstellungen zu ändern.</span><?php endif; ?></div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table compact">
                        <thead><tr>
                            <th><a href="<?= getSortLink('mail_date', $sort_by, $sort_order) ?>" style="color:inherit;text-decoration:none;">Datum<?= getSortIndicator('mail_date', $sort_by, $sort_order) ?></a></th>
                            <th><a href="<?= getSortLink('sender_email', $sort_by, $sort_order) ?>" style="color:inherit;text-decoration:none;">Absender<?= getSortIndicator('sender_email', $sort_by, $sort_order) ?></a></th>
                            <th><a href="<?= getSortLink('subject', $sort_by, $sort_order) ?>" style="color:inherit;text-decoration:none;">Betreff<?= getSortIndicator('subject', $sort_by, $sort_order) ?></a></th>
                            <th><a href="<?= getSortLink('backup_job_name', $sort_by, $sort_order) ?>" style="color:inherit;text-decoration:none;">Job<?= getSortIndicator('backup_job_name', $sort_by, $sort_order) ?></a></th>
                            <th><a href="<?= getSortLink('customer_name', $sort_by, $sort_order) ?>" style="color:inherit;text-decoration:none;">Kunde<?= getSortIndicator('customer_name', $sort_by, $sort_order) ?></a></th>
                            <th><a href="<?= getSortLink('backup_status', $sort_by, $sort_order) ?>" style="color:inherit;text-decoration:none;">Status<?= getSortIndicator('backup_status', $sort_by, $sort_order) ?></a></th>
                            <th>Verarb.</th>
                        </tr></thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): $rd = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>
                            <tr class="clickable" onclick='showDetails(<?= $rd ?>)'>
                                <td><?= date('d.m.Y H:i', strtotime($row['mail_date'])) ?></td>
                                <td><div class="truncate" title="<?= htmlspecialchars($row['sender_email']) ?>"><?= htmlspecialchars($row['sender_email']) ?></div></td>
                                <td><div class="truncate" title="<?= htmlspecialchars($row['subject']) ?>"><?= htmlspecialchars($row['subject']) ?></div></td>
                                <td><?= $row['backup_job_name'] ? htmlspecialchars($row['backup_job_name']) : ($row['job_found'] ? '<span class="badge badge-gray">Zugeordnet</span>' : '–') ?></td>
                                <td><?= $row['customer_name'] ? htmlspecialchars($row['customer_name']) : '–' ?></td>
                                <td><?php if ($row['backup_status']): $bs=$row['backup_status']; ?><span class="badge badge-<?= $bs==='success'?'success':($bs==='warning'?'warning':'danger') ?>"><?= $bs==='success'?'Erfolgreich':($bs==='warning'?'Warnung':'Fehler') ?></span><?php else: ?>–<?php endif; ?></td>
                                <td><?= $row['result_processed'] ? '<i class="fas fa-check text-green-500"></i>' : '<i class="fas fa-times text-red-500"></i>' ?></td>
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

    <!-- MODAL: E-Mail Details -->
    <div class="modal" id="detailModal"><div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h3 class="modal-title"><i class="fas fa-envelope text-blue-500"></i> E-Mail Details</h3><button class="modal-close" onclick="closeModal('detailModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body custom-scroll" id="detailBody">
            <!-- E-Mail Info -->
            <div class="modal-card" style="margin-bottom: 1rem;">
                <h4 class="modal-card-title"><i class="fas fa-envelope"></i> E-Mail Informationen</h4>
                <div class="detail-grid">
                    <span class="label">Datum:</span><span class="value" id="mailDate"></span>
                    <span class="label">Absender:</span><span class="value" id="mailSender"></span>
                    <span class="label">Betreff:</span><span class="value" id="mailSubject"></span>
                    <span class="label">Verarbeitet:</span><span class="value" id="mailProcessed"></span>
                </div>
                <div style="margin-top: 1rem;" id="mailContentWrap"></div>
            </div>
            <!-- Backup-Job Info -->
            <div class="modal-card" style="margin-bottom: 1rem;">
                <h4 class="modal-card-title"><i class="fas fa-briefcase"></i> Backup-Job</h4>
                <div class="detail-grid">
                    <span class="label">Name:</span><span class="value" id="jobName"></span>
                    <span class="label">Kunde:</span><span class="value" id="customerName"></span>
                    <span class="label">E-Mail Suchwort:</span><span class="value" id="stMail"></span>
                    <span class="label">Betreff Suchwort:</span><span class="value" id="stSubject"></span>
                    <span class="label">Text Suchwort 1:</span><span class="value" id="stText"></span>
                    <span class="label">Text Suchwort 2:</span><span class="value" id="stText2"></span>
                    <span class="label">Notiz:</span><span class="value" id="jobNote"></span>
                </div>
            </div>
            <!-- Backup-Ergebnis -->
            <div class="modal-card">
                <h4 class="modal-card-title"><i class="fas fa-chart-bar"></i> Backup-Ergebnis</h4>
                <div class="detail-grid">
                    <span class="label">Status:</span><span class="value" id="bStatus"></span>
                    <span class="label">Datum:</span><span class="value" id="bDate"></span>
                    <span class="label">Uhrzeit:</span><span class="value" id="bTime"></span>
                    <span class="label">Größe:</span><span class="value" id="bSize"></span>
                    <span class="label">Dauer:</span><span class="value" id="bDuration"></span>
                    <span class="label">Notiz:</span><span class="value" id="bNote"></span>
                </div>
            </div>
        </div>
        <div class="modal-footer"><button class="btn btn-outline" onclick="closeModal('detailModal')">Schließen</button></div>
    </div></div></div>

    <script src="../../includes/app.js"></script>
    <script>
    document.getElementById('advToggle').addEventListener('click', () => {
        document.getElementById('advFilters').classList.toggle('show');
    });

    function applyDateFilter() {
        const url = new URL(window.location.href);
        const df = document.getElementById('date_from').value;
        const dt = document.getElementById('date_to').value;
        df ? url.searchParams.set('date_from', df) : url.searchParams.delete('date_from');
        dt ? url.searchParams.set('date_to', dt) : url.searchParams.delete('date_to');
        url.searchParams.set('page', 1);
        window.location.href = url.toString();
    }

    function showDetails(d) {
        document.getElementById('mailDate').textContent = new Date(d.mail_date).toLocaleString('de-DE');
        document.getElementById('mailSender').textContent = d.sender_email || '–';
        document.getElementById('mailSubject').textContent = d.subject || '–';
        document.getElementById('mailProcessed').textContent = d.result_processed == 1 ? 'Ja' : 'Nein';

        // Mail-Inhalt
        const wrap = document.getElementById('mailContentWrap');
        const content = d.content || 'Kein Inhalt verfügbar';
        const isHTML = /<[a-z][\s\S]*>/i.test(content);
        if (isHTML) {
            const escaped = content.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
            wrap.innerHTML = '<iframe class="mail-iframe" sandbox="allow-same-origin" srcdoc="' + escaped + '" onload="this.style.height=this.contentDocument.body.scrollHeight+20+\'px\'"></iframe>';
        } else {
            wrap.innerHTML = '<pre style="white-space:pre-wrap;font-family:monospace;font-size:0.875rem;background:var(--color-gray-50);padding:1rem;border-radius:var(--border-radius-sm);">' + escHtml(content) + '</pre>';
        }

        // Backup-Job
        document.getElementById('jobName').textContent = d.backup_job_name || '–';
        document.getElementById('customerName').textContent = d.customer_name || '–';
        document.getElementById('stMail').textContent = d.search_term_mail || '–';
        document.getElementById('stSubject').textContent = d.search_term_subject || '–';
        document.getElementById('stText').textContent = d.search_term_text || '–';
        document.getElementById('stText2').textContent = d.search_term_text2 || '–';
        document.getElementById('jobNote').textContent = d.backup_job_note || '–';

        // Backup-Ergebnis
        const s = d.backup_status;
        const bsEl = document.getElementById('bStatus');
        if (s) {
            const lbl = s === 'success' ? 'Erfolgreich' : (s === 'warning' ? 'Warnung' : 'Fehler');
            const cls = s === 'success' ? 'badge-success' : (s === 'warning' ? 'badge-warning' : 'badge-danger');
            bsEl.innerHTML = '<span class="badge ' + cls + '">' + lbl + '</span>';
        } else { bsEl.textContent = '–'; }

        document.getElementById('bDate').textContent = d.backup_date ? new Date(d.backup_date).toLocaleDateString('de-DE') : '–';
        document.getElementById('bTime').textContent = d.backup_time || '–';
        document.getElementById('bSize').textContent = d.size_mb ? d.size_mb + ' MB' : '–';
        document.getElementById('bDuration').textContent = d.duration_minutes ? d.duration_minutes + ' min' : '–';
        document.getElementById('bNote').textContent = d.backup_note || '–';

        openModal('detailModal');
    }
    </script>
</body>
</html>
<?php $conn->close(); ?>