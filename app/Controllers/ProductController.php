<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;

class ProductController
{
    private ProductRepository $productRepo;
    private CategoryRepository $categoryRepo;

    public function __construct()
    {
        $this->productRepo = new ProductRepository();
        $this->categoryRepo = new CategoryRepository();
    }

    /**
     * Product Catalog with filtering.
     * 
     * @param array<string, string> $vars
     */
    public function index(Request $request, array $vars = []): Response
    {
        $categorySlug = $request->getQuery('category');
        $search = $request->getQuery('q');
        $brand = $request->getQuery('brand');
        $page = (int) $request->getQuery('page', 1);

        $filters = [];
        if (is_string($categorySlug) && $categorySlug !== '') {
            $filters['category_slug'] = $categorySlug;
        }
        if (is_string($search) && $search !== '') {
            $filters['search'] = $search;
        }
        if (is_string($brand) && $brand !== '') {
            $filters['brand'] = $brand;
        }

        $catalogData = $this->productRepo->getCatalog($filters, $page, 12);
        $categories = $this->categoryRepo->getTree();

        $currentCategory = null;
        if (!empty($filters['category_slug'])) {
            $currentCategory = $this->categoryRepo->findBySlug($filters['category_slug']);
        }

        $title = $currentCategory ? 'خرید ' . $currentCategory['title'] : 'کاتالوگ محصولات لوازم‌التحریر';

        $html = View::render('products/index', [
            'page_title' => $title . ' | تحریرچی',
            'meta_description' => 'مشاهده و خرید آنلاین جدیدترین انواع ' . ($currentCategory['title'] ?? 'لوازم‌التحریر') . ' با بهترین کیفیت و قیمت مناسب.',
            'products' => $catalogData['products'],
            'total' => $catalogData['total'],
            'current_page' => $catalogData['page'],
            'total_pages' => $catalogData['total_pages'],
            'categories' => $categories,
            'current_category' => $currentCategory,
            'search_query' => is_string($search) ? $search : '',
        ]);

        return Response::html($html);
    }

    /**
     * Single Product Details with Schema.org JSON-LD SEO markup.
     * 
     * @param array<string, string> $vars
     */
    public function show(Request $request, array $vars = []): Response
    {
        $slug = $vars['slug'] ?? '';
        $product = $this->productRepo->findBySlug($slug);

        if (!$product) {
            $html = View::render('home/404', [
                'page_title' => 'محصول پیدا نشد',
                'message' => 'متأسفانه کالای درخواستی شما در فروشگاه موجود نیست یا حذف گردیده است.',
            ]);
            return Response::html($html, 404);
        }

        // Generate Schema.org Product JSON-LD for Search Engine Rich Snippets
        $schemaData = [
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => $product['title'],
            'image' => [url((string) ($product['primary_image'] ?? ''))],
            'description' => strip_tags((string) ($product['summary'] ?? $product['title'])),
            'sku' => $product['sku'],
            'brand' => [
                '@type' => 'Brand',
                'name' => $product['brand'] ?? 'تحریرچی',
            ],
            'offers' => [
                '@type' => 'Offer',
                'url' => url('products/' . $product['slug']),
                'priceCurrency' => 'IRR',
                'price' => (float) $product['price'] * 10, // Tomans to Rials standard for Googlebot
                'priceValidUntil' => date('Y-m-d', strtotime('+1 year')),
                'availability' => (int) $product['stock_quantity'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            ],
        ];

        $html = View::render('products/detail', [
            'page_title' => $product['title'] . ' | فروشگاه تحریرچی',
            'meta_description' => mb_substr(strip_tags((string) ($product['summary'] ?? $product['title'])), 0, 160),
            'product' => $product,
            'schema_json' => json_encode($schemaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return Response::html($html);
    }
}
