<?php
declare(strict_types=1);

/**
 * table_filter.php — Filtrim i menjëhershëm i tabelës së faqes.
 *
 * Punon mbi rreshtat që janë tashmë në faqe: shkruaj dhe lista ngushtohet
 * pa u ringarkuar. Nuk zëvendëson kërkimin e serverit — e plotëson.
 *
 * Variabla opsionale:
 *   $tfTarget      string  selektor i tabelës (default: e para në faqe)
 *   $tfPlaceholder string
 *   $tfChips       array   [['label'=>…, 'match'=>…], …] filtra të shpejtë
 *   $tfNoun        string  si quhen rreshtat (p.sh. "kursantë", "grupe")
 */

$tfTarget      = $tfTarget      ?? '';
$tfPlaceholder = $tfPlaceholder ?? 'Filtro këtë faqe…';
$tfChips       = $tfChips       ?? [];
$tfNoun        = $tfNoun        ?? 'rreshta';
?>
<div class="tfilter" data-tfilter data-tfilter-noun="<?= h($tfNoun) ?>">
  <div class="tfilter-field">
    <i class="bi bi-funnel" aria-hidden="true"></i>
    <input type="text" data-table-filter="<?= h($tfTarget) ?>"
           placeholder="<?= h($tfPlaceholder) ?>" aria-label="Filtro rreshtat në këtë faqe">
    <button type="button" class="tfilter-clear" data-tfilter-clear aria-label="Pastro filtrin">
      <i class="bi bi-x-lg" aria-hidden="true"></i>
    </button>
  </div>

  <?php if ($tfChips): ?>
    <div class="tfilter-chips" role="group" aria-label="Filtra të shpejtë">
      <?php foreach ($tfChips as $c): ?>
        <button type="button" class="tfilter-chip"
                data-tfilter-chip="<?= h((string)$c['match']) ?>"><?= h((string)$c['label']) ?></button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <span class="tfilter-count" data-tfilter-count aria-live="polite"></span>
</div>
