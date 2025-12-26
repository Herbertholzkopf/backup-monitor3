<?php
// kein php Skript, nur pures HTML :)
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einstellungen</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        }

        body {
            background-color: #f3f4f6;
            min-height: 100vh;
            padding: 12rem 2rem;
            padding-bottom: 4rem;
            position: relative;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            margin-bottom: 4rem;
        }

        h1 {
            font-size: 2.5rem;
            margin-bottom: 3.5rem;
            text-align: left;
            padding-left: 1.5rem;
            color: #1f2937;
        }

        .cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 4rem;
        }

        .card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .card-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
            gap: 1rem;
        }

        .card-icon {
            width: 24px;
            height: 24px;
            flex-shrink: 0;
        }

        .card-icon img {
            width: 100%;
            height: 100%;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
        }

        .card-description {
            color: #6b7280;
            line-height: 1.4;
            padding-left: 2.5rem;
        }

        footer {
        position: fixed;
        bottom: 0;
        left: 0;
        width: 100%;
        text-align: center;
        background-color: white;
        border-top: 1px solid #e5e7eb;
        padding: 1rem 0;
        z-index: 100;
        color: #6b7280;
        }

        footer .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .footer-link {
            color: inherit;
            text-decoration: none;
        }

        /* Neuer Back-Button Style */
        .back-button {
            position: absolute;
            top: 2rem;
            left: 2rem;
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            border-radius: 5px;
            text-decoration: none;
            color: #1f2937;
            background-color: transparent;
            transition: background-color 0.2s;
        }

        .back-button:hover {
            background-color: rgba(229, 231, 235, 0.5);
        }

        .back-arrow {
            font-size: 1.25rem;
            margin-right: 0.5rem;
        }

        .back-text {
            font-weight: 500;
        }
    </style>
</head>
<body>
    <!-- Neuer Back-Button -->
    <a href="../" class="back-button">
        <span class="back-arrow">←</span>
        <span class="back-text">Zurück zum Dashboard</span>
    </a>

    <div class="container">
        <h1>Einstellungen</h1>
        
        <div class="cards-container">
            <a href="./customers" class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <img src="./customers/user.png" alt="Kunden Icon von https://thenounproject.com/creator/denovo-agency/">
                    </div>
                    <h2 class="card-title">Kunden</h2>
                </div>
                <p class="card-description">Erstellen, Bearbeiten & Löschen von Kunden</p>
            </a>

            <a href="./backup-jobs" class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <img src="./backup-jobs/database.png" alt="Backup Icon von https://thenounproject.com/creator/denovo-agency/">
                    </div>
                    <h2 class="card-title">Backup-Jobs</h2>
                </div>
                <p class="card-description">Erstellen, Bearbeiten & Löschen von Backup-Jobs der Kunden</p>
            </a>

            <a href="./information" class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <img src="./information/info.png" alt="Info Icon von https://thenounproject.com/creator/denovo-agency/">
                    </div>
                    <h2 class="card-title">Wissendatenbank</h2>
                </div>
                <p class="card-description">Anleitungen und Erklärungen zur Verwendung</p>
            </a>

            <a href="./unprocessed-mails" class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <img src="./unprocessed-mails/list.png" alt="Backup Icon von https://thenounproject.com/creator/denovo-agency/">
                    </div>
                    <h2 class="card-title">Unverarbeitete Mails</h2>
                </div>
                <p class="card-description">Diese Mails wurde noch nicht weiter verarbeitet oder konnten keinem Job zugewiesen werden</p>
            </a>

            <a href="./all-mails" class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <img src="./all-mails/bug.png" alt="Backup Icon von https://thenounproject.com/creator/denovo-agency/">
                    </div>
                    <h2 class="card-title">Alle Mails</h2>
                </div>
                <p class="card-description">Liste aller gespeicherten Mails inkl. zugeordneter Backup-Jobs und Ergebnisse (für Troubleshooting)</p>
            </a>

            <a href="./mail-filter" class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <img src="./mail-filter/trash.png" alt="Backup Icon von https://thenounproject.com/creator/denovo-agency/">
                    </div>
                    <h2 class="card-title">Mail-Filter</h2>
                </div>
                <p class="card-description">Hier können Filter für das automatische Aussortieren von Mails konfiguriert werden</p>
            </a>
        </div>

        <h2 style="font-size: 1.75rem; margin-bottom: 2rem; margin-top: 4rem; text-align: left; padding-left: 1.5rem; color: #374151;">Weitere Statistiken und Statusmeldungen</h2>

        <div class="cards-container">
            <a href="./mailstore-info" class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <img src="./mailstore-info/detail.png" alt="Icon von https://thenounproject.com/creator/UBicon/">
                    </div>
                    <h2 class="card-title">Mailstore Informationen</h2>
                </div>
                <p class="card-description">Erhalte weitere Infos von Mailstore, wie Version, Lizenzgröße, Lizenzablaufdatum, ...</p>
            </a>

            <a href="./veeam-health-info" class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <img src="./veeam-health-info/health.png" alt="Icon von https://thenounproject.com/creator/muhammadriza/">
                    </div>
                    <h2 class="card-title">Veeam Backup Health</h2>
                </div>
                <p class="card-description">Veeam prüft einmal im Monat die Integrität der Backup-Dateien. Diese Info kann genutzt werden, um korrumpierte Backups zu identifizieren.</p>
            </a>

            <a href="./nas-disks-info" class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <img src="./nas-disks-info/nas.png" alt="Icon von https://thenounproject.com/creator/eckstein/">
                    </div>
                    <h2 class="card-title">Synology Festplatten</h2>
                </div>
                <p class="card-description">Synology NAS-Geräte prüfen einmal im Monat einen Gesundheitsstatus der installieren Festplatten.</p>
            </a>
        </div>
    </div>

    <footer class="fixed bottom-0 left-0 w-full bg-white border-t border-gray-200 py-4 z-10">
        <div class="container mx-auto text-center">
            Made with ❤️ by <a href="https://github.com/Herbertholzkopf/" class="footer-link">Andreas Koller</a>
        </div>
    </footer>

</body>
</html>