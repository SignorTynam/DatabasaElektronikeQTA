/* ============================================================================
   THEMELI — Kalendari: zgjedhja e datës në një dialog
   ----------------------------------------------------------------------------
   Çdo fushë date (<input data-dmy>) dhe çdo qelizë date që redaktohet në
   tabelë (<span class="editable" contenteditable data-dmy>) hap të njëjtin
   kalendar, në vend të kalendarit të shfletuesit. Data mbetet dd.mm.vvvv.

   Hapet me klik mbi fushën, butonin e kalendarit ose qelizën, ose me Alt + ↓.
   Me tastierë fusha shkruhet si më parë (Tab brenda saj, pastaj shifrat).

   Brenda kalendarit:
     shigjetat: ditë / javë · PageUp, PageDown: muaj (me Shift: vit)
     Home, End: fillimi / fundi i javës · Enter, Hapësirë: zgjedh · Esc: mbyll
     shifrat e shtypura shkojnë te data në krye (p.sh. 05031990 + Enter)
     titulli i muajit hap muajt, pastaj vitet — për data të largëta.

   Atributet (të gjitha opsionale):
     data-dmy-min, data-dmy-max  "vvvv-mm-dd", "today" ose "#id" (vlera e një
                                 fushe tjetër, p.sh. mbarimi jo para fillimit)
     data-dmy-kind="birth"       datëlindje: deri sot, nis nga vitet, pa "Sot"
     data-dmy-title="…"          titulli i dialogut (përndryshe etiketa e fushës)
     data-dmy-required           pa butonin "Pastro"
     data-dmy-commit             fusha ruhet kur humb fokusin: zgjedhja ruhet menjëherë

   Pas zgjedhjes: fusha merr vlerën dhe ngjarjet "input" e "change"; qeliza
   merr fokusin, tekstin e ri dhe humb fokusin — faqja e ruan njësoj si kur
   data shkruhet me dorë. window.qtaDatePicker.open(el) e hap nga kodi.
   ========================================================================= */
(function () {
  'use strict';

  var MONTHS = ['janar', 'shkurt', 'mars', 'prill', 'maj', 'qershor', 'korrik', 'gusht', 'shtator', 'tetor', 'nëntor', 'dhjetor'];
  var MONTHS_SHORT = ['Jan', 'Shk', 'Mar', 'Pri', 'Maj', 'Qer', 'Kor', 'Gus', 'Sht', 'Tet', 'Nën', 'Dhj'];
  var DAYS = ['e hënë', 'e martë', 'e mërkurë', 'e enjte', 'e premte', 'e shtunë', 'e diel'];
  var DAYS_SHORT = ['Hë', 'Ma', 'Më', 'En', 'Pr', 'Sh', 'Di'];
  var PAGE = 12;              /* vite në një faqe */
  var MS_DAY = 86400000;
  var TARGETS = 'input[data-dmy], [data-dmy][contenteditable]';

  /* ------------------------------------------------------------ Datat (UTC) */
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function mk(y, m, d) { return new Date(Date.UTC(y, m, d)); }
  function today() { var n = new Date(); return mk(n.getFullYear(), n.getMonth(), n.getDate()); }
  function toDmy(dt) { return pad(dt.getUTCDate()) + '.' + pad(dt.getUTCMonth() + 1) + '.' + dt.getUTCFullYear(); }
  function toIso(dt) { return dt.getUTCFullYear() + '-' + pad(dt.getUTCMonth() + 1) + '-' + pad(dt.getUTCDate()); }
  function daysIn(y, m) { return mk(y, m + 1, 0).getUTCDate(); }
  function addDays(dt, n) { return new Date(dt.getTime() + n * MS_DAY); }
  function addMonths(dt, n) {
    var first = mk(dt.getUTCFullYear(), dt.getUTCMonth() + n, 1);
    var y = first.getUTCFullYear(), m = first.getUTCMonth();
    return mk(y, m, Math.min(dt.getUTCDate(), daysIn(y, m)));
  }
  function same(a, b) { return !!(a && b) && a.getTime() === b.getTime(); }
  function dow(dt) { return (dt.getUTCDay() + 6) % 7; }            /* 0 = e hënë */
  function mod(n, m) { return ((n % m) + m) % m; }
  function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }
  function words(dt) { return DAYS[dow(dt)] + ', ' + dt.getUTCDate() + ' ' + MONTHS[dt.getUTCMonth()] + ' ' + dt.getUTCFullYear(); }

  /* dd.mm.vvvv (edhe me - ose /) ose vvvv-mm-dd; data që s'ekziston → null */
  function parse(value) {
    var s = String(value == null ? '' : value).trim();
    var m = s.match(/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})$/);
    var y, mo, d;
    if (m) { d = +m[1]; mo = +m[2]; y = +m[3]; }
    else if ((m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/))) { y = +m[1]; mo = +m[2]; d = +m[3]; }
    else return null;
    if (y < 1900 || y > 2200 || mo < 1 || mo > 12 || d < 1 || d > daysIn(y, mo - 1)) return null;
    return mk(y, mo - 1, d);
  }

  function mask(value) {
    var digits = String(value || '').replace(/\D/g, '').slice(0, 8);
    var out = digits.slice(0, 2);
    if (digits.length > 2) out += '.' + digits.slice(2, 4);
    if (digits.length > 4) out += '.' + digits.slice(4, 8);
    return out;
  }

  /* ---------------------------------------------------------- Fusha/qeliza */
  function isField(el) { return el.tagName === 'INPUT'; }
  function readValue(el) { return isField(el) ? el.value : el.textContent; }
  function usable(el) {
    if (!el || !document.contains(el)) return false;
    return isField(el) ? !el.disabled && !el.readOnly : el.isContentEditable;
  }
  function isRequired(el) { return el.hasAttribute('data-dmy-required') || (isField(el) && el.required); }
  function bound(el, attr) {
    var raw = (el.getAttribute(attr) || '').trim();
    if (!raw) return null;
    if (raw === 'today') return today();
    if (raw.charAt(0) === '#') {
      var ref = document.getElementById(raw.slice(1));
      return ref ? parse(readValue(ref)) : null;
    }
    return parse(raw);
  }
  function clean(text) { return String(text || '').replace(/\s+/g, ' ').trim(); }
  function titleFor(el) {
    var own = el.getAttribute('data-dmy-title');
    if (own) return own;
    if (isField(el) && el.id) {
      var label = document.querySelector('label[for="' + (window.CSS && CSS.escape ? CSS.escape(el.id) : el.id) + '"]');
      if (label) {
        var copy = label.cloneNode(true);
        Array.prototype.forEach.call(copy.querySelectorAll('.req, .optional, .visually-hidden'), function (n) { n.remove(); });
        if (clean(copy.textContent)) return clean(copy.textContent);
      }
    }
    if (el.getAttribute('aria-label')) return el.getAttribute('aria-label');
    var cell = el.closest('td, th');
    var table = cell ? cell.closest('table') : null;
    var head = table && table.tHead && table.tHead.rows[0] ? table.tHead.rows[0].cells[cell.cellIndex] : null;
    return head && clean(head.textContent) ? clean(head.textContent) : 'Zgjidh datën';
  }
  function focusQuiet(el) {
    try { el.focus({ preventScroll: true }); } catch (e) { /* s'merr dot fokus */ }
  }
  function caretToEnd(el) {
    if (isField(el) || !window.getSelection) return;
    var range = document.createRange();
    range.selectNodeContents(el);
    range.collapse(false);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }

  /* ------------------------------------------------------------ Gjendja */
  var state = {
    target: null, modality: 'pointer', result: undefined, under: null,
    shown: false, typed: false, cancel: false,
    kind: '', min: null, max: null,
    selected: null,     /* data e zgjedhur (vlera e fushës ose e shkruar në krye) */
    focus: null,        /* dita aktive në kalendar */
    view: 'days',       /* days | months | years */
    viewYear: 0, yearsFrom: 0, cell: 0, openedAt: 0
  };
  var modalEl = null, modal = null, els = {};

  function inRange(dt) { return (!state.min || dt >= state.min) && (!state.max || dt <= state.max); }
  function clampDate(dt) {
    if (state.min && dt < state.min) return state.min;
    if (state.max && dt > state.max) return state.max;
    return dt;
  }
  function monthOpen(y, m) {
    return (!state.max || mk(y, m, 1) <= state.max) && (!state.min || mk(y, m, daysIn(y, m)) >= state.min);
  }
  function yearOpen(y) {
    return (!state.max || mk(y, 0, 1) <= state.max) && (!state.min || mk(y, 11, 31) >= state.min);
  }
  function rangeText() {
    if (state.min && state.max) return 'nga ' + toDmy(state.min) + ' deri më ' + toDmy(state.max);
    if (state.min) return 'nga ' + toDmy(state.min) + ' e tutje';
    if (state.max) return 'deri më ' + toDmy(state.max);
    return '';
  }

  /* ------------------------------------------------------------ Dialogu */
  function build() {
    if (modalEl) return;
    var wrap = document.createElement('div');
    wrap.innerHTML =
      '<div class="modal fade dp-modal" id="qtaDatePicker" tabindex="-1" aria-labelledby="dpTitle" aria-describedby="dpHelp">' +
        '<div class="modal-dialog modal-dialog-centered dp-dialog">' +
          '<div class="modal-content">' +
            '<div class="dp-head">' +
              '<div class="dp-head-main">' +
                '<h2 class="dp-title" id="dpTitle"></h2>' +
                '<input class="dp-entry" type="text" inputmode="numeric" autocomplete="off" spellcheck="false" maxlength="10"' +
                      ' placeholder="dd.mm.vvvv" aria-label="Data, e shkruar me shifra" aria-describedby="dpWords">' +
                '<p class="dp-words" id="dpWords" aria-live="polite"></p>' +
              '</div>' +
              '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll kalendarin"></button>' +
            '</div>' +
            '<div class="dp-body">' +
              '<div class="dp-nav">' +
                '<button type="button" class="btn btn-ghost btn-icon dp-step" data-dp-step="-1"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>' +
                '<button type="button" class="dp-period" data-dp-period><span id="dpPeriodText"></span><i class="bi bi-chevron-down" aria-hidden="true"></i></button>' +
                '<button type="button" class="btn btn-ghost btn-icon dp-step" data-dp-step="1"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>' +
              '</div>' +
              '<div class="dp-view" data-dp-view></div>' +
              '<p class="visually-hidden" id="dpHelp">Shigjetat lëvizin nëpër ditë, Page Up dhe Page Down nëpër muaj. Enter e zgjedh datën, Esc e mbyll kalendarin. Data mund të shkruhet edhe me shifra.</p>' +
              '<p class="visually-hidden" data-dp-live aria-live="polite"></p>' +
            '</div>' +
            '<div class="modal-footer dp-foot">' +
              '<div class="modal-footer-start">' +
                '<button type="button" class="btn btn-ghost" data-dp-today><i class="bi bi-calendar-check" aria-hidden="true"></i>Sot</button>' +
                '<button type="button" class="btn btn-ghost" data-dp-clear><i class="bi bi-eraser" aria-hidden="true"></i>Pastro</button>' +
              '</div>' +
              '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>';
    modalEl = wrap.firstChild;
    document.body.appendChild(modalEl);
    modal = new window.bootstrap.Modal(modalEl);

    els.title = modalEl.querySelector('.dp-title');
    els.entry = modalEl.querySelector('.dp-entry');
    els.words = modalEl.querySelector('.dp-words');
    els.prev = modalEl.querySelector('[data-dp-step="-1"]');
    els.next = modalEl.querySelector('[data-dp-step="1"]');
    els.period = modalEl.querySelector('[data-dp-period]');
    els.periodText = modalEl.querySelector('#dpPeriodText');
    els.view = modalEl.querySelector('[data-dp-view]');
    els.live = modalEl.querySelector('[data-dp-live]');
    els.today = modalEl.querySelector('[data-dp-today]');
    els.clear = modalEl.querySelector('[data-dp-clear]');

    els.prev.addEventListener('click', function () { step(-1); });
    els.next.addEventListener('click', function () { step(1); });
    els.period.addEventListener('click', function () {
      if (state.view === 'days') toMonths();
      else if (state.view === 'months') toYears();
    });
    els.view.addEventListener('click', onViewClick);
    els.view.addEventListener('keydown', onViewKey);
    modalEl.addEventListener('keydown', onDialogKey, true);
    els.entry.addEventListener('input', onEntryInput);
    els.entry.addEventListener('keydown', onEntryKey);
    els.today.addEventListener('click', function () { pick(today()); });
    els.clear.addEventListener('click', function () { state.result = ''; modal.hide(); });

    /* Klikimi i dytë i një dyfish-kliku mbi fushë nuk e mbyll kalendarin sapo hapet. */
    modalEl.addEventListener('mousedown', function (e) {
      if (e.target === modalEl && Date.now() - state.openedAt < 450) { e.stopImmediatePropagation(); e.preventDefault(); }
    }, true);
    modalEl.addEventListener('shown.bs.modal', function () {
      state.shown = true;
      if (state.cancel) { modal.hide(); return; }
      if (!state.typed) { focusActive(); return; }
      focusQuiet(els.entry);
      els.entry.setSelectionRange(els.entry.value.length, els.entry.value.length);
    });
    modalEl.addEventListener('hidden.bs.modal', onHidden);
  }

  function open(el, modality) {
    if (!usable(el) || !window.bootstrap || !window.bootstrap.Modal) return false;
    build();
    if (modalEl.classList.contains('show') || state.target) return false;

    var kind = el.getAttribute('data-dmy-kind') || '';
    state.target = el;
    state.modality = modality === 'keyboard' || modality === 'touch' ? modality : 'pointer';
    state.result = undefined;
    state.shown = false;
    state.typed = false;
    state.cancel = false;
    state.kind = kind;
    state.min = bound(el, 'data-dmy-min') || (kind === 'birth' ? mk(1900, 0, 1) : null);
    state.max = bound(el, 'data-dmy-max') || (kind === 'birth' ? today() : null);
    if (state.min && state.max && state.min > state.max) state.min = null;
    state.selected = parse(readValue(el));

    var fallback = kind === 'birth' ? mk(today().getUTCFullYear() - 30, 0, 1) : today();
    state.focus = state.selected || clampDate(fallback);
    state.view = !state.selected && kind === 'birth' ? 'years' : 'days';
    state.viewYear = state.focus.getUTCFullYear();
    state.yearsFrom = state.viewYear - mod(state.viewYear, PAGE);
    state.cell = state.view === 'years' ? state.viewYear - state.yearsFrom : state.focus.getUTCMonth();

    els.title.textContent = titleFor(el);
    els.entry.value = state.selected ? toDmy(state.selected) : '';
    els.today.hidden = kind === 'birth' || !inRange(today());
    els.clear.hidden = !state.selected || isRequired(el);
    paintWords('');
    render(false);

    /* Mbi një dialog tjetër (p.sh. grupi): del sipër tij, si konfirmimi. */
    state.under = document.querySelector('.modal.show');
    modalEl.classList.toggle('is-stacked', !!state.under);
    state.openedAt = Date.now();
    modal.show();
    if (state.under) {
      var drops = document.querySelectorAll('.modal-backdrop');
      if (drops.length) drops[drops.length - 1].classList.add('is-stacked');
    }
    return true;
  }

  /* byKeys: zgjedhja u bë me tastierë, prandaj fokusi kthehet te fusha/qeliza. */
  function pick(dt, byKeys) {
    if (!dt || !inRange(dt)) return;
    if (byKeys && state.modality !== 'touch') state.modality = 'keyboard';
    state.result = dt;
    modal.hide();
  }

  function onHidden() {
    var el = state.target, result = state.result, modality = state.modality, under = state.under;
    state.target = null;
    state.under = null;
    state.shown = false;
    modalEl.classList.remove('is-stacked');
    els.view.innerHTML = '';

    /* Dialogu poshtë mbetet i hapur: faqja e bllokuar dhe tastiera brenda tij. */
    if (under && under.classList.contains('show')) {
      document.body.classList.add('modal-open');
      var below = window.bootstrap.Modal.getInstance(under);
      if (below && below._focustrap) {
        try { below._focustrap.deactivate(); below._focustrap.activate(); } catch (e) { /* vazhdon pa kurth fokusi */ }
      }
    }
    if (!el || !document.contains(el)) return;

    /* Me prekje (celular) fokusi nuk kthehet te fusha: do të hapte tastierën pa nevojë. */
    var refocus = modality !== 'touch';
    if (result === undefined) {                 /* Anulo / Esc: fokusi kthehet te fusha */
      if (refocus && (isField(el) || modality === 'keyboard')) { focusQuiet(el); caretToEnd(el); }
      return;
    }
    var text = result === '' ? '' : toDmy(result);
    if (isField(el)) {
      el.value = text;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      if (el.hasAttribute('data-dmy-commit')) { focusQuiet(el); el.blur(); }
      if (refocus) focusQuiet(el);
      return;
    }
    /* Qeliza e tabelës: fokusi (faqja kujton vlerën e vjetër), teksti i ri,
       pastaj blur — faqja e ruan si të ishte shkruar me dorë. */
    focusQuiet(el);
    el.textContent = text;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.blur();
    if (modality === 'keyboard') { focusQuiet(el); caretToEnd(el); }
  }

  /* ------------------------------------------------------------ Vizatimi */
  function render(moveFocus) {
    var label, periodLabel, prevLabel, nextLabel, prevOk, nextOk, periodOn = true, html;
    if (state.view === 'days') {
      var y = state.focus.getUTCFullYear(), m = state.focus.getUTCMonth();
      var before = mk(y, m - 1, 1), after = mk(y, m + 1, 1);
      html = daysHtml(y, m);
      label = cap(MONTHS[m]) + ' ' + y;
      periodLabel = label + ' — zgjidh muajin ose vitin';
      prevLabel = 'Muaji i kaluar';
      nextLabel = 'Muaji tjetër';
      prevOk = monthOpen(before.getUTCFullYear(), before.getUTCMonth());
      nextOk = monthOpen(after.getUTCFullYear(), after.getUTCMonth());
    } else if (state.view === 'months') {
      html = monthsHtml(state.viewYear);
      label = String(state.viewYear);
      periodLabel = label + ' — zgjidh vitin';
      prevLabel = 'Viti i kaluar';
      nextLabel = 'Viti tjetër';
      prevOk = yearOpen(state.viewYear - 1);
      nextOk = yearOpen(state.viewYear + 1);
    } else {
      html = yearsHtml(state.yearsFrom);
      label = state.yearsFrom + ' – ' + (state.yearsFrom + PAGE - 1);
      periodLabel = 'Vitet ' + label;
      periodOn = false;
      prevLabel = 'Vitet e mëparshme';
      nextLabel = 'Vitet në vijim';
      prevOk = yearOpen(state.yearsFrom - 1);
      nextOk = yearOpen(state.yearsFrom + PAGE);
    }
    var hadFocus = els.view.contains(document.activeElement);
    els.view.innerHTML = html;
    els.view.setAttribute('data-view', state.view);
    if (els.periodText.textContent !== label) els.live.textContent = label;
    els.periodText.textContent = label;
    els.period.setAttribute('aria-label', periodLabel);
    els.period.setAttribute('aria-disabled', periodOn ? 'false' : 'true');
    [[els.prev, prevLabel, prevOk], [els.next, nextLabel, nextOk]].forEach(function (b) {
      b[0].setAttribute('aria-label', b[1]);
      b[0].title = b[1];
      if (!b[2] && document.activeElement === b[0]) moveFocus = true;
      b[0].disabled = !b[2];
    });
    if (moveFocus || hadFocus) focusActive();
  }

  function daysHtml(y, m) {
    var first = mk(y, m, 1), start = addDays(first, -dow(first)), now = today();
    var h = '<table class="dp-grid" role="grid" aria-labelledby="dpPeriodText"><thead><tr>';
    for (var i = 0; i < 7; i++) h += '<th scope="col" abbr="' + DAYS[i] + '">' + DAYS_SHORT[i] + '</th>';
    h += '</tr></thead><tbody>';
    for (var w = 0; w < 6; w++) {
      h += '<tr>';
      for (var k = 0; k < 7; k++) {
        var dt = addDays(start, w * 7 + k);
        var isToday = same(dt, now), isSel = same(dt, state.selected);
        var cls = 'dp-day' + (dt.getUTCMonth() !== m ? ' is-out' : '') + (isToday ? ' is-today' : '') + (isSel ? ' is-selected' : '');
        h += '<td role="gridcell" class="' + cls + '" data-iso="' + toIso(dt) + '" tabindex="' + (same(dt, state.focus) ? '0' : '-1') + '"' +
          ' aria-selected="' + (isSel ? 'true' : 'false') + '"' + (inRange(dt) ? '' : ' aria-disabled="true"') +
          (isToday ? ' aria-current="date"' : '') + ' aria-label="' + words(dt) + (isToday ? ', sot' : '') + '">' +
          '<span>' + dt.getUTCDate() + '</span></td>';
      }
      h += '</tr>';
    }
    return h + '</tbody></table>';
  }

  function cellsHtml(label, items) {
    var h = '<div class="dp-cells" role="group" aria-label="' + label + '">';
    items.forEach(function (it, i) {
      h += '<button type="button" class="dp-cell' + (it.today ? ' is-today' : '') + (it.selected ? ' is-selected' : '') + '" ' + it.attr +
        ' tabindex="' + (i === state.cell ? '0' : '-1') + '"' + (it.open ? '' : ' aria-disabled="true"') +
        (it.selected ? ' aria-current="true"' : '') + ' aria-label="' + it.name + '">' + it.text + '</button>';
    });
    return h + '</div>';
  }

  function monthsHtml(y) {
    var now = today(), sel = state.selected, items = [];
    for (var i = 0; i < 12; i++) {
      items.push({
        attr: 'data-dp-month="' + i + '"', text: MONTHS_SHORT[i], name: cap(MONTHS[i]) + ' ' + y, open: monthOpen(y, i),
        today: now.getUTCFullYear() === y && now.getUTCMonth() === i,
        selected: !!sel && sel.getUTCFullYear() === y && sel.getUTCMonth() === i
      });
    }
    return cellsHtml('Muajt e vitit ' + y, items);
  }

  function yearsHtml(from) {
    var now = today(), sel = state.selected, items = [];
    for (var i = 0; i < PAGE; i++) {
      var y = from + i;
      items.push({
        attr: 'data-dp-year="' + y + '"', text: String(y), name: 'Viti ' + y, open: yearOpen(y),
        today: now.getUTCFullYear() === y, selected: !!sel && sel.getUTCFullYear() === y
      });
    }
    return cellsHtml('Vitet ' + from + ' – ' + (from + PAGE - 1), items);
  }

  function focusActive() {
    var active = els.view.querySelector('[tabindex="0"]');
    if (active) focusQuiet(active);
  }

  function paintWords(problem) {
    var text, bad = false;
    if (problem === 'invalid') {
      text = 'Kjo datë nuk ekziston. Kontrollo ditën dhe muajin.';
      bad = true;
    } else if (problem === 'range') {
      text = 'Zgjidh një datë ' + rangeText() + '.';
      bad = true;
    } else if (problem === 'empty') {
      text = 'Kjo datë duhet plotësuar.';
      bad = true;
    } else if (problem === 'typing') {
      text = 'Shkruaje si dd.mm.vvvv';
    } else if (state.selected) {
      text = cap(words(state.selected)) + (inRange(state.selected) ? '' : ' · jashtë datave të lejuara');
      bad = !inRange(state.selected);
    } else if (state.kind === 'birth') {
      text = 'Zgjidh vitin, pastaj muajin dhe ditën.';
    } else {
      text = rangeText() ? 'Datat e lejuara: ' + rangeText() + '.' : 'Zgjidh një ditë ose shkruaje me shifra.';
    }
    els.words.textContent = text;
    els.words.classList.toggle('is-error', bad);
  }

  /* ------------------------------------------------------------ Lëvizja */
  function toMonths() {
    state.view = 'months';
    state.viewYear = state.focus.getUTCFullYear();
    state.cell = state.focus.getUTCMonth();
    render(true);
  }
  function toYears() {
    state.view = 'years';
    state.yearsFrom = state.viewYear - mod(state.viewYear, PAGE);
    state.cell = state.viewYear - state.yearsFrom;
    render(true);
  }
  function chooseMonth(i) {
    var y = state.viewYear;
    state.focus = clampDate(mk(y, i, Math.min(state.focus.getUTCDate(), daysIn(y, i))));
    state.view = 'days';
    render(true);
  }
  function chooseYear(y) {
    var m = state.focus.getUTCMonth();
    state.focus = clampDate(mk(y, m, Math.min(state.focus.getUTCDate(), daysIn(y, m))));
    state.viewYear = y;
    state.view = 'months';
    state.cell = state.focus.getUTCFullYear() === y ? state.focus.getUTCMonth() : 0;
    render(true);
  }
  function step(dir, cell) {
    if (state.view === 'days') state.focus = clampDate(addMonths(state.focus, dir));
    else if (state.view === 'months') state.viewYear += dir;
    else state.yearsFrom += dir * PAGE;
    if (cell != null) state.cell = cell;
    render(cell != null);
  }
  function moveDay(dt) {
    dt = clampDate(dt);
    if (same(dt, state.focus)) return;
    var sameMonth = dt.getUTCFullYear() === state.focus.getUTCFullYear() && dt.getUTCMonth() === state.focus.getUTCMonth();
    state.focus = dt;
    if (!sameMonth) { render(true); return; }
    var old = els.view.querySelector('[tabindex="0"]');
    var next = els.view.querySelector('[data-iso="' + toIso(dt) + '"]');
    if (old) old.setAttribute('tabindex', '-1');
    if (next) { next.setAttribute('tabindex', '0'); focusQuiet(next); }
  }

  function onViewClick(e) {
    var node = e.target.closest ? e.target.closest('[data-iso], [data-dp-month], [data-dp-year]') : null;
    if (!node || node.getAttribute('aria-disabled') === 'true') return;
    if (node.hasAttribute('data-iso')) pick(parse(node.getAttribute('data-iso')));
    else if (node.hasAttribute('data-dp-month')) chooseMonth(+node.getAttribute('data-dp-month'));
    else chooseYear(+node.getAttribute('data-dp-year'));
  }

  /* Shifrat e shtypura kudo në dialog shkruajnë datën në krye. Edhe ato të
     shtypura sa dialogu po hapet (fokusi është ende te fusha e faqes). */
  function typeDigit(digit) {
    els.entry.value = mask((state.typed ? els.entry.value : '') + digit);
    state.typed = true;
    onEntryInput();
  }
  function onDialogKey(e) {
    if (e.altKey || e.ctrlKey || e.metaKey || e.target === els.entry || !/^\d$/.test(e.key)) return;
    e.preventDefault();
    state.typed = false;
    typeDigit(e.key);
    focusQuiet(els.entry);
    els.entry.setSelectionRange(els.entry.value.length, els.entry.value.length);
  }
  document.addEventListener('keydown', function (e) {
    if (!state.target || state.shown || e.altKey || e.ctrlKey || e.metaKey) return;
    var digit = /^\d$/.test(e.key);
    if (!digit && e.key !== 'Enter' && e.key !== 'Escape') return;
    /* Asgjë nuk shkon te faqja poshtë: as shifrat, as Enter (që do ta dërgonte formularin). */
    e.preventDefault();
    e.stopPropagation();
    if (digit) typeDigit(e.key);
    else if (e.key === 'Escape') state.cancel = true;
  }, true);

  function onViewKey(e) {
    if (e.altKey || e.ctrlKey || e.metaKey || e.defaultPrevented) return;
    if (state.view === 'days') daysKey(e); else cellsKey(e);
  }

  function daysKey(e) {
    var f = state.focus, n;
    switch (e.key) {
      case 'ArrowLeft': n = addDays(f, -1); break;
      case 'ArrowRight': n = addDays(f, 1); break;
      case 'ArrowUp': n = addDays(f, -7); break;
      case 'ArrowDown': n = addDays(f, 7); break;
      case 'Home': n = addDays(f, -dow(f)); break;
      case 'End': n = addDays(f, 6 - dow(f)); break;
      case 'PageUp': n = addMonths(f, e.shiftKey ? -12 : -1); break;
      case 'PageDown': n = addMonths(f, e.shiftKey ? 12 : 1); break;
      case 'Enter':
      case ' ':
        e.preventDefault();
        if (inRange(f)) pick(f, true);
        return;
      default: return;
    }
    e.preventDefault();
    moveDay(n);
  }

  function cellsKey(e) {
    var cells = Array.prototype.slice.call(els.view.querySelectorAll('.dp-cell'));
    var i = cells.indexOf(document.activeElement), j;
    if (i < 0) i = state.cell;
    switch (e.key) {
      case 'ArrowLeft': j = i - 1; break;
      case 'ArrowRight': j = i + 1; break;
      case 'ArrowUp': j = i - 3; break;
      case 'ArrowDown': j = i + 3; break;
      case 'Home': j = 0; break;
      case 'End': j = cells.length - 1; break;
      case 'PageUp': e.preventDefault(); step(-1, i); return;
      case 'PageDown': e.preventDefault(); step(1, i); return;
      case 'Enter':
      case ' ':
        e.preventDefault();
        if (cells[i]) cells[i].click();
        return;
      default: return;
    }
    e.preventDefault();
    if (j < 0 || j >= cells.length) { step(j < 0 ? -1 : 1, mod(j, cells.length)); return; }
    state.cell = j;
    cells[i].setAttribute('tabindex', '-1');
    cells[j].setAttribute('tabindex', '0');
    focusQuiet(cells[j]);
  }

  /* Data e shkruar në krye: kalendari e ndjek; Enter e zgjedh. */
  function onEntryInput() {
    var v = mask(els.entry.value);
    if (els.entry.value !== v) els.entry.value = v;
    if (v === '') { state.selected = null; paintWords(''); render(false); return; }
    if (v.length < 10) { paintWords('typing'); return; }
    var dt = parse(v);
    if (!dt) { paintWords('invalid'); return; }
    state.selected = dt;
    state.focus = dt;
    state.view = 'days';
    paintWords(inRange(dt) ? '' : 'range');
    render(false);
  }

  function onEntryKey(e) {
    if (e.key === 'ArrowDown' && !e.altKey) { e.preventDefault(); focusActive(); return; }
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var v = els.entry.value.trim();
    if (v === '') {
      if (isRequired(state.target)) { paintWords('empty'); return; }
      if (state.modality !== 'touch') state.modality = 'keyboard';
      state.result = '';
      modal.hide();
      return;
    }
    var dt = parse(v);
    if (!dt) { paintWords(v.length < 10 ? 'typing' : 'invalid'); return; }
    if (!inRange(dt)) { paintWords('range'); return; }
    pick(dt, true);
  }

  /* ------------------------------------------------------ Fushat e faqes */
  function enhance(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('input[data-dmy]:not([data-dmy-ready])'), function (input) {
      input.setAttribute('data-dmy-ready', '');
      input.setAttribute('aria-haspopup', 'dialog');
      input.setAttribute('aria-keyshortcuts', 'Alt+ArrowDown');
      input.setAttribute('autocomplete', 'off');
      if (!input.getAttribute('placeholder')) input.setAttribute('placeholder', 'dd.mm.vvvv');
      var wrap = document.createElement('span');
      wrap.className = 'date-field' + (input.classList.contains('w-auto') ? ' is-auto' : '') + (input.classList.contains('form-control-sm') ? ' is-sm' : '');
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'date-field-btn';
      btn.title = 'Hap kalendarin';
      btn.setAttribute('aria-label', 'Hap kalendarin: ' + titleFor(input));
      btn.innerHTML = '<i class="bi bi-calendar3" aria-hidden="true"></i>';
      wrap.appendChild(btn);
    });
    Array.prototype.forEach.call((root || document).querySelectorAll('[data-dmy][contenteditable="true"]:not([data-dmy-ready])'), function (cell) {
      cell.setAttribute('data-dmy-ready', '');
      cell.setAttribute('aria-haspopup', 'dialog');
      cell.setAttribute('aria-keyshortcuts', 'Alt+ArrowDown');
      var icon = document.createElement('i');
      icon.className = 'bi bi-calendar3 dp-cell-icon';
      icon.setAttribute('aria-hidden', 'true');
      cell.insertAdjacentElement('afterend', icon);
    });
  }

  function targetOf(node) {
    if (!node || !node.closest) return null;
    var el = node.closest(TARGETS);
    if (!el) {
      var icon = node.closest('.dp-cell-icon');
      el = icon && icon.previousElementSibling && icon.previousElementSibling.matches(TARGETS) ? icon.previousElementSibling : null;
    }
    return el && usable(el) ? el : null;
  }

  /* Klik mbi fushë ose qelizë: kalendari (pa kursor dhe pa tastierën e celularit). */
  var lastPointer = '';
  document.addEventListener('pointerdown', function (e) { lastPointer = e.pointerType || ''; }, true);
  function modalityOf(e) {
    if (e.detail === 0) return 'keyboard';
    var type = e.pointerType || lastPointer;
    return type === 'touch' || type === 'pen' ? 'touch' : 'pointer';
  }
  document.addEventListener('mousedown', function (e) {
    if (e.button === 0 && targetOf(e.target)) e.preventDefault();
  });
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.date-field-btn') : null;
    if (btn) {
      var input = btn.parentNode ? btn.parentNode.querySelector('input[data-dmy]') : null;
      if (input && usable(input)) { e.preventDefault(); open(input, modalityOf(e)); }
      return;
    }
    var el = targetOf(e.target);
    if (el) { e.preventDefault(); open(el, modalityOf(e) === 'touch' ? 'touch' : 'pointer'); }
  });
  document.addEventListener('keydown', function (e) {
    if (!e.altKey || (e.key !== 'ArrowDown' && e.key !== 'Down')) return;
    var el = targetOf(e.target);
    if (el) { e.preventDefault(); open(el, 'keyboard'); }
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { enhance(); });
  else enhance();

  window.qtaDatePicker = {
    open: function (el) { return open(el, 'keyboard'); },
    enhance: enhance,
    parse: function (value) { var dt = parse(value); return dt ? toIso(dt) : null; }
  };
})();
