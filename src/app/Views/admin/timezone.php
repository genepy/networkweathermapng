<?php
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<div class="admin-timezone-page">
    <div class="page-header">
        <div class="breadcrumb">
            <a href="/admin"><?= e__('Admin') ?></a> &raquo; <?= e__('Time zone') ?>
        </div>
    </div>

    <form method="post" action="/admin/timezone">
        <div class="form-card timezone-defaults">
            <div class="form-group">
                <label for="timezone-default"><?= e__('Default time zone') ?></label>
                <select id="timezone-default" name="timezone" class="form-control">
                    <option value=""><?= e__('Server setting (:zone)', ['zone' => $appZone]) ?></option>
                    <?php foreach ($zones as $zone): ?>
                    <option value="<?= $h($zone) ?>" <?= $zone === $default && $default !== $appZone ? 'selected' : '' ?>><?= $h($zone) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted"><?= e__('Used for every map, unless the map has a time zone of its own below.') ?></small>
            </div>
        </div>

        <?php if (empty($maps)): ?>
        <div class="empty-state">
            <p><?= e__('No maps configured yet.') ?></p>
        </div>
        <?php else: ?>
        <datalist id="timezone-list">
            <?php foreach ($zones as $zone): ?>
            <option value="<?= $h($zone) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <div class="data-table-container">
            <table class="data-table timezone-table">
                <thead>
                    <tr>
                        <th><?= e__('Map') ?></th>
                        <th><?= e__('Group') ?></th>
                        <th><?= e__('Time zone') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($maps as $map):
                        $id = (int) $map['id'];
                    ?>
                    <tr>
                        <td>
                            <strong><?= $h($map['name']) ?></strong>
                            <?php if (!(int) $map['active']): ?>
                            <span class="status-badge status-inactive"><?= e__('Inactive') ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $h($map['group_name'] ?? __('Default')) ?></td>
                        <td>
                            <input type="text" name="maps[<?= $id ?>]" class="form-control" list="timezone-list"
                                   value="<?= $h($overrides[$id] ?? '') ?>" autocomplete="off" spellcheck="false"
                                   placeholder="<?= e__('Default (:zone)', ['zone' => $default]) ?>"
                                   aria-label="<?= e__('Time zone') ?>: <?= $h($map['name']) ?>">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted timezone-help">
            <?= e__('The time zone applies to the date drawn on the map, the last update time and the traffic graphs. Leave a map empty to use the default time zone. A map shows the change at its next refresh.') ?>
        </p>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= e__('Save') ?></button>
            <a href="/admin" class="btn btn-secondary"><?= e__('Cancel') ?></a>
        </div>
    </form>
</div>

<style>
.timezone-defaults { max-width: 520px; margin-bottom: 20px; }
.timezone-table input.form-control { min-width: 200px; max-width: 280px; }
.timezone-help { margin: 12px 0 0; font-size: 13px; }
.admin-timezone-page .data-table-container { overflow-x: auto; }
</style>
