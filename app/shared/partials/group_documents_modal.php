<?php
declare(strict_types=1);

/**
 * group_documents_modal.php — Një dialog i vetëm për dokumentet e grupit.
 *
 * Hapet nga çdo buton me:
 *   data-bs-toggle="modal" data-bs-target="#groupDocModal"
 *   data-doc="proces_verbal|lista_emerore|praktika_profesionale|rregullat_sigurimi_teknik"
 *   data-group-id="…" data-group-label="…"
 *
 * Dokumenti dërgohet me POST (tokeni CSRF nuk del në URL) dhe hapet në skedë të re.
 * Pritet: $CSRF (string)
 */
?>
<div class="modal fade" id="groupDocModal" tabindex="-1" aria-labelledby="groupDocTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" target="_blank" data-group-doc-form
          data-download-toast="Dokumenti po përgatitet. Do të hapet në një skedë të re.">
      <input type="hidden" name="csrf" value="<?= h((string)$CSRF) ?>">
      <input type="hidden" name="group_id" value="">
      <div class="modal-header">
        <div>
          <span class="eyebrow mb-0" data-doc-group>Grupi</span>
          <h2 class="modal-title" id="groupDocTitle"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><span data-doc-title>Dokumenti</span></h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted" data-doc-desc></p>
        <p class="form-label mb-2" id="groupDocFormats">Zgjidh formatin</p>
        <div class="d-grid gap-2" role="group" aria-labelledby="groupDocFormats" data-doc-formats></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
  var modalEl = document.getElementById('groupDocModal');
  if (!modalEl) return;
  var form = modalEl.querySelector('[data-group-doc-form]');
  var icons = { pdf: 'bi-file-earmark-pdf', doc: 'bi-file-earmark-word', docx: 'bi-file-earmark-word', xlsx: 'bi-file-earmark-spreadsheet' };
  var labels = { pdf: 'PDF — për printim', doc: 'Word — për ndryshime', docx: 'Word — për ndryshime', xlsx: 'Excel — tabelë' };
  var DOCS = {
    proces_verbal: {
      title: 'Procesverbali i provimit', action: 'download_proces_verbal.php', field: 'f', formats: ['pdf', 'docx', 'xlsx'],
      desc: 'Dokumenti zyrtar me kursantët e grupit, datat dhe rezultatet e provimit.'
    },
    lista_emerore: {
      title: 'Lista emërore', action: 'download_lista_emerore.php', field: 'format', formats: ['pdf', 'doc'],
      desc: 'Lista me numrin rendor dhe emrin e plotë (emër, atësi, mbiemër) të çdo kursanti.'
    },
    praktika_profesionale: {
      title: 'Praktika profesionale', action: 'download_praktika_profesionale.php', field: 'format', formats: ['pdf', 'doc'],
      desc: 'Dokumenti i praktikës profesionale për kursantët e këtij grupi.'
    },
    rregullat_sigurimi_teknik: {
      title: 'Rregullat e sigurimit teknik', action: 'download_rregullat_sigurimi_teknik.php', field: 'format', formats: ['pdf', 'doc'],
      desc: 'Rregullat e sigurisë në punë që nënshkruhen nga kursantët e grupit.'
    }
  };

  modalEl.addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    var key = btn ? btn.getAttribute('data-doc') : '';
    var doc = DOCS[key];
    if (!doc) return;
    form.setAttribute('action', doc.action);
    form.elements.group_id.value = btn.getAttribute('data-group-id') || '';
    modalEl.querySelector('[data-doc-title]').textContent = doc.title;
    modalEl.querySelector('[data-doc-desc]').textContent = doc.desc;
    modalEl.querySelector('[data-doc-group]').textContent = btn.getAttribute('data-group-label') || 'Grupi';
    var box = modalEl.querySelector('[data-doc-formats]');
    box.innerHTML = '';
    doc.formats.forEach(function (fmt) {
      var b = document.createElement('button');
      b.type = 'submit';
      b.name = doc.field;
      b.value = fmt;
      b.className = 'btn btn-secondary btn-lg justify-content-start';
      b.innerHTML = '<i class="bi ' + (icons[fmt] || 'bi-file-earmark') + '" aria-hidden="true"></i>';
      b.appendChild(document.createTextNode(labels[fmt] || fmt.toUpperCase()));
      box.appendChild(b);
    });
  });

  form.addEventListener('submit', function () {
    if (!form.elements.group_id.value) return;
    setTimeout(function () {
      var inst = window.bootstrap && window.bootstrap.Modal.getInstance(modalEl);
      if (inst) inst.hide();
    }, 50);
  });
})();
</script>
