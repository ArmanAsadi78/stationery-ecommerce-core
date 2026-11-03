<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

class MenuService
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $cachedHeaderMenus = null;

    /**
     * Retrieve active header navigation menus sorted by sort_order.
     *
     * @return list<array<string, mixed>>
     */
    public static function getHeaderMenus(): array
    {
        if (self::$cachedHeaderMenus !== null) {
            return self::$cachedHeaderMenus;
        }

        /** @var list<array<string, mixed>> $defaultMenus */
        $defaultMenus = [
            ['id' => 1, 'title' => 'صفحه اصلی', 'url' => '/', 'sort_order' => 1, 'is_active' => 1, 'location' => 'header'],
            ['id' => 2, 'title' => 'تخفیف‌های شگفت‌انگیز', 'url' => '/products', 'sort_order' => 2, 'is_active' => 1, 'location' => 'header'],
            ['id' => 3, 'title' => 'درباره ما', 'url' => '/about-us', 'sort_order' => 3, 'is_active' => 1, 'location' => 'header'],
            ['id' => 4, 'title' => 'تماس با ما', 'url' => '/contact-us', 'sort_order' => 4, 'is_active' => 1, 'location' => 'header'],
        ];

        try {
            $db = Database::getInstance();
            /** @var list<array<string, mixed>> $rows */
            $rows = $db->fetchAll("
                SELECT `id`, `title`, `url`, `sort_order`, `is_active`, `location`
                FROM `navigation_menus`
                WHERE `location` = 'header' AND `is_active` = 1
                ORDER BY `sort_order` ASC, `id` ASC
            ");

            if (!empty($rows)) {
                self::$cachedHeaderMenus = $rows;
            } else {
                self::$cachedHeaderMenus = $defaultMenus;
            }
        } catch (Throwable) {
            self::$cachedHeaderMenus = $defaultMenus;
        }

        return self::$cachedHeaderMenus;
    }

    /**
     * Flush memory cache.
     */
    public static function flush(): void
    {
        self::$cachedHeaderMenus = null;
    }
}
