<?php
declare(strict_types=1);
require_once __DIR__.'/../../app/shared/enrollments.php';
t_case('Enrollment: dates and exam invariants',function(){
  t_eq(['2026-03-01','2026-03-31'],qta_enrollment_dates('01.03.2026','31.03.2026'),'individual boundaries');
  t_throws(QtaUserError::class,fn()=>qta_enrollment_dates('31.03.2026','01.03.2026'),'reversed');
  t_throws(QtaUserError::class,fn()=>qta_enrollment_dates('',''),'required');
  t_eq(null,qta_enrollment_exam('','2026-03-31',false),'no score permits absent exam');
  t_throws(QtaUserError::class,fn()=>qta_enrollment_exam('','2026-03-31',true),'score requires exam');
  t_throws(QtaUserError::class,fn()=>qta_enrollment_exam('30.03.2026','2026-03-31',true),'exam before individual end');
  t_throws(QtaUserError::class,fn()=>qta_enrollment_exam('31.03.2026','2026-03-31',true,'2026-04-01'),'exam before group end');
  t_eq('2026-03-31',qta_enrollment_exam('31.03.2026','2026-03-31',true),'equality is valid');
  t_eq(0,qta_score_parse('0'),'zero valid'); t_eq(null,qta_score_parse(''),'blank missing');
  foreach(['-1','101','82.125'] as $v) t_throws(QtaUserError::class,fn()=>qta_score_parse($v),'invalid '.$v);
  t_eq(8500,qta_results_compute([1,2,3],[1=>8000,2=>9000,3=>8500])['final'],'unweighted complete average');
  t_eq(null,qta_results_compute([1,2,3,4,5],[1=>8000,2=>9000,3=>8500])['final'],'partial average is not official');
});
