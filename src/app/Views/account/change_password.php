<div class="page-header">
    <?= breadcrumb([[$title ?? __('Change password')]]) ?>
</div>

<div class="form-card">
    <div>
        <form method="POST" action="/account/password">
            <div class="form-group">
                <label for="current_password"><?= e__('Current password') ?> *</label>
                <input type="password" id="current_password" name="current_password" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="new_password"><?= e__('New password') ?> *</label>
                <input type="password" id="new_password" name="new_password" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="confirm_password"><?= e__('Confirm new password') ?> *</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= e__('Change password') ?></button>
                <a href="/" class="btn btn-secondary"><?= e__('Cancel') ?></a>
            </div>
        </form>
    </div>
</div>

<style>
.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.form-control {
    width: 100%;
    max-width: 500px;
    padding: 0.5rem 0.75rem;
    font-size: 1rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background: var(--input-bg);
    color: var(--text-color);
}

.form-control:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 2px var(--accent-soft);
}

.form-actions {
    margin-top: 2rem;
    display: flex;
    gap: 1rem;
}
</style>
