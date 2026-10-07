<?php
declare(strict_types=1);

/**
 * Migrimi 2026-09-28 (konvertimi i grupeve + 8 orë në ditë) mbi databaza të
 * përkohshme, secila e ndërtuar nga e para si një instalim i vërtetë:
 *   db/tables.sql → db/create_audit.sql → migrimet sipas datës.
 *
 * Rastet: databazë e pastër; vetëm grupe të mëparshme; me grupe me orar;
 * migrim i ekzekutuar dy herë; rreshta me mbi 8 orë (ndalet pa ndryshuar asgjë);
 * pa migrimin 2026-09-26 (ndalet pa ndryshuar asgjë).
 *
 * Krijon dhe fshin vetë databazat qta_migtest_*; nuk prek databazën e testeve
 * as atë të punës. Kërkon QTA_TEST_DB=1 dhe të drejtën CREATE/DROP DATABASE.
 */

const MIG_ROOT = __DIR__ . '/../../db/';
const MIG_0926 = MIG_ROOT . 'migrations/2026-09-26-kurset-modulet-temat-orari.sql';
const MIG_0928 = MIG_ROOT . 'migrations/2026-09-28-konvertimi-i-grupeve.sql';
const MIG_1007 = MIG_ROOT . 'migrations/2026-10-07-orari-me-periudhe-te-percaktuar.sql';
const MIG_DATA_TABLES = ['courses', 'course_groups', 'course_group_students', 'students', 'group_schedule_topics',
  'group_schedule_days', 'group_schedule_slots', 'group_day_rules', 'audit_events', 'audit_event_fields'];

/* ------------------------------------------------------------ Ndihmës */

function mig_pdo(?string $db = null): PDO
{
  $pass = getenv('QTA_DB_PASSWORD');
  return new PDO(
    'mysql:host=' . (getenv('QTA_DB_HOST') ?: '127.0.0.1') . ';' . ($db === null ? '' : 'dbname=' . $db . ';') . 'charset=utf8mb4',
    getenv('QTA_DB_USER') ?: 'root',
    $pass === false ? '' : $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => true]
  );
}

/**
 * Ndan një skedar SQL në deklarata si klienti mysql: ndjek DELIMITER, hedh
 * komentet (--, #, blloqet) dhe nuk ndan brenda thonjëzave.
 */
function mig_split(string $sql): array
{
  $out = [];
  $delim = ';';
  $buf = '';
  $len = strlen($sql);
  $i = 0;
  while ($i < $len) {
    if (trim($buf) === '' && ($i === 0 || $sql[$i - 1] === "\n")
        && preg_match('/\G[ \t]*DELIMITER[ \t]+(\S+)[^\n]*\n?/Ai', $sql, $m, 0, $i)) {
      $delim = $m[1];
      $buf = '';
      $i += strlen($m[0]);
      continue;
    }
    $c = $sql[$i];
    if ($c === '#' || ($c === '-' && preg_match('/\G--(\s|$)/A', $sql, $m, 0, $i))) {
      $e = strpos($sql, "\n", $i);
      $i = $e === false ? $len : $e;
      continue;
    }
    if ($c === '/' && ($sql[$i + 1] ?? '') === '*' && ($sql[$i + 2] ?? '') !== '!') {
      $e = strpos($sql, '*/', $i + 2);
      $i = $e === false ? $len : $e + 2;
      $buf .= ' ';
      continue;
    }
    if ($c === "'" || $c === '"' || $c === '`') {
      $j = $i + 1;
      while ($j < $len) {
        if ($sql[$j] === '\\' && $c !== '`') { $j += 2; continue; }
        if ($sql[$j] === $c) {
          if (($sql[$j + 1] ?? '') !== $c) break;
          $j++;
        }
        $j++;
      }
      $buf .= substr($sql, $i, $j - $i + 1);
      $i = $j + 1;
      continue;
    }
    if (substr($sql, $i, strlen($delim)) === $delim) {
      if (trim($buf) !== '') $out[] = trim($buf);
      $buf = '';
      $i += strlen($delim);
      continue;
    }
    $buf .= $c;
    $i++;
  }
  if (trim($buf) !== '') $out[] = trim($buf);
  return $out;
}

/**
 * Ekzekuton një skedar si `mysql db < skedar`: ndalet te gabimi i parë.
 * CREATE DATABASE / USE kapërcehen (gjithçka ndodh në databazën e lidhjes).
 * Kthen tabelat që shfaq skedari (p.sh. lista e kontrollit) dhe gabimin.
 */
function mig_run(PDO $pdo, string $file): array
{
  $sets = [];
  foreach (mig_split((string)file_get_contents($file)) as $stmt) {
    if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $stmt)) continue;
    try {
      $st = $pdo->query($stmt);
      do {
        if ($st->columnCount() > 0) $sets[] = $st->fetchAll();
      } while ($st->nextRowset());
      $st->closeCursor();
    } catch (PDOException $e) {
      return ['sets' => $sets, 'error' => (string)($e->errorInfo[2] ?? $e->getMessage())];
    }
  }
  return ['sets' => $sets, 'error' => null];
}

function mig_name(string $tag, string $case): string
{
  return 'qta_migtest_' . $tag . '_' . $case;
}

/** Databazë e re me skemën bazë (tables.sql + create_audit.sql). */
function mig_create(string $name): PDO
{
  if (!preg_match('/^qta_migtest_[0-9a-f]{8}_[a-z0-9]+$/', $name)) {
    throw new RuntimeException('Emër i palejuar për databazë testimi: ' . $name);
  }
  mig_pdo()->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
  $pdo = mig_pdo($name);
  foreach (['tables.sql', 'create_audit.sql'] as $f) {
    $r = mig_run($pdo, MIG_ROOT . $f);
    if ($r['error'] !== null) throw new RuntimeException($f . ': ' . $r['error']);
  }
  return $pdo;
}

function mig_drop(string $name): void
{
  if (preg_match('/^qta_migtest_[0-9a-f]{8}_[a-z0-9]+$/', $name)) {
    mig_pdo()->exec('DROP DATABASE IF EXISTS `' . $name . '`');
  }
}

/** Skema e plotë: kolonat, kufijtë, çelësat, trigger-at, procedurat. */
function mig_schema(PDO $pdo): array
{
  $q = static fn(string $sql) => $pdo->query($sql)->fetchAll();
  return [
    'columns' => $q('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION'),
    'checks' => $q('SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY CONSTRAINT_NAME, CHECK_CLAUSE'),
    'keys' => $q('SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE'),
    'triggers' => $q('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME'),
    'routines' => $q('SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_NAME'),
  ];
}

/** Të gjithë rreshtat e tabelave të dhëna, në radhë të qëndrueshme. */
function mig_data(PDO $pdo, array $tables): array
{
  $out = [];
  foreach ($tables as $t) {
    $rows = $pdo->query('SELECT * FROM `' . $t . '`')->fetchAll();
    usort($rows, static fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
    $out[$t] = $rows;
  }
  return $out;
}

function mig_one(PDO $pdo, string $sql)
{
  return $pdo->query($sql)->fetchColumn();
}

/** Pas një ndalimi: skema e njëjtë; mbetet vetëm procedura e kontrollit (ekzekutimi i radhës e fshin). */
function mig_assert_untouched(array $before, array $after, string $label): void
{
  $extra = array_values(array_diff(array_column($after['routines'], 'ROUTINE_NAME'), array_column($before['routines'], 'ROUTINE_NAME')));
  t_eq(['qta_migrate_20260928_check'], $extra, $label . ': mbetet vetëm procedura e kontrollit');
  $after['routines'] = $before['routines'];
  t_eq($before, $after, $label . ': skema nuk ndryshoi');
}

/** Kontrollet e përbashkëta pas një migrimi të suksesshëm. */
function mig_assert_migrated(PDO $pdo, string $label): void
{
  $s = mig_schema($pdo);
  $cols = [];
  foreach ($s['columns'] as $c) $cols[$c['TABLE_NAME'] . '.' . $c['COLUMN_NAME']] = $c;
  t_eq(["enum('calculated','fixed_range')", 'NO'], [$cols['group_schedules.schedule_mode']['COLUMN_TYPE'] ?? null, $cols['group_schedules.schedule_mode']['IS_NULLABLE'] ?? null], $label . ': schedule_mode');
  t_eq('YES', $cols['group_schedules.daily_hours']['IS_NULLABLE'] ?? null, $label . ': daily_hours lejon NULL');
  foreach (['group_fixed_days.hours', 'legacy_conversion_drafts.revision', 'group_conversions.status'] as $c) {
    t_ok(isset($cols[$c]), $label . ': ' . $c);
  }
  $checks = array_column($s['checks'], 'CHECK_CLAUSE', 'CONSTRAINT_NAME');
  foreach (['chk_gsd_hours', 'chk_gss_hours', 'chk_gdr_hours', 'chk_gfd_hours'] as $name) {
    t_ok(isset($checks[$name]) && str_contains($checks[$name], '8') && !str_contains($checks[$name], '12'), $label . ': ' . $name . ' me kufirin 8');
  }
  t_ok(isset($checks['chk_gs_daily']) && stripos($checks['chk_gs_daily'], 'fixed_range') !== false && stripos($checks['chk_gs_daily'], 'is not null') !== false, $label . ': chk_gs_daily për të dy llojet, pa NULL te i llogariti');
  $triggers = array_column($s['triggers'], 'TRIGGER_NAME');
  foreach (['trg_cg_model_guard_bu', 'trg_gs_requires_scheduled_bi', 'trg_gfd_requires_fixed_bi', 'trg_lcd_requires_legacy_bi', 'trg_gc_start_bi', 'trg_gc_complete_bu', 'trg_audit_gc_ai', 'trg_audit_gfd_au'] as $t) {
    t_ok(in_array($t, $triggers, true), $label . ': trigger-i ' . $t);
  }
  t_eq([], array_values(array_filter(array_column($s['routines'], 'ROUTINE_NAME'), static fn($r) => str_starts_with($r, 'qta_migrate_'))), $label . ': asnjë procedurë e përkohshme');
}

/** Grupe të mëparshme me kursantë; i pari i mbyllur, me provim dhe pikë. */
function mig_seed_legacy(PDO $pdo): void
{
  $pdo->exec("INSERT INTO courses (code, name, hours) VALUES ('MIG-L', 'Kurs i vjetër', 40)");
  $c = (int)$pdo->lastInsertId();
  $pdo->exec("INSERT INTO course_groups (course_id, start_date, end_date) VALUES ($c, '2025-03-03', '2025-03-14'), ($c, '2025-04-07', '2025-04-11')");
  $g = (int)$pdo->lastInsertId();
  $pdo->exec("UPDATE course_groups SET is_completed = 1 WHERE id = $g");
  $role = (int)mig_one($pdo, "SELECT id FROM roles WHERE name = 'student'");
  $gender = (int)mig_one($pdo, 'SELECT id FROM genders ORDER BY id LIMIT 1');
  foreach ([['MIG-P1', '9101', $g], ['MIG-P2', '9102', $g], ['MIG-P3', '9103', $g + 1]] as [$pn, $amze, $gid]) {
    $pdo->exec("INSERT INTO persons (personal_number, first_name, last_name, gender_id) VALUES ('$pn', 'Test', 'Migrimi', $gender)");
    $person = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO users (role_id, person_id, full_name) VALUES ($role, $person, 'Test Migrimi')");
    $user = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO students (person_id, user_id, nr_amze) VALUES ($person, $user, '$amze')");
    $pdo->exec('INSERT INTO course_group_students (group_id, student_id) VALUES (' . $gid . ', ' . (int)$pdo->lastInsertId() . ')');
  }
  $pdo->exec("UPDATE course_group_students SET exam_date = '2025-03-20', final_score = 86.5 WHERE group_id = $g");
}

/**
 * Grup me orar si ata të krijuar pas migrimit 2026-09-26 (kur kufiri ishte 12):
 * temat $topics me radhë, ditët nga e hëna 02.11.2026 me nga $daily orë, pa të
 * diela; $rule = [data, orë] → një ditë e veçantë.
 */
function mig_seed_scheduled(PDO $pdo, int $daily, array $topics, ?array $rule = null): int
{
  static $n = 0;
  $n++;
  $total = array_sum($topics);
  $pdo->exec("INSERT INTO courses (code, name, hours) VALUES ('MIG-S$n', 'Kurs me orar $n', $total)");
  $c = (int)$pdo->lastInsertId();

  $days = [];
  $date = new DateTimeImmutable('2026-11-02');
  for ($left = $total; $left > 0; $left -= end($days)[1]) {
    if ($date->format('N') === '7') $date = $date->modify('+1 day');
    $days[] = [$date->format('Y-m-d'), min($daily, $left)];
    $date = $date->modify('+1 day');
  }
  $pdo->exec("INSERT INTO course_groups (course_id, start_date, end_date, model) VALUES ($c, '{$days[0][0]}', '" . end($days)[0] . "', 'scheduled')");
  $g = (int)$pdo->lastInsertId();
  $pdo->exec("INSERT INTO group_schedules (group_id, daily_hours, course_hours, curriculum_taken_at, teaching_days, revision, generated_at)
              VALUES ($g, $daily, $total, '2026-09-27 10:00:00', " . count($days) . ", 1, '2026-09-27 10:00:00')");
  foreach ($topics as $i => $h) {
    $pdo->exec("INSERT INTO group_schedule_topics (group_id, seq, module_seq, module_title, module_hours, topic_seq, topic_title, topic_hours)
                VALUES ($g, " . ($i + 1) . ", 1, 'Moduli', $total, " . ($i + 1) . ", 'Tema " . ($i + 1) . "', $h)");
  }
  $t = 0;
  $tLeft = $topics[0];
  foreach ($days as $d => [$lessonDate, $h]) {
    $pdo->exec("INSERT INTO group_schedule_days (group_id, day_seq, lesson_date, hours) VALUES ($g, " . ($d + 1) . ", '$lessonDate', $h)");
    for ($slot = 1; $h > 0; $slot++) {
      if ($tLeft === 0) $tLeft = $topics[++$t];
      $take = min($h, $tLeft);
      $pdo->exec("INSERT INTO group_schedule_slots (group_id, day_seq, slot_seq, topic_seq, hours) VALUES ($g, " . ($d + 1) . ", $slot, " . ($t + 1) . ", $take)");
      $h -= $take;
      $tLeft -= $take;
    }
  }
  if ($rule !== null) {
    $pdo->exec("INSERT INTO group_day_rules (group_id, rule_date, hours, note) VALUES ($g, '{$rule[0]}', {$rule[1]}, 'Provë')");
  }
  return $g;
}

$migTag = bin2hex(random_bytes(4));

/* ------------------------------------------------------------ Rastet */

t_case('Migrimi 2026-09-28 — databazë e pastër', function () use ($migTag) {
  $db = mig_name($migTag, 'clean');
  try {
    $pdo = mig_create($db);
    t_eq(null, mig_run($pdo, MIG_0926)['error'], 'migrimi 2026-09-26');
    t_eq(null, mig_run($pdo, MIG_0928)['error'], 'migrimi 2026-09-28');
    mig_assert_migrated($pdo, 'e pastër');
    t_eq(0, (int)mig_one($pdo, 'SELECT COUNT(*) FROM audit_events'), 'migrimi nuk shkruan në historik');
  } finally {
    mig_drop($db);
  }
});

t_case('Migrimi 2026-09-28 — vetëm grupe të mëparshme', function () use ($migTag) {
  $db = mig_name($migTag, 'legacy');
  try {
    $pdo = mig_create($db);
    mig_seed_legacy($pdo);
    t_eq(null, mig_run($pdo, MIG_0926)['error'], 'migrimi 2026-09-26');
    $before = mig_data($pdo, MIG_DATA_TABLES);
    t_eq(null, mig_run($pdo, MIG_0928)['error'], 'migrimi 2026-09-28');
    mig_assert_migrated($pdo, 'grupe të mëparshme');
    t_eq($before, mig_data($pdo, MIG_DATA_TABLES), 'grupet, kursantët, provimet, pikët dhe historiku mbetën njësoj');
    t_eq([['model' => 'legacy', 'n' => 2]], $pdo->query('SELECT model, COUNT(*) AS n FROM course_groups GROUP BY model')->fetchAll(), 'asnjë grup nuk u konvertua');
    t_eq(0, (int)mig_one($pdo, 'SELECT (SELECT COUNT(*) FROM group_schedules) + (SELECT COUNT(*) FROM group_conversions)'), 'asnjë orar dhe asnjë konvertim');
  } finally {
    mig_drop($db);
  }
});

t_case('Migrimi 2026-09-28 — me grupe me orar, pastaj i ekzekutuar sërish', function () use ($migTag) {
  $db = mig_name($migTag, 'sched');
  try {
    $pdo = mig_create($db);
    mig_seed_legacy($pdo);
    t_eq(null, mig_run($pdo, MIG_0926)['error'], 'migrimi 2026-09-26');
    $g8 = mig_seed_scheduled($pdo, 8, [8, 8], ['2026-11-04', 0]);
    $g6 = mig_seed_scheduled($pdo, 6, [5, 7, 4], ['2026-11-05', 8]);
    $before = mig_data($pdo, MIG_DATA_TABLES);
    $sched = mig_data($pdo, ['group_schedules'])['group_schedules'];
    t_eq(null, mig_run($pdo, MIG_0928)['error'], 'migrimi 2026-09-28');
    mig_assert_migrated($pdo, 'me orar');
    t_eq($before, mig_data($pdo, MIG_DATA_TABLES), 'ditët, pjesët, ditët e veçanta dhe historiku mbetën njësoj');
    $after = mig_data($pdo, ['group_schedules'])['group_schedules'];
    t_eq($sched, array_map(static fn($r) => array_diff_key($r, ['schedule_mode' => 1]), $after), 'oraret ekzistuese: të njëjtat vlera');
    t_eq(['calculated'], array_values(array_unique(array_column($after, 'schedule_mode'))), "oraret ekzistuese janë 'calculated'");

    t_throws(PDOException::class, fn() => $pdo->exec("UPDATE group_schedules SET daily_hours = 9 WHERE group_id = $g6"), 'kufiri i ri: 9 orë në ditë refuzohen');
    t_throws(PDOException::class, fn() => $pdo->exec("UPDATE group_schedules SET daily_hours = NULL WHERE group_id = $g8"), "orari 'calculated' nuk mbetet pa orë në ditë");
    t_throws(PDOException::class, fn() => $pdo->exec("UPDATE group_schedule_days SET hours = 9 WHERE group_id = $g6 AND day_seq = 1"), 'dita e orarit: mbi 8 orë refuzohet');
    t_throws(PDOException::class, fn() => $pdo->exec("UPDATE course_groups SET model = 'scheduled' WHERE model = 'legacy' ORDER BY id LIMIT 1"), 'lloji i grupit nuk ndryshon jashtë konvertimit');
    t_throws(PDOException::class, fn() => $pdo->exec("UPDATE course_groups SET model = 'legacy' WHERE id = $g8"), "'scheduled' → 'legacy' refuzohet");

    /* I ekzekutuar dy herë: e njëjta skemë dhe të njëjtat të dhëna. */
    $schema = mig_schema($pdo);
    $all = array_merge(MIG_DATA_TABLES, ['group_schedules']);
    $data = mig_data($pdo, $all);
    t_eq(null, mig_run($pdo, MIG_0928)['error'], 'ekzekutimi i dytë');
    t_eq($schema, mig_schema($pdo), 'ekzekutimi i dytë: e njëjta skemë');
    t_eq($data, mig_data($pdo, $all), 'ekzekutimi i dytë: të njëjtat të dhëna');

    /* 2026-09-26 i ekzekutuar gabimisht pas tij rikthen trigger-at e vjetër; 2026-09-28 sërish i rregullon. */
    t_eq(null, mig_run($pdo, MIG_0926)['error'], '2026-09-26 i ekzekutuar sërish');
    t_eq(null, mig_run($pdo, MIG_0928)['error'], '2026-09-28 pas tij');
    t_eq($schema, mig_schema($pdo), 'e njëjta skemë si pas migrimit të parë');
    t_eq($data, mig_data($pdo, $all), 'të njëjtat të dhëna si pas migrimit të parë');
  } finally {
    mig_drop($db);
  }
});

t_case('Migrimi 2026-10-07 — fixed_range i ri, datat operative dhe prejardhja', function () use ($migTag) {
  $db = mig_name($migTag, 'fixednew');
  try {
    $pdo = mig_create($db);
    t_eq(null, mig_run($pdo, MIG_0926)['error'], 'migrimi 2026-09-26');
    t_eq(null, mig_run($pdo, MIG_0928)['error'], 'migrimi 2026-09-28');
    t_eq(null, mig_run($pdo, MIG_1007)['error'], 'migrimi 2026-10-07');

    $triggers = array_column(mig_schema($pdo)['triggers'], 'TRIGGER_NAME');
    foreach (['trg_audit_gfd_ai', 'trg_audit_gfd_ad', 'trg_gs_requires_scheduled_bi', 'trg_cg_model_guard_bu'] as $trigger) {
      t_ok(in_array($trigger, $triggers, true), 'trigger-i i ri: ' . $trigger);
    }

    $pdo->exec("INSERT INTO courses (code,name,hours) VALUES ('MIG-FIX','Periudhë e re',16)");
    $course = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO course_groups (course_id,start_date,end_date,model) VALUES ($course,'2026-10-01','2026-10-02','scheduled')");
    $group = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO group_schedules (group_id,schedule_mode,daily_hours,course_hours,curriculum_taken_at,teaching_days,revision,generated_at)
                VALUES ($group,'fixed_range',NULL,16,NOW(),2,1,NOW())");
    $pdo->exec("INSERT INTO group_fixed_days (group_id,lesson_date,hours) VALUES ($group,'2026-10-01',8),($group,'2026-10-02',8)");
    t_eq(0, (int)mig_one($pdo, "SELECT COUNT(*) FROM group_conversions WHERE group_id = $group"), 'fixed_range i ri nuk kërkon konvertim');
    $pdo->exec("UPDATE course_groups SET start_date='2026-09-30', end_date='2026-10-03' WHERE id=$group");
    t_eq('2026-09-30|2026-10-03', (string)mig_one($pdo, "SELECT CONCAT(start_date,'|',end_date) FROM course_groups WHERE id=$group"),
      'datat operative lejohen të ndryshojnë');
    t_throws(PDOException::class, fn() => $pdo->exec("UPDATE group_schedules SET schedule_mode='calculated',daily_hours=8 WHERE group_id=$group"),
      'mënyra e orarit mbetet e pandryshueshme', 'nuk ndryshon');

    /* Një konvertim real mban datat burimore edhe kur datat operative lejohen. */
    $pdo->exec("INSERT INTO course_groups (course_id,start_date,end_date,model) VALUES ($course,'2026-11-01','2026-11-02','legacy')");
    $legacy = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO group_conversions (group_id,source_start_date,source_end_date,course_hours,teaching_days,algorithm_version,approved_plan_hash,source_fingerprint,status,converted_at)
                VALUES ($legacy,'2026-11-01','2026-11-02',16,2,'test',REPEAT('0',64),REPEAT('1',64),'applying',NOW())");
    $pdo->exec("UPDATE course_groups SET model='scheduled' WHERE id=$legacy");
    $pdo->exec("INSERT INTO group_schedules (group_id,schedule_mode,daily_hours,course_hours,curriculum_taken_at,teaching_days,revision,generated_at)
                VALUES ($legacy,'fixed_range',NULL,16,NOW(),2,1,NOW())");
    $pdo->exec("UPDATE group_conversions SET status='completed' WHERE group_id=$legacy");
    t_throws(PDOException::class, fn() => $pdo->exec("UPDATE group_conversions SET source_end_date='2026-11-03' WHERE group_id=$legacy"),
      'datat burimore mbeten të pandryshueshme', 'nuk ndryshon');
    $pdo->exec("UPDATE course_groups SET end_date='2026-11-03' WHERE id=$legacy");
    t_eq('2026-11-02', (string)mig_one($pdo, "SELECT source_end_date FROM group_conversions WHERE group_id=$legacy"),
      'korrigjimi operativ nuk prek burimin');

    $schema = mig_schema($pdo);
    $data = mig_data($pdo, array_merge(MIG_DATA_TABLES, ['group_schedules', 'group_fixed_days', 'group_conversions']));
    t_eq(null, mig_run($pdo, MIG_1007)['error'], 'ekzekutimi i dytë 2026-10-07');
    t_eq($schema, mig_schema($pdo), 'migrimi i ri është idempotent në skemë');
    t_eq($data, mig_data($pdo, array_merge(MIG_DATA_TABLES, ['group_schedules', 'group_fixed_days', 'group_conversions'])),
      'migrimi i ri është idempotent në të dhëna');
  } finally {
    mig_drop($db);
  }
});

t_case('Migrimi 2026-09-28 — rreshta me mbi 8 orë: ndalet pa ndryshuar asgjë', function () use ($migTag) {
  $db = mig_name($migTag, 'over');
  try {
    $pdo = mig_create($db);
    mig_seed_legacy($pdo);
    t_eq(null, mig_run($pdo, MIG_0926)['error'], 'migrimi 2026-09-26');
    $g10 = mig_seed_scheduled($pdo, 10, [10, 6]);
    $gRule = mig_seed_scheduled($pdo, 8, [8, 8], ['2026-11-04', 9]);
    $all = array_merge(MIG_DATA_TABLES, ['group_schedules']);
    $schema = mig_schema($pdo);
    $data = mig_data($pdo, $all);

    $r = mig_run($pdo, MIG_0928);
    t_ok($r['error'] !== null && str_contains($r['error'], 'mbi 8 orë') && str_contains($r['error'], 'Asgjë nuk ndryshoi'), 'mesazhi i qartë | ' . ($r['error'] ?? 'pa gabim'));
    $listed = array_map(static fn($row) => [(int)$row['grupi'], (string)$row['data'], (int)$row['ore'], strtok((string)$row['cfare'], ' ')], $r['sets'][0] ?? []);
    sort($listed);
    $expected = [[$g10, '', 10, 'Orët'], [$g10, '2026-11-02', 10, 'Ditë'], [$g10, '2026-11-02', 10, 'Pjesë'], [$gRule, '2026-11-04', 9, 'Ditë']];
    sort($expected);
    t_eq($expected, $listed, 'lista: çdo rresht që duhet korrigjuar');
    mig_assert_untouched($schema, mig_schema($pdo), 'mbi 8 orë');
    t_eq($data, mig_data($pdo, $all), 'të dhënat nuk ndryshuan');

    /* Pasi korrigjohen orët (si te faqja e grupit), migrimi kalon. */
    $pdo->exec("UPDATE group_schedules SET daily_hours = 8 WHERE group_id = $g10");
    $pdo->exec("DELETE FROM group_schedule_slots WHERE group_id = $g10");
    $pdo->exec("UPDATE group_schedule_days SET hours = 8 WHERE group_id = $g10");
    $pdo->exec("UPDATE group_schedule_topics SET topic_hours = 8 WHERE group_id = $g10");
    $pdo->exec("INSERT INTO group_schedule_slots (group_id, day_seq, slot_seq, topic_seq, hours) VALUES ($g10, 1, 1, 1, 8), ($g10, 2, 1, 2, 8)");
    $pdo->exec("UPDATE group_day_rules SET hours = 8 WHERE group_id = $gRule");
    t_eq(null, mig_run($pdo, MIG_0928)['error'], 'pas korrigjimit migrimi kalon');
    mig_assert_migrated($pdo, 'pas korrigjimit');
  } finally {
    mig_drop($db);
  }
});

t_case('Migrimi 2026-09-28 — pa migrimin 2026-09-26: ndalet pa ndryshuar asgjë', function () use ($migTag) {
  $db = mig_name($migTag, 'early');
  try {
    $pdo = mig_create($db);
    mig_seed_legacy($pdo);
    $schema = mig_schema($pdo);
    $data = mig_data($pdo, ['courses', 'course_groups', 'course_group_students', 'audit_events']);
    $r = mig_run($pdo, MIG_0928);
    t_ok($r['error'] !== null && str_contains($r['error'], '2026-09-26') && str_contains($r['error'], 'Asgjë nuk ndryshoi'), 'mesazhi: ekzekuto së pari 2026-09-26 | ' . ($r['error'] ?? 'pa gabim'));
    mig_assert_untouched($schema, mig_schema($pdo), 'pa 2026-09-26');
    t_eq($data, mig_data($pdo, ['courses', 'course_groups', 'course_group_students', 'audit_events']), 'të dhënat nuk ndryshuan');
  } finally {
    mig_drop($db);
  }
});
