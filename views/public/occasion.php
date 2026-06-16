<?php

declare(strict_types=1);

/**
 * views/public/occasion.php — Occasion-filtered product grid page.
 *
 * Rendered inside views/layouts/public.php via ShopController::occasion().
 * Displays a heading and blurb for the occasion, then a product grid where
 * each card has per-product CTAs:
 *
 *  - "Add to Cart" button (buyable products only; price_from > 0).
 *    <!-- Phase 3 --> wires the actual add-to-cart panel and POST endpoint.
 *    The button carries data attributes for that wiring.
 *  - "Customize this bouquet" link (all products) → /order prefilled with
 *    the product name (?product=) and occasion (?occasion=).
 *
 * Variables injected by BaseController::render() via ShopController::occasion():
 *
 *   @var array<string, mixed>     $occasion  The occasion row from Occasion::findBySlug().
 *   @var array<int, array>        $products  Product rows from Product::byOccasion().
 *   @var array{heading:string, blurb:string} $copy Localised heading + blurb from Shop::occasionCopy().
 *   @var string                   $lang      Active locale code — 'en' or 'es'.
 *   @var string                   $slug      The occasion URL slug.
 *   @var array                    $jsonLd    JSON-LD ItemList for structured data.
 *
 * @see \App\Controllers\ShopController
 * @see \App\Support\Shop::isBuyable()
 * @see \App\Support\Shop::occasionCopy()
 */

use App\Support\Shop;

// Occasion label used to prefill the order form occasion field.
$occasionLabel = $occasion['name_en'] ?? '';
?>

<!-- ============================================================
     PAGE HEADER — dark strip with occasion heading
     ============================================================ -->
<div class="section section--dark" style="padding: 3rem 0 2rem">
  <div class="container text-center">
    <span class="eyebrow label" style="color:var(--color-muted)">
      <?= htmlspecialchars($lang === 'es' ? 'Ocasión' : 'Occasion') ?>
    </span>
    <h1 style="color:var(--color-text-light)">
      <?= htmlspecialchars($copy['heading']) ?>
    </h1>
    <?php if (!empty($copy['blurb'])): ?>
    <p style="color:rgba(255,255,255,0.75); max-width:600px; margin: 1rem auto 0; line-height:1.65">
      <?= htmlspecialchars($copy['blurb']) ?>
    </p>
    <?php endif; ?>
  </div>
</div>

<!-- ============================================================
     PRODUCT GRID
     ============================================================ -->
<div class="section" style="padding-top: 2.5rem">
  <div class="container">

    <?php if (empty($products)): ?>
    <!-- Empty state — no products tagged with this occasion yet -->
    <div class="text-center" style="padding: 4rem 0; color: var(--color-muted)">
      <p><?= __t('products.custom_note') ?></p>
      <a href="/<?= $lang ?>/order<?= $occasionLabel !== '' ? '?occasion=' . urlencode($occasionLabel) : '' ?>"
         class="btn btn-primary"
         style="margin-top: 1.5rem">
        <?= htmlspecialchars(__t('shop.customize')) ?>
      </a>
    </div>

    <?php else: ?>
    <div class="product-grid">

      <?php foreach ($products as $product): ?>
      <?php
      $buyable      = Shop::isBuyable($product);
      $productName  = $product['name_' . $lang] ?? $product['name_en'] ?? '';
      $productNameEn = $product['name_en'] ?? '';
      $desc         = $product['description_' . $lang] ?? $product['description_en'] ?? '';
      $priceFrom    = !empty($product['price_from']) ? (float) $product['price_from'] : null;
      $priceTo      = !empty($product['price_to'])   ? (float) $product['price_to']   : null;
      $flowerCount  = (int) ($product['flower_count'] ?? 0);
      $customizeUrl = '/' . $lang . '/order'
          . '?product='   . urlencode($productNameEn)
          . '&occasion='  . urlencode($occasionLabel);
      ?>
      <div class="product-card">

        <!-- Product image -->
        <img class="product-card-img"
             src="<?= !empty($product['image_path'])
                 ? htmlspecialchars('/public/uploads/products/' . $product['image_path'])
                 : '/public/assets/images/placeholder-flower.jpg' ?>"
             alt="<?= htmlspecialchars($productName) ?>"
             loading="lazy"
             width="400"
             height="300">

        <div class="product-card-body">

          <!-- Category eyebrow -->
          <span class="product-card-category">
            <?= htmlspecialchars($product['category_name_' . $lang] ?? $product['category_name_en'] ?? '') ?>
          </span>

          <!-- Product name -->
          <h3 class="product-card-name">
            <?= htmlspecialchars($productName) ?>
          </h3>

          <!-- Optional description -->
          <?php if ($desc !== '' && $desc !== null): ?>
          <p style="color:var(--color-muted); font-size:0.9rem; margin-bottom:0.75rem; line-height:1.5">
            <?= htmlspecialchars($desc) ?>
          </p>
          <?php endif; ?>

          <!-- Pricing — single price or range -->
          <?php if ($priceFrom !== null): ?>
          <p class="product-card-price">
            <?php if ($priceTo !== null && $priceTo !== $priceFrom): ?>
              $<?= number_format($priceFrom, 2) ?> &ndash; $<?= number_format($priceTo, 2) ?>
            <?php else: ?>
              <?= __t('products.price_from') ?> $<?= number_format($priceFrom, 2) ?>
            <?php endif; ?>
          </p>
          <?php endif; ?>

          <!-- CTAs -->
          <div style="display:flex; flex-direction:column; gap:0.5rem; margin-top:auto">

            <?php if ($buyable): ?>
            <!--
              Phase 3: wire this button to the add-to-cart panel + POST endpoint.
              The data attributes carry everything Phase 3 needs:
                data-product-id   — product primary key for the cart POST body.
                data-price        — unit price in USD (price_from).
                data-flower-count — stem count for display in the cart panel.
            -->
            <button type="button"
                    class="btn btn-primary"
                    style="width:100%; justify-content:center"
                    data-product-id="<?= (int) $product['id'] ?>"
                    data-price="<?= number_format((float) $product['price_from'], 2) ?>"
                    data-flower-count="<?= $flowerCount ?>">
              <?= htmlspecialchars(__t('shop.add_to_cart')) ?>
            </button>
            <?php endif; ?>

            <a href="<?= htmlspecialchars($customizeUrl) ?>"
               class="btn btn-outline"
               style="width:100%; justify-content:center">
              <?= htmlspecialchars(__t('shop.customize')) ?>
            </a>

          </div><!-- /.ctas -->

        </div><!-- /.product-card-body -->
      </div><!-- /.product-card -->
      <?php endforeach; ?>

    </div><!-- /.product-grid -->
    <?php endif; ?>

    <!-- ============================================================
         CUSTOM ORDER CTA — bottom of page
         ============================================================ -->
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
         class="btn btn-accent btn-lg"
         style="margin-top: 0.5rem">
        <?= htmlspecialchars(__t('shop.customize')) ?>
      </a>
    </div>

  </div><!-- /.container -->
</div><!-- /.section -->

<?php if (!empty($jsonLd)): ?>
<script type="application/ld+json">
<?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
<?php endif; ?>
