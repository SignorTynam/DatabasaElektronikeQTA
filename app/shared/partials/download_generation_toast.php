<?php
// Shared helper: uses the existing page toast system (`notify`) for download progress.
?>
<script>
(function () {
  if (window.qtaDownloadToast) return;

  const READY_COOKIE = 'qta_file_ready';
  const DOWNLOAD_RE = /(?:register_export|students_export|groups_export|register_export_agency|download_[a-z_]+)\.php\b/i;

  let pollTimer = null;
  let timeoutTimer = null;
  let activeToastEl = null;
  let activeToastInstance = null;

  function setCookie(name, value, maxAgeSec) {
    document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${maxAgeSec}; SameSite=Lax`;
  }

  function clearReadyCookie() {
    setCookie(READY_COOKIE, '', 0);
  }

  function hasReadyCookie() {
    return document.cookie.split(';').some((c) => c.trim().startsWith(READY_COOKIE + '='));
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

  function finish() {
    stopPolling();
    clearReadyCookie();
    hideActiveToast();
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
      if (hasReadyCookie()) {
        finish();
      }
    }, 500);

    timeoutTimer = setTimeout(function () {
      stopPolling();
      hideActiveToast();
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
