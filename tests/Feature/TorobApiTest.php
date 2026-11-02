<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\TorobApiController;
use App\Core\Application;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\CacheService;
use App\Services\OrderService;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

class TorobApiTest extends TestCase
{
    private Application $app;
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();

        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->safeLoad();

        $this->db = Database::getInstance();
        $this->app = new Application();
        $router = $this->app->getRouter();

        $router->get('/api/torob-products', [TorobApiController::class, 'index']);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function get(string $uri, array $query = []): Response
    {
        $queryString = !empty($query) ? '?' . http_build_query($query) : '';
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri . $queryString,
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
        ];

        $request = new Request($query, [], $server, [], []);
        return $this->app->handle($request);
    }

    public function test_torob_products_returns_valid_structure_and_types(): void
    {
        // 1. Flush Torob Cache to ensure fresh query
        CacheService::flushTorob();

        $response = $this->get('/api/torob-products');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json; charset=UTF-8', $response->getHeader('Content-Type'));
        $this->assertSame('MISS', $response->getHeader('X-Cache'));

        /** @var mixed $data */
        $data = json_decode($response->getContent(), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('count', $data);
        $this->assertArrayHasKey('max_pages', $data);
        $this->assertArrayHasKey('products', $data);

        /** @var array{count: int, max_pages: int, products: list<array<string, mixed>>} $data */

        $this->assertGreaterThan(0, $data['count']);
        $this->assertNotEmpty($data['products']);

        // Inspect First Product Fields
        $firstProduct = $data['products'][0];

        $this->assertArrayHasKey('page_unique_code', $firstProduct);
        $this->assertIsString($firstProduct['page_unique_code']);

        $this->assertArrayHasKey('title', $firstProduct);
        $this->assertNotEmpty($firstProduct['title']);

        $this->assertArrayHasKey('page_url', $firstProduct);
        $this->assertStringStartsWith('http://', (string) $firstProduct['page_url']);

        $this->assertArrayHasKey('price', $firstProduct);
        $this->assertIsInt($firstProduct['price']);
        $this->assertGreaterThan(0, $firstProduct['price']);

        $this->assertArrayHasKey('availability', $firstProduct);
        $this->assertContains($firstProduct['availability'], ['instock', 'outofstock']);

        $this->assertArrayHasKey('image_links', $firstProduct);
        $this->assertIsArray($firstProduct['image_links']);
        $this->assertNotEmpty($firstProduct['image_links']);
        $this->assertStringStartsWith('http://', (string) $firstProduct['image_links'][0]);

        $this->assertArrayHasKey('category_name', $firstProduct);
        $this->assertNotEmpty($firstProduct['category_name']);

        $this->assertArrayHasKey('spec', $firstProduct);
    }

    public function test_torob_cache_hit_on_subsequent_request(): void
    {
        CacheService::flushTorob();

        // First Request: Cache Miss
        $res1 = $this->get('/api/torob-products', ['page' => 1, 'limit' => 10]);
        $this->assertSame('MISS', $res1->getHeader('X-Cache'));

        // Second Request: Cache Hit
        $res2 = $this->get('/api/torob-products', ['page' => 1, 'limit' => 10]);
        $this->assertSame('HIT', $res2->getHeader('X-Cache'));

        // Verify content matches exactly
        $this->assertSame($res1->getContent(), $res2->getContent());
    }

    public function test_torob_pagination_parameters(): void
    {
        CacheService::flushTorob();

        $limit = 2;
        $response = $this->get('/api/torob-products', ['page' => 1, 'limit' => $limit]);
        $this->assertSame(200, $response->getStatusCode());

        /** @var mixed $data */
        $data = json_decode($response->getContent(), true);

        $this->assertIsArray($data);
        /** @var array{count: int, max_pages: int, products: list<array<string, mixed>>} $data */
        $this->assertLessThanOrEqual($limit, count($data['products']));
    }

    public function test_torob_cache_invalidation_when_order_created(): void
    {
        // 1. Warm Cache
        CacheService::flushTorob();
        $this->get('/api/torob-products', ['page' => 1, 'limit' => 50]);
        $warmCheck = $this->get('/api/torob-products', ['page' => 1, 'limit' => 50]);
        $this->assertSame('HIT', $warmCheck->getHeader('X-Cache'));

        // 2. Trigger Order Creation (Deduct inventory and trigger CacheService::flushTorob())
        $prod = $this->db->fetch("SELECT `id` FROM `products` WHERE `is_active` = 1 AND `stock_quantity` > 5 LIMIT 1");
        $this->assertNotNull($prod);

        $orderService = new OrderService($this->db);
        $order = $orderService->createOrder([
            'recipient_name' => 'تست باطل‌سازی کش ترب',
            'recipient_phone' => '09123334455',
            'shipping_province' => 'تهران',
            'shipping_city' => 'تهران',
            'shipping_postal_code' => '1111122222',
            'shipping_address' => 'خیابان جمهوری، تست کشینگ',
        ], [
            'item_1' => [
                'product_id' => (int) $prod['id'],
                'variant_id' => null,
                'quantity' => 1,
            ],
        ]);

        $this->assertGreaterThan(0, $order['id']);

        // 3. Subsequent Request must be MISS because cache was invalidated
        $afterOrderRes = $this->get('/api/torob-products', ['page' => 1, 'limit' => 50]);
        $this->assertSame('MISS', $afterOrderRes->getHeader('X-Cache'));
    }
}
