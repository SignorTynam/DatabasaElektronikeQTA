<?php
/**
 * edit_mode_off_banner.php — Shënim i qetë kur ndryshimet janë të mbyllura:
 * i tregon përdoruesit si t'i hapë, pa e alarmuar.
 */
if (!isset($EDIT_MODE) || $EDIT_MODE) {
  return;
}

$editModeBannerTitle = $editModeBannerTitle ?? 'Po shikon të dhënat.';
$editModeBannerText = $editModeBannerText ?? 'Për t\'i ndryshuar, shtyp "Lejo ndryshimet" lart djathtas.';
?>
<div class="notice mb-3" role="note">
  <i class="bi bi-lock" aria-hidden="true"></i>
  <span><b><?= htmlspecialchars($editModeBannerTitle, ENT_QUOTES, 'UTF-8') ?></b>
    <?= htmlspecialchars($editModeBannerText, ENT_QUOTES, 'UTF-8') ?></span>
</div>
