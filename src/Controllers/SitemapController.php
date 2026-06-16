<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Response;
use App\Models\ProductCategory;
use App\Support\LocalArea;

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

        // Static pages: [path, changefreq, priority]
        $staticPages = [
            ['/',         'weekly',  '1.0'],
            ['/products', 'weekly',  '0.9'],
            ['/order',    'monthly', '0.8'],
            ['/about',    'monthly', '0.6'],
            ['/contact',  'monthly', '0.6'],
        ];

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
        $xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        foreach ($staticPages as [$path, $changefreq, $priority]) {
            $xml .= self::urlBlock($base, $path, $changefreq, $priority);
        }

        foreach ($categories as $category) {
            $slug = $category['slug'] ?? '';
            if ($slug === '') {
                continue;
            }
            $xml .= self::urlBlock($base, '/products/' . $slug, 'weekly', '0.8');
        }

        // Local-SEO city landing pages + their hub (config/local-areas.php).
        foreach (LocalArea::allPaths(LocalArea::areas(), LocalArea::services()) as $path) {
            if ($path === '') {
                continue;
            }
            $priority = $path === '/delivery-areas' ? '0.7' : '0.6';
            $xml .= self::urlBlock($base, $path, 'monthly', $priority);
        }

        $xml .= '</urlset>' . "\n";

        return (new Response($xml, 200))
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * Build a <url> block with bilingual hreflang links for both en and es.
     *
     * @param string $base       The application base URL without trailing slash.
     * @param string $path       The path (e.g. '/products/bouquets'). Use '/' for home.
     * @param string $changefreq XML changefreq value.
     * @param string $priority   XML priority value.
     * @return string The <url>...</url> XML fragment (×2 for each language).
     */
    private static function urlBlock(
        string $base,
        string $path,
        string $changefreq,
        string $priority
    ): string {
        $enUrl = $base . '/en' . ($path === '/' ? '/' : $path);
        $esUrl = $base . '/es' . ($path === '/' ? '/' : $path);

        $block = '';
        foreach ([['en', $enUrl], ['es', $esUrl]] as [$lang, $loc]) {
            $block .= "\n  <url>\n";
            $block .= '    <loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>' . "\n";
            $block .= '    <xhtml:link rel="alternate" hreflang="en" href="' . htmlspecialchars($enUrl, ENT_XML1) . '"/>' . "\n";
            $block .= '    <xhtml:link rel="alternate" hreflang="es" href="' . htmlspecialchars($esUrl, ENT_XML1) . '"/>' . "\n";
            $block .= '    <changefreq>' . $changefreq . '</changefreq>' . "\n";
            $block .= '    <priority>' . $priority . '</priority>' . "\n";
            $block .= "  </url>\n";
        }
        return $block;
    }
}
