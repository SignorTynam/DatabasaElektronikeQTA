/* Persistent enrollment forms. All previews are checked again by the server. */
(function () {
  'use strict';
  var cfgNode = document.getElementById('studentsConfig') || document.getElementById('enrollmentConfig');
  if (!cfgNode) return;
  var cfg = JSON.parse(cfgNode.textContent), editor = document.querySelector('[data-enrollment-editor]');
  var conflict = document.getElementById('enrollmentConflict'), conflictState = null, editorState = null, editorSequence = 0;
  var wizard = document.querySelector('[data-student-wizard]'), step = 0, wizardMeta = null, wizardSequence = 0;
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function date(v) { return /^\d{4}-\d{2}-\d{2}$/.test(v || '') ? v.slice(8)+'.'+v.slice(5,7)+'.'+v.slice(0,4) : (v || '—'); }
  function modal(el) { return bootstrap.Modal.getOrCreateInstance(el); }
  function restoreContext(state, cancelled) {
    if(!state) return;
    var trigger=state.trigger, parent=state.parent;
    function focus() {if(trigger && trigger.isConnected && trigger.getClientRects().length) trigger.focus();}
    if(parent && parent.id==='resultsModal' && window.QtaResultsEnrollment) {
      if(cancelled) parent.addEventListener('shown.bs.modal',focus,{once:true});
      window.QtaResultsEnrollment.resume(trigger,!cancelled);return;
    }
    if(cancelled && parent && parent.isConnected) {
      parent.addEventListener('shown.bs.modal',focus,{once:true}); modal(parent).show(trigger);
    } else focus();
  }
  async function post(payload, url) {
    var r = await window.qtaFetch.response(url || cfg.assign, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(Object.assign({csrf:cfg.csrf},payload))});
    return r.json();
  }
  function error(root, message) { var el=root.querySelector('[data-enrollment-error], [data-conflict-error]'); el.textContent=message || ''; el.hidden=!message; }
  function loading(root, on) { root.setAttribute('aria-busy',String(on)); root.querySelectorAll('button[type="submit"], [data-wizard-next]').forEach(b=>{b.disabled=on; b.classList.toggle('is-loading',on);}); }
  function saved(message) { window.qtaToast(message || 'Ndryshimi u ruajt.','success'); if(window.qtaLive) window.qtaLive.refresh(); else location.reload(); }
  function scores(root) { var out={}; root.querySelectorAll('[data-en-score]').forEach(i=>{out[i.dataset.enScore]=i.value;}); return out; }
  function details(root) { var out={scores:scores(root)}; ['start_date','end_date','exam_date','manual_final_score'].forEach(k=>{var i=root.querySelector('[name="'+k+'"]'); if(i) out[k]=i.value;}); return out; }
  function average(root) {
    var inputs=Array.from(root.querySelectorAll('[data-en-score]')), n=0, sum=0, invalid=false;
    inputs.forEach(i=>{var v=i.value.trim().replace(',','.'); if(v==='') return; if(!/^\d{1,3}(?:\.\d{1,2})?$/.test(v) || Number(v)>100) invalid=true; else {n++; sum+=Math.round(Number(v)*100);} });
    var manual=root.querySelector('[name="manual_final_score"]');
    var hasScore=n>0 || !!(manual && manual.value.trim());
    var exam=root.querySelector('[name="exam_date"]'); if(exam) exam.required=hasScore;
    var label=manual ? (manual.value.trim()?'Pikët përfundimtare: '+manual.value+' · rezultat manual':'Pa rezultat manual') : invalid ? 'Pikët janë nga 0 deri në 100, me të shumtën 2 shifra pas presjes.' : inputs.length && n===inputs.length ? 'Mesatarja: '+(Math.floor((2*sum+n)/(2*n))/100).toLocaleString('sq-AL') : n+' nga '+inputs.length+' module — rezultati përfundimtar ende nuk është llogaritur';
    root.querySelector('[data-enrollment-average]').textContent=label;
  }
  function render(root, meta, snapshot) {
    var e=snapshot && snapshot.enrollment, mods=snapshot && snapshot.modules.length ? snapshot.modules : meta.modules;
    var manual=e && e.result_source==='manual' || !mods.length || (!meta.ready && (!e || e.result_source!=='modules') && !(snapshot && snapshot.group && snapshot.group.model==='scheduled'));
    root.querySelector('[data-enrollment-course]').textContent=meta.course.name+' · '+meta.course.code+' · '+meta.course.hours+' orë · '+(meta.ready?'Gati për grup':'Struktura e moduleve nuk është përfunduar ende');
    ['start_date','end_date','exam_date'].forEach(k=>{
      var input=root.querySelector('[name="'+k+'"]');
      input.value=e?date(e[k]).replace('—',''):'';
      input.readOnly=!!(snapshot && snapshot.group && k!=='exam_date');
    });
    var body=root.querySelector('[data-enrollment-results]');
    if(manual) {
      body.innerHTML='<p>'+(meta.ready?'Rezultati manual historik ruhet edhe pse kursi tani ka module.':'Struktura e moduleve të këtij kursi nuk është përfunduar ende.')+'</p><label class="form-label" for="enManual'+(root===wizard?'New':'Edit')+'">Pikët përfundimtare</label><input class="form-control" id="enManual'+(root===wizard?'New':'Edit')+'" name="manual_final_score" inputmode="decimal" value="'+esc(e && e.manual_final_score)+'">'+(meta.ready && e?'<button class="btn btn-secondary mt-3" type="button" data-en-convert>Përdor pikët sipas moduleve</button>':'');
    } else {
      body.innerHTML='<div class="table-responsive"><table class="table"><thead><tr><th scope="col">Moduli</th><th scope="col">Orë</th><th scope="col">Pikët</th></tr></thead><tbody>'+mods.map(m=>'<tr><th scope="row">'+esc(m.title)+'</th><td>'+m.hours+'</td><td><input class="form-control" inputmode="decimal" data-en-score="'+m.id+'" aria-label="Pikët: '+esc(m.title)+'" value="'+esc(snapshot && snapshot.scores[m.id])+'"></td></tr>').join('')+'</tbody></table></div>';
    }
    root.querySelectorAll('[data-enrollment-fields] input').forEach(i=>{i.disabled=false;});
    average(root);
  }
  async function open(student, course, trigger, pending) {
    if(!cfg.edit) return;
    editor.dataset.clean=''; editor.dataset.dirty='';
    editorState={student_id:student,course_id:course,trigger:trigger,pending:pending,parent:trigger && trigger.closest('.modal:not(#enrollmentModal)')};
    var sequence=++editorSequence;
    modal(document.getElementById('enrollmentModal')).show(trigger); loading(editor,true); error(editor,'');
    try {
      var responses=await Promise.all([post({action:'course_details',course_id:course}),post({action:'enrollment',student_id:student,course_id:course})]);
      if(sequence!==editorSequence) return;
      if(responses.some(r=>!r.ok)) throw new Error((responses.find(r=>!r.ok)||{}).error);
      editorState.meta=responses[0]; editorState.snapshot=responses[1].snapshot;
      render(editor,responses[0],responses[1].snapshot);
    } catch(e) {if(sequence===editorSequence) error(editor,e.message || 'Të dhënat nuk u morën.');} finally {if(sequence===editorSequence) loading(editor,false);}
  }
  function showConflict(json, student, group, trigger, intent) {
    if(!json.details || !json.details.enrollment) throw new Error(json.error);
    var parent=document.querySelector('.modal.show:not(#enrollmentConflict)') || (conflict.classList.contains('show') && conflictState && conflictState.parent);
    conflictState={details:json.details,student:student,group:group,trigger:trigger,intent:intent,parent:parent};
    conflict.querySelector('[data-conflict-choices]').hidden=false;
    conflict.querySelector('#enConflictTitle').textContent='Datat nuk përputhen';
    var d=json.details, e=d.enrollment, g=d.group;
    conflict.querySelector('[data-conflict-dates]').innerHTML='<div><dt>Kursanti</dt><dd>'+date(e.start_date)+' → '+date(e.end_date)+'</dd></div><div><dt>'+(d.creation?'Grupi i ri':'Grupi #'+g.id)+' · '+g.members+'/10</dt><dd>'+date(g.start_date)+' → '+date(g.end_date)+'</dd></div>';
    conflict.querySelector('[data-conflict-work]').hidden=true;
    error(conflict,json.code==='stale_enrollment'?json.error:'');
    if(parent && parent.classList.contains('show')) {parent.addEventListener('hidden.bs.modal',()=>modal(conflict).show(trigger),{once:true});modal(parent).hide();}
    else modal(conflict).show(trigger);
  }
  async function assign(student, group, options, trigger) {
    var json=await post(Object.assign({action:'assign_to_group',student_id:student,group_id:group},options || {}));
    if(json.ok) {if(conflict.classList.contains('show')) {conflictState.finished=true;modal(conflict).hide();} saved(json.message); return true;}
    if(['enrollment_dates_conflict','stale_enrollment'].includes(json.code)) {showConflict(json,student,group,trigger); return false;}
    throw new Error(json.error || 'Caktimi nuk u ruajt.');
  }
  async function studentDates(student,course,trigger) {
    var res=await post({action:'enrollment',student_id:student,course_id:course});
    if(!res.ok || !res.snapshot) throw new Error(res.error || 'Plotëso së pari të dhënat e kursit.');
    {
      conflictState={student:student,trigger:trigger,details:{enrollment:res.snapshot.enrollment,candidates:[]}};
      conflict.querySelector('[data-conflict-choices]').hidden=true;
      conflict.querySelector('#enConflictTitle').textContent='Grup me datat e kursantit';
      conflict.querySelector('[data-conflict-dates]').innerHTML='<div><dt>Kursanti</dt><dd>'+date(res.snapshot.enrollment.start_date)+' → '+date(res.snapshot.enrollment.end_date)+'</dd></div>';
      modal(conflict).show(trigger);
    }
    await choose('student');
  }
  async function choose(which) {
    var st=conflictState,d=st.details,e=d.enrollment,work=conflict.querySelector('[data-conflict-work]');
    work.hidden=false; work.innerHTML='<p role="status">Po kontrolloj…</p>'; error(conflict,'');
    try {
      if(which==='group') {
        var g=d.group;
        work.innerHTML='<h3 class="modal-section-title">Pas ndryshimit: '+date(g.start_date)+' → '+date(g.end_date)+'</h3><p class="callout is-warning">Datat individuale të kursantit do të ndryshojnë. Ky veprim nuk këshillohet nëse dokumentet ose certifikata e kursantit janë lëshuar tashmë.</p><label class="form-label" for="enConflictExam">Data e provimit</label><input class="form-control" id="enConflictExam" data-dmy value="'+esc(date(e.exam_date).replace('—',''))+'" placeholder="dd.mm.vvvv"><label class="form-check mt-3"><input class="form-check-input" type="checkbox" data-en-accept> <span class="form-check-label">Pranoj ndryshimin e datave individuale.</span></label><button class="btn btn-primary mt-3" type="button" data-en-adopt>Përdor datat e grupit</button>';
      } else if(which==='candidates') {
        var list=d.candidates || [];
        work.innerHTML='<h3 class="modal-section-title">Grupet e sugjeruara</h3>'+(list.length?'<div class="enrollment-choices">'+list.map(g=>'<button class="btn btn-secondary" type="button" data-en-candidate="'+g.id+'"><strong>Grupi #'+g.id+' · '+g.members+'/10 · '+(Number(g.is_completed)?'I mbyllur':'I hapur')+'</strong><span>'+date(g.start_date)+' → '+date(g.end_date)+' · '+(g.schedule_mode==='fixed_range'?'Periudhë e përcaktuar':g.model==='legacy'?'Regjistri i vjetër':'Orar i llogaritur')+' · diferenca '+g.difference_days+' ditë</span></button>').join('')+'</div>':'<p>Nuk ka grupe të vlefshme me vende të lira për këtë kurs.</p>');
      } else {
        var p=await post({action:'preview_student_group',student_id:st.student,course_id:e.course_id});
        if(!p.ok) throw new Error(p.error);
        st.enrollmentBaseline=p.enrollment_baseline;
        var match=p.exact[0];
        var days=p.preview && p.preview.plan.days;
        work.innerHTML='<h3 class="modal-section-title">'+(match?'U gjet Grupi #'+match.id+' me të njëjtat data — përdor këtë grup.':'Krijo grup me datat e kursantit')+'</h3><p>'+date(e.start_date)+' → '+date(e.end_date)+(match?' · '+match.members+'/10':(' · '+p.preview.summary.total_hours+' orë · '+p.preview.summary.days+' ditë mësimi'))+'</p>'+(days?'<details><summary>Shiko orarin para ruajtjes</summary><ol class="agenda">'+days.map(day=>'<li>'+date(day.date)+' · '+day.hours+' orë</li>').join('')+'</ol></details>':'')+'<button type="button" class="btn btn-primary mt-3" data-en-use-dates>'+(match?'Përdor Grupin #'+match.id:'Krijo grupin dhe cakto kursantin')+'</button>';
      }
      work.focus(); document.dispatchEvent(new CustomEvent('qta:content'));
    } catch(err) {work.innerHTML='';error(conflict,err.message);}
  }
  conflict.addEventListener('click',async function(ev){
    var choice=ev.target.closest('[data-en-resolution]'); if(choice) {await choose(choice.dataset.enResolution);return;}
    var b=ev.target.closest('[data-en-candidate], [data-en-adopt], [data-en-use-dates]'); if(!b || b.disabled) return;
    b.disabled=true; var st=conflictState,e=st.details.enrollment;
    try {
      if(b.hasAttribute('data-en-candidate')) {
        if(st.intent) {st.intent.cancel(); st.intent=null;}
        await assign(st.student,Number(b.dataset.enCandidate),{},st.trigger);
      }
      else if(b.hasAttribute('data-en-adopt')) {
        if(!conflict.querySelector('[data-en-accept]').checked) throw new Error('Konfirmo shprehimisht ndryshimin e datave.');
        var options={resolution:'adopt_group_dates',force:1,baseline:st.details.baseline,exam_date:document.getElementById('enConflictExam').value};
        if(st.intent) {
          var result=await st.intent.submit(options);
          if(result && result.ok) {st.intent=null;st.finished=true;modal(conflict).hide();}
        } else await assign(st.student,st.group,options,st.trigger);
      } else {
        var json=await post({action:'use_student_dates',student_id:st.student,course_id:e.course_id,enrollment_baseline:st.enrollmentBaseline});
        if(!json.ok) throw new Error(json.error);
        if(st.intent) {st.intent.cancel();st.intent=null;}
        st.finished=true;modal(conflict).hide(); saved(json.message);
      }
    } catch(err) {error(conflict,err.message);} finally {b.disabled=false;}
  });
  editor.addEventListener('submit',async function(ev){
    ev.preventDefault(); if(!editorState || editor.getAttribute('aria-busy')==='true') return;
    error(editor,''); if(!editor.reportValidity()) return; loading(editor,true);
    try {
      var payload=Object.assign({action:'save_enrollment',student_id:editorState.student_id,course_id:editorState.course_id},details(editor));
      if(editorState.snapshot) payload.baseline=editorState.snapshot.baseline;
      if(editorState.convert) Object.assign(payload,{use_modules:1,force:1});
      if(editorState.pending) Object.assign(payload,editorState.pending.payload,{planned_course_id:editorState.course_id});
      var url=editorState.pending?editorState.pending.url:null;
      var json=await post(payload,url);
      if(json.confirm && await window.qtaConfirm({title:json.confirm.title,message:json.confirm.message,confirm:json.confirm.confirm,danger:true})) json=await post(Object.assign(payload,{force:1}),url);
      if(!json.ok) throw new Error(json.error || 'Ndryshimi nuk u ruajt.');
      editor.dataset.clean='1'; modal(document.getElementById('enrollmentModal')).hide(); saved(json.message);
    } catch(err) {error(editor,err.message);} finally {loading(editor,false);}
  });
  editor.addEventListener('click',async function(ev){
    if(!ev.target.closest('[data-en-convert]')) return;
    var ok=await window.qtaConfirm({title:'Përdor pikët sipas moduleve?',message:'Rezultati manual ruhet si prejardhje. Rezultati zyrtar do të llogaritet vetëm kur çdo modul ka pikë.',confirm:'Po, përdor modulet',danger:false});
    if(ok) {var old=details(editor); editorState.convert=true; var snap=editorState.snapshot; render(editor,editorState.meta,Object.assign({},snap,{enrollment:Object.assign({},snap.enrollment,{result_source:'modules'}),modules:editorState.meta.modules})); ['start_date','end_date','exam_date'].forEach(k=>editor.elements[k].value=old[k]);}
  });
  document.addEventListener('input',function(ev){var root=ev.target.closest('[data-student-wizard], [data-enrollment-editor]'); if(root && ev.target.closest('[data-enrollment-fields]')) average(root); if(root) root.dataset.dirty='1';});
  document.addEventListener('click',function(ev){
    var b=ev.target.closest('[data-enrollment-open], [data-enrollment-create-group], [data-plan-save]');
    if(!b || !cfg.edit) return;
    ev.preventDefault();ev.stopImmediatePropagation();
    var row=b.closest('tr'), cid=Number(b.dataset.course || (row && row.querySelector('[data-plan-select]') || {}).value);
    if(!cid) {window.qtaToast('Zgjidh kursin së pari.','warning');return;}
    if(b.hasAttribute('data-enrollment-create-group')) studentDates(Number(b.dataset.student),cid,b).catch(e=>window.qtaToast(e.message,'danger'));
    else {
      var parent=b.closest('.modal.show');
      if(parent && parent.id==='resultsModal' && window.QtaResultsEnrollment && !window.QtaResultsEnrollment.suspend()) return;
      if(parent && parent.id!=='enrollmentModal') {parent.addEventListener('hidden.bs.modal',()=>open(Number(b.dataset.student),cid,b),{once:true});modal(parent).hide();}
      else open(Number(b.dataset.student),cid,b);
    }
  },true);
  if(wizard) {
document.getElementById('addStudentModal').addEventListener('hidden.bs.modal',function(){if(wizard.dataset.clean==='1'){wizard.reset();wizard.dataset.clean='';wizard.dataset.dirty='';wizardMeta=null;show(0);wizard.querySelectorAll('[data-wizard-course] input').forEach(i=>i.disabled=true);}});
    wizard.querySelectorAll('[data-wizard-course] input').forEach(i=>i.disabled=true);
    function show(n) {
      step=n; wizard.querySelector('[data-wizard-basic]').hidden=n!==0; wizard.querySelector('[data-wizard-course]').hidden=n!==1; wizard.querySelector('[data-wizard-review]').hidden=n!==2;
      wizard.querySelector('[data-wizard-step]').textContent=n===0?'1. Të dhënat e kursantit':n===1?'2. Të dhënat e kursit':'3. Kontrollo dhe ruaj';
      wizard.querySelector('[data-wizard-back]').hidden=n===0; wizard.querySelector('[data-wizard-next]').hidden=n===2; wizard.querySelector('[data-wizard-save]').hidden=n!==2;
      if(n===2) {
        var d=details(wizard); wizard.querySelector('[data-wizard-summary]').innerHTML='<div><dt>Kursanti</dt><dd>'+esc(wizard.elements.first_name.value+' '+wizard.elements.last_name.value)+' · Amza '+esc(wizard.elements.nr_amze.value)+'</dd></div><div><dt>Kursi</dt><dd>'+esc(wizardMeta?wizardMeta.course.name:'Më vonë')+'</dd></div>'+(wizardMeta?'<div><dt>Periudha individuale</dt><dd>'+esc(d.start_date)+' → '+esc(d.end_date)+'</dd></div><div><dt>Provimi</dt><dd>'+esc(d.exam_date || 'Pa datë')+'</dd></div><div><dt>Rezultati</dt><dd>'+esc(wizard.querySelector('[data-enrollment-average]').textContent)+'</dd></div>':'');
      }
      var target=n===0?wizard.elements.nr_amze:n===1?wizard.querySelector('[data-enrollment-heading]'):wizard.querySelector('[data-wizard-review]'); target.focus();
    }
    wizard.querySelector('[data-wizard-next]').addEventListener('click',async function(){
      error(wizard,''); if(step===0 && !wizard.elements.nr_amze.reportValidity()) return;
      if(step===1 && !Array.from(wizard.querySelectorAll('[data-wizard-course] input')).every(i=>i.reportValidity())) return;
      if(step===0 && wizard.elements.planned_course_id.value) {
        loading(wizard,true); var sequence=++wizardSequence;
        try { var meta=await post({action:'course_details',course_id:Number(wizard.elements.planned_course_id.value)}); if(sequence!==wizardSequence) return; if(!meta.ok) throw new Error(meta.error); if(!wizardMeta || wizardMeta.course.id!==meta.course.id) render(wizard,meta,null); wizardMeta=meta; show(1); }
        catch(err) {error(wizard,err.message);} finally {loading(wizard,false);}
      } else if(step===0) {wizardMeta=null;wizard.querySelectorAll('[data-wizard-course] input').forEach(i=>i.disabled=true);show(2);} else show(2);
    });
    wizard.querySelector('[data-wizard-back]').addEventListener('click',()=>show(step===2 && wizardMeta?1:0));
    wizard.addEventListener('submit',async function(ev){
      ev.preventDefault(); if(step!==2 || wizard.getAttribute('aria-busy')==='true') return; loading(wizard,true); error(wizard,'');
      var payload=Object.fromEntries(new FormData(wizard)); if(wizardMeta) Object.assign(payload,details(wizard));
      try { var json=await post(payload,'students.php'); if(!json.ok) throw new Error(json.error); wizard.dataset.clean='1'; modal(document.getElementById('addStudentModal')).hide(); saved('Kursanti dhe regjistrimi u ruajtën.'); }
      catch(err) {error(wizard,err.message);} finally {loading(wizard,false);}
    });
  }
  [document.getElementById('addStudentModal'),document.getElementById('enrollmentModal')].forEach(el=>{
    if(!el) return; var allow=false;
    el.addEventListener('hide.bs.modal',function(ev){var form=el.querySelector('form'); if(allow || form.dataset.clean==='1' || form.dataset.dirty!=='1') {allow=false;return;} ev.preventDefault(); window.qtaConfirm({title:'Të mbyllet pa ruajtur?',message:'Të dhënat e shkruara do të mbeten në formular. Asgjë nuk është ruajtur.',confirm:'Mbyll pa ruajtur',danger:false}).then(ok=>{if(ok){allow=true;modal(el).hide();}});});
  });
  document.getElementById('enrollmentModal').addEventListener('hidden.bs.modal',function(){
    ++editorSequence;
    restoreContext(editorState,editor.dataset.clean!=='1');
  });
  conflict.addEventListener('hidden.bs.modal',function(){
    if(conflictState && conflictState.intent) {conflictState.intent.cancel();conflictState.intent=null;}
    restoreContext(conflictState,conflictState && !conflictState.finished);
  });
  window.QtaEnrollment={open:open,assign:assign,post:post,showConflict:showConflict};
})();
