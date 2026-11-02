<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AdminController;
use App\Core\Application;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middlewares\AdminMiddleware;
use App\Services\AuthService;
use App\Services\CacheService;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

class AdminTest extends TestCase
{
    private Application $app;
    private Database $db;
    private AuthService $auth;

    /** @var list<string> */
    private array $createdUserEmails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->safeLoad();

        Session::start();
        $this->db = Database::getInstance();
        $this->auth = new AuthService($this->db);

        $this->app = new Application();
        $router = $this->app->getRouter();

        // Register Admin Routes
        $router->get('/admin/login', [AdminController::class, 'showLogin']);
        $router->post('/admin/login', [AdminController::class, 'login']);
        $router->get('/admin/logout', [AdminController::class, 'logout']);
        $router->post('/admin/logout', [AdminController::class, 'logout']);

        $router->get('/admin/dashboard', [AdminController::class, 'dashboard'], [AdminMiddleware::class]);
        $router->get('/admin/orders', [AdminController::class, 'orders'], [AdminMiddleware::class]);
        $router->get('/admin/orders/{id:[0-9]+}', [AdminController::class, 'orderDetail'], [AdminMiddleware::class]);
        $router->post('/admin/orders/{id:[0-9]+}/status', [AdminController::class, 'updateOrderStatus'], [AdminMiddleware::class]);

        $router->get('/admin/products', [AdminController::class, 'products'], [AdminMiddleware::class]);
        $router->get('/admin/products/create', [AdminController::class, 'createProduct'], [AdminMiddleware::class]);
        $router->post('/admin/products', [AdminController::class, 'storeProduct'], [AdminMiddleware::class]);
        $router->get('/admin/products/{id:[0-9]+}/edit', [AdminController::class, 'editProduct'], [AdminMiddleware::class]);
        $router->post('/admin/products/{id:[0-9]+}', [AdminController::class, 'updateProduct'], [AdminMiddleware::class]);
        $router->post('/admin/products/{id:[0-9]+}/delete', [AdminController::class, 'deleteProduct'], [AdminMiddleware::class]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdUserEmails as $email) {
            $this->db->execute("DELETE FROM `users` WHERE `email` = :email", [':email' => $email]);
        }
        $this->auth->logout();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    private function request(string $method, string $uri, array $post = [], array $headers = []): Response
    {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
        ];

        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }

        $request = new Request([], $post, $server, [], []);
        return $this->app->handle($request);
    }

    public function test_admin_login_page_renders_successfully(): void
    {
        $response = $this->request('GET', '/admin/login');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('ورود به پرتال مدیریت', $response->getContent());
    }

    public function test_admin_middleware_blocks_guests(): void
    {
        $this->auth->logout();

        $response = $this->request('GET', '/admin/dashboard');

        // Should return 302 redirect to /admin/login
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('admin/login', $response->getHeader('Location') ?? '');
    }

    public function test_admin_middleware_blocks_regular_customers(): void
    {
        $customerEmail = 'cust_test_' . time() . '@example.com';
        $this->createdUserEmails[] = $customerEmail;

        $hash = password_hash('cust_pass', PASSWORD_BCRYPT);
        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES ('مشتری عادی', :email, :hash, 'customer', 1, NOW())",
            [':email' => $customerEmail, ':hash' => $hash]
        );

        $this->auth->login($customerEmail, 'cust_pass');
        $this->assertTrue($this->auth->check());

        // Normal customer accessing /admin/dashboard should receive 403 Forbidden
        $response = $this->request('GET', '/admin/dashboard');
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('دسترسی غیرمجاز', $response->getContent());
    }

    public function test_admin_login_success_and_dashboard_access(): void
    {
        $adminEmail = 'adm_test_' . time() . '@example.com';
        $this->createdUserEmails[] = $adminEmail;

        $hash = password_hash('adm_pass123', PASSWORD_BCRYPT);
        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES ('مدیر فنی سیستم', :email, :hash, 'admin', 1, NOW())",
            [':email' => $adminEmail, ':hash' => $hash]
        );

        // 1. Login via POST /admin/login
        $csrfToken = Session::csrfToken();
        $loginRes = $this->request('POST', '/admin/login', [
            '_csrf_token' => $csrfToken,
            'identifier' => $adminEmail,
            'password' => 'adm_pass123',
        ], ['X-Requested-With' => 'XMLHttpRequest']);

        $this->assertSame(200, $loginRes->getStatusCode());
        $json = json_decode($loginRes->getContent(), true);
        $this->assertTrue($json['success']);
        $this->assertTrue($this->auth->check());

        // 2. Access Admin Dashboard
        $dashRes = $this->request('GET', '/admin/dashboard');
        $this->assertSame(200, $dashRes->getStatusCode());
        $dashHtml = $dashRes->getContent();
        $this->assertStringContainsString('داشبورد آمار و شاخص‌های کلیدی', $dashHtml);
        $this->assertStringContainsString('مدیر فنی سیستم', $dashHtml);

        // 3. Access Admin Orders
        $ordersRes = $this->request('GET', '/admin/orders');
        $this->assertSame(200, $ordersRes->getStatusCode());
        $this->assertStringContainsString('مدیریت سفارش‌ها و رهگیری مرسولات', $ordersRes->getContent());
    }

    public function test_admin_can_update_order_status_and_tracking_code(): void
    {
        $adminEmail = 'adm_updater_' . time() . '@example.com';
        $this->createdUserEmails[] = $adminEmail;

        $hash = password_hash('adm_pass123', PASSWORD_BCRYPT);
        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES ('مدیر انبارداری', :email, :hash, 'admin', 1, NOW())",
            [':email' => $adminEmail, ':hash' => $hash]
        );

        $this->auth->login($adminEmail, 'adm_pass123');

        // Create or find an order to update
        $order = $this->db->fetch("SELECT `id`, `status` FROM `orders` ORDER BY `id` DESC LIMIT 1");
        if (!$order) {
            $this->db->execute("
                INSERT INTO `orders` (`order_number`, `user_id`, `subtotal`, `total_amount`, `status`, `recipient_name`, `recipient_phone`, `shipping_province`, `shipping_city`, `shipping_postal_code`, `shipping_address`, `created_at`)
                VALUES ('ORD-TEST-99', NULL, 150000, 150000, 'processing', 'رضا تست', '09120000000', 'تهران', 'تهران', '1234567890', 'خیابان تست', NOW())
            ");
            $order = $this->db->fetch("SELECT `id`, `status` FROM `orders` WHERE `order_number` = 'ORD-TEST-99'");
        }
        $orderId = (int) $order['id'];

        $csrfToken = Session::csrfToken();
        $updateRes = $this->request('POST', "/admin/orders/{$orderId}/status", [
            '_csrf_token' => $csrfToken,
            'status' => 'shipped',
            'customer_notes' => 'کد رهگیری پست پیشتاز: 98765432101234567890',
        ]);

        $this->assertSame(302, $updateRes->getStatusCode());

        // Verify database state
        $updatedOrder = $this->db->fetch("SELECT `status`, `customer_notes` FROM `orders` WHERE `id` = :id", [':id' => $orderId]);
        $this->assertSame('shipped', $updatedOrder['status']);
        $this->assertStringContainsString('98765432101234567890', (string) $updatedOrder['customer_notes']);
    }

    public function test_admin_orders_page_renders_with_pagination(): void
    {
        $adminEmail = 'adm_pagi_' . time() . '@example.com';
        $this->createdUserEmails[] = $adminEmail;

        $hash = password_hash('adm_pass123', PASSWORD_BCRYPT);
        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES ('مدیر تست', :email, :hash, 'admin', 1, NOW())",
            [':email' => $adminEmail, ':hash' => $hash]
        );

        $this->auth->login($adminEmail, 'adm_pass123');

        $response = $this->request('GET', '/admin/orders?page=1');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('مدیریت سفارش‌ها', $response->getContent());
    }

    public function test_admin_products_crud_and_cache_invalidation(): void
    {
        $adminEmail = 'adm_crud_' . time() . '@example.com';
        $this->createdUserEmails[] = $adminEmail;

        $hash = password_hash('adm_pass123', PASSWORD_BCRYPT);
        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES ('مدیر انبار', :email, :hash, 'admin', 1, NOW())",
            [':email' => $adminEmail, ':hash' => $hash]
        );

        $this->auth->login($adminEmail, 'adm_pass123');

        // Prime torob and sitemap cache
        CacheService::torob()->set('torob_test_key', ['foo' => 'bar'], 1800);
        CacheService::sitemap()->set('sitemap_xml_content', '<xml>test</xml>', 3600);
        $this->assertTrue(CacheService::torob()->has('torob_test_key'));
        $this->assertTrue(CacheService::sitemap()->has('sitemap_xml_content'));

        // 1. Products List
        $resList = $this->request('GET', '/admin/products');
        $this->assertSame(200, $resList->getStatusCode());
        $this->assertStringContainsString('مدیریت محصولات و موجودی انبار', $resList->getContent());

        // 2. Create Page
        $resCreate = $this->request('GET', '/admin/products/create');
        $this->assertSame(200, $resCreate->getStatusCode());
        $this->assertStringContainsString('افزودن محصول جدید', $resCreate->getContent());

        // Find a valid category
        $cat = $this->db->fetch("SELECT `id` FROM `categories` LIMIT 1");
        $catId = (int) ($cat['id'] ?? 7);

        // 3. Store Product
        $testSku = 'TEST-SKU-' . time();
        $resStore = $this->request('POST', '/admin/products', [
            '_csrf_token' => Session::csrfToken(),
            'name' => 'روان‌نویس تست سیستم خودکار ' . time(),
            'category_id' => (string) $catId,
            'price' => '120000',
            'old_price' => '150000',
            'stock' => '25',
            'brand' => 'روترینگ',
            'sku' => $testSku,
            'summary' => 'خلاصه تست',
            'description' => 'توضیحات تست محصول',
            'spec' => "کشور: آلمان\nقطر نوک: ۰.۵",
            'is_active' => '1',
        ]);

        $this->assertSame(302, $resStore->getStatusCode());

        // Assert Torob and Sitemap cache were invalidated
        $this->assertFalse(CacheService::torob()->has('torob_test_key'));
        $this->assertFalse(CacheService::sitemap()->has('sitemap_xml_content'));

        // Verify product in database
        $createdProduct = $this->db->fetch("SELECT * FROM `products` WHERE `sku` = :sku", [':sku' => $testSku]);
        $this->assertNotNull($createdProduct);
        $this->assertSame('روترینگ', $createdProduct['brand']);
        $this->assertSame(25, (int) $createdProduct['stock_quantity']);
        $prodId = (int) $createdProduct['id'];

        // 4. Edit Form
        $resEdit = $this->request('GET', "/admin/products/{$prodId}/edit");
        $this->assertSame(200, $resEdit->getStatusCode());
        $this->assertStringContainsString('ویرایش کالا:', $resEdit->getContent());

        // Prime cache again
        CacheService::torob()->set('torob_test_key_2', 'data', 1800);
        CacheService::sitemap()->set('sitemap_xml_content', '<xml>test</xml>', 3600);

        // 5. Update Product
        $resUpdate = $this->request('POST', "/admin/products/{$prodId}", [
            '_csrf_token' => Session::csrfToken(),
            'name' => 'روان‌نویس تست ادیت‌شده ' . time(),
            'category_id' => (string) $catId,
            'price' => '135000',
            'stock' => '30',
            'sku' => $testSku,
        ]);
        $this->assertSame(302, $resUpdate->getStatusCode());
        $this->assertFalse(CacheService::torob()->has('torob_test_key_2'));

        $updatedProduct = $this->db->fetch("SELECT `price`, `stock_quantity` FROM `products` WHERE `id` = :id", [':id' => $prodId]);
        $this->assertSame('135000', (string) $updatedProduct['price']);
        $this->assertSame(30, (int) $updatedProduct['stock_quantity']);

        // 6. Delete Product
        $resDelete = $this->request('POST', "/admin/products/{$prodId}/delete", [
            '_csrf_token' => Session::csrfToken(),
        ]);
        $this->assertSame(302, $resDelete->getStatusCode());

        $deletedProduct = $this->db->fetch("SELECT `id` FROM `products` WHERE `id` = :id", [':id' => $prodId]);
        $this->assertNull($deletedProduct);
    }
}
