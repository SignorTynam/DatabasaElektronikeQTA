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
   Helpers
------------------------------- */
function flash(string $key, ?string $msg=null) {
    if ($msg === null) {
        if (!empty($_SESSION['flash'][$key])) {
            $m = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $m;
        }
        return null;
    }
    $_SESSION['flash'][$key] = $msg;
}

function require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf'] ?? '';
        if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(400);
            exit('CSRF token mismatch.');
        }
    }
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$CSRF = $_SESSION['csrf_token'];

/* ------------------------------
   Merr rolet & gjej adminRoleId
------------------------------- */
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$adminRoleId = null;
foreach ($roles as $r) if ($r['name'] === 'administrator') { $adminRoleId = (int)$r['id']; break; }
if ($adminRoleId === null) {
    exit('Konfigurim i mangët: roli "administrator" mungon në tabelën roles.');
}

/* ------------------------------
   Veprime POST (create/reset/delete)
   (tani të lejuara vetëm mbi llogari me rol administrator)
------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_admin') {
            $full_name = trim($_POST['full_name'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $pass1     = $_POST['password'] ?? '';
            $pass2     = $_POST['password2'] ?? '';

            if ($full_name === '' || $email === '' || $pass1 === '' || $pass2 === '') {
                throw new RuntimeException('Ju lutem plotësoni të gjitha fushat.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Email i pavlefshëm.');
            }
            if ($pass1 !== $pass2) {
                throw new RuntimeException('Fjalëkalimet nuk përputhen.');
            }

            // Unik email
            $exists = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :e");
            $exists->execute([':e' => $email]);
            if ((int)$exists->fetchColumn() > 0) {
                throw new RuntimeException('Ky email ekziston tashmë.');
            }

            $pdo->beginTransaction();
            // users
            $insUser = $pdo->prepare("
                INSERT INTO users (role_id, full_name, email)
                VALUES (:rid, :fn, :em)
            ");
            $insUser->execute([
                ':rid' => $adminRoleId,
                ':fn'  => $full_name,
                ':em'  => $email
            ]);
            $newUserId = (int)$pdo->lastInsertId();

            // credentials
            $hash = password_hash($pass1, PASSWORD_BCRYPT);
            $insCred = $pdo->prepare("
                INSERT INTO credentials (user_id, password_hash, last_password_change)
                VALUES (:uid, :ph, NOW())
            ");
            $insCred->execute([':uid' => $newUserId, ':ph' => $hash]);

            // admins
            $empCode = 'ADM' . str_pad((string)$newUserId, 5, '0', STR_PAD_LEFT);
            $insAdmin = $pdo->prepare("
                INSERT INTO admins (user_id, employee_code)
                VALUES (:uid, :code)
            ");
            $insAdmin->execute([':uid' => $newUserId, ':code' => $empCode]);

            $pdo->commit();
            flash('ok', 'Administratori u shtua me sukses.');
        }
        elseif ($action === 'reset_password') {
            $uid   = (int)($_POST['user_id'] ?? 0);
            $pass1 = $_POST['new_password'] ?? '';
            $pass2 = $_POST['new_password2'] ?? '';
            if ($uid <= 0 || $pass1==='' || $pass2==='') throw new RuntimeException('Të dhëna të paplota.');
            if ($pass1 !== $pass2) throw new RuntimeException('Fjalëkalimet nuk përputhen.');

            // Lejo vetëm nëse target-i është administrator
            $roleQ = $pdo->prepare("SELECT role_id FROM users WHERE id = :uid");
            $roleQ->execute([':uid' => $uid]);
            $targetRole = $roleQ->fetchColumn();
            if (!$targetRole || (int)$targetRole !== $adminRoleId) {
                throw new RuntimeException('Veprimi lejohet vetëm për llogari administrator.');
            }

            $hash = password_hash($pass1, PASSWORD_BCRYPT);

            // Update ose insert në credentials
            $exists = $pdo->prepare("SELECT COUNT(*) FROM credentials WHERE user_id = :uid");
            $exists->execute([':uid' => $uid]);
            if ((int)$exists->fetchColumn() > 0) {
                $upd = $pdo->prepare("
                    UPDATE credentials
                    SET password_hash = :ph, last_password_change = NOW()
                    WHERE user_id = :uid
                ");
                $upd->execute([':ph' => $hash, ':uid' => $uid]);
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO credentials (user_id, password_hash, last_password_change)
                    VALUES (:uid, :ph, NOW())
                ");
                $ins->execute([':uid' => $uid, ':ph' => $hash]);
            }
            flash('ok', 'Fjalëkalimi u ndryshua me sukses.');
        }
        elseif ($action === 'delete_user') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid <= 0) throw new RuntimeException('ID e pavlefshme.');

            if ($uid === (int)$currentUser['id']) {
                throw new RuntimeException('Nuk mund të fshini llogarinë tuaj gjatë seancës.');
            }

            // Lejo fshirje vetëm nëse target-i është administrator
            $roleQ = $pdo->prepare("SELECT role_id FROM users WHERE id = :uid");
            $roleQ->execute([':uid' => $uid]);
            $targetRole = $roleQ->fetchColumn();
            if (!$targetRole || (int)$targetRole !== $adminRoleId) {
                throw new RuntimeException('Fshirja lejohet vetëm për llogari administrator.');
            }

            $del = $pdo->prepare("DELETE FROM users WHERE id = :uid");
            $del->execute([':uid' => $uid]);
            flash('ok', 'Administratori u fshi.');
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flash('err', $e->getMessage());
    }

    // Redirect që të shmangim ri-submit
    header('Location: users.php'); exit;
}

/* ------------------------------
   Filtrim vetëm për administratorë + paginim
------------------------------- */
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where   = ["r.id = :adminRole"];    // Kusht i detyrueshëm: VETËM admin
$params  = [':adminRole' => $adminRoleId];

if ($q !== '') {
    $where[] = "(u.full_name LIKE :kw OR u.email LIKE :kw)";
    $params[':kw'] = '%'.$q.'%';
}
$whereSql = 'WHERE '.implode(' AND ', $where);

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM users u
    JOIN roles r ON r.id = u.role_id
    $whereSql
");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));

$listStmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, u.created_at
    FROM users u
    JOIN roles r ON r.id = u.role_id
    $whereSql
    ORDER BY u.created_at DESC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
}
$listStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$users = $listStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="UTF-8" />
    <title>Administratorët – QTA Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <!-- Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>

    <style>
        body { background: #f5f7fb; }
        .navbar-brand img { height: 28px; }
        .sidebar {
            position: fixed; top: 0; left: 0; width: 240px; height: 100vh;
            background: #111827; color: #cbd5e1; padding-top: 64px; z-index: 1029;
        }
        .sidebar a {
            display: block; padding: 12px 18px; color: #cbd5e1; text-decoration: none; border-radius: .5rem;
            margin: 6px 10px; transition: .2s ease;
        }
        .sidebar a:hover, .sidebar a.active { background: #2563eb; color: #fff; }
        .content { margin-left: 260px; padding: 24px; }
        .card { border: none; border-radius: 1rem; box-shadow: 0 10px 25px rgba(2,6,23,.06); }
        .mini-table thead { background: #f1f5f9; }
        .kpi-icon {
            width: 46px; height: 46px; border-radius: .75rem; display:flex; align-items:center; justify-content:center; background:#eef2ff;
        }
        .form-control::placeholder { color:#9ca3af; }
        .pagination .page-link { border-radius: .5rem; }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark bg-dark fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center" href="dashboard_admin.php">
            <img src="image/logoPNG2.png" class="me-2" alt="QTA"> QTA – Paneli i Administratorit
        </a>
        <div class="d-flex align-items-center gap-3">
            <span class="text-white-50 small d-none d-md-inline">Mirësevjen,</span>
            <span class="text-white fw-semibold">
                <i class="bi bi-person-circle me-1"></i>
                <?= htmlspecialchars($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Administrator')) ?>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right me-1"></i>Dil</a>
        </div>
    </div>
</nav>

<!-- Sidebar (etiketa e menusë ndryshon në “Administratorët”) -->
<aside class="sidebar">
    <a href="dashboard_admin.php"><i class="bi bi-speedometer2 me-2"></i> Dashboardi</a>
    <a class="active" href="users.php"><i class="bi bi-people me-2"></i> Administratorët</a>
    <a href="agencies.php"><i class="bi bi-building me-2"></i> Agjencitë</a>
    <a href="#"><i class="bi bi-mortarboard me-2"></i> Studentët</a>
    <a href="#"><i class="bi bi-bar-chart me-2"></i> Raportet</a>
    <a href="index.html"><i class="bi bi-house me-2"></i> Kryefaqja</a>
</aside>

<main class="content" style="margin-top: 50px">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h2 class="mb-0">Menaxhimi i administratorëve</h2>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAdminModal">
                <i class="bi bi-person-plus me-1"></i> Shto Administrator
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

    <!-- Kërkim (vetëm tekst) -->
    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get" action="users.php">
                <div class="col-md-9">
                    <label class="form-label">Kërko</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control border-0" placeholder="Emër ose email..."
                               value="<?= htmlspecialchars($q) ?>">
                    </div>
                </div>
                <div class="col-md-3 text-end">
                    <button class="btn btn-outline-secondary me-1" type="button"
                            onclick="window.location='users.php'"><i class="bi bi-x-circle me-1"></i>Pastro</button>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apliko</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela (vetëm administratorë) -->
    <div class="card">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
            <h5 class="mb-0"><i class="bi bi-people me-2"></i>Lista e administratorëve</h5>
            <span class="text-muted small"><?= number_format($total) ?> rezultat(e)</span>
        </div>
        <div class="card-body">
            <div class="table-responsive mini-table">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:80px">ID</th>
                            <th>Emri</th>
                            <th>Email</th>
                            <th>Roli</th>
                            <th>Regjistruar</th>
                            <th class="text-end">Veprime</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($users): ?>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td class="text-muted">#<?= (int)$u['id'] ?></td>
                                <td><?= htmlspecialchars($u['full_name'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($u['email'] ?: '—') ?></td>
                                <td>
                                    <span class="badge rounded-pill text-bg-danger">Administrator</span>
                                </td>
                                <td class="text-muted"><?= htmlspecialchars($u['created_at']) ?></td>
                                <td class="text-end">
                                    <!-- Reset Password -->
                                    <button class="btn btn-sm btn-outline-secondary me-1"
                                            data-bs-toggle="modal"
                                            data-bs-target="#resetPassModal"
                                            data-user-id="<?= (int)$u['id'] ?>"
                                            data-user-name="<?= htmlspecialchars($u['full_name'] ?: ($u['email'] ?? 'Administrator')) ?>">
                                        <i class="bi bi-key me-1"></i>Reset
                                    </button>
                                    <!-- Delete -->
                                    <form class="d-inline" method="post" onsubmit="return confirm('Fshini këtë administrator?');">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash me-1"></i>Fshi
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center text-muted">Nuk u gjet asnjë administrator.</td></tr>
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
                    // url bazë (vetëm q & page)
                    $base = 'users.php?'.http_build_query(array_filter([
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

<!-- Modal: Shto Administrator -->
<div class="modal fade" id="addAdminModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="action" value="create_admin">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-plus me-1"></i> Shto Administrator</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">Emri i plotë</label>
            <input type="text" name="full_name" class="form-control" required placeholder="P.sh. Arben Hoxha">
        </div>
        <div class="mb-3">
            <label class="form-label">Email (unik)</label>
            <input type="email" name="email" class="form-control" required placeholder="admin@qta.al">
        </div>
        <div class="row g-2">
            <div class="col-12 col-md-6">
                <label class="form-label">Fjalëkalimi</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label">Përsërit fjalëkalimin</label>
                <input type="password" name="password2" class="form-control" required>
            </div>
        </div>
        <div class="form-text mt-2">
            Krijon rreshta në <code>users</code>, <code>credentials</code> dhe <code>admins</code>.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit">Ruaj</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Reset Password -->
<div class="modal fade" id="resetPassModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="reset_user_id">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-key me-1"></i> Ndrysho fjalëkalimin</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
            <label class="form-label">Përdoruesi</label>
            <input type="text" id="reset_user_name" class="form-control" disabled>
        </div>
        <div class="row g-2">
            <div class="col-md-6">
                <label class="form-label">Fjalëkalimi i ri</label>
                <input type="password" name="new_password" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Përsërit fjalëkalimin</label>
                <input type="password" name="new_password2" class="form-control" required>
            </div>
        </div>
        <div class="form-text">Fjalëkalimi ruhet i hash-uar me <code>PASSWORD_BCRYPT</code>.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit">Ruaj</button>
      </div>
    </form>
  </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const resetModal = document.getElementById('resetPassModal');
resetModal?.addEventListener('show.bs.modal', event => {
    const btn = event.relatedTarget;
    document.getElementById('reset_user_id').value = btn.getAttribute('data-user-id');
    document.getElementById('reset_user_name').value = btn.getAttribute('data-user-name');
});
</script>
</body>
</html>
