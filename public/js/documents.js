/*
 * Documents library (legacy/documents.php): SharePoint-style behaviour.
 *
 * Selection (click, Ctrl/Cmd+click, Shift+click, checkboxes, keyboard),
 * a selection-aware command bar and context menu, the details pane,
 * sorting, file-type and keyword filters, list / compact / tiles layouts,
 * favourites, copy link, CSV export, drag-and-drop upload and move.
 *
 * Server config comes from window.DOC_LIB (see documents.php).
 */
(function () {
    'use strict';

    var CFG = window.DOC_LIB || {};
    var HANDLER = 'includes/documents-handler.php';
    var app = document.getElementById('spApp');

    if (!app) {
        return;
    }

    /* =====================================================================
     * Helpers
     * ===================================================================== */

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function store(key, value) {
        try {
            if (value === undefined) {
                return window.localStorage.getItem(key);
            }
            window.localStorage.setItem(key, value);
        } catch (e) { /* private mode */ }
        return null;
    }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function plural(n, word) {
        return n + ' ' + word + (n === 1 ? '' : 's');
    }

    function parseWhen(value) {
        if (!value) return null;
        var d = new Date(String(value).replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    }

    /* "A few seconds ago", "3 hours ago", "Yesterday at 14:05", "12 Mar". */
    function relativeWhen(value) {
        var d = value instanceof Date ? value : parseWhen(value);
        if (!d) return '';

        var now = new Date();
        var diff = (now - d) / 1000;
        var time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        if (diff < 0) diff = 0;
        if (diff < 60) return 'A few seconds ago';
        if (diff < 3600) return plural(Math.floor(diff / 60), 'minute') + ' ago';

        var startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        if (d >= startToday) return plural(Math.floor(diff / 3600), 'hour') + ' ago';

        var startYesterday = new Date(startToday.getTime() - 86400000);
        if (d >= startYesterday) return 'Yesterday at ' + time;

        if (diff < 6 * 86400) {
            return d.toLocaleDateString([], { weekday: 'long' }) + ' at ' + time;
        }

        return d.toLocaleDateString([], d.getFullYear() === now.getFullYear()
            ? { day: 'numeric', month: 'short' }
            : { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function initials(name) {
        return String(name || '?').trim().split(/\s+/).slice(0, 2).map(function (p) {
            return p.charAt(0).toUpperCase();
        }).join('') || '?';
    }

    var toastTimer = null;

    function toast(text, icon, sticky) {
        var el = document.getElementById('spToast');
        if (!el) return;
        document.getElementById('spToastText').textContent = text;
        document.getElementById('spToastIcon').className = 'fas ' + (icon || 'fa-circle-check') + (icon === 'fa-spinner' ? ' fa-spin' : '');
        el.classList.add('is-on');
        window.clearTimeout(toastTimer);
        if (!sticky) {
            toastTimer = window.setTimeout(function () { el.classList.remove('is-on'); }, 3200);
        }
    }

    function setItems(form, items) {
        var holder = $('.sp-items-holder', form);
        holder.innerHTML = '';
        items.forEach(function (it) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'items[]';
            input.value = it.type + ':' + it.id;
            holder.appendChild(input);
        });
    }

    function setReturn(form) {
        var input = form.querySelector('input[name="return"]');
        if (input) input.value = CFG.returnQuery || '';
    }

    /* Immediate POST through the hidden action form. */
    function postAction(action, fields, items) {
        var form = document.getElementById('spActionForm');
        form.querySelector('[name="action"]').value = action;
        form.querySelector('[name="document_id"]').value = (fields && fields.document_id) || '';
        form.querySelector('[name="version_id"]').value = (fields && fields.version_id) || '';
        setItems(form, items || []);
        setReturn(form);
        toast('Working...', 'fa-spinner', true);
        form.submit();
    }

    /* POST FormData with fetch, then follow the server's redirect. */
    function submitFormData(formData, busyText) {
        toast(busyText || 'Working...', 'fa-spinner', true);

        return fetch(HANDLER, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (response) {
                window.location.href = response.url || window.location.href;
            })
            .catch(function () {
                toast('Something went wrong. Please try again.', 'fa-triangle-exclamation');
            });
    }

    /* =====================================================================
     * Hooks used by the create / upload / edit / share modals
     * ===================================================================== */

    var knownTypes = CFG.folderTypes || [];

    function toggleOther(selectId, wrapId, inputId) {
        var type = (document.getElementById(selectId) || {}).value || '';
        var wrap = document.getElementById(wrapId);
        var input = document.getElementById(inputId);
        if (!wrap || !input) return;
        var show = type === 'Other';
        wrap.style.display = show ? 'block' : 'none';
        input.required = show;
        if (!show) input.value = '';
    }

    window.docChooseNewFolder = function () { closeModal('addNewModal'); openModal('createFolderModal'); };
    window.docChooseUploadFile = function () { closeModal('addNewModal'); openModal('uploadModal'); };
    window.docChooseUploadFolder = function () { closeModal('addNewModal'); openModal('uploadFolderModal'); };
    window.docToggleOtherFolderType = function () { toggleOther('folder_document_type', 'folderOtherTypeWrap', 'folder_other_name'); };
    window.docToggleUploadOtherType = function () { toggleOther('upload_document_type', 'uploadOtherTypeWrap', 'upload_other_name'); };
    window.docToggleEditFolderOtherType = function () { toggleOther('editFolderType', 'editFolderOtherTypeWrap', 'editFolderOtherTypeName'); };
    window.docToggleEditOtherType = function () { toggleOther('editDocumentType', 'editOtherTypeWrap', 'editOtherTypeName'); };

    function applyType(selectId, otherId, current) {
        var select = document.getElementById(selectId);
        var other = document.getElementById(otherId);
        current = String(current || '');
        if (knownTypes.indexOf(current) !== -1) {
            select.value = current;
            other.value = '';
        } else {
            select.value = 'Other';
            other.value = current;
        }
    }

    window.docOpenEditFolder = function (data) {
        if (!data) return;
        document.getElementById('editFolderId').value = data.folder_id || '';
        document.getElementById('editFolderParentId').value = data.parent_folder_id || 0;
        document.getElementById('editFolderName').value = data.folder_name || '';
        document.getElementById('editFolderSummaryName').textContent = data.folder_name || 'Folder';
        applyType('editFolderType', 'editFolderOtherTypeName', data.document_type);
        document.getElementById('editFolderDescription').value = data.description || '';
        document.getElementById('editFolderProject').value = Number(data.project_id || 0) > 0 ? String(data.project_id) : '';
        document.getElementById('editFolderProgram').value = Number(data.program_id || 0) > 0 ? String(data.program_id) : '';
        window.docToggleEditFolderOtherType();
        openModal('editFolderModal');
    };

    window.docOpenEditFile = function (data) {
        if (!data) return;
        document.getElementById('editDocumentId').value = data.document_id || '';
        document.getElementById('editDocumentFolderId').value = data.folder_id || '';
        document.getElementById('editDocumentName').value = data.document_name || '';
        document.getElementById('editDocumentSummaryName').textContent = data.document_name || 'Document';
        applyType('editDocumentType', 'editOtherTypeName', data.document_type);
        document.getElementById('editDocumentDescription').value = data.description || '';
        document.getElementById('editDocumentTags').value = data.tags || '';
        document.getElementById('editDocumentProject').value = Number(data.project_id || 0) > 0 ? String(data.project_id) : '';
        document.getElementById('editDocumentProgram').value = Number(data.program_id || 0) > 0 ? String(data.program_id) : '';
        window.docToggleEditOtherType();
        openModal('editFileModal');
    };

    window.docOpenPermissions = function (type, id, name) {
        document.getElementById('permResourceType').value = type;
        document.getElementById('permResourceId').value = id;
        document.getElementById('permResourceName').textContent = name;
        $$('.perm-box').forEach(function (box) { box.checked = false; });

        fetch(HANDLER + '?action=get_permissions&resource_type=' + encodeURIComponent(type) + '&resource_id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                (data.permissions || []).forEach(function (row) {
                    ['view', 'edit', 'download'].forEach(function (right) {
                        var box = $('.perm-box[data-user="' + row.user_id + '"][data-right="' + right + '"]');
                        if (box) box.checked = Number(row['can_' + right] || 0) === 1;
                    });
                });
            })
            .catch(function () { /* open with empty grid */ })
            .then(function () { openModal('permissionsModal'); });
    };

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    window.docUpdateSelectedFiles = function (input) {
        var summary = document.getElementById('selectedFilesSummary');
        if (!summary) return;
        var files = Array.prototype.slice.call((input && input.files) || []);
        if (!files.length) {
            summary.textContent = 'You can select multiple files at once.';
            return;
        }
        var total = files.reduce(function (sum, f) { return sum + Number(f.size || 0); }, 0);
        summary.innerHTML = '<strong>' + plural(files.length, 'file') + ' selected</strong> &middot; ' + formatBytes(total);
    };

    window.docUpdateFolderUpload = function (input) {
        var summary = document.getElementById('folderUploadSummary');
        if (!summary) return;
        var files = Array.prototype.slice.call((input && input.files) || []);
        if (!files.length) {
            summary.textContent = 'Select a folder. Its subfolders and files will be preserved.';
            return;
        }
        var first = files[0].webkitRelativePath || files[0].name || '';
        summary.textContent = (first.indexOf('/') !== -1 ? first.split('/')[0] : 'Selected folder') + ' · ' + plural(files.length, 'file');
    };

    /* =====================================================================
     * Rows and selection
     * ===================================================================== */

    var selected = [];
    var anchorRow = null;

    function allRows() { return $$('.sp-row'); }
    function visibleRows() { return allRows().filter(function (r) { return !r.hidden; }); }

    function itemOf(row) {
        var d = row.dataset;
        var edit = {};
        try { edit = JSON.parse(d.edit || '{}'); } catch (e) { edit = {}; }
        return {
            row: row,
            type: d.type,
            id: Number(d.id),
            name: d.name,
            kind: d.kind,
            parent: Number(d.parent || 0),
            url: d.url,
            canView: d.canView === '1',
            canEdit: d.canEdit === '1',
            canDownload: d.canDownload === '1',
            favorite: d.favorite === '1',
            checkedOut: d.checkedOut || '',
            checkedOutMe: d.checkedOutMe === '1',
            edit: edit
        };
    }

    function selectedItems() { return selected.map(itemOf); }

    function setRowSelected(row, on) {
        row.classList.toggle('is-selected', on);
        row.setAttribute('aria-selected', on ? 'true' : 'false');
        var box = $('.sp-row-check', row);
        if (box) box.checked = on;
    }

    function select(rowsToSelect) {
        allRows().forEach(function (r) { setRowSelected(r, false); });
        selected = rowsToSelect.slice();
        selected.forEach(function (r) { setRowSelected(r, true); });
        selectionChanged();
    }

    function toggleRow(row) {
        var idx = selected.indexOf(row);
        if (idx === -1) {
            selected.push(row);
            setRowSelected(row, true);
        } else {
            selected.splice(idx, 1);
            setRowSelected(row, false);
        }
        anchorRow = row;
        selectionChanged();
    }

    function selectRange(toRow) {
        var list = visibleRows();
        var a = list.indexOf(anchorRow || toRow);
        var b = list.indexOf(toRow);
        if (a === -1) a = b;
        select(list.slice(Math.min(a, b), Math.max(a, b) + 1));
    }

    function focusRow(row) {
        allRows().forEach(function (r) { r.tabIndex = -1; });
        if (row) {
            row.tabIndex = 0;
            row.focus({ preventScroll: false });
        }
    }

    /* =====================================================================
     * Commands
     * ===================================================================== */

    var CMD = {
        'open': ['Open', 'fa-arrow-up-right-from-square'],
        'preview': ['Preview', 'fa-eye'],
        'share': ['Share', 'fa-share-nodes'],
        'copy-link': ['Copy link', 'fa-link'],
        'download': ['Download', 'fa-download'],
        'favorite': ['Favorite', 'fa-star'],
        'delete': ['Delete', 'fa-trash-can'],
        'rename': ['Edit details', 'fa-pen'],
        'move': ['Move to', 'fa-arrow-right-to-bracket'],
        'versions': ['Version history', 'fa-clock-rotate-left'],
        'new-version': ['Upload new version', 'fa-file-arrow-up'],
        'checkout': ['Check out', 'fa-lock'],
        'checkin': ['Check in', 'fa-lock-open'],
        'details': ['Details', 'fa-circle-info'],
        'restore': ['Restore', 'fa-rotate-left'],
        'purge': ['Delete permanently', 'fa-trash-can']
    };

    var CONTEXT_CMDS = CFG.view === 'bin'
        ? ['restore', 'purge', '-', 'details']
        : ['open', 'preview', '-', 'share', 'copy-link', 'download', 'favorite', '-', 'rename', 'move', 'delete', '-', 'versions', 'new-version', 'checkout', 'checkin', '-', 'details'];

    function blockedByCheckout(it) {
        return it.checkedOut !== '' && !it.checkedOutMe && !CFG.isAdmin;
    }

    function allowed(cmd, items) {
        var n = items.length;
        var one = n === 1 ? items[0] : null;
        var isDoc = one && one.type === 'document';
        var bin = CFG.view === 'bin';
        var lib = !!CFG.libReady;

        switch (cmd) {
            case 'new-folder':
            case 'upload-files': return n === 0 && !!CFG.canCreate;
            case 'upload-folder': return n === 0 && !!CFG.canUploadFolder;
            case 'open': return !!one && !bin && (one.type === 'folder' || one.canView);
            case 'preview': return !!isDoc && !bin && one.canView;
            case 'share': return !!one && !bin && one.canEdit;
            case 'copy-link': return !!one && !bin;
            case 'download': return n > 0 && !bin && items.every(function (it) { return it.type === 'document' && it.canDownload; });
            case 'favorite': return n > 0 && !bin && lib;
            case 'delete':
            case 'move': return n > 0 && !bin && items.every(function (it) { return it.canEdit && !blockedByCheckout(it); });
            case 'rename': return !!one && !bin && one.canEdit && !blockedByCheckout(one);
            case 'versions': return !!isDoc && !bin && lib;
            case 'new-version': return !!isDoc && !bin && lib && one.canEdit && !blockedByCheckout(one);
            case 'checkout': return !!isDoc && !bin && lib && one.canEdit && one.checkedOut === '';
            case 'checkin': return !!isDoc && !bin && lib && one.checkedOut !== '' && (one.checkedOutMe || CFG.isAdmin);
            case 'details': return true;
            case 'export': return n === 0 && allRows().length > 0;
            case 'restore':
            case 'purge': return n > 0 && bin;
            case 'empty-bin': return n === 0 && bin && allRows().length > 0;
            case 'clear-selection': return n > 0;
            default: return false;
        }
    }

    function showRule(rule, n) {
        if (rule === 'none') return n === 0;
        if (rule === 'one') return n === 1;
        if (rule === 'any') return n > 0;
        return true;
    }

    function refreshCommandBar() {
        var items = selectedItems();
        var n = items.length;
        var bar = document.getElementById('spCommandBar');

        $$('[data-cmd]', bar).forEach(function (btn) {
            var wrap = btn.closest('.sp-cmd-wrap');
            var rule = btn.dataset.show;
            var ok = allowed(btn.dataset.cmd, items) && (!rule || showRule(rule, n));
            if (btn.closest('.sp-cmd-right')) ok = btn.dataset.cmd === 'details' || ok;
            btn.hidden = !ok;
            if (wrap && wrap !== btn) btn.hidden = !ok;
        });

        $$('.sp-cmd-wrap', bar).forEach(function (wrap) {
            var rule = wrap.dataset.show;
            var anyItem = $$('.sp-menu [data-cmd]', wrap).some(function (b) { return !b.hidden; });
            var hasCmdItems = $$('.sp-menu [data-cmd]', wrap).length > 0;
            wrap.hidden = !(showRule(rule, n) && (!hasCmdItems || anyItem));
        });

        $$('.sp-menu-sep', bar).forEach(function (sep) {
            var prev = sep.previousElementSibling;
            var next = sep.nextElementSibling;
            var prevShown = false;
            var nextShown = false;
            while (prev) { if (!prev.hidden && !prev.classList.contains('sp-menu-sep')) { prevShown = true; break; } prev = prev.previousElementSibling; }
            while (next) { if (!next.hidden && !next.classList.contains('sp-menu-sep')) { nextShown = true; break; } next = next.nextElementSibling; }
            sep.hidden = !(prevShown && nextShown);
        });

        // Favorite reads as "Remove from favorites" when everything selected is starred.
        var fav = $('[data-cmd="favorite"]', bar);
        if (fav) {
            var allFav = n > 0 && items.every(function (it) { return it.favorite; });
            fav.innerHTML = '<i class="' + (allFav ? 'fas' : 'far') + ' fa-star" aria-hidden="true"></i><span>' + (allFav ? 'Remove from favorites' : 'Favorite') + '</span>';
        }

        var pill = document.getElementById('spSelectionPill');
        if (pill) {
            pill.hidden = n === 0;
            document.getElementById('spSelectionCount').textContent = n + ' selected';
        }
    }

    function selectionChanged() {
        var total = visibleRows().length;
        var all = document.getElementById('spSelectAll');
        if (all) {
            all.checked = total > 0 && selected.length === total;
            all.indeterminate = selected.length > 0 && selected.length < total;
        }
        var list = document.getElementById('spList');
        if (list) list.classList.toggle('has-selection', selected.length > 0);
        refreshCommandBar();
        renderDetails();
    }

    function absoluteLink(it) {
        var path = it.type === 'folder' ? 'documents?folder=' + it.id : HANDLER + '?action=view&id=' + it.id;
        return new URL(path, window.location.href).href;
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy') ? resolve() : reject(); } catch (e) { reject(e); }
            area.remove();
        });
    }

    function downloadAll(items) {
        items.forEach(function (it, i) {
            window.setTimeout(function () {
                var frame = document.createElement('iframe');
                frame.hidden = true;
                frame.src = HANDLER + '?action=download&id=' + it.id;
                document.body.appendChild(frame);
                window.setTimeout(function () { frame.remove(); }, 60000);
            }, i * 600);
        });
        toast(items.length === 1 ? 'Downloading ' + items[0].name : 'Downloading ' + plural(items.length, 'file'), 'fa-download');
    }

    function setFavorite(items, on) {
        var fd = new FormData();
        fd.append('action', 'toggle_favorite');
        fd.append('state', on ? '1' : '0');
        items.forEach(function (it) { fd.append('items[]', it.type + ':' + it.id); });

        return fetch(HANDLER, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) throw new Error(data.error || 'failed');

                if (CFG.view === 'favorites' && !on) {
                    window.location.reload();
                    return;
                }

                items.forEach(function (it) {
                    if (!it.row) return;
                    it.row.dataset.favorite = on ? '1' : '0';
                    var mark = $('.sp-fav-mark', it.row);
                    if (on && !mark) {
                        var star = document.createElement('i');
                        star.className = 'fas fa-star sp-fav-mark';
                        star.title = 'Favorite';
                        star.setAttribute('aria-hidden', 'true');
                        $('.sp-name', it.row).insertAdjacentElement('afterend', star);
                    } else if (!on && mark) {
                        mark.remove();
                    }
                });

                detailsCache = {};
                refreshCommandBar();
                renderDetails();
                toast(on ? 'Added to Favorites' : 'Removed from Favorites', 'fa-star');
            })
            .catch(function () {
                toast('Could not update favorites.', 'fa-triangle-exclamation');
            });
    }

    function confirmModal(opts) {
        var form = document.getElementById('spConfirmForm');
        document.getElementById('spConfirmAction').value = opts.action;
        document.getElementById('spConfirmTitle').textContent = opts.title;
        document.getElementById('spConfirmMessage').innerHTML = opts.message;
        document.getElementById('spConfirmSubmit').textContent = opts.button;
        document.getElementById('spConfirmIcon').innerHTML = '<i class="fas ' + (opts.icon || 'fa-trash-can') + '" aria-hidden="true"></i>';
        setItems(form, opts.items || []);
        setReturn(form);
        openModal('spConfirmModal');
    }

    function describe(items) {
        if (items.length === 1) return '<strong>' + esc(items[0].name) + '</strong>';
        return '<strong>' + plural(items.length, 'item') + '</strong>';
    }

    function openMove(items) {
        var form = document.getElementById('spMoveForm');
        var select = document.getElementById('spMoveTarget');
        var submit = document.getElementById('spMoveSubmit');
        setItems(form, items);
        document.getElementById('spMoveSummary').innerHTML = 'Move ' + describe(items) + ' to:';
        select.innerHTML = '<option value="">Loading folders...</option>';
        select.disabled = true;
        submit.disabled = true;
        openModal('spMoveModal');

        var qs = items.map(function (it) { return 'items[]=' + encodeURIComponent(it.type + ':' + it.id); }).join('&');
        var here = items[0].parent;

        fetch(HANDLER + '?action=get_move_targets&' + qs, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var options = (data && data.success && data.options) || [];
                select.innerHTML = '';
                if (!options.length) {
                    select.innerHTML = '<option value="">No destinations available</option>';
                    return;
                }
                options.forEach(function (opt) {
                    var o = document.createElement('option');
                    var depth = Number(opt.depth || 0);
                    var isRoot = Number(opt.folder_id) === 0;
                    o.value = opt.folder_id;
                    o.textContent = (isRoot ? '' : '   '.repeat(depth + 1)) + (isRoot ? 'Documents' : '📁 ' + opt.folder_name)
                        + (Number(opt.folder_id) === here ? '  (current location)' : '');
                    select.appendChild(o);
                });
                select.disabled = false;
                submit.disabled = false;
                select.focus();
            })
            .catch(function () {
                select.innerHTML = '<option value="">Could not load folders</option>';
            });
    }

    function openHistory(it) {
        document.getElementById('spHistoryName').textContent = it.name;
        var body = document.getElementById('spHistoryRows');
        body.innerHTML = '<tr><td colspan="6" class="cell-muted">Loading...</td></tr>';
        openModal('spHistoryModal');

        loadDetails(it).then(function (d) {
            var versions = (d && d.versions) || [];
            if (!versions.length) {
                body.innerHTML = '<tr><td colspan="6" class="cell-muted">No history yet.</td></tr>';
                return;
            }
            body.innerHTML = versions.map(function (v) {
                var dl = v.current
                    ? HANDLER + '?action=download&id=' + it.id
                    : HANDLER + '?action=download_version&version_id=' + v.version_id;
                var actions = '';
                if (it.canDownload) {
                    actions += '<a class="btn btn-sm btn-soft" href="' + esc(dl) + '"><i class="fas fa-download" aria-hidden="true"></i> Download</a> ';
                }
                if (!v.current && it.canEdit && !blockedByCheckout(it)) {
                    actions += '<button type="button" class="btn btn-sm btn-secondary" data-restore-version="' + Number(v.version_id) + '"><i class="fas fa-rotate-left" aria-hidden="true"></i> Restore</button>';
                }
                return '<tr>'
                    + '<td><strong>' + Number(v.version) + '.0</strong>' + (v.current ? ' <span class="badge badge-primary">Current</span>' : '') + '</td>'
                    + '<td title="' + esc(v.when) + '">' + esc(relativeWhen(v.when)) + '</td>'
                    + '<td>' + esc(v.by || '—') + '</td>'
                    + '<td>' + esc(v.size) + '</td>'
                    + '<td>' + esc(v.comment || '') + '</td>'
                    + '<td class="sp-history-actions">' + actions + '</td>'
                    + '</tr>';
            }).join('');
        }).catch(function () {
            body.innerHTML = '<tr><td colspan="6" class="cell-muted">Could not load the version history.</td></tr>';
        });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-restore-version]');
        if (!btn) return;
        var it = selectedItems()[0];
        if (!it) return;
        postAction('restore_version', { version_id: btn.dataset.restoreVersion, document_id: it.id });
    });

    function exportCsv() {
        var heads = $$('#spList thead th').slice(2).map(function (th) { return th.textContent.trim(); });
        var lines = [['Type'].concat(heads)];
        visibleRows().forEach(function (row) {
            var cells = $$('td', row).slice(2).map(function (td, i) {
                if (i === 0) return row.dataset.name;
                var time = $('time', td);
                return time ? time.getAttribute('datetime') : td.textContent.replace(/\s+/g, ' ').trim().replace(/^—$/, '');
            });
            lines.push([row.dataset.type === 'folder' ? 'Folder' : (row.dataset.kind || 'File')].concat(cells));
        });
        var csv = lines.map(function (line) {
            return line.map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(',');
        }).join('\r\n');
        var blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = (document.getElementById('spTitle').textContent.trim() || 'documents').replace(/[^\w\- ]+/g, '') + '.csv';
        document.body.appendChild(a);
        a.click();
        window.setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    }

    function run(cmd) {
        var items = selectedItems();
        var it = items[0];

        if (cmd !== 'details' && cmd !== 'clear-selection' && cmd !== 'export' && cmd !== 'empty-bin'
            && cmd.indexOf('new-folder') === -1 && cmd.indexOf('upload-') === -1 && !allowed(cmd, items)) {
            return;
        }

        switch (cmd) {
            case 'new-folder': openModal('createFolderModal'); break;
            case 'upload-files': openModal('uploadModal'); break;
            case 'upload-folder': openModal('uploadFolderModal'); break;

            case 'open':
                if (it.type === 'folder') window.location.href = it.url;
                else window.open(it.url, '_blank', 'noopener');
                break;

            case 'preview': window.open(HANDLER + '?action=view&id=' + it.id, '_blank', 'noopener'); break;
            case 'share': window.docOpenPermissions(it.type, it.id, it.name); break;

            case 'copy-link':
                copyText(absoluteLink(it))
                    .then(function () { toast('Link copied. People with access can open it.', 'fa-link'); })
                    .catch(function () { toast('Could not copy the link.', 'fa-triangle-exclamation'); });
                break;

            case 'download': downloadAll(items); break;

            case 'favorite':
                setFavorite(items, !items.every(function (x) { return x.favorite; }));
                break;

            case 'delete':
                var hasFolder = items.some(function (x) { return x.type === 'folder'; });
                confirmModal({
                    action: 'bulk_delete',
                    items: items,
                    title: items.length === 1 ? 'Delete?' : 'Delete ' + plural(items.length, 'item') + '?',
                    button: 'Delete',
                    message: CFG.libReady
                        ? 'Are you sure you want to send ' + describe(items) + ' to the recycle bin?'
                            + (hasFolder ? ' Folders go with everything inside them.' : '')
                            + ' You can restore ' + (items.length === 1 ? 'it' : 'them') + ' from the recycle bin.'
                        : 'This permanently deletes ' + describe(items) + (hasFolder ? ' and everything inside the folders' : '') + '. This cannot be undone.'
                });
                break;

            case 'rename':
                if (it.type === 'folder') window.docOpenEditFolder(it.edit);
                else window.docOpenEditFile(it.edit);
                break;

            case 'move': openMove(items); break;
            case 'versions': openHistory(it); break;

            case 'new-version':
                document.getElementById('spVersionDocId').value = it.id;
                document.getElementById('spVersionDocName').textContent = it.name;
                document.getElementById('spVersionCheckinWrap').hidden = !it.checkedOutMe;
                setReturn(document.querySelector('#spVersionModal form'));
                openModal('spVersionModal');
                break;

            case 'checkout':
            case 'checkin':
                postAction(cmd, { document_id: it.id });
                break;

            case 'details': toggleDetails(); break;

            case 'export': exportCsv(); break;

            case 'restore': postAction('restore', {}, items); break;

            case 'purge':
                confirmModal({
                    action: 'purge',
                    items: items,
                    title: 'Delete permanently?',
                    button: 'Delete permanently',
                    message: 'This permanently deletes ' + describe(items) + ', including every previous version. This cannot be undone.'
                });
                break;

            case 'empty-bin':
                confirmModal({
                    action: 'empty_bin',
                    items: [],
                    title: 'Empty the recycle bin?',
                    button: 'Empty recycle bin',
                    icon: 'fa-dumpster',
                    message: 'Everything in ' + (CFG.isAdmin ? 'the' : 'your') + ' recycle bin will be permanently deleted. This cannot be undone.'
                });
                break;

            case 'clear-selection': select([]); break;
        }
    }

    /* =====================================================================
     * Menus (command bar dropdowns + context menu)
     * ===================================================================== */

    var openMenu = null;
    var menuTrigger = null;

    function closeMenus(restoreFocus) {
        if (openMenu) {
            openMenu.classList.remove('is-open');
            if (menuTrigger && menuTrigger.hasAttribute('aria-haspopup')) menuTrigger.setAttribute('aria-expanded', 'false');
            if (restoreFocus && menuTrigger) menuTrigger.focus();
        }
        openMenu = null;
        menuTrigger = null;
    }

    function menuItems(menu) {
        return $$('[role^="menuitem"]', menu).filter(function (b) { return !b.hidden && !b.disabled; });
    }

    function showMenu(menu, trigger, x, y, viaKeyboard) {
        closeMenus();
        openMenu = menu;
        menuTrigger = trigger;
        menu.classList.add('is-open');
        if (trigger && trigger.hasAttribute('aria-haspopup')) trigger.setAttribute('aria-expanded', 'true');

        if (x !== undefined) {
            var rect = menu.getBoundingClientRect();
            var left = Math.min(x, window.innerWidth - rect.width - 8);
            var top = y + rect.height > window.innerHeight - 8 ? Math.max(8, y - rect.height) : y;
            menu.style.left = Math.max(8, left) + 'px';
            menu.style.top = Math.max(8, top) + 'px';
        }

        if (viaKeyboard) {
            var first = menuItems(menu)[0];
            if (first) first.focus();
        }
    }

    function showContextMenu(row, x, y, trigger, viaKeyboard) {
        if (selected.indexOf(row) === -1) {
            anchorRow = row;
            select([row]);
        }

        var menu = document.getElementById('spContextMenu');
        var items = selectedItems();
        var html = '';
        var lastSep = true;

        CONTEXT_CMDS.forEach(function (cmd) {
            if (cmd === '-') {
                if (!lastSep) { html += '<div class="sp-menu-sep" role="separator"></div>'; lastSep = true; }
                return;
            }
            if (!allowed(cmd, items)) return;
            var label = CMD[cmd][0];
            var icon = 'fas ' + CMD[cmd][1];
            if (cmd === 'favorite' && items.every(function (x) { return x.favorite; })) label = 'Remove from favorites';
            if (cmd === 'details') label = 'Details';
            html += '<button type="button" role="menuitem" data-cmd="' + cmd + '"><i class="' + icon + '" aria-hidden="true"></i>' + esc(label) + '</button>';
            lastSep = false;
        });

        menu.innerHTML = html.replace(/<div class="sp-menu-sep" role="separator"><\/div>$/, '');
        showMenu(menu, trigger || row, x, y, viaKeyboard);
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-menu]');

        if (trigger) {
            e.preventDefault();
            var menu = document.getElementById(trigger.dataset.menu);
            if (openMenu === menu) {
                closeMenus();
            } else {
                showMenu(menu, trigger, undefined, undefined, e.detail === 0);
            }
            return;
        }

        var cmdBtn = e.target.closest('[data-cmd]');

        if (cmdBtn && !cmdBtn.disabled) {
            closeMenus();
            run(cmdBtn.dataset.cmd);
            return;
        }

        if (openMenu && !e.target.closest('.sp-menu')) {
            closeMenus();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (!openMenu) return;
        var items = menuItems(openMenu);
        var idx = items.indexOf(document.activeElement);
        var next = null;

        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenus(true); return; }
        if (e.key === 'ArrowDown') next = items[(idx + 1) % items.length];
        if (e.key === 'ArrowUp') next = items[(idx - 1 + items.length) % items.length];
        if (e.key === 'Home') next = items[0];
        if (e.key === 'End') next = items[items.length - 1];
        if (e.key === 'Tab') closeMenus();

        if (next) { e.preventDefault(); next.focus(); }
    }, true);

    window.addEventListener('resize', function () { closeMenus(); });
    window.addEventListener('scroll', function () { if (openMenu && openMenu.id === 'spContextMenu') closeMenus(); }, true);

    /* =====================================================================
     * Row events
     * ===================================================================== */

    var tbody = document.getElementById('spRows');

    if (tbody) {
        tbody.addEventListener('click', function (e) {
            var row = e.target.closest('.sp-row');
            if (!row) return;

            if (e.target.closest('.sp-row-more')) {
                e.preventDefault();
                e.stopPropagation();
                var r = e.target.closest('.sp-row-more').getBoundingClientRect();
                showContextMenu(row, r.left, r.bottom + 4, e.target.closest('.sp-row-more'), e.detail === 0);
                return;
            }

            if (e.target.closest('.sp-check')) {
                if (e.target.matches('input')) {
                    toggleRow(row);
                }
                return;
            }

            if (e.target.closest('a')) {
                return; // name / location links navigate
            }

            if (e.shiftKey) {
                selectRange(row);
            } else if (e.ctrlKey || e.metaKey) {
                toggleRow(row);
            } else {
                anchorRow = row;
                select([row]);
            }

            focusRow(row);
        });

        tbody.addEventListener('dblclick', function (e) {
            var row = e.target.closest('.sp-row');
            if (!row || e.target.closest('a, button, .sp-check')) return;
            anchorRow = row;
            select([row]);
            run('open');
        });

        tbody.addEventListener('contextmenu', function (e) {
            var row = e.target.closest('.sp-row');
            if (!row) return;
            e.preventDefault();
            var keyboard = !e.clientX && !e.clientY;
            var rect = row.getBoundingClientRect();
            showContextMenu(row, keyboard ? rect.left + 40 : e.clientX, keyboard ? rect.bottom : e.clientY, row, keyboard);
        });

        tbody.addEventListener('keydown', function (e) {
            var row = e.target.closest('.sp-row');
            if (!row || e.target !== row) return;
            var list = visibleRows();
            var i = list.indexOf(row);

            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                var next = list[e.key === 'ArrowDown' ? Math.min(list.length - 1, i + 1) : Math.max(0, i - 1)];
                if (e.shiftKey) {
                    selectRange(next);
                } else if (!(e.ctrlKey || e.metaKey)) {
                    anchorRow = next;
                    select([next]);
                }
                focusRow(next);
            } else if (e.key === 'Home' || e.key === 'End') {
                e.preventDefault();
                focusRow(list[e.key === 'Home' ? 0 : list.length - 1]);
            } else if (e.key === ' ') {
                e.preventDefault();
                toggleRow(row);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (selected.indexOf(row) === -1) select([row]);
                run(CFG.view === 'bin' ? 'details' : 'open');
            } else if (e.key === 'Delete') {
                e.preventDefault();
                if (selected.indexOf(row) === -1) select([row]);
                run(CFG.view === 'bin' ? 'purge' : 'delete');
            } else if (e.key === 'F2') {
                e.preventDefault();
                select([row]);
                run('rename');
            } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') {
                e.preventDefault();
                select(visibleRows());
            } else if (e.key === 'Escape' && !openMenu) {
                select([]);
            } else if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) {
                e.preventDefault();
                var rect = row.getBoundingClientRect();
                showContextMenu(row, rect.left + 40, rect.bottom, row, true);
            }
        });

        // Roving tabindex: Tab lands on the first (or selected) row.
        var firstRow = allRows()[0];
        if (firstRow) firstRow.tabIndex = 0;
    }

    var selectAll = document.getElementById('spSelectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            select(selectAll.checked ? visibleRows() : []);
        });
    }

    /* =====================================================================
     * Sorting
     * ===================================================================== */

    var sortState = { key: null, dir: 1 };

    $$('.sp-sort').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var key = btn.dataset.sort;
            sortState.dir = sortState.key === key ? -sortState.dir : (key === 'modified' || key === 'size' ? -1 : 1);
            sortState.key = key;

            var numeric = key === 'modified' || key === 'size';
            var list = allRows();

            list.sort(function (a, b) {
                // Folders stay above files, as in SharePoint.
                if (a.dataset.type !== b.dataset.type) return a.dataset.type === 'folder' ? -1 : 1;
                var av = a.dataset['sort' + key.charAt(0).toUpperCase() + key.slice(1)] || '';
                var bv = b.dataset['sort' + key.charAt(0).toUpperCase() + key.slice(1)] || '';
                var cmp = numeric ? (Number(av) - Number(bv)) : av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' });
                return cmp * sortState.dir;
            });

            list.forEach(function (row) { tbody.appendChild(row); });

            $$('#spList thead th[aria-sort]').forEach(function (th) {
                var mine = th.contains(btn);
                th.setAttribute('aria-sort', mine ? (sortState.dir === 1 ? 'ascending' : 'descending') : 'none');
                var icon = $('.sp-sort i', th);
                if (icon) icon.className = 'fas ' + (mine ? (sortState.dir === 1 ? 'fa-arrow-up' : 'fa-arrow-down') : 'fa-sort');
            });
        });
    });

    /* =====================================================================
     * Filters: file type chips + keyword (non-library views)
     * ===================================================================== */

    var kindFilter = 'all';
    var keyword = '';
    var NAMED_KINDS = ['folder', 'word', 'excel', 'ppt', 'pdf', 'image', 'video'];

    function applyFilters() {
        var shown = 0;
        allRows().forEach(function (row) {
            var kind = row.dataset.kind;
            var kindOk = kindFilter === 'all'
                || (kindFilter === 'other' ? NAMED_KINDS.indexOf(kind) === -1 : kind === kindFilter);
            var wordOk = !keyword || (row.dataset.sortName || '').indexOf(keyword) !== -1
                || (row.dataset.sortExtra || '').indexOf(keyword) !== -1;
            row.hidden = !(kindOk && wordOk);
            if (!row.hidden) shown++;
        });

        selected = selected.filter(function (row) {
            if (row.hidden) setRowSelected(row, false);
            return !row.hidden;
        });

        var none = document.getElementById('spNoMatch');
        if (none) none.hidden = shown > 0;
        selectionChanged();
    }

    $$('[data-kind-filter]').forEach(function (chip) {
        chip.addEventListener('click', function () {
            kindFilter = chip.dataset.kindFilter;
            $$('[data-kind-filter]').forEach(function (c) {
                var on = c === chip;
                c.classList.toggle('is-on', on);
                c.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            applyFilters();
        });
    });

    var filterToggle = document.getElementById('spFilterToggle');
    var filters = document.getElementById('spFilters');

    if (filterToggle && filters) {
        var params = new URLSearchParams(window.location.search);
        var startOpen = params.get('project') || params.get('program');

        var setFilters = function (open) {
            filters.hidden = !open;
            filterToggle.setAttribute('aria-pressed', open ? 'true' : 'false');
            filterToggle.classList.toggle('is-on', open);
        };

        setFilters(!!startOpen);
        filterToggle.addEventListener('click', function () { setFilters(filters.hidden); });
    }

    var searchForm = document.getElementById('spSearchForm');
    var searchInput = document.getElementById('spSearchInput');

    if (searchForm && searchInput && CFG.view !== 'library') {
        searchForm.addEventListener('submit', function (e) { e.preventDefault(); });
        searchInput.addEventListener('input', function () {
            keyword = searchInput.value.trim().toLowerCase();
            applyFilters();
        });
    }

    var scopeSelect = document.getElementById('spSearchScope');
    if (scopeSelect && searchInput) {
        scopeSelect.addEventListener('change', function () {
            if (searchInput.value.trim() !== '') searchForm.submit();
        });
    }

    /* =====================================================================
     * Layout: list / compact / tiles
     * ===================================================================== */

    var LAYOUTS = { list: ['List', 'fa-list'], compact: ['Compact list', 'fa-bars'], tiles: ['Tiles', 'fa-table-cells-large'] };

    function applyLayout(layout) {
        if (!LAYOUTS[layout]) layout = 'list';
        app.classList.remove('layout-list', 'layout-compact', 'layout-tiles');
        app.classList.add('layout-' + layout);
        document.getElementById('spViewLabel').textContent = LAYOUTS[layout][0];
        document.getElementById('spViewIcon').className = 'fas ' + LAYOUTS[layout][1];
        $$('[data-layout]').forEach(function (b) {
            b.setAttribute('aria-checked', b.dataset.layout === layout ? 'true' : 'false');
        });
    }

    applyLayout(store('ims.documents.layout') || 'list');

    $$('[data-layout]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            applyLayout(btn.dataset.layout);
            store('ims.documents.layout', btn.dataset.layout);
            closeMenus();
        });
    });

    // Image thumbnails (list icon + tiles); drop any that fail to load.
    $$('img.sp-thumb[data-src]').forEach(function (img) {
        img.addEventListener('load', function () { img.closest('.sp-ft').classList.add('has-thumb'); });
        img.addEventListener('error', function () { img.remove(); });
        img.src = img.dataset.src;
    });

    // Relative dates in the Modified column.
    $$('time.sp-when').forEach(function (t) {
        var label = relativeWhen(new Date(t.getAttribute('datetime')));
        if (label) t.textContent = label;
    });

    /* =====================================================================
     * Details pane
     * ===================================================================== */

    var pane = document.getElementById('spDetails');
    var paneBody = document.getElementById('spDetailsBody');
    var summaryHtml = paneBody ? paneBody.innerHTML : '';
    var paneTitle = document.getElementById('spDetailsTitle');
    var paneIcon = document.getElementById('spDetailsIcon');
    var defaultTitle = paneTitle ? paneTitle.textContent : '';
    var defaultIcon = paneIcon ? paneIcon.innerHTML : '';
    var detailsCache = {};
    var detailsSeq = 0;

    function paneOpen() { return pane && !pane.hidden; }

    function toggleDetails(force) {
        if (!pane) return;
        var open = force === undefined ? pane.hidden : force;
        pane.hidden = !open;
        app.classList.toggle('details-open', open);
        var t = document.getElementById('spInfoToggle');
        if (t) {
            t.setAttribute('aria-pressed', open ? 'true' : 'false');
            t.classList.toggle('is-on', open);
        }
        store('ims.documents.details', open ? '1' : '0');
        if (open) renderDetails();
    }

    function loadDetails(it) {
        var key = it.type + ':' + it.id;
        if (detailsCache[key]) return Promise.resolve(detailsCache[key]);

        return fetch(HANDLER + '?action=get_details&type=' + it.type + '&id=' + it.id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) throw new Error(data.error || 'failed');
                detailsCache[key] = data.item;
                return data.item;
            });
    }

    function section(title, inner, action) {
        return '<section class="sp-pane-section"><div class="sp-pane-section-head"><h4>' + esc(title) + '</h4>' + (action || '') + '</div>' + inner + '</section>';
    }

    function renderDetails() {
        if (!paneOpen()) return;

        var items = selectedItems();
        var seq = ++detailsSeq;

        paneIcon.className = 'sp-details-icon';

        if (items.length === 0) {
            paneTitle.textContent = defaultTitle;
            paneIcon.innerHTML = defaultIcon;
            paneBody.innerHTML = summaryHtml;
            return;
        }

        if (items.length > 1) {
            paneTitle.textContent = plural(items.length, 'item') + ' selected';
            paneIcon.innerHTML = '<i class="fas fa-layer-group"></i>';
            paneBody.innerHTML = '<ul class="sp-pane-multi">' + items.map(function (x) {
                return '<li><i class="fas ' + (x.type === 'folder' ? 'fa-folder' : 'fa-file') + '" aria-hidden="true"></i> ' + esc(x.name) + '</li>';
            }).join('') + '</ul>';
            return;
        }

        var it = items[0];
        paneTitle.textContent = it.name;
        paneIcon.innerHTML = $('.sp-ft', it.row) ? '<i class="fas ' + ($('.sp-ft > i', it.row) || { className: 'fa-file' }).className.replace(/^fas\s*/, '') + '"></i>' : '';
        paneIcon.className = 'sp-details-icon sp-ft-' + it.kind;
        paneBody.innerHTML = '<div class="sp-pane-loading"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Loading details...</div>';

        loadDetails(it).then(function (d) {
            if (seq !== detailsSeq) return;

            var html = '';

            html += '<div class="sp-details-preview sp-ft-' + esc(d.kind) + '">'
                + (d.preview ? '<img src="' + esc(d.preview) + '" alt="Preview of ' + esc(d.name) + '">' : '<i class="fas ' + esc(d.icon) + '" aria-hidden="true"></i>')
                + '</div>';

            var quick = [];
            ['open', 'share', 'copy-link', 'favorite'].forEach(function (cmd) {
                if (!allowed(cmd, [it])) return;
                var label = cmd === 'favorite' ? (it.favorite ? 'Unfavorite' : 'Favorite') : CMD[cmd][0];
                var icon = cmd === 'favorite' ? (it.favorite ? 'fas fa-star' : 'far fa-star') : 'fas ' + CMD[cmd][1];
                quick.push('<button type="button" class="sp-quick" data-cmd="' + cmd + '"><i class="' + icon + '" aria-hidden="true"></i><span>' + esc(label) + '</span></button>');
            });
            if (quick.length) html += '<div class="sp-quick-row">' + quick.join('') + '</div>';

            // Has access
            var access = d.access || [];
            var accessHtml = access.length
                ? '<ul class="sp-access">' + access.map(function (p) {
                    return '<li><span class="sp-avatar" aria-hidden="true">' + esc(p.initials || initials(p.name)) + '</span>'
                        + '<span class="sp-access-text"><strong>' + esc(p.name) + '</strong><small>' + esc(p.access)
                        + (p.inherited ? ' &middot; inherited from ' + esc(p.inherited) : '') + '</small></span></li>';
                }).join('') + '</ul>'
                : '<p class="sp-muted">Only the owner and administrators.</p>';
            html += section('Has access', accessHtml, allowed('share', [it]) ? '<button type="button" class="sp-link" data-cmd="share">Manage access</button>' : '');

            // Properties
            var props = d.properties || {};
            var dates = d.dates || [];
            var propHtml = '<dl class="sp-props">' + Object.keys(props).map(function (k) {
                var v = props[k];
                if (dates.indexOf(k) !== -1) {
                    var dt = parseWhen(v);
                    v = dt ? dt.toLocaleString([], { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : v;
                }
                return '<div><dt>' + esc(k) + '</dt><dd>' + esc(v) + '</dd></div>';
            }).join('') + '</dl>';
            html += section('Properties', propHtml, allowed('rename', [it]) ? '<button type="button" class="sp-link" data-cmd="rename">Edit all</button>' : '');

            // Versions
            if (d.type === 'document' && (d.versions || []).length) {
                var count = d.versions.length;
                html += section('Version history',
                    '<p class="sp-muted">Current version ' + Number(d.versions[0].version) + '.0 &middot; ' + plural(count, 'version') + ' kept.</p>',
                    allowed('versions', [it]) ? '<button type="button" class="sp-link" data-cmd="versions">View all</button>' : '');
            }

            // Activity
            var activity = d.activity || [];
            var actHtml = activity.length
                ? '<ul class="sp-activity">' + activity.map(function (a) {
                    return '<li><span class="sp-avatar" aria-hidden="true">' + esc(a.initials || initials(a.user)) + '</span>'
                        + '<span><strong>' + esc(a.user) + '</strong> ' + esc(a.action) + ' this'
                        + (a.details ? ' <span class="sp-muted">(' + esc(a.details) + ')</span>' : '')
                        + '<small title="' + esc(a.when) + '">' + esc(relativeWhen(a.when)) + '</small></span></li>';
                }).join('') + '</ul>'
                : '<p class="sp-muted">No activity recorded yet.</p>';
            html += section('Activity', actHtml);

            paneBody.innerHTML = html;
        }).catch(function () {
            if (seq !== detailsSeq) return;
            paneBody.innerHTML = '<p class="sp-muted">Could not load details for this item.</p>';
        });
    }

    if (pane && (store('ims.documents.details') === '1') && window.matchMedia('(min-width: 1100px)').matches) {
        toggleDetails(true);
    }

    /* =====================================================================
     * Navigation drawer (small screens) + follow folder
     * ===================================================================== */

    $$('[data-sp-nav-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            app.classList.toggle('nav-open');
        });
    });

    var follow = document.getElementById('spFollowFolder');
    if (follow) {
        follow.addEventListener('click', function () {
            var on = follow.getAttribute('aria-pressed') !== 'true';
            var parts = follow.dataset.item.split(':');
            setFavorite([{ type: parts[0], id: Number(parts[1]), row: null }], on).then(function () {
                follow.setAttribute('aria-pressed', on ? 'true' : 'false');
                follow.classList.toggle('is-on', on);
                $('i', follow).className = (on ? 'fas' : 'far') + ' fa-star';
            });
        });
    }

    /* =====================================================================
     * Drag and drop: upload from the desktop, move within the library
     * ===================================================================== */

    var MOVE_MIME = 'application/x-ims-doc-items';
    var overlay = document.getElementById('docDropOverlay');
    var dragDepth = 0;

    function hasType(e, type) {
        return Array.prototype.indexOf.call((e.dataTransfer && e.dataTransfer.types) || [], type) !== -1;
    }

    window.addEventListener('dragover', function (e) { if (hasType(e, 'Files')) e.preventDefault(); });

    window.addEventListener('dragenter', function (e) {
        if (!hasType(e, 'Files')) return;
        e.preventDefault();
        dragDepth++;
        if (CFG.canCreate && overlay) overlay.classList.add('is-on');
    });

    window.addEventListener('dragleave', function () {
        dragDepth = Math.max(0, dragDepth - 1);
        if (dragDepth === 0 && overlay) overlay.classList.remove('is-on');
    });

    window.addEventListener('drop', function (e) {
        if (!hasType(e, 'Files')) return;
        e.preventDefault();
        dragDepth = 0;
        if (overlay) overlay.classList.remove('is-on');

        if (!CFG.canCreate) {
            toast(CFG.view === 'library' ? 'You do not have permission to upload here.' : 'Open a folder in Documents to upload files.', 'fa-triangle-exclamation');
            return;
        }

        collectDropped(e.dataTransfer).then(uploadDropped).catch(function () {
            toast('Could not read the dropped files.', 'fa-triangle-exclamation');
        });
    });

    function collectDropped(dt) {
        var entries = [];
        var list = (dt && dt.items) || [];

        for (var i = 0; i < list.length; i++) {
            if (list[i].kind !== 'file') continue;
            var entry = list[i].webkitGetAsEntry ? list[i].webkitGetAsEntry() : null;
            if (entry) {
                entries.push(entry);
            } else {
                var f = list[i].getAsFile();
                if (f) entries.push({ isFile: true, name: f.name, __file: f });
            }
        }

        var collected = [];
        var state = { dirs: false };

        function read(entry, prefix) {
            if (entry.__file) {
                collected.push({ file: entry.__file, path: prefix + entry.name });
                return Promise.resolve();
            }
            if (entry.isFile) {
                return new Promise(function (resolve, reject) { entry.file(resolve, reject); }).then(function (file) {
                    collected.push({ file: file, path: prefix + entry.name });
                });
            }
            if (entry.isDirectory) {
                state.dirs = true;
                var reader = entry.createReader();
                var readBatch = function () {
                    return new Promise(function (resolve, reject) { reader.readEntries(resolve, reject); }).then(function (batch) {
                        if (!batch.length) return null;
                        return batch.reduce(function (p, child) {
                            return p.then(function () { return read(child, prefix + entry.name + '/'); });
                        }, Promise.resolve()).then(readBatch);
                    });
                };
                return readBatch();
            }
            return Promise.resolve();
        }

        return entries.reduce(function (p, entry) {
            return p.then(function () { return read(entry, ''); });
        }, Promise.resolve()).then(function () {
            return { files: collected, hasDirectory: state.dirs };
        });
    }

    function uploadDropped(result) {
        if (!result.files.length) return;
        var fd = new FormData();

        if (result.hasDirectory) {
            if (!CFG.canUploadFolder) {
                toast('Open a folder first to upload a folder into it.', 'fa-triangle-exclamation');
                return;
            }
            fd.append('action', 'upload_folder');
            fd.append('folder_id', String(CFG.folderId || 0));
            result.files.forEach(function (x) { fd.append('folder_documents[]', x.file, x.path); });
        } else {
            fd.append('action', 'upload');
            fd.append('folder_id', String(CFG.folderId || 0));
            fd.append('document_type', 'Inherited');
            ['project_id', 'program_id', 'description', 'tags'].forEach(function (k) { fd.append(k, ''); });
            result.files.forEach(function (x) { fd.append('documents[]', x.file, x.file.name); });
        }

        submitFormData(fd, 'Uploading ' + plural(result.files.length, 'file') + '...');
    }

    // Drag rows (the whole selection when the dragged row is selected).
    allRows().forEach(function (row) {
        row.addEventListener('dragstart', function (e) {
            if (row.getAttribute('draggable') !== 'true') { e.preventDefault(); return; }
            if (selected.indexOf(row) === -1) { anchorRow = row; select([row]); }
            var items = selectedItems().filter(function (x) { return x.canEdit && !blockedByCheckout(x); });
            if (!items.length) { e.preventDefault(); return; }
            e.dataTransfer.setData(MOVE_MIME, JSON.stringify(items.map(function (x) { return x.type + ':' + x.id; })));
            e.dataTransfer.setData('text/plain', items.map(function (x) { return x.name; }).join(', '));
            e.dataTransfer.effectAllowed = 'move';
            selected.forEach(function (r) { r.classList.add('is-dragging'); });
        });

        row.addEventListener('dragend', function () {
            allRows().forEach(function (r) { r.classList.remove('is-dragging'); });
        });
    });

    $$('[data-drop-folder]').forEach(function (target) {
        var folderId = Number(target.dataset.dropFolder || 0);

        target.addEventListener('dragover', function (e) {
            if (!hasType(e, MOVE_MIME)) return;
            if (target.classList.contains('is-dragging')) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            target.classList.add('is-drop-target');
        });

        target.addEventListener('dragleave', function () { target.classList.remove('is-drop-target'); });

        target.addEventListener('drop', function (e) {
            if (!hasType(e, MOVE_MIME)) return;
            e.preventDefault();
            e.stopPropagation();
            target.classList.remove('is-drop-target');

            var tokens = [];
            try { tokens = JSON.parse(e.dataTransfer.getData(MOVE_MIME)) || []; } catch (err) { tokens = []; }
            tokens = tokens.filter(function (t) { return t !== 'folder:' + folderId; });
            if (!tokens.length) return;

            var fd = new FormData();
            fd.append('action', 'bulk_move');
            fd.append('target_folder_id', String(folderId));
            tokens.forEach(function (t) { fd.append('items[]', t); });
            submitFormData(fd, 'Moving ' + plural(tokens.length, 'item') + '...');
        });
    });

    /* =====================================================================
     * Start
     * ===================================================================== */

    // Make sure every form posting back to the library knows where to return.
    $$('#spConfirmForm, #spMoveForm, #spVersionModal form, #spActionForm').forEach(setReturn);

    selectionChanged();

    if (CFG.autoOpenUpload) {
        window.setTimeout(function () { openModal('uploadModal'); }, 50);
    }
})();
