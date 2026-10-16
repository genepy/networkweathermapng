<?php
/** @var array $meta @var array $report @var array $sources @var array $groups @var ?array $map */
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$choices = (array) ($meta['choices'] ?? []);
$statusLabel = [
    'matched' => __('Found'),
    'ambiguous' => __('To choose'),
    'unmatched' => __('Not found'),
    'keep' => __('Kept'),
];
$option = static function (array $c) use ($h): string {
    $value = json_encode(array_filter(['target' => $c['target'], 'datasource' => $c['datasource'] ?? null]), JSON_UNESCAPED_SLASHES);
    $label = trim(($c['host'] ?? '') . ' › ' . ($c['label'] ?? $c['target']), ' ›');
    if (!empty($c['bandwidth'])) {
        $label .= ' (' . $c['bandwidth'] . ')';
    }
    return '<option value="' . $h($value) . '"%s>' . $h($label) . '</option>';
};
$todo = count(array_filter($report['targets'], fn ($t) => in_array($t['match']['status'], ['ambiguous', 'unmatched'], true)));
$missing = count(array_filter($report['images'], fn ($i) => $i['status'] === 'missing'));
?>
<div class="admin-container migration-page">
    <div class="page-header">
        <div class="breadcrumb">
            <a href="/admin"><?= e__('Admin') ?></a> &raquo; <a href="/admin/migration"><?= e__('Migration') ?></a> &raquo; <?= $h($meta['name']) ?>
        </div>
        <?php if ($map): ?>
        <div class="page-actions">
            <a href="/editor/edit/<?= (int) $map['id'] ?>" class="btn"><?= e__('Edit map') ?></a>
            <a href="/admin/migration/<?= $meta['id'] ?>/rollback" data-method="post" class="btn"
               data-confirm="<?= e__('Put the uploaded file back in place of the converted map?') ?>"><?= e__('Restore original') ?></a>
        </div>
        <?php endif; ?>
    </div>

    <section class="panel summary">
        <div><strong><?= $h($report['title'] ?: $meta['name']) ?></strong> · <?= (int) $report['size'][0] ?>×<?= (int) $report['size'][1] ?></div>
        <div><?= e__(':nodes nodes, :links links', ['nodes' => $report['counts']['nodes'], 'links' => $report['counts']['links']]) ?></div>
        <div class="<?= $todo ? 'warn' : 'ok' ?>"><?= e__(':n target(s) to choose', ['n' => $todo]) ?></div>
        <div class="<?= $missing ? 'warn' : 'ok' ?>"><?= e__(':n missing image(s)', ['n' => $missing]) ?></div>
    </section>

    <?php if ($report['removed'] || $report['outputs'] || $report['warnings']): ?>
    <section class="panel">
        <h2><?= e__('Checks') ?></h2>
        <ul class="checks">
            <?php foreach ($report['removed'] as $line): ?>
            <li class="warn"><?= e__('Removed, it reads files on the old server:') ?> <code><?= $h($line) ?></code></li>
            <?php endforeach; ?>
            <?php foreach ($report['outputs'] as $line): ?>
            <li><?= e__('Ignored, this application writes its own files:') ?> <code><?= $h($line) ?></code></li>
            <?php endforeach; ?>
            <?php foreach ($report['warnings'] as $line): ?>
            <?php if (str_starts_with($line, 'Unrecognised config')): ?>
            <li><?= e__('Kept as is in the converted map, this application does not use it:') ?> <code><?= $h($line) ?></code></li>
            <?php else: ?>
            <li class="warn"><code><?= $h($line) ?></code></li>
            <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <form method="post" action="/admin/migration/<?= $meta['id'] ?>" class="migration-form">
        <section class="panel">
            <h2><?= e__('Map') ?></h2>
            <div class="form-row">
                <label><?= e__('Name') ?> <input type="text" name="name" value="<?= $h($meta['name']) ?>" required></label>
                <label><?= e__('Group') ?>
                    <select name="group_id">
                        <?php foreach ($groups as $g): ?>
                        <option value="<?= (int) $g['id'] ?>" <?= (int) ($meta['groupId'] ?? 1) === (int) $g['id'] ? 'selected' : '' ?>><?= $h($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <?php if ($report['fonts']): ?>
            <label class="inline">
                <input type="checkbox" name="fonts" value="1" <?= !empty($meta['fonts']) ? 'checked' : '' ?>>
                <?= e__('Replace the bitmap fonts (:list) by TrueType fonts of the same size', ['list' => implode(', ', $report['fonts'])]) ?>
            </label>
            <?php endif; ?>
        </section>

        <?php if ($report['images']): ?>
        <section class="panel">
            <h2><?= e__('Images') ?></h2>
            <table class="data-table">
                <thead><tr><th><?= e__('Used by') ?></th><th><?= e__('File') ?></th><th><?= e__('Status') ?></th></tr></thead>
                <tbody>
                <?php foreach ($report['images'] as $img): ?>
                    <tr>
                        <td><?= $h($img['item']) ?></td>
                        <td><code><?= $h($img['path']) ?></code></td>
                        <td>
                            <?php if ($img['status'] === 'ok'): ?><span class="status-badge status-active"><?= e__('Found') ?></span>
                            <?php elseif ($img['status'] === 'relinked'): ?><span class="status-badge status-active"><?= e__('Found as') ?></span> <code><?= $h($img['new']) ?></code>
                            <?php else: ?><span class="status-badge status-inactive"><?= e__('Missing') ?></span> <?= e__('add it to the zip, or to the objects folder') ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php endif; ?>

        <section class="panel">
            <h2><?= e__('Targets') ?></h2>
            <div class="form-row">
                <label><?= e__('Look for the interfaces in') ?>
                    <select name="source" onchange="location.href='/admin/migration/<?= $meta['id'] ?>?source=' + this.value">
                        <option value="0"><?= e__('No data source') ?></option>
                        <?php foreach ($sources as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) ($meta['sourceId'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= $h($s['name']) ?> (<?= $h($s['type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <?php if (!$report['targets']): ?>
                <p class="text-muted"><?= e__('This map has no target.') ?></p>
            <?php else: ?>
            <table class="data-table targets">
                <thead><tr><th><?= e__('Item') ?></th><th><?= e__('Old target') ?></th><th><?= e__('Status') ?></th><th><?= e__('New target') ?></th></tr></thead>
                <tbody>
                <?php foreach ($report['targets'] as $t):
                    $m = $t['match'];
                    $key = $t['key'];
                    $saved = $choices[$key] ?? null;
                    $savedValue = is_array($saved) ? json_encode(array_filter(['target' => $saved['target'], 'datasource' => $saved['datasource'] ?? null]), JSON_UNESCAPED_SLASHES) : $saved;
                    $default = $m['status'] === 'matched' ? json_encode(array_filter(['target' => $m['choice']['target'], 'datasource' => $m['choice']['datasource'] ?? null]), JSON_UNESCAPED_SLASHES) : 'keep';
                    $selected = $savedValue ?? $default;
                    $known = $selected === 'keep';
                ?>
                    <tr class="status-<?= $h($m['status']) ?>">
                        <td><?= $h($t['kind']) ?> <strong><?= $h($t['name']) ?></strong></td>
                        <td><code><?= $h($t['raw']) ?></code><div class="text-muted small"><?= $h($t['parsed']['kind']) ?><?= $t['parsed']['host'] !== '' ? ' · ' . $h($t['parsed']['host']) : '' ?><?= $t['parsed']['ifIndex'] !== null ? ' · ifIndex ' . (int) $t['parsed']['ifIndex'] : '' ?></div></td>
                        <td><span class="match match-<?= $h($m['status']) ?>"><?= $h($statusLabel[$m['status']] ?? $m['status']) ?></span>
                            <?php if ($m['note'] !== ''): ?><div class="text-muted small"><?= $h($m['note']) ?></div><?php endif; ?></td>
                        <td>
                            <?php if ($t['parsed']['convert'] && $t['parsed']['kind'] !== 'external'): ?>
                            <select name="target[<?= $h($key) ?>]">
                                <option value="keep" <?= $selected === 'keep' ? 'selected' : '' ?>><?= e__('Keep the old target') ?></option>
                                <?php
                                $list = $m['candidates'];
                                if ($m['status'] === 'matched') {
                                    array_unshift($list, $m['choice']);
                                }
                                $seen = [];
                                foreach ($list as $c):
                                    if (isset($seen[$c['target']])) { continue; }
                                    $seen[$c['target']] = true;
                                    $value = json_encode(array_filter(['target' => $c['target'], 'datasource' => $c['datasource'] ?? null]), JSON_UNESCAPED_SLASHES);
                                    $known = $known || $value === $selected;
                                    printf($option($c), $value === $selected ? ' selected' : '');
                                endforeach; ?>
                            </select>
                            <input type="text" name="manual[<?= $h($key) ?>]" placeholder="<?= e__('or type a target') ?>"
                                   value="<?= !$known && is_array($saved) ? $h($saved['target']) : '' ?>">
                            <?php else: ?>
                            <input type="hidden" name="target[<?= $h($key) ?>]" value="keep">
                            <span class="text-muted"><?= $t['parsed']['kind'] === 'external' ? e__('Replace it by hand in the editor') : e__('Kept as it is') ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </section>

        <div class="form-actions">
            <button type="submit" name="action" value="compare" class="btn"><?= e__('Compare old and new') ?></button>
            <button type="submit" name="action" value="apply" class="btn btn-primary"><?= $map ? e__('Convert again') : e__('Create the map') ?></button>
        </div>
    </form>
</div>
