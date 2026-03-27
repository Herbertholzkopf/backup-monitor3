<?php
/**
 * BACKUP-JOBS VERWALTUNG (Split-Layout)
 * 
 * Pfad:    /settings/backup-jobs/index.php
 * Includes: ../../includes/styles.css, ../../includes/app.js
 * 
 * Layout: Links Kundenliste mit Suche, rechts Jobs des gewählten Kunden.
 * Nach CRUD-Aktionen bleibt man auf dem ausgewählten Kunden.
 */

$config = require_once '../../config.php';
$conn = new mysqli($config['server'], $config['user'], $config['password'], $config['database']);
if ($conn->connect_error) { die('Verbindungsfehler: ' . $conn->connect_error); }
$conn->set_charset('utf8mb4');
if (!isset($_SESSION)) { session_start(); }

// ─── POST-Verarbeitung ───
// Nach jeder Aktion: Redirect zurück mit customer_id, damit man auf dem Kunden bleibt
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $redirect_customer = 0;

    switch ($_POST['action']) {
        case 'add':
            $customer_id = (int)$_POST['customer_id'];
            $redirect_customer = $customer_id;
            $stmt = $conn->prepare("INSERT INTO backup_jobs (customer_id, name, note, backup_type, search_term_mail, search_term_subject, search_term_text, search_term_text2, include_in_report, ignore_no_status_updates_for_x_hours) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $name = trim($_POST['name']);
            $note = trim($_POST['note'] ?? '');
            $backup_type = trim($_POST['backup_type'] ?? '');
            $stm = trim($_POST['search_term_mail'] ?? '');
            $sts = trim($_POST['search_term_subject'] ?? '');
            $stt = trim($_POST['search_term_text'] ?? '');
            $stt2 = trim($_POST['search_term_text2'] ?? '');
            $include = isset($_POST['include_in_report']) ? 1 : 0;
            $hours = !empty($_POST['ignore_no_status_updates_for_x_hours']) ? (int)$_POST['ignore_no_status_updates_for_x_hours'] : null;
            $stmt->bind_param('isssssssii', $customer_id, $name, $note, $backup_type, $stm, $sts, $stt, $stt2, $include, $hours);
            $stmt->execute();
            $stmt->close();
            $_SESSION['message'] = "Backup-Job erfolgreich erstellt.";
            $_SESSION['message_type'] = "success";
            break;

        case 'edit':
            $id = (int)$_POST['id'];
            $customer_id = (int)$_POST['customer_id'];
            $redirect_customer = $customer_id;
            $stmt = $conn->prepare("UPDATE backup_jobs SET customer_id=?, name=?, note=?, backup_type=?, search_term_mail=?, search_term_subject=?, search_term_text=?, search_term_text2=?, include_in_report=?, ignore_no_status_updates_for_x_hours=? WHERE id=?");
            $name = trim($_POST['name']);
            $note = trim($_POST['note'] ?? '');
            $backup_type = trim($_POST['backup_type'] ?? '');
            $stm = trim($_POST['search_term_mail'] ?? '');
            $sts = trim($_POST['search_term_subject'] ?? '');
            $stt = trim($_POST['search_term_text'] ?? '');
            $stt2 = trim($_POST['search_term_text2'] ?? '');
            $include = isset($_POST['include_in_report']) ? 1 : 0;
            $hours = !empty($_POST['ignore_no_status_updates_for_x_hours']) ? (int)$_POST['ignore_no_status_updates_for_x_hours'] : null;
            $stmt->bind_param('isssssssiii', $customer_id, $name, $note, $backup_type, $stm, $sts, $stt, $stt2, $include, $hours, $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['message'] = "Backup-Job erfolgreich aktualisiert.";
            $_SESSION['message_type'] = "success";
            break;

        case 'delete':
            $id = (int)$_POST['id'];
            $redirect_customer = (int)($_POST['redirect_customer'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM backup_jobs WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['message'] = "Backup-Job erfolgreich gelöscht.";
            $_SESSION['message_type'] = "success";
            break;
    }

    $redirect_url = $_SERVER['PHP_SELF'];
    if ($redirect_customer > 0) {
        $redirect_url .= '?customer=' . $redirect_customer;
    }
    header("Location: " . $redirect_url);
    exit();
}

// ─── Daten laden ───
$selected_customer = isset($_GET['customer']) ? (int)$_GET['customer'] : 0;

// Alle Kunden mit Job-Anzahl laden
$customersResult = $conn->query("
    SELECT c.id, c.name, c.number, COUNT(bj.id) AS job_count
    FROM customers c
    LEFT JOIN backup_jobs bj ON c.id = bj.customer_id
    GROUP BY c.id
    ORDER BY c.name
");
$allCustomers = [];
while ($row = $customersResult->fetch_assoc()) {
    $allCustomers[] = $row;
}

// Backup-Typen für Datalist
$backup_types = [];
$btr = $conn->query("SELECT DISTINCT backup_type FROM backup_jobs WHERE backup_type IS NOT NULL AND backup_type != '' ORDER BY backup_type");
while ($t = $btr->fetch_assoc()) $backup_types[] = $t['backup_type'];

// Job-Namen pro Kunde laden (ein Query statt N+1, für Client-Side-Suche)
$jobNamesByCustomer = [];
$jnResult = $conn->query("SELECT customer_id, LOWER(name) AS name FROM backup_jobs ORDER BY customer_id");
while ($row = $jnResult->fetch_assoc()) {
    $cid = $row['customer_id'];
    if (!isset($jobNamesByCustomer[$cid])) $jobNamesByCustomer[$cid] = [];
    $jobNamesByCustomer[$cid][] = $row['name'];
}

// Jobs des ausgewählten Kunden laden
$selectedCustomerData = null;
$jobs = [];
if ($selected_customer > 0) {
    $stmt = $conn->prepare("SELECT id, name, number FROM customers WHERE id = ?");
    $stmt->bind_param('i', $selected_customer);
    $stmt->execute();
    $r = $stmt->get_result();
    $selectedCustomerData = $r->fetch_assoc();
    $stmt->close();

    if ($selectedCustomerData) {
        $stmt = $conn->prepare("SELECT * FROM backup_jobs WHERE customer_id = ? ORDER BY name");
        $stmt->bind_param('i', $selected_customer);
        $stmt->execute();
        $jobsResult = $stmt->get_result();
        while ($row = $jobsResult->fetch_assoc()) {
            $jobs[] = $row;
        }
        $stmt->close();
    }
}

// Gesamtzahl Jobs
$totalJobs = $conn->query("SELECT COUNT(*) AS c FROM backup_jobs")->fetch_assoc()['c'];

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
        /* ─── Split-Layout ─── */
        .split-layout {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 1.5rem;
            align-items: start;
        }

        /* ─── Linke Spalte: Kundenliste ─── */
        .customer-panel {
            background: #ffffff;
            border-radius: var(--border-radius);
            border: 1px solid var(--color-gray-200);
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 1.5rem;
            max-height: calc(100vh - 10rem);
            display: flex;
            flex-direction: column;
        }

        .customer-panel-header {
            padding: 1rem;
            border-bottom: 1px solid var(--color-gray-200);
        }

        .customer-panel-header h2 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--color-gray-800);
            margin-bottom: 0.75rem;
        }

        .customer-search-wrap {
            position: relative;
        }

        .customer-search-wrap .search-icon-sm {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--color-gray-400);
            font-size: 0.8125rem;
            pointer-events: none;
        }

        .customer-search {
            width: 100%;
            padding: 0.5rem 0.75rem 0.5rem 2.25rem;
            border: 1px solid var(--color-gray-200);
            border-radius: var(--border-radius);
            font-size: 0.8125rem;
            color: var(--color-gray-800);
            background: var(--color-gray-50);
            transition: border-color var(--transition-fast);
        }

        .customer-search:focus {
            outline: none;
            border-color: var(--color-primary);
            background: #ffffff;
        }

        .search-mode-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: var(--color-gray-500);
        }

        .search-mode-row input[type="checkbox"] {
            width: 0.875rem;
            height: 0.875rem;
            accent-color: var(--color-primary);
        }

        .customer-list {
            flex: 1;
            overflow-y: auto;
            padding: 0.5rem;
        }

        .customer-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.625rem 0.75rem;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all var(--transition-fast);
            text-decoration: none;
            color: var(--color-gray-700);
            gap: 0.5rem;
        }

        .customer-item:hover {
            background-color: var(--color-gray-100);
        }

        .customer-item.active {
            background-color: var(--color-primary-light);
            color: var(--color-primary);
            font-weight: 500;
        }

        .customer-item-info {
            min-width: 0;
            flex: 1;
        }

        .customer-item-name {
            font-size: 0.875rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .customer-item-number {
            font-size: 0.6875rem;
            color: var(--color-gray-400);
        }

        .customer-item.active .customer-item-number {
            color: var(--color-primary);
            opacity: 0.7;
        }

        .customer-item-badge {
            background: var(--color-gray-100);
            color: var(--color-gray-500);
            font-size: 0.6875rem;
            font-weight: 600;
            padding: 0.125rem 0.5rem;
            border-radius: 9999px;
            flex-shrink: 0;
        }

        .customer-item.active .customer-item-badge {
            background: var(--color-primary);
            color: #ffffff;
        }

        .customer-list-empty {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--color-gray-400);
            font-size: 0.8125rem;
        }

        /* ─── Rechte Spalte: Job-Bereich ─── */
        .jobs-panel-empty {
            text-align: center;
            padding: 4rem 2rem;
        }

        .jobs-panel-empty i {
            font-size: 3rem;
            color: var(--color-gray-300);
            margin-bottom: 1rem;
            display: block;
        }

        .jobs-panel-empty p {
            color: var(--color-gray-500);
            font-size: 0.9375rem;
        }

        .jobs-panel-empty span {
            color: var(--color-gray-400);
            font-size: 0.8125rem;
            display: block;
            margin-top: 0.25rem;
        }

        /* Job-Karten */
        .job-card {
            background: #ffffff;
            border-radius: var(--border-radius);
            border: 1px solid var(--color-gray-200);
            box-shadow: var(--shadow-sm);
            padding: 1.25rem;
            transition: box-shadow var(--transition-fast);
        }

        .job-card:hover {
            box-shadow: var(--shadow);
        }

        .job-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .job-card-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--color-gray-800);
        }

        .job-card-actions {
            display: flex;
            gap: 0.25rem;
            flex-shrink: 0;
        }

        .job-card-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        .job-card-field {
            font-size: 0.8125rem;
        }

        .job-card-field .label {
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--color-gray-500);
            font-weight: 600;
            margin-bottom: 0.125rem;
        }

        .job-card-field .value {
            color: var(--color-gray-800);
            word-break: break-word;
        }

        .job-card-field .value:empty::after {
            content: '–';
            color: var(--color-gray-300);
        }

        .job-card-divider {
            border: none;
            border-top: 1px solid var(--color-gray-100);
            margin: 0.75rem 0;
        }

        .job-card-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.75rem;
            color: var(--color-gray-400);
        }

        /* Toggle Switch (reused from original) */
        .toggle-switch { display: flex; align-items: center; justify-content: space-between; }
        .toggle-switch input[type="checkbox"] { width: 44px; height: 24px; appearance: none; background-color: var(--color-gray-300); border-radius: 12px; position: relative; cursor: pointer; transition: background-color 0.2s; flex-shrink: 0; }
        .toggle-switch input[type="checkbox"]:checked { background-color: var(--color-success); }
        .toggle-switch input[type="checkbox"]::before { content: ''; position: absolute; width: 20px; height: 20px; border-radius: 50%; background: #fff; top: 2px; left: 2px; transition: transform 0.2s; }
        .toggle-switch input[type="checkbox"]:checked::before { transform: translateX(20px); }
        .form-hint { font-size: 0.75rem; color: var(--color-gray-500); margin-top: 0.25rem; line-height: 1.4; }

        /* Scrollbar für Kundenliste */
        .customer-list::-webkit-scrollbar { width: 5px; }
        .customer-list::-webkit-scrollbar-track { background: transparent; }
        .customer-list::-webkit-scrollbar-thumb { background: var(--color-gray-300); border-radius: 4px; }
        .customer-list::-webkit-scrollbar-thumb:hover { background: var(--color-gray-400); }

        /* Responsive */
        @media (max-width: 900px) {
            .split-layout {
                grid-template-columns: 1fr;
            }
            .customer-panel {
                position: static;
                max-height: 40vh;
            }
        }
    </style>
</head>
<body>
    <div class="container mx-auto px-4 py-6">

        <header class="page-header">
            <a href="../" class="back-button"><i class="fas fa-arrow-left"></i></a>
            <div class="page-header-title">
                <h1>Backup-Jobs Verwaltung</h1>
                <p><?= $totalJobs ?> Jobs bei <?= count($allCustomers) ?> Kunden</p>
            </div>
            <a href="../customers" class="btn btn-secondary"><i class="fas fa-users"></i> Kunden verwalten</a>
            <?php if ($selected_customer > 0 && $selectedCustomerData): ?>
                <button onclick="openAddModal()" class="btn btn-primary"><i class="fas fa-plus"></i> Neuen Job anlegen</button>
            <?php endif; ?>
        </header>

        <div class="split-layout">

            <!-- ════════════════════════════════════════
                 LINKE SPALTE: Kundenliste
                 ════════════════════════════════════════ -->
            <div class="customer-panel">
                <div class="customer-panel-header">
                    <h2><i class="fas fa-users" style="margin-right: 0.5rem; color: var(--color-gray-400);"></i>Kunden</h2>
                    <div class="customer-search-wrap">
                        <i class="fas fa-search search-icon-sm"></i>
                        <input type="text" id="customerSearch" class="customer-search"
                               placeholder="Kundenname oder Nummer..." autocomplete="off">
                    </div>
                    <div class="search-mode-row">
                        <input type="checkbox" id="searchJobNames">
                        <label for="searchJobNames" style="cursor: pointer;">Auch in Job-Namen suchen</label>
                    </div>
                </div>
                <div class="customer-list" id="customerList">
                    <?php foreach ($allCustomers as $c): ?>
                        <a href="?customer=<?= $c['id'] ?>"
                           class="customer-item <?= ($selected_customer == $c['id']) ? 'active' : '' ?>"
                           data-name="<?= htmlspecialchars(strtolower($c['name'])) ?>"
                           data-number="<?= htmlspecialchars(strtolower($c['number'])) ?>"
                           data-id="<?= $c['id'] ?>">
                            <div class="customer-item-info">
                                <div class="customer-item-name"><?= htmlspecialchars($c['name']) ?></div>
                                <div class="customer-item-number"><?= htmlspecialchars($c['number']) ?></div>
                            </div>
                            <span class="customer-item-badge"><?= $c['job_count'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ════════════════════════════════════════
                 RECHTE SPALTE: Jobs des Kunden
                 ════════════════════════════════════════ -->
            <div>
                <?php if (!$selected_customer || !$selectedCustomerData): ?>
                    <!-- Kein Kunde gewählt -->
                    <div class="content-card">
                        <div class="jobs-panel-empty">
                            <i class="fas fa-hand-pointer"></i>
                            <p>Wähle links einen Kunden aus</p>
                            <span>Die Backup-Jobs des Kunden werden dann hier angezeigt.</span>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Kunden-Header -->
                    <div class="content-card mb-4" style="padding: 1rem 1.25rem;">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <div>
                                <h2 style="font-size: 1.125rem; font-weight: 600; color: var(--color-gray-800);">
                                    <?= htmlspecialchars($selectedCustomerData['name']) ?>
                                </h2>
                                <p style="font-size: 0.8125rem; color: var(--color-gray-500);">
                                    Kundennr. <?= htmlspecialchars($selectedCustomerData['number']) ?> · <?= count($jobs) ?> Backup-Job<?= count($jobs) !== 1 ? 's' : '' ?>
                                </p>
                            </div>
                            <button onclick="openAddModal()" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Neuer Job</button>
                        </div>
                    </div>

                    <?php if (empty($jobs)): ?>
                        <div class="content-card">
                            <div class="empty-state">
                                <i class="fas fa-database"></i>
                                <p>Noch keine Backup-Jobs für diesen Kunden.</p>
                                <span>Lege den ersten Job an, um loszulegen.</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <?php foreach ($jobs as $job): ?>
                                <div class="job-card">
                                    <div class="job-card-header">
                                        <div>
                                            <div class="job-card-title"><?= htmlspecialchars($job['name']) ?></div>
                                            <?php if (!empty($job['backup_type'])): ?>
                                                <span class="badge badge-primary" style="margin-top: 0.25rem;"><?= htmlspecialchars($job['backup_type']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="job-card-actions">
                                            <button onclick='editJob(<?= json_encode($job) ?>)' class="btn btn-outline btn-sm" title="Bearbeiten"><i class="fas fa-edit"></i></button>
                                            <button onclick='confirmDeleteJob(<?= $job["id"] ?>, <?= json_encode($job["name"]) ?>, <?= $selected_customer ?>)' class="btn-inline delete" title="Löschen"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </div>

                                    <?php if (!empty($job['note'])): ?>
                                        <p style="font-size: 0.8125rem; color: var(--color-gray-600); margin-bottom: 0.75rem;"><?= htmlspecialchars($job['note']) ?></p>
                                    <?php endif; ?>

                                    <div class="job-card-grid">
                                        <div class="job-card-field">
                                            <div class="label">E-Mail Suchwort</div>
                                            <div class="value"><?= htmlspecialchars($job['search_term_mail']) ?></div>
                                        </div>
                                        <div class="job-card-field">
                                            <div class="label">Betreff Suchwort</div>
                                            <div class="value"><?= htmlspecialchars($job['search_term_subject']) ?></div>
                                        </div>
                                        <div class="job-card-field">
                                            <div class="label">Text Suchwort 1</div>
                                            <div class="value"><?= htmlspecialchars($job['search_term_text']) ?></div>
                                        </div>
                                        <div class="job-card-field">
                                            <div class="label">Text Suchwort 2</div>
                                            <div class="value"><?= htmlspecialchars($job['search_term_text2'] ?? '') ?></div>
                                        </div>
                                    </div>

                                    <hr class="job-card-divider">

                                    <div class="job-card-meta">
                                        <span><i class="fas fa-<?= $job['include_in_report'] ? 'check-circle text-green-500' : 'times-circle text-red-500' ?>"></i> Mail-Bericht</span>
                                        <span><i class="fas fa-clock"></i> Timeout: <?= $job['ignore_no_status_updates_for_x_hours'] ? $job['ignore_no_status_updates_for_x_hours'] . 'h' : '–' ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div><!-- /split-layout -->
    </div>

    <!-- ════════════════════════════════════════
         MODAL: Neuen Backup-Job anlegen
         ════════════════════════════════════════ -->
    <div class="modal" id="addModal"><div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h3 class="modal-title"><i class="fas fa-plus text-blue-500"></i> Neuen Backup-Job anlegen</h3><button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button></div>
        <form method="post">
            <input type="hidden" name="action" value="add">
            <!-- Kunde ist vorausgewählt -->
            <input type="hidden" name="customer_id" value="<?= $selected_customer ?>">
            <div class="modal-body custom-scroll">
                <div class="form-section-title">Allgemeine Daten</div>
                <?php if ($selectedCustomerData): ?>
                    <p style="font-size: 0.875rem; color: var(--color-gray-600); margin-bottom: 1rem;">
                        <i class="fas fa-user" style="color: var(--color-gray-400);"></i>
                        Kunde: <strong><?= htmlspecialchars($selectedCustomerData['name']) ?></strong>
                    </p>
                <?php endif; ?>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Name <span class="required">*</span></label><input type="text" name="name" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Backup-Typ</label><input type="text" name="backup_type" class="form-input" list="bt_list"><datalist id="bt_list"><?php foreach ($backup_types as $t): ?><option value="<?= htmlspecialchars($t) ?>"><?php endforeach; ?></datalist></div>
                </div>
                <div class="form-group"><label class="form-label">Notiz</label><textarea name="note" class="form-textarea" style="min-height: 3rem;"></textarea></div>
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

    <!-- ════════════════════════════════════════
         MODAL: Backup-Job bearbeiten
         ════════════════════════════════════════ -->
    <div class="modal" id="editModal"><div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h3 class="modal-title"><i class="fas fa-edit text-blue-500"></i> Backup-Job bearbeiten</h3><button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button></div>
        <form method="post">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="modal-body custom-scroll">
                <div class="form-section-title">Allgemeine Daten</div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Kunde <span class="required">*</span></label>
                        <select id="edit_customer_id" name="customer_id" class="form-select" required>
                            <?php foreach ($allCustomers as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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

    // ─── Kundenliste: Client-Side-Suche ───
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('customerSearch');
        const searchJobCheckbox = document.getElementById('searchJobNames');
        const items = document.querySelectorAll('.customer-item');

        // Job-Namen pro Kunde (für die Suche nach Job-Namen)
        // Bereits in PHP geladen (single query statt N+1)
        const jobNamesByCustomer = <?= json_encode($jobNamesByCustomer) ?>;

        function filterCustomers() {
            const term = searchInput.value.toLowerCase().trim();
            const searchJobs = searchJobCheckbox.checked;
            let visibleCount = 0;

            items.forEach(item => {
                if (term === '') {
                    item.style.display = '';
                    visibleCount++;
                    return;
                }

                const name = item.dataset.name || '';
                const number = item.dataset.number || '';
                let match = name.includes(term) || number.includes(term);

                if (!match && searchJobs) {
                    const customerId = item.dataset.id;
                    const jobNames = jobNamesByCustomer[customerId] || [];
                    match = jobNames.some(jn => jn.includes(term));
                }

                item.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            });

            // "Keine Ergebnisse" anzeigen
            let emptyMsg = document.getElementById('customerListEmpty');
            if (visibleCount === 0 && term !== '') {
                if (!emptyMsg) {
                    emptyMsg = document.createElement('div');
                    emptyMsg.id = 'customerListEmpty';
                    emptyMsg.className = 'customer-list-empty';
                    emptyMsg.textContent = 'Kein Kunde gefunden.';
                    document.getElementById('customerList').appendChild(emptyMsg);
                }
                emptyMsg.style.display = '';
            } else if (emptyMsg) {
                emptyMsg.style.display = 'none';
            }
        }

        searchInput.addEventListener('input', filterCustomers);
        searchJobCheckbox.addEventListener('change', filterCustomers);

        // Aktiven Kunden in den sichtbaren Bereich scrollen
        const activeItem = document.querySelector('.customer-item.active');
        if (activeItem) {
            activeItem.scrollIntoView({ block: 'center', behavior: 'instant' });
        }
    });

    // ─── Add-Modal: Kunde ist vorausgewählt ───
    function openAddModal() {
        openModal('addModal');
    }

    // ─── Edit-Modal befüllen ───
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

    // ─── Delete mit Redirect zum gleichen Kunden ───
    function confirmDeleteJob(id, name, customerId) {
        showConfirm('Backup-Job "' + name + '" wirklich löschen? Alle zugehörigen Backup-Ergebnisse werden ebenfalls gelöscht. Die verknüpften Mails bleiben erhalten.', () => {
            const f = document.createElement('form');
            f.method = 'post';
            f.innerHTML = '<input type="hidden" name="action" value="delete">' +
                           '<input type="hidden" name="id" value="' + id + '">' +
                           '<input type="hidden" name="redirect_customer" value="' + customerId + '">';
            document.body.appendChild(f);
            f.submit();
        });
    }
    </script>
</body>
</html>
<?php $conn->close(); ?>