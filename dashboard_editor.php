<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';
$pdo = getPDO();

/* Guard: vetëm EDITOR i loguar */
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

/* Pak statistika të shpejta */
$counts = [
  'students' => (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn(),
  'agencies' => (int)$pdo->query("SELECT COUNT(*) FROM agencies")->fetchColumn(),
  'courses'  => (int)$pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn(),
  'groups'   => (int)$pdo->query("SELECT COUNT(*) FROM course_groups")->fetchColumn(),
];

/* 5 grupet e fundit me numër studentësh */
$latestGroups = $pdo->query("
  SELECT cg.id, c.code, c.name, cg.start_date, cg.end_date, COUNT(cgs.student_id) AS members
  FROM course_groups cg
  JOIN courses c ON c.id = cg.course_id
  LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
  GROUP BY cg.id
  ORDER BY cg.start_date DESC, cg.id DESC
  LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

/* navbar editor */
$NAV_ACTIVE = 'dashboard';
require __DIR__ . '/inc/navbar4.php';
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
    body { background:#f5f7fb; }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
  </style>
</head>
<body>

<main class="container-fluid px-3 px-md-4">
  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between my-3 gap-2">
    <h2 class="mb-0">Përmbledhje e editorit</h2>
  </div>

  <div class="row g-3">
    <div class="col-sm-6 col-lg-3">
      <div class="card p-3">
        <div class="d-flex align-items-center">
          <div class="me-3 fs-3"><i class="bi bi-mortarboard"></i></div>
          <div>
            <div class="text-muted">Studentë</div>
            <div class="h4 mb-0"><?= number_format($counts['students']) ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="card p-3">
        <div class="d-flex align-items-center">
          <div class="me-3 fs-3"><i class="bi bi-building"></i></div>
          <div>
            <div class="text-muted">Agjenci</div>
            <div class="h4 mb-0"><?= number_format($counts['agencies']) ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="card p-3">
        <div class="d-flex align-items-center">
          <div class="me-3 fs-3"><i class="bi bi-book"></i></div>
          <div>
            <div class="text-muted">Modulet</div>
            <div class="h4 mb-0"><?= number_format($counts['courses']) ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="card p-3">
        <div class="d-flex align-items-center">
          <div class="me-3 fs-3"><i class="bi bi-people"></i></div>
          <div>
            <div class="text-muted">Grupe</div>
            <div class="h4 mb-0"><?= number_format($counts['groups']) ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Grupet e fundit -->
  <div class="card my-4">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
      <h5 class="mb-0"><i class="bi bi-collection me-2"></i>Grupet e fundit</h5>
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
              <th>Studentë</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($latestGroups): foreach ($latestGroups as $g): ?>
              <tr>
                <td class="fw-semibold">#<?= (int)$g['id'] ?></td>
                <td><?= htmlspecialchars(($g['code']? $g['code'].' · ' : '').$g['name']) ?></td>
                <td><?= htmlspecialchars($g['start_date']) ?></td>
                <td><?= htmlspecialchars($g['end_date']) ?></td>
                <td><?= (int)$g['members'] ?></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="5" class="text-center text-muted">S’ka të dhëna.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="text-center text-muted small my-4">
    &copy; <?= date('Y') ?> QTA • Editor Panel
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
