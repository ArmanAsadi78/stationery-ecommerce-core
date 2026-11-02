<?php

declare(strict_types=1);

// Ensure vendor autoload is present
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\CartController;
use App\Controllers\CheckoutController;
use App\Controllers\HomeController;
use App\Controllers\ProductController;
use App\Controllers\SitemapController;
use App\Controllers\TorobApiController;
use App\Core\Application;

$app = new Application();
$router = $app->getRouter();

// Define Web & API Routes
$router->get('/', [HomeController::class, 'index']);
$router->get('/products', [ProductController::class, 'index']);
$router->get('/products/{slug:[^/]+}', [ProductController::class, 'show']);

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

// Search Engine Optimization & External Integrations
$router->get('/sitemap.xml', [SitemapController::class, 'index']);
$router->get('/api/torob-products', [TorobApiController::class, 'index']);

// Execute Request Lifecycle
$app->run();
