<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Response;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\LocalArea;
use App\Support\Shop;
use App\Support\Slug;

/**
 * Generates the XML sitemap dynamically from the database.
 *
 * Serves /sitemap.xml with all public bilingual URLs including product
 * category pages, which are DB-driven and cannot be maintained in a
 * static file.
 */
class SitemapController
{
    /**
     * Render the sitemap as application/xml.
     *
     * Includes both /en/ and /es/ variants of every public page with
     * proper hreflang cross-links. Category pages are pulled from the
     * database so the sitemap stays current without manual edits.
     *
     * @return Response An XML response.
     *
     * @example
     *   // Matched by: GET /sitemap.xml
     *   $response = (new SitemapController())->index();
     */
    public function index(): Response
    {
        $base       = rtrim((string) Config::get('APP_URL', ''), '/');
        $categories = ProductCategory::allActive();

        // Catalog timestamps for <lastmod> on product-driven pages.
        $catalogMax = Product::maxUpdatedAt();
        $catMaxById = Product::maxUpdatedAtByCategory();

        // Static pages: [path, changefreq, priority]
        $staticPages = [
            ['/',                  'weekly',  '1.0'],
            ['/products',          'weekly',  '0.9'],
            ['/flowers/occasions', 'weekly',  '0.8'],
            ['/order',             'monthly', '0.8'],
            ['/about',             'monthly', '0.6'],
            ['/contact',           'monthly', '0.6'],
        ];

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
        $xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        foreach ($staticPages as [$path, $changefreq, $priority]) {
            // The products index reflects the catalog's freshness; others have no timestamp.
            $lastmod = $path === '/products' ? $catalogMax : null;
            $xml    .= self::urlBlock($base, $path, $changefreq, $priority, $lastmod);
        }

        foreach ($categories as $category) {
            $slug = $category['slug'] ?? '';
            if ($slug === '') {
                continue;
            }
            $lastmod = $catMaxById[(int) $category['id']] ?? $catalogMax;
            $xml    .= self::urlBlock($base, '/products/' . $slug, 'weekly', '0.8', $lastmod);
        }

        // Local-SEO city landing pages, per-city hubs, and the areas hub
        // (config/local-areas.php). No reliable content timestamp — omit lastmod.
        foreach (LocalArea::allPaths(LocalArea::areas(), LocalArea::services()) as $path) {
            if ($path === '') {
                continue;
            }
            $priority = $path === '/delivery-areas' ? '0.7' : '0.6';
            $xml .= self::urlBlock($base, $path, 'monthly', $priority);
        }

        // Occasion bouquet pages: every active occasion plus the combined
        // group pages (e.g. /flowers/occasion/hospital).
        $occasionSlugs = [];
        foreach (Occasion::allActive() as $occasion) {
            $slug = $occasion['slug'] ?? '';
            if ($slug !== '') {
                $occasionSlugs[] = $slug;
            }
        }
        foreach (Shop::occasionGroupSlugs() as $groupSlug) {
            $occasionSlugs[] = $groupSlug;
        }
        foreach (array_unique($occasionSlugs) as $slug) {
            $xml .= self::urlBlock($base, '/flowers/occasion/' . $slug, 'weekly', '0.7', $catalogMax);
        }

        // Individual bouquet detail pages (id-anchored slugs), with per-product lastmod.
        foreach (Product::allActive() as $product) {
            $xml .= self::urlBlock(
                $base,
                '/flowers/' . Slug::productRef($product),
                'weekly',
                '0.7',
                isset($product['updated_at']) ? (string) $product['updated_at'] : null
            );
        }

        $xml .= '</urlset>' . "\n";

        return (new Response($xml, 200))
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * Build a <url> block with bilingual hreflang links for both en and es.
     *
     * @param string      $base       The application base URL without trailing slash.
     * @param string      $path       The path (e.g. '/products/bouquets'). Use '/' for home.
     * @param string      $changefreq XML changefreq value.
     * @param string      $priority   XML priority value.
     * @param string|null $lastmod    A DB timestamp ('Y-m-d H:i:s') or null; emitted as a
     *                                `<lastmod>` date (YYYY-MM-DD) when provided.
     * @return string The <url>...</url> XML fragment (×2 for each language).
     */
    private static function urlBlock(
        string $base,
        string $path,
        string $changefreq,
        string $priority,
        ?string $lastmod = null
    ): string {
        $enUrl     = $base . '/en' . ($path === '/' ? '/' : $path);
        $esUrl     = $base . '/es' . ($path === '/' ? '/' : $path);
        $lastmodXml = ($lastmod !== null && $lastmod !== '')
            ? '    <lastmod>' . substr($lastmod, 0, 10) . '</lastmod>' . "\n"
            : '';

        $block = '';
        foreach ([['en', $enUrl], ['es', $esUrl]] as [$lang, $loc]) {
            $block .= "\n  <url>\n";
            $block .= '    <loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>' . "\n";
            $block .= '    <xhtml:link rel="alternate" hreflang="en" href="' . htmlspecialchars($enUrl, ENT_XML1) . '"/>' . "\n";
            $block .= '    <xhtml:link rel="alternate" hreflang="es" href="' . htmlspecialchars($esUrl, ENT_XML1) . '"/>' . "\n";
            $block .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($enUrl, ENT_XML1) . '"/>' . "\n";
            $block .= $lastmodXml;
            $block .= '    <changefreq>' . $changefreq . '</changefreq>' . "\n";
            $block .= '    <priority>' . $priority . '</priority>' . "\n";
            $block .= "  </url>\n";
        }
        return $block;
    }
}
