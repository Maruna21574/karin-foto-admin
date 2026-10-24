<?php
declare(strict_types=1);

/** @var array $gallery @var string $rest */

$token = $gallery['token'];

function client_photo(array $gallery, string $id): array
{
    $s = db()->prepare('SELECT * FROM photos WHERE id = ? AND gallery_id = ?');
    $s->execute([(int)$id, $gallery['id']]);
    return $s->fetch() ?: not_found();
}

// ---- stránka galérie
if ($rest === '') {
    $photos = gallery_photos((int)$gallery['id']);
    $sections = gallery_sections((int)$gallery['id']);
    render('gallery', compact('gallery', 'photos', 'sections'));
    exit;
}

// ---- náhľad pri zdieľaní odkazu: /og.jpg
if ($rest === 'og.jpg') {
    header('Cache-Control: public, max-age=86400');
    send_file(og_path($gallery), 'image/jpeg', null, false);
}

// ---- obrázky: /img/{id}/{thumb|view|full}
if (preg_match('#^img/(\d+)/(thumb|view|full)$#', $rest, $m)) {
    $photo = client_photo($gallery, $m[1]);
    $download = isset($_GET['dl']);
    switch ($m[2]) {
        case 'thumb':
            ensure_derivative('thumbs', $photo);
            send_file(photo_path('thumbs', $photo), 'image/webp');
        case 'view':
            ensure_derivative('views', $photo);
            send_file(photo_path('views', $photo), 'image/webp');
        case 'full':
            // Typ podľa prípony: stiahnutie nesmie závisieť od rozšírenia fileinfo, ktoré hosting nemusí mať.
            $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp']
                [strtolower(pathinfo($photo['filename'], PATHINFO_EXTENSION))] ?? 'application/octet-stream';
            send_file(photo_path('originals', $photo), $mime, $download ? $photo['orig_name'] : null);
    }
}

// ---- obľúbené: POST /fav/{id}
if (preg_match('#^fav/(\d+)$#', $rest, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $photo = client_photo($gallery, $m[1]);
    $new = $photo['favorite'] ? 0 : 1;
    db()->prepare('UPDATE photos SET favorite = ? WHERE id = ?')->execute([$new, $photo['id']]);
    $count = db()->prepare('SELECT COUNT(*) FROM photos WHERE gallery_id = ? AND favorite = 1');
    $count->execute([$gallery['id']]);
    json_out(['favorite' => (bool)$new, 'count' => (int)$count->fetchColumn()]);
}

// ---- ZIP: /zip, /zip?fav=1, /zip?section=ID
if ($rest === 'zip') {
    @set_time_limit(0);
    $onlyFav = isset($_GET['fav']);
    $sections = array_column(gallery_sections((int)$gallery['id']), 'title', 'id');
    $onlySection = isset($_GET['section']) ? valid_section((int)$gallery['id'], $_GET['section']) : null;
    $photos = array_values(array_filter(
        gallery_photos((int)$gallery['id']),
        fn($p) => (!$onlyFav || $p['favorite']) && (!$onlySection || (int)$p['section_id'] === $onlySection)
    ));
    $folder = function (array $p) use ($sections, $onlySection): string {
        if ($onlySection || !$sections) {
            return '';
        }
        $title = $sections[(int)$p['section_id']] ?? 'Ostatné';
        return trim(preg_replace('/[\\\\\/:*?"<>|]+/u', '-', $title), ' .-') . '/';
    };
    if (!$photos) {
        http_response_code(404);
        exit('Žiadne fotky na stiahnutie.');
    }
    $tmp = STORAGE . '/tmp/' . bin2hex(random_bytes(8)) . '.zip';
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE);
    $used = [];
    foreach ($photos as $p) {
        $file = photo_path('originals', $p);
        $name = $p['orig_name'];
        $path = $folder($p) . $name;
        if (isset($used[$path])) { // rovnaké meno súboru dvakrát
            $path = $folder($p) . pathinfo($name, PATHINFO_FILENAME) . '-' . $p['id'] . '.' . pathinfo($name, PATHINFO_EXTENSION);
        }
        $used[$path] = true;
        if ($file && is_file($file)) {
            $zip->addFile($file, $path);
            $zip->setCompressionName($path, ZipArchive::CM_STORE);
        }
    }
    $zip->close();
    $slugOf = fn(string $s) => slugify($s) ?: 'galeria';
    $zipName = 'attelierkay-' . $slugOf($gallery['title']) . ($onlySection ? '-' . $slugOf($sections[$onlySection]) : '')
        . ($onlyFav ? '-oblubene' : '') . '.zip';
    register_shutdown_function(fn() => @unlink($tmp));
    send_file($tmp, 'application/zip', $zipName, false);
}

not_found();
