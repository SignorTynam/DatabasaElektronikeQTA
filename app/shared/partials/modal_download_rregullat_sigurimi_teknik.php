<?php
// partials/modal_download_rregullat_sigurimi_teknik.php
?>
<div class="modal fade" id="modalDownloadSigurimiTeknik" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="stTitle">
          <i class="bi bi-download me-2"></i> Shkarko rregullat e sigurimit teknik
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>

      <div class="modal-body">
        <div class="alert alert-light small mb-3">
          Zgjidh formatin e shkarkimit. Dokumenti do të përgatitet automatikisht.
        </div>

        <input type="hidden" id="stGroupId" value="">
        <input type="hidden" id="stCsrf" value="">

        <div class="d-grid gap-2">
          <button type="button" class="btn btn-soft-danger btn-pill" data-st-format="pdf">
            <i class="bi bi-filetype-pdf me-1"></i> PDF
          </button>

          <button type="button" class="btn btn-soft-secondary btn-pill" data-st-format="doc">
            <i class="bi bi-filetype-docx me-1"></i> Word
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
  const modalEl = document.getElementById('modalDownloadSigurimiTeknik');
  if (!modalEl) return;

  const titleEl = document.getElementById('stTitle');
  const groupEl = document.getElementById('stGroupId');
  const csrfEl  = document.getElementById('stCsrf');

  modalEl.addEventListener('show.bs.modal', function (ev) {
    const btn  = ev.relatedTarget;
    const gid  = btn ? (btn.getAttribute('data-group-id') || '') : '';
    const csrf = btn ? (btn.getAttribute('data-csrf') || '') : '';

    groupEl.value = gid;
    csrfEl.value  = csrf;

    if (titleEl && gid) {
      titleEl.innerHTML = '<i class="bi bi-download me-2"></i> Shkarko rregullat e sigurimit teknik — Grup #' + gid;
    } else if (titleEl) {
      titleEl.innerHTML = '<i class="bi bi-download me-2"></i> Shkarko rregullat e sigurimit teknik';
    }
  });

  modalEl.querySelectorAll('[data-st-format]').forEach(function (b) {
    b.addEventListener('click', function () {
      const fmt  = b.getAttribute('data-st-format'); // pdf | doc
      const gid  = groupEl.value;
      const csrf = csrfEl.value;

      if (!gid || !csrf) {
        alert('Mungon grupi ose CSRF (rifresko faqen).');
        return;
      }

      // POST në tab të ri (backend pret POST + CSRF)
      if (window.qtaDownloadToast && typeof window.qtaDownloadToast.start === 'function') {
        window.qtaDownloadToast.start('Dokumenti i rregullave të sigurimit teknik po gjenerohet. Ju lutem prisni...');
      }

      const f = document.createElement('form');
      f.method = 'POST';
      f.action = 'download_rregullat_sigurimi_teknik.php';
      f.target = '_blank';

      const i1 = document.createElement('input');
      i1.type='hidden'; i1.name='group_id'; i1.value=gid;

      const i2 = document.createElement('input');
      i2.type='hidden'; i2.name='csrf'; i2.value=csrf;

      const i3 = document.createElement('input');
      i3.type='hidden'; i3.name='format'; i3.value=fmt;

      f.appendChild(i1); f.appendChild(i2); f.appendChild(i3);
      document.body.appendChild(f);
      f.submit();
      f.remove();
    });
  });
})();
</script>
