<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* -------------------------------------------------
   Guard: admin/editor/agjencia (jo studentë)
-------------------------------------------------- */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.role_id, u.full_name, u.email, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = :uid
  LIMIT 1
");
$u->execute([':uid'=>$_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);
if (!$currentUser) { header('Location: selectProfile.php'); exit; }

$ROLE = strtolower((string)$currentUser['role_name']);
if (!in_array($ROLE, ['administrator','editor','agjencia'], true)) { http_response_code(403); exit('Akses i ndaluar.'); }
$CAN_EDIT = in_array($ROLE, ['administrator','editor'], true);

/* -------------------------------------------------
   EDIT MODE toggle (persistohet në session)
-------------------------------------------------- */
if (isset($_GET['edit'])) {
  $e = strtolower((string)$_GET['edit']);
  $_SESSION['edit_mode'] = ($e === 'on');
  $qs = $_GET; unset($qs['edit']);
  $url = 'student_card.php' . (empty($qs) ? '' : ('?' . http_build_query($qs)));
  header("Location: $url"); exit;
}
$EDIT_MODE = $CAN_EDIT ? (bool)($_SESSION['edit_mode'] ?? false) : false;

/* -------------------------------------------------
   CSRF & flash
-------------------------------------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

function flash(string $k, ?string $m=null){
  if ($m===null){ if(!empty($_SESSION['flash'][$k])){ $x=$_SESSION['flash'][$k]; unset($_SESSION['flash'][$k]); return $x; } return null; }
  $_SESSION['flash'][$k]=$m;
}
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* -------------------------------------------------
   Helper për shfaqjen e datave (DD-MM-YYYY)
-------------------------------------------------- */
function fmt_dMY(?string $iso): string {
  if (!$iso) return '—';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return h($iso);
  $ts = strtotime($iso);
  return $ts ? date('d-m-Y', $ts) : '—';
}

/* -------------------------------------------------
   Lexo agency_id (nëse jemi agjenci) për filtrime
-------------------------------------------------- */
$MY_AGENCY_ID = null;
if ($ROLE === 'agjencia') {
  $a = $pdo->prepare("SELECT id FROM agencies WHERE user_id=:u LIMIT 1");
  $a->execute([':u'=>$currentUser['id']]);
  $MY_AGENCY_ID = (int)($a->fetchColumn() ?: 0);
  if ($MY_AGENCY_ID <= 0) { http_response_code(403); exit('Agjencia nuk u gjet.'); }
}

/* -------------------------------------------------
   Kërkim & zgjedhje PERSONI
-------------------------------------------------- */
$q   = trim($_GET['q']   ?? '');
$pid = (int)($_GET['pid'] ?? 0);
$sid = (int)($_GET['sid'] ?? 0);
$person = null;
$studentsOfPerson = [];
$eduLevels = [];
$genders = [];
$stats = $groups = $planned = $upcoming = $series = [];

/* Edu levels & genders për dropdown */
try {
  $eduLevels = $pdo->query("SELECT id, code, label FROM education_levels ORDER BY COALESCE(code, label) ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){ $eduLevels = []; }
try {
  $genders = $pdo->query("SELECT id, code, label FROM genders ORDER BY FIELD(code,'M','F') DESC, label ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){ $genders = []; }

/* Merr pid nga sid nëse s'është dhënë */
if ($pid<=0 && $sid>0) {
  $p = $pdo->prepare("SELECT person_id FROM students WHERE id=:sid");
  $p->execute([':sid'=>$sid]);
  $pid = (int)($p->fetchColumn() ?: 0);
}

/* ====== Kur kemi person të zgjedhur ====== */
$personQR = ['token'=>null,'created_at'=>null];
if ($pid > 0) {
  // Lejo akses për agjenci vetëm nëse të paktën një nga studentët e personit i përket asaj agjencie
  $person = $pdo->prepare("
    SELECT p.id, p.first_name, p.father_name, p.last_name, p.birth_date, p.birth_place,
           p.personal_number, p.phone, p.gender_id,
           g.code AS gender_code, g.label AS gender_label
    FROM persons p
    LEFT JOIN genders g ON g.id = p.gender_id
    WHERE p.id = :pid
  ");
  $person->execute([':pid'=>$pid]);
  $person = $person->fetch(PDO::FETCH_ASSOC);
  if (!$person) { flash('err','Personi nuk u gjet.'); $pid = 0; }

  if ($person) {
    // Të gjitha students (regjistrimet) të këtij personi – përdoren për statistika, nuk shfaqim listë AMZË-sh
    $sqlS = "
      SELECT
        s.id, s.nr_amze, s.education_level_id, el.code AS edu_code, el.label AS edu_label,
        s.created_at, u.email,
        ajs.agency_id, ag.company_name AS agency_name
      FROM students s
      JOIN users u ON u.id = s.user_id
      LEFT JOIN education_levels el ON el.id = s.education_level_id
      LEFT JOIN agency_students ajs ON ajs.student_id = s.id
      LEFT JOIN agencies ag ON ag.id = ajs.agency_id
      WHERE s.person_id = :pid
      ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
    ";
    $st = $pdo->prepare($sqlS); $st->execute([':pid'=>$pid]);
    $studentsOfPerson = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($ROLE === 'agjencia') {
      $haveAny = false;
      foreach ($studentsOfPerson as $row) {
        if ((int)($row['agency_id'] ?? 0) === $MY_AGENCY_ID) { $haveAny = true; break; }
      }
      if (!$haveAny) { $person = null; $studentsOfPerson = []; flash('err','S’keni akses për këtë person.'); }
    }

    if ($person) {
      // Token & QR per person
      $qrP = $pdo->prepare("SELECT token, created_at FROM person_qr_tokens WHERE person_id = :pid");
      $qrP->execute([':pid'=>$pid]);
      if ($row = $qrP->fetch(PDO::FETCH_ASSOC)) {
        $personQR['token'] = $row['token'];
        $personQR['created_at'] = $row['created_at'];
      }

      // Statistika & të dhënat e kombinuara
      $ids = array_map(fn($r)=> (int)$r['id'], $studentsOfPerson);
      if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));

        // KPI: nr grupesh, kurse, orë
        $q1 = $pdo->prepare("
          SELECT COUNT(DISTINCT cgs.group_id) AS groups_cnt,
                 COUNT(DISTINCT cg.course_id) AS courses_cnt,
                 COALESCE(SUM(c.hours),0) AS total_hours
          FROM course_group_students cgs
          JOIN course_groups cg ON cg.id = cgs.group_id
          JOIN courses c ON c.id = cg.course_id
          WHERE cgs.student_id IN ($ph)
        ");
        $q1->execute($ids);
        $row = $q1->fetch(PDO::FETCH_ASSOC);
        $stats['groups']  = (int)($row['groups_cnt'] ?? 0);
        $stats['courses'] = (int)($row['courses_cnt'] ?? 0);
        $stats['hours']   = (int)($row['total_hours'] ?? 0);

        // Mesatare / kalueshmëri / best / last
        $q2 = $pdo->prepare("
          SELECT
            AVG(final_score) AS avg_score,
            SUM(CASE WHEN final_score>=50 THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0) * 100 AS pass_rate,
            MAX(final_score) AS best_score
          FROM course_group_students
          WHERE student_id IN ($ph) AND final_score IS NOT NULL
        ");
        $q2->execute($ids); $row = $q2->fetch(PDO::FETCH_ASSOC);
        $stats['avg_score'] = $row['avg_score']!==null ? round((float)$row['avg_score'],2) : null;
        $stats['pass_rate'] = $row['pass_rate']!==null ? round((float)$row['pass_rate'],1) : null;
        $stats['best']      = $row['best_score']!==null ? round((float)$row['best_score'],1) : null;

        // Nota e fundit
        $q3 = $pdo->prepare("
          SELECT cgs.final_score
          FROM course_group_students cgs
          JOIN course_groups cg ON cg.id = cgs.group_id
          WHERE cgs.student_id IN ($ph) AND cgs.final_score IS NOT NULL
          ORDER BY COALESCE(cgs.exam_date, cg.end_date) DESC, cgs.group_id DESC
          LIMIT 1
        ");
        $q3->execute($ids);
        $stats['last'] = $q3->fetchColumn();

        // Të gjitha grupet
        $q4 = $pdo->prepare("
          SELECT cgs.student_id, cg.id AS group_id, c.code, c.name, cg.start_date, cg.end_date, cgs.exam_date, cgs.final_score
          FROM course_group_students cgs
          JOIN students s ON s.id = cgs.student_id
          JOIN course_groups cg ON cg.id = cgs.group_id
          JOIN courses c ON c.id = cg.course_id
          WHERE cgs.student_id IN ($ph)
          ORDER BY cg.start_date DESC, cg.id DESC
        ");
        $q4->execute($ids);
        $groups = $q4->fetchAll(PDO::FETCH_ASSOC);

        // Modulet e planifikuara (pa grup)
        $q5 = $pdo->prepare("
          SELECT
            scp.id AS scp_id,
            scp.student_id,
            c.id AS course_id,
            c.code,
            c.name
          FROM student_course_plans scp
          JOIN students s ON s.id = scp.student_id
          JOIN courses  c ON c.id = scp.course_id
          WHERE scp.student_id IN ($ph)
            AND scp.status = 'planned'
            AND scp.group_id IS NULL
          ORDER BY c.name ASC
        ");
        $q5->execute($ids);
        $planned = $q5->fetchAll(PDO::FETCH_ASSOC);

        // Provime të afërta (≥ sot)
        $q6 = $pdo->prepare("
          SELECT DISTINCT c.code, c.name, cgs.exam_date
          FROM course_group_students cgs
          JOIN course_groups cg ON cg.id = cgs.group_id
          JOIN courses c ON c.id = cg.course_id
          WHERE cgs.student_id IN ($ph)
            AND cgs.exam_date IS NOT NULL
            AND cgs.exam_date >= CURDATE()
          ORDER BY cgs.exam_date ASC
          LIMIT 8
        ");
        $q6->execute($ids);
        $upcoming = $q6->fetchAll(PDO::FETCH_ASSOC);

        // Seri për grafik
        $q7 = $pdo->prepare("
          SELECT DATE_FORMAT(COALESCE(cgs.exam_date, cg.end_date), '%Y-%m-%d') AS d, cgs.final_score AS s
          FROM course_group_students cgs
          JOIN course_groups cg ON cg.id = cgs.group_id
          WHERE cgs.student_id IN ($ph) AND cgs.final_score IS NOT NULL
          ORDER BY COALESCE(cgs.exam_date, cg.end_date) ASC, cgs.group_id ASC
          LIMIT 80
        ");
        $q7->execute($ids);
        $series = $q7->fetchAll(PDO::FETCH_ASSOC);
      }
    }
  }
}

/* Rezultatet e kërkimit kur s’është zgjedhur personi */
$results = [];
if ($pid<=0 && $q!=='') {
  $sql = "
    SELECT DISTINCT
      p.id AS person_id,
      p.first_name, p.father_name, p.last_name, p.personal_number,
      COUNT(DISTINCT s.id) AS registrations
    FROM persons p
    LEFT JOIN students s ON s.person_id = p.id
    LEFT JOIN users u ON u.id = s.user_id
    WHERE (
       p.first_name      LIKE :kw1
    OR p.last_name       LIKE :kw2
    OR p.personal_number LIKE :kw3
    OR s.nr_amze         LIKE :kw4
    OR s.id              = :idExact
    OR u.email           LIKE :kw5
  )
  ";
  $params = [
    ':kw1' => '%'.$q.'%',
    ':kw2' => '%'.$q.'%',
    ':kw3' => '%'.$q.'%',
    ':kw4' => '%'.$q.'%',
    ':kw5' => '%'.$q.'%',
    ':idExact' => ctype_digit($q) ? (int)$q : -1,
  ];
  if ($ROLE === 'agjencia') {
    $sql .= " AND EXISTS (SELECT 1 FROM agency_students ajs WHERE ajs.student_id = s.id AND ajs.agency_id = :aid)";
    $params[':aid'] = $MY_AGENCY_ID;
  }
  $sql .= " GROUP BY p.id ORDER BY p.last_name, p.first_name LIMIT 40";
  $st = $pdo->prepare($sql); $st->execute($params);
  $results = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* Navbar */
$NAV_ACTIVE = 'students';
if     ($ROLE === 'administrator') require __DIR__ . '/inc/navbar.php';
elseif ($ROLE === 'editor')       require __DIR__ . '/inc/navbar4.php';
elseif ($ROLE === 'agjencia')     require __DIR__ . '/inc/navbar2.php';

/* Toggle URL */
$toggleUrl = 'student_card.php?' . http_build_query(array_filter([
  'q'   => ($q!=='' ? $q : null),
  'pid' => ($pid>0 ? $pid : null),
  'edit'=> ($EDIT_MODE ? 'off' : 'on'),
]));
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Profili i personit – QTA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <style>
    body { background:#f6f8fb; padding-top:72px; }
    .navbar-brand img { height:28px; }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
    .mini-table thead { background:#f1f5f9; }
    .nowrap{ white-space:nowrap; }
    .avatar{ width:72px;height:72px;border-radius:1.25rem;background:linear-gradient(135deg,#eef2ff,#e0e7ff);display:flex;align-items:center;justify-content:center;font-weight:800;color:#4338ca;font-size:1.25rem; }
    .soft { background:#f8fafc; border:1px solid #e5e7eb; }
    .btn-soft { background:#f8fafc; border:1px solid #e5e7eb; }
    .hero { background: linear-gradient(180deg,#ffffff 0%, #f7f9fe 100%); border-radius:1rem; border:1px solid #eef2ff; }
    .stat { display:flex; align-items:center; gap:.9rem; }
    .stat i { font-size:1.4rem; }
    .stat .label{ font-size:.72rem; letter-spacing:.04em; text-transform:uppercase; color:#64748b; }
    .stat .value{ font-weight:700; font-size:1.25rem; }
    .editable { display:inline-block; min-width:72px; padding:.25rem .4rem; border-radius:.5rem; transition:box-shadow .2s, background-color .2s; }
    .editable[contenteditable="true"]:hover { background:#f8fafc; box-shadow:inset 0 0 0 1px #e5e7eb; cursor:text; }
    .editable[contenteditable="true"]:focus { outline:0; background:#eef2ff; box-shadow:inset 0 0 0 2px #4f46e5; }
    .editable[contenteditable="false"] { opacity:.9; cursor:default; }
    .cell-ok { animation: flashOk 1.2s ease; } @keyframes flashOk { 0%{background:#ecfdf5;} 100%{background:transparent;} }
    .cell-err{ animation: flashErr 1.2s ease; } @keyframes flashErr{ 0%{background:#fef2f2;} 100%{background:transparent;} }
    .fab-stack{ position: fixed; right: 24px; bottom: 24px; display: flex; flex-direction: column-reverse; gap: 12px; z-index: 1040; }
    .fab-stack .fab-btn{ align-self: flex-end; display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 52px; height: 52px; width: 52px; padding: 0 14px; border-radius: 999px; box-shadow: 0 12px 20px rgba(2,6,23,.15); transition: width .2s ease, box-shadow .2s ease, transform .06s ease; overflow:hidden; }
    .fab-stack .fab-btn:hover, .fab-stack .fab-btn:focus{ width:auto; box-shadow:0 16px 28px rgba(2,6,23,.22); }
    .fab-stack .fab-btn .fab-text{ white-space:nowrap; max-width:0; opacity:0; transition:max-width .2s ease, opacity .15s ease, margin-left .2s ease; margin-left:0; }
    .fab-stack .fab-btn:hover .fab-text, .fab-stack .fab-btn:focus .fab-text{ max-width: 220px; opacity:1; margin-left:4px; }
    .fab-stack .fab-btn:active{ transform: translateY(1px); }
    .toast-container { z-index:1080; }
    .qta-toast { border-radius:.75rem; box-shadow:0 10px 25px rgba(2,6,23,.18); overflow:hidden; }
    .toast-success .toast-header{ background:#ecfdf5; }
    .toast-danger  .toast-header{ background:#fef2f2; }
    .toast-warning .toast-header{ background:#fff7ed; }
    .toast-info    .toast-header{ background:#eff6ff; }
    .section-title{ display:flex; align-items:center; gap:.6rem; }
    .section-title i{ color:#4f46e5; }
    #qrBox canvas, #qrBox img { image-rendering: pixelated; }
    .pill{ display:inline-block; padding:.3rem .6rem; border-radius:999px; background:#eef2ff; color:#4338ca; font-weight:600; font-size:.8rem; }
  </style>
</head>
<body class="<?= $EDIT_MODE ? '' : 'editing-off' ?>">

<!-- Toast container -->
<div id="toastZone" class="toast-container position-fixed start-0 bottom-0 p-3"></div>

<main class="container-fluid px-3 px-md-4">
  <?php if ($m = flash('ok')): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <i class="bi bi-check-circle me-1"></i><?= h($m) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  <?php if ($m = flash('err')): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="bi bi-exclamation-triangle me-1"></i><?= h($m) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- HERO + SEARCH -->
  <div class="hero p-3 p-md-4 mb-3">
    <form class="row g-2 align-items-end" method="get" action="student_card.php">
      <div class="col-md-9">
        <label class="form-label fs-5 mb-2">Kërko person / student</label>
        <div class="input-group input-group-lg">
          <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
          <input type="text" name="q" class="form-control border-0" placeholder="Emër, AMZË, ID personale, ID studenti ose email"
                 value="<?= h($q) ?>">
        </div>
        <div class="form-text">Rezultatet kthehen sipas personit (të dhënat e kombinuara të regjistrimeve).</div>
      </div>
      <div class="col-md-3 text-end">
        <button class="btn btn-outline-secondary btn-lg me-2" type="button" onclick="window.location='student_card.php'">
          <i class="bi bi-x-circle me-1"></i>Pastro
        </button>
        <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-funnel me-1"></i>Kërko</button>
      </div>
    </form>
  </div>

  <?php if ($pid<=0 && $q!==''): ?>
    <!-- Rezultatet -->
    <div class="card mb-4">
      <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <h5 class="mb-0 section-title"><i class="bi bi-people"></i>Rezultatet e kërkimit</h5>
        <span class="text-muted small"><?= count($results) ?> person(a)</span>
      </div>
      <div class="card-body">
        <?php if ($results): ?>
          <div class="table-responsive mini-table">
            <table class="table align-middle">
              <thead class="table-light"><tr>
                <th>Personi</th><th class="nowrap">ID personale</th><th class="text-end">Hap</th>
              </tr></thead>
              <tbody>
              <?php foreach ($results as $r):
                $full = trim(($r['first_name']??'').' '.(($r['father_name']??'')?($r['father_name'].' '):'').($r['last_name']??'')); ?>
                <tr>
                  <td>
                    <div class="d-flex align-items-center">
                      <div class="avatar me-3"><?= strtoupper(substr($r['first_name']??'?',0,1).substr($r['last_name']??'?',0,1)) ?></div>
                      <div>
                        <div class="fw-semibold"><?= h($full ?: '—') ?></div>
                        <div class="text-muted small">Person ID: #<?= (int)$r['person_id'] ?></div>
                      </div>
                    </div>
                  </td>
                  <td class="nowrap"><?= h($r['personal_number'] ?? '—') ?></td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-primary" href="student_card.php?pid=<?= (int)$r['person_id'] ?>">
                      <i class="bi bi-box-arrow-in-right me-1"></i>Hap
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-muted">Asgjë nuk u gjet me këtë kriter.</div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($person): ?>
    <?php
      $full = trim(($person['first_name']??'').' '.(($person['father_name']??'')?($person['father_name'].' '):'').($person['last_name']??''));
      $canInline = ($EDIT_MODE && $CAN_EDIT);
      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https://' : 'http://';
      $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
      $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
      $verifyURL = $personQR['token'] ? ($scheme.$host.$base.'/verify.php?pid='.$pid.'&t='.$personQR['token']) : null;
    ?>

    <!-- Header i personit -->
    <section class="card p-3 p-md-4 mb-4">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
          <div class="avatar"><?= strtoupper(substr($person['first_name']??'?',0,1).substr($person['last_name']??'?',0,1)) ?></div>
          <div>
            <div class="h3 mb-1">
              <span class="editable"
                    contenteditable="<?= $canInline ? 'true' : 'false' ?>"
                    data-type="person" data-id="<?= (int)$pid ?>" data-field="first_name"
                    title="Emri"><?= h($person['first_name'] ?? '—') ?></span>
              <span class="editable"
                    contenteditable="<?= $canInline ? 'true' : 'false' ?>"
                    data-type="person" data-id="<?= (int)$pid ?>" data-field="father_name"
                    title="Atësia"><?= h($person['father_name'] ?? '') ?></span>
              <span class="editable"
                    contenteditable="<?= $canInline ? 'true' : 'false' ?>"
                    data-type="person" data-id="<?= (int)$pid ?>" data-field="last_name"
                    title="Mbiemri"><?= h($person['last_name'] ?? '—') ?></span>
            </div>
            <div class="text-muted small">Person ID: <strong>#<?= (int)$pid ?></strong></div>
          </div>
        </div>

        <!-- QR per-person -->
        <div class="d-flex align-items-center gap-2">
          <?php if (!empty($personQR['token'])): ?>
            <span class="badge text-bg-success"><i class="bi bi-qr-code me-1"></i>QR ekziston</span>
            <?php if ($verifyURL): ?>
              <a class="btn btn-outline-primary btn-sm" target="_blank" href="<?= h($verifyURL) ?>">
                <i class="bi bi-box-arrow-up-right me-1"></i>Verifiko
              </a>
            <?php endif; ?>
            <button class="btn btn-soft btn-sm" id="btnDlQrPerson">
              <i class="bi bi-download me-1"></i>Shkarko PNG
            </button>
          <?php else: ?>
            <?php if ($CAN_EDIT): ?>
              <button class="btn btn-primary btn-sm" id="btnGenQrPerson">
                <i class="bi bi-magic me-1"></i>Gjenero QR
              </button>
            <?php else: ?>
              <span class="badge text-bg-secondary">QR —</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <hr class="my-3">

      <!-- Info të shpejta -->
      <div class="row g-3">
        <div class="col-12 col-md-4">
          <div class="soft p-3 rounded-3 h-100">
            <div class="text-muted small mb-1">ID personale</div>
            <div class="fw-semibold editable"
                 contenteditable="<?= $canInline ? 'true':'false' ?>"
                 data-type="person" data-id="<?= (int)$pid ?>" data-field="personal_number"
                 title="ID personale"><?= h($person['personal_number'] ?? '—') ?></div>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <div class="soft p-3 rounded-3 h-100">
            <div class="text-muted small mb-1">Datëlindja</div>
            <input type="text"
                   class="form-control form-control-sm dmy-input"
                   data-type="person" data-id="<?= (int)$pid ?>" data-field="birth_date"
                   placeholder="DD-MM-YYYY" pattern="^\d{2}-\d{2}-\d{4}$"
                   value="<?= h(fmt_dMY($person['birth_date'] ?? null)) ?>"
                   <?= $canInline ? '' : 'disabled' ?>>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <div class="soft p-3 rounded-3 h-100">
            <div class="text-muted small mb-1">Gjinia</div>
            <select class="form-select form-select-sm inline-select"
                    data-type="person" data-id="<?= (int)$pid ?>" data-field="gender_id"
                    <?= $canInline ? '' : 'disabled' ?>>
              <option value="">— Zgjidh —</option>
              <?php foreach ($genders as $g): ?>
                <option value="<?= (int)$g['id'] ?>" <?= ((int)($person['gender_id'] ?? 0)===(int)$g['id'])?'selected':'' ?>>
                  <?= h($g['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="col-12 col-md-6">
          <div class="soft p-3 rounded-3 h-100">
            <div class="text-muted small mb-1">Vendlindja</div>
            <div class="fw-semibold editable"
                 contenteditable="<?= $canInline ? 'true':'false' ?>"
                 data-type="person" data-id="<?= (int)$pid ?>" data-field="birth_place"
                 title="Vendlindja"><?= h($person['birth_place'] ?? '—') ?></div>
          </div>
        </div>
        <div class="col-12 col-md-6">
          <div class="soft p-3 rounded-3 h-100">
            <div class="text-muted small mb-1">Telefoni</div>
            <div class="fw-semibold editable"
                 contenteditable="<?= $canInline ? 'true':'false' ?>"
                 data-type="person" data-id="<?= (int)$pid ?>" data-field="phone"
                 title="Telefon"><?= h($person['phone'] ?? '—') ?></div>
          </div>
        </div>
      </div>

      <!-- QR i personit – preview -->
      <hr class="my-3">
      <div class="row g-3">
        <div class="col-12 col-md-4">
          <div class="soft p-3 rounded-3 h-100 text-center">
            <div class="text-muted small mb-2">QR i personit</div>
            <div id="qrBox" class="d-flex justify-content-center"></div>
            <div class="mt-2">
              <button class="btn btn-sm btn-soft me-1" id="btnPreviewQr"><i class="bi bi-eye me-1"></i>Shfaq</button>
              <button class="btn btn-sm btn-outline-primary" id="btnDlQrPerson2"><i class="bi bi-download me-1"></i>Shkarko</button>
            </div>
            <div class="small text-muted mt-2">
              Payload: <code id="qrPayloadText">—</code>
              <button class="btn btn-link btn-sm p-0 ms-1" id="btnCopyPayload" title="Kopjo"><i class="bi bi-clipboard"></i></button>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-8">
          <div class="soft p-3 rounded-3 h-100">
            <div class="d-flex align-items-center justify-content-between">
              <div class="text-muted small">Përmbledhje</div>
              <?php if (($stats['pass_rate']??null)!==null): ?>
                <span class="pill">Kalueshmëri: <?= (float)$stats['pass_rate'] ?>%</span>
              <?php endif; ?>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-6 col-lg-3">
                <div class="stat">
                  <i class="bi bi-journal-text text-primary"></i>
                  <div><div class="label">Modulet</div><div class="value"><?= number_format($stats['courses'] ?? 0) ?></div></div>
                </div>
              </div>
              <div class="col-6 col-lg-3">
                <div class="stat">
                  <i class="bi bi-collection text-success"></i>
                  <div><div class="label">Grupe</div><div class="value"><?= number_format($stats['groups'] ?? 0) ?></div></div>
                </div>
              </div>
              <div class="col-6 col-lg-3">
                <div class="stat">
                  <i class="bi bi-bar-chart-line text-danger"></i>
                  <div><div class="label">Mes. Pikë</div><div class="value"><?= $stats['avg_score']!==null ? $stats['avg_score'] : '—' ?></div></div>
                </div>
              </div>
              <div class="col-6 col-lg-3">
                <div class="stat">
                  <i class="bi bi-clock-history text-primary"></i>
                  <div><div class="label">Orë studimi</div><div class="value"><?= number_format($stats['hours'] ?? 0) ?></div></div>
                </div>
              </div>
            </div>
            <?php if (($stats['best']??null)!==null || ($stats['last']??null)!==null): ?>
              <div class="text-muted small mt-2">
                <span class="me-3">Më e mira: <strong><?= h((string)$stats['best']) ?></strong></span>
                <span>E fundit: <strong><?= h((string)$stats['last']) ?></strong></span>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <!-- Grafik + Provime -->
    <section class="row g-3 mb-4">
      <div class="col-12 col-lg-7">
        <div class="card h-100">
          <div class="card-header bg-white section-title"><i class="bi bi-graph-up-arrow"></i><span>Ecuria e pikëve</span></div>
          <div class="card-body"><canvas id="chartScores" height="120"></canvas></div>
        </div>
      </div>
      <div class="col-12 col-lg-5">
        <div class="card h-100">
          <div class="card-header bg-white d-flex align-items-center justify-content-between">
            <h6 class="mb-0 section-title"><i class="bi bi-calendar2-event"></i>Provimet e afërta</h6>
            <span class="text-muted small">30 ditët në vijim</span>
          </div>
          <div class="card-body">
            <?php if ($upcoming): ?>
              <ul class="list-group list-group-flush">
                <?php foreach ($upcoming as $e): ?>
                  <li class="list-group-item d-flex justify-content-between align-items-start">
                    <div>
                      <div class="fw-semibold"><?= h(($e['code'] ?? '').' · '.($e['name'] ?? '')) ?></div>
                      <div class="small text-muted">Data: <?= h(fmt_dMY($e['exam_date'] ?? null)) ?></div>
                    </div>
                    <span class="badge rounded-pill text-bg-primary">Test</span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div class="text-muted">Asnjë provim i afërt.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <!-- Aktiviteti mësimor (pa kolonë AMZË, pa veprime) -->
    <section class="card mb-4">
      <div class="card-header bg-white section-title">
        <i class="bi bi-collection"></i><span>Modulet & grupet</span>
      </div>
      <div class="card-body">
        <?php if (empty($groups) && !empty($planned)): ?>
          <div class="alert alert-info py-2 mb-3">
            Ky person ende <strong>nuk ka marrë pjesë në asnjë grup</strong>, por ka module të planifikuara më poshtë.
          </div>
        <?php endif; ?>

        <div class="table-responsive mini-table">
          <table class="table align-middle">
            <thead class="table-light">
            <tr>
              <th>#Grupi</th>
              <th>Moduli</th>
              <th class="nowrap">Datat</th>
              <th class="nowrap">Testi</th>
              <th class="nowrap">Pikët</th>
            </tr>
            </thead>
            <tbody>
            <?php $hasRows=false; ?>

            <?php if (!empty($planned)): $hasRows=true; foreach($planned as $pl): ?>
              <tr>
                <td class="text-muted">—</td>
                <td>
                  <?= h(($pl['code'] ?? '').' · '.($pl['name'] ?? '')) ?>
                  <span class="badge bg-warning-subtle text-warning-emphasis ms-1">Planuar</span>
                </td>
                <td class="nowrap">—</td>
                <td class="nowrap">—</td>
                <td class="nowrap">—</td>
              </tr>
            <?php endforeach; endif; ?>

            <?php if (!empty($groups)): $hasRows=true; foreach($groups as $g): ?>
              <tr>
                <td>#<?= (int)$g['group_id'] ?></td>
                <td><?= h(($g['code'] ?? '').' · '.($g['name'] ?? '')) ?></td>
                <td class="nowrap"><?= h(fmt_dMY($g['start_date'])) ?> – <?= h(fmt_dMY($g['end_date'])) ?></td>
                <td class="nowrap"><?= h(fmt_dMY($g['exam_date'] ?? null)) ?></td>
                <td class="nowrap"><?= $g['final_score']!==null ? h((string)$g['final_score']) : '—' ?></td>
              </tr>
            <?php endforeach; endif; ?>

            <?php if (!$hasRows): ?>
              <tr><td colspan="5" class="text-center text-muted">Nuk ka ende të dhëna për module/grupe.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <div class="text-center text-muted small my-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<!-- Floating action buttons -->
<div class="fab-stack" role="group" aria-label="Veprime shpejta">
  <?php if ($CAN_EDIT): ?>
    <a class="fab-btn btn <?= $EDIT_MODE ? 'btn-success' : 'btn-secondary' ?>"
       href="<?= h($toggleUrl) ?>"
       title="Ndrysho gjendjen e Edit Mode">
      <i class="bi <?= $EDIT_MODE ? 'bi-unlock' : 'bi-lock' ?>"></i>
      <span class="fab-text">Edit Mode: <?= $EDIT_MODE ? 'ON' : 'OFF' ?></span>
    </a>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const INLINE = 'student_card_inline.php';
const CAN_EDIT = <?= $CAN_EDIT ? 'true' : 'false' ?>;
const EDIT_MODE = <?= $EDIT_MODE ? 'true' : 'false' ?>;
const PERSON_ID = <?= (int)$pid ?>;

// Token & payload i personit (nga PHP)
let PERSON_QR_TOKEN = <?= json_encode($personQR['token'] ?? '') ?>;
let PERSON_QR_PAYLOAD = PERSON_QR_TOKEN ? ('QTA|PID:' + PERSON_ID + '|TOKEN:' + PERSON_QR_TOKEN) : '';

function clean(s){ return (s||'').replace(/\s+/g,' ').trim(); }

async function postJSON(url, payload){
  const r = await fetch(url, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });
  const data = await r.json().catch(()=> ({}));
  if (!r.ok || data.ok===false) throw new Error(data.error || ('HTTP '+r.status));
  return data;
}

/* Toast helper */
function notify(type, text, opts={}){
  const zone = document.getElementById('toastZone');
  const id = 't' + Date.now() + Math.random().toString(16).slice(2);
  const icons = { success:'check-circle', danger:'exclamation-triangle', warning:'exclamation-circle', info:'info-circle' };
  const icon = icons[type] || 'bell';
  const title = opts.title ?? (
    type==='success' ? 'Sukses' :
    type==='danger'  ? 'Gabim'  :
    type==='warning' ? 'Kujdes' : 'Njoftim'
  );
  const autohide = opts.autohide ?? true;
  const delay = opts.delay ?? 4500;
  const html = `
    <div id="${id}" class="toast qta-toast toast-${type}" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="toast-header">
        <i class="bi bi-${icon} me-2"></i>
        <strong class="me-auto">${title}</strong>
        <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Mbyll"></button>
      </div>
      <div class="toast-body">${text}</div>
    </div>`;
  zone.insertAdjacentHTML('beforeend', html);
  const el = document.getElementById(id);
  const t = new bootstrap.Toast(el, { autohide, delay });
  el.addEventListener('hidden.bs.toast', ()=> el.remove());
  t.show();
}

/* QR helpers */
function renderQrInto(el, text, size=220){
  el.innerHTML = '';
  if (!text){ el.innerHTML = '<div class="text-muted small">S’ka QR ende.</div>'; return; }
  new QRCode(el, { text, width:size, height:size, correctLevel: QRCode.CorrectLevel.M });
}

function renderQrToDataURL(text, size=240){
  return new Promise((resolve, reject)=>{
    try{
      const holder = document.createElement('div');
      holder.style.position='absolute';
      holder.style.left='-10000px';
      holder.style.top='-10000px';
      document.body.appendChild(holder);

      new QRCode(holder, { text, width:size, height:size, correctLevel: QRCode.CorrectLevel.M });

      setTimeout(()=>{
        const img = holder.querySelector('img');
        const canvas = holder.querySelector('canvas');
        let url = '';
        if (img && img.complete) url = img.src;
        else if (canvas) url = canvas.toDataURL('image/png');
        document.body.removeChild(holder);
        if (url) resolve(url); else reject(new Error('S’u renderua QR.'));
      }, 0);
    }catch(e){ reject(e); }
  });
}

function refreshQrUI(){
  const box = document.getElementById('qrBox');
  const payloadEl = document.getElementById('qrPayloadText');
  renderQrInto(box, PERSON_QR_PAYLOAD);
  if (payloadEl) payloadEl.textContent = PERSON_QR_PAYLOAD || '—';
}

/* Inline edit: blur or Enter (span.editable) */
document.querySelectorAll('.editable[contenteditable="true"]').forEach(el=>{
  el.addEventListener('keydown', e=>{ if (e.key==='Enter'){ e.preventDefault(); el.blur(); } });
  el.addEventListener('blur', async ()=>{
    if (!CAN_EDIT || !EDIT_MODE) return;
    const type  = el.dataset.type;
    const id    = parseInt(el.dataset.id||'0',10);
    const field = el.dataset.field;
    const value = clean(el.innerText);
    if (!type || !id || !field) return;
    el.classList.remove('cell-ok','cell-err');
    try{
      if (type==='person'){
        const res = await postJSON(INLINE, {csrf:CSRF, action:'set_person_field', person_id:id, field, value});
        el.innerText = res.display ?? value;
      }
      el.classList.add('cell-ok');
      notify('success','U ruajt.');
    } catch(e){
      el.classList.add('cell-err');
      notify('danger', e.message);
    }
  });
});

/* Selects (gender_id) */
document.querySelectorAll('.inline-select').forEach(sel=>{
  sel.addEventListener('change', async ()=>{
    if (!CAN_EDIT || !EDIT_MODE) return;
    const type  = sel.dataset.type;   // person
    const id    = parseInt(sel.dataset.id||'0',10);
    const field = sel.dataset.field;  // gender_id
    const value = sel.value;
    try{
      await postJSON(INLINE, {csrf:CSRF, action:'set_person_field', person_id:id, field, value});
      sel.classList.remove('is-invalid'); sel.classList.add('is-valid');
      setTimeout(()=> sel.classList.remove('is-valid'), 900);
      notify('success','U ruajt.');
    }catch(e){
      sel.classList.add('is-invalid');
      notify('danger', e.message);
    }
  });
});

/* Datëlindja input (DD-MM-YYYY) */
function isDmy(s){ return /^\d{2}-\d{2}-\d{4}$/.test((s||'').trim()); }
document.querySelectorAll('.dmy-input').forEach(inp=>{
  inp.addEventListener('keydown', (e)=>{ if (e.key==='Enter'){ e.preventDefault(); inp.blur(); } });
  inp.addEventListener('blur', async ()=>{
    if (!CAN_EDIT || !EDIT_MODE) return;
    const type  = inp.dataset.type;       // person
    const id    = parseInt(inp.dataset.id||'0',10);
    const field = inp.dataset.field;      // birth_date
    const value = inp.value.trim();
    if (value!=='' && !isDmy(value)){
      inp.classList.add('is-invalid'); notify('danger','Formati i datës duhet të jetë DD-MM-YYYY.'); return;
    }
    try{
      await postJSON(INLINE, {csrf:CSRF, action:'set_person_field', person_id:id, field, value});
      inp.classList.remove('is-invalid'); inp.classList.add('is-valid');
      setTimeout(()=> inp.classList.remove('is-valid'), 900);
      notify('success','U ruajt.');
    }catch(e){
      inp.classList.add('is-invalid');
      notify('danger', e.message);
    }
  });
});

/* Gjenero QR për PERSON */
const btnGenQrPerson = document.getElementById('btnGenQrPerson');
if (btnGenQrPerson){
  btnGenQrPerson.addEventListener('click', async ()=>{
    try{
      const res = await postJSON(INLINE, {csrf:CSRF, action:'generate_qr_person', person_id:PERSON_ID});
      PERSON_QR_TOKEN   = res.token;
      PERSON_QR_PAYLOAD = 'QTA|PID:' + PERSON_ID + '|TOKEN:' + PERSON_QR_TOKEN;
      refreshQrUI();
      notify('success','Kodi QR u gjenerua.');
    } catch(err){ notify('danger', err.message); }
  });
}

/* Shkarko QR i personit */
const btnDlQrPerson = document.getElementById('btnDlQrPerson');
if (btnDlQrPerson){
  btnDlQrPerson.addEventListener('click', async ()=>{
    try{
      if (!PERSON_QR_PAYLOAD){
        const res = await postJSON(INLINE, {csrf:CSRF, action:'qr_payload', person_id:PERSON_ID});
        PERSON_QR_TOKEN   = res.token || PERSON_QR_TOKEN;
        PERSON_QR_PAYLOAD = res.payload;
        refreshQrUI();
      }
      const dataURL = await renderQrToDataURL(PERSON_QR_PAYLOAD, 240);
      const a = document.createElement('a');
      a.href = dataURL; a.download = 'person_qr_'+PERSON_ID+'.png'; a.click();
    } catch(err){ notify('danger', err.message); }
  });
}

/* Shkarko (buton në box) */
document.getElementById('btnDlQrPerson2')?.addEventListener('click', ()=>{
  document.getElementById('btnDlQrPerson')?.click();
});

/* Shfaq (re-render) QR në box */
document.getElementById('btnPreviewQr')?.addEventListener('click', ()=>{
  refreshQrUI();
});

/* Copy payload */
document.getElementById('btnCopyPayload')?.addEventListener('click', async ()=>{
  try{
    await navigator.clipboard.writeText(PERSON_QR_PAYLOAD || '');
    notify('success','Payload u kopjua.');
  }catch{ notify('danger','S’u kopjua.'); }
});

/* Render inicial i QR */
if (PERSON_QR_PAYLOAD) refreshQrUI();

/* Grafik */
<?php if ($person): ?>
(()=>{
  const labels = <?= json_encode(array_column($series,'d')) ?>;
  const data   = <?= json_encode(array_map(fn($r)=> $r['s']!==null?(float)$r['s']:null, $series)) ?>;
  const ctx = document.getElementById('chartScores');
  if (!ctx) return;
  new Chart(ctx, {
    type:'line',
    data:{
      labels,
      datasets:[{ label:'Pikë', data, tension:.35, fill:true, borderWidth:2 }]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ display:false } },
      scales:{ x:{ grid:{display:false} }, y:{ beginAtZero:true, suggestedMax:100 } }
    }
  });
})();
<?php endif; ?>
</script>
</body>
</html>
