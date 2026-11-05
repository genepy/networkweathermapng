<?php
/**
 * Add or edit a user (Settings, UI redesign step 6): a panel of the
 * Settings page, or ($panel) only the form, shown in a drawer.
 *
 * @var ?array $userData @var array $groups @var int[] $userGroups @var string $action create|edit @var bool $panel
 */
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$tag = $panel ? 'h2' : 'h1';
$id = (int) ($userData['id'] ?? 0);
$isMainAdmin = $action === 'edit' && ($userData['username'] ?? '') === 'admin';
$currentRole = $userData['role'] ?? 'viewer';
?>
<form method="post" action="<?= $action === 'create' ? '/admin/users/create' : '/admin/users/edit/' . $id ?>" class="set-panel set-form">
    <header class="set-head">
        <<?= $tag ?>><?= $action === 'create' ? e__('Add user') : e__('Edit user') ?></<?= $tag ?>>
        <?php if ($panel): ?>
        <button type="button" class="ms-close" data-drawer-close aria-label="<?= e__('Close') ?>" title="<?= e__('Close') ?>"><?= icon('x') ?></button>
        <?php endif; ?>
    </header>
    <div class="set-body">
        <?php if ($action === 'create'): ?>
        <div class="form-group">
            <label for="username"><?= e__('Username') ?> *</label>
            <input type="text" id="username" name="username" class="form-control" required autocomplete="off">
        </div>
        <?php else: ?>
        <div class="form-group">
            <label for="username"><?= e__('Username') ?></label>
            <input type="text" id="username" class="form-control" value="<?= $h($userData['username'] ?? '') ?>" disabled>
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label for="email"><?= e__('Email') ?></label>
            <input type="email" id="email" name="email" class="form-control" value="<?= $h($userData['email'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="role"><?= e__('Role') ?></label>
            <select id="role" name="role" class="form-control" <?= $isMainAdmin ? 'disabled' : '' ?>>
                <option value="viewer" <?= $currentRole === 'viewer' ? 'selected' : '' ?>><?= e__('Viewer') ?></option>
                <option value="editor" <?= $currentRole === 'editor' ? 'selected' : '' ?>><?= e__('Editor') ?></option>
                <option value="admin" <?= $currentRole === 'admin' ? 'selected' : '' ?>><?= e__('Administrator') ?></option>
            </select>
            <small class="text-muted"><?= e__('Viewer: sees the maps it is given. Editor: creates and edits every map, without access to users, data sources or settings. Administrator: everything.') ?></small>
            <?php if ($isMainAdmin): ?>
            <input type="hidden" name="role" value="admin">
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="ms-switch">
                <input type="checkbox" role="switch" name="active" value="1" <?= ($userData['active'] ?? 1) ? 'checked' : '' ?> <?= $isMainAdmin ? 'disabled' : '' ?>>
                <span><?= e__('Active') ?></span>
            </label>
            <?php if ($isMainAdmin): ?>
            <input type="hidden" name="active" value="1">
            <?php endif; ?>
        </div>

        <fieldset class="form-group set-checklist">
            <legend><?= e__('Group Access') ?></legend>
            <?php if (empty($groups)): ?>
            <p class="text-muted"><?= e__('No groups available.') ?></p>
            <?php else: ?>
            <div class="set-checklist-items">
                <?php foreach ($groups as $group): ?>
                <label class="checkbox-label">
                    <input type="checkbox" name="groups[]" value="<?= (int) $group['id'] ?>"
                        <?= in_array($group['id'], $userGroups ?? []) ? 'checked' : '' ?>>
                    <?= $h($group['name']) ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <small class="text-muted"><?= e__('Select map groups this user can access.') ?></small>
        </fieldset>

        <div class="form-group">
            <label for="password"><?= $action === 'create' ? e__('Password') . ' *' : e__('New Password') ?></label>
            <input type="password" id="password" name="password" class="form-control" autocomplete="new-password" <?= $action === 'create' ? 'required' : '' ?>>
        </div>

        <div class="form-group">
            <label for="confirm_password"><?= $action === 'create' ? e__('Confirm Password') . ' *' : e__('Confirm New Password') ?></label>
            <input type="password" id="confirm_password" name="confirm_password" class="form-control" autocomplete="new-password" <?= $action === 'create' ? 'required' : '' ?>>
        </div>
    </div>
    <footer class="set-foot">
        <?php if ($action === 'edit' && !$isMainAdmin): ?>
        <a href="/admin/users/delete/<?= $id ?>" class="wm-btn set-danger danger" data-method="post" data-confirm-label="<?= e__('Delete') ?>"
           data-confirm="<?= e__('Are you sure you want to delete this user?') ?>"><?= icon('trash-2') ?><span><?= e__('Delete…') ?></span></a>
        <?php endif; ?>
        <a href="/admin/users" class="wm-btn" data-drawer-close><?= e__('Cancel') ?></a>
        <button type="submit" class="wm-btn wm-btn-primary"><?= $action === 'create' ? e__('Add user') : e__('Save') ?></button>
    </footer>
</form>
