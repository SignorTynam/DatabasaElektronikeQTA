<?php
// Shared helper: uses the existing page toast system (`notify`) for download progress.
?>
<script>
(function () {
  if (window.qtaDownloadToast) return;

  const READY_COOKIE = 'qta_file_ready';
  const MSG_COOKIE = 'qta_file_msg';
  const DOWNLOAD_RE = /(?:register_export|students_export|groups_export|register_export_agency|download_[a-z_]+)\.php\b/i;

  let pollTimer = null;
  let timeoutTimer = null;
  let activeToastEl = null;
  let activeToastInstance = null;

  function setCookie(name, value, maxAgeSec) {
    document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${maxAgeSec}; SameSite=Lax`;
  }

  function getCookie(name) {
    const match = document.cookie
      .split(';')
      .map((c) => c.trim())
      .find((c) => c.startsWith(name + '='));
    if (!match) return '';
    return match.slice(name.length + 1);
  }

  function clearReadyCookie() {
    setCookie(READY_COOKIE, '', 0);
    setCookie(MSG_COOKIE, '', 0);
  }

  function ensureZone() {
    let zone = document.getElementById('toastZone');
    if (zone) return zone;
    if (!document.body) return null;

    zone = document.createElement('div');
    zone.id = 'toastZone';
    zone.className = 'toast-container position-fixed top-0 end-0 p-3';
    zone.style.zIndex = '1200';
    document.body.appendChild(zone);
    return zone;
  }

  function hideActiveToast() {
    if (activeToastInstance) {
      activeToastInstance.hide();
    } else if (activeToastEl) {
      activeToastEl.remove();
    }
    activeToastEl = null;
    activeToastInstance = null;
  }

  function showWithNotify(message) {
    if (typeof window.notify !== 'function') return null;
    const zone = ensureZone();
    if (!zone) return null;

    const before = zone.lastElementChild;
    window.notify('info', message, {
      title: 'Ju lutem prisni',
      autohide: false
    });
    const after = zone.lastElementChild;
    if (after && after !== before) {
      return after;
    }
    return null;
  }

  function showFallback(message) {
    if (typeof bootstrap === 'undefined') return null;
    const zone = ensureZone();
    if (!zone) return null;

    const id = 'download-progress-' + Date.now().toString(36);
    const html = `
      <div id="${id}" class="toast qta-toast toast-info" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="toast-header">
          <i class="bi bi-info-circle me-2"></i>
          <strong class="me-auto">Ju lutem prisni</strong>
          <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Mbyll"></button>
        </div>
        <div class="toast-body">
          <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${message}
        </div>
      </div>`;

    zone.insertAdjacentHTML('beforeend', html);
    return document.getElementById(id);
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
    if (timeoutTimer) {
      clearTimeout(timeoutTimer);
      timeoutTimer = null;
    }
  }

  function notifyFinal(type, message) {
    if (typeof window.notify === 'function') {
      window.notify(type, message, { delay: 4500 });
      return;
    }
    // fallback minimal
    if (typeof bootstrap !== 'undefined') {
      const zone = ensureZone();
      if (!zone) return;
      const id = 'download-final-' + Date.now().toString(36);
      const icon = type === 'success' ? 'check-circle' : 'exclamation-triangle';
      const title = type === 'success' ? 'Sukses' : 'Gabim';
      const html = `
        <div id="${id}" class="toast qta-toast toast-${type}" role="alert" aria-live="assertive" aria-atomic="true">
          <div class="toast-header">
            <i class="bi bi-${icon} me-2"></i>
            <strong class="me-auto">${title}</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Mbyll"></button>
          </div>
          <div class="toast-body">${message}</div>
        </div>`;
      zone.insertAdjacentHTML('beforeend', html);
      const el = document.getElementById(id);
      const t = bootstrap.Toast.getOrCreateInstance(el, { autohide: true, delay: 4500 });
      el.addEventListener('hidden.bs.toast', () => el.remove(), { once: true });
      t.show();
    }
  }

  function finish(finalType, finalMessage) {
    stopPolling();
    clearReadyCookie();
    hideActiveToast();
    if (finalType && finalMessage) {
      notifyFinal(finalType, finalMessage);
    }
  }

  function start(message) {
    // Avoid duplicate progress toasts from chained click+submit handlers.
    if (pollTimer) return;

    const msg = message || 'Dokumenti po gjenerohet. Ju lutem prisni...';
    clearReadyCookie();

    let toastEl = showWithNotify(msg);
    if (!toastEl) toastEl = showFallback(msg);
    if (toastEl && typeof bootstrap !== 'undefined') {
      activeToastEl = toastEl;
      activeToastInstance = bootstrap.Toast.getOrCreateInstance(toastEl, { autohide: false });
      activeToastInstance.show();
      toastEl.addEventListener('hidden.bs.toast', function () {
        toastEl.remove();
      }, { once: true });
    }

    stopPolling();
    pollTimer = setInterval(function () {
      const status = decodeURIComponent(getCookie(READY_COOKIE) || '').trim().toLowerCase();
      if (!status) return;

      const rawMsg = getCookie(MSG_COOKIE);
      const msg = rawMsg ? decodeURIComponent(rawMsg) : '';

      if (status === 'ok' || status === 'success') {
        finish('success', msg || 'Dokumenti u gjenerua me sukses.');
      } else {
        finish('danger', msg || 'Dokumenti nuk u gjenerua. Ju lutem provo perseri.');
      }
    }, 500);

    timeoutTimer = setTimeout(function () {
      finish('danger', 'Dokumenti nuk u gjenerua. Ju lutem provo perseri.');
    }, 5 * 60 * 1000);
  }

  window.qtaDownloadToast = { start, finish };

  document.addEventListener('click', function (ev) {
    const a = ev.target.closest('a[href]');
    if (!a) return;
    const href = a.getAttribute('href') || '';
    if (DOWNLOAD_RE.test(href)) {
      start(a.dataset.downloadToast || 'Dokumenti po gjenerohet. Ju lutem prisni...');
    }
  }, true);

  document.addEventListener('submit', function (ev) {
    const form = ev.target;
    if (!form || form.tagName !== 'FORM') return;
    const action = form.getAttribute('action') || '';
    if (DOWNLOAD_RE.test(action)) {
      start(form.dataset.downloadToast || 'Dokumenti po gjenerohet. Ju lutem prisni...');
    }
  }, true);
})();
</script>

