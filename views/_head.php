<!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#ffffff">
<title><?= e($pageTitle ?? cfg('site_name')) ?> · <?= e(cfg('site_name')) ?></title>
<link rel="icon" href="<?= e(url('assets/logo-mark.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/app.css')) ?>?v=<?= filemtime(ROOT . '/public/assets/app.css') ?>">
<?= $headExtra ?? '' ?>
</head>
