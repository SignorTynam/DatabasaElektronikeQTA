(function () {
  'use strict';
  if (window.qtaDocumentGeneration) return;
  var dialog = document.getElementById('documentGeneration');
  if (!dialog) return;
  var endpointNames = ['students_export.php', 'groups_export.php', 'register_export_agency.php',
    'download_proces_verbal.php', 'download_lista_emerore.php', 'download_regjistri_mesimit.php',
    'download_praktika_profesionale.php', 'download_rregullat_sigurimi_teknik.php', 'activity_log_export.php'];
  var scriptUrl = new URL(document.currentScript ? document.currentScript.src :
    document.querySelector('script[src*="document-generation.js"]').src);
  // Works both at legacy rewritten URLs and /app/pages/*.php.
  var api = new URL('../../actions/document_generation.php', scriptUrl);
  var storageKey = 'qtaDocumentGeneration:' + api.pathname;
  var status = dialog.querySelector('[data-document-status]');
  var percent = dialog.querySelector('[data-document-percent]');
  var progress = dialog.querySelector('progress');
  var errorBox = dialog.querySelector('[data-document-error]');
  var actions = dialog.querySelector('[data-document-actions]');
  var retryButton = dialog.querySelector('[data-document-retry]');
  var active = false, pending = null, trigger = null, jobId = null, busy = false;

  function remember(id) {
    jobId = id;
    try {
      if (id) sessionStorage.setItem(storageKey, JSON.stringify({ id: id, fields: pending ? Array.from(pending.entries()) : [] }));
      else sessionStorage.removeItem(storageKey);
    } catch (_) {}
  }
  function update(value, message) {
    progress.value = value;
    percent.textContent = value + '%';
    status.textContent = message;
  }
  function open() {
    active = true;
    errorBox.hidden = true;
    actions.hidden = true;
    dialog.querySelector('h2').textContent = 'Po përgatitet dokumenti';
    document.body.classList.add('is-generating-document');
    if (!dialog.open) dialog.showModal();
    dialog.querySelector('h2').focus();
  }
  function fail(message) {
    busy = false;
    errorBox.textContent = message;
    errorBox.hidden = false;
    actions.hidden = false;
    retryButton.disabled = false;
    status.textContent = 'Përgatitja nuk përfundoi.';
    dialog.querySelector('h2').textContent = 'Dokumenti nuk u krijua';
    retryButton.focus();
  }
  function apiUrl(action, id) {
    var url = new URL(api);
    url.searchParams.set('action', action);
    if (id) url.searchParams.set('id', id);
    return url;
  }
  async function json(response) {
    var data;
    try { data = await response.json(); }
    catch (_) { throw new Error('Serveri nuk e përfundoi kërkesën. Provo përsëri.'); }
    if (!response.ok || data.error) throw new Error(data.error || 'Dokumenti nuk u krijua. Provo përsëri.');
    return data;
  }
  function pause() { return new Promise(function (resolve) { setTimeout(resolve, 1000); }); }

  async function receive(state) {
    update(100, 'Dokumenti u krijua. Po merret skedari.');
    var response = await fetch(apiUrl('file', jobId), { credentials: 'same-origin', cache: 'no-store' });
    if (!response.ok) { await json(response); return; }
    var blob = await response.blob();
    if (blob.size !== state.size || blob.size === 0) throw new Error('Skedari nuk u mor plotësisht. Provo përsëri.');
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = state.filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
    remember(null);
    busy = active = false;
    dialog.close();
    document.body.classList.remove('is-generating-document');
    if (trigger && trigger.isConnected) {
      var parentForm = trigger.closest('form');
      var returnTo = trigger.getClientRects().length ? trigger : parentForm && parentForm.querySelector('[data-bs-toggle="dropdown"]');
      if (returnTo) returnTo.focus();
    }
    if (window.qtaToast) window.qtaToast('Dokumenti është gati. Kontrollo dosjen “Shkarkime”.', 'success', 'Dokumenti u krijua');
  }
  async function poll() {
    var state;
    for (;;) {
      try {
        var response = await fetch(apiUrl('status', jobId), { credentials: 'same-origin', cache: 'no-store' });
        // Temporary service/network failures never restart generation or unlock the page.
        if ([502, 503, 504].indexOf(response.status) !== -1) {
          status.textContent = 'Po pritet lidhja me serverin. Përgatitja nuk është anuluar.';
          await pause(); continue;
        }
        state = await json(response);
      } catch (error) {
        if (error instanceof TypeError) {
          status.textContent = 'Lidhja u ndërpre. Po provohet përsëri pa anuluar dokumentin.';
          await pause(); continue;
        }
        throw error;
      }
      update(state.percent, state.message);
      if (state.status === 'error') throw new Error(state.message);
      if (state.status === 'ready') { await receive(state); return; }
      await pause();
    }
  }
  async function run(resume) {
    if (busy) return;
    busy = true;
    open();
    try {
      if (!resume) {
        update(0, 'Po nis përgatitja e dokumentit.');
        var response = await fetch(apiUrl('start'), { method: 'POST', body: pending, credentials: 'same-origin' });
        var data = await json(response);
        if (!/^[a-f0-9]{48}$/.test(data.id || '')) throw new Error('Përgatitja nuk mund të ndiqet. Provo përsëri.');
        remember(data.id);
      }
      await poll();
    } catch (error) { fail(error.message || 'Dokumenti nuk u krijua. Provo përsëri.'); }
  }
  function exportUrl(raw) {
    var url = new URL(raw, location.href);
    return url.origin === location.origin && endpointNames.indexOf(url.pathname.split('/').pop()) !== -1 ? url : null;
  }
  function prepare(url, data, source) {
    if (active) return;
    pending = data;
    pending.set('_document_endpoint', url.pathname.split('/').pop());
    trigger = source;
    remember(null);
    run(false);
  }
  document.addEventListener('submit', function (event) {
    var form = event.target;
    var url = form.tagName === 'FORM' ? exportUrl(form.action) : null;
    if (!url) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (active) return;
    var data = new FormData(form);
    var button = event.submitter;
    if (button && button.name) data.append(button.name, button.value);
    url.searchParams.forEach(function (value, key) { if (!data.has(key)) data.append(key, value); });
    prepare(url, data, button || form);
  }, true);
  document.addEventListener('click', function (event) {
    var link = event.target.closest('a[href]');
    var url = link ? exportUrl(link.dataset.documentEndpoint || link.href) : null;
    if (!url) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    var data = new FormData();
    new URL(link.href).searchParams.forEach(function (value, key) { data.append(key, value); });
    if (link.dataset.documentCsrf) data.set('csrf', link.dataset.documentCsrf);
    prepare(url, data, link);
  }, true);
  // Native modal semantics make the page inert, including Bootstrap dialogs underneath.
  dialog.addEventListener('cancel', function (event) { event.preventDefault(); });
  document.addEventListener('focusin', function (event) {
    // Keep a Bootstrap dialog below from pulling focus out of the native modal.
    if (active && dialog.contains(event.target)) event.stopImmediatePropagation();
  }, true);
  document.addEventListener('keydown', function (event) {
    if (!active) return;
    if (event.key === 'Tab') {
      var controls = Array.from(dialog.querySelectorAll('button:not([disabled]), a[href], [tabindex="0"]')).filter(function (el) { return el.getClientRects().length; });
      if (!controls.length) { event.preventDefault(); dialog.querySelector('h2').focus(); }
      else if (controls.length === 1 || event.shiftKey && document.activeElement === controls[0] || !event.shiftKey && document.activeElement === controls[controls.length - 1]) {
        event.preventDefault(); controls[event.shiftKey ? controls.length - 1 : 0].focus();
      }
      event.stopImmediatePropagation();
      return;
    }
    if (event.key === 'Escape' || (event.ctrlKey || event.metaKey) && ['k', 's', 'p'].indexOf(event.key.toLowerCase()) !== -1 || event.key === '/') {
      event.preventDefault(); event.stopImmediatePropagation();
    }
    event.stopImmediatePropagation();
  }, true);
  window.addEventListener('beforeunload', function (event) {
    if (active) { event.preventDefault(); event.returnValue = ''; }
  });
  retryButton.addEventListener('click', async function () {
    if (busy) return;
    retryButton.disabled = true;
    // A completed job whose transfer failed is downloaded again without regenerating it.
    if (jobId) {
      try {
        var state = await json(await fetch(apiUrl('status', jobId), { credentials: 'same-origin', cache: 'no-store' }));
        if (state.status !== 'error') { run(true); return; }
      } catch (error) { fail(error.message); return; }
    }
    if (pending) { remember(null); run(false); }
    else fail('Rihap faqen e dokumentit dhe kërko shkarkimin përsëri.');
  });
  window.qtaDocumentGeneration = { isActive: function () { return active; } };
  try {
    var saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
    if (saved && /^[a-f0-9]{48}$/.test(saved.id)) {
      jobId = saved.id;
      pending = new FormData();
      saved.fields.forEach(function (field) { pending.append(field[0], field[1]); });
    }
  } catch (_) {}
  if (jobId) run(true);
})();
