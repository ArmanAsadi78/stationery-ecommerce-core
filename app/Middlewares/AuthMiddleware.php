<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuthService;

class AuthMiddleware
{
    private AuthService $auth;

    public function __construct(?AuthService $auth = null)
    {
        $this->auth = $auth ?? new AuthService();
    }

    /**
     * Intercept request and ensure authentication.
     */
    public function handle(Request $request): ?Response
    {
        if (!$this->auth->check()) {
            // Save Intended URL for redirection after login
            $path = $request->getPath();
            Session::set('intended_url', $path);
            Session::setFlash('error', 'برای دسترسی به این بخش، لطفاً ابتدا وارد حساب کاربری خود شوید.');

            return Response::redirect(url('login'));
        }

        // Role-based Sliding Inactivity check for customer (3 days / 259,200s)
        if (!Session::checkCustomerInactivityTimeout(Session::CUSTOMER_TIMEOUT)) {
            $path = $request->getPath();
            Session::set('intended_url', $path);
            return Response::redirect(url('login'));
        }

        return null;
    }
}
