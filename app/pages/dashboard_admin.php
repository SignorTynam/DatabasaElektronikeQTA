<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* ===== Vetëm administratori ===== */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }

$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id
  LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || ($currentUser['role_name'] ?? '') !== 'administrator') {
  header('Location: selectProfile.php'); exit;
}

$NAV_ACTIVE = 'dashboard';
$HELP_TOPIC = 'dashboard_staff';
$DASH_ROLE  = 'administrator';
$pageTitle  = 'Kreu';

require __DIR__ . '/../shared/app_head.php';
require __DIR__ . '/inc/navbar.php';
require __DIR__ . '/../shared/partials/dashboard_staff.php';
require __DIR__ . '/../shared/app_scripts.php';
?>
</body>
</html>
