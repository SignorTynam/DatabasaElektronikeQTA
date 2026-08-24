<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* ===== Guard: vetëm STUDENT ===== */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);
if (!$currentUser || ($currentUser['role_name']??'')!=='student') { header('Location: selectProfile.php'); exit; }

/* ===== Helpers ===== */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function i($v): int { return (int)$v; }
$today = date('Y-m-d');

/* ===== Profili bazë i studentit ===== */
$baseQ = $pdo->prepare("
  SELECT s.id AS sid, s.person_id, p.personal_number
  FROM students s JOIN persons p ON p.id=s.person_id
  WHERE s.user_id=:uid LIMIT 1
");
$baseQ->execute([':uid'=>$_SESSION['user_id']]);
$base = $baseQ->fetch(PDO::FETCH_ASSOC);
if (!$base) { exit('Profili i studentit nuk u gjet.'); }

$baseSid        = (int)$base['sid'];
$basePersonId   = (int)$base['person_id'];
$personalNumber = (string)($base['personal_number'] ?? '');

/* Të gjitha regjistrimet (AMZË) për të njëjtin person_id */
$amzeRows = $pdo->prepare("
  SELECT id, nr_amze FROM students
  WHERE person_id=:pid
  ORDER BY CAST(nr_amze AS UNSIGNED) ASC, nr_amze ASC
");
$amzeRows->execute([':pid'=>$basePersonId]);
$amzeRows = $amzeRows->fetchAll(PDO::FETCH_ASSOC);

$studentIds = $amzeRows ? array_values(array_unique(array_map(fn($r)=>(int)$r['id'], $amzeRows))) : [$baseSid];
$amzeList   = array_values(array_filter(array_map(fn($r)=>$r['nr_amze'] ?? null, $amzeRows)));

/* Info personale + arsimi */
$S = $pdo->prepare("
  SELECT 
    p.first_name, p.father_name, p.last_name, p.birth_date, p.birth_place,
    s.nr_amze, p.personal_number, p.phone,
    el.code AS edu_code, el.label AS edu_label
  FROM students s
  JOIN persons p ON p.id=s.person_id
  LEFT JOIN education_levels el ON el.id=s.education_level_id
  WHERE s.id=:sid LIMIT 1
");
$S->execute([':sid'=>$baseSid]);
$stud = $S->fetch(PDO::FETCH_ASSOC);

/* Kompania e caktuar (maksimumi 1 sipas skemës) */
$company = null;
if ($studentIds) {
  $ph = implode(',', array_fill(0, count($studentIds), '?'));
  $C = $pdo->prepare("
    SELECT a.company_name, a.nip_t, a.phone
    FROM agency_students s
    JOIN agencies a ON a.id=s.agency_id
    WHERE s.student_id IN ($ph)
    LIMIT 1
  ");
  $C->execute($studentIds);
  $company = $C->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* Grupe për TË GJITHË student_id-t */
$groups = [];
if ($studentIds) {
  $ph = implode(',', array_fill(0, count($studentIds), '?'));
  $G = $pdo->prepare("
    SELECT cg.id AS group_id, cg.start_date, cg.end_date,
           c.code AS course_code, c.name AS course_name, c.hours,
           cgs.final_score, cgs.exam_date AS my_exam,
           cgs.student_id
    FROM course_group_students cgs
    JOIN course_groups cg ON cg.id=cgs.group_id
    JOIN courses c ON c.id=cg.course_id
    WHERE cgs.student_id IN ($ph)
    ORDER BY cg.start_date DESC, cg.id DESC
  ");
  $G->execute($studentIds);
  $groups = $G->fetchAll(PDO::FETCH_ASSOC);
}

/* ===== KPI & përmbledhje ===== */
$k_total_groups   = count($groups);
$k_active_groups  = 0;
$k_completed      = 0;
$k_upcoming_tests = 0;
$totalHours       = 0;

$allScores = [];
foreach ($groups as $g) {
  $totalHours += (int)($g['hours'] ?? 0);
  $isActive   = !empty($g['start_date']) && !empty($g['end_date']) && ($today >= $g['start_date'] && $today <= $g['end_date']);
  $isEnded    = !empty($g['end_date']) && $g['end_date'] < $today;
  if ($isActive) $k_active_groups++;
  if ($isEnded)  $k_completed++;
  if (!empty($g['my_exam']) && $g['my_exam'] >= $today) $k_upcoming_tests++;
  if ($g['final_score'] !== null) $allScores[] = (float)$g['final_score'];
}
$k_avg_score = $allScores ? round(array_sum($allScores)/count($allScores), 2) : null;
$k_pass_rate = null;
if ($allScores) {
  $passed = 0; foreach ($allScores as $sc) if ($sc >= 50) $passed++;
  $k_pass_rate = round(($passed / count($allScores)) * 100, 1);
}

/* Teste në të ardhmen (Top 5) */
$upcoming = array_values(array_filter($groups, fn($r)=> !empty($r['my_exam']) && $r['my_exam'] >= $today));
usort($upcoming, fn($a,$b)=> strcmp($a['my_exam'], $b['my_exam']));
$upcoming = array_slice($upcoming, 0, 5);

/* Data për grafiqe */
$scoreLabels = [];
$scoreData   = [];
foreach (array_reverse($groups) as $r) { // të vjetrit më parë
  if ($r['final_score'] !== null) {
    $scoreLabels[] = trim(($r['course_code'] ?? 'Kurs')." #".$r['group_id']);
    $scoreData[]   = (float)$r['final_score'];
  }
}

/* Orët sipas modulit (bar) */
$hoursByCourse = [];
foreach ($groups as $r) {
  $key = ($r['course_code'] ?? '').' · '.($r['course_name'] ?? '');
  $hoursByCourse[$key] = ($hoursByCourse[$key] ?? 0) + (int)($r['hours'] ?? 0);
}
$hoursLabels = array_keys($hoursByCourse);
$hoursData   = array_values($hoursByCourse);

/* Status split (doughnut) */
$statusCounts = [
  'Aktive'   => $k_active_groups,
  'Të mbyllura' => $k_completed,
  'Me test në pritje' => $k_upcoming_tests,
];
$statusLabels = array_keys($statusCounts);
$statusData   = array_values($statusCounts);

/* Navbar studenti */
$NAV_ACTIVE = 'dashboard';
require __DIR__ . '/inc/navbar3.php';

$pageTitle = 'Dashboard i Ri — Studenti • QTA';
require __DIR__ . '/../shared/app_head.php';
?>


<main class="app-main">
  <!-- HERO (i njëjtë stil si Admin/Editor) -->
  <section class="hero p-4 p-md-5 mb-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="chip">Panel i ri</span>
          <span class="small text-muted">QTA • Qendra e Trajnimeve të Avancuara</span>
        </div>
        <h1 class="fw-bold mb-1">Përmbledhje personale</h1>
        <p class="mb-0 text-muted">Grupet, testet dhe progresi yt — gjithçka në një vend.</p>
      </div>
      <div class="soft">
        <div class="small text-muted">Mirë se erdhe</div>
        <div class="h5 mb-0"><?= h($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Student')) ?></div>
      </div>
    </div>
  </section>

  <!-- KPIs -->
  <section class="row g-4 mb-4 kpi">
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100">
        <div class="d-flex align-items-center">
          <div class="icon me-3"><i class="bi bi-collection fs-4 text-primary"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Grupet e mia</div>
            <div class="h3 mb-1"><?= number_format($k_total_groups) ?></div>
            <span class="small text-muted">Aktive sot: <?= number_format($k_active_groups) ?></span>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100">
        <div class="d-flex align-items-center">
          <div class="icon me-3"><i class="bi bi-hourglass-split fs-4 text-success"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Orë mësimore</div>
            <div class="h3 mb-1"><?= number_format($totalHours) ?></div>
            <span class="small text-muted">Sipas moduleve ku je regjistruar</span>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100">
        <div class="d-flex align-items-center">
          <div class="icon me-3"><i class="bi bi-clipboard2-check fs-4 text-warning"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Teste në pritje</div>
            <div class="h3 mb-1"><?= number_format($k_upcoming_tests) ?></div>
            <span class="small text-muted">Për 30 ditët e ardhshme</span>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100">
        <div class="d-flex align-items-center">
          <div class="icon me-3"><i class="bi bi-building fs-4 text-danger"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Kompania</div>
            <div class="h6 mb-1"><?= h($company['company_name'] ?? '—') ?></div>
            <span class="small text-muted"><?= isset($company['nip_t']) ? 'NIPT: '.h($company['nip_t']) : 'S’ka të caktuar' ?></span>
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
          <h5 class="mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Pikët e mia</h5>
          <span class="text-muted small">Shfaqen kur ka rezultate</span>
        </div>
        <div class="card-body">
          <canvas id="chartScores" height="120"></canvas>
        </div>
      </div>
    </div>
    <div class="col-12 col-xl-5">
      <div class="card h-100">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-bar-chart-line me-2"></i>Orë sipas modulit</h5>
        </div>
        <div class="card-body">
          <canvas id="chartHours" height="120"></canvas>
        </div>
      </div>
    </div>
  </section>

  <!-- Status split + Profi info -->
  <section class="row g-4 mb-4">
    <div class="col-12 col-xl-5">
      <div class="card h-100">
        <div class="card-header bg-white">
          <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Statusi i grupeve</h5>
        </div>
        <div class="card-body">
          <canvas id="chartStatus" height="150"></canvas>
          <div class="small text-muted mt-2">Përmbledhje e grupeve aktive, të mbyllura dhe testeve në pritje.</div>
        </div>
      </div>
    </div>
    <div class="col-12 col-xl-7">
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
            <div class="col-12 mt-2">
              <span class="badge badge-soft rounded-pill me-1">Arsimi: <?= h(($stud['edu_code']?($stud['edu_code'].' · '):'').($stud['edu_label']??'—')) ?></span>
              <span class="badge badge-soft rounded-pill">Nr. Personal: <?= h($personalNumber ?: ($stud['personal_number'] ?? '—')) ?></span>
            </div>
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
            <tr><td colspan="7" class="text-center text-muted">Aktualisht s’je i regjistruar në asnjë grup.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- Testet e planifikuara -->
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
                <div class="small text-muted">Data: <?= h($e['my_exam']) ?> • Grupi #<?= (int)$e['group_id'] ?></div>
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

  <div class="text-center text-muted small my-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<!-- JS -->
<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<script>
/* PHP → JS */
const scoreLabels = <?= json_encode($scoreLabels) ?>;
const scoreData   = <?= json_encode($scoreData) ?>;

const hoursLabels = <?= json_encode($hoursLabels) ?>;
const hoursData   = <?= json_encode($hoursData) ?>;

const statusLabels = <?= json_encode($statusLabels) ?>;
const statusData   = <?= json_encode($statusData) ?>;

/* Charts */
(() => {
  const cs = document.getElementById('chartScores');
  if (cs && scoreData.length) new Chart(cs, {
    type: 'line',
    data: { labels: scoreLabels, datasets: [{ label:'Pikët', data: scoreData, borderWidth:2, tension:.35, fill:true }] },
    options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} }, scales:{ x:{ grid:{display:false} }, y:{ beginAtZero:true, suggestedMax:100 } } }
  });

  const ch = document.getElementById('chartHours');
  if (ch && hoursData.length) new Chart(ch, {
    type: 'bar',
    data: { labels: hoursLabels, datasets: [{ label:'Orë', data: hoursData, borderWidth:1 }] },
    options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} }, scales:{ x:{ grid:{display:false} }, y:{ beginAtZero:true } } }
  });

  const cst = document.getElementById('chartStatus');
  if (cst) new Chart(cst, {
    type: 'doughnut',
    data: { labels: statusLabels, datasets: [{ data: statusData }] },
    options: { plugins:{ legend:{ position:'bottom' } } }
  });
})();
</script>
</body>
</html>
