/**
 * NetworkWeathermapNG - the Map settings panel (UI redesign step 5).
 *
 * - Tabs (General, Display & kiosk, Recording, Access) on the panel, with
 *   the arrow keys of a tab list. Without this script the four sections
 *   follow each other on the page.
 * - Links marked data-map-settings (the ⋯ menu of a map, the settings
 *   button of the viewer) open the panel in a drawer on the right instead of
 *   leaving the page: GET <href>&panel=1, saved with fetch; on success the
 *   page reloads and shows the saved message. Any failure to load falls
 *   back to the page itself.
 * - Choosing between a name and a drawn title that differ fills the Name
 *   field with the chosen one.
 */
(function () {
    'use strict';

    function t(s) { return (window.WM_T && window.WM_T[s]) || s; }

    function setupTabs(form) {
        const list = form.querySelector('.ms-tabs');
        if (!list) return;
        const tabs = Array.from(list.querySelectorAll('[role="tab"]'));
        const panels = tabs.map(function (tab) { return form.querySelector('#' + tab.getAttribute('aria-controls')); });
        list.hidden = false;
        form.classList.add('has-tabs');

        function select(i, focus) {
            tabs.forEach(function (tab, k) {
                const on = k === i;
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
                tab.tabIndex = on ? 0 : -1;
                if (panels[k]) panels[k].hidden = !on;
            });
            if (focus) tabs[i].focus();
        }
        tabs.forEach(function (tab, i) {
            tab.addEventListener('click', function () { select(i, false); });
            tab.addEventListener('keydown', function (e) {
                let to = null;
                if (e.key === 'ArrowRight') to = (i + 1) % tabs.length;
                if (e.key === 'ArrowLeft') to = (i - 1 + tabs.length) % tabs.length;
                if (e.key === 'Home') to = 0;
                if (e.key === 'End') to = tabs.length - 1;
                if (to !== null) { e.preventDefault(); select(to, true); }
            });
        });
        // a field the browser wants to show (invalid on submit) opens its tab
        form.addEventListener('invalid', function (e) {
            const k = panels.findIndex(function (p) { return p && p.contains(e.target); });
            if (k >= 0) select(k, false);
        }, true);
        const wanted = tabs.findIndex(function (tab) { return tab.dataset.tab === form.dataset.tab; });
        select(wanted >= 0 ? wanted : 0, false);
    }

    function setupConflict(form) {
        const name = form.querySelector('input[name="name"]');
        form.querySelectorAll('input[name="keep"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (radio.checked && name) name.value = radio.dataset.name;
            });
        });
    }

    function setup(form) {
        if (form.dataset.ready) return;
        form.dataset.ready = '1';
        setupTabs(form);
        setupConflict(form);
    }

    // ------------------------------------------------------------------
    // Drawer

    let drawer = null;
    let opener = null;

    function getDrawer() {
        if (drawer) return drawer;
        drawer = document.createElement('dialog');
        drawer.className = 'wm-drawer';
        drawer.setAttribute('aria-labelledby', 'ms-title');
        document.body.appendChild(drawer);
        drawer.addEventListener('click', function (e) {
            // a click on the backdrop, or on Close or Cancel
            if (e.target === drawer || e.target.closest('[data-ms-close]')) {
                e.preventDefault();
                drawer.close();
            }
        });
        drawer.addEventListener('close', function () {
            drawer.innerHTML = '';
            if (opener && opener.isConnected) opener.focus();
        });
        return drawer;
    }

    function panelUrl(href) {
        const url = new URL(href, window.location.href);
        url.searchParams.set('panel', '1');
        return url;
    }

    function open(link) {
        const d = getDrawer();
        opener = link;
        d.innerHTML = '<p class="wm-drawer-loading">' + t('Loading…') + '</p>';
        if (!d.open) d.showModal();
        fetch(panelUrl(link.href), {credentials: 'same-origin', headers: {Accept: 'text/html'}})
            .then(function (r) {
                if (!r.ok || r.redirected) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                d.innerHTML = html;
                const form = d.querySelector('[data-map-settings-form]');
                if (!form) throw new Error('no form');
                if (link.dataset.tab) form.dataset.tab = link.dataset.tab;
                setup(form);
                form.addEventListener('submit', submit);
                const first = form.querySelector('[role="tab"][aria-selected="true"]') || form.querySelector('input:not([type="hidden"])');
                if (first) first.focus();
            })
            .catch(function () {
                window.location.href = link.href;
            });
    }

    function submit(e) {
        const form = e.currentTarget;
        e.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        if (button) button.disabled = true;
        fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {Accept: 'application/json'},
            body: new FormData(form)
        }).then(function (r) {
            return r.json().catch(function () { return {success: false}; });
        }).then(function (data) {
            if (!data.success) throw new Error(data.error || t('The settings could not be saved'));
            // the saved message is waiting for the reloaded page
            window.location.reload();
        }).catch(function (err) {
            if (button) button.disabled = false;
            if (window.WMUI) WMUI.toast(err.message || t('The settings could not be saved'), 'error');
        });
    }

    document.addEventListener('click', function (e) {
        const link = e.target.closest ? e.target.closest('a[data-map-settings]') : null;
        if (!link || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
        if (typeof HTMLDialogElement === 'undefined') return;
        e.preventDefault();
        const menu = link.closest('details');
        if (menu) menu.open = false;
        open(link);
    });

    function ready() {
        document.querySelectorAll('[data-map-settings-form]').forEach(setup);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
})();
