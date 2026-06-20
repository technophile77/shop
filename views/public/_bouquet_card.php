<?php

declare(strict_types=1);

/**
 * views/public/_bouquet_card.php — One product card with add-to-cart panel.
 *
 * Shared partial included by views/public/occasion.php and the birthday inline
 * grid on views/public/local-area.php so the bouquet card + add-to-cart panel
 * markup lives in exactly one place.
 *
 * Expected variables in the including scope:
 *   @var array              $product             Product row (id, name_*, description_*, price_from/to, image_path, flower_count, pictured_paper_color_id, category_slug).
 *
 * The root .product-card element emits a data-category attribute sourced from
 * $product['category_slug'] (inert where no category filter is present; used by
 * the /products page JS filter to show/hide cards).
 *   @var string             $lang                Active locale ('en'|'es').
 *   @var string             $occasionLabel       Occasion name for the "Customize" /order prefill.
 *   @var array<int, array>  $productColorOptions Per-product flower-type color options keyed by product id.
 *   @var array<int, array>  $paperColors         Active paper-color rows.
 *   @var array<int, array>  $addons              Active add-on rows (price + has_custom_text).
 *   @var string             $csrfToken           CSRF token for the add-to-cart form.
 *
 * @see \App\Support\Shop::isBuyable()
 * @see \App\Support\Ribbon::ribbonCharLimit()
 */

$buyable       = \App\Support\Shop::isBuyable($product);
$productName   = $product['name_' . $lang] ?? $product['name_en'] ?? '';
$productNameEn = $product['name_en'] ?? '';
$desc          = $product['description_' . $lang] ?? $product['description_en'] ?? '';
$priceFrom     = !empty($product['price_from']) ? (float) $product['price_from'] : null;
$priceTo       = !empty($product['price_to'])   ? (float) $product['price_to']   : null;
$flowerCount   = (int) ($product['flower_count'] ?? 0);
$customizeUrl  = '/' . $lang . '/order'
    . '?product='  . urlencode($productNameEn)
    . '&occasion=' . urlencode((string) ($occasionLabel ?? ''));
$detailUrl     = '/' . $lang . '/flowers/' . \App\Support\Slug::productRef($product);
?>
<div class="product-card" data-category="<?= htmlspecialchars($product['category_slug'] ?? '') ?>">

  <a href="<?= htmlspecialchars($detailUrl) ?>">
    <img class="product-card-img"
         src="<?= !empty($product['image_path'])
             ? htmlspecialchars('/public/uploads/products/' . $product['image_path'])
             : '/public/assets/images/placeholder-flower.jpg' ?>"
         alt="<?= htmlspecialchars($productName) ?>"
         loading="lazy" width="400" height="300">
  </a>

  <div class="product-card-body">

    <span class="product-card-category">
      <?= htmlspecialchars($product['category_name_' . $lang] ?? $product['category_name_en'] ?? '') ?>
    </span>

    <h3 class="product-card-name">
      <a href="<?= htmlspecialchars($detailUrl) ?>" style="color:inherit; text-decoration:none"><?= htmlspecialchars($productName) ?></a>
    </h3>

    <?php if ($desc !== '' && $desc !== null): ?>
    <p style="color:var(--color-muted); font-size:0.9rem; margin-bottom:0.75rem; line-height:1.5">
      <?= htmlspecialchars($desc) ?>
    </p>
    <?php endif; ?>

    <?php if ($priceFrom !== null): ?>
    <p class="product-card-price">
      <?php if ($priceTo !== null && $priceTo !== $priceFrom): ?>
        $<?= number_format($priceFrom, 2) ?> &ndash; $<?= number_format($priceTo, 2) ?>
      <?php else: ?>
        <?= __t('products.price_from') ?> $<?= number_format($priceFrom, 2) ?>
      <?php endif; ?>
    </p>
    <?php endif; ?>

    <div style="display:flex; flex-direction:column; gap:0.5rem; margin-top:auto">

      <?php if ($buyable):
        $pid           = (int) $product['id'];
        $colorOptions  = $productColorOptions[$pid] ?? [];
        $picturedPaper = isset($product['pictured_paper_color_id']) ? (int) $product['pictured_paper_color_id'] : null;
        $ribbonLimit   = \App\Support\Ribbon::ribbonCharLimit($flowerCount);
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

        <?php $formExtraAttrs = 'x-show="open" x-cloak'; include __DIR__ . '/_addtocart_form.php'; ?>
      </div>
      <?php endif; ?>

      <a href="<?= htmlspecialchars($customizeUrl) ?>"
         class="btn btn-outline"
         style="width:100%; justify-content:center">
        <?= htmlspecialchars(__t('shop.customize')) ?>
      </a>

    </div>
  </div>
</div>
