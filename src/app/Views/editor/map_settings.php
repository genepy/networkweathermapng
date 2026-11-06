<?php
/**
 * Map settings (UI redesign step 5): every setting of one map on four tabs.
 * A page of its own, or ($panel) only the panel, shown in a drawer by
 * map-settings.js. Without JavaScript the four sections follow each other.
 *
 * @var array $map @var array $groups @var ?string $drawnTitle the title drawn on the map when it differs from the name
 * @var array $settings MapSettings::load() @var array $audience MapSettings::audience()
 * @var string[] $zones @var int[] $zoomSteps @var string $return @var bool $panel
 */
use App\Services\KioskService;

$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$id = (int) $map['id'];
$s = $settings;
$zoomOptions = $zoomSteps;
if ($s['kioskZoom'] !== KioskService::ZOOM_FIT && !in_array($s['kioskZoom'], $zoomOptions, true)) {
    $zoomOptions[] = $s['kioskZoom'];
    sort($zoomOptions);
}
$isAdmin = $auth->isAdmin();
$tabs = [
    'general' => __('General'),
    'display' => __('Display & kiosk'),
    'recording' => __('Recording'),
    'access' => __('Access'),
];
$names = static fn (array $list): string => $h(implode(', ', $list));
?>
<?php if (!$panel): ?>
<div class="admin-container map-settings-page">
    <div class="page-header">
        <?= breadcrumb([[__('Maps'), '/'], [$map['name'], '/map/' . $id], [__('Map settings')]]) ?>
    </div>
<?php endif; ?>
<form method="post" action="/editor/maps/edit/<?= $id ?>" class="map-settings<?= $panel ? '' : ' form-card' ?>" id="map-settings-form" data-map-settings-form>
    <input type="hidden" name="return" value="<?= $h($return) ?>">
    <?php if ($panel): ?>
    <div class="ms-head">
        <h2 id="ms-title"><?= e__('Map settings') ?></h2>
        <button type="button" class="ms-close" data-ms-close aria-label="<?= e__('Close') ?>" title="<?= e__('Close') ?>"><?= icon('x') ?></button>
    </div>
    <?php endif; ?>
    <div class="ms-tabs" role="tablist" aria-label="<?= e__('Map settings') ?>" hidden>
        <?php foreach ($tabs as $key => $label): ?>
        <button type="button" role="tab" id="ms-tab-<?= $key ?>" aria-controls="ms-<?= $key ?>" data-tab="<?= $key ?>"
                aria-selected="<?= $key === 'general' ? 'true' : 'false' ?>" tabindex="<?= $key === 'general' ? '0' : '-1' ?>"><?= $h($label) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="ms-body">
        <section class="ms-section" id="ms-general" role="tabpanel" aria-labelledby="ms-tab-general">
            <h3 class="ms-section-title"><?= e__('General') ?></h3>
            <?php if ($drawnTitle !== null): ?>
            <fieldset class="ms-conflict">
                <legend><?= e__('The title drawn on this map differs from its name. Which one should the map keep?') ?></legend>
                <label class="ms-choice"><input type="radio" name="keep" value="name" data-name="<?= $h($map['name']) ?>">
                    <span><?= e__('The name') ?> <strong><?= $h($map['name']) ?></strong></span></label>
                <label class="ms-choice"><input type="radio" name="keep" value="title" data-name="<?= $h($drawnTitle) ?>">
                    <span><?= e__('The drawn title') ?> <strong><?= $h($drawnTitle) ?></strong></span></label>
                <small class="text-muted"><?= e__('The other one is replaced: a map has one name, also drawn as its title. Without a choice, both stay as they are.') ?></small>
            </fieldset>
            <?php endif; ?>
            <div class="form-group">
                <label for="ms-name"><?= e__('Name') ?></label>
                <input type="text" id="ms-name" name="name" class="form-control" value="<?= $h($map['name']) ?>" required maxlength="200">
                <small class="text-muted"><?= e__('Also the title drawn on the map.') ?></small>
            </div>
            <div class="form-group">
                <label for="ms-group"><?= e__('Group') ?></label>
                <select id="ms-group" name="group_id" class="form-control">
                    <?php foreach ($groups as $group): ?>
                    <option value="<?= (int) $group['id'] ?>" <?= (int) $map['group_id'] === (int) $group['id'] ? 'selected' : '' ?>><?= $h($group['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="ms-switch">
                    <input type="checkbox" role="switch" name="active" value="1" <?= $map['active'] ? 'checked' : '' ?>>
                    <span>
                        <strong><?= e__('Visible to users') ?></strong>
                        <small class="text-muted"><?= e__('Off hides it from viewers, on the Maps page and in the kiosk. Editors still see it.') ?></small>
                    </span>
                </label>
            </div>
            <div class="form-group">
                <label for="ms-file"><?= e__('Configuration file') ?></label>
                <input type="text" id="ms-file" class="form-control ms-mono" value="<?= $h($map['config_file']) ?>" readonly>
                <label class="checkbox-label">
                    <input type="checkbox" name="rename_file" value="1">
                    <?= e__('Rename the file after the map name') ?>
                </label>
            </div>
        </section>

        <section class="ms-section" id="ms-display" role="tabpanel" aria-labelledby="ms-tab-display">
            <h3 class="ms-section-title"><?= e__('Display & kiosk') ?></h3>
            <div class="form-group">
                <label for="ms-timezone"><?= e__('Time zone') ?></label>
                <input type="text" id="ms-timezone" name="timezone" class="form-control" list="ms-zones" autocomplete="off" spellcheck="false"
                       value="<?= $h($s['timezone']) ?>" placeholder="<?= e__('Default (:zone)', ['zone' => $s['defaultZone']]) ?>">
                <datalist id="ms-zones">
                    <?php foreach ($zones as $zone): ?>
                    <option value="<?= $h($zone) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <small class="text-muted"><?= e__('Dates shown on this map and in its history. Type to search; leave empty to use the default zone.') ?></small>
            </div>
            <div class="form-group form-pair">
                <label><?= e__('Zoom in the kiosk') ?>
                    <select name="kiosk_zoom" class="form-control">
                        <option value="<?= KioskService::ZOOM_FIT ?>"><?= e__('Fit to screen') ?></option>
                        <?php foreach ($zoomOptions as $z): ?>
                        <option value="<?= (int) $z ?>" <?= $s['kioskZoom'] === $z ? 'selected' : '' ?>><?= (int) $z ?> %</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><?= e__('Display time (seconds)') ?>
                    <input type="number" name="kiosk_duration" class="form-control"
                           value="<?= $s['kioskDuration'] === null ? '' : (int) $s['kioskDuration'] ?>"
                           min="<?= KioskService::MIN_DURATION ?>" max="<?= KioskService::MAX_DURATION ?>"
                           placeholder="<?= e__('Default (:seconds s)', ['seconds' => (int) $s['kioskDefault']]) ?>">
                </label>
            </div>
            <?php if ($isAdmin): ?>
            <p class="text-muted ms-note"><?= e__('Defaults for every map:') ?> <a href="/admin/timezone"><?= e__('Time zone') ?></a> · <a href="/admin/kiosk"><?= e__('Kiosk mode') ?></a></p>
            <?php endif; ?>
        </section>

        <section class="ms-section" id="ms-recording" role="tabpanel" aria-labelledby="ms-tab-recording">
            <h3 class="ms-section-title"><?= e__('Recording') ?></h3>
            <?php if ($s['recordingAllowed']): ?>
            <input type="hidden" name="recording_shown" value="1">
            <div class="form-group">
                <label class="ms-switch">
                    <input type="checkbox" role="switch" name="recorded" value="1" <?= $s['recorded'] ? 'checked' : '' ?>>
                    <span>
                        <strong><?= e__('Record this map') ?></strong>
                        <small class="text-muted"><?= e__('An image every :interval min, kept :hours h, to play the map back from its viewer.', ['interval' => $s['interval'], 'hours' => $s['retention']]) ?></small>
                    </span>
                </label>
            </div>
            <?php if ($s['recorded']): ?>
            <p class="text-muted ms-note"><?= e__(':n images kept. Switching recording off deletes them.', ['n' => $s['frames']]) ?>
                <?php if ($s['frames'] > 0): ?><a href="/map/<?= $id ?>#play"><?= e__('Play them back') ?></a><?php endif; ?></p>
            <p class="text-muted ms-note"><?= e__('In the viewer of the map, the Live / Last :hours h switch next to the search plays the recording back (key H).', ['hours' => $s['retention']]) ?></p>
            <?php endif; ?>
            <?php else: ?>
            <p class="ms-note"><?= e__('Recording is switched off for the whole application.') ?>
                <?php if ($isAdmin): ?><a href="/admin/recording"><?= e__('Map recording') ?></a><?php else: ?><?= e__('An administrator can switch it on.') ?><?php endif; ?></p>
            <?php endif; ?>
        </section>

        <section class="ms-section" id="ms-access" role="tabpanel" aria-labelledby="ms-tab-access">
            <h3 class="ms-section-title"><?= e__('Access') ?></h3>
            <?php if ($audience['open']): ?>
            <p class="ms-note"><?= e__('Sign-in is switched off: everybody who opens the application sees this map.') ?></p>
            <?php else: ?>
            <dl class="ms-access">
                <dt><?= e__('Administrators and editors') ?></dt>
                <dd><?= $audience['staff'] ? $names($audience['staff']) : e__('Nobody') ?> <small class="text-muted"><?= e__('(they see every map)') ?></small></dd>
                <?php if ($audience['public']): ?>
                <dt><?= e__('Everybody signed in') ?></dt>
                <dd><?= e__('This map is shared with every user.') ?></dd>
                <?php endif; ?>
                <dt><?= e__('Viewers of the group :group', ['group' => (string) (array_column($groups, 'name', 'id')[(int) $map['group_id']] ?? '')]) ?></dt>
                <dd><?= $audience['group'] ? $names($audience['group']) : e__('Nobody') ?></dd>
                <?php if ($audience['direct']): ?>
                <dt><?= e__('Viewers given this map') ?></dt>
                <dd><?= $names($audience['direct']) ?></dd>
                <?php endif; ?>
            </dl>
            <?php if (!$audience['active']): ?>
            <p class="ms-note ms-warn"><?= e__('This map is hidden from users: viewers do not see it until it is visible again.') ?></p>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <p class="text-muted ms-note"><?= e__('Who sees a map is set per group:') ?> <a href="/admin/groups/edit/<?= (int) $map['group_id'] ?>"><?= e__('Edit the group') ?></a> · <a href="/admin/users"><?= e__('Users') ?></a></p>
            <?php else: ?>
            <p class="text-muted ms-note"><?= e__('Who sees a map is set per group by an administrator.') ?></p>
            <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <div class="ms-actions">
        <a href="/editor/maps/delete/<?= $id ?>" data-method="post" class="wm-btn ms-delete danger" data-confirm-label="<?= e__('Delete') ?>"
           data-confirm="<?= $h(__('Delete the map ":name" and all its data (configuration, images, history, permissions)? This cannot be undone.', ['name' => $map['name']])) ?>"><?= icon('trash-2') ?><span><?= e__('Delete map…') ?></span></a>
        <a href="<?= $h($return) ?>" class="wm-btn" data-ms-close><?= e__('Cancel') ?></a>
        <button type="submit" class="wm-btn wm-btn-primary"><?= e__('Save') ?></button>
    </div>
</form>
<?php if (!$panel): ?>
</div>
<?php endif; ?>
