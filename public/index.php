<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$base = base_path();
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

// php -S / súbory z public
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

if ($path === '/') {
    render('home');
    exit;
}

/* ----------------------------- klient ----------------------------- */
if (preg_match('#^/g/([A-Za-z0-9_-]+)(?:/(.*))?$#', $path, $m)) {
    $gallery = gallery_by_token($m[1]);
    if (!$gallery) {
        not_found();
    }
    $rest = $m[2] ?? '';
    require ROOT . '/app/client.php';
    exit;
}

/* ----------------------------- admin ------------------------------ */
if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    $rest = trim(substr($path, 6), '/');
    require ROOT . '/app/admin.php';
    exit;
}

/* ------------------- vlastná adresa galérie ------------------- */
// photos.attelierkay.sk/stuzkova-teplice
if (preg_match('#^/([a-z0-9][a-z0-9-]*)(?:/(.*))?$#', $path, $m) && ($gallery = gallery_by_slug($m[1]))) {
    $rest = $m[2] ?? '';
    require ROOT . '/app/client.php';
    exit;
}

not_found();
