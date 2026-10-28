/*
 * Application widget.
 * Embed on a partner site:
 *   <div id="ims-apply"></div>
 *   <script src="https://<ims-host>/js/apply-embed.js"></script>
 * The iframe URL is derived from this script's src (/js/apply-embed.js -> /apply-embed);
 * set window.IMSApplyConfig.src to override. Older embeds using
 * <div id="hivecolab-apply"> and window.HiveColabConfig keep working.
 */


(function () {
    'use strict';

    /* ---------- config ---------- */
    /* window.IMSApplyConfig; window.HiveColabConfig is still read for existing embeds. */
    var cfg = window.IMSApplyConfig || window.HiveColabConfig || {};
    /* Default container: #ims-apply, or the older #hivecolab-apply. */
    var CONTAINER_ID  = cfg.containerId  || null;
    var ACCENT        = cfg.accentColor  || '#64748b';
    var MIN_HEIGHT    = cfg.minHeight    || 400;
    var BORDER_RADIUS = cfg.borderRadius || '16px';
    var BACKGROUND    = cfg.background   || '#FFF8F2';

    /* Auto-detect the src from the script tag if not provided */
    var SRC = cfg.src;
    if (!SRC) {
        var scripts = document.getElementsByTagName('script');
        var thisScript = scripts[scripts.length - 1];
        SRC = thisScript.src.replace(/(js\/)?apply-embed\.js(\?.*)?$/, 'apply-embed');
    }

    /* ---------- find / create container ---------- */
    function init() {
        var container = CONTAINER_ID
            ? document.getElementById(CONTAINER_ID)
            : (document.getElementById('ims-apply') || document.getElementById('hivecolab-apply'));
        if (!container) {
            console.warn('[IMS apply widget] No element with id="' + (CONTAINER_ID || 'ims-apply') + '" found.');
            return;
        }

        /* --- loading placeholder --- */
        container.style.cssText = [
            'position:relative',
            'width:100%',
            'min-height:' + MIN_HEIGHT + 'px',
            'background:' + BACKGROUND,
            'border-radius:' + BORDER_RADIUS,
            'overflow:hidden',
            'box-shadow:0 4px 24px rgba(26,18,8,.08)',
        ].join(';');

        var loader = document.createElement('div');
        loader.id  = 'hc-loader';
        loader.style.cssText = [
            'position:absolute',
            'inset:0',
            'display:flex',
            'flex-direction:column',
            'align-items:center',
            'justify-content:center',
            'gap:14px',
            'font-family:system-ui,sans-serif',
            'font-size:14px',
            'color:' + ACCENT,
            'background:' + BACKGROUND,
            'z-index:2',
            'transition:opacity .4s',
        ].join(';');
        loader.innerHTML = '<svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" style="animation:hc-spin 1s linear infinite"><circle cx="18" cy="18" r="15" stroke="' + ACCENT + '" stroke-width="3" stroke-dasharray="70 24" stroke-linecap="round"/></svg><span>Loading opportunities...</span>';
        container.appendChild(loader);

        /* spin keyframes */
        if (!document.getElementById('hc-keyframes')) {
            var style = document.createElement('style');
            style.id = 'hc-keyframes';
            style.textContent = '@keyframes hc-spin{to{transform:rotate(360deg)}}';
            document.head.appendChild(style);
        }

        /* --- iframe --- */
        var iframe = document.createElement('iframe');
        iframe.src             = SRC;
        iframe.title           = cfg.title || 'Apply for programs';
        iframe.allow           = 'fullscreen';
        iframe.setAttribute('scrolling', 'no');
        iframe.setAttribute('frameborder', '0');
        iframe.style.cssText = [
            'display:block',
            'width:100%',
            'border:none',
            'min-height:' + MIN_HEIGHT + 'px',
            'border-radius:' + BORDER_RADIUS,
            'opacity:0',
            'transition:opacity .5s',
        ].join(';');

        /* hide loader once iframe loads */
        iframe.addEventListener('load', function () {
            iframe.style.opacity = '1';
            var ld = document.getElementById('hc-loader');
            if (ld) { ld.style.opacity = '0'; setTimeout(function(){ ld.remove(); }, 450); }
        });

        container.appendChild(iframe);

        /* --- auto-resize via postMessage --- */
        window.addEventListener('message', function (event) {
            if (iframe.contentWindow && event.source !== iframe.contentWindow) return;
            if (event.data && (event.data.type === 'ims-apply-height' || event.data.type === 'hivecolab-height')) {
                var h = Math.max(parseInt(event.data.height, 10), MIN_HEIGHT);
                iframe.style.height    = h + 'px';
                iframe.style.minHeight = h + 'px';
                container.style.minHeight = h + 'px';
            }
        });
    }

    /* run after DOM ready */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();