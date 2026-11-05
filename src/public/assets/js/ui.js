/*
 * NetworkWeathermapNG - shared interface pieces of the application shell
 *
 *   WMUI.toast(message, type)   a short message at the bottom of the page
 *                               (type: success, error, info); returns the element
 *   WMUI.confirm(options)       a styled confirmation dialog instead of
 *                               window.confirm(); resolves to true or false
 *       options: { message, title, confirm (button label), cancel, danger }
 *   WMUI.drawer(href, opener, onLoad)
 *                               a panel on the right: loads href?panel=1 into a
 *                               dialog and calls onLoad(dialog); when the page
 *                               cannot be loaded, or onLoad throws, the browser
 *                               goes to href instead. Buttons and links marked
 *                               data-drawer-close close it.
 *
 * Links marked data-drawer open their page in the drawer; its form is posted
 * the usual way. The drawer fires "wm:drawer-load" once its content is in.
 *
 * Toasts already in the page (flash messages written by the server in
 * #wm-toasts) get their close button and time out like the others.
 * Texts come from window.WM_T (the ui-js catalogue), English otherwise.
 */
(function () {
    'use strict';

    function t(s) { return (window.WM_T && window.WM_T[s]) || s; }

    var DELAY = { success: 5000, info: 6000, error: 10000 };

    function region() {
        var r = document.getElementById('wm-toasts');
        if (!r) {
            r = document.createElement('div');
            r.id = 'wm-toasts';
            r.className = 'wm-toasts';
            document.body.appendChild(r);
        }
        return r;
    }

    function dismiss(el) {
        if (!el.isConnected || el.classList.contains('is-leaving')) { return; }
        el.classList.add('is-leaving');
        setTimeout(function () { el.remove(); }, 200);
    }

    // close button, time-out that pauses while the pointer or the focus is on the toast
    function arm(el) {
        if (el.dataset.armed) { return; }
        el.dataset.armed = '1';
        var close = el.querySelector('.wm-toast-close');
        if (!close) {
            close = document.createElement('button');
            close.type = 'button';
            close.className = 'wm-toast-close';
            close.setAttribute('aria-label', t('Dismiss'));
            close.textContent = '×';
            el.appendChild(close);
        }
        close.addEventListener('click', function () { dismiss(el); });
        var type = el.dataset.type || 'info';
        var timer = null;
        function start() { timer = setTimeout(function () { dismiss(el); }, DELAY[type] || DELAY.info); }
        function stop() { clearTimeout(timer); }
        el.addEventListener('mouseenter', stop);
        el.addEventListener('mouseleave', start);
        el.addEventListener('focusin', stop);
        el.addEventListener('focusout', start);
        start();
    }

    function toast(message, type) {
        type = DELAY[type] ? type : 'info';
        var el = document.createElement('div');
        el.className = 'wm-toast wm-toast-' + type;
        el.dataset.type = type;
        // errors interrupt a screen reader, the others wait their turn
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        var text = document.createElement('span');
        text.className = 'wm-toast-text';
        text.textContent = String(message);
        el.appendChild(text);
        region().appendChild(el);
        arm(el);
        return el;
    }

    var dialog = null;

    function buildDialog() {
        dialog = document.createElement('dialog');
        dialog.className = 'wm-confirm';
        dialog.setAttribute('aria-labelledby', 'wm-confirm-title');
        dialog.setAttribute('aria-describedby', 'wm-confirm-message');
        dialog.innerHTML = '<h2 id="wm-confirm-title"></h2><p id="wm-confirm-message"></p>'
            + '<div class="wm-confirm-actions"><button type="button" class="wm-btn" data-answer="no"></button>'
            + '<button type="button" class="wm-btn" data-answer="yes"></button></div>';
        document.body.appendChild(dialog);
        return dialog;
    }

    function confirmDialog(options) {
        options = typeof options === 'string' ? { message: options } : (options || {});
        if (typeof HTMLDialogElement === 'undefined' || !document.body) {
            return Promise.resolve(window.confirm(options.message || ''));
        }
        var d = dialog || buildDialog();
        if (d.open) { d.close(); }
        var yes = d.querySelector('[data-answer="yes"]');
        var no = d.querySelector('[data-answer="no"]');
        var title = d.querySelector('#wm-confirm-title');
        title.textContent = options.title || '';
        title.hidden = !options.title;
        if (!options.title) { d.setAttribute('aria-labelledby', 'wm-confirm-message'); } else { d.setAttribute('aria-labelledby', 'wm-confirm-title'); }
        d.querySelector('#wm-confirm-message').textContent = options.message || '';
        yes.textContent = options.confirm || t('Confirm');
        no.textContent = options.cancel || t('Cancel');
        yes.className = 'wm-btn ' + (options.danger ? 'wm-btn-danger' : 'wm-btn-primary');

        return new Promise(function (resolve) {
            function finish(answer) {
                yes.removeEventListener('click', onYes);
                no.removeEventListener('click', onNo);
                d.removeEventListener('close', onClose);
                if (d.open) { d.close(); }
                resolve(answer);
            }
            function onYes() { finish(true); }
            function onNo() { finish(false); }
            function onClose() { finish(false); } // Escape
            yes.addEventListener('click', onYes);
            no.addEventListener('click', onNo);
            d.addEventListener('close', onClose);
            d.showModal();
            // the safe answer has the focus, so Enter does not delete by accident
            no.focus();
        });
    }

    // ------------------------------------------------------------------
    // Drawer

    var drawerEl = null;
    var drawerOpener = null;

    function getDrawer() {
        if (drawerEl) { return drawerEl; }
        drawerEl = document.createElement('dialog');
        drawerEl.className = 'wm-drawer';
        document.body.appendChild(drawerEl);
        drawerEl.addEventListener('click', function (e) {
            // a click on the backdrop, or on Close or Cancel
            if (e.target === drawerEl || e.target.closest('[data-drawer-close], [data-ms-close]')) {
                e.preventDefault();
                drawerEl.close();
            }
        });
        drawerEl.addEventListener('close', function () {
            drawerEl.innerHTML = '';
            if (drawerOpener && drawerOpener.isConnected) { drawerOpener.focus(); }
        });
        return drawerEl;
    }

    function drawer(href, opener, onLoad) {
        var d = getDrawer();
        drawerOpener = opener || null;
        d.innerHTML = '<p class="wm-drawer-loading">' + t('Loading…') + '</p>';
        d.removeAttribute('aria-labelledby');
        if (!d.open) { d.showModal(); }
        var url = new URL(href, window.location.href);
        url.searchParams.set('panel', '1');
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html' } })
            .then(function (r) {
                if (!r.ok || r.redirected) { throw new Error('HTTP ' + r.status); }
                return r.text();
            })
            .then(function (html) {
                d.innerHTML = html;
                var heading = d.querySelector('h1, h2');
                if (heading) {
                    if (!heading.id) { heading.id = 'wm-drawer-title'; }
                    d.setAttribute('aria-labelledby', heading.id);
                }
                if (onLoad) { onLoad(d); }
                d.dispatchEvent(new CustomEvent('wm:drawer-load', { bubbles: true }));
            })
            .catch(function () {
                window.location.href = href;
            });
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('a[data-drawer]') : null;
        if (!link || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) { return; }
        if (typeof HTMLDialogElement === 'undefined') { return; }
        e.preventDefault();
        var menu = link.closest('details');
        if (menu) { menu.open = false; }
        drawer(link.href, link, function (d) {
            if (!d.querySelector('form')) { throw new Error('no form'); }
            var first = d.querySelector('form input:not([type="hidden"]):not([disabled]), form select, form textarea');
            if (first) { first.focus(); }
        });
    });

    window.WMUI = { toast: toast, confirm: confirmDialog, drawer: drawer };

    function ready() {
        document.querySelectorAll('#wm-toasts .wm-toast').forEach(arm);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
})();
