<?php

declare(strict_types=1);

// Ensure vendor autoload is present
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\CartController;
use App\Controllers\HomeController;
use App\Controllers\ProductController;
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

// Execute Request Lifecycle
$app->run();
