<?php
declare(strict_types=1);

/**
 * students_list.php — Lista e kursantëve: nga lexohen, si kërkohen dhe si
 * filtrohen. E njëjta pyetje për students.php dhe students_export.php, që
 * dokumenti i shkarkuar të ketë saktësisht rreshtat e listës në ekran.
 *
 * Gjendjet (çipat) janë kushte të strukturuara, jo tekst i dukshëm:
 *   no_group    pa asnjë grup
 *   ready       pa grup, me kurs të zgjedhur ("Gati për grup")
 *   no_course   pa grup dhe pa kurs të zgjedhur
 *   in_group    në një grup
 *   incomplete  me të paktën një të dhënë që mungon (telefoni nuk llogaritet)
 *   no_exam     grupi ka mbaruar, por pa datë provimi (si te "Çfarë pret për ty")
 */

require_once __DIR__ . '/list_filter.php';

const QTA_STUDENT_STATES = ['no_group', 'ready', 'no_course', 'in_group', 'incomplete', 'no_exam'];

if (!function_exists('qta_students_from_sql')) {
  /**
   * Burimi i listës. lg = grupi i fundit i regjistrimit (sipas datës së fillimit),
   * pp = kursi i zgjedhur që pret grup (status 'planned', më i riu).
   */
  function qta_students_from_sql(): string
  {
    return "
      FROM students s
      JOIN users u   ON u.id = s.user_id
      JOIN persons p ON p.id = s.person_id
      LEFT JOIN education_levels el ON el.id = s.education_level_id
      LEFT JOIN genders g ON g.id = p.gender_id
      LEFT JOIN (
        SELECT y.student_id, y.group_id FROM (
          SELECT cgs.student_id, cgs.group_id,
                 ROW_NUMBER() OVER (PARTITION BY cgs.student_id ORDER BY cg2.start_date DESC, cg2.id DESC) AS rn
          FROM course_group_students cgs
          JOIN course_groups cg2 ON cg2.id = cgs.group_id
        ) y WHERE y.rn = 1
      ) lg ON lg.student_id = s.id
      LEFT JOIN course_groups cg ON cg.id = lg.group_id
      LEFT JOIN courses gc ON gc.id = cg.course_id
      LEFT JOIN course_group_students lgm ON lgm.group_id = lg.group_id AND lgm.student_id = s.id
      LEFT JOIN (
        SELECT x.student_id, x.course_id FROM (
          SELECT scp.student_id, scp.course_id,
                 ROW_NUMBER() OVER (PARTITION BY scp.student_id ORDER BY scp.id DESC) AS rn
          FROM student_course_plans scp
          WHERE scp.status = 'planned'
        ) x WHERE x.rn = 1
      ) pp ON pp.student_id = s.id
      LEFT JOIN courses pc ON pc.id = pp.course_id
    ";
  }
}

if (!function_exists('qta_students_state_sql')) {
  function qta_students_state_sql(string $state): string
  {
    return match ($state) {
      'no_group'   => '(lg.group_id IS NULL)',
      'ready'      => '(lg.group_id IS NULL AND pp.course_id IS NOT NULL)',
      'no_course'  => '(lg.group_id IS NULL AND pp.course_id IS NULL)',
      'in_group'   => '(lg.group_id IS NOT NULL)',
      'incomplete' => "(p.personal_number IS NULL OR p.personal_number = '' OR
                        p.first_name IS NULL OR p.first_name = '' OR
                        p.father_name IS NULL OR p.father_name = '' OR
                        p.last_name IS NULL OR p.last_name = '' OR
                        p.birth_date IS NULL OR p.birth_date = '0000-00-00' OR
                        p.birth_place IS NULL OR p.birth_place = '' OR
                        s.education_level_id IS NULL OR p.gender_id IS NULL)",
      'no_exam'    => "(EXISTS (SELECT 1 FROM course_group_students ne JOIN course_groups neg ON neg.id = ne.group_id
                                WHERE ne.student_id = s.id AND ne.exam_date IS NULL AND neg.end_date < CURDATE()))",
      default      => '1=1',
    };
  }
}

if (!function_exists('qta_students_filters')) {
  /**
   * Filtrat nga kërkesa (GET ose POST i eksportit), të pastruar.
   * Pranon edhe adresat e vjetra: ?incomplete=1 dhe edu me kod (AU/AM/AL).
   */
  function qta_students_filters(array $in): array
  {
    $status = qta_list_choice($in['status'] ?? '', QTA_STUDENT_STATES);
    if ($status === '' && (string)($in['incomplete'] ?? '') === '1') {
      $status = 'incomplete';
    }
    $edu = trim((string)($in['edu'] ?? ''));
    if ($edu !== '' && !preg_match('/^(\d{1,6}|[A-Za-z]{1,10})$/', $edu)) {
      $edu = '';
    }
    $course = trim((string)($in['course_id'] ?? ''));
    return [
      'q'         => qta_search_q($in['q'] ?? ''),
      'status'    => $status,
      'edu'       => $edu,
      'course_id' => ctype_digit($course) ? $course : '',
    ];
  }
}

if (!function_exists('qta_students_where')) {
  /**
   * Kushtet WHERE për filtrat. $withState = false jep bazën e çipave
   * (kërkimi + arsimi + kursi, pa gjendjen), për numrat mbi çdo çip.
   */
  function qta_students_where(array $f, array &$params, bool $withState = true): string
  {
    $w = ["u.role_id = (SELECT r.id FROM roles r WHERE r.name = 'student' LIMIT 1)"];
    /* "pa grup", "gati për grup"… brenda kërkimit janë gjendje, jo fjalë për t'u gjetur. */
    $q = (string)($f['q'] ?? '');
    foreach (qta_search_phrases($q, [
      'gati per grup'   => 'ready',
      'pa date provimi' => 'no_exam',
      'pa grup'         => 'no_group',
      'pa kurs'         => 'no_course',
      'ne grup'         => 'in_group',
    ]) as $state) {
      $w[] = qta_students_state_sql($state);
    }
    $tokens = qta_search_tokens($q);
    if ($tokens) {
      $w[] = qta_search_sql($tokens, [
        's.nr_amze', 'p.first_name', 'p.father_name', 'p.last_name',
        "CONCAT_WS(' ', p.first_name, p.father_name, p.last_name)",
        'p.personal_number', 'p.birth_place', 'p.phone',
        "DATE_FORMAT(p.birth_date, '%d.%m.%Y')",
        'el.label', 'g.label', 'gc.name', 'gc.code', 'pc.name', 'pc.code',
      ], $params, 'stq', ['digits' => [qta_phone_digits_sql('p.phone')]]);
    }
    $edu = (string)($f['edu'] ?? '');
    if ($edu !== '') {
      if (ctype_digit($edu)) {
        $w[] = 's.education_level_id = :f_edu';
        $params[':f_edu'] = (int)$edu;
      } else {
        $w[] = 'el.code = :f_edu';
        $params[':f_edu'] = $edu;
      }
    }
    if (($f['course_id'] ?? '') !== '') {
      $w[] = '(gc.id = :f_course OR (lg.group_id IS NULL AND pp.course_id = :f_course2))';
      $params[':f_course'] = (int)$f['course_id'];
      $params[':f_course2'] = (int)$f['course_id'];
    }
    if ($withState && ($f['status'] ?? '') !== '') {
      $w[] = qta_students_state_sql((string)$f['status']);
    }
    return 'WHERE ' . implode(' AND ', $w);
  }
}

if (!function_exists('qta_students_counts')) {
  /** Sa kursantë ka çdo çip, për kërkimin dhe filtrat e tanishëm (një pyetje). */
  function qta_students_counts(PDO $pdo, array $f): array
  {
    $params = [];
    $where = qta_students_where($f, $params, false);
    $sum = static fn(string $state): string => 'COALESCE(SUM(' . qta_students_state_sql($state) . '), 0)';
    $st = $pdo->prepare('SELECT COUNT(*) AS all_n, '
      . implode(', ', array_map(static fn($s) => $sum($s) . ' AS ' . $s, QTA_STUDENT_STATES))
      . ' ' . qta_students_from_sql() . ' ' . $where);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $out = ['' => (int)($row['all_n'] ?? 0)];
    foreach (QTA_STUDENT_STATES as $s) {
      $out[$s] = (int)($row[$s] ?? 0);
    }
    return $out;
  }
}
