<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\CartService;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PaymentService;
use RuntimeException;
use Throwable;

class CheckoutController
{
    private CartService $cartService;
    private OrderService $orderService;
    private CouponService $couponService;
    private PaymentService $paymentService;

    public function __construct()
    {
        $this->cartService = new CartService();
        $this->orderService = new OrderService();
        $this->couponService = new CouponService();
        $this->paymentService = new PaymentService();
    }

    /**
     * Display checkout page with address form and order summary.
     * 
     * @param array<string, string> $vars
     */
    public function index(Request $request, array $vars = []): Response
    {
        $cartSummary = $this->cartService->getSummary();
        $totalCount = (int) $cartSummary['total_count'];

        if ($totalCount <= 0) {
            Session::setFlash('error', 'سبد خرید شما خالی است. ابتدا محصول مورد نظر خود را انتخاب نمایید.');
            return Response::redirect(url('/products'));
        }

        $subtotal = (float) $cartSummary['subtotal'];
        $shippingCost = $this->orderService->calculateShippingCost($subtotal);

        // Check active session coupon
        $discountAmount = 0.0;
        /** @var mixed $appliedCoupon */
        $appliedCoupon = Session::get('_applied_coupon');
        $couponCode = null;

        if (is_array($appliedCoupon) && !empty($appliedCoupon['code'])) {
            $validation = $this->couponService->validate((string) $appliedCoupon['code'], $subtotal);
            if ($validation['valid']) {
                $discountAmount = (float) $validation['discount_amount'];
                $couponCode = (string) $appliedCoupon['code'];
            } else {
                Session::remove('_applied_coupon');
            }
        }

        $payableTotal = max(0.0, ($subtotal - $discountAmount) + $shippingCost);

        $provinces = [
            'تهران', 'اصفهان', 'خراسان رضوی', 'فارس', 'آذربایجان شرقی',
            'مازندران', 'گیلان', 'البرز', 'خوزستان', 'قم', 'یزد', 'کرمان',
            'همدان', 'کرمانشاه', 'آذربایجان غربی', 'قزوین', 'مرکزی', 'بوشهر'
        ];

        $html = View::render('checkout/index', [
            'page_title' => 'تسویه حساب و تکمیل سفارش - فروشگاه تحریرچی',
            'cart' => $cartSummary,
            'items' => $this->cartService->getItems(),
            'subtotal' => $subtotal,
            'shipping_cost' => $shippingCost,
            'discount_amount' => $discountAmount,
            'coupon_code' => $couponCode,
            'payable_total' => $payableTotal,
            'provinces' => $provinces,
            'free_shipping_threshold' => OrderService::FREE_SHIPPING_THRESHOLD,
        ]);

        return Response::html($html);
    }

    /**
     * Apply discount coupon code.
     * 
     * @param array<string, string> $vars
     */
    public function applyCoupon(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) ($request->input('_csrf_token') ?? $request->getHeader('X-CSRF-Token'));
        if (!Session::validateCsrf($csrfToken)) {
            if ($request->isAjax()) {
                return Response::json(['success' => false, 'message' => 'توکن امنیتی نامعتبر است.'], 403);
            }
            Session::setFlash('error', 'توکن امنیتی نامعتبر است.');
            return Response::redirect(url('/checkout'));
        }

        $code = trim((string) $request->input('coupon_code', ''));
        $subtotal = $this->cartService->getSubtotal();

        $validation = $this->couponService->validate($code, $subtotal);

        if (!$validation['valid']) {
            if ($request->isAjax()) {
                return Response::json(['success' => false, 'message' => $validation['message']], 400);
            }
            Session::setFlash('error', $validation['message']);
            return Response::redirect(url('/checkout'));
        }

        Session::set('_applied_coupon', [
            'code' => strtoupper($code),
            'discount_amount' => $validation['discount_amount'],
        ]);

        if ($request->isAjax()) {
            return Response::json([
                'success' => true,
                'message' => $validation['message'],
                'discount_amount' => $validation['discount_amount'],
            ]);
        }

        Session::setFlash('success', $validation['message']);
        return Response::redirect(url('/checkout'));
    }

    /**
     * Remove applied coupon.
     * 
     * @param array<string, string> $vars
     */
    public function removeCoupon(Request $request, array $vars = []): Response
    {
        Session::remove('_applied_coupon');

        if ($request->isAjax()) {
            return Response::json(['success' => true, 'message' => 'کد تخفیف حذف شد.']);
        }

        Session::setFlash('info', 'کد تخفیف از فاکتور حذف گردید.');
        return Response::redirect(url('/checkout'));
    }

    /**
     * Process checkout form, execute database transaction with pessimistic locking, and redirect to gateway.
     * 
     * @param array<string, string> $vars
     */
    public function process(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) $request->input('_csrf_token');
        if (!Session::validateCsrf($csrfToken)) {
            Session::setFlash('error', 'درخواست شما به دلیل انقضای فرم امنیتی تأیید نشد. لطفاً دوباره تلاش کنید.');
            return Response::redirect(url('/checkout'));
        }

        // Form Validation
        $name = trim((string) $request->input('recipient_name', ''));
        $phone = trim((string) $request->input('recipient_phone', ''));
        $province = trim((string) $request->input('shipping_province', ''));
        $city = trim((string) $request->input('shipping_city', ''));
        $postalCode = trim((string) $request->input('shipping_postal_code', ''));
        $address = trim((string) $request->input('shipping_address', ''));
        $notes = trim((string) $request->input('customer_notes', ''));

        if (mb_strlen($name) < 3) {
            Session::setFlash('error', 'نام و نام‌خانوادگی گیرنده باید حداقل ۳ کاراکتر باشد.');
            return Response::redirect(url('/checkout'));
        }

        if (!preg_match('/^09[0-9]{9}$/', $phone)) {
            Session::setFlash('error', 'شماره موبایل وارد شده معتبر نیست (فرمت صحیح: ۰۹۱۲۳۴۵۶۷۸۹).');
            return Response::redirect(url('/checkout'));
        }

        if (empty($province) || empty($city)) {
            Session::setFlash('error', 'لطفاً استان و شهر محل تحویل را مشخص فرمایید.');
            return Response::redirect(url('/checkout'));
        }

        if (!preg_match('/^[0-9]{10}$/', $postalCode)) {
            Session::setFlash('error', 'کد پستی باید دقیقاً یک عدد ۱۰ رقمی باشد.');
            return Response::redirect(url('/checkout'));
        }

        if (mb_strlen($address) < 8) {
            Session::setFlash('error', 'آدرس دقیق پستی باید حداقل ۸ کاراکتر باشد.');
            return Response::redirect(url('/checkout'));
        }

        $cartItems = $this->cartService->getItems();
        if (empty($cartItems)) {
            Session::setFlash('error', 'سبد خرید شما خالی است.');
            return Response::redirect(url('/products'));
        }

        /** @var mixed $appliedCoupon */
        $appliedCoupon = Session::get('_applied_coupon');
        $couponCode = is_array($appliedCoupon) && !empty($appliedCoupon['code']) ? (string) $appliedCoupon['code'] : null;

        try {
            // Execute order creation inside transaction with Pessimistic Locking
            $orderResult = $this->orderService->createOrder([
                'recipient_name' => $name,
                'recipient_phone' => $phone,
                'shipping_province' => $province,
                'shipping_city' => $city,
                'shipping_postal_code' => $postalCode,
                'shipping_address' => $address,
                'customer_notes' => $notes !== '' ? $notes : null,
            ], $cartItems, $couponCode);

            // Clear coupon session
            Session::remove('_applied_coupon');

            // Initialize Payment (Phase 1)
            $paymentResult = $this->paymentService->requestPayment(
                $orderResult['id'],
                $orderResult['total_amount'],
                "پرداخت سفارش {$orderResult['order_number']}",
                url('/payment/callback')
            );

            return Response::redirect($paymentResult['payment_url']);
        } catch (Throwable $e) {
            Session::setFlash('error', $e->getMessage());
            return Response::redirect(url('/checkout'));
        }
    }

    /**
     * Local interactive mock gateway page (Shaparak / Zarinpal Simulation).
     * 
     * @param array<string, string> $vars
     */
    public function mockGateway(Request $request, array $vars = []): Response
    {
        $authority = (string) $request->getQuery('authority', '');
        if ($authority === '') {
            return Response::redirect(url('/'));
        }

        $payment = $this->paymentService->getPaymentByAuthority($authority);
        if (!$payment) {
            Session::setFlash('error', 'تراکنش پرداخت مورد نظر یافت نشد.');
            return Response::redirect(url('/checkout'));
        }

        $order = $this->orderService->getOrderById((int) $payment['order_id']);
        if (!$order) {
            Session::setFlash('error', 'سفارش متناظر با این پرداخت یافت نشد.');
            return Response::redirect(url('/'));
        }

        $html = View::render('checkout/mock_gateway', [
            'page_title' => 'درگاه پرداخت تستی شاپرک / زرین‌پال - تحریرچی',
            'authority' => $authority,
            'payment' => $payment,
            'order' => $order,
            'amount_tomans' => (float) $payment['amount'],
            'amount_rials' => ((float) $payment['amount']) * 10,
        ]);

        return Response::html($html);
    }

    /**
     * Payment Gateway Callback (Phase 2 - Verification).
     * 
     * @param array<string, string> $vars
     */
    public function callback(Request $request, array $vars = []): Response
    {
        $authority = (string) ($request->getQuery('Authority') ?? $request->getQuery('authority') ?? '');
        $status = (string) ($request->getQuery('Status') ?? $request->getQuery('status') ?? '');

        if ($authority === '') {
            Session::setFlash('error', 'پارامترهای بازگشت از درگاه پرداخت ناقص است.');
            return Response::redirect(url('/'));
        }

        $verification = $this->paymentService->verifyPayment($authority, $status);

        if (!$verification['success']) {
            Session::setFlash('error', $verification['message']);
            return Response::redirect(url('/checkout'));
        }

        // Clear cart after successful checkout
        $this->cartService->clear();

        $orderId = (int) $verification['order_id'];
        Session::setFlash('success', 'تراکنش با موفقیت انجام شد و سفارش شما ثبت گردید.');

        return Response::redirect(url('/order/success/' . $orderId));
    }

    /**
     * Order Success & Invoice page.
     * 
     * @param array<string, string> $vars
     */
    public function success(Request $request, array $vars = []): Response
    {
        $orderId = (int) ($vars['id'] ?? 0);
        if ($orderId <= 0) {
            return Response::redirect(url('/'));
        }

        $order = $this->orderService->getOrderById($orderId);
        if (!$order) {
            Session::setFlash('error', 'سفارش مورد نظر یافت نشد.');
            return Response::redirect(url('/'));
        }

        $items = $this->orderService->getOrderItems($orderId);
        $payment = $this->paymentService->getPaymentByOrderId($orderId);

        $html = View::render('checkout/success', [
            'page_title' => 'سفارش با موفقیت ثبت شد - تحریرچی',
            'order' => $order,
            'items' => $items,
            'payment' => $payment,
        ]);

        return Response::html($html);
    }
}
