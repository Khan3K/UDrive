<?php
namespace UDrive\Providers;

class Mega implements ProviderInterface {
    private const API_URL = 'https://g.api.mega.co.nz/cs';

    private function apiCall(array $commands, ?string $sessionId = null): array {
        $url = self::API_URL;
        if ($sessionId) {
            $url .= '?id=' . $sessionId;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($commands),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        if (!$response) return [];
        $result = json_decode($response, true);
        return is_array($result) ? $result : [];
    }

    private function getSessionFromCredentials($credentials): ?string {
        if (is_string($credentials)) {
            $decoded = json_decode($credentials, true);
            return $decoded['session_id'] ?? null;
        }
        if (is_array($credentials)) {
            if (isset($credentials['credentials'])) {
                $decoded = json_decode($credentials['credentials'], true);
                return $decoded['session_id'] ?? null;
            }
            return $credentials['session_id'] ?? null;
        }
        return null;
    }

    private function login(string $email, string $password): array {
        $encEmail = strtoupper(bin2hex($this->strToKey($email)));
        $encPass = $this->hashPassword($password);

        $response = $this->apiCall([
            ['a' => 'us', 'user' => $encEmail, 'uh' => bin2hex($encPass)]
        ]);

        if (empty($response) || !is_array($response)) {
            return ['ok' => false, 'error' => 'Invalid MEGA credentials'];
        }
        if (isset($response[0]['e'])) {
            return ['ok' => false, 'error' => 'MEGA login failed: ' . $response[0]['e']];
        }

        $sessionId = $response[0] ?? null;
        if (!$sessionId) {
            return ['ok' => false, 'error' => 'No session ID returned'];
        }
        return ['ok' => true, 'session_id' => $sessionId];
    }

    private function strToKey(string $data): string {
        $padded = $data . str_repeat("\0", (4 - (strlen($data) % 4)) % 4);
        $key = '';
        for ($i = 0; $i < strlen($padded); $i += 4) {
            $key .= pack('N', unpack('N', substr($padded, $i, 4))[1]);
        }
        return $key;
    }

    private function hashPassword(string $password): string {
        $hash = hash('sha1', $password, true);
        $key = str_repeat("\0", 16);
        for ($i = 0; $i < strlen($hash); $i++) {
            $key[$i % 16] = $key[$i % 16] ^ $hash[$i];
        }
        return $key;
    }

    public function getProviderName(): string { return 'mega'; }

    public function getAuthUrl(string $redirectUri): string {
        return $redirectUri . '&provider=mega';
    }

    public function handleCallback(array $params): array {
        if (isset($params['email'], $params['password'])) {
            return $this->testAndStore($params['email'], $params['password']);
        }
        return ['ok' => false, 'error' => 'Missing MEGA credentials'];
    }

    public function testConnection($credentials): bool {
        $sessionId = $this->getSessionFromCredentials($credentials);
        return !empty($sessionId);
    }

    public function getStorageInfo($credentials): array {
        $sessionId = $this->getSessionFromCredentials($credentials);
        if (!$sessionId) return ['total' => 0, 'used' => 0, 'free' => 0];

        $response = $this->apiCall([['a' => 'uq', 'xfer' => 1]], $sessionId);
        if (!empty($response[0]) && !isset($response[0]['e'])) {
            $c = $response[0];
            $total = (int) ($c['mstrg'] ?? 0);
            $used = (int) ($c['cstrg'] ?? 0);
            return ['total' => $total, 'used' => $used, 'free' => $total - $used];
        }
        return ['total' => 21474836480, 'used' => 0, 'free' => 21474836480];
    }

    public function listFiles($credentials, string $parentId = null): array {
        $sessionId = $this->getSessionFromCredentials($credentials);
        if (!$sessionId) return [];

        $response = $this->apiCall([
            ['a' => 'f', 'c' => 1, 'r' => 1]
        ], $sessionId);

        if (empty($response[0]['f'])) return [];

        $files = [];
        $rootId = $response[0]['h'] ?? '';
        $targetParent = $parentId ?: $rootId;

        foreach ($response[0]['f'] as $node) {
            if ($node['p'] === $targetParent || ($targetParent === $rootId && ($node['p'] === '0' || $node['p'] === $rootId))) {
                $isFolder = ($node['t'] ?? 0) == 1;
                $attrs = $this->safeDecodeAttributes($node['a'] ?? '');
                $files[] = [
                    'remote_id' => $node['h'],
                    'name' => $attrs['n'] ?? 'unknown',
                    'mime_type' => $isFolder ? 'folder' : 'application/octet-stream',
                    'size' => $isFolder ? 0 : (int) ($node['s'] ?? 0),
                    'is_folder' => $isFolder,
                    'created_at' => date('Y-m-d H:i:s', (int) ($node['ts'] ?? time())),
                    'modified_at' => date('Y-m-d H:i:s', (int) ($node['ts'] ?? time())),
                    'parent_remote_id' => $node['p'],
                ];
            }
        }
        return $files;
    }

    private function safeDecodeAttributes(string $encAttributes): array {
        if (strlen($encAttributes) < 4) return ['name' => 'unknown'];
        $prefix = substr($encAttributes, 0, 4);
        if ($prefix !== 'MEGA') return ['name' => 'unknown'];
        $json = substr($encAttributes, 4);
        $attrs = @json_decode($json, true);
        return is_array($attrs) ? $attrs : ['name' => 'encrypted_file'];
    }

    public function getFile($credentials, string $fileId): array {
        $files = $this->listFiles($credentials);
        foreach ($files as $f) {
            if ($f['remote_id'] === $fileId) return $f;
        }
        return ['error' => 'File not found'];
    }

    public function createFolder($credentials, string $parentId, string $name): array {
        $sessionId = $this->getSessionFromCredentials($credentials);
        if (!$sessionId) return ['error' => 'No session'];

        $encName = 'MEGA' . json_encode(['n' => $name]);
        $response = $this->apiCall([
            ['a' => 'p', 't' => $parentId, 'n' => -1, 'a' => base64_encode($encName)]
        ], $sessionId);

        if (!empty($response[0]['h'])) {
            return [
                'remote_id' => $response[0]['h'],
                'name' => $name,
                'mime_type' => 'folder',
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }
        return ['error' => 'Failed to create folder'];
    }

    public function uploadFile($credentials, string $parentId, $data, string $name, string $mime): array {
        return ['error' => 'MEGA upload requires complex encryption. Use the transfer feature.'];
    }

    public function downloadStream($credentials, string $fileId) {
        $sessionId = $this->getSessionFromCredentials($credentials);
        if (!$sessionId) return null;

        $response = $this->apiCall([
            ['a' => 'g', 'g' => 1, 'n' => $fileId]
        ], $sessionId);

        if (!empty($response[0]['g'])) {
            $url = $response[0]['g'];
            $tempPath = tempnam(sys_get_temp_dir(), 'mega_dl_');
            $fp = fopen($tempPath, 'w');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_exec($ch);
            curl_close($ch);
            fclose($fp);
            return fopen($tempPath, 'rb');
        }
        return null;
    }

    public function deleteFile($credentials, string $fileId): bool {
        $sessionId = $this->getSessionFromCredentials($credentials);
        if (!$sessionId) return false;

        $response = $this->apiCall([
            ['a' => 'd', 'n' => $fileId]
        ], $sessionId);

        return empty($response[0]['e']);
    }

    public function renameFile($credentials, string $fileId, string $newName): bool {
        $sessionId = $this->getSessionFromCredentials($credentials);
        if (!$sessionId) return false;

        $encName = 'MEGA' . json_encode(['n' => $newName]);
        $response = $this->apiCall([
            ['a' => 'a', 'n' => $fileId, 'attr' => base64_encode($encName)]
        ], $sessionId);

        return empty($response[0]['e']);
    }

    public function moveFile($credentials, string $fileId, string $newParentId): bool {
        $sessionId = $this->getSessionFromCredentials($credentials);
        if (!$sessionId) return false;

        $response = $this->apiCall([
            ['a' => 'm', 'n' => $fileId, 'p' => $newParentId]
        ], $sessionId);

        return empty($response[0]['e']);
    }

    public function copyFile($credentials, string $fileId, string $newParentId): array {
        $sessionId = $this->getSessionFromCredentials($credentials);
        if (!$sessionId) return ['error' => 'No session'];

        $response = $this->apiCall([
            ['a' => 'c', 'n' => $fileId, 'p' => $newParentId]
        ], $sessionId);

        if (!empty($response[0]['h'])) {
            return [
                'remote_id' => $response[0]['h'],
                'name' => 'copied_file',
                'mime_type' => 'application/octet-stream',
            ];
        }
        return ['error' => 'Copy failed'];
    }

    public function testAndStore(string $email, string $password): array {
        $result = $this->login($email, $password);
        if (!$result['ok']) return $result;

        $sessionId = $result['session_id'];
        $storage = $this->getStorageInfo(['session_id' => $sessionId]);

        return [
            'ok' => true,
            'credentials' => json_encode(['session_id' => $sessionId, 'email' => $email]),
            'storage_total' => $storage['total'],
            'storage_used' => $storage['used'],
            'drive_name' => "MEGA ({$email})",
        ];
    }
}
