<?php
namespace UDrive\Providers;

class ProviderFactory {
    private static array $providers = [
        'google_drive' => GoogleDrive::class,
        'mega' => Mega::class,
        'onedrive' => OneDrive::class,
        'dropbox' => Dropbox::class,
    ];

    public static function create(string $type): ProviderInterface {
        if (!isset(self::$providers[$type])) {
            throw new \InvalidArgumentException("Unknown provider: {$type}");
        }
        $class = self::$providers[$type];
        return new $class();
    }

    public static function getSupported(): array {
        return [
            'google_drive' => ['name' => 'Google Drive', 'icon' => 'google', 'auth' => 'oauth'],
            'mega' => ['name' => 'MEGA', 'icon' => 'mega', 'auth' => 'login'],
            'onedrive' => ['name' => 'OneDrive', 'icon' => 'onedrive', 'auth' => 'oauth'],
            'dropbox' => ['name' => 'Dropbox', 'icon' => 'dropbox', 'auth' => 'oauth'],
        ];
    }
}
