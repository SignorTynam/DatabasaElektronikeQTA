<?php
declare(strict_types=1);

/**
 * edit_lock.php — Kyçi i regjistrit (kontroll i vetëm për të gjitha faqet).
 *
 * Zëvendëson tri variantet e vjetra: butonin-pilulë në toolbar, FAB-in pluskues
 * dhe shiritin e paralajmërimit. Një regjistër zyrtar rri i mbyllur dhe hapet
 * me vetëdije për të shkruar — prandaj gjendja thuhet me fjalë, jo me "ON/OFF".
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
$lockQs['edit'] = $EDIT_MODE ? '0' : '1';
$lockPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$lockUrl  = $lockPage . ($lockQs ? ('?' . http_build_query($lockQs)) : '');
?>
<div class="edit-lock<?= $EDIT_MODE ? ' is-open' : '' ?>">
  <span class="edit-lock-state">
    <i class="bi <?= $EDIT_MODE ? 'bi-unlock' : 'bi-lock-fill' ?>" aria-hidden="true"></i>
    <span>
      <span class="label">Regjistri</span>
      <b><?= $EDIT_MODE ? 'I hapur për shkrim' : 'I mbyllur' ?></b>
    </span>
  </span>

  <?php if ($lockCanEdit): ?>
    <a class="btn btn-sm <?= $EDIT_MODE ? 'btn-seal' : 'btn-ink' ?>" href="<?= h($lockUrl) ?>">
      <?= $EDIT_MODE ? 'Mbyll regjistrin' : 'Hap për shkrim' ?>
    </a>
  <?php else: ?>
    <span class="muted" style="font-size:var(--fs-xs)">Nuk ke leje shkrimi.</span>
  <?php endif; ?>
</div>
