<?php
namespace UDrive\Encryption;

class KeyDeriver {
    private string $cipher;
    private int $iterations;

    public function __construct() {
        $config = require __DIR__ . '/../../config.php';
        $this->cipher = $config['encryption']['cipher'];
        $this->iterations = $config['encryption']['pbkdf2_iterations'];
    }

    public function generateSalt(): string {
        return bin2hex(random_bytes(32));
    }

    public function deriveKey(string $password, string $salt): string {
        return hash_pbkdf2('sha256', $password, hex2bin($salt), $this->iterations, 32, true);
    }

    public function deriveKeyHex(string $password, string $salt): string {
        return bin2hex($this->deriveKey($password, $salt));
    }

    public function createEncryptedKey(string $password): array {
        $salt = $this->generateSalt();
        $key = $this->deriveKey($password, $salt);
        return ['key' => $key, 'salt' => $salt];
    }

    public function verifyPassword(string $password, string $storedSalt): bool {
        $key = $this->deriveKey($password, $storedSalt);
        return strlen($key) === 32;
    }
}
