<?php
/**
 * views/public/terms.php — Terms & Conditions page.
 *
 * Rendered inside views/layouts/public.php. Outputs only the page body content.
 * Drafted to satisfy the Terms & Conditions URL required by Twilio's A2P
 * 10DLC campaign registration. This is a starting draft, not vetted legal
 * advice — review and adjust before relying on it. All contact details are
 * pulled from config so they never drift from the live business information.
 *
 * Variables injected by BaseController::render() via TermsController::index():
 *   string $lang      Current locale ('en' or 'es').
 *   string $pageTitle Localised page title from site settings.
 *
 * @see \App\Controllers\TermsController::index()
 */

use App\Core\Config;

$phone     = (string) Config::get('BUSINESS_PHONE', '');
$telDigits = preg_replace('/\D/', '', $phone);
$email     = (string) Config::get('BUSINESS_EMAIL', '');
$name      = (string) Config::get('BUSINESS_NAME', '');
$city      = (string) Config::get('BUSINESS_CITY', 'Tulsa');
$state     = (string) Config::get('BUSINESS_STATE', 'OK');

$heading = $lang === 'es' ? 'Términos y Condiciones' : 'Terms & Conditions';
?>

<!-- ============================================================
     HERO HEADER — dark strip
     ============================================================ -->
<div class="section section--dark" style="padding: 3rem 0 2.5rem">
    <div class="container text-center">
        <span class="eyebrow label" style="color:var(--color-muted)">
            <?= htmlspecialchars($name) ?>
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

                <p>Al usar este sitio y realizar un pedido, aceptas los siguientes términos.</p>

                <h2>Pedidos y Cotizaciones</h2>
                <p>Los pedidos personalizados se confirman mediante una cotización que revisas y aceptas antes de pagar. Los precios pueden cambiar según la disponibilidad estacional de las flores. El pago de un depósito o del total confirma tu pedido.</p>

                <h2>Pagos</h2>
                <p>Aceptamos pago con tarjeta a través de Stripe, así como Zelle y CashApp según se indique al momento del pago. Los pagos con tarjeta se procesan de forma segura; no almacenamos los datos de tu tarjeta en nuestros servidores.</p>

                <h2>Entregas y Devoluciones</h2>
                <p>Consulta nuestra <a href="/<?= htmlspecialchars($lang) ?>/returns" style="color:var(--color-primary)">Política de Devoluciones y Reembolsos</a> para más detalles sobre entregas, daños y reembolsos.</p>

                <h2>Programa de Mensajes de Texto (SMS)</h2>
                <p>Si te suscribes a nuestro programa de mensajes de texto, aceptas recibir mensajes automatizados de <?= htmlspecialchars($name) ?> al número que proporcionaste. La frecuencia de los mensajes varía; pueden aplicar tarifas de mensajes y datos según tu plan. Responde STOP para cancelar en cualquier momento o AYUDA/HELP para obtener ayuda. Los operadores de telefonía móvil no son responsables de los mensajes retrasados o no entregados.</p>

                <h2>Uso del Sitio</h2>
                <p>Este sitio y su contenido son propiedad de <?= htmlspecialchars($name) ?>. No debes usar el sitio de manera que infrinja la ley o interfiera con su funcionamiento.</p>

                <h2>Limitación de Responsabilidad</h2>
                <p>En la medida permitida por la ley, <?= htmlspecialchars($name) ?> no será responsable por daños indirectos o consecuentes derivados del uso de este sitio o de nuestros productos.</p>

                <h2>Ley Aplicable</h2>
                <p>Estos términos se rigen por las leyes del estado de <?= htmlspecialchars($state) ?>, Estados Unidos.</p>

                <h2>Cambios a Estos Términos</h2>
                <p>Podemos actualizar estos términos ocasionalmente. La fecha de "última actualización" a continuación refleja la revisión más reciente.</p>

                <p style="margin-top:2rem; padding-top:1.5rem; border-top:1px solid var(--color-border)">
                    Si tienes preguntas, contáctanos:
                </p>

            <?php else: ?>

                <p>By using this site and placing an order, you agree to the following terms.</p>

                <h2>Orders & Quotes</h2>
                <p>Custom orders are confirmed through a quote that you review and accept before paying. Prices may change based on seasonal flower availability. Payment of a deposit or the full amount confirms your order.</p>

                <h2>Payments</h2>
                <p>We accept card payment through Stripe, as well as Zelle and CashApp where indicated at checkout. Card payments are processed securely; we do not store your card details on our servers.</p>

                <h2>Delivery & Returns</h2>
                <p>See our <a href="/<?= htmlspecialchars($lang) ?>/returns" style="color:var(--color-primary)">Return & Refund Policy</a> for details on delivery, damage, and refunds.</p>

                <h2>Text Message (SMS) Program</h2>
                <p>If you sign up for our text message program, you agree to receive automated messages from <?= htmlspecialchars($name) ?> at the number you provided. Message frequency varies; message and data rates may apply depending on your plan. Reply STOP to opt out at any time, or HELP for help. Carriers are not liable for delayed or undelivered messages.</p>

                <h2>Use of This Site</h2>
                <p>This site and its content are owned by <?= htmlspecialchars($name) ?>. You may not use the site in a way that violates the law or interferes with its operation.</p>

                <h2>Limitation of Liability</h2>
                <p>To the extent permitted by law, <?= htmlspecialchars($name) ?> is not liable for indirect or consequential damages arising from your use of this site or our products.</p>

                <h2>Governing Law</h2>
                <p>These terms are governed by the laws of the state of <?= htmlspecialchars($state) ?>, United States.</p>

                <h2>Changes to These Terms</h2>
                <p>We may update these terms from time to time. The "last updated" date below reflects the most recent revision.</p>

                <p style="margin-top:2rem; padding-top:1.5rem; border-top:1px solid var(--color-border)">
                    If you have questions, contact us:
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
