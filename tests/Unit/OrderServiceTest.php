<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use App\Core\Session;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OrderServiceTest extends TestCase
{
    private Database $db;
    private OrderService $orderService;
    private CouponService $couponService;
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();

        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->safeLoad();

        Session::start();
        $this->db = Database::getInstance();
        $this->couponService = new CouponService($this->db);
        $this->orderService = new OrderService($this->db, $this->couponService);
        $this->paymentService = new PaymentService($this->db);
    }

    private function getOrCreateTestProduct(): int
    {
        $cat = $this->db->fetch("SELECT `id` FROM `categories` ORDER BY `id` ASC LIMIT 1");
        if (!$cat) {
            $this->db->execute("
                INSERT INTO `categories` (`title`, `slug`, `sort_order`, `is_active`)
                VALUES ('دسته‌بندی تست', 'test-category', 1, 1)
            ");
            $cat = $this->db->fetch("SELECT `id` FROM `categories` WHERE `slug` = 'test-category'");
        }
        $catId = (int) $cat['id'];

        $this->db->execute("
            INSERT INTO `products` 
            (`category_id`, `title`, `slug`, `sku`, `price`, `stock_quantity`, `is_active`)
            VALUES 
            (:cat_id, 'محصول تستی جهت بررسی قفل انبار', 'test-pessimistic-locking-product', 'TEST-SKU-LOCK-01', 120000, 15, 1)
            ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `price` = 120000, `stock_quantity` = 15, `is_active` = 1
        ", [':cat_id' => $catId]);

        $prod = $this->db->fetch(
            "SELECT `id` FROM `products` WHERE `sku` = 'TEST-SKU-LOCK-01'"
        );

        return (int) $prod['id'];
    }

    public function test_coupon_validation_and_discount_calculation(): void
    {
        // 1. Percentage Coupon (WELCOME10 - 10%, max 50,000, min 100,000)
        $res1 = $this->couponService->validate('WELCOME10', 200000);
        $this->assertTrue($res1['valid']);
        $this->assertSame(20000.0, $res1['discount_amount']);

        // 2. Percentage with Max Cap
        $res2 = $this->couponService->validate('WELCOME10', 800000);
        $this->assertTrue($res2['valid']);
        $this->assertSame(50000.0, $res2['discount_amount']);

        // 3. Below minimum purchase amount
        $res3 = $this->couponService->validate('WELCOME10', 50000);
        $this->assertFalse($res3['valid']);
        $this->assertStringContainsString('حداقل مبلغ خرید', $res3['message']);

        // 4. Fixed Amount Coupon (TAHRIR50 - 50,000, min 250,000)
        $res4 = $this->couponService->validate('TAHRIR50', 300000);
        $this->assertTrue($res4['valid']);
        $this->assertSame(50000.0, $res4['discount_amount']);

        // 5. Invalid / Non-existent Coupon
        $res5 = $this->couponService->validate('UNKNOWN_COUPON_CODE', 200000);
        $this->assertFalse($res5['valid']);
    }

    public function test_order_creation_with_pessimistic_locking_deducts_stock_and_logs_inventory(): void
    {
        $productId = $this->getOrCreateTestProduct();

        $product = $this->db->fetch(
            "SELECT `id`, `stock_quantity` FROM `products` WHERE `id` = :id",
            [':id' => $productId]
        );
        $this->assertNotNull($product);
        $this->assertSame(15, (int) $product['stock_quantity']);

        $customerData = [
            'recipient_name' => 'آرمان اسدی (تست خودکار)',
            'recipient_phone' => '09121112233',
            'shipping_province' => 'تهران',
            'shipping_city' => 'تهران',
            'shipping_postal_code' => '1234567890',
            'shipping_address' => 'خیابان انقلاب، تقاطع فلسطین، پلاک ۱۰',
            'customer_notes' => 'تست سیستم تسویه حساب با قفل سطری',
        ];

        $cartItems = [
            'item_1' => [
                'product_id' => $productId,
                'variant_id' => null,
                'quantity' => 4,
            ],
        ];

        // Execute Order creation
        $order = $this->orderService->createOrder($customerData, $cartItems, 'WELCOME10');

        $this->assertGreaterThan(0, $order['id']);
        $this->assertStringStartsWith('ORD-', $order['order_number']);
        $this->assertSame(480000.0, $order['subtotal']); // 120,000 * 4 = 480,000
        $this->assertSame(48000.0, $order['discount_amount']); // 10% of 480,000

        // Verify Product Stock was decreased from 15 to 11
        $updatedProduct = $this->db->fetch(
            "SELECT `stock_quantity` FROM `products` WHERE `id` = :id",
            [':id' => $productId]
        );
        $this->assertNotNull($updatedProduct);
        $this->assertSame(11, (int) $updatedProduct['stock_quantity']);

        // Verify Inventory Log
        $inventoryLog = $this->db->fetch(
            "SELECT * FROM `inventory_logs` WHERE `order_id` = :order_id AND `product_id` = :prod_id",
            [':order_id' => $order['id'], ':prod_id' => $productId]
        );
        $this->assertNotNull($inventoryLog);
        $this->assertSame(-4, (int) $inventoryLog['quantity_change']);
        $this->assertSame(11, (int) $inventoryLog['balance_after']);
        $this->assertSame('order_reservation', $inventoryLog['reason']);

        // Verify Order Items
        $items = $this->orderService->getOrderItems($order['id']);
        $this->assertCount(1, $items);
        $this->assertSame('محصول تستی جهت بررسی قفل انبار', $items[0]['product_title']);
        $this->assertSame(4, (int) $items[0]['quantity']);
    }

    public function test_order_creation_fails_and_rolls_back_when_stock_is_insufficient(): void
    {
        $productId = $this->getOrCreateTestProduct();

        $product = $this->db->fetch(
            "SELECT `id`, `stock_quantity` FROM `products` WHERE `id` = :id",
            [':id' => $productId]
        );
        $this->assertNotNull($product);
        $initialStock = (int) $product['stock_quantity'];

        $customerData = [
            'recipient_name' => 'تست ناموجود',
            'recipient_phone' => '09129998877',
            'shipping_province' => 'تهران',
            'shipping_city' => 'تهران',
            'shipping_postal_code' => '9876543210',
            'shipping_address' => 'میدان ولیعصر، پلاک ۲۰',
        ];

        // Requesting 999 items while stock is far less
        $cartItems = [
            'item_excess' => [
                'product_id' => $productId,
                'variant_id' => null,
                'quantity' => 999,
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('کافی نیست');

        try {
            $this->orderService->createOrder($customerData, $cartItems);
        } finally {
            // Verify database transaction rolled back cleanly and stock did not change
            $checkProduct = $this->db->fetch(
                "SELECT `stock_quantity` FROM `products` WHERE `id` = :id",
                [':id' => $productId]
            );
            $this->assertSame($initialStock, (int) $checkProduct['stock_quantity']);
        }
    }

    public function test_order_cancellation_restores_inventory(): void
    {
        $productId = $this->getOrCreateTestProduct();

        $product = $this->db->fetch(
            "SELECT `id`, `stock_quantity` FROM `products` WHERE `id` = :id",
            [':id' => $productId]
        );
        $this->assertNotNull($product);
        $beforeStock = (int) $product['stock_quantity'];

        $customerData = [
            'recipient_name' => 'تست بازگشت انبار',
            'recipient_phone' => '09125554433',
            'shipping_province' => 'اصفهان',
            'shipping_city' => 'اصفهان',
            'shipping_postal_code' => '8181818181',
            'shipping_address' => 'چهارباغ عباسی، کوچه فردوسی، پلاک ۵',
        ];

        $cartItems = [
            'item_restore' => [
                'product_id' => $productId,
                'variant_id' => null,
                'quantity' => 2,
            ],
        ];

        $order = $this->orderService->createOrder($customerData, $cartItems);
        $orderId = $order['id'];

        // Cancel order and verify restoration
        $success = $this->orderService->cancelOrder($orderId, 'test_cancelled');
        $this->assertTrue($success);

        $afterProduct = $this->db->fetch(
            "SELECT `stock_quantity` FROM `products` WHERE `id` = :id",
            [':id' => $productId]
        );
        $this->assertSame($beforeStock, (int) $afterProduct['stock_quantity']);

        // Check audit log for restoration
        $restoreLog = $this->db->fetch(
            "SELECT * FROM `inventory_logs` WHERE `order_id` = :order_id AND `quantity_change` > 0",
            [':order_id' => $orderId]
        );
        $this->assertNotNull($restoreLog);
        $this->assertSame(2, (int) $restoreLog['quantity_change']);
        $this->assertSame('test_cancelled', $restoreLog['reason']);
    }

    public function test_two_phase_payment_flow_and_isolated_logging(): void
    {
        $productId = $this->getOrCreateTestProduct();

        $customerData = [
            'recipient_name' => 'تست پرداخت بانکی',
            'recipient_phone' => '09127778899',
            'shipping_province' => 'فارس',
            'shipping_city' => 'شیراز',
            'shipping_postal_code' => '7171717171',
            'shipping_address' => 'بلوار ارم، کوچه سرو، پلاک ۲',
        ];

        $order = $this->orderService->createOrder($customerData, [
            'item_pay' => [
                'product_id' => $productId,
                'variant_id' => null,
                'quantity' => 1,
            ],
        ]);

        $orderId = $order['id'];

        // Phase 1: Request Payment
        $req = $this->paymentService->requestPayment($orderId, $order['total_amount'], 'تست تراکنش بانکی', '/callback');
        $this->assertTrue($req['success']);
        $this->assertStringStartsWith('AUTH-', $req['authority']);
        $this->assertStringContainsString('mock-gateway', $req['payment_url']);

        // Verify Pending State in Payments Table
        $pendingPayment = $this->paymentService->getPaymentByAuthority($req['authority']);
        $this->assertNotNull($pendingPayment);
        $this->assertSame('pending', $pendingPayment['status']);

        // Phase 2: Callback & Verification (Success case: Status = OK)
        $verify = $this->paymentService->verifyPayment($req['authority'], 'OK', '6037991234567890');
        $this->assertTrue($verify['success']);
        $this->assertStringStartsWith('TRX-', (string) $verify['reference_id']);

        // Verify Database Updates
        $verifiedPayment = $this->paymentService->getPaymentByAuthority($req['authority']);
        $this->assertNotNull($verifiedPayment);
        $this->assertSame('verified', $verifiedPayment['status']);
        $this->assertSame($verify['reference_id'], $verifiedPayment['reference_id']);
        $this->assertSame('603799******7890', $verifiedPayment['card_pan']);

        $updatedOrder = $this->orderService->getOrderById($orderId);
        $this->assertNotNull($updatedOrder);
        $this->assertSame('processing', $updatedOrder['status']);

        // Verify Isolated Payment Log
        $logPath = storage_path('logs/payments.log');
        $this->assertFileExists($logPath);
        $logContent = (string) file_get_contents($logPath);
        $this->assertStringContainsString($req['authority'], $logContent);
        $this->assertStringContainsString('Payment successfully verified', $logContent);

        // Verification Failure Case (Status = NOK)
        $req2 = $this->paymentService->requestPayment($orderId, 50000, 'تست تراکنش لغو شده', '/callback');
        $verifyFail = $this->paymentService->verifyPayment($req2['authority'], 'NOK');
        $this->assertFalse($verifyFail['success']);

        $failedPayment = $this->paymentService->getPaymentByAuthority($req2['authority']);
        $this->assertNotNull($failedPayment);
        $this->assertSame('failed', $failedPayment['status']);
    }
}
