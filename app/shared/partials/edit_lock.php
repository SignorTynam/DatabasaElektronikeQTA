<?php
declare(strict_types=1);

/**
 * edit_lock.php — Kyçi i ndryshimeve (një kontroll i vetëm për të gjitha faqet).
 *
 * Të dhënat rrinë të mbrojtura nga ndryshimet aksidentale dhe hapen me
 * vetëdije. Gjendja thuhet me fjalë, jo me "ON/OFF".
 *
 * Pritet nga faqja prind:
 *   $EDIT_MODE  bool    gjendja aktuale
 *   $CAN_EDIT   bool    a lejohet ky përdorues ta ndryshojë (opsionale, default true)
 */

if (!isset($EDIT_MODE)) {
  return;
}

$lockCanEdit = $CAN_EDIT ?? true;

/* Ruaj çdo parametër ekzistues të kërkesës; ndrysho vetëm 'edit'. */
$lockQs = $_GET;
unset($lockQs['add'], $lockQs['create']);
$lockQs['edit'] = $EDIT_MODE ? '0' : '1';
$lockPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$lockUrl  = $lockPage . ($lockQs ? ('?' . http_build_query($lockQs)) : '');
?>
<div class="edit-lock<?= $EDIT_MODE ? ' is-open' : '' ?>" role="group" aria-label="Ndryshimet e të dhënave">
  <i class="bi <?= $EDIT_MODE ? 'bi-unlock' : 'bi-lock' ?> edit-lock-icon" aria-hidden="true"></i>
  <span class="edit-lock-text">
    <b><?= $EDIT_MODE ? 'Ndryshimet janë të hapura' : 'Vetëm për lexim' ?></b>
    <span><?= $EDIT_MODE ? 'Çdo ndryshim ruhet menjëherë.' : 'Të dhënat janë të mbrojtura.' ?></span>
  </span>
  <?php if ($lockCanEdit): ?>
    <a class="btn btn-sm btn-secondary" href="<?= h($lockUrl) ?>">
      <?= $EDIT_MODE ? 'Mbyll ndryshimet' : 'Lejo ndryshimet' ?>
    </a>
  <?php else: ?>
    <span class="text-muted small">Nuk ke leje për ndryshime.</span>
  <?php endif; ?>
</div>
