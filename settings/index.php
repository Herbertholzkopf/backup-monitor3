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
            padding: 8rem 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
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
            text-align: center;
            color: #666;
            position: fixed;
            bottom: 1rem;
            width: 100%;
            left: 0;
        }

        footer span {
            color: red;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Einstellungen</h1>
        
        <div class="cards-container">
            <a href="/settings/customers" class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <img src="./user.png" alt="Kunden Icon von https://thenounproject.com/creator/denovo-agency/">
                    </div>
                    <h2 class="card-title">Kunden</h2>
                </div>
                <p class="card-description">Erstellen, Bearbeiten & Löschen von Kunden</p>
            </a>

            <a href="/settings/backup-jobs" class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <img src="./database.png" alt="Backup Icon von https://thenounproject.com/creator/denovo-agency/">
                    </div>
                    <h2 class="card-title">Backup-Jobs</h2>
                </div>
                <p class="card-description">Erstellen, Bearbeiten & Löschen von Backup-Jobs der Kunden</p>
            </a>

            <a href="/settings/information" class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <img src="./info.png" alt="Info Icon von https://thenounproject.com/creator/denovo-agency/">
                    </div>
                    <h2 class="card-title">Informationen</h2>
                </div>
                <p class="card-description">Anleitungen und Erklärungen zur Verwendung</p>
            </a>

            <a href="/settings/unprocessed-mails" class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <img src="./list.png" alt="Backup Icon von https://thenounproject.com/creator/denovo-agency/">
                    </div>
                    <h2 class="card-title">Unverarbeitete Mails</h2>
                </div>
                <p class="card-description">Diese Mails wurde noch nicht weiter verarbeitet oder konnten keinem Job zugewiesen werden</p>
            </a>

            <a href="/settings/all-mails" class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <img src="./bug.png" alt="Backup Icon von https://thenounproject.com/creator/denovo-agency/">
                    </div>
                    <h2 class="card-title">Alle Mails</h2>
                </div>
                <p class="card-description">Liste aller gespeicherten Mails inkl. zugeordneter Backup-Jobs und Ergebnisse (für Troubleshooting)</p>
            </a>
        </div>
    </div>

    <footer>
        made with <span>❤</span> by Andreas Koller
    </footer>
</body>
</html>