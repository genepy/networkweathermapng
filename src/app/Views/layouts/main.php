<?php
/**
 * Application shell: a thin top bar (sections and the user menu), toasts
 * for the flash messages, and a bottom navigation bar on phones.
 * $auth may be null on an error page shown before the application started.
 */
use App\Core\I18n;

$theme = in_array($_COOKIE['theme'] ?? '', ['light', 'dark'], true) ? $_COOKIE['theme'] : '';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$auth = $auth ?? null;
$user = $user ?? null;
$isAdmin = $auth !== null && $auth->isAdmin();
// with AUTH_ENABLED=false everybody is in, without a user name
$showNav = $auth !== null && $auth->check();
$signedIn = $user !== null && (string) ($user['username'] ?? '') !== '';

// Sections of the application: [key, label, address, icon, active, shown].
// "phone" sections are only in the bottom navigation. The map list and the
// map management are one page (Maps); /editor redirects there.
$onMaps = $uri === '/' || $uri === '/maps' || str_starts_with($uri, '/map/') || str_starts_with($uri, '/editor');
$favFilter = ($uri === '/' || $uri === '/maps') && ($_GET['filter'] ?? '') === 'favourites';
$sections = [
    ['maps', __('Maps'), '/', 'map', $onMaps && !$favFilter, true],
    ['favourites', __('Favourites'), '/?filter=favourites', 'star', $favFilter, 'phone'],
    ['kiosk', __('Kiosk'), '/cycle', 'tv', str_starts_with($uri, '/cycle'), 'phone'],
    ['settings', __('Settings'), '/admin', 'settings', str_starts_with($uri, '/admin'), $isAdmin],
];
$roles = ['admin' => __('Administrator'), 'editor' => __('Editor'), 'viewer' => __('Viewer')];
$themes = ['' => [__('Auto'), 'sun-moon'], 'light' => [__('Light'), 'sun'], 'dark' => [__('Dark'), 'moon']];
$initial = $signedIn ? mb_strtoupper(mb_substr((string) $user['username'], 0, 1)) : '';
?>
<!DOCTYPE html>
<html lang="<?= I18n::locale() ?>"<?= $theme ? ' data-theme="' . $theme . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <?= \App\Core\Csrf::metaTag() ?>
    <script>window.WM_T = Object.assign(window.WM_T || {}, <?= json_encode((object) I18n::section('ui-js'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>);</script>
    <script src="<?= asset('/assets/js/csrf.js') ?>"></script>
    <script src="<?= asset('/assets/js/preferences.js') ?>"></script>
    <title><?= htmlspecialchars($title ?? 'NetworkWeathermapNG') ?></title>
    <link rel="icon" href="<?= asset('/assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= asset('/assets/css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= asset('/assets/css/weathermap.css') ?>">
    <link rel="stylesheet" href="<?= asset('/assets/css/ui.css') ?>">
    <script src="<?= asset('/assets/js/ui.js') ?>" defer></script>
    <script src="<?= asset('/assets/js/map-settings.js') ?>" defer></script>
    <script src="<?= asset('/assets/js/weathermap.js') ?>" defer></script>
    <?php if ($showNav): ?>
    <script src="<?= asset('/assets/js/palette.js') ?>" defer></script>
    <?php endif; ?>
</head>
<body class="app-shell">
    <a class="skip-link" href="#main"><?= e__('Skip to content') ?></a>
    <header class="app-bar">
        <a class="app-logo" href="/" aria-label="NetworkWeathermapNG">
            <span class="app-logo-mark"><?= icon('map') ?></span>
            <span class="app-logo-text">NetworkWeathermapNG</span>
        </a>
        <?php if ($showNav): ?>
        <nav class="app-nav" aria-label="<?= e__('Sections') ?>">
            <?php foreach ($sections as [$key, $label, $href, $ic, $active, $shown]): ?>
            <?php if ($shown === true): ?>
            <a href="<?= $href ?>"<?= $active ? ' class="active" aria-current="page"' : '' ?>><?= htmlspecialchars($label) ?></a>
            <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        <div class="app-bar-end">
            <?php if ($showNav): ?>
            <button type="button" class="app-search" data-palette-open aria-keyshortcuts="Control+K Meta+K"
                    aria-label="<?= e__('Search maps, nodes, commands') ?>">
                <?= icon('search') ?>
                <span class="app-search-text"><?= e__('Search maps, nodes, commands') ?></span>
                <kbd class="app-search-key">Ctrl K</kbd>
            </button>
            <?php endif; ?>
            <?php if (!$showNav): ?>
            <a href="/login" class="wm-btn wm-btn-primary"><?= e__('Login') ?></a>
            <?php endif; ?>
            <button type="button" class="user-button" popovertarget="user-menu"
                    aria-label="<?= $signedIn ? e__('User menu for :name', ['name' => $user['username']]) : e__('Preferences') ?>">
                <?php if ($signedIn): ?>
                <span class="avatar" aria-hidden="true"><?= htmlspecialchars($initial) ?></span>
                <?php else: ?>
                <?= icon('sliders-horizontal') ?>
                <?php endif; ?>
            </button>
        </div>
        <div id="user-menu" class="user-menu-panel" popover>
            <?php if ($signedIn): ?>
            <div class="um-who">
                <span class="avatar" aria-hidden="true"><?= htmlspecialchars($initial) ?></span>
                <span>
                    <strong><?= htmlspecialchars((string) $user['username']) ?></strong>
                    <span class="um-role"><?= htmlspecialchars($roles[$user['role'] ?? ''] ?? (string) ($user['role'] ?? '')) ?></span>
                </span>
            </div>
            <?php endif; ?>
            <label class="um-row">
                <span><?= e__('Language') ?></span>
                <select data-pref="lang">
                    <?php foreach (I18n::locales() as $code => $label): ?>
                    <option value="<?= $code ?>" <?= I18n::locale() === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="um-row">
                <span id="um-theme"><?= e__('Theme') ?></span>
                <div class="segmented" role="group" aria-labelledby="um-theme">
                    <?php foreach ($themes as $value => [$label, $ic]): ?>
                    <button type="button" data-pref="theme" data-theme-value="<?= $value ?>" aria-pressed="<?= $theme === $value ? 'true' : 'false' ?>"><?= icon($ic) ?><span><?= htmlspecialchars($label) ?></span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($signedIn): ?>
            <div class="um-sep" role="separator"></div>
            <a class="um-item" href="/account/password"><?= icon('lock') ?><?= e__('Change password') ?></a>
            <form method="post" action="/logout" class="um-logout"><?= \App\Core\Csrf::field() ?>
                <button type="submit" class="um-item"><?= icon('arrow-left') ?><?= e__('Log out') ?></button>
            </form>
            <?php endif; ?>
        </div>
    </header>

    <main class="main-content" id="main">
        <?= $content ?>
    </main>

    <?php
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    ?>
    <div id="wm-toasts" class="wm-toasts">
        <?php foreach (['success', 'error', 'info'] as $type): ?>
        <?php if (isset($flash[$type])): ?>
        <div class="wm-toast wm-toast-<?= $type ?>" data-type="<?= $type ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
            <span class="wm-toast-text"><?= htmlspecialchars((string) $flash[$type]) ?></span>
            <button type="button" class="wm-toast-close" aria-label="<?= e__('Dismiss') ?>">&times;</button>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php if ($showNav): ?>
    <nav class="bottom-nav" aria-label="<?= e__('Sections') ?>">
        <?php foreach ($sections as [$key, $label, $href, $ic, $active, $shown]): ?>
        <?php if ($shown): ?>
        <a data-section="<?= $key ?>" href="<?= $href ?>"<?= $active ? ' class="active" aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= htmlspecialchars($label) ?></span></a>
        <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
</body>
</html>
