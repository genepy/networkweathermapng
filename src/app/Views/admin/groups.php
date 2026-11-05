<?php
/** Map groups (Settings, UI redesign step 6). Add and Edit open the form in a drawer. */
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<section class="set-panel">
    <header class="set-head">
        <h1><?= e__('Groups') ?></h1>
        <div class="set-head-actions">
            <a href="/admin/groups/create" class="wm-btn wm-btn-primary" data-drawer><?= icon('plus') ?><?= e__('Add group') ?></a>
        </div>
    </header>
    <div class="data-table-container">
        <table class="data-table set-table">
            <thead>
                <tr>
                    <th><?= e__('Name') ?></th>
                    <th><?= e__('Maps') ?></th>
                    <th class="set-col-actions"><span class="visually-hidden"><?= e__('Actions') ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $group): ?>
                <tr>
                    <td>
                        <a class="set-row-name" href="/admin/groups/edit/<?= (int) $group['id'] ?>" data-drawer><?= $h($group['name']) ?></a>
                        <?php if ((int) $group['id'] === 1): ?>
                        <span class="text-muted">· <?= e__('Default') ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) ($group['map_count'] ?? 0) ?></td>
                    <td class="set-col-actions">
                        <a href="/admin/groups/edit/<?= (int) $group['id'] ?>" class="wm-btn" data-drawer aria-label="<?= $h(__('Edit :name', ['name' => $group['name']])) ?>"><?= icon('pencil') ?><span class="set-btn-label"><?= e__('Edit') ?></span></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="set-help set-body-tail"><?= e__('Groups sort the maps on the Maps page. A viewer sees the maps of the groups given to them.') ?></p>
</section>
