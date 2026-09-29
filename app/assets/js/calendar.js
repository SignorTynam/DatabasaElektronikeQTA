/* ============================================================================
   calendar.js — Kalendari i grupeve (calendar.php).

   Pamje vetëm për lexim e grupeve të Regjistrit të kurseve profesionale: kur fillon
   dhe kur mbaron çdo grup brenda një muaji. Të dhënat vijnë gati nga serveri
   (calendar_data.php → app/shared/group_calendar.php): gjendja me fjalë, datat,
   kohëzgjatja, kursantët, modulet. Këtu vetëm vizatohen — asnjë rregull biznesi dhe
   asnjë datë nuk llogaritet sërish; "sot" është dita e serverit.

   Pamjet
     Kalendar  një rresht për grup, ditët e muajit në horizontale. Fillimi dhe mbarimi
               janë ditë mësimi (të përfshira), prandaj vija mbyllet te vija e rrjetës
               PAS ditës së mbarimit: grid-column: fillimi / mbarimi + 1.
     Listë     grupet me radhë sipas fillimit; parazgjedhje në ekrane të ngushta.

   Adresa ndjek gjendjen (?month=2026-10&view=list&status=active&course_id=5&legacy=1
   &group=12): muaji, pamja dhe filtrat shtojnë një hap në historik, po ashtu dritarja e
   një grupi, që "Prapa" ta mbyllë. Datat trajtohen si data pa orë (UTC).

   Tastiera: shigjetat lart/poshtë mes grupeve, Home/End në skaje, Page Up/Page Down
   muaji, Enter/Hapësirë hap dritaren; Esc e mbyll dhe fokusi kthehet te grupi.
   ========================================================================= */
(function () {
  'use strict';

  var root = document.querySelector('[data-cal]');
  var cfgEl = document.getElementById('calConfig');
  if (!root || !cfgEl) return;
  var CFG = JSON.parse(cfgEl.textContent || '{}');

  var MONTHS = ['janar', 'shkurt', 'mars', 'prill', 'maj', 'qershor', 'korrik', 'gusht', 'shtator', 'tetor', 'nëntor', 'dhjetor'];
  var MONTHS_DEF = ['janari', 'shkurti', 'marsi', 'prilli', 'maji', 'qershori', 'korriku', 'gushti', 'shtatori', 'tetori', 'nëntori', 'dhjetori'];
  var MONTHS_SHORT = ['jan', 'shk', 'mar', 'pri', 'maj', 'qer', 'kor', 'gus', 'sht', 'tet', 'nën', 'dhj'];
  var DAYS_SHORT = ['Hë', 'Ma', 'Më', 'En', 'Pr', 'Sh', 'Di'];
  var DAYS = ['e hënë', 'e martë', 'e mërkurë', 'e enjte', 'e premte', 'e shtunë', 'e diel'];
  var STATES = ['active', 'upcoming', 'awaiting_close', 'closed'];
  var TODAY = String(CFG.today || '');
  var narrow = window.matchMedia ? window.matchMedia('(max-width: 767.98px)') : null;

  var el = {
    label: root.querySelector('[data-cal-label]'),
    body: root.querySelector('[data-cal-body]'),
    announce: root.querySelector('[data-cal-announce]'),
    views: Array.prototype.slice.call(root.querySelectorAll('[data-cal-view]')),
    chips: Array.prototype.slice.call(root.querySelectorAll('[data-cal-status]')),
    legacy: root.querySelector('[data-cal-legacy]'),
    legacyCount: root.querySelector('[data-cal-legacy-count]'),
    courseChip: root.querySelector('[data-cal-course-chip]'),
    courseChipText: root.querySelector('[data-cal-course-chip-text]'),
    course: root.querySelector('[data-cal-course]'),
    more: root.querySelector('[data-cal-more]'),
    moreCount: root.querySelector('[data-cal-more-count]'),
    jump: root.querySelector('[data-cal-jump]'),
    jumpField: root.querySelector('[data-cal-jump-field]')
  };

  /* Gjendja e faqes; e njëjtë me adresën. */
  var S = {
    month: String(CFG.month || TODAY.slice(0, 7)),
    view: CFG.view || '',
    status: CFG.status || '',
    course: CFG.course ? String(CFG.course) : '',
    legacy: !!CFG.legacy,
    group: CFG.group ? parseInt(CFG.group, 10) : null
  };
  var feed = CFG.feed || null;          /* përgjigjja e fundit e serverit për muajin */
  var courses = CFG.courses || {};

  /* ------------------------------------------------------------ Ndihmës */
  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
  /* Data pa orë: "2026-10-01" → milisekonda UTC; kurrë new Date("…") me orën lokale. */
  function ms(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
    return m ? Date.UTC(+m[1], +m[2] - 1, +m[3]) : NaN;
  }
  function dayIndex(iso, fromIso) { return Math.round((ms(iso) - ms(fromIso)) / 86400000); }
  function weekday(iso) { return (new Date(ms(iso)).getUTCDay() + 6) % 7; }   /* 0 = e hënë */
  function dmy(iso) { var p = String(iso).split('-'); return p[2] + '.' + p[1] + '.' + p[0]; }
  function range(a, b) { return a === b ? dmy(a) : dmy(a) + ' – ' + dmy(b); }
  function validMonth(v) { return /^(19\d\d|2[01]\d\d|2200)-(0[1-9]|1[0-2])$/.test(v || ''); }
  function monthInfo(ym) {
    var y = +ym.slice(0, 4), m = +ym.slice(5, 7);
    var days = new Date(Date.UTC(y, m, 0)).getUTCDate();
    return { ym: ym, y: y, m: m, days: days, from: ym + '-01', to: ym + '-' + pad(days),
             label: cap(MONTHS[m - 1]) + ' ' + y, inText: MONTHS[m - 1] + ' ' + y, def: MONTHS_DEF[m - 1] + ' ' + y };
  }
  function addMonths(ym, n) {
    var t = +ym.slice(0, 4) * 12 + (+ym.slice(5, 7) - 1) + n;
    return Math.floor(t / 12) + '-' + pad(t % 12 + 1);
  }
  function effectiveView() { return S.view || (narrow && narrow.matches ? 'list' : 'timeline'); }
  function announce(text) {
    if (!el.announce) return;
    el.announce.textContent = '';
    setTimeout(function () { el.announce.textContent = text; }, 80);
  }
  function statusHtml(st) {
    return '<span class="status status-' + esc(st.tone) + '"><i class="bi ' + esc(st.icon) + '" aria-hidden="true"></i>' + esc(st.label) + '</span>';
  }
  function emptyHtml(icon, title, text, actions) {
    return '<div class="empty is-compact"><span class="empty-icon"><i class="bi ' + icon + '" aria-hidden="true"></i></span>'
      + '<p class="empty-title">' + esc(title) + '</p>' + (text ? '<p class="empty-text">' + esc(text) + '</p>' : '')
      + (actions ? '<div class="empty-actions">' + actions + '</div>' : '') + '</div>';
  }

  /* Emri i plotë i një grupi për lexuesit e ekranit (përfshin tekstin që shihet). */
  function eventLabel(ev, mi) {
    var parts = [ev.course + ', Grupi #' + ev.id + (ev.legacy ? ', regjistri i vjetër' : ''), ev.status.label,
      'Nga ' + dmy(ev.start) + ' deri më ' + dmy(ev.end) + ', ' + plural(ev.days, 'ditë', 'ditë')];
    if (mi && ev.start < mi.from) parts.push('Fillon para këtij muaji');
    if (mi && ev.end > mi.to) parts.push('Vazhdon pas këtij muaji');
    parts.push(plural(ev.members, 'kursant', 'kursantë'));
    return parts.join('. ') + '.';
  }

  /* ------------------------------------------------------------ Filtrat */
  function byCourse(list) {
    return S.course ? list.filter(function (ev) { return String(ev.course_id) === S.course; }) : list;
  }
  function visible() {
    var list = byCourse(feed ? feed.events : []);
    return S.status ? list.filter(function (ev) { return ev.status.key === S.status; }) : list;
  }

  /* ------------------------------------------------------------ Pamja: kalendar */
  function timelineHtml(list, mi) {
    var todayIdx = TODAY >= mi.from && TODAY <= mi.to ? dayIndex(TODAY, mi.from) : -1;
    var head = '', cols = '';
    for (var i = 0; i < mi.days; i++) {
      var wd = weekday(mi.ym + '-' + pad(i + 1));
      var cls = (wd === 6 ? ' is-sun' : '') + (wd === 0 && i > 0 ? ' is-week' : '') + (i === todayIdx ? ' is-today' : '');
      head += '<span class="cal-day' + cls + '"><span class="cal-wd">' + DAYS_SHORT[wd] + '</span><span class="cal-dn">' + (i + 1) + '</span></span>';
      cols += '<span class="cal-col' + cls + '"></span>';
    }
    return '<div class="cal-scroll"><div class="cal-timeline" style="--cal-days:' + mi.days + '">'
      + '<div class="cal-head" aria-hidden="true"><span class="cal-corner">Grupi</span><span class="cal-scale">' + head + '</span></div>'
      + '<div class="cal-grid"><span class="cal-cols" aria-hidden="true">' + cols + '</span>'
      + '<ol class="cal-rows" aria-label="' + esc('Grupet në ' + mi.inText) + '" aria-describedby="calKeys">'
      + list.map(function (ev, k) { return rowHtml(ev, mi, k === 0); }).join('')
      + '</ol>' + (todayIdx >= 0 ? '<span class="cal-now" style="--cal-t:' + (todayIdx + 1) + '" aria-hidden="true"></span>' : '')
      + '</div></div></div>';
  }

  function rowHtml(ev, mi, first) {
    var st = ev.status;
    var before = ev.start < mi.from, after = ev.end > mi.to;
    var s = before ? 0 : dayIndex(ev.start, mi.from);
    var e = after ? mi.days - 1 : dayIndex(ev.end, mi.from);
    var bar = 'cal-bar tone-' + st.tone + (ev.legacy ? ' is-legacy' : '') + (before ? ' is-before' : '') + (after ? ' is-after' : '');
    return '<li class="cal-row">'
      + '<button type="button" class="cal-event" data-cal-group="' + ev.id + '" aria-haspopup="dialog" tabindex="' + (first ? '0' : '-1') + '"'
      + ' aria-label="' + esc(eventLabel(ev, mi)) + '">'
      + '<span class="cal-label"><span class="cal-course">' + esc(ev.course) + '</span>'
      + '<span class="cal-sub"><span>Grupi #' + ev.id + (ev.legacy ? ' · regjistri i vjetër' : '') + '</span>'
      + '<span class="cal-state tone-' + esc(st.tone) + '"><i class="bi ' + esc(st.icon) + '" aria-hidden="true"></i>' + esc(st.label) + '</span></span></span>'
      + '<span class="cal-track"><span class="' + bar + '" style="--cal-s:' + (s + 1) + ';--cal-e:' + (e + 2) + '">'
      + (before ? '<i class="bi bi-chevron-left cal-cut" aria-hidden="true"></i>' : '')
      + '<i class="bi ' + (ev.legacy ? 'bi-archive' : esc(st.icon)) + ' cal-bar-icon" aria-hidden="true"></i>'
      + '<span class="cal-bar-text"><span class="cal-bar-dates">' + range(ev.start, ev.end) + ' · </span>'
      + '<span class="cal-bar-days">' + plural(ev.days, 'ditë', 'ditë') + '</span></span>'
      + (after ? '<i class="bi bi-chevron-right cal-cut" aria-hidden="true"></i>' : '')
      + '</span></span></button></li>';
  }

  /* ------------------------------------------------------------ Pamja: listë */
  function listHtml(list, mi) {
    return '<ol class="cal-list" aria-label="' + esc('Grupet në ' + mi.inText) + '" aria-describedby="calKeys">'
      + list.map(function (ev, k) {
        var d = new Date(ms(ev.start));
        return '<li><button type="button" class="cal-item' + (ev.legacy ? ' is-legacy' : '') + '" data-cal-group="' + ev.id + '"'
          + ' aria-haspopup="dialog" tabindex="' + (k === 0 ? '0' : '-1') + '" aria-label="' + esc(eventLabel(ev, null)) + '">'
          + '<span class="cal-item-date" aria-hidden="true"><b>' + d.getUTCDate() + '</b><span>' + MONTHS_SHORT[d.getUTCMonth()] + '</span></span>'
          + '<span class="cal-item-main"><span class="cal-item-title">' + esc(ev.course) + '</span>'
          + '<span class="cal-item-meta">Grupi #' + ev.id + ' · ' + range(ev.start, ev.end) + ' · ' + plural(ev.days, 'ditë', 'ditë')
          + ' · ' + plural(ev.members, 'kursant', 'kursantë') + (ev.legacy ? ' · regjistri i vjetër' : '') + '</span></span>'
          + statusHtml(ev.status) + '</button></li>';
      }).join('') + '</ol>';
  }

  /* ------------------------------------------------------------ Gjendjet bosh */
  function emptyStateHtml(mi) {
    if (feed.events.length) {
      return emptyHtml('bi-funnel', 'Asnjë grup nuk përputhet me filtrat.', '',
        '<button class="btn btn-secondary" type="button" data-cal-clear>Hiq filtrat</button>');
    }
    var text = [], actions = '';
    var near = feed.nearest || {};
    if (near.prev) {
      text.push('Grupi i fundit mbaroi më ' + dmy(near.prev) + '.');
      actions += '<button class="btn btn-secondary" type="button" data-cal-go="' + near.prev.slice(0, 7) + '">Shko te ' + esc(monthInfo(near.prev.slice(0, 7)).def) + '</button>';
    }
    if (near.next) {
      text.push('Grupi i radhës nis më ' + dmy(near.next) + '.');
      actions += '<button class="btn btn-secondary" type="button" data-cal-go="' + near.next.slice(0, 7) + '">Shko te ' + esc(monthInfo(near.next.slice(0, 7)).def) + '</button>';
    }
    if (!S.legacy && feed.legacy_hidden > 0) {
      text.push(plural(feed.legacy_hidden, 'grup', 'grupe') + ' të regjistrit të vjetër nuk ' + (feed.legacy_hidden === 1 ? 'shfaqet.' : 'shfaqen.'));
      actions += '<button class="btn btn-secondary" type="button" data-cal-legacy-on>Shfaq regjistrin e vjetër</button>';
    }
    actions += '<a class="btn btn-ghost" href="' + esc(CFG.registry || 'lesson_groups.php') + '">Regjistri i kurseve profesionale</a>';
    return emptyHtml('bi-calendar-x', 'Nuk ka grupe në këtë periudhë.', text.join(' '), actions);
  }

  /* Seanca ka mbaruar (401) ose llogaria nuk ka më të drejtë (403): provimi sërish nuk
     ndihmon, prandaj veprimi është hyrja sërish. */
  function retryAction(status, attr) {
    return status === 401 || status === 403
      ? '<a class="btn btn-secondary" href="selectProfile.php"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Hyr sërish</a>'
      : '<button class="btn btn-secondary" type="button" ' + (attr || 'data-cal-retry') + '><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Provo sërish</button>';
  }
  function errorStateHtml(mi, message, status) {
    return emptyHtml('bi-wifi-off', 'Kalendari nuk u ngarkua për ' + mi.inText + '.',
      message || 'Kontrollo lidhjen me internetin dhe provo sërish.', retryAction(status));
  }

  /* ------------------------------------------------------------ Vizatimi */
  function paintToolbar() {
    var mi = monthInfo(S.month);
    if (el.label) el.label.textContent = mi.label;
    document.title = 'Kalendari · ' + mi.label + ' · Regjistri QTA';
    var view = effectiveView();
    el.views.forEach(function (b) {
      var on = b.getAttribute('data-cal-view') === view;
      b.setAttribute('aria-checked', on ? 'true' : 'false');
      b.tabIndex = on ? 0 : -1;
    });
    el.chips.forEach(function (c) {
      var on = c.getAttribute('data-cal-status') === S.status;
      c.classList.toggle('is-on', on);
      c.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    if (el.course && el.course.value !== S.course) el.course.value = S.course;
    var name = S.course ? courses[S.course] : '';
    if (el.courseChip) {
      el.courseChip.hidden = !S.course;
      el.courseChipText.textContent = S.course ? 'Kursi: ' + (name || '#' + S.course) : '';
      el.courseChip.setAttribute('aria-label', 'Hiq filtrin: Kursi: ' + (name || S.course));
    }
    if (el.moreCount) el.moreCount.hidden = !S.course;
  }

  /* Numrat e çipave: grupet e muajit sipas kursit, para zgjedhjes së gjendjes. */
  function paintCounts() {
    var base = feed ? byCourse(feed.events) : [];
    root.querySelectorAll('[data-cal-count]').forEach(function (c) {
      var key = c.getAttribute('data-cal-count');
      c.textContent = feed ? String(key ? base.filter(function (ev) { return ev.status.key === key; }).length : base.length) : '';
    });
    if (el.legacy) {
      var n = !feed ? 0 : (S.legacy ? base.filter(function (ev) { return ev.legacy; }).length : feed.legacy_hidden);
      el.legacy.hidden = !S.legacy && n === 0;
      el.legacy.classList.toggle('is-on', S.legacy);
      el.legacy.setAttribute('aria-pressed', S.legacy ? 'true' : 'false');
      el.legacyCount.textContent = feed ? String(n) : '';
    }
  }

  var rows = null;   /* lista e rreshtave të vizatuar (për tastierën) */
  function render(opts) {
    opts = opts || {};
    var mi = monthInfo(S.month);
    paintToolbar();
    paintCounts();
    var html;
    if (!feed) {
      html = errorStateHtml(mi, opts.error, opts.status);
    } else {
      var list = visible();
      html = list.length ? (effectiveView() === 'list' ? listHtml(list, mi) : timelineHtml(list, mi)) : emptyStateHtml(mi);
    }
    var keep = document.activeElement && el.body.contains(document.activeElement) ? document.activeElement.getAttribute('data-cal-group') : null;
    el.body.innerHTML = html;
    rows = el.body.querySelector('.cal-rows, .cal-list');
    if (opts.fresh) {
      el.body.classList.remove('is-fresh');
      void el.body.offsetWidth;
      el.body.classList.add('is-fresh');
    }
    /* Fokusi nuk humbet: i njëjti grup, i pari, ose titulli i muajit. */
    if (keep || opts.focus) {
      var target = (keep && el.body.querySelector('[data-cal-group="' + keep + '"]')) || (opts.focus !== 'keep' ? firstRow() : null);
      if (target) rove(target); else if (el.jump) el.jump.focus();
    }
  }

  /* ------------------------------------------------------------ Leximi */
  /* Gjatë leximit muaji i ri shihet menjëherë në titull; rreshtat e mëparshëm mbeten dhe
     zbehen vetëm nëse përgjigjja vonon (si te listat), me një vijë të hollë në shirit. */
  var seq = 0, ctrl = null, staleTimer = null;
  function setBusy(on) {
    root.classList.toggle('is-busy', on);
    clearTimeout(staleTimer);
    if (on) {
      el.body.setAttribute('aria-busy', 'true');
      staleTimer = setTimeout(function () { el.body.classList.add('is-stale'); }, 160);
    } else {
      el.body.removeAttribute('aria-busy');
      el.body.classList.remove('is-stale');
    }
  }

  function load(opts) {
    opts = opts || {};
    var mi = monthInfo(S.month);
    var mine = ++seq;
    if (ctrl) ctrl.abort();
    ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    setBusy(true);
    var url = CFG.endpoint + '?from=' + mi.from + '&to=' + mi.to + (S.legacy ? '&legacy=1' : '');
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: ctrl ? ctrl.signal : undefined })
      .then(function (r) {
        return r.json().catch(function () { return null; }).then(function (json) { return { status: r.status, json: json }; });
      })
      .then(function (res) {
        if (mine !== seq) return;
        setBusy(false);
        if (!res.json || !res.json.ok) {
          feed = null;
          render({ error: res.json && res.json.error ? res.json.error : null, status: res.status, focus: opts.focus ? 'keep' : false });
          announce('Kalendari nuk u ngarkua për ' + mi.inText + '.');
          return;
        }
        feed = res.json;
        if (feed.today) TODAY = String(feed.today);
        render({ fresh: true, focus: opts.focus });
        var n = visible().length;
        announce(mi.label + ': ' + (n ? plural(n, 'grup', 'grupe') : 'asnjë grup') + '.');
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        if (mine !== seq) return;
        setBusy(false);
        feed = null;
        render({ focus: opts.focus ? 'keep' : false });
        announce('Kalendari nuk u ngarkua për ' + mi.inText + '. Kontrollo lidhjen.');
      });
  }

  /* ------------------------------------------------------------ Adresa dhe historiku */
  function urlFor() {
    var p = new URLSearchParams();
    if (S.month !== TODAY.slice(0, 7)) p.set('month', S.month);
    if (S.view) p.set('view', S.view);
    if (S.status) p.set('status', S.status);
    if (S.course) p.set('course_id', S.course);
    if (S.legacy) p.set('legacy', '1');
    if (S.group) p.set('group', String(S.group));
    var q = p.toString();
    return window.location.pathname + (q ? '?' + q : '');
  }
  function commit(push) {
    try {
      window.history[push ? 'pushState' : 'replaceState']({ qtaCal: 1, group: S.group || null }, '', urlFor());
    } catch (e) { /* adresa mbetet */ }
  }
  function fromUrl() {
    var p = new URLSearchParams(window.location.search);
    var month = p.get('month') || '', status = p.get('status') || '', view = p.get('view') || '', course = p.get('course_id') || '';
    var group = parseInt(p.get('group') || '', 10);
    return {
      month: validMonth(month) ? month : TODAY.slice(0, 7),
      view: view === 'list' || view === 'timeline' ? view : '',
      status: STATES.indexOf(status) >= 0 ? status : '',
      course: /^\d{1,9}$/.test(course) ? course : '',
      legacy: p.get('legacy') === '1',
      group: group > 0 ? group : null
    };
  }

  /* Ndryshimet e përdoruesit: gjendja e re, adresa, pastaj vizatimi ose leximi. */
  function change(next, opts) {
    opts = opts || {};
    var reload = (next.month && next.month !== S.month) || (next.legacy !== undefined && next.legacy !== S.legacy);
    Object.keys(next).forEach(function (k) { S[k] = next[k]; });
    S.group = null;
    commit(true);
    if (reload) {
      paintToolbar();
      load({ focus: opts.focus });
    } else {
      render({ fresh: true, focus: opts.focus });
      var n = visible().length;
      announce(n ? plural(n, 'grup', 'grupe') + '.' : 'Asnjë grup.');
    }
  }
  function goMonth(delta, opts) { change({ month: addMonths(S.month, delta) }, opts); }

  var skipPop = false;
  window.addEventListener('popstate', function () {
    if (skipPop) { skipPop = false; return; }
    var st = fromUrl();
    var reload = st.month !== S.month || st.legacy !== S.legacy;
    var redraw = reload || st.view !== S.view || st.status !== S.status || st.course !== S.course;
    ['month', 'view', 'status', 'course', 'legacy'].forEach(function (k) { S[k] = st[k]; });
    if (reload) { paintToolbar(); load(); } else if (redraw) render({ fresh: true });
    if (st.group && st.group !== current.id) {
      openGroup(st.group, null, { history: false });
    } else if (!st.group && current.id !== null) {
      current.fromHistory = true;
      modal().hide();
    }
    S.group = st.group;
  });

  /* ------------------------------------------------------------ Tastiera dhe fokusi */
  function rowButtons() { return rows ? Array.prototype.slice.call(rows.querySelectorAll('[data-cal-group]')) : []; }
  function firstRow() { return rowButtons()[0] || null; }
  function rove(btn) {
    rowButtons().forEach(function (b) { b.tabIndex = b === btn ? 0 : -1; });
    try { btn.focus({ preventScroll: false }); } catch (e) { btn.focus(); }
  }

  el.body.addEventListener('keydown', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-cal-group]') : null;
    if (!btn || e.altKey || e.ctrlKey || e.metaKey) return;
    var all = rowButtons(), i = all.indexOf(btn), j;
    switch (e.key) {
      case 'ArrowDown': j = Math.min(all.length - 1, i + 1); break;
      case 'ArrowUp': j = Math.max(0, i - 1); break;
      case 'Home': j = 0; break;
      case 'End': j = all.length - 1; break;
      case 'PageDown': e.preventDefault(); goMonth(1, { focus: true }); return;
      case 'PageUp': e.preventDefault(); goMonth(-1, { focus: true }); return;
      default: return;
    }
    e.preventDefault();
    if (all[j]) rove(all[j]);
  });

  el.body.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.closest) return;
    var btn = t.closest('[data-cal-group]');
    if (btn) { rove(btn); openGroup(parseInt(btn.getAttribute('data-cal-group'), 10), btn, { history: true }); return; }
    var go = t.closest('[data-cal-go]');
    if (go) { change({ month: go.getAttribute('data-cal-go') }, { focus: true }); return; }
    if (t.closest('[data-cal-legacy-on]')) { change({ legacy: true }, { focus: true }); return; }
    if (t.closest('[data-cal-clear]')) { change({ status: '', course: '' }, { focus: true }); return; }
    if (t.closest('[data-cal-retry]')) { load({ focus: true }); }
  });

  /* ------------------------------------------------------------ Shiriti i veglave */
  root.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.closest || el.body.contains(t)) return;
    var step = t.closest('[data-cal-step]');
    if (step) { goMonth(parseInt(step.getAttribute('data-cal-step'), 10)); return; }
    if (t.closest('[data-cal-today]')) {
      if (S.month !== TODAY.slice(0, 7)) change({ month: TODAY.slice(0, 7) });
      else scrollToToday();
      return;
    }
    var view = t.closest('[data-cal-view]');
    if (view) { setView(view.getAttribute('data-cal-view')); return; }
    var chip = t.closest('[data-cal-status]');
    if (chip) { change({ status: chip.getAttribute('data-cal-status') }); chip.focus(); return; }
    if (t.closest('[data-cal-legacy]')) { change({ legacy: !S.legacy }); return; }
    if (t.closest('[data-cal-course-chip]')) {
      change({ course: '' });
      var summary = el.more && el.more.querySelector('summary');
      if (summary) summary.focus();
      return;
    }
    if (t.closest('[data-cal-more-reset]')) change({ course: '' });
  });

  function setView(view) {
    if (view === effectiveView()) return;
    change({ view: view });
    var b = root.querySelector('[data-cal-view="' + view + '"]');
    if (b) b.focus();
  }
  /* Grup radioje: shigjetat zgjedhin pamjen tjetër. */
  el.views.forEach(function (b) {
    b.addEventListener('keydown', function (e) {
      if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].indexOf(e.key) < 0) return;
      e.preventDefault();
      var i = el.views.indexOf(b);
      var j = (i + (e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 1) + el.views.length) % el.views.length;
      setView(el.views[j].getAttribute('data-cal-view'));
    });
  });

  if (el.course) el.course.addEventListener('change', function () { change({ course: el.course.value }); el.course.focus(); });

  /* "Filtra" mbyllet me Esc ose me klik jashtë, si te listat. */
  if (el.more) {
    document.addEventListener('click', function (e) {
      if (!el.more.open || el.more.contains(e.target)) return;
      el.more.open = false;
    });
    el.more.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || !el.more.open) return;
      e.stopPropagation();
      el.more.open = false;
      var summary = el.more.querySelector('summary');
      if (summary) summary.focus();
    });
  }

  /* Titulli i muajit hap kalendarin e përbashkët të datave (date-picker.js) mbi një fushë të
     fshehur: data e zgjedhur çon te muaji i saj. Fokusi që kalendari ia kthen fushës kalon
     te titulli. */
  if (el.jump && el.jumpField) {
    el.jump.addEventListener('click', function () {
      if (!window.qtaDatePicker) return;
      el.jumpField.value = dmy(S.month === TODAY.slice(0, 7) ? TODAY : S.month + '-01');
      window.qtaDatePicker.open(el.jumpField);
    });
    el.jumpField.addEventListener('change', function () {
      var iso = window.qtaDatePicker ? window.qtaDatePicker.parse(el.jumpField.value) : null;
      if (iso && iso.slice(0, 7) !== S.month) change({ month: iso.slice(0, 7) });
    });
    el.jumpField.addEventListener('focus', function () { el.jump.focus(); });
  }

  /* "Sot" në muajin e sotëm: dita theksohet shkurt; në ekran të ngushtë, ku kalendari
     rrëshqet anash, sillet në pamje. */
  function scrollToToday() {
    var day = el.body.querySelector('.cal-day.is-today');
    var box = el.body.querySelector('.cal-scroll');
    if (day) {
      if (box && box.scrollWidth > box.clientWidth) box.scrollLeft = Math.max(0, day.offsetLeft - box.clientWidth / 2);
      day.classList.remove('is-flash');
      void day.offsetWidth;
      day.classList.add('is-flash');
    }
    announce('Sot është ' + DAYS[weekday(TODAY)] + ', ' + dmy(TODAY) + '.');
  }

  if (narrow && narrow.addEventListener) {
    narrow.addEventListener('change', function () { if (!S.view) render(); });
  }

  /* ------------------------------------------------------------ Dritarja e grupit */
  var modalEl = document.getElementById('calGroup');
  var M = {
    eyebrow: modalEl.querySelector('[data-calg-eyebrow]'),
    title: modalEl.querySelector('[data-calg-title]'),
    meta: modalEl.querySelector('[data-calg-meta]'),
    body: modalEl.querySelector('[data-calg-body]'),
    open: modalEl.querySelector('[data-calg-open]'),
    course: modalEl.querySelector('[data-calg-course]')
  };
  var current = { id: null, pushed: false, fromHistory: false, seq: 0 };
  function modal() { return window.bootstrap.Modal.getOrCreateInstance(modalEl); }

  function findEvent(id) {
    var list = feed ? feed.events : [];
    for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i];
    return null;
  }

  function paintHead(g) {
    var code = g.course ? (typeof g.course === 'object' ? g.course.code : g.code) : '';
    var name = g.course ? (typeof g.course === 'object' ? g.course.name : g.course) : '';
    M.eyebrow.textContent = 'Grupi #' + g.id + (code ? ' · ' + code : '');
    M.title.textContent = name || 'Grupi #' + g.id;
    M.meta.innerHTML = g.start
      ? '<span>' + statusHtml(g.status) + '</span>'
        + '<span><i class="bi bi-calendar-range" aria-hidden="true"></i>' + range(g.start, g.end) + '</span>'
        + '<span><i class="bi bi-hourglass" aria-hidden="true"></i>' + plural(g.days, 'ditë', 'ditë') + '</span>'
        + (g.legacy ? '<span><i class="bi bi-archive" aria-hidden="true"></i>Regjistri i vjetër</span>' : '')
      : '';
    var courseHref = typeof g.course === 'object' && g.course ? g.course.href : (g.course_href || '');
    M.open.hidden = !g.href;
    if (g.href) M.open.href = g.href;
    M.course.hidden = !courseHref;
    if (courseHref) M.course.href = courseHref;
  }

  function paintLoading() {
    M.body.setAttribute('aria-busy', 'true');
    M.body.innerHTML = '<div class="calg-loading" aria-hidden="true">'
      + '<span class="skeleton is-line"></span><span class="skeleton is-line is-short"></span>'
      + '<span class="skeleton is-title"></span><span class="skeleton is-block"></span>'
      + '<span class="skeleton is-title"></span><span class="skeleton is-block"></span></div>'
      + '<p class="visually-hidden">Po ngarkohen detajet e grupit…</p>';
  }

  function detailHtml(g) {
    var h = '';
    if (g.note) {
      h += '<div class="notice is-sunken calg-note' + (g.conversion_href ? ' has-action' : '') + '"><i class="bi ' + esc(g.note.icon) + '" aria-hidden="true"></i>'
        + '<span><b>' + esc(g.note.lead) + '</b>' + esc(g.note.text) + '</span>'
        + (g.conversion_href ? '<a class="btn btn-secondary btn-sm notice-action" href="' + esc(g.conversion_href) + '"><i class="bi bi-arrow-left-right" aria-hidden="true"></i>Përgatit konvertimin</a>' : '')
        + '</div>';
    }
    if (g.facts && g.facts.length) {
      h += '<dl class="kv kv-2 calg-facts">' + g.facts.map(function (f) {
        return '<dt>' + esc(f.label) + '</dt><dd>' + esc(f.value) + '</dd>';
      }).join('') + '</dl>';
    }

    h += '<section class="modal-section" aria-labelledby="calgPeople"><div class="modal-section-head">'
      + '<h3 class="modal-section-title" id="calgPeople">Kursantët <span class="count">' + g.members.length + '/' + g.capacity + '</span></h3></div>';
    h += g.members.length
      ? '<ul class="calg-people">' + g.members.map(function (m) {
          return '<li><a class="person-name" href="' + esc(m.href) + '">' + esc(m.name || 'Pa emër ende') + '</a>'
            + '<span class="id-code"><span class="visually-hidden">Nr. i amzës </span>' + esc(m.amze) + '</span></li>';
        }).join('') + '</ul>'
      : '<p class="calg-muted">Grupi nuk ka ende kursantë.</p>';
    h += '</section>';

    if (g.curriculum) {
      var c = g.curriculum;
      h += '<section class="modal-section" aria-labelledby="calgCourse"><div class="modal-section-head">'
        + '<h3 class="modal-section-title" id="calgCourse">Përmbajtja e kursit</h3>'
        + (c.modules.length > 1 ? '<button class="btn btn-ghost btn-sm" type="button" data-calg-expand aria-expanded="false"><i class="bi bi-arrows-expand" aria-hidden="true"></i><span>Hap të gjitha</span></button>' : '')
        + '</div><p class="calg-source"><b>' + esc(c.summary) + '.</b> ' + esc(c.source) + '</p><ol class="calg-modules">';
      c.modules.forEach(function (mod) {
        h += '<li><details class="calg-module"' + (c.modules.length === 1 ? ' open' : '') + '><summary>'
          + '<span class="calg-mod-pos" aria-hidden="true">' + mod.seq + '</span>'
          + '<span class="calg-mod-main"><span class="calg-mod-title"><span class="visually-hidden">Moduli ' + mod.seq + ': </span>' + esc(mod.title) + '</span>'
          + '<span class="calg-mod-meta">' + esc(mod.hours) + ' · ' + plural(mod.topics.length, 'temë', 'tema') + (mod.when ? ' · ' + esc(mod.when) : '') + '</span></span>'
          + '<i class="bi bi-chevron-down calg-mod-chev" aria-hidden="true"></i></summary>'
          + '<ol class="calg-topics">' + mod.topics.map(function (t) {
              return '<li><span class="calg-topic-num" aria-hidden="true">' + t.seq + '.</span><span class="calg-topic-title">' + esc(t.title) + '</span>'
                + '<span class="calg-topic-hours">' + esc(t.hours) + '</span></li>';
            }).join('') + '</ol></details></li>';
      });
      h += '</ol></section>';
    }
    return h;
  }

  function loadDetail(id) {
    var mine = ++current.seq;
    fetch(CFG.endpoint + '?group=' + encodeURIComponent(String(id)), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        return r.json().catch(function () { return null; }).then(function (json) { return { status: r.status, json: json }; });
      })
      .then(function (res) {
        if (mine !== current.seq || current.id !== id) return;
        M.body.removeAttribute('aria-busy');
        if (res.json && res.json.ok) {
          paintHead(res.json.group);
          M.body.innerHTML = detailHtml(res.json.group);
          return;
        }
        if (res.status === 404) {
          M.open.hidden = true;
          M.course.hidden = true;
          M.body.innerHTML = emptyHtml('bi-calendar-x', 'Grupi nuk u gjet', 'Ndoshta u fshi. Kalendari tregon grupet që ekzistojnë tani.', '');
          return;
        }
        detailError(id, res.json && res.json.error, res.status);
      })
      .catch(function () {
        if (mine !== current.seq || current.id !== id) return;
        M.body.removeAttribute('aria-busy');
        detailError(id, null, 0);
      });
  }
  function detailError(id, message, status) {
    M.body.innerHTML = emptyHtml('bi-wifi-off', 'Detajet e grupit nuk u ngarkuan',
      message || 'Nuk mora përgjigje nga serveri. Kontrollo lidhjen dhe provo sërish.', retryAction(status, 'data-calg-retry'));
    var action = M.body.querySelector('.empty-actions > .btn');
    if (action.hasAttribute('data-calg-retry')) action.addEventListener('click', function () { paintLoading(); loadDetail(id); });
    action.focus();
  }

  /**
   * Hap dritaren e grupit. Me opts.history shtohet një hap në historik (?group=12), që
   * "Prapa" ta mbyllë; nga adresa ose nga "Prapa/Përpara" dritarja hapet pa hap të ri.
   */
  function openGroup(id, opener, opts) {
    if (!id || !window.bootstrap) return;
    opts = opts || {};
    current.id = id;
    current.fromHistory = false;
    current.pushed = !!opts.history;
    if (opts.history) { S.group = id; commit(true); }
    var ev = findEvent(id);
    paintHead(ev || { id: id });
    paintLoading();
    if (!modalEl.classList.contains('show')) modal().show(opener || undefined);
    loadDetail(id);
  }

  M.body.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-calg-expand]') : null;
    if (!btn) return;
    var all = Array.prototype.slice.call(M.body.querySelectorAll('.calg-module'));
    var open = btn.getAttribute('aria-expanded') !== 'true';
    all.forEach(function (d) { d.open = open; });
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.querySelector('span').textContent = open ? 'Mbyll të gjitha' : 'Hap të gjitha';
    btn.querySelector('i').className = 'bi ' + (open ? 'bi-arrows-collapse' : 'bi-arrows-expand');
  });
  /* Butoni "Hap/Mbyll të gjitha" ndjek modulet e hapura një nga një. */
  M.body.addEventListener('toggle', function () {
    var btn = M.body.querySelector('[data-calg-expand]');
    if (!btn) return;
    var all = Array.prototype.slice.call(M.body.querySelectorAll('.calg-module'));
    var open = all.length > 0 && all.every(function (d) { return d.open; });
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.querySelector('span').textContent = open ? 'Mbyll të gjitha' : 'Hap të gjitha';
    btn.querySelector('i').className = 'bi ' + (open ? 'bi-arrows-collapse' : 'bi-arrows-expand');
  }, true);

  modalEl.addEventListener('hidden.bs.modal', function () {
    var id = current.id;
    current.id = null;
    current.seq++;
    M.body.innerHTML = '';
    M.body.removeAttribute('aria-busy');
    /* Mbyllur me butonin ose Esc: adresa humb ?group. Nëse dritarja shtoi hap në
       historik, kthehemi një hap (pa rivizatuar); përndryshe adresa zëvendësohet. */
    if (!current.fromHistory) {
      if (current.pushed && window.history.state && window.history.state.group === id) {
        skipPop = true;
        S.group = null;
        window.history.back();
      } else {
        S.group = null;
        commit(false);
      }
    }
    current.fromHistory = false;
    current.pushed = false;
    /* app.js e kthen fokusin te rreshti që e hapi; kur ai mungon (lidhje e drejtpërdrejtë),
       fokusi shkon te grupi në kalendar ose te titulli i muajit. */
    setTimeout(function () {
      var active = document.activeElement;
      if (active && active !== document.body && !modalEl.contains(active)) return;
      var row = el.body.querySelector('[data-cal-group="' + id + '"]');
      if (row) rove(row); else if (el.jump) el.jump.focus();
    }, 60);
  });

  /* ------------------------------------------------------------ Nisja */
  /* Skripti ngarkohet me defer: faqja është gati. Adresa pastrohet (vetëm vlerat e
     vlefshme) dhe calendar.php?group=12 hap menjëherë dritaren e grupit. */
  commit(false);
  render();
  if (S.group) openGroup(S.group, null, { history: false });
})();
