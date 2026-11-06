<?php
    $isFullscreen = !empty($fullscreen);
    $viewKind = isset($viewKind) ? (string) $viewKind : 'png';
    $isSvg = $viewKind === 'svg';
    $mapId = (int) $map['id'];
    $svgFsUrl = '/map/' . $mapId . '?fullscreen=1';
    $pngUrl = '/map/' . $mapId . '?view=png';
    $dataRefresh = !empty($autoRefresh) ? (int) $autoRefresh : (int) $config->get('MAP_REFRESH_INTERVAL', 300);
    $isFit = !empty($fit);
    $mode = isset($mode) ? (string) $mode : '';
    $fsFitUrl = '/map/' . (int)$map['id'] . '?fullscreen=1&fit=1&autorefresh=120';
    $normalUrl = '/map/' . (int)$map['id'];
    $previewUrl = '/map/' . (int)$map['id'] . '?mode=preview&hours=4&fullscreen=1&fit=0';
    $rootClasses = 'map-view-page' . ($isFullscreen ? ' fullscreen' : '') . ($isFit ? ' fit' : '') . ($mode === 'preview' ? ' preview' : '') . ($isSvg ? ' svg-view' : '');
    $mapTitle = $map['title_cache'] ?: $map['name'];
    $canEdit = $auth->canEdit();
    $historyHours = isset($historyHours) ? (int) $historyHours : 24;
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

<div class="<?= $rootClasses ?>">
    <style>
        <?php if ($isFullscreen): ?>
        body {
            margin: 0;
        }
        <?php endif; ?>
        .map-view-page.fullscreen {
            padding: 0;
            margin: 0;
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            background: var(--map-bg, #111);
        }
        .map-view-page.fullscreen .map-container {
            width: 100vw;
            height: 100vh;
        }
        .map-view-page.fullscreen .map-image-wrapper {
            width: 100vw;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            margin: 0;
        }
        .map-view-page.fullscreen.fit .map-image,
        .map-view-page.fullscreen .map-image {
            width: 100%;
            height: 100%;
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
        }
        .map-view-page.fullscreen.preview {
            overflow: auto;
        }
        .map-view-page.fullscreen.preview .map-image-wrapper {
            display: block;
            width: auto;
            height: auto;
            overflow: auto;
        }
        .map-view-page.fullscreen.preview .map-image {
            width: auto;
            height: auto;
            max-width: none;
            max-height: none;
            object-fit: unset;
        }
        .map-view-page.fullscreen:not(.fit) {
            overflow: auto;
        }
        .map-view-page.fullscreen:not(.fit) .map-image-wrapper {
            display: block;
            width: auto;
            height: auto;
            overflow: auto;
            padding: 0;
            margin: 0;
        }
        .map-view-page.fullscreen:not(.fit) .map-image {
            display: block;
            width: auto;
            height: auto;
            max-width: none;
            max-height: none;
        }
        .map-view-page.fit .map-image {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
        }
    </style>
    <?php if (!$isFullscreen && !$isSvg): ?>
        <div class="page-header">
            <?= breadcrumb([[__('Maps'), '/'], [$mapTitle]]) ?>
            <div class="page-actions">
                <a href="<?= $normalUrl ?>" class="btn btn-secondary" title="<?= e__('Zoomable view of the map') ?>"><?= e__('Zoomable view') ?></a>
                <a href="<?= $fsFitUrl ?>&amp;view=png" class="btn btn-secondary"><?= e__('Fullscreen') ?></a>
                <a href="<?= $previewUrl ?>" class="btn btn-secondary"><?= e__('Preview') ?></a>
                <?php if ($canEdit): ?>
                <a href="/editor/edit/<?= $mapId ?>" class="btn btn-primary"><?= e__('Edit') ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if ($isSvg): ?>
    <div class="wmv<?= $isFullscreen ? ' wmv-fullscreen wmv-standalone' : '' ?>" id="wmv"
         data-map-id="<?= $mapId ?>"
         data-timezone="<?= htmlspecialchars($timezone) ?>"
         data-refresh="<?= max(30, $dataRefresh) ?>"
         data-fullscreen="<?= $isFullscreen ? '1' : '0' ?>"
         data-normal-url="<?= htmlspecialchars($normalUrl) ?>"
         data-kiosk-url="<?= htmlspecialchars($svgFsUrl) ?>"
         data-png-url="/map/<?= $mapId ?>/image"
         data-history="<?= !empty($history) ? '1' : '0' ?>">
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
                <input type="search" class="wmv-find-input" list="wmv-nodes" placeholder="<?= e__('Find a node') ?>" aria-label="<?= e__('Find a node') ?>" aria-keyshortcuts="/">
                <kbd aria-hidden="true">/</kbd>
                <datalist id="wmv-nodes"></datalist>
            </form>
            <?php if (!empty($history)): ?>
            <div class="segmented wmv-when" role="group" aria-label="<?= e__('Time shown') ?>">
                <button type="button" data-act="live" aria-pressed="true" aria-label="<?= e__('Live') ?>" title="<?= e__('The map with the latest data') ?>"><span class="wmv-live-dot" aria-hidden="true"></span><span><?= e__('Live') ?></span></button>
                <button type="button" data-act="history" aria-pressed="false" aria-keyshortcuts="H" aria-label="<?= e__('Last :hours h', ['hours' => $historyHours]) ?>" title="<?= e__('Play back the recorded hours (H)') ?>"><?= icon('history') ?><span><?= e__('Last :hours h', ['hours' => $historyHours]) ?></span></button>
            </div>
            <?php endif; ?>
            <button type="button" class="wmv-icon-btn" data-act="panel" aria-controls="wmv-panel" aria-pressed="false" aria-keyshortcuts="P"
                    title="<?= e__('Side panel: sites, total traffic, legend (P)') ?>" aria-label="<?= e__('Side panel') ?>"><?= icon('panel-left') ?></button>
            <?php if ($isFullscreen): ?>
            <a class="wmv-icon-btn" href="<?= htmlspecialchars($normalUrl) ?>" data-act="exit" aria-keyshortcuts="F" title="<?= e__('Leave fullscreen (Esc)') ?>" aria-label="<?= e__('Leave fullscreen') ?>"><?= icon('minimize') ?></a>
            <?php else: ?>
            <button type="button" class="wmv-icon-btn" data-act="fullscreen" aria-keyshortcuts="F" aria-pressed="false"
                    data-enter="<?= e__('Fullscreen (F)') ?>" data-leave="<?= e__('Leave fullscreen (F)') ?>"
                    title="<?= e__('Fullscreen (F)') ?>" aria-label="<?= e__('Fullscreen') ?>"><?= icon('maximize') ?></button>
            <?php endif; ?>
            <?php if ($canEdit && !$isFullscreen): ?>
            <a class="wmv-icon-btn" href="/editor/maps/edit/<?= $mapId ?>?return=<?= rawurlencode($normalUrl) ?>" data-map-settings title="<?= e__('Map settings') ?>" aria-label="<?= e__('Map settings') ?>"><?= icon('settings') ?></a>
            <?php endif; ?>
            <details class="row-menu wmv-more">
                <summary class="wmv-icon-btn" title="<?= e__('More actions') ?>" aria-label="<?= e__('More actions') ?>"><?= icon('ellipsis') ?></summary>
                <div class="row-menu-items">
                    <a href="<?= htmlspecialchars($pngUrl) ?>"><?= icon('image') ?><?= e__('Open as PNG') ?></a>
                    <a href="<?= htmlspecialchars($previewUrl) ?>"><?= icon('chart-line') ?><?= e__('Graphs on hover (PNG)') ?></a>
                    <button type="button" data-act="copy-kiosk"><?= icon('link') ?><?= e__('Copy kiosk link') ?></button>
                    <a href="/map/<?= $mapId ?>/image" download="<?= htmlspecialchars(preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $map['name']) ?: 'map') ?>.png"><?= icon('download') ?><?= e__('Download PNG') ?></a>
                </div>
            </details>
            <?php if ($canEdit): ?>
            <a class="wm-btn wm-btn-primary wmv-edit" href="/editor/edit/<?= $mapId ?>" aria-keyshortcuts="E"><?= icon('pencil') ?><span><?= e__('Edit') ?></span></a>
            <?php endif; ?>
        </div>

        <?php if (!empty($history)): ?>
        <div class="wmv-history" role="group" aria-label="<?= e__('Playback') ?>" hidden>
            <button type="button" class="wmv-btn" data-hist="play" title="<?= e__('Play or pause (Space)') ?>" aria-label="<?= e__('Play') ?>"><?= icon('play') ?></button>
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
            <button type="button" class="wmv-icon-btn" data-act="in" aria-keyshortcuts="+" title="<?= e__('Zoom in (+)') ?>" aria-label="<?= e__('Zoom in') ?>"><?= icon('plus') ?></button>
            <button type="button" class="wmv-zoom" data-act="actual" aria-keyshortcuts="1" title="<?= e__('Actual size (1)') ?>" aria-live="polite">100 %</button>
            <button type="button" class="wmv-icon-btn" data-act="out" aria-keyshortcuts="-" title="<?= e__('Zoom out (-)') ?>" aria-label="<?= e__('Zoom out') ?>"><?= icon('minus') ?></button>
            <button type="button" class="wmv-icon-btn" data-act="fit" aria-keyshortcuts="0" title="<?= e__('Fit the map to the window (0)') ?>" aria-label="<?= e__('Fit') ?>"><?= icon('scan') ?></button>
        </div>

        <aside class="wmv-panel wmv-fades" id="wmv-panel" hidden aria-label="<?= e__('Side panel') ?>">
            <section class="wmv-panel-sec wmv-sites" aria-labelledby="wmv-sites-title">
                <header class="wmv-panel-head">
                    <h2 id="wmv-sites-title" data-sites="<?= e__('Sites') ?>" data-nodes="<?= e__('Nodes') ?>"><?= e__('Nodes') ?></h2>
                    <span class="wmv-panel-count"></span>
                    <button type="button" class="wmv-icon-btn" data-act="panel-close" title="<?= e__('Close the side panel (P)') ?>" aria-label="<?= e__('Close the side panel') ?>"><?= icon('x') ?></button>
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
    <?php else: ?>
    <div class="map-container">
        <?php if ($mapHtml): ?>
            <?= $mapHtml ?>
        <?php else: ?>
            <div class="map-image-wrapper">
                <img src="/map/<?= $map['id'] ?>/image?t=<?= time() ?>" 
                     alt="<?= htmlspecialchars($map['name']) ?>"
                     class="map-image">
            </div>
        <?php endif; ?>
    </div>
    
    <?php endif; ?>

    <div class="map-meta">
        <?php if ($lastRun !== null): ?>
        <span class="last-updated" title="<?= htmlspecialchars($timezone) ?>"><?= e__('Last updated:') ?> <?= htmlspecialchars($lastRun) ?></span>
        <?php endif; ?>
    </div>
</div>

<script>
window.WM_T = Object.assign(window.WM_T || {}, <?= json_encode(\App\Core\I18n::forScript(['API request failed', 'Invalid data source', 'Failed to load', 'Loading…', 'IN', 'OUT']) + \App\Core\I18n::section('viewer-js')) ?>);
</script>

<?php if ($isSvg): ?>
<script>window.WM_MAPLIBRE = <?= json_encode(['js' => asset('/assets/vendor/maplibre-gl/maplibre-gl-csp.js'), 'worker' => asset('/assets/vendor/maplibre-gl/maplibre-gl-csp-worker.js'), 'css' => asset('/assets/vendor/maplibre-gl/maplibre-gl.css')], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= asset('/assets/js/basemap.js') ?>" defer></script>
<script src="<?= asset('/assets/js/viewer.js') ?>" defer></script>
<?php else: ?>

<?php if (empty($autoRefresh)): ?>
<script>
// Auto-refresh map image
(function() {
    const refreshInterval = <?= (int) $config->get('MAP_REFRESH_INTERVAL', 300) ?> * 1000;
    
    setInterval(function() {
        const images = document.querySelectorAll('.map-image');
        images.forEach(function(img) {
            const src = img.src.split('?')[0];
            img.src = src + '?t=' + Date.now();
        });
    }, refreshInterval);
})();
</script>
<?php endif; ?>

<?php if (!empty($autoRefresh)): ?>
<script>
(function() {
    const intervalMs = <?= (int) $autoRefresh ?> * 1000;
    const img = document.querySelector('.map-image');
    if (!img) return;
    const baseSrc = img.src.split('?')[0];

    function refreshImage() {
        const next = new Image();
        next.onload = function() {
            img.src = this.src;
        };
        next.src = baseSrc + '?t=' + Date.now();
    }

    setInterval(refreshImage, intervalMs);
    refreshImage();
})();
</script>
<?php endif; ?>

<script>
// Show edits right away: when the map's configuration changes, reload the
// image (or the whole page when it carries a clickable imagemap).
(function() {
    const versionUrl = '/map/<?= (int) $map['id'] ?>/version';
    const reloadPage = <?= $mapHtml ? 'true' : 'false' ?>;
    let current = null;

    function check() {
        if (document.hidden) return;
        fetch(versionUrl, {cache: 'no-store'})
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(data) {
                if (!data || !data.version) return;
                if (current !== null && data.version !== current) {
                    if (reloadPage) {
                        window.location.reload();
                        return;
                    }
                    document.querySelectorAll('.map-image').forEach(function(img) {
                        const next = new Image();
                        next.onload = function() { img.src = this.src; };
                        next.src = img.src.split('?')[0] + '?t=' + Date.now();
                    });
                }
                current = data.version;
            })
            .catch(function() {});
    }

    check();
    setInterval(check, 10000);
    document.addEventListener('visibilitychange', check);
})();
</script>

<?php if ($mode === 'preview'): ?>
<script>
(function() {
    function parseLinkIdFromAreaId(id) {
        // Examples:
        //  LINK:L12:0
        //  LINK:L12:2
        if (!id) return null;
        const m = String(id).match(/^LINK:(?:L)?(\d+)(?::|$)/);
        return m ? parseInt(m[1], 10) : null;
    }

    function parseNodeIdFromAreaId(id) {
        // Examples:
        //  NODE:N12:0
        //  NODE:N12:2
        if (!id) return null;
        const m = String(id).match(/^NODE:(?:N)?(\d+)(?::|$)/);
        return m ? parseInt(m[1], 10) : null;
    }

    function firstImageUrlFromOverlibArea(areaEl) {
        if (!areaEl) return null;
        // When HTMLSTYLE is 'overlib', WeatherMap may embed HTML in extra attributes,
        // most commonly as a data-hover="...<img src='...'>..." blob.
        const dataHover = areaEl.getAttribute('data-hover') || '';
        if (!dataHover) return null;

        const m = String(dataHover).match(/<img\s+[^>]*src=['\"]([^'\"]+)['\"][^>]*>/i);
        if (!m) return null;
        return m[1] || null;
    }

    const tooltip = document.createElement('div');
    tooltip.style.position = 'fixed';
    tooltip.style.zIndex = '9999';
    tooltip.style.display = 'none';
    tooltip.style.pointerEvents = 'none';
    tooltip.style.background = 'rgba(20,20,24,0.92)';
    tooltip.style.border = '1px solid rgba(255,255,255,0.15)';
    tooltip.style.borderRadius = '6px';
    tooltip.style.padding = '6px';
    tooltip.style.boxShadow = '0 8px 24px rgba(0,0,0,0.35)';
    tooltip.style.maxWidth = '540px';

    const img = document.createElement('img');
    img.style.display = 'block';
    img.style.width = '520px';
    img.style.height = '180px';
    img.alt = <?= json_encode(__('Traffic last :hours h', ['hours' => (int) $hours])) ?>;
    tooltip.appendChild(img);
    document.body.appendChild(tooltip);

    let lastLinkId = null;
    let pendingSrc = null;
    let hideTimer = null;

    function moveTooltip(e) {
        const offset = 14;
        const x = Math.min(window.innerWidth - 10, e.clientX + offset);
        const y = Math.min(window.innerHeight - 10, e.clientY + offset);
        tooltip.style.left = x + 'px';
        tooltip.style.top = y + 'px';
    }

    function showForLink(linkId, e) {
        if (!linkId) return;
        if (hideTimer) {
            clearTimeout(hideTimer);
            hideTimer = null;
        }
        moveTooltip(e);

        const areaEl = e && e.target && e.target.tagName === 'AREA' ? e.target : null;
        const overlibImg = firstImageUrlFromOverlibArea(areaEl);
        const nextSrc = (function() {
            if (overlibImg) {
                const sep = overlibImg.indexOf('?') === -1 ? '?' : '&';
                return overlibImg + sep + 't=' + Date.now();
            }
            return '/map/<?= (int) $map['id'] ?>/link/' + linkId + '/graph?minutes=<?= (int)($hours * 60) ?>&t=' + Date.now();
        })();

        // Load first; show tooltip only when the image loads successfully.
        // This prevents showing empty tooltips for links without a datasource.
        if (pendingSrc !== nextSrc) {
            pendingSrc = nextSrc;
            lastLinkId = linkId;
            tooltip.style.display = 'none';

            img.onload = function() {
                if (pendingSrc !== nextSrc) return;
                tooltip.style.display = 'block';
            };
            img.onerror = function() {
                if (pendingSrc !== nextSrc) return;
                tooltip.style.display = 'none';
                lastLinkId = null;
            };

            img.src = nextSrc;
        } else {
            // Same image - ensure it's visible.
            if (img.complete && img.naturalWidth > 0) {
                tooltip.style.display = 'block';
            }
        }
    }

    function scheduleHide() {
        if (hideTimer) clearTimeout(hideTimer);
        hideTimer = setTimeout(function() {
            tooltip.style.display = 'none';
            lastLinkId = null;
            pendingSrc = null;
        }, 100);
    }

    // <area> elements don't reliably emit mousemove in all browsers.
    // We still hook mouseover/mouseout to know when we're over a link.
    document.addEventListener('mouseover', function(e) {
        const t = e.target;
        if (!t || t.tagName !== 'AREA') return;
        const isLinkArea = t.classList.contains('link') || (typeof t.id === 'string' && t.id.indexOf('LINK:') === 0);
        if (!isLinkArea) return;
        const linkId = parseLinkIdFromAreaId(t.id);
        document.body.style.cursor = 'pointer';
        showForLink(linkId, e);
    });
    document.addEventListener('mousemove', function(e) {
        if (tooltip.style.display === 'block') {
            moveTooltip(e);
        }
    });
    document.addEventListener('mouseout', function(e) {
        const t = e.target;
        if (!t || t.tagName !== 'AREA') return;
        const isLinkArea = t.classList.contains('link') || (typeof t.id === 'string' && t.id.indexOf('LINK:') === 0);
        if (!isLinkArea) return;
        document.body.style.cursor = '';
        scheduleHide();
    });

    document.addEventListener('click', function(e) {
        const t = e.target;
        if (!t || t.tagName !== 'AREA') return;

        const isLinkArea = t.classList.contains('link') || (typeof t.id === 'string' && t.id.indexOf('LINK:') === 0);
        const isNodeArea = t.classList.contains('node') || (typeof t.id === 'string' && t.id.indexOf('NODE:') === 0);
        if (!isLinkArea && !isNodeArea) return;

        const href = t.getAttribute('href');
        // If INFOURL is configured, WeatherMap emits it as href in non-editor HTML.
        // In preview mode we open it in a new tab instead of navigating away.
        if (href && href !== '' && href !== '#') {
            e.preventDefault();
            window.open(href, '_blank', 'noopener');
            return;
        }

        // No INFOURL configured -> keep preview behavior (do not navigate).
        e.preventDefault();
    });
})();
</script>
<?php endif; ?>

<?php if ($isFullscreen): ?>
<script>
(function() {
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            window.location.href = <?= json_encode($normalUrl . '?view=png') ?>;
        }
    });
})();
</script>
<?php endif; ?>
<?php endif; /* PNG view */ ?>
<?php if ($isFullscreen): ?>
</body>
</html>
<?php endif; ?>
