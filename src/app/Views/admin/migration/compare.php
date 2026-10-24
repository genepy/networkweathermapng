<?php $id = $meta['id']; ?>
<div class="admin-container migration-page">
    <div class="page-header">
        <?= breadcrumb([[__('Admin'), '/admin'], [__('Migration'), '/admin/migration'], [$meta['name'], '/admin/migration/' . $id], [__('Compare')]]) ?>
        <div class="page-actions">
            <a href="/admin/migration/<?= $id ?>" class="btn"><?= e__('Back to the choices') ?></a>
        </div>
    </div>

    <div class="compare-modes" role="tablist">
        <button type="button" class="btn btn-sm active" data-mode="side"><?= e__('Side by side') ?></button>
        <button type="button" class="btn btn-sm" data-mode="diff"><?= e__('Differences') ?></button>
        <button type="button" class="btn btn-sm" data-mode="fade"><?= e__('Overlay') ?></button>
        <input type="range" min="0" max="100" value="50" class="fade-range" hidden aria-label="<?= e__('Overlay') ?>">
    </div>
    <p class="text-muted small compare-help" data-for="diff" hidden><?= e__('Identical pixels are black: what shows in colour has moved or changed. Live data is not read for either drawing.') ?></p>

    <div class="compare mode-side">
        <figure>
            <figcaption><?= e__('Before (PNG, old engine)') ?></figcaption>
            <img src="/admin/migration/<?= $id ?>/image/old.png" alt="">
        </figure>
        <figure>
            <figcaption><?= e__('After (SVG)') ?></figcaption>
            <img src="/admin/migration/<?= $id ?>/image/new.svg" alt="">
        </figure>
    </div>
</div>
<script>
(function () {
    var box = document.querySelector('.compare');
    var range = document.querySelector('.fade-range');
    var help = document.querySelector('.compare-help');
    var after = box.querySelectorAll('img')[1];
    document.querySelectorAll('.compare-modes [data-mode]').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('.compare-modes [data-mode]').forEach(function (o) { o.classList.toggle('active', o === b); });
            box.className = 'compare mode-' + b.dataset.mode;
            range.hidden = b.dataset.mode !== 'fade';
            help.hidden = b.dataset.mode !== 'diff';
            after.style.opacity = b.dataset.mode === 'fade' ? range.value / 100 : '';
        });
    });
    range.addEventListener('input', function () { after.style.opacity = range.value / 100; });
})();
</script>
