<?php
// dashboard_student.php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: selectProfile.html');
    exit;
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Dashboard Student</title></head>
<body>
<h1>Mirësevini, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Student') ?></h1>
<p>Kjo është faqja e studentit.</p>
<p><a href="logout.php">Dil</a></p>
</body>
</html>