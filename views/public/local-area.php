<?php
/**
 * views/public/local-area.php — Local-SEO city landing page (funeral / hospital
 * / birthday flower delivery).
 *
 * Rendered inside views/layouts/public.php; outputs only the page body. All
 * localized strings use the inline `$lang === 'es' ? … : …` idiom used
 * elsewhere in the site; the SEO logic and data are prepared by
 * LocalAreaController + App\Support\LocalArea.
 *
 * Variables injected by BaseController::render() via LocalAreaController:
 *   string                      $lang         'en' or 'es'.
 *   string                      $service      'funeral'|'hospital'|'birthday'.
 *   array                       $serviceDef   The service definition row.
 *   array<string,array>         $services     All service definitions.
 *   array<string,array>         $areas        All city records.
 *   string                      $citySlug     This city's slug.
 *   array                       $city         This city's record.
 *   string                      $headline     Hero H1.
 *   ?string                     $venueHeading Heading for the venue list (or null).
 *   list<array{name,address}>   $venues       Named venues for this page.
 *   list<array{q,a}>            $faqItems     FAQ pairs (also in the JSON-LD).
 *   array                       $jsonLd       schema.org @graph for this page.
 *
 * @see \App\Controllers\LocalAreaController
 * @see \App\Support\LocalArea
 */

use App\Core\Config;

$cityName  = (string) $city['name'];
$direction = (string) ($city[$lang === 'es' ? 'direction_es' : 'direction_en'] ?? '');
$distance  = (string) ($city['distance_miles'] ?? '');
$isHome    = $distance === '0' || $distance === '';

$bizAddr   = Config::get('BUSINESS_ADDRESS', '');
$baseMiles = (int) Config::get('BUSINESS_DELIVERY_BASE_MILES', 5);
$baseFee   = (int) Config::get('BUSINESS_DELIVERY_BASE_FEE', 10);
$perMile   = (int) Config::get('BUSINESS_DELIVERY_PER_MILE_FEE', 1);
$bizName   = Config::get('BUSINESS_NAME', "Perla's Flowers");

/** Format a dollar fee with no trailing ".00" (e.g. 16.0 → "$16", 16.5 → "$16.50"). */
$fmtFee = static fn (float $f): string => '$' . (fmod($f, 1.0) === 0.0 ? number_format($f, 0) : number_format($f, 2));
?>

<!-- schema.org structured data (Service + venue Places + FAQPage) -->
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
            <?= htmlspecialchars($cityName) ?>, <?= htmlspecialchars((string) ($city['state'] ?? 'OK')) ?>
        </span>
        <h1 style="color:var(--color-text-light); max-width:820px; margin:0.5rem auto 0">
            <?= htmlspecialchars($headline) ?>
        </h1>
        <p style="color:rgba(255,255,255,0.75); max-width:640px; margin:1.25rem auto 2rem; font-size:1.05rem; line-height:1.7">
            <?php if ($isHome): ?>
                <?= $lang === 'es'
                    ? htmlspecialchars("{$bizName} es una floristería local que entrega arreglos hechos a mano en {$cityName} y toda el área metropolitana, a menudo el mismo día.")
                    : htmlspecialchars("{$bizName} is a local florist delivering handcrafted arrangements across {$cityName} and the wider metro — often the same day.") ?>
            <?php else: ?>
                <?= $lang === 'es'
                    ? htmlspecialchars("¿Enviando flores a {$cityName}? {$bizName} es una floristería local de Tulsa que entrega arreglos hechos a mano en {$cityName} — {$direction}.")
                    : htmlspecialchars("Sending flowers to {$cityName}? {$bizName} is a local Tulsa florist delivering handcrafted arrangements to {$cityName} — {$direction}.") ?>
            <?php endif; ?>
        </p>
        <div class="hero-actions" style="display:flex; gap:1rem; flex-wrap:wrap; justify-content:center">
            <a href="/<?= $lang ?>/order" class="btn btn-accent btn-lg">
                <?= $lang === 'es' ? 'Pedir un Arreglo' : 'Order an Arrangement' ?>
            </a>
            <a href="/<?= $lang ?>/products" class="btn btn-outline-light btn-lg">
                <?= $lang === 'es' ? 'Ver Flores' : 'Browse Flowers' ?>
            </a>
        </div>
    </div>
</div>

<!-- ============================================================
     INTRO + DELIVERY DETAILS — light
     ============================================================ -->
<section class="section section--light">
    <div class="container">
        <div class="grid-2" style="align-items:start; gap:3rem">

            <!-- Localized intro copy -->
            <div style="font-size:1.05rem; line-height:1.85; color:var(--color-text-dark)">
                <?php if ($service === 'funeral'): ?>
                    <p><?= $lang === 'es'
                        ? htmlspecialchars("Perder a un ser querido es difícil, y aún más cuando está lejos. Enviamos flores fúnebres y de condolencia a las funerarias de {$cityName} y coordinamos la entrega para que lleguen a tiempo para el servicio.")
                        : htmlspecialchars("Losing someone is hard — harder still from out of town. We deliver funeral and sympathy flowers to {$cityName}-area funeral homes and coordinate timing so your arrangement arrives ahead of the service.") ?></p>
                <?php elseif ($service === 'hospital'): ?>
                    <p><?= $lang === 'es'
                        ? htmlspecialchars("Un ramo alegre puede iluminar el día de un paciente. Entregamos flores de pronta recuperación a los hospitales que atienden a {$cityName}, con la frescura y el cuidado de una floristería local.")
                        : htmlspecialchars("A bright bouquet can lift a patient's whole day. We deliver get-well flowers to the hospitals serving {$cityName} with the freshness and care only a local florist provides.") ?></p>
                <?php else: ?>
                    <p><?= $lang === 'es'
                        ? htmlspecialchars("¿Un cumpleaños en {$cityName}? Sorprenda a alguien especial con un ramo personalizado y hecho a mano, entregado fresco — a menudo el mismo día.")
                        : htmlspecialchars("Birthday in {$cityName}? Surprise someone special with a custom, handcrafted bouquet delivered fresh — often the very same day.") ?></p>
                <?php endif; ?>

                <p><?= $lang === 'es'
                    ? htmlspecialchars("A diferencia de los servicios nacionales por catálogo, cada arreglo lo hacemos nosotros aquí mismo y lo entregamos en persona. Lo que pide es lo que recibe.")
                    : htmlspecialchars("Unlike national order-gatherer sites, every arrangement is made by us here in town and delivered in person. What you see is what arrives.") ?></p>

                <?php if (!empty($cityMiles)):
                    $rngMin = number_format((float) $cityMiles['min'], 1);
                    $rngMax = number_format((float) $cityMiles['max'], 1);
                    $rngTxt = (float) $cityMiles['min'] === (float) $cityMiles['max'] ? $rngMin : $rngMin . '–' . $rngMax;
                ?>
                <p style="color:var(--color-muted); font-size:0.95rem">
                    <?= $lang === 'es'
                        ? htmlspecialchars("Las ubicaciones que atendemos en {$cityName} están a {$rngTxt} millas de nuestra tienda.")
                        : htmlspecialchars("The {$cityName} locations we deliver to are {$rngTxt} miles from our shop.") ?>
                </p>
                <?php endif; ?>
            </div>

            <!-- Delivery facts card -->
            <aside style="background:#fff; border:1px solid var(--color-border); border-radius:8px; padding:1.75rem; box-shadow:0 2px 12px rgba(0,0,0,0.05)">
                <p class="eyebrow label" style="margin-bottom:1rem">
                    <?= $lang === 'es' ? 'Detalles de Entrega' : 'Delivery Details' ?>
                </p>
                <ul style="list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:0.9rem; font-size:0.95rem; color:var(--color-text-dark)">
                    <li>
                        <strong><?= $lang === 'es' ? 'Área:' : 'Area:' ?></strong>
                        <?= htmlspecialchars($cityName) ?>, <?= htmlspecialchars((string) ($city['state'] ?? 'OK')) ?>
                        <?php if (!empty($city['zips'])): ?>
                            <span style="color:var(--color-muted)">(<?= htmlspecialchars(implode(', ', (array) $city['zips'])) ?>)</span>
                        <?php endif; ?>
                    </li>
                    <?php if ($baseFee > 0): ?>
                    <li>
                        <strong><?= $lang === 'es' ? 'Tarifa:' : 'Delivery fee:' ?></strong>
                        <?= $lang === 'es'
                            ? htmlspecialchars("Dentro de {$baseMiles} millas \${$baseFee} · +\${$perMile} por milla adicional")
                            : htmlspecialchars("Within {$baseMiles} mi \${$baseFee} · +\${$perMile} per additional mile") ?>
                    </li>
                    <?php endif; ?>
                    <li>
                        <strong><?= $lang === 'es' ? 'Mismo día:' : 'Same-day:' ?></strong>
                        <?= $lang === 'es' ? 'Disponible si pide temprano' : 'Available when you order early' ?>
                    </li>
                </ul>
                <a href="/<?= $lang ?>/order" class="btn btn-primary" style="margin-top:1.5rem; width:100%">
                    <?= $lang === 'es' ? 'Empezar mi Pedido' : 'Start My Order' ?>
                </a>
            </aside>

        </div><!-- /.grid-2 -->
    </div>
</section>

<!-- ============================================================
     VENUE LIST — funeral homes / hospitals (omitted for birthday)
     ============================================================ -->
<?php if ($venueHeading !== null && $venues !== []): ?>
<section class="section section--light" style="padding-top:0">
    <div class="container">
        <h2 style="margin-bottom:0.5rem"><?= htmlspecialchars($venueHeading) ?></h2>
        <p style="color:var(--color-muted); max-width:640px; margin-bottom:2rem">
            <?= $lang === 'es'
                ? 'Indique el nombre del lugar al hacer su pedido y coordinaremos la entrega.'
                : 'Just name the venue when you order and we\'ll coordinate the delivery.' ?>
        </p>
        <div class="grid-3" style="gap:1.25rem">
            <?php foreach ($venues as $venue): ?>
            <div style="background:#fff; border:1px solid var(--color-border); border-radius:8px; padding:1.25rem">
                <p style="font-family:'Cormorant Garamond',serif; font-size:1.25rem; font-weight:600; margin:0 0 0.35rem; color:var(--color-text-dark)">
                    <?= htmlspecialchars($venue['name']) ?>
                </p>
                <p style="font-size:0.9rem; color:var(--color-muted); margin:0 0 0.75rem; line-height:1.5">
                    <?= htmlspecialchars($venue['address']) ?>
                </p>
                <?php if (isset($venue['miles'], $venue['fee'])): ?>
                <p style="font-size:0.85rem; color:var(--color-text-dark); margin:0 0 0.75rem; line-height:1.5">
                    <span style="color:var(--color-muted)"><?= $lang === 'es' ? 'Desde la tienda:' : 'From our shop:' ?></span>
                    <strong><?= htmlspecialchars(number_format((float) $venue['miles'], 1)) ?> <?= $lang === 'es' ? 'millas' : 'mi' ?></strong>
                    &middot;
                    <span style="color:var(--color-muted)"><?= $lang === 'es' ? 'entrega est.' : 'est. delivery' ?></span>
                    <strong><?= htmlspecialchars($fmtFee((float) $venue['fee'])) ?></strong>
                </p>
                <?php endif; ?>
                <?php
                $sendSlug = \App\Support\LocalArea::occasionSlugForService($service);
                $sendUrl  = '/' . $lang . '/flowers/occasion/' . $sendSlug
                    . '?dest_service='  . urlencode($service)
                    . '&dest_city='     . urlencode($citySlug)
                    . '&venue_name='    . urlencode($venue['name'])
                    . '&venue_address=' . urlencode($venue['address']);
                ?>
                <a href="<?= htmlspecialchars($sendUrl) ?>"
                   class="btn btn-primary btn-sm"
                   style="margin-top:0.25rem">
                    <?= $lang === 'es' ? 'Enviar flores' : 'Send flowers' ?> &rarr;
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <p style="color:var(--color-muted); font-size:0.85rem; margin-top:1.5rem">
            <?= $lang === 'es'
                ? 'Las distancias son en línea recta desde nuestra tienda y las tarifas de entrega son estimadas; la tarifa final se calcula con su dirección exacta al finalizar el pedido. ¿No ve el lugar que busca? Entregamos en toda el área — contáctenos.'
                : 'Distances are straight-line from our shop and delivery fees are estimates; the final fee is calculated from your exact address at checkout. Don\'t see your venue? We deliver area-wide — just reach out.' ?>
        </p>
    </div>
</section>
<?php endif; ?>

<!-- ============================================================
     BIRTHDAY BOUQUETS — inline product grid (birthday pages only)
     ============================================================ -->
<?php if ($service === 'birthday' && !empty($bouquetProducts)): ?>
<section class="section section--light" style="padding-top:0">
    <div class="container">
        <h2 style="margin-bottom:0.5rem">
            <?= htmlspecialchars($lang === 'es' ? 'Ramos de Cumpleaños' : 'Birthday Bouquets') ?>
        </h2>
        <p style="color:var(--color-muted); max-width:640px; margin-bottom:2rem">
            <?= $lang === 'es'
                ? 'Elige un ramo, personaliza los colores y lo entregamos a tu destinatario.'
                : 'Pick a bouquet, customize the colors, and we\'ll deliver it to your recipient.' ?>
        </p>
        <div class="product-grid">
            <?php foreach ($bouquetProducts as $product): ?>
            <?php include __DIR__ . '/_bouquet_card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ============================================================
     FAQ — also emitted as FAQPage JSON-LD above
     ============================================================ -->
<?php if (!empty($faqItems)): ?>
<section class="section section--light" style="padding-top:0">
    <div class="container" style="max-width:760px">
        <h2 style="margin-bottom:1.5rem; text-align:center">
            <?= $lang === 'es' ? 'Preguntas Frecuentes' : 'Frequently Asked Questions' ?>
        </h2>
        <?php foreach ($faqItems as $faq): ?>
        <div style="border-top:1px solid var(--color-border); padding:1.25rem 0">
            <p style="font-weight:600; color:var(--color-text-dark); margin:0 0 0.5rem">
                <?= htmlspecialchars($faq['q']) ?>
            </p>
            <p style="color:var(--color-text-dark); margin:0; line-height:1.7">
                <?= htmlspecialchars($faq['a']) ?>
            </p>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- ============================================================
     INTERNAL LINKS — sibling services + same service nearby cities
     ============================================================ -->
<section class="section section--dark" style="padding:3rem 0">
    <div class="container">
        <div class="grid-2" style="gap:3rem">

            <!-- Other services in this city -->
            <div>
                <p class="eyebrow label" style="color:var(--color-muted); margin-bottom:1rem">
                    <?= $lang === 'es'
                        ? htmlspecialchars("Más entregas en {$cityName}")
                        : htmlspecialchars("More delivery in {$cityName}") ?>
                </p>
                <ul style="list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:0.6rem">
                    <?php foreach ($services as $code => $def): ?>
                        <?php if ($code === $service) continue; ?>
                        <li>
                            <a href="/<?= $lang ?>/<?= htmlspecialchars($def['prefix']) ?>-<?= htmlspecialchars($citySlug) ?>"
                               style="color:rgba(255,255,255,0.85)">
                                <?= htmlspecialchars(($def['label_' . ($lang === 'es' ? 'es' : 'en')] ?? '') . ' — ' . $cityName) ?> &rarr;
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Same service, nearby cities -->
            <div>
                <p class="eyebrow label" style="color:var(--color-muted); margin-bottom:1rem">
                    <?= htmlspecialchars(($serviceDef['label_' . ($lang === 'es' ? 'es' : 'en')] ?? '')) ?>
                    <?= $lang === 'es' ? 'en otras ciudades' : 'in nearby cities' ?>
                </p>
                <ul style="list-style:none; margin:0; padding:0; display:grid; grid-template-columns:1fr 1fr; gap:0.6rem">
                    <?php foreach ($areas as $slug => $area): ?>
                        <?php if ($slug === $citySlug) continue; ?>
                        <li>
                            <a href="/<?= $lang ?>/<?= htmlspecialchars($serviceDef['prefix']) ?>-<?= htmlspecialchars($slug) ?>"
                               style="color:rgba(255,255,255,0.85)">
                                <?= htmlspecialchars((string) $area['name']) ?> &rarr;
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin-top:1.25rem">
                    <a href="/<?= $lang ?>/delivery-areas" style="color:var(--color-accent)">
                        <?= $lang === 'es' ? 'Ver todas las áreas de entrega' : 'See all delivery areas' ?> &rarr;
                    </a>
                </p>
            </div>

        </div>
    </div>
</section>
