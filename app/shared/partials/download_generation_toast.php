<?php
// Njoftimi i progresit gjatë gjenerimit të dokumenteve (Excel/PDF/Word).
// Eksportet vendosin cookie-n qta_file_ready kur dokumenti është gati.
?>
<script>
(function () {
  if (window.qtaDownloadToast) return;

  var READY_COOKIE = 'qta_file_ready';
  var MSG_COOKIE = 'qta_file_msg';
  var DOWNLOAD_RE = /(?:register_export|students_export|groups_export|register_export_agency|download_[a-z_]+)\.php\b/i;

  var pollTimer = null;
  var timeoutTimer = null;
  var activeToast = null;

  function setCookie(name, value, maxAgeSec) {
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAgeSec + '; SameSite=Lax';
  }
  function getCookie(name) {
    var match = document.cookie.split(';').map(function (c) { return c.trim(); })
      .find(function (c) { return c.indexOf(name + '=') === 0; });
    return match ? match.slice(name.length + 1) : '';
  }
  function clearReadyCookie() { setCookie(READY_COOKIE, '', 0); setCookie(MSG_COOKIE, '', 0); }

  function toast(message, variant, title, opts) {
    return window.qtaToast ? window.qtaToast(message, variant, title, opts) : null;
  }

  function hideActive() {
    if (activeToast && window.bootstrap) {
      var inst = window.bootstrap.Toast.getInstance(activeToast);
      if (inst) inst.hide(); else activeToast.remove();
    }
    activeToast = null;
  }

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    if (timeoutTimer) { clearTimeout(timeoutTimer); timeoutTimer = null; }
  }

  function finish(finalType, finalMessage) {
    stopPolling();
    clearReadyCookie();
    hideActive();
    if (finalType && finalMessage) toast(finalMessage, finalType, finalType === 'success' ? 'Dokumenti është gati' : 'Dokumenti nuk u krijua');
  }

  function start(message) {
    if (pollTimer) return;
    clearReadyCookie();
    activeToast = toast(message || 'Dokumenti po përgatitet. Mund të vazhdosh punën.', 'info', 'Po përgatitet dokumenti', { autohide: false });

    pollTimer = setInterval(function () {
      var status = decodeURIComponent(getCookie(READY_COOKIE) || '').trim().toLowerCase();
      if (!status) return;
      var rawMsg = getCookie(MSG_COOKIE);
      var msg = rawMsg ? decodeURIComponent(rawMsg) : '';
      if (status === 'ok' || status === 'success') {
        finish('success', msg || 'Dokumenti u shkarkua. Kontrollo dosjen "Shkarkime".');
      } else {
        finish('danger', msg || 'Dokumenti nuk u krijua. Provo përsëri.');
      }
    }, 500);

    timeoutTimer = setTimeout(function () {
      finish('danger', 'Dokumenti po vonon shumë. Provo përsëri ose zvogëlo listën me filtra.');
    }, 5 * 60 * 1000);
  }

  window.qtaDownloadToast = { start: start, finish: finish };

  document.addEventListener('click', function (ev) {
    var a = ev.target.closest ? ev.target.closest('a[href]') : null;
    if (a && DOWNLOAD_RE.test(a.getAttribute('href') || '')) start(a.dataset.downloadToast);
  }, true);

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (form && form.tagName === 'FORM' && DOWNLOAD_RE.test(form.getAttribute('action') || '')) {
      start(form.dataset.downloadToast);
    }
  }, true);
})();
</script>
