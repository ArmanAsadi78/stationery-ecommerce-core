<?php

declare(strict_types=1);

namespace App\Core;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use RuntimeException;
use function FastRoute\simpleDispatcher;

class Router
{
    /** @var list<array{0: string, 1: string, 2: callable|array{0: class-string, 1: string}, 3: list<class-string>}> */
    private array $routes = [];

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     * @param list<class-string> $middlewares
     */
    public function get(string $route, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute('GET', $route, $handler, $middlewares);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     * @param list<class-string> $middlewares
     */
    public function post(string $route, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute('POST', $route, $handler, $middlewares);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     * @param list<class-string> $middlewares
     */
    public function put(string $route, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute('PUT', $route, $handler, $middlewares);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     * @param list<class-string> $middlewares
     */
    public function delete(string $route, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute('DELETE', $route, $handler, $middlewares);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     * @param list<class-string> $middlewares
     */
    private function addRoute(string $httpMethod, string $route, callable|array $handler, array $middlewares = []): void
    {
        $this->routes[] = [$httpMethod, $route, $handler, $middlewares];
    }

    public function dispatch(Request $request): Response
    {
        $dispatcher = simpleDispatcher(function (RouteCollector $r) {
            foreach ($this->routes as $routeDef) {
                [$method, $pattern, $handler, $middlewares] = $routeDef;
                $r->addRoute($method, $pattern, [$handler, $middlewares]);
            }
        });

        $httpMethod = $request->getMethod();
        $uri = $request->getPath();

        $routeInfo = $dispatcher->dispatch($httpMethod, $uri);

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                return $this->handleNotFound($uri);

            case Dispatcher::METHOD_NOT_ALLOWED:
                /** @var list<string> $allowedMethods */
                $allowedMethods = $routeInfo[1];
                return Response::json([
                    'error' => 'Method Not Allowed',
                    'allowed' => $allowedMethods,
                ], 405);

            case Dispatcher::FOUND:
                /** @var array{0: callable|array{0: class-string, 1: string}, 1: list<class-string>} $routeData */
                $routeData = $routeInfo[1];
                $handler = $routeData[0];
                $middlewares = $routeData[1];
                /** @var array<string, string> $vars */
                $vars = $routeInfo[2];

                // Execute route middlewares
                foreach ($middlewares as $middlewareClass) {
                    if (class_exists($middlewareClass)) {
                        /** @var object $middleware */
                        $middleware = new $middlewareClass();
                        if (method_exists($middleware, 'handle')) {
                            /** @var mixed $midResponse */
                            $midResponse = $middleware->handle($request);
                            if ($midResponse instanceof Response) {
                                return $midResponse;
                            }
                        }
                    }
                }

                return $this->executeHandler($handler, $request, $vars);

            default:
                throw new RuntimeException("Unexpected route dispatcher response.");
        }
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     * @param array<string, string> $vars
     */
    private function executeHandler(callable|array $handler, Request $request, array $vars): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            if (!class_exists($class)) {
                throw new RuntimeException("Controller class '{$class}' not found.");
            }

            $controller = new $class();
            if (!method_exists($controller, $method)) {
                throw new RuntimeException("Method '{$method}' not found in controller '{$class}'.");
            }

            /** @var mixed $result */
            $result = $controller->$method($request, $vars);
        } else {
            /** @var mixed $result */
            $result = $handler($request, $vars);
        }

        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        return Response::html('', 204);
    }

    private function handleNotFound(string $uri): Response
    {
        $html = "
        <!DOCTYPE html>
        <html lang='fa' dir='rtl'>
        <head>
            <meta charset='UTF-8'>
            <title>صفحه مورد نظر یافت نشد - ۴۰۴</title>
            <style>
                body { font-family: system-ui, Tahoma, sans-serif; background: #f8fafc; color: #1e293b; text-align: center; padding: 100px 20px; }
                .box { max-width: 500px; margin: 0 auto; background: white; padding: 40px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
                h1 { font-size: 72px; color: #e11d48; margin: 0; }
                p { font-size: 18px; margin: 20px 0; color: #64748b; }
                a { display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; }
                a:hover { background: #1d4ed8; }
            </style>
        </head>
        <body>
            <div class='box'>
                <h1>۴۰۴</h1>
                <h2>صفحه پیدا نشد</h2>
                <p>متأسفانه صفحه‌ای که با آدرس <code>" . escape_html($uri) . "</code> به دنبال آن بودید وجود ندارد یا منتقل شده است.</p>
                <a href='" . url('/') . "'>بازگشت به صفحه اصلی فروشگاه</a>
            </div>
        </body>
        </html>
        ";

        return Response::html($html, 404);
    }
}
