<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* ------------------------------
   Guard: admin/editor i loguar
------------------------------- */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);
$role = strtolower((string)($currentUser['role_name'] ?? ''));
if (!$currentUser || !in_array($role, ['administrator','editor'], true)) {
  header('Location: selectProfile.php'); exit;
}

/* ------------------------------
   EDIT MODE toggle (persistohet në session)
------------------------------- */
if (isset($_GET['edit'])) {
  $e = strtolower((string)$_GET['edit']);
  $_SESSION['edit_mode'] = ($e === 'on');
  $qs = $_GET; unset($qs['edit']);
  $url = 'groups.php' . (empty($qs) ? '' : ('?' . http_build_query($qs)));
  header("Location: $url"); exit;
}
$EDIT_MODE = (bool)($_SESSION['edit_mode'] ?? false);

/* ------------------------------
   CSRF
------------------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

/* ------------------------------
   Roli 'student' dhe gjinia 'mashkull' (për auto-krijime me AMZË)
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
function fmt_dMY(?string $iso): string {
  if (!$iso) return '—';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return htmlspecialchars($iso, ENT_QUOTES, 'UTF-8');
  $ts = strtotime($iso);
  return $ts ? date('d-m-Y', $ts) : '—';
}
function dmy_to_iso(?string $s): ?string {
  if ($s === null) return null;
  $s = trim($s);
  if ($s === '') return null;
  if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $s, $m)) {
    return "{$m[3]}-{$m[2]}-{$m[1]}";
  }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  return null;
}
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
/* Audit helper – thërret librarinë nëse ekziston */
function qta_audit_event(string $type, array $payload): void {
  try {
    if (function_exists('qta_audit_log')) {
      qta_audit_log($GLOBALS['pdo'] ?? null, $type, $payload);
    }
  } catch (Throwable $e) {
    // mos blloko rrjedhën nëse audit dështon
  }
}

/** Siguron ekzistencën e një studenti me nr_amze = $amzeNum (krijon persons+users+students nëse mungon). */
function ensureStudentByAmze(PDO $pdo, int $studentRoleId, int $maleGenderId, int $amzeNum): int {
  $q = $pdo->prepare("SELECT id FROM students WHERE CAST(nr_amze AS UNSIGNED) = :n LIMIT 1");
  $q->execute([':n'=>$amzeNum]);
  $sid = $q->fetchColumn();
  if ($sid) return (int)$sid;

  $insP = $pdo->prepare("
    INSERT INTO persons (first_name, father_name, last_name, birth_date, birth_place, personal_number, phone, gender_id)
    VALUES (NULL, NULL, NULL, NULL, NULL, NULL, NULL, :g)
  ");
  $insP->execute([':g'=>$maleGenderId]);
  $pid = (int)$pdo->lastInsertId();

  $insU = $pdo->prepare("INSERT INTO users (role_id, person_id, full_name, email) VALUES (:r, :pid, NULL, NULL)");
  $insU->execute([':r'=>$studentRoleId, ':pid'=>$pid]);
  $uid = (int)$pdo->lastInsertId();

  $insS = $pdo->prepare("INSERT INTO students (user_id, person_id, nr_amze, education_level_id) VALUES (:uid, :pid, :amz, NULL)");
  $insS->execute([':uid'=>$uid, ':pid'=>$pid, ':amz'=>(string)$amzeNum]);

  return (int)$pdo->lastInsertId();
}

/* ------------------------------
   POST: create/edit/update/delete
------------------------------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';

  if (!$EDIT_MODE) {
    $_SESSION['flash_err'] = 'Edit Mode është OFF. Aktivizo për të bërë ndryshime.';
    header('Location: groups.php'); exit;
  }
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf'])) {
    http_response_code(400); $_SESSION['flash_err'] = 'CSRF token mismatch.'; header('Location: groups.php'); exit;
  }

  /* ===== Krijo grup ===== */
  if ($action==='create_group') {
    try {
      $course_id   = (int)($_POST['course_id'] ?? 0);
      $start_date  = dmy_to_iso((string)($_POST['start_date'] ?? ''));
      $end_date    = dmy_to_iso((string)($_POST['end_date'] ?? ''));
      $amze_spec   = trim((string)($_POST['amze_spec'] ?? ''));
      $is_completed = isset($_POST['is_completed']) && $_POST['is_completed']=='1' ? 1 : 0;

      if ($course_id<=0) throw new RuntimeException('Zgjidh një modul.');
      if (!$start_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) throw new RuntimeException('Data e fillimit duhet në formatin DD-MM-YYYY.');
      if (!$end_date   || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date))   throw new RuntimeException('Data e mbarimit duhet në formatin DD-MM-YYYY.');
      if ($end_date < $start_date) throw new RuntimeException('Data e mbarimit duhet të jetë ≥ datës së fillimit.');

      $pdo->beginTransaction();

      $st = $pdo->prepare("INSERT INTO course_groups (course_id, start_date, end_date, is_completed) VALUES (:c,:s,:e,:ic)");
      $st->execute([':c'=>$course_id, ':s'=>$start_date, ':e'=>$end_date, ':ic'=>$is_completed]);
      $gid = (int)$pdo->lastInsertId();

      if ($amze_spec !== '') {
        $nums = parseAmzeRanges($amze_spec);
        if (count($nums) > 10) throw new RuntimeException('Maksimumi 10 studentë për grup. Redukto listën e AMZË-ve.');

        $ids = [];
        foreach ($nums as $n) { $ids[] = ensureStudentByAmze($pdo, $studentRoleId, $maleGenderId, $n); }
        $ids = array_values(array_unique($ids));
        if (count($ids)>10) throw new RuntimeException('Maksimumi 10 studentë për grup.');

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

        $ins = $pdo->prepare("INSERT INTO course_group_students (group_id, student_id) VALUES (:g,:s)");
        foreach ($ids as $sid) { $ins->execute([':g'=>$gid, ':s'=>$sid]); }
      }
      $pdo->commit();

      // AUDIT
      qta_audit_event('group.create', [
        'group_id'=>$gid,
        'course_id'=>$course_id,
        'start_date'=>$start_date,
        'end_date'=>$end_date,
        'is_completed'=>$is_completed,
        'actor_user_id'=>$_SESSION['user_id'] ?? null
      ]);

      $_SESSION['flash_ok'] = 'Grupi u krijua me sukses.';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $_SESSION['flash_err'] = $e->getMessage();
    }
    header('Location: groups.php'); exit;
  }

  /* ===== Ndrysho modulin e grupit ===== */
  if ($action==='update_group_course') {
    $group_id  = (int)($_POST['group_id'] ?? 0);
    $course_id = (int)($_POST['course_id'] ?? 0);
    $force     = (int)($_POST['force'] ?? 0);

    try {
      if ($group_id<=0 || $course_id<=0) throw new RuntimeException('Të dhëna të pavlefshme.');

      $gRow = $pdo->prepare("SELECT is_completed FROM course_groups WHERE id=:g");
      $gRow->execute([':g'=>$group_id]);
      $is_completed = (int)($gRow->fetchColumn() ?? 0);
      if ($is_completed && !$force) { throw new RuntimeException('Ky grup është i përfunduar. Konfirmo ndryshimin.'); }

      $q = $pdo->prepare("SELECT 1 FROM courses WHERE id=:id");
      $q->execute([':id'=>$course_id]);
      if (!$q->fetchColumn()) throw new RuntimeException('Moduli i zgjedhur nuk ekziston.');

      $members = $pdo->prepare("SELECT student_id FROM course_group_students WHERE group_id=:g");
      $members->execute([':g'=>$group_id]);
      $toCheck = $members->fetchAll(PDO::FETCH_COLUMN, 0);

      if ($toCheck) {
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

      $st = $pdo->prepare("UPDATE course_groups SET course_id=:c WHERE id=:g");
      $st->execute([':c'=>$course_id, ':g'=>$group_id]);

      // AUDIT
      qta_audit_event('group.update_course', [
        'group_id'=>$group_id,
        'new_course_id'=>$course_id,
        'actor_user_id'=>$_SESSION['user_id'] ?? null
      ]);

      $_SESSION['flash_ok'] = 'Moduli i grupit u përditësua.';
    } catch (Throwable $e) {
      $_SESSION['flash_err'] = $e->getMessage();
    }
    header('Location: groups.php'); exit;
  }

  /* ===== Modifiko anëtarët ===== */
  if ($action==='edit_members') {
    $group_id = (int)($_POST['group_id'] ?? 0);
    $amze_spec_members = trim((string)($_POST['amze_spec_members'] ?? ''));
    $force     = (int)($_POST['force'] ?? 0);

    try {
      if ($group_id<=0) throw new RuntimeException('Grup i pavlefshëm.');

      $gRow = $pdo->prepare("SELECT is_completed, course_id FROM course_groups WHERE id=:g");
      $gRow->execute([':g'=>$group_id]);
      $gRowData = $gRow->fetch(PDO::FETCH_ASSOC);
      $is_completed = (int)($gRowData['is_completed'] ?? 0);
      $gidCourseId  = (int)($gRowData['course_id'] ?? 0);
      if ($is_completed && !$force) { throw new RuntimeException('Ky grup është i përfunduar. Konfirmo ndryshimin.'); }

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

      $targetNums = ($amze_spec_members === '') ? [] : parseAmzeRanges($amze_spec_members);
      if (count($targetNums) > 10) throw new RuntimeException('Maksimumi 10 studentë për grup.');

      $targetMap = [];
      foreach ($targetNums as $n) { $targetMap[$n] = ensureStudentByAmze($pdo, $studentRoleId, $maleGenderId, $n); }
      if (count($targetMap) > 10) throw new RuntimeException('Maksimumi 10 studentë për grup.');

      $toRemove = [];
      foreach ($existMap as $amz=>$sid) if (!array_key_exists($amz, $targetMap)) $toRemove[] = $sid;
      $toAdd = [];
      foreach ($targetMap as $amz=>$sid) if (!array_key_exists($amz, $existMap)) $toAdd[] = $sid;

      $pdo->beginTransaction();

      if ($toRemove) {
        $del = $pdo->prepare("DELETE FROM course_group_students WHERE group_id=:g AND student_id=:s");
        foreach ($toRemove as $sid) { $del->execute([':g'=>$group_id, ':s'=>$sid]); }
      }

      if ($toAdd) {
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

        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM course_group_students WHERE group_id=".(int)$group_id)->fetchColumn();
        if ($cnt + count($toAdd) > 10) throw new RuntimeException('Ky ndryshim tejkalon kufirin 10 për grup.');

        $ins = $pdo->prepare("INSERT INTO course_group_students (group_id, student_id) VALUES (:g,:s)");
        foreach ($toAdd as $sid) $ins->execute([':g'=>$group_id, ':s'=>$sid]);
      }

      $pdo->commit();

      // AUDIT
      qta_audit_event('group.update_members', [
        'group_id'=>$group_id,
        'added'=>$toAdd,
        'removed'=>$toRemove,
        'actor_user_id'=>$_SESSION['user_id'] ?? null
      ]);

      $_SESSION['flash_ok'] = 'Anëtarët e grupit u përditësuan.';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $_SESSION['flash_err'] = $e->getMessage();
    }
    header('Location: groups.php'); exit;
  }

  /* ===== Fshi grupin ===== */
  if ($action==='delete_group') {
    $group_id = (int)($_POST['group_id'] ?? 0);
    $force     = (int)($_POST['force'] ?? 0);

    try {
      if ($group_id<=0) throw new RuntimeException('Grup i pavlefshëm.');

      $gRow = $pdo->prepare("SELECT is_completed FROM course_groups WHERE id=:g");
      $gRow->execute([':g'=>$group_id]);
      $is_completed = (int)($gRow->fetchColumn() ?? 0);
      if ($is_completed && !$force) { throw new RuntimeException('Ky grup është i përfunduar. Konfirmo fshirjen.'); }

      $exists = $pdo->prepare("SELECT 1 FROM course_groups WHERE id=:g");
      $exists->execute([':g'=>$group_id]);
      if (!$exists->fetchColumn()) throw new RuntimeException('Grupi nuk u gjet.');

      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM course_group_students WHERE group_id=:g")->execute([':g'=>$group_id]);
      $pdo->prepare("DELETE FROM course_groups WHERE id=:g")->execute([':g'=>$group_id]);
      $pdo->commit();

      // AUDIT
      qta_audit_event('group.delete', [
        'group_id'=>$group_id,
        'actor_user_id'=>$_SESSION['user_id'] ?? null
      ]);

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

/* Kurset për dropdown (pa kodin, vetëm emrat) */
$courses = $pdo->query("SELECT id, name FROM courses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

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
    cg.id AS group_id, cg.course_id, cg.start_date, cg.end_date, cg.is_completed,
    c.name AS course_name,

    s.id AS student_id, s.nr_amze,
    p.first_name, p.father_name, p.last_name,
    p.personal_number, p.birth_date,
    TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) AS age,

    el.code AS edu_code, el.label AS edu_label,

    cgs.exam_date,
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
  SELECT
    s.id AS student_id, s.nr_amze,
    p.first_name, p.father_name, p.last_name, p.personal_number, p.birth_date,
    TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) AS age,
    el.code AS edu_code, el.label AS edu_label,

    /* NEW: plan i modulit (mund të ketë më shumë se 1) */
    COUNT(DISTINCT scp.course_id) AS planned_count,
    MIN(cp.name) AS planned_first_name

  FROM students s
  LEFT JOIN course_group_students cgs ON cgs.student_id = s.id
  LEFT JOIN persons  p ON p.id = s.person_id
  LEFT JOIN education_levels el ON el.id = s.education_level_id

  /* NEW: planet e moduleve */
  LEFT JOIN student_course_plans scp
         ON scp.student_id = s.id AND scp.status = 'planned'
  LEFT JOIN courses cp ON cp.id = scp.course_id

  $whereNoGroup
  GROUP BY s.id
  HAVING COUNT(cgs.group_id) = 0
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
";
$ng = $pdo->prepare($sqlNoGroup);
foreach ($params2 as $k=>$v) $ng->bindValue($k,$v,PDO::PARAM_STR);
$ng->execute();
$noGroup = $ng->fetchAll(PDO::FETCH_ASSOC);

/* ===== Banner metrics ===== */
try {
  // (1) Studentë pa asnjë grup fare
  $countNoGroup = (int)$pdo->query("
    SELECT COUNT(*)
    FROM students s
    LEFT JOIN course_group_students cgs ON cgs.student_id = s.id
    WHERE cgs.student_id IS NULL
  ")->fetchColumn();

  // (2) Studentë që KANË modul (plan) por NUK janë në asnjë grup
  $countPlannedNoGroup = (int)$pdo->query("
    SELECT COUNT(DISTINCT s.id)
    FROM students s
    LEFT JOIN course_group_students cgs ON cgs.student_id = s.id
    JOIN student_course_plans scp
      ON scp.student_id = s.id AND scp.status = 'planned'
    WHERE cgs.student_id IS NULL
  ")->fetchColumn();
} catch (Throwable $e) {
  // Nëse mungon tabela student_course_plans, mos e prish faqen
  $countNoGroup = (int)($countNoGroup ?? 0);
  $countPlannedNoGroup = 0;
}

/* Info për dropdown-et e Formularit 1 (pa kod, vetëm emër kursi) */
$groupInfo = $pdo->query("
  SELECT
    cg.id,
    cg.start_date, cg.end_date, cg.is_completed,
    c.name AS course_name,
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

/* Navbar */
$NAV_ACTIVE = 'groups';
if ($role === 'administrator') require __DIR__ . '/inc/navbar.php';
else require __DIR__ . '/inc/navbar4.php';

/* Build toggle URL që ruan parametrat */
$toggleUrl = 'groups.php?' . http_build_query(array_filter([
  'q' => ($q !== '' ? $q : null),
  'course_id' => ($courseFilter !== '' ? $courseFilter : null),
  'edit' => ($EDIT_MODE ? 'off' : 'on'),
]));
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

    /* --- Inline editable cells --- */
    .editable { display:inline-block; min-width:72px; padding:.35rem .5rem; border-radius:.5rem; transition:box-shadow .2s, background-color .2s; }
    .editable[contenteditable="true"]:hover { background:#f8fafc; box-shadow:inset 0 0 0 1px #e5e7eb; cursor:text; }
    .editable[contenteditable="true"]:focus { outline:0; background:#eef2ff; box-shadow:inset 0 0 0 2px #4f46e5; }
    .editable[contenteditable="false"] { opacity:.7; cursor:default; }

    .cell-saving { position:relative; }
    .cell-saving::after { content:''; position:absolute; right:.25rem; top:50%; width:.55rem; height:.55rem; border:.15rem solid rgba(0,0,0,.2); border-top-color:rgba(0,0,0,.55); border-radius:50%; animation:spin .6s linear infinite; transform:translateY(-50%); }
    @keyframes spin { to { transform:translateY(-50%) rotate(360deg); } }
    .cell-ok { animation: flashOk 1.2s ease; } @keyframes flashOk { 0%{background:#ecfdf5;} 100%{background:transparent;} }
    .cell-err { animation: flashErr 1.2s ease; } @keyframes flashErr { 0%{background:#fef2f2;} 100%{background:transparent;} }

    .btn-pill { border-radius:999px !important; }
    .btn-soft-primary   { background:#eef2ff; color:#1d4ed8; border:1px solid #e0e7ff; }
    .btn-soft-primary:hover { background:#e0e7ff; color:#1d4ed8; }
    .btn-soft-success   { background:#ecfdf5; color:#166534; border:1px solid #bbf7d0; }
    .btn-soft-success:hover { background:#bbf7d0; color:#14532d; }
    .btn-soft-danger    { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
    .btn-soft-danger:hover { background:#fecaca; color:#7f1d1d; }
    .btn-soft-secondary { background:#f1f5f9; color:#334155; border:1px solid #e2e8f0; }
    .btn-soft-secondary:hover { background:#e2e8f0; color:#0f172a; }

    /* --- Toolbar layout --- */
    .page-toolbar { gap:.5rem; }
    .page-toolbar .btn, .group-toolbar .btn { padding:.4rem .75rem; }

    .group-badge { font-size:.75rem; }
    .status-alert { border-radius:.75rem; }
    .status-alert i { opacity:.8; }

    /* FAB (+) poshtë DJATHTAS */
    .btn-fab{
      position: fixed;
      right: 24px;
      bottom: 24px;
      width: 56px; height: 56px; border-radius: 50%;
      display:flex; align-items:center; justify-content:center;
      z-index:1040; box-shadow:0 12px 20px rgba(2,6,23,.15);
    }
    .btn-fab i{ font-size: 1.15rem; line-height: 1; }
    .btn-fab:focus{ box-shadow:0 0 0 .25rem rgba(13,110,253,.25), 0 12px 20px rgba(2,6,23,.15); }
    @media (max-width:575.98px){ .btn-fab{ right:16px; bottom:16px; width:52px; height:52px; } }

/* ===== Floating action buttons (stacked) ===== */
.fab-stack{
  position: fixed;
  right: 24px;
  bottom: 24px;
  display: flex;
  flex-direction: column-reverse; /* create on bottom, toggles above, then edit mode */
  gap: 12px;
  z-index: 1040;
}

.fab-stack .fab-btn{
  align-self: flex-end;            /* expand left, stick to right edge */
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 52px;
  height: 52px;
  width: 52px;                     /* compact (icon-only) by default */
  padding: 0 14px;                 /* room for text when expanded */
  border-radius: 999px;
  box-shadow: 0 12px 20px rgba(2,6,23,.15);
  transition: width .2s ease, box-shadow .2s ease, transform .06s ease;
  overflow: hidden;
}

.fab-stack .fab-btn .fab-text{
  white-space: nowrap;
  max-width: 0;
  opacity: 0;
  transition: max-width .2s ease, opacity .15s ease, margin-left .2s ease;
  margin-left: 0;
}

.fab-stack .fab-btn:hover,
.fab-stack .fab-btn:focus{
  width: auto;                     /* pill with label */
  box-shadow: 0 16px 28px rgba(2,6,23,.22);
}

.fab-stack .fab-btn:hover .fab-text,
.fab-stack .fab-btn:focus .fab-text{
  max-width: 180px;
  opacity: 1;
  margin-left: 4px;
}

.fab-stack .fab-btn:active{ transform: translateY(1px); }

@media (max-width: 575.98px){
  .fab-stack{ right:16px; bottom:16px; gap:10px; }
  .fab-stack .fab-btn{ min-height:48px; height:48px; width:48px; padding:0 12px; }
}

/* Optional: keep old .btn-fab buttons from overlapping if left in DOM */
.btn-fab.fab-toggleall, .btn-fab.fab-editmode{ display:none !important; }

    /* Toasts poshtë MAJTAS */
    .toast.qta-toast{ border:0; border-radius:.75rem; box-shadow:0 12px 20px rgba(2,6,23,.12); }
    .toast.qta-toast .toast-header{ border-bottom:0; }
    .toast-success .toast-header{ background:#ecfdf5; color:#065f46; }
    .toast-danger  .toast-header{ background:#fef2f2; color:#991b1b; }
    .toast-info    .toast-header{ background:#eff6ff; color:#1e40af; }
    .toast-warning .toast-header{ background:#fff7ed; color:#9a3412; }

    .collapse-toggle .bi-chevron-down { transition: transform .2s ease; }
    .collapse-toggle[aria-expanded="true"] .bi-chevron-down { transform: rotate(180deg); }

    .compact .mini-table table.table > :not(caption) > * > * { padding: .35rem .5rem; }
    .status-banner strong { font-size:1.05rem; }
    .status-banner .metric { display:flex; align-items:center; gap:.5rem; }
  </style>
</head>
<body class="<?= $EDIT_MODE ? '' : 'editing-off' ?>">

<!-- Toast container -->
<div id="toastZone" class="toast-container position-fixed start-0 bottom-0 p-3" style="z-index:1080;"></div>

<main class="container-fluid px-3 px-md-4">
  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
    <h2 class="mb-0">Grupe</h2>
    <div class="d-flex flex-wrap align-items-center page-toolbar">
      <button class="btn btn-soft-success btn-pill" data-bs-toggle="modal" data-bs-target="#form1Modal" data-bs-title="Shkarko statistika për grupe">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Formulari nr. 1
      </button>
      <button class="btn btn-soft-danger btn-pill" data-bs-toggle="modal" data-bs-target="#form2Modal" data-bs-title="Shkarko listë studentësh sipas AMZË">
        <i class="bi bi-file-earmark-text me-1"></i> Formulari nr. 2
      </button>
      <!-- U hoqën butonat Expand/Collapse dhe Edit Mode nga toolbar-i sipër -->
    </div>
  </div>

  <?php if (isset($countNoGroup, $countPlannedNoGroup)): ?>
    <div class="alert alert-primary status-banner d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
      <div class="metric">
        <i class="bi bi-people me-1"></i>
        <span><strong><?= number_format((int)$countNoGroup) ?></strong> studentë pa asnjë grup.</span>
      </div>
      <div class="metric">
        <i class="bi bi-journal-text me-1"></i>
        <span><strong><?= number_format((int)$countPlannedNoGroup) ?></strong> me modul (plan) por ende pa grup.</span>
      </div>
    </div>
  <?php endif; ?>


  <div class="card mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get" action="groups.php">
        <div class="col-md-9">
          <div class="d-flex align-items-center">
            <label class="form-label mb-0 me-2" style="min-width:70px;">Kërko</label>
            <div class="input-group flex-grow-1">
              <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
              <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control border-0" placeholder="Kërko studentë sipas AMZË/ID/Emri...">
              <select name="course_id" class="form-select">
                <option value="">— Modul —</option>
                <?php foreach($courses as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= ($courseFilter!=='' && (int)$courseFilter===(int)$c['id'])?'selected':'' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="col-md-3 text-end">
          <button class="btn btn-soft-secondary btn-pill me-1" type="button" onclick="window.location='groups.php'">
            <i class="bi bi-x-circle me-1"></i>Pastro
          </button>
          <button class="btn btn-primary btn-pill" type="submit">
            <i class="bi bi-funnel me-1"></i>Apliko
          </button>
        </div>
      </form>
    </div>
  </div>

  <div id="msgBox" class="mb-3" style="display:none;"></div>

  <?php
  $groups = [];
  foreach ($rows as $r) {
    $gid = (int)$r['group_id'];
    if (!isset($groups[$gid])) {
      $groups[$gid] = [
        'header' => [
          'group_id'=>$gid,
          'course_id'=>$r['course_id'],
          'course_name'=>$r['course_name'],
          'start_date'=>$r['start_date'],
          'end_date'=>$r['end_date'],
          'is_completed'=>(int)$r['is_completed']
        ],
        'students' => []
      ];
    }
    if ($r['student_id']) $groups[$gid]['students'][] = $r;
  }

  foreach ($groups as $gid => &$g) {
    $amzes = [];
    foreach ($g['students'] as $stRow) {
      if (!empty($stRow['nr_amze'])) $amzes[] = (int)$stRow['nr_amze'];
    }
    $g['min_amze'] = $amzes ? min($amzes) : PHP_INT_MAX;
    $g['max_amze'] = $amzes ? max($amzes) : null;
  }
  unset($g);
  uasort($groups, function($A, $B){ return ($A['min_amze'] <=> $B['min_amze']); });
  ?>

  <?php if ($groups): foreach ($groups as $gid=>$g): ?>
    <?php
      $prefillAmze = [];
      foreach ($g['students'] as $stRow) { $prefillAmze[] = (string)((int)$stRow['nr_amze']); }
      $prefillAmzeStr = implode(', ', $prefillAmze);
      $completed = (int)$g['header']['is_completed'] === 1;
      $minLbl = ($g['min_amze']===PHP_INT_MAX) ? '—' : (string)$g['min_amze'];
      $maxLbl = ($g['max_amze']===null ? '' : '–'.$g['max_amze']);
    ?>
    <div class="card mb-4">
      <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex align-items-center gap-3">
          <h5 class="mb-0">
            <i class="bi bi-collection me-2"></i>
            Grup #<?= (int)$g['header']['group_id'] ?> — <?= htmlspecialchars($g['header']['course_name']) ?>
          </h5>
          <small class="text-muted">AMZË: <?= htmlspecialchars($minLbl.$maxLbl) ?></small>
          <span class="badge <?= $completed ? 'text-bg-success' : 'text-bg-danger' ?> group-badge" data-group="<?= (int)$gid ?>">
            <?= $completed ? 'I përfunduar' : 'Jo i përfunduar' ?>
          </span>
          <div class="form-check form-switch ms-2" title="Ndrysho statusin e përfundimit">
            <input class="form-check-input toggle-completed" type="checkbox"
                   data-group="<?= (int)$gid ?>" <?= $completed ? 'checked' : '' ?> <?= $EDIT_MODE ? '' : 'disabled' ?>>
            <label class="form-check-label small">Përfunduar</label>
          </div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 group-toolbar">
          <button class="btn btn-soft-secondary btn-pill collapse-toggle"
                  data-bs-toggle="collapse"
                  data-bs-target="#gBody_<?= (int)$gid ?>"
                  aria-expanded="false" aria-controls="gBody_<?= (int)$gid ?>">
            <i class="bi bi-chevron-down me-1"></i> Hap/Mbyll
          </button>

          <button class="btn btn-soft-secondary btn-pill"
                  data-bs-toggle="modal" data-bs-target="#editMembersModal_<?= (int)$gid ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>
                  title="Shto/hiq anëtarë (maks 10)">
            <i class="bi bi-people me-1"></i> Anëtarët
          </button>
          <button class="btn btn-soft-secondary btn-pill"
                  data-bs-toggle="modal" data-bs-target="#editCourseModal_<?= (int)$gid ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>
                  title="Ndrysho modulin e grupit">
            <i class="bi bi-book me-1"></i> Moduli
          </button>
          <button class="btn btn-soft-danger btn-pill"
                  data-bs-toggle="modal" data-bs-target="#deleteGroupModal_<?= (int)$gid ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>
                  title="Fshi grupin">
            <i class="bi bi-trash me-1"></i> Fshi
          </button>
        </div>
      </div>

      <div id="gBody_<?= (int)$gid ?>" class="collapse group-body">
        <div class="card-body">

          <div id="statusAlert_<?= (int)$gid ?>" class="status-alert alert <?= $completed ? 'alert-success' : 'alert-danger' ?> py-2 mb-3 small">
            <i class="bi <?= $completed ? 'bi-check-circle' : 'bi-x-octagon' ?> me-1"></i>
            <?= $completed
                  ? 'Ky grup është shënuar si <strong>i përfunduar</strong>. Çdo ndryshim do të kërkojë konfirmim.'
                  : 'Ky grup është <strong>jo i përfunduar</strong>. Vendosni statusin si i përfunduar kur të mbaroni.' ?>
          </div>

          <div class="d-flex flex-wrap align-items-center justify-content-between mb-2 text-muted small">
            <div>
              <span class="me-3">Fillimi:
                <span class="editable cell-inline" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"
                      data-field="start_date" data-group="<?= (int)$g['header']['group_id'] ?>" data-student="0"
                      title="DD-MM-YYYY"><?= htmlspecialchars(fmt_dMY($g['header']['start_date'])) ?></span>
              </span>
              <span class="me-3">Mbarimi:
                <span class="editable cell-inline" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"
                      data-field="end_date" data-group="<?= (int)$g['header']['group_id'] ?>" data-student="0"
                      title="DD-MM-YYYY (≥ data e fillimit)"><?= htmlspecialchars(fmt_dMY($g['header']['end_date'])) ?></span>
              </span>
            </div>
          </div>

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

                  <td class="cell nowrap" data-student="<?= (int)$r['student_id'] ?>" data-group="<?= (int)$gid ?>" data-field="exam_date"
                      title="DD-MM-YYYY (≥ data e mbarimit të grupit)">
                    <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>">
                      <?= htmlspecialchars(fmt_dMY($r['exam_date'])) ?>
                    </span>
                  </td>

                  <td class="cell nowrap" data-student="<?= (int)$r['student_id'] ?>" data-group="<?= (int)$gid ?>" data-field="final_score" title="0–100">
                    <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>">
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
    </div>

    <!-- MODAL: Modifiko anëtarët e grupit -->
    <div class="modal fade" id="editMembersModal_<?= (int)$gid ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <form class="modal-content" method="post" action="groups.php" onsubmit="return confirmIfCompleted(this, <?= (int)$gid ?>)">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
          <input type="hidden" name="action" value="edit_members">
          <input type="hidden" name="group_id" value="<?= (int)$gid ?>">
          <input type="hidden" name="force" value="0">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-people me-1"></i> Modifiko anëtarët — Grup #<?= (int)$gid ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <label class="form-label">AMZË që duhet të jenë në këtë grup (deri në 10)</label>
            <textarea name="amze_spec_members" class="form-control" rows="3" <?= $EDIT_MODE ? '' : 'disabled' ?>
              placeholder="p.sh. 3400-3403, 3409"><?= htmlspecialchars($prefillAmzeStr) ?></textarea>
            <div class="form-text">
              Mund të shtosh ose heqësh AMZË. Nëse shkruan AMZË që s’ekziston, do të krijohet student i ri me të dhëna bosh.
              Kapaciteti maksimal: 10 studentë. <br>
              <strong>Rregull:</strong> i njëjti person (sipas ID personale) nuk mund të jetë dy herë në të njëjtin modul.
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Mbyll</button>
            <button class="btn btn-primary btn-pill" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>>Ruaj ndryshimet</button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL: Ndrysho modulin e grupit -->
    <div class="modal fade" id="editCourseModal_<?= (int)$gid ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <form class="modal-content" method="post" action="groups.php" onsubmit="return confirmIfCompleted(this, <?= (int)$gid ?>)">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
          <input type="hidden" name="action" value="update_group_course">
          <input type="hidden" name="group_id" value="<?= (int)$gid ?>">
          <input type="hidden" name="force" value="0">

          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-book me-1"></i> Ndrysho modulin — Grup #<?= (int)$gid ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">
            <label class="form-label">Zgjidh modul</label>
            <select name="course_id" class="form-select" required <?= $EDIT_MODE ? '' : 'disabled' ?>>
              <?php foreach($courses as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === (int)$g['header']['course_id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Ndryshon modulin (kursin) me të cilin lidhet ky grup.</div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Mbyll</button>
            <button class="btn btn-primary btn-pill" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>>Ruaj</button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL: Fshi grupin -->
    <div class="modal fade" id="deleteGroupModal_<?= (int)$gid ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <form class="modal-content" method="post" action="groups.php" onsubmit="return confirmIfCompleted(this, <?= (int)$gid ?>)">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
          <input type="hidden" name="action" value="delete_group">
          <input type="hidden" name="group_id" value="<?= (int)$gid ?>">
          <input type="hidden" name="force" value="0">
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
            <button type="button" class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Anulo</button>
            <button class="btn btn-danger btn-pill" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>>Po, fshije</button>
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
            <th class="nowrap">Gjendja modulit</th>
            <th class="nowrap">Arsimi</th>
          </tr>
          </thead>
          <tbody>
          <?php if ($noGroup): foreach ($noGroup as $s): ?>
            <tr>
              <td class="nowrap"><?= htmlspecialchars($s['nr_amze']) ?></td>
              <td>
                <div class="fw-semibold">
                  <?= htmlspecialchars(trim(($s['first_name']??'').' '.(($s['father_name']??'')?($s['father_name'].' '):'').($s['last_name']??''))) ?>
                </div>
                <div class="text-muted small"><?= htmlspecialchars($s['personal_number'] ?? '') ?></div>
              </td>
              <td class="nowrap"><?= $s['age'] !== null ? (int)$s['age'] : '—' ?></td>

              <!-- NEW: Gjendja modulit -->
              <td class="nowrap">
                <?php if ((int)($s['planned_count'] ?? 0) > 0): ?>
                  <span class="badge text-bg-primary me-1">Me modul</span>
                  <span class="text-muted small" title="<?= htmlspecialchars((string)$s['planned_first_name']) ?>">
                    <?= htmlspecialchars((string)$s['planned_first_name']) ?>
                    <?php if ((int)$s['planned_count'] > 1): ?>
                      +<?= (int)$s['planned_count'] - 1 ?>
                    <?php endif; ?>
                  </span>
                <?php else: ?>
                  <span class="badge text-bg-secondary">Pa modul</span>
                <?php endif; ?>
              </td>

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

<!-- REPLACE the three floating buttons block with this stack -->
<div class="fab-stack" role="group" aria-label="Veprime shpejta">
  <!-- Toggle All -->
  <button id="toggleAllBtn" class="btn btn-soft-secondary fab-btn" type="button" title="Zgjero/Mbyll të gjitha">
    <i class="bi bi-arrows-angle-expand" id="toggleAllIcon"></i>
    <span class="fab-text" id="toggleAllText">Zgjero të gjitha</span>
  </button>

  <!-- Edit Mode -->
  <a id="editModeFab"
     class="fab-btn btn <?= $EDIT_MODE ? 'btn-success' : 'btn-soft-secondary' ?>"
     href="<?= htmlspecialchars($toggleUrl) ?>"
     title="Ndrysho gjendjen e Edit Mode">
    <i class="bi <?= $EDIT_MODE ? 'bi-unlock' : 'bi-lock' ?>"></i>
    <span class="fab-text">Edit Mode: <?= $EDIT_MODE ? 'ON' : 'OFF' ?></span>
  </a>

  <!-- Create Group (only when Edit Mode is ON) -->
  <?php if ($EDIT_MODE): ?>
  <button class="fab-btn btn btn-primary"
          type="button"
          data-bs-toggle="modal"
          data-bs-target="#createGroupModal"
          title="Krijo grup">
    <i class="bi bi-plus-lg"></i>
    <span class="fab-text">Grup i ri</span>
  </button>
  <?php endif; ?>
</div>

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
                #<?= (int)$gi['id'] ?> — <?= htmlspecialchars($gi['course_name']) ?>
                (<?= htmlspecialchars(fmt_dMY($gi['start_date']).' → '.fmt_dMY($gi['end_date'])) ?>)
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
                #<?= (int)$gi['id'] ?> — <?= htmlspecialchars($gi['course_name']) ?>
                (<?= htmlspecialchars(fmt_dMY($gi['start_date']).' → '.fmt_dMY($gi['end_date'])) ?>)
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
          <button type="button" class="btn btn-soft-success btn-pill" data-dl="xlsx"><i class="bi bi-file-earmark-excel me-1"></i> Excel</button>
          <button type="button" class="btn btn-soft-danger btn-pill" data-dl="pdf"><i class="bi bi-file-earmark-pdf me-1"></i> PDF</button>
          <button type="button" class="btn btn-soft-primary btn-pill" data-dl="docx"><i class="bi bi-file-earmark-word me-1"></i> Word</button>
        </div>
        <button type="button" class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Mbyll</button>
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
          <button type="button" class="btn btn-soft-success btn-pill" data-dl="xlsx"><i class="bi bi-file-earmark-excel me-1"></i> Excel</button>
          <button type="button" class="btn btn-soft-danger btn-pill" data-dl="pdf"><i class="bi bi-file-earmark-pdf me-1"></i> PDF</button>
          <button type="button" class="btn btn-soft-primary btn-pill" data-dl="docx"><i class="bi bi-file-earmark-word me-1"></i> Word</button>
        </div>
        <button type="button" class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Mbyll</button>
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
            <select name="course_id" class="form-select" required <?= $EDIT_MODE ? '' : 'disabled' ?>>
              <option value="">— Zgjidh —</option>
              <?php foreach($courses as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Datë fillimi *</label>
            <input type="text" name="start_date" class="form-control dmy" required
                   placeholder="DD-MM-YYYY" pattern="^\d{2}-\d{2}-\d{4}$" <?= $EDIT_MODE ? '' : 'disabled' ?>>
          </div>

          <div class="col-md-3">
            <label class="form-label">Datë mbarimi *</label>
            <input type="text" name="end_date" class="form-control dmy" required
                   placeholder="DD-MM-YYYY" pattern="^\d{2}-\d{2}-\d{4}$" <?= $EDIT_MODE ? '' : 'disabled' ?>>
          </div>

          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="is_completed" name="is_completed" value="1" <?= $EDIT_MODE ? '' : 'disabled' ?>>
              <label class="form-check-label" for="is_completed">Grupi është i përfunduar</label>
            </div>
            <div class="form-text">Mund ta ndryshosh statusin edhe më vonë nga header-i i grupit.</div>
          </div>

          <div class="col-12">
            <label class="form-label">AMZË për këtë grup (deri në 10)</label>
            <textarea name="amze_spec" class="form-control" rows="2" placeholder="p.sh. 3400-3403, 3409" <?= $EDIT_MODE ? '' : 'disabled' ?>></textarea>
            <div class="form-text">
              Mund të shkruash intervale dhe vlera të ndara me presje. Maksimumi 10 studentë.
              <br><strong>Rregull:</strong> i njëjti person (sipas ID personale) nuk mund ta ndjekë dy herë të njëjtin modul.
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary btn-pill" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>>Krijo grup</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const ENDPOINT = 'groups_inline_update.php';
const EDIT_MODE = <?= $EDIT_MODE ? 'true' : 'false' ?>;

/* Map: groupId -> completed (0/1) për konfirmime */
const GROUP_COMPLETED = <?= json_encode(array_column($groupInfo, 'is_completed', 'id')) ?>;

/* Mapping për hints e Formularit 1 */
const GROUP_AMZE = <?= json_encode(array_column($groupInfo, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;

function clean(s){ return (s||'').replace(/\s+/g,' ').trim(); }

/* Toast helper */
function notify(type, text, opts={}){
  const zone = document.getElementById('toastZone');
  const id = 't' + Date.now() + Math.random().toString(16).slice(2);
  const icons = { success:'check-circle', danger:'exclamation-triangle', warning:'exclamation-circle', info:'info-circle' };
  const icon = icons[type] || 'bell';
  const title = opts.title ?? (
    type==='success' ? 'Sukses' :
    type==='danger'  ? 'Gabim'  :
    type==='warning' ? 'Kujdes' : 'Njoftim'
  );
  const autohide = opts.autohide ?? true;
  const delay = opts.delay ?? 4500;

  const html = `
    <div id="${id}" class="toast qta-toast toast-${type}" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="toast-header">
        <i class="bi bi-${icon} me-2"></i>
        <strong class="me-auto">${title}</strong>
        <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Mbyll"></button>
      </div>
      <div class="toast-body">${text}</div>
          </div>
      <div class="toast-body">${text}</div>
    </div>`;
  zone.insertAdjacentHTML('beforeend', html);
  const el = document.getElementById(id);
  const t = new bootstrap.Toast(el, { autohide, delay });
  el.addEventListener('hidden.bs.toast', ()=> el.remove());
  t.show();
}

/* showMsg wrapper */
function showMsg(type, text){ notify(type, text); }

/* DD-MM-YYYY -> YYYY-MM-DD (vetëm ky format lejohet) */
function normalizeDateForServer(v){
  const s = clean(v);
  if (s === '' || s === '—') return '';
  const m = s.match(/^(\d{2})-(\d{2})-(\d{4})$/);
  if (!m) throw new Error('Formati i datës duhet të jetë DD-MM-YYYY.');
  return `${m[3]}-${m[2]}-${m[1]}`;
}

/* Konfirmim & vendos "force" për modale */
function confirmIfCompleted(formEl, groupId){
  if (!EDIT_MODE) return false;
  const g = String(groupId);
  const inp = formEl.querySelector('input[name="force"]');
  if (GROUP_COMPLETED[g] == 1) {
    if (!confirm('Ky grup është i përfunduar. Jeni i sigurt që doni të vazhdoni?')) {
      if (inp) inp.value = '0';
      return false;
    }
    if (inp) inp.value = '1';
    return true;
  }
  if (inp) inp.value = '0';
  return true;
}

/* Përditëso UI e statusit (badge + alert) */
function updateStatusUI(gid, isCompleted){
  const badge = document.querySelector(`.group-badge[data-group="${gid}"]`);
  if (badge){
    badge.textContent = isCompleted ? 'I përfunduar' : 'Jo i përfunduar';
    badge.className = `badge ${isCompleted ? 'text-bg-success' : 'text-bg-danger'} group-badge`;
  }
  const alertBox = document.getElementById(`statusAlert_${gid}`);
  if (alertBox){
    alertBox.classList.remove('alert-success','alert-danger');
    alertBox.classList.add(isCompleted ? 'alert-success' : 'alert-danger');
    alertBox.innerHTML = isCompleted
      ? `<i class="bi bi-check-circle me-1"></i> Ky grup është shënuar si <strong>i përfunduar</strong>. Çdo ndryshim do të kërkojë konfirmim.`
      : `<i class="bi bi-x-octagon me-1"></i> Ky grup është <strong>jo i përfunduar</strong>. Vendosni statusin si i përfunduar kur të mbaroni.`;
  }
}

/* AJAX helper */
async function saveInline(payload, cell, displayEl, oldVal){
  if (!EDIT_MODE) return;
  try{
    if (cell) cell.classList.add('cell-saving');
    const res = await fetch(ENDPOINT, {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({...payload, csrf: CSRF})
    });
    const json = await res.json();
    if (cell) cell.classList.remove('cell-saving');

    if(!json.ok){
      if(displayEl) displayEl.textContent = oldVal;
      if(cell){ cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'), 1200); }
      showMsg('danger', json.error || 'Gabim i panjohur.');
      return;
    }

    if (json.is_completed !== undefined) {
      const gid = payload.group_id;
      GROUP_COMPLETED[String(gid)] = json.is_completed ? 1 : 0;
      updateStatusUI(gid, !!json.is_completed);
    }

    if(displayEl && json.display !== undefined){
      displayEl.textContent = json.display;
    }
    if(cell){ cell.classList.add('cell-ok'); setTimeout(()=>cell.classList.remove('cell-ok'), 800); }
    notify('success','U ruajt me sukses.');
  }catch(e){
    console.error(e);
    if(displayEl) displayEl.textContent = oldVal;
    if(cell){ cell.classList.remove('cell-saving'); cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'),1200); }
    showMsg('danger','Nuk u krye veprimi. Kontrollo lidhjen ose provo sërish.');
  }
}

/* Maskë DD-MM-YYYY për contenteditable dhe input */
function maskToDDMMYYYY(input) {
  const digits = String(input || '').replace(/\D/g, '').slice(0, 8);
  const d = digits.slice(0, 2);
  const m = digits.slice(2, 4);
  const y = digits.slice(4, 8);
  let out = d;
  if (digits.length > 2) out += '-' + m;
  if (digits.length > 4) out += '-' + y;
  return out;
}
function placeCaretAtEnd(el) {
  const range = document.createRange();
  range.selectNodeContents(el);
  range.collapse(false);
  const sel = window.getSelection();
  sel.removeAllRanges();
  sel.addRange(range);
}
function attachDateMaskContentEditable(el) {
  el.addEventListener('input', () => {
    const masked = maskToDDMMYYYY(el.textContent);
    if (el.textContent !== masked) {
      el.textContent = masked;
      placeCaretAtEnd(el);
    }
  });
  el.addEventListener('paste', (e) => {
    e.preventDefault();
    const txt = (e.clipboardData || window.clipboardData).getData('text');
    el.textContent = maskToDDMMYYYY(txt);
    placeCaretAtEnd(el);
  });
}
function applyDmyMask(el){
  el.addEventListener('input', ()=>{
    let v = el.value.replace(/[^\d]/g,'').slice(0,8);
    if (v.length >= 5) v = v.slice(0,2)+'-'+v.slice(2,4)+'-'+v.slice(4);
    else if (v.length >= 3) v = v.slice(0,2)+'-'+v.slice(2);
    el.value = v;
  });
}

/* Toggle Completed (AJAX) */
document.querySelectorAll('.toggle-completed').forEach(chk=>{
  chk.addEventListener('change', ()=>{
    if (!EDIT_MODE) { chk.checked = !chk.checked; return; }
    const gid = parseInt(chk.dataset.group,10);
    const want = chk.checked ? 1 : 0;
    if (GROUP_COMPLETED[String(gid)] == 1 && want === 0) {
      if (!confirm('Ky grup është i përfunduar. Dëshiron të ndryshosh statusin?')) { chk.checked = true; return; }
    }
    saveInline({action:'set_group_completed', group_id:gid, is_completed:want, force:1}, null, null, null);
  });
});

/* Inline në header-in e grupit (start/end) */
document.querySelectorAll('.cell-inline.editable').forEach(el=>{
  let oldVal = el.textContent;
  if (!EDIT_MODE) el.setAttribute('contenteditable','false');

  /* maskë për datë */
  attachDateMaskContentEditable(el);

  el.addEventListener('focus', ()=>{ oldVal = el.textContent; });
  el.addEventListener('keydown', ev=>{ if(ev.key==='Enter'){ ev.preventDefault(); el.blur(); }});
  el.addEventListener('blur', ()=>{
    if (!EDIT_MODE) return;
    const gid = parseInt(el.dataset.group,10);
    const field = el.dataset.field;
    let newVal = clean(el.textContent);
    if(newVal===clean(oldVal)) return;

    if(['start_date','end_date'].includes(field)){
      try { newVal = normalizeDateForServer(newVal); }
      catch(err){ el.textContent = oldVal; el.classList.add('cell-err'); setTimeout(()=>el.classList.remove('cell-err'),1200); showMsg('danger', err.message || err); return; }
      const payload = {action:(field==='start_date'?'update_group_start':'update_group_end'),
                       student_id:0, group_id:gid, [field]:(newVal===''?null:newVal)};
      if (GROUP_COMPLETED[String(gid)] == 1) { if (!confirm('Ky grup është i përfunduar. Jeni i sigurt?')) { el.textContent = oldVal; return; } payload.force = 1; }
      saveInline(payload, null, el, oldVal);
    }
  });
});

/* Inline per student: exam_date & final_score */
document.querySelectorAll('td.cell .editable').forEach(el=>{
  let oldVal = el.textContent;
  if (!EDIT_MODE) el.setAttribute('contenteditable','false');

  /* maskë për exam_date */
  const cell = el.closest('td.cell');
  if (cell && cell.dataset.field === 'exam_date') attachDateMaskContentEditable(el);

  el.addEventListener('focus', ()=>{ oldVal = el.textContent; });
  el.addEventListener('keydown', ev=>{ if(ev.key==='Enter'){ ev.preventDefault(); el.blur(); }});
  el.addEventListener('blur', ()=>{
    if (!EDIT_MODE) return;

    const cell = el.closest('td.cell');
    const field = cell.dataset.field;
    const sid = parseInt(cell.dataset.student,10);
    const gid = parseInt(cell.dataset.group,10);
    let newVal = clean(el.textContent);
    if(newVal===clean(oldVal)) return;

    if(field==='exam_date'){
      try { newVal = normalizeDateForServer(newVal); }
      catch(err){ el.textContent = oldVal; cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'),1200); showMsg('danger', err.message || err); return; }
      const payload = {action:'update_student_exam_date', student_id:sid, group_id:gid, exam_date:(newVal===''?null:newVal)};
      if (GROUP_COMPLETED[String(gid)] == 1) { if (!confirm('Ky grup është i përfunduar. Jeni i sigurt?')) { el.textContent = oldVal; return; } payload.force = 1; }
      saveInline(payload, cell, el, oldVal);
      return;
    }

    if(field==='final_score'){
      if(newVal===''){
        const payload = {action:'update_final_score', student_id:sid, group_id:gid, final_score:null};
        if (GROUP_COMPLETED[String(gid)] == 1) { if (!confirm('Ky grup është i përfunduar. Jeni i sigurt?')) { el.textContent = oldVal; return; } payload.force = 1; }
        saveInline(payload, cell, el, oldVal);
        return;
      }
      const n = newVal.replace(',','.');
      if(isNaN(n)){ el.textContent = oldVal; cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'),1200); showMsg('danger','Nota duhet të jetë numër.'); return; }
      const payload = {action:'update_final_score', student_id:sid, group_id:gid, final_score:n};
      if (GROUP_COMPLETED[String(gid)] == 1) { if (!confirm('Ky grup është i përfunduar. Jeni i sigurt?')) { el.textContent = oldVal; return; } payload.force = 1; }
      saveInline(payload, cell, el, oldVal);
      return;
    }
  });
});

/* Hints për Formularin 1 */
function updateHint(selId, hintId) {
  const v = document.getElementById(selId)?.value;
  const h = document.getElementById(hintId);
  if (!v || !GROUP_AMZE[v]) { if(h) h.textContent='(AMZË: —)'; return; }
  const mi = GROUP_AMZE[v]['amze_min'];
  const ma = GROUP_AMZE[v]['amze_max'];
  if (h) h.textContent = (mi === null || ma === null) ? '(AMZË: —)' : `(AMZË: ${mi} – ${ma})`;
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

/* Maskë për input-et DD-MM-YYYY në modal "Krijo grup" */
document.querySelectorAll('input.dmy').forEach(applyDmyMask);
const createForm = document.querySelector('#createGroupModal form');
if (createForm){
  createForm.addEventListener('submit', (ev)=>{
    const s = createForm.querySelector('input[name="start_date"]');
    const e = createForm.querySelector('input[name="end_date"]');
    try{
      s.value = normalizeDateForServer(s.value);
      e.value = normalizeDateForServer(e.value);
    }catch(err){
      ev.preventDefault();
      notify('danger', err.message || 'Formati i datës duhet të jetë DD-MM-YYYY.');
    }
  });
}

/* Accordion state me localStorage + ToggleAll i vetëm */
const OPEN_KEY = 'qta_groups_open';
function getOpenSet(){
  try{ return new Set(JSON.parse(localStorage.getItem(OPEN_KEY) || '[]').map(String)); }
  catch{ return new Set(); }
}
function saveOpenSet(set){
  localStorage.setItem(OPEN_KEY, JSON.stringify(Array.from(set)));
}
function areAllOpen(){
  const bodies = document.querySelectorAll('.group-body.collapse');
  const open = Array.from(bodies).filter(b=>b.classList.contains('show')).length;
  return open === bodies.length && bodies.length>0;
}
function updateToggleAllBtn(){
  const btn = document.getElementById('toggleAllBtn');
  const icon = document.getElementById('toggleAllIcon');
  const txt = document.getElementById('toggleAllText');
  if (!btn || !icon || !txt) return;
  if (areAllOpen()){
    icon.className = 'bi bi-arrows-angle-contract me-1';
    txt.textContent = 'Mbyll të gjitha';
  } else {
    icon.className = 'bi bi-arrows-angle-expand me-1';
    txt.textContent = 'Zgjero të gjitha';
  }
}

document.addEventListener('DOMContentLoaded', ()=>{
  // compact mode
  document.body.classList.add('compact');

  // accordion restore
  const open = getOpenSet();
  document.querySelectorAll('.group-body.collapse').forEach(el=>{
    const gid = (el.id || '').replace('gBody_','');
    const inst = new bootstrap.Collapse(el, { toggle:false });
    if (open.has(String(gid))) inst.show();

    el.addEventListener('shown.bs.collapse', ()=>{
      open.add(String(gid)); saveOpenSet(open); updateToggleAllBtn();
      const btn = document.querySelector(`.collapse-toggle[data-bs-target="#${el.id}"]`);
      if (btn) btn.setAttribute('aria-expanded','true');
    });
    el.addEventListener('hidden.bs.collapse', ()=>{
      open.delete(String(gid)); saveOpenSet(open); updateToggleAllBtn();
      const btn = document.querySelector(`.collapse-toggle[data-bs-target="#${el.id}"]`);
      if (btn) btn.setAttribute('aria-expanded','false');
    });
  });
  updateToggleAllBtn();

  // ToggleAll floating button
  const toggleAll = document.getElementById('toggleAllBtn');
  if (toggleAll){
    toggleAll.addEventListener('click', ()=>{
      const allBodies = document.querySelectorAll('.group-body.collapse');
      const wantOpen = !areAllOpen();
      allBodies.forEach(el=>{
        new bootstrap.Collapse(el, { toggle:false })[wantOpen ? 'show' : 'hide']();
        const gid = (el.id || '').replace('gBody_','');
        const set = getOpenSet();
        if (wantOpen) set.add(String(gid)); else set.delete(String(gid));
        saveOpenSet(set);
      });
      updateToggleAllBtn();
    });
  }
});

<?php if ($flash_ok): ?>
document.addEventListener('DOMContentLoaded',()=>notify('success', <?= json_encode($flash_ok) ?>));
<?php endif; ?>
<?php if ($flash_err): ?>
document.addEventListener('DOMContentLoaded',()=>notify('danger', <?= json_encode($flash_err) ?>));
<?php endif; ?>
</script>
</body>
</html>

