<?php $pageTitle = 'Prihlásenie'; require __DIR__ . '/_head.php'; ?>
<body class="center-page">
<form class="card narrow tc" method="post" action="<?= e(url('admin/login')) ?>">
  <img class="logo" src="<?= e(url('assets/logo-mark.png')) ?>" alt="Attelier Kay">
  <h1>Administrácia</h1>
  <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
  <?= csrf_field() ?>
  <input type="email" name="email" placeholder="E-mail" autocomplete="username" <?= $locked ? 'disabled' : 'autofocus' ?> required>
  <input type="password" name="password" placeholder="Heslo" autocomplete="current-password" <?= $locked ? 'disabled' : '' ?> required>
  <button class="btn" type="submit" <?= $locked ? 'disabled' : '' ?>>Prihlásiť sa</button>
</form>
</body></html>
