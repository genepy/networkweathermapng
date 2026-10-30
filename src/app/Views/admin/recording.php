<?php
use App\Services\RecordingService;

$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$recordedSet = array_flip($recorded);
$size = $diskUsage >= 1048576
    ? number_format($diskUsage / 1048576, 1) . ' MB'
    : number_format($diskUsage / 1024, 0) . ' KB';
?>
<div class="admin-recording-page">
    <div class="page-header">
        <?= breadcrumb([[__('Admin'), '/admin'], [__('Map recording')]]) ?>
    </div>

    <form method="post" action="/admin/recording">
        <div class="form-card recording-defaults">
            <p class="text-muted">
                <?= e__('A recorded map is drawn with live data at every interval, even when nobody looks at it, and each drawing is kept so the map can be played back in its viewer. Only the maps checked below are recorded: each one costs a data source query and a drawing per interval, and some disk space.') ?>
            </p>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="allowed" value="1" <?= $allowed ? 'checked' : '' ?>>
                    <?= e__('Allow map recording') ?>
                </label>
                <small class="text-muted"><?= e__('Turned off, nothing is recorded and the viewers no longer offer the playback. Frames already recorded are deleted when they get too old.') ?></small>
            </div>
            <div class="form-group">
                <label for="recording-interval"><?= e__('Interval between two frames (minutes)') ?></label>
                <input type="number" id="recording-interval" name="interval" class="form-control" required
                       value="<?= (int) $interval ?>" min="<?= RecordingService::MIN_INTERVAL ?>" max="<?= RecordingService::MAX_INTERVAL ?>">
            </div>
            <div class="form-group">
                <label for="recording-retention"><?= e__('Keep the frames for (hours)') ?></label>
                <input type="number" id="recording-retention" name="retention" class="form-control" required
                       value="<?= (int) $retention ?>" min="<?= RecordingService::MIN_RETENTION ?>" max="<?= RecordingService::MAX_RETENTION ?>">
                <small class="text-muted"><?= e__('24 hours at 5 minutes is 288 frames per map. Frames now use :size in all.', ['size' => $size]) ?></small>
            </div>
        </div>

        <?php if (empty($maps)): ?>
        <div class="empty-state">
            <p><?= e__('No maps configured yet.') ?></p>
        </div>
        <?php else: ?>
        <div class="data-table-container">
            <table class="data-table recording-table">
                <thead>
                    <tr>
                        <th><?= e__('Map') ?></th>
                        <th><?= e__('Group') ?></th>
                        <th><?= e__('Record') ?></th>
                        <th><?= e__('Frames kept') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($maps as $map):
                        $id = (int) $map['id'];
                        $on = isset($recordedSet[$id]);
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
                            <input type="checkbox" name="maps[<?= $id ?>]" value="1" <?= $on ? 'checked' : '' ?>
                                   aria-label="<?= e__('Record') ?>: <?= $h($map['name']) ?>">
                        </td>
                        <td><?= $on ? (int) ($frames[$id] ?? 0) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted recording-help">
            <?= e__('Unchecking a map deletes its frames. The recorder runs in the background of the Docker image; elsewhere, run php bin/record.php (or php bin/record.php --once from cron every minute).') ?>
        </p>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= e__('Save') ?></button>
            <a href="/admin" class="btn btn-secondary"><?= e__('Cancel') ?></a>
        </div>
    </form>
</div>

<style>
.recording-defaults { max-width: 620px; margin-bottom: 20px; }
.recording-defaults .form-control { max-width: 160px; }
.recording-help { margin: 12px 0 0; font-size: 13px; }
.admin-recording-page .data-table-container { overflow-x: auto; }
</style>
