<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Session;
use App\Repositories\ProductRepository;
use InvalidArgumentException;

class CartService
{
    private ProductRepository $productRepo;

    public function __construct(?ProductRepository $productRepo = null)
    {
        $this->productRepo = $productRepo ?? new ProductRepository();
    }

    /**
     * Add item to cart with stock validation.
     * 
     * @return array{success: bool, message: string, cart?: array<string, mixed>}
     */
    public function add(int $productId, ?int $variantId = null, int $quantity = 1): array
    {
        if ($quantity <= 0) {
            return ['success' => false, 'message' => 'تعداد کالا باید بزرگتر از صفر باشد.'];
        }

        $product = $this->productRepo->findById($productId);
        if (!$product) {
            return ['success' => false, 'message' => 'محصول مورد نظر یافت نشد.'];
        }

        $price = (float) $product['price'];
        $availableStock = (int) $product['stock_quantity'];
        $variantTitle = null;

        if ($variantId !== null) {
            $matchedVariant = null;
            /** @var list<array<string, mixed>> $variants */
            $variants = $product['variants'] ?? [];
            foreach ($variants as $v) {
                if ((int) $v['id'] === $variantId) {
                    $matchedVariant = $v;
                    break;
                }
            }

            if (!$matchedVariant) {
                return ['success' => false, 'message' => 'تنوع انتخابی معتبر نیست.'];
            }

            $price += (float) $matchedVariant['additional_price'];
            $availableStock = (int) $matchedVariant['stock_quantity'];
            $variantTitle = $matchedVariant['attribute_name'] . ': ' . $matchedVariant['attribute_value'];
        }

        if ($availableStock <= 0) {
            return ['success' => false, 'message' => 'متأسفانه موجودی این محصول به اتمام رسیده است.'];
        }

        $cartKey = $this->generateKey($productId, $variantId);
        $items = $this->getItems();

        $currentQty = isset($items[$cartKey]) ? (int) $items[$cartKey]['quantity'] : 0;
        $newQty = $currentQty + $quantity;

        if ($newQty > $availableStock) {
            return [
                'success' => false,
                'message' => "حداکثر موجودی قابل سفارش این محصول {$availableStock} عدد است.",
            ];
        }

        $items[$cartKey] = [
            'key' => $cartKey,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'title' => (string) $product['title'],
            'variant_title' => $variantTitle,
            'slug' => (string) $product['slug'],
            'image' => (string) ($product['primary_image'] ?? ''),
            'unit_price' => $price,
            'quantity' => $newQty,
            'max_stock' => $availableStock,
            'subtotal' => $price * $newQty,
        ];

        Session::set('_cart', $items);

        return [
            'success' => true,
            'message' => 'محصول با موفقیت به سبد خرید اضافه شد.',
            'cart' => $this->getSummary(),
        ];
    }

    /**
     * Update quantity of an item.
     * 
     * @return array{success: bool, message: string, cart?: array<string, mixed>}
     */
    public function update(string $cartKey, int $quantity): array
    {
        $items = $this->getItems();
        if (!isset($items[$cartKey])) {
            return ['success' => false, 'message' => 'کالای مورد نظر در سبد خرید یافت نشد.'];
        }

        if ($quantity <= 0) {
            return $this->remove($cartKey);
        }

        $item = $items[$cartKey];
        if ($quantity > (int) $item['max_stock']) {
            return [
                'success' => false,
                'message' => "تعداد درخواستی بیشتر از موجودی انبار ({$item['max_stock']}) است.",
            ];
        }

        $items[$cartKey]['quantity'] = $quantity;
        $items[$cartKey]['subtotal'] = (float) $item['unit_price'] * $quantity;

        Session::set('_cart', $items);

        return [
            'success' => true,
            'message' => 'تعداد کالا در سبد خرید به‌روزرسانی شد.',
            'cart' => $this->getSummary(),
        ];
    }

    /**
     * Remove item from cart.
     * 
     * @return array{success: bool, message: string, cart?: array<string, mixed>}
     */
    public function remove(string $cartKey): array
    {
        $items = $this->getItems();
        if (!isset($items[$cartKey])) {
            return ['success' => false, 'message' => 'کالا در سبد خرید وجود ندارد.'];
        }

        unset($items[$cartKey]);
        Session::set('_cart', $items);

        return [
            'success' => true,
            'message' => 'کالا از سبد خرید حذف شد.',
            'cart' => $this->getSummary(),
        ];
    }

    public function clear(): void
    {
        Session::remove('_cart');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getItems(): array
    {
        /** @var mixed $items */
        $items = Session::get('_cart', []);
        /** @var array<string, array<string, mixed>> $result */
        $result = is_array($items) ? $items : [];
        return $result;
    }

    public function getTotalCount(): int
    {
        $count = 0;
        foreach ($this->getItems() as $item) {
            $count += (int) ($item['quantity'] ?? 0);
        }

        return $count;
    }

    public function getSubtotal(): float
    {
        $total = 0.0;
        foreach ($this->getItems() as $item) {
            $total += (float) ($item['subtotal'] ?? 0.0);
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        $items = array_values($this->getItems());
        $subtotal = $this->getSubtotal();

        return [
            'items' => $items,
            'total_count' => $this->getTotalCount(),
            'subtotal' => $subtotal,
            'formatted_subtotal' => format_price($subtotal),
        ];
    }

    private function generateKey(int $productId, ?int $variantId = null): string
    {
        return $variantId === null ? "prod_{$productId}" : "prod_{$productId}_var_{$variantId}";
    }
}
