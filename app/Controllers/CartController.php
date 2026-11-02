<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\CartService;

class CartController
{
    private CartService $cartService;

    public function __construct()
    {
        $this->cartService = new CartService();
    }

    /**
     * Get Cart data for AJAX drawer.
     * 
     * @param array<string, string> $vars
     */
    public function data(Request $request, array $vars = []): Response
    {
        return Response::json([
            'success' => true,
            'cart' => $this->cartService->getSummary(),
        ]);
    }

    /**
     * Add product to cart via AJAX.
     * 
     * @param array<string, string> $vars
     */
    public function add(Request $request, array $vars = []): Response
    {
        // CSRF validation for state-modifying requests
        $csrfToken = (string) ($request->input('_csrf_token') ?? $request->getHeader('X-CSRF-Token'));
        if (!Session::validateCsrf($csrfToken)) {
            return Response::json([
                'success' => false,
                'message' => 'توکن امنیتی معتبر نیست. لطفاً صفحه را رفرش نمایید.',
            ], 403);
        }

        $productId = (int) $request->input('product_id', 0);
        $variantId = $request->input('variant_id');
        $variantId = ($variantId !== null && $variantId !== '' && $variantId !== 0) ? (int) $variantId : null;
        $quantity = max(1, (int) $request->input('quantity', 1));

        if ($productId <= 0) {
            return Response::json([
                'success' => false,
                'message' => 'شناسه محصول ارسالی نامعتبر است.',
            ], 400);
        }

        $result = $this->cartService->add($productId, $variantId, $quantity);
        $status = $result['success'] ? 200 : 422;

        return Response::json($result, $status);
    }

    /**
     * Update item quantity in cart.
     * 
     * @param array<string, string> $vars
     */
    public function update(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) ($request->input('_csrf_token') ?? $request->getHeader('X-CSRF-Token'));
        if (!Session::validateCsrf($csrfToken)) {
            return Response::json([
                'success' => false,
                'message' => 'توکن امنیتی معتبر نیست.',
            ], 403);
        }

        $cartKey = (string) $request->input('cart_key', '');
        $quantity = (int) $request->input('quantity', 1);

        if ($cartKey === '') {
            return Response::json(['success' => false, 'message' => 'شناسه ردیف سبد مشخص نیست.'], 400);
        }

        $result = $this->cartService->update($cartKey, $quantity);
        $status = $result['success'] ? 200 : 422;

        return Response::json($result, $status);
    }

    /**
     * Remove item from cart.
     * 
     * @param array<string, string> $vars
     */
    public function remove(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) ($request->input('_csrf_token') ?? $request->getHeader('X-CSRF-Token'));
        if (!Session::validateCsrf($csrfToken)) {
            return Response::json([
                'success' => false,
                'message' => 'توکن امنیتی معتبر نیست.',
            ], 403);
        }

        $cartKey = (string) $request->input('cart_key', '');
        if ($cartKey === '') {
            return Response::json(['success' => false, 'message' => 'شناسه ردیف سبد مشخص نیست.'], 400);
        }

        $result = $this->cartService->remove($cartKey);

        return Response::json($result);
    }
}
