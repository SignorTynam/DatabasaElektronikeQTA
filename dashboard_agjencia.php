<?php
// dashboard_agjencia.php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'agjencia') {
    header('Location: selectProfile.html');
    exit;
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Dashboard Agjencia</title></head>
<body>
<h1>Mirësevini, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Agjencia') ?></h1>
<p>Kjo është faqja e agjencisë.</p>
<p><a href="logout.php">Dil</a></p>
</body>
</html>