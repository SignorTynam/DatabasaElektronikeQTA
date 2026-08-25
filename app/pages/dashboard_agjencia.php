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
  /* Vetëm nga variablat që logjika sipër i ka kufizuar te kjo agjenci. */
  $agencyName = trim((string)($COMPANY['name'] ?? $COMPANY['company_name'] ?? ''));
  $agencyNipt = trim((string)($COMPANY['nipt'] ?? ''));

  $greetHour = (int)date('G');
  $greeting  = $greetHour < 12 ? 'Mirëmëngjes' : ($greetHour < 18 ? 'Mirëdita' : 'Mirëmbrëma');
  $whoShort  = trim((string)($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Agjenci')));
  ?>

  <div class="title-block">
    <div class="title-block-main">
      <div class="title-block-eyebrow">Fleta e agjencisë</div>
      <h1><?= h($greeting) ?><?= $agencyName !== '' ? ', ' . h($agencyName) : ', ' . h($whoShort) ?></h1>
    </div>
    <div class="title-block-fields">
      <?php if ($agencyNipt !== ''): ?>
        <div class="title-block-field">
          <span class="label">NIPT</span>
          <span class="value"><?= h($agencyNipt) ?></span>
        </div>
      <?php endif; ?>
      <div class="title-block-field">
        <span class="label">Sot</span>
        <span class="value"><?= h(date('d.m.Y')) ?></span>
      </div>
    </div>
  </div>

  <!-- ====================================================== KËRKIMI I SHPEJTË -->
  <section class="jump" aria-labelledby="jumpTitle">
    <h2 id="jumpTitle" class="visually-hidden">Gjej një punonjës</h2>
    <form class="jump-form" method="get" action="register_agjencia.php">
      <div class="jump-field">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="text" name="q" autofocus
               placeholder="Gjej punonjës — emër, numër amze ose numër personal"
               aria-label="Gjej punonjës">
      </div>
      <button class="btn btn-ink" type="submit">Kërko</button>
    </form>
  </section>

  <!-- ============================================================ VEPRIMET -->
  <section class="mb-5" aria-labelledby="actTitle">
    <div class="plate-head"><h2 id="actTitle">Çfarë mund të bësh</h2></div>
    <div class="actions">
      <a class="act act-primary" href="register_agjencia.php">
        <i class="bi bi-person-plus" aria-hidden="true"></i>
        <b>Punonjësit e mi</b><span>Regjistro dhe shiko të dhënat</span>
      </a>
      <a class="act act-primary" href="groups_agjencia.php">
        <i class="bi bi-collection" aria-hidden="true"></i>
        <b>Grupet</b><span>Ku janë caktuar punonjësit</span>
      </a>
      <a class="act" href="verify.php">
        <i class="bi bi-patch-check" aria-hidden="true"></i>
        <b>Verifiko</b><span>Kontrollo një certifikatë</span>
      </a>
      <a class="act" href="profile.php">
        <i class="bi bi-building" aria-hidden="true"></i>
        <b>Profili</b><span>Të dhënat e agjencisë</span>
      </a>
    </div>
  </section>

  <!-- ========================================================= PRET PËR TY -->
  <?php if (!empty($noGroupList)): ?>
    <section class="mb-5" aria-labelledby="waitTitle">
      <div class="plate-head">
        <h2 id="waitTitle">Presin caktim në grup</h2>
        <span class="label"><?= number_format((int)$noGroupCnt) ?> punonjës</span>
      </div>
      <div class="waiting">
        <?php foreach (array_slice($noGroupList, 0, 6) as $s):
          $full = trim(((string)($s['first_name'] ?? '')) . ' ' . ((string)($s['last_name'] ?? ''))); ?>
          <a class="wait-row" href="register_agjencia.php">
            <span class="wait-n code" style="font-size:var(--fs-sm)"><?= h((string)($s['nr_amze'] ?? '')) ?></span>
            <span class="wait-main">
              <b style="font-family:var(--font-record);font-weight:500"><?= h($full !== '' ? $full : '—') ?></b>
              <span>Ende pa grup të caktuar.</span>
            </span>
            <span class="wait-go">Shiko →</span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if (count($noGroupList) > 6): ?>
        <p class="muted mt-2" style="font-size:var(--fs-xs)">
          Edhe <?= number_format(count($noGroupList) - 6) ?> të tjerë —
          <a href="register_agjencia.php">shihi të gjithë</a>.
        </p>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <!-- ============================================================== GRUPET -->
  <?php if (!empty($groupsCap)): ?>
    <section aria-labelledby="agGroups">
      <div class="plate-head">
        <h2 id="agGroups">Grupet ku keni punonjës</h2>
        <a class="label" href="groups_agjencia.php">Të plota →</a>
      </div>

      <div class="ledger">
        <table class="ledger-table" data-sortable>
          <thead>
            <tr>
              <th class="no" data-sort="none">Nr.</th>
              <th data-sort="text">Moduli</th>
              <th class="nowrap" data-sort="date">Nisi</th>
              <th class="nowrap" data-sort="date">Mbaron</th>
              <th class="nowrap num-col" data-sort="num">Punonjësit tuaj</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groupsCap as $i => $g): ?>
              <tr>
                <td class="no"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></td>
                <td><span class="person"><?= h((string)($g['name'] ?? $g['course_name'] ?? '—')) ?></span></td>
                <td class="nowrap num"><?= !empty($g['start_date']) ? h(date('d.m.Y', strtotime((string)$g['start_date']))) : '—' ?></td>
                <td class="nowrap num"><?= !empty($g['end_date']) ? h(date('d.m.Y', strtotime((string)$g['end_date']))) : '—' ?></td>
                <td class="nowrap num-col num"><?= number_format((int)($g['cnt_company'] ?? 0)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>

</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
</body>
</html>
