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

/* Kurset për zgjedhje */
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
                            'Ky person ka tashmë një regjistrim për këtë kurs (me një numër tjetër amze). '
                            .'Zgjidh një kurs tjetër ose lëre bosh.'
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
   Lista: kërkimi, gjendja (çipat), filtrat e rrallë dhe faqosja.
   Serveri filtron gjithë regjistrin; faqja e rifreskon listën pa ringarkim.
------------------------------- */
require_once __DIR__ . '/../shared/students_list.php';

$F = qta_students_filters($_GET);
$counts = qta_students_counts($pdo, $F);
$status = $F['status'];

/* "Pa grup", "Gati për grup" dhe "Pa kurs" hapin punën e caktimit në grup:
   kursi i zgjedhur, grupi dhe zgjedhja e disa kursantëve njëherësh. */
$assignMode = in_array($status, ['no_group', 'ready', 'no_course'], true);
$limit      = $assignMode ? 50 : 20;
$total      = (int)($counts[$status] ?? 0);
$totalPages = max(1, (int)ceil($total / $limit));
$page       = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$offset     = ($page - 1) * $limit;
$hasFilters = ($F['q'] !== '' || $F['edu'] !== '' || $F['course_id'] !== '');

$params = [];
$where = qta_students_where($F, $params);
$listStmt = $pdo->prepare("
    SELECT
        s.id AS student_id, s.user_id, s.nr_amze, s.education_level_id AS edu_id, u.created_at,
        p.first_name, p.father_name, p.last_name, p.birth_date, p.birth_place,
        p.personal_number, p.phone, p.gender_id,
        g.code AS gender_code, g.label AS gender_label,
        el.code AS edu_code, el.label AS edu_label,
        lg.group_id, cg.model AS group_model, cg.start_date AS group_start_date, cg.end_date AS group_end_date,
        gc.name AS group_course_name,
        pp.course_id AS plan_course_id, pc.name AS plan_course_name
    " . qta_students_from_sql() . "
    $where
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

/* Numra amze që mungojnë mes më të voglit dhe më të madhit (vetëm në listën e
   plotë, pa filtra: një kërkim do të krijonte "boshllëqe" që s'janë të vërteta). */
$amzeCheck = ['min' => null, 'max' => null, 'missing' => [], 'missing_count' => 0, 'truncated' => false];
if (!$hasFilters && $status === '') {
    try {
        $amzeList = array_map('intval', $pdo->query("
            SELECT CAST(s.nr_amze AS UNSIGNED)
            FROM students s JOIN users u ON u.id = s.user_id
            WHERE u.role_id = " . (int)$studentRoleId . " AND s.nr_amze REGEXP '^[0-9]+$'
            ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC
        ")->fetchAll(PDO::FETCH_COLUMN, 0));
        if ($amzeList) {
            $SHOW_LIMIT = 250;
            $missing = [];
            $missingCount = 0;
            $expected = $amzeList[0];
            foreach ($amzeList as $a) {
                if ($a < $expected) continue; // p.sh. '001' dhe '1'
                if ($a > $expected) {
                    $gap = $a - $expected;
                    $missingCount += $gap;
                    $toStore = min($gap, $SHOW_LIMIT - count($missing));
                    for ($i = 0; $i < $toStore; $i++) $missing[] = $expected + $i;
                }
                $expected = $a + 1;
            }
            $amzeCheck = [
                'min' => $amzeList[0], 'max' => $amzeList[count($amzeList) - 1],
                'missing' => $missing, 'missing_count' => $missingCount, 'truncated' => $missingCount > $SHOW_LIMIT,
            ];
        }
    } catch (Throwable $e) {
        /* pa njoftim — faqja vazhdon */
    }
}

/* Caktimi në grup: grupet për listat e zgjedhjes. */
$groupsByCourse = [];
$allGroupOptions = '';
$groupOption = null;
if ($assignMode) {
    $groupsMeta = $pdo->query("
        SELECT cg.id, cg.course_id, c.name AS course_name, cg.start_date, cg.end_date, cg.is_completed, cg.model,
               COUNT(cgs.student_id) AS members
        FROM course_groups cg
        JOIN courses c ON c.id = cg.course_id
        LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
        GROUP BY cg.id
        ORDER BY c.name ASC, cg.start_date DESC, cg.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($groupsMeta as $gm) {
        $groupsByCourse[(int)$gm['course_id']][] = $gm;
    }
    /* "Grupi #7 (me orar) · 24.09.2026 – 23.10.2026 · 6/10" */
    $groupOption = static function (array $g): string {
        $members = (int)$g['members'];
        $label = 'Grupi #' . (int)$g['id'] . (($g['model'] ?? '') === 'scheduled' ? ' (me orar)' : '')
            . ' · ' . qta_date($g['start_date']) . ' – ' . qta_date($g['end_date']) . ' · ' . $members . '/10';
        if ($members >= 10) $label .= ' · plot';
        elseif (!empty($g['is_completed'])) $label .= ' · i mbyllur';
        return '<option value="' . (int)$g['id'] . '"' . ($members >= 10 ? ' disabled' : '') . '>' . h($label) . '</option>';
    };
    foreach ($groupsByCourse as $list) {
        $allGroupOptions .= '<optgroup label="' . h((string)$list[0]['course_name']) . '">';
        foreach ($list as $g) $allGroupOptions .= $groupOption($g);
        $allGroupOptions .= '</optgroup>';
    }
}

$pageTitle  = 'Kursantët';
$NAV_ACTIVE = 'users_students';
$HELP_TOPIC = 'students';
$openAdd    = $EDIT_MODE && isset($_GET['add']);
$addHref    = 'students.php?' . http_build_query(['edit' => '1', 'add' => '1']);
$createHref = 'lesson_groups.php?' . http_build_query(['edit' => '1', 'create' => '1']);
$listState  = ['q' => $F['q'], 'status' => $status, 'edu' => $F['edu'], 'course_id' => $F['course_id']];

$exportAction = 'students_export.php';
$exportFields = $listState;

$stateTitles = [
    ''           => 'Të gjithë kursantët',
    'no_group'   => 'Kursantët pa grup',
    'ready'      => 'Kursantët gati për grup',
    'no_course'  => 'Kursantët pa kurs',
    'in_group'   => 'Kursantët në grup',
    'incomplete' => 'Kursantët me të dhëna që mungojnë',
    'no_exam'    => 'Kursantët pa datë provimi',
];
$chips = [];
foreach ([
    ['value' => '',           'label' => 'Të gjithë'],
    ['value' => 'no_group',   'label' => 'Pa grup'],
    ['value' => 'ready',      'label' => 'Gati për grup'],
    ['value' => 'no_course',  'label' => 'Pa kurs'],
    ['value' => 'in_group',   'label' => 'Në grup'],
    ['value' => 'incomplete', 'label' => 'Me të dhëna që mungojnë'],
    ['value' => 'no_exam',    'label' => 'Pa datë provimi', 'optional' => true],
] as $c) {
    $chips[] = $c + ['count' => $counts[$c['value']] ?? 0];
}
$eduOptions = [];
foreach ($eduLevels as $el) $eduOptions[(string)$el['id']] = (string)$el['label'];
if ($F['edu'] !== '' && !ctype_digit($F['edu'])) {
    foreach ($eduLevels as $el) if (strcasecmp((string)$el['code'], $F['edu']) === 0) $eduOptions[$F['edu']] = (string)$el['label'];
}
$courseOptions = [];
foreach ($courses as $c) $courseOptions[(string)$c['id']] = (string)$c['name'];

$LF = [
    'action'      => 'students.php',
    'label'       => 'Kërko kursantë',
    'placeholder' => 'Emër, nr. i amzës, nr. personal, telefon, vendlindje ose kurs',
    'q'           => $F['q'],
    'target'      => 'studentsResults',
    'status'      => $status,
    'chips'       => $chips,
    'chips_label' => 'Gjendja e kursantëve',
    'more'        => [
        ['name' => 'edu', 'label' => 'Arsimi', 'value' => $F['edu'], 'options' => $eduOptions, 'empty' => 'Çdo nivel arsimi'],
        ['name' => 'course_id', 'label' => 'Kursi', 'value' => $F['course_id'], 'options' => $courseOptions, 'empty' => 'Çdo kurs'],
    ],
];

$emptyFor = static function () use ($hasFilters, $status): string {
    if ($hasFilters) {
        return qta_empty('Asnjë kursant nuk përputhet', 'Provo një emër tjetër, numrin e amzës ose hiq një filtër.', 'bi-search');
    }
    return match ($status) {
        'no_group'   => qta_empty('Askush nuk pret një grup', 'Të gjithë kursantët janë në grupe. Kursantët e rinj pa grup shfaqen këtu.', 'bi-check2-circle', '', 'is-success'),
        'ready'      => qta_empty('Askush nuk është gati për grup', 'Kursantët pa grup nuk kanë ende kurs të zgjedhur. Shiko "Pa kurs".', 'bi-hourglass'),
        'no_course'  => qta_empty('Çdo kursant pa grup e ka kursin', 'Nuk ka asnjë kursant që pret zgjedhjen e kursit.', 'bi-check2-circle', '', 'is-success'),
        'in_group'   => qta_empty('Ende asnjë kursant në grup', 'Kur caktoni kursantë në grupe, ata shfaqen këtu.', 'bi-people'),
        'incomplete' => qta_empty('Të dhënat janë të plota', 'Asnjë kursant nuk ka të dhëna që mungojnë.', 'bi-check2-circle', '', 'is-success'),
        'no_exam'    => qta_empty('Çdo provim ka datë', 'Asnjë kursant nuk pret datën e provimit.', 'bi-check2-circle', '', 'is-success'),
        default      => qta_empty('Ende nuk ka kursantë', 'Shtyp "Shto kursant" për të regjistruar të parin.', 'bi-people'),
    };
};

$pageScripts = [qta_asset('app/assets/js/students.js')];
require __DIR__ . '/../shared/app_head.php';

if ($role === 'administrator') require __DIR__ . '/inc/navbar.php';
elseif ($role === 'editor')    require __DIR__ . '/inc/navbar4.php';
?>

<main class="app-main is-wide" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title">Kursantët</h1>
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

  <section class="section" aria-labelledby="listTitle">
    <div class="list-head" data-live-region="list-head">
      <h2 class="section-title" id="listTitle" tabindex="-1" data-live-focus>
        <?= h($stateTitles[$status] ?? 'Të gjithë kursantët') ?>
        <span class="count"><?= number_format($total, 0, ',', '.') ?></span>
      </h2>
      <div class="list-actions">
        <?php if ($total > 0) require __DIR__ . '/../shared/partials/export_menu.php'; ?>
      </div>
    </div>

    <?php require __DIR__ . '/../shared/partials/list_toolbar.php'; ?>

    <div id="studentsResults" data-live-region="results" data-live-announce="<?= h(qta_plural($total, 'kursant', 'kursantë')) ?>">

      <?php if ($amzeCheck['missing_count'] > 0): ?>
        <div class="notice is-warning mb-3" role="note">
          <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
          <span>
            <b>Mungojnë <?= (int)$amzeCheck['missing_count'] ?> numra amze midis <?= (int)$amzeCheck['min'] ?> dhe <?= (int)$amzeCheck['max'] ?>.</b>
            <?php if (!empty($amzeCheck['missing'])): ?>
              <details class="d-inline">
                <summary class="d-inline">Shiko cilët</summary>
                <span class="code d-block mt-1"><?= h(implode(', ', $amzeCheck['missing'])) ?><?= $amzeCheck['truncated'] ? ' …' : '' ?></span>
              </details>
            <?php endif; ?>
          </span>
        </div>
      <?php endif; ?>

      <?php if ($assignMode && !$EDIT_MODE && $students):
        $editModeBannerTitle = 'Po shikon listën.';
        $editModeBannerText = 'Për të zgjedhur kursin ose për t\'i caktuar në grup, shtyp "Lejo ndryshimet" lart djathtas.';
        require __DIR__ . '/../shared/partials/edit_mode_off_banner.php';
      endif; ?>

      <?php if (!$students): ?>
        <?= $emptyFor() ?>

      <?php elseif ($assignMode): ?>
        <div class="table-responsive">
          <table class="table" id="studentsTable" data-sortable data-assign-table>
            <thead>
              <tr>
                <th scope="col" class="pick-col" data-sort="none">
                  <input class="form-check-input" type="checkbox" data-pick-all aria-label="Zgjidh të gjithë kursantët në këtë faqe" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                </th>
                <th scope="col" class="nowrap" data-sort="num">Nr. i amzës</th>
                <th scope="col" data-sort="text">Kursanti</th>
                <th scope="col" class="col-medium" data-sort="text">Kursi</th>
                <th scope="col" class="nowrap" data-sort="none">Cakto në grup</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($students as $s):
                $sid  = (int)$s['student_id'];
                $pcid = (int)($s['plan_course_id'] ?? 0);
                $full = qta_full_name($s['first_name'] ?? '', $s['father_name'] ?? '', $s['last_name'] ?? '');
                $who  = $full !== '' ? $full : 'kursantin ' . (string)$s['nr_amze'];
                $rowGroups = $pcid > 0 ? ($groupsByCourse[$pcid] ?? []) : null;
              ?>
                <tr data-student="<?= $sid ?>">
                  <td class="pick-col">
                    <input class="form-check-input" type="checkbox" data-pick value="<?= $sid ?>" aria-label="Zgjidh <?= h($who) ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                  </td>
                  <td class="nowrap" data-sort-value="<?= (int)$s['nr_amze'] ?>"><span class="id-code"><?= h((string)$s['nr_amze']) ?></span></td>
                  <td>
                    <a class="person-name" href="student_card.php?sid=<?= $sid ?>"><?= h($full !== '' ? $full : 'Pa emër ende') ?></a>
                    <?php if (!empty($s['personal_number'])): ?><span class="cell-sub code"><?= h((string)$s['personal_number']) ?></span><?php endif; ?>
                  </td>
                  <td class="col-medium" data-sort-value="<?= h((string)($s['plan_course_name'] ?? '')) ?>">
                    <?php if ($pcid > 0): ?>
                      <span class="d-inline-flex align-items-center gap-1">
                        <span><?= h((string)$s['plan_course_name']) ?></span>
                        <?php if ($EDIT_MODE): ?>
                          <button class="btn btn-ghost btn-sm btn-icon" type="button" data-plan-remove
                                  data-student="<?= $sid ?>" data-course="<?= $pcid ?>" data-name="<?= h($who) ?>"
                                  aria-label="Hiq kursin <?= h((string)$s['plan_course_name']) ?> nga <?= h($who) ?>" data-tip="Hiq kursin">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                          </button>
                        <?php endif; ?>
                      </span>
                    <?php else: ?>
                      <div class="inline-action">
                        <select class="form-select form-select-sm" data-plan-select aria-label="Zgjidh kursin për <?= h($who) ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                          <option value="">Zgjidh kursin</option>
                          <?php foreach ($courses as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= h((string)$c['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button class="btn btn-secondary btn-sm" type="button" data-plan-save data-student="<?= $sid ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>Ruaj</button>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="nowrap">
                    <?php if ($rowGroups === []): ?>
                      <span class="text-muted small">Nuk ka grup për këtë kurs.</span>
                      <a class="small" href="<?= h($createHref . '&course_id=' . $pcid) ?>">Krijo një</a>
                    <?php else: ?>
                      <div class="inline-action">
                        <select class="form-select form-select-sm" data-group-select aria-label="Zgjidh grupin për <?= h($who) ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                          <option value="">Zgjidh grupin</option>
                          <?php if ($rowGroups !== null): foreach ($rowGroups as $g) echo $groupOption($g); else: echo $allGroupOptions; endif; ?>
                        </select>
                        <button class="btn btn-primary btn-sm" type="button" data-assign data-student="<?= $sid ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>Cakto</button>
                      </div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($EDIT_MODE): ?>
          <div class="bulk-bar" data-bulk role="region" aria-label="Veprim për kursantët e zgjedhur" hidden>
            <span class="bulk-count"><span data-bulk-n>0</span> të zgjedhur</span>
            <label class="form-label mb-0" for="bulkGroup">Cakto në</label>
            <select class="form-select form-select-sm w-auto mw-100" id="bulkGroup" data-bulk-group>
              <option value="">Zgjidh grupin</option>
              <?= $allGroupOptions ?>
            </select>
            <button class="btn btn-primary btn-sm" type="button" data-bulk-assign>
              <i class="bi bi-people" aria-hidden="true"></i>Cakto të zgjedhurit
            </button>
            <button class="btn btn-ghost btn-sm" type="button" data-bulk-clear>Hiq zgjedhjen</button>
            <span class="text-muted small" data-bulk-progress aria-live="polite"></span>
          </div>
        <?php endif; ?>

      <?php else: ?>
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
                <th scope="col" class="col-wide" data-sort="text">Kursi</th>
                <th scope="col" data-sort="text">Gjinia</th>
                <th scope="col" data-sort="text">Telefoni</th>
                <th scope="col" class="col-actions" data-sort="none"><span class="visually-hidden">Veprime</span></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($students as $s):
              $sid = (int)$s['student_id'];
              $fullName = qta_full_name($s['first_name'] ?? '', $s['father_name'] ?? '', $s['last_name'] ?? '');
              $gid = (int)($s['group_id'] ?? 0);
              $groupHref = $gid ? (($s['group_model'] ?? '') === 'scheduled' ? 'lesson_group.php?id=' . $gid . '#kursantet' : 'groups.php?group=' . $gid) : '';
              $from = qta_date($s['group_start_date'] ?? null, '');
              $to   = qta_date($s['group_end_date'] ?? null, '');
              $ce = $EDIT_MODE ? 'true' : 'false';
            ?>
              <tr id="row-<?= $sid ?>">
                <td class="cell" data-id="<?= $sid ?>" data-field="nr_amze">
                  <span class="editable id-code" contenteditable="<?= $ce ?>"><?= h((string)$s['nr_amze']) ?></span>
                </td>
                <td class="cell" data-id="<?= $sid ?>" data-field="first_name">
                  <span class="editable" contenteditable="<?= $ce ?>"><?= h($s['first_name'] ?: '—') ?></span>
                </td>
                <td class="cell" data-id="<?= $sid ?>" data-field="father_name">
                  <span class="editable" contenteditable="<?= $ce ?>"><?= h($s['father_name'] ?: '—') ?></span>
                </td>
                <td class="cell" data-id="<?= $sid ?>" data-field="last_name">
                  <span class="editable" contenteditable="<?= $ce ?>"><?= h($s['last_name'] ?: '—') ?></span>
                </td>
                <td class="cell nowrap" data-id="<?= $sid ?>" data-field="personal_number">
                  <span class="editable id-code" contenteditable="<?= $ce ?>"><?= h($s['personal_number'] ?: '—') ?></span>
                </td>
                <td class="cell nowrap" data-id="<?= $sid ?>" data-field="birth_date" title="Formati: dd.mm.vvvv">
                  <span class="editable" contenteditable="<?= $ce ?>" data-dmy data-dmy-kind="birth" data-dmy-title="Datëlindja — <?= h($fullName ?: 'kursanti') ?>"><?= h(qta_date($s['birth_date'])) ?></span>
                </td>
                <td class="cell" data-id="<?= $sid ?>" data-field="birth_place">
                  <span class="editable" contenteditable="<?= $ce ?>"><?= h($s['birth_place'] ?: '—') ?></span>
                </td>
                <td class="cell" data-id="<?= $sid ?>" data-field="education_level_id" data-sort-value="<?= h((string)($s['edu_label'] ?? '')) ?>">
                  <select class="form-select form-select-sm inline-select" aria-label="Arsimi i <?= h($fullName ?: 'kursantit') ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                    <option value="">— Zgjidh —</option>
                    <?php foreach ($eduLevels as $el): ?>
                      <option value="<?= (int)$el['id'] ?>" <?= ((int)$s['edu_id'] === (int)$el['id']) ? 'selected' : '' ?>><?= h($el['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td class="col-wide">
                  <?php if ($gid): ?>
                    <a class="person-name" href="<?= h($groupHref) ?>"><?= h((string)$s['group_course_name']) ?></a>
                    <span class="cell-sub">Grupi #<?= $gid ?><?= $from !== '' ? ' · ' . h($from . ($to !== '' ? ' – ' . $to : '')) : '' ?></span>
                  <?php elseif (!empty($s['plan_course_name'])): ?>
                    <span><?= h((string)$s['plan_course_name']) ?></span>
                    <span class="cell-sub">Pret grup</span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="cell" data-id="<?= $sid ?>" data-field="gender_id" data-sort-value="<?= h((string)($s['gender_label'] ?? '')) ?>">
                  <select class="form-select form-select-sm inline-select" aria-label="Gjinia e <?= h($fullName ?: 'kursantit') ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>>
                    <?php foreach ($genders as $g): ?>
                      <option value="<?= (int)$g['id'] ?>" <?= ((int)$s['gender_id'] === (int)$g['id']) ? 'selected' : '' ?>><?= h($g['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td class="cell nowrap" data-id="<?= $sid ?>" data-field="phone">
                  <span class="editable" contenteditable="<?= $ce ?>"><?= h($s['phone'] ?: '—') ?></span>
                </td>
                <td class="col-actions">
                  <span class="row-actions">
                    <a class="btn btn-ghost btn-sm btn-icon" href="student_card.php?sid=<?= $sid ?>"
                       aria-label="Hap kartelën e <?= h($fullName ?: 'kursantit ' . (string)$s['nr_amze']) ?>" data-tip="Hap kartelën">
                      <i class="bi bi-person-vcard" aria-hidden="true"></i>
                    </a>
                    <?php if ($EDIT_MODE): ?>
                      <button type="button" class="btn btn-ghost btn-ghost-danger btn-sm btn-icon btn-delete"
                              aria-label="Fshi regjistrimin <?= h((string)$s['nr_amze']) ?>" data-tip="Fshi regjistrimin"
                              data-sid="<?= $sid ?>" data-amze="<?= h((string)$s['nr_amze']) ?>" data-name="<?= h($fullName ?: '—') ?>">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                      </button>
                    <?php endif; ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?= qta_list_pager('students.php', $listState, $page, $totalPages, qta_plural($total, 'kursant', 'kursantë')) ?>
    </div>
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
            <input type="text" name="birth_date" id="bdInput" class="form-control" placeholder="dd.mm.vvvv" inputmode="numeric" autocomplete="off" aria-describedby="bdHelp" data-dmy data-dmy-kind="birth">
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
            <label class="form-label" for="planSelect">Kursi <span class="optional">(nëse dihet)</span></label>
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

<!-- Dialog: kursi pas ndryshimit të numrit të amzës -->
<div class="modal fade" id="pickCourseModal" tabindex="-1" aria-labelledby="pickCourseTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="pickCourseTitle"><i class="bi bi-book" aria-hidden="true"></i>Cili kurs është për këtë amzë?</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <label class="form-label" for="pickCourseSelect">Kursi</label>
        <select id="pickCourseSelect" class="form-select">
          <option value="">— Nuk dua të zgjedh tani —</option>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="form-text">Kursi vetëm planifikohet; grupi caktohet më vonë.</p>
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
        <p class="mb-2">Bashkë me të fshihen edhe kursi i zgjedhur, vendi në grup dhe kodi QR i këtij regjistrimi. <b>Kjo nuk mund të kthehet mbrapsht.</b></p>
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
<script type="application/json" id="studentsConfig"><?= json_encode([
    'csrf'     => $CSRF,
    'edit'     => $EDIT_MODE,
    'inline'   => 'students_inline_update.php',
    'assign'   => 'student_assignment.php',
    'flash_ok' => flash('ok'),
    'flash_err'=> flash('err'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</body>
</html>
