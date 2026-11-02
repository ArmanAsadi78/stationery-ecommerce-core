<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\AuthService;

class AccountController
{
    private AuthService $auth;
    private Database $db;

    public function __construct(?AuthService $auth = null, ?Database $db = null)
    {
        $this->auth = $auth ?? new AuthService();
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Customer Account Dashboard Overview.
     * Route: GET /account/dashboard
     * 
     * @param array<string, string> $vars
     */
    public function dashboard(Request $request, array $vars = []): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return Response::redirect(url('login'));
        }

        $userId = (int) $user['id'];

        // 1. Fetch Order Statistics
        $ordersCountRow = $this->db->fetch(
            "SELECT COUNT(*) AS total_orders, 
                    SUM(CASE WHEN `status` IN ('pending_payment', 'processing') THEN 1 ELSE 0 END) AS pending_orders,
                    SUM(CASE WHEN `status` = 'delivered' THEN 1 ELSE 0 END) AS delivered_orders,
                    COALESCE(SUM(CASE WHEN `status` != 'cancelled' THEN `total_amount` ELSE 0 END), 0) AS total_spent
             FROM `orders` WHERE `user_id` = :user_id",
            [':user_id' => $userId]
        );

        // 2. Fetch Wishlist Count
        $wishlistCountRow = $this->db->fetch(
            "SELECT COUNT(*) AS total FROM `wishlists` WHERE `user_id` = :user_id",
            [':user_id' => $userId]
        );

        // 3. Fetch Recent Orders (Latest 5)
        /** @var list<array<string, mixed>> $recentOrders */
        $recentOrders = $this->db->fetchAll(
            "SELECT `id`, `order_number`, `status`, `total_amount`, `created_at` 
             FROM `orders` 
             WHERE `user_id` = :user_id 
             ORDER BY `created_at` DESC 
             LIMIT 5",
            [':user_id' => $userId]
        );

        $html = View::render('account/dashboard', [
            'page_title' => 'داشبورد کاربری | تحریرچی',
            'active_tab' => 'dashboard',
            'user' => $user,
            'stats' => [
                'total_orders' => (int) ($ordersCountRow['total_orders'] ?? 0),
                'pending_orders' => (int) ($ordersCountRow['pending_orders'] ?? 0),
                'delivered_orders' => (int) ($ordersCountRow['delivered_orders'] ?? 0),
                'total_spent' => (float) ($ordersCountRow['total_spent'] ?? 0),
                'wishlist_count' => (int) ($wishlistCountRow['total'] ?? 0),
            ],
            'recent_orders' => $recentOrders,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        return Response::html($html);
    }

    /**
     * Customer Orders History.
     * Route: GET /account/orders
     * 
     * @param array<string, string> $vars
     */
    public function orders(Request $request, array $vars = []): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return Response::redirect(url('login'));
        }

        $userId = (int) $user['id'];
        $filterStatus = trim((string) $request->getQuery('status', ''));

        $sql = "SELECT o.`id`, o.`order_number`, o.`status`, o.`subtotal`, o.`discount_amount`, 
                       o.`shipping_cost`, o.`total_amount`, o.`recipient_name`, o.`created_at`,
                       COUNT(oi.`id`) AS items_count
                FROM `orders` o
                LEFT JOIN `order_items` oi ON o.`id` = oi.`order_id`
                WHERE o.`user_id` = :user_id ";

        $params = [':user_id' => $userId];

        $allowedStatuses = ['pending_payment', 'processing', 'shipped', 'delivered', 'cancelled'];
        if ($filterStatus !== '' && in_array($filterStatus, $allowedStatuses, true)) {
            $sql .= "AND o.`status` = :status ";
            $params[':status'] = $filterStatus;
        }

        $sql .= "GROUP BY o.`id` ORDER BY o.`created_at` DESC";

        /** @var list<array<string, mixed>> $orders */
        $orders = $this->db->fetchAll($sql, $params);

        if ($request->isAjax()) {
            $partialHtml = View::render('account/_orders_list', [
                'orders' => $orders,
                'current_filter' => $filterStatus,
            ]);

            return Response::json([
                'success' => true,
                'html' => $partialHtml,
                'count' => count($orders),
                'current_filter' => $filterStatus,
            ]);
        }

        $html = View::render('account/orders', [
            'page_title' => 'تاریخچه سفارش‌ها | تحریرچی',
            'active_tab' => 'orders',
            'user' => $user,
            'orders' => $orders,
            'current_filter' => $filterStatus,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        return Response::html($html);
    }

    /**
     * View Single Order Details and Invoice.
     * Route: GET /account/orders/{id}
     * 
     * @param array<string, string> $vars
     */
    public function orderDetail(Request $request, array $vars = []): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return Response::redirect(url('login'));
        }

        $userId = (int) $user['id'];
        $orderId = (int) ($vars['id'] ?? 0);

        /** @var array<string, mixed>|null $order */
        $order = $this->db->fetch(
            "SELECT * FROM `orders` WHERE `id` = :id AND `user_id` = :user_id LIMIT 1",
            [':id' => $orderId, ':user_id' => $userId]
        );

        if (!$order) {
            Session::setFlash('error', 'سفارش مورد نظر یافت نشد یا دسترسی به آن امکان‌پذیر نیست.');
            return Response::redirect(url('account/orders'));
        }

        // Fetch Order Items with Product details
        /** @var list<array<string, mixed>> $items */
        $items = $this->db->fetchAll(
            "SELECT oi.`id`, oi.`product_id`, oi.`title`, oi.`variant_name`, oi.`unit_price`, 
                    oi.`quantity`, oi.`total_price`, p.`slug`, p.`primary_image`
             FROM `order_items` oi
             LEFT JOIN `products` p ON oi.`product_id` = p.`id`
             WHERE oi.`order_id` = :order_id",
            [':order_id' => $orderId]
        );

        // Fetch Payment Record
        /** @var array<string, mixed>|null $payment */
        $payment = $this->db->fetch(
            "SELECT `id`, `reference_id`, `gateway`, `status`, `created_at` 
             FROM `payments` 
             WHERE `order_id` = :order_id 
             ORDER BY `id` DESC LIMIT 1",
            [':order_id' => $orderId]
        );

        $html = View::render('account/order_detail', [
            'page_title' => 'جزئیات سفارش ' . $order['order_number'] . ' | تحریرچی',
            'active_tab' => 'orders',
            'user' => $user,
            'order' => $order,
            'items' => $items,
            'payment' => $payment ?: null,
        ]);

        return Response::html($html);
    }

    /**
     * Customer Saved Wishlist.
     * Route: GET /account/wishlist
     * 
     * @param array<string, string> $vars
     */
    public function wishlist(Request $request, array $vars = []): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return Response::redirect(url('login'));
        }

        $userId = (int) $user['id'];
        $wishlistItems = $this->auth->getWishlist($userId);

        $html = View::render('account/wishlist', [
            'page_title' => 'کالاهای مورد علاقه | تحریرچی',
            'active_tab' => 'wishlist',
            'user' => $user,
            'wishlist' => $wishlistItems,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        return Response::html($html);
    }

    /**
     * Toggle Product in Wishlist via AJAX.
     * Route: POST /account/wishlist/toggle
     * 
     * @param array<string, string> $vars
     */
    public function toggleWishlist(Request $request, array $vars = []): Response
    {
        if (!$this->auth->check()) {
            return Response::json([
                'success' => false,
                'message' => 'برای افزودن به لیست علاقه‌مندی‌ها، لطفاً ابتدا وارد حساب کاربری خود شوید.',
                'require_login' => true,
                'redirect' => url('login'),
            ], 401);
        }

        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            return Response::json(['success' => false, 'message' => 'توکن امنیتی معتبر نیست.'], 403);
        }

        $userId = (int) $this->auth->id();
        $productId = (int) $request->input('product_id', 0);

        if ($productId <= 0) {
            return Response::json(['success' => false, 'message' => 'شناسه محصول نامعتبر است.'], 422);
        }

        $result = $this->auth->toggleWishlist($userId, $productId);
        return Response::json($result, 200);
    }

    /**
     * Profile Management & Password Change.
     * Route: GET|POST /account/profile
     * 
     * @param array<string, string> $vars
     */
    public function profile(Request $request, array $vars = []): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return Response::redirect(url('login'));
        }

        $userId = (int) $user['id'];

        if ($request->getMethod() === 'POST') {
            $csrfToken = (string) (
                $request->input('_csrf_token') 
                ?? $request->input('csrf_token') 
                ?? $request->getHeader('X-CSRF-Token')
            );

            if (!Session::validateCsrf($csrfToken)) {
                if ($request->isAjax()) {
                    return Response::json(['success' => false, 'message' => 'توکن امنیتی معتبر نیست.'], 403);
                }
                Session::setFlash('error', 'توکن امنیتی معتبر نیست.');
                return Response::redirect(url('account/profile'));
            }

            $action = (string) $request->input('action', 'profile');

            if ($action === 'password') {
                $oldPass = (string) $request->input('old_password', '');
                $newPass = (string) $request->input('new_password', '');
                $confirmPass = (string) $request->input('new_password_confirmation', '');

                if ($newPass !== $confirmPass) {
                    $err = 'رمز عبور جدید با تکرار آن یکسان نیست.';
                    if ($request->isAjax()) {
                        return Response::json(['success' => false, 'message' => $err], 422);
                    }
                    Session::setFlash('error', $err);
                    return Response::redirect(url('account/profile'));
                }

                $res = $this->auth->changePassword($userId, $oldPass, $newPass);
                if (!$res['success']) {
                    if ($request->isAjax()) {
                        return Response::json($res, 422);
                    }
                    Session::setFlash('error', $res['message']);
                    return Response::redirect(url('account/profile'));
                }

                if ($request->isAjax()) {
                    return Response::json($res, 200);
                }
                Session::setFlash('success', $res['message']);
                return Response::redirect(url('account/profile'));
            }

            // Default: Profile Info Update
            $updateData = [
                'full_name' => (string) $request->input('full_name', ''),
                'phone' => (string) $request->input('phone', ''),
            ];

            $res = $this->auth->updateProfile($userId, $updateData);
            if (!$res['success']) {
                if ($request->isAjax()) {
                    return Response::json($res, 422);
                }
                Session::setFlash('error', $res['message']);
                return Response::redirect(url('account/profile'));
            }

            if ($request->isAjax()) {
                return Response::json($res, 200);
            }
            Session::setFlash('success', $res['message']);
            return Response::redirect(url('account/profile'));
        }

        // Fetch Saved Addresses
        /** @var list<array<string, mixed>> $addresses */
        $addresses = $this->db->fetchAll(
            "SELECT * FROM `user_addresses` WHERE `user_id` = :user_id ORDER BY `is_default` DESC, `id` DESC",
            [':user_id' => $userId]
        );

        $html = View::render('account/profile', [
            'page_title' => 'تنظیمات و اطلاعات کاربری | تحریرچی',
            'active_tab' => 'profile',
            'user' => $user,
            'addresses' => $addresses,
            'flash_success' => Session::getFlash('success'),
            'flash_error' => Session::getFlash('error'),
        ]);

        return Response::html($html);
    }
}
