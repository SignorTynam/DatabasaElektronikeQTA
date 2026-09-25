<?php
declare(strict_types=1);

/**
 * export_menu.php — Butoni "Shkarko ▾" me formatet e dokumentit.
 * Dërgohet me POST që tokeni CSRF të mos dalë në URL.
 *
 * Pritet nga faqja prind:
 *   $exportAction  string  skripti i eksportit (p.sh. 'students_export.php')
 *   $exportFields  array   fushat e filtrit [emër => vlerë]; bosh = nuk dërgohet
 *   $CSRF          string
 * Opsionale:
 *   $exportFormats array   [f => etiketë]
 *   $exportTitle   string  titulli i menusë
 */

$exportFields  = $exportFields  ?? [];
$exportFormats = $exportFormats ?? ['xlsx' => 'Excel (.xlsx)', 'pdf' => 'PDF', 'docx' => 'Word (.docx)'];
$exportTitle   = $exportTitle   ?? 'Shkarko listën si';
$exportIcons   = [
  'xlsx' => 'bi-file-earmark-spreadsheet',
  'pdf'  => 'bi-file-earmark-pdf',
  'docx' => 'bi-file-earmark-word',
  'doc'  => 'bi-file-earmark-word',
];
?>
<form class="dropdown" method="post" action="<?= h((string)$exportAction) ?>"
      data-download-toast="Dokumenti po përgatitet. Mund të vazhdosh punën.">
  <input type="hidden" name="csrf" value="<?= h((string)$CSRF) ?>">
  <?php foreach ($exportFields as $exportName => $exportValue):
    if ($exportValue === null || $exportValue === '' || $exportValue === false) continue; ?>
    <input type="hidden" name="<?= h((string)$exportName) ?>" value="<?= h((string)$exportValue) ?>">
  <?php endforeach; ?>
  <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
    <i class="bi bi-download" aria-hidden="true"></i>Shkarko
  </button>
  <ul class="dropdown-menu dropdown-menu-end">
    <li><span class="dropdown-header"><?= h($exportTitle) ?></span></li>
    <?php foreach ($exportFormats as $exportFormat => $exportLabelText): ?>
      <li>
        <button class="dropdown-item" type="submit" name="f" value="<?= h((string)$exportFormat) ?>">
          <i class="bi <?= h($exportIcons[$exportFormat] ?? 'bi-file-earmark') ?>" aria-hidden="true"></i><?= h((string)$exportLabelText) ?>
        </button>
      </li>
    <?php endforeach; ?>
  </ul>
</form>
