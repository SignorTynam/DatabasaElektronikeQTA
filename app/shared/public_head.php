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
  <link href="app/assets/css/public.css" rel="stylesheet">
</head>
<body class="<?= h($pageBodyClass) ?>">
