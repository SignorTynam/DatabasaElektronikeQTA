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
  'group_schedules'       => 'Orari i grupit',
  'group_day_rules'       => 'Ditë e veçantë',
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
  'course_group_students' => ['group_id' => 'Grupi', 'student_id' => 'Kursanti', 'final_score' => 'Pikët', 'exam_date' => 'Data e provimit'],
  'group_schedules' => ['group_id' => 'Grupi', 'daily_hours' => 'Orë në ditë', 'course_hours' => 'Orët e kursit', 'teaching_days' => 'Ditë mësimi', 'curriculum_taken_at' => 'Temat u kopjuan më'],
  'group_day_rules' => ['group_id' => 'Grupi', 'rule_date' => 'Data', 'hours' => 'Mësimi atë ditë', 'note' => 'Shënim'],
  'student_course_plans'  => ['student_id' => 'Kursanti', 'course_id' => 'Kursi', 'status' => 'Gjendja', 'group_id' => 'Grupi', 'selected_by' => 'Zgjodhi'],
];
$tableIcons = [
  'users' => 'bi-person-badge', 'persons' => 'bi-person', 'students' => 'bi-mortarboard', 'agencies' => 'bi-building',
  'courses' => 'bi-book', 'course_modules' => 'bi-collection', 'course_topics' => 'bi-list-ol', 'course_groups' => 'bi-collection',
  'course_group_students' => 'bi-people', 'group_schedules' => 'bi-calendar-week', 'group_day_rules' => 'bi-calendar-event',
  'student_course_plans' => 'bi-journal-check',
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
    case 'birth_date': case 'start_date': case 'end_date': case 'exam_date': case 'rule_date':
      return qta_date(substr($val, 0, 10), $val);
    case 'created_at': case 'curriculum_taken_at':
      return qta_datetime($val, $val);
    case 'model':        return $val === 'scheduled' ? 'Me orar mësimi' : 'Pa orar (i mëparshëm)';
    case 'daily_hours':  return (int)$val . ' orë në ditë';
    case 'course_hours': return (int)$val . ' orë';
    case 'teaching_days': return (int)$val . ' ditë';
    case 'position':     return 'vendi ' . (int)$val;
    case 'module_id':    return qta_log_lookup($pdo, 'module', $val) ?? ('Modul #' . (int)$val);
    case 'is_completed':
      return in_array(strtolower(trim($val)), ['1', 'true', 't', 'yes', 'y', 'on'], true) ? 'Po, i mbyllur' : 'Jo, i hapur';
    case 'hours':       return rtrim(rtrim($val, '0'), '.') . ' orë';
    case 'final_score': return rtrim(rtrim($val, '0'), '.');
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
      $gid = (int)($pk['group_id'] ?? 0);
      return $label . ': ' . (qta_log_lookup($pdo, 'group', $gid) ?? ('Grupi #' . $gid . ' (nuk ekziston më)'));
    case 'group_day_rules':
      $gid = (int)($pk['group_id'] ?? 0);
      $day = isset($pk['rule_date']) ? qta_date(substr((string)$pk['rule_date'], 0, 10)) : '';
      return $label . ': ' . $day . ' · ' . (qta_log_lookup($pdo, 'group', $gid) ?? ('Grupi #' . $gid));
    case 'course_group_students':
      $gid = (int)($pk['group_id'] ?? 0); $sid = (int)($pk['student_id'] ?? 0);
      $s = qta_log_lookup($pdo, 'student', $sid) ?? ('Kursant #' . $sid);
      $g = qta_log_lookup($pdo, 'group', $gid) ?? ('Grup #' . $gid);
      return $s . ' në ' . $g;
  }
  if ($name !== null) return $label . ': ' . $name;
  $old = $fromValues();
  if ($old !== null) return $label . ': ' . $old . ($id > 0 ? ' (nuk ekziston më)' : '');
  if ($id > 0) return $label . ' #' . $id . ' (nuk ekziston më)';
  $pkText = $pk ? implode(', ', array_map(static fn($k) => $k . ' ' . $pk[$k], array_keys($pk))) : '';
  return $label . ($pkText !== '' ? ' (' . $pkText . ')' : '');
}

/* ================================================================ Filtrat */
$validDate = static fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
$action  = isset($_GET['action']) && in_array($_GET['action'], ['INSERT', 'UPDATE', 'DELETE'], true) ? $_GET['action'] : null;
$table   = isset($_GET['table']) && is_string($_GET['table']) && $_GET['table'] !== '' ? $_GET['table'] : null;
$q       = isset($_GET['q']) && is_string($_GET['q']) && trim($_GET['q']) !== '' ? trim($_GET['q']) : null;
$from    = $validDate($_GET['from'] ?? null);
$to      = $validDate($_GET['to'] ?? null);
$who_id  = (!$LOG_MINE && isset($_GET['who']) && is_string($_GET['who']) && ctype_digit($_GET['who'])) ? (int)$_GET['who'] : null;
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int)($_GET['per'] ?? 25)));
$offset  = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($LOG_MINE) { $where[] = "ae.user_id = :me"; $params[':me'] = (int)$LOG['scope_user']; }
if ($action)   { $where[] = "ae.action = :action"; $params[':action'] = $action; }
if ($table)    { $where[] = "ae.table_name = :table"; $params[':table'] = $table; }
if ($who_id)   { $where[] = "ae.user_id = :who"; $params[':who'] = $who_id; }
if ($from)     { $where[] = "ae.happened_at >= :from"; $params[':from'] = $from . ' 00:00:00'; }
if ($to)       { $where[] = "ae.happened_at <= :to";   $params[':to']   = $to . ' 23:59:59'; }
/* Kërkimi shikon edhe brenda vlerave të ndryshuara (p.sh. emri i një kursanti). */
if ($q) {
  $where[] = $LOG_MINE
    ? "(ae.row_pk LIKE :q OR ae.old_data LIKE :q OR ae.new_data LIKE :q)"
    : "(u.full_name LIKE :q OR u.email LIKE :q OR ae.row_pk LIKE :q OR ae.old_data LIKE :q OR ae.new_data LIKE :q)";
  $params[':q'] = '%' . $q . '%';
}
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

$st = $pdo->prepare($selectSql . " LIMIT " . (int)$perPage . " OFFSET " . (int)$offset);
$bindAll($st);
$st->execute();
$events = $st->fetchAll(PDO::FETCH_ASSOC);
$fields = $loadFields(array_column($events, 'id'));

$stats = ['INSERT' => 0, 'UPDATE' => 0, 'DELETE' => 0];
$stc = $pdo->prepare("SELECT ae.action, COUNT(*) c $fromSql GROUP BY ae.action");
$bindAll($stc);
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
$presets = [
  'Sot'          => ['from' => $today, 'to' => $today],
  '7 ditët e fundit'  => ['from' => date('Y-m-d', strtotime('-6 days')), 'to' => $today],
  '30 ditët e fundit' => ['from' => date('Y-m-d', strtotime('-29 days')), 'to' => $today],
];
$whoName = null;
if ($who_id) {
  foreach ($actors as $a) { if ((int)$a['id'] === $who_id) { $whoName = (string)$a['nm']; break; } }
  $whoName = $whoName ?? ('Llogaria #' . $who_id);
}
$activeFilters = array_filter([
  'action' => $action ? ($actionWord[$action] ?? $action) : null,
  'table'  => $table ? ($tableLabels[$table] ?? $table) : null,
  'who'    => $whoName,
  'from'   => $from ? 'nga ' . qta_date($from) : null,
  'to'     => $to ? 'deri ' . qta_date($to) : null,
  'q'      => $q ? '"' . $q . '"' : null,
]);
$csvUrl = $self . '?' . http_build_query(array_merge(array_diff_key($_GET, ['page' => 1]), ['export' => 'csv']));
$verbs = $LOG_MINE
  ? ['INSERT' => 'shtove', 'UPDATE' => 'ndryshove', 'DELETE' => 'fshive']
  : ['INSERT' => 'shtoi', 'UPDATE' => 'ndryshoi', 'DELETE' => 'fshiu'];
$kinds = ['INSERT' => ['is-add', 'bi-plus-lg'], 'UPDATE' => ['is-edit', 'bi-pencil'], 'DELETE' => ['is-del', 'bi-trash']];
?>

<main class="app-main" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title"><?= h($LOG['title']) ?></h1>
      <p class="page-lead"><?= h($LOG['lead']) ?></p>
    </div>
    <div class="page-actions">
      <?= qta_help_button() ?>
      <?php if ($total > 0): ?>
        <a class="btn btn-secondary" href="<?= h($csvUrl) ?>"><i class="bi bi-download" aria-hidden="true"></i>Shkarko listën (Excel)</a>
      <?php endif; ?>
    </div>
  </header>

  <div class="stats mb-4" aria-label="Përmbledhje sipas filtrave">
    <div class="stat">
      <span class="stat-label">Veprime gjithsej</span>
      <span class="stat-value"><?= number_format($total, 0, ',', '.') ?></span>
      <span class="stat-note"><?= $activeFilters ? 'sipas filtrave' : 'që nga fillimi' ?></span>
    </div>
    <div class="stat">
      <span class="stat-label"><span class="log-dot is-add" aria-hidden="true"></span>U shtuan</span>
      <span class="stat-value"><?= number_format($stats['INSERT'], 0, ',', '.') ?></span>
    </div>
    <div class="stat">
      <span class="stat-label"><span class="log-dot is-edit" aria-hidden="true"></span>U ndryshuan</span>
      <span class="stat-value"><?= number_format($stats['UPDATE'], 0, ',', '.') ?></span>
    </div>
    <div class="stat">
      <span class="stat-label"><span class="log-dot is-del" aria-hidden="true"></span>U fshinë</span>
      <span class="stat-value"><?= number_format($stats['DELETE'], 0, ',', '.') ?></span>
    </div>
  </div>

  <form class="filters" method="get" action="<?= h($self) ?>" role="search" aria-label="Filtro historikun">
    <div class="filter-field is-grow">
      <label class="form-label" for="lq">Kërko</label>
      <div class="search-field">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="form-control" id="lq" type="search" name="q" value="<?= h((string)$q) ?>" placeholder="Emër kursanti, nr. i amzës, datë…" aria-describedby="lqHelp">
      </div>
      <span class="form-text mt-0" id="lqHelp">Kërkon edhe te vlerat e vjetra, p.sh. një emër që është ndryshuar.</span>
    </div>
    <div class="filter-field">
      <label class="form-label" for="laction">Çfarë ndodhi</label>
      <select class="form-select" id="laction" name="action">
        <option value="">Çdo veprim</option>
        <?php foreach ($actionWord as $code => $label): ?>
          <option value="<?= h($code) ?>"<?= $action === $code ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-field">
      <label class="form-label" for="ltable">Ku</label>
      <select class="form-select" id="ltable" name="table">
        <option value="">Kudo</option>
        <?php foreach ($tables as $t): ?>
          <option value="<?= h((string)$t) ?>"<?= $table === $t ? ' selected' : '' ?>><?= h($tableLabels[$t] ?? ucfirst(str_replace('_', ' ', (string)$t))) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (!$LOG_MINE): ?>
      <div class="filter-field">
        <label class="form-label" for="lwho">Kush</label>
        <select class="form-select" id="lwho" name="who">
          <option value="">Kushdo</option>
          <?php foreach ($actors as $a): ?>
            <option value="<?= (int)$a['id'] ?>"<?= $who_id === (int)$a['id'] ? ' selected' : '' ?>><?= h((string)$a['nm']) ?> (<?= number_format((int)$a['n'], 0, ',', '.') ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="filter-field">
      <label class="form-label" for="lfrom">Nga data</label>
      <input class="form-control" type="date" id="lfrom" name="from" value="<?= h((string)$from) ?>" max="<?= h($today) ?>">
    </div>
    <div class="filter-field">
      <label class="form-label" for="lto">Deri më</label>
      <input class="form-control" type="date" id="lto" name="to" value="<?= h((string)$to) ?>" max="<?= h($today) ?>">
    </div>
    <div class="filter-actions">
      <?php if ($activeFilters): ?><a class="btn btn-ghost" href="<?= h($self) ?>">Pastro filtrat</a><?php endif; ?>
      <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i>Shfaq</button>
    </div>
  </form>

  <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
    <span class="text-muted small">Periudha:</span>
    <?php foreach ($presets as $lbl => $rng):
      $pq = array_merge(array_diff_key($_GET, ['page' => 1, 'export' => 1]), $rng);
      $isOn = ($from === $rng['from'] && $to === $rng['to']); ?>
      <a class="chip<?= $isOn ? ' is-on' : '' ?>" href="<?= h($self . '?' . http_build_query($pq)) ?>" <?= $isOn ? 'aria-current="true"' : '' ?>><?= h($lbl) ?></a>
    <?php endforeach; ?>
    <?php if ($activeFilters): ?>
      <span class="text-muted small ms-2">Po shikon:</span>
      <?php foreach ($activeFilters as $key => $label):
        $drop = array_diff_key($_GET, [$key => 1, 'page' => 1, 'export' => 1]); ?>
        <a class="chip is-on" href="<?= h($self . ($drop ? '?' . http_build_query($drop) : '')) ?>" title="Hiq këtë filtër">
          <?= h((string)$label) ?><i class="bi bi-x" aria-hidden="true"></i><span class="visually-hidden">(hiq)</span>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if (!$events): ?>
    <?= qta_empty(
          $activeFilters ? 'Asnjë veprim nuk përputhet' : 'Ende pa veprime',
          $activeFilters ? 'Hiq një filtër ose zgjero periudhën.' : 'Kur dikush shton, ndryshon ose fshin të dhëna, veprimi shfaqet këtu.',
          'bi-clock-history',
          $activeFilters ? '<a class="btn btn-secondary" href="' . h($self) . '">Pastro filtrat</a>' : ''
        ) ?>
  <?php else: ?>
    <?php
      $byDay = [];
      foreach ($events as $ev) { $byDay[substr((string)$ev['happened_at'], 0, 10)][] = $ev; }
    ?>
    <?php foreach ($byDay as $day => $rows):
      $dts = strtotime($day); ?>
      <section class="log-day" aria-label="<?= h(qta_date($day)) ?>">
        <h2 class="log-day-title">
          <span><?= $day === $today ? 'Sot' : ($day === date('Y-m-d', strtotime('-1 day')) ? 'Dje' : h(ucfirst(qta_weekday((int)date('N', $dts))))) ?>, <?= h(qta_date($day)) ?></span>
          <span class="log-day-count"><?= h(qta_plural(count($rows), 'veprim', 'veprime')) ?></span>
        </h2>
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

    <?php if ($pages > 1):
      $pBase = $self . '?' . http_build_query(array_diff_key($_GET, ['page' => 1, 'export' => 1]));
      $pLink = static fn(int $p) => $pBase . (str_ends_with($pBase, '?') ? '' : '&') . 'page=' . $p; ?>
      <nav class="pager mt-4" aria-label="Faqet e historikut">
        <a class="btn btn-secondary btn-sm<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= h($pLink(max(1, $page - 1))) ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>><i class="bi bi-chevron-left" aria-hidden="true"></i>Më të rejat</a>
        <span class="text-muted small">Faqja <?= $page ?> nga <?= $pages ?></span>
        <a class="btn btn-secondary btn-sm<?= $page >= $pages ? ' disabled' : '' ?>" href="<?= h($pLink(min($pages, $page + 1))) ?>" <?= $page >= $pages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Më të vjetrat<i class="bi bi-chevron-right" aria-hidden="true"></i></a>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</main>

<?php require __DIR__ . '/app_scripts.php'; ?>
</body>
</html>
