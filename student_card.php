<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* -------------------------------------------------
   Guard: vetëm admin, editor ose agjenci (jo studentë)
-------------------------------------------------- */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }

$usr = $pdo->prepare("
  SELECT u.id, u.role_id, u.full_name, u.email, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = :uid
  LIMIT 1
");
$usr->execute([':uid'=>$_SESSION['user_id']]);
$currentUser = $usr->fetch(PDO::FETCH_ASSOC);
if (!$currentUser) { header('Location: selectProfile.php'); exit; }

$ROLE = (string)$currentUser['role_name'];
if (!in_array($ROLE, ['administrator','editor','agjencia'], true)) {
  http_response_code(403); exit('Akses i ndaluar.');
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
   POST: gjenero_token (vetëm një herë)
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $token = $_POST['csrf'] ?? '';
  if (empty($token) || !hash_equals($CSRF, $token)) { http_response_code(400); exit('CSRF token mismatch.'); }

  $action = $_POST['action'] ?? '';
  try{
    if ($action === 'generate_qr') {
      $sid = (int)($_POST['student_id'] ?? 0);
      if ($sid<=0) throw new RuntimeException('Student i pavlefshëm.');

      // Nëse agjenci, verifiko që studenti i përket kësaj agjencie
      if ($ROLE === 'agjencia') {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM agency_students WHERE agency_id=:aid AND student_id=:sid");
        $chk->execute([':aid'=>$MY_AGENCY_ID, ':sid'=>$sid]);
        if ((int)$chk->fetchColumn()===0) throw new RuntimeException('Nuk lejohet: studenti nuk i përket agjencisë suaj.');
      }

      // Eksiston tashmë?
      $ex = $pdo->prepare("SELECT token FROM student_qr_tokens WHERE student_id=:sid");
      $ex->execute([':sid'=>$sid]);
      $exists = $ex->fetchColumn();
      if ($exists) { flash('ok','Ky student e ka tashmë kodin QR.'); header("Location: student_card.php?sid=".$sid); exit; }

      // Gjenero token të përhershëm
      $newToken = bin2hex(random_bytes(16)); // 32 hex
      $ins = $pdo->prepare("INSERT INTO student_qr_tokens(student_id, token, created_at) VALUES(:sid, :t, NOW())");
      $ins->execute([':sid'=>$sid, ':t'=>$newToken]);

      flash('ok','Kodi QR u gjenerua njëherë dhe u ruajt.');
      header("Location: student_card.php?sid=".$sid); exit;
    }

    throw new RuntimeException('Veprim i panjohur.');
  } catch(Throwable $e){
    flash('err',$e->getMessage());
    header('Location: student_card.php'); exit;
  }
}

/* -------------------------------------------------
   Kërkim & zgjedhje studenti
-------------------------------------------------- */
$q = trim($_GET['q'] ?? '');
$sid = (int)($_GET['sid'] ?? 0);
$students = [];
$selected = null;

if ($sid > 0) {
  // Merr studentin sipas ID (me kontroll access)
  $sql = "
    SELECT
      s.id, s.nr_amze, s.education_level_id, s.created_at,
      u.created_at AS user_created_at,
      p.first_name, p.father_name, p.last_name, p.personal_number,
      el.label AS edu_label,
      ajs.agency_id, ag.company_name AS agency_name, ajs.assigned_at
    FROM students s
    JOIN users u    ON u.id = s.user_id
    JOIN persons p  ON p.id = s.person_id
    LEFT JOIN education_levels el ON el.id = s.education_level_id
    LEFT JOIN agency_students ajs  ON ajs.student_id = s.id
    LEFT JOIN agencies ag          ON ag.id = ajs.agency_id
    WHERE s.id = :sid
  ";
  if ($ROLE==='agjencia') { $sql .= " AND ajs.agency_id = :aid"; }
  $st = $pdo->prepare($sql);
  $params = [':sid'=>$sid];
  if ($ROLE==='agjencia') $params[':aid']=$MY_AGENCY_ID;
  $st->execute($params);
  $selected = $st->fetch(PDO::FETCH_ASSOC);
  if (!$selected) { flash('err','Studenti nuk u gjet ose nuk keni akses.'); }
}
elseif ($q !== '') {
  // Kërko sipas emrit / amzë / id personale / id studenti / email
  $sql = "
    SELECT
      s.id,
      s.nr_amze,
      p.first_name, p.father_name, p.last_name,
      p.personal_number,
      u.email,
      ajs.agency_id,
      ag.company_name AS agency_name
    FROM students s
    JOIN users u   ON u.id = s.user_id
    JOIN persons p ON p.id = s.person_id
    LEFT JOIN agency_students ajs ON ajs.student_id = s.id
    LEFT JOIN agencies ag         ON ag.id = ajs.agency_id
    WHERE (
           s.id = :idExact
        OR s.nr_amze         LIKE :like1
        OR p.personal_number LIKE :like2
        OR p.first_name      LIKE :like3
        OR p.last_name       LIKE :like4
        OR u.email           LIKE :like5
    )
  ";

  $params = [
    ':idExact' => ctype_digit($q) ? (int)$q : -1,
    ':like1'   => '%'.$q.'%',
    ':like2'   => '%'.$q.'%',
    ':like3'   => '%'.$q.'%',
    ':like4'   => '%'.$q.'%',
    ':like5'   => '%'.$q.'%',
  ];

  if ($ROLE === 'agjencia') {
    $sql .= " AND ajs.agency_id = :aid";
    $params[':aid'] = $MY_AGENCY_ID;
  }

  $sql .= " ORDER BY p.last_name, p.first_name LIMIT 25";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $students = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* -------------------------------------------------
   Nëse kemi një student të zgjedhur: llogarit statistikat
-------------------------------------------------- */
$stats = $groups = $upcoming = $scores = []; $qrToken = null; $qrCreatedAt = null; $verifyURL = null;
if ($selected) {
  $SID = (int)$selected['id'];

  // QR token
  $t = $pdo->prepare("SELECT token, created_at FROM student_qr_tokens WHERE student_id=:sid");
  $t->execute([':sid'=>$SID]);
  $qrRow = $t->fetch(PDO::FETCH_ASSOC);
  $qrToken = $qrRow['token'] ?? null;
  $qrCreatedAt = $qrRow['created_at'] ?? null;
  if ($qrToken) {
    // Link publik verifikimi
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $verifyURL = $scheme.$host.$base.'/verify.php?sid='.$SID.'&t='.$qrToken;
  }

  // # grupe
  $st = $pdo->prepare("SELECT COUNT(DISTINCT group_id) FROM course_group_students WHERE student_id=:sid");
  $st->execute([':sid'=>$SID]); $stats['groups'] = (int)$st->fetchColumn();

  // # module + orë totale
  $st = $pdo->prepare("
    SELECT COUNT(DISTINCT cg.course_id) AS courses_cnt, COALESCE(SUM(c.hours),0) AS total_hours
    FROM course_group_students cgs
    JOIN course_groups cg ON cg.id = cgs.group_id
    JOIN courses c ON c.id = cg.course_id
    WHERE cgs.student_id=:sid
  ");
  $st->execute([':sid'=>$SID]); $row = $st->fetch(PDO::FETCH_ASSOC);
  $stats['courses'] = (int)($row['courses_cnt'] ?? 0);
  $stats['hours']   = (int)($row['total_hours'] ?? 0);

  // Nota mesatare / kalueshmëria / nota më e lartë / e fundit
  $st = $pdo->prepare("
    SELECT
      AVG(final_score) AS avg_score,
      SUM(CASE WHEN final_score>=50 THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0) * 100 AS pass_rate,
      MAX(final_score) AS best_score
    FROM course_group_students
    WHERE student_id=:sid AND final_score IS NOT NULL
  ");
  $st->execute([':sid'=>$SID]); $row = $st->fetch(PDO::FETCH_ASSOC);
  $stats['avg_score'] = $row['avg_score']!==null ? round((float)$row['avg_score'],2) : null;
  $stats['pass_rate'] = $row['pass_rate']!==null ? round((float)$row['pass_rate'],1) : null;
  $stats['best']      = $row['best_score']!==null ? round((float)$row['best_score'],1) : null;

  // Nota më e fundit
  $st = $pdo->prepare("
    SELECT cgs.final_score
    FROM course_group_students cgs
    JOIN course_groups cg ON cg.id = cgs.group_id
    WHERE cgs.student_id=:sid AND cgs.final_score IS NOT NULL
    ORDER BY COALESCE(cgs.exam_date, cg.end_date) DESC, cgs.group_id DESC
    LIMIT 1
  ");
  $st->execute([':sid'=>$SID]); $stats['last'] = $st->fetchColumn();

  // Listë grupe (5 të fundit)
  $st = $pdo->prepare("
    SELECT cg.id AS group_id, c.code, c.name, cg.start_date, cg.end_date, cgs.exam_date, cgs.final_score
    FROM course_group_students cgs
    JOIN course_groups cg ON cg.id = cgs.group_id
    JOIN courses c ON c.id = cg.course_id
    WHERE cgs.student_id=:sid
    ORDER BY cg.start_date DESC, cg.id DESC
    LIMIT 5
  ");
  $st->execute([':sid'=>$SID]); $groups = $st->fetchAll(PDO::FETCH_ASSOC);

  // Provime të afërta (30 ditë)
  $st = $pdo->prepare("
    SELECT DISTINCT c.code, c.name, cgs.exam_date
    FROM course_group_students cgs
    JOIN course_groups cg ON cg.id = cgs.group_id
    JOIN courses c ON c.id = cg.course_id
    WHERE cgs.student_id=:sid AND cgs.exam_date IS NOT NULL AND cgs.exam_date >= CURDATE()
    ORDER BY cgs.exam_date ASC
    LIMIT 6
  ");
  $st->execute([':sid'=>$SID]); $upcoming = $st->fetchAll(PDO::FETCH_ASSOC);

  // Sery notash për grafik
  $st = $pdo->prepare("
    SELECT DATE_FORMAT(COALESCE(cgs.exam_date, cg.end_date), '%Y-%m-%d') AS d, cgs.final_score AS s
    FROM course_group_students cgs
    JOIN course_groups cg ON cg.id = cgs.group_id
    WHERE cgs.student_id=:sid AND cgs.final_score IS NOT NULL
    ORDER BY COALESCE(cgs.exam_date, cg.end_date) ASC, cgs.group_id ASC
    LIMIT 50
  ");
  $st->execute([':sid'=>$SID]);
  $scores = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Kartela e studentit – QTA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <style>
    :root{
      --g1:#0ea5e9; --g2:#2563eb; --g3:#4f46e5;
      --glass: rgba(255,255,255,.78);
      --glass-b: rgba(255,255,255,.55);
      --shadow: 0 18px 40px rgba(2,6,23,.12);
    }
    body { background:#f5f7fb; padding-top:72px; }
    .navbar-brand img { height:28px; }
    .card { border:none; border-radius:1rem; box-shadow:var(--shadow); }
    .hero {
      background:
        radial-gradient(1200px 420px at 10% -20%, rgba(37,99,235,.25), rgba(37,99,235,0) 60%),
        radial-gradient(900px 320px at 90% -10%, rgba(99,102,241,.22), rgba(99,102,241,0) 55%),
        linear-gradient(135deg, var(--g1) 0%, var(--g2) 55%, var(--g3) 100%);
      color:#fff; border-radius:1.25rem; overflow:hidden;
    }
    .chip { background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.35); }
    .mini-table thead { background:#f1f5f9; }
    .nowrap{ white-space:nowrap; }
    .kpi .icon { width:46px; height:46px; border-radius:.75rem; display:flex; align-items:center; justify-content:center; background:#eef2ff; }
    .glass {
      background:var(--glass); border:1px solid var(--glass-b); backdrop-filter: blur(10px);
    }
    .avatar{
      width:58px;height:58px;border-radius:1rem;background:#eef2ff;display:flex;align-items:center;justify-content:center;
      font-weight:700;color:#4338ca; letter-spacing:.5px;
    }
    .btn-soft { background:#f8fafc; border:1px solid #e5e7eb; }
  </style>
</head>
<body>

<?php
if     ($ROLE === 'administrator') require __DIR__.'/inc/navbar.php';
elseif ($ROLE === 'editor')       require __DIR__.'/inc/navbar4.php';
elseif ($ROLE === 'agjencia')     require __DIR__.'/inc/navbar2.php';
?>

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

  <!-- FORM KËRKIMI -->
  <div class="card glass mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get" action="student_card.php">
        <div class="col-md-9">
          <h2 class="form-label">Kërko studentin</h2>
          <div class="input-group">
            <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
            <input type="text" name="q" class="form-control border-0" placeholder="p.sh. Ana, 3401, ID, ose email" value="<?= h($q) ?>">
          </div>
          <div class="form-text">Shkruaj çfarëdolloj detaji – sistemi filtron automatikisht.</div>
        </div>
        <div class="col-md-3 text-end">
          <button class="btn btn-outline-secondary me-1" type="button" onclick="window.location='student_card.php'">
            <i class="bi bi-x-circle me-1"></i>Pastro
          </button>
          <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Kërko</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($q!=='' && !$selected): ?>
    <!-- Rezultatet -->
    <div class="card mb-4">
      <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <h5 class="mb-0"><i class="bi bi-people me-2"></i>Rezultatet e kërkimit</h5>
        <span class="text-muted small"><?= count($students) ?> rezultat(e)</span>
      </div>
      <div class="card-body">
        <?php if ($students): ?>
          <div class="table-responsive mini-table">
            <table class="table align-middle">
              <thead class="table-light">
                <tr>
                  <th>ID</th>
                  <th>Emri & Mbiemri</th>
                  <th class="nowrap">AMZË</th>
                  <th class="nowrap">ID personale</th>
                  <th>Agjencia</th>
                  <th class="text-end">Hap</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($students as $s):
                  $full = trim(($s['first_name']??'').' '.(($s['father_name']??'')?($s['father_name'].' '):'').($s['last_name']??'')); ?>
                  <tr>
                    <td class="text-muted">#<?= (int)$s['id'] ?></td>
                    <td>
                      <div class="d-flex align-items-center">
                        <div class="avatar me-2"><?= strtoupper(substr($s['first_name']??'?',0,1).substr($s['last_name']??'?',0,1)) ?></div>
                        <div>
                          <div class="fw-semibold"><?= h($full ?: '—') ?></div>
                          <div class="small text-muted"><?= h($s['email'] ?? '') ?></div>
                        </div>
                      </div>
                    </td>
                    <td class="nowrap"><?= h($s['nr_amze'] ?? '—') ?></td>
                    <td class="nowrap"><?= h($s['personal_number'] ?? '—') ?></td>
                    <td><?= h($s['agency_name'] ?? '—') ?></td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-primary" href="student_card.php?sid=<?= (int)$s['id'] ?>">
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

  <?php if ($selected):
    $full = trim(($selected['first_name']??'').' '.(($selected['father_name']??'')?($selected['father_name'].' '):'').($selected['last_name']??'')); ?>

    <!-- LAYOUT: Sidebar left (Profile & QR) + right (Analytics) -->
    <section class="row g-4">
      <!-- Left: Profile & QR -->
      <div class="col-12 col-xl-4">
        <!-- Profile -->
        <div class="card mb-4">
          <div class="card-body">
            <div class="d-flex align-items-center mb-2">
              <div class="avatar me-3"><?= strtoupper(substr($selected['first_name']??'?',0,1).substr($selected['last_name']??'?',0,1)) ?></div>
              <div>
                <div class="h4 mb-1"><?= h($full ?: '—') ?></div>
                <div class="small text-muted">
                  AMZË: <strong><?= h($selected['nr_amze'] ?? '—') ?></strong>
                  <span class="mx-2">•</span>
                  ID: <strong>#<?= (int)$selected['id'] ?></strong>
                </div>
              </div>
            </div>
            <hr>
            <div class="row g-3">
              <div class="col-6">
                <div class="p-3 rounded" style="background:#f8fafc;">
                  <div class="small text-muted">Edukimi</div>
                  <div class="fw-semibold mb-0"><?= h($selected['edu_label'] ?? '—') ?></div>
                </div>
              </div>
              <div class="col-6">
                <div class="p-3 rounded" style="background:#f8fafc;">
                  <div class="small text-muted">Agjencia</div>
                  <div class="fw-semibold mb-0"><?= h($selected['agency_name'] ?? '—') ?></div>
                </div>
              </div>
              <?php if (!empty($selected['assigned_at'])): ?>
              <div class="col-12">
                <div class="p-3 rounded" style="background:#eef2ff;">
                  <div class="small text-muted">Në këtë agjenci prej</div>
                  <div class="fw-semibold mb-0"><?= h($selected['assigned_at']) ?></div>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- QR -->
        <div class="card">
          <div class="card-header bg-white d-flex align-items-center justify-content-between">
            <h6 class="mb-0"><i class="bi bi-qr-code me-2"></i>Kodi QR i studentit</h6>
            <?php if ($qrToken): ?>
              <span class="badge bg-light text-dark">Gjeneruar: <?= h($qrCreatedAt ?? '') ?></span>
            <?php endif; ?>
          </div>
          <div class="card-body text-center">
            <?php if ($qrToken): ?>
              <div id="qrBox" class="d-inline-block p-2 rounded" style="background:#fff;border:1px dashed #e5e7eb;"></div>
              <div class="small text-muted mt-2">Ky QR është i përhershëm.</div>
              <div class="d-grid gap-2 mt-3">
                <button id="btnDownloadQR" class="btn btn-soft"><i class="bi bi-download me-1"></i>Shkarko PNG</button>
                <?php if ($verifyURL): ?>
                  <div class="input-group">
                    <span class="input-group-text bg-light border-0"><i class="bi bi-link-45deg"></i></span>
                    <input id="verifyLink" type="text" class="form-control border-0" readonly value="<?= h($verifyURL) ?>">
                    <button id="btnCopyLink" class="btn btn-outline-secondary" type="button"><i class="bi bi-clipboard"></i></button>
                    <a class="btn btn-outline-primary" href="<?= h($verifyURL) ?>" target="_blank"><i class="bi bi-box-arrow-up-right"></i></a>
                  </div>
                  <div class="form-text">Link publik verifikimi (verify.php).</div>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="alert alert-info">Ky student nuk ka ende kod QR.</div>
              <form method="post" onsubmit="return confirm('Gjenero kodin e përhershëm për këtë student?');">
                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="generate_qr">
                <input type="hidden" name="student_id" value="<?= (int)$selected['id'] ?>">
                <button class="btn btn-primary"><i class="bi bi-magic me-1"></i>Gjenero kodin e studentit</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Right: Analytics & Tables -->
      <div class="col-12 col-xl-8">
        <!-- KPIs -->
        <section class="row g-4 mb-4">
          <div class="col-12 col-sm-6 col-xxl-3">
            <div class="card p-3 h-100 kpi">
              <div class="d-flex align-items-center">
                <div class="icon me-3" style="background:#eff6ff;"><i class="bi bi-journal-text fs-4 text-primary"></i></div>
                <div>
                  <div class="small text-muted text-uppercase">Modulet</div>
                  <div class="h4 mb-1"><?= number_format($stats['courses'] ?? 0) ?></div>
                  <span class="small text-muted">Unike</span>
                </div>
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-xxl-3">
            <div class="card p-3 h-100 kpi">
              <div class="d-flex align-items-center">
                <div class="icon me-3" style="background:#ecfdf5;"><i class="bi bi-collection fs-4 text-success"></i></div>
                <div>
                  <div class="small text-muted text-uppercase">Grupe</div>
                  <div class="h4 mb-1"><?= number_format($stats['groups'] ?? 0) ?></div>
                  <span class="small text-muted">Gjithsej</span>
                </div>
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-xxl-3">
            <div class="card p-3 h-100 kpi">
              <div class="d-flex align-items-center">
                <div class="icon me-3" style="background:#fff1f2;"><i class="bi bi-bar-chart-line fs-4 text-danger"></i></div>
                <div>
                  <div class="small text-muted text-uppercase">Mes. Pikë</div>
                  <div class="h4 mb-1"><?= $stats['avg_score']!==null ? $stats['avg_score'] : '—' ?></div>
                  <span class="small text-muted">Kalueshmëria: <?= $stats['pass_rate']!==null ? ($stats['pass_rate'].'%') : '—' ?></span>
                </div>
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-xxl-3">
            <div class="card p-3 h-100 kpi">
              <div class="d-flex align-items-center">
                <div class="icon me-3" style="background:#eef2ff;"><i class="bi bi-clock-history fs-4 text-primary"></i></div>
                <div>
                  <div class="small text-muted text-uppercase">Orë studimi</div>
                  <div class="h4 mb-1"><?= number_format($stats['hours'] ?? 0) ?></div>
                  <span class="small text-muted">Sipas silabusit</span>
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- Chart & Upcoming -->
        <section class="row g-4 mb-4">
          <div class="col-12 col-lg-7">
            <div class="card h-100">
              <div class="card-header bg-white d-flex align-items-center justify-content-between">
                <h5 class="mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Ecuria e pikëve</h5>
              </div>
              <div class="card-body">
                <canvas id="chartScores" height="120"></canvas>
              </div>
            </div>
          </div>
          <div class="col-12 col-lg-5">
            <div class="card h-100">
              <div class="card-header bg-white d-flex align-items-center justify-content-between">
                <h5 class="mb-0"><i class="bi bi-calendar2-event me-2"></i>Provimet e afërta</h5>
                <span class="text-muted small">30 ditët në vijim</span>
              </div>
              <div class="card-body">
                <?php if ($upcoming): ?>
                  <ul class="list-group list-group-flush">
                    <?php foreach ($upcoming as $e): ?>
                      <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div>
                          <div class="fw-semibold"><?= h(($e['code'] ?? '').' · '.($e['name'] ?? '')) ?></div>
                          <div class="small text-muted">Data: <?= h($e['exam_date']) ?></div>
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

        <!-- Groups table -->
        <div class="card">
          <div class="card-header bg-white d-flex align-items-center justify-content-between">
            <h5 class="mb-0"><i class="bi bi-collection me-2"></i>Grupet e fundit</h5>
            <span class="text-muted small">max 5</span>
          </div>
          <div class="card-body">
            <div class="table-responsive mini-table">
              <table class="table align-middle">
                <thead class="table-light">
                  <tr>
                    <th>#</th><th>Moduli</th><th class="nowrap">Datat</th><th class="nowrap">Testi</th><th class="nowrap">Pikët</th>
                  </tr>
                </thead>
                <tbody>
                <?php if ($groups): foreach ($groups as $g): ?>
                  <tr>
                    <td>#<?= (int)$g['group_id'] ?></td>
                    <td><?= h(($g['code'] ?? '').' · '.($g['name'] ?? '')) ?></td>
                    <td class="nowrap"><?= h($g['start_date']) ?> – <?= h($g['end_date']) ?></td>
                    <td class="nowrap"><?= h($g['exam_date'] ?? '—') ?></td>
                    <td class="nowrap"><?= $g['final_score']!==null ? h((string)$g['final_score']) : '—' ?></td>
                  </tr>
                <?php endforeach; else: ?>
                  <tr><td colspan="5" class="text-center text-muted">Nuk ka grupe.</td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div>
    </section>
  <?php endif; ?>

  <div class="text-center text-muted small my-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($selected): ?>
<script>
  // Chart: Scores (soft area)
  const scoreLabels = <?= json_encode(array_column($scores,'d')) ?>;
  const scoreData   = <?= json_encode(array_map(fn($r)=> $r['s']!==null?(float)$r['s']:null, $scores)) ?>;
  (() => {
    const ctx = document.getElementById('chartScores');
    if (!ctx) return;
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: scoreLabels,
        datasets: [{
          label:'Pikë',
          data: scoreData,
          tension:.35,
          fill:true,
          borderWidth:2
        }]
      },
      options: {
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ display:false } },
        scales:{ x:{ grid:{display:false}}, y:{ beginAtZero:true, suggestedMax:100 } }
      }
    });
  })();
</script>
<?php endif; ?>

<?php if ($selected && $qrToken): ?>
<script>
  // QR payload i qëndrueshëm (string i shënuar)
  const payload = <?= json_encode('QTA|SID:'.$selected['id'].'|AMZE:'.($selected['nr_amze']??'').'|TOKEN:'.$qrToken) ?>;
  const qrBox = document.getElementById('qrBox');
  if (qrBox) {
    new QRCode(qrBox, { text: payload, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M });
  }
  // Shkarko PNG
  document.getElementById('btnDownloadQR')?.addEventListener('click', ()=>{
    const img = qrBox.querySelector('img') || qrBox.querySelector('canvas');
    if (!img) return;
    let dataURL = '';
    if (img.tagName.toLowerCase()==='img') dataURL = img.src;
    else dataURL = img.toDataURL('image/png');
    const a = document.createElement('a');
    a.href = dataURL; a.download = 'student_qr_<?= (int)$selected['id'] ?>.png'; a.click();
  });
  // Copy verify link
  document.getElementById('btnCopyLink')?.addEventListener('click', async ()=>{
    const inp = document.getElementById('verifyLink');
    if (!inp) return;
    try {
      await navigator.clipboard.writeText(inp.value);
      const btn = document.getElementById('btnCopyLink');
      const old = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-check2"></i>';
      setTimeout(()=>btn.innerHTML = old, 900);
    } catch(e){
      inp.select(); document.execCommand('copy');
    }
  });
</script>
<?php endif; ?>
</body>
</html>
