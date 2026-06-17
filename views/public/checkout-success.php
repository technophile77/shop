<?php

declare(strict_types=1);

/**
 * views/public/checkout-success.php — Post-payment thank-you page.
 *
 * Rendered by CheckoutController::success() once Stripe confirms payment.
 * Shows a confirmation, the order number, and the amount paid.
 *
 * Variables injected by BaseController::render():
 *   @var array<string, mixed> $order The paid order row.
 *   @var string               $lang  Active locale.
 *
 * @see \App\Controllers\CheckoutController::success()
 */

$paid = ($order['payment_status'] ?? '') === 'paid';
?>

<div class="section section--light" style="padding: 4rem 0">
  <div class="container text-center" style="max-width:600px">
    <div style="font-size:3rem; margin-bottom:1rem">🌸</div>
    <h1 style="margin-bottom:0.75rem"><?= htmlspecialchars(__t('checkout.success_heading')) ?></h1>
    <p style="color:var(--color-muted); margin-bottom:1.5rem">
      <?= htmlspecialchars(__t('checkout.success_message')) ?>
    </p>

    <div class="admin-card" style="max-width:420px; margin:0 auto 2rem; text-align:left">
      <div style="display:flex; justify-content:space-between; margin-bottom:0.4rem">
        <span style="color:var(--color-muted)"><?= htmlspecialchars($lang === 'es' ? 'Pedido' : 'Order') ?></span>
        <span>#<?= (int) ($order['id'] ?? 0) ?></span>
      </div>
      <?php if ($paid): ?>
      <div style="display:flex; justify-content:space-between; font-weight:700">
        <span><?= htmlspecialchars(__t('checkout.total')) ?></span>
        <span>$<?= number_format((float) ($order['total'] ?? 0), 2) ?></span>
      </div>
      <?php endif; ?>
    </div>

    <a href="/<?= $lang ?>/products" class="btn btn-primary">
      <?= htmlspecialchars(__t('home.view_all')) ?>
    </a>
  </div>
</div>
