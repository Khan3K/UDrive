<?php
use UDrive\Auth\Auth;
use UDrive\Database\Database;
use UDrive\Providers\ProviderFactory;
use UDrive\Engine\StoragePool;
use UDrive\Config\ConfigHelper;

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$body = array_merge($_GET, $body);

function getAppUrl(): string {
    $config = require __DIR__ . '/../config.php';
    if (!empty($config['app']['url'])) return $config['app']['url'];
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $base = preg_replace('#/api/.*$#', '', $uri);
    $base = rtrim($base, '/');
    return $scheme . '://' . $host . $base;
}

switch ($action) {
    case 'connect_oauth':
        $providerType = $_GET['provider'] ?? '';
        $redirectBase = getAppUrl();
        if (!$providerType) {
            header('Location: ' . $redirectBase . '?error=' . urlencode('Missing provider'));
            exit;
        }
        try {
            $provider = ProviderFactory::create($providerType);
        } catch (\Exception $e) {
            header('Location: ' . $redirectBase . '?error=' . urlencode('Unknown provider'));
            exit;
        }
        $clientId = ConfigHelper::get($providerType . '.client_id', '');
        if (!$clientId || str_starts_with($clientId, 'YOUR_')) {
            header('Location: ' . $redirectBase . '?error=' . urlencode('OAuth not configured. Ask admin to set up ' . $providerType . ' credentials in the admin panel.'));
            exit;
        }
        $redirectUri = getAppUrl() . '/api/drives/callback?provider=' . $providerType;
        $authUrl = $provider->getAuthUrl($redirectUri);
        header('Location: ' . $authUrl);
        exit;

    case 'connect':
        $user = Auth::requireAuth();
        $providerType = $body['provider'] ?? '';
        $credentials = $body['credentials'] ?? null;
        if (is_string($credentials)) {
            $credentials = json_decode($credentials, true);
        }
        if (!is_array($credentials)) {
            $credentials = [];
        }

        if (!$providerType) {
            http_response_code(400);
            echo json_encode(['error' => 'Provider type required']);
            break;
        }

        $supported = ProviderFactory::getSupported();
        if (!isset($supported[$providerType])) {
            http_response_code(400);
            echo json_encode(['error' => 'Unsupported provider']);
            break;
        }

        if ($providerType === 'mega') {
            $email = $credentials['email'] ?? '';
            $password = $credentials['password'] ?? '';
            if (empty($email) || empty($password)) {
                http_response_code(400);
                echo json_encode(['error' => 'Email and password required']);
                break;
            }
            $provider = ProviderFactory::create('mega');
            $result = $provider->handleCallback(['email' => $email, 'password' => $password]);
            if ($result['ok']) {
                Database::insert('drives', [
                    'user_id' => $user['id'],
                    'provider' => 'mega',
                    'name' => $result['drive_name'] ?? 'MEGA',
                    'credentials' => $result['credentials'],
                    'storage_total' => $result['storage_total'] ?? 0,
                    'storage_used' => $result['storage_used'] ?? 0,
                ]);
                echo json_encode(['ok' => true, 'message' => 'MEGA connected']);
            } else {
                http_response_code(400);
                echo json_encode($result);
            }
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'OAuth providers must use the connect link, not POST.']);
        }
        break;

    case 'callback':
        $user = Auth::requireAuth();
        $providerType = $_GET['provider'] ?? '';
        $redirectBase = getAppUrl();

        try {
            $provider = ProviderFactory::create($providerType);
        } catch (\Exception $e) {
            header('Location: ' . $redirectBase . '?error=' . urlencode('Unknown provider'));
            exit;
        }

        $params = $_GET;
        $params['redirect_uri'] = getAppUrl() . '/api/drives/callback?provider=' . $providerType;

        $result = $provider->handleCallback($params);

        if ($result['ok']) {
            Database::insert('drives', [
                'user_id' => $user['id'],
                'provider' => $providerType,
                'name' => $result['drive_name'] ?? $providerType,
                'credentials' => $result['credentials'],
                'storage_total' => $result['storage_total'] ?? 0,
                'storage_used' => $result['storage_used'] ?? 0,
            ]);
            header('Location: ' . $redirectBase . '?drive_connected=1');
        } else {
            $errorMsg = urlencode($result['error'] ?? 'Connection failed');
            header('Location: ' . $redirectBase . '?error=' . $errorMsg);
        }
        exit;

    case 'list':
        $user = Auth::requireAuth();
        $drives = Database::fetchAll(
            "SELECT id, provider, name, storage_total, storage_used, is_active, last_synced, created_at
             FROM drives WHERE user_id = ?",
            [$user['id']]
        );
        foreach ($drives as &$d) {
            $d['storage_total'] = (int) $d['storage_total'];
            $d['storage_used'] = (int) $d['storage_used'];
            $d['storage_free'] = $d['storage_total'] - $d['storage_used'];
            $d['is_active'] = (bool) $d['is_active'];
        }
        echo json_encode(['ok' => true, 'drives' => $drives]);
        break;

    case 'info':
        $user = Auth::requireAuth();
        $pool = new StoragePool($user['id']);
        echo json_encode(['ok' => true, 'storage' => $pool->getCombinedStorage()]);
        break;

    case 'disconnect':
        $user = Auth::requireAuth();
        $driveId = (int) ($body['id'] ?? $_GET['id'] ?? 0);
        if (!$driveId) {
            http_response_code(400);
            echo json_encode(['error' => 'Drive ID required']);
            break;
        }
        Database::delete('drives', 'id = ? AND user_id = ?', [$driveId, $user['id']]);
        echo json_encode(['ok' => true]);
        break;

    case 'sync':
        $user = Auth::requireAuth();
        $driveId = (int) ($body['id'] ?? 0);
        $pool = new StoragePool($user['id']);
        if ($driveId) {
            $pool->syncDriveStorage($driveId);
        } else {
            $pool->syncAllDrives();
        }
        echo json_encode(['ok' => true, 'storage' => $pool->getCombinedStorage()]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
