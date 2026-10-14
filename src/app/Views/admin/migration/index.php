<div class="admin-container migration-page">
    <div class="page-header">
        <div class="breadcrumb">
            <a href="/admin"><?= e__('Admin') ?></a> &raquo; <?= e__('Migration') ?>
        </div>
    </div>

    <section class="panel">
        <h2><?= e__('Import maps') ?></h2>
        <p class="text-muted"><?= e__('Upload Weathermap .conf files, or a .zip with the .conf files and their icons and backgrounds. Nothing is created until you have reviewed each map.') ?></p>
        <form method="post" action="/admin/migration/upload" enctype="multipart/form-data" class="upload-form">
            <input type="file" name="files[]" multiple required accept=".conf,.cfg,.txt,.zip,.png,.jpg,.jpeg,.gif">
            <button type="submit" class="btn btn-primary"><?= e__('Import') ?></button>
        </form>
        <p class="text-muted small"><?= e__('Many maps at once:') ?> <code>php bin/migrate.php --source=1 --apply maps/*.conf</code></p>
    </section>

    <?php if ($migrations): ?>
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= e__('Map') ?></th>
                    <th><?= e__('File') ?></th>
                    <th><?= e__('Imported') ?></th>
                    <th><?= e__('Status') ?></th>
                    <th><?= e__('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($migrations as $m): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($m['name']) ?></strong></td>
                    <td><code><?= htmlspecialchars($m['file']) ?></code></td>
                    <td><?= htmlspecialchars($m['created']) ?></td>
                    <td>
                        <?php if ($m['mapId']): ?>
                            <span class="status-badge status-active"><?= e__('Created') ?></span>
                        <?php else: ?>
                            <span class="status-badge status-inactive"><?= e__('To review') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <a href="/admin/migration/<?= $m['id'] ?>" class="btn btn-sm"><?= e__('Review') ?></a>
                        <?php if ($m['mapId']): ?>
                        <a href="/editor/edit/<?= (int) $m['mapId'] ?>" class="btn btn-sm"><?= e__('Edit map') ?></a>
                        <?php endif; ?>
                        <a href="/admin/migration/<?= $m['id'] ?>/delete" data-method="post" class="btn btn-sm btn-danger"
                           data-confirm="<?= e__('Remove this migration? A map it created is kept.') ?>"><?= e__('Remove') ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><p><?= e__('No map imported yet.') ?></p></div>
    <?php endif; ?>
</div>
