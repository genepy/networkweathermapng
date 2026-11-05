<?php
/**
 * The Settings space (UI redesign step 6): side navigation in four groups,
 * counters on the pages that list things, and the page itself as a panel.
 *
 * @var string $settingsBody the page @var ?string $settingsPage key of the page
 * @var array<string,int> $settingsCounts
 */
use App\Services\SettingsNav;
?>
<div class="settings-shell">
    <nav class="settings-nav" aria-label="<?= e__('Settings') ?>">
        <?php foreach (SettingsNav::groups() as [$groupLabel, $items]): ?>
        <div class="settings-nav-group">
            <h2 class="settings-nav-title"><?= htmlspecialchars($groupLabel) ?></h2>
            <ul>
                <?php foreach ($items as [$key, $label, $href, $ic]): ?>
                <li>
                    <a href="<?= $href ?>"<?= $key === $settingsPage ? ' class="active" aria-current="page"' : '' ?>>
                        <?= icon($ic) ?><span><?= htmlspecialchars($label) ?></span>
                        <?php if (isset($settingsCounts[$key])): ?>
                        <span class="settings-count"><?= (int) $settingsCounts[$key] ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </nav>
    <div class="settings-main">
        <?= $settingsBody ?>
    </div>
</div>
<script src="<?= asset('/assets/js/settings.js') ?>" defer></script>
