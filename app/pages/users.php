<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* -------------------------------------------------
   Toggle: Edit Mode (ruhet në session)
-------------------------------------------------- */
if (isset($_GET['edit'])) {
    $_SESSION['users_edit_mode'] = filter_var($_GET['edit'], FILTER_VALIDATE_BOOLEAN);
    // redirect pa param 'edit' (ruaj pjesën tjetër të query-it)
    $qs = $_GET; unset($qs['edit']);
    $redir = 'users.php' . ($qs ? ('?' . http_build_query($qs)) : '');
    header('Location: ' . $redir);
    exit;
}
$EDIT_MODE = !empty($_SESSION['users_edit_mode']);

/* ------------------------------
   Guard: vetëm admin i loguar
------------------------------- */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }

$userStmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, r.name AS role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id = :uid
    LIMIT 1
");
$userStmt->execute([':uid' => $_SESSION['user_id']]);
$currentUser = $userStmt->fetch();

if (!$currentUser || strtolower((string)($currentUser['role_name'] ?? '')) !== 'administrator') {
    header('Location: selectProfile.php'); exit;
}

/* ------------------------------
   Helpers
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
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$CSRF = $_SESSION['csrf_token'];

/* ------------------------------
   Merr rolet & gjej adminRoleId
------------------------------- */
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$adminRoleId = null;
foreach ($roles as $r) if (strtolower($r['name']) === 'administrator') { $adminRoleId = (int)$r['id']; break; }
if ($adminRoleId === null) { exit('Konfigurim i mangët: roli "administrator" mungon në tabelën roles.'); }

/* ------------------------------
   Veprime POST (create/reset/delete)
   → Lejohen vetëm kur Edit Mode është ON
------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$EDIT_MODE) {
        flash('err','Aktivizo <strong>Mënyrën e redaktimit</strong> për të bërë ndryshime.');
        header('Location: users.php'); exit;
    }

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

            // Vetëm për administratorë
            $roleQ = $pdo->prepare("SELECT role_id FROM users WHERE id = :uid");
            $roleQ->execute([':uid' => $uid]);
            $targetRole = $roleQ->fetchColumn();
            if (!$targetRole || (int)$targetRole !== $adminRoleId) {
                throw new RuntimeException('Veprimi lejohet vetëm për llogari administrator.');
            }

            $hash = password_hash($pass1, PASSWORD_BCRYPT);
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
            if ($uid === (int)$currentUser['id']) throw new RuntimeException('Nuk mund të fshini llogarinë tuaj gjatë seancës.');

            // Vetëm nëse target-i është administrator
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

    header('Location: users.php'); exit;
}

/* ------------------------------
   Filtrim (vetëm admin) + paginim
------------------------------- */
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where   = ["r.id = :adminRole"];
$params  = [':adminRole' => $adminRoleId];

if ($q !== '') {
    $where[] = "(u.full_name LIKE :kw1 OR u.email LIKE :kw2)";
    $kw = '%'.$q.'%';
    $params[':kw1'] = $kw;
    $params[':kw2'] = $kw;
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


$pageTitle = 'Administratorët – QTA Admin';
$bodyClass = $EDIT_MODE ? '' : 'editing-off';
require __DIR__ . '/../shared/app_head.php';
?>

<?php $NAV_ACTIVE = 'users_admins'; require __DIR__ . '/inc/navbar.php'; ?>

<main class="app-main">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
        <div class="title-block-main">
          <div class="title-block-eyebrow">Aksesi</div>
          <h1>Menaxhimi i administratorëve</h1>
        </div>
        <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>

        <!-- Page toolbar: Edit Mode toggle -->
        <div class="d-flex align-items-center">
            <?php
                $qs = $_GET;
                $qs['edit'] = $EDIT_MODE ? '0' : '1';
                $toggleUrl = 'users.php' . ($qs ? ('?' . http_build_query($qs)) : '');
            ?>
</div>
    </div>

    <?php if (!$EDIT_MODE): ?>
        <div class="alert alert-secondary py-2">
            <i class="bi bi-info-circle me-1"></i>
            Aktivizo <strong>Mënyrën e redaktimit</strong> për të ndryshuar qelizat, për të shtuar administratorë, ose për të fshirë/resetuar fjalëkalime.
        </div>
    <?php endif; ?>

    <!-- Kërkim -->
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
                    <button class="btn btn-soft-secondary btn-pill me-1" type="button" onclick="window.location='users.php'">
                        <i class="bi bi-x-circle me-1"></i>Pastro
                    </button>
                    <button class="btn btn-primary btn-pill" type="submit">
                        <i class="bi bi-funnel me-1"></i>Apliko
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela (me inline-edit për Emri & Email) -->
    <div class="card">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
            <h5 class="mb-0"><i class="bi bi-people me-2"></i>Lista e administratorëve</h5>
            <span class="text-muted small"><?= number_format($total) ?> rezultat(e)</span>
        </div>
        <div class="card-body">
            <div class="table-responsive mini-table">
                <table class="table align-middle mb-0" data-sortable>
                    <thead class="table-light">
                        <tr>
                            <th style="width:80px" data-sort="text">ID</th>
                            <th data-sort="text">Emri</th>
                            <th data-sort="text">Email</th>
                            <th data-sort="text">Roli</th>
                            <th data-sort="text">Regjistruar</th>
                            <th class="text-end" data-sort="none">Veprime</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($users): ?>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td class="text-muted">#<?= (int)$u['id'] ?></td>

                                <!-- full_name (inline) -->
                                <td class="cell" data-id="<?= (int)$u['id'] ?>" data-field="full_name">
                                    <span class="editable"
                                          contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"
                                          tabindex="<?= $EDIT_MODE ? 0 : -1 ?>"><?= htmlspecialchars($u['full_name'] ?: '—') ?></span>
                                </td>

                                <!-- email (inline) -->
                                <td class="cell nowrap" data-id="<?= (int)$u['id'] ?>" data-field="email">
                                    <span class="editable"
                                          contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"
                                          tabindex="<?= $EDIT_MODE ? 0 : -1 ?>"><?= htmlspecialchars($u['email'] ?: '—') ?></span>
                                </td>

                                <td><span class="badge rounded-pill text-bg-danger">Administrator</span></td>
                                <td class="text-muted"><?= htmlspecialchars($u['created_at']) ?></td>
                                <td class="text-end">
                                    <!-- Reset Password -->
                                    <button class="btn btn-sm btn-outline-secondary me-1"
                                            data-bs-toggle="modal" data-bs-target="#resetPassModal"
                                            data-user-id="<?= (int)$u['id'] ?>"
                                            data-user-name="<?= htmlspecialchars($u['full_name'] ?: ($u['email'] ?? 'Administrator'), ENT_QUOTES) ?>"
                                            <?= $EDIT_MODE ? '' : 'disabled' ?>
                                            title="<?= $EDIT_MODE ? 'Ndrysho fjalëkalimin' : 'Aktivizo Edit Mode për të resetuar' ?>">
                                        <i class="bi bi-key me-1"></i>Reset
                                    </button>
                                    <!-- Delete -->
                                    <form class="d-inline" method="post" action="users.php" onsubmit="return <?= $EDIT_MODE ? 'confirm(\'Fshini këtë administrator?\')' : 'false' ?>;">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" <?= $EDIT_MODE ? '' : 'disabled' ?>
                                                title="<?= $EDIT_MODE ? 'Fshi këtë administrator' : 'Aktivizo Edit Mode për të fshirë' ?>">
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
                    $base = 'users.php?'.http_build_query(array_filter(['q' => $q !== '' ? $q : null, 'edit' => $EDIT_MODE ? '1' : '0']));
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

<!-- FAB: Shto administrator (poshtë djathtas) -->
<?php if ($EDIT_MODE): ?>
<button class="btn btn-primary btn-fab" type="button"
        data-bs-toggle="modal" data-bs-target="#addAdminModal"
        aria-label="Shto administrator" title="Shto administrator">
  <i class="bi bi-plus-lg"></i>
</button>
<?php else: ?>
<button class="btn btn-soft-secondary btn-fab" type="button" disabled
        title="Aktivizo Edit Mode për të shtuar administrator">
  <i class="bi bi-plus-lg"></i>
</button>
<?php endif; ?>

<!-- Toasts -->
<div id="toastZone" class="toast-container position-fixed start-0 bottom-0 p-3" style="z-index:1080;"></div>

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
            <input type="text" name="full_name" class="form-control" <?= $EDIT_MODE ? 'required' : 'disabled' ?> placeholder="P.sh. Arben Hoxha">
        </div>
        <div class="mb-3">
            <label class="form-label">Email (unik)</label>
            <input type="email" name="email" class="form-control" <?= $EDIT_MODE ? 'required' : 'disabled' ?> placeholder="admin@qta.al">
        </div>
        <div class="row g-2">
            <div class="col-12 col-md-6">
                <label class="form-label">Fjalëkalimi</label>
                <input type="password" name="password" class="form-control" <?= $EDIT_MODE ? 'required' : 'disabled' ?>>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label">Përsërit fjalëkalimin</label>
                <input type="password" name="password2" class="form-control" <?= $EDIT_MODE ? 'required' : 'disabled' ?>>
            </div>
        </div>
        <div class="form-text mt-2">
            Krijon rreshta në <code>users</code>, <code>credentials</code> dhe <code>admins</code>.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary btn-pill" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>>Ruaj</button>
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
                <input type="password" name="new_password" class="form-control" <?= $EDIT_MODE ? 'required' : 'disabled' ?>>
            </div>
            <div class="col-md-6">
                <label class="form-label">Përsërit fjalëkalimin</label>
                <input type="password" name="new_password2" class="form-control" <?= $EDIT_MODE ? 'required' : 'disabled' ?>>
            </div>
        </div>
        <div class="form-text">Fjalëkalimi ruhet i hash-uar me <code>PASSWORD_BCRYPT</code>.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary btn-pill" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>>Ruaj</button>
      </div>
    </form>
  </div>
</div>

<!-- JS -->
<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const ENDPOINT = 'user_inline.php';
const EDIT_ENABLED = <?= $EDIT_MODE ? 'true' : 'false' ?>;

/* Toast helper */
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

/* Për kompatibilitet me kodin ekzistues */
function showMsg(type, text){ notify(type, text); }
function notifySuccess(text, opts){ notify('success', text, opts); }
function notifyError(text, opts){ notify('danger', text, opts); }

function cleanText(s) {
  const v = (s || '').replace(/\s+/g,' ').trim();
  return (v === '—' ? '' : v);
}

async function saveInline(userId, field, value, cell, displayEl) {
  try {
    cell.classList.add('cell-saving');
    const res = await fetch(ENDPOINT, {
      method: 'POST',
      headers: {'Content-Type':'application/json', 'Accept':'application/json'},
      body: JSON.stringify({ csrf: CSRF, user_id: userId, field, value })
    });
    const json = await res.json();
    cell.classList.remove('cell-saving');
    if (!json.ok) throw new Error(json.error || 'Gabim i panjohur.');
    if (displayEl) { displayEl.textContent = json.display ?? (value || '—'); }
    cell.classList.add('cell-ok'); setTimeout(()=>cell.classList.remove('cell-ok'), 800);
    notify('success','U ruajt me sukses.');
  } catch (e) {
    console.error(e);
    cell.classList.remove('cell-saving'); cell.classList.add('cell-err');
    setTimeout(()=>cell.classList.remove('cell-err'), 1200);
    notify('danger', e.message || 'Ndodhi një gabim.');
  }
}

/* Inline-edit: vetëm kur Edit Mode është ON */
if (EDIT_ENABLED) {
  document.querySelectorAll('td.cell .editable[contenteditable="true"]').forEach(el => {
    let oldVal = el.textContent;
    el.addEventListener('focus', () => { oldVal = el.textContent; });
    el.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); el.blur(); }});
    el.addEventListener('blur', () => {
      const cell = el.closest('td.cell');
      const field = cell.dataset.field;
      const uid = parseInt(cell.dataset.id, 10);
      const newVal = cleanText(el.textContent);
      if (newVal === cleanText(oldVal)) return;

      // Validime front
      if (field === 'email') {
        if (newVal === '') { notify('warning','Email-i është i detyrueshëm.'); el.textContent = oldVal; return; }
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!re.test(newVal)) { notify('warning','Email i pavlefshëm.'); el.textContent = oldVal; return; }
      }
      if (field === 'full_name' && newVal === '') {
        notify('warning','Emri nuk mund të jetë bosh.'); el.textContent = oldVal; return;
      }

      saveInline(uid, field, newVal, cell, el);
    });
  });
}

/* Reset Password modal fill */
const resetModal = document.getElementById('resetPassModal');
resetModal?.addEventListener('show.bs.modal', event => {
    const btn = event.relatedTarget;
    if (!btn || btn.hasAttribute('disabled')) { event.preventDefault(); return; }
    document.getElementById('reset_user_id').value = btn.getAttribute('data-user-id');
    document.getElementById('reset_user_name').value = btn.getAttribute('data-user-name');
});

/* Flash -> Toast sapo ngarkohet faqja */
<?php if ($m = flash('ok')): ?>
document.addEventListener('DOMContentLoaded',()=>notify('success', <?= json_encode($m) ?>));
<?php endif; ?>
<?php if ($m = flash('err')): ?>
document.addEventListener('DOMContentLoaded',()=>notify('danger', <?= json_encode($m) ?>));
<?php endif; ?>
</script>
</body>
</html>
