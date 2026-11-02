<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuthService;

class AdminMiddleware
{
    private AuthService $auth;

    public function __construct(?AuthService $auth = null)
    {
        $this->auth = $auth ?? new AuthService();
    }

    /**
     * Intercept request and ensure user has 'admin' privileges.
     */
    public function handle(Request $request): ?Response
    {
        if (!$this->auth->check()) {
            // Save Intended URL for redirection after admin login
            $path = $request->getPath();
            Session::set('intended_url', $path);
            Session::setFlash('error', 'برای دسترسی به پنل مدیریت، لطفاً ابتدا وارد حساب کاربری مدیریت شوید.');

            return Response::redirect(url('admin/login'));
        }

        $user = $this->auth->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            $html = "
            <!DOCTYPE html>
            <html lang='fa' dir='rtl'>
            <head>
                <meta charset='UTF-8'>
                <title>۴۰۳ - دسترسی غیرمجاز به پنل مدیریت</title>
                <style>
                    body { font-family: system-ui, Tahoma, sans-serif; background: #0f172a; color: #f8fafc; text-align: center; padding: 100px 20px; }
                    .box { max-width: 480px; margin: 0 auto; background: #1e293b; padding: 40px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); border: 1px solid #334155; }
                    h1 { font-size: 56px; color: #ef4444; margin: 0 0 10px 0; }
                    p { font-size: 15px; margin: 16px 0 24px 0; color: #94a3b8; line-height: 1.8; }
                    a { display: inline-block; padding: 10px 22px; background: #f43f5e; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class='box'>
                    <h1>۴۰۳</h1>
                    <h2>دسترسی غیرمجاز</h2>
                    <p>حساب کاربری شما اجازه دسترسی به پنل مدیریت سامانه را ندارد. این بخش منحصراً برای مدیران ارشد سیستم در نظر گرفته شده است.</p>
                    <a href='" . url('/') . "'>بازگشت به فروشگاه</a>
                </div>
            </body>
            </html>
            ";

            return Response::html($html, 403);
        }

        return null;
    }
}
