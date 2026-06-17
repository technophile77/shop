<?php

declare(strict_types=1);

/**
 * views/public/product.php — Individual bouquet (product) detail page.
 *
 * Rendered inside views/layouts/public.php via ShopController::product().
 * Shows a breadcrumb, large image, name, price, description, and the
 * add-to-cart panel (buyable products) or just "Customize this bouquet".
 * Product + BreadcrumbList JSON-LD is emitted at the bottom.
 *
 * Variables injected by BaseController::render():
 *   @var array              $product      Active product row (+ category fields).
 *   @var string             $lang         Active locale ('en'|'es').
 *   @var bool               $buyable      Whether the product has a positive price_from.
 *   @var array<int, array>  $colorOptions FlowerColorResolver output (buyable only).
 *   @var array<int, array>  $paperColors  Active paper-color rows.
 *   @var array<int, array>  $addons       Active add-on rows.
 *   @var string             $csrfToken    CSRF token for the add-to-cart form.
 *   @var array<int, array{name:string,url:string}> $crumbs Breadcrumb trail.
 *   @var array               $jsonLd      Product + BreadcrumbList @graph.
 *
 * @see \App\Controllers\ShopController::product()
 * @see views/public/_addtocart_form.php
 */

use App\Support\Ribbon;

$name      = $product['name_' . $lang] ?? $product['name_en'] ?? '';
$desc      = $product['description_' . $lang] ?? $product['description_en'] ?? '';
$priceFrom = !empty($product['price_from']) ? (float) $product['price_from'] : null;
$priceTo   = !empty($product['price_to'])   ? (float) $product['price_to']   : null;
$image     = !empty($product['image_path'])
    ? '/public/uploads/products/' . $product['image_path']
    : '/public/assets/images/placeholder-flower.jpg';
$category     = $product['category_name_' . $lang] ?? $product['category_name_en'] ?? '';
$customizeUrl = '/' . $lang . '/order?product=' . urlencode((string) ($product['name_en'] ?? ''));
?>

<!-- BREADCRUMB -->
<div class="section section--light" style="padding:1.5rem 0 0">
  <div class="container">
    <nav aria-label="Breadcrumb" style="font-size:0.85rem; color:var(--color-muted)">
      <?php $last = count($crumbs) - 1; foreach ($crumbs as $i => $c): ?>
        <?php if ($i > 0): ?><span style="margin:0 0.4rem">&rsaquo;</span><?php endif; ?>
        <?php if ($i < $last): ?>
        <a href="<?= htmlspecialchars((string) preg_replace('#^https?://[^/]+#', '', $c['url'])) ?>" style="color:var(--color-primary)"><?= htmlspecialchars($c['name']) ?></a>
        <?php else: ?>
        <span style="color:var(--color-text-dark)"><?= htmlspecialchars($c['name']) ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  </div>
</div>

<!-- DETAIL -->
<section class="section section--light" style="padding-top:1.5rem">
  <div class="container">
    <div class="grid-2" style="gap:2.5rem; align-items:start">

      <div>
        <img src="<?= htmlspecialchars($image) ?>" alt="<?= htmlspecialchars($name) ?>"
             width="600" height="450"
             style="width:100%; border-radius:12px; object-fit:cover">
      </div>

      <div>
        <?php if ($category !== ''): ?>
        <span class="eyebrow label" style="color:var(--color-muted)"><?= htmlspecialchars($category) ?></span>
        <?php endif; ?>

        <h1 style="margin:0.25rem 0 0.75rem"><?= htmlspecialchars($name) ?></h1>

        <?php if ($priceFrom !== null): ?>
        <p class="product-card-price" style="font-size:1.25rem; margin-bottom:1rem">
          <?php if ($priceTo !== null && $priceTo !== $priceFrom): ?>
            $<?= number_format($priceFrom, 2) ?> &ndash; $<?= number_format($priceTo, 2) ?>
          <?php else: ?>
            <?= __t('products.price_from') ?> $<?= number_format($priceFrom, 2) ?>
          <?php endif; ?>
        </p>
        <?php endif; ?>

        <?php if ($desc !== '' && $desc !== null): ?>
        <p style="line-height:1.7; color:var(--color-text-dark); margin-bottom:1.5rem"><?= nl2br(htmlspecialchars($desc)) ?></p>
        <?php endif; ?>

        <?php if ($buyable):
          $pid           = (int) $product['id'];
          $picturedPaper = isset($product['pictured_paper_color_id']) ? (int) $product['pictured_paper_color_id'] : null;
          $flowerCount   = (int) ($product['flower_count'] ?? 0);
          $ribbonLimit   = Ribbon::ribbonCharLimit($flowerCount);
          $differsAny    = false;
          foreach ($colorOptions as $_co) {
              if (!empty($_co['differs_from_photo'])) { $differsAny = true; break; }
          }
          include __DIR__ . '/_addtocart_form.php';
        endif; ?>

        <a href="<?= htmlspecialchars($customizeUrl) ?>"
           class="btn btn-outline"
           style="<?= $buyable ? 'margin-top:0.75rem; ' : '' ?>width:100%; justify-content:center">
          <?= htmlspecialchars(__t('shop.customize')) ?>
        </a>
      </div>

    </div>
  </div>
</section>

<?php if (!empty($jsonLd)): ?>
<script type="application/ld+json">
<?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
<?php endif; ?>
