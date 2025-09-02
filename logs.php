<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo); // vendos @audit_* për këtë request
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

/* ===== Guard admin ===== */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch();
if (!$currentUser || $currentUser['role_name']!=='administrator') { header('Location: selectProfile.php'); exit; }

/* Helpers */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function nonEmpty(?string $s): bool { return $s !== null && $s !== ''; }
function fmtDate(?string $v): string {
  if ($v === null || $v === '') return '—';
  // Përpiqu të lexosh datetime ose date
  if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $v)) {
    $ts = strtotime($v);
    return $ts ? date('d.m.Y H:i', $ts) : h($v);
  }
  return h($v);
}
function fmtPlain(?string $v): string {
  if ($v === null || $v === '') return '—';
  return h($v);
}
function timeAgo(string $ts): string {
  $t = strtotime($ts) ?: time();
  $diff = time() - $t;
  if ($diff < 60) return 'pak sekonda më parë';
  $mins = intdiv($diff,60);
  if ($mins < 60) return $mins.' min më parë';
  $hrs = intdiv($mins,60);
  if ($hrs < 24) return $hrs.' orë më parë';
  $days = intdiv($hrs,24);
  if ($days < 30) return $days.' ditë më parë';
  $months = intdiv($days,30);
  return $months.' muaj më parë';
}

/**
 * Etiketa njerëzore për tabela/kolona
 */
$tableLabels = [
  'users'                 => 'Përdorues',
  'roles'                 => 'Rol',
  'persons'               => 'Person',
  'students'              => 'Student',
  'agencies'              => 'Agjenci',
  'courses'               => 'Modul',
  'course_groups'         => 'Grup kursi',
  'course_group_students' => 'Anëtar i grupit',
];

$columnLabels = [
  'users' => [
    'full_name'=>'Emri i plotë','email'=>'Email','role_id'=>'Roli','person_id'=>'Personi',
    'created_at'=>'Krijuar më'
  ],
  'persons' => [
    'personal_number'=>'ID personale','first_name'=>'Emri','father_name'=>'Emri i atit',
    'last_name'=>'Mbiemri','birth_date'=>'Datëlindja','birth_place'=>'Vendlindja',
    'phone'=>'Telefoni','gender_id'=>'Gjinia'
  ],
  'students' => [
    'person_id'=>'Personi','user_id'=>'Llogaria','nr_amze'=>'AMZË','education_level_id'=>'Niveli arsimor','created_at'=>'Krijuar më'
  ],
  'agencies' => [
    'user_id'=>'Llogaria','nip_t'=>'NIPT','company_name'=>'Emri i kompanisë','address'=>'Adresa','phone'=>'Telefoni'
  ],
  'courses' => [
    'code'=>'Kodi','name'=>'Emri i modulit','hours'=>'Orë mësimore','created_at'=>'Krijuar më'
  ],
  'course_groups' => [
    'course_id'=>'Moduli','start_date'=>'Fillon','end_date'=>'Mbaron','exam_date'=>'Data e testit','created_at'=>'Krijuar më'
  ],
  'course_group_students' => [
    'group_id'=>'Grupi','student_id'=>'Studenti','final_score'=>'Nota finale','exam_date'=>'Data testit (student)'
  ]
];

/**
 * Lookup i thjeshtë me cache për id → emër (role, gender, edu level, course, user/person/student/agency)
 */
function lookup(PDO $pdo, string $what, $id): ?string {
  static $cache = [];
  if ($id === null || $id === '') return null;
  $key = $what.':'.$id;
  if (isset($cache[$key])) return $cache[$key];

  switch ($what) {
    case 'role':
      $st = $pdo->prepare("SELECT name FROM roles WHERE id=?"); break;
    case 'gender':
      $st = $pdo->prepare("SELECT label FROM genders WHERE id=?"); break;
    case 'edu':
      $st = $pdo->prepare("SELECT label FROM education_levels WHERE id=?"); break;
    case 'course':
      $st = $pdo->prepare("SELECT CONCAT(code,' · ',name) AS label FROM courses WHERE id=?"); break;
    case 'user':
      $st = $pdo->prepare("SELECT COALESCE(NULLIF(full_name,''), email, CONCAT('User #',id)) AS label FROM users WHERE id=?"); break;
    case 'person':
      $st = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(father_name,''),' ',COALESCE(last_name,''))) AS label FROM persons WHERE id=?"); break;
    case 'student':
      $st = $pdo->prepare("SELECT CONCAT('AMZË ',nr_amze) AS label FROM students WHERE id=?"); break;
    case 'agency':
      $st = $pdo->prepare("SELECT COALESCE(NULLIF(company_name,''), CONCAT('Agjenci #',id)) AS label FROM agencies WHERE id=?"); break;
    case 'group':
      $st = $pdo->prepare("
        SELECT CONCAT('Grupi #',cg.id,' (',c.code,' · ',c.name,')') AS label
        FROM course_groups cg JOIN courses c ON c.id=cg.course_id
        WHERE cg.id=?");
      break;
    default: return null;
  }
  $st->execute([$id]);
  $val = $st->fetchColumn();
  $cache[$key] = $val ?: null;
  return $cache[$key];
}

/** Formatim vlerash miqësor sipas kolonës */
function prettyValue(PDO $pdo, string $table, string $col, ?string $val): string {
  if ($val === null || $val === '') return '—';
  switch ($col) {
    case 'birth_date':
    case 'start_date':
    case 'end_date':
    case 'exam_date':
    case 'created_at':
      return fmtDate($val);
    case 'hours':
    case 'final_score':
      // numra: ruaj presje dhjetore nëse ka
      return rtrim(rtrim((string)$val, '0'), '.') . (in_array($col, ['hours']) ? ' orë' : '');
    case 'role_id':
      return h(lookup($pdo,'role',(int)$val) ?? ('Rol #'.(int)$val));
    case 'gender_id':
      return h(lookup($pdo,'gender',(int)$val) ?? ('Gjini #'.(int)$val));
    case 'education_level_id':
      return h(lookup($pdo,'edu',(int)$val) ?? ('Niv. #'.(int)$val));
    case 'course_id':
      return h(lookup($pdo,'course',(int)$val) ?? ('Modul #'.(int)$val));
    case 'user_id':
      return h(lookup($pdo,'user',(int)$val) ?? ('User #'.(int)$val));
    case 'person_id':
      return h(lookup($pdo,'person',(int)$val) ?? ('Person #'.(int)$val));
    case 'student_id':
      return h(lookup($pdo,'student',(int)$val) ?? ('Student #'.(int)$val));
    case 'agency_id':
      return h(lookup($pdo,'agency',(int)$val) ?? ('Agjenci #'.(int)$val));
    case 'group_id':
      return h(lookup($pdo,'group',(int)$val) ?? ('Grup #'.(int)$val));
    default:
      return h($val);
  }
}

/** Subjekti njerëzor i rreshtit të prekur (p.sh. “Përdorues: John Doe”, “Student: AMZË 1234”, …) */
function subjectFor(PDO $pdo, string $table, array $pk, array $tableLabels): string {
  $label = $tableLabels[$table] ?? ucfirst($table);
  try {
    switch ($table) {
      case 'users':
        $row = $pdo->prepare("SELECT COALESCE(NULLIF(full_name,''), email, CONCAT('User #',id)) AS t FROM users WHERE id=?");
        $row->execute([(int)($pk['id'] ?? 0)]);
        $t = $row->fetchColumn(); return $label.': '.($t ?: ('#'.((int)($pk['id'] ?? 0))));
      case 'persons':
        $row = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(father_name,''),' ',COALESCE(last_name,''))) AS t FROM persons WHERE id=?");
        $row->execute([(int)($pk['id'] ?? 0)]);
        $t = $row->fetchColumn(); return $label.': '.(nonEmpty($t)?$t:('#'.((int)($pk['id'] ?? 0))));
      case 'students':
        $row = $pdo->prepare("SELECT nr_amze FROM students WHERE id=?");
        $row->execute([(int)($pk['id'] ?? 0)]);
        $t = $row->fetchColumn(); return $label.': '.(nonEmpty($t)?('AMZË '.$t):('#'.((int)($pk['id'] ?? 0))));
      case 'agencies':
        $row = $pdo->prepare("SELECT COALESCE(NULLIF(company_name,''), CONCAT('Agjenci #',id)) FROM agencies WHERE id=?");
        $row->execute([(int)($pk['id'] ?? 0)]);
        $t = $row->fetchColumn(); return $label.': '.($t ?: ('#'.((int)($pk['id'] ?? 0))));
      case 'courses':
        $row = $pdo->prepare("SELECT CONCAT(code,' · ',name) FROM courses WHERE id=?");
        $row->execute([(int)($pk['id'] ?? 0)]);
        $t = $row->fetchColumn(); return $label.': '.($t ?: ('#'.((int)($pk['id'] ?? 0))));
      case 'course_groups':
        $row = $pdo->prepare("
          SELECT CONCAT('Grupi #',cg.id,' (',c.code,' · ',c.name,')') AS t
          FROM course_groups cg JOIN courses c ON c.id=cg.course_id WHERE cg.id=?");
        $row->execute([(int)($pk['id'] ?? 0)]);
        $t = $row->fetchColumn(); return $label.': '.($t ?: ('#'.((int)($pk['id'] ?? 0))));
      case 'course_group_students':
        $gid = (int)($pk['group_id'] ?? 0);
        $sid = (int)($pk['student_id'] ?? 0);
        $g = lookup($pdo,'group',$gid) ?? ('Grup #'.$gid);
        $s = lookup($pdo,'student',$sid) ?? ('Student #'.$sid);
        return $label.': '.$s.' në '.$g;
      default:
        // fallback
        $pkText = $pk ? implode(', ', array_map(fn($k)=>$k.'='.$pk[$k], array_keys($pk))) : '—';
        return ($tableLabels[$table] ?? ucfirst($table)).' ('.$pkText.')';
    }
  } catch (\Throwable $e) {
    $pkText = $pk ? implode(', ', array_map(fn($k)=>$k.'='.$pk[$k], array_keys($pk))) : '—';
    return ($tableLabels[$table] ?? ucfirst($table)).' ('.$pkText.')';
  }
}

/* ===== Filters ===== */
$action  = isset($_GET['action']) && in_array($_GET['action'], ['INSERT','UPDATE','DELETE'], true) ? $_GET['action'] : null;
$table   = isset($_GET['table']) && $_GET['table'] !== '' ? $_GET['table'] : null;
$q       = isset($_GET['q']) && $_GET['q'] !== '' ? trim($_GET['q']) : null;
$from    = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : null; // YYYY-MM-DD
$to      = isset($_GET['to'])   && $_GET['to']   !== '' ? $_GET['to']   : null;

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int)($_GET['per'] ?? 25)));
$offset  = ($page - 1) * $perPage;

/* Build WHERE */
$where = [];
$params = [];

if ($action) { $where[] = "ae.action = :action"; $params[':action'] = $action; }
if ($table)  { $where[] = "ae.table_name = :table"; $params[':table'] = $table; }
if ($from)   { $where[] = "ae.happened_at >= :from"; $params[':from'] = $from . ' 00:00:00'; }
if ($to)     { $where[] = "ae.happened_at <= :to";   $params[':to']   = $to   . ' 23:59:59'; }

if ($q) {
  // MariaDB-safe: kërko në emër/email ose tekstin e PK JSON
  $where[] = "(u.full_name LIKE :q OR u.email LIKE :q OR INSTR(ae.row_pk, :q2) > 0)";
  $params[':q']  = '%'.$q.'%';
  $params[':q2'] = $q;
}

$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* Tablat për dropdown */
$tables = $pdo->query("SELECT DISTINCT table_name FROM audit_events ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);

/* Count total */
$cntSql = "SELECT COUNT(*) FROM audit_events ae LEFT JOIN users u ON u.id=ae.user_id $whereSql";
$cntSt  = $pdo->prepare($cntSql);
foreach ($params as $k => $v) {
  $cntSt->bindValue($k, $v);
}
$cntSt->execute();
$total = (int)$cntSt->fetchColumn();

/* Fetch events page */
$perPageI = (int)$perPage;
$offsetI  = (int)$offset;

$sql = "
  SELECT ae.id, ae.happened_at, ae.action, ae.table_name, ae.row_pk, ae.user_id, ae.ip_address, ae.user_agent
       , u.full_name, u.email, r.name AS role_name
  FROM audit_events ae
  LEFT JOIN users u ON u.id=ae.user_id
  LEFT JOIN roles r ON r.id=u.role_id
  $whereSql
  ORDER BY ae.id DESC
  LIMIT $perPageI OFFSET $offsetI
";
$st = $pdo->prepare($sql);
foreach ($params as $k=>$v){ $st->bindValue($k,$v); }
$st->execute();

$events = $st->fetchAll(PDO::FETCH_ASSOC);

/* Load fields for listed events (to show diff) */
$byId = array_values(array_map('intval', array_column($events, 'id'))); // rindezohen + int
$fields = [];
if ($byId) {
  $in = implode(',', array_fill(0, count($byId), '?'));
  $fs = $pdo->prepare(
    "SELECT event_id, column_name, old_value, new_value
     FROM audit_event_fields
     WHERE event_id IN ($in)
     ORDER BY column_name ASC"
  );
  $fs->execute($byId); // tani është numerik 0..n-1
  while ($row = $fs->fetch(PDO::FETCH_ASSOC)) {
    $fields[(int)$row['event_id']][] = $row;
  }
}

$NAV_ACTIVE = 'logs';
require __DIR__ . '/inc/navbar.php';
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Logs – QTA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { background:#f6f8fb; padding-top:72px; }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
    .badge-action { text-transform:uppercase; letter-spacing:.02em; }
    .nowrap { white-space:nowrap; }
    .ev-border-success { border-left:6px solid #22c55e !important; }
    .ev-border-warning { border-left:6px solid #f59e0b !important; }
    .ev-border-danger  { border-left:6px solid #ef4444 !important; }
    .diff-old { background:#fff1f2; }
    .diff-new { background:#ecfdf5; }
    .muted-pill { background:#eef2ff; color:#334155; border-radius:999px; padding:.15rem .5rem; font-size:.75rem; }
    .chip { background:#f1f5f9; border:1px solid #e2e8f0; border-radius:999px; padding:.2rem .6rem; }
  </style>
</head>
<body>

<main class="container-fluid px-3 px-md-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
    <h3 class="mb-2 mb-md-0">Auditim i ndryshimeve</h3>
    <span class="text-muted">Gjithsej: <?= number_format($total) ?></span>
  </div>

  <!-- Filters miqësore -->
  <div class="card mb-4">
    <div class="card-body">
      <form class="row g-3 align-items-end">
        <div class="col-12 col-md-2">
          <label class="form-label">Veprimi</label>
          <select class="form-select" name="action">
            <option value="">Të gjitha</option>
            <?php foreach (['INSERT'=>'Shtime','UPDATE'=>'Ndryshime','DELETE'=>'Fshirje'] as $code=>$label): ?>
              <option value="<?= $code ?>"<?= $action===$code?' selected':'' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">Zona e sistemit</label>
          <select class="form-select" name="table">
            <option value="">Të gjitha</option>
            <?php foreach ($tables as $t): ?>
              <option value="<?= h($t) ?>"<?= $table===$t?' selected':'' ?>><?= h($tableLabels[$t] ?? ucfirst($t)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Nga data</label>
          <input type="date" class="form-control" name="from" value="<?= h($from) ?>">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Deri më</label>
          <input type="date" class="form-control" name="to" value="<?= h($to) ?>">
        </div>
        <div class="col-12 col-md-2">
          <label class="form-label">Kërko</label>
          <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Emër, email ose ID">
        </div>
        <div class="col-6 col-md-1">
          <label class="form-label">/faqe</label>
          <input type="number" class="form-control" name="per" min="10" max="100" value="<?= (int)$perPage ?>">
        </div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary"><i class="bi bi-filter me-1"></i>Filtro</button>
          <a class="btn btn-outline-secondary" href="logs.php"><i class="bi bi-x-circle me-1"></i>Fshij filtrat</a>
          <!-- Shkurtore kohore -->
          <a class="btn btn-outline-dark btn-sm chip" href="logs.php?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>">Sot</a>
          <a class="btn btn-outline-dark btn-sm chip" href="logs.php?from=<?= date('Y-m-d',strtotime('-7 days')) ?>&to=<?= date('Y-m-d') ?>">7 ditë</a>
          <a class="btn btn-outline-dark btn-sm chip" href="logs.php?from=<?= date('Y-m-d',strtotime('-30 days')) ?>&to=<?= date('Y-m-d') ?>">30 ditë</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Tabela miqësore -->
  <div class="card">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Kur</th>
              <th>Çfarë u prek</th>
              <th>Veprimi</th>
              <th>Përmbledhje e kuptueshme</th>
              <th>Kush</th>
              <th>IP</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$events): ?>
              <tr><td colspan="8" class="text-center text-muted">S’ka rezultate për filtrat e zgjedhur.</td></tr>
            <?php else: foreach ($events as $ev): ?>
              <?php
                $eid = (int)$ev['id'];
                $pk  = json_decode($ev['row_pk'] ?? 'null', true) ?: [];
                $act = $ev['action'];
                $badge = $act==='INSERT' ? 'success' : ($act==='UPDATE' ? 'warning' : 'danger');
                $rowClass = $act==='INSERT' ? 'ev-border-success' : ($act==='UPDATE' ? 'ev-border-warning' : 'ev-border-danger');

                // Subjekti (p.sh. "Student: AMZË 1234")
                $subject = subjectFor($pdo, (string)$ev['table_name'], $pk, $tableLabels);

                // Përmbledhje njerëzore e diferencave
                $diffs = $fields[$eid] ?? [];
                $humanBits = [];
                $translated = $columnLabels[$ev['table_name']] ?? [];
                foreach ($diffs as $f) {
                  $col = (string)$f['column_name'];
                  $label = $translated[$col] ?? ucfirst(str_replace('_',' ',$col));
                  $old = prettyValue($pdo, $ev['table_name'], $col, $f['old_value']);
                  $new = prettyValue($pdo, $ev['table_name'], $col, $f['new_value']);
                  if ($act === 'INSERT') {
                    $humanBits[] = "$label: $new";
                  } elseif ($act === 'DELETE') {
                    $humanBits[] = "$label: $old";
                  } else {
                    $humanBits[] = "$label: nga \"$old\" në \"$new\"";
                  }
                }
                // Limit shfaqjen e gjatë
                $summary = $humanBits ? implode('; ', array_slice($humanBits, 0, 4)) . (count($humanBits)>4 ? '…' : '') : '—';

                // Kush e bëri
                $who = $ev['full_name'] ?: ($ev['email'] ?? '—');
                $role = $ev['role_name'] ?? null;
              ?>
              <tr class="<?= $rowClass ?>">
                <td class="nowrap">#<?= $eid ?></td>
                <td class="small text-muted">
                  <?= h($ev['happened_at']) ?><br><span class="muted-pill"><?= h(timeAgo($ev['happened_at'])) ?></span>
                </td>
                <td>
                  <div class="fw-semibold"><?= h($tableLabels[$ev['table_name']] ?? ucfirst($ev['table_name'])) ?></div>
                  <div class="small text-muted"><?= h($subject) ?></div>
                </td>
                <td><span class="badge badge-action text-bg-<?= $badge ?>"><?= $act==='INSERT'?'Shtim':($act==='UPDATE'?'Ndryshim':'Fshirje') ?></span></td>
                <td style="max-width:520px;">
                  <?= h($summary) ?>
                </td>
                <td>
                  <div class="fw-semibold"><?= h($who) ?></div>
                  <div class="small text-muted"><?= h($role ?? '') ?></div>
                </td>
                <td class="small text-muted"><?= h($ev['ip_address'] ?? '—') ?></td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#ev<?= $eid ?>">
                    Shiko detajet
                  </button>
                </td>
              </tr>
              <tr class="collapse" id="ev<?= $eid ?>">
                <td colspan="8">
                  <div class="row g-3">
                    <div class="col-lg-7">
                      <div class="border rounded p-2">
                        <div class="small text-muted mb-2"><i class="bi bi-list-check me-1"></i>Ndryshimet fushë-për-fushë</div>
                        <div class="table-responsive">
                          <table class="table table-sm">
                            <thead class="table-light">
                              <tr><th>Fusha</th><th class="w-50">Vlera e vjetër</th><th class="w-50">Vlera e re</th></tr>
                            </thead>
                            <tbody>
                              <?php if ($diffs): foreach ($diffs as $f):
                                $col = (string)$f['column_name'];
                                $label = ($columnLabels[$ev['table_name']][$col] ?? ucfirst(str_replace('_',' ',$col)));
                                $old = prettyValue($pdo, $ev['table_name'], $col, $f['old_value']);
                                $new = prettyValue($pdo, $ev['table_name'], $col, $f['new_value']);
                              ?>
                                <tr>
                                  <td class="small"><?= h($label) ?></td>
                                  <td class="small diff-old"><?= $ev['action']==='INSERT' ? '—' : $old ?></td>
                                  <td class="small diff-new"><?= $ev['action']==='DELETE' ? '—' : $new ?></td>
                                </tr>
                              <?php endforeach; else: ?>
                                <tr><td colspan="3" class="text-muted">S’ka diferenca të regjistruara.</td></tr>
                              <?php endif; ?>
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </div>
                    <div class="col-lg-5">
                      <div class="border rounded p-2">
                        <div class="d-flex align-items-center justify-content-between">
                          <div class="small text-muted"><i class="bi bi-gear-wide-connected me-1"></i>Detaje teknike (opsionale)</div>
                          <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#raw<?= $eid ?>">
                            Shfaq/FSheh
                          </button>
                        </div>
                        <?php
                          $j = $pdo->prepare("SELECT old_data, new_data, user_agent FROM audit_events WHERE id = ?");
                          $j->execute([$eid]);
                          $jd = $j->fetch(PDO::FETCH_ASSOC) ?: [];
                        ?>
                        <div class="collapse mt-2" id="raw<?= $eid ?>">
                          <div class="small mb-1"><span class="badge text-bg-secondary">UA</span> <?= h($jd['user_agent'] ?? '') ?></div>
                          <div class="row g-2">
                            <div class="col-md-6">
                              <div class="small text-muted mb-1">old_data</div>
                              <pre class="mb-0" style="white-space:pre-wrap; font-family:ui-monospace; font-size:.8rem;"><?= h($jd['old_data'] ?: 'null') ?></pre>
                            </div>
                            <div class="col-md-6">
                              <div class="small text-muted mb-1">new_data</div>
                              <pre class="mb-0" style="white-space:pre-wrap; font-family:ui-monospace; font-size:.8rem;"><?= h($jd['new_data'] ?: 'null') ?></pre>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php
        $pages = max(1, (int)ceil($total / $perPage));
        $qs = $_GET; unset($qs['page']);
        $base = 'logs.php?'.http_build_query($qs);
      ?>
      <nav class="mt-3" aria-label="Faqet">
        <ul class="pagination pagination-sm">
          <?php for ($i=1; $i<=$pages; $i++): ?>
            <li class="page-item<?= $i===$page ? ' active' : '' ?>">
              <a class="page-link" href="<?= $base . '&page=' . $i ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  </div>

  <div class="text-center text-muted small mt-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
