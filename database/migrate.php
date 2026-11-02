<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '3306';
$database = $_ENV['DB_DATABASE'] ?? 'stationery_shop';
$username = $_ENV['DB_USERNAME'] ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';
$charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

echo "========================================================\n";
echo " Database Migration Runner\n";
echo " Host: {$host}:{$port} | Target Database: {$database}\n";
echo "========================================================\n\n";

try {
    // 1. Connect to MySQL server without database selected
    $dsnNoDb = "mysql:host={$host};port={$port};charset={$charset}";
    $pdo = new PDO($dsnNoDb, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 2. Read schema.sql
    $schemaFile = __DIR__ . DIRECTORY_SEPARATOR . 'schema.sql';
    if (!file_exists($schemaFile)) {
        throw new RuntimeException("Schema file not found at: {$schemaFile}");
    }

    $sql = file_get_contents($schemaFile);
    if ($sql === false) {
        throw new RuntimeException("Failed to read schema file.");
    }

    echo "[1/2] Executing schema.sql statements...\n";
    $pdo->exec($sql);
    echo "       ✓ Schema executed successfully!\n";

    // 3. Verify created tables
    $pdo->exec("USE `{$database}`");
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    echo "[2/2] Verifying generated tables...\n";
    foreach ($tables as $index => $table) {
        $num = $index + 1;
        echo "       {$num}. {$table}\n";
    }

    echo "\n Migration completed successfully! Total tables created: " . count($tables) . "\n";
    exit(0);
} catch (Throwable $e) {
    echo "\n Migration Failed: " . $e->getMessage() . "\n";
    echo " File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
    exit(1);
}
