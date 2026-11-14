<?php
// Local development only:  php -S localhost:8000 router.php
// Mirrors the URL rewrites in vercel.json.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/') $path = '/index.php';
if ($path === '/style.css') $path = '/style.php';

$file = __DIR__ . '/api' . $path;
if (preg_match('#^/[A-Za-z_]+\.php$#', $path) && is_file($file)) {
    $_SERVER['SCRIPT_NAME'] = $path;
    chdir(__DIR__ . '/api');
    require $file;
    return true;
}
http_response_code(404);
echo 'Not found';
