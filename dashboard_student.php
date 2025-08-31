<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* Guard: vetëm student i loguar */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch();
if (!$currentUser || $currentUser['role_name']!=='student') { header('Location: selectProfile.php'); exit; }

/* Helper HTML safe */
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

/* ================================
   Student bazë (sipas user_id)
=================================== */
$baseQ = $pdo->prepare("SELECT id, personal_number FROM students WHERE user_id = :uid LIMIT 1");
$baseQ->execute([':uid'=>$_SESSION['user_id']]);
$base = $baseQ->fetch();
if (!$base) { exit('Profili i studentit nuk u gjet.'); }

$baseSid = (int)$base['id'];
$personalNumber = (string)($base['personal_number'] ?? '');

/* Të gjitha AMZË-të sipas numrit personal */
$amzeRows = [];
$studentIds = [$baseSid];

if ($personalNumber !== '') {
  $allQ = $pdo->prepare("
    SELECT id, nr_amze
    FROM students
    WHERE personal_number = :pn
    ORDER BY CAST(nr_amze AS UNSIGNED) ASC, nr_amze ASC
  ");
  $allQ->execute([':pn'=>$personalNumber]);
  $amzeRows = $allQ->fetchAll(PDO::FETCH_ASSOC);

  if ($amzeRows) {
    $studentIds = array_map(fn($r)=>(int)$r['id'], $amzeRows);
    // siguri kundër dublikatave
    $studentIds = array_values(array_unique($studentIds));
  }
}

/* Lista e AMZË-ve për shfaqje */
$amzeList = array_values(array_filter(array_map(
  fn($r)=> $r['nr_amze'] ?? '',
  ($amzeRows ?: [['nr_amze' => $pdo->query("SELECT nr_amze FROM students WHERE id=".$baseSid)->fetchColumn()]])
), fn($x)=> $x !== ''));

/* Info personale (nga rreshti bazë) */
$S = $pdo->prepare("
  SELECT s.first_name, s.father_name, s.last_name, s.birth_date, s.birth_place,
         s.nr_amze, s.personal_number, s.phone,
         el.code AS edu_code, el.label AS edu_label
  FROM students s
  LEFT JOIN education_levels el ON el.id = s.education_level_id
  WHERE s.id = :sid
  LIMIT 1
");
$S->execute([':sid'=>$baseSid]);
$stud = $S->fetch();

/* Grupe për të GJITHË student_id me të njëjtin numër personal */
$groups = [];
if ($studentIds) {
  $ph = implode(',', array_fill(0, count($studentIds), '?'));
  $G = $pdo->prepare("
    SELECT cg.id AS group_id, cg.start_date, cg.end_date,
           c.code AS course_code, c.name AS course_name, c.hours,
           cgs.final_score, cgs.exam_date AS my_exam,
           cgs.student_id
    FROM course_group_students cgs
    JOIN course_groups cg ON cg.id = cgs.group_id
    JOIN courses c ON c.id = cg.course_id
    WHERE cgs.student_id IN ($ph)
    ORDER BY cg.start_date DESC, cg.id DESC
  ");
  $G->execute($studentIds);
  $groups = $G->fetchAll(PDO::FETCH_ASSOC);
}

/* KPI për studentin */
$k_total_groups = count($groups);

$k_avg_score = null;
$k_pass_rate = null;
$allScores = array_values(array_filter(
  array_map(fn($r)=> $r['final_score']!==null ? (float)$r['final_score'] : null, $groups),
  fn($v)=> $v!==null
));
if ($allScores) {
  $k_avg_score = round(array_sum($allScores)/count($allScores), 2);
  $pass = 0; foreach ($allScores as $sc) if ($sc>=50) $pass++;
  $k_pass_rate = round(($pass / count($allScores))*100, 1);
}

/* Teste në të ardhmen */
$today = date('Y-m-d');
$upcoming = array_values(array_filter($groups, fn($r)=> !empty($r['my_exam']) && $r['my_exam'] >= $today));
usort($upcoming, fn($a,$b)=> strcmp($a['my_exam']??'', $b['my_exam']??''));
$upcoming = array_slice($upcoming, 0, 5);

/* Data për grafikun e pikëve */
$chartLabels = [];
$chartData   = [];
foreach (array_reverse($groups) as $r) { // të vjetrit më parë në grafik
  if ($r['final_score'] !== null) {
    $chartLabels[] = trim(($r['course_code'] ?? 'Kurs')." #".$r['group_id']);
    $chartData[]   = (float)$r['final_score'];
  }
}

/* Navbar (variant studenti) */
$NAV_ACTIVE = 'dashboard';
require __DIR__ . '/inc/navbar3.php';
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Paneli i Studentit – QTA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    body { background:#f5f7fb; padding-top:72px; }
    .navbar-brand img { height:28px; }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
    .mini-table thead { background:#f1f5f9; }
    .hero {
      background:
        radial-gradient(1200px 420px at 10% -20%, rgba(37,99,235,.20), rgba(37,99,235,0) 60%),
        radial-gradient(900px 320px at 90% -10%, rgba(99,102,241,.18), rgba(99,102,241,0) 55%),
        linear-gradient(135deg, #0ea5e9 0%, #2563eb 55%, #4f46e5 100%);
      color:#fff; border-radius:1.25rem; overflow:hidden;
    }
    .hero .chip { background:rgba(255,255,255,.17); border:1px solid rgba(255,255,255,.26); }
    .badge-soft { background:#f1f5f9; color:#475569; }
    .nowrap { white-space:nowrap; }
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
          <span class="badge chip rounded-pill">Paneli i Studentit</span>
          <span class="small" style="opacity:.85">QTA • Qendra e Trajnimeve të Avancuara</span>
        </div>
        <h1 class="display-6 fw-bold mb-2">
          Mirë se erdhe, <?= h($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Student')) ?>!
        </h1>
        <p class="mb-0">Këtu gjen informacionin kryesor: grupet ku je, datat e testimeve dhe rezultatet e tua.</p>
      </div>
      <div class="col-lg-4 mt-4 mt-lg-0">
        <div class="card text-dark">
          <div class="card-body">
            <div class="d-flex align-items-center mb-2">
              <div class="me-3" style="width:46px;height:46px;border-radius:.75rem;background:#eef2ff;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-person-badge fs-4"></i>
              </div>
              <div>
                <div class="text-uppercase small text-muted">Numri Personal</div>
                <div class="h6 mb-0"><?= h($personalNumber ?: ($stud['personal_number'] ?? '—')) ?></div>
              </div>
            </div>
            <div class="small mb-2"><span class="text-muted">AMZË-të:</span>
              <?php if ($amzeList): foreach ($amzeList as $a): ?>
                <span class="badge rounded-pill bg-light text-dark border ms-1"><?= h((string)$a) ?></span>
              <?php endforeach; else: ?>
                <span class="badge rounded-pill bg-light text-dark border ms-1">—</span>
              <?php endif; ?>
            </div>
            <div class="mt-2 small">
              <span class="badge badge-soft rounded-pill me-1">Arsimi: <?= h(($stud['edu_code']?($stud['edu_code'].' · '):'').($stud['edu_label']??'—')) ?></span>
              <span class="badge badge-soft rounded-pill">Tel: <?= h($stud['phone'] ?? '—') ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- KPIs -->
  <section class="row g-4 mb-4">
    <div class="col-12 col-md-6 col-xl-4">
      <div class="card p-3 h-100">
        <div class="d-flex align-items-center">
          <div class="me-3" style="width:46px;height:46px;border-radius:.75rem;background:#eff6ff;display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-collection fs-4 text-primary"></i>
          </div>
          <div>
            <div class="small text-muted text-uppercase">Grupet e mia</div>
            <div class="h3 mb-0"><?= number_format($k_total_groups) ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
      <div class="card p-3 h-100">
        <div class="d-flex align-items-center">
          <div class="me-3" style="width:46px;height:46px;border-radius:.75rem;background:#ecfdf5;display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-bar-chart-line fs-4 text-success"></i>
          </div>
          <div>
            <div class="small text-muted text-uppercase">Mesatarja e pikëve</div>
            <div class="h3 mb-0"><?= $k_avg_score!==null ? $k_avg_score : '—' ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
      <div class="card p-3 h-100">
        <div class="d-flex align-items-center">
          <div class="me-3" style="width:46px;height:46px;border-radius:.75rem;background:#fff7ed;display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-check2-circle fs-4 text-warning"></i>
          </div>
          <div>
            <div class="small text-muted text-uppercase">Kalueshmëria</div>
            <div class="h3 mb-0"><?= $k_pass_rate!==null ? ($k_pass_rate.'%') : '—' ?></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Charts + Info -->
  <section class="row g-4 mb-4">
    <div class="col-12 col-xl-7">
      <div class="card h-100">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Pikët e mia</h5>
          <span class="text-muted small">Shfaqen vetëm kur ka nota</span>
        </div>
        <div class="card-body">
          <canvas id="chartScores" height="120"></canvas>
        </div>
      </div>
    </div>
    <div class="col-12 col-xl-5">
      <div class="card h-100">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-person-lines-fill me-2"></i>Profili im</h5>
        </div>
        <div class="card-body">
          <div class="row g-2">
            <div class="col-12"><strong>Emri i plotë:</strong> <?= h(trim(($stud['first_name']??'').' '.(($stud['father_name']??'')?($stud['father_name'].' '):'').($stud['last_name']??''))) ?: '—' ?></div>
            <div class="col-12"><strong>Vendlindja:</strong> <?= h($stud['birth_place'] ?? '—') ?></div>
            <div class="col-6"><strong>Datëlindja:</strong> <?= h($stud['birth_date'] ?? '—') ?></div>
            <div class="col-6"><strong>Tel.:</strong> <?= h($stud['phone'] ?? '—') ?></div>
            <div class="col-12"><small class="text-muted">Për çdo ndryshim të të dhënave, kontakto administratën.</small></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Tabela: Grupet e mia -->
  <section class="card">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
      <h5 class="mb-0"><i class="bi bi-mortarboard me-2"></i>Grupet e mia</h5>
      <span class="text-muted small"><?= number_format($k_total_groups) ?> grup(e)</span>
    </div>
    <div class="card-body">
      <div class="table-responsive mini-table">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Grupi</th>
              <th>Moduli</th>
              <th class="nowrap">Fillimi</th>
              <th class="nowrap">Mbarimi</th>
              <th class="nowrap">Data e testit</th>
              <th class="nowrap">Pikët</th>
              <th class="nowrap">Statusi</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($groups): foreach ($groups as $g):
            $status = 'Aktiv';
            if ($g['final_score'] !== null) {
              $status = ((float)$g['final_score'] >= 50) ? 'Përfunduar (kaloi)' : 'Përfunduar (jo-kalues)';
            } elseif (!empty($g['my_exam'])) {
              $status = ($g['my_exam'] >= $today) ? ('Test më '.$g['my_exam']) : 'Test i kaluar';
            } elseif (!empty($g['end_date']) && $g['end_date'] < $today) {
              $status = 'Mbyllur';
            }
          ?>
            <tr>
              <td>#<?= (int)$g['group_id'] ?></td>
              <td><?= h(($g['course_code'] ?? '').' · '.($g['course_name'] ?? '')) ?></td>
              <td class="nowrap"><?= h($g['start_date'] ?? '') ?></td>
              <td class="nowrap"><?= h($g['end_date'] ?? '') ?></td>
              <td class="nowrap"><?= h($g['my_exam'] ?? '—') ?></td>
              <td class="nowrap"><?= $g['final_score']!==null ? rtrim(rtrim((string)$g['final_score'],'0'),'.') : '—' ?></td>
              <td class="nowrap"><span class="badge badge-soft rounded-pill"><?= h($status) ?></span></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7" class="text-center text-muted">Aktualisht s'je i regjistruar në asnjë grup.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- Teste në të ardhmen -->
  <section class="card mt-4">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
      <h6 class="mb-0"><i class="bi bi-calendar2-event me-2"></i>Testet e planifikuara</h6>
      <span class="text-muted small">Vetëm ato me datë në të ardhmen</span>
    </div>
    <div class="card-body">
      <?php if ($upcoming): ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($upcoming as $e): ?>
            <li class="list-group-item d-flex justify-content-between align-items-start">
              <div>
                <div class="fw-semibold"><?= h(($e['course_code'] ?? '').' · '.($e['course_name'] ?? '')) ?></div>
                <div class="small text-muted">Data: <?= h($e['my_exam']) ?></div>
              </div>
              <span class="badge rounded-pill text-bg-primary">Test</span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="text-muted mb-0">Nuk ka teste të planifikuara për momentin.</p>
      <?php endif; ?>
    </div>
  </section>

  <div class="text-center text-muted small mt-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Grafiku i pikëve */
const scoreLabels = <?= json_encode($chartLabels) ?>;
const scoreData   = <?= json_encode($chartData) ?>;
(() => {
  const ctx = document.getElementById('chartScores');
  if (!ctx || !scoreData.length) return;
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: scoreLabels,
      datasets: [{
        label: 'Pikët',
        data: scoreData,
        tension: .35,
        fill: true,
        borderWidth: 2
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display:false } },
      scales: {
        x: { grid: { display:false } },
        y: { beginAtZero:true, suggestedMax:100 }
      }
    }
  });
})();
</script>
</body>
</html>
