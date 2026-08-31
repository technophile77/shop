<?php
/**
 * views/public/privacy.php — Privacy Policy page.
 *
 * Rendered inside views/layouts/public.php. Outputs only the page body content.
 * Drafted to satisfy the Privacy Policy URL required by Twilio's A2P 10DLC
 * campaign registration and by Google Merchant Center / Google Ads. This is a
 * starting draft, not vetted legal advice — review and adjust before relying
 * on it. All contact details are pulled from config so they never drift from
 * the live business information.
 *
 * Variables injected by BaseController::render() via PrivacyPolicyController::index():
 *   string $lang      Current locale ('en' or 'es').
 *   string $pageTitle Localised page title from site settings.
 *
 * @see \App\Controllers\PrivacyPolicyController::index()
 */

use App\Core\Config;

$phone     = (string) Config::get('BUSINESS_PHONE', '');
$telDigits = preg_replace('/\D/', '', $phone);
$email     = (string) Config::get('BUSINESS_EMAIL', '');
$name      = (string) Config::get('BUSINESS_NAME', '');

$heading = $lang === 'es' ? 'Política de Privacidad' : 'Privacy Policy';
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

                <h2>Información que Recopilamos</h2>
                <p>Cuando realizas un pedido, solicitas una cotización o te registras para recibir promociones, recopilamos tu nombre, correo electrónico, número de teléfono y, cuando corresponde, la dirección de entrega. También usamos herramientas de análisis (Google Analytics, Meta Pixel, Google Ads) que recopilan información sobre cómo usas nuestro sitio.</p>

                <h2>Cómo Usamos tu Información</h2>
                <ul style="line-height:2">
                    <li>Para procesar y entregar tus pedidos y cotizaciones.</li>
                    <li>Para comunicarnos contigo sobre el estado de tu pedido.</li>
                    <li>Para enviarte promociones por correo electrónico o mensaje de texto, únicamente si diste tu consentimiento explícito.</li>
                    <li>Para procesar pagos de forma segura a través de Stripe.</li>
                    <li>Para mejorar nuestro sitio y nuestras campañas publicitarias.</li>
                </ul>

                <h2>Mensajes de Texto (SMS)</h2>
                <p>Si te registraste para recibir mensajes de texto promocionales, enviamos esos mensajes a través de Twilio. Nunca compartimos tu número de teléfono con terceros para fines de mercadeo ajenos a <?= htmlspecialchars($name) ?>. Puedes cancelar en cualquier momento respondiendo STOP, o contactándonos directamente.</p>

                <h2>Terceros con Quienes Compartimos Información</h2>
                <p>Compartimos información únicamente con los proveedores necesarios para operar el negocio: Stripe (pagos), Twilio (mensajes de texto), nuestro proveedor de correo electrónico, y plataformas publicitarias (Google, Meta) para medir el rendimiento de nuestros anuncios. No vendemos tu información personal.</p>

                <h2>Tus Opciones</h2>
                <p>Puedes cancelar tu suscripción a promociones por correo o mensaje de texto en cualquier momento. También puedes solicitarnos que eliminemos tu información de contacto contactándonos directamente.</p>

                <h2>Menores de Edad</h2>
                <p>Nuestro sitio no está dirigido a menores de 13 años y no recopilamos intencionalmente información de menores.</p>

                <p style="margin-top:2rem; padding-top:1.5rem; border-top:1px solid var(--color-border)">
                    Si tienes preguntas sobre esta política, contáctanos:
                </p>

            <?php else: ?>

                <h2>Information We Collect</h2>
                <p>When you place an order, request a quote, or sign up for promotions, we collect your name, email address, phone number, and, when applicable, your delivery address. We also use analytics tools (Google Analytics, Meta Pixel, Google Ads) that collect information about how you use our site.</p>

                <h2>How We Use Your Information</h2>
                <ul style="line-height:2">
                    <li>To process and deliver your orders and quotes.</li>
                    <li>To communicate with you about your order status.</li>
                    <li>To send you email or text message promotions, only if you gave explicit consent.</li>
                    <li>To process payments securely through Stripe.</li>
                    <li>To improve our site and advertising campaigns.</li>
                </ul>

                <h2>Text Messages (SMS)</h2>
                <p>If you signed up to receive promotional text messages, we send those messages through Twilio. We never share your phone number with third parties for marketing purposes unrelated to <?= htmlspecialchars($name) ?>. You can opt out at any time by replying STOP, or by contacting us directly.</p>

                <h2>Third Parties We Share Information With</h2>
                <p>We share information only with the vendors necessary to run the business: Stripe (payments), Twilio (text messages), our email provider, and advertising platforms (Google, Meta) to measure ad performance. We do not sell your personal information.</p>

                <h2>Your Choices</h2>
                <p>You can unsubscribe from email or text message promotions at any time. You can also ask us to delete your contact information by reaching out to us directly.</p>

                <h2>Children's Privacy</h2>
                <p>Our site is not directed at children under 13, and we do not knowingly collect information from children.</p>

                <p style="margin-top:2rem; padding-top:1.5rem; border-top:1px solid var(--color-border)">
                    If you have questions about this policy, contact us:
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
