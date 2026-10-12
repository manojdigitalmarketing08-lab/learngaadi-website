<?php
/*
 * Local dev router — mirrors .htaccess so clean URLs work with PHP's built-in server.
 * Run from the project folder:  php -S localhost:8000 router.php
 * Not used on Hostinger (Apache reads .htaccess there).
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $path;

// /index or /index.html -> /
if (preg_match('#^/index(\.html)?$#i', $path)) {
    header('Location: /', true, 301);
    exit;
}

// /page.html -> /page
if (preg_match('#^/(.+)\.html$#i', $path, $m)) {
    header('Location: /' . $m[1], true, 301);
    exit;
}

// Existing real file or folder (css, js, images, api/*.php, ...): let PHP serve it
if ($path !== '/' && (is_file($file) || is_dir($file))) {
    return false;
}

// Clean URL -> matching .html file
if ($path !== '/' && is_file($file . '.html')) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($file . '.html');
    return true;
}

// Home
if ($path === '/') {
    header('Content-Type: text/html; charset=UTF-8');
    readfile(__DIR__ . '/index.html');
    return true;
}

http_response_code(404);
echo '404 Not Found';
