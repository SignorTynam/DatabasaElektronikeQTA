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
   Veprime POST: vetëm create (update/reset/delete bëhen inline via AJAX)
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

            // 1) users (email = NULL)
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
    // Placeholderë unikë për të shmangur HY093
    $where[] = "(
        a.company_name LIKE :kw1 OR
        a.nip_t        LIKE :kw2 OR
        a.phone        LIKE :kw3 OR
        a.address      LIKE :kw4
    )";
    $kw = '%'.$q.'%';
    $params[':kw1'] = $kw;
    $params[':kw2'] = $kw;
    $params[':kw3'] = $kw;
    $params[':kw4'] = $kw;
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
        body { background:#f5f7fb; padding-top:72px; } /* hapësirë për navbar fixed-top */
        .navbar-brand img { height:28px; }
        .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
        .mini-table thead { background:#f1f5f9; }
        .form-control::placeholder { color:#9ca3af; }
        .pagination .page-link { border-radius:.5rem; }
        .truncate-2 { display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        @media (max-width: 575.98px) { .navbar-text { display:none; } }

        /* Inline-edit styles */
        .editable {
          display:inline-block; min-width:90px; padding:.35rem .5rem;
          border-radius:.5rem; transition:box-shadow .2s, background-color .2s;
        }
        .editable:hover { background:#f8fafc; box-shadow:inset 0 0 0 1px #e5e7eb; }
        .editable:focus { outline:0; background:#eef2ff; box-shadow:inset 0 0 0 2px #4f46e5; }
        .cell-saving { position:relative; }
        .cell-saving::after {
          content:''; position:absolute; right:.25rem; top:50%; width:.55rem; height:.55rem;
          border:.15rem solid rgba(0,0,0,.2); border-top-color:rgba(0,0,0,.55); border-radius:50%;
          animation:spin .6s linear infinite; transform:translateY(-50%);
        }
        @keyframes spin { to { transform:translateY(-50%) rotate(360deg); } }
        .cell-ok { animation: flashOk 1.2s ease; }
        @keyframes flashOk { 0%{background:#ecfdf5;} 100%{background:transparent;} }
        .cell-err { animation: flashErr 1.2s ease; }
        @keyframes flashErr { 0%{background:#fef2f2;} 100%{background:transparent;} }
        .nowrap { white-space:nowrap; }
    </style>
</head>
<body>

<!-- NAVBAR (pa sidebar, me dropdown Përdorues) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center" href="dashboard_admin.php">
            <img src="image/logoPNG2.png" class="me-2" alt="QTA"> QTA – Paneli i Administratorit
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNav">
            <span class="navbar-toggler-icon"></span>
        </button>

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
                    <a class="nav-link dropdown-toggle active" href="#" id="usersDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-people me-1"></i>Përdorues
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="usersDropdown">
                        <li><a class="dropdown-item" href="users.php"><i class="bi bi-shield-lock me-2"></i>Administratorët</a></li>
                        <li><a class="dropdown-item active" href="agencies.php"><i class="bi bi-building me-2"></i>Agjencitë</a></li>
                        <li><a class="dropdown-item" href="students.php"><i class="bi bi-mortarboard me-2"></i>Studentët</a></li>
                    </ul>
                </li>

                <li class="nav-item">
                    <a class="nav-link" aria-current="page" href="courses.php">
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

    <!-- Tabela e agjencive (inline-edit) -->
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
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($agencies): ?>
                        <?php foreach ($agencies as $a): $aid=(int)$a['agency_id']; ?>
                            <tr>
                                <td class="text-muted">#<?= $aid ?></td>

                                <td class="cell" data-id="<?= $aid ?>" data-field="company_name">
                                    <span class="editable" contenteditable="true"><?= htmlspecialchars($a['company_name'] ?: '—') ?></span>
                                </td>

                                <td class="cell" data-id="<?= $aid ?>" data-field="nip_t">
                                    <span class="editable" contenteditable="true"><?= htmlspecialchars($a['nip_t']) ?></span>
                                </td>

                                <td class="cell nowrap" data-id="<?= $aid ?>" data-field="phone">
                                    <span class="editable" contenteditable="true"><?= htmlspecialchars($a['phone'] ?: '—') ?></span>
                                </td>

                                <td class="cell" data-id="<?= $aid ?>" data-field="address" title="Kliko për të modifikuar">
                                    <span class="editable" contenteditable="true"><?= htmlspecialchars($a['address'] ?: '—') ?></span>
                                </td>

                                <td class="text-muted"><?= htmlspecialchars($a['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center text-muted">Nuk u gjet asnjë agjenci.</td></tr>
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
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit">Shto agjenci</button>
      </div>
    </form>
  </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const ENDPOINT = 'agencies_inline_update.php';

function cleanText(s) {
  const v = (s || '').replace(/\s+/g,' ').trim();
  return (v === '—' ? '' : v);
}

async function saveInline(agencyId, field, value, cell, displayEl) {
  try {
    cell.classList.add('cell-saving');
    const res = await fetch(ENDPOINT, {
      method: 'POST',
      headers: {'Content-Type':'application/json', 'Accept':'application/json'},
      body: JSON.stringify({ csrf: CSRF, agency_id: agencyId, field, value })
    });
    const json = await res.json();
    cell.classList.remove('cell-saving');
    if (!json.ok) throw new Error(json.error || 'Gabim i panjohur.');

    if (displayEl) {
      displayEl.textContent = json.display ?? (value || '—');
    }
    cell.classList.add('cell-ok');
    setTimeout(()=>cell.classList.remove('cell-ok'), 800);
  } catch (e) {
    console.error(e);
    cell.classList.remove('cell-saving');
    cell.classList.add('cell-err');
    setTimeout(()=>cell.classList.remove('cell-err'), 1200);
  }
}

/* Event për contenteditable (blur & Enter) */
document.querySelectorAll('td.cell .editable').forEach(el => {
  let oldVal = el.textContent;
  el.addEventListener('focus', () => { oldVal = el.textContent; });
  el.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter') {
      ev.preventDefault();
      el.blur(); // trigger blur -> save
    }
  });
  el.addEventListener('blur', () => {
    const cell = el.closest('td.cell');
    const field = cell.dataset.field;
    const aid = parseInt(cell.dataset.id, 10);
    const newVal = cleanText(el.textContent);
    if (newVal === cleanText(oldVal)) return; // asgjë s'ka ndryshuar
    saveInline(aid, field, newVal, cell, el);
  });
});
</script>
</body>
</html>
