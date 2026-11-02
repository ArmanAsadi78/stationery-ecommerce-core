<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;

class HomeController
{
    private ProductRepository $productRepo;
    private CategoryRepository $categoryRepo;

    public function __construct()
    {
        $this->productRepo = new ProductRepository();
        $this->categoryRepo = new CategoryRepository();
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

        $html = View::render('home/index', [
            'page_title' => 'فروشگاه تخصصی لوازم‌التحریر تحریرچی | خرید روان‌نویس، خودکار، دفتر و نقاشی',
            'meta_description' => 'بزرگترین فروشگاه آنلاین نوشت‌افزار، لوازم اداری، طراحی و دفتر با بهترین قیمت، اصالت کالا و ارسال سریع به سراسر ایران.',
            'featured_products' => $featuredProducts,
            'categories' => $categories,
        ]);

        return Response::html($html);
    }
}
