<?php
namespace UDrive\Auth;

use UDrive\Database\Database;

class Auth {
    public static function register(string $username, string $email, string $password): array {
        $username = trim($username);
        $email = trim(strtolower($email));

        if (strlen($username) < 3 || strlen($username) > 50) {
            return ['ok' => false, 'error' => 'Username must be 3-50 characters'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid email address'];
        }
        if (strlen($password) < 6) {
            return ['ok' => false, 'error' => 'Password must be at least 6 characters'];
        }

        $exists = Database::fetch("SELECT id FROM users WHERE username = ? OR email = ?", [$username, $email]);
        if ($exists) {
            return ['ok' => false, 'error' => 'Username or email already exists'];
        }

        $colors = ['#4A90D9','#E74C3C','#2ECC71','#F39C12','#9B59B6','#1ABC9C','#E67E22','#3498DB'];
        $id = Database::insert('users', [
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'avatar_color' => $colors[array_rand($colors)],
        ]);

        return ['ok' => true, 'user_id' => $id];
    }

    public static function login(string $username, string $password): array {
        $user = Database::fetch(
            "SELECT * FROM users WHERE username = ? OR email = ?",
            [trim($username), trim($username)]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['ok' => false, 'error' => 'Invalid username or password'];
        }

        Database::update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

        $sessionId = self::createSession($user['id']);

        return [
            'ok' => true,
            'session_id' => $sessionId,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'encryption_mode' => (int) $user['encryption_mode'],
                'avatar_color' => $user['avatar_color'],
                'is_admin' => (int) ($user['is_admin'] ?? 0),
            ],
        ];
    }

    public static function logout(): void {
        $sessionId = self::getSessionId();
        if ($sessionId) {
            Database::delete('sessions', 'id = ?', [$sessionId]);
        }
    }

    public static function check(): ?array {
        $sessionId = self::getSessionId();
        if (!$sessionId) return null;

        $session = Database::fetch(
            "SELECT s.*, u.id as uid, u.username, u.email, u.encryption_mode, u.avatar_color, u.is_admin
             FROM sessions s JOIN users u ON s.user_id = u.id
             WHERE s.id = ? AND s.expires_at > NOW()",
            [$sessionId]
        );

        if (!$session) return null;

        return [
            'id' => (int) $session['uid'],
            'username' => $session['username'],
            'email' => $session['email'],
            'encryption_mode' => (int) $session['encryption_mode'],
            'avatar_color' => $session['avatar_color'],
            'is_admin' => (int) ($session['is_admin'] ?? 0),
            'has_encryption_key' => !empty($session['encryption_key']),
        ];
    }

    public static function requireAuth(): array {
        $user = self::check();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Not authenticated']);
            exit;
        }
        return $user;
    }

    public static function requireAdmin(): array {
        $user = self::requireAuth();
        if (empty($user['is_admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            exit;
        }
        return $user;
    }

    public static function getUserId(): int {
        $user = self::requireAuth();
        return $user['id'];
    }

    public static function createSession(int $userId): string {
        $sessionId = bin2hex(random_bytes(32));
        $config = require __DIR__ . '/../../config.php';
        $expires = date('Y-m-d H:i:s', time() + $config['session']['lifetime']);

        Database::delete('sessions', 'user_id = ? OR expires_at < NOW()', [$userId]);

        Database::insert('sessions', [
            'id' => $sessionId,
            'user_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'expires_at' => $expires,
        ]);

        self::startSession($config);
        $_SESSION['udrive_session'] = $sessionId;
        session_write_close();

        return $sessionId;
    }

    public static function getSessionId(): ?string {
        $config = require __DIR__ . '/../../config.php';
        self::startSession($config);
        $id = $_SESSION['udrive_session'] ?? null;
        session_write_close();
        return $id;
    }

    private static function startSession(array $config): void {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        @session_name($config['session']['name']);
        @session_set_cookie_params([
            'lifetime' => $config['session']['lifetime'],
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @session_start();
    }

    public static function setEncryptionKey(string $password, int $userId): bool {
        $user = Database::fetch("SELECT encryption_salt, encryption_verify FROM users WHERE id = ?", [$userId]);
        if (!$user) return false;

        $salt = $user['encryption_salt'];
        if (!$salt) {
            $salt = bin2hex(random_bytes(32));
            $verifyHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            Database::update('users', ['encryption_salt' => $salt, 'encryption_verify' => $verifyHash], 'id = ?', [$userId]);
        } else {
            if (!empty($user['encryption_verify']) && !password_verify($password, $user['encryption_verify'])) {
                return false;
            }
        }

        $key = hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
        $keyHex = bin2hex($key);

        $sessionId = self::getSessionId();
        if ($sessionId) {
            Database::update('sessions', ['encryption_key' => $keyHex], 'id = ?', [$sessionId]);
        }

        return true;
    }

    public static function getEncryptionKey(int $userId): ?string {
        $sessionId = self::getSessionId();
        if (!$sessionId) return null;

        $session = Database::fetch(
            "SELECT encryption_key FROM sessions WHERE id = ? AND user_id = ? AND expires_at > NOW()",
            [$sessionId, $userId]
        );

        if (!$session || empty($session['encryption_key'])) return null;

        return hex2bin($session['encryption_key']);
    }

    public static function clearEncryptionKey(): void {
        $sessionId = self::getSessionId();
        if ($sessionId) {
            Database::update('sessions', ['encryption_key' => null], 'id = ?', [$sessionId]);
        }
    }

    public static function verifyEncryptionPassword(string $password, int $userId): bool {
        $user = Database::fetch("SELECT encryption_salt, encryption_verify FROM users WHERE id = ?", [$userId]);
        if (!$user || !$user['encryption_salt']) return false;
        if (empty($user['encryption_verify'])) return true;
        return password_verify($password, $user['encryption_verify']);
    }

    public static function toggleEncryptionMode(int $userId, bool $enable): void {
        Database::update('users', ['encryption_mode' => $enable ? 1 : 0], 'id = ?', [$userId]);
    }
}
