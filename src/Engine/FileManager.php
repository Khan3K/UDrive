<?php
namespace UDrive\Engine;

use UDrive\Database\Database;
use UDrive\Providers\ProviderFactory;

class FileManager {
    private int $userId;

    public function __construct(int $userId) {
        $this->userId = $userId;
    }

    public function searchFiles(string $query): array {
        if (strlen($query) < 1) return [];
        $term = '%' . $query . '%';
        return Database::fetchAll(
            "SELECT f.*, d.name as drive_name, d.provider as drive_provider
             FROM files f
             LEFT JOIN drives d ON f.drive_id = d.id
             WHERE f.user_id = ? AND f.name LIKE ? AND f.trashed = 0
             ORDER BY f.is_folder DESC, f.name ASC
             LIMIT 50",
            [$this->userId, $term]
        );
    }

    public function listFiles(?int $parentId = null, bool $showTrashed = false): array {
        $params = [$this->userId];
        $where = "f.user_id = ?";

        if ($parentId !== null) {
            $where .= " AND f.parent_id = ?";
            $params[] = $parentId;
        } else {
            $where .= " AND f.parent_id IS NULL";
        }

        if (!$showTrashed) {
            $where .= " AND f.trashed = 0";
        }

        $files = Database::fetchAll(
            "SELECT f.*, d.name as drive_name, d.provider as drive_provider
             FROM files f
             LEFT JOIN drives d ON f.drive_id = d.id
             WHERE {$where}
             ORDER BY f.is_folder DESC, f.name ASC",
            $params
        );

        foreach ($files as &$file) {
            if ($file['is_encrypted'] && $file['encryption_iv']) {
                $file['name_encrypted'] = true;
            } else {
                $file['name_encrypted'] = false;
            }
        }

        return $files;
    }

    public function getFile(int $fileId): ?array {
        $file = Database::fetch(
            "SELECT f.*, d.name as drive_name, d.provider as drive_provider
             FROM files f
             JOIN drives d ON f.drive_id = d.id
             WHERE f.id = ? AND f.user_id = ?",
            [$fileId, $this->userId]
        );
        return $file ?: null;
    }

    public function createFolder(string $name, ?int $parentId = null, ?int $driveId = null): array {
        if (!$driveId) {
            $drive = $this->getFirstActiveDrive();
            if (!$drive) throw new \RuntimeException("No active drive connected");
            $driveId = $drive['id'];
        }

        $drive = Database::fetch("SELECT * FROM drives WHERE id = ? AND user_id = ?", [$driveId, $this->userId]);
        if (!$drive) throw new \RuntimeException("Drive not found");

        $provider = ProviderFactory::create($drive['provider']);
        $parentRemoteId = null;

        if ($parentId) {
            $parentFile = $this->getFile($parentId);
            if ($parentFile) {
                $parentRemoteId = $parentFile['remote_id'];
            }
        }

        $result = $provider->createFolder(
            json_decode($drive['credentials'], true),
            $parentRemoteId ?? $drive['root_folder_id'] ?? '',
            $name
        );

        if (isset($result['error'])) throw new \RuntimeException($result['error']);

        $fileId = Database::insert('files', [
            'user_id' => $this->userId,
            'drive_id' => $driveId,
            'parent_id' => $parentId,
            'remote_id' => $result['remote_id'],
            'name' => $name,
            'mime_type' => 'folder',
            'size' => 0,
            'is_folder' => 1,
        ]);

        return $this->getFile($fileId);
    }

    public function uploadFile(string $name, string $mimeType, $data, ?int $parentId = null, ?int $driveId = null, bool $isEncrypted = false, ?array $encryptionMeta = null): array {
        if (!$driveId) {
            $driveId = $this->pickBestDrive(strlen($data));
        }

        $drive = Database::fetch("SELECT * FROM drives WHERE id = ? AND user_id = ?", [$driveId, $this->userId]);
        if (!$drive) throw new \RuntimeException("Drive not found");

        $provider = ProviderFactory::create($drive['provider']);
        $parentRemoteId = $drive['root_folder_id'] ?? '';

        if ($parentId) {
            $parentFile = $this->getFile($parentId);
            if ($parentFile) $parentRemoteId = $parentFile['remote_id'];
        }

        $result = $provider->uploadFile(
            json_decode($drive['credentials'], true),
            $parentRemoteId,
            $data,
            $isEncrypted ? 'udrive_enc_' . bin2hex(random_bytes(8)) . '.bin' : $name,
            $mimeType
        );

        if (isset($result['error'])) throw new \RuntimeException($result['error']);

        $fileId = Database::insert('files', [
            'user_id' => $this->userId,
            'drive_id' => $driveId,
            'parent_id' => $parentId,
            'remote_id' => $result['remote_id'],
            'name' => $name,
            'mime_type' => $mimeType,
            'size' => (int) ($result['size'] ?? strlen($data)),
            'is_encrypted' => $isEncrypted ? 1 : 0,
            'encryption_iv' => $encryptionMeta['iv'] ?? null,
            'encryption_tag' => $encryptionMeta['tag'] ?? null,
        ]);

        Database::query(
            "UPDATE drives SET storage_used = storage_used + ? WHERE id = ?",
            [(int) ($result['size'] ?? strlen($data)), $driveId]
        );

        return $this->getFile($fileId);
    }

    public function deleteFile(int $fileId, bool $permanent = false): bool {
        $file = $this->getFile($fileId);
        if (!$file) return false;

        if ($permanent) {
            $drive = Database::fetch("SELECT * FROM drives WHERE id = ?", [$file['drive_id']]);
            if ($drive) {
                try {
                    $provider = ProviderFactory::create($drive['provider']);
                    $provider->deleteFile(json_decode($drive['credentials'], true), $file['remote_id']);
                } catch (\Exception $e) {
                    error_log("Failed to delete from cloud: " . $e->getMessage());
                }
            }

            if ($file['is_folder']) {
                $children = Database::fetchAll("SELECT id FROM files WHERE parent_id = ?", [$fileId]);
                foreach ($children as $child) {
                    $this->deleteFile($child['id'], true);
                }
            }

            Database::delete('files', 'id = ? AND user_id = ?', [$fileId, $this->userId]);

            if (!$file['is_folder']) {
                Database::query("UPDATE drives SET storage_used = GREATEST(0, storage_used - ?) WHERE id = ?",
                    [$file['size'], $file['drive_id']]);
            }
        } else {
            Database::update('files', ['trashed' => 1, 'updated_at' => date('Y-m-d H:i:s')], 'id = ? AND user_id = ?', [$fileId, $this->userId]);
        }

        return true;
    }

    public function renameFile(int $fileId, string $newName): bool {
        $file = $this->getFile($fileId);
        if (!$file) return false;

        $drive = Database::fetch("SELECT * FROM drives WHERE id = ?", [$file['drive_id']]);
        if (!$drive) return false;

        try {
            $provider = ProviderFactory::create($drive['provider']);
            $provider->renameFile(json_decode($drive['credentials'], true), $file['remote_id'], $newName);
        } catch (\Exception $e) {
            error_log("Failed to rename on cloud: " . $e->getMessage());
        }

        Database::update('files', ['name' => $newName, 'updated_at' => date('Y-m-d H:i:s')], 'id = ? AND user_id = ?', [$fileId, $this->userId]);
        return true;
    }

    public function moveFile(int $fileId, int $targetDriveId, ?int $targetParentId = null): bool {
        $file = $this->getFile($fileId);
        if (!$file) return false;

        if ($file['drive_id'] == $targetDriveId) {
            $drive = Database::fetch("SELECT * FROM drives WHERE id = ?", [$targetDriveId]);
            $provider = ProviderFactory::create($drive['provider']);
            $targetRemoteId = $drive['root_folder_id'] ?? '';
            if ($targetParentId) {
                $targetFile = $this->getFile($targetParentId);
                if ($targetFile) $targetRemoteId = $targetFile['remote_id'];
            }
            $provider->moveFile(json_decode($drive['credentials'], true), $file['remote_id'], $targetRemoteId);
        } else {
            $this->transferFile($fileId, $targetDriveId, $targetParentId);
            return true;
        }

        Database::update('files', [
            'parent_id' => $targetParentId,
            'drive_id' => $targetDriveId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ? AND user_id = ?', [$fileId, $this->userId]);

        return true;
    }

    public function copyFile(int $fileId, int $targetDriveId, ?int $targetParentId = null): ?int {
        $file = $this->getFile($fileId);
        if (!$file) return null;

        $sourceDrive = Database::fetch("SELECT * FROM drives WHERE id = ?", [$file['drive_id']]);
        $targetDrive = Database::fetch("SELECT * FROM drives WHERE id = ? AND user_id = ?", [$targetDriveId, $this->userId]);
        if (!$sourceDrive || !$targetDrive) return null;

        $sourceProvider = ProviderFactory::create($sourceDrive['provider']);
        $targetProvider = ProviderFactory::create($targetDrive['provider']);

        $targetRemoteId = $targetDrive['root_folder_id'] ?? '';
        if ($targetParentId) {
            $targetFile = $this->getFile($targetParentId);
            if ($targetFile) $targetRemoteId = $targetFile['remote_id'];
        }

        $stream = $sourceProvider->downloadStream(json_decode($sourceDrive['credentials'], true), $file['remote_id']);
        $content = stream_get_contents($stream);
        if (is_resource($stream)) fclose($stream);

        $result = $targetProvider->uploadFile(
            json_decode($targetDrive['credentials'], true),
            $targetRemoteId,
            $content,
            $file['name'],
            $file['mime_type']
        );

        $newId = Database::insert('files', [
            'user_id' => $this->userId,
            'drive_id' => $targetDriveId,
            'parent_id' => $targetParentId,
            'remote_id' => $result['remote_id'] ?? '',
            'name' => $file['name'],
            'mime_type' => $file['mime_type'],
            'size' => $file['size'],
            'is_folder' => $file['is_folder'],
            'is_encrypted' => $file['is_encrypted'],
        ]);

        return $newId;
    }

    public function downloadFile(int $fileId) {
        $file = $this->getFile($fileId);
        if (!$file || $file['is_folder']) return null;

        $drive = Database::fetch("SELECT * FROM drives WHERE id = ?", [$file['drive_id']]);
        if (!$drive) return null;

        $provider = ProviderFactory::create($drive['provider']);
        return $provider->downloadStream(json_decode($drive['credentials'], true), $file['remote_id']);
    }

    public function transferFile(int $fileId, int $targetDriveId, ?int $targetParentId = null): bool {
        $file = $this->getFile($fileId);
        if (!$file) return false;

        $sourceDrive = Database::fetch("SELECT * FROM drives WHERE id = ?", [$file['drive_id']]);
        $targetDrive = Database::fetch("SELECT * FROM drives WHERE id = ? AND user_id = ?", [$targetDriveId, $this->userId]);
        if (!$sourceDrive || !$targetDrive) return false;

        $sourceProvider = ProviderFactory::create($sourceDrive['provider']);
        $targetProvider = ProviderFactory::create($targetDrive['provider']);

        $stream = $sourceProvider->downloadStream(json_decode($sourceDrive['credentials'], true), $file['remote_id']);
        if (!$stream) return false;

        $content = stream_get_contents($stream);
        fclose($stream);

        $targetRemoteId = $targetDrive['root_folder_id'] ?? '';
        if ($targetParentId) {
            $targetFile = $this->getFile($targetParentId);
            if ($targetFile) $targetRemoteId = $targetFile['remote_id'];
        }

        $result = $targetProvider->uploadFile(
            json_decode($targetDrive['credentials'], true),
            $targetRemoteId,
            $content,
            $file['name'],
            $file['mime_type']
        );

        if (isset($result['error'])) return false;

        $sourceProvider->deleteFile(json_decode($sourceDrive['credentials'], true), $file['remote_id']);

        Database::update('files', [
            'drive_id' => $targetDriveId,
            'parent_id' => $targetParentId,
            'remote_id' => $result['remote_id'] ?? $file['remote_id'],
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ? AND user_id = ?', [$fileId, $this->userId]);

        Database::query("UPDATE drives SET storage_used = GREATEST(0, storage_used - ?) WHERE id = ?", [$file['size'], $file['drive_id']]);
        Database::query("UPDATE drives SET storage_used = storage_used + ? WHERE id = ?", [(int)($result['size'] ?? $file['size']), $targetDriveId]);

        return true;
    }

    public function toggleStar(int $fileId): int {
        $file = $this->getFile($fileId);
        if (!$file) return -1;
        $newStar = $file['starred'] ? 0 : 1;
        Database::update('files', ['starred' => $newStar], 'id = ? AND user_id = ?', [$fileId, $this->userId]);
        return $newStar;
    }

    public function restoreFile(int $fileId): bool {
        return Database::update('files', ['trashed' => 0], 'id = ? AND user_id = ?', [$fileId, $this->userId]) > 0;
    }

    private function pickBestDrive(int $fileSize): int {
        $drives = Database::fetchAll(
            "SELECT id, storage_total, storage_used FROM drives WHERE user_id = ? AND is_active = 1",
            [$this->userId]
        );
        if (empty($drives)) throw new \RuntimeException("No active drives");

        usort($drives, function ($a, $b) {
            $freeA = $a['storage_total'] - $a['storage_used'];
            $freeB = $b['storage_total'] - $b['storage_used'];
            return $freeB - $freeA;
        });

        return $drives[0]['id'];
    }

    private function getFirstActiveDrive(): ?array {
        return Database::fetch(
            "SELECT * FROM drives WHERE user_id = ? AND is_active = 1 LIMIT 1",
            [$this->userId]
        );
    }
}
