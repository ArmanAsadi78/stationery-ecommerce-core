<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\PageController;
use App\Core\Application;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

class PageRoutesTest extends TestCase
{
    private Application $app;
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();

        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->safeLoad();

        Session::start();
        $this->db = Database::getInstance();

        $this->app = new Application();
        $router = $this->app->getRouter();

        // Register Page Routes
        $router->get('/about-us', [PageController::class, 'about']);
        $router->get('/contact-us', [PageController::class, 'contact']);
        $router->post('/contact-us', [PageController::class, 'submitContact']);
        $router->get('/faq', [PageController::class, 'faq']);
        $router->get('/terms', [PageController::class, 'terms']);
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

    public function test_about_page_renders_successfully(): void
    {
        $response = $this->request('GET', '/about-us');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeader('Content-Type') ?? '');

        $content = $response->getContent();
        $this->assertStringContainsString('داستان تحریرچی', $content);
        $this->assertStringContainsString('تضمین اصالت فیزیکی', $content);
        $this->assertStringContainsString('بسته‌بندی ایمن اقلام حساس', $content);
    }

    public function test_contact_page_renders_successfully(): void
    {
        $response = $this->request('GET', '/contact-us');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeader('Content-Type') ?? '');

        $content = $response->getContent();
        $this->assertStringContainsString('اطلاعات تماس و دفتر مرکزی', $content);
        $this->assertStringContainsString('۰۲۱-۸۸۸۸۴۳۲۱', $content);
        $this->assertStringContainsString('support@tahrirchi.com', $content);
        $this->assertStringContainsString('id="contactForm"', $content);
    }

    public function test_faq_page_renders_successfully(): void
    {
        $response = $this->request('GET', '/faq');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeader('Content-Type') ?? '');

        $content = $response->getContent();
        $this->assertStringContainsString('پاسخ به پرتکرارترین پرسش‌های شما', $content);
        $this->assertStringContainsString('ثبت سفارش و شیوه‌های پرداخت', $content);
        $this->assertStringContainsString('faq-accordion-group', $content);
    }

    public function test_terms_page_renders_successfully(): void
    {
        $response = $this->request('GET', '/terms');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeader('Content-Type') ?? '');

        $content = $response->getContent();
        $this->assertStringContainsString('قوانین، رویه‌های بازگشت کالا و حریم خصوصی', $content);
        $this->assertStringContainsString('ضمانت بازگشت ۷ روزه کالا', $content);
        $this->assertStringContainsString('سیاست حفظ حریم خصوصی کاربران', $content);
    }

    public function test_contact_form_submission_requires_csrf(): void
    {
        $payload = [
            'name' => 'کاربر تستی',
            'email' => 'test@example.com',
            'subject' => 'پرسش تستی',
            'message' => 'متن پیام تستی برای بررسی اعتبارسنجی توکن',
            '_csrf_token' => 'invalid-or-fake-csrf-token',
        ];

        $response = $this->request('POST', '/contact-us', $payload);

        $this->assertSame(403, $response->getStatusCode());
        $json = json_decode($response->getContent(), true);
        $this->assertIsArray($json);
        $this->assertFalse($json['success']);
        $this->assertStringContainsString('توکن امنیتی معتبر نیست', $json['message']);
    }

    public function test_contact_form_validation_errors(): void
    {
        $csrfToken = Session::csrfToken();
        $payload = [
            '_csrf_token' => $csrfToken,
            'name' => 'A', // Too short (< 2)
            'email' => 'not-an-email',
            'subject' => '',
            'message' => 'کوتاه', // Too short (< 10)
        ];

        $response = $this->request('POST', '/contact-us', $payload);

        $this->assertSame(422, $response->getStatusCode());
        $json = json_decode($response->getContent(), true);
        $this->assertIsArray($json);
        $this->assertFalse($json['success']);
        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('name', $json['errors']);
        $this->assertArrayHasKey('email', $json['errors']);
        $this->assertArrayHasKey('subject', $json['errors']);
        $this->assertArrayHasKey('message', $json['errors']);
    }

    public function test_contact_form_saves_message_successfully(): void
    {
        $testEmail = 'arman.test.' . time() . '@example.com';
        $csrfToken = Session::csrfToken();

        $payload = [
            '_csrf_token' => $csrfToken,
            'name' => 'آرمان اسدی',
            'email' => $testEmail,
            'phone' => '09123456789',
            'subject' => 'استعلام موجودی روان‌نویس فابرکاستل',
            'message' => 'با سلام، آیا روان‌نویس فابرکاستل ۰.۵ میلی‌متری رنگ مشکی مجدداً شارژ خواهد شد؟',
        ];

        $response = $this->request('POST', '/contact-us', $payload);

        $this->assertSame(200, $response->getStatusCode());
        $json = json_decode($response->getContent(), true);
        $this->assertIsArray($json);
        $this->assertTrue($json['success']);
        $this->assertStringContainsString('پیام شما با موفقیت دریافت شد', $json['message']);

        // Verify insertion into database
        $record = $this->db->fetch(
            "SELECT * FROM `contact_messages` WHERE `email` = ? ORDER BY `id` DESC LIMIT 1",
            [$testEmail]
        );

        $this->assertNotNull($record, 'The contact message record was not saved to database.');
        $this->assertSame('آرمان اسدی', $record['name']);
        $this->assertSame($testEmail, $record['email']);
        $this->assertSame('09123456789', $record['phone']);
        $this->assertSame('استعلام موجودی روان‌نویس فابرکاستل', $record['subject']);
        $this->assertStringContainsString('روان‌نویس فابرکاستل', $record['message']);
        $this->assertEquals(0, $record['is_read']);

        // Clean up test record
        $this->db->execute("DELETE FROM `contact_messages` WHERE `id` = ?", [$record['id']]);
    }
}
