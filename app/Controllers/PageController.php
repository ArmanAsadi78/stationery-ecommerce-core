<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

class PageController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Display About Us page.
     * Route: GET /about-us
     * 
     * @param array<string, string> $vars
     */
    public function about(Request $request, array $vars = []): Response
    {
        $html = View::render('pages/about', [
            'page_title' => 'درباره ما | فروشگاه لوازم‌التحریر تحریرچی',
            'meta_description' => 'آشنایی با فروشگاه تحریرچی، رسالت ما در ارائه نوشت‌افزارهای اصیل، بسته‌بندی ویژه اقلام حساس و استانداردهای کیفی ما.',
            'canonical_url' => url('about-us', true),
        ]);

        return Response::html($html);
    }

    /**
     * Display Contact Us page.
     * Route: GET /contact-us
     * 
     * @param array<string, string> $vars
     */
    public function contact(Request $request, array $vars = []): Response
    {
        $html = View::render('pages/contact', [
            'page_title' => 'تماس با ما | پشتیبانی و مشاوره تخصصی تحریرچی',
            'meta_description' => 'راه‌های ارتباطی با تحریرچی، تلفن پشتیبانی، آدرس پستی، ساعات پاسخگویی و فرم ارسال پیام آنلاین.',
            'canonical_url' => url('contact-us', true),
        ]);

        return Response::html($html);
    }

    /**
     * Handle Contact Us form submission via AJAX.
     * Route: POST /contact-us
     * 
     * @param array<string, string> $vars
     */
    public function submitContact(Request $request, array $vars = []): Response
    {
        // 1. Verify CSRF Token
        $csrfToken = (string) (
            $request->input('_csrf_token') 
            ?? $request->input('csrf_token') 
            ?? $request->getHeader('X-CSRF-Token')
        );

        if (!Session::validateCsrf($csrfToken)) {
            return Response::json([
                'success' => false,
                'message' => 'توکن امنیتی معتبر نیست. لطفاً صفحه را تازه‌سازی فرمایید.',
            ], 403);
        }

        // 2. Extract and Sanitize Inputs
        $name = trim((string) $request->input('name', ''));
        $email = trim((string) $request->input('email', ''));
        $phone = trim((string) $request->input('phone', ''));
        $subject = trim((string) $request->input('subject', ''));
        $message = trim((string) $request->input('message', ''));

        // 3. Validation
        $errors = [];

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors['name'] = 'نام و نام‌خانوادگی باید بین ۲ تا ۱۰۰ کاراکتر باشد.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'لطفاً یک آدرس ایمیل معتبر وارد فرمایید.';
        }

        if ($phone !== '' && (mb_strlen($phone) < 7 || mb_strlen($phone) > 20)) {
            $errors['phone'] = 'شماره تماس وارد شده معتبر نیست.';
        }

        if (mb_strlen($subject) < 3 || mb_strlen($subject) > 150) {
            $errors['subject'] = 'موضوع پیام باید بین ۳ تا ۱۵۰ کاراکتر باشد.';
        }

        if (mb_strlen($message) < 10 || mb_strlen($message) > 3000) {
            $errors['message'] = 'متن پیام باید حداقل ۱۰ کاراکتر باشد.';
        }

        if (!empty($errors)) {
            $firstError = reset($errors);
            return Response::json([
                'success' => false,
                'message' => $firstError,
                'errors' => $errors,
            ], 422);
        }

        // 4. Save to Database
        try {
            $this->db->execute(
                "INSERT INTO `contact_messages` (`name`, `email`, `phone`, `subject`, `message`, `is_read`, `created_at`) 
                 VALUES (?, ?, ?, ?, ?, 0, NOW())",
                [
                    $name,
                    $email,
                    $phone !== '' ? $phone : null,
                    $subject,
                    $message,
                ]
            );

            return Response::json([
                'success' => true,
                'message' => 'پیام شما با موفقیت دریافت شد. کارشناسان تحریرچی به زودی با شما تماس خواهند گرفت.',
            ], 200);
        } catch (\Throwable $e) {
            return Response::json([
                'success' => false,
                'message' => 'خطایی در ثبت پیام رخ داد. لطفاً لحظاتی دیگر تلاش فرمایید.',
            ], 500);
        }
    }

    /**
     * Display Frequently Asked Questions (FAQ) page.
     * Route: GET /faq
     * 
     * @param array<string, string> $vars
     */
    public function faq(Request $request, array $vars = []): Response
    {
        $html = View::render('pages/faq', [
            'page_title' => 'سوالات متداول | پرسش‌های پرتکرار مشتریان تحریرچی',
            'meta_description' => 'پاسخ به سوالات متداول درباره نحوه ثبت سفارش، رهگیری پستی، ضمانت بازگشت کالا و شیوه‌های پرداخت در تحریرچی.',
            'canonical_url' => url('faq', true),
        ]);

        return Response::html($html);
    }

    /**
     * Display Terms, Conditions and Guarantee Policies.
     * Route: GET /terms
     * 
     * @param array<string, string> $vars
     */
    public function terms(Request $request, array $vars = []): Response
    {
        $html = View::render('pages/terms', [
            'page_title' => 'قوانین و رویه‌های ضمانت بازگشت | تحریرچی',
            'meta_description' => 'قوانین و رویه‌های رسمی بازگرداندن کالا، ضمانت ۷ روزه بازگشت وجه، حریم خصوصی و ضوابط خرید از فروشگاه تحریرچی.',
            'canonical_url' => url('terms', true),
        ]);

        return Response::html($html);
    }
}
