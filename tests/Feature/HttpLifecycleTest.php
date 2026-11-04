<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class HttpLifecycleTest extends TestCase
{
    /**
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    private function makeRequest(string $method, string $uri, array $post = [], array $headers = []): string
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_GET = [];
        $_POST = $post;

        $parsedUrl = parse_url($uri);
        if (!empty($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $_GET);
        }

        foreach ($headers as $k => $v) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }

        ob_start();
        require dirname(__DIR__, 2) . '/public/index.php';
        return (string) ob_get_clean();
    }

    public function test_homepage_renders_successfully(): void
    {
        $body = $this->makeRequest('GET', '/');

        $this->assertStringContainsString('تحریرچی', $body);
        $this->assertStringContainsString('سبد خرید', $body);
        $this->assertStringContainsString('hero-banner-slider', $body);
        $this->assertStringContainsString('vanilla-tilt', $body);
        $this->assertStringContainsString('story-categories-section', $body);
        $this->assertStringContainsString('story-icon-img', $body);
        $this->assertStringContainsString('promo-tiles-section', $body);
        $this->assertStringContainsString('promo-composite-img', $body);
    }

    public function test_catalog_page_renders_with_categories(): void
    {
        $body = $this->makeRequest('GET', '/products');

        $this->assertStringContainsString('کاتالوگ تمامی محصولات', $body);
        $this->assertStringContainsString('دسته‌بندی‌های نوشت‌افزار', $body);
    }

    public function test_catalog_search_works_without_pdo_error(): void
    {
        $body = $this->makeRequest('GET', '/products?q=روان‌نویس');

        $this->assertStringContainsString('نتایج جستجو برای', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
    }

    public function test_product_detail_page_includes_schema_org(): void
    {
        $body = $this->makeRequest('GET', '/products/schneider-one-business-rollerball-pen-06mm');

        $this->assertStringContainsString('https://schema.org/', $body);
        $this->assertStringContainsString('روان‌نویس اشنایدر', $body);
    }

    public function test_cart_data_endpoint_returns_json(): void
    {
        $body = $this->makeRequest('GET', '/cart/data', [], ['Accept' => 'application/json']);
        $json = json_decode($body, true);

        $this->assertIsArray($json);
        $this->assertTrue($json['success'] ?? false);
        $this->assertArrayHasKey('cart', $json);
    }

    public function test_checkout_page_renders_with_active_cart(): void
    {
        \App\Core\Session::start();
        $cartService = new \App\Services\CartService();
        $cartService->add(6, null, 1);

        $body = $this->makeRequest('GET', '/checkout');

        $this->assertStringContainsString('مشخصات تحویل‌گیرنده', $body);
        $this->assertStringContainsString('ثبت نهایی سفارش', $body);
        $this->assertStringContainsString('خلاصه فاکتور خرید', $body);
    }

    public function test_ajax_coupon_apply_and_remove(): void
    {
        \App\Core\Session::start();
        $csrfToken = \App\Core\Session::csrfToken();
        $cartService = new \App\Services\CartService();
        $cartService->add(6, null, 2);

        // Apply Coupon via AJAX
        $applyBody = $this->makeRequest('POST', '/checkout/apply-coupon', [
            'coupon_code' => 'WELCOME10',
            '_csrf_token' => $csrfToken,
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $applyJson = json_decode($applyBody, true);
        $this->assertIsArray($applyJson);
        $this->assertTrue($applyJson['success'] ?? false);
        $this->assertSame('WELCOME10', $applyJson['coupon_code'] ?? null);
        $this->assertArrayHasKey('formatted_discount', $applyJson);
        $this->assertArrayHasKey('formatted_payable', $applyJson);

        // Remove Coupon via AJAX
        $removeBody = $this->makeRequest('POST', '/checkout/remove-coupon', [
            '_csrf_token' => $csrfToken,
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $removeJson = json_decode($removeBody, true);
        $this->assertIsArray($removeJson);
        $this->assertTrue($removeJson['success'] ?? false);
        $this->assertSame(0.0, (float) ($removeJson['discount_amount'] ?? -1));
    }
}
