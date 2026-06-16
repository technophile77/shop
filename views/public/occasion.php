<?php

declare(strict_types=1);

/**
 * views/public/occasion.php — Occasion-filtered product grid page.
 *
 * Rendered inside views/layouts/public.php via ShopController::occasion().
 * Shows a heading + blurb for the occasion, then the bouquet grid. Each card
 * (and its add-to-cart panel) is rendered by the shared partial
 * views/public/_bouquet_card.php so the markup is defined once.
 *
 * Variables injected by BaseController::render() via ShopController::occasion():
 *   @var array<string, mixed>     $occasion  The occasion row from Occasion::findBySlug().
 *   @var array<int, array>        $products  Product rows from Product::byOccasion().
 *   @var array{heading:string, blurb:string} $copy Localised heading + blurb.
 *   @var string                   $lang      Active locale — 'en' or 'es'.
 *   @var string                   $slug      The occasion URL slug.
 *   @var array                    $jsonLd    JSON-LD ItemList for structured data.
 *   @var array<int, array>        $productColorOptions Per-buyable-product color options.
 *   @var array<int, array>        $paperColors Active paper-color rows.
 *   @var array<int, array>        $addons    Active add-on rows.
 *   @var array|null               $destination Current send-flowers destination, or null.
 *   @var string                   $csrfToken CSRF token for the add-to-cart form.
 *
 * @see \App\Controllers\ShopController
 * @see views/public/_bouquet_card.php
 */

// Occasion label used to prefill the order form occasion field.
$occasionLabel = $occasion['name_en'] ?? '';
?>

<!-- PAGE HEADER -->
<div class="section section--dark" style="padding: 3rem 0 2rem">
  <div class="container text-center">
    <span class="eyebrow label" style="color:var(--color-muted)">
      <?= htmlspecialchars($lang === 'es' ? 'Ocasión' : 'Occasion') ?>
    </span>
    <h1 style="color:var(--color-text-light)"><?= htmlspecialchars($copy['heading']) ?></h1>
    <?php if (!empty($copy['blurb'])): ?>
    <p style="color:rgba(255,255,255,0.75); max-width:600px; margin: 1rem auto 0; line-height:1.65">
      <?= htmlspecialchars($copy['blurb']) ?>
    </p>
    <?php endif; ?>
  </div>
</div>

<!-- PRODUCT GRID -->
<div class="section" style="padding-top: 2.5rem">
  <div class="container">

    <?php if (!empty($destination) && !empty($destination['venue_name'])): ?>
    <div style="background:var(--color-bg-dark); border-radius:10px; padding:1rem 1.25rem; margin-bottom:2rem; display:flex; align-items:center; gap:0.75rem; color:var(--color-text-light)">
      <span aria-hidden="true">📍</span>
      <span>
        <?= htmlspecialchars($lang === 'es' ? 'Enviando a:' : 'Sending to:') ?>
        <strong><?= htmlspecialchars($destination['venue_name']) ?></strong>
        <?php if (!empty($destination['venue_address'])): ?>
        <span style="color:var(--color-muted)"> &middot; <?= htmlspecialchars($destination['venue_address']) ?></span>
        <?php endif; ?>
      </span>
    </div>
    <?php endif; ?>

    <?php if (empty($products)): ?>
    <div class="text-center" style="padding: 4rem 0; color: var(--color-muted)">
      <p><?= __t('products.custom_note') ?></p>
      <a href="/<?= $lang ?>/order<?= $occasionLabel !== '' ? '?occasion=' . urlencode($occasionLabel) : '' ?>"
         class="btn btn-primary" style="margin-top: 1.5rem">
        <?= htmlspecialchars(__t('shop.customize')) ?>
      </a>
    </div>

    <?php else: ?>
    <div class="product-grid">
      <?php foreach ($products as $product): ?>
      <?php include __DIR__ . '/_bouquet_card.php'; ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- CUSTOM ORDER CTA -->
    <div class="text-center"
         style="margin-top: 4rem; padding: 3rem; background: var(--color-bg-dark); border-radius: 12px">
      <span class="eyebrow label" style="color:var(--color-muted)">
        <?= htmlspecialchars($lang === 'es'
            ? '¿No encuentras lo que buscas?'
            : "Don't see what you're looking for?") ?>
      </span>
      <h3 style="color:var(--color-text-light); margin: 1rem 0">
        <?= __t('products.custom_note') ?>
      </h3>
      <a href="/<?= $lang ?>/order<?= $occasionLabel !== '' ? '?occasion=' . urlencode($occasionLabel) : '' ?>"
         class="btn btn-accent btn-lg" style="margin-top: 0.5rem">
        <?= htmlspecialchars(__t('shop.customize')) ?>
      </a>
    </div>

  </div>
</div>

<?php if (!empty($jsonLd)): ?>
<script type="application/ld+json">
<?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
<?php endif; ?>
