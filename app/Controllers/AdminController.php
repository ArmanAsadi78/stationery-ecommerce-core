<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\AuthService;
use App\Services\CacheService;
use App\Services\MenuService;
use App\Services\SettingService;

class AdminController
{
    private AuthService $auth;
    private Database $db;

    public function __construct(?AuthService $auth = null, ?Database $db = null)
    {
        $this->auth = $auth ?? new AuthService();
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Show dedicated Admin Login form.
     * Route: GET /admin/login
     * 
     * @param array<string, string> $vars
     */
    public function showLogin(Request $request, array $vars = []): Response
    {
        if ($this->auth->check()) {
            $user = $this->auth->user();
            if ($user && ($user['role'] ?? '') === 'admin') {
                return Response::redirect(url('admin/dashboard'));
            }
        }

        $html = View::render('admin/login', [
            'page_title' => 'ورود به پنل مدیریت سامانه | تحریرچی',
            'error_message' => Session::getFlash('error'),
            'success_message' => Session::getFlash('success'),
        ]);

        return Response::html($html);
    }

    /**
     * Handle Admin Login attempt.
     * Route: POST /admin/login
     * 
     * @param array<string, string> $vars
     */
    public function login(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            if ($request->isAjax()) {
                return Response::json(['success' => false, 'message' => 'توکن امنیتی نامعتبر است.'], 403);
            }
            Session::setFlash('error', 'توکن امنیتی معتبر نیست. لطفاً مجدداً تلاش فرمایید.');
            return Response::redirect(url('admin/login'));
        }

        $identifier = trim((string) $request->input('identifier', ''));
        $password = (string) $request->input('password', '');

        $result = $this->auth->login($identifier, $password);

        if (!$result['success']) {
            if ($request->isAjax()) {
                return Response::json($result, 422);
            }
            Session::setFlash('error', $result['message']);
            return Response::redirect(url('admin/login'));
        }

        // Verify that the user has the 'admin' role
        $user = $this->auth->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            $this->auth->logout();
            $err = 'دسترسی غیرمجاز: حساب کاربری شما اجازه ورود به پنل مدیریت را ندارد.';
            if ($request->isAjax()) {
                return Response::json(['success' => false, 'message' => $err], 403);
            }
            Session::setFlash('error', $err);
            return Response::redirect(url('admin/login'));
        }

        /** @var string|null $intendedUrl */
        $intendedUrl = Session::get('intended_url');
        Session::remove('intended_url');
        $redirectUrl = $intendedUrl !== null && $intendedUrl !== '' ? url(ltrim($intendedUrl, '/')) : url('admin/dashboard');

        if ($request->isAjax()) {
            return Response::json([
                'success' => true,
                'message' => 'خوش آمدید! ورود به پنل مدیریت با موفقیت انجام شد.',
                'redirect' => $redirectUrl,
            ], 200);
        }

        Session::setFlash('success', 'ورود به پنل مدیریت با موفقیت انجام شد.');
        return Response::redirect($redirectUrl);
    }

    /**
     * Admin Dashboard Overview & Real-time Metrics.
     * Route: GET /admin/dashboard
     * 
     * @param array<string, string> $vars
     */
    public function dashboard(Request $request, array $vars = []): Response
    {
        $adminUser = $this->auth->user();

        // 1. Core Financial and Order Aggregates
        $metrics = $this->db->fetch("
            SELECT 
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN `status` NOT IN ('cancelled', 'refunded') THEN `total_amount` ELSE 0 END), 0) AS total_revenue,
                SUM(CASE WHEN `status` IN ('pending_payment', 'processing') THEN 1 ELSE 0 END) AS pending_orders,
                SUM(CASE WHEN `status` = 'shipped' THEN 1 ELSE 0 END) AS shipped_orders,
                SUM(CASE WHEN `status` = 'delivered' THEN 1 ELSE 0 END) AS delivered_orders
            FROM `orders`
        ");

        // 2. Customers and Products Count
        $customerCountRow = $this->db->fetch("SELECT COUNT(*) AS total FROM `users` WHERE `role` = 'customer'");
        $totalCustomers = (int) ($customerCountRow['total'] ?? 0);

        $productCountRow = $this->db->fetch("SELECT COUNT(*) AS total FROM `products` WHERE `is_active` = 1");
        $totalProducts = (int) ($productCountRow['total'] ?? 0);

        // 3. Low Stock Alert Products
        /** @var list<array<string, mixed>> $lowStockItems */
        $lowStockItems = $this->db->fetchAll("
            SELECT `id`, `title`, `sku`, `stock_quantity`, `price`
            FROM `products`
            WHERE `stock_quantity` <= 5 AND `is_active` = 1
            ORDER BY `stock_quantity` ASC
            LIMIT 5
        ");

        // 4. Latest Orders
        /** @var list<array<string, mixed>> $recentOrders */
        $recentOrders = $this->db->fetchAll("
            SELECT o.`id`, o.`order_number`, o.`recipient_name`, o.`status`, o.`total_amount`, o.`created_at`,
                   u.`email` AS customer_email
            FROM `orders` o
            LEFT JOIN `users` u ON o.`user_id` = u.`id`
            ORDER BY o.`created_at` DESC
            LIMIT 8
        ");

        $pageTitle = 'داشبورد مدیریت سامانه | تحریرچی';
        $isPjax = $request->isPjax();

        $html = View::render('admin/dashboard', [
            'page_title' => $pageTitle,
            'admin_user' => $adminUser,
            'active_menu' => 'dashboard',
            'is_pjax' => $isPjax,
            'metrics' => [
                'total_orders' => (int) ($metrics['total_orders'] ?? 0),
                'total_revenue' => (float) ($metrics['total_revenue'] ?? 0),
                'pending_orders' => (int) ($metrics['pending_orders'] ?? 0),
                'shipped_orders' => (int) ($metrics['shipped_orders'] ?? 0),
                'delivered_orders' => (int) ($metrics['delivered_orders'] ?? 0),
                'total_customers' => $totalCustomers,
                'total_products' => $totalProducts,
            ],
            'low_stock_items' => $lowStockItems,
            'recent_orders' => $recentOrders,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        if ($isPjax) {
            return Response::json([
                'success' => true,
                'html' => $html,
                'title' => $pageTitle,
                'active_menu' => 'dashboard',
            ]);
        }

        return Response::html($html);
    }

    /**
     * Orders Management List with Filtering and Search.
     * Route: GET /admin/orders
     * 
     * @param array<string, string> $vars
     */
    public function orders(Request $request, array $vars = []): Response
    {
        $adminUser = $this->auth->user();

        $statusFilter = trim((string) $request->getQuery('status', ''));
        $searchQuery = trim((string) $request->getQuery('q', ''));
        $page = max(1, (int) $request->getQuery('page', 1));
        $perPage = 20;

        $conditions = ["1=1"];
        $params = [];

        $allowedStatuses = ['pending_payment', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];
        if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
            $conditions[] = "o.`status` = :status";
            $params[':status'] = $statusFilter;
        }

        if ($searchQuery !== '') {
            $conditions[] = "(o.`order_number` LIKE :q_order OR o.`recipient_name` LIKE :q_recip OR o.`recipient_phone` LIKE :q_phone)";
            $qVal = '%' . $searchQuery . '%';
            $params[':q_order'] = $qVal;
            $params[':q_recip'] = $qVal;
            $params[':q_phone'] = $qVal;
        }

        $whereSql = implode(' AND ', $conditions);

        // Count total matching orders for pagination
        $countRow = $this->db->fetch("
            SELECT COUNT(DISTINCT o.`id`) AS total
            FROM `orders` o
            WHERE {$whereSql}
        ", $params);
        $totalOrders = (int) ($countRow['total'] ?? 0);
        $totalPages = max(1, (int) ceil($totalOrders / $perPage));
        if ($page > $totalPages && $totalOrders > 0) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        /** @var list<array<string, mixed>> $orders */
        $orders = $this->db->fetchAll("
            SELECT o.`id`, o.`order_number`, o.`status`, o.`subtotal`, o.`discount_amount`, 
                   o.`shipping_cost`, o.`total_amount`, o.`recipient_name`, o.`recipient_phone`, 
                   o.`customer_notes`, o.`created_at`,
                   COUNT(oi.`id`) AS items_count,
                   p.`reference_id` AS payment_tracking
            FROM `orders` o
            LEFT JOIN `order_items` oi ON o.`id` = oi.`order_id`
            LEFT JOIN `payments` p ON o.`id` = p.`order_id`
            WHERE {$whereSql}
            GROUP BY o.`id`
            ORDER BY o.`created_at` DESC
            LIMIT {$perPage} OFFSET {$offset}
        ", $params);

        $pageTitle = 'مدیریت و پیگیری سفارش‌ها | پنل ادمین';
        $isPjax = $request->isPjax();

        $html = View::render('admin/orders', [
            'page_title' => $pageTitle,
            'admin_user' => $adminUser,
            'active_menu' => 'orders',
            'is_pjax' => $isPjax,
            'orders' => $orders,
            'current_status' => $statusFilter,
            'search_query' => $searchQuery,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_orders' => $totalOrders,
            'per_page' => $perPage,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        if ($isPjax) {
            return Response::json([
                'success' => true,
                'html' => $html,
                'title' => $pageTitle,
                'active_menu' => 'orders',
            ]);
        }

        return Response::html($html);
    }

    /**
     * View Single Order in Admin Panel.
     * Route: GET /admin/orders/{id}
     * 
     * @param array<string, string> $vars
     */
    public function orderDetail(Request $request, array $vars = []): Response
    {
        $adminUser = $this->auth->user();
        $orderId = (int) ($vars['id'] ?? 0);

        /** @var array<string, mixed>|null $order */
        $order = $this->db->fetch("
            SELECT o.*, u.`full_name` AS customer_name, u.`email` AS customer_email, u.`phone` AS customer_phone
            FROM `orders` o
            LEFT JOIN `users` u ON o.`user_id` = u.`id`
            WHERE o.`id` = :id
            LIMIT 1
        ", [':id' => $orderId]);

        if (!$order) {
            Session::setFlash('error', 'سفارش مورد نظر در سیستم یافت نشد.');
            return Response::redirect(url('admin/orders'));
        }

        /** @var list<array<string, mixed>> $items */
        $items = $this->db->fetchAll("
            SELECT oi.*, p.`slug`, p.`sku`, p.`primary_image`
            FROM `order_items` oi
            LEFT JOIN `products` p ON oi.`product_id` = p.`id`
            WHERE oi.`order_id` = :id
        ", [':id' => $orderId]);

        /** @var array<string, mixed>|null $payment */
        $payment = $this->db->fetch("
            SELECT * FROM `payments` WHERE `order_id` = :id ORDER BY `id` DESC LIMIT 1
        ", [':id' => $orderId]);

        $pageTitle = 'جزئیات سفارش #' . $order['order_number'] . ' | پنل مدیریت';
        $isPjax = $request->isPjax();

        $html = View::render('admin/order_detail', [
            'page_title' => $pageTitle,
            'admin_user' => $adminUser,
            'active_menu' => 'orders',
            'is_pjax' => $isPjax,
            'order' => $order,
            'items' => $items,
            'payment' => $payment,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        if ($isPjax) {
            return Response::json([
                'success' => true,
                'html' => $html,
                'title' => $pageTitle,
                'active_menu' => 'orders',
            ]);
        }

        return Response::html($html);
    }

    /**
     * Update Order Status and Post Tracking Details.
     * Route: POST /admin/orders/{id}/status
     * 
     * @param array<string, string> $vars
     */
    public function updateOrderStatus(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            Session::setFlash('error', 'توکن امنیتی نامعتبر است.');
            return Response::redirect(url('admin/orders'));
        }

        $orderId = (int) ($vars['id'] ?? 0);
        $newStatus = trim((string) $request->input('status', ''));
        $notes = trim((string) $request->input('customer_notes', ''));

        $allowedStatuses = ['pending_payment', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            Session::setFlash('error', 'وضعیت سفارش انتخاب‌شده معتبر نیست.');
            return Response::redirect(url('admin/orders/' . $orderId));
        }

        // Fetch current order status
        $order = $this->db->fetch("SELECT `id`, `status` FROM `orders` WHERE `id` = :id LIMIT 1", [':id' => $orderId]);
        if (!$order) {
            Session::setFlash('error', 'سفارش یافت نشد.');
            return Response::redirect(url('admin/orders'));
        }

        $this->db->execute("
            UPDATE `orders` 
            SET `status` = :status, `customer_notes` = :notes, `updated_at` = NOW() 
            WHERE `id` = :id
        ", [
            ':status' => $newStatus,
            ':notes' => $notes !== '' ? $notes : null,
            ':id' => $orderId,
        ]);

        Session::setFlash('success', "وضعیت سفارش به «{$newStatus}» تغییر یافت و کد رهگیری ذخیره گردید.");
        return Response::redirect(url('admin/orders/' . $orderId));
    }

    /**
     * Safely logout admin user.
     * Route: POST|GET /admin/logout
     * 
     * @param array<string, string> $vars
     */
    public function logout(Request $request, array $vars = []): Response
    {
        $this->auth->logout();
        Session::setFlash('success', 'با موفقیت از پنل مدیریت خارج شدید.');
        return Response::redirect(url('admin/login'));
    }

    /**
     * Products Catalog Management List with Search and Filtering.
     * Route: GET /admin/products
     * 
     * @param array<string, string> $vars
     */
    public function products(Request $request, array $vars = []): Response
    {
        $adminUser = $this->auth->user();

        $searchQuery = trim((string) $request->getQuery('q', ''));
        $categoryId = (int) $request->getQuery('category_id', 0);
        $stockFilter = trim((string) $request->getQuery('stock', ''));
        $page = max(1, (int) $request->getQuery('page', 1));
        $perPage = 20;

        $conditions = ["1=1"];
        $params = [];

        if ($searchQuery !== '') {
            $conditions[] = "(p.`title` LIKE :q_title OR p.`sku` LIKE :q_sku OR p.`brand` LIKE :q_brand)";
            $qVal = '%' . $searchQuery . '%';
            $params[':q_title'] = $qVal;
            $params[':q_sku'] = $qVal;
            $params[':q_brand'] = $qVal;
        }

        if ($categoryId > 0) {
            $conditions[] = "p.`category_id` = :cat_id";
            $params[':cat_id'] = $categoryId;
        }

        if ($stockFilter === 'low') {
            $conditions[] = "p.`stock_quantity` > 0 AND p.`stock_quantity` <= 5";
        } elseif ($stockFilter === 'out') {
            $conditions[] = "p.`stock_quantity` <= 0";
        } elseif ($stockFilter === 'in') {
            $conditions[] = "p.`stock_quantity` > 5";
        }

        $whereSql = implode(' AND ', $conditions);

        // Count total products
        $countRow = $this->db->fetch("
            SELECT COUNT(*) AS total
            FROM `products` p
            WHERE {$whereSql}
        ", $params);
        $totalProducts = (int) ($countRow['total'] ?? 0);
        $totalPages = max(1, (int) ceil($totalProducts / $perPage));
        if ($page > $totalPages && $totalProducts > 0) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        /** @var list<array<string, mixed>> $products */
        $products = $this->db->fetchAll("
            SELECT p.`id`, p.`category_id`, p.`title`, p.`slug`, p.`sku`, p.`brand`,
                   p.`price`, p.`compare_at_price`, p.`stock_quantity`, p.`primary_image`,
                   p.`is_active`, p.`is_featured`, p.`created_at`,
                   c.`title` AS category_title
            FROM `products` p
            LEFT JOIN `categories` c ON p.`category_id` = c.`id`
            WHERE {$whereSql}
            ORDER BY p.`id` DESC
            LIMIT {$perPage} OFFSET {$offset}
        ", $params);

        /** @var list<array<string, mixed>> $categories */
        $categories = $this->db->fetchAll("
            SELECT `id`, `title` FROM `categories` ORDER BY `sort_order` ASC, `title` ASC
        ");

        $pageTitle = 'مدیریت محصولات و کالاها | پنل ادمین';
        $isPjax = $request->isPjax();

        $html = View::render('admin/products', [
            'page_title' => $pageTitle,
            'admin_user' => $adminUser,
            'active_menu' => 'products',
            'is_pjax' => $isPjax,
            'products' => $products,
            'categories' => $categories,
            'search_query' => $searchQuery,
            'selected_category' => $categoryId,
            'stock_filter' => $stockFilter,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_products' => $totalProducts,
            'per_page' => $perPage,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        if ($isPjax) {
            return Response::json([
                'success' => true,
                'html' => $html,
                'title' => $pageTitle,
                'active_menu' => 'products',
            ]);
        }

        return Response::html($html);
    }

    /**
     * Show Create Product Form.
     * Route: GET /admin/products/create
     * 
     * @param array<string, string> $vars
     */
    public function createProduct(Request $request, array $vars = []): Response
    {
        $adminUser = $this->auth->user();

        /** @var list<array<string, mixed>> $categories */
        $categories = $this->db->fetchAll("
            SELECT `id`, `title` FROM `categories` ORDER BY `sort_order` ASC, `title` ASC
        ");

        $pageTitle = 'افزودن محصول جدید | پنل مدیریت تحریرچی';
        $isPjax = $request->isPjax();

        $html = View::render('admin/product_form', [
            'page_title' => $pageTitle,
            'admin_user' => $adminUser,
            'active_menu' => 'products',
            'is_pjax' => $isPjax,
            'is_edit' => false,
            'product' => null,
            'categories' => $categories,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        if ($isPjax) {
            return Response::json([
                'success' => true,
                'html' => $html,
                'title' => $pageTitle,
                'active_menu' => 'products',
            ]);
        }

        return Response::html($html);
    }

    /**
     * Store Newly Created Product.
     * Route: POST /admin/products
     * 
     * @param array<string, string> $vars
     */
    public function storeProduct(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            Session::setFlash('error', 'توکن امنیتی نامعتبر است. لطفاً مجدداً تلاش فرمایید.');
            return Response::redirect(url('admin/products/create'));
        }

        $title = trim((string) ($request->input('name') ?? $request->input('title') ?? ''));
        $categoryId = (int) ($request->input('category_id') ?? $request->input('category') ?? 0);
        $rawPrice = (string) $request->input('price', '0');
        $price = (int) preg_replace('/[^0-9]/', '', $rawPrice);
        $rawOldPrice = (string) ($request->input('old_price') ?? $request->input('compare_at_price') ?? '');
        $compareAtPrice = $rawOldPrice !== '' ? (int) preg_replace('/[^0-9]/', '', $rawOldPrice) : null;
        $stock = (int) ($request->input('stock') ?? $request->input('stock_quantity') ?? 0);
        $brand = trim((string) $request->input('brand', ''));
        $skuInput = trim((string) $request->input('sku', ''));
        $summary = trim((string) $request->input('summary', ''));
        $description = trim((string) $request->input('description', ''));
        $spec = trim((string) ($request->input('spec') ?? $request->input('specifications') ?? ''));
        $isActive = $request->input('is_active') ? 1 : 0;
        $isFeatured = $request->input('is_featured') ? 1 : 0;

        if ($title === '') {
            Session::setFlash('error', 'نام یا عنوان محصول الزامی است.');
            return Response::redirect(url('admin/products/create'));
        }

        if ($categoryId <= 0) {
            Session::setFlash('error', 'انتخاب دسته‌بندی کالا الزامی است.');
            return Response::redirect(url('admin/products/create'));
        }

        if ($price <= 0) {
            Session::setFlash('error', 'قیمت محصول باید عددی معتبر و بزرگتر از صفر باشد.');
            return Response::redirect(url('admin/products/create'));
        }

        // Slug Generation & Uniqueness
        $customSlug = trim((string) $request->input('slug', ''));
        $baseSlug = $customSlug !== '' ? $this->slugify($customSlug) : $this->slugify($title);
        $slug = $this->ensureUniqueSlug($baseSlug);

        // SKU Generation & Uniqueness
        $sku = $skuInput !== '' ? $skuInput : 'TAH-' . strtoupper(substr(md5(uniqid((string) time(), true)), 0, 8));

        // Image Upload
        $uploadedImagePath = $this->handleImageUpload($request, $slug);
        $primaryImage = $uploadedImagePath ?? '/assets/images/placeholder.webp';

        $this->db->execute("
            INSERT INTO `products` (
                `category_id`, `title`, `slug`, `sku`, `brand`, `summary`, `description`, 
                `specifications`, `price`, `compare_at_price`, `stock_quantity`, `primary_image`,
                `is_active`, `is_featured`
            ) VALUES (
                :category_id, :title, :slug, :sku, :brand, :summary, :description,
                :specifications, :price, :compare_at_price, :stock_quantity, :primary_image,
                :is_active, :is_featured
            )
        ", [
            ':category_id' => $categoryId,
            ':title' => $title,
            ':slug' => $slug,
            ':sku' => $sku,
            ':brand' => $brand !== '' ? $brand : null,
            ':summary' => $summary !== '' ? $summary : null,
            ':description' => $description !== '' ? $description : null,
            ':specifications' => $spec !== '' ? $spec : null,
            ':price' => $price,
            ':compare_at_price' => ($compareAtPrice !== null && $compareAtPrice > 0) ? $compareAtPrice : null,
            ':stock_quantity' => max(0, $stock),
            ':primary_image' => $primaryImage,
            ':is_active' => $isActive,
            ':is_featured' => $isFeatured,
        ]);

        // Invalidate Torob API & Sitemap Cache
        CacheService::flushTorob();
        CacheService::flushSitemap();

        Session::setFlash('success', "محصول جدید «{$title}» با موفقیت افزوده شد و تغییرات در وب‌سرویس‌ها منعکس گردید.");
        return Response::redirect(url('admin/products'));
    }

    /**
     * Show Edit Product Form.
     * Route: GET /admin/products/{id}/edit
     * 
     * @param array<string, string> $vars
     */
    public function editProduct(Request $request, array $vars = []): Response
    {
        $adminUser = $this->auth->user();
        $productId = (int) ($vars['id'] ?? 0);

        /** @var array<string, mixed>|null $product */
        $product = $this->db->fetch("SELECT * FROM `products` WHERE `id` = :id LIMIT 1", [':id' => $productId]);
        if (!$product) {
            Session::setFlash('error', 'محصول مورد نظر یافت نشد.');
            return Response::redirect(url('admin/products'));
        }

        /** @var list<array<string, mixed>> $categories */
        $categories = $this->db->fetchAll("
            SELECT `id`, `title` FROM `categories` ORDER BY `sort_order` ASC, `title` ASC
        ");

        $pageTitle = 'ویرایش محصول: ' . $product['title'] . ' | پنل مدیریت';
        $isPjax = $request->isPjax();

        $html = View::render('admin/product_form', [
            'page_title' => $pageTitle,
            'admin_user' => $adminUser,
            'active_menu' => 'products',
            'is_pjax' => $isPjax,
            'is_edit' => true,
            'product' => $product,
            'categories' => $categories,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        if ($isPjax) {
            return Response::json([
                'success' => true,
                'html' => $html,
                'title' => $pageTitle,
                'active_menu' => 'products',
            ]);
        }

        return Response::html($html);
    }

    /**
     * Update Product Details.
     * Route: POST /admin/products/{id}
     * 
     * @param array<string, string> $vars
     */
    public function updateProduct(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        $productId = (int) ($vars['id'] ?? 0);

        if (!Session::validateCsrf($csrfToken)) {
            Session::setFlash('error', 'توکن امنیتی نامعتبر است.');
            return Response::redirect(url('admin/products/' . $productId . '/edit'));
        }

        /** @var array<string, mixed>|null $product */
        $product = $this->db->fetch("SELECT * FROM `products` WHERE `id` = :id LIMIT 1", [':id' => $productId]);
        if (!$product) {
            Session::setFlash('error', 'محصول یافت نشد.');
            return Response::redirect(url('admin/products'));
        }

        $title = trim((string) ($request->input('name') ?? $request->input('title') ?? ''));
        $categoryId = (int) ($request->input('category_id') ?? $request->input('category') ?? 0);
        $rawPrice = (string) $request->input('price', '0');
        $price = (int) preg_replace('/[^0-9]/', '', $rawPrice);
        $rawOldPrice = (string) ($request->input('old_price') ?? $request->input('compare_at_price') ?? '');
        $compareAtPrice = $rawOldPrice !== '' ? (int) preg_replace('/[^0-9]/', '', $rawOldPrice) : null;
        $stock = (int) ($request->input('stock') ?? $request->input('stock_quantity') ?? 0);
        $brand = trim((string) $request->input('brand', ''));
        $sku = trim((string) $request->input('sku', ''));
        $summary = trim((string) $request->input('summary', ''));
        $description = trim((string) $request->input('description', ''));
        $spec = trim((string) ($request->input('spec') ?? $request->input('specifications') ?? ''));
        $isActive = $request->input('is_active') !== null ? ($request->input('is_active') ? 1 : 0) : 1;
        $isFeatured = $request->input('is_featured') ? 1 : 0;

        if ($title === '') {
            Session::setFlash('error', 'عنوان محصول نمی‌تواند خالی باشد.');
            return Response::redirect(url('admin/products/' . $productId . '/edit'));
        }

        if ($categoryId <= 0) {
            Session::setFlash('error', 'دسته‌بندی کالا معتبر نیست.');
            return Response::redirect(url('admin/products/' . $productId . '/edit'));
        }

        if ($price <= 0) {
            Session::setFlash('error', 'قیمت محصول باید عددی مثبت باشد.');
            return Response::redirect(url('admin/products/' . $productId . '/edit'));
        }

        // Slug handling
        $customSlug = trim((string) $request->input('slug', ''));
        $baseSlug = $customSlug !== '' ? $this->slugify($customSlug) : $this->slugify($title);
        $slug = $this->ensureUniqueSlug($baseSlug, $productId);

        if ($sku === '') {
            $sku = (string) $product['sku'];
        }

        // Image Handling
        $newUploadedImage = $this->handleImageUpload($request, $slug);
        $primaryImage = $newUploadedImage ?? $product['primary_image'];

        $this->db->execute("
            UPDATE `products` SET
                `category_id` = :category_id,
                `title` = :title,
                `slug` = :slug,
                `sku` = :sku,
                `brand` = :brand,
                `summary` = :summary,
                `description` = :description,
                `specifications` = :specifications,
                `price` = :price,
                `compare_at_price` = :compare_at_price,
                `stock_quantity` = :stock_quantity,
                `primary_image` = :primary_image,
                `is_active` = :is_active,
                `is_featured` = :is_featured,
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
        ", [
            ':category_id' => $categoryId,
            ':title' => $title,
            ':slug' => $slug,
            ':sku' => $sku,
            ':brand' => $brand !== '' ? $brand : null,
            ':summary' => $summary !== '' ? $summary : null,
            ':description' => $description !== '' ? $description : null,
            ':specifications' => $spec !== '' ? $spec : null,
            ':price' => $price,
            ':compare_at_price' => ($compareAtPrice !== null && $compareAtPrice > 0) ? $compareAtPrice : null,
            ':stock_quantity' => max(0, $stock),
            ':primary_image' => $primaryImage,
            ':is_active' => $isActive,
            ':is_featured' => $isFeatured,
            ':id' => $productId,
        ]);

        // Invalidate Torob API, Sitemap & Twig Cache
        CacheService::flushTorob();
        CacheService::flushSitemap();
        CacheService::flushTwig();

        Session::setFlash('success', 'تغییرات محصول با موفقیت ذخیره شد و کش سیستم بروزرسانی گردید.');
        return Response::redirect(url('admin/products'));
    }

    /**
     * Delete Product or Deactivate if Ordered.
     * Route: POST /admin/products/{id}/delete
     * 
     * @param array<string, string> $vars
     */
    public function deleteProduct(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            Session::setFlash('error', 'توکن امنیتی نامعتبر است.');
            return Response::redirect(url('admin/products'));
        }

        $productId = (int) ($vars['id'] ?? 0);
        $product = $this->db->fetch("SELECT `id`, `title` FROM `products` WHERE `id` = :id LIMIT 1", [':id' => $productId]);
        if (!$product) {
            Session::setFlash('error', 'محصول یافت نشد.');
            return Response::redirect(url('admin/products'));
        }

        // Check if product was referenced in orders
        $orderCountRow = $this->db->fetch("SELECT COUNT(*) AS total FROM `order_items` WHERE `product_id` = :id", [':id' => $productId]);
        $hasOrders = ((int) ($orderCountRow['total'] ?? 0)) > 0;

        if ($hasOrders) {
            // Soft delete by deactivating to preserve financial order integrity
            $this->db->execute("UPDATE `products` SET `is_active` = 0 WHERE `id` = :id", [':id' => $productId]);
            $msg = "محصول «{$product['title']}» به دلیل وجود در فاکتورهای پیشین غیرفعال گردید.";
        } else {
            $this->db->execute("DELETE FROM `products` WHERE `id` = :id", [':id' => $productId]);
            $msg = "محصول «{$product['title']}» با موفقیت از سیستم حذف شد.";
        }

        // Invalidate Torob API & Sitemap Cache
        CacheService::flushTorob();
        CacheService::flushSitemap();

        Session::setFlash('success', $msg);
        return Response::redirect(url('admin/products'));
    }

    /**
     * Securely handle image uploads to public/assets/images/products/
     */
    private function handleImageUpload(Request $request, string $slug): ?string
    {
        /** @var array<string, mixed>|null $file */
        $file = $request->getFile('image') ?? $request->getFile('primary_image');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return null;
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size > 5 * 1024 * 1024) { // 5 MB max
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpName);

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowedMimes[$mime])) {
            return null;
        }

        $ext = $allowedMimes[$mime];
        $targetDir = public_path('assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'products');
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $cleanSlug = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $slug) ?: 'prod', 0, 30);
        $fileName = $cleanSlug . '-' . time() . '-' . mt_rand(100, 999) . '.' . $ext;
        $destination = $targetDir . DIRECTORY_SEPARATOR . $fileName;

        if (move_uploaded_file($tmpName, $destination)) {
            return '/assets/images/products/' . $fileName;
        }

        return null;
    }

    private function slugify(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text) ?? '';
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text) ?? '';
        $text = mb_strtolower($text, 'UTF-8');

        return $text !== '' ? $text : 'item-' . time();
    }

    private function ensureUniqueSlug(string $baseSlug, int $ignoreId = 0): string
    {
        $slug = $baseSlug;
        $counter = 1;

        while (true) {
            $row = $this->db->fetch(
                "SELECT `id` FROM `products` WHERE `slug` = :slug AND `id` != :ignore_id LIMIT 1",
                [':slug' => $slug, ':ignore_id' => $ignoreId]
            );

            if (!$row) {
                break;
            }

            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Navigation Menus Management.
     * Route: GET /admin/menus
     * 
     * @param array<string, string> $vars
     */
    public function menus(Request $request, array $vars = []): Response
    {
        $adminUser = $this->auth->user();

        /** @var list<array<string, mixed>> $menus */
        $menus = $this->db->fetchAll("
            SELECT `id`, `title`, `url`, `sort_order`, `is_active`, `location`, `created_at`
            FROM `navigation_menus`
            ORDER BY `location` ASC, `sort_order` ASC, `id` ASC
        ");

        $pageTitle = 'مدیریت منوهای ناوبری | پنل مدیریت';
        $isPjax = $request->isPjax();

        $html = View::render('admin/menus', [
            'page_title' => $pageTitle,
            'admin_user' => $adminUser,
            'active_menu' => 'menus',
            'is_pjax' => $isPjax,
            'menus' => $menus,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        if ($isPjax) {
            return Response::json([
                'success' => true,
                'html' => $html,
                'title' => $pageTitle,
                'active_menu' => 'menus',
            ]);
        }

        return Response::html($html);
    }

    /**
     * Store New Navigation Menu Item.
     * Route: POST /admin/menus
     * 
     * @param array<string, string> $vars
     */
    public function storeMenu(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            Session::setFlash('error', 'اعتبار نشست کاربری منقضی شده است. لطفاً مجدداً تلاش کنید.');
            return Response::redirect(url('admin/menus'));
        }

        $title = trim((string) $request->input('title', ''));
        $urlPath = trim((string) $request->input('url', ''));
        $sortOrder = (int) $request->input('sort_order', 0);
        $location = (string) $request->input('location', 'header');
        if (!in_array($location, ['header', 'footer'], true)) {
            $location = 'header';
        }
        $isActive = $request->input('is_active') !== null ? 1 : 0;

        if ($title === '' || $urlPath === '') {
            Session::setFlash('error', 'عنوان و آدرس مقصد منو الزامی هستند.');
            return Response::redirect(url('admin/menus'));
        }

        $this->db->execute("
            INSERT INTO `navigation_menus` (`title`, `url`, `sort_order`, `is_active`, `location`)
            VALUES (:title, :url, :sort_order, :is_active, :location)
        ", [
            ':title' => $title,
            ':url' => $urlPath,
            ':sort_order' => $sortOrder,
            ':is_active' => $isActive,
            ':location' => $location,
        ]);

        MenuService::flush();

        Session::setFlash('success', "آیتم منوی «{$title}» با موفقیت افزوده شد.");
        return Response::redirect(url('admin/menus'));
    }

    /**
     * Toggle Menu Active Status.
     * Route: POST /admin/menus/{id}/toggle
     * 
     * @param array<string, string> $vars
     */
    public function toggleMenu(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            if ($request->isAjax()) {
                return Response::json(['success' => false, 'message' => 'توکن امنیتی نامعتبر است.'], 403);
            }
            Session::setFlash('error', 'توکن امنیتی نامعتبر است.');
            return Response::redirect(url('admin/menus'));
        }

        $id = (int) ($vars['id'] ?? 0);
        $this->db->execute("
            UPDATE `navigation_menus` 
            SET `is_active` = 1 - `is_active` 
            WHERE `id` = :id
        ", [':id' => $id]);

        MenuService::flush();

        if ($request->isAjax()) {
            return Response::json(['success' => true, 'message' => 'وضعیت آیتم منو تغییر یافت.']);
        }

        Session::setFlash('success', 'وضعیت آیتم منو با موفقیت به‌روزرسانی شد.');
        return Response::redirect(url('admin/menus'));
    }

    /**
     * Delete Navigation Menu Item.
     * Route: POST /admin/menus/{id}/delete
     * 
     * @param array<string, string> $vars
     */
    public function deleteMenu(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            if ($request->isAjax()) {
                return Response::json(['success' => false, 'message' => 'توکن امنیتی نامعتبر است.'], 403);
            }
            Session::setFlash('error', 'توکن امنیتی نامعتبر است.');
            return Response::redirect(url('admin/menus'));
        }

        $id = (int) ($vars['id'] ?? 0);
        $this->db->execute("DELETE FROM `navigation_menus` WHERE `id` = :id", [':id' => $id]);

        MenuService::flush();

        if ($request->isAjax()) {
            return Response::json(['success' => true, 'message' => 'آیتم منو با موفقیت حذف گردید.']);
        }

        Session::setFlash('success', 'آیتم منو با موفقیت حذف گردید.');
        return Response::redirect(url('admin/menus'));
    }

    /**
     * Homepage Sliders Management.
     * Route: GET /admin/sliders
     * 
     * @param array<string, string> $vars
     */
    public function sliders(Request $request, array $vars = []): Response
    {
        $adminUser = $this->auth->user();

        /** @var list<array<string, mixed>> $sliders */
        $sliders = $this->db->fetchAll("
            SELECT `id`, `title`, `image_path`, `image_path` AS `image_url`, `target_url`, `target_url` AS `link`, `sort_order`, `is_active`, `created_at` 
            FROM `home_sliders` 
            ORDER BY `sort_order` ASC, `id` ASC
        ");

        $pageTitle = 'مدیریت بنرهای اسلایدر صفحه اصلی | پنل مدیریت';
        $isPjax = $request->isPjax();

        $html = View::render('admin/sliders', [
            'page_title' => $pageTitle,
            'admin_user' => $adminUser,
            'active_menu' => 'sliders',
            'is_pjax' => $isPjax,
            'sliders' => $sliders,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        if ($isPjax) {
            return Response::json([
                'success' => true,
                'html' => $html,
                'title' => $pageTitle,
                'active_menu' => 'sliders',
            ]);
        }

        return Response::html($html);
    }

    /**
     * Show Create Slider Form.
     * Route: GET /admin/sliders/create
     * 
     * @param array<string, string> $vars
     */
    public function createSlider(Request $request, array $vars = []): Response
    {
        $adminUser = $this->auth->user();
        $pageTitle = 'افزودن بنر اسلایدر جدید | پنل مدیریت';
        $isPjax = $request->isPjax();

        $html = View::render('admin/slider_form', [
            'page_title' => $pageTitle,
            'admin_user' => $adminUser,
            'active_menu' => 'sliders',
            'is_pjax' => $isPjax,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        if ($isPjax) {
            return Response::json([
                'success' => true,
                'html' => $html,
                'title' => $pageTitle,
                'active_menu' => 'sliders',
            ]);
        }

        return Response::html($html);
    }

    /**
     * Store Newly Created Slider.
     * Route: POST /admin/sliders & POST /admin/sliders/create
     * 
     * @param array<string, string> $vars
     */
    public function storeSlider(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            if ($request->isAjax()) {
                return Response::json(['success' => false, 'message' => 'توکن امنیتی نامعتبر است.'], 403);
            }
            Session::setFlash('error', 'توکن امنیتی نامعتبر است.');
            return Response::redirect(url('admin/sliders'));
        }

        $title = trim((string) $request->input('title', ''));
        $targetUrl = trim((string) ($request->input('target_url') ?? $request->input('link') ?? '/'));
        $sortOrder = (int) $request->input('sort_order', 0);
        $isActive = $request->input('is_active') !== null ? 1 : 0;

        if ($title === '') {
            Session::setFlash('error', 'عنوان اسلایدر الزامی است.');
            return Response::redirect(url('admin/sliders/create'));
        }

        if ($targetUrl === '') {
            $targetUrl = '/';
        }

        $imagePath = $this->handleSliderImageUpload($request);
        if ($imagePath === null) {
            // Check if existing image path was provided manually
            $manualImage = trim((string) ($request->input('image_path') ?? $request->input('image_url') ?? ''));
            if ($manualImage !== '') {
                $imagePath = $manualImage;
            } else {
                Session::setFlash('error', 'بارگذاری تصویر بنر معتبر (WebP, JPG, PNG حداکثر ۵ مگابایت) الزامی است.');
                return Response::redirect(url('admin/sliders/create'));
            }
        }

        $this->db->execute("
            INSERT INTO `home_sliders` (`title`, `image_path`, `target_url`, `sort_order`, `is_active`)
            VALUES (:title, :image_path, :target_url, :sort_order, :is_active)
        ", [
            ':title' => $title,
            ':image_path' => $imagePath,
            ':target_url' => $targetUrl,
            ':sort_order' => $sortOrder,
            ':is_active' => $isActive,
        ]);

        CacheService::flushTwig();

        if ($request->isAjax()) {
            return Response::json(['success' => true, 'message' => "بنر اسلایدر «{$title}» با موفقیت افزوده شد."]);
        }

        Session::setFlash('success', "بنر اسلایدر «{$title}» با موفقیت افزوده شد.");
        return Response::redirect(url('admin/sliders'));
    }

    /**
     * Update Slider.
     * Route: POST /admin/sliders/{id}/edit
     * 
     * @param array<string, string> $vars
     */
    public function updateSlider(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            if ($request->isAjax()) {
                return Response::json(['success' => false, 'message' => 'توکن امنیتی نامعتبر است.'], 403);
            }
            Session::setFlash('error', 'توکن امنیتی نامعتبر است.');
            return Response::redirect(url('admin/sliders'));
        }

        $id = (int) ($vars['id'] ?? 0);
        $slider = $this->db->fetch("SELECT * FROM `home_sliders` WHERE `id` = :id LIMIT 1", [':id' => $id]);
        if (!$slider) {
            Session::setFlash('error', 'اسلایدر مورد نظر یافت نشد.');
            return Response::redirect(url('admin/sliders'));
        }

        $title = trim((string) $request->input('title', ''));
        $targetUrl = trim((string) ($request->input('target_url') ?? $request->input('link') ?? '/'));
        $sortOrder = (int) $request->input('sort_order', 0);
        $isActive = $request->input('is_active') !== null ? 1 : 0;

        if ($title === '') {
            Session::setFlash('error', 'عنوان اسلایدر الزامی است.');
            return Response::redirect(url('admin/sliders'));
        }

        if ($targetUrl === '') {
            $targetUrl = '/';
        }

        $newImage = $this->handleSliderImageUpload($request);
        $manualImage = trim((string) ($request->input('image_path') ?? $request->input('image_url') ?? ''));
        $imagePath = $newImage ?? ($manualImage !== '' ? $manualImage : (string) $slider['image_path']);

        $this->db->execute("
            UPDATE `home_sliders`
            SET `title` = :title,
                `image_path` = :image_path,
                `target_url` = :target_url,
                `sort_order` = :sort_order,
                `is_active` = :is_active
            WHERE `id` = :id
        ", [
            ':title' => $title,
            ':image_path' => $imagePath,
            ':target_url' => $targetUrl,
            ':sort_order' => $sortOrder,
            ':is_active' => $isActive,
            ':id' => $id,
        ]);

        CacheService::flushTwig();

        if ($request->isAjax()) {
            return Response::json(['success' => true, 'message' => "بنر اسلایدر «{$title}» با موفقیت به‌روزرسانی شد."]);
        }

        Session::setFlash('success', "بنر اسلایدر «{$title}» با موفقیت به‌روزرسانی شد.");
        return Response::redirect(url('admin/sliders'));
    }

    /**
     * Toggle Slider Active Status.
     * Route: POST /admin/sliders/{id}/toggle
     * 
     * @param array<string, string> $vars
     */
    public function toggleSlider(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            if ($request->isAjax()) {
                return Response::json(['success' => false, 'message' => 'توکن امنیتی نامعتبر است.'], 403);
            }
            Session::setFlash('error', 'توکن امنیتی نامعتبر است.');
            return Response::redirect(url('admin/sliders'));
        }

        $id = (int) ($vars['id'] ?? 0);
        $this->db->execute("
            UPDATE `home_sliders` 
            SET `is_active` = 1 - `is_active` 
            WHERE `id` = :id
        ", [':id' => $id]);

        CacheService::flushTwig();

        if ($request->isAjax()) {
            return Response::json(['success' => true, 'message' => 'وضعیت اسلایدر تغییر یافت.']);
        }

        Session::setFlash('success', 'وضعیت اسلایدر با موفقیت به‌روزرسانی شد.');
        return Response::redirect(url('admin/sliders'));
    }

    /**
     * Delete Slider.
     * Route: POST /admin/sliders/{id}/delete
     * 
     * @param array<string, string> $vars
     */
    public function deleteSlider(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            if ($request->isAjax()) {
                return Response::json(['success' => false, 'message' => 'توکن امنیتی نامعتبر است.'], 403);
            }
            Session::setFlash('error', 'توکن امنیتی نامعتبر است.');
            return Response::redirect(url('admin/sliders'));
        }

        $id = (int) ($vars['id'] ?? 0);
        $this->db->execute("DELETE FROM `home_sliders` WHERE `id` = :id", [':id' => $id]);

        CacheService::flushTwig();

        if ($request->isAjax()) {
            return Response::json(['success' => true, 'message' => 'اسلایدر با موفقیت حذف گردید.']);
        }

        Session::setFlash('success', 'اسلایدر با موفقیت حذف گردید.');
        return Response::redirect(url('admin/sliders'));
    }

    /**
     * Securely handle slider image uploads to public/assets/images/sliders/
     */
    private function handleSliderImageUpload(Request $request): ?string
    {
        /** @var array<string, mixed>|null $file */
        $file = $request->getFile('image') ?? $request->getFile('slider_image');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return null;
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size > 5 * 1024 * 1024) { // 5 MB max
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpName);

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        if (!isset($allowedMimes[$mime])) {
            return null;
        }

        $ext = $allowedMimes[$mime];
        $targetDir = public_path('assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'sliders');
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $fileName = 'slide-' . time() . '-' . mt_rand(100, 999) . '.' . $ext;
        $destination = $targetDir . DIRECTORY_SEPARATOR . $fileName;

        if (move_uploaded_file($tmpName, $destination)) {
            return 'images/sliders/' . $fileName;
        }

        return null;
    }

    /**
     * General Site Settings.
     * Route: GET /admin/settings
     * 
     * @param array<string, string> $vars
     */
    public function settings(Request $request, array $vars = []): Response
    {
        $adminUser = $this->auth->user();
        $settings = SettingService::all();

        $pageTitle = 'تنظیمات عمومی سایت | پنل مدیریت';
        $isPjax = $request->isPjax();

        $html = View::render('admin/settings', [
            'page_title' => $pageTitle,
            'admin_user' => $adminUser,
            'active_menu' => 'settings',
            'is_pjax' => $isPjax,
            'settings' => $settings,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        if ($isPjax) {
            return Response::json([
                'success' => true,
                'html' => $html,
                'title' => $pageTitle,
                'active_menu' => 'settings',
            ]);
        }

        return Response::html($html);
    }

    /**
     * Update Site Settings.
     * Route: POST /admin/settings
     * 
     * @param array<string, string> $vars
     */
    public function updateSettings(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            Session::setFlash('error', 'اعتبار نشست کاربری منقضی شده است. لطفاً مجدداً تلاش کنید.');
            return Response::redirect(url('admin/settings'));
        }

        $allowedKeys = [
            'top_announcement_text',
            'support_phone',
            'support_email',
            'store_address',
            'working_hours',
            'footer_copyright',
        ];

        foreach ($allowedKeys as $key) {
            $val = trim((string) $request->input($key, ''));
            SettingService::set($key, $val);
        }

        Session::setFlash('success', 'تنظیمات عمومی سایت با موفقیت به‌روزرسانی شد.');
        return Response::redirect(url('admin/settings'));
    }

    /**
     * Categories Management.
     * Route: GET /admin/categories
     * 
     * @param array<string, string> $vars
     */
    public function categories(Request $request, array $vars = []): Response
    {
        $adminUser = $this->auth->user();

        /** @var list<array<string, mixed>> $categories */
        $categories = $this->db->fetchAll("
            SELECT `id`, `parent_id`, `title`, `slug`, `icon_image`, `image_url`, `sort_order`, `is_active`, `created_at`
            FROM `categories`
            ORDER BY `sort_order` ASC, `id` ASC
        ");

        $pageTitle = 'مدیریت دسته‌بندی‌ها و آیکون‌ها | پنل مدیریت';
        $isPjax = $request->isPjax();

        $html = View::render('admin/categories', [
            'page_title' => $pageTitle,
            'admin_user' => $adminUser,
            'active_menu' => 'categories',
            'is_pjax' => $isPjax,
            'categories' => $categories,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        if ($isPjax) {
            return Response::json([
                'success' => true,
                'html' => $html,
                'title' => $pageTitle,
                'active_menu' => 'categories',
            ]);
        }

        return Response::html($html);
    }

    /**
     * Update Category (title, sort_order, is_active, icon_image).
     * Route: POST /admin/categories/{id}
     * 
     * @param array<string, string> $vars
     */
    public function updateCategory(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            Session::setFlash('error', 'اعتبار نشست کاربری منقضی شده است. لطفاً مجدداً تلاش کنید.');
            return Response::redirect(url('admin/categories'));
        }

        $id = (int) ($vars['id'] ?? 0);
        if ($id <= 0) {
            Session::setFlash('error', 'شناسه دسته‌بندی نامعتبر است.');
            return Response::redirect(url('admin/categories'));
        }

        $title = trim((string) $request->input('title', ''));
        if ($title === '') {
            Session::setFlash('error', 'عنوان دسته‌بندی نمی‌تواند خالی باشد.');
            return Response::redirect(url('admin/categories'));
        }

        $sortOrder = (int) $request->input('sort_order', 0);
        $isActive = $request->input('is_active') ? 1 : 0;

        $newIcon = $this->handleCategoryIconUpload($request);

        if ($newIcon !== null) {
            $this->db->execute("
                UPDATE `categories`
                SET `title` = :title,
                    `sort_order` = :sort_order,
                    `is_active` = :is_active,
                    `icon_image` = :icon_image
                WHERE `id` = :id
            ", [
                ':title' => $title,
                ':sort_order' => $sortOrder,
                ':is_active' => $isActive,
                ':icon_image' => $newIcon,
                ':id' => $id,
            ]);
        } else {
            $this->db->execute("
                UPDATE `categories`
                SET `title` = :title,
                    `sort_order` = :sort_order,
                    `is_active` = :is_active
                WHERE `id` = :id
            ", [
                ':title' => $title,
                ':sort_order' => $sortOrder,
                ':is_active' => $isActive,
                ':id' => $id,
            ]);
        }

        CacheService::flushTwig();

        if ($request->isAjax()) {
            return Response::json(['success' => true, 'message' => 'دسته‌بندی با موفقیت به‌روزرسانی شد.']);
        }

        Session::setFlash('success', 'دسته‌بندی با موفقیت به‌روزرسانی شد.');
        return Response::redirect(url('admin/categories'));
    }

    /**
     * Toggle Category active status.
     * Route: POST /admin/categories/{id}/toggle
     * 
     * @param array<string, string> $vars
     */
    public function toggleCategory(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            Session::setFlash('error', 'توکن امنیتی معتبر نیست.');
            return Response::redirect(url('admin/categories'));
        }

        $id = (int) ($vars['id'] ?? 0);
        $this->db->execute("
            UPDATE `categories` 
            SET `is_active` = 1 - `is_active` 
            WHERE `id` = :id
        ", [':id' => $id]);

        CacheService::flushTwig();

        if ($request->isAjax()) {
            return Response::json(['success' => true, 'message' => 'وضعیت دسته‌بندی با موفقیت تغییر یافت.']);
        }

        Session::setFlash('success', 'وضعیت دسته‌بندی با موفقیت تغییر یافت.');
        return Response::redirect(url('admin/categories'));
    }

    /**
     * Securely handle category icon uploads to public/assets/images/categories/
     */
    private function handleCategoryIconUpload(Request $request): ?string
    {
        /** @var array<string, mixed>|null $file */
        $file = $request->getFile('icon_image') ?? $request->getFile('icon');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if (!is_uploaded_file($tmpName) || $size <= 0 || $size > 5 * 1024 * 1024) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            return null;
        }
        $mime = finfo_file($finfo, $tmpName);
        finfo_close($finfo);

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];

        if (!isset($allowedMimes[$mime])) {
            return null;
        }

        $ext = $allowedMimes[$mime];
        $targetDir = public_path('assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'categories');
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $fileName = 'cat-icon-' . time() . '-' . mt_rand(100, 999) . '.' . $ext;
        $destination = $targetDir . DIRECTORY_SEPARATOR . $fileName;

        if (move_uploaded_file($tmpName, $destination)) {
            return 'images/categories/' . $fileName;
        }

        return null;
    }

    /**
     * Promotional Split Banners Management.
     * Route: GET /admin/banners
     * 
     * @param array<string, string> $vars
     */
    public function promoBanners(Request $request, array $vars = []): Response
    {
        $adminUser = $this->auth->user();

        /** @var list<array<string, mixed>> $banners */
        $banners = $this->db->fetchAll("
            SELECT `id`, `badge_text`, `title`, `description`, `button_text`, `target_url`, `image_path`, `position`, `is_active`, `created_at`, `updated_at`
            FROM `promo_banners`
            ORDER BY `id` ASC
        ");

        $pageTitle = 'مدیریت بنرهای تبلیغاتی میانی | پنل مدیریت';
        $isPjax = $request->isPjax();

        $html = View::render('admin/banners', [
            'page_title' => $pageTitle,
            'admin_user' => $adminUser,
            'active_menu' => 'banners',
            'is_pjax' => $isPjax,
            'banners' => $banners,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        if ($isPjax) {
            return Response::json([
                'success' => true,
                'html' => $html,
                'title' => $pageTitle,
                'active_menu' => 'banners',
            ]);
        }

        return Response::html($html);
    }

    /**
     * Update Promotional Split Banner.
     * Route: POST /admin/banners/{id}
     * 
     * @param array<string, string> $vars
     */
    public function updatePromoBanner(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            Session::setFlash('error', 'اعتبار نشست کاربری منقضی شده است. لطفاً مجدداً تلاش کنید.');
            return Response::redirect(url('admin/banners'));
        }

        $id = (int) ($vars['id'] ?? 0);
        if ($id <= 0) {
            Session::setFlash('error', 'شناسه بنر نامعتبر است.');
            return Response::redirect(url('admin/banners'));
        }

        $title = trim((string) $request->input('title', ''));
        $badgeText = trim((string) $request->input('badge_text', ''));
        $description = trim((string) $request->input('description', ''));
        $buttonText = trim((string) $request->input('button_text', ''));
        $targetUrl = trim((string) $request->input('target_url', '/'));
        $isActive = $request->input('is_active') ? 1 : 0;

        if ($title === '' || $targetUrl === '') {
            Session::setFlash('error', 'عنوان بنر و آدرس مقصد الزامی هستند.');
            return Response::redirect(url('admin/banners'));
        }

        $newImage = $this->handleBannerImageUpload($request);

        if ($newImage !== null) {
            $this->db->execute("
                UPDATE `promo_banners`
                SET `badge_text` = :badge_text,
                    `title` = :title,
                    `description` = :description,
                    `button_text` = :button_text,
                    `target_url` = :target_url,
                    `image_path` = :image_path,
                    `is_active` = :is_active
                WHERE `id` = :id
            ", [
                ':badge_text' => $badgeText,
                ':title' => $title,
                ':description' => $description,
                ':button_text' => $buttonText,
                ':target_url' => $targetUrl,
                ':image_path' => $newImage,
                ':is_active' => $isActive,
                ':id' => $id,
            ]);
        } else {
            $this->db->execute("
                UPDATE `promo_banners`
                SET `badge_text` = :badge_text,
                    `title` = :title,
                    `description` = :description,
                    `button_text` = :button_text,
                    `target_url` = :target_url,
                    `is_active` = :is_active
                WHERE `id` = :id
            ", [
                ':badge_text' => $badgeText,
                ':title' => $title,
                ':description' => $description,
                ':button_text' => $buttonText,
                ':target_url' => $targetUrl,
                ':is_active' => $isActive,
                ':id' => $id,
            ]);
        }

        CacheService::flushTwig();

        if ($request->isAjax()) {
            return Response::json(['success' => true, 'message' => 'بنر تبلیغاتی با موفقیت به‌روزرسانی شد.']);
        }

        Session::setFlash('success', 'بنر تبلیغاتی با موفقیت به‌روزرسانی شد.');
        return Response::redirect(url('admin/banners'));
    }

    /**
     * Securely handle promo banner image uploads to public/assets/images/banners/
     */
    private function handleBannerImageUpload(Request $request): ?string
    {
        /** @var array<string, mixed>|null $file */
        $file = $request->getFile('image') ?? $request->getFile('banner_image');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if (!is_uploaded_file($tmpName) || $size <= 0 || $size > 5 * 1024 * 1024) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            return null;
        }
        $mime = finfo_file($finfo, $tmpName);
        finfo_close($finfo);

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowedMimes[$mime])) {
            return null;
        }

        $ext = $allowedMimes[$mime];
        $targetDir = public_path('assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'banners');
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $fileName = 'promo-' . time() . '-' . mt_rand(100, 999) . '.' . $ext;
        $destination = $targetDir . DIRECTORY_SEPARATOR . $fileName;

        if (move_uploaded_file($tmpName, $destination)) {
            return 'images/banners/' . $fileName;
        }

        return null;
    }
}
