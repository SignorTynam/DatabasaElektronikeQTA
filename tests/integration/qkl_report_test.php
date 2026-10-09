<?php
declare(strict_types=1);

/**
 * SQL integration for Raporti QKL. Runs only through tests/run.php --integration
 * against an explicitly enabled test database and rolls back every fixture.
 */

require_once __DIR__ . '/../../app/shared/database.php';
require_once __DIR__ . '/../../app/shared/group_members.php';
require_once __DIR__ . '/../../app/exports/inc/qkl_report.php';
require_once __DIR__ . '/../../app/shared/group_calendar.php';

$qklPdo = getPDO();
$qklDb = (string)$qklPdo->query('SELECT DATABASE()')->fetchColumn();
if ($qklDb === 'qta_db' && getenv('QTA_ALLOW_MAIN_DB') !== '1') {
  fwrite(STDERR, "Refuzohet: testet e integrimit QKL nuk punojnë mbi qta_db.\n");
  exit(2);
}

$qklPdo->beginTransaction();
try {
  $qklTag = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
  $qklAmze = 980000000 + random_int(1000, 9000) * 10;

  $qklInsertCourse = $qklPdo->prepare('INSERT INTO courses (code, name, hours) VALUES (?, ?, 40)');
  $qklInsertCourse->execute(['QKLG-' . $qklTag, 'QKL grup ' . $qklTag]);
  $qklGroupCourse = (int)$qklPdo->lastInsertId();
  $qklInsertCourse->execute(['QKLP-' . $qklTag, 'QKL plan ' . $qklTag]);
  $qklPlanCourse = (int)$qklPdo->lastInsertId();
  $qklInsertCourse->execute(['QKLC-' . $qklTag, 'QKL anuluar ' . $qklTag]);
  $qklCancelledCourse = (int)$qklPdo->lastInsertId();

  $qklPdo->prepare("INSERT INTO course_groups (course_id, start_date, end_date, exam_date) VALUES (?, '2026-01-05', '2026-02-06', '2026-02-10')")
      ->execute([$qklGroupCourse]);
  $qklGroup = (int)$qklPdo->lastInsertId();

  $qklGroupStudent = qta_amze_ensure_student($qklPdo, $qklAmze);
  $qklPlanStudent = qta_amze_ensure_student($qklPdo, $qklAmze + 1);
  $qklCancelledStudent = qta_amze_ensure_student($qklPdo, $qklAmze + 2);

  // Trigger-i krijon planin assigned për kursin e grupit.
  $qklPdo->prepare("INSERT INTO course_group_students (group_id, student_id, exam_date) VALUES (?, ?, '2026-02-10')")
      ->execute([$qklGroup, $qklGroupStudent]);
  // Një plan tjetër aktiv nuk duhet ta mposhtë anëtarësinë reale.
  $qklPdo->prepare("INSERT INTO student_course_plans (student_id, course_id, status) VALUES (?, ?, 'planned')")
      ->execute([$qklGroupStudent, $qklPlanCourse]);
  // Pa grup: kursi duhet të vijë nga plani, por jo datat.
  $qklPdo->prepare("INSERT INTO student_course_plans (student_id, course_id, status) VALUES (?, ?, 'planned')")
      ->execute([$qklPlanStudent, $qklPlanCourse]);
  // Cancelled pa timestamp canonik nuk është kurs aktual dhe nuk krijon datë ndërprerjeje.
  $qklPdo->prepare("INSERT INTO student_course_plans (student_id, course_id, status) VALUES (?, ?, 'cancelled')")
      ->execute([$qklCancelledStudent, $qklCancelledCourse]);

  $qklRaw = qkl_fetch_records($qklPdo, $qklAmze, $qklAmze + 2, "'Shqiptare' AS citizenship_value");
  $qklCanonical = qkl_normalize_records($qklRaw);
  $qklByAmze = [];
  foreach ($qklCanonical as $record) $qklByAmze[(int)$record['nr_amze']] = $record;

  t_case('QKL SQL — një query kthen një rresht unik për çdo AMZË', function () use ($qklCanonical, $qklByAmze): void {
    t_eq(3, count($qklCanonical), 'tre fixtures, tre rreshta');
    t_eq(3, count($qklByAmze), 'zero duplikime');
  });

  t_case('QKL SQL — grupi fiton dhe provimi është individual', function () use ($qklByAmze, $qklAmze, $qklTag): void {
    $record = $qklByAmze[$qklAmze];
    t_eq('QKL grup ' . $qklTag, $record['course_name'], 'kursi i grupit');
    t_eq('2026-01-05', $record['start_date'], 'fillimi i grupit');
    t_eq('2026-02-06', $record['end_date'], 'mbarimi i grupit');
    t_eq('2026-02-10', $record['exam_date'], 'nga course_group_students.exam_date');
  });

  t_case('QKL SQL — plani pa grup jep kursin, jo data të fabrikuara', function () use ($qklByAmze, $qklAmze, $qklTag): void {
    $record = $qklByAmze[$qklAmze + 1];
    t_eq('QKL plan ' . $qklTag, $record['course_name'], 'kursi i zgjedhur');
    t_eq([null, null, null], [$record['start_date'], $record['end_date'], $record['exam_date']], 'pa data grupi');
  });

  t_case('QKL SQL — cancelled nuk raportohet si kurs dhe nuk shpik ndërprerje', function () use ($qklByAmze, $qklAmze): void {
    $record = $qklByAmze[$qklAmze + 2];
    t_eq('', $record['course_name'], 'kursi i anuluar nuk bëhet kurs aktual');
    t_eq(null, $record['interruption_date'], 'nuk ekziston timestamp canonik');
  });
  t_case('QKL SQL — individual dates, operational calendar and legacy fallback',function() use($qklPdo,$qklGroup,$qklGroupStudent,$qklAmze): void {
    // Model a historical individual period under the same scoped flag used by
    // reviewed reconciliation. Ordinary SQL/UI writes cannot create this mismatch.
    $qklPdo->exec('SET @qta_enrollment_sync=1');
    try {
      $qklPdo->prepare("UPDATE student_course_plans SET start_date='2026-01-07',end_date='2026-02-05' WHERE student_id=? AND group_id=?")->execute([$qklGroupStudent,$qklGroup]);
      $row=qkl_normalize_records(qkl_fetch_records($qklPdo,$qklAmze,$qklAmze,"'Shqiptare' AS citizenship_value"))[0];
      t_eq(['2026-01-07','2026-02-05'],[$row['start_date'],$row['end_date']],'report uses individual boundaries');
      $events=qta_calendar_events($qklPdo,'2026-01-01','2026-02-28',true)['events'];
      $event=array_values(array_filter($events,fn($e)=>$e['id']===$qklGroup))[0];
      t_eq(['2026-01-05','2026-02-06'],[$event['start'],$event['end']],'calendar retains operational period');
      $qklPdo->prepare('UPDATE student_course_plans SET start_date=NULL,end_date=NULL WHERE student_id=? AND group_id=?')->execute([$qklGroupStudent,$qklGroup]);
      $row=qkl_normalize_records(qkl_fetch_records($qklPdo,$qklAmze,$qklAmze,"'Shqiptare' AS citizenship_value"))[0];
      t_eq(['2026-01-05','2026-02-06'],[$row['start_date'],$row['end_date']],'legacy missing dates fall back safely');
    } finally {$qklPdo->exec('SET @qta_enrollment_sync=NULL');}
  });
} finally {
  if ($qklPdo->inTransaction()) $qklPdo->rollBack();
}

