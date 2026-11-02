<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\AuthService;

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

        $html = View::render('admin/dashboard', [
            'page_title' => 'داشبورد مدیریت سامانه | تحریرچی',
            'admin_user' => $adminUser,
            'active_menu' => 'dashboard',
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
            LIMIT 50
        ", $params);

        $html = View::render('admin/orders', [
            'page_title' => 'مدیریت و پیگیری سفارش‌ها | پنل ادمین',
            'admin_user' => $adminUser,
            'active_menu' => 'orders',
            'orders' => $orders,
            'current_status' => $statusFilter,
            'search_query' => $searchQuery,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

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

        $html = View::render('admin/order_detail', [
            'page_title' => 'جزئیات سفارش #' . $order['order_number'] . ' | پنل مدیریت',
            'admin_user' => $adminUser,
            'active_menu' => 'orders',
            'order' => $order,
            'items' => $items,
            'payment' => $payment,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

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
}
