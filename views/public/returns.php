<?php
/**
 * views/public/returns.php — Return & Refund Policy page.
 *
 * Rendered inside views/layouts/public.php. Outputs only the page body content.
 * Provides the bilingual returns/refund policy required for Google Merchant
 * Center compliance. All contact details are pulled from config so they never
 * drift from the live business information.
 *
 * Variables injected by BaseController::render() via ReturnPolicyController::index():
 *   string $lang      Current locale ('en' or 'es').
 *   string $pageTitle Localised page title from site settings.
 *
 * @see \App\Controllers\ReturnPolicyController::index()
 */

use App\Core\Config;

$phone     = (string) Config::get('BUSINESS_PHONE', '');
$telDigits = preg_replace('/\D/', '', $phone);
$waPhone   = (string) Config::get('WHATSAPP_PHONE', '');
$email     = (string) Config::get('BUSINESS_EMAIL', '');

$heading = $lang === 'es'
    ? 'Política de Devoluciones y Reembolsos'
    : 'Return & Refund Policy';
?>

<!-- ============================================================
     HERO HEADER — dark strip
     ============================================================ -->
<div class="section section--dark" style="padding: 3rem 0 2.5rem">
    <div class="container text-center">
        <span class="eyebrow label" style="color:var(--color-muted)">
            <?= htmlspecialchars(Config::get('BUSINESS_NAME', '')) ?>
        </span>
        <h1 style="color:var(--color-text-light)">
            <?= htmlspecialchars($heading) ?>
        </h1>
    </div>
</div>

<!-- ============================================================
     POLICY CONTENT — light
     ============================================================ -->
<section class="section section--light">
    <div class="container">
        <div style="max-width:720px; margin:0 auto; font-size:1.05rem; line-height:1.85; color:var(--color-text-dark)">

            <?php if ($lang === 'es'): ?>

                <p>Debido a que nuestras flores son frescas, hechas a mano y a menudo por encargo, todas las ventas son finales y no podemos aceptar devoluciones ni cambios.</p>

                <p>Tu satisfacción es importante para nosotros. Si tu pedido llega dañado, incorrecto o no se entrega, contáctanos dentro de las 48 horas posteriores a la entrega (incluye una foto si está dañado) y reemplazaremos el arreglo o emitiremos un reembolso a nuestra discreción.</p>

                <p>Los pedidos personalizados no son reembolsables una vez que ha comenzado su elaboración.</p>

                <p style="margin-top:2rem; padding-top:1.5rem; border-top:1px solid var(--color-border)">
                    Para reportar un problema, contáctanos:
                </p>

            <?php else: ?>

                <p>Because our flowers are fresh, handcrafted, and often made to order, all sales are final and we cannot accept returns or exchanges.</p>

                <p>Your satisfaction matters to us. If your order arrives damaged, incorrect, or is not delivered, please contact us within 48 hours of delivery (include a photo for damaged items) and we will replace the arrangement or issue a refund at our discretion.</p>

                <p>Custom and personalized orders are non-refundable once production has begun.</p>

                <p style="margin-top:2rem; padding-top:1.5rem; border-top:1px solid var(--color-border)">
                    To report an issue, contact us:
                </p>

            <?php endif; ?>

            <ul style="list-style:none; padding:0; margin:0 0 2rem; line-height:2">
                <?php if ($phone !== ''): ?>
                <li>
                    <a href="tel:<?= htmlspecialchars($telDigits) ?>" style="color:var(--color-primary)">
                        <?= htmlspecialchars($phone) ?>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($waPhone !== ''): ?>
                <li>
                    <a href="https://wa.me/<?= htmlspecialchars($waPhone) ?>"
                       target="_blank" rel="noopener noreferrer"
                       style="color:var(--color-primary)">
                        WhatsApp
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($email !== ''): ?>
                <li>
                    <a href="mailto:<?= htmlspecialchars($email) ?>" style="color:var(--color-primary)">
                        <?= htmlspecialchars($email) ?>
                    </a>
                </li>
                <?php endif; ?>
            </ul>

            <p style="color:var(--color-muted); font-size:0.85rem; margin-bottom:0">
                <?= $lang === 'es' ? 'Última actualización: ' : 'Last updated: ' ?><?= date('F Y') ?>
            </p>

        </div>
    </div>
</section>
