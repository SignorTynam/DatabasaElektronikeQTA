<?php
declare(strict_types=1);

/**
 * Regjistri i orëve të mësimit mbi databazën: kursantët në radhën e Listës emërore,
 * datat nga orari i ruajtur, temat nga kopja e grupit (edhe pasi kursi ndryshon),
 * dhe refuzimi i grupeve të mëparshme dhe të munguara.
 *
 * Vetëm në një databazë testimi (QTA_TEST_DB=1). Testi krijon të dhënat e veta.
 */

require_once __DIR__ . '/../../app/shared/database.php';
require_once __DIR__ . '/../../app/shared/lesson_register.php';

$pdo = getPDO();
if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() === 'qta_db' && getenv('QTA_ALLOW_MAIN_DB') !== '1') {
  fwrite(STDERR, "Refuzohet: testet e integrimit nuk punojnë mbi qta_db.\n");
  exit(2);
}
$lrAdmin = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'administrator' ORDER BY u.id LIMIT 1")->fetchColumn();
$pdo->exec('SET @audit_user_id = ' . $lrAdmin . ", @audit_ip = '127.0.0.1', @audit_ua = 'tests'");

$lrTag = substr(md5((string)microtime(true)), 0, 5);
$lrBase = random_int(1, 400);

t_case('Integrimi: regjistri ndjek Listën emërore, orarin e ruajtur dhe kopjen e temave', function () use ($pdo, $lrTag, $lrBase) {
  $pdo->prepare('INSERT INTO courses (code, name, hours) VALUES (?, ?, ?)')->execute(['REG-' . $lrTag, 'Operator kompjuteri ' . $lrTag, 30]);
  $cid = (int)$pdo->lastInsertId();
  $m1 = qta_curriculum_add_module($pdo, $cid, 'Njohuri të përgjithshme', 7);
  qta_curriculum_add_topic($pdo, $m1, 'Hyrje në kompjuter', 4);
  qta_curriculum_add_topic($pdo, $m1, 'Çështje praktike', 3);
  $m2 = qta_curriculum_add_module($pdo, $cid, 'Përpunimi i të dhënave', 23);
  $long = 'Formula dhe funksione bazë, referencat relative dhe absolute, kontrolli i gabimeve dhe përgatitja e tabelave për printim';
  qta_curriculum_add_topic($pdo, $m2, $long, 8);
  qta_curriculum_add_topic($pdo, $m2, 'Grafikët', 15);

  /* AMZË ku radha si numër ndryshon nga radha si tekst ("100…" < "99…"). */
  $amze = [100000 + $lrBase, 99000 + $lrBase, 99500 + $lrBase];
  $created = qta_lg_create($pdo, ['course_id' => $cid, 'start_date' => '29.09.2026', 'daily_hours' => 5, 'exam_date' => '2199-12-31', 'amze_spec' => implode(', ', $amze)]);
  $gid = (int)$created['groups'][0]['group_id'];

  $model = qta_lesson_register_build($pdo, $gid, '2026-09-26');

  /* Kursantët: e njëjta radhë si download_lista_emerore.php. */
  $ls = $pdo->prepare('SELECT s.id FROM course_group_students cgs JOIN students s ON s.id = cgs.student_id LEFT JOIN persons p ON p.id = s.person_id WHERE cgs.group_id = ? ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC');
  $ls->execute([$gid]);
  t_eq(array_map('intval', $ls->fetchAll(PDO::FETCH_COLUMN)), array_column($model['students'], 'student_id'), 'radha e kursantëve = Lista emërore');
  t_eq([(string)(99000 + $lrBase), (string)(99500 + $lrBase), (string)(100000 + $lrBase)], array_column($model['students'], 'nr_amze'), 'radha sipas numrit, jo sipas tekstit');
  t_eq([1, 2, 3], array_column($model['students'], 'sequence'), 'numrat 1…3');

  /* Datat dhe rreshtat: saktësisht orari i ruajtur. */
  $days = $pdo->prepare('SELECT lesson_date FROM group_schedule_days WHERE group_id = ? ORDER BY day_seq');
  $days->execute([$gid]);
  $stored = $days->fetchAll(PDO::FETCH_COLUMN);
  $seen = [];
  $rows = 0;
  $cols = 0;
  foreach ($model['pages'] as $p) {
    if ($p['kind'] === 'attendance') {
      foreach ($p['dates'] as $d) $seen[$d] = true;
      $cols += count(array_filter($p['columns']));
    } else {
      $rows += count($p['rows']);
    }
  }
  $storedHours = (int)$pdo->query('SELECT SUM(hours) FROM group_schedule_slots WHERE group_id = ' . $gid)->fetchColumn();
  t_eq($stored, array_keys($seen), 'datat e prezencës = ditët e ruajta të mësimit');
  t_eq($storedHours, $rows, 'një rresht teme për çdo orë të orarit të ruajtur');
  t_eq($storedHours, $cols, 'një kolonë prezence për çdo orë të orarit të ruajtur');
  t_eq(30, $rows, '30 orë kursi → 30 rreshta dhe 30 kolona');
  t_eq(['Moduli 1 — Njohuri të përgjithshme', 'Moduli 2 — Përpunimi i të dhënave'], array_values(array_unique(array_filter(array_column($model['pages'], 'heading')))), 'titujt e moduleve nga kopja');
  $first = $model['pages'][0];
  t_eq(['29', '29', '29', '29', '29', '30', '30'], array_map(static fn($c) => $c['day'], array_values(array_filter($first['columns']))), 'moduli 1: 5 orë më 29 dhe 2 orë më 30 shtator');
  $m2att = $model['pages'][2];
  t_eq('2026-09-30', $m2att['dates'][0], 'moduli 2 nis më 30.09, ditën kur mbaron moduli 1');
  t_eq([9, 10], array_column($m2att['months'], 'month'), 'moduli 2 kalon nga muaji 9 te muaji 10');

  /* Kursi ndryshon pas krijimit të grupit: regjistri mbetet i njëjtë. */
  $before = json_encode($model);
  $topic = qta_course_modules($pdo, $cid)[0]['topics'][0];
  qta_curriculum_update_topic($pdo, (int)$topic['id'], ['title' => 'Titull i ri pas krijimit']);
  qta_curriculum_update_module($pdo, $m2, ['title' => 'Emër i ri i modulit']);
  t_eq($before, json_encode(qta_lesson_register_build($pdo, $gid, '2026-09-26')), 'ndryshimet e kursit nuk e prekin regjistrin e grupit');

  /* Orari i ruajtur ndryshon (e diela me mësim): regjistri e ndjek. */
  qta_lg_change($pdo, $gid, ['type' => 'rule', 'date' => '04.10.2026', 'mode' => 'hours', 'hours' => 4, 'note' => ''], ['force' => true, 'today' => '2026-09-26']);
  $after = qta_lesson_register_build($pdo, $gid, '2026-09-26');
  $withSunday = [];
  foreach ($after['pages'] as $p) if ($p['kind'] === 'attendance') foreach ($p['dates'] as $d) $withSunday[$d] = true;
  t_ok(isset($withSunday['2026-10-04']), 'e diela 04.10 me mësim del si kolonë pasi ruhet në orar');
});

t_case('Integrimi: regjistri refuzon grupet e mëparshme dhe grupet që mungojnë', function () use ($pdo) {
  $legacy = (int)$pdo->query("SELECT id FROM course_groups WHERE model = 'legacy' ORDER BY id LIMIT 1")->fetchColumn();
  t_ok($legacy > 0, 'ka një grup të mëparshëm');
  $e = t_throws(QtaUserError::class, fn() => qta_lesson_register_build($pdo, $legacy), 'grupi i mëparshëm refuzohet', 'grup i mëparshëm');
  t_eq('legacy_group', $e instanceof QtaUserError ? ($e->data['code'] ?? '') : '', 'me kodin legacy_group');
  $missing = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM course_groups')->fetchColumn();
  t_throws(QtaUserError::class, fn() => qta_lesson_register_build($pdo, $missing), 'grupi që mungon refuzohet', 'nuk u gjet');
});
