<?php
// login_handler.php
session_start();
require __DIR__ . '/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: selectProfile.php');
    exit;
}

$role = $_POST['role'] ?? '';
$identifier = trim($_POST['identifier'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($role) || empty($identifier) || empty($password)) {
    $_SESSION['login_error'] = 'Plotësoni të gjitha fushat.';
    header('Location: selectProfile.php?role=' . urlencode($role ?: 'administrator'));
    exit;
}

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

try {
    if ($role === 'administrator') {
        $sql = "SELECT u.id AS user_id, r.name AS role_name, c.password_hash, u.full_name
                FROM users u
                JOIN roles r ON u.role_id = r.id
                JOIN credentials c ON c.user_id = u.id
                WHERE u.email = :identifier AND r.name = 'administrator'
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':identifier' => $identifier]);

    } elseif ($role === 'editor') {
        $sql = "SELECT u.id AS user_id, r.name AS role_name, c.password_hash, u.full_name
                FROM users u
                JOIN roles r ON u.role_id = r.id
                JOIN credentials c ON c.user_id = u.id
                WHERE u.email = :identifier AND r.name = 'editor'
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':identifier' => $identifier]);

    } elseif ($role === 'agjencia') {
        $sql = "SELECT u.id AS user_id, r.name AS role_name, c.password_hash, u.full_name, a.nip_t
                FROM users u
                JOIN roles r ON u.role_id = r.id
                JOIN credentials c ON c.user_id = u.id
                JOIN agencies a ON a.user_id = u.id
                WHERE a.nip_t = :identifier AND r.name = 'agjencia'
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':identifier' => $identifier]);

    } elseif ($role === 'student') {
        /* Përshtatur me skemën ku personal_number është te persons:
           users -> students (user_id) -> persons (personal_number) */
        $sql = "SELECT u.id AS user_id, r.name AS role_name, c.password_hash, u.full_name, p.personal_number
                FROM users u
                JOIN roles r      ON u.role_id = r.id
                JOIN credentials c ON c.user_id = u.id
                JOIN students s    ON s.user_id = u.id
                JOIN persons  p    ON p.id = s.person_id
                WHERE p.personal_number = :identifier AND r.name = 'student'
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':identifier' => $identifier]);

    } else {
        $_SESSION['login_error'] = 'Roli i papërcaktuar.';
        header('Location: selectProfile.php?role=' . urlencode($role));
        exit;
    }

    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $_SESSION['login_error'] = 'Kombinim i gabuar i të dhënave.';
        header('Location: selectProfile.php?role=' . urlencode($role));
        exit;
    }

    // Login sukses
    session_regenerate_id(true);
    $_SESSION['user_id']   = (int)$user['user_id'];
    $_SESSION['role']      = $user['role_name'];
    $_SESSION['full_name'] = $user['full_name'] ?? '';

    // Redirect sipas rolit
    switch ($user['role_name']) {
        case 'administrator':
            header('Location: dashboard_admin.php'); exit;
        case 'editor':
            header('Location: dashboard_editor.php'); exit;
        case 'agjencia':
            header('Location: dashboard_agjencia.php'); exit;
        case 'student':
            header('Location: dashboard_student.php'); exit;
        default:
            header('Location: selectProfile.php'); exit;
    }

} catch (Exception $e) {
    // log error në prod
    $_SESSION['login_error'] = 'Ndodhi një gabim gjatë hyrjes.';
    header('Location: selectProfile.php?role=' . urlencode($role));
    exit;
}
