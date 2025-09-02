<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* ------------------------------
   Guard: vetëm admin i loguar
------------------------------- */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch();
$role = strtolower((string)($currentUser['role_name'] ?? ''));
if (!$currentUser || !in_array($role, ['administrator','editor'], true)) {
  header('Location: selectProfile.php'); exit;
}

/* ------------------------------
   CSRF
------------------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

/* ------------------------------
   Roli 'student' dhe gjinia 'mashkull'
------------------------------- */
$studentRoleId = (int)$pdo->query("SELECT id FROM roles WHERE name='student'")->fetchColumn();
if (!$studentRoleId) { exit('Konfigurim i mangët: mungon roli "student".'); }

$maleGenderId = (int)($pdo->query("
  SELECT id FROM genders
  WHERE code IN ('M','m') OR LOWER(label) IN ('mashkull','male','m')
  LIMIT 1
")->fetchColumn() ?: 0);
if (!$maleGenderId) { exit('Konfigurim i mangët: mungon gjinia Mashkull në tabelën genders.'); }

/* ------------------------------
   Helpers
------------------------------- */
function parseAmzeRanges(string $s): array {
  $out = [];
  foreach (preg_split('/\s*,\s*/', trim($s)) as $tok) {
    if ($tok === '') continue;
    if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $tok, $m)) {
      $a = (int)$m[1]; $b = (int)$m[2];
      if ($a > $b) [$a,$b] = [$b,$a];
      for ($i=$a; $i<=$b; $i++) $out[$i] = true;
    } elseif (preg_match('/^\d+$/', $tok)) {
      $out[(int)$tok] = true;
    }
  }
  $nums = array_keys($out);
  sort($nums, SORT_NUMERIC);
  return $nums;
}

/** Siguron ekzistencën e një studenti me nr_amze = $amzeNum (krijon persons+users+students nëse mungon). */
function ensureStudentByAmze(PDO $pdo, int $studentRoleId, int $maleGenderId, int $amzeNum): int {
  $q = $pdo->prepare("SELECT id FROM students WHERE CAST(nr_amze AS UNSIGNED) = :n LIMIT 1");
  $q->execute([':n'=>$amzeNum]);
  $sid = $q->fetchColumn();
  if ($sid) return (int)$sid;

  // 1) person (lejon NULL për emra/ID personale sipas patch-it të skemës)
  $insP = $pdo->prepare("
    INSERT INTO persons (first_name, father_name, last_name, birth_date, birth_place, personal_number, phone, gender_id)
    VALUES (NULL, NULL, NULL, NULL, NULL, NULL, NULL, :g)
  ");
  $insP->execute([':g'=>$maleGenderId]);
  $pid = (int)$pdo->lastInsertId();

  // 2) user
  $insU = $pdo->prepare("INSERT INTO users (role_id, person_id, full_name, email) VALUES (:r, :pid, NULL, NULL)");
  $insU->execute([':r'=>$studentRoleId, ':pid'=>$pid]);
  $uid = (int)$pdo->lastInsertId();

  // 3) student
  $nrAmzeStr = (string)$amzeNum;
  $insS = $pdo->prepare("
    INSERT INTO students (user_id, person_id, nr_amze, education_level_id)
    VALUES (:uid, :pid, :amz, NULL)
  ");
  $insS->execute([':uid'=>$uid, ':pid'=>$pid, ':amz'=>$nrAmzeStr]);

  return (int)$pdo->lastInsertId();
}

/* ------------------------------
   POST: create/edit/update/delete
------------------------------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';

  /* ===== Krijo grup ===== */
  if ($action==='create_group') {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf'])) {
      http_response_code(400); exit('CSRF token mismatch.');
    }
    try {
      $course_id = (int)($_POST['course_id'] ?? 0);
      $start_date = trim((string)($_POST['start_date'] ?? ''));
      $end_date   = trim((string)($_POST['end_date'] ?? ''));
      $amze_spec  = trim((string)($_POST['amze_spec'] ?? ''));

      if ($course_id<=0) throw new RuntimeException('Zgjidh një modul.');
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) throw new RuntimeException('Data e fillimit është e pavlefshme.');
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) throw new RuntimeException('Data e mbarimit është e pavlefshme.');
      if ($end_date < $start_date) throw new RuntimeException('Data e mbarimit duhet të jetë ≥ datës së fillimit.');

      $pdo->beginTransaction();

      // Krijo grupin – NUK ka exam_date në nivel grupi
      $st = $pdo->prepare("INSERT INTO course_groups (course_id, start_date, end_date) VALUES (:c,:s,:e)");
      $st->execute([':c'=>$course_id, ':s'=>$start_date, ':e'=>$end_date]);
      $gid = (int)$pdo->lastInsertId();

      // Anëtarët (deri në 10) + rregull: personi s’mund ta ndjekë dy herë të njëjtin modul
      if ($amze_spec !== '') {
        $nums = parseAmzeRanges($amze_spec);
        if (count($nums) > 10) throw new RuntimeException('Maksimumi 10 studentë për grup. Redukto listën e AMZË-ve.');

        $ids = [];
        foreach ($nums as $n) { $ids[] = ensureStudentByAmze($pdo, $studentRoleId, $maleGenderId, $n); }
        $ids = array_values(array_unique($ids));
        if (count($ids)>10) throw new RuntimeException('Maksimumi 10 studentë për grup.');

        // 1) Kontroll sipas student_id në të njëjtin modul
        if ($ids) {
          $ph = implode(',', array_fill(0, count($ids), '?'));
          $confQ = $pdo->prepare("
            SELECT s.nr_amze, cg.id AS group_id, c.name AS course_name
            FROM course_group_students cgs
            JOIN course_groups cg ON cg.id = cgs.group_id
            JOIN courses c ON c.id = cg.course_id
            JOIN students s ON s.id = cgs.student_id
            WHERE cgs.student_id IN ($ph) AND cg.course_id = ?
          ");
          $confQ->execute([...$ids, $course_id]);
          $conf = $confQ->fetchAll(PDO::FETCH_ASSOC);
          if ($conf) {
            $items = array_map(fn($r)=> $r['nr_amze'].' ('.$r['course_name'].')', $conf);
            throw new RuntimeException('Këta studentë e kanë ndjekur tashmë këtë modul: '.implode(', ', $items));
          }
        }

        // 2) Kontroll shtesë sipas persons.personal_number
        if ($ids) {
          $phIds = implode(',', array_fill(0, count($ids), '?'));
          $pnStmt = $pdo->prepare("
            SELECT DISTINCT p.personal_number
            FROM students s
            JOIN persons  p ON p.id = s.person_id
            WHERE s.id IN ($phIds)
              AND p.personal_number IS NOT NULL AND p.personal_number <> ''
          ");
          $pnStmt->execute($ids);
          $pnList = $pnStmt->fetchAll(PDO::FETCH_COLUMN);

          if ($pnList) {
            $phPn = implode(',', array_fill(0, count($pnList), '?'));
            $confPN = $pdo->prepare("
              SELECT DISTINCT p.personal_number, s.nr_amze, cg.id AS group_id, c.name AS course_name
              FROM course_group_students cgs
              JOIN students s ON s.id = cgs.student_id
              JOIN persons  p ON p.id = s.person_id
              JOIN course_groups cg ON cg.id = cgs.group_id
              JOIN courses c ON c.id = cg.course_id
              WHERE cg.course_id = ?
                AND p.personal_number IN ($phPn)
            ");
            $confPN->execute([$course_id, ...$pnList]);
            $hitPN = $confPN->fetchAll(PDO::FETCH_ASSOC);
            if ($hitPN) {
              $items = array_map(fn($r)=> ($r['nr_amze'] ?: $r['personal_number']).' ('.$r['course_name'].')', $hitPN);
              throw new RuntimeException('Disa persona (sipas ID personale) e kanë ndjekur tashmë këtë modul: '.implode(', ', $items));
            }
          }
        }

        // Shto anëtarët (exam_date dhe final_score = NULL fillimisht)
        $ins = $pdo->prepare("INSERT INTO course_group_students (group_id, student_id) VALUES (:g,:s)");
        foreach ($ids as $sid) { $ins->execute([':g'=>$gid, ':s'=>$sid]); }
      }
      $pdo->commit();

      $_SESSION['flash_ok'] = 'Grupi u krijua me sukses.';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $_SESSION['flash_err'] = $e->getMessage();
    }
    header('Location: groups.php'); exit;
  }

  /* ===== Ndrysho modulin e grupit ===== */
  if ($action==='update_group_course') {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf'])) {
      http_response_code(400); exit('CSRF token mismatch.');
    }
    $group_id  = (int)($_POST['group_id'] ?? 0);
    $course_id = (int)($_POST['course_id'] ?? 0);
    try {
      if ($group_id<=0 || $course_id<=0) throw new RuntimeException('Të dhëna të pavlefshme.');

      // Moduli duhet të ekzistojë
      $q = $pdo->prepare("SELECT 1 FROM courses WHERE id=:id");
      $q->execute([':id'=>$course_id]);
      if (!$q->fetchColumn()) throw new RuntimeException('Moduli i zgjedhur nuk ekziston.');

      // Anëtarët aktualë të grupit
      $members = $pdo->prepare("SELECT student_id FROM course_group_students WHERE group_id=:g");
      $members->execute([':g'=>$group_id]);
      $toCheck = $members->fetchAll(PDO::FETCH_COLUMN, 0);

      if ($toCheck) {
        // 1) Kontroll sipas student_id
        $ph = implode(',', array_fill(0, count($toCheck), '?'));
        $confQ = $pdo->prepare("
          SELECT s.nr_amze, cg.id AS other_group_id, c.name AS course_name
          FROM course_group_students cgs
          JOIN course_groups cg ON cg.id = cgs.group_id
          JOIN courses c ON c.id = cg.course_id
          JOIN students s ON s.id = cgs.student_id
          WHERE cgs.student_id IN ($ph)
            AND cgs.group_id <> ?
            AND cg.course_id = ?
        ");
        $confQ->execute([...$toCheck, $group_id, $course_id]);
        $conf = $confQ->fetchAll(PDO::FETCH_ASSOC);
        if ($conf) {
          $items = array_map(fn($r)=> $r['nr_amze'].' ('.$r['course_name'].')', $conf);
          throw new RuntimeException('Ndërrimi i modulit s’lejohet: disa studentë e kanë ndjekur tashmë këtë modul: '.implode(', ', $items));
        }

        // 2) Kontroll shtesë sipas persons.personal_number
        $phIds = implode(',', array_fill(0, count($toCheck), '?'));
        $pnStmt = $pdo->prepare("
          SELECT DISTINCT p.personal_number
          FROM students s
          JOIN persons  p ON p.id = s.person_id
          WHERE s.id IN ($phIds)
            AND p.personal_number IS NOT NULL AND p.personal_number <> ''
        ");
        $pnStmt->execute($toCheck);
        $pnList = $pnStmt->fetchAll(PDO::FETCH_COLUMN);
        if ($pnList) {
          $phPn = implode(',', array_fill(0, count($pnList), '?'));
          $confPN = $pdo->prepare("
            SELECT DISTINCT p.personal_number, s.nr_amze, cg.id AS other_group_id, c.name AS course_name
            FROM course_group_students cgs
            JOIN students s ON s.id = cgs.student_id
            JOIN persons  p ON p.id = s.person_id
            JOIN course_groups cg ON cg.id = cgs.group_id
            JOIN courses c ON c.id = cg.course_id
            WHERE cg.course_id = ?
              AND cgs.group_id <> ?
              AND p.personal_number IN ($phPn)
          ");
          $confPN->execute([$course_id, $group_id, ...$pnList]);
          $hitPN = $confPN->fetchAll(PDO::FETCH_ASSOC);
          if ($hitPN) {
            $items = array_map(fn($r)=> ($r['nr_amze'] ?: $r['personal_number']).' ('.$r['course_name'].')', $hitPN);
            throw new RuntimeException('Ndërrimi i modulit s’lejohet: persona (sipas ID personale) e kanë ndjekur tashmë këtë modul: '.implode(', ', $items));
          }
        }
      }

      // OK – përditëso
      $st = $pdo->prepare("UPDATE course_groups SET course_id=:c WHERE id=:g");
      $st->execute([':c'=>$course_id, ':g'=>$group_id]);

      $_SESSION['flash_ok'] = 'Moduli i grupit u përditësua.';
    } catch (Throwable $e) {
      $_SESSION['flash_err'] = $e->getMessage();
    }
    header('Location: groups.php'); exit;
  }

  /* ===== Modifiko anëtarët ===== */
  if ($action==='edit_members') {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf'])) {
      http_response_code(400); exit('CSRF token mismatch.');
    }
    $group_id = (int)($_POST['group_id'] ?? 0);
    $amze_spec_members = trim((string)($_POST['amze_spec_members'] ?? ''));
    try {
      if ($group_id<=0) throw new RuntimeException('Grup i pavlefshëm.');

      // Ekzistuesit (amz num -> student_id)
      $q = $pdo->prepare("
        SELECT s.id AS student_id, CAST(s.nr_amze AS UNSIGNED) AS amznum
        FROM course_group_students cgs
        JOIN students s ON s.id = cgs.student_id
        WHERE cgs.group_id = :gid
        ORDER BY amznum
      ");
      $q->execute([':gid'=>$group_id]);
      $existing = $q->fetchAll(PDO::FETCH_ASSOC);
      $existMap = [];
      foreach ($existing as $row) $existMap[(int)$row['amznum']] = (int)$row['student_id'];

      // Target (deri 10)
      $targetNums = ($amze_spec_members === '') ? [] : parseAmzeRanges($amze_spec_members);
      if (count($targetNums) > 10) throw new RuntimeException('Maksimumi 10 studentë për grup.');

      $targetMap = []; // amznum => student_id
      foreach ($targetNums as $n) { $targetMap[$n] = ensureStudentByAmze($pdo, $studentRoleId, $maleGenderId, $n); }
      if (count($targetMap) > 10) throw new RuntimeException('Maksimumi 10 studentë për grup.');

      // Diferencat
      $toRemove = [];
      foreach ($existMap as $amz=>$sid) if (!array_key_exists($amz, $targetMap)) $toRemove[] = $sid;
      $toAdd = [];
      foreach ($targetMap as $amz=>$sid) if (!array_key_exists($amz, $existMap)) $toAdd[] = $sid;

      $pdo->beginTransaction();

      // Heqjet
      if ($toRemove) {
        $del = $pdo->prepare("DELETE FROM course_group_students WHERE group_id=:g AND student_id=:s");
        foreach ($toRemove as $sid) { $del->execute([':g'=>$group_id, ':s'=>$sid]); }
      }

      // Shtimet
      if ($toAdd) {
        $gidCourseId = (int)$pdo->query("SELECT course_id FROM course_groups WHERE id = ".(int)$group_id)->fetchColumn();

        // 1) Rregulli: askush nga $toAdd të mos e ketë ndjekur këtë modul
        $ph = implode(',', array_fill(0, count($toAdd), '?'));
        $confQ = $pdo->prepare("
          SELECT s.nr_amze, cg.id AS group_id, c.name AS course_name
          FROM course_group_students cgs
          JOIN course_groups cg ON cg.id = cgs.group_id
          JOIN courses c ON c.id = cg.course_id
          JOIN students s ON s.id = cgs.student_id
          WHERE cgs.student_id IN ($ph) AND cg.course_id = ?
        ");
        $confQ->execute([...$toAdd, $gidCourseId]);
        $conf = $confQ->fetchAll(PDO::FETCH_ASSOC);
        if ($conf) {
          $items = array_map(fn($r)=> $r['nr_amze'].' ('.$r['course_name'].')', $conf);
          throw new RuntimeException('Këta studentë e kanë ndjekur tashmë këtë modul: '.implode(', ', $items));
        }

        // 2) Kontroll shtesë sipas persons.personal_number
        $phIds = implode(',', array_fill(0, count($toAdd), '?'));
        $pnStmt = $pdo->prepare("
          SELECT DISTINCT p.personal_number
          FROM students s
          JOIN persons  p ON p.id = s.person_id
          WHERE s.id IN ($phIds)
            AND p.personal_number IS NOT NULL AND p.personal_number <> ''
        ");
        $pnStmt->execute($toAdd);
        $pnList = $pnStmt->fetchAll(PDO::FETCH_COLUMN);
        if ($pnList) {
          $phPn = implode(',', array_fill(0, count($pnList), '?'));
          $confPN = $pdo->prepare("
            SELECT DISTINCT p.personal_number, s.nr_amze, cg.id AS group_id, c.name AS course_name
            FROM course_group_students cgs
            JOIN students s ON s.id = cgs.student_id
            JOIN persons  p ON p.id = s.person_id
            JOIN course_groups cg ON cg.id = cgs.group_id
            JOIN courses c ON c.id = cg.course_id
            WHERE cg.course_id = ?
              AND p.personal_number IN ($phPn)
          ");
          $confPN->execute([$gidCourseId, ...$pnList]);
          $hitPN = $confPN->fetchAll(PDO::FETCH_ASSOC);
          if ($hitPN) {
            $items = array_map(fn($r)=> ($r['nr_amze'] ?: $r['personal_number']).' ('.$r['course_name'].')', $hitPN);
            throw new RuntimeException('Disa persona (sipas ID personale) e kanë ndjekur tashmë këtë modul: '.implode(', ', $items));
          }
        }

        // Kapaciteti
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM course_group_students WHERE group_id=".(int)$group_id)->fetchColumn();
        if ($cnt + count($toAdd) > 10) throw new RuntimeException('Ky ndryshim tejkalon kufirin 10 për grup.');

        $ins = $pdo->prepare("INSERT INTO course_group_students (group_id, student_id) VALUES (:g,:s)");
        foreach ($toAdd as $sid) $ins->execute([':g'=>$group_id, ':s'=>$sid]);
      }

      $pdo->commit();
      $_SESSION['flash_ok'] = 'Anëtarët e grupit u përditësuan.';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $_SESSION['flash_err'] = $e->getMessage();
    }
    header('Location: groups.php'); exit;
  }

  /* ===== Fshi grupin ===== */
  if ($action==='delete_group') {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf'])) {
      http_response_code(400); exit('CSRF token mismatch.');
    }
    $group_id = (int)($_POST['group_id'] ?? 0);
    try {
      if ($group_id<=0) throw new RuntimeException('Grup i pavlefshëm.');
      $exists = $pdo->prepare("SELECT 1 FROM course_groups WHERE id=:g");
      $exists->execute([':g'=>$group_id]);
      if (!$exists->fetchColumn()) throw new RuntimeException('Grupi nuk u gjet.');

      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM course_group_students WHERE group_id=:g")->execute([':g'=>$group_id]);
      $pdo->prepare("DELETE FROM course_groups WHERE id=:g")->execute([':g'=>$group_id]);
      $pdo->commit();

      $_SESSION['flash_ok'] = 'Grupi u fshi me sukses.';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $_SESSION['flash_err'] = $e->getMessage();
    }
    header('Location: groups.php'); exit;
  }
}

/* ------------------------------
   Filtro/Kërko
------------------------------- */
$q = trim($_GET['q'] ?? '');
$courseFilter = trim($_GET['course_id'] ?? '');  // opsional

/* Kurset për dropdown */
$courses = $pdo->query("SELECT id, code, name FROM courses ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);

/* Query: rreshta (grup + student) */
$params = [];
$w = ["1=1"];
if ($q !== '') {
  $w[] = "(s.nr_amze LIKE :kw
        OR p.personal_number LIKE :kw2
        OR p.first_name LIKE :kw3
        OR p.father_name LIKE :kw4
        OR p.last_name LIKE :kw5)";
  $params[':kw']  = '%'.$q.'%';
  $params[':kw2'] = '%'.$q.'%';
  $params[':kw3'] = '%'.$q.'%';
  $params[':kw4'] = '%'.$q.'%';
  $params[':kw5'] = '%'.$q.'%';
}
if ($courseFilter !== '' && ctype_digit($courseFilter)) {
  $w[] = "cg.course_id = :cf";
  $params[':cf'] = (int)$courseFilter;
}
$whereSql = 'WHERE '.implode(' AND ', $w);

$sql = "
  SELECT
    cg.id AS group_id, cg.course_id, cg.start_date, cg.end_date,
    c.code AS course_code, c.name AS course_name,

    s.id AS student_id, s.nr_amze,
    p.first_name, p.father_name, p.last_name,
    p.personal_number, p.birth_date,
    TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) AS age,

    el.code AS edu_code, el.label AS edu_label,

    cgs.exam_date,            -- EXAM PER-STUDENT
    cgs.final_score
  FROM course_groups cg
  JOIN courses c ON c.id = cg.course_id
  LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
  LEFT JOIN students s ON s.id = cgs.student_id
  LEFT JOIN persons  p ON p.id = s.person_id
  LEFT JOIN education_levels el ON el.id = s.education_level_id
  $whereSql
  ORDER BY cg.start_date DESC, cg.id DESC, CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
";
$st = $pdo->prepare($sql);
foreach ($params as $k=>$v) $st->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* Studentë pa grup */
$w2 = ["1=1"];
$params2 = [];
if ($q !== '') {
  $w2[] = "(s.nr_amze LIKE :kw
        OR p.personal_number LIKE :kw2
        OR p.first_name LIKE :kw3
        OR p.father_name LIKE :kw4
        OR p.last_name LIKE :kw5)";
  $params2[':kw']  = '%'.$q.'%';
  $params2[':kw2'] = '%'.$q.'%';
  $params2[':kw3'] = '%'.$q.'%';
  $params2[':kw4'] = '%'.$q.'%';
  $params2[':kw5'] = '%'.$q.'%';
}
$whereNoGroup = 'WHERE '.implode(' AND ', $w2);
$sqlNoGroup = "
  SELECT s.id AS student_id, s.nr_amze,
         p.first_name, p.father_name, p.last_name, p.personal_number, p.birth_date,
         TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) AS age,
         el.code AS edu_code, el.label AS edu_label
  FROM students s
  LEFT JOIN course_group_students cgs ON cgs.student_id = s.id
  LEFT JOIN persons  p ON p.id = s.person_id
  LEFT JOIN education_levels el ON el.id = s.education_level_id
  $whereNoGroup
  GROUP BY s.id
  HAVING COUNT(cgs.group_id) = 0
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
";
$ng = $pdo->prepare($sqlNoGroup);
foreach ($params2 as $k=>$v) $ng->bindValue($k,$v,PDO::PARAM_STR);
$ng->execute();
$noGroup = $ng->fetchAll(PDO::FETCH_ASSOC);

/* Info për dropdown-et e Formularit 1 */
$groupInfo = $pdo->query("
  SELECT
    cg.id,
    cg.start_date, cg.end_date,
    c.code AS course_code, c.name AS course_name,
    MIN(CAST(s.nr_amze AS UNSIGNED)) AS amze_min,
    MAX(CAST(s.nr_amze AS UNSIGNED)) AS amze_max
  FROM course_groups cg
  JOIN courses c ON c.id = cg.course_id
  LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
  LEFT JOIN students s ON s.id = cgs.student_id
  GROUP BY cg.id
  ORDER BY cg.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* Flash mesazhe */
$flash_ok  = $_SESSION['flash_ok']  ?? null; unset($_SESSION['flash_ok']);
$flash_err = $_SESSION['flash_err'] ?? null; unset($_SESSION['flash_err']);

$NAV_ACTIVE = 'groups';
if ($role === 'administrator') {
  require __DIR__ . '/inc/navbar.php';    // navbar i adminëve
} else {
  require __DIR__ . '/inc/navbar4.php';   // navbar i editorëve
}
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Grupe – QTA <?= $role==='editor' ? 'Editor' : 'Admin' ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { background:#f5f7fb; padding-top:72px; }
    .navbar-brand img { height:28px; }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
    .mini-table thead { background:#f1f5f9; }
    .form-control::placeholder { color:#9ca3af; }
    .pagination .page-link { border-radius:.5rem; }
    .nowrap { white-space:nowrap; }
    @media (max-width: 575.98px) { .navbar-text { display:none; } }

    .editable { display:inline-block; min-width:72px; padding:.35rem .5rem; border-radius:.5rem; transition:box-shadow .2s, background-color .2s; }
    .editable:hover { background:#f8fafc; box-shadow:inset 0 0 0 1px #e5e7eb; }
    .editable:focus { outline:0; background:#eef2ff; box-shadow:inset 0 0 0 2px #4f46e5; }
    .cell-saving { position:relative; }
    .cell-saving::after { content:''; position:absolute; right:.25rem; top:50%; width:.55rem; height:.55rem; border:.15rem solid rgba(0,0,0,.2); border-top-color:rgba(0,0,0,.55); border-radius:50%; animation:spin .6s linear infinite; transform:translateY(-50%); }
    @keyframes spin { to { transform:translateY(-50%) rotate(360deg); } }
    .cell-ok { animation: flashOk 1.2s ease; } @keyframes flashOk { 0%{background:#ecfdf5;} 100%{background:transparent;} }
    .cell-err { animation: flashErr 1.2s ease; } @keyframes flashErr { 0%{background:#fef2f2;} 100%{background:transparent;} }
  </style>
</head>
<body>

<main class="container-fluid px-3 px-md-4">
  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
    <h2 class="mb-0">Grupe</h2>
    <div class="d-flex gap-2">
      <form class="d-flex" method="get" action="groups.php">
        <div class="input-group">
          <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
          <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control border-0" placeholder="Kërko studentë sipas AMZË/ID/Emri...">
          <select name="course_id" class="form-select">
            <option value="">— Modul —</option>
            <?php foreach($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ($courseFilter!=='' && (int)$courseFilter===(int)$c['id'])?'selected':'' ?>>
                <?= htmlspecialchars($c['code'].' — '.$c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-outline-secondary" type="button" onclick="window.location='groups.php'"><i class="bi bi-x-circle me-1"></i>Pastro</button>
          <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apliko</button>
        </div>
      </form>
      <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#form1Modal">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Formulari nr. 1
      </button>
      <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#form2Modal">
        <i class="bi bi-file-earmark-text me-1"></i> Formulari nr. 2
      </button>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createGroupModal">
        <i class="bi bi-plus-circle me-1"></i> Krijo grup
      </button>
    </div>
  </div>

  <?php if ($flash_ok): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($flash_ok) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  <?php if ($flash_err): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($flash_err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div id="msgBox" class="mb-3" style="display:none;"></div>

  <?php
  // Grupi -> (header, students[])
  $groups = [];
  foreach ($rows as $r) {
    $gid = (int)$r['group_id'];
    if (!isset($groups[$gid])) {
      $groups[$gid] = [
        'header' => [
          'group_id'=>$gid,
          'course_id'=>$r['course_id'],
          'course_code'=>$r['course_code'],
          'course_name'=>$r['course_name'],
          'start_date'=>$r['start_date'],
          'end_date'=>$r['end_date'],
        ],
        'students' => []
      ];
    }
    if ($r['student_id']) $groups[$gid]['students'][] = $r;
  }
  ?>

  <?php if ($groups): foreach ($groups as $gid=>$g): ?>
    <?php
      $prefillAmze = [];
      foreach ($g['students'] as $stRow) { $prefillAmze[] = (string)((int)$stRow['nr_amze']); }
      $prefillAmzeStr = implode(', ', $prefillAmze);
    ?>
    <div class="card mb-4">
      <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex align-items-center gap-3">
          <h5 class="mb-0">
            <i class="bi bi-collection me-2"></i>
            Grup #<?= (int)$g['header']['group_id'] ?> — <?= htmlspecialchars($g['header']['course_code'].' · '.$g['header']['course_name']) ?>
          </h5>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-3">
          <div class="text-muted small">
            <span class="me-3">Fillimi:
              <span class="editable cell-inline" contenteditable="true"
                    data-field="start_date" data-group="<?= (int)$g['header']['group_id'] ?>" data-student="0"
                    title="YYYY-MM-DD"><?= htmlspecialchars($g['header']['start_date']) ?></span>
            </span>
            <span class="me-3">Mbarimi:
              <span class="editable cell-inline" contenteditable="true"
                    data-field="end_date" data-group="<?= (int)$g['header']['group_id'] ?>" data-student="0"
                    title="YYYY-MM-DD (≥ data e fillimit)"><?= htmlspecialchars($g['header']['end_date']) ?></span>
            </span>
          </div>
          <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-primary btn-sm"
                    data-bs-toggle="modal" data-bs-target="#editMembersModal_<?= (int)$gid ?>">
              <i class="bi bi-pencil-square me-1"></i>Modifiko anëtarët
            </button>
            <button class="btn btn-outline-secondary btn-sm"
                    data-bs-toggle="modal" data-bs-target="#editCourseModal_<?= (int)$gid ?>">
              <i class="bi bi-pencil me-1"></i>Ndrysho modul
            </button>
            <button class="btn btn-outline-danger btn-sm"
                    data-bs-toggle="modal" data-bs-target="#deleteGroupModal_<?= (int)$gid ?>">
              <i class="bi bi-trash me-1"></i>Fshi grupin
            </button>
          </div>
        </div>
      </div>
      <div class="card-body">
        <div class="table-responsive mini-table">
          <table class="table align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th class="nowrap">AMZË</th>
                <th>Emër Atësi Mbiemër<br><small class="text-muted">ID Personal</small></th>
                <th class="nowrap">Datë testimi (student)</th>
                <th class="nowrap">Pikët përfundimtare</th>
                <th class="nowrap">Mosha</th>
                <th class="nowrap">Arsimi</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($g['students']): foreach ($g['students'] as $r): ?>
              <tr>
                <td class="nowrap"><?= htmlspecialchars($r['nr_amze']) ?></td>
                <td>
                  <div class="fw-semibold">
                    <?= htmlspecialchars(trim(($r['first_name']??'').' '.(($r['father_name']??'')?($r['father_name'].' '):'').($r['last_name']??''))) ?>
                  </div>
                  <div class="text-muted small"><?= htmlspecialchars($r['personal_number'] ?? '') ?></div>
                </td>

                <!-- exam_date per student -->
                <td class="cell nowrap" data-student="<?= (int)$r['student_id'] ?>" data-group="<?= (int)$gid ?>" data-field="exam_date"
                    title="YYYY-MM-DD (≥ data e mbarimit të grupit)">
                  <span class="editable" contenteditable="true">
                    <?= htmlspecialchars($r['exam_date'] ?: '—') ?>
                  </span>
                </td>

                <!-- final_score per student -->
                <td class="cell nowrap" data-student="<?= (int)$r['student_id'] ?>" data-group="<?= (int)$gid ?>" data-field="final_score" title="0–100">
                  <span class="editable" contenteditable="true">
                    <?= $r['final_score'] !== null ? rtrim(rtrim((string)$r['final_score'],'0'),'.') : '—' ?>
                  </span>
                </td>

                <td class="nowrap"><?= $r['age'] !== null ? (int)$r['age'] : '—' ?></td>
                <td><?= htmlspecialchars(($r['edu_code']? $r['edu_code'].' — ' : '').($r['edu_label'] ?? '—')) ?></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="6" class="text-center text-muted">S’ka studentë në këtë grup.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- MODAL: Modifiko anëtarët e grupit -->
    <div class="modal fade" id="editMembersModal_<?= (int)$gid ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <form class="modal-content" method="post" action="groups.php">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
          <input type="hidden" name="action" value="edit_members">
          <input type="hidden" name="group_id" value="<?= (int)$gid ?>">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-people me-1"></i> Modifiko anëtarët — Grup #<?= (int)$gid ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <label class="form-label">AMZË që duhet të jenë në këtë grup (deri në 10)</label>
            <textarea name="amze_spec_members" class="form-control" rows="3"
              placeholder="p.sh. 3400-3403, 3409"><?= htmlspecialchars($prefillAmzeStr) ?></textarea>
            <div class="form-text">
              Mund të shtosh ose heqësh AMZË. Nëse shkruan AMZË që s’ekziston, do të krijohet student i ri me të dhëna bosh.
              Kapaciteti maksimal: 10 studentë. <br>
              <strong>Rregull:</strong> i njëjti person (sipas ID personale) nuk mund të jetë dy herë në të njëjtin modul.
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mbyll</button>
            <button class="btn btn-primary" type="submit">Ruaj ndryshimet</button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL: Ndrysho modulin e grupit -->
    <div class="modal fade" id="editCourseModal_<?= (int)$gid ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <form class="modal-content" method="post" action="groups.php">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
          <input type="hidden" name="action" value="update_group_course">
          <input type="hidden" name="group_id" value="<?= (int)$gid ?>">

          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-book me-1"></i> Ndrysho modulin — Grup #<?= (int)$gid ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">
            <label class="form-label">Zgjidh modul</label>
            <select name="course_id" class="form-select" required>
              <?php foreach($courses as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === (int)$g['header']['course_id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($c['code'].' — '.$c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Ndryshon modulin (kursin) me të cilin lidhet ky grup.</div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mbyll</button>
            <button class="btn btn-primary" type="submit">Ruaj</button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL: Fshi grupin -->
    <div class="modal fade" id="deleteGroupModal_<?= (int)$gid ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <form class="modal-content" method="post" action="groups.php">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
          <input type="hidden" name="action" value="delete_group">
          <input type="hidden" name="group_id" value="<?= (int)$gid ?>">
          <div class="modal-header">
            <h5 class="modal-title text-danger"><i class="bi bi-trash me-1"></i> Fshi grupin #<?= (int)$gid ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            Jeni i sigurt që doni të fshini këtë grup?<br/>
            <strong>Kujdes:</strong> Kjo do të fshijë edhe lidhjet e studentëve me këtë grup (notat e ruajtura në këtë grup).
            Studentët nuk fshihen nga sistemi.
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
            <button class="btn btn-danger" type="submit">Po, fshije</button>
          </div>
        </form>
      </div>
    </div>

  <?php endforeach; else: ?>
    <div class="card mb-4">
      <div class="card-body">
        <div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1"></i>Nuk ka grupe ende. Krijo një të ri.</div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Studentë pa grup -->
  <div class="card mb-4">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
      <h5 class="mb-0"><i class="bi bi-person-dash me-2"></i>Studentë pa grup</h5>
      <span class="text-muted small"><?= number_format(count($noGroup)) ?> student(ë)</span>
    </div>
    <div class="card-body">
      <div class="table-responsive mini-table">
        <table class="table align-middle mb-0">
          <thead class="table-light">
          <tr>
            <th class="nowrap">AMZË</th>
            <th>Emër Atësi Mbiemër<br><small class="text-muted">ID Personal</small></th>
            <th class="nowrap">Mosha</th>
            <th class="nowrap">Arsimi</th>
          </tr>
          </thead>
          <tbody>
          <?php if ($noGroup): foreach ($noGroup as $s): ?>
            <tr>
              <td class="nowrap"><?= htmlspecialchars($s['nr_amze']) ?></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars(trim(($s['first_name']??'').' '.(($s['father_name']??'')?($s['father_name'].' '):'').($s['last_name']??''))) ?></div>
                <div class="text-muted small"><?= htmlspecialchars($s['personal_number'] ?? '') ?></div>
              </td>
              <td class="nowrap"><?= $s['age'] !== null ? (int)$s['age'] : '—' ?></td>
              <td><?= htmlspecialchars(($s['edu_code']? $s['edu_code'].' — ' : '').($s['edu_label'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="4" class="text-center text-muted">Të gjithë studentët janë në grupe.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="text-center text-muted small mt-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<!-- MODAL: Formulari nr. 1 -->
<div class="modal fade" id="form1Modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="form1Export" method="get" action="groups_export.php">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="type" value="form1">
      <input type="hidden" name="f" value="xlsx" id="form1Format">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Formulari nr. 1</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Grupi i fillimit</label>
          <select name="gstart" id="gstart" class="form-select" required>
            <option value="">— Zgjidh —</option>
            <?php foreach($groupInfo as $gi): ?>
              <option value="<?= (int)$gi['id'] ?>">
                #<?= (int)$gi['id'] ?> — <?= htmlspecialchars($gi['course_code'].' · '.$gi['course_name']) ?> (<?= htmlspecialchars($gi['start_date'].' → '.$gi['end_date']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text" id="gstartHint">(AMZË: —)</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Grupi i mbarimit</label>
          <select name="gend" id="gend" class="form-select" required>
            <option value="">— Zgjidh —</option>
            <?php foreach($groupInfo as $gi): ?>
              <option value="<?= (int)$gi['id'] ?>">
                #<?= (int)$gi['id'] ?> — <?= htmlspecialchars($gi['course_code'].' · '.$gi['course_name']) ?> (<?= htmlspecialchars($gi['start_date'].' → '.$gi['end_date']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text" id="gendHint">(AMZË: —)</div>
        </div>
        <div class="small text-muted">
          Për çdo grup në intervalin [fillim…mbarim] shkarkohet: Emri i kursit, Fillimi, Mbarimi, Totale,
          Femra (kur gjinia të jetë e plotë), moshat 16–24, 25–34, 35+, si dhe AU/AM/AL.
        </div>
      </div>
      <div class="modal-footer">
        <div class="btn-group me-auto">
          <button type="button" class="btn btn-outline-success" data-dl="xlsx"><i class="bi bi-file-earmark-excel me-1"></i> Excel</button>
          <button type="button" class="btn btn-outline-danger" data-dl="pdf"><i class="bi bi-file-earmark-pdf me-1"></i> PDF</button>
          <button type="button" class="btn btn-outline-primary" data-dl="docx"><i class="bi bi-file-earmark-word me-1"></i> Word</button>
        </div>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mbyll</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: Formulari nr. 2 -->
<div class="modal fade" id="form2Modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="form2Export" method="get" action="groups_export.php">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="type" value="form2">
      <input type="hidden" name="f" value="xlsx" id="form2Format">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-1"></i> Formulari nr. 2</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">AMZË e fillimit</label>
            <input type="number" name="amze_start" class="form-control" placeholder="p.sh. 3400" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">AMZË e mbarimit</label>
            <input type="number" name="amze_end" class="form-control" placeholder="p.sh. 3499" required>
          </div>
        </div>
        <div class="small text-muted mt-2">
          Shkarkohet: AMZË, Emër-Atësi-Mbiemër, vendlindja, dhe emri i kursit (nga grupi më i fundit të studentit).
        </div>
      </div>
      <div class="modal-footer">
        <div class="btn-group me-auto">
          <button type="button" class="btn btn-outline-success" data-dl="xlsx"><i class="bi bi-file-earmark-excel me-1"></i> Excel</button>
          <button type="button" class="btn btn-outline-danger" data-dl="pdf"><i class="bi bi-file-earmark-pdf me-1"></i> PDF</button>
          <button type="button" class="btn btn-outline-primary" data-dl="docx"><i class="bi bi-file-earmark-word me-1"></i> Word</button>
        </div>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mbyll</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: Krijo grup -->
<div class="modal fade" id="createGroupModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post" action="groups.php">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="action" value="create_group">

      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> Krijo grup të ri</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>

      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Moduli *</label>
            <select name="course_id" class="form-select" required>
              <option value="">— Zgjidh —</option>
              <?php foreach($courses as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['code'].' — '.$c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Datë fillimi *</label>
            <input type="date" name="start_date" class="form-control" required>
          </div>

          <div class="col-md-3">
            <label class="form-label">Datë mbarimi *</label>
            <input type="date" name="end_date" class="form-control" required>
          </div>

          <div class="col-md-9">
            <label class="form-label">AMZË për këtë grup (deri në 10)</label>
            <textarea name="amze_spec" class="form-control" rows="2" placeholder="p.sh. 3400-3403, 3409"></textarea>
            <div class="form-text">
              Mund të shkruash intervale dhe vlera të ndara me presje. Maksimumi 10 studentë.
              <br><strong>Rregull:</strong> i njëjti person (sipas ID personale) nuk mund ta ndjekë dy herë të njëjtin modul.
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit">Krijo grup</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const ENDPOINT = 'groups_inline_update.php';

function clean(s){ return (s||'').replace(/\s+/g,' ').trim(); }
function showMsg(type, text){
  const box = document.getElementById('msgBox');
  box.innerHTML = `
    <div class="alert alert-${type} alert-dismissible fade show" role="alert">
      ${text}
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>`;
  box.style.display = '';
}

async function saveInline(payload, cell, displayEl, oldVal){
  try{
    if(cell) cell.classList.add('cell-saving');
    const res = await fetch(ENDPOINT, {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({...payload, csrf: CSRF})
    });
    const json = await res.json();
    if(cell) cell.classList.remove('cell-saving');

    if(!json.ok){
      if(displayEl) displayEl.textContent = oldVal;
      if(cell){ cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'),1200); }
      showMsg('danger', json.error || 'Gabim i panjohur.');
      return;
    }

    if(displayEl && json.display !== undefined){
      displayEl.textContent = json.display;
    }
    if(cell){ cell.classList.add('cell-ok'); setTimeout(()=>cell.classList.remove('cell-ok'),800); }
  }catch(e){
    console.error(e);
    if(displayEl) displayEl.textContent = oldVal;
    if(cell){ cell.classList.remove('cell-saving'); cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'),1200); }
    showMsg('danger','Nuk u krye veprimi. Kontrollo lidhjen ose provo sërish.');
  }
}

/* Inline në header-in e grupit (vetëm start/end) */
document.querySelectorAll('.cell-inline.editable').forEach(el=>{
  let oldVal = el.textContent;
  el.addEventListener('focus', ()=>{ oldVal = el.textContent; });
  el.addEventListener('keydown', ev=>{ if(ev.key==='Enter'){ ev.preventDefault(); el.blur(); }});
  el.addEventListener('blur', ()=>{
    const field = el.dataset.field;
    const gid = parseInt(el.dataset.group,10);
    const newVal = clean(el.textContent);
    if(newVal===clean(oldVal)) return;

    if(['start_date','end_date'].includes(field)){
      if(newVal!=='' && !/^\d{4}-\d{2}-\d{2}$/.test(newVal)){
        el.textContent = oldVal; el.classList.add('cell-err'); setTimeout(()=>el.classList.remove('cell-err'),1200);
        showMsg('danger','Data duhet në formatin YYYY-MM-DD.'); return;
      }
      const action = field==='start_date' ? 'update_group_start' : 'update_group_end';
      saveInline({action, student_id:0, group_id:gid, [field]:(newVal===''?null:newVal)}, null, el, oldVal);
    }
  });
});

/* Inline per student: exam_date & final_score */
document.querySelectorAll('td.cell .editable').forEach(el=>{
  let oldVal = el.textContent;
  el.addEventListener('focus', ()=>{ oldVal = el.textContent; });
  el.addEventListener('keydown', ev=>{ if(ev.key==='Enter'){ ev.preventDefault(); el.blur(); }});
  el.addEventListener('blur', ()=>{
    const cell = el.closest('td.cell');
    const field = cell.dataset.field;
    const sid = parseInt(cell.dataset.student,10);
    const gid = parseInt(cell.dataset.group,10);
    const newVal = clean(el.textContent);
    if(newVal===clean(oldVal)) return;

    if(field==='exam_date'){
      if(newVal!=='' && !/^\d{4}-\d{2}-\d{2}$/.test(newVal)){
        el.textContent = oldVal; cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'),1200);
        showMsg('danger','Data e testit duhet në formatin YYYY-MM-DD.'); return;
      }
      saveInline({action:'update_student_exam_date', student_id:sid, group_id:gid, exam_date:(newVal===''?null:newVal)}, cell, el, oldVal);
      return;
    }

    if(field==='final_score'){
      if(newVal===''){
        saveInline({action:'update_final_score', student_id:sid, group_id:gid, final_score:null}, cell, el, oldVal);
        return;
      }
      const n = newVal.replace(',','.');
      if(isNaN(n)){
        el.textContent = oldVal; cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'),1200);
        showMsg('danger','Nota duhet të jetë numër.'); return;
      }
      saveInline({action:'update_final_score', student_id:sid, group_id:gid, final_score:n}, cell, el, oldVal);
      return;
    }
  });
});

/* Mapping: groupId -> {min, max} për hint-et në modalin e Form. 1 */
const GROUP_AMZE = <?= json_encode(array_column($groupInfo, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;

function updateHint(selId, hintId) {
  const v = document.getElementById(selId).value;
  const h = document.getElementById(hintId);
  if (!v || !GROUP_AMZE[v]) { h.textContent = '(AMZË: —)'; return; }
  const mi = GROUP_AMZE[v]['amze_min'];
  const ma = GROUP_AMZE[v]['amze_max'];
  if (mi === null || ma === null) h.textContent = '(AMZË: —)';
  else h.textContent = `(AMZË: ${mi} – ${ma})`;
}

['gstart','gend'].forEach(id=>{
  const el = document.getElementById(id);
  if (el) el.addEventListener('change', ()=>{
    updateHint('gstart','gstartHint');
    updateHint('gend','gendHint');
  });
});
updateHint('gstart','gstartHint');
updateHint('gend','gendHint');

/* Butonat e download-it për secilin formular */
document.querySelectorAll('#form1Modal [data-dl]').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    document.getElementById('form1Format').value = btn.dataset.dl;
    document.getElementById('form1Export').submit();
  });
});
document.querySelectorAll('#form2Modal [data-dl]').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    document.getElementById('form2Format').value = btn.dataset.dl;
    document.getElementById('form2Export').submit();
  });
});
</script>
</body>
</html>
