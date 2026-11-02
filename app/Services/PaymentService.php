<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;
use Throwable;

class PaymentService
{
    private Database $db;
    private string $logPath;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->logPath = storage_path('logs' . DIRECTORY_SEPARATOR . 'payments.log');
    }

    /**
     * Phase 1: Request Payment (Two-phase pattern - Gateway Request).
     * 
     * @return array{success: bool, authority: string, payment_url: string, message?: string}
     */
    public function requestPayment(int $orderId, float $amount, string $description, string $callbackUrl): array
    {
        $authority = 'AUTH-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(6)));

        try {
            $this->db->execute("
                INSERT INTO `payments` 
                (`order_id`, `gateway`, `authority`, `amount`, `status`, `created_at`)
                VALUES
                (:order_id, 'sandbox', :authority, :amount, 'pending', NOW())
            ", [
                ':order_id' => $orderId,
                ':authority' => $authority,
                ':amount' => $amount,
            ]);

            $this->log('INFO', 'Payment request initialized', [
                'order_id' => $orderId,
                'authority' => $authority,
                'amount' => $amount,
                'description' => $description,
            ]);

            $paymentUrl = url('/payment/mock-gateway?authority=' . $authority);

            return [
                'success' => true,
                'authority' => $authority,
                'payment_url' => $paymentUrl,
            ];
        } catch (Throwable $e) {
            $this->log('ERROR', 'Failed to initialize payment request: ' . $e->getMessage(), [
                'order_id' => $orderId,
                'amount' => $amount,
            ]);

            throw new RuntimeException("خطا در ایجاد درخواست پرداخت: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Phase 2: Verify Payment (Two-phase pattern - Gateway Callback Verification).
     * 
     * @return array{
     *     success: bool,
     *     message: string,
     *     reference_id?: string,
     *     order_id?: int,
     *     payment?: array<string, mixed>
     * }
     */
    public function verifyPayment(string $authority, string $status, ?string $cardPan = null): array
    {
        $payment = $this->getPaymentByAuthority($authority);

        if (!$payment) {
            $this->log('WARN', 'Payment verification failed: authority not found', ['authority' => $authority]);
            return [
                'success' => false,
                'message' => 'تراکنش مورد نظر یافت نشد.',
            ];
        }

        $orderId = (int) $payment['order_id'];
        $amount = (float) $payment['amount'];

        // If already verified
        if ($payment['status'] === 'verified') {
            $this->log('INFO', 'Payment already verified', [
                'authority' => $authority,
                'reference_id' => $payment['reference_id'],
            ]);

            return [
                'success' => true,
                'message' => 'این تراکنش قبلاً با موفقیت تأیید شده است.',
                'reference_id' => (string) $payment['reference_id'],
                'order_id' => $orderId,
                'payment' => $payment,
            ];
        }

        // If user cancelled or gateway returned failure
        if (strtoupper($status) !== 'OK') {
            $this->db->execute("
                UPDATE `payments` 
                SET `status` = 'failed',
                    `gateway_response` = :response,
                    `updated_at` = NOW()
                WHERE `id` = :id
            ", [
                ':id' => $payment['id'],
                ':response' => json_encode(['status' => $status, 'error' => 'User cancelled or gateway rejected']),
            ]);

            $this->log('WARN', 'Payment rejected or cancelled by user', [
                'order_id' => $orderId,
                'authority' => $authority,
                'status' => $status,
            ]);

            return [
                'success' => false,
                'message' => 'پرداخت ناموفق بود یا توسط خریدار لغو گردید.',
                'order_id' => $orderId,
                'payment' => $payment,
            ];
        }

        // Successful Payment: generate tracking reference code
        $referenceId = 'TRX-' . date('Ymd') . '-' . mt_rand(100000, 999999);
        $maskedCard = $this->maskCardNumber($cardPan ?? '603799' . mt_rand(100000, 999999));

        $gatewayPayload = [
            'status' => 'OK',
            'authority' => $authority,
            'reference_id' => $referenceId,
            'card_pan' => $maskedCard,
            'verified_at' => date('Y-m-d H:i:s'),
        ];

        // Update payment record
        $this->db->execute("
            UPDATE `payments` 
            SET `status` = 'verified',
                `reference_id` = :reference_id,
                `card_pan` = :card_pan,
                `gateway_response` = :response,
                `updated_at` = NOW()
            WHERE `id` = :id
        ", [
            ':id' => $payment['id'],
            ':reference_id' => $referenceId,
            ':card_pan' => $maskedCard,
            ':response' => json_encode($gatewayPayload),
        ]);

        // Update order status to 'processing'
        $this->db->execute("
            UPDATE `orders` 
            SET `status` = 'processing',
                `updated_at` = NOW()
            WHERE `id` = :order_id
        ", [
            ':order_id' => $orderId,
        ]);

        $this->log('INFO', 'Payment successfully verified', [
            'order_id' => $orderId,
            'authority' => $authority,
            'reference_id' => $referenceId,
            'amount' => $amount,
            'card_pan' => $maskedCard,
        ]);

        return [
            'success' => true,
            'message' => 'پرداخت با موفقیت انجام شد و سفارش شما در مرحله پردازش قرار گرفت.',
            'reference_id' => $referenceId,
            'order_id' => $orderId,
            'payment' => array_merge($payment, [
                'status' => 'verified',
                'reference_id' => $referenceId,
                'card_pan' => $maskedCard,
            ]),
        ];
    }

    /**
     * Retrieve payment record by authority.
     * 
     * @return array<string, mixed>|null
     */
    public function getPaymentByAuthority(string $authority): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM `payments` WHERE `authority` = :authority LIMIT 1",
            [':authority' => $authority]
        );
    }

    /**
     * Retrieve payment record by order ID.
     * 
     * @return array<string, mixed>|null
     */
    public function getPaymentByOrderId(int $orderId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM `payments` WHERE `order_id` = :order_id ORDER BY `id` DESC LIMIT 1",
            [':order_id' => $orderId]
        );
    }

    /**
     * Isolated logger for bank and gateway transactions.
     * 
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $logDir = dirname($this->logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $entry = "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";

        file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);
    }

    private function maskCardNumber(string $cardNumber): string
    {
        $digits = preg_replace('/\D/', '', $cardNumber) ?? '';
        if (strlen($digits) === 16) {
            return substr($digits, 0, 6) . '******' . substr($digits, 12, 4);
        }
        return '603799******1234';
    }
}
