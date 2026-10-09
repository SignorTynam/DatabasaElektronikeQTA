<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli' || getenv('QTA_TEST_DB')!=='1') exit(2);
require_once __DIR__.'/../../app/shared/database.php';
require_once __DIR__.'/../../app/shared/enrollments.php';
$p=getPDO(); if(!str_contains((string)$p->query('SELECT DATABASE()')->fetchColumn(),'test')) exit(2);
[$file,$mode,$sid,$target,$time]=$argv;
while(microtime(true)<(float)$time) usleep(10000);
try {
  if($mode==='sql') {
    $p->beginTransaction();$p->query('SELECT COUNT(*) FROM course_group_students')->fetchColumn();
    $p->prepare('INSERT INTO course_group_students(group_id,student_id) VALUES(?,?)')->execute([(int)$target,(int)$sid]);
    $p->commit();echo json_encode(['ok'=>true,'group_id'=>(int)$target]);exit;
  }
  $r=$mode==='capacity'?qta_enrollment_assign($p,(int)$sid,(int)$target):qta_enrollment_use_dates($p,(int)$sid,(int)$target);
  echo json_encode(['ok'=>true,'group_id'=>$r['enrollment']['group_id']]);
} catch(Throwable $e) {echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);}
