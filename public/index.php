<?php

declare(strict_types=1);

// Ensure vendor autoload is present
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\AccountController;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\CartController;
use App\Controllers\CheckoutController;
use App\Controllers\HomeController;
use App\Controllers\PageController;
use App\Controllers\ProductController;
use App\Controllers\SitemapController;
use App\Controllers\TorobApiController;
use App\Core\Application;
use App\Middlewares\AdminMiddleware;
use App\Middlewares\AuthMiddleware;

$app = new Application();
$router = $app->getRouter();

// Define Web & API Routes
$router->get('/', [HomeController::class, 'index']);
$router->get('/products', [ProductController::class, 'index']);
$router->get('/products/{slug:[^/]+}', [ProductController::class, 'show']);

// Public Trust & Informational Pages
$router->get('/about-us', [PageController::class, 'about']);
$router->get('/contact-us', [PageController::class, 'contact']);
$router->post('/contact-us', [PageController::class, 'submitContact']);
$router->get('/faq', [PageController::class, 'faq']);
$router->get('/terms', [PageController::class, 'terms']);

// AJAX Drawer Cart Routes
$router->get('/cart/data', [CartController::class, 'data']);
$router->post('/cart/add', [CartController::class, 'add']);
$router->post('/cart/update', [CartController::class, 'update']);
$router->post('/cart/remove', [CartController::class, 'remove']);

// Checkout & Payment Routes
$router->get('/checkout', [CheckoutController::class, 'index']);
$router->post('/checkout/apply-coupon', [CheckoutController::class, 'applyCoupon']);
$router->post('/checkout/remove-coupon', [CheckoutController::class, 'removeCoupon']);
$router->post('/checkout/process', [CheckoutController::class, 'process']);
$router->get('/payment/mock-gateway', [CheckoutController::class, 'mockGateway']);
$router->get('/payment/callback', [CheckoutController::class, 'callback']);
$router->get('/order/success/{id:[0-9]+}', [CheckoutController::class, 'success']);

// Customer Authentication & Guest Access Routes
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/logout', [AuthController::class, 'logout']);

// Customer Portal Routes (Protected by AuthMiddleware)
$router->get('/account/dashboard', [AccountController::class, 'dashboard'], [AuthMiddleware::class]);
$router->get('/account/orders', [AccountController::class, 'orders'], [AuthMiddleware::class]);
$router->get('/account/orders/{id:[0-9]+}', [AccountController::class, 'orderDetail'], [AuthMiddleware::class]);
$router->get('/account/wishlist', [AccountController::class, 'wishlist'], [AuthMiddleware::class]);
$router->post('/account/wishlist/toggle', [AccountController::class, 'toggleWishlist']);
$router->get('/account/profile', [AccountController::class, 'profile'], [AuthMiddleware::class]);
$router->post('/account/profile', [AccountController::class, 'profile'], [AuthMiddleware::class]);

// Administrative Panel Routes (Protected by AdminMiddleware)
$router->get('/admin/login', [AdminController::class, 'showLogin']);
$router->post('/admin/login', [AdminController::class, 'login']);
$router->get('/admin/logout', [AdminController::class, 'logout']);
$router->post('/admin/logout', [AdminController::class, 'logout']);

$router->get('/admin', [AdminController::class, 'dashboard'], [AdminMiddleware::class]);
$router->get('/admin/dashboard', [AdminController::class, 'dashboard'], [AdminMiddleware::class]);
$router->get('/admin/orders', [AdminController::class, 'orders'], [AdminMiddleware::class]);
$router->get('/admin/orders/{id:[0-9]+}', [AdminController::class, 'orderDetail'], [AdminMiddleware::class]);
$router->post('/admin/orders/{id:[0-9]+}/status', [AdminController::class, 'updateOrderStatus'], [AdminMiddleware::class]);

// Search Engine Optimization & External Integrations
$router->get('/sitemap.xml', [SitemapController::class, 'index']);
$router->get('/api/torob-products', [TorobApiController::class, 'index']);

// Execute Request Lifecycle
$app->run();
