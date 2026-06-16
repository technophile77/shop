<?php

declare(strict_types=1);

/**
 * Service definitions for the city landing pages. Pairs each high-intent
 * delivery service with its URL prefix, the venue list it renders, and the
 * bilingual title/meta/heading templates used by App\Support\LocalArea.
 *
 * The array is keyed by service code. The {city} routes map to these codes:
 *   /funeral-flowers-{city}          → 'funeral'
 *   /hospital-flower-delivery-{city} → 'hospital'
 *   /birthday-delivery-{city}        → 'birthday'
 *
 * Template fields contain a single `%s` placeholder that is filled with the
 * city display name via sprintf().
 *
 * Field contract (enforced by App\Support\LocalArea::validateServices and the
 * test-suite):
 *   - prefix         string       URL prefix (no leading slash, no trailing dash).
 *   - entities       string|null  Key into a city's local-areas entry whose list
 *                                 of {name,address} venues this page shows, or null
 *                                 (birthday pages have no venue list).
 *   - service_type   string       schema.org Service `serviceType` value.
 *   - label_en/_es   string       Short label for hub links and breadcrumbs.
 *   - title_en/_es   string       <title>/pageTitle template, one %s = city.
 *   - meta_en/_es    string       Meta-description template, one %s = city.
 *   - h1_en/_es      string       Hero headline template, one %s = city.
 *   - venue_heading_en/_es string|null  Heading above the venue list (null = none).
 *
 * @return array<string, array{
 *     prefix: string, entities: string|null, service_type: string,
 *     label_en: string, label_es: string,
 *     title_en: string, title_es: string,
 *     meta_en: string, meta_es: string,
 *     h1_en: string, h1_es: string,
 *     venue_heading_en: ?string, venue_heading_es: ?string
 * }>
 */

return [

    'funeral' => [
        'prefix'           => 'funeral-flowers',
        'entities'         => 'funeral_homes',
        'service_type'     => 'Funeral flower delivery',
        'label_en'         => 'Funeral Flowers',
        'label_es'         => 'Flores para Funerales',
        'title_en'         => 'Funeral Flower Delivery to %s, OK',
        'title_es'         => 'Entrega de Flores para Funerales en %s, OK',
        'meta_en'          => 'Same-day funeral and sympathy flower delivery to funeral homes in %s, OK. Locally handcrafted arrangements from Perla\'s Flowers, delivered with care.',
        'meta_es'          => 'Entrega el mismo día de flores fúnebres y de condolencia a funerarias en %s, OK. Arreglos hechos a mano por Perla\'s Flowers, entregados con cuidado.',
        'h1_en'            => 'Funeral & Sympathy Flowers Delivered in %s',
        'h1_es'            => 'Flores Fúnebres y de Condolencia con Entrega en %s',
        'venue_heading_en' => 'Funeral homes we deliver to in and around %s',
        'venue_heading_es' => 'Funerarias a las que entregamos en %s y alrededores',
    ],

    'hospital' => [
        'prefix'           => 'hospital-flower-delivery',
        'entities'         => 'hospitals',
        'service_type'     => 'Hospital flower delivery',
        'label_en'         => 'Hospital Delivery',
        'label_es'         => 'Entrega a Hospitales',
        'title_en'         => 'Hospital Flower Delivery to %s, OK',
        'title_es'         => 'Entrega de Flores a Hospitales en %s, OK',
        'meta_en'          => 'Send get-well flowers to hospitals serving %s, OK. Same-day, locally handcrafted bouquets delivered to patient rooms by Perla\'s Flowers.',
        'meta_es'          => 'Envíe flores de pronta recuperación a hospitales que atienden a %s, OK. Ramos hechos a mano con entrega el mismo día por Perla\'s Flowers.',
        'h1_en'            => 'Get-Well & Hospital Flower Delivery in %s',
        'h1_es'            => 'Flores de Recuperación con Entrega a Hospitales en %s',
        'venue_heading_en' => 'Hospitals we deliver to serving %s',
        'venue_heading_es' => 'Hospitales a los que entregamos que atienden a %s',
    ],

    'birthday' => [
        'prefix'           => 'birthday-delivery',
        'entities'         => null,
        'service_type'     => 'Birthday flower delivery',
        'label_en'         => 'Birthday Delivery',
        'label_es'         => 'Entrega de Cumpleaños',
        'title_en'         => 'Birthday Flower Delivery in %s, OK',
        'title_es'         => 'Entrega de Flores de Cumpleaños en %s, OK',
        'meta_en'          => 'Send a birthday surprise to %s, OK. Same-day delivery of custom, locally handcrafted birthday bouquets from Perla\'s Flowers.',
        'meta_es'          => 'Envíe una sorpresa de cumpleaños a %s, OK. Entrega el mismo día de ramos de cumpleaños personalizados hechos a mano por Perla\'s Flowers.',
        'h1_en'            => 'Birthday Flower Delivery in %s',
        'h1_es'            => 'Entrega de Flores de Cumpleaños en %s',
        'venue_heading_en' => null,
        'venue_heading_es' => null,
    ],

];
