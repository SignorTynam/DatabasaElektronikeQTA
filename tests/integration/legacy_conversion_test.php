<?php
declare(strict_types=1);

/**
 * Integrimi: konvertimi i grupeve nga regjistri i vjetër te regjistri i kurseve
 * profesionale — kontrolli paraprak, drafti, konkurrenca, konvertimi atomik,
 * rregullat e bazës, rikthimi pas një gabimi, regjistri i orëve dhe historiku.
 *
 * Vetëm në një databazë testimi (QTA_TEST_DB=1). Testi krijon grupet e veta.
 */

require_once __DIR__ . '/../../app/shared/database.php';
require_once __DIR__ . '/../../app/shared/legacy_conversion.php';
require_once __DIR__ . '/../../app/shared/lesson_register.php';

$pdo = getPDO();
if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() === 'qta_db' && getenv('QTA_ALLOW_MAIN_DB') !== '1') {
  fwrite(STDERR, "Refuzohet: testet e integrimit nuk punojnë mbi qta_db.\n");
  exit(2);
}
$cvAdmin = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'administrator' ORDER BY u.id LIMIT 1")->fetchColumn();
$pdo->exec('SET @audit_user_id = ' . $cvAdmin . ", @audit_ip = '127.0.0.1', @audit_ua = 'tests'");
$cvTag = substr(md5((string)microtime(true)), 0, 5);
$cvAmze = 700000 + random_int(0, 9000) * 10;

/* ------------------------------------------------------------ Ndihmës */

function cv_count(PDO $pdo, string $sql, array $p = []): int
{
  $st = $pdo->prepare($sql);
  $st->execute($p);
  return (int)$st->fetchColumn();
}

/** Kurs gati: [[titulli, [orët e temave]], …] */
function cv_course(PDO $pdo, string $code, string $name, array $modules): int
{
  $hours = array_sum(array_map(static fn($m) => array_sum($m[1]), $modules));
  $pdo->prepare('INSERT INTO courses (code, name, hours) VALUES (?, ?, ?)')->execute([$code, $name, $hours]);
  $cid = (int)$pdo->lastInsertId();
  foreach ($modules as [$title, $topics]) {
    $mid = qta_curriculum_add_module($pdo, $cid, $title, array_sum($topics));
    foreach ($topics as $i => $h) qta_curriculum_add_topic($pdo, $mid, $title . ' — tema ' . ($i + 1), $h);
  }
  return $cid;
}

/** Grup i vjetër, si ata të regjistrit të vjetër: vetëm rreshti i grupit dhe kursantët. */
function cv_legacy_group(PDO $pdo, int $courseId, string $start, string $end, array $amzeList, int $closed = 0): int
{
  $pdo->prepare('INSERT INTO course_groups (course_id, start_date, end_date, is_completed) VALUES (?, ?, ?, ?)')->execute([$courseId, $start, $end, $closed]);
  $gid = (int)$pdo->lastInsertId();
  foreach ($amzeList as $a) {
    $sid = qta_amze_ensure_student($pdo, (int)$a);
    $pdo->prepare('INSERT INTO course_group_students (group_id, student_id) VALUES (?, ?)')->execute([$gid, $sid]);
  }
  return $gid;
}

/** Gjurma e plotë e grupit të vjetër: rreshti, kursantët, provimet, pikët, planet. */
function cv_group_print(PDO $pdo, int $gid): string
{
  $g = $pdo->prepare('SELECT id, course_id, start_date, end_date, is_completed, model, exam_date, created_at FROM course_groups WHERE id = ?');
  $g->execute([$gid]);
  $m = $pdo->prepare('SELECT group_id, student_id, exam_date, final_score FROM course_group_students WHERE group_id = ? ORDER BY student_id');
  $m->execute([$gid]);
  $p = $pdo->prepare('SELECT student_id, course_id, status, group_id FROM student_course_plans WHERE group_id = ? ORDER BY student_id');
  $p->execute([$gid]);
  return md5(json_encode([$g->fetch(PDO::FETCH_ASSOC), $m->fetchAll(PDO::FETCH_ASSOC), $p->fetchAll(PDO::FETCH_ASSOC)]));
}

function cv_schedule_rows(PDO $pdo, int $gid): int
{
  $n = 0;
  foreach (['group_schedules', 'group_schedule_topics', 'group_schedule_days', 'group_schedule_slots', 'group_fixed_days', 'group_conversions'] as $t) {
    $n += cv_count($pdo, "SELECT COUNT(*) FROM $t WHERE group_id = ?", [$gid]);
  }
  return $n;
}

$WORD = [2, 2, 2, 2, 1, 1];
$cvCourse = cv_course($pdo, 'MSO-' . $cvTag, 'Microsoft Office ' . $cvTag,
  [['Microsoft Word', $WORD], ['Microsoft Excel', $WORD], ['PowerPoint', $WORD], ['Access', $WORD], ['Outlook', $WORD]]);
$cvMembers = range($cvAmze, $cvAmze + 8);
$cvGroup = cv_legacy_group($pdo, $cvCourse, '2026-10-01', '2026-10-10', $cvMembers);
/* Provime dhe pikë historike: tre kursantë me provim, dy prej tyre me pikë. */
$pdo->prepare('UPDATE course_group_students cgs JOIN students s ON s.id = cgs.student_id SET cgs.exam_date = ? WHERE cgs.group_id = ? AND CAST(s.nr_amze AS UNSIGNED) IN (?, ?, ?)')
    ->execute(['2026-10-12', $cvGroup, $cvAmze, $cvAmze + 1, $cvAmze + 2]);
/* Pikë të shkruara para pikëve sipas moduleve: vetëm llogaritja e rezultatit e shkruan
   final_score (trg_cgs_results_bu), prandaj të dhënat historike shënohen si të tilla. */
$pdo->exec('SET @qta_results_sync = 1');
$pdo->prepare('UPDATE course_group_students cgs JOIN students s ON s.id = cgs.student_id SET cgs.final_score = ? WHERE cgs.group_id = ? AND CAST(s.nr_amze AS UNSIGNED) IN (?, ?)')
    ->execute([78.5, $cvGroup, $cvAmze, $cvAmze + 1]);
$pdo->exec('SET @qta_results_sync = NULL');

/* ============================================================== Testet */

t_case('Konvertimi — kontrolli paraprak dhe propozimi nuk prekin grupin', function () use ($pdo, $cvGroup) {
  $before = cv_group_print($pdo, $cvGroup);
  $v = qta_conv_view($pdo, $cvGroup);
  t_eq([], $v['pre']['blockers'], 'asnjë pengesë');
  t_eq('ready', $v['status']['key'], 'gjendja: Gati për përgatitje');
  t_eq(50, $v['hours'], '50 orë');
  t_eq(['2026-10-01' => 8, '2026-10-02' => 7, '2026-10-03' => 0, '2026-10-04' => 0, '2026-10-05' => 7,
        '2026-10-06' => 7, '2026-10-07' => 7, '2026-10-08' => 0, '2026-10-09' => 7, '2026-10-10' => 7], $v['plan']['days'], 'propozimi 50 orë');
  t_eq(null, $v['draft'], 'pa draft');
  t_eq($before, cv_group_print($pdo, $cvGroup), 'grupi, kursantët, provimet dhe pikët të paprekura');
  t_eq(0, cv_schedule_rows($pdo, $cvGroup), 'asnjë rresht orari');
  t_eq(0, cv_count($pdo, 'SELECT COUNT(*) FROM legacy_conversion_drafts WHERE group_id = ?', [$cvGroup]), 'asnjë draft');
  $list = qta_conv_list($pdo, ['q' => '#' . $cvGroup, 'course_id' => '', 'status' => '']);
  t_eq([$cvGroup], array_map('intval', array_column($list['rows'], 'id')), 'lista e konvertimit e gjen grupin');
  t_eq('ready', $list['rows'][0]['status']['key'], 'lista: Gati për përgatitje');
});

t_case('Konvertimi — drafti: ruajtja, versioni dhe skeda e vjetër', function () use ($pdo, $cvGroup, $cvAdmin) {
  $before = cv_group_print($pdo, $cvGroup);
  $v = qta_conv_view($pdo, $cvGroup);
  $plan = qta_conv_plan_for_client($v['plan']);
  foreach ($plan as &$d) if ($d['d'] === '2026-10-05') { $d['h'] = 0; $d['m'] = 1; }
  unset($d);
  $r = qta_conv_save($pdo, $cvGroup, $plan, 0, $v['fingerprint'], $cvAdmin);
  t_eq([1, true], [$r['revision'], $r['created']], 'drafti u krijua me versionin 1');
  t_eq('missing_hours', $r['summary']['issues'][0]['code'] ?? null, 'drafti mund të ruhet i paplotë (43 nga 50)');
  t_eq($before, cv_group_print($pdo, $cvGroup), 'ruajtja e draftit nuk prek grupin');
  t_eq('legacy', (string)$pdo->query('SELECT model FROM course_groups WHERE id = ' . $cvGroup)->fetchColumn(), 'grupi mbetet i vjetër');
  t_eq(0, cv_schedule_rows($pdo, $cvGroup), 'asnjë rresht orari');

  $e = t_throws(QtaUserError::class, fn() => qta_conv_save($pdo, $cvGroup, $plan, 0, $v['fingerprint'], $cvAdmin), 'skeda e dytë që nuk e pa draftin refuzohet', 'ndryshua ndërkohë');
  t_eq('draft_changed', $e instanceof QtaUserError ? $e->data['code'] : null, 'kodi draft_changed');

  /* Rishpërndarja nuk ruan, dhe e ruan zgjedhjen me dorë. */
  $rb = qta_conv_rebalance($pdo, $cvGroup, $plan);
  t_eq([50, 0], [array_sum($rb['days']), $rb['days']['2026-10-05']], 'rishpërndarja: 50 orë, 05.10 mbetet pa mësim');
  $rb = array_map(static fn($d) => ['d' => $d['d'], 'h' => $rb['days'][$d['d']], 'm' => $d['m'] ?? 0], $plan);
  $r = qta_conv_save($pdo, $cvGroup, $rb, 1, $v['fingerprint'], $cvAdmin);
  t_eq(2, $r['revision'], 'versioni 2');
  t_eq([], $r['summary']['issues'], 'plani i ruajtur është i plotë');
  t_eq(true, qta_conv_save($pdo, $cvGroup, $rb, 2, $v['fingerprint'], $cvAdmin)['unchanged'], 'ruajtja pa ndryshime nuk rrit versionin');
  $e = t_throws(QtaUserError::class, fn() => qta_conv_apply($pdo, $cvGroup, 1, $v['fingerprint'], $cvAdmin), 'konvertimi me versionin e vjetër refuzohet', 'ndryshua ndërkohë');
  t_eq('legacy', (string)$pdo->query('SELECT model FROM course_groups WHERE id = ' . $cvGroup)->fetchColumn(), 'grupi mbetet i vjetër');

  $bad = $rb; $bad[0]['h'] = 9;
  t_throws(QtaUserError::class, fn() => qta_conv_save($pdo, $cvGroup, $bad, 2, $v['fingerprint'], $cvAdmin), 'ditë me 9 orë refuzohet', 'maksimumi 8 orë');
  $short = array_slice($rb, 1);
  t_throws(QtaUserError::class, fn() => qta_conv_save($pdo, $cvGroup, $short, 2, $v['fingerprint'], $cvAdmin), 'plan pa të gjitha datat refuzohet', 'nuk është i plotë');
  $out = $rb; $out[] = ['d' => '2026-10-11', 'h' => 1];
  t_throws(QtaUserError::class, fn() => qta_conv_save($pdo, $cvGroup, $out, 2, $v['fingerprint'], $cvAdmin), 'datë jashtë periudhës refuzohet', 'nuk është i plotë');
});

t_case('Konvertimi — ndryshimi i kursit ose i kursantëve e vjetëron draftin', function () use ($pdo, $cvGroup, $cvCourse, $cvAdmin) {
  $v = qta_conv_view($pdo, $cvGroup);
  t_eq(false, $v['stale'], 'drafti është i freskët');
  $topic = qta_course_modules($pdo, $cvCourse)[0]['topics'][0];
  qta_curriculum_update_topic($pdo, (int)$topic['id'], ['title' => 'Hyrje në Word (e re)']);
  $v2 = qta_conv_view($pdo, $cvGroup);
  t_eq([true, 'review'], [$v2['stale'], $v2['status']['key']], 'ndryshimi i temës e vjetëron draftin: Kërkon kontroll');
  $plan = qta_conv_plan_for_client($v2['plan']);
  $e = t_throws(QtaUserError::class, fn() => qta_conv_apply($pdo, $cvGroup, $v2['draft']['revision'], $v['fingerprint'], $cvAdmin), 'konvertimi i draftit të vjetëruar refuzohet', 'ndryshuan');
  t_eq('source_changed', $e instanceof QtaUserError ? $e->data['code'] : null, 'kodi source_changed');
  t_throws(QtaUserError::class, fn() => qta_conv_save($pdo, $cvGroup, $plan, $v2['draft']['revision'], $v2['fingerprint'], $cvAdmin), 'edhe ruajtja pret rifreskimin', 'ndryshuan');
  t_eq('legacy', (string)$pdo->query('SELECT model FROM course_groups WHERE id = ' . $cvGroup)->fetchColumn(), 'grupi mbetet i vjetër');

  $r = qta_conv_refresh($pdo, $cvGroup, $v2['draft']['revision'], $cvAdmin);
  t_eq([true, false], [$r['kept'], $r['discarded']], 'rifreskimi e mban planin (periudha e njëjtë)');
  $v3 = qta_conv_view($pdo, $cvGroup);
  t_eq([false, $v2['plan']['days']], [$v3['stale'], $v3['plan']['days']], 'drafti është sërish i freskët, me të njëjtin plan');

  /* Një pikë e ndryshuar gjatë shqyrtimit e vjetëron sërish punën. */
  $pdo->exec('SET @qta_results_sync = 1');
  $pdo->prepare('UPDATE course_group_students SET final_score = 81 WHERE group_id = ? AND final_score IS NOT NULL LIMIT 1')->execute([$cvGroup]);
  $pdo->exec('SET @qta_results_sync = NULL');
  t_eq(true, qta_conv_view($pdo, $cvGroup)['stale'], 'ndryshimi i pikëve e vjetëron draftin');
  qta_conv_refresh($pdo, $cvGroup, $v3['draft']['revision'], $cvAdmin);
  t_eq(false, qta_conv_view($pdo, $cvGroup)['stale'], 'pas rifreskimit: i freskët');
});

t_case('Konvertimi — atomik, në vend, me të gjitha të dhënat e ruajtura', function () use ($pdo, $cvGroup, $cvAdmin) {
  $v = qta_conv_view($pdo, $cvGroup);
  $members = $pdo->query('SELECT student_id, exam_date, final_score FROM course_group_students WHERE group_id = ' . $cvGroup . ' ORDER BY student_id')->fetchAll(PDO::FETCH_ASSOC);
  $groupRow = $pdo->query('SELECT id, course_id, start_date, end_date, is_completed, exam_date, created_at FROM course_groups WHERE id = ' . $cvGroup)->fetch(PDO::FETCH_ASSOC);
  $plans = $pdo->query('SELECT student_id, course_id, status, group_id FROM student_course_plans WHERE group_id = ' . $cvGroup . ' ORDER BY student_id')->fetchAll(PDO::FETCH_ASSOC);
  $groupsBefore = cv_count($pdo, 'SELECT COUNT(*) FROM course_groups');
  $studentsBefore = cv_count($pdo, 'SELECT COUNT(*) FROM students');
  $auditBefore = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_events')->fetchColumn();

  $r = qta_conv_apply($pdo, $cvGroup, $v['draft']['revision'], $v['fingerprint'], $cvAdmin);
  t_eq([$cvGroup, 50, 9], [$r['group_id'], $r['hours'], $r['members']], 'i njëjti grup, 50 orë, 9 kursantë');

  $g = qta_lg_find($pdo, $cvGroup);
  t_eq(['scheduled', 'fixed_range', null], [$g['model'], $g['schedule_mode'], $g['daily_hours']], 'grup me orar, data historike, pa "orë në ditë"');
  t_eq(['2026-10-01', '2026-10-10'], [$g['start_date'], $g['end_date']], 'fillimi dhe mbarimi nuk lëvizën');
  t_eq($groupRow, $pdo->query('SELECT id, course_id, start_date, end_date, is_completed, exam_date, created_at FROM course_groups WHERE id = ' . $cvGroup)->fetch(PDO::FETCH_ASSOC), 'rreshti i grupit identik (përveç llojit)');
  t_eq($members, $pdo->query('SELECT student_id, exam_date, final_score FROM course_group_students WHERE group_id = ' . $cvGroup . ' ORDER BY student_id')->fetchAll(PDO::FETCH_ASSOC), 'kursantët, provimet dhe pikët identike');
  t_eq($plans, $pdo->query('SELECT student_id, course_id, status, group_id FROM student_course_plans WHERE group_id = ' . $cvGroup . ' ORDER BY student_id')->fetchAll(PDO::FETCH_ASSOC), 'kurset e zgjedhura (AMZË → grup) identike');
  t_eq($groupsBefore, cv_count($pdo, 'SELECT COUNT(*) FROM course_groups'), 'asnjë grup i ri');
  t_eq($studentsBefore, cv_count($pdo, 'SELECT COUNT(*) FROM students'), 'asnjë kursant i ri');

  $topics = qta_lg_topics($pdo, $cvGroup);
  t_eq(30, count($topics), 'kopja e temave: 30 tema');
  t_eq('Hyrje në Word (e re)', $topics[0]['topic_title'], 'kopja ndjek strukturën aktuale të kursit');
  $fixed = qta_lg_fixed_days($pdo, $cvGroup);
  t_eq(qta_sched_dates('2026-10-01', '2026-10-10'), array_keys($fixed), 'plani i ditëve: çdo datë e periudhës');
  t_eq(50, array_sum(array_column($fixed, 'hours')), 'plani: 50 orë');
  t_eq(0, $fixed['2026-10-05']['hours'], '05.10 mbetet pa mësim (zgjedhja me dorë)');
  t_eq(50, cv_count($pdo, 'SELECT SUM(hours) FROM group_schedule_days WHERE group_id = ?', [$cvGroup]), 'Σ ditë = 50');
  t_eq(50, cv_count($pdo, 'SELECT SUM(hours) FROM group_schedule_slots WHERE group_id = ?', [$cvGroup]), 'Σ pjesë = 50');
  t_eq(0, cv_count($pdo, 'SELECT COUNT(*) FROM group_schedule_days WHERE group_id = ? AND hours > 8', [$cvGroup]), 'asnjë ditë mbi 8 orë');
  t_eq(['2026-10-01', '2026-10-10'], [
    (string)$pdo->query('SELECT MIN(lesson_date) FROM group_schedule_days WHERE group_id = ' . $cvGroup)->fetchColumn(),
    (string)$pdo->query('SELECT MAX(lesson_date) FROM group_schedule_days WHERE group_id = ' . $cvGroup)->fetchColumn(),
  ], 'dita e parë dhe e fundit e mësimit = datat historike');
  t_eq(0, cv_count($pdo, 'SELECT COUNT(*) FROM (SELECT t.seq FROM group_schedule_topics t LEFT JOIN group_schedule_slots s ON s.group_id = t.group_id AND s.topic_seq = t.seq WHERE t.group_id = ? GROUP BY t.seq, t.topic_hours HAVING COALESCE(SUM(s.hours),0) <> t.topic_hours) x', [$cvGroup]), 'çdo temë me orët e veta');
  t_eq([], qta_sched_verify_fixed_range($topics, ['start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'days' => qta_lg_days($pdo, $cvGroup)],
    '2026-10-01', '2026-10-10', qta_lg_fixed_hours($fixed)), 'orari i ruajtur kalon kontrollin e pavarur');

  $c = $pdo->query('SELECT * FROM group_conversions WHERE group_id = ' . $cvGroup)->fetch(PDO::FETCH_ASSOC);
  t_eq(['completed', '2026-10-01', '2026-10-10', 50, $cvAdmin, QTA_FIXED_ALGORITHM_VERSION],
    [$c['status'], $c['source_start_date'], $c['source_end_date'], (int)$c['course_hours'], (int)$c['converted_by'], $c['algorithm_version']], 'shënimi i konvertimit');
  t_eq(qta_conv_plan_hash(['days' => qta_lg_fixed_hours($fixed), 'notes' => []]), $c['approved_plan_hash'], 'gjurma e planit të miratuar');
  t_eq(0, cv_count($pdo, 'SELECT COUNT(*) FROM legacy_conversion_drafts WHERE group_id = ?', [$cvGroup]), 'drafti u hoq');

  t_eq(1, cv_count($pdo, "SELECT COUNT(*) FROM audit_events WHERE id > ? AND table_name = 'group_conversions' AND action = 'INSERT' AND user_id = ?", [$auditBefore, $cvAdmin]), 'historiku: konvertimi, me përdoruesin');
  t_eq(1, cv_count($pdo, "SELECT COUNT(*) FROM audit_event_fields f JOIN audit_events e ON e.id = f.event_id WHERE e.id > ? AND e.table_name = 'course_groups' AND f.column_name = 'model' AND f.old_value = 'legacy' AND f.new_value = 'scheduled'", [$auditBefore]), 'historiku: lloji i grupit legacy → scheduled');
  t_eq(1, cv_count($pdo, "SELECT COUNT(*) FROM audit_event_fields f JOIN audit_events e ON e.id = f.event_id WHERE e.id > ? AND e.table_name = 'group_schedules' AND f.column_name = 'schedule_mode' AND f.new_value = 'fixed_range'", [$auditBefore]), 'historiku: orari me data historike');
  t_eq(0, cv_count($pdo, "SELECT COUNT(*) FROM audit_events WHERE id > ? AND table_name = 'course_group_students'", [$auditBefore]), 'historiku: asnjë ndryshim te kursantët');
});

t_case('Konvertimi — grupi kalon regjistër, pa dyfishim', function () use ($pdo, $cvGroup) {
  $F = ['q' => '#' . $cvGroup, 'course_id' => '', 'status' => ''];
  $params = [];
  $w = array_merge(["cg.model = 'scheduled'"], qta_group_where($F, $params));
  $st = $pdo->prepare('SELECT cg.id FROM course_groups cg JOIN courses c ON c.id = cg.course_id JOIN group_schedules gs ON gs.group_id = cg.id WHERE ' . implode(' AND ', $w));
  $st->execute($params);
  t_eq([$cvGroup], array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), 'del te "Regjistri i kurseve profesionale" (një herë)');
  $params = [];
  $w = array_merge(["cg.model = 'legacy'"], qta_group_where($F, $params));
  $st = $pdo->prepare('SELECT COUNT(*) FROM course_groups cg JOIN courses c ON c.id = cg.course_id WHERE ' . implode(' AND ', $w));
  $st->execute($params);
  t_eq(0, (int)$st->fetchColumn(), 'nuk del më te "Regjistri i vjetër"');
  t_eq([], qta_conv_list($pdo, $F)['rows'], 'nuk del më te lista e konvertimit');
  $e = t_throws(QtaUserError::class, fn() => qta_conv_apply($pdo, $cvGroup, 0, null, null), 'konvertimi i dytë refuzohet', 'konvertuar tashmë');
  t_eq('converted', $e instanceof QtaUserError ? $e->data['code'] : null, 'kodi converted');
  t_throws(QtaUserError::class, fn() => qta_conv_view($pdo, $cvGroup)['draft'] === null && qta_conv_save($pdo, $cvGroup, [], 0, null, null), 'drafti nuk ruhet më për grupin e konvertuar', 'konvertuar tashmë');
});

t_case('Konvertimi — regjistri i orëve dhe dokumentet përdorin kopjen dhe orarin e ruajtur', function () use ($pdo, $cvGroup, $cvCourse) {
  $model = qta_lesson_register_build($pdo, $cvGroup, '2026-10-15');
  $rows = 0; $cols = 0; $dates = [];
  foreach ($model['pages'] as $p) {
    if ($p['kind'] === 'attendance') { $cols += count(array_filter($p['columns'])); foreach ($p['dates'] as $d) $dates[$d] = true; }
    else $rows += count($p['rows']);
  }
  t_eq([50, 50], [$rows, $cols], 'një rresht dhe një kolonë për çdo orë (50)');
  $stored = $pdo->query('SELECT lesson_date FROM group_schedule_days WHERE group_id = ' . $cvGroup . ' ORDER BY day_seq')->fetchAll(PDO::FETCH_COLUMN);
  t_eq($stored, array_keys($dates), 'datat = ditët e ruajta të mësimit');
  t_eq(9, count($model['students']), '9 kursantë në radhën e listës emërore');
  $before = json_encode($model);
  qta_curriculum_update_module($pdo, qta_course_modules($pdo, $cvCourse)[1]['id'], ['title' => 'Excel (emër i ri)']);
  t_eq($before, json_encode(qta_lesson_register_build($pdo, $cvGroup, '2026-10-15')), 'ndryshimet e kursit pas konvertimit nuk e prekin regjistrin');
  $hours = $pdo->prepare('SELECT gs.course_hours FROM group_schedules gs JOIN course_groups cg ON cg.id = gs.group_id WHERE gs.group_id = ? AND cg.model = \'scheduled\'');
  $hours->execute([$cvGroup]);
  t_eq(50, (int)$hours->fetchColumn(), 'procesverbali merr orët nga kopja e grupit');
});

t_case('Konvertimi — korrigjimet e mëvonshme: brenda periudhës, datat nuk lëvizin', function () use ($pdo, $cvGroup) {
  $g = qta_lg_find($pdo, $cvGroup);
  $rev = (int)$g['revision'];
  $today = '2026-09-28';
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $cvGroup, ['type' => 'settings', 'start_date' => '02.10.2026', 'daily_hours' => 5], ['revision' => $rev, 'today' => $today]),
    'fillimi nuk ndryshohet', 'data historike');
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $cvGroup, ['type' => 'rule', 'date' => '03.10.2026', 'mode' => 'hours', 'hours' => 4], ['revision' => $rev, 'today' => $today]),
    'ditët e veçanta nuk vlejnë', 'plani i ditëve');
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $cvGroup, ['type' => 'refresh'], ['revision' => $rev, 'today' => $today]),
    'temat nuk rimerren', 'konvertim');
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $cvGroup, ['type' => 'fixed_days', 'days' => ['2026-10-03' => 4]], ['revision' => $rev, 'today' => $today]),
    'korrigjim që prish shumën refuzohet', 'Janë vendosur 4 orë më shumë');
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $cvGroup, ['type' => 'fixed_days', 'days' => ['2026-10-01' => 0, '2026-10-03' => 8]], ['revision' => $rev, 'today' => $today]),
    'fillimi pa mësim refuzohet', 'data historike e fillimit');
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $cvGroup, ['type' => 'fixed_days', 'days' => ['2026-10-11' => 1]], ['revision' => $rev, 'today' => $today]),
    'datë jashtë periudhës refuzohet', 'jashtë periudhës');
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $cvGroup, ['type' => 'fixed_days', 'days' => ['2026-10-03' => 9]], ['revision' => $rev, 'today' => $today]),
    '9 orë refuzohen', 'nga 0 deri në 8');

  /* 03.10: 0 → 4, 06.10: 8 → 4 — shuma mbetet 50. */
  $fixedBefore = qta_lg_fixed_days($pdo, $cvGroup);
  $change = ['type' => 'fixed_days', 'days' => ['2026-10-03' => 4, '2026-10-06' => $fixedBefore['2026-10-06']['hours'] - 4], 'notes' => ['2026-10-03' => 'Mësim zëvendësues']];
  $dry = qta_lg_change($pdo, $cvGroup, $change, ['revision' => $rev, 'dry_run' => true, 'today' => $today]);
  t_eq([null, '2026-10-10'], [$dry['confirm'], $dry['new']['end_date']], 'parashikimi: pa konfirmim (e ardhmja), mbarimi mbetet');
  t_eq($fixedBefore, qta_lg_fixed_days($pdo, $cvGroup), 'parashikimi nuk ndryshon asgjë');
  $r = qta_lg_change($pdo, $cvGroup, $change, ['revision' => $rev, 'today' => $today]);
  t_eq($rev + 1, $r['revision'], 'versioni rritet');
  $after = qta_lg_fixed_days($pdo, $cvGroup);
  t_eq([4, 'Mësim zëvendësues'], [$after['2026-10-03']['hours'], $after['2026-10-03']['note']], '03.10 ka 4 orë dhe shënimin');
  $g = qta_lg_find($pdo, $cvGroup);
  t_eq(['2026-10-01', '2026-10-10', 50], [$g['start_date'], $g['end_date'], array_sum(array_column(qta_lg_days($pdo, $cvGroup), 'hours'))], 'datat historike dhe 50 orët mbeten');
  t_ok(cv_count($pdo, "SELECT COUNT(*) FROM audit_events WHERE table_name = 'group_fixed_days' AND action = 'UPDATE' AND row_pk LIKE ?", ['%2026-10-03%']) >= 1, 'historiku: korrigjimi i 03.10');

  /* Ditë që kanë kaluar dhe grupi i mbyllur kërkojnë konfirmim. */
  $e = t_throws(QtaConfirmNeeded::class, fn() => qta_lg_change($pdo, $cvGroup, ['type' => 'fixed_days', 'days' => ['2026-10-03' => 3, '2026-10-06' => $after['2026-10-06']['hours'] + 1]], ['revision' => $r['revision'], 'today' => '2026-10-20']),
    'korrigjimi i ditëve të kaluara kërkon konfirmim', 'kanë kaluar');
  $pdo->prepare('UPDATE course_groups SET is_completed = 1 WHERE id = ?')->execute([$cvGroup]);
  t_throws(QtaConfirmNeeded::class, fn() => qta_lg_change($pdo, $cvGroup, ['type' => 'fixed_days', 'days' => ['2026-10-09' => $after['2026-10-09']['hours'] - 1, '2026-10-06' => $after['2026-10-06']['hours'] + 1]], ['revision' => $r['revision'], 'today' => $today]),
    'grup i mbyllur: kërkon konfirmim', 'mbyllur');
  $pdo->prepare('UPDATE course_groups SET is_completed = 0 WHERE id = ?')->execute([$cvGroup]);
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $cvGroup, $change, ['revision' => $rev, 'today' => $today]), 'versioni i vjetër refuzohet', 'dikush tjetër');
});

t_case('Konvertimi — rregullat e bazës: lloji dhe datat historike', function () use ($pdo, $cvGroup, $cvCourse, $cvAmze) {
  $other = cv_legacy_group($pdo, $cvCourse, '2026-11-02', '2026-11-12', [$cvAmze + 50]);
  t_throws(PDOException::class, fn() => $pdo->exec("UPDATE course_groups SET model = 'scheduled' WHERE id = " . $other), 'legacy → scheduled pa konvertim refuzohet', 'nuk mund të ndryshohet');
  t_throws(PDOException::class, fn() => $pdo->exec("UPDATE course_groups SET model = 'legacy' WHERE id = " . $cvGroup), 'scheduled → legacy refuzohet', 'nuk mund të ndryshohet');
  t_throws(PDOException::class, fn() => $pdo->exec("UPDATE course_groups SET end_date = '2026-10-11' WHERE id = " . $cvGroup), 'mbarimi historik nuk ndryshon', 'data historike');
  t_throws(PDOException::class, fn() => $pdo->exec("UPDATE course_groups SET start_date = '2026-09-30' WHERE id = " . $cvGroup), 'fillimi historik nuk ndryshon', 'data historike');
  t_throws(PDOException::class, fn() => $pdo->exec("UPDATE group_schedules SET schedule_mode = 'calculated', daily_hours = 5 WHERE group_id = " . $cvGroup), 'orari nuk kthehet në të llogaritur', 'nuk ndryshon');
  t_throws(PDOException::class, fn() => $pdo->exec('UPDATE group_conversions SET status = \'applying\' WHERE group_id = ' . $cvGroup), 'shënimi i konvertimit nuk ndryshon', 'nuk ndryshon');
  t_throws(PDOException::class, fn() => $pdo->exec("INSERT INTO group_day_rules (group_id, rule_date, hours) VALUES ($cvGroup, '2026-10-04', 3)"), 'ditë e veçantë te grupi i konvertuar refuzohet', 'vetëm për grupet me orar të llogaritur');
  t_throws(PDOException::class, fn() => $pdo->exec("INSERT INTO group_conversions (group_id, source_start_date, source_end_date, course_hours, teaching_days, algorithm_version, approved_plan_hash, source_fingerprint, status, converted_at)
    VALUES ($cvGroup, '2026-10-01', '2026-10-10', 50, 7, 'x', REPEAT('0', 64), REPEAT('0', 64), 'applying', NOW())"), 'konvertimi i dytë i të njëjtit grup refuzohet', '');
  /* Ndryshimi i llojit lejohet vetëm me konvertim në proces, dhe kurrë bashkë me datat. */
  $pdo->beginTransaction();
  try {
    $pdo->exec("INSERT INTO group_conversions (group_id, source_start_date, source_end_date, course_hours, teaching_days, algorithm_version, approved_plan_hash, source_fingerprint, status, converted_at)
                VALUES ($other, '2026-11-02', '2026-11-12', 50, 7, 'x', REPEAT('0', 64), REPEAT('0', 64), 'applying', NOW())");
    t_throws(PDOException::class, fn() => $pdo->exec("UPDATE course_groups SET model = 'scheduled', end_date = '2026-11-13' WHERE id = " . $other), 'gjatë konvertimit datat nuk ndryshojnë', 'datat');
    t_throws(PDOException::class, fn() => $pdo->exec("INSERT INTO group_conversions (group_id, source_start_date, source_end_date, course_hours, teaching_days, algorithm_version, approved_plan_hash, source_fingerprint, status, converted_at)
                VALUES ($cvGroup, '2026-01-01', '2026-01-02', 1, 1, 'x', REPEAT('0', 64), REPEAT('0', 64), 'applying', NOW())"), 'konvertim për grup jo të vjetër refuzohet', '');
    t_throws(PDOException::class, fn() => $pdo->exec("UPDATE group_conversions SET status = 'completed' WHERE group_id = " . $other), 'nuk mbyllet pa orarin', 'pa orarin');
  } finally {
    $pdo->rollBack();
  }
  t_eq('legacy', (string)$pdo->query('SELECT model FROM course_groups WHERE id = ' . $other)->fetchColumn(), 'grupi tjetër mbetet i vjetër');
  t_throws(PDOException::class, fn() => $pdo->exec("INSERT INTO legacy_conversion_drafts (group_id, source_fingerprint, algorithm_version, plan_json) VALUES ($cvGroup, REPEAT('0', 64), 'x', '{}')"), 'draft për grup të konvertuar refuzohet', 'regjistrit të vjetër');
  t_throws(PDOException::class, fn() => $pdo->exec("UPDATE group_fixed_days SET lesson_date = '2026-10-11' WHERE group_id = $cvGroup AND lesson_date = '2026-10-10'"), 'data e planit nuk ndryshon', 'nuk ndryshon');
  t_throws(PDOException::class, fn() => $pdo->exec("UPDATE group_fixed_days SET hours = 9 WHERE group_id = $cvGroup AND lesson_date = '2026-10-10'"), '9 orë refuzohen nga baza', 'chk_gfd_hours');
  $GLOBALS['CV_OTHER'] = $other;
});

t_case('Konvertimi — gabimi në mes të transaksionit kthen gjithçka mbrapsht', function () use ($pdo, $cvCourse, $cvAdmin, $cvAmze) {
  $gid = cv_legacy_group($pdo, $cvCourse, '2026-11-02', '2026-11-14', [$cvAmze + 60, $cvAmze + 61]);
  $v = qta_conv_view($pdo, $gid);
  qta_conv_save($pdo, $gid, qta_conv_plan_for_client($v['plan']), 0, $v['fingerprint'], $cvAdmin);
  $before = cv_group_print($pdo, $gid);
  $auditBefore = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_events')->fetchColumn();
  /* Një gabim i vërtetë i bazës pasi janë shkruar tashmë lloji, orari, temat dhe ditët. */
  $pdo->exec('DROP TRIGGER IF EXISTS trg_test_fail_slots');
  $pdo->exec("CREATE TRIGGER trg_test_fail_slots BEFORE INSERT ON group_schedule_slots FOR EACH ROW BEGIN IF NEW.group_id = $gid THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'gabim testimi'; END IF; END");
  try {
    t_throws(PDOException::class, fn() => qta_conv_apply($pdo, $gid, 1, $v['fingerprint'], $cvAdmin), 'konvertimi dështon në mes', 'gabim testimi');
  } finally {
    $pdo->exec('DROP TRIGGER IF EXISTS trg_test_fail_slots');
  }
  t_eq(false, $pdo->inTransaction(), 'transaksioni u mbyll');
  t_eq('legacy', (string)$pdo->query('SELECT model FROM course_groups WHERE id = ' . $gid)->fetchColumn(), 'grupi mbetet i vjetër');
  t_eq($before, cv_group_print($pdo, $gid), 'grupi, kursantët dhe provimet identike');
  t_eq(0, cv_schedule_rows($pdo, $gid), 'asnjë rresht orari, plani apo konvertimi');
  t_eq(1, (int)qta_conv_draft_find($pdo, $gid)['revision'], 'drafti mbetet, i paprekur');
  t_eq(0, cv_count($pdo, 'SELECT COUNT(*) FROM audit_events WHERE id > ?', [$auditBefore]), 'asnjë gjurmë në historik');
  /* Pas heqjes së gabimit, i njëjti draft konvertohet. */
  $r = qta_conv_apply($pdo, $gid, 1, $v['fingerprint'], $cvAdmin);
  t_eq($gid, $r['group_id'], 'pastaj konvertohet normalisht');
  $GLOBALS['CV_SECOND'] = $gid;
});

t_case('Konvertimi — pengesat: kursi, kapaciteti dhe kursantët', function () use ($pdo, $cvCourse, $cvAdmin, $cvAmze, $cvTag) {
  /* Kursi jo gati: pengesë me lidhjen te katalogu. */
  $pdo->prepare('INSERT INTO courses (code, name, hours) VALUES (?, ?, ?)')->execute(['NR-' . $cvTag, 'Kurs pa strukturë ' . $cvTag, 40]);
  $bare = (int)$pdo->lastInsertId();
  $g1 = cv_legacy_group($pdo, $bare, '2026-10-01', '2026-10-20', [$cvAmze + 70]);
  $v = qta_conv_view($pdo, $g1);
  t_eq(['problems', 'course_not_ready', 'course.php?id=' . $bare], [$v['status']['key'], $v['pre']['blockers'][0]['code'], $v['pre']['blockers'][0]['fix']['href']], 'kursi jo gati: Ka probleme, me lidhjen te kursi');
  t_eq(null, $v['plan'], 'pa propozim pa kurs gati');

  /* 81 orë në 10 ditë: e pamundur. */
  $big = cv_course($pdo, 'BIG-' . $cvTag, 'Kurs 81 orë ' . $cvTag, [['A', [9, 9, 9, 9, 9, 9, 9, 9, 9]]]);
  $g2 = cv_legacy_group($pdo, $big, '2026-10-01', '2026-10-10', [$cvAmze + 71]);
  $v = qta_conv_view($pdo, $g2);
  t_eq(['impossible', 'capacity', true], [$v['status']['key'], $v['pre']['blockers'][0]['code'], $v['pre']['impossible']], '81 orë në 10 ditë: Nuk mund të konvertohet');
  t_ok(str_contains($v['pre']['blockers'][0]['text'], '80 orë'), 'arsyeja: kapaciteti 80 orë');
  $plan = array_map(static fn($d) => ['d' => $d, 'h' => 8], qta_sched_dates('2026-10-01', '2026-10-10'));
  qta_conv_save($pdo, $g2, $plan, 0, $v['fingerprint'], $cvAdmin);
  t_throws(QtaUserError::class, fn() => qta_conv_apply($pdo, $g2, 1, $v['fingerprint'], $cvAdmin), 'konvertimi refuzohet', '80 orë');
  t_eq('legacy', (string)$pdo->query('SELECT model FROM course_groups WHERE id = ' . $g2)->fetchColumn(), 'grupi mbetet i vjetër');

  /* 20 orë në 2 ditë (16): e pamundur. */
  $c20 = cv_course($pdo, 'C20-' . $cvTag, 'Kurs 20 orë ' . $cvTag, [['A', [10, 10]]]);
  t_eq('impossible', qta_conv_view($pdo, cv_legacy_group($pdo, $c20, '2026-10-01', '2026-10-02', [$cvAmze + 72]))['status']['key'], '20 orë në 2 ditë: e pamundur');

  /* I njëjti kursant në dy grupe (si te të dhënat e vjetra): pengesë, asgjë nuk lëviz. */
  $ga = cv_legacy_group($pdo, $cvCourse, '2026-12-01', '2026-12-12', [$cvAmze + 80, $cvAmze + 81]);
  $gb = cv_legacy_group($pdo, $cvCourse, '2026-12-01', '2026-12-12', [$cvAmze + 82]);
  $sid = (int)$pdo->query('SELECT id FROM students WHERE CAST(nr_amze AS UNSIGNED) = ' . ($cvAmze + 81))->fetchColumn();
  $pdo->prepare('UPDATE course_group_students cgs JOIN students s ON s.id = cgs.student_id SET cgs.student_id = ? WHERE cgs.group_id = ? AND CAST(s.nr_amze AS UNSIGNED) = ?')->execute([$sid, $gb, $cvAmze + 82]);
  $v = qta_conv_view($pdo, $ga);
  t_eq(['problems', 'in_other_group'], [$v['status']['key'], $v['pre']['blockers'][0]['code']], 'kursant edhe në grup tjetër: Ka probleme');
  t_ok(str_contains($v['pre']['blockers'][0]['text'], (string)($cvAmze + 81)) && str_contains($v['pre']['blockers'][0]['text'], 'Grupi #' . $gb), 'mesazhi thotë kush dhe ku');
  qta_conv_save($pdo, $ga, qta_conv_plan_for_client($v['plan']), 0, $v['fingerprint'], $cvAdmin);
  $before = cv_group_print($pdo, $ga) . cv_group_print($pdo, $gb);
  t_throws(QtaUserError::class, fn() => qta_conv_apply($pdo, $ga, 1, $v['fingerprint'], $cvAdmin), 'konvertimi refuzohet', 'vetëm në një grup');
  t_eq($before, cv_group_print($pdo, $ga) . cv_group_print($pdo, $gb), 'asnjë kursant nuk u hoq, nuk u lëviz');

  /* I njëjti person me dy numra amze në të njëjtin kurs. */
  $gc = cv_legacy_group($pdo, $cvCourse, '2027-01-04', '2027-01-15', [$cvAmze + 90]);
  $gd = cv_legacy_group($pdo, $cvCourse, '2027-02-01', '2027-02-12', [$cvAmze + 91]);
  $pn = 'T' . $cvTag . 'X';
  $pdo->prepare('UPDATE persons p JOIN students s ON s.person_id = p.id SET p.personal_number = ? WHERE CAST(s.nr_amze AS UNSIGNED) = ?')->execute([$pn, $cvAmze + 90]);
  $pid = (int)$pdo->query('SELECT person_id FROM students WHERE CAST(nr_amze AS UNSIGNED) = ' . ($cvAmze + 90))->fetchColumn();
  $pdo->prepare('UPDATE students SET person_id = ? WHERE CAST(nr_amze AS UNSIGNED) = ?')->execute([$pid, $cvAmze + 91]);
  t_eq('same_course_twice', qta_conv_view($pdo, $gc)['pre']['blockers'][0]['code'] ?? null, 'i njëjti person dy herë: pengesë');
  t_eq('same_course_twice', qta_conv_view($pdo, $gd)['pre']['blockers'][0]['code'] ?? null, 'edhe te grupi tjetër');

  /* Provim para mbarimit (si te të dhënat e vjetra, i shkruar drejtpërdrejt) dhe 11 kursantë. */
  $ge = cv_legacy_group($pdo, $cvCourse, '2027-03-01', '2027-03-12', range($cvAmze + 100, $cvAmze + 108));
  $pdo->prepare('INSERT INTO course_group_students (group_id, student_id, exam_date) VALUES (?, ?, ?)')
      ->execute([$ge, qta_amze_ensure_student($pdo, $cvAmze + 109), '2027-03-05']);
  $gf = cv_legacy_group($pdo, $cvCourse, '2027-03-01', '2027-03-12', [$cvAmze + 110]);
  $pdo->prepare('UPDATE course_group_students SET group_id = ? WHERE group_id = ?')->execute([$ge, $gf]);
  $codes = array_column(qta_conv_view($pdo, $ge)['pre']['blockers'], 'code');
  t_ok(in_array('exam_before_end', $codes, true), 'provim para mbarimit: pengesë');
  t_ok(in_array('too_many', $codes, true), '11 kursantë: pengesë');

  /* Lista: gjendjet dhe numrat e çipave. */
  $list = qta_conv_list($pdo, ['q' => $cvTag, 'course_id' => '', 'status' => '']);
  $byId = [];
  foreach ($list['rows'] as $r) $byId[(int)$r['id']] = $r['status']['key'];
  t_eq(['problems', 'impossible', 'problems'], [$byId[$g1] ?? null, $byId[$g2] ?? null, $byId[$ga] ?? null], 'lista: të njëjtat gjendje si faqja');
  t_eq(count($list['rows']), $list['counts'][''], 'numri i të gjithave');
  t_eq($list['counts']['impossible'], count(qta_conv_list($pdo, ['q' => $cvTag, 'course_id' => '', 'status' => ''], 'impossible')['rows']), 'çipi filtron sipas gjendjes');
});

t_case('Konvertimi — e diela e nevojshme shënohet "Kërkon kontroll"', function () use ($pdo, $cvAmze, $cvTag) {
  $c = cv_course($pdo, 'SUN-' . $cvTag, 'Kurs me të diel ' . $cvTag, [['A', [8, 8, 8, 8, 8, 8, 8, 8, 8, 8]]]);
  $g = cv_legacy_group($pdo, $c, '2026-10-01', '2026-10-10', [$cvAmze + 120]);
  $v = qta_conv_view($pdo, $g);
  t_eq(['review', ['2026-10-04']], [$v['status']['key'], $v['summary']['sundays']], '80 orë në 10 ditë: e diela 04.10 kërkon kontroll');
  t_eq([], $v['pre']['blockers'], 'e diela nuk është pengesë');
});

t_case('Konvertimi — fshirja e grupit të konvertuar pastron edhe planin dhe shënimin', function () use ($pdo) {
  $gid = $GLOBALS['CV_SECOND'];
  qta_lg_delete($pdo, $gid, ['force' => true]);
  t_eq(0, cv_schedule_rows($pdo, $gid), 'orari, plani dhe shënimi u fshinë');
  t_eq(1, cv_count($pdo, "SELECT COUNT(*) FROM audit_events WHERE table_name = 'group_conversions' AND action = 'DELETE' AND row_pk LIKE ?", ['%' . $gid . '%']), 'historiku: shënimi i konvertimit u hoq me grupin');
});
