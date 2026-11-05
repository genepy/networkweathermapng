<?php
/** Users (Settings, UI redesign step 6). Add and Edit open the form in a drawer. */
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$roles = ['admin' => __('Administrator'), 'editor' => __('Editor'), 'viewer' => __('Viewer')];
?>
<section class="set-panel">
    <header class="set-head">
        <h1><?= e__('Users') ?></h1>
        <div class="set-head-actions">
            <a class="wm-btn wm-btn-primary" href="/admin/users/create" data-drawer><?= icon('plus') ?><?= e__('Add user') ?></a>
        </div>
    </header>
    <div class="data-table-container">
        <table class="data-table set-table">
            <thead>
                <tr>
                    <th><?= e__('Username') ?></th>
                    <th class="set-col-wide"><?= e__('Email') ?></th>
                    <th><?= e__('Role') ?></th>
                    <th><?= e__('Status') ?></th>
                    <th class="set-col-wide"><?= e__('Created') ?></th>
                    <th class="set-col-actions"><span class="visually-hidden"><?= e__('Actions') ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $row): ?>
                <tr>
                    <td><a class="set-row-name" href="/admin/users/edit/<?= (int) $row['id'] ?>" data-drawer><?= $h($row['username']) ?></a></td>
                    <td class="set-col-wide"><?= $h($row['email'] ?? '—') ?></td>
                    <td><span class="role-badge role-<?= $h($row['role']) ?>"><?= $h($roles[$row['role']] ?? ucfirst((string) $row['role'])) ?></span></td>
                    <td>
                        <span class="status-badge <?= $row['active'] ? 'status-active' : 'status-inactive' ?>">
                            <?= $row['active'] ? e__('Active') : e__('Inactive') ?>
                        </span>
                    </td>
                    <td class="set-col-wide text-muted"><?= $h($row['created_at'] ?? '—') ?></td>
                    <td class="set-col-actions">
                        <a class="wm-btn" href="/admin/users/edit/<?= (int) $row['id'] ?>" data-drawer aria-label="<?= $h(__('Edit :name', ['name' => $row['username']])) ?>"><?= icon('pencil') ?><span class="set-btn-label"><?= e__('Edit') ?></span></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
