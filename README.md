# UDrive — All-in-One Cloud Storage Aggregator

UDrive is a self-hosted web application that unifies multiple cloud storage providers (Google Drive, MEGA, OneDrive, Dropbox) into a single interface. It supports end-to-end file encryption, multi-user accounts, file splitting across drives, and full CRUD operations on remote files.

---

## Table of Contents

- [Features](#features)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure)
- [Database Schema](#database-schema)
- [Installation](#installation)
- [Configuration](#configuration)
- [Admin Setup](#admin-setup)
- [OAuth Provider Setup](#oauth-provider-setup)
- [API Reference](#api-reference)
- [Architecture](#architecture)
- [Security](#security)
- [Default Credentials](#default-credentials)

---

## Features

### Storage Aggregation
- **Unified file view** across all connected cloud drives
- **4 supported providers**: Google Drive, MEGA, OneDrive, Dropbox
- **Storage dashboard** showing per-drive usage and total
- **Cross-drive operations**: move/copy files between any two connected drives
- **Auto-routing** of uploads to the drive with most free space

### Encryption
- **AES-256-GCM** symmetric encryption (authenticated)
- **PBKDF2-SHA256** key derivation (100,000 iterations, per-user salt)
- **Per-file IV** and **GCM tag** stored with each file
- **Lock/unlock** mode — encryption key only lives in the session
- **Per-user password verification** with bcrypt (separate from login password)

### File Management
- Upload, download, rename, delete, restore from trash
- Folder hierarchy with breadcrumb navigation
- Star / unstar files
- Search by filename (case-insensitive, partial match)
- Starred / Recent / Trash views
- Drag-and-drop upload
- Multiple file upload with progress bar

### Multi-User
- User registration and login
- Bcrypt-hashed passwords (cost 12)
- Session-based auth with rotating session tokens
- Per-user isolation — users only see their own files
- **Admin role** for managing OAuth credentials

### Admin Panel
- Web UI to configure OAuth credentials for Google Drive, OneDrive, Dropbox
- Shows the exact redirect URI to add in each provider's developer console
- No need to edit `config.php` for OAuth setup

### Modern UI
- **Inter** font (Google Fonts)
- **Light & dark theme** with CSS variables
- **Responsive** layout — works on mobile
- **SVG icons** throughout (no emoji)
- **Modern design** with subtle shadows, smooth transitions, gradients

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.2 |
| Database | MySQL (PDO) |
| Frontend | Vanilla JavaScript, no framework |
| CSS | Custom CSS with CSS variables, no preprocessor |
| Font | Inter (Google Fonts) |
| Web Server | Apache 2.4 |
| Google SDK | `google/apiclient` ^2.15 |

### PHP Extensions Required
- `pdo_mysql` — database
- `openssl` — AES-256-GCM encryption
- `mbstring` — string handling
- `curl` — HTTP calls to providers
- `json` — JSON encoding
- `hash` — PBKDF2 key derivation
- `session` — session management

---

## Project Structure

```
UDrive/
├── index.php                  # Root entry — serves SPA and routes static assets
├── setup.php                  # One-time setup script (DB + migrations)
├── config.php                 # App configuration (DB, session, crypto)
├── composer.json              # PHP dependencies
├── .htaccess                  # Apache URL rewriting
│
├── api/                       # Backend API endpoints
│   ├── index.php              # Router — maps METHOD+path to file+action
│   ├── auth.php               # /auth/* (register, login, logout, check)
│   ├── drives.php             # /drives/* (list, connect, callback, disconnect, sync)
│   ├── files.php              # /files/* (CRUD, upload, download, search, star, etc.)
│   ├── encrypt.php            # /encrypt/* (unlock, lock, status, toggle)
│   └── admin.php              # /admin/* (get/save settings — admin only)
│
├── src/                       # PHP source code (PSR-4: UDrive\ → src/)
│   ├── Auth/Auth.php          # User authentication, session management
│   ├── Config/ConfigHelper.php # DB-first config reader (falls back to config.php)
│   ├── Database/Database.php  # PDO wrapper + migrations
│   ├── Encryption/
│   │   ├── CryptoEngine.php   # AES-256-GCM encrypt/decrypt for files
│   │   ├── KeyDeriver.php     # PBKDF2 key derivation
│   │   └── StreamCrypto.php   # Streaming encrypt/decrypt for large files
│   ├── Engine/
│   │   ├── FileManager.php    # File CRUD, search, star, move, copy
│   │   └── StoragePool.php    # Aggregate storage info across drives
│   └── Providers/
│       ├── ProviderInterface.php  # Common interface for all 4 providers
│       ├── ProviderFactory.php    # Factory: name → class
│       ├── GoogleDrive.php        # Google Drive via google/apiclient
│       ├── OneDrive.php           # Microsoft Graph API
│       ├── Dropbox.php            # Dropbox API v2
│       └── Mega.php               # MEGA API
│
└── public/                    # Frontend SPA
    ├── index.html             # Single-page app HTML
    ├── css/style.css          # Complete stylesheet
    └── js/
        ├── api.js             # fetch() wrapper
        └── app.js             # Main app logic
```

---

## Database Schema

6 tables in MySQL (created by `Database::migrate()`):

### `users`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| username | VARCHAR(50) UNIQUE | |
| email | VARCHAR(255) UNIQUE | |
| password_hash | VARCHAR(255) | bcrypt cost 12 |
| encryption_mode | TINYINT(1) DEFAULT 0 | 0=normal, 1=encrypted |
| encryption_salt | VARCHAR(64) | 32 bytes hex |
| encryption_verify | VARCHAR(255) | bcrypt verify hash |
| avatar_color | VARCHAR(7) | random color for UI |
| is_admin | TINYINT(1) DEFAULT 0 | 0=user, 1=admin |
| created_at | DATETIME | |
| last_login | DATETIME | |

### `drives`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| user_id | INT FK → users.id | CASCADE delete |
| provider | VARCHAR(30) | google_drive / mega / onedrive / dropbox |
| name | VARCHAR(100) | display name (e.g. user's email) |
| credentials | TEXT | JSON OAuth tokens |
| storage_total | BIGINT | bytes |
| storage_used | BIGINT | bytes |
| root_folder_id | VARCHAR(255) | remote root folder ID |
| is_active | TINYINT(1) | |
| last_synced | DATETIME | |
| created_at | DATETIME | |

### `files`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| user_id | INT FK → users.id | |
| drive_id | INT FK → drives.id | |
| parent_id | INT | self-referencing folder hierarchy |
| remote_id | VARCHAR(255) | ID on the remote provider |
| name | VARCHAR(500) | |
| mime_type | VARCHAR(255) | |
| size | BIGINT | bytes |
| is_folder | TINYINT(1) | |
| is_encrypted | TINYINT(1) | |
| encryption_iv | VARCHAR(64) | hex |
| encryption_tag | VARCHAR(64) | hex |
| split_manifest | TEXT | JSON if file is split across drives |
| starred | TINYINT(1) | |
| trashed | TINYINT(1) | soft delete |
| created_at | DATETIME | |
| updated_at | DATETIME | |

Indexes: `(user_id)`, `(parent_id)`, `(drive_id)`, `(user_id, trashed)`.

### `file_chunks`
For files split across multiple drives. Each row is one chunk:
| Column | Type |
|--------|------|
| id | INT AUTO_INCREMENT PK |
| file_id | INT FK → files.id |
| chunk_index | INT |
| chunk_size | BIGINT |
| drive_id | INT FK → drives.id |
| remote_id | VARCHAR(255) |
| remote_path | VARCHAR(500) |
| checksum | VARCHAR(64) |

### `sessions`
| Column | Type |
|--------|------|
| id | VARCHAR(64) PK — random hex token |
| user_id | INT FK → users.id |
| ip_address | VARCHAR(45) |
| user_agent | TEXT |
| encryption_key | VARCHAR(128) — per-session encryption key (cleared on lock) |
| created_at | DATETIME |
| expires_at | DATETIME |

### `transfer_jobs`
For background file transfers between drives:
| Column | Type |
|--------|------|
| id | INT AUTO_INCREMENT PK |
| user_id | INT FK |
| file_id | INT FK |
| source_drive_id | INT FK |
| target_drive_id | INT FK |
| status | VARCHAR(20) — pending / running / done / failed |
| progress | INT 0-100 |
| error_message | TEXT |
| created_at | DATETIME |
| completed_at | DATETIME |

### `settings`
Dynamic key-value store for admin-configurable settings (OAuth credentials):
| Column | Type |
|--------|------|
| setting_key | VARCHAR(100) PK |
| setting_value | TEXT |
| updated_at | DATETIME |

---

## Installation

### 1. Prerequisites
- **XAMPP** (or any LAMP/WAMP stack) with PHP 8.0+
- **MySQL** running
- **Apache** with `mod_rewrite` enabled
- **Composer** (for PHP dependencies)

### 2. Clone / Copy Project
```bash
# Copy the UDrive folder into your web root
cp -r UDrive/ /opt/lampp/htdocs/
```

### 3. Install PHP Dependencies
```bash
cd /opt/lampp/htdocs/UDrive
/opt/lampp/bin/php /opt/lampp/bin/composer.phar install
```

This installs `google/apiclient` and `google/auth` packages.

### 4. Configure `config.php`
Edit `config.php` and update the database credentials:
```php
'db' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'udrive',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
],
```

### 5. Run Setup
Open in your browser:
```
http://localhost/UDrive/setup.php
```

This creates the database and all tables. You should see:
- ✅ MySQL connection OK
- ✅ Database ready
- ✅ Tables created/migrated

### 6. Start Using UDrive
```
http://localhost/UDrive/
```

---

## Configuration

The `config.php` file contains all static configuration. Values that can be changed at runtime via the admin panel are stored in the `settings` database table and read by `ConfigHelper` (DB takes priority).

```php
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
        'url' => null,            // Auto-detected; or set to 'http://localhost/UDrive'
        'debug' => true,
    ],
    'session' => [
        'lifetime' => 86400,      // 24 hours
        'name' => 'udrive_session',
    ],
    'encryption' => [
        'cipher' => 'aes-256-gcm',
        'pbkdf2_iterations' => 100000,
        'chunk_size' => 65536,    // 64 KB
    ],
];
```

OAuth provider credentials (`google_drive`, `onedrive`, `dropbox`) can be set in `config.php` as fallbacks, but are **overridden** by the `settings` table when set via the admin panel.

---

## Admin Setup

The first user registered through the UI will be a normal user. To make a user an admin, run this SQL query:

```sql
UPDATE users SET is_admin = 1 WHERE username = 'your_username';
```

After running this query, the user must log out and log back in for the change to take effect. The **Admin Settings** nav item will then appear in the sidebar.

### Admin Panel Features
- View configuration status for Google Drive, OneDrive, Dropbox
- Save Client ID and Client Secret for each provider
- See the **exact redirect URI** to add in each provider's developer console (click to copy)

---

## OAuth Provider Setup

UDrive supports **4 cloud storage providers**. MEGA uses direct email/password login, so it works out of the box. The other 3 need OAuth credentials.

### Google Drive

1. Go to **https://console.cloud.google.com/**
2. Create a new project (or select existing)
3. **APIs & Services → Library** → search "Google Drive API" → **Enable**
4. **APIs & Services → OAuth consent screen** → choose **External** → fill in app name + your email → **Save and Continue** through all steps
5. **Test users** → add your Google email
6. **APIs & Services → Credentials** → **Create Credentials → OAuth client ID**
7. Application type: **Web application**
8. **Authorized redirect URIs** → add (use the exact URL shown in the admin panel):
   ```
   http://localhost/UDrive/api/drives/callback?provider=google_drive
   ```
9. **Create** → copy **Client ID** and **Client Secret**
10. In UDrive admin panel → **Google Drive** → paste → **Save**

> ⚠️ Google shows "This app isn't verified" warning in testing mode. Click **Advanced → Go to UDrive (unsafe)** to proceed.

### OneDrive (Microsoft)

1. Go to **https://portal.azure.com/#blade/Microsoft_AAD_RegisteredApps/ApplicationsListBlade**
2. **New registration** → name "UDrive" → choose accounts in any organizational directory + personal → add redirect URI:
   ```
   http://localhost/UDrive/api/drives/callback?provider=onedrive
   ```
3. After creation, copy the **Application (client) ID**
4. **Certificates & secrets** → **New client secret** → copy the value
5. In UDrive admin panel → **OneDrive** → paste Client ID + Secret → **Save**

### Dropbox

1. Go to **https://www.dropbox.com/developers/apps**
2. **Create app** → choose **Scoped access** → **Full Dropbox** → name "UDrive"
3. Add redirect URI:
   ```
   http://localhost/UDrive/api/drives/callback?provider=dropbox
   ```
4. On the settings page, find:
   - **App key** (= Client ID)
   - **App secret** (= Client Secret)
5. In UDrive admin panel → **Dropbox** → paste → **Save**

### MEGA

No setup needed — users connect by entering their MEGA email and password directly in the UI.

---

## API Reference

All endpoints are under `/UDrive/api/`. All return JSON. Authenticated endpoints require a valid session cookie.

### Authentication (`auth.php`)

| Method | Path | Auth | Body / Query | Returns |
|--------|------|------|--------------|---------|
| POST | `/auth/register` | No | `{username, email, password}` | `{ok, user_id}` or `{error}` |
| POST | `/auth/login` | No | `{username, password}` | `{ok, session_id, user}` |
| POST | `/auth/logout` | Yes | — | `{ok}` |
| GET | `/auth/check` | Cookie | — | `{ok, user}` or `{error}` |

### Drives (`drives.php`)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/drives/list` | Yes | List user's connected drives |
| GET | `/drives/info` | Yes | Combined storage across drives |
| POST | `/drives/connect` | Yes | Connect MEGA (email/password) |
| GET | `/drives/connect?provider=X` | No | Start OAuth flow for X |
| GET | `/drives/callback?provider=X&code=...` | Yes | OAuth callback |
| DELETE | `/drives?id=X` | Yes | Disconnect drive |
| POST | `/drives/sync?id=X` | Yes | Sync storage info |

### Files (`files.php`)

| Method | Path | Body / Query |
|--------|------|--------------|
| GET | `/files?folder=X` | List files in folder |
| GET | `/files/starred` | Starred files |
| GET | `/files/recent` | Recent files |
| GET | `/files/trashed` | Files in trash |
| GET | `/files/search?q=X` | Search by name |
| POST | `/files/upload` | multipart: file, folder_id, drive_id, encrypted |
| POST | `/files/folder` | `{name, folder_id, drive_id}` |
| POST | `/files/delete` | `{id, permanent?}` |
| POST | `/files/rename` | `{id, name}` |
| POST | `/files/move` | `{id, target_drive_id}` |
| POST | `/files/copy` | `{id, target_drive_id}` |
| POST | `/files/star` | `{id}` — toggles, returns new state |
| POST | `/files/restore` | `{id}` |
| POST | `/files/transfer` | `{file_id, target_drive_id}` |
| GET | `/files/download?id=X` | Streams the file |

### Encryption (`encrypt.php`)

| Method | Path | Body |
|--------|------|------|
| GET | `/encrypt/status` | — returns `{mode, unlocked}` |
| POST | `/encrypt/unlock` | `{password}` |
| POST | `/encrypt/lock` | — |
| POST | `/encrypt/toggle` | `{enabled: true/false}` |

### Admin (`admin.php`) — Admin only

| Method | Path | Returns |
|--------|------|---------|
| GET | `/admin/settings` | `{settings: {provider: {client_id, client_secret}}}` |
| POST | `/admin/settings` | Body: `{provider, client_id, client_secret}` |

---

## Architecture

### Request Lifecycle

```
Browser
  ↓ GET /UDrive/
Apache (.htaccess)
  ↓ RewriteRule → index.php
index.php
  ↓ reads /public/index.html
HTML page (SPA)
  ↓ loads css/style.css, js/api.js, js/app.js
User interacts (e.g. click "Upload")
  ↓ fetch('/api/files/upload', POST, FormData)
Apache
  ↓ RewriteRule → api/index.php
api/index.php (router)
  ↓ matches "POST /files/upload" → files.php?action=upload
files.php
  ↓ requires auth, calls FileManager::upload()
FileManager
  ↓ calls Provider->uploadFile()
Provider (e.g. GoogleDrive)
  ↓ Google API SDK → Google servers
Response JSON
  ↓ Browser updates UI
```

### Encryption Flow

1. User enables encryption mode in settings → enters encryption password (separate from login)
2. Password + per-user salt → PBKDF2-SHA256 (100k iterations) → 32-byte key
3. Bcrypt hash of password stored in `users.encryption_verify`
4. Encryption key stored in `sessions.encryption_key` (session-scoped, cleared on lock)
5. When uploading a file:
   - Random 16-byte IV generated
   - File encrypted with AES-256-GCM
   - IV + ciphertext + GCM tag stored
6. When downloading:
   - Verify encryption is unlocked
   - Retrieve key from session
   - Decrypt file

### Provider System

All providers implement `ProviderInterface`:
```php
interface ProviderInterface {
    public function getProviderName(): string;
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
}
```

`ProviderFactory::create($type)` instantiates the correct class. `ProviderFactory::getSupported()` returns metadata for all providers.

### Config Resolution

OAuth credentials are resolved in this order:
1. `ConfigHelper::get('google_drive.client_id')` — checks the `settings` database table
2. Falls back to `config.php` if not found in DB

This allows runtime configuration via the admin panel without editing files.

---

## Security

### Authentication
- Passwords: bcrypt cost 12
- Sessions: random 64-char hex token, stored in `sessions` table, 24h expiry
- Session ID also stored in PHP cookie (`session_name` = `udrive_session`)
- SQL injection: all queries use PDO prepared statements

### Encryption
- Algorithm: AES-256-GCM (authenticated encryption)
- Key derivation: PBKDF2-SHA256, 100,000 iterations, 32-byte per-user salt
- IV: 16 random bytes per file
- GCM tag: 16 bytes, stored with ciphertext
- File format: `[IV (16)][ciphertext][GCM tag (16)]`
- Encryption key never persisted — lives only in the session row, cleared on lock/logout

### API Security
- All write endpoints require authentication (`Auth::requireAuth()`)
- Admin endpoints require `Auth::requireAdmin()`
- CORS allowed only for localhost and private network ranges (10.x, 172.16-31.x, 192.168.x, 127.x)
- Cookies are `HttpOnly` and `SameSite=Lax`

### File Storage on Provider
- For encrypted files, the **plaintext never leaves your server** — encryption happens before upload
- Providers only see ciphertext blobs

---

## Default Credentials

After running `setup.php`, no default users exist. You must register the first user through the UI at `http://localhost/UDrive/`.

To create an admin user, register normally then:
```sql
UPDATE users SET is_admin = 1 WHERE username = 'your_username';
```

---

## Routes Map

```
METHOD+PATH                  → FILE            → ACTION
─────────────────────────────────────────────────────────
POST /auth/register          → auth.php        → register
POST /auth/login             → auth.php        → login
POST /auth/logout            → auth.php        → logout
GET  /auth/check             → auth.php        → check

GET  /drives/list            → drives.php      → list
GET  /drives/info            → drives.php      → info
POST /drives/connect         → drives.php      → connect
GET  /drives/connect         → drives.php      → connect_oauth
GET  /drives/callback        → drives.php      → callback
DELETE /drives               → drives.php      → disconnect
POST /drives/sync            → drives.php      → sync

GET  /files                  → files.php       → list
GET  /files/starred          → files.php       → starred
GET  /files/search           → files.php       → search
GET  /files/recent           → files.php       → recent
GET  /files/trashed          → files.php       → trashed
POST /files/upload           → files.php       → upload
POST /files/folder           → files.php       → create_folder
POST /files/delete           → files.php       → delete
POST /files/rename           → files.php       → rename
POST /files/move             → files.php       → move
POST /files/copy             → files.php       → copy
POST /files/star             → files.php       → star
POST /files/restore          → files.php       → restore
POST /files/transfer         → files.php       → transfer
GET  /files/download         → files.php       → download

POST /encrypt/unlock         → encrypt.php     → unlock
POST /encrypt/lock           → encrypt.php     → lock
GET  /encrypt/status         → encrypt.php     → status
POST /encrypt/toggle         → encrypt.php     → toggle

GET  /admin/settings         → admin.php       → get_settings
POST /admin/settings         → admin.php       → save_settings
```

---

## License

MIT — do whatever you want.

## Credits

- **Inter font** by Rasmus Andersson
- **google/apiclient** by Google
- **MEGA** API reverse-engineered by the open-source community
