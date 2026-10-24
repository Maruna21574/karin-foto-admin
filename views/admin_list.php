<?php $pageTitle = 'Galérie'; require __DIR__ . '/_head.php'; ?>
<body class="admin">
<header class="topbar">
  <a href="<?= e(url('admin')) ?>" class="brand"><img src="<?= e(url('assets/logo-mark.png')) ?>" alt=""><span>Galérie klientov</span></a>
  <a class="link" href="<?= e(url('admin/logout')) ?>">Odhlásiť</a>
</header>
<main class="wrap">
  <section class="card">
    <h2>Nová galéria</h2>
    <form class="grid-form" method="post" action="<?= e(url('admin/g/new')) ?>">
      <?= csrf_field() ?>
      <label>Názov galérie<input name="title" placeholder="Svadba Jana &amp; Peter" required></label>
      <label>Meno klienta<input name="client_name" placeholder="Jana Nováková"></label>
      <label>Dátum fotenia<input type="date" name="shoot_date"></label>
      <label class="full">Správa pre klienta (nepovinné)<textarea name="message" rows="2" placeholder="Ďakujem, že som mohla byť pri vašom dni…"></textarea></label>
      <button class="btn" type="submit">Vytvoriť a nahrať fotky</button>
    </form>
  </section>

  <h2 class="section-title">Všetky galérie (<?= count($galleries) ?>)</h2>
  <?php if (!$galleries): ?><p class="muted">Zatiaľ žiadna galéria.</p><?php endif; ?>
  <div class="cards">
  <?php foreach ($galleries as $g): ?>
    <a class="gcard" href="<?= e(url('admin/g/' . $g['id'])) ?>">
      <div class="cover">
        <?php if ($g['cover_photo_id']): ?>
          <img loading="lazy" src="<?= e(url("g/{$g['token']}/img/{$g['cover_photo_id']}/thumb")) ?>" alt="">
        <?php else: ?><span>bez fotiek</span><?php endif; ?>
      </div>
      <div class="meta">
        <strong><?= e($g['title']) ?></strong>
        <span><?= e($g['client_name']) ?><?= $g['shoot_date'] ? ' · ' . e(format_date($g['shoot_date'])) : '' ?></span>
        <span class="muted"><?= (int)$g['photo_count'] ?> fotiek, <?= (int)$g['fav_count'] ?> obľúbených</span>
      </div>
    </a>
  <?php endforeach; ?>
  </div>
</main>
</body></html>
