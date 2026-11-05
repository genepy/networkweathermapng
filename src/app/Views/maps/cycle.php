<?php
/**
 * Kiosk: the maps of a group one after the other, each for its own time
 * (Settings > Kiosk). The map fills the page; the controls float at the
 * bottom and fade out after a few seconds without movement. ?fullscreen=1
 * is the same page without the application shell, for a wall screen.
 */
$cycleUrl = '/cycle' . ($groupId ? '?group=' . (int) $groupId : '');
$fsUrl = '/cycle?fullscreen=1' . ($groupId ? '&group=' . (int) $groupId : '');
?>
<?php if ($fullscreen): ?>
<?php $theme = in_array($_COOKIE['theme'] ?? '', ['light', 'dark'], true) ? $_COOKIE['theme'] : ''; ?>
<!DOCTYPE html>
<html lang="<?= \App\Core\I18n::locale() ?>"<?= $theme ? ' data-theme="' . $theme . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title><?= e__('Kiosk') ?> - NetworkWeathermapNG</title>
    <link rel="icon" href="<?= asset('/assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= asset('/assets/css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= asset('/assets/css/weathermap.css') ?>">
    <link rel="stylesheet" href="<?= asset('/assets/css/ui.css') ?>">
</head>
<body class="wmv-noc">
<?php endif; ?>
<link rel="stylesheet" href="<?= asset('/assets/css/viewer.css') ?>">

<div class="kiosk<?= $fullscreen ? ' kiosk-standalone wmv-fullscreen' : '' ?>" id="kiosk"
     data-refresh="<?= max(30, (int) $refreshInterval) ?>"
     data-exit-url="<?= htmlspecialchars($cycleUrl) ?>"
     data-fullscreen-url="<?= htmlspecialchars($fsUrl) ?>"
     data-standalone="<?= $fullscreen ? '1' : '0' ?>">
    <?php if (!$maps): ?>
    <div class="empty-state">
        <p><?= e__('No maps available.') ?></p>
        <p><a href="/" class="wm-btn"><?= e__('Back to the maps') ?></a></p>
    </div>
    <?php else: ?>
    <div class="kiosk-progress" aria-hidden="true"><span></span></div>
    <div class="kiosk-stage">
        <?php foreach ($maps as $index => $map):
            $settings = $kiosk[(int) $map['id']] ?? ['zoom' => 0, 'duration' => 30];
            $name = (string) ($map['title_cache'] ?: $map['name']);
        ?>
        <div class="cycle-map <?= $settings['zoom'] ? 'zoom-fixed' : 'zoom-fit' ?><?= $index === 0 ? ' active' : '' ?>"
             data-map-id="<?= (int) $map['id'] ?>"
             data-name="<?= htmlspecialchars($name) ?>"
             data-zoom="<?= (int) $settings['zoom'] ?>"
             data-duration="<?= (int) $settings['duration'] ?>">
            <img src="/map/<?= (int) $map['id'] ?>/image?t=<?= time() ?>" alt="<?= htmlspecialchars($name) ?>">
        </div>
        <?php endforeach; ?>
    </div>

    <div class="kiosk-bar wmv-fades" role="toolbar" aria-label="<?= e__('Kiosk') ?>">
        <button type="button" class="wmv-icon-btn" data-kiosk="prev" aria-keyshortcuts="ArrowLeft" title="<?= e__('Previous map (←)') ?>" aria-label="<?= e__('Previous map') ?>"><?= icon('chevron-left') ?></button>
        <button type="button" class="wmv-icon-btn" data-kiosk="pause" aria-keyshortcuts="Space" aria-pressed="false"
                title="<?= e__('Pause (Space)') ?>" aria-label="<?= e__('Pause') ?>"
                data-pause="<?= e__('Pause (Space)') ?>" data-play="<?= e__('Play (Space)') ?>"><?= icon('pause') ?></button>
        <button type="button" class="wmv-icon-btn" data-kiosk="next" aria-keyshortcuts="ArrowRight" title="<?= e__('Next map (→)') ?>" aria-label="<?= e__('Next map') ?>"><?= icon('chevron-right') ?></button>
        <span class="kiosk-info" aria-live="polite">
            <strong class="kiosk-name"><?= htmlspecialchars((string) ($maps[0]['title_cache'] ?: $maps[0]['name'])) ?></strong>
            <span class="kiosk-count"><?= __('Map :current of :total', [
                'current' => '<span class="kiosk-current">1</span>',
                'total' => '<span class="kiosk-total">' . count($maps) . '</span>',
            ]) ?></span>
        </span>
        <?php if ($fullscreen): ?>
        <a class="wmv-icon-btn" href="<?= htmlspecialchars($cycleUrl) ?>" data-kiosk="exit" title="<?= e__('Leave fullscreen (Esc)') ?>" aria-label="<?= e__('Leave fullscreen') ?>"><?= icon('minimize') ?></a>
        <?php else: ?>
        <button type="button" class="wmv-icon-btn" data-kiosk="fullscreen" aria-keyshortcuts="F" aria-pressed="false"
                data-enter="<?= e__('Fullscreen (F)') ?>" data-leave="<?= e__('Leave fullscreen (F)') ?>"
                title="<?= e__('Fullscreen (F)') ?>" aria-label="<?= e__('Fullscreen') ?>"><?= icon('maximize') ?></button>
        <?php if ($auth->isAdmin()): ?>
        <a class="wmv-icon-btn" href="/admin/kiosk" title="<?= e__('Kiosk settings') ?>" aria-label="<?= e__('Kiosk settings') ?>"><?= icon('settings') ?></a>
        <?php endif; ?>
        <a class="wmv-icon-btn" href="/" title="<?= e__('Leave the kiosk') ?>" aria-label="<?= e__('Leave the kiosk') ?>"><?= icon('x') ?></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script src="<?= asset('/assets/js/kiosk.js') ?>" defer></script>
<?php if ($fullscreen): ?>
</body>
</html>
<?php endif; ?>
