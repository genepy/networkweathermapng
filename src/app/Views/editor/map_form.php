<?php
/**
 * New map and Duplicate. The settings of an existing map are in the Map
 * settings panel (editor/map_settings.php).
 * @var string $action create or duplicate @var string $return the page to go back to
 * @var bool $webMap New map can start on a web map (Basemap::starterAllowed())
 */
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$targets = ['create' => '/editor/maps/create', 'duplicate' => '/editor/maps/duplicate/'];
$url = $targets[$action] . ($action === 'create' ? '' : (int) $map['id']);
$name = match ($action) {
    'duplicate' => $map['name'] . ' ' . __('(copy)'),
    default => '',
};
?>
<div class="admin-container map-form-page">
    <div class="page-header">
        <?= breadcrumb([[__('Maps'), '/'], [$title]]) ?>
    </div>

    <form method="post" action="<?= $url ?>" class="form-card">
        <input type="hidden" name="return" value="<?= $h($return) ?>">
        <div class="form-group">
            <label for="name"><?= e__('Name') ?> *</label>
            <input type="text" id="name" name="name" class="form-control" value="<?= $h($name) ?>" required maxlength="200" autofocus>
            <small class="text-muted"><?= e__('Also the title drawn on the map.') ?></small>
        </div>

        <div class="form-group">
            <label for="group_id"><?= e__('Group') ?></label>
            <select id="group_id" name="group_id" class="form-control">
                <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>" <?= (int) ($map['group_id'] ?? 1) === (int) $group['id'] ? 'selected' : '' ?>><?= $h($group['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($action === 'create'): ?>
        <div class="form-group">
            <label for="template"><?= e__('Start from') ?></label>
            <select id="template" name="template" class="form-control">
                <option value="0"><?= e__('An empty map') ?></option>
                <?php if (!empty($webMap)): ?>
                <option value="web"><?= e__('An empty map on a web map (OpenStreetMap)') ?></option>
                <?php endif; ?>
                <?php foreach ($maps as $m): ?>
                <option value="<?= (int) $m['id'] ?>"><?= e__('A copy of :name', ['name' => $m['name']]) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted" data-web-only hidden><?= e__('The editor opens on Map › Look › Basemap: pick the area and the zoom, or another web map. Nodes you give a location sit on their point.') ?></small>
            <?php if ($auth->isAdmin()): ?>
            <small class="text-muted"><?= e__('To bring maps from another Weathermap, use') ?> <a href="/admin/migration"><?= e__('Migration') ?></a>.</small>
            <?php endif; ?>
        </div>
        <div class="form-group form-pair" data-empty-only>
            <label><?= e__('Width') ?> <input type="number" name="width" class="form-control" value="800" min="100" max="10000"></label>
            <label><?= e__('Height') ?> <input type="number" name="height" class="form-control" value="600" min="100" max="10000"></label>
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="active" value="1" <?= ($action === 'duplicate' ? 0 : ($map['active'] ?? 1)) ? 'checked' : '' ?>>
                <?= e__('Visible to viewers') ?>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <?= $action === 'create' ? e__('Create map') : e__('Duplicate') ?>
            </button>
            <a href="<?= $h($return) ?>" class="btn btn-secondary"><?= e__('Cancel') ?></a>
        </div>
    </form>
</div>
<?php if ($action === 'create'): ?>
<script>
(function () {
    var t = document.getElementById('template');
    var size = document.querySelector('[data-empty-only]');
    var web = document.querySelector('[data-web-only]');
    t.addEventListener('change', function () {
        size.hidden = t.value !== '0' && t.value !== 'web';
        web.hidden = t.value !== 'web';
    });
})();
</script>
<?php endif; ?>
