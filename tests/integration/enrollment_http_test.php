<?php
declare(strict_types=1);
require_once __DIR__.'/../../app/shared/database.php';
require_once __DIR__.'/../../app/shared/lesson_groups.php';

/** Exercise real request guards and the single transaction behind the wizard. */
function eh_request(string $base,string $path,?string $session,array $body=[],string $method='POST'): array {
  $headers=['Accept: application/json','Content-Type: application/json'];
  if($session) $headers[]='Cookie: PHPSESSID='.$session;
  $raw=file_get_contents($base.$path,false,stream_context_create(['http'=>[
    'method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='POST'?json_encode($body):'',
    'ignore_errors'=>true,'follow_location'=>0,'timeout'=>25]]));
  preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);
  return ['status'=>(int)($m[1]??0),'json'=>json_decode((string)$raw,true),'body'=>$raw];
}
function eh_counts(PDO $p): array {
  $out=[];foreach(['persons','users','students','student_course_plans','enrollment_module_scores','course_group_students','audit_events'] as $t) $out[$t]=(int)$p->query('SELECT COUNT(*) FROM '.$t)->fetchColumn();return $out;
}
t_case('Enrollment HTTP: staff/CSRF/edit guards, wizard and atomic failures',function(){
  $p=getPDO();$db=(string)$p->query('SELECT DATABASE()')->fetchColumn();
  if(getenv('QTA_TEST_DB')!=='1' || !str_contains($db,'test')) throw new RuntimeException('Isolated test database required.');
  $dir=sys_get_temp_dir().'/qta_enrollment_http_'.bin2hex(random_bytes(5));mkdir($dir);
  $probe=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($probe,false),':'),1);fclose($probe);
  $log=$dir.'/server.log';$app=realpath(__DIR__.'/../../app');
  $proc=proc_open([PHP_BINARY,'-d','session.save_path='.$dir,'-d','display_errors=0','-d','log_errors=1','-d','error_log='.$log,'-S','127.0.0.1:'.$port,'-t',$app],
    [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$app);
  if(!is_resource($proc)) throw new RuntimeException('HTTP server did not start.');
  $sessions=[];$csrf='enrollment-http-token';$base='http://127.0.0.1:'.$port;
  try {
    for($i=0;$i<100;$i++) {try {fclose(fsockopen('127.0.0.1',$port,$errno,$errstr,.1));break;} catch(Throwable){usleep(50000);}}
    foreach(['administrator','editor','student','agjencia'] as $role) {
      $q=$p->prepare('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name=? ORDER BY u.id LIMIT 1');$q->execute([$role]);$uid=(int)$q->fetchColumn();
      if(!$uid) throw new RuntimeException('Seed required for '.$role);
      foreach([false,true] as $edit) {
        $id=bin2hex(random_bytes(16));$data='';foreach(['user_id'=>$uid,'csrf_token'=>$csrf,'edit_mode'=>$edit] as $k=>$v) $data.=$k.'|'.serialize($v);
        file_put_contents($dir.'/sess_'.$id,$data);$sessions[$role.($edit?'-open':'')]=$id;
      }
    }
    $p->prepare('INSERT INTO courses(code,name,hours) VALUES(?,?,30)')->execute(['EH-'.bin2hex(random_bytes(4)),'Enrollment HTTP ready']);$cid=(int)$p->lastInsertId();$mods=[];
    foreach(['Word','Excel','PowerPoint'] as $title) {$mid=qta_curriculum_add_module($p,$cid,$title,10);qta_curriculum_add_topic($p,$mid,$title,10);$mods[]=$mid;}
    $p->prepare('INSERT INTO courses(code,name,hours) VALUES(?,?,30)')->execute(['EHD-'.bin2hex(random_bytes(4)),'Enrollment HTTP draft']);$draft=(int)$p->lastInsertId();
    $call=fn($who,$body,$path='/actions/student_assignment.php')=>eh_request($base,$path,$who===null?null:$sessions[$who],$body);
    $query=['action'=>'course_details','course_id'=>$cid,'csrf'=>$csrf];
    t_eq(405,eh_request($base,'/actions/student_assignment.php',null,[],'GET')['status'],'POST required');
    t_eq(401,$call(null,$query)['status'],'anonymous cannot read enrollment data');
    foreach(['student','agjencia'] as $role) t_eq(403,$call($role,$query)['status'],$role.' cannot read staff enrollment data');
    t_eq(400,$call('administrator',array_replace($query,['csrf'=>'wrong']))['status'],'CSRF required even on sensitive reads');
    foreach(['administrator','editor'] as $role) t_eq(true,$call($role,$query)['json']['ready'],$role.' may read with changes locked');
    t_eq(403,$call('administrator',['action'=>'save_enrollment','course_id'=>$cid,'student_id'=>1,'csrf'=>$csrf])['status'],'writes require edit mode');
    $before=eh_counts($p);
    $missing=$call('administrator-open',['action'=>'save_enrollment','course_id'=>$cid,'student_id'=>0,'csrf'=>$csrf,'start_date'=>'2026-03-01','end_date'=>'2026-03-31']);
    t_eq('not_found',$missing['json']['code'],'invalid student relationship is rejected explicitly');t_eq($before,eh_counts($p),'invalid ID writes nothing');
    $amze=random_int(71000000,79000000);
    $create=['action'=>'create_student','csrf'=>$csrf,'nr_amze'=>(string)$amze,'first_name'=>'HTTP','last_name'=>'Enrollment'];
    $r=$call('administrator-open',$create,'/pages/students.php');t_eq(true,$r['json']['ok'],'create without course retains previous flow');$sid=(int)($r['json']['student_id']??0);
    $q=$p->prepare('SELECT COUNT(*) FROM student_course_plans WHERE student_id=?');$q->execute([$sid]);t_eq(0,(int)$q->fetchColumn(),'no fabricated course');
    $ready=array_replace($create,['nr_amze'=>(string)($amze+1),'planned_course_id'=>$cid]);$before=eh_counts($p);
    $r=$call('administrator-open',$ready,'/pages/students.php');t_eq('enrollment_dates_required',$r['json']['code'],'server requires course dates');t_eq($before,eh_counts($p),'missing dates rolls back student, identity, enrollment and audit');
    $ready+=['start_date'=>'2026-03-01','end_date'=>'2026-03-31','exam_date'=>'2026-04-05','scores'=>array_combine($mods,['80','90','85'])];
    $r=$call('editor-open',$ready,'/pages/students.php');t_eq(true,$r['json']['ok'],'editor saves ready-course wizard');$readySid=(int)($r['json']['student_id']??0);
    $s=qta_enrollment_snapshot($p,$readySid,$cid);t_eq('85.00',$s['result']['final'],'server computes result');t_eq(null,$s['enrollment']['group_id'],'pre-group identity persisted');
    $manual=array_replace($create,['nr_amze'=>(string)($amze+2),'planned_course_id'=>$draft,'start_date'=>'2026-01-10','end_date'=>'2026-01-25','exam_date'=>'2026-01-28','manual_final_score'=>'78']);
    $r=$call('administrator-open',$manual,'/pages/students.php');t_eq(true,$r['json']['ok'],'draft wizard succeeds');$manualSid=(int)($r['json']['student_id']??0);
    $s=qta_enrollment_snapshot($p,$manualSid,$draft);t_eq('manual',$s['result']['mode'],'manual is distinct');t_eq('78.00',$s['result']['final'],'fallback stored');t_eq([], (array)$s['scores'],'no false module scores');
    foreach([
      ['start_date'=>'2026-04-01','end_date'=>'2026-03-31'],['exam_date'=>''],['exam_date'=>'2026-03-30'],['scores'=>[$mods[0]=>'101']]
    ] as $invalid) {
      $before=eh_counts($p);$r=$call('administrator-open',array_replace($ready,['nr_amze'=>(string)($amze+3)],$invalid),'/pages/students.php');
      t_eq(false,$r['json']['ok'],'invalid wizard rejected');t_eq($before,eh_counts($p),'invalid wizard leaves no partial data');
    }
    $gid=(int)qta_lg_create($p,['course_id'=>$cid,'schedule_mode'=>'fixed_range','start_date'=>'2026-03-05','end_date'=>'2026-04-05'])['groups'][0]['group_id'];
    $assign=['action'=>'assign_to_group','csrf'=>$csrf,'student_id'=>$readySid,'group_id'=>$gid];$before=eh_counts($p);
    $r=$call('administrator-open',$assign);t_eq('enrollment_dates_conflict',$r['json']['code'],'HTTP structured date conflict');t_eq($before,eh_counts($p),'conflict writes nothing');
    t_ok(isset($r['json']['details']['enrollment']['start_date'],$r['json']['details']['group']['end_date'],$r['json']['details']['candidates'],$r['json']['details']['baseline']),'conflict carries individual, target and alternative context');
    $r=$call('administrator-open',$assign+['resolution'=>'adopt_group_dates','force'=>1,'baseline'=>$r['json']['details']['baseline'],'exam_date'=>'2026-04-05']);
    t_eq(true,$r['json']['ok'],'reviewed assignment succeeds through HTTP');$s=qta_enrollment_snapshot($p,$readySid,$cid);t_eq('85.00',$s['result']['final'],'HTTP assignment retains scores');
    t_eq(['2026-03-05','2026-04-05','2026-04-05'],[$s['enrollment']['start_date'],$s['enrollment']['end_date'],$s['enrollment']['exam_date']],'individual period and exam reconciled');
    t_ok(!preg_match('/PHP (Warning|Fatal|Notice|Deprecated|Parse)/i',file_get_contents($log)),'HTTP runtime clean');
  } finally {foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe);proc_terminate($proc);proc_close($proc);}
});
