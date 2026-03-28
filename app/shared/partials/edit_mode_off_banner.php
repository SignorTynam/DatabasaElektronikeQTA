<?php
if (!isset($EDIT_MODE) || $EDIT_MODE) {
  return;
}

$editModeBannerTitle = $editModeBannerTitle ?? 'Edit Mode eshte OFF';
$editModeBannerText = $editModeBannerText ?? 'Aktivizo Edit Mode per te bere ndryshime.';
?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3" role="status">
  <i class="bi bi-exclamation-triangle-fill"></i>
  <div>
    <strong><?= htmlspecialchars($editModeBannerTitle, ENT_QUOTES, 'UTF-8') ?></strong>
    <span class="ms-1"><?= htmlspecialchars($editModeBannerText, ENT_QUOTES, 'UTF-8') ?></span>
  </div>
</div>
