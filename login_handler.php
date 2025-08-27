<?php
// login_handler.php
session_start();
require 'database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: selectProfile.html');
    exit;
}

$role = $_POST['role'] ?? '';
$identifier = trim($_POST['identifier'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($role) || empty($identifier) || empty($password)) {
    $_SESSION['login_error'] = 'Plotësoni të gjitha fushat.';
    header('Location: selectProfile.html');
    exit;
}

$pdo = getPDO();

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
        $sql = "SELECT u.id AS user_id, r.name AS role_name, c.password_hash, u.full_name, s.personal_number
                FROM users u
                JOIN roles r ON u.role_id = r.id
                JOIN credentials c ON c.user_id = u.id
                JOIN students s ON s.user_id = u.id
                WHERE s.personal_number = :identifier AND r.name = 'student'
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':identifier' => $identifier]);
    } else {
        $_SESSION['login_error'] = 'Roli i papërcaktuar.';
        header('Location: selectProfile.html');
        exit;
    }

    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION['login_error'] = 'Kombinim i gabuar i të dhënave.';
        header('Location: selectProfile.html');
        exit;
    }

    if (!password_verify($password, $user['password_hash'])) {
        $_SESSION['login_error'] = 'Kombinim i gabuar i të dhënave.';
        header('Location: selectProfile.html');
        exit;
    }

    // Login sukses
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['role'] = $user['role_name'];
    $_SESSION['full_name'] = $user['full_name'] ?? '';

    // Redirect sipas role
    if ($user['role_name'] === 'administrator') {
        header('Location: dashboard_admin.php');
        exit;
    } elseif ($user['role_name'] === 'agjencia') {
        header('Location: dashboard_agjencia.php');
        exit;
    } elseif ($user['role_name'] === 'student') {
        header('Location: dashboard_student.php');
        exit;
    } else {
        // fallback
        header('Location: selectProfile.html');
        exit;
    }
} catch (Exception $e) {
    // log error in production
    $_SESSION['login_error'] = 'Ndodhi një gabim gjatë hyrjes.';
    header('Location: selectProfile.html');
    exit;
}