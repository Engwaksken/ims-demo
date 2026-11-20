/*
 * Clickable statistics cards (every page with .stats-grid / .reports-stats).
 *
 * Each stat card becomes a button that filters the page it sits on:
 *
 *   data-stat-href="url"        go to that (pre-filtered) URL
 *   data-stat-filter="word"     filter the page by that word (see below)
 *   data-stat-filter="all"      clear the filter (show everything)
 *   data-stat-filter="none"     card stays a plain, non-clickable figure
 *
 * Without attributes the card's label decides: "Total ..." / "All ..."
 * clears the filter; otherwise the label's words are matched, in order, to
 *   1. an option of a filter <select> on the page (search / filter bars,
 *      GET forms): the option is selected and the filter applied (the form
 *      is submitted, or the page's own change handler runs), or
 *   2. the status badges of the page's list rows (table rows and list
 *      cards): rows without that status are hidden.
 * Cards whose label matches nothing on the page (money totals, averages)
 * are left as they are. Clicking the active card again clears the filter.
 * Cards that pages already wire up (links, data-filter, onclick) are left
 * alone.
 */
(function () {
    'use strict';

    var STOP = ['total', 'totals', 'all', 'number', 'of', 'no', 'count', 'records', 'record', 'this', 'month', 'year', 'week',
        'today', 'the', 'in', 'my', 'your', 'and', 'or', 'a', 'an', 'to', 'for', 'per', 'by', 'with', 'on', 'from', 'yet'];
    var ROW_SELECTOR = 'table tbody tr, [data-stat-row], .employee-card, .opp-card, .job-card, .app-card, .project-card, .program-card, .member-card, .space-card';
    var BADGE_SELECTOR = '.badge, [class*="status"], [class*="pill"], [class*="tag"], [data-status]';

    var main = document.querySelector('.main-content') || document.body;
    var activeCard = null;

    function norm(text) {
        return String(text || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function stem(word) {
        if (word.length > 4 && /ies$/.test(word)) return word.slice(0, -3) + 'y';
        if (word.length > 4 && /s$/.test(word) && !/ss$/.test(word)) return word.slice(0, -1);
        return word;
    }

    function wordRegex(word) {
        var base = stem(word).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        var tail = /y$/.test(base) ? '(?:y|ies)' : '(?:s|es)?';
        if (/y$/.test(base)) base = base.slice(0, -1);
        return new RegExp('(^|[^a-z])' + base + tail + '($|[^a-z])', 'i');
    }

    /** Significant words of a card label, most specific first (the phrase, then single words). */
    function labelWords(label) {
        var clean = norm(label).replace(/\(.*?\)/g, ' ').replace(/[0-9]+/g, ' ').replace(/[^a-z\s/&-]/g, ' ');
        var words = clean.split(/[\s/&-]+/).filter(function (w) { return w.length > 1 && STOP.indexOf(w) === -1; });
        return words;
    }

    function isResetLabel(label) {
        var l = norm(label);
        return /^(total|all)\b/.test(l) && labelWords(label).length <= 1;
    }

    function inModalOrStats(el) {
        return !!el.closest('.modal, dialog, .jm-overlay, .stats-grid, .reports-stats, [aria-hidden="true"]');
    }

    /* ---------- strategy 1: filter selects ---------- */
    function filterSelects() {
        return Array.prototype.filter.call(main.querySelectorAll('select'), function (s) {
            if (inModalOrStats(s) || s.closest('table') || s.multiple) return false;
            var form = s.form;
            return !!(s.closest('.filters-bar, .controls-bar, .filter-bar, [role="search"], .toolbar, .filters')
                || (form && (form.getAttribute('method') || 'get').toLowerCase() === 'get'));
        });
    }

    function findOption(words) {
        var selects = filterSelects();
        for (var w = 0; w < words.length; w++) {
            var re = wordRegex(words[w]);
            for (var i = 0; i < selects.length; i++) {
                var opts = selects[i].options;
                for (var j = 0; j < opts.length; j++) {
                    if (opts[j].value === '') continue;
                    if (re.test(opts[j].text) || re.test(opts[j].value)) {
                        return { select: selects[i], value: opts[j].value };
                    }
                }
            }
        }
        return null;
    }

    function applySelect(select, value) {
        select.value = value;
        var form = select.form;
        var isGetForm = form && (form.getAttribute('method') || 'get').toLowerCase() === 'get';
        // Pages that filter as you change the select (onchange / listeners) do it themselves.
        var live = select.hasAttribute('onchange') || select.hasAttribute('oninput') || select.dataset.live === '1';
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
        if (isGetForm && !live) {
            if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
        }
    }

    function clearSelects() {
        var submitted = null;
        filterSelects().forEach(function (s) {
            if (s.value === '' || !Array.prototype.some.call(s.options, function (o) { return o.value === ''; })) return;
            s.value = '';
            s.dispatchEvent(new Event('input', { bubbles: true }));
            s.dispatchEvent(new Event('change', { bubbles: true }));
            var live = s.hasAttribute('onchange') || s.hasAttribute('oninput');
            if (!live && s.form && (s.form.getAttribute('method') || 'get').toLowerCase() === 'get') submitted = s.form;
        });
        if (submitted) {
            if (typeof submitted.requestSubmit === 'function') submitted.requestSubmit(); else submitted.submit();
            return true;
        }
        return false;
    }

    /* ---------- strategy 2: row badges ---------- */
    function rows() {
        return Array.prototype.filter.call(main.querySelectorAll(ROW_SELECTOR), function (r) {
            return !inModalOrStats(r) && !r.querySelector('td[colspan]') && !r.closest('thead');
        });
    }

    function rowStatusText(row) {
        var parts = Array.prototype.map.call(row.querySelectorAll(BADGE_SELECTOR), function (b) {
            return (b.getAttribute('data-status') || '') + ' ' + b.textContent;
        });
        if (row.dataset.status) parts.push(row.dataset.status);
        // Status selects inside a row (e.g. inline status editors) count as the row's status.
        Array.prototype.forEach.call(row.querySelectorAll('select'), function (s) {
            if (s.selectedOptions && s.selectedOptions[0]) parts.push(s.selectedOptions[0].text);
        });
        return parts.join(' ');
    }

    function findRowWord(words) {
        var list = rows();
        if (!list.length) return null;
        for (var w = 0; w < words.length; w++) {
            var re = wordRegex(words[w]);
            for (var i = 0; i < list.length; i++) {
                if (re.test(rowStatusText(list[i]))) return words[w];
            }
        }
        return null;
    }

    function filterRows(word) {
        var re = word ? wordRegex(word) : null;
        var shown = 0;
        rows().forEach(function (r) {
            var keep = !re || re.test(rowStatusText(r));
            r.classList.toggle('stat-filter-hidden', !keep);
            if (keep) shown++;
        });
        return shown;
    }

    /* ---------- wiring ---------- */
    function setActive(card) {
        if (activeCard) {
            activeCard.classList.remove('is-active');
            activeCard.setAttribute('aria-pressed', 'false');
        }
        activeCard = card;
        if (card) {
            card.classList.add('is-active');
            card.setAttribute('aria-pressed', 'true');
        }
    }

    function listAnchor() {
        var r = rows()[0];
        return r ? (r.closest('.card, section, table') || r) : null;
    }

    function scrollToList() {
        var a = listAnchor();
        if (a && a.getBoundingClientRect().top > window.innerHeight * 0.6) {
            a.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function makeClickable(card, action, title) {
        card.classList.add('is-clickable');
        card.setAttribute('role', 'button');
        card.setAttribute('tabindex', '0');
        card.setAttribute('aria-pressed', 'false');
        if (title && !card.title) card.title = title;
        card.addEventListener('click', function (e) {
            if (e.target.closest('a, button, input, select, textarea, label')) return;
            action();
        });
        card.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); action(); }
        });
    }

    function wire(card) {
        if (card.dataset.statWired) return;
        card.dataset.statWired = '1';
        var explicit = card.getAttribute('data-stat-filter');
        if (explicit === 'none') return;
        // Already handled by the page.
        if (card.closest('a, button') || card.hasAttribute('data-filter') || card.hasAttribute('onclick')) return;

        var labelEl = card.querySelector('.stat-label, .stat-title, h4, p');
        var label = explicit || (labelEl ? labelEl.textContent : card.textContent);

        var href = card.getAttribute('data-stat-href');
        if (href) {
            makeClickable(card, function () { window.location.href = href; }, 'Show ' + norm(labelEl ? labelEl.textContent : '') );
            try {
                var target = new URL(href, window.location.href);
                if (target.pathname === window.location.pathname && target.search === window.location.search) setActive(card);
            } catch (e) { /* ignore */ }
            return;
        }

        if (explicit === 'all' || (!explicit && isResetLabel(label))) {
            makeClickable(card, function () {
                setActive(null);
                if (!clearSelects()) { filterRows(null); scrollToList(); }
            }, 'Show all');
            return;
        }

        var words = explicit ? [explicit] : labelWords(label);
        if (!words.length) return;

        var opt = findOption(words);
        if (opt) {
            if (opt.select.value === opt.value) setActive(card);
            makeClickable(card, function () {
                if (activeCard === card) { setActive(null); clearSelects(); filterRows(null); return; }
                setActive(card);
                applySelect(opt.select, opt.value);
                scrollToList();
            }, 'Filter: ' + (Array.prototype.find.call(opt.select.options, function (o) { return o.value === opt.value; }) || {}).text);
            return;
        }

        var word = findRowWord(words);
        if (word) {
            makeClickable(card, function () {
                if (activeCard === card) { setActive(null); filterRows(null); return; }
                setActive(card);
                filterRows(word);
                scrollToList();
            }, 'Filter: ' + word);
        }
    }

    function init() {
        Array.prototype.forEach.call(main.querySelectorAll('.stats-grid .stat-card, .reports-stats .stat-card'), wire);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
    window.imsStatFilters = { refresh: init };
})();
