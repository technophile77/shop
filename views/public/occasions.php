<?php

declare(strict_types=1);

/**
 * views/public/occasions.php — "Shop by Occasion" hub.
 *
 * Rendered inside views/layouts/public.php via ShopController::occasions().
 * A grid of occasion tiles (the on-site entry to browsing by occasion) plus a
 * custom-order CTA.
 *
 * Variables injected by BaseController::render():
 *   @var string $lang          Active locale.
 *   @var array  $occasionTiles  Tiles from OccasionMenu::tiles().
 *
 * @see \App\Controllers\ShopController::occasions()
 * @see views/public/_occasion_tiles.php
 */
?>

<div class="section section--dark" style="padding: 3rem 0 2rem">
  <div class="container text-center">
    <span class="eyebrow label" style="color:var(--color-muted)">
      <?= $lang === 'es' ? 'Comprar por Ocasión' : 'Shop by Occasion' ?>
    </span>
    <h1 style="color:var(--color-text-light)">
      <?= $lang === 'es' ? 'Flores para Cada Ocasión' : 'Flowers for Every Occasion' ?>
    </h1>
    <p style="color:rgba(255,255,255,0.75); max-width:600px; margin:1rem auto 0; line-height:1.65">
      <?= $lang === 'es'
          ? 'Elige la ocasión y te mostramos los ramos perfectos para enviar.'
          : "Pick the occasion and we'll show the perfect bouquets to send." ?>
    </p>
  </div>
</div>

<div class="section" style="padding-top: 2.5rem">
  <div class="container">
    <?php if (empty($occasionTiles)): ?>
    <p class="text-center" style="color:var(--color-muted); padding:2rem 0"><?= __t('products.custom_note') ?></p>
    <?php else: ?>
    <?php include __DIR__ . '/_occasion_tiles.php'; ?>
    <?php endif; ?>

    <div class="text-center" style="margin-top: 3.5rem; padding: 3rem; background: var(--color-bg-dark); border-radius: 12px">
      <span class="eyebrow label" style="color:var(--color-muted)">
        <?= $lang === 'es' ? '¿No ves tu ocasión?' : "Don't see your occasion?" ?>
      </span>
      <h3 style="color:var(--color-text-light); margin: 1rem 0">
        <?= __t('products.custom_note') ?>
      </h3>
      <a href="/<?= $lang ?>/order" class="btn btn-accent btn-lg" style="margin-top:0.5rem">
        <?= htmlspecialchars(__t('shop.customize')) ?>
      </a>
    </div>
  </div>
</div>
