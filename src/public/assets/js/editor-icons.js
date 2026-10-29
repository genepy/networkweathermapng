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
        arista: 'Arista', cisco: 'Cisco', cumulus: 'Cumulus', f5: 'F5', juniper: 'Juniper', viptela: 'Viptela', vmware: 'VMware'
    };

    function label(theme) {
        return LABELS[theme] || theme.replace(/[-_]+/g, ' ').replace(/^./, function (c) { return c.toUpperCase(); });
    }

    function loadFolds() {
        try { return JSON.parse(localStorage.getItem(FOLD_KEY) || 'null') || {}; } catch (e) { return {}; }
    }

    function saveFolds() {
        try { localStorage.setItem(FOLD_KEY, JSON.stringify(state.open)); } catch (e) { /* this page only */ }
    }

    function render() {
        var q = (search.value || '').trim().toLowerCase();
        var sections = [];

        state.themes.forEach(function (theme) {
            var icons = theme.icons.filter(function (i) {
                return !q || i.name.toLowerCase().indexOf(q) >= 0 || theme.theme.indexOf(q) >= 0;
            });
            if (!icons.length) { return; }

            var grid = h('div', { 'class': 'icon-grid' }, icons.map(function (i) {
                return h('div', { 'class': 'palette-icon', draggable: state.manage ? 'false' : 'true', 'data-icon': i.path, title: i.name }, [
                    h('img', { src: '/' + i.preview, alt: '', loading: 'lazy' }),
                    state.manage ? h('button', {
                        type: 'button', 'class': 'icon-delete', title: t('Remove this icon'), 'aria-label': t('Remove :name', { name: i.name }),
                        onclick: function (evt) { evt.stopPropagation(); remove(i); }
                    }, ['×']) : null
                ]);
            }));
            var details = h('details', { 'class': 'icon-theme', open: q ? true : state.open[theme.theme] !== false }, [
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
        holder.replaceChildren.apply(holder, sections);
        manageBtn.classList.toggle('active', state.manage);
        manageBtn.setAttribute('aria-pressed', state.manage ? 'true' : 'false');
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
        var anyOpen = state.themes.some(function (th) { return state.open[th.theme] !== false; });
        setAll(!anyOpen);
    });
    search.addEventListener('input', render);
    search.addEventListener('keydown', function (evt) {
        if (evt.key === 'Escape' && search.value) { search.value = ''; render(); evt.stopPropagation(); }
    });

    render();
})();
