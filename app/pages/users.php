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
    $_SESSION['edit_mode'] = filter_var($_GET['edit'], FILTER_VALIDATE_BOOLEAN);
    // redirect pa param 'edit' (ruaj pjesën tjetër të query-it)
    $qs = $_GET; unset($qs['edit']);
    $redir = 'users.php' . ($qs ? ('?' . http_build_query($qs)) : '');
    header('Location: ' . $redir);
    exit;
}
$EDIT_MODE = !empty($_SESSION['edit_mode']);

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
            http_response_code(400); exit('Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.');
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
        flash('err','Ndryshimet janë të mbyllura. Shtyp "Lejo ndryshimet" dhe provo sërish.');
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
                throw new RuntimeException('Plotëso emrin, email-in dhe fjalëkalimin.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Email-i nuk duket i saktë. Kontrolloje, p.sh. emri@qta.al.');
            }
            if (mb_strlen($pass1) < 8) {
                throw new RuntimeException('Fjalëkalimi duhet të ketë të paktën 8 shenja.');
            }
            if ($pass1 !== $pass2) {
                throw new RuntimeException('Dy fjalëkalimet nuk janë njësoj. Shkruaji sërish.');
            }

            // Unik email
            $exists = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :e");
            $exists->execute([':e' => $email]);
            if ((int)$exists->fetchColumn() > 0) {
                throw new RuntimeException('Ky email përdoret nga një llogari tjetër.');
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
            flash('ok', 'Llogaria e administratorit u krijua. Hyrja bëhet me email-in dhe fjalëkalimin që vendose.');
        }
        elseif ($action === 'reset_password') {
            $uid   = (int)($_POST['user_id'] ?? 0);
            $pass1 = $_POST['new_password'] ?? '';
            $pass2 = $_POST['new_password2'] ?? '';
            if ($uid <= 0 || $pass1==='' || $pass2==='') throw new RuntimeException('Shkruaj fjalëkalimin e ri dy herë.');
            if (mb_strlen($pass1) < 8) throw new RuntimeException('Fjalëkalimi duhet të ketë të paktën 8 shenja.');
            if ($pass1 !== $pass2) throw new RuntimeException('Dy fjalëkalimet nuk janë njësoj. Shkruaji sërish.');

            // Vetëm për administratorë
            $roleQ = $pdo->prepare("SELECT role_id FROM users WHERE id = :uid");
            $roleQ->execute([':uid' => $uid]);
            $targetRole = $roleQ->fetchColumn();
            if (!$targetRole || (int)$targetRole !== $adminRoleId) {
                throw new RuntimeException('Kjo llogari nuk është në listën e administratorëve. Rifresko faqen.');
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
            flash('ok', 'Fjalëkalimi u ndryshua. Njoftoje personin për fjalëkalimin e ri.');
        }
        elseif ($action === 'delete_user') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid <= 0) throw new RuntimeException('Llogaria nuk u gjet. Rifresko faqen.');
            if ($uid === (int)$currentUser['id']) throw new RuntimeException('Nuk mund ta fshish llogarinë tënde.');

            // Vetëm nëse target-i është administrator
            $roleQ = $pdo->prepare("SELECT role_id FROM users WHERE id = :uid");
            $roleQ->execute([':uid' => $uid]);
            $targetRole = $roleQ->fetchColumn();
            if (!$targetRole || (int)$targetRole !== $adminRoleId) {
                throw new RuntimeException('Kjo llogari nuk është në listën e administratorëve. Rifresko faqen.');
            }

            $del = $pdo->prepare("DELETE FROM users WHERE id = :uid");
            $del->execute([':uid' => $uid]);
            flash('ok', 'Llogaria e administratorit u fshi.');
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


$SA = [
  'page'     => 'users.php',
  'title'    => 'Administratorët',
  'lead'     => 'Llogaritë me qasje të plotë në regjistër. Shto vetëm persona që drejtojnë punën në QTA.',
  'one'      => 'administrator',
  'many'     => 'administratorë',
  'create'   => 'create_admin',
  'endpoint' => 'user_inline.php',
  'nav'      => 'users_admins',
  'help'     => 'users',
  'can'      => ['regjistron dhe ndryshon kursantë, grupe e provime', 'menaxhon agjencitë', 'shton administratorë dhe editorë', 'sheh historikun e plotë të ndryshimeve'],
];
require __DIR__ . '/../shared/partials/staff_accounts.php';
