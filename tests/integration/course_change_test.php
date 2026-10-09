<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/shared/database.php';
require_once __DIR__ . '/../../app/shared/legacy_conversion.php';

$ccPdo = getPDO();
if (getenv('QTA_TEST_DB') !== '1' || !str_contains((string)$ccPdo->query('SELECT DATABASE()')->fetchColumn(), 'test')) exit(2);

function cc_course(PDO $pdo, int $hours): int
{
  $pdo->prepare('INSERT INTO courses(code,name,hours) VALUES(?,?,?)')
    ->execute(['CC-' . bin2hex(random_bytes(4)), 'Kurs test ' . $hours, $hours]);
  $cid = (int)$pdo->lastInsertId();
  $mid = qta_curriculum_add_module($pdo, $cid, 'Moduli ' . $hours, $hours);
  qta_curriculum_add_topic($pdo, $mid, 'Tema ' . $hours, $hours);
  return $cid;
}

function cc_fingerprint(PDO $pdo, int $gid): string
{
  $rows = [];
  foreach (['course_groups' => 'id', 'group_schedules' => 'group_id', 'group_schedule_topics' => 'group_id',
    'group_schedule_days' => 'group_id', 'group_schedule_slots' => 'group_id', 'group_fixed_days' => 'group_id',
    'group_conversions' => 'group_id', 'course_group_students' => 'group_id'] as $table => $key) {
    $st = $pdo->prepare("SELECT * FROM $table WHERE $key = ?");
    $st->execute([$gid]);
    $rows[$table] = $st->fetchAll(PDO::FETCH_ASSOC);
  }
  return hash('sha256', serialize($rows));
}

t_case('Ndryshimi i kursit: të dy mënyrat, konfirmimi, kopja, orët, versioni dhe historiku', function () use ($ccPdo) {
  $pdo = $ccPdo;
  $old = cc_course($pdo, 16);
  foreach (['fixed_range', 'calculated'] as $mode) {
    $gid = qta_lg_create($pdo, ['course_id' => $old, 'start_date' => '2090-02-01', 'end_date' => '2090-02-10',
      'schedule_mode' => $mode, 'daily_hours' => 4, 'exam_date' => '2199-12-31', 'amze_spec' => ''])['groups'][0]['group_id'];
    foreach ([24, 8, 8] as $hours) {
      $cid = cc_course($pdo, $hours);
      $g = qta_lg_require($pdo, $gid);
      $change = ['type' => $mode === 'fixed_range' ? 'fixed_range_settings' : 'settings', 'course_id' => $cid,
        'start_date' => $g['start_date'], 'end_date' => $g['end_date'], 'daily_hours' => 4];
      $before = cc_fingerprint($pdo, $gid);
      $preview = qta_lg_change($pdo, $gid, $change, ['dry_run' => true, 'revision' => $g['revision']]);
      t_eq($hours, $preview['new']['total_hours'], 'parashikimi përdor orët e kursit të ri');
      t_eq(true, $preview['course_changed'], 'ndikimi tregon ndryshimin e kursit');
      t_eq($before, cc_fingerprint($pdo, $gid), 'parashikimi nuk shkruan');
      t_throws(QtaConfirmNeeded::class, fn() => qta_lg_change($pdo, $gid, $change), 'ndryshimi kërkon konfirmim');
      t_eq($before, cc_fingerprint($pdo, $gid), 'pa konfirmim asgjë nuk shkruhet');
      $r = qta_lg_change($pdo, $gid, $change, ['force' => true, 'revision' => $g['revision']]);
      $after = qta_lg_require($pdo, $gid);
      t_eq($cid, $after['course_id'], 'kursi i ri u ruajt');
      t_eq($hours, $after['course_hours'], 'koka ruan orët e reja');
      t_eq($g['revision'] + 1, $r['revision'], 'versioni rritet vetëm një herë');
      t_eq(qta_course_schedule_topics(qta_course_modules($pdo, $cid)), qta_lg_topics($pdo, $gid), 'kopja i përket kursit të ri');
      t_eq($hours, array_sum(array_column(qta_lg_days($pdo, $gid), 'hours')), 'orari ka të gjitha orët');
      if ($mode === 'fixed_range') {
        t_eq($g['end_date'], $after['end_date'], 'periudha mbetet e njëjtë');
        $plan = qta_lg_fixed_hours(qta_lg_fixed_days($pdo, $gid));
        $on = array_keys(array_filter($plan, static fn($h) => $h > 0));
        $off = array_keys(array_filter($plan, static fn($h) => $h === 0));
        $edit = [$on[0] => $plan[$on[0]] - 1, $off[0] => 1];
        qta_lg_change($pdo, $gid, ['type' => 'fixed_days', 'days' => $edit]);
        t_eq(1, qta_lg_fixed_days($pdo, $gid)[$off[0]]['hours'], 'organizimi i ditëve funksionon pas ndërrimit');
      }
      t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, $change, ['force' => true, 'revision' => $g['revision']]), 'versioni i vjetër refuzohet', 'dikush tjetër');
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM audit_events WHERE table_name='course_groups' AND action='UPDATE' AND JSON_EXTRACT(new_data, '$.id') = ?");
    $st->execute([$gid]);
    t_ok((int)$st->fetchColumn() >= 3, 'historiku përmban ndryshimet e kursit');
  }
});

t_case('Ndryshimi i kursit: gabimet dhe dështimi i shkrimit rikthejnë të gjithë grupin', function () use ($ccPdo) {
  $pdo = $ccPdo;
  $old = cc_course($pdo, 16);
  $new = cc_course($pdo, 24);
  $gid = qta_lg_create($pdo, ['course_id' => $old, 'start_date' => '2090-03-01', 'end_date' => '2090-03-03',
    'schedule_mode' => 'fixed_range', 'exam_date' => '2199-12-31', 'amze_spec' => ''])['groups'][0]['group_id'];
  $change = ['type' => 'fixed_range_settings', 'course_id' => $new, 'start_date' => '2090-03-01', 'end_date' => '2090-03-03'];
  $sid = qta_amze_ensure_student($pdo, random_int(82000000, 82999999));
  $pdo->prepare("INSERT INTO course_group_students(group_id,student_id,exam_date) VALUES(?,?,'2090-03-03')")->execute([$gid, $sid]);
  $before = cc_fingerprint($pdo, $gid);
  t_throws(PDOException::class, fn() => $pdo->prepare('UPDATE course_groups SET course_id=? WHERE id=?')->execute([$new, $gid]), 'SQL i drejtpërdrejtë refuzohet');
  $tooLarge = cc_course($pdo, 25);
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, array_replace($change, ['course_id' => $tooLarge]), ['force' => true]), 'periudha e pamjaftueshme refuzohet');
  $pdo->prepare('INSERT INTO courses(code,name,hours) VALUES(?,?,20)')->execute(['CC-D-' . bin2hex(random_bytes(4)), 'Kurs jo gati']);
  $draft = (int)$pdo->lastInsertId();
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, array_replace($change, ['course_id' => $draft]), ['force' => true]), 'kursi jo gati refuzohet', 'nuk është ende gati');
  t_eq($before, cc_fingerprint($pdo, $gid), 'gabimet ruajnë gjithçka');
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, array_replace($change, ['end_date' => '2090-03-04']), ['force' => true]), 'provimi para mbarimit refuzohet', 'Provimi nuk mund');
  $pdo->exec('SET @qta_results_sync = 1');
  $pdo->prepare('UPDATE course_group_students SET final_score=0 WHERE group_id=?')->execute([$gid]);
  $pdo->exec('SET @qta_results_sync = NULL');
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, $change, ['force' => true]), 'edhe rezultati historik zero bllokon ndryshimin', 'pikë të regjistruara');
  $pdo->exec('SET @qta_results_sync = 1');
  $pdo->prepare('UPDATE course_group_students SET final_score=NULL WHERE group_id=?')->execute([$gid]);
  $pdo->exec('SET @qta_results_sync = NULL');
  $st = $pdo->prepare('SELECT person_id FROM students WHERE id=?');
  $st->execute([$sid]);
  $pid = (int)$st->fetchColumn();
  $pdo->prepare('UPDATE persons SET personal_number=? WHERE id=?')->execute(['CC' . bin2hex(random_bytes(5)), $pid]);
  $otherSid = qta_amze_ensure_student($pdo, random_int(83000000, 83999999));
  $pdo->prepare('UPDATE students SET person_id=? WHERE id=?')->execute([$pid, $otherSid]);
  $other = qta_lg_create($pdo, ['course_id' => $new, 'start_date' => '2090-04-01', 'daily_hours' => 4])['groups'][0]['group_id'];
  $pdo->prepare('INSERT INTO course_group_students(group_id,student_id) VALUES(?,?)')->execute([$other, $otherSid]);
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, $change, ['force' => true]), 'ndjekja e të njëjtit kurs me amzë tjetër bllokohet', 'një numër tjetër amze');
  $pdo->prepare('DELETE FROM course_group_students WHERE group_id=?')->execute([$other]);
  t_eq($before, cc_fingerprint($pdo, $gid), 'refuzimet nuk ndryshojnë grupin');
  $pdo->exec("CREATE TRIGGER cc_test_failure BEFORE INSERT ON group_schedule_slots FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='cc injected failure'");
  try {
    t_throws(PDOException::class, fn() => qta_lg_change($pdo, $gid, $change, ['force' => true]), 'gabimi në mes të rindërtimit del te thirrësi', 'cc injected');
  } finally {
    $pdo->exec('DROP TRIGGER cc_test_failure');
  }
  t_eq($before, cc_fingerprint($pdo, $gid), 'kursi, kopja, datat, versioni dhe plani rikthehen pas gabimit');
  t_eq(null, $pdo->query('SELECT @qta_course_change_group')->fetchColumn(), 'leja e lidhjes pastrohet pas gabimit');
});

t_case('Ndryshimi i kursit: grupi i konvertuar ruan prejardhjen, kursantët dhe provimet; pikët e bllokojnë', function () use ($ccPdo) {
  $pdo = $ccPdo;
  $old = cc_course($pdo, 16);
  $new = cc_course($pdo, 24);
  $pdo->prepare("INSERT INTO course_groups(course_id,start_date,end_date,is_completed) VALUES(?,'2026-01-01','2026-01-05',1)")->execute([$old]);
  $gid = (int)$pdo->lastInsertId();
  $sid = qta_amze_ensure_student($pdo, random_int(81000000, 81999999));
  $pdo->prepare("INSERT INTO course_group_students(group_id,student_id,exam_date) VALUES(?,?,'2026-01-06')")->execute([$gid, $sid]);
  $admin = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='administrator' LIMIT 1")->fetchColumn();
  $v = qta_conv_view($pdo, $gid);
  qta_conv_save($pdo, $gid, qta_conv_plan_for_client($v['plan']), 0, $v['fingerprint'], $admin);
  qta_conv_apply($pdo, $gid, 1, $v['fingerprint'], $admin);
  $st = $pdo->prepare('SELECT * FROM group_conversions WHERE group_id=?');
  $st->execute([$gid]);
  $provenance = $st->fetch(PDO::FETCH_ASSOC);
  $change = ['type' => 'fixed_range_settings', 'course_id' => $new, 'start_date' => '2026-01-01', 'end_date' => '2026-01-05'];
  $preview = qta_lg_change($pdo, $gid, $change, ['dry_run' => true]);
  t_ok(str_contains($preview['confirm']['message'], 'mbyllur') && count($preview['past_changed']) > 0, 'konfirmimi përmban mbylljen dhe ditët e kaluara');
  qta_lg_change($pdo, $gid, $change, ['force' => true]);
  $st->execute([$gid]);
  t_eq($provenance, $st->fetch(PDO::FETCH_ASSOC), 'prejardhja e konvertimit nuk ndryshon');
  $g = qta_lg_require($pdo, $gid);
  t_eq(1, $g['is_completed'], 'grupi mbetet i mbyllur');
  $st = $pdo->prepare('SELECT student_id,exam_date FROM course_group_students WHERE group_id=?');
  $st->execute([$gid]);
  t_eq([['student_id' => $sid, 'exam_date' => '2026-01-06']], $st->fetchAll(PDO::FETCH_ASSOC), 'kursanti dhe provimi ruhen');
  $module = qta_lg_topics($pdo, $gid)[0]['source_module_id'];
  qta_results_save($pdo, $gid, [['student_id' => $sid, 'module_id' => $module, 'from' => null, 'to' => '80']], ['force' => true]);
  $before = cc_fingerprint($pdo, $gid);
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, array_replace($change, ['course_id' => $old]), ['force' => true]), 'pikët e kursit nuk zhvendosen', 'pikë të regjistruara');
  t_eq($before, cc_fingerprint($pdo, $gid), 'bllokimi i pikëve ruan grupin');
});
