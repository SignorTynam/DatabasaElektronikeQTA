<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';
require __DIR__ . '/inc/navbar.php';

$pdo = getPDO();

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
   POST: Shto / Fshi kurs
------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_course') {
        try {
            $code  = trim($_POST['code'] ?? '');
            $name  = trim($_POST['name'] ?? '');
            $hours = trim($_POST['hours'] ?? '');

            if ($code === '' || $name === '' || $hours === '') {
                throw new RuntimeException('Plotësoni fushat: Kod, Emër, Orë.');
            }
            if (!ctype_digit($hours) || (int)$hours < 1 || (int)$hours > 65535) {
                throw new RuntimeException('“Orë” duhet të jetë numër i plotë ≥ 1.');
            }

            // Unik: code
            $q = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE code = :c");
            $q->execute([':c'=>$code]);
            if ((int)$q->fetchColumn() > 0) {
                throw new RuntimeException('Ky kod kursi ekziston tashmë.');
            }

            $st = $pdo->prepare("INSERT INTO courses (code, name, hours) VALUES (:c, :n, :h)");
            $st->execute([':c'=>$code, ':n'=>$name, ':h'=>(int)$hours]);

            flash('ok', 'Moduli u shtua me sukses.');
        } catch (Throwable $e) {
            flash('err', $e->getMessage());
        }
        header('Location: courses.php'); exit;
    }

    if ($action === 'delete_course') {
        try {
            $course_id = (int)($_POST['course_id'] ?? 0);
            if ($course_id <= 0) throw new RuntimeException('Kurs i pavlefshëm.');

            // Opsionale: lexo kodin për mesazh
            $cinfo = $pdo->prepare("SELECT code, name FROM courses WHERE id=:id LIMIT 1");
            $cinfo->execute([':id'=>$course_id]);
            $ci = $cinfo->fetch(PDO::FETCH_ASSOC);
            if (!$ci) throw new RuntimeException('Moduli nuk u gjet.');

            // Fshi (ON DELETE CASCADE do të fshijë groupet e lidhura dhe anëtarësimet)
            $del = $pdo->prepare("DELETE FROM courses WHERE id=:id");
            $del->execute([':id'=>$course_id]);

            flash('ok', 'Moduli "'.$ci['code'].' — '.$ci['name'].'" u fshi.');
        } catch (Throwable $e) {
            flash('err', $e->getMessage());
        }
        header('Location: courses.php'); exit;
    }
}

/* ------------------------------
   Kërkim + Paginim
------------------------------- */
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where  = ["1=1"];
$params = [];

if ($q !== '') {
    $where[] = "(c.code LIKE :kw OR c.name LIKE :kw2)";
    $params[':kw']  = '%'.$q.'%';
    $params[':kw2'] = '%'.$q.'%';
}
$whereSql = 'WHERE '.implode(' AND ', $where);

/* Numri total */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM courses c $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));

/* Lista – rendit sipas code (leksikografik) pastaj id */
$listStmt = $pdo->prepare("
    SELECT c.id AS course_id, c.code, c.name, c.hours, c.created_at
    FROM courses c
    $whereSql
    ORDER BY c.code ASC, c.id ASC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k=>$v) { $listStmt->bindValue($k, $v, PDO::PARAM_STR); }
$listStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$courses = $listStmt->fetchAll(PDO::FETCH_ASSOC);

/* Flash mesazhe */
$ok  = flash('ok');
$err = flash('err');
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
        .truncate-2 { display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .nowrap { white-space:nowrap; }
        @media (max-width: 575.98px) { .navbar-text { display:none; } }

        .editable {
          display:inline-block; min-width:72px; padding:.35rem .5rem;
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
    </style>
</head>
<body>

<main class="container-fluid px-3 px-md-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
        <h2 class="mb-0">Modulet</h2>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCourseModal">
                <i class="bi bi-bookmark-plus me-1"></i> Shto Modul
            </button>
        </div>
    </div>

    <?php if ($ok): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($ok) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($err) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Kërkim -->
    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get" action="courses.php">
                <div class="col-md-9">
                    <div class="d-flex align-items-center">
                        <label class="form-label mb-0 me-2" style="min-width:70px;">Kërko</label>
                        <div class="input-group flex-grow-1">
                            <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                            <input type="text" name="q" class="form-control border-0"
                                   placeholder="Kërko sipas Kodit ose Emrit..."
                                   value="<?= htmlspecialchars($q) ?>">
                        </div>
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

    <!-- Tabela -->
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
                            <th>Kod</th>
                            <th>Emër</th>
                            <th class="nowrap">Orë</th>
                            <th class="nowrap">Krijuar më</th>
                            <th class="nowrap text-end">Veprime</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($courses): ?>
                        <?php foreach ($courses as $c): $cid=(int)$c['course_id']; ?>
                            <tr>
                                <!-- code -->
                                <td class="cell" data-id="<?= $cid ?>" data-field="code">
                                    <span class="editable" contenteditable="true"><?= htmlspecialchars($c['code']) ?></span>
                                </td>
                                <!-- name -->
                                <td class="cell" data-id="<?= $cid ?>" data-field="name">
                                    <span class="editable" contenteditable="true"><?= htmlspecialchars($c['name']) ?></span>
                                </td>
                                <!-- hours -->
                                <td class="cell nowrap" data-id="<?= $cid ?>" data-field="hours" title="Numër i plotë ≥ 1">
                                    <span class="editable" contenteditable="true"><?= (int)$c['hours'] ?></span>
                                </td>
                                <!-- created_at (read-only) -->
                                <td class="text-muted small nowrap">
                                    <?= htmlspecialchars($c['created_at']) ?>
                                </td>
                                <!-- actions -->
                                <td class="text-end">
                                    <button class="btn btn-outline-danger btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#deleteCourseModal_<?= $cid ?>">
                                        <i class="bi bi-trash me-1"></i> Fshi
                                    </button>
                                </td>
                            </tr>

                            <!-- MODAL: Fshi modul -->
                            <div class="modal fade" id="deleteCourseModal_<?= $cid ?>" tabindex="-1" aria-hidden="true">
                              <div class="modal-dialog">
                                <form class="modal-content" method="post" action="courses.php">
                                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
                                  <input type="hidden" name="action" value="delete_course">
                                  <input type="hidden" name="course_id" value="<?= $cid ?>">
                                  <div class="modal-header">
                                    <h5 class="modal-title"><i class="bi bi-trash me-1"></i> Fshi modul</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                  </div>
                                  <div class="modal-body">
                                    <p>Jeni i sigurt që dëshironi të fshini modulin:</p>
                                    <ul class="mb-2">
                                      <li><strong>Kod:</strong> <?= htmlspecialchars($c['code']) ?></li>
                                      <li><strong>Emër:</strong> <?= htmlspecialchars($c['name']) ?></li>
                                    </ul>
                                    <div class="alert alert-warning small mb-0">
                                      <i class="bi bi-exclamation-triangle me-1"></i>
                                      <strong>Kujdes:</strong> Fshirja do të <u>shkaktojë fshirje kaskadë</u> të grupeve dhe pjesëmarrjeve të lidhura me këtë modul.
                                    </div>
                                  </div>
                                  <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
                                    <button class="btn btn-danger" type="submit">Po, fshije</button>
                                  </div>
                                </form>
                              </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted">Nuk u gjet asnjë modul.</td></tr>
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
                    $base = 'courses.php?'.http_build_query(array_filter(['q' => $q !== '' ? $q : null]));
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

<!-- MODAL: Shto Modul -->
<div class="modal fade" id="addCourseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="action" value="create_course">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-bookmark-plus me-1"></i> Shto modul</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Kod *</label>
                <input type="text" name="code" class="form-control" placeholder="p.sh. QTA-ALGO" required>
            </div>
            <div class="col-md-5">
                <label class="form-label">Emër *</label>
                <input type="text" name="name" class="form-control" placeholder="p.sh. Algoritme" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Orë *</label>
                <input type="number" name="hours" class="form-control" min="1" step="1" placeholder="p.sh. 30" required>
            </div>
        </div>
        <div class="form-text mt-2">
            Krijon një rresht në <code>courses</code> (kolonat: <code>code, name, hours</code>).
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit">Shto modul</button>
      </div>
    </form>
  </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const ENDPOINT = 'courses_inline_update.php';

/* Helper: trim & normalizim */
function cleanText(s) { return (s || '').replace(/\s+/g,' ').trim(); }

/* Ruajtje AJAX për inline-edit (code/name/hours) */
async function saveInline(courseId, field, value, cell, displayEl) {
  try {
    cell.classList.add('cell-saving');
    const res = await fetch(ENDPOINT, {
      method: 'POST',
      headers: {'Content-Type':'application/json', 'Accept':'application/json'},
      body: JSON.stringify({ csrf: CSRF, course_id: courseId, field, value })
    });
    const json = await res.json();
    cell.classList.remove('cell-saving');
    if (!json.ok) throw new Error(json.error || 'Gabim i panjohur.');
    if (displayEl) { displayEl.textContent = json.display ?? (value || ''); }
    cell.classList.add('cell-ok'); setTimeout(()=>cell.classList.remove('cell-ok'), 800);
  } catch (e) {
    console.error(e);
    cell.classList.remove('cell-saving'); cell.classList.add('cell-err');
    setTimeout(()=>cell.classList.remove('cell-err'), 1200);
  }
}

/* Event për contenteditable (blur & Enter) */
document.querySelectorAll('td.cell .editable').forEach(el => {
  let oldVal = el.textContent;
  el.addEventListener('focus', () => { oldVal = el.textContent; });
  el.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); el.blur(); }});
  el.addEventListener('blur', () => {
    const cell = el.closest('td.cell');
    const field = cell.dataset.field;
    const cid = parseInt(cell.dataset.id, 10);
    const newVal = cleanText(el.textContent);
    if (newVal === cleanText(oldVal)) return;
    if (field === 'hours' && (newVal === '' || isNaN(newVal) || parseInt(newVal,10) < 1)) {
      el.textContent = oldVal; cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'), 1200);
      return;
    }
    saveInline(cid, field, newVal, cell, el);
  });
});
</script>
</body>
</html>
