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
    $fsOrigUrl = '/map/' . (int)$map['id'] . '?fullscreen=1&fit=0&autorefresh=120';
    $normalUrl = '/map/' . (int)$map['id'];
    $previewUrl = '/map/' . (int)$map['id'] . '?mode=preview&hours=4&fullscreen=1&fit=0';
    $rootClasses = 'map-view-page' . ($isFullscreen ? ' fullscreen' : '') . ($isFit ? ' fit' : '') . ($mode === 'preview' ? ' preview' : '') . ($isSvg ? ' svg-view' : '');
    $mapTitle = $map['title_cache'] ?: $map['name'];
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
    <link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/weathermap.css">
    <link rel="stylesheet" href="/assets/css/viewer.css">
    <script src="/assets/js/weathermap.js" defer></script>
</head>
<body class="wmv-noc">
<?php else: ?>
<link rel="stylesheet" href="/assets/css/viewer.css">
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
    <?php if (!$isFullscreen): ?>
        <div class="page-header">
            <?= breadcrumb([[__('Maps'), '/'], [$map['title_cache'] ?: $map['name']]]) ?>
            <div class="page-actions">
                <?php if ($isSvg): ?>
                <a href="<?= $svgFsUrl ?>" class="btn btn-secondary" id="wmv-fullscreen-link"><?= e__('Fullscreen') ?></a>
                <a href="<?= $pngUrl ?>" class="btn btn-secondary" title="<?= e__('Show the map as a PNG picture') ?>"><?= e__('PNG') ?></a>
                <?php else: ?>
                <a href="<?= $normalUrl ?>" class="btn btn-secondary" title="<?= e__('Zoomable view of the map') ?>"><?= e__('Zoomable view') ?></a>
                <a href="<?= $fsFitUrl ?>&amp;view=png" class="btn btn-secondary"><?= e__('Fullscreen (Fit)') ?></a>
                <a href="<?= $fsOrigUrl ?>&amp;view=png" class="btn btn-secondary"><?= e__('Fullscreen (Original)') ?></a>
                <?php endif; ?>
                <a href="<?= $previewUrl ?>" class="btn btn-success"><?= e__('Preview') ?></a>
                <?php if ($auth->canEdit()): ?>
                <a href="/editor/edit/<?= (int) $map['id'] ?>" class="btn btn-primary"><?= e__('Edit Map') ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if ($isSvg): ?>
    <div class="wmv<?= $isFullscreen ? ' wmv-fullscreen' : '' ?>" id="wmv"
         data-map-id="<?= $mapId ?>"
         data-refresh="<?= max(30, $dataRefresh) ?>"
         data-fullscreen="<?= $isFullscreen ? '1' : '0' ?>"
         data-normal-url="<?= htmlspecialchars($normalUrl) ?>"
         data-png-url="/map/<?= $mapId ?>/image">
        <div class="wmv-viewport" tabindex="0" role="application" aria-label="<?= htmlspecialchars(__('Map :name, zoom with the wheel, drag to move', ['name' => $mapTitle])) ?>">
            <div class="wmv-stage"></div>
            <div class="wmv-message" role="status"><?= e__('Loading the map…') ?></div>
        </div>
        <div class="wmv-toolbar" role="toolbar" aria-label="<?= e__('Map view') ?>">
            <form class="wmv-find" role="search" autocomplete="off">
                <input type="search" class="wmv-find-input" list="wmv-nodes" placeholder="<?= e__('Find a node') ?>" aria-label="<?= e__('Find a node') ?>">
                <datalist id="wmv-nodes"></datalist>
            </form>
            <button type="button" class="wmv-btn" data-act="out" title="<?= e__('Zoom out (-)') ?>" aria-label="<?= e__('Zoom out') ?>">−</button>
            <span class="wmv-zoom" aria-live="polite">100 %</span>
            <button type="button" class="wmv-btn" data-act="in" title="<?= e__('Zoom in (+)') ?>" aria-label="<?= e__('Zoom in') ?>">+</button>
            <button type="button" class="wmv-btn wmv-btn-text" data-act="fit" title="<?= e__('Fit the map to the window (0)') ?>"><?= e__('Fit') ?></button>
            <button type="button" class="wmv-btn wmv-btn-text" data-act="actual" title="<?= e__('Actual size (1)') ?>">100 %</button>
            <?php if ($isFullscreen): ?>
            <a class="wmv-btn wmv-btn-text" href="<?= htmlspecialchars($normalUrl) ?>" data-act="exit" title="<?= e__('Leave fullscreen (Esc)') ?>"><?= e__('Exit') ?></a>
            <?php endif; ?>
            <span class="wmv-state" hidden><span class="wmv-dot"></span><span class="wmv-state-text"></span></span>
        </div>
        <div class="wmv-minimap" hidden aria-hidden="true">
            <img alt="">
            <div class="wmv-minimap-view"></div>
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
        <?php if ($map['last_run']): ?>
        <span class="last-updated"><?= e__('Last updated:') ?> <?= htmlspecialchars($map['last_run']) ?></span>
        <?php endif; ?>
    </div>
</div>

<script>
window.WM_T = Object.assign(window.WM_T || {}, <?= json_encode(\App\Core\I18n::forScript(['API request failed', 'Invalid data source', 'Failed to load', 'Loading…', 'Link bandwidth (24h)', 'IN', 'OUT']) + \App\Core\I18n::section('viewer-js')) ?>);
</script>

<?php if ($isSvg): ?>
<script src="/assets/js/viewer.js" defer></script>
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
