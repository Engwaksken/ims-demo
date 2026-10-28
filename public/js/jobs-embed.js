/*
 * Jobs widget.
 * Embed on a partner site:
 *   <div id="ims-jobs"></div>
 *   <script src="https://<ims-host>/js/jobs-embed.js"></script>
 * The iframe URL is derived from this script's src (/js/jobs-embed.js -> /jobs-embed);
 * set window.IMSJobsConfig.src to override. Older embeds using
 * <div id="hivecolab-jobs"> and window.HiveColabJobsConfig keep working.
 */
(function () {
    'use strict';
    /* window.IMSJobsConfig; window.HiveColabJobsConfig is still read for existing embeds. */
    var cfg = window.IMSJobsConfig || window.HiveColabJobsConfig || {};

    /* Default container: #ims-jobs, or the older #hivecolab-jobs (resolved in init). */
    var CONTAINER_ID  = cfg.containerId  || null;
    var MIN_HEIGHT    = cfg.minHeight    != null ? parseInt(cfg.minHeight, 10)  : 400;
    var BORDER_RADIUS = cfg.borderRadius || '20px';   /* matches --radius-xl */
    var THEME         = cfg.theme        || 'light';
    var ACCENT        = cfg.accent       || '';
    var COMPACT       = cfg.compact      ? 1 : 0;
    var LIMIT         = cfg.limit        != null ? parseInt(cfg.limit,  10)     : 0;
    var TYPE          = cfg.type         || '';
    var DEPT          = cfg.dept         || '';
    var SHOW_SEARCH   = cfg.showSearch   !== false ? 1 : 0;
    var SHOW_FILTER   = cfg.showFilter   !== false ? 1 : 0;
    var SHOW_HEADER   = cfg.showHeader   !== false ? 1 : 0;
    var APPLY_BASE    = cfg.applyBase    || '';

 
    var TOKENS = {
        light: {
            surfacePage:  '#f6f7f9',   /* --surface-page  */
            surfaceCard:  '#ffffff',   /* --surface-card  */
            ink100:       '#d1d5db',   /* --ink-100       */
            ink200:       '#9ca3af',   /* --ink-200       */
            ink300:       '#6b7280',   /* --ink-300       */
            brand500:     '#64748b',   /* neutral; the widget itself uses the site theme */
            shadow:       '0 4px 24px rgba(13,17,23,.10), 0 1px 4px rgba(13,17,23,.06)',
        },
        dark: {
            surfacePage:  '#0f0b07',
            surfaceCard:  '#1a1410',
            ink100:       '#3a3028',
            ink200:       '#5a5048',
            ink300:       '#7a6e62',
            brand500:     '#64748b',
            shadow:       '0 4px 32px rgba(0,0,0,.45), 0 1px 6px rgba(0,0,0,.30)',
        },
    };

    var T           = TOKENS[THEME] || TOKENS.light;
    var ACCENT_COLOR = ACCENT || T.brand500;
    var BG           = T.surfacePage;

    /* -- Auto-detect base src ------------------------------------ */
    var BASE_SRC = cfg.src;
    if (!BASE_SRC) {
        var scripts = document.getElementsByTagName('script');
        var me      = scripts[scripts.length - 1];
        BASE_SRC    = me.src.replace(/(js\/)?jobs-embed\.js(\?.*)?$/, 'jobs-embed');
    }

    /* -- Build iframe query string ------------------------------- */
    function buildSrc() {
        var p = [];
        p.push('theme='       + encodeURIComponent(THEME));
        p.push('compact='     + COMPACT);
        p.push('limit='       + LIMIT);
        p.push('show_search=' + SHOW_SEARCH);
        p.push('show_filter=' + SHOW_FILTER);
        p.push('show_header=' + SHOW_HEADER);
        if (TYPE)       p.push('type='       + encodeURIComponent(TYPE));
        if (DEPT)       p.push('dept='       + encodeURIComponent(DEPT));
        if (ACCENT)     p.push('accent='     + encodeURIComponent(ACCENT));
        if (APPLY_BASE) p.push('apply_base=' + encodeURIComponent(APPLY_BASE));
        return BASE_SRC + '?' + p.join('&');
    }

    /* -- Inject keyframes once into host page -------------------- */
    function injectKeyframes() {
        if (document.getElementById('hcj-kf')) return;
        var s       = document.createElement('style');
        s.id        = 'hcj-kf';
        s.textContent = [
            /* Spinner rotation */
            '@keyframes hcj-spin{',
            '  to{transform:rotate(360deg)}',
            '}',
            /* Loader fade-out */
            '@keyframes hcj-fade-out{',
            '  from{opacity:1}to{opacity:0}',
            '}',
            /* iframe fade-in — matches jobs-listing.css @keyframes rowIn */
            '@keyframes hcj-fade-in{',
            '  from{opacity:0;transform:translateY(10px)}',
            '  to{opacity:1;transform:translateY(0)}',
            '}',
        ].join('');
        document.head.appendChild(s);
    }

    /* -- Build the loading overlay ------------------------------- */
    function makeLoader() {
        /* Outer overlay — fills the container */
        var overlay      = document.createElement('div');
        overlay.id       = 'hcj-loader-' + CONTAINER_ID;
        overlay.setAttribute('aria-label', 'Loading job listings');
        overlay.setAttribute('role', 'status');
        overlay.style.cssText = [
            'position:absolute',
            'inset:0',
            'display:flex',
            'flex-direction:column',
            'align-items:center',
            'justify-content:center',
            'gap:16px',

            /* surface-card background with a subtle top brand stripe */
            'background:' + T.surfaceCard,
            'border-radius:inherit',
            'z-index:3',
            'transition:opacity .35s ease',
        ].join(';');

        /* Brand accent top bar (mirrors .job-row.featured::before aesthetic) */
        var bar       = document.createElement('div');
        bar.style.cssText = [
            'position:absolute',
            'top:0', 'left:0', 'right:0',
            'height:3px',
            'background:' + ACCENT_COLOR,
            'border-radius:' + BORDER_RADIUS + ' ' + BORDER_RADIUS + ' 0 0',
        ].join(';');
        overlay.appendChild(bar);

        /* SVG spinner — uses the same stroke colour as --brand-500 */
        var spinWrap       = document.createElement('div');
        spinWrap.style.cssText = [
            'width:48px', 'height:48px',
            'border-radius:50%',

            /* brand-50 background circle — matches .widget-empty-icon */
            'background:color-mix(in srgb,' + ACCENT_COLOR + ' ' + (THEME === 'dark' ? '12%' : '8%') + ',transparent)',
            'display:flex', 'align-items:center', 'justify-content:center',
        ].join(';');

        var svg = [
            '<svg width="28" height="28" viewBox="0 0 28 28" fill="none"',
            ' aria-hidden="true"',
            ' style="animation:hcj-spin .85s linear infinite">',
            '<circle cx="14" cy="14" r="11"',
            ' stroke="' + ACCENT_COLOR + '"',
            ' stroke-width="2.5"',
            ' stroke-dasharray="54 18"',
            ' stroke-linecap="round"/>',
            '</svg>',
        ].join('');
        spinWrap.innerHTML = svg;
        overlay.appendChild(spinWrap);

        /* Label — mirrors .widget-count font treatment */
        var label       = document.createElement('span');
        label.textContent = 'Loading positions...';
        label.style.cssText = [
            'font-family:\'Plus Jakarta Sans\',system-ui,sans-serif',
            'font-size:12px',
            'font-weight:600',
            'letter-spacing:.03em',
            'color:' + T.ink300,
        ].join(';');
        overlay.appendChild(label);

        return overlay;
    }

    /* -- Build the container shell ------------------------------- */
    function styleContainer(container) {
     
        container.style.cssText = [
            'position:relative',
            'width:100%',
            'min-height:' + MIN_HEIGHT + 'px',
            'background:' + T.surfaceCard,
            'border:1.5px solid ' + (THEME === 'dark' ? T.ink100 : 'rgba(0,0,0,.05)'),
            'border-radius:' + BORDER_RADIUS,
            'overflow:hidden',
            'box-shadow:' + T.shadow,

            /* Smooth height transitions as iframe auto-resizes */
            'transition:min-height .3s ease, box-shadow .2s ease',
        ].join(';');
    }

    /* -- Build the iframe ---------------------------------------- */
    function makeIframe() {
        var iframe       = document.createElement('iframe');
        iframe.src       = buildSrc();
        iframe.title     = cfg.title || 'Job opportunities';
        iframe.setAttribute('frameborder', '0');
        iframe.setAttribute('scrolling', 'no');
        iframe.setAttribute('loading', 'eager');
        iframe.allow     = 'fullscreen';

        /* Start invisible; fade in on load */
        iframe.style.cssText = [
            'display:block',
            'width:100%',
            'border:none',
            'min-height:' + MIN_HEIGHT + 'px',
            'border-radius:' + BORDER_RADIUS,
            'opacity:0',
            'transition:opacity .4s ease',
        ].join(';');

        return iframe;
    }

    /* -- Wire the load event ------------------------------------- */
    function onIframeLoad(iframe, loader) {
        /* Fade iframe in */
        iframe.style.opacity = '1';

        /* Fade loader out then remove it */
        if (loader) {
            loader.style.opacity = '0';
            loader.style.pointerEvents = 'none';
            setTimeout(function () {
                if (loader.parentNode) loader.parentNode.removeChild(loader);
            }, 400);
        }
    }

    /* -- Listen for height messages from the widget -------------- */
    function listenForResize(iframe, container) {
        window.addEventListener('message', function (evt) {
            if (!evt.data || (evt.data.type !== 'ims-jobs-height' && evt.data.type !== 'hivecolab-jobs-height')) return;
            if (iframe.contentWindow && evt.source !== iframe.contentWindow) return;

            /* Validate origin when possible */
            try {
                var iframeSrc = new URL(iframe.src);
                var msgOrigin = new URL(evt.origin || '');
                if (iframeSrc.hostname !== msgOrigin.hostname) return;
            } catch (e) {
                /* cross-origin or invalid URL — still resize safely */
            }

            var h = Math.max(parseInt(evt.data.height, 10) || 0, MIN_HEIGHT);

            iframe.style.height    = h + 'px';
            iframe.style.minHeight = h + 'px';
            container.style.minHeight = h + 'px';
        });
    }

    /* -- Main init ----------------------------------------------- */
    function init() {
        var container = CONTAINER_ID
            ? document.getElementById(CONTAINER_ID)
            : (document.getElementById('ims-jobs') || document.getElementById('hivecolab-jobs'));
        if (!container) {
            console.warn(
                '[IMS jobs widget] No element with id="' + (CONTAINER_ID || 'ims-jobs') + '" found.',
                'Add <div id="' + (CONTAINER_ID || 'ims-jobs') + '"></div> to your page.'
            );
            return;
        }
        CONTAINER_ID = container.id;

        injectKeyframes();
        styleContainer(container);

        var loader = makeLoader();
        container.appendChild(loader);

        var iframe = makeIframe();
        iframe.addEventListener('load', function () {
            onIframeLoad(iframe, loader);
        });
        container.appendChild(iframe);

        listenForResize(iframe, container);
    }

    /* -- Run after DOM is ready ---------------------------------- */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();