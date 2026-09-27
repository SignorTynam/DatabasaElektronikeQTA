<?php
declare(strict_types=1);

/**
 * head_common.php — pjesa e përbashkët e <head> për panelin dhe faqet publike.
 * Tema (E çelët · Sipas pajisjes · E errët) zgjidhet para vizatimit të parë,
 * që faqja të mos "shkëlqejë" me temën e gabuar.
 */

require_once __DIR__ . '/../themeli.php';
?>
  <link rel="icon" type="image/png" href="<?= h(qta_asset('image/logoPNG2.png')) ?>">
  <meta name="theme-color" content="#f6f5f1" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#171614" media="(prefers-color-scheme: dark)">
  <script>
    (function () {
      var d = document.documentElement, mode = 'system', rail = false;
      try {
        mode = localStorage.getItem('qta_theme') || 'system';
        rail = localStorage.getItem('qta_sidebar') === 'collapsed';
      } catch (e) { /* ruajtja e bllokuar: përdor parazgjedhjet */ }
      if (mode !== 'light' && mode !== 'dark') mode = 'system';
      var dark = mode === 'dark' || (mode === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
      d.setAttribute('data-theme', dark ? 'dark' : 'light');
      d.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
      d.setAttribute('data-theme-mode', mode);
      if (rail) d.setAttribute('data-sidebar', 'rail');
    })();
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible+Mono:wght@400;500;600&family=Atkinson+Hyperlegible+Next:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,500;8..60,600&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="<?= h(qta_asset('app/assets/css/tokens.css')) ?>" rel="stylesheet">
  <link href="<?= h(qta_asset('app/assets/css/base.css')) ?>" rel="stylesheet">
  <link href="<?= h(qta_asset('app/assets/css/components.css')) ?>" rel="stylesheet">
