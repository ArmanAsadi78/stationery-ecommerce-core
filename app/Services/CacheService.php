<?php

declare(strict_types=1);

namespace App\Services;

class CacheService
{
    private string $cacheDir;
    private bool $lastHit = false;

    public function __construct(string $subDir = 'default')
    {
        $cleanSubDir = trim(str_replace(['..', '/', '\\'], '', $subDir));
        if ($cleanSubDir === '' || $cleanSubDir === 'default') {
            $this->cacheDir = storage_path('cache');
        } else {
            $this->cacheDir = storage_path('cache' . DIRECTORY_SEPARATOR . $cleanSubDir);
        }

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    public static function torob(): self
    {
        return new self('torob');
    }

    public static function flushTorob(): bool
    {
        return self::torob()->flush();
    }

    public static function sitemap(): self
    {
        return new self('sitemap');
    }

    public static function flushSitemap(): bool
    {
        return self::sitemap()->flush();
    }

    public static function flushTwig(): bool
    {
        $twigCacheDir = storage_path('cache' . DIRECTORY_SEPARATOR . 'twig');
        if (!is_dir($twigCacheDir)) {
            return true;
        }

        try {
            $it = new \RecursiveDirectoryIterator($twigCacheDir, \FilesystemIterator::SKIP_DOTS);
            $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }
        } catch (\Throwable $e) {
            // Silently continue if permission or access restriction occurs
        }

        return true;
    }

    /**
     * Retrieve an item from cache.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            $this->lastHit = false;
            return $default;
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            $this->lastHit = false;
            return $default;
        }

        /** @var mixed $payload */
        $payload = json_decode($content, true);
        if (!is_array($payload) || !isset($payload['expires_at']) || !array_key_exists('data', $payload)) {
            @unlink($filePath);
            $this->lastHit = false;
            return $default;
        }

        $expiresAt = (int) $payload['expires_at'];
        if ($expiresAt !== 0 && time() > $expiresAt) {
            @unlink($filePath);
            $this->lastHit = false;
            return $default;
        }

        $this->lastHit = true;
        return $payload['data'];
    }

    /**
     * Check if last get() operation was a cache hit.
     */
    public function isHit(): bool
    {
        return $this->lastHit;
    }

    /**
     * Store an item in the cache.
     * 
     * @param int $ttl Seconds until expiration (default: 1800s = 30min)
     */
    public function set(string $key, mixed $value, int $ttl = 1800): bool
    {
        $filePath = $this->getFilePath($key);
        $payload = [
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
            'created_at' => time(),
            'data' => $value,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return (bool) @file_put_contents($filePath, $json, LOCK_EX);
    }

    /**
     * Check if key exists and has not expired.
     */
    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    /**
     * Delete an item from cache.
     */
    public function delete(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        if (file_exists($filePath)) {
            return @unlink($filePath);
        }

        return true;
    }

    /**
     * Flush all cache items in this directory.
     */
    public function flush(): bool
    {
        if (!is_dir($this->cacheDir)) {
            return true;
        }

        $files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache');
        if ($files === false) {
            return true;
        }

        $success = true;
        foreach ($files as $file) {
            if (is_file($file)) {
                $deleted = @unlink($file);
                if (!$deleted) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    /**
     * Get or compute cached value.
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $cached = $this->get($key);
        if ($this->isHit()) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    private function getFilePath(string $key): string
    {
        $safeHash = hash('sha256', $key);
        return $this->cacheDir . DIRECTORY_SEPARATOR . $safeHash . '.cache';
    }
}
