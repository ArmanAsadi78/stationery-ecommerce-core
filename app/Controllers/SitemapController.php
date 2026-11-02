<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\CacheService;

class SitemapController
{
    private Database $db;
    private CacheService $cache;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->cache = new CacheService('sitemap');
    }

    /**
     * Generate dynamic XML Sitemap conforming to sitemaps.org standard.
     * Route: GET /sitemap.xml
     * 
     * @param array<string, string> $vars
     */
    public function index(Request $request, array $vars = []): Response
    {
        $cacheKey = 'sitemap_xml_content';

        /** @var string|null $cachedXml */
        $cachedXml = $this->cache->get($cacheKey);

        if (is_string($cachedXml) && $cachedXml !== '') {
            $response = new Response($cachedXml, 200);
            $response->setHeader('Content-Type', 'application/xml; charset=UTF-8');
            $response->setHeader('X-Cache', 'HIT');
            return $response;
        }

        // Cache Miss: Generate Dynamic XML
        $xml = $this->generateSitemapXml();

        // Cache for 1 hour (3600s)
        $this->cache->set($cacheKey, $xml, 3600);

        $response = new Response($xml, 200);
        $response->setHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->setHeader('X-Cache', 'MISS');

        return $response;
    }

    private function generateSitemapXml(): string
    {
        $now = date('Y-m-d\TH:i:sP');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // 1. Homepage
        $xml .= "  <url>\n";
        $xml .= "    <loc>" . escape_html(url('/', true)) . "</loc>\n";
        $xml .= "    <lastmod>{$now}</lastmod>\n";
        $xml .= "    <changefreq>daily</changefreq>\n";
        $xml .= "    <priority>1.0</priority>\n";
        $xml .= "  </url>\n";

        // 2. Catalog Main Page
        $xml .= "  <url>\n";
        $xml .= "    <loc>" . escape_html(url('products', true)) . "</loc>\n";
        $xml .= "    <lastmod>{$now}</lastmod>\n";
        $xml .= "    <changefreq>daily</changefreq>\n";
        $xml .= "    <priority>0.8</priority>\n";
        $xml .= "  </url>\n";

        // 3. Categories
        $categories = $this->db->fetchAll("SELECT `slug`, `updated_at` FROM `categories` WHERE `is_active` = 1 ORDER BY `sort_order` ASC");
        foreach ($categories as $cat) {
            $catUrl = url('products?category=' . $cat['slug'], true);
            $modDate = !empty($cat['updated_at']) ? date('Y-m-d\TH:i:sP', strtotime((string) $cat['updated_at'])) : $now;

            $xml .= "  <url>\n";
            $xml .= "    <loc>" . escape_html($catUrl) . "</loc>\n";
            $xml .= "    <lastmod>{$modDate}</lastmod>\n";
            $xml .= "    <changefreq>weekly</changefreq>\n";
            $xml .= "    <priority>0.8</priority>\n";
            $xml .= "  </url>\n";
        }

        // 4. Products
        $products = $this->db->fetchAll("SELECT `slug`, `updated_at` FROM `products` WHERE `is_active` = 1 ORDER BY `id` DESC");
        foreach ($products as $prod) {
            $prodUrl = url('products/' . $prod['slug'], true);
            $modDate = !empty($prod['updated_at']) ? date('Y-m-d\TH:i:sP', strtotime((string) $prod['updated_at'])) : $now;

            $xml .= "  <url>\n";
            $xml .= "    <loc>" . escape_html($prodUrl) . "</loc>\n";
            $xml .= "    <lastmod>{$modDate}</lastmod>\n";
            $xml .= "    <changefreq>daily</changefreq>\n";
            $xml .= "    <priority>0.9</priority>\n";
            $xml .= "  </url>\n";
        }

        // 5. Public Trust & Information Pages
        $staticPages = [
            'about-us',
            'contact-us',
            'faq',
            'terms',
        ];

        foreach ($staticPages as $pageSlug) {
            $pageUrl = url($pageSlug, true);
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . escape_html($pageUrl) . "</loc>\n";
            $xml .= "    <lastmod>{$now}</lastmod>\n";
            $xml .= "    <changefreq>monthly</changefreq>\n";
            $xml .= "    <priority>0.6</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';

        return $xml;
    }
}
