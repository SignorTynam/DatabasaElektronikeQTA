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

$pageTitle = 'Kartela ime – Regjistri QTA';
require __DIR__ . '/../shared/app_head.php';

/* ---------------------------------------------------------------------------
   Vetëm nga $groups, që logjika sipër e ka kufizuar te ky kursant.
   Asnjë pyetje e re dhe asnjë fushë më tepër.
   ------------------------------------------------------------------------ */
$myScores = [];
foreach ($groups as $g) {
  if ($g['final_score'] !== null && $g['final_score'] !== '') { $myScores[] = (float)$g['final_score']; }
}
$avgScore = $myScores ? round(array_sum($myScores) / count($myScores), 1) : null;

/* Çfarë pret kursantin: provime të caktuara që ende s'kanë notë. */
$upcomingExams = [];
foreach ($groups as $g) {
  $hasScore = $g['final_score'] !== null && $g['final_score'] !== '';
  if (!$hasScore && !empty($g['my_exam'])) { $upcomingExams[] = $g; }
}
usort($upcomingExams, static fn($a, $b) => strcmp((string)$a['my_exam'], (string)$b['my_exam']));

$greetHour = (int)date('G');
$greeting  = $greetHour < 12 ? 'Mirëmëngjes' : ($greetHour < 18 ? 'Mirëdita' : 'Mirëmbrëma');
$whoShort  = trim((string)($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Kursant')));
?>

<main class="app-main">

  <div class="title-block">
    <div class="title-block-main">
      <div class="title-block-eyebrow">Kartela ime</div>
      <h1><?= h($greeting) ?>, <?= h($whoShort) ?></h1>
    </div>
    <div class="title-block-fields">
      <?php if (!empty($company)): ?>
        <div class="title-block-field">
          <span class="label">Agjencia</span>
          <span class="value" style="font-family:var(--font-record)"><?= h((string)$company) ?></span>
        </div>
      <?php endif; ?>
      <div class="title-block-field">
        <span class="label">Sot</span>
        <span class="value"><?= h(date('d.m.Y')) ?></span>
      </div>
    </div>
  </div>

  <!-- ============================================================ VEPRIMET -->
  <section class="mb-5" aria-labelledby="actTitle">
    <div class="plate-head"><h2 id="actTitle">Çfarë mund të bësh</h2></div>
    <div class="actions">
      <a class="act act-primary" href="groups_student.php">
        <i class="bi bi-mortarboard" aria-hidden="true"></i>
        <b>Modulet e mia</b><span>Datat, provimet dhe pikët</span>
      </a>
      <a class="act act-primary" href="verify.php">
        <i class="bi bi-patch-check" aria-hidden="true"></i>
        <b>Verifiko certifikatën</b><span>Kontrollo me kod ose QR</span>
      </a>
      <a class="act" href="profile.php">
        <i class="bi bi-person" aria-hidden="true"></i>
        <b>Profili im</b><span>Të dhënat e mia</span>
      </a>
      <a class="act" href="contact.php">
        <i class="bi bi-envelope" aria-hidden="true"></i>
        <b>Shkruaji QTA-së</b><span>Për pyetje ose ndreqje të dhënash</span>
      </a>
    </div>
  </section>

  <!-- ========================================================= PRET PËR TY -->
  <?php if ($upcomingExams): ?>
    <section class="mb-5" aria-labelledby="waitTitle">
      <div class="plate-head">
        <h2 id="waitTitle">Provime të caktuara</h2>
        <span class="label"><?= count($upcomingExams) ?> në pritje</span>
      </div>
      <div class="waiting">
        <?php foreach (array_slice($upcomingExams, 0, 5) as $g): ?>
          <a class="wait-row" href="groups_student.php">
            <span class="wait-n" style="font-size:var(--fs-sm);min-width:5rem">
              <?= h(date('d.m.Y', strtotime((string)$g['my_exam']))) ?>
            </span>
            <span class="wait-main">
              <b><?= h((string)$g['course_name']) ?></b>
              <span>Ende pa notë të regjistruar.</span>
            </span>
            <span class="wait-go">Shiko →</span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- ======================================================== MODULET E MIA -->
  <section aria-labelledby="myTitle">
    <div class="plate-head">
      <h2 id="myTitle">Modulet e mia</h2>
      <a class="label" href="groups_student.php">Të plota →</a>
    </div>

    <?php if ($groups): ?>
      <div class="ledger">
        <table class="ledger-table" data-sortable>
          <thead>
            <tr>
              <th class="no" data-sort="none">Nr.</th>
              <th data-sort="text">Moduli</th>
              <th class="nowrap" data-sort="date">Nisi</th>
              <th class="nowrap" data-sort="date">Mbaroi</th>
              <th class="nowrap" data-sort="date">Provimi</th>
              <th class="nowrap num-col" data-sort="num">Nota</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groups as $i => $g):
              $score = $g['final_score'];
              $hasScore = $score !== null && $score !== ''; ?>
              <tr>
                <td class="no"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></td>
                <td><span class="person"><?= h((string)$g['course_name']) ?></span></td>
                <td class="nowrap num"><?= !empty($g['start_date']) ? h(date('d.m.Y', strtotime((string)$g['start_date']))) : '—' ?></td>
                <td class="nowrap num"><?= !empty($g['end_date']) ? h(date('d.m.Y', strtotime((string)$g['end_date']))) : '—' ?></td>
                <td class="nowrap num"><?= !empty($g['my_exam']) ? h(date('d.m.Y', strtotime((string)$g['my_exam']))) : '—' ?></td>
                <td class="nowrap num-col num"><?= $hasScore ? h((string)$score) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($avgScore !== null): ?>
        <p class="muted mt-3" style="font-size:var(--fs-xs)">
          Mesatarja jote: <b class="code" style="color:var(--ink)"><?= h((string)$avgScore) ?></b>
          nga <?= count($myScores) ?> module të vlerësuara.
        </p>
      <?php endif; ?>

    <?php else: ?>
      <div class="blank">
        <span class="blank-title">Ende pa module</span>
        <span class="blank-note">Sapo administrata të të caktojë në një grup, moduli shfaqet këtu.</span>
      </div>
    <?php endif; ?>
  </section>

</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
</body>
</html>
