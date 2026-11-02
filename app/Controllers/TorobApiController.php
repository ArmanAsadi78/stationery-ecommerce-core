<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\CacheService;

class TorobApiController
{
    private Database $db;
    private CacheService $cache;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->cache = CacheService::torob();
    }

    /**
     * Official Torob Products Integration API Endpoint.
     * Route: GET /api/torob-products
     * 
     * @param array<string, string> $vars
     */
    public function index(Request $request, array $vars = []): Response
    {
        $page = max(1, (int) $request->getQuery('page', 1));
        $limit = min(100, max(1, (int) $request->getQuery('limit', 50)));

        $cacheKey = "torob_products_page_{$page}_limit_{$limit}";

        /** @var array{count: int, max_pages: int, products: list<array<string, mixed>>}|null $cachedData */
        $cachedData = $this->cache->get($cacheKey);

        if ($cachedData !== null) {
            $response = Response::json($cachedData);
            $response->setHeader('X-Cache', 'HIT');
            return $response;
        }

        // Cache Miss: Query Database
        $offset = ($page - 1) * $limit;

        // Count total active products
        $countRow = $this->db->fetch("SELECT COUNT(*) AS total FROM `products` WHERE `is_active` = 1");
        $totalCount = (int) ($countRow['total'] ?? 0);
        $maxPages = (int) ceil($totalCount / $limit);

        // Fetch paginated products with category details
        $sql = "
            SELECT 
                p.id, p.category_id, p.title, p.slug, p.sku, p.barcode, p.brand,
                p.summary, p.price, p.compare_at_price, p.stock_quantity,
                p.primary_image, p.updated_at,
                c.title AS category_title
            FROM `products` p
            JOIN `categories` c ON p.category_id = c.id
            WHERE p.is_active = 1
            ORDER BY p.id ASC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rawProducts */
        $rawProducts = $stmt->fetchAll();

        $torobProducts = [];

        foreach ($rawProducts as $product) {
            $productId = (int) $product['id'];

            // Fetch variants if available
            $variants = $this->db->fetchAll(
                "SELECT * FROM `product_variants` WHERE `product_id` = :id AND `is_active` = 1 ORDER BY `id` ASC",
                [':id' => $productId]
            );

            // Fetch gallery images
            $galleryImages = $this->db->fetchAll(
                "SELECT `webp_path` FROM `product_images` WHERE `product_id` = :id ORDER BY `sort_order` ASC",
                [':id' => $productId]
            );

            $imageLinks = [];
            if (!empty($product['primary_image'])) {
                $imageLinks[] = url((string) $product['primary_image'], true);
            }
            foreach ($galleryImages as $img) {
                $imgUrl = url((string) $img['webp_path'], true);
                if (!in_array($imgUrl, $imageLinks, true)) {
                    $imageLinks[] = $imgUrl;
                }
            }
            if (empty($imageLinks)) {
                $imageLinks[] = asset('images/placeholder.webp', true);
            }

            // Technical Specifications Map
            $spec = [];
            if (!empty($product['brand'])) {
                $spec['برند'] = (string) $product['brand'];
            }
            if (!empty($product['sku'])) {
                $spec['شناسه کالا (SKU)'] = (string) $product['sku'];
            }
            if (!empty($product['barcode'])) {
                $spec['بارکد'] = (string) $product['barcode'];
            }

            if (!empty($variants)) {
                $variantSummary = [];
                foreach ($variants as $v) {
                    $variantSummary[] = $v['attribute_name'] . ': ' . $v['attribute_value'];
                }
                $spec['تنوع'] = implode(' | ', $variantSummary);
            }

            $price = (int) $product['price'];
            $comparePrice = !empty($product['compare_at_price']) ? (int) $product['compare_at_price'] : null;
            $oldPrice = ($comparePrice !== null && $comparePrice > $price) ? $comparePrice : null;

            $stock = (int) $product['stock_quantity'];
            $availability = $stock > 0 ? 'instock' : 'outofstock';

            $torobProducts[] = [
                'page_unique_code' => (string) $productId,
                'product_id' => $productId,
                'title' => (string) $product['title'],
                'subtitle' => (string) ($product['summary'] ?? $product['brand'] ?? ''),
                'page_url' => url('products/' . $product['slug'], true),
                'price' => $price,
                'old_price' => $oldPrice,
                'availability' => $availability,
                'image_links' => $imageLinks,
                'category_name' => (string) $product['category_title'],
                'spec' => (object) $spec,
            ];
        }

        $payload = [
            'count' => $totalCount,
            'max_pages' => $maxPages,
            'products' => $torobProducts,
        ];

        // Store into Cache (30 min TTL = 1800s)
        $this->cache->set($cacheKey, $payload, 1800);

        $response = Response::json($payload);
        $response->setHeader('X-Cache', 'MISS');

        return $response;
    }
}
