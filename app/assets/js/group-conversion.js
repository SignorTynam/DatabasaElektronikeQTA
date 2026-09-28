/* ============================================================================
   group-conversion.js — Konvertimi i një grupi të regjistrit të vjetër
   (group_conversion.php).

   Kalendari (day-plan.js) është vendi ku shqyrtohet propozimi. Këtu:
     - përmbledhja e gjallë: orët e vendosura, ditët, kontrollet dhe vendimi
       ("Orari është gati për konvertim" / "Mungojnë 7 orë");
     - "Rishpërndaj automatikisht", "Rikthe propozimin", "Zhbëj";
     - "Ruaj draftin" dhe "Konverto grupin" (pas konfirmimit; ruan së pari
       ndryshimet e paruajtura);
     - një skedë e vjetër ose të dhëna që ndryshuan ndërkohë: njoftim me
       "Rifresko të dhënat", pa humbur asgjë në heshtje.
   Serveri (group_conversion_update.php) rikontrollon gjithçka; këtu është
   vetëm ndihma e çastit.
   ========================================================================= */
(function () {
  'use strict';

  var cfgEl = document.getElementById('cvConfig');
  if (!cfgEl) return;
  var CFG = JSON.parse(cfgEl.textContent || '{}');
  var FLASH_KEY = 'qtaFlash';

  function $(sel) { return document.querySelector(sel); }
  function toast(message, variant, opts) { if (window.qtaToast) window.qtaToast(message, variant || 'success', null, opts || {}); }
  function dmy(iso) { var p = String(iso).split('-'); return p[2] + '.' + p[1] + '.' + p[0]; }
  function hoursPhrase(n, one, many) { return (n === 1 ? one : many).replace('%d', n); }

  document.addEventListener('DOMContentLoaded', function () {
    var queued = null;
    try { queued = sessionStorage.getItem(FLASH_KEY); sessionStorage.removeItem(FLASH_KEY); } catch (e) { queued = null; }
    if (queued) toast(queued, 'success', { delay: 7000 });
    if (CFG.flash) toast(CFG.flash, 'success', { delay: 7000 });
  });

  var planEl = document.getElementById('cvPlan');
  if (!planEl || !window.QtaDayPlan) return;
  var plan = window.QtaDayPlan.mount(planEl);
  var H = CFG.hours;
  var revision = CFG.revision || 0;
  var dirty = false;
  var working = false;
  var stale = !!CFG.stale;

  var el = {
    planned: $('[data-cv-planned]'),
    meter: $('[data-cv-meter]'),
    teach: $('[data-cv-teach]'),
    off: $('[data-cv-off]'),
    verdict: $('[data-cv-verdict]'),
    barText: $('[data-cv-bar-text]'),
    barIcon: $('[data-cv-bar-icon]'),
    saved: $('[data-cv-saved]'),
    save: $('[data-cv-save]'),
    convert: $('[data-cv-convert]'),
    undo: $('[data-cv-undo]'),
    rebalance: $('[data-cv-rebalance]'),
    reset: $('[data-cv-reset]'),
    staleBox: $('[data-cv-stale]'),
    staleText: $('[data-cv-stale-text]')
  };

  /* ------------------------------------------------ Përmbledhja e planit */
  function summarize() {
    var p = plan.plan();
    var s = { total: 0, teach: 0, off: 0, sundays: [], issues: [] };
    plan.dates.forEach(function (d) {
      var h = p[d];
      s.total += h;
      if (h > 0) s.teach++; else s.off++;
      if (h > 0 && d !== CFG.start && d !== CFG.end && window.QtaDayPlan.weekday(d) === 0) s.sundays.push(d);
    });
    var range = dmy(CFG.start) + '–' + dmy(CFG.end);
    if (p[CFG.start] < 1) s.issues.push({ code: 'start', text: 'Fillimi historik (' + dmy(CFG.start) + ') duhet të ketë mësim.' });
    if (p[CFG.end] < 1) s.issues.push({ code: 'end', text: 'Mbarimi historik (' + dmy(CFG.end) + ') duhet të ketë mësim.' });
    if (s.total < H) {
      s.issues.push({ code: 'hours', short: hoursPhrase(H - s.total, 'Mungon %d orë', 'Mungojnë %d orë'),
        text: hoursPhrase(H - s.total, 'Mungon %d orë mësimi', 'Mungojnë %d orë mësimi') + '. Shpërndaji brenda periudhës ' + range + ' para se ta konvertosh grupin.' });
    } else if (s.total > H) {
      s.issues.push({ code: 'hours', short: hoursPhrase(s.total - H, 'Është vendosur %d orë më shumë', 'Janë vendosur %d orë më shumë'),
        text: hoursPhrase(s.total - H, 'Është vendosur %d orë më shumë', 'Janë vendosur %d orë më shumë') + ' se orët e kursit. Hiqi nga ndonjë ditë brenda periudhës ' + range + '.' });
    }
    s.ok = s.issues.length === 0;
    return s;
  }

  function setCheck(key, kind, text) {
    var li = document.querySelector('[data-check="' + key + '"]');
    if (!li) return;
    var icons = { ok: 'bi-check-circle-fill', err: 'bi-x-circle-fill', warn: 'bi-exclamation-triangle-fill' };
    li.className = 'cv-check is-' + kind;
    li.querySelector('.bi').className = 'bi ' + icons[kind];
    li.querySelector('[data-check-text]').textContent = text;
  }

  function paint() {
    var s = summarize();
    if (el.planned && el.planned.textContent !== String(s.total)) {
      el.planned.textContent = String(s.total);
      el.planned.classList.remove('is-bump');
      void el.planned.offsetWidth;
      el.planned.classList.add('is-bump');
    }
    if (el.meter) {
      el.meter.value = Math.min(s.total, H);
      el.meter.classList.toggle('is-full', s.total === H);
      el.meter.classList.toggle('is-over', s.total > H);
    }
    if (el.teach) el.teach.textContent = String(s.teach);
    if (el.off) el.off.textContent = String(s.off);

    var hoursIssue = s.issues.filter(function (i) { return i.code === 'hours'; })[0];
    setCheck('hours', hoursIssue ? 'err' : 'ok', hoursIssue ? hoursIssue.short : s.total + ' nga ' + H + ' orë');
    setCheck('start', s.issues.some(function (i) { return i.code === 'start'; }) ? 'err' : 'ok',
      'Fillimi ' + dmy(CFG.start) + (s.issues.some(function (i) { return i.code === 'start'; }) ? ' pa mësim' : ' ka mësim'));
    setCheck('end', s.issues.some(function (i) { return i.code === 'end'; }) ? 'err' : 'ok',
      'Mbarimi ' + dmy(CFG.end) + (s.issues.some(function (i) { return i.code === 'end'; }) ? ' pa mësim' : ' ka mësim'));
    setCheck('sundays', s.sundays.length ? 'warn' : 'ok', s.sundays.length
      ? 'Përdor ' + (s.sundays.length === 1 ? '1 të diel' : s.sundays.length + ' të diela') + ' (' + s.sundays.map(dmy).join(', ') + ')'
      : 'Pa mësim të dielave');

    var kind, text, icon;
    if (!s.ok) {
      kind = 'err'; icon = 'bi-x-circle-fill'; text = s.issues[0].short || s.issues[0].text;
    } else if (stale) {
      kind = 'warn'; icon = 'bi-arrow-repeat'; text = 'Rifresko të dhënat para se të vazhdosh';
    } else if (CFG.blocked) {
      kind = 'warn'; icon = 'bi-exclamation-triangle-fill'; text = 'Orari është i vlefshëm — rregullo problemet e grupit';
    } else if (s.sundays.length) {
      kind = 'warn'; icon = 'bi-exclamation-triangle-fill'; text = 'Orari është i vlefshëm — kontrollo të dielat';
    } else {
      kind = 'ok'; icon = 'bi-check-circle-fill'; text = 'Orari është gati për konvertim';
    }
    if (el.verdict) {
      var fullText = s.ok ? text : s.issues[0].text;
      if (el.verdict.getAttribute('data-text') !== fullText) {
        el.verdict.className = 'cv-verdict is-' + kind;
        el.verdict.innerHTML = '<i class="bi ' + icon + '" aria-hidden="true"></i><span></span>';
        el.verdict.querySelector('span').textContent = fullText;
        el.verdict.setAttribute('data-text', fullText);
        void el.verdict.offsetWidth;
        el.verdict.classList.add('is-changed');
      }
    }
    if (el.barText) {
      el.barText.innerHTML = '<b></b> · <span></span>';
      el.barText.querySelector('b').textContent = s.total + ' / ' + H + ' orë';
      el.barText.querySelector('span').textContent = s.ok
        ? (stale ? 'Rifresko të dhënat' : (CFG.blocked ? 'Orari i vlefshëm, grupi ka probleme' : (s.sundays.length ? 'I vlefshëm, me të diela' : 'Orari i vlefshëm')))
        : (s.issues[0].short || 'Kontrollo planin');
      el.barText.className = 'cv-bar-text is-' + kind;
    }
    if (el.barIcon) el.barIcon.className = 'bi ' + icon + ' is-' + kind;

    if (el.undo) el.undo.disabled = working || !plan.canUndo();
    if (el.rebalance) el.rebalance.disabled = working || s.total === H;
    if (el.reset) el.reset.disabled = working;
    if (el.save) el.save.disabled = working || stale || (!dirty && revision > 0);
    if (el.convert) {
      var blockedWhy = !CFG.convertible ? CFG.blockedReason : (stale ? 'Rifresko të dhënat para se ta konvertosh grupin.' : (!s.ok ? s.issues[0].text : ''));
      el.convert.disabled = working || !!blockedWhy;
      el.convert.setAttribute('aria-describedby', 'cvConvertWhy');
      var why = document.getElementById('cvConvertWhy');
      if (why) why.textContent = blockedWhy || 'Konverto grupin me këtë orar.';
    }
    paintSaved();
  }

  function paintSaved() {
    if (!el.saved) return;
    var text, cls;
    if (dirty) { text = 'Ndryshime të paruajtura'; cls = 'is-dirty'; }
    else if (revision > 0) { text = CFG.savedLabel || 'Drafti është i ruajtur'; cls = 'is-saved'; }
    else { text = 'Propozim automatik, ende pa ruajtur'; cls = ''; }
    el.saved.textContent = text;
    el.saved.className = 'cv-saved ' + cls;
  }

  planEl.addEventListener('dplan:change', function () {
    dirty = true;
    paint();
  });
  window.addEventListener('beforeunload', function (e) {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = '';
  });

  /* ----------------------------------------------------- Dërgimi te serveri */
  function post(payload) {
    return fetch(CFG.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf: CFG.csrf, group_id: CFG.group }, payload))
    }).then(function (r) {
      return r.json().catch(function () { return null; });
    }).then(function (json) {
      if (!json) throw new Error('Nuk mora përgjigje nga serveri. Kontrollo lidhjen dhe provo sërish.');
      if (!json.ok) {
        var err = new Error(json.error || 'Veprimi nuk u krye.');
        err.code = json.code || null;
        throw err;
      }
      return json;
    });
  }

  function busy(btn, on) {
    working = !!on;
    if (btn) btn.classList.toggle('is-loading', !!on);
    paint();
  }

  function showStale(message) {
    stale = true;
    if (el.staleBox) {
      if (message && el.staleText) el.staleText.textContent = message;
      el.staleBox.hidden = false;
      el.staleBox.classList.add('is-flash');
    }
    paint();
  }

  function fail(err) {
    if (err && (err.code === 'draft_changed' || err.code === 'source_changed')) {
      showStale(err.message);
      toast(err.message, 'warning', { autohide: false });
      return;
    }
    if (err && err.code === 'converted') {
      window.location.href = 'lesson_group.php?id=' + CFG.group;
      return;
    }
    toast((err && err.message) || 'Veprimi nuk u krye.', 'danger', { autohide: false });
  }

  function save() {
    return post({ action: 'save', plan: plan.serialize(), revision: revision, source: CFG.source }).then(function (json) {
      revision = json.revision;
      dirty = false;
      CFG.savedLabel = 'Drafti u ruajt tani';
      return json;
    });
  }

  if (el.save) el.save.addEventListener('click', function () {
    busy(el.save, true);
    save().then(function (json) {
      toast(json.message);
    }).catch(fail).then(function () { busy(el.save, false); });
  });

  if (el.undo) el.undo.addEventListener('click', function () {
    plan.undo();
    paint();
  });

  if (el.rebalance) el.rebalance.addEventListener('click', function () {
    busy(el.rebalance, true);
    post({ action: 'rebalance', plan: plan.serialize() }).then(function (json) {
      var current = plan.plan();
      var changes = {};
      json.plan.forEach(function (it) { if (current[it.d] !== it.h) changes[it.d] = { h: it.h }; });
      plan.apply(changes, { origin: 'auto', source: 'rebalance' });
      var warn = (json.sundays_added && json.sundays_added.length) || (json.manual_changed && json.manual_changed.length);
      toast(json.message, warn ? 'warning' : 'success', warn ? { autohide: false } : {});
      plan.announce(json.message);
    }).catch(fail).then(function () { busy(el.rebalance, false); });
  });

  if (el.reset) el.reset.addEventListener('click', function () {
    window.qtaConfirm({
      title: 'Të rikthehet propozimi fillestar?',
      message: 'Çdo datë merr orët që propozoi sistemi. Ndryshimet e tua në këtë plan zëvendësohen — mund t\'i kthesh me "Zhbëj".',
      confirm: 'Po, rikthe propozimin', danger: false, icon: 'bi-arrow-counterclockwise'
    }).then(function (ok) {
      if (!ok) return;
      busy(el.reset, true);
      post({ action: 'propose' }).then(function (json) {
        var changes = {};
        json.plan.forEach(function (it) { changes[it.d] = { h: it.h }; });
        plan.apply(changes, { origin: 'auto', source: 'reset' });
        toast(json.message, 'success');
      }).catch(fail).then(function () { busy(el.reset, false); });
    });
  });

  var refreshBtn = $('[data-cv-refresh]');
  if (refreshBtn) refreshBtn.addEventListener('click', function () {
    busy(refreshBtn, true);
    post({ action: 'refresh', revision: revision }).then(function () {
      dirty = false;
      window.location.reload();
    }).catch(function (err) {
      busy(refreshBtn, false);
      toast(err.message || 'Të dhënat nuk u rifreskuan.', 'danger', { autohide: false });
    });
  });

  if (el.convert) el.convert.addEventListener('click', function () {
    var s = summarize();
    if (!s.ok) { toast(s.issues[0].text, 'danger'); return; }
    var points = [
      'Numri i grupit mbetet #' + CFG.group + '.',
      'Kursantët, datat e provimeve dhe pikët nuk ndryshojnë.',
      'Datat historike mbeten ' + dmy(CFG.start) + ' – ' + dmy(CFG.end) + '.',
      H + ' orë në ' + s.teach + ' ditë mësimi bëhen orari zyrtar i grupit, me modulet dhe temat e kursit siç janë sot.',
      'Grupi kalon te "Regjistri i kurseve profesionale" dhe nuk del më te regjistri i vjetër.'
    ];
    if (s.sundays.length) points.push('Plani përdor ' + (s.sundays.length === 1 ? '1 të diel' : s.sundays.length + ' të diela') + ': ' + s.sundays.map(dmy).join(', ') + '.');
    window.qtaConfirm({
      title: 'Të konvertohet Grupi #' + CFG.group + '?',
      message: CFG.course + ' kalon nga regjistri i vjetër te Regjistri i kurseve profesionale, me orarin që sheh këtu.',
      points: points,
      confirm: 'Po, konverto grupin', cancel: 'Jo, kthehu', danger: false, icon: 'bi-arrow-left-right'
    }).then(function (ok) {
      if (!ok) return;
      busy(el.convert, true);
      (dirty || revision === 0 ? save() : Promise.resolve()).then(function () {
        return post({ action: 'convert', revision: revision, source: CFG.source });
      }).then(function (json) {
        dirty = false;
        window.location.href = json.redirect;
      }).catch(function (err) {
        busy(el.convert, false);
        fail(err);
      });
    });
  });

  paint();
})();
