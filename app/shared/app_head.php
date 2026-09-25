<?php
declare(strict_types=1);

/**
 * app_head.php — <head> i përbashkët i panelit.
 *
 * Variabla opsionale nga faqja prind:
 *   $pageTitle   string  titulli i skedës
 *   $bodyClass   string  klasa shtesë për <body>
 *   $pageStyles  array   URL-ra CSS shtesë
 *   $headExtra   string  HTML i papërpunuar për <head> (p.sh. <style> i faqes)
 */

require_once __DIR__ . '/app_ui.php';

$pageTitle  = $pageTitle  ?? 'QTA';
$bodyClass  = $bodyClass  ?? '';
$pageStyles = $pageStyles ?? [];
$headExtra  = $headExtra  ?? '';
?>
<!doctype html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= h($pageTitle) ?></title>
  <link rel="icon" type="image/png" href="<?= h(qta_asset('image/logoPNG2.png')) ?>">

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
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,500;8..60,600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="<?= h(qta_asset('app/assets/css/tokens.css')) ?>" rel="stylesheet">
  <link href="<?= h(qta_asset('app/assets/css/protokoll.css')) ?>" rel="stylesheet">
  <link href="<?= h(qta_asset('app/assets/css/app.css')) ?>" rel="stylesheet">
  <link href="<?= h(qta_asset('app/assets/css/legacy-map.css')) ?>" rel="stylesheet">
  <link href="<?= h(qta_asset('app/assets/css/claude-ui.css')) ?>" rel="stylesheet">
<?php foreach ($pageStyles as $style): ?>
  <link href="<?= h((string)$style) ?>" rel="stylesheet">
<?php endforeach; ?>
<?= $headExtra ?>
</head>
<body class="qta-claude-ui <?= h($bodyClass) ?>">
<?php
/* Koka doli: vizato navbar-in nëse faqja e kërkoi më herët. */
$GLOBALS['QTA_HEAD_RENDERED'] = true;
if (!empty($GLOBALS['QTA_NAV_DEFERRED'])) {
  $GLOBALS['QTA_NAV_DEFERRED'] = false;
  require __DIR__ . '/inc/app_navbar.php';
}
?>
