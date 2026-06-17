<?php

declare(strict_types=1);

/**
 * views/public/_occasion_tiles.php — Shared "Shop by Occasion" tile grid.
 *
 * Included by the occasions hub (views/public/occasions.php) and the homepage
 * (views/public/home.php). Renders each tile as an image + heading card linking
 * to its occasion page.
 *
 * Expected variables in the including scope:
 *   @var array<int, array{slug:string,heading:string,image:string,url:string}> $occasionTiles
 *
 * @see \App\Services\OccasionMenu::tiles()
 */
?>
<div class="product-grid">
  <?php foreach ($occasionTiles as $tile): ?>
  <a href="<?= htmlspecialchars($tile['url']) ?>" class="product-card"
     style="display:block; text-decoration:none; color:inherit">
    <img class="product-card-img" src="<?= htmlspecialchars($tile['image']) ?>"
         alt="<?= htmlspecialchars($tile['heading']) ?>" loading="lazy" width="400" height="300">
    <div class="product-card-body">
      <h3 class="product-card-name" style="margin:0"><?= htmlspecialchars($tile['heading']) ?></h3>
    </div>
  </a>
  <?php endforeach; ?>
</div>
