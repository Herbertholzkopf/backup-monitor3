# PowerShell-Skript zum Erstellen einer Aufgabe in der Windows Aufgabenplanung

# Name der Aufgabe
$TaskName = "(backup-monitor3) - Apply Mail Filters"

# Pfad zum Python-Skript
$PythonScriptPath = "C:\inetpub\wwwroot\backup-monitor3\processing\mail-filter.py"

# Arbeitsverzeichnis
$WorkingDirectory = "C:\inetpub\wwwroot\backup-monitor3\processing\"

# Befehl zum Ausführen (Python-Interpreter und Skript)
# Vollständiger Pfad zum Python-Interpreter
$PythonExe = "C:\Users\Administrator.PHD\AppData\Local\Programs\Python\Python313\python.exe"
$Action = New-ScheduledTaskAction -Execute $PythonExe -Argument $PythonScriptPath -WorkingDirectory $WorkingDirectory

# Trigger erstellen: Beim Systemstart und dann alle 10 Minuten wiederholen
# (Erhöht auf 10 Minuten da Mail-Filterung nicht so zeitkritisch ist wie Mail-Job-Zuordnung)
$Trigger = New-ScheduledTaskTrigger -AtStartup
$Trigger.Repetition = (New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 10)).Repetition

# Einstellungen für den Aufgabenausführer
# SYSTEM-Konto verwenden, damit die Aufgabe ohne Benutzeranmeldung läuft
$Principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

# Weitere Einstellungen
$Settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -Hidden -ExecutionTimeLimit (New-TimeSpan -Minutes 5)

# Aufgabe registrieren
Register-ScheduledTask -TaskName $TaskName -Trigger $Trigger -Action $Action -Principal $Principal -Settings $Settings -Force

Write-Host "Die Aufgabe '$TaskName' wurde erfolgreich erstellt und konfiguriert."