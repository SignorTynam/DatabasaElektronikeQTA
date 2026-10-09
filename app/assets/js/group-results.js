/* ============================================================================
   group-results.js — "Pikët sipas moduleve" (groups.php, lesson_group.php).

   Tabela kursant × modul: çdo qelizë mban pikët e një moduli (0–100, deri në dy
   shifra pas presjes); kolona e fundit është rezultati — mesatarja e moduleve,
   vetëm kur çdo modul ka pikë. Këtu llogaritja është vetëm parashikim: serveri
   (group_results.php → app/shared/results.php) e bën sërish, dhe pas ruajtjes
   tabela merr vlerat e tij.

   Ruajtja dërgon njëherësh vetëm qelizat e ndryshuara, secila me vlerën që pa
   faqja: nëse dikush tjetër e ka ndryshuar ndërkohë, asgjë nuk ruhet dhe vlera e
   re vendoset si bazë, që përdoruesi të vendosë.

   Tastiera: Enter / ↓ te kursanti tjetër (Enter në fund kalon te moduli tjetër),
   Shift+Enter / ↑ te i mëparshmi, Tab te qeliza tjetër, Esc kthen qelizën, Ctrl+S
   ruan. Ndryshimet e paruajtura nuk humbin pa pyetje.
   ========================================================================= */
(function () {
  'use strict';

  var modalEl = document.getElementById('resultsModal');
  if (!modalEl) return;
  var CFG = {};
  try { CFG = JSON.parse(modalEl.getAttribute('data-results') || '{}'); } catch (e) { CFG = {}; }

  var el = {
    eyebrow: modalEl.querySelector('[data-rs-eyebrow]'),
    meta: modalEl.querySelector('[data-rs-meta]'),
    notices: modalEl.querySelector('[data-rs-notices]'),
    body: modalEl.querySelector('[data-rs-body]'),
    status: modalEl.querySelector('[data-rs-status]'),
    save: modalEl.querySelector('[data-rs-save]'),
    revert: modalEl.querySelector('[data-rs-revert]'),
    unlock: modalEl.querySelector('[data-rs-unlock]')
  };

  var MAX = 10000;        // 100,00 pikë në qindëshe
  /* Në ekran me prekje udhëzimi i tastierës nuk shfaqet. */
  var TOUCH = !!(window.matchMedia && window.matchMedia('(hover: none) and (pointer: coarse)').matches);
  var S = null;           // fleta e hapur
  var seq = 0;            // leximi i fundit (përgjigjet e vona injorohen)
  var shown = false;
  var closing = false;    // mbyllja u pranua: pa pyetje të dytë
  var saving = false;
  var pendingFocus = null;
  var goTo = null;        // pas mbylljes: fokusi te një fushë e faqes (data e provimit)
  var enrollmentSuspended = false, enrollmentRefresh = false, enrollmentOpener = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
  function pad2(n) { return (n < 10 ? '0' : '') + n; }

  /* ------------------------------------------------------------ Pikët */
  /* Të njëjtat rregulla si qta_score_parse() në server. */
  function parseScore(raw) {
    var s = String(raw == null ? '' : raw).replace(/,/g, '.').trim();
    if (s === '') return { h: null };
    var m = s.match(/^0*(\d{1,3})(?:\.(\d{0,2}))?$/);
    if (m) {
      var h = parseInt(m[1], 10) * 100 + parseInt(((m[2] || '') + '00').slice(0, 2), 10);
      return h <= MAX ? { h: h } : { error: 'Pikët janë nga 0 deri në 100.' };
    }
    if (/^\d+\.\d{3,}$/.test(s)) return { error: 'Pikët kanë të shumtën dy shifra pas presjes, p.sh. 85,25.' };
    if (/^-?\d+(?:\.\d*)?$/.test(s)) return { error: 'Pikët janë nga 0 deri në 100.' };
    return { error: 'Shkruaj pikët me shifra, nga 0 deri në 100, p.sh. 85 ose 85,5.' };
  }
  /* "85.50" (baza) → 8550; lejon edhe vlera të vjetra jashtë 0–100. */
  function fromDb(v) {
    if (v == null || v === '') return null;
    var m = String(v).trim().match(/^(-?)(\d+)(?:\.(\d{1,2})\d*)?$/);
    if (!m) return null;
    var h = parseInt(m[2], 10) * 100 + parseInt(((m[3] || '') + '00').slice(0, 2), 10);
    return m[1] === '-' ? -h : h;
  }
  function toDb(h) { var a = Math.abs(h); return (h < 0 ? '-' : '') + Math.floor(a / 100) + '.' + pad2(a % 100); }
  /* 8550 → "85,5"; 8400 → "84"; 8325 → "83,25". */
  function label(h) {
    if (h == null) return '—';
    var a = Math.abs(h), f = a % 100;
    return (h < 0 ? '-' : '') + Math.floor(a / 100) + (f === 0 ? '' : ',' + pad2(f).replace(/0$/, ''));
  }
  /* Mesatarja e rrumbullakuar gjysma lart, me numra të plotë (si qta_results_round_avg). */
  function avg(sum, n) { return Math.floor((2 * sum + n) / (2 * n)); }

  /* ------------------------------------------------------------- Gjendja */
  function build(sheet, gid) {
    var st = { gid: gid, sheet: sheet, edit: !!CFG.edit, modules: sheet.modules || [], members: sheet.members || [],
               base: {}, cells: {}, byId: {}, error: null, saved: null, lastKey: null };
    st.members.forEach(function (m) {
      st.byId[m.id] = m;
      st.modules.forEach(function (mod) {
        var k = m.id + ':' + mod.id;
        var h = fromDb(m.scores ? m.scores[mod.id] : null);
        st.base[k] = h;
        st.cells[k] = { raw: h === null ? '' : label(h), h: h, error: null };
      });
    });
    return st;
  }
  function who(m) { return m.name || ('amza ' + m.amze); }
  function isDirty(k) { var c = S.cells[k]; return !!c.error || c.h !== S.base[k]; }
  function counts() {
    var out = { dirty: 0, bad: 0, first: null };
    Object.keys(S.cells).forEach(function (k) {
      if (isDirty(k)) out.dirty++;
      if (S.cells[k].error) { out.bad++; if (!out.first) out.first = k; }
    });
    return out;
  }

  /* Parashikimi i rezultatit — i njëjti rregull si qta_results_compute(). */
  function compute(m) {
    var req = S.modules.length, scored = 0, sum = 0, bad = 0;
    S.modules.forEach(function (mod) {
      var c = S.cells[m.id + ':' + mod.id];
      if (c.error) bad++;
      else if (c.h !== null) { scored++; sum += c.h; }
    });
    var legacy = fromDb(m.legacy);
    if (!scored && m.result_source === 'manual') return {mode:'manual',required:req,scored:0,final:fromDb(m.manual),legacy:legacy};
    if (bad) return { mode: 'invalid', required: req, scored: scored, legacy: legacy };
    if (!scored) return { mode: legacy !== null ? 'legacy' : 'none', required: req, scored: 0, final: legacy, legacy: legacy };
    var complete = req > 0 && scored === req;
    return { mode: 'modules', required: req, scored: scored, complete: complete,
             final: complete ? avg(sum, req) : null, partial: avg(sum, scored), legacy: legacy };
  }

  /* ---------------------------------------------------------- Vizatimi */
  function emptyHtml(icon, title, text, actions) {
    return '<div class="empty is-compact"><span class="empty-icon"><i class="bi ' + icon + '" aria-hidden="true"></i></span>'
      + '<p class="empty-title">' + esc(title) + '</p>' + (text ? '<p class="empty-text">' + esc(text) + '</p>' : '')
      + (actions ? '<div class="empty-actions">' + actions + '</div>' : '') + '</div>';
  }
  function notice(icon, html, action, cls) {
    return '<div class="notice ' + (cls || 'is-sunken') + '"><i class="bi ' + icon + '" aria-hidden="true"></i><span>' + html + '</span>' + (action || '') + '</div>';
  }
  function examCell(gid, sid) {
    return document.querySelector('td[data-field="exam_date"][data-group="' + gid + '"][data-student="' + sid + '"] .editable')
      || document.querySelector('#lgMembersTable td[data-field="exam_date"][data-student="' + sid + '"] .editable');
  }

  function renderHead() {
    var g = S.sheet.group;
    el.eyebrow.textContent = 'Grupi #' + g.id + ' · ' + g.course;
    /* Grupi i regjistrit të vjetër nuk ka kopje: ndjek modulet e kursit siç janë tani. */
    var parts = [['bi-people', plural(S.members.length, 'kursant', 'kursantë')],
      ['bi-collection', plural(S.modules.length, 'modul', 'module') + (g.source === 'course' && S.modules.length ? ' të kursit tani' : '')]];
    if (S.modules.length) parts.push(['bi-calculator', 'Rezultati: mesatarja e moduleve']);
    el.meta.innerHTML = parts.map(function (p) {
      return '<span><i class="bi ' + p[0] + '" aria-hidden="true"></i>' + esc(p[1]) + '</span>';
    }).join('');

    var html = [];
    if (S.edit && S.members.length && S.modules.length) {
      if (g.closed) html.push(notice('bi-lock', '<b>Grupi është i mbyllur.</b> Ruajtja e pikëve kërkon konfirmim, sepse dokumentet mund të jenë lëshuar.'));
      var noExam = S.members.filter(function (m) { return !m.exam; });
      if (noExam.length) {
        var btn = examCell(g.id, noExam[0].id)
          ? '<button type="button" class="btn btn-secondary btn-sm notice-action" data-rs-exam="' + noExam[0].id + '"><i class="bi bi-calendar-event" aria-hidden="true"></i>Cakto datën e provimit</button>' : '';
        html.push(notice('bi-calendar-x', (noExam.length === 1 ? '<b>' + esc(who(noExam[0])) + '</b> nuk ka datë provimi' : '<b>' + noExam.length + ' kursantë</b> nuk kanë datë provimi')
          + ': pikët vendosen pasi të caktohet data.', btn, 'is-warning' + (btn ? ' has-action' : '')));
      }
      var legacy = S.members.filter(function (m) { return compute(m).mode === 'legacy'; }).length;
      if (legacy) {
        html.push(notice('bi-archive', (legacy === 1 ? '1 kursant ka' : legacy + ' kursantë kanë') + ' vetëm pikë të vjetra, pa ndarje sipas moduleve. '
          + 'Kur u vendos pikë moduli, rezultati i tyre llogaritet nga modulet.'));
      }
    }
    if(S.edit && S.members.length) html.push(notice('bi-person-vcard','Periudha, provimi dhe prejardhja e rezultatit ruhen për secilin kursant.',S.members.map(function(m){return '<button type="button" class="btn btn-secondary btn-sm" data-enrollment-open data-student="'+m.id+'" data-course="'+g.course_id+'">'+esc(who(m))+' · Të dhënat e kursit</button>';}).join(' ')));
    el.notices.innerHTML = html.join('');
    el.notices.hidden = !html.length;
  }

  function tableHtml() {
    var h = '<table class="table rs-grid">'
      + '<caption class="visually-hidden">Pikët e kursantëve sipas moduleve. Kolona e fundit, Rezultati, është mesatarja e moduleve dhe llogaritet vetë.</caption>'
      + '<thead><tr><th scope="col" class="rs-who">Kursanti</th>';
    S.modules.forEach(function (mod) {
      h += '<th scope="col" class="rs-mod" title="' + esc(mod.title) + '"><span class="rs-mod-name">' + esc(mod.title) + '</span></th>';
    });
    h += '<th scope="col" class="rs-final">Rezultati</th></tr></thead><tbody>';
    S.members.forEach(function (m) {
      var noExam = !m.exam;
      h += '<tr data-sid="' + m.id + '"' + (noExam ? ' class="is-blocked"' : '') + '>'
        + '<th scope="row" class="rs-who"><span class="rs-name">' + esc(m.name || 'Pa emër ende') + '</span>'
        + '<span class="rs-meta"><span class="id-code">' + esc(m.amze) + '</span> · '
        + (noExam ? '<span class="rs-noexam">pa datë provimi</span>' : '<span>provimi ' + esc(m.exam_label) + '</span>') + '</span></th>';
      S.modules.forEach(function (mod) {
        var k = m.id + ':' + mod.id;
        var c = S.cells[k];
        if (S.edit) {
          h += '<td class="rs-cell"><input class="rs-input" type="text" inputmode="decimal" autocomplete="off" spellcheck="false" maxlength="6"'
            + ' data-k="' + k + '" data-mid="' + mod.id + '" value="' + esc(c.raw) + '"'
            + ' aria-label="' + esc(mod.title + ' — ' + who(m)) + '" aria-describedby="rsF' + m.id + '"'
            + (noExam ? ' disabled title="Cakto së pari datën e provimit të kursantit"' : '') + '></td>';
        } else {
          h += '<td class="rs-cell is-readonly">' + (c.h === null ? '<span aria-hidden="true">—</span><span class="visually-hidden">pa pikë</span>' : esc(label(c.h))) + '</td>';
        }
      });
      h += '<td class="rs-final"><output class="rs-final-out" id="rsF' + m.id + '" data-final-for="' + m.id + '"></output></td></tr>';
    });
    return h + '</tbody></table>';
  }

  function paintRow(m) {
    var out = el.body.querySelector('[data-final-for="' + m.id + '"]');
    if (!out) return;
    var r = compute(m), cls = 'is-empty', main = null, subs = [], title = '';
    if (r.mode === 'invalid') {
      cls = 'is-error'; subs.push('kontrollo pikët');
    } else if (r.mode === 'modules') {
      if (r.complete) { cls = 'is-final'; main = label(r.final); }
      else {
        cls = 'is-pending'; subs.push(r.scored + ' nga ' + r.required + ' module');
        title = 'Mesatarja e ' + plural(r.scored, 'modulit', 'moduleve') + ' me pikë: ' + label(r.partial) + '. Rezultati del kur çdo modul ka pikë.';
      }
      if (r.legacy !== null) subs.push('më parë ' + label(r.legacy));
    } else if (r.mode === 'manual') {
      cls='is-legacy'; main=label(r.final); subs.push('rezultat manual');
    } else if (r.mode === 'legacy') {
      cls = 'is-legacy'; main = label(r.final); subs.push('pikë të vjetra');
      title = 'Pikë të shkruara para pikëve sipas moduleve. Nuk ndahen nëpër module.';
    }
    out.className = 'rs-final-out ' + cls;
    /* Çdo shënim në rreshtin e vet: kolona mbetet e njëjtë ndërsa shkruhet. */
    out.innerHTML = '<span class="visually-hidden">Rezultati: </span>'
      + (main !== null ? '<span class="rs-final-value">' + esc(main) + '</span>'
        : '<span class="rs-final-value" aria-hidden="true">—</span>' + (subs.length ? '' : '<span class="visually-hidden">ende pa rezultat</span>'))
      + subs.map(function (s) { return '<span class="rs-final-sub">' + esc(s) + '</span>'; }).join('<span class="visually-hidden">, </span>');
    if (title) out.title = title; else out.removeAttribute('title');
  }

  function inputFor(k) { return k ? el.body.querySelector('.rs-input[data-k="' + k + '"]') : null; }
  function paintCell(inp) {
    var k = inp.getAttribute('data-k'), c = S.cells[k], dirty = isDirty(k);
    inp.classList.toggle('is-dirty', dirty && !c.error);
    if (c.error) { inp.setAttribute('aria-invalid', 'true'); inp.title = c.error; }
    else { inp.removeAttribute('aria-invalid'); if (!inp.disabled) inp.removeAttribute('title'); }
    inp.parentNode.classList.toggle('is-dirty', dirty);
    inp.parentNode.classList.toggle('is-invalid', !!c.error);
  }

  var lastStatus = '';
  function paintStatus() {
    if (!S) return;
    var n = counts(), icon = '', text, cls = '';
    if (saving) { icon = 'bi-hourglass-split'; text = 'Po ruhen pikët…'; }
    else if (n.bad) { icon = 'bi-exclamation-circle'; cls = 'is-error'; text = (n.bad === 1 ? 'Një qelizë ka pikë të pavlefshme' : n.bad + ' qeliza kanë pikë të pavlefshme') + ': ' + S.cells[n.first].error; }
    else if (S.error) { icon = 'bi-x-circle-fill'; cls = 'is-error'; text = S.error; }
    else if (n.dirty) { icon = 'bi-pencil'; cls = 'is-dirty'; text = plural(n.dirty, 'ndryshim i paruajtur', 'ndryshime të paruajtura') + '.'; }
    else if (S.saved) { icon = 'bi-check-circle-fill'; cls = 'is-ok'; text = S.saved; }
    else if (!S.edit) { icon = 'bi-lock'; text = 'Vetëm për lexim.'; }
    else if (!S.members.length || !S.modules.length) { text = ''; }
    else { text = 'Pikët nga 0 deri në 100.' + (TOUCH ? '' : ' Enter kalon te kursanti tjetër, Ctrl+S ruan.'); }
    var key = cls + '|' + text;
    if (key !== lastStatus) {
      lastStatus = key;
      el.status.className = 'rs-status' + (cls ? ' ' + cls : '');
      el.status.innerHTML = (icon ? '<i class="bi ' + icon + '" aria-hidden="true"></i>' : '') + '<span>' + esc(text) + '</span>';
    }
    if (el.save) {
      el.save.disabled = saving || !n.dirty || n.bad > 0;
      /* Pa kursantë ose pa module nuk ka çfarë të ruhet: veprimi i vetëm është te gjendja bosh. */
      el.save.hidden = !S.members.length || !S.modules.length;
    }
    if (el.revert) el.revert.hidden = saving || !n.dirty;
  }

  function render() {
    renderHead();
    var g = S.sheet.group;
    if (!S.members.length) {
      el.body.innerHTML = emptyHtml('bi-people', 'Grupi nuk ka ende kursantë', 'Shto kursantët e grupit, pastaj vendos pikët e tyre sipas moduleve.');
    } else if (!S.modules.length) {
      el.body.innerHTML = emptyHtml('bi-collection', 'Kursi nuk ka module',
        'Struktura e moduleve të këtij kursi nuk është përfunduar ende. Hap të dhënat individuale të kursantit për rezultatin manual dhe datën e provimit.',
        '<a class="btn btn-primary" href="course.php?id=' + encodeURIComponent(g.course_id) + '"><i class="bi bi-diagram-3" aria-hidden="true"></i>Hap kursin</a>');
    } else {
      el.body.innerHTML = tableHtml();
      S.members.forEach(paintRow);
      if (S.edit) Array.prototype.forEach.call(el.body.querySelectorAll('.rs-input'), paintCell);
    }
    lastStatus = '';
    paintStatus();
  }

  function keepScroll(fn) {
    var left = el.body.scrollLeft, top = el.body.scrollTop;
    fn();
    el.body.scrollLeft = left;
    el.body.scrollTop = top;
  }

  /* -------------------------------------------------------- Leximi */
  function post(payload) {
    return window.qtaFetch.response(CFG.endpoint || 'group_results.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf: CFG.csrf }, payload))
    }).then(function (r) {
      return r.json().catch(function () { return null; }).then(function (json) {
        if (!json) throw new Error('bad_response');
        return { status: r.status, json: json };
      });
    });
  }

  function start(gid, focusSid, lbl) {
    var mine = ++seq;
    S = null; closing = false; saving = false; goTo = null; lastStatus = '';
    pendingFocus = focusSid || true;
    el.eyebrow.textContent = lbl || ('Grupi #' + gid);
    el.meta.innerHTML = '';
    el.notices.hidden = true;
    el.notices.innerHTML = '';
    el.body.innerHTML = '<div class="rs-loading" aria-hidden="true"><span class="skeleton"></span><span class="skeleton"></span><span class="skeleton"></span></div>';
    el.status.className = 'rs-status';
    el.status.textContent = 'Po ngarkohen pikët…';
    if (el.save) el.save.disabled = true;
    if (el.revert) el.revert.hidden = true;
    if (el.unlock) el.unlock.href = String(CFG.unlock || '#').split('{gid}').join(String(gid));
    post({ action: 'sheet', group_id: gid }).then(function (res) {
      if (mine !== seq) return;
      if (!res.json.ok) { failLoad(res.json.error, gid, focusSid, lbl); return; }
      S = build(res.json.sheet, gid);
      render();
      if (shown) doFocus();
    }).catch(function () {
      if (mine === seq) failLoad('Pikët nuk u ngarkuan: nuk mora përgjigje nga serveri. Kontrollo lidhjen dhe provo sërish.', gid, focusSid, lbl);
    });
  }
  function failLoad(message, gid, focusSid, lbl) {
    el.body.innerHTML = emptyHtml('bi-wifi-off', 'Pikët nuk u ngarkuan', message || 'Provo sërish.',
      '<button type="button" class="btn btn-secondary" data-rs-retry><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Provo sërish</button>');
    el.status.textContent = '';
    var retry = el.body.querySelector('[data-rs-retry]');
    retry.addEventListener('click', function () { start(gid, focusSid, lbl); });
    if (shown) retry.focus();
  }

  function doFocus() {
    var want = pendingFocus;
    pendingFocus = null;
    if (!S || !S.edit || want === null) return;
    var row = typeof want === 'number' ? el.body.querySelector('tr[data-sid="' + want + '"]') : null;
    var pick = function (scope) {
      var list = scope ? scope.querySelectorAll('.rs-input:not(:disabled)') : [];
      return Array.prototype.find.call(list, function (i) { return i.value === ''; }) || list[0] || null;
    };
    var target = pick(row) || (row ? el.notices.querySelector('[data-rs-exam]') : null) || pick(el.body);
    if (target) target.focus();
  }

  /* ------------------------------------------------------ Redaktimi */
  function setCell(inp, raw) {
    var k = inp.getAttribute('data-k');
    var p = parseScore(raw);
    S.cells[k] = { raw: raw, h: p.error ? null : p.h, error: p.error || null };
    S.error = null;
    S.saved = null;
    paintCell(inp);
    paintRow(S.byId[k.split(':')[0]]);
    paintStatus();
  }
  function target(ev) {
    var t = ev.target;
    return t && t.classList && t.classList.contains('rs-input') ? t : null;
  }
  function column(mid) {
    return Array.prototype.filter.call(el.body.querySelectorAll('.rs-input[data-mid="' + mid + '"]'), function (i) { return !i.disabled; });
  }
  function move(inp, dir, wrap) {
    var list = column(inp.getAttribute('data-mid'));
    var next = list[list.indexOf(inp) + dir] || null;
    if (!next && wrap && dir > 0) {
      var ids = S.modules.map(function (m) { return String(m.id); });
      for (var j = ids.indexOf(inp.getAttribute('data-mid')) + 1; j < ids.length && !next; j++) next = column(ids[j])[0] || null;
    }
    if (next) next.focus();
  }

  el.body.addEventListener('focusin', function (ev) {
    var inp = target(ev);
    if (!inp || !S) return;
    inp.dataset.focusValue = inp.value;
    inp.dataset.selectOnUp = '1';
    S.lastKey = inp.getAttribute('data-k');
    try { inp.select(); } catch (e) { /* disa shfletues nuk lejojnë */ }
  });
  /* Klikimi me mi e zgjedh vlerën: shkrimi e zëvendëson, si te një fletë llogaritëse. */
  el.body.addEventListener('mouseup', function (ev) {
    var inp = target(ev);
    if (inp && inp.dataset.selectOnUp === '1') { ev.preventDefault(); delete inp.dataset.selectOnUp; }
  });
  el.body.addEventListener('input', function (ev) {
    var inp = target(ev);
    if (inp && S && !saving) setCell(inp, inp.value);
  });
  el.body.addEventListener('focusout', function (ev) {
    var inp = target(ev);
    if (!inp || !S) return;
    delete inp.dataset.selectOnUp;
    var c = S.cells[inp.getAttribute('data-k')];
    if (c && !c.error) {
      var norm = c.h === null ? '' : label(c.h);
      if (inp.value !== norm) { inp.value = norm; c.raw = norm; }
    }
  });
  el.body.addEventListener('keydown', function (ev) {
    var inp = target(ev);
    if (!inp || !S) return;
    if (ev.key === 'Enter' || ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      if (ev.altKey || ev.ctrlKey || ev.metaKey) return;
      ev.preventDefault();
      var up = ev.key === 'ArrowUp' || (ev.key === 'Enter' && ev.shiftKey);
      move(inp, up ? -1 : 1, ev.key === 'Enter' && !up);
    } else if (ev.key === 'Escape') {
      var before = inp.dataset.focusValue || '';
      if (inp.value !== before) {
        /* Esc kthen vetëm këtë qelizë; dritarja mbetet e hapur. */
        ev.preventDefault();
        ev.stopPropagation();
        inp.value = before;
        setCell(inp, before);
        inp.select();
      }
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (!shown || !S || !S.edit || document.querySelector('.modal.show.is-stacked')) return;
    if ((ev.ctrlKey || ev.metaKey) && !ev.altKey && (ev.key === 's' || ev.key === 'S')) {
      ev.preventDefault();
      save(false);
    }
  });

  /* ----------------------------------------------------------- Ruajtja */
  function changes() {
    var out = [];
    Object.keys(S.cells).forEach(function (k) {
      var c = S.cells[k];
      if (c.error || c.h === S.base[k]) return;
      var p = k.split(':');
      out.push({ student_id: +p[0], module_id: +p[1], from: S.base[k] === null ? null : toDb(S.base[k]), to: c.h === null ? null : toDb(c.h) });
    });
    return out;
  }
  function setBusy(on) {
    saving = on;
    Array.prototype.forEach.call(el.body.querySelectorAll('.rs-input'), function (i) { i.readOnly = on; });
    if (el.save) {
      el.save.classList.toggle('is-loading', on);
      el.save.setAttribute('aria-busy', on ? 'true' : 'false');
    }
    paintStatus();
  }

  function save(force) {
    if (!S || !S.edit || saving) return;
    var n = counts();
    if (n.bad) {
      var bad = inputFor(n.first);
      if (bad) bad.focus();
      paintStatus();
      return;
    }
    var cells = changes();
    if (!cells.length) return;
    var gid = S.gid;
    var active = document.activeElement && document.activeElement.getAttribute ? document.activeElement.getAttribute('data-k') : null;
    setBusy(true);
    post({ action: 'save', group_id: gid, cells: cells, force: force ? 1 : 0 }).then(function (res) {
      if (!S || S.gid !== gid) return;
      var json = res.json;
      if (json.ok) {
        setBusy(false);
        applySaved(json, cells, active || S.lastKey);
        return;
      }
      if (res.status === 409 && json.confirm) {
        setBusy(false);
        window.qtaConfirm({ title: json.confirm.title, message: json.confirm.message, confirm: json.confirm.confirm, danger: false }).then(function (ok) {
          if (ok) save(true);
        });
        return;
      }
      setBusy(false);
      failSave(json);
    }).catch(function (err) {
      if (!S || S.gid !== gid) return;
      setBusy(false);
      S.error = err.message + ' Ndryshimet e tua janë ende këtu.';
      paintStatus();
    }).finally(function () { if (S && S.gid === gid) setBusy(false); });
  }

  function applySaved(json, sent, focusKey) {
    S = build(json.sheet, S.gid);
    S.saved = json.message || 'Pikët u ruajtën.';
    keepScroll(render);
    sent.forEach(function (c) {
      var inp = inputFor(c.student_id + ':' + c.module_id);
      if (!inp) return;
      inp.classList.add('is-saved');
      setTimeout(function () { inp.classList.remove('is-saved'); }, 950);
    });
    var back = inputFor(focusKey);
    if (back && !back.disabled) back.focus({ preventScroll: true });
    else if (el.save) el.save.focus();
    updatePage(json.sheet);
  }

  function failSave(json) {
    var d = json.details || {};
    if (json.code === 'stale' && d.conflicts) {
      /* Vlera e re e dikujt tjetër bëhet bazë; ajo që shkroi përdoruesi mbetet e paruajtur. */
      d.conflicts.forEach(function (c) {
        var k = c.student_id + ':' + c.module_id;
        if (!(k in S.base)) return;
        S.base[k] = fromDb(c.value);
        var inp = inputFor(k);
        if (inp) paintCell(inp);
      });
      S.members.forEach(paintRow);
    }
    if (json.code === 'invalid' && d.cells) {
      d.cells.forEach(function (c) {
        var k = c.student_id + ':' + c.module_id;
        if (!S.cells[k]) return;
        S.cells[k].error = c.message;
        var inp = inputFor(k);
        if (inp) paintCell(inp);
      });
      S.members.forEach(paintRow);
    }
    S.error = json.error || 'Pikët nuk u ruajtën.';
    lastStatus = '';
    paintStatus();
  }

  /* Pas ruajtjes: kolona "Pikët" e faqes merr rezultatin e serverit; faqja
     rifreskon gjendjet dhe numëruesit me ngjarjen qta:results-saved. */
  function updatePage(sheet) {
    var gid = sheet.group.id, req = (sheet.modules || []).length, detail = [];
    (sheet.members || []).forEach(function (m) {
      var scored = 0;
      (sheet.modules || []).forEach(function (mod) { if (m.scores && m.scores[mod.id] != null) scored++; });
      var fin = fromDb(m.final);
      var pending = fin === null && scored > 0 && req > 0;
      var sub = pending ? scored + ' nga ' + req + ' module' : '';
      detail.push({ student_id: m.id, final: m.final, scored: scored, required: req });
      Array.prototype.forEach.call(document.querySelectorAll('[data-final-cell][data-group="' + gid + '"][data-student="' + m.id + '"]'), function (td) {
        td.setAttribute('data-final', m.final || '');
        var btn = td.querySelector('.rs-open');
        if (!btn) return;
        btn.innerHTML = '<span class="rs-open-value">' + esc(label(fin)) + '</span>' + (sub ? '<span class="rs-open-sub">' + esc(sub) + '</span>' : '');
        btn.setAttribute('aria-label', 'Pikët e ' + who(m) + ': ' + (fin !== null ? label(fin) : (pending ? sub : 'ende pa pikë')) + '. Hap pikët sipas moduleve');
      });
    });
    document.dispatchEvent(new CustomEvent('qta:results-saved', { detail: { group: gid, members: detail } }));
  }

  if (el.save) el.save.addEventListener('click', function () { save(false); });
  if (el.revert) el.revert.addEventListener('click', function () {
    if (!S) return;
    var n = counts().dirty;
    window.qtaConfirm({
      title: 'Të anulohen ndryshimet?',
      message: 'Pikët kthehen siç ishin kur u hap dritarja (' + plural(n, 'ndryshim', 'ndryshime') + ').',
      confirm: 'Po, anuloji', cancel: 'Jo, vazhdo', danger: false
    }).then(function (ok) {
      if (!ok || !S) return;
      Object.keys(S.cells).forEach(function (k) {
        var h = S.base[k];
        S.cells[k] = { raw: h === null ? '' : label(h), h: h, error: null };
      });
      S.error = null;
      keepScroll(render);
      var first = el.body.querySelector('.rs-input:not(:disabled)');
      if (first) first.focus({ preventScroll: true });
    });
  });

  el.notices.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-rs-exam]') : null;
    if (!b || !S) return;
    goTo = examCell(S.gid, b.getAttribute('data-rs-exam'));
    window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
  });

  /* ------------------------------------------------ Hapja dhe mbyllja */
  modalEl.addEventListener('show.bs.modal', function (ev) {
    if(enrollmentSuspended) {
      enrollmentSuspended=false;
      if(enrollmentRefresh && S) start(S.gid,Number(enrollmentRefresh),el.eyebrow.textContent);
      enrollmentRefresh=false; return;
    }
    var t = ev.relatedTarget;
    var gid = t && t.getAttribute ? parseInt(t.getAttribute('data-results-group') || '0', 10) : 0;
    if (!gid) { ev.preventDefault(); return; }
    var focus = parseInt(t.getAttribute('data-results-focus') || '0', 10);
    start(gid, focus > 0 ? focus : null, t.getAttribute('data-results-label'));
  });
  modalEl.addEventListener('shown.bs.modal', function () {
    shown = true;
    if (S) doFocus();
  });
  modalEl.addEventListener('hide.bs.modal', function (ev) {
    if(enrollmentSuspended) return;
    if (closing || !S || saving) {
      if (saving) ev.preventDefault();
      return;
    }
    var n = counts().dirty;
    if (!n) return;
    ev.preventDefault();
    window.qtaConfirm({
      title: 'Të mbyllen pikët pa u ruajtur?',
      message: 'Ke ' + plural(n, 'ndryshim të paruajtur', 'ndryshime të paruajtura') + '. Nëse e mbyll dritaren, ato humbasin.',
      confirm: 'Mbylle pa ruajtur', cancel: 'Kthehu te pikët', danger: true
    }).then(function (ok) {
      if (!ok) { goTo = null; return; }
      closing = true;
      window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    });
  });
  modalEl.addEventListener('hidden.bs.modal', function () {
    shown = false;
    if(enrollmentSuspended) return;
    closing = false;
    S = null;
    seq++;
    el.body.innerHTML = '';
    el.notices.innerHTML = '';
    el.notices.hidden = true;
    lastStatus = '';
    if (goTo) {
      var dest = goTo;
      goTo = null;
      if (modalEl.getAttribute('data-return-to')) {
        /* Dritarja e grupit rihapet (app.js) dhe fokusi shkon te data e provimit. */
        modalEl._qtaReturnTrigger = dest;
      } else {
        modalEl._qtaOpener = null;
        setTimeout(function () { try { dest.focus(); } catch (e) { /* fusha mund të jetë hequr */ } }, 60);
      }
    }
  });
  window.QtaResultsEnrollment={
    suspend:function(){
      if(!S || saving || counts().dirty) {
        window.qtaToast('Ruaj ose zhbëj ndryshimet e pikëve përpara hapjes së të dhënave individuale.','warning');return false;
      }
      enrollmentSuspended=true;enrollmentOpener=modalEl._qtaOpener;return true;
    },
    resume:function(trigger,changed){
      enrollmentRefresh=changed && trigger?Number(trigger.dataset.student):false;
      window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
      modalEl._qtaOpener=enrollmentOpener;
    }
  };
  window.addEventListener('beforeunload', function (e) {
    if (S && shown && counts().dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  /* ?results=12: hape dritaren pas "Lejo ndryshimet" (faqja ringarkohet). */
  document.addEventListener('DOMContentLoaded', function () {
    var url;
    try { url = new URL(window.location.href); } catch (e) { return; }
    var gid = parseInt(url.searchParams.get('results') || '0', 10);
    if (!gid) return;
    url.searchParams.delete('results');
    try { window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash); } catch (e) { /* adresa mbetet */ }
    var trigger = document.querySelector('button.btn[data-results-group="' + gid + '"]');
    if (!trigger) return;
    var host = trigger.closest('.modal');
    if (host && !host.classList.contains('show')) {
      host.addEventListener('shown.bs.modal', function () { trigger.click(); }, { once: true });
    } else {
      trigger.click();
    }
  });
})();
