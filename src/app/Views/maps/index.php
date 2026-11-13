<?php
/**
 * The one Maps page (UI redesign step 3): thumbnails or a list, filter
 * chips, search, favourites; editors also manage the maps here (actions on
 * each map, bulk actions in the list, inactive maps shown greyed).
 *
 * @var array $maps @var array $groups @var array $groupCounts @var array $allGroups
 * @var string $filter all, favourites, busy or group:<id>
 * @var bool $canEdit @var bool $canImport
 */
$favourites = array_map('intval', (array) ($favourites ?? []));
$statuses = (array) ($statuses ?? []);
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$busyAt = 80; // "Over 80 %" chip

$favCount = 0;
$busyCount = 0;
$rows = [];
foreach ($maps as $i => $map) {
    $mid = (int) $map['id'];
    $st = $statuses[$mid] ?? null;
    $load = ($st !== null && $st['max'] !== null) ? (float) $st['max'] : null;
    $timezone = $timezones[$mid];
    $stTitle = __('No data yet');
    $stText = '';
    if ($load !== null) {
        $stTitle = __('Max load :pct %', ['pct' => $st['max']]) . ($st['link'] ? ' (' . $st['link'] . ')' : '');
        $stText = round($load) . ' %';
    }
    if ($st !== null && !empty($st['time'])) {
        $stTitle .= ' — ' . __('Updated :time', ['time' => \App\Services\TimezoneService::formatTimestamp((int) $st['time'], $timezone, 'Y-m-d H:i')]);
    }
    $isFav = in_array($mid, $favourites, true);
    $favCount += $isFav ? 1 : 0;
    $busyCount += ($load !== null && $load >= $busyAt) ? 1 : 0;
    $rows[] = [
        'map' => $map,
        'id' => $mid,
        'fav' => $isFav,
        'active' => (bool) $map['active'],
        'attrs' => sprintf(
            ' data-map-id="%d" data-group="%d" data-fav="%s" data-load="%s" data-stale="%s" data-timezone="%s" data-order="%d" data-name="%s"',
            $mid, (int) $map['group_id'], $isFav ? '1' : '0', $load === null ? '' : $h($load),
            ($st === null || !empty($st['stale'])) ? '1' : '0', $h($timezone), (int) ($map['list_order'] ?? $i),
            $h(mb_strtolower($map['name'] . ' ' . ($map['group_name'] ?? '') . ($canEdit ? ' ' . $map['config_file'] : '')))
        ),
        'badge' => sprintf(
            '<span class="map-state%s" title="%s" aria-label="%s"><span class="map-state-dot"%s></span><span class="map-state-text">%s</span></span>',
            ($st !== null && !empty($st['stale'])) ? ' map-state-stale' : '', $h($stTitle), $h($stTitle),
            ($st !== null && !empty($st['colour'])) ? ' style="background:' . $h($st['colour']) . '"' : '', $h($stText)
        ),
    ];
}

$favButton = static fn (array $r): string => sprintf(
    '<button type="button" class="map-fav" aria-pressed="%s" title="%s" aria-label="%s">%s</button>',
    $r['fav'] ? 'true' : 'false',
    $r['fav'] ? e__('Remove from favourites') : e__('Add to favourites'),
    $h(__('Favourite: :name', ['name' => $r['map']['name']])),
    icon('star')
);

// The "more actions" menu of a map, the same on its thumbnail and its row
$menu = static function (array $r) use ($h): string {
    $id = $r['id'];
    $item = static fn (string $href, string $ic, string $label, string $extra = '') =>
        '<a href="' . $href . '"' . $extra . '>' . icon($ic) . $h($label) . '</a>';
    return '<details class="row-menu">'
        . '<summary class="map-action" title="' . e__('More actions') . '" aria-label="' . $h(__('More actions for :name', ['name' => $r['map']['name']])) . '">' . icon('ellipsis') . '</summary>'
        . '<div class="row-menu-items">'
        . $item('/editor/maps/edit/' . $id . '?return=%2F', 'settings', __('Settings'), ' data-map-settings')
        . $item('/editor/maps/duplicate/' . $id . '?return=%2F', 'copy', __('Duplicate'))
        . '<button type="button" class="menu-move" data-map-id="' . $id . '" data-group="' . (int) $r['map']['group_id'] . '">' . icon('arrow-left-right') . e__('Move to…') . '</button>'
        . $item('/editor/maps/toggle/' . $id . '?return=%2F', $r['active'] ? 'eye-off' : 'eye', $r['active'] ? __('Hide from users') : __('Show to users'), ' data-method="post"')
        . $item('/map/' . $id . '?fullscreen=1', 'monitor', __('Fullscreen'))
        . $item('/editor/maps/export/' . $id, 'download', __('Download .conf'))
        . $item('/editor/maps/delete/' . $id, 'trash-2', __('Delete…'), ' class="danger" data-method="post" data-confirm-label="' . e__('Delete') . '" data-confirm="'
            . $h(__('Delete the map ":name" and all its data (configuration, images, history, permissions)? This cannot be undone.', ['name' => $r['map']['name']])) . '"')
        . '</div></details>';
};

$chips = [['all', __('All'), count($maps)], ['favourites', __('Favourites'), $favCount]];
foreach ($groups as $g) {
    $chips[] = ['group:' . (int) $g['id'], (string) $g['name'], (int) ($groupCounts[(int) $g['id']] ?? 0)];
}
$chips[] = ['busy', __('Over :pct %', ['pct' => $busyAt]), $busyCount];
?>
<link rel="stylesheet" href="<?= asset('/assets/css/viewer.css') ?>">
<div class="maps-page" id="mapsPage" data-view="grid" data-busy="<?= $busyAt ?>">
    <script>try { document.getElementById('mapsPage').dataset.view = localStorage.getItem('wm-maps-view') === 'list' ? 'list' : 'grid'; } catch (e) { /* private mode */ }</script>
    <div class="page-header">
        <div class="maps-heading">
            <?= breadcrumb([[__('Maps')]]) ?>
            <span class="maps-total"><?= $h(__(':n maps', ['n' => count($maps)])) ?></span>
        </div>
        <div class="page-actions">
            <?php if ($maps): ?>
            <div class="segmented maps-view-switch" role="group" aria-label="<?= e__('Show the maps as') ?>">
                <button type="button" data-view="grid" aria-pressed="true" title="<?= e__('Thumbnails') ?>" aria-label="<?= e__('Thumbnails') ?>"><?= icon('layout-grid') ?></button>
                <button type="button" data-view="list" aria-pressed="false" title="<?= e__('List') ?>" aria-label="<?= e__('List') ?>"><?= icon('list') ?></button>
            </div>
            <a href="/cycle" class="wm-btn" aria-label="<?= e__('Kiosk') ?>"><?= icon('tv') ?><span><?= e__('Kiosk') ?></span></a>
            <?php endif; ?>
            <?php if ($canEdit): ?>
            <a href="/editor/maps/create?return=%2F" class="wm-btn wm-btn-primary" aria-label="<?= e__('New map') ?>"><?= icon('plus') ?><span><?= e__('New map') ?></span></a>
            <?php if ($canImport || $maps): ?>
            <details class="row-menu">
                <summary class="wm-btn" title="<?= e__('More actions') ?>" aria-label="<?= e__('More actions') ?>"><?= icon('ellipsis') ?></summary>
                <div class="row-menu-items">
                    <?php if ($canImport): ?>
                    <a href="/admin/migration"><?= icon('upload') ?><?= e__('Import maps') ?></a>
                    <?php endif; ?>
                    <?php if ($maps): ?>
                    <a href="/editor/maps/export"><?= icon('package') ?><?= e__('Export all') ?></a>
                    <?php endif; ?>
                </div>
            </details>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$maps): ?>
    <div class="empty-state">
        <p><?= e__('No maps available.') ?></p>
        <?php if ($canEdit): ?>
        <p>
            <a href="/editor/maps/create?return=%2F" class="wm-btn wm-btn-primary"><?= e__('Create your first map') ?></a>
            <?php if ($canImport): ?>
            <a href="/admin/migration" class="wm-btn"><?= e__('Import existing maps') ?></a>
            <?php endif; ?>
        </p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <?php if (!empty($titleConflicts)): ?>
    <div class="maps-notice" role="note">
        <?= icon('circle-dot') ?>
        <div>
            <p><?= e__('These maps draw a title that differs from their name. Open their settings to choose which one to keep:') ?></p>
            <ul>
                <?php foreach ($maps as $m): if (!isset($titleConflicts[(int) $m['id']])) { continue; } ?>
                <li><a href="/editor/maps/edit/<?= (int) $m['id'] ?>?return=%2F" data-map-settings><?= $h($m['name']) ?></a>
                    <span class="text-muted"><?= $h(__('title: :title', ['title' => $titleConflicts[(int) $m['id']]])) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
    <div class="chips" role="group" aria-label="<?= e__('Filter the maps') ?>" id="mapsChips">
        <?php foreach ($chips as [$key, $label, $n]): ?>
        <button type="button" class="chip<?= $key === 'busy' ? ' chip-busy' : '' ?>" data-filter="<?= $h($key) ?>" aria-pressed="<?= $filter === $key ? 'true' : 'false' ?>">
            <?php if ($key === 'favourites'): ?><?= icon('star') ?><?php elseif ($key === 'busy'): ?><span class="chip-dot" aria-hidden="true"></span><?php endif; ?>
            <span><?= $h($label) ?></span> <span class="chip-count"><?= (int) $n ?></span>
        </button>
        <?php endforeach; ?>
    </div>

    <div class="maps-toolbar">
        <input type="search" id="mapsSearch" class="maps-search" placeholder="<?= e__('Search maps') ?>" aria-label="<?= e__('Search maps') ?>" autocomplete="off">
        <span class="maps-count" id="mapsCount" aria-live="polite"></span>
    </div>
    <p class="maps-no-match" id="mapsNoMatch" hidden><?= e__('No map matches the search or the filter.') ?></p>

    <p class="visually-hidden" id="mapsMoved" aria-live="polite"></p>
    <div class="maps-grid" id="mapsGrid"<?= $canEdit ? ' data-reorder' : '' ?>>
        <?php foreach ($rows as $r): $map = $r['map']; $id = $r['id']; ?>
        <div class="map-card<?= $r['active'] ? '' : ' is-hidden-map' ?>"<?= $r['attrs'] ?>>
            <a href="/map/<?= $id ?>" class="map-link">
                <div class="map-thumbnail">
                    <img src="/map/<?= $id ?>/thumb?fast=1" alt="" loading="lazy">
                </div>
                <div class="map-info">
                    <div class="map-info-text">
                        <h3><?= $h($map['name']) ?></h3>
                        <span class="map-group">
                            <?= $h($map['group_name'] ?? __('Default')) ?>
                            <?php if (!$r['active']): ?><span class="badge-hidden" title="<?= e__('Inactive: only editors see this map') ?>"><?= e__('Hidden') ?></span><?php endif; ?>
                        </span>
                    </div>
                    <?= $r['badge'] ?>
                </div>
            </a>
            <?= $favButton($r) ?>
            <?php if ($canEdit): ?>
            <div class="map-card-actions">
                <button type="button" class="map-action map-drag" draggable="true"
                        aria-label="<?= $h(__('Reorder :name: drag it, or use the arrow keys', ['name' => $map['name']])) ?>"
                        title="<?= e__('Drag to reorder (or use the arrow keys)') ?>"><?= icon('grip-vertical') ?></button>
                <a href="/editor/edit/<?= $id ?>" class="map-action map-action-label" aria-label="<?= $h(__('Edit :name', ['name' => $map['name']])) ?>"><?= icon('pencil') ?><span><?= e__('Edit') ?></span></a>
                <?= $menu($r) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <form method="post" action="/editor/maps/bulk" id="mapsList" class="maps-list">
        <input type="hidden" name="return" value="/">
        <?php if ($canEdit): ?>
        <div class="bulk-bar" hidden>
            <span class="bulk-count"></span>
            <button type="submit" name="action" value="activate" class="btn btn-sm"><?= e__('Show to users') ?></button>
            <button type="submit" name="action" value="deactivate" class="btn btn-sm"><?= e__('Hide from users') ?></button>
            <select name="group_id" aria-label="<?= e__('Group') ?>">
                <?php foreach ($allGroups as $g): ?>
                <option value="<?= (int) $g['id'] ?>"><?= $h($g['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="action" value="move" class="btn btn-sm"><?= e__('Move to group') ?></button>
            <button type="submit" name="action" value="export" class="btn btn-sm"><?= e__('Export') ?></button>
            <button type="submit" name="action" value="delete" class="btn btn-sm btn-danger"
                    data-confirm-bulk="<?= e__('Delete the selected maps and all their data? This cannot be undone.') ?>"><?= e__('Delete') ?></button>
        </div>
        <?php endif; ?>
        <div class="data-table-container">
            <table class="data-table maps-table">
                <thead>
                    <tr>
                        <?php if ($canEdit): ?>
                        <th class="col-check"><input type="checkbox" id="check-all" aria-label="<?= e__('Select all') ?>"></th>
                        <?php endif; ?>
                        <th class="col-fav"><span class="visually-hidden"><?= e__('Favourites') ?></span></th>
                        <th><?= e__('Name') ?></th>
                        <th class="col-group"><?= e__('Group') ?></th>
                        <th><?= e__('Load') ?></th>
                        <?php if ($canEdit): ?>
                        <th><?= e__('Status') ?></th>
                        <th class="col-last-run"><?= e__('Last run') ?></th>
                        <?php endif; ?>
                        <th class="col-actions"><span class="visually-hidden"><?= e__('Actions') ?></span></th>
                    </tr>
                </thead>
                <tbody id="mapsRows">
                    <?php foreach ($rows as $r): $map = $r['map']; $id = $r['id']; ?>
                    <tr class="map-row<?= $r['active'] ? '' : ' is-hidden-map' ?>"<?= $r['attrs'] ?>>
                        <?php if ($canEdit): ?>
                        <td class="col-check"><input type="checkbox" name="ids[]" value="<?= $id ?>" aria-label="<?= $h($map['name']) ?>"></td>
                        <?php endif; ?>
                        <td class="col-fav"><?= $favButton($r) ?></td>
                        <td>
                            <a href="/map/<?= $id ?>" class="map-row-name"><?= $h($map['name']) ?></a>
                            <?php if ($canEdit): ?><code class="map-file"><?= $h($map['config_file']) ?></code><?php endif; ?>
                        </td>
                        <td class="col-group"><?= $h($map['group_name'] ?? __('Default')) ?></td>
                        <td><?= $r['badge'] ?></td>
                        <?php if ($canEdit): ?>
                        <td>
                            <a href="/editor/maps/toggle/<?= $id ?>?return=%2F" data-method="post" role="switch" aria-checked="<?= $r['active'] ? 'true' : 'false' ?>"
                               class="status-toggle <?= $r['active'] ? 'is-on' : '' ?>"
                               title="<?= $r['active'] ? e__('Active: click to deactivate (hidden from users)') : e__('Inactive: click to activate') ?>">
                                <span class="status-toggle-track" aria-hidden="true"></span>
                                <span class="status-toggle-label"><?= $r['active'] ? e__('Active') : e__('Inactive') ?></span>
                            </a>
                        </td>
                        <td class="col-last-run"><?= $map['last_run'] ? $h($map['last_run']) : e__('Never') ?></td>
                        <?php endif; ?>
                        <td class="col-actions">
                            <div class="row-actions">
                                <?php if ($canEdit): ?>
                                <a href="/editor/edit/<?= $id ?>" class="btn btn-sm btn-primary" title="<?= e__('Edit') ?>"><?= icon('pencil') ?><span class="btn-label"><?= e__('Edit') ?></span></a>
                                <?= $menu($r) ?>
                                <?php else: ?>
                                <a href="/map/<?= $id ?>" class="btn btn-sm" title="<?= e__('View') ?>"><?= icon('eye') ?><span class="btn-label"><?= e__('View') ?></span></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php if ($canEdit && $maps): ?>
<dialog id="moveDialog" class="wm-confirm" aria-labelledby="moveDialogTitle">
    <form method="dialog" id="moveForm">
        <h2 id="moveDialogTitle"><?= e__('Move to another group') ?></h2>
        <p><label for="moveGroup"><?= e__('Group') ?></label>
            <select id="moveGroup" class="form-control">
                <?php foreach ($allGroups as $g): ?>
                <option value="<?= (int) $g['id'] ?>"><?= $h($g['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <div class="wm-confirm-actions">
            <button type="button" class="wm-btn" value="cancel" autofocus><?= e__('Cancel') ?></button>
            <button type="submit" class="wm-btn wm-btn-primary" value="move"><?= e__('Move') ?></button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<script>
window.WM_T = Object.assign(window.WM_T || {}, <?= json_encode((object) (\App\Core\I18n::forScript([':n selected', 'Failed to move map', 'Moved to position :pos of :total in :group', 'The new order could not be saved']) + \App\Core\I18n::section('viewer-js')), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>);
</script>
<script src="<?= asset('/assets/js/maplist.js') ?>" defer></script>
