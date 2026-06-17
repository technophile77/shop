<?php

declare(strict_types=1);

/**
 * views/public/cart.php — Shopping cart page.
 *
 * Rendered inside views/layouts/public.php via CartController::view(). Lists
 * each configured bouquet line with its colors, paper, add-ons, and per-line
 * total; shows the cart subtotal and the current send-flowers destination; and
 * links to checkout. Quantity update and line removal post to /cart/update and
 * /cart/remove.
 *
 * Variables injected by BaseController::render():
 *   @var array<int, array> $items            Canonical cart line items.
 *   @var array<string, float> $lineTotals     Per-line totals keyed by signature.
 *   @var float             $subtotal          Cart merchandise subtotal.
 *   @var array|null        $destination       Send-flowers destination, or null.
 *   @var string            $lang              Active locale — 'en' or 'es'.
 *   @var string            $csrfToken         CSRF token for update/remove forms.
 *   @var array<int,string> $flowerTypeNames   id => localised flower-type name.
 *   @var array<int,string> $flowerColorNames  id => localised flower-color name.
 *   @var array<int,string> $paperColorNames   id => localised paper-color name.
 *
 * @see \App\Controllers\CartController
 * @see \App\Support\CartPricing
 */
?>

<div class="section section--dark" style="padding: 3rem 0 2rem">
  <div class="container text-center">
    <h1 style="color:var(--color-text-light)"><?= htmlspecialchars(__t('cart.heading')) ?></h1>
  </div>
</div>

<div class="section" style="padding-top: 2.5rem">
  <div class="container">

    <?php if (empty($items)): ?>
    <!-- Empty cart -->
    <div class="text-center" style="padding: 4rem 0; color: var(--color-muted)">
      <p><?= htmlspecialchars(__t('cart.empty')) ?></p>
      <a href="/<?= $lang ?>/products" class="btn btn-primary" style="margin-top:1.5rem">
        <?= htmlspecialchars(__t('nav.products')) ?>
      </a>
    </div>

    <?php else: ?>

    <?php if (!empty($destination) && !empty($destination['venue_name'])): ?>
    <div style="background:var(--color-bg-dark); border-radius:10px; padding:1rem 1.25rem; margin-bottom:2rem; color:var(--color-text-light)">
      📍 <?= htmlspecialchars($lang === 'es' ? 'Enviando a:' : 'Sending to:') ?>
      <strong><?= htmlspecialchars($destination['venue_name']) ?></strong>
      <?php if (!empty($destination['venue_address'])): ?>
      <span style="color:var(--color-muted)"> &middot; <?= htmlspecialchars($destination['venue_address']) ?></span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="display:flex; flex-direction:column; gap:1rem">
      <?php foreach ($items as $item): ?>
      <?php
        $sig       = (string) ($item['signature'] ?? '');
        $name      = $item['name_' . $lang] ?? $item['name_en'] ?? '';
        $lineTotal = $lineTotals[$sig] ?? 0.0;
        $imgSrc    = !empty($item['image_path'])
            ? '/public/uploads/products/' . $item['image_path']
            : '/public/assets/images/placeholder-flower.jpg';
      ?>
      <div style="display:flex; gap:1rem; align-items:flex-start; border:1px solid rgba(0,0,0,0.08); border-radius:10px; padding:1rem">

        <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($name) ?>"
             width="90" height="90" loading="lazy"
             style="border-radius:8px; object-fit:cover; flex:0 0 auto">

        <div style="flex:1 1 auto">
          <h3 style="margin:0 0 0.25rem; font-size:1.05rem"><?= htmlspecialchars($name) ?></h3>

          <!-- Colors per flower type -->
          <?php foreach ((array) ($item['colors'] ?? []) as $c): ?>
          <?php
            $typeName  = $flowerTypeNames[(int) ($c['flower_type_id'] ?? 0)] ?? '';
            $colorList = [];
            foreach ((array) ($c['color_ids'] ?? []) as $cid) {
                $colorList[] = $flowerColorNames[(int) $cid] ?? '';
            }
            $colorList = array_filter($colorList);
          ?>
          <?php if ($typeName !== '' && $colorList !== []): ?>
          <p style="margin:0; font-size:0.82rem; color:var(--color-muted)">
            <?= htmlspecialchars($typeName) ?>:
            <?= htmlspecialchars(implode(', ', $colorList)) ?><?php if (!empty($c['mixed'])): ?> <?= htmlspecialchars(__t('shop.mixed')) ?><?php endif; ?>
          </p>
          <?php endif; ?>
          <?php endforeach; ?>

          <!-- Paper color -->
          <?php if (!empty($item['paper_color_id']) && isset($paperColorNames[(int) $item['paper_color_id']])): ?>
          <p style="margin:0; font-size:0.82rem; color:var(--color-muted)">
            <?= htmlspecialchars(__t('shop.paper_color')) ?>: <?= htmlspecialchars($paperColorNames[(int) $item['paper_color_id']]) ?>
          </p>
          <?php endif; ?>

          <!-- Add-ons -->
          <?php foreach ((array) ($item['addons'] ?? []) as $a):
            $aQty   = max(1, (int) ($a['quantity'] ?? 1));
            $aPrice = (float) ($a['price'] ?? 0);
          ?>
          <p style="margin:0; font-size:0.82rem; color:var(--color-muted)">
            + <?= htmlspecialchars($a['name_' . $lang] ?? $a['name_en'] ?? '') ?><?php
              if ($aQty > 1): ?> &times; <?= $aQty ?><?php endif;
              if (!empty($a['custom_text'])): ?> — &ldquo;<?= htmlspecialchars((string) $a['custom_text']) ?>&rdquo;<?php endif;
              if ($aPrice > 0): ?> (+$<?= number_format($aPrice * $aQty, 2) ?>)<?php endif; ?>
          </p>
          <?php endforeach; ?>

          <!-- Quantity update + remove -->
          <div style="display:flex; gap:0.75rem; align-items:center; margin-top:0.6rem">
            <form method="post" action="/cart/update" style="display:flex; gap:0.4rem; align-items:center">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
              <input type="hidden" name="signature" value="<?= htmlspecialchars($sig) ?>">
              <label style="font-size:0.82rem; color:var(--color-muted)"><?= htmlspecialchars(__t('shop.quantity')) ?></label>
              <input type="number" name="qty" value="<?= (int) ($item['qty'] ?? 1) ?>" min="0"
                     style="width:4rem; padding:0.3rem">
              <button type="submit" class="btn btn-outline" style="padding:0.3rem 0.7rem; font-size:0.8rem">
                <?= htmlspecialchars(__t('cart.update')) ?>
              </button>
            </form>
            <form method="post" action="/cart/remove">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
              <input type="hidden" name="signature" value="<?= htmlspecialchars($sig) ?>">
              <button type="submit" class="btn btn-outline" style="padding:0.3rem 0.7rem; font-size:0.8rem">
                <?= htmlspecialchars(__t('cart.remove')) ?>
              </button>
            </form>
          </div>
        </div>

        <div style="flex:0 0 auto; font-weight:600; white-space:nowrap">
          $<?= number_format((float) $lineTotal, 2) ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Subtotal + checkout -->
    <div style="margin-top:2rem; display:flex; flex-direction:column; align-items:flex-end; gap:1rem">
      <p style="font-size:1.15rem; margin:0">
        <?= htmlspecialchars(__t('cart.subtotal')) ?>:
        <strong>$<?= number_format((float) $subtotal, 2) ?></strong>
      </p>
      <p style="font-size:0.82rem; color:var(--color-muted); margin:0">
        <?= htmlspecialchars(__t('cart.totals_note')) ?>
      </p>
      <a href="/<?= $lang ?>/checkout" class="btn btn-accent btn-lg">
        <?= htmlspecialchars(__t('cart.checkout')) ?>
      </a>
    </div>

    <?php endif; ?>

  </div>
</div>
