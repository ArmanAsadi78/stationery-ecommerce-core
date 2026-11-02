<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Session;
use RuntimeException;
use Throwable;

class OrderService
{
    private Database $db;
    private CouponService $couponService;

    public const SHIPPING_FLAT_RATE = 35000.0;
    public const FREE_SHIPPING_THRESHOLD = 500000.0;

    public function __construct(?Database $db = null, ?CouponService $couponService = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->couponService = $couponService ?? new CouponService($this->db);
    }

    /**
     * Create order with Pessimistic Locking (SELECT ... FOR UPDATE) inside an ACID transaction.
     * 
     * @param array{
     *     recipient_name: string,
     *     recipient_phone: string,
     *     shipping_province: string,
     *     shipping_city: string,
     *     shipping_postal_code: string,
     *     shipping_address: string,
     *     customer_notes?: string|null,
     *     user_id?: int|null
     * } $customerData
     * @param array<string, array<string, mixed>> $cartItems
     * @param string|null $couponCode
     * @return array{
     *     id: int,
     *     order_number: string,
     *     subtotal: float,
     *     discount_amount: float,
     *     shipping_cost: float,
     *     total_amount: float,
     *     items_count: int
     * }
     * @throws RuntimeException
     */
    public function createOrder(array $customerData, array $cartItems, ?string $couponCode = null): array
    {
        if (empty($cartItems)) {
            throw new RuntimeException("سبد خرید شما خالی است.");
        }

        $this->db->beginTransaction();

        try {
            $processedItems = [];
            $subtotal = 0.0;

            // 1. Pessimistic Row Locking & Stock Validation
            foreach ($cartItems as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $variantId = !empty($item['variant_id']) ? (int) $item['variant_id'] : null;
                $quantity = (int) ($item['quantity'] ?? 0);

                if ($productId <= 0 || $quantity <= 0) {
                    throw new RuntimeException("اقلام سبد خرید نامعتبر است.");
                }

                if ($variantId !== null) {
                    // Lock variant row with pessimistic lock
                    $variant = $this->db->fetch(
                        "SELECT `id`, `product_id`, `attribute_name`, `attribute_value`, `additional_price`, `stock_quantity`, `is_active`
                         FROM `product_variants`
                         WHERE `id` = :id FOR UPDATE",
                        [':id' => $variantId]
                    );

                    if (!$variant || !(bool) $variant['is_active']) {
                        throw new RuntimeException("تنوع محصول انتخاب شده در دسترس نیست.");
                    }

                    // Lock base product row as well
                    $product = $this->db->fetch(
                        "SELECT `id`, `title`, `price`, `is_active`
                         FROM `products`
                         WHERE `id` = :id FOR UPDATE",
                        [':id' => $productId]
                    );

                    if (!$product || !(bool) $product['is_active']) {
                        throw new RuntimeException("محصول مورد نظر غیرفعال شده است.");
                    }

                    $currentStock = (int) $variant['stock_quantity'];
                    if ($currentStock < $quantity) {
                        throw new RuntimeException(
                            "موجودی انبار برای «{$product['title']} ({$variant['attribute_name']}: {$variant['attribute_value']})» کافی نیست. موجودی فعلی: {$currentStock} عدد."
                        );
                    }

                    $effectivePrice = (float) $product['price'] + (float) $variant['additional_price'];
                    $itemSubtotal = $effectivePrice * $quantity;
                    $subtotal += $itemSubtotal;
                    $newStock = $currentStock - $quantity;

                    // Deduct variant stock
                    $this->db->execute(
                        "UPDATE `product_variants` SET `stock_quantity` = :stock WHERE `id` = :id",
                        [':stock' => $newStock, ':id' => $variantId]
                    );

                    $processedItems[] = [
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'product_title' => (string) $product['title'],
                        'variant_details' => $variant['attribute_name'] . ': ' . $variant['attribute_value'],
                        'unit_price' => $effectivePrice,
                        'quantity' => $quantity,
                        'subtotal' => $itemSubtotal,
                        'balance_after' => $newStock,
                    ];
                } else {
                    // Lock product row with pessimistic lock
                    $product = $this->db->fetch(
                        "SELECT `id`, `title`, `price`, `stock_quantity`, `is_active`
                         FROM `products`
                         WHERE `id` = :id FOR UPDATE",
                        [':id' => $productId]
                    );

                    if (!$product || !(bool) $product['is_active']) {
                        throw new RuntimeException("محصول انتخابی در دسترس نیست یا غیرفعال شده است.");
                    }

                    $currentStock = (int) $product['stock_quantity'];
                    if ($currentStock < $quantity) {
                        throw new RuntimeException(
                            "موجودی انبار برای «{$product['title']}» کافی نیست. موجودی فعلی: {$currentStock} عدد."
                        );
                    }

                    $effectivePrice = (float) $product['price'];
                    $itemSubtotal = $effectivePrice * $quantity;
                    $subtotal += $itemSubtotal;
                    $newStock = $currentStock - $quantity;

                    // Deduct base product stock
                    $this->db->execute(
                        "UPDATE `products` SET `stock_quantity` = :stock WHERE `id` = :id",
                        [':stock' => $newStock, ':id' => $productId]
                    );

                    $processedItems[] = [
                        'product_id' => $productId,
                        'variant_id' => null,
                        'product_title' => (string) $product['title'],
                        'variant_details' => null,
                        'unit_price' => $effectivePrice,
                        'quantity' => $quantity,
                        'subtotal' => $itemSubtotal,
                        'balance_after' => $newStock,
                    ];
                }
            }

            // 2. Validate Coupon & Calculate Totals
            $discountAmount = 0.0;
            $couponId = null;

            if ($couponCode !== null && trim($couponCode) !== '') {
                $couponRes = $this->couponService->validate($couponCode, $subtotal);
                if ($couponRes['valid'] && isset($couponRes['coupon'])) {
                    $discountAmount = (float) $couponRes['discount_amount'];
                    $couponId = (int) $couponRes['coupon']['id'];
                    $this->couponService->incrementUsage($couponId);
                }
            }

            $shippingCost = $this->calculateShippingCost($subtotal);
            $totalAmount = max(0.0, ($subtotal - $discountAmount) + $shippingCost);

            // 3. User Identification
            $userId = $this->resolveUserId($customerData);

            // 4. Create Order Entry
            $orderNumber = $this->generateOrderNumber();
            $this->db->execute("
                INSERT INTO `orders` 
                (`order_number`, `user_id`, `coupon_id`, `subtotal`, `discount_amount`, `shipping_cost`, `total_amount`, `status`, `recipient_name`, `recipient_phone`, `shipping_province`, `shipping_city`, `shipping_postal_code`, `shipping_address`, `customer_notes`)
                VALUES
                (:order_number, :user_id, :coupon_id, :subtotal, :discount_amount, :shipping_cost, :total_amount, 'pending_payment', :recipient_name, :recipient_phone, :shipping_province, :shipping_city, :shipping_postal_code, :shipping_address, :customer_notes)
            ", [
                ':order_number' => $orderNumber,
                ':user_id' => $userId,
                ':coupon_id' => $couponId,
                ':subtotal' => $subtotal,
                ':discount_amount' => $discountAmount,
                ':shipping_cost' => $shippingCost,
                ':total_amount' => $totalAmount,
                ':recipient_name' => trim((string) $customerData['recipient_name']),
                ':recipient_phone' => trim((string) $customerData['recipient_phone']),
                ':shipping_province' => trim((string) $customerData['shipping_province']),
                ':shipping_city' => trim((string) $customerData['shipping_city']),
                ':shipping_postal_code' => trim((string) $customerData['shipping_postal_code']),
                ':shipping_address' => trim((string) $customerData['shipping_address']),
                ':customer_notes' => isset($customerData['customer_notes']) ? trim((string) $customerData['customer_notes']) : null,
            ]);

            $orderId = (int) $this->db->lastInsertId();

            // 5. Insert Order Items & Inventory Logs (Audit Trail)
            foreach ($processedItems as $item) {
                $this->db->execute("
                    INSERT INTO `order_items` 
                    (`order_id`, `product_id`, `variant_id`, `product_title`, `variant_details`, `unit_price`, `quantity`, `subtotal`)
                    VALUES 
                    (:order_id, :product_id, :variant_id, :product_title, :variant_details, :unit_price, :quantity, :subtotal)
                ", [
                    ':order_id' => $orderId,
                    ':product_id' => $item['product_id'],
                    ':variant_id' => $item['variant_id'],
                    ':product_title' => $item['product_title'],
                    ':variant_details' => $item['variant_details'],
                    ':unit_price' => $item['unit_price'],
                    ':quantity' => $item['quantity'],
                    ':subtotal' => $item['subtotal'],
                ]);

                // Inventory Log
                $this->db->execute("
                    INSERT INTO `inventory_logs`
                    (`product_id`, `variant_id`, `order_id`, `quantity_change`, `balance_after`, `reason`)
                    VALUES
                    (:product_id, :variant_id, :order_id, :quantity_change, :balance_after, :reason)
                ", [
                    ':product_id' => $item['product_id'],
                    ':variant_id' => $item['variant_id'],
                    ':order_id' => $orderId,
                    ':quantity_change' => -$item['quantity'],
                    ':balance_after' => $item['balance_after'],
                    ':reason' => 'order_reservation',
                ]);
            }

            $this->db->commit();

            return [
                'id' => $orderId,
                'order_number' => $orderNumber,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'shipping_cost' => $shippingCost,
                'total_amount' => $totalAmount,
                'items_count' => count($processedItems),
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Cancel order and restore inventory via transaction.
     */
    public function cancelOrder(int $orderId, string $reason = 'order_cancelled'): bool
    {
        $this->db->beginTransaction();

        try {
            $order = $this->db->fetch(
                "SELECT * FROM `orders` WHERE `id` = :id FOR UPDATE",
                [':id' => $orderId]
            );

            if (!$order || $order['status'] === 'cancelled') {
                $this->db->rollBack();
                return false;
            }

            $items = $this->getOrderItems($orderId);

            foreach ($items as $item) {
                $qty = (int) $item['quantity'];
                $productId = (int) $item['product_id'];
                $variantId = !empty($item['variant_id']) ? (int) $item['variant_id'] : null;

                if ($variantId !== null) {
                    $variant = $this->db->fetch(
                        "SELECT `stock_quantity` FROM `product_variants` WHERE `id` = :id FOR UPDATE",
                        [':id' => $variantId]
                    );
                    $newBalance = ($variant ? (int) $variant['stock_quantity'] : 0) + $qty;

                    $this->db->execute(
                        "UPDATE `product_variants` SET `stock_quantity` = :stock WHERE `id` = :id",
                        [':stock' => $newBalance, ':id' => $variantId]
                    );

                    $this->db->execute("
                        INSERT INTO `inventory_logs`
                        (`product_id`, `variant_id`, `order_id`, `quantity_change`, `balance_after`, `reason`)
                        VALUES
                        (:product_id, :variant_id, :order_id, :quantity_change, :balance_after, :reason)
                    ", [
                        ':product_id' => $productId,
                        ':variant_id' => $variantId,
                        ':order_id' => $orderId,
                        ':quantity_change' => $qty,
                        ':balance_after' => $newBalance,
                        ':reason' => $reason,
                    ]);
                } else {
                    $prod = $this->db->fetch(
                        "SELECT `stock_quantity` FROM `products` WHERE `id` = :id FOR UPDATE",
                        [':id' => $productId]
                    );
                    $newBalance = ($prod ? (int) $prod['stock_quantity'] : 0) + $qty;

                    $this->db->execute(
                        "UPDATE `products` SET `stock_quantity` = :stock WHERE `id` = :id",
                        [':stock' => $newBalance, ':id' => $productId]
                    );

                    $this->db->execute("
                        INSERT INTO `inventory_logs`
                        (`product_id`, `variant_id`, `order_id`, `quantity_change`, `balance_after`, `reason`)
                        VALUES
                        (:product_id, :variant_id, :order_id, :quantity_change, :balance_after, :reason)
                    ", [
                        ':product_id' => $productId,
                        ':variant_id' => null,
                        ':order_id' => $orderId,
                        ':quantity_change' => $qty,
                        ':balance_after' => $newBalance,
                        ':reason' => $reason,
                    ]);
                }
            }

            // Restore coupon if used
            if (!empty($order['coupon_id'])) {
                $this->couponService->decrementUsage((int) $order['coupon_id']);
            }

            $this->db->execute(
                "UPDATE `orders` SET `status` = 'cancelled' WHERE `id` = :id",
                [':id' => $orderId]
            );

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    /**
     * Fetch order by ID.
     * 
     * @return array<string, mixed>|null
     */
    public function getOrderById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM `orders` WHERE `id` = :id LIMIT 1",
            [':id' => $id]
        );
    }

    /**
     * Fetch order by Order Number.
     * 
     * @return array<string, mixed>|null
     */
    public function getOrderByNumber(string $orderNumber): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM `orders` WHERE `order_number` = :order_number LIMIT 1",
            [':order_number' => $orderNumber]
        );
    }

    /**
     * Fetch order items.
     * 
     * @return list<array<string, mixed>>
     */
    public function getOrderItems(int $orderId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM `order_items` WHERE `order_id` = :order_id ORDER BY `id` ASC",
            [':order_id' => $orderId]
        );
    }

    /**
     * Update order status.
     */
    public function updateStatus(int $orderId, string $status): bool
    {
        return $this->db->execute(
            "UPDATE `orders` SET `status` = :status WHERE `id` = :id",
            [':status' => $status, ':id' => $orderId]
        );
    }

    public function calculateShippingCost(float $subtotal): float
    {
        return $subtotal >= self::FREE_SHIPPING_THRESHOLD ? 0.0 : self::SHIPPING_FLAT_RATE;
    }

    private function generateOrderNumber(): string
    {
        return 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * @param array<string, mixed> $customerData
     */
    private function resolveUserId(array $customerData): int
    {
        // 1. Check logged in user session
        if (!empty($customerData['user_id'])) {
            return (int) $customerData['user_id'];
        }

        /** @var mixed $sessionUser */
        $sessionUser = Session::get('user');
        if (is_array($sessionUser) && !empty($sessionUser['id'])) {
            return (int) $sessionUser['id'];
        }

        // 2. Lookup existing user by phone or create guest customer account
        $phone = isset($customerData['recipient_phone']) ? trim((string) $customerData['recipient_phone']) : null;
        if ($phone !== null && $phone !== '') {
            $user = $this->db->fetch(
                "SELECT `id` FROM `users` WHERE `phone` = :phone LIMIT 1",
                [':phone' => $phone]
            );
            if ($user) {
                return (int) $user['id'];
            }
        }

        // 3. Fallback: check or create guest customer with customer role
        $email = 'guest_' . time() . '_' . bin2hex(random_bytes(2)) . '@stationery.local';
        $name = isset($customerData['recipient_name']) ? (string) $customerData['recipient_name'] : 'مشتری مهمان';
        $randomPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID);

        $this->db->execute("
            INSERT INTO `users` (`name`, `email`, `phone`, `password_hash`, `role`, `is_active`)
            VALUES (:name, :email, :phone, :password_hash, 'customer', 1)
        ", [
            ':name' => $name,
            ':email' => $email,
            ':phone' => $phone,
            ':password_hash' => $randomPass,
        ]);

        return (int) $this->db->lastInsertId();
    }
}
