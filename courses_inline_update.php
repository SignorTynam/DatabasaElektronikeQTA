<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

header('Content-Type: application/json; charset=UTF-8');

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* Guard: vetëm admin i loguar */
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Nuk jeni i autentikuar.']); exit;
}
$userStmt = $pdo->prepare("
    SELECT u.id, r.name AS role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id = :uid LIMIT 1
");
$userStmt->execute([':uid'=>$_SESSION['user_id']]);
$me = $userStmt->fetch();

// brenda courses_inline_update.php
$meRole = strtolower((string)$me['role_name'] ?? '');
if (!in_array($meRole, ['administrator','editor'], true)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Lejohet vetëm për administrator ose editor.']); exit;
}

/* Lexo input (JSON ose form) */
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$csrf      = $data['csrf'] ?? '';
$course_id = (int)($data['course_id'] ?? 0);
$field     = trim((string)($data['field'] ?? ''));
$value     = $data['value'] ?? null;

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'CSRF token mismatch.']); exit;
}
if ($course_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'ID kursi e pavlefshme.']); exit;
}

/* Verifiko që kursi ekziston */
$chk = $pdo->prepare("SELECT id FROM courses WHERE id = :id LIMIT 1");
$chk->execute([':id'=>$course_id]);
if (!$chk->fetch()) {
    echo json_encode(['ok'=>false,'error'=>'Moduli nuk u gjet.']); exit;
}

/* Whitelist fushash */
$allowed = ['code','name','hours'];
if (!in_array($field, $allowed, true)) {
    echo json_encode(['ok'=>false,'error'=>'Fusha nuk lejohet për redaktim.']); exit;
}

try {
    if ($field === 'code') {
        $v = trim((string)$value);
        if ($v === '') throw new RuntimeException('Kodi është i detyrueshëm.');
        // unik
        $q = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE code=:v AND id<>:id");
        $q->execute([':v'=>$v, ':id'=>$course_id]);
        if ((int)$q->fetchColumn() > 0) throw new RuntimeException('Ky kod kursi përdoret nga modul tjetër.');
        $pdo->prepare("UPDATE courses SET code=:v WHERE id=:id")->execute([':v'=>$v, ':id'=>$course_id]);
        echo json_encode(['ok'=>true,'display'=>$v]); exit;

    } elseif ($field === 'name') {
        $v = trim((string)$value);
        if ($v === '') throw new RuntimeException('Emri është i detyrueshëm.');
        $pdo->prepare("UPDATE courses SET name=:v WHERE id=:id")->execute([':v'=>$v, ':id'=>$course_id]);
        echo json_encode(['ok'=>true,'display'=>$v]); exit;

    } elseif ($field === 'hours') {
        $v = trim((string)$value);
        if ($v === '' || !ctype_digit($v) || (int)$v < 1 || (int)$v > 65535) {
            throw new RuntimeException('“Orë” duhet të jetë numër i plotë ≥ 1.');
        }
        $iv = (int)$v;
        $pdo->prepare("UPDATE courses SET hours=:v WHERE id=:id")->execute([':v'=>$iv, ':id'=>$course_id]);
        echo json_encode(['ok'=>true,'display'=>$iv]); exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Fusha e panjohur.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
