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
 *   WMUI.tuckHelp(root), WMUI.watchHelp(box)
 *                               long help behind i buttons (.info-tip), whose
 *                               tip shows on hover, focus or a tap
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

    /**
     * The clock options of Intl for a clock (F51): 24h -> hourCycle h23,
     * 12h -> hour12. The clock is the one given, else the page's
     * (<html data-clock>, resolved by the server from the language and the
     * settings); none: the language's own.
     */
    function clockOptions(clock) {
        clock = clock || document.documentElement.getAttribute('data-clock') || '';
        return clock === '24h' ? { hourCycle: 'h23' } : clock === '12h' ? { hour12: true } : {};
    }

    /**
     * The one browser formatter of times (twin of App\Services\ClockFormat):
     * in the language of the interface (not the browser's), the clock of
     * the map and its time zone. opts: zone, clock, seconds (true), date
     * (true: day and month; 'full': weekday, day, month and year;
     * 'weekday': short weekday), plus any Intl option.
     */
    function formatTime(seconds, opts) {
        opts = opts || {};
        var o = { hour: '2-digit', minute: '2-digit' };
        if (opts.seconds) { o.second = '2-digit'; }
        if (opts.date === 'full') { o.weekday = 'long'; o.day = 'numeric'; o.month = 'long'; o.year = 'numeric'; }
        else if (opts.date === 'weekday') { o.weekday = 'short'; }
        else if (opts.date) { o.day = 'numeric'; o.month = 'short'; o.year = 'numeric'; }
        Object.keys(opts).forEach(function (k) {
            if (['zone', 'clock', 'seconds', 'date'].indexOf(k) < 0) { o[k] = opts[k]; }
        });
        Object.assign(o, clockOptions(opts.clock));
        if (opts.zone) { o.timeZone = opts.zone; }
        var lang = document.documentElement.lang || undefined, at = new Date(seconds * 1000);
        try {
            return new Intl.DateTimeFormat(lang, o).format(at);
        } catch (e) {
            delete o.timeZone; // a zone the browser does not know
            return new Intl.DateTimeFormat(lang, o).format(at);
        }
    }

    // ------------------------------------------------------------------
    // Info tips (F24, F69, F73): an i button (.info-tip, data-tip = the id
    // of its text) shows its text in a bubble on hover, on keyboard focus
    // and on a tap. tuckHelp(root) puts the long help of a panel behind such
    // buttons: the explanation of a section of the map properties next to
    // its title, a help line of a field next to the field's name. The help
    // element stays in the page, hidden, as the text of its tip, so code
    // that updates it still does. Short hints, notes and warnings stay.

    var HINT_MIN = 40;
    var tipSeq = 0, bubble = null, bubbleFor = null;

    function fmt(s, params) {
        return t(s).replace(/:(\w+)/g, function (m, k) { return params && k in params ? params[k] : m; });
    }

    function infoButton(text, name) {
        if (!text.id) { text.id = 'ui-tip-' + (++tipSeq); }
        text.hidden = true;
        text.classList.add('tip-text');
        text.setAttribute('data-tucked', '');
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'info-tip';
        b.setAttribute('data-tip', text.id);
        b.setAttribute('aria-label', fmt('About :name', { name: name }));
        b.setAttribute('aria-describedby', text.id);
        b.textContent = 'i';
        return b;
    }

    /** The name of a label: its tip name, its bold name, else its own text */
    function labelName(label) {
        var tn = label.querySelector('.tip-name > span, strong');
        if (tn) { return tn.textContent.trim(); }
        for (var n = label.firstChild; n; n = n.nextSibling) {
            if (n.nodeType === 3 && n.textContent.trim()) { return n.textContent.trim(); }
        }
        return label.textContent.trim().slice(0, 40);
    }

    function tuckHelp(root) {
        if (!root || !root.querySelectorAll) { return; }
        root.querySelectorAll('.mapprops-explain:not([data-tucked])').forEach(function (p) {
            var sec = p.closest('details.map-sec');
            var sum = sec ? sec.querySelector(':scope > summary') : null;
            if (!sum || !sum.firstElementChild) { return; }
            // one button per section; a section drawn again gives it its new text
            var old = sum.querySelector('.info-tip');
            if (old) {
                infoButton(p, '');
                old.setAttribute('data-tip', p.id);
                old.setAttribute('aria-describedby', p.id);
                return;
            }
            sum.firstElementChild.after(infoButton(p, sum.firstElementChild.textContent.trim()));
        });
        // the help lines of the editor's panels, and those of the Map panel's fields
        root.querySelectorAll('span.hint:not([data-tucked]), .form-group > small.text-muted:not([data-tucked]), label small.text-muted:not([data-tucked]), fieldset > small.text-muted:not([data-tucked])').forEach(function (hint) {
            if (hint.textContent.trim().length < HINT_MIN) { return; }
            var parent = hint.parentNode;
            // a choice between radio buttons: next to its question
            if (parent.tagName === 'FIELDSET') {
                var legend = parent.querySelector(':scope > legend');
                if (legend) { legend.appendChild(infoButton(hint, legend.textContent.trim().slice(0, 40))); }
                return;
            }
            var label = hint.closest('label');
            if (!label) {
                var prev = hint.previousElementSibling;
                label = prev && prev.tagName === 'LABEL' ? prev : null;
            }
            if (!label && parent.classList.contains('form-group')) {
                label = parent.querySelector(':scope > label');
            }
            if (!label || !label.firstChild) { return; }
            var btn = infoButton(hint, labelName(label));
            var tn = label.querySelector(':scope > .tip-name');
            if (tn) { tn.appendChild(btn); return; }
            // a switch: after its bold name
            var strong = label.querySelector('strong');
            if (strong) { strong.after(' ', btn); return; }
            // after the name of the label, before its control
            var first = label.firstChild;
            if (first.nodeType === 3 || (first.nodeType === 1 && !/^(INPUT|SELECT|TEXTAREA|BUTTON)$/.test(first.tagName))) {
                var wrap = document.createElement('span');
                wrap.className = 'tip-name';
                label.insertBefore(wrap, first);
                wrap.appendChild(first);
                wrap.appendChild(btn);
            } else {
                label.appendChild(btn);
            }
        });
    }

    /** Tuck the help of a box now and whenever it is drawn again */
    function watchHelp(box) {
        if (!box) { return; }
        tuckHelp(box);
        if (!window.MutationObserver) { return; }
        var pending = false;
        new MutationObserver(function () {
            if (pending) { return; }
            pending = true;
            // once the panel is drawn, before it is painted
            Promise.resolve().then(function () { pending = false; tuckHelp(box); });
        }).observe(box, { childList: true, subtree: true });
    }

    function showTip(btn) {
        var src = document.getElementById(btn.getAttribute('data-tip'));
        if (!src) { return; }
        if (!bubble) {
            bubble = document.createElement('div');
            bubble.id = 'info-bubble';
            bubble.setAttribute('role', 'tooltip');
            bubble.hidden = true;
            document.body.appendChild(bubble);
        }
        bubble.textContent = src.textContent;
        bubble.hidden = false;
        bubbleFor = btn;
        var r = btn.getBoundingClientRect(), b = bubble.getBoundingClientRect();
        var left = Math.max(8, Math.min(r.left + r.width / 2 - b.width / 2, window.innerWidth - b.width - 8));
        var top = r.bottom + 6;
        if (top + b.height > window.innerHeight - 8) { top = r.top - b.height - 6; }
        bubble.style.left = left + 'px';
        bubble.style.top = Math.max(8, top) + 'px';
    }

    function hideTip() {
        if (bubble) { bubble.hidden = true; }
        bubbleFor = null;
    }

    function tipButton(target) {
        return target && target.closest ? target.closest('.info-tip') : null;
    }

    document.addEventListener('mouseover', function (evt) {
        var b = tipButton(evt.target);
        if (b && b !== bubbleFor) { showTip(b); }
    });
    document.addEventListener('mouseout', function (evt) {
        var b = tipButton(evt.target);
        if (b && b === bubbleFor && !b.contains(evt.relatedTarget) && document.activeElement !== b) { hideTip(); }
    });
    document.addEventListener('focusin', function (evt) {
        var b = tipButton(evt.target);
        if (b) { showTip(b); } else if (bubbleFor) { hideTip(); }
    });
    document.addEventListener('focusout', function (evt) {
        if (bubbleFor && evt.target === bubbleFor) { hideTip(); }
    });
    // a tap shows it too (touch screens have no hover); never a label click
    document.addEventListener('click', function (evt) {
        var b = tipButton(evt.target);
        if (b) { evt.preventDefault(); showTip(b); }
    });
    // the panel scrolls (also when focus brings the button into view): follow the button
    document.addEventListener('scroll', function () {
        if (!bubbleFor || !bubble || bubble.hidden) { return; }
        if (document.contains(bubbleFor)) { showTip(bubbleFor); } else { hideTip(); }
    }, true);

    window.WMUI = {
        toast: toast, confirm: confirmDialog, drawer: drawer, time: formatTime, clockOptions: clockOptions,
        tuckHelp: tuckHelp, watchHelp: watchHelp, hideTip: hideTip,
        tipShown: function () { return !!(bubble && !bubble.hidden); }
    };

    function ready() {
        document.querySelectorAll('#wm-toasts .wm-toast').forEach(arm);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
})();
