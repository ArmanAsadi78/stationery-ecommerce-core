<?php

declare(strict_types=1);

namespace App\Core;

class Request
{
    /** @var array<string, mixed> */
    private array $get;

    /** @var array<string, mixed> */
    private array $post;

    /** @var array<string, mixed> */
    private array $server;

    /** @var array<string, mixed> */
    private array $cookies;

    /** @var array<string, mixed> */
    private array $files;

    /** @var array<string, mixed>|null */
    private ?array $json = null;

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     */
    public function __construct(
        array $get,
        array $post,
        array $server,
        array $cookies,
        array $files
    ) {
        $this->get = $get;
        $this->post = $post;
        $this->server = $server;
        $this->cookies = $cookies;
        $this->files = $files;

        // Auto-extract query parameters from REQUEST_URI or QUERY_STRING if $get is empty
        if (empty($this->get)) {
            $rawUri = (string) ($this->server['REQUEST_URI'] ?? '');
            $queryString = (string) (parse_url($rawUri, PHP_URL_QUERY) ?? ($this->server['QUERY_STRING'] ?? ''));
            if ($queryString !== '') {
                parse_str($queryString, $parsed);
                /** @var array<string, mixed> $parsed */
                $this->get = $parsed;
            }
        }
    }

    public static function createFromGlobals(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_COOKIE, $_FILES);
    }

    public function getMethod(): string
    {
        $method = (string) ($this->server['REQUEST_METHOD'] ?? 'GET');
        
        // Support method override for PUT/DELETE via POST _method field
        if ($method === 'POST') {
            $override = $this->post['_method'] ?? $this->getHeader('X-HTTP-Method-Override');
            if (is_string($override)) {
                $method = strtoupper($override);
            }
        }

        return strtoupper($method);
    }

    /**
     * Get clean decoded URI path (Unicode / Persian safe).
     */
    public function getPath(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        if ($path === false || $path === null || $path === '') {
            $path = '/';
        }

        // Decode Persian / URL-encoded characters
        $decoded = rawurldecode($path);

        // Normalize sub-folder path if app is running in a sub-directory
        $scriptName = (string) ($this->server['SCRIPT_NAME'] ?? '');
        $scriptDir = dirname($scriptName);
        if ($scriptDir !== '/' && $scriptDir !== '\\' && str_starts_with($decoded, $scriptDir)) {
            $decoded = substr($decoded, strlen($scriptDir));
        }

        $trimmed = '/' . trim($decoded, '/');
        return $trimmed === '/' ? '/' : rtrim($trimmed, '/');
    }

    public function getQuery(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->get;
        }

        return $this->get[$key] ?? $default;
    }

    public function getPost(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->post;
        }

        return $this->post[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getJson(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            $this->json = [];
            return $this->json;
        }

        $decoded = json_decode($raw, true);
        $this->json = is_array($decoded) ? $decoded : [];

        return $this->json;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $post = $this->getPost();
        if (array_key_exists($key, $post)) {
            return $post[$key];
        }

        $json = $this->getJson();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }

        $get = $this->getQuery();
        if (array_key_exists($key, $get)) {
            return $get[$key];
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->getQuery(), $this->getPost(), $this->getJson());
    }

    public function getCookie(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->cookies;
        }

        return $this->cookies[$key] ?? $default;
    }

    public function getFile(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->files;
        }

        return $this->files[$key] ?? null;
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$serverKey]) && is_string($this->server[$serverKey])) {
            return $this->server[$serverKey];
        }

        // Special headers like Content-Type
        if (strtoupper($name) === 'CONTENT-TYPE' && isset($this->server['CONTENT_TYPE'])) {
            return (string) $this->server['CONTENT_TYPE'];
        }

        return $default;
    }

    public function isAjax(): bool
    {
        $requestedWith = $this->getHeader('X-Requested-With');
        if ($requestedWith !== null && strtolower($requestedWith) === 'xmlhttprequest') {
            return true;
        }

        $accept = (string) $this->getHeader('Accept', '');
        return str_contains($accept, 'application/json');
    }

    public function isPjax(): bool
    {
        $pjax = $this->getHeader('X-PJAX');
        return $pjax !== null && ($pjax === 'true' || $pjax === '1');
    }

    public function ip(): string
    {
        $client = $this->server['HTTP_CLIENT_IP'] ?? null;
        $forward = $this->server['HTTP_X_FORWARDED_FOR'] ?? null;
        $remote = $this->server['REMOTE_ADDR'] ?? '127.0.0.1';

        if (is_string($client) && filter_var($client, FILTER_VALIDATE_IP)) {
            return $client;
        }

        if (is_string($forward)) {
            $ips = explode(',', $forward);
            $first = trim($ips[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        return is_string($remote) ? $remote : '127.0.0.1';
    }
}
