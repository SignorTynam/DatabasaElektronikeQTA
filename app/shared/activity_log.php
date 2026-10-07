<?php
declare(strict_types=1);

/**
 * activity_log.php — Historiku i ndryshimeve (i përbashkët për logs.php dhe logs_editor.php).
 *
 * Faqja prind kontrollon hyrjen dhe përgatit:
 *   $pdo          PDO (me ATTR_EMULATE_PREPARES = true, se :q përdoret disa herë)
 *   $currentUser  llogaria që po shikon
 *   $LOG = [
 *     'page'       => 'logs.php',        faqja (për formularët dhe lidhjet)
 *     'scope_user' => null|int,          null = të gjithë; int = vetëm veprimet e këtij përdoruesi
 *     'nav'        => 'logs',
 *     'navbar'     => 'navbar.php',
 *     'title'      => 'Historiku i ndryshimeve',
 *     'lead'       => '…',
 *     'csv'        => 'historiku',       emri i skedarit të eksportit
 *   ]
 */

require_once __DIR__ . '/themeli.php';

$LOG_MINE = $LOG['scope_user'] !== null;

/* ===================================================== Etiketat njerëzore */
$tableLabels = [
  'users'                 => 'Llogari',
  'roles'                 => 'Rol',
  'persons'               => 'Person',
  'students'              => 'Kursant',
  'agencies'              => 'Agjenci',
  'courses'               => 'Kurs',
  'course_modules'        => 'Modul',
  'course_topics'         => 'Temë',
  'course_groups'         => 'Grup',
  'course_group_students' => 'Kursant në grup',
  'enrollment_module_scores' => 'Pikët e një moduli',
  'group_schedules'       => 'Orari i grupit',
  'group_day_rules'       => 'Ditë e veçantë',
  'group_fixed_days'      => 'Ditë e periudhës së orarit',
  'group_conversions'     => 'Konvertim nga regjistri i vjetër',
  'student_course_plans'  => 'Kurs i zgjedhur',
  'education_levels'      => 'Nivel arsimi',
  'genders'               => 'Gjini',
  'agency_students'       => 'Punonjës agjencie',
];
$columnLabels = [
  'users'    => ['full_name' => 'Emri', 'email' => 'Email-i', 'role_id' => 'Roli', 'person_id' => 'Personi', 'created_at' => 'Krijuar më'],
  'persons'  => ['personal_number' => 'Numri personal', 'first_name' => 'Emri', 'father_name' => 'Atësia', 'last_name' => 'Mbiemri',
                 'birth_date' => 'Datëlindja', 'birth_place' => 'Vendlindja', 'phone' => 'Telefoni', 'gender_id' => 'Gjinia'],
  'students' => ['person_id' => 'Personi', 'user_id' => 'Llogaria', 'nr_amze' => 'Nr. i amzës', 'education_level_id' => 'Arsimi', 'created_at' => 'Krijuar më'],
  'agencies' => ['user_id' => 'Llogaria', 'nip_t' => 'NIPT', 'company_name' => 'Emri i kompanisë', 'address' => 'Adresa', 'phone' => 'Telefoni'],
  'courses'  => ['code' => 'Kodi', 'name' => 'Emri i kursit', 'hours' => 'Orë mësimi', 'created_at' => 'Krijuar më'],
  'course_modules' => ['course_id' => 'Kursi', 'title' => 'Emri i modulit', 'hours' => 'Orë', 'position' => 'Vendi në radhë'],
  'course_topics'  => ['module_id' => 'Moduli', 'title' => 'Tema', 'hours' => 'Orë', 'position' => 'Vendi në modul'],
  'course_groups' => ['course_id' => 'Kursi', 'model' => 'Lloji i grupit', 'start_date' => 'Fillimi', 'end_date' => 'Mbarimi', 'is_completed' => 'Grupi i mbyllur', 'created_at' => 'Krijuar më'],
  'course_group_students' => ['group_id' => 'Grupi', 'student_id' => 'Kursanti', 'final_score' => 'Pikët', 'legacy_final_score' => 'Pikët e vjetra', 'exam_date' => 'Data e provimit'],
  'enrollment_module_scores' => ['group_id' => 'Grupi', 'student_id' => 'Kursanti', 'module_id' => 'Moduli', 'score' => 'Pikët e modulit'],
  'group_schedules' => ['group_id' => 'Grupi', 'schedule_mode' => 'Lloji i orarit', 'daily_hours' => 'Orë në ditë', 'course_hours' => 'Orët e kursit', 'teaching_days' => 'Ditë mësimi', 'curriculum_taken_at' => 'Temat u kopjuan më', 'revision' => 'Versioni i orarit', 'generated_at' => 'Orari u rindërtua më'],
  'group_day_rules' => ['group_id' => 'Grupi', 'rule_date' => 'Data', 'hours' => 'Mësimi atë ditë', 'note' => 'Shënim'],
  'group_fixed_days' => ['group_id' => 'Grupi', 'lesson_date' => 'Data', 'hours' => 'Orë mësimi', 'note' => 'Shënim'],
  'group_conversions' => ['group_id' => 'Grupi', 'source_start_date' => 'Fillimi historik', 'source_end_date' => 'Mbarimi historik',
                          'course_hours' => 'Orët e kursit', 'teaching_days' => 'Ditë mësimi', 'algorithm_version' => 'Propozimi automatik'],
  'student_course_plans'  => ['student_id' => 'Kursanti', 'course_id' => 'Kursi', 'status' => 'Gjendja', 'group_id' => 'Grupi', 'selected_by' => 'Zgjodhi'],
];
$tableIcons = [
  'users' => 'bi-person-badge', 'persons' => 'bi-person', 'students' => 'bi-mortarboard', 'agencies' => 'bi-building',
  'courses' => 'bi-book', 'course_modules' => 'bi-collection', 'course_topics' => 'bi-list-ol', 'course_groups' => 'bi-collection',
  'course_group_students' => 'bi-people', 'group_schedules' => 'bi-calendar-week', 'group_day_rules' => 'bi-calendar-event',
  'group_fixed_days' => 'bi-calendar3', 'group_conversions' => 'bi-arrow-left-right',
  'student_course_plans' => 'bi-journal-check', 'enrollment_module_scores' => 'bi-table',
];

/* ================================================= Kërkime me memorie */
function qta_log_lookup(PDO $pdo, string $what, $id): ?string {
  static $cache = [];
  if ($id === null || $id === '' || (int)$id <= 0) return null;
  $key = $what . ':' . $id;
  if (array_key_exists($key, $cache)) return $cache[$key];
  $sql = [
    'role'    => "SELECT name FROM roles WHERE id=?",
    'gender'  => "SELECT label FROM genders WHERE id=?",
    'edu'     => "SELECT label FROM education_levels WHERE id=?",
    'course'  => "SELECT name FROM courses WHERE id=?",
    'user'    => "SELECT COALESCE(NULLIF(full_name,''), email, CONCAT('Llogaria #',id)) FROM users WHERE id=?",
    'person'  => "SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(father_name,''),' ',COALESCE(last_name,''))) FROM persons WHERE id=?",
    'student' => "SELECT CONCAT(TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))),' (amza ',s.nr_amze,')') FROM students s LEFT JOIN persons p ON p.id=s.person_id WHERE s.id=?",
    'agency'  => "SELECT COALESCE(NULLIF(company_name,''), CONCAT('Agjencia #',id)) FROM agencies WHERE id=?",
    'group'   => "SELECT CONCAT('Grupi #',cg.id,' · ',c.name) FROM course_groups cg JOIN courses c ON c.id=cg.course_id WHERE cg.id=?",
    'module'  => "SELECT CONCAT(m.title,' (',c.name,')') FROM course_modules m JOIN courses c ON c.id=m.course_id WHERE m.id=?",
    /* Moduli i hequr nga katalogu mbetet me emrin e tij te kopja e një grupi me orar. */
    'module_copy' => "SELECT module_title FROM group_schedule_topics WHERE source_module_id=? LIMIT 1",
    'topic'   => "SELECT CONCAT(t.title,' (',m.title,')') FROM course_topics t JOIN course_modules m ON m.id=t.module_id WHERE t.id=?",
    'plan'    => "SELECT CONCAT(TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))),' (amza ',s.nr_amze,') · ',c.name)
                  FROM student_course_plans scp JOIN students s ON s.id=scp.student_id LEFT JOIN persons p ON p.id=s.person_id
                  JOIN courses c ON c.id=scp.course_id WHERE scp.id=?",
  ][$what] ?? null;
  if ($sql === null) return null;
  try {
    $st = $pdo->prepare($sql);
    $st->execute([(int)$id]);
    $val = $st->fetchColumn();
    $val = is_string($val) ? trim($val) : null;
  } catch (Throwable $e) {
    $val = null;
  }
  return $cache[$key] = ($val !== null && $val !== '' && $val !== '(amza )') ? $val : null;
}

/** Vlera e një fushe me fjalë (tekst i thjeshtë, pa HTML). */
function qta_log_value(PDO $pdo, string $col, ?string $val, string $table = ''): string {
  if ($val === null || $val === '') return '—';
  /* Ditët e veçanta: 'default' = orari i zakonshëm (p.sh. një e diel me mësim), 0 = pa mësim. */
  if ($table === 'group_day_rules' && $col === 'hours') {
    if ($val === 'default') return 'Orari i zakonshëm';
    return (int)$val === 0 ? 'Pa mësim' : ((int)$val . ' orë');
  }
  switch ($col) {
    case 'birth_date': case 'start_date': case 'end_date': case 'exam_date': case 'rule_date': case 'lesson_date':
      return qta_date(substr($val, 0, 10), $val);
    case 'created_at': case 'curriculum_taken_at': case 'generated_at':
      return qta_datetime($val, $val);
    case 'model':        return $val === 'scheduled' ? 'Me orar mësimi' : 'Pa orar (i mëparshëm)';
    case 'schedule_mode': return $val === 'fixed_range' ? 'Me periudhë të përcaktuar' : 'Llogaritet nga orët në ditë';
    case 'source_start_date': case 'source_end_date': return qta_date(substr($val, 0, 10), $val);
    case 'algorithm_version': return 'versioni ' . $val;
    case 'daily_hours':  return (int)$val . ' orë në ditë';
    case 'course_hours': return (int)$val . ' orë';
    case 'teaching_days': return (int)$val . ' ditë';
    case 'revision': return 'versioni ' . (int)$val;
    case 'position':     return 'vendi ' . (int)$val;
    case 'module_id':    return qta_log_lookup($pdo, 'module', $val) ?? qta_log_lookup($pdo, 'module_copy', $val) ?? ('Modul #' . (int)$val);
    case 'is_completed':
      return in_array(strtolower(trim($val)), ['1', 'true', 't', 'yes', 'y', 'on'], true) ? 'Po, i mbyllur' : 'Jo, i hapur';
    case 'hours':       return rtrim(rtrim($val, '0'), '.') . ' orë';
    case 'final_score': case 'legacy_final_score': case 'score': return rtrim(rtrim($val, '0'), '.');
    case 'status':      return ['planned' => 'Pret grup', 'assigned' => 'Në grup', 'completed' => 'Përfunduar'][$val] ?? $val;
    case 'role_id':     return qta_log_lookup($pdo, 'role', $val) ?? ('Rol #' . (int)$val);
    case 'gender_id':   return qta_log_lookup($pdo, 'gender', $val) ?? ('Gjini #' . (int)$val);
    case 'education_level_id': return qta_log_lookup($pdo, 'edu', $val) ?? ('Nivel #' . (int)$val);
    case 'course_id':   return qta_log_lookup($pdo, 'course', $val) ?? ('Kurs #' . (int)$val);
    case 'user_id': case 'selected_by': return qta_log_lookup($pdo, 'user', $val) ?? ('Llogaria #' . (int)$val);
    case 'person_id':   return qta_log_lookup($pdo, 'person', $val) ?? ('Person #' . (int)$val);
    case 'student_id':  return qta_log_lookup($pdo, 'student', $val) ?? ('Kursant #' . (int)$val);
    case 'agency_id':   return qta_log_lookup($pdo, 'agency', $val) ?? ('Agjenci #' . (int)$val);
    case 'group_id':    return qta_log_lookup($pdo, 'group', $val) ?? ('Grup #' . (int)$val);
    default:            return $val;
  }
}

/** "Kursant: Blerina Kola (amza 1003)" — çfarë preku veprimi (tekst i thjeshtë).
 *  $values = vlerat e ruajtura te ngjarja (kolona => vlerë); përdoren kur rreshti
 *  nuk ekziston më (p.sh. pas fshirjes). */
function qta_log_subject(PDO $pdo, string $table, array $pk, array $tableLabels, array $values = []): string {
  $label = $tableLabels[$table] ?? ucfirst(str_replace('_', ' ', $table));
  $id = (int)($pk['id'] ?? 0);
  $name = null;
  $fromValues = static function () use ($table, $values, $pdo): ?string {
    $v = static fn(string $k) => trim((string)($values[$k] ?? ''));
    switch ($table) {
      case 'users':    return $v('full_name') ?: ($v('email') ?: null);
      case 'persons':  return trim($v('first_name') . ' ' . $v('father_name') . ' ' . $v('last_name')) ?: null;
      case 'students': return $v('nr_amze') !== '' ? 'amza ' . $v('nr_amze') : null;
      case 'agencies': return $v('company_name') ?: ($v('nip_t') ?: null);
      case 'courses':  return $v('name') ?: ($v('code') ?: null);
      case 'course_modules':
        $c = $v('course_id') !== '' ? qta_log_lookup($pdo, 'course', $v('course_id')) : null;
        return $v('title') !== '' ? $v('title') . ($c ? ' (' . $c . ')' : '') : null;
      case 'course_topics':
        $m = $v('module_id') !== '' ? qta_log_lookup($pdo, 'module', $v('module_id')) : null;
        return $v('title') !== '' ? $v('title') . ($m ? ' — moduli ' . $m : '') : null;
      case 'course_groups':
        $c = $v('course_id') !== '' ? qta_log_lookup($pdo, 'course', $v('course_id')) : null;
        return $c ? ('grupi i kursit ' . $c) : null;
      case 'student_course_plans':
        $s = $v('student_id') !== '' ? qta_log_lookup($pdo, 'student', $v('student_id')) : null;
        $c = $v('course_id') !== '' ? qta_log_lookup($pdo, 'course', $v('course_id')) : null;
        return ($s || $c) ? trim(($s ?? '') . ($c ? ' · ' . $c : ''), ' ·') : null;
    }
    return null;
  };
  switch ($table) {
    case 'users':    $name = qta_log_lookup($pdo, 'user', $id); break;
    case 'persons':  $name = qta_log_lookup($pdo, 'person', $id); break;
    case 'students': $name = qta_log_lookup($pdo, 'student', $id); break;
    case 'agencies': $name = qta_log_lookup($pdo, 'agency', $id); break;
    case 'courses':  $name = qta_log_lookup($pdo, 'course', $id); break;
    case 'course_modules': $name = qta_log_lookup($pdo, 'module', $id); break;
    case 'course_topics':  $name = qta_log_lookup($pdo, 'topic', $id); break;
    case 'student_course_plans': $name = qta_log_lookup($pdo, 'plan', $id); break;
    case 'course_groups': $name = qta_log_lookup($pdo, 'group', $id); break;
    case 'group_schedules':
    case 'group_conversions':
      $gid = (int)($pk['group_id'] ?? 0);
      return $label . ': ' . (qta_log_lookup($pdo, 'group', $gid) ?? ('Grupi #' . $gid . ' (nuk ekziston më)'));
    case 'group_fixed_days':
      $gid = (int)($pk['group_id'] ?? 0);
      $day = isset($pk['lesson_date']) ? qta_date(substr((string)$pk['lesson_date'], 0, 10)) : '';
      return $label . ': ' . $day . ' · ' . (qta_log_lookup($pdo, 'group', $gid) ?? ('Grupi #' . $gid));
    case 'group_day_rules':
      $gid = (int)($pk['group_id'] ?? 0);
      $day = isset($pk['rule_date']) ? qta_date(substr((string)$pk['rule_date'], 0, 10)) : '';
      return $label . ': ' . $day . ' · ' . (qta_log_lookup($pdo, 'group', $gid) ?? ('Grupi #' . $gid));
    case 'course_group_students':
      $gid = (int)($pk['group_id'] ?? 0); $sid = (int)($pk['student_id'] ?? 0);
      $s = qta_log_lookup($pdo, 'student', $sid) ?? ('Kursant #' . $sid);
      $g = qta_log_lookup($pdo, 'group', $gid) ?? ('Grup #' . $gid);
      return $s . ' në ' . $g;
    case 'enrollment_module_scores':
      $gid = (int)($pk['group_id'] ?? 0); $sid = (int)($pk['student_id'] ?? 0); $mid = (int)($pk['module_id'] ?? 0);
      $s = qta_log_lookup($pdo, 'student', $sid) ?? ('Kursant #' . $sid);
      $g = qta_log_lookup($pdo, 'group', $gid) ?? ('Grup #' . $gid);
      $m = qta_log_lookup($pdo, 'module_copy', $mid) ?? qta_log_lookup($pdo, 'module', $mid) ?? ('Modul #' . $mid);
      return $label . ': ' . $m . ' · ' . $s . ' në ' . $g;
  }
  if ($name !== null) return $label . ': ' . $name;
  $old = $fromValues();
  if ($old !== null) return $label . ': ' . $old . ($id > 0 ? ' (nuk ekziston më)' : '');
  if ($id > 0) return $label . ' #' . $id . ' (nuk ekziston më)';
  $pkText = $pk ? implode(', ', array_map(static fn($k) => $k . ' ' . $pk[$k], array_keys($pk))) : '';
  return $label . ($pkText !== '' ? ' (' . $pkText . ')' : '');
}

/* ================================================================ Filtrat */
/* Datat vijnë si dd.mm.vvvv nga fushat (kalendari) ose vvvv-mm-dd nga lidhjet e periudhave. */
$validDate = static function ($v): ?string {
  if (!is_string($v)) return null;
  $v = trim($v);
  if (preg_match('/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})$/', $v, $m)) {
    [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
  } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
    [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
  } else {
    return null;
  }
  return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
};
require_once __DIR__ . '/list_filter.php';
$action  = isset($_GET['action']) && in_array($_GET['action'], ['INSERT', 'UPDATE', 'DELETE'], true) ? $_GET['action'] : null;
$table   = isset($_GET['table']) && is_string($_GET['table']) && preg_match('/^[a-z_]{1,64}$/', $_GET['table']) ? $_GET['table'] : null;
$q       = qta_search_q($_GET['q'] ?? '');
$q       = $q !== '' ? $q : null;
$from    = $validDate($_GET['from'] ?? null);
$to      = $validDate($_GET['to'] ?? null);
$who_id  = (!$LOG_MINE && isset($_GET['who']) && is_string($_GET['who']) && ctype_digit($_GET['who'])) ? (int)$_GET['who'] : null;
$perPage = min(100, max(10, (int)($_GET['per'] ?? 25)));

/* Kushtet pa "Çfarë ndodhi": baza e numrave mbi çipat (U shtuan / U ndryshuan / U fshinë). */
$where = [];
$params = [];
if ($LOG_MINE) { $where[] = "ae.user_id = :me"; $params[':me'] = (int)$LOG['scope_user']; }
if ($table)    { $where[] = "ae.table_name = :table"; $params[':table'] = $table; }
if ($who_id)   { $where[] = "ae.user_id = :who"; $params[':who'] = $who_id; }
if ($from)     { $where[] = "ae.happened_at >= :from"; $params[':from'] = $from . ' 00:00:00'; }
if ($to)       { $where[] = "ae.happened_at <= :to";   $params[':to']   = $to . ' 23:59:59'; }
/* Kërkimi me fjalë shikon edhe brenda vlerave të ndryshuara (p.sh. emri i vjetër i një kursanti). */
if ($q !== null) {
  $where[] = qta_search_sql(qta_search_tokens($q),
    $LOG_MINE ? ['ae.row_pk', 'ae.old_data', 'ae.new_data'] : ['u.full_name', 'u.email', 'ae.row_pk', 'ae.old_data', 'ae.new_data'],
    $params, 'lq');
}
$baseWhereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$baseParams = $params;
if ($action) { $where[] = "ae.action = :action"; $params[':action'] = $action; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$bindAll = static function (PDOStatement $st) use ($params): void { foreach ($params as $k => $v) $st->bindValue($k, $v); };

/* Zonat dhe personat për listat e filtrave */
if ($LOG_MINE) {
  $ts = $pdo->prepare("SELECT DISTINCT table_name FROM audit_events WHERE user_id = :me ORDER BY table_name");
  $ts->execute([':me' => (int)$LOG['scope_user']]);
  $tables = $ts->fetchAll(PDO::FETCH_COLUMN);
  $actors = [];
} else {
  $tables = $pdo->query("SELECT DISTINCT table_name FROM audit_events ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
  $actors = $pdo->query("
    SELECT u.id, COALESCE(NULLIF(u.full_name,''), u.email, CONCAT('Llogaria #', u.id)) AS nm, COUNT(*) AS n
    FROM audit_events ae JOIN users u ON u.id = ae.user_id
    GROUP BY u.id ORDER BY n DESC
  ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$fromSql = "FROM audit_events ae LEFT JOIN users u ON u.id = ae.user_id $whereSql";
$selectSql = "SELECT ae.id, ae.happened_at, ae.action, ae.table_name, ae.row_pk, ae.user_id, ae.ip_address, u.full_name, u.email $fromSql ORDER BY ae.happened_at DESC, ae.id DESC";

/* Fushat e ndryshuara për një listë ngjarjesh */
$loadFields = static function (array $ids) use ($pdo): array {
  $out = [];
  foreach (array_chunk(array_values(array_map('intval', $ids)), 500) as $chunk) {
    if (!$chunk) continue;
    $in = implode(',', array_fill(0, count($chunk), '?'));
    $fs = $pdo->prepare("SELECT event_id, column_name, old_value, new_value FROM audit_event_fields WHERE event_id IN ($in) ORDER BY column_name ASC");
    $fs->execute($chunk);
    while ($row = $fs->fetch(PDO::FETCH_ASSOC)) { $out[(int)$row['event_id']][] = $row; }
  }
  return $out;
};

/* Ndryshimet e një ngjarjeje si çifte fushë → vlerë (tekst i thjeshtë).
   Fushat renditen si në $columnLabels (më të rëndësishmet së pari); "id" fshihet. */
$changesFor = static function (array $ev, array $diffs) use ($pdo, $columnLabels): array {
  $translated = $columnLabels[$ev['table_name']] ?? [];
  $order = array_flip(array_keys($translated));
  usort($diffs, static function ($a, $b) use ($order) {
    return ($order[$a['column_name']] ?? 999) <=> ($order[$b['column_name']] ?? 999) ?: strcmp((string)$a['column_name'], (string)$b['column_name']);
  });
  $out = [];
  foreach ($diffs as $f) {
    $col = (string)$f['column_name'];
    if (in_array($col, ['id', 'updated_at'], true)) continue;
    /* Te shtimet dhe fshirjet, fushat bosh nuk thonë asgjë */
    if ($ev['action'] === 'INSERT' && ($f['new_value'] === null || $f['new_value'] === '')) continue;
    if ($ev['action'] === 'DELETE' && ($f['old_value'] === null || $f['old_value'] === '')) continue;
    $out[] = [
      'label' => $translated[$col] ?? ucfirst(str_replace('_', ' ', $col)),
      'old'   => qta_log_value($pdo, $col, $f['old_value'], (string)$ev['table_name']),
      'new'   => qta_log_value($pdo, $col, $f['new_value'], (string)$ev['table_name']),
    ];
  }
  return $out;
};

/* Vlerat e ruajtura te ngjarja (për emrin e rreshtave që s'ekzistojnë më) */
$valuesFor = static function (array $ev, array $diffs): array {
  $vals = [];
  foreach ($diffs as $f) {
    $vals[(string)$f['column_name']] = $ev['action'] === 'DELETE' ? $f['old_value'] : ($f['new_value'] ?? $f['old_value']);
  }
  return $vals;
};

$actionWord = ['INSERT' => 'U shtua', 'UPDATE' => 'U ndryshua', 'DELETE' => 'U fshi'];

/* ======================================== Eksporti CSV (të gjitha rezultatet) */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  $max = 5000;
  $ex = $pdo->prepare($selectSql . " LIMIT $max");
  $bindAll($ex);
  $ex->execute();
  $rows = $ex->fetchAll(PDO::FETCH_ASSOC);
  $allFields = $loadFields(array_column($rows, 'id'));

  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $LOG['csv'] . '_' . date('Y-m-d_Hi') . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF"); // Excel e lexon saktë ë/ç
  $head = ['Data', 'Ora', 'Çfarë ndodhi', 'Ku', 'Çfarë', 'Ndryshimet'];
  if (!$LOG_MINE) array_splice($head, 2, 0, ['Kush']);
  fputcsv($out, $head, ';');
  foreach ($rows as $ev) {
    $pk = json_decode((string)($ev['row_pk'] ?? 'null'), true) ?: [];
    $parts = [];
    foreach ($changesFor($ev, $allFields[(int)$ev['id']] ?? []) as $c) {
      $parts[] = $ev['action'] === 'INSERT' ? ($c['label'] . ': ' . $c['new'])
        : ($ev['action'] === 'DELETE' ? ($c['label'] . ': ' . $c['old']) : ($c['label'] . ': ' . $c['old'] . ' → ' . $c['new']));
    }
    $line = [
      qta_date(substr((string)$ev['happened_at'], 0, 10)),
      substr((string)$ev['happened_at'], 11, 5),
      $actionWord[$ev['action']] ?? $ev['action'],
      $tableLabels[$ev['table_name']] ?? $ev['table_name'],
      qta_log_subject($pdo, (string)$ev['table_name'], $pk, $tableLabels, $valuesFor($ev, $allFields[(int)$ev['id']] ?? [])),
      $parts ? implode('; ', $parts) : '',
    ];
    if (!$LOG_MINE) array_splice($line, 2, 0, [(string)($ev['full_name'] ?: ($ev['email'] ?? '—'))]);
    fputcsv($out, $line, ';');
  }
  fclose($out);
  exit;
}

/* ======================================================= Numrat dhe lista */
$cntSt = $pdo->prepare("SELECT COUNT(*) $fromSql");
$bindAll($cntSt);
$cntSt->execute();
$total = (int)$cntSt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$offset = ($page - 1) * $perPage;

$st = $pdo->prepare($selectSql . " LIMIT " . (int)$perPage . " OFFSET " . (int)$offset);
$bindAll($st);
$st->execute();
$events = $st->fetchAll(PDO::FETCH_ASSOC);
$fields = $loadFields(array_column($events, 'id'));

/* Numrat mbi çipat: me të gjithë filtrat përveç "Çfarë ndodhi". */
$stats = ['INSERT' => 0, 'UPDATE' => 0, 'DELETE' => 0];
$stc = $pdo->prepare("SELECT ae.action, COUNT(*) c FROM audit_events ae LEFT JOIN users u ON u.id = ae.user_id $baseWhereSql GROUP BY ae.action");
foreach ($baseParams as $k => $v) $stc->bindValue($k, $v);
$stc->execute();
while ($r = $stc->fetch(PDO::FETCH_ASSOC)) { $stats[$r['action']] = (int)$r['c']; }

/* ============================================================== Faqja */
$NAV_ACTIVE = $LOG['nav'];
$HELP_TOPIC = 'logs';
require __DIR__ . '/../pages/inc/' . $LOG['navbar'];
$pageTitle = $LOG['title'];
require __DIR__ . '/app_head.php';

$self = $LOG['page'];
$today = date('Y-m-d');
$listState = array_filter([
  'q' => $q, 'action' => $action, 'table' => $table, 'who' => $who_id ? (string)$who_id : null,
  'from' => $from, 'to' => $to, 'per' => isset($_GET['per']) ? (string)$perPage : null,
]);
$csvUrl = $self . '?' . http_build_query($listState + ['export' => 'csv']);
$verbs = $LOG_MINE
  ? ['INSERT' => 'shtove', 'UPDATE' => 'ndryshove', 'DELETE' => 'fshive']
  : ['INSERT' => 'shtoi', 'UPDATE' => 'ndryshoi', 'DELETE' => 'fshiu'];
$kinds = ['INSERT' => ['is-add', 'bi-plus-lg'], 'UPDATE' => ['is-edit', 'bi-pencil'], 'DELETE' => ['is-del', 'bi-trash']];
$hasFilters = (bool)array_diff_key($listState, ['per' => 1]);

$tableOptions = [];
foreach ($tables as $t) $tableOptions[(string)$t] = $tableLabels[$t] ?? ucfirst(str_replace('_', ' ', (string)$t));
$more = [['name' => 'table', 'label' => 'Ku', 'value' => (string)$table, 'options' => $tableOptions, 'empty' => 'Kudo', 'chip' => 'Ku: %s']];
if (!$LOG_MINE) {
  $actorOptions = [];
  foreach ($actors as $a) $actorOptions[(string)$a['id']] = (string)$a['nm'];
  $more[] = ['name' => 'who', 'label' => 'Kush', 'value' => $who_id ? (string)$who_id : '', 'options' => $actorOptions, 'empty' => 'Kushdo', 'chip' => 'Kush: %s'];
}
$more[] = ['name' => 'from', 'label' => 'Nga data', 'type' => 'date', 'value' => (string)$from, 'chip' => 'Nga %s', 'attrs' => 'data-dmy-max="today"'];
$more[] = ['name' => 'to', 'label' => 'Deri më', 'type' => 'date', 'value' => (string)$to, 'chip' => 'Deri %s', 'attrs' => 'data-dmy-min="#lfMore' . (count($more) - 1) . '" data-dmy-max="today"'];
$dmy = static fn(string $iso): string => qta_date($iso);
$LF = [
  'action'      => $self,
  'label'       => 'Kërko në historik',
  'placeholder' => 'Emër, nr. i amzës, datë ose një vlerë e vjetër',
  'q'           => (string)$q,
  'target'      => 'logResults',
  'status'      => (string)$action,
  'chip_param'  => 'action',
  'chips'       => [
    ['value' => '',       'label' => 'Të gjitha',   'count' => array_sum($stats)],
    ['value' => 'INSERT', 'label' => 'U shtuan',    'count' => $stats['INSERT']],
    ['value' => 'UPDATE', 'label' => 'U ndryshuan', 'count' => $stats['UPDATE']],
    ['value' => 'DELETE', 'label' => 'U fshinë',    'count' => $stats['DELETE']],
  ],
  'chips_label' => 'Çfarë ndodhi',
  'more'        => $more,
  'presets'     => [
    ['label' => 'Sot',               'set' => ['from' => $dmy($today), 'to' => $dmy($today)]],
    ['label' => '7 ditët e fundit',  'set' => ['from' => $dmy(date('Y-m-d', strtotime('-6 days'))), 'to' => $dmy($today)]],
    ['label' => '30 ditët e fundit', 'set' => ['from' => $dmy(date('Y-m-d', strtotime('-29 days'))), 'to' => $dmy($today)]],
  ],
];
?>

<main class="app-main" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title"><?= h($LOG['title']) ?></h1>
      <p class="page-lead"><?= h($LOG['lead']) ?></p>
    </div>
    <div class="page-actions">
      <?= qta_help_button() ?>
    </div>
  </header>

  <section class="section" aria-labelledby="logTitle">
    <div class="list-head" data-live-region="list-head">
      <h2 class="section-title" id="logTitle" tabindex="-1" data-live-focus>
        <?= $hasFilters ? 'Veprimet që përputhen' : 'Të gjitha veprimet' ?>
        <span class="count"><?= number_format($total, 0, ',', '.') ?></span>
      </h2>
      <?php if ($total > 0): ?>
        <div class="list-actions">
          <a class="btn btn-secondary" href="<?= h($csvUrl) ?>"><i class="bi bi-download" aria-hidden="true"></i>Shkarko (Excel)</a>
        </div>
      <?php endif; ?>
    </div>

    <?php require __DIR__ . '/partials/list_toolbar.php'; ?>

    <div id="logResults" data-live-region="results" data-live-announce="<?= h(qta_plural($total, 'veprim', 'veprime')) ?>">
  <?php if (!$events): ?>
    <?= qta_empty(
          $hasFilters ? 'Asnjë veprim nuk përputhet' : 'Ende pa veprime',
          $hasFilters ? 'Hiq një filtër ose zgjero periudhën.' : 'Kur dikush shton, ndryshon ose fshin të dhëna, veprimi shfaqet këtu.',
          'bi-clock-history'
        ) ?>
  <?php else: ?>
    <?php
      $byDay = [];
      foreach ($events as $ev) { $byDay[substr((string)$ev['happened_at'], 0, 10)][] = $ev; }
    ?>
    <?php foreach ($byDay as $day => $rows):
      $dts = strtotime($day); ?>
      <section class="log-day" aria-label="<?= h(qta_date($day)) ?>">
        <h3 class="log-day-title">
          <span><?= $day === $today ? 'Sot' : ($day === date('Y-m-d', strtotime('-1 day')) ? 'Dje' : h(ucfirst(qta_weekday((int)date('N', $dts))))) ?>, <?= h(qta_date($day)) ?></span>
          <span class="log-day-count"><?= h(qta_plural(count($rows), 'veprim', 'veprime')) ?></span>
        </h3>
        <ol class="log-list">
          <?php foreach ($rows as $ev):
            $eid = (int)$ev['id'];
            $act = (string)$ev['action'];
            [$kind, $icon] = $kinds[$act] ?? ['is-edit', 'bi-pencil'];
            $pk = json_decode((string)($ev['row_pk'] ?? 'null'), true) ?: [];
            $subject = qta_log_subject($pdo, (string)$ev['table_name'], $pk, $tableLabels, $valuesFor($ev, $fields[$eid] ?? []));
            $who = $LOG_MINE ? 'Ti' : (string)($ev['full_name'] ?: ($ev['email'] ?: 'Sistemi'));
            $changes = $changesFor($ev, $fields[$eid] ?? []);
            $shown = array_slice($changes, 0, 4);
            $more = array_slice($changes, 4);
          ?>
            <li class="log-item <?= h($kind) ?>">
              <span class="log-icon" aria-hidden="true"><i class="bi <?= h($icon) ?>"></i></span>
              <div class="log-body">
                <div class="log-head">
                  <p class="log-line"><b><?= h($who) ?></b> <?= h($verbs[$act] ?? strtolower($act)) ?> <span class="log-subject"><?= h($subject) ?></span></p>
                  <time class="log-when" datetime="<?= h((string)$ev['happened_at']) ?>"><?= h(substr((string)$ev['happened_at'], 11, 5)) ?> · <?= h(qta_ago((string)$ev['happened_at'])) ?></time>
                </div>
                <?php if ($changes): ?>
                  <dl class="log-changes">
                    <?php foreach ($shown as $c): ?>
                      <div class="log-change">
                        <dt><?= h($c['label']) ?></dt>
                        <dd>
                          <?php if ($act === 'INSERT'): ?><span class="val-new"><?= h($c['new']) ?></span>
                          <?php elseif ($act === 'DELETE'): ?><span class="val-old"><?= h($c['old']) ?></span>
                          <?php else: ?><span class="val-old"><?= h($c['old']) ?></span><span class="val-arrow" aria-label="u bë">→</span><span class="val-new"><?= h($c['new']) ?></span><?php endif; ?>
                        </dd>
                      </div>
                    <?php endforeach; ?>
                  </dl>
                  <?php if ($more): ?>
                    <button class="btn btn-link btn-sm p-0 log-more" type="button" data-bs-toggle="collapse" data-bs-target="#logMore<?= $eid ?>" aria-expanded="false" aria-controls="logMore<?= $eid ?>">
                      Shfaq edhe <?= h(qta_plural(count($more), 'fushë', 'fusha')) ?>
                    </button>
                    <div class="collapse" id="logMore<?= $eid ?>">
                      <dl class="log-changes">
                        <?php foreach ($more as $c): ?>
                          <div class="log-change">
                            <dt><?= h($c['label']) ?></dt>
                            <dd>
                              <?php if ($act === 'INSERT'): ?><span class="val-new"><?= h($c['new']) ?></span>
                              <?php elseif ($act === 'DELETE'): ?><span class="val-old"><?= h($c['old']) ?></span>
                              <?php else: ?><span class="val-old"><?= h($c['old']) ?></span><span class="val-arrow" aria-label="u bë">→</span><span class="val-new"><?= h($c['new']) ?></span><?php endif; ?>
                            </dd>
                          </div>
                        <?php endforeach; ?>
                      </dl>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      </section>
    <?php endforeach; ?>

    <?= qta_list_pager($self, $listState, $page, $pages, qta_plural($total, 'veprim', 'veprime')) ?>
  <?php endif; ?>
    </div>
  </section>
</main>

<?php require __DIR__ . '/app_scripts.php'; ?>
</body>
</html>
