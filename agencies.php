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
   Gjej rolin 'agjencia'
------------------------------- */
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$agencyRoleId = null;
foreach ($roles as $r) if ($r['name'] === 'agjencia') { $agencyRoleId = (int)$r['id']; break; }
if ($agencyRoleId === null) { exit('Konfigurim i mangët: roli "agjencia" mungon në tabelën roles.'); }

/* ------------------------------
   Veprime POST: create/update/reset/delete
------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_agency') {
            $company_name = trim($_POST['company_name'] ?? '');
            $nip_t        = trim($_POST['nip_t'] ?? '');
            $phone        = trim($_POST['phone'] ?? '');
            $address      = trim($_POST['address'] ?? '');
            $pass1        = $_POST['password'] ?? '';
            $pass2        = $_POST['password2'] ?? '';

            if ($company_name === '' || $nip_t === '' || $pass1 === '' || $pass2 === '') {
                throw new RuntimeException('Ju lutem plotësoni fushat e detyrueshme (Emri, NIPT, Fjalëkalimi).');
            }
            if ($pass1 !== $pass2) {
                throw new RuntimeException('Fjalëkalimet nuk përputhen.');
            }

            // NIPT unik
            $exists = $pdo->prepare("SELECT COUNT(*) FROM agencies WHERE nip_t = :n");
            $exists->execute([':n'=>$nip_t]);
            if ((int)$exists->fetchColumn() > 0) {
                throw new RuntimeException('Ky NIPT ekziston tashmë.');
            }

            $pdo->beginTransaction();

            // 1) users (email mund të jetë NULL për agjenci; ruajmë emrin e kompanisë në full_name për shfaqje)
            $insUser = $pdo->prepare("
                INSERT INTO users (role_id, full_name, email)
                VALUES (:rid, :fn, NULL)
            ");
            $insUser->execute([':rid'=>$agencyRoleId, ':fn'=>$company_name]);
            $newUserId = (int)$pdo->lastInsertId();

            // 2) credentials
            $hash = password_hash($pass1, PASSWORD_BCRYPT);
            $insCred = $pdo->prepare("
                INSERT INTO credentials (user_id, password_hash, last_password_change)
                VALUES (:uid, :ph, NOW())
            ");
            $insCred->execute([':uid'=>$newUserId, ':ph'=>$hash]);

            // 3) agencies
            $insAgency = $pdo->prepare("
                INSERT INTO agencies (user_id, nip_t, company_name, address, phone)
                VALUES (:uid, :nip, :cn, :ad, :ph)
            ");
            $insAgency->execute([
                ':uid'=>$newUserId, ':nip'=>$nip_t, ':cn'=>$company_name, ':ad'=>$address, ':ph'=>$phone
            ]);

            $pdo->commit();
            flash('ok', 'Agjencia u shtua me sukses.');
        }

        elseif ($action === 'update_agency') {
            $agency_id    = (int)($_POST['agency_id'] ?? 0);
            $company_name = trim($_POST['company_name'] ?? '');
            $nip_t        = trim($_POST['nip_t'] ?? '');
            $phone        = trim($_POST['phone'] ?? '');
            $address      = trim($_POST['address'] ?? '');

            if ($agency_id <= 0) throw new RuntimeException('ID agjencie e pavlefshme.');
            if ($company_name === '' || $nip_t === '') throw new RuntimeException('Emri i agjencisë dhe NIPT janë të detyrueshme.');

            // Verifiko që agency_id i përket një user-i me rol agjencia
            $chk = $pdo->prepare("
                SELECT a.user_id
                FROM agencies a
                JOIN users u ON u.id = a.user_id
                WHERE a.id = :aid AND u.role_id = :rid
                LIMIT 1
            ");
            $chk->execute([':aid'=>$agency_id, ':rid'=>$agencyRoleId]);
            $row = $chk->fetch();
            if (!$row) throw new RuntimeException('Agjencia nuk u gjet ose nuk ka rolin e duhur.');
            $userIdOfAgency = (int)$row['user_id'];

            // Unik NIPT (për agjenci të tjera)
            $exists = $pdo->prepare("SELECT COUNT(*) FROM agencies WHERE nip_t = :n AND id <> :aid");
            $exists->execute([':n'=>$nip_t, ':aid'=>$agency_id]);
            if ((int)$exists->fetchColumn() > 0) throw new RuntimeException('Ky NIPT ekziston për një agjenci tjetër.');

            // Update agencies
            $upd = $pdo->prepare("
                UPDATE agencies
                SET company_name = :cn, nip_t = :nip, phone = :ph, address = :ad
                WHERE id = :aid
            ");
            $upd->execute([
                ':cn'=>$company_name, ':nip'=>$nip_t, ':ph'=>$phone, ':ad'=>$address, ':aid'=>$agency_id
            ]);

            // Sinkronizo edhe users.full_name (opsionale por ndihmon në listime të tjera)
            $updUser = $pdo->prepare("UPDATE users SET full_name = :fn WHERE id = :uid");
            $updUser->execute([':fn'=>$company_name, ':uid'=>$userIdOfAgency]);

            flash('ok', 'Të dhënat e agjencisë u përditësuan me sukses.');
        }

        elseif ($action === 'reset_password') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $pass1   = $_POST['new_password'] ?? '';
            $pass2   = $_POST['new_password2'] ?? '';

            if ($user_id <= 0 || $pass1 === '' || $pass2 === '') throw new RuntimeException('Të dhëna të paplota.');
            if ($pass1 !== $pass2) throw new RuntimeException('Fjalëkalimet nuk përputhen.');

            // Lejo vetëm për user me rol agjencia
            $roleQ = $pdo->prepare("SELECT role_id FROM users WHERE id = :uid");
            $roleQ->execute([':uid'=>$user_id]);
            $rid = $roleQ->fetchColumn();
            if (!$rid || (int)$rid !== $agencyRoleId) throw new RuntimeException('Veprimi lejohet vetëm për agjenci.');

            $hash = password_hash($pass1, PASSWORD_BCRYPT);

            $exists = $pdo->prepare("SELECT COUNT(*) FROM credentials WHERE user_id = :uid");
            $exists->execute([':uid'=>$user_id]);
            if ((int)$exists->fetchColumn() > 0) {
                $upd = $pdo->prepare("UPDATE credentials SET password_hash=:ph, last_password_change=NOW() WHERE user_id=:uid");
                $upd->execute([':ph'=>$hash, ':uid'=>$user_id]);
            } else {
                $ins = $pdo->prepare("INSERT INTO credentials (user_id, password_hash, last_password_change) VALUES (:uid,:ph,NOW())");
                $ins->execute([':uid'=>$user_id, ':ph'=>$hash]);
            }
            flash('ok', 'Fjalëkalimi u ndryshua me sukses.');
        }

        elseif ($action === 'delete_agency') {
            $agency_id = (int)($_POST['agency_id'] ?? 0);
            if ($agency_id <= 0) throw new RuntimeException('ID agjencie e pavlefshme.');

            // Gjej user_id dhe konfirmo rolin
            $q = $pdo->prepare("
                SELECT a.user_id
                FROM agencies a
                JOIN users u ON u.id = a.user_id
                WHERE a.id = :aid AND u.role_id = :rid
                LIMIT 1
            ");
            $q->execute([':aid'=>$agency_id, ':rid'=>$agencyRoleId]);
            $row = $q->fetch();
            if (!$row) throw new RuntimeException('Agjencia nuk u gjet ose nuk ka rolin e duhur.');

            $uid = (int)$row['user_id'];

            // Mos lejo fshirjen nëse është vetë user-i aktiv (për siguri)
            if ($uid === (int)$currentUser['id']) {
                throw new RuntimeException('Nuk mund të fshini llogarinë tuaj gjatë seancës.');
            }

            // Fshi user-in -> CASCADE fshin agencies
            $del = $pdo->prepare("DELETE FROM users WHERE id = :uid");
            $del->execute([':uid'=>$uid]);

            flash('ok', 'Agjencia u fshi.');
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flash('err', $e->getMessage());
    }

    header('Location: agencies.php'); exit;
}

/* ------------------------------
   Kërkim + Paginim (vetëm agjenci)
------------------------------- */
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where  = ["r.id = :agencyRole"];
$params = [':agencyRole' => $agencyRoleId];

if ($q !== '') {
    $where[] = "(a.company_name LIKE :kw OR a.nip_t LIKE :kw OR a.phone LIKE :kw OR a.address LIKE :kw)";
    $params[':kw'] = '%'.$q.'%';
}
$whereSql = 'WHERE '.implode(' AND ', $where);

/* Numri total */
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM agencies a
    JOIN users u ON u.id = a.user_id
    JOIN roles r ON r.id = u.role_id
    $whereSql
");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));

/* Lista */
$listStmt = $pdo->prepare("
    SELECT
        a.id              AS agency_id,
        a.company_name,
        a.nip_t,
        a.phone,
        a.address,
        u.id              AS user_id,
        u.created_at
    FROM agencies a
    JOIN users u ON u.id = a.user_id
    JOIN roles r ON r.id = u.role_id
    $whereSql
    ORDER BY u.created_at DESC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$agencies = $listStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="UTF-8" />
    <title>Agjencitë – QTA Admin</title>
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
        .form-control::placeholder { color:#9ca3af; }
        .pagination .page-link { border-radius: .5rem; }
        .truncate-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    </style>
</head>
<body>

<!-- Navbar (identik) -->
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

<!-- Sidebar (Agjencitë aktive) -->
<aside class="sidebar">
    <a href="dashboard_admin.php"><i class="bi bi-speedometer2 me-2"></i> Dashboardi</a>
    <a href="users.php"><i class="bi bi-people me-2"></i> Administratorët</a>
    <a class="active" href="agencies.php"><i class="bi bi-building me-2"></i> Agjencitë</a>
    <a href="students.php"><i class="bi bi-mortarboard me-2"></i> Studentët</a>
    <a href="#"><i class="bi bi-bar-chart me-2"></i> Raportet</a>
    <a href="index.html"><i class="bi bi-house me-2"></i> Kryefaqja</a>
</aside>

<main class="content" style="margin-top: 50px">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h2 class="mb-0">Agjencitë e regjistruara</h2>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAgencyModal">
                <i class="bi bi-building-add me-1"></i> Shto Agjenci
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
            <form class="row g-2 align-items-end" method="get" action="agencies.php">
                <div class="col-md-9">
                    <label class="form-label">Kërko</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control border-0"
                               placeholder="Emër agjencie, NIPT, telefon ose adresë..."
                               value="<?= htmlspecialchars($q) ?>">
                    </div>
                </div>
                <div class="col-md-3 text-end">
                    <button class="btn btn-outline-secondary me-1" type="button"
                            onclick="window.location='agencies.php'"><i class="bi bi-x-circle me-1"></i>Pastro</button>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apliko</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela e agjencive -->
    <div class="card">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
            <h5 class="mb-0"><i class="bi bi-building me-2"></i>Lista e agjencive</h5>
            <span class="text-muted small"><?= number_format($total) ?> rezultat(e)</span>
        </div>
        <div class="card-body">
            <div class="table-responsive mini-table">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:80px">ID</th>
                            <th>Emri i agjencisë</th>
                            <th>NIPT</th>
                            <th>Telefon</th>
                            <th>Adresë</th>
                            <th>Regjistruar</th>
                            <th class="text-end">Veprime</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($agencies): ?>
                        <?php foreach ($agencies as $a): ?>
                            <tr>
                                <td class="text-muted">#<?= (int)$a['agency_id'] ?></td>
                                <td><?= htmlspecialchars($a['company_name'] ?: '—') ?></td>
                                <td><span class="badge text-bg-success"><?= htmlspecialchars($a['nip_t']) ?></span></td>
                                <td><?= htmlspecialchars($a['phone'] ?: '—') ?></td>
                                <td>
                                    <div class="truncate-2" title="<?= htmlspecialchars($a['address'] ?: '') ?>">
                                        <?= htmlspecialchars($a['address'] ?: '—') ?>
                                    </div>
                                </td>
                                <td class="text-muted"><?= htmlspecialchars($a['created_at']) ?></td>
                                <td class="text-end">
                                    <!-- Edit -->
                                    <button class="btn btn-sm btn-outline-primary me-1"
                                            data-bs-toggle="modal" data-bs-target="#editAgencyModal"
                                            data-agency-id="<?= (int)$a['agency_id'] ?>"
                                            data-company-name="<?= htmlspecialchars($a['company_name'] ?? '', ENT_QUOTES) ?>"
                                            data-nip-t="<?= htmlspecialchars($a['nip_t'] ?? '', ENT_QUOTES) ?>"
                                            data-phone="<?= htmlspecialchars($a['phone'] ?? '', ENT_QUOTES) ?>"
                                            data-address="<?= htmlspecialchars($a['address'] ?? '', ENT_QUOTES) ?>">
                                        <i class="bi bi-pencil-square me-1"></i>Modifiko
                                    </button>
                                    <!-- Reset Password -->
                                    <button class="btn btn-sm btn-outline-secondary me-1"
                                            data-bs-toggle="modal" data-bs-target="#resetPassModal"
                                            data-user-id="<?= (int)$a['user_id'] ?>"
                                            data-agency-name="<?= htmlspecialchars($a['company_name'] ?: 'Agjenci') ?>">
                                        <i class="bi bi-key me-1"></i>Reset
                                    </button>
                                    <!-- Delete -->
                                    <form class="d-inline" method="post" onsubmit="return confirm('Fshini këtë agjenci? Veprimi do të fshijë llogarinë dhe të dhënat e saj.');">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
                                        <input type="hidden" name="action" value="delete_agency">
                                        <input type="hidden" name="agency_id" value="<?= (int)$a['agency_id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash me-1"></i>Fshi
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center text-muted">Nuk u gjet asnjë agjenci.</td></tr>
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
                    $base = 'agencies.php?'.http_build_query(array_filter(['q' => $q !== '' ? $q : null]));
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

<!-- Modal: Shto Agjenci -->
<div class="modal fade" id="addAgencyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="action" value="create_agency">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-building-add me-1"></i> Shto agjenci</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Emri i agjencisë</label>
                <input type="text" name="company_name" class="form-control" required placeholder="p.sh. QTA Partner Sh.p.k.">
            </div>
            <div class="col-md-6">
                <label class="form-label">NIPT</label>
                <input type="text" name="nip_t" class="form-control" required placeholder="p.sh. L42202012A">
                <div class="form-text">Duhet të jetë unik.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Fjalëkalimi</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Përsërit fjalëkalimin</label>
                <input type="password" name="password2" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Telefon</label>
                <input type="text" name="phone" class="form-control" placeholder="+355 67 000 0000">
            </div>
            <div class="col-12">
                <label class="form-label">Adresë</label>
                <textarea name="address" class="form-control" rows="3" placeholder="Rr. ... , Qyteti"></textarea>
            </div>
        </div>
        <div class="form-text mt-2">
            Krijon rreshta në <code>users</code>, <code>credentials</code> dhe <code>agencies</code>.
            Agjencitë hyjnë me <strong>NIPT + fjalëkalim</strong>.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit">Shto agjenci</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Modifiko Agjenci -->
<div class="modal fade" id="editAgencyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="action" value="update_agency">
      <input type="hidden" name="agency_id" id="edit_agency_id">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-1"></i> Modifiko agjencinë</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Emri i agjencisë</label>
                <input type="text" name="company_name" id="edit_company_name" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">NIPT</label>
                <input type="text" name="nip_t" id="edit_nip_t" class="form-control" required>
                <div class="form-text">Duhet të jetë unik.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Telefon</label>
                <input type="text" name="phone" id="edit_phone" class="form-control">
            </div>
            <div class="col-12">
                <label class="form-label">Adresë</label>
                <textarea name="address" id="edit_address" class="form-control" rows="3"></textarea>
            </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit">Ruaj ndryshimet</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Reset Password (Agjenci) -->
<div class="modal fade" id="resetPassModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="reset_user_id">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-key me-1"></i> Ndrysho fjalëkalimin (Agjenci)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
            <label class="form-label">Agjencia</label>
            <input type="text" id="reset_agency_name" class="form-control" disabled>
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
// Populate Edit Agency modal
const editModal = document.getElementById('editAgencyModal');
editModal?.addEventListener('show.bs.modal', (event) => {
    const btn = event.relatedTarget;
    document.getElementById('edit_agency_id').value   = btn.getAttribute('data-agency-id');
    document.getElementById('edit_company_name').value= btn.getAttribute('data-company-name') || '';
    document.getElementById('edit_nip_t').value       = btn.getAttribute('data-nip-t') || '';
    document.getElementById('edit_phone').value       = btn.getAttribute('data-phone') || '';
    document.getElementById('edit_address').value     = btn.getAttribute('data-address') || '';
});

// Populate Reset Password modal
const resetModal = document.getElementById('resetPassModal');
resetModal?.addEventListener('show.bs.modal', (event) => {
    const btn = event.relatedTarget;
    document.getElementById('reset_user_id').value    = btn.getAttribute('data-user-id');
    document.getElementById('reset_agency_name').value= btn.getAttribute('data-agency-name') || 'Agjenci';
});
</script>
</body>
</html>
