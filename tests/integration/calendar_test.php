<?php
declare(strict_types=1);

/**
 * Integrimi: kalendari i grupeve mbi të dhënat e ruajtura — intervali dhe datat
 * përfshirëse, grupet njëditore dhe ato që kalojnë muajin e vitin, orari i llogaritur
 * dhe ai me data historike, gjendjet, numri i kursantëve, grupet e regjistrit të vjetër,
 * kopja e ngrirë e moduleve dhe temave, grupi që mungon dhe numri i query-ve (pa N+1).
 *
 * Vetëm në një databazë testimi (QTA_TEST_DB=1). Testi krijon grupet e veta në vitin 2091,
 * që asnjë grup tjetër të mos bjerë në të njëjtat intervale.
 */

require_once __DIR__ . '/../../app/shared/database.php';
require_once __DIR__ . '/../../app/shared/group_calendar.php';
require_once __DIR__ . '/../../app/shared/legacy_conversion.php';
require_once __DIR__ . '/../../app/shared/group_list.php';

$pdo = getPDO();
if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() === 'qta_db' && getenv('QTA_ALLOW_MAIN_DB') !== '1') {
  fwrite(STDERR, "Refuzohet: testet e integrimit nuk punojnë mbi qta_db.\n");
  exit(2);
}
$calAdmin = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'administrator' ORDER BY u.id LIMIT 1")->fetchColumn();
$pdo->exec('SET @audit_user_id = ' . $calAdmin . ", @audit_ip = '127.0.0.1', @audit_ua = 'tests'");
$calTag = substr(md5((string)microtime(true)), 0, 5);
$calAmze = 810000 + random_int(0, 9000) * 10;

/* ------------------------------------------------------------ Ndihmës */

function cal_course(PDO $pdo, string $code, string $name, array $modules): int
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

/** Data e parë nga $iso që nuk është e diel. */
function cal_weekday_from(string $iso): string
{
  while (qta_sched_is_sunday($iso)) $iso = qta_sched_next_day($iso);
  return $iso;
}

function cal_shift(string $iso, int $days): string
{
  return qta_sched_date($iso)->modify(($days >= 0 ? '+' : '') . $days . ' day')->format('Y-m-d');
}

/** Grupi me orar, i krijuar si nga "Krijo grup". */
function cal_group(PDO $pdo, int $courseId, string $start, int $daily, string $amze = ''): array
{
  $r = qta_lg_create($pdo, ['course_id' => $courseId, 'start_date' => $start, 'daily_hours' => $daily, 'amze_spec' => $amze]);
  return ['id' => (int)$r['groups'][0]['group_id'], 'start' => $r['summary']['start_date'], 'end' => $r['summary']['end_date']];
}

/** Id-të e grupeve të një intervali. */
function cal_ids(array $feed): array
{
  return array_map(static fn($e) => $e['id'], $feed['events']);
}

function cal_event(array $feed, int $id): ?array
{
  foreach ($feed['events'] as $e) if ($e['id'] === $id) return $e;
  return null;
}

function cal_questions(PDO $pdo): int
{
  return (int)$pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'];
}

/* ------------------------------------------------------------ Të dhënat */

/* Kursi 20 orë: 8 + 8 + 4. Me 8 orë në ditë: 3 ditë mësimi. */
$calCourse = cal_course($pdo, 'CAL-' . $calTag, 'Kurs kalendari ' . $calTag, [['Hyrje', [4, 4]], ['Praktika', [4, 4]], ['Provimi', [2, 2]]]);
/* Kursi 6 orë: me 8 orë në ditë mbaron të njëjtën ditë. */
$calShort = cal_course($pdo, 'CAL1-' . $calTag, 'Kurs njëditor ' . $calTag, [['Një ditë', [3, 3]]]);

$G = [];
$G['main'] = cal_group($pdo, $calCourse, cal_weekday_from('2091-03-05'), 8, ($calAmze) . '-' . ($calAmze + 2));
/* 20 orë me 2 në ditë = 10 ditë mësimi: nis në dhjetor 2090 dhe mbaron në janar 2091. */
$G['year'] = cal_group($pdo, $calCourse, cal_weekday_from('2090-12-27'), 2);
$G['one'] = cal_group($pdo, $calShort, cal_weekday_from('2091-03-20'), 8);
$G['closed'] = cal_group($pdo, $calCourse, cal_weekday_from('2091-05-07'), 5);
$pdo->prepare('UPDATE course_groups SET is_completed = 1 WHERE id = ?')->execute([$G['closed']['id']]);

/* Grup i regjistrit të vjetër, pa orar. */
$pdo->prepare('INSERT INTO course_groups (course_id, start_date, end_date, is_completed) VALUES (?, ?, ?, 0)')->execute([$calCourse, '2091-04-10', '2091-04-20']);
$G['legacy'] = ['id' => (int)$pdo->lastInsertId(), 'start' => '2091-04-10', 'end' => '2091-04-20'];
$pdo->prepare('INSERT INTO course_group_students (group_id, student_id) VALUES (?, ?)')->execute([$G['legacy']['id'], qta_amze_ensure_student($pdo, $calAmze + 5)]);

/* Grup i vjetër i konvertuar: orari me data historike (fixed_range), 01.06–05.06.2091. */
$pdo->prepare('INSERT INTO course_groups (course_id, start_date, end_date, is_completed) VALUES (?, ?, ?, 0)')->execute([$calCourse, '2091-06-01', '2091-06-05']);
$fixedId = (int)$pdo->lastInsertId();
foreach ([$calAmze + 7, $calAmze + 8] as $a) {
  $pdo->prepare('INSERT INTO course_group_students (group_id, student_id) VALUES (?, ?)')->execute([$fixedId, qta_amze_ensure_student($pdo, $a)]);
}
$calConv = qta_conv_view($pdo, $fixedId);
qta_conv_save($pdo, $fixedId, qta_conv_plan_for_client($calConv['plan']), 0, $calConv['fingerprint'], $calAdmin);
qta_conv_apply($pdo, $fixedId, 1, $calConv['fingerprint'], $calAdmin);
$G['fixed'] = ['id' => $fixedId, 'start' => '2091-06-01', 'end' => '2091-06-05'];

/* ============================================================== Testet */

t_case('Kalendari: një grup shfaqet në çdo interval që prek një nga ditët e tij (fillimi dhe mbarimi përfshirës)', function () use ($pdo, $G) {
  ['id' => $id, 'start' => $s, 'end' => $e] = $G['main'];
  t_ok($e > $s, 'grupi kryesor zgjat disa ditë (' . $s . ' – ' . $e . ')');
  $in = static fn(string $from, string $to): bool => in_array($id, cal_ids(qta_calendar_events($pdo, $from, $to)), true);
  t_eq(true, $in($s, $s), 'intervali mbaron në ditën e fillimit');
  t_eq(true, $in($e, cal_shift($e, 5)), 'intervali nis në ditën e mbarimit: mbarimi është ditë e grupit');
  t_eq(true, $in(cal_shift($s, -10), cal_shift($e, 10)), 'intervali e mbulon grupin');
  t_eq(true, $in(cal_shift($s, 1), cal_shift($s, 1)), 'një ditë në mes të grupit');
  t_eq(false, $in(cal_shift($e, 1), cal_shift($e, 10)), 'dita pas mbarimit: jashtë');
  t_eq(false, $in(cal_shift($s, -10), cal_shift($s, -1)), 'dita para fillimit: jashtë');

  $ev = cal_event(qta_calendar_events($pdo, $s, $e), $id);
  t_eq([$s, $e, qta_calendar_days($s, $e)], [$ev['start'], $ev['end'], $ev['days']], 'datat e ruajtura, pa zhvendosje, dhe ditët përfshirëse');
  $stored = $pdo->query('SELECT start_date, end_date FROM course_groups WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
  t_eq([$stored['start_date'], $stored['end_date']], [$ev['start'], $ev['end']], 'burimi i së vërtetës: course_groups.start_date / end_date');
});

t_case('Kalendari: grupi njëditor dhe grupi që kalon muajin dhe vitin', function () use ($pdo, $G) {
  ['id' => $one, 'start' => $d] = $G['one'];
  t_eq($d, $G['one']['end'], 'kursi 6 orë me 8 orë në ditë: fillimi = mbarimi');
  $ev = cal_event(qta_calendar_events($pdo, $d, $d), $one);
  t_eq([1, $d, $d], [$ev['days'] ?? null, $ev['start'] ?? null, $ev['end'] ?? null], 'një ditë, e shfaqur në datën e vet');
  t_eq(false, in_array($one, cal_ids(qta_calendar_events($pdo, cal_shift($d, 1), cal_shift($d, 3))), true), 'nuk del në ditët pas saj');

  ['id' => $y, 'start' => $ys, 'end' => $ye] = $G['year'];
  t_eq(['2090-12', '2091-01'], [substr($ys, 0, 7), substr($ye, 0, 7)], 'nis në dhjetor 2090 dhe mbaron në janar 2091');
  foreach (['2090-12', '2091-01'] as $ym) {
    $m = qta_calendar_month($ym, '2091-01-01');
    $e = cal_event(qta_calendar_events($pdo, $m['from'], $m['to']), $y);
    t_eq([$ys, $ye], [$e['start'] ?? null, $e['end'] ?? null], 'del me datat e plota në ' . $m['label']);
  }
  t_eq(false, in_array($y, cal_ids(qta_calendar_events($pdo, '2091-02-01', '2091-02-28')), true), 'nuk del në shkurt');
});

t_case('Kalendari: gjendjet, si te regjistri, për një ditë të caktuar', function () use ($pdo, $G) {
  ['id' => $id, 'start' => $s, 'end' => $e] = $G['main'];
  $state = static fn(string $today): array => cal_event(qta_calendar_events($pdo, $s, $e, false, $today), $id)['status'];
  t_eq(['upcoming', 'Nis pas 3 ditësh'], array_values(array_intersect_key($state(cal_shift($s, -3)), ['key' => 1, 'label' => 1])), 'para fillimit: "Nis pas 3 ditësh"');
  t_eq('active', $state($s)['key'], 'ditën e parë: në mësim');
  t_eq('active', $state($e)['key'], 'ditën e fundit: në mësim');
  t_eq(['awaiting_close', 'Pret mbylljen'], array_values(array_intersect_key($state(cal_shift($e, 1)), ['key' => 1, 'label' => 1])), 'pas mbarimit: pret mbylljen');
  $c = $G['closed'];
  t_eq(['closed', 'I mbyllur'], array_values(array_intersect_key(cal_event(qta_calendar_events($pdo, $c['start'], $c['end'], false, $c['start']), $c['id'])['status'], ['key' => 1, 'label' => 1])),
    'grupi i mbyllur: "I mbyllur", edhe gjatë datave të tij');
});

t_case('Kalendari: gjendja në PHP është e njëjtë me gjendjen në SQL të listave', function () use ($pdo) {
  $rows = $pdo->query('SELECT id, start_date, end_date, is_completed FROM course_groups')->fetchAll(PDO::FETCH_ASSOC);
  $bySql = [];
  foreach (QTA_GROUP_STATES as $s) {
    foreach ($pdo->query('SELECT cg.id FROM course_groups cg WHERE ' . qta_group_state_sql($s))->fetchAll(PDO::FETCH_COLUMN) as $gid) $bySql[(int)$gid] = $s;
  }
  $diff = array_filter($rows, static fn($r) => ($bySql[(int)$r['id']] ?? null) !== qta_group_state($r));
  t_eq([], array_values(array_map(static fn($r) => (int)$r['id'], $diff)), 'çdo grup i databazës ka të njëjtën gjendje (' . count($rows) . ' grupe)');
});

t_case('Kalendari: orari i llogaritur dhe ai me data historike', function () use ($pdo, $G) {
  $calc = qta_calendar_group($pdo, $G['main']['id'], $G['main']['start']);
  $fixed = qta_calendar_group($pdo, $G['fixed']['id'], $G['fixed']['start']);
  t_eq(['calculated', false], [$calc['mode'], $calc['legacy']], 'grupi i krijuar: orar i llogaritur');
  t_eq(['fixed_range', false], [$fixed['mode'], $fixed['legacy']], 'grupi i konvertuar: data historike');
  $facts = static fn(array $g): array => array_column($g['facts'], 'value', 'label');
  t_eq(['3', '20 orë · 8 në ditë', 'Llogaritet nga data e fillimit'], array_values($facts($calc)), 'faktet e orarit të llogaritur');
  t_eq('20 orë · data historike', $facts($fixed)['Orët e kursit'], 'orët e grupit me data historike');
  t_ok(str_starts_with($facts($fixed)['Orari'], 'Konvertuar nga regjistri i vjetër më '), 'kur u konvertua');
  $inRange = cal_event(qta_calendar_events($pdo, '2091-06-01', '2091-06-30'), $G['fixed']['id']);
  t_eq(['2091-06-01', '2091-06-05', 5, false], [$inRange['start'], $inRange['end'], $inRange['days'], $inRange['legacy']], 'grupi i konvertuar del me datat historike, si grup me orar');
});

t_case('Kalendari: numri i kursantëve dhe asnjë e dhënë personale te lista', function () use ($pdo, $G, $calAmze) {
  $feed = qta_calendar_events($pdo, $G['main']['start'], $G['main']['end']);
  t_eq(3, cal_event($feed, $G['main']['id'])['members'], 'tre kursantë, të numëruar në të njëjtën query');
  $json = json_encode($feed, JSON_UNESCAPED_UNICODE);
  t_eq(false, str_contains($json, (string)$calAmze), 'lista nuk mban numrat e amzës');
  foreach (['personal_number', 'birth_date', 'phone', 'first_name', 'last_name', 'members":[', 'email'] as $field) {
    t_eq(false, str_contains($json, $field), 'lista nuk mban ' . $field);
  }
});

t_case('Kalendari: grupet e regjistrit të vjetër — të numëruara, të shfaqura vetëm kur kërkohen', function () use ($pdo, $G) {
  ['id' => $id, 'start' => $s, 'end' => $e] = $G['legacy'];
  $hidden = qta_calendar_events($pdo, '2091-04-01', '2091-04-30');
  t_eq(false, in_array($id, cal_ids($hidden), true), 'pa filtrin: nuk shfaqet');
  t_eq(1, $hidden['legacy_hidden'], 'por numërohet');
  $shown = qta_calendar_events($pdo, '2091-04-01', '2091-04-30', true);
  $ev = cal_event($shown, $id);
  t_eq([true, 'groups.php?group=' . $id, $s, $e, 1], [$ev['legacy'] ?? null, $ev['href'] ?? null, $ev['start'] ?? null, $ev['end'] ?? null, $ev['members'] ?? null], 'me filtrin: i dallueshëm, hapet te regjistri i vjetër');
  t_eq(0, $shown['legacy_hidden'], 'me filtrin asgjë nuk mbetet e fshehur');

  $d = qta_calendar_group($pdo, $id, $s);
  t_eq([true, null, null, [], 'groups.php?group=' . $id, 'group_conversion.php?id=' . $id], [$d['legacy'], $d['mode'], $d['curriculum'], $d['facts'], $d['href'], $d['conversion_href'] ?? null],
    'detajet: pa orar dhe pa kopje temash, me lidhjen e konvertimit');
  t_eq(['bi-archive', 1], [$d['note']['icon'], count($d['members'])], 'shënimi i regjistrit të vjetër dhe kursantët');
});

t_case('Kalendari: përmbajtja e kursit është kopja e ngrirë e grupit, jo kursi siç është sot', function () use ($pdo, $G, $calCourse) {
  $before = qta_calendar_group($pdo, $G['main']['id'])['curriculum'];
  t_eq(['Hyrje', 'Praktika', 'Provimi'], array_column($before['modules'], 'title'), 'modulet në radhën e kopjes');
  t_eq(['8 orë', '8 orë', '4 orë'], array_column($before['modules'], 'hours'), 'orët e moduleve');
  t_eq(['Hyrje — tema 1', 'Hyrje — tema 2'], array_column($before['modules'][0]['topics'], 'title'), 'temat me radhë');
  t_eq(['4 orë', '4 orë'], array_column($before['modules'][0]['topics'], 'hours'), 'orët e temave');
  t_eq([1, 2], array_column($before['modules'][0]['topics'], 'seq'), 'radha e temave');
  t_eq('3 module · 6 tema · 20 orë', $before['summary'], 'përmbledhja');

  /* Kursi ndryshon pas krijimit: grupi mbetet me kopjen e vet. */
  $modules = qta_course_modules($pdo, $calCourse);
  qta_curriculum_update_module($pdo, (int)$modules[0]['id'], ['title' => 'Hyrje e re']);
  qta_curriculum_update_topic($pdo, (int)$modules[1]['topics'][0]['id'], ['title' => 'Temë e riemërtuar']);
  $live = qta_course_modules($pdo, $calCourse);
  t_eq('Hyrje e re', $live[0]['title'], 'kursi sot ka titullin e ri');
  foreach (['main', 'fixed'] as $k) {
    $after = qta_calendar_group($pdo, $G[$k]['id'])['curriculum'];
    t_eq(['Hyrje', 'Praktika', 'Provimi'], array_column($after['modules'], 'title'), $k . ': modulet mbeten ato të kopjes');
    t_eq('Praktika — tema 1', $after['modules'][1]['topics'][0]['title'], $k . ': tema mbetet ajo e kopjes');
  }
  /* Datat e moduleve vijnë nga orari i ruajtur: me 8 orë në ditë çdo modul ka ditën e vet. */
  $main = qta_calendar_group($pdo, $G['main']['id']);
  $days = array_column(qta_lg_days($pdo, $G['main']['id']), 'date');
  t_eq([qta_date($days[0]), qta_date($days[1]), qta_date($days[2])], array_column($main['curriculum']['modules'], 'when'), 'kur zhvillohet çdo modul');
});

t_case('Kalendari: detajet — kursantët, lidhjet dhe shënimi i ditës', function () use ($pdo, $G, $calCourse) {
  ['id' => $id, 'start' => $s, 'end' => $e] = $G['main'];
  $d = qta_calendar_group($pdo, $id, $s);
  t_eq(['lesson_group.php?id=' . $id, 'course.php?id=' . $calCourse], [$d['href'], $d['course']['href']], 'lidhjet e grupit dhe të kursit');
  t_eq(3, count($d['members']), 'tre kursantë');
  $m = $d['members'][0];
  t_eq(['id', 'name', 'amze', 'href'], array_keys($m), 'për çdo kursant vetëm emri, amza dhe lidhja');
  t_eq('student_card.php?sid=' . $m['id'], $m['href'], 'lidhja te kartela e kursantit');
  t_eq([QTA_GROUP_MAX_MEMBERS, 'Grupi #' . $id], [$d['capacity'], 'Grupi #' . $d['id']], 'kapaciteti i grupit');

  t_eq(['bi-geo-alt', 'Sot, dita 1 nga 3'], [$d['note']['icon'], $d['note']['lead']], 'dita e parë: mësimi i sotëm');
  t_ok(str_contains($d['note']['text'], 'Hyrje: 1. Hyrje — tema 1 (4 orë)'), 'me modulin dhe temat e ditës');
  t_eq('Mësimi nis nesër', qta_calendar_group($pdo, $id, cal_shift($s, -1))['note']['lead'], 'para fillimit');
  t_eq('Mësimi mbaroi', qta_calendar_group($pdo, $id, cal_shift($e, 1))['note']['lead'], 'pas mbarimit');
  t_eq(null, qta_calendar_group($pdo, $G['closed']['id'], $G['closed']['start'])['note'], 'grupi i mbyllur: statusi mjafton');
});

t_case('Kalendari: grupi që mungon dhe muaji pa grupe', function () use ($pdo, $G) {
  $e = t_throws(QtaUserError::class, fn() => qta_calendar_group($pdo, 2147480000), 'grupi që mungon refuzohet', 'nuk u gjet');
  t_eq('not_found', $e instanceof QtaUserError ? ($e->data['code'] ?? null) : null, 'me kodin not_found (HTTP 404)');
  $empty = qta_calendar_events($pdo, '2091-08-01', '2091-08-31');
  t_eq([], $empty['events'], 'gusht 2091: asnjë grup');
  t_eq($G['fixed']['end'], $empty['nearest']['prev'], 'grupi i fundit para tij mbaroi më ' . $G['fixed']['end']);
  t_eq(null, qta_calendar_events($pdo, $G['main']['start'], $G['main']['end'])['nearest'], 'kur ka grupe, s\'ka nevojë për më të afërtit');
});

t_case('Kalendari: numri i query-ve nuk rritet me grupet (pa N+1)', function () use ($pdo, $G) {
  $q0 = cal_questions($pdo);
  $wide = qta_calendar_events($pdo, '2090-12-01', '2091-01-31');
  $events = cal_questions($pdo) - $q0 - 1;
  $q0 = cal_questions($pdo);
  qta_calendar_events($pdo, $G['main']['start'], $G['main']['start']);
  t_eq($events, cal_questions($pdo) - $q0 - 1, 'intervali me ' . count($wide['events']) . ' grupe dhe ai me një grup: i njëjti numër query-sh');
  t_eq(2, $events, 'grupet me kursantët + numërimi i regjistrit të vjetër: 2 query');
  foreach (['main', 'fixed', 'legacy'] as $k) {
    $q0 = cal_questions($pdo);
    qta_calendar_group($pdo, $G[$k]['id']);
    t_ok(cal_questions($pdo) - $q0 - 1 <= 5, $k . ': detajet me të shumtën 5 query');
  }
});

/* ------------------------------------------------------------ Pastrimi */

foreach (['main', 'year', 'one', 'closed', 'fixed'] as $k) {
  qta_lg_delete($pdo, $G[$k]['id'], ['force' => true]);
}
$pdo->prepare('DELETE FROM course_group_students WHERE group_id = ?')->execute([$G['legacy']['id']]);
$pdo->prepare('DELETE FROM course_groups WHERE id = ?')->execute([$G['legacy']['id']]);
