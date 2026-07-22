<?php
/**
 * UDrive Setup Script
 * Run once: http://localhost/UDrive/setup.php
 */
$config = require __DIR__ . '/config.php';

echo "<h2>UDrive Setup</h2>";

// Test DB connection
try {
    $pdo = new PDO(
        "mysql:host={$config['database']['host']};port={$config['database']['port']}",
        $config['database']['username'],
        $config['database']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "<p>✅ MySQL connection OK</p>";

    // Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['database']['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$config['database']['database']}`");
    echo "<p>✅ Database ready</p>";

    // Run migrations via Database class
    require_once __DIR__ . '/vendor/autoload.php';
    UDrive\Database\Database::migrate();
    echo "<p>✅ Tables created/migrated</p>";

    echo "<h3>Setup complete!</h3>";
    echo "<p><a href='index.html'>Go to UDrive</a></p>";

} catch (PDOException $e) {
    echo "<p>❌ Database error: " . $e->getMessage() . "</p>";
    echo "<p>Make sure MySQL is running and credentials in config.php are correct.</p>";
}
