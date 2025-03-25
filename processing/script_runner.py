#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script-Runner: Führt mehrere Python-Skripte in einer definierten Reihenfolge aus
mit konfigurierbaren Pausen zwischen den Skripten.
"""

import subprocess
import time
import os
import logging
from datetime import datetime

# Logging konfigurieren
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("script_runner.log"),
        logging.StreamHandler()
    ]
)

# Konfiguration
# Konfiguration der Skripte mit relativen Pfaden
# Format: (skript_pfad, pause_nach_skript_in_sekunden)
SCRIPTS_TO_RUN = [
    # Skripte im gleichen Verzeichnis wie der Script-Runner
    ("mail-to-database.py", 0.3),  # Ohne Verzeichnisangabe = aktuelles Verzeichnis
    ("mail-filter.py", 0.3),
    ("mail-and-job.py", 0.3),
    
    # Skripte im Unterordner "backup-engines"
    (os.path.join("backup-engines", "veeam.py"), 0.3),
    (os.path.join("backup-engines", "synaxon-cloud.py"), 0.3),
    (os.path.join("backup-engines", "proxmox.py"), 0.3),
    (os.path.join("backup-engines", "synology-hyperbackup.py"), 0.3),
    
    # weitere Skripte
    (os.path.join("mail-reports", "backup_status.py"), 0.3),
]

def run_script(script_path):
    """Führt ein einzelnes Python-Skript in seinem eigenen Verzeichnis aus und gibt zurück, ob es erfolgreich war"""
    try:
        # Überprüfen, ob die Datei existiert
        if not os.path.isfile(script_path):
            logging.error(f"Skript nicht gefunden: {script_path}")
            return False
        
        # Verzeichnis des Skripts ermitteln
        script_dir = os.path.dirname(script_path)
        script_name = os.path.basename(script_path)
        
        logging.info(f"Starte Skript: {script_path} (im Verzeichnis {script_dir})")
        start_time = time.time()
        
        # Aktuelles Verzeichnis merken
        original_dir = os.getcwd()
        
        try:
            # In das Verzeichnis des Skripts wechseln
            if script_dir:
                os.chdir(script_dir)
            
            # Skript mit Python ausführen (nur den Dateinamen, nicht den vollen Pfad)
            result = subprocess.run(["python", script_name], 
                                   capture_output=True, 
                                   text=True, 
                                   check=False)
            
            execution_time = time.time() - start_time
            
            if result.returncode == 0:
                logging.info(f"Skript erfolgreich ausgeführt: {script_path} (Dauer: {execution_time:.2f}s)")
                return True
            else:
                logging.error(f"Fehler beim Ausführen von {script_path} (Returncode: {result.returncode})")
                logging.error(f"Fehlerausgabe: {result.stderr}")
                return False
                
        finally:
            # Immer zum ursprünglichen Verzeichnis zurückkehren
            os.chdir(original_dir)
            
    except Exception as e:
        logging.error(f"Fehler beim Ausführen von {script_path}: {str(e)}")
        return False

def main():
    """Hauptfunktion, die alle Skripte nacheinander ausführt"""
    logging.info(f"=== Script-Runner gestartet am {datetime.now().strftime('%Y-%m-%d %H:%M:%S')} ===")
    
    successful_scripts = 0
    total_scripts = len(SCRIPTS_TO_RUN)
    
    for i, (script_path, pause_seconds) in enumerate(SCRIPTS_TO_RUN):
        # Skript ausführen
        success = run_script(script_path)
        
        if success:
            successful_scripts += 1
        
        # Pause einlegen, außer nach dem letzten Skript
        if pause_seconds > 0:
            logging.info(f"Warte {pause_seconds} Sekunden...")
            time.sleep(pause_seconds)
    
    # Zusammenfassung
    logging.info(f"=== Script-Runner beendet am {datetime.now().strftime('%Y-%m-%d %H:%M:%S')} ===")
    logging.info(f"Zusammenfassung: {successful_scripts} von {total_scripts} Skripten erfolgreich ausgeführt")

if __name__ == "__main__":
    main()