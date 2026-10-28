<?php
declare(strict_types=1);

/** @var string $rest */

$method = $_SERVER['REQUEST_METHOD'];

// ---- prihlásenie
if ($rest === 'login') {
    $error = null;
    $locked = login_lock_seconds(); // pri zamknutí sa heslo vôbec neoveruje
    if ($method === 'POST' && !$locked) {
        if (!hash_equals(csrf_token(), (string)($_POST['_csrf'] ?? ''))) {
            $error = 'Stránka je príliš stará, skús to znova.';
        } else {
            $emailOk = hash_equals(mb_strtolower((string)cfg('admin_email')), mb_strtolower(trim((string)($_POST['email'] ?? ''))));
            // password_verify sa spustí vždy, aby odpoveď nevyzradila, či sedí e-mail
            $passOk = password_verify((string)($_POST['password'] ?? ''), (string)cfg('admin_hash'));
            if ($emailOk && $passOk) {
                login_clear_failures();
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                redirect('admin');
            }
            login_record_failure();
            sleep(1);
            $locked = login_lock_seconds();
            $error = 'Nesprávny e-mail alebo heslo.';
        }
    }
    if ($locked) {
        http_response_code(429);
        header('Retry-After: ' . $locked);
        $mins = (int)ceil($locked / 60);
        $error = 'Príliš veľa nesprávnych pokusov. Skús to znova o ' . $mins . ' '
            . ($mins === 1 ? 'minútu' : ($mins <= 4 ? 'minúty' : 'minút')) . '.';
    }
    render('admin_login', compact('error', 'locked'));
    exit;
}

if ($rest === 'logout') {
    $_SESSION = [];
    session_destroy();
    redirect('admin/login');
}

require_admin();

if ($method === 'POST') {
    csrf_check();
}

// ---- zoznam galérií
if ($rest === '') {
    $galleries = db()->query('SELECT g.*,
        (SELECT COUNT(*) FROM photos p WHERE p.gallery_id = g.id) AS photo_count,
        (SELECT COUNT(*) FROM photos p WHERE p.gallery_id = g.id AND p.favorite = 1) AS fav_count
        FROM galleries g ORDER BY g.id DESC')->fetchAll();
    render('admin_list', compact('galleries'));
    exit;
}

// ---- nová galéria
if ($rest === 'g/new' && $method === 'POST') {
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') {
        redirect('admin');
    }
    db()->prepare('INSERT INTO galleries (token, title, client_name, shoot_date, message) VALUES (?,?,?,?,?)')
        ->execute([
            new_token(),
            mb_substr($title, 0, 120),
            mb_substr(trim((string)($_POST['client_name'] ?? '')), 0, 120),
            (string)($_POST['shoot_date'] ?? ''),
            mb_substr(trim((string)($_POST['message'] ?? '')), 0, 1000),
        ]);
    redirect('admin/g/' . db()->lastInsertId());
}

if (!preg_match('#^g/(\d+)(?:/(.*))?$#', $rest, $m)) {
    not_found();
}
$gallery = gallery_by_id((int)$m[1]) ?? not_found();
$sub = $m[2] ?? '';

// ---- správa galérie
if ($sub === '') {
    $photos = gallery_photos((int)$gallery['id']);
    $sections = gallery_sections((int)$gallery['id']);
    render('admin_gallery', compact('gallery', 'photos', 'sections'));
    exit;
}

if ($method !== 'POST') {
    not_found();
}

// ---- úprava údajov
if ($sub === 'update') {
    $title = trim((string)($_POST['title'] ?? ''));
    db()->prepare('UPDATE galleries SET title = ?, client_name = ?, shoot_date = ?, message = ? WHERE id = ?')
        ->execute([
            $title !== '' ? mb_substr($title, 0, 120) : $gallery['title'],
            mb_substr(trim((string)($_POST['client_name'] ?? '')), 0, 120),
            (string)($_POST['shoot_date'] ?? ''),
            mb_substr(trim((string)($_POST['message'] ?? '')), 0, 1000),
            $gallery['id'],
        ]);
    redirect('admin/g/' . $gallery['id']);
}

// ---- vlastná adresa galérie (prázdna = vráti sa dlhý odkaz)
if ($sub === 'slug') {
    $raw = trim((string)($_POST['slug'] ?? ''));
    $slug = slugify($raw);
    if ($raw !== '') {
        if (strlen($slug) < 3 || strlen($slug) > 60) {
            json_out(['error' => 'Adresa musí mať 3 až 60 znakov (písmená, čísla a pomlčky).'], 422);
        }
        if (in_array($slug, RESERVED_SLUGS, true)) {
            json_out(['error' => 'Táto adresa je vyhradená, vyber inú.'], 422);
        }
        $taken = gallery_by_slug($slug);
        if ($taken && (int)$taken['id'] !== (int)$gallery['id']) {
            json_out(['error' => 'Túto adresu už používa iná galéria.'], 409);
        }
    }
    db()->prepare('UPDATE galleries SET slug = ? WHERE id = ?')->execute([$raw === '' ? null : $slug, $gallery['id']]);
    $gallery['slug'] = $raw === '' ? null : $slug;
    json_out(['ok' => true, 'slug' => $gallery['slug'] ?? '', 'link' => abs_url(client_path($gallery))]);
}

// ---- zmazanie galérie
if ($sub === 'delete') {
    db()->prepare('DELETE FROM galleries WHERE id = ?')->execute([$gallery['id']]);
    foreach (['originals', 'thumbs', 'views'] as $k) {
        delete_dir(STORAGE . "/$k/{$gallery['id']}");
    }
    redirect('admin');
}

// ---- nahrávanie jednej fotky (volá JS opakovane)
if ($sub === 'upload') {
    $f = $_FILES['photo'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        json_out(['error' => 'Nahrávanie zlyhalo (kód ' . ($f['error'] ?? '?') . ').'], 400);
    }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        json_out(['error' => 'Podporované sú iba JPG, PNG a WebP.'], 415);
    }
    $img = load_image($f['tmp_name']) ?? json_out(['error' => 'Obrázok sa nepodarilo načítať (je asi príliš veľký).'], 422);

    $gid = (int)$gallery['id'];
    $maxFull = (int)cfg('max_full_px', 4000);
    // Stiahnuteľná verzia: malá fotka ostane tak, ako je; veľká sa zmenší a uloží ako JPEG.
    $keep = $info[2] === IMAGETYPE_JPEG && max($info[0], $info[1]) <= $maxFull && filesize($f['tmp_name']) <= 12 * 1048576;
    $filename = bin2hex(random_bytes(8)) . '.jpg';
    foreach (['originals', 'thumbs', 'views'] as $k) {
        if (!is_dir(STORAGE . "/$k/$gid")) {
            mkdir(STORAGE . "/$k/$gid", 0775, true);
        }
    }
    $photo = ['gallery_id' => $gid, 'filename' => $filename];
    if ($keep) {
        move_uploaded_file($f['tmp_name'], photo_path('originals', $photo));
    } else {
        save_resized($img, photo_path('originals', $photo), $maxFull, 90, 'jpeg');
    }
    save_resized($img, photo_path('thumbs', $photo), 700, 78);
    save_resized($img, photo_path('views', $photo), 2000, 82);

    $origName = pathinfo(basename((string)$f['name']), PATHINFO_FILENAME) . '.jpg';
    db()->prepare('INSERT INTO photos (gallery_id, filename, orig_name, width, height, size, section_id) VALUES (?,?,?,?,?,?,?)')
        ->execute([$gid, $filename, mb_substr($origName, 0, 200), imagesx($img), imagesy($img), (int)filesize(photo_path('originals', $photo)), valid_section($gid, $_POST['section_id'] ?? 0)]);
    $pid = (int)db()->lastInsertId();
    if (!$gallery['cover_photo_id']) {
        db()->prepare('UPDATE galleries SET cover_photo_id = ? WHERE id = ?')->execute([$pid, $gid]);
    }
    json_out(['ok' => true, 'photo' => [
        'id' => $pid,
        's' => valid_section($gid, $_POST['section_id'] ?? 0) ?? 0,
        'name' => $origName,
        'w' => imagesx($img),
        'h' => imagesy($img),
        'fav' => false,
    ], 'cover' => (int)($gallery['cover_photo_id'] ?: $pid)]);
}

// ---- sekcie
if ($sub === 'section/add') {
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 80);
    if ($title === '') {
        json_out(['error' => 'Zadaj názov sekcie.'], 422);
    }
    $pos = (int)db()->query('SELECT COALESCE(MAX(position), 0) + 1 FROM sections WHERE gallery_id = ' . (int)$gallery['id'])->fetchColumn();
    db()->prepare('INSERT INTO sections (gallery_id, title, position) VALUES (?,?,?)')->execute([$gallery['id'], $title, $pos]);
    json_out(['ok' => true, 'id' => (int)db()->lastInsertId(), 'title' => $title]);
}

if (preg_match('#^section/(\d+)/(rename|delete|up|down|cover)$#', $sub, $sm)) {
    $sid = valid_section((int)$gallery['id'], $sm[1]) ?? not_found();
    switch ($sm[2]) {
        case 'rename':
            $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 80);
            if ($title === '') {
                json_out(['error' => 'Zadaj názov sekcie.'], 422);
            }
            db()->prepare('UPDATE sections SET title = ? WHERE id = ?')->execute([$title, $sid]);
            break;
        case 'delete': // fotky ostanú v galérii, len bez sekcie
            db()->prepare('UPDATE photos SET section_id = NULL WHERE section_id = ?')->execute([$sid]);
            db()->prepare('DELETE FROM sections WHERE id = ?')->execute([$sid]);
            break;
        case 'cover': // titulná fotka sekcie (0 = žiadna, použije sa prvá fotka)
            $s = db()->prepare('SELECT id FROM photos WHERE id = ? AND gallery_id = ?');
            $s->execute([(int)($_POST['photo_id'] ?? 0), $gallery['id']]);
            $pid = (int)$s->fetchColumn();
            db()->prepare('UPDATE sections SET cover_photo_id = ? WHERE id = ?')->execute([$pid ?: null, $sid]);
            json_out(['ok' => true, 'cover' => $pid]);
        default: // up / down: prehodí poradie so susedom
            $all = array_column(gallery_sections((int)$gallery['id']), 'id');
            $i = array_search($sid, array_map('intval', $all), true);
            $j = $sm[2] === 'up' ? $i - 1 : $i + 1;
            if ($i !== false && isset($all[$j])) {
                [$all[$i], $all[$j]] = [$all[$j], $all[$i]];
                $u = db()->prepare('UPDATE sections SET position = ? WHERE id = ?');
                foreach ($all as $pos => $id) {
                    $u->execute([$pos, $id]);
                }
            }
    }
    json_out(['ok' => true]);
}

/** Zoznam id fotiek z poľa "ids" (čiarkami oddelené), len tie, čo patria do tejto galérie. */
function posted_photo_ids(array $gallery): array
{
    $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
    if (!$ids) {
        json_out(['error' => 'Nie sú vybraté žiadne fotky.'], 422);
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $s = db()->prepare("SELECT id FROM photos WHERE gallery_id = ? AND id IN ($in)");
    $s->execute([$gallery['id'], ...array_values($ids)]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

// ---- hromadne: presunúť fotky do sekcie (section_id 0 = bez sekcie)
if ($sub === 'photos/move') {
    $ids = posted_photo_ids($gallery);
    $sec = valid_section((int)$gallery['id'], $_POST['section_id'] ?? 0);
    $in = implode(',', $ids);
    db()->prepare("UPDATE photos SET section_id = ? WHERE id IN ($in)")->execute([$sec]);
    json_out(['ok' => true, 'ids' => $ids, 'section_id' => $sec ?? 0]);
}

// ---- hromadne: zmazať fotky
if ($sub === 'photos/delete') {
    $ids = posted_photo_ids($gallery);
    $in = implode(',', $ids);
    $rows = db()->query("SELECT * FROM photos WHERE id IN ($in)")->fetchAll();
    db()->exec("DELETE FROM photos WHERE id IN ($in)");
    db()->exec("UPDATE sections SET cover_photo_id = NULL WHERE cover_photo_id IN ($in)");
    foreach ($rows as $row) {
        delete_photo_files($row);
    }
    $cover = (int)$gallery['cover_photo_id'];
    if (in_array($cover, $ids, true)) {
        $next = db()->prepare('SELECT id FROM photos WHERE gallery_id = ? ORDER BY id LIMIT 1');
        $next->execute([$gallery['id']]);
        $cover = (int)$next->fetchColumn();
        db()->prepare('UPDATE galleries SET cover_photo_id = ? WHERE id = ?')->execute([$cover ?: null, $gallery['id']]);
    }
    json_out(['ok' => true, 'ids' => $ids, 'cover' => $cover]);
}

// ---- titulná fotka (zobrazí sa v zozname galérií)
if (preg_match('#^photo/(\d+)/cover$#', $sub, $pm)) {
    $s = db()->prepare('SELECT id FROM photos WHERE id = ? AND gallery_id = ?');
    $s->execute([(int)$pm[1], $gallery['id']]);
    $pid = $s->fetchColumn() ?: not_found();
    db()->prepare('UPDATE galleries SET cover_photo_id = ? WHERE id = ?')->execute([$pid, $gallery['id']]);
    json_out(['ok' => true, 'cover' => (int)$pid]);
}

not_found();
