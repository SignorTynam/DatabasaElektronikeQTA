/* ============================================================================
   curriculum.js — Struktura e kursit (course.php): modulet dhe temat me radhë.
   Çdo ndryshim ruhet në server (course_structure_update.php), i cili kontrollon
   rregullat dhe kthen pjesën e rivizatuar. Këtu vetëm dërgohet, rivizatohet dhe
   ruhet fokusi, që puna me tastierë të vazhdojë aty ku ishte.
   ========================================================================= */
(function () {
  'use strict';

  var cfgEl = document.getElementById('curConfig');
  var root = document.getElementById('courseStructure');
  if (!cfgEl || !root) return;
  var CFG = JSON.parse(cfgEl.textContent || '{}');
  var announcer = document.getElementById('curAnnounce');
  var pendingFocus = null;
  /* Të dhënat e fundit të kursit (pas çdo ruajtjeje), për dialogun "Ndrysho kursin". */
  var current = CFG.courseData || null;

  function toast(message, variant, opts) {
    if (window.qtaToast) window.qtaToast(message, variant || 'success', null, opts || {});
  }
  function announce(message) {
    if (!announcer) return;
    announcer.textContent = '';
    setTimeout(function () { announcer.textContent = message; }, 40);
  }

  function send(payload) {
    return fetch(CFG.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf: CFG.csrf, course_id: CFG.course }, payload))
    }).then(function (res) {
      return res.json().catch(function () { return null; });
    }).then(function (json) {
      if (!json) throw new Error('Nuk mora përgjigje nga serveri. Kontrollo lidhjen dhe provo sërish.');
      if (!json.ok) {
        var err = new Error(json.error || 'Ndryshimi nuk u ruajt.');
        err.data = json;
        throw err;
      }
      return json;
    });
  }

  function resubmit(form) {
    if (form.requestSubmit) form.requestSubmit();
    else form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  }

  /* Orët e temave kalojnë modulin (ose modulet kursin, ose e tëra ulet nën
     pjesët): serveri e refuzon dhe këtu del një dialog që shpjegon çfarë nuk
     shkon. Kur ka një vlerë të vlefshme, "Vendos 10 orë" e vendos dhe ruan. */
  function hoursProblem(err, input, retry) {
    var d = err && err.data && err.data.code === 'hours_limit' ? err.data.dialog : null;
    if (!d || !window.qtaConfirm) return false;
    var fix = d.fix && input && retry ? d.fix : null;
    if (input && document.contains(input)) input.focus();
    window.qtaConfirm({
      title: d.title,
      message: d.message,
      danger: false,
      icon: 'bi-exclamation-triangle',
      confirm: fix ? fix.label : 'Ndrysho orët',
      cancel: fix ? 'Ndrysho orët' : false
    }).then(function (ok) {
      if (ok && fix) { input.value = String(fix.value); retry(); return; }
      if (input && document.contains(input)) {
        input.focus();
        if (input.select) input.select();
      }
    });
    return true;
  }

  function busy(el, on) {
    if (!el) return;
    el.disabled = !!on;
    el.classList.toggle('is-loading', !!on);
    el.setAttribute('aria-busy', on ? 'true' : 'false');
  }

  /* --------------------------------------------------- Rivizatimi dhe fokusi */
  function focusEl(el) {
    if (!el) return false;
    try { el.focus({ preventScroll: false }); } catch (e) { el.focus(); }
    return document.activeElement === el;
  }

  function applyFocus(f) {
    if (!f) return;
    var heading = document.getElementById('curModulesTitle');
    if (f.kind === 'heading') {
      if (heading) { heading.setAttribute('tabindex', '-1'); focusEl(heading); }
      return;
    }
    var sel = f.kind === 'module' ? '[data-module="' + f.id + '"]' : '[data-topic="' + f.id + '"]';
    var box = root.querySelector(sel);
    if (!box) { if (heading) { heading.setAttribute('tabindex', '-1'); focusEl(heading); } return; }
    if (f.target === 'quick') {
      var q = box.querySelector('form[data-cur-add-topic] input[name="title"]');
      if (focusEl(q)) return;
    }
    if (f.target === 'move') {
      var own = box.querySelector('[data-cur-move][data-id="' + f.id + '"][data-dir="' + f.dir + '"]');
      if (own && !own.disabled && focusEl(own)) return;
      var other = box.querySelector('[data-cur-move][data-id="' + f.id + '"][data-dir="' + (-f.dir) + '"]');
      if (other && !other.disabled && focusEl(other)) return;
    }
    if (f.target === 'edit') {
      var edit = box.querySelector('[data-cur-open$="-edit"][data-id="' + f.id + '"]');
      if (focusEl(edit)) return;
    }
    box.setAttribute('tabindex', '-1');
    focusEl(box);
  }

  function render(json, focus, quiet) {
    root.innerHTML = json.html;
    if (json.course) {
      current = json.course;
      var hoursEl = document.querySelector('[data-course-hours]');
      if (hoursEl) hoursEl.textContent = json.course.hours + ' orë';
      var codeEl = document.querySelector('[data-course-code]');
      if (codeEl) codeEl.textContent = json.course.code;
      var titleEl = document.getElementById('courseTitle');
      if (titleEl) titleEl.textContent = json.course.name;
    }
    /* Temat e shtuara njëra pas tjetrës japin një njoftim të vetëm që numëron;
       emri i plotë i temës lexohet nga lexuesi i ekranit. */
    if (json.message) { toast(quiet || json.message); announce(json.message); }
    var f = focus === undefined ? json.focus : focus;
    if (document.querySelector('.modal.show')) pendingFocus = f; else applyFocus(f);
  }

  function fail(err, control) {
    toast(err.message || 'Ndryshimi nuk u ruajt.', 'danger', { autohide: false });
    if (control && document.contains(control)) control.focus();
  }

  /* Kur mbyllet një dialog pas ruajtjes, fokusi shkon te elementi i ri përkatës. */
  document.addEventListener('hidden.bs.modal', function () {
    if (pendingFocus === null) return;
    var f = pendingFocus;
    pendingFocus = null;
    setTimeout(function () { applyFocus(f); }, 10);
  });

  /* ------------------------------------------------------------ Dialogët */
  var moduleDialog = document.getElementById('moduleDialog');
  var topicDialog = document.getElementById('topicDialog');
  var courseDialog = document.getElementById('courseDialog');

  function modal(el) { return el && window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(el) : null; }
  /* Bootstrap nuk e mbyll një dialog që ende po hapet; atëherë pret sa të hapet. */
  function hideModal(el) {
    var inst = modal(el);
    if (!inst) return;
    if (inst._isTransitioning) el.addEventListener('shown.bs.modal', function () { inst.hide(); }, { once: true });
    else inst.hide();
  }

  function fillPositions(select, count, current, extraLast) {
    select.innerHTML = '';
    var total = count + (extraLast ? 1 : 0);
    for (var i = 1; i <= total; i++) {
      var o = document.createElement('option');
      o.value = String(i);
      o.textContent = i + (i === 1 ? ' — i pari' : (i === total ? ' — i fundit' : ''));
      if (i === current) o.selected = true;
      select.appendChild(o);
    }
  }

  function formError(form, message) {
    var box = form.querySelector('[data-form-error]');
    if (!box) return;
    box.textContent = message || '';
    box.hidden = !message;
  }

  /* ------------------------------------------- Sa orë lejohen (udhëzimet) */
  function num(el, attr) { return el ? parseInt(el.getAttribute(attr), 10) || 0 : 0; }
  function totals() {
    var s = root.querySelector('.cur-status');
    return { course: num(s, 'data-course-hours'), modules: num(s, 'data-module-hours') };
  }
  function hint(form, text) {
    var el = form.querySelector('[data-hours-hint]');
    if (el) el.textContent = text;
  }
  function moduleHint(form, mode, btn) {
    var t = totals();
    if (mode !== 'edit') {
      var free = t.course - t.modules;
      hint(form, free > 0
        ? 'Mbeten ' + free + ' orë nga ' + t.course + ' të kursit.'
        : 'Modulet i kanë zënë të gjitha orët e kursit (' + t.course + '). Për një modul të ri, rrit orët e kursit me "Ndrysho kursin".');
      return;
    }
    var own = num(btn, 'data-hours');
    var topics = num(root.querySelector('.cur-module[data-module="' + btn.getAttribute('data-id') + '"]'), 'data-topic-hours');
    var max = t.course - (t.modules - own);
    var min = Math.max(1, topics);
    if (max < 1) hint(form, 'Modulet kalojnë orët e kursit: ul orët e moduleve, ose rrit orët e kursit.');
    else if (max < min) hint(form, 'Temat e këtij moduli kanë ' + topics + ' orë, më shumë se sa lejon kursi (' + max + ').');
    else if (min === max) hint(form, 'Ky modul duhet të ketë ' + min + ' orë.');
    else hint(form, topics > 0
      ? 'Nga ' + min + ' orë (sa kanë temat) deri në ' + max + ' orë (sa lejon kursi).'
      : 'Deri në ' + max + ' orë (sa lejon kursi).');
  }
  function topicHint(form, btn) {
    var box = btn.closest('.cur-module');
    var mh = num(box, 'data-hours');
    var th = num(box, 'data-topic-hours');
    var others = th - num(btn, 'data-hours');
    hint(form, th > mh
      ? 'Temat e modulit kanë ' + th + ' orë, por moduli ka vetëm ' + mh + ': ul orët.'
      : 'Deri në ' + Math.max(0, mh - others) + ' orë: moduli ka ' + mh + ' orë, temat e tjera ' + others + '.');
  }

  function openModuleDialog(mode, btn) {
    if (!moduleDialog) return;
    var form = moduleDialog.querySelector('form');
    form.reset();
    formError(form, '');
    var count = root.querySelectorAll('.cur-module').length;
    var isEdit = mode === 'edit';
    form.dataset.mode = mode;
    form.elements.module_id.value = isEdit ? btn.getAttribute('data-id') : '';
    form.elements.title.value = isEdit ? btn.getAttribute('data-title') : '';
    form.elements.hours.value = isEdit ? btn.getAttribute('data-hours') : '';
    fillPositions(form.elements.position, count, isEdit ? parseInt(btn.getAttribute('data-position'), 10) : count + 1, !isEdit);
    moduleHint(form, mode, btn);
    moduleDialog.querySelector('[data-dialog-title]').textContent = isEdit ? 'Ndrysho modulin' : 'Shto një modul';
    moduleDialog.querySelector('[data-submit-label] span').textContent = isEdit ? 'Ruaj ndryshimet' : 'Shto modulin';
    modal(moduleDialog).show(btn || undefined);
  }

  function openTopicDialog(btn) {
    if (!topicDialog) return;
    var form = topicDialog.querySelector('form');
    form.reset();
    formError(form, '');
    form.elements.topic_id.value = btn.getAttribute('data-id');
    form.elements.module_id.value = btn.getAttribute('data-module');
    form.elements.title.value = btn.getAttribute('data-title');
    form.elements.hours.value = btn.getAttribute('data-hours');
    fillPositions(form.elements.position, parseInt(btn.getAttribute('data-count'), 10) || 1, parseInt(btn.getAttribute('data-position'), 10), false);
    topicHint(form, btn);
    topicDialog.querySelector('[data-dialog-eyebrow]').textContent = 'Moduli ' + (btn.getAttribute('data-module-title') || '');
    modal(topicDialog).show(btn);
  }

  if (moduleDialog) {
    moduleDialog.addEventListener('show.bs.modal', function (ev) {
      /* Hapur nga lidhja "Shto modul" (?add=1): gatit formularin bosh. */
      if (!ev.relatedTarget && moduleDialog.querySelector('form').dataset.mode === undefined) {
        var form = moduleDialog.querySelector('form');
        var count = root.querySelectorAll('.cur-module').length;
        form.dataset.mode = 'add';
        fillPositions(form.elements.position, count, count + 1, true);
        moduleHint(form, 'add', null);
      }
    });
    moduleDialog.addEventListener('shown.bs.modal', function () { moduleDialog.querySelector('input[name="title"]').focus(); });
  }
  if (topicDialog) topicDialog.addEventListener('shown.bs.modal', function () { topicDialog.querySelector('input[name="title"]').focus(); });
  if (courseDialog) {
    /* Hapet gjithmonë me vlerat e ruajtura së fundi (edhe pas një rregullimi me një klik). */
    courseDialog.addEventListener('show.bs.modal', function () {
      var form = courseDialog.querySelector('form');
      formError(form, '');
      if (current) {
        form.elements.name.value = current.name;
        form.elements.code.value = current.code;
        form.elements.hours.value = current.hours;
      }
      var t = totals();
      hint(form, t.modules > 0 ? 'Të paktën ' + t.modules + ' orë: aq kanë modulet.' : '');
    });
  }

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form.matches) return;

    if (form.matches('form[data-cur-form]')) {
      ev.preventDefault();
      var kind = form.getAttribute('data-cur-form');
      var btn = form.querySelector('button[type="submit"]');
      var payload;
      if (kind === 'module') {
        var editing = form.dataset.mode === 'edit';
        payload = editing
          ? { action: 'update_module', module_id: form.elements.module_id.value, title: form.elements.title.value, hours: form.elements.hours.value, position: form.elements.position.value }
          : { action: 'add_module', title: form.elements.title.value, hours: form.elements.hours.value, position: form.elements.position.value };
      } else if (kind === 'topic') {
        payload = { action: 'update_topic', topic_id: form.elements.topic_id.value, title: form.elements.title.value, hours: form.elements.hours.value, position: form.elements.position.value };
      } else {
        payload = { action: 'update_course', name: form.elements.name.value, code: form.elements.code.value, hours: form.elements.hours.value };
      }
      formError(form, '');
      busy(btn, true);
      send(payload).then(function (json) {
        busy(btn, false);
        var host = form.closest('.modal');
        render(json);
        if (host) hideModal(host);
      }).catch(function (err) {
        busy(btn, false);
        if (hoursProblem(err, form.elements.hours, function () { resubmit(form); })) return;
        formError(form, err.message);
        var first = form.querySelector('input:not([type="hidden"])');
        if (first) first.focus();
      });
      return;
    }

    if (form.matches('form[data-cur-add-topic]')) {
      ev.preventDefault();
      var mid = form.getAttribute('data-cur-add-topic');
      var title = form.elements.title.value.trim();
      var hours = form.elements.hours.value.trim();
      if (!title) { toast('Shkruaj emrin e temës, p.sh. "Formatimi i tekstit".', 'warning'); form.elements.title.focus(); return; }
      if (!/^\d+$/.test(hours) || parseInt(hours, 10) < 1) { toast('Shkruaj orët e temës: një numër i plotë, të paktën 1.', 'warning'); form.elements.hours.focus(); return; }
      var addBtn = form.querySelector('button[type="submit"]');
      busy(addBtn, true);
      send({ action: 'add_topic', module_id: mid, title: title, hours: hours }).then(function (json) {
        render(json, undefined, 'Tema u shtua.');
      }).catch(function (err) {
        busy(addBtn, false);
        if (hoursProblem(err, form.elements.hours, function () { resubmit(form); })) return;
        fail(err, form.elements.title);
      });
    }
  });

  /* -------------------------------------------------------- Butonat */
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest ? ev.target : null;
    if (!t) return;

    var opener = t.closest('[data-cur-open]');
    if (opener) {
      ev.preventDefault();
      var what = opener.getAttribute('data-cur-open');
      if (what === 'module-add') openModuleDialog('add', opener);
      else if (what === 'module-edit') openModuleDialog('edit', opener);
      else if (what === 'topic-edit') openTopicDialog(opener);
      return;
    }

    var mover = t.closest('[data-cur-move]');
    if (mover && !mover.disabled) {
      var kind = mover.getAttribute('data-cur-move');
      var id = parseInt(mover.getAttribute('data-id'), 10);
      var dir = parseInt(mover.getAttribute('data-dir'), 10);
      busy(mover, true);
      send({ action: 'move', kind: kind, id: id, dir: dir }).then(function (json) {
        render(json);
      }).catch(function (err) { busy(mover, false); fail(err, mover); });
      return;
    }

    var del = t.closest('[data-cur-delete]');
    if (del) {
      var dkind = del.getAttribute('data-cur-delete');
      var dtitle = del.getAttribute('data-title') || '';
      var topics = parseInt(del.getAttribute('data-topics') || '0', 10);
      var q = dkind === 'module'
        ? { title: 'Të fshihet moduli "' + dtitle + '"?',
            message: 'Moduli' + (topics ? ' dhe ' + (topics === 1 ? 'tema e tij' : topics + ' temat e tij') : '') + ' fshihen nga kursi. Grupet që ekzistojnë nuk preken — secili ka kopjen e vet të temave.',
            confirm: 'Po, fshije modulin' }
        : { title: 'Të fshihet tema "' + dtitle + '"?',
            message: 'Tema fshihet nga moduli dhe temat e tjera zënë vendin e saj. Grupet që ekzistojnë nuk preken.',
            confirm: 'Po, fshije temën' };
      window.qtaConfirm(Object.assign({ danger: true }, q)).then(function (ok) {
        if (!ok) return;
        busy(del, true);
        var payload = dkind === 'module' ? { action: 'delete_module', module_id: del.getAttribute('data-id') } : { action: 'delete_topic', topic_id: del.getAttribute('data-id') };
        send(payload).then(function (json) { render(json); }).catch(function (err) { busy(del, false); fail(err, del); });
      });
      return;
    }

    var fix = t.closest('[data-cur-fix]');
    if (fix) {
      var action = fix.getAttribute('data-cur-fix');
      busy(fix, true);
      send({ action: action, value: fix.getAttribute('data-value'), module_id: fix.getAttribute('data-module') })
        .then(function (json) { render(json); })
        .catch(function (err) { busy(fix, false); if (!hoursProblem(err, null, null)) fail(err, fix); });
    }
  });

  if (CFG.flash) toast(CFG.flash);

  /* Të dhëna më të vjetra ku temat kalojnë modulin (ose modulet kursin): sot kjo
     nuk lejohet, prandaj kur hapen ndryshimet del një dialog që kërkon rregullimin.
     "Më vonë" e hesht për këtë gjendje deri në mbylljen e shfletuesit. */
  (function overLimitNotice() {
    if (!CFG.edit || !window.qtaConfirm) return;
    var t = totals();
    var parts = [];
    var first = null;
    if (t.modules > t.course) {
      parts.push('Modulet kanë ' + t.modules + ' orë, por kursi ka vetëm ' + t.course + '.');
      first = document.getElementById('curStatusTitle');
    }
    Array.prototype.forEach.call(root.querySelectorAll('.cur-module.is-over'), function (box) {
      parts.push('Temat e modulit "' + box.getAttribute('data-title') + '" kanë ' + num(box, 'data-topic-hours') + ' orë, por moduli ka vetëm ' + num(box, 'data-hours') + '.');
      if (!first) first = box;
    });
    if (!parts.length) return;
    var key = 'qtaHoursNotice:' + CFG.course + ':' + parts.join('|');
    try { if (sessionStorage.getItem(key)) return; } catch (e) { /* pa kujtesë: dialogu del sërish */ }
    window.qtaConfirm({
      title: 'Orët nuk përputhen',
      message: parts.join(' ') + ' Orët e temave nuk mund të kalojnë orët e modulit, as modulet orët e kursit. Rregulloji që kursi të përdoret për grupe me orar.',
      danger: false,
      icon: 'bi-exclamation-triangle',
      confirm: 'Rregulloji tani',
      cancel: 'Më vonë'
    }).then(function (ok) {
      if (!ok) {
        try { sessionStorage.setItem(key, '1'); } catch (e) { /* pa kujtesë */ }
        return;
      }
      if (!first) return;
      first.setAttribute('tabindex', '-1');
      first.scrollIntoView({ block: 'center' });
      focusEl(first);
    });
  })();
})();
