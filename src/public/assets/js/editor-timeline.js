/**
 * History and savepoints of the SVG editor, in the style of the VS Code
 * Timeline: a panel lists the saved versions of the map with their time
 * (relative, the exact time on hover), grouped by day, and the savepoints
 * the author named. A click on a version previews it over the drawing;
 * Restore puts it back as the map. Restoring is a new version and one undo
 * step, so it can be undone.
 *
 * The versions come from ConfigHistory on the server (/editor/timeline/{id}).
 * Opened from the History button of the top bar, the ⋯ menu or Ctrl+K.
 */
(function () {
    'use strict';

    var ed = window.WMEditor;
    if (!ed) { return; }
    var t = ed.t;
    var h = ed.h;
    var mapId = window.WM_EDITOR.mapId;
    var panel = document.getElementById('timeline');
    var list = panel.querySelector('.tl-list');
    var form = panel.querySelector('.tl-new');
    var nameField = form.querySelector('input[name="name"]');
    var onlyNamed = panel.querySelector('input[name="named"]');
    var note = panel.querySelector('.tl-note');
    var preview = document.getElementById('version-preview');
    var previewBody = preview.querySelector('.vp-body');
    var previewTitle = preview.querySelector('.vp-title');
    var restoreButton = preview.querySelector('[data-vp="restore"]');
    var opener = null;
    var versions = [];
    var previewing = null;
    var reloadTimer = null;
    var lang = document.documentElement.lang || undefined;

    // ------------------------------------------------------------------
    // Times

    var relative = typeof Intl !== 'undefined' && Intl.RelativeTimeFormat ? new Intl.RelativeTimeFormat(lang, { numeric: 'auto' }) : null;

    /** "2 minutes ago", "yesterday"... in the page language */
    function ago(time) {
        var s = Math.round(time - Date.now() / 1000);
        var units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
        if (!relative) { return exact(time); }
        if (Math.abs(s) < 45) { return relative.format(0, 'second'); }
        for (var i = 0; i < units.length; i++) {
            if (Math.abs(s) >= units[i][1] * 0.9 || units[i][0] === 'minute') {
                return relative.format(Math.round(s / units[i][1]), units[i][0]);
            }
        }
        return exact(time);
    }

    function exact(time) {
        return new Date(time * 1000).toLocaleString(lang, { dateStyle: 'full', timeStyle: 'medium' });
    }

    function clock(time) {
        return new Date(time * 1000).toLocaleTimeString(lang, { hour: '2-digit', minute: '2-digit' });
    }

    /** The heading of the day of a version: Today, Yesterday or the date */
    function day(time) {
        var d = new Date(time * 1000);
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        var start = new Date(d.getTime());
        start.setHours(0, 0, 0, 0);
        var diff = Math.round((today - start) / 86400000);
        if (diff === 0) { return t('Today'); }
        if (diff === 1) { return t('Yesterday'); }
        return d.toLocaleDateString(lang, { weekday: 'long', day: 'numeric', month: 'long', year: today.getFullYear() === d.getFullYear() ? undefined : 'numeric' });
    }

    // ------------------------------------------------------------------
    // What a version is

    var ICONS = { savepoint: 'star', restore: 'history', import: 'upload', outside: 'external-link', start: 'circle-dot', edit: 'pencil' };

    function label(v) {
        if (v.name) { return v.name; }
        switch (v.kind) {
            case 'start': return v.id ? t('Earlier version') : t('The map as it is now');
            case 'outside': return v.id ? t('Saved outside the editor') : t('Changed outside the editor');
            case 'import': return t('Map imported');
            case 'restore':
                if (v.from && v.from.name) { return t('Restored “:name”', { name: v.from.name }); }
                return v.from ? t('Restored the version of :time', { time: clock(v.from.time) }) : t('Restored');
            default: return t('Edited');
        }
    }

    function icon(name) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'i');
        svg.setAttribute('aria-hidden', 'true');
        var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
        use.setAttribute('href', (window.WM_TIMELINE && window.WM_TIMELINE.icons || '') + '#' + name);
        svg.appendChild(use);
        return svg;
    }

    // ------------------------------------------------------------------
    // The list

    function load() {
        return ed.api('GET', '/editor/timeline/' + mapId).then(show).catch(function (e) {
            list.replaceChildren(h('p', { class: 'tl-empty', text: e.message }));
        });
    }

    function show(d) {
        versions = d.versions || [];
        note.textContent = t('Edits made within a minute of each other share one version. Savepoints are always kept; other versions past the last :n are dropped.', { n: d.limit });
        render();
    }

    function render() {
        var shown = onlyNamed.checked ? versions.filter(function (v) { return v.name; }) : versions;
        var out = [];
        if (!shown.length) {
            out.push(h('p', { class: 'tl-empty', text: onlyNamed.checked
                ? t('No savepoints yet. Create one before a test to come back to it in one click.')
                : t('No versions yet. Every change you make in the editor is kept here with its time.') }));
        }
        var lastDay = null;
        shown.forEach(function (v) {
            var d = day(v.time);
            if (d !== lastDay) {
                out.push(h('h4', { class: 'tl-day', text: d }));
                lastDay = d;
            }
            out.push(row(v));
        });
        list.replaceChildren.apply(list, out);
    }

    function row(v) {
        var main = h('button', {
            type: 'button', class: 'tl-main',
            title: v.id ? t('Preview this version') : exact(v.time),
            disabled: !v.id,
            'aria-current': v.current ? 'true' : false,
            onclick: function () { openPreview(v); }
        }, [
            icon(ICONS[v.kind] || 'pencil'),
            h('span', { class: 'tl-label', text: label(v) }),
            v.current ? h('span', { class: 'tl-badge', text: t('Current') }) : null,
            h('time', { datetime: new Date(v.time * 1000).toISOString(), title: exact(v.time), text: ago(v.time) })
        ]);
        var li = h('div', {
            class: 'tl-row' + (v.name ? ' named' : '') + (v.current ? ' current' : '') + (previewing && previewing.id === v.id ? ' previewing' : ''),
            role: 'listitem', 'data-id': v.id || ''
        }, [main]);
        if (v.id) {
            li.appendChild(h('span', { class: 'tl-actions' }, [
                v.current ? null : h('button', { type: 'button', title: t('Restore this version'), 'aria-label': t('Restore this version'), onclick: function () { restore(v); } }, [icon('history')]),
                h('button', { type: 'button', title: v.name ? t('Rename the savepoint') : t('Name this version (savepoint)'), 'aria-label': v.name ? t('Rename the savepoint') : t('Name this version (savepoint)'), onclick: function () { rename(li, v); } }, [icon(v.name ? 'pencil' : 'star')]),
                v.name ? h('button', { type: 'button', title: t('Remove the name (the version stays)'), 'aria-label': t('Remove the name (the version stays)'), onclick: function () { unname(v); } }, [icon('x')]) : null
            ]));
        }
        return li;
    }

    /** Name a version or rename a savepoint, in the row itself */
    function rename(li, v) {
        var input = h('input', { type: 'text', maxlength: 80, value: v.name || '', 'aria-label': t('Savepoint name'), placeholder: t('Savepoint name') });
        var done = false;
        function finish(save) {
            if (done) { return; }
            done = true;
            var name = input.value.trim();
            if (save && name && name !== v.name) {
                ed.api('POST', '/editor/timeline/' + mapId + '/savepoint', { name: name, version: v.id }).then(show).catch(function (e) { ed.toast(e.message, true); render(); });
            } else {
                render();
            }
        }
        input.addEventListener('keydown', function (evt) {
            if (evt.key === 'Enter') { finish(true); evt.preventDefault(); }
            if (evt.key === 'Escape') { finish(false); evt.preventDefault(); evt.stopPropagation(); }
        });
        input.addEventListener('blur', function () { finish(true); });
        li.replaceChildren(h('div', { class: 'tl-edit' }, [icon('star'), input]));
        input.focus();
        input.select();
    }

    function unname(v) {
        ed.api('POST', '/editor/timeline/' + mapId + '/unname', { version: v.id }).then(show).catch(function (e) { ed.toast(e.message, true); });
    }

    form.addEventListener('submit', function (evt) {
        evt.preventDefault();
        var name = nameField.value.trim();
        if (!name) { nameField.focus(); return; }
        ed.api('POST', '/editor/timeline/' + mapId + '/savepoint', { name: name }).then(function (d) {
            nameField.value = '';
            show(d);
            ed.toast(t('Savepoint “:name” created', { name: name }));
        }).catch(function (e) { ed.toast(e.message, true); });
    });

    onlyNamed.addEventListener('change', render);

    // ------------------------------------------------------------------
    // Restore

    function restore(v) {
        ed.run(function () {
            return ed.api('POST', '/editor/timeline/' + mapId + '/restore', { version: v.id, mapVersion: ed.mapVersion() }).then(function (d) {
                ed.applyRender(d);
                closePreview();
                ed.toast(t('“:name” is back. Undo puts the map back as it was.', { name: label(v) }));
                return load();
            });
        });
    }

    // ------------------------------------------------------------------
    // Preview over the drawing

    function openPreview(v) {
        if (!v.id) { return; }
        previewing = v;
        preview.hidden = false;
        previewTitle.textContent = label(v);
        preview.querySelector('.vp-time').textContent = exact(v.time);
        restoreButton.hidden = !!v.current;
        restoreButton.onclick = function () { restore(v); };
        previewBody.replaceChildren(h('p', { class: 'vp-loading', text: t('Loading…') }));
        render();
        ed.api('GET', '/editor/timeline/' + mapId + '/' + encodeURIComponent(v.id)).then(function (d) {
            if (previewing !== v) { return; }
            var doc = new DOMParser().parseFromString(d.svg, 'image/svg+xml');
            var svg = doc.documentElement;
            if (svg.nodeName !== 'svg') { throw new Error(t('The map could not be drawn')); }
            svg = document.importNode(svg, true);
            if (!svg.getAttribute('viewBox')) {
                svg.setAttribute('viewBox', '0 0 ' + d.width + ' ' + d.height);
            }
            svg.removeAttribute('width');
            svg.removeAttribute('height');
            svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
            svg.setAttribute('role', 'img');
            svg.setAttribute('aria-label', label(v));
            previewBody.replaceChildren(svg);
        }).catch(function (e) {
            previewBody.replaceChildren(h('p', { class: 'vp-loading', text: e.message }));
        });
    }

    function closePreview() {
        if (!previewing) { return; }
        previewing = null;
        preview.hidden = true;
        previewBody.replaceChildren();
        render();
    }

    preview.querySelector('[data-vp="close"]').addEventListener('click', closePreview);

    // ------------------------------------------------------------------
    // The panel

    function open() {
        opener = document.activeElement;
        panel.hidden = false;
        document.getElementById('app').classList.add('timeline-open');
        setPressed(true);
        list.replaceChildren(h('p', { class: 'tl-empty', text: t('Loading…') }));
        load();
        nameField.focus();
    }

    function close() {
        closePreview();
        panel.hidden = true;
        document.getElementById('app').classList.remove('timeline-open');
        setPressed(false);
        if (opener && opener.focus) { opener.focus(); }
    }

    function setPressed(on) {
        document.querySelectorAll('[data-cmd="timeline"]').forEach(function (b) {
            if (b.closest('#appbar')) { b.setAttribute('aria-expanded', on ? 'true' : 'false'); }
        });
    }

    panel.querySelector('[data-tl="close"]').addEventListener('click', close);
    document.addEventListener('keydown', function (evt) {
        if (evt.key !== 'Escape' || panel.hidden || evt.defaultPrevented) { return; }
        if (previewing) { closePreview(); } else { close(); }
        evt.preventDefault();
    });

    // every change in the editor is a version: keep the list up to date
    document.addEventListener('wm-editor-render', function () {
        if (panel.hidden) { return; }
        clearTimeout(reloadTimer);
        reloadTimer = setTimeout(load, 300);
    });
    // relative times move on
    setInterval(function () { if (!panel.hidden) { render(); } }, 30000);

    ed.timeline = {
        open: open, close: close, load: load, preview: openPreview,
        toggle: function () { if (panel.hidden) { open(); } else { close(); } }
    };
})();
