<?php
/** @var string $CSRF */
?>
<!-- MODAL: Raporti per QKL -->
<div class="modal fade" id="qklReportModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="qklReportExport" method="get" action="groups_export.php">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="type" value="qkl">
      <input type="hidden" name="f" value="xlsx" id="qklReportFormat">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Raporti për QKL</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="qklAmzeStart">AMZË fillimi</label>
            <input type="number" min="1" step="1" name="amze_start" id="qklAmzeStart" class="form-control" placeholder="p.sh. 3400" required>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="qklAmzeEnd">AMZË mbarimi</label>
            <input type="number" min="1" step="1" name="amze_end" id="qklAmzeEnd" class="form-control" placeholder="p.sh. 3499" required>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <div class="btn-group me-auto">
          <button type="button" class="btn btn-soft-success btn-pill" data-dl="xlsx"><i class="bi bi-file-earmark-excel me-1"></i> Excel</button>
          <button type="button" class="btn btn-soft-danger btn-pill" data-dl="pdf"><i class="bi bi-file-earmark-pdf me-1"></i> PDF</button>
        </div>
        <button type="button" class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Mbyll</button>
      </div>
    </form>
  </div>
</div>
