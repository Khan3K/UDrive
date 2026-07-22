<?php
namespace UDrive\Config;

use UDrive\Database\Database;

class ConfigHelper {
    private static ?array $cache = null;

    public static function get(string $key, $default = null) {
        if (self::$cache === null) {
            self::loadCache();
        }
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $config = require __DIR__ . '/../../config.php';
        $parts = explode('.', $key);
        $val = $config;
        foreach ($parts as $part) {
            if (!isset($val[$part])) return $default;
            $val = $val[$part];
        }
        return $val ?? $default;
    }

    public static function set(string $key, string $value): void {
        Database::query(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [$key, $value]
        );
        self::$cache[$key] = $value;
    }

    public static function setMultiple(array $pairs): void {
        foreach ($pairs as $key => $value) {
            self::set($key, $value);
        }
    }

    public static function getAll(): array {
        if (self::$cache === null) {
            self::loadCache();
        }
        $rows = Database::fetchAll("SELECT setting_key, setting_value FROM settings");
        $result = [];
        foreach ($rows as $row) {
            $result[$row['setting_key']] = $row['setting_value'];
        }
        return $result;
    }

    private static function loadCache(): void {
        self::$cache = [];
        try {
            $rows = Database::fetchAll("SELECT setting_key, setting_value FROM settings");
            foreach ($rows as $row) {
                self::$cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (\Exception $e) {
            self::$cache = [];
        }
    }
}
