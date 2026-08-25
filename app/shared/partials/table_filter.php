<?php
declare(strict_types=1);

/**
 * table_filter.php — Filtrim i menjëhershëm i tabelës së faqes.
 *
 * Punon mbi rreshtat që janë tashmë në faqe: shkruaj dhe lista ngushtohet
 * pa u ringarkuar. Nuk zëvendëson filtrat e serverit — i plotëson kur je
 * brenda një faqeje rezultatesh.
 *
 * Variabla opsionale:
 *   $tfTarget      string  selektor i tabelës (default: e para në faqe)
 *   $tfPlaceholder string
 *   $tfChips       array   [['label'=>…, 'match'=>…], …] filtra të shpejtë
 */

$tfTarget      = $tfTarget      ?? '';
$tfPlaceholder = $tfPlaceholder ?? 'Ngushto listën — shkruaj çfarë kërkon';
$tfChips       = $tfChips       ?? [];
?>
<div class="tfilter" data-tfilter>
  <div class="tfilter-field">
    <i class="bi bi-funnel" aria-hidden="true"></i>
    <input type="text" data-pal-off="1"
           data-table-filter="<?= h($tfTarget) ?>"
           placeholder="<?= h($tfPlaceholder) ?>"
           aria-label="Ngushto listën">
    <button type="button" class="tfilter-clear" data-tfilter-clear aria-label="Pastro">
      <i class="bi bi-x"></i>
    </button>
  </div>

  <?php if ($tfChips): ?>
    <div class="tfilter-chips">
      <?php foreach ($tfChips as $c): ?>
        <button type="button" class="tfilter-chip"
                data-tfilter-chip="<?= h((string)$c['match']) ?>"><?= h((string)$c['label']) ?></button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <span class="tfilter-count" data-tfilter-count></span>
</div>
