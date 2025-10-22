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
$currentUser = $u->fetch(PDO::FETCH_ASSOC);
if (!$currentUser || strtolower((string)($currentUser['role_name']??''))!=='administrator') { header('Location: selectProfile.php'); exit; }

/* ===== Helpers ===== */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function nonEmpty(?string $s): bool { return $s !== null && $s !== ''; }
function fmtDate(?string $v): string {
  if ($v === null || $v === '') return '—';
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $ts = strtotime($v); return $ts ? date('d-m-Y', $ts) : h($v); }
  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) { $ts = strtotime($v); return $ts ? date('d-m-Y H:i', $ts) : h($v); }
  return h($v);
}
function timeAgo(string $ts): string {
  $t = strtotime($ts) ?: time();
  $d = time() - $t;
  if ($d < 60) return 'pak sekonda më parë';
  $m = intdiv($d,60); if ($m < 60) return $m.' min më parë';
  $h = intdiv($m,60); if ($h < 24) return $h.' orë më parë';
  $dy = intdiv($h,24); if ($dy < 30) return $dy.' ditë më parë';
  $mo = intdiv($dy,30); return $mo.' muaj më parë';
}

/* ===== Etiketa njerëzore ===== */
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
    'full_name'=>'Emri i plotë','email'=>'Email','role_id'=>'Roli','person_id'=>'Personi','created_at'=>'Krijuar më',
  ],
  'persons' => [
    'personal_number'=>'ID personale','first_name'=>'Emri','father_name'=>'Emri i atit','last_name'=>'Mbiemri',
    'birth_date'=>'Datëlindja','birth_place'=>'Vendlindja','phone'=>'Telefoni','gender_id'=>'Gjinia',
  ],
  'students' => [
    'person_id'=>'Personi','user_id'=>'Llogaria','nr_amze'=>'AMZË','education_level_id'=>'Niveli arsimor','created_at'=>'Krijuar më',
  ],
  'agencies' => [
    'user_id'=>'Llogaria','nip_t'=>'NIPT','company_name'=>'Emri i kompanisë','address'=>'Adresa','phone'=>'Telefoni',
  ],
  'courses' => [
    'code'=>'Kodi','name'=>'Emri i modulit','hours'=>'Orë mësimore','created_at'=>'Krijuar më',
  ],
  'course_groups' => [
    'course_id'=>'Moduli','start_date'=>'Fillon','end_date'=>'Mbaron','is_completed'=>'Përfunduar? (grup)','created_at'=>'Krijuar më',
  ],
  'course_group_students' => [
    'group_id'=>'Grupi','student_id'=>'Studenti','final_score'=>'Nota finale','exam_date'=>'Data testit (student)',
  ],
];

/* ===== Ikona për zona (UI) ===== */
$tableIcons = [
  'users' => 'person-badge',
  'roles' => 'award',
  'persons' => 'person',
  'students' => 'mortarboard',
  'agencies' => 'building',
  'courses' => 'book',
  'course_groups' => 'collection',
  'course_group_students' => 'people',
];

/* ===== Lookups me cache ===== */
function lookup(PDO $pdo, string $what, $id): ?string {
  static $cache = [];
  if ($id === null || $id === '') return null;
  $key = $what.':'.$id;
  if (isset($cache[$key])) return $cache[$key];

  switch ($what) {
    case 'role':   $st = $pdo->prepare("SELECT name FROM roles WHERE id=?"); break;
    case 'gender': $st = $pdo->prepare("SELECT label FROM genders WHERE id=?"); break;
    case 'edu':    $st = $pdo->prepare("SELECT label FROM education_levels WHERE id=?"); break;
    case 'course': $st = $pdo->prepare("SELECT CONCAT(code,' · ',name) FROM courses WHERE id=?"); break;
    case 'user':   $st = $pdo->prepare("SELECT COALESCE(NULLIF(full_name,''), email, CONCAT('User #',id)) FROM users WHERE id=?"); break;
    case 'person': $st = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(father_name,''),' ',COALESCE(last_name,''))) FROM persons WHERE id=?"); break;
    case 'student':$st = $pdo->prepare("SELECT CONCAT('AMZË ',nr_amze) FROM students WHERE id=?"); break;
    case 'agency': $st = $pdo->prepare("SELECT COALESCE(NULLIF(company_name,''), CONCAT('Agjenci #',id)) FROM agencies WHERE id=?"); break;
    case 'group':
      $st = $pdo->prepare("SELECT CONCAT('Grupi #',cg.id,' (',c.code,' · ',c.name,')')
                           FROM course_groups cg JOIN courses c ON c.id=cg.course_id WHERE cg.id=?"); break;
    default: return null;
  }
  $st->execute([$id]); $val = $st->fetchColumn();
  return $cache[$key] = ($val ?: null);
}

/* ===== Formatim vlerash sipas kolonës ===== */
function prettyValue(PDO $pdo, string $table, string $col, ?string $val): string {
  if ($val === null || $val === '') return '—';
  switch ($col) {
    case 'birth_date': case 'start_date': case 'end_date': case 'exam_date': case 'created_at': return fmtDate($val);
    case 'is_completed': return in_array(strtolower(trim((string)$val)), ['1','true','t','yes','y','on'], true) ? 'I përfunduar' : 'Jo i përfunduar';
    case 'hours': return rtrim(rtrim((string)$val,'0'),'.').' orë';
    case 'final_score': return rtrim(rtrim((string)$val,'0'),'.');
    case 'role_id': return h(lookup($pdo,'role',(int)$val) ?? ('Rol #'.(int)$val));
    case 'gender_id': return h(lookup($pdo,'gender',(int)$val) ?? ('Gjini #'.(int)$val));
    case 'education_level_id': return h(lookup($pdo,'edu',(int)$val) ?? ('Niv. #'.(int)$val));
    case 'course_id': return h(lookup($pdo,'course',(int)$val) ?? ('Modul #'.(int)$val));
    case 'user_id': return h(lookup($pdo,'user',(int)$val) ?? ('User #'.(int)$val));
    case 'person_id': return h(lookup($pdo,'person',(int)$val) ?? ('Person #'.(int)$val));
    case 'student_id': return h(lookup($pdo,'student',(int)$val) ?? ('Student #'.(int)$val));
    case 'agency_id': return h(lookup($pdo,'agency',(int)$val) ?? ('Agjenci #'.(int)$val));
    case 'group_id': return h(lookup($pdo,'group',(int)$val) ?? ('Grup #'.(int)$val));
    default: return h($val);
  }
}

/* ===== Subjekti njerëzor i rreshtit ===== */
function subjectFor(PDO $pdo, string $table, array $pk, array $tableLabels): string {
  $label = $tableLabels[$table] ?? ucfirst($table);
  try {
    switch ($table) {
      case 'users':
        $row = $pdo->prepare("SELECT COALESCE(NULLIF(full_name,''), email, CONCAT('User #',id)) FROM users WHERE id=?");
        $row->execute([(int)($pk['id'] ?? 0)]); $t = $row->fetchColumn(); return $label.': '.($t ?: ('#'.((int)($pk['id'] ?? 0))));
// ... (pjesët e tjera si më parë)
      case 'persons':
        $row = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(father_name,''),' ',COALESCE(last_name,''))) FROM persons WHERE id=?");
        $row->execute([(int)($pk['id'] ?? 0)]); $t = $row->fetchColumn(); return $label.': '.(nonEmpty($t)?$t:('#'.((int)($pk['id'] ?? 0))));
      case 'students':
        $row = $pdo->prepare("SELECT nr_amze FROM students WHERE id=?");
        $row->execute([(int)($pk['id'] ?? 0)]); $t = $row->fetchColumn(); return $label.': '.(nonEmpty($t)?('AMZË '.$t):('#'.((int)($pk['id'] ?? 0))));
      case 'agencies':
        $row = $pdo->prepare("SELECT COALESCE(NULLIF(company_name,''), CONCAT('Agjenci #',id)) FROM agencies WHERE id=?");
        $row->execute([(int)($pk['id'] ?? 0)]); $t = $row->fetchColumn(); return $label.': '.($t ?: ('#'.((int)($pk['id'] ?? 0))));
      case 'courses':
        $row = $pdo->prepare("SELECT CONCAT(code,' · ',name) FROM courses WHERE id=?");
        $row->execute([(int)($pk['id'] ?? 0)]); $t = $row->fetchColumn(); return $label.': '.($t ?: ('#'.((int)($pk['id'] ?? 0))));
      case 'course_groups':
        $row = $pdo->prepare("SELECT CONCAT('Grupi #',cg.id,' (',c.code,' · ',c.name,')')
                              FROM course_groups cg JOIN courses c ON c.id=cg.course_id WHERE cg.id=?");
        $row->execute([(int)($pk['id'] ?? 0)]); $t = $row->fetchColumn(); return $label.': '.($t ?: ('#'.((int)($pk['id'] ?? 0))));
      case 'course_group_students':
        $gid = (int)($pk['group_id'] ?? 0); $sid = (int)($pk['student_id'] ?? 0);
        $g = lookup($pdo,'group',$gid) ?? ('Grup #'.$gid); $s = lookup($pdo,'student',$sid) ?? ('Student #'.$sid);
        return $label.': '.$s.' në '.$g;
      default:
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

$where = [];
$params = [];
if ($action) { $where[] = "ae.action = :action"; $params[':action'] = $action; }
if ($table)  { $where[] = "ae.table_name = :table"; $params[':table'] = $table; }
if ($from)   { $where[] = "ae.happened_at >= :from"; $params[':from'] = $from.' 00:00:00'; }
if ($to)     { $where[] = "ae.happened_at <= :to";   $params[':to']   = $to  .' 23:59:59'; }
if ($q) { $where[] = "(u.full_name LIKE :q OR u.email LIKE :q OR INSTR(ae.row_pk, :q2) > 0)"; $params[':q'] = '%'.$q.'%'; $params[':q2'] = $q; }
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$tables = $pdo->query("SELECT DISTINCT table_name FROM audit_events ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);

/* ===== Count & Fetch ===== */
$cntSt = $pdo->prepare("SELECT COUNT(*) FROM audit_events ae LEFT JOIN users u ON u.id=ae.user_id $whereSql");
foreach ($params as $k=>$v) { $cntSt->bindValue($k,$v); }
$cntSt->execute(); $total = (int)$cntSt->fetchColumn();

$perPageI=(int)$perPage; $offsetI=(int)$offset;
$st = $pdo->prepare("
  SELECT ae.id, ae.happened_at, ae.action, ae.table_name, ae.row_pk, ae.user_id, ae.ip_address, ae.user_agent,
         u.full_name, u.email, r.name AS role_name
  FROM audit_events ae
  LEFT JOIN users u ON u.id=ae.user_id
  LEFT JOIN roles r ON r.id=u.role_id
  $whereSql
  ORDER BY ae.happened_at DESC, ae.id DESC
  LIMIT $perPageI OFFSET $offsetI
");
foreach ($params as $k=>$v) { $st->bindValue($k,$v); }
$st->execute();
$events = $st->fetchAll(PDO::FETCH_ASSOC);

/* ===== Fields (diff) ===== */
$ids = array_values(array_map('intval', array_column($events, 'id')));
$fields = [];
if ($ids) {
  $in = implode(',', array_fill(0, count($ids), '?'));
  $fs = $pdo->prepare("SELECT event_id, column_name, old_value, new_value FROM audit_event_fields WHERE event_id IN ($in) ORDER BY column_name ASC");
  $fs->execute($ids);
  while ($row = $fs->fetch(PDO::FETCH_ASSOC)) { $fields[(int)$row['event_id']][] = $row; }
}

/* ===== Statistika për kartat (brenda filtrave) ===== */
$stats = ['total'=>$total,'ins'=>0,'upd'=>0,'del'=>0];
$stc = $pdo->prepare("SELECT action, COUNT(*) c FROM audit_events ae LEFT JOIN users u ON u.id=ae.user_id $whereSql GROUP BY action");
foreach ($params as $k=>$v) { $stc->bindValue($k,$v); }
$stc->execute();
while ($r = $stc->fetch(PDO::FETCH_ASSOC)) {
  if ($r['action']==='INSERT') $stats['ins']=(int)$r['c'];
  elseif ($r['action']==='UPDATE') $stats['upd']=(int)$r['c'];
  elseif ($r['action']==='DELETE') $stats['del']=(int)$r['c'];
}

/* Top tabela */
$topTables=[];
$stt = $pdo->prepare("SELECT ae.table_name, COUNT(*) c FROM audit_events ae LEFT JOIN users u ON u.id=ae.user_id $whereSql GROUP BY ae.table_name ORDER BY c DESC LIMIT 5");
foreach ($params as $k=>$v) { $stt->bindValue($k,$v); }
$stt->execute(); $topTables=$stt->fetchAll(PDO::FETCH_ASSOC);

/* Top përdorues */
$topUsers=[];
$stu = $pdo->prepare("SELECT COALESCE(NULLIF(u.full_name,''), u.email, CONCAT('User #',u.id)) nm, COUNT(*) c
  FROM audit_events ae LEFT JOIN users u ON u.id=ae.user_id $whereSql GROUP BY nm ORDER BY c DESC LIMIT 5");
foreach ($params as $k=>$v) { $stu->bindValue($k,$v); }
$stu->execute(); $topUsers=$stu->fetchAll(PDO::FETCH_ASSOC);

/* ===== Eksport CSV (opsional) ===== */
if (isset($_GET['export']) && $_GET['export']==='csv') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="auditime_'.date('Ymd_His').'.csv"');
  $out = fopen('php://output','w');
  fputcsv($out, ['ID','Kur','Veprimi','Zona','Subjekti','Kush','IP','Përmbledhje']);
  foreach ($events as $ev) {
    $eid = (int)$ev['id'];
    $pk  = json_decode($ev['row_pk'] ?? 'null', true) ?: [];
    $subject = subjectFor($pdo, (string)$ev['table_name'], $pk, $tableLabels);
    $diffs = $fields[$eid] ?? [];
    $human=[];
    $translated = $columnLabels[$ev['table_name']] ?? [];
    foreach ($diffs as $f) {
      $col=(string)$f['column_name'];
      $label=$translated[$col] ?? ucfirst(str_replace('_',' ',$col));
      $old = prettyValue($pdo, $ev['table_name'], $col, $f['old_value']);
      $new = prettyValue($pdo, $ev['table_name'], $col, $f['new_value']);
      if ($ev['action']==='INSERT') $human[]="$label: $new";
      elseif ($ev['action']==='DELETE') $human[]="$label: $old";
      else $human[]="$label: $old → $new";
    }
    $summary = $human ? implode('; ', $human) : '—';
    $who = $ev['full_name'] ?: ($ev['email'] ?? '—');
    fputcsv($out, [$eid, $ev['happened_at'], $ev['action'], ($tableLabels[$ev['table_name']] ?? $ev['table_name']), $subject, $who, ($ev['ip_address'] ?? ''), $summary]);
  }
  fclose($out); exit;
}

$NAV_ACTIVE = 'logs';
require __DIR__ . '/inc/navbar.php';
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Auditime – QTA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { background:#f5f7fb; padding-top:72px; }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
    .nowrap { white-space:nowrap; }
    .chip { background:#eef2ff; color:#1f2937; border:1px solid #e5e7eb; border-radius:999px; padding:.25rem .6rem; font-size:.85rem; }
    .help-box { background:#eff6ff; border:1px solid #dbeafe; border-radius:.75rem; }

    /* Karta statistike */
    .stat-card { border-radius:1rem; }
    .stat-icon { width:44px; height:44px; border-radius:50%; display:flex; align-items:center; justify-content:center; background:#f8fafc; }

    /* Ngjarje */
    .ev { border-left:6px solid transparent; border-radius:1rem; }
    .ev-insert { border-left-color:#22c55e; background:#f0fdf4; }
    .ev-update { border-left-color:#f59e0b; background:#fffbeb; }
    .ev-delete { border-left-color:#ef4444; background:#fef2f2; }
    .ev + .ev { margin-top: .75rem; }

    .diff-badge { font-size:.75rem; border-radius:.5rem; padding:.15rem .4rem; }
    .diff-old { background:#fee2e2; }
    .diff-new { background:#dcfce7; }

    /* Datat si seksione */
    .day-header { position:sticky; top:64px; z-index:5; background:#f5f7fb; padding:.35rem 0; }

    /* FAB export/refresh */
    .fab {
      position: fixed; bottom: 24px; right: 24px; width:56px; height:56px;
      border-radius: 50%; display:flex; align-items:center; justify-content:center;
      box-shadow:0 12px 24px rgba(2,6,23,.18); z-index:1055;
    }
    .fab-secondary { right: 92px; }

    @media (max-width: 575.98px){
      .day-header { top:56px; }
    }
  </style>
</head>
<body>

<main class="container-fluid px-3 px-md-4">

  <!-- Header i thjeshtë -->
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center gap-3">
      <h2 class="mb-0">Auditime</h2>
      <span class="text-muted small"><?= number_format($total) ?> ngjarje</span>
    </div>
  </div>

  <!-- Ndihmë e shpejtë -->
  <div class="help-box p-3 mb-3">
    <div class="d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-2">
        <i class="bi bi-info-circle"></i>
        <strong>Çfarë shoh këtu?</strong>
      </div>
      <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#helpBody">Shpjego</button>
    </div>
    <div id="helpBody" class="collapse mt-2">
      <ul class="mb-0">
        <li><span class="text-success fw-semibold">E gjelbër</span> = <strong>Shtim</strong> (u krijua diçka e re)</li>
        <li><span class="text-warning fw-semibold">E verdhë</span> = <strong>Ndryshim</strong> (diçka u përditësua)</li>
        <li><span class="text-danger fw-semibold">E kuqe</span> = <strong>Fshirje</strong></li>
      </ul>
      <div class="small text-muted mt-2">Detajet teknike (JSON, IP, etj.) janë të fshehura – kliko “Detaje” te çdo rresht për t’i parë.</div>
    </div>
  </div>

  <!-- Karta statistike -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-md-3">
      <div class="card stat-card p-3">
        <div class="d-flex align-items-center gap-3">
          <div class="stat-icon"><i class="bi bi-activity"></i></div>
          <div>
            <div class="text-muted small">Ngjarje gjithsej</div>
            <div class="fs-4 fw-bold"><?= number_format($stats['total']) ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-4 col-md-3">
      <div class="card stat-card p-3">
        <div class="d-flex align-items-center gap-3">
          <div class="stat-icon"><i class="bi bi-plus-circle text-success"></i></div>
          <div>
            <div class="text-muted small">Shtime</div>
            <div class="fs-5 fw-bold text-success"><?= number_format($stats['ins']) ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-4 col-md-3">
      <div class="card stat-card p-3">
        <div class="d-flex align-items-center gap-3">
          <div class="stat-icon"><i class="bi bi-arrow-repeat text-warning"></i></div>
          <div>
            <div class="text-muted small">Ndryshime</div>
            <div class="fs-5 fw-bold text-warning"><?= number_format($stats['upd']) ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-4 col-md-3">
      <div class="card stat-card p-3">
        <div class="d-flex align-items-center gap-3">
          <div class="stat-icon"><i class="bi bi-trash text-danger"></i></div>
          <div>
            <div class="text-muted small">Fshirje</div>
            <div class="fs-5 fw-bold text-danger"><?= number_format($stats['del']) ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Top-5 (opsionale) -->
  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center">
          <strong>Zona më aktive</strong>
          <span class="small text-muted">brenda filtrave</span>
        </div>
        <div class="mt-2">
          <?php if (!$topTables): ?>
            <div class="text-muted small">—</div>
          <?php else: foreach ($topTables as $tt): ?>
            <div class="d-flex align-items-center justify-content-between py-1">
              <div><i class="bi bi-<?= h($tableIcons[$tt['table_name']] ?? 'grid') ?> me-1"></i> <?= h($tableLabels[$tt['table_name']] ?? $tt['table_name']) ?></div>
              <div class="badge text-bg-secondary"><?= (int)$tt['c'] ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center">
          <strong>Aktiviteti sipas përdoruesve</strong>
          <span class="small text-muted">brenda filtrave</span>
        </div>
        <div class="mt-2">
          <?php if (!$topUsers): ?>
            <div class="text-muted small">—</div>
          <?php else: foreach ($topUsers as $tu): ?>
            <div class="d-flex align-items-center justify-content-between py-1">
              <div><i class="bi bi-person-circle me-1"></i> <?= h($tu['nm']) ?></div>
              <div class="badge text-bg-secondary"><?= (int)$tu['c'] ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Filtrat -->
  <div class="card mb-4">
    <div class="card-body">
      <form class="row g-3 align-items-end" method="get" action="logs.php">
        <div class="col-12 col-sm-6 col-md-2">
          <label class="form-label">Veprimi</label>
          <select class="form-select" name="action">
            <option value="">Të gjitha</option>
            <?php foreach (['INSERT'=>'Shtime','UPDATE'=>'Ndryshime','DELETE'=>'Fshirje'] as $code=>$label): ?>
              <option value="<?= $code ?>"<?= $action===$code?' selected':'' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
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
        <div class="col-12 col-md-3">
          <label class="form-label">Kërko (emër, email, AMZË, ID)</label>
          <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="p.sh. Arben, 12345, QTA-ALGO">
        </div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary"><i class="bi bi-filter me-1"></i>Zbato filtrat</button>
          <a class="btn btn-outline-secondary" href="logs.php"><i class="bi bi-x-circle me-1"></i>Pastro</a>
          <a class="btn btn-outline-success" href="<?= 'logs.php?'.h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>">
            <i class="bi bi-download me-1"></i>Eksporto CSV
          </a>
        </div>
      </form>
    </div>
  </div>

  <!-- Ngjarje, të grupuara sipas datës -->
  <?php
    // grupim sipas ditës
    $byDay = [];
    foreach ($events as $ev) {
      $day = substr((string)$ev['happened_at'], 0, 10);
      $byDay[$day][] = $ev;
    }
  ?>

  <?php if (!$events): ?>
    <div class="text-center text-muted py-5">S’ka rezultate për filtrat e zgjedhur.</div>
  <?php else: ?>
    <?php foreach ($byDay as $day => $rows): ?>
      <div class="day-header d-flex align-items-center gap-2 mt-2 mb-1">
        <i class="bi bi-calendar3"></i>
        <strong><?= fmtDate($day) ?></strong>
        <span class="badge text-bg-secondary"><?= count($rows) ?></span>
      </div>

      <?php foreach ($rows as $ev):
        $eid = (int)$ev['id'];
        $pk  = json_decode($ev['row_pk'] ?? 'null', true) ?: [];
        $act = $ev['action'];
        $class = $act==='INSERT' ? 'ev-insert' : ($act==='UPDATE' ? 'ev-update' : 'ev-delete');
        $icon  = $act==='INSERT' ? 'plus-circle' : ($act==='UPDATE' ? 'arrow-repeat' : 'trash');
        $labelAct = $act==='INSERT' ? 'shtoi' : ($act==='UPDATE' ? 'ndryshoi' : 'fshiu');

        $subject = subjectFor($pdo, (string)$ev['table_name'], $pk, $tableLabels);
        $who = $ev['full_name'] ?: ($ev['email'] ?? '—');
        $zoneLabel = $tableLabels[$ev['table_name']] ?? $ev['table_name'];
        $zoneIcon  = $tableIcons[$ev['table_name']] ?? 'grid';

        // përmbledhje njerëzore e ndryshimeve
        $diffs = $fields[$eid] ?? [];
        $translated = $columnLabels[$ev['table_name']] ?? [];
        $human = [];
        foreach ($diffs as $f) {
          $col = (string)$f['column_name'];
          $label = $translated[$col] ?? ucfirst(str_replace('_',' ',$col));
          $old = prettyValue($pdo, $ev['table_name'], $col, $f['old_value']);
          $new = prettyValue($pdo, $ev['table_name'], $col, $f['new_value']);
          if ($act==='INSERT') $human[] = "<span class=\"diff-badge diff-new\">$label: $new</span>";
          elseif ($act==='DELETE') $human[] = "<span class=\"diff-badge diff-old\">$label: $old</span>";
          else $human[] = "<span class=\"diff-badge diff-old\">$label: $old</span> → <span class=\"diff-badge diff-new\">$new</span>";
        }
        $summaryHtml = $human ? implode(' &nbsp; ', array_slice($human,0,6)).(count($human)>6?' …':'') : '—';
        $collapseId = 'ev'.$eid;
        $jsonId = 'raw'.$eid;
      ?>
        <div class="ev <?= $class ?> p-3">
          <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
            <div class="d-flex align-items-start gap-2">
              <div class="mt-1"><i class="bi bi-<?= $icon ?>"></i></div>
              <div>
                <div class="fw-semibold">
                  <?= h($who) ?> <span class="text-muted fw-normal"><?= $labelAct ?></span>
                  <span class="fw-semibold"><?= h($zoneLabel) ?></span>
                </div>
                <div class="small text-muted">
                  <?= h($subject) ?> • <?= h($ev['happened_at']) ?> (<?= h(timeAgo($ev['happened_at'])) ?>)
                </div>
              </div>
            </div>

            <div class="text-end small">
              <div class="text-muted"><i class="bi bi-<?= $zoneIcon ?>"></i> <?= h($zoneLabel) ?></div>
              <div class="mt-1"><span class="text-muted">IP:</span> <code><?= h($ev['ip_address'] ?? '—') ?></code></div>
              <div class="mt-1"><span class="text-muted">ID:</span> <code>#<?= $eid ?></code></div>
            </div>
          </div>

          <div class="mt-2"><?= $summaryHtml ?></div>

          <div class="mt-2 text-end">
            <button class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>">
              <i class="bi bi-chevron-down me-1"></i>Detaje
            </button>
          </div>

          <div class="collapse mt-2" id="<?= $collapseId ?>">
            <div class="row g-2">
              <div class="col-lg-7">
                <div class="border rounded p-2 bg-white">
                  <div class="small text-muted mb-2"><i class="bi bi-list-check me-1"></i>Ndryshimet fushë-për-fushë</div>
                  <div class="table-responsive">
                    <table class="table table-sm mb-0">
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
                          <td class="small"><?= $ev['action']==='INSERT' ? '—' : $old ?></td>
                          <td class="small"><?= $ev['action']==='DELETE' ? '—' : $new ?></td>
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
                <div class="border rounded p-2 bg-white">
                  <div class="d-flex align-items-center justify-content-between">
                    <div class="small text-muted"><i class="bi bi-gear-wide-connected me-1"></i>Detaje teknike (opsionale)</div>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $jsonId ?>">
                      Shfaq/Fsheh JSON
                    </button>
                  </div>
                  <?php
                    $j = $pdo->prepare("SELECT old_data, new_data, user_agent FROM audit_events WHERE id = ?");
                    $j->execute([$eid]); $jd = $j->fetch(PDO::FETCH_ASSOC) ?: [];
                  ?>
                  <div class="collapse mt-2" id="<?= $jsonId ?>">
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
            </div><!-- /row -->
          </div><!-- /collapse -->
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- Pagination -->
  <?php
    $pages = max(1, (int)ceil($total / $perPage));
    $qs = $_GET; unset($qs['page']);
    $base = 'logs.php?'.http_build_query($qs);
    if ($base === 'logs.php?') { $base = 'logs.php?'; }
    $prev = max(1, $page-1); $next = min($pages, $page+1);
  ?>
  <div class="d-flex align-items-center justify-content-end mt-3">
    <nav aria-label="Faqet">
      <ul class="pagination mb-0">
        <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $base ?>page=1">«</a></li>
        <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $base ?>page=<?= $prev ?>">‹</a></li>
        <li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $pages ?></span></li>
        <li class="page-item <?= $page>=$pages?'disabled':'' ?>"><a class="page-link" href="<?= $base ?>page=<?= $next ?>">›</a></li>
        <li class="page-item <?= $page>=$pages?'disabled':'' ?>"><a class="page-link" href="<?= $base ?>page=<?= $pages ?>">»</a></li>
      </ul>
    </nav>
  </div>

  <div class="text-center text-muted small my-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<?php
  // URL për FAB rifresko (ruan filtrat, kthen te faqja 1)
  $fabUrl = 'logs.php?'.http_build_query(array_merge($_GET ?? [], ['page'=>1]));
  if ($fabUrl === 'logs.php?') { $fabUrl = 'logs.php?'; }
  $csvUrl = 'logs.php?'.http_build_query(array_merge($_GET ?? [], ['export'=>'csv']));
?>
<a href="<?= h($csvUrl) ?>" class="btn btn-success fab fab-secondary" title="Eksporto CSV" aria-label="Eksporto CSV">
  <i class="bi bi-download"></i>
</a>
<a href="<?= h($fabUrl) ?>" class="btn btn-primary fab" aria-label="Rifresko" title="Rifresko">
  <i class="bi bi-arrow-repeat"></i>
</a>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
