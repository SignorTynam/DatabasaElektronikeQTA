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
    echo json_encode(['ok'=>false,'error'=>'Seanca ka mbaruar. Hyr sërish në llogari.']); exit;
}
$userStmt = $pdo->prepare("
    SELECT u.id, r.name AS role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id = :uid
    LIMIT 1
");
$userStmt->execute([':uid'=>$_SESSION['user_id']]);
$me = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$me || strtolower((string)$me['role_name']) !== 'administrator') {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Vetëm administratorët mund t\'i ndryshojnë llogaritë e stafit.']); exit;
}

/* Lexo input (JSON ose form) */
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$csrf    = $data['csrf'] ?? '';
$user_id = (int)($data['user_id'] ?? 0);
$field   = trim((string)($data['field'] ?? ''));
$value   = $data['value'] ?? null;

/* CSRF & validime të para */
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.']); exit;
}
if (empty($_SESSION['edit_mode'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Ndryshimet janë të mbyllura. Shtyp "Lejo ndryshimet" dhe provo sërish.']); exit;
}
if ($user_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Llogaria nuk u gjet. Rifresko faqen.']); exit;
}

/* Target duhet të jetë EDITOR */
$roleQ = $pdo->prepare("
  SELECT u.id, u.email, u.full_name, r.id AS role_id, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = :uid
  LIMIT 1
");
$roleQ->execute([':uid'=>$user_id]);
$target = $roleQ->fetch(PDO::FETCH_ASSOC);
if (!$target) {
    echo json_encode(['ok'=>false,'error'=>'Llogaria nuk u gjet. Rifresko faqen.']); exit;
}
if (strtolower((string)$target['role_name']) !== 'editor') {
    echo json_encode(['ok'=>false,'error'=>'Kjo llogari nuk është editor. Rifresko faqen.']); exit;
}

/* Fusha të lejuara */
$allowed = ['full_name','email'];
if (!in_array($field, $allowed, true)) {
    echo json_encode(['ok'=>false,'error'=>'Kjo fushë nuk mund të ndryshohet këtu.']); exit;
}

$dispValue = null;

try {
    if ($field === 'email') {
        $v = trim((string)$value);
        if ($v === '') throw new RuntimeException('Shkruaj email-in.');
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email-i nuk duket i saktë. Kontrolloje, p.sh. emri@qta.al.');

        // Unikësi (përveç përdoruesit aktual)
        $c = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :e AND id <> :id");
        $c->execute([':e'=>$v, ':id'=>$user_id]);
        if ((int)$c->fetchColumn() > 0) throw new RuntimeException('Ky email përdoret nga një llogari tjetër.');

        $pdo->prepare("UPDATE users SET email=:e WHERE id=:id")->execute([':e'=>$v, ':id'=>$user_id]);
        $dispValue = $v;

    } elseif ($field === 'full_name') {
        $v = trim((string)$value);
        // lejo bosh -> NULL
        $pdo->prepare("UPDATE users SET full_name=:fn WHERE id=:id")
            ->execute([':fn'=>($v===''?null:$v), ':id'=>$user_id]);
        $dispValue = ($v==='' ? '—' : $v);
    }

    echo json_encode(['ok'=>true,'display'=>$dispValue]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
