<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$relative = preg_replace('#^/UDrive/?#', '', $path);

// Serve static assets from public/
if (preg_match('#\.(css|js|ico|png|jpg|gif|svg|woff2?|ttf|eot|map)$#i', $relative)) {
    $file = __DIR__ . '/public/' . $relative;
    if (file_exists($file)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = [
            'css' => 'text/css', 'js' => 'application/javascript', 'ico' => 'image/x-icon',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif',
            'svg' => 'image/svg+xml', 'woff2' => 'font/woff2', 'woff' => 'font/woff',
            'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject', 'map' => 'application/json',
        ];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        readfile($file);
        exit;
    }
}

// SPA: serve the HTML shell
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/public/index.html');
