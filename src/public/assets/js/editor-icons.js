/**
 * Icon library of the SVG editor palette: icons sorted by theme in sections
 * that fold, a search box, and adding or removing icons.
 *
 * The icons come from window.WM_EDITOR.iconThemes and, after a change, from
 * /editor/icons. Dragging an icon onto the map is handled by editor-svg.js
 * (any .palette-icon with a data-icon).
 */
(function () {
    'use strict';

    var cfg = window.WM_EDITOR;
    var ed = window.WMEditor;
    var t = ed.t, h = ed.h;
    var FOLD_KEY = 'wm-editor-icon-themes';

    var holder = document.getElementById('palette-icons');
    var search = document.getElementById('icon-search');
    var manageBtn = document.getElementById('icon-manage');
    var dialog = document.getElementById('icon-dialog');

    var state = { themes: cfg.iconThemes || [], manage: false, open: loadFolds() };

    /** Names of the themes the project knows; others show their folder name */
    var LABELS = {
        classic: t('Classic'), generic: t('Generic'), status: t('Status'), clouds: t('Clouds'), net: t('Network'),
        affinity: 'Affinity', ansible: 'Ansible', arista: 'Arista', cisco: 'Cisco', cumulus: 'Cumulus', f5: 'F5', juniper: 'Juniper', viptela: 'Viptela', vmware: 'VMware'
    };

    /** Themes open until the person folds them; the others start folded */
    var OPEN_FIRST = ['net', 'classic'];

    function isOpen(theme) {
        return theme in state.open ? state.open[theme] : OPEN_FIRST.indexOf(theme) >= 0;
    }

    function label(theme) {
        return LABELS[theme] || theme.replace(/[-_]+/g, ' ').replace(/^./, function (c) { return c.toUpperCase(); });
    }

    function loadFolds() {
        try { return JSON.parse(localStorage.getItem(FOLD_KEY) || 'null') || {}; } catch (e) { return {}; }
    }

    function saveFolds() {
        try { localStorage.setItem(FOLD_KEY, JSON.stringify(state.open)); } catch (e) { /* this page only */ }
    }

    /**
     * The icons of a theme that match a search: every word typed must
     * appear in the icon name, the theme folder or the theme name.
     */
    function matching(theme, q) {
        var words = q.toLowerCase().split(/\s+/).filter(Boolean);
        if (!words.length) { return theme.icons; }
        var where = theme.theme + ' ' + label(theme.theme).toLowerCase();
        return theme.icons.filter(function (i) {
            var text = i.name.toLowerCase().replace(/[-_.]+/g, ' ') + ' ' + i.name.toLowerCase() + ' ' + where;
            return words.every(function (w) { return text.indexOf(w) >= 0; });
        });
    }

    function render() {
        var q = (search.value || '').trim().toLowerCase();
        var sections = [];

        state.themes.forEach(function (theme) {
            var icons = matching(theme, q);
            if (!icons.length) { return; }

            var grid = h('div', { 'class': 'icon-grid' }, icons.map(function (i) {
                return h('div', { 'class': 'palette-icon', draggable: state.manage ? 'false' : 'true', 'data-icon': i.path, title: i.name }, [
                    h('img', { src: '/' + i.preview, alt: '', loading: 'lazy' }),
                    state.manage && !i.shipped ? h('button', {
                        type: 'button', 'class': 'icon-delete', title: t('Remove this icon'), 'aria-label': t('Remove :name', { name: i.name }),
                        onclick: function (evt) { evt.stopPropagation(); remove(i); }
                    }, ['×']) : null
                ]);
            }));
            var details = h('details', { 'class': 'icon-theme', open: q ? true : isOpen(theme.theme) }, [
                h('summary', {}, [h('span', { text: label(theme.theme) }), h('span', { 'class': 'icon-count', text: String(icons.length) })]),
                grid
            ]);
            details.addEventListener('toggle', function () {
                if (q) { return; }
                state.open[theme.theme] = details.open;
                saveFolds();
            });
            sections.push(details);
        });

        if (!sections.length) {
            sections.push(h('p', { 'class': 'hint', text: q ? t('No icon matches') : t('No icons in public/objects') }));
        }
        if (state.manage) {
            sections.unshift(h('p', { 'class': 'hint', text: t('Icons shipped with NetworkWeathermapNG cannot be removed.') }));
        }
        holder.replaceChildren.apply(holder, sections);
        manageBtn.classList.toggle('active', state.manage);
        manageBtn.setAttribute('aria-pressed', state.manage ? 'true' : 'false');
    }

    // ------------------------------------------------------------------
    // Picking the icon of the selected nodes, in the properties panel

    var SHAPES = [['', t('(none)')], ['box', t('Box')], ['rbox', t('Rounded box')], ['round', t('Circle')]];

    function findIcon(path) {
        var found = null;
        state.themes.forEach(function (th) {
            th.icons.forEach(function (i) { if (i.path === path) { found = { icon: i, theme: th.theme }; } });
        });
        return found;
    }

    /**
     * The current icon, and a button that opens a search over the library.
     * `current` is the icon path ('' for none, null when the selection is
     * mixed); `onPick` gets the chosen path.
     */
    function iconPicker(current, onPick) {
        var LIMIT = 60;
        var box = h('div', { 'class': 'icon-pick' });
        var shape = SHAPES.filter(function (sh) { return sh[0] === current; })[0];
        var known = current ? findIcon(current) : null;
        var name = current === null ? t('(mixed)') : shape ? shape[1] : known ? known.icon.name : current.split('/').pop();
        var thumb = known ? h('img', { src: '/' + known.icon.preview, alt: '' }) : h('span', { 'class': 'icon-pick-none', 'aria-hidden': 'true' });
        var toggle = h('button', { type: 'button', 'class': 'icon-pick-current', 'aria-expanded': 'false', title: t('Choose an icon') }, [
            thumb, h('span', { text: name })
        ]);
        var input = h('input', { type: 'search', placeholder: t('Search icons'), 'aria-label': t('Search icons'), autocomplete: 'off' });
        var results = h('div', { 'class': 'icon-pick-results' });
        var pop = h('div', { 'class': 'icon-pick-pop', hidden: true }, [input, results]);

        function choose(path) { onPick(path); }

        function list() {
            var q = input.value.trim();
            var out = [];
            if (!q) {
                out.push(h('div', { 'class': 'icon-pick-shapes' }, SHAPES.map(function (sh) {
                    return h('button', { type: 'button', 'class': 'icon-pick-shape' + (sh[0] === current ? ' on' : ''), text: sh[1], onclick: function () { choose(sh[0]); } });
                })));
            }
            var shown = 0, more = 0;
            state.themes.forEach(function (th) {
                var icons = matching(th, q);
                if (!icons.length) { return; }
                var room = Math.max(0, LIMIT - shown);
                more += Math.max(0, icons.length - room);
                icons = icons.slice(0, room);
                if (!icons.length) { return; }
                shown += icons.length;
                out.push(h('h4', { text: label(th.theme) }), h('div', { 'class': 'icon-grid' }, icons.map(function (i) {
                    return h('button', {
                        type: 'button', 'class': 'palette-icon' + (i.path === current ? ' on' : ''), title: i.name, 'aria-label': i.name,
                        onclick: function () { choose(i.path); }
                    }, [h('img', { src: '/' + i.preview, alt: '', loading: 'lazy' })]);
                })));
            });
            if (!shown && q) { out.push(h('p', { 'class': 'help', text: t('No icon matches') })); }
            if (more) { out.push(h('p', { 'class': 'help', text: t(':n more, type to narrow the search', { n: more }) })); }
            results.replaceChildren.apply(results, out);
        }

        function show(open) {
            pop.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                list();
                input.focus();
            }
        }

        toggle.addEventListener('click', function () { show(pop.hidden); });
        input.addEventListener('input', list);
        input.addEventListener('keydown', function (evt) {
            if (evt.key === 'Enter') {
                var first = results.querySelector('.palette-icon, .icon-pick-shape');
                if (first) { first.focus(); first.click(); }
            } else if (evt.key === 'Escape') {
                if (input.value) { input.value = ''; list(); } else { show(false); toggle.focus(); }
            } else if (evt.key === 'ArrowDown') {
                var b = results.querySelector('button');
                if (b) { b.focus(); }
            } else {
                return;
            }
            evt.preventDefault();
            evt.stopPropagation();
        });
        results.addEventListener('keydown', function (evt) {
            var all = Array.prototype.slice.call(results.querySelectorAll('button'));
            var i = all.indexOf(document.activeElement);
            var step = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 5, ArrowUp: -5 }[evt.key];
            if (step) {
                var next = all[i + step] || (step < 0 ? input : all[all.length - 1]);
                next.focus();
            } else if (evt.key === 'Escape') {
                input.focus();
            } else {
                // keys typed on a result never reach the editor shortcuts
                evt.stopPropagation();
                return;
            }
            evt.preventDefault();
            evt.stopPropagation();
        });

        box.appendChild(toggle);
        box.appendChild(pop);
        return box;
    }

    function update(d) {
        state.themes = d.themes || [];
        // the inspector's icon list reads cfg.icons
        cfg.icons.splice.apply(cfg.icons, [0, cfg.icons.length].concat(d.icons || []));
        render();
    }

    function remove(icon) {
        if (!window.confirm(t('Remove the icon :name from the library?', { name: icon.name }))) { return; }
        ed.run(function () {
            return ed.api('POST', '/editor/icons/delete', { path: icon.path }).then(function (d) {
                update(d);
                ed.toast(t('Icon :name removed', { name: icon.name }));
            });
        });
    }

    function setAll(open) {
        state.themes.forEach(function (th) { state.open[th.theme] = open; });
        saveFolds();
        render();
    }

    // ------------------------------------------------------------------
    // Adding icons

    function openAdd() {
        var f = dialog.querySelector('form');
        var list = dialog.querySelector('#icon-theme-list');
        list.replaceChildren.apply(list, state.themes.map(function (th) { return h('option', { value: th.theme, text: label(th.theme) }); }));
        dialog.querySelector('.icon-errors').replaceChildren();
        f.reset();
        dialog.returnValue = '';
        dialog.showModal();
        f.theme.focus();
    }

    dialog.querySelector('form').addEventListener('submit', function (evt) {
        if (evt.submitter && evt.submitter.value === 'cancel') { return; }
        evt.preventDefault();
        var f = evt.target, errors = dialog.querySelector('.icon-errors');
        if (!f.icons.files.length) { f.icons.focus(); return; }

        var body = new FormData();
        body.append('theme', f.theme.value);
        Array.prototype.forEach.call(f.icons.files, function (file) { body.append('icons[]', file); });

        var send = dialog.querySelector('button.primary');
        send.disabled = true;
        fetch('/editor/icons', { method: 'POST', body: body, headers: { 'Accept': 'application/json' } }).then(function (r) {
            return r.json().catch(function () { return { error: t('The server answered :status', { status: r.status }) }; });
        }).then(function (d) {
            if (d.themes) { update(d); }
            var problems = (d.errors || []).concat(d.error ? [d.error] : []);
            errors.replaceChildren.apply(errors, problems.map(function (p) { return h('li', { text: p }); }));
            if (d.added && d.added.length) {
                state.open[f.theme.value.trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '-')] = true;
                ed.toast(t(':n icon(s) added', { n: d.added.length }));
                if (!problems.length) { dialog.close(); }
            }
        }).catch(function (e) {
            errors.replaceChildren(h('li', { text: e.message }));
        }).finally(function () { send.disabled = false; });
    });

    document.getElementById('icon-add').addEventListener('click', openAdd);
    manageBtn.addEventListener('click', function () { state.manage = !state.manage; render(); });
    document.getElementById('icon-fold').addEventListener('click', function () {
        var anyOpen = state.themes.some(function (th) { return isOpen(th.theme); });
        setAll(!anyOpen);
    });
    search.addEventListener('input', render);
    search.addEventListener('keydown', function (evt) {
        if (evt.key === 'Escape' && search.value) { search.value = ''; render(); evt.stopPropagation(); }
    });

    ed.iconPicker = iconPicker;
    render();
})();
