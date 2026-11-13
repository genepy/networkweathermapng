<?php
    /*
     * The map viewer: the map drawn as SVG (viewer.js). ?fullscreen=1 is the
     * page without the application shell; $kiosk is the bare map that the
     * kiosk (/cycle) shows in a frame, without the toolbar or the panel.
     */
    $isFullscreen = !empty($fullscreen);
    $isKiosk = !empty($kiosk);
    $mapId = (int) $map['id'];
    $svgFsUrl = '/map/' . $mapId . '?fullscreen=1';
    $dataRefresh = !empty($autoRefresh) ? (int) $autoRefresh : (int) $config->get('MAP_REFRESH_INTERVAL', 300);
    $normalUrl = '/map/' . $mapId;
    $mapTitle = $map['title_cache'] ?: $map['name'];
    $canEdit = $auth->canEdit();
    $historyHours = isset($historyHours) ? (int) $historyHours : 24;
    $fileName = \App\Controllers\MapController::downloadName((string) $map['name']);
?>
<?php if ($isFullscreen): ?>
<?php $theme = in_array($_COOKIE['theme'] ?? '', ['light', 'dark'], true) ? $_COOKIE['theme'] : ''; ?>
<!DOCTYPE html>
<html lang="<?= \App\Core\I18n::locale() ?>"<?= $theme ? ' data-theme="' . $theme . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title><?= htmlspecialchars($mapTitle) ?> - NetworkWeathermapNG</title>
    <link rel="icon" href="<?= asset('/assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= asset('/assets/css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= asset('/assets/css/weathermap.css') ?>">
    <link rel="stylesheet" href="<?= asset('/assets/css/viewer.css') ?>">
    <link rel="stylesheet" href="<?= asset('/assets/css/ui.css') ?>">
    <script>window.WM_T = Object.assign(window.WM_T || {}, <?= json_encode((object) \App\Core\I18n::section('ui-js'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>);</script>
    <script src="<?= asset('/assets/js/ui.js') ?>" defer></script>
    <script src="<?= asset('/assets/js/weathermap.js') ?>" data-uplot="<?= asset('/assets/vendor/uplot/uPlot.iife.min.js') ?>" data-uplot-css="<?= asset('/assets/vendor/uplot/uPlot.min.css') ?>" defer></script>
</head>
<body class="wmv-noc">
<?php else: ?>
<link rel="stylesheet" href="<?= asset('/assets/css/viewer.css') ?>">
<?php endif; ?>

<div class="map-view-page svg-view<?= $isFullscreen ? ' fullscreen' : '' ?>">
    <div class="wmv<?= $isFullscreen ? ' wmv-fullscreen wmv-standalone' : '' ?><?= $isKiosk ? ' wmv-kiosk' : '' ?>" id="wmv"
         data-map-id="<?= $mapId ?>"
         data-timezone="<?= htmlspecialchars($timezone) ?>"
         data-refresh="<?= max(30, $dataRefresh) ?>"
         data-fullscreen="<?= $isFullscreen ? '1' : '0' ?>"
         data-normal-url="<?= htmlspecialchars($normalUrl) ?>"
         data-kiosk-url="<?= htmlspecialchars($svgFsUrl) ?>"
         data-png-url="/map/<?= $mapId ?>/image"
         data-history="<?= !empty($history) ? '1' : '0' ?>"<?php if ($isKiosk): ?>
         data-kiosk="1" data-zoom="<?= (int) ($zoom ?? 0) ?>"<?php endif; ?>>
        <div class="wmv-viewport" tabindex="0" role="application" aria-label="<?= htmlspecialchars(__('Map :name, zoom with the wheel, drag to move', ['name' => $mapTitle])) ?>">
            <div class="wmv-basemap" hidden></div>
            <div class="wmv-stage"></div>
            <div class="wmv-clusters"></div>
            <div class="wmv-message" role="status"><?= e__('Loading the map…') ?></div>
        </div>

        <div class="wmv-bar wmv-fades" role="toolbar" aria-label="<?= e__('Map view') ?>">
            <nav class="wmv-crumbs" aria-label="<?= e__('Breadcrumb') ?>">
                <a href="/"><?= e__('Maps') ?></a>
                <?= icon('chevron-right', 'icon wmv-crumb-sep') ?>
                <h1 title="<?= htmlspecialchars($mapTitle) ?>"><?= htmlspecialchars($mapTitle) ?></h1>
                <span class="wmv-badge" data-live="<?= e__('Live') ?>" data-playback="<?= e__('Playback') ?>"><?= e__('Live') ?></span>
            </nav>
            <form class="wmv-find" role="search" autocomplete="off">
                <?= icon('search') ?>
                <input type="search" class="wmv-find-input" list="wmv-nodes" placeholder="<?= e__('Find a node') ?>" aria-label="<?= e__('Find a node') ?>" aria-keyshortcuts="/" data-keys="viewer.find">
                <kbd aria-hidden="true" data-keys="viewer.find">/</kbd>
                <datalist id="wmv-nodes"></datalist>
            </form>
            <?php if (!empty($history)): ?>
            <div class="segmented wmv-when" role="group" aria-label="<?= e__('Time shown') ?>">
                <button type="button" data-act="live" aria-pressed="true" aria-label="<?= e__('Live') ?>" title="<?= e__('The map with the latest data') ?>"><span class="wmv-live-dot" aria-hidden="true"></span><span><?= e__('Live') ?></span></button>
                <button type="button" data-act="history" aria-pressed="false" aria-keyshortcuts="H" data-keys="viewer.history" aria-label="<?= e__('Last :hours h', ['hours' => $historyHours]) ?>" title="<?= e__('Play back the recorded hours') ?>"><?= icon('history') ?><span><?= e__('Last :hours h', ['hours' => $historyHours]) ?></span></button>
            </div>
            <?php endif; ?>
            <details class="row-menu wmv-more wmv-areas" hidden>
                <summary class="wmv-icon-btn" title="<?= e__('Focus areas') ?>" aria-label="<?= e__('Focus areas') ?>"><?= icon('focus') ?></summary>
                <div class="row-menu-items" role="menu" aria-label="<?= e__('Focus areas') ?>"></div>
            </details>
            <button type="button" class="wmv-icon-btn" data-act="panel" aria-controls="wmv-panel" aria-pressed="false" aria-keyshortcuts="P" data-keys="viewer.panel"
                    title="<?= e__('Side panel: sites, total traffic, legend') ?>" aria-label="<?= e__('Side panel') ?>"><?= icon('panel-left') ?></button>
            <?php if ($isFullscreen): ?>
            <a class="wmv-icon-btn" href="<?= htmlspecialchars($normalUrl) ?>" data-act="exit" aria-keyshortcuts="F" title="<?= e__('Leave fullscreen (Esc)') ?>" aria-label="<?= e__('Leave fullscreen') ?>"><?= icon('minimize') ?></a>
            <?php else: ?>
            <button type="button" class="wmv-icon-btn" data-act="fullscreen" aria-keyshortcuts="F" data-keys="viewer.fullscreen" aria-pressed="false"
                    data-enter="<?= e__('Fullscreen') ?>" data-leave="<?= e__('Leave fullscreen') ?>"
                    title="<?= e__('Fullscreen') ?>" aria-label="<?= e__('Fullscreen') ?>"><?= icon('maximize') ?></button>
            <?php endif; ?>
            <?php if ($canEdit && !$isFullscreen): ?>
            <a class="wmv-icon-btn" href="/editor/maps/edit/<?= $mapId ?>?return=<?= rawurlencode($normalUrl) ?>" data-map-settings title="<?= e__('Map settings') ?>" aria-label="<?= e__('Map settings') ?>"><?= icon('settings') ?></a>
            <?php endif; ?>
            <details class="row-menu wmv-more">
                <summary class="wmv-icon-btn" title="<?= e__('More actions') ?>" aria-label="<?= e__('More actions') ?>"><?= icon('ellipsis') ?></summary>
                <div class="row-menu-items">
                    <button type="button" data-act="copy-kiosk"><?= icon('link') ?><?= e__('Copy kiosk link') ?></button>
                    <a href="/map/<?= $mapId ?>/svg/download" download="<?= htmlspecialchars($fileName) ?>.svg"><?= icon('download') ?><?= e__('Download as SVG') ?></a>
                    <a href="/map/<?= $mapId ?>/image" download="<?= htmlspecialchars($fileName) ?>.png"><?= icon('download') ?><?= e__('Download PNG') ?></a>
                </div>
            </details>
            <?php if ($canEdit): ?>
            <a class="wm-btn wm-btn-primary wmv-edit" href="/editor/edit/<?= $mapId ?>" aria-keyshortcuts="E" data-keys="viewer.edit"><?= icon('pencil') ?><span><?= e__('Edit') ?></span></a>
            <?php endif; ?>
        </div>

        <?php if (!empty($history)): ?>
        <div class="wmv-history" role="group" aria-label="<?= e__('Playback') ?>" hidden>
            <button type="button" class="wmv-btn" data-hist="play" title="<?= e__('Play or pause') ?>" data-keys="viewer.play" aria-label="<?= e__('Play') ?>"><?= icon('play') ?></button>
            <input type="range" class="wmv-history-slider" min="0" max="0" value="0" aria-label="<?= e__('Time of the frame shown') ?>">
            <span class="wmv-history-time" aria-live="polite"></span>
            <select class="wmv-history-speed" aria-label="<?= e__('Playback speed') ?>">
                <option value="1000">1×</option>
                <option value="400" selected>2×</option>
                <option value="150">5×</option>
                <option value="60">10×</option>
            </select>
        </div>
        <?php endif; ?>

        <div class="wmv-status" hidden>
            <span class="wmv-status-when"><span class="wmv-dot" aria-hidden="true"></span><span class="wmv-updated"></span></span>
            <span class="wmv-status-max"></span>
            <span class="wmv-legend" hidden>
                <span class="wmv-legend-min"></span>
                <span class="wmv-legend-bar" role="img"></span>
                <span class="wmv-legend-max"></span>
            </span>
        </div>

        <div class="wmv-zoombar wmv-fades" role="group" aria-label="<?= e__('Zoom') ?>">
            <button type="button" class="wmv-icon-btn" data-act="in" aria-keyshortcuts="+" data-keys="viewer.zoom-in" title="<?= e__('Zoom in') ?>" aria-label="<?= e__('Zoom in') ?>"><?= icon('plus') ?></button>
            <button type="button" class="wmv-zoom" data-act="actual" aria-keyshortcuts="1" data-keys="viewer.actual-size" title="<?= e__('Actual size') ?>" aria-live="polite">100 %</button>
            <button type="button" class="wmv-icon-btn" data-act="out" aria-keyshortcuts="-" data-keys="viewer.zoom-out" title="<?= e__('Zoom out') ?>" aria-label="<?= e__('Zoom out') ?>"><?= icon('minus') ?></button>
            <button type="button" class="wmv-icon-btn" data-act="fit" aria-keyshortcuts="0" data-keys="viewer.fit" title="<?= e__('Fit the map to the window') ?>" aria-label="<?= e__('Fit') ?>"><?= icon('scan') ?></button>
        </div>

        <?php if (!$isKiosk): ?>
        <aside class="wmv-panel wmv-fades" id="wmv-panel" hidden aria-label="<?= e__('Side panel') ?>">
            <section class="wmv-panel-sec wmv-sites" aria-labelledby="wmv-sites-title">
                <header class="wmv-panel-head">
                    <h2 id="wmv-sites-title" data-sites="<?= e__('Sites') ?>" data-nodes="<?= e__('Nodes') ?>"><?= e__('Nodes') ?></h2>
                    <span class="wmv-panel-count"></span>
                    <button type="button" class="wmv-icon-btn wmv-sites-all" data-act="sites-all" aria-pressed="false" hidden
                            data-collapse="<?= e__('Collapse every site') ?>" data-expand="<?= e__('Expand every site') ?>"
                            title="<?= e__('Collapse every site') ?>" aria-label="<?= e__('Collapse every site') ?>"><?= icon('chevrons-down-up') ?></button>
                    <button type="button" class="wmv-icon-btn" data-act="panel-close" data-keys="viewer.panel" title="<?= e__('Close the side panel') ?>" aria-label="<?= e__('Close the side panel') ?>"><?= icon('x') ?></button>
                </header>
                <ul class="wmv-site-list"></ul>
            </section>
            <section class="wmv-panel-sec wmv-total" aria-labelledby="wmv-total-title" hidden>
                <header class="wmv-panel-head">
                    <h2 id="wmv-total-title"><?= e__('Total traffic') ?></h2>
                    <span class="wmv-panel-count wmv-total-links"></span>
                </header>
                <dl class="wmv-total-now">
                    <div class="wmv-total-in"><dt><?= e__('IN') ?></dt><dd></dd></div>
                    <div class="wmv-total-out"><dt><?= e__('OUT') ?></dt><dd></dd></div>
                </dl>
                <svg class="wmv-total-graph" viewBox="0 0 260 64" preserveAspectRatio="none" role="img" hidden></svg>
                <p class="wmv-total-stats" hidden></p>
            </section>
            <section class="wmv-panel-sec wmv-panel-legend" aria-labelledby="wmv-legend-title" hidden>
                <header class="wmv-panel-head"><h2 id="wmv-legend-title"><?= e__('Link load') ?></h2></header>
                <div class="wmv-panel-scale"></div>
            </section>
        </aside>
        <?php endif; ?>

        <aside class="wmv-card wmv-fades" hidden aria-label="<?= e__('Selected node') ?>">
            <header class="wmv-card-head">
                <div class="wmv-card-names">
                    <h2 class="wmv-card-title"></h2>
                    <p class="wmv-card-sub" hidden></p>
                </div>
                <button type="button" class="wmv-icon-btn" data-act="zoom-focus" title="<?= e__('Zoom onto this node') ?>" aria-label="<?= e__('Zoom onto this node') ?>"><?= icon('locate') ?></button>
                <button type="button" class="wmv-icon-btn" data-act="unfocus" aria-keyshortcuts="Escape" title="<?= e__('Close (Esc)') ?>" aria-label="<?= e__('Close') ?>"><?= icon('x') ?></button>
            </header>
            <p class="wmv-card-count"></p>
            <ul class="wmv-card-links"></ul>
            <p class="wmv-card-none" hidden><?= e__('No links on this node.') ?></p>
            <!-- the traffic of one of its links, drawn by WM.trafficPanel (weathermap.js) -->
            <section class="wmv-card-traffic" hidden>
                <label class="wmv-card-tpick"><span><?= e__('Traffic graph') ?></span><select></select></label>
                <div class="wmv-card-plot"></div>
            </section>
            <a class="wm-btn wmv-card-open" target="_blank" rel="noopener" hidden><?= icon('external-link') ?><span><?= e__('Open its page') ?></span></a>
        </aside>

        <!-- the city of the hovered site, on a map with a basemap (viewer.js) -->
        <aside class="wmv-inset wmv-fades" hidden aria-hidden="true">
            <header class="wmv-inset-head"><span class="wmv-inset-title"></span><span class="wmv-inset-count"></span></header>
            <div class="wmv-inset-map"></div>
        </aside>

        <div class="wmv-minimap wmv-fades" hidden aria-hidden="true">
            <img alt="">
            <div class="wmv-minimap-view"></div>
            <div class="wmv-minimap-mark" hidden></div>
        </div>
    </div>

    <div class="map-meta">
        <?php if ($lastRun !== null): ?>
        <span class="last-updated" title="<?= htmlspecialchars($timezone) ?>"><?= e__('Last updated:') ?> <?= htmlspecialchars($lastRun) ?></span>
        <?php endif; ?>
    </div>
</div>

<script>
window.WM_T = Object.assign(window.WM_T || {}, <?= json_encode(\App\Core\I18n::forScript(['API request failed', 'Invalid data source', 'Failed to load', 'Loading…', 'IN', 'OUT']) + \App\Core\I18n::section('viewer-js')) ?>);
</script>

<script>window.WM_MAPLIBRE = <?= json_encode(['js' => asset('/assets/vendor/maplibre-gl/maplibre-gl-csp.js'), 'worker' => asset('/assets/vendor/maplibre-gl/maplibre-gl-csp-worker.js'), 'css' => asset('/assets/vendor/maplibre-gl/maplibre-gl.css')], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= asset('/assets/js/basemap.js') ?>" defer></script>
<script src="<?= asset('/assets/js/geo-view.js') ?>" defer></script>
<script src="<?= asset('/assets/js/viewer.js') ?>" defer></script>
<?php if ($isFullscreen): ?>
</body>
</html>
<?php endif; ?>
