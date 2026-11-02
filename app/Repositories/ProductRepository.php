<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

class ProductRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get featured products with their category details (Eager Loaded).
     * 
     * @return list<array<string, mixed>>
     */
    public function getFeatured(int $limit = 8): array
    {
        $sql = "
            SELECT 
                p.id, p.category_id, p.title, p.slug, p.sku, p.brand, 
                p.summary, p.price, p.compare_at_price, p.stock_quantity, 
                p.primary_image, p.is_featured,
                c.title AS category_title, c.slug AS category_slug
            FROM `products` p
            JOIN `categories` c ON p.category_id = c.id
            WHERE p.is_active = 1 AND p.is_featured = 1
            ORDER BY p.id DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $products */
        $products = $stmt->fetchAll();

        return $this->attachVariantsBatch($products);
    }

    /**
     * Fetch catalog products with filtering and pagination.
     * 
     * @param array<string, mixed> $filters
     * @return array{products: list<array<string, mixed>>, total: int, page: int, total_pages: int}
     */
    public function getCatalog(array $filters = [], int $page = 1, int $perPage = 12): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $conditions = ["p.is_active = 1"];
        $params = [];

        if (!empty($filters['category_slug'])) {
            $conditions[] = "(c.slug = :cat_slug OR c.parent_id = (SELECT id FROM categories WHERE slug = :cat_slug_sub LIMIT 1))";
            $params[':cat_slug'] = $filters['category_slug'];
            $params[':cat_slug_sub'] = $filters['category_slug'];
        }

        if (!empty($filters['brand'])) {
            $conditions[] = "p.brand = :brand";
            $params[':brand'] = $filters['brand'];
        }

        if (!empty($filters['search'])) {
            $conditions[] = "(p.title LIKE :search OR p.summary LIKE :search OR p.brand LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = implode(' AND ', $conditions);

        // Count total matching items
        $countSql = "
            SELECT COUNT(*) 
            FROM `products` p
            JOIN `categories` c ON p.category_id = c.id
            WHERE {$whereClause}
        ";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch paginated products
        $sql = "
            SELECT 
                p.id, p.category_id, p.title, p.slug, p.sku, p.brand, 
                p.summary, p.price, p.compare_at_price, p.stock_quantity, 
                p.primary_image, p.is_featured,
                c.title AS category_title, c.slug AS category_slug
            FROM `products` p
            JOIN `categories` c ON p.category_id = c.id
            WHERE {$whereClause}
            ORDER BY p.id DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $products */
        $products = $stmt->fetchAll();
        $products = $this->attachVariantsBatch($products);

        return [
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Find single product by its Persian/English slug with full Eager Loaded relations.
     * 
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $sql = "
            SELECT 
                p.*,
                c.title AS category_title, c.slug AS category_slug
            FROM `products` p
            JOIN `categories` c ON p.category_id = c.id
            WHERE p.slug = :slug AND p.is_active = 1
            LIMIT 1
        ";

        $product = $this->db->fetch($sql, [':slug' => $slug]);
        if (!$product) {
            return null;
        }

        $productId = (int) $product['id'];

        // Eager load variants
        $product['variants'] = $this->getVariants($productId);

        // Eager load gallery images
        $product['images'] = $this->getImages($productId);

        return $product;
    }

    /**
     * Find single product by ID.
     * 
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $sql = "
            SELECT 
                p.*,
                c.title AS category_title, c.slug AS category_slug
            FROM `products` p
            JOIN `categories` c ON p.category_id = c.id
            WHERE p.id = :id AND p.is_active = 1
            LIMIT 1
        ";

        $product = $this->db->fetch($sql, [':id' => $id]);
        if (!$product) {
            return null;
        }

        $productId = (int) $product['id'];
        $product['variants'] = $this->getVariants($productId);
        $product['images'] = $this->getImages($productId);

        return $product;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getVariants(int $productId): array
    {
        $sql = "
            SELECT * 
            FROM `product_variants` 
            WHERE product_id = :product_id AND is_active = 1 
            ORDER BY id ASC
        ";

        return $this->db->fetchAll($sql, [':product_id' => $productId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getImages(int $productId): array
    {
        $sql = "
            SELECT * 
            FROM `product_images` 
            WHERE product_id = :product_id 
            ORDER BY sort_order ASC, id ASC
        ";

        return $this->db->fetchAll($sql, [':product_id' => $productId]);
    }

    /**
     * Batch eager loader for product variants to prevent N+1 queries.
     * 
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    private function attachVariantsBatch(array $products): array
    {
        if (empty($products)) {
            return [];
        }

        $ids = array_map(fn($p) => (int) $p['id'], $products);
        $inClause = implode(',', $ids);

        $sql = "
            SELECT * 
            FROM `product_variants` 
            WHERE product_id IN ({$inClause}) AND is_active = 1
            ORDER BY id ASC
        ";

        $variants = $this->db->fetchAll($sql);
        $variantMap = [];
        foreach ($variants as $v) {
            $pId = (int) $v['product_id'];
            $variantMap[$pId][] = $v;
        }

        foreach ($products as &$product) {
            $pId = (int) $product['id'];
            $product['variants'] = $variantMap[$pId] ?? [];
        }

        return $products;
    }
}
