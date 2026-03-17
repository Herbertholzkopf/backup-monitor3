<?php
/**
 * EINSTELLUNGEN — Übersichtsseite
 * 
 * Pfad:    /settings/index.php
 * Includes: ../includes/styles.css, ../includes/app.js
 */
// kein PHP-Skript, nur pures HTML :)
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einstellungen – Backup-Monitor</title>
    <!-- ===== Zentrale Einbindung ===== -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="../includes/styles.css" rel="stylesheet">

    <!-- ===== Seiten-spezifische Styles ===== -->
    <style>
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }

        .settings-card {
            background: #ffffff;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--color-gray-200);
            padding: 1.5rem;
            text-decoration: none;
            color: inherit;
            display: block;
            transition: all var(--transition-normal);
        }

        .settings-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-xl);
            border-color: var(--color-primary);
        }

        .settings-card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .settings-card-icon {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .settings-card-icon img {
            width: 1.25rem;
            height: 1.25rem;
            opacity: 0.8;
        }

        .settings-card-icon i {
            font-size: 1rem;
        }

        .settings-card-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--color-gray-800);
        }

        .settings-card-desc {
            font-size: 0.875rem;
            color: var(--color-gray-500);
            line-height: 1.5;
            padding-left: 3rem;
        }
    </style>
</head>
<body>

    <div class="container mx-auto px-4 py-6">

        <!-- ============================================================
             SEITEN-HEADER
             ============================================================ -->
        <header class="page-header">
            <a href="../" class="back-button">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="page-header-title">
                <h1>Einstellungen</h1>
                <p>Verwaltung und Konfiguration</p>
            </div>
        </header>


        <!-- ============================================================
             EINSTELLUNGEN — Hauptbereich
             ============================================================ -->
        <div class="settings-grid mb-6">

            <a href="./customers" class="settings-card">
                <div class="settings-card-header">
                    <div class="settings-card-icon bg-blue-50">
                        <i class="fas fa-users text-blue-500"></i>
                    </div>
                    <h2 class="settings-card-title">Kunden</h2>
                </div>
                <p class="settings-card-desc">Erstellen, Bearbeiten & Löschen von Kunden</p>
            </a>

            <a href="./backup-jobs" class="settings-card">
                <div class="settings-card-header">
                    <div class="settings-card-icon bg-green-50">
                        <i class="fas fa-database text-green-500"></i>
                    </div>
                    <h2 class="settings-card-title">Backup-Jobs</h2>
                </div>
                <p class="settings-card-desc">Erstellen, Bearbeiten & Löschen von Backup-Jobs der Kunden</p>
            </a>

            <a href="./information" class="settings-card">
                <div class="settings-card-header">
                    <div class="settings-card-icon bg-purple-50">
                        <i class="fas fa-book text-purple-500"></i>
                    </div>
                    <h2 class="settings-card-title">Wissensdatenbank</h2>
                </div>
                <p class="settings-card-desc">Anleitungen und Erklärungen zur Verwendung</p>
            </a>

            <a href="./mail-filter" class="settings-card">
                <div class="settings-card-header">
                    <div class="settings-card-icon bg-gray-100">
                        <i class="fas fa-filter text-gray-500"></i>
                    </div>
                    <h2 class="settings-card-title">Mail-Filter</h2>
                </div>
                <p class="settings-card-desc">Hier können Filter für das automatische Aussortieren von Mails konfiguriert werden</p>
            </a>

            <a href="./unprocessed-mails" class="settings-card">
                <div class="settings-card-header">
                    <div class="settings-card-icon bg-orange-50">
                        <i class="fas fa-inbox text-orange-500"></i>
                    </div>
                    <h2 class="settings-card-title">Unverarbeitete Mails</h2>
                </div>
                <p class="settings-card-desc">Diese Mails wurden noch nicht weiter verarbeitet oder konnten keinem Job zugewiesen werden</p>
            </a>

            <a href="./all-mails" class="settings-card">
                <div class="settings-card-header">
                    <div class="settings-card-icon bg-red-50">
                        <i class="fas fa-bug text-red-500"></i>
                    </div>
                    <h2 class="settings-card-title">Alle Mails</h2>
                </div>
                <p class="settings-card-desc">Liste aller gespeicherten Mails inkl. zugeordneter Backup-Jobs und Ergebnisse (für Troubleshooting)</p>
            </a>

        </div>


        <!-- ============================================================
             WEITERE STATISTIKEN UND STATUSMELDUNGEN
             ============================================================ -->
        <div class="content-card mb-6" style="padding: 0; border: none; box-shadow: none; background: transparent;">
            <div class="section-header" style="margin-bottom: 1.5rem; margin-top: 1rem;">
                <h2 class="section-title">Weitere Statistiken und Statusmeldungen</h2>
            </div>
        </div>

        <div class="settings-grid mb-6">

            <a href="./mailstore-info" class="settings-card">
                <div class="settings-card-header">
                    <div class="settings-card-icon bg-blue-50">
                        <i class="fas fa-info-circle text-blue-500"></i>
                    </div>
                    <h2 class="settings-card-title">Mailstore Informationen</h2>
                </div>
                <p class="settings-card-desc">Erhalte weitere Infos von Mailstore, wie Version, Lizenzgröße, Lizenzablaufdatum, ...</p>
            </a>

            <a href="./veeam-health-info" class="settings-card">
                <div class="settings-card-header">
                    <div class="settings-card-icon bg-green-50">
                        <i class="fas fa-heartbeat text-green-500"></i>
                    </div>
                    <h2 class="settings-card-title">Veeam Backup Health</h2>
                </div>
                <p class="settings-card-desc">Veeam prüft einmal im Monat die Integrität der Backup-Dateien. Diese Info kann genutzt werden, um korrumpierte Backups zu identifizieren.</p>
            </a>

            <a href="./nas-disks-info" class="settings-card">
                <div class="settings-card-header">
                    <div class="settings-card-icon bg-orange-50">
                        <i class="fas fa-hdd text-orange-500"></i>
                    </div>
                    <h2 class="settings-card-title">Synology Festplatten</h2>
                </div>
                <p class="settings-card-desc">Synology NAS-Geräte prüfen einmal im Monat einen Gesundheitsstatus der installierten Festplatten.</p>
            </a>

        </div>

    </div><!-- /container -->


    <!-- ============================================================
         FOOTER
         ============================================================ -->
    <footer class="app-footer">
        Made with ❤️ by <a href="https://github.com/Herbertholzkopf/">Andreas Koller</a>
    </footer>


    <!-- ============================================================
         JAVASCRIPT (nur app.js für Grundfunktionen)
         ============================================================ -->
    <script src="../includes/app.js"></script>

</body>
</html>