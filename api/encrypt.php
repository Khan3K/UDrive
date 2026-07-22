<?php
use UDrive\Auth\Auth;

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$body = array_merge($_GET, $body);

switch ($action) {
    case 'unlock':
        $user = Auth::requireAuth();
        $password = $body['password'] ?? '';

        if (empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Password required']);
            break;
        }

        if (Auth::setEncryptionKey($password, $user['id'])) {
            echo json_encode(['ok' => true, 'message' => 'Encryption unlocked']);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Failed to unlock encryption']);
        }
        break;

    case 'lock':
        Auth::requireAuth();
        Auth::clearEncryptionKey();
        echo json_encode(['ok' => true, 'message' => 'Encryption locked']);
        break;

    case 'status':
        $user = Auth::requireAuth();
        $hasKey = Auth::getEncryptionKey($user['id']) !== null;
        echo json_encode([
            'ok' => true,
            'unlocked' => $hasKey,
            'mode' => $user['encryption_mode'] ? 'encrypted' : 'normal',
        ]);
        break;

    case 'toggle':
        $user = Auth::requireAuth();
        $enable = isset($body['enabled']) && $body['enabled'] === true;
        Auth::toggleEncryptionMode($user['id'], $enable);
        echo json_encode(['ok' => true, 'mode' => $enable ? 'encrypted' : 'normal']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
