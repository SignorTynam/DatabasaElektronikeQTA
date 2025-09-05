<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* ------------------------------
   Guard: admin OSE editor i loguar
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
    // Heq parametër 'edit' nga URL duke bërë redirect në të njëjtën faqe pa të
    $qs = $_GET; unset($qs['edit']);
    $url = 'students.php' . (empty($qs) ? '' : ('?' . http_build_query($qs)));
    header("Location: $url"); exit;
}
$EDIT_MODE = (bool)($_SESSION['edit_mode'] ?? false);

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

$eduLevels = $pdo->query("SELECT id, code, label FROM education_levels ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$genders   = $pdo->query("SELECT id, code, label FROM genders ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$maleId = null; foreach ($genders as $g) { if ($g['code']==='M') { $maleId = (int)$g['id']; break; } }

/* ------------------------------
   AJAX: Autoplotësim sipas Numrit Personal (opsional)
------------------------------- */
if (($_SERVER['REQUEST_METHOD'] === 'GET') && isset($_GET['action']) && $_GET['action']==='lookup_person') {
    header('Content-Type: application/json; charset=UTF-8');
    $pn = trim((string)($_GET['personal_number'] ?? ''));
    if ($pn === '') { echo json_encode(['ok'=>false,'error'=>'Numri personal mungon.']); exit; }

    $p = $pdo->prepare("
        SELECT p.id, p.first_name, p.father_name, p.last_name, p.birth_date, p.birth_place, p.phone, p.gender_id
        FROM persons p
        WHERE p.personal_number = :pn
        LIMIT 1
    ");
    $p->execute([':pn'=>$pn]);
    $row = $p->fetch(PDO::FETCH_ASSOC);

    $edu_id = null;
    if ($row) {
        $q = $pdo->prepare("SELECT education_level_id FROM students WHERE person_id=:pid ORDER BY id DESC LIMIT 1");
        $q->execute([':pid'=>$row['id']]);
        $edu_id = $q->fetchColumn() ?: null;
    }
    echo json_encode(['ok'=>true,'person'=>$row,'education_level_id'=>$edu_id]);
    exit;
}

/* ------------------------------
   Helpers për fjalëkalimin dhe datën
------------------------------- */
function make_initial_password(string $first_name, ?string $birth_date): string {
    $fname = trim($first_name);
    $fname = preg_replace('/\s+/', '', $fname);
    $year  = '0000';
    if ($birth_date && preg_match('/^(\d{4})-/', $birth_date, $m)) { $year = $m[1]; }
    return ($fname === '' ? 'User' : $fname) . '.' . $year;
}
function fmt_dMY(?string $iso): string {
    if (!$iso) return '—';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return htmlspecialchars($iso, ENT_QUOTES, 'UTF-8');
    $ts = strtotime($iso);
    return $ts ? date('d-m-Y', $ts) : '—';
}

/* ------------------------------
   Veprime POST: create student
------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_student') {
            if (!$EDIT_MODE) { throw new RuntimeException('Edit Mode është OFF. Aktivizo për të kryer ndryshime.'); }

            $personal_number    = trim($_POST['personal_number'] ?? '');
            $nr_amze            = trim($_POST['nr_amze'] ?? '');
            $first_name         = trim($_POST['first_name'] ?? '');
            $father_name        = trim($_POST['father_name'] ?? '');
            $last_name          = trim($_POST['last_name'] ?? '');
            $birth_date         = trim($_POST['birth_date'] ?? '');
            $birth_place        = trim($_POST['birth_place'] ?? '');
            $phone              = trim($_POST['phone'] ?? '');
            $gender_id          = (int)($_POST['gender_id'] ?? 0);
            $education_level_id = (int)($_POST['education_level_id'] ?? 0);

            if ($nr_amze === '') {
                throw new RuntimeException('Nr. i amzës është i detyrueshëm.');
            }

            $q1 = $pdo->prepare("SELECT COUNT(*) FROM students WHERE nr_amze = :x");
            $q1->execute([':x'=>$nr_amze]);
            if ((int)$q1->fetchColumn() > 0) throw new RuntimeException('Nr. i amzës ekziston tashmë.');

            if ($gender_id <= 0 && $maleId) { $gender_id = $maleId; }

            if ($birth_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
                throw new RuntimeException('Datëlindja duhet në formatin YYYY-MM-DD.');
            }

            $pdo->beginTransaction();

            /* 1) PERSON */
            $personId = 0; $person = null;
            if ($personal_number !== '') {
                $pSel = $pdo->prepare("SELECT id, first_name, father_name, last_name, birth_date, birth_place, phone, gender_id FROM persons WHERE personal_number = :pn LIMIT 1");
                $pSel->execute([':pn'=>$personal_number]);
                $person = $pSel->fetch(PDO::FETCH_ASSOC);
                $personId = (int)($person['id'] ?? 0);
            }

            if ($personId === 0) {
                $pIns = $pdo->prepare("
                    INSERT INTO persons (personal_number, first_name, father_name, last_name, birth_date, birth_place, phone, gender_id)
                    VALUES (:pn, :fn, :fat, :ln, :bd, :bp, :ph, :gid)
                ");
                $pIns->execute([
                    ':pn'=>($personal_number !== '' ? $personal_number : null),
                    ':fn'=>($first_name !== '' ? $first_name : null),
                    ':fat'=>($father_name !== '' ? $father_name : null),
                    ':ln'=>($last_name !== '' ? $last_name : null),
                    ':bd'=>($birth_date !== '' ? $birth_date : null),
                    ':bp'=>($birth_place !== '' ? $birth_place : null),
                    ':ph'=>($phone !== '' ? $phone : null),
                    ':gid'=>($gender_id > 0 ? $gender_id : $maleId)
                ]);
                $personId = (int)$pdo->lastInsertId();
                $person   = [
                    'id'=>$personId, 'first_name'=>$first_name ?: '', 'father_name'=>$father_name ?: '', 'last_name'=>$last_name ?: '',
                    'birth_date'=>($birth_date !== '' ? $birth_date : null), 'birth_place'=>$birth_place ?: null,
                    'phone'=>$phone ?: null, 'gender_id'=>($gender_id > 0 ? $gender_id : $maleId),
                ];
            } else {
                $pUpd = $pdo->prepare("
                    UPDATE persons SET
                        first_name  = COALESCE(NULLIF(:fn,''), first_name),
                        father_name = COALESCE(NULLIF(:fat,''), father_name),
                        last_name   = COALESCE(NULLIF(:ln,''), last_name),
                        birth_date  = COALESCE(NULLIF(:bd,''), birth_date),
                        birth_place = COALESCE(NULLIF(:bp,''), birth_place),
                        phone       = COALESCE(NULLIF(:ph,''), phone),
                        gender_id   = COALESCE(:gid, gender_id)
                    WHERE id = :pid
                ");
                $pUpd->execute([
                    ':fn'=>$first_name, ':fat'=>$father_name, ':ln'=>$last_name,
                    ':bd'=>$birth_date, ':bp'=>$birth_place, ':ph'=>$phone,
                    ':gid'=>($gender_id > 0 ? $gender_id : null), ':pid'=>$personId
                ]);
                $pSel2 = $pdo->prepare("SELECT id, first_name, father_name, last_name, birth_date, birth_place, phone, gender_id FROM persons WHERE id=:pid");
                $pSel2->execute([':pid'=>$personId]);
                $person = $pSel2->fetch(PDO::FETCH_ASSOC);
            }

            /* 2) USER me rol student */
            $uSel = $pdo->prepare("SELECT id FROM users WHERE role_id = :rid AND person_id = :pid LIMIT 1");
            $uSel->execute([':rid'=>$studentRoleId, ':pid'=>$personId]);
            $userId = (int)($uSel->fetchColumn() ?: 0);

            $full = trim(($person['first_name'] ?? '').' '.(($person['father_name'] ?? '') ? ($person['father_name'].' ') : '').($person['last_name'] ?? ''));

            if ($userId === 0) {
                $uIns = $pdo->prepare("INSERT INTO users (role_id, person_id, full_name, email) VALUES (:rid, :pid, :fn, NULL)");
                $uIns->execute([':rid'=>$studentRoleId, ':pid'=>$personId, ':fn'=>$full !== '' ? $full : null]);
                $userId = (int)$pdo->lastInsertId();

                $initialPassword = make_initial_password($person['first_name'] ?? $first_name, $person['birth_date'] ?? $birth_date);
                $hash = password_hash($initialPassword, PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO credentials (user_id, password_hash, last_password_change) VALUES (:uid, :ph, NOW())")
                    ->execute([':uid'=>$userId, ':ph'=>$hash]);
            } else {
                $pdo->prepare("UPDATE users SET full_name=:fn WHERE id=:uid")
                    ->execute([':fn'=>($full !== '' ? $full : null), ':uid'=>$userId]);

                $cSel = $pdo->prepare("SELECT 1 FROM credentials WHERE user_id=:uid");
                $cSel->execute([':uid'=>$userId]);
                if (!$cSel->fetchColumn()) {
                    $initialPassword = make_initial_password($person['first_name'] ?? $first_name, $person['birth_date'] ?? $birth_date);
                    $hash = password_hash($initialPassword, PASSWORD_BCRYPT);
                    $pdo->prepare("INSERT INTO credentials (user_id, password_hash, last_password_change) VALUES (:uid, :ph, NOW())")
                        ->execute([':uid'=>$userId, ':ph'=>$hash]);
                }
            }

            /* 3) STUDENT – nr_amze unik */
            $insStud = $pdo->prepare("
                INSERT INTO students (person_id, user_id, nr_amze, education_level_id)
                VALUES (:pid, :uid, :amz, :edu)
            ");
            $insStud->execute([
                ':pid'=>$personId, ':uid'=>$userId, ':amz'=>$nr_amze,
                ':edu'=>($education_level_id > 0 ? $education_level_id : null)
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
$edu    = trim($_GET['edu'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where  = ["u.role_id = :studentRole"];
$params = [':studentRole' => $studentRoleId];

if ($q !== '') {
    $where[] = "(
        p.first_name      LIKE :kw1 OR
        p.father_name     LIKE :kw2 OR
        p.last_name       LIKE :kw3 OR
        s.nr_amze         LIKE :kw4 OR
        p.personal_number LIKE :kw5 OR
        p.phone           LIKE :kw6 OR
        p.birth_place     LIKE :kw7
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
    JOIN users u   ON u.id = s.user_id
    JOIN persons p ON p.id = s.person_id
    LEFT JOIN education_levels el ON el.id = s.education_level_id
    $whereSql
");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));

/* Lista – RENDITJE NUMERIKE sipas nr_amze */
$listStmt = $pdo->prepare("
    SELECT
        s.id              AS student_id,
        s.user_id,
        s.nr_amze,
        s.education_level_id AS edu_id,
        u.created_at,

        /* nga persons */
        p.first_name,
        p.father_name,
        p.last_name,
        p.birth_date,
        p.birth_place,
        p.personal_number,
        p.phone,
        p.gender_id,
        g.code  AS gender_code,
        g.label AS gender_label,

        /* arsimi */
        el.code AS edu_code,
        el.label AS edu_label

    FROM students s
    JOIN users u   ON u.id = s.user_id
    JOIN persons p ON p.id = s.person_id
    LEFT JOIN education_levels el ON el.id = s.education_level_id
    LEFT JOIN genders g           ON g.id = p.gender_id
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

/* Build toggle URL që ruan q/edu/page */
$toggleUrl = 'students.php?' . http_build_query(array_filter([
    'q' => ($q !== '' ? $q : null),
    'edu' => ($edu !== '' ? $edu : null),
    'page' => ($page > 1 ? $page : null),
    'edit' => ($EDIT_MODE ? 'off' : 'on')
]));
?>
<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="UTF-8" />
    <title>Studentët – QTA <?= $role==='editor' ? 'Editor' : 'Admin' ?></title>
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

        /* Inline-edit styles */
        .editable {
          display:inline-block; min-width:72px; padding:.35rem .5rem;
          border-radius:.5rem; transition:box-shadow .2s, background-color .2s;
        }
        .editable[contenteditable="true"]:hover { background:#f8fafc; box-shadow:inset 0 0 0 1px #e5e7eb; cursor:text; }
        .editable[contenteditable="true"]:focus { outline:0; background:#eef2ff; box-shadow:inset 0 0 0 2px #4f46e5; }
        .editable[contenteditable="false"] { opacity:.7; cursor:default; }
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

        /* Edit Mode OFF visuals */
        .editing-off .editable { color:#6b7280; cursor:not-allowed; }
        .editing-off td.cell .inline-select:disabled { background:#f3f4f6; color:#6b7280; cursor:not-allowed; }
        .badge-edit { letter-spacing:.2px; }

        /* --- Soft buttons & pills (UI e re) --- */
        .btn-pill { border-radius:999px !important; }
        .btn-soft-primary   { background:#eef2ff; color:#1d4ed8; border:1px solid #e0e7ff; }
        .btn-soft-primary:hover { background:#e0e7ff; color:#1d4ed8; }
        .btn-soft-success   { background:#ecfdf5; color:#166534; border:1px solid #bbf7d0; }
        .btn-soft-success:hover { background:#bbf7d0; color:#14532d; }
        .btn-soft-danger    { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        .btn-soft-danger:hover { background:#fecaca; color:#7f1d1d; }
        .btn-soft-secondary { background:#f1f5f9; color:#334155; border:1px solid #e2e8f0; }
        .btn-soft-secondary:hover { background:#e2e8f0; color:#0f172a; }

        /* FAB (+) poshtë DJATHTAS */
        .btn-fab{
          position: fixed;
          right: 24px;
          bottom: 24px;
          width: 56px; height: 56px; border-radius: 50%;
          display:flex; align-items:center; justify-content:center;
          z-index:1040; box-shadow:0 12px 20px rgba(2,6,23,.15);
        }
        .btn-fab i{ font-size:1.25rem; line-height:1; }
        .btn-fab:focus{ box-shadow:0 0 0 .25rem rgba(13,110,253,.25), 0 12px 20px rgba(2,6,23,.15); }
        @media (max-width:575.98px){ .btn-fab{ right:16px; bottom:16px; width:52px; height:52px; } }

        /* Toasts poshtë MAJTAS */
        .toast.qta-toast{ border:0; border-radius:.75rem; box-shadow:0 12px 20px rgba(2,6,23,.12); }
        .toast.qta-toast .toast-header{ border-bottom:0; }
        .toast-success .toast-header{ background:#ecfdf5; color:#065f46; }
        .toast-danger  .toast-header{ background:#fef2f2; color:#991b1b; }
        .toast-info    .toast-header{ background:#eff6ff; color:#1e40af; }
        .toast-warning .toast-header{ background:#fff7ed; color:#9a3412; }
    </style>
</head>
<body class="<?= $EDIT_MODE ? '' : 'editing-off' ?>">

<?php
  $NAV_ACTIVE = 'students';
  if ($role === 'administrator') require __DIR__ . '/inc/navbar.php';
  elseif ($role === 'editor')    require __DIR__ . '/inc/navbar4.php';
?>

<!-- Toast container -->
<div id="toastZone" class="toast-container position-fixed start-0 bottom-0 p-3" style="z-index:1080;"></div>

<main class="container-fluid px-3 px-md-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
        <h2 class="mb-0">Studentët</h2>
        <div class="d-flex align-items-center page-toolbar">
            <!-- Edit Mode Toggle -->
            <a class="btn btn-pill <?= $EDIT_MODE ? 'btn-success' : 'btn-soft-secondary' ?>"
               href="<?= htmlspecialchars($toggleUrl) ?>" title="Ndrysho gjendjen e Edit Mode">
               <i class="bi <?= $EDIT_MODE ? 'bi-unlock' : 'bi-lock' ?> me-1"></i>
               Edit Mode:
               <span class="badge ms-1 <?= $EDIT_MODE ? 'bg-light text-success' : 'bg-secondary' ?> badge-edit"><?= $EDIT_MODE ? 'ON' : 'OFF' ?></span>
            </a>
            <!-- Heqim butonin klasik 'Shto Student'; përdor FAB poshtë djathtas -->
        </div>
    </div>

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
                    <button class="btn btn-soft-secondary btn-pill me-1" type="button"
                            onclick="window.location='students.php'"><i class="bi bi-x-circle me-1"></i>Pastro</button>
                    <button class="btn btn-primary btn-pill" type="submit"><i class="bi bi-funnel me-1"></i>Apliko</button>
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
                            <th class="nowrap">Gjinia</th>
                            <th>Tel.</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($students): ?>
                        <?php foreach ($students as $s): $sid=(int)$s['student_id']; ?>
                            <tr>
                                <!-- nr_amze -->
                                <td class="cell" data-id="<?= $sid ?>" data-field="nr_amze">
                                    <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= htmlspecialchars($s['nr_amze']) ?></span>
                                </td>
                                <!-- first_name -->
                                <td class="cell" data-id="<?= $sid ?>" data-field="first_name">
                                    <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= htmlspecialchars($s['first_name'] ?: '—') ?></span>
                                </td>
                                <!-- father_name -->
                                <td class="cell" data-id="<?= $sid ?>" data-field="father_name">
                                    <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= htmlspecialchars($s['father_name'] ?: '—') ?></span>
                                </td>
                                <!-- last_name -->
                                <td class="cell" data-id="<?= $sid ?>" data-field="last_name">
                                    <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= htmlspecialchars($s['last_name'] ?: '—') ?></span>
                                </td>
                                <!-- personal_number -->
                                <td class="cell nowrap" data-id="<?= $sid ?>" data-field="personal_number">
                                    <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= htmlspecialchars($s['personal_number'] ?: '—') ?></span>
                                </td>
                                <!-- birth_date (display dd-mm-yyyy) -->
                                <td class="cell nowrap" data-id="<?= $sid ?>" data-field="birth_date" title="DD-MM-YYYY">
                                    <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= htmlspecialchars(fmt_dMY($s['birth_date'])) ?></span>
                                </td>
                                <!-- birth_place -->
                                <td class="cell" data-id="<?= $sid ?>" data-field="birth_place">
                                    <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= htmlspecialchars($s['birth_place'] ?: '—') ?></span>
                                </td>
                                <!-- education_level_id -->
                                <td class="cell" data-id="<?= $sid ?>" data-field="education_level_id">
                                    <select class="form-select form-select-sm inline-select" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                                        <option value="">— Zgjidh —</option>
                                        <?php foreach ($eduLevels as $el): ?>
                                            <option value="<?= (int)$el['id'] ?>" <?= ((int)$s['edu_id'] === (int)$el['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($el['code'].' — '.$el['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <!-- gender_id -->
                                <td class="cell" data-id="<?= $sid ?>" data-field="gender_id">
                                  <select class="form-select form-select-sm inline-select" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                                    <?php foreach ($genders as $g): ?>
                                      <option value="<?= (int)$g['id'] ?>" <?= ((int)$s['gender_id'] === (int)$g['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($g['label']) ?>
                                      </option>
                                    <?php endforeach; ?>
                                  </select>
                                </td>
                                <!-- phone -->
                                <td class="cell nowrap" data-id="<?= $sid ?>" data-field="phone">
                                    <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= htmlspecialchars($s['phone'] ?: '—') ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10" class="text-center text-muted">Nuk u gjet asnjë student.</td></tr>
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

<!-- FAB: Shto Student (poshtë djathtas) -->
<?php if ($EDIT_MODE): ?>
<button class="btn btn-primary btn-fab" type="button"
        data-bs-toggle="modal" data-bs-target="#addStudentModal"
        aria-label="Shto student">
  <i class="bi bi-plus-lg"></i>
</button>
<?php else: ?>
<button class="btn btn-soft-secondary btn-fab" type="button" disabled
        title="Aktivizo Edit Mode për të shtuar student">
  <i class="bi bi-plus-lg"></i>
</button>
<?php endif; ?>

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
                <label class="form-label">Numri Personal <span class="text-muted">(opsional)</span></label>
                <input type="text" name="personal_number" id="pnInput" class="form-control" placeholder="Opsional — për autoplotësim">
                <div class="form-text">Nëse ekziston, të dhënat e tjera do të plotësohen automatikisht.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Nr. Amzës *</label>
                <input type="text" name="nr_amze" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Arsimi</label>
                <select name="education_level_id" id="eduSelect" class="form-select">
                    <option value="">— Zgjidh —</option>
                    <?php foreach ($eduLevels as $el): ?>
                        <option value="<?= (int)$el['id'] ?>"><?= htmlspecialchars($el['code'].' — '.$el['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Emri <span class="text-muted">(opsional)</span></label>
                <input type="text" name="first_name" id="fnInput" class="form-control" placeholder="Opsional">
            </div>
            <div class="col-md-4">
                <label class="form-label">Atësia</label>
                <input type="text" name="father_name" id="fatInput" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Mbiemri <span class="text-muted">(opsional)</span></label>
                <input type="text" name="last_name" id="lnInput" class="form-control" placeholder="Opsional">
            </div>

            <div class="col-md-4">
                <label class="form-label">Datëlindja</label>
                <input type="date" name="birth_date" id="bdInput" class="form-control">
                <div class="form-text">Fjalëkalimi fillestar: <code>[Emri].[VitiLindjes]</code> (p.sh. <code>Ardit.1998</code>). Në mungesë vitit vendoset <code>0000</code>.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Vendlindja</label>
                <input type="text" name="birth_place" id="bpInput" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Tel.</label>
                <input type="text" name="phone" id="phInput" class="form-control" placeholder="+355 ...">
            </div>

            <div class="col-md-4">
                <label class="form-label">Gjinia</label>
                <select name="gender_id" id="genderSelect" class="form-select">
                    <?php foreach ($genders as $g): ?>
                        <option value="<?= (int)$g['id'] ?>" <?= ($g['code']==='M'?'selected':'') ?>>
                            <?= htmlspecialchars($g['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-text mt-2">
            Mund të krijosh student vetëm me <strong>Nr. Amzës</strong>. Fusha të tjera janë opsionale.
            Krijohet/ri-përdoret <code>users</code> + <code>credentials</code> (password auto).
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary btn-pill" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>>Shto student</button>
      </div>
    </form>
  </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const ENDPOINT = 'students_inline_update.php';
const EDIT_MODE = <?= $EDIT_MODE ? 'true' : 'false' ?>;

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

/* Helper: trim & normalizim “—” */
function cleanText(s) {
  const v = (s || '').replace(/\s+/g,' ').trim();
  return (v === '—' ? '' : v);
}

/* Parse dhe normalizo vlerën e datës për server-in (YYYY-MM-DD) */
function normalizeDateForServer(str) {
  const v = (str || '').trim();
  if (v === '' || v === '—') return '';
  // dd-mm-yyyy
  let m = v.match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/);
  if (m) {
    const dd = m[1].padStart(2,'0'), mm = m[2].padStart(2,'0'), yy = m[3];
    return `${yy}-${mm}-${dd}`;
  }
  // yyyy-mm-dd
  m = v.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
  if (m) {
    const yy = m[1], mm = m[2].padStart(2,'0'), dd = m[3].padStart(2,'0');
    return `${yy}-${mm}-${dd}`;
  }
  throw new Error('Formati i datës duhet të jetë DD-MM-YYYY.');
}

/* Ruajtje AJAX për inline */
async function saveInline(studentId, field, value, cell, displayEl) {
  if (!EDIT_MODE) return;
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

    if (displayEl && field !== 'education_level_id' && field !== 'gender_id') {
      displayEl.textContent = json.display ?? (value || '—');
    }
    cell.classList.add('cell-ok');
    setTimeout(()=>cell.classList.remove('cell-ok'), 800);
    notify('success','U ruajt me sukses.');
  } catch (e) {
    console.error(e);
    notify('danger', e.message || 'Ndodhi një gabim.');
    cell.classList.remove('cell-saving');
    cell.classList.add('cell-err');
    setTimeout(()=>cell.classList.remove('cell-err'), 1200);
  }
}

/* Event për contenteditable (blur & Enter) */
document.querySelectorAll('td.cell .editable').forEach(el => {
  let oldVal = el.textContent;
  if (!EDIT_MODE) el.setAttribute('contenteditable', 'false');

  el.addEventListener('focus', () => { oldVal = el.textContent; });
  el.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); el.blur(); }});
  el.addEventListener('blur', () => {
    if (!EDIT_MODE) return;
    const cell = el.closest('td.cell');
    const field = cell.dataset.field;
    const sid = parseInt(cell.dataset.id, 10);
    let newVal = cleanText(el.textContent);
    if (newVal === cleanText(oldVal)) return;

    if (field === 'birth_date') {
      try { newVal = normalizeDateForServer(newVal); }
      catch (err) { el.textContent = oldVal; notify('danger', err.message || err); return; }
    }
    saveInline(sid, field, newVal, cell, el);
  });
});

/* Event për select (education_level_id, gender_id) */
document.querySelectorAll('td.cell select.inline-select').forEach(sel => {
  if (!EDIT_MODE) sel.setAttribute('disabled', 'disabled');
  sel.addEventListener('change', () => {
    if (!EDIT_MODE) return;
    const cell  = sel.closest('td.cell');
    const sid   = parseInt(cell.dataset.id, 10);
    const field = cell.dataset.field;
    const val   = sel.value;
    saveInline(sid, field, val, cell, null);
  });
});

/* Autoplotësim nga Numri Personal (MODAL) */
const pnInput = document.getElementById('pnInput');
pnInput?.addEventListener('blur', async ()=>{
  const pn = pnInput.value.trim();
  if (!pn) return;
  try{
    const res = await fetch(`students.php?action=lookup_person&personal_number=${encodeURIComponent(pn)}`, {
      headers: {'Accept':'application/json'}
    });
    const json = await res.json();
    if (!json.ok) { notify('danger', json.error || 'Gabim në kërkim.'); return; }

    const p = json.person;
    if (p) {
      document.getElementById('fnInput').value  = p.first_name ?? '';
      document.getElementById('fatInput').value = p.father_name ?? '';
      document.getElementById('lnInput').value  = p.last_name ?? '';
      document.getElementById('bdInput').value  = p.birth_date ?? '';
      document.getElementById('bpInput').value  = p.birth_place ?? '';
      document.getElementById('phInput').value  = p.phone ?? '';

      const gsel = document.getElementById('genderSelect');
      if (gsel && p.gender_id) gsel.value = String(p.gender_id);

      const esel = document.getElementById('eduSelect');
      if (esel && json.education_level_id) esel.value = String(json.education_level_id);

      notify('info','Të dhënat u plotësuan nga numri personal.');
    } else {
      notify('warning','Nuk u gjet person me këtë numër personal.');
    }
  }catch(e){ console.error(e); notify('danger','Gabim gjatë autoplotësimit.'); }
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
