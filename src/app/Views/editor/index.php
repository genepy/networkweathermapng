<?php
/** @var array $maps @var array $groups @var bool $canImport */
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
// Small inline icons (no external font): the label beside them hides on narrow screens
$icon = static function (string $name): string {
    $paths = [
        'edit' => '<path d="M4 20h4l10.5-10.5-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
        'view' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'preview' => '<rect x="3" y="4" width="18" height="13" rx="1"/><path d="M8 21h8M12 17v4"/>',
        'more' => '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
        'properties' => '<path d="M4 6h16M4 12h16M4 18h10"/>',
        'duplicate' => '<rect x="8" y="8" width="12" height="12" rx="1"/><path d="M16 8V4H4v12h4"/>',
        'download' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
        'delete' => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">' . $paths[$name] . '</svg>';
};
?>
<div class="editor-page maps-admin-page">
    <div class="page-header">
        <?= breadcrumb([[__('Maps')]]) ?>
        <div class="page-actions">
            <?php if ($canImport): ?>
            <a href="/admin/migration" class="btn btn-secondary"><?= e__('Import') ?></a>
            <?php endif; ?>
            <?php if (!empty($maps)): ?>
            <a href="/editor/maps/export" class="btn btn-secondary"><?= e__('Export all') ?></a>
            <?php endif; ?>
            <a href="/editor/maps/create" class="btn btn-primary">+ <?= e__('New map') ?></a>
        </div>
    </div>

    <?php if (empty($maps)): ?>
    <div class="empty-state">
        <p><?= e__('No maps configured yet.') ?></p>
        <p>
            <a href="/editor/maps/create" class="btn btn-primary"><?= e__('Create your first map') ?></a>
            <?php if ($canImport): ?>
            <a href="/admin/migration" class="btn btn-secondary"><?= e__('Import existing maps') ?></a>
            <?php endif; ?>
        </p>
    </div>
    <?php else: ?>
    <form method="post" action="/editor/maps/bulk" id="bulk-form">
        <div class="bulk-bar" hidden>
            <span class="bulk-count"></span>
            <button type="submit" name="action" value="activate" class="btn btn-sm"><?= e__('Activate') ?></button>
            <button type="submit" name="action" value="deactivate" class="btn btn-sm"><?= e__('Deactivate') ?></button>
            <select name="group_id" aria-label="<?= e__('Group') ?>">
                <?php foreach ($groups as $g): ?>
                <option value="<?= (int) $g['id'] ?>"><?= $h($g['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="action" value="move" class="btn btn-sm"><?= e__('Move to group') ?></button>
            <button type="submit" name="action" value="export" class="btn btn-sm"><?= e__('Export') ?></button>
            <button type="submit" name="action" value="delete" class="btn btn-sm btn-danger"
                    data-confirm-bulk="<?= e__('Delete the selected maps and all their data? This cannot be undone.') ?>"><?= e__('Delete') ?></button>
        </div>
        <div class="data-table-container">
            <table class="data-table maps-table">
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" id="check-all" aria-label="<?= e__('Select all') ?>"></th>
                        <th><?= e__('Name') ?></th>
                        <th class="col-group"><?= e__('Group') ?></th>
                        <th><?= e__('Status') ?></th>
                        <th class="col-last-run"><?= e__('Last run') ?></th>
                        <th class="col-actions"><?= e__('Actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($maps as $map): $id = (int) $map['id']; $active = (bool) $map['active']; ?>
                    <tr>
                        <td class="col-check"><input type="checkbox" name="ids[]" value="<?= $id ?>" aria-label="<?= $h($map['name']) ?>"></td>
                        <td>
                            <strong><?= $h($map['name']) ?></strong>
                            <code class="map-file"><?= $h($map['config_file']) ?></code>
                        </td>
                        <td class="col-group"><?= $h($map['group_name'] ?? __('Default')) ?></td>
                        <td>
                            <a href="/editor/maps/toggle/<?= $id ?>" data-method="post" role="switch" aria-checked="<?= $active ? 'true' : 'false' ?>"
                               class="status-toggle <?= $active ? 'is-on' : '' ?>"
                               title="<?= $active ? e__('Active: click to deactivate (hidden from users)') : e__('Inactive: click to activate') ?>">
                                <span class="status-toggle-track" aria-hidden="true"></span>
                                <span class="status-toggle-label"><?= $active ? e__('Active') : e__('Inactive') ?></span>
                            </a>
                        </td>
                        <td class="col-last-run"><?= $map['last_run'] ? $h($map['last_run']) : e__('Never') ?></td>
                        <td class="col-actions">
                            <div class="row-actions">
                                <a href="/editor/edit/<?= $id ?>" class="btn btn-sm btn-primary" title="<?= e__('Edit') ?>"><?= $icon('edit') ?><span class="btn-label"><?= e__('Edit') ?></span></a>
                                <a href="/map/<?= $id ?>" class="btn btn-sm" title="<?= e__('View') ?>"><?= $icon('view') ?><span class="btn-label"><?= e__('View') ?></span></a>
                                <a href="/map/<?= $id ?>?mode=preview&amp;hours=4&amp;fullscreen=1&amp;fit=0" class="btn btn-sm" title="<?= e__('Preview') ?>"><?= $icon('preview') ?><span class="btn-label"><?= e__('Preview') ?></span></a>
                                <details class="row-menu">
                                    <summary class="btn btn-sm" title="<?= e__('More actions') ?>" aria-label="<?= e__('More actions') ?>"><?= $icon('more') ?></summary>
                                    <div class="row-menu-items">
                                        <a href="/editor/maps/edit/<?= $id ?>"><?= $icon('properties') ?><?= e__('Properties') ?></a>
                                        <a href="/editor/maps/duplicate/<?= $id ?>"><?= $icon('duplicate') ?><?= e__('Duplicate') ?></a>
                                        <a href="/editor/maps/export/<?= $id ?>" title="<?= e__('Download the .conf file') ?>"><?= $icon('download') ?>.conf</a>
                                        <a href="/editor/maps/delete/<?= $id ?>" class="danger" data-method="post"
                                           data-confirm="<?= e__('Delete this map and all its data (configuration, images, history, permissions)? This cannot be undone.') ?>"><?= $icon('delete') ?><?= e__('Delete') ?></a>
                                    </div>
                                </details>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
    <script>
    (function () {
        var form = document.getElementById('bulk-form');
        var boxes = form.querySelectorAll('input[name="ids[]"]');
        var all = document.getElementById('check-all');
        var bar = form.querySelector('.bulk-bar');
        var count = form.querySelector('.bulk-count');
        var label = <?= json_encode(__(':n selected')) ?>;
        function refresh() {
            var n = form.querySelectorAll('input[name="ids[]"]:checked').length;
            bar.hidden = n === 0;
            count.textContent = label.replace(':n', n);
            all.checked = n === boxes.length;
            all.indeterminate = n > 0 && n < boxes.length;
        }
        all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); refresh(); });
        boxes.forEach(function (b) { b.addEventListener('change', refresh); });
        form.addEventListener('submit', function (e) {
            var btn = e.submitter;
            if (btn && btn.dataset.confirmBulk && !confirm(btn.dataset.confirmBulk)) { e.preventDefault(); }
        });
        // One "more actions" menu open at a time; a click elsewhere or Escape closes it
        var menus = form.querySelectorAll('.row-menu');
        function closeMenus(except) { menus.forEach(function (m) { if (m !== except) { m.open = false; } }); }
        menus.forEach(function (m) { m.addEventListener('toggle', function () { if (m.open) { closeMenus(m); } }); });
        document.addEventListener('click', function (e) { if (!e.target.closest('.row-menu')) { closeMenus(null); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeMenus(null); } });
    })();
    </script>
    <?php endif; ?>
</div>
