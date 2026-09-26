<?php
declare(strict_types=1);

/**
 * Integrimi me databazën: struktura e kursit, grupet me orar, rillogaritja,
 * kopja e temave, kursantët, fshirja, historiku dhe izolimi i grupeve të mëparshme.
 *
 * Vetëm në një databazë testimi (QTA_TEST_DB=1). Testet krijojnë të dhënat e veta.
 */

require_once __DIR__ . '/../../app/shared/database.php';
require_once __DIR__ . '/../../app/shared/lesson_groups.php';

$pdo = getPDO();
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($dbName === 'qta_db' && getenv('QTA_ALLOW_MAIN_DB') !== '1') {
  fwrite(STDERR, "Refuzohet: testet e integrimit nuk punojnë mbi qta_db.\n");
  exit(2);
}
$adminId = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'administrator' ORDER BY u.id LIMIT 1")->fetchColumn();
$pdo->exec('SET @audit_user_id = ' . $adminId . ", @audit_ip = '127.0.0.1', @audit_ua = 'tests'");

/* ------------------------------------------------------------ Ndihmës */

function it_legacy_fingerprint(PDO $pdo, array $legacyIds): string
{
  $ph = implode(',', array_map('intval', $legacyIds));
  $pdo->exec('SET SESSION group_concat_max_len = 10000000');
  $a = $pdo->query("SELECT GROUP_CONCAT(CONCAT_WS('|',id,course_id,start_date,end_date,is_completed,model,IFNULL(exam_date,'-')) ORDER BY id) FROM course_groups WHERE id IN ($ph)")->fetchColumn();
  $b = $pdo->query("SELECT GROUP_CONCAT(CONCAT_WS('|',group_id,student_id,IFNULL(final_score,'-'),IFNULL(exam_date,'-')) ORDER BY group_id, student_id) FROM course_group_students WHERE group_id IN ($ph)")->fetchColumn();
  return md5((string)$a . '#' . (string)$b);
}

function it_course(PDO $pdo, string $code, string $name, int $hours, array $modules): int
{
  $pdo->prepare('INSERT INTO courses (code, name, hours) VALUES (?, ?, ?)')->execute([$code, $name, $hours]);
  $cid = (int)$pdo->lastInsertId();
  foreach ($modules as [$title, $mh, $topics]) {
    $mid = qta_curriculum_add_module($pdo, $cid, $title, $mh);
    foreach ($topics as $i => $th) {
      qta_curriculum_add_topic($pdo, $mid, $title . ' — tema ' . ($i + 1), $th);
    }
  }
  return $cid;
}

function it_count(PDO $pdo, string $sql, array $p = []): int
{
  $st = $pdo->prepare($sql);
  $st->execute($p);
  return (int)$st->fetchColumn();
}

function it_signature(PDO $pdo, int $gid): string
{
  $days = qta_lg_days($pdo, $gid);
  return md5(json_encode($days));
}

$W = [2, 2, 2, 2, 1, 1];
$W20 = array_merge($W, $W);
$legacyIds = array_map('intval', $pdo->query("SELECT id FROM course_groups WHERE model = 'legacy' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$legacyBefore = it_legacy_fingerprint($pdo, $legacyIds);
$tag = substr(md5((string)microtime(true)), 0, 5);

/* ============================================================== Testet */

t_case('Integrimi: grupet ekzistuese janë të gjitha të mëparshme pas migrimit', function () use ($pdo, $legacyIds) {
  t_ok(count($legacyIds) > 0, 'ka grupe të mëparshme në databazë');
  t_eq(0, it_count($pdo, "SELECT COUNT(*) FROM course_groups WHERE model <> 'legacy'"), 'asnjë grup ekzistues nuk u bë grup me orar');
  t_eq(0, it_count($pdo, 'SELECT COUNT(*) FROM group_schedules'), 'asnjë orar i krijuar për grupet ekzistuese');
});

t_case('Pranimi A — struktura ruhet, radha është e qartë, historiku shënohet', function () use ($pdo, $W, $tag) {
  $before = it_count($pdo, "SELECT COUNT(*) FROM audit_events WHERE table_name IN ('course_modules','course_topics')");
  $cid = it_course($pdo, 'MSO50-' . $tag, 'Microsoft Office', 50,
    [['Word', 10, $W], ['Excel', 10, $W], ['PowerPoint', 10, $W], ['Access', 10, $W], ['Outlook', 10, $W]]);
  $course = qta_course_find($pdo, $cid);
  $modules = qta_course_modules($pdo, $cid);
  $check = qta_course_check($course, $modules);
  t_eq(true, $check['ready'], 'kursi 50 orë me 5 × 10 orë është gati');
  t_eq([1, 2, 3, 4, 5], array_column($modules, 'position'), 'modulet kanë radhën 1…5');
  t_eq([1, 2, 3, 4, 5, 6], array_column($modules[0]['topics'], 'position'), 'temat e Word kanë radhën 1…6');
  $sum = qta_course_summaries($pdo, [$cid]);
  t_eq(true, $sum[$cid]['ready'], 'përmbledhja e listës përputhet: gati');
  t_eq(35, it_count($pdo, "SELECT COUNT(*) FROM audit_events WHERE table_name IN ('course_modules','course_topics')") - $before, 'historiku: 5 module + 30 tema');

  /* Lëvizja: Excel del i pari; vetëm dy module ndryshojnë vend. */
  $excel = $modules[1]['id'];
  $auditBefore = it_count($pdo, "SELECT COUNT(*) FROM audit_events WHERE table_name = 'course_modules' AND action = 'UPDATE'");
  $moved = qta_curriculum_move($pdo, 'module', $excel, -1);
  t_eq(1, $moved['position'], 'Excel është tani i pari');
  $after = qta_course_modules($pdo, $cid);
  t_eq(['Excel', 'Word', 'PowerPoint', 'Access', 'Outlook'], array_column($after, 'title'), 'radha e re ruhet');
  t_eq(2, it_count($pdo, "SELECT COUNT(*) FROM audit_events WHERE table_name = 'course_modules' AND action = 'UPDATE'") - $auditBefore, 'historiku: vetëm dy module ndryshuan vend');

  /* Vendosja direkte në një vend. */
  qta_curriculum_update_module($pdo, $excel, ['position' => 5]);
  t_eq(['Word', 'PowerPoint', 'Access', 'Outlook', 'Excel'], array_column(qta_course_modules($pdo, $cid), 'title'), 'Excel në vendin 5');
  qta_curriculum_update_module($pdo, $excel, ['position' => 2]);
  t_eq(['Word', 'Excel', 'PowerPoint', 'Access', 'Outlook'], array_column(qta_course_modules($pdo, $cid), 'title'), 'Excel kthehet në vendin 2');

  /* Mospërputhja zbulohet, pastaj rregullohet me "Vendos orët e modulit". */
  $word = qta_course_modules($pdo, $cid)[0];
  qta_curriculum_update_topic($pdo, $word['topics'][5]['id'], ['hours' => 2]);
  $check = qta_course_check(qta_course_find($pdo, $cid), qta_course_modules($pdo, $cid));
  t_eq(false, $check['ready'], 'Word me 11 orë tema: jo gati');
  t_eq(false, qta_course_summaries($pdo, [$cid])[$cid]['ready'], 'përmbledhja: jo gati');
  qta_curriculum_update_topic($pdo, $word['topics'][5]['id'], ['hours' => 1]);
  t_eq(true, qta_course_check(qta_course_find($pdo, $cid), qta_course_modules($pdo, $cid))['ready'], 'pas korrigjimit: gati');

  /* Fshirja e një teme rinumëron të tjerat. */
  $tid = qta_curriculum_add_topic($pdo, $word['id'], 'Temë e përkohshme', 1, 2);
  t_eq(2, qta_topic_find($pdo, $tid)['position'], 'tema e re u fut në vendin 2');
  qta_curriculum_delete_topic($pdo, $tid);
  t_eq([1, 2, 3, 4, 5, 6], array_column(qta_course_modules($pdo, $cid)[0]['topics'], 'position'), 'pas fshirjes radha është 1…6');

  /* Radha e prishur jashtë aplikacionit zbulohet dhe rregullohet. */
  $pdo->prepare('UPDATE course_modules SET position = 1 WHERE id = ?')->execute([qta_course_modules($pdo, $cid)[3]['id']]);
  t_eq(false, qta_course_check(qta_course_find($pdo, $cid), qta_course_modules($pdo, $cid))['ready'], 'dy module në vendin 1: jo gati');
  t_eq(false, qta_course_summaries($pdo, [$cid])[$cid]['ready'], 'përmbledhja e zbulon edhe ajo');
  t_throws(QtaUserError::class, fn() => qta_lg_preview_new($pdo, $cid, '01.10.2026', 5), 'grupi nuk krijohet me radhë të paqartë', 'nuk është ende gati');
  qta_curriculum_normalize($pdo, $cid);
  t_eq(true, qta_course_check(qta_course_find($pdo, $cid), qta_course_modules($pdo, $cid))['ready'], '"Rregullo radhën" e zgjidh');
});

t_case('Kursi jo gati nuk përdoret për grup me orar', function () use ($pdo, $W, $tag) {
  $cid = it_course($pdo, 'BAD-' . $tag, 'Kurs i paplotë', 30, [['A', 10, $W], ['B', 10, [2, 2]]]);
  $groupsBefore = it_count($pdo, 'SELECT COUNT(*) FROM course_groups');
  $studentsBefore = it_count($pdo, 'SELECT COUNT(*) FROM students');
  t_throws(QtaUserError::class, fn() => qta_lg_create($pdo, ['course_id' => $cid, 'start_date' => '01.10.2026', 'daily_hours' => 5, 'amze_spec' => '9901-9903']),
    'krijimi refuzohet', 'nuk është ende gati');
  t_eq($groupsBefore, it_count($pdo, 'SELECT COUNT(*) FROM course_groups'), 'asnjë grup nuk u krijua');
  t_eq($studentsBefore, it_count($pdo, 'SELECT COUNT(*) FROM students'), 'asnjë kursant bosh nuk mbeti');
  $empty = it_course($pdo, 'EMPTY-' . $tag, 'Kurs pa module', 20, []);
  t_throws(QtaUserError::class, fn() => qta_lg_preview_new($pdo, $empty, '01.10.2026', 5), 'kurs pa module', 'nuk ka ende module');
});

t_case('Pranimi C — grup i ri, 100 orë, 01.10.2026, 5 orë/ditë, 11.10 me 4 orë', function () use ($pdo, $W20, $tag) {
  $cid = it_course($pdo, 'MSO100-' . $tag, 'Microsoft Office 100', 100,
    [['Word', 20, $W20], ['Excel', 20, $W20], ['PowerPoint', 20, $W20], ['Access', 20, $W20], ['Outlook', 20, $W20]]);
  $preview = qta_lg_preview_new($pdo, $cid, '01.10.2026', '5');
  t_eq('2026-10-23', $preview['summary']['end_date'], 'parashikimi: mbaron 23.10.2026 (20 ditë)');

  $res = qta_lg_create($pdo, ['course_id' => $cid, 'start_date' => '01.10.2026', 'daily_hours' => 5]);
  $gid = $res['groups'][0]['group_id'];
  $g = qta_lg_find($pdo, $gid);
  t_eq('scheduled', $g['model'], 'grupi është grup me orar');
  t_eq(['2026-10-01', '2026-10-23'], [$g['start_date'], $g['end_date']], 'datat e grupit vijnë nga orari');
  t_eq(20, (int)$g['teaching_days'], '20 ditë mësimi');
  t_eq(1, (int)$g['revision'], 'versioni 1');

  $r = qta_lg_change($pdo, $gid, ['type' => 'rule', 'date' => '11.10.2026', 'mode' => 'hours', 'hours' => 4], ['revision' => 1, 'today' => '2026-09-26']);
  t_eq('2026-10-23', $r['new']['end_date'], 'me 11.10 (e diel) 4 orë: mbaron 23.10.2026');
  $g = qta_lg_find($pdo, $gid);
  t_eq('2026-10-23', $g['end_date'], 'data e mbarimit në grup: 23.10.2026');
  t_eq(21, (int)$g['teaching_days'], '21 ditë mësimi');
  $days = qta_lg_days($pdo, $gid);
  $last = end($days);
  t_eq(['2026-10-23', 1], [$last['date'], $last['hours']], 'dita e fundit: 23.10 me 1 orë');
  t_eq(99, array_sum(array_column(array_slice($days, 0, -1), 'hours')), '99 orë para ditës së fundit');
  $byDate = array_column($days, 'hours', 'date');
  t_eq(4, $byDate['2026-10-11'] ?? null, 'e diela 11.10 ka 4 orë');
  t_ok(!isset($byDate['2026-10-04']) && !isset($byDate['2026-10-18']), 'të dielat e tjera pa mësim');
  t_eq(5, $byDate['2026-10-12'] ?? null, 'e hëna 12.10 ka 5 orë');
  t_eq(100, it_count($pdo, 'SELECT SUM(hours) FROM group_schedule_days WHERE group_id = ?', [$gid]), 'Σ ditë = 100');
  t_eq(100, it_count($pdo, 'SELECT SUM(hours) FROM group_schedule_slots WHERE group_id = ?', [$gid]), 'Σ pjesë = 100');
  t_eq(0, it_count($pdo, 'SELECT COUNT(*) FROM (SELECT t.seq FROM group_schedule_topics t LEFT JOIN group_schedule_slots s ON s.group_id = t.group_id AND s.topic_seq = t.seq WHERE t.group_id = ? GROUP BY t.seq, t.topic_hours HAVING COALESCE(SUM(s.hours),0) <> t.topic_hours) x', [$gid]), 'çdo temë ka pikërisht orët e veta');
  t_eq(0, it_count($pdo, 'SELECT COUNT(*) FROM (SELECT t.module_seq FROM group_schedule_topics t JOIN group_schedule_slots s ON s.group_id = t.group_id AND s.topic_seq = t.seq WHERE t.group_id = ? GROUP BY t.module_seq, t.module_hours HAVING SUM(s.hours) <> t.module_hours) x', [$gid]), 'çdo modul ka pikërisht orët e veta');
  $GLOBALS['IT_GROUP_C'] = $gid;
  $GLOBALS['IT_COURSE_C'] = $cid;
});

t_case('Pranimi E — rillogaritja 5 → 4 orë, pastaj kthim i saktë', function () use ($pdo) {
  $gid = $GLOBALS['IT_GROUP_C'];
  $sigBefore = it_signature($pdo, $gid);
  $rev = (int)qta_lg_find($pdo, $gid)['revision'];

  $dry = qta_lg_change($pdo, $gid, ['type' => 'rule', 'date' => '06.10.2026', 'mode' => 'hours', 'hours' => 4], ['revision' => $rev, 'dry_run' => true, 'today' => '2026-09-26']);
  t_eq($sigBefore, it_signature($pdo, $gid), 'parashikimi nuk ndryshon asgjë');
  t_eq('2026-10-06', $dry['first_changed'], 'parashikimi: ndryshimi nis te 06.10');

  $r = qta_lg_change($pdo, $gid, ['type' => 'rule', 'date' => '06.10.2026', 'mode' => 'hours', 'hours' => 4], ['revision' => $rev, 'today' => '2026-09-26']);
  $days = qta_lg_days($pdo, $gid);
  $byDate = array_column($days, 'hours', 'date');
  t_eq(4, $byDate['2026-10-06'], '06.10 ka 4 orë');
  t_eq(100, array_sum(array_column($days, 'hours')), 'totali mbetet 100');
  t_eq('2026-10-23', $r['new']['end_date'], 'mbarimi mbetet 23.10');
  t_eq(2, end($days)['hours'], 'dita e fundit ka tani 2 orë');
  $topics = qta_lg_topics($pdo, $gid);
  t_eq([], qta_sched_verify($topics, ['start_date' => $days[0]['date'], 'end_date' => end($days)['date'], 'days' => $days], 5, qta_lg_rule_map(qta_lg_rules($pdo, $gid))), 'orari i ruajtur mban të gjitha rregullat');

  $r = qta_lg_change($pdo, $gid, ['type' => 'rule', 'date' => '2026-10-06', 'mode' => 'remove'], ['revision' => $r['revision'], 'today' => '2026-09-26']);
  t_eq($sigBefore, it_signature($pdo, $gid), 'pas kthimit orari është identik me të parin');

  /* Një ditë pa mësim zhvendos mbarimin dhe rikthehet. */
  $r = qta_lg_change($pdo, $gid, ['type' => 'rule', 'date' => '23.10.2026', 'mode' => 'off', 'note' => 'Festë'], ['revision' => $r['revision'], 'today' => '2026-09-26']);
  t_eq('2026-10-24', qta_lg_find($pdo, $gid)['end_date'], '23.10 pa mësim → mbaron 24.10');
  $r = qta_lg_change($pdo, $gid, ['type' => 'rule', 'date' => '23.10.2026', 'mode' => 'remove'], ['revision' => $r['revision'], 'today' => '2026-09-26']);
  t_eq('2026-10-23', qta_lg_find($pdo, $gid)['end_date'], 'kthimi: 23.10');
  t_eq($sigBefore, it_signature($pdo, $gid), 'orari identik pas kthimit të dytë');

  /* Versioni i vjetër refuzohet. */
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, ['type' => 'settings', 'start_date' => '02.10.2026', 'daily_hours' => 5], ['revision' => 1, 'today' => '2026-09-26']),
    'versioni i vjetër i faqes refuzohet', 'dikush tjetër');

  /* Orët në ditë ndryshojnë para fillimit pa konfirmim. */
  $r = qta_lg_change($pdo, $gid, ['type' => 'settings', 'start_date' => '01.10.2026', 'daily_hours' => 4], ['revision' => $r['revision'], 'today' => '2026-09-26']);
  t_eq(4, (int)qta_lg_find($pdo, $gid)['daily_hours'], '4 orë në ditë');
  t_eq(100, it_count($pdo, 'SELECT SUM(hours) FROM group_schedule_days WHERE group_id = ?', [$gid]), 'totali 100 edhe me 4 orë');
  $r = qta_lg_change($pdo, $gid, ['type' => 'settings', 'start_date' => '01.10.2026', 'daily_hours' => 5], ['revision' => $r['revision'], 'today' => '2026-09-26']);
  t_eq($sigBefore, it_signature($pdo, $gid), 'kthimi në 5 orë jep të njëjtin orar');

  /* Gabimet e hyrjeve. */
  $rev = (int)qta_lg_find($pdo, $gid)['revision'];
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, ['type' => 'settings', 'start_date' => '04.10.2026', 'daily_hours' => 5], ['revision' => $rev]), 'fillimi të dielën refuzohet', 'nuk është ditë mësimi');
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, ['type' => 'settings', 'start_date' => '31.09.2026', 'daily_hours' => 5], ['revision' => $rev]), 'datë e pavlefshme', 'dd.mm.vvvv');
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, ['type' => 'settings', 'start_date' => '01.10.2026', 'daily_hours' => 0], ['revision' => $rev]), '0 orë në ditë', 'nga 1 deri');
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, ['type' => 'rule', 'date' => '07.10.2026', 'mode' => 'hours', 'hours' => 15], ['revision' => $rev]), 'ditë me 15 orë', 'nga 0 deri');
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, ['type' => 'rule', 'date' => '20.09.2026', 'mode' => 'hours', 'hours' => 3], ['revision' => $rev]), 'rregull para fillimit', 'para fillimit');
  t_eq($sigBefore, it_signature($pdo, $gid), 'gabimet nuk lanë gjurmë');
});

t_case('Pranimi F — ndryshimi i kursit nuk prek orarin e grupeve ekzistuese', function () use ($pdo) {
  $gid = $GLOBALS['IT_GROUP_C'];
  $cid = $GLOBALS['IT_COURSE_C'];
  $sig = it_signature($pdo, $gid);
  $snapshot = qta_lg_topics($pdo, $gid);

  $modules = qta_course_modules($pdo, $cid);
  qta_curriculum_update_topic($pdo, $modules[0]['topics'][0]['id'], ['title' => 'Hyrje e re në Word']);
  qta_curriculum_update_module($pdo, $modules[4]['id'], ['title' => 'Outlook dhe Teams']);
  qta_curriculum_move($pdo, 'module', $modules[1]['id'], -1);
  t_eq($sig, it_signature($pdo, $gid), 'orari i grupit nuk ndryshoi');
  t_eq($snapshot, qta_lg_topics($pdo, $gid), 'kopja e temave nuk ndryshoi');
  t_ok(qta_lg_curriculum_changed($snapshot, qta_course_modules($pdo, $cid)), 'ndryshimi i kursit dallohet');

  /* Grupi nuk ka nisur (sot 26.09): merr temat e reja, me konfirmim të qartë nga ndërfaqja. */
  $rev = (int)qta_lg_find($pdo, $gid)['revision'];
  $r = qta_lg_change($pdo, $gid, ['type' => 'refresh'], ['revision' => $rev, 'today' => '2026-09-26']);
  $fresh = qta_lg_topics($pdo, $gid);
  t_eq('Hyrje e re në Word', $fresh[12]['topic_title'] ?? null, 'kopja e re ka emrin e ri (Word është tani moduli 2, tema 13 e kursit)');
  t_eq([2, 1], [$fresh[12]['module_seq'], $fresh[12]['topic_seq']], 'tema e parë e Word: moduli 2, tema 1');
  t_eq('Excel', $fresh[0]['module_title'], 'kopja e re ndjek radhën e re të moduleve');
  t_ok(!qta_lg_curriculum_changed($fresh, qta_course_modules($pdo, $cid)), 'grupi përputhet me kursin');

  /* Në një grup që ka nisur, temat nuk rimerren. */
  qta_curriculum_update_topic($pdo, $modules[2]['topics'][0]['id'], ['title' => 'Tjetër emër']);
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, ['type' => 'refresh'], ['revision' => $r['revision'], 'today' => '2026-10-05']),
    'grupi ka nisur: temat nuk rimerren', 'ka nisur');
  /* Kursi jo gati nuk jep kopje të re. */
  qta_curriculum_update_topic($pdo, $modules[2]['topics'][0]['id'], ['hours' => 5]);
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, ['type' => 'refresh'], ['revision' => $r['revision'], 'today' => '2026-09-26']),
    'kursi jo gati: nuk merret kopje', 'nuk është ende gati');
  qta_curriculum_update_topic($pdo, $modules[2]['topics'][0]['id'], ['hours' => 2]);
});

t_case('Ditët që kanë kaluar dhe grupi i mbyllur kërkojnë konfirmim', function () use ($pdo, $W, $tag) {
  $cid = it_course($pdo, 'PAST-' . $tag, 'Kurs që ka nisur', 20, [['A', 10, $W], ['B', 10, $W]]);
  $gid = qta_lg_create($pdo, ['course_id' => $cid, 'start_date' => '07.09.2026', 'daily_hours' => 2])['groups'][0]['group_id'];
  $today = '2026-09-15';
  $rev = (int)qta_lg_find($pdo, $gid)['revision'];

  $e = t_throws(QtaConfirmNeeded::class, fn() => qta_lg_change($pdo, $gid, ['type' => 'settings', 'start_date' => '07.09.2026', 'daily_hours' => 3], ['revision' => $rev, 'today' => $today]),
    'orët në ditë në një grup që ka nisur: pyet', 'kanë kaluar');
  t_eq($rev, (int)qta_lg_find($pdo, $gid)['revision'], 'pa konfirmim nuk ndryshoi asgjë');

  $r = qta_lg_change($pdo, $gid, ['type' => 'rule', 'date' => '17.09.2026', 'mode' => 'hours', 'hours' => 4], ['revision' => $rev, 'today' => $today]);
  t_eq([], $r['past_changed'], 'një datë në të ardhmen nuk prek ditët e kaluara — pa pyetje');

  $e = t_throws(QtaConfirmNeeded::class, fn() => qta_lg_change($pdo, $gid, ['type' => 'rule', 'date' => '10.09.2026', 'mode' => 'off'], ['revision' => $r['revision'], 'today' => $today]),
    'një datë e kaluar: pyet', 'ditë');
  $r = qta_lg_change($pdo, $gid, ['type' => 'rule', 'date' => '10.09.2026', 'mode' => 'off'], ['revision' => $r['revision'], 'today' => $today, 'force' => true]);
  t_ok(count($r['past_changed']) > 0, 'me konfirmim ndryshon dhe raporton ditët e kaluara');

  $pdo->prepare('UPDATE course_groups SET is_completed = 1 WHERE id = ?')->execute([$gid]);
  t_throws(QtaConfirmNeeded::class, fn() => qta_lg_change($pdo, $gid, ['type' => 'rule', 'date' => '30.09.2026', 'mode' => 'hours', 'hours' => 1], ['revision' => $r['revision'], 'today' => $today]),
    'grup i mbyllur: pyet', 'mbyllur');
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, ['type' => 'refresh'], ['revision' => $r['revision'], 'today' => '2026-01-01']),
    'grup i mbyllur: temat nuk rimerren', 'mbyllur');
  $pdo->prepare('UPDATE course_groups SET is_completed = 0 WHERE id = ?')->execute([$gid]);
});

t_case('Provimi para mbarimit të ri e ndalon ndryshimin — asgjë gjysmake', function () use ($pdo, $W, $tag) {
  $cid = it_course($pdo, 'EXAM-' . $tag, 'Kurs me provim', 10, [['A', 10, $W]]);
  $res = qta_lg_create($pdo, ['course_id' => $cid, 'start_date' => '01.10.2026', 'daily_hours' => 5, 'amze_spec' => '98001-98003']);
  $gid = $res['groups'][0]['group_id'];
  t_eq(3, $res['groups'][0]['count'], '3 kursantë');
  $end = qta_lg_find($pdo, $gid)['end_date'];
  t_eq('2026-10-02', $end, '10 orë × 5: mbaron 02.10');
  $pdo->prepare('UPDATE course_group_students SET exam_date = ? WHERE group_id = ?')->execute([$end, $gid]);
  $sig = it_signature($pdo, $gid);
  $rev = (int)qta_lg_find($pdo, $gid)['revision'];
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $gid, ['type' => 'rule', 'date' => '02.10.2026', 'mode' => 'off'], ['revision' => $rev, 'today' => '2026-09-26']),
    'mbarimi pas provimit refuzohet', 'datës së provimit');
  t_eq($sig, it_signature($pdo, $gid), 'orari mbeti i paprekur');
  t_eq(0, it_count($pdo, 'SELECT COUNT(*) FROM group_day_rules WHERE group_id = ?', [$gid]), 'rregulli nuk u ruajt');
  t_eq('2026-10-02', qta_lg_find($pdo, $gid)['end_date'], 'data e mbarimit mbeti');
});

t_case('Kursantët: ndarja mbi 10, dyfishimi, kufiri 10, heqja me pikë', function () use ($pdo, $W, $tag) {
  $cid = it_course($pdo, 'MEM-' . $tag, 'Kurs për kursantët', 10, [['A', 10, $W]]);
  $res = qta_lg_create($pdo, ['course_id' => $cid, 'start_date' => '05.10.2026', 'daily_hours' => 5, 'amze_spec' => '97001-97012']);
  t_eq(2, count($res['groups']), '12 kursantë → 2 grupe');
  t_eq([6, 6], array_column($res['groups'], 'count'), 'grupe të barabarta 6 + 6');
  t_eq([97001, 97006, 97007, 97012], [$res['groups'][0]['amze_min'], $res['groups'][0]['amze_max'], $res['groups'][1]['amze_min'], $res['groups'][1]['amze_max']], 'radha e amzës ruhet');
  [$g1, $g2] = array_column($res['groups'], 'group_id');
  t_eq(it_signature($pdo, $g1), it_signature($pdo, $g2), 'të dy grupet kanë të njëjtin orar');
  t_eq(1, it_count($pdo, 'SELECT COUNT(*) FROM group_schedules WHERE group_id = ?', [$g2]), 'grupi i dytë ka orarin e vet');

  t_throws(QtaUserError::class, fn() => qta_lg_set_members($pdo, $g1, '97001-97006, 97007'), 'një amzë nga grupi tjetër refuzohet', 'tashmë në një grup');
  t_throws(QtaUserError::class, fn() => qta_lg_set_members($pdo, $g1, '97001-97006, 97100-97104'), 'mbi 10 kursantë refuzohet', 'deri në 10');
  $r = qta_lg_set_members($pdo, $g1, '97001-97006, 97100');
  t_eq([1, 0, 7], [$r['added'], $r['removed'], $r['total']], 'u shtua 1 kursant');
  $pdo->prepare("UPDATE course_group_students cgs JOIN students s ON s.id = cgs.student_id SET cgs.exam_date = '2026-10-10', cgs.final_score = 70 WHERE cgs.group_id = ? AND s.nr_amze = '97100'")->execute([$g1]);
  t_throws(QtaConfirmNeeded::class, fn() => qta_lg_set_members($pdo, $g1, '97001-97006'), 'heqja e një kursanti me pikë kërkon konfirmim', 'pikë');
  $r = qta_lg_set_members($pdo, $g1, '97001-97006', ['force' => true]);
  t_eq(1, $r['removed'], 'me konfirmim u hoq');
  t_throws(QtaUserError::class, fn() => qta_lg_set_members($pdo, $g1, 'abc'), 'teksti i pavlefshëm refuzohet', 'Nuk e kuptova');
  t_throws(QtaUserError::class, fn() => qta_lg_set_members($pdo, $g1, '1-100000'), 'interval tepër i gjatë', 'shumë i gjatë');
  $GLOBALS['IT_MEMBERS_G2'] = $g2;
});

t_case('Pranimi D — grupi i mëparshëm mbetet i paprekur dhe pa orar', function () use ($pdo, $legacyIds, $legacyBefore) {
  $legacy = $legacyIds[0];
  t_throws(QtaUserError::class, fn() => qta_lg_change($pdo, $legacy, ['type' => 'rule', 'date' => '01.10.2026', 'mode' => 'off']), 'shërbimi i orarit refuzon grupin e mëparshëm', 'grup i mëparshëm');
  t_throws(QtaUserError::class, fn() => qta_lg_set_members($pdo, $legacy, '1001'), 'kursantët e grupit të mëparshëm nuk preken këtu', 'grup i mëparshëm');
  t_throws(QtaUserError::class, fn() => qta_lg_delete($pdo, $legacy, ['force' => true]), 'fshirja e orarit nuk vlen për grupin e mëparshëm', 'grup i mëparshëm');
  t_throws(PDOException::class, fn() => $pdo->exec("UPDATE course_groups SET model = 'scheduled' WHERE id = " . $legacy), 'baza nuk lejon kthimin në grup me orar', 'nuk mund të ndryshohet');
  t_throws(PDOException::class, fn() => $pdo->exec('INSERT INTO group_schedules (group_id, daily_hours, course_hours, curriculum_taken_at) VALUES (' . $legacy . ', 5, 10, NOW())'), 'baza nuk lejon orar për grupin e mëparshëm', 'vetëm me grupet me orar');
  t_throws(PDOException::class, fn() => $pdo->exec('INSERT INTO group_schedule_days (group_id, day_seq, lesson_date, hours) VALUES (' . $legacy . ', 1, \'2026-10-01\', 5)'), 'baza nuk lejon ditë mësimi pa orar', 'foreign key');
  t_eq($legacyBefore, it_legacy_fingerprint($pdo, $legacyIds), 'grupet e mëparshme: datat, kursantët, provimet dhe pikët identike');
  t_eq(0, it_count($pdo, 'SELECT COUNT(*) FROM group_schedules WHERE group_id IN (' . implode(',', $legacyIds) . ')'), 'asnjë orar për grupet e mëparshme');
  $firstCourse = (int)$pdo->query('SELECT course_id FROM course_groups WHERE id = ' . $legacy)->fetchColumn();
  t_throws(QtaUserError::class, fn() => qta_course_delete($pdo, $firstCourse), 'kursi me grupe të mëparshme nuk fshihet', 'nuk u fshi');
  t_throws(PDOException::class, fn() => $pdo->exec('DELETE FROM courses WHERE id = ' . $firstCourse), 'edhe baza e ndalon fshirjen zinxhir', 'foreign key');
});

t_case('Grupi me orar nuk kalon te një kurs tjetër', function () use ($pdo) {
  $gid = $GLOBALS['IT_GROUP_C'];
  $other = (int)$pdo->query('SELECT id FROM courses ORDER BY id LIMIT 1')->fetchColumn();
  t_throws(PDOException::class, fn() => $pdo->exec('UPDATE course_groups SET course_id = ' . $other . ' WHERE id = ' . $gid), 'baza e ndalon', 'kurs tjetër');
});

t_case('Fshirja e grupit me orar dhe e kursit pa grupe', function () use ($pdo, $W, $tag) {
  $g2 = $GLOBALS['IT_MEMBERS_G2'];
  t_throws(QtaConfirmNeeded::class, fn() => qta_lg_delete($pdo, $g2), 'fshirja pa konfirmim pyet', 'nuk fshihen');
  $students = it_count($pdo, 'SELECT COUNT(*) FROM students');
  $r = qta_lg_delete($pdo, $g2, ['force' => true]);
  t_eq(6, $r['members'], '6 kursantë dolën nga grupi');
  foreach (['group_schedules', 'group_schedule_days', 'group_schedule_slots', 'group_schedule_topics', 'group_day_rules', 'course_group_students'] as $t) {
    t_eq(0, it_count($pdo, "SELECT COUNT(*) FROM $t WHERE group_id = ?", [$g2]), "$t: asnjë rresht i mbetur");
  }
  t_eq($students, it_count($pdo, 'SELECT COUNT(*) FROM students'), 'kursantët nuk u fshinë');
  t_ok(it_count($pdo, "SELECT COUNT(*) FROM audit_events WHERE table_name = 'group_schedules' AND action = 'DELETE' AND row_pk LIKE ?", ['%' . $g2 . '%']) === 1, 'historiku: fshirja e orarit u shënua');

  $cid = it_course($pdo, 'DEL-' . $tag, 'Kurs për fshirje', 10, [['A', 10, $W]]);
  $r = qta_course_delete($pdo, $cid);
  t_eq([1, 6], [$r['modules'], $r['topics']], 'kursi pa grupe u fshi me modulin dhe 6 temat');
  t_eq(0, it_count($pdo, 'SELECT COUNT(*) FROM course_modules WHERE course_id = ?', [$cid]), 'asnjë modul i mbetur');
});

t_case('Historiku ka përdoruesin për çdo ndryshim të ri', function () use ($pdo, $adminId) {
  foreach (['course_modules', 'course_topics', 'group_schedules', 'group_day_rules'] as $t) {
    t_ok(it_count($pdo, 'SELECT COUNT(*) FROM audit_events WHERE table_name = ? AND user_id = ?', [$t, $adminId]) > 0, "$t shënohet me përdoruesin");
  }
  t_ok(it_count($pdo, "SELECT COUNT(*) FROM audit_event_fields f JOIN audit_events e ON e.id = f.event_id WHERE e.table_name = 'course_groups' AND f.column_name = 'model' AND f.new_value = 'scheduled'") > 0, 'krijimi i grupit shënon llojin "me orar"');
});

t_case('Dy ndryshime njëkohësisht: i dyti pret derisa i pari mbaron', function () use ($pdo) {
  $gid = $GLOBALS['IT_GROUP_C'];
  $other = getPDO();
  $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
  $pdo->beginTransaction();
  qta_lg_require($pdo, $gid, true); // mban bllokun
  try {
    $err = t_throws(PDOException::class, fn() => qta_lg_change($other, $gid, ['type' => 'rule', 'date' => '07.10.2026', 'mode' => 'hours', 'hours' => 3], ['today' => '2026-09-26']),
      'lidhja e dytë pret bllokun dhe nuk shkruan', 'Lock wait timeout');
  } finally {
    $pdo->rollBack();
  }
  t_eq(0, it_count($pdo, "SELECT COUNT(*) FROM group_day_rules WHERE group_id = ? AND rule_date = '2026-10-07'", [$gid]), 'asgjë nga lidhja e dytë nuk u ruajt');
});
