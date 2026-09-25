<?php
declare(strict_types=1);

/**
 * public_head.php — <head> i faqeve publike dhe hapja e <body>.
 *
 * Variabla opsionale nga faqja prind:
 *   $pageTitle        string
 *   $pageDescription  string
 *   $pageBodyClass    string
 */

require_once __DIR__ . '/public_ui.php';

$pageTitle = $pageTitle ?? 'Regjistri QTA';
$pageDescription = $pageDescription ?? 'Regjistri publik i certifikimeve profesionale të Qendrës së Trajnimeve të Avancuara.';
$pageBodyClass = $pageBodyClass ?? '';
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?= h($pageDescription) ?>">
  <title><?= h($pageTitle) ?></title>
<?php require __DIR__ . '/partials/head_common.php'; ?>
  <link href="<?= h(qta_asset('app/assets/css/public.css')) ?>" rel="stylesheet">
</head>
<body class="public <?= h($pageBodyClass) ?>">
<a class="skip-link" href="#main">Kalo te përmbajtja</a>
