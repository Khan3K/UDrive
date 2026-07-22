<?php
namespace UDrive\Providers;

class Dropbox implements ProviderInterface {
    public function getProviderName(): string { return 'dropbox'; }

    public function getAuthUrl(string $redirectUri): string {
        $clientId = \UDrive\Config\ConfigHelper::get('dropbox.client_id', '');
        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'token_access_type' => 'offline',
        ]);
        return "https://www.dropbox.com/oauth2/authorize?{$params}";
    }

    public function handleCallback(array $params): array {
        if (!isset($params['code'])) {
            return ['ok' => false, 'error' => 'No authorization code'];
        }
        $clientId = \UDrive\Config\ConfigHelper::get('dropbox.client_id', '');
        $clientSecret = \UDrive\Config\ConfigHelper::get('dropbox.client_secret', '');
        $ch = curl_init('https://api.dropboxapi.com/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $params['code'],
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $params['redirect_uri'] ?? '',
            ]),
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['access_token'])) {
            $token = [
                'access_token' => $response['access_token'],
                'refresh_token' => $response['refresh_token'] ?? '',
            ];
            $info = $this->getStorageInfo($token);
            return [
                'ok' => true,
                'credentials' => json_encode($token),
                'storage_total' => $info['total'],
                'storage_used' => $info['used'],
                'drive_name' => 'Dropbox',
            ];
        }
        return ['ok' => false, 'error' => $response['error_description'] ?? 'Token exchange failed'];
    }

    private function apiCall(string $endpoint, $credentials, array $args = []) {
        $token = $credentials;
        if (is_string($credentials)) {
            $token = json_decode($credentials, true);
        }
        if (!$token) return null;
        $ch = curl_init("https://api.dropboxapi.com/2/{$endpoint}");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token['access_token'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($args),
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $result = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $result;
    }

    private function getContentDownload(string $path, $credentials) {
        $token = $credentials;
        if (is_string($credentials)) {
            $token = json_decode($credentials, true);
        }
        if (!$token) return null;
        $tempPath = tempnam(sys_get_temp_dir(), 'db_dl_');
        $fp = fopen($tempPath, 'w');
        $ch = curl_init('https://content.dropboxapi.com/2/files/download');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token['access_token'],
                'Content-Type: application/octet-stream',
                'Dropbox-API-Arg: ' . json_encode(['path' => $path]),
            ],
            CURLOPT_FILE => $fp,
        ]);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        return fopen($tempPath, 'rb');
    }

    public function testConnection($credentials): bool {
        $result = $this->apiCall('users/get_current_account', $credentials);
        return isset($result['account_id']);
    }

    public function getStorageInfo($credentials): array {
        $result = $this->apiCall('users/get_space_usage', $credentials);
        $used = (int) ($result['used'] ?? 0);
        $allocated = $result['allocation'] ?? [];
        $total = (int) ($allocated['allocated'] ?? 2147483648);
        return ['total' => $total, 'used' => $used, 'free' => $total - $used];
    }

    public function listFiles($credentials, string $parentId = null): array {
        $path = $parentId ?: '';
        $result = $this->apiCall('files/list_folder', $credentials, [
            'path' => $path,
            'include_deleted' => false,
        ]);
        if (!isset($result['entries'])) return [];
        $files = [];
        foreach ($result['entries'] as $entry) {
            $isFolder = $entry['.tag'] === 'folder';
            $files[] = [
                'remote_id' => $entry['id'],
                'name' => $entry['name'],
                'mime_type' => $isFolder ? 'folder' : 'application/octet-stream',
                'size' => $isFolder ? 0 : (int) ($entry['size'] ?? 0),
                'is_folder' => $isFolder,
                'created_at' => $entry['server_modified'] ?? date('Y-m-d H:i:s'),
                'modified_at' => $entry['server_modified'] ?? date('Y-m-d H:i:s'),
            ];
        }
        return $files;
    }

    public function getFile($credentials, string $fileId): array {
        $result = $this->apiCall('files/get_metadata', $credentials, ['path' => $fileId]);
        if (!isset($result['id'])) return ['error' => 'Not found'];
        $isFolder = $result['.tag'] === 'folder';
        return [
            'remote_id' => $result['id'],
            'name' => $result['name'],
            'mime_type' => $isFolder ? 'folder' : 'application/octet-stream',
            'size' => $isFolder ? 0 : (int) ($result['size'] ?? 0),
            'is_folder' => $isFolder,
        ];
    }

    public function createFolder($credentials, string $parentId, string $name): array {
        $path = ($parentId ?: '') . "/{$name}";
        $result = $this->apiCall('files/create_folder_v2', $credentials, ['path' => $path]);
        $meta = $result['metadata'] ?? [];
        return [
            'remote_id' => $meta['id'] ?? '',
            'name' => $meta['name'] ?? $name,
            'mime_type' => 'folder',
        ];
    }

    public function uploadFile($credentials, string $parentId, $data, string $name, string $mime): array {
        $path = ($parentId ?: '') . "/{$name}";
        $token = $credentials;
        if (is_string($credentials)) {
            $token = json_decode($credentials, true);
        }
        $ch = curl_init('https://content.dropboxapi.com/2/files/upload');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token['access_token'],
                'Content-Type: application/octet-stream',
                'Dropbox-API-Arg: ' . json_encode(['path' => $path, 'mode' => 'add']),
            ],
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $result = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return [
            'remote_id' => $result['id'] ?? '',
            'name' => $result['name'] ?? $name,
            'mime_type' => $mime,
            'size' => (int) ($result['size'] ?? 0),
        ];
    }

    public function downloadStream($credentials, string $fileId) {
        return $this->getContentDownload($fileId, $credentials);
    }

    public function deleteFile($credentials, string $fileId): bool {
        $result = $this->apiCall('files/delete_v2', $credentials, ['path' => $fileId]);
        return isset($result['metadata']);
    }

    public function renameFile($credentials, string $fileId, string $newName): bool {
        $file = $this->getFile($credentials, $fileId);
        $oldPath = $fileId;
        $parts = explode('/', $oldPath);
        $parts[count($parts) - 1] = $newName;
        $newPath = implode('/', $parts);
        $result = $this->apiCall('files/move_v2', $credentials, [
            'from_path' => $oldPath,
            'to_path' => $newPath,
        ]);
        return isset($result['metadata']);
    }

    public function moveFile($credentials, string $fileId, string $newParentId): bool {
        $file = $this->getFile($credentials, $fileId);
        $newPath = $newParentId . '/' . $file['name'];
        $result = $this->apiCall('files/move_v2', $credentials, [
            'from_path' => $fileId,
            'to_path' => $newPath,
        ]);
        return isset($result['metadata']);
    }

    public function copyFile($credentials, string $fileId, string $newParentId): array {
        $file = $this->getFile($credentials, $fileId);
        $newPath = $newParentId . '/' . $file['name'];
        $result = $this->apiCall('files/copy_v2', $credentials, [
            'from_path' => $fileId,
            'to_path' => $newPath,
        ]);
        $meta = $result['metadata'] ?? [];
        return ['remote_id' => $meta['id'] ?? '', 'name' => $meta['name'] ?? $file['name']];
    }
}
