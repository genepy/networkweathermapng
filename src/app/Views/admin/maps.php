<?php $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES); ?>
<div class="admin-maps-page">
    <div class="page-header">
        <div class="breadcrumb">
            <a href="/admin"><?= e__('Admin') ?></a> &raquo; <?= e__('Maps') ?>
        </div>
        <div class="page-actions">
            <a href="/admin/migration" class="btn btn-secondary"><?= e__('Import') ?></a>
            <?php if (!empty($maps)): ?>
            <a href="/admin/maps/export" class="btn btn-secondary"><?= e__('Export all') ?></a>
            <?php endif; ?>
            <a href="/admin/maps/create" class="btn btn-primary">+ <?= e__('New map') ?></a>
        </div>
    </div>

    <?php if (empty($maps)): ?>
    <div class="empty-state">
        <p><?= e__('No maps configured yet.') ?></p>
        <p>
            <a href="/admin/maps/create" class="btn btn-primary"><?= e__('Create your first map') ?></a>
            <a href="/admin/migration" class="btn btn-secondary"><?= e__('Import existing maps') ?></a>
        </p>
    </div>
    <?php else: ?>
    <form method="post" action="/admin/maps/bulk" id="bulk-form">
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
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" id="check-all" aria-label="<?= e__('Select all') ?>"></th>
                        <th><?= e__('Name') ?></th>
                        <th><?= e__('Config file') ?></th>
                        <th><?= e__('Group') ?></th>
                        <th><?= e__('Status') ?></th>
                        <th><?= e__('Last run') ?></th>
                        <th><?= e__('Actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($maps as $map): $id = (int) $map['id']; ?>
                    <tr>
                        <td class="col-check"><input type="checkbox" name="ids[]" value="<?= $id ?>" aria-label="<?= $h($map['name']) ?>"></td>
                        <td><strong><?= $h($map['name']) ?></strong></td>
                        <td><code><?= $h($map['config_file']) ?></code></td>
                        <td><?= $h($map['group_name'] ?? __('Default')) ?></td>
                        <td>
                            <span class="status-badge <?= $map['active'] ? 'status-active' : 'status-inactive' ?>">
                                <?= $map['active'] ? e__('Active') : e__('Inactive') ?>
                            </span>
                        </td>
                        <td><?= $map['last_run'] ? $h($map['last_run']) : e__('Never') ?></td>
                        <td class="actions">
                            <a href="/editor/edit/<?= $id ?>" class="btn btn-sm"><?= e__('Edit') ?></a>
                            <a href="/admin/maps/edit/<?= $id ?>" class="btn btn-sm"><?= e__('Properties') ?></a>
                            <a href="/map/<?= $id ?>" class="btn btn-sm"><?= e__('View') ?></a>
                            <a href="/admin/maps/duplicate/<?= $id ?>" class="btn btn-sm"><?= e__('Duplicate') ?></a>
                            <a href="/admin/maps/export/<?= $id ?>" class="btn btn-sm" title="<?= e__('Download the .conf file') ?>">.conf</a>
                            <a href="/admin/maps/delete/<?= $id ?>" class="btn btn-sm btn-danger" data-method="post"
                               data-confirm="<?= e__('Delete this map and all its data (configuration, images, history, permissions)? This cannot be undone.') ?>"><?= e__('Delete') ?></a>
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
    })();
    </script>
    <?php endif; ?>
</div>
