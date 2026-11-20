/*
 * HR pages UI helpers (hr-operations, recruitment).
 *
 * 1. Custom popups instead of the browser's confirm()/alert():
 *      hrConfirm(message, {title, okText, cancelText, danger}) -> Promise<boolean>
 *      hrAlert(message, {title})                              -> Promise<void>
 *    Declarative: <form data-confirm="..."> or <button data-confirm="...">
 *    (optional data-confirm-title, data-confirm-ok, data-confirm-danger).
 *
 * 2. Multiple selection for tables:
 *      <div data-bulk-scope>
 *        <table> ... <input type="checkbox" class="hr-sel-all"> ...
 *                    <input type="checkbox" class="hr-sel" name="ids[]" value="1"> ...
 *        <div class="hr-bulkbar" data-bulk-bar> <span data-bulk-count></span>
 *          <button data-bulk-required ...>Act on selected</button>
 *      </div>
 *    Select all, shift-click ranges, a live "N selected" count, row
 *    highlighting, and bulk buttons that ask to pick rows when none are.
 */
(function () {
    'use strict';

    /* ---------------- popup ---------------- */
    var dialog = null;
    var resolver = null;

    function esc(text) {
        var d = document.createElement('div');
        d.textContent = String(text == null ? '' : text);
        return d.innerHTML;
    }

    function build() {
        if (dialog) return dialog;
        dialog = document.createElement('div');
        dialog.className = 'modal hr-dialog';
        dialog.id = 'hrDialog';
        dialog.setAttribute('role', 'alertdialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'hrDialogTitle');
        dialog.setAttribute('aria-describedby', 'hrDialogMsg');
        dialog.setAttribute('aria-hidden', 'true');
        dialog.innerHTML =
            '<div class="modal-content hr-dialog-box">' +
            '<div class="hr-dialog-icon" aria-hidden="true"><i class="fas fa-circle-question"></i></div>' +
            '<h3 id="hrDialogTitle"></h3>' +
            '<p id="hrDialogMsg"></p>' +
            '<div class="hr-dialog-actions">' +
            '<button type="button" class="btn btn-secondary" data-hr-dialog="cancel">Cancel</button>' +
            '<button type="button" class="btn btn-primary" data-hr-dialog="ok">OK</button>' +
            '</div></div>';
        document.body.appendChild(dialog);
        dialog.addEventListener('click', function (e) {
            var b = e.target.closest('[data-hr-dialog]');
            if (b) finish(b.getAttribute('data-hr-dialog') === 'ok');
            else if (e.target === dialog) finish(false);
        });
        document.addEventListener('keydown', function (e) {
            if (!dialog.classList.contains('show')) return;
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(false); }
        }, true);
        return dialog;
    }

    function finish(ok) {
        if (!dialog) return;
        dialog.classList.remove('show');
        dialog.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.modal.show')) document.body.style.overflow = '';
        var r = resolver;
        resolver = null;
        var back = dialog._returnFocus;
        if (back && document.contains(back)) back.focus();
        if (r) r(ok);
    }

    function open(message, opts, alertOnly) {
        opts = opts || {};
        build();
        if (resolver) resolver(false);
        var danger = !!opts.danger;
        dialog.querySelector('#hrDialogTitle').textContent = opts.title || (alertOnly ? 'Please check' : 'Are you sure?');
        dialog.querySelector('#hrDialogMsg').innerHTML = esc(message).replace(/\n/g, '<br>');
        var icon = dialog.querySelector('.hr-dialog-icon');
        icon.className = 'hr-dialog-icon ' + (alertOnly ? 'is-info' : (danger ? 'is-danger' : 'is-ask'));
        icon.innerHTML = '<i class="fas ' + (alertOnly ? 'fa-circle-info' : (danger ? 'fa-triangle-exclamation' : 'fa-circle-question')) + '"></i>';
        var ok = dialog.querySelector('[data-hr-dialog="ok"]');
        var cancel = dialog.querySelector('[data-hr-dialog="cancel"]');
        ok.textContent = opts.okText || (alertOnly ? 'OK' : 'Continue');
        ok.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
        cancel.textContent = opts.cancelText || 'Cancel';
        cancel.hidden = !!alertOnly;
        dialog._returnFocus = document.activeElement;
        dialog.classList.add('show');
        dialog.removeAttribute('aria-hidden');
        document.body.style.overflow = 'hidden';
        setTimeout(function () { (alertOnly ? ok : (danger ? cancel : ok)).focus(); }, 30);
        return new Promise(function (resolve) { resolver = resolve; });
    }

    window.hrConfirm = function (message, opts) { return open(message, opts, false); };
    window.hrAlert = function (message, opts) { return open(message, opts, true).then(function () {}); };

    function confirmOpts(el) {
        return {
            title: el.getAttribute('data-confirm-title') || undefined,
            okText: el.getAttribute('data-confirm-ok') || undefined,
            danger: el.hasAttribute('data-confirm-danger'),
        };
    }

    // Buttons with data-confirm (submit buttons keep their name/value).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-confirm], a[data-confirm], input[type="submit"][data-confirm]');
        if (!btn || btn.dataset.hrConfirmed === '1') return;
        // A bulk button with nothing selected gets the "select rows" message instead.
        var bulkScope = btn.hasAttribute('data-bulk-required') ? btn.closest('[data-bulk-scope]') : null;
        if (bulkScope && !bulkScope.querySelector('input.hr-sel:checked')) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        hrConfirm(btn.getAttribute('data-confirm'), confirmOpts(btn)).then(function (yes) {
            if (!yes) return;
            if (btn.tagName === 'A') { window.location.href = btn.href; return; }
            btn.dataset.hrConfirmed = '1';
            btn.click();
            delete btn.dataset.hrConfirmed;
        });
    }, true);

    // Forms with data-confirm.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches || !form.matches('form[data-confirm]') || form.dataset.hrConfirmed === '1') return;
        e.preventDefault();
        var submitter = e.submitter || null;
        hrConfirm(form.getAttribute('data-confirm'), confirmOpts(form)).then(function (yes) {
            if (!yes) return;
            form.dataset.hrConfirmed = '1';
            if (typeof form.requestSubmit === 'function') form.requestSubmit(submitter || undefined); else form.submit();
            delete form.dataset.hrConfirmed;
        });
    }, true);

    /* ---------------- multiple selection ---------------- */
    function scopeOf(el) { return el.closest('[data-bulk-scope]'); }
    function boxes(scope) { return Array.prototype.slice.call(scope.querySelectorAll('input.hr-sel:not(:disabled)')); }

    function refresh(scope) {
        var all = boxes(scope);
        var picked = all.filter(function (b) { return b.checked; });
        all.forEach(function (b) {
            var row = b.closest('tr');
            if (row) row.classList.toggle('is-selected', b.checked);
        });
        scope.querySelectorAll('.hr-sel-all').forEach(function (h) {
            h.checked = all.length > 0 && picked.length === all.length;
            h.indeterminate = picked.length > 0 && picked.length < all.length;
        });
        scope.querySelectorAll('[data-bulk-count]').forEach(function (c) {
            c.textContent = picked.length ? picked.length + ' selected' : 'None selected';
        });
        scope.querySelectorAll('[data-bulk-bar]').forEach(function (bar) {
            bar.classList.toggle('has-selection', picked.length > 0);
        });
    }

    var lastClicked = new WeakMap();

    document.addEventListener('click', function (e) {
        var box = e.target.closest('input.hr-sel');
        if (!box) return;
        var scope = scopeOf(box);
        if (!scope) return;
        var list = boxes(scope);
        var prev = lastClicked.get(scope);
        if (e.shiftKey && prev && prev !== box && list.indexOf(prev) > -1) {
            var a = list.indexOf(prev), b = list.indexOf(box);
            list.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(function (x) { x.checked = box.checked; });
        }
        lastClicked.set(scope, box);
    });

    document.addEventListener('change', function (e) {
        var t = e.target;
        var scope = scopeOf(t);
        if (!scope) return;
        if (t.matches('.hr-sel-all')) {
            boxes(scope).forEach(function (b) {
                var row = b.closest('tr');
                if (!row || !row.classList.contains('stat-filter-hidden')) b.checked = t.checked;
            });
        }
        if (t.matches('.hr-sel, .hr-sel-all')) refresh(scope);
    });

    // Bulk buttons need a selection.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-bulk-required]');
        if (!btn) return;
        var scope = scopeOf(btn);
        if (scope && !boxes(scope).some(function (b) { return b.checked; })) {
            e.preventDefault();
            e.stopImmediatePropagation();
            hrAlert(btn.getAttribute('data-bulk-required') || 'Select at least one row first.', { title: 'Nothing selected' });
        }
    }, true);

    /** Values of the selected rows in the scope containing el. */
    window.hrSelected = function (el) {
        var scope = typeof el === 'string' ? document.getElementById(el) : scopeOf(el);
        return scope ? boxes(scope).filter(function (b) { return b.checked; }).map(function (b) { return parseInt(b.value, 10); }) : [];
    };

    function init() { document.querySelectorAll('[data-bulk-scope]').forEach(refresh); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
