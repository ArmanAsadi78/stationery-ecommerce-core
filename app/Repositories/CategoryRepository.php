<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

class CategoryRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAll(): array
    {
        $sql = "
            SELECT * 
            FROM `categories` 
            WHERE is_active = 1 
            ORDER BY sort_order ASC, id ASC
        ";

        return $this->db->fetchAll($sql);
    }

    /**
     * Build hierarchical categories tree.
     * 
     * @return list<array<string, mixed>>
     */
    public function getTree(): array
    {
        $all = $this->getAll();
        $indexed = [];
        $tree = [];

        foreach ($all as $item) {
            $item['children'] = [];
            $indexed[(int) $item['id']] = $item;
        }

        foreach ($indexed as $id => &$category) {
            $parentId = $category['parent_id'] !== null ? (int) $category['parent_id'] : null;
            if ($parentId !== null && isset($indexed[$parentId])) {
                $indexed[$parentId]['children'][] = &$category;
            } else {
                $tree[] = &$category;
            }
        }

        return $tree;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $sql = "
            SELECT * 
            FROM `categories` 
            WHERE slug = :slug AND is_active = 1 
            LIMIT 1
        ";

        return $this->db->fetch($sql, [':slug' => $slug]);
    }
}
