// IMS - Main JavaScript
//
// Loaded once per page by legacy/includes/footer.php. Every function that was
// public before (openModal, closeModal, showAlert, showNotification,
// toggleMobileMenu, confirmDelete, exportToCSV, ...) is still a global with
// the same signature, because inline page scripts call them.

// ---------------------------------------------------------------------------
// Boot (single DOMContentLoaded handler; previously initializeApp() was
// registered twice, so modal/close handlers and alert timers ran twice).
// ---------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function () {
    initializeApp();
    initMobileMenu();
    enhancedAutoHideAlerts();
    highlightCurrentPage();
    initAnchorLinks();
    wrapOverflowingTables();
});

window.addEventListener('load', function () {
    // Images/fonts can change table widths after DOMContentLoaded.
    wrapOverflowingTables();
});

window.addEventListener('resize', debounce(function () {
    wrapOverflowingTables();
}, 250));

function initializeApp() {
    initTooltips();
    initModals();
    initFormValidation();
    initTabbedLongForms();

    if (typeof Chart !== 'undefined') {
        initCharts();
    }

    initDataTables();
    initSidebarToggle();
}

// Break very long multi-section forms into compact tabs while keeping every
// input in the same form for validation and submission.
function initTabbedLongForms() {
    document.querySelectorAll('form[data-form-tabs]').forEach(form => {
        const mode = form.dataset.formTabs;
        let panels = [];
        let navAnchor = null;
        if (mode === 'sections') {
            const sections = Array.from(form.querySelectorAll(':scope > .form-section'));
            if (sections.length < 3) return;
            panels = sections.slice(0, -1).map((element, index) => ({ element, title: element.querySelector('.section-title')?.textContent.trim() || `Section ${index + 1}` }));
            navAnchor = sections[0];
            sections[sections.length - 1].classList.add('long-form-actions');
        } else if (mode === 'label-sections') {
            const grid = form.querySelector('.form-grid');
            if (!grid) return;
            const children = Array.from(grid.children);
            const groups = [];
            children.forEach(element => {
                if (element.classList.contains('form-section-label')) groups.push({ title: element.textContent.trim(), elements: [element] });
                else if (groups.length) groups[groups.length - 1].elements.push(element);
            });
            if (groups.length < 3) return;
            panels = groups.map(group => {
                const panel = document.createElement('section');
                panel.className = 'long-form-panel';
                panel.style.cssText = 'grid-column:1/-1;display:grid;grid-template-columns:inherit;gap:inherit;align-content:start';
                group.elements.forEach(element => panel.append(element));
                grid.append(panel);
                return { element: panel, title: group.title };
            });
            navAnchor = grid;
        } else return;

        if (!panels.length) return;
        const nav = document.createElement('div');
        nav.className = 'long-form-tabs';
        nav.setAttribute('role', 'tablist');
        nav.setAttribute('aria-label', 'Form sections');
        panels.forEach((panel, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'long-form-tab';
            button.setAttribute('role', 'tab');
            button.textContent = panel.title;
            button.addEventListener('click', () => activate(index));
            panel.button = button;
            nav.append(button);
        });
        navAnchor.parentElement.insertBefore(nav, navAnchor);
        let active = 0;
        const activate = index => {
            active = index;
            panels.forEach((panel, i) => {
                panel.element.hidden = i !== active;
                panel.button.classList.toggle('active', i === active);
                panel.button.setAttribute('aria-selected', i === active ? 'true' : 'false');
            });
        };
        activate(0);
        const style = document.getElementById('long-form-tab-styles') || document.createElement('style');
        style.id = 'long-form-tab-styles';
        style.textContent = '.long-form-tabs{display:flex;gap:4px;overflow-x:auto;border-bottom:1px solid #dbe3ec;margin:0 0 18px}.long-form-tab{border:0;border-bottom:3px solid transparent;background:transparent;padding:11px 15px;color:#64748b;font-weight:700;white-space:nowrap;cursor:pointer}.long-form-tab.active{color:#0f766e;border-color:#0f766e}.long-form-panel[hidden],.form-section[hidden]{display:none!important}';
        if (!style.isConnected) document.head.append(style);

        // Native browser validation cannot focus an invalid input in a hidden
        // panel. Reveal its tab first, then invoke the usual field message.
        form.noValidate = true;
        form.addEventListener('submit', event => {
            const invalid = Array.from(form.elements).find(field => field.willValidate && !field.checkValidity());
            if (!invalid) return;
            event.preventDefault();
            const panelIndex = panels.findIndex(panel => panel.element.contains(invalid));
            if (panelIndex >= 0) activate(panelIndex);
            window.setTimeout(() => invalid.reportValidity(), 0);
        });
    });
}

// Sidebar Toggle (legacy .sidebar-toggle button, if a page has one)
function initSidebarToggle() {
    const toggleBtn = document.querySelector('.sidebar-toggle');
    const sidebar = document.querySelector('.sidebar');

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('active');
        });
    }
}

// ---------------------------------------------------------------------------
// Modals
// ---------------------------------------------------------------------------
function initModals() {
    const closeBtns = document.querySelectorAll('.close');

    closeBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const modal = this.closest('.modal');
            closeModal(modal);
        });
    });

    window.addEventListener('click', function (e) {
        if (e.target.classList && e.target.classList.contains('modal')) {
            if (isBootstrapModal(e.target)) return; // Bootstrap handles its own backdrop clicks
            closeModal(e.target);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        const open = Array.from(document.querySelectorAll('.modal.show')).filter(m => !isBootstrapModal(m));
        if (open.length) {
            // Not via closeModal(): many pages redefine it with an id-only signature.
            open[open.length - 1].classList.remove('show');
            if (!document.querySelector('.modal.show')) {
                document.body.style.overflow = '';
            }
        }
    });
}

// Modals driven by bootstrap.bundle.js (payment-voucher.php) must not have
// their .show class toggled behind Bootstrap's back, or the backdrop is left behind.
function isBootstrapModal(el) {
    return !!(el && window.bootstrap && window.bootstrap.Modal &&
        typeof window.bootstrap.Modal.getInstance === 'function' &&
        window.bootstrap.Modal.getInstance(el));
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal._returnFocus = document.activeElement;
        modal.classList.add('show');
        modal.removeAttribute('aria-hidden');
        document.body.style.overflow = 'hidden';
        scheduleTableWrap(); // tables inside the modal are measurable now

        // Move focus into the dialog: first form field, else the close button.
        const field = modal.querySelector(
            'input:not([type="hidden"]):not([disabled]):not([readonly]), select:not([disabled]), textarea:not([disabled]), .modal-close, button'
        );
        if (field) {
            window.setTimeout(() => field.focus(), 60);
        }
    }
}

function closeModal(modal) {
    if (typeof modal === 'string') {
        modal = document.getElementById(modal);
    }
    if (modal) {
        modal.classList.remove('show');
        if (!document.querySelector('.modal.show')) {
            document.body.style.overflow = '';
        }
        const opener = modal._returnFocus;
        modal._returnFocus = null;
        if (opener && typeof opener.focus === 'function' && document.contains(opener)) {
            opener.focus();
        }
    }
}

// ---------------------------------------------------------------------------
// Form Validation
// ---------------------------------------------------------------------------
function initFormValidation() {
    const forms = document.querySelectorAll('form[data-validate="true"]');

    forms.forEach(form => {
        form.addEventListener('submit', function (e) {
            if (!validateForm(this)) {
                e.preventDefault();
            }
        });
    });
}

function validateForm(form) {
    let isValid = true;
    const requiredFields = form.querySelectorAll('[required]');

    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            showFieldError(field, 'This field is required');
            isValid = false;
        } else {
            clearFieldError(field);
        }
    });

    const emailFields = form.querySelectorAll('input[type="email"]');
    emailFields.forEach(field => {
        if (field.value && !isValidEmail(field.value)) {
            showFieldError(field, 'Please enter a valid email address');
            isValid = false;
        }
    });

    const phoneFields = form.querySelectorAll('input[type="tel"]');
    phoneFields.forEach(field => {
        if (field.value && !isValidPhone(field.value)) {
            showFieldError(field, 'Please enter a valid phone number');
            isValid = false;
        }
    });

    return isValid;
}

function showFieldError(field, message) {
    clearFieldError(field);

    field.classList.add('error');
    field.setAttribute('aria-invalid', 'true');
    field.style.borderColor = '#b91c1c';

    const errorDiv = document.createElement('div');
    errorDiv.className = 'field-error';
    errorDiv.setAttribute('role', 'alert');
    errorDiv.textContent = message;

    field.parentNode.appendChild(errorDiv);
}

function clearFieldError(field) {
    field.classList.remove('error');
    field.removeAttribute('aria-invalid');
    field.style.borderColor = '';

    const errorDiv = field.parentNode.querySelector('.field-error');
    if (errorDiv) {
        errorDiv.remove();
    }
}

function isValidEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

function isValidPhone(phone) {
    const re = /^[\d\s\-\+\(\)]+$/;
    return re.test(phone) && phone.replace(/\D/g, '').length >= 10;
}

// ---------------------------------------------------------------------------
// Alerts
// ---------------------------------------------------------------------------
function buildAlert(message, type) {
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-' + type;
    alertDiv.setAttribute('role', type === 'danger' ? 'alert' : 'status');

    const icon = document.createElement('i');
    icon.className = 'fas fa-' + getAlertIcon(type);
    icon.setAttribute('aria-hidden', 'true');

    const text = document.createElement('span');
    text.textContent = String(message); // text, not HTML

    alertDiv.appendChild(icon);
    alertDiv.appendChild(text);
    return alertDiv;
}

function fadeOutAndRemove(el, delay) {
    setTimeout(() => {
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 300);
    }, delay);
}

function showAlert(message, type = 'info') {
    const alertDiv = buildAlert(message, type);

    // .content-area does not exist in the shared layout; fall back to <main>.
    const container = document.querySelector('.content-area') || document.querySelector('.main-content');
    if (container) {
        container.insertBefore(alertDiv, container.firstChild);
        fadeOutAndRemove(alertDiv, 5000);
    } else {
        showNotification(message, type);
    }
}

function getAlertIcon(type) {
    const icons = {
        'success': 'check-circle',
        'danger': 'exclamation-circle',
        'warning': 'exclamation-triangle',
        'info': 'info-circle'
    };
    return icons[type] || 'info-circle';
}

// Kept for backwards compatibility; auto-hiding is handled by
// enhancedAutoHideAlerts().
function autoHideAlerts() {}

// Which alerts disappear on their own:
//   - the session flash message from header.php (.alert-flash / data-autohide)
//   - any .alert-success that is not inside a form or modal
// Everything else (warnings, errors, info notes such as a KPI's rejection
// reason) stays on screen. Previously EVERY .alert on the page was removed
// after 5 seconds, including permanent page content.
// Opt out with data-persist or .alert-static; opt in with data-autohide="true".
function shouldAutoHide(alert) {
    if (alert.hasAttribute('data-persist') || alert.classList.contains('alert-static')) return false;
    if (alert.classList.contains('alert-flash') || alert.getAttribute('data-autohide') === 'true') return true;
    if (alert.closest('form, .modal, .modal-overlay, [role="dialog"]')) return false;
    return alert.classList.contains('alert-success');
}

function enhancedAutoHideAlerts() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        if (!alert.querySelector('.alert-close') && !alert.hasAttribute('data-persist')) {
            const closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'alert-close';
            closeBtn.setAttribute('aria-label', 'Dismiss');
            closeBtn.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i>';
            closeBtn.onclick = function () {
                fadeOutAndRemove(alert, 0);
            };
            alert.appendChild(closeBtn);
        }

        if (shouldAutoHide(alert)) {
            fadeOutAndRemove(alert, 5000);
        }
    });
}

function showNotification(message, type = 'info') {
    const alertDiv = buildAlert(message, type);
    alertDiv.style.cssText = 'position: fixed; top: 80px; right: 20px; z-index: 9999; min-width: 300px; max-width: calc(100vw - 40px); animation: slideInRight 0.3s;';

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'alert-close';
    closeBtn.setAttribute('aria-label', 'Dismiss');
    closeBtn.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i>';
    closeBtn.onclick = function () { alertDiv.remove(); };
    alertDiv.appendChild(closeBtn);

    document.body.appendChild(alertDiv);
    fadeOutAndRemove(alertDiv, 5000);
}

// ---------------------------------------------------------------------------
// Confirmation helpers
// ---------------------------------------------------------------------------
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

function confirmDelete(itemName, deleteUrl) {
    if (confirm(`Are you sure you want to delete ${itemName}? This action cannot be undone.`)) {
        window.location.href = deleteUrl;
    }
}

// ---------------------------------------------------------------------------
// AJAX helpers
// ---------------------------------------------------------------------------
function ajaxRequest(url, method, data, callback) {
    const xhr = new XMLHttpRequest();
    xhr.open(method, url, true);

    if (method === 'POST') {
        xhr.setRequestHeader('Content-Type', 'application/json');
    }

    xhr.onload = function () {
        if (xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                callback(null, response);
            } catch (e) {
                callback(null, xhr.responseText);
            }
        } else {
            callback(new Error('Request failed: ' + xhr.status));
        }
    };

    xhr.onerror = function () {
        callback(new Error('Network error'));
    };

    xhr.send(method === 'POST' ? JSON.stringify(data) : null);
}

function loadData(url, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = '<div class="spinner" role="status" aria-label="Loading"></div>';

    fetch(url)
        .then(response => response.text())
        .then(data => {
            container.innerHTML = data;
        })
        .catch(error => {
            container.innerHTML = '<p class="alert alert-danger">Error loading data</p>';
            console.error('loadData failed:', error);
        });
}

// ---------------------------------------------------------------------------
// Charts
// The previous version drew hard-coded SAMPLE data onto #projectStatusChart
// and #beneficiaryChart, which are real canvases on dashboard.php, after the
// dashboard had drawn its own charts. Pages now own their charts; these
// functions remain only so existing calls do not break.
// ---------------------------------------------------------------------------
function initCharts() {}
function createProjectStatusChart() {}
function createBeneficiaryChart() {}

// ---------------------------------------------------------------------------
// Data tables (search + sort on .data-table)
// ---------------------------------------------------------------------------
function initDataTables() {
    const tables = document.querySelectorAll('.main-content table');
    tables.forEach(table => {
        addTableSearch(table);
        addTableSort(table);
        initTablePagination(table);
    });
}

function initTablePagination(table) {
    const tbody = table.tBodies && table.tBodies[0];
    if (!tbody) return;
    const rows = Array.from(tbody.rows);
    if (rows.length <= 25 || table.closest('[data-no-pagination]')) return;

    // Existing server/client pagers remain authoritative.
    let ancestor = table.parentElement;
    while (ancestor && ancestor !== document.body) {
        if (ancestor.querySelector('[class*="pagination"], [class*="pager"], [aria-label*="pagination" i], .dataTables_paginate, [data-table-pagination]')) return;
        if (ancestor.matches('.card, .panel, .modal, .table-responsive')) break;
        ancestor = ancestor.parentElement;
    }

    rows.forEach(row => {
        row.dataset.tableSearchMatch = 'true';
        row.dataset.tableInitiallyHidden = row.style.display === 'none' ? 'true' : 'false';
    });
    const host = table.closest('.table-responsive, .table-wrap, .table-scroll') || table;
    if (table.parentElement && table.parentElement.closest('table')) return;
    const pager = document.createElement('div');
    pager.className = 'table-pagination-auto';
    pager.setAttribute('aria-label', 'Table pagination');
    host.insertAdjacentElement('afterend', pager);
    if (!document.getElementById('table-pagination-auto-styles')) {
        const style = document.createElement('style');
        style.id = 'table-pagination-auto-styles';
        style.textContent = '.table-pagination-auto{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 2px;color:#64748b;font-size:12px}.table-pagination-controls{display:flex;align-items:center;gap:7px}.table-pagination-controls select,.table-pagination-controls button{min-height:34px;padding:6px 10px;border:1px solid #dbe3ec;border-radius:7px;background:#fff;color:#334155;font-weight:700}.table-pagination-controls button:not(:disabled){cursor:pointer}.table-pagination-controls button:disabled{opacity:.5;cursor:not-allowed}@media(max-width:600px){.table-pagination-auto{align-items:flex-start;flex-direction:column}}';
        document.head.append(style);
    }

    let page = 1;
    let pageSize = 25;
    const searchableRows = () => rows.filter(row => row.dataset.tableInitiallyHidden !== 'true' && row.dataset.tableSearchMatch !== 'false');
    const render = () => {
        const matching = searchableRows();
        const pages = Math.max(1, Math.ceil(matching.length / pageSize));
        page = Math.min(Math.max(1, page), pages);
        const start = (page - 1) * pageSize;
        rows.forEach(row => { row.style.display = 'none'; });
        matching.slice(start, start + pageSize).forEach(row => { row.style.display = ''; });
        const end = Math.min(start + pageSize, matching.length);
        pager.replaceChildren();
        const info = document.createElement('span');
        info.className = 'table-pagination-info';
        info.textContent = matching.length ? `Showing ${start + 1}–${end} of ${matching.length}` : 'No matching rows';
        const controls = document.createElement('div');
        controls.className = 'table-pagination-controls';
        const size = document.createElement('select');
        size.setAttribute('aria-label', 'Rows per page');
        [25, 50, 100].forEach(value => {
            const option = document.createElement('option');
            option.value = String(value);
            option.textContent = `${value} per page`;
            option.selected = pageSize === value;
            size.append(option);
        });
        size.addEventListener('change', () => { pageSize = Number(size.value); page = 1; render(); });
        const previous = document.createElement('button');
        previous.type = 'button'; previous.textContent = 'Previous'; previous.disabled = page <= 1;
        previous.addEventListener('click', () => { page--; render(); });
        const next = document.createElement('button');
        next.type = 'button'; next.textContent = 'Next'; next.disabled = page >= pages;
        next.addEventListener('click', () => { page++; render(); });
        controls.append(size, previous, next);
        pager.append(info, controls);
        pager.hidden = matching.length <= 25;
    };
    table._paginationRender = render;
    render();
}

function addTableSearch(table) {
    const scope = table.closest('.table-responsive-auto') ? table.closest('.table-responsive-auto').parentElement : table.parentElement;
    const searchInput = scope ? scope.querySelector('.table-search') : null;
    if (!searchInput) return;

    searchInput.addEventListener('keyup', function () {
        const searchTerm = this.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.dataset.tableSearchMatch = text.includes(searchTerm) ? 'true' : 'false';
        });
        if (table._paginationRender) table._paginationRender();
        else rows.forEach(row => { row.style.display = row.dataset.tableSearchMatch === 'true' ? '' : 'none'; });
    });
}

function addTableSort(table) {
    const headers = table.querySelectorAll('th[data-sortable="true"]');

    headers.forEach((header, index) => {
        header.style.cursor = 'pointer';
        header.addEventListener('click', function () {
            sortTable(table, index);
        });
    });
}

function sortTable(table, columnIndex) {
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));

    rows.sort((a, b) => {
        const aValue = a.cells[columnIndex].textContent.trim();
        const bValue = b.cells[columnIndex].textContent.trim();

        return aValue.localeCompare(bValue, undefined, { numeric: true });
    });

    rows.forEach(row => tbody.appendChild(row));
    if (table._paginationRender) table._paginationRender();
}

// ---------------------------------------------------------------------------
// Responsive tables
// Wraps any table inside <main> that is wider than its container in a
// horizontally scrollable .table-responsive div, unless it is already inside
// a scrolling wrapper. Tables that fit are left untouched, so page CSS that
// relies on the original DOM keeps working. Opt out with data-no-table-wrap.
// ---------------------------------------------------------------------------
function wrapOverflowingTables() {
    const main = document.querySelector('.main-content');
    if (!main) return;

    main.querySelectorAll('table').forEach(table => {
        if (table.closest('[data-no-table-wrap], .fc, .table-responsive, .table-wrap, .table-scroll')) return;
        if (table.parentElement && table.parentElement.closest('table')) return; // nested table
        if (!table.offsetParent) {
            watchHiddenTable(table); // hidden (inactive tab, closed modal): re-check once shown
            return;
        }

        // Already inside a scroll container below <main>?
        let el = table.parentElement;
        while (el && el !== main) {
            const ox = getComputedStyle(el).overflowX;
            if (ox === 'auto' || ox === 'scroll') return;
            el = el.parentElement;
        }

        const parent = table.parentElement;
        const tableRight = table.getBoundingClientRect().right;
        const mainRight = main.getBoundingClientRect().right;
        const overflows = table.offsetWidth > parent.clientWidth + 1 || tableRight > mainRight + 1;
        if (!overflows) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'table-responsive table-responsive-auto';
        wrapper.setAttribute('tabindex', '0'); // keyboard-scrollable
        wrapper.setAttribute('role', 'region');
        wrapper.setAttribute('aria-label', 'Scrollable table');
        parent.insertBefore(wrapper, table);
        wrapper.appendChild(table);
    });
}

// Tables that are hidden at load (inactive tab panes, closed modals,
// collapsed sections) cannot be measured. Watch them and re-run the wrap
// check when they become visible. IntersectionObserver fires when a
// display:none element starts rendering in the viewport; the delegated
// click/Bootstrap-event listeners cover tabs/modals that open off-screen.
const watchedHiddenTables = (typeof WeakSet === 'function') ? new WeakSet() : null;
let hiddenTableObserver = null;

const scheduleTableWrap = (function () {
    let pending = false;
    return function () {
        if (pending) return;
        pending = true;
        const run = function () { pending = false; wrapOverflowingTables(); };
        if (window.requestAnimationFrame) {
            // two frames: let tab/modal show classes and transitions apply first
            requestAnimationFrame(function () { requestAnimationFrame(run); });
        } else {
            setTimeout(run, 50);
        }
    };
})();

function watchHiddenTable(table) {
    if (!watchedHiddenTables || watchedHiddenTables.has(table)) return;
    if (!('IntersectionObserver' in window)) return;
    watchedHiddenTables.add(table);

    if (!hiddenTableObserver) {
        hiddenTableObserver = new IntersectionObserver(function (entries) {
            let shown = false;
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    shown = true;
                    hiddenTableObserver.unobserve(entry.target);
                    watchedHiddenTables.delete(entry.target);
                }
            });
            if (shown) scheduleTableWrap();
        });
    }
    hiddenTableObserver.observe(table);
}

// Tab buttons / modal triggers: re-check after any click that may reveal content.
document.addEventListener('click', function (e) {
    if (e.target && e.target.closest && e.target.closest(
        '[role="tab"], .tab, .tab-btn, .tab-button, .nav-link, [data-tab], [data-bs-toggle], [onclick*="Modal"], [onclick*="Tab"], [onclick*="tab"]'
    )) {
        scheduleTableWrap();
    }
}, true);
['shown.bs.tab', 'shown.bs.modal', 'shown.bs.collapse'].forEach(function (evt) {
    document.addEventListener(evt, scheduleTableWrap);
});

// ---------------------------------------------------------------------------
// Files / export / print
// ---------------------------------------------------------------------------
function previewFile(input, previewId) {
    const file = input.files[0];
    const preview = document.getElementById(previewId);

    if (file && preview) {
        const reader = new FileReader();

        reader.onload = function (e) {
            preview.innerHTML = '';
            if (file.type.startsWith('image/')) {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.alt = 'Preview of ' + file.name;
                img.style.maxWidth = '100%';
                img.style.maxHeight = '200px';
                preview.appendChild(img);
            } else {
                const p = document.createElement('p');
                p.textContent = 'File selected: ' + file.name;
                preview.appendChild(p);
            }
        };

        reader.readAsDataURL(file);
    }
}

function exportToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const csv = [];
    const rows = table.querySelectorAll('tr');

    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        // Quote every field so commas/quotes/newlines in cells don't shift columns.
        const rowData = Array.from(cols).map(col => '"' + col.textContent.trim().replace(/"/g, '""') + '"');
        csv.push(rowData.join(','));
    });

    downloadFile(csv.join('\n'), filename + '.csv', 'text/csv');
}

function exportToPDF(contentId, filename) {
    // This requires a library like jsPDF
    showAlert('PDF export requires additional setup', 'info');
}

function downloadFile(content, filename, mimeType) {
    const blob = new Blob([content], { type: mimeType });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    window.URL.revokeObjectURL(url);
    a.remove();
}

function printContent(elementId) {
    const element = document.getElementById(elementId);
    if (!element) return;

    const printWindow = window.open('', '', 'height=600,width=800');
    printWindow.document.write('<html><head><title>Print</title>');
    printWindow.document.write('<link rel="stylesheet" href="css/style.css">');
    printWindow.document.write('</head><body>');
    printWindow.document.write(element.innerHTML);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}

// ---------------------------------------------------------------------------
// Misc helpers
// ---------------------------------------------------------------------------
function animateProgress(elementId, targetValue) {
    const element = document.getElementById(elementId);
    if (!element) return;

    let currentValue = 0;
    const increment = targetValue / 50;

    const timer = setInterval(() => {
        currentValue += increment;
        if (currentValue >= targetValue) {
            currentValue = targetValue;
            clearInterval(timer);
        }
        element.style.width = currentValue + '%';
        element.textContent = Math.round(currentValue) + '%';
    }, 20);
}

function formatDate(dateString, format = 'Y-m-d') {
    const date = new Date(dateString);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return format
        .replace('Y', year)
        .replace('m', month)
        .replace('d', day);
}

function formatNumber(number, decimals = 0) {
    return Number(number).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

function formatCurrency(amount, currency = 'UGX') {
    return currency + ' ' + formatNumber(amount, 2);
}

// Tooltips
function initTooltips() {
    const tooltips = document.querySelectorAll('[data-tooltip]');

    tooltips.forEach(element => {
        element.addEventListener('mouseenter', function () {
            showTooltip(this);
        });

        element.addEventListener('mouseleave', function () {
            hideTooltip();
        });
    });
}

function showTooltip(element) {
    const text = element.getAttribute('data-tooltip');
    const tooltip = document.createElement('div');
    tooltip.className = 'tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.textContent = text;
    tooltip.style.cssText = `
        position: absolute;
        background: #333;
        color: white;
        padding: 5px 10px;
        border-radius: 4px;
        font-size: 12px;
        z-index: 9999;
        pointer-events: none;
    `;

    document.body.appendChild(tooltip);

    const rect = element.getBoundingClientRect();
    tooltip.style.left = rect.left + window.scrollX + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
    tooltip.style.top = rect.top + window.scrollY - tooltip.offsetHeight - 5 + 'px';
}

function hideTooltip() {
    const tooltip = document.querySelector('.tooltip');
    if (tooltip) {
        tooltip.remove();
    }
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Local Storage Helpers (storage can be unavailable in private mode)
function saveToStorage(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* ignore */ }
}

function getFromStorage(key) {
    try {
        const item = localStorage.getItem(key);
        return item ? JSON.parse(item) : null;
    } catch (e) {
        return null;
    }
}

function removeFromStorage(key) {
    try { localStorage.removeItem(key); } catch (e) { /* ignore */ }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showAlert('Copied to clipboard!', 'success');
    }).catch(() => {
        showAlert('Failed to copy', 'danger');
    });
}

// ---------------------------------------------------------------------------
// Mobile menu
// ---------------------------------------------------------------------------
function setMobileMenu(open) {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const menuToggle = document.getElementById('menuToggle');

    if (!sidebar || !overlay || !menuToggle) return;

    const icon = menuToggle.querySelector('i');

    sidebar.classList.toggle('active', open);
    overlay.classList.toggle('active', open);
    document.body.classList.toggle('sidebar-open', open);
    menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');

    if (icon) {
        icon.classList.toggle('fa-bars', !open);
        icon.classList.toggle('fa-times', open);
    }
}

function toggleMobileMenu() {
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;
    setMobileMenu(!sidebar.classList.contains('active'));
}

function initMobileMenu() {
    // Close menu when a link is followed (mobile)
    const sidebarLinks = document.querySelectorAll('.sidebar-menu a');
    sidebarLinks.forEach(link => {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 768) {
                setMobileMenu(false);
            }
        });
    });

    // Close with Escape
    document.addEventListener('keydown', function (e) {
        const sidebar = document.querySelector('.sidebar');
        if (e.key === 'Escape' && sidebar && sidebar.classList.contains('active')) {
            setMobileMenu(false);
            const toggle = document.getElementById('menuToggle');
            if (toggle) toggle.focus();
        }
    });

    // Reset when resizing up to desktop
    window.addEventListener('resize', function () {
        if (window.innerWidth > 768) {
            setMobileMenu(false);
        }
    });
}

// Sidebar highlighting for the current page
function highlightCurrentPage() {
    const currentPage = window.location.pathname.split('/').pop();
    const sidebarLinks = document.querySelectorAll('.sidebar-menu li');

    sidebarLinks.forEach(li => {
        const link = li.querySelector('a');
        if (link && link.getAttribute('href') === currentPage) {
            li.classList.add('active');
            link.setAttribute('aria-current', 'page');
        } else {
            li.classList.remove('active');
            if (link) link.removeAttribute('aria-current');
        }
    });
}

// ---------------------------------------------------------------------------
// In-page anchor links
// Previously document.querySelector('#') threw a SyntaxError on every click
// of an href="#" link. Default navigation is still prevented for "#..." links
// (pages rely on that for href="#" + onclick buttons).
// ---------------------------------------------------------------------------
function initAnchorLinks() {
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const href = this.getAttribute('href');
            if (!href || href.length < 2) return;

            let target = null;
            try {
                target = document.querySelector(href);
            } catch (err) {
                target = document.getElementById(href.slice(1));
            }

            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                if (target.hasAttribute('tabindex')) {
                    target.focus({ preventScroll: true });
                }
            }
        });
    });
}

// Clickable table rows (<tr data-clickable="1" data-url="...">)
function makeTableRowsClickable() {
    const tables = document.querySelectorAll('.data-table');
    tables.forEach(table => {
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            if (!row.dataset.clickable) return;

            row.style.cursor = 'pointer';
            row.addEventListener('click', function (e) {
                if (e.target.closest('button') || e.target.closest('a')) return;

                const url = this.dataset.url;
                if (url) {
                    window.location.href = url;
                }
            });
        });
    });
}

// Keyframes used by showNotification(). Wrapped in an IIFE: the old top-level
// "const style" became a global binding that clashed with any page script
// declaring its own `style` variable.
(function () {
    const styleEl = document.createElement('style');
    styleEl.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }
    `;
    document.head.appendChild(styleEl);
})();
