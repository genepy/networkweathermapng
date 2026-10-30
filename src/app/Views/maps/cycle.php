<?php if ($fullscreen): ?>
<?php $theme = in_array($_COOKIE['theme'] ?? '', ['light', 'dark'], true) ? $_COOKIE['theme'] : ''; ?>
<!DOCTYPE html>
<html lang="<?= \App\Core\I18n::locale() ?>"<?= $theme ? ' data-theme="' . $theme . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title><?= e__('Map Cycle') ?> - NetworkWeathermapNG</title>
    <link rel="stylesheet" href="/assets/css/weathermap.css">
    <style>
        body { margin: 0; padding: 0; background: var(--bg-color); }
        .cycle-fullscreen { width: 100vw; height: 100vh; display: flex; flex-direction: column; }
        .cycle-controls { background: rgba(0,0,0,0.8); padding: 10px; color: #fff; text-align: center; }
        .cycle-map-container { flex: 1; height: auto; min-height: 0; overflow: hidden; }
    </style>
</head>
<body>
<?php endif; ?>

<div class="<?= $fullscreen ? 'cycle-fullscreen' : 'cycle-page' ?>">
    <div class="cycle-controls">
        <button id="cycle-prev" class="btn btn-sm">&laquo; <?= e__('Prev') ?></button>
        <button id="cycle-pause" class="btn btn-sm" data-play="<?= e__('Play') ?>" data-pause="<?= e__('Pause') ?>"><?= e__('Pause') ?></button>
        <button id="cycle-next" class="btn btn-sm"><?= e__('Next') ?> &raquo;</button>
        <span class="cycle-info">
            <?= __('Map :current of :total', [
                'current' => '<span id="current-map">1</span>',
                'total' => '<span id="total-maps">' . count($maps) . '</span>',
            ]) ?>
        </span>
        <?php if (!$fullscreen): ?>
        <a href="/cycle?fullscreen=1&group=<?= $groupId ?>" class="btn btn-sm"><?= e__('Fullscreen') ?></a>
        <?php if ($auth->isAdmin()): ?>
        <a href="/admin/kiosk" class="btn btn-sm"><?= e__('Kiosk settings') ?></a>
        <?php endif; ?>
        <a href="/" class="btn btn-sm"><?= e__('Exit') ?></a>
        <?php else: ?>
        <a href="/cycle?group=<?= $groupId ?>" class="btn btn-sm"><?= e__('Exit Fullscreen') ?></a>
        <?php endif; ?>
    </div>
    
    <div class="cycle-map-container">
        <?php foreach ($maps as $index => $map):
            $settings = $kiosk[(int) $map['id']] ?? ['zoom' => 0, 'duration' => 30];
        ?>
        <div class="cycle-map <?= $settings['zoom'] ? 'zoom-fixed' : 'zoom-fit' ?> <?= $index === 0 ? 'active' : '' ?>"
             data-map-id="<?= $map['id'] ?>"
             data-zoom="<?= (int) $settings['zoom'] ?>"
             data-duration="<?= (int) $settings['duration'] ?>">
            <img src="/map/<?= $map['id'] ?>/image?t=<?= time() ?>" 
                 alt="<?= htmlspecialchars($map['name']) ?>">
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
(function() {
    const maps = document.querySelectorAll('.cycle-map');
    const totalMaps = maps.length;
    const refreshInterval = <?= $refreshInterval ?> * 1000;
    
    // A fixed zoom is a percentage of the map's own size
    maps.forEach(function(mapDiv) {
        const zoom = parseInt(mapDiv.dataset.zoom, 10) || 0;
        const img = mapDiv.querySelector('img');
        if (!zoom || !img) return;
        function size() {
            if (img.naturalWidth) img.style.width = (img.naturalWidth * zoom / 100) + 'px';
        }
        img.addEventListener('load', size);
        if (img.complete) size();
    });
    
    let currentIndex = 0;
    let isPaused = false;
    let timer = null;
    
    function showMap(index) {
        maps.forEach((map, i) => {
            map.classList.toggle('active', i === index);
        });
        document.getElementById('current-map').textContent = index + 1;
    }
    
    function nextMap() {
        currentIndex = (currentIndex + 1) % totalMaps;
        showMap(currentIndex);
    }
    
    function prevMap() {
        currentIndex = (currentIndex - 1 + totalMaps) % totalMaps;
        showMap(currentIndex);
    }
    
    // Each map stays on screen for its own display time
    function startCycle() {
        stopCycle();
        const seconds = parseInt(maps[currentIndex].dataset.duration, 10) || 30;
        timer = setTimeout(function() {
            nextMap();
            startCycle();
        }, Math.max(5, seconds) * 1000);
    }
    
    function stopCycle() {
        if (timer) clearTimeout(timer);
        timer = null;
    }
    
    document.getElementById('cycle-prev').addEventListener('click', function() {
        prevMap();
        if (!isPaused) { stopCycle(); startCycle(); }
    });
    
    document.getElementById('cycle-next').addEventListener('click', function() {
        nextMap();
        if (!isPaused) { stopCycle(); startCycle(); }
    });
    
    document.getElementById('cycle-pause').addEventListener('click', function() {
        isPaused = !isPaused;
        this.textContent = isPaused ? this.dataset.play : this.dataset.pause;
        if (isPaused) {
            stopCycle();
        } else {
            startCycle();
        }
    });
    
    // Refresh images periodically
    setInterval(function() {
        maps.forEach(function(mapDiv) {
            const img = mapDiv.querySelector('img');
            if (img) {
                const src = img.src.split('?')[0];
                img.src = src + '?t=' + Date.now();
            }
        });
    }, refreshInterval);
    
    // Start cycling
    if (totalMaps > 1) {
        startCycle();
    }
})();
</script>

<?php if ($fullscreen): ?>
</body>
</html>
<?php endif; ?>
