<?php
namespace UDrive\Engine;

use UDrive\Database\Database;

class StoragePool {
    private int $userId;

    public function __construct(int $userId) {
        $this->userId = $userId;
    }

    public function getCombinedStorage(): array {
        $drives = Database::fetchAll(
            "SELECT id, name, provider, storage_total, storage_used, is_active
             FROM drives WHERE user_id = ?",
            [$this->userId]
        );

        $total = 0;
        $used = 0;
        $driveList = [];

        foreach ($drives as $drive) {
            $dTotal = (int) $drive['storage_total'];
            $dUsed = (int) $drive['storage_used'];
            $total += $dTotal;
            $used += $dUsed;

            $driveList[] = [
                'id' => (int) $drive['id'],
                'name' => $drive['name'],
                'provider' => $drive['provider'],
                'total' => $dTotal,
                'used' => $dUsed,
                'free' => $dTotal - $dUsed,
                'is_active' => (bool) $drive['is_active'],
            ];
        }

        return [
            'total' => $total,
            'used' => $used,
            'free' => $total - $used,
            'drives' => $driveList,
        ];
    }

    public function syncDriveStorage(int $driveId): ?array {
        $drive = Database::fetch("SELECT * FROM drives WHERE id = ? AND user_id = ?", [$driveId, $this->userId]);
        if (!$drive) return null;

        try {
            $provider = \UDrive\Providers\ProviderFactory::create($drive['provider']);
            $info = $provider->getStorageInfo(json_decode($drive['credentials'], true));
            Database::update('drives', [
                'storage_total' => $info['total'],
                'storage_used' => $info['used'],
                'last_synced' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$driveId]);

            return $info;
        } catch (\Exception $e) {
            error_log("Failed to sync drive {$driveId}: " . $e->getMessage());
            return null;
        }
    }

    public function syncAllDrives(): void {
        $drives = Database::fetchAll(
            "SELECT id FROM drives WHERE user_id = ? AND is_active = 1",
            [$this->userId]
        );
        foreach ($drives as $drive) {
            $this->syncDriveStorage($drive['id']);
        }
    }

    public function getStats(): array {
        $storage = $this->getCombinedStorage();
        $totalFiles = Database::fetch(
            "SELECT COUNT(*) as cnt FROM files WHERE user_id = ? AND is_folder = 0 AND trashed = 0",
            [$this->userId]
        );
        $totalFolders = Database::fetch(
            "SELECT COUNT(*) as cnt FROM files WHERE user_id = ? AND is_folder = 1 AND trashed = 0",
            [$this->userId]
        );
        $encryptedFiles = Database::fetch(
            "SELECT COUNT(*) as cnt FROM files WHERE user_id = ? AND is_encrypted = 1 AND trashed = 0",
            [$this->userId]
        );

        return [
            'storage' => $storage,
            'total_files' => (int) ($totalFiles['cnt'] ?? 0),
            'total_folders' => (int) ($totalFolders['cnt'] ?? 0),
            'encrypted_files' => (int) ($encryptedFiles['cnt'] ?? 0),
            'drive_count' => count($storage['drives']),
        ];
    }
}
