<?php
/**
 * views/public/local-areas-hub.php — "Delivery Areas" hub page.
 *
 * Lists every city Perla's Flowers delivers to, grouped by distance band, with
 * links to each city's funeral / hospital / birthday service page. Acts as the
 * internal-linking surface that keeps the city pages crawlable and connected.
 *
 * Variables injected by LocalAreaController::hub():
 *   string              $lang     'en' or 'es'.
 *   array<string,array> $areas    All city records (config/local-areas.php).
 *   array<string,array> $services All service definitions (config/local-services.php).
 *
 * @see \App\Controllers\LocalAreaController::hub()
 */

use App\Core\Config;

$bizName = Config::get('BUSINESS_NAME', "Perla's Flowers");

// Split cities into "core" (≤ ~9 mi, incl. Tulsa) and "extended perimeter" by
// the leading number in distance_miles.
$bands = ['core' => [], 'extended' => []];
foreach ($areas as $slug => $area) {
    $lead = (int) preg_replace('/\D.*$/', '', (string) ($area['distance_miles'] ?? '0'));
    $bands[$lead <= 9 ? 'core' : 'extended'][$slug] = $area;
}

$bandLabels = [
    'core'     => $lang === 'es' ? 'Tulsa y ciudades cercanas' : 'Tulsa & nearby cities',
    'extended' => $lang === 'es' ? 'Perímetro extendido' : 'Extended perimeter',
];
?>

<!-- ============================================================
     HERO — dark strip
     ============================================================ -->
<div class="section section--dark" style="padding:3.5rem 0 3rem">
    <div class="container text-center">
        <span class="eyebrow label" style="color:var(--color-muted)"><?= htmlspecialchars($bizName) ?></span>
        <h1 style="color:var(--color-text-light); max-width:760px; margin:0.5rem auto 0">
            <?= $lang === 'es' ? 'Áreas de Entrega de Flores' : 'Flower Delivery Areas' ?>
        </h1>
        <p style="color:rgba(255,255,255,0.75); max-width:620px; margin:1.25rem auto 0; font-size:1.05rem; line-height:1.7">
            <?= $lang === 'es'
                ? 'Entregamos arreglos hechos a mano en Tulsa y las ciudades de alrededor. Elija su ciudad y servicio para empezar.'
                : 'We deliver handcrafted arrangements across Tulsa and its surrounding cities. Choose your city and service to get started.' ?>
        </p>
    </div>
</div>

<!-- ============================================================
     CITY GRID — grouped by distance band
     ============================================================ -->
<section class="section section--light">
    <div class="container">
        <?php foreach (['core', 'extended'] as $band): ?>
            <?php if (empty($bands[$band])) continue; ?>
            <h2 style="margin-bottom:1.5rem"><?= htmlspecialchars($bandLabels[$band]) ?></h2>
            <div class="grid-2" style="gap:1.5rem; margin-bottom:3rem">
                <?php foreach ($bands[$band] as $slug => $area): ?>
                <div style="background:#fff; border:1px solid var(--color-border); border-radius:8px; padding:1.5rem">
                    <p style="font-family:'Cormorant Garamond',serif; font-size:1.5rem; font-weight:600; margin:0 0 0.25rem; color:var(--color-text-dark)">
                        <?= htmlspecialchars((string) $area['name']) ?>, <?= htmlspecialchars((string) ($area['state'] ?? 'OK')) ?>
                    </p>
                    <?php $cm = $cityMiles[$slug] ?? null; if (!empty($cm)):
                        $mn = number_format((float) $cm['min'], 1);
                        $mx = number_format((float) $cm['max'], 1);
                        $rt = (float) $cm['min'] === (float) $cm['max'] ? $mn : $mn . '–' . $mx;
                    ?>
                    <p style="font-size:0.8rem; color:var(--color-muted); margin:0 0 0.85rem; text-transform:uppercase; letter-spacing:0.04em">
                        <?= $lang === 'es' ? htmlspecialchars("{$rt} millas desde la tienda") : htmlspecialchars("{$rt} miles from our shop") ?>
                    </p>
                    <?php endif; ?>
                    <ul style="list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:0.5rem">
                        <?php foreach ($services as $def): ?>
                        <li>
                            <?php if (($def['entities'] ?? null) === null): ?>
                            <?php // Non-venue (birthday) service is home/office delivery — the
                                  // order flow captures the recipient address, so link to the
                                  // general products catalog rather than a fixed-venue page. ?>
                            <a href="/<?= $lang ?>/products" style="color:var(--color-primary)">
                                <?= $lang === 'es' ? 'Entrega a Casa u Oficina' : 'Home/Office Delivery' ?> &rarr;
                            </a>
                            <?php else: ?>
                            <a href="/<?= $lang ?>/<?= htmlspecialchars($def['prefix']) ?>-<?= htmlspecialchars($slug) ?>"
                               style="color:var(--color-primary)">
                                <?= htmlspecialchars(($def['label_' . ($lang === 'es' ? 'es' : 'en')] ?? '')) ?> &rarr;
                            </a>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <div class="text-center" style="margin-top:1rem">
            <a href="/<?= $lang ?>/order" class="btn btn-accent btn-lg">
                <?= $lang === 'es' ? 'Pedir un Arreglo Personalizado' : 'Order a Custom Arrangement' ?>
            </a>
        </div>
    </div>
</section>
