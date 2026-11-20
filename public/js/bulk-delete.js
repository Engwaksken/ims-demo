/*
 * Multiple selection + "Delete selected" for data tables (loaded from
 * includes/footer.php, after hr-ui.js which provides the popups).
 *
 * A page opts a table in and reuses its OWN single-row delete action, so
 * each page's permission checks, cascades and soft-delete rules still apply:
 *
 *   <table data-bulk-delete="programs-process"            (URL of the existing delete handler)
 *          data-bulk-delete-fields='{"action":"delete","program_id":"{id}"}'
 *          data-bulk-delete-method="POST"                   (default POST; GET builds a query string)
 *          data-bulk-delete-label="program|programs">       (singular|plural, for messages)
 *     ...
 *     <tr data-row-id="12">   <- only rows this user may delete (the page renders it
 *                                where it renders the row's own Delete button)
 *
 * Each selected row is deleted with one request to that handler, one after
 * another. The page then reloads and reports how many rows really went
 * (rows still present after the reload were refused by the server).
 * "Select all" picks the rows on screen (current page and filter).
 */
(function () {
    'use strict';

    var STORE = 'ims.bulkDelete';
    var main = document.querySelector('.main-content') || document.body;
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function labels(table) {
        var l = (table.getAttribute('data-bulk-delete-label') || 'record|records').split('|');
        return { one: l[0], many: l[1] || l[0] + 's' };
    }
    function plural(n, l) { return n + ' ' + (n === 1 ? l.one : l.many); }

    function rowVisible(row) {
        return !(row.classList.contains('pg-hidden') || row.classList.contains('stat-filter-hidden') || row.hidden || row.style.display === 'none');
    }

    function popupConfirm(msg, opts) {
        return window.hrConfirm ? window.hrConfirm(msg, opts) : Promise.resolve(window.confirm(msg));
    }
    function popupAlert(msg, opts) {
        return window.hrAlert ? window.hrAlert(msg, opts) : Promise.resolve(window.alert(msg));
    }

    function setup(table) {
        if (table.dataset.bulkReady) return;
        table.dataset.bulkReady = '1';
        var url = table.getAttribute('data-bulk-delete');
        var method = (table.getAttribute('data-bulk-delete-method') || 'POST').toUpperCase();
        var fields = {};
        try { fields = JSON.parse(table.getAttribute('data-bulk-delete-fields') || '{}'); } catch (e) { fields = {}; }
        var l = labels(table);
        // This table's own rows only (not rows of tables nested inside a cell).
        var rows = [];
        Array.prototype.forEach.call(table.tBodies, function (tb) { rows = rows.concat(Array.prototype.slice.call(tb.rows)); });
        var deletable = rows.filter(function (r) { return r.hasAttribute('data-row-id'); });
        if (!url || !deletable.length) return;

        // Checkbox column (header + every body row, so columns stay aligned).
        var headRow = table.tHead && table.tHead.rows[0];
        if (headRow) {
            var th = document.createElement('th');
            th.className = 'bd-col';
            th.innerHTML = '<input type="checkbox" class="bd-all" aria-label="Select all ' + l.many + ' on this page">';
            headRow.insertBefore(th, headRow.firstElementChild);
        }
        rows.forEach(function (r) {
            var td = document.createElement('td');
            td.className = 'bd-col';
            td.setAttribute('data-label', 'Select');
            var single = r.cells.length === 1 && r.cells[0].colSpan > 1;
            if (single) { r.cells[0].colSpan += 1; return; }
            if (r.hasAttribute('data-row-id')) {
                td.innerHTML = '<input type="checkbox" class="bd-sel" value="' + String(r.getAttribute('data-row-id')).replace(/"/g, '') + '" aria-label="Select this ' + l.one + '">';
            }
            r.insertBefore(td, r.firstElementChild);
        });
        Array.prototype.forEach.call(table.tFoot ? table.tFoot.rows : [], function (r) {
            if (r.firstElementChild) r.firstElementChild.colSpan = (r.firstElementChild.colSpan || 1) + 1;
        });

        // Bulk bar under the table.
        var bar = document.createElement('div');
        bar.className = 'bd-bar';
        bar.hidden = true;
        bar.innerHTML = '<span class="bd-count"></span>'
            + '<button type="button" class="btn btn-sm btn-secondary bd-clear">Clear selection</button>'
            + '<button type="button" class="btn btn-sm btn-danger bd-go"><i class="fas fa-trash" aria-hidden="true"></i> Delete selected</button>';
        var anchor = table.closest('.table-responsive, .table-wrap, [class*="table-wrap"]') || table;
        anchor.parentNode.insertBefore(bar, anchor.nextSibling);

        var boxes = function () { return Array.prototype.slice.call(table.querySelectorAll('input.bd-sel')); };
        var picked = function () { return boxes().filter(function (b) { return b.checked; }); };
        var last = null;

        function refresh() {
            var all = boxes().filter(function (b) { return rowVisible(b.closest('tr')); });
            var sel = picked();
            boxes().forEach(function (b) { b.closest('tr').classList.toggle('is-selected', b.checked); });
            var head = table.querySelector('.bd-all');
            if (head) {
                var onScreen = all.filter(function (b) { return b.checked; }).length;
                head.checked = all.length > 0 && onScreen === all.length;
                head.indeterminate = onScreen > 0 && onScreen < all.length;
            }
            bar.hidden = sel.length === 0;
            bar.querySelector('.bd-count').textContent = plural(sel.length, l) + ' selected';
        }

        table.addEventListener('click', function (e) {
            var b = e.target.closest('input.bd-sel');
            if (!b) return;
            var list = boxes();
            if (e.shiftKey && last && last !== b) {
                var a = list.indexOf(last), z = list.indexOf(b);
                list.slice(Math.min(a, z), Math.max(a, z) + 1).forEach(function (x) { if (rowVisible(x.closest('tr'))) x.checked = b.checked; });
            }
            last = b;
        });
        table.addEventListener('change', function (e) {
            if (e.target.matches('.bd-all')) {
                boxes().forEach(function (b) { if (rowVisible(b.closest('tr'))) b.checked = e.target.checked; });
            }
            if (e.target.matches('.bd-all, .bd-sel')) refresh();
        });
        bar.querySelector('.bd-clear').addEventListener('click', function () {
            boxes().forEach(function (b) { b.checked = false; });
            refresh();
        });

        bar.querySelector('.bd-go').addEventListener('click', function () {
            var ids = picked().map(function (b) { return b.value; });
            if (!ids.length) return;
            popupConfirm('Delete ' + plural(ids.length, l) + '? This cannot be undone.', {
                title: 'Delete ' + (ids.length === 1 ? l.one : l.many), okText: 'Delete ' + ids.length, danger: true,
            }).then(function (yes) {
                if (yes) run(ids);
            });
        });

        function request(id) {
            var data = {};
            Object.keys(fields).forEach(function (k) { data[k] = String(fields[k]).replace(/\{id\}/g, id); });
            // Rows whose delete needs more than one value carry them in data-row-fields='{"name":"value"}'.
            var row = table.querySelector('tr[data-row-id="' + String(id).replace(/"/g, '') + '"]');
            if (row && row.hasAttribute('data-row-fields')) {
                try {
                    var extra = JSON.parse(row.getAttribute('data-row-fields'));
                    Object.keys(extra).forEach(function (k) { data[k] = String(extra[k]); });
                } catch (e) { /* ignore malformed */ }
            }
            if (method === 'GET') {
                var u = new URL(url, window.location.href);
                Object.keys(data).forEach(function (k) { u.searchParams.set(k, data[k]); });
                if (token) u.searchParams.set('csrf_token', token);
                return fetch(u.toString(), { credentials: 'same-origin' });
            }
            var body = new FormData();
            Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
            if (token && !body.has('csrf_token')) body.append('csrf_token', token);
            return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' });
        }

        function run(ids) {
            var go = bar.querySelector('.bd-go');
            go.disabled = true;
            bar.querySelector('.bd-clear').disabled = true;
            var done = 0, failed = 0;
            var next = function (i) {
                if (i >= ids.length) {
                    try {
                        sessionStorage.setItem(STORE, JSON.stringify({ path: location.pathname + location.search, ids: ids, one: l.one, many: l.many, failed: failed }));
                    } catch (e) { /* ignore */ }
                    window.location.reload();
                    return;
                }
                go.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Deleting ' + (i + 1) + ' of ' + ids.length + '...';
                request(ids[i]).then(function (res) { if (res.ok) done++; else failed++; })
                    .catch(function () { failed++; })
                    .then(function () { next(i + 1); });
            };
            next(0);
        }

        refresh();
    }

    // After the reload: rows still listed were not deleted (refused, in use, or no permission).
    function report() {
        var raw = null;
        try { raw = sessionStorage.getItem(STORE); sessionStorage.removeItem(STORE); } catch (e) { return; }
        if (!raw) return;
        var info;
        try { info = JSON.parse(raw); } catch (e) { return; }
        if (!info || info.path !== location.pathname + location.search) return;
        var present = {};
        Array.prototype.forEach.call(document.querySelectorAll('[data-bulk-delete] tr[data-row-id]'), function (r) { present[r.getAttribute('data-row-id')] = true; });
        var kept = info.ids.filter(function (id) { return present[id]; }).length;
        var gone = info.ids.length - kept;
        var l = { one: info.one, many: info.many };
        var msg = plural(gone, l) + ' deleted.';
        if (kept) msg += '\n' + plural(kept, l) + ' could not be deleted: still in use, not permitted, or refused. Open one to see why.';
        popupAlert(msg, { title: kept ? 'Some ' + l.many + ' were not deleted' : 'Deleted' });
    }

    function init() {
        Array.prototype.forEach.call(main.querySelectorAll('table[data-bulk-delete]'), setup);
        report();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
