<?php
use UDrive\Auth\Auth;
use UDrive\Config\ConfigHelper;

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

switch ($action) {
    case 'get_settings':
        Auth::requireAdmin();
        $allSettings = ConfigHelper::getAll();
        $providers = ['google_drive', 'onedrive', 'dropbox'];
        $result = [];
        foreach ($providers as $p) {
            $result[$p] = [
                'client_id' => $allSettings[$p . '.client_id'] ?? '',
                'client_secret' => $allSettings[$p . '.client_secret'] ?? '',
            ];
        }
        echo json_encode(['ok' => true, 'settings' => $result]);
        break;

    case 'save_settings':
        Auth::requireAdmin();
        $provider = $body['provider'] ?? '';
        $clientId = $body['client_id'] ?? '';
        $clientSecret = $body['client_secret'] ?? '';
        $supported = ['google_drive', 'onedrive', 'dropbox'];
        if (!in_array($provider, $supported)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid provider']);
            break;
        }
        if (empty($clientId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Client ID is required']);
            break;
        }
        ConfigHelper::set($provider . '.client_id', $clientId);
        ConfigHelper::set($provider . '.client_secret', $clientSecret);
        echo json_encode(['ok' => true, 'message' => ucfirst(str_replace('_', ' ', $provider)) . ' credentials saved']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
