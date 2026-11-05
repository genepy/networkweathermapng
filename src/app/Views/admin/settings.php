<?php
/**
 * Maps & rendering (Settings, UI redesign step 6): the settings read from
 * the environment, shown read only with the variable that sets them.
 *
 * @var array<string,string> $values
 */
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$rows = [
    'MAP_OUTPUT_FORMAT' => [__('Image format'), strtoupper($values['MAP_OUTPUT_FORMAT'])],
    'MAP_REFRESH_INTERVAL' => [__('Refresh interval'), __(':n seconds', ['n' => (int) $values['MAP_REFRESH_INTERVAL']])],
];
?>
<section class="set-panel">
    <header class="set-head">
        <h1><?= e__('Maps & rendering') ?></h1>
    </header>
    <div class="set-body">
        <dl class="set-values">
            <?php foreach ($rows as $var => [$label, $value]): ?>
            <div>
                <dt><?= $h($label) ?></dt>
                <dd><strong><?= $h($value) ?></strong> <code><?= $h($var) ?></code></dd>
            </div>
            <?php endforeach; ?>
        </dl>
        <p class="set-help"><?= e__('These settings come from the environment: the :file file or the Docker environment. Change them there, then restart the application.', ['file' => '.env']) ?></p>
        <p class="set-help"><?= e__('Kiosk, recording and time zone have pages of their own in this menu; the settings of one map are in its Map settings panel.') ?></p>
    </div>
</section>
