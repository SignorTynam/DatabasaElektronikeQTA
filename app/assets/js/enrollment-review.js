/* Shared, server-authoritative review of changes affecting individual periods. */
(function () {
  'use strict';
  window.qtaReviewEnrollment = async function (json, payload, retry) {
    if (json.code === 'group_enrollment_dates_conflict' && json.details) {
      var d = json.details;
      var rows = d.members.map(function (m) { return 'Kursanti #' + m.student_id + ': ' + m.start_date + ' → ' + m.end_date; });
      var ok = await window.qtaConfirm({title:'Ndryshojnë datat individuale të ' + rows.length + ' kursantëve',
        message:rows.join('\n') + '\nPeriudha e re: ' + d.start_date + ' → ' + d.end_date + '.\nDatat individuale do të ndryshojnë. Ky veprim nuk këshillohet nëse dokumentet ose certifikatat janë lëshuar tashmë.',
        confirm:'Po, pajto datat individuale',danger:true});
      if (ok) return retry(Object.assign({}, payload, {enrollment_resolution:'adopt_group_dates',enrollment_baseline:d.baseline}));
      var cancelled = new Error(''); cancelled.cancelled = true; throw cancelled;
    }
    if (['enrollment_dates_conflict','stale_enrollment'].includes(json.code) && window.QtaEnrollment && json.details && json.details.enrollment) {
      return new Promise(function(resolve,reject){
        var sid=Number(json.details.enrollment.student_id);
        window.QtaEnrollment.showConflict(json,sid,Number(json.details.group.id),document.activeElement,{
          cancel:function(){var cancelled=new Error('');cancelled.cancelled=true;reject(cancelled);},
          submit:async function(options){
            var reviews=Object.assign({},payload.enrollment_resolutions || {});reviews[sid]=options;
            var result=await retry(Object.assign({},payload,{enrollment_resolutions:reviews}));
            resolve(result);return result;
          }
        });
      });
    }
    return null;
  };
  async function legacy(payload) {
    var response=await window.qtaFetch.response('groups.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
    var json=await response.json();if(json.ok) return json;
    var reviewed=await window.qtaReviewEnrollment(json,payload,legacy);if(reviewed) return reviewed;
    throw new Error(json.error || 'Ndryshimi nuk u ruajt.');
  }
  window.qtaSubmitEnrollmentGroupForm=async function(form) {
    var button=form.querySelector('[type="submit"]');if(button && button.disabled) return;
    if(button) button.disabled=true;
    try {var json=await legacy(Object.fromEntries(new FormData(form)));location.assign(json.redirect);}
    catch(err){if(!err.cancelled) window.qtaToast(err.message,'danger',null,{autohide:false});}
    finally {if(button) button.disabled=false;}
  };
})();
