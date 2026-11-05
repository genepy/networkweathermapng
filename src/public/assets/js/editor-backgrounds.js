/**
 * Background library of the SVG editor: the Background row of the map panel
 * opens a dialog to search the backgrounds, pick one, add new ones or remove
 * the ones no map uses. The Add panel lists them too: a click sets the
 * background of the map.
 *
 * The backgrounds come from window.WM_EDITOR.backgrounds and, after a
 * change, from /editor/backgrounds (see BackgroundLibrary::all()).
 */
(function () {
    'use strict';

    var cfg = window.WM_EDITOR;
    var ed = window.WMEditor;
    var t = ed.t, h = ed.h;

    var dialog = document.getElementById('bg-dialog');
    var search = dialog.querySelector('.bg-search');
    var grid = dialog.querySelector('.bg-grid');
    var errors = dialog.querySelector('.icon-errors');
    var fileInput = dialog.querySelector('input[type=file]');
    var addLabel = dialog.querySelector('.bg-add');

    var state = { list: cfg.backgrounds || [], current: '', onPick: null };

    function find(path) {
        return state.list.filter(function (b) { return b.path === path; })[0] || null;
    }

    function size(bytes) {
        return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' kB';
    }

    /** Every word typed must appear in the file name or the folder */
    function matching(q) {
        var words = q.toLowerCase().split(/\s+/).filter(Boolean);
        if (!words.length) { return state.list; }
        return state.list.filter(function (b) {
            var text = b.path.toLowerCase() + ' ' + b.path.toLowerCase().replace(/[-_./]+/g, ' ');
            return words.every(function (w) { return text.indexOf(w) >= 0; });
        });
    }

    function tile(b) {
        var on = (b ? b.path : '') === state.current;
        var info = b ? [
            h('span', { 'class': 'bg-name', text: b.name }),
            h('span', { 'class': 'bg-meta', text: (b.width ? b.width + ' × ' + b.height + ' px · ' : '') + size(b.bytes) }),
            b.used_by.length ? h('span', { 'class': 'bg-meta', text: t('Used by :n map(s)', { n: b.used_by.length }), title: b.used_by.join(', ') }) : null
        ] : [h('span', { 'class': 'bg-name', text: t('(none)') })];
        var pick = h('button', {
            type: 'button', 'class': 'bg-tile' + (on ? ' on' : ''), role: 'option', 'aria-selected': on ? 'true' : 'false',
            title: b ? b.path : t('No background image'),
            onclick: function () { choose(b ? b.path : ''); }
        }, [
            b ? h('img', { src: '/' + b.path, alt: '', loading: 'lazy' }) : h('span', { 'class': 'bg-empty', 'aria-hidden': 'true' })
        ].concat(info));
        var removable = b && !b.shipped && !b.used_by.length;
        return h('div', { 'class': 'bg-item' }, [
            pick,
            removable ? h('button', {
                type: 'button', 'class': 'icon-delete', title: t('Remove this background'), 'aria-label': t('Remove :name', { name: b.name }),
                onclick: function () { remove(b); }
            }, ['×']) : null
        ]);
    }

    function render() {
        var q = search.value.trim();
        var shown = matching(q);
        var items = (q ? [] : [tile(null)]).concat(shown.map(tile));
        if (!shown.length) {
            items.push(h('p', { 'class': 'hint', text: q ? t('No background matches') : t('No backgrounds in public/backgrounds') }));
        }
        grid.replaceChildren.apply(grid, items);
    }

    function choose(path) {
        dialog.close();
        if (path !== state.current && state.onPick) { state.onPick(path); }
    }

    function open(current, onPick) {
        state.current = current;
        state.onPick = onPick;
        search.value = '';
        errors.replaceChildren();
        render();
        dialog.showModal();
        var sel = grid.querySelector('.bg-tile.on');
        if (sel) { sel.scrollIntoView({ block: 'nearest' }); }
        search.focus();
    }

    function update(d) {
        state.list = d.backgrounds || [];
        cfg.backgrounds = state.list;
        render();
        renderPalette();
    }

    // ------------------------------------------------------------------
    // The Backgrounds fold of the Add panel

    var fold = document.getElementById('palette-backgrounds');
    var foldGrid = fold && fold.querySelector('.pb-grid');
    var FOLD_KEY = 'wm-editor-backgrounds-open';

    function currentBackground() {
        var m = ed.state.model && ed.state.model.map;
        return m ? m.background || '' : '';
    }

    function setBackground(path) {
        if (path === currentBackground()) { return; }
        ed.send([{ op: 'set_map', background: path }], function () {
            ed.toast(path ? t('Background set. Ctrl+Z to undo.') : t('Background removed. Ctrl+Z to undo.'));
            return undefined;
        });
    }

    function renderPalette() {
        if (!fold) { return; }
        fold.querySelector('.icon-count').textContent = String(state.list.length);
        if (!fold.open) { return; }
        var now = currentBackground();
        foldGrid.replaceChildren.apply(foldGrid, [null].concat(state.list).map(function (b) {
            var path = b ? b.path : '';
            return h('button', {
                type: 'button', 'class': 'pb-tile' + (path === now ? ' on' : ''), 'aria-pressed': path === now ? 'true' : 'false',
                title: b ? b.name + (b.width ? ' · ' + b.width + ' × ' + b.height + ' px' : '') : t('No background image'),
                'aria-label': b ? b.name : t('No background image'),
                onclick: function () { setBackground(path); }
            }, [b ? h('img', { src: '/' + b.path, alt: '', loading: 'lazy' }) : h('span', { 'class': 'bg-empty', 'aria-hidden': 'true' })]);
        }));
    }

    if (fold) {
        try { fold.open = localStorage.getItem(FOLD_KEY) === '1'; } catch (e) { /* closed */ }
        fold.addEventListener('toggle', function () {
            try { localStorage.setItem(FOLD_KEY, fold.open ? '1' : '0'); } catch (e) { /* this page only */ }
            renderPalette();
        });
        fold.querySelector('.pb-manage').addEventListener('click', function () { open(currentBackground(), setBackground); });
        document.addEventListener('wm-editor-render', renderPalette);
        renderPalette();
    }

    function remove(b) {
        var question = t('Remove the background :name from the library?', { name: b.name });
        var ask = window.WMUI ? window.WMUI.confirm({ message: question, confirm: t('Remove'), danger: true })
            : Promise.resolve(window.confirm(question));
        ask.then(function (ok) { if (ok) { removeNow(b); } });
    }

    function removeNow(b) {
        ed.api('POST', '/editor/backgrounds/delete', { path: b.path }).then(function (d) {
            update(d);
            ed.toast(t('Background :name removed', { name: b.name }));
        }).catch(function (e) {
            errors.replaceChildren(h('li', { text: e.message }));
        });
    }

    function upload() {
        if (!fileInput.files.length) { return; }
        var body = new FormData();
        Array.prototype.forEach.call(fileInput.files, function (file) { body.append('backgrounds[]', file); });
        addLabel.classList.add('busy');
        fetch('/editor/backgrounds', { method: 'POST', body: body, headers: { 'Accept': 'application/json' } }).then(function (r) {
            return r.json().catch(function () { return { error: t('The server answered :status', { status: r.status }) }; });
        }).then(function (d) {
            if (d.backgrounds) { update(d); }
            var problems = (d.errors || []).concat(d.error ? [d.error] : []);
            errors.replaceChildren.apply(errors, problems.map(function (p) { return h('li', { text: p }); }));
            if (d.added && d.added.length) {
                ed.toast(t(':n background(s) added', { n: d.added.length }));
                var first = Array.prototype.filter.call(grid.querySelectorAll('.bg-tile'), function (el) { return el.title === d.added[0]; })[0];
                if (first) { first.scrollIntoView({ block: 'nearest' }); first.focus(); }
            }
        }).catch(function (e) {
            errors.replaceChildren(h('li', { text: e.message }));
        }).finally(function () {
            addLabel.classList.remove('busy');
            fileInput.value = '';
        });
    }

    /**
     * The current background and a button that opens the library.
     * `onPick` gets the chosen path ('' for none).
     */
    function backgroundPicker(current, onPick) {
        var b = current ? find(current) : null;
        var thumb = current ? h('img', { src: '/' + current, alt: '' }) : h('span', { 'class': 'icon-pick-none', 'aria-hidden': 'true' });
        var button = h('button', { type: 'button', 'class': 'icon-pick-current', title: t('Choose a background'), 'aria-haspopup': 'dialog' }, [
            thumb, h('span', { text: current ? (b ? b.name : current.split('/').pop()) : t('(none)') })
        ]);
        button.addEventListener('click', function () { open(current, onPick); });
        return button;
    }

    search.addEventListener('input', render);
    search.addEventListener('keydown', function (evt) {
        if (evt.key === 'Escape' && search.value) {
            search.value = '';
            render();
            evt.preventDefault();
        } else if (evt.key === 'Enter') {
            var first = grid.querySelector('.bg-tile');
            if (first) { first.click(); }
            evt.preventDefault();
        } else if (evt.key === 'ArrowDown') {
            var b = grid.querySelector('.bg-tile');
            if (b) { b.focus(); }
            evt.preventDefault();
        }
        // keys typed here never reach the editor shortcuts
        evt.stopPropagation();
    });
    grid.addEventListener('keydown', function (evt) {
        var all = Array.prototype.slice.call(grid.querySelectorAll('.bg-tile'));
        var i = all.indexOf(document.activeElement);
        var step = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 3, ArrowUp: -3 }[evt.key];
        if (step && i >= 0) {
            var next = all[i + step] || (step < 0 ? search : all[all.length - 1]);
            next.focus();
            evt.preventDefault();
        }
        evt.stopPropagation();
    });
    fileInput.addEventListener('change', upload);
    addLabel.addEventListener('keydown', function (evt) {
        if (evt.key === 'Enter' || evt.key === ' ') {
            fileInput.click();
            evt.preventDefault();
        }
        evt.stopPropagation();
    });

    ed.backgroundPicker = backgroundPicker;
})();
