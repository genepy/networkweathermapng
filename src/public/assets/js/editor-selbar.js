/**
 * The contextual bar of the SVG editor (UI redesign step 9): a small bar
 * over the selection with what applies to it (align and space evenly,
 * group, lock, order, link, duplicate, delete and ⋯ for the menu of
 * actions). It exists only while something is selected, so the permanent
 * toolbars can stay hidden; they come back with ⋯ › Toolbars and panels ›
 * Movable toolbars (editor-toolbars.js), and then this bar is not shown.
 *
 * The buttons carry data-cmd and data-needs like the toolbars, so
 * editor-svg.js runs them and enables them.
 */
(function () {
    'use strict';

    var ed = window.WMEditor;
    var t = ed.t;
    var state = ed.state;
    var app = document.getElementById('app');
    var canvas = document.getElementById('canvas');
    var bar = document.getElementById('selbar');
    var alignMenu = document.getElementById('selbar-align');
    var alignButton = bar.querySelector('.sb-align');
    var rail = document.getElementById('tool-rail');
    var GAP = 12, EDGE = 8;
    var queued = false;

    function contextual() {
        return app.dataset.mode !== 'toolbars';
    }

    function show(button, on) {
        if (button) { button.hidden = !on; }
    }

    /** Which buttons make sense for this selection */
    function fill() {
        var items = ed.selected('node').concat(ed.selected('link'), ed.selected('zone'));
        var nodes = ed.selected('node').length;
        var grouped = items.some(function (i) { return i.group; });
        var groups = {};
        items.forEach(function (i) { groups[i.group || ('\u0000' + i.name)] = true; });
        var locked = items.length > 0 && items.every(function (i) { return i.locked; });

        show(alignButton, ed.alignCount() >= 2);
        show(bar.querySelector('[data-cmd="group"]'), items.length >= 2 && Object.keys(groups).length > 1);
        show(bar.querySelector('[data-cmd="ungroup"]'), grouped);
        ['lock', 'front', 'back'].forEach(function (c) { show(bar.querySelector('[data-cmd="' + c + '"]'), items.length > 0); });
        show(bar.querySelector('[data-cmd="link-selection"]'), nodes >= 1 && nodes <= 2);
        show(bar.querySelector('[data-cmd="duplicate"]'), nodes >= 1);
        // legends and timestamps too: Delete hides them
        show(bar.querySelector('[data-cmd="delete"]'), items.length > 0 || ed.deletableFurniture() > 0);

        var lock = bar.querySelector('[data-cmd="lock"]');
        var label = locked ? t('Unlock') : t('Lock');
        lock.querySelector('use').setAttribute('href', locked ? '#i-unlock' : '#i-lock');
        lock.setAttribute('aria-label', label);
        lock.title = (locked ? t('Unlock') : t('Lock')) + ' (Ctrl+L)';
        lock.querySelector('.lbl').textContent = label;

        var link = bar.querySelector('[data-cmd="link-selection"]');
        link.title = nodes === 2 ? t('Link the two selected nodes') : t('Draw a link from the selected node (L)');
        link.setAttribute('aria-label', link.title);

        // a separator only between two shown groups of buttons
        var sep = bar.querySelector('.sb-sep');
        var before = false, after = false, seen = false;
        Array.prototype.forEach.call(bar.children, function (c) {
            if (c === sep) { seen = true; return; }
            if (!c.hidden) { if (seen) { after = true; } else { before = true; } }
        });
        sep.hidden = !(before && after);
    }

    function wanted() {
        if (!contextual() || !state.model || state.textMode || state.placing || state.linkFrom || state.tool === 'link') { return false; }
        if (state.drag && state.drag.started !== false) { return false; }
        return state.selection.size > 0 && !!ed.selectionBox();
    }

    function place() {
        queued = false;
        if (!wanted()) {
            if (!bar.hidden && bar.contains(document.activeElement)) { canvas.focus && canvas.focus(); }
            bar.hidden = true;
            closeAlign();
            return;
        }
        fill();
        bar.hidden = false;
        var box = ed.selectionBox();
        var pad = 3 * state.zoom;
        var a = ed.toCanvas(box[0], box[1]), b = ed.toCanvas(box[2], box[3]);
        var cw = canvas.clientWidth, ch = canvas.clientHeight;
        var w = bar.offsetWidth, hh = bar.offsetHeight;
        var top = a.y - pad - GAP - hh;
        if (top < EDGE) { top = b.y + pad + GAP; }
        if (top + hh > ch - EDGE) { top = EDGE; }
        var left = (a.x + b.x) / 2 - w / 2;
        left = Math.max(EDGE, Math.min(left, cw - w - EDGE));
        // keep clear of the tool rail in the top left corner
        if (rail && !rail.hidden && rail.offsetParent) {
            var r = { right: rail.offsetLeft + rail.offsetWidth, bottom: rail.offsetTop + rail.offsetHeight };
            if (left < r.right + EDGE && top < r.bottom + EDGE) { left = Math.min(r.right + EDGE, Math.max(EDGE, cw - w - EDGE)); }
        }
        bar.style.left = Math.round(left) + 'px';
        bar.style.top = Math.round(top) + 'px';
    }

    function later() {
        if (queued) { return; }
        queued = true;
        requestAnimationFrame(place);
    }

    // ------------------------------------------------------------------
    // Align and space evenly: a small menu under its button

    function closeAlign() {
        try { if (alignMenu.matches(':popover-open')) { alignMenu.hidePopover(); } } catch (e) { /* no popover support */ }
    }

    alignMenu.addEventListener('toggle', function (evt) {
        alignButton.setAttribute('aria-expanded', evt.newState === 'open' ? 'true' : 'false');
        if (evt.newState !== 'open') { return; }
        var r = alignButton.getBoundingClientRect();
        alignMenu.style.left = Math.round(Math.max(EDGE, Math.min(r.left, window.innerWidth - alignMenu.offsetWidth - EDGE))) + 'px';
        alignMenu.style.top = Math.round(r.bottom + 6) + 'px';
        var first = alignMenu.querySelector('button:not(:disabled)');
        if (first) { first.focus(); }
    });
    alignMenu.addEventListener('click', function (evt) {
        if (evt.target.closest('button[data-cmd]')) {
            closeAlign();
            alignButton.focus();
        }
    });

    // arrows move between the buttons of the bar and of the align menu
    function arrows(root, horizontal) {
        root.addEventListener('keydown', function (evt) {
            var keys = horizontal ? { ArrowRight: 1, ArrowLeft: -1 } : { ArrowDown: 1, ArrowUp: -1, ArrowRight: 1, ArrowLeft: -1 };
            var step = keys[evt.key];
            if (!step && evt.key !== 'Home' && evt.key !== 'End') { return; }
            var list = Array.prototype.filter.call(root.querySelectorAll('button'), function (b) { return !b.hidden && !b.disabled; });
            if (!list.length) { return; }
            var i = list.indexOf(document.activeElement);
            var next = evt.key === 'Home' ? 0 : evt.key === 'End' ? list.length - 1 : (i + step + list.length) % list.length;
            list[next].focus();
            evt.preventDefault();
            evt.stopPropagation();
        });
    }
    arrows(bar, true);
    arrows(alignMenu, false);
    arrows(rail, false);

    // ------------------------------------------------------------------

    document.addEventListener('wm-editor-overlay', later);
    document.addEventListener('wm-editor-layout', later);
    window.addEventListener('resize', later);
    later();

    ed.selbar = { place: place };
})();
