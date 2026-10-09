<?php
declare(strict_types=1);

/** Persistent course enrollment and reconciliation. Callers own authorization.
 * Dates and result provenance belong to student_course_plans. Scores belong to its
 * ID; group_id on scores is a maintained compatibility projection, never identity.
 * Exam: membership while assigned, enrollment while ungrouped. No JSON results.
 */
require_once __DIR__ . '/curriculum.php';
require_once __DIR__ . '/group_members.php';

function qta_enrollment_dates($start, $end): array
{
  $s = qta_parse_date_input($start); $e = qta_parse_date_input($end);
  if (!$s || !$e) throw new QtaUserError('Plotëso datën e fillimit dhe të mbarimit të kursantit.', ['code'=>'enrollment_dates_required']);
  if ($s > $e) throw new QtaUserError('Fillimi i kursit nuk mund të jetë pas mbarimit.', ['code'=>'enrollment_dates_invalid']);
  return [$s,$e];
}

function qta_enrollment_exam($raw, string $end, bool $hasResult, ?string $groupEnd = null): ?string
{
  $exam = qta_parse_date_input($raw);
  if (trim((string)$raw) !== '' && !$exam) throw new QtaUserError('Shkruaj një datë provimi të vlefshme.', ['code'=>'exam_invalid']);
  if (!$exam && $hasResult) throw new QtaUserError('Pikët kërkojnë datën e provimit.', ['code'=>'exam_required_for_result']);
  if ($exam && $exam < max($end,$groupEnd ?? $end)) throw new QtaUserError('Provimi duhet të jetë në ose pas mbarimit të kursantit dhe grupit.', ['code'=>'exam_before_end']);
  return $exam;
}

function qta_enrollment_course(PDO $pdo, int $courseId, bool $lock = false): array
{
  $course = qta_course_find($pdo,$courseId,$lock);
  if (!$course) throw new QtaUserError('Kursi nuk u gjet.', ['code'=>'not_found']);
  $modules = qta_course_modules($pdo,$courseId);
  $check = qta_course_check($course,$modules);
  return ['course'=>$course,'ready'=>$check['ready'],'issues'=>$check['issues'],
    'modules'=>$check['ready'] ? array_map(static fn($m)=>['id'=>$m['id'],'seq'=>$m['position'],'title'=>$m['title'],'hours'=>$m['hours']],$modules) : []];
}

function qta_enrollment_find(PDO $pdo, int $studentId, int $courseId, bool $lock = false): ?array
{
  $q=$pdo->prepare('SELECT * FROM student_course_plans WHERE student_id=? AND course_id=?'.($lock?' FOR UPDATE':''));
  $q->execute([$studentId,$courseId]); return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function qta_enrollment_lock_student(PDO $pdo, int $studentId): void
{
  $q=$pdo->prepare('SELECT id FROM students WHERE id=? FOR UPDATE');$q->execute([$studentId]);
  if(!$q->fetchColumn()) throw new QtaUserError('Kursanti nuk u gjet.', ['code'=>'not_found']);
}

function qta_enrollment_modules(PDO $pdo, int $id): array
{
  $q=$pdo->prepare('SELECT module_id AS id,seq,title,hours FROM enrollment_result_modules WHERE enrollment_id=? ORDER BY seq');
  $q->execute([$id]);
  return array_map(static function($m){ foreach(['id','seq','hours'] as $k) $m[$k]=(int)$m[$k]; return $m; },$q->fetchAll(PDO::FETCH_ASSOC));
}

function qta_enrollment_freeze(PDO $pdo, int $id, array $modules): void
{
  $q=$pdo->prepare('INSERT INTO enrollment_result_modules(enrollment_id,module_id,seq,title,hours) VALUES(?,?,?,?,?)');
  foreach($modules as $m) $q->execute([$id,$m['id'],$m['seq'],$m['title'],$m['hours']]);
}

/** Complete reader for student cards, future certificates and pre-group editing. */
function qta_enrollment_snapshot(PDO $pdo, int $studentId, int $courseId): ?array
{
  $e=qta_enrollment_find($pdo,$studentId,$courseId); if(!$e) return null;
  if($e['manual_final_score']!==null) $e['manual_final_score']=qta_score_db(qta_score_from_db($e['manual_final_score']));
  $modules=qta_enrollment_modules($pdo,(int)$e['id']);
  $q=$pdo->prepare('SELECT module_id,score FROM enrollment_module_scores WHERE enrollment_id=? ORDER BY module_id');
  $q->execute([$e['id']]); $scores=array_map(static fn($v)=>qta_score_db(qta_score_from_db($v)),$q->fetchAll(PDO::FETCH_KEY_PAIR));
  $exam=$e['exam_date']; $legacy=qta_score_from_db($e['legacy_result']); $group=null;
  if($e['group_id']!==null) {
    $q=$pdo->prepare('SELECT g.*,m.exam_date AS member_exam,m.final_score,m.legacy_final_score FROM course_groups g JOIN course_group_students m ON m.group_id=g.id WHERE g.id=? AND m.student_id=?');
    $q->execute([$e['group_id'],$studentId]); $group=$q->fetch(PDO::FETCH_ASSOC) ?: null;
    if(!$group) throw new QtaUserError('Anëtarësimi ndryshoi. Rifresko të dhënat.', ['code'=>'stale_enrollment']);
    $exam=$group['member_exam'];
    $modules=qta_results_module_sets($pdo,[(int)$group['id']=>$group])[(int)$group['id']];
    $legacy=qta_score_from_db($group['legacy_final_score']) ?? $legacy ?? (!$scores ? qta_score_from_db($group['final_score']) : null);
  }
  $result=qta_results_compute(array_column($modules,'id'),array_map('qta_score_from_db',$scores),$legacy);
  if($e['result_source']==='manual') $result=array_replace($result,['mode'=>'manual','final'=>qta_score_from_db($e['manual_final_score']),'complete'=>true]);
  $result['final']=$result['final']===null?null:qta_score_db($result['final']);
  $e['exam_date']=$exam;
  return ['enrollment'=>$e,'course'=>qta_course_find($pdo,$courseId),'group'=>$group,'modules'=>$modules,'scores'=>(object)$scores,'result'=>$result,
    'baseline'=>hash('sha256',json_encode([$e,$modules,$scores,$group],JSON_UNESCAPED_UNICODE))];
}

/** Save details atomically; immutable module IDs protect pre-group provenance. */
function qta_enrollment_save(PDO $pdo, int $studentId, int $courseId, array $in): array
{
  return qta_tx($pdo,function() use($pdo,$studentId,$courseId,$in){
    $meta=qta_enrollment_course($pdo,$courseId,true);
    qta_enrollment_lock_student($pdo,$studentId);
    $e=qta_enrollment_find($pdo,$studentId,$courseId,true);
    if($e && isset($in['baseline']) && $in['baseline']!==qta_enrollment_snapshot($pdo,$studentId,$courseId)['baseline']) throw new QtaUserError('Regjistrimi ndryshoi ndërkohë. Rifreskoje.', ['code'=>'stale_enrollment']);
    [$start,$end]=qta_enrollment_dates($in['start_date']??null,$in['end_date']??null);
    if($e && $e['group_id']!==null) return qta_enrollment_save_assigned($pdo,$e,$meta,$start,$end,$in);
    $mods=$e?qta_enrollment_modules($pdo,(int)$e['id']):[];
    $manualMode=($e && $e['result_source']==='manual') || (!$mods && !$meta['ready']);
    if(!empty($in['use_modules']) && $manualMode) {
      if(!$meta['ready']) throw new QtaUserError('Kursi ende nuk është gati për pikët sipas moduleve.', ['code'=>'course_not_ready']);
      if(empty($in['force'])) throw new QtaConfirmNeeded('Rezultati do të llogaritet nga modulet','Rezultati manual ruhet si prejardhje. Rezultati zyrtar do të ekzistojë vetëm pasi çdo modul të ketë pikë.','Po, përdor modulet');
      $manualMode=false;
    }
    if(!$mods && !$manualMode) $mods=$meta['modules'];
    $manual=$manualMode?qta_score_parse($in['manual_final_score']??null):null;
    $raw=$in['scores']??[]; if(!is_array($raw)) throw new QtaUserError('Pikët nuk u lexuan.', ['code'=>'score_format']);
    $scores=[]; $valid=array_column($mods,'id');
    foreach($raw as $mid=>$v) {
      $h=qta_score_parse($v);
      if($h===null) continue;
      if($manualMode || !in_array((int)$mid,$valid,true)) throw new QtaUserError('Moduli nuk i përket kopjes së regjistrimit.', ['code'=>'result_module_mismatch']);
      $scores[(int)$mid]=$h;
    }
    $effective=$e?(array)qta_enrollment_snapshot($pdo,$studentId,$courseId)['scores']:[];
    foreach($raw as $mid=>$v) { if(qta_score_parse($v)===null) unset($effective[$mid]); else $effective[$mid]=$v; }
    $exam=qta_enrollment_exam($in['exam_date']??null,$end,$manual!==null || (bool)$effective);
    if(!$e) {
      qta_members_assert_can_join($pdo,[$studentId],$courseId);
      $dup=$pdo->prepare("SELECT 1 FROM student_course_plans e JOIN students s ON s.id=e.student_id JOIN students own ON own.person_id=s.person_id WHERE own.id=? AND e.course_id=? AND e.status<>'cancelled' LIMIT 1");
      $dup->execute([$studentId,$courseId]); if($dup->fetchColumn()) throw new QtaUserError('Ky person ka tashmë një regjistrim për këtë kurs.');
      $pdo->prepare("INSERT INTO student_course_plans(student_id,course_id,status,selected_by,start_date,end_date,exam_date) VALUES(?,?,'planned',@audit_user_id,?,?,?)")->execute([$studentId,$courseId,$start,$end,$exam]);
      $e=qta_enrollment_find($pdo,$studentId,$courseId,true);
    }
    if(!qta_enrollment_modules($pdo,(int)$e['id']) && $mods) qta_enrollment_freeze($pdo,(int)$e['id'],$mods);
    // Remove only explicitly submitted scores. Exam cannot be cleared ahead of deletion.
    $del=$pdo->prepare('DELETE FROM enrollment_module_scores WHERE enrollment_id=? AND module_id=?');
    foreach($raw as $mid=>$v) if(qta_score_parse($v)===null) $del->execute([$e['id'],(int)$mid]);
    $source=$manual!==null?'manual':(!$manualMode && $mods?'modules':'none');
    $pdo->prepare('UPDATE student_course_plans SET start_date=?,end_date=?,exam_date=?,manual_final_score=COALESCE(?,manual_final_score),result_source=?,status=\'planned\' WHERE id=?')
      ->execute([$start,$end,$exam,$manual===null?null:qta_score_db($manual),$source,$e['id']]);
    $up=$pdo->prepare('INSERT INTO enrollment_module_scores(enrollment_id,student_id,group_id,module_id,score) VALUES(?,?,NULL,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score)');
    foreach($scores as $mid=>$h) $up->execute([$e['id'],$studentId,$mid,qta_score_db($h)]);
    return qta_enrollment_snapshot($pdo,$studentId,$courseId);
  });
}

/** Assigned details share the results engine; group periods remain a reviewed operation. */
function qta_enrollment_save_assigned(PDO $pdo, array $e, array $meta, string $start, string $end, array $in): array
{
  if($start!==$e['start_date'] || $end!==$e['end_date']) throw new QtaUserError('Ndrysho periudhën te grupi që të kontrollohen të gjithë kursantët.', ['code'=>'enrollment_dates_conflict']);
  $sid=(int)$e['student_id']; $cid=(int)$e['course_id']; $gid=(int)$e['group_id'];
  $s=qta_enrollment_snapshot($pdo,$sid,$cid);
  $q=$pdo->prepare('SELECT id FROM course_groups WHERE id=? FOR UPDATE'); $q->execute([$gid]);
  if((int)$s['group']['is_completed'] && empty($in['force'])) throw new QtaConfirmNeeded('Grupi është i mbyllur','Rezultati ose provimi individual do të ndryshojë. Dokumentet mund të jenë lëshuar.','Po, ndryshoje');
  $manualMode=$e['result_source']==='manual' || (!$meta['ready'] && $s['group']['model']==='legacy' && count((array)$s['scores'])===0);
  if(!empty($in['use_modules'])) {
    if(!$meta['ready'] && !$s['modules']) throw new QtaUserError('Kursi ende nuk është gati.', ['code'=>'course_not_ready']);
    if(empty($in['force'])) throw new QtaConfirmNeeded('Përdor pikët sipas moduleve?','Rezultati manual ruhet si prejardhje. Rezultati zyrtar pret plotësimin e çdo moduli.','Po, përdor modulet');
    $manualMode=false;
  }
  $raw=$in['scores']??[]; if(!is_array($raw)) throw new QtaUserError('Pikët nuk u lexuan.');
  $effective=(array)$s['scores']; $cells=[];
  foreach($raw as $mid=>$v) {
    $h=qta_score_parse($v); if(!in_array((int)$mid,array_column($s['modules'],'id'),true)) throw new QtaUserError('Moduli nuk i përket grupit.', ['code'=>'result_module_mismatch']);
    if($h===null) unset($effective[$mid]); else $effective[$mid]=$v;
    $cells[]=['student_id'=>$sid,'module_id'=>(int)$mid,'from'=>((array)$s['scores'])[$mid]??null,'to'=>$v];
  }
  $manual=$manualMode?qta_score_parse($in['manual_final_score']??null):null;
  $exam=qta_enrollment_exam($in['exam_date']??null,$end,$manual!==null || (bool)$effective,$s['group']['end_date']);
  if($exam!==null) $pdo->prepare('UPDATE course_group_students SET exam_date=? WHERE group_id=? AND student_id=?')->execute([$exam,$gid,$sid]);
  if($manualMode) {
    $pdo->prepare('UPDATE student_course_plans SET manual_final_score=COALESCE(?,manual_final_score),result_source=? WHERE id=?')->execute([$manual===null?null:qta_score_db($manual),$manual===null?'none':'manual',$e['id']]);
    qta_results_write($pdo,$gid,[$sid=>['final'=>$manual,'legacy'=>null]]);
  }
  if(!$manualMode && !empty($in['use_modules'])) {
    // Conversion is a state transition even when every submitted cell is blank.
    // Keep the manual value as provenance; clear its official projection.
    $pdo->prepare("UPDATE student_course_plans SET result_source='modules' WHERE id=?")->execute([$e['id']]);
    qta_results_write($pdo,$gid,[$sid=>['final'=>null,'legacy'=>null]]);
  }
  // A non-null exam can be changed before score writes; clearing follows score deletion.
  if(!$manualMode && $cells) qta_results_save($pdo,$gid,$cells,$in);
  if($exam===null) $pdo->prepare('UPDATE course_group_students SET exam_date=NULL WHERE group_id=? AND student_id=?')->execute([$gid,$sid]);
  return qta_enrollment_snapshot($pdo,$sid,$cid);
}

/** Validate the actual stored schedule before suggesting or joining it. */
function qta_enrollment_group_valid(PDO $pdo, array $g): bool
{
  if($g['start_date']>$g['end_date']) return false;
  if($g['model']==='legacy') return true;
  require_once __DIR__.'/lesson_groups.php';
  try {
    $full=qta_lg_require($pdo,(int)$g['id']); $topics=qta_lg_topics($pdo,(int)$g['id']);
    if(qta_lg_is_fixed($full)) qta_lg_assert_stored_fixed($pdo,(int)$g['id'],$topics,$g['start_date'],$g['end_date']);
    else qta_lg_assert_stored($pdo,(int)$g['id'],$topics,(int)$full['daily_hours'],qta_lg_rule_map(qta_lg_rules($pdo,(int)$g['id'])),$g['start_date']);
    return true;
  } catch(QtaUserError $e) { return false; }
}

/** Indexed course search; deterministic boundary ranking, capped before validation. */
function qta_enrollment_candidates(PDO $pdo, array $e): array
{
  require_once __DIR__.'/lesson_groups.php';
  $q=$pdo->prepare("SELECT g.*,s.schedule_mode,s.daily_hours,s.course_hours,s.teaching_days,COUNT(m.student_id) AS members,
      ABS(DATEDIFF(g.start_date,?))+ABS(DATEDIFF(g.end_date,?)) AS difference_days,
      (g.start_date=? AND g.end_date=?) AS exact_dates,
      (g.start_date>=? AND g.end_date<=?) AS within_dates
    FROM course_groups g LEFT JOIN group_schedules s ON s.group_id=g.id
    LEFT JOIN course_group_students m ON m.group_id=g.id WHERE g.course_id=?
    GROUP BY g.id HAVING members<10
    ORDER BY exact_dates DESC,within_dates DESC,difference_days,g.is_completed,g.id LIMIT 30");
  $q->execute([$e['start_date'],$e['end_date'],$e['start_date'],$e['end_date'],$e['start_date'],$e['end_date'],$e['course_id']]);
  $groups=$q->fetchAll(PDO::FETCH_ASSOC);
  $ids=array_column(array_filter($groups,fn($g)=>$g['model']==='scheduled'),'id');
  if(!$ids) return array_values(array_filter($groups,fn($g)=>$g['start_date']<=$g['end_date']));
  $ph=implode(',',array_fill(0,count($ids),'?'));
  $all=[];
  // Constant query count regardless of the number of candidate groups.
  foreach(['topics'=>'SELECT * FROM group_schedule_topics','days'=>'SELECT * FROM group_schedule_days',
           'slots'=>'SELECT * FROM group_schedule_slots','fixed'=>'SELECT * FROM group_fixed_days',
           'rules'=>'SELECT * FROM group_day_rules'] as $key=>$sql) {
    $st=$pdo->prepare($sql." WHERE group_id IN ($ph)"); $st->execute($ids);
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row) $all[$key][$row['group_id']][]=$row;
  }
  return array_values(array_filter($groups,static function($g) use($all){
    if($g['start_date']>$g['end_date']) return false;
    if($g['model']==='legacy') return true;
    $id=$g['id']; $topics=[]; $days=[]; $fixed=[]; $rules=[];
    foreach($all['topics'][$id]??[] as $t) {
      $t['hours']=(int)$t['topic_hours'];
      foreach(['seq','module_seq','module_hours','topic_seq'] as $k) $t[$k]=(int)$t[$k];
      if($t['source_module_id']===null) return false;
      $topics[]=$t;
    }
    usort($topics,fn($a,$b)=>$a['seq']<=>$b['seq']);
    foreach($all['days'][$id]??[] as $d) $days[(int)$d['day_seq']]=['seq'=>(int)$d['day_seq'],'date'=>$d['lesson_date'],'hours'=>(int)$d['hours'],'slots'=>[]];
    ksort($days);
    $slots=$all['slots'][$id]??[]; usort($slots,fn($a,$b)=>[$a['day_seq'],$a['slot_seq']]<=>[$b['day_seq'],$b['slot_seq']]);
    foreach($slots as $s) { if(!isset($days[$s['day_seq']])) return false; $days[$s['day_seq']]['slots'][]=['seq'=>(int)$s['slot_seq'],'topic'=>(int)$s['topic_seq'],'hours'=>(int)$s['hours']]; }
    $days=array_values($days);
    foreach($all['fixed'][$id]??[] as $d) $fixed[$d['lesson_date']]=(int)$d['hours'];
    ksort($fixed);
    foreach($all['rules'][$id]??[] as $r) $rules[$r['rule_date']]=$r['hours']===null?null:(int)$r['hours'];
    if(!$days || !$topics || (int)$g['course_hours']!==array_sum(array_column($topics,'hours')) || (int)$g['teaching_days']!==count($days)) return false;
    $plan=['start_date'=>$days[0]['date'],'end_date'=>$days[count($days)-1]['date'],'days'=>$days];
    if($plan['start_date']!==$g['start_date'] || $plan['end_date']!==$g['end_date']) return false;
    if($g['schedule_mode']==='fixed_range') return $g['daily_hours']===null && !qta_sched_verify_fixed_range($topics,$plan,$g['start_date'],$g['end_date'],$fixed);
    return $g['schedule_mode']==='calculated' && !qta_sched_verify($topics,$plan,(int)$g['daily_hours'],$rules,$g['start_date']);
  }));
}

function qta_enrollment_conflict(PDO $pdo, array $e, array $g, string $baseline): QtaUserError
{
  return new QtaUserError('Datat e kursantit nuk përputhen me grupin. Zgjidh si dëshiron të vazhdosh.',
    ['code'=>'enrollment_dates_conflict','details'=>['enrollment'=>$e,'group'=>$g,'baseline'=>$baseline,'candidates'=>qta_enrollment_candidates($pdo,$e)]]);
}

function qta_enrollment_assignment_baseline(array $snapshot, array $g): string
{
  return hash('sha256',$snapshot['baseline'].json_encode($g));
}

/** Review a not-yet-created target without depending on its future auto-increment ID.
 * Called inside the creation transaction, before any group or membership is written.
 */
function qta_enrollment_prepare_new_group(PDO $pdo, int $studentId, array $target, array $reviews = []): void
{
  qta_enrollment_lock_student($pdo,$studentId);
  $e=qta_enrollment_find($pdo,$studentId,(int)$target['course_id'],true);
  if(!$e || $e['start_date']===null) return;
  if($e['start_date']===$target['start_date'] && $e['end_date']===$target['end_date']) return;
  $snapshot=qta_enrollment_snapshot($pdo,$studentId,(int)$target['course_id']);
  $baseline=qta_enrollment_assignment_baseline($snapshot,$target);
  $review=$reviews[$studentId]??[];
  if(($review['resolution']??'')!=='adopt_group_dates' || empty($review['force'])) {
    $error=qta_enrollment_conflict($pdo,$snapshot['enrollment'],$target,$baseline);
    $error->data['details']['creation']=true;
    throw $error;
  }
  if(($review['baseline']??'')!==$baseline) throw new QtaUserError('Të dhënat e kursantit ose grupit të ri ndryshuan. Kontrolloji sërish.',
    ['code'=>'stale_enrollment','details'=>['enrollment'=>$snapshot['enrollment'],'group'=>$target,'baseline'=>$baseline,'creation'=>true,'candidates'=>qta_enrollment_candidates($pdo,$e)]]);
  $exam=qta_enrollment_exam($review['exam_date']??$snapshot['enrollment']['exam_date'],$target['end_date'],
    $snapshot['result']['final']!==null || (bool)(array)$snapshot['scores'],$target['end_date']);
  $pdo->prepare('UPDATE student_course_plans SET start_date=?,end_date=?,exam_date=?,note=? WHERE id=?')->execute([
    $target['start_date'],$target['end_date'],$exam,
    'Datat u pajtuan gjatë krijimit të grupit: '.$e['start_date'].'–'.$e['end_date'].' → '.$target['start_date'].'–'.$target['end_date'],$e['id']]);
}

/** One reusable assignment boundary for students, AMZË and both registries. */
function qta_enrollment_assign(PDO $pdo, int $studentId, int $groupId, array $opts=[]): array
{
  return qta_tx($pdo,function() use($pdo,$studentId,$groupId,$opts){
    // Course lock serializes exact-period reuse and course edits. Group lock protects capacity.
    $q=$pdo->prepare('SELECT course_id FROM course_groups WHERE id=?'); $q->execute([$groupId]); $cid=(int)$q->fetchColumn();
    if(!$cid) throw new QtaUserError('Grupi nuk u gjet.', ['code'=>'group_candidate_changed']);
    qta_course_find($pdo,$cid,true);
    qta_enrollment_lock_student($pdo,$studentId);
    $e=qta_enrollment_find($pdo,$studentId,$cid,true);
    if($e && $e['status']==='cancelled') throw new QtaUserError('Regjistrimi është anuluar. Rihap të dhënat e kursit përpara caktimit.', ['code'=>'enrollment_cancelled']);
    $q=$pdo->prepare('SELECT g.*,s.revision AS schedule_revision,(SELECT COUNT(*) FROM course_group_students m WHERE m.group_id=g.id) AS members FROM course_groups g LEFT JOIN group_schedules s ON s.group_id=g.id WHERE g.id=? FOR UPDATE');
    $q->execute([$groupId]); $g=$q->fetch(PDO::FETCH_ASSOC);
    if(!$g || (int)$g['course_id']!==$cid) throw new QtaUserError('Grupi ndryshoi ndërkohë.', ['code'=>'group_candidate_changed']);
    $q=$pdo->prepare('SELECT student_id FROM course_group_students WHERE group_id=? FOR UPDATE');$q->execute([$groupId]);
    $g['members']=count($q->fetchAll(PDO::FETCH_COLUMN));
    if((int)$g['members']>=10) throw new QtaUserError('Grupi është plot: 10 nga 10 kursantë.', ['code'=>'group_full']);
    qta_members_assert_can_join($pdo,[$studentId],$cid);
    if(!qta_enrollment_group_valid($pdo,$g)) throw new QtaUserError('Orari i grupit nuk është i vlefshëm.', ['code'=>'schedule_not_feasible']);
    $snap=$e?qta_enrollment_snapshot($pdo,$studentId,$cid):null;
    $baseline=$snap?qta_enrollment_assignment_baseline($snap,$g):'';
    if((isset($opts['baseline']) || ($opts['resolution']??'')==='adopt_group_dates') && ($opts['baseline']??'')!==$baseline) throw new QtaUserError('Regjistrimi ose grupi ndryshoi ndërkohë. Kontrollo datat sërish.', ['code'=>'stale_enrollment','details'=>['enrollment'=>$e,'group'=>$g,'baseline'=>$baseline,'candidates'=>$e?qta_enrollment_candidates($pdo,$e):[]]]);
    if($e && $e['start_date']!==null && ($e['start_date']!==$g['start_date'] || $e['end_date']!==$g['end_date'])) {
      if(($opts['resolution']??'')!=='adopt_group_dates' || empty($opts['force'])) throw qta_enrollment_conflict($pdo,$e,$g,$baseline);
    }
    $target=qta_results_module_sets($pdo,[(int)$g['id']=>$g])[(int)$g['id']];
    if($snap && (array)$snap['scores']) {
      $norm=static fn($mods)=>array_map(static fn($m)=>[(int)$m['id'],(int)$m['hours'],(string)$m['title']],$mods);
      if($norm($snap['modules'])!==$norm($target)) throw new QtaUserError('Kopja e moduleve të regjistrimit ndryshon nga ajo e grupit. Pikët nuk mund të lidhen automatikisht.', ['code'=>'result_module_mismatch']);
    }
    $exam=qta_enrollment_exam($opts['exam_date']??($snap['enrollment']['exam_date']??null),$g['end_date'], $snap && ($snap['result']['final']!==null || (bool)(array)$snap['scores']),$g['end_date']);
    if($e) $pdo->prepare('UPDATE student_course_plans SET start_date=?,end_date=?,exam_date=?,note=? WHERE id=?')->execute([$g['start_date'],$g['end_date'],$exam,
      ($opts['resolution']??'')==='adopt_group_dates'?'Datat u pajtuan me Grupin #'.$groupId.': '.$e['start_date'].'–'.$e['end_date'].' → '.$g['start_date'].'–'.$g['end_date']:$e['note'],$e['id']]);
    $pdo->prepare('INSERT INTO course_group_students(group_id,student_id,exam_date) VALUES(?,?,?)')->execute([$groupId,$studentId,$exam]);
    $e=qta_enrollment_find($pdo,$studentId,$cid,true);
    $pdo->exec('SET @qta_enrollment_sync=1');
    try { $pdo->prepare('UPDATE enrollment_module_scores SET group_id=? WHERE enrollment_id=?')->execute([$groupId,$e['id']]); }
    finally { $pdo->exec('SET @qta_enrollment_sync=NULL'); }
    $pdo->prepare('UPDATE student_course_plans SET exam_date=NULL WHERE id=?')->execute([$e['id']]);
    $fresh=qta_enrollment_snapshot($pdo,$studentId,$cid);
    qta_results_write($pdo,$groupId,[$studentId=>['final'=>qta_score_from_db($fresh['result']['final']),'legacy'=>null]]);
    return qta_enrollment_snapshot($pdo,$studentId,$cid);
  });
}

/** Exact-date reuse/create is serialized by the course row, including the new schedule. */
function qta_enrollment_use_dates(PDO $pdo, int $studentId, int $courseId, array $opts=[]): array
{
  require_once __DIR__.'/lesson_groups.php';
  return qta_tx($pdo,function() use($pdo,$studentId,$courseId,$opts){
    qta_course_find($pdo,$courseId,true);
    $e=qta_enrollment_find($pdo,$studentId,$courseId,true);
    if(!$e) throw new QtaUserError('Regjistrimi nuk u gjet.', ['code'=>'not_found']);
    if(isset($opts['enrollment_baseline']) && $opts['enrollment_baseline']!==qta_enrollment_snapshot($pdo,$studentId,$courseId)['baseline']) throw new QtaUserError('Regjistrimi ndryshoi. Rifreskoje.', ['code'=>'stale_enrollment']);
    [$start,$end]=qta_enrollment_dates($e['start_date'],$e['end_date']);
    foreach(qta_enrollment_candidates($pdo,$e) as $g) if($g['start_date']===$start && $g['end_date']===$end) return qta_enrollment_assign($pdo,$studentId,(int)$g['id']);
    $res=qta_lg_create($pdo,['course_id'=>$courseId,'start_date'=>$start,'end_date'=>$end,'schedule_mode'=>'fixed_range']);
    return qta_enrollment_assign($pdo,$studentId,(int)$res['groups'][0]['group_id']);
  });
}

/** Preflight collects every failure. A batch has one transaction and no partial commit. */
function qta_enrollment_batch(PDO $pdo, array $ids, int $groupId, bool $preview=false): array
{
  $ids=array_values(array_unique(array_map('intval',$ids))); sort($ids);
  if(!$ids || count($ids)>10) throw new QtaUserError('Zgjidh nga 1 deri në 10 kursantë.', ['code'=>'bad_request']);
  $own=!$pdo->inTransaction(); if($own) $pdo->beginTransaction();
  try {
    $q=$pdo->prepare('SELECT course_id FROM course_groups WHERE id=?'); $q->execute([$groupId]);
    qta_course_find($pdo,(int)$q->fetchColumn(),true);
    $q=$pdo->prepare('SELECT id FROM course_groups WHERE id=? FOR UPDATE'); $q->execute([$groupId]);
    $q=$pdo->prepare('SELECT student_id FROM course_group_students WHERE group_id=? FOR UPDATE'); $q->execute([$groupId]);
    $available=max(0,10-count($q->fetchAll(PDO::FETCH_COLUMN)));
    $errors=[]; $matched=0;
    foreach($ids as $sid) {
      $pdo->exec('SAVEPOINT enrollment_preflight');
      try { qta_enrollment_assign($pdo,$sid,$groupId); $matched++; }
      catch(QtaUserError $e) { $errors[]=['student_id'=>$sid,'code'=>$e->data['code']??'invalid','message'=>$e->getMessage(),'details'=>$e->data['details']??null]; }
      $pdo->exec('ROLLBACK TO SAVEPOINT enrollment_preflight');
    }
    if($matched>$available) $errors[]=['student_id'=>null,'code'=>'group_full','message'=>'Përzgjedhja ka '.$matched.' kursantë të vlefshëm, por grupi ka vetëm '.$available.' vende të lira.'];
    $summary=['available'=>$available,'matched'=>$matched,'conflicts'=>count(array_filter($errors,fn($e)=>$e['code']==='enrollment_dates_conflict')),'blocked'=>count(array_filter($errors,fn($e)=>$e['code']!=='enrollment_dates_conflict')),'errors'=>$errors];
    if($preview) { if($own) $pdo->rollBack(); return $summary; }
    if($errors) throw new QtaUserError('Asnjë caktim nuk u ruajt. Zgjidh konfliktet e listuara së pari.', ['code'=>'batch_conflict','details'=>$summary]);
    foreach($ids as $sid) qta_enrollment_assign($pdo,$sid,$groupId);
    if($own) $pdo->commit(); return $summary;
  } catch(Throwable $e) { if($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** Group changes require a reviewed list; force alone never reconciles dates. */
function qta_enrollment_group_period(PDO $pdo, int $groupId, string $start, string $end, array $opts=[]): void
{
  $q=$pdo->prepare('SELECT e.id,e.student_id,e.start_date,e.end_date,e.revision,m.exam_date FROM student_course_plans e JOIN course_group_students m ON m.student_id=e.student_id AND m.group_id=e.group_id WHERE e.group_id=? ORDER BY e.id FOR UPDATE');
  $q->execute([$groupId]); $members=$q->fetchAll(PDO::FETCH_ASSOC);
  $impact=array_values(array_filter($members,fn($m)=>$m['start_date']!==$start || $m['end_date']!==$end)); if(!$impact) return;
  $baseline=hash('sha256',json_encode([$start,$end,$members]));
  if(($opts['enrollment_resolution']??'')!=='adopt_group_dates' || ($opts['enrollment_baseline']??'')!==$baseline) throw new QtaUserError('Periudha e re ndryshon datat individuale të kursantëve. Kontrollo ndikimin dhe konfirmo pajtimin.', ['code'=>'group_enrollment_dates_conflict','details'=>['members'=>$impact,'start_date'=>$start,'end_date'=>$end,'baseline'=>$baseline]]);
  foreach($members as $m) qta_enrollment_exam($m['exam_date'],$end,false,$end);
  if(!empty($opts['dry_run'])) return;
  $pdo->exec('SET @qta_enrollment_sync=1');
  try { $pdo->prepare('UPDATE student_course_plans SET start_date=?,end_date=?,note=? WHERE group_id=?')->execute([$start,$end,'Datat individuale u pajtuan gjatë ndryshimit të Grupit #'.$groupId,$groupId]); }
  finally { $pdo->exec('SET @qta_enrollment_sync=NULL'); }
}

/** Course replacement retires the old enrollment instead of rewriting its identity. */
function qta_enrollment_replace_course(PDO $pdo, int $groupId, int $courseId, ?callable $write=null): void
{
  qta_tx($pdo,function() use($pdo,$groupId,$courseId,$write){
    qta_course_find($pdo,$courseId,true);
    $q=$pdo->prepare('SELECT * FROM course_groups WHERE id=? FOR UPDATE');$q->execute([$groupId]);$g=$q->fetch(PDO::FETCH_ASSOC);
    if(!$g) throw new QtaUserError('Grupi nuk u gjet.');
    if((int)$g['course_id']===$courseId) return;
    $q=$pdo->prepare('SELECT m.*,e.id AS enrollment_id,e.manual_final_score,e.start_date AS individual_start,e.end_date AS individual_end FROM course_group_students m JOIN student_course_plans e ON e.group_id=m.group_id AND e.student_id=m.student_id WHERE m.group_id=? FOR UPDATE');$q->execute([$groupId]);$members=$q->fetchAll(PDO::FETCH_ASSOC);
    if(qta_results_scored_students($pdo,$groupId) || array_filter($members,fn($m)=>$m['final_score']!==null || $m['legacy_final_score']!==null || $m['manual_final_score']!==null)) throw new QtaUserError('Regjistrimet kanë rezultate ose prejardhje rezultati. Kursi nuk mund të zëvendësohet.', ['code'=>'has_scores']);
    qta_members_assert_can_join($pdo,array_map('intval',array_column($members,'student_id')),$courseId,$groupId);
    foreach($members as $m) {
      if(qta_enrollment_find($pdo,(int)$m['student_id'],$courseId,true)) throw new QtaUserError('Kursanti ka regjistrim tjetër për kursin e ri.', ['code'=>'enrollment_course_conflict']);
      $pdo->prepare("UPDATE student_course_plans SET group_id=NULL,status='cancelled',exam_date=?,note=? WHERE id=?")
        ->execute([$m['exam_date'],'Kursi u zëvendësua te Grupi #'.$groupId,$m['enrollment_id']]);
    }
    if($write) $write(); else $pdo->prepare('UPDATE course_groups SET course_id=? WHERE id=?')->execute([$courseId,$groupId]);
    foreach($members as $m) $pdo->prepare("INSERT INTO student_course_plans(student_id,course_id,status,group_id,start_date,end_date,assigned_at) VALUES(?,?,'assigned',?,?,?,NOW())")->execute([$m['student_id'],$courseId,$groupId,$m['individual_start'],$m['individual_end']]);
  });
}
