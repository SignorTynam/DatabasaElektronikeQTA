<?php
declare(strict_types=1);
require_once __DIR__.'/../../app/shared/database.php';
require_once __DIR__.'/../../app/shared/lesson_groups.php';
function ec_race(string $mode,array $ids,int $target): array {
  $jobs=[];$time=microtime(true)+0.5;
  foreach($ids as $sid) {
    $proc=proc_open([PHP_BINARY,__DIR__.'/../fixtures/enrollment_worker.php',$mode,(string)$sid,(string)$target,(string)$time],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc)) throw new RuntimeException('Worker did not start.');
    fclose($pipes[0]);$jobs[]=[$proc,$pipes];
  }
  $out=[];
  foreach($jobs as [$proc,$pipes]) {$text=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);$out[]=json_decode($text,true)??['ok'=>false,'message'=>$text.$err];}
  return $out;
}
t_case('Enrollment: concurrent capacity and duplicate group prevention',function(){
  $p=getPDO(); if(getenv('QTA_TEST_DB')!=='1' || !str_contains((string)$p->query('SELECT DATABASE()')->fetchColumn(),'test')) throw new RuntimeException('Test DB required.');
  $p->prepare('INSERT INTO courses(code,name,hours) VALUES(?,?,10)')->execute(['RACE-'.bin2hex(random_bytes(4)),'Enrollment race']);$cid=(int)$p->lastInsertId();
  $mid=qta_curriculum_add_module($p,$cid,'Moduli',10);qta_curriculum_add_topic($p,$mid,'Tema',10);
  $base=random_int(60000000,70000000);$map=qta_tx($p,fn()=>qta_amze_ensure_batch($p,range($base,$base+12)));$ids=array_values($map);
  $gid=(int)qta_lg_create($p,['course_id'=>$cid,'start_date'=>'2091-01-01','end_date'=>'2091-01-10','schedule_mode'=>'fixed_range'])['groups'][0]['group_id'];
  foreach(array_slice($ids,0,9) as $sid) qta_enrollment_assign($p,$sid,$gid);
  $r=ec_race('capacity',array_slice($ids,9,2),$gid);
  t_eq(1,count(array_filter($r,fn($x)=>$x['ok'])),'only one request receives the last place');
  $q=$p->prepare('SELECT COUNT(*) FROM course_group_students WHERE group_id=?');$q->execute([$gid]);t_eq(10,(int)$q->fetchColumn(),'never eleven members');
  foreach(array_slice($ids,11,2) as $sid) qta_enrollment_save($p,$sid,$cid,['start_date'=>'2091-02-01','end_date'=>'2091-02-10']);
  $r=ec_race('reuse',array_slice($ids,11,2),$cid);
  t_eq(2,count(array_filter($r,fn($x)=>$x['ok'])),'both exact-period requests succeed');
  t_eq(1,count(array_unique(array_column($r,'group_id'))),'both reuse the same created group');
  $q=$p->prepare("SELECT COUNT(*) FROM course_groups WHERE course_id=? AND start_date='2091-02-01' AND end_date='2091-02-10'");$q->execute([$cid]);t_eq(1,(int)$q->fetchColumn(),'one group created concurrently');
  $fresh=array_values(qta_tx($p,fn()=>qta_amze_ensure_batch($p,range($base+100,$base+110))));
  $gid=(int)qta_lg_create($p,['course_id'=>$cid,'start_date'=>'2091-03-01','end_date'=>'2091-03-10','schedule_mode'=>'fixed_range'])['groups'][0]['group_id'];
  foreach(array_slice($fresh,0,9) as $sid) qta_enrollment_assign($p,$sid,$gid);
  $r=ec_race('sql',array_slice($fresh,9,2),$gid);t_eq(1,count(array_filter($r,fn($x)=>$x['ok'])),'DB guard also protects direct SQL with stale read snapshots');
  $q=$p->prepare('SELECT COUNT(*) FROM course_group_students WHERE group_id=?');$q->execute([$gid]);t_eq(10,(int)$q->fetchColumn(),'DB guard never permits eleven');
});
