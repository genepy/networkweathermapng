<?php
/** @var array $map @var int $mapId @var array $icons @var array $backgrounds @var array $iconThemes */
$e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$theme = in_array($_COOKIE['theme'] ?? '', ['light', 'dark'], true) ? $_COOKIE['theme'] : '';
?>
<!DOCTYPE html>
<html lang="<?= \App\Core\I18n::locale() ?>"<?= $theme ? ' data-theme="' . $theme . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= \App\Core\Csrf::metaTag() ?>
    <script src="/assets/js/csrf.js"></script>
    <script src="/assets/js/preferences.js"></script>
    <title><?= $e($title ?? __('Map Editor')) ?></title>
    <link rel="stylesheet" href="/assets/css/editor-svg.css">
</head>
<body>
<?php require __DIR__ . '/toolbar-icons.php'; ?>
<?php
// one toolbar button: an icon, a label shown when the toolbars show labels, a tooltip
$btn = static function (string $cmd, string $icon, string $label, string $title, string $extra = '') use ($e): string {
    return sprintf('<button type="button" data-cmd="%s" title="%s" aria-label="%s"%s><svg class="i" aria-hidden="true"><use href="#i-%s"/></svg><span class="lbl">%s</span></button>',
        $cmd, $e($title), $e($label), $extra ? ' ' . $extra : '', $icon, $e($label));
};
?>
<div id="app" data-map-id="<?= $mapId ?>">
    <header id="appbar">
        <a class="tb-back" href="/editor" title="<?= e__('Back to the map list') ?>" aria-label="<?= e__('Back to the map list') ?>"><svg class="i" aria-hidden="true"><use href="#i-back"/></svg></a>
        <span class="tb-title" title="<?= $e($map['name']) ?>"><?= $e($map['name']) ?></span>
        <span class="appbar-right">
            <?= $btn('map-properties', 'properties', __('Map properties'), __('Title, legend, timestamp, fonts, colours and link defaults of the map')) ?>
            <a class="tb-link" href="/map/<?= $mapId ?>" target="_blank" rel="noopener" title="<?= e__('Open the published map') ?>"><svg class="i" aria-hidden="true"><use href="#i-view"/></svg><span class="lbl"><?= e__('View map') ?></span></a>
            <a class="tb-link" href="/editor/classic/<?= $mapId ?>" title="<?= e__('The previous editor') ?>"><svg class="i" aria-hidden="true"><use href="#i-classic"/></svg><span class="lbl"><?= e__('Classic editor') ?></span></a>
            <button type="button" id="toolbars-button" popovertarget="toolbars-menu" title="<?= e__('Show, hide and arrange the toolbars and panels') ?>" aria-label="<?= e__('Toolbars and panels') ?>"><svg class="i" aria-hidden="true"><use href="#i-toolbars"/></svg><span class="lbl"><?= e__('Toolbars') ?></span></button>
            <button type="button" data-pref="theme" aria-label="<?= e__('Theme') ?>"
                    data-labels="<?= htmlspecialchars(json_encode(['' => __('Theme: automatic'), 'light' => __('Theme: light'), 'dark' => __('Theme: dark')])) ?>"></button>
        </span>
    </header>

    <div id="toolbars-menu" popover>
        <h3><?= e__('Toolbars') ?></h3>
        <div id="toolbars-list"></div>
        <h3><?= e__('Panels') ?></h3>
        <label><input type="checkbox" name="panel-palette"> <?= e__('Add panel (nodes and icons)') ?></label>
        <label><input type="checkbox" name="panel-inspector"> <?= e__('Properties panel') ?></label>
        <label><input type="checkbox" name="panel-minimap"> <?= e__('Overview map') ?></label>
        <h3><?= e__('Buttons') ?></h3>
        <label><?= e__('Size') ?>
            <select name="size">
                <option value="s"><?= e__('Small') ?></option>
                <option value="m"><?= e__('Medium') ?></option>
                <option value="l"><?= e__('Large') ?></option>
            </select>
        </label>
        <label><?= e__('Labels') ?>
            <select name="labels">
                <option value="auto"><?= e__('When there is room') ?></option>
                <option value="on"><?= e__('Always') ?></option>
                <option value="off"><?= e__('Icons only') ?></option>
            </select>
        </label>
        <p class="hint"><?= e__('Drag a toolbar by its handle to the top, a side or the bottom, or drop it on the map to make it float. A floating toolbar can be resized from its corner.') ?></p>
        <button type="button" id="toolbars-reset"><?= e__('Reset the layout') ?></button>
    </div>

    <div class="dock" id="dock-top" data-dock="top">
        <div class="toolbar" data-toolbar="history" aria-label="<?= e__('History') ?>" role="toolbar">
            <?= $btn('undo', 'undo', __('Undo'), __('Undo (Ctrl+Z)'), 'disabled') ?>
            <?= $btn('redo', 'redo', __('Redo'), __('Redo (Ctrl+Shift+Z)'), 'disabled') ?>
        </div>
        <div class="toolbar" data-toolbar="clipboard" aria-label="<?= e__('Clipboard') ?>" role="toolbar">
            <?= $btn('cut', 'cut', __('Cut'), __('Cut (Ctrl+X)'), 'data-needs="nodes1"') ?>
            <?= $btn('copy', 'copy', __('Copy'), __('Copy the selected nodes and the links between them, also for another map (Ctrl+C)'), 'data-needs="nodes1"') ?>
            <?= $btn('paste', 'paste', __('Paste'), __('Paste (Ctrl+V): where the mouse is, or next to the original'), 'disabled') ?>
        </div>
        <div class="toolbar" data-toolbar="tools" aria-label="<?= e__('Tools') ?>" role="toolbar">
            <?= $btn('tool-select', 'select', __('Select'), __('Select and move (V)'), 'class="active"') ?>
            <?= $btn('tool-link', 'link', __('Link'), __('Draw a link: click two nodes (L)')) ?>
            <?= $btn('zone', 'zone', __('Zone'), __('Add a zone: a labelled rectangle for a site, around the selected nodes if any')) ?>
        </div>
        <div class="toolbar" data-toolbar="align" aria-label="<?= e__('Align') ?>" role="toolbar" data-needs="objects2">
            <?= $btn('align-left', 'align-left', __('Left'), __('Align left edges')) ?>
            <?= $btn('align-hcenter', 'align-hcenter', __('Centre'), __('Align centres vertically')) ?>
            <?= $btn('align-right', 'align-right', __('Right'), __('Align right edges')) ?>
            <?= $btn('align-top', 'align-top', __('Top'), __('Align top edges')) ?>
            <?= $btn('align-vcenter', 'align-vcenter', __('Middle'), __('Align centres horizontally')) ?>
            <?= $btn('align-bottom', 'align-bottom', __('Bottom'), __('Align bottom edges')) ?>
            <?= $btn('distribute-h', 'distribute-h', __('Space across'), __('Space evenly, left to right'), 'data-needs="objects3"') ?>
            <?= $btn('distribute-v', 'distribute-v', __('Space down'), __('Space evenly, top to bottom'), 'data-needs="objects3"') ?>
        </div>
        <div class="toolbar" data-toolbar="arrange" aria-label="<?= e__('Arrange') ?>" role="toolbar" data-needs="any">
            <?= $btn('group', 'group', __('Group'), __('Group (Ctrl+G)')) ?>
            <?= $btn('ungroup', 'ungroup', __('Ungroup'), __('Ungroup (Ctrl+Shift+G)')) ?>
            <?= $btn('lock', 'lock', __('Lock'), __('Lock or unlock position (Ctrl+L)')) ?>
            <?= $btn('front', 'front', __('Front'), __('Bring to front (Ctrl+])')) ?>
            <?= $btn('back', 'send-back', __('Back'), __('Send to back (Ctrl+[)')) ?>
        </div>
        <div class="toolbar" data-toolbar="tidy" aria-label="<?= e__('Tidy up') ?>" role="toolbar">
            <?= $btn('autoroute', 'autoroute', __('Auto-route'), __('Route the selected links (or all) around nodes')) ?>
            <?= $btn('spread', 'spread', __('Spread apart'), __('Move overlapping nodes apart')) ?>
            <?= $btn('untangle', 'untangle', __('Untangle labels'), __('Move bandwidth labels that overlap')) ?>
            <?= $btn('find', 'find', __('Select…'), __('Select by criteria (Ctrl+F)')) ?>
        </div>
        <div class="toolbar" data-toolbar="view" aria-label="<?= e__('View') ?>" role="toolbar">
            <span class="tb-layers">
                <?= $btn('layers', 'layers', __('Layers'), __('Hide layers while editing (not saved in the map)'), 'aria-expanded="false" aria-controls="layers-panel"') ?>
                <span id="layers-panel" role="group" aria-label="<?= e__('Layers shown in the editor') ?>" hidden>
                    <label><input type="checkbox" name="background" checked> <?= e__('Background image') ?></label>
                    <label><input type="checkbox" name="zones" checked> <?= e__('Zones') ?></label>
                    <label><input type="checkbox" name="links" checked> <?= e__('Links') ?></label>
                    <label><input type="checkbox" name="linklabels" checked> <?= e__('Link labels (bandwidth)') ?></label>
                    <label><input type="checkbox" name="nodelabels" checked> <?= e__('Node labels') ?></label>
                    <small><?= e__('Hiding a layer only changes your view in this editor. It is not saved in the map, and other people still see everything. This browser remembers your choice.') ?></small>
                    <div id="custom-layers" role="group" aria-label="<?= e__('Your layers') ?>"></div>
                </span>
            </span>
            <?= $btn('text-mode', 'text', __('Text mode'), __('Edit the map as text (.conf)')) ?>
            <?= $btn('snap', 'grid', __('Grid'), __('Snap to a 10 px grid (G)')) ?>
            <?= $btn('zoom-out', 'zoom-out', __('Zoom out'), __('Zoom out (-)')) ?>
            <button type="button" data-cmd="zoom-reset" id="zoom-level" title="<?= e__('Actual size (0)') ?>" aria-label="<?= e__('Zoom level, click for actual size') ?>"><span class="zoom-tag"><?= e__('Zoom') ?></span> <span id="zoom-value">100%</span></button>
            <?= $btn('zoom-in', 'zoom-in', __('Zoom in'), __('Zoom in (+)')) ?>
            <?= $btn('zoom-fit', 'fit', __('Fit'), __('Fit to window (F)')) ?>
        </div>
    </div>
    <div class="dock vertical" id="dock-left" data-dock="left"></div>
    <div class="dock vertical" id="dock-right" data-dock="right"></div>
    <div class="dock" id="dock-bottom" data-dock="bottom"></div>

    <aside id="palette">
        <div class="splitter" data-panel="palette" title="<?= e__('Drag to resize, double-click to hide') ?>"></div>
        <h3><?= e__('Add') ?></h3>
        <p class="hint"><?= e__('Drag onto the map') ?></p>
        <div class="palette-item" draggable="true" data-template="DEFAULT">
            <span class="pi-box"><?= e__('Label') ?></span> <?= e__('Node') ?>
        </div>
        <div id="palette-templates"></div>
        <div class="palette-head">
            <h3><?= e__('Icons') ?></h3>
            <span class="palette-tools">
                <button type="button" id="icon-fold" title="<?= e__('Fold or unfold every theme') ?>" aria-label="<?= e__('Fold or unfold every theme') ?>">&#x2195;</button>
                <button type="button" id="icon-manage" aria-pressed="false" title="<?= e__('Remove icons from the library') ?>" aria-label="<?= e__('Remove icons') ?>">&#x1F5D1;</button>
                <button type="button" id="icon-add" title="<?= e__('Add icons to the library (PNG, GIF, JPEG or SVG)') ?>" aria-label="<?= e__('Add icons') ?>">+</button>
            </span>
        </div>
        <input type="search" id="icon-search" placeholder="<?= e__('Search icons') ?>" aria-label="<?= e__('Search icons') ?>" autocomplete="off">
        <div id="palette-icons"></div>
    </aside>

    <main id="canvas">
        <svg id="stage" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse">
                    <path d="M10 0H0V10" fill="none" stroke="#0002" stroke-width="0.5"/>
                </pattern>
            </defs>
            <rect id="paper" x="0" y="0" width="0" height="0"/>
            <g id="map"></g>
            <rect id="grid-layer" x="0" y="0" width="0" height="0" fill="url(#grid)" pointer-events="none"/>
            <g id="overlay"></g>
            <rect id="resize-handle" width="12" height="12"><title><?= e__('Drag to resize the map') ?></title></rect>
        </svg>
        <div id="minimap">
            <svg id="minimap-svg" xmlns="http://www.w3.org/2000/svg">
                <use href="#map"/>
                <rect id="minimap-view"/>
            </svg>
        </div>
        <div id="busy" hidden><?= e__('Saving…') ?></div>
        <div id="toast" hidden></div>
    </main>

    <aside id="inspector">
        <div class="splitter" data-panel="inspector" title="<?= e__('Drag to resize, double-click to hide') ?>"></div>
        <div id="inspector-body"></div>
    </aside>

    <section id="text-mode" hidden aria-label="<?= e__('Text mode') ?>">
        <div class="text-bar">
            <strong><?= e__('Text mode') ?></strong>
            <span id="text-status" role="status" aria-live="polite"></span>
            <span class="text-actions">
                <button type="button" id="text-cancel"><?= e__('Back to the drawing') ?></button>
                <button type="button" id="text-apply" class="primary" title="<?= e__('Save the text and return to the drawing (Ctrl+Enter)') ?>"><?= e__('Apply') ?></button>
            </span>
        </div>
        <div class="text-body">
            <div id="text-gutter" aria-hidden="true"></div>
            <textarea id="text-area" spellcheck="false" wrap="off" autocomplete="off" autocapitalize="off" aria-label="<?= e__('Map configuration') ?>"></textarea>
        </div>
        <ol id="text-problems" aria-label="<?= e__('Problems found') ?>"></ol>
        <p class="text-help"><?= e__('Changes are checked by the engine as you type. INCLUDE and output paths (*OUTPUTFILE, IMAGEURI) cannot be changed here. Apply saves the text as one step you can undo.') ?></p>
    </section>
</div>

<div id="ctx-menu" role="menu" tabindex="-1" aria-label="<?= e__('Actions') ?>" hidden></div>

<dialog id="find-dialog">
    <form method="dialog">
        <h3><?= e__('Select by criteria') ?></h3>
        <label><?= e__('Look at') ?>
            <select name="kind">
                <option value="all"><?= e__('Nodes and links') ?></option>
                <option value="node"><?= e__('Nodes') ?></option>
                <option value="link"><?= e__('Links') ?></option>
            </select>
        </label>
        <label><?= e__('Field') ?>
            <select name="field">
                <option value="name"><?= e__('Name') ?></option>
                <option value="label"><?= e__('Label') ?></option>
                <option value="target"><?= e__('Data target') ?></option>
                <option value="icon"><?= e__('Icon') ?></option>
                <option value="group"><?= e__('Group id') ?></option>
                <option value="width"><?= e__('Link width') ?></option>
                <option value="bandwidth_in"><?= e__('Bandwidth') ?></option>
                <option value="node"><?= e__('Link touches node') ?></option>
            </select>
        </label>
        <label><?= e__('Match') ?>
            <select name="mode">
                <option value="contains"><?= e__('contains') ?></option>
                <option value="equals"><?= e__('is') ?></option>
                <option value="regex"><?= e__('matches regex') ?></option>
                <option value="empty"><?= e__('is empty') ?></option>
            </select>
        </label>
        <label><?= e__('Value') ?> <input name="value" autocomplete="off"></label>
        <label class="inline"><input type="checkbox" name="add"> <?= e__('Add to the current selection') ?></label>
        <p class="find-count"></p>
        <menu>
            <button value="cancel" formnovalidate><?= e__('Cancel') ?></button>
            <button value="ok" class="primary"><?= e__('Select') ?></button>
        </menu>
    </form>
</dialog>

<dialog id="icon-dialog">
    <form method="dialog">
        <h3><?= e__('Add icons') ?></h3>
        <label><?= e__('Theme') ?>
            <input name="theme" list="icon-theme-list" required maxlength="32" autocomplete="off" placeholder="<?= e__('An existing theme or a new one') ?>">
        </label>
        <datalist id="icon-theme-list"></datalist>
        <label><?= e__('Files') ?>
            <input type="file" name="icons" multiple accept=".png,.gif,.jpg,.jpeg,.svg,image/png,image/gif,image/jpeg,image/svg+xml">
        </label>
        <p class="hint"><?= e__('PNG, GIF or JPEG up to 1024 px, or SVG (cleaned, and drawn as a 64 px PNG for the PNG map). 2 MB at most per file.') ?></p>
        <ul class="icon-errors"></ul>
        <menu>
            <button value="cancel" formnovalidate><?= e__('Cancel') ?></button>
            <button value="ok" class="primary"><?= e__('Add') ?></button>
        </menu>
    </form>
</dialog>

<dialog id="mapprops-dialog" aria-labelledby="mapprops-title">
    <form method="dialog">
        <h3 id="mapprops-title"><?= e__('Map properties') ?></h3>
        <nav class="mapprops-tabs" role="tablist"></nav>
        <div class="mapprops-body"></div>
        <menu>
            <button value="cancel" formnovalidate><?= e__('Cancel') ?></button>
            <button value="ok" class="primary"><?= e__('Apply') ?></button>
        </menu>
    </form>
</dialog>

<dialog id="layer-dialog">
    <form method="dialog">
        <h3><?= e__('Add to a layer') ?></h3>
        <label><?= e__('Layer') ?>
            <input name="layer" list="layer-list" required maxlength="40" autocomplete="off" placeholder="<?= e__('An existing layer or a new one') ?>">
        </label>
        <datalist id="layer-list"></datalist>
        <p class="hint"><?= e__('A layer is a set of nodes, links and zones you name. It is saved in the map; you can then hide, lock or select it as a whole from the Layers panel.') ?></p>
        <menu>
            <button value="cancel" formnovalidate><?= e__('Cancel') ?></button>
            <button value="ok" class="primary"><?= e__('Add') ?></button>
        </menu>
    </form>
</dialog>

<dialog id="resize-dialog">
    <form method="dialog">
        <h3><?= e__('The map size changes') ?></h3>
        <p><?= e__('What should happen to what is already on the map?') ?></p>
        <label class="inline"><input type="radio" name="mode" value="scale" checked> <?= e__('Move everything with the new size') ?></label>
        <label class="inline indent"><input type="checkbox" name="keep_aspect" checked> <?= e__('Keep proportions') ?></label>
        <label class="inline indent"><input type="checkbox" name="scale_sizes"> <?= e__('Also scale icons and link widths') ?></label>
        <label class="inline"><input type="radio" name="mode" value="extend"> <?= e__('Leave everything where it is') ?></label>
        <menu>
            <button value="cancel" formnovalidate><?= e__('Cancel') ?></button>
            <button value="ok" class="primary"><?= e__('Apply') ?></button>
        </menu>
    </form>
</dialog>

<script>
window.WM_T = <?= json_encode((object) \App\Core\I18n::section('editor-js'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.WM_EDITOR = {
    mapId: <?= $mapId ?>,
    icons: <?= json_encode(array_values($icons), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    iconThemes: <?= json_encode($iconThemes, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    backgrounds: <?= json_encode(array_values($backgrounds), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    fonts: <?= json_encode($fonts, JSON_HEX_TAG | JSON_HEX_AMP) ?>
};
</script>
<script src="/assets/js/editor-svg.js"></script>
<script src="/assets/js/editor-icons.js"></script>
<script src="/assets/js/editor-toolbars.js"></script>
<script src="/assets/js/editor-mapprops.js"></script>
</body>
</html>
