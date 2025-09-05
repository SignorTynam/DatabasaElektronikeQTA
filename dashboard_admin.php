<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* ===== Guard admin ===== */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);
if (!$currentUser || ($currentUser['role_name']??'')!=='administrator') { header('Location: selectProfile.php'); exit; }

/* ===== Helpers ===== */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function intv($v): int { return (int)$v; }

/* ===== Core KPIs (no grades) ===== */
$studentsTotal   = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$agenciesTotal   = (int)$pdo->query("SELECT COUNT(*) FROM agencies")->fetchColumn();
$coursesTotal    = (int)$pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn();
$usersTotal      = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

/* Active groups & utilization (capacity=10) */
$activeGroups = (int)$pdo->query("
  SELECT COUNT(*) FROM course_groups
  WHERE start_date <= CURDATE() AND end_date >= CURDATE()
")->fetchColumn();

$activeEnrollments = (int)$pdo->query("
  SELECT COUNT(*)
  FROM course_group_students cgs
  JOIN course_groups cg ON cg.id=cgs.group_id
  WHERE CURDATE() BETWEEN cg.start_date AND cg.end_date
")->fetchColumn();

$capacitySeats = $activeGroups * 10;
$fillRate = $capacitySeats > 0 ? round(($activeEnrollments / $capacitySeats) * 100, 1) : null;

/* Audit activity (last 24h) */
$audit24h = (int)$pdo->query("
  SELECT COUNT(*) FROM audit_events
  WHERE happened_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
")->fetchColumn();

/* Security & hygiene */
$usersWithoutPerson = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE person_id IS NULL")->fetchColumn();
$usersNoCredentials = (int)$pdo->query("
  SELECT COUNT(*) FROM users u
  LEFT JOIN credentials c ON c.user_id=u.id
  WHERE c.user_id IS NULL
")->fetchColumn();
$outdatedCreds = (int)$pdo->query("
  SELECT COUNT(*) FROM credentials
  WHERE last_password_change IS NULL
     OR last_password_change < DATE_SUB(NOW(), INTERVAL 180 DAY)
")->fetchColumn();

/* ===== New Concepts: Distributions & Trends ===== */

/* Funnel (students lifecycle) */
$studentsInAnyGroup = (int)$pdo->query("SELECT COUNT(DISTINCT student_id) FROM course_group_students")->fetchColumn();
$studentsInActiveGroups = (int)$pdo->query("
  SELECT COUNT(DISTINCT cgs.student_id)
  FROM course_group_students cgs
  JOIN course_groups cg ON cg.id=cgs.group_id
  WHERE CURDATE() BETWEEN cg.start_date AND cg.end_date
")->fetchColumn();
$studentsWithAgency = (int)$pdo->query("SELECT COUNT(DISTINCT student_id) FROM agency_students")->fetchColumn();

/* Weekly new students (last 12 weeks) */
$weekly = $pdo->query("
  SELECT YEARWEEK(created_at,3) AS yw,
         CONCAT(YEAR(created_at), '-W', LPAD(WEEK(created_at,3),2,'0')) AS label,
         COUNT(*) AS cnt
  FROM students
  WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
  GROUP BY yw, label
  ORDER BY yw ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* Audit events last 14 days by action (stacked) */
$auditSeriesRows = $pdo->query("
  SELECT DATE(happened_at) AS d, action, COUNT(*) AS cnt
  FROM audit_events
  WHERE happened_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
  GROUP BY d, action
  ORDER BY d ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* Roles distribution */
$rolesDist = $pdo->query("
  SELECT r.name AS label, COUNT(*) AS cnt
  FROM users u JOIN roles r ON r.id=u.role_id
  GROUP BY r.name
  ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* Gender split (of students) */
$genderDist = $pdo->query("
  SELECT g.label AS label, COUNT(*) AS cnt
  FROM students s
  JOIN persons p ON p.id=s.person_id
  JOIN genders g ON g.id=p.gender_id
  GROUP BY g.id
  ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* Education level split (of students) */
$eduDist = $pdo->query("
  SELECT COALESCE(el.label,'Pa specifikuar') AS label, COUNT(*) AS cnt
  FROM students s
  LEFT JOIN education_levels el ON el.id=s.education_level_id
  GROUP BY COALESCE(el.label,'Pa specifikuar')
  ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* Top agencies by assigned students */
$agencyTop = $pdo->query("
  SELECT a.company_name, a.nip_t, COUNT(asg.student_id) AS cnt
  FROM agencies a
  LEFT JOIN agency_students asg ON asg.agency_id=a.id
  GROUP BY a.id
  ORDER BY cnt DESC, a.company_name ASC
  LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

/* Upcoming milestones (starts & exams next 30 days) */
$milestones = $pdo->query("
  SELECT * FROM (
    SELECT 'Fillim' AS type, cg.start_date AS d, cg.id AS gid, c.code, c.name
    FROM course_groups cg JOIN courses c ON c.id=cg.course_id
    WHERE cg.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    UNION ALL
    SELECT 'Test' AS type, cg.exam_date AS d, cg.id AS gid, c.code, c.name
    FROM course_groups cg JOIN courses c ON c.id=cg.course_id
    WHERE cg.exam_date IS NOT NULL
      AND cg.exam_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
  ) x
  ORDER BY d ASC
  LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

/* Utilization by course (active only) */
$utilByCourse = $pdo->query("
  SELECT c.id, c.code, c.name,
         COUNT(DISTINCT cg.id) AS active_groups,
         COUNT(cgs.student_id) AS enrolled
  FROM courses c
  JOIN course_groups cg ON cg.course_id=c.id
    AND CURDATE() BETWEEN cg.start_date AND cg.end_date
  LEFT JOIN course_group_students cgs ON cgs.group_id=cg.id
  GROUP BY c.id
  ORDER BY enrolled DESC, c.code ASC
  LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

/* Recent users (fresh activity) */
$recentUsers = $pdo->query("
  SELECT u.full_name, u.email, u.created_at, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id=u.role_id
  ORDER BY u.created_at DESC
  LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

/* ===== Prep data for charts ===== */
$weeklyLabels = array_column($weekly, 'label');
$weeklyCounts = array_map('intv', array_column($weekly, 'cnt'));

/* Audit stacked */
$days = [];
foreach ($auditSeriesRows as $r) { $days[$r['d']] = true; }
$days = array_keys($days); sort($days);
$actions = ['INSERT','UPDATE','DELETE'];
$seriesAudit = [];
foreach ($actions as $a) {
  $vals = [];
  foreach ($days as $d) {
    $found = 0;
    foreach ($auditSeriesRows as $r) {
      if ($r['d']===$d && $r['action']===$a) { $found = (int)$r['cnt']; break; }
    }
    $vals[] = $found;
  }
  $seriesAudit[$a] = $vals;
}

/* Distributions */
$rolesLabels  = array_column($rolesDist, 'label');
$rolesCounts  = array_map('intv', array_column($rolesDist, 'cnt'));
$genderLabels = array_column($genderDist, 'label');
$genderCounts = array_map('intv', array_column($genderDist, 'cnt'));
$eduLabels    = array_column($eduDist, 'label');
$eduCounts    = array_map('intv', array_column($eduDist, 'cnt'));

/* Utilization arrays */
$utilLabels = array_map(fn($r)=> ($r['code']??'').' · '.($r['name']??''), $utilByCourse);
$utilGroups = array_map(fn($r)=> (int)$r['active_groups'], $utilByCourse);
$utilSeats  = array_map(fn($g)=> $g*10, $utilGroups);
$utilEnr    = array_map(fn($r)=> (int)$r['enrolled'], $utilByCourse);

/* Funnel */
$funnel = [
  'Gjithë studentët'         => $studentsTotal,
  'Në ndonjë grup'           => $studentsInAnyGroup,
  'Në grupe aktive (sot)'    => $studentsInActiveGroups,
  'Me kompani të caktuar'    => $studentsWithAgency,
];

$NAV_ACTIVE = 'dashboard';
require __DIR__ . '/inc/navbar.php';
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Dashboard i Ri – QTA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    body { background:#f5f7fb; padding-top:72px; }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
    .hero {
      border-radius:1.25rem; color:#0b1220;
      background:
        radial-gradient(900px 300px at 90% -20%, rgba(16,185,129,.18), rgba(16,185,129,0) 60%),
        radial-gradient(900px 300px at 10% -30%, rgba(59,130,246,.22), rgba(59,130,246,0) 55%),
        linear-gradient(135deg, #e0f2fe 0%, #eff6ff 100%);
    }
    .chip { background:#eef2ff; border:1px solid #e0e7ff; border-radius:999px; padding:.25rem .65rem; }
    .kpi .icon { width:46px; height:46px; border-radius:.75rem; display:flex; align-items:center; justify-content:center; background:#f1f5f9; }
    .mini-table thead { background:#f1f5f9; }
    .progress { height:8px; }
    .soft { background:#f8fafc; border:1px solid #e2e8f0; border-radius:.75rem; padding:.5rem .75rem; }
  </style>
</head>
<body>

<main class="container-fluid px-3 px-md-4">
  <!-- HERO -->
  <section class="hero p-4 p-md-5 mb-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="chip">Panel i ri</span>
          <span class="small text-muted">QTA • Qendra e Trajnimeve të Avancuara</span>
        </div>
        <h1 class="fw-bold mb-1">Përmbledhje operative</h1>
        <p class="mb-0 text-muted">Shëndeti i sistemit, aktiviteti i fundit dhe shfrytëzimi i kapaciteteve — në kohë reale.</p>
      </div>
      <div class="soft">
        <div class="small text-muted">Mirë se erdhe</div>
        <div class="h5 mb-0"><?= h($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Administrator')) ?></div>
      </div>
    </div>
  </section>

  <!-- KPI row -->
  <section class="row g-4 mb-4 kpi">
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100">
        <div class="d-flex align-items-center">
          <div class="icon me-3"><i class="bi bi-mortarboard fs-4 text-primary"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Studentë</div>
            <div class="h3 mb-1"><?= number_format($studentsTotal) ?></div>
            <span class="small text-muted">Në grupe aktive sot: <?= number_format($studentsInActiveGroups) ?></span>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100">
        <div class="d-flex align-items-center">
          <div class="icon me-3"><i class="bi bi-collection fs-4 text-success"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Grupe aktive</div>
            <div class="h3 mb-1"><?= number_format($activeGroups) ?></div>
            <span class="small text-muted">Kapacitet: <?= number_format($capacitySeats) ?> vende</span>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100">
        <div class="d-flex align-items-center">
          <div class="icon me-3"><i class="bi bi-activity fs-4 text-warning"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Shfrytëzim</div>
            <div class="h3 mb-1"><?= $fillRate!==null ? $fillRate.'%' : '—' ?></div>
            <span class="small text-muted">Regjistrime aktive: <?= number_format($activeEnrollments) ?></span>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card p-3 h-100">
        <div class="d-flex align-items-center">
          <div class="icon me-3"><i class="bi bi-shield-check fs-4 text-danger"></i></div>
          <div>
            <div class="small text-muted text-uppercase">Audit (24h)</div>
            <div class="h3 mb-1"><?= number_format($audit24h) ?></div>
            <span class="small text-muted">Përdorues: <?= number_format($usersTotal) ?> • Kompani: <?= number_format($agenciesTotal) ?></span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Trends & Distributions -->
  <section class="row g-4 mb-4">
    <div class="col-12 col-xl-7">
      <div class="card h-100">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Studentë të rinj (12 javë)</h5>
        </div>
        <div class="card-body">
          <canvas id="chartWeekly" height="120"></canvas>
        </div>
      </div>
    </div>
    <div class="col-12 col-xl-5">
      <div class="card h-100">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-clipboard-data me-2"></i>Aktivitet audit (14 ditë)</h5>
        </div>
        <div class="card-body">
          <canvas id="chartAudit" height="120"></canvas>
        </div>
      </div>
    </div>
  </section>

  <!-- Funnel + Utilization -->
  <section class="row g-4 mb-4">
    <div class="col-12 col-xl-5">
      <div class="card h-100">
        <div class="card-header bg-white">
          <h5 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Funneli i studentëve</h5>
        </div>
        <div class="card-body">
          <?php foreach ($funnel as $label=>$val): ?>
            <div class="mb-3">
              <div class="d-flex align-items-center justify-content-between">
                <div class="small text-muted"><?= h($label) ?></div>
                <div class="fw-semibold"><?= number_format($val) ?></div>
              </div>
              <?php
                $pct = ($studentsTotal>0) ? round(($val/$studentsTotal)*100) : 0;
              ?>
              <div class="progress"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>
            </div>
          <?php endforeach; ?>
          <div class="small text-muted">Shifra relative ndaj totalit të studentëve.</div>
        </div>
      </div>
    </div>
    <div class="col-12 col-xl-7">
      <div class="card h-100">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-bar-chart-steps me-2"></i>Përdorimi sipas modulit (aktive)</h5>
          <span class="text-muted small">Kufiri: 10 / grup</span>
        </div>
        <div class="card-body">
          <?php if ($utilByCourse): ?>
            <div class="table-responsive mini-table">
              <table class="table align-middle">
                <thead class="table-light">
                  <tr><th>Moduli</th><th class="nowrap">Gr. aktive</th><th>Mbushja</th></tr>
                </thead>
                <tbody>
                <?php foreach ($utilByCourse as $r):
                  $seats = (int)$r['active_groups'] * 10;
                  $enr   = (int)$r['enrolled'];
                  $p = $seats>0 ? min(100, round(($enr/$seats)*100)) : 0;
                ?>
                  <tr>
                    <td><?= h(($r['code']??'').' · '.($r['name']??'')) ?></td>
                    <td class="nowrap"><?= (int)$r['active_groups'] ?></td>
                    <td style="min-width:220px;">
                      <div class="d-flex align-items-center">
                        <div class="flex-grow-1 me-2">
                          <div class="progress"><div class="progress-bar" style="width:<?= $p ?>%"></div></div>
                        </div>
                        <span class="small text-muted"><?= $enr ?>/<?= $seats ?></span>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p class="text-muted mb-0">Nuk ka module me grupe aktive për momentin.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- Distributions row -->
  <section class="row g-4 mb-4">
    <div class="col-12 col-xl-4">
      <div class="card h-100">
        <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-people me-2"></i>Role përdoruesish</h6></div>
        <div class="card-body"><canvas id="chartRoles" height="140"></canvas></div>
      </div>
    </div>
    <div class="col-12 col-xl-4">
      <div class="card h-100">
        <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-gender-ambiguous me-2"></i>Gjinia (studentë)</h6></div>
        <div class="card-body"><canvas id="chartGender" height="140"></canvas></div>
      </div>
    </div>
    <div class="col-12 col-xl-4">
      <div class="card h-100">
        <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-mortarboard-fill me-2"></i>Niveli arsimor</h6></div>
        <div class="card-body"><canvas id="chartEdu" height="140"></canvas></div>
      </div>
    </div>
  </section>

  <!-- Leaderboards & Milestones & Security -->
  <section class="row g-4">
    <div class="col-12 col-xl-5">
      <div class="card h-100">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Kompani kryesuese</h5>
          <span class="text-muted small">sip. studentëve të caktuar</span>
        </div>
        <div class="card-body">
          <?php if ($agencyTop): ?>
            <div class="table-responsive mini-table">
              <table class="table align-middle">
                <thead class="table-light"><tr><th>Kompania</th><th>NIPT</th><th class="text-end">Studentë</th></tr></thead>
                <tbody>
                <?php foreach ($agencyTop as $a): ?>
                  <tr>
                    <td><?= h($a['company_name'] ?: '—') ?></td>
                    <td><code><?= h($a['nip_t']) ?></code></td>
                    <td class="text-end fw-semibold"><?= (int)$a['cnt'] ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p class="text-muted mb-0">S’ka të dhëna për kompanit.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-4">
      <div class="card h-100">
        <div class="card-header bg-white"><h5 class="mb-0"><i class="bi bi-calendar2-week me-2"></i>Milestones (30 ditë)</h5></div>
        <div class="card-body">
          <?php if ($milestones): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($milestones as $m): ?>
                <li class="list-group-item d-flex justify-content-between align-items-start">
                  <div>
                    <div class="fw-semibold"><?= h(($m['code'] ?? '').' · '.($m['name'] ?? '')) ?></div>
                    <div class="small text-muted"><?= h($m['type']) ?> • <?= h($m['d']) ?> • Grupi #<?= (int)$m['gid'] ?></div>
                  </div>
                  <span class="badge rounded-pill <?= $m['type']==='Fillim'?'text-bg-primary':'text-bg-success' ?>"><?= h($m['type']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="text-muted mb-0">Asnjë milestone në 30 ditët e ardhshme.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-3">
      <div class="card h-100">
        <div class="card-header bg-white"><h5 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Higjiena e llogarive</h5></div>
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="text-muted">Pa person të lidhur</span><span class="fw-semibold"><?= number_format($usersWithoutPerson) ?></span>
          </div>
          <div class="progress mb-3"><div class="progress-bar" style="width:<?= $usersTotal>0? round(($usersWithoutPerson/$usersTotal)*100):0 ?>%"></div></div>

          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="text-muted">Pa kredenciale</span><span class="fw-semibold"><?= number_format($usersNoCredentials) ?></span>
          </div>
          <div class="progress mb-3"><div class="progress-bar" style="width:<?= $usersTotal>0? round(($usersNoCredentials/$usersTotal)*100):0 ?>%"></div></div>

          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="text-muted">Fjalëkalim i vjetruar</span><span class="fw-semibold"><?= number_format($outdatedCreds) ?></span>
          </div>
          <div class="progress"><div class="progress-bar" style="width:<?= $usersTotal>0? round(($outdatedCreds/$usersTotal)*100):0 ?>%"></div></div>

          <div class="small text-muted mt-3">Rekomandim: ndryshim çdo 180 ditë; lidh person-in kur të jetë e mundur.</div>
        </div>
      </div>
    </div>
  </section>

  <!-- Recent users -->
  <section class="row g-4 my-4">
    <div class="col-12">
      <div class="card">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Aktivitet i fundit (përdorues të rinj)</h5>
        </div>
        <div class="card-body">
          <?php if ($recentUsers): ?>
            <div class="table-responsive mini-table">
              <table class="table align-middle">
                <thead class="table-light"><tr><th>Emri</th><th>Email</th><th>Roli</th><th class="nowrap">Krijuar më</th></tr></thead>
                <tbody>
                <?php foreach ($recentUsers as $ru): ?>
                  <tr>
                    <td><?= h($ru['full_name'] ?: '—') ?></td>
                    <td><?= h($ru['email'] ?: '—') ?></td>
                    <td><span class="badge text-bg-secondary"><?= h($ru['role_name'] ?? '') ?></span></td>
                    <td class="nowrap"><?= h($ru['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p class="text-muted mb-0">S’ka përdorues të rinj së fundmi.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <div class="text-center text-muted small my-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* PHP → JS */
const weeklyLabels = <?= json_encode($weeklyLabels) ?>;
const weeklyCounts = <?= json_encode($weeklyCounts) ?>;

const auditDays = <?= json_encode($days) ?>;
const auditInsert = <?= json_encode($seriesAudit['INSERT'] ?? []) ?>;
const auditUpdate = <?= json_encode($seriesAudit['UPDATE'] ?? []) ?>;
const auditDelete = <?= json_encode($seriesAudit['DELETE'] ?? []) ?>;

const rolesLabels  = <?= json_encode($rolesLabels) ?>;
const rolesCounts  = <?= json_encode($rolesCounts) ?>;
const genderLabels = <?= json_encode($genderLabels) ?>;
const genderCounts = <?= json_encode($genderCounts) ?>;
const eduLabels    = <?= json_encode($eduLabels) ?>;
const eduCounts    = <?= json_encode($eduCounts) ?>;

/* Charts */
(() => {
  const cw = document.getElementById('chartWeekly');
  if (cw) new Chart(cw, {
    type: 'line',
    data: { labels: weeklyLabels, datasets: [{ label:'Studentë të rinj', data: weeklyCounts, borderWidth:2, tension:.35, fill:true }] },
    options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} }, scales:{ x:{ grid:{display:false} }, y:{ beginAtZero:true } } }
  });

  const ca = document.getElementById('chartAudit');
  if (ca) new Chart(ca, {
    type: 'bar',
    data: {
      labels: auditDays,
      datasets: [
        { label: 'INSERT', data: auditInsert, stack:'a' },
        { label: 'UPDATE', data: auditUpdate, stack:'a' },
        { label: 'DELETE', data: auditDelete, stack:'a' }
      ]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } }, scales:{ x:{ stacked:true }, y:{ stacked:true, beginAtZero:true } } }
  });

  const cr = document.getElementById('chartRoles');
  if (cr) new Chart(cr, { type:'doughnut', data:{ labels: rolesLabels, datasets:[{ data: rolesCounts }] }, options:{ plugins:{ legend:{ position:'bottom' } } } });

  const cg = document.getElementById('chartGender');
  if (cg) new Chart(cg, { type:'doughnut', data:{ labels: genderLabels, datasets:[{ data: genderCounts }] }, options:{ plugins:{ legend:{ position:'bottom' } } } });

  const ce = document.getElementById('chartEdu');
  if (ce) new Chart(ce, { type:'doughnut', data:{ labels: eduLabels, datasets:[{ data: eduCounts }] }, options:{ plugins:{ legend:{ position:'bottom' } } } });
})();
</script>
</body>
</html>
