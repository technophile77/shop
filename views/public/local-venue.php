<?php
/**
 * views/public/local-venue.php — Per-venue local-SEO landing page (a single
 * funeral home or hospital in a given city).
 *
 * Rendered inside views/layouts/public.php via LocalAreaController::renderVenue().
 * Deeper than the city service page: it targets "{venue} flowers" searches with a
 * unique H1, the venue's address + map, a real distance/fee estimate, and a
 * "Send flowers" CTA that pre-fills the venue as the delivery destination.
 *
 * Variables injected by LocalAreaController::renderVenue():
 *   string                 $lang         'en' or 'es'.
 *   string                 $service      'funeral' or 'hospital'.
 *   array                  $serviceDef   The service definition row.
 *   string                 $citySlug     This city's slug.
 *   array                  $city         This city's record.
 *   array{name,address,miles?,fee?} $venue The single matched venue (enriched).
 *   string                 $occasionSlug Occasion slug the CTA links to.
 *   string                 $sendUrl      "Send flowers" CTA URL (venue pre-filled).
 *   string                 $mapsUrl      Google Maps link for the address.
 *   array                  $jsonLd       schema.org Service node for this page.
 *
 * @see \App\Controllers\LocalAreaController::renderVenue()
 * @see \App\Support\LocalArea
 */

use App\Core\Config;

$cityName  = (string) $city['name'];
$state     = (string) ($city['state'] ?? 'OK');
$venueName = (string) $venue['name'];
$bizName   = Config::get('BUSINESS_NAME', "Perla's Flowers");

$cityPath    = '/' . $lang . '/flower-delivery-' . $citySlug;
$servicePath = '/' . $lang . '/' . (string) ($serviceDef['prefix'] ?? '') . '-' . $citySlug;
$serviceLabel = (string) ($serviceDef['label_' . ($lang === 'es' ? 'es' : 'en')] ?? '');

/** Format a dollar fee with no trailing ".00" (e.g. 16.0 → "$16", 16.5 → "$16.50"). */
$fmtFee = static fn (float $f): string => '$' . (fmod($f, 1.0) === 0.0 ? number_format($f, 0) : number_format($f, 2));

$headline = $lang === 'es'
    ? "Entrega de Flores a {$venueName}"
    : "Flower Delivery to {$venueName}";
?>

<!-- schema.org structured data (Service naming the venue as areaServed) -->
<?php if (!empty($jsonLd)): ?>
<script type="application/ld+json">
<?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
<?php endif; ?>

<!-- ============================================================
     HERO — dark strip
     ============================================================ -->
<div class="section section--dark" style="padding:3.5rem 0 3rem">
    <div class="container text-center">
        <span class="eyebrow label" style="color:var(--color-muted)">
            <?= htmlspecialchars($cityName) ?>, <?= htmlspecialchars($state) ?>
        </span>
        <h1 style="color:var(--color-text-light); max-width:820px; margin:0.5rem auto 0">
            <?= htmlspecialchars($headline) ?>
        </h1>
        <p style="color:rgba(255,255,255,0.75); max-width:640px; margin:1.25rem auto 2rem; font-size:1.05rem; line-height:1.7">
            <?php if ($service === 'funeral'): ?>
                <?= $lang === 'es'
                    ? htmlspecialchars("{$bizName} entrega flores fúnebres y de condolencia a {$venueName} en {$cityName}. Coordinamos la entrega según el horario del servicio para que su arreglo llegue a tiempo.")
                    : htmlspecialchars("{$bizName} delivers funeral and sympathy flowers to {$venueName} in {$cityName}. We coordinate delivery around the service time so your arrangement arrives ahead of it.") ?>
            <?php else: ?>
                <?= $lang === 'es'
                    ? htmlspecialchars("{$bizName} entrega flores de pronta recuperación a {$venueName} en {$cityName}. Entregamos en la recepción, que lleva el arreglo a la habitación del paciente.")
                    : htmlspecialchars("{$bizName} delivers get-well flowers to {$venueName} in {$cityName}. We deliver to the front desk, which routes your arrangement to the patient's room.") ?>
            <?php endif; ?>
        </p>
        <div class="hero-actions" style="display:flex; gap:1rem; flex-wrap:wrap; justify-content:center">
            <a href="<?= htmlspecialchars($sendUrl) ?>" class="btn btn-accent btn-lg">
                <?= $lang === 'es' ? 'Enviar Flores' : 'Send Flowers' ?>
            </a>
            <a href="<?= htmlspecialchars($mapsUrl) ?>" class="btn btn-outline-light btn-lg"
               target="_blank" rel="noopener noreferrer">
                <?= $lang === 'es' ? 'Ver en el Mapa' : 'View on Map' ?>
            </a>
        </div>
    </div>
</div>

<!-- ============================================================
     BREADCRUMB
     ============================================================ -->
<nav aria-label="Breadcrumb" class="section section--light" style="padding:1rem 0 0">
    <div class="container">
        <p style="font-size:0.85rem; color:var(--color-muted); margin:0">
            <a href="/<?= $lang ?>/delivery-areas" style="color:var(--color-primary)">
                <?= $lang === 'es' ? 'Áreas de Entrega' : 'Delivery Areas' ?></a>
            &rsaquo;
            <a href="<?= htmlspecialchars($cityPath) ?>" style="color:var(--color-primary)">
                <?= htmlspecialchars($cityName) ?></a>
            &rsaquo;
            <a href="<?= htmlspecialchars($servicePath) ?>" style="color:var(--color-primary)">
                <?= htmlspecialchars($serviceLabel) ?></a>
            &rsaquo;
            <span><?= htmlspecialchars($venueName) ?></span>
        </p>
    </div>
</nav>

<!-- ============================================================
     VENUE DETAILS — address, distance, fee
     ============================================================ -->
<section class="section section--light">
    <div class="container">
        <div class="grid-2" style="align-items:start; gap:3rem">

            <!-- Localized intro copy -->
            <div style="font-size:1.05rem; line-height:1.85; color:var(--color-text-dark)">
                <p><?= $lang === 'es'
                    ? htmlspecialchars("A diferencia de los servicios nacionales por catálogo, cada arreglo lo hacemos aquí mismo en Tulsa y lo entregamos en persona a {$venueName}. Lo que pide es lo que recibe.")
                    : htmlspecialchars("Unlike national order-gatherer sites, every arrangement is made here in Tulsa and hand-delivered to {$venueName}. What you see is what arrives.") ?></p>
                <?php if ($service === 'funeral'): ?>
                <p><?= $lang === 'es'
                    ? 'Indique la fecha y hora del servicio al hacer su pedido y coordinaremos la entrega con la funeraria.'
                    : 'Tell us the service date and time when you order and we\'ll coordinate the delivery with the funeral home.' ?></p>
                <?php else: ?>
                <p><?= $lang === 'es'
                    ? 'Incluya el nombre completo del paciente y, si lo conoce, el número de habitación, para que el hospital pueda dirigir las flores.'
                    : 'Include the patient\'s full name and room number if you have it, so the hospital can route the flowers.' ?></p>
                <?php endif; ?>
            </div>

            <!-- Venue facts card -->
            <aside style="background:#fff; border:1px solid var(--color-border); border-radius:8px; padding:1.75rem; box-shadow:0 2px 12px rgba(0,0,0,0.05)">
                <p class="eyebrow label" style="margin-bottom:1rem">
                    <?= htmlspecialchars($venueName) ?>
                </p>
                <ul style="list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:0.9rem; font-size:0.95rem; color:var(--color-text-dark)">
                    <li>
                        <strong><?= $lang === 'es' ? 'Dirección:' : 'Address:' ?></strong><br>
                        <a href="<?= htmlspecialchars($mapsUrl) ?>" target="_blank" rel="noopener noreferrer"
                           style="color:var(--color-primary)">
                            <?= htmlspecialchars((string) $venue['address']) ?>
                        </a>
                    </li>
                    <?php if (isset($venue['miles'], $venue['fee'])): ?>
                    <li>
                        <strong><?= $lang === 'es' ? 'Desde la tienda:' : 'From our shop:' ?></strong>
                        <?= htmlspecialchars(number_format((float) $venue['miles'], 1)) ?> <?= $lang === 'es' ? 'millas' : 'mi' ?>
                    </li>
                    <li>
                        <strong><?= $lang === 'es' ? 'Entrega est.:' : 'Est. delivery:' ?></strong>
                        <?= htmlspecialchars($fmtFee((float) $venue['fee'])) ?>
                    </li>
                    <?php endif; ?>
                    <li>
                        <strong><?= $lang === 'es' ? 'Mismo día:' : 'Same-day:' ?></strong>
                        <?= $lang === 'es' ? 'Disponible si pide temprano' : 'Available when you order early' ?>
                    </li>
                </ul>
                <a href="<?= htmlspecialchars($sendUrl) ?>" class="btn btn-primary" style="margin-top:1.5rem; width:100%">
                    <?= $lang === 'es' ? 'Enviar Flores' : 'Send Flowers' ?> &rarr;
                </a>
                <p style="font-size:0.8rem; color:var(--color-muted); margin:0.85rem 0 0; line-height:1.5">
                    <?= $lang === 'es'
                        ? 'La distancia es en línea recta y la tarifa es estimada; la tarifa final se calcula con la dirección exacta al finalizar.'
                        : 'Distance is straight-line and the fee is an estimate; the final fee is calculated from the exact address at checkout.' ?>
                </p>
            </aside>

        </div><!-- /.grid-2 -->

        <p style="margin-top:2.5rem">
            <a href="<?= htmlspecialchars($servicePath) ?>" style="color:var(--color-primary)">
                &larr; <?= $lang === 'es'
                    ? htmlspecialchars("Ver todas las entregas de {$serviceLabel} en {$cityName}")
                    : htmlspecialchars("See all {$serviceLabel} delivery in {$cityName}") ?>
            </a>
        </p>
    </div>
</section>
