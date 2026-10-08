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
  var calculatedFields = form.querySelector('[data-lg-calculated-fields]');
  var fixedFields = form.querySelector('[data-lg-fixed-fields]');
  var dailyInput = form.elements.daily_hours;
  var endInput = form.elements.end_date;

  function mode() {
    return form.querySelector('input[name="schedule_mode"]:checked').value;
  }

  function syncMode() {
    var fixed = mode() === 'fixed_range';
    calculatedFields.hidden = fixed;
    fixedFields.hidden = !fixed;
    dailyInput.required = !fixed;
    endInput.required = fixed;
    if (CFG.edit) {
      dailyInput.disabled = fixed;
      endInput.disabled = !fixed;
    }
    setPreview('', fixed
      ? 'Plotëso kursin, fillimin dhe mbarimin: sistemi propozon shpërndarjen e orëve.'
      : 'Plotëso kursin, fillimin dhe orët në ditë: këtu del kur mbaron mësimi.');
    schedule();
  }

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
    var end = form.elements.end_date.value.trim();
    var scheduleMode = mode();
    var dateOk = /^\d{1,2}[.\-\/]\d{1,2}[.\-\/]\d{4}$/;
    if (!course || !dateOk.test(start) || (scheduleMode === 'calculated' ? !daily : !dateOk.test(end))) {
      setPreview('', scheduleMode === 'fixed_range'
        ? 'Plotëso kursin, fillimin dhe mbarimin: sistemi propozon shpërndarjen e orëve.'
        : 'Plotëso kursin, fillimin dhe orët në ditë: këtu del kur mbaron mësimi.');
      return;
    }
    var mine = ++seq;
    setPreview('', 'Po llogaris orarin…');
    window.qtaFetch.response(CFG.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ csrf: CFG.csrf, action: 'preview_new', course_id: course,
        schedule_mode: scheduleMode, start_date: start, end_date: end, daily_hours: daily })
    }).then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (json) {
        if (mine !== seq) return;
        if (!json) { setPreview('error', 'Parashikimi nuk u mor. Kontrollo lidhjen; grupi mund të krijohet sërish.'); return; }
        if (!json.ok) { setPreview('error', json.error || 'Kontrollo të dhënat.'); return; }
        var s = json.summary;
        if (json.schedule_mode === 'fixed_range') {
          var f = s.fixed;
          var sundayCount = f.sundays.length + f.boundary_sundays.length;
          setPreview('ok', 'Periudha ' + s.start_label + ' – ' + s.end_label + ' · '
            + plural(f.teaching_days, 'ditë mësimi', 'ditë mësimi') + ' · '
            + plural(f.off_days, 'ditë pa mësim', 'ditë pa mësim')
            + (sundayCount ? ' · ' + plural(sundayCount, 'e diel me mësim', 'të diela me mësim') + ' për kontroll' : '') + '.');
          return;
        }
        setPreview('ok', 'Mbaron ' + s.end_on + ' · ' + plural(s.days, 'ditë mësimi', 'ditë mësimi') + ' për ' + s.total_hours + ' orë'
          + (s.last_day_hours < parseInt(daily, 10) ? ' · dita e fundit ka ' + plural(s.last_day_hours, 'orë', 'orë') : '')
          + (s.sundays_skipped ? ' · ' + plural(s.sundays_skipped, 'e diel', 'të diela') + ' pa mësim' : '') + '.');
      })
      .catch(function () { if (mine === seq) setPreview('error', 'Parashikimi nuk u mor. Kontrollo lidhjen.'); });
  }

  function schedule() { clearTimeout(timer); timer = setTimeout(preview, 250); }
  ['course_id', 'start_date', 'daily_hours', 'end_date'].forEach(function (name) {
    var el = form.elements[name];
    if (!el) return;
    el.addEventListener('input', schedule);
    el.addEventListener('change', schedule);
  });
  form.querySelectorAll('input[name="schedule_mode"]').forEach(function (el) {
    el.addEventListener('change', syncMode);
  });
  syncMode();
  var modalEl = document.getElementById('createLessonGroup');
  if (modalEl) modalEl.addEventListener('shown.bs.modal', function () {
    preview();
    var first = form.elements.course_id.value ? form.elements.start_date : form.elements.course_id;
    if (first && !first.disabled) first.focus();
  });

  /* Numrat e amzës: "3400-3403, 3409" → lista e renditur (vetëm për parashikimin e ndarjes). */
  function parseAmze(s) {
    if (String(s || '').length > 8192) return []; // Leave authoritative errors to PHP.
    var out = {};
    String(s || '').split(/[,;\n]/).forEach(function (raw) {
      var tok = raw.trim();
      var m = tok.match(/^(\d{1,9})\s*[-–]\s*(\d{1,9})$/);
      if (m) {
        var a = parseInt(m[1], 10), b = parseInt(m[2], 10);
        if (a > b) { var t = a; a = b; b = t; }
        if (b - a >= CFG.amze_max) return;
        for (var i = a; i <= b; i++) out[i] = true;
      } else if (/^\d{1,9}$/.test(tok)) {
        out[parseInt(tok, 10)] = true;
      }
    });
    var numbers = Object.keys(out);
    if (numbers.length > CFG.amze_max) return [];
    return numbers.map(Number).sort(function (x, y) { return x - y; });
  }

  form.addEventListener('submit', function (ev) {
    if (form.dataset.ready === '1') return;
    var missing = [];
    if (!form.elements.course_id.value) missing.push('kursin');
    if (!form.elements.start_date.value.trim()) missing.push('datën e fillimit');
    if (mode() === 'fixed_range') {
      if (!form.elements.end_date.value.trim()) missing.push('datën e mbarimit');
    } else if (!form.elements.daily_hours.value.trim()) {
      missing.push('orët në ditë');
    }
    if (missing.length) {
      ev.preventDefault();
      toast('Plotëso ' + missing.join(', ') + '.', 'warning');
      var focus = form.elements.course_id;
      if (form.elements.course_id.value) {
        focus = !form.elements.start_date.value.trim() ? form.elements.start_date
          : (mode() === 'fixed_range' ? form.elements.end_date : form.elements.daily_hours);
      }
      focus.focus();
      return;
    }
    var nums = parseAmze(form.elements.amze_spec.value);
    if (nums.length <= 10) return;
    ev.preventDefault();
    if (form.dataset.confirming === '1') return;
    form.dataset.confirming = '1';
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
      window.qtaNativeSubmit(form);
    }).finally(function () { delete form.dataset.confirming; });
  });
})();
