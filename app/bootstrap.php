<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('STORAGE', ROOT . '/storage');

// Chyby sa nesmú vypísať do odpovede, inak poškodia sťahované súbory (ZIP, obrázky). Idú do logu.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', ROOT . '/storage/php-error.log');

$config = require ROOT . '/config.php';

/**
 * Beží web cez HTTPS? Hosting často ukončuje HTTPS na proxy a PHP potom vidí obyčajné HTTP,
 * preto sa okrem hlavičiek berie za HTTPS aj každá verejná adresa (nie localhost, *.test ani IP).
 * Odkazy pre klienta, cookie a obrázok pre zdieľanie tak vždy začínajú na https://.
 */
function is_https(): bool
{
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    $host = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
    $local = $host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)
        || str_ends_with($host, '.test') || str_ends_with($host, '.local') || str_ends_with($host, '.localhost');
    return !$local;
}

session_name('kay_admin');
ini_set('session.use_strict_mode', '1'); // server neprijme session id, ktoré si sám nevydal
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => is_https(), // na HTTPS sa cookie nikdy nepošle cez nezabezpečené spojenie
    'path' => '/',
]);
session_start();

require __DIR__ . '/og.php';

foreach (['originals', 'thumbs', 'views', 'og', 'tmp'] as $d) {
    if (!is_dir(STORAGE . "/$d")) {
        mkdir(STORAGE . "/$d", 0775, true);
    }
}

function cfg(string $key, $default = null)
{
    global $config;
    return $config[$key] ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . STORAGE . '/db.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE IF NOT EXISTS galleries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        client_name TEXT NOT NULL DEFAULT "",
        shoot_date TEXT NOT NULL DEFAULT "",
        message TEXT NOT NULL DEFAULT "",
        cover_photo_id INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        gallery_id INTEGER NOT NULL REFERENCES galleries(id) ON DELETE CASCADE,
        filename TEXT NOT NULL,
        orig_name TEXT NOT NULL,
        width INTEGER NOT NULL,
        height INTEGER NOT NULL,
        size INTEGER NOT NULL,
        favorite INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_photos_gallery ON photos(gallery_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip, at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS sections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        gallery_id INTEGER NOT NULL REFERENCES galleries(id) ON DELETE CASCADE,
        title TEXT NOT NULL,
        position INTEGER NOT NULL DEFAULT 0
    )');
    $gcols = array_column($pdo->query('PRAGMA table_info(galleries)')->fetchAll(), 'name');
    if (!in_array('slug', $gcols, true)) {
        $pdo->exec('ALTER TABLE galleries ADD COLUMN slug TEXT');
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_galleries_slug ON galleries(slug)');
    $cols = array_column($pdo->query('PRAGMA table_info(photos)')->fetchAll(), 'name');
    if (!in_array('section_id', $cols, true)) {
        $pdo->exec('ALTER TABLE photos ADD COLUMN section_id INTEGER');
    }
    return $pdo;
}

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** URL prefix, under which the app lives ('' on a vhost, '/karin_galeria' under localhost). */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    // root .htaccess rewrites to /public, but the visitor's URL doesn't contain it
    if (str_ends_with($base, '/public') && !str_starts_with($uri, $base)) {
        $base = substr($base, 0, -7);
    }
    return $base;
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function abs_url(string $path = ''): string
{
    return (is_https() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . url($path);
}

/* ---------- ochrana prihlásenia pred hádaním hesla ---------- */
const LOGIN_MAX_FAILS = 5;        // zlých pokusov z jednej adresy
const LOGIN_MAX_FAILS_ALL = 40;   // zlých pokusov zo všetkých adries dokopy (útok z viacerých IP)
const LOGIN_WINDOW = 15 * 60;     // okno aj dĺžka zámku v sekundách

function client_ip(): string
{
    // Zámerne len REMOTE_ADDR: hlavičku X-Forwarded-For si môže vymyslieť útočník.
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/** Koľko sekúnd ešte trvá zámok (0 = prihlasovať sa môže). */
function login_lock_seconds(): int
{
    $since = time() - LOGIN_WINDOW;
    db()->prepare('DELETE FROM login_attempts WHERE at < ?')->execute([$since]);

    $s = db()->prepare('SELECT COUNT(*), MIN(at) FROM (SELECT at FROM login_attempts WHERE ip = ? ORDER BY at DESC LIMIT ?)');
    $s->execute([client_ip(), LOGIN_MAX_FAILS]);
    [$count, $oldest] = $s->fetch(PDO::FETCH_NUM);
    if ((int)$count >= LOGIN_MAX_FAILS) {
        return max(1, (int)$oldest + LOGIN_WINDOW - time());
    }
    $all = (int)db()->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
    if ($all >= LOGIN_MAX_FAILS_ALL) {
        $oldest = (int)db()->query('SELECT MIN(at) FROM login_attempts')->fetchColumn();
        return max(1, $oldest + LOGIN_WINDOW - time());
    }
    return 0;
}

function login_record_failure(): void
{
    db()->prepare('INSERT INTO login_attempts (ip, at) VALUES (?, ?)')->execute([client_ip(), time()]);
}

function login_clear_failures(): void
{
    db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function render(string $view, array $vars = []): void
{
    extract($vars);
    require ROOT . "/views/$view.php";
}

function not_found(): never
{
    http_response_code(404);
    render('404');
    exit;
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!hash_equals(csrf_token(), (string)$sent)) {
        json_out(['error' => 'Neplatný bezpečnostný token, obnov stránku.'], 419);
    }
}

function require_admin(): void
{
    if (empty($_SESSION['admin'])) {
        redirect('admin/login');
    }
}

function format_date(string $d): string
{
    $t = strtotime($d);
    return $t ? date('j. n. Y', $t) : '';
}

function format_bytes(int $b): string
{
    return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024) . ' kB';
}

/* ---------- galleries & photos ---------- */

function gallery_by_token(string $token): ?array
{
    $s = db()->prepare('SELECT * FROM galleries WHERE token = ?');
    $s->execute([$token]);
    return $s->fetch() ?: null;
}

function gallery_by_slug(string $slug): ?array
{
    $s = db()->prepare('SELECT * FROM galleries WHERE slug = ?');
    $s->execute([$slug]);
    return $s->fetch() ?: null;
}

/** "Stužková / Teplice" → "stuzkova-teplice" */
function slugify(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, [
        'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ĺ' => 'l', 'ľ' => 'l',
        'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ŕ' => 'r', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
        'ů' => 'u', 'ü' => 'u', 'ý' => 'y', 'ž' => 'z',
    ]);
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

/** Adresy, ktoré patria aplikácii a nesmú byť názvom galérie. */
const RESERVED_SLUGS = ['admin', 'g', 'assets', 'storage', 'public', 'app', 'views', 'index', 'index-php', 'api',
    'login', 'logout', 'robots-txt', 'favicon-ico', 'sitemap-xml'];

/** Odkaz, ktorý sa dáva klientovi: vlastná adresa, inak dlhý odkaz. */
function client_path(array $gallery): string
{
    return $gallery['slug'] ? $gallery['slug'] : 'g/' . $gallery['token'];
}

function gallery_by_id(int $id): ?array
{
    $s = db()->prepare('SELECT * FROM galleries WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function gallery_photos(int $galleryId): array
{
    $s = db()->prepare('SELECT * FROM photos WHERE gallery_id = ? ORDER BY id');
    $s->execute([$galleryId]);
    $rows = $s->fetchAll();
    // prirodzené radenie: IMG_2 pred IMG_10
    usort($rows, fn($a, $b) => strnatcasecmp($a['orig_name'], $b['orig_name']) ?: $a['id'] <=> $b['id']);
    return $rows;
}

function gallery_sections(int $galleryId): array
{
    $s = db()->prepare('SELECT * FROM sections WHERE gallery_id = ? ORDER BY position, id');
    $s->execute([$galleryId]);
    return $s->fetchAll();
}

/** Skontroluje, že sekcia patrí do galérie; vráti jej id alebo null. */
function valid_section(int $galleryId, $sectionId): ?int
{
    $sectionId = (int)$sectionId;
    if ($sectionId <= 0) {
        return null;
    }
    $s = db()->prepare('SELECT id FROM sections WHERE id = ? AND gallery_id = ?');
    $s->execute([$sectionId, $galleryId]);
    return $s->fetchColumn() ? $sectionId : null;
}

function photo_path(string $kind, array $photo): string
{
    $name = $photo['filename'];
    if ($kind === 'thumbs' || $kind === 'views') {
        $name = pathinfo($name, PATHINFO_FILENAME) . '.webp';
    }
    return STORAGE . "/$kind/{$photo['gallery_id']}/$name";
}

function new_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'ab'), '=');
}

/* ---------- images ---------- */

function load_image(string $path): ?GdImage
{
    $info = @getimagesize($path);
    if (!$info) {
        return null;
    }
    // GD drží obrázok v pamäti rozbalený (~5 B/px), veľké súbory treba pohlídať
    $need = (int)($info[0] * $info[1] * 5.5) + 64 * 1048576;
    $limit = ini_parse_quantity((string)ini_get('memory_limit'));
    if ($limit > 0 && $need > $limit) {
        @ini_set('memory_limit', (string)$need);
        $limit = ini_parse_quantity((string)ini_get('memory_limit'));
        if ($limit > 0 && $need > $limit) {
            return null;
        }
    }
    $img = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        default => false,
    };
    if (!$img) {
        return null;
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $o = $exif['Orientation'] ?? 1;
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle) {
            $img = imagerotate($img, $angle, 0) ?: $img;
        }
    }
    return $img;
}

function save_resized(GdImage $src, string $dest, int $maxSide, int $quality, string $format = 'webp'): void
{
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $maxSide / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $out = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    if (!is_dir(dirname($dest))) {
        mkdir(dirname($dest), 0775, true);
    }
    $format === 'jpeg' ? imagejpeg($out, $dest, $quality) : imagewebp($out, $dest, $quality);
}

/** Náhľady sa dopočítajú z uloženého súboru, ak chýbajú (napr. po zmene formátu). */
function ensure_derivative(string $kind, array $photo): void
{
    $dest = photo_path($kind, $photo);
    if (is_file($dest)) {
        return;
    }
    $img = load_image(photo_path('originals', $photo));
    if ($img) {
        [$side, $q] = $kind === 'thumbs' ? [700, 78] : [2000, 82];
        save_resized($img, $dest, $side, $q);
    }
}

function send_file(string $path, string $mime, ?string $downloadName = null, bool $cache = true): never
{
    if (!is_file($path)) {
        not_found();
    }
    while (ob_get_level()) { // nič nesmie predbehnúť hlavičky ani sa pridať do súboru
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    if ($cache) {
        header('Cache-Control: private, max-age=604800');
    }
    if ($downloadName) {
        // ASCII názov pre staré prehliadače + správny UTF-8 názov (RFC 5987), ktorý používa Safari na iPhone
        $ascii = trim((string)preg_replace('/[^A-Za-z0-9._ -]+/', '_', slugify(pathinfo($downloadName, PATHINFO_FILENAME)) . '.' . pathinfo($downloadName, PATHINFO_EXTENSION)), '. ');
        header('Content-Disposition: attachment; filename="' . ($ascii ?: 'subor') . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    }
    readfile($path);
    exit;
}

function delete_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function delete_photo_files(array $photo): void
{
    foreach (['originals', 'thumbs', 'views'] as $k) {
        @unlink(photo_path($k, $photo));
    }
}
