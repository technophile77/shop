<?php

declare(strict_types=1);

use App\Core\Settings;

/**
 * Public products gallery view.
 *
 * Rendered inside views/layouts/public.php. Expects the following variables
 * extracted into scope by BaseController::render():
 *
 *   @var string                $lang                Active locale code — 'en' or 'es'.
 *   @var string                $activeSlug          Current category slug, or 'all'.
 *   @var array<int, array>     $categories          Rows from ProductCategory::allActive().
 *   @var array<int, array>     $products            Product rows to display in the grid.
 *   @var array<int, array>     $productColorOptions Per-product flower-type color options keyed by product id.
 *   @var array<int, array>     $paperColors         Active paper-color rows.
 *   @var array<int, array>     $addons              Active add-on rows (price + has_custom_text).
 *   @var string                $csrfToken           CSRF token for the add-to-cart form.
 *
 * @see \App\Controllers\ProductController
 * @see \App\Models\Product
 * @see \App\Models\ProductCategory
 */
?>

<!-- ============================================================
     PAGE HEADER — dark strip with eyebrow + h1
     ============================================================ -->
<div class="section section--dark" style="padding: 3rem 0 2rem">
  <div class="container text-center">
    <span class="eyebrow label" style="color:var(--color-muted)">
      <?= htmlspecialchars(Settings::get('products_eyebrow_' . $lang, Settings::get('products_eyebrow_en', ''))) ?>
    </span>
    <h1 style="color:var(--color-text-light)">
      <?= htmlspecialchars(Settings::get('products_page_title_' . $lang, Settings::get('products_page_title_en', __t('products.title')))) ?>
    </h1>
  </div>
</div>

<!-- ============================================================
     CATEGORY FILTER BAR
     <a> tags give server-side filtering without requiring JS.
     JS in main.js also listens to these clicks for instant
     client-side show/hide by data-category on the /products page.
     ============================================================ -->
<div class="section" style="padding-top: 3rem; padding-bottom: 1rem">
  <div class="container">
    <div class="filter-bar">

      <a href="/<?= $lang ?>/products"
         class="filter-btn <?= $activeSlug === 'all' ? 'active' : '' ?>"
         data-category="all">
        <?= __t('products.filter_all') ?>
      </a>

      <?php foreach ($categories as $cat): ?>
      <a href="/<?= $lang ?>/products/<?= htmlspecialchars($cat['slug']) ?>"
         class="filter-btn <?= $activeSlug === $cat['slug'] ? 'active' : '' ?>"
         data-category="<?= htmlspecialchars($cat['slug']) ?>">
        <?= htmlspecialchars($cat['name_' . $lang] ?? $cat['name_en']) ?>
      </a>
      <?php endforeach; ?>

    </div>
  </div>
</div>

<!-- ============================================================
     PRODUCT GRID
     ============================================================ -->
<div class="section" style="padding-top: 1rem">
  <div class="container">

    <?php if (empty($products)): ?>
    <!-- Empty state — no products in this category yet -->
    <div class="text-center" style="padding: 4rem 0; color: var(--color-muted)">
      <p><?= __t('products.custom_note') ?></p>
      <a href="/<?= $lang ?>/order" class="btn btn-primary" style="margin-top: 1.5rem">
        <?= htmlspecialchars(Settings::get('order_button_text_' . $lang, Settings::get('order_button_text_en', __t('products.request_cta')))) ?>
      </a>
    </div>

    <?php else: ?>
    <div class="product-grid">

      <?php $occasionLabel = ''; ?>
      <?php foreach ($products as $product): ?>
      <?php include __DIR__ . '/_bouquet_card.php'; ?>
      <?php endforeach; ?>

    </div><!-- /.product-grid -->
    <?php endif; ?>

    <!-- ============================================================
         CUSTOM ORDER CTA — bottom of page
         Shown regardless of whether products exist so visitors always
         have a path to a custom arrangement request.
         ============================================================ -->
    <div class="text-center"
         style="margin-top: 4rem; padding: 3rem; background: var(--color-bg-dark); border-radius: 12px">
      <span class="eyebrow label" style="color:var(--color-muted)">
        <?= htmlspecialchars(Settings::get('products_cta_eyebrow_' . $lang, Settings::get('products_cta_eyebrow_en', "Don't see what you're looking for?"))) ?>
      </span>
      <h3 style="color:var(--color-text-light); margin: 1rem 0">
        <?= __t('products.custom_note') ?>
      </h3>
      <a href="/<?= $lang ?>/order"
         class="btn btn-accent btn-lg"
         style="margin-top: 0.5rem">
        <?= htmlspecialchars(Settings::get('order_button_text_' . $lang, Settings::get('order_button_text_en', __t('products.request_cta')))) ?>
      </a>
    </div>

  </div><!-- /.container -->
</div><!-- /.section -->

<?php if (!empty($jsonLd)): ?>
<script type="application/ld+json">
<?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
<?php endif; ?>
