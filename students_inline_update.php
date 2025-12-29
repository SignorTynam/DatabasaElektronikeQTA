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
$action     = trim((string)($data['action'] ?? '')); // NEW
$student_id = (int)($data['student_id'] ?? 0);
$field      = trim((string)($data['field'] ?? ''));
$value      = $data['value'] ?? null;

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'CSRF token mismatch.']); exit;
}

/* ==============================
   ACTION: check_amze (lookup)
============================== */
if ($action === 'check_amze') {
    $amze = trim((string)($data['nr_amze'] ?? ''));
    if ($amze === '' || !preg_match('/^\d+$/', $amze)) {
        echo json_encode(['ok'=>true,'exists'=>false]); exit;
    }

    $q = $pdo->prepare("
        SELECT
            s.id AS student_id,
            s.nr_amze,
            CONCAT_WS(' ', p.first_name, NULLIF(p.father_name,''), p.last_name) AS full_name,
            p.personal_number,
            p.phone,
            p.birth_date,
            DATE_FORMAT(p.birth_date, '%d-%m-%Y') AS birth_date_dmy
        FROM students s
        JOIN persons p ON p.id = s.person_id
        WHERE s.nr_amze = :amze
        LIMIT 1
    ");
    $q->execute([':amze'=>$amze]);
    $st = $q->fetch(PDO::FETCH_ASSOC);

    if ($st) {
        echo json_encode(['ok'=>true,'exists'=>true,'student'=>$st]); exit;
    }
    echo json_encode(['ok'=>true,'exists'=>false]); exit;
}

/* ==============================
   ACTION: merge_students (opsionale)
   - Bashkon duplikatin (source) te target
============================== */
if ($action === 'merge_students') {
    $source = (int)($data['source_student_id'] ?? 0);
    $target = (int)($data['target_student_id'] ?? 0);

    if ($source <= 0 || $target <= 0 || $source === $target) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Parametra merge të pavlefshëm.']); exit;
    }

    try {
        $pdo->beginTransaction();

        // Siguro që ekzistojnë
        $chk = $pdo->prepare("SELECT 1 FROM students WHERE id=:id");
        $chk->execute([':id'=>$source]);
        if (!$chk->fetchColumn()) throw new RuntimeException('Source student nuk ekziston.');
        $chk->execute([':id'=>$target]);
        if (!$chk->fetchColumn()) throw new RuntimeException('Target student nuk ekziston.');

        // student_course_plans (nëse ekziston)
        try {
            $pdo->prepare("
              INSERT IGNORE INTO student_course_plans (student_id, course_id, status, selected_by)
              SELECT :target, course_id, status, selected_by
              FROM student_course_plans
              WHERE student_id = :source
            ")->execute([':target'=>$target, ':source'=>$source]);
            $pdo->prepare("DELETE FROM student_course_plans WHERE student_id=:source")
                ->execute([':source'=>$source]);
        } catch (Throwable $e) { /* ignore */ }

        // course_group_students (nëse ekziston)
        try {
            $pdo->prepare("
              INSERT IGNORE INTO course_group_students (group_id, student_id)
              SELECT group_id, :target
              FROM course_group_students
              WHERE student_id = :source
            ")->execute([':target'=>$target, ':source'=>$source]);
            $pdo->prepare("DELETE FROM course_group_students WHERE student_id=:source")
                ->execute([':source'=>$source]);
        } catch (Throwable $e) { /* ignore */ }

        // agency_students (nëse ekziston)
        try {
            $pdo->prepare("UPDATE agency_students SET student_id=:target WHERE student_id=:source")
                ->execute([':target'=>$target, ':source'=>$source]);
        } catch (Throwable $e) { /* ignore */ }

        // student_qr_tokens (nëse ekziston)
        try {
            $pdo->prepare("UPDATE student_qr_tokens SET student_id=:target WHERE student_id=:source")
                ->execute([':target'=>$target, ':source'=>$source]);
        } catch (Throwable $e) { /* ignore */ }

        // Në fund: fshi duplikatin (source)
        $pdo->prepare("DELETE FROM students WHERE id=:id")->execute([':id'=>$source]);

        $pdo->commit();
        echo json_encode(['ok'=>true,'target_student_id'=>$target]); exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
    }
}

/* ==============================
   ACTION: link_person_by_pn
   - Lidh këtë AMZË (student_id) me personin ekzistues sipas PN
   - Pastaj kthen të dhënat për UI (autofill)
============================== */
if ($action === 'link_person_by_pn') {
    $sid = (int)($data['student_id'] ?? 0);
    $pn  = trim((string)($data['personal_number'] ?? ''));

    if ($sid <= 0 || $pn === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Parametra të pavlefshëm.']); exit;
    }

    // gjej rolin student
    $studentRoleId = (int)($pdo->query("SELECT id FROM roles WHERE name='student' LIMIT 1")->fetchColumn() ?: 0);
    if ($studentRoleId <= 0) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Roli student mungon.']); exit;
    }

    try {
        $pdo->beginTransaction();

        // studenti aktual (AMZË) + lidhjet e vjetra
        $cur = $pdo->prepare("SELECT id, person_id, user_id, education_level_id FROM students WHERE id=:sid LIMIT 1");
        $cur->execute([':sid'=>$sid]);
        $curRow = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$curRow) throw new RuntimeException('Studenti nuk u gjet.');

        $oldPid = (int)$curRow['person_id'];
        $oldUid = (int)$curRow['user_id'];
        $oldEdu = $curRow['education_level_id'] ?? null;

        // personi ekzistues sipas PN
        $pSel = $pdo->prepare("
            SELECT id, personal_number, first_name, father_name, last_name, birth_date, birth_place, phone, gender_id
            FROM persons
            WHERE personal_number = :pn
            LIMIT 1
        ");
        $pSel->execute([':pn'=>$pn]);
        $p = $pSel->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new RuntimeException('Nuk u gjet person me këtë numër personal.');

        $newPid = (int)$p['id'];

        // gjej / krijo user-in student për këtë person
        $uSel = $pdo->prepare("SELECT id FROM users WHERE role_id=:rid AND person_id=:pid LIMIT 1");
        $uSel->execute([':rid'=>$studentRoleId, ':pid'=>$newPid]);
        $newUid = (int)($uSel->fetchColumn() ?: 0);

        if ($newUid <= 0) {
            $full = trim(($p['first_name'] ?? '').' '.(($p['father_name'] ?? '') ? ($p['father_name'].' ') : '').($p['last_name'] ?? ''));
            $pdo->prepare("INSERT INTO users (role_id, person_id, full_name, email) VALUES (:rid,:pid,:fn,NULL)")
                ->execute([':rid'=>$studentRoleId, ':pid'=>$newPid, ':fn'=>($full!==''?$full:null)]);
            $newUid = (int)$pdo->lastInsertId();

            // krijo credentials nëse s’ka
            $plain = qta_make_initial_password((string)($p['first_name'] ?? ''), $p['birth_date'] ?? null);
            $hash  = password_hash($plain, PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO credentials (user_id, password_hash, last_password_change) VALUES (:uid,:ph,NOW())")
                ->execute([':uid'=>$newUid, ':ph'=>$hash]);
        }

        // Lidh AMZË me personin ekzistues
        $pdo->prepare("UPDATE students SET person_id=:pid, user_id=:uid WHERE id=:sid")
            ->execute([':pid'=>$newPid, ':uid'=>$newUid, ':sid'=>$sid]);

        // Edu: nëse AMZË aktual s’ka edu, merre nga studentët e tjerë të këtij personi (më i fundit)
        $eduId = null;
        $eduQ = $pdo->prepare("
            SELECT education_level_id
            FROM students
            WHERE person_id=:pid AND id<>:sid AND education_level_id IS NOT NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $eduQ->execute([':pid'=>$newPid, ':sid'=>$sid]);
        $eduId = $eduQ->fetchColumn() ?: null;

        if (($oldEdu === null || $oldEdu === '') && $eduId !== null) {
            $pdo->prepare("UPDATE students SET education_level_id=:ed WHERE id=:sid")
                ->execute([':ed'=>(int)$eduId, ':sid'=>$sid]);
        }

        // Cleanup: nëse personi i vjetër (bosh) nuk ka më studentë, fshije (dhe user-in e vjetër nëse s’përdoret)
        if ($oldPid > 0 && $oldPid !== $newPid) {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE person_id=:pid");
            $cnt->execute([':pid'=>$oldPid]);
            $left = (int)$cnt->fetchColumn();

            if ($left === 0) {
                if ($oldUid > 0 && $oldUid !== $newUid) {
                    $cntU = $pdo->prepare("SELECT COUNT(*) FROM students WHERE user_id=:uid");
                    $cntU->execute([':uid'=>$oldUid]);
                    if ((int)$cntU->fetchColumn() === 0) {
                        $pdo->prepare("DELETE FROM users WHERE id=:uid")->execute([':uid'=>$oldUid]);
                    }
                }
                $pdo->prepare("DELETE FROM persons WHERE id=:pid")->execute([':pid'=>$oldPid]);
            }
        }

        $pdo->commit();

        // kthe të dhëna për autofill
        $birthDmy = (!empty($p['birth_date']) ? date('d-m-Y', strtotime((string)$p['birth_date'])) : '—');
        echo json_encode([
            'ok'=>true,
            'person'=>[
                'personal_number'=>$p['personal_number'],
                'first_name'=>$p['first_name'] ?? '',
                'father_name'=>$p['father_name'] ?? '',
                'last_name'=>$p['last_name'] ?? '',
                'birth_date_dmy'=>$birthDmy,
                'birth_place'=>$p['birth_place'] ?? '',
                'phone'=>$p['phone'] ?? '',
                'gender_id'=>(int)($p['gender_id'] ?? 0),
            ],
            'education_level_id'=>($eduId !== null ? (int)$eduId : null),
            'student_id'=>$sid
        ]);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
    }
}

/* ==============================
   Pastaj vazhdon kodi ekzistues i update
============================== */

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
                        $pdo->prepare("UPDATE persons SET personal_number=NULL WHERE id=:pid")
                            ->execute([':pid'=>$person_id]);
                        $dispValue = '—';
                    } else {
                        // nëse PN ekziston te një person tjetër → mos e trajto si gabim toast,
                        // por kthe info që UI të hapë modal dhe të ofrojë "Lidhe AMZË"
                        $ex = $pdo->prepare("
                            SELECT
                            p.id AS person_id,
                            CONCAT_WS(' ', p.first_name, NULLIF(p.father_name,''), p.last_name) AS full_name,
                            p.personal_number,
                            p.phone,
                            p.birth_date,
                            DATE_FORMAT(p.birth_date, '%d-%m-%Y') AS birth_date_dmy
                            FROM persons p
                            WHERE p.personal_number = :pn AND p.id <> :pid
                            LIMIT 1
                        ");
                        $ex->execute([':pn'=>$v, ':pid'=>$person_id]);
                        $exist = $ex->fetch(PDO::FETCH_ASSOC);

                        if ($exist) {
                            $pdo->rollBack();
                            http_response_code(409);
                            echo json_encode([
                                'ok'=>false,
                                'code'=>'PERSONAL_EXISTS',
                                'error'=>'Ky numër personal ekziston. Zgjidh “Lidhe këtë AMZË” për ta përdorur.',
                                'person'=>$exist
                            ]);
                            exit;
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
