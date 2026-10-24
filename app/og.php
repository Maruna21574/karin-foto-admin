<?php
declare(strict_types=1);

/**
 * Náhľad pri zdieľaní odkazu (Messenger, WhatsApp, iMessage, Facebook…): obrázok 1200×630
 * z titulnej fotky galérie s pásom dole (logo, názov galérie, telefón a e-mail).
 */

const OG_W = 1200;
const OG_H = 630;
const OG_BAND = 170;

/** Titulná fotka galérie; ak nie je nastavená, prvá nahratá. */
function og_cover_photo(array $gallery): ?array
{
    if ($gallery['cover_photo_id']) {
        $s = db()->prepare('SELECT * FROM photos WHERE id = ? AND gallery_id = ?');
        $s->execute([$gallery['cover_photo_id'], $gallery['id']]);
        if ($p = $s->fetch()) {
            return $p;
        }
    }
    $s = db()->prepare('SELECT * FROM photos WHERE gallery_id = ? ORDER BY id LIMIT 1');
    $s->execute([$gallery['id']]);
    return $s->fetch() ?: null;
}

/** Mení sa vždy, keď sa zmení čokoľvek, z čoho sa obrázok skladá. */
function og_version(array $gallery): string
{
    $cover = og_cover_photo($gallery);
    return substr(md5(implode('|', [
        'v2', $gallery['title'], $cover['filename'] ?? '-', cfg('phone'), cfg('email'), cfg('site_name'),
        (string)@filemtime(ROOT . '/public/assets/logo-mark.png'),
    ])), 0, 10);
}

function og_path(array $gallery): string
{
    $dir = STORAGE . '/og';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $dest = "$dir/{$gallery['id']}-" . og_version($gallery) . '.jpg';
    if (!is_file($dest)) {
        og_render($gallery, $dest);
        foreach (glob("$dir/{$gallery['id']}-*.jpg") ?: [] as $old) { // staré verzie preč
            if ($old !== $dest) {
                @unlink($old);
            }
        }
    }
    return $dest;
}

/** URL obrázka vrátane verzie, aby si ho siete po zmene stiahli znova. */
function og_url(array $gallery): string
{
    return abs_url('g/' . $gallery['token'] . '/og.jpg') . '?v=' . og_version($gallery);
}

/** Zalomí text do najviac $maxLines riadkov šírky $maxW; null, ak sa nezmestí. */
function og_wrap(string $text, string $font, float $size, int $maxW, int $maxLines): ?array
{
    $width = function (string $s) use ($font, $size): int {
        $b = imagettfbbox($size, 0, $font, $s);
        return $b ? (int)($b[2] - $b[0]) : 0;
    };
    $lines = [];
    $cur = '';
    foreach (preg_split('/\s+/u', trim($text)) as $word) {
        $try = $cur === '' ? $word : "$cur $word";
        if ($width($try) <= $maxW) {
            $cur = $try;
        } elseif ($cur === '' || $width($word) > $maxW) {
            return null; // jedno slovo je širšie než miesto
        } else {
            $lines[] = $cur;
            $cur = $word;
        }
    }
    $lines[] = $cur;
    return count($lines) <= $maxLines ? $lines : null;
}

function og_render(array $gallery, string $dest): void
{
    $bold = realpath(ROOT . '/app/fonts/Manrope-Bold.ttf');
    $medium = realpath(ROOT . '/app/fonts/Manrope-Medium.ttf');
    $img = imagecreatetruecolor(OG_W, OG_H);
    imagealphablending($img, true);
    $ink = imagecolorallocate($img, 15, 15, 15);
    $gray = imagecolorallocate($img, 125, 125, 125);
    $line = imagecolorallocate($img, 231, 231, 231);
    $white = imagecolorallocate($img, 255, 255, 255);
    $soft = imagecolorallocate($img, 244, 243, 241);
    $bandTop = OG_H - OG_BAND;

    // --- pozadie: titulná fotka (zrezaná na plnú plochu), inak jemná farba
    $photo = ($p = og_cover_photo($gallery)) ? load_image(photo_path('views', $p)) : null;
    if ($photo) {
        $sw = imagesx($photo);
        $sh = imagesy($photo);
        $scale = max(OG_W / $sw, OG_H / $sh);
        $cw = (int)round(OG_W / $scale);
        $ch = (int)round(OG_H / $scale);
        $sx = (int)round(($sw - $cw) / 2);
        $sy = (int)round(($sh - $ch) * 0.35); // o niečo vyššie, aby ostali tváre
        imagecopyresampled($img, $photo, 0, 0, $sx, $sy, OG_W, OG_H, $cw, $ch);
    } else {
        imagefilledrectangle($img, 0, 0, OG_W, OG_H, $soft);
    }

    // --- biely pás dole
    imagefilledrectangle($img, 0, $bandTop, OG_W, OG_H, $white);

    // --- logo
    $logo = @imagecreatefrompng(ROOT . '/public/assets/logo-mark.png');
    if ($logo && $photo) { // bez fotky je logo veľké nad pásom, v páse by bolo dvakrát
        $lh = 108;
        $lw = (int)round(imagesx($logo) * $lh / imagesy($logo));
        $ly = $bandTop + (int)((OG_BAND - $lh) / 2);
        imagecopyresampled($img, $logo, 48, $ly, 0, 0, $lw, $lh, imagesx($logo), imagesy($logo));
        $textX = 48 + $lw + 40;
    } else {
        $textX = 48;
    }
    if (!$photo && $logo) { // galéria ešte nemá fotky: veľké logo v hornej časti
        $bh = 250;
        $bw = (int)round(imagesx($logo) * $bh / imagesy($logo));
        imagecopyresampled($img, $logo, (int)((OG_W - $bw) / 2), 90, 0, 0, $bw, $bh, imagesx($logo), imagesy($logo));
    }

    // --- pravý blok: telefón + e-mail
    $phone = (string)cfg('phone', '');
    $email = (string)cfg('email', '');
    $right = OG_W - 48;
    $blockW = 0;
    $tw = fn(string $t, string $f, float $s) => ($b = imagettfbbox($s, 0, $f, $t)) ? (int)($b[2] - $b[0]) : 0;
    if ($phone || $email) {
        $blockW = max($phone ? $tw($phone, $bold, 34) : 0, $email ? $tw($email, $medium, 22) : 0);
        $by = $bandTop + ($phone && $email ? 78 : 96);
        if ($phone) {
            imagettftext($img, 34, 0, $right - $tw($phone, $bold, 34), $by, $ink, $bold, $phone);
        }
        if ($email) {
            imagettftext($img, 22, 0, $right - $tw($email, $medium, 22), $by + ($phone ? 42 : 0), $gray, $medium, $email);
        }
        $sepX = $right - $blockW - 36;
        imageline($img, $sepX, $bandTop + 34, $sepX, OG_H - 34, $line);
    } else {
        $sepX = OG_W - 48;
    }

    // --- názov galérie (1 až 2 riadky, veľkosť sa prispôsobí) a značka
    $maxW = $sepX - $textX - 32;
    $title = trim((string)$gallery['title']);
    $lines = null;
    $size = 46;
    for (; $size >= 26 && $lines === null; $size -= 2) {
        $lines = og_wrap($title, $bold, $size, $maxW, $size > 34 ? 2 : 3);
    }
    if ($lines === null) { // nezmestí sa ani zmenšený: skráť po celých slovách
        $size = 28;
        $words = preg_split('/\s+/u', $title);
        while (count($words) > 1 && !og_wrap(implode(' ', $words) . '…', $bold, $size, $maxW, 3)) {
            array_pop($words);
        }
        $lines = og_wrap(implode(' ', $words) . '…', $bold, $size, $maxW, 3) ?: [mb_substr($title, 0, 24) . '…'];
    } else {
        $size += 2;
    }
    $brand = (string)cfg('site_name', 'Attelier Kay');
    $lineH = (int)round($size * (count($lines) > 1 ? 1.32 : 1.22));
    $blockH = count($lines) * $lineH + (count($lines) === 1 ? 40 : 0);
    $y = $bandTop + (int)((OG_BAND - $blockH) / 2) + $size;
    foreach ($lines as $l) {
        imagettftext($img, $size, 0, $textX, $y, $ink, $bold, $l);
        $y += $lineH;
    }
    if (count($lines) === 1) {
        imagettftext($img, 22, 0, $textX, $y - $lineH + 40, $gray, $medium, $brand);
    }

    imagejpeg($img, $dest, 88);
}
