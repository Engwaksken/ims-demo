/*
 * Pagination for every data table (loaded from includes/footer.php).
 *
 * Any <table> in the page content with more rows than one page gets a pager
 * under it: "Showing 1–20 of 135", previous / next, page numbers and a
 * rows-per-page choice (remembered per browser). Rows hidden by the page's
 * own filters, search boxes or clickable stat cards are left out of the
 * count, and the pager follows those filters as they change.
 *
 * Skipped: tables in modals/dialogs, tables that already have server-side
 * pagination near them (anything with "pagination" in its class), and
 * tables marked data-no-paginate (e.g. grouped finance reports).
 * Optional: data-page-size="50" on a table sets its default page size.
 */
(function () {
    'use strict';

    var SIZES = [10, 20, 50, 100];
    var DEFAULT_SIZE = 20;
    var STORE_KEY = 'ims.tablePageSize';
    var main = document.querySelector('.main-content') || document.body;

    function storedSize() {
        try {
            var v = parseInt(window.localStorage.getItem(STORE_KEY), 10);
            return SIZES.indexOf(v) > -1 || v === 0 ? v : null;
        } catch (e) { return null; }
    }
    function storeSize(v) {
        try { window.localStorage.setItem(STORE_KEY, String(v)); } catch (e) { /* private mode */ }
    }

    function eligible(table) {
        if (table.dataset.pager || table.hasAttribute('data-no-paginate')) return false;
        if (table.closest('.modal, dialog, .jm-overlay, [data-no-paginate], .stats-grid')) return false;
        if (!table.tBodies.length) return false;
        // Server-side pagination already present next to this table?
        var box = table.closest('.card, section, .panel, .fr-card, .mpb-card') || table.parentElement;
        for (var i = 0; i < 2 && box; i++) {
            if (box.querySelector('[class*="pagination"], [class*="pager"]:not(.tbl-pager)')) return false;
            box = box.parentElement && box.parentElement !== main ? box.parentElement : null;
        }
        return true;
    }

    function dataRows(table) {
        var rows = [];
        Array.prototype.forEach.call(table.tBodies, function (tb) {
            Array.prototype.forEach.call(tb.rows, function (r) {
                // "No records" placeholder rows (single colspan cell) are not data.
                if (r.cells.length === 1 && r.cells[0].colSpan > 1) return;
                rows.push(r);
            });
        });
        return rows;
    }

    function filteredOut(row) {
        return row.classList.contains('stat-filter-hidden') || row.hidden || row.style.display === 'none';
    }

    function Pager(table) {
        this.table = table;
        this.page = 1;
        var own = parseInt(table.dataset.pageSize, 10);
        var stored = storedSize();
        this.size = stored !== null ? stored : (own > 0 ? own : DEFAULT_SIZE);
        this.applying = false;
        table.dataset.pager = '1';

        var anchor = table.closest('.table-responsive, .table-wrap, [class*="table-wrap"]') || table;
        this.el = document.createElement('nav');
        this.el.className = 'tbl-pager';
        this.el.setAttribute('aria-label', 'Table pages');
        anchor.parentNode.insertBefore(this.el, anchor.nextSibling);

        var self = this;
        this.el.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-page]');
            if (!b || b.disabled) return;
            self.go(parseInt(b.dataset.page, 10), true);
        });
        this.el.addEventListener('change', function (e) {
            if (!e.target.matches('select')) return;
            self.size = parseInt(e.target.value, 10);
            storeSize(self.size);
            self.page = 1;
            self.render();
        });

        // Follow the page's own filters (row style/class/hidden changes, rows added or removed).
        this.observer = new MutationObserver(function (records) {
            if (self.applying) return;
            // Only changes that can show/hide rows matter (not e.g. a row's "selected" highlight).
            var relevant = records.some(function (r) {
                if (r.type === 'childList') return true;
                if (r.attributeName !== 'class') return r.target.tagName === 'TR';
                var was = / stat-filter-hidden /.test(' ' + (r.oldValue || '') + ' ');
                return was !== r.target.classList.contains('stat-filter-hidden');
            });
            if (relevant) { self.page = 1; self.schedule(); }
        });
        Array.prototype.forEach.call(table.tBodies, function (tb) {
            self.observer.observe(tb, { childList: true, subtree: true, attributes: true, attributeOldValue: true, attributeFilter: ['class', 'style', 'hidden'] });
        });
        this.render();
    }

    Pager.prototype.schedule = function () {
        var self = this;
        if (this.timer) return;
        this.timer = setTimeout(function () { self.timer = null; self.render(); }, 30);
    };

    Pager.prototype.go = function (page, scroll) {
        this.page = page;
        this.render();
        if (scroll) {
            var top = this.table.getBoundingClientRect().top;
            if (top < 0) this.table.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };

    Pager.prototype.render = function () {
        var rows = dataRows(this.table);
        var visible = rows.filter(function (r) { return !filteredOut(r); });
        var size = this.size > 0 ? this.size : visible.length || 1;
        var pages = Math.max(1, Math.ceil(visible.length / size));
        if (this.page > pages) this.page = pages;
        var from = (this.page - 1) * size;
        var to = Math.min(visible.length, from + size);

        this.applying = true;
        rows.forEach(function (r) { r.classList.remove('pg-hidden'); });
        visible.forEach(function (r, i) { if (i < from || i >= to) r.classList.add('pg-hidden'); });
        this.observer.takeRecords();
        this.applying = false;

        // No pager needed when everything fits on the smallest page.
        var needed = visible.length > SIZES[0];
        this.el.hidden = !needed;
        if (!needed) { this.el.innerHTML = ''; return; }

        var html = '<span class="tbl-pager-info" aria-live="polite">Showing <strong>' + (visible.length ? from + 1 : 0) + '&ndash;' + to + '</strong> of <strong>' + visible.length + '</strong></span>';
        html += '<span class="tbl-pager-pages">';
        html += '<button type="button" class="tbl-pager-btn" data-page="' + (this.page - 1) + '"' + (this.page <= 1 ? ' disabled' : '') + ' aria-label="Previous page">&lsaquo;</button>';
        var nums = [];
        for (var p = 1; p <= pages; p++) {
            if (p === 1 || p === pages || Math.abs(p - this.page) <= 1) nums.push(p);
            else if (nums[nums.length - 1] !== 'gap') nums.push('gap');
        }
        nums.forEach(function (n) {
            if (n === 'gap') { html += '<span class="tbl-pager-gap" aria-hidden="true">&hellip;</span>'; return; }
            html += '<button type="button" class="tbl-pager-btn' + (n === this.page ? ' is-current' : '') + '" data-page="' + n + '"'
                + (n === this.page ? ' aria-current="page"' : '') + ' aria-label="Page ' + n + '">' + n + '</button>';
        }, this);
        html += '<button type="button" class="tbl-pager-btn" data-page="' + (this.page + 1) + '"' + (this.page >= pages ? ' disabled' : '') + ' aria-label="Next page">&rsaquo;</button>';
        html += '</span>';
        html += '<label class="tbl-pager-size">Rows <select aria-label="Rows per page">';
        SIZES.forEach(function (s) { html += '<option value="' + s + '"' + (s === this.size ? ' selected' : '') + '>' + s + '</option>'; }, this);
        html += '<option value="0"' + (this.size === 0 ? ' selected' : '') + '>All</option></select></label>';
        this.el.innerHTML = html;
    };

    function init() {
        Array.prototype.forEach.call(main.querySelectorAll('table'), function (t) {
            if (eligible(t)) new Pager(t);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
    window.imsTablePager = { refresh: init };
})();
