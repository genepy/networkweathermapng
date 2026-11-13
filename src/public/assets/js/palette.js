/*
 * NetworkWeathermapNG - the Ctrl+K command palette (UI redesign step 7).
 *
 * One search box for the maps, the nodes of every map (opens the map zoomed
 * onto the node), the Settings pages (administrators) and commands: edit a
 * map or open its settings, start the kiosk (for a group), new map, theme,
 * change password, log out.
 *
 *   Ctrl+K (Cmd+K on a Mac) or the search button of the top bar opens it.
 *   Up and Down move, Enter opens, Ctrl/Cmd/Shift+Enter opens a page in a
 *   new tab, Escape closes. Every word typed must appear (accents ignored).
 *
 * The index comes from GET /palette (CommandPalette on the server), fetched
 * once per page the first time the palette opens (or the pointer reaches
 * the button). Texts come from window.WM_T (the ui-js catalogue).
 *
 * A page adds its own entries with WMPalette.register(source) (the SVG
 * editor does, editor-palette.js): source.kind names the section,
 * source.title is its heading and source.entries() returns entries like
 * { icon, label, context, keys, shortcut, run } (an icon starting with
 * "#" is a symbol of the page). They come before the maps, also when the
 * index cannot be loaded. source.mapId hides the server's nodes of that
 * map (the page lists them itself) and its own Edit command.
 */
(function () {
    'use strict';

    function t(s, args) {
        var out = (window.WM_T && window.WM_T[s]) || s;
        if (args) {
            Object.keys(args).forEach(function (k) { out = out.split(':' + k).join(String(args[k])); });
        }
        return out;
    }

    var LIMITS = { maps: 6, nodes: 8, settings: 6, commands: 8 };
    var isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');

    var dialog = null;
    var input = null;
    var list = null;
    var status = null;
    var data = null;
    var loading = null;
    var results = [];
    var active = -1;
    var opener = null;
    var sources = [];
    var EMPTY = { maps: [], nodes: [], groups: [], settings: [], can: { edit: false, account: false } };

    function norm(s) {
        return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    function sprite() {
        var use = document.querySelector('svg use[href*="ui.svg"]');
        return use ? (use.getAttribute('href') || '').split('#')[0] : '/assets/icons/ui.svg';
    }

    function iconEl(name) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('class', 'icon');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        var use = document.createElementNS(ns, 'use');
        use.setAttribute('href', name.charAt(0) === '#' ? name : sprite() + '#' + name);
        svg.appendChild(use);
        return svg;
    }

    function load() {
        if (data) { return Promise.resolve(data); }
        if (loading) { return loading; }
        loading = fetch('/palette', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) {
                if (!r.ok || r.redirected) { throw new Error('HTTP ' + r.status); }
                return r.json();
            })
            .then(function (json) {
                data = json;
                return data;
            })
            .catch(function (err) {
                loading = null; // try again next time
                throw err;
            });
        return loading;
    }

    // ------------------------------------------------------------------
    // Entries

    function currentMapId() {
        var v = document.getElementById('wmv') || document.querySelector('[data-map-id]');
        return v ? parseInt(v.getAttribute('data-map-id') || '0', 10) : 0;
    }

    /** The map whose nodes and Edit command a registered source replaces */
    function ownMap() {
        var id = 0;
        sources.forEach(function (src) { if (src.mapId) { id = src.mapId; } });
        return id;
    }

    /** The sections of the registered sources, ranked for the words typed */
    function ownSections(words, whole) {
        return sources.map(function (src) {
            var entries = (src.entries() || []).map(function (e) { e.kind = src.kind; return e; });
            return [src.kind, words.length ? ranked(entries, words, whole, src.limit || LIMITS.commands) : entries.slice(0, src.first !== undefined ? src.first : entries.length)];
        });
    }

    function mapSettings(id) {
        var a = document.createElement('a');
        a.href = '/editor/maps/edit/' + id + '?return=' + encodeURIComponent(location.pathname + location.search);
        a.setAttribute('data-map-settings', '');
        a.hidden = true;
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    function setTheme(value) {
        var b = document.querySelector('[data-pref="theme"][data-theme-value="' + value + '"]');
        if (b) { b.click(); }
    }

    function logOut() {
        var f = document.querySelector('form.um-logout');
        if (f) { f.submit(); }
    }

    /** Commands for one map: edit it, open its settings */
    function mapCommands(map, d) {
        if (!d.can.edit) { return []; }
        var out = [];
        if (map.id !== ownMap()) {
            out.push({ kind: 'commands', icon: 'pencil', label: t('Edit “:name”', { name: map.name }), keys: 'edit ' + map.name, href: '/editor/edit/' + map.id });
        }
        out.push({ kind: 'commands', icon: 'settings', label: t('Map settings for “:name”', { name: map.name }), keys: 'settings ' + map.name, run: function () { mapSettings(map.id); } });
        return out;
    }

    function generalCommands(d) {
        var out = [];
        if (d.can.edit) {
            out.push({ kind: 'commands', icon: 'plus', label: t('New map'), keys: 'create add', href: '/editor/maps/create' });
        }
        out.push({ kind: 'commands', icon: 'tv', label: t('Start kiosk'), keys: 'cycle', href: '/cycle' });
        d.groups.forEach(function (g) {
            out.push({ kind: 'commands', icon: 'tv', label: t('Start kiosk with group “:name”', { name: g.name }), keys: 'cycle', href: '/cycle/' + g.id });
        });
        out.push({ kind: 'commands', icon: 'star', label: t('Show favourite maps'), keys: 'favorites', href: '/?filter=favourites' });
        out.push({ kind: 'commands', icon: 'chart-line', label: t('Show maps over 80 %'), keys: 'busy load', href: '/?filter=busy' });
        if (document.querySelector('[data-pref="theme"][data-theme-value]')) {
            out.push({ kind: 'commands', icon: 'sun', label: t('Use the light theme'), keys: 'theme light', run: function () { setTheme('light'); } });
            out.push({ kind: 'commands', icon: 'moon', label: t('Use the dark theme'), keys: 'theme dark night', run: function () { setTheme('dark'); } });
            out.push({ kind: 'commands', icon: 'sun-moon', label: t('Use the automatic theme'), keys: 'theme auto system', run: function () { setTheme(''); } });
        }
        if (d.can.account) {
            out.push({ kind: 'commands', icon: 'lock', label: t('Change password'), keys: 'account', href: '/account/password' });
            if (document.querySelector('form.um-logout')) {
                out.push({ kind: 'commands', icon: 'arrow-left', label: t('Log out'), keys: 'sign out logout', run: logOut });
            }
        }
        return out;
    }

    function mapEntry(m) {
        return {
            kind: 'maps', icon: 'map', label: m.name, context: m.group, keys: m.group,
            href: '/map/' + m.id, map: m,
            badge: m.pct !== null && m.pct !== undefined ? Math.round(m.pct) + ' %' : '',
            level: m.level, hidden: !m.active, favourite: m.favourite
        };
    }

    /** Score of an entry for the words typed; 0 when one word is missing */
    function score(entry, words, whole) {
        var label = norm(entry.label);
        var own = label + ' ' + norm(entry.keys);
        var hay = own + ' ' + norm(entry.context);
        var mine = false;
        for (var i = 0; i < words.length; i++) {
            if (hay.indexOf(words[i]) === -1) { return 0; }
            if (own.indexOf(words[i]) !== -1) { mine = true; }
        }
        // a node is not found by the name of its map alone
        if (entry.strict && !mine) { return 0; }
        var s = 1;
        if (label === whole) { s += 100; }
        else if (label.indexOf(whole) === 0) { s += 60; }
        else if (new RegExp('(^|[^a-z0-9])' + whole.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).test(label)) { s += 40; }
        else if (label.indexOf(whole) !== -1) { s += 20; }
        if (entry.favourite) { s += 5; }
        return s - Math.min(label.length, 60) / 100;
    }

    function ranked(entries, words, whole, limit) {
        var out = [];
        entries.forEach(function (e, i) {
            var s = score(e, words, whole);
            if (s > 0) { out.push({ e: e, s: s, i: i }); }
        });
        out.sort(function (a, b) { return b.s - a.s || a.i - b.i; });
        return out.slice(0, limit).map(function (x) { return x.e; });
    }

    function search(query) {
        var d = data || EMPTY;
        var whole = norm(query).trim().replace(/\s+/g, ' ');
        var words = whole ? whole.split(' ') : [];
        var maps = d.maps.map(mapEntry);
        var sections = ownSections(words, whole);

        if (!words.length) {
            // nothing typed: the current map's commands, favourites and the first maps, the commands
            var here = currentMapId();
            var cmds = [];
            d.maps.forEach(function (m) { if (m.id === here) { cmds = mapCommands(m, d); } });
            // favourites first, then the order of the Maps page
            var first = maps.slice().sort(function (a, b) { return (b.favourite ? 1 : 0) - (a.favourite ? 1 : 0); });
            sections.push(['maps', first.filter(function (e) { return e.map.id !== here; }).slice(0, LIMITS.maps)]);
            sections.push(['commands', cmds.concat(generalCommands(d))]);
            return sections;
        }

        var foundMaps = ranked(maps, words, whole, LIMITS.maps);
        var byId = {};
        d.maps.forEach(function (m) { byId[m.id] = m; });
        var own = ownMap();
        var nodes = d.nodes.filter(function (n) { return n.map !== own; }).map(function (n) {
            var map = byId[n.map];
            return {
                kind: 'nodes', icon: 'rectangle-horizontal', label: n.label, strict: true,
                context: map ? t('in :map', { map: map.name }) : '',
                keys: n.name !== n.label ? n.name : '',
                href: '/map/' + n.map + '#n=' + encodeURIComponent(n.name)
            };
        });
        var settings = d.settings.map(function (p) {
            return { kind: 'settings', icon: p.icon, label: p.label, context: p.group, keys: 'settings admin', href: p.href };
        });
        // commands of the maps that match best, then the general ones
        var ofMaps = [];
        foundMaps.slice(0, 2).forEach(function (e) { ofMaps = ofMaps.concat(mapCommands(e.map, d)); });
        var foundCmds = ofMaps.concat(ranked(generalCommands(d), words, whole, LIMITS.commands));

        sections.push(['maps', foundMaps]);
        sections.push(['nodes', ranked(nodes, words, whole, LIMITS.nodes)]);
        sections.push(['settings', ranked(settings, words, whole, LIMITS.settings)]);
        sections.push(['commands', foundCmds.slice(0, LIMITS.commands)]);
        return sections;
    }

    // ------------------------------------------------------------------
    // Drawing

    function title(kind) {
        var own = null;
        sources.forEach(function (src) { if (src.kind === kind) { own = src.title; } });
        return own || { maps: t('Maps'), nodes: t('Nodes'), settings: t('Settings'), commands: t('Commands') }[kind];
    }

    function message(text) {
        var p = document.createElement('p');
        p.className = 'pal-empty';
        p.textContent = text;
        list.replaceChildren(p);
        results = [];
        active = -1;
        input.removeAttribute('aria-activedescendant');
        status.textContent = text;
    }

    function draw() {
        if (!data && !sources.length) { return; }
        var sections = search(input.value);
        var nodes = [];
        results = [];
        sections.forEach(function (sec) {
            var kind = sec[0];
            var entries = sec[1];
            if (!entries.length) { return; }
            var group = document.createElement('div');
            group.setAttribute('role', 'group');
            group.setAttribute('aria-labelledby', 'pal-h-' + kind);
            var head = document.createElement('div');
            head.className = 'pal-head';
            head.id = 'pal-h-' + kind;
            head.setAttribute('role', 'presentation');
            head.textContent = title(kind);
            group.appendChild(head);
            entries.forEach(function (e) {
                var i = results.length;
                var opt = document.createElement('div');
                opt.className = 'pal-item';
                opt.id = 'pal-opt-' + i;
                opt.setAttribute('role', 'option');
                opt.setAttribute('aria-selected', 'false');
                opt.dataset.index = String(i);
                opt.appendChild(iconEl(e.icon));
                var label = document.createElement('span');
                label.className = 'pal-label';
                label.textContent = e.label;
                opt.appendChild(label);
                if (e.context) {
                    var ctx = document.createElement('span');
                    ctx.className = 'pal-ctx';
                    ctx.textContent = e.context;
                    opt.appendChild(ctx);
                }
                if (e.hidden) {
                    var hid = document.createElement('span');
                    hid.className = 'pal-tag';
                    hid.textContent = t('Hidden');
                    opt.appendChild(hid);
                }
                var end = document.createElement('span');
                end.className = 'pal-end';
                if (e.shortcut) {
                    var keys = document.createElement('kbd');
                    keys.className = 'pal-keys';
                    // shortcuts.js already writes the keys the way this computer shows them
                    keys.textContent = isMac && !window.WMKeys ? e.shortcut.replace(/Ctrl\+/g, '⌘').replace(/Shift\+/g, '⇧') : e.shortcut;
                    end.appendChild(keys);
                }
                if (e.badge) {
                    var badge = document.createElement('span');
                    badge.className = 'pal-badge' + (e.level ? ' pal-load-' + e.level : '');
                    badge.textContent = e.badge;
                    end.appendChild(badge);
                }
                var enter = document.createElement('kbd');
                enter.className = 'pal-enter';
                enter.setAttribute('aria-hidden', 'true');
                enter.textContent = t('Enter');
                end.appendChild(enter);
                opt.appendChild(end);
                group.appendChild(opt);
                results.push(e);
            });
            nodes.push(group);
        });
        if (!results.length) {
            message(t('No results for “:q”', { q: input.value.trim() }));
            return;
        }
        list.replaceChildren.apply(list, nodes);
        status.textContent = results.length === 1 ? t('1 result') : t(':n results', { n: results.length });
        select(0, false);
    }

    function select(i, scroll) {
        if (!results.length) { return; }
        i = (i + results.length) % results.length;
        var old = list.querySelector('.pal-item.is-active');
        if (old) { old.classList.remove('is-active'); old.setAttribute('aria-selected', 'false'); }
        var opt = document.getElementById('pal-opt-' + i);
        active = i;
        if (!opt) { return; }
        opt.classList.add('is-active');
        opt.setAttribute('aria-selected', 'true');
        input.setAttribute('aria-activedescendant', opt.id);
        if (scroll !== false) { opt.scrollIntoView({ block: 'nearest' }); }
    }

    function run(i, newTab) {
        var e = results[i];
        if (!e) { return; }
        close(false);
        if (e.run) {
            e.run();
        } else if (newTab) {
            window.open(e.href, '_blank', 'noopener');
        } else {
            var target = new URL(e.href, location.href);
            window.location.href = target.href;
        }
    }

    // ------------------------------------------------------------------
    // Dialog

    function build() {
        dialog = document.createElement('dialog');
        dialog.className = 'wm-palette';
        dialog.setAttribute('aria-label', t('Command palette'));
        dialog.innerHTML =
            '<div class="pal-search">'
            + '<input type="text" class="pal-input" role="combobox" aria-expanded="true" aria-autocomplete="list"'
            + ' aria-controls="pal-list" autocomplete="off" spellcheck="false">'
            + '<button type="button" class="pal-close"><kbd>Esc</kbd></button></div>'
            + '<div class="pal-list" id="pal-list" role="listbox"></div>'
            + '<div class="pal-foot" aria-hidden="true"><span><kbd>↑</kbd><kbd>↓</kbd> <span data-t="move"></span></span>'
            + '<span><kbd data-t="enter"></kbd> <span data-t="open"></span></span>'
            + '<span><kbd>Esc</kbd> <span data-t="close"></span></span></div>'
            + '<p class="visually-hidden" role="status" aria-live="polite"></p>';
        dialog.querySelector('.pal-search').insertBefore(iconEl('search'), dialog.querySelector('.pal-input'));
        var closeButton = dialog.querySelector('.pal-close');
        closeButton.appendChild(iconEl('x'));
        closeButton.setAttribute('aria-label', t('Close'));
        closeButton.addEventListener('click', function () { close(true); });
        var foot = { move: t('Move'), enter: t('Enter'), open: t('Open'), close: t('Close') };
        dialog.querySelectorAll('[data-t]').forEach(function (s) { s.textContent = foot[s.getAttribute('data-t')]; });
        input = dialog.querySelector('.pal-input');
        var hint = sources.length && sources[0].placeholder ? sources[0].placeholder : t('Search maps, nodes, commands');
        input.placeholder = hint;
        input.setAttribute('aria-label', hint);
        list = dialog.querySelector('.pal-list');
        status = dialog.querySelector('[role="status"]');
        document.body.appendChild(dialog);

        input.addEventListener('input', draw);
        input.addEventListener('keydown', function (e) {
            switch (e.key) {
                case 'ArrowDown': select(active + 1); break;
                case 'ArrowUp': select(active - 1); break;
                case 'Home': if (!e.ctrlKey) { return; } select(0); break;
                case 'End': if (!e.ctrlKey) { return; } select(results.length - 1); break;
                case 'PageDown': select(Math.min(active + 5, results.length - 1)); break;
                case 'PageUp': select(Math.max(active - 5, 0)); break;
                case 'Enter':
                    if (e.isComposing) { return; }
                    run(active, e.ctrlKey || e.metaKey || e.shiftKey);
                    break;
                default: return;
            }
            e.preventDefault();
        });
        list.addEventListener('mousemove', function (e) {
            var opt = e.target.closest('.pal-item');
            if (opt && Number(opt.dataset.index) !== active) { select(Number(opt.dataset.index), false); }
        });
        list.addEventListener('click', function (e) {
            var opt = e.target.closest('.pal-item');
            if (opt) { run(Number(opt.dataset.index), e.ctrlKey || e.metaKey || e.shiftKey); }
        });
        list.addEventListener('auxclick', function (e) {
            var opt = e.target.closest('.pal-item');
            if (opt && e.button === 1) { e.preventDefault(); run(Number(opt.dataset.index), true); }
        });
        // a click on the backdrop closes it
        dialog.addEventListener('click', function (e) { if (e.target === dialog) { close(true); } });
        // Escape closes the dialog by itself: give the focus back too
        dialog.addEventListener('cancel', function () { dialog.dataset.restore = '1'; });
        dialog.addEventListener('close', function () {
            if (opener && opener.isConnected && opener !== document.body && dialog.dataset.restore === '1') { opener.focus(); }
        });
        return dialog;
    }

    function open() {
        if (typeof HTMLDialogElement === 'undefined') { return; }
        var d = dialog || build();
        if (d.open) { input.select(); return; }
        opener = document.activeElement;
        input.value = '';
        d.showModal();
        input.focus();
        if (data) {
            draw();
            return;
        }
        if (sources.length) {
            // the page's own entries first, the maps when they arrive
            draw();
            load().then(function () { if (d.open) { draw(); } }).catch(function () { /* the page's entries only */ });
            return;
        }
        message(t('Loading…'));
        load().then(function () { if (d.open) { draw(); } })
            .catch(function () { if (d.open) { message(t('The search could not be loaded')); } });
    }

    function close(restore) {
        if (!dialog || !dialog.open) { return; }
        dialog.dataset.restore = restore ? '1' : '0';
        dialog.close();
    }

    document.addEventListener('keydown', function (e) {
        // the keys of the user (shortcuts.js), Ctrl+K when that script is missing
        var hit = window.WMKeys ? window.WMKeys.is(e, 'palette')
            : (e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && (e.key === 'k' || e.key === 'K');
        if (hit) {
            e.preventDefault();
            if (dialog && dialog.open) { close(true); } else { open(); }
        }
    });

    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-palette-open]') : null;
        if (b) { e.preventDefault(); open(); }
    });
    // fetch the index while the pointer is on its way to the button
    ['pointerenter', 'focusin'].forEach(function (type) {
        document.addEventListener(type, function (e) {
            if (e.target.closest && e.target.closest('[data-palette-open]')) { load().catch(function () { /* again on open */ }); }
        }, true);
    });

    function ready() {
        // the shortcut shown on the button follows the keyboard and the user's keys
        document.querySelectorAll('[data-palette-open] .app-search-key').forEach(function (k) {
            if (window.WMKeys) {
                k.textContent = window.WMKeys.first('palette');
                k.hidden = !k.textContent;
            } else {
                k.textContent = isMac ? '⌘ K' : 'Ctrl K';
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }

    window.WMPalette = {
        open: open,
        close: function () { close(true); },
        register: function (source) { sources.push(source); }
    };
})();
