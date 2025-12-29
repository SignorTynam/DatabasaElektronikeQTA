<?php
/** @var string $CSRF */
/** @var array $groupInfo */
?>
<!-- MODAL: Formulari nr. 1 -->
<div class="modal fade" id="form1Modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="form1Export" method="get" action="groups_export.php">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="type" value="form1">
      <input type="hidden" name="f" value="xlsx" id="form1Format">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Formulari nr. 1</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Grupi i fillimit</label>
          <select name="gstart" id="gstart" class="form-select" required>
            <option value="">— Zgjidh —</option>
              <?php foreach($groupInfo as $gi): ?>
                <?php
                  $min = $gi['amze_min'];
                  $max = $gi['amze_max'];

                  if ($min === null || $min === '') {
                    $range = '—';
                  } else {
                    $min = (int)$min;
                    $max = ($max === null || $max === '') ? $min : (int)$max;
                    $range = ($max !== $min) ? ($min . '–' . $max) : (string)$min;
                  }

                  $dates = date('d-m-Y', strtotime($gi['start_date'])) . ' → ' . date('d-m-Y', strtotime($gi['end_date']));
                ?>
                <option value="<?= (int)$gi['id'] ?>">
                  #<?= (int)$gi['id'] ?> — <?= htmlspecialchars($gi['course_name'], ENT_QUOTES, 'UTF-8') ?>
                  [<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>]
                  (<?= htmlspecialchars($dates, ENT_QUOTES, 'UTF-8') ?>)
                </option>
              <?php endforeach; ?>
          </select>
          <div class="form-text" id="gstartHint">(AMZË: —)</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Grupi i mbarimit</label>
          <select name="gend" id="gend" class="form-select" required>
            <option value="">— Zgjidh —</option>
              <?php foreach($groupInfo as $gi): ?>
                <?php
                  $min = $gi['amze_min'];
                  $max = $gi['amze_max'];

                  if ($min === null || $min === '') {
                    $range = '—';
                  } else {
                    $min = (int)$min;
                    $max = ($max === null || $max === '') ? $min : (int)$max;
                    $range = ($max !== $min) ? ($min . '–' . $max) : (string)$min;
                  }

                  $dates = date('d-m-Y', strtotime($gi['start_date'])) . ' → ' . date('d-m-Y', strtotime($gi['end_date']));
                ?>
                <option value="<?= (int)$gi['id'] ?>">
                  #<?= (int)$gi['id'] ?> — <?= htmlspecialchars($gi['course_name'], ENT_QUOTES, 'UTF-8') ?>
                  [<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>]
                  (<?= htmlspecialchars($dates, ENT_QUOTES, 'UTF-8') ?>)
                </option>
              <?php endforeach; ?>
          </select>
          <div class="form-text" id="gendHint">(AMZË: —)</div>
        </div>
        <div class="small text-muted">
          Për çdo grup në intervalin [fillim…mbarim] shkarkohet: Emri i kursit, Fillimi, Mbarimi, Totale,
          Femra (kur gjinia të jetë e plotë), moshat 16–24, 25–34, 35+, si dhe AU/AM/AL.
        </div>
      </div>
      <div class="modal-footer">
        <div class="btn-group me-auto">
          <button type="button" class="btn btn-soft-success btn-pill" data-dl="xlsx"><i class="bi bi-file-earmark-excel me-1"></i> Excel</button>
          <button type="button" class="btn btn-soft-danger btn-pill" data-dl="pdf"><i class="bi bi-file-earmark-pdf me-1"></i> PDF</button>
          <button type="button" class="btn btn-soft-primary btn-pill" data-dl="docx"><i class="bi bi-file-earmark-word me-1"></i> Word</button>
        </div>
        <button type="button" class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Mbyll</button>
      </div>
    </form>
  </div>
</div>
