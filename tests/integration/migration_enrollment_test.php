<?php
declare(strict_types=1);
require_once __DIR__.'/../../app/shared/results.php';
if(!function_exists('mig_create')) require_once __DIR__.'/migration_conversion_test.php';

function em_prior(PDO $p, bool $last=true): void {
  $files=glob(__DIR__.'/../../db/migrations/*.sql');
  foreach($files as $f) {
    if(str_contains($f,'2026-10-09') || str_contains($f,'2026-09-28-piket')) continue;
    if(!$last && str_contains($f,'2026-10-08')) continue;
    if(str_contains($f,'2026-10-07')) {
      $r=mig_run($p,__DIR__.'/../../db/migrations/2026-09-28-piket-sipas-moduleve.sql');
      if($r['error']) throw new RuntimeException($r['error']);
    }
    $r=mig_run($p,$f); if($r['error']) throw new RuntimeException(basename($f).': '.$r['error']);
  }
}

t_case('Enrollment migration: missing latest prerequisite stops before changing data',function(){
  $name=mig_name(bin2hex(random_bytes(4)),'enrollmentprereq');$p=mig_create($name);
  try {
    em_prior($p,false);$before=mig_data($p,['course_groups','course_group_students','student_course_plans']);
    $file=__DIR__.'/../../db/migrations/2026-10-09-student-course-enrollment-details.sql';
    t_ok(str_contains((string)mig_run($p,$file)['error'],'2026-10-08'),'specific missing prerequisite reported');
    t_eq($before,mig_data($p,['course_groups','course_group_students','student_course_plans']),'data untouched');
    t_eq(0,(int)$p->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_course_plans' AND COLUMN_NAME='start_date'")->fetchColumn(),'no partial column changes');
    t_eq(null,mig_run($p,__DIR__.'/../../db/migrations/2026-10-08-ndryshimi-i-kursit-te-grupit.sql')['error'],'apply prerequisite');
    t_eq(null,mig_run($p,$file)['error'],'retry succeeds');
  } finally {$p=null;mig_drop($name);}
});
t_case('Enrollment migration: fresh, historical, idempotent and exact scores',function(){
  foreach([false,true] as $historical) {
    $name=mig_name(bin2hex(random_bytes(4)),'enrollment'); $p=mig_create($name);
    try {
      if($historical) mig_seed_legacy($p);
      em_prior($p);
      if($historical) {
        $gid=(int)$p->query('SELECT MIN(id) FROM course_groups')->fetchColumn();
        $p->exec("UPDATE course_groups SET exam_date='2025-03-22' WHERE id=$gid");
        $sid=(int)$p->query("SELECT MIN(student_id) FROM course_group_students WHERE group_id=$gid")->fetchColumn();
        $p->exec("UPDATE course_group_students SET exam_date=NULL WHERE group_id=$gid AND student_id<>$sid");
        $before=$p->query('SELECT group_id,student_id,final_score,legacy_final_score FROM course_group_students ORDER BY student_id')->fetchAll();
        $p->exec("INSERT INTO course_modules(course_id,position,title,hours) SELECT MIN(id),1,'Moduli historik',40 FROM courses");
        $mid=(int)$p->lastInsertId();
        $p->exec("INSERT INTO enrollment_module_scores(group_id,student_id,module_id,score) VALUES($gid,$sid,$mid,80.25)");
      }
      $file=__DIR__.'/../../db/migrations/2026-10-09-student-course-enrollment-details.sql';
      t_eq(null,mig_run($p,$file)['error'],'migration succeeds');
      if($historical) {
        t_eq($before,$p->query('SELECT group_id,student_id,final_score,legacy_final_score FROM course_group_students ORDER BY student_id')->fetchAll(),'old official and legacy results untouched');
        t_eq('2025-03-20',$p->query("SELECT exam_date FROM course_group_students WHERE group_id=$gid AND student_id=$sid")->fetchColumn(),'individual exam untouched');
        t_eq('2025-03-22',$p->query("SELECT exam_date FROM course_group_students WHERE group_id=$gid AND student_id<>$sid")->fetchColumn(),'missing exam backfilled');
        t_eq('80.25',qta_score_db(qta_score_from_db($p->query('SELECT score FROM enrollment_module_scores')->fetchColumn())),'score untouched');
        t_eq(0,(int)$p->query('SELECT COUNT(*) FROM student_course_plans e JOIN course_groups g ON g.id=e.group_id WHERE e.start_date<>g.start_date OR e.end_date<>g.end_date')->fetchColumn(),'individual period backfilled');
      }
      $tables=['student_course_plans','enrollment_result_modules','enrollment_module_scores','course_group_students','students','course_groups'];
      if($historical) $p->exec("UPDATE course_group_students SET exam_date=NULL WHERE group_id=$gid AND student_id<>$sid");
      $data=mig_data($p,$tables);$schema=mig_schema($p);
      t_eq(null,mig_run($p,$file)['error'],'repeat succeeds');
      t_eq($data,mig_data($p,$tables),'repeat preserves data');t_eq($schema,mig_schema($p),'repeat preserves schema');
    } finally {$p=null;mig_drop($name);}
  }
});
