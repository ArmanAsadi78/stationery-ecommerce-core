<?php

declare(strict_types=1);

namespace App\Core;

class Session
{
    private static bool $started = false;

    /**
     * Start secure session.
     */
    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $lifetime = (int) env('SESSION_LIFETIME', 7200);
        $secure = (bool) env('SESSION_SECURE_COOKIE', false);
        $samesite = (string) env('SESSION_COOKIE_SAMESITE', 'Strict');

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

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
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
