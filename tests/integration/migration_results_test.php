<?php
declare(strict_types=1);

/**
 * Migrimi 2026-09-28 (2) — pikët sipas moduleve — mbi databaza të përkohshme, secila e
 * ndërtuar nga e para si një instalim i vërtetë (db/tables.sql → db/create_audit.sql →
 * migrimet sipas datës).
 *
 * Rastet: databazë e pastër; me grupe dhe pikë të mëparshme (asnjë rresht dhe asnjë ngjarje
 * historiku nuk ndryshon); migrim i ekzekutuar dy herë; pa migrimin 2026-09-28 të konvertimit
 * (ndalet pa ndryshuar asgjë); rregullat e bazës pas migrimit.
 *
 * Përdor ndihmësit e migration_conversion_test.php (mig_*), që ekzekutohet para tij.
 * Krijon dhe fshin vetë databazat qta_migtest_*.
 */

const MIG_PIKET = __DIR__ . '/../../db/migrations/2026-09-28-piket-sipas-moduleve.sql';

if (!function_exists('mig_create') || !defined('MIG_0926') || !defined('MIG_0928')) {
  t_case('Migrimi i pikëve sipas moduleve', function () {
    t_ok(false, 'ndihmësit e migration_conversion_test.php mungojnë (ekzekuto gjithë testet me tests/run.php --integration)');
  });
  return;
}

/** Skema e tabelave që prek migrimi, pa kolonën e re (për krahasimin "para / pas"). */
function migp_data(PDO $pdo): array
{
  $data = mig_data($pdo, ['courses', 'course_groups', 'course_group_students', 'students', 'audit_events', 'audit_event_fields']);
  $data['course_group_students'] = array_map(static function ($r) {
    unset($r['legacy_final_score']);
    return $r;
  }, $data['course_group_students']);
  return $data;
}

function migp_assert_migrated(PDO $pdo, string $label): void
{
  $s = mig_schema($pdo);
  $cols = [];
  foreach ($s['columns'] as $c) $cols[$c['TABLE_NAME'] . '.' . $c['COLUMN_NAME']] = $c;
  t_eq(['decimal(5,2)', 'YES'], [$cols['course_group_students.legacy_final_score']['COLUMN_TYPE'] ?? null, $cols['course_group_students.legacy_final_score']['IS_NULLABLE'] ?? null], $label . ': legacy_final_score');
  t_eq(['decimal(5,2)', 'NO'], [$cols['enrollment_module_scores.score']['COLUMN_TYPE'] ?? null, $cols['enrollment_module_scores.score']['IS_NULLABLE'] ?? null], $label . ': enrollment_module_scores.score');
  $checks = array_column($s['checks'], 'CHECK_CLAUSE', 'CONSTRAINT_NAME');
  t_ok(isset($checks['chk_ems_score']) && str_contains($checks['chk_ems_score'], '100'), $label . ': chk_ems_score 0–100');
  $keys = array_map(static fn($k) => $k['TABLE_NAME'] . '.' . $k['CONSTRAINT_NAME'] . '.' . $k['CONSTRAINT_TYPE'], $s['keys']);
  t_ok(in_array('enrollment_module_scores.fk_ems_enrollment.FOREIGN KEY', $keys, true), $label . ': FK te kursanti në grup');
  t_ok(in_array('enrollment_module_scores.PRIMARY.PRIMARY KEY', $keys, true), $label . ': një rresht për (grup, kursant, modul)');
  $rule = $pdo->query("SELECT DELETE_RULE, UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
                       WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_ems_enrollment'")->fetch(PDO::FETCH_ASSOC);
  t_eq(['CASCADE', 'CASCADE'], [$rule['DELETE_RULE'] ?? null, $rule['UPDATE_RULE'] ?? null], $label . ': pikët ndjekin kursantin (fshirje, ndarje grupi)');
  $triggers = array_column($s['triggers'], 'TRIGGER_NAME');
  foreach (['trg_ems_bi', 'trg_ems_bu', 'trg_cgs_results_bu', 'trg_cgs_results_bd', 'trg_cg_results_course_bu',
            'trg_cm_results_bd', 'trg_cm_results_bu', 'trg_audit_ems_ai', 'trg_audit_ems_au', 'trg_audit_ems_ad'] as $t) {
    t_ok(in_array($t, $triggers, true), $label . ': trigger-i ' . $t);
  }
  t_eq([], array_values(array_filter(array_column($s['routines'], 'ROUTINE_NAME'), static fn($r) => str_starts_with($r, 'qta_migrate_'))), $label . ': asnjë procedurë e përkohshme');
}

$migpTag = bin2hex(random_bytes(4));

t_case('Migrimi i pikëve — databazë e pastër', function () use ($migpTag) {
  $db = mig_name($migpTag, 'pclean');
  try {
    $pdo = mig_create($db);
    t_eq(null, mig_run($pdo, MIG_0926)['error'], 'migrimi 2026-09-26');
    t_eq(null, mig_run($pdo, MIG_0928)['error'], 'migrimi 2026-09-28 (konvertimi)');
    t_eq(null, mig_run($pdo, MIG_PIKET)['error'], 'migrimi i pikëve');
    migp_assert_migrated($pdo, 'e pastër');
    t_eq(0, (int)mig_one($pdo, 'SELECT COUNT(*) FROM audit_events'), 'migrimi nuk shkruan në historik');
  } finally {
    mig_drop($db);
  }
});

t_case('Migrimi i pikëve — me pikë të mëparshme: asgjë nuk ndryshon; ekzekutimi i dytë identik', function () use ($migpTag) {
  $db = mig_name($migpTag, 'plegacy');
  try {
    $pdo = mig_create($db);
    mig_seed_legacy($pdo);
    t_eq(null, mig_run($pdo, MIG_0926)['error'], 'migrimi 2026-09-26');
    t_eq(null, mig_run($pdo, MIG_0928)['error'], 'migrimi 2026-09-28 (konvertimi)');
    $before = migp_data($pdo);
    t_eq(null, mig_run($pdo, MIG_PIKET)['error'], 'migrimi i pikëve');
    migp_assert_migrated($pdo, 'me pikë');
    t_eq($before, migp_data($pdo), 'grupet, kursantët, provimet, pikët dhe historiku mbetën njësoj');
    t_eq(0, (int)mig_one($pdo, 'SELECT COUNT(*) FROM course_group_students WHERE legacy_final_score IS NOT NULL'), 'asnjë kopje paraprake e pikëve');
    t_eq(2, (int)mig_one($pdo, "SELECT COUNT(*) FROM course_group_students WHERE final_score = 86.5"), 'pikët e vjetra (86,5) mbeten ashtu siç janë');

    $schema = mig_schema($pdo);
    $data = mig_data($pdo, ['course_group_students', 'audit_events']);
    t_eq(null, mig_run($pdo, MIG_PIKET)['error'], 'ekzekutimi i dytë');
    t_eq($schema, mig_schema($pdo), 'ekzekutimi i dytë: e njëjta skemë');
    t_eq($data, mig_data($pdo, ['course_group_students', 'audit_events']), 'ekzekutimi i dytë: të njëjtat të dhëna');

    /* Pas migrimit: rezultati nuk shkruhet më me dorë, as te grupet e mëparshme. */
    t_throws(PDOException::class, fn() => $pdo->exec('UPDATE course_group_students SET final_score = 90 WHERE final_score IS NOT NULL'), 'rezultati me dorë refuzohet', 'nuk shkruhet me dorë');
    t_eq(2, (int)mig_one($pdo, "SELECT COUNT(*) FROM course_group_students WHERE final_score = 86.5"), 'pikët e vjetra të paprekura');
    $pdo->exec('UPDATE course_group_students SET exam_date = exam_date WHERE final_score IS NOT NULL');
    t_ok(true, 'një UPDATE pa ndryshim të rezultatit lejohet');
  } finally {
    mig_drop($db);
  }
});

t_case('Migrimi i pikëve — pa migrimin e konvertimit: ndalet pa ndryshuar asgjë', function () use ($migpTag) {
  $db = mig_name($migpTag, 'pearly');
  try {
    $pdo = mig_create($db);
    mig_seed_legacy($pdo);
    t_eq(null, mig_run($pdo, MIG_0926)['error'], 'vetëm 2026-09-26');
    $schema = mig_schema($pdo);
    $data = mig_data($pdo, ['courses', 'course_groups', 'course_group_students', 'audit_events']);
    $r = mig_run($pdo, MIG_PIKET);
    t_ok($r['error'] !== null && str_contains($r['error'], '2026-09-28-konvertimi-i-grupeve.sql') && str_contains($r['error'], 'Asgjë nuk ndryshoi'),
      'mesazhi: ekzekuto së pari migrimet e mëparshme | ' . ($r['error'] ?? 'pa gabim'));
    $after = mig_schema($pdo);
    t_eq(['qta_migrate_piket_check'], array_values(array_diff(array_column($after['routines'], 'ROUTINE_NAME'), array_column($schema['routines'], 'ROUTINE_NAME'))),
      'mbetet vetëm procedura e kontrollit (ekzekutimi i radhës e fshin)');
    $after['routines'] = $schema['routines'];
    t_eq($schema, $after, 'skema nuk ndryshoi');
    t_eq($data, mig_data($pdo, ['courses', 'course_groups', 'course_group_students', 'audit_events']), 'të dhënat nuk ndryshuan');

    t_eq(null, mig_run($pdo, MIG_0928)['error'], 'pastaj 2026-09-28 (konvertimi)');
    t_eq(null, mig_run($pdo, MIG_PIKET)['error'], 'pastaj migrimi i pikëve kalon');
    migp_assert_migrated($pdo, 'pas radhës së duhur');
  } finally {
    mig_drop($db);
  }
});
