<?php
/**
 * Maps & rendering (Settings): the image format and the refresh interval of
 * the maps, stored in the app. The environment (.env) gives the defaults.
 *
 * @var array<string, array{value: string, default: string, stored: bool}> $settings
 */
use App\Services\RenderingSettings;

$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$format = $settings['MAP_OUTPUT_FORMAT'];
$refresh = $settings['MAP_REFRESH_INTERVAL'];
?>
<form method="post" action="/admin/settings" class="set-panel set-form admin-rendering-page">
    <header class="set-head">
        <h1><?= e__('Maps & rendering') ?></h1>
    </header>
    <div class="set-body">
        <div class="form-card">
            <div class="form-group">
                <label for="rendering-format"><?= e__('Image format') ?></label>
                <select id="rendering-format" name="format">
                    <?php foreach (RenderingSettings::FORMATS as $f): ?>
                    <option value="<?= $h($f) ?>" <?= $f === $format['value'] ? 'selected' : '' ?>><?= $h(strtoupper($f)) ?><?= $f === $format['default'] ? ' · ' . e__('default') : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <small><?= e__('Format of the map pictures (the SVG view is not affected). Default: :value, from :var.', ['value' => strtoupper($format['default']), 'var' => 'MAP_OUTPUT_FORMAT']) ?></small>
            </div>
            <div class="form-group">
                <label for="rendering-refresh"><?= e__('Refresh interval (seconds)') ?></label>
                <input type="number" id="rendering-refresh" name="refresh" required
                       value="<?= (int) $refresh['value'] ?>"
                       min="<?= RenderingSettings::MIN_REFRESH ?>" max="<?= RenderingSettings::MAX_REFRESH ?>">
                <small><?= e__('How often the maps fetch new data and the views follow it. Default: :value, from :var.', ['value' => __(':n seconds', ['n' => (int) $refresh['default']]), 'var' => 'MAP_REFRESH_INTERVAL']) ?></small>
            </div>
        </div>
        <p class="set-help"><?= e__('Saved in the application. The :file file or the Docker environment only gives the values of a new install, or of a setting left at its default.', ['file' => '.env']) ?></p>
        <p class="set-help"><?= e__('Kiosk, recording and time zone have pages of their own in this menu; the settings of one map are in its Map settings panel.') ?></p>
    </div>
    <footer class="set-foot">
        <button type="reset" class="wm-btn"><?= e__('Cancel') ?></button>
        <button type="submit" class="wm-btn wm-btn-primary"><?= e__('Save') ?></button>
    </footer>
</form>
