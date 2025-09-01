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

/* Mer të dhënat bazë të studentit, përfshi person_id */
$roleQ = $pdo->prepare("
    SELECT s.id, s.user_id, s.person_id, u.role_id
    FROM students s
    JOIN users u ON u.id = s.user_id
    WHERE s.id = :sid LIMIT 1
");
$roleQ->execute([':sid'=>$student_id]);
$row = $roleQ->fetch();
if (!$row) { echo json_encode(['ok'=>false,'error'=>'Studenti nuk u gjet.']); exit; }

$person_id = (int)$row['person_id'];
if ($person_id <= 0) { echo json_encode(['ok'=>false,'error'=>'Lidhja me personin mungon.']); exit; }

/* Whitelist fushash */
$allowed = [
    'first_name','father_name','last_name',
    'birth_date','birth_place','nr_amze',
    'personal_number','phone','education_level_id',
    'gender_id'
];
if (!in_array($field, $allowed, true)) {
    echo json_encode(['ok'=>false,'error'=>'Fusha nuk lejohet për redaktim.']); exit;
}

/* Ndarje fushash sipas tabelës */
$personFields  = ['first_name','father_name','last_name','birth_date','birth_place','personal_number','phone','gender_id'];
$studentFields = ['nr_amze','education_level_id'];

$dispValue = null;

try {
    $pdo->beginTransaction();

    if (in_array($field, $personFields, true)) {
        /* ———————— UPDATE te persons ———————— */
        if ($field === 'gender_id') {
            $gid = ($value === '' || $value === null)
                ? (int)($pdo->query("SELECT id FROM genders WHERE code='M' LIMIT 1")->fetchColumn() ?: 0)
                : (int)$value;

            $gchk = $pdo->prepare("SELECT id, code, label FROM genders WHERE id=:id");
            $gchk->execute([':id'=>$gid]);
            $g = $gchk->fetch();
            if (!$g) throw new RuntimeException('Gjinia e zgjedhur nuk ekziston.');

            $q = $pdo->prepare("UPDATE persons SET gender_id=:g WHERE id=:pid");
            $q->execute([':g'=>(int)$g['id'], ':pid'=>$person_id]);

            $dispValue = ['id'=>(int)$g['id'],'code'=>$g['code'],'label'=>$g['label']];
        }
        elseif ($field === 'birth_date') {
            $v = trim((string)$value);
            if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                throw new RuntimeException('Data duhet në formatin YYYY-MM-DD.');
            }
            $q = $pdo->prepare("UPDATE persons SET birth_date=:v WHERE id=:pid");
            $q->execute([':v'=>($v===''?null:$v), ':pid'=>$person_id]);
            $dispValue = ($v===''?'—':$v);
        }
        elseif ($field === 'personal_number') {
            $v = trim((string)$value);
            if ($v === '') throw new RuntimeException('Numri Personal është i detyrueshëm.');
            /* Unike në persons */
            $c = $pdo->prepare("SELECT COUNT(*) FROM persons WHERE personal_number=:v AND id<>:pid");
            $c->execute([':v'=>$v, ':pid'=>$person_id]);
            if ((int)$c->fetchColumn() > 0) {
                throw new RuntimeException('Numri Personal përdoret nga një person tjetër.');
            }
            $q = $pdo->prepare("UPDATE persons SET personal_number=:v WHERE id=:pid");
            $q->execute([':v'=>$v, ':pid'=>$person_id]);
            $dispValue = $v;
        }
        else {
            /* first_name, father_name, last_name, birth_place, phone */
            $v = trim((string)$value);
            $sql = "UPDATE persons SET $field = :v WHERE id=:pid";
            $pdo->prepare($sql)->execute([':v'=>($v===''?null:$v), ':pid'=>$person_id]);
            $dispValue = ($v===''?'—':$v);
        }

        /* Nëse ndryshon emri/atësi/mbiemri → rifresko users.full_name */
        if (in_array($field, ['first_name','father_name','last_name'], true)) {
            $pdo->prepare("
                UPDATE users u
                JOIN persons p ON p.id = u.person_id
                SET u.full_name = CONCAT_WS(' ', p.first_name, NULLIF(p.father_name,''), p.last_name)
                WHERE u.person_id = :pid
            ")->execute([':pid'=>$person_id]);
        }
    }
    elseif (in_array($field, $studentFields, true)) {
        /* ———————— UPDATE te students ———————— */
        if ($field === 'education_level_id') {
            if ($value === '' || $value === null) {
                $pdo->prepare("UPDATE students SET education_level_id=NULL WHERE id=:sid")
                    ->execute([':sid'=>$student_id]);
                $dispValue = ['id'=>null,'code'=>null,'label'=>null];
            } else {
                $eduid = (int)$value;
                $chk = $pdo->prepare("SELECT id, code, label FROM education_levels WHERE id=:id");
                $chk->execute([':id'=>$eduid]);
                $ed = $chk->fetch();
                if (!$ed) throw new RuntimeException('Niveli i arsimit i pavlefshëm.');
                $pdo->prepare("UPDATE students SET education_level_id=:ed WHERE id=:sid")
                    ->execute([':ed'=>$eduid, ':sid'=>$student_id]);
                $dispValue = ['id'=>(int)$ed['id'],'code'=>$ed['code'],'label'=>$ed['label']];
            }
        }
        elseif ($field === 'nr_amze') {
            $v = trim((string)$value);
            if ($v === '') throw new RuntimeException('Nr. i amzës është i detyrueshëm.');
            $c = $pdo->prepare("SELECT COUNT(*) FROM students WHERE nr_amze=:v AND id<>:sid");
            $c->execute([':v'=>$v, ':sid'=>$student_id]);
            if ((int)$c->fetchColumn() > 0) throw new RuntimeException('Nr. i amzës përdoret nga student tjetër.');
            $pdo->prepare("UPDATE students SET nr_amze=:v WHERE id=:sid")->execute([':v'=>$v, ':sid'=>$student_id]);
            $dispValue = $v;
        }
    }
    else {
        throw new RuntimeException('Fushë e panjohur.');
    }

    $pdo->commit();
    echo json_encode(['ok'=>true,'display'=>$dispValue]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
