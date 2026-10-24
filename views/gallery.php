<?php
/** @var array $gallery @var array $photos */
$pageTitle = $gallery['title'];
$t = $gallery['token'];
$total = count($photos);
$favCount = array_sum(array_column($photos, 'favorite'));
$data = array_map(fn($p) => [
    'id' => (int)$p['id'],
    'w' => (int)$p['width'],
    'h' => (int)$p['height'],
    'fav' => (bool)$p['favorite'],
    's' => $p['section_id'] ? (int)$p['section_id'] : 0,
], $photos);
$secData = array_map(fn($s) => ['id' => (int)$s['id'], 'title' => $s['title']], $sections);
$booking = cfg('booking_url', cfg('website'));

// náhľad pri zdieľaní odkazu (Messenger, WhatsApp, iMessage, Facebook…)
$ogTitle = $gallery['title'] . ' · ' . cfg('site_name');
$ogDesc = 'Tvoje fotky od ' . cfg('site_name') . '. Rezervácia termínu: '
    . implode(' · ', array_filter([(string)cfg('phone'), (string)cfg('email')]));
$ogMeta = [
    ['property', 'og:type', 'website'],
    ['property', 'og:site_name', cfg('site_name')],
    ['property', 'og:locale', 'sk_SK'],
    ['property', 'og:title', $ogTitle],
    ['property', 'og:description', $ogDesc],
    ['property', 'og:url', abs_url(client_path($gallery))],
    ['property', 'og:image', og_url($gallery)],
    ['property', 'og:image:type', 'image/jpeg'],
    ['property', 'og:image:width', (string)OG_W],
    ['property', 'og:image:height', (string)OG_H],
    ['property', 'og:image:alt', $gallery['title'] . ' · ' . cfg('site_name')],
    ['name', 'twitter:card', 'summary_large_image'],
    ['name', 'twitter:title', $ogTitle],
    ['name', 'twitter:description', $ogDesc],
    ['name', 'twitter:image', og_url($gallery)],
    ['name', 'description', $ogDesc],
];
$headExtra = '';
foreach ($ogMeta as [$attr, $name, $content]) {
    $headExtra .= '<meta ' . $attr . '="' . e($name) . '" content="' . e($content) . '">' . "\n";
}

$icoDl ='<svg viewBox="0 0 24 24"><path d="M12 4v11m0 0l-4-4m4 4l4-4M5 19.5h14"/></svg>';
$icoHeart = '<svg viewBox="0 0 24 24"><path d="M12 20s-7-4.4-7-10a4 4 0 017-2.6A4 4 0 0119 10c0 5.6-7 10-7 10z"/></svg>';
$icoBw = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 4a8 8 0 010 16z" fill="currentColor"/></svg>';
$icoShare = '<svg viewBox="0 0 24 24"><path d="M12 15V4m0 0L8 8m4-4l4 4M6 12v6a2 2 0 002 2h8a2 2 0 002-2v-6"/></svg>';
$icoClose = '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>';
require __DIR__ . '/_head.php';
?>
<body class="client" data-base="<?= e(url("g/$t")) ?>">

<header class="site" id="site">
  <a class="mark" href="#top" aria-label="Attelier Kay"><img src="<?= e(url('assets/logo-mark.png')) ?>" alt="Attelier Kay"></a>

  <div class="seg" id="seg" role="tablist">
    <button type="button" class="seg-btn active" data-filter="all">Všetky <span id="cntAll"><?= $total ?></span></button>
    <?php foreach ($sections as $s): ?>
      <button type="button" class="seg-btn" data-filter="<?= (int)$s['id'] ?>"><?= e($s['title']) ?> <span data-seccount="<?= (int)$s['id'] ?>"></span></button>
    <?php endforeach; ?>
    <button type="button" class="seg-btn" data-filter="fav">Obľúbené <span id="cntFav"><?= (int)$favCount ?></span></button>
  </div>

  <details class="menu" id="dlMenu">
    <summary class="dl"><?= $icoDl ?><span>Stiahnuť</span></summary>
    <div class="pop">
      <a href="<?= e(url("g/$t/zip")) ?>"><?= $icoDl ?><span><b>Všetky fotky</b><small><?= $total ?> fotiek · ZIP</small></span></a>
      <a href="#" id="dlSec" hidden><?= $icoDl ?><span><b></b><small>ZIP</small></span></a>
      <a href="<?= e(url("g/$t/zip?fav=1")) ?>" data-fav><?= $icoHeart ?><span><b>Obľúbené</b><small><i data-favn><?= (int)$favCount ?></i> fotiek · ZIP</small></span></a>
      <a href="<?= e(url("g/$t/zip?fav=1&bw=1")) ?>" data-fav><?= $icoBw ?><span><b>Obľúbené čiernobielo</b><small><i data-favn><?= (int)$favCount ?></i> fotiek · ZIP</small></span></a>
    </div>
  </details>
</header>

<main id="top">
  <section class="head">
    <h1><?= e($gallery['title']) ?></h1>
    <div class="head-side">
      <dl class="facts">
        <?php if ($gallery['client_name']): ?><div><dt>Klient</dt><dd><?= e($gallery['client_name']) ?></dd></div><?php endif; ?>
        <?php if ($gallery['shoot_date']): ?><div><dt>Dátum</dt><dd><?= e(format_date($gallery['shoot_date'])) ?></dd></div><?php endif; ?>
        <div><dt>Fotiek</dt><dd><?= $total ?></dd></div>
      </dl>
      <?php if ($gallery['message']): ?><p class="msg"><?= nl2br(e($gallery['message'])) ?></p><?php endif; ?>
    </div>
  </section>

  <?php if (!$photos): ?><p class="empty">Fotky sa ešte nahrávajú, skús to o chvíľu.</p><?php endif; ?>
  <div id="grid"></div>
  <p class="empty" id="emptyFav" hidden>Zatiaľ tu nič nie je. Srdiečkom pri fotke ju pridáš do obľúbených.</p>
</main>

<?php
$phone = (string)cfg('phone', '');
$email = (string)cfg('email', '');
$tel = preg_replace('/\D+/', '', $phone);
if (str_starts_with($tel, '0')) {
    $tel = '421' . substr($tel, 1); // 0919… → +421919…
}
?>
<?php if ($phone || $email): ?>
<section class="contact">
  <div class="contact-in">
    <div>
      <h2>Páčia sa ti fotky?</h2>
      <p>Ak plánuješ ďalšie fotenie, ozvi sa a dohodneme termín.</p>
    </div>
    <div class="contact-links">
      <?php if ($phone): ?>
        <a href="tel:+<?= e($tel) ?>">
          <svg viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A15 15 0 013 6a2 2 0 012-2z"/></svg>
          <span><small>Zavolaj</small><b><?= e($phone) ?></b></span>
        </a>
      <?php endif; ?>
      <?php if ($email): ?>
        <a href="mailto:<?= e($email) ?>?subject=<?= e(rawurlencode('Rezervácia termínu')) ?>">
          <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 7l8.5 6 8.5-6"/></svg>
          <span><small>Napíš</small><b><?= e($email) ?></b></span>
        </a>
      <?php endif; ?>
      <a class="primary" href="<?= e($booking) ?>">Rezervovať termín
        <svg viewBox="0 0 24 24"><path d="M5 12h14m0 0l-6-6m6 6l-6 6"/></svg></a>
    </div>
  </div>
</section>
<?php endif; ?>

<footer class="foot">
  <div class="foot-in">
    <img src="<?= e(url('assets/logo-mark.png')) ?>" alt="Attelier Kay">
    <span class="muted small">© <?= date('Y') ?> Attelier Kay</span>
    <nav>
      <a href="<?= e(cfg('website')) ?>">attelierkay.sk</a>
      <a href="<?= e(cfg('instagram')) ?>">Instagram</a>
      <a href="<?= e(cfg('facebook')) ?>">Facebook</a>
    </nav>
  </div>
</footer>

<div class="lb" id="lb" hidden>
  <div class="lb-count" id="lbCount"></div>
  <button type="button" class="round lb-close" id="lbClose" aria-label="Zavrieť"><?= $icoClose ?></button>
  <button type="button" class="round lb-nav prev" id="lbPrev" aria-label="Predchádzajúca"><svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
  <div class="stage" id="stage"><img id="lbImg" alt=""></div>
  <button type="button" class="round lb-nav next" id="lbNext" aria-label="Ďalšia"><svg viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg></button>
  <div class="dock">
    <button type="button" id="lbFav"><?= $icoHeart ?><span>Obľúbené</span></button>
    <button type="button" id="lbBw"><?= $icoBw ?><span>Čiernobielo</span></button>
    <button type="button" id="lbShare"><?= $icoShare ?><span>Zdieľať</span></button>
    <a id="lbDl" href="#"><?= $icoDl ?><span>Stiahnuť</span></a>
  </div>
</div>
<div class="toast" id="toast" hidden></div>

<script>window.PHOTOS = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>; window.SECTIONS = <?= json_encode($secData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= e(url('assets/gallery.js')) ?>?v=<?= filemtime(ROOT . '/public/assets/gallery.js') ?>"></script>
</body></html>
