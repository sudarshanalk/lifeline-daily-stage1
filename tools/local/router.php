<?php
// Router for PHP's built-in server: serve real files directly, send everything else to WordPress.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = $_SERVER['DOCUMENT_ROOT'] . $path;
if ($path !== '/' && is_file($file)) {
    // Mirror the production deny-all on the tools backend folder.
    if (preg_match('#^/tools/api/#', $path)) { http_response_code(403); exit; }
    return false;
}
// A folder with its own index.php (/wp-admin/) is served by that file, as Apache's DirectoryIndex does.
if ($path !== '/' && !preg_match('#^/tools/api/#', $path) && is_file(rtrim($file, '/') . '/index.php')) {
    return false;
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
