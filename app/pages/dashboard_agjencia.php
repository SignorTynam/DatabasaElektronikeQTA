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

$pageTitle = 'Dashboard i Kompanisë – QTA';
require __DIR__ . '/../shared/app_head.php';
?>


<?php require __DIR__ . '/inc/navbar2.php'; ?>

<main class="app-main">

  <?php
  /* Pamja mbështetet vetëm te variablat që logjika sipër i ka kufizuar
     tashmë te kjo agjenci. Asnjë pyetje e re. */
  $agencyName = trim((string)($COMPANY['name'] ?? $COMPANY['company_name'] ?? ''));
  $agencyNipt = trim((string)($COMPANY['nipt'] ?? ''));

  $weeklyMax = 0;
  foreach (($weekly ?? []) as $w) { $weeklyMax = max($weeklyMax, (int)$w['cnt']); }

  $topMax = 0;
  foreach (($topCourses ?? []) as $c) { $topMax = max($topMax, (int)$c['total_students']); }

  $greetHour = (int)date('G');
  $greeting  = $greetHour < 12 ? 'Mirëmëngjes' : ($greetHour < 18 ? 'Mirëdita' : 'Mirëmbrëma');
  $whoShort  = trim((string)($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Agjenci')));
  ?>

  <div class="title-block">
    <div class="title-block-main">
      <div class="title-block-eyebrow">Fleta e agjencisë</div>
      <h1><?= h($greeting) ?><?= $agencyName !== '' ? ', ' . h($agencyName) : ', ' . h($whoShort) ?></h1>
      <p class="title-block-note">Punonjësit që keni regjistruar dhe grupet ku janë caktuar.</p>
    </div>
    <div class="title-block-fields">
      <?php if ($agencyNipt !== ''): ?>
        <div class="title-block-field">
          <span class="label">NIPT</span>
          <span class="value"><?= h($agencyNipt) ?></span>
        </div>
      <?php endif; ?>
      <div class="title-block-field">
        <span class="label">Data</span>
        <span class="value"><?= h(date('d.m.Y')) ?></span>
      </div>
    </div>
  </div>

  <!-- ============================================================== SHIFRAT -->
  <section class="tally" aria-label="Gjendja e agjencisë">
    <div class="tally-cell">
      <span class="label">Punonjës</span>
      <span class="tally-value"><?= number_format((int)$studentsTotal) ?></span>
      <span class="tally-foot">të regjistruar</span>
    </div>
    <div class="tally-cell">
      <span class="label">Grupe</span>
      <span class="tally-value"><?= number_format((int)$groupsCnt) ?></span>
      <span class="tally-foot"><?= number_format((int)$activeGroupsToday) ?> në zhvillim</span>
    </div>
    <div class="tally-cell">
      <span class="label">Module</span>
      <span class="tally-value"><?= number_format((int)$coursesCnt) ?></span>
      <span class="tally-foot">zanate të ndjekura</span>
    </div>
    <div class="tally-cell<?= (int)$noGroupCnt > 0 ? ' is-hold' : '' ?>">
      <span class="label">Pa grup</span>
      <span class="tally-value"><?= number_format((int)$noGroupCnt) ?></span>
      <span class="tally-foot">presin caktim</span>
    </div>
  </section>

  <!-- =============================================================== RITMI -->
  <?php if (!empty($weekly)): ?>
    <section class="mb-5" aria-labelledby="agRhythm">
      <div class="plate-head">
        <h2 id="agRhythm">Ritmi i regjistrimeve</h2>
        <span class="label">Punonjës për javë</span>
      </div>
      <div class="bars">
        <?php foreach ($weekly as $w):
          $n = (int)$w['cnt'];
          $pct = $weeklyMax > 0 ? max(2, round($n / $weeklyMax * 100)) : 2; ?>
          <div class="bar<?= $n === $weeklyMax ? ' is-peak' : '' ?>" title="<?= h((string)$w['label']) ?>: <?= $n ?>">
            <span class="bar-value"><?= $n ?></span>
            <span class="bar-fill" style="height:<?= $pct ?>%"></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="bars-axis">
        <?php foreach ($weekly as $w):
          $lbl = (string)$w['label'];
          $short = (strpos($lbl, '-W') !== false) ? substr($lbl, strpos($lbl, '-W') + 1) : $lbl; ?>
          <span><?= h($short) ?></span>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <div class="row g-4 g-xl-5">

    <!-- ============================================================ MODULET -->
    <div class="col-12 col-xl-7">
      <section aria-labelledby="agMod">
        <div class="plate-head">
          <h2 id="agMod">Zanatet e punonjësve tuaj</h2>
          <a class="label" href="groups_agjencia.php">Grupet →</a>
        </div>

        <?php if (!empty($topCourses)): ?>
          <div class="rank">
            <?php foreach ($topCourses as $c):
              $n = (int)$c['total_students'];
              $pct = $topMax > 0 ? round($n / $topMax * 100) : 0; ?>
              <div class="rank-row" title="<?= h((string)$c['name']) ?> — <?= $n ?> punonjës">
                <span class="code"><?= h((string)$c['code']) ?></span>
                <span class="rank-name"><?= h((string)$c['name']) ?></span>
                <span class="rank-n"><?= $n ?></span>
                <span class="rank-track"><span class="rank-bar" style="width:<?= $pct ?>%"></span></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="blank">
            <span class="blank-title">Ende pa module</span>
            <span class="blank-note">Sapo punonjësit të caktohen në grupe, zanatet shfaqen këtu.</span>
          </div>
        <?php endif; ?>
      </section>
    </div>

    <!-- ============================================================= PA GRUP -->
    <div class="col-12 col-xl-5">
      <section aria-labelledby="agNoGroup">
        <div class="plate-head">
          <h2 id="agNoGroup">Presin caktim</h2>
          <span class="label"><?= number_format((int)$noGroupCnt) ?> punonjës</span>
        </div>

        <?php if (!empty($noGroupList)): ?>
          <div class="action-list">
            <?php foreach (array_slice($noGroupList, 0, 8) as $i => $s):
              $full = trim(((string)($s['first_name'] ?? '')) . ' ' . ((string)($s['last_name'] ?? ''))); ?>
              <a class="action-row" href="register_agjencia.php">
                <span class="no"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                <span class="action-main">
                  <b style="font-family:var(--font-record);font-weight:500"><?= h($full !== '' ? $full : '—') ?></b>
                  <span class="code"><?= h((string)($s['nr_amze'] ?? '')) ?></span>
                </span>
                <span class="action-count" style="font-size:var(--fs-sm)">
                  <span class="state state-hold">Pa grup</span>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
          <?php if (count($noGroupList) > 8): ?>
            <p class="muted" style="font-size:var(--fs-xs);margin-top:.7rem">
              Edhe <?= number_format(count($noGroupList) - 8) ?> të tjerë —
              <a href="register_agjencia.php">shihi të gjithë</a>.
            </p>
          <?php endif; ?>
        <?php else: ?>
          <div class="blank">
            <span class="blank-title">Të gjithë janë caktuar</span>
            <span class="blank-note">Asnjë punonjës nuk pret caktim në grup.</span>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </div>

  <!-- ============================================================== GRUPET -->
  <?php if (!empty($groupsCap)): ?>
    <section class="mt-5" aria-labelledby="agGroups">
      <div class="plate-head">
        <h2 id="agGroups">Grupet ku keni punonjës</h2>
        <span class="label"><?= count($groupsCap) ?> zëra</span>
      </div>

      <div class="ledger">
        <table class="ledger-table">
          <thead>
            <tr>
              <th class="no">Nr.</th>
              <th>Moduli</th>
              <th>Kodi</th>
              <th>Nisi</th>
              <th>Mbaron</th>
              <th>Punonjësit tuaj</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groupsCap as $i => $g): ?>
              <tr>
                <td class="no"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></td>
                <td><span class="person"><?= h((string)($g['name'] ?? $g['course_name'] ?? '—')) ?></span></td>
                <td class="code"><?= h((string)($g['code'] ?? $g['course_code'] ?? '')) ?></td>
                <td class="num"><?= !empty($g['start_date']) ? h(date('d.m.Y', strtotime((string)$g['start_date']))) : '—' ?></td>
                <td class="num"><?= !empty($g['end_date']) ? h(date('d.m.Y', strtotime((string)$g['end_date']))) : '—' ?></td>
                <td class="num"><?= number_format((int)($g['cnt_company'] ?? 0)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>

  <!-- ============================================================= SHKO TE -->
  <section class="mt-5" aria-labelledby="agGo">
    <div class="plate-head"><h2 id="agGo">Shko te</h2></div>
    <div class="row g-2">
      <?php
      $shortcuts = [
        ['register_agjencia.php', 'Punonjësit',  'Regjistrimi dhe të dhënat'],
        ['groups_agjencia.php',   'Grupet',      'Ku janë caktuar'],
        ['profile.php',           'Profili',     'Të dhënat e agjencisë'],
        ['verify.php',            'Verifiko',    'Kontrollo një certifikatë'],
      ];
      foreach ($shortcuts as $sc): ?>
        <div class="col-12 col-sm-6 col-lg-3">
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
