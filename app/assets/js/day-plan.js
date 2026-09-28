/* ============================================================================
   day-plan.js — Kalendari i orëve të një periudhe historike (QtaDayPlan).

   E përdorin konvertimi i një grupi (group-conversion.js) dhe korrigjimi i një
   grupi të konvertuar (lesson-group.js). Kalendari vizatohet nga serveri
   (partials/day_plan.php); këtu shtohet redaktimi:
     - klik ose Enter/Hapësirë mbi një datë hap zgjedhjen e orëve;
     - shifrat 0–8 i vendosin orët menjëherë, Delete e bën "pa mësim";
     - shigjetat lëvizin ditë/javë, PageUp/PageDown muaj, Home/End te fillimi
       dhe mbarimi i periudhës; Ctrl+Z zhbën ndryshimin e fundit;
     - fillimi dhe mbarimi historik nuk bëhen "pa mësim";
     - pas çdo ndryshimi: ngjarja 'dplan:change' me datat që ndryshuan.

   Rregullat e plota i kontrollon vetëm serveri; këtu është ndihma e çastit.

   API: QtaDayPlan.mount(root) → { plan(), serialize(), apply(changes, opts),
        undo(), canUndo(), focus(date), announce(text), dates, start, end, max }
   ========================================================================= */
(function () {
  'use strict';

  var WEEKDAYS = ['e diel', 'e hënë', 'e martë', 'e mërkurë', 'e enjte', 'e premte', 'e shtunë'];

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function parse(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
    return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])) : null;
  }
  function toIso(d) { return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()); }
  function dmy(iso) { var p = String(iso).split('-'); return p[2] + '.' + p[1] + '.' + p[0]; }
  function addDays(iso, n) { var d = parse(iso); d.setUTCDate(d.getUTCDate() + n); return toIso(d); }
  function addMonths(iso, n) {
    var d = parse(iso);
    var day = d.getUTCDate();
    d.setUTCDate(1);
    d.setUTCMonth(d.getUTCMonth() + n);
    var last = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0)).getUTCDate();
    d.setUTCDate(Math.min(day, last));
    return toIso(d);
  }
  function weekday(iso) { return parse(iso).getUTCDay(); }
  function dayLabel(iso) { return WEEKDAYS[weekday(iso)] + ', ' + dmy(iso); }
  function hoursLabel(h) { return h > 0 ? h + ' orë' : 'pa mësim'; }
  function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

  function mount(root) {
    var start = root.getAttribute('data-start');
    var end = root.getAttribute('data-end');
    var MAX = parseInt(root.getAttribute('data-max'), 10) || 8;
    var editable = root.classList.contains('is-editable');
    var byDate = {};
    var order = [];
    var state = {};
    Array.prototype.forEach.call(root.querySelectorAll('[data-date]'), function (c) {
      var d = c.getAttribute('data-date');
      byDate[d] = c;
      order.push(d);
      state[d] = { h: parseInt(c.getAttribute('data-hours'), 10) || 0, manual: c.getAttribute('data-manual') === '1', note: c.getAttribute('data-note') || '' };
    });
    var history = [];
    var live = root.querySelector('[data-dplan-live]');
    var editor = root.querySelector('[data-dplan-editor]');
    var options = editor ? Array.prototype.slice.call(editor.querySelectorAll('.dplan-opt')) : [];
    var noteInput = editor ? editor.querySelector('[data-ed-note]') : null;
    var openFor = null;

    function isBound(d) { return d === start || d === end; }
    function sundayLesson(d) { return state[d].h > 0 && !isBound(d) && weekday(d) === 0; }

    function announce(text) {
      if (!live) return;
      live.textContent = '';
      window.requestAnimationFrame(function () { live.textContent = text; });
    }

    function label(d) {
      var s = state[d];
      var parts = [dayLabel(d) + ': ' + hoursLabel(s.h)];
      if (d === start) parts.push('data historike e fillimit');
      if (d === end) parts.push('data historike e mbarimit');
      if (sundayLesson(d)) parts.push('e diel me mësim, kontrolloje');
      if (s.manual) parts.push('ndryshuar me dorë');
      if (s.note) parts.push('shënim: ' + s.note);
      return parts.join('. ');
    }

    function render(d) {
      var c = byDate[d];
      var s = state[d];
      c.setAttribute('data-hours', String(s.h));
      c.classList.toggle('is-on', s.h > 0);
      c.classList.toggle('is-off', s.h === 0);
      c.classList.toggle('is-warn', sundayLesson(d));
      c.classList.toggle('is-manual', s.manual);
      c.classList.toggle('has-note', !!s.note);
      var val = c.querySelector('.dplan-val');
      if (val) val.innerHTML = s.h > 0 ? s.h + '<span class="dplan-unit"> orë</span>' : '—';
      var marks = c.querySelector('.dplan-marks');
      if (marks) {
        marks.innerHTML = (isBound(d) ? '<i class="bi bi-lock-fill"></i>' : '')
          + (sundayLesson(d) ? '<i class="bi bi-exclamation-triangle-fill"></i>' : '')
          + (s.note ? '<i class="bi bi-chat-left-text"></i>' : '');
      }
      if (editable) c.setAttribute('aria-label', label(d));
    }

    function flash(c) {
      c.classList.remove('is-flash');
      void c.offsetWidth;
      c.classList.add('is-flash');
    }

    function emit(changed, source) {
      root.dispatchEvent(new CustomEvent('dplan:change', { bubbles: true, detail: { changed: changed, source: source } }));
    }

    /**
     * Zbaton ndryshime: { 'yyyy-mm-dd': { h?, note? } }.
     * opts.origin: 'manual' (dora e përdoruesit), 'auto' (sistemi), ose pa të (mban shenjën).
     * Kufijtë historikë nuk bien nën 1 orë; asnjë ditë nuk kalon maksimumin.
     */
    function apply(changes, opts) {
      opts = opts || {};
      var before = {};
      var changed = [];
      Object.keys(changes).forEach(function (d) {
        if (!state[d]) return;
        var s = state[d];
        var ch = changes[d] || {};
        var h = ch.h == null ? s.h : Math.max(0, Math.min(MAX, parseInt(ch.h, 10) || 0));
        if (isBound(d) && h < 1) h = s.h;
        var note = ch.note == null ? s.note : String(ch.note);
        var manual = opts.origin === 'manual' ? (h !== s.h ? true : s.manual) : (opts.origin === 'auto' ? false : s.manual);
        if (h === s.h && note === s.note && manual === s.manual) return;
        before[d] = { h: s.h, note: s.note, manual: s.manual };
        state[d] = { h: h, note: note, manual: manual };
        changed.push(d);
        render(d);
        if (opts.flash !== false) flash(byDate[d]);
      });
      if (!changed.length) return [];
      if (opts.record !== false) {
        history.push(before);
        if (history.length > 200) history.shift();
      }
      emit(changed, opts.source || 'user');
      return changed;
    }

    function undo() {
      var last = history.pop();
      if (!last) return false;
      var changed = Object.keys(last);
      changed.forEach(function (d) { state[d] = last[d]; render(d); flash(byDate[d]); });
      emit(changed, 'undo');
      announce(changed.length === 1 ? 'U zhbë: ' + dayLabel(changed[0]) + ', ' + hoursLabel(state[changed[0]].h) + '.' : 'U zhbënë ndryshimet e ' + changed.length + ' datave.');
      return true;
    }

    function setHours(d, h) {
      if (h > MAX) {
        announce('Një ditë mund të ketë maksimumi ' + MAX + ' orë.');
        return;
      }
      if (isBound(d) && h === 0) {
        announce((d === start ? 'Fillimi' : 'Mbarimi') + ' historik (' + dmy(d) + ') duhet të ketë mësim.');
        byDate[d].classList.remove('is-refused');
        void byDate[d].offsetWidth;
        byDate[d].classList.add('is-refused');
        return;
      }
      var ch = {};
      ch[d] = { h: h };
      if (apply(ch, { origin: 'manual' }).length) announce(cap(dayLabel(d)) + ': ' + hoursLabel(h) + '.');
    }

    /* ------------------------------------------------- Fokusi dhe tastiera */
    function focusDate(d) {
      var cell = byDate[d];
      if (!cell) return;
      var current = root.querySelector('[data-date][tabindex="0"]');
      if (current && current !== cell) current.setAttribute('tabindex', '-1');
      cell.setAttribute('tabindex', '0');
      cell.focus();
    }
    function clamp(d) { return d < start ? start : (d > end ? end : d); }

    if (editable) {
      root.addEventListener('keydown', function (e) {
        var cell = e.target.closest ? e.target.closest('[data-date]') : null;
        if (!cell || !root.contains(cell)) return;
        var d = cell.getAttribute('data-date');
        var next = null;
        if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z')) { e.preventDefault(); undo(); return; }
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        switch (e.key) {
          case 'ArrowLeft': next = addDays(d, -1); break;
          case 'ArrowRight': next = addDays(d, 1); break;
          case 'ArrowUp': next = addDays(d, -7); break;
          case 'ArrowDown': next = addDays(d, 7); break;
          case 'PageUp': next = addMonths(d, -1); break;
          case 'PageDown': next = addMonths(d, 1); break;
          case 'Home': next = start; break;
          case 'End': next = end; break;
          case 'Enter': case ' ': e.preventDefault(); openEditor(d); return;
          case 'Delete': case 'Backspace': e.preventDefault(); setHours(d, 0); return;
          default:
            if (/^[0-9]$/.test(e.key)) { e.preventDefault(); setHours(d, parseInt(e.key, 10)); }
            return;
        }
        e.preventDefault();
        focusDate(clamp(next));
      });
      root.addEventListener('click', function (e) {
        var cell = e.target.closest ? e.target.closest('[data-date]') : null;
        if (!cell || !root.contains(cell)) return;
        var d = cell.getAttribute('data-date');
        focusDate(d);
        openEditor(d);
      });
    }

    /* ------------------------------------------------- Zgjedhja e orëve */
    function position(cell) {
      if (window.matchMedia('(max-width: 575.98px)').matches) {
        editor.style.left = '';
        editor.style.top = '';
        editor.classList.add('is-sheet');
        return;
      }
      editor.classList.remove('is-sheet');
      var r = cell.getBoundingClientRect();
      var box = root.getBoundingClientRect();
      var w = editor.offsetWidth;
      var h = editor.offsetHeight;
      var left = Math.max(0, Math.min(r.left - box.left + r.width / 2 - w / 2, box.width - w));
      var top = r.bottom - box.top + 6;
      if (r.bottom + h + 12 > window.innerHeight && r.top - h - 6 > 0) top = r.top - box.top - h - 6;
      editor.style.left = Math.round(left) + 'px';
      editor.style.top = Math.round(top) + 'px';
    }

    function paintOptions(d) {
      var s = state[d];
      options.forEach(function (b) {
        var h = parseInt(b.getAttribute('data-h'), 10);
        var on = h === s.h;
        b.setAttribute('aria-checked', on ? 'true' : 'false');
        b.tabIndex = on ? 0 : -1;
        b.disabled = h === 0 && isBound(d);
      });
    }

    function openEditor(d) {
      if (!editor) return;
      if (openFor && openFor !== d) closeEditor(false);
      openFor = d;
      editor.querySelector('[data-ed-title]').textContent = cap(dayLabel(d));
      var hint = editor.querySelector('[data-ed-hint]');
      var text = d === start ? 'Fillimi historik i grupit: kjo ditë ka gjithmonë mësim.'
        : d === end ? 'Mbarimi historik i grupit: kjo ditë ka gjithmonë mësim.'
        : weekday(d) === 0 ? 'E diel: vendos orë vetëm nëse mësimi u zhvillua vërtet këtë ditë.' : '';
      hint.textContent = text;
      hint.hidden = !text;
      paintOptions(d);
      noteInput.value = state[d].note;
      byDate[d].classList.add('is-selected');
      byDate[d].setAttribute('aria-expanded', 'true');
      editor.hidden = false;
      position(byDate[d]);
      (editor.querySelector('.dplan-opt[aria-checked="true"]') || options[1]).focus();
    }

    function commitNote() {
      if (!openFor || !noteInput) return;
      var v = noteInput.value.replace(/\s+/g, ' ').trim().slice(0, 160);
      if (v === state[openFor].note) return;
      var ch = {};
      ch[openFor] = { note: v };
      apply(ch, {});
    }

    function closeEditor(returnFocus) {
      if (!editor || editor.hidden) return;
      commitNote();
      editor.hidden = true;
      var d = openFor;
      openFor = null;
      if (d) {
        byDate[d].classList.remove('is-selected');
        byDate[d].removeAttribute('aria-expanded');
        if (returnFocus !== false) byDate[d].focus();
      }
    }

    function choose(h) {
      if (!openFor) return;
      var d = openFor;
      commitNote();
      if (h !== state[d].h) setHours(d, h);
      closeEditor(true);
    }

    if (editor && editable) {
      options.forEach(function (b) {
        b.addEventListener('click', function () { if (!b.disabled) choose(parseInt(b.getAttribute('data-h'), 10)); });
      });
      editor.querySelector('[data-ed-close]').addEventListener('click', function () { closeEditor(true); });
      editor.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeEditor(true); return; }
        if (e.target === noteInput) {
          if (e.key === 'Enter') { e.preventDefault(); closeEditor(true); }
          return;
        }
        var enabled = options.filter(function (b) { return !b.disabled; });
        var i = enabled.indexOf(document.activeElement);
        if (i >= 0 && ['ArrowLeft', 'ArrowUp', 'ArrowRight', 'ArrowDown', 'Home', 'End'].indexOf(e.key) >= 0) {
          e.preventDefault();
          var j = e.key === 'Home' ? 0 : e.key === 'End' ? enabled.length - 1
            : (i + (e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 1) + enabled.length) % enabled.length;
          enabled.forEach(function (b) { b.tabIndex = -1; });
          enabled[j].tabIndex = 0;
          enabled[j].focus();
          return;
        }
        if (/^[0-9]$/.test(e.key)) {
          e.preventDefault();
          var h = parseInt(e.key, 10);
          if (h <= MAX && !(h === 0 && isBound(openFor))) choose(h);
          return;
        }
        if (e.key === 'Tab') {
          /* Tabi mbetet brenda dritares së vogël, si te çdo dialog. */
          var focusables = [editor.querySelector('.dplan-opt[tabindex="0"]'), editor.querySelector('[data-ed-close]'), noteInput].filter(Boolean);
          var k = focusables.indexOf(document.activeElement);
          e.preventDefault();
          focusables[(k + (e.shiftKey ? -1 : 1) + focusables.length) % focusables.length].focus();
        }
      });
      document.addEventListener('mousedown', function (e) {
        if (!openFor || editor.contains(e.target)) return;
        var cell = e.target.closest ? e.target.closest('[data-date]') : null;
        if (cell && cell.getAttribute('data-date') === openFor) return;
        closeEditor(false);
      });
      window.addEventListener('resize', function () { if (openFor) position(byDate[openFor]); });
    }

    return {
      root: root,
      start: start,
      end: end,
      max: MAX,
      dates: order.slice(),
      plan: function () {
        var out = {};
        order.forEach(function (d) { out[d] = state[d].h; });
        return out;
      },
      serialize: function () {
        return order.map(function (d) {
          var s = state[d];
          var item = { d: d, h: s.h };
          if (s.manual) item.m = 1;
          if (s.note) item.n = s.note;
          return item;
        });
      },
      apply: apply,
      undo: undo,
      canUndo: function () { return history.length > 0; },
      focus: function (d) { focusDate(d || start); },
      announce: announce,
      close: function () { closeEditor(false); }
    };
  }

  window.QtaDayPlan = { mount: mount, dmy: dmy, dayLabel: dayLabel, weekday: weekday };
})();
