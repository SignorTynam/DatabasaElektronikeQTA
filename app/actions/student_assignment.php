<?php
declare(strict_types=1);
require_once __DIR__.'/../shared/session.php';
qta_session_boot();
require_once __DIR__.'/database.php';
require_once __DIR__.'/../shared/staff_guard.php';
require_once __DIR__.'/../shared/enrollments.php';
$pdo=getPDO();
require_once __DIR__.'/inc/audit_bootstrap.php';
qta_audit_attach($pdo,isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:null);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') qta_json_out(['ok'=>false,'error'=>'Përdor formularin e kursantit.'],405);
qta_json_require_staff($pdo);
$data=qta_json_input(); qta_json_require_csrf($data);
$action=(string)($data['action']??'');
$sid=(int)($data['student_id']??0); $cid=(int)($data['course_id']??0); $gid=(int)($data['group_id']??0);
try {
  if($action==='course_details') qta_json_out(['ok'=>true]+qta_enrollment_course($pdo,$cid));
  if($action==='enrollment') qta_json_out(['ok'=>true,'snapshot'=>qta_enrollment_snapshot($pdo,$sid,$cid)]);
  if($action==='candidates') {
    $e=qta_enrollment_find($pdo,$sid,$cid); if(!$e) throw new QtaUserError('Regjistrimi nuk u gjet.');
    qta_json_out(['ok'=>true,'candidates'=>qta_enrollment_candidates($pdo,$e)]);
  }
  if($action==='preview_student_group') {
    require_once __DIR__.'/../shared/lesson_groups.php';
    $e=qta_enrollment_find($pdo,$sid,$cid); if(!$e) throw new QtaUserError('Regjistrimi nuk u gjet.');
    $exact=array_values(array_filter(qta_enrollment_candidates($pdo,$e),fn($g)=>$g['start_date']===$e['start_date'] && $g['end_date']===$e['end_date']));
    $preview=$exact ? null : qta_lg_preview_new($pdo,$cid,$e['start_date'],null,'fixed_range',$e['end_date']);
    qta_json_out(['ok'=>true,'exact'=>$exact,'preview'=>$preview,'enrollment_baseline'=>qta_enrollment_snapshot($pdo,$sid,$cid)['baseline']]);
  }
  qta_json_require_edit_mode();
  switch($action) {
    case 'assign_to_group': $result=qta_enrollment_assign($pdo,$sid,$gid,$data); break;
    case 'use_student_dates': $result=qta_enrollment_use_dates($pdo,$sid,$cid,$data); break;
    case 'set_student_plan': case 'save_enrollment': $result=qta_enrollment_save($pdo,$sid,$cid,$data); break;
    case 'batch_preflight': case 'batch_assign':
      $result=qta_enrollment_batch($pdo,(array)($data['student_ids']??[]),$gid,$action==='batch_preflight'); break;
    case 'remove_student_plan':
      $result=qta_tx($pdo,function() use($pdo,$sid,$cid){
        $e=qta_enrollment_find($pdo,$sid,$cid,true);
        if(!$e || $e['group_id']!==null) throw new QtaUserError('Hiqe kursantin nga grupi së pari.');
        $pdo->prepare("UPDATE student_course_plans SET status='cancelled' WHERE id=?")->execute([$e['id']]);
        return ['id'=>$e['id']];
      }); break;
    default: throw new QtaUserError('Veprimi nuk njihet.', ['code'=>'bad_request']);
  }
  qta_json_out(['ok'=>true,'result'=>$result,'message'=>'Ndryshimi u ruajt.']);
} catch(Throwable $e) { qta_json_fail($e); }
