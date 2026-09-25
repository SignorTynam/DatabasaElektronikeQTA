<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* ------------------------------
   Guard: admin OSE editor i loguar
------------------------------- */
if (!isset($_SESSION['user_id'])) {
    header('Location: selectProfile.php'); exit;
}

$userStmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, r.name AS role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id = :uid
    LIMIT 1
");
$userStmt->execute([':uid' => $_SESSION['user_id']]);
$currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);

$role = strtolower((string)($currentUser['role_name'] ?? ''));
if (!$currentUser || !in_array($role, ['administrator','editor'], true)) {
    header('Location: selectProfile.php'); exit;
}

/* ------------------------------
   EDIT MODE toggle (persistohet në session)
------------------------------- */
if (isset($_GET['edit'])) {
    $_SESSION['edit_mode'] = filter_var($_GET['edit'], FILTER_VALIDATE_BOOLEAN);
    // Heq parametër 'edit' nga URL duke bërë redirect në të njëjtën faqe pa të
    $qs = $_GET; unset($qs['edit']);
    $url = 'students.php' . (empty($qs) ? '' : ('?' . http_build_query($qs)));
    header("Location: $url"); exit;
}
$EDIT_MODE = (bool)($_SESSION['edit_mode'] ?? false);

/* ------------------------------
   CSRF & Flash helpers
------------------------------- */
function flash(string $key, ?string $msg=null) {
    if ($msg === null) {
        if (!empty($_SESSION['flash'][$key])) { $m = $_SESSION['flash'][$key]; unset($_SESSION['flash'][$key]); return $m; }
        return null;
    }
    $_SESSION['flash'][$key] = $msg;
}
function require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(400); exit('Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.');
        }
    }
}
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

/* ------------------------------
   Role & lookup data
------------------------------- */
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$studentRoleId = null;
foreach ($roles as $r) if ($r['name'] === 'student') { $studentRoleId = (int)$r['id']; break; }
if ($studentRoleId === null) { exit('Konfigurim i mangët: roli "student" mungon në tabelën roles.'); }

$eduLevels = $pdo->query("SELECT id, code, label FROM education_levels ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$genders   = $pdo->query("SELECT id, code, label FROM genders ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$maleId = null; foreach ($genders as $g) { if ($g['code']==='M') { $maleId = (int)$g['id']; break; } }

/* Kurse (modulet) për zgjedhje */
try {
    $courses = $pdo->query("SELECT id, name FROM courses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $courses = [];
}

/* ------------------------------
   AJAX: Autoplotësim sipas Numrit Personal (opsional)
------------------------------- */
if (($_SERVER['REQUEST_METHOD'] === 'GET') && isset($_GET['action']) && $_GET['action']==='lookup_person') {
    header('Content-Type: application/json; charset=UTF-8');
    $pn = trim((string)($_GET['personal_number'] ?? ''));
    if ($pn === '') { echo json_encode(['ok'=>false,'error'=>'Numri personal mungon.']); exit; }

    $p = $pdo->prepare("
        SELECT p.id, p.first_name, p.father_name, p.last_name, p.birth_date, p.birth_place, p.phone, p.gender_id
        FROM persons p
        WHERE p.personal_number = :pn
        LIMIT 1
    ");
    $p->execute([':pn'=>$pn]);
    $row = $p->fetch(PDO::FETCH_ASSOC);

    $edu_id = null;
    if ($row) {
        $q = $pdo->prepare("SELECT education_level_id FROM students WHERE person_id=:pid ORDER BY id DESC LIMIT 1");
        $q->execute([':pid'=>$row['id']]);
        $edu_id = $q->fetchColumn() ?: null;
    }
    echo json_encode(['ok'=>true,'person'=>$row,'education_level_id'=>$edu_id]);
    exit;
}

/* ------------------------------
   Helpers për fjalëkalimin dhe datën
------------------------------- */
function make_initial_password(string $first_name, ?string $birth_date): string {
    $fname = trim($first_name);
    $fname = preg_replace('/\s+/', '', $fname);
    $year  = '0000';
    if ($birth_date && preg_match('/^(\d{4})-/', $birth_date, $m)) { $year = $m[1]; }
    return ($fname === '' ? 'User' : $fname) . '.' . $year;
}

/* ------------------------------
   Veprime POST: Create & Delete
------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // lexo raw JSON nëse vjen nga fetch()
    $raw = file_get_contents('php://input');
    $asJson = false;
    $post = $_POST;
    if (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) {
        $tmp = json_decode($raw ?: '[]', true);
        if (is_array($tmp)) { $post = $tmp; $asJson = true; }
    }
    // CSRF
    if ($asJson) {
        $tok = (string)($post['csrf'] ?? '');
        if (empty($tok) || !hash_equals($_SESSION['csrf_token'], $tok)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok'=>false,'error'=>'Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.']); exit;
        }
    } else {
        require_csrf();
    }

    $action = (string)($post['action'] ?? '');

    try {
        /* --------- CREATE STUDENT --------- */
        if ($action === 'create_student') {
            if (!$EDIT_MODE) { throw new RuntimeException('Ndryshimet janë të mbyllura. Shtyp "Lejo ndryshimet" dhe provo sërish.'); }

            $personal_number    = trim($post['personal_number'] ?? '');
            $nr_amze            = trim($post['nr_amze'] ?? '');
            $first_name         = trim($post['first_name'] ?? '');
            $father_name        = trim($post['father_name'] ?? '');
            $last_name          = trim($post['last_name'] ?? '');
            $birth_date         = trim($post['birth_date'] ?? '');
            $birth_place        = trim($post['birth_place'] ?? '');
            $phone              = trim($post['phone'] ?? '');
            $gender_id          = (int)($post['gender_id'] ?? 0);
            $education_level_id = (int)($post['education_level_id'] ?? 0);
            $planned_course_id  = (int)($post['planned_course_id'] ?? 0);

            if ($nr_amze === '') throw new RuntimeException('Nr. i amzës është i detyrueshëm.');

            $q1 = $pdo->prepare("SELECT COUNT(*) FROM students WHERE nr_amze = :x");
            $q1->execute([':x'=>$nr_amze]);
            if ((int)$q1->fetchColumn() > 0) throw new RuntimeException('Nr. i amzës ekziston tashmë.');

            if ($gender_id <= 0 && $maleId) { $gender_id = $maleId; }

            if ($birth_date !== '') {
                if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $birth_date)) {
                    [$dd,$mm,$yy] = explode('-', $birth_date);
                    $birth_date = sprintf('%04d-%02d-%02d', (int)$yy, (int)$mm, (int)$dd);
                } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
                    throw new RuntimeException('Datëlindja duhet në formatin DD-MM-YYYY.');
                }
            }

            $pdo->beginTransaction();

            // PERSON
            $personId = 0; $person = null;
            if ($personal_number !== '') {
                $pSel = $pdo->prepare("SELECT id, first_name, father_name, last_name, birth_date, birth_place, phone, gender_id FROM persons WHERE personal_number = :pn LIMIT 1");
                $pSel->execute([':pn'=>$personal_number]);
                $person = $pSel->fetch(PDO::FETCH_ASSOC);
                $personId = (int)($person['id'] ?? 0);
            }

            if ($personId === 0) {
                $pIns = $pdo->prepare("
                    INSERT INTO persons (personal_number, first_name, father_name, last_name, birth_date, birth_place, phone, gender_id)
                    VALUES (:pn, :fn, :fat, :ln, :bd, :bp, :ph, :gid)
                ");
                $pIns->execute([
                    ':pn'=>($personal_number !== '' ? $personal_number : null),
                    ':fn'=>($first_name !== '' ? $first_name : null),
                    ':fat'=>($father_name !== '' ? $father_name : null),
                    ':ln'=>($last_name !== '' ? $last_name : null),
                    ':bd'=>($birth_date !== '' ? $birth_date : null),
                    ':bp'=>($birth_place !== '' ? $birth_place : null),
                    ':ph'=>($phone !== '' ? $phone : null),
                    ':gid'=>($gender_id > 0 ? $gender_id : $maleId)
                ]);
                $personId = (int)$pdo->lastInsertId();
                $person   = [
                    'id'=>$personId, 'first_name'=>$first_name ?: '', 'father_name'=>$father_name ?: '', 'last_name'=>$last_name ?: '',
                    'birth_date'=>($birth_date !== '' ? $birth_date : null), 'birth_place'=>$birth_place ?: null,
                    'phone'=>$phone ?: null, 'gender_id'=>($gender_id > 0 ? $gender_id : $maleId),
                ];
            } else {
                $pUpd = $pdo->prepare("
                    UPDATE persons SET
                        first_name  = COALESCE(NULLIF(:fn,''), first_name),
                        father_name = COALESCE(NULLIF(:fat,''), father_name),
                        last_name   = COALESCE(NULLIF(:ln,''), last_name),
                        birth_date  = COALESCE(NULLIF(:bd,''), birth_date),
                        birth_place = COALESCE(NULLIF(:bp,''), birth_place),
                        phone       = COALESCE(NULLIF(:ph,''), phone),
                        gender_id   = COALESCE(:gid, gender_id)
                    WHERE id = :pid
                ");
                $pUpd->execute([
                    ':fn'=>$first_name, ':fat'=>$father_name, ':ln'=>$last_name,
                    ':bd'=>$birth_date, ':bp'=>$birth_place, ':ph'=>$phone,
                    ':gid'=>($gender_id > 0 ? $gender_id : null), ':pid'=>$personId
                ]);
                $pSel2 = $pdo->prepare("SELECT id, first_name, father_name, last_name, birth_date, birth_place, phone, gender_id FROM persons WHERE id=:pid");
                $pSel2->execute([':pid'=>$personId]);
                $person = $pSel2->fetch(PDO::FETCH_ASSOC);
            }

            // USER (rol student)
            $uSel = $pdo->prepare("SELECT id FROM users WHERE role_id = :rid AND person_id = :pid LIMIT 1");
            $uSel->execute([':rid'=>$studentRoleId, ':pid'=>$personId]);
            $userId = (int)($uSel->fetchColumn() ?: 0);

            $full = trim(($person['first_name'] ?? '').' '.(($person['father_name'] ?? '') ? ($person['father_name'].' ') : '').($person['last_name'] ?? ''));

            if ($userId === 0) {
                $uIns = $pdo->prepare("INSERT INTO users (role_id, person_id, full_name, email) VALUES (:rid, :pid, :fn, NULL)");
                $uIns->execute([':rid'=>$studentRoleId, ':pid'=>$personId, ':fn'=>$full !== '' ? $full : null]);
                $userId = (int)$pdo->lastInsertId();

                $initialPassword = make_initial_password($person['first_name'] ?? $first_name, $person['birth_date'] ?? $birth_date);
                $hash = password_hash($initialPassword, PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO credentials (user_id, password_hash, last_password_change) VALUES (:uid, :ph, NOW())")
                    ->execute([':uid'=>$userId, ':ph'=>$hash]);
            } else {
                $pdo->prepare("UPDATE users SET full_name=:fn WHERE id=:uid")
                    ->execute([':fn'=>($full !== '' ? $full : null), ':uid'=>$userId]);

                $cSel = $pdo->prepare("SELECT 1 FROM credentials WHERE user_id=:uid");
                $cSel->execute([':uid'=>$userId]);
                if (!$cSel->fetchColumn()) {
                    $initialPassword = make_initial_password($person['first_name'] ?? $first_name, $person['birth_date'] ?? $birth_date);
                    $hash = password_hash($initialPassword, PASSWORD_BCRYPT);
                    $pdo->prepare("INSERT INTO credentials (user_id, password_hash, last_password_change) VALUES (:uid, :ph, NOW())")
                        ->execute([':uid'=>$userId, ':ph'=>$hash]);
                }
            }

            // STUDENT
            $insStud = $pdo->prepare("
                INSERT INTO students (person_id, user_id, nr_amze, education_level_id)
                VALUES (:pid, :uid, :amz, :edu)
            ");
            $insStud->execute([
                ':pid'=>$personId, ':uid'=>$userId, ':amz'=>$nr_amze,
                ':edu'=>($education_level_id > 0 ? $education_level_id : null)
            ]);
            $newStudentId = (int)$pdo->lastInsertId();

            /*
            * Planifikim moduli (opsional)
            * -----------------------------------------------
            * MOS LEJO: që i njëjti PERSON të marrë përsëri
            * të njëjtin modul (edhe nëse është AMZË tjetër).
            */
            if ($planned_course_id > 0) {

                if ($personId > 0) {
                    // Shiko nëse ky person ka tashmë këtë modul
                    $dup = $pdo->prepare("
                        SELECT 1
                        FROM student_course_plans scp
                        JOIN students s2 ON s2.id = scp.student_id
                        WHERE s2.person_id = :pid
                          AND scp.course_id = :cid
                        LIMIT 1
                    ");
                    $dup->execute([
                        ':pid' => $personId,
                        ':cid' => $planned_course_id,
                    ]);

                    if ($dup->fetchColumn()) {
                        // Ky person ka bërë / po bën këtë modul me një AMZË tjetër
                        throw new RuntimeException(
                            'Ky student ka tashmë një regjistrim për këtë modul (në një AMZË tjetër). '
                            .'Ju lutem zgjidh një modul tjetër ose lëre bosh.'
                        );
                    }
                }

                // Nëse nuk ka conflict, vazhdo si më parë
                $chk = $pdo->prepare("SELECT 1 FROM courses WHERE id=:id");
                $chk->execute([':id'=>$planned_course_id]);
                if ($chk->fetchColumn()) {
                    $pdo->prepare("
                        INSERT INTO student_course_plans (student_id, course_id, status, selected_by)
                        VALUES (:sid, :cid, 'planned', :uid)
                        ON DUPLICATE KEY UPDATE status=VALUES(status), selected_by=VALUES(selected_by)
                    ")->execute([
                        ':sid'=>$newStudentId,
                        ':cid'=>$planned_course_id,
                        ':uid'=>$_SESSION['user_id'] ?? null
                    ]);
                }
            }

            $pdo->commit();

            if ($asJson) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['ok'=>true, 'student_id'=>$newStudentId]); exit;
            }
            flash('ok', 'Kursanti u shtua në regjistër.');
            header('Location: students.php'); exit;
        }

        /* --------- DELETE STUDENT (AMZË) --------- */
        if ($action === 'delete_student') {
            if (!$EDIT_MODE) { throw new RuntimeException('Ndryshimet janë të mbyllura. Shtyp "Lejo ndryshimet" për të fshirë.'); }

            $student_id = (int)($post['student_id'] ?? 0);
            $also_identity = (int)($post['also_delete_identity'] ?? 0) === 1;

            if ($student_id <= 0) throw new RuntimeException('ID e studentit mungon.');

            // lexo info (para fshirjes)
            $sel = $pdo->prepare("
                SELECT s.id, s.nr_amze, s.person_id, s.user_id,
                       p.first_name, p.father_name, p.last_name
                FROM students s
                JOIN persons p ON p.id = s.person_id
                WHERE s.id = :sid
                LIMIT 1
            ");
            $sel->execute([':sid'=>$student_id]);
            $st = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$st) throw new RuntimeException('Studenti nuk u gjet.');

            $pid = (int)$st['person_id'];
            $uid = (int)$st['user_id'];

            $pdo->beginTransaction();

            // fshi vetëm rreshtin e students — FK-të varëse (plans, group_members, agency_students, student_qr_tokens) do të pastrohen me ON DELETE CASCADE
            $del = $pdo->prepare("DELETE FROM students WHERE id=:sid");
            $del->execute([':sid'=>$student_id]);

            $deleted_user = false;
            $deleted_person = false;

            if ($also_identity) {
                // nëse personi nuk ka më studentë të tjerë, fshi user-in dhe më pas person-in
                $cnt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE person_id=:pid");
                $cnt->execute([':pid'=>$pid]);
                $left = (int)$cnt->fetchColumn();

                if ($left === 0) {
                    // fshi user-in (kaskadë te credentials)
                    if ($uid > 0) {
                        $pdo->prepare("DELETE FROM users WHERE id=:uid")->execute([':uid'=>$uid]);
                        $deleted_user = true;
                    }
                    // fshi person-in (kaskadë te person_qr_tokens)
                    if ($pid > 0) {
                        $pdo->prepare("DELETE FROM persons WHERE id=:pid")->execute([':pid'=>$pid]);
                        $deleted_person = true;
                    }
                }
            }

            $pdo->commit();

            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ok'=>true,
                'student_id'=>$student_id,
                'nr_amze'=>$st['nr_amze'],
                'deleted_user'=>$deleted_user,
                'deleted_person'=>$deleted_person
            ]);
            exit;
        }

        // nëse arrihet këtu me action tjetër
        if ($asJson) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok'=>false,'error'=>'Veprim i panjohur.']); exit;
        }
        header('Location: students.php'); exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (isset($asJson) && $asJson) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
        }
        flash('err', $e->getMessage());
        header('Location: students.php'); exit;
    }
}

/* ------------------------------
   Kërkim + Paginim – vetëm studentë, sipas nr_amze (numeric)
------------------------------- */
$q         = trim($_GET['q'] ?? '');
$edu       = trim($_GET['edu'] ?? '');
$incomplete = isset($_GET['incomplete']) && $_GET['incomplete'] === '1'; // NEW
$page      = max(1, (int)($_GET['page'] ?? 1));
$limit     = 20;
$offset    = ($page - 1) * $limit;

$where  = ["u.role_id = :studentRole"];
$params = [':studentRole' => $studentRoleId];

if ($q !== '') {
    $where[] = "(
        p.first_name      LIKE :kw1 OR
        p.father_name     LIKE :kw2 OR
        p.last_name       LIKE :kw3 OR
        s.nr_amze         LIKE :kw4 OR
        p.personal_number LIKE :kw5 OR
        p.phone           LIKE :kw6 OR
        p.birth_place     LIKE :kw7
    )";
    $kw = '%'.$q.'%';
    $params[':kw1'] = $kw;
    $params[':kw2'] = $kw;
    $params[':kw3'] = $kw;
    $params[':kw4'] = $kw;
    $params[':kw5'] = $kw;
    $params[':kw6'] = $kw;
    $params[':kw7'] = $kw;
}
if ($edu !== '') {
    if (ctype_digit($edu)) {
        $where[] = "s.education_level_id = :eduid";
        $params[':eduid'] = (int)$edu;
    } else {
        $where[] = "el.code = :educode";
        $params[':educode'] = $edu;
    }
}

/*
 * Filtro vetëm studentët që kanë TË PAKTËN një fushë bosh
 * (TEL injorohet qëllimisht)
 */
if ($incomplete) {
    $where[] = "(
        p.personal_number IS NULL OR p.personal_number = '' OR
        p.first_name      IS NULL OR p.first_name      = '' OR
        p.father_name     IS NULL OR p.father_name     = '' OR
        p.last_name       IS NULL OR p.last_name       = '' OR
        p.birth_date      IS NULL OR p.birth_date      = '0000-00-00' OR
        p.birth_place     IS NULL OR p.birth_place     = '' OR
        s.education_level_id IS NULL OR
        p.gender_id       IS NULL
    )";
}

$whereSql = 'WHERE '.implode(' AND ', $where);

/* Numri total */
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM students s
    JOIN users u   ON u.id = s.user_id
    JOIN persons p ON p.id = s.person_id
    LEFT JOIN education_levels el ON el.id = s.education_level_id
    $whereSql
");

/* ------------------------------
   Kontroll: AMZË të munguar midis min & max (numerike)
   - Respekton filtrat aktualë (q/edu/incomplete) përmes $whereSql/$params
   - Nuk ndikohet nga paginimi
------------------------------- */
$amzeCheck = [
    'min' => null,
    'max' => null,
    'missing' => [],
    'missing_count' => 0,
    'truncated' => false,
];

try {
    $amzeStmt = $pdo->prepare("
        SELECT CAST(s.nr_amze AS UNSIGNED) AS amze
        FROM students s
        JOIN users u   ON u.id = s.user_id
        JOIN persons p ON p.id = s.person_id
        LEFT JOIN education_levels el ON el.id = s.education_level_id
        $whereSql
          AND s.nr_amze REGEXP '^[0-9]+$'
        ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC
    ");

    foreach ($params as $k => $v) {
        $amzeStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $amzeStmt->execute();

    $amzeList = $amzeStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $amzeList = array_map('intval', $amzeList);

    if (!empty($amzeList)) {
        $minAmze = $amzeList[0];
        $maxAmze = $amzeList[count($amzeList) - 1];

        $missing = [];
        $missingCount = 0;

        // sa numra të munguar të shfaqim në banner (për të mos e bërë faqen “miles”)
        $SHOW_LIMIT = 250;

        $expected = $minAmze;

        foreach ($amzeList as $a) {
            if ($a < $expected) {
                // mbulon raste të rralla duplikimi pas CAST (p.sh. '001' dhe '1')
                continue;
            }

            if ($a > $expected) {
                $gap = $a - $expected;       // sa AMZË mungojnë në këtë interval
                $missingCount += $gap;

                // ruaj vetëm të parat SHOW_LIMIT për t’i shfaqur
                $toStore = min($gap, $SHOW_LIMIT - count($missing));
                for ($i = 0; $i < $toStore; $i++) {
                    $missing[] = $expected + $i;
                }
            }

            $expected = $a + 1;
        }

        $amzeCheck = [
            'min' => $minAmze,
            'max' => $maxAmze,
            'missing' => $missing,
            'missing_count' => $missingCount,
            'truncated' => ($missingCount > $SHOW_LIMIT),
        ];
    }
} catch (Throwable $e) {
    // nëse ndodh ndonjë problem, thjesht mos e shfaq banner-in (pa e prishur faqen)
    $amzeCheck = [
        'min' => null,
        'max' => null,
        'missing' => [],
        'missing_count' => 0,
        'truncated' => false,
    ];
}


$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));

/* Lista – RENDITJE NUMERIKE sipas nr_amze */
$listStmt = $pdo->prepare("
    SELECT
        s.id              AS student_id,
        s.user_id,
        s.nr_amze,
        s.education_level_id AS edu_id,
        u.created_at,

        p.first_name,
        p.father_name,
        p.last_name,
        p.birth_date,
        p.birth_place,
        p.personal_number,
        p.phone,
        p.gender_id,
        g.code  AS gender_code,
        g.label AS gender_label,

        el.code AS edu_code,
        el.label AS edu_label,

        /* Grupi (nëse ekziston) – përdor course_group_students + course_groups + courses */
        (
          SELECT c.name
          FROM course_group_students cgs
          JOIN course_groups cg ON cg.id = cgs.group_id
          JOIN courses c        ON c.id = cg.course_id
          WHERE cgs.student_id = s.id
          ORDER BY cg.start_date DESC, cg.id DESC
          LIMIT 1
        ) AS group_name,
        (
          SELECT cg.start_date
          FROM course_group_students cgs
          JOIN course_groups cg ON cg.id = cgs.group_id
          WHERE cgs.student_id = s.id
          ORDER BY cg.start_date DESC, cg.id DESC
          LIMIT 1
        ) AS group_start_date,
        (
          SELECT cg.end_date
          FROM course_group_students cgs
          JOIN course_groups cg ON cg.id = cgs.group_id
          WHERE cgs.student_id = s.id
          ORDER BY cg.start_date DESC, cg.id DESC
          LIMIT 1
        ) AS group_end_date,


        /* Moduli i planifikuar (nëse nuk ka grup) */
        (
          SELECT c.name
          FROM student_course_plans scp
          JOIN courses c ON c.id = scp.course_id
          WHERE scp.student_id = s.id
          ORDER BY scp.id DESC
          LIMIT 1
        ) AS planned_course_name

    FROM students s
    JOIN users u   ON u.id = s.user_id
    JOIN persons p ON p.id = s.person_id
    LEFT JOIN education_levels el ON el.id = s.education_level_id
    LEFT JOIN genders g           ON g.id = p.gender_id
    $whereSql
    ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
    LIMIT :lim OFFSET :off
");

foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$students = $listStmt->fetchAll(PDO::FETCH_ASSOC);

/* Build toggle URL që ruan q/edu/page */
/* Build toggle URL që ruan q/edu/incomplete/page */
$toggleUrl = 'students.php?' . http_build_query(array_filter([
    'q'          => ($q !== '' ? $q : null),
    'edu'        => ($edu !== '' ? $edu : null),
    'incomplete' => ($incomplete ? '1' : null),
    'page'       => ($page > 1 ? $page : null),
    'edit'       => ($EDIT_MODE ? 'off' : 'on')
]));

$exportBase = 'students_export.php?' . http_build_query(array_filter([
    'csrf'       => $CSRF,
    'q'          => ($q !== '' ? $q : null),
    'edu'        => ($edu !== '' ? $edu : null),
    'incomplete' => ($incomplete ? '1' : null),
    // pagen NUK e dërgojmë që eksporti të marrë gjithçka, jo vetëm faqen aktuale
]));


$pageTitle  = 'Kursantët';
$NAV_ACTIVE = 'users_students';
$HELP_TOPIC = 'students';
$openAdd    = $EDIT_MODE && isset($_GET['add']);
$hasFilters = ($q !== '' || $edu !== '' || $incomplete);
$addHref    = 'students.php?' . http_build_query(['edit' => '1', 'add' => '1']);
$exportAction = 'students_export.php';
$exportFields = ['q' => $q, 'edu' => $edu, 'incomplete' => $incomplete ? '1' : ''];
require __DIR__ . '/../shared/app_head.php';

if ($role === 'administrator') require __DIR__ . '/inc/navbar.php';
elseif ($role === 'editor')    require __DIR__ . '/inc/navbar4.php';
?>

<main class="app-main is-wide" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title">Kursantët</h1>
      <p class="page-lead">Të gjithë kursantët e regjistruar. Kërko, hap kartelën ose ndrysho të dhënat direkt në tabelë.</p>
    </div>
    <div class="page-actions">
      <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>
      <?= qta_help_button() ?>
      <?php if ($EDIT_MODE): ?>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addStudentModal">
          <i class="bi bi-person-plus" aria-hidden="true"></i>Shto kursant
        </button>
      <?php else: ?>
        <a class="btn btn-primary" href="<?= h($addHref) ?>">
          <i class="bi bi-person-plus" aria-hidden="true"></i>Shto kursant
        </a>
      <?php endif; ?>
    </div>
  </header>

  <form class="filters" method="get" action="students.php" role="search" aria-label="Kërko kursantë">
    <div class="filter-field is-grow">
      <label class="form-label" for="fQ">Kërko</label>
      <div class="search-field">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="form-control" id="fQ" type="search" name="q" value="<?= h($q) ?>"
               placeholder="Emri, numri personal, nr. i amzës, telefoni ose vendlindja">
      </div>
    </div>
    <div class="filter-field">
      <label class="form-label" for="fEdu">Arsimi</label>
      <select class="form-select" id="fEdu" name="edu">
        <option value="">Të gjitha nivelet</option>
        <?php foreach ($eduLevels as $el): $val = (string)$el['id']; ?>
          <option value="<?= h($val) ?>" <?= $edu === $val ? 'selected' : '' ?>><?= h($el['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-field filter-check">
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" id="onlyIncomplete" name="incomplete" value="1" <?= $incomplete ? 'checked' : '' ?>>
        <label class="form-check-label" for="onlyIncomplete">Vetëm me të dhëna që mungojnë</label>
      </div>
    </div>
    <div class="filter-actions">
      <?php if ($hasFilters): ?>
        <a class="btn btn-ghost" href="students.php"><i class="bi bi-x-lg" aria-hidden="true"></i>Pastro kërkimin</a>
      <?php endif; ?>
      <button class="btn btn-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i>Kërko</button>
    </div>
  </form>

  <?php if ($amzeCheck['min'] !== null && $amzeCheck['max'] !== null && $amzeCheck['missing_count'] > 0): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="status">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      <div>
        <span class="alert-title">Mungojnë <?= (int)$amzeCheck['missing_count'] ?> numra amze midis <?= (int)$amzeCheck['min'] ?> dhe <?= (int)$amzeCheck['max'] ?></span>
        Mund të jenë regjistrime të fshira ose numra të kapërcyer gabimisht.
        <?php if (!empty($amzeCheck['missing'])): ?>
          <details class="mt-1">
            <summary>Shiko numrat që mungojnë</summary>
            <p class="mb-0 mt-1 code"><?= h(implode(', ', $amzeCheck['missing'])) ?><?= $amzeCheck['truncated'] ? ' …' : '' ?></p>
          </details>
        <?php endif; ?>
      </div>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Mbyll njoftimin"></button>
    </div>
  <?php endif; ?>

  <section class="section" aria-labelledby="listTitle">
    <div class="section-head">
      <h2 class="section-title" id="listTitle">
        <?= $hasFilters ? 'Rezultatet' : 'Të gjithë kursantët' ?>
        <span class="count"><?= number_format($total, 0, ',', '.') ?></span>
      </h2>
      <?php require __DIR__ . '/../shared/partials/export_menu.php'; ?>
    </div>

    <?php if ($incomplete): ?>
      <div class="notice mb-3">
        <i class="bi bi-funnel" aria-hidden="true"></i>
        <span>Po shfaqen vetëm kursantët që kanë mangësi: numër personal, emër, atësi, mbiemër, datëlindje, vendlindje, arsim ose gjini.</span>
      </div>
    <?php endif; ?>

    <?php
      $tfTarget = '#studentsTable';
      $tfPlaceholder = 'Filtro këtë faqe — emër, amzë, vendlindje, modul…';
      $tfChips = [['label' => 'Pa modul', 'match' => '—']];
      $tfNoun = 'kursantë';
      require __DIR__ . '/../shared/partials/table_filter.php';
    ?>

    <div class="table-responsive">
      <table class="table table-freeze" id="studentsTable" data-sortable>
        <thead>
          <tr>
            <th scope="col" class="nowrap" data-sort="num">Nr. i amzës</th>
            <th scope="col" data-sort="text">Emri</th>
            <th scope="col" data-sort="text">Atësia</th>
            <th scope="col" data-sort="text">Mbiemri</th>
            <th scope="col" class="nowrap" data-sort="text">Nr. personal</th>
            <th scope="col" class="nowrap" data-sort="date">Datëlindja</th>
            <th scope="col" class="col-medium" data-sort="text">Vendlindja</th>
            <th scope="col" data-sort="text">Arsimi</th>
            <th scope="col" class="col-wide" data-sort="text">Moduli</th>
            <th scope="col" data-sort="text">Gjinia</th>
            <th scope="col" data-sort="text">Telefoni</th>
            <th scope="col" class="col-actions" data-sort="none"><span class="visually-hidden">Veprime</span></th>
          </tr>
        </thead>
        <tbody>
        <?php if ($students): ?>
          <?php foreach ($students as $s):
            $sid = (int)$s['student_id'];
            $fullName = trim(($s['first_name'] ?: '').' '.($s['father_name'] ?: '').' '.($s['last_name'] ?: ''));
            $moduleName = $s['group_name'] ?: $s['planned_course_name'] ?: '—';
            $from = qta_date($s['group_start_date'] ?? null, '');
            $to   = qta_date($s['group_end_date'] ?? null, '');
            $moduleDates = trim($from . ($from !== '' && $to !== '' ? ' – ' : '') . $to);
          ?>
            <tr id="row-<?= $sid ?>">
              <td class="cell" data-id="<?= $sid ?>" data-field="nr_amze">
                <span class="editable id-code" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= h((string)$s['nr_amze']) ?></span>
              </td>
              <td class="cell" data-id="<?= $sid ?>" data-field="first_name">
                <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= h($s['first_name'] ?: '—') ?></span>
              </td>
              <td class="cell" data-id="<?= $sid ?>" data-field="father_name">
                <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= h($s['father_name'] ?: '—') ?></span>
              </td>
              <td class="cell" data-id="<?= $sid ?>" data-field="last_name">
                <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= h($s['last_name'] ?: '—') ?></span>
              </td>
              <td class="cell nowrap" data-id="<?= $sid ?>" data-field="personal_number">
                <span class="editable id-code" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= h($s['personal_number'] ?: '—') ?></span>
              </td>
              <td class="cell nowrap" data-id="<?= $sid ?>" data-field="birth_date" title="Formati: dd.mm.vvvv">
                <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= h(qta_date($s['birth_date'])) ?></span>
              </td>
              <td class="cell" data-id="<?= $sid ?>" data-field="birth_place">
                <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= h($s['birth_place'] ?: '—') ?></span>
              </td>
              <td class="cell" data-id="<?= $sid ?>" data-field="education_level_id">
                <select class="form-select form-select-sm inline-select" aria-label="Arsimi i <?= h($fullName ?: 'kursantit') ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                  <option value="">— Zgjidh —</option>
                  <?php foreach ($eduLevels as $el): ?>
                    <option value="<?= (int)$el['id'] ?>" <?= ((int)$s['edu_id'] === (int)$el['id']) ? 'selected' : '' ?>><?= h($el['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="col-wide">
                <span><?= h($moduleName) ?></span>
                <?php if ($moduleDates !== ''): ?><span class="cell-sub"><?= h($moduleDates) ?></span><?php endif; ?>
              </td>
              <td class="cell" data-id="<?= $sid ?>" data-field="gender_id">
                <select class="form-select form-select-sm inline-select" aria-label="Gjinia e <?= h($fullName ?: 'kursantit') ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                  <?php foreach ($genders as $g): ?>
                    <option value="<?= (int)$g['id'] ?>" <?= ((int)$s['gender_id'] === (int)$g['id']) ? 'selected' : '' ?>><?= h($g['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="cell nowrap" data-id="<?= $sid ?>" data-field="phone">
                <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= h($s['phone'] ?: '—') ?></span>
              </td>
              <td class="col-actions">
                <span class="row-actions">
                  <a class="btn btn-ghost btn-sm" href="student_card.php?sid=<?= $sid ?>" title="Hap kartelën e <?= h($fullName ?: 'kursantit') ?>">
                    <i class="bi bi-person-vcard" aria-hidden="true"></i>Kartela
                  </a>
                  <?php if ($EDIT_MODE): ?>
                    <button type="button" class="btn btn-ghost btn-sm btn-icon btn-delete text-danger"
                            aria-label="Fshi regjistrimin <?= h((string)$s['nr_amze']) ?>" title="Fshi këtë regjistrim"
                            data-sid="<?= $sid ?>" data-amze="<?= h((string)$s['nr_amze']) ?>" data-name="<?= h($fullName ?: '—') ?>">
                      <i class="bi bi-trash" aria-hidden="true"></i>
                    </button>
                  <?php endif; ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="12" class="table-empty">
            <?= $hasFilters ? 'Asnjë kursant nuk përputhet me kërkimin. Provo një emër tjetër ose shtyp "Pastro kërkimin".' : 'Ende nuk ka kursantë. Shtyp "Shto kursant" për të regjistruar të parin.' ?>
          </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1):
      $base = 'students.php?' . http_build_query(array_filter([
        'q'          => $q !== '' ? $q : null,
        'edu'        => $edu !== '' ? $edu : null,
        'incomplete' => $incomplete ? '1' : null,
      ]));
      $sep = str_contains($base, '=') ? '&' : '';
    ?>
      <nav class="pager" aria-label="Faqet e listës">
        <span>Faqja <?= $page ?> nga <?= $totalPages ?> · <?= h(qta_plural($total, 'kursant', 'kursantë')) ?></span>
        <ul class="pagination">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h($base . $sep . 'page=' . max(1, $page - 1)) ?>" aria-label="Faqja e mëparshme"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
          </li>
          <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= h($base . $sep . 'page=' . $p) ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>><?= $p ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h($base . $sep . 'page=' . min($totalPages, $page + 1)) ?>" aria-label="Faqja tjetër"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>
  </section>
</main>

<!-- Dialog: Shto kursant -->
<div class="modal fade" id="addStudentModal" tabindex="-1" aria-labelledby="addStudentTitle" aria-hidden="true"<?= $openAdd ? ' data-open-on-load="add"' : '' ?>>
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" method="post" data-loading>
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="create_student">
      <div class="modal-header">
        <h2 class="modal-title" id="addStudentTitle"><i class="bi bi-person-plus" aria-hidden="true"></i>Shto një kursant</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted">Mjafton numri i amzës; të tjerat mund t'i plotësosh edhe më vonë. Nëse shkruan numrin personal të dikujt që ekziston, të dhënat plotësohen vetë.</p>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="amzeInput">Nr. i amzës <span class="req" aria-hidden="true">*</span></label>
            <input type="text" name="nr_amze" id="amzeInput" class="form-control input-code" required autocomplete="off">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="pnInput">Numri personal <span class="optional">(nëse e ke)</span></label>
            <input type="text" name="personal_number" id="pnInput" class="form-control input-code" placeholder="p.sh. J75010110A" autocomplete="off">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="fnInput">Emri</label>
            <input type="text" name="first_name" id="fnInput" class="form-control" autocomplete="off">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="fatInput">Atësia</label>
            <input type="text" name="father_name" id="fatInput" class="form-control" autocomplete="off">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="lnInput">Mbiemri</label>
            <input type="text" name="last_name" id="lnInput" class="form-control" autocomplete="off">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="bdInput">Datëlindja</label>
            <input type="text" name="birth_date" id="bdInput" class="form-control" placeholder="dd.mm.vvvv" inputmode="numeric" autocomplete="off" aria-describedby="bdHelp">
            <p class="form-text" id="bdHelp">P.sh. 05.03.1990</p>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="bpInput">Vendlindja</label>
            <input type="text" name="birth_place" id="bpInput" class="form-control" autocomplete="off">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="phInput">Telefoni</label>
            <input type="tel" name="phone" id="phInput" class="form-control" placeholder="+355 6…" autocomplete="off">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="genderSelect">Gjinia</label>
            <select name="gender_id" id="genderSelect" class="form-select">
              <?php foreach ($genders as $g): ?>
                <option value="<?= (int)$g['id'] ?>" <?= ($g['code']==='M'?'selected':'') ?>><?= h($g['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="eduSelect">Arsimi</label>
            <select name="education_level_id" id="eduSelect" class="form-select">
              <option value="">— Zgjidh —</option>
              <?php foreach ($eduLevels as $el): ?>
                <option value="<?= (int)$el['id'] ?>"><?= h($el['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="planSelect">Moduli <span class="optional">(nëse dihet)</span></label>
            <select name="planned_course_id" id="planSelect" class="form-select" aria-describedby="planHelp">
              <option value="">— Më vonë —</option>
              <?php foreach ($courses as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="form-text" id="planHelp">Grupi caktohet më vonë.</p>
          </div>
        </div>
        <div class="tip mt-4">
          <i class="bi bi-key" aria-hidden="true"></i>
          <span>Kursanti merr vetë një llogari. Fjalëkalimi i parë është <b>Emri.VitiILindjes</b>, p.sh. <span class="code">Ardit.1998</span> — jepjani kursantit.</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>>
          <i class="bi bi-check-lg" aria-hidden="true"></i>Ruaj kursantin
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Dialog: moduli pas ndryshimit të numrit të amzës -->
<div class="modal fade" id="pickCourseModal" tabindex="-1" aria-labelledby="pickCourseTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="pickCourseTitle"><i class="bi bi-book" aria-hidden="true"></i>Cili modul është për këtë amzë?</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <label class="form-label" for="pickCourseSelect">Moduli</label>
        <select id="pickCourseSelect" class="form-select">
          <option value="">— Nuk dua të zgjedh tani —</option>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="form-text">Moduli vetëm planifikohet; grupi caktohet më vonë.</p>
      </div>
      <div class="modal-footer">
        <button type="button" id="btnSkipCourse" class="btn btn-secondary" data-bs-dismiss="modal">Më vonë</button>
        <button type="button" id="btnSaveCourse" class="btn btn-primary">Ruaj</button>
      </div>
    </div>
  </div>
</div>

<!-- Dialog: fshirja e regjistrimit -->
<div class="modal fade" id="deleteStudentModal" tabindex="-1" aria-labelledby="deleteStudentTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body pt-4">
        <span class="confirm-icon is-danger"><i class="bi bi-trash" aria-hidden="true"></i></span>
        <h2 class="modal-title mb-2" id="deleteStudentTitle">Të fshihet ky regjistrim?</h2>
        <dl class="kv mb-3">
          <dt>Kursanti</dt><dd id="delName">—</dd>
          <dt>Nr. i amzës</dt><dd class="code" id="delAmze">—</dd>
        </dl>
        <p class="mb-2">Bashkë me të fshihen edhe planet e modulit, vendi në grup dhe kodi QR i këtij regjistrimi. <b>Kjo nuk mund të kthehet mbrapsht.</b></p>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="delAlsoIdentity">
          <label class="form-check-label" for="delAlsoIdentity">
            Fshi edhe personin dhe llogarinë e tij <span class="text-muted">(vetëm nëse nuk ka regjistrime të tjera)</span>
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="button" id="btnConfirmDelete" class="btn btn-danger">
          <i class="bi bi-trash" aria-hidden="true"></i>Po, fshije
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Dialog: numri personal ekziston -->
<div class="modal fade" id="pnExistsModal" tabindex="-1" aria-labelledby="pnExistsTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body pt-4">
        <span class="confirm-icon"><i class="bi bi-person-check" aria-hidden="true"></i></span>
        <h2 class="modal-title mb-2" id="pnExistsTitle">Ky numër personal ekziston</h2>
        <p>Numri personal <b class="code" id="pnExistsValue">—</b> i përket këtij personi:</p>
        <div class="panel-sunken mb-3">
          <b id="pnExistsName">—</b>
          <span class="cell-sub">Datëlindja: <span id="pnExistsBD">—</span> · Tel.: <span id="pnExistsPhone">—</span></span>
        </div>
        <p class="text-muted mb-0">Nëse është i njëjti person, lidhe këtë regjistrim me të. Të dhënat plotësohen vetë.</p>
      </div>
      <div class="modal-footer">
        <button type="button" id="btnPnCancel" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="button" id="btnPnLink" class="btn btn-primary">
          <i class="bi bi-link-45deg" aria-hidden="true"></i>Po, lidhe me këtë person
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Dialog: numri i amzës ekziston -->
<div class="modal fade" id="amzeExistsModal" tabindex="-1" aria-labelledby="amzeExistsTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body pt-4">
        <span class="confirm-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span>
        <h2 class="modal-title mb-2" id="amzeExistsTitle">Ky numër amze është i zënë</h2>
        <p>Nr. i amzës <b class="code" id="amzeExistsValue">—</b> i përket një kursanti tjetër:</p>
        <div class="panel-sunken mb-3">
          <b id="amzeExistsName">—</b>
          <span class="cell-sub">Nr. personal: <span class="code" id="amzeExistsPN">—</span> · Datëlindja: <span id="amzeExistsBD">—</span> · Tel.: <span id="amzeExistsPhone">—</span></span>
        </div>
        <p class="text-muted mb-0">Nëse është i njëjti person i regjistruar dy herë, mund t'i bashkosh. Rreshti aktual fshihet dhe mbetet vetëm regjistrimi ekzistues.</p>
      </div>
      <div class="modal-footer">
        <button type="button" id="btnAmzeCancel" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="button" id="btnAmzeOpenExisting" class="btn btn-secondary">
          <i class="bi bi-person-vcard" aria-hidden="true"></i>Hap kartelën e tij
        </button>
        <button type="button" id="btnAmzeMergeDuplicate" class="btn btn-danger">
          <i class="bi bi-intersect" aria-hidden="true"></i>Bashko dhe hiq dublikatën
        </button>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<?php require __DIR__ . '/../shared/partials/download_generation_toast.php'; ?>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const ENDPOINT = 'students_inline_update.php';
const EDIT_MODE = <?= $EDIT_MODE ? 'true' : 'false' ?>;

/* Njoftimet: sistemi i përbashkët (app.js) */
function notify(type, text, opts={}) {
  return window.qtaToast ? window.qtaToast(text, type, opts.title, opts) : null;
}

/* Helpers */
function cleanText(s){ const v=(s||'').replace(/\s+/g,' ').trim(); return (v==='—'?'':v); }
function normalizeDateForServer(str){
  const v=(str||'').trim();
  if (v==='' || v==='—') return '';
  let m=v.match(/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})$/);
  if (m){ const dd=m[1].padStart(2,'0'), mm=m[2].padStart(2,'0'), yy=m[3]; return `${yy}-${mm}-${dd}`; }
  m=v.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
  if (m){ const yy=m[1], mm=m[2].padStart(2,'0'), dd=m[3].padStart(2,'0'); return `${yy}-${mm}-${dd}`; }
  throw new Error('Shkruaje datën si dd.mm.vvvv, p.sh. 05.03.1990.');
}

let amzeExistsState = null;

let pnExistsState = null;

function applyPersonToRow(sid, person, eduId){
  const row = document.getElementById(`row-${sid}`);
  if (!row || !person) return;

  const setText = (field, val) => {
    const el = row.querySelector(`td.cell[data-field="${field}"] .editable`);
    if (el) el.textContent = (val && String(val).trim() !== '' ? val : '—');
  };
  const setSelect = (field, val) => {
    const sel = row.querySelector(`td.cell[data-field="${field}"] select.inline-select`);
    if (sel) sel.value = (val ? String(val) : '');
  };

  setText('personal_number', person.personal_number || '—');
  setText('first_name', person.first_name || '—');
  setText('father_name', person.father_name || '—');
  setText('last_name', person.last_name || '—');
  setText('birth_date', person.birth_date_dmy || '—');
  setText('birth_place', person.birth_place || '—');
  setText('phone', person.phone || '—');

  if (person.gender_id) setSelect('gender_id', person.gender_id);
  if (eduId !== undefined) setSelect('education_level_id', eduId);

  // update dataset.name për delete button (që modal i fshirjes ta shfaqë saktë)
  const fullName = [person.first_name, person.father_name, person.last_name].filter(Boolean).join(' ').replace(/\s+/g,' ').trim();
  const delBtn = row.querySelector('.btn-delete');
  if (delBtn) delBtn.dataset.name = (fullName !== '' ? fullName : '—');
}


async function postJSON(payload){
  const res = await fetch(ENDPOINT, {
    method: 'POST',
    headers: {'Content-Type':'application/json','Accept':'application/json'},
    body: JSON.stringify(payload)
  });
  const json = await res.json().catch(()=>({ok:false,error:'Përgjigje e pavlefshme nga serveri.'}));
  return { res, json };
}

async function checkAmzeExists(amze){
  return postJSON({ csrf: CSRF, action: 'check_amze', nr_amze: amze });
}

function showAmzeExistsModal(existingStudent, pending){
  amzeExistsState = { existingStudent, pending };

  document.getElementById('amzeExistsValue').textContent = pending.newVal || '—';
  document.getElementById('amzeExistsName').textContent  = existingStudent.full_name || '—';
  document.getElementById('amzeExistsPN').textContent    = existingStudent.personal_number || '—';
  document.getElementById('amzeExistsBD').textContent    = existingStudent.birth_date_dmy || '—';
  document.getElementById('amzeExistsPhone').textContent = existingStudent.phone || '—';

  new bootstrap.Modal(document.getElementById('amzeExistsModal')).show();
}



/* Save inline (lejon extra p.sh. planned_course_id) */
async function saveInline(studentId, field, value, cell, displayEl, extra={}){
  if (!EDIT_MODE) return;
  try{
    cell.classList.add('cell-saving');
    const payload = Object.assign({ csrf: CSRF, student_id: studentId, field, value }, extra||{});
    const res = await fetch(ENDPOINT, {
      method: 'POST',
      headers: {'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify(payload)
    });
    const json = await res.json();
    cell.classList.remove('cell-saving');
    if (!json.ok) throw new Error(json.error || 'Gabim i panjohur.');
    if (displayEl && field !== 'education_level_id' && field !== 'gender_id') {
      displayEl.textContent = json.display ?? (value || '—');
    }
    cell.classList.add('cell-ok'); setTimeout(()=>cell.classList.remove('cell-ok'), 800);
    notify('success','Ndryshimi u ruajt.');
  }catch(e){
    console.error(e);
    notify('danger', e.message || 'Ndodhi një gabim.');
    cell.classList.remove('cell-saving');
    cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'), 1200);
  }
}

/* contenteditable events + AMZE hook */
let pendingAmzeChange = null;

document.querySelectorAll('td.cell .editable').forEach(el => {
  let oldVal = el.textContent;
  if (!EDIT_MODE) el.setAttribute('contenteditable','false');

  el.addEventListener('focus', ()=>{ oldVal = el.textContent; });
  el.addEventListener('keydown', ev => { if (ev.key==='Enter'){ ev.preventDefault(); el.blur(); }});
  el.addEventListener('blur', () => {
    if (!EDIT_MODE) return;
    const cell = el.closest('td.cell');
    const field = cell.dataset.field;
    const sid = parseInt(cell.dataset.id, 10);
    let newVal = cleanText(el.textContent);
    if (newVal === cleanText(oldVal)) return;

    if (field === 'birth_date') {
      try { newVal = normalizeDateForServer(newVal); }
      catch(err){ el.textContent = oldVal; notify('danger', err.message || err); return; }
    }

    if (field === 'nr_amze') {
      // ruaj oldVal për revert në rast anulimi
      const oldAmze = cleanText(oldVal);

      // kontrollo fillimisht nëse AMZË ekziston
      (async ()=>{
        const { json } = await checkAmzeExists(newVal);

        if (!json.ok) {
          // nëse lookup dështoi, mos e blloko përdoruesin: kthe vlerën e vjetër
          el.textContent = oldVal;
          notify('danger', json.error || 'Gabim gjatë kontrollit të AMZË-së.');
          return;
        }

        if (json.exists && json.student && parseInt(json.student.student_id,10) !== sid) {
          // AMZË i përket një studenti tjetër → modal konfirmimi
          showAmzeExistsModal(json.student, { sid, field, newVal, cell, el, oldAmze });
          return;
        }

        // AMZË nuk ekziston (ose i njëjti student) → vazhdo me flow-in ekzistues të modulit
        pendingAmzeChange = { sid, field, newVal, cell, el, oldAmze };
        const pickModal = new bootstrap.Modal(document.getElementById('pickCourseModal'));
        const sel = document.getElementById('pickCourseSelect'); if (sel) sel.value = '';
        pickModal.show();
      })();

      return;
    }
        if (field === 'personal_number') {
      const oldPN = cleanText(oldVal);

      (async ()=>{
        try{
          cell.classList.add('cell-saving');

          const { json } = await postJSON({
            csrf: CSRF,
            student_id: sid,
            field: 'personal_number',
            value: newVal
          });

          cell.classList.remove('cell-saving');

          if (json.ok) {
            el.textContent = json.display ?? (newVal || '—');
            cell.classList.add('cell-ok'); setTimeout(()=>cell.classList.remove('cell-ok'), 800);
            notify('success','Ndryshimi u ruajt.');
            return;
          }

          if (json.code === 'PERSONAL_EXISTS' && json.person) {
            // hap modal për lidhje
            pnExistsState = { sid, newVal, cell, el, oldPN, person: json.person };

            document.getElementById('pnExistsValue').textContent = newVal || '—';
            document.getElementById('pnExistsName').textContent  = json.person.full_name || '—';
            document.getElementById('pnExistsBD').textContent    = json.person.birth_date_dmy || '—';
            document.getElementById('pnExistsPhone').textContent = json.person.phone || '—';

            new bootstrap.Modal(document.getElementById('pnExistsModal')).show();
            return;
          }

          throw new Error(json.error || 'Gabim i panjohur.');
        } catch(e){
          cell.classList.remove('cell-saving');
          el.textContent = oldPN || '—';
          notify('danger', e.message || 'Gabim gjatë ruajtjes.');
          cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'), 1200);
        }
      })();

      return;
    }

    saveInline(sid, field, newVal, cell, el);
  });
});

/* select events */
document.querySelectorAll('td.cell select.inline-select').forEach(sel=>{
  if (!EDIT_MODE) sel.setAttribute('disabled','disabled');
  sel.addEventListener('change', ()=>{
    if (!EDIT_MODE) return;
    const cell = sel.closest('td.cell');
    const sid  = parseInt(cell.dataset.id, 10);
    const field= cell.dataset.field;
    saveInline(sid, field, sel.value, cell, null);
  });
});

document.getElementById('btnPnCancel')?.addEventListener('click', ()=>{
  if (!pnExistsState) return;
  const { el, oldPN } = pnExistsState;
  if (el) el.textContent = oldPN || '—';
  pnExistsState = null;
});

document.getElementById('btnPnLink')?.addEventListener('click', async ()=>{
  if (!pnExistsState) return;
  const { sid, newVal } = pnExistsState;

  try{
    const { json } = await postJSON({
      csrf: CSRF,
      action: 'link_person_by_pn',
      student_id: sid,
      personal_number: newVal
    });

    if (!json.ok) throw new Error(json.error || 'Lidhja dështoi.');

    applyPersonToRow(sid, json.person, json.education_level_id);
    bootstrap.Modal.getInstance(document.getElementById('pnExistsModal'))?.hide();
    notify('success', 'Regjistrimi u lidh me personin ekzistues.');
  }catch(e){
    notify('danger', e.message || 'Gabim gjatë lidhjes.');
  }finally{
    pnExistsState = null;
  }
});


/* Autoplotësim nga Numri Personal (modal i shtimit) */
function isoToDmy(iso){ if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return ''; const [y,m,d]=iso.split('-'); return `${d}.${m}.${y}`; }
document.getElementById('pnInput')?.addEventListener('blur', async ()=>{
  const pn = document.getElementById('pnInput').value.trim();
  if (!pn) return;
  try{
    const res = await fetch(`students.php?action=lookup_person&personal_number=${encodeURIComponent(pn)}`, { headers:{'Accept':'application/json'} });
    const json = await res.json();
    if (!json.ok) { notify('danger', json.error || 'Gabim në kërkim.'); return; }
    const p = json.person;
    if (p){
      document.getElementById('fnInput').value  = p.first_name ?? '';
      document.getElementById('fatInput').value = p.father_name ?? '';
      document.getElementById('lnInput').value  = p.last_name ?? '';
      document.getElementById('bdInput').value  = isoToDmy(p.birth_date) || '';
      document.getElementById('bpInput').value  = p.birth_place ?? '';
      document.getElementById('phInput').value  = p.phone ?? '';
      const gsel = document.getElementById('genderSelect'); if (gsel && p.gender_id) gsel.value = String(p.gender_id);
      const esel = document.getElementById('eduSelect');    if (esel && json.education_level_id) esel.value = String(json.education_level_id);
      notify('info','Personi ekziston: të dhënat u plotësuan vetë.');
    } else notify('warning','Nuk u gjet person me këtë numër personal.');
  }catch(e){ console.error(e); notify('danger','Gabim gjatë autoplotësimit.'); }
});

/* DD-MM-YYYY mask */
function maskToDDMMYYYY(input){
  const digits=String(input||'').replace(/\D/g,'').slice(0,8);
  const d=digits.slice(0,2), m=digits.slice(2,4), y=digits.slice(4,8);
  let out=d; if (digits.length>2) out+='.'+m; if (digits.length>4) out+='.'+y; return out;
}
function placeCaretAtEnd(el){
  const range=document.createRange(); range.selectNodeContents(el); range.collapse(false);
  const sel=window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
}
function attachDateMaskContentEditable(el){
  el.addEventListener('input', ()=>{
    const masked = maskToDDMMYYYY(el.textContent);
    if (el.textContent !== masked){ el.textContent = masked; placeCaretAtEnd(el); }
  });
  el.addEventListener('paste', (e)=>{
    e.preventDefault(); const txt=(e.clipboardData||window.clipboardData).getData('text');
    el.textContent = maskToDDMMYYYY(txt); placeCaretAtEnd(el);
  });
}
function attachDateMaskInput(inp){
  const apply=()=>{ inp.value = maskToDDMMYYYY(inp.value); };
  inp.addEventListener('input', apply);
  inp.addEventListener('blur', apply);
  inp.addEventListener('paste', (e)=>{
    e.preventDefault(); const txt=(e.clipboardData||window.clipboardData).getData('text');
    inp.value = maskToDDMMYYYY(txt);
  });
}
document.querySelectorAll('td.cell[data-field="birth_date"] .editable').forEach(attachDateMaskContentEditable);
const bdInputEl = document.getElementById('bdInput'); if (bdInputEl) attachDateMaskInput(bdInputEl);

/* Modal e modulit pas ndryshimit të nr_amze */
const pickCourseSelect = document.getElementById('pickCourseSelect');
document.getElementById('btnSaveCourse')?.addEventListener('click', ()=>{
  if (!pendingAmzeChange) return;
  const { sid, field, newVal, cell, el } = pendingAmzeChange;
  const cid = (pickCourseSelect && pickCourseSelect.value) ? parseInt(pickCourseSelect.value,10) : 0;
  saveInline(sid, field, newVal, cell, el, cid>0 ? { planned_course_id: cid } : {});
  pendingAmzeChange = null;
  bootstrap.Modal.getInstance(document.getElementById('pickCourseModal'))?.hide();
});
document.getElementById('btnSkipCourse')?.addEventListener('click', ()=>{
  if (!pendingAmzeChange) return;
  const { sid, field, newVal, cell, el } = pendingAmzeChange;
  saveInline(sid, field, newVal, cell, el);
  pendingAmzeChange = null;
});

/* Delete flow */
let deleteState = { sid:0, name:'', amze:'' };
document.querySelectorAll('.btn-delete').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    deleteState.sid  = parseInt(btn.dataset.sid, 10);
    deleteState.name = btn.dataset.name || '—';
    deleteState.amze = btn.dataset.amze || '—';
    document.getElementById('delName').textContent = deleteState.name;
    document.getElementById('delAmze').textContent = deleteState.amze;
    document.getElementById('delAlsoIdentity').checked = false;
    new bootstrap.Modal(document.getElementById('deleteStudentModal')).show();
  });
});

document.getElementById('btnAmzeCancel')?.addEventListener('click', ()=>{
  if (!amzeExistsState) return;
  const { pending } = amzeExistsState;
  // rikthe vlerën e vjetër në qelizë
  if (pending?.el) pending.el.textContent = pending.oldAmze || '';
  amzeExistsState = null;
});

document.getElementById('btnAmzeOpenExisting')?.addEventListener('click', ()=>{
  if (!amzeExistsState) return;
  const { existingStudent, pending } = amzeExistsState;

  // kthe vlerën e vjetër në rreshtin aktual (s’ka ndryshim DB)
  if (pending?.el) pending.el.textContent = pending.oldAmze || '';

  // hap kartelën e studentit ekzistues
  window.location.href = `student_card.php?sid=${encodeURIComponent(existingStudent.student_id)}`;
});

document.getElementById('btnAmzeMergeDuplicate')?.addEventListener('click', async ()=>{
  if (!amzeExistsState) return;
  const { existingStudent, pending } = amzeExistsState;

  try{
    const { json } = await postJSON({
      csrf: CSRF,
      action: 'merge_students',
      source_student_id: pending.sid,                 // ky rreshti ku po editoje
      target_student_id: existingStudent.student_id   // studenti “i saktë” ekzistues
    });

    if (!json.ok) throw new Error(json.error || 'Bashkimi dështoi.');

    // hiq rreshtin duplikat nga UI
    document.getElementById(`row-${pending.sid}`)?.remove();

    bootstrap.Modal.getInstance(document.getElementById('amzeExistsModal'))?.hide();
    notify('success', 'U bashkua duplikati. Po hap studentin ekzistues...');
    window.location.href = `student_card.php?sid=${encodeURIComponent(existingStudent.student_id)}`;
  }catch(e){
    notify('danger', e.message || 'Gabim gjatë bashkimit.');
  }finally{
    amzeExistsState = null;
  }
});

document.getElementById('btnConfirmDelete')?.addEventListener('click', async ()=>{
  if (!deleteState.sid) return;
  const also = document.getElementById('delAlsoIdentity').checked ? 1 : 0;
  try{
    const res = await fetch('students.php', {
      method: 'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({
        csrf: CSRF,
        action: 'delete_student',
        student_id: deleteState.sid,
        also_delete_identity: also
      })
    });
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'Fshirja dështoi.');
    // hiq rreshtin
    document.getElementById(`row-${deleteState.sid}`)?.remove();
    bootstrap.Modal.getInstance(document.getElementById('deleteStudentModal'))?.hide();
    notify('success', `Regjistrimi ${deleteState.amze} u fshi${json.deleted_person ? ' bashkë me personin' : ''}.`);
  }catch(e){
    console.error(e);
    notify('danger', e.message || 'Gabim gjatë fshirjes.');
  }
});

/* Flash -> Toast on load */
<?php if ($m = flash('ok')): ?>
document.addEventListener('DOMContentLoaded',()=>notify('success', <?= json_encode($m) ?>));
<?php endif; ?>
<?php if ($m = flash('err')): ?>
document.addEventListener('DOMContentLoaded',()=>notify('danger', <?= json_encode($m) ?>));
<?php endif; ?>
</script>
</body>
</html>
