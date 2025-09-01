<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';
$pdo = getPDO();

/* ------------------------------
   Guard: vetëm EDITOR i loguar
------------------------------- */
if (empty($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$st = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id = u.role_id
  WHERE u.id = :id LIMIT 1
");
$st->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $st->fetch();
if (!$currentUser || strtolower((string)$currentUser['role_name']) !== 'editor') {
  header('Location: selectProfile.php'); exit;
}

/* ------------------------------
   Statistika të shpejta
------------------------------- */
$counts = [
  'students' => (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn(),
  'agencies' => (int)$pdo->query("SELECT COUNT(*) FROM agencies")->fetchColumn(),
  'courses'  => (int)$pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn(),
  'groups'   => (int)$pdo->query("SELECT COUNT(*) FROM course_groups")->fetchColumn(),
];

/* Grupet e fundit */
$latestGroups = $pdo->query("
  SELECT cg.id, c.code, c.name, cg.start_date, cg.end_date, COUNT(cgs.student_id) AS members
  FROM course_groups cg
  JOIN courses c ON c.id = cg.course_id
  LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
  GROUP BY cg.id
  ORDER BY cg.start_date DESC, cg.id DESC
  LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

/* Provime të afërta (datat e testit per-student) */
$upcomingExams = $pdo->query("
  SELECT cgs.exam_date, s.nr_amze,
         COALESCE(CONCAT(TRIM(p.first_name),' ',TRIM(p.last_name)),'') AS full_name,
         c.name AS course_name, cg.id AS group_id
  FROM course_group_students cgs
  JOIN students s ON s.id = cgs.student_id
  LEFT JOIN persons p ON p.id = s.person_id
  JOIN course_groups cg ON cg.id = cgs.group_id
  JOIN courses c ON c.id = cg.course_id
  WHERE cgs.exam_date IS NOT NULL AND cgs.exam_date >= CURDATE()
  ORDER BY cgs.exam_date ASC
  LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

/* Studentë të shtuar së fundmi */
$recentStudents = $pdo->query("
  SELECT s.id, s.nr_amze,
         COALESCE(TRIM(p.first_name),'') AS first_name,
         COALESCE(TRIM(p.last_name),'')  AS last_name,
         p.personal_number
  FROM students s
  LEFT JOIN persons p ON p.id = s.person_id
  ORDER BY s.id DESC
  LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

/* Navbar i editorit */
$NAV_ACTIVE = 'dashboard';
require __DIR__ . '/inc/navbar4.php';

/* Helper për HTML */
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}
?>
<!doctype html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <title>Dashboard — Editor • QTA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root { --admin-nav-height: 64px; }
    body { background:#f5f7fb; padding-top:var(--admin-nav-height); }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
    .kpi { display:flex; align-items:center; gap:.9rem; }
    .kpi .ico { width:52px; height:52px; border-radius:.9rem; display:flex; align-items:center; justify-content:center; background:#eef2ff; }
    .kpi .lbl { color:#64748b; font-size:.95rem; }
    .table thead { background:#f1f5f9; }
    .link-muted { color:#6b7280; text-decoration:none; }
    .link-muted:hover { color:#111827; text-decoration:underline; }
  </style>
</head>
<body>

<main class="container-fluid px-3 px-md-4">

  <!-- Header -->
  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between my-3 gap-2">
    <div>
      <h2 class="mb-0">Paneli i editorit</h2>
      <div class="text-muted">Mirë se erdhe, <?= h($currentUser['full_name'] ?: $currentUser['email']) ?>!</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-dark" href="groups.php"><i class="bi bi-people me-1"></i>Regjistri me grupe</a>
      <a class="btn btn-dark" href="register.php"><i class="bi bi-journal-text me-1"></i>Regjistri i plotë</a>
    </div>
  </div>

  <!-- KPI cards -->
  <div class="row g-3">
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card p-3">
        <div class="kpi">
          <div class="ico"><i class="bi bi-mortarboard fs-4"></i></div>
          <div>
            <div class="lbl">Studentë</div>
            <div class="h4 mb-0"><?= number_format($counts['students']) ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card p-3">
        <div class="kpi">
          <div class="ico"><i class="bi bi-building fs-4"></i></div>
          <div>
            <div class="lbl">Agjenci</div>
            <div class="h4 mb-0"><?= number_format($counts['agencies']) ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card p-3">
        <div class="kpi">
          <div class="ico"><i class="bi bi-book fs-4"></i></div>
          <div>
            <div class="lbl">Modulet</div>
            <div class="h4 mb-0"><?= number_format($counts['courses']) ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
      <div class="card p-3">
        <div class="kpi">
          <div class="ico"><i class="bi bi-people fs-4"></i></div>
          <div>
            <div class="lbl">Grupe</div>
            <div class="h4 mb-0"><?= number_format($counts['groups']) ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Main grids -->
  <div class="row g-3 mt-1">
    <!-- Left: Grupet e fundit -->
    <div class="col-12 col-lg-7">
      <div class="card">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-collection me-2"></i>Grupet e fundit</h5>
          <a class="link-muted" href="groups.php">Shiko të gjitha →</a>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Kursi</th>
                  <th>Fillimi</th>
                  <th>Mbarimi</th>
                  <th class="text-center">Studentë</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($latestGroups): foreach ($latestGroups as $g): ?>
                  <tr>
                    <td class="fw-semibold">#<?= (int)$g['id'] ?></td>
                    <td><?= h(($g['code'] ? $g['code'].' · ' : '').$g['name']) ?></td>
                    <td><?= h($g['start_date']) ?></td>
                    <td><?= h($g['end_date']) ?></td>
                    <td class="text-center"><?= (int)$g['members'] ?></td>
                  </tr>
                <?php endforeach; else: ?>
                  <tr><td colspan="5" class="text-center text-muted">S’ka të dhëna.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Right: Provime + Shkurtore -->
    <div class="col-12 col-lg-5">
      <div class="card mb-3">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-calendar2-week me-2"></i>Provime në afat</h5>
        </div>
        <div class="card-body">
          <?php if ($upcomingExams): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($upcomingExams as $e): ?>
                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                  <div>
                    <div class="fw-semibold"><?= h($e['course_name']) ?> — Grup #<?= (int)$e['group_id'] ?></div>
                    <div class="text-muted small"><?= h($e['nr_amze']) ?> · <?= h($e['full_name']) ?></div>
                  </div>
                  <span class="badge rounded-pill text-bg-dark"><?= h($e['exam_date']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="text-muted">Nuk ka provime të planifikuara.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header bg-white">
          <h5 class="mb-0"><i class="bi bi-stars me-2"></i>Shkurtore</h5>
        </div>
        <div class="card-body">
          <div class="d-grid gap-2">
            <a class="btn btn-outline-dark" href="courses.php"><i class="bi bi-book me-1"></i>Menaxho modulet</a>
            <a class="btn btn-outline-dark" href="groups.php"><i class="bi bi-people me-1"></i>Menaxho grupet</a>
            <a class="btn btn-outline-dark" href="students.php"><i class="bi bi-mortarboard me-1"></i>Lista e studentëve</a>
            <div class="btn-group">
              <a class="btn btn-dark" href="#" data-bs-toggle="modal" data-bs-target="#exportsModal">
                <i class="bi bi-filetype-xlsx me-1"></i>Eksporto formularë
              </a>
              <a class="btn btn-outline-dark" href="verify.php"><i class="bi bi-qr-code-scan me-1"></i>Verifiko certifikatë</a>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-person-plus me-2"></i>Studentë të rinj</h5>
          <a class="link-muted" href="students.php">Shiko të gjithë →</a>
        </div>
        <div class="card-body">
          <?php if ($recentStudents): ?>
            <div class="list-group list-group-flush">
              <?php foreach ($recentStudents as $s): ?>
                <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                  <div>
                    <div class="fw-semibold"><?= h(trim(($s['first_name'] ?: '').' '.($s['last_name'] ?: '')) ?: '—') ?></div>
                    <div class="text-muted small">AMZË: <?= h($s['nr_amze']) ?><?= $s['personal_number'] ? ' · ID: '.h($s['personal_number']) : '' ?></div>
                  </div>
                  <span class="badge rounded-pill text-bg-secondary">#<?= (int)$s['id'] ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="text-muted">S’ka të dhëna.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="text-center text-muted small my-4">
    &copy; <?= date('Y') ?> QTA • Editor Panel
  </div>
</main>

<!-- Modal i shpejtë për eksport (Form1 / Form2) -->
<div class="modal fade" id="exportsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-download me-1"></i> Eksporto Formularë</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-grid gap-2">
          <a class="btn btn-outline-success" href="groups.php" title="Hap regjistrin e grupeve dhe zgjidh Form. 1 / Form. 2">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Formulari nr. 1 / 2
          </a>
          <div class="text-muted small">
            Përdor dialogët e eksportit brenda faqes <strong>Regjistri me grupe</strong>.
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Mbyll</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
