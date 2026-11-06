/**
 * The Ctrl+K palette in the SVG editor (UI redesign step 9): every command
 * of the editor with its shortcut, then the nodes, links and zones of this
 * map (a click selects the item and zooms onto it), then what the palette
 * finds on the other pages (maps, nodes of the other maps, Settings).
 * Only the commands that apply now are listed: with a selection, the ones
 * for the selection come first.
 */
(function () {
    'use strict';

    var ed = window.WMEditor;
    var palette = window.WMPalette;
    if (!ed || !palette) { return; }
    var t = ed.t;
    var state = ed.state;
    var mapId = state && document.getElementById('app') ? parseInt(document.getElementById('app').dataset.mapId, 10) : 0;

    function cmd(name) {
        return function () {
            if (ed.commands[name]) { ed.commands[name](); }
        };
    }

    function click(selector) {
        return function () {
            var el = document.querySelector(selector);
            if (el) { el.click(); }
        };
    }

    function count() {
        var nodes = ed.selected('node').length, links = ed.selected('link').length, zones = ed.selected('zone').length;
        var items = ed.selected('node').concat(ed.selected('link'), ed.selected('zone'));
        return {
            nodes: nodes, links: links, any: state.selection.size, items: items.length,
            objects: ed.alignCount(),
            grouped: items.some(function (i) { return i.group; }),
            locked: items.length > 0 && items.every(function (i) { return i.locked; }),
            layered: items.some(function (i) { return i.layer; }),
            zones: zones
        };
    }

    /** Every command, in the order shown when nothing is typed; `when` says if it applies now */
    function commands() {
        var c = count();
        var editing = !state.textMode;
        var mode = ed.layout ? ed.layout.get().mode : 'contextual';
        var list = [
            // the selection
            { icon: '#i-align-left', label: t('Align left edges'), keys: 'align', when: c.objects >= 2, sel: true, run: cmd('align-left') },
            { icon: '#i-align-hcenter', label: t('Align centres vertically'), keys: 'align center middle', when: c.objects >= 2, sel: true, run: cmd('align-hcenter') },
            { icon: '#i-align-right', label: t('Align right edges'), keys: 'align', when: c.objects >= 2, sel: true, run: cmd('align-right') },
            { icon: '#i-align-top', label: t('Align top edges'), keys: 'align', when: c.objects >= 2, sel: true, run: cmd('align-top') },
            { icon: '#i-align-vcenter', label: t('Align centres horizontally'), keys: 'align center middle', when: c.objects >= 2, sel: true, run: cmd('align-vcenter') },
            { icon: '#i-align-bottom', label: t('Align bottom edges'), keys: 'align', when: c.objects >= 2, sel: true, run: cmd('align-bottom') },
            { icon: '#i-distribute-h', label: t('Space evenly, left to right'), keys: 'distribute', when: c.objects >= 3, sel: true, run: cmd('distribute-h') },
            { icon: '#i-distribute-v', label: t('Space evenly, top to bottom'), keys: 'distribute', when: c.objects >= 3, sel: true, run: cmd('distribute-v') },
            { icon: '#i-link', label: t('Link the two selected nodes'), keys: 'connect', when: c.nodes === 2, sel: true, run: cmd('link-selection') },
            { icon: '#i-group', label: t('Group'), shortcut: 'Ctrl+G', when: c.items >= 2, sel: true, run: cmd('group') },
            { icon: '#i-ungroup', label: t('Ungroup'), shortcut: 'Ctrl+Shift+G', when: c.grouped, sel: true, run: cmd('ungroup') },
            { icon: c.locked ? '#i-unlock' : '#i-lock', label: c.locked ? t('Unlock') : t('Lock'), shortcut: 'Ctrl+L', keys: 'lock unlock', when: c.items > 0, sel: true, run: cmd('lock') },
            { icon: '#i-front', label: t('Bring to front'), shortcut: 'Ctrl+]', keys: 'order', when: c.items > 0, sel: true, run: cmd('front') },
            { icon: '#i-send-back', label: t('Send to back'), shortcut: 'Ctrl+[', keys: 'order', when: c.items > 0, sel: true, run: cmd('back') },
            { icon: '#i-layers', label: t('Add to a layer…'), when: c.items > 0, sel: true, run: cmd('add-layer') },
            { icon: '#i-zone', label: t('Add a zone around the selection'), when: c.nodes > 0, sel: true, run: cmd('zone') },
            { icon: '#i-autoroute', label: t('Route with right angles'), keys: 'angled', when: c.links > 0, sel: true, run: cmd('right-angles') },
            { icon: '#i-copy', label: t('Duplicate'), shortcut: 'Ctrl+D', keys: 'clone', when: c.nodes > 0, sel: true, run: cmd('duplicate') },
            { icon: '#i-cut', label: t('Cut'), shortcut: 'Ctrl+X', when: c.nodes > 0, sel: true, run: cmd('cut') },
            { icon: '#i-copy', label: t('Copy'), shortcut: 'Ctrl+C', when: c.nodes > 0, sel: true, run: cmd('copy') },
            { icon: '#i-trash', label: t('Delete'), shortcut: t('Del'), keys: 'remove', when: c.items > 0, sel: true, run: cmd('delete') },
            // adding
            { icon: 'rectangle-horizontal', label: t('Add a node'), keys: 'new insert', when: editing, run: cmd('add-node') },
            { icon: '#i-note', label: t('Add a note'), keys: 'new text', when: editing, run: cmd('note') },
            { icon: '#i-zone', label: t('Add a zone'), keys: 'new site rectangle', when: editing && c.nodes === 0, run: cmd('zone') },
            { icon: '#i-link', label: t('Draw a link'), shortcut: 'L', keys: 'link tool new', when: editing, run: cmd('tool-link') },
            { icon: '#i-paste', label: t('Paste'), shortcut: 'Ctrl+V', when: editing && ed.hasClip(), run: cmd('paste') },
            // selecting
            { icon: '#i-select', label: t('Select all'), shortcut: 'Ctrl+A', keys: 'everything', when: editing, run: cmd('select-all') },
            { icon: '#i-find', label: t('Select by criteria'), shortcut: 'Ctrl+F', keys: 'find search', when: editing, run: cmd('find') },
            // tidying up
            { icon: '#i-autoroute', label: t('Route the selected links (or all) around nodes'), keys: 'auto-route autoroute', when: editing, run: cmd('autoroute') },
            { icon: '#i-spread', label: t('Move overlapping nodes apart'), keys: 'spread overlap', when: editing && state.overlaps.size > 0, run: cmd('spread') },
            { icon: '#i-untangle', label: t('Move bandwidth labels that overlap'), keys: 'untangle labels', when: editing, run: cmd('untangle') },
            // history
            { icon: '#i-undo', label: t('Undo'), shortcut: 'Ctrl+Z', when: editing && !!state.history.undo, run: cmd('undo') },
            { icon: '#i-redo', label: t('Redo'), shortcut: 'Ctrl+Shift+Z', when: editing && !!state.history.redo, run: cmd('redo') },
            // view
            { icon: '#i-fit', label: t('Fit to window'), shortcut: 'F', keys: 'zoom', when: true, run: cmd('zoom-fit') },
            { icon: '#i-zoom-in', label: t('Zoom in'), shortcut: '+', when: true, run: cmd('zoom-in') },
            { icon: '#i-zoom-out', label: t('Zoom out'), shortcut: '-', when: true, run: cmd('zoom-out') },
            { icon: '#i-view', label: t('Actual size'), shortcut: '0', keys: 'zoom 100', when: true, run: cmd('zoom-reset') },
            { icon: '#i-grid', label: state.snap ? t('Stop snapping to the grid') : t('Snap to a 10 px grid'), shortcut: 'G', keys: 'grid snap', when: editing, run: cmd('snap') },
            { icon: '#i-layers', label: t('Hide layers while editing'), keys: 'layers show', when: true, run: cmd('layers') },
            { icon: '#i-text', label: state.textMode ? t('Back to the drawing') : t('Edit the map as text (.conf)'), keys: 'text mode conf source', when: true, run: cmd('text-mode') },
            // the map and the editor
            { icon: 'sliders-horizontal', label: t('Map properties'), keys: 'title background legend colours fonts', when: editing, run: function () { ed.select([]); } },
            { icon: 'settings', label: t('Map settings'), keys: 'group kiosk recording access time zone', when: true, run: click('#editor-menu a[href^="/editor/maps/edit/"]') },
            { icon: 'eye', label: t('Open the published map'), keys: 'view viewer', when: true, run: click('#appbar .tb-view') },
            { icon: '#i-toolbars', label: mode === 'contextual' ? t('Use the movable toolbars') : t('Use the bar next to the selection'), keys: 'toolbars layout contextual', when: !!ed.layout, run: function () { ed.layout.setMode(mode === 'contextual' ? 'toolbars' : 'contextual'); } },
            { icon: 'sun-moon', label: t('Change the theme'), keys: 'theme dark light', when: true, run: click('#editor-menu [data-pref="theme"]') },
            { icon: 'list', label: t('Keyboard shortcuts'), shortcut: '?', keys: 'help keys', when: true, run: cmd('shortcuts') }
        ];
        return list.filter(function (e) { return e.when; }).map(function (e) {
            if (e.sel) { e.context = t('Selection'); }
            return e;
        });
    }

    /** The nodes, links and zones of this map: select and zoom onto one */
    function items() {
        var out = [];
        function go(kind, name) {
            return function () {
                ed.select([kind + ':' + name]);
                var box = ed.selectionBox();
                if (box) { ed.zoomTo(box); }
            };
        }
        state.nodes.forEach(function (n) {
            var label = n.note ? (n.note_text || '').split('\n')[0] : (n.label || n.name);
            out.push({ icon: n.note ? '#i-note' : 'rectangle-horizontal', label: label || n.name, keys: n.name + ' ' + (n.site || ''), context: n.note ? t('Note') : t('Node'), run: go('node', n.name) });
        });
        state.links.forEach(function (l) {
            out.push({ icon: '#i-link', label: l.name, keys: l.a + ' ' + l.b, context: l.a + ' ↔ ' + l.b, run: go('link', l.name) });
        });
        state.zones.forEach(function (z) {
            out.push({ icon: '#i-zone', label: z.label || z.name, keys: z.name, context: t('Zone'), run: go('zone', z.name) });
        });
        return out;
    }

    palette.register({
        // first, so that a name typed finds its item before the commands
        kind: 'here', title: t('On this map'), first: 0, limit: 6,
        placeholder: t('Search commands, nodes and maps'),
        mapId: mapId,
        entries: function () { return state.model && !state.textMode ? items() : []; }
    });
    palette.register({
        kind: 'editor', title: t('Editor'), first: 12, limit: 10,
        entries: function () { return state.model ? commands() : []; }
    });
})();
