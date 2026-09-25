<?php
declare(strict_types=1);

/**
 * app_head.php — <head> i përbashkët i panelit dhe hapja e <body>.
 *
 * Variabla opsionale nga faqja prind:
 *   $pageTitle   string  titulli i skedës
 *   $bodyClass   string  klasa shtesë për <body>
 *   $pageStyles  array   URL-ra CSS shtesë (vetëm për raste të veçanta)
 *   $EDIT_MODE   bool    kur ndryshimet janë të hapura, shell-i e tregon gjithnjë
 */

require_once __DIR__ . '/app_ui.php';

$pageTitle  = $pageTitle  ?? 'QTA';
$bodyClass  = $bodyClass  ?? '';
$pageStyles = $pageStyles ?? [];

$bodyClasses = trim('app ' . $bodyClass . (!empty($EDIT_MODE) ? ' is-editing' : ''));
if (!str_contains($pageTitle, 'QTA')) {
  $pageTitle .= ' · Regjistri QTA';
}
?>
<!doctype html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= h($pageTitle) ?></title>
<?php require __DIR__ . '/partials/head_common.php'; ?>
  <link href="<?= h(qta_asset('app/assets/css/shell.css')) ?>" rel="stylesheet">
<?php foreach ($pageStyles as $style): ?>
  <link href="<?= h((string)$style) ?>" rel="stylesheet">
<?php endforeach; ?>
</head>
<body class="<?= h($bodyClasses) ?>">
<a class="skip-link" href="#main">Kalo te përmbajtja</a>
<?php
/* Koka doli: vizato shell-in nëse faqja e kërkoi më herët. */
$GLOBALS['QTA_HEAD_RENDERED'] = true;
if (!empty($GLOBALS['QTA_NAV_DEFERRED'])) {
  $GLOBALS['QTA_NAV_DEFERRED'] = false;
  require __DIR__ . '/inc/app_navbar.php';
}
?>
