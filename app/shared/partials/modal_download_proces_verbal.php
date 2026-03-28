<?php
// partials/modal_download_proces_verbal.php
?>
<div class="modal fade" id="modalDownloadProcesVerbal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="pvTitle">
          <i class="bi bi-download me-2"></i> Shkarko proces verbal
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>

      <div class="modal-body">
        <div class="alert alert-light small mb-3">
          Zgjidh formatin e shkarkimit. Dokumenti do të përgatitet automatikisht.
        </div>

        <input type="hidden" id="pvGroupId" value="">
        <input type="hidden" id="pvCsrf" value="">

        <div class="d-grid gap-2">
          <button type="button" class="btn btn-soft-danger btn-pill" data-pv-format="pdf">
            <i class="bi bi-filetype-pdf me-1"></i> PDF
          </button>

          <button type="button" class="btn btn-soft-secondary btn-pill" data-pv-format="docx">
            <i class="bi bi-filetype-docx me-1"></i> Word
          </button>

          <button type="button" class="btn btn-soft-success btn-pill" data-pv-format="xlsx">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Excel
          </button>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Mbyll</button>
      </div>

    </div>
  </div>
</div>

<script>
(function () {
  const modalEl = document.getElementById('modalDownloadProcesVerbal');
  if (!modalEl) return;

  const titleEl = document.getElementById('pvTitle');
  const groupEl = document.getElementById('pvGroupId');
  const csrfEl  = document.getElementById('pvCsrf');

  modalEl.addEventListener('show.bs.modal', function (ev) {
    const btn  = ev.relatedTarget;
    const gid  = btn ? (btn.getAttribute('data-group-id') || '') : '';
    const csrf = btn ? (btn.getAttribute('data-csrf') || '') : '';

    groupEl.value = gid;
    csrfEl.value  = csrf;

    if (titleEl && gid) {
      titleEl.innerHTML = '<i class="bi bi-download me-2"></i> Shkarko proces verbal — Grup #' + gid;
    }
  });

  modalEl.querySelectorAll('[data-pv-format]').forEach(function (b) {
    b.addEventListener('click', function () {
      const fmt  = b.getAttribute('data-pv-format');
      const gid  = groupEl.value;
      const csrf = csrfEl.value;

      if (!gid || !csrf) {
        alert('Mungon grupi ose CSRF (rifresko faqen).');
        return;
      }

      const url =
        'download_proces_verbal.php' +
        '?group_id=' + encodeURIComponent(gid) +
        '&f=' + encodeURIComponent(fmt) +
        '&csrf=' + encodeURIComponent(csrf);

      window.open(url, '_blank');
    });
  });
})();
</script>
