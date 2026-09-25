<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

/* ===== Guard: vetëm EDITOR ===== */
if (!isset($_SESSION['user_id'])) {
  header('Location: selectProfile.php');
  exit;
}
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);
$role = strtolower((string)($currentUser['role_name'] ?? ''));
if ($currentUser && $role === 'administrator') {
  // Administratori sheh historikun e plotë
  header('Location: logs.php' . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']));
  exit;
}
if (!$currentUser || $role!=='editor') {
  // adminët e kanë logs.php; këtu lejoj vetëm editorët
  header('Location: selectProfile.php');
  exit;
}
$ME_ID = (int)$currentUser['id'];

$LOG = [
  'page'       => 'logs_editor.php',
  'scope_user' => $ME_ID,
  'nav'        => 'logs',
  'navbar'     => 'navbar4.php',
  'title'      => 'Historiku im',
  'lead'       => 'Çdo gjë që ke shtuar, ndryshuar ose fshirë në regjistër, me vlerat para dhe pas. Të ndihmon të kujtosh ose të korrigjosh një ndryshim.',
  'csv'        => 'historiku_im',
];
require __DIR__ . '/../shared/activity_log.php';
