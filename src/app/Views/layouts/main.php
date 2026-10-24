<?php
use App\Core\I18n;

$theme = in_array($_COOKIE['theme'] ?? '', ['light', 'dark'], true) ? $_COOKIE['theme'] : '';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
?>
<!DOCTYPE html>
<html lang="<?= I18n::locale() ?>"<?= $theme ? ' data-theme="' . $theme . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <?= \App\Core\Csrf::metaTag() ?>
    <script src="/assets/js/csrf.js"></script>
    <script src="/assets/js/preferences.js"></script>
    <title><?= htmlspecialchars($title ?? 'NetworkWeathermapNG') ?></title>
    <link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/weathermap.css">
    <script src="/assets/js/weathermap.js" defer></script>
</head>
<body>
    <header class="main-header">
        <div class="header-container">
            <div class="logo">
                <a href="/">NetworkWeathermapNG</a>
            </div>
            <nav class="main-nav">
                <ul>
                    <li><a href="/" class="<?= ($uri === '/' || str_starts_with($uri, '/maps')) ? 'active' : '' ?>"><?= e__('Maps') ?></a></li>
                    <?php if ($auth->canEdit()): ?>
                    <li><a href="/editor" class="<?= str_starts_with($uri, '/editor') ? 'active' : '' ?>"><?= e__('Editor') ?></a></li>
                    <?php endif; ?>
                    <?php if ($auth->isAdmin()): ?>
                    <li><a href="/admin" class="<?= str_starts_with($uri, '/admin') ? 'active' : '' ?>"><?= e__('Admin') ?></a></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <div class="user-menu">
                <select class="lang-select" aria-label="<?= e__('Language') ?>" data-pref="lang">
                    <?php foreach (I18n::locales() as $code => $label): ?>
                    <option value="<?= $code ?>" <?= I18n::locale() === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="theme-toggle" data-pref="theme"
                        data-labels="<?= htmlspecialchars(json_encode(['' => __('Theme: automatic'), 'light' => __('Theme: light'), 'dark' => __('Theme: dark')])) ?>"
                        aria-label="<?= e__('Theme') ?>"></button>
                <?php if ($user): ?>
                    <span class="username"><?= htmlspecialchars($user['username']) ?></span>
                    <a href="/account/password" class="login-btn"><?= e__('Change password') ?></a>
                    <form method="post" action="/logout" class="logout-form"><?= \App\Core\Csrf::field() ?><button type="submit" class="logout-btn"><?= e__('Logout') ?></button></form>
                <?php else: ?>
                    <a href="/login" class="login-btn"><?= e__('Login') ?></a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="main-content">
        <?php
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        if (!empty($flash)):
        ?>
        <div class="flash-messages">
            <?php foreach (['success', 'error', 'info'] as $type): ?>
            <?php if (isset($flash[$type])): ?>
            <div class="alert alert-<?= $type ?>"><?= htmlspecialchars($flash[$type]) ?></div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?= $content ?>
    </main>

    <footer class="main-footer">
        <div class="footer-container">
            <p>&copy; <?= date('Y') ?> NetworkWeathermapNG</p>
        </div>
    </footer>
</body>
</html>
