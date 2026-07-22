<?php
namespace UDrive\Providers;

interface ProviderInterface {
    public function getAuthUrl(string $redirectUri): string;
    public function handleCallback(array $params): array;
    public function testConnection($credentials): bool;
    public function getStorageInfo($credentials): array;
    public function listFiles($credentials, string $parentId = null): array;
    public function getFile($credentials, string $fileId): array;
    public function createFolder($credentials, string $parentId, string $name): array;
    public function uploadFile($credentials, string $parentId, $data, string $name, string $mime): array;
    public function downloadStream($credentials, string $fileId);
    public function deleteFile($credentials, string $fileId): bool;
    public function renameFile($credentials, string $fileId, string $newName): bool;
    public function moveFile($credentials, string $fileId, string $newParentId): bool;
    public function copyFile($credentials, string $fileId, string $newParentId): array;
    public function getProviderName(): string;
}
