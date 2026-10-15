<?php
/** @var string $action create, edit or duplicate */
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$targets = ['create' => '/admin/maps/create', 'edit' => '/admin/maps/edit/', 'duplicate' => '/admin/maps/duplicate/'];
$url = $targets[$action] . ($action === 'create' ? '' : (int) $map['id']);
$name = match ($action) {
    'duplicate' => $map['name'] . ' ' . __('(copy)'),
    'edit' => $map['name'],
    default => '',
};
?>
<div class="admin-container map-form-page">
    <div class="page-header">
        <div class="breadcrumb">
            <a href="/admin"><?= e__('Admin') ?></a> &raquo; <a href="/admin/maps"><?= e__('Maps') ?></a> &raquo; <?= $h($title) ?>
        </div>
    </div>

    <form method="post" action="<?= $url ?>" class="form-card">
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
                <?php foreach ($maps as $m): ?>
                <option value="<?= (int) $m['id'] ?>"><?= e__('A copy of :name', ['name' => $m['name']]) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted"><?= e__('To bring maps from another Weathermap, use') ?> <a href="/admin/migration"><?= e__('Migration') ?></a>.</small>
        </div>
        <div class="form-group form-pair" data-empty-only>
            <label><?= e__('Width') ?> <input type="number" name="width" class="form-control" value="800" min="100" max="10000"></label>
            <label><?= e__('Height') ?> <input type="number" name="height" class="form-control" value="600" min="100" max="10000"></label>
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="active" value="1" <?= ($action === 'duplicate' ? 0 : ($map['active'] ?? 1)) ? 'checked' : '' ?>>
                <?= e__('Active (visible to users)') ?>
            </label>
        </div>

        <?php if ($action === 'edit'): ?>
        <div class="form-group">
            <label><?= e__('Configuration file') ?></label>
            <input type="text" class="form-control" value="<?= $h($map['config_file']) ?>" disabled>
            <label class="checkbox-label">
                <input type="checkbox" name="rename_file" value="1">
                <?= e__('Rename the file after the map name') ?>
            </label>
        </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <?= match ($action) { 'create' => e__('Create map'), 'duplicate' => e__('Duplicate'), default => e__('Save') } ?>
            </button>
            <a href="/admin/maps" class="btn btn-secondary"><?= e__('Cancel') ?></a>
            <?php if ($action === 'edit'): ?>
            <a href="/admin/maps/delete/<?= (int) $map['id'] ?>" data-method="post" class="btn btn-danger push-right"
               data-confirm="<?= e__('Delete this map and all its data (configuration, images, history, permissions)? This cannot be undone.') ?>"><?= e__('Delete') ?></a>
            <?php endif; ?>
        </div>
    </form>
</div>
<?php if ($action === 'create'): ?>
<script>
(function () {
    var t = document.getElementById('template');
    var size = document.querySelector('[data-empty-only]');
    t.addEventListener('change', function () { size.hidden = t.value !== '0'; });
})();
</script>
<?php endif; ?>
