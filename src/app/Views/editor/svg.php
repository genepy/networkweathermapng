<?php
/** @var array $map @var int $mapId @var array $icons @var array $backgrounds */
$e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= \App\Core\Csrf::metaTag() ?>
    <script src="/assets/js/csrf.js"></script>
    <title><?= $e($title ?? 'Map Editor') ?></title>
    <link rel="stylesheet" href="/assets/css/editor-svg.css">
</head>
<body>
<div id="app" data-map-id="<?= $mapId ?>">
    <header id="toolbar">
        <a class="tb-back" href="/editor" title="Back to the map list">&larr;</a>
        <span class="tb-title"><?= $e($map['name']) ?></span>

        <div class="tb-group">
            <button data-cmd="undo" title="Undo (Ctrl+Z)" disabled>&#x21B6;</button>
            <button data-cmd="redo" title="Redo (Ctrl+Shift+Z)" disabled>&#x21B7;</button>
        </div>

        <div class="tb-group">
            <button data-cmd="tool-select" class="active" title="Select and move (V)">&#x2B09; Select</button>
            <button data-cmd="tool-link" title="Draw a link: click two nodes (L)">&#x2194; Link</button>
        </div>

        <div class="tb-group" data-needs="nodes2">
            <button data-cmd="align-left" title="Align left edges">&#x23B8;&#x2190;</button>
            <button data-cmd="align-hcenter" title="Align centres vertically">&#x2195;</button>
            <button data-cmd="align-right" title="Align right edges">&#x2192;&#x23B9;</button>
            <button data-cmd="align-top" title="Align top edges">&#x2912;</button>
            <button data-cmd="align-vcenter" title="Align centres horizontally">&#x2194;</button>
            <button data-cmd="align-bottom" title="Align bottom edges">&#x2913;</button>
            <button data-cmd="distribute-h" title="Space evenly, left to right" data-needs="nodes3">&#x22EF;</button>
            <button data-cmd="distribute-v" title="Space evenly, top to bottom" data-needs="nodes3">&#x22EE;</button>
        </div>

        <div class="tb-group" data-needs="any">
            <button data-cmd="group" title="Group (Ctrl+G)">Group</button>
            <button data-cmd="ungroup" title="Ungroup (Ctrl+Shift+G)">Ungroup</button>
            <button data-cmd="lock" title="Lock or unlock position (Ctrl+L)">&#x1F512;</button>
            <button data-cmd="front" title="Bring to front (Ctrl+])">Front</button>
            <button data-cmd="back" title="Send to back (Ctrl+[)">Back</button>
        </div>

        <div class="tb-group">
            <button data-cmd="autoroute" title="Route the selected links (or all) around nodes">Auto-route</button>
            <button data-cmd="untangle" title="Move bandwidth labels that overlap">Untangle labels</button>
            <button data-cmd="find" title="Select by criteria (Ctrl+F)">Select&hellip;</button>
        </div>

        <div class="tb-group tb-right">
            <button data-cmd="snap" title="Snap to a 10 px grid (G)">Grid</button>
            <button data-cmd="zoom-out" title="Zoom out (-)">&minus;</button>
            <button data-cmd="zoom-reset" id="zoom-level" title="Actual size (0)">100%</button>
            <button data-cmd="zoom-in" title="Zoom in (+)">+</button>
            <button data-cmd="zoom-fit" title="Fit to window (F)">Fit</button>
            <a class="tb-link" href="/map/<?= $mapId ?>" target="_blank" rel="noopener" title="Open the published map">View map</a>
            <a class="tb-link" href="/editor/classic/<?= $mapId ?>" title="The previous editor">Classic editor</a>
        </div>
    </header>

    <aside id="palette">
        <h3>Add</h3>
        <p class="hint">Drag onto the map</p>
        <div class="palette-item" draggable="true" data-template="DEFAULT">
            <span class="pi-box">Label</span> Node
        </div>
        <div id="palette-templates"></div>
        <h3>Icons</h3>
        <div id="palette-icons">
            <?php foreach ($icons as $icon): ?>
                <div class="palette-icon" draggable="true" data-icon="<?= $e($icon) ?>" title="<?= $e(basename($icon)) ?>">
                    <img src="/<?= $e($icon) ?>" alt="" loading="lazy">
                </div>
            <?php endforeach; ?>
            <?php if (!$icons): ?>
                <p class="hint">No icons in public/objects</p>
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
            <rect id="resize-handle" width="12" height="12"><title>Drag to resize the map</title></rect>
        </svg>
        <div id="minimap">
            <svg id="minimap-svg" xmlns="http://www.w3.org/2000/svg">
                <use href="#map"/>
                <rect id="minimap-view"/>
            </svg>
        </div>
        <div id="busy" hidden>Saving&hellip;</div>
        <div id="toast" hidden></div>
    </main>

    <aside id="inspector">
        <div id="inspector-body"></div>
    </aside>
</div>

<dialog id="find-dialog">
    <form method="dialog">
        <h3>Select by criteria</h3>
        <label>Look at
            <select name="kind">
                <option value="all">Nodes and links</option>
                <option value="node">Nodes</option>
                <option value="link">Links</option>
            </select>
        </label>
        <label>Field
            <select name="field">
                <option value="name">Name</option>
                <option value="label">Label</option>
                <option value="target">Data target</option>
                <option value="icon">Icon</option>
                <option value="group">Group</option>
                <option value="width">Link width</option>
                <option value="bandwidth_in">Bandwidth</option>
                <option value="node">Link touches node</option>
            </select>
        </label>
        <label>Match
            <select name="mode">
                <option value="contains">contains</option>
                <option value="equals">is</option>
                <option value="regex">matches regex</option>
                <option value="empty">is empty</option>
            </select>
        </label>
        <label>Value <input name="value" autocomplete="off"></label>
        <label class="inline"><input type="checkbox" name="add"> Add to the current selection</label>
        <p class="find-count"></p>
        <menu>
            <button value="cancel" formnovalidate>Cancel</button>
            <button value="ok" class="primary">Select</button>
        </menu>
    </form>
</dialog>

<dialog id="resize-dialog">
    <form method="dialog">
        <h3>The map size changes</h3>
        <p>What should happen to what is already on the map?</p>
        <label class="inline"><input type="radio" name="mode" value="scale" checked> Move everything with the new size</label>
        <label class="inline indent"><input type="checkbox" name="keep_aspect" checked> Keep proportions</label>
        <label class="inline indent"><input type="checkbox" name="scale_sizes"> Also scale icons and link widths</label>
        <label class="inline"><input type="radio" name="mode" value="extend"> Leave everything where it is</label>
        <menu>
            <button value="cancel" formnovalidate>Cancel</button>
            <button value="ok" class="primary">Apply</button>
        </menu>
    </form>
</dialog>

<script>
window.WM_EDITOR = {
    mapId: <?= $mapId ?>,
    icons: <?= json_encode(array_values($icons), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    backgrounds: <?= json_encode(array_values($backgrounds), JSON_HEX_TAG | JSON_HEX_AMP) ?>
};
</script>
<script src="/assets/js/editor-svg.js"></script>
</body>
</html>
