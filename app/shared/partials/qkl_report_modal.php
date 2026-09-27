<?php
/**
 * qkl_report_modal.php — Raporti për QKL sipas një intervali numrash amze.
 * Dërgohet me POST (tokeni CSRF nuk del në URL).
 * @var string $CSRF
 */
?>
<div class="modal fade" id="qklReportModal" tabindex="-1" aria-labelledby="qklReportTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="qklReportExport" method="post" action="groups_export.php"
          data-download-toast="Raporti për QKL po përgatitet.">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="type" value="qkl">
      <div class="modal-header">
        <h2 class="modal-title" id="qklReportTitle"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i>Raporti për QKL</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted">Raporti përfshin regjistrimet me numër amze nga i pari deri tek i fundit që shkruan këtu.</p>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label" for="qklAmzeStart">Nga nr. i amzës</label>
            <input type="number" min="1" step="1" name="amze_start" id="qklAmzeStart" class="form-control input-code" placeholder="p.sh. 3400" required>
          </div>
          <div class="col-6">
            <label class="form-label" for="qklAmzeEnd">Deri te nr. i amzës</label>
            <input type="number" min="1" step="1" name="amze_end" id="qklAmzeEnd" class="form-control input-code" placeholder="p.sh. 3499" required>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary me-auto" data-bs-dismiss="modal">Anulo</button>
        <button type="submit" class="btn btn-secondary" name="f" value="pdf"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>Shkarko PDF</button>
        <button type="submit" class="btn btn-primary" name="f" value="xlsx"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i>Shkarko Excel</button>
      </div>
    </form>
  </div>
</div>
