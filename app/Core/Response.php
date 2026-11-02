<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    private string $content;
    private int $statusCode;
    /** @var array<string, string> */
    private array $headers = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(string $content = '', int $statusCode = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    public static function html(string $content, int $statusCode = 200): self
    {
        $response = new self($content, $statusCode);
        $response->setHeader('Content-Type', 'text/html; charset=UTF-8');
        return $response;
    }

    public static function json(mixed $data, int $statusCode = 200): self
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $content = $json === false ? '{"error":"JSON encoding failed"}' : $json;

        $response = new self($content, $statusCode);
        $response->setHeader('Content-Type', 'application/json; charset=UTF-8');
        return $response;
    }

    public static function redirect(string $url, int $statusCode = 302): self
    {
        $response = new self('', $statusCode);
        $response->setHeader('Location', $url);
        return $response;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);

            // Default security headers if not already set
            if (!isset($this->headers['X-Content-Type-Options'])) {
                $this->headers['X-Content-Type-Options'] = 'nosniff';
            }
            if (!isset($this->headers['X-Frame-Options'])) {
                $this->headers['X-Frame-Options'] = 'SAMEORIGIN';
            }

            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        echo $this->content;
    }
}
