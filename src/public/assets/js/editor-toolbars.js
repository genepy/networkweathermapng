/**
 * Layout of the SVG editor: toolbars that can be docked at the top, on a
 * side or at the bottom, or float over the map; shown or hidden; with
 * small, medium or large buttons, with or without labels. The side panels
 * can be hidden and resized. The layout is kept in this browser only.
 */
(function () {
    'use strict';

    var ed = window.WMEditor;
    var t = ed.t, h = ed.h;
    var KEY = 'wm-editor-layout', VERSION = 1;

    var app = document.getElementById('app');
    var canvas = document.getElementById('canvas');
    var menu = document.getElementById('toolbars-menu');
    var docks = {};
    Array.prototype.forEach.call(document.querySelectorAll('.dock'), function (d) { docks[d.dataset.dock] = d; });
    var bars = {};
    Array.prototype.forEach.call(document.querySelectorAll('.toolbar[data-toolbar]'), function (b, i) {
        bars[b.dataset.toolbar] = b;
        b.dataset.order = i;
    });
    var ORDER = Object.keys(bars);

    var NAMES = {
        history: t('History'), clipboard: t('Clipboard'), tools: t('Tools'), align: t('Align'),
        arrange: t('Arrange'), tidy: t('Tidy up'), view: t('View')
    };

    var PANEL_SIZES = { palette: [140, 420], inspector: [220, 560] };

    function defaults() {
        var out = { version: VERSION, size: 'm', labels: 'auto', panels: { palette: true, inspector: true, minimap: true }, widths: {}, bars: {} };
        ORDER.forEach(function (id, i) { out.bars[id] = { dock: 'top', order: i, hidden: false }; });
        return out;
    }

    function load() {
        var d = defaults();
        try {
            var saved = JSON.parse(localStorage.getItem(KEY) || 'null');
            if (saved && saved.version === VERSION) {
                ['size', 'labels'].forEach(function (k) { if (typeof saved[k] === 'string') { d[k] = saved[k]; } });
                Object.keys(d.panels).forEach(function (k) { if (saved.panels && typeof saved.panels[k] === 'boolean') { d.panels[k] = saved.panels[k]; } });
                Object.keys(PANEL_SIZES).forEach(function (k) { if (saved.widths && +saved.widths[k] > 0) { d.widths[k] = +saved.widths[k]; } });
                ORDER.forEach(function (id) { if (saved.bars && saved.bars[id]) { Object.assign(d.bars[id], saved.bars[id]); } });
            }
        } catch (e) { /* defaults */ }
        return d;
    }

    var layout = load();

    function save() {
        try { localStorage.setItem(KEY, JSON.stringify(layout)); } catch (e) { /* this page only */ }
    }

    // ------------------------------------------------------------------
    // Applying the layout

    function apply() {
        app.dataset.size = layout.size;
        app.dataset.labels = layout.labels;
        app.classList.toggle('no-palette', !layout.panels.palette);
        app.classList.toggle('no-inspector', !layout.panels.inspector);
        app.classList.toggle('no-minimap', !layout.panels.minimap);
        Object.keys(PANEL_SIZES).forEach(function (k) {
            if (layout.widths[k]) { app.style.setProperty('--' + k + '-w', layout.widths[k] + 'px'); } else { app.style.removeProperty('--' + k + '-w'); }
        });

        ORDER.slice().sort(function (a, b) { return layout.bars[a].order - layout.bars[b].order; }).forEach(function (id) {
            var bar = bars[id], conf = layout.bars[id];
            var dock = conf.dock === 'float' ? canvas : (docks[conf.dock] || docks.top);
            if (bar.parentNode !== dock || dock === canvas) { dock.appendChild(bar); }
            bar.hidden = !!conf.hidden;
            bar.classList.toggle('floating', conf.dock === 'float');
            if (conf.dock === 'float') {
                bar.style.left = Math.max(0, conf.x || 0) + 'px';
                bar.style.top = Math.max(0, conf.y || 0) + 'px';
                bar.style.width = conf.w ? conf.w + 'px' : '';
            } else {
                bar.style.left = bar.style.top = bar.style.width = '';
            }
        });
        Object.keys(docks).forEach(function (k) {
            docks[k].classList.toggle('empty', !Array.prototype.some.call(docks[k].children, function (c) { return !c.hidden; }));
        });
        keepFloatingInside();
        fillMenu();
        // the canvas size may have changed
        requestAnimationFrame(function () { ed.updateView(); });
    }

    function keepFloatingInside() {
        ORDER.forEach(function (id) {
            var bar = bars[id], conf = layout.bars[id];
            if (conf.dock !== 'float' || bar.hidden) { return; }
            var maxX = Math.max(0, canvas.clientWidth - bar.offsetWidth), maxY = Math.max(0, canvas.clientHeight - bar.offsetHeight);
            bar.style.left = Math.min(Math.max(0, conf.x || 0), maxX) + 'px';
            bar.style.top = Math.min(Math.max(0, conf.y || 0), maxY) + 'px';
        });
    }

    // ------------------------------------------------------------------
    // Grips: drag a toolbar to a dock or onto the map

    ORDER.forEach(function (id) {
        var bar = bars[id];
        var grip = h('span', { 'class': 'tb-grip', title: t('Drag to move the :name toolbar', { name: NAMES[id] || id }), 'aria-hidden': 'true' });
        grip.innerHTML = '<svg class="i"><use href="#i-grip"/></svg>';
        bar.insertBefore(grip, bar.firstChild);
        grip.addEventListener('pointerdown', function (evt) { startDrag(evt, id); });
        if (window.ResizeObserver) {
            new ResizeObserver(function () {
                var conf = layout.bars[id];
                if (conf.dock === 'float' && bar.style.width && !drag) {
                    conf.w = Math.round(bar.offsetWidth);
                    save();
                }
            }).observe(bar);
        }
    });

    var drag = null;

    function startDrag(evt, id) {
        if (evt.button !== 0) { return; }
        evt.preventDefault();
        var bar = bars[id], r = bar.getBoundingClientRect();
        drag = { id: id, dx: evt.clientX - r.left, dy: evt.clientY - r.top, pointer: evt.pointerId };
        evt.target.setPointerCapture(evt.pointerId);
        app.classList.add('moving-toolbar');
        bar.classList.add('dragging');
        bar.style.position = 'fixed';
        bar.style.width = r.width + 'px';
        moveDrag(evt);
    }

    function targetAt(x, y) {
        var found = null;
        Object.keys(docks).forEach(function (k) {
            var r = docks[k].getBoundingClientRect();
            if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) { found = k; }
        });
        if (found) { return found; }
        var c = canvas.getBoundingClientRect();
        return x >= c.left && x <= c.right && y >= c.top && y <= c.bottom ? 'float' : null;
    }

    function moveDrag(evt) {
        if (!drag || evt.pointerId !== drag.pointer) { return; }
        var bar = bars[drag.id];
        bar.style.left = (evt.clientX - drag.dx) + 'px';
        bar.style.top = (evt.clientY - drag.dy) + 'px';
        var target = targetAt(evt.clientX, evt.clientY);
        Object.keys(docks).forEach(function (k) { docks[k].classList.toggle('drop', k === target); });
    }

    function endDrag(evt) {
        if (!drag || evt.pointerId !== drag.pointer) { return; }
        var id = drag.id, bar = bars[id], conf = layout.bars[id];
        var target = targetAt(evt.clientX, evt.clientY);
        app.classList.remove('moving-toolbar');
        bar.classList.remove('dragging');
        bar.style.position = '';
        Object.keys(docks).forEach(function (k) { docks[k].classList.remove('drop'); });

        if (target === 'float') {
            var c = canvas.getBoundingClientRect();
            conf.dock = 'float';
            conf.x = Math.round(evt.clientX - drag.dx - c.left);
            conf.y = Math.round(evt.clientY - drag.dy - c.top);
        } else if (target) {
            conf.dock = target;
            delete conf.w;
            conf.order = orderAt(target, id, evt.clientX, evt.clientY);
        }
        drag = null;
        renumber();
        save();
        apply();
    }

    /** Order of a toolbar dropped in a dock: before the first one it is in front of */
    function orderAt(dock, id, x, y) {
        var vertical = dock === 'left' || dock === 'right';
        var others = ORDER.filter(function (o) { return o !== id && layout.bars[o].dock === dock && !layout.bars[o].hidden; })
            .sort(function (a, b) { return layout.bars[a].order - layout.bars[b].order; });
        for (var i = 0; i < others.length; i++) {
            var r = bars[others[i]].getBoundingClientRect();
            var before = vertical ? y < r.top + r.height / 2 : (y < r.bottom && x < r.left + r.width / 2) || y < r.top;
            if (before) { return layout.bars[others[i]].order - 0.5; }
        }
        return 1000;
    }

    function renumber() {
        ORDER.slice().sort(function (a, b) { return layout.bars[a].order - layout.bars[b].order; })
            .forEach(function (id, i) { layout.bars[id].order = i; });
    }

    document.addEventListener('pointermove', moveDrag);
    document.addEventListener('pointerup', endDrag);
    document.addEventListener('pointercancel', function (evt) {
        if (drag) { drag = null; app.classList.remove('moving-toolbar'); apply(); }
    });

    // ------------------------------------------------------------------
    // Side panels: drag the inner edge to resize, double-click to hide

    Array.prototype.forEach.call(document.querySelectorAll('.splitter'), function (sp) {
        var panel = sp.dataset.panel, start = null;
        sp.addEventListener('pointerdown', function (evt) {
            if (evt.button !== 0) { return; }
            evt.preventDefault();
            sp.setPointerCapture(evt.pointerId);
            start = { x: evt.clientX, w: sp.parentNode.getBoundingClientRect().width };
        });
        sp.addEventListener('pointermove', function (evt) {
            if (!start) { return; }
            var dx = evt.clientX - start.x;
            var w = start.w + (panel === 'palette' ? dx : -dx);
            w = Math.round(Math.min(PANEL_SIZES[panel][1], Math.max(PANEL_SIZES[panel][0], w)));
            layout.widths[panel] = w;
            app.style.setProperty('--' + panel + '-w', w + 'px');
            ed.updateView();
        });
        sp.addEventListener('pointerup', function () { if (start) { start = null; save(); } });
        sp.addEventListener('dblclick', function () { layout.panels[panel] = false; save(); apply(); });
    });

    // ------------------------------------------------------------------
    // The Toolbars menu

    function fillMenu() {
        var list = document.getElementById('toolbars-list');
        list.replaceChildren.apply(list, ORDER.map(function (id) {
            var box = h('input', { type: 'checkbox', checked: !layout.bars[id].hidden });
            box.addEventListener('change', function () { layout.bars[id].hidden = !box.checked; save(); apply(); });
            return h('label', {}, [box, ' ' + (NAMES[id] || id)]);
        }));
        Object.keys(layout.panels).forEach(function (k) {
            var box = menu.querySelector('input[name="panel-' + k + '"]');
            if (box) { box.checked = layout.panels[k]; }
        });
        menu.querySelector('select[name="size"]').value = layout.size;
        menu.querySelector('select[name="labels"]').value = layout.labels;
    }

    menu.addEventListener('change', function (evt) {
        var el = evt.target;
        if (el.name && el.name.indexOf('panel-') === 0) { layout.panels[el.name.slice(6)] = el.checked; }
        else if (el.name === 'size' || el.name === 'labels') { layout[el.name] = el.value; }
        else { return; }
        save();
        apply();
    });

    document.getElementById('toolbars-reset').addEventListener('click', function () {
        layout = defaults();
        save();
        apply();
    });

    window.addEventListener('resize', keepFloatingInside);

    apply();

    window.WMEditor.layout = { get: function () { return layout; }, apply: apply };
})();
