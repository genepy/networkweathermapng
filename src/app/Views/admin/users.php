<div class="admin-users-page">
    <div class="page-header">
        <div class="breadcrumb">
            <a href="/admin"><?= e__('Admin') ?></a> &raquo; <?= e__('Users') ?>
        </div>
        <div class="page-actions">
            <a class="btn btn-primary" href="/admin/users/create"><?= e__('Add User') ?></a>
        </div>
    </div>
    
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= e__('Username') ?></th>
                    <th><?= e__('Email') ?></th>
                    <th><?= e__('Role') ?></th>
                    <th><?= e__('Status') ?></th>
                    <th><?= e__('Created') ?></th>
                    <th><?= e__('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                    <td><?= htmlspecialchars($user['email'] ?? '-') ?></td>
                    <td>
                        <span class="role-badge role-<?= $user['role'] ?>">
                            <?= ($user['role'] === 'admin' ? e__('Administrator') : e__(ucfirst((string) $user['role']))) ?>
                        </span>
                    </td>
                    <td>
                        <span class="status-badge <?= $user['active'] ? 'status-active' : 'status-inactive' ?>">
                            <?= $user['active'] ? e__('Active') : e__('Inactive') ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($user['created_at'] ?? '-') ?></td>
                    <td class="actions">
                        <a class="btn btn-sm" href="/admin/users/edit/<?= $user['id'] ?>"><?= e__('Edit') ?></a>
                        <?php if ($user['username'] !== 'admin'): ?>
                        <a class="btn btn-sm btn-danger" href="/admin/users/delete/<?= $user['id'] ?>" data-method="post" data-confirm="<?= e__('Are you sure you want to delete this user?') ?>"><?= e__('Delete') ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

