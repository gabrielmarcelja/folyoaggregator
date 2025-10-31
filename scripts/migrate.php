#!/usr/bin/env php
<?php
/**
 * Database Migration Script for FolyoAggregator
 * Executes all SQL migration files in order
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load environment variables
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Load .env file
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// Database configuration
$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? 3306;
$dbname = $_ENV['DB_NAME'] ?? 'folyoaggregator';
$username = $_ENV['DB_USER'] ?? 'root';
$password = $_ENV['DB_PASS'] ?? '';
$charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

echo "===========================================\n";
echo "FolyoAggregator Database Migration Script\n";
echo "===========================================\n\n";

try {
    // Connect to database
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "✓ Connected to database: $dbname\n\n";

    // Create migrations tracking table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS migrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) UNIQUE NOT NULL,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Get list of executed migrations
    $stmt = $pdo->query("SELECT filename FROM migrations");
    $executedMigrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get migration files
    $migrationsDir = dirname(__DIR__) . '/database/migrations';
    $migrationFiles = glob($migrationsDir . '/*.sql');
    sort($migrationFiles); // Ensure they run in order

    $migrationsRun = 0;
    $migrationsSkipped = 0;

    foreach ($migrationFiles as $file) {
        $filename = basename($file);

        // Check if migration was already executed
        if (in_array($filename, $executedMigrations)) {
            echo "⏭  Skipping: $filename (already executed)\n";
            $migrationsSkipped++;
            continue;
        }

        echo "🔄 Executing: $filename\n";

        try {
            // Read and execute migration file
            $sql = file_get_contents($file);

            // Split by delimiter if needed (for stored procedures, events, etc.)
            if (strpos($sql, 'DELIMITER') !== false) {
                // Handle custom delimiters
                preg_match_all('/DELIMITER\s+(.*?)\n(.*?)DELIMITER\s*;/s', $sql, $matches);
                if (!empty($matches[2])) {
                    foreach ($matches[2] as $block) {
                        $pdo->exec(trim($block));
                    }
                }
                // Execute remaining SQL after removing delimiter blocks
                $sql = preg_replace('/DELIMITER\s+(.*?)\n(.*?)DELIMITER\s*;/s', '', $sql);
            }

            // Execute the migration
            if (!empty(trim($sql))) {
                // Split by semicolon but not within strings
                $statements = preg_split('/;(?=(?:[^\"]*\"[^\"]*\")*[^\"]*$)/', $sql);

                foreach ($statements as $statement) {
                    $statement = trim($statement);
                    if (!empty($statement)) {
                        $pdo->exec($statement);
                    }
                }
            }

            // Record migration as executed
            $stmt = $pdo->prepare("INSERT INTO migrations (filename) VALUES (?)");
            $stmt->execute([$filename]);

            echo "✅ Success: $filename\n\n";
            $migrationsRun++;

        } catch (Exception $e) {
            echo "❌ Error in $filename: " . $e->getMessage() . "\n";
            echo "Migration stopped. Please fix the error and run again.\n";
            exit(1);
        }
    }

    echo "===========================================\n";
    echo "Migration Summary:\n";
    echo "- Migrations executed: $migrationsRun\n";
    echo "- Migrations skipped: $migrationsSkipped\n";
    echo "- Total migrations: " . count($migrationFiles) . "\n";
    echo "===========================================\n\n";

    if ($migrationsRun > 0) {
        echo "✅ Database migration completed successfully!\n";
    } else {
        echo "ℹ️  All migrations were already up to date.\n";
    }

} catch (PDOException $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "\n";
    echo "Please check your database configuration in .env file.\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";