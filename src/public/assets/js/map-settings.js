/**
 * NetworkWeathermapNG - the Map panel (F53, map_settings.php).
 *
 * - Tabs (General, Look, Viewer & kiosk, Recording, Access) on the panel,
 *   with the arrow keys of a tab list. Without this script the sections
 *   follow each other on the page.
 * - The map editor shows the same panel in its properties panel when
 *   nothing is selected (WMMapSettings.load, editor-svg.js) and fills its
 *   data-ms-slot boxes with its own live fields; there Cancel puts the
 *   fields back and Save keeps the editor open.
 * - Links marked data-map-settings (the ⋯ menu of a map, the settings
 *   button of the viewer) open the panel in a drawer on the right instead of
 *   leaving the page: GET <href>&panel=1, saved with fetch; on success the
 *   page reloads and shows the saved message. Any failure to load falls
 *   back to the page itself. In the map editor (window.WMEditor) the page
 *   does not reload: the drawer closes and the editor redraws the map, whose
 *   drawn title may follow a new name.
 * - Choosing between a name and a drawn title that differ fills the Name
 *   field with the chosen one.
 * - Display links (F81, data-display-links on the Viewer & kiosk tab): the
 *   list of the map's links, a button that makes one (its address is shown
 *   once, with a copy button) and a Revoke button per active link. These
 *   fields have no name: they are not sent with the form.
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
            form.dataset.activeTab = tabs[i].dataset.tab;
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
        form.msSelect = function (key) {
            const k = tabs.findIndex(function (tab) { return tab.dataset.tab === key; });
            if (k >= 0) select(k, false);
        };
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

    function setupDisplayLinks(form) {
        const box = form.querySelector('[data-display-links]');
        if (!box) return;
        const base = box.dataset.displayLinks;
        const zone = box.dataset.zone || undefined;
        const list = box.querySelector('[data-dl-list]');
        const label = box.querySelector('[data-dl-label]');
        const expires = box.querySelector('[data-dl-expires]');
        const create = box.querySelector('[data-dl-create]');
        const made = box.querySelector('[data-dl-made]');
        const url = box.querySelector('[data-dl-url]');
        const copy = box.querySelector('[data-dl-copy]');

        function when(seconds) {
            return window.WMUI && WMUI.time ? WMUI.time(seconds, {zone: zone, date: true}) : new Date(seconds * 1000).toLocaleString();
        }
        function toast(message, kind) {
            if (window.WMUI) WMUI.toast(message, kind);
        }
        function call(path, body) {
            return fetch(base + path, {
                method: body ? 'POST' : 'GET',
                credentials: 'same-origin',
                headers: body ? {Accept: 'application/json', 'Content-Type': 'application/json'} : {Accept: 'application/json'},
                body: body ? JSON.stringify(body) : undefined
            }).then(function (r) {
                return r.json().catch(function () { return {success: false}; });
            }).then(function (data) {
                if (!data.success) throw new Error(data.error || t('The request failed'));
                return data;
            });
        }
        function show(links) {
            list.textContent = '';
            if (!links.length) {
                const none = document.createElement('li');
                none.className = 'text-muted';
                none.textContent = t('No display link yet');
                list.appendChild(none);
                return;
            }
            links.forEach(function (link) {
                const li = document.createElement('li');
                li.className = 'ms-dl-item ms-dl-' + link.state;
                const name = document.createElement('strong');
                name.textContent = link.label || t('Display link :n').replace(':n', link.id);
                const facts = [t('Made :date').replace(':date', when(link.created_at))];
                if (link.state === 'revoked') facts.push(t('Revoked'));
                else if (link.state === 'expired') facts.push(t('Expired'));
                else if (link.expires_at) facts.push(t('Expires :date').replace(':date', when(link.expires_at)));
                facts.push(link.last_used_at ? t('Last used :date').replace(':date', when(link.last_used_at)) : t('Never used'));
                const info = document.createElement('small');
                info.className = 'text-muted';
                info.textContent = facts.join(' · ');
                li.appendChild(name);
                li.appendChild(document.createTextNode(' '));
                li.appendChild(info);
                if (link.state === 'active') {
                    const revoke = document.createElement('button');
                    revoke.type = 'button';
                    revoke.className = 'wm-btn wm-btn-danger ms-dl-revoke';
                    revoke.textContent = t('Revoke');
                    revoke.addEventListener('click', function () { revokeLink(link); });
                    li.appendChild(revoke);
                }
                list.appendChild(li);
            });
        }
        function refresh() {
            return call('').then(function (data) { show(data.links || []); }).catch(function (err) {
                list.textContent = '';
                const li = document.createElement('li');
                li.className = 'text-muted';
                li.textContent = err.message;
                list.appendChild(li);
            });
        }
        function revokeLink(link) {
            const ask = window.WMUI && WMUI.confirm
                ? WMUI.confirm({message: t('Revoke this display link? Screens that use it stop showing the map.'), confirm: t('Revoke'), danger: true})
                : Promise.resolve(window.confirm(t('Revoke this display link? Screens that use it stop showing the map.')));
            ask.then(function (yes) {
                if (!yes) return;
                call('/' + encodeURIComponent(link.id) + '/revoke', {}).then(function () {
                    toast(t('Display link revoked'), 'success');
                    refresh();
                }).catch(function (err) { toast(err.message, 'error'); });
            });
        }
        function make() {
            create.disabled = true;
            call('', {label: label.value, expires: expires.value}).then(function (data) {
                url.value = location.origin + data.path;
                made.hidden = false;
                url.focus();
                url.select();
                label.value = '';
                expires.value = '';
                refresh();
            }).catch(function (err) {
                toast(err.message, 'error');
            }).then(function () { create.disabled = false; });
        }

        create.addEventListener('click', make);
        // Enter in the label makes the link instead of saving the panel
        label.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); make(); }
        });
        copy.addEventListener('click', function () {
            url.select();
            const done = function () { toast(t('Address copied'), 'success'); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url.value).then(done, function () { document.execCommand('copy'); done(); });
            } else {
                document.execCommand('copy');
                done();
            }
        });
        refresh();
    }

    function setup(form) {
        if (form.dataset.ready) return;
        form.dataset.ready = '1';
        setupTabs(form);
        setupConflict(form);
        setupDisplayLinks(form);
        // long help behind i buttons, as in the editor (ui.js, F73)
        if (window.WMUI && window.WMUI.tuckHelp) window.WMUI.tuckHelp(form);
        // in the editor's panel (no drawer around it), Cancel puts the fields back
        form.addEventListener('click', function (e) {
            const cancel = e.target.closest ? e.target.closest('[data-ms-close]') : null;
            if (!cancel || form.closest('dialog')) return;
            e.preventDefault();
            form.reset();
        });
    }

    /**
     * The panel of a map, for the map editor: a promise of its form, set
     * up and saved by fetch (the editor redraws after a save).
     */
    function load(mapId) {
        const url = '/editor/maps/edit/' + encodeURIComponent(mapId) + '?panel=1&return=' + encodeURIComponent('/editor/edit/' + mapId);
        return fetch(url, {credentials: 'same-origin', headers: {Accept: 'text/html'}}).then(function (r) {
            if (!r.ok || r.redirected) throw new Error('HTTP ' + r.status);
            return r.text();
        }).then(function (html) {
            const box = document.createElement('div');
            box.innerHTML = html;
            const form = box.querySelector('[data-map-settings-form]');
            if (!form) throw new Error('no form');
            // the editor's panel has its own heading and no close button
            const head = form.querySelector('.ms-head');
            if (head) head.remove();
            form.classList.add('ms-inline');
            setup(form);
            form.addEventListener('submit', submit);
            return form;
        });
    }

    // ------------------------------------------------------------------
    // Drawer (WMUI.drawer, ui.js)

    function open(link) {
        WMUI.drawer(link.href, link, function (d) {
            const form = d.querySelector('[data-map-settings-form]');
            if (!form) throw new Error('no form');
            if (link.dataset.tab) form.dataset.tab = link.dataset.tab;
            setup(form);
            form.addEventListener('submit', submit);
            const first = form.querySelector('[role="tab"][aria-selected="true"]') || form.querySelector('input:not([type="hidden"])');
            if (first) first.focus();
        });
    }

    function submit(e) {
        const form = e.currentTarget;
        e.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        if (button) button.disabled = true;
        const editor = window.WMEditor;
        const body = new FormData(form);
        // the editor stays on the page: no saved message kept for the next one
        if (editor) body.append('inline', '1');
        fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {Accept: 'application/json'},
            body: body
        }).then(function (r) {
            return r.json().catch(function () { return {success: false}; });
        }).then(function (data) {
            if (!data.success) throw new Error(data.error || t('The settings could not be saved'));
            if (editor) {
                const dialog = form.closest('dialog');
                if (dialog) dialog.close();
                if (window.WMUI) WMUI.toast(t('Map saved'), 'success');
                // the panel of the editor is fetched again with the saved values
                if (editor.mapFormSaved) editor.mapFormSaved();
                editor.run(editor.load);
                return;
            }
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
        if (typeof HTMLDialogElement === 'undefined' || !window.WMUI || !WMUI.drawer) return;
        e.preventDefault();
        const menu = link.closest('details');
        if (menu) menu.open = false;
        open(link);
    });

    window.WMMapSettings = {load: load, setup: setup};

    function ready() {
        document.querySelectorAll('[data-map-settings-form]').forEach(setup);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
})();
