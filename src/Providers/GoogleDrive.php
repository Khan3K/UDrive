<?php
namespace UDrive\Providers;

use Google\Client;
use Google\Service\Drive as DriveService;

class GoogleDrive implements ProviderInterface {
    private function getClient($credentials = []): Client {
        if (is_string($credentials)) {
            $credentials = json_decode($credentials, true);
        }
        $clientId = \UDrive\Config\ConfigHelper::get('google_drive.client_id', '');
        $clientSecret = \UDrive\Config\ConfigHelper::get('google_drive.client_secret', '');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = rtrim(preg_replace('#/api/.*$#', '', $uri), '/');
        $redirectUri = $scheme . '://' . $host . $base . '/api/drives/callback?provider=google_drive';
        $client = new Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->addScope(DriveService::DRIVE);
        $client->setAccessType('offline');

        if (!empty($credentials['access_token'])) {
            $client->setAccessToken($credentials['access_token']);
            if (!empty($credentials['refresh_token'])) {
                $token = $client->getAccessToken();
                $token['refresh_token'] = $credentials['refresh_token'];
                $client->setAccessToken($token);
            }
            if ($client->isAccessTokenExpired() && $client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken();
            }
        }
        return $client;
    }

    private function getService($credentials = []): DriveService {
        return new DriveService($this->getClient($credentials));
    }

    public function getProviderName(): string { return 'google_drive'; }

    public function getAuthUrl(string $redirectUri): string {
        $clientId = \UDrive\Config\ConfigHelper::get('google_drive.client_id', '');
        $clientSecret = \UDrive\Config\ConfigHelper::get('google_drive.client_secret', '');
        $client = new Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->addScope(DriveService::DRIVE);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        return $client->createAuthUrl();
    }

    public function handleCallback(array $params): array {
        $clientId = \UDrive\Config\ConfigHelper::get('google_drive.client_id', '');
        $clientSecret = \UDrive\Config\ConfigHelper::get('google_drive.client_secret', '');
        $redirectUri = $params['redirect_uri'] ?? '';
        $client = new Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->addScope(DriveService::DRIVE);
        $client->setAccessType('offline');

        if (isset($params['code'])) {
            $token = $client->fetchAccessTokenWithAuthCode($params['code']);
            if (isset($token['error'])) {
                return ['ok' => false, 'error' => $token['error_description'] ?? $token['error']];
            }
            $service = new DriveService($client);
            $about = $service->about->get('user');
            return [
                'ok' => true,
                'credentials' => json_encode($token),
                'storage_total' => (int) ($about->getStorageQuota()->getLimit() ?? 0),
                'storage_used' => (int) $about->getStorageQuota()->getUsage(),
                'drive_name' => $about->getUser()->getDisplayName() ?? 'Google Drive',
            ];
        }
        return ['ok' => false, 'error' => 'No authorization code received'];
    }

    public function testConnection($credentials): bool {
        try {
            $service = $this->getService($credentials);
            $service->about->get('user');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getStorageInfo($credentials): array {
        $service = $this->getService($credentials);
        $about = $service->about->get('user');
        $quota = $about->getStorageQuota();
        return [
            'total' => (int) ($quota->getLimit() ?? 0),
            'used' => (int) $quota->getUsage(),
            'free' => (int) (($quota->getLimit() ?? 0) - $quota->getUsage()),
        ];
    }

    public function listFiles($credentials, string $parentId = null): array {
        $service = $this->getService($credentials);
        $query = "trashed = false";
        if ($parentId) {
            $query .= " and '{$parentId}' in parents";
        } else {
            $query .= " and 'root' in parents";
        }
        $results = $service->files->listFiles([
            'q' => $query,
            'fields' => 'files(id, name, mimeType, size, createdTime, modifiedTime, parents)',
            'orderBy' => 'folder, name',
            'pageSize' => 1000,
        ]);
        $files = [];
        foreach ($results->getFiles() as $file) {
            $files[] = [
                'remote_id' => $file->getId(),
                'name' => $file->getName(),
                'mime_type' => $file->getMimeType(),
                'size' => (int) ($file->getSize() ?? 0),
                'is_folder' => $file->getMimeType() === 'application/vnd.google-apps.folder',
                'created_at' => $file->getCreatedTime()->format('Y-m-d H:i:s'),
                'modified_at' => $file->getModifiedTime()->format('Y-m-d H:i:s'),
                'parent_remote_id' => $parentId,
            ];
        }
        return $files;
    }

    public function getFile($credentials, string $fileId): array {
        $service = $this->getService($credentials);
        $file = $service->files->get($fileId, [
            'fields' => 'id, name, mimeType, size, createdTime, modifiedTime, parents',
        ]);
        return [
            'remote_id' => $file->getId(),
            'name' => $file->getName(),
            'mime_type' => $file->getMimeType(),
            'size' => (int) ($file->getSize() ?? 0),
            'is_folder' => $file->getMimeType() === 'application/vnd.google-apps.folder',
            'created_at' => $file->getCreatedTime()->format('Y-m-d H:i:s'),
            'modified_at' => $file->getModifiedTime()->format('Y-m-d H:i:s'),
        ];
    }

    public function createFolder($credentials, string $parentId, string $name): array {
        $service = $this->getService($credentials);
        $meta = new \Google\Service\Drive\DriveFile([
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parentId],
        ]);
        $folder = $service->files->create($meta, ['fields' => 'id, name, mimeType, createdTime']);
        return [
            'remote_id' => $folder->getId(),
            'name' => $folder->getName(),
            'mime_type' => $folder->getMimeType(),
            'created_at' => $folder->getCreatedTime()->format('Y-m-d H:i:s'),
        ];
    }

    public function uploadFile($credentials, string $parentId, $data, string $name, string $mime): array {
        $service = $this->getService($credentials);
        $meta = new \Google\Service\Drive\DriveFile([
            'name' => $name,
            'parents' => [$parentId],
        ]);
        $file = $service->files->create($meta, [
            'data' => $data,
            'mimeType' => $mime,
            'uploadType' => 'multipart',
            'fields' => 'id, name, mimeType, size, createdTime',
        ]);
        return [
            'remote_id' => $file->getId(),
            'name' => $file->getName(),
            'mime_type' => $file->getMimeType(),
            'size' => (int) ($file->getSize() ?? 0),
            'created_at' => $file->getCreatedTime()->format('Y-m-d H:i:s'),
        ];
    }

    public function downloadStream($credentials, string $fileId) {
        $service = $this->getService($credentials);
        $response = $service->files->get($fileId, ['alt' => 'media']);
        return $response->getBody()->detach();
    }

    public function deleteFile($credentials, string $fileId): bool {
        $service = $this->getService($credentials);
        $service->files->delete($fileId);
        return true;
    }

    public function renameFile($credentials, string $fileId, string $newName): bool {
        $service = $this->getService($credentials);
        $file = new \Google\Service\Drive\DriveFile(['name' => $newName]);
        $service->files->update($fileId, $file, ['fields' => 'id']);
        return true;
    }

    public function moveFile($credentials, string $fileId, string $newParentId): bool {
        $service = $this->getService($credentials);
        $fileMetadata = new \Google\Service\Drive\DriveFile();
        $service->files->update($fileId, $fileMetadata, [
            'addParents' => $newParentId,
            'fields' => 'id, parents',
        ]);
        return true;
    }

    public function copyFile($credentials, string $fileId, string $newParentId): array {
        $service = $this->getService($credentials);
        $copy = new \Google\Service\Drive\DriveFile(['parents' => [$newParentId]]);
        $file = $service->files->copy($fileId, $copy, ['fields' => 'id, name, mimeType, size, createdTime']);
        return [
            'remote_id' => $file->getId(),
            'name' => $file->getName(),
            'mime_type' => $file->getMimeType(),
            'size' => (int) ($file->getSize() ?? 0),
        ];
    }
}
