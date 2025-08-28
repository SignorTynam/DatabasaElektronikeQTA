<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* Guard admin */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch();
if (!$currentUser || $currentUser['role_name']!=='administrator') { header('Location: selectProfile.php'); exit; }

/* Helper HTML safe */
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

/* ====== KPIs ====== */
function tableCount(PDO $pdo, string $table): int {
  return (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}
$kpi = [
  'students'     => tableCount($pdo, 'students'),
  'agencies'     => tableCount($pdo, 'agencies'),
  'admins'       => tableCount($pdo, 'admins'),
  'users'        => tableCount($pdo, 'users'),
  'courses'      => tableCount($pdo, 'courses'),
  'groups'       => tableCount($pdo, 'course_groups'),
];

/* Studentë pa grup */
$noGroupCnt = (int)$pdo->query("
  SELECT COUNT(*)
  FROM students s
  LEFT JOIN course_group_students cgs ON cgs.student_id = s.id
  WHERE cgs.group_id IS NULL
")->fetchColumn();

/* Mesatare pikësh & norma kalueshmërie (≥ 50) */
$avgScore = $pdo->query("SELECT AVG(final_score) FROM course_group_students WHERE final_score IS NOT NULL")->fetchColumn();
$avgScore = $avgScore !== null ? round((float)$avgScore, 2) : null;
$passRate = $pdo->query("
  SELECT (SUM(CASE WHEN final_score >= 50 THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0)) * 100
  FROM course_group_students
  WHERE final_score IS NOT NULL
")->fetchColumn();
$passRate = $passRate !== null ? round((float)$passRate, 1) : null;

/* Aktivitetet e fundit (user-at më të rinj) */
$recentUsers = $pdo->query("
  SELECT u.full_name, u.email, u.created_at, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  ORDER BY u.created_at DESC
  LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

/* Provimet e afërta (30 ditët në vijim) */
$upcomingExams = $pdo->query("
  SELECT cg.exam_date, c.code AS course_code, c.name AS course_name,
         DATE_FORMAT(cg.exam_date, '%Y-%m-%d') AS d
  FROM course_groups cg
  JOIN courses c ON c.id = cg.course_id
  WHERE cg.exam_date IS NOT NULL AND cg.exam_date >= CURDATE()
  ORDER BY cg.exam_date ASC
  LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

/* Kapacitetet e grupeve (6 më të fundit) */
$groupsCap = $pdo->query("
  SELECT cg.id, c.code AS course_code, c.name AS course_name, cg.start_date, cg.end_date, cg.exam_date,
         COUNT(cgs.student_id) AS cnt
  FROM course_groups cg
  JOIN courses c ON c.id = cg.course_id
  LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
  GROUP BY cg.id
  ORDER BY cg.start_date DESC, cg.id DESC
  LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

/* Top module sipas regjistrimeve (bar chart) */
$topCourses = $pdo->query("
  SELECT c.code, c.name, COUNT(cgs.student_id) AS total_students
  FROM courses c
  LEFT JOIN course_groups cg ON cg.course_id = c.id
  LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
  GROUP BY c.id
  ORDER BY total_students DESC, c.code ASC
  LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

/* Studentë të rinj 8 javët e fundit (line chart) */
$weekly = $pdo->query("
  SELECT DATE_FORMAT(u.created_at, '%x-%v') AS yw, 
         CONCAT(YEAR(u.created_at), '-W', LPAD(WEEK(u.created_at, 3), 2, '0')) AS label,
         COUNT(*) AS cnt
  FROM students s
  JOIN users u ON u.id = s.user_id
  WHERE u.created_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
  GROUP BY yw, label
  ORDER BY yw ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* Studentë pa grup - lista e shkurtër */
$noGroupList = $pdo->query("
  SELECT s.id, s.nr_amze, s.first_name, s.father_name, s.last_name, s.personal_number
  FROM students s
  LEFT JOIN course_group_students cgs ON cgs.student_id = s.id
  WHERE cgs.group_id IS NULL
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
  LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$NAV_ACTIVE = 'dashboard';
require __DIR__ . '/inc/navbar.php';
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Dashboard Administrator – QTA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <!-- Chart.js për mini-grafikë -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    body { background:#f5f7fb; padding-top:72px; }
    .navbar-brand img { height:28px; }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
    .hero {
      background:
        radial-gradient(1200px 420px at 10% -20%, rgba(37,99,235,.25), rgba(37,99,235,0) 60%),
        radial-gradient(900px 320px at 90% -10%, rgba(99,102,241,.22), rgba(99,102,241,0) 55%),
        linear-gradient(135deg, #0ea5e9 0%, #2563eb 55%, #4f46e5 100%);
      color:#fff; border-radius:1.25rem; overflow:hidden;
    }
    .hero .chip { background:rgba(255,255,255,.17); border:1px solid rgba(255,255,255,.26); }
    .kpi .icon {
      width:46px; height:46px; border-radius:.75rem; display:flex; align-items:center; justify-content:center;
      background:#eef2ff;
    }
    .mini-table thead { background:#f1f5f9; }
    .progress { height:8px; }
    .badge-soft { background:#f1f5f9; color:#475569; }
    @media (max-width: 575.98px) { .navbar-text { display:none; } }
  </style>
</head>
<body>

<main class="container-fluid px-3 px-md-4">
  <!-- HERO -->
  <section class="hero p-4 p-md-5 mb-4">
    <div class="row align-items-center">
      <div class="col-lg-8 pe-lg-5">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="badge chip rounded-pill">Paneli i Administratorit</span>
          <span class="small" style="opacity:.85">QTA • Qendra e Trajnimeve të Avancuara</span>
        </div>
        <h1 class="display-6 fw-bold mb-2">Mirë se erdhe, <?= h($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Administrator')) ?>!</h1>
        <p class="mb-0">Shih panoramën e përgjithshme të përdoruesve, grupeve dhe performancës. Grafiqet poshtë përditësohen sipas të dhënave aktuale.</p>
      </div>
      <div class="col-lg-4 mt-4 mt-lg-0">
        <div class="card text-dark">
          <div class="card-body">
            <div class="d-flex align-items-center">
              <div class="icon me-3"><i class="bi bi-people-fill fs-4"></i></div>
              <div>
                <div class="text-uppercase small text-muted">Përdorues gjithsej</div>
                <div class="h3 mb-0"><?= number_format($kpi['users']) ?></div>
              </div>
            </div>
            <div class="progress mt-3" role="progressbar" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100">
              <div class="progress-bar" style="width:100%"></div>
            </div>
            <div class="small text-muted mt-2">Aktualisht në sistem</div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- KPIs -->
  <section class="row g-4 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100 kpi">
        <div class="d-flex align-items-center">
          <div class="icon me-3" style="background:#eff6ff;"><i class="bi bi-mortarboard fs-4 text-primary"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Studentë</div>
            <div class="h3 mb-1"><?= number_format($kpi['students']) ?></div>
            <span class="badge badge-soft rounded-pill">Pa grup: <?= number_format($noGroupCnt) ?></span>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100 kpi">
        <div class="d-flex align-items-center">
          <div class="icon me-3" style="background:#ecfdf5;"><i class="bi bi-building fs-4 text-success"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Agjenci</div>
            <div class="h3 mb-1"><?= number_format($kpi['agencies']) ?></div>
            <span class="small text-muted">Partnerë aktivë</span>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100 kpi">
        <div class="d-flex align-items-center">
          <div class="icon me-3" style="background:#fff1f2;"><i class="bi bi-shield-lock fs-4 text-danger"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Administratorë</div>
            <div class="h3 mb-1"><?= number_format($kpi['admins']) ?></div>
            <span class="small text-muted">Staf administrativ</span>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100 kpi">
        <div class="d-flex align-items-center">
          <div class="icon me-3" style="background:#eef2ff;"><i class="bi bi-journal-text fs-4 text-primary"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Modulet / Grupe</div>
            <div class="h3 mb-1"><?= number_format($kpi['courses']) ?> / <?= number_format($kpi['groups']) ?></div>
            <span class="small text-muted">Mes. pikë: <?= $avgScore!==null ? $avgScore : '—' ?> • Kalueshmëria: <?= $passRate!==null ? $passRate.'%' : '—' ?></span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Charts + Lists -->
  <section class="row g-4 mb-4">
    <div class="col-12 col-xl-7">
      <div class="card h-100">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Studentë të rinj (8 javët e fundit)</h5>
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
          <span class="text-muted small">Kufiri: 10 studentë</span>
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
                  <th class="nowrap">Kapaciteti</th>
                </tr>
              </thead>
              <tbody>
              <?php if ($groupsCap): foreach ($groupsCap as $g): 
                $cnt = (int)$g['cnt']; $pct = min(100, (int)round(($cnt/10)*100));
              ?>
                <tr>
                  <td>#<?= (int)$g['id'] ?></td>
                  <td><?= h(($g['course_code'] ?? '').' · '.($g['course_name'] ?? '')) ?></td>
                  <td class="nowrap"><?= h($g['start_date']) ?> – <?= h($g['end_date']) ?></td>
                  <td class="nowrap"><?= h($g['exam_date'] ?? '—') ?></td>
                  <td style="min-width:180px;">
                    <div class="d-flex align-items-center">
                      <div class="flex-grow-1 me-2">
                        <div class="progress" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                          <div class="progress-bar" style="width: <?= $pct ?>%"></div>
                        </div>
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
          <h5 class="mb-0"><i class="bi bi-calendar2-event me-2"></i>Provimet e afërta</h5>
          <span class="text-muted small">30 ditët në vijim</span>
        </div>
        <div class="card-body">
          <?php if ($upcomingExams): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($upcomingExams as $e): ?>
                <li class="list-group-item d-flex justify-content-between align-items-start">
                  <div>
                    <div class="fw-semibold"><?= h(($e['course_code'] ?? '').' · '.($e['course_name'] ?? '')) ?></div>
                    <div class="small text-muted">Data: <?= h($e['d']) ?></div>
                  </div>
                  <span class="badge rounded-pill text-bg-primary">Test</span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="text-muted mb-0">Asnjë provim i afërt.</p>
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
                $full = trim(($s['first_name']??'').' '.(($s['father_name']??'')?($s['father_name'].' '):'').($s['last_name']??''));
              ?>
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
    data: {
      labels: weeklyLabels,
      datasets: [{
        label: 'Studentë të rinj',
        data: weeklyData,
        tension: .35,
        fill: true,
        borderWidth: 2
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display:false } },
        y: { beginAtZero:true, ticks: { stepSize: 1 } }
      }
    }
  });
})();

/* Chart: Top module sipas regjistrimeve */
(() => {
  const ctx = document.getElementById('chartTopCourses');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: topLabels,
      datasets: [{
        label: 'Regjistrime',
        data: topData,
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display:false } },
      scales: {
        x: { grid: { display:false } },
        y: { beginAtZero:true, ticks: { stepSize: 1 } }
      }
    }
  });
})();
</script>
</body>
</html>
