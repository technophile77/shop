<?php

declare(strict_types=1);

/**
 * views/public/local-city-hub.php — Per-city hub listing all services for a city.
 *
 * Rendered inside views/layouts/public.php via LocalAreaController::cityHub().
 * Lets a visitor choose funeral / hospital / birthday for the city instead of
 * assuming a service.
 *
 * Variables injected by LocalAreaController::cityHub():
 *   string              $lang      'en' or 'es'.
 *   array               $city      The city record (config/local-areas.php).
 *   string              $citySlug  This city's slug.
 *   array<string,array> $services  All service definitions.
 *   array|null          $cityMiles {min,max} miles range for this city, or null.
 *
 * @see \App\Controllers\LocalAreaController::cityHub()
 */

use App\Core\Config;

$bizName  = Config::get('BUSINESS_NAME', "Perla's Flowers");
$cityName = (string) $city['name'];
$state    = (string) ($city['state'] ?? 'OK');
?>

<!-- HERO -->
<div class="section section--dark" style="padding:3.5rem 0 3rem">
    <div class="container text-center">
        <span class="eyebrow label" style="color:var(--color-muted)">
            <?= htmlspecialchars($cityName) ?>, <?= htmlspecialchars($state) ?>
        </span>
        <h1 style="color:var(--color-text-light); max-width:760px; margin:0.5rem auto 0">
            <?= $lang === 'es' ? htmlspecialchars("Entrega de Flores en {$cityName}") : htmlspecialchars("Flower Delivery to {$cityName}") ?>
        </h1>
        <p style="color:rgba(255,255,255,0.75); max-width:620px; margin:1.25rem auto 0; font-size:1.05rem; line-height:1.7">
            <?= $lang === 'es'
                ? htmlspecialchars("{$bizName} entrega arreglos hechos a mano en {$cityName}. Elige el servicio que necesitas.")
                : htmlspecialchars("{$bizName} delivers handcrafted arrangements to {$cityName}. Choose the service you need.") ?>
        </p>
        <?php if (!empty($cityMiles)):
            $mn = number_format((float) $cityMiles['min'], 1);
            $mx = number_format((float) $cityMiles['max'], 1);
            $rt = (float) $cityMiles['min'] === (float) $cityMiles['max'] ? $mn : $mn . '–' . $mx;
        ?>
        <p style="color:rgba(255,255,255,0.55); margin-top:1rem; font-size:0.82rem; text-transform:uppercase; letter-spacing:0.04em">
            <?= $lang === 'es' ? htmlspecialchars("{$rt} millas desde nuestra tienda") : htmlspecialchars("{$rt} miles from our shop") ?>
        </p>
        <?php endif; ?>
    </div>
</div>

<!-- SERVICE CHOICES -->
<section class="section section--light">
    <div class="container">
        <div class="grid-3" style="gap:1.5rem">
            <?php foreach ($services as $def):
                $label  = (string) ($def['label_' . ($lang === 'es' ? 'es' : 'en')] ?? '');
                $prefix = (string) ($def['prefix'] ?? '');
                if ($prefix === '') { continue; }
                $url = '/' . $lang . '/' . $prefix . '-' . $citySlug;
            ?>
            <a href="<?= htmlspecialchars($url) ?>"
               style="display:block; background:#fff; border:1px solid var(--color-border); border-radius:8px; padding:1.5rem; text-decoration:none; color:inherit">
                <p style="font-family:'Cormorant Garamond',serif; font-size:1.4rem; font-weight:600; margin:0 0 0.35rem; color:var(--color-text-dark)">
                    <?= htmlspecialchars($label) ?>
                </p>
                <span style="color:var(--color-primary); font-size:0.9rem">
                    <?= $lang === 'es' ? 'Ver opciones' : 'View options' ?> &rarr;
                </span>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="text-center" style="margin-top:2.5rem">
            <a href="/<?= $lang ?>/order" class="btn btn-accent btn-lg">
                <?= $lang === 'es' ? 'Pedir un Arreglo Personalizado' : 'Order a Custom Arrangement' ?>
            </a>
            <p style="margin-top:1rem">
                <a href="/<?= $lang ?>/delivery-areas" style="color:var(--color-primary); font-size:0.9rem">
                    <?= $lang === 'es' ? 'Ver todas las áreas de entrega' : 'View all delivery areas' ?> &rarr;
                </a>
            </p>
        </div>
    </div>
</section>
