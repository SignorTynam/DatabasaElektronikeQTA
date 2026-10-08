/* ============================================================================
   lesson-group.js — Faqja e një grupi me orar (lesson_group.php).

   Orari llogaritet vetëm në server (lesson_group_update.php). Këtu:
     - parashikimi i ndikimit para ruajtjes (i njëjti motor, pa ruajtur);
     - konfirmimi kur serveri e kërkon (ditë që kanë kaluar, grup i mbyllur, fshirje);
     - datat e provimit në tabelë (groups_inline_update.php, si te grupet e tjera); pikët
       vendosen sipas moduleve në dritaren e tyre (group-results.js) dhe këtu rifreskohen
       vetëm gjendjet;
     - skedat, printimi dhe fokusi.
   ========================================================================= */
(function () {
  'use strict';

  var cfgEl = document.getElementById('lgConfig');
  if (!cfgEl) return;
  var CFG = JSON.parse(cfgEl.textContent || '{}');
  var FLASH_KEY = 'qtaFlash';

  function toast(message, variant, opts) {
    if (window.qtaToast) window.qtaToast(message, variant || 'success', null, opts || {});
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function reloadWith(message) {
    try { if (message) sessionStorage.setItem(FLASH_KEY, message); } catch (e) { /* pa kujtesë */ }
    window.location.reload();
  }

  document.addEventListener('DOMContentLoaded', function () {
    var queued = null;
    try { queued = sessionStorage.getItem(FLASH_KEY); sessionStorage.removeItem(FLASH_KEY); } catch (e) { queued = null; }
    if (queued) toast(queued, 'success', { delay: 7000 });
    if (CFG.flash) toast(CFG.flash, 'success', { delay: 7000 });
  });

  /* ---------------------------------------------------------------- Skedat */
  function showTab(id) {
    var btn = document.querySelector('[data-bs-toggle="tab"][data-bs-target="#' + id + '"]');
    if (btn && window.bootstrap) window.bootstrap.Tab.getOrCreateInstance(btn).show();
  }
  document.addEventListener('DOMContentLoaded', function () {
    var hash = (window.location.hash || '').slice(1);
    if (hash !== 'kursantet' && hash !== 'dokumentet') return;
    /* Skeda nga adresa hapet menjëherë, pa kalim, që faqja të mos dalë bosh për një çast. */
    var panes = document.querySelectorAll('.tab-pane.fade');
    Array.prototype.forEach.call(panes, function (p) { p.classList.remove('fade'); });
    showTab(hash);
    Array.prototype.forEach.call(panes, function (p) { p.classList.add('fade'); });
  });
  document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (btn) {
    btn.addEventListener('shown.bs.tab', function () {
      var id = (btn.getAttribute('data-bs-target') || '').slice(1);
      try { history.replaceState(history.state, '', id === 'orari' ? window.location.pathname + window.location.search : '#' + id); } catch (e) { /* adresa mbetet */ }
    });
  });
  /* Lidhjet "#dita-…" hapin skedën e orarit nëse nuk është hapur. */
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest ? ev.target.closest('a[href^="#dita-"]') : null;
    if (!a) return;
    var pane = document.getElementById('orari');
    if (pane && !pane.classList.contains('active')) showTab('orari');
    var target = document.getElementById(a.getAttribute('href').slice(1));
    if (target) {
      ev.preventDefault();
      target.setAttribute('tabindex', '-1');
      target.scrollIntoView({ block: 'center' });
      target.focus({ preventScroll: true });
    }
  });

  var printBtn = document.querySelector('[data-lg-print]');
  if (printBtn) printBtn.addEventListener('click', function () {
    showTab('orari');
    setTimeout(function () { window.print(); }, 150);
  });

  /* ----------------------------------------------------- Dërgimi te serveri */
  function post(url, payload) {
    return window.qtaFetch.response(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf: CFG.csrf }, payload))
    }).then(function (r) {
      return r.json().catch(function () { return null; });
    }).then(function (json) {
      if (!json) throw new Error('Nuk mora përgjigje nga serveri. Kontrollo lidhjen dhe provo sërish.');
      return json;
    });
  }

  /**
   * Veprim me konfirmim nga serveri: nëse serveri thotë se duhet pranuar një
   * pasojë, pyetet përdoruesi dhe veprimi dërgohet sërish me force = 1.
   */
  function run(payload) {
    return post(CFG.endpoint, Object.assign({ group_id: CFG.group }, payload)).then(function (json) {
      if (json.ok) return json;
      if (json.confirm) {
        return window.qtaConfirm({
          title: json.confirm.title, message: json.confirm.message,
          confirm: json.confirm.confirm, danger: true
        }).then(function (ok) {
          if (!ok) { var c = new Error(''); c.cancelled = true; throw c; }
          return post(CFG.endpoint, Object.assign({ group_id: CFG.group }, payload, { force: 1 })).then(function (again) {
            if (!again.ok) throw new Error(again.error || 'Ndryshimi nuk u ruajt.');
            return again;
          });
        });
      }
      throw new Error(json.error || 'Ndryshimi nuk u ruajt.');
    });
  }

  function busy(btn, on) {
    if (!btn) return;
    btn.disabled = !!on;
    btn.classList.toggle('is-loading', !!on);
    btn.setAttribute('aria-busy', on ? 'true' : 'false');
  }
  function modal(el) { return el && window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(el) : null; }
  function formError(form, message) {
    var box = form.querySelector('[data-form-error]');
    if (!box) return;
    box.textContent = message || '';
    box.hidden = !message;
  }

  /* ----------------------------------------------------- Datat dhe ditët */
  var WEEKDAYS = ['e diel', 'e hënë', 'e martë', 'e mërkurë', 'e enjte', 'e premte', 'e shtunë'];
  function toIso(v) {
    var m = String(v || '').trim().match(/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})$/);
    if (m) return m[3] + '-' + ('0' + m[2]).slice(-2) + '-' + ('0' + m[1]).slice(-2);
    return /^\d{4}-\d{2}-\d{2}$/.test(String(v || '')) ? String(v) : '';
  }
  function toDmy(iso) {
    var m = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return m ? m[3] + '.' + m[2] + '.' + m[1] : '';
  }
  function weekday(iso) {
    var m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return -1;
    var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
    return d.getUTCFullYear() === +m[1] && d.getUTCMonth() === +m[2] - 1 && d.getUTCDate() === +m[3] ? d.getUTCDay() : -1;
  }

  /* Ndikimi i parashikuar (dry run) në një kuti të dialogut. */
  function impactText(impact) {
    var n = impact.new, o = impact.old;
    var parts = [];
    if (impact.course_changed) parts.push('Kursi bëhet “' + impact.course_name + '”, me ' + n.total_hours + ' orë. Modulet dhe temat zëvendësohen.');
    if (impact.what === 'fixed_range_settings') {
      parts.push(n.start_date === o.start_date && n.end_date === o.end_date
        ? 'Periudha mbetet ' + toDmy(n.start_date) + ' – ' + toDmy(n.end_date) + '.'
        : 'Periudha bëhet ' + toDmy(n.start_date) + ' – ' + toDmy(n.end_date)
          + '; tani është ' + toDmy(o.start_date) + ' – ' + toDmy(o.end_date) + '.');
      parts.push(n.days + ' ditë mësimi dhe ' + n.fixed.off_days + ' ditë pa mësim.');
      if (impact.changed_dates && impact.changed_dates.length) {
        parts.push('Rindërtohen ' + impact.changed_dates.length + ' data; e para është ' + toDmy(impact.first_changed) + '.');
      }
      return parts.join(' ');
    }
    parts.push(n.end_date === o.end_date ? 'Mbarimi mbetet ' + n.end_on + '.' : 'Do të mbarojë ' + n.end_on + '; tani mbaron më ' + toDmy(o.end_date) + '.');
    parts.push(n.days + ' ditë mësimi' + (n.last_day_hours < CFG.daily ? ', dita e fundit me ' + n.last_day_hours + ' orë' : '') + '.');
    if (impact.changed_dates && impact.changed_dates.length) {
      parts.push('Ndryshon mësimi nga ' + toDmy(impact.first_changed) + ' e tutje.');
    } else {
      parts.push('Mësimi i asnjë dite nuk ndryshon.');
    }
    return parts.join(' ');
  }
  function setImpact(form, kind, message) {
    var box = form.querySelector('[data-lg-impact]');
    if (!box) return;
    box.classList.remove('is-ok', 'is-error', 'is-warning');
    if (kind) box.classList.add('is-' + kind);
    box.querySelector('[data-lg-impact-text]').textContent = message;
  }
  var previewSeq = 0;
  function previewChange(form, change) {
    var mine = ++previewSeq;
    setImpact(form, '', 'Po llogaris orarin…');
    post(CFG.endpoint, { action: 'change', group_id: CFG.group, revision: CFG.revision, dry_run: 1, change: change }).then(function (json) {
      if (mine !== previewSeq) return;
      if (!json.ok) { setImpact(form, 'error', json.error || 'Kontrollo të dhënat.'); return; }
      var imp = json.impact;
      var text = impactText(imp);
      if (imp.confirm) { setImpact(form, 'warning', text + ' ' + imp.confirm.message); return; }
      setImpact(form, 'ok', text);
    }).catch(function () { if (mine === previewSeq) setImpact(form, 'error', 'Parashikimi nuk u mor. Kontrollo lidhjen.'); });
  }
  function debounce(fn, ms) {
    var t = null;
    return function () { var args = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, args); }, ms); };
  }

  /* -------------------------------------------- Fillimi dhe orët në ditë */
  var settingsModal = document.getElementById('lgSettings');
  if (settingsModal) {
    var sForm = settingsModal.querySelector('form');
    var sChange = function () {
      return { type: 'settings', course_id: sForm.elements.course_id.value, start_date: sForm.elements.start_date.value, daily_hours: sForm.elements.daily_hours.value };
    };
    var sPreview = debounce(function () {
      if (String(sForm.elements.course_id.value) === String(CFG.course) && toIso(sForm.elements.start_date.value) === CFG.start && String(sForm.elements.daily_hours.value) === String(CFG.daily)) {
        ++previewSeq;
        setImpact(sForm, '', 'Ndrysho kursin, datën e fillimit ose orët në ditë: këtu del ndikimi në orar.');
        return;
      }
      if (!toIso(sForm.elements.start_date.value) || !sForm.elements.daily_hours.value) { setImpact(sForm, '', 'Plotëso datën (dd.mm.vvvv) dhe orët në ditë.'); return; }
      previewChange(sForm, sChange());
    }, 300);
    sForm.addEventListener('input', sPreview);
    settingsModal.addEventListener('shown.bs.modal', function () { sForm.elements.course_id.focus(); });
    document.addEventListener('click', function (ev) {
      if (ev.target.closest && ev.target.closest('[data-lg-settings]')) {
        sForm.reset();
        formError(sForm, '');
        ++previewSeq;
        setImpact(sForm, '', 'Ndrysho kursin, datën e fillimit ose orët në ditë: këtu del ndikimi në orar.');
        modal(settingsModal).show(ev.target.closest('[data-lg-settings]'));
      }
    });
    sForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = sForm.querySelector('button[type="submit"]');
      formError(sForm, '');
      if (btn && btn.disabled) return;
      busy(btn, true);
      run({ action: 'change', revision: CFG.revision, change: sChange() }).then(function (json) {
        reloadWith(json.message);
      }).catch(function (err) {
        busy(btn, false);
        if (!err.cancelled) formError(sForm, err.message);
      }).finally(function () { busy(btn, false); });
    });
  }

  /* ------------------------------------------ Periudha e përcaktuar */
  var fixedSettingsModal = document.getElementById('lgFixedSettings');
  if (fixedSettingsModal) {
    var fsForm = fixedSettingsModal.querySelector('form');
    var fsChange = function () {
      return { type: 'fixed_range_settings', course_id: fsForm.elements.course_id.value, start_date: fsForm.elements.start_date.value, end_date: fsForm.elements.end_date.value };
    };
    var fsPreview = debounce(function () {
      var start = toIso(fsForm.elements.start_date.value);
      var end = toIso(fsForm.elements.end_date.value);
      if (String(fsForm.elements.course_id.value) === String(CFG.course) && start === CFG.start && end === CFG.end) {
        ++previewSeq;
        setImpact(fsForm, '', 'Ndrysho kursin, fillimin ose mbarimin për të parë si rindërtohet orari.');
        return;
      }
      if (!start || !end) {
        setImpact(fsForm, '', 'Plotëso të dyja datat si dd.mm.vvvv.');
        return;
      }
      previewChange(fsForm, fsChange());
    }, 300);
    fsForm.addEventListener('input', fsPreview);
    fixedSettingsModal.addEventListener('shown.bs.modal', function () { fsForm.elements.course_id.focus(); });
    document.addEventListener('click', function (ev) {
      var opener = ev.target.closest ? ev.target.closest('[data-lg-fixed-settings]') : null;
      if (!opener) return;
      fsForm.reset();
      formError(fsForm, '');
      ++previewSeq;
      setImpact(fsForm, '', 'Ndrysho kursin, fillimin ose mbarimin për të parë si rindërtohet orari.');
      modal(fixedSettingsModal).show(opener);
    });
    fsForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (!toIso(fsForm.elements.start_date.value) || !toIso(fsForm.elements.end_date.value)) {
        formError(fsForm, 'Plotëso të dyja datat si dd.mm.vvvv.');
        return;
      }
      var btn = fsForm.querySelector('button[type="submit"]');
      formError(fsForm, '');
      if (btn && btn.disabled) return;
      busy(btn, true);
      run({ action: 'change', revision: CFG.revision, change: fsChange() }).then(function (json) {
        reloadWith(json.message);
      }).catch(function (err) {
        busy(btn, false);
        if (!err.cancelled) formError(fsForm, err.message);
      }).finally(function () { busy(btn, false); });
    });
  }

  /* ------------------------------------------------------ Një ditë e veçantë */
  var dayModal = document.getElementById('lgDay');
  if (dayModal) {
    var dForm = dayModal.querySelector('form');
    var sundayOnly = dForm.querySelector('[data-lg-sunday-only]');
    var weekdayOnly = dForm.querySelector('[data-lg-weekday-only]');
    var normalLabel = dForm.querySelector('[data-lg-normal-label]');
    var weekdayHelp = dForm.querySelector('[data-lg-weekday]');
    var hoursInput = dForm.elements.hours;

    var paintWeekday = function () {
      var iso = toIso(dForm.elements.date.value);
      var wd = iso ? weekday(iso) : -1;
      var sunday = wd === 0;
      sundayOnly.hidden = !sunday;
      weekdayOnly.hidden = sunday;
      normalLabel.textContent = sunday ? 'Pa mësim, si çdo të diel' : 'Orari i zakonshëm: ' + CFG.daily + ' orë';
      weekdayHelp.textContent = wd < 0 ? 'Shkruaje si dd.mm.vvvv, p.sh. 11.10.2026.' : 'Kjo është ' + WEEKDAYS[wd] + '.' + (sunday ? ' Të dielën nuk ka mësim, përveç kur e zgjedh këtu.' : '');
      var checked = dForm.querySelector('input[name="mode"]:checked');
      if (checked && checked.closest('[hidden]')) dForm.elements.mode.value = 'normal';
      return { iso: iso, sunday: sunday };
    };
    var dChange = function () {
      var info = paintWeekday();
      var mode = (dForm.querySelector('input[name="mode"]:checked') || {}).value || 'normal';
      var serverMode = mode === 'normal' ? (info.sunday ? 'off' : 'default') : mode;
      return { type: 'rule', date: dForm.elements.date.value, mode: serverMode, hours: hoursInput.value, note: dForm.elements.note.value };
    };
    var dPreview = debounce(function () {
      var c = dChange();
      if (!toIso(c.date)) { setImpact(dForm, '', 'Shkruaj datën si dd.mm.vvvv.'); return; }
      if (c.mode === 'hours' && !/^\d+$/.test(String(c.hours).trim())) { setImpact(dForm, '', 'Shkruaj sa orë mësim ka kjo ditë.'); return; }
      previewChange(dForm, c);
    }, 300);
    dForm.addEventListener('input', function (ev) {
      if (ev.target === hoursInput && hoursInput.value !== '') dForm.elements.mode.value = 'hours';
      dPreview();
    });
    dForm.addEventListener('change', dPreview);

    document.addEventListener('click', function (ev) {
      var opener = ev.target.closest ? ev.target.closest('[data-lg-day]') : null;
      if (!opener) return;
      var iso = opener.getAttribute('data-lg-day');
      dForm.reset();
      formError(dForm, '');
      dForm.elements.date.value = iso ? toDmy(iso) : '';
      dForm.elements.date.readOnly = !!iso;
      var rule = iso && CFG.rules ? CFG.rules[iso] : undefined;
      var wd = iso ? weekday(iso) : -1;
      if (rule) {
        if (rule.hours === null) dForm.elements.mode.value = 'default';
        else if (rule.hours === 0) dForm.elements.mode.value = wd === 0 ? 'normal' : 'off';
        else { dForm.elements.mode.value = 'hours'; hoursInput.value = rule.hours; }
        dForm.elements.note.value = rule.note || '';
      } else if (wd === 0) {
        dForm.elements.mode.value = 'default';
      }
      dayModal.querySelector('[data-lg-day-title]').textContent = iso ? 'Dita: ' + WEEKDAYS[wd] + ', ' + toDmy(iso) : 'Një ditë e veçantë';
      paintWeekday();
      setImpact(dForm, '', 'Zgjidh si do të jetë dita: këtu del ndikimi në orar.');
      if (iso && (rule || wd === 0)) dPreview();
      modal(dayModal).show(opener);
    });
    dayModal.addEventListener('shown.bs.modal', function () {
      (dForm.elements.date.readOnly ? dForm.querySelector('input[name="mode"]:checked') : dForm.elements.date).focus();
    });
    dForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var c = dChange();
      if (!toIso(c.date)) { formError(dForm, 'Shkruaj datën si dd.mm.vvvv, p.sh. 11.10.2026.'); dForm.elements.date.focus(); return; }
      if (c.mode === 'hours' && !/^\d+$/.test(String(c.hours).trim())) { formError(dForm, 'Shkruaj sa orë mësim ka kjo ditë: një numër nga 1 deri në ' + hoursInput.max + '.'); hoursInput.focus(); return; }
      var btn = dForm.querySelector('button[type="submit"]');
      formError(dForm, '');
      if (btn && btn.disabled) return;
      busy(btn, true);
      run({ action: 'change', revision: CFG.revision, change: c }).then(function (json) {
        reloadWith(json.message);
      }).catch(function (err) {
        busy(btn, false);
        if (!err.cancelled) formError(dForm, err.message);
      }).finally(function () { busy(btn, false); });
    });
  }

  document.addEventListener('click', function (ev) {
    var rm = ev.target.closest ? ev.target.closest('[data-lg-rule-remove]') : null;
    if (!rm) return;
    var date = rm.getAttribute('data-lg-rule-remove');
    window.qtaConfirm({
      title: 'Të hiqet dita e veçantë?',
      message: 'Dita ' + (rm.getAttribute('data-label') || toDmy(date)) + ' kthehet në orarin e zakonshëm dhe orari rillogaritet.',
      confirm: 'Po, hiqe', danger: false
    }).then(function (ok) {
      if (!ok) return;
      if (rm && rm.disabled) return;
      busy(rm, true);
      run({ action: 'change', revision: CFG.revision, change: { type: 'rule', date: date, mode: 'remove' } })
        .then(function (json) { reloadWith(json.message); })
        .catch(function (err) { busy(rm, false); if (!err.cancelled) toast(err.message, 'danger', { autohide: false }); }).finally(function () { busy(rm, false); });
    });
  });

  /* ------------------------------------------------ Temat e reja të kursit */
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-lg-refresh]') : null;
    if (!btn) return;
    window.qtaConfirm({
      title: 'Të merren temat e reja të kursit?',
      message: 'Orari i grupit ndërtohet sërish me modulet dhe temat e kursit siç janë tani. Data e mbarimit mund të ndryshojë. Grupi nuk ka nisur, prandaj asnjë ditë e zhvilluar nuk preket.',
      confirm: 'Po, merri', danger: false
    }).then(function (ok) {
      if (!ok) return;
      if (btn && btn.disabled) return;
      busy(btn, true);
      run({ action: 'change', revision: CFG.revision, change: { type: 'refresh' } })
        .then(function (json) { reloadWith(json.message); })
        .catch(function (err) { busy(btn, false); if (!err.cancelled) toast(err.message, 'danger', { autohide: false }); }).finally(function () { busy(btn, false); });
    });
  });

  /* ------------------------------------------------------------ Kursantët */
  var membersModal = document.getElementById('lgMembers');
  if (membersModal) {
    var mForm = membersModal.querySelector('form');
    membersModal.addEventListener('shown.bs.modal', function () { mForm.elements.amze_spec.focus(); });
    mForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = mForm.querySelector('button[type="submit"]');
      formError(mForm, '');
      if (btn && btn.disabled) return;
      busy(btn, true);
      run({ action: 'members', amze_spec: mForm.elements.amze_spec.value }).then(function (json) {
        try { history.replaceState(history.state, '', '#kursantet'); } catch (e) { /* */ }
        reloadWith(json.message);
      }).catch(function (err) {
        busy(btn, false);
        if (!err.cancelled) formError(mForm, err.message);
      }).finally(function () { busy(btn, false); });
    });
  }

  /* ------------------------------------------------------------- Fshirja */
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-lg-delete]') : null;
    if (!btn) return;
    if (btn && btn.disabled) return;
      busy(btn, true);
    run({ action: 'delete' }).then(function (json) {
      window.location.href = json.redirect || 'lesson_groups.php';
    }).catch(function (err) {
      busy(btn, false);
      if (!err.cancelled) toast(err.message, 'danger', { autohide: false });
    }).finally(function () { busy(btn, false); });
  });

  /* ------------------------------------------------ Mbyllja e grupit */
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-lg-complete]') : null;
    if (!btn) return;
    var want = btn.getAttribute('data-lg-complete') === '1' ? 1 : 0;
    window.qtaConfirm(want
      ? { title: 'Të mbyllet grupi?', message: 'Mbylle kur provimet dhe pikët janë të plota. Pas mbylljes, çdo ndryshim do të kërkojë konfirmim.', confirm: 'Po, mbylle', danger: false }
      : { title: 'Të rihapet grupi?', message: 'Ky grup është i mbyllur dhe dokumentet mund të jenë lëshuar. E rihap vetëm për të korrigjuar një gabim.', confirm: 'Po, rihape', danger: true }
    ).then(function (ok) {
      if (!ok) return;
      if (btn && btn.disabled) return;
      busy(btn, true);
      post(CFG.cellEndpoint, { action: 'set_group_completed', group_id: CFG.group, is_completed: want }).then(function (json) {
        if (!json.ok) throw new Error(json.error || 'Gjendja e grupit nuk u ndryshua.');
        reloadWith(want ? 'Grupi u mbyll.' : 'Grupi u rihap.');
      }).catch(function (err) { busy(btn, false); toast(err.message, 'danger', { autohide: false }); }).finally(function () { busy(btn, false); });
    });
  });

  /* ---------------------------------- Data e provimit dhe pikët në tabelë */
  var TODAY = CFG.today;
  function statusHtml(label, variant, icon) {
    return '<span class="status status-' + variant + '"><i class="bi ' + icon + '" aria-hidden="true"></i>' + esc(label) + '</span>';
  }
  function whenLabel(iso) {
    var n = Math.round((Date.parse(iso) - Date.parse(TODAY)) / 86400000);
    if (isNaN(n)) return '';
    if (n === 0) return 'sot';
    if (n === 1) return 'nesër';
    if (n === -1) return 'dje';
    return n > 1 ? 'pas ' + n + ' ditësh' : Math.abs(n) + ' ditë më parë';
  }
  /* Rezultati zyrtar i rreshtit (bosh kur mungon), nga qeliza "Pikët". */
  function finalOf(row) {
    var fc = row && row.querySelector('[data-final-cell]');
    return fc ? (fc.getAttribute('data-final') || '') : '';
  }
  function refreshStatus(row) {
    var cell = row && row.querySelector('[data-status]');
    if (!cell) return;
    var score = finalOf(row);
    var exam = toIso((row.querySelector('td[data-field="exam_date"] .editable') || {}).textContent || '');
    var html;
    if (score) html = statusHtml('Përfunduar', 'neutral', 'bi-check2');
    else if (exam) html = exam >= TODAY ? statusHtml('Provimi ' + whenLabel(exam), 'info', 'bi-calendar-event') : statusHtml('Pret pikët', 'warning', 'bi-hourglass-split');
    else if (CFG.start > TODAY) html = statusHtml('Nis ' + whenLabel(CFG.start), 'info', 'bi-calendar-event');
    else if (TODAY <= CFG.end) html = statusHtml('Në mësim', 'accent', 'bi-easel');
    else html = statusHtml('Pret datën e provimit', 'warning', 'bi-clock-fill');
    cell.innerHTML = html;
  }
  function refreshScored() {
    var rows = document.querySelectorAll('#lgMembersTable tr[data-student-row]');
    var scored = 0;
    rows.forEach(function (r) { if (finalOf(r)) scored++; });
    var label = document.querySelector('[data-scored-label]');
    if (label) label.textContent = scored === 0 ? 'ende pa pikë' : (scored === rows.length ? 'të gjithë me pikë' : scored + ' me pikë');
  }
  /* Pas ruajtjes së pikëve sipas moduleve: gjendja e rreshtave dhe numëruesi. */
  document.addEventListener('qta:results-saved', function (ev) {
    if (!ev.detail || ev.detail.group !== CFG.group) return;
    document.querySelectorAll('#lgMembersTable tr[data-student-row]').forEach(refreshStatus);
    refreshScored();
  });
  function flashCell(cell, cls) {
    cell.classList.remove('cell-ok', 'cell-err');
    cell.classList.add(cls);
    setTimeout(function () { cell.classList.remove(cls); }, 900);
  }

  function saveCell(ed) {
    var cell = ed.closest('td.cell');
    var field = cell.getAttribute('data-field');
    var prev = ed.dataset.prev != null ? ed.dataset.prev : ed.textContent.trim();
    var raw = ed.textContent.replace(/\s+/g, ' ').trim();
    ed.textContent = raw;
    var value = raw;
    if (raw === '' || raw === '—') {
      value = '';
    } else if (field === 'exam_date') {
      value = toIso(raw);
      if (!value) { ed.textContent = prev || '—'; flashCell(cell, 'cell-err'); toast('Shkruaje datën si dd.mm.vvvv, p.sh. 25.10.2026.', 'danger'); return; }
      if (value < CFG.end) { ed.textContent = prev || '—'; flashCell(cell, 'cell-err'); toast('Provimi nuk mund të jetë para mbarimit të grupit (' + toDmy(CFG.end) + ').', 'danger'); return; }
    }
    var display = value === '' ? '—' : (field === 'exam_date' ? toDmy(value) : String(value));
    if (display === (prev || '—')) { ed.textContent = prev || '—'; return; }

    var ask = CFG.closed
      ? window.qtaConfirm({ title: 'Ky grup është i mbyllur', message: 'Dokumentet e këtij grupi mund të jenë lëshuar tashmë. Je i sigurt që do ta ndryshosh?', confirm: 'Po, ndryshoje', danger: false })
      : Promise.resolve(true);
    ask.then(function (ok) {
      if (!ok) { ed.textContent = prev || '—'; return; }
      if (cell.classList.contains('cell-saving')) return;
      cell.setAttribute('aria-busy', 'true');
      cell.classList.add('cell-saving');
      post(CFG.cellEndpoint, {
        action: 'update_cell', group_id: CFG.group, student_id: parseInt(cell.getAttribute('data-student'), 10),
        field: field, value: value, force: CFG.closed ? 1 : 0
      }).then(function (json) {
        cell.classList.remove('cell-saving');
        if (!json.ok) throw new Error(json.error || 'Ndryshimi nuk u ruajt.');
        ed.textContent = json.display || display;
        ed.dataset.prev = ed.textContent;
        flashCell(cell, 'cell-ok');
        refreshStatus(ed.closest('tr'));
        toast('Ndryshimi u ruajt.');
      }).catch(function (err) {
        cell.classList.remove('cell-saving');
        ed.textContent = prev || '—';
        flashCell(cell, 'cell-err');
        toast(err.message || 'Ndryshimi nuk u ruajt.', 'danger');
      }).finally(function () { cell.classList.remove('cell-saving'); cell.removeAttribute('aria-busy'); });
    });
  }

  /* ----------------------------- Plani i ditëve (periudhë e përcaktuar) */
  /* Datat historike nuk lëvizin; korrigjohen vetëm orët brenda periudhës. Shiriti
     del vetëm kur ka ndryshime të paruajtura; ruajtja kalon nga e njëjta rrugë
     (run) si çdo ndryshim orari, me konfirmim për ditët e kaluara dhe grupin e mbyllur. */
  var fxRoot = document.getElementById('lgPlan');
  if (fxRoot && CFG.mode === 'fixed_range' && CFG.edit && window.QtaDayPlan) {
    var fx = window.QtaDayPlan.mount(fxRoot);
    var fxOriginal = fx.plan();
    var fxOriginalNotes = {};
    fx.serialize().forEach(function (it) { fxOriginalNotes[it.d] = it.n || ''; });
    var fxBar = document.querySelector('[data-fx-bar]');
    var fxText = document.querySelector('[data-fx-text]');
    var fxIcon = document.querySelector('[data-fx-icon]');
    var fxSave = document.querySelector('[data-fx-save]');
    var fxCancel = document.querySelector('[data-fx-cancel]');
    var fxUndo = document.querySelector('[data-fx-undo]');
    var fxRebalance = document.querySelector('[data-fx-rebalance]');
    var fxDirty = false;

    var fxChanges = function () {
      var now = fx.plan();
      var notes = {};
      fx.serialize().forEach(function (it) { notes[it.d] = it.n || ''; });
      var out = { days: {}, notes: {}, count: 0 };
      Object.keys(now).forEach(function (d) {
        var hours = now[d] !== fxOriginal[d];
        var note = notes[d] !== fxOriginalNotes[d];
        if (hours) out.days[d] = now[d];
        if (note) out.notes[d] = notes[d];
        if (hours || note) out.count++;
      });
      return out;
    };
    var fxPaint = function () {
      var p = fx.plan();
      var total = 0;
      Object.keys(p).forEach(function (d) { total += p[d]; });
      var ch = fxChanges();
      var H = CFG.hours;
      var issue = p[CFG.start] < 1 ? 'Fillimi historik duhet të ketë mësim'
        : p[CFG.end] < 1 ? 'Mbarimi historik duhet të ketë mësim'
        : total < H ? (H - total === 1 ? 'Mungon 1 orë' : 'Mungojnë ' + (H - total) + ' orë')
        : total > H ? (total - H === 1 ? 'Është vendosur 1 orë më shumë' : 'Janë vendosur ' + (total - H) + ' orë më shumë')
        : '';
      fxDirty = ch.count > 0;
      fxBar.hidden = !fxDirty;
      fxText.innerHTML = '<b></b> · <span></span>';
      fxText.querySelector('b').textContent = total + ' / ' + H + ' orë';
      fxText.querySelector('span').textContent = issue || (ch.count === 1 ? '1 ditë ndryshoi' : ch.count + ' ditë ndryshuan');
      fxIcon.className = 'bi ' + (issue ? 'bi-x-circle-fill is-err' : 'bi-check-circle-fill is-ok');
      fxSave.disabled = !!issue;
      if (fxUndo) fxUndo.disabled = !fx.canUndo();
      if (fxRebalance) fxRebalance.disabled = total === H;
    };
    fxRoot.addEventListener('dplan:change', fxPaint);
    window.addEventListener('beforeunload', function (e) {
      if (!fxDirty) return;
      e.preventDefault();
      e.returnValue = '';
    });
    fxCancel.addEventListener('click', function () {
      fxDirty = false;
      window.location.reload();
    });
    if (fxUndo) fxUndo.addEventListener('click', function () { fx.undo(); fxPaint(); });
    if (fxRebalance) fxRebalance.addEventListener('click', function () {
      if (fxRebalance && fxRebalance.disabled) return;
      busy(fxRebalance, true);
      post(CFG.endpoint, { action: 'rebalance', group_id: CFG.group, plan: fx.serialize() }).then(function (json) {
        busy(fxRebalance, false);
        if (!json.ok) throw new Error(json.error || 'Orët nuk u rishpërndanë.');
        var current = fx.plan();
        var changes = {};
        json.plan.forEach(function (it) { if (current[it.d] !== it.h) changes[it.d] = { h: it.h }; });
        fx.apply(changes, { origin: 'auto', source: 'rebalance' });
        toast(json.message, (json.sundays_added && json.sundays_added.length) ? 'warning' : 'success');
        fxPaint();
      }).catch(function (err) { busy(fxRebalance, false); toast(err.message, 'danger', { autohide: false }); }).finally(function () { busy(fxRebalance, false); });
    });
    fxSave.addEventListener('click', function () {
      var ch = fxChanges();
      if (fxSave && fxSave.disabled) return;
      busy(fxSave, true);
      run({ action: 'change', revision: CFG.revision, change: { type: 'fixed_days', days: ch.days, notes: ch.notes } }).then(function (json) {
        fxDirty = false;
        reloadWith(json.message);
      }).catch(function (err) {
        busy(fxSave, false);
        if (!err.cancelled) toast(err.message, 'danger', { autohide: false });
      }).finally(function () { busy(fxSave, false); });
    });
    fxPaint();
  }

  if (CFG.edit) {
    document.querySelectorAll('#lgMembersTable td.cell .editable[contenteditable="true"]').forEach(function (ed) {
      ed.addEventListener('focus', function () { ed.dataset.prev = ed.textContent.trim(); });
      ed.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); ed.blur(); }
        if (e.key === 'Escape') { e.preventDefault(); ed.textContent = ed.dataset.prev || ed.textContent; ed.blur(); }
      });
      ed.addEventListener('paste', function (e) {
        e.preventDefault();
        var text = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
        document.execCommand('insertText', false, text.replace(/\s+/g, ' ').trim());
      });
      ed.addEventListener('blur', function () { saveCell(ed); });
    });
  }
})();
