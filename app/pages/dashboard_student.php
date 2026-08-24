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
   Përgatitja e pamjes — vetëm nga $groups, që tashmë është kufizuar te ky
   kursant në pjesën e logjikës. Asnjë pyetje e re dhe asnjë fushë më tepër.
   ------------------------------------------------------------------------ */
$myScores = [];
foreach ($groups as $g) {
  if ($g['final_score'] !== null && $g['final_score'] !== '') {
    $myScores[] = (float)$g['final_score'];
  }
}
$avgScore = $myScores ? round(array_sum($myScores) / count($myScores), 1) : null;

/* Orët sipas modulit — renditur, si zërat e një dëftese. */
$byModule = [];
foreach ($groups as $g) {
  $key = (string)$g['course_code'];
  if (!isset($byModule[$key])) {
    $byModule[$key] = ['code' => $key, 'name' => (string)$g['course_name'], 'hours' => 0, 'n' => 0];
  }
  $byModule[$key]['hours'] += (int)$g['hours'];
  $byModule[$key]['n']++;
}
uasort($byModule, static fn($a, $b) => $b['hours'] <=> $a['hours']);
$moduleMax = 0;
foreach ($byModule as $m) { $moduleMax = max($moduleMax, (int)$m['hours']); }

$greetHour = (int)date('G');
$greeting  = $greetHour < 12 ? 'Mirëmëngjes' : ($greetHour < 18 ? 'Mirëdita' : 'Mirëmbrëma');
$whoShort  = trim((string)($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Kursant')));
?>

<main class="app-main">

  <div class="title-block">
    <div class="title-block-main">
      <div class="title-block-eyebrow">Kartela ime</div>
      <h1><?= h($greeting) ?>, <?= h($whoShort) ?></h1>
      <p class="title-block-note">Modulet ku je regjistruar, orët dhe rezultatet e tua.</p>
    </div>
    <div class="title-block-fields">
      <?php if (!empty($company)): ?>
        <div class="title-block-field">
          <span class="label">Agjencia</span>
          <span class="value" style="font-family:var(--font-record)"><?= h((string)$company) ?></span>
        </div>
      <?php endif; ?>
      <div class="title-block-field">
        <span class="label">Data</span>
        <span class="value"><?= h(date('d.m.Y')) ?></span>
      </div>
    </div>
  </div>

  <!-- ============================================================== SHIFRAT -->
  <section class="tally" aria-label="Përmbledhje personale">
    <div class="tally-cell">
      <span class="label">Modulet e mia</span>
      <span class="tally-value"><?= number_format((int)$k_total_groups) ?></span>
      <span class="tally-foot"><?= number_format((int)$k_active_groups) ?> në zhvillim</span>
    </div>
    <div class="tally-cell">
      <span class="label">Orë mësimore</span>
      <span class="tally-value"><?= number_format((int)$totalHours) ?></span>
      <span class="tally-foot">gjithsej</span>
    </div>
    <div class="tally-cell">
      <span class="label">Nota mesatare</span>
      <span class="tally-value"><?= $avgScore !== null ? h((string)$avgScore) : '—' ?></span>
      <span class="tally-foot"><?= count($myScores) ?> të vlerësuara</span>
    </div>
    <div class="tally-cell<?= (int)$k_upcoming_tests > 0 ? ' is-hold' : '' ?>">
      <span class="label">Provime në pritje</span>
      <span class="tally-value"><?= number_format((int)$k_upcoming_tests) ?></span>
      <span class="tally-foot">me datë të caktuar</span>
    </div>
  </section>

  <div class="row g-4 g-xl-5">

    <!-- ============================================================ MODULET -->
    <div class="col-12 col-xl-7">
      <section aria-labelledby="myModTitle">
        <div class="plate-head">
          <h2 id="myModTitle">Orë sipas modulit</h2>
          <span class="label"><?= count($byModule) ?> module</span>
        </div>

        <?php if ($byModule): ?>
          <div class="rank">
            <?php foreach ($byModule as $m):
              $pct = $moduleMax > 0 ? round((int)$m['hours'] / $moduleMax * 100) : 0; ?>
              <div class="rank-row" title="<?= h($m['name']) ?> — <?= (int)$m['hours'] ?> orë">
                <span class="code"><?= h($m['code']) ?></span>
                <span class="rank-name"><?= h($m['name']) ?></span>
                <span class="rank-n"><?= (int)$m['hours'] ?></span>
                <span class="rank-track"><span class="rank-bar" style="width:<?= $pct ?>%"></span></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="blank">
            <span class="blank-title">Ende pa module</span>
            <span class="blank-note">Sapo të caktohesh në një grup, moduli shfaqet këtu.</span>
          </div>
        <?php endif; ?>
      </section>
    </div>

    <!-- ============================================================ PIKËT -->
    <div class="col-12 col-xl-5">
      <section aria-labelledby="myScoreTitle">
        <div class="plate-head">
          <h2 id="myScoreTitle">Pikët e mia</h2>
          <span class="label"><?= count($myScores) ?> nga <?= count($groups) ?></span>
        </div>

        <?php if ($myScores): ?>
          <?php
          $myBands = ['90–100' => 0, '80–89' => 0, '70–79' => 0, 'nën 70' => 0];
          foreach ($myScores as $sc) {
            if ($sc >= 90)      { $myBands['90–100']++; }
            elseif ($sc >= 80)  { $myBands['80–89']++; }
            elseif ($sc >= 70)  { $myBands['70–79']++; }
            else                { $myBands['nën 70']++; }
          }
          $myBandMax = max($myBands);
          ?>
          <div class="spread mb-3">
            <?php foreach ($myBands as $band => $n):
              $pct = $myBandMax > 0 ? max(1, round($n / $myBandMax * 100)) : 1; ?>
              <div class="spread-row<?= ($n > 0 && $n === $myBandMax) ? ' is-top' : '' ?>">
                <span class="spread-band"><?= h($band) ?></span>
                <span class="spread-track"><span class="spread-bar" style="width:<?= $n > 0 ? $pct : 0 ?>%"></span></span>
                <span class="spread-n"><?= $n ?></span>
              </div>
            <?php endforeach; ?>
          </div>

          <dl style="margin:0">
            <div class="datarow"><dt>Mesatarja</dt><dd><?= h((string)$avgScore) ?></dd></div>
            <div class="datarow"><dt>Nota më e lartë</dt><dd><?= h((string)max($myScores)) ?></dd></div>
            <div class="datarow"><dt>Ende pa notë</dt><dd><?= max(0, count($groups) - count($myScores)) ?></dd></div>
          </dl>
        <?php else: ?>
          <div class="blank">
            <span class="blank-title">Ende pa rezultate</span>
            <span class="blank-note">Notat shfaqen pasi të mbahet provimi.</span>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </div>

  <!-- ======================================================== GRUPET E MIA -->
  <section class="mt-5" aria-labelledby="myGroupsTitle">
    <div class="plate-head">
      <h2 id="myGroupsTitle">Grupet e mia</h2>
      <span class="label"><?= count($groups) ?> zëra</span>
    </div>

    <?php if ($groups): ?>
      <div class="ledger">
        <table class="ledger-table">
          <thead>
            <tr>
              <th class="no">Nr.</th>
              <th>Moduli</th>
              <th>Kodi</th>
              <th>Orë</th>
              <th>Nisi</th>
              <th>Mbaroi</th>
              <th>Provimi</th>
              <th>Nota</th>
              <th>Gjendja</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groups as $i => $g):
              $score = $g['final_score'];
              $hasScore = $score !== null && $score !== '';
              $exam = !empty($g['my_exam']) ? date('d.m.Y', strtotime((string)$g['my_exam'])) : null;
              ?>
              <tr>
                <td class="no"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></td>
                <td><span class="person"><?= h((string)$g['course_name']) ?></span></td>
                <td class="code"><?= h((string)$g['course_code']) ?></td>
                <td class="num"><?= (int)$g['hours'] ?></td>
                <td class="num"><?= !empty($g['start_date']) ? h(date('d.m.Y', strtotime((string)$g['start_date']))) : '—' ?></td>
                <td class="num"><?= !empty($g['end_date']) ? h(date('d.m.Y', strtotime((string)$g['end_date']))) : '—' ?></td>
                <td class="num"><?= $exam ? h($exam) : '—' ?></td>
                <td class="num"><?= $hasScore ? h((string)$score) : '—' ?></td>
                <td>
                  <?php if ($hasScore): ?>
                    <span class="state state-valid">Vlerësuar</span>
                  <?php elseif ($exam): ?>
                    <span class="state state-hold">Provim i caktuar</span>
                  <?php else: ?>
                    <span class="state state-idle">Në ndjekje</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="blank">
        <span class="blank-title">Ende pa grupe</span>
        <span class="blank-note">Sapo administrata të të caktojë në një grup, ai shfaqet këtu.</span>
      </div>
    <?php endif; ?>
  </section>

  <!-- =========================================================== SHKO TE -->
  <section class="mt-5" aria-labelledby="stGoTitle">
    <div class="plate-head"><h2 id="stGoTitle">Shko te</h2></div>
    <div class="row g-2">
      <?php
      $shortcuts = [
        ['groups_student.php', 'Grupet e mia', 'Datat dhe rezultatet'],
        ['profile.php',        'Profili',      'Të dhënat e mia'],
        ['verify.php',         'Verifiko',     'Kontrollo një certifikatë'],
      ];
      foreach ($shortcuts as $sc): ?>
        <div class="col-12 col-sm-6 col-lg-4">
          <a class="shortcut" href="<?= h($sc[0]) ?>">
            <span><b><?= h($sc[1]) ?></b><span><?= h($sc[2]) ?></span></span>
            <i class="bi bi-arrow-right arrow"></i>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
</body>
</html>
