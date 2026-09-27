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
    $redir = 'editors.php' . ($qs ? ('?' . http_build_query($qs)) : '');
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

if (!$currentUser || strtolower((string)$currentUser['role_name']) !== 'administrator') {
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
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

/* ------------------------------
   Merr rolet & gjej editorRoleId
------------------------------- */
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$editorRoleId = null;
foreach ($roles as $r) { if (strtolower($r['name']) === 'editor') { $editorRoleId = (int)$r['id']; break; } }
if ($editorRoleId === null) { exit('Konfigurim i mangët: roli "editor" mungon në tabelën roles.'); }

/* ------------------------------
   Veprime POST (create/reset/delete)
   → Lejohen vetëm kur Edit Mode është ON
------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  if (!$EDIT_MODE) {
    flash('err','Ndryshimet janë të mbyllura. Shtyp "Lejo ndryshimet" dhe provo sërish.');
    header('Location: editors.php'); exit;
  }

  $action = $_POST['action'] ?? '';

  try {
    if ($action === 'create_editor') {
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

      // Krijo user-in (rol = editor)
      $insUser = $pdo->prepare("
        INSERT INTO users (role_id, full_name, email)
        VALUES (:rid, :fn, :em)
      ");
      $insUser->execute([
        ':rid' => $editorRoleId,
        ':fn'  => $full_name,
        ':em'  => $email
      ]);
      $newUserId = (int)$pdo->lastInsertId();

      // Krijo kredencialet
      $hash = password_hash($pass1, PASSWORD_BCRYPT);
      $insCred = $pdo->prepare("
        INSERT INTO credentials (user_id, password_hash, last_password_change)
        VALUES (:uid, :ph, NOW())
      ");
      $insCred->execute([':uid' => $newUserId, ':ph' => $hash]);

      $pdo->commit();
      flash('ok', 'Llogaria e editorit u krijua. Hyrja bëhet me email-in dhe fjalëkalimin që vendose.');
    }
    elseif ($action === 'reset_password') {
      $uid   = (int)($_POST['user_id'] ?? 0);
      $pass1 = $_POST['new_password'] ?? '';
      $pass2 = $_POST['new_password2'] ?? '';
      if ($uid <= 0 || $pass1==='' || $pass2==='') throw new RuntimeException('Shkruaj fjalëkalimin e ri dy herë.');
      if (mb_strlen($pass1) < 8) throw new RuntimeException('Fjalëkalimi duhet të ketë të paktën 8 shenja.');
      if ($pass1 !== $pass2) throw new RuntimeException('Dy fjalëkalimet nuk janë njësoj. Shkruaji sërish.');

      // Lejo vetëm për llogari me rol "editor"
      $roleQ = $pdo->prepare("SELECT role_id FROM users WHERE id = :uid");
      $roleQ->execute([':uid' => $uid]);
      $targetRole = (int)($roleQ->fetchColumn() ?: 0);
      if ($targetRole !== $editorRoleId) {
        throw new RuntimeException('Kjo llogari nuk është në listën e editorëve. Rifresko faqen.');
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

      // Lejo fshirje vetëm për editor
      $roleQ = $pdo->prepare("SELECT role_id FROM users WHERE id = :uid");
      $roleQ->execute([':uid' => $uid]);
      $targetRole = (int)($roleQ->fetchColumn() ?: 0);
      if ($targetRole !== $editorRoleId) {
        throw new RuntimeException('Kjo llogari nuk është në listën e editorëve. Rifresko faqen.');
      }

      $del = $pdo->prepare("DELETE FROM users WHERE id = :uid");
      $del->execute([':uid' => $uid]);
      flash('ok', 'Llogaria e editorit u fshi.');
    }

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    flash('err', $e->getMessage());
  }

  header('Location: editors.php'); exit;
}

/* ------------------------------
   Filtrim + paginim (vetëm editor)
------------------------------- */
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where   = ["r.id = :editorRole"];
$params  = [':editorRole' => $editorRoleId];

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
  'page'     => 'editors.php',
  'title'    => 'Editorët',
  'lead'     => 'Kolegët që mbajnë regjistrin çdo ditë: regjistrojnë kursantë, krijojnë grupe dhe vendosin datat e pikët e provimeve.',
  'one'      => 'editor',
  'many'     => 'editorë',
  'create'   => 'create_editor',
  'endpoint' => 'editors_inline.php',
  'nav'      => 'users_editors',
  'help'     => 'editors',
  'can'      => ['regjistron dhe ndryshon kursantë, grupe e provime', 'menaxhon agjencitë', 'sheh historikun e ndryshimeve të veta', 'nuk shton dot llogari stafi'],
];
require __DIR__ . '/../shared/partials/staff_accounts.php';
