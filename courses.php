<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* ------------------------------
   Guard: vetëm admin i loguar
------------------------------- */
if (!isset($_SESSION['user_id'])) {
    header('Location: selectProfile.php'); exit;
}

$userStmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, r.name AS role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id = :uid
    LIMIT 1
");
$userStmt->execute([':uid' => $_SESSION['user_id']]);
$currentUser = $userStmt->fetch();

if (!$currentUser || $currentUser['role_name'] !== 'administrator') {
    header('Location: selectProfile.php'); exit;
}

/* ------------------------------
   CSRF & Flash helpers
------------------------------- */
function flash(string $key, ?string $msg=null) {
    if ($msg === null) {
        if (!empty($_SESSION['flash'][$key])) { $m = $_SESSION['flash'][$key]; unset($_SESSION['flash'][$key]); return $m; }
        return null;
    }
    $_SESSION['flash'][$key] = $msg;
}
function require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf'] ?? '';
        if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(400); exit('CSRF token mismatch.');
        }
    }
}
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

/* ------------------------------
   POST: create / update / delete
------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_course') {
            $code  = strtoupper(trim($_POST['code'] ?? ''));
            $name  = trim($_POST['name'] ?? '');
            $hours = (int)($_POST['hours'] ?? 0);

            if ($code === '' || $name === '' || $hours <= 0) {
                throw new RuntimeException('Plotësoni KOD, EMËR dhe ORE (>0).');
            }
            // Lejo A-Z, 0-9, -, _
            if (!preg_match('/^[A-Z0-9\-_]{2,50}$/', $code)) {
                throw new RuntimeException('KOD i pavlefshëm. Përdorni A-Z, 0-9, - ose _.');
            }

            // unik code
            $q = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE code = :c");
            $q->execute([':c'=>$code]);
            if ((int)$q->fetchColumn() > 0) {
                throw new RuntimeException('Ky KOD ekziston tashmë.');
            }

            $ins = $pdo->prepare("INSERT INTO courses (code, name, hours) VALUES (:c,:n,:h)");
            $ins->execute([':c'=>$code, ':n'=>$name, ':h'=>$hours]);

            flash('ok', 'Moduli u shtua me sukses.');
        }

        elseif ($action === 'update_course') {
            $id    = (int)($_POST['course_id'] ?? 0);
            $code  = strtoupper(trim($_POST['code'] ?? ''));
            $name  = trim($_POST['name'] ?? '');
            $hours = (int)($_POST['hours'] ?? 0);

            if ($id <= 0) throw new RuntimeException('ID e pavlefshme.');
            if ($code === '' || $name === '' || $hours <= 0) {
                throw new RuntimeException('Plotësoni KOD, EMËR dhe ORE (>0).');
            }
            if (!preg_match('/^[A-Z0-9\-_]{2,50}$/', $code)) {
                throw new RuntimeException('KOD i pavlefshëm. Përdorni A-Z, 0-9, - ose _.');
            }

            // unik code përveç vetes
            $q = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE code = :c AND id <> :id");
            $q->execute([':c'=>$code, ':id'=>$id]);
            if ((int)$q->fetchColumn() > 0) {
                throw new RuntimeException('Ky KOD përdoret nga modul tjetër.');
            }

            $upd = $pdo->prepare("UPDATE courses SET code=:c, name=:n, hours=:h WHERE id=:id");
            $upd->execute([':c'=>$code, ':n'=>$name, ':h'=>$hours, ':id'=>$id]);

            flash('ok', 'Moduli u përditësua me sukses.');
        }

        elseif ($action === 'delete_course') {
            $id = (int)($_POST['course_id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ID e pavlefshme.');

            $del = $pdo->prepare("DELETE FROM courses WHERE id = :id");
            $del->execute([':id'=>$id]);

            flash('ok', 'Moduli u fshi.');
        }

    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }

    header('Location: courses.php'); exit;
}

/* ------------------------------
   Kërkim + Paginim
------------------------------- */
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where  = [];
$params = [];

if ($q !== '') {
    $where[] = "(code LIKE :kw1 OR name LIKE :kw2)";
    $kw = '%'.$q.'%';
    $params[':kw1'] = $kw;
    $params[':kw2'] = $kw;
}
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* total */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM courses $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));

/* list */
$listStmt = $pdo->prepare("
    SELECT id, code, name, hours, created_at
    FROM courses
    $whereSql
    ORDER BY code ASC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v, PDO::PARAM_STR);
}
$listStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$courses = $listStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="UTF-8" />
    <title>Modulet – QTA Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <!-- Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>

    <style>
        body { background:#f5f7fb; padding-top:72px; }
        .navbar-brand img { height:28px; }
        .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
        .mini-table thead { background:#f1f5f9; }
        .form-control::placeholder { color:#9ca3af; }
        .pagination .page-link { border-radius:.5rem; }
        @media (max-width: 575.98px) { .navbar-text { display:none; } }
    </style>
</head>
<body>

<!-- NAVBAR (pa sidebar) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center" href="dashboard_admin.php">
            <img src="image/logoPNG2.png" class="me-2" alt="QTA"> QTA – Paneli i Administratorit
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Sipas kërkesës tënde -->
        <div class="collapse navbar-collapse" id="topNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link" href="dashboard_admin.php">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboardi
                    </a>
                </li>

                <!-- Dropdown: Përdorues -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="usersDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-people me-1"></i>Përdorues
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="usersDropdown">
                        <li><a class="dropdown-item" href="users.php"><i class="bi bi-shield-lock me-2"></i>Administratorët</a></li>
                        <li><a class="dropdown-item" href="agencies.php"><i class="bi bi-building me-2"></i>Agjencitë</a></li>
                        <li><a class="dropdown-item" href="students.php"><i class="bi bi-mortarboard me-2"></i>Studentët</a></li>
                    </ul>
                </li>

                <li class="nav-item">
                    <a class="nav-link active" aria-current="page" href="courses.php">
                        <i class="bi bi-book me-1"></i>Modulet
                    </a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <span class="text-white-50 small navbar-text">Mirësevjen,</span>
                <span class="text-white fw-semibold navbar-text">
                    <i class="bi bi-person-circle me-1"></i>
                    <?= htmlspecialchars($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Administrator')) ?>
                </span>
                <a href="logout.php" class="btn btn-outline-light btn-sm ms-1">
                    <i class="bi bi-box-arrow-right me-1"></i>Dil
                </a>
            </div>
        </div>
    </div>
</nav>

<main class="container-fluid px-3 px-md-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
        <h2 class="mb-0">Modulet e QTA</h2>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCourseModal">
                <i class="bi bi-bookmark-plus me-1"></i> Shto Modul
            </button>
        </div>
    </div>

    <?php if ($m = flash('ok')): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($m) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($m = flash('err')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($m) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Kërkim -->
    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get" action="courses.php">
                <div class="col-md-9">
                    <label class="form-label">Kërko</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control border-0"
                               placeholder="KOD ose EMËR moduli..."
                               value="<?= htmlspecialchars($q) ?>">
                    </div>
                </div>
                <div class="col-md-3 text-end">
                    <button class="btn btn-outline-secondary me-1" type="button"
                            onclick="window.location='courses.php'"><i class="bi bi-x-circle me-1"></i>Pastro</button>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apliko</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela e moduleve -->
    <div class="card">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
            <h5 class="mb-0"><i class="bi bi-book me-2"></i>Lista e moduleve</h5>
            <span class="text-muted small"><?= number_format($total) ?> rezultat(e)</span>
        </div>
        <div class="card-body">
            <div class="table-responsive mini-table">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:90px">ID</th>
                            <th>KOD</th>
                            <th>EMËR</th>
                            <th style="width:120px">ORE</th>
                            <th style="width:190px">Regjistruar</th>
                            <th class="text-end" style="width:180px">Veprime</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($courses): ?>
                        <?php foreach ($courses as $c): ?>
                            <tr>
                                <td class="text-muted">#<?= (int)$c['id'] ?></td>
                                <td><span class="badge text-bg-primary"><?= htmlspecialchars($c['code']) ?></span></td>
                                <td><?= htmlspecialchars($c['name']) ?></td>
                                <td><span class="badge text-bg-info"><?= (int)$c['hours'] ?></span></td>
                                <td class="text-muted"><?= htmlspecialchars($c['created_at']) ?></td>
                                <td class="text-end">
                                    <!-- Edit -->
                                    <button class="btn btn-sm btn-outline-primary me-1"
                                            data-bs-toggle="modal" data-bs-target="#editCourseModal"
                                            data-course-id="<?= (int)$c['id'] ?>"
                                            data-code="<?= htmlspecialchars($c['code'], ENT_QUOTES) ?>"
                                            data-name="<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>"
                                            data-hours="<?= (int)$c['hours'] ?>">
                                        <i class="bi bi-pencil-square me-1"></i>Modifiko
                                    </button>
                                    <!-- Delete -->
                                    <form class="d-inline" method="post" onsubmit="return confirm('Fshini këtë modul?');">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
                                        <input type="hidden" name="action" value="delete_course">
                                        <input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash me-1"></i>Fshi
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center text-muted">Nuk u gjet asnjë modul.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Paginim -->
        <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white">
            <nav aria-label="Page navigation">
                <ul class="pagination mb-0 justify-content-end">
                    <?php
                    $base = 'courses.php?'.http_build_query(array_filter([
                        'q' => $q !== '' ? $q : null,
                    ]));
                    $prev = max(1, $page-1);
                    $next = min($totalPages, $page+1);
                    ?>
                    <li class="page-item <?= $page<=1?'disabled':'' ?>">
                        <a class="page-link" href="<?= $base.(strpos($base,'?')!==false?'&':'?') ?>page=1">«</a>
                    </li>
                    <li class="page-item <?= $page<=1?'disabled':'' ?>">
                        <a class="page-link" href="<?= $base.(strpos($base,'?')!==false?'&':'?') ?>page=<?= $prev ?>">‹</a>
                    </li>
                    <li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $totalPages ?></span></li>
                    <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
                        <a class="page-link" href="<?= $base.(strpos($base,'?')!==false?'&':'?') ?>page=<?= $next ?>">›</a>
                    </li>
                    <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
                        <a class="page-link" href="<?= $base.(strpos($base,'?')!==false?'&':'?') ?>page=<?= $totalPages ?>">»</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>

    <div class="text-center text-muted small mt-4">
        &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
    </div>
</main>

<!-- MODALS -->

<!-- Modal: Shto Modul -->
<div class="modal fade" id="addCourseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="action" value="create_course">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-bookmark-plus me-1"></i> Shto modul</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">KOD *</label>
            <input type="text" name="code" class="form-control" required placeholder="p.sh. QTA-ALG1">
            <div class="form-text">Lejohen shkronja A-Z, numra, '-' dhe '_'. Ruhet me shkronja të mëdha.</div>
        </div>
        <div class="mb-3">
            <label class="form-label">EMËR *</label>
            <input type="text" name="name" class="form-control" required placeholder="p.sh. Algoritme Bazë">
        </div>
        <div class="mb-3">
            <label class="form-label">ORE *</label>
            <input type="number" name="hours" class="form-control" required min="1" max="1000" placeholder="p.sh. 60">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit">Shto modul</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Modifiko Modul -->
<div class="modal fade" id="editCourseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="action" value="update_course">
      <input type="hidden" name="course_id" id="edit_course_id">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-1"></i> Modifiko modul</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">KOD *</label>
            <input type="text" name="code" id="edit_code" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">EMËR *</label>
            <input type="text" name="name" id="edit_name" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">ORE *</label>
            <input type="number" name="hours" id="edit_hours" class="form-control" required min="1" max="1000">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit">Ruaj ndryshimet</button>
      </div>
    </form>
  </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Populate Edit Course modal
const editModal = document.getElementById('editCourseModal');
editModal?.addEventListener('show.bs.modal', (event) => {
    const btn = event.relatedTarget;
    document.getElementById('edit_course_id').value = btn.getAttribute('data-course-id');
    document.getElementById('edit_code').value      = btn.getAttribute('data-code') || '';
    document.getElementById('edit_name').value      = btn.getAttribute('data-name') || '';
    document.getElementById('edit_hours').value     = btn.getAttribute('data-hours') || '';
});
</script>
</body>
</html>
