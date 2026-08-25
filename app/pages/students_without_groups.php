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
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function fmt_dMY(?string $iso): string {
  if (!$iso) return '—';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return h($iso);
  $ts = strtotime($iso);
  return $ts ? date('d-m-Y', $ts) : '—';
}
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
      throw new RuntimeException('CSRF token mismatch.');
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
      if ($student_id<=0 || $group_id<=0) throw new RuntimeException('Të dhëna të pavlefshme.');

      // Group exists and capacity <10
      $gq = $pdo->prepare("SELECT cg.course_id, cg.start_date, cg.end_date, COUNT(cgs.student_id) AS members
                           FROM course_groups cg
                           LEFT JOIN course_group_students cgs ON cgs.group_id=cg.id
                           WHERE cg.id=:g GROUP BY cg.id");
      $gq->execute([':g'=>$group_id]);
      $g = $gq->fetch(PDO::FETCH_ASSOC);
      if (!$g) throw new RuntimeException('Grupi nuk u gjet.');
      if ((int)$g['members'] >= 10) throw new RuntimeException('Grupi është i mbushur (10/10).');

      // Student not already in this group
      $exists = $pdo->prepare("SELECT 1 FROM course_group_students WHERE group_id=:g AND student_id=:s");
      $exists->execute([':g'=>$group_id, ':s'=>$student_id]);
      if ($exists->fetchColumn()) throw new RuntimeException('Studenti është tashmë në këtë grup.');

      // Ndalim: i njëjti person nuk duhet ta ketë ndjekur më parë këtë modul
      $getPersonPN->execute([':sid'=>$student_id]);
      $pn = $getPersonPN->fetchColumn();
      if ($hasAttendedCoursePN((int)$g['course_id'], $pn)) {
        throw new RuntimeException('Ky person e ka ndjekur më parë këtë modul — nuk lejohet përsëritja.');
      }

      $pdo->beginTransaction();
      // RULE: Një AMZË nuk duhet të jetë njëkohësisht me modul (plan) dhe në grup -> fshijmë çdo "planned"
      $pdo->prepare("DELETE FROM student_course_plans WHERE student_id=:s AND status='planned'")->execute([':s'=>$student_id]);

      // Vendos në grup
      $ins = $pdo->prepare("INSERT INTO course_group_students (group_id, student_id) VALUES (:g,:s)");
      $ins->execute([':g'=>$group_id, ':s'=>$student_id]);
      $pdo->commit();

      // Flash për toast pas rifreskimit
      $_SESSION['flash_ok'] = 'U vendos në grup.';
      json_response(['ok'=>true, 'message'=>'U vendos në grup.']);
    }

    if ($action === 'set_student_plan') {
      $student_id = (int)($payload['student_id'] ?? 0);
      $course_id  = (int)($payload['course_id']  ?? 0);
      if ($student_id<=0 || $course_id<=0) throw new RuntimeException('Të dhëna të pavlefshme.');

      // S’lejohet plan nëse studenti është në ndonjë grup
      $inGroup = $pdo->prepare("SELECT 1 FROM course_group_students WHERE student_id=:s LIMIT 1");
      $inGroup->execute([':s'=>$student_id]);
      if ($inGroup->fetchColumn()) {
        throw new RuntimeException('Ky student është në një grup. Hiqe nga grupi përpara ndryshimit të modulit.');
      }

      // Ndalim: i njëjti person s’mund ta ketë ndjekur (në grupe) të njëjtin modul
      $getPersonPN->execute([':sid'=>$student_id]);
      $pn = $getPersonPN->fetchColumn();
      if ($hasAttendedCoursePN($course_id, $pn)) {
        throw new RuntimeException('Ky person e ka ndjekur më parë këtë modul — nuk lejohet plan për të njëjtin modul.');
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
      $_SESSION['flash_ok'] = 'Moduli (plan) u përditësua.';
      json_response(['ok'=>true, 'message'=>'Moduli (plan) u përditësua.']);
    }

    /* NEW: Hiq studentin nga një modul (fshi planin e modulit) */
    if ($action === 'remove_student_plan') {
      $student_id = (int)($payload['student_id'] ?? 0);
      $course_id  = (int)($payload['course_id']  ?? 0);
      if ($student_id<=0 || $course_id<=0) throw new RuntimeException('Të dhëna të pavlefshme.');

      // Student nuk duhet të jetë në ndonjë grup të këtij moduli
      $inGroup = $pdo->prepare("SELECT 1 FROM course_group_students cgs JOIN course_groups cg ON cg.id=cgs.group_id WHERE cgs.student_id=:s AND cg.course_id=:c LIMIT 1");
      $inGroup->execute([':s'=>$student_id, ':c'=>$course_id]);
      if ($inGroup->fetchColumn()) {
        throw new RuntimeException('Ky student është i caktuar në një grup për këtë modul — hiqe nga grupi fillimisht.');
      }

      // Fshi vetëm planet 'planned' për këtë modul
      $del = $pdo->prepare("DELETE FROM student_course_plans WHERE student_id=:s AND course_id=:c AND status='planned'");
      $del->execute([':s'=>$student_id, ':c'=>$course_id]);

      if ($del->rowCount() < 1) {
        throw new RuntimeException('Nuk u gjet plan aktiv për këtë modul.');
      }

      // Flash për toast pas rifreskimit
      $_SESSION['flash_ok'] = 'Plani i modulit u hoq.';
      json_response(['ok'=>true, 'message'=>'Plani i modulit u hoq.']);
    }

    throw new RuntimeException('Veprim i panjohur.');
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
    cg.start_date, cg.end_date,
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
if ($role === 'administrator') require __DIR__ . '/inc/navbar.php';
else require __DIR__ . '/inc/navbar4.php';

/* Build toggle URL që ruan parametrat */
$toggleUrl = 'students_without_groups.php?' . http_build_query(array_filter([
  'q' => ($q !== '' ? $q : null),
  'course_id' => ($courseFilter !== '' ? $courseFilter : null),
  'edit' => ($EDIT_MODE ? 'off' : 'on'),
]));

$pageTitle = 'Studentë pa grupe – QTA ' . ($role==='editor' ? 'Editor' : 'Admin');
$bodyClass = $EDIT_MODE ? '' : 'editing-off';
require __DIR__ . '/../shared/app_head.php';
?>


<!-- Toast container -->
<div id="toastZone" class="toast-container position-fixed start-0 bottom-0 p-3" style="z-index:1080;"></div>

<main class="app-main">

  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
    <div class="title-block-main">
      <div class="title-block-eyebrow">Regjistri</div>
      <h1>Kursantë pa grup</h1>
      <p class="title-block-note">Kush pret caktim, dhe në cilin grup mund të shkojë.</p>
    </div>
    <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>
  </div>

  <!-- ============================================================== SHIFRAT -->
  <section class="tally" aria-label="Gjendja e pritjes">
    <div class="tally-cell">
      <span class="label">Në regjistër</span>
      <span class="tally-value"><?= number_format($totalStudents) ?></span>
      <span class="tally-foot">kursantë</span>
    </div>
    <div class="tally-cell<?= (int)$countNoGroup > 0 ? ' is-hold' : '' ?>">
      <span class="label">Pa grup</span>
      <span class="tally-value"><?= number_format((int)$countNoGroup) ?></span>
      <span class="tally-foot">presin caktim</span>
    </div>
    <div class="tally-cell">
      <span class="label">Me modul, pa grup</span>
      <span class="tally-value"><?= number_format((int)$countPlannedNoGroup) ?></span>
      <span class="tally-foot">gati për caktim</span>
    </div>
    <div class="tally-cell">
      <span class="label">Pa modul</span>
      <span class="tally-value"><?= number_format((int)$countNoPlanNoGroup) ?></span>
      <span class="tally-foot">duhet modul së pari</span>
    </div>
  </section>

  <!-- ============================================================== FILTRAT -->
  <section class="leaf mb-4">
    <div class="leaf-head">
      <span class="ui-title">Gjej një kursant</span>
    </div>
    <div class="leaf-body">
      <form class="row g-3 align-items-end" method="get" action="students_without_groups.php">
        <div class="col-12 col-lg-6">
          <label class="label" for="swgQ">Kërko</label>
          <input class="input" id="swgQ" type="text" name="q" value="<?= h($q) ?>"
                 placeholder="Emër, AMZË ose numër personal">
        </div>
        <div class="col-12 col-lg-4">
          <label class="label" for="swgC">Moduli</label>
          <select class="select" id="swgC" name="course_id">
            <option value="">Të gjitha modulet</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ($courseFilter !== '' && (int)$courseFilter === (int)$c['id']) ? 'selected' : '' ?>>
                <?= h($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-lg-2 d-flex gap-2">
          <button class="btn btn-ink" type="submit">Apliko</button>
          <a class="btn" href="students_without_groups.php">Pastro</a>
        </div>
      </form>
    </div>
  </section>

  <?php
  /* Një listë e vetme: kursantët me modul të planifikuar dhe ata pa modul.
     Më parë ishin dy seksione të ndara me karta për çdo modul — e njëjta punë
     e ndarë në dhjetëra vende. */
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
  ?>

  <!-- ========================================================= VEPRIM MASIV -->
  <div class="bulk-bar" id="bulkBar" hidden>
    <span class="bulk-count"><b id="bulkN">0</b> të zgjedhur</span>
    <label class="label" for="bulkGroup" style="margin:0">Cakto në</label>
    <select class="select" id="bulkGroup" style="max-width:320px">
      <option value="">— Zgjidh grupin —</option>
      <?php foreach ($groupsMeta as $g): ?>
        <option value="<?= (int)$g['id'] ?>" <?= !empty($g['full']) ? 'disabled' : '' ?>>
          <?= h($g['course_name']) ?> · #<?= (int)$g['id'] ?> · <?= (int)$g['members'] ?>/10<?= !empty($g['full']) ? ' (plot)' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-ink" type="button" id="bulkAssign" <?= $EDIT_MODE ? '' : 'disabled' ?>>Cakto</button>
    <button class="btn btn-sm" type="button" id="bulkClear">Hiq zgjedhjen</button>
    <span class="bulk-progress" id="bulkProgress"></span>
  </div>

  <!-- ================================================== LISTA E PRITJES ==== -->
  <?php if ($waiting): ?>
    <?php
      $tfTarget = '#waitTable';
      $tfPlaceholder = 'Ngushto listën — emër, amzë ose modul';
      $tfChips = [['label' => 'Pa modul', 'match' => 'Vendos modul']];
      require __DIR__ . '/../shared/partials/table_filter.php';
    ?>
    <div class="ledger">
      <table class="ledger-table" id="waitTable" data-sortable>
        <thead>
          <tr>
            <th class="pick-col" data-sort="none">
              <input type="checkbox" id="pickAll" aria-label="Zgjidh të gjithë" <?= $EDIT_MODE ? '' : 'disabled' ?>>
            </th>
            <th class="no" data-sort="num">Nr.</th>
            <th class="nowrap" data-sort="num">AMZË</th>
            <th data-sort="text">Kursanti</th>
            <th data-sort="text">Moduli</th>
            <th class="nowrap" data-sort="none">Cakto në grup</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($waiting as $i => $s):
            $sid  = (int)$s['student_id'];
            $pcid = (int)$s['plan_course_id'];
            $groupsForRow = $pcid > 0 ? ($groupsByCourse[$pcid] ?? []) : $groupsMeta;
            $full = trim(($s['first_name'] ?? '') . ' ' . (($s['father_name'] ?? '') ? ($s['father_name'] . ' ') : '') . ($s['last_name'] ?? ''));
          ?>
            <tr data-student="<?= $sid ?>">
              <td class="pick-col">
                <input type="checkbox" class="pick" data-student="<?= $sid ?>"
                       aria-label="Zgjidh <?= h($full) ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>
              </td>
              <td class="no"><?= str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT) ?></td>
              <td class="nowrap code"><?= h((string)$s['nr_amze']) ?></td>
              <td>
                <span class="person"><?= h($full) ?></span>
                <span class="code muted-2 d-block"><?= h((string)($s['personal_number'] ?? '')) ?></span>
              </td>

              <td>
                <?php if ($pcid > 0): ?>
                  <?= h($s['plan_course_name']) ?>
                <?php else: ?>
                  <div class="d-flex gap-1 align-items-center">
                    <select class="select inline-select" data-role="plan-select" data-student="<?= $sid ?>"
                            <?= $EDIT_MODE ? '' : 'disabled' ?> aria-label="Vendos modulin">
                      <option value="">— Vendos modul —</option>
                      <?php foreach ($courses as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm" type="button" data-role="plan-btn" data-student="<?= $sid ?>"
                            <?= $EDIT_MODE ? '' : 'disabled' ?>>Ruaj</button>
                  </div>
                <?php endif; ?>
              </td>

              <td class="nowrap">
                <div class="d-flex gap-1 align-items-center">
                  <select class="select inline-select" style="min-width:210px"
                          data-role="<?= $pcid > 0 ? 'group-select' : 'group-select-any' ?>"
                          data-student="<?= $sid ?>" <?= $pcid > 0 ? 'data-course="' . $pcid . '"' : '' ?>
                          <?= $EDIT_MODE ? '' : 'disabled' ?> aria-label="Zgjidh grupin">
                    <option value="">— Grupi —</option>
                    <?php foreach ($groupsForRow as $g):
                      $isFull = ((int)$g['members'] >= 10); ?>
                      <option value="<?= (int)$g['id'] ?>" <?= $isFull ? 'disabled' : '' ?>>
                        <?php if ($pcid <= 0): ?><?= h($g['course_name']) ?> · <?php endif; ?>#<?= (int)$g['id'] ?> · <?= (int)$g['members'] ?>/10<?= $isFull ? ' (plot)' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-ink" type="button"
                          data-role="<?= $pcid > 0 ? 'assign-btn' : 'assign-btn-any' ?>"
                          data-student="<?= $sid ?>" <?= $pcid > 0 ? 'data-course="' . $pcid . '"' : '' ?>
                          <?= $EDIT_MODE ? '' : 'disabled' ?>>Cakto</button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="muted mt-3" style="font-size:var(--fs-xs)">
      <?= number_format(count($waiting)) ?> kursantë presin caktim. Zgjidh disa rreshta për t'i caktuar të gjithë njëherësh.
    </p>

  <?php else: ?>
    <div class="blank">
      <span class="blank-title">Askush nuk pret caktim</span>
      <span class="blank-note">Të gjithë kursantët janë caktuar në një grup.</span>
    </div>
  <?php endif; ?>

</main>


<!-- FAB: Edit Mode -->
<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const EDIT_MODE = <?= $EDIT_MODE ? 'true' : 'false' ?>;
/* Endpoint = kjo faqe */
const ENDPOINT = 'students_without_groups.php';

/* Toast helper */
function notify(type, text, opts={}){
  const zone = document.getElementById('toastZone');
  const id = 't' + Date.now() + Math.random().toString(16).slice(2);
  const icons = { success:'check-circle', danger:'exclamation-triangle', warning:'exclamation-circle', info:'info-circle' };
  const icon = icons[type] || 'bell';
  const title = opts.title ?? (type==='success' ? 'Sukses' : type==='danger' ? 'Gabim' : type==='warning' ? 'Kujdes' : 'Njoftim');
  const autohide = opts.autohide ?? true;
  const delay = opts.delay ?? 4500;
  const html = `
    <div id="${id}" class="toast qta-toast toast-${type}" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="toast-header">
        <i class="bi bi-${icon} me-2"></i>
        <strong class="me-auto">${title}</strong>
        <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Mbyll"></button>
      </div>
      <div class="toast-body">${text}</div>
    </div>`;
  zone.insertAdjacentHTML('beforeend', html);
  const el = document.getElementById(id);
  const t = new bootstrap.Toast(el, { autohide, delay });
  el.addEventListener('hidden.bs.toast', ()=> el.remove());
  t.show();
}

/* Assign (me modul të ditur) */
document.querySelectorAll('[data-role="assign-btn"]').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    if (!EDIT_MODE) return;
    const sid = parseInt(btn.dataset.student,10);
    const cid = parseInt(btn.dataset.course,10);
    const sel = document.querySelector(`select[data-role="group-select"][data-student="${sid}"][data-course="${cid}"]`);
    const gid = parseInt(sel?.value||'0',10);
    if (!gid){ notify('warning','Zgjidh një grup.'); return; }

    btn.disabled = true;
    try{
      const res = await fetch(ENDPOINT, {
        method:'POST',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body: JSON.stringify({csrf:CSRF, action:'assign_to_group', student_id:sid, group_id:gid})
      });
      const json = await res.json();
      btn.disabled = false;
      if (!json.ok) { notify('danger', json.error || 'Nuk u krye veprimi.'); return; }
      // Mos shfaq toast këtu; do shfaqet pas reload-it nga flash_ok
      location.reload();
    }catch(e){ btn.disabled=false; notify('danger','Gabim lidhjeje.'); }
  });
});

/* Assign (nga çdo grup) – pa modul të caktuar */
document.querySelectorAll('[data-role="assign-btn-any"]').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    if (!EDIT_MODE) return;
    const sid = parseInt(btn.dataset.student,10);
    const sel = document.querySelector(`select[data-role="group-select-any"][data-student="${sid}"]`);
    const gid = parseInt(sel?.value||'0',10);
    if (!gid){ notify('warning','Zgjidh një grup.'); return; }

    btn.disabled = true;
    try{
      const res = await fetch(ENDPOINT, {
        method:'POST',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body: JSON.stringify({csrf:CSRF, action:'assign_to_group', student_id:sid, group_id:gid})
      });
      const json = await res.json();
      btn.disabled = false;
      if (!json.ok) { notify('danger', json.error || 'Nuk u krye veprimi.'); return; }
      // Toast shfaqet pas reload-it nga flash_ok
      location.reload();
    }catch(e){ btn.disabled=false; notify('danger','Gabim lidhjeje.'); }
  });
});

/* Ndrysho/ cakto modul (plan) – upsert */
document.querySelectorAll('[data-role="plan-btn"]').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    if (!EDIT_MODE) return;
    const sid = parseInt(btn.dataset.student,10);
    const sel = document.querySelector(`select[data-role="plan-select"][data-student="${sid}"]`);
    const cid = sel?.value ? parseInt(sel.value,10) : 0;
    if (!cid){ notify('warning','Zgjidh një modul.'); return; }

    btn.disabled = true;
    try{
      const res = await fetch(ENDPOINT, {
        method:'POST',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body: JSON.stringify({csrf:CSRF, action:'set_student_plan', student_id:sid, course_id:cid})
      });
      const json = await res.json();
      btn.disabled = false;
      if (!json.ok) { notify('danger', json.error || 'Nuk u ruajt moduli.'); return; }
      // Toast shfaqet pas reload-it nga flash_ok
      location.reload();
    }catch(e){ btn.disabled=false; notify('danger','Gabim lidhjeje.'); }
  });
});

/* NEW: Hiq modulin (plan) për këtë student */
document.querySelectorAll('[data-role="plan-remove"]').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    if (!EDIT_MODE) return;
    const sid = parseInt(btn.dataset.student,10);
    const cid = parseInt(btn.dataset.course,10);
    if (!sid || !cid) { notify('danger','Të dhëna të pavlefshme.'); return; }
    if (!confirm('Je i sigurt që dëshiron të heqësh modulin e planifikuar për këtë student?')) return;

    btn.disabled = true;
    try{
      const res = await fetch(ENDPOINT, {
        method:'POST',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body: JSON.stringify({csrf:CSRF, action:'remove_student_plan', student_id:sid, course_id:cid})
      });
      const json = await res.json();
      btn.disabled = false;
      if (!json.ok) { notify('danger', json.error || 'Nuk u hoq plani i modulit.'); return; }
      // Toast shfaqet pas reload-it nga flash_ok
      location.reload();
    }catch(e){ btn.disabled=false; notify('danger','Gabim lidhjeje.'); }
  });
});

document.addEventListener('DOMContentLoaded', ()=>{ document.body.classList.add('compact'); });
<?php if ($flash_ok): ?>
document.addEventListener('DOMContentLoaded',()=>notify('success', <?= json_encode($flash_ok) ?>));
<?php endif; ?>

/* ===== Zgjedhje rreshtash dhe caktim masiv =====
   Përdor të njëjtin endpoint si caktimi një-nga-një; asnjë veprim i ri nuk u
   shtua në server. Ndryshimi është vetëm sa herë e thërret përdoruesi. */
(function(){
  const bar   = document.getElementById('bulkBar');
  const nOut  = document.getElementById('bulkN');
  const gSel  = document.getElementById('bulkGroup');
  const btn   = document.getElementById('bulkAssign');
  const clr   = document.getElementById('bulkClear');
  const all   = document.getElementById('pickAll');
  const prog  = document.getElementById('bulkProgress');
  if (!bar) return;

  const picks = () => Array.from(document.querySelectorAll('.pick:checked'));

  function sync(){
    const n = picks().length;
    nOut.textContent = n;
    bar.hidden = n === 0;
    document.querySelectorAll('.pick').forEach(cb=>{
      cb.closest('tr').classList.toggle('is-selected', cb.checked);
    });
    if (all) {
      const boxes = document.querySelectorAll('.pick:not(:disabled)');
      all.checked = n > 0 && n === boxes.length;
      all.indeterminate = n > 0 && n < boxes.length;
    }
  }

  document.addEventListener('change', e=>{
    if (e.target.classList && e.target.classList.contains('pick')) sync();
  });

  if (all) all.addEventListener('change', ()=>{
    document.querySelectorAll('.pick:not(:disabled)').forEach(cb=>{ cb.checked = all.checked; });
    sync();
  });

  if (clr) clr.addEventListener('click', ()=>{
    document.querySelectorAll('.pick').forEach(cb=>{ cb.checked = false; });
    sync();
  });

  if (btn) btn.addEventListener('click', async ()=>{
    if (!EDIT_MODE) return;
    const gid = parseInt(gSel.value || '0', 10);
    if (!gid){ notify('warning','Zgjidh grupin ku do t\'i caktosh.'); gSel.focus(); return; }

    const ids = picks().map(cb => parseInt(cb.dataset.student, 10));
    if (!ids.length) return;

    btn.disabled = true;
    let ok = 0;
    const failed = [];

    for (let i = 0; i < ids.length; i++){
      prog.textContent = (i+1) + '/' + ids.length;
      try{
        const res  = await fetch(ENDPOINT, {
          method:'POST',
          headers:{'Content-Type':'application/json','Accept':'application/json'},
          body: JSON.stringify({csrf:CSRF, action:'assign_to_group', student_id:ids[i], group_id:gid})
        });
        const json = await res.json();
        if (json.ok) ok++; else failed.push(json.error || 'i papranuar');
      }catch(e){ failed.push('gabim lidhjeje'); }
    }

    prog.textContent = '';
    btn.disabled = false;

    if (ok && !failed.length){
      notify('success', ok + ' kursantë u caktuan në grup.');
      setTimeout(()=>location.reload(), 700);
    } else if (ok){
      notify('warning', ok + ' u caktuan, ' + failed.length + ' jo: ' + failed[0]);
      setTimeout(()=>location.reload(), 1600);
    } else {
      notify('danger', 'Asnjë nuk u caktua. ' + (failed[0] || ''));
    }
  });

  sync();
})();

<?php if ($flash_err): ?>
document.addEventListener('DOMContentLoaded',()=>notify('danger', <?= json_encode($flash_err) ?>));
<?php endif; ?>
</script>
</body>
</html>
