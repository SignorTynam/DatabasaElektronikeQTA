<?php
// create_admin.php
require 'database.php';

$pdo = getPDO();

// ndrysho këto të dhëna sipas dëshirës
$adminEmail = 'admin@qta.test';
$adminFullName = 'Super Admin';
$adminPassword = 'Admin123!'; // ndrysho menjëherë pas krijimit

// Email: admin@qta.test, Password: Admin123! (ruaje diku të sigurt)

try {
    // gjej role_id per administrator
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'administrator'");
    $stmt->execute();
    $role = $stmt->fetchColumn();
    if (!$role) throw new Exception("Role 'administrator' not found.");

    // krijo user
    $stmt = $pdo->prepare("INSERT INTO users (role_id, full_name, email) VALUES (:role, :name, :email)");
    $stmt->execute([':role'=>$role, ':name'=>$adminFullName, ':email'=>$adminEmail]);
    $userId = (int)$pdo->lastInsertId();

    // shto cred (hash password)
    $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO credentials (user_id, password_hash, last_password_change) VALUES (:uid, :hash, NOW())");
    $stmt->execute([':uid'=>$userId, ':hash'=>$hash]);

    // (opsional) shto rresht ne admins
    $stmt = $pdo->prepare("INSERT INTO admins (user_id, employee_code) VALUES (:uid, :code)");
    $stmt->execute([':uid'=>$userId, ':code'=>'ADM001']);

    echo "Admin i krijuar me sukses. Email: $adminEmail, Password: $adminPassword (ruaje diku të sigurt)";
} catch (Exception $e) {
    echo "Gabim: " . $e->getMessage();
}