<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* ------------------------------
   Guard: vetëm kompani e loguar
------------------------------- */
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

/* Lejo 'kompani' (emri i ri) dhe 'agjencia' (për kompatibilitet) */
if (!$currentUser || !in_array($currentUser['role_name'] ?? '', ['kompani','agjencia'], true)) {
    header('Location: selectProfile.php'); exit;
}

/* Gjej kompaninë e lidhur me user-in (tabela ekzistuese: agencies) */
$co = $pdo->prepare("
  SELECT id, user_id, company_name, nip_t, address, phone
  FROM agencies
  WHERE user_id = :uid
  LIMIT 1
");
$co->execute([':uid' => (int)$currentUser['id']]);
$COMPANY = $co->fetch(PDO::FETCH_ASSOC);
if (!$COMPANY) { header('Location: selectProfile.php'); exit; }

/* ------------------------------
   Helpers
------------------------------- */
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}
$cid = (int)$COMPANY['id'];

/* ====== KPI-t e kompanisë (pa nota) ====== */

/* Studentë të kësaj kompanie */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM agency_students WHERE agency_id=:cid");
$stmt->execute([':cid'=>$cid]);
$studentsTotal = (int)$stmt->fetchColumn();

/* Studentë pa asnjë grup */
$stmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM agency_students a
  LEFT JOIN course_group_students cgs ON cgs.student_id = a.student_id
  WHERE a.agency_id = :cid AND cgs.group_id IS NULL
");
$stmt->execute([':cid'=>$cid]);
$noGroupCnt = (int)$stmt->fetchColumn();

/* Grupe ku kompania ka studentë */
$stmt = $pdo->prepare("
  SELECT COUNT(DISTINCT cgs.group_id)
  FROM course_group_students cgs
  JOIN agency_students a ON a.student_id = cgs.student_id
  WHERE a.agency_id = :cid
");
$stmt->execute([':cid'=>$cid]);
$groupsCnt = (int)$stmt->fetchColumn();

/* Module ku kompania ka studentë */
$stmt = $pdo->prepare("
  SELECT COUNT(DISTINCT cg.course_id)
  FROM course_group_students cgs
  JOIN agency_students a ON a.student_id = cgs.student_id
  JOIN course_groups cg ON cg.id = cgs.group_id
  WHERE a.agency_id = :cid
");
$stmt->execute([':cid'=>$cid]);
$coursesCnt = (int)$stmt->fetchColumn();

/* Studentë të kompanisë në grupe AKTIVE sot */
$stmt = $pdo->prepare("
  SELECT COUNT(DISTINCT cgs.student_id)
  FROM course_group_students cgs
  JOIN agency_students a ON a.student_id = cgs.student_id
  JOIN course_groups cg ON cg.id=cgs.group_id
  WHERE a.agency_id=:cid
    AND CURDATE() BETWEEN cg.start_date AND cg.end_date
");
$stmt->execute([':cid'=>$cid]);
$activeStudentsToday = (int)$stmt->fetchColumn();

/* Grupe aktive sot ku kompania ka studentë */
$stmt = $pdo->prepare("
  SELECT COUNT(DISTINCT cg.id)
  FROM course_groups cg
  JOIN course_group_students cgs ON cgs.group_id=cg.id
  JOIN agency_students a ON a.student_id=cgs.student_id
  WHERE a.agency_id=:cid
    AND CURDATE() BETWEEN cg.start_date AND cg.end_date
");
$stmt->execute([':cid'=>$cid]);
$activeGroupsToday = (int)$stmt->fetchColumn();

/* ====== Charts & Lists ====== */

/* Studentë të rinj të kompanisë (8 javët e fundit) */
$stmt = $pdo->prepare("
  SELECT DATE_FORMAT(u.created_at, '%x-%v') AS yw,
         CONCAT(YEAR(u.created_at), '-W', LPAD(WEEK(u.created_at, 3), 2, '0')) AS label,
         COUNT(*) AS cnt
  FROM agency_students a
  JOIN students s ON s.id = a.student_id
  JOIN users u ON u.id = s.user_id
  WHERE a.agency_id = :cid
    AND u.created_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
  GROUP BY yw, label
  ORDER BY yw ASC
");
$stmt->execute([':cid'=>$cid]);
$weekly = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Top 5 module sipas regjistrimeve të studentëve të kompanisë */
$stmt = $pdo->prepare("
  SELECT c.code, c.name, COUNT(*) AS total_students
  FROM courses c
  JOIN course_groups cg ON cg.course_id = c.id
  JOIN course_group_students cgs ON cgs.group_id = cg.id
  JOIN agency_students a ON a.student_id = cgs.student_id
  WHERE a.agency_id = :cid
  GROUP BY c.id
  ORDER BY total_students DESC, c.code ASC
  LIMIT 5
");
$stmt->execute([':cid'=>$cid]);
$topCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Ngjarje të afërta: fillime/teste të grupeve ku kompania ka studentë (30 ditë) */
$stmt = $pdo->prepare("
  SELECT DISTINCT cg.start_date, cg.exam_date, c.code AS course_code, c.name AS course_name, cg.id AS gid
  FROM course_group_students cgs
  JOIN agency_students a ON a.student_id = cgs.student_id
  JOIN course_groups cg ON cg.id = cgs.group_id
  JOIN courses c ON c.id = cg.course_id
  WHERE a.agency_id = :cid
    AND (
      (cg.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))
      OR
      (cg.exam_date  IS NOT NULL AND cg.exam_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))
    )
  ORDER BY LEAST(COALESCE(cg.start_date, '9999-12-31'), COALESCE(cg.exam_date, '9999-12-31')) ASC
  LIMIT 8
");
$stmt->execute([':cid'=>$cid]);
$upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Kapacitetet e grupeve (6 më të fundit ku kompania ka studentë) */
$stmt = $pdo->prepare("
  SELECT cg.id, c.code AS course_code, c.name AS course_name,
         cg.start_date, cg.end_date, cg.exam_date,
         COUNT(cgs.student_id) AS cnt_company
  FROM course_group_students cgs
  JOIN agency_students a ON a.student_id = cgs.student_id
  JOIN course_groups cg ON cg.id = cgs.group_id
  JOIN courses c ON c.id = cg.course_id
  WHERE a.agency_id = :cid
  GROUP BY cg.id
  ORDER BY cg.start_date DESC, cg.id DESC
  LIMIT 6
");
$stmt->execute([':cid'=>$cid]);
$groupsCap = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Studentë të kësaj kompanie pa grup – listë e shkurtër (korrekte me persons) */
$stmt = $pdo->prepare("
  SELECT s.id, s.nr_amze, p.first_name, p.father_name, p.last_name, p.personal_number
  FROM agency_students a
  JOIN students s ON s.id = a.student_id
  JOIN persons p ON p.id = s.person_id
  WHERE a.agency_id = :cid
    AND NOT EXISTS (SELECT 1 FROM course_group_students x WHERE x.student_id = s.id)
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
  LIMIT 6
");
$stmt->execute([':cid'=>$cid]);
$noGroupList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$NAV_ACTIVE = 'dashboard';
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Dashboard i Kompanisë – QTA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    body { background:#f5f7fb; padding-top:72px; }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
    .hero {
      border-radius:1.25rem; overflow:hidden; color:#0b1220;
      background:
        radial-gradient(1100px 380px at 8% -20%, rgba(14,165,233,.18), rgba(14,165,233,0) 60%),
        radial-gradient(900px 320px at 92% -15%, rgba(99,102,241,.18), rgba(99,102,241,0) 55%),
        linear-gradient(135deg, #e0f2fe 0%, #eef2ff 100%);
    }
    .chip { background:#eef2ff; border:1px solid #e0e7ff; border-radius:999px; padding:.25rem .65rem; }
    .kpi .icon { width:46px; height:46px; border-radius:.75rem; display:flex; align-items:center; justify-content:center; background:#f1f5f9; }
    .mini-table thead { background:#f1f5f9; }
    .progress { height:8px; }
    .nowrap { white-space:nowrap; }
  </style>
</head>
<body>

<?php require __DIR__ . '/inc/navbar2.php'; ?>

<main class="container-fluid px-3 px-md-4">
  <!-- HERO -->
  <section class="hero p-4 p-md-5 mb-4">
    <div class="row align-items-center">
      <div class="col-lg-8 pe-lg-5">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="chip">Paneli i Kompanisë</span>
          <span class="small text-muted">QTA • Qendra e Trajnimeve të Avancuara</span>
        </div>
        <h1 class="fw-bold mb-2">Mirë se erdhe, <?= h($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Kompani')) ?>!</h1>
        <p class="mb-0 text-muted">Shih statusin e studentëve të kompanisë, grupet ku marrin pjesë dhe ngjarjet e afërta.</p>
      </div>
      <div class="col-lg-4 mt-4 mt-lg-0">
        <div class="card text-dark">
          <div class="card-body">
            <div class="d-flex align-items-center">
              <div class="icon me-3"><i class="bi bi-buildings fs-4"></i></div>
              <div>
                <div class="text-uppercase small text-muted">Kompania</div>
                <div class="h5 mb-0"><?= h($COMPANY['company_name'] ?? '—') ?></div>
                <div class="small text-muted">NIPT: <?= h($COMPANY['nip_t'] ?? '—') ?></div>
                <div class="small text-muted">Tel: <?= h($COMPANY['phone'] ?? '—') ?> • <?= h($COMPANY['address'] ?? '') ?></div>
              </div>
            </div>
            <div class="progress mt-3"><div class="progress-bar" style="width:100%"></div></div>
            <div class="small text-muted mt-2">Status: Aktiv</div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- KPIs (pa nota) -->
  <section class="row g-4 mb-4 kpi">
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100">
        <div class="d-flex align-items-center">
          <div class="icon me-3"><i class="bi bi-people-fill fs-4 text-primary"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Studentë</div>
            <div class="h3 mb-1"><?= number_format($studentsTotal) ?></div>
            <span class="small text-muted">Pa grup: <?= number_format($noGroupCnt) ?></span>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100">
        <div class="d-flex align-items-center">
          <div class="icon me-3"><i class="bi bi-collection fs-4 text-success"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Grupe</div>
            <div class="h3 mb-1"><?= number_format($groupsCnt) ?></div>
            <span class="small text-muted">Module: <?= number_format($coursesCnt) ?></span>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100">
        <div class="d-flex align-items-center">
          <div class="icon me-3"><i class="bi bi-activity fs-4 text-warning"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Aktivitet sot</div>
            <div class="h3 mb-1"><?= number_format($activeStudentsToday) ?></div>
            <span class="small text-muted">Në grupe aktive: <?= number_format($activeGroupsToday) ?></span>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100">
        <div class="d-flex align-items-center">
          <div class="icon me-3"><i class="bi bi-flag fs-4 text-secondary"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Prioritete</div>
            <div class="h6 mb-0">Fillime & teste</div>
            <span class="small text-muted">Shiko listat më poshtë</span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Charts -->
  <section class="row g-4 mb-4">
    <div class="col-12 col-xl-7">
      <div class="card h-100">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Studentë të rinj (8 javë)</h5>
        </div>
        <div class="card-body">
          <canvas id="chartWeekly" height="120"></canvas>
        </div>
      </div>
    </div>
    <div class="col-12 col-xl-5">
      <div class="card h-100">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-bar-chart-line me-2"></i>Top 5 module (sipas regjistrimeve)</h5>
        </div>
        <div class="card-body">
          <canvas id="chartTopCourses" height="120"></canvas>
        </div>
      </div>
    </div>
  </section>

  <!-- Tables -->
  <section class="row g-4">
    <div class="col-12 col-xl-7">
      <div class="card">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-collection me-2"></i>Kapacitetet e grupeve</h5>
          <span class="text-muted small">Kufiri i grupit: 10 studentë</span>
        </div>
        <div class="card-body">
          <div class="table-responsive mini-table">
            <table class="table align-middle">
              <thead class="table-light">
                <tr>
                  <th>Grupi</th>
                  <th>Moduli</th>
                  <th class="nowrap">Datat</th>
                  <th class="nowrap">Testi</th>
                  <th class="nowrap">Kapaciteti (tuaj)</th>
                </tr>
              </thead>
              <tbody>
              <?php if ($groupsCap): foreach ($groupsCap as $g):
                $cnt = (int)$g['cnt_company']; $pct = min(100, (int)round(($cnt/10)*100));
              ?>
                <tr>
                  <td>#<?= (int)$g['id'] ?></td>
                  <td><?= h(($g['course_code'] ?? '').' · '.($g['course_name'] ?? '')) ?></td>
                  <td class="nowrap"><?= h($g['start_date']) ?> – <?= h($g['end_date']) ?></td>
                  <td class="nowrap"><?= h($g['exam_date'] ?? '—') ?></td>
                  <td style="min-width:200px;">
                    <div class="d-flex align-items-center">
                      <div class="flex-grow-1 me-2">
                        <div class="progress"><div class="progress-bar" style="width: <?= $pct ?>%"></div></div>
                      </div>
                      <span class="small text-muted"><?= $cnt ?>/10</span>
                    </div>
                  </td>
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

    <div class="col-12 col-xl-5">
      <div class="card mb-4">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-calendar2-week me-2"></i>Ngjarje të afërta (30 ditë)</h5>
        </div>
        <div class="card-body">
          <?php if ($upcoming): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($upcoming as $e):
                $dStart = $e['start_date'] ?? null;
                $dExam  = $e['exam_date']  ?? null;
              ?>
                <?php if ($dStart && $dStart >= date('Y-m-d') && $dStart <= date('Y-m-d', strtotime('+30 days'))): ?>
                  <li class="list-group-item d-flex justify-content-between align-items-start">
                    <div>
                      <div class="fw-semibold"><?= h(($e['course_code'] ?? '').' · '.($e['course_name'] ?? '')) ?></div>
                      <div class="small text-muted">Fillim: <?= h($dStart) ?> • Grupi #<?= (int)$e['gid'] ?></div>
                    </div>
                    <span class="badge rounded-pill text-bg-primary">Fillim</span>
                  </li>
                <?php endif; ?>
                <?php if ($dExam && $dExam >= date('Y-m-d') && $dExam <= date('Y-m-d', strtotime('+30 days'))): ?>
                  <li class="list-group-item d-flex justify-content-between align-items-start">
                    <div>
                      <div class="fw-semibold"><?= h(($e['course_code'] ?? '').' · '.($e['course_name'] ?? '')) ?></div>
                      <div class="small text-muted">Test: <?= h($dExam) ?> • Grupi #<?= (int)$e['gid'] ?></div>
                    </div>
                    <span class="badge rounded-pill text-bg-success">Test</span>
                  </li>
                <?php endif; ?>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="text-muted mb-0">Asnjë ngjarje e afërt.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-header bg-white">
          <h6 class="mb-0"><i class="bi bi-person-dash me-2"></i>Studentë pa grup</h6>
        </div>
        <div class="card-body">
          <?php if ($noGroupList): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($noGroupList as $s):
                $full = trim(($s['first_name']??'').' '.(($s['father_name']??'')?($s['father_name'].' '):'').($s['last_name']??'')); ?>
                <li class="list-group-item d-flex justify-content-between align-items-start">
                  <div>
                    <div class="fw-semibold"><?= h($full ?: '—') ?></div>
                    <div class="small text-muted">AMZË: <?= h($s['nr_amze']) ?> · ID: <?= h($s['personal_number'] ?? '—') ?></div>
                  </div>
                  <span class="badge rounded-pill text-bg-secondary">Pezull</span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="text-muted mb-0">Të gjithë studentët janë në grupe.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <div class="text-center text-muted small mt-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Të dhënat për grafiqet (nga PHP) */
const weeklyLabels = <?= json_encode(array_column($weekly, 'label')) ?>;
const weeklyData   = <?= json_encode(array_map('intval', array_column($weekly, 'cnt'))) ?>;

const topLabels = <?= json_encode(array_map(fn($r)=> ($r['code']??'').' · '.($r['name']??''), $topCourses)) ?>;
const topData   = <?= json_encode(array_map('intval', array_column($topCourses, 'total_students'))) ?>;

/* Chart: Studentë të rinj / javë */
(() => {
  const ctx = document.getElementById('chartWeekly');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'line',
    data: { labels: weeklyLabels, datasets: [{ label: 'Studentë të rinj', data: weeklyData, tension: .35, fill: true, borderWidth: 2 }] },
    options: {
      responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
      scales: { x: { grid: { display:false } }, y: { beginAtZero:true, ticks: { stepSize: 1 } } }
    }
  });
})();

/* Chart: Top module sipas regjistrimeve */
(() => {
  const ctx = document.getElementById('chartTopCourses');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'bar',
    data: { labels: topLabels, datasets: [{ label: 'Regjistrime', data: topData, borderWidth: 1 }] },
    options: {
      responsive: true, maintainAspectRatio: false, plugins: { legend: { display:false } },
      scales: { x: { grid: { display:false } }, y: { beginAtZero:true, ticks: { stepSize: 1 } } }
    }
  });
})();
</script>
</body>
</html>
