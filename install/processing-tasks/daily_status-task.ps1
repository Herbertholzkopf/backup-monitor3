# PowerShell-Skript zum Erstellen einer Aufgabe, die täglich um 8 Uhr ausgeführt wird

# Name der Aufgabe
$TaskName = "(backup-monitor3) - Calculate daily Backup-Job Status"

# Pfad zum Python-Skript
$PythonScriptPath = "C:\inetpub\wwwroot\backup-monitor3\processing\mail-reports\daily_status.py"

# Arbeitsverzeichnis
$WorkingDirectory = "C:\inetpub\wwwroot\backup-monitor3\processing\mail-reports\"

# Befehl zum Ausführen (Python-Interpreter und Skript)
# Vollständiger Pfad zum Python-Interpreter
$PythonExe = "C:\Users\Administrator.PHD\AppData\Local\Programs\Python\Python313\python.exe"
$Action = New-ScheduledTaskAction -Execute $PythonExe -Argument $PythonScriptPath -WorkingDirectory $WorkingDirectory

# Trigger erstellen: Täglich um 8:00 Uhr
$Trigger = New-ScheduledTaskTrigger -Daily -At "07:55"

# Einstellungen für den Aufgabenausführer
# SYSTEM-Konto verwenden, damit die Aufgabe ohne Benutzeranmeldung läuft
$Principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

# Weitere Einstellungen
$Settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 5)

# Aufgabe registrieren
Register-ScheduledTask -TaskName $TaskName -Trigger $Trigger -Action $Action -Principal $Principal -Settings $Settings -Force

Write-Host "Die Aufgabe '$TaskName' wurde erfolgreich erstellt und konfiguriert."