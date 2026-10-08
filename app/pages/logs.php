<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/session.php';
qta_session_boot();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null); // vendos @audit_* për këtë request
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

/* ===== Guard admin ===== */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);
if ($currentUser && strtolower((string)($currentUser['role_name'] ?? '')) === 'editor') {
  // Editori sheh vetëm historikun e vet
  header('Location: logs_editor.php' . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']));
  exit;
}
if (!$currentUser || strtolower((string)($currentUser['role_name']??''))!=='administrator') { header('Location: selectProfile.php'); exit; }

$LOG = [
  'page'       => 'logs.php',
  'scope_user' => null,
  'nav'        => 'logs',
  'navbar'     => 'navbar.php',
  'title'      => 'Historiku i ndryshimeve',
  'lead'       => 'Kush shtoi, ndryshoi ose fshiu të dhëna, kur dhe çfarë ndryshoi.',
  'csv'        => 'historiku',
];
require __DIR__ . '/../shared/activity_log.php';
