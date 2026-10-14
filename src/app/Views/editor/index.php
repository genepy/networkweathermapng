<div class="editor-page">
    <div class="page-header">
        <h1><?= e__('Map Editor') ?></h1>
        <div class="page-actions">
            <a href="/admin/maps/create" class="btn btn-primary">+ <?= e__('Create New Map') ?></a>
        </div>
    </div>
    
    <?php if (empty($maps)): ?>
    <div class="empty-state">
        <p><?= e__('No maps available to edit.') ?></p>
        <p><a href="/admin/maps/create" class="btn btn-primary"><?= e__('Create your first map') ?></a></p>
    </div>
    <?php else: ?>
    <div class="maps-list">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= e__('Name') ?></th>
                    <th><?= e__('Config File') ?></th>
                    <th><?= e__('Status') ?></th>
                    <th><?= e__('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($maps as $map): ?>
                <tr>
                    <td><?= htmlspecialchars($map['name']) ?></td>
                    <td><code><?= htmlspecialchars($map['config_file']) ?></code></td>
                    <td>
                        <span class="status-badge <?= $map['active'] ? 'status-active' : 'status-inactive' ?>">
                            <?= $map['active'] ? e__('Active') : e__('Inactive') ?>
                        </span>
                    </td>
                    <td>
                        <a href="/editor/edit/<?= $map['id'] ?>" class="btn btn-sm btn-primary"><?= e__('Edit Config') ?></a>
                        <a href="/map/<?= $map['id'] ?>" class="btn btn-sm btn-secondary"><?= e__('View') ?></a>
                        <a href="/map/<?= $map['id'] ?>?mode=preview&amp;hours=4&amp;fullscreen=1&amp;fit=0" class="btn btn-sm btn-success"><?= e__('Preview') ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
