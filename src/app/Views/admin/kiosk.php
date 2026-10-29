<?php
use App\Services\KioskService;

$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$zooms = [50, 75, 100, 125, 150, 175, 200, 250, 300];
?>
<div class="admin-kiosk-page">
    <div class="page-header">
        <div class="breadcrumb">
            <a href="/admin"><?= e__('Admin') ?></a> &raquo; <?= e__('Kiosk mode') ?>
        </div>
        <div class="page-actions">
            <a href="/cycle?fullscreen=1" class="btn btn-secondary"><?= e__('Open kiosk mode') ?></a>
        </div>
    </div>

    <form method="post" action="/admin/kiosk">
        <div class="form-card kiosk-defaults">
            <div class="form-group">
                <label for="kiosk-duration"><?= e__('Display time of each map (seconds)') ?></label>
                <input type="number" id="kiosk-duration" name="duration" class="form-control" required
                       value="<?= (int) $duration ?>" min="<?= KioskService::MIN_DURATION ?>" max="<?= KioskService::MAX_DURATION ?>">
                <small class="text-muted"><?= e__('Used for every map, unless the map has a display time of its own below.') ?></small>
            </div>
        </div>

        <?php if (empty($maps)): ?>
        <div class="empty-state">
            <p><?= e__('No maps configured yet.') ?></p>
        </div>
        <?php else: ?>
        <div class="data-table-container">
            <table class="data-table kiosk-table">
                <thead>
                    <tr>
                        <th><?= e__('Map') ?></th>
                        <th><?= e__('Group') ?></th>
                        <th><?= e__('Zoom') ?></th>
                        <th><?= e__('Display time (seconds)') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($maps as $map):
                        $id = (int) $map['id'];
                        $s = $settings[$id] ?? ['zoom' => KioskService::ZOOM_FIT, 'duration' => null];
                        $options = $zooms;
                        if ($s['zoom'] !== KioskService::ZOOM_FIT && !in_array($s['zoom'], $options, true)) {
                            $options[] = $s['zoom'];
                            sort($options);
                        }
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
                            <select name="maps[<?= $id ?>][zoom]" class="form-control" aria-label="<?= e__('Zoom') ?>: <?= $h($map['name']) ?>">
                                <option value="<?= KioskService::ZOOM_FIT ?>"><?= e__('Fit to screen') ?></option>
                                <?php foreach ($options as $z): ?>
                                <option value="<?= $z ?>" <?= $s['zoom'] === $z ? 'selected' : '' ?>><?= $z ?> %</option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="number" name="maps[<?= $id ?>][duration]" class="form-control"
                                   value="<?= $s['duration'] === null ? '' : (int) $s['duration'] ?>"
                                   min="<?= KioskService::MIN_DURATION ?>" max="<?= KioskService::MAX_DURATION ?>"
                                   placeholder="<?= e__('Default (:seconds s)', ['seconds' => (int) $duration]) ?>"
                                   aria-label="<?= e__('Display time (seconds)') ?>: <?= $h($map['name']) ?>">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted kiosk-help">
            <?= e__('Fit to screen enlarges or shrinks the map to fill the screen. A percentage shows the map at that share of its size, centred; what does not fit is cut off. Leave the display time empty to use the one above. Inactive maps are not shown in kiosk mode.') ?>
        </p>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= e__('Save') ?></button>
            <a href="/admin" class="btn btn-secondary"><?= e__('Cancel') ?></a>
        </div>
    </form>
</div>

<style>
.kiosk-defaults { max-width: 520px; margin-bottom: 20px; }
.kiosk-table select.form-control,
.kiosk-table input.form-control { min-width: 130px; max-width: 180px; }
.kiosk-help { margin: 12px 0 0; font-size: 13px; }
.admin-kiosk-page .data-table-container { overflow-x: auto; }
</style>
