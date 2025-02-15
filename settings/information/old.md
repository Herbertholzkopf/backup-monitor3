# Hallo :D
Hier findest du Anleitungen zur richtigen Bedienung.


## Anlegen von Backup-Jobs
Beim Anlegen von Backup-Jobs muss auf einiges geachtet werdden, um die Mails zuverlässig den richtigen Jobs zuweisen zu können.

Der **Name des Backup-Jobs** ist frei wählbar. Beim **Backup-Typ** muss genau der unten erwähnte Name eingeben werden, sonst werden Mails nicht oder nicht richtig verarbeitet.


### Veeam Backup & Replication
**Für Mails von Veeam Backup (nicht Veeam Agent!)**
- Name: *frei wählbar*
- Backup-Typ: `Veeam Backup & Replication`
- E-Mail Suchwort: Mailadresse (z.B. `phd@phd-support.de`)
- Betreff Suchwort: Name des Backup-Jobs (z.B. `Sicherung täglich`)
- Text Suchwort 1: Name des Backup-Jobs (z.B. `Sicherung täglich`)
--> Für Configuration Backups: Servername (z.B. `VMHOST1`)


### Proxmox Backup
**Für Mails von einer Proxmox Node (nicht Proxmox Backup Server!)**
- Name: *frei wählbar*
- Backup-Typ: `Proxmox`
- E-Mail Suchwort: Mailadresse (z.B. `phd.it.systeme.upo@gmail.com`)
- Betreff Suchwort: Name des Servers (z.B. `pve.local`)
- Text Suchwort 1: irgendein Inhalt der Mail (z.B. `Details`)


### Synaxon manged Cloud Backup
**Für Mails vom managed Backup von Synaxon (--> Acronis)**
- Name: *frei wählbar*
- Backup-Typ: `Synaxon managed Backup`
- E-Mail Suchwort: `noreply@synaxon.de`
- Betreff Suchwort: Name des Backup-Kontos (z.B. `phd_IT`)

--> Mail Betreff lautet z.B. `(Gruppe: SYN60229 PHD IT-SYSTEME GMBH > phd IT-Systeme intern)(Backup-Konto: phd_IT)(Maschine: VMHOST1.PHD.local)(Plan: phd_Sicherung_taeglich)`
- Text Suchwort 1: Name des Plans (z.B. `phd_Sicherung_taeglich`)
- Text Suchwort 2: Name des Geräts (z.B. `VMHOST1.PHD.local`)


---------------------------------------------------------------------------------------------------------------------------------

# Willkommen
Dies ist ein **Beispieltext** in Markdown.

## Funktionen
- Überschriften
- *Kursiver* Text
- **Fetter** Text
- Listen

Hier ist ein `Inline-Code` Beispiel.

```Und hier ein Code-Block```

[Ein Link](https://example.com)




