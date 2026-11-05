<?php
/**
 * Add or edit a map group (Settings, UI redesign step 6): a panel of the
 * Settings page, or ($panel) only the form, shown in a drawer.
 *
 * @var ?array $group @var array $users @var int[] $groupUsers @var string $action create|edit @var bool $panel
 */
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$tag = $panel ? 'h2' : 'h1';
$id = (int) ($group['id'] ?? 0);
?>
<form method="post" action="<?= $action === 'create' ? '/admin/groups/create' : '/admin/groups/edit/' . $id ?>" class="set-panel set-form">
    <header class="set-head">
        <<?= $tag ?>><?= $action === 'create' ? e__('Add group') : e__('Edit group') ?></<?= $tag ?>>
        <?php if ($panel): ?>
        <button type="button" class="ms-close" data-drawer-close aria-label="<?= e__('Close') ?>" title="<?= e__('Close') ?>"><?= icon('x') ?></button>
        <?php endif; ?>
    </header>
    <div class="set-body">
        <div class="form-group">
            <label for="name"><?= e__('Group Name') ?> *</label>
            <input type="text" id="name" name="name" class="form-control"
                   value="<?= $h($group['name'] ?? '') ?>"
                   placeholder="<?= e__('Enter group name') ?>" required>
        </div>

        <fieldset class="form-group set-checklist">
            <legend><?= e__('User Access') ?></legend>
            <?php if (empty($users)): ?>
            <p class="text-muted"><?= e__('No users available.') ?></p>
            <?php else: ?>
            <div class="set-checklist-items">
                <?php foreach ($users as $user): ?>
                <?php if ($user['username'] !== 'admin'): ?>
                <label class="checkbox-label">
                    <input type="checkbox" name="users[]" value="<?= (int) $user['id'] ?>"
                        <?= in_array($user['id'], $groupUsers ?? []) ? 'checked' : '' ?>>
                    <?= $h($user['username']) ?>
                    <span class="text-muted">(<?= ($user['role'] === 'admin' ? e__('Administrator') : e__(ucfirst((string) $user['role']))) ?>)</span>
                </label>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <small class="text-muted"><?= e__('Select users who can access this map group.') ?></small>
        </fieldset>
    </div>
    <footer class="set-foot">
        <?php if ($action === 'edit' && $id !== 1): ?>
        <a href="/admin/groups/delete/<?= $id ?>" class="wm-btn set-danger danger" data-method="post" data-confirm-label="<?= e__('Delete') ?>"
           data-confirm="<?= e__('Are you sure you want to delete this group? Maps will be moved to Default group.') ?>"><?= icon('trash-2') ?><span><?= e__('Delete…') ?></span></a>
        <?php endif; ?>
        <a href="/admin/groups" class="wm-btn" data-drawer-close><?= e__('Cancel') ?></a>
        <button type="submit" class="wm-btn wm-btn-primary"><?= $action === 'create' ? e__('Add group') : e__('Save') ?></button>
    </footer>
</form>
