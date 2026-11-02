<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\CartService;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Twig\TwigFilter;

class View
{
    private static ?Environment $twig = null;

    public static function getTwig(): Environment
    {
        if (self::$twig !== null) {
            return self::$twig;
        }

        $viewsPath = app_path('Views');
        $cachePath = storage_path('cache' . DIRECTORY_SEPARATOR . 'twig');

        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0777, true);
        }

        $loader = new FilesystemLoader($viewsPath);
        $debug = (bool) config('app.debug', true);

        self::$twig = new Environment($loader, [
            'cache' => $debug ? false : $cachePath,
            'debug' => $debug,
            'auto_reload' => true,
            'charset' => 'UTF-8',
        ]);

        self::registerCustomExtensions(self::$twig);

        return self::$twig;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = []): string
    {
        // Normalize .twig extension
        if (!str_ends_with($template, '.twig')) {
            $template .= '.twig';
        }

        $twig = self::getTwig();

        // Inject dynamic globals
        $cartService = new CartService();
        $globalData = array_merge([
            'app_name' => config('app.name', 'فروشگاه لوازم‌التحریر تحریرچی'),
            'app_url' => config('app.url', 'http://localhost'),
            'csrf_token' => Session::csrfToken(),
            'cart_count' => $cartService->getTotalCount(),
            'cart_total' => $cartService->getSubtotal(),
            'cart_items' => $cartService->getItems(),
            'auth_user' => Session::get('user'),
            'is_logged_in' => Session::has('user'),
        ], $data);

        return $twig->render($template, $globalData);
    }

    private static function registerCustomExtensions(Environment $twig): void
    {
        // Helper Functions
        $twig->addFunction(new TwigFunction('csrf_token', function (): string {
            return Session::csrfToken();
        }));

        $twig->addFunction(new TwigFunction('csrf_field', function (): string {
            $token = Session::csrfToken();
            return '<input type="hidden" name="_csrf_token" value="' . escape_html($token) . '">';
        }, ['is_safe' => ['html']]));

        $twig->addFunction(new TwigFunction('asset', function (string $path, bool $absolute = false): string {
            return asset($path, $absolute);
        }));

        $twig->addFunction(new TwigFunction('url', function (string $path = '', bool $absolute = false): string {
            return url($path, $absolute);
        }));

        $twig->addFunction(new TwigFunction('format_price', function (int|float|string $price): string {
            return format_price($price);
        }));

        $twig->addFunction(new TwigFunction('persian_number', function (int|float|string $number): string {
            return persian_number($number);
        }));

        $twig->addFunction(new TwigFunction('flash', function (string $type): ?string {
            return Session::getFlash($type);
        }));

        $twig->addFunction(new TwigFunction('has_flash', function (string $type): bool {
            return Session::hasFlash($type);
        }));

        // Helper Filters
        $twig->addFilter(new TwigFilter('price', function (int|float|string $price): string {
            return format_price($price);
        }));

        $twig->addFilter(new TwigFilter('fa_num', function (int|float|string $num): string {
            return persian_number($num);
        }));
    }
}
