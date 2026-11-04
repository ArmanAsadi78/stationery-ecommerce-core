<?php

declare(strict_types=1);

namespace App\Core;

class Session
{
    private static bool $started = false;

    public const ADMIN_TIMEOUT = 43200;       // 12 hours in seconds
    public const CUSTOMER_TIMEOUT = 259200;   // 3 days in seconds

    /**
     * Start secure session.
     */
    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $savePath = storage_path('sessions');
        if (!is_dir($savePath)) {
            mkdir($savePath, 0777, true);
        }
        session_save_path($savePath);

        $lifetime = (int) env('SESSION_LIFETIME', self::CUSTOMER_TIMEOUT);
        if ($lifetime < self::CUSTOMER_TIMEOUT) {
            $lifetime = self::CUSTOMER_TIMEOUT;
        }

        $secure = (bool) env('SESSION_SECURE_COOKIE', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'));
        $samesite = (string) env('SESSION_COOKIE_SAMESITE', 'Lax');

        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $samesite,
        ]);

        session_start();
        self::$started = true;

        // Auto initialize CSRF token if not set
        if (!isset($_SESSION['_csrf_token'])) {
            self::regenerateCsrfToken();
        }
    }

    /**
     * Check and handle sliding inactivity timeout for admin session (12 hours / 43,200s).
     * Returns true if session is valid and active, false if timed out.
     */
    public static function checkAdminInactivityTimeout(int $timeout = self::ADMIN_TIMEOUT): bool
    {
        self::start();
        if (isset($_SESSION['user']) && is_array($_SESSION['user']) && ($_SESSION['user']['role'] ?? '') === 'admin') {
            $lastActivity = $_SESSION['admin_last_activity'] ?? $_SESSION['last_activity'] ?? null;
            if ($lastActivity !== null && is_numeric($lastActivity)) {
                $elapsed = time() - (int) $lastActivity;
                if ($elapsed > $timeout) {
                    self::invalidateUserSession('نشست مدیریت شما به دلیل عدم فعالیت بیش از ۱۲ ساعت منقضی شد. لطفاً مجدداً وارد شوید.');
                    return false;
                }
            }
            $currentTime = time();
            $_SESSION['admin_last_activity'] = $currentTime;
            $_SESSION['last_activity'] = $currentTime;
        }
        return true;
    }

    /**
     * Check and handle sliding inactivity timeout for customer session (3 days / 259,200s).
     * Returns true if session is valid and active, false if timed out.
     */
    public static function checkCustomerInactivityTimeout(int $timeout = self::CUSTOMER_TIMEOUT): bool
    {
        self::start();
        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $lastActivity = $_SESSION['user_last_activity'] ?? $_SESSION['last_activity'] ?? null;
            if ($lastActivity !== null && is_numeric($lastActivity)) {
                $elapsed = time() - (int) $lastActivity;
                if ($elapsed > $timeout) {
                    self::invalidateUserSession('نشست کاربری شما به دلیل عدم فعالیت بیش از ۳ روز منقضی شد. لطفاً مجدداً وارد شوید.');
                    return false;
                }
            }
            $currentTime = time();
            $_SESSION['user_last_activity'] = $currentTime;
            $_SESSION['last_activity'] = $currentTime;
        }
        return true;
    }

    /**
     * Invalidate authenticated user session data and set flash message.
     */
    public static function invalidateUserSession(string $message): void
    {
        self::start();
        unset($_SESSION['user']);
        unset($_SESSION['last_activity']);
        unset($_SESSION['admin_last_activity']);
        unset($_SESSION['user_last_activity']);
        self::setFlash('error', $message);
    }

    /**
     * Handle sliding inactivity timeout for authenticated sessions based on role.
     */
    public static function checkInactivityTimeout(?int $timeout = null): bool
    {
        self::start();
        if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
            return true;
        }

        $role = (string) ($_SESSION['user']['role'] ?? 'customer');
        if ($role === 'admin') {
            return self::checkAdminInactivityTimeout($timeout ?? self::ADMIN_TIMEOUT);
        }

        return self::checkCustomerInactivityTimeout($timeout ?? self::CUSTOMER_TIMEOUT);
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
        if ($key === 'user' && !empty($value)) {
            $currentTime = time();
            $_SESSION['last_activity'] = $currentTime;
            if (is_array($value) && ($value['role'] ?? '') === 'admin') {
                $_SESSION['admin_last_activity'] = $currentTime;
            }
            $_SESSION['user_last_activity'] = $currentTime;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
        if ($key === 'user') {
            unset($_SESSION['last_activity']);
            unset($_SESSION['admin_last_activity']);
            unset($_SESSION['user_last_activity']);
        }
    }

    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            self::regenerateCsrfToken();
        }

        return (string) $_SESSION['_csrf_token'];
    }

    public static function regenerateCsrfToken(): string
    {
        self::start();
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    public static function validateCsrf(?string $token): bool
    {
        self::start();
        if ($token === null || empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            return false;
        }

        return hash_equals((string) $_SESSION['_csrf_token'], $token);
    }

    public static function setFlash(string $key, string $message): void
    {
        self::start();
        /** @var array<string, string> $flashes */
        $flashes = self::get('_flash', []);
        $flashes[$key] = $message;
        self::set('_flash', $flashes);
    }

    public static function getFlash(string $key): ?string
    {
        self::start();
        /** @var array<string, string> $flashes */
        $flashes = self::get('_flash', []);
        if (isset($flashes[$key])) {
            $msg = $flashes[$key];
            unset($flashes[$key]);
            self::set('_flash', $flashes);
            return $msg;
        }

        return null;
    }

    public static function hasFlash(string $key): bool
    {
        self::start();
        /** @var array<string, string> $flashes */
        $flashes = self::get('_flash', []);
        return isset($flashes[$key]);
    }

    public static function regenerate(bool $deleteOldSession = true): bool
    {
        self::start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            return session_regenerate_id($deleteOldSession);
        }
        return false;
    }

    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        self::$started = false;
    }
}
