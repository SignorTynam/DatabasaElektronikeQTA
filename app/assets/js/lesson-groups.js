/* ============================================================================
   lesson-groups.js — Grupet me orar (lesson_groups.php): krijimi i grupit.
   Parashikimi i datës së mbarimit vjen nga serveri (i njëjti motor që ruan
   orarin); këtu vetëm pyetet dhe shfaqet përgjigja.
   ========================================================================= */
(function () {
  'use strict';

  var cfgEl = document.getElementById('lgConfig');
  if (!cfgEl) return;
  var CFG = JSON.parse(cfgEl.textContent || '{}');

  function toast(message, variant, opts) {
    if (window.qtaToast) window.qtaToast(message, variant || 'success', null, opts || {});
  }
  document.addEventListener('DOMContentLoaded', function () {
    if (CFG.flash_ok) toast(CFG.flash_ok, 'success', { delay: 7000 });
    if (CFG.flash_err) toast(CFG.flash_err, 'danger', { autohide: false });
  });

  var form = document.querySelector('form[data-lg-create]');
  if (!form) return;
  var box = form.querySelector('[data-lg-preview]');
  var text = form.querySelector('[data-lg-preview-text]');
  var timer = null;
  var seq = 0;

  function setPreview(kind, message) {
    box.classList.remove('is-ok', 'is-error');
    if (kind) box.classList.add(kind === 'ok' ? 'is-ok' : 'is-error');
    text.textContent = message;
  }

  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

  function preview() {
    var course = form.elements.course_id.value;
    var start = form.elements.start_date.value.trim();
    var daily = form.elements.daily_hours.value.trim();
    if (!course || !/^\d{1,2}[.\-\/]\d{1,2}[.\-\/]\d{4}$/.test(start) || !daily) {
      setPreview('', 'Zgjidh kursin, datën e fillimit dhe orët në ditë: këtu del kur mbaron mësimi.');
      return;
    }
    var mine = ++seq;
    setPreview('', 'Po llogaris orarin…');
    fetch(CFG.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ csrf: CFG.csrf, action: 'preview_new', course_id: course, start_date: start, daily_hours: daily })
    }).then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (json) {
        if (mine !== seq) return;
        if (!json) { setPreview('error', 'Parashikimi nuk u mor. Kontrollo lidhjen; grupi mund të krijohet sërish.'); return; }
        if (!json.ok) { setPreview('error', json.error || 'Kontrollo të dhënat.'); return; }
        var s = json.summary;
        setPreview('ok', 'Mbaron ' + s.end_on + ' · ' + plural(s.days, 'ditë mësimi', 'ditë mësimi') + ' për ' + s.total_hours + ' orë'
          + (s.last_day_hours < parseInt(daily, 10) ? ' · dita e fundit ka ' + plural(s.last_day_hours, 'orë', 'orë') : '')
          + (s.sundays_skipped ? ' · ' + plural(s.sundays_skipped, 'e diel', 'të diela') + ' pa mësim' : '') + '.');
      })
      .catch(function () { if (mine === seq) setPreview('error', 'Parashikimi nuk u mor. Kontrollo lidhjen.'); });
  }

  function schedule() { clearTimeout(timer); timer = setTimeout(preview, 250); }
  ['course_id', 'start_date', 'daily_hours'].forEach(function (name) {
    var el = form.elements[name];
    if (!el) return;
    el.addEventListener('input', schedule);
    el.addEventListener('change', schedule);
  });
  var modalEl = document.getElementById('createLessonGroup');
  if (modalEl) modalEl.addEventListener('shown.bs.modal', function () {
    preview();
    var first = form.elements.course_id.value ? form.elements.start_date : form.elements.course_id;
    if (first && !first.disabled) first.focus();
  });

  /* Numrat e amzës: "3400-3403, 3409" → lista e renditur (vetëm për parashikimin e ndarjes). */
  function parseAmze(s) {
    var out = {};
    String(s || '').split(/[,;\n]/).forEach(function (raw) {
      var tok = raw.trim();
      var m = tok.match(/^(\d+)\s*[-–]\s*(\d+)$/);
      if (m) {
        var a = parseInt(m[1], 10), b = parseInt(m[2], 10);
        if (a > b) { var t = a; a = b; b = t; }
        if (b - a > 400) return;
        for (var i = a; i <= b; i++) out[i] = true;
      } else if (/^\d+$/.test(tok)) {
        out[parseInt(tok, 10)] = true;
      }
    });
    return Object.keys(out).map(Number).sort(function (x, y) { return x - y; });
  }

  form.addEventListener('submit', function (ev) {
    if (form.dataset.ready === '1') return;
    var missing = [];
    if (!form.elements.course_id.value) missing.push('kursin');
    if (!form.elements.start_date.value.trim()) missing.push('datën e fillimit');
    if (!form.elements.daily_hours.value.trim()) missing.push('orët në ditë');
    if (missing.length) {
      ev.preventDefault();
      toast('Plotëso ' + missing.join(', ') + '.', 'warning');
      (form.elements.course_id.value ? (form.elements.start_date.value.trim() ? form.elements.daily_hours : form.elements.start_date) : form.elements.course_id).focus();
      return;
    }
    var nums = parseAmze(form.elements.amze_spec.value);
    if (nums.length <= 10) return;
    ev.preventDefault();
    var groups = Math.ceil(nums.length / 10);
    var base = Math.floor(nums.length / groups), rem = nums.length % groups, cursor = 0, parts = [];
    for (var g = 0; g < groups; g++) {
      var size = base + (g < rem ? 1 : 0);
      var chunk = nums.slice(cursor, cursor + size);
      cursor += size;
      parts.push('grupi ' + (g + 1) + ': ' + chunk.length + ' kursantë (' + chunk[0] + (chunk.length > 1 ? '–' + chunk[chunk.length - 1] : '') + ')');
    }
    window.qtaConfirm({
      title: 'Do të krijohen ' + groups + ' grupe',
      message: 'Ke shkruar ' + nums.length + ' numra amze. Një grup mban deri në 10 kursantë, prandaj krijohen ' + groups
        + ' grupe me të njëjtin kurs dhe orar: ' + parts.join('; ') + '.',
      confirm: 'Po, krijo ' + groups + ' grupe',
      danger: false
    }).then(function (ok) {
      if (!ok) return;
      form.dataset.ready = '1';
      var btn = form.querySelector('button[type="submit"]');
      if (btn) btn.classList.add('is-loading');
      if (form.requestSubmit) form.requestSubmit(); else form.submit();
    });
  });
})();
