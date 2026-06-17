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
 *   @var array              $product             Product row (id, name_*, description_*, price_from/to, image_path, flower_count, pictured_paper_color_id).
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
?>
<div class="product-card">

  <img class="product-card-img"
       src="<?= !empty($product['image_path'])
           ? htmlspecialchars('/public/uploads/products/' . $product['image_path'])
           : '/public/assets/images/placeholder-flower.jpg' ?>"
       alt="<?= htmlspecialchars($productName) ?>"
       loading="lazy" width="400" height="300">

  <div class="product-card-body">

    <span class="product-card-category">
      <?= htmlspecialchars($product['category_name_' . $lang] ?? $product['category_name_en'] ?? '') ?>
    </span>

    <h3 class="product-card-name"><?= htmlspecialchars($productName) ?></h3>

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
              $hasQty  = !empty($addon['has_quantity']);
              $aid     = (int) $addon['id'];
              $aprice  = (float) ($addon['price'] ?? 0);
            ?>
            <div x-data="{ checked: false }" style="margin-bottom:0.3rem">
              <label style="display:inline-flex; align-items:center; gap:0.35rem; font-size:0.85rem">
                <input type="checkbox" name="addon_ids[]" value="<?= $aid ?>" x-model="checked">
                <?= htmlspecialchars($addon['name_' . $lang] ?? $addon['name_en'] ?? '') ?>
                <?php if ($aprice > 0): ?><span style="color:var(--color-muted)">(<?= $hasQty
                    ? '$' . number_format($aprice, 2) . ' ' . htmlspecialchars(__t('shop.each'))
                    : '+$' . number_format($aprice, 2) ?>)</span><?php endif; ?>
              </label>
              <?php if ($hasText): ?>
              <input type="text"
                     name="addon_text[<?= $aid ?>]"
                     maxlength="<?= $ribbonLimit ?>"
                     x-show="checked" x-cloak
                     placeholder="<?= htmlspecialchars(__t('shop.ribbon_message')) ?> (<?= $ribbonLimit ?>)"
                     style="display:block; width:100%; margin-top:0.3rem; padding:0.35rem">
              <?php endif; ?>
              <?php if ($hasQty): ?>
              <label x-show="checked" x-cloak style="display:flex; align-items:center; gap:0.4rem; margin-top:0.3rem; font-size:0.8rem; color:var(--color-muted)">
                <?= htmlspecialchars(__t('shop.quantity')) ?>:
                <input type="number" name="addon_qty[<?= $aid ?>]" value="1" min="1" max="99"
                       style="width:4.5rem; padding:0.3rem">
              </label>
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

    </div>
  </div>
</div>
