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
   Role & lookup data
------------------------------- */
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$studentRoleId = null;
foreach ($roles as $r) if ($r['name'] === 'student') { $studentRoleId = (int)$r['id']; break; }
if ($studentRoleId === null) { exit('Konfigurim i mangët: roli "student" mungon në tabelën roles.'); }

/* education_levels për select */
$eduLevels = $pdo->query("SELECT id, code, label FROM education_levels ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

/* ------------------------------
   Veprime POST: vetëm create (inline update bëhet me AJAX te students_inline_update.php)
------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_student') {
            $first_name       = trim($_POST['first_name'] ?? '');
            $father_name      = trim($_POST['father_name'] ?? '');
            $last_name        = trim($_POST['last_name'] ?? '');
            $birth_date       = trim($_POST['birth_date'] ?? '');
            $birth_place      = trim($_POST['birth_place'] ?? '');
            $nr_amze          = trim($_POST['nr_amze'] ?? '');
            $personal_number  = trim($_POST['personal_number'] ?? '');
            $education_level_id = (int)($_POST['education_level_id'] ?? 0);
            $phone            = trim($_POST['phone'] ?? '');
            $pass1            = $_POST['password'] ?? '';
            $pass2            = $_POST['password2'] ?? '';

            if ($first_name==='' || $last_name==='' || $nr_amze==='' || $personal_number==='' || $pass1==='' || $pass2==='') {
                throw new RuntimeException('Plotësoni fushat e detyrueshme: Emër, Mbiemër, Nr. Amzës, Nr. Personal, Fjalëkalim.');
            }
            if ($pass1 !== $pass2) throw new RuntimeException('Fjalëkalimet nuk përputhen.');

            // Unike: nr_amze + personal_number
            $q1 = $pdo->prepare("SELECT COUNT(*) FROM students WHERE nr_amze = :x");
            $q1->execute([':x'=>$nr_amze]);
            if ((int)$q1->fetchColumn() > 0) throw new RuntimeException('Nr. i amzës ekziston tashmë.');

            $q2 = $pdo->prepare("SELECT COUNT(*) FROM students WHERE personal_number = :p");
            $q2->execute([':p'=>$personal_number]);
            if ((int)$q2->fetchColumn() > 0) throw new RuntimeException('Numri personal ekziston tashmë.');

            $pdo->beginTransaction();

            // 1) users
            $full = trim($first_name . ' ' . ($father_name ? $father_name.' ' : '') . $last_name);
            $insUser = $pdo->prepare("INSERT INTO users (role_id, full_name, email) VALUES (:rid, :fn, NULL)");
            $insUser->execute([':rid'=>$studentRoleId, ':fn'=>$full]);
            $newUserId = (int)$pdo->lastInsertId();

            // 2) credentials
            $hash = password_hash($pass1, PASSWORD_BCRYPT);
            $insCred = $pdo->prepare("
                INSERT INTO credentials (user_id, password_hash, last_password_change)
                VALUES (:uid, :ph, NOW())
            ");
            $insCred->execute([':uid'=>$newUserId, ':ph'=>$hash]);

            // 3) students
            $insStud = $pdo->prepare("
                INSERT INTO students
                (user_id, first_name, father_name, last_name, birth_date, birth_place, nr_amze, personal_number, education_level_id, phone)
                VALUES
                (:uid, :fn, :fat, :ln, :bd, :bp, :amz, :pn, :edu, :ph)
            ");
            $insStud->execute([
                ':uid'=>$newUserId, ':fn'=>$first_name, ':fat'=>$father_name, ':ln'=>$last_name,
                ':bd'=>($birth_date !== '' ? $birth_date : null),
                ':bp'=>($birth_place !== '' ? $birth_place : null),
                ':amz'=>$nr_amze, ':pn'=>$personal_number,
                ':edu'=>($education_level_id > 0 ? $education_level_id : null),
                ':ph'=>($phone !== '' ? $phone : null)
            ]);

            $pdo->commit();
            flash('ok', 'Studenti u shtua me sukses.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flash('err', $e->getMessage());
    }

    header('Location: students.php'); exit;
}

/* ------------------------------
   Kërkim + Paginim (vetëm studentë) – renditje numerike sipas nr_amze
------------------------------- */
$q      = trim($_GET['q'] ?? '');
$edu    = trim($_GET['edu'] ?? ''); // mund të dërgohet code ose id
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where  = ["u.role_id = :studentRole"];
$params = [':studentRole' => $studentRoleId];

if ($q !== '') {
    // Placeholderë unikë (kw1..kw7) – shmang HY093
    $where[] = "(
        s.first_name      LIKE :kw1 OR
        s.father_name     LIKE :kw2 OR
        s.last_name       LIKE :kw3 OR
        s.nr_amze         LIKE :kw4 OR
        s.personal_number LIKE :kw5 OR
        s.phone           LIKE :kw6 OR
        s.birth_place     LIKE :kw7
    )";
    $kw = '%'.$q.'%';
    $params[':kw1'] = $kw;
    $params[':kw2'] = $kw;
    $params[':kw3'] = $kw;
    $params[':kw4'] = $kw;
    $params[':kw5'] = $kw;
    $params[':kw6'] = $kw;
    $params[':kw7'] = $kw;
}
if ($edu !== '') {
    if (ctype_digit($edu)) {
        $where[] = "s.education_level_id = :eduid";
        $params[':eduid'] = (int)$edu;
    } else {
        $where[] = "el.code = :educode";
        $params[':educode'] = $edu;
    }
}
$whereSql = 'WHERE '.implode(' AND ', $where);

/* Numri total */
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM students s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN education_levels el ON el.id = s.education_level_id
    $whereSql
");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));

/* Lista – RENDITJE NUMERIKE sipas nr_amze */
$listStmt = $pdo->prepare("
    SELECT
        s.id            AS student_id,
        s.user_id,
        s.first_name,
        s.father_name,
        s.last_name,
        s.birth_date,
        s.birth_place,
        s.nr_amze,
        s.personal_number,
        s.phone,
        s.education_level_id AS edu_id,
        el.code         AS edu_code,
        el.label        AS edu_label,
        u.created_at
    FROM students s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN education_levels el ON el.id = s.education_level_id
    $whereSql
    ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$students = $listStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="UTF-8" />
    <title>Studentët – QTA Admin</title>
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
        .nowrap { white-space:nowrap; }
        @media (max-width: 575.98px) { .navbar-text { display:none; } }

        /* Inline-edit styles */
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
        .inline-select { min-width: 160px; }
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
                        <li><a class="dropdown-item" href="agencies.php"><i class="bi bi-building me-2"></i>Agjencitë</a></li>
                        <li><a class="dropdown-item active" href="students.php"><i class="bi bi-mortarboard me-2"></i>Studentët</a></li>
                    </ul>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="registerDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-file-earmark-text me-1"></i>Regjistri
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="registerDropdown">
                        <li><a class="dropdown-item" href="register.php"><i class="bi bi-file-earmark-text me-2"></i>Regjistri i plotë</a></li>
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
        <h2 class="mb-0">Studentët</h2>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                <i class="bi bi-person-plus me-1"></i> Shto Student
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

    <!-- Kërkim + filtër edukimi -->
    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get" action="students.php">
                <div class="col-md-5">
                    <div class="d-flex align-items-center">
                        <label class="form-label mb-0 me-2" style="min-width:70px;">Kërko</label>
                        <div class="input-group flex-grow-1">
                            <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                            <input type="text" name="q" class="form-control border-0"
                                   placeholder="Emër/Atësi/Mbiemër, nr. amzës, nr. personal, tel., vendlindje..."
                                   value="<?= htmlspecialchars($q) ?>">
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center">
                        <label class="form-label mb-0 me-2" style="min-width:70px;">Arsimi</label>
                        <select name="edu" class="form-select flex-grow-1">
                            <option value="">Të gjithë</option>
                            <?php foreach ($eduLevels as $el): ?>
                                <?php $val = (string)$el['id']; ?>
                                <option value="<?= htmlspecialchars($val) ?>" <?= $edu===$val?'selected':'' ?>>
                                    <?= htmlspecialchars($el['code'].' — '.$el['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3 text-end">
                    <button class="btn btn-outline-secondary me-1" type="button"
                            onclick="window.location='students.php'"><i class="bi bi-x-circle me-1"></i>Pastro</button>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apliko</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela: inline-edit, renditur sipas nr_amze (numeric) -->
    <div class="card">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
            <h5 class="mb-0"><i class="bi bi-mortarboard me-2"></i>Lista e studentëve</h5>
            <span class="text-muted small"><?= number_format($total) ?> rezultat(e)</span>
        </div>
        <div class="card-body">
            <div class="table-responsive mini-table">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="nowrap">Nr. Amzës</th>
                            <th>Emër</th>
                            <th>Atësi</th>
                            <th>Mbiemër</th>
                            <th class="nowrap">Nr. Personal</th>
                            <th class="nowrap">Datëlindja</th>
                            <th>Vendlindja</th>
                            <th>Arsimi</th>
                            <th>Tel.</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($students): ?>
                        <?php foreach ($students as $s): $sid=(int)$s['student_id']; ?>
                            <tr>
                                <!-- nr_amze -->
                                <td class="cell" data-id="<?= $sid ?>" data-field="nr_amze">
                                    <span class="editable" contenteditable="true"><?= htmlspecialchars($s['nr_amze']) ?></span>
                                </td>
                                <!-- first_name -->
                                <td class="cell" data-id="<?= $sid ?>" data-field="first_name">
                                    <span class="editable" contenteditable="true"><?= htmlspecialchars($s['first_name'] ?: '—') ?></span>
                                </td>
                                <!-- father_name -->
                                <td class="cell" data-id="<?= $sid ?>" data-field="father_name">
                                    <span class="editable" contenteditable="true"><?= htmlspecialchars($s['father_name'] ?: '—') ?></span>
                                </td>
                                <!-- last_name -->
                                <td class="cell" data-id="<?= $sid ?>" data-field="last_name">
                                    <span class="editable" contenteditable="true"><?= htmlspecialchars($s['last_name'] ?: '—') ?></span>
                                </td>
                                <!-- personal_number -->
                                <td class="cell nowrap" data-id="<?= $sid ?>" data-field="personal_number">
                                    <span class="editable" contenteditable="true"><?= htmlspecialchars($s['personal_number'] ?: '—') ?></span>
                                </td>
                                <!-- birth_date -->
                                <td class="cell nowrap" data-id="<?= $sid ?>" data-field="birth_date" title="YYYY-MM-DD">
                                    <span class="editable" contenteditable="true"><?= htmlspecialchars($s['birth_date'] ?: '—') ?></span>
                                </td>
                                <!-- birth_place -->
                                <td class="cell" data-id="<?= $sid ?>" data-field="birth_place">
                                    <span class="editable" contenteditable="true"><?= htmlspecialchars($s['birth_place'] ?: '—') ?></span>
                                </td>
                                <!-- education_level_id -->
                                <td class="cell" data-id="<?= $sid ?>" data-field="education_level_id">
                                    <select class="form-select form-select-sm inline-select">
                                        <option value="">— Zgjidh —</option>
                                        <?php foreach ($eduLevels as $el): ?>
                                            <option value="<?= (int)$el['id'] ?>" <?= ((int)$s['edu_id'] === (int)$el['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($el['code'].' — '.$el['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <!-- phone -->
                                <td class="cell nowrap" data-id="<?= $sid ?>" data-field="phone">
                                    <span class="editable" contenteditable="true"><?= htmlspecialchars($s['phone'] ?: '—') ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="9" class="text-center text-muted">Nuk u gjet asnjë student.</td></tr>
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
                    $base = 'students.php?'.http_build_query(array_filter([
                        'q' => $q !== '' ? $q : null,
                        'edu' => $edu !== '' ? $edu : null,
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

<!-- MODAL: Shto Student -->
<div class="modal fade" id="addStudentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="action" value="create_student">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-plus me-1"></i> Shto student</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Emri *</label>
                <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Atësia</label>
                <input type="text" name="father_name" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Mbiemri *</label>
                <input type="text" name="last_name" class="form-control" required>
            </div>

            <div class="col-md-4">
                <label class="form-label">Datëlindja</label>
                <input type="date" name="birth_date" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Vendlindja</label>
                <input type="text" name="birth_place" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Tel.</label>
                <input type="text" name="phone" class="form-control" placeholder="+355 ...">
            </div>

            <div class="col-md-4">
                <label class="form-label">Nr. Amzës *</label>
                <input type="text" name="nr_amze" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Numri Personal *</label>
                <input type="text" name="personal_number" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Arsimi</label>
                <select name="education_level_id" class="form-select">
                    <option value="">— Zgjidh —</option>
                    <?php foreach ($eduLevels as $el): ?>
                        <option value="<?= (int)$el['id'] ?>"><?= htmlspecialchars($el['code'].' — '.$el['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Fjalëkalimi *</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Përsërit fjalëkalimin *</label>
                <input type="password" name="password2" class="form-control" required>
            </div>
        </div>
        <div class="form-text mt-2">
            Krijon rreshta në <code>users</code>, <code>credentials</code> dhe <code>students</code>.
            Studentët hyjnë me <strong>Numrin Personal + fjalëkalim</strong>.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit">Shto student</button>
      </div>
    </form>
  </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const ENDPOINT = 'students_inline_update.php';

/* Helper: trim & normalizim “—” */
function cleanText(s) {
  const v = (s || '').replace(/\s+/g,' ').trim();
  return (v === '—' ? '' : v);
}

/* Ruajtje AJAX */
async function saveInline(studentId, field, value, cell, displayEl) {
  try {
    cell.classList.add('cell-saving');
    const res = await fetch(ENDPOINT, {
      method: 'POST',
      headers: {'Content-Type':'application/json', 'Accept':'application/json'},
      body: JSON.stringify({ csrf: CSRF, student_id: studentId, field, value })
    });
    const json = await res.json();
    cell.classList.remove('cell-saving');
    if (!json.ok) throw new Error(json.error || 'Gabim i panjohur.');

    // Përditëso display (nëse serveri kthen vlerë “display”)
    if (displayEl && field !== 'education_level_id') {
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
    const sid = parseInt(cell.dataset.id, 10);
    const newVal = cleanText(el.textContent);
    if (newVal === cleanText(oldVal)) return; // asgjë s'ka ndryshuar
    saveInline(sid, field, newVal, cell, el);
  });
});

/* Event për select (education_level_id) */
document.querySelectorAll('td.cell[data-field="education_level_id"] select.inline-select')
  .forEach(sel => {
    sel.addEventListener('change', () => {
      const cell = sel.closest('td.cell');
      const sid = parseInt(cell.dataset.id, 10);
      const field = 'education_level_id';
      const val = sel.value; // '' ose id
      saveInline(sid, field, val, cell, null);
    });
  });
</script>
</body>
</html>
