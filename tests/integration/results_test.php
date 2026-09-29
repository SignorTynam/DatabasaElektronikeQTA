<?php
declare(strict_types=1);

/**
 * Integrimi me databazën: pikët sipas moduleve dhe rezultati përfundimtar
 * (app/shared/results.php, app/actions/group_results.php dhe rregullat e migrimit
 * 2026-09-28-piket-sipas-moduleve.sql).
 *
 * Vetëm në një databazë testimi (QTA_TEST_DB=1) me të tre migrimet. Testi krijon
 * kurset, grupet dhe kursantët e vet.
 */

require_once __DIR__ . '/../../app/shared/database.php';
require_once __DIR__ . '/../../app/shared/lesson_groups.php';
require_once __DIR__ . '/../../app/shared/results.php';

$pdo = getPDO();
$rsDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($rsDb === 'qta_db' && getenv('QTA_ALLOW_MAIN_DB') !== '1') {
  fwrite(STDERR, "Refuzohet: testet e integrimit nuk punojnë mbi qta_db.\n");
  exit(2);
}
if (!$pdo->query("SHOW TABLES LIKE 'enrollment_module_scores'")->fetchColumn()) {
  t_case('Pikët sipas moduleve', function () {
    t_ok(false, 'databaza e testit nuk ka migrimin 2026-09-28-piket-sipas-moduleve.sql');
  });
  return;
}
$rsAdmin = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'administrator' ORDER BY u.id LIMIT 1")->fetchColumn();
$pdo->exec('SET @audit_user_id = ' . $rsAdmin . ", @audit_ip = '127.0.0.1', @audit_ua = 'tests'");

/* ------------------------------------------------------------ Ndihmës */

function rs_count(PDO $pdo, string $sql, array $p = []): int
{
  $st = $pdo->prepare($sql);
  $st->execute($p);
  return (int)$st->fetchColumn();
}

function rs_one(PDO $pdo, string $sql, array $p = [])
{
  $st = $pdo->prepare($sql);
  $st->execute($p);
  return $st->fetchColumn();
}

/** Kurs me module (orët e temave mblidhen në orët e modulit). $modules = [['Word', [5, 5]], …] */
function rs_course(PDO $pdo, string $name, array $modules): int
{
  $hours = array_sum(array_map(static fn($m) => array_sum($m[1]), $modules));
  $pdo->prepare('INSERT INTO courses (code, name, hours) VALUES (?, ?, ?)')->execute(['RS-' . bin2hex(random_bytes(4)), $name, $hours]);
  $cid = (int)$pdo->lastInsertId();
  foreach ($modules as [$title, $topics]) {
    $mid = qta_curriculum_add_module($pdo, $cid, $title, array_sum($topics));
    foreach ($topics as $i => $h) qta_curriculum_add_topic($pdo, $mid, $title . ' — tema ' . ($i + 1), $h);
  }
  return $cid;
}

function rs_cell(int $sid, int $mid, $from, $to): array
{
  return ['student_id' => $sid, 'module_id' => $mid, 'from' => $from, 'to' => $to];
}

function rs_member(array $sheet, int $sid): ?array
{
  foreach ($sheet['members'] as $m) if ($m['student_id'] === $sid) return $m;
  return null;
}

/** Numri i deklaratave që ekzekutoi serveri në këtë lidhje (pa vetë SHOW STATUS). */
function rs_questions(PDO $pdo): int
{
  return (int)$pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'];
}

/** Rezultati i ruajtur (final_score, legacy_final_score) i një kursanti. */
function rs_stored(PDO $pdo, int $gid, int $sid): array
{
  $st = $pdo->prepare('SELECT final_score, legacy_final_score FROM course_group_students WHERE group_id = ? AND student_id = ?');
  $st->execute([$gid, $sid]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return [$r['final_score'] ?? null, $r['legacy_final_score'] ?? null];
}

/** Pikët e moduleve të një kursanti: module_id → "85.50". */
function rs_scores(PDO $pdo, int $gid, int $sid): array
{
  $st = $pdo->prepare('SELECT module_id, score FROM enrollment_module_scores WHERE group_id = ? AND student_id = ? ORDER BY module_id');
  $st->execute([$gid, $sid]);
  return array_map('strval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Shkrim "të dhënash të vjetra" (rezultat me dorë), siç ishin para pikëve sipas moduleve. */
function rs_legacy_final(PDO $pdo, int $gid, int $sid, ?string $value): void
{
  $pdo->exec('SET @qta_results_sync = 1');
  try {
    $pdo->prepare('UPDATE course_group_students SET final_score = ? WHERE group_id = ? AND student_id = ?')->execute([$value, $gid, $sid]);
  } finally {
    $pdo->exec('SET @qta_results_sync = NULL');
  }
}

/** Gabimi i bazës (trigger ose CHECK) për një shkrim që duhet refuzuar. */
function rs_db_refuses(PDO $pdo, string $sql, array $p, string $what, ?string $contains = null): void
{
  t_throws(PDOException::class, function () use ($pdo, $sql, $p) {
    $pdo->prepare($sql)->execute($p);
  }, $what, $contains);
}

$rsTag = substr(md5((string)microtime(true)), 0, 5);
$rsAmze = 900000000 + random_int(0, 8000000) * 10;

/* Kurs 30 orë, 3 module; grup me orar nga e hëna 05.01.2026 me 8 orë në ditë (mbaron 08.01.2026). */
$rsCourse = rs_course($pdo, 'Microsoft Office ' . $rsTag, [['Microsoft Word', [5, 5]], ['Microsoft Excel', [5, 5]], ['PowerPoint', [5, 5]]]);
$rsCreated = qta_lg_create($pdo, ['course_id' => $rsCourse, 'start_date' => '05.01.2026', 'daily_hours' => 8,
                                  'amze_spec' => $rsAmze . '-' . ($rsAmze + 2)]);
$rsGroup = (int)$rsCreated['groups'][0]['group_id'];
$rsSheet0 = qta_results_sheet($pdo, $rsGroup);
[$rsWord, $rsExcel, $rsPpt] = array_column($rsSheet0['modules'], 'id');
[$rsA, $rsB, $rsC] = array_column($rsSheet0['members'], 'student_id');
/* A dhe B kanë datë provimi; C jo ende. */
$pdo->prepare("UPDATE course_group_students SET exam_date = '2026-01-12' WHERE group_id = ? AND student_id IN (?, ?)")->execute([$rsGroup, $rsA, $rsB]);

/* ============================================================== Testet */

t_case('Pikët — fleta: modulet e kopjes në radhë, kursantët sipas amzës, 4 query', function () use ($pdo, $rsGroup, $rsAmze) {
  $before = rs_questions($pdo);
  $sheet = qta_results_sheet($pdo, $rsGroup);
  t_eq(4, rs_questions($pdo) - $before - 1, 'fleta lexohet me 4 query, pa N+1');
  t_eq(['Microsoft Word', 'Microsoft Excel', 'PowerPoint'], array_column($sheet['modules'], 'title'), 'modulet e kopjes, në radhën e kursit');
  t_eq('copy', $sheet['module_source'], 'grupi me orar ndjek kopjen e tij');
  t_eq([(string)$rsAmze, (string)($rsAmze + 1), (string)($rsAmze + 2)], array_column($sheet['members'], 'amze'), 'kursantët sipas amzës');
  t_eq(['none', 'none', 'none'], array_map(static fn($m) => $m['result']['mode'], $sheet['members']), 'askush nuk ka ende pikë');

  $before = rs_questions($pdo);
  $p = qta_results_progress($pdo, [$rsGroup]);
  t_eq(3, rs_questions($pdo) - $before - 1, 'përmbledhja për tabelat: 3 query (grupe me orar)');
  t_eq(3, $p[$rsGroup]['required'], '3 module kërkojnë pikë');
});

t_case('Pikët — ruajtja: vetëm qelizat që ndryshojnë, rezultati nga serveri, historiku', function () use ($pdo, $rsGroup, $rsA, $rsB, $rsWord, $rsExcel, $rsPpt) {
  $auditBefore = rs_count($pdo, "SELECT COUNT(*) FROM audit_events WHERE table_name = 'enrollment_module_scores' AND action = 'INSERT'");
  $r = qta_results_save($pdo, $rsGroup, [
    rs_cell($rsA, $rsWord, null, '85'), rs_cell($rsA, $rsExcel, null, '90'), rs_cell($rsA, $rsPpt, null, '77,5'),
    rs_cell($rsB, $rsWord, null, '0'),
  ]);
  t_eq([4, 2], [$r['changed'], $r['students']], '4 qeliza te 2 kursantë');
  t_eq('Pikët u ruajtën: 4 ndryshime te 2 kursantë.', $r['message'], 'mesazhi përdor foljen e veprimit');
  t_eq(['84.17', null], rs_stored($pdo, $rsGroup, $rsA), 'A: (85 + 90 + 77,5) / 3 = 84,17 — i llogaritur nga serveri');
  t_eq([null, null], rs_stored($pdo, $rsGroup, $rsB), 'B: vetëm Word (0) → i paplotë, pa rezultat');
  t_eq([$rsWord => '0.00'], rs_scores($pdo, $rsGroup, $rsB), '0 ruhet si pikë (jo bosh)');
  t_eq(4, rs_count($pdo, "SELECT COUNT(*) FROM audit_events WHERE table_name = 'enrollment_module_scores' AND action = 'INSERT'") - $auditBefore, 'historiku: 4 pikë moduli');
  t_ok(rs_count($pdo, "SELECT COUNT(*) FROM audit_events ae JOIN audit_event_fields f ON f.event_id = ae.id
                       WHERE ae.table_name = 'course_group_students' AND f.column_name = 'final_score' AND f.new_value = '84.17'") > 0, 'historiku: rezultati i ri i A');
  $b = rs_member($r['sheet'], $rsB);
  t_eq(['modules', 1, 3, null], [$b['result']['mode'], $b['result']['scored'], $b['result']['required'], $b['result']['final']], 'fleta: B 1 nga 3, pa rezultat');

  $again = qta_results_save($pdo, $rsGroup, [rs_cell($rsA, $rsWord, '85.00', '85')]);
  t_eq(0, $again['changed'], 'e njëjta vlerë: asgjë nuk shkruhet');
});

t_case('Pikët — dikush tjetër e ndryshoi ndërkohë: asgjë nuk ruhet', function () use ($pdo, $rsGroup, $rsA, $rsB, $rsWord, $rsExcel) {
  $e = t_throws(QtaUserError::class, fn() => qta_results_save($pdo, $rsGroup, [
    rs_cell($rsB, $rsExcel, null, '70'),
    rs_cell($rsA, $rsWord, null, '60'),       // faqja pa bosh, por A ka 85
  ]), 'vlera e ndryshuar nga dikush tjetër refuzohet', 'dikush tjetër');
  t_eq('stale', $e instanceof QtaUserError ? ($e->data['code'] ?? null) : null, 'code = stale');
  $conf = $e instanceof QtaUserError ? ($e->data['details']['conflicts'] ?? []) : [];
  t_eq([[$rsA, $rsWord, '85.00']], array_map(static fn($c) => [$c['student_id'], $c['module_id'], $c['value']], $conf), 'kthehet vlera e re (85)');
  t_eq([$rsWord => '0.00'], rs_scores($pdo, $rsGroup, $rsB), 'as qeliza e vlefshme e B nuk u ruajt');
  t_eq(0, qta_results_save($pdo, $rsGroup, [rs_cell($rsA, $rsWord, null, '85')])['changed'], 'e njëjta vlerë si ajo e re: nuk ka konflikt');
});

t_case('Pikët — validimi në server: një qelizë e gabuar ndal gjithçka', function () use ($pdo, $rsGroup, $rsB, $rsC, $rsWord, $rsExcel, $rsPpt) {
  $bad = static function (array $cells, string $what, string $contains) use ($pdo, $rsGroup) {
    $e = t_throws(QtaUserError::class, fn() => qta_results_save($pdo, $rsGroup, $cells), $what, $contains);
    return $e instanceof QtaUserError ? ($e->data['code'] ?? null) : null;
  };
  t_eq('invalid', $bad([rs_cell($rsB, $rsExcel, null, '70'), rs_cell($rsB, $rsPpt, null, '101')], 'mbi 100', 'nga 0 deri në 100'), 'code = invalid');
  t_eq([$rsWord => '0.00'], rs_scores($pdo, $rsGroup, $rsB), 'Excel 70 nuk u ruajt (transaksioni)');
  $bad([rs_cell($rsB, $rsExcel, null, '85,255')], 'tri shifra pas presjes', 'dy shifra');
  $bad([rs_cell($rsB, $rsExcel, null, 'abc')], 'jo numër', 'me shifra');

  $other = rs_course($pdo, 'Kurs tjetër', [['Python', [4]]]);
  $py = (int)rs_one($pdo, 'SELECT id FROM course_modules WHERE course_id = ?', [$other]);
  $bad([rs_cell($rsB, $py, null, '80')], 'moduli i një kursi tjetër', 'nuk është te modulet e grupit');
  $outsider = qta_amze_ensure_student($pdo, 999999999);
  $bad([rs_cell($outsider, $rsWord, null, '80')], 'kursant jashtë grupit', 'nuk është më në grup');
  $bad([rs_cell($rsC, $rsWord, null, '80')], 'pa datë provimi', 'datë provimi');
  t_throws(QtaUserError::class, fn() => qta_results_save($pdo, $rsGroup, [rs_cell($rsB, $rsExcel, null, '1'), rs_cell($rsB, $rsExcel, null, '2')]), 'e njëjta qelizë dy herë refuzohet');
  t_throws(QtaUserError::class, fn() => qta_results_save($pdo, $rsGroup, 'jo listë'), 'kërkesa e pavlefshme refuzohet');
  t_throws(QtaUserError::class, fn() => qta_results_save($pdo, $rsGroup, []), 'pa asnjë qelizë', 'asnjë pikë');
});

t_case('Pikët — grupi i mbyllur kërkon konfirmim', function () use ($pdo, $rsGroup, $rsB, $rsExcel) {
  $pdo->prepare('UPDATE course_groups SET is_completed = 1 WHERE id = ?')->execute([$rsGroup]);
  t_throws(QtaConfirmNeeded::class, fn() => qta_results_save($pdo, $rsGroup, [rs_cell($rsB, $rsExcel, null, '70')]), 'pa konfirmim: pyet', 'mbyllur');
  t_eq(1, count(rs_scores($pdo, $rsGroup, $rsB)), 'pa konfirmim asgjë nuk ruhet');
  $r = qta_results_save($pdo, $rsGroup, [rs_cell($rsB, $rsExcel, null, '70')], ['force' => true]);
  t_eq(1, $r['changed'], 'me konfirmim ruhet');
  $pdo->prepare('UPDATE course_groups SET is_completed = 0 WHERE id = ?')->execute([$rsGroup]);
});

t_case('Pikët — pikët e vjetra: ruhen, zëvendësohen vetëm me konfirmim, kthehen kur hiqen modulet', function () use ($pdo, $rsGroup, $rsC, $rsWord, $rsExcel, $rsPpt) {
  $pdo->prepare("UPDATE course_group_students SET exam_date = '2026-01-12' WHERE group_id = ? AND student_id = ?")->execute([$rsGroup, $rsC]);
  rs_legacy_final($pdo, $rsGroup, $rsC, '80.00');
  $c = rs_member(qta_results_sheet($pdo, $rsGroup), $rsC);
  t_eq(['legacy', 8000, 8000], [$c['result']['mode'], $c['result']['final'], $c['legacy']], 'pa module: rezultati i vjetër (80) mbetet');

  t_throws(QtaConfirmNeeded::class, fn() => qta_results_save($pdo, $rsGroup, [rs_cell($rsC, $rsWord, null, '90')]), 'zëvendësimi i pikëve të vjetra pyet', 'pikë të vjetra');
  t_eq(['80.00', null], rs_stored($pdo, $rsGroup, $rsC), 'pa konfirmim: asgjë nuk ndryshoi');

  qta_results_save($pdo, $rsGroup, [rs_cell($rsC, $rsWord, null, '90')], ['force' => true]);
  t_eq([null, '80.00'], rs_stored($pdo, $rsGroup, $rsC), 'me module: i paplotë; 80 ruhet si pikë të vjetra');
  $c = rs_member(qta_results_sheet($pdo, $rsGroup), $rsC);
  t_eq(['modules', 8000], [$c['result']['mode'], $c['legacy']], 'fleta: nga modulet, me pikët e vjetra të ditura');

  qta_results_save($pdo, $rsGroup, [rs_cell($rsC, $rsExcel, null, '70'), rs_cell($rsC, $rsPpt, null, '80')]);
  t_eq(['80.00', '80.00'], rs_stored($pdo, $rsGroup, $rsC), 'i plotë: (90 + 70 + 80) / 3 = 80 nga modulet; pikët e vjetra mbeten');

  qta_results_save($pdo, $rsGroup, [rs_cell($rsC, $rsWord, '90', null), rs_cell($rsC, $rsExcel, '70', null), rs_cell($rsC, $rsPpt, '80', null)]);
  t_eq(['80.00', '80.00'], rs_stored($pdo, $rsGroup, $rsC), 'pa asnjë modul: rezultati kthehet te pikët e vjetra');
  t_eq('legacy', rs_member(qta_results_sheet($pdo, $rsGroup), $rsC)['result']['mode'], 'fleta: sërish pikë të vjetra');

  rs_db_refuses($pdo, 'UPDATE course_group_students SET legacy_final_score = 99 WHERE group_id = ? AND student_id = ?', [$rsGroup, $rsC],
    'baza: pikët e vjetra nuk ndryshojnë', 'nuk ndryshojnë');
});

t_case('Pikët — rregullat e bazës vlejnë edhe jashtë aplikacionit', function () use ($pdo, $rsGroup, $rsA, $rsC, $rsWord, $rsExcel) {
  rs_db_refuses($pdo, 'UPDATE course_group_students SET final_score = 91 WHERE group_id = ? AND student_id = ?', [$rsGroup, $rsA],
    'rezultati nuk shkruhet me dorë', 'nuk shkruhet me dorë');
  t_eq('84.17', rs_stored($pdo, $rsGroup, $rsA)[0], 'rezultati mbeti ai i llogaritur');
  rs_db_refuses($pdo, 'UPDATE course_group_students SET exam_date = NULL WHERE group_id = ? AND student_id = ?', [$rsGroup, $rsA],
    'data e provimit nuk hiqet kur ka pikë moduli', 'data e provimit nuk hiqet');
  $foreign = (int)rs_one($pdo, "SELECT id FROM course_modules WHERE course_id <> ? ORDER BY id LIMIT 1", [(int)rs_one($pdo, 'SELECT course_id FROM course_groups WHERE id = ?', [$rsGroup])]);
  rs_db_refuses($pdo, 'INSERT INTO enrollment_module_scores (group_id, student_id, module_id, score) VALUES (?, ?, ?, 50)', [$rsGroup, $rsC, $foreign],
    'moduli jashtë kopjes së grupit', 'nuk është te modulet e grupit');
  rs_db_refuses($pdo, 'INSERT INTO enrollment_module_scores (group_id, student_id, module_id, score) VALUES (?, ?, ?, 100.01)', [$rsGroup, $rsC, $rsExcel],
    'pikët mbi 100 (CHECK)');
  rs_db_refuses($pdo, 'UPDATE enrollment_module_scores SET module_id = ? WHERE group_id = ? AND student_id = ? AND module_id = ?', [$rsExcel, $rsGroup, $rsA, $rsWord],
    'pikët nuk kalojnë te një modul tjetër', 'ndryshohen vetëm pikët');
  $noExam = qta_amze_ensure_student($pdo, 999999998);
  $pdo->prepare('INSERT INTO course_group_students (group_id, student_id) VALUES (?, ?)')->execute([$rsGroup, $noExam]);
  rs_db_refuses($pdo, 'INSERT INTO enrollment_module_scores (group_id, student_id, module_id, score) VALUES (?, ?, ?, 50)', [$rsGroup, $noExam, $rsWord],
    'pa datë provimi', 'datën e provimit');
  $pdo->prepare('DELETE FROM course_group_students WHERE group_id = ? AND student_id = ?')->execute([$rsGroup, $noExam]);
});

t_case('Pikët — heqja e kursantit fshin pikët e tij, secila në historik', function () use ($pdo, $rsGroup, $rsA, $rsAmze) {
  t_eq(3, count(rs_scores($pdo, $rsGroup, $rsA)), 'A ka 3 pikë moduli');
  t_throws(QtaConfirmNeeded::class, fn() => qta_lg_set_members($pdo, $rsGroup, ($rsAmze + 1) . '-' . ($rsAmze + 2)), 'heqja e një kursanti me pikë pyet', 'pikë');
  $before = rs_count($pdo, "SELECT COUNT(*) FROM audit_events WHERE table_name = 'enrollment_module_scores' AND action = 'DELETE'");
  qta_lg_set_members($pdo, $rsGroup, ($rsAmze + 1) . '-' . ($rsAmze + 2), ['force' => true]);
  t_eq(0, rs_count($pdo, 'SELECT COUNT(*) FROM enrollment_module_scores WHERE group_id = ? AND student_id = ?', [$rsGroup, $rsA]), 'asnjë pikë e mbetur pa kursant');
  t_eq(3, rs_count($pdo, "SELECT COUNT(*) FROM audit_events WHERE table_name = 'enrollment_module_scores' AND action = 'DELETE'") - $before, 'historiku: 3 fshirje');
});

t_case('Pikët — moduli i hequr nga katalogu mbetet te grupi me orar', function () use ($pdo, $rsGroup, $rsB, $rsWord, $rsCourse) {
  qta_curriculum_delete_module($pdo, $rsWord);
  t_eq(0, rs_count($pdo, 'SELECT COUNT(*) FROM course_modules WHERE id = ?', [$rsWord]), 'moduli u fshi nga kursi');
  $sheet = qta_results_sheet($pdo, $rsGroup);
  t_eq(['Microsoft Word', 'Microsoft Excel', 'PowerPoint'], array_column($sheet['modules'], 'title'), 'grupi ruan modulet e kopjes së tij');
  qta_results_save($pdo, $rsGroup, [rs_cell($rsB, $rsWord, '0', '10')]);
  t_eq('10.00', rs_scores($pdo, $rsGroup, $rsB)[$rsWord] ?? null, 'pikët e modulit të kopjes ruhen ende');
  t_ok($rsCourse > 0, 'kursi ekziston');
});

t_case('Pikët — regjistri i vjetër ndjek modulet e kursit tani', function () use ($pdo, $rsAmze) {
  $cid = rs_course($pdo, 'Kurs i vjetër', [['Teoria', [10]], ['Praktika', [10]]]);
  [$teo, $pra] = array_map('intval', $pdo->query('SELECT id FROM course_modules WHERE course_id = ' . $cid . ' ORDER BY position')->fetchAll(PDO::FETCH_COLUMN));
  $pdo->prepare("INSERT INTO course_groups (course_id, start_date, end_date) VALUES (?, '2025-03-03', '2025-03-14')")->execute([$cid]);
  $g1 = (int)$pdo->lastInsertId();
  $x = qta_amze_ensure_student($pdo, $rsAmze + 5);
  $y = qta_amze_ensure_student($pdo, $rsAmze + 6);
  foreach ([$x, $y] as $sid) {
    $pdo->prepare("INSERT INTO course_group_students (group_id, student_id, exam_date) VALUES (?, ?, '2025-03-20')")->execute([$g1, $sid]);
  }
  rs_legacy_final($pdo, $g1, $y, '66.50');

  $sheet = qta_results_sheet($pdo, $g1);
  t_eq(['course', ['Teoria', 'Praktika']], [$sheet['module_source'], array_column($sheet['modules'], 'title')], 'modulet e kursit, në radhë');
  qta_results_save($pdo, $g1, [rs_cell($x, $teo, null, '60'), rs_cell($x, $pra, null, '80')]);
  t_eq(['70.00', null], rs_stored($pdo, $g1, $x), '(60 + 80) / 2 = 70');
  t_eq(['66.50', null], rs_stored($pdo, $g1, $y), 'pikët e vjetra të Y mbeten të paprekura');

  rs_db_refuses($pdo, 'UPDATE course_group_students SET final_score = 90 WHERE group_id = ? AND student_id = ?', [$g1, $y],
    'as në regjistrin e vjetër rezultati nuk shkruhet më me dorë', 'nuk shkruhet me dorë');
  $other = rs_course($pdo, 'Kurs tjetër', [['A', [5]]]);
  rs_db_refuses($pdo, 'UPDATE course_groups SET course_id = ? WHERE id = ?', [$other, $g1], 'kursi i grupit me pikë nuk ndryshon', 'kursi i grupit nuk ndryshon');
  t_throws(QtaUserError::class, fn() => qta_curriculum_delete_module($pdo, $teo), 'moduli me pikë nuk fshihet nga katalogu', 'nuk fshihet');
  rs_db_refuses($pdo, 'DELETE FROM course_modules WHERE id = ?', [$teo], 'as jashtë aplikacionit', 'nuk fshihet');

  qta_curriculum_set_course_hours($pdo, $cid, 25);
  $new = qta_curriculum_add_module($pdo, $cid, 'Siguria', 5);
  t_eq([null, null], rs_stored($pdo, $g1, $x), 'moduli i ri e bën rezultatin të paplotë');
  t_eq(3, count(qta_results_sheet($pdo, $g1)['modules']), 'fleta ka 3 module');
  qta_curriculum_delete_module($pdo, $new);
  t_eq(['70.00', null], rs_stored($pdo, $g1, $x), 'pa modulin e ri: 70 sërish');

  /* Ndarja e një grupi të vjetër zhvendos kursantin bashkë me pikët e tij. */
  $pdo->prepare("INSERT INTO course_groups (course_id, start_date, end_date) VALUES (?, '2025-03-03', '2025-03-14')")->execute([$cid]);
  $g2 = (int)$pdo->lastInsertId();
  $pdo->prepare('UPDATE course_group_students SET group_id = ? WHERE group_id = ? AND student_id = ?')->execute([$g2, $g1, $x]);
  t_eq([2, 0], [rs_count($pdo, 'SELECT COUNT(*) FROM enrollment_module_scores WHERE group_id = ? AND student_id = ?', [$g2, $x]),
                rs_count($pdo, 'SELECT COUNT(*) FROM enrollment_module_scores WHERE group_id = ?', [$g1])], 'pikët kaluan bashkë me kursantin');
  t_eq(['70.00', null], rs_stored($pdo, $g2, $x), 'rezultati mbeti i njëjtë');
});

t_case('Pikët — të dhënat për certifikatën', function () use ($pdo, $rsGroup, $rsB, $rsC) {
  $e = qta_results_enrollment($pdo, $rsGroup, $rsB);
  t_ok(is_array($e), 'rezultati i kursantit');
  t_eq(['Microsoft Word', 'Microsoft Excel', 'PowerPoint'], array_column($e['modules'] ?? [], 'title'), 'modulet në radhë');
  t_eq(['10.00', '70.00', null], array_column($e['modules'] ?? [], 'score'), 'pikët e secilit modul (bosh = null)');
  t_eq(['modules', false, null], [$e['result']['source'] ?? null, $e['result']['complete'] ?? null, $e['result']['final'] ?? null], 'i paplotë: pa rezultat');
  $c = qta_results_enrollment($pdo, $rsGroup, $rsC);
  t_eq(['legacy', '80.00'], [$c['result']['source'] ?? null, $c['result']['final'] ?? null], 'pikë të vjetra: rezultati 80');
  t_eq(null, qta_results_enrollment($pdo, $rsGroup, 999999999), 'kursant jashtë grupit: null');
});

/* ------------------------------------------------------------ Përmes HTTP */
if (function_exists('cvh_server_start') && function_exists('cvh_http') && function_exists('cvh_session')) {
  $rshDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qta_http_rs_' . bin2hex(random_bytes(4));
  mkdir($rshDir);
  $rshServer = cvh_server_start(realpath(__DIR__ . '/../../app'), $rshDir, $rsDb);
  $rshStudent = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'student' ORDER BY u.id LIMIT 1")->fetchColumn();
  $rshCsrf = bin2hex(random_bytes(16));
  $rshSid = [
    'student' => cvh_session($rshDir, ['user_id' => $rshStudent, 'csrf_token' => $rshCsrf]),
    'locked'  => cvh_session($rshDir, ['user_id' => $rsAdmin, 'csrf_token' => $rshCsrf, 'edit_mode' => false]),
    'open'    => cvh_session($rshDir, ['user_id' => $rsAdmin, 'csrf_token' => $rshCsrf, 'edit_mode' => true]),
  ];
  $call = static function (?string $who, array $body, bool $csrf = true) use ($rshServer, $rshSid, $rshCsrf): array {
    return cvh_http($rshServer['base'] . '/actions/group_results.php', 'POST', $who === null ? null : $rshSid[$who], $body + ($csrf ? ['csrf' => $rshCsrf] : []));
  };

  t_case('Pikët përmes HTTP — hyrja, leximi, ruajtja dhe konfirmimi', function () use ($pdo, $rshServer, $rshSid, $call, $rsGroup, $rsB, $rsWord, $rsPpt, $rsExcel) {
    t_eq(405, cvh_http($rshServer['base'] . '/actions/group_results.php', 'GET', $rshSid['open'])['status'], 'GET refuzohet (405)');
    t_eq(401, $call(null, ['action' => 'sheet', 'group_id' => $rsGroup])['status'], 'pa seancë: 401');
    t_eq(403, $call('student', ['action' => 'sheet', 'group_id' => $rsGroup])['status'], 'kursanti: 403');
    t_eq(400, $call('open', ['action' => 'sheet', 'group_id' => $rsGroup], false)['status'], 'pa tokenin e faqes: 400');

    $r = $call('locked', ['action' => 'sheet', 'group_id' => $rsGroup]);
    t_eq(200, $r['status'], 'leximi lejohet me ndryshimet të mbyllura');
    t_eq(3, count($r['json']['sheet']['modules'] ?? []), 'fleta ka 3 module');
    $b = null;
    foreach ($r['json']['sheet']['members'] ?? [] as $m) if ($m['id'] === $rsB) $b = $m;
    t_eq(['10.00', '70.00'], [$b['scores'][(string)$rsWord] ?? null, $b['scores'][(string)$rsExcel] ?? null], 'pikët në formatin e bazës');

    $cells = [['student_id' => $rsB, 'module_id' => $rsPpt, 'from' => null, 'to' => '85,5']];
    t_eq(403, $call('locked', ['action' => 'save', 'group_id' => $rsGroup, 'cells' => $cells])['status'], 'ruajtja me ndryshimet të mbyllura: 403');
    t_eq(null, rs_scores($pdo, $rsGroup, $rsB)[$rsPpt] ?? null, 'asgjë nuk u ruajt');

    $pdo->prepare('UPDATE course_groups SET is_completed = 1 WHERE id = ?')->execute([$rsGroup]);
    $r = $call('open', ['action' => 'save', 'group_id' => $rsGroup, 'cells' => $cells]);
    t_eq([409, true], [$r['status'], isset($r['json']['confirm']['message'])], 'grupi i mbyllur: 409 me pyetjen');
    $r = $call('open', ['action' => 'save', 'group_id' => $rsGroup, 'cells' => $cells, 'force' => 1]);
    t_eq([200, 1], [$r['status'], $r['json']['changed'] ?? null], 'me konfirmim: ruhet');
    t_eq('85.50', rs_scores($pdo, $rsGroup, $rsB)[$rsPpt] ?? null, '85,5 u ruajt si 85.50');
    $pdo->prepare('UPDATE course_groups SET is_completed = 0 WHERE id = ?')->execute([$rsGroup]);

    $r = $call('open', ['action' => 'save', 'group_id' => $rsGroup, 'cells' => [['student_id' => $rsB, 'module_id' => $rsExcel, 'from' => '70', 'to' => '120']]]);
    t_eq([400, 'invalid'], [$r['status'], $r['json']['code'] ?? null], 'pikë të pavlefshme: 400');
    t_eq(1, count($r['json']['details']['cells'] ?? []), 'kthehet qeliza me problem');
    t_ok(str_contains((string)($r['json']['details']['cells'][0]['message'] ?? ''), '100'), 'mesazhi thotë kufirin');
    t_eq('70.00', rs_scores($pdo, $rsGroup, $rsB)[$rsExcel] ?? null, 'të dhënat mbetën të paprekura');

    $r = $call('open', ['action' => 'save', 'group_id' => $rsGroup, 'cells' => [['student_id' => $rsB, 'module_id' => $rsExcel, 'from' => '1', 'to' => '75']]]);
    t_eq([400, 'stale'], [$r['status'], $r['json']['code'] ?? null], 'vlerë e vjetër: 400 stale');
    t_eq('70.00', $r['json']['details']['conflicts'][0]['value'] ?? null, 'me vlerën e re');
  });

  proc_terminate($rshServer['proc']);
}
