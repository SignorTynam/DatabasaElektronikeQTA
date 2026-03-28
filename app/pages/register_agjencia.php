<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* ------------------------------
   Guard: vetëm përdorues i loguar me rol "agjencia"
--------------------------------*/
if (!isset($_SESSION['user_id'])) {
  header('Location: selectProfile.php'); exit;
}

$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = :id
  LIMIT 1
");
$u->execute([':id' => $_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || $currentUser['role_name'] !== 'agjencia') {
  header('Location: selectProfile.php'); exit;
}

/* Marrim entitetin e agjencisë për këtë user */
$astmt = $pdo->prepare("SELECT * FROM agencies WHERE user_id = :uid LIMIT 1");
$astmt->execute([':uid' => $currentUser['id']]);
$AGENCY = $astmt->fetch(PDO::FETCH_ASSOC);
if (!$AGENCY) { header('Location: selectProfile.php'); exit; }

/* CSRF për eksportet */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

/* ------------------------------
   Parametra kërkimi & paginimi
--------------------------------*/
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

/* ------------------------------
   SQL bazë (vetëm studentët e kësaj agjencie)
   - lastg: grupi i fundit për student (sipas start_date)
--------------------------------*/
$params = [':agid' => (int)$AGENCY['id']];
$whereQ = '';
if ($q !== '') {
  $whereQ = " AND (
                 s.nr_amze LIKE :kw
              OR p.personal_number LIKE :kw2
              OR p.first_name LIKE :kw3
              OR p.father_name LIKE :kw4
              OR p.last_name LIKE :kw5
            )";
  $params[':kw']  = '%'.$q.'%';
  $params[':kw2'] = '%'.$q.'%';
  $params[':kw3'] = '%'.$q.'%';
  $params[':kw4'] = '%'.$q.'%';
  $params[':kw5'] = '%'.$q.'%';
}

$sqlBase = "
  FROM agency_students asg
  JOIN students s ON s.id = asg.student_id
  LEFT JOIN persons p ON p.id = s.person_id
  JOIN users u ON u.id = s.user_id
  LEFT JOIN education_levels el ON el.id = s.education_level_id
  LEFT JOIN (
      SELECT t.student_id, t.group_id
      FROM (
        SELECT cgs.student_id, cgs.group_id,
               ROW_NUMBER() OVER (PARTITION BY cgs.student_id ORDER BY cg.start_date DESC, cg.id DESC) AS rn
        FROM course_group_students cgs
        JOIN course_groups cg ON cg.id = cgs.group_id
      ) t
      WHERE t.rn = 1
  ) lastg ON lastg.student_id = s.id
  LEFT JOIN course_group_students cgs ON cgs.group_id = lastg.group_id AND cgs.student_id = s.id
  LEFT JOIN course_groups cg ON cg.id = lastg.group_id
  WHERE asg.agency_id = :agid
  $whereQ
";

/* total */
$count = $pdo->prepare("SELECT COUNT(*) ".$sqlBase);
$count->execute($params);
$total = (int)$count->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));

/* list */
$list = $pdo->prepare("
  SELECT
    s.id AS student_id,
    s.nr_amze,
    p.first_name, p.father_name, p.last_name,
    p.personal_number,
    p.birth_date, p.birth_place,
    TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) AS age,
    el.code AS edu_code, el.label AS edu_label,
    lastg.group_id, cg.start_date, cg.end_date, cg.exam_date,
    cgs.final_score
  ".$sqlBase."
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
  LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) {
  $list->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':lim', $limit, PDO::PARAM_INT);
$list->bindValue(':off', $offset, PDO::PARAM_INT);
$list->execute();
$rows = $list->fetchAll(PDO::FETCH_ASSOC);

/* për navbar2.php: cilin item të aktivizojmë */
$NAV_ACTIVE = 'students'; // ose 'register'
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Regjistri – QTA Agjenci</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { background:#f5f7fb; padding-top:72px; }
    .navbar-brand img { height:28px; }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
    .mini-table thead { background:#f1f5f9; }
    .form-control::placeholder { color:#9ca3af; }
    .pagination .page-link { border-radius:.5rem; }
    .nowrap { white-space:nowrap; }
    @media (max-width: 575.98px) { .navbar-text { display:none; } }
    /* Heqim çdo stil të inline-edit nga versioni admin */
    .readonly { display:inline-block; min-width:72px; padding:.35rem .5rem; border-radius:.5rem; }
  </style>
</head>
<body>

<?php require __DIR__ . '/inc/navbar2.php'; ?>

<main class="container-fluid px-3 px-md-4">
  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
    <h2 class="mb-0">Regjistri i studentëve</h2>
    <form class="d-flex" method="get" action="register_agjencia.php">
      <div class="input-group">
        <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control border-0" placeholder="Kërko sipas AMZËS/ID/Emrit...">
        <button class="btn btn-outline-secondary" type="button" onclick="window.location='register_agjencia.php'">
          <i class="bi bi-x-circle me-1"></i>Pastro
        </button>
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apliko</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
      <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Regjistri</h5>
      <div class="d-flex align-items-center gap-2">
        <span class="text-muted small me-2"><?= number_format($total) ?> rezultat(e)</span>
        <div class="btn-group" role="group" aria-label="Shkarkime">
          <a class="btn btn-outline-success"
             href="register_export_agency.php?f=xlsx&q=<?= urlencode($q) ?>&csrf=<?= urlencode($CSRF) ?>">
            <i class="bi bi-file-earmark-excel me-1"></i> Excel
          </a>
          <a class="btn btn-outline-danger"
             href="register_export_agency.php?f=pdf&q=<?= urlencode($q) ?>&csrf=<?= urlencode($CSRF) ?>">
            <i class="bi bi-file-earmark-pdf me-1"></i> PDF
          </a>
          <a class="btn btn-outline-primary"
             href="register_export_agency.php?f=docx&q=<?= urlencode($q) ?>&csrf=<?= urlencode($CSRF) ?>">
            <i class="bi bi-file-earmark-word me-1"></i> Word
          </a>
        </div>
      </div>
    </div>

    <div class="card-body">
      <div class="table-responsive mini-table">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="nowrap">AMZË</th>
              <th>Emër Atësi Mbiemër<br><small class="text-muted">ID Personal</small></th>
              <th class="nowrap">Datë fillimi</th>
              <th class="nowrap">Datë mbarimi</th>
              <th class="nowrap">Datë testimi</th>
              <th class="nowrap">Pikët përfundimtare</th>
              <th class="nowrap">Mosha</th>
              <th class="nowrap">Arsimi</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($rows): foreach ($rows as $r):
              $full = trim(($r['first_name']??'').' '.(($r['father_name']??'')?($r['father_name'].' '):'').($r['last_name']??''));
          ?>
            <tr>
              <td class="nowrap"><?= htmlspecialchars($r['nr_amze']) ?></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($full ?: '—') ?></div>
                <div class="text-muted small"><?= htmlspecialchars($r['personal_number'] ?? '—') ?></div>
              </td>
              <td class="nowrap"><span class="readonly"><?= htmlspecialchars($r['start_date'] ?: '—') ?></span></td>
              <td class="nowrap"><span class="readonly"><?= htmlspecialchars($r['end_date'] ?: '—') ?></span></td>
              <td class="nowrap"><span class="readonly"><?= htmlspecialchars($r['exam_date'] ?: '—') ?></span></td>
              <td class="nowrap">
                <span class="readonly">
                  <?= $r['final_score'] !== null ? rtrim(rtrim((string)$r['final_score'],'0'),'.') : '—' ?>
                </span>
              </td>
              <td class="nowrap"><?= $r['age'] !== null ? (int)$r['age'] : '—' ?></td>
              <td><?= htmlspecialchars(($r['edu_code']? $r['edu_code'].' — ' : '').($r['edu_label'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="8" class="text-center text-muted">Nuk u gjetën studentë për këtë agjenci.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white">
      <nav aria-label="Page navigation">
        <ul class="pagination mb-0 justify-content-end">
          <?php
            $base = 'register_agjencia.php?'.http_build_query(array_filter(['q'=>$q!==''?$q:null]));
            $prev = max(1,$page-1);
            $next = min($totalPages,$page+1);
            $sep  = (str_contains($base,'?') ? '&' : '?');
          ?>
          <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $base.$sep ?>page=1">«</a></li>
          <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $base.$sep ?>page=<?= $prev ?>">‹</a></li>
          <li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $totalPages ?></span></li>
          <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= $base.$sep ?>page=<?= $next ?>">›</a></li>
          <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= $base.$sep ?>page=<?= $totalPages ?>">»</a></li>
        </ul>
      </nav>
    </div>
    <?php endif; ?>
  </div>

  <div class="text-center text-muted small mt-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/../shared/partials/download_generation_toast.php'; ?>
</body>
</html>
