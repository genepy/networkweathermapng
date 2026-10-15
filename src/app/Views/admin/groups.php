<div class="admin-groups-page">
    <div class="page-header">
        <div class="breadcrumb">
            <a href="/admin"><?= e__('Admin') ?></a> &raquo; <?= e__('Groups') ?>
        </div>
        <div class="page-actions">
            <a href="/admin/groups/create" class="btn btn-primary">+ <?= e__('Add Group') ?></a>
        </div>
    </div>
    
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= e__('Name') ?></th>
                    <th><?= e__('Maps') ?></th>
                    <th><?= e__('Sort Order') ?></th>
                    <th><?= e__('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $group): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($group['name']) ?></strong></td>
                    <td><?= $group['map_count'] ?? 0 ?></td>
                    <td><?= $group['sort_order'] ?></td>
                    <td class="actions">
                        <a href="/admin/groups/edit/<?= $group['id'] ?>" class="btn btn-sm"><?= e__('Edit') ?></a>
                        <?php if ($group['id'] != 1): ?>
                        <a href="/admin/groups/delete/<?= $group['id'] ?>" class="btn btn-sm btn-danger"
                           data-method="post" data-confirm="<?= e__('Are you sure you want to delete this group? Maps will be moved to Default group.') ?>"><?= e__('Delete') ?></a>
                        <?php else: ?>
                        <span class="text-muted"><?= e__('Default') ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
