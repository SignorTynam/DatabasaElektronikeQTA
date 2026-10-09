<?php
declare(strict_types=1);
require_once __DIR__.'/../../app/shared/database.php';
require_once __DIR__.'/../../app/shared/enrollments.php';
require_once __DIR__.'/../../app/shared/lesson_groups.php';
$enPdo=getPDO();
if(!str_contains((string)$enPdo->query('SELECT DATABASE()')->fetchColumn(),'test')) throw new RuntimeException('Isolated test schema required.');
function en_case(string $name,callable $fn): void {
  global $enPdo;
  t_case($name,function() use($enPdo,$fn){$enPdo->beginTransaction(); try{$fn($enPdo);}finally{if($enPdo->inTransaction())$enPdo->rollBack();}});
}
function en_course(PDO $p,bool $ready=true): int {
  $p->prepare('INSERT INTO courses(code,name,hours) VALUES(?,?,30)')->execute(['EN-'.bin2hex(random_bytes(4)),'Enrollment test']);$cid=(int)$p->lastInsertId();
  if($ready) foreach(['Word','Excel','PowerPoint'] as $title){$mid=qta_curriculum_add_module($p,$cid,$title,10);qta_curriculum_add_topic($p,$mid,$title,10);}
  return $cid;
}
function en_student(PDO $p): int { $n=random_int(30000000,90000000);return qta_amze_ensure_batch($p,[$n])[$n]; }
function en_details(array $extra=[]): array {return array_replace(['start_date'=>'2026-03-01','end_date'=>'2026-03-31','exam_date'=>'2026-04-05'], $extra);}
function en_one(PDO $p,string $sql,array $args=[]) {$q=$p->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function en_group(PDO $p,int $cid,string $start='2026-03-01',string $end='2026-03-31'): int {return (int)qta_lg_create($p,['course_id'=>$cid,'start_date'=>$start,'end_date'=>$end,'schedule_mode'=>'fixed_range'])['groups'][0]['group_id'];}
en_case('Enrollment: ready course before group, partial, zero, complete',function($p){
  $cid=en_course($p);$sid=en_student($p);$mods=qta_enrollment_course($p,$cid)['modules'];
  $s=qta_enrollment_save($p,$sid,$cid,en_details(['scores'=>[$mods[0]['id']=>'0',$mods[1]['id']=>'',''.$mods[2]['id']=>'85']]));
  t_eq(null,$s['enrollment']['group_id'],'persistent before membership');t_eq(2,$s['result']['scored'],'blank not zero');t_eq(null,$s['result']['final'],'incomplete official null');
  t_eq('0.00',((array)$s['scores'])[$mods[0]['id']],'zero stored');
  $s=qta_enrollment_save($p,$sid,$cid,en_details(['scores'=>[$mods[0]['id']=>'80',$mods[1]['id']=>'90',$mods[2]['id']=>'85']]));
  t_eq('85.00',$s['result']['final'],'acceptance average');
  $id=$s['enrollment']['id'];$gid=en_group($p,$cid);
  qta_enrollment_assign($p,$sid,$gid);
  $s=qta_enrollment_snapshot($p,$sid,$cid);t_eq($id,$s['enrollment']['id'],'identity preserved'); t_eq('85.00',$s['result']['final'],'scores preserved');
  t_eq(3,(int)en_one($p,'SELECT COUNT(*) FROM enrollment_module_scores WHERE enrollment_id=?',[$id]),'no duplicate score');
  t_eq('85.00',en_one($p,'SELECT final_score FROM course_group_students WHERE group_id=? AND student_id=?',[$gid,$sid]),'official projection');
  $p->prepare('DELETE FROM course_group_students WHERE group_id=? AND student_id=?')->execute([$gid,$sid]);
  $s=qta_enrollment_snapshot($p,$sid,$cid);t_eq(null,$s['enrollment']['group_id'],'removed membership');t_eq('85.00',$s['result']['final'],'removal retains results');t_eq('2026-04-05',$s['enrollment']['exam_date'],'removal retains exam');
});
en_case('Enrollment: draft manual fallback survives curriculum and assignment',function($p){
  $cid=en_course($p,false);$sid=en_student($p);
  $s=qta_enrollment_save($p,$sid,$cid,en_details(['manual_final_score'=>'78'])); t_eq('manual',$s['result']['mode'],'manual source');t_eq('78.00',$s['result']['final'],'manual value');t_eq(0,(int)en_one($p,'SELECT COUNT(*) FROM enrollment_module_scores WHERE enrollment_id=?',[$s['enrollment']['id']]),'no invented modules');
  foreach(['Word','Excel','PowerPoint'] as $title){$mid=qta_curriculum_add_module($p,$cid,$title,10);qta_curriculum_add_topic($p,$mid,$title,10);}
  $gid=en_group($p,$cid);qta_enrollment_assign($p,$sid,$gid);
  $sheet=qta_results_sheet($p,$gid);$m=$sheet['members'][0];t_eq('manual',$m['result']['mode'],'manual preserved in result dialog');t_eq(7800,$m['result']['final'],'manual result preserved');
  $cell=[['student_id'=>$sid,'module_id'=>$sheet['modules'][0]['id'],'from'=>null,'to'=>'0']];
  t_throws(QtaConfirmNeeded::class,fn()=>qta_results_save($p,$gid,$cell),'explicit conversion required');
  qta_results_save($p,$gid,$cell,['force'=>true]);
  $s=qta_enrollment_snapshot($p,$sid,$cid);t_eq('modules',$s['result']['mode'],'converted');t_eq(null,$s['result']['final'],'partial null after conversion');t_eq('78.00',$s['enrollment']['manual_final_score'],'provenance retained');
});
en_case('Enrollment: date conflicts, explicit resolution, stale and exam',function($p){
  $cid=en_course($p,false);$sid=en_student($p);qta_enrollment_save($p,$sid,$cid,en_details(['manual_final_score'=>'82']));
  // Existing legacy policy remains available for matching assignments.
  $p->prepare("INSERT INTO course_groups(course_id,start_date,end_date) VALUES(?,'2026-03-05','2026-04-06')")->execute([$cid]);$gid=(int)$p->lastInsertId();
  $e=t_throws(QtaUserError::class,fn()=>qta_enrollment_assign($p,$sid,$gid),'mismatch blocked');t_eq('enrollment_dates_conflict',$e->data['code'],'structured conflict');
  t_eq(0,(int)en_one($p,'SELECT COUNT(*) FROM course_group_students WHERE group_id=?',[$gid]),'no partial membership');
  $opts=['resolution'=>'adopt_group_dates','force'=>true,'baseline'=>$e->data['details']['baseline']];
  t_throws(QtaUserError::class,fn()=>qta_enrollment_assign($p,$sid,$gid,$opts),'invalid exam not shifted');
  t_eq('2026-03-31',qta_enrollment_snapshot($p,$sid,$cid)['enrollment']['end_date'],'rollback dates');
  $s=qta_enrollment_assign($p,$sid,$gid,$opts+['exam_date'=>'2026-04-06']);t_eq('2026-04-06',$s['enrollment']['end_date'],'adopted dates');t_eq('82.00',$s['result']['final'],'manual retained');
});
en_case('Enrollment: exact reuse, creation and schedule rollback',function($p){
  $cid=en_course($p);$gid=en_group($p,$cid);$sid=en_student($p);qta_enrollment_save($p,$sid,$cid,en_details());
  t_eq($gid,(int)qta_enrollment_use_dates($p,$sid,$cid)['enrollment']['group_id'],'exact group reused');
  $sid=en_student($p);qta_enrollment_save($p,$sid,$cid,en_details(['start_date'=>'2026-02-01','end_date'=>'2026-02-28']));
  $s=qta_enrollment_use_dates($p,$sid,$cid);t_ok((int)$s['enrollment']['group_id']!==$gid,'new group created');t_eq('fixed_range',qta_lg_find($p,(int)$s['enrollment']['group_id'])['schedule_mode'],'engine fixed range');
  $sid=en_student($p);qta_enrollment_save($p,$sid,$cid,en_details(['start_date'=>'2026-02-01','end_date'=>'2026-02-02']));
  $before=(int)en_one($p,'SELECT COUNT(*) FROM course_groups');t_throws(QtaUserError::class,fn()=>qta_enrollment_use_dates($p,$sid,$cid),'impossible schedule');t_eq($before,(int)en_one($p,'SELECT COUNT(*) FROM course_groups'),'no orphan group');
});
en_case('Enrollment: validation, batching and group period impact',function($p){
  $cid=en_course($p,false);$sid=en_student($p);
  foreach([en_details(['start_date'=>'2026-04-01']),en_details(['manual_final_score'=>'-1']),en_details(['manual_final_score'=>'101']),en_details(['manual_final_score'=>'0','exam_date'=>'']),en_details(['manual_final_score'=>'0','exam_date'=>'2026-03-30'])] as $in) t_throws(QtaUserError::class,fn()=>qta_enrollment_save($p,$sid,$cid,$in),'invalid enrollment rejected');
  t_eq(null,qta_enrollment_find($p,$sid,$cid),'no partial enrollment');
  $cid=en_course($p);$gid=en_group($p,$cid);$a=en_student($p);$b=en_student($p);qta_enrollment_save($p,$a,$cid,en_details());qta_enrollment_save($p,$b,$cid,en_details(['start_date'=>'2026-03-02']));
  $r=qta_enrollment_batch($p,[$a,$b],$gid,true);t_eq(1,$r['matched'],'batch matched');t_eq(1,$r['conflicts'],'batch conflict');
  t_throws(QtaUserError::class,fn()=>qta_enrollment_batch($p,[$a,$b],$gid),'atomic batch rejected');t_eq(0,(int)en_one($p,'SELECT COUNT(*) FROM course_group_students WHERE group_id=?',[$gid]),'zero partial assignments');
  qta_enrollment_assign($p,$a,$gid);
  $e=t_throws(QtaUserError::class,fn()=>qta_lg_change($p,$gid,['type'=>'fixed_range_settings','start_date'=>'2026-03-02','end_date'=>'2026-03-31'],['force'=>true]),'group change requires reconciliation');
  t_eq('group_enrollment_dates_conflict',$e->data['code'],'group structured conflict');
  $r=qta_lg_change($p,$gid,['type'=>'fixed_range_settings','start_date'=>'2026-03-02','end_date'=>'2026-03-31'],['force'=>true,'enrollment_resolution'=>'adopt_group_dates','enrollment_baseline'=>$e->data['details']['baseline']]);
  t_eq('2026-03-02',qta_enrollment_find($p,$a,$cid)['start_date'],'group change reconciles after review');
});
en_case('Enrollment: database precision, references and immutable identity',function($p){
  $cid=en_course($p);$sid=en_student($p);$mods=qta_enrollment_course($p,$cid)['modules'];
  $s=qta_enrollment_save($p,$sid,$cid,en_details());$id=$s['enrollment']['id'];$mid=$mods[0]['id'];
  t_throws(PDOException::class,fn()=>$p->prepare('INSERT INTO enrollment_module_scores(enrollment_id,student_id,module_id,score) VALUES(?,?,?,?)')->execute([$id,$sid,$mid,'85.123']),'DB rejects excess precision');
  t_throws(PDOException::class,fn()=>$p->prepare('UPDATE student_course_plans SET manual_final_score=85.123 WHERE id=?')->execute([$id]),'DB rejects manual precision');
  t_throws(PDOException::class,fn()=>$p->prepare('INSERT INTO enrollment_module_scores(enrollment_id,student_id,module_id,score) VALUES(?,?,?,?)')->execute([$id,$sid,999999999,'85']),'DB rejects foreign module');
  $other=en_course($p);
  t_throws(PDOException::class,fn()=>$p->prepare('UPDATE student_course_plans SET course_id=? WHERE id=?')->execute([$other,$id]),'DB keeps historical enrollment identity');
});
en_case('Enrollment: five modules, assigned manual edit and stale resolution',function($p){
  $cid=en_course($p,false);$sid=en_student($p);
  $s=qta_enrollment_save($p,$sid,$cid,en_details(['manual_final_score'=>'78']));
  $p->prepare("INSERT INTO course_groups(course_id,start_date,end_date) VALUES(?,'2026-03-01','2026-03-31')")->execute([$cid]);$gid=(int)$p->lastInsertId();
  qta_enrollment_assign($p,$sid,$gid);
  $s=qta_enrollment_save($p,$sid,$cid,en_details(['manual_final_score'=>'79','baseline'=>qta_enrollment_snapshot($p,$sid,$cid)['baseline']]));
  t_eq('79.00',$s['result']['final'],'assigned manual correction');
  t_eq('79.00',en_one($p,'SELECT final_score FROM course_group_students WHERE group_id=?',[$gid]),'manual correction updates guarded projection');
  $cid=en_course($p,false);$p->prepare('UPDATE courses SET hours=50 WHERE id=?')->execute([$cid]);
  for($i=1;$i<=5;$i++){$mid=qta_curriculum_add_module($p,$cid,'Moduli '.$i,10);qta_curriculum_add_topic($p,$mid,'Tema',10);}
  $sid=en_student($p);$mods=qta_enrollment_course($p,$cid)['modules'];$scores=array_fill_keys(array_column($mods,'id'),'85.25');
  $s=qta_enrollment_save($p,$sid,$cid,en_details(['scores'=>$scores]));t_eq('85.25',$s['result']['final'],'five complete modules');
  $scores[$mods[3]['id']]='';$scores[$mods[4]['id']]='';$s=qta_enrollment_save($p,$sid,$cid,en_details(['scores'=>$scores]));
  t_eq(3,$s['result']['scored'],'three of five');t_eq(null,$s['result']['final'],'partial result stays unofficial');
  $gid=en_group($p,$cid,'2026-03-02','2026-03-31');$err=t_throws(QtaUserError::class,fn()=>qta_enrollment_assign($p,$sid,$gid),'initial conflict');
  qta_enrollment_save($p,$sid,$cid,en_details(['exam_date'=>'2026-04-06']));
  $err=t_throws(QtaUserError::class,fn()=>qta_enrollment_assign($p,$sid,$gid,['resolution'=>'adopt_group_dates','force'=>1,'baseline'=>$err->data['details']['baseline']]),'stale resolution');
  t_eq('stale_enrollment',$err->data['code'],'stale is structured');
});

en_case('Enrollment: initial batch exam, individual correction and empty group',function($p){
  $cid=en_course($p);$first=random_int(30000000,80000000);
  $r=qta_lg_create($p,['course_id'=>$cid,'schedule_mode'=>'fixed_range','start_date'=>'2026-03-01','end_date'=>'2026-03-31','exam_date'=>'2026-03-31','amze_spec'=>$first.'-'.($first+2)]);
  $gid=$r['groups'][0]['group_id'];
  t_eq(3,(int)en_one($p,"SELECT COUNT(*) FROM course_group_students WHERE group_id=? AND exam_date='2026-03-31'",[$gid]),'exam equal end copied to all three');
  t_eq(null,en_one($p,'SELECT exam_date FROM course_groups WHERE id=?',[$gid]),'no group-level exam');
  $sid=(int)en_one($p,'SELECT MIN(student_id) FROM course_group_students WHERE group_id=?',[$gid]);
  $p->prepare("UPDATE course_group_students SET exam_date='2026-04-07' WHERE group_id=? AND student_id=?")->execute([$gid,$sid]);
  t_eq(2,(int)en_one($p,"SELECT COUNT(*) FROM course_group_students WHERE group_id=? AND exam_date='2026-03-31'",[$gid]),'other exams unchanged');
  $r=qta_lg_create($p,['course_id'=>$cid,'schedule_mode'=>'fixed_range','start_date'=>'2026-04-01','end_date'=>'2026-04-30','exam_date'=>'not a date']);
  t_eq(null,en_one($p,'SELECT exam_date FROM course_groups WHERE id=?',[$r['groups'][0]['group_id']]),'empty group ignores batch exam');
});

en_case('Enrollment: full, foreign and corrupt candidates excluded; full exact group not reused',function($p){
  $cid=en_course($p);$full=en_group($p,$cid);
  for($i=0;$i<10;$i++) qta_enrollment_assign($p,en_student($p),$full);
  $corrupt=en_group($p,$cid);$p->prepare('DELETE FROM group_schedule_slots WHERE group_id=?')->execute([$corrupt]);
  $foreign=en_group($p,en_course($p));
  $sid=en_student($p);qta_enrollment_save($p,$sid,$cid,en_details());
  $list=qta_enrollment_candidates($p,qta_enrollment_find($p,$sid,$cid));
  foreach([$full,$corrupt,$foreign] as $bad) t_ok(!in_array($bad,array_map('intval',array_column($list,'id')),true),'invalid candidate excluded '.$bad);
  $r=qta_enrollment_use_dates($p,$sid,$cid);t_ok((int)$r['enrollment']['group_id']!==$full,'full exact group not reused');
  t_eq(10,(int)en_one($p,'SELECT COUNT(*) FROM course_group_students WHERE group_id=?',[$full]),'full group stays ten');
});

en_case('Enrollment: new group AMZE conflict reviewed atomically',function($p){
  $cid=en_course($p);$sid=en_student($p);$m=qta_enrollment_course($p,$cid)['modules'];
  $r=qta_enrollment_save($p,$sid,$cid,en_details(['scores'=>array_fill_keys(array_column($m,'id'),'85')]));$eid=$r['enrollment']['id'];
  $in=['course_id'=>$cid,'schedule_mode'=>'fixed_range','start_date'=>'2026-03-05','end_date'=>'2026-04-05','exam_date'=>'2026-04-05','amze_spec'=>en_one($p,'SELECT nr_amze FROM students WHERE id=?',[$sid])];
  $before=(int)en_one($p,'SELECT COUNT(*) FROM course_groups');
  $error=t_throws(QtaUserError::class,fn()=>qta_lg_create($p,$in),'new group conflict');
  t_eq(true,$error->data['details']['creation'],'future target identified');t_eq(0,$error->data['details']['group']['id'],'no dangling provisional group');
  t_eq($before,(int)en_one($p,'SELECT COUNT(*) FROM course_groups'),'nothing created during conflict');
  $in['enrollment_resolutions']=[$sid=>['resolution'=>'adopt_group_dates','force'=>true,'baseline'=>$error->data['details']['baseline'],'exam_date'=>'2026-04-05']];
  $g=qta_lg_create($p,$in)['groups'][0]['group_id'];$r=qta_enrollment_snapshot($p,$sid,$cid);
  t_eq($eid,$r['enrollment']['id'],'identity retained during reviewed creation');t_eq('2026-04-05',$r['enrollment']['end_date'],'adopted boundary');t_eq('85.00',$r['result']['final'],'score transfer complete');
  qta_lg_delete($p,$g,['force'=>true]);$r=qta_enrollment_snapshot($p,$sid,$cid);
  t_eq(null,$r['enrollment']['group_id'],'group deletion detaches');t_eq('85.00',$r['result']['final'],'group deletion preserves results');t_eq('2026-04-05',$r['enrollment']['exam_date'],'group deletion archives exam');
});

en_case('Enrollment: explicit empty conversion keeps manual provenance',function($p){
  $cid=en_course($p,false);$sid=en_student($p);qta_enrollment_save($p,$sid,$cid,en_details(['manual_final_score'=>'78']));
  foreach(['A','B','C'] as $title){$mid=qta_curriculum_add_module($p,$cid,$title,10);qta_curriculum_add_topic($p,$mid,$title,10);}
  $gid=en_group($p,$cid);qta_enrollment_assign($p,$sid,$gid);
  $r=qta_enrollment_save($p,$sid,$cid,en_details(['use_modules'=>1,'force'=>1,'scores'=>[]]));
  t_eq('modules',$r['enrollment']['result_source'],'empty conversion persists mode');t_eq('78.00',$r['enrollment']['manual_final_score'],'manual provenance retained');
  t_eq(null,$r['result']['final'],'no official manual projection after conversion');
});

en_case('Enrollment: parent deletion keeps capacity and FK cleanup consistent',function($p){
  $cid=en_course($p);$gid=en_group($p,$cid);$mods=qta_enrollment_course($p,$cid)['modules'];
  foreach(['students','users','persons'] as $parent){
    $sid=en_student($p);qta_enrollment_save($p,$sid,$cid,en_details(['scores'=>array_fill_keys(array_column($mods,'id'),'85')]));qta_enrollment_assign($p,$sid,$gid);
    $id=$parent==='students'?$sid:(int)en_one($p,'SELECT '.($parent==='users'?'user_id':'person_id').' FROM students WHERE id=?',[$sid]);
    t_throws(PDOException::class,fn()=>$p->prepare('DELETE FROM student_course_plans WHERE student_id=?')->execute([$sid]),'assigned enrollment cannot be orphaned');
    if($parent==='persons') $p->prepare('UPDATE users SET person_id=NULL WHERE person_id=?')->execute([$id]);
    $p->prepare('DELETE FROM '.$parent.' WHERE id=?')->execute([$id]);
    t_eq(0,(int)en_one($p,'SELECT COUNT(*) FROM course_group_students WHERE group_id=?',[$gid]),$parent.' deletion clears membership');
    t_eq(0,(int)en_one($p,'SELECT members FROM group_enrollment_capacity WHERE group_id=?',[$gid]),$parent.' deletion keeps counter accurate');
    t_eq(0,(int)en_one($p,'SELECT COUNT(*) FROM enrollment_module_scores WHERE student_id=?',[$sid]),$parent.' deletion clears dependent scores');
  }
});

t_case('Enrollment: injected transfer failure rolls back dates, membership, scores and audit',function() use($enPdo){
  $p=$enPdo;$trigger='en_transfer_failure_'.bin2hex(random_bytes(4));$sid=$cid=$gid=0;
  $p->exec("CREATE TRIGGER $trigger BEFORE UPDATE ON enrollment_module_scores FOR EACH ROW BEGIN IF COALESCE(@qta_test_transfer_failure,0)=1 AND NEW.group_id IS NOT NULL AND OLD.group_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test transfer failure'; END IF; END");
  try {
    [$sid,$cid,$gid]=qta_tx($p,function() use($p){$cid=en_course($p);$sid=en_student($p);$mods=qta_enrollment_course($p,$cid)['modules'];qta_enrollment_save($p,$sid,$cid,en_details(['scores'=>array_fill_keys(array_column($mods,'id'),'85')]));return [$sid,$cid,en_group($p,$cid,'2026-03-05','2026-04-05')];});
    $snapshot=qta_enrollment_snapshot($p,$sid,$cid);$audit=(int)en_one($p,'SELECT COUNT(*) FROM audit_events');
    $error=t_throws(QtaUserError::class,fn()=>qta_enrollment_assign($p,$sid,$gid),'review before transfer');
    $p->exec('SET @qta_test_transfer_failure=1');
    t_throws(PDOException::class,fn()=>qta_enrollment_assign($p,$sid,$gid,['resolution'=>'adopt_group_dates','force'=>1,'baseline'=>$error->data['details']['baseline']]),'actual transfer write fails');
    t_eq(false,$p->inTransaction(),'service ended transaction');t_eq(json_encode($snapshot),json_encode(qta_enrollment_snapshot($p,$sid,$cid)),'all enrollment state rolled back');
    t_eq(0,(int)en_one($p,'SELECT COUNT(*) FROM course_group_students WHERE group_id=?',[$gid]),'no partial membership');
    t_eq($audit,(int)en_one($p,'SELECT COUNT(*) FROM audit_events'),'no partial audit');
  } finally {
    $p->exec('SET @qta_test_transfer_failure=NULL');$p->exec('DROP TRIGGER '.$trigger);
    if($gid) $p->prepare('DELETE FROM course_groups WHERE id=?')->execute([$gid]);
    if($sid){$p->prepare('DELETE FROM enrollment_module_scores WHERE student_id=?')->execute([$sid]);$p->prepare('DELETE FROM students WHERE id=?')->execute([$sid]);}
    if($cid){foreach(qta_course_modules($p,$cid) as $m) qta_curriculum_delete_module($p,(int)$m['id']);$p->prepare('DELETE FROM courses WHERE id=?')->execute([$cid]);}
  }
});
