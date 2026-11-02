<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

class CouponService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Find coupon by code.
     * 
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        $cleanCode = strtoupper(trim($code));
        return $this->db->fetch(
            "SELECT * FROM `coupons` WHERE UPPER(`code`) = :code LIMIT 1",
            [':code' => $cleanCode]
        );
    }

    /**
     * Validate coupon for a given order amount.
     * 
     * @return array{valid: bool, message: string, discount_amount: float, coupon?: array<string, mixed>}
     */
    public function validate(string $code, float $orderAmount): array
    {
        $cleanCode = strtoupper(trim($code));
        if ($cleanCode === '') {
            return [
                'valid' => false,
                'message' => 'لطفاً کد تخفیف را وارد نمایید.',
                'discount_amount' => 0.0,
            ];
        }

        $coupon = $this->findByCode($cleanCode);
        if (!$coupon || !(bool) $coupon['is_active']) {
            return [
                'valid' => false,
                'message' => 'کد تخفیف وارد شده نامعتبر یا منقضی شده است.',
                'discount_amount' => 0.0,
            ];
        }

        $now = time();

        // Check start date
        if (!empty($coupon['starts_at'])) {
            $startTime = strtotime((string) $coupon['starts_at']);
            if ($startTime !== false && $now < $startTime) {
                return [
                    'valid' => false,
                    'message' => 'مهلت استفاده از این کد تخفیف هنوز شروع نشده است.',
                    'discount_amount' => 0.0,
                ];
            }
        }

        // Check expiration date
        if (!empty($coupon['expires_at'])) {
            $expireTime = strtotime((string) $coupon['expires_at']);
            if ($expireTime !== false && $now > $expireTime) {
                return [
                    'valid' => false,
                    'message' => 'مهلت اعتبار این کد تخفیف به پایان رسیده است.',
                    'discount_amount' => 0.0,
                ];
            }
        }

        // Check usage limit
        if ($coupon['usage_limit'] !== null) {
            $limit = (int) $coupon['usage_limit'];
            $used = (int) $coupon['used_count'];
            if ($used >= $limit) {
                return [
                    'valid' => false,
                    'message' => 'سقف استفاده از این کد تخفیف به پایان رسیده است.',
                    'discount_amount' => 0.0,
                ];
            }
        }

        // Check minimum order amount
        $minOrder = (float) ($coupon['min_order_amount'] ?? 0);
        if ($orderAmount < $minOrder) {
            return [
                'valid' => false,
                'message' => 'حداقل مبلغ خرید برای اعمال این کد تخفیف ' . format_price($minOrder) . ' است.',
                'discount_amount' => 0.0,
            ];
        }

        // Calculate discount amount
        $type = (string) $coupon['type'];
        $val = (float) $coupon['value'];
        $discount = 0.0;

        if ($type === 'percentage') {
            $discount = ($orderAmount * $val) / 100.0;
            if (!empty($coupon['max_discount_amount'])) {
                $maxLimit = (float) $coupon['max_discount_amount'];
                if ($discount > $maxLimit) {
                    $discount = $maxLimit;
                }
            }
        } else {
            // Fixed discount
            $discount = min($orderAmount, $val);
        }

        // Clamp discount to order amount
        $discount = min($discount, $orderAmount);
        $discount = round($discount, 0);

        return [
            'valid' => true,
            'message' => 'کد تخفیف با موفقیت اعمال گردید.',
            'discount_amount' => $discount,
            'coupon' => $coupon,
        ];
    }

    /**
     * Increment coupon usage count.
     */
    public function incrementUsage(int $couponId): bool
    {
        return $this->db->execute(
            "UPDATE `coupons` SET `used_count` = `used_count` + 1 WHERE `id` = :id",
            [':id' => $couponId]
        );
    }

    /**
     * Decrement coupon usage count (e.g. if order failed/cancelled).
     */
    public function decrementUsage(int $couponId): bool
    {
        return $this->db->execute(
            "UPDATE `coupons` SET `used_count` = GREATEST(0, `used_count` - 1) WHERE `id` = :id",
            [':id' => $couponId]
        );
    }
}
