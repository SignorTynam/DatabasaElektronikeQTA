<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

header('Content-Type: application/json; charset=UTF-8');

$pdo = getPDO();

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
if (!$me || $me['role_name'] !== 'administrator') {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Lejohet vetëm për administrator.']); exit;
}

/* Lexo input (JSON ose form) */
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$csrf       = $data['csrf'] ?? '';
$student_id = (int)($data['student_id'] ?? 0);
$field      = trim((string)($data['field'] ?? ''));
$value      = $data['value'] ?? null;

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'CSRF token mismatch.']); exit;
}
if ($student_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'ID studenti e pavlefshme.']); exit;
}

/* Verifiko që është vërtet student */
$roleQ = $pdo->prepare("
    SELECT s.id, s.user_id, u.role_id
    FROM students s
    JOIN users u ON u.id = s.user_id
    WHERE s.id = :sid LIMIT 1
");
$roleQ->execute([':sid'=>$student_id]);
$row = $roleQ->fetch();
if (!$row) { echo json_encode(['ok'=>false,'error'=>'Studenti nuk u gjet.']); exit; }

/* Whitelist fushash që lejohet të modifikohen */
$allowed = [
    'first_name','father_name','last_name',
    'birth_date','birth_place','nr_amze',
    'personal_number','phone','education_level_id',
    'gender_id'
];
if (!in_array($field, $allowed, true)) {
    echo json_encode(['ok'=>false,'error'=>'Fusha nuk lejohet për redaktim.']); exit;
}

/* Normalizime + Validime */
$dispValue = null; // vlera që do kthehet për shfaqje
$params = [':sid'=>$student_id];

try {
    if ($field === 'gender_id') {
        // në UI nuk pritet bosh, por nëse vjen bosh -> default Mashkull
        $gid = null;
        if ($value === '' || $value === null) {
            $maleId = (int)$pdo->query("SELECT id FROM genders WHERE code='M' LIMIT 1")->fetchColumn();
            if (!$maleId) {
                $anyId = (int)$pdo->query("SELECT id FROM genders ORDER BY id LIMIT 1")->fetchColumn();
                if (!$anyId) { throw new RuntimeException('Konfigurimi i gjinisë mungon.'); }
                $gid = $anyId;
            } else {
                $gid = $maleId;
            }
        } else {
            $gid = (int)$value;
        }

        // verifiko ekzistencën dhe lexo code/label për display
        $gchk = $pdo->prepare("SELECT id, code, label FROM genders WHERE id=:id");
        $gchk->execute([':id'=>$gid]);
        $g = $gchk->fetch();
        if (!$g) throw new RuntimeException('Gjinia e zgjedhur nuk ekziston.');

        $st = $pdo->prepare("UPDATE students SET gender_id=:g WHERE id=:sid");
        $st->execute([':g'=>(int)$g['id'], ':sid'=>$student_id]);

        $dispValue = ['id'=>(int)$g['id'], 'code'=>$g['code'], 'label'=>$g['label']];

    } elseif ($field === 'education_level_id') {
        // lejo bosh -> NULL
        if ($value === '' || $value === null) {
            $sql = "UPDATE students SET education_level_id = NULL WHERE id = :sid";
            $pdo->prepare($sql)->execute($params);
            $dispValue = ['id'=>null,'code'=>null,'label'=>null];
        } else {
            $eduid = (int)$value;
            // verifiko që ekziston
            $chk = $pdo->prepare("SELECT id, code, label FROM education_levels WHERE id = :id");
            $chk->execute([':id'=>$eduid]);
            $ed = $chk->fetch();
            if (!$ed) throw new RuntimeException('Niveli i arsimit i pavlefshëm.');
            $sql = "UPDATE students SET education_level_id = :ed WHERE id = :sid";
            $st = $pdo->prepare($sql);
            $st->execute([':ed'=>$eduid, ':sid'=>$student_id]);
            $dispValue = ['id'=>(int)$ed['id'],'code'=>$ed['code'],'label'=>$ed['label']];
        }

    } elseif ($field === 'birth_date') {
        $v = trim((string)$value);
        if ($v === '') {
            $pdo->prepare("UPDATE students SET birth_date=NULL WHERE id=:sid")->execute($params);
            $dispValue = '—';
        } else {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                throw new RuntimeException('Data duhet në formatin YYYY-MM-DD.');
            }
            $pdo->prepare("UPDATE students SET birth_date=:v WHERE id=:sid")->execute([':v'=>$v,':sid'=>$student_id]);
            $dispValue = $v;
        }

    } elseif ($field === 'nr_amze') {
        $v = trim((string)$value);
        if ($v === '') throw new RuntimeException('Nr. i amzës është i detyrueshëm.');
        $c = $pdo->prepare("SELECT COUNT(*) FROM students WHERE nr_amze=:v AND id<>:sid");
        $c->execute([':v'=>$v,':sid'=>$student_id]);
        if ((int)$c->fetchColumn() > 0) throw new RuntimeException('Nr. i amzës përdoret nga student tjetër.');
        $pdo->prepare("UPDATE students SET nr_amze=:v WHERE id=:sid")->execute([':v'=>$v,':sid'=>$student_id]);
        $dispValue = $v;

    } elseif ($field === 'personal_number') {
        $v = trim((string)$value);
        if ($v === '') throw new RuntimeException('Numri Personal është i detyrueshëm.');
        $c = $pdo->prepare("SELECT COUNT(*) FROM students WHERE personal_number=:v AND id<>:sid");
        $c->execute([':v'=>$v,':sid'=>$student_id]);
        if ((int)$c->fetchColumn() > 0) throw new RuntimeException('Numri Personal përdoret nga student tjetër.');
        $pdo->prepare("UPDATE students SET personal_number=:v WHERE id=:sid")->execute([':v'=>$v,':sid'=>$student_id]);
        $dispValue = $v;

    } else {
        // fusha tekstuale të tjera (lejo bosh -> NULL)
        $v = trim((string)$value);
        $col = $field; // safe sepse vjen vetëm nga $allowed
        $pdo->prepare("UPDATE students SET $col = :v WHERE id=:sid")->execute([':v'=>($v===''?null:$v),':sid'=>$student_id]);
        $dispValue = ($v===''?'—':$v);
    }

    // Nëse ndryshon emri/atësi/mbiemri -> sinkronizo users.full_name
    if (in_array($field, ['first_name','father_name','last_name'], true)) {
        $nm = $pdo->prepare("SELECT first_name,father_name,last_name,user_id FROM students WHERE id=:sid");
        $nm->execute([':sid'=>$student_id]);
        $S = $nm->fetch();
        if ($S) {
            $full = trim(($S['first_name']??'').' '.(($S['father_name']??'')?($S['father_name'].' '):'').($S['last_name']??''));
            $pdo->prepare("UPDATE users SET full_name=:fn WHERE id=:uid")->execute([':fn'=>$full, ':uid'=>$S['user_id']]);
        }
    }

    echo json_encode(['ok'=>true,'display'=>$dispValue]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}