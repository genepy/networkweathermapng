<?php
/**
 * The keyboard shortcuts of the signed-in user. The table of changeable
 * keys is drawn by assets/js/shortcuts-page.js from $page; the keys that
 * cannot be changed are listed under each area.
 *
 * @var array $page @var array $fixed @var array $areas @var bool $canSave
 */
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
?>
<div class="set-panel ks-page" id="ks-page">
    <header class="set-head">
        <h1><?= icon('keyboard') ?><?= e__('Keyboard shortcuts') ?></h1>
        <div class="set-head-actions">
            <label class="ks-filter">
                <?= icon('search') ?>
                <input type="search" id="ks-filter" placeholder="<?= e__('Find an action or a key') ?>" aria-label="<?= e__('Find an action or a key') ?>" autocomplete="off">
            </label>
        </div>
    </header>
    <div class="set-body">
        <p class="ks-intro"><?= e__('Change the keys of the map editor, the map viewer and the command palette. They are saved with your account and follow you on every computer.') ?></p>
        <?php if (!$canSave): ?>
        <p class="ks-notice"><?= e__('Sign in to keep your own keyboard shortcuts.') ?></p>
        <?php endif; ?>
        <?php foreach ($areas as $area => $label): ?>
        <section class="ks-area" data-area="<?= $h($area) ?>">
            <h2><?= $h($label) ?></h2>
            <?php if ($area === 'editor-drag'): ?>
            <p class="ks-help"><?= e__('Hold the key while you drag with the mouse. Space+drag and the middle button always move the view.') ?></p>
            <?php endif; ?>
            <table class="ks-table">
                <thead class="visually-hidden">
                    <tr><th scope="col"><?= e__('Action') ?></th><th scope="col"><?= e__('Keys') ?></th><th scope="col"><?= e__('Actions') ?></th></tr>
                </thead>
                <tbody class="ks-rows"></tbody>
                <?php if (!empty($fixed[$area])): ?>
                <tbody class="ks-fixed">
                    <?php foreach ($fixed[$area] as [$keys, $what]): ?>
                    <tr>
                        <th scope="row"><?= $h($what) ?></th>
                        <td><span class="ks-plain"><?= $h($keys) ?></span></td>
                        <td class="ks-fixed-note"><?= e__('Cannot be changed') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php endif; ?>
            </table>
        </section>
        <?php endforeach; ?>
    </div>
    <footer class="set-foot">
        <button type="button" class="wm-btn set-danger" id="ks-reset-all"<?= $canSave ? '' : ' disabled' ?>><?= e__('Back to the defaults') ?></button>
        <span class="ks-state" id="ks-state" role="status" aria-live="polite"></span>
        <button type="button" class="wm-btn" id="ks-cancel" disabled><?= e__('Cancel') ?></button>
        <button type="button" class="wm-btn wm-btn-primary" id="ks-save" disabled><?= e__('Save') ?></button>
    </footer>
</div>
<script>
window.WM_T = Object.assign(window.WM_T || {}, <?= json_encode((object) \App\Core\I18n::forScript([
    'Add a key', 'Press the keys…', 'Esc cancels', 'Remove :key', 'Back to the default keys',
    'No key', 'None', 'Same key as: :what', 'This key cannot be used', 'At most :n keys for one action',
    'Changes not saved', 'Keyboard shortcuts saved', 'Fix the keys marked in red first.',
    'Put every key back to its default?', 'Back to the defaults', 'Cancel', 'The keys could not be saved', 'Changed',
]), $flags) ?>);
window.WM_SHORTCUTS_PAGE = <?= json_encode($page, $flags) ?>;
</script>
<script src="<?= asset('/assets/js/shortcuts-page.js') ?>" defer></script>
