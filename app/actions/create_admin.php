<?php
declare(strict_types=1);

/**
 * create_admin.php — Krijon llogarinë e parë të administratorit.
 *
 * SIGURI: punon VETËM nga rreshti i komandave në server, kurrë nga shfletuesi.
 * Më parë ky skedar ishte i hapur në internet dhe krijonte një administrator me
 * fjalëkalim të shkruar në kod. Tani:
 *
 *   php app/actions/create_admin.php email@qta.al "Emri Mbiemri"
 *
 * Fjalëkalimi kërkohet në terminal (nuk ruhet askund në kod).
 * Për llogaritë e tjera përdor faqen "Administratorët" brenda portalit.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

$adminEmail    = trim((string)($argv[1] ?? ''));
$adminFullName = trim((string)($argv[2] ?? 'Administrator'));

if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Përdorimi: php app/actions/create_admin.php email@qta.al \"Emri Mbiemri\"\n");
    exit(1);
}

fwrite(STDOUT, "Fjalëkalimi (të paktën 8 shenja): ");
$adminPassword = rtrim((string)fgets(STDIN), "\r\n");
fwrite(STDOUT, "Shkruaje sërish: ");
$again = rtrim((string)fgets(STDIN), "\r\n");

if (mb_strlen($adminPassword) < 8) { fwrite(STDERR, "Fjalëkalimi duhet të ketë të paktën 8 shenja.\n"); exit(1); }
if ($adminPassword !== $again)      { fwrite(STDERR, "Dy fjalëkalimet nuk janë njësoj.\n"); exit(1); }

try {
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'administrator'");
    $stmt->execute();
    $role = $stmt->fetchColumn();
    if (!$role) throw new RuntimeException("Roli 'administrator' mungon në tabelën roles.");

    $exists = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :e");
    $exists->execute([':e' => $adminEmail]);
    if ((int)$exists->fetchColumn() > 0) throw new RuntimeException('Ky email ekziston tashmë.');

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO users (role_id, full_name, email) VALUES (:role, :name, :email)");
    $stmt->execute([':role' => $role, ':name' => $adminFullName, ':email' => $adminEmail]);
    $userId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO credentials (user_id, password_hash, last_password_change) VALUES (:uid, :hash, NOW())");
    $stmt->execute([':uid' => $userId, ':hash' => password_hash($adminPassword, PASSWORD_DEFAULT)]);

    $stmt = $pdo->prepare("INSERT INTO admins (user_id, employee_code) VALUES (:uid, :code)");
    $stmt->execute([':uid' => $userId, ':code' => 'ADM' . str_pad((string)$userId, 5, '0', STR_PAD_LEFT)]);

    $pdo->commit();
    fwrite(STDOUT, "Administratori u krijua: $adminEmail\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Gabim: ' . $e->getMessage() . "\n");
    exit(1);
}
