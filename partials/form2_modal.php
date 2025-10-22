<?php
/** @var string $CSRF */
?>
<!-- MODAL: Formulari nr. 2 -->
<div class="modal fade" id="form2Modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="form2Export" method="get" action="groups_export.php">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="type" value="form2">
      <input type="hidden" name="f" value="xlsx" id="form2Format">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-1"></i> Formulari nr. 2</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">AMZË e fillimit</label>
            <input type="number" name="amze_start" class="form-control" placeholder="p.sh. 3400" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">AMZË e mbarimit</label>
            <input type="number" name="amze_end" class="form-control" placeholder="p.sh. 3499" required>
          </div>
        </div>
        <div class="small text-muted mt-2">
          Shkarkohet: AMZË, Emër-Atësi-Mbiemër, vendlindja, dhe emri i kursit (nga grupi më i fundit të studentit).
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
