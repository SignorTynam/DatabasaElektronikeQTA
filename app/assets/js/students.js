/* ============================================================================
   students.js — Kursantët (students.php).
   Rreshtat vijnë edhe nga lista e gjallë (app.js), prandaj çdo veprim këtu
   dëgjohet me delegim te dokumenti, jo te elementët e ngarkimit të parë.
     1. Redaktimi në tabelë (emri, datëlindja, arsimi…), amza dhe numri personal
     2. Fshirja e regjistrimit dhe dialogu i shtimit
     3. Caktimi në grup ("Pa grup", "Gati për grup", "Pa kurs"): kursi, grupi,
        disa kursantë njëherësh
   Ndryshimet i ruan serveri (students_inline_update.php, student_assignment.php):
   lejet, kyçi i ndryshimeve dhe rregullat e grupeve kontrollohen atje.
   ========================================================================= */
(function () {
  'use strict';

  var cfgEl = document.getElementById('studentsConfig');
  if (!cfgEl) return;
  var CFG = JSON.parse(cfgEl.textContent || '{}');
  var EDIT = !!CFG.edit;

  function notify(type, text, opts) {
    opts = opts || {};
    return window.qtaToast ? window.qtaToast(text, type, opts.title, opts) : null;
  }
  function cleanText(s) { var v = String(s || '').replace(/\s+/g, ' ').trim(); return v === '—' ? '' : v; }
  function modal(id) { var el = document.getElementById(id); return el ? window.bootstrap.Modal.getOrCreateInstance(el) : null; }
  function flashCell(cell, cls, ms) { cell.classList.add(cls); setTimeout(function () { cell.classList.remove(cls); }, ms); }
  function busy(btn, on) {
    if (!btn) return;
    btn.disabled = on;
    btn.classList.toggle('is-loading', on);
    btn.setAttribute('aria-busy', on ? 'true' : 'false');
  }

  function normalizeDateForServer(str) {
    var v = String(str || '').trim();
    if (v === '' || v === '—') return '';
    var m = v.match(/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})$/);
    if (m) return m[3] + '-' + m[2].padStart(2, '0') + '-' + m[1].padStart(2, '0');
    m = v.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
    if (m) return m[1] + '-' + m[2].padStart(2, '0') + '-' + m[3].padStart(2, '0');
    throw new Error('Shkruaje datën si dd.mm.vvvv, p.sh. 05.03.1990.');
  }

  function postJSON(url, payload) {
    return window.qtaFetch.response(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf: CFG.csrf }, payload))
    }).then(function (res) {
      return res.json().catch(function () { return { ok: false, error: 'Përgjigje e pavlefshme nga serveri. Provo sërish.' }; });
    });
  }

  /* ---------------------------------------------- 1. Redaktimi në tabelë */
  function saveInline(studentId, field, value, cell, displayEl, extra) {
    if (!EDIT) return Promise.resolve();
    if (cell.classList.contains('cell-saving')) return;
    cell.setAttribute('aria-busy', 'true');
      cell.classList.add('cell-saving');
    return postJSON(CFG.inline, Object.assign({ student_id: studentId, field: field, value: value }, extra || {}))
      .then(function (json) {
        cell.classList.remove('cell-saving');
        if (!json.ok) throw new Error(json.error || 'Ndryshimi nuk u ruajt.');
        if (displayEl && field !== 'education_level_id' && field !== 'gender_id') {
          displayEl.textContent = json.display != null ? json.display : (value || '—');
        }
        flashCell(cell, 'cell-ok', 800);
        notify('success', 'Ndryshimi u ruajt.');
      })
      .catch(function (e) {
        cell.classList.remove('cell-saving');
        if (displayEl && displayEl.dataset.prev != null) displayEl.textContent = displayEl.dataset.prev;
        flashCell(cell, 'cell-err', 1200);
        notify('danger', e.message || 'Ndryshimi nuk u ruajt.');
      }).finally(function () { cell.classList.remove('cell-saving'); cell.removeAttribute('aria-busy'); });
  }

  var amzeExistsState = null;
  var pnExistsState = null;
  var pendingAmzeChange = null;

  window.qtaEditable('#studentsTable td.cell .editable', function (el, prevRaw) {
    if (!EDIT) return;
    var cell = el.closest('td.cell');
    var field = cell.dataset.field;
    var sid = parseInt(cell.dataset.id, 10);
    var oldVal = prevRaw;
    var newVal = cleanText(el.textContent);
    if (newVal === cleanText(oldVal)) { el.textContent = oldVal; return; }

    if (field === 'birth_date') {
      try { newVal = normalizeDateForServer(newVal); }
      catch (err) { el.textContent = oldVal; notify('danger', err.message || String(err)); return; }
    }

    if (field === 'nr_amze') {
      var oldAmze = cleanText(oldVal);
      postJSON(CFG.inline, { action: 'check_amze', nr_amze: newVal }).then(function (json) {
        if (!json.ok) { el.textContent = oldVal; notify('danger', json.error || 'Numri i amzës nuk u kontrollua.'); return; }
        if (json.exists && json.student && parseInt(json.student.student_id, 10) !== sid) {
          amzeExistsState = { existingStudent: json.student, pending: { sid: sid, el: el, oldAmze: oldAmze, newVal: newVal } };
          document.getElementById('amzeExistsValue').textContent = newVal || '—';
          document.getElementById('amzeExistsName').textContent = json.student.full_name || '—';
          document.getElementById('amzeExistsPN').textContent = json.student.personal_number || '—';
          document.getElementById('amzeExistsBD').textContent = json.student.birth_date_dmy || '—';
          document.getElementById('amzeExistsPhone').textContent = json.student.phone || '—';
          modal('amzeExistsModal').show();
          return;
        }
        /* Amza e re nuk ekziston: pyet për kursin e këtij regjistrimi. */
        pendingAmzeChange = { sid: sid, field: field, newVal: newVal, cell: cell, el: el, oldAmze: oldAmze };
        var sel = document.getElementById('pickCourseSelect');
        if (sel) sel.value = '';
        modal('pickCourseModal').show();
      }).catch(function (err) { el.textContent = oldVal; notify('danger', err.message, { autohide: false }); });
      return;
    }

    if (field === 'personal_number') {
      var oldPN = cleanText(oldVal);
      cell.classList.add('cell-saving');
      postJSON(CFG.inline, { student_id: sid, field: 'personal_number', value: newVal }).then(function (json) {
        cell.classList.remove('cell-saving');
        if (json.ok) {
          el.textContent = json.display != null ? json.display : (newVal || '—');
          flashCell(cell, 'cell-ok', 800);
          notify('success', 'Ndryshimi u ruajt.');
          return;
        }
        if (json.code === 'PERSONAL_EXISTS' && json.person) {
          pnExistsState = { sid: sid, newVal: newVal, el: el, oldPN: oldPN };
          document.getElementById('pnExistsValue').textContent = newVal || '—';
          document.getElementById('pnExistsName').textContent = json.person.full_name || '—';
          document.getElementById('pnExistsBD').textContent = json.person.birth_date_dmy || '—';
          document.getElementById('pnExistsPhone').textContent = json.person.phone || '—';
          modal('pnExistsModal').show();
          return;
        }
        el.textContent = oldPN || '—';
        flashCell(cell, 'cell-err', 1200);
        notify('danger', json.error || 'Ndryshimi nuk u ruajt.');
      }).catch(function (err) {
        el.textContent = oldPN || '—';
        flashCell(cell, 'cell-err', 1200);
        notify('danger', err.message, { autohide: false });
      }).finally(function () { cell.classList.remove('cell-saving'); });
      return;
    }

    el.dataset.prev = oldVal;
    saveInline(sid, field, newVal, cell, el);
  });

  /* Arsimi dhe gjinia: ruhen sapo zgjidhen. */
  document.addEventListener('change', function (event) {
    var sel = event.target;
    if (!sel.matches || !sel.matches('#studentsTable td.cell select.inline-select') || !EDIT) return;
    var cell = sel.closest('td.cell');
    saveInline(parseInt(cell.dataset.id, 10), cell.dataset.field, sel.value, cell, null);
  });

  /* Datëlindja në tabelë: shifrat formatohen si dd.mm.vvvv ndërsa shkruhen. */
  function maskDate(value) {
    var d = String(value || '').replace(/\D/g, '').slice(0, 8);
    var out = d.slice(0, 2);
    if (d.length > 2) out += '.' + d.slice(2, 4);
    if (d.length > 4) out += '.' + d.slice(4, 8);
    return out;
  }
  document.addEventListener('input', function (event) {
    var el = event.target;
    if (!el.matches || !el.matches('#studentsTable td.cell[data-field="birth_date"] .editable')) return;
    var masked = maskDate(el.textContent);
    if (el.textContent !== masked) {
      el.textContent = masked;
      var range = document.createRange();
      range.selectNodeContents(el);
      range.collapse(false);
      var s = window.getSelection();
      s.removeAllRanges();
      s.addRange(range);
    }
  });

  function applyPersonToRow(sid, person, eduId) {
    var row = document.getElementById('row-' + sid);
    if (!row || !person) return;
    function setText(field, val) {
      var el = row.querySelector('td.cell[data-field="' + field + '"] .editable');
      if (el) el.textContent = (val && String(val).trim() !== '') ? val : '—';
    }
    function setSelect(field, val) {
      var sel = row.querySelector('td.cell[data-field="' + field + '"] select.inline-select');
      if (sel) sel.value = val ? String(val) : '';
    }
    setText('personal_number', person.personal_number);
    setText('first_name', person.first_name);
    setText('father_name', person.father_name);
    setText('last_name', person.last_name);
    setText('birth_date', person.birth_date_dmy);
    setText('birth_place', person.birth_place);
    setText('phone', person.phone);
    if (person.gender_id) setSelect('gender_id', person.gender_id);
    if (eduId !== undefined) setSelect('education_level_id', eduId);
    var fullName = [person.first_name, person.father_name, person.last_name].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();
    var delBtn = row.querySelector('.btn-delete');
    if (delBtn) delBtn.dataset.name = fullName || '—';
  }

  function on(id, type, fn) { var el = document.getElementById(id); if (el) el.addEventListener(type, fn); }

  /* Numri personal i dikujt tjetër: lidhe regjistrimin me atë person. */
  on('btnPnCancel', 'click', function () {
    if (pnExistsState && pnExistsState.el) pnExistsState.el.textContent = pnExistsState.oldPN || '—';
    pnExistsState = null;
  });
  on('btnPnLink', 'click', function () {
    if (!pnExistsState) return;
    var st = pnExistsState;
    postJSON(CFG.inline, { action: 'link_person_by_pn', student_id: st.sid, personal_number: st.newVal }).then(function (json) {
      if (!json.ok) throw new Error(json.error || 'Lidhja nuk u krye.');
      applyPersonToRow(st.sid, json.person, json.education_level_id);
      modal('pnExistsModal').hide();
      notify('success', 'Regjistrimi u lidh me personin ekzistues.');
    }).catch(function (e) {
      notify('danger', e.message || 'Lidhja nuk u krye.');
    }).then(function () { pnExistsState = null; });
  });

  /* Amza e re: kursi i këtij regjistrimi (ose më vonë). */
  on('btnSaveCourse', 'click', function () {
    if (!pendingAmzeChange) return;
    var p = pendingAmzeChange;
    var sel = document.getElementById('pickCourseSelect');
    var cid = sel && sel.value ? parseInt(sel.value, 10) : 0;
    p.el.dataset.prev = p.oldAmze;
    if(cid>0) {
      document.getElementById('pickCourseModal').addEventListener('hidden.bs.modal',function(){window.QtaEnrollment.open(p.sid,cid,p.el,{url:CFG.inline,payload:{action:'update_cell',student_id:p.sid,field:p.field,value:p.newVal}});},{once:true});
      p.el.textContent=p.oldAmze;
    } else saveInline(p.sid, p.field, p.newVal, p.cell, p.el);
    pendingAmzeChange = null;
    modal('pickCourseModal').hide();
  });
  on('btnSkipCourse', 'click', function () {
    if (!pendingAmzeChange) return;
    var p = pendingAmzeChange;
    p.el.dataset.prev = p.oldAmze;
    saveInline(p.sid, p.field, p.newVal, p.cell, p.el);
    pendingAmzeChange = null;
  });
  /* Dialogu u mbyll pa zgjedhje (Esc, ×): amza kthehet siç ishte. */
  on('pickCourseModal', 'hidden.bs.modal', function () {
    if (!pendingAmzeChange) return;
    pendingAmzeChange.el.textContent = pendingAmzeChange.oldAmze || '—';
    pendingAmzeChange = null;
  });

  /* Amza i përket një kursanti tjetër. */
  on('btnAmzeCancel', 'click', function () {
    if (amzeExistsState && amzeExistsState.pending.el) amzeExistsState.pending.el.textContent = amzeExistsState.pending.oldAmze || '';
    amzeExistsState = null;
  });
  on('btnAmzeOpenExisting', 'click', function () {
    if (!amzeExistsState) return;
    var st = amzeExistsState;
    if (st.pending.el) st.pending.el.textContent = st.pending.oldAmze || '';
    window.location.href = 'student_card.php?sid=' + encodeURIComponent(st.existingStudent.student_id);
  });
  on('btnAmzeMergeDuplicate', 'click', function () {
    if (!amzeExistsState) return;
    var st = amzeExistsState;
    postJSON(CFG.inline, {
      action: 'merge_students',
      source_student_id: st.pending.sid,
      target_student_id: st.existingStudent.student_id
    }).then(function (json) {
      if (!json.ok) throw new Error(json.error || 'Bashkimi nuk u krye.');
      var row = document.getElementById('row-' + st.pending.sid);
      if (row) row.remove();
      modal('amzeExistsModal').hide();
      notify('success', 'Dublikata u bashkua. Po hap kursantin ekzistues…');
      window.location.href = 'student_card.php?sid=' + encodeURIComponent(st.existingStudent.student_id);
    }).catch(function (e) {
      notify('danger', e.message || 'Bashkimi nuk u krye.');
    }).then(function () { amzeExistsState = null; });
  });

  /* ------------------------------------ 2. Fshirja dhe dialogu i shtimit */
  var deleteState = { sid: 0, name: '', amze: '' };
  document.addEventListener('click', function (event) {
    var btn = event.target.closest ? event.target.closest('#studentsTable .btn-delete') : null;
    if (!btn) return;
    deleteState = { sid: parseInt(btn.dataset.sid, 10), name: btn.dataset.name || '—', amze: btn.dataset.amze || '—' };
    document.getElementById('delName').textContent = deleteState.name;
    document.getElementById('delAmze').textContent = deleteState.amze;
    document.getElementById('delAlsoIdentity').checked = false;
    window.bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteStudentModal')).show(btn);
  });
  on('btnConfirmDelete', 'click', function () {
    if (!deleteState.sid) return;
    var btn = document.getElementById('btnConfirmDelete');
    if (btn && btn.disabled) return;
    busy(btn, true);
    window.qtaFetch.response('students.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        csrf: CFG.csrf,
        action: 'delete_student',
        student_id: deleteState.sid,
        also_delete_identity: document.getElementById('delAlsoIdentity').checked ? 1 : 0
      })
    }).then(function (res) { return res.json(); }).then(function (json) {
      busy(btn, false);
      if (!json.ok) throw new Error(json.error || 'Fshirja nuk u krye.');
      modal('deleteStudentModal').hide();
      notify('success', 'Regjistrimi ' + deleteState.amze + ' u fshi' + (json.deleted_person ? ' bashkë me personin' : '') + '.');
      if (window.qtaLive) window.qtaLive.refresh(); else window.location.reload();
    }).catch(function (e) {
      busy(btn, false);
      notify('danger', e.message || 'Fshirja nuk u krye.');
    }).finally(function () { busy(btn, false); });
  });

  /* Shto kursant: numri personal i dikujt që ekziston plotëson të dhënat. */
  function isoToDmy(iso) {
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return '';
    var p = iso.split('-');
    return p[2] + '.' + p[1] + '.' + p[0];
  }
  on('pnInput', 'blur', function () {
    var pn = document.getElementById('pnInput').value.trim();
    if (!pn) return;
    window.qtaFetch.response('students.php?action=lookup_person&personal_number=' + encodeURIComponent(pn), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (!json.ok) { notify('danger', json.error || 'Kërkimi nuk u krye.'); return; }
        var p = json.person;
        if (!p) { notify('warning', 'Nuk u gjet person me këtë numër personal.'); return; }
        document.getElementById('fnInput').value = p.first_name || '';
        document.getElementById('fatInput').value = p.father_name || '';
        document.getElementById('lnInput').value = p.last_name || '';
        document.getElementById('bdInput').value = isoToDmy(p.birth_date) || '';
        document.getElementById('bpInput').value = p.birth_place || '';
        document.getElementById('phInput').value = p.phone || '';
        var gsel = document.getElementById('genderSelect'); if (gsel && p.gender_id) gsel.value = String(p.gender_id);
        var esel = document.getElementById('eduSelect'); if (esel && json.education_level_id) esel.value = String(json.education_level_id);
        notify('info', 'Personi ekziston: të dhënat u plotësuan vetë.');
      })
      .catch(function () { notify('danger', 'Të dhënat e personit nuk u morën. Plotësoji me dorë.'); });
  });

  /* ------------------------------------------------ 3. Caktimi në grup */
  function afterChange(message) {
    notify('success', message);
    if (window.qtaLive) window.qtaLive.refresh(); else window.location.reload();
  }
  function rowOf(el) { return el.closest ? el.closest('tr[data-student]') : null; }

  document.addEventListener('click', function (event) {
    var t = event.target;
    if (!t.closest || !t.closest('[data-assign-table], [data-bulk]')) return;

    /* Cakto një kursant */
    var assign = t.closest('[data-assign]');
    if (assign && EDIT) {
      var row = rowOf(assign);
      var sel = row && row.querySelector('[data-group-select]');
      var gid = parseInt((sel && sel.value) || '0', 10);
      if (!gid) { notify('warning', 'Zgjidh grupin së pari.'); if (sel) sel.focus(); return; }
      if (assign && assign.disabled) return;
      busy(assign, true);
      window.QtaEnrollment.assign(parseInt(assign.dataset.student,10),gid,{},assign)
        .catch(function (err) { notify('danger', err.message, { autohide: false }); }).finally(function () { busy(assign, false); });
      return;
    }

    /* Ruaj kursin e zgjedhur */
    var planSave = t.closest('[data-plan-save]');
    if (planSave && EDIT) {
      var prow = rowOf(planSave);
      var psel = prow && prow.querySelector('[data-plan-select]');
      var cid = psel && psel.value ? parseInt(psel.value, 10) : 0;
      if (!cid) { notify('warning', 'Zgjidh kursin së pari.'); if (psel) psel.focus(); return; }
      if (planSave && planSave.disabled) return;
      busy(planSave, true);
      postJSON(CFG.assign, { action: 'set_student_plan', student_id: parseInt(planSave.dataset.student, 10), course_id: cid })
        .then(function (json) {
          busy(planSave, false);
          if (!json.ok) { notify('danger', json.error || 'Kursi nuk u ruajt.'); return; }
          afterChange(json.message || 'Kursi u ruajt. Tani zgjidh grupin.');
        })
        .catch(function (err) { notify('danger', err.message, { autohide: false }); }).finally(function () { busy(planSave, false); });
      return;
    }

    /* Hiq kursin e zgjedhur */
    var planRemove = t.closest('[data-plan-remove]');
    if (planRemove && EDIT) {
      var who = planRemove.dataset.name || 'këtij kursanti';
      window.qtaConfirm({
        title: 'Të hiqet kursi?',
        message: 'Kursi i zgjedhur për ' + who + ' do të hiqet. Kursanti mbetet pa grup dhe mund të marrë një kurs tjetër.',
        confirm: 'Po, hiqe kursin',
        danger: true
      }).then(function (ok) {
        if (!ok) return;
        if (planRemove && planRemove.disabled) return;
        busy(planRemove, true);
        postJSON(CFG.assign, {
          action: 'remove_student_plan',
          student_id: parseInt(planRemove.dataset.student, 10),
          course_id: parseInt(planRemove.dataset.course, 10)
        }).then(function (json) {
          busy(planRemove, false);
          if (!json.ok) { notify('danger', json.error || 'Kursi nuk u hoq.'); return; }
          afterChange(json.message || 'Kursi u hoq. Zgjidh një kurs tjetër kur të jesh gati.');
        }).catch(function (err) { notify('danger', err.message, { autohide: false }); }).finally(function () { busy(planRemove, false); });
      });
      return;
    }

    /* Hiq zgjedhjen */
    if (t.closest('[data-bulk-clear]')) {
      Array.prototype.forEach.call(document.querySelectorAll('[data-pick]:checked'), function (cb) { cb.checked = false; });
      syncBulk();
      var all = document.querySelector('[data-pick-all]');
      if (all) all.focus();
      return;
    }

    /* Cakto të zgjedhurit — i njëjti veprim si një nga një, me radhë. */
    var bulkBtn = t.closest('[data-bulk-assign]');
    if (bulkBtn && EDIT) bulkAssign(bulkBtn);
  });

  function picks() { return Array.prototype.slice.call(document.querySelectorAll('[data-pick]:checked')); }

  function syncBulk() {
    var bar = document.querySelector('[data-bulk]');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-pick]:not(:disabled)'));
    var n = picks().length;
    boxes.forEach(function (cb) { var tr = cb.closest('tr'); if (tr) tr.classList.toggle('is-selected', cb.checked); });
    if (bar) {
      bar.hidden = n === 0;
      var out = bar.querySelector('[data-bulk-n]');
      if (out) out.textContent = String(n);
    }
    var all = document.querySelector('[data-pick-all]');
    if (all) {
      all.checked = n > 0 && boxes.length > 0 && boxes.every(function (cb) { return cb.checked; });
      all.indeterminate = n > 0 && !all.checked;
    }
  }

  document.addEventListener('change', function (event) {
    var t = event.target;
    if (!t.matches) return;
    if (t.matches('[data-pick-all]')) {
      Array.prototype.forEach.call(document.querySelectorAll('[data-pick]:not(:disabled)'), function (cb) { cb.checked = t.checked; });
      syncBulk();
    } else if (t.matches('[data-pick]')) {
      syncBulk();
    }
  });
  /* Lista e re (filtër tjetër, faqe tjetër): zgjedhja nis nga e para. */
  document.addEventListener('qta:content', function () { syncBulk(); });

  function bulkAssign(btn) {
    var gSel = document.querySelector('[data-bulk-group]');
    var prog = document.querySelector('[data-bulk-progress]');
    var gid = parseInt((gSel && gSel.value) || '0', 10);
    if (!gid) { notify('warning', 'Zgjidh grupin ku do t\'i caktosh.'); if (gSel) gSel.focus(); return; }
    var ids = picks().map(function (cb) { return parseInt(cb.value, 10); });
    if (!ids.length) return;
    var label = (gSel.options[gSel.selectedIndex] && gSel.options[gSel.selectedIndex].textContent.trim()) || 'grupin e zgjedhur';
    window.qtaConfirm({
      title: 'Të caktohen ' + ids.length + ' kursantë?',
      message: 'Do të kontrollohen të gjithë te ' + label + '. Caktimi ruhet vetëm nëse çdo kursant përputhet dhe ka vend për të gjithë.',
      confirm: 'Po, caktoji',
      danger: false
    }).then(function (ok) {
      if (!ok) return;
      if (btn && btn.disabled) return;
      busy(btn, true);
      var done = 0, failed = [];
      async function assignAll() {
        try {
          if (prog) prog.textContent = 'Po kontrolloj të gjithë kursantët…';
          var preflight = await postJSON(CFG.assign,{action:'batch_preflight',student_ids:ids,group_id:gid});
          if (!preflight.ok) throw new Error(preflight.error);
          var summary=preflight.result;
          if (summary.blocked) {
            notify('warning',summary.matched+' përputhen · '+summary.conflicts+' kanë konflikt datash · '+(summary.blocked-summary.conflicts)+' nuk mund të caktohen. Asnjë ndryshim nuk u ruajt. '+summary.errors.map(function(e){return e.message;}).join(' '),{autohide:false});
            return;
          }
          var reviewed=await window.qtaConfirm({title:'Të ruhen '+ids.length+' caktime?',message:summary.matched+' kursantë përputhen. Të gjitha caktimet ruhen në një transaksion.',confirm:'Ruaj caktimet',danger:false});
          if(!reviewed) return;
          var json=await postJSON(CFG.assign,{action:'batch_assign',student_ids:ids,group_id:gid});
          if(json.ok) done=ids.length; else failed.push(json.error || 'Caktimet nuk u ruajtën.');
          finish();
        } catch(err) {notify('danger',err.message,{autohide:false});
        } finally { busy(btn, false); if (prog) prog.textContent = ''; }
      }
      function finish() {
        if (prog) prog.textContent = '';
        busy(btn, false);
        if (done && !failed.length) {
          afterChange(done === 1 ? '1 kursant u caktua në grup.' : done + ' kursantë u caktuan në grup.');
        } else if (done) {
          notify('warning', done + ' u caktuan, ' + failed.length + ' jo. Arsyeja: ' + failed[0], { autohide: false });
          if (window.qtaLive) window.qtaLive.refresh();
        } else {
          notify('danger', 'Asnjë kursant nuk u caktua. ' + (failed[0] || ''), { autohide: false });
        }
      }
      assignAll();
    });
  }

  syncBulk();

  /* Mesazhet pas ringarkimit */
  document.addEventListener('DOMContentLoaded', function () {
    if (CFG.flash_ok) notify('success', CFG.flash_ok);
    if (CFG.flash_err) notify('danger', CFG.flash_err, { autohide: false });
  });
})();
