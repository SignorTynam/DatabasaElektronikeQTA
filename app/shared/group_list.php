<?php
declare(strict_types=1);

/**
 * group_list.php — Listat e grupeve: "Regjistri i kurseve profesionale" (me orar)
 * dhe "Regjistri i vjetër i kurseve profesionale". Kërkimi dhe gjendjet si kushte SQL.
 *
 * Gjendjet ndjekin të njëjtën radhë si etiketa në faqe (qta_group_state_sql):
 *   closed          i mbyllur
 *   upcoming        nis më vonë
 *   active          në mësim sot
 *   awaiting_close  mësimi ka mbaruar, grupi s'është mbyllur
 * Kërkimi gjen kursin (emër, kod), numrin e grupit, datat dhe çdo kursant të grupit
 * (nr. i amzës, numri personal, emri i plotë).
 */

require_once __DIR__ . '/list_filter.php';

const QTA_GROUP_STATES = ['active', 'upcoming', 'awaiting_close', 'closed'];

if (!function_exists('qta_group_filters')) {
  function qta_group_filters(array $in, array $states = QTA_GROUP_STATES): array
  {
    $course = trim((string)($in['course_id'] ?? ''));
    return [
      'q'         => qta_search_q($in['q'] ?? ''),
      'status'    => qta_list_choice($in['status'] ?? '', $states),
      'course_id' => ctype_digit($course) ? $course : '',
    ];
  }
}

if (!function_exists('qta_group_where')) {
  /**
   * Kushtet për cg (course_groups) dhe c (courses). $withState = false jep bazën
   * e numrave mbi çipat. Kthen listë kushtesh (thirrësi shton modelin).
   */
  function qta_group_where(array $f, array &$params, bool $withState = true): array
  {
    $w = [];
    $tokens = qta_search_tokens($f['q'] ?? '');
    if ($tokens) {
      $w[] = qta_search_sql($tokens, [
        'c.name', 'c.code',
        "DATE_FORMAT(cg.start_date, '%d.%m.%Y')",
        "DATE_FORMAT(cg.end_date, '%d.%m.%Y')",
      ], $params, 'gq', [
        'ids'    => ['cg.id'],
        'exists' => [[
          "EXISTS (SELECT 1 FROM course_group_students ms
                   JOIN students ss ON ss.id = ms.student_id
                   LEFT JOIN persons ps ON ps.id = ss.person_id
                   WHERE ms.group_id = cg.id AND ({cond}))",
          ['ss.nr_amze', 'ps.personal_number', 'ps.first_name', 'ps.father_name', 'ps.last_name',
           "CONCAT_WS(' ', ps.first_name, ps.father_name, ps.last_name)"],
        ]],
      ]);
    }
    if (($f['course_id'] ?? '') !== '') {
      $w[] = 'cg.course_id = :g_course';
      $params[':g_course'] = (int)$f['course_id'];
    }
    if ($withState && ($f['status'] ?? '') !== '') {
      $w[] = qta_group_state_sql((string)$f['status']);
    }
    return $w;
  }
}

if (!function_exists('qta_group_counts')) {
  /** Sa grupe ka çdo çip, për kërkimin dhe kursin e zgjedhur. */
  function qta_group_counts(PDO $pdo, string $model, array $f, array $states = QTA_GROUP_STATES): array
  {
    $params = [':g_model' => $model];
    $w = array_merge(['cg.model = :g_model'], qta_group_where($f, $params, false));
    $sql = 'SELECT COUNT(*) AS all_n';
    foreach ($states as $s) {
      $sql .= ', COALESCE(SUM(' . qta_group_state_sql($s) . '), 0) AS ' . $s;
    }
    $st = $pdo->prepare($sql . ' FROM course_groups cg JOIN courses c ON c.id = cg.course_id WHERE ' . implode(' AND ', $w));
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $out = ['' => (int)($row['all_n'] ?? 0)];
    foreach ($states as $s) {
      $out[$s] = (int)($row[$s] ?? 0);
    }
    return $out;
  }
}

if (!function_exists('qta_course_options')) {
  /** Kurset për filtrin "Kursi": id → emër. */
  function qta_course_options(PDO $pdo): array
  {
    $out = [];
    foreach ($pdo->query('SELECT id, name FROM courses ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $c) {
      $out[(string)$c['id']] = (string)$c['name'];
    }
    return $out;
  }
}
