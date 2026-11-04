<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;

class HomeController
{
    private ProductRepository $productRepo;
    private CategoryRepository $categoryRepo;
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->productRepo = new ProductRepository();
        $this->categoryRepo = new CategoryRepository();
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Display Homepage with Featured Stationery Products.
     * 
     * @param array<string, string> $vars
     */
    public function index(Request $request, array $vars = []): Response
    {
        $featuredProducts = $this->productRepo->getFeatured(8);
        $categories = $this->categoryRepo->getTree();

        /** @var list<array<string, mixed>> $sliders */
        $sliders = $this->db->fetchAll("
            SELECT `id`, `title`, `image_path`, `image_path` AS `image_url`, `target_url`, `target_url` AS `link`, `sort_order`, `is_active`
            FROM `home_sliders` 
            WHERE `is_active` = 1 
            ORDER BY `sort_order` ASC, `id` ASC
        ");

        if (empty($sliders)) {
            /** @var list<array<string, mixed>> $sliders */
            $sliders = $this->db->fetchAll("
                SELECT `id`, `title`, `image_url` AS `image_path`, `image_url`, `link` AS `target_url`, `link`, `sort_order`, `is_active`
                FROM `sliders` 
                WHERE `is_active` = 1 
                ORDER BY `sort_order` ASC, `id` ASC
            ");
        }

        /** @var list<array<string, mixed>> $popularCategories */
        $popularCategories = $this->db->fetchAll("
            SELECT `id`, `parent_id`, `title`, `slug`, `icon_image`, `sort_order`
            FROM `categories`
            WHERE `is_active` = 1
            ORDER BY `sort_order` ASC, `id` ASC
            LIMIT 6
        ");

        /** @var list<array<string, mixed>> $rawPromoBanners */
        $rawPromoBanners = $this->db->fetchAll("
            SELECT `id`, `badge_text`, `title`, `description`, `button_text`, `target_url`, `image_path`, `position`, `is_active`
            FROM `promo_banners`
            WHERE `is_active` = 1
        ");

        /** @var array<string, array<string, mixed>> $promoBanners */
        $promoBanners = [];
        foreach ($rawPromoBanners as $banner) {
            $promoBanners[(string) $banner['position']] = $banner;
        }

        $html = View::render('home/index', [
            'page_title' => 'فروشگاه تخصصی لوازم‌التحریر تحریرچی | خرید روان‌نویس، خودکار، دفتر و نقاشی',
            'meta_description' => 'بزرگترین فروشگاه آنلاین نوشت‌افزار، لوازم اداری، طراحی و دفتر با بهترین قیمت، اصالت کالا و ارسال سریع به سراسر ایران.',
            'featured_products' => $featuredProducts,
            'categories' => $categories,
            'popular_categories' => $popularCategories,
            'promo_banners' => $promoBanners,
            'sliders' => $sliders,
        ]);

        return Response::html($html);
    }
}
