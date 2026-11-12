/* ==========================================================================
   PASSWORD SHOW/HIDE TOGGLE
   --------------------------------------------------------------------------
   Adds an eye button to every <input type="password"> on the page, including
   fields added later (modals, dynamic forms). Loaded by accessibility.js, so
   any page that includes that script gets it.

   Fields that already have their own toggle button next to them are left
   alone. Opt a field out with data-password-toggle="off".
   ========================================================================== */
(function () {
    'use strict';

    if (window.IMSPasswordToggle) return;

    var EYE = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var EYE_OFF = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    var CSS = ''
        + '.ims-pw-wrap{position:relative;display:block;width:100%;}'
        + '.ims-pw-wrap>input{width:100%;padding-right:44px !important;box-sizing:border-box;}'
        + '.ims-pw-btn{position:absolute;top:0;right:0;bottom:0;width:42px;display:flex;align-items:center;justify-content:center;'
        + 'padding:0;margin:0;border:0;background:transparent;color:#6b7280;cursor:pointer;border-radius:0 6px 6px 0;}'
        + '.ims-pw-btn:hover{color:#111827;}'
        + '.ims-pw-btn:focus-visible{outline:2px solid #2563eb;outline-offset:-2px;}'
        + '@media (prefers-color-scheme:dark){.ims-pw-btn{color:#9ca3af;}.ims-pw-btn:hover{color:#f3f4f6;}}'
        + 'input.ims-pw-input::-ms-reveal,input.ims-pw-input::-ms-clear{display:none;}';

    var uid = 0;

    function injectCss() {
        if (document.querySelector('style[data-ims-pw]')) return;
        var style = document.createElement('style');
        style.setAttribute('data-ims-pw', '');
        style.textContent = CSS;
        (document.head || document.documentElement).appendChild(style);
    }

    // A page-specific toggle already sits next to this field.
    function hasOwnToggle(input) {
        var parent = input.parentElement;
        if (!parent) return false;
        var buttons = parent.querySelectorAll('button, [role="button"]');
        for (var i = 0; i < buttons.length; i++) {
            var b = buttons[i];
            if (b.classList.contains('ims-pw-btn')) continue;
            var sig = (b.className || '') + ' ' + (b.getAttribute('onclick') || '') + ' '
                + (b.getAttribute('aria-label') || '') + ' ' + (b.getAttribute('title') || '');
            if (/toggle|show|hide|reveal|secret|password/i.test(sig)
                || b.hasAttribute('data-toggle-password')
                || b.hasAttribute('data-password-target')) {
                return true;
            }
        }
        return false;
    }

    function setState(input, button, visible) {
        input.type = visible ? 'text' : 'password';
        button.innerHTML = visible ? EYE_OFF : EYE;
        button.setAttribute('aria-pressed', visible ? 'true' : 'false');
        button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        button.title = visible ? 'Hide password' : 'Show password';
    }

    function enhance(input) {
        if (input.dataset.imsPw === '1') return;
        if ((input.getAttribute('data-password-toggle') || '').toLowerCase() === 'off') return;
        if (hasOwnToggle(input)) {
            input.dataset.imsPw = '1';
            return;
        }
        input.dataset.imsPw = '1';

        if (!input.id) {
            input.id = 'ims-pw-' + (++uid);
        }

        var wrap = document.createElement('span');
        wrap.className = 'ims-pw-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        input.classList.add('ims-pw-input');

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'ims-pw-btn';
        button.setAttribute('aria-controls', input.id);
        setState(input, button, false);

        button.addEventListener('click', function () {
            var start = input.selectionStart;
            var end = input.selectionEnd;
            setState(input, button, input.type === 'password');
            input.focus();
            try { input.setSelectionRange(start, end); } catch (e) { /* not supported */ }
        });

        wrap.appendChild(button);

        // Always submit (and let browsers remember) the field as a password.
        if (input.form && !input.form.dataset.imsPwSubmit) {
            input.form.dataset.imsPwSubmit = '1';
            input.form.addEventListener('submit', function () {
                var shown = this.querySelectorAll('.ims-pw-wrap > input.ims-pw-input');
                for (var i = 0; i < shown.length; i++) {
                    var btn = shown[i].parentNode.querySelector('.ims-pw-btn');
                    if (btn) setState(shown[i], btn, false);
                }
            });
        }
    }

    function scan(root) {
        if (!root || !root.querySelectorAll) return;
        if (root.matches && root.matches('input[type="password"]')) {
            enhance(root);
        }
        var inputs = root.querySelectorAll('input[type="password"]');
        for (var i = 0; i < inputs.length; i++) {
            enhance(inputs[i]);
        }
    }

    function start() {
        injectCss();
        scan(document);
        if (window.MutationObserver) {
            new MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    var added = mutations[i].addedNodes;
                    for (var j = 0; j < added.length; j++) {
                        if (added[j].nodeType === 1) scan(added[j]);
                    }
                }
            }).observe(document.body || document.documentElement, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    window.IMSPasswordToggle = { refresh: function () { scan(document); } };
})();
