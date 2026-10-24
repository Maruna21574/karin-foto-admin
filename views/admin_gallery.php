<?php
/** @var array $gallery @var array $photos @var array $sections */
$pageTitle = $gallery['title'];
$link = abs_url(client_path($gallery));
$linkPrefix = preg_replace('#^https?://#', '', abs_url('')); // napr. photos.attelierkay.sk/
$gid = (int)$gallery['id'];

$state = [
    'photos' => array_map(fn($p) => [
        'id' => (int)$p['id'],
        's' => (int)$p['section_id'],
        'name' => $p['orig_name'],
        'w' => (int)$p['width'],
        'h' => (int)$p['height'],
        'fav' => (bool)$p['favorite'],
    ], $photos),
    'sections' => array_map(fn($s) => ['id' => (int)$s['id'], 'title' => $s['title']], $sections),
    'cover' => (int)$gallery['cover_photo_id'],
];
require __DIR__ . '/_head.php';
?>
<body class="admin" data-og="<?= e(url("g/{$gallery['token']}/og.jpg")) ?>" data-base="<?= e(url("admin/g/$gid")) ?>" data-img="<?= e(url("g/{$gallery['token']}/img")) ?>" data-maxpx="<?= (int)cfg('max_full_px', 4000) ?>" data-csrf="<?= e(csrf_token()) ?>">
<header class="topbar">
  <a href="<?= e(url('admin')) ?>" class="brand"><img src="<?= e(url('assets/logo-mark.png')) ?>" alt=""><span>← Všetky galérie</span></a>
  <a class="link" href="<?= e(url('admin/logout')) ?>">Odhlásiť</a>
</header>
<main class="wrap">
  <section class="card">
    <h1 class="h-serif"><?= e($gallery['title']) ?></h1>
    <p class="muted"><?= e($gallery['client_name']) ?><?= $gallery['shoot_date'] ? ' · ' . e(format_date($gallery['shoot_date'])) : '' ?></p>
    <label class="linkbox">Odkaz pre klienta
      <span class="row">
        <input id="clientLink" readonly value="<?= e($link) ?>">
        <button class="btn" type="button" id="copyLink">Kopírovať</button>
        <a class="btn ghost" id="openLink" href="<?= e($link) ?>" target="_blank" rel="noopener">Otvoriť</a>
      </span>
    </label>

    <div class="slugbox">
      <label for="slugInput">Vlastná adresa galérie</label>
      <div class="slugrow">
        <span class="prefix"><?= e($linkPrefix) ?></span>
        <input id="slugInput" value="<?= e((string)$gallery['slug']) ?>" placeholder="stuzkova-teplice" maxlength="60" autocomplete="off" spellcheck="false">
        <button class="btn" type="button" id="slugSave">Uložiť adresu</button>
        <button class="mini" type="button" id="slugRandom" title="Pridá na koniec náhodný kód, aby sa adresa nedala uhádnuť">+ náhodný kód</button>
      </div>
      <p class="muted small" id="slugMsg">Krátka adresa sa dá ľahko uhádnuť. Ak má byť galéria súkromná, pridaj k nej náhodný kód. Pôvodný dlhý odkaz funguje stále.</p>
    </div>
  </section>

  <section class="card ogcard">
    <h2>Náhľad pri zdieľaní</h2>
    <p class="muted small">Takto sa zobrazí odkaz, keď ho klient pošle cez Messenger, WhatsApp alebo iMessage. Skladá sa z titulnej fotky, loga a tvojho kontaktu. Titulnú fotku zmeníš nižšie pri fotke tlačidlom „Nastaviť ako titulnú“.</p>
    <img id="ogPreview" src="<?= e(url("g/{$gallery['token']}/og.jpg")) ?>?v=<?= e(og_version($gallery)) ?>" alt="Náhľad pri zdieľaní" width="1200" height="630">
  </section>

  <section class="ws">
    <div class="tabsbar">
      <div class="seg" id="tabs"></div>
      <button type="button" class="mini" id="addSecBtn">+ Nová sekcia</button>
    </div>
    <form class="row addsec" id="addSecForm" hidden>
      <input name="title" placeholder="Názov sekcie, napr. Svadba" maxlength="80">
      <button class="btn" type="submit">Pridať</button>
      <button class="btn ghost" type="button" id="addSecCancel">Zrušiť</button>
    </form>

    <div class="secbar" id="secBar" hidden>
      <strong id="secName"></strong>
      <span class="s-actions">
        <button type="button" class="mini" data-sact="up" title="Posunúť vyššie">↑</button>
        <button type="button" class="mini" data-sact="down" title="Posunúť nižšie">↓</button>
        <button type="button" class="mini" data-sact="rename">Premenovať</button>
        <button type="button" class="mini danger" data-sact="delete">Zmazať sekciu</button>
      </span>
    </div>

    <div class="drop" id="drop">
      <p>Pretiahni sem fotky do sekcie <strong id="dropTarget"></strong></p>
      <label class="btn">Vybrať súbory<input type="file" id="fileInput" accept="image/jpeg,image/png,image/webp" multiple hidden></label>
      <p class="muted small">JPG, PNG alebo WebP. Veľké fotky sa automaticky zmenšia (najdlhšia strana <?= (int)cfg('max_full_px', 4000) ?> px) a pripravia sa rýchle náhľady. RAW súbory (CR2, NEF, ARW) treba najprv exportovať do JPG.</p>
    </div>
    <div id="queue" class="queue"></div>

    <div class="grid-head">
      <h2 class="section-title" id="countTitle"></h2>
      <div class="row">
        <button type="button" class="mini" id="selectAll">Vybrať všetky</button>
      </div>
    </div>
    <div id="photoGrid"></div>
  </section>

  <details class="card">
    <summary>Upraviť údaje / zmazať galériu</summary>
    <form class="grid-form" method="post" action="<?= e(url("admin/g/$gid/update")) ?>">
      <?= csrf_field() ?>
      <label>Názov<input name="title" value="<?= e($gallery['title']) ?>" required></label>
      <label>Meno klienta<input name="client_name" value="<?= e($gallery['client_name']) ?>"></label>
      <label>Dátum fotenia<input type="date" name="shoot_date" value="<?= e($gallery['shoot_date']) ?>"></label>
      <label class="full">Správa pre klienta<textarea name="message" rows="2"><?= e($gallery['message']) ?></textarea></label>
      <button class="btn" type="submit">Uložiť</button>
    </form>
    <form method="post" action="<?= e(url("admin/g/$gid/delete")) ?>" onsubmit="return confirm('Naozaj zmazať celú galériu aj s fotkami? Nedá sa vrátiť späť.')">
      <?= csrf_field() ?>
      <button class="btn danger" type="submit">Zmazať galériu</button>
    </form>
  </details>
</main>

<div class="selbar" id="selbar" hidden>
  <span><b id="selCount">0</b> vybraných</span>
  <span class="selbar-actions">
    <label class="inline">Presunúť do
      <select id="moveTo"></select>
    </label>
    <button type="button" class="btn" id="moveBtn">Presunúť</button>
    <button type="button" class="btn danger" id="delBtn">Zmazať</button>
    <button type="button" class="btn ghost" id="clearSel">Zrušiť výber</button>
  </span>
</div>

<script>window.GALLERY = <?= json_encode($state, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= e(url('assets/admin.js')) ?>?v=<?= filemtime(ROOT . '/public/assets/admin.js') ?>"></script>
</body></html>
