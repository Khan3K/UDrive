<?php
header('Content-Type: application/json');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && preg_match('#^https?://(localhost|127\.\d+\.\d+\.\d+|10\.\d+\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+|192\.168\.\d+\.\d+|[\d.]+):\d*$#i', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

$uri = $_SERVER['REQUEST_URI'];
$uri = parse_url($uri, PHP_URL_PATH);
$uri = preg_replace('#^/UDrive/api#', '', $uri);
$uri = rtrim($uri, '/');

$method = $_SERVER['REQUEST_METHOD'];
$key = "{$method} {$uri}";

$routeMap = [
    'POST /auth/register'  => ['file' => 'auth.php', 'action' => 'register'],
    'POST /auth/login'     => ['file' => 'auth.php', 'action' => 'login'],
    'POST /auth/logout'    => ['file' => 'auth.php', 'action' => 'logout'],
    'GET /auth/check'      => ['file' => 'auth.php', 'action' => 'check'],

    'GET /drives/list'     => ['file' => 'drives.php', 'action' => 'list'],
    'GET /drives/info'     => ['file' => 'drives.php', 'action' => 'info'],
    'POST /drives/connect' => ['file' => 'drives.php', 'action' => 'connect'],
    'GET /drives/connect'  => ['file' => 'drives.php', 'action' => 'connect_oauth'],
    'GET /drives/callback' => ['file' => 'drives.php', 'action' => 'callback'],
    'DELETE /drives'       => ['file' => 'drives.php', 'action' => 'disconnect'],
    'POST /drives/sync'    => ['file' => 'drives.php', 'action' => 'sync'],

    'GET /files'           => ['file' => 'files.php', 'action' => 'list'],
    'GET /files/starred'   => ['file' => 'files.php', 'action' => 'starred'],
    'GET /files/search'    => ['file' => 'files.php', 'action' => 'search'],
    'GET /files/recent'    => ['file' => 'files.php', 'action' => 'recent'],
    'GET /files/trashed'   => ['file' => 'files.php', 'action' => 'trashed'],
    'POST /files/upload'   => ['file' => 'files.php', 'action' => 'upload'],
    'POST /files/folder'   => ['file' => 'files.php', 'action' => 'create_folder'],
    'POST /files/delete'   => ['file' => 'files.php', 'action' => 'delete'],
    'POST /files/rename'   => ['file' => 'files.php', 'action' => 'rename'],
    'POST /files/move'     => ['file' => 'files.php', 'action' => 'move'],
    'POST /files/copy'     => ['file' => 'files.php', 'action' => 'copy'],
    'POST /files/star'     => ['file' => 'files.php', 'action' => 'star'],
    'POST /files/restore'  => ['file' => 'files.php', 'action' => 'restore'],
    'POST /files/transfer' => ['file' => 'files.php', 'action' => 'transfer'],
    'GET /files/download'  => ['file' => 'files.php', 'action' => 'download'],

    'POST /encrypt/unlock' => ['file' => 'encrypt.php', 'action' => 'unlock'],
    'POST /encrypt/lock'   => ['file' => 'encrypt.php', 'action' => 'lock'],
    'GET /encrypt/status'  => ['file' => 'encrypt.php', 'action' => 'status'],
    'POST /encrypt/toggle' => ['file' => 'encrypt.php', 'action' => 'toggle'],

    'GET /admin/settings'  => ['file' => 'admin.php', 'action' => 'get_settings'],
    'POST /admin/settings' => ['file' => 'admin.php', 'action' => 'save_settings'],
];

if (!isset($routeMap[$key])) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found', 'uri' => $uri, 'method' => $method]);
    exit;
}

$route = $routeMap[$key];
$_GET['action'] = $route['action'];

require __DIR__ . '/' . $route['file'];
