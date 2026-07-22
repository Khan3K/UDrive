<?php
namespace UDrive\Database;

use PDO;
use PDOException;

class Database {
    private static ?PDO $pdo = null;

    public static function connect(): PDO {
        if (self::$pdo === null) {
            $config = require __DIR__ . '/../../config.php';
            $db = $config['db'];
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
            try {
                self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                throw new \RuntimeException("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$pdo;
    }

    public static function query(string $sql, array $params = []): \PDOStatement {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array {
        $row = self::query($sql, $params)->fetch();
        return $row ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    public static function insert(string $table, array $data): int {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        self::query($sql, array_values($data));
        return (int) self::connect()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $set = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($data)));
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
        $stmt = self::query($sql, array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }

    public static function migrate(): void {
        $pdo = self::connect();

        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            encryption_mode TINYINT(1) DEFAULT 0,
            encryption_salt VARCHAR(64) DEFAULT NULL,
            encryption_verify VARCHAR(255) DEFAULT NULL,
            avatar_color VARCHAR(7) DEFAULT '#4A90D9',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS drives (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            provider VARCHAR(30) NOT NULL,
            name VARCHAR(100) NOT NULL,
            credentials TEXT NOT NULL,
            storage_total BIGINT DEFAULT 0,
            storage_used BIGINT DEFAULT 0,
            root_folder_id VARCHAR(255) DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            last_synced DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            drive_id INT NOT NULL,
            parent_id INT DEFAULT NULL,
            remote_id VARCHAR(255) NOT NULL,
            name VARCHAR(500) NOT NULL,
            mime_type VARCHAR(255) DEFAULT 'application/octet-stream',
            size BIGINT DEFAULT 0,
            is_folder TINYINT(1) DEFAULT 0,
            is_encrypted TINYINT(1) DEFAULT 0,
            encryption_iv VARCHAR(64) DEFAULT NULL,
            encryption_tag VARCHAR(64) DEFAULT NULL,
            split_manifest TEXT DEFAULT NULL,
            starred TINYINT(1) DEFAULT 0,
            trashed TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (drive_id) REFERENCES drives(id) ON DELETE CASCADE,
            INDEX idx_files_user (user_id),
            INDEX idx_files_parent (parent_id),
            INDEX idx_files_drive (drive_id),
            INDEX idx_files_trashed (user_id, trashed)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS file_chunks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_id INT NOT NULL,
            chunk_index INT NOT NULL,
            chunk_size BIGINT NOT NULL,
            drive_id INT NOT NULL,
            remote_id VARCHAR(255) NOT NULL,
            remote_path VARCHAR(500) DEFAULT NULL,
            checksum VARCHAR(64) DEFAULT NULL,
            FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
            FOREIGN KEY (drive_id) REFERENCES drives(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
            id VARCHAR(64) PRIMARY KEY,
            user_id INT NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            encryption_key VARCHAR(128) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_sessions_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS transfer_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            file_id INT NOT NULL,
            source_drive_id INT NOT NULL,
            target_drive_id INT NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            progress INT DEFAULT 0,
            error_message TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (source_drive_id) REFERENCES drives(id) ON DELETE CASCADE,
            FOREIGN KEY (target_drive_id) REFERENCES drives(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_admin'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) DEFAULT 0 AFTER avatar_color");
            $pdo->exec("UPDATE users SET is_admin = 1 WHERE username = 'admin'");
        }
    }
}
