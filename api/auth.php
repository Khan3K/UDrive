<?php
use UDrive\Auth\Auth;
use UDrive\Database\Database;

$action = $_GET['action'] ?? '';
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true) ?: $_POST;
$body = array_merge($_GET, $body);

switch ($action) {
    case 'register':
        $result = Auth::register(
            $body['username'] ?? '',
            $body['email'] ?? '',
            $body['password'] ?? ''
        );
        if ($result['ok']) {
            http_response_code(201);
        } else {
            http_response_code(400);
        }
        echo json_encode($result);
        break;

    case 'login':
        $result = Auth::login(
            $body['username'] ?? '',
            $body['password'] ?? ''
        );
        if (!$result['ok']) {
            http_response_code(401);
        }
        echo json_encode($result);
        break;

    case 'logout':
        Auth::logout();
        echo json_encode(['ok' => true]);
        break;

    case 'check':
        $user = Auth::check();
        if ($user) {
            echo json_encode(['ok' => true, 'user' => $user]);
        } else {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
