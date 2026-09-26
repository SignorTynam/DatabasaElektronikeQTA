/* ============================================================================
   Verifikimi publik i certifikatës — skano, ngarko foto ose shkruaj kodin.
   Kamera hapet VETËM kur përdoruesi e kërkon. Rezultati vizatohet këtu, si
   për kontrollet e reja ashtu edhe për lidhjet e hapura me ?t=…
   ========================================================================= */
(function () {
  'use strict';

  var $ = function (sel) { return document.querySelector(sel); };
  var btnScan = $('[data-scan-start]');
  var scanBox = $('[data-scan-box]');
  var camSelect = $('[data-scan-camera]');
  var btnStop = $('[data-scan-stop]');
  var scanStatus = $('[data-scan-status]');
  var fileInput = $('[data-scan-file]');
  var form = $('[data-verify-form]');
  var input = $('#manualPayload');
  var btnPaste = $('[data-paste]');
  var verdict = $('[data-verdict]');
  var verdictTitle = $('#statusText');
  var verdictNote = $('[data-verdict-note]');
  var result = $('[data-result]');
  if (!verdict || !result) return;

  var camQr = null, fileQr = null, running = false, busy = false, cameras = [], lastPayload = '';

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function toast(msg, variant) { if (window.qtaToast) window.qtaToast(msg, variant); }
  function status(label, variant, icon) {
    return '<span class="status status-' + variant + '"><i class="bi ' + icon + '" aria-hidden="true"></i>' + esc(label) + '</span>';
  }

  /* ------------------------------------------------------------ Verdikti */
  var states = {
    idle:    { icon: 'bi-qr-code-scan', title: 'Gati për kontroll', note: 'Skano kodin QR ose shkruaj kodin e certifikatës.' },
    loading: { icon: 'bi-arrow-repeat', title: 'Po kontrolloj…', note: 'Po e kërkojmë kodin në regjistër.' },
    valid:   { icon: 'bi-check-lg', title: 'Certifikatë e vlefshme', note: 'Kodi figuron në regjistrin e QTA-së. Krahaso emrin më poshtë me emrin në certifikatë.' },
    invalid: { icon: 'bi-x-lg', title: 'Kodi nuk u gjet', note: 'Ky kod nuk përputhet me asnjë certifikatë në regjistër.' },
    error:   { icon: 'bi-wifi-off', title: 'Kontrolli nuk u krye', note: 'Nuk u lidhëm me serverin. Kontrollo internetin dhe provo sërish.' }
  };

  function setVerdict(state, note) {
    var s = states[state] || states.idle;
    verdict.className = 'verdict is-' + state;
    verdict.querySelector('.verdict-icon').innerHTML = '<i class="bi ' + s.icon + '" aria-hidden="true"></i>';
    verdictTitle.textContent = s.title;
    verdictNote.textContent = note || s.note;
    result.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
  }

  function resetResult() {
    setVerdict('idle');
    result.innerHTML = '<div class="result-empty"><i class="bi bi-patch-question" aria-hidden="true"></i><span>Rezultati do të shfaqet këtu.</span></div>';
  }

  /* ----------------------------------------------------------- Rezultati */
  function tokenFrom(payload) {
    var m = String(payload || '').match(/[a-f0-9]{32}/i);
    return m ? m[0].toLowerCase() : '';
  }

  function shareLink(payload, json) {
    var token = tokenFrom(payload);
    if (!token) return '';
    var base = location.origin + location.pathname;
    if (json && json.kind === 'person' && json.person) return base + '?pid=' + json.person.id + '&t=' + token;
    if (json && json.kind === 'student' && json.student) return base + '?sid=' + json.student.id + '&t=' + token;
    return base + '?t=' + token;
  }

  function actionsHtml(valid) {
    return '<div class="result-actions no-print">' +
      (valid ? '<button class="btn btn-secondary" type="button" data-share><i class="bi bi-link-45deg" aria-hidden="true"></i>Kopjo lidhjen</button>' +
      '<button class="btn btn-secondary" type="button" data-print><i class="bi bi-printer" aria-hidden="true"></i>Printo</button>' : '') +
      '<button class="btn btn-ghost" type="button" data-again><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Kontrollo një tjetër</button>' +
    '</div>';
  }

  function renderValid(json) {
    var person = json.kind === 'person';
    var d = (person ? json.person : json.student) || {};
    var full = [d.first_name, d.father_name, d.last_name].filter(Boolean).join(' ') || '—';
    var idLine = 'Numri personal: <span class="code">' + esc(d.personal_number_masked || '—') + '</span>';
    if (!person && d.amze) idLine += ' · Nr. i amzës: <span class="code">' + esc(d.amze) + '</span>';

    var rows = (d.groups || []).map(function (g) {
      var planned = g.row_kind === 'planned';
      return '<tr>' +
        '<td><span class="person-name">' + esc(g.course_name || '—') + '</span><span class="cell-sub">' + esc(g.course_code || '') + '</span></td>' +
        '<td><span class="id-code">' + esc(g.amze || d.amze || '—') + '</span></td>' +
        '<td>' + (planned ? status('Pret grupin', 'neutral', 'bi-hourglass-split') : status('Në regjistër', 'success', 'bi-check-circle-fill')) + '</td>' +
      '</tr>';
    }).join('');

    /* Verifikimi publik tregon vetëm personin dhe modulet — asgjë tjetër. */
    result.innerHTML =
      '<p class="result-person">' + esc(full) + '</p>' +
      '<p class="result-id">' + idLine + '</p>' +
      '<h3 class="section-title mb-2">Modulet në regjistër</h3>' +
      (rows
        ? '<div class="table-responsive"><table class="table"><thead><tr><th scope="col">Moduli</th><th scope="col">Nr. i amzës</th><th scope="col">Gjendja</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
        : '<p class="text-muted">Nuk ka ende module të regjistruara.</p>') +
      actionsHtml(true);
  }

  function renderInvalid(json) {
    result.innerHTML =
      '<p class="mb-2">Çfarë mund të bësh:</p>' +
      '<ul class="mb-3">' +
        '<li>Kontrollo që kodi të jetë shkruar i plotë dhe pa hapësira.</li>' +
        '<li>Provo ta skanosh sërish kodin QR, me dritë të mirë.</li>' +
        '<li>Nëse kodi është i saktë dhe prapë nuk gjendet, certifikata mund të jetë e rreme. Njofto QTA-në: ' +
          '<a href="tel:+355698778837">+355 69 877 8837</a> · <a href="mailto:officialqta@gmail.com">officialqta@gmail.com</a>.</li>' +
      '</ul>' +
      (json && json.reason ? '<p class="text-muted small mb-0">Detaj: ' + esc(json.reason) + '</p>' : '') +
      actionsHtml();
  }

  function render(json, payload) {
    if (payload) lastPayload = payload;
    if (!json || json.ok !== true) {
      setVerdict('error');
      result.innerHTML = '<p class="mb-0">Provo sërish pas pak. Nëse vazhdon, na kontakto.</p>' + actionsHtml();
      return;
    }
    if (json.valid) {
      setVerdict('valid');
      renderValid(json);
      var link = shareLink(lastPayload, json);
      if (link && window.history && window.history.replaceState) window.history.replaceState({}, '', link);
    } else {
      setVerdict('invalid');
      renderInvalid(json);
    }
    var box = verdict.getBoundingClientRect();
    if (box.top < 0 || box.top > window.innerHeight * 0.6) {
      verdict.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  function verify(payload) {
    payload = String(payload || '').trim();
    if (!payload) return;
    lastPayload = payload;
    setVerdict('loading');
    fetch('verify.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ action: 'verify', payload: payload })
    })
      .then(function (r) { return r.json(); })
      .then(function (json) { render(json, payload); })
      .catch(function () { render(null, payload); });
  }

  /* ------------------------------------------------------------- Kamera */
  function setScanStatus(text, isError) {
    if (!scanStatus) return;
    scanStatus.textContent = text;
    scanStatus.classList.toggle('is-error', !!isError);
  }

  function stopScan() {
    var done = function () {
      running = false;
      if (scanBox) scanBox.classList.remove('is-on');
      if (btnScan) btnScan.hidden = false;
    };
    if (camQr && running) {
      return camQr.stop().then(function () { try { camQr.clear(); } catch (e) {} done(); }, done);
    }
    done();
    return Promise.resolve();
  }

  function onDecode(text) {
    if (busy) return;
    busy = true;
    setScanStatus('Kodi u lexua. Po e kontrollojmë…');
    stopScan().then(function () {
      if (input) input.value = text;
      verify(text);
      busy = false;
    });
  }

  function startCamera(deviceId) {
    if (!camQr) camQr = new window.Html5Qrcode('reader');
    var config = {
      fps: 10,
      qrbox: function (w, h) { var s = Math.max(200, Math.min(320, Math.floor(Math.min(w, h) * 0.8))); return { width: s, height: s }; }
    };
    return camQr.start(deviceId, config, onDecode, function () {}).then(function () {
      running = true;
      setScanStatus('Drejtoje kamerën te kodi QR. Kontrolli nis vetë sapo kodi lexohet.');
      if (btnStop) btnStop.focus();
    });
  }

  function startScan() {
    if (!window.Html5Qrcode) {
      setScanStatus('Skaneri nuk u ngarkua. Shkruaj kodin më poshtë.', true);
      return;
    }
    setScanStatus('Po hap kamerën… Nëse shfletuesi pyet, zgjidh "Lejo".');
    if (scanBox) scanBox.classList.add('is-on');
    if (btnScan) btnScan.hidden = true;
    window.Html5Qrcode.getCameras().then(function (list) {
      cameras = Array.isArray(list) ? list : [];
      if (!cameras.length) throw new Error('none');
      var back = cameras.findIndex(function (c) { return /back|rear|mbrapa|environment/i.test(c.label || ''); });
      var index = back >= 0 ? back : cameras.length - 1;
      if (camSelect) {
        camSelect.innerHTML = cameras.map(function (c, i) {
          return '<option value="' + i + '">' + esc(c.label || ('Kamera ' + (i + 1))) + '</option>';
        }).join('');
        camSelect.value = String(index);
        camSelect.hidden = cameras.length < 2;
      }
      return startCamera(cameras[index].id);
    }).catch(function () {
      stopScan();
      setScanStatus('Kamera nuk u lejua ose nuk u gjet. Mund të ngarkosh një foto të kodit ose ta shkruash më poshtë.', true);
    });
  }

  if (btnScan) btnScan.addEventListener('click', startScan);
  if (btnStop) btnStop.addEventListener('click', function () {
    stopScan().then(function () {
      setScanStatus('Kamera u ndal.');
      if (btnScan) btnScan.focus();
    });
  });
  if (camSelect) camSelect.addEventListener('change', function () {
    var cam = cameras[parseInt(camSelect.value, 10)];
    if (!cam) return;
    stopScan().then(function () {
      if (scanBox) scanBox.classList.add('is-on');
      if (btnScan) btnScan.hidden = true;
      startCamera(cam.id).catch(function () { setScanStatus('Kjo kamerë nuk u hap. Provo një tjetër.', true); });
    });
  });

  document.addEventListener('visibilitychange', function () { if (document.hidden) stopScan(); });
  window.addEventListener('pagehide', function () { stopScan(); });

  /* ---------------------------------------------------------------- Foto */
  if (fileInput) fileInput.addEventListener('change', function () {
    var file = fileInput.files && fileInput.files[0];
    if (!file) return;
    if (!window.Html5Qrcode) { setScanStatus('Leximi i fotos nuk është i disponueshëm. Shkruaj kodin.', true); return; }
    if (!fileQr) fileQr = new window.Html5Qrcode('file-reader');
    setScanStatus('Po lexoj kodin nga fotoja…');
    fileQr.scanFile(file, false).then(function (text) {
      setScanStatus('Kodi u lexua nga fotoja.');
      if (input) input.value = text;
      verify(text);
    }).catch(function () {
      setScanStatus('Nuk u gjet kod QR në këtë foto. Provo një foto më të qartë, nga afër dhe pa reflektim.', true);
    }).finally(function () { fileInput.value = ''; });
  });

  /* ------------------------------------------------------- Kodi me dorë */
  if (form) form.addEventListener('submit', function (event) {
    event.preventDefault();
    var value = input ? input.value.trim() : '';
    if (!value) {
      if (input) { input.classList.add('is-invalid'); input.focus(); }
      setScanStatus('Shkruaj kodin e certifikatës, pastaj shtyp "Kontrollo kodin".', true);
      return;
    }
    input.classList.remove('is-invalid');
    verify(value);
  });
  if (input) input.addEventListener('input', function () { input.classList.remove('is-invalid'); });

  if (btnPaste) btnPaste.addEventListener('click', function () {
    if (!navigator.clipboard || !navigator.clipboard.readText) {
      toast('Shfletuesi nuk lejon ngjitjen automatike. Përdor Ctrl+V në fushë.', 'warning');
      if (input) input.focus();
      return;
    }
    navigator.clipboard.readText().then(function (text) {
      if (!text) { toast('Kujtesa është bosh.', 'warning'); return; }
      input.value = text.trim();
      verify(input.value);
    }, function () {
      toast('Shfletuesi nuk lejoi ngjitjen. Përdor Ctrl+V në fushë.', 'warning');
      if (input) input.focus();
    });
  });

  /* ------------------------------------------------ Veprimet e rezultatit */
  result.addEventListener('click', function (event) {
    if (event.target.closest('[data-print]')) { window.print(); return; }
    if (event.target.closest('[data-again]')) {
      resetResult();
      if (input) { input.value = ''; input.focus(); }
      if (window.history && window.history.replaceState) window.history.replaceState({}, '', location.pathname);
      return;
    }
    if (event.target.closest('[data-share]')) {
      var link = location.href;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(function () { toast('Lidhja u kopjua.', 'success'); },
          function () { toast('Kopjimi nuk u lejua nga shfletuesi.', 'warning'); });
      }
    }
  });

  /* --------------------------------------------------------------- Nisja */
  var prefill = document.getElementById('verifyPrefill');
  if (prefill) {
    try { render(JSON.parse(prefill.textContent || 'null'), input ? input.value : ''); }
    catch (e) { resetResult(); }
  } else if (location.hash === '#skano') {
    startScan();
  }
})();
