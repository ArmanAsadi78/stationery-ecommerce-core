<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Session;

class AuthService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Check if user is currently authenticated.
     */
    public function check(): bool
    {
        Session::start();
        $user = Session::get('user');
        return is_array($user) && !empty($user['id']);
    }

    /**
     * Get currently authenticated user ID.
     */
    public function id(): ?int
    {
        if (!$this->check()) {
            return null;
        }

        /** @var array{id: int|string} $user */
        $user = Session::get('user');
        return (int) $user['id'];
    }

    /**
     * Get current user entity from database.
     * 
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        $userId = $this->id();
        if ($userId === null) {
            return null;
        }

        /** @var array<string, mixed>|null $row */
        $row = $this->db->fetch(
            "SELECT `id`, `full_name`, `email`, `phone`, `role`, `is_active`, `created_at`, `updated_at` 
             FROM `users` WHERE `id` = :id LIMIT 1",
            [':id' => $userId]
        );

        if (!$row) {
            $this->logout();
            return null;
        }

        // Keep name alias for backward-compatibility with Twig and templates
        $row['name'] = $row['full_name'];

        return $row;
    }

    /**
     * Register a new customer account.
     * 
     * @param array{
     *     full_name?: string,
     *     email?: string,
     *     phone?: string,
     *     password?: string,
     *     password_confirmation?: string
     * } $data
     * @return array{success: bool, message: string, errors: array<string, string>, user?: array<string, mixed>}
     */
    public function register(array $data): array
    {
        $fullName = trim((string) ($data['full_name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = trim((string) ($data['phone'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $passwordConfirmation = (string) ($data['password_confirmation'] ?? '');

        $errors = [];

        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
            $errors['full_name'] = 'نام و نام‌خانوادگی باید بین ۲ تا ۱۰۰ کاراکتر باشد.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'لطفاً یک آدرس ایمیل معتبر وارد فرمایید.';
        } else {
            $existingEmail = $this->db->fetch(
                "SELECT `id` FROM `users` WHERE `email` = :email LIMIT 1",
                [':email' => $email]
            );
            if ($existingEmail) {
                $errors['email'] = 'این آدرس ایمیل قبلاً در سیستم ثبت شده است.';
            }
        }

        if ($phone !== '') {
            // Clean phone format
            $cleanPhone = preg_replace('/[^\d]/', '', $phone) ?? '';
            if (mb_strlen($cleanPhone) < 10 || mb_strlen($cleanPhone) > 15) {
                $errors['phone'] = 'شماره تلفن همراه وارد شده نامعتبر است.';
            } else {
                $existingPhone = $this->db->fetch(
                    "SELECT `id` FROM `users` WHERE `phone` = :phone LIMIT 1",
                    [':phone' => $phone]
                );
                if ($existingPhone) {
                    $errors['phone'] = 'این شماره تماس قبلاً به حساب کاربری دیگری متصل شده است.';
                }
            }
        } else {
            $phone = null;
        }

        if (mb_strlen($password) < 6) {
            $errors['password'] = 'رمز عبور باید حداقل ۶ کاراکتر باشد.';
        }

        if ($passwordConfirmation !== '' && $password !== $passwordConfirmation) {
            $errors['password_confirmation'] = 'تکرار رمز عبور با رمز عبور اصلی یکسان نیست.';
        }

        if (!empty($errors)) {
            $firstError = reset($errors);
            return [
                'success' => false,
                'message' => $firstError,
                'errors' => $errors,
            ];
        }

        // Secure password hashing (Argon2id if supported, otherwise Bcrypt)
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $hash = password_hash($password, $algo);

        $this->db->execute(
            "INSERT INTO `users` (`full_name`, `email`, `phone`, `password_hash`, `role`, `is_active`, `created_at`) 
             VALUES (:full_name, :email, :phone, :hash, 'customer', 1, NOW())",
            [
                ':full_name' => $fullName,
                ':email' => $email,
                ':phone' => $phone,
                ':hash' => $hash,
            ]
        );

        $newUserId = (int) $this->db->lastInsertId();

        $userData = [
            'id' => $newUserId,
            'full_name' => $fullName,
            'name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'role' => 'customer',
        ];

        // Auto login on successful registration
        Session::regenerate(true);
        Session::set('user', $userData);

        return [
            'success' => true,
            'message' => 'ثبت‌نام شما با موفقیت انجام شد. به تحریرچی خوش آمدید!',
            'errors' => [],
            'user' => $userData,
        ];
    }

    /**
     * Authenticate customer with email or phone and password.
     * Includes simple brute-force throttle mitigation.
     * 
     * @return array{success: bool, message: string, errors: array<string, string>, user?: array<string, mixed>}
     */
    public function login(string $identifier, string $password): array
    {
        Session::start();

        // 1. Brute-force throttle check
        /** @var array{count: int, time: int} $throttle */
        $throttle = Session::get('login_throttle', ['count' => 0, 'time' => time()]);
        if ($throttle['count'] >= 5 && (time() - $throttle['time']) < 60) {
            $remaining = 60 - (time() - $throttle['time']);
            return [
                'success' => false,
                'message' => "تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً {$remaining} ثانیه دیگر مجدداً تلاش نمایید.",
                'errors' => ['login' => 'حساب موقتاً قفل شده است.'],
            ];
        }

        $cleanIdentifier = trim($identifier);
        if ($cleanIdentifier === '' || $password === '') {
            return [
                'success' => false,
                'message' => 'لطفاً نام کاربری (ایمیل یا موبایل) و رمز عبور را وارد فرمایید.',
                'errors' => [
                    'identifier' => 'وارد کردن شناسه کاربری الزامی است.',
                    'password' => 'وارد کردن رمز عبور الزامی است.',
                ],
            ];
        }

        // 2. Query user by email OR phone
        /** @var array<string, mixed>|null $user */
        $user = $this->db->fetch(
            "SELECT `id`, `full_name`, `email`, `phone`, `password_hash`, `role`, `is_active` 
             FROM `users` 
             WHERE `email` = :id_email OR `phone` = :id_phone 
             LIMIT 1",
            [
                ':id_email' => strtolower($cleanIdentifier),
                ':id_phone' => $cleanIdentifier,
            ]
        );

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            // Increment failed attempt counter
            $newCount = $throttle['count'] + 1;
            Session::set('login_throttle', ['count' => $newCount, 'time' => time()]);

            return [
                'success' => false,
                'message' => 'ایمیل، شماره موبایل یا رمز عبور وارد شده نادرست است.',
                'errors' => ['login' => 'اطلاعات ورود نامعتبر است.'],
            ];
        }

        // 3. Check if account is active
        if (!(bool) $user['is_active']) {
            return [
                'success' => false,
                'message' => 'حساب کاربری شما مسدود شده است. لطفاً با پشتیبانی تحریرچی تماس حاصل فرمایید.',
                'errors' => ['account' => 'حساب غیرفعال است.'],
            ];
        }

        // 4. Successful login: Reset throttle, regenerate session ID to prevent Session Fixation
        Session::remove('login_throttle');
        Session::regenerate(true);

        $userData = [
            'id' => (int) $user['id'],
            'full_name' => (string) $user['full_name'],
            'name' => (string) $user['full_name'],
            'email' => (string) $user['email'],
            'phone' => $user['phone'] !== null ? (string) $user['phone'] : null,
            'role' => (string) $user['role'],
        ];

        Session::set('user', $userData);

        return [
            'success' => true,
            'message' => 'با موفقیت وارد حساب کاربری شدید.',
            'errors' => [],
            'user' => $userData,
        ];
    }

    /**
     * Safely destroy session and logout.
     */
    public function logout(): void
    {
        Session::remove('user');
        Session::regenerate(true);
        Session::regenerateCsrfToken();
    }

    /**
     * Update user profile information.
     * 
     * @param array{full_name?: string, phone?: string} $data
     * @return array{success: bool, message: string, errors: array<string, string>}
     */
    public function updateProfile(int $userId, array $data): array
    {
        $fullName = trim((string) ($data['full_name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));

        $errors = [];

        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
            $errors['full_name'] = 'نام و نام‌خانوادگی باید بین ۲ تا ۱۰۰ کاراکتر باشد.';
        }

        if ($phone !== '') {
            $existing = $this->db->fetch(
                "SELECT `id` FROM `users` WHERE `phone` = :phone AND `id` != :id LIMIT 1",
                [':phone' => $phone, ':id' => $userId]
            );
            if ($existing) {
                $errors['phone'] = 'این شماره تماس قبلاً توسط کاربر دیگری ثبت شده است.';
            }
        } else {
            $phone = null;
        }

        if (!empty($errors)) {
            $firstError = reset($errors);
            return [
                'success' => false,
                'message' => $firstError,
                'errors' => $errors,
            ];
        }

        $this->db->execute(
            "UPDATE `users` SET `full_name` = :full_name, `phone` = :phone, `updated_at` = NOW() WHERE `id` = :id",
            [':full_name' => $fullName, ':phone' => $phone, ':id' => $userId]
        );

        // Update active session data
        /** @var array<string, mixed> $sessUser */
        $sessUser = Session::get('user', []);
        $sessUser['full_name'] = $fullName;
        $sessUser['name'] = $fullName;
        $sessUser['phone'] = $phone;
        Session::set('user', $sessUser);

        return [
            'success' => true,
            'message' => 'اطلاعات پروفایل کاربری با موفقیت به‌روزرسانی شد.',
            'errors' => [],
        ];
    }

    /**
     * Change user password with old password verification.
     * 
     * @return array{success: bool, message: string, errors: array<string, string>}
     */
    public function changePassword(int $userId, string $oldPassword, string $newPassword): array
    {
        /** @var array<string, mixed>|null $user */
        $user = $this->db->fetch(
            "SELECT `password_hash` FROM `users` WHERE `id` = :id LIMIT 1",
            [':id' => $userId]
        );

        if (!$user || !password_verify($oldPassword, (string) $user['password_hash'])) {
            return [
                'success' => false,
                'message' => 'رمز عبور فعلی وارد شده نادرست است.',
                'errors' => ['old_password' => 'رمز فعلی اشتباه است.'],
            ];
        }

        if (mb_strlen($newPassword) < 6) {
            return [
                'success' => false,
                'message' => 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد.',
                'errors' => ['new_password' => 'رمز باید حداقل ۶ کاراکتر باشد.'],
            ];
        }

        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $newHash = password_hash($newPassword, $algo);

        $this->db->execute(
            "UPDATE `users` SET `password_hash` = :hash, `updated_at` = NOW() WHERE `id` = :id",
            [':hash' => $newHash, ':id' => $userId]
        );

        return [
            'success' => true,
            'message' => 'رمز عبور شما با موفقیت تغییر یافت.',
            'errors' => [],
        ];
    }

    /**
     * Toggle a product in user's wishlist.
     * 
     * @return array{success: bool, in_wishlist: bool, message: string, total_count: int}
     */
    public function toggleWishlist(int $userId, int $productId): array
    {
        $existing = $this->db->fetch(
            "SELECT `id` FROM `wishlists` WHERE `user_id` = :user_id AND `product_id` = :product_id LIMIT 1",
            [':user_id' => $userId, ':product_id' => $productId]
        );

        if ($existing) {
            $this->db->execute(
                "DELETE FROM `wishlists` WHERE `id` = :id",
                [':id' => $existing['id']]
            );
            $inWishlist = false;
            $msg = 'کالا از لیست علاقه‌مندی‌ها حذف شد.';
        } else {
            $this->db->execute(
                "INSERT INTO `wishlists` (`user_id`, `product_id`, `created_at`) VALUES (:user_id, :product_id, NOW())",
                [':user_id' => $userId, ':product_id' => $productId]
            );
            $inWishlist = true;
            $msg = 'کالا به لیست علاقه‌مندی‌های شما اضافه شد.';
        }

        $countRow = $this->db->fetch(
            "SELECT COUNT(*) AS total FROM `wishlists` WHERE `user_id` = :user_id",
            [':user_id' => $userId]
        );
        $totalCount = (int) ($countRow['total'] ?? 0);

        return [
            'success' => true,
            'in_wishlist' => $inWishlist,
            'message' => $msg,
            'total_count' => $totalCount,
        ];
    }

    /**
     * Get user's wishlist products.
     * 
     * @return list<array<string, mixed>>
     */
    public function getWishlist(int $userId): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = $this->db->fetchAll(
            "SELECT p.`id`, p.`title`, p.`slug`, p.`price`, p.`compare_at_price`, p.`stock_quantity`, 
                    p.`primary_image`, c.`title` AS category_title, w.`created_at` AS added_at
             FROM `wishlists` w
             INNER JOIN `products` p ON w.`product_id` = p.`id`
             LEFT JOIN `categories` c ON p.`category_id` = c.`id`
             WHERE w.`user_id` = :user_id AND p.`is_active` = 1
             ORDER BY w.`created_at` DESC",
            [':user_id' => $userId]
        );

        return $items;
    }
}
