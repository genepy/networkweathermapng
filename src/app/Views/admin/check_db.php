<div class="page-header">
    <?= breadcrumb([[__('Admin'), '/admin'], [__('Database Integrity Check')]]) ?>
</div>

<div class="form-card">
    <div>
        <?php if ($results['status'] === 'ok'): ?>
            <div class="alert alert-success">
                <strong>✅ <?= e__('Database integrity check passed.') ?></strong> <?= e__('All required tables and columns are present.') ?>
            </div>
        <?php else: ?>
            <div class="alert alert-danger">
                <strong>❌ <?= e__('Database integrity check failed.') ?></strong>
                <ul>
                    <?php foreach ($results['errors'] as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
                <div style="margin-top: 15px;">
                    <form method="POST" action="/admin/check-db">
                        <input type="hidden" name="fix" value="1">
                        <button type="submit" class="btn btn-primary"><?= e__('Attempt Auto-Fix') ?></button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <h3><?= e__('Table Status') ?></h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= e__('Table Name') ?></th>
                        <th><?= e__('Status') ?></th>
                        <th><?= e__('Issues') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results['tables'] as $table): ?>
                    <tr>
                        <td>
                            <code><?= htmlspecialchars($table['name']) ?></code>
                        </td>
                        <td>
                            <?php if ($table['exists']): ?>
                                <span class="badge badge-success"><?= e__('Exists') ?></span>
                            <?php else: ?>
                                <span class="badge badge-danger"><?= e__('Table missing') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($table['missing_columns'])): ?>
                                <span class="text-danger"><?= e__('Missing columns: :columns', ['columns' => implode(', ', $table['missing_columns'])]) ?></span>
                            <?php elseif ($table['exists']): ?>
                                <span class="text-muted"><?= e__('No issues') ?></span>
                            <?php else: ?>
                                <span class="text-danger"><?= e__('Table missing') ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.alert {
    padding: 15px;
    margin-bottom: 20px;
    border: 1px solid transparent;
    border-radius: 4px;
}
.alert-success {
    color: var(--success-text);
    background-color: var(--success-bg);
    border-color: var(--success-bg);
}
.alert-danger {
    color: var(--danger-text);
    background-color: var(--danger-bg);
    border-color: var(--danger-bg);
}
.badge {
    display: inline-block;
    padding: 0.25em 0.4em;
    font-size: 75%;
    font-weight: 700;
    line-height: 1;
    text-align: center;
    white-space: nowrap;
    vertical-align: baseline;
    border-radius: 0.25rem;
}
.badge-success {
    color: #fff;
    background-color: var(--success-color);
}
.badge-danger {
    color: #fff;
    background-color: var(--danger-color);
}
.table {
    width: 100%;
    margin-bottom: 1rem;
    color: var(--text-color);
    border-collapse: collapse;
}
.table th,
.table td {
    padding: 0.75rem;
    vertical-align: top;
    border-top: 1px solid var(--border-color);
    text-align: left;
}
.table thead th {
    vertical-align: bottom;
    border-bottom: 2px solid var(--border-color);
}
</style>
