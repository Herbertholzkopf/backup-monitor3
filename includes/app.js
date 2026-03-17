/**
 * ============================================================
 * KUNDEN MANAGER — Zentrale JavaScript-Funktionen (app.js)
 * ============================================================
 * 
 * Diese Datei ist die Single Source of Truth für alle gemeinsamen
 * JavaScript-Funktionen. Sie initialisiert sich automatisch beim Laden.
 * 
 * Einbindung in jeder PHP-Seite (vor </body>):
 *   <script src="../../includes/app.js"></script>
 * 
 * Voraussetzung im HTML (einmal pro Seite):
 *   <div id="modalBackdrop" class="modal-backdrop"></div>
 *   <div id="notifications" class="notification-container"></div>
 *   <div id="confirmModal" class="modal stacked">...</div>   (optional, wird ggf. automatisch erstellt)
 * 
 * INHALTSVERZEICHNIS:
 *   1. Notifications / Toast
 *   2. Confirm-Dialog
 *   3. Modal-System
 *   4. Loading-States
 *   5. API-Helfer (POST, GET, FormData)
 *   6. Formatierung (Datum, Währung, Zahlen)
 *   7. HTML-Helfer
 *   8. Tab-Switching
 *   9. Tabellen-Filter
 *  10. Dokument-Upload
 *  11. Auto-Initialisierung
 * ============================================================
 */


/* ============================================================
   1. NOTIFICATIONS / TOAST
   ============================================================
   
   showNotification('Gespeichert!')                → grün (success)
   showNotification('Fehler!', 'error')            → rot
   showNotification('Achtung', 'warning')          → orange
   showNotification('Hinweis', 'info')             → blau
   showNotification('Text', 'success', 5000)       → 5 Sekunden sichtbar
*/

function showNotification(message, type = 'success', duration = 3000) {
    let container = document.getElementById('notifications');

    // Container automatisch erstellen, falls nicht vorhanden
    if (!container) {
        container = document.createElement('div');
        container.id = 'notifications';
        container.className = 'notification-container';
        document.body.appendChild(container);
    }

    const icons = {
        success: 'fa-check-circle',
        error:   'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info:    'fa-info-circle'
    };

    const el = document.createElement('div');
    el.className = 'notification notification-' + type;
    el.innerHTML = '<i class="fas ' + (icons[type] || icons.info) + '"></i>'
                 + '<span>' + escHtml(message) + '</span>';

    container.appendChild(el);

    // Ausblenden + Entfernen
    setTimeout(() => {
        el.style.opacity = '0';
        el.style.transform = 'translateX(20px)';
        el.style.transition = 'opacity 0.3s, transform 0.3s';
        setTimeout(() => el.remove(), 300);
    }, duration);
}


/* ============================================================
   2. CONFIRM-DIALOG
   ============================================================
   
   showConfirm('Wirklich löschen?', async () => {
       const result = await apiCall('delete', { id: 5 });
       if (result.success) location.reload();
   });
   
   showConfirm('Abbrechen?', onConfirm, {
       confirmText: 'Ja, abbrechen',
       confirmClass: 'btn btn-danger',
       cancelText: 'Nein'
   });
*/

let _confirmCallback = null;

function showConfirm(message, callback, options = {}) {
    _confirmCallback = callback;

    const opts = {
        confirmText:  options.confirmText  || 'Löschen',
        confirmClass: options.confirmClass || 'btn btn-danger',
        cancelText:   options.cancelText   || 'Abbrechen',
        ...options
    };

    // Confirm-Modal finden oder erstellen
    let modal = document.getElementById('confirmModal');
    if (!modal) {
        modal = _createConfirmModal();
    }

    document.getElementById('confirmText').innerHTML = message;

    const okBtn = document.getElementById('confirmOkBtn');
    okBtn.textContent = opts.confirmText;
    okBtn.className = opts.confirmClass;

    const cancelBtn = document.getElementById('confirmCancelBtn');
    if (cancelBtn) cancelBtn.textContent = opts.cancelText;

    // Backdrop aktivieren
    const backdrop = document.getElementById('modalBackdrop');
    if (backdrop) backdrop.classList.add('active');

    modal.classList.add('active');
    // Fallback: dunklen Hintergrund direkt per JS setzen
    modal.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
}

function _confirmOk() {
    const modal = document.getElementById('confirmModal');
    if (modal) {
        modal.classList.remove('active');
        modal.style.backgroundColor = '';
    }

    // Backdrop nur entfernen, wenn keine anderen Modals mehr offen sind
    const stillOpen = document.querySelectorAll('.modal.active');
    if (stillOpen.length === 0) {
        const backdrop = document.getElementById('modalBackdrop');
        if (backdrop) backdrop.classList.remove('active');
    }

    if (typeof _confirmCallback === 'function') {
        _confirmCallback();
    }
    _confirmCallback = null;
}

function _confirmCancel() {
    const modal = document.getElementById('confirmModal');
    if (modal) {
        modal.classList.remove('active');
        modal.style.backgroundColor = '';
    }

    // Backdrop nur entfernen, wenn keine anderen Modals mehr offen sind
    const stillOpen = document.querySelectorAll('.modal.active');
    if (stillOpen.length === 0) {
        const backdrop = document.getElementById('modalBackdrop');
        if (backdrop) backdrop.classList.remove('active');
    }

    _confirmCallback = null;
}

/**
 * Erstellt das Confirm-Modal dynamisch, falls nicht im HTML vorhanden.
 */
function _createConfirmModal() {
    const modal = document.createElement('div');
    modal.id = 'confirmModal';
    modal.className = 'modal stacked';
    modal.innerHTML = ''
        + '<div class="modal-dialog modal-sm">'
        + '  <div class="modal-content">'
        + '    <div class="modal-body">'
        + '      <p id="confirmText" style="font-size: 0.875rem; color: #374151;"></p>'
        + '    </div>'
        + '    <div class="modal-footer">'
        + '      <button id="confirmCancelBtn" class="btn btn-outline" onclick="_confirmCancel()">Abbrechen</button>'
        + '      <button id="confirmOkBtn" class="btn btn-danger" onclick="_confirmOk()">Löschen</button>'
        + '    </div>'
        + '  </div>'
        + '</div>';
    document.body.appendChild(modal);
    return modal;
}


/* ============================================================
   3. MODAL-SYSTEM
   ============================================================
   
   openModal('meinModal')         → Modal öffnen
   closeModal('meinModal')        → Einzelnes Modal schließen
   closeAllModals()               → Alle Modals schließen
   
   Regeln:
   - Backdrop-Klick schließt NICHT (bewusste Entscheidung)
   - ESC schließt das oberste Modal
   - body overflow bleibt frei, Hintergrund ist weiterhin scrollbar
   - Mehrere Modals können gestapelt sein (z-index regelt Reihenfolge)
*/

function openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) {
        console.warn('Modal nicht gefunden:', id);
        return;
    }

    const backdrop = document.getElementById('modalBackdrop');
    if (backdrop) backdrop.classList.add('active');

    modal.classList.add('active');

    // Stacked/Chooser Modals bekommen eigenen dunklen Hintergrund
    if (modal.classList.contains('stacked') || modal.classList.contains('chooser')) {
        modal.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('active');
        modal.style.backgroundColor = '';
    }

    // Prüfen ob noch andere Modals offen sind
    const stillOpen = document.querySelectorAll('.modal.active');
    if (stillOpen.length === 0) {
        const backdrop = document.getElementById('modalBackdrop');
        if (backdrop) backdrop.classList.remove('active');
    }
}

function closeAllModals() {
    document.querySelectorAll('.modal.active').forEach(m => {
        m.classList.remove('active');
        m.style.backgroundColor = '';
    });
    const backdrop = document.getElementById('modalBackdrop');
    if (backdrop) backdrop.classList.remove('active');
}

/**
 * Schließt das aktuelle Modal und lädt die Seite neu.
 * Nützlich bei Settings-Modals (Kategorien, Empfänger), wo
 * nach dem Schließen die Daten aktualisiert sein müssen.
 */
function closeAndReload() {
    closeAllModals();
    location.reload();
}


/* ============================================================
   4. LOADING-STATES
   ============================================================
   
   showLoading('modalBody')                     → Spinner anzeigen
   showLoading('modalBody', 'Daten laden...')    → mit Text
   hideLoading('modalBody', html)               → Spinner ersetzen durch Inhalt
   
   Für Inline-Spinner:
   inlineSpinner()    → gibt HTML-String zurück
*/

/**
 * Ermittelt den Pfad zum /includes/ Ordner anhand des Script-Tags.
 * Funktioniert unabhängig von der Verzeichnistiefe des Moduls.
 */
const _includesPath = (function() {
    const scripts = document.getElementsByTagName('script');
    for (let i = scripts.length - 1; i >= 0; i--) {
        const src = scripts[i].getAttribute('src') || '';
        if (src.includes('app.js')) {
            return src.replace('app.js', '');
        }
    }
    return '/includes/';
})();

/**
 * Zeigt den Loading-Spinner in einem Container.
 */
function showLoading(containerId, text) {
    const container = document.getElementById(containerId);
    if (!container) return;

    let html = '<div class="loading">'
             + '  <img src="' + _includesPath + 'loading.png" class="loading-spinner" alt="Laden...">'
             + '</div>';

    if (text) {
        html = '<div class="loading">'
             + '  <img src="' + _includesPath + 'loading.png" class="loading-spinner" alt="Laden...">'
             + '  <p class="loading-text">' + escHtml(text) + '</p>'
             + '</div>';
    }

    container.innerHTML = html;
}

/**
 * Ersetzt den Loading-Spinner durch neuen Inhalt.
 */
function hideLoading(containerId, newHtml) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = newHtml;
}

/**
 * Gibt einen Inline-Spinner (loading.png) als HTML-String zurück.
 */
function inlineSpinner() {
    return '<img src="' + _includesPath + 'loading.png" class="spinner" alt="">';
}


/* ============================================================
   5. API-HELFER
   ============================================================
   
   // POST an aktuelle Seite (für inline AJAX-Handler in PHP)
   const result = await apiCall('createItem', { name: 'Test' });
   
   // POST mit FormData (für Datei-Uploads)
   const formData = new FormData();
   formData.append('datei', fileInput.files[0]);
   const result = await apiCallFormData('upload', formData);
   
   // GET-Request
   const result = await apiGet({ ajax: 'details', id: 5 });
   
   // POST an andere URL
   const result = await apiFetch('/api/endpoint.php', { key: 'value' });
*/

/**
 * POST an die aktuelle Seite mit action-Parameter.
 * Zeigt automatisch Erfolgs-/Fehlermeldungen.
 */
async function apiCall(action, data = {}) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', action);

    for (const [key, value] of Object.entries(data)) {
        if (value !== null && value !== undefined) {
            formData.append(key, value);
        }
    }

    try {
        const url = window.location.href.split('#')[0].split('?')[0];
        const response = await fetch(url, { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success && result.message) {
            showNotification(result.message);
        } else if (!result.success) {
            showNotification(result.message || result.error || 'Ein Fehler ist aufgetreten', 'error');
        }

        return result;
    } catch (error) {
        console.error('apiCall Fehler:', error);
        showNotification('Verbindungsfehler', 'error');
        return { success: false, error: 'Netzwerkfehler' };
    }
}

/**
 * POST mit vorbereitetem FormData (z.B. für Datei-Uploads).
 * Fügt automatisch ajax=1 und action hinzu.
 */
async function apiCallFormData(action, formData) {
    formData.append('ajax', '1');
    formData.append('action', action);

    try {
        const url = window.location.href.split('#')[0].split('?')[0];
        const response = await fetch(url, { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success && result.message) {
            showNotification(result.message);
        } else if (!result.success) {
            showNotification(result.message || result.error || 'Ein Fehler ist aufgetreten', 'error');
        }

        return result;
    } catch (error) {
        console.error('apiCallFormData Fehler:', error);
        showNotification('Verbindungsfehler', 'error');
        return { success: false, error: 'Netzwerkfehler' };
    }
}

/**
 * GET-Request an die aktuelle Seite mit Query-Parametern.
 */
async function apiGet(params = {}) {
    try {
        const base = window.location.href.split('#')[0].split('?')[0];
        const query = new URLSearchParams(params).toString();
        const url = base + '?' + query;
        const response = await fetch(url);
        return await response.json();
    } catch (error) {
        console.error('apiGet Fehler:', error);
        return { success: false, error: 'Netzwerkfehler' };
    }
}

/**
 * POST an eine beliebige URL (z.B. separate API-Datei).
 */
async function apiFetch(url, data = {}) {
    const formData = new FormData();
    for (const [key, value] of Object.entries(data)) {
        if (value !== null && value !== undefined) {
            formData.append(key, value);
        }
    }

    try {
        const response = await fetch(url, { method: 'POST', body: formData });
        return await response.json();
    } catch (error) {
        console.error('apiFetch Fehler:', error);
        return { success: false, error: 'Netzwerkfehler' };
    }
}


/* ============================================================
   6. FORMATIERUNG
   ============================================================ */

/**
 * Zahl deutsch formatieren: 1234.56 → "1.234,56"
 */
function formatDE(num, decimals = 2) {
    if (num === null || num === undefined || isNaN(num)) return '–';
    return parseFloat(num).toLocaleString('de-DE', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

/**
 * Deutschen Zahlen-String parsen: "1.234,56" → 1234.56
 */
function parseDE(str) {
    if (!str || str === '' || str === '–') return 0;
    return parseFloat(String(str).replace(/\./g, '').replace(',', '.')) || 0;
}

/**
 * Währung formatieren: 1234.56 → "1.234,56 €"
 */
function formatCurrency(num) {
    if (num === null || num === undefined || isNaN(num)) return '–';
    return formatDE(num) + ' €';
}

/**
 * Datum formatieren: "2025-03-15" → "15.03.2025"
 * Verwendet explizites Parsing um Timezone-Probleme zu vermeiden.
 */
function formatDate(dateStr) {
    if (!dateStr) return '–';

    // ISO-Format parsen (YYYY-MM-DD)
    const parts = String(dateStr).split('T')[0].split('-');
    if (parts.length !== 3) return '–';

    const year  = parseInt(parts[0], 10);
    const month = parseInt(parts[1], 10);
    const day   = parseInt(parts[2], 10);

    if (isNaN(year) || isNaN(month) || isNaN(day)) return '–';

    return String(day).padStart(2, '0') + '.'
         + String(month).padStart(2, '0') + '.'
         + year;
}

/**
 * Anzahl formatieren: Ganzzahlen ohne Dezimal, Kommazahlen mit 2 Stellen.
 */
function formatAnzahl(anzahl) {
    const num = parseFloat(anzahl);
    if (isNaN(num)) return '–';
    return (Math.floor(num) === num)
        ? num.toLocaleString('de-DE', { minimumFractionDigits: 0 })
        : num.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/* ============================================================
   7. HTML-HELFER
   ============================================================ */

/**
 * HTML-Escape: Verhindert XSS bei dynamischem Content.
 */
function escHtml(str) {
    if (str === null || str === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}

/**
 * FontAwesome Icon-Klasse anhand des MIME-Types.
 */
function getFileIcon(mimeType) {
    if (!mimeType) return 'fa-file';
    if (mimeType.startsWith('image/'))                                        return 'fa-file-image';
    if (mimeType === 'application/pdf')                                       return 'fa-file-pdf';
    if (mimeType.includes('word') || mimeType.includes('document'))           return 'fa-file-word';
    if (mimeType.includes('excel') || mimeType.includes('spreadsheet'))       return 'fa-file-excel';
    if (mimeType.includes('powerpoint') || mimeType.includes('presentation')) return 'fa-file-powerpoint';
    if (mimeType.startsWith('text/'))                                         return 'fa-file-alt';
    if (mimeType.includes('zip') || mimeType.includes('archive'))             return 'fa-file-archive';
    return 'fa-file';
}

/**
 * Erzeugt ein Status-Badge als HTML-String.
 * Typen: 'success', 'danger', 'warning', 'info', 'purple', 'yellow', 'gray', 'primary', 'secondary'
 */
function badgeHtml(text, type = 'primary') {
    return '<span class="badge badge-' + type + '">' + escHtml(text) + '</span>';
}


/* ============================================================
   8. TAB-SWITCHING
   ============================================================
   
   HTML:
   <nav class="tabs-nav">
       <button class="tab-item active" data-tab="stammdaten" onclick="switchTab('stammdaten')">
       <button class="tab-item" data-tab="produkte" onclick="switchTab('produkte')">
   </nav>
   
   <div id="tab-stammdaten" class="tab-content">...</div>
   <div id="tab-produkte" class="tab-content hidden">...</div>
   
   switchTab('produkte')
   switchTab('produkte', true)  → aktualisiert auch die URL (?tab=produkte)
*/

function switchTab(tabName, updateUrl = true) {
    // Alle Tab-Inhalte verstecken
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });

    // Alle Tab-Buttons deaktivieren
    document.querySelectorAll('.tab-item, [data-tab]').forEach(btn => {
        btn.classList.remove('active', 'tab-active');
        btn.classList.add('border-transparent', 'text-gray-500');
        btn.classList.remove('text-blue-600');
    });

    // Aktiven Tab anzeigen
    const activeContent = document.getElementById('tab-' + tabName);
    if (activeContent) {
        activeContent.classList.remove('hidden');
    }

    // Aktiven Tab-Button markieren
    const activeBtn = document.querySelector('[data-tab="' + tabName + '"]');
    if (activeBtn) {
        activeBtn.classList.add('active', 'tab-active');
        activeBtn.classList.remove('border-transparent', 'text-gray-500');
    }

    // URL aktualisieren (ohne Reload)
    if (updateUrl) {
        const url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        history.replaceState(null, '', url);
    }
}


/* ============================================================
   9. TABELLEN-FILTER
   ============================================================
   
   HTML:
   <input type="text" id="mySearch" data-filter-table="myTable" 
          data-filter-fields="name,description" placeholder="Suchen...">
   
   <select id="mySelect" data-filter-table="myTable" data-filter-field="categoryId">
       <option value="">Alle</option>
       <option value="3">EDV</option>
   </select>
   
   <table id="myTable">
       <tbody>
           <tr data-name="..." data-description="..." data-category-id="3">
   
   Oder manuell:
   filterTable('myTable', { search: 'suchtext', filters: { status: 'aktiv' } })
*/

/**
 * Filtert eine Tabelle anhand von Suche und Dropdown-Filtern.
 * 
 * @param {string} tableId       - ID der Tabelle
 * @param {object} options
 *   - search:   {string}   Suchbegriff (durchsucht alle data-* Attribute der Zeilen)
 *   - filters:  {object}   Key-Value Paare: { 'status': 'aktiv', 'kategorie-id': '3' }
 *   - fields:   {string[]} Optional: nur diese data-Attribute durchsuchen
 */
function filterTable(tableId, options = {}) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const rows = table.querySelectorAll('tbody tr[data-name], tbody tr[data-bezeichnung], tbody tr:not(.no-filter)');
    const search = (options.search || '').toLowerCase().trim();
    const filters = options.filters || {};
    const fields = options.fields || null;

    let visibleCount = 0;

    rows.forEach(row => {
        // Suchfilter: Alle data-Attribute der Zeile durchsuchen
        let matchSearch = true;
        if (search) {
            matchSearch = false;
            const attrs = row.dataset;
            for (const key in attrs) {
                if (fields && !fields.includes(key)) continue;
                if (attrs[key].toLowerCase().includes(search)) {
                    matchSearch = true;
                    break;
                }
            }
        }

        // Dropdown-Filter: Exakte Übereinstimmung pro Filter
        let matchFilters = true;
        for (const [key, value] of Object.entries(filters)) {
            if (!value) continue; // Leerer Filter = kein Filter
            // data-attribut Name: camelCase → kebab-case konvertieren
            const dataKey = key.replace(/([A-Z])/g, '-$1').toLowerCase();
            const rowValue = row.dataset[key] || row.getAttribute('data-' + dataKey) || '';
            if (rowValue !== value) {
                matchFilters = false;
                break;
            }
        }

        const visible = matchSearch && matchFilters;
        row.style.display = visible ? '' : 'none';
        if (visible) visibleCount++;
    });

    return visibleCount;
}

/**
 * Initialisiert automatische Tabellen-Filter für Inputs/Selects
 * die das Attribut data-filter-table haben.
 */
function _initTableFilters() {
    document.querySelectorAll('[data-filter-table]').forEach(el => {
        const event = (el.tagName === 'SELECT') ? 'change' : 'input';

        el.addEventListener(event, () => {
            const tableId = el.dataset.filterTable;

            // Alle Filter-Elemente für diese Tabelle sammeln
            const allFilterEls = document.querySelectorAll('[data-filter-table="' + tableId + '"]');

            let search = '';
            const filters = {};

            allFilterEls.forEach(filterEl => {
                if (filterEl.dataset.filterFields || filterEl.type === 'text' || filterEl.type === 'search') {
                    search = filterEl.value;
                } else if (filterEl.dataset.filterField) {
                    filters[filterEl.dataset.filterField] = filterEl.value;
                }
            });

            const fields = el.dataset.filterFields ? el.dataset.filterFields.split(',') : null;
            filterTable(tableId, { search, filters, fields });
        });
    });
}


/* ============================================================
   10. DOKUMENT-UPLOAD
   ============================================================
   
   HTML:
   <input type="file" id="dokUpload" multiple 
          onchange="uploadDokumente(this.files, '/api/upload.php', 'kundennummer', '12345')">
   
   Oder manueller Aufruf:
   uploadDokumente(files, actionUrl, refField, refValue, {
       onSuccess: (data) => { ... },
       listId: 'dokumentListe'
   })
*/

/**
 * Lädt eine oder mehrere Dateien hoch.
 * 
 * @param {FileList} files         - Die Dateien
 * @param {string}   actionUrl     - Ziel-URL oder '' für aktuelle Seite
 * @param {string}   refField      - Name des Referenz-Felds (z.B. 'kundennummer')
 * @param {string}   refValue      - Wert des Referenz-Felds (z.B. '12345')
 * @param {object}   options       - Optionale Callbacks und Einstellungen
 */
async function uploadDokumente(files, actionUrl, refField, refValue, options = {}) {
    if (!files || files.length === 0) return;

    const url = actionUrl || window.location.href.split('#')[0].split('?')[0];
    let successCount = 0;
    let errorCount = 0;

    for (let i = 0; i < files.length; i++) {
        const formData = new FormData();
        formData.append('dok_action', 'upload');
        formData.append('dokument', files[i]);
        if (refField && refValue) {
            formData.append(refField, refValue);
        }

        try {
            const response = await fetch(url, { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                successCount++;
                if (typeof options.onSuccess === 'function') {
                    options.onSuccess(result, files[i]);
                }
            } else {
                errorCount++;
                showNotification(result.message || 'Upload fehlgeschlagen', 'error');
            }
        } catch (error) {
            errorCount++;
            console.error('Upload-Fehler:', error);
        }
    }

    if (successCount > 0) {
        const msg = successCount === 1
            ? 'Dokument hochgeladen'
            : successCount + ' Dokumente hochgeladen';
        showNotification(msg);
    }

    if (errorCount > 0 && successCount === 0) {
        showNotification('Upload fehlgeschlagen', 'error');
    }
}

/**
 * Löscht ein Dokument nach Bestätigung.
 */
function deleteDokument(id, options = {}) {
    const url = options.url || window.location.href.split('#')[0].split('?')[0];

    showConfirm('Dokument wirklich löschen?', async () => {
        const formData = new FormData();
        formData.append('dok_action', 'delete');
        formData.append('dok_id', id);

        try {
            const response = await fetch(url, { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                showNotification('Dokument gelöscht');
                // Element aus DOM entfernen
                const el = document.querySelector('[data-id="' + id + '"]');
                if (el) el.remove();
                if (typeof options.onSuccess === 'function') {
                    options.onSuccess(result);
                }
            } else {
                showNotification(result.message || 'Fehler beim Löschen', 'error');
            }
        } catch (error) {
            showNotification('Fehler beim Löschen', 'error');
        }
    });
}


/* ============================================================
   11. AUTO-INITIALISIERUNG
   ============================================================
   
   Wird automatisch bei DOMContentLoaded ausgeführt:
   - Notification-Container erstellen (falls fehlt)
   - Confirm-Modal erstellen (falls fehlt)
   - Backdrop erstellen (falls fehlt)
   - ESC-Taste → oberstes Modal schließen
   - Tabellen-Filter initialisieren
   - Tab aus URL-Parameter aktivieren
*/

document.addEventListener('DOMContentLoaded', () => {

    // --- Notification Container ---
    if (!document.getElementById('notifications')) {
        const container = document.createElement('div');
        container.id = 'notifications';
        container.className = 'notification-container';
        document.body.appendChild(container);
    }

    // --- Modal Backdrop ---
    if (!document.getElementById('modalBackdrop')) {
        const backdrop = document.createElement('div');
        backdrop.id = 'modalBackdrop';
        backdrop.className = 'modal-backdrop';
        // KEIN Click-Handler: Backdrop-Klick schließt absichtlich NICHT
        document.body.appendChild(backdrop);
    }

    // --- Tabellen-Filter ---
    _initTableFilters();

    // --- Tab aus URL aktivieren ---
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    if (tabParam && document.getElementById('tab-' + tabParam)) {
        switchTab(tabParam, false);
    }
});

// --- ESC-Taste: Oberstes Modal schließen ---
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;

    // Confirm hat Priorität
    const confirm = document.getElementById('confirmModal');
    if (confirm && confirm.classList.contains('active')) {
        _confirmCancel();
        return;
    }

    // Dann das Modal mit dem höchsten z-index schließen
    const openModals = Array.from(document.querySelectorAll('.modal.active'));
    if (openModals.length === 0) return;

    // Nach z-index sortieren (höchster zuerst)
    openModals.sort((a, b) => {
        const zA = parseInt(getComputedStyle(a).zIndex) || 0;
        const zB = parseInt(getComputedStyle(b).zIndex) || 0;
        return zB - zA;
    });

    closeModal(openModals[0].id);
});