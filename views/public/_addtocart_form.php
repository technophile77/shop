<?php

declare(strict_types=1);

/**
 * views/public/_addtocart_form.php — The add-to-cart <form> (colors, paper,
 * add-ons, ribbon, quantity), shared by the bouquet card and the product
 * detail page so the cart markup lives in one place.
 *
 * Expected variables in the including scope:
 *   @var int                $pid            Product id.
 *   @var array<int, array>  $colorOptions   FlowerColorResolver output for this product.
 *   @var int|null           $picturedPaper  Pictured paper color id, or null (no paper).
 *   @var int                $ribbonLimit    Ribbon char limit for this product.
 *   @var bool               $differsAny     True when any flower type's default differs from the photo.
 *   @var array<int, array>  $paperColors    Active paper-color rows.
 *   @var array<int, array>  $addons         Active add-on rows.
 *   @var string             $csrfToken      CSRF token.
 *   @var string             $lang           Active locale ('en'|'es').
 *   @var string             $formExtraAttrs Optional extra attributes on the <form> tag
 *                                           (e.g. 'x-show="open" x-cloak'); defaults to ''.
 *
 * @see views/public/_bouquet_card.php
 * @see views/public/product.php
 */
?>
<form method="post" action="/cart/add" <?= $formExtraAttrs ?? '' ?>
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
               <?= in_array((int) $color['id'], array_map('intval', (array) ($opt['default_color_ids'] ?? [])), true) ? 'checked' : '' ?>>
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

  <?php /* Paper picker only when the arrangement has paper (pictured paper set);
           products with no paper — vase or shape — omit it entirely. */ ?>
  <?php if (!empty($paperColors) && $picturedPaper !== null): ?>
  <label style="display:block; font-weight:600; font-size:0.85rem; margin-bottom:0.25rem">
    <?= htmlspecialchars(__t('shop.paper_color')) ?>
  </label>
  <select name="paper_color_id" style="width:100%; margin-bottom:0.85rem; padding:0.4rem">
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
