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

        $router->get('/admin/menus', [AdminController::class, 'menus'], [AdminMiddleware::class]);
        $router->post('/admin/menus', [AdminController::class, 'storeMenu'], [AdminMiddleware::class]);
        $router->post('/admin/menus/{id:[0-9]+}/toggle', [AdminController::class, 'toggleMenu'], [AdminMiddleware::class]);
        $router->post('/admin/menus/{id:[0-9]+}/delete', [AdminController::class, 'deleteMenu'], [AdminMiddleware::class]);

        $router->get('/admin/sliders', [AdminController::class, 'sliders'], [AdminMiddleware::class]);
        $router->get('/admin/sliders/create', [AdminController::class, 'createSlider'], [AdminMiddleware::class]);
        $router->post('/admin/sliders', [AdminController::class, 'storeSlider'], [AdminMiddleware::class]);
        $router->post('/admin/sliders/create', [AdminController::class, 'storeSlider'], [AdminMiddleware::class]);
        $router->post('/admin/sliders/{id:[0-9]+}/toggle', [AdminController::class, 'toggleSlider'], [AdminMiddleware::class]);
        $router->post('/admin/sliders/{id:[0-9]+}/edit', [AdminController::class, 'updateSlider'], [AdminMiddleware::class]);
        $router->post('/admin/sliders/{id:[0-9]+}/delete', [AdminController::class, 'deleteSlider'], [AdminMiddleware::class]);

        $router->get('/admin/settings', [AdminController::class, 'settings'], [AdminMiddleware::class]);
        $router->post('/admin/settings', [AdminController::class, 'updateSettings'], [AdminMiddleware::class]);

        $router->get('/admin/categories', [AdminController::class, 'categories'], [AdminMiddleware::class]);
        $router->post('/admin/categories/{id:[0-9]+}', [AdminController::class, 'updateCategory'], [AdminMiddleware::class]);
        $router->post('/admin/categories/{id:[0-9]+}/toggle', [AdminController::class, 'toggleCategory'], [AdminMiddleware::class]);

        $router->get('/admin/banners', [AdminController::class, 'promoBanners'], [AdminMiddleware::class]);
        $router->post('/admin/banners/{id:[0-9]+}', [AdminController::class, 'updatePromoBanner'], [AdminMiddleware::class]);
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

    private function createAndLoginAdmin(): string
    {
        $adminEmail = 'adm_test_' . time() . mt_rand(1000, 9999) . '@example.com';
        $this->createdUserEmails[] = $adminEmail;
        $this->db->execute("
            INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `is_active`)
            VALUES ('مدیر تست', :email, :hash, 'admin', 1)
        ", [
            ':email' => $adminEmail,
            ':hash' => password_hash('adm_pass123', PASSWORD_DEFAULT),
        ]);
        $this->auth->login($adminEmail, 'adm_pass123');
        return $adminEmail;
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

    public function test_admin_pjax_tab_navigation(): void
    {
        $adminEmail = 'admin_pjax_' . time() . '@example.com';
        $this->createdUserEmails[] = $adminEmail;

        $hash = password_hash('adm_pass123', PASSWORD_BCRYPT);
        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES ('مدیر سیستم پی‌جکس', :email, :hash, 'admin', 1, NOW())",
            [':email' => $adminEmail, ':hash' => $hash]
        );

        $this->auth->login($adminEmail, 'adm_pass123');

        // 1. Dashboard PJAX
        $dashRes = $this->request('GET', '/admin/dashboard', [], [
            'X-Requested-With' => 'XMLHttpRequest',
            'X-PJAX' => 'true',
        ]);
        $this->assertSame(200, $dashRes->getStatusCode());
        $dashJson = json_decode($dashRes->getContent(), true);
        $this->assertIsArray($dashJson);
        $this->assertTrue($dashJson['success']);
        $this->assertSame('dashboard', $dashJson['active_menu']);
        $this->assertStringContainsString('admin-metrics-grid', $dashJson['html']);

        // 2. Orders PJAX
        $ordersRes = $this->request('GET', '/admin/orders', [], [
            'X-Requested-With' => 'XMLHttpRequest',
            'X-PJAX' => 'true',
        ]);
        $this->assertSame(200, $ordersRes->getStatusCode());
        $ordersJson = json_decode($ordersRes->getContent(), true);
        $this->assertIsArray($ordersJson);
        $this->assertTrue($ordersJson['success']);
        $this->assertSame('orders', $ordersJson['active_menu']);
        $this->assertStringContainsString('admin-orders-view', $ordersJson['html']);

        // 3. Products PJAX
        $productsRes = $this->request('GET', '/admin/products', [], [
            'X-Requested-With' => 'XMLHttpRequest',
            'X-PJAX' => 'true',
        ]);
        $this->assertSame(200, $productsRes->getStatusCode());
        $productsJson = json_decode($productsRes->getContent(), true);
        $this->assertIsArray($productsJson);
        $this->assertTrue($productsJson['success']);
        $this->assertSame('products', $productsJson['active_menu']);
        $this->assertStringContainsString('admin-products-view', $productsJson['html']);
    }

    public function test_admin_navigation_menus_crud(): void
    {
        $adminEmail = 'admin_menus_' . time() . '@example.com';
        $this->createdUserEmails[] = $adminEmail;

        $hash = password_hash('adm_pass123', PASSWORD_BCRYPT);
        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES ('مدیر منوها', :email, :hash, 'admin', 1, NOW())",
            [':email' => $adminEmail, ':hash' => $hash]
        );

        $this->auth->login($adminEmail, 'adm_pass123');

        // 1. View menus page
        $resView = $this->request('GET', '/admin/menus');
        $this->assertSame(200, $resView->getStatusCode());
        $this->assertStringContainsString('مدیریت منوهای ناوبری', $resView->getContent());

        // 2. Add new menu item
        $csrf = Session::csrfToken();
        $testTitle = 'لینک تستی ویژه ' . time();
        $resStore = $this->request('POST', '/admin/menus', [
            '_csrf_token' => $csrf,
            'title' => $testTitle,
            'url' => '/special-deals',
            'sort_order' => '10',
            'location' => 'header',
            'is_active' => '1',
        ]);
        $this->assertSame(302, $resStore->getStatusCode());

        /** @var array<string, mixed>|null $created */
        $created = $this->db->fetch("SELECT * FROM `navigation_menus` WHERE `title` = :t LIMIT 1", [':t' => $testTitle]);
        $this->assertNotNull($created);
        $menuId = (int) $created['id'];
        $this->assertSame('/special-deals', $created['url']);
        $this->assertSame(1, (int) $created['is_active']);

        // 3. Toggle active status
        $resToggle = $this->request('POST', "/admin/menus/{$menuId}/toggle", [
            '_csrf_token' => $csrf,
        ]);
        $this->assertSame(302, $resToggle->getStatusCode());
        /** @var array<string, mixed>|null $toggled */
        $toggled = $this->db->fetch("SELECT `is_active` FROM `navigation_menus` WHERE `id` = :id", [':id' => $menuId]);
        $this->assertNotNull($toggled);
        $this->assertSame(0, (int) $toggled['is_active']);

        // 4. Delete menu item
        $resDelete = $this->request('POST', "/admin/menus/{$menuId}/delete", [
            '_csrf_token' => $csrf,
        ]);
        $this->assertSame(302, $resDelete->getStatusCode());
        /** @var array<string, mixed>|null $deleted */
        $deleted = $this->db->fetch("SELECT `id` FROM `navigation_menus` WHERE `id` = :id", [':id' => $menuId]);
        $this->assertNull($deleted);
    }

    public function test_admin_site_settings_management(): void
    {
        $adminEmail = 'admin_settings_' . time() . '@example.com';
        $this->createdUserEmails[] = $adminEmail;

        $hash = password_hash('adm_pass123', PASSWORD_BCRYPT);
        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES ('مدیر تنظیمات', :email, :hash, 'admin', 1, NOW())",
            [':email' => $adminEmail, ':hash' => $hash]
        );

        $this->auth->login($adminEmail, 'adm_pass123');

        // Backup existing settings to prevent test pollution
        $originalSettings = \App\Services\SettingService::all();

        try {
            // 1. View settings page
            $resView = $this->request('GET', '/admin/settings');
            $this->assertSame(200, $resView->getStatusCode());
            $this->assertStringContainsString('تنظیمات عمومی سایت و متون ثابت', $resView->getContent());

            // 2. Update settings
            $csrf = Session::csrfToken();
            $testAnnouncement = 'ارسال رایگان برای همه تست‌ها ' . time();
            $testPhone = '۰۲۱-۵۵۵۵۴۴۳۳';
            $testCopyright = '© کلیه حقوق تست برای تحریرچی ' . time();

            $resUpdate = $this->request('POST', '/admin/settings', [
                '_csrf_token' => $csrf,
                'top_announcement_text' => $testAnnouncement,
                'support_phone' => $testPhone,
                'support_email' => 'custom_support@example.com',
                'store_address' => 'تهران، خیابان تست، پلاک ۱',
                'working_hours' => '۹ تا ۱۸',
                'footer_copyright' => $testCopyright,
            ]);
            $this->assertSame(302, $resUpdate->getStatusCode());

            // 3. Verify in SettingService
            $this->assertSame($testAnnouncement, \App\Services\SettingService::get('top_announcement_text'));
            $this->assertSame($testPhone, \App\Services\SettingService::get('support_phone'));
            $this->assertSame($testCopyright, \App\Services\SettingService::get('footer_copyright'));
        } finally {
            // Restore clean production settings so DB is never polluted
            foreach ($originalSettings as $key => $val) {
                $this->db->execute(
                    "UPDATE `site_settings` SET `setting_value` = :v WHERE `setting_key` = :k",
                    [':v' => $val, ':k' => $key]
                );
            }
            \App\Services\SettingService::flush();
        }
    }

    public function test_admin_sliders_crud(): void
    {
        $adminEmail = 'admin_sliders_' . time() . '@example.com';
        $this->createdUserEmails[] = $adminEmail;

        $hash = password_hash('adm_pass123', PASSWORD_BCRYPT);
        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES ('مدیر اسلایدر', :email, :hash, 'admin', 1, NOW())",
            [':email' => $adminEmail, ':hash' => $hash]
        );

        $this->auth->login($adminEmail, 'adm_pass123');

        // 1. View Sliders Page
        $resView = $this->request('GET', '/admin/sliders');
        $this->assertSame(200, $resView->getStatusCode());
        $this->assertStringContainsString('مدیریت بنرهای اسلایدر صفحه اصلی', $resView->getContent());

        // 1.1 View Create Slider Form Page
        $resCreateForm = $this->request('GET', '/admin/sliders/create');
        $this->assertSame(200, $resCreateForm->getStatusCode());
        $this->assertStringContainsString('افزودن بنر اسلایدر جدید', $resCreateForm->getContent());

        // 1.2 PJAX request
        $resPjax = $this->request('GET', '/admin/sliders', [], ['X-PJAX' => 'true']);
        $this->assertSame(200, $resPjax->getStatusCode());
        $pjaxJson = json_decode($resPjax->getContent(), true);
        $this->assertIsArray($pjaxJson);
        $this->assertTrue($pjaxJson['success'] ?? false);
        $this->assertSame('sliders', $pjaxJson['active_menu'] ?? '');

        // 2. Create New Slider via POST /admin/sliders
        $csrf = Session::csrfToken();
        $testTitle = 'بنر اسلایدر آزمایشی ' . time();
        $testTargetUrl = '/products?category=writing-instruments';

        $resCreate = $this->request('POST', '/admin/sliders', [
            '_csrf_token' => $csrf,
            'title' => $testTitle,
            'target_url' => $testTargetUrl,
            'image_path' => 'images/sliders/banner-1.webp',
            'sort_order' => '10',
            'is_active' => '1',
        ]);
        $this->assertSame(302, $resCreate->getStatusCode());

        /** @var array<string, mixed>|null $created */
        $created = $this->db->fetch("SELECT * FROM `home_sliders` WHERE `title` = :title LIMIT 1", [':title' => $testTitle]);
        $this->assertNotNull($created);
        $sliderId = (int) $created['id'];
        $this->assertSame(1, (int) $created['is_active']);
        $this->assertSame($testTargetUrl, $created['target_url']);

        // 3. Toggle Status
        $resToggle = $this->request('POST', "/admin/sliders/{$sliderId}/toggle", [
            '_csrf_token' => $csrf,
        ]);
        $this->assertSame(302, $resToggle->getStatusCode());

        /** @var array<string, mixed>|null $toggled */
        $toggled = $this->db->fetch("SELECT `is_active` FROM `home_sliders` WHERE `id` = :id", [':id' => $sliderId]);
        $this->assertNotNull($toggled);
        $this->assertSame(0, (int) $toggled['is_active']);

        // 4. Update Slider
        $resEdit = $this->request('POST', "/admin/sliders/{$sliderId}/edit", [
            '_csrf_token' => $csrf,
            'title' => $testTitle . ' ویرایش‌شده',
            'target_url' => '/products',
            'sort_order' => '5',
            'is_active' => '1',
        ]);
        $this->assertSame(302, $resEdit->getStatusCode());

        /** @var array<string, mixed>|null $updated */
        $updated = $this->db->fetch("SELECT * FROM `home_sliders` WHERE `id` = :id", [':id' => $sliderId]);
        $this->assertNotNull($updated);
        $this->assertSame($testTitle . ' ویرایش‌شده', $updated['title']);
        $this->assertSame(1, (int) $updated['is_active']);

        // 5. Delete Slider
        $resDelete = $this->request('POST', "/admin/sliders/{$sliderId}/delete", [
            '_csrf_token' => $csrf,
        ]);
        $this->assertSame(302, $resDelete->getStatusCode());

        /** @var array<string, mixed>|null $deleted */
        $deleted = $this->db->fetch("SELECT `id` FROM `home_sliders` WHERE `id` = :id", [':id' => $sliderId]);
        $this->assertNull($deleted);
    }

    public function testAdminCategoriesCrud(): void
    {
        $this->createAndLoginAdmin();
        $csrf = Session::csrfToken();

        // 1. Get Categories List
        $resList = $this->request('GET', '/admin/categories');
        $this->assertSame(200, $resList->getStatusCode());
        $this->assertStringContainsString('مدیریت دسته‌بندی‌ها', $resList->getContent());

        // 2. Fetch an existing category to test update and toggle
        /** @var array<string, mixed>|null $cat */
        $cat = $this->db->fetch("SELECT * FROM `categories` ORDER BY `id` ASC LIMIT 1");
        $this->assertNotNull($cat);
        $catId = (int) $cat['id'];
        $originalTitle = (string) $cat['title'];
        $originalSort = (int) $cat['sort_order'];
        $originalActive = (int) $cat['is_active'];

        // 3. Update Category Title and Sort Order
        $resUpdate = $this->request('POST', "/admin/categories/{$catId}", [
            '_csrf_token' => $csrf,
            'title' => $originalTitle . ' ویرایش تستی',
            'sort_order' => (string) ($originalSort + 10),
            'is_active' => '1',
        ]);
        $this->assertSame(302, $resUpdate->getStatusCode());

        /** @var array<string, mixed>|null $updated */
        $updated = $this->db->fetch("SELECT * FROM `categories` WHERE `id` = :id", [':id' => $catId]);
        $this->assertNotNull($updated);
        $this->assertSame($originalTitle . ' ویرایش تستی', $updated['title']);
        $this->assertSame($originalSort + 10, (int) $updated['sort_order']);

        // 4. Toggle Category Status
        $resToggle = $this->request('POST', "/admin/categories/{$catId}/toggle", [
            '_csrf_token' => $csrf,
        ]);
        $this->assertSame(302, $resToggle->getStatusCode());

        /** @var array<string, mixed>|null $toggled */
        $toggled = $this->db->fetch("SELECT `is_active` FROM `categories` WHERE `id` = :id", [':id' => $catId]);
        $this->assertNotNull($toggled);
        $this->assertSame(0, (int) $toggled['is_active']);

        // 5. Clean up / Restore original category values
        $this->db->execute("
            UPDATE `categories`
            SET `title` = :title, `sort_order` = :sort, `is_active` = :active
            WHERE `id` = :id
        ", [
            ':title' => $originalTitle,
            ':sort' => $originalSort,
            ':active' => $originalActive,
            ':id' => $catId,
        ]);
    }

    public function testAdminPromoBannersCrud(): void
    {
        $this->createAndLoginAdmin();
        $csrf = Session::csrfToken();

        // 1. Get Promo Banners List
        $resList = $this->request('GET', '/admin/banners');
        $this->assertSame(200, $resList->getStatusCode());
        $this->assertStringContainsString('مدیریت بنرهای تبلیغاتی میانی', $resList->getContent());
        $this->assertStringContainsString('banner_right', $resList->getContent());
        $this->assertStringContainsString('banner_left', $resList->getContent());

        // 2. Fetch banner_right
        /** @var array<string, mixed>|null $banner */
        $banner = $this->db->fetch("SELECT * FROM `promo_banners` WHERE `position` = 'banner_right' LIMIT 1");
        $this->assertNotNull($banner);
        $bannerId = (int) $banner['id'];
        $origTitle = (string) $banner['title'];
        $origBadge = (string) $banner['badge_text'];
        $origDesc = (string) $banner['description'];
        $origBtn = (string) $banner['button_text'];
        $origUrl = (string) $banner['target_url'];

        // 3. Update Promo Banner
        $resUpdate = $this->request('POST', "/admin/banners/{$bannerId}", [
            '_csrf_token' => $csrf,
            'badge_text' => '✨ تخفیف ویژه تستی',
            'title' => 'پک‌های هیجان‌انگیز تستی',
            'description' => 'توضیحات تست بنر پروموشن',
            'button_text' => 'خرید آنلاین ←',
            'target_url' => '/products?category=test',
            'is_active' => '1',
        ]);
        $this->assertSame(302, $resUpdate->getStatusCode());

        /** @var array<string, mixed>|null $updated */
        $updated = $this->db->fetch("SELECT * FROM `promo_banners` WHERE `id` = :id", [':id' => $bannerId]);
        $this->assertNotNull($updated);
        $this->assertSame('✨ تخفیف ویژه تستی', $updated['badge_text']);
        $this->assertSame('پک‌های هیجان‌انگیز تستی', $updated['title']);
        $this->assertSame('توضیحات تست بنر پروموشن', $updated['description']);
        $this->assertSame('خرید آنلاین ←', $updated['button_text']);
        $this->assertSame('/products?category=test', $updated['target_url']);

        // 4. Restore original banner values
        $this->db->execute("
            UPDATE `promo_banners`
            SET `badge_text` = :badge,
                `title` = :title,
                `description` = :desc,
                `button_text` = :btn,
                `target_url` = :url
            WHERE `id` = :id
        ", [
            ':badge' => $origBadge,
            ':title' => $origTitle,
            ':desc' => $origDesc,
            ':btn' => $origBtn,
            ':url' => $origUrl,
            ':id' => $bannerId,
        ]);
    }

    public function testAdminSessionInactivityTimeout(): void
    {
        $this->createAndLoginAdmin();
        $this->assertTrue($this->auth->check());
        $this->assertArrayHasKey('admin_last_activity', $_SESSION);

        // Case 1: Active admin within 12 hours refreshes admin_last_activity
        $recentTime = time() - 3600;
        $_SESSION['admin_last_activity'] = $recentTime;
        $active = Session::checkAdminInactivityTimeout();

        $this->assertTrue($active);
        $this->assertTrue(Session::has('user'));
        $this->assertGreaterThanOrEqual($recentTime, (int) $_SESSION['admin_last_activity']);

        // Case 2: Inactive admin beyond 12 hours (43200s) invalidates session
        $_SESSION['admin_last_activity'] = time() - 43205;
        $timeoutResult = Session::checkAdminInactivityTimeout();

        $this->assertFalse($timeoutResult);
        $this->assertFalse(Session::has('user'));
        $this->assertTrue(Session::hasFlash('error'));
        $flashMsg = Session::getFlash('error');
        $this->assertStringContainsString('۱۲ ساعت', (string) $flashMsg);

        // Case 3: AdminMiddleware redirects to /admin/login when session timed out
        $this->createAndLoginAdmin();
        $_SESSION['admin_last_activity'] = time() - 43205;
        $response = $this->request('GET', '/admin/dashboard');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('admin/login', $response->getHeader('Location') ?? '');
    }
}
