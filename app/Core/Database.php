<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

class Database
{
    private static ?self $instance = null;
    private PDO $connection;

    private function __construct()
    {
        $host = (string) config('database.host', '127.0.0.1');
        $port = (int) config('database.port', 3306);
        $dbname = (string) config('database.database', 'stationery_shop');
        $charset = (string) config('database.charset', 'utf8mb4');
        $username = (string) config('database.username', 'root');
        $password = (string) config('database.password', '');
        /** @var array<int, mixed> $options */
        $options = config('database.options', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if ($password === '[رمز_شما_در_پنل]') {
            throw new RuntimeException("لطفاً رمز عبور دیتابیس هاست (vPanel Password) را در فایل config/database.php یا متغیر DB_PROD_PASSWORD تنظیم نمایید.");
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        try {
            $this->connection = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new RuntimeException("Database Connection Error: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    private function __clone()
    {
    }

    public function __wakeup()
    {
        throw new RuntimeException("Cannot unserialize singleton Database instance.");
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    public function beginTransaction(): bool
    {
        return $this->connection->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->connection->commit();
    }

    public function rollBack(): bool
    {
        return $this->connection->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }

    public function prepare(string $query): PDOStatement
    {
        return $this->connection->prepare($query);
    }

    public function query(string $query): PDOStatement
    {
        return $this->connection->query($query);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->connection->lastInsertId($name);
    }

    /**
     * Helper to fetch a single row with prepared statement.
     * 
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * Helper to fetch all rows with prepared statement.
     * 
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        /** @var list<array<string, mixed>> $result */
        $result = $stmt->fetchAll();

        return $result;
    }

    /**
     * Helper to execute an insert/update/delete statement.
     * 
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute($params);
    }
}
