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
   EDIT MODE toggle (persistohet në session)
------------------------------- */
if (isset($_GET['edit'])) {
  $e = strtolower((string)$_GET['edit']);
  $_SESSION['edit_mode'] = ($e === 'on');
  $qs = $_GET; unset($qs['edit']);
  $url = 'students_without_groups.php' . (empty($qs) ? '' : ('?' . http_build_query($qs)));
  header("Location: $url"); exit;
}
$EDIT_MODE = (bool)($_SESSION['edit_mode'] ?? false);

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

/* ------------------------------
   Filtro/Kërko
------------------------------- */
$q = trim($_GET['q'] ?? '');
$courseFilter = trim($_GET['course_id'] ?? '');  // opsional

/* Dropdown kurse + grupe (me zënie) */
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

// SHËNO edhe këtu në meta nëse grupi është mbushur (>=10)
foreach ($groupsMeta as &$gm) {
  $gm['full'] = ((int)$gm['members'] >= 10);
}
unset($gm);


/* Harta: course_id => lista grupeve (me “members” dhe “full”) */
$groupsByCourse = [];
foreach ($groupsMeta as $gm) {
  $gm['full'] = ((int)$gm['members'] >= 10);
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
    p.personal_number, p.birth_date,
    TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) AS age,
    el.code AS edu_code, el.label AS edu_label

  FROM students s
  /* pa asnjë grup */
  LEFT JOIN course_group_students cgs ON cgs.student_id = s.id
  JOIN student_course_plans scp
    ON scp.student_id = s.id AND scp.status = 'planned'
  JOIN courses c ON c.id = scp.course_id
  LEFT JOIN persons  p ON p.id = s.person_id
  LEFT JOIN education_levels el ON el.id = s.education_level_id

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
    p.first_name, p.father_name, p.last_name, p.personal_number, p.birth_date,
    TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) AS age,
    el.code AS edu_code, el.label AS edu_label,

    COUNT(DISTINCT scp.course_id) AS planned_count
  FROM students s
  LEFT JOIN course_group_students cgs ON cgs.student_id = s.id
  LEFT JOIN persons  p ON p.id = s.person_id
  LEFT JOIN education_levels el ON el.id = s.education_level_id
  LEFT JOIN student_course_plans scp
         ON scp.student_id = s.id AND scp.status = 'planned'
  $whereNsql
  GROUP BY s.id
  HAVING COUNT(cgs.group_id) = 0 AND planned_count = 0
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
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Studentë pa grupe – QTA <?= $role==='editor' ? 'Editor' : 'Admin' ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { background:#f5f7fb; padding-top:72px; }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
    .mini-table thead { background:#f1f5f9; }
    .form-control::placeholder { color:#9ca3af; }
    .pagination .page-link { border-radius:.5rem; }
    .nowrap { white-space:nowrap; }
    .btn-pill { border-radius:999px !important; }
    .btn-soft-secondary { background:#f1f5f9; color:#334155; border:1px solid #e2e8f0; }
    .btn-soft-secondary:hover { background:#e2e8f0; color:#0f172a; }
    .badge-cap { font-weight:600; }
    .table td select.form-select { padding:.2rem .5rem; }
    .status-banner strong { font-size:1.05rem; }
    .status-banner .metric { display:flex; align-items:center; gap:.5rem; }

    /* Toasts poshtë MAJTAS (si groups.php) */
    .toast.qta-toast{ border:0; border-radius:.75rem; box-shadow:0 12px 20px rgba(2,6,23,.12); }
    .toast.qta-toast .toast-header{ border-bottom:0; }
    .toast-success .toast-header{ background:#ecfdf5; color:#065f46; }
    .toast-danger  .toast-header{ background:#fef2f2; color:#991b1b; }
    .toast-info    .toast-header{ background:#eff6ff; color:#1e40af; }
    .toast-warning .toast-header{ background:#fff7ed; color:#9a3412; }

    /* FAB stack (si groups.php) */
    .fab-stack{ position:fixed; right:24px; bottom:24px; display:flex; flex-direction:column-reverse; gap:12px; z-index:1040; }
    .fab-stack .fab-btn{ align-self:flex-end; display:inline-flex; align-items:center; justify-content:center; gap:6px;
      min-height:52px; height:52px; width:52px; padding:0 14px; border-radius:999px; box-shadow:0 12px 20px rgba(2,6,23,.15);
      transition:width .2s ease, box-shadow .2s ease, transform .06s ease; overflow:hidden; }
    .fab-stack .fab-btn:hover, .fab-stack .fab-btn:focus{ width:auto; box-shadow:0 16px 28px rgba(2,6,23,.22); }
    .fab-stack .fab-btn .fab-text{ white-space:nowrap; max-width:0; opacity:0; transition:max-width .2s, opacity .15s, margin-left .2s; margin-left:0; }
    .fab-stack .fab-btn:hover .fab-text, .fab-stack .fab-btn:focus .fab-text{ max-width:180px; opacity:1; margin-left:4px; }
    .fab-stack .fab-btn:active{ transform:translateY(1px); }
    @media (max-width:575.98px){ .fab-stack{ right:16px; bottom:16px; gap:10px; } .fab-stack .fab-btn{ min-height:48px; height:48px; width:48px; padding:0 12px; } }

    .compact .mini-table table.table > :not(caption) > * > * { padding: .35rem .5rem; }
  </style>
</head>
<body class="<?= $EDIT_MODE ? '' : 'editing-off' ?>">

<!-- Toast container -->
<div id="toastZone" class="toast-container position-fixed start-0 bottom-0 p-3" style="z-index:1080;"></div>

<main class="container-fluid px-3 px-md-4">
  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
    <h2 class="mb-0">Studentë pa grupe</h2>
    <div class="d-flex flex-wrap align-items-center page-toolbar">
      <!-- si groups.php – nuk ka formularë këtu -->
    </div>
  </div>

  <div class="alert alert-primary status-banner d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
    <div class="metric"><i class="bi bi-people me-1"></i> Gjithsej: <strong><?= number_format($totalStudents) ?></strong></div>
    <div class="metric"><i class="bi bi-person-dash me-1"></i> Pa grup: <strong><?= number_format($countNoGroup) ?></strong></div>
    <div class="metric"><i class="bi bi-journal-text me-1"></i> Me modul pa grup: <strong><?= number_format($countPlannedNoGroup) ?></strong></div>
    <div class="metric"><i class="bi bi-slash-circle me-1"></i> Pa modul & pa grup: <strong><?= number_format($countNoPlanNoGroup) ?></strong></div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get" action="students_without_groups.php">
        <div class="col-md-9">
          <div class="d-flex align-items-center">
            <label class="form-label mb-0 me-2" style="min-width:70px;">Kërko</label>
            <div class="input-group flex-grow-1">
              <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
              <input type="text" name="q" value="<?= h($q) ?>" class="form-control border-0" placeholder="Kërko sipas AMZË/ID/Emri...">
              <select name="course_id" class="form-select">
                <option value="">— Modul —</option>
                <?php foreach($courses as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= ($courseFilter!=='' && (int)$courseFilter===(int)$c['id'])?'selected':'' ?>>
                    <?= h($c['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="col-md-3 text-end">
          <a class="btn btn-soft-secondary btn-pill me-1" href="students_without_groups.php"><i class="bi bi-x-circle me-1"></i>Pastro</a>
          <button class="btn btn-primary btn-pill" type="submit"><i class="bi bi-funnel me-1"></i>Apliko</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ====== SEKSIONI A: Me modul (pa grup), i ndarë sipas modulit ====== -->
  <?php if (!empty($studentsByCourse)): ?>
    <?php foreach ($studentsByCourse as $cid => $bucket): $rows = $bucket['rows'] ?? []; $cname = $bucket['course_name'] ?? '—'; ?>
      <div class="card mb-4">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-book me-2"></i><?= h($cname) ?> — studentë pa grup</h5>
          <span class="text-muted small"><?= number_format(count($rows)) ?> student(ë)</span>
        </div>
        <div class="card-body">
          <div class="table-responsive mini-table">
            <table class="table align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th class="nowrap">AMZË</th>
                  <th>Emër Atësi Mbiemër<br><small class="text-muted">ID Personal</small></th>
                  <th class="nowrap">Mosha</th>
                  <th class="nowrap">Arsimi</th>
                  <th class="nowrap">Zgjidh grup (<?= h($cname) ?>)</th>
                  <th class="nowrap">Ndrysho modul</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $r): ?>
                <?php
                  $sid = (int)$r['student_id'];
                  $availableGroups = $groupsByCourse[(int)$cid] ?? [];
                ?>
                <tr id="row_s<?= $sid ?>_c<?= (int)$cid ?>">
                  <td class="nowrap"><?= h($r['nr_amze']) ?></td>
                  <td>
                    <div class="fw-semibold">
                      <?= h(trim(($r['first_name']??'').' '.(($r['father_name']??'')?($r['father_name'].' '):'').($r['last_name']??''))) ?>
                    </div>
                    <div class="text-muted small"><?= h($r['personal_number'] ?? '') ?></div>
                  </td>
                  <td class="nowrap"><?= $r['age'] !== null ? (int)$r['age'] : '—' ?></td>
                  <td><?= h(($r['edu_code']? $r['edu_code'].' — ' : '').($r['edu_label'] ?? '—')) ?></td>

                  <td class="nowrap">
                    <div class="d-flex gap-2">
                      <select class="form-select form-select-sm" style="min-width:220px"
                              data-role="group-select" data-student="<?= $sid ?>" data-course="<?= (int)$cid ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                        <option value="">— Zgjidh grup —</option>
                        <?php foreach($availableGroups as $g): ?>
                          <option value="<?= (int)$g['id'] ?>" <?= $g['full'] ? 'disabled' : '' ?>>
                            #<?= (int)$g['id'] ?> • <?= h(fmt_dMY($g['start_date']).' → '.fmt_dMY($g['end_date'])) ?>
                            (<?= h($g['course_name']) ?>)
                            <?= $g['full'] ? ' — [Full]' : ' — ['.(int)$g['members'].'/10]' ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn btn-primary btn-sm" data-role="assign-btn"
                              data-student="<?= $sid ?>" data-course="<?= (int)$cid ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                        <i class="bi bi-plus-circle me-1"></i>Vendos
                      </button>
                    </div>
                    <div class="small text-muted mt-1">
                      Kapaciteti max 10 / grup. Nuk lejohet të ndiqet i njëjti modul dy herë (edhe sipas ID personale).
                    </div>
                  </td>

                  <td class="nowrap">
                    <div class="d-flex gap-2">
                      <select class="form-select form-select-sm" style="min-width:220px"
                              data-role="plan-select" data-student="<?= $sid ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                        <?php foreach($courses as $c): ?>
                          <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id']===(int)$cid)?'selected':'' ?>>
                            <?= h($c['name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn btn-soft-secondary btn-sm" data-role="plan-btn"
                              data-student="<?= $sid ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                        <i class="bi bi-arrow-repeat me-1"></i>Ruaj
                      </button>
                    </div>
                    <div class="small text-muted mt-1">Ndrysho modulin e planifikuar të studentit.</div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="card mb-4"><div class="card-body">
      <div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1"></i>Nuk ka studentë me modul pa grup sipas filtrave.</div>
    </div></div>
  <?php endif; ?>

  <!-- ====== SEKSIONI B: Pa modul (pa grup) ====== -->
  <div class="card mb-4">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
      <h5 class="mb-0"><i class="bi bi-slash-circle me-2"></i>Studentë pa modul dhe pa grup</h5>
      <span class="text-muted small"><?= number_format(count($noPlanNoGroupRows)) ?> student(ë)</span>
    </div>
    <div class="card-body">
      <div class="table-responsive mini-table">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="nowrap">AMZË</th>
              <th>Emër Atësi Mbiemër<br><small class="text-muted">ID Personal</small></th>
              <th class="nowrap">Mosha</th>
              <th class="nowrap">Arsimi</th>
              <th class="nowrap">Zgjidh grup (çdo modul)</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($noPlanNoGroupRows): foreach ($noPlanNoGroupRows as $s): $sid=(int)$s['student_id']; ?>
            <tr id="row_s<?= $sid ?>_noplan">
              <td class="nowrap"><?= h($s['nr_amze']) ?></td>
              <td>
                <div class="fw-semibold">
                  <?= h(trim(($s['first_name']??'').' '.(($s['father_name']??'')?($s['father_name'].' '):'').($s['last_name']??''))) ?>
                </div>
                <div class="text-muted small"><?= h($s['personal_number'] ?? '') ?></div>
              </td>
              <td class="nowrap"><?= $s['age'] !== null ? (int)$s['age'] : '—' ?></td>
              <td><?= h(($s['edu_code']? $s['edu_code'].' — ' : '').($s['edu_label'] ?? '—')) ?></td>

              <!-- Zgjidh grup nga të gjithë grupet ekzistuese -->
              <td class="nowrap">
                <div class="d-flex gap-2">
                  <select class="form-select form-select-sm" style="min-width:280px"
                          data-role="group-select-any" data-student="<?= $sid ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                    <option value="">— Zgjidh grup —</option>
                    <?php foreach ($groupsMeta as $g): ?>
                    <?php $isFull = ((int)$g['members'] >= 10); ?>
                    <option value="<?= (int)$g['id'] ?>" <?= $isFull ? 'disabled' : '' ?>>
                        #<?= (int)$g['id'] ?> • <?= h($g['course_name']) ?> • <?= h(fmt_dMY($g['start_date']).' → '.fmt_dMY($g['end_date'])) ?>
                        <?= $isFull ? ' — [Full]' : ' — ['.(int)$g['members'].'/10]' ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-primary btn-sm" data-role="assign-btn-any" data-student="<?= $sid ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                    <i class="bi bi-plus-circle me-1"></i>Vendos
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" class="text-center text-muted">Asnjë student pa modul & pa grup.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="text-center text-muted small mt-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<!-- FAB stack: ToggleAll (nuk ka accordion këtu), Edit Mode -->
<div class="fab-stack" role="group" aria-label="Veprime shpejta">
  <a id="editModeFab"
     class="fab-btn btn <?= $EDIT_MODE ? 'btn-success' : 'btn-soft-secondary' ?>"
     href="<?= h($toggleUrl) ?>"
     title="Ndrysho gjendjen e Edit Mode">
    <i class="bi <?= $EDIT_MODE ? 'bi-unlock' : 'bi-lock' ?>"></i>
    <span class="fab-text">Edit Mode: <?= $EDIT_MODE ? 'ON' : 'OFF' ?></span>
  </a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const EDIT_MODE = <?= $EDIT_MODE ? 'true' : 'false' ?>;
const ENDPOINT = 'students_without_groups_inline.php';

/* Toast helper – identik me groups.php */
function notify(type, text, opts={}){
  const zone = document.getElementById('toastZone');
  const id = 't' + Date.now() + Math.random().toString(16).slice(2);
  const icons = { success:'check-circle', danger:'exclamation-triangle', warning:'exclamation-circle', info:'info-circle' };
  const icon = icons[type] || 'bell';
  const title = opts.title ?? (
    type==='success' ? 'Sukses' :
    type==='danger'  ? 'Gabim'  :
    type==='warning' ? 'Kujdes' : 'Njoftim'
  );
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
      notify('success','U vendos në grup.');
      const row = document.getElementById(`row_s${sid}_c${cid}`);
      if (row) row.remove();
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
      notify('success','U vendos në grup.');
      const row = document.getElementById(`row_s${sid}_noplan`);
      if (row) row.remove();
    }catch(e){ btn.disabled=false; notify('danger','Gabim lidhjeje.'); }
  });
});

/* Ndrysho/ cakto modul (plan) – për të dy seksionet */
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
      notify('success','Moduli u përditësua.');
    }catch(e){ btn.disabled=false; notify('danger','Gabim lidhjeje.'); }
  });
});

document.addEventListener('DOMContentLoaded', ()=>{ document.body.classList.add('compact'); });
<?php if ($flash_ok): ?>
document.addEventListener('DOMContentLoaded',()=>notify('success', <?= json_encode($flash_ok) ?>));
<?php endif; ?>
<?php if ($flash_err): ?>
document.addEventListener('DOMContentLoaded',()=>notify('danger', <?= json_encode($flash_err) ?>));
<?php endif; ?>
</script>
</body>
</html>
