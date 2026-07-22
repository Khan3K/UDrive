<?php
namespace UDrive\Providers;

class OneDrive implements ProviderInterface {
    public function getProviderName(): string { return 'onedrive'; }

    public function getAuthUrl(string $redirectUri): string {
        $clientId = \UDrive\Config\ConfigHelper::get('onedrive.client_id', '');
        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'files.ReadWrite offline_access',
        ]);
        return "https://login.microsoftonline.com/common/oauth2/v2.0/authorize?{$params}";
    }

    public function handleCallback(array $params): array {
        if (!isset($params['code'])) {
            return ['ok' => false, 'error' => 'No authorization code'];
        }
        $clientId = \UDrive\Config\ConfigHelper::get('onedrive.client_id', '');
        $clientSecret = \UDrive\Config\ConfigHelper::get('onedrive.client_secret', '');
        $data = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $params['code'],
            'redirect_uri' => $params['redirect_uri'] ?? '',
            'grant_type' => 'authorization_code',
        ];
        $ch = curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
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
                'drive_name' => 'OneDrive',
            ];
        }
        return ['ok' => false, 'error' => $response['error_description'] ?? 'Token exchange failed'];
    }

    private function getHeaders($credentials): array {
        $token = $credentials;
        if (is_string($credentials)) {
            $token = json_decode($credentials, true);
        }
        if (!isset($token['access_token'])) return [];
        return ['Authorization: Bearer ' . $token['access_token']];
    }

    public function testConnection($credentials): bool {
        $headers = $this->getHeaders($credentials);
        if (!$headers) return false;
        $ch = curl_init('https://graph.microsoft.com/v1.0/me/drive');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($result, true);
        return isset($data['id']);
    }

    public function getStorageInfo($credentials): array {
        $headers = $this->getHeaders($credentials);
        if (!$headers) return ['total' => 0, 'used' => 0, 'free' => 0];
        $ch = curl_init('https://graph.microsoft.com/v1.0/me/drive');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $data = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $quota = $data['quota'] ?? [];
        $total = (int) ($quota['total'] ?? 0);
        $used = (int) ($quota['used'] ?? 0);
        return ['total' => $total, 'used' => $used, 'free' => $total - $used];
    }

    public function listFiles($credentials, string $parentId = null): array {
        $headers = $this->getHeaders($credentials);
        if (!$headers) return [];
        $path = $parentId ? "/drive/items/{$parentId}/children" : '/drive/root/children';
        $ch = curl_init("https://graph.microsoft.com/v1.0{$path}");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $data = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $files = [];
        foreach ($data['value'] ?? [] as $item) {
            $isFolder = isset($item['folder']);
            $files[] = [
                'remote_id' => $item['id'],
                'name' => $item['name'],
                'mime_type' => $isFolder ? 'folder' : ($item['file']['mimeType'] ?? 'application/octet-stream'),
                'size' => (int) ($item['size'] ?? 0),
                'is_folder' => $isFolder,
                'created_at' => $item['createdDateTime'] ?? date('Y-m-d H:i:s'),
                'modified_at' => $item['lastModifiedDateTime'] ?? date('Y-m-d H:i:s'),
            ];
        }
        return $files;
    }

    public function getFile($credentials, string $fileId): array {
        $headers = $this->getHeaders($credentials);
        if (!$headers) return [];
        $ch = curl_init("https://graph.microsoft.com/v1.0/drive/items/{$fileId}");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $item = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $isFolder = isset($item['folder']);
        return [
            'remote_id' => $item['id'],
            'name' => $item['name'],
            'mime_type' => $isFolder ? 'folder' : ($item['file']['mimeType'] ?? 'application/octet-stream'),
            'size' => (int) ($item['size'] ?? 0),
            'is_folder' => $isFolder,
        ];
    }

    public function createFolder($credentials, string $parentId, string $name): array {
        $headers = array_merge($this->getHeaders($credentials), ['Content-Type: application/json']);
        $body = json_encode(['name' => $name, 'folder' => (object)[]]);
        $path = $parentId ? "/drive/items/{$parentId}/children" : '/drive/root/children';
        $ch = curl_init("https://graph.microsoft.com/v1.0{$path}");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $item = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return [
            'remote_id' => $item['id'] ?? '',
            'name' => $item['name'] ?? $name,
            'mime_type' => 'folder',
        ];
    }

    public function uploadFile($credentials, string $parentId, $data, string $name, string $mime): array {
        $headers = $this->getHeaders($credentials);
        $path = $parentId ? "/drive/items/{$parentId}:/{$name}:/content" : "/drive/root:/{$name}:/content";
        $ch = curl_init("https://graph.microsoft.com/v1.0{$path}");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array_merge($headers, ["Content-Type: {$mime}"]),
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $item = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return [
            'remote_id' => $item['id'] ?? '',
            'name' => $item['name'] ?? $name,
            'mime_type' => $mime,
            'size' => (int) ($item['size'] ?? 0),
        ];
    }

    public function downloadStream($credentials, string $fileId) {
        $headers = $this->getHeaders($credentials);
        $tempPath = tempnam(sys_get_temp_dir(), 'od_dl_');
        $fp = fopen($tempPath, 'w');
        $ch = curl_init("https://graph.microsoft.com/v1.0/drive/items/{$fileId}/content");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        return fopen($tempPath, 'rb');
    }

    public function deleteFile($credentials, string $fileId): bool {
        $headers = $this->getHeaders($credentials);
        $ch = curl_init("https://graph.microsoft.com/v1.0/drive/items/{$fileId}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => $headers,
        ]);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $status == 204 || $status == 200;
    }

    public function renameFile($credentials, string $fileId, string $newName): bool {
        $headers = array_merge($this->getHeaders($credentials), ['Content-Type: application/json']);
        $ch = curl_init("https://graph.microsoft.com/v1.0/drive/items/{$fileId}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode(['name' => $newName]),
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $result = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return isset($result['id']);
    }

    public function moveFile($credentials, string $fileId, string $newParentId): bool {
        $headers = array_merge($this->getHeaders($credentials), ['Content-Type: application/json']);
        $ch = curl_init("https://graph.microsoft.com/v1.0/drive/items/{$fileId}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode(['parentReference' => ['id' => $newParentId]]),
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $result = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return isset($result['id']);
    }

    public function copyFile($credentials, string $fileId, string $newParentId): array {
        $headers = array_merge($this->getHeaders($credentials), ['Content-Type: application/json']);
        $ch = curl_init("https://graph.microsoft.com/v1.0/drive/items/{$fileId}/copy");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode(['parentReference' => ['id' => $newParentId]]),
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $item = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return ['remote_id' => $item['id'] ?? '', 'name' => $item['name'] ?? 'copy'];
    }
}
