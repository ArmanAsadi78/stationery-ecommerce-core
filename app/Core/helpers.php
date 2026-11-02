<?php

declare(strict_types=1);

use App\Core\Session;

if (!function_exists('base_path')) {
    /**
     * Get the base path of the project.
     */
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__, 2);
        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}

if (!function_exists('app_path')) {
    /**
     * Get the app directory path.
     */
    function app_path(string $path = ''): string
    {
        return base_path('app' . ($path === '' ? '' : DIRECTORY_SEPARATOR . ltrim($path, '/\\')));
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get the storage directory path.
     */
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path === '' ? '' : DIRECTORY_SEPARATOR . ltrim($path, '/\\')));
    }
}

if (!function_exists('public_path')) {
    /**
     * Get the public directory path.
     */
    function public_path(string $path = ''): string
    {
        return base_path('public' . ($path === '' ? '' : DIRECTORY_SEPARATOR . ltrim($path, '/\\')));
    }
}

if (!function_exists('config_path')) {
    /**
     * Get the config directory path.
     */
    function config_path(string $path = ''): string
    {
        return base_path('config' . ($path === '' ? '' : DIRECTORY_SEPARATOR . ltrim($path, '/\\')));
    }
}

if (!function_exists('config')) {
    /**
     * Retrieve configuration value using 'file.key' syntax.
     */
    function config(string $key, mixed $default = null): mixed
    {
        /** @var array<string, mixed> $configs */
        static $configs = [];

        $parts = explode('.', $key);
        $file = $parts[0];

        if (!isset($configs[$file])) {
            $filePath = config_path($file . '.php');
            if (file_exists($filePath)) {
                /** @var mixed $loaded */
                $loaded = require $filePath;
                $configs[$file] = is_array($loaded) ? $loaded : [];
            } else {
                $configs[$file] = [];
            }
        }

        $current = $configs[$file];
        for ($i = 1; $i < count($parts); $i++) {
            $part = $parts[$i];
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } else {
                return $default;
            }
        }

        return $current;
    }
}

if (!function_exists('env')) {
    /**
     * Retrieve environment variable with default fallback.
     */
    function env(string $key, mixed $default = null): mixed
    {
        if (isset($_ENV[$key])) {
            $val = $_ENV[$key];
        } elseif (isset($_SERVER[$key])) {
            $val = $_SERVER[$key];
        } else {
            $envVal = getenv($key);
            if ($envVal === false) {
                return $default;
            }
            $val = $envVal;
        }

        if (is_string($val)) {
            return match (strtolower($val)) {
                'true', '(true)' => true,
                'false', '(false)' => false,
                'empty', '(empty)' => '',
                'null', '(null)' => null,
                default => $val,
            };
        }

        return $val;
    }
}

if (!function_exists('escape_html')) {
    /**
     * HTML escape helper.
     */
    function escape_html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /**
     * Generate relative or absolute application URL.
     */
    function url(string $path = ''): string
    {
        $base = rtrim((string) (config('app.url') ?? ''), '/');
        $cleanPath = '/' . ltrim($path, '/');
        return $cleanPath === '/' ? ($base ?: '/') : ($base ? $base . $cleanPath : $cleanPath);
    }
}

if (!function_exists('asset')) {
    /**
     * Generate URL for public assets.
     */
    function asset(string $path): string
    {
        return url('assets/' . ltrim($path, '/'));
    }
}

if (!function_exists('persian_number')) {
    /**
     * Convert English digits to Persian digits.
     */
    function persian_number(int|float|string $number): string
    {
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return str_replace($en, $fa, (string) $number);
    }
}

if (!function_exists('format_price')) {
    /**
     * Format price with thousand separators and Toman currency symbol in Persian.
     */
    function format_price(int|float|string $price): string
    {
        $numeric = (float) $price;
        $formatted = number_format($numeric, 0, '.', '،');
        return persian_number($formatted) . ' تومان';
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Get active CSRF token.
     */
    function csrf_token(): string
    {
        return Session::csrfToken();
    }
}
