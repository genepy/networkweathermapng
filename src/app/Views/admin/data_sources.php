<?php
/**
 * Data sources (Settings, UI redesign step 6): one row per source with its
 * state, a Test button on the row and a ⋯ menu. Add and Edit open the form
 * in a drawer (settings.js, WMUI.drawer).
 *
 * @var array $sources @var array $types type => label
 */
use App\Services\SettingsNav;

$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$connected = count(array_filter($sources, static fn ($s) => $s['active'] && $s['status'] === 'connected'));
$states = [
    'connected' => __('Connected'),
    'error' => __('Error'),
    'unknown' => __('Not tested'),
];
?>
<section class="set-panel">
    <header class="set-head">
        <h1><?= e__('Data sources') ?>
            <?php if ($sources): ?>
            <span class="set-badge"><?= e__(':n connected', ['n' => $connected]) ?></span>
            <?php endif; ?>
        </h1>
        <div class="set-head-actions">
            <a href="/admin/data-sources/create" class="wm-btn wm-btn-primary" data-drawer><?= icon('plus') ?><?= e__('Add data source') ?></a>
        </div>
    </header>

    <?php if (empty($sources)): ?>
    <div class="set-body set-empty">
        <?= icon('plug') ?>
        <p><strong><?= e__('No data sources configured yet.') ?></strong></p>
        <p><?= e__('Add a data source to start fetching network data for your maps.') ?></p>
    </div>
    <?php else: ?>
    <ul class="ds-list">
        <?php foreach ($sources as $source):
            $id = (int) $source['id'];
            $status = $source['active'] ? (in_array($source['status'], ['connected', 'error'], true) ? $source['status'] : 'unknown') : 'off';
            $editUrl = '/admin/data-sources/edit/' . $id;
            $typeLabel = $types[$source['type']] ?? ucfirst((string) $source['type']);
        ?>
        <li class="ds-row" data-source-id="<?= $id ?>" data-source-name="<?= $h($source['name']) ?>">
            <span class="ds-dot is-<?= $status ?>" aria-hidden="true"></span>
            <div class="ds-text">
                <a class="ds-name" href="<?= $editUrl ?>" data-drawer><?= $h($source['name']) ?></a>
                <span class="visually-hidden ds-state"><?= $status === 'off' ? e__('Inactive') : $h($states[$status]) ?></span>
                <?php if (!$source['active']): ?>
                <span class="status-badge status-inactive"><?= e__('Inactive') ?></span>
                <?php endif; ?>
                <div class="ds-meta">
                    <span><?= $h(__($typeLabel)) ?></span>
                    <?php if ((string) $source['url'] !== ''): ?>
                    <span class="ds-url"><?= $h($source['url']) ?></span>
                    <?php endif; ?>
                    <span class="ds-tested"><?= $h(SettingsNav::testedAgo($source['last_check'] ?? null)) ?></span>
                </div>
            </div>
            <button type="button" class="wm-btn ds-test" data-test-source="<?= $id ?>"><?= e__('Test') ?></button>
            <details class="row-menu">
                <summary class="wm-btn ds-more" title="<?= e__('More actions') ?>" aria-label="<?= $h(__('More actions for :name', ['name' => $source['name']])) ?>"><?= icon('ellipsis') ?></summary>
                <div class="row-menu-items">
                    <a href="<?= $editUrl ?>" data-drawer><?= icon('pencil') ?><?= e__('Edit') ?></a>
                    <a href="/admin/data-sources/delete/<?= $id ?>" class="danger" data-method="post" data-confirm-label="<?= e__('Delete') ?>"
                       data-confirm="<?= $h(__('Delete the data source ":name"? Maps that read it show no data until they use another one.', ['name' => $source['name']])) ?>"><?= icon('trash-2') ?><?= e__('Delete…') ?></a>
                </div>
            </details>
            <div class="ds-result" hidden>
                <p class="ds-result-text" role="status"></p>
                <details class="ds-debug">
                    <summary><?= e__('Request and response') ?></summary>
                    <h4><?= e__('cURL Command') ?></h4>
                    <pre class="ds-curl"></pre>
                    <h4><?= e__('Request Payload') ?></h4>
                    <pre class="ds-request"></pre>
                    <h4><?= e__('Response') ?></h4>
                    <pre class="ds-response"></pre>
                </details>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <p class="set-help set-body-tail"><?= e__('Data sources provide hosts, interfaces and traffic data for your maps.') ?></p>
</section>
