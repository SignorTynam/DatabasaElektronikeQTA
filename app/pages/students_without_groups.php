<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* ------------------------------
   Guard: admin/editor i loguar
------------------------------- */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);
$role = strtolower((string)($currentUser['role_name'] ?? ''));
if (!$currentUser || !in_array($role, ['administrator','editor'], true)) {
  header('Location: selectProfile.php'); exit;
}

/* ------------------------------
   CSRF
------------------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

/* ------------------------------
   Helpers
------------------------------- */
require_once __DIR__ . '/../shared/themeli.php';
function json_response(array $payload): void {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==========================================================
   AJAX (POST JSON) — e trajtojmë këtu (nuk përdorim skedar tjetër)
   Veprime:
   - assign_to_group: vendos studentin në grup, heq çdo plan "planned"
   - set_student_plan: ndërron/ vendos modulin "planned" (upsert)
   - remove_student_plan: heq studentin nga një modul (fshin planin 'planned')
========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
  $payload = json_decode(file_get_contents('php://input'), true) ?: [];
  try {
    if (empty($payload['csrf']) || !hash_equals($_SESSION['csrf_token'], (string)$payload['csrf'])) {
      throw new RuntimeException('Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.');
    }
    $action = (string)($payload['action'] ?? '');

    /* --- Common checks/helpers --- */
    $getPersonPN = $pdo->prepare("SELECT p.personal_number FROM students s JOIN persons p ON p.id=s.person_id WHERE s.id=:sid");
    $hasAttendedCoursePN = function(int $course_id, ?string $pn) use ($pdo): bool {
      if (!$pn) return false;
      $q = $pdo->prepare("
        SELECT 1
        FROM course_group_students cgs
        JOIN students s ON s.id=cgs.student_id
        JOIN persons  p ON p.id=s.person_id
        JOIN course_groups cg ON cg.id=cgs.group_id
        WHERE cg.course_id=:c AND p.personal_number=:pn
        LIMIT 1
      ");
      $q->execute([':c'=>$course_id, ':pn'=>$pn]);
      return (bool)$q->fetchColumn();
    };

    if ($action === 'assign_to_group') {
      $student_id = (int)($payload['student_id'] ?? 0);
      $group_id   = (int)($payload['group_id']   ?? 0);
      if ($student_id<=0 || $group_id<=0) throw new RuntimeException('Zgjidh kursantin dhe grupin.');

      // Group exists and capacity <10
      $gq = $pdo->prepare("SELECT cg.course_id, cg.start_date, cg.end_date, COUNT(cgs.student_id) AS members
                           FROM course_groups cg
                           LEFT JOIN course_group_students cgs ON cgs.group_id=cg.id
                           WHERE cg.id=:g GROUP BY cg.id");
      $gq->execute([':g'=>$group_id]);
      $g = $gq->fetch(PDO::FETCH_ASSOC);
      if (!$g) throw new RuntimeException('Grupi nuk u gjet.');
      if ((int)$g['members'] >= 10) throw new RuntimeException('Ky grup është plot (10 kursantë). Zgjidh një grup tjetër.');

      // Student not already in this group
      $exists = $pdo->prepare("SELECT 1 FROM course_group_students WHERE group_id=:g AND student_id=:s");
      $exists->execute([':g'=>$group_id, ':s'=>$student_id]);
      if ($exists->fetchColumn()) throw new RuntimeException('Ky kursant është tashmë në këtë grup.');

      // Ndalim: i njëjti person nuk duhet ta ketë ndjekur më parë këtë modul
      $getPersonPN->execute([':sid'=>$student_id]);
      $pn = $getPersonPN->fetchColumn();
      if ($hasAttendedCoursePN((int)$g['course_id'], $pn)) {
        throw new RuntimeException('Ky person e ka ndjekur më parë këtë modul, prandaj nuk mund ta ndjekë sërish.');
      }

      $pdo->beginTransaction();
      // RULE: Një AMZË nuk duhet të jetë njëkohësisht me modul (plan) dhe në grup -> fshijmë çdo "planned"
      $pdo->prepare("DELETE FROM student_course_plans WHERE student_id=:s AND status='planned'")->execute([':s'=>$student_id]);

      // Vendos në grup
      $ins = $pdo->prepare("INSERT INTO course_group_students (group_id, student_id) VALUES (:g,:s)");
      $ins->execute([':g'=>$group_id, ':s'=>$student_id]);
      $pdo->commit();

      // Flash për toast pas rifreskimit
      $_SESSION['flash_ok'] = 'Kursanti u caktua në grup.';
      json_response(['ok'=>true, 'message'=>'Kursanti u caktua në grup.']);
    }

    if ($action === 'set_student_plan') {
      $student_id = (int)($payload['student_id'] ?? 0);
      $course_id  = (int)($payload['course_id']  ?? 0);
      if ($student_id<=0 || $course_id<=0) throw new RuntimeException('Zgjidh kursantin dhe modulin.');

      // S’lejohet plan nëse studenti është në ndonjë grup
      $inGroup = $pdo->prepare("SELECT 1 FROM course_group_students WHERE student_id=:s LIMIT 1");
      $inGroup->execute([':s'=>$student_id]);
      if ($inGroup->fetchColumn()) {
        throw new RuntimeException('Ky kursant është në një grup. Hiqe nga grupi para se të ndryshosh modulin.');
      }

      // Ndalim: i njëjti person s’mund ta ketë ndjekur (në grupe) të njëjtin modul
      $getPersonPN->execute([':sid'=>$student_id]);
      $pn = $getPersonPN->fetchColumn();
      if ($hasAttendedCoursePN($course_id, $pn)) {
        throw new RuntimeException('Ky person e ka ndjekur më parë këtë modul. Zgjidh një modul tjetër.');
      }

      $pdo->beginTransaction();

      // Hiq çdo plan TË TJERË (kurse të tjerë) që ka studenti,
      // por mos prek rreshtin ekzistues për këtë kurs target (për të shmangur konfliktin unik).
      $pdo->prepare("
        DELETE FROM student_course_plans
        WHERE student_id = :s AND status = 'planned' AND course_id <> :c
      ")->execute([':s'=>$student_id, ':c'=>$course_id]);

      // UPSERT: nëse ekziston rresht për (student_id, course_id), thjesht përditëso statusin në 'planned'
      $upsert = $pdo->prepare("
        INSERT INTO student_course_plans (student_id, course_id, status)
        VALUES (:s, :c, 'planned')
        ON DUPLICATE KEY UPDATE status = VALUES(status)
      ");
      $upsert->execute([':s'=>$student_id, ':c'=>$course_id]);

      $pdo->commit();

      // Flash për toast pas rifreskimit
      $_SESSION['flash_ok'] = 'Moduli u ruajt. Tani zgjidh grupin.';
      json_response(['ok'=>true, 'message'=>'Moduli u ruajt. Tani zgjidh grupin.']);
    }

    /* NEW: Hiq studentin nga një modul (fshi planin e modulit) */
    if ($action === 'remove_student_plan') {
      $student_id = (int)($payload['student_id'] ?? 0);
      $course_id  = (int)($payload['course_id']  ?? 0);
      if ($student_id<=0 || $course_id<=0) throw new RuntimeException('Zgjidh kursantin dhe modulin.');

      // Student nuk duhet të jetë në ndonjë grup të këtij moduli
      $inGroup = $pdo->prepare("SELECT 1 FROM course_group_students cgs JOIN course_groups cg ON cg.id=cgs.group_id WHERE cgs.student_id=:s AND cg.course_id=:c LIMIT 1");
      $inGroup->execute([':s'=>$student_id, ':c'=>$course_id]);
      if ($inGroup->fetchColumn()) {
        throw new RuntimeException('Ky kursant është në një grup të këtij moduli. Hiqe nga grupi së pari.');
      }

      // Fshi vetëm planet 'planned' për këtë modul
      $del = $pdo->prepare("DELETE FROM student_course_plans WHERE student_id=:s AND course_id=:c AND status='planned'");
      $del->execute([':s'=>$student_id, ':c'=>$course_id]);

      if ($del->rowCount() < 1) {
        throw new RuntimeException('Ky kursant nuk ka modul të zgjedhur.');
      }

      // Flash për toast pas rifreskimit
      $_SESSION['flash_ok'] = 'Moduli u hoq. Zgjidh një modul tjetër kur të jesh gati.';
      json_response(['ok'=>true, 'message'=>'Moduli u hoq. Zgjidh një modul tjetër kur të jesh gati.']);
    }

    throw new RuntimeException('Ky veprim nuk njihet. Rifresko faqen dhe provo sërish.');
  } catch (Throwable $e) {
    // Për gabimet NUK vendosim flash_err, sepse s’po rifreskojmë faqen në frontend.
    json_response(['ok'=>false, 'error'=>$e->getMessage()]);
  }
}

/* ------------------------------
   EDIT MODE toggle (persistohet në session)
------------------------------- */
if (isset($_GET['edit'])) {
  $_SESSION['edit_mode'] = filter_var($_GET['edit'], FILTER_VALIDATE_BOOLEAN);
  $qs = $_GET; unset($qs['edit']);
  $url = 'students_without_groups.php' . (empty($qs) ? '' : ('?' . http_build_query($qs)));
  header("Location: $url"); exit;
}
$EDIT_MODE = (bool)($_SESSION['edit_mode'] ?? false);

/* ------------------------------
   Filtro/Kërko
------------------------------- */
$q = trim($_GET['q'] ?? '');
$courseFilter = trim($_GET['course_id'] ?? '');  // opsional

/* Dropdown kurse */
$courses = $pdo->query("SELECT id, name FROM courses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* Info e grupeve (për dropdown/zgjedhje) */
$groupsMeta = $pdo->query("
  SELECT
    cg.id,
    cg.course_id,
    c.name AS course_name,
    cg.start_date, cg.end_date, cg.is_completed,
    COUNT(cgs.student_id) AS members
  FROM course_groups cg
  JOIN courses c ON c.id = cg.course_id
  LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
  GROUP BY cg.id
  ORDER BY c.name ASC, cg.start_date DESC, cg.id DESC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($groupsMeta as &$gm) { $gm['full'] = ((int)$gm['members'] >= 10); } unset($gm);

/* Harta: course_id => lista grupeve */
$groupsByCourse = [];
foreach ($groupsMeta as $gm) {
  $groupsByCourse[(int)$gm['course_id']][] = $gm;
}

/* ===== Banner metrics ===== */
$totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();

/* (A) Pa grup fare */
$countNoGroup = (int)$pdo->query("
  SELECT COUNT(*)
  FROM students s
  LEFT JOIN course_group_students cgs ON cgs.student_id = s.id
  WHERE cgs.student_id IS NULL
")->fetchColumn();

/* (B) Me modul (plan) por pa grup */
$countPlannedNoGroup = (int)$pdo->query("
  SELECT COUNT(DISTINCT s.id)
  FROM students s
  LEFT JOIN course_group_students cgs ON cgs.student_id = s.id
  JOIN student_course_plans scp
    ON scp.student_id = s.id AND scp.status = 'planned'
  WHERE cgs.student_id IS NULL
")->fetchColumn();

/* (C) Pa modul dhe pa grup */
$countNoPlanNoGroup = (int)$pdo->query("
  SELECT COUNT(*) FROM (
    SELECT s.id
    FROM students s
    LEFT JOIN course_group_students cgs ON cgs.student_id = s.id
    LEFT JOIN student_course_plans scp
      ON scp.student_id = s.id AND scp.status = 'planned'
    GROUP BY s.id
    HAVING COUNT(cgs.group_id)=0 AND COUNT(scp.course_id)=0
  ) t
")->fetchColumn();

/* ------------------------------
   Query: STUDENTË ME MODUL (PLAN) PA GRUP
------------------------------- */
$paramsM = [];
$whereM = ["1=1"];
if ($q !== '') {
  $whereM[] = "(s.nr_amze LIKE :kw
        OR p.personal_number LIKE :kw2
        OR p.first_name LIKE :kw3
        OR p.father_name LIKE :kw4
        OR p.last_name LIKE :kw5)";
  $paramsM[':kw']  = '%'.$q.'%';
  $paramsM[':kw2'] = '%'.$q.'%';
  $paramsM[':kw3'] = '%'.$q.'%';
  $paramsM[':kw4'] = '%'.$q.'%';
  $paramsM[':kw5'] = '%'.$q.'%';
}
if ($courseFilter !== '' && ctype_digit($courseFilter)) {
  $whereM[] = "scp.course_id = :cf";
  $paramsM[':cf'] = (int)$courseFilter;
}
$whereMsql = 'WHERE '.implode(' AND ', $whereM);

$sqlPlannedNoGroup = "
  SELECT
    scp.course_id,
    c.name AS course_name,

    s.id AS student_id,
    s.nr_amze,
    p.first_name, p.father_name, p.last_name,
    p.personal_number

  FROM students s
  /* pa asnjë grup */
  LEFT JOIN course_group_students cgs ON cgs.student_id = s.id
  JOIN student_course_plans scp
    ON scp.student_id = s.id AND scp.status = 'planned'
  JOIN courses c ON c.id = scp.course_id
  LEFT JOIN persons  p ON p.id = s.person_id

  $whereMsql
  GROUP BY s.id, scp.course_id
  HAVING COUNT(cgs.group_id) = 0
  ORDER BY c.name ASC, CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
";
$stm = $pdo->prepare($sqlPlannedNoGroup);
foreach ($paramsM as $k=>$v) $stm->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
$stm->execute();
$plannedNoGroupRows = $stm->fetchAll(PDO::FETCH_ASSOC);

/* Grupi sipas modulit: course_id => [rows...] */
$studentsByCourse = [];
foreach ($plannedNoGroupRows as $r) {
  $cid = (int)$r['course_id'];
  $studentsByCourse[$cid]['course_name'] = $r['course_name'];
  $studentsByCourse[$cid]['rows'][] = $r;
}

/* ------------------------------
   Query: STUDENTË PA MODUL dhe PA GRUP
------------------------------- */
$paramsN = [];
$whereN = ["1=1"];
if ($q !== '') {
  $whereN[] = "(s.nr_amze LIKE :kw
        OR p.personal_number LIKE :kw2
        OR p.first_name LIKE :kw3
        OR p.father_name LIKE :kw4
        OR p.last_name LIKE :kw5)";
  $paramsN[':kw']  = '%'.$q.'%';
  $paramsN[':kw2'] = '%'.$q.'%';
  $paramsN[':kw3'] = '%'.$q.'%';
  $paramsN[':kw4'] = '%'.$q.'%';
  $paramsN[':kw5'] = '%'.$q.'%';
}
$whereNsql = 'WHERE '.implode(' AND ', $whereN);

$sqlNoPlanNoGroup = "
  SELECT
    s.id AS student_id, s.nr_amze,
    p.first_name, p.father_name, p.last_name, p.personal_number
  FROM students s
  LEFT JOIN course_group_students cgs ON cgs.student_id = s.id
  LEFT JOIN persons  p ON p.id = s.person_id
  LEFT JOIN student_course_plans scp
         ON scp.student_id = s.id AND scp.status = 'planned'
  $whereNsql
  GROUP BY s.id
  HAVING COUNT(cgs.group_id) = 0 AND COUNT(scp.course_id) = 0
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
";
$stn = $pdo->prepare($sqlNoPlanNoGroup);
foreach ($paramsN as $k=>$v) $stn->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
$stn->execute();
$noPlanNoGroupRows = $stn->fetchAll(PDO::FETCH_ASSOC);

/* Flash mesazhe */
$flash_ok  = $_SESSION['flash_ok']  ?? null; unset($_SESSION['flash_ok']);
$flash_err = $_SESSION['flash_err'] ?? null; unset($_SESSION['flash_err']);

/* Navbar */
$NAV_ACTIVE = 'students_without_groups';
$HELP_TOPIC = 'students_without_groups';
if ($role === 'administrator') require __DIR__ . '/inc/navbar.php';
else require __DIR__ . '/inc/navbar4.php';

$pageTitle = 'Kursantët pa grup';
require __DIR__ . '/../shared/app_head.php';

$hasFilters = ($q !== '' || $courseFilter !== '');

/* Një listë e vetme: kursantët me modul të zgjedhur dhe ata pa modul. */
$waiting = [];
foreach (($studentsByCourse ?? []) as $cid => $bucket) {
  foreach (($bucket['rows'] ?? []) as $r) {
    $r['plan_course_id']   = (int)$cid;
    $r['plan_course_name'] = (string)($bucket['course_name'] ?? '');
    $waiting[] = $r;
  }
}
foreach (($noPlanNoGroupRows ?? []) as $r) {
  $r['plan_course_id']   = 0;
  $r['plan_course_name'] = '';
  $waiting[] = $r;
}
usort($waiting, static fn($a, $b) => ((int)$a['nr_amze'] <=> (int)$b['nr_amze']));

/* Etiketa e një grupi në listat e zgjedhjes: "Grupi #7 · nis 24.09.2026 · 6/10" */
$groupOption = static function (array $g): string {
  $members = (int)$g['members'];
  $label = 'Grupi #' . (int)$g['id'] . ' · ' . qta_date($g['start_date']) . ' – ' . qta_date($g['end_date']) . ' · ' . $members . '/10';
  if ($members >= 10) $label .= ' · plot';
  elseif (!empty($g['is_completed'])) $label .= ' · i mbyllur';
  $disabled = $members >= 10 ? ' disabled' : '';
  return '<option value="' . (int)$g['id'] . '"' . $disabled . '>' . h($label) . '</option>';
};
$groupOptionsByCourse = static function () use ($groupsByCourse, $groupOption): string {
  $html = '';
  foreach ($groupsByCourse as $list) {
    if (!$list) continue;
    $html .= '<optgroup label="' . h((string)$list[0]['course_name']) . '">';
    foreach ($list as $g) $html .= $groupOption($g);
    $html .= '</optgroup>';
  }
  return $html;
};
$allGroupOptions = $groupOptionsByCourse();
$createHref = 'groups.php?' . http_build_query(['edit' => '1', 'create' => '1']);
?>

<main class="app-main" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title">Kursantët pa grup</h1>
      <p class="page-lead">Këta kursantë janë regjistruar, por ende nuk janë në asnjë grup. Zgjidh grupin për secilin — ose shëno disa dhe caktoji njëherësh.</p>
    </div>
    <div class="page-actions">
      <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>
      <?= qta_help_button() ?>
    </div>
  </header>

  <div class="stats mb-4" aria-label="Gjendja e pritjes">
    <div class="stat">
      <span class="stat-label">Presin një grup</span>
      <span class="stat-value"><?= number_format((int)$countNoGroup, 0, ',', '.') ?></span>
      <span class="stat-note">nga <?= number_format($totalStudents, 0, ',', '.') ?> kursantë gjithsej</span>
    </div>
    <div class="stat">
      <span class="stat-label">Gati për grup</span>
      <span class="stat-value"><?= number_format((int)$countPlannedNoGroup, 0, ',', '.') ?></span>
      <span class="stat-note">e kanë modulin të zgjedhur</span>
    </div>
    <div class="stat">
      <span class="stat-label">Pa modul</span>
      <span class="stat-value"><?= number_format((int)$countNoPlanNoGroup, 0, ',', '.') ?></span>
      <span class="stat-note">zgjidh modulin së pari</span>
    </div>
  </div>

  <form class="filters" method="get" action="students_without_groups.php" role="search" aria-label="Kërko te kursantët pa grup">
    <div class="filter-field is-grow">
      <label class="form-label" for="swgQ">Kërko një kursant</label>
      <div class="search-field">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="form-control" id="swgQ" type="search" name="q" value="<?= h($q) ?>" placeholder="Emri, numri personal ose nr. i amzës">
      </div>
    </div>
    <div class="filter-field">
      <label class="form-label" for="swgC">Moduli</label>
      <select class="form-select" id="swgC" name="course_id">
        <option value="">Të gjitha modulet</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= ($courseFilter !== '' && (int)$courseFilter === (int)$c['id']) ? 'selected' : '' ?>><?= h((string)$c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-actions">
      <?php if ($hasFilters): ?>
        <a class="btn btn-ghost" href="students_without_groups.php"><i class="bi bi-x-lg" aria-hidden="true"></i>Pastro kërkimin</a>
      <?php endif; ?>
      <button class="btn btn-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i>Kërko</button>
    </div>
  </form>

  <?php require __DIR__ . '/../shared/partials/edit_mode_off_banner.php'; ?>

  <section class="section" aria-labelledby="waitTitle">
    <div class="section-head">
      <h2 class="section-title" id="waitTitle">
        <?= $hasFilters ? 'Kursantët që përputhen' : 'Lista e pritjes' ?>
        <span class="count"><?= number_format(count($waiting), 0, ',', '.') ?></span>
      </h2>
      <?php if (!$groupsMeta): ?>
        <a class="section-link" href="<?= h($createHref) ?>">Krijo grupin e parë</a>
      <?php endif; ?>
    </div>

    <?php if ($waiting): ?>
      <?php
        $tfTarget = '#waitTable';
        $tfPlaceholder = 'Filtro — emër, amzë ose modul';
        $tfChips = [['label' => 'Pa modul', 'match' => 'Pa modul ende'], ['label' => 'Gati për grup', 'match' => 'Me modul']];
        $tfNoun = 'kursantë';
        require __DIR__ . '/../shared/partials/table_filter.php';
      ?>
      <div class="table-responsive">
        <table class="table" id="waitTable" data-sortable>
          <thead>
            <tr>
              <th scope="col" class="pick-col" data-sort="none">
                <input class="form-check-input" type="checkbox" id="pickAll" aria-label="Zgjidh të gjithë kursantët në listë" <?= $EDIT_MODE ? '' : 'disabled' ?>>
              </th>
              <th scope="col" class="nowrap" data-sort="num">Nr. i amzës</th>
              <th scope="col" data-sort="text">Kursanti</th>
              <th scope="col" class="col-medium" data-sort="text">Moduli</th>
              <th scope="col" class="nowrap" data-sort="none">Cakto në grup</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($waiting as $s):
              $sid  = (int)$s['student_id'];
              $pcid = (int)$s['plan_course_id'];
              $full = qta_full_name($s['first_name'] ?? '', $s['father_name'] ?? '', $s['last_name'] ?? '');
              $rowGroups = $pcid > 0 ? ($groupsByCourse[$pcid] ?? []) : null;
            ?>
              <tr data-student="<?= $sid ?>">
                <td class="pick-col">
                  <input type="checkbox" class="form-check-input pick" data-student="<?= $sid ?>"
                         aria-label="Zgjidh <?= h($full !== '' ? $full : 'kursantin ' . (string)$s['nr_amze']) ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                </td>
                <td class="nowrap" data-sort-value="<?= (int)$s['nr_amze'] ?>"><span class="id-code"><?= h((string)$s['nr_amze']) ?></span></td>
                <td>
                  <a class="person-name" href="student_card.php?sid=<?= $sid ?>"><?= h($full !== '' ? $full : 'Pa emër ende') ?></a>
                  <?php if (!empty($s['personal_number'])): ?><span class="cell-sub code"><?= h((string)$s['personal_number']) ?></span><?php endif; ?>
                </td>

                <td class="col-medium">
                  <?php if ($pcid > 0): ?>
                    <span class="d-inline-flex align-items-center gap-2">
                      <span class="visually-hidden">Me modul:</span>
                      <span><?= h($s['plan_course_name']) ?></span>
                      <?php if ($EDIT_MODE): ?>
                        <button class="btn btn-ghost btn-sm btn-icon" type="button" data-role="plan-remove"
                                data-student="<?= $sid ?>" data-course="<?= $pcid ?>" data-name="<?= h($full) ?>"
                                aria-label="Hiq modulin <?= h($s['plan_course_name']) ?>" title="Hiq modulin">
                          <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                      <?php endif; ?>
                    </span>
                  <?php else: ?>
                    <span class="visually-hidden">Pa modul ende.</span>
                    <div class="inline-action">
                      <select class="form-select form-select-sm" data-role="plan-select" data-student="<?= $sid ?>"
                              <?= $EDIT_MODE ? '' : 'disabled' ?> aria-label="Zgjidh modulin për <?= h($full) ?>">
                        <option value="">Zgjidh modulin</option>
                        <?php foreach ($courses as $c): ?>
                          <option value="<?= (int)$c['id'] ?>"><?= h((string)$c['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn btn-secondary btn-sm" type="button" data-role="plan-btn" data-student="<?= $sid ?>"
                              <?= $EDIT_MODE ? '' : 'disabled' ?>>Ruaj</button>
                    </div>
                  <?php endif; ?>
                </td>

                <td class="nowrap">
                  <?php if ($rowGroups === []): ?>
                    <span class="text-muted small">Nuk ka grup për këtë modul.</span>
                    <a class="small" href="<?= h($createHref) ?>">Krijo një</a>
                  <?php else: ?>
                    <div class="inline-action">
                      <select class="form-select form-select-sm" data-role="group-select" data-student="<?= $sid ?>"
                              <?= $EDIT_MODE ? '' : 'disabled' ?> aria-label="Zgjidh grupin për <?= h($full) ?>">
                        <option value="">Zgjidh grupin</option>
                        <?php if ($rowGroups !== null): ?>
                          <?php foreach ($rowGroups as $g) echo $groupOption($g); ?>
                        <?php else: ?>
                          <?= $allGroupOptions ?>
                        <?php endif; ?>
                      </select>
                      <button class="btn btn-primary btn-sm" type="button" data-role="assign-btn" data-student="<?= $sid ?>"
                              <?= $EDIT_MODE ? '' : 'disabled' ?>>Cakto</button>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <p class="text-muted small mt-2 mb-0">
        <?= $EDIT_MODE
          ? 'Shëno disa kursantë me kutizën majtas për t\'i caktuar të gjithë njëherësh në të njëjtin grup.'
          : 'Për të caktuar kursantë në grupe, shtyp "Lejo ndryshimet".' ?>
      </p>

      <div class="bulk-bar" id="bulkBar" role="region" aria-label="Veprim për të zgjedhurit" hidden>
        <span class="bulk-count"><span id="bulkN">0</span> të zgjedhur</span>
        <label class="form-label mb-0" for="bulkGroup">Cakto në</label>
        <select class="form-select form-select-sm w-auto mw-100" id="bulkGroup">
          <option value="">Zgjidh grupin</option>
          <?= $allGroupOptions ?>
        </select>
        <button class="btn btn-primary btn-sm" type="button" id="bulkAssign" <?= $EDIT_MODE ? '' : 'disabled' ?>>
          <i class="bi bi-people" aria-hidden="true"></i>Cakto të zgjedhurit
        </button>
        <button class="btn btn-ghost btn-sm" type="button" id="bulkClear">Hiq zgjedhjen</button>
        <span class="text-muted small" id="bulkProgress" aria-live="polite"></span>
      </div>

    <?php else: ?>
      <?= $hasFilters
        ? qta_empty('Asnjë kursant nuk përputhet', 'Provo një emër tjetër ose pastro kërkimin.', 'bi-search', '<a class="btn btn-secondary" href="students_without_groups.php">Pastro kërkimin</a>')
        : qta_empty('Askush nuk pret një grup', 'Të gjithë kursantët janë caktuar në grupe. Kur regjistron kursantë të rinj, ata shfaqen këtu.', 'bi-check2-circle', '<a class="btn btn-secondary" href="groups.php">Shiko grupet</a>', 'is-success') ?>
    <?php endif; ?>
  </section>
</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const EDIT_MODE = <?= $EDIT_MODE ? 'true' : 'false' ?>;
/* Veprimet i trajton vetë kjo faqe */
const ENDPOINT = 'students_without_groups.php';

function notify(type, text, opts={}){
  return window.qtaToast ? window.qtaToast(text, type, opts.title, opts) : null;
}

async function post(payload){
  const res = await fetch(ENDPOINT, {
    method: 'POST',
    headers: {'Content-Type':'application/json','Accept':'application/json'},
    body: JSON.stringify(Object.assign({csrf: CSRF}, payload))
  });
  let json = null;
  try { json = await res.json(); } catch(_) { /* bosh */ }
  if (!json) throw new Error('Nuk mora përgjigje nga serveri. Provo sërish.');
  return json;
}

function busy(btn, on){
  btn.disabled = on;
  btn.classList.toggle('is-loading', on);
  btn.setAttribute('aria-busy', on ? 'true' : 'false');
}

/* Cakto një kursant në grup */
document.querySelectorAll('[data-role="assign-btn"]').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    if (!EDIT_MODE) return;
    const sid = parseInt(btn.dataset.student,10);
    const sel = document.querySelector(`select[data-role="group-select"][data-student="${sid}"]`);
    const gid = parseInt(sel?.value||'0',10);
    if (!gid){ notify('warning','Zgjidh grupin së pari.'); sel?.focus(); return; }
    busy(btn, true);
    try{
      const json = await post({action:'assign_to_group', student_id:sid, group_id:gid});
      if (!json.ok) { busy(btn, false); notify('danger', json.error || 'Kursanti nuk u caktua.'); return; }
      location.reload(); /* mesazhi del pas rifreskimit */
    }catch(e){ busy(btn, false); notify('danger', e.message || 'Lidhja dështoi. Provo sërish.'); }
  });
});

/* Zgjidh modulin për një kursant pa modul */
document.querySelectorAll('[data-role="plan-btn"]').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    if (!EDIT_MODE) return;
    const sid = parseInt(btn.dataset.student,10);
    const sel = document.querySelector(`select[data-role="plan-select"][data-student="${sid}"]`);
    const cid = sel?.value ? parseInt(sel.value,10) : 0;
    if (!cid){ notify('warning','Zgjidh modulin së pari.'); sel?.focus(); return; }
    busy(btn, true);
    try{
      const json = await post({action:'set_student_plan', student_id:sid, course_id:cid});
      if (!json.ok) { busy(btn, false); notify('danger', json.error || 'Moduli nuk u ruajt.'); return; }
      location.reload();
    }catch(e){ busy(btn, false); notify('danger', e.message || 'Lidhja dështoi. Provo sërish.'); }
  });
});

/* Hiq modulin e zgjedhur */
document.querySelectorAll('[data-role="plan-remove"]').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    if (!EDIT_MODE) return;
    const sid = parseInt(btn.dataset.student,10);
    const cid = parseInt(btn.dataset.course,10);
    const who = btn.dataset.name || 'këtij kursanti';
    const ok = await window.qtaConfirm({
      title: 'Të hiqet moduli?',
      message: `Moduli i zgjedhur për ${who} do të hiqet. Kursanti mbetet në listë dhe mund të marrë një modul tjetër.`,
      confirm: 'Po, hiqe modulin', danger: true
    });
    if (!ok) return;
    busy(btn, true);
    try{
      const json = await post({action:'remove_student_plan', student_id:sid, course_id:cid});
      if (!json.ok) { busy(btn, false); notify('danger', json.error || 'Moduli nuk u hoq.'); return; }
      location.reload();
    }catch(e){ busy(btn, false); notify('danger', e.message || 'Lidhja dështoi. Provo sërish.'); }
  });
});

<?php if ($flash_ok): ?>
document.addEventListener('DOMContentLoaded',()=>notify('success', <?= json_encode($flash_ok, JSON_UNESCAPED_UNICODE) ?>));
<?php endif; ?>
<?php if ($flash_err): ?>
document.addEventListener('DOMContentLoaded',()=>notify('danger', <?= json_encode($flash_err, JSON_UNESCAPED_UNICODE) ?>));
<?php endif; ?>

/* ===== Zgjedhja e disa kursantëve dhe caktimi njëherësh =====
   Përdor të njëjtin veprim si caktimi një nga një. */
(function(){
  const bar  = document.getElementById('bulkBar');
  const nOut = document.getElementById('bulkN');
  const gSel = document.getElementById('bulkGroup');
  const btn  = document.getElementById('bulkAssign');
  const clr  = document.getElementById('bulkClear');
  const all  = document.getElementById('pickAll');
  const prog = document.getElementById('bulkProgress');
  if (!bar) return;

  const picks = () => Array.from(document.querySelectorAll('.pick:checked'));
  const visibleBoxes = () => Array.from(document.querySelectorAll('.pick:not(:disabled)')).filter(cb => !cb.closest('tr').hidden);

  function sync(){
    const n = picks().length;
    nOut.textContent = n;
    bar.hidden = n === 0;
    document.querySelectorAll('.pick').forEach(cb=> cb.closest('tr').classList.toggle('is-selected', cb.checked));
    if (all) {
      const boxes = visibleBoxes();
      all.checked = n > 0 && boxes.every(cb => cb.checked);
      all.indeterminate = n > 0 && !all.checked;
    }
  }

  document.addEventListener('change', e=>{ if (e.target.classList && e.target.classList.contains('pick')) sync(); });
  if (all) all.addEventListener('change', ()=>{ visibleBoxes().forEach(cb=>{ cb.checked = all.checked; }); sync(); });
  if (clr) clr.addEventListener('click', ()=>{ document.querySelectorAll('.pick').forEach(cb=>{ cb.checked = false; }); sync(); all?.focus(); });

  if (btn) btn.addEventListener('click', async ()=>{
    if (!EDIT_MODE) return;
    const gid = parseInt(gSel.value || '0', 10);
    if (!gid){ notify('warning','Zgjidh grupin ku do t\'i caktosh.'); gSel.focus(); return; }
    const ids = picks().map(cb => parseInt(cb.dataset.student, 10));
    if (!ids.length) return;
    const label = gSel.options[gSel.selectedIndex]?.textContent.trim() || 'grupin e zgjedhur';
    const ok = await window.qtaConfirm({
      title: `Të caktohen ${ids.length} kursantë?`,
      message: `Do të caktohen te ${label}. Një grup mban deri në 10 kursantë — ata që nuk nxënë mbeten në listë.`,
      confirm: 'Po, caktoji', danger: false
    });
    if (!ok) return;

    busy(btn, true);
    let done = 0;
    const failed = [];
    for (let i = 0; i < ids.length; i++){
      prog.textContent = `Po caktoj ${i+1} nga ${ids.length}…`;
      try{
        const json = await post({action:'assign_to_group', student_id:ids[i], group_id:gid});
        if (json.ok) done++; else failed.push(json.error || 'nuk u pranua');
      }catch(e){ failed.push('lidhja dështoi'); }
    }
    prog.textContent = '';
    busy(btn, false);

    if (done && !failed.length){
      notify('success', `${done} kursantë u caktuan në grup.`);
      setTimeout(()=>location.reload(), 900);
    } else if (done){
      notify('warning', `${done} u caktuan, ${failed.length} jo. Arsyeja: ${failed[0]}`, {autohide:false});
      setTimeout(()=>location.reload(), 2500);
    } else {
      notify('danger', 'Asnjë kursant nuk u caktua. ' + (failed[0] || ''), {autohide:false});
    }
  });

  sync();
})();
</script>
</body>
</html>
