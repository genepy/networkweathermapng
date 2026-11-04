<?php
/** @var int $code @var string $title @var string $message */
?>
<section class="error-page">
    <p class="error-code"><?= e__('Error :code', ['code' => (int) $code]) ?></p>
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($message) ?></p>
    <div class="error-actions">
        <?php if ($code === 419 || $code >= 500): ?>
        <a class="wm-btn" href="<?= htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? '/'), ENT_QUOTES) ?>"><?= e__('Try again') ?></a>
        <?php endif; ?>
        <a class="wm-btn wm-btn-primary" href="/"><?= e__('Back to the maps') ?></a>
    </div>
</section>
