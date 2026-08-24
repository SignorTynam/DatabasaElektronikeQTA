<?php
declare(strict_types=1);

require_once __DIR__ . '/public_ui.php';

$pageTitle = $pageTitle ?? 'QTA';
$pageDescription = $pageDescription ?? 'Qendra e Trajnimeve të Avancuara';
$pageBodyClass = $pageBodyClass ?? '';
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?= h($pageDescription) ?>">
  <title><?= h($pageTitle) ?></title>
  <link rel="icon" type="image/png" href="image/logoPNG2.png">
  <script>
    (function () {
      var stored = localStorage.getItem('qta_theme');
      var theme = stored === 'dark' || stored === 'light'
        ? stored
        : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Spectral:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <?php if (qta_plugin_enabled('aos')): ?>
    <link href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css" rel="stylesheet">
  <?php endif; ?>
  <?php if (qta_plugin_enabled('swiper')): ?>
    <link href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" rel="stylesheet">
  <?php endif; ?>
  <?php if (qta_plugin_enabled('leaflet')): ?>
    <link href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
  <?php endif; ?>
  <link href="<?= h(qta_asset('app/assets/css/tokens.css')) ?>" rel="stylesheet">
  <link href="<?= h(qta_asset('app/assets/css/protokoll.css')) ?>" rel="stylesheet">
  <link href="<?= h(qta_asset('app/assets/css/public.css')) ?>" rel="stylesheet">
</head>
<body class="<?= h($pageBodyClass) ?>">
