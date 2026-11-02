<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Core\Application;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middlewares\AuthMiddleware;
use App\Services\AuthService;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
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

        // Register Auth & Account Routes
        $router->get('/login', [AuthController::class, 'showLogin']);
        $router->post('/login', [AuthController::class, 'login']);
        $router->get('/register', [AuthController::class, 'showRegister']);
        $router->post('/register', [AuthController::class, 'register']);
        $router->post('/logout', [AuthController::class, 'logout']);

        $router->get('/account/dashboard', [AccountController::class, 'dashboard'], [AuthMiddleware::class]);
        $router->get('/account/orders', [AccountController::class, 'orders'], [AuthMiddleware::class]);
        $router->get('/account/wishlist', [AccountController::class, 'wishlist'], [AuthMiddleware::class]);
        $router->post('/account/wishlist/toggle', [AccountController::class, 'toggleWishlist']);
        $router->get('/account/profile', [AccountController::class, 'profile'], [AuthMiddleware::class]);
        $router->post('/account/profile', [AccountController::class, 'profile'], [AuthMiddleware::class]);
    }

    protected function tearDown(): void
    {
        // Clean up any test users created during tests
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

    public function test_auth_pages_render_successfully(): void
    {
        // Login Page
        $loginRes = $this->request('GET', '/login');
        $this->assertSame(200, $loginRes->getStatusCode());
        $this->assertStringContainsString('ورود به حساب کاربری', $loginRes->getContent());

        // Register Page
        $regRes = $this->request('GET', '/register');
        $this->assertSame(200, $regRes->getStatusCode());
        $this->assertStringContainsString('عضویت در فروشگاه تحریرچی', $regRes->getContent());
    }

    public function test_user_registration_success_and_duplicate_prevention(): void
    {
        $testEmail = 'customer_' . time() . '_' . bin2hex(random_bytes(2)) . '@example.com';
        $this->createdUserEmails[] = $testEmail;

        $csrfToken = Session::csrfToken();
        $payload = [
            '_csrf_token' => $csrfToken,
            'full_name' => 'آرمان اسدی',
            'email' => $testEmail,
            'phone' => '0912999' . rand(1000, 9999),
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ];

        // 1. First registration attempt: Success
        $response = $this->request('POST', '/register', $payload, ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertSame(200, $response->getStatusCode());
        $json = json_decode($response->getContent(), true);
        $this->assertIsArray($json);
        $this->assertTrue($json['success']);

        // Assert DB entry
        $userInDb = $this->db->fetch("SELECT * FROM `users` WHERE `email` = :email", [':email' => $testEmail]);
        $this->assertNotNull($userInDb);
        $this->assertSame('آرمان اسدی', $userInDb['full_name']);
        $this->assertTrue(password_verify('secret123', (string) $userInDb['password_hash']));

        // Assert auto-login
        $this->assertTrue($this->auth->check());
        $this->assertSame($testEmail, Session::get('user')['email']);

        // 2. Duplicate registration attempt: Should fail
        $this->auth->logout();
        $payload['_csrf_token'] = Session::csrfToken();
        $dupResponse = $this->request('POST', '/register', $payload, ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertSame(422, $dupResponse->getStatusCode());
        $dupJson = json_decode($dupResponse->getContent(), true);
        $this->assertIsArray($dupJson);
        $this->assertFalse($dupJson['success']);
        $this->assertArrayHasKey('email', $dupJson['errors']);
    }

    public function test_user_login_success_and_invalid_credentials(): void
    {
        $testEmail = 'login_test_' . time() . '@example.com';
        $this->createdUserEmails[] = $testEmail;

        // Create user in DB
        $hash = password_hash('correct_pass', PASSWORD_BCRYPT);
        $testPhone = '0935' . rand(1000000, 9999999);
        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `phone`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES ('کاربر تست', :email, :phone, :hash, 'customer', 1, NOW())",
            [':email' => $testEmail, ':phone' => $testPhone, ':hash' => $hash]
        );

        $csrfToken = Session::csrfToken();

        // 1. Invalid Password attempt
        $badPayload = [
            '_csrf_token' => $csrfToken,
            'identifier' => $testEmail,
            'password' => 'wrong_pass',
        ];
        $badRes = $this->request('POST', '/login', $badPayload, ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertSame(422, $badRes->getStatusCode());
        $badJson = json_decode($badRes->getContent(), true);
        $this->assertIsArray($badJson);
        $this->assertFalse($badJson['success']);
        $this->assertFalse($this->auth->check());

        // 2. Correct Password attempt
        $goodPayload = [
            '_csrf_token' => $csrfToken,
            'identifier' => $testEmail,
            'password' => 'correct_pass',
        ];
        $goodRes = $this->request('POST', '/login', $goodPayload, ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertSame(200, $goodRes->getStatusCode());
        $goodJson = json_decode($goodRes->getContent(), true);
        $this->assertIsArray($goodJson);
        $this->assertTrue($goodJson['success']);
        $this->assertTrue($this->auth->check());
        $this->assertSame($testEmail, Session::get('user')['email']);
    }

    public function test_middleware_protects_dashboard_from_unauthenticated_guests(): void
    {
        $this->auth->logout();

        $response = $this->request('GET', '/account/dashboard');

        // Should return 302 redirect to /login
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('login', $response->getHeader('Location') ?? '');
        $this->assertSame('/account/dashboard', Session::get('intended_url'));
    }

    public function test_authenticated_user_can_access_dashboard_and_profile(): void
    {
        $testEmail = 'member_' . time() . '@example.com';
        $testPhone = '0912' . rand(1000000, 9999999);
        $this->createdUserEmails[] = $testEmail;

        $hash = password_hash('member_pass', PASSWORD_BCRYPT);
        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `phone`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES ('سحر امینی', :email, :phone, :hash, 'customer', 1, NOW())",
            [':email' => $testEmail, ':phone' => $testPhone, ':hash' => $hash]
        );
        $userId = (int) $this->db->lastInsertId();

        // Login user
        $this->auth->login($testEmail, 'member_pass');
        $this->assertTrue($this->auth->check());

        // 1. Dashboard Access
        $dashRes = $this->request('GET', '/account/dashboard');
        $this->assertSame(200, $dashRes->getStatusCode());
        $dashContent = $dashRes->getContent();
        $this->assertStringContainsString('خلاصه وضعیت حساب کاربری', $dashContent);
        $this->assertStringContainsString('سحر امینی', $dashContent);

        // 2. Orders History Access
        $ordersRes = $this->request('GET', '/account/orders');
        $this->assertSame(200, $ordersRes->getStatusCode());
        $this->assertStringContainsString('تاریخچه سفارش‌های من', $ordersRes->getContent());

        // 3. Wishlist Access
        $wishRes = $this->request('GET', '/account/wishlist');
        $this->assertSame(200, $wishRes->getStatusCode());
        $this->assertStringContainsString('کالاهای مورد علاقه', $wishRes->getContent());

        // 4. Profile Access
        $profRes = $this->request('GET', '/account/profile');
        $this->assertSame(200, $profRes->getStatusCode());
        $this->assertStringContainsString('اطلاعات فردی و امنیت حساب', $profRes->getContent());
    }

    public function test_wishlist_toggle_via_ajax(): void
    {
        $testEmail = 'wish_user_' . time() . '@example.com';
        $this->createdUserEmails[] = $testEmail;

        $hash = password_hash('wish_pass', PASSWORD_BCRYPT);
        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES ('کاربر نشان‌گذار', :email, :hash, 'customer', 1, NOW())",
            [':email' => $testEmail, ':hash' => $hash]
        );
        $userId = (int) $this->db->lastInsertId();

        // Get a valid product ID
        $prod = $this->db->fetch("SELECT `id` FROM `products` WHERE `is_active` = 1 LIMIT 1");
        $productId = (int) ($prod['id'] ?? 1);

        // 1. Unauthenticated toggle should return 401
        $this->auth->logout();
        $unauthRes = $this->request('POST', '/account/wishlist/toggle', ['product_id' => $productId]);
        $this->assertSame(401, $unauthRes->getStatusCode());

        // 2. Authenticated toggle: Add
        $this->auth->login($testEmail, 'wish_pass');
        $csrfToken = Session::csrfToken();

        $addRes = $this->request('POST', '/account/wishlist/toggle', [
            '_csrf_token' => $csrfToken,
            'product_id' => $productId,
        ]);
        $this->assertSame(200, $addRes->getStatusCode());
        $addJson = json_decode($addRes->getContent(), true);
        $this->assertTrue($addJson['success']);
        $this->assertTrue($addJson['in_wishlist']);
        $this->assertGreaterThanOrEqual(1, $addJson['total_count']);

        // 3. Authenticated toggle again: Remove
        $remRes = $this->request('POST', '/account/wishlist/toggle', [
            '_csrf_token' => $csrfToken,
            'product_id' => $productId,
        ]);
        $this->assertSame(200, $remRes->getStatusCode());
        $remJson = json_decode($remRes->getContent(), true);
        $this->assertTrue($remJson['success']);
        $this->assertFalse($remJson['in_wishlist']);
    }

    public function test_logout_clears_session(): void
    {
        $testEmail = 'logout_user_' . time() . '@example.com';
        $this->createdUserEmails[] = $testEmail;

        $hash = password_hash('pass123', PASSWORD_BCRYPT);
        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES ('تست خروج', :email, :hash, 'customer', 1, NOW())",
            [':email' => $testEmail, ':hash' => $hash]
        );

        $this->auth->login($testEmail, 'pass123');
        $this->assertTrue($this->auth->check());

        $csrfToken = Session::csrfToken();
        $logoutRes = $this->request('POST', '/logout', ['_csrf_token' => $csrfToken]);

        $this->assertSame(302, $logoutRes->getStatusCode());
        $this->assertFalse($this->auth->check());
        $this->assertNull(Session::get('user'));
    }

    public function test_customer_orders_ajax_filter(): void
    {
        $testEmail = 'ajax_order_cust_' . time() . '@example.com';
        $this->createdUserEmails[] = $testEmail;

        $hash = password_hash('pass123', PASSWORD_BCRYPT);
        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES ('مشتری فیلتر ایجکس', :email, :hash, 'customer', 1, NOW())",
            [':email' => $testEmail, ':hash' => $hash]
        );

        $this->auth->login($testEmail, 'pass123');

        $response = $this->request('GET', '/account/orders?status=processing', [], [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $json = json_decode($response->getContent(), true);
        $this->assertIsArray($json);
        $this->assertTrue($json['success']);
        $this->assertArrayHasKey('html', $json);
        $this->assertSame('processing', $json['current_filter']);
    }

    public function test_customer_pjax_tab_navigation(): void
    {
        $testEmail = 'pjax_cust_' . time() . '@example.com';
        $this->createdUserEmails[] = $testEmail;

        $hash = password_hash('pass123', PASSWORD_BCRYPT);
        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES ('مشتری تست پی‌جکس', :email, :hash, 'customer', 1, NOW())",
            [':email' => $testEmail, ':hash' => $hash]
        );

        $this->auth->login($testEmail, 'pass123');

        // Test dashboard PJAX
        $dashRes = $this->request('GET', '/account/dashboard', [], [
            'X-Requested-With' => 'XMLHttpRequest',
            'X-PJAX' => 'true',
        ]);
        $this->assertSame(200, $dashRes->getStatusCode());
        $dashJson = json_decode($dashRes->getContent(), true);
        $this->assertIsArray($dashJson);
        $this->assertTrue($dashJson['success']);
        $this->assertSame('dashboard', $dashJson['active_tab']);
        $this->assertStringContainsString('خلاصه وضعیت حساب کاربری', $dashJson['html']);

        // Test orders PJAX
        $ordersRes = $this->request('GET', '/account/orders', [], [
            'X-Requested-With' => 'XMLHttpRequest',
            'X-PJAX' => 'true',
        ]);
        $this->assertSame(200, $ordersRes->getStatusCode());
        $ordersJson = json_decode($ordersRes->getContent(), true);
        $this->assertIsArray($ordersJson);
        $this->assertTrue($ordersJson['success']);
        $this->assertSame('orders', $ordersJson['active_tab']);
        $this->assertStringContainsString('orders-filter-strip', $ordersJson['html']);

        // Test wishlist PJAX
        $wishRes = $this->request('GET', '/account/wishlist', [], [
            'X-Requested-With' => 'XMLHttpRequest',
            'X-PJAX' => 'true',
        ]);
        $this->assertSame(200, $wishRes->getStatusCode());
        $wishJson = json_decode($wishRes->getContent(), true);
        $this->assertIsArray($wishJson);
        $this->assertTrue($wishJson['success']);
        $this->assertSame('wishlist', $wishJson['active_tab']);

        // Test profile PJAX
        $profRes = $this->request('GET', '/account/profile', [], [
            'X-Requested-With' => 'XMLHttpRequest',
            'X-PJAX' => 'true',
        ]);
        $this->assertSame(200, $profRes->getStatusCode());
        $profJson = json_decode($profRes->getContent(), true);
        $this->assertIsArray($profJson);
        $this->assertTrue($profJson['success']);
        $this->assertSame('profile', $profJson['active_tab']);
    }
}
