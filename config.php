<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'udrive',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'UDrive',
        'url' => null,
        'debug' => true,
    ],
    'session' => [
        'lifetime' => 86400,
        'name' => 'udrive_session',
    ],
    'google_drive' => [
        'client_id' => 'YOUR_GOOGLE_CLIENT_ID',
        'client_secret' => 'YOUR_GOOGLE_CLIENT_SECRET',
        'redirect_uri' => 'http://localhost/UDrive/api/drives/callback?provider=google_drive',
        'scopes' => ['https://www.googleapis.com/auth/drive'],
    ],
    'onedrive' => [
        'client_id' => 'YOUR_ONEDRIVE_CLIENT_ID',
        'client_secret' => 'YOUR_ONEDRIVE_CLIENT_SECRET',
        'scopes' => ['files.ReadWrite', 'offline_access'],
    ],
    'dropbox' => [
        'client_id' => 'YOUR_DROPBOX_APP_KEY',
        'client_secret' => 'YOUR_DROPBOX_APP_SECRET',
    ],
    'mega' => [
        'api_url' => 'https://g.api.mega.co.nz/cs',
    ],
    'encryption' => [
        'cipher' => 'aes-256-gcm',
        'pbkdf2_iterations' => 100000,
        'chunk_size' => 65536,
    ],
];
