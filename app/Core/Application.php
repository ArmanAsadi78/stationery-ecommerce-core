<?php

declare(strict_types=1);

namespace App\Core;

use Dotenv\Dotenv;
use Throwable;

class Application
{
    private Router $router;
    private Request $request;

    public function __construct()
    {
        // 1. Load Environment variables
        $dotenv = Dotenv::createImmutable(base_path());
        $dotenv->safeLoad();

        // 2. Start Secure Session
        Session::start();

        // 3. Initialize Request & Router
        $this->request = Request::createFromGlobals();
        $this->router = new Router();
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function handle(Request $request): Response
    {
        return $this->router->dispatch($request);
    }

    public function run(): void
    {
        try {
            $response = $this->handle($this->request);
            $response->send();
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    private function handleException(Throwable $e): void
    {
        $debug = (bool) config('app.debug', false);

        if ($this->request->isAjax()) {
            $data = [
                'error' => true,
                'message' => $debug ? $e->getMessage() : 'خطای داخلی در سرور رخ داده است.',
            ];
            if ($debug) {
                $data['trace'] = $e->getTraceAsString();
                $data['file'] = $e->getFile();
                $data['line'] = $e->getLine();
            }
            Response::json($data, 500)->send();
            return;
        }

        if ($debug) {
            $html = "
            <!DOCTYPE html>
            <html lang='fa' dir='rtl'>
            <head>
                <meta charset='UTF-8'>
                <title>خطای سیستم (Debug Mode)</title>
                <style>
                    body { font-family: monospace; background: #0f172a; color: #f8fafc; padding: 30px; direction: ltr; text-align: left; }
                    .error-card { background: #1e293b; border-left: 6px solid #ef4444; padding: 25px; border-radius: 8px; margin-bottom: 20px; }
                    h1 { color: #f87171; font-size: 24px; margin-top: 0; }
                    pre { background: #090d16; padding: 15px; border-radius: 6px; overflow-x: auto; color: #38bdf8; }
                </style>
            </head>
            <body>
                <div class='error-card'>
                    <h1>" . get_class($e) . ": " . escape_html($e->getMessage()) . "</h1>
                    <p><strong>File:</strong> " . escape_html($e->getFile()) . " (Line " . $e->getLine() . ")</p>
                </div>
                <h3>Stack Trace:</h3>
                <pre>" . escape_html($e->getTraceAsString()) . "</pre>
            </body>
            </html>
            ";
        } else {
            $html = "
            <!DOCTYPE html>
            <html lang='fa' dir='rtl'>
            <head>
                <meta charset='UTF-8'>
                <title>خطای داخلی سرور - ۵۰۰</title>
                <style>
                    body { font-family: system-ui, Tahoma, sans-serif; background: #f8fafc; color: #1e293b; text-align: center; padding: 100px 20px; }
                    .box { max-width: 500px; margin: 0 auto; background: white; padding: 40px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
                    h1 { font-size: 72px; color: #ef4444; margin: 0; }
                    p { font-size: 18px; margin: 20px 0; color: #64748b; }
                    a { display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class='box'>
                    <h1>۵۰۰</h1>
                    <h2>خطایی رخ داده است</h2>
                    <p>متأسفانه در پردازش درخواست شما خطایی رخ داد. تیم فنی در حال بررسی مشکل است.</p>
                    <a href='" . url('/') . "'>بازگشت به صفحه اصلی</a>
                </div>
            </body>
            </html>
            ";
        }

        Response::html($html, 500)->send();
    }
}
