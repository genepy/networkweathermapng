<?php
/** @var array $map @var int $mapId @var array $icons @var array $backgrounds */
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
<div id="app" data-map-id="<?= $mapId ?>">
    <header id="toolbar">
        <a class="tb-back" href="/editor" title="<?= e__('Back to the map list') ?>">&larr;</a>
        <span class="tb-title"><?= $e($map['name']) ?></span>

        <div class="tb-group">
            <button data-cmd="undo" title="<?= e__('Undo (Ctrl+Z)') ?>" disabled>&#x21B6;</button>
            <button data-cmd="redo" title="<?= e__('Redo (Ctrl+Shift+Z)') ?>" disabled>&#x21B7;</button>
        </div>

        <div class="tb-group">
            <button data-cmd="cut" data-needs="nodes1" title="<?= e__('Cut (Ctrl+X)') ?>" aria-label="<?= e__('Cut') ?>">&#x2702;</button>
            <button data-cmd="copy" data-needs="nodes1" title="<?= e__('Copy the selected nodes and the links between them, also for another map (Ctrl+C)') ?>" aria-label="<?= e__('Copy') ?>">&#x2398;</button>
            <button data-cmd="paste" title="<?= e__('Paste (Ctrl+V): where the mouse is, or next to the original') ?>" aria-label="<?= e__('Paste') ?>" disabled>&#x1F4CB;</button>
        </div>

        <div class="tb-group">
            <button data-cmd="tool-select" class="active" title="<?= e__('Select and move (V)') ?>">&#x2B09; <?= e__('Select') ?></button>
            <button data-cmd="tool-link" title="<?= e__('Draw a link: click two nodes (L)') ?>">&#x2194; <?= e__('Link') ?></button>
            <button data-cmd="zone" title="<?= e__('Add a zone: a labelled rectangle for a site, around the selected nodes if any') ?>">&#x25AD; <?= e__('Zone') ?></button>
        </div>

        <div class="tb-group" data-needs="objects2">
            <button data-cmd="align-left" title="<?= e__('Align left edges') ?>">&#x23B8;&#x2190;</button>
            <button data-cmd="align-hcenter" title="<?= e__('Align centres vertically') ?>">&#x2195;</button>
            <button data-cmd="align-right" title="<?= e__('Align right edges') ?>">&#x2192;&#x23B9;</button>
            <button data-cmd="align-top" title="<?= e__('Align top edges') ?>">&#x2912;</button>
            <button data-cmd="align-vcenter" title="<?= e__('Align centres horizontally') ?>">&#x2194;</button>
            <button data-cmd="align-bottom" title="<?= e__('Align bottom edges') ?>">&#x2913;</button>
            <button data-cmd="distribute-h" title="<?= e__('Space evenly, left to right') ?>" data-needs="objects3">&#x22EF;</button>
            <button data-cmd="distribute-v" title="<?= e__('Space evenly, top to bottom') ?>" data-needs="objects3">&#x22EE;</button>
        </div>

        <div class="tb-group" data-needs="any">
            <button data-cmd="group" title="<?= e__('Group (Ctrl+G)') ?>"><?= e__('Group selection') ?></button>
            <button data-cmd="ungroup" title="<?= e__('Ungroup (Ctrl+Shift+G)') ?>"><?= e__('Ungroup') ?></button>
            <button data-cmd="lock" title="<?= e__('Lock or unlock position (Ctrl+L)') ?>">&#x1F512;</button>
            <button data-cmd="front" title="<?= e__('Bring to front (Ctrl+])') ?>"><?= e__('Front') ?></button>
            <button data-cmd="back" title="<?= e__('Send to back (Ctrl+[)') ?>"><?= e__('Back') ?></button>
        </div>

        <div class="tb-group">
            <button data-cmd="autoroute" title="<?= e__('Route the selected links (or all) around nodes') ?>"><?= e__('Auto-route') ?></button>
            <button data-cmd="spread" title="<?= e__('Move overlapping nodes apart') ?>"><?= e__('Spread apart') ?></button>
            <button data-cmd="untangle" title="<?= e__('Move bandwidth labels that overlap') ?>"><?= e__('Untangle labels') ?></button>
            <button data-cmd="find" title="<?= e__('Select by criteria (Ctrl+F)') ?>"><?= e__('Select…') ?></button>
        </div>

        <div class="tb-group tb-right">
            <span class="tb-layers">
                <button data-cmd="layers" aria-expanded="false" aria-controls="layers-panel" title="<?= e__('Hide layers while editing (not saved in the map)') ?>"><?= e__('Layers') ?></button>
                <span id="layers-panel" role="group" aria-label="<?= e__('Layers shown in the editor') ?>" hidden>
                    <label><input type="checkbox" name="background" checked> <?= e__('Background image') ?></label>
                    <label><input type="checkbox" name="zones" checked> <?= e__('Zones') ?></label>
                    <label><input type="checkbox" name="links" checked> <?= e__('Links') ?></label>
                    <label><input type="checkbox" name="linklabels" checked> <?= e__('Link labels (bandwidth)') ?></label>
                    <label><input type="checkbox" name="nodelabels" checked> <?= e__('Node labels') ?></label>
                    <small><?= e__('Only in this editor, in this browser.') ?></small>
                </span>
            </span>
            <button data-cmd="text-mode" title="<?= e__('Edit the map as text (.conf)') ?>"><?= e__('Text mode') ?></button>
            <button data-cmd="snap" title="<?= e__('Snap to a 10 px grid (G)') ?>"><?= e__('Grid') ?></button>
            <button data-cmd="zoom-out" title="<?= e__('Zoom out (-)') ?>">&minus;</button>
            <button data-cmd="zoom-reset" id="zoom-level" title="<?= e__('Actual size (0)') ?>">100%</button>
            <button data-cmd="zoom-in" title="<?= e__('Zoom in (+)') ?>">+</button>
            <button data-cmd="zoom-fit" title="<?= e__('Fit to window (F)') ?>"><?= e__('Fit') ?></button>
            <a class="tb-link" href="/map/<?= $mapId ?>" target="_blank" rel="noopener" title="<?= e__('Open the published map') ?>"><?= e__('View map') ?></a>
            <a class="tb-link" href="/editor/classic/<?= $mapId ?>" title="<?= e__('The previous editor') ?>"><?= e__('Classic editor') ?></a>
            <button type="button" data-pref="theme" aria-label="<?= e__('Theme') ?>"
                    data-labels="<?= htmlspecialchars(json_encode(['' => __('Theme: automatic'), 'light' => __('Theme: light'), 'dark' => __('Theme: dark')])) ?>"></button>
        </div>
    </header>

    <aside id="palette">
        <h3><?= e__('Add') ?></h3>
        <p class="hint"><?= e__('Drag onto the map') ?></p>
        <div class="palette-item" draggable="true" data-template="DEFAULT">
            <span class="pi-box"><?= e__('Label') ?></span> <?= e__('Node') ?>
        </div>
        <div id="palette-templates"></div>
        <h3><?= e__('Icons') ?></h3>
        <div id="palette-icons">
            <?php foreach ($icons as $icon): ?>
                <div class="palette-icon" draggable="true" data-icon="<?= $e($icon) ?>" title="<?= $e(basename($icon)) ?>">
                    <img src="/<?= $e($icon) ?>" alt="" loading="lazy">
                </div>
            <?php endforeach; ?>
            <?php if (!$icons): ?>
                <p class="hint"><?= e__('No icons in public/objects') ?></p>
            <?php endif; ?>
        </div>
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
    backgrounds: <?= json_encode(array_values($backgrounds), JSON_HEX_TAG | JSON_HEX_AMP) ?>
};
</script>
<script src="/assets/js/editor-svg.js"></script>
</body>
</html>
