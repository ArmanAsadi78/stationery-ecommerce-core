<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SitemapController;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Services\CacheService;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

class SitemapTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->safeLoad();

        $this->app = new Application();
        $router = $this->app->getRouter();

        $router->get('/sitemap.xml', [SitemapController::class, 'index']);
    }

    private function get(string $uri): Response
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
        ];

        $request = new Request([], [], $server, [], []);
        return $this->app->handle($request);
    }

    public function test_sitemap_returns_valid_xml_and_correct_headers(): void
    {
        // 1. Clear Sitemap Cache
        (new CacheService('sitemap'))->flush();

        // 2. First Request: Cache Miss
        $response = $this->get('/sitemap.xml');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/xml; charset=UTF-8', $response->getHeader('Content-Type'));
        $this->assertSame('MISS', $response->getHeader('X-Cache'));

        $content = $response->getContent();
        $this->assertNotEmpty($content);
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $content);

        // 3. Parse with SimpleXML to validate standard compliance
        $xml = simplexml_load_string($content);
        $this->assertInstanceOf(SimpleXMLElement::class, $xml);
        $this->assertSame('urlset', $xml->getName());

        $this->assertGreaterThan(0, count($xml->url));

        // 4. Verify Homepage entry
        $firstUrl = $xml->url[0];
        $this->assertSame('1.0', (string) $firstUrl->priority);
        $this->assertSame('daily', (string) $firstUrl->changefreq);
        $this->assertNotEmpty((string) $firstUrl->lastmod);

        // 5. Verify presence of categories and product URLs
        $urlsJoined = $content;
        $this->assertStringContainsString('/products', $urlsJoined);
        $this->assertStringContainsString('category=', $urlsJoined);
    }

    public function test_sitemap_caching_returns_hit_header(): void
    {
        (new CacheService('sitemap'))->flush();

        // First Request: Cache Miss
        $res1 = $this->get('/sitemap.xml');
        $this->assertSame('MISS', $res1->getHeader('X-Cache'));

        // Second Request: Cache Hit
        $res2 = $this->get('/sitemap.xml');
        $this->assertSame('HIT', $res2->getHeader('X-Cache'));

        $this->assertSame($res1->getContent(), $res2->getContent());
    }
}
