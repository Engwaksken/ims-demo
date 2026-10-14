/* ==========================================================================
   ACCESSIBILITY WIDGET
   --------------------------------------------------------------------------
   Floating button (bottom-right) that opens a panel of display and reading
   aids for people with disabilities. Include once per page, in <head>:

       <script src="../js/accessibility.js"></script>

   The script loads css/accessibility.css from the same base path, applies the
   saved settings to <html> straight away (no flash of the default page), then
   builds the UI once the DOM is ready. Settings persist in localStorage.

   Shortcut: Alt+A opens/closes the panel; Escape closes it.
   ========================================================================== */
(function () {
    'use strict';

    if (window.IMSAccessibility) return;

    var STORAGE_KEY = 'ims-a11y-settings';
    var root = document.documentElement;

    /* ---- Option tables ------------------------------------------------ */
    var ZOOM = [1, 1.1, 1.2, 1.35, 1.5, 1.75, 2];
    var LINE_HEIGHT = [
        { label: 'Default' },
        { label: '1.5', value: '1.5' },
        { label: '1.8', value: '1.8' },
        { label: '2.2', value: '2.2' }
    ];
    var SPACING = [
        { label: 'Default' },
        { label: 'Wide', letter: '0.12em', word: '0.16em' },
        { label: 'Wider', letter: '0.2em', word: '0.3em' }
    ];
    var CONTRAST = [
        { label: 'Default' },
        { label: 'High', filter: 'contrast(1.5)' },
        { label: 'Inverted', filter: 'invert(1) hue-rotate(180deg)', invert: true },
        { label: 'Soft', filter: 'contrast(0.85) brightness(1.05)' }
    ];
    var SATURATION = [
        { label: 'Default' },
        { label: 'Grayscale', filter: 'grayscale(1)' },
        { label: 'Low', filter: 'saturate(0.5)' },
        { label: 'High', filter: 'saturate(2)' }
    ];

    var DEFAULTS = {
        zoom: 0, lineHeight: 0, spacing: 0, contrast: 0, saturation: 0,
        readableFont: false, alignLeft: false, links: false, headings: false,
        focus: false, cursor: false, noMotion: false, hideImages: false,
        guide: false, mask: false, tts: false
    };

    /* Boolean settings -> class on <html>. */
    var FLAG_CLASSES = {
        readableFont: 'a11y-readable-font',
        alignLeft: 'a11y-align-left',
        links: 'a11y-links',
        headings: 'a11y-headings',
        focus: 'a11y-focus',
        cursor: 'a11y-cursor',
        noMotion: 'a11y-no-motion',
        hideImages: 'a11y-hide-images',
        guide: 'a11y-guide-on',
        mask: 'a11y-mask-on',
        tts: 'a11y-tts'
    };

    /* ---- State -------------------------------------------------------- */
    function load() {
        var s = {};
        try { s = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') || {}; } catch (e) { s = {}; }
        var out = {};
        Object.keys(DEFAULTS).forEach(function (k) {
            out[k] = (k in s && typeof s[k] === typeof DEFAULTS[k]) ? s[k] : DEFAULTS[k];
        });
        out.zoom = clamp(out.zoom, 0, ZOOM.length - 1);
        out.lineHeight = clamp(out.lineHeight, 0, LINE_HEIGHT.length - 1);
        out.spacing = clamp(out.spacing, 0, SPACING.length - 1);
        out.contrast = clamp(out.contrast, 0, CONTRAST.length - 1);
        out.saturation = clamp(out.saturation, 0, SATURATION.length - 1);
        return out;
    }

    function save() {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) { /* private mode */ }
    }

    function clamp(n, min, max) {
        n = parseInt(n, 10);
        return isNaN(n) ? min : Math.min(max, Math.max(min, n));
    }

    function isActive() {
        return Object.keys(DEFAULTS).some(function (k) { return state[k] !== DEFAULTS[k]; });
    }

    var state = load();

    /* ---- Apply settings to the page ----------------------------------- */
    function apply() {
        var z = ZOOM[state.zoom];
        root.classList.toggle('a11y-zoom', z !== 1);
        root.style.setProperty('--a11y-zoom', String(z));

        var lh = LINE_HEIGHT[state.lineHeight];
        root.classList.toggle('a11y-line-height', !!lh.value);
        if (lh.value) root.style.setProperty('--a11y-line-height', lh.value);
        else root.style.removeProperty('--a11y-line-height');

        var sp = SPACING[state.spacing];
        root.classList.toggle('a11y-spacing', !!sp.letter);
        if (sp.letter) {
            root.style.setProperty('--a11y-letter-spacing', sp.letter);
            root.style.setProperty('--a11y-word-spacing', sp.word);
        } else {
            root.style.removeProperty('--a11y-letter-spacing');
            root.style.removeProperty('--a11y-word-spacing');
        }

        var filters = [CONTRAST[state.contrast].filter, SATURATION[state.saturation].filter].filter(Boolean);
        root.classList.toggle('a11y-filter', filters.length > 0);
        root.classList.toggle('a11y-invert', !!CONTRAST[state.contrast].invert);
        if (filters.length) root.style.setProperty('--a11y-filter', filters.join(' '));
        else root.style.removeProperty('--a11y-filter');

        Object.keys(FLAG_CLASSES).forEach(function (k) {
            root.classList.toggle(FLAG_CLASSES[k], !!state[k]);
        });

        if (state.noMotion) pauseMedia();
        if (!state.tts) stopSpeech();
    }

    function pauseMedia() {
        if (!document.body) return;
        Array.prototype.forEach.call(document.querySelectorAll('video, audio'), function (m) {
            if (m.autoplay || !m.paused) { try { m.pause(); } catch (e) { /* ignore */ } }
        });
    }

    // Apply immediately: this runs in <head>, before the page paints.
    apply();

    // Load the stylesheet from the same base path as this script.
    (function injectCss() {
        if (document.querySelector('link[data-a11y-css]')) return;
        var me = document.currentScript;
        var src = me && me.src ? me.src : '';
        var href = src ? src.replace(/js\/accessibility\.js(\?.*)?$/, 'css/accessibility.css$1') : '/css/accessibility.css';
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.setAttribute('data-a11y-css', '');
        (document.head || root).appendChild(link);
    })();

    /* ---- Text to speech ----------------------------------------------- */
    var synth = window.speechSynthesis || null;
    var speakingEl = null;

    function stopSpeech() {
        if (synth) { try { synth.cancel(); } catch (e) { /* ignore */ } }
        if (speakingEl) { speakingEl.classList.remove('a11y-speaking'); speakingEl = null; }
    }

    // Chrome stops long utterances after ~15s, so speak sentence-sized chunks.
    function speak(text, el) {
        if (!synth) return;
        stopSpeech();
        text = String(text || '').replace(/\s+/g, ' ').trim();
        if (!text) return;
        var chunks = text.match(/[^.!?;:]{1,220}[.!?;:]*|.{1,220}/g) || [text];
        if (el) { speakingEl = el; el.classList.add('a11y-speaking'); }
        chunks.forEach(function (c, i) {
            var u = new SpeechSynthesisUtterance(c.trim());
            u.lang = root.lang || 'en';
            u.rate = 1;
            if (i === chunks.length - 1) {
                u.onend = u.onerror = function () {
                    if (speakingEl) { speakingEl.classList.remove('a11y-speaking'); speakingEl = null; }
                };
            }
            synth.speak(u);
        });
    }

    function readableText(el) {
        if (!el) return '';
        var clone = el.cloneNode(true);
        Array.prototype.forEach.call(
            clone.querySelectorAll('script, style, noscript, template, [aria-hidden="true"], .a11y-ui, [hidden]'),
            function (n) { n.remove(); }
        );
        return clone.innerText || clone.textContent || '';
    }

    function pageContent() {
        return document.getElementById('main-content') || document.querySelector('main, [role="main"]') || document.body;
    }

    /* ---- UI ----------------------------------------------------------- */
    var ICONS = {
        person: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><circle cx="12" cy="4" r="2.2"/><path d="M20.5 7.6a1.2 1.2 0 0 0-1.4-.9c-2.3.5-4.6.8-7.1.8s-4.8-.3-7.1-.8a1.2 1.2 0 1 0-.5 2.3c1.8.4 3.7.7 5.6.8v3.2l-1.9 7.8a1.2 1.2 0 1 0 2.3.6L12 15.2l1.6 6.2a1.2 1.2 0 1 0 2.3-.6L14 13v-3.2c1.9-.1 3.8-.4 5.6-.8a1.2 1.2 0 0 0 .9-1.4z"/></svg>',
        close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>',
        lineHeight: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M10 5h11M10 12h11M10 19h11M4 7l2-3 2 3M4 17l2 3 2-3M6 4v16"/></svg>',
        spacing: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 15l4-10 4 10M5.5 11.5h5M15 5v10M15 5h3a2.5 2.5 0 0 1 0 5h-3M15 10h3.5a2.5 2.5 0 0 1 0 5H15M3 20h18M3 20l2-1.5M3 20l2 1.5M21 20l-2-1.5M21 20l-2 1.5"/></svg>',
        font: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 20L10 4l6 16M6.5 14h7M17 20v-7a3 3 0 0 1 3-3"/></svg>',
        align: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M3 6h18M3 10h12M3 14h18M3 18h10"/></svg>',
        contrast: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 3v18a9 9 0 0 0 0-18z" fill="currentColor"/></svg>',
        saturation: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11z"/><path d="M12 9v11" /></svg>',
        link: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>',
        heading: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M5 4v16M15 4v16M5 12h10M19 10l2-1v11"/></svg>',
        focus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3"/><rect x="8" y="9" width="8" height="6" rx="1"/></svg>',
        cursor: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 3l14 7-6 2-2 6z"/><path d="M13 12l6 6"/></svg>',
        guide: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 5h16M4 19h16"/><rect x="2" y="10" width="20" height="4" rx="1" fill="currentColor" stroke="none"/></svg>',
        mask: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><rect x="3" y="3" width="18" height="6" fill="currentColor" stroke="none" opacity=".45"/><rect x="3" y="15" width="18" height="6" fill="currentColor" stroke="none" opacity=".45"/><path d="M3 12h18"/></svg>',
        motion: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/></svg>',
        image: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9M3 3l18 18"/></svg>',
        speak: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M11 5L6 9H3v6h3l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/></svg>',
        play: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7 4.5v15l12-7.5z"/></svg>',
        stop: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><rect x="6" y="6" width="12" height="12" rx="1.5"/></svg>',
        structure: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 5h4M4 12h4M4 19h4M11 5h9M13 12h7M13 19h7"/></svg>',
        reset: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>'
    };

    /* Panel controls. type: 'toggle' (on/off) or 'cycle' (steps through options). */
    var SECTIONS = [
        {
            title: 'Text & reading',
            tiles: [
                { key: 'lineHeight', type: 'cycle', options: LINE_HEIGHT, label: 'Line height', icon: 'lineHeight' },
                { key: 'spacing', type: 'cycle', options: SPACING, label: 'Text spacing', icon: 'spacing' },
                { key: 'readableFont', type: 'toggle', label: 'Readable font', icon: 'font', hint: 'Dyslexia-friendly' },
                { key: 'alignLeft', type: 'toggle', label: 'Align left', icon: 'align' },
                { key: 'links', type: 'toggle', label: 'Highlight links', icon: 'link' },
                { key: 'headings', type: 'toggle', label: 'Highlight headings', icon: 'heading' }
            ]
        },
        {
            title: 'Colour & vision',
            tiles: [
                { key: 'contrast', type: 'cycle', options: CONTRAST, label: 'Contrast', icon: 'contrast' },
                { key: 'saturation', type: 'cycle', options: SATURATION, label: 'Saturation', icon: 'saturation' },
                { key: 'hideImages', type: 'toggle', label: 'Hide images', icon: 'image' }
            ]
        },
        {
            title: 'Navigation & focus',
            tiles: [
                { key: 'focus', type: 'toggle', label: 'Focus outline', icon: 'focus' },
                { key: 'cursor', type: 'toggle', label: 'Big cursor', icon: 'cursor' },
                { key: 'noMotion', type: 'toggle', label: 'Stop animations', icon: 'motion' },
                { key: 'guide', type: 'toggle', label: 'Reading guide', icon: 'guide' },
                { key: 'mask', type: 'toggle', label: 'Reading mask', icon: 'mask' },
                { key: 'structure', type: 'action', label: 'Page structure', icon: 'structure' }
            ]
        }
    ];

    var ui, fab, panel, live, stepOut, stepDown, stepUp, structureList, guideEl, maskTop, maskBottom;
    var tileEls = {};
    var lastFocus = null;

    function el(tag, attrs, html) {
        var n = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        if (html != null) n.innerHTML = html;
        return n;
    }

    function announce(msg) {
        if (!live) return;
        live.textContent = '';
        setTimeout(function () { live.textContent = msg; }, 30);
    }

    function tileState(t) {
        if (t.type === 'cycle') return t.options[state[t.key]].label;
        if (t.type === 'toggle') return state[t.key] ? 'On' : 'Off';
        return '';
    }

    function renderTile(t) {
        var b = tileEls[t.key];
        if (!b) return;
        if (t.type === 'action') {
            b.setAttribute('aria-expanded', structureList && !structureList.hidden ? 'true' : 'false');
            return;
        }
        var on = t.type === 'cycle' ? state[t.key] > 0 : !!state[t.key];
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
        var s = b.querySelector('.a11y-tile-state');
        if (t.type === 'cycle') {
            s.textContent = tileState(t);
            var bars = b.querySelectorAll('.a11y-level span');
            Array.prototype.forEach.call(bars, function (bar, i) { bar.classList.toggle('on', i < state[t.key]); });
        } else {
            s.textContent = tileState(t);
        }
    }

    function renderAll() {
        SECTIONS.forEach(function (sec) { sec.tiles.forEach(renderTile); });
        if (stepOut) {
            stepOut.value = stepOut.textContent = Math.round(ZOOM[state.zoom] * 100) + '%';
            stepDown.disabled = state.zoom === 0;
            stepUp.disabled = state.zoom === ZOOM.length - 1;
        }
        if (tileEls.tts) {
            tileEls.tts.setAttribute('aria-pressed', state.tts ? 'true' : 'false');
            tileEls.tts.querySelector('.a11y-tile-state').textContent = state.tts ? 'On' : 'Off';
        }
        if (ui) ui.classList.toggle('has-active', isActive());
    }

    function update(key, value, label) {
        state[key] = value;
        apply();
        save();
        renderAll();
        if (label) announce(label);
    }

    function onTile(t) {
        if (t.type === 'action') { toggleStructure(); return; }
        if (t.type === 'cycle') {
            var next = (state[t.key] + 1) % t.options.length;
            update(t.key, next, t.label + ': ' + t.options[next].label);
        } else {
            update(t.key, !state[t.key], t.label + ' ' + (!state[t.key] ? 'on' : 'off'));
        }
        if (t.key === 'guide' && state.guide && state.mask) update('mask', false);
        if (t.key === 'mask' && state.mask && state.guide) update('guide', false);
    }

    function buildTile(t) {
        var b = el('button', { type: 'button', 'class': 'a11y-tile' });
        if (t.type === 'action') b.setAttribute('aria-controls', 'a11y-structure');
        var inner = ICONS[t.icon] + '<span>' + t.label + '</span>';
        if (t.type === 'cycle') {
            inner += '<span class="a11y-level" aria-hidden="true">';
            for (var i = 1; i < t.options.length; i++) inner += '<span></span>';
            inner += '</span>';
        }
        if (t.type !== 'action') inner += '<span class="a11y-sr-only">, </span><span class="a11y-tile-state a11y-sr-only"></span>';
        b.innerHTML = inner;
        if (t.hint) b.title = t.hint;
        b.addEventListener('click', function () { onTile(t); });
        tileEls[t.key] = b;
        return b;
    }

    function buildUI() {
        if (ui || !document.body) return;

        ui = el('div', { 'class': 'a11y-ui', id: 'a11y-widget' });

        fab = el('button', {
            type: 'button',
            'class': 'a11y-fab',
            'aria-label': 'Accessibility options (Alt+A)',
            title: 'Accessibility options (Alt+A)',
            'aria-expanded': 'false',
            'aria-controls': 'a11y-panel'
        }, ICONS.person + '<span class="a11y-fab-dot" aria-hidden="true"></span>');

        panel = el('div', {
            'class': 'a11y-panel',
            id: 'a11y-panel',
            role: 'dialog',
            'aria-modal': 'false',
            'aria-labelledby': 'a11y-title'
        });
        panel.hidden = true;

        var head = el('div', { 'class': 'a11y-head' });
        head.innerHTML = ICONS.person + '<h2 id="a11y-title" tabindex="-1">Accessibility</h2>';
        var closeBtn = el('button', { type: 'button', 'class': 'a11y-icon-btn', 'aria-label': 'Close accessibility options' }, ICONS.close);
        closeBtn.addEventListener('click', function () { close(true); });
        head.appendChild(closeBtn);

        var body = el('div', { 'class': 'a11y-body' });

        // Text size stepper.
        var sizeSec = el('section', { 'class': 'a11y-section', 'aria-labelledby': 'a11y-sec-size' });
        sizeSec.appendChild(el('h3', { id: 'a11y-sec-size' }, 'Text size'));
        var stepper = el('div', { 'class': 'a11y-stepper', role: 'group', 'aria-label': 'Text size' });
        stepDown = el('button', { type: 'button', 'class': 'a11y-step-btn', 'aria-label': 'Decrease text size' }, '&minus;');
        stepUp = el('button', { type: 'button', 'class': 'a11y-step-btn', 'aria-label': 'Increase text size' }, '+');
        stepOut = el('output', { 'aria-live': 'off' });
        stepper.appendChild(el('span', { 'class': 'a11y-stepper-label', 'aria-hidden': 'true' }, 'Size'));
        stepper.appendChild(stepDown);
        stepper.appendChild(stepOut);
        stepper.appendChild(stepUp);
        stepDown.addEventListener('click', function () {
            if (state.zoom > 0) update('zoom', state.zoom - 1, 'Text size ' + Math.round(ZOOM[state.zoom - 1] * 100) + '%');
        });
        stepUp.addEventListener('click', function () {
            if (state.zoom < ZOOM.length - 1) update('zoom', state.zoom + 1, 'Text size ' + Math.round(ZOOM[state.zoom + 1] * 100) + '%');
        });
        sizeSec.appendChild(stepper);
        body.appendChild(sizeSec);

        // Tile sections.
        SECTIONS.forEach(function (sec, i) {
            var s = el('section', { 'class': 'a11y-section', 'aria-labelledby': 'a11y-sec-' + i });
            s.appendChild(el('h3', { id: 'a11y-sec-' + i }, sec.title));
            var grid = el('div', { 'class': 'a11y-grid' });
            sec.tiles.forEach(function (t) { grid.appendChild(buildTile(t)); });
            s.appendChild(grid);
            if (sec.tiles.some(function (t) { return t.key === 'structure'; })) {
                structureList = el('ul', { 'class': 'a11y-structure', id: 'a11y-structure', 'aria-label': 'Headings and regions on this page' });
                structureList.hidden = true;
                s.appendChild(structureList);
            }
            body.appendChild(s);
        });

        // Text to speech (only where the browser supports it).
        if (synth && window.SpeechSynthesisUtterance) {
            var ttsSec = el('section', { 'class': 'a11y-section', 'aria-labelledby': 'a11y-sec-tts' });
            ttsSec.appendChild(el('h3', { id: 'a11y-sec-tts' }, 'Read aloud'));
            var ttsGrid = el('div', { 'class': 'a11y-grid' });
            var ttsTile = buildTile({ key: 'tts', type: 'toggle', label: 'Click to read', icon: 'speak' });
            ttsTile.title = 'Click any text, or select text, to hear it';
            ttsGrid.appendChild(ttsTile);
            ttsGrid.style.gridTemplateColumns = '1fr';
            ttsSec.appendChild(ttsGrid);
            var row = el('div', { 'class': 'a11y-row' });
            var readBtn = el('button', { type: 'button', 'class': 'a11y-btn a11y-btn-primary' }, ICONS.play + '<span>Read page</span>');
            var stopBtn = el('button', { type: 'button', 'class': 'a11y-btn' }, ICONS.stop + '<span>Stop</span>');
            readBtn.addEventListener('click', function () { speak(readableText(pageContent())); });
            stopBtn.addEventListener('click', function () { stopSpeech(); announce('Reading stopped'); });
            row.appendChild(readBtn);
            row.appendChild(stopBtn);
            ttsSec.appendChild(row);
            body.appendChild(ttsSec);
        }

        var foot = el('div', { 'class': 'a11y-foot' });
        foot.appendChild(el('small', null, 'Saved on this device. Shortcut: Alt+A'));
        var resetBtn = el('button', { type: 'button', 'class': 'a11y-btn' }, ICONS.reset + '<span>Reset all</span>');
        resetBtn.addEventListener('click', resetAll);
        foot.appendChild(resetBtn);

        live = el('div', { 'class': 'a11y-sr-only', role: 'status', 'aria-live': 'polite' });

        panel.appendChild(head);
        panel.appendChild(body);
        panel.appendChild(foot);
        ui.appendChild(panel);
        ui.appendChild(fab);
        ui.appendChild(live);
        document.body.appendChild(ui);

        // Reading guide / mask overlays (outside .a11y-ui so they sit over the page).
        guideEl = el('div', { 'class': 'a11y-overlay a11y-guide', 'aria-hidden': 'true' });
        maskTop = el('div', { 'class': 'a11y-overlay a11y-mask-top', 'aria-hidden': 'true' });
        maskBottom = el('div', { 'class': 'a11y-overlay a11y-mask-bottom', 'aria-hidden': 'true' });
        document.body.appendChild(guideEl);
        document.body.appendChild(maskTop);
        document.body.appendChild(maskBottom);
        positionOverlays(window.innerHeight / 2);

        fab.addEventListener('click', function () { panel.hidden ? open() : close(true); });
        bindGlobalEvents();
        renderAll();
    }

    /* ---- Open / close ------------------------------------------------- */
    function open() {
        lastFocus = document.activeElement;
        panel.hidden = false;
        fab.setAttribute('aria-expanded', 'true');
        var title = panel.querySelector('#a11y-title');
        if (title) title.focus();
    }

    function close(restoreFocus) {
        if (!panel || panel.hidden) return;
        panel.hidden = true;
        fab.setAttribute('aria-expanded', 'false');
        if (restoreFocus) {
            var target = panel.contains(lastFocus) || !lastFocus || lastFocus === document.body ? fab : lastFocus;
            try { target.focus(); } catch (e) { fab.focus(); }
        }
    }

    function resetAll() {
        Object.keys(DEFAULTS).forEach(function (k) { state[k] = DEFAULTS[k]; });
        stopSpeech();
        apply();
        save();
        if (structureList) structureList.hidden = true;
        renderAll();
        announce('All accessibility settings reset');
    }

    /* ---- Page structure ----------------------------------------------- */
    var LANDMARKS = {
        header: 'Banner', nav: 'Navigation', main: 'Main content', aside: 'Sidebar', footer: 'Footer',
        banner: 'Banner', navigation: 'Navigation', complementary: 'Sidebar', contentinfo: 'Footer', search: 'Search'
    };

    function isVisible(n) {
        return !!(n.offsetWidth || n.offsetHeight || n.getClientRects().length) &&
            getComputedStyle(n).visibility !== 'hidden';
    }

    function toggleStructure() {
        if (!structureList) return;
        if (!structureList.hidden) {
            structureList.hidden = true;
            renderTile({ key: 'structure', type: 'action' });
            return;
        }
        structureList.innerHTML = '';
        var nodes = document.querySelectorAll('h1, h2, h3, h4, h5, h6, [role="heading"], main, nav, aside, header, footer, [role="main"], [role="navigation"], [role="banner"], [role="complementary"], [role="contentinfo"], [role="search"]');
        var count = 0;
        Array.prototype.forEach.call(nodes, function (n) {
            if (ui.contains(n) || !isVisible(n)) return;
            var tag = n.tagName.toLowerCase();
            var role = n.getAttribute('role');
            var isHeading = /^h[1-6]$/.test(tag) || role === 'heading';
            var label, badge;
            if (isHeading) {
                var level = /^h[1-6]$/.test(tag) ? tag.charAt(1) : (n.getAttribute('aria-level') || '2');
                badge = 'H' + level;
                label = (n.textContent || '').replace(/\s+/g, ' ').trim();
            } else {
                badge = 'Area';
                label = n.getAttribute('aria-label') || LANDMARKS[role] || LANDMARKS[tag] || tag;
            }
            if (!label) return;
            var li = el('li');
            var b = el('button', { type: 'button' });
            b.appendChild(el('span', { 'class': 'a11y-tag', 'aria-hidden': 'true' }, badge));
            var txt = el('span');
            txt.textContent = label.length > 80 ? label.slice(0, 77) + '...' : label;
            b.appendChild(txt);
            b.setAttribute('aria-label', (isHeading ? 'Heading level ' + badge.slice(1) : 'Region') + ': ' + txt.textContent);
            b.addEventListener('click', function () { jumpTo(n); });
            li.appendChild(b);
            structureList.appendChild(li);
            count++;
        });
        if (!count) structureList.appendChild(el('li', { 'class': 'a11y-empty' }, 'No headings or regions found on this page.'));
        structureList.hidden = false;
        renderTile({ key: 'structure', type: 'action' });
        announce(count + ' items in page structure');
    }

    function jumpTo(n) {
        if (window.matchMedia('(max-width: 520px)').matches) close(false);
        if (!n.hasAttribute('tabindex') && !/^(a|button|input|select|textarea)$/i.test(n.tagName)) {
            n.setAttribute('tabindex', '-1');
        }
        n.scrollIntoView({ block: 'start', behavior: state.noMotion ? 'auto' : 'smooth' });
        try { n.focus({ preventScroll: true }); } catch (e) { n.focus(); }
    }

    /* ---- Reading guide / mask ----------------------------------------- */
    function positionOverlays(y) {
        if (!guideEl) return;
        var gap = 50;
        guideEl.style.top = y + 'px';
        maskTop.style.height = Math.max(0, y - gap) + 'px';
        maskBottom.style.height = Math.max(0, window.innerHeight - y - gap) + 'px';
    }

    /* ---- Global events ------------------------------------------------ */
    function bindGlobalEvents() {
        document.addEventListener('keydown', function (e) {
            if (e.altKey && !e.ctrlKey && !e.metaKey && (e.key === 'a' || e.key === 'A' || e.code === 'KeyA')) {
                e.preventDefault();
                panel.hidden ? open() : close(true);
                return;
            }
            if (e.key === 'Escape') {
                if (!panel.hidden) { close(true); }
                else if (synth && synth.speaking) { stopSpeech(); }
            }
        });

        // Tapping/clicking outside the panel closes it (non-modal, focus stays put).
        document.addEventListener('pointerdown', function (e) {
            if (!panel.hidden && !ui.contains(e.target)) close(false);
        });

        var onMove = function (y) {
            if (state.guide || state.mask) positionOverlays(y);
        };
        document.addEventListener('pointermove', function (e) { onMove(e.clientY); }, { passive: true });
        document.addEventListener('touchmove', function (e) {
            if (e.touches && e.touches[0]) onMove(e.touches[0].clientY);
        }, { passive: true });
        // Keyboard users: follow focus.
        document.addEventListener('focusin', function (e) {
            if (!(state.guide || state.mask) || ui.contains(e.target)) return;
            var r = e.target.getBoundingClientRect();
            positionOverlays(r.top + r.height / 2);
        });

        // Click-to-read (and read on keyboard focus, and selected text).
        var READ_SEL = 'p, li, h1, h2, h3, h4, h5, h6, td, th, label, a, button, dt, dd, blockquote, figcaption, caption, legend, summary, [role="heading"]';
        document.addEventListener('click', function (e) {
            if (!state.tts || ui.contains(e.target)) return;
            var sel = window.getSelection ? String(window.getSelection()) : '';
            if (sel.trim()) return; // handled by mouseup
            var t = e.target.closest ? e.target.closest(READ_SEL) : null;
            if (t) speak(readableText(t) || t.getAttribute('aria-label') || '', t);
        }, true);
        document.addEventListener('mouseup', function (e) {
            if (!state.tts || ui.contains(e.target)) return;
            var sel = window.getSelection ? String(window.getSelection()) : '';
            if (sel.trim()) speak(sel);
        });
        document.addEventListener('focusin', function (e) {
            if (!state.tts || ui.contains(e.target) || e.target === document.body) return;
            var t = e.target;
            var text = t.getAttribute('aria-label') || (t.labels && t.labels[0] && t.labels[0].textContent) ||
                t.getAttribute('placeholder') || t.getAttribute('title') || readableText(t);
            if (text && text.length < 300) speak(text, t);
        });

        window.addEventListener('pagehide', stopSpeech);

        // Media added after load (modals, AJAX) also respects "stop animations".
        if (window.MutationObserver) {
            new MutationObserver(function () { if (state.noMotion) pauseMedia(); })
                .observe(document.body, { childList: true, subtree: true });
        }

        // Settings changed in another tab.
        window.addEventListener('storage', function (e) {
            if (e.key !== STORAGE_KEY) return;
            state = load();
            apply();
            renderAll();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', buildUI);
    } else {
        buildUI();
    }

    window.IMSAccessibility = {
        open: function () { buildUI(); open(); },
        close: function () { close(true); },
        reset: resetAll
    };
})();
