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
 *   @var array<int, array>        $productColorOptions Per-buyable-product flower-type
 *                                            color options (FlowerColorResolver output), keyed by product id.
 *   @var array<int, array>        $paperColors Active paper-color rows for the panel select.
 *   @var array<int, array>        $addons    Active add-on rows (price + has_custom_text).
 *   @var array|null               $destination Current send-flowers destination, or null.
 *   @var string                   $csrfToken CSRF token for the add-to-cart form.
 *
 * @see \App\Controllers\ShopController
 * @see \App\Support\Shop::isBuyable()
 * @see \App\Support\Shop::occasionCopy()
 * @see \App\Support\Ribbon::ribbonCharLimit()
 */

use App\Support\Ribbon;
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

    <?php if (!empty($destination) && !empty($destination['venue_name'])): ?>
    <!-- Send-flowers destination banner (set from a city-page venue card) -->
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

            <?php if ($buyable):
              $pid           = (int) $product['id'];
              $colorOptions  = $productColorOptions[$pid] ?? [];
              $picturedPaper = isset($product['pictured_paper_color_id']) ? (int) $product['pictured_paper_color_id'] : null;
              $ribbonLimit   = Ribbon::ribbonCharLimit($flowerCount);
              $differsAny    = false;
              foreach ($colorOptions as $_co) {
                  if (!empty($_co['differs_from_photo'])) { $differsAny = true; break; }
              }
            ?>
            <div x-data="{ open: false }">
              <button type="button" class="btn btn-primary"
                      style="width:100%; justify-content:center"
                      @click="open = !open">
                <?= htmlspecialchars(__t('shop.add_to_cart')) ?>
              </button>

              <!-- Add-to-cart panel: per-flower-type color pickers, paper color,
                   add-ons (ribbon reveals a size-limited text box), and qty. -->
              <form method="post" action="/cart/add" x-show="open" x-cloak
                    style="margin-top:0.75rem; text-align:left; border:1px solid rgba(0,0,0,0.1); border-radius:8px; padding:1rem">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="product_id" value="<?= $pid ?>">

                <?php if ($differsAny): ?>
                <p style="font-size:0.8rem; color:var(--color-muted); margin-bottom:0.75rem">
                  <?= htmlspecialchars(__t('shop.colors_may_differ')) ?>
                </p>
                <?php endif; ?>

                <?php foreach ($colorOptions as $opt): ?>
                <?php if (empty($opt['colors'])) { continue; } ?>
                <fieldset style="border:0; padding:0; margin:0 0 0.85rem">
                  <legend style="font-weight:600; font-size:0.85rem; margin-bottom:0.35rem">
                    <?= htmlspecialchars($opt['name_' . $lang] ?? $opt['name_en'] ?? '') ?>
                  </legend>
                  <div style="display:flex; flex-wrap:wrap; gap:0.4rem 0.9rem">
                    <?php foreach ($opt['colors'] as $color): ?>
                    <label style="display:inline-flex; align-items:center; gap:0.35rem; font-size:0.85rem">
                      <input type="checkbox"
                             name="color_ids[<?= (int) $opt['flower_type_id'] ?>][]"
                             value="<?= (int) $color['id'] ?>"
                             <?= ((int) $color['id'] === (int) ($opt['default_color_id'] ?? 0)) ? 'checked' : '' ?>>
                      <?php if (!empty($color['hex'])): ?>
                      <span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:<?= htmlspecialchars($color['hex']) ?>; border:1px solid rgba(0,0,0,0.15)"></span>
                      <?php endif; ?>
                      <?= htmlspecialchars($color['name_' . $lang] ?? $color['name_en'] ?? '') ?>
                    </label>
                    <?php endforeach; ?>
                  </div>
                  <p style="font-size:0.72rem; color:var(--color-muted); margin-top:0.25rem">
                    <?= htmlspecialchars(__t('shop.color_hint')) ?>
                  </p>
                </fieldset>
                <?php endforeach; ?>

                <?php if (!empty($paperColors)): ?>
                <label style="display:block; font-weight:600; font-size:0.85rem; margin-bottom:0.25rem">
                  <?= htmlspecialchars(__t('shop.paper_color')) ?>
                </label>
                <select name="paper_color_id" style="width:100%; margin-bottom:0.85rem; padding:0.4rem">
                  <option value=""><?= htmlspecialchars($lang === 'es' ? '— Ninguno —' : '— None —') ?></option>
                  <?php foreach ($paperColors as $paper): ?>
                  <option value="<?= (int) $paper['id'] ?>" <?= ((int) $paper['id'] === $picturedPaper) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($paper['name_' . $lang] ?? $paper['name_en'] ?? '') ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <?php endif; ?>

                <?php if (!empty($addons)): ?>
                <div style="margin-bottom:0.85rem">
                  <span style="font-weight:600; font-size:0.85rem; display:block; margin-bottom:0.35rem">
                    <?= htmlspecialchars(__t('shop.addons')) ?>
                  </span>
                  <?php foreach ($addons as $addon):
                    $hasText = !empty($addon['has_custom_text']);
                    $aid     = (int) $addon['id'];
                    $aprice  = (float) ($addon['price'] ?? 0);
                  ?>
                  <div x-data="{ checked: false }" style="margin-bottom:0.3rem">
                    <label style="display:inline-flex; align-items:center; gap:0.35rem; font-size:0.85rem">
                      <input type="checkbox" name="addon_ids[]" value="<?= $aid ?>" x-model="checked">
                      <?= htmlspecialchars($addon['name_' . $lang] ?? $addon['name_en'] ?? '') ?>
                      <?php if ($aprice > 0): ?><span style="color:var(--color-muted)">(+$<?= number_format($aprice, 2) ?>)</span><?php endif; ?>
                    </label>
                    <?php if ($hasText): ?>
                    <input type="text"
                           name="addon_text[<?= $aid ?>]"
                           maxlength="<?= $ribbonLimit ?>"
                           x-show="checked" x-cloak
                           placeholder="<?= htmlspecialchars(__t('shop.ribbon_message')) ?> (<?= $ribbonLimit ?>)"
                           style="display:block; width:100%; margin-top:0.3rem; padding:0.35rem">
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <label style="display:block; font-weight:600; font-size:0.85rem; margin-bottom:0.25rem">
                  <?= htmlspecialchars(__t('shop.quantity')) ?>
                </label>
                <input type="number" name="qty" value="1" min="1"
                       style="width:5rem; margin-bottom:0.85rem; padding:0.35rem">

                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center">
                  <?= htmlspecialchars(__t('shop.add_to_cart')) ?>
                </button>
              </form>
            </div>
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
