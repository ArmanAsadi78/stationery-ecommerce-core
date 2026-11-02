<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\AuthService;

class AuthController
{
    private AuthService $authService;

    public function __construct(?AuthService $authService = null)
    {
        $this->authService = $authService ?? new AuthService();
    }

    /**
     * Show customer login form.
     * Route: GET /login
     * 
     * @param array<string, string> $vars
     */
    public function showLogin(Request $request, array $vars = []): Response
    {
        if ($this->authService->check()) {
            return Response::redirect(url('account/dashboard'));
        }

        $html = View::render('auth/login', [
            'page_title' => 'ورود به حساب کاربری | تحریرچی',
            'meta_description' => 'ورود به پنل کاربری فروشگاه تحریرچی، مشاهده و پیگیری وضعیت سفارش‌ها و مدیریت علاقه‌مندی‌ها.',
            'error_message' => Session::getFlash('error'),
            'success_message' => Session::getFlash('success'),
        ]);

        return Response::html($html);
    }

    /**
     * Handle customer login attempt.
     * Route: POST /login
     * 
     * @param array<string, string> $vars
     */
    public function login(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            if ($request->isAjax()) {
                return Response::json(['success' => false, 'message' => 'توکن امنیتی معتبر نیست. لطفاً صفحه را تازه‌سازی فرمایید.'], 403);
            }
            Session::setFlash('error', 'توکن امنیتی معتبر نیست. لطفاً مجدداً تلاش فرمایید.');
            return Response::redirect(url('login'));
        }

        $identifier = trim((string) $request->input('identifier', ''));
        $password = (string) $request->input('password', '');

        $result = $this->authService->login($identifier, $password);

        if (!$result['success']) {
            if ($request->isAjax()) {
                return Response::json($result, 422);
            }
            Session::setFlash('error', $result['message']);
            return Response::redirect(url('login'));
        }

        /** @var string|null $intendedUrl */
        $intendedUrl = Session::get('intended_url');
        Session::remove('intended_url');
        $redirectUrl = $intendedUrl !== null && $intendedUrl !== '' ? url(ltrim($intendedUrl, '/')) : url('account/dashboard');

        if ($request->isAjax()) {
            return Response::json([
                'success' => true,
                'message' => $result['message'],
                'redirect' => $redirectUrl,
            ], 200);
        }

        Session::setFlash('success', $result['message']);
        return Response::redirect($redirectUrl);
    }

    /**
     * Show customer registration form.
     * Route: GET /register
     * 
     * @param array<string, string> $vars
     */
    public function showRegister(Request $request, array $vars = []): Response
    {
        if ($this->authService->check()) {
            return Response::redirect(url('account/dashboard'));
        }

        $html = View::render('auth/register', [
            'page_title' => 'ثبت‌نام و عضویت در تحریرچی',
            'meta_description' => 'عضویت در خانواده تحریرچی برای تجربه خریدی سریع، رهگیری مرسولات و دریافت تخفیف‌های ویژه مشتریان.',
            'error_message' => Session::getFlash('error'),
            'success_message' => Session::getFlash('success'),
        ]);

        return Response::html($html);
    }

    /**
     * Handle customer registration attempt.
     * Route: POST /register
     * 
     * @param array<string, string> $vars
     */
    public function register(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            if ($request->isAjax()) {
                return Response::json(['success' => false, 'message' => 'توکن امنیتی معتبر نیست. لطفاً صفحه را تازه‌سازی فرمایید.'], 403);
            }
            Session::setFlash('error', 'توکن امنیتی معتبر نیست. لطفاً مجدداً تلاش فرمایید.');
            return Response::redirect(url('register'));
        }

        $data = [
            'full_name' => (string) $request->input('full_name', ''),
            'email' => (string) $request->input('email', ''),
            'phone' => (string) $request->input('phone', ''),
            'password' => (string) $request->input('password', ''),
            'password_confirmation' => (string) $request->input('password_confirmation', ''),
        ];

        $result = $this->authService->register($data);

        if (!$result['success']) {
            if ($request->isAjax()) {
                return Response::json($result, 422);
            }
            Session::setFlash('error', $result['message']);
            return Response::redirect(url('register'));
        }

        if ($request->isAjax()) {
            return Response::json([
                'success' => true,
                'message' => $result['message'],
                'redirect' => url('account/dashboard'),
            ], 200);
        }

        Session::setFlash('success', $result['message']);
        return Response::redirect(url('account/dashboard'));
    }

    /**
     * Safely logout customer.
     * Route: POST /logout
     * 
     * @param array<string, string> $vars
     */
    public function logout(Request $request, array $vars = []): Response
    {
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if ($request->getMethod() === 'POST' && !Session::validateCsrf($csrfToken)) {
            Session::setFlash('error', 'توکن امنیتی نامعتبر بود.');
            return Response::redirect(url('/'));
        }

        $this->authService->logout();
        Session::setFlash('success', 'با موفقیت از حساب کاربری خارج شدید.');

        return Response::redirect(url('/'));
    }
}
