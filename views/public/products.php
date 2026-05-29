<?php

declare(strict_types=1);

use App\Core\Settings;

/**
 * Public products gallery view.
 *
 * Rendered inside views/layouts/public.php. Expects the following variables
 * extracted into scope by BaseController::render():
 *
 *   @var string                $lang        Active locale code — 'en' or 'es'.
 *   @var string                $activeSlug  Current category slug, or 'all'.
 *   @var array<int, array>     $categories  Rows from ProductCategory::allActive().
 *   @var array<int, array>     $products    Product rows to display in the grid.
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

      <a href="/products"
         class="filter-btn <?= $activeSlug === 'all' ? 'active' : '' ?>"
         data-category="all">
        <?= __t('products.filter_all') ?>
      </a>

      <?php foreach ($categories as $cat): ?>
      <a href="/products/<?= htmlspecialchars($cat['slug']) ?>"
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
      <a href="/order" class="btn btn-primary" style="margin-top: 1.5rem">
        <?= htmlspecialchars(Settings::get('order_button_text_' . $lang, Settings::get('order_button_text_en', __t('products.request_cta')))) ?>
      </a>
    </div>

    <?php else: ?>
    <div class="product-grid">

      <?php foreach ($products as $product): ?>
      <div class="product-card"
           data-category="<?= htmlspecialchars($product['category_slug'] ?? '') ?>">

        <!-- Product image -->
        <img class="product-card-img"
             src="<?= $product['image_path']
                 ? htmlspecialchars('/public/uploads/products/' . $product['image_path'])
                 : '/public/assets/images/placeholder-flower.jpg' ?>"
             alt="<?= htmlspecialchars($product['name_' . $lang] ?? $product['name_en']) ?>"
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
            <?= htmlspecialchars($product['name_' . $lang] ?? $product['name_en']) ?>
          </h3>

          <!-- Optional description -->
          <?php
          $desc = $product['description_' . $lang] ?? $product['description_en'] ?? '';
          if ($desc !== '' && $desc !== null):
          ?>
          <p style="color:var(--color-muted); font-size:0.9rem; margin-bottom:0.75rem; line-height:1.5">
            <?= htmlspecialchars($desc) ?>
          </p>
          <?php endif; ?>

          <!-- Pricing — single price or range -->
          <?php if (!empty($product['price_from'])): ?>
          <p class="product-card-price">
            <?php
            $priceFrom = (float) $product['price_from'];
            $priceTo   = !empty($product['price_to']) ? (float) $product['price_to'] : null;
            if ($priceTo !== null && $priceTo !== $priceFrom):
            ?>
              $<?= number_format($priceFrom, 2) ?> &ndash; $<?= number_format($priceTo, 2) ?>
            <?php else: ?>
              <?= __t('products.price_from') ?> $<?= number_format($priceFrom, 2) ?>
            <?php endif; ?>
          </p>
          <?php endif; ?>

          <!-- CTA — links to order form with arrangement pre-filled -->
          <a href="/order?arrangement=<?= urlencode($product['name_en']) ?>"
             class="btn btn-outline"
             style="width:100%; justify-content:center">
            <?= htmlspecialchars(Settings::get('order_button_text_' . $lang, Settings::get('order_button_text_en', __t('products.request_cta')))) ?>
          </a>

        </div><!-- /.product-card-body -->
      </div><!-- /.product-card -->
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
      <a href="/order"
         class="btn btn-accent btn-lg"
         style="margin-top: 0.5rem">
        <?= htmlspecialchars(Settings::get('order_button_text_' . $lang, Settings::get('order_button_text_en', __t('products.request_cta')))) ?>
      </a>
    </div>

  </div><!-- /.container -->
</div><!-- /.section -->
