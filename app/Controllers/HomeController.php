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
            SELECT * FROM `sliders` 
            WHERE `is_active` = 1 
            ORDER BY `sort_order` ASC, `id` ASC
        ");

        $html = View::render('home/index', [
            'page_title' => 'فروشگاه تخصصی لوازم‌التحریر تحریرچی | خرید روان‌نویس، خودکار، دفتر و نقاشی',
            'meta_description' => 'بزرگترین فروشگاه آنلاین نوشت‌افزار، لوازم اداری، طراحی و دفتر با بهترین قیمت، اصالت کالا و ارسال سریع به سراسر ایران.',
            'featured_products' => $featuredProducts,
            'categories' => $categories,
            'sliders' => $sliders,
        ]);

        return Response::html($html);
    }
}
