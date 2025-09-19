<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

header('Content-Type: application/json; charset=UTF-8');

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* Guard: vetëm admin ose editor i loguar */
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
$me = $userStmt->fetch(PDO::FETCH_ASSOC);

$role = strtolower((string)($me['role_name'] ?? ''));
if (!$me || !in_array($role, ['administrator','editor'], true)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Lejohet vetëm për administrator ose editor.']); exit;
}

/* Enforce EDIT MODE */
$EDIT_MODE = (bool)($_SESSION['edit_mode'] ?? false);
if (!$EDIT_MODE) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Edit Mode është OFF. Aktivizo për të bërë ndryshime.']); exit;
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
$user_id = (int)$row['user_id'];

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

/* Helper: [Emri].[VitiLindjes], bosh → User.0000 */
function qta_make_initial_password(string $first_name, ?string $birth_date): string {
    $fname = trim(preg_replace('/\s+/', '', $first_name));
    $year  = '0000';
    if ($birth_date && preg_match('/^(\d{4})-/', $birth_date, $m)) { $year = $m[1]; }
    return ($fname === '' ? 'User' : $fname) . '.' . $year;
}

/* Helper: kthe YYYY-MM-DD -> DD-MM-YYYY për UI */
function fmt_dMY(?string $iso): string {
    if (!$iso) return '—';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return $iso;
    $ts = strtotime($iso);
    return $ts ? date('d-m-Y', $ts) : '—';
}

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

            // Prano si: yyyy-mm-dd (preferuar nga JS), ose dd-mm-yyyy (nëse vjen direkt)
            if ($v !== '') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    $iso = $v;
                } elseif (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $v, $m)) {
                    $dd = str_pad($m[1],2,'0',STR_PAD_LEFT);
                    $mm = str_pad($m[2],2,'0',STR_PAD_LEFT);
                    $yy = $m[3];
                    $iso = "{$yy}-{$mm}-{$dd}";
                } else {
                    throw new RuntimeException('Formati i datës duhet të jetë DD-MM-YYYY.');
                }
            } else {
                $iso = null;
            }

            $q = $pdo->prepare("UPDATE persons SET birth_date=:v WHERE id=:pid");
            $q->execute([':v'=>$iso, ':pid'=>$person_id]);
            $dispValue = $iso ? fmt_dMY($iso) : '—';
        }
        elseif ($field === 'personal_number') {
            $v = trim((string)$value);
            if ($v === '') {
                // Lejo bosh sipas skemës së re (NULL)
                $pdo->prepare("UPDATE persons SET personal_number=NULL WHERE id=:pid")
                    ->execute([':pid'=>$person_id]);
                $dispValue = '—';
            } else {
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
            $plannedCourseId = (int)($data['planned_course_id'] ?? 0);
            if ($plannedCourseId > 0) {
                $ok = $pdo->prepare("SELECT 1 FROM courses WHERE id=:id");
                $ok->execute([':id'=>$plannedCourseId]);
                if ($ok->fetchColumn()) {
                    $pdo->prepare("
                    INSERT INTO student_course_plans (student_id, course_id, status, selected_by)
                    VALUES (:sid, :cid, 'planned', :uid)
                    ON DUPLICATE KEY UPDATE status='planned', selected_by=VALUES(selected_by)
                    ")->execute([':sid'=>$student_id, ':cid'=>$plannedCourseId, ':uid'=>$_SESSION['user_id'] ?? null]);
                }
            }
        }
    }
    else {
        throw new RuntimeException('Fushë e panjohur.');
    }

    /* ==== RESET PASSWORD pas çdo ndryshimi ==== */
    $pNow = $pdo->prepare("SELECT COALESCE(NULLIF(first_name,''),'') AS fn, birth_date FROM persons WHERE id=:pid");
    $pNow->execute([':pid'=>$person_id]);
    $pers = $pNow->fetch(PDO::FETCH_ASSOC) ?: ['fn'=>'','birth_date'=>null];

    $plain = qta_make_initial_password((string)$pers['fn'], $pers['birth_date'] ?? null);
    $hash  = password_hash($plain, PASSWORD_BCRYPT);

    // Siguro/ruaj në credentials
    $cSel = $pdo->prepare("SELECT 1 FROM credentials WHERE user_id=:uid");
    $cSel->execute([':uid'=>$user_id]);
    if ($cSel->fetchColumn()) {
        $pdo->prepare("UPDATE credentials SET password_hash=:ph, last_password_change=NOW() WHERE user_id=:uid")
            ->execute([':ph'=>$hash, ':uid'=>$user_id]);
    } else {
        $pdo->prepare("INSERT INTO credentials (user_id, password_hash, last_password_change) VALUES (:uid,:ph,NOW())")
            ->execute([':uid'=>$user_id, ':ph'=>$hash]);
    }

    $pdo->commit();
    echo json_encode(['ok'=>true,'display'=>$dispValue]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
