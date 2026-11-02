<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Session;
use App\Repositories\ProductRepository;
use App\Services\CartService;
use PHPUnit\Framework\TestCase;

class CartServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear session cart
        Session::start();
        Session::remove('_cart');
    }

    public function test_add_item_to_cart_calculates_subtotal_and_count(): void
    {
        // Mock ProductRepository to isolate unit test
        $mockProductRepo = $this->createMock(ProductRepository::class);
        $mockProductRepo->method('findById')->willReturn([
            'id' => 1,
            'title' => 'روان‌نویس اشنایدر One Business',
            'slug' => 'schneider-one-business',
            'price' => 125000,
            'stock_quantity' => 10,
            'primary_image' => '/assets/images/schneider.webp',
            'variants' => [],
        ]);

        $cartService = new CartService($mockProductRepo);

        $result = $cartService->add(1, null, 2);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $cartService->getTotalCount());
        $this->assertSame(250000.0, $cartService->getSubtotal());
    }

    public function test_cannot_add_item_with_insufficient_stock(): void
    {
        $mockProductRepo = $this->createMock(ProductRepository::class);
        $mockProductRepo->method('findById')->willReturn([
            'id' => 2,
            'title' => 'اتود روترینگ',
            'slug' => 'rotring-tikky',
            'price' => 240000,
            'stock_quantity' => 3,
            'primary_image' => '/assets/images/rotring.webp',
            'variants' => [],
        ]);

        $cartService = new CartService($mockProductRepo);

        $result = $cartService->add(2, null, 5);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('حداکثر موجودی قابل سفارش', $result['message']);
        $this->assertSame(0, $cartService->getTotalCount());
    }

    public function test_remove_item_from_cart(): void
    {
        $mockProductRepo = $this->createMock(ProductRepository::class);
        $mockProductRepo->method('findById')->willReturn([
            'id' => 3,
            'title' => 'مدادرنگی فابرکاستل',
            'slug' => 'faber-castell-24',
            'price' => 590000,
            'stock_quantity' => 20,
            'primary_image' => '',
            'variants' => [],
        ]);

        $cartService = new CartService($mockProductRepo);
        $cartService->add(3, null, 1);
        $this->assertSame(1, $cartService->getTotalCount());

        $result = $cartService->remove('prod_3');
        $this->assertTrue($result['success']);
        $this->assertSame(0, $cartService->getTotalCount());
        $this->assertSame(0.0, $cartService->getSubtotal());
    }
}
