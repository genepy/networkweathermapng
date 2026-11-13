/**
 * Focus areas in the SVG editor (SET focus_*, see FocusAreas.php): parts
 * of a broad map that the viewer and the kiosk put forward, the rest
 * dimmed, or the view zoomed onto them, or both.
 *
 * The *Focus areas* section of the map properties lists them: name, how
 * the area stands out, whether the map opens on it (the kiosk shows that
 * one), its rectangle, *Use the view* (the part of the map in the editor
 * view becomes the area), *Show* and *Delete*. The context menu adds one
 * around the selection, or over the part of the map in view. While the
 * section is open, the areas are drawn over the canvas.
 */
(function () {
    'use strict';

    var ed = window.WMEditor;
    if (!ed) { return; }
    var t = ed.t, h = ed.h;
    var SVGNS = 'http://www.w3.org/2000/svg';
    var canvas = document.getElementById('canvas');
    var overlay = document.getElementById('overlay');
    var layer = null;

    var STYLES = [
        ['both', t('Dim the rest and zoom onto it')],
        ['dim', t('Dim the rest')],
        ['zoom', t('Zoom onto it')]
    ];

    function areas() {
        return (ed.state.model && ed.state.model.focus) || [];
    }

    function titleOf(a) {
        return a.title || t('Area :id', { id: a.id });
    }

    /** The part of the map in the editor view, inset a little, within the map */
    function viewBox() {
        var m = ed.state.model.map, z = ed.state.zoom, v = ed.state.view;
        var w = canvas.clientWidth / z, hh = canvas.clientHeight / z;
        var x1 = Math.max(0, v.x + w * 0.05), y1 = Math.max(0, v.y + hh * 0.05);
        var x2 = Math.min(m.width, v.x + w * 0.95), y2 = Math.min(m.height, v.y + hh * 0.95);
        if (x2 - x1 < 10 || y2 - y1 < 10) {
            return [0, 0, m.width, m.height];
        }
        return [Math.round(x1), Math.round(y1), Math.round(x2), Math.round(y2)];
    }

    /** Opens the section on the new area, so the person sees it and names it */
    function created(r) {
        var id = r.focus && r.focus[0];
        setTimeout(function () {
            if (ed.openMapProperties) { ed.openMapProperties('focus'); }
            var input = id && document.querySelector('#inspector .focus-area[data-id="' + id + '"] input');
            if (input) { input.focus(); }
        }, 0);
        return [];
    }

    /** A new area around the selection, or over the part of the map in view */
    function addArea() {
        var box = ed.state.selection.size ? ed.selectionBox() : null, pad = 30;
        var m = ed.state.model.map;
        box = box
            ? [Math.max(0, box[0] - pad), Math.max(0, box[1] - pad), Math.min(m.width, box[2] + pad), Math.min(m.height, box[3] + pad)].map(Math.round)
            : viewBox();
        var op = { op: 'add_focus', box: box };
        // the first area is the one the map opens on
        if (!areas().length) { op['default'] = true; }
        return ed.send([op], created).then(function () { ed.toast(t('Focus area added. Ctrl+Z to undo.')); });
    }

    function setArea(id, props) {
        return ed.send([{ op: 'set_focus', name: id, props: props }], function () { return undefined; });
    }

    /** One area of the section */
    function row(a) {
        var title = h('input', { type: 'text', value: a.title, placeholder: t('Area :id', { id: a.id }), autocomplete: 'off', 'aria-label': t('Name') });
        title.addEventListener('change', function () { setArea(a.id, { title: title.value }); });

        var style = h('select', { 'aria-label': t('How it stands out') }, STYLES.map(function (s) {
            return h('option', { value: s[0], text: s[1], selected: s[0] === a.style });
        }));
        style.addEventListener('change', function () { setArea(a.id, { style: style.value }); });

        var def = h('input', { type: 'checkbox', checked: !!a['default'] });
        def.addEventListener('change', function () { setArea(a.id, { 'default': def.checked }); });

        var b = a.box;
        var nums = [['x', b[0], 'X'], ['y', b[1], 'Y'], ['w', b[2] - b[0], t('Width')], ['h', b[3] - b[1], t('Height')]].map(function (f) {
            var inp = h('input', { type: 'number', value: f[1], min: f[0] === 'w' || f[0] === 'h' ? 10 : undefined, step: 1, 'aria-label': f[2], 'data-k': f[0] });
            return h('label', { 'class': 'focus-num' }, [h('span', { text: f[2] }), inp]);
        });
        var numBox = h('div', { 'class': 'focus-nums' }, nums);
        numBox.addEventListener('change', function () {
            var v = {};
            numBox.querySelectorAll('input').forEach(function (i) { v[i.dataset.k] = Math.round(+i.value); });
            if (!(v.w >= 10 && v.h >= 10)) { return; }
            setArea(a.id, { box: [v.x, v.y, v.x + v.w, v.y + v.h] });
        });

        var actions = h('div', { 'class': 'focus-actions' }, [
            h('button', { type: 'button', text: t('Use the view'), title: t('The part of the map in the editor view becomes the area'), onclick: function () { setArea(a.id, { box: viewBox() }); } }),
            h('button', { type: 'button', text: t('Show'), title: t('Move the editor view onto the area'), onclick: function () { ed.zoomTo(a.box); } }),
            h('button', { type: 'button', 'class': 'danger', text: t('Delete'), onclick: function () {
                ed.send([{ op: 'delete_focus', name: a.id }], function () { return undefined; }).then(function () { ed.toast(t('Focus area deleted. Ctrl+Z to undo.')); });
            } })
        ]);

        return h('div', { 'class': 'focus-area', 'data-id': a.id }, [
            h('label', {}, [t('Name'), title]),
            h('label', {}, [t('How it stands out'), style]),
            h('label', { 'class': 'inline' }, [def, ' ' + t('Open the map on this area, also in the kiosk')]),
            numBox,
            actions
        ]);
    }

    /** The fields of the Focus areas section of the map properties */
    function pane() {
        var list = areas();
        return [
            h('p', { 'class': 'mapprops-explain', text: t('A focus area puts a part of a broad map forward in the live view and the kiosk: the rest of the map dims, or the view zooms onto it, or both. The pictures (PNG, SVG) do not change.') })
        ].concat(list.map(row)).concat([
            h('button', { type: 'button', 'class': 'focus-add', text: t('Add a focus area'), title: t('Over the part of the map in view; right-click a selection to put one around it'), onclick: addArea }),
            list.length ? h('span', { 'class': 'hint', text: t('Viewers pick an area from the focus button of the toolbar. A link in the address keeps it: #a=<id>.') }) : null
        ].filter(Boolean));
    }

    function summary() {
        var list = areas();
        if (!list.length) { return t('None'); }
        var def = list.filter(function (a) { return a['default']; })[0];
        return def ? titleOf(def) : String(list.length);
    }

    /** The areas over the canvas, while their section is open */
    function draw() {
        if (!overlay || !overlay.parentNode) { return; }
        if (!layer) {
            layer = document.createElementNS(SVGNS, 'g');
            layer.setAttribute('id', 'focus-areas');
            layer.setAttribute('pointer-events', 'none');
            overlay.parentNode.insertBefore(layer, overlay);
        }
        layer.replaceChildren();
        if (!document.querySelector('#inspector .map-sec[data-section="focus"][open]')) { return; }
        var k = 1 / (ed.state.zoom || 1);
        areas().forEach(function (a) {
            var b = a.box;
            var r = document.createElementNS(SVGNS, 'rect');
            r.setAttribute('class', 'focus-frame' + (a['default'] ? ' default' : ''));
            r.setAttribute('x', b[0]);
            r.setAttribute('y', b[1]);
            r.setAttribute('width', b[2] - b[0]);
            r.setAttribute('height', b[3] - b[1]);
            r.setAttribute('rx', 6);
            layer.appendChild(r);
            var label = document.createElementNS(SVGNS, 'text');
            label.setAttribute('class', 'focus-label');
            label.setAttribute('x', b[0] + 6 * k);
            // above the frame, clear of the zone labels inside it
            label.setAttribute('y', b[1] - 5 * k);
            label.setAttribute('font-size', 12 * k);
            label.textContent = titleOf(a);
            layer.appendChild(label);
        });
    }

    // after the panel: the overlay is drawn before the properties panel
    var queued = false;
    function later() {
        if (queued) { return; }
        queued = true;
        setTimeout(function () { queued = false; draw(); }, 0);
    }
    document.addEventListener('wm-editor-overlay', later);
    // the section opens or closes: show or hide the areas
    document.addEventListener('toggle', function (e) {
        if (e.target && e.target.matches && e.target.matches('.map-sec[data-section="focus"]')) { draw(); }
    }, true);
    canvas.addEventListener('wheel', later, { passive: true });

    ed.focusPane = pane;
    ed.focusSummary = summary;
    ed.addFocusArea = addArea;
})();
