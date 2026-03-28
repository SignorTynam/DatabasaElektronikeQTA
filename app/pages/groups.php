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

/* =========================
   POST: create/edit/update/delete
   (me politika të reja:
    - kur shtojmë studentë në grup => FSHIJMË çdo 'planned' për ta
    - ndalim që personi ta ndjekë të njëjtin modul dy herë)
========================= */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';

  if (!$EDIT_MODE) {
    $_SESSION['flash_err'] = 'Edit Mode është OFF. Aktivizo për të bërë ndryshime.';
    header('Location: groups.php'); exit;
  }
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf'])) {
    http_response_code(400); $_SESSION['flash_err'] = 'CSRF token mismatch.'; header('Location: groups.php'); exit;
  }

  /* ===== Krijo grup (me ndarje inteligjente >10) ===== */
    /* ===== Krijo grup (me ndarje inteligjente >10) ===== */
  /* ===== Krijo grup (me ndarje inteligjente >10) ===== */
  if ($action==='create_group') {
    try {
      $course_id    = (int)($_POST['course_id'] ?? 0);
      $start_date   = dmy_to_iso((string)($_POST['start_date'] ?? ''));
      $end_date     = dmy_to_iso((string)($_POST['end_date'] ?? ''));
      $amze_spec    = trim((string)($_POST['amze_spec'] ?? ''));
      $is_completed = isset($_POST['is_completed']) && $_POST['is_completed'] == '1' ? 1 : 0;

      if ($course_id <= 0) {
        throw new RuntimeException('Zgjidh një modul.');
      }
      if (!$start_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
        throw new RuntimeException('Data e fillimit duhet në formatin DD-MM-YYYY.');
      }
      if (!$end_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        throw new RuntimeException('Data e mbarimit duhet në formatin DD-MM-YYYY.');
      }
      if ($end_date < $start_date) {
        throw new RuntimeException('Data e mbarimit duhet të jetë ≥ datës së fillimit.');
      }

      // ---------------------------------------------------
      // Përgatit listën e AMZË-ve -> student IDs (të renditura sipas AMZË)
      // ---------------------------------------------------
      $amzeList   = [];
      $studentIds = [];

      if ($amze_spec !== '') {
        $nums = parseAmzeRanges($amze_spec); // tashmë të renditura dhe unike
        if (!$nums) {
          throw new RuntimeException('Nuk u gjet asnjë AMZË e vlefshme.');
        }

        $amzeToStudent = [];
        foreach ($nums as $n) {
          // siguro studentin për çdo AMZË
          $amzeToStudent[$n] = ensureStudentByAmze($pdo, $studentRoleId, $maleGenderId, $n);
        }

        // garanto renditjen sipas numrit të AMZË-s (edhe nëse dicka ndryshon më vonë te parseAmzeRanges)
        ksort($amzeToStudent, SORT_NUMERIC);
        $amzeList   = array_keys($amzeToStudent);   // [1000,1001,...]
        $studentIds = array_values($amzeToStudent); // [sid1000, sid1001,...] në të njëjtin rend
      }

      // ---------------------------------------------------
      // NEW: Nëse këto AMZË janë tashmë në ÇFARËDO grupi, ndalo krijimin
      // ---------------------------------------------------
      if ($studentIds) {
        $phDup   = implode(',', array_fill(0, count($studentIds), '?'));
        $dupStmt = $pdo->prepare("
          SELECT DISTINCT
            s.nr_amze,
            cg.id   AS group_id,
            c.name  AS course_name
          FROM course_group_students cgs
          JOIN students      s  ON s.id  = cgs.student_id
          JOIN course_groups cg ON cg.id = cgs.group_id
          JOIN courses       c  ON c.id  = cg.course_id
          WHERE cgs.student_id IN ($phDup)
        ");
        $dupStmt->execute($studentIds);
        $dupRows = $dupStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($dupRows) {
          $items = array_map(
            fn($r) => $r['nr_amze'] . ' (Grup #' . $r['group_id'] . ', ' . $r['course_name'] . ')',
            $dupRows
          );
          throw new RuntimeException(
            'Procesi u ndërpre: këto AMZË janë tashmë pjesë e një grupi: ' . implode(', ', $items)
          );
        }
      }

      // Nëse s’ka studentë fare => krijo vetëm grup bosh
      if (!$studentIds) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("
          INSERT INTO course_groups (course_id, start_date, end_date, is_completed)
          VALUES (:c,:s,:e,:ic)
        ");
        $st->execute([
          ':c'  => $course_id,
          ':s'  => $start_date,
          ':e'  => $end_date,
          ':ic' => $is_completed
        ]);
        $gid = (int)$pdo->lastInsertId();
        $pdo->commit();

        qta_audit_event('group.create', [
          'group_id'      => $gid,
          'course_id'     => $course_id,
          'start_date'    => $start_date,
          'end_date'      => $end_date,
          'is_completed'  => $is_completed,
          'actor_user_id' => $_SESSION['user_id'] ?? null
        ]);

        $_SESSION['flash_ok'] = 'Grupi u krijua me sukses (pa studentë).';
        header('Location: groups.php');
        exit;
      }

      // ---------------------------------------------------
      // Ndalim: sipas personal_number që të mos kenë ndjekur më parë po këtë modul
      // ---------------------------------------------------
      $phIds  = implode(',', array_fill(0, count($studentIds), '?'));
      $pnStmt = $pdo->prepare("
        SELECT DISTINCT p.personal_number
        FROM students s
        JOIN persons  p ON p.id = s.person_id
        WHERE s.id IN ($phIds)
          AND p.personal_number IS NOT NULL
          AND p.personal_number <> ''
      ");
      $pnStmt->execute($studentIds);
      $pnList = $pnStmt->fetchAll(PDO::FETCH_COLUMN);

      if ($pnList) {
        $phPn   = implode(',', array_fill(0, count($pnList), '?'));
        $confPN = $pdo->prepare("
          SELECT DISTINCT
            p.personal_number,
            s.nr_amze,
            cg.id   AS group_id,
            c.name  AS course_name
          FROM course_group_students cgs
          JOIN students      s  ON s.id  = cgs.student_id
          JOIN persons       p  ON p.id  = s.person_id
          JOIN course_groups cg ON cg.id = cgs.group_id
          JOIN courses       c  ON c.id  = cg.course_id
          WHERE cg.course_id = ?
            AND p.personal_number IN ($phPn)
        ");
        $confPN->execute([$course_id, ...$pnList]);
        $hitPN = $confPN->fetchAll(PDO::FETCH_ASSOC);

        if ($hitPN) {
          $items = array_map(
            fn($r) => ($r['nr_amze'] ?: $r['personal_number']) . ' (Grup #' . $r['group_id'] . ', ' . $r['course_name'] . ')',
            $hitPN
          );
          throw new RuntimeException(
            'Disa persona (sipas ID personale) e kanë ndjekur tashmë këtë modul: ' . implode(', ', $items)
          );
        }
      }

      // ---------------------------------------------------
      // Fshi "planned" për këta studentë përpara regjistrimit në grupe
      // ---------------------------------------------------
      $pdo->prepare("
        DELETE FROM student_course_plans
        WHERE status = 'planned'
          AND student_id IN ($phIds)
      ")->execute($studentIds);

      // ---------------------------------------------------
      // Ndarja inteligjente në grupe (kapacitet 10, duke ruajtur rendin e AMZË-ve)
      // ---------------------------------------------------
      $total        = count($studentIds);
      $groupsNeeded = (int)ceil($total / 10);
      $base         = (int)floor($total / $groupsNeeded);
      $rem          = $total % $groupsNeeded; // grupet e para 'rem' do kenë (base+1) studentë

      $chunks = [];
      $cursor = 0;

      for ($g = 0; $g < $groupsNeeded; $g++) {
        $size = $base + ($g < $rem ? 1 : 0);
        if ($size <= 0) {
          continue;
        }

        // gjithmonë segmente të VAZHDUESHME sipas rendit të AMZË-ve
        $chunkIds  = array_slice($studentIds, $cursor, $size);
        $chunkAmze = array_slice($amzeList,   $cursor, $size);
        $cursor   += $size;

        if (!$chunkIds) {
          continue;
        }

        $chunks[] = [
          'ids'      => $chunkIds,
          'amze_min' => $chunkAmze ? min($chunkAmze) : null,
          'amze_max' => $chunkAmze ? max($chunkAmze) : null,
        ];
      }

      $pdo->beginTransaction();

      $created = []; // [[group_id=>.., count=>.., amze_min=>.., amze_max=>..], ...]
      foreach ($chunks as $chunkInfo) {
        $chunk = $chunkInfo['ids'];
        if (!$chunk) {
          continue;
        }
        if (count($chunk) > 10) {
          throw new RuntimeException('Ndërprerë: ndarje e pasaktë (>10).');
        }

        $st = $pdo->prepare("
          INSERT INTO course_groups (course_id, start_date, end_date, is_completed)
          VALUES (:c,:s,:e,:ic)
        ");
        $st->execute([
          ':c'  => $course_id,
          ':s'  => $start_date,
          ':e'  => $end_date,
          ':ic' => $is_completed
        ]);
        $gid = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("
          INSERT INTO course_group_students (group_id, student_id)
          VALUES (:g,:s)
        ");
        foreach ($chunk as $sid) {
          $ins->execute([':g' => $gid, ':s' => $sid]);
        }

        $created[] = [
          'group_id' => $gid,
          'count'    => count($chunk),
          'amze_min' => $chunkInfo['amze_min'],
          'amze_max' => $chunkInfo['amze_max'],
        ];

        // AUDIT per grup
        qta_audit_event('group.create', [
          'group_id'      => $gid,
          'course_id'     => $course_id,
          'start_date'    => $start_date,
          'end_date'      => $end_date,
          'is_completed'  => $is_completed,
          'actor_user_id' => $_SESSION['user_id'] ?? null
        ]);
      }

      $pdo->commit();

      // Mesazh më i pasur (me range AMZË-sh)
      if (count($created) === 1) {
        $c     = $created[0];
        $range = ($c['amze_min'] !== null)
          ? ' (AMZË ' . $c['amze_min'] . ($c['amze_max'] && $c['amze_max'] !== $c['amze_min'] ? '–' . $c['amze_max'] : '') . ')'
          : '';
        $_SESSION['flash_ok'] = 'Grupi u krijua me sukses. (' . $c['count'] . ' studentë)' . $range;
      } else {
        $parts = array_map(function ($r) {
          $range = ($r['amze_min'] !== null)
            ? ' [' . $r['amze_min'] . ($r['amze_max'] && $r['amze_max'] !== $r['amze_min'] ? '–' . $r['amze_max'] : '') . ']'
            : '';
          return '#' . $r['group_id'] . ' (' . $r['count'] . ')' . $range;
        }, $created);
        $_SESSION['flash_ok'] = 'U krijuan ' . count($created) . ' grupe: ' . implode(', ', $parts);
      }

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $_SESSION['flash_err'] = $e->getMessage();
    }

    header('Location: groups.php');
    exit;
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

      // Mbledh anëtarët aktualë
      $members = $pdo->prepare("SELECT student_id FROM course_group_students WHERE group_id=:g");
      $members->execute([':g'=>$group_id]);
      $toCheck = $members->fetchAll(PDO::FETCH_COLUMN, 0);

      if ($toCheck) {
        // Kontroll sipas personal_number: të mos rezultojnë se e kanë ndjekur tashmë modul të ri
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
            throw new RuntimeException('Ndërrimi i modulit s’lejohet: disa persona e kanë ndjekur tashmë këtë modul: '.implode(', ', $items));
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
    $group_id          = (int)($_POST['group_id'] ?? 0);
    $amze_spec_members = trim((string)($_POST['amze_spec_members'] ?? ''));
    $force             = (int)($_POST['force'] ?? 0);

    try {
      if ($group_id <= 0) {
        throw new RuntimeException('Grup i pavlefshëm.');
      }

      // Marrim të dhënat bazë të grupit (për kopjim në grupet e reja)
      $gRow = $pdo->prepare("
        SELECT is_completed, course_id, start_date, end_date
        FROM course_groups
        WHERE id = :g
      ");
      $gRow->execute([':g' => $group_id]);
      $gRowData     = $gRow->fetch(PDO::FETCH_ASSOC);
      $is_completed = (int)($gRowData['is_completed'] ?? 0);
      $gidCourseId  = (int)($gRowData['course_id'] ?? 0);

      if (!$gRowData) {
        throw new RuntimeException('Grupi nuk u gjet.');
      }

      if ($is_completed && !$force) {
        throw new RuntimeException('Ky grup është i përfunduar. Konfirmo ndryshimin.');
      }

      // Anëtarët ekzistues të grupit
      $q = $pdo->prepare("
        SELECT
          s.id AS student_id,
          CAST(s.nr_amze AS UNSIGNED) AS amznum,
          cgs.exam_date,
          cgs.final_score
        FROM course_group_students cgs
        JOIN students s ON s.id = cgs.student_id
        WHERE cgs.group_id = :gid
        ORDER BY amznum
      ");
      $q->execute([':gid' => $group_id]);
      $existing = $q->fetchAll(PDO::FETCH_ASSOC);

      $existMap = [];          // amznum => student_id
      $existingCgs = [];       // student_id => ['exam_date'=>..., 'final_score'=>...]

      foreach ($existing as $row) {
        if ($row['amznum'] === null) continue;
        $sid = (int)$row['student_id'];
        $existMap[(int)$row['amznum']] = $sid;
        $existingCgs[$sid] = [
          'exam_date'   => $row['exam_date'],
          'final_score' => $row['final_score'],
        ];
      }

      // Target AMZË nga input-i
      $targetNums = ($amze_spec_members === '') ? [] : parseAmzeRanges($amze_spec_members);

      $targetMap = [];
      foreach ($targetNums as $n) {
        $targetMap[$n] = ensureStudentByAmze($pdo, $studentRoleId, $maleGenderId, $n);
      }

      // Llogarisim cilët hiqen dhe cilët shtohen (por nuk veprojmë ende)
      $toRemove = [];
      foreach ($existMap as $amz => $sid) {
        if (!array_key_exists($amz, $targetMap)) {
          $toRemove[] = $sid;
        }
      }

      $toAdd = [];
      foreach ($targetMap as $amz => $sid) {
        if (!array_key_exists($amz, $existMap)) {
          $toAdd[] = $sid;
        }
      }

      $totalTarget = count($targetMap);

      // Nëse nuk ka fare target -> thjesht boshatis grupin (por pa krijuar të rinj)
      if ($totalTarget === 0) {
        $pdo->beginTransaction();

        // Fshijmë të gjithë anëtarët aktualë të grupit
        $pdo->prepare("
          DELETE FROM course_group_students
          WHERE group_id = :g
        ")->execute([':g' => $group_id]);

        $pdo->commit();

        qta_audit_event('group.update_members', [
          'group_id'      => $group_id,
          'added'         => [],
          'removed'       => $toRemove,
          'actor_user_id' => $_SESSION['user_id'] ?? null
        ]);

        $_SESSION['flash_ok'] = 'Grupi u boshatis.';
        header('Location: groups.php');
        exit;
      }

      $pdo->beginTransaction();

      if ($toAdd) {
        // ---------------------------------------------------
        // Ndalim: studentët në $toAdd të mos jenë tashmë në ASNJË grup tjetër
        // ---------------------------------------------------
        $ph    = implode(',', array_fill(0, count($toAdd), '?'));
        $confQ = $pdo->prepare("
          SELECT
            s.nr_amze,
            cg.id   AS group_id,
            c.name  AS course_name
          FROM course_group_students cgs
          JOIN course_groups cg ON cg.id = cgs.group_id
          JOIN courses      c  ON c.id = cg.course_id
          JOIN students     s  ON s.id = cgs.student_id
          WHERE cgs.student_id IN ($ph)
        ");
        $confQ->execute($toAdd);
        $conf = $confQ->fetchAll(PDO::FETCH_ASSOC);

        if ($conf) {
          $items = array_map(
            fn($r) => $r['nr_amze'] . ' (Grup #' . $r['group_id'] . ', ' . $r['course_name'] . ')',
            $conf
          );
          throw new RuntimeException(
            'Procesi u ndërpre: këta studentë janë tashmë pjesë e një grupi tjetër: ' . implode(', ', $items)
          );
        }

        // ---------------------------------------------------
        // Kontroll sipas personal_number (ndalim që i njëjti person ta ndjekë modul dy herë)
        // ---------------------------------------------------
        $phIds  = implode(',', array_fill(0, count($toAdd), '?'));
        $pnStmt = $pdo->prepare("
          SELECT DISTINCT p.personal_number
          FROM students s
          JOIN persons  p ON p.id = s.person_id
          WHERE s.id IN ($phIds)
            AND p.personal_number IS NOT NULL
            AND p.personal_number <> ''
        ");
        $pnStmt->execute($toAdd);
        $pnList = $pnStmt->fetchAll(PDO::FETCH_COLUMN);

        if ($pnList) {
          $phPn   = implode(',', array_fill(0, count($pnList), '?'));
          $confPN = $pdo->prepare("
            SELECT DISTINCT
              p.personal_number,
              s.nr_amze,
              cg.id   AS group_id,
              c.name  AS course_name
            FROM course_group_students cgs
            JOIN students      s  ON s.id  = cgs.student_id
            JOIN persons       p  ON p.id  = s.person_id
            JOIN course_groups cg ON cg.id = cgs.group_id
            JOIN courses       c  ON c.id  = cg.course_id
            WHERE cg.course_id = ?
              AND p.personal_number IN ($phPn)
          ");
          $confPN->execute([$gidCourseId, ...$pnList]);
          $hitPN = $confPN->fetchAll(PDO::FETCH_ASSOC);

          if ($hitPN) {
            $items = array_map(
              fn($r) => ($r['nr_amze'] ?: $r['personal_number']) .
                        ' (Grup #' . $r['group_id'] . ', ' . $r['course_name'] . ')',
              $hitPN
            );
            throw new RuntimeException(
              'Disa persona (sipas ID personale) e kanë ndjekur tashmë këtë modul: ' . implode(', ', $items)
            );
          }
        }

        // ---------------------------------------------------
        // RREGULL: një AMZË s’mund të jetë njëkohësisht "me modul (plan)" dhe "në grup"
        // ---------------------------------------------------
        $pdo->prepare("
          DELETE FROM student_course_plans
          WHERE status = 'planned'
            AND student_id IN ($ph)
        ")->execute($toAdd);
      }

      // Tani kemi targetMap si lista përfundimtare e studentëve të grupit (mund të jenë > 10)

      if ($totalTarget <= 10) {
        // === Rast klasik: maksimumi 10 studentë në këtë grup ===

        // Hiq anëtarët që s’janë më
        if ($toRemove) {
          $del = $pdo->prepare("
            DELETE FROM course_group_students
            WHERE group_id = :g
              AND student_id = :s
          ");
          foreach ($toRemove as $sid) {
            $del->execute([':g' => $group_id, ':s' => $sid]);
          }
        }

        // Shto anëtarët e rinj
        if ($toAdd) {
          $ins = $pdo->prepare("
            INSERT INTO course_group_students (group_id, student_id)
            VALUES (:g,:s)
          ");
          foreach ($toAdd as $sid) {
            $ins->execute([':g' => $group_id, ':s' => $sid]);
          }
        }

        $pdo->commit();

        qta_audit_event('group.update_members', [
          'group_id'      => $group_id,
          'added'         => $toAdd,
          'removed'       => $toRemove,
          'actor_user_id' => $_SESSION['user_id'] ?? null
        ]);

        $_SESSION['flash_ok'] = 'Anëtarët e grupit u përditësuan.';
      } else {
          // === >10 studentë -> ndahet automatikisht në grupe të balancuara (diff max 1) ===

          // Rendit sipas AMZË
          $amzeList = array_map('intval', array_keys($targetMap));
          sort($amzeList, SORT_NUMERIC);

          $studentIdsOrdered = [];
          foreach ($amzeList as $n) {
            $studentIdsOrdered[] = (int)$targetMap[$n];
          }

          // Siguri: mos lejo student_id duplikat (në rast anomalish në DB)
          if (count($studentIdsOrdered) !== count(array_unique($studentIdsOrdered))) {
            throw new RuntimeException('Procesi u ndërpre: u gjetën studentë të duplikuar në listë.');
          }

          $total        = count($studentIdsOrdered);
          $groupsNeeded = (int)ceil($total / 10);
          if ($groupsNeeded < 2) $groupsNeeded = 2;

          $base = (int)floor($total / $groupsNeeded);
          $rem  = $total % $groupsNeeded;

          // Përgatit chunk-e të vazhdueshme sipas AMZË
          $chunks = [];
          $cursor = 0;
          for ($gIndex = 0; $gIndex < $groupsNeeded; $gIndex++) {
            $size = $base + ($gIndex < $rem ? 1 : 0);
            if ($size <= 0) continue;

            $chunkIds  = array_slice($studentIdsOrdered, $cursor, $size);
            $chunkAmze = array_slice($amzeList,          $cursor, $size);
            $cursor   += $size;

            if (!$chunkIds) continue;
            if (count($chunkIds) > 10) {
              throw new RuntimeException('Ndërprerë: ndarje e pasaktë (>10 në një grup).');
            }

            $chunks[] = [
              'ids'      => $chunkIds,
              'amze_min' => $chunkAmze ? min($chunkAmze) : null,
              'amze_max' => $chunkAmze ? max($chunkAmze) : null,
            ];
          }

          if (!$chunks) {
            throw new RuntimeException('Ndërprerë: nuk u arrit të ndahen anëtarët në grupe.');
          }

          // Fshi vetëm ata që po HIQEN (jo të gjithë) – që të mos humbasin exam_date/final_score për të tjerët
          if ($toRemove) {
            $del = $pdo->prepare("
              DELETE FROM course_group_students
              WHERE group_id = :g AND student_id = :s
            ");
            foreach ($toRemove as $sid) {
              $del->execute([':g' => $group_id, ':s' => (int)$sid]);
            }
          }

          // Krijo grupet e reja (për chunk-et > 0)
          $created = [];
          $insGroupStmt = $pdo->prepare("
            INSERT INTO course_groups (course_id, start_date, end_date, is_completed)
            VALUES (:c,:s,:e,:ic)
          ");

          $newGroupIds = []; // index => gid
          foreach ($chunks as $idx => $chunkInfo) {
            if ($idx === 0) {
              $newGroupIds[$idx] = $group_id; // grupi ekzistues mban chunk-un e parë
              continue;
            }

            $insGroupStmt->execute([
              ':c'  => $gidCourseId,
              ':s'  => $gRowData['start_date'],
              ':e'  => $gRowData['end_date'],
              ':ic' => $is_completed
            ]);
            $gidNew = (int)$pdo->lastInsertId();
            $newGroupIds[$idx] = $gidNew;

            qta_audit_event('group.create', [
              'group_id'          => $gidNew,
              'course_id'         => $gidCourseId,
              'start_date'        => $gRowData['start_date'],
              'end_date'          => $gRowData['end_date'],
              'is_completed'      => $is_completed,
              'actor_user_id'     => $_SESSION['user_id'] ?? null,
              'source_split_from' => $group_id
            ]);
          }

          // Prepared statements: INSERT dhe UPDATE për “zhvendosje”
          $insMember = $pdo->prepare("
            INSERT INTO course_group_students (group_id, student_id)
            VALUES (:g,:s)
          ");

          $moveMember = $pdo->prepare("
            UPDATE course_group_students
            SET group_id = :newg
            WHERE group_id = :oldg AND student_id = :s
          ");

          // Aplikojmë chunk-et:
          // - Nëse studenti ishte në këtë grup => ose rri, ose zhvendoset (pa humbje exam_date/final_score)
          // - Nëse është i ri => INSERT në grupin përkatës
          foreach ($chunks as $idx => $chunkInfo) {
            $gidUse = (int)$newGroupIds[$idx];

            foreach ($chunkInfo['ids'] as $sid) {
              $sid = (int)$sid;

              if (isset($existingCgs[$sid])) {
                // ishte në grupin origjinal
                if ($gidUse !== $group_id) {
                  $moveMember->execute([':newg' => $gidUse, ':oldg' => $group_id, ':s' => $sid]);
                }
              } else {
                // student i ri (nuk ishte në këtë grup)
                $insMember->execute([':g' => $gidUse, ':s' => $sid]);
              }
            }

            $created[] = [
              'group_id' => $gidUse,
              'count'    => count($chunkInfo['ids']),
              'amze_min' => $chunkInfo['amze_min'],
              'amze_max' => $chunkInfo['amze_max'],
            ];
          }

          $pdo->commit();

          qta_audit_event('group.update_members', [
            'group_id'      => $group_id,
            'added'         => $toAdd,
            'removed'       => $toRemove,
            'actor_user_id' => $_SESSION['user_id'] ?? null,
            'split_created' => array_values(array_filter($newGroupIds, fn($x)=> (int)$x !== (int)$group_id)),
            'split_summary' => $created
          ]);

          // === Multi-toasts (një toast për çdo veprim) ===
          $_SESSION['flash_ok_list'] = $_SESSION['flash_ok_list'] ?? [];

          $_SESSION['flash_ok_list'][] = 'Grupi #' . $group_id . ' u nda në ' . count($created) . ' grupe (balancim automatik).';

          foreach ($created as $r) {
            $range = ($r['amze_min'] !== null)
              ? ' [AMZË ' . $r['amze_min'] . ($r['amze_max'] && $r['amze_max'] !== $r['amze_min'] ? '–' . $r['amze_max'] : '') . ']'
              : '';
            $_SESSION['flash_ok_list'][] = 'Grupi #' . $r['group_id'] . ' u formua me ' . $r['count'] . ' studentë' . $range . '.';
          }

          if ($toAdd) {
            $_SESSION['flash_ok_list'][] = 'U shtuan ' . count($toAdd) . ' studentë të rinj në ndarje.';
          }
          if ($toRemove) {
            $_SESSION['flash_ok_list'][] = 'U hoqën ' . count($toRemove) . ' studentë nga grupi.';
          }
        }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $_SESSION['flash_err'] = $e->getMessage();
    }

    header('Location: groups.php');
    exit;
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

/* Studentë pa grup (për tabelën poshtë) */
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
  ORDER BY
    (MIN(CAST(s.nr_amze AS UNSIGNED)) IS NULL) ASC,
    MIN(CAST(s.nr_amze AS UNSIGNED)) ASC,
    cg.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* Flash mesazhe */
$flash_ok_list  = $_SESSION['flash_ok_list']  ?? [];
$flash_err_list = $_SESSION['flash_err_list'] ?? [];
unset($_SESSION['flash_ok_list'], $_SESSION['flash_err_list']);

$flash_ok  = $_SESSION['flash_ok']  ?? null; unset($_SESSION['flash_ok']);
$flash_err = $_SESSION['flash_err'] ?? null; unset($_SESSION['flash_err']);

$flash_js = [
  'ok'       => $flash_ok,
  'err'      => $flash_err,
  'ok_list'  => array_values((array)$flash_ok_list),
  'err_list' => array_values((array)$flash_err_list),
];

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
      flex-direction: column-reverse;
      gap: 12px;
      z-index: 1040;
    }
    .fab-stack .fab-btn{
      align-self: flex-end;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      min-height: 52px;
      height: 52px;
      width: 52px;
      padding: 0 14px;
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
      width: auto;
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
                  title="Shto/hiq anëtarë (nëse kalon 10, ndahet automatikisht)">
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
            <?php
              if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
              }
            ?>

            <div class="d-flex justify-content-end mt-3 gap-2">
              <button type="button"
                      class="btn btn-soft-primary btn-pill"
                      data-bs-toggle="modal"
                      data-bs-target="#modalDownloadProcesVerbal"
                      data-group-id="<?= (int)$gid ?>"
                      data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-download me-1"></i> Shkarko proces verbalin
              </button>

              <button type="button"
                      class="btn btn-soft-secondary btn-pill"
                      data-bs-toggle="modal"
                      data-bs-target="#modalDownloadPraktikaProfesionale"
                      data-group-id="<?= (int)$gid ?>"
                      data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-download me-1"></i> Shkarko praktikën profesionale
              </button>

              <button type="button"
                      class="btn btn-soft-secondary btn-pill"
                      data-bs-toggle="modal"
                      data-bs-target="#modalDownloadSigurimiTeknik"
                      data-group-id="<?= (int)$gid ?>"
                      data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-shield-check me-1"></i> Shkarko rregullat e sigurimit teknik
              </button>

              <button type="button"
                      class="btn btn-soft-secondary btn-pill"
                      data-bs-toggle="modal"
                      data-bs-target="#modalDownloadListaEmerore"
                      data-group-id="<?= (int)$gid ?>"
                      data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-list-ol me-1"></i> Shkarko listën emërore
              </button>
            </div>
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
            <label class="form-label">AMZË që duhet të jenë në këtë grup (mund të kalojë 10 — ndahet automatikisht)</label>
            <textarea name="amze_spec_members"
                      class="form-control"
                      rows="3"
                      <?= $EDIT_MODE ? '' : 'disabled' ?>
                      data-original-amze="<?= htmlspecialchars($prefillAmzeStr) ?>"
                      placeholder="p.sh. 3400-3403, 3409"><?= htmlspecialchars($prefillAmzeStr) ?>
            </textarea>
            <div class="form-text">
              Mund të shtosh ose heqësh AMZË. Nëse shkruan AMZË që s’ekziston, do të krijohet student i ri me të dhëna bosh. Nëse kalon 10, grupi ndahet automatikisht në disa grupe të balancuara (diferencë max 1 student).
              <strong>Rregull:</strong> i njëjti person (sipas ID personale) nuk mund të jetë dy herë në të njëjtin modul.
              <br><strong>Shënim:</strong> në momentin e shtimit në grup, fshihen automatikisht të gjithë planët <em>planned</em> të studentit.
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
            <tr><td colspan="5" class="text-center text-muted">Të gjithë studentët janë në grupe.</td></tr>
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

<!-- Floating action buttons (stack) -->
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

<!-- ===== Modalet e formularëve në partiale ===== -->
<?php require __DIR__ . '/partials/form1_modal.php'; ?>
<?php require __DIR__ . '/partials/form2_modal.php'; ?>
<?php require __DIR__ . '/partials/modal_download_proces_verbal.php'; ?>
<?php require __DIR__ . '/partials/modal_download_praktika_profesionale.php'; ?>
<?php require __DIR__ . '/partials/modal_download_rregullat_sigurimi_teknik.php'; ?>
<?php require __DIR__ . '/partials/modal_download_lista_emerore.php'; ?>

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
            <input type="text" name="start_date" class="form-control dmy" required placeholder="DD-MM-YYYY" pattern="^\d{2}-\d{2}-\d{4}$" <?= $EDIT_MODE ? '' : 'disabled' ?>>
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
            <label class="form-label">AMZË për këtë grup (mund të kalojë 10 – do ndahen automatikisht)</label>
            <textarea name="amze_spec" class="form-control" rows="2" placeholder="p.sh. 3400-3403, 3409" <?= $EDIT_MODE ? '' : 'disabled' ?>></textarea>
            <div class="form-text">
              Mund të shkruash intervale dhe vlera të ndara me presje. Nëse janë më shumë se 10,
              do krijohen disa grupe të balancuara automatikisht.
              <br><strong>Rregull:</strong> i njëjti person (sipas ID personale) nuk mund ta ndjekë dy herë të njëjtin modul.
              <br><strong>Shënim:</strong> në momentin e shtimit në grup, fshihen automatikisht të gjithë planët <em>planned</em> të studentëve.
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary btn-pill" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>>Krijo grup(et)</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: Split preview (Bootstrap) -->
<div class="modal fade" id="splitPreviewModal" tabindex="-1" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-white">
        <h5 class="modal-title" id="splitPreviewTitle">
          <i class="bi bi-exclamation-triangle me-2 text-warning"></i> Konfirmo ndarjen e grupit
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>

      <div class="modal-body">
        <div class="alert alert-warning mb-3" id="splitPreviewIntro">
          Ky veprim do të ndajë automatikisht studentët në disa grupe (maksimumi 10 studentë për grup),
          të balancuara (diferencë max 1). Renditja ruhet sipas AMZË.
        </div>

        <div class="d-flex flex-wrap gap-2 mb-3" id="splitPreviewBadges" style="display:none;"></div>

        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Grupi</th>
                <th class="nowrap">Studentë</th>
                <th class="nowrap">AMZË (min–max)</th>
              </tr>
            </thead>
            <tbody id="splitPreviewTbody"></tbody>
          </table>
        </div>

        <div class="form-text mt-2" id="splitPreviewNote"></div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-soft-secondary btn-pill" id="splitPreviewCancelBtn" data-bs-dismiss="modal">
          Anulo
        </button>
        <button type="button" class="btn btn-primary btn-pill" id="splitPreviewOkBtn">
          Po, vazhdo
        </button>
      </div>
    </div>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/../shared/partials/download_generation_toast.php'; ?>
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
    </div>`;
  zone.insertAdjacentHTML('beforeend', html);
  const el = document.getElementById(id);
  const t = new bootstrap.Toast(el, { autohide, delay });
  el.addEventListener('hidden.bs.toast', ()=> el.remove());
  t.show();
}

/* === Flash -> Toast (nga serveri pas POST/redirect) === */
const FLASH = <?= json_encode($flash_js, JSON_UNESCAPED_UNICODE) ?>;
if (FLASH && Array.isArray(FLASH.ok_list)) {
  FLASH.ok_list.forEach(m => { if (m) notify('success', m); });
}
if (FLASH && Array.isArray(FLASH.err_list)) {
  FLASH.err_list.forEach(m => { if (m) notify('danger', m); });
}
if (FLASH && FLASH.ok)  notify('success', FLASH.ok);
if (FLASH && FLASH.err) notify('danger',  FLASH.err);

/* showMsg wrapper */
function showMsg(type, text){ notify(type, text); }

/* DD-MM-YYYY -> YYYY-MM-DD (vetëm ky format lejohet) */
function normalizeDateForServer(v){
  const s = clean(v);
  if (s === '' || s === '—') return '';
  const m = s.match(/^(\d{2})-(\d{2})-(\d{4})$/);
  if (!m) throw new Error('Formati i datës duhet të jetë DD-MM-YYYY');
  return `${m[3]}-${m[2]}-${m[1]}`;
}

/* YYYY-MM-DD -> DD-MM-YYYY (për shfaqje) */
function isoToDmy(iso){
  if (!iso) return '—';
  const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!m) return '—';
  return `${m[2]}-${m[1]}-${m[0]}`;
}

/* Lexo datën e mbarimit të grupit nga header (DD-MM-YYYY -> ISO) */
function getGroupEndIso(gid){
  const span = document.querySelector(`.editable[data-field="end_date"][data-group="${gid}"]`);
  if (!span) return '';
  const txt = clean(span.textContent);
  if (!txt || txt==='—') return '';
  return normalizeDateForServer(txt);
}

/* POST JSON helper */
async function postJSON(payload){
  const res = await fetch(ENDPOINT, {
    method: 'POST',
    headers: {'Content-Type':'application/json', 'Accept':'application/json'},
    body: JSON.stringify(payload)
  });
  let json = null;
  try{ json = await res.json(); }catch(_){ /* ignore */ }
  if (!res.ok || !json || json.ok === false){
    const msg = (json && json.error) ? json.error : 'Veprimi dështoi.';
    throw new Error(msg);
  }
  return json;
}

/* ===== Inline editing ===== */
function startSaving(el){ el.classList.add('cell-saving'); }
function stopSaving(el){ el.classList.remove('cell-saving'); }
function flashOk(el){ el.classList.remove('cell-err'); el.classList.add('cell-ok'); setTimeout(()=>el.classList.remove('cell-ok'), 800); }
function flashErr(el){ el.classList.remove('cell-ok'); el.classList.add('cell-err'); setTimeout(()=>el.classList.remove('cell-err'), 800); }

/* Merre kontekstin: group_id, student_id, field nga struktura (td .cell ose span .cell-inline) */
function getCellCtx(editable){
  const cell = editable.closest('.cell');
  if (cell){
    return {
      scope: 'row',
      group_id: parseInt(cell.dataset.group,10)||0,
      student_id: parseInt(cell.dataset.student,10)||0,
      field: String(cell.dataset.field||'')
    };
  }
  // header span (start_date / end_date)
  return {
    scope: 'header',
    group_id: parseInt(editable.dataset.group,10)||0,
    student_id: parseInt(editable.dataset.student,10)||0,
    field: String(editable.dataset.field||'')
  };
}

/* Validime + normalizime sipas fushës */
function normalizeValueForField(field, rawValue, ctx){
  const s = clean(rawValue);
  if (s === '' || s === '—') return { value: '' , display:'—' };

  if (field === 'final_score'){
    const num = Number(String(s).replace(',', '.'));
    if (!Number.isFinite(num)) throw new Error('Pikët duhet të jenë numër.');
    if (num < 0 || num > 100) throw new Error('Pikët duhet të jenë midis 0–100.');
    // shfaq pa zera të panevojshëm
    return { value: num, display: String(num).replace(/\.0+$/,'').replace(/(\.\d*?)0+$/,'$1') };
  }

  if (field === 'exam_date' || field === 'start_date' || field === 'end_date'){
    const iso = normalizeDateForServer(s);
    // Rregull opsional: exam_date ≥ end_date (po të jetë e ditur)
    if (field === 'exam_date'){
      const endIso = getGroupEndIso(ctx.group_id);
      if (endIso && iso < endIso) throw new Error('Data e testimit duhet të jetë ≥ datës së mbarimit të grupit.');
    }
    return { value: iso, display: s };
  }

  // Default: kthe si tekst i thjeshtë
  return { value: s, display: s };
}

/* Ruaj një qelizë */
async function saveEditable(editable){
  if (!EDIT_MODE) return;
  const ctx = getCellCtx(editable);
  const prev = editable.dataset.prev ?? clean(editable.textContent);
  const nowRaw = clean(editable.textContent);

  // në contenteditable përdoruesi mund të fusë newline — pastrojmë
  editable.textContent = nowRaw;

  try{
    const { value, display } = normalizeValueForField(ctx.field, nowRaw, ctx);
    // nëse nuk ka ndryshim real, mos bëj asgjë
    if (clean(prev) === display) return;

    startSaving(editable);

    // endpoint i përgjithshëm "update_cell" — backend duhet të dallojë nga field-i
    await postJSON({
      csrf: CSRF,
      action: 'update_cell',
      group_id: ctx.group_id,
      student_id: ctx.student_id,
      field: ctx.field,
      value: value
    });

    // UI: përditëso
    editable.textContent = display || '—';
    editable.dataset.prev = editable.textContent;

    stopSaving(editable);
    flashOk(editable);
    notify('success','U ruajt.');
  }catch(err){
    stopSaving(editable);
    flashErr(editable);
    editable.textContent = prev || '—';
    notify('danger', err.message || 'Nuk u ruajt ndryshimi.');
  }
}

/* Binds për të gjitha .editable */
document.querySelectorAll('.editable').forEach(ed=>{
  // mos lejo Enter të fusë newline — me Enter bëjmë save
  ed.addEventListener('keydown', (e)=>{
    if (!EDIT_MODE) return;
    if (e.key === 'Enter'){
      e.preventDefault();
      ed.blur();
    }
    if (e.key === 'Escape'){
      e.preventDefault();
      ed.textContent = ed.dataset.prev ?? ed.textContent;
      ed.blur();
    }
  });
  ed.addEventListener('focus', ()=>{
    ed.dataset.prev = clean(ed.textContent);
  });
  ed.addEventListener('paste', (e)=>{
    e.preventDefault();
    const text = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
    document.execCommand('insertText', false, clean(text));
  });
  ed.addEventListener('blur', ()=>{
    if (!EDIT_MODE) return;
    saveEditable(ed);
  });
});

/* ===== Toggle “Përfunduar” (switch) ===== */
document.querySelectorAll('.toggle-completed').forEach(sw=>{
  sw.addEventListener('change', async ()=>{
    if (!EDIT_MODE){ sw.checked = !sw.checked; return; }
    const gid = parseInt(sw.dataset.group,10)||0;
    const want = sw.checked ? 1 : 0;

    try{
      // konfirmo kur po e ç’përfundon
      if (GROUP_COMPLETED[gid] == 1 && want === 0){
        const ok = confirm('Ky grup është i përfunduar. E ç’përfundon?');
        if (!ok){ sw.checked = true; return; }
      }
      // ose kur po e shënon si të përfunduar
      if (GROUP_COMPLETED[gid] != 1 && want === 1){
        const ok = confirm('Po e shënon grupin si të përfunduar. Vazhdo?');
        if (!ok){ sw.checked = false; return; }
      }

      await postJSON({ csrf:CSRF, action:'set_group_completed', group_id:gid, is_completed:want });

      GROUP_COMPLETED[gid] = want;

      // UI badge + alert
      const badge = document.querySelector(`.group-badge[data-group="${gid}"]`);
      const alertBox = document.getElementById(`statusAlert_${gid}`);
      if (badge){
        badge.className = `badge ${want ? 'text-bg-success' : 'text-bg-danger'} group-badge`;
        badge.textContent = want ? 'I përfunduar' : 'Jo i përfunduar';
      }
      if (alertBox){
        alertBox.className = `status-alert alert ${want ? 'alert-success' : 'alert-danger'} py-2 mb-3 small`;
        alertBox.innerHTML = want
          ? `<i class="bi bi-check-circle me-1"></i> Ky grup është shënuar si <strong>i përfunduar</strong>. Çdo ndryshim do të kërkojë konfirmim.`
          : `<i class="bi bi-x-octagon me-1"></i> Ky grup është <strong>jo i përfunduar</strong>. Vendosni statusin si i përfunduar kur të mbaroni.`;
      }

      notify('success','Statusi u përditësua.');
    }catch(err){
      sw.checked = !sw.checked; // rikthe
      notify('danger', err.message || 'Nuk u përditësua statusi.');
    }
  });
});

/* ===== Konfirmim për formularët kur grupi është i përfunduar ===== */
function confirmIfCompleted(formEl, gid){
  const isCompleted = Number(GROUP_COMPLETED[gid] || 0) === 1;
  if (!isCompleted) return true;
  const ok = confirm('Ky grup është i përfunduar. Vazhdo gjithsesi?');
  if (!ok) return false;
  const force = formEl.querySelector('input[name="force"]');
  if (force) force.value = '1';
  return true;
}
window.confirmIfCompleted = confirmIfCompleted;

/* ===== Toggle All (expand/collapse) ===== */
let allOpen = false;
const toggleAllBtn  = document.getElementById('toggleAllBtn');
const toggleAllIcon = document.getElementById('toggleAllIcon');
const toggleAllText = document.getElementById('toggleAllText');

function setAll(open){
  document.querySelectorAll('.group-body').forEach(el=>{
    const c = bootstrap.Collapse.getOrCreateInstance(el, {toggle:false});
    open ? c.show() : c.hide();
  });
  allOpen = !!open;
  toggleAllIcon.className = allOpen ? 'bi bi-arrows-angle-contract' : 'bi bi-arrows-angle-expand';
  toggleAllText.textContent = allOpen ? 'Mbyll të gjitha' : 'Zgjero të gjitha';
}
if (toggleAllBtn){
  toggleAllBtn.addEventListener('click', ()=> setAll(!allOpen));
}

/* ===== Formulari nr.1 — Hints për AMZË & datat ===== */
function updateForm1Hint(selId, hintId){
  const sel  = document.getElementById(selId);
  const hint = document.getElementById(hintId);
  if (!sel || !hint) return;
  const gid = sel.value ? Number(sel.value) : 0;
  const meta = GROUP_AMZE[String(gid)] || null;
  if (!gid || !meta){ hint.textContent = '(AMZË: —)'; return; }
  const min = meta.amze_min ?? '—';
  const max = meta.amze_max ?? '';
  const range = (min==='—' ? '—' : (max ? `${min}–${max}` : `${min}`));
  const s = `${isoToDmy(meta.start_date)} → ${isoToDmy(meta.end_date)}`;
  hint.textContent = `(AMZË: ${range}; Datat: ${s})`;
}
['gstart','gend'].forEach(id=>{
  const el = document.getElementById(id);
  if (el){
    el.addEventListener('change', ()=>{
      updateForm1Hint('gstart','gstartHint');
      updateForm1Hint('gend','gendHint');
    });
  }
});

/* ===== Eksportet (format buttons) + toast ===== */
document.querySelectorAll('#form1Modal [data-dl]').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const format = btn.getAttribute('data-dl');
    document.getElementById('form1Format').value = format;
    notify('success', 'Shkarkimi po përgatitet (Formulari 1 · ' + format.toUpperCase() + ').');
    document.getElementById('form1Export').submit();
  });
});
document.querySelectorAll('#form2Modal [data-dl]').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const format = btn.getAttribute('data-dl');
    document.getElementById('form2Format').value = format;
    notify('success', 'Shkarkimi po përgatitet (Formulari 2 · ' + format.toUpperCase() + ').');
    document.getElementById('form2Export').submit();
  });
});

/* Compact spacing */
document.addEventListener('DOMContentLoaded', ()=>{
  document.body.classList.add('compact');
  // initialize default hints për Formularin 1
  updateForm1Hint('gstart','gstartHint');
  updateForm1Hint('gend','gendHint');

  function parseAmzeRangesClient(s){
    const out = new Set();
    (s || '').split(',').forEach(raw => {
      const tok = raw.trim();
      if (!tok) return;

      // interval p.sh. 1000-1012
      const rangeMatch = tok.match(/^(\d+)\s*-\s*(\d+)$/);
      if (rangeMatch) {
        let a = parseInt(rangeMatch[1],10);
        let b = parseInt(rangeMatch[2],10);
        if (Number.isNaN(a) || Number.isNaN(b)) return;
        if (a > b){ const t=a; a=b; b=t; }
        for (let i=a; i<=b; i++) out.add(i);
        return;
      }

      // numër i vetëm p.sh. 3409
      const singleMatch = tok.match(/^\d+$/);
      if (singleMatch) {
        out.add(parseInt(singleMatch[0],10));
      }
    });
    return Array.from(out).sort((a,b)=>a-b);
  }

  function attachCreateGroupWarning(){
    const modal = document.getElementById('createGroupModal');
    if (!modal) return;
    const form = modal.querySelector('form');
    if (!form) return;

    form.addEventListener('submit', function(e){
      if (!EDIT_MODE) return;

      const textarea = form.querySelector('textarea[name="amze_spec"]');
      if (!textarea) return;

      const nums = parseAmzeRangesClient(textarea.value);
      if (nums.length <= 10) return;

      const total        = nums.length;
      const groupsNeeded = Math.ceil(total / 10);
      if (groupsNeeded <= 1) return;

      const base = Math.floor(total / groupsNeeded);
      const rem  = total % groupsNeeded;

      let cursor = 0;
      const parts = [];

      for (let g=0; g<groupsNeeded; g++){
        const size = base + (g < rem ? 1 : 0);
        if (size <= 0) continue;
        const chunk = nums.slice(cursor, cursor + size);
        cursor += size;
        if (!chunk.length) continue;

        const min = chunk[0];
        const max = chunk[chunk.length-1];
        parts.push({ index:g+1, count:chunk.length, min, max });
      }

      if (!parts.length) return;

      let msg = 'Kujdes: Kjo komandë do të krijojë ' + parts.length + ' grupe:\n\n';
      parts.forEach(p => {
        msg += 'Grupi ' + p.index + ' — ' + p.count + ' studentë, AMZË ' +
              p.min + (p.max !== p.min ? '–' + p.max : '') + '\n';
      });
      msg += '\nVazhdo?';

      if (!confirm(msg)) {
        e.preventDefault();
        e.stopPropagation();
        return false;
      }
    });
  }

  attachCreateGroupWarning();

  function attachCreateGroupWarning(){
    const modal = document.getElementById('createGroupModal');
    if (!modal) return;
    const form = modal.querySelector('form');
    if (!form) return;

    form.addEventListener('submit', function(e){
      // nëse Edit Mode është OFF, nuk bën sens të kontrollojmë
      if (!EDIT_MODE) return;

      const textarea = form.querySelector('textarea[name="amze_spec"]');
      if (!textarea) return;

      const nums = parseAmzeRangesClient(textarea.value);
      if (nums.length <= 10) return; // 1 grup, asnjë paralajmërim i veçantë

      const total        = nums.length;
      const groupsNeeded = Math.ceil(total / 10);
      if (groupsNeeded <= 1) return;

      const base = Math.floor(total / groupsNeeded);
      const rem  = total % groupsNeeded;

      let cursor = 0;
      const parts = [];

      for (let g=0; g<groupsNeeded; g++){
        const size = base + (g < rem ? 1 : 0);
        if (size <= 0) continue;
        const chunk = nums.slice(cursor, cursor + size);
        cursor += size;
        if (!chunk.length) continue;

        const min = chunk[0];
        const max = chunk[chunk.length-1];
        parts.push({
          index: g+1,
          count: chunk.length,
          min,
          max
        });
      }

      if (!parts.length) return;

      let msg = 'Kujdes: Kjo komandë do të krijojë ' + parts.length + ' grupe:\n\n';
      parts.forEach(p => {
        msg += 'Grupi ' + p.index + ' — ' + p.count + ' studentë, AMZË ' +
              p.min + (p.max !== p.min ? '–' + p.max : '') + '\n';
      });
      msg += '\nVazhdo?';

      if (!confirm(msg)) {
        e.preventDefault();
        e.stopPropagation();
        return false;
      }
    });
  }

  attachCreateGroupWarning();

    // ===== Split preview MODAL (për create_group & edit_members) =====

  function parseAmzeRangesClient(s){
    const out = new Set();
    (s || '').split(',').forEach(raw => {
      const tok = raw.trim();
      if (!tok) return;

      const rangeMatch = tok.match(/^(\d+)\s*-\s*(\d+)$/);
      if (rangeMatch) {
        let a = parseInt(rangeMatch[1],10);
        let b = parseInt(rangeMatch[2],10);
        if (Number.isNaN(a) || Number.isNaN(b)) return;
        if (a > b){ const t=a; a=b; b=t; }
        for (let i=a; i<=b; i++) out.add(i);
        return;
      }

      const singleMatch = tok.match(/^\d+$/);
      if (singleMatch) out.add(parseInt(singleMatch[0],10));
    });
    return Array.from(out).sort((a,b)=>a-b);
  }

  function buildSplitParts(nums){
    const total = nums.length;
    const groupsNeeded = Math.ceil(total / 10);
    const base = Math.floor(total / groupsNeeded);
    const rem  = total % groupsNeeded;

    let cursor = 0;
    const parts = [];
    for (let g=0; g<groupsNeeded; g++){
      const size = base + (g < rem ? 1 : 0);
      if (size <= 0) continue;
      const chunk = nums.slice(cursor, cursor + size);
      cursor += size;
      if (!chunk.length) continue;

      parts.push({
        idx: g,
        count: chunk.length,
        min: chunk[0],
        max: chunk[chunk.length-1]
      });
    }
    return parts;
  }

  function diffCounts(oldNums, newNums){
    const oldSet = new Set(oldNums);
    const newSet = new Set(newNums);
    let added = 0, removed = 0;

    newSet.forEach(n => { if (!oldSet.has(n)) added++; });
    oldSet.forEach(n => { if (!newSet.has(n)) removed++; });

    return { added, removed };
  }

  const splitModalEl = document.getElementById('splitPreviewModal');
  const splitModal   = splitModalEl ? bootstrap.Modal.getOrCreateInstance(splitModalEl) : null;

  const $title  = document.getElementById('splitPreviewTitle');
  const $intro  = document.getElementById('splitPreviewIntro');
  const $badges = document.getElementById('splitPreviewBadges');
  const $tbody  = document.getElementById('splitPreviewTbody');
  const $note   = document.getElementById('splitPreviewNote');

  const btnOk     = document.getElementById('splitPreviewOkBtn');
  const btnCancel = document.getElementById('splitPreviewCancelBtn');

  let pending = null; // { form, resumeModalEl, resumeModalInstance, context }

  function renderBadges(items){
    if (!$badges) return;
    if (!items || !items.length){
      $badges.style.display = 'none';
      $badges.innerHTML = '';
      return;
    }
    $badges.style.display = '';
    $badges.innerHTML = items.map(it => {
      const cls = it.type === 'danger' ? 'text-bg-danger'
               : it.type === 'success' ? 'text-bg-success'
               : it.type === 'primary' ? 'text-bg-primary'
               : 'text-bg-secondary';
      return `<span class="badge ${cls}">${it.text}</span>`;
    }).join(' ');
  }

  function renderPartsTable(parts, labelFn){
    $tbody.innerHTML = parts.map(p => {
      const label = labelFn ? labelFn(p) : `Grupi ${p.idx+1}`;
      const range = `${p.min}${(p.max !== p.min) ? '–' + p.max : ''}`;
      return `
        <tr>
          <td class="fw-semibold">${label}</td>
          <td class="nowrap">${p.count}</td>
          <td class="nowrap">${range}</td>
        </tr>
      `;
    }).join('');
  }

  function showSplitPreviewModal(options){
    if (!splitModal) return false;

    // options: { title, introHtml, badges[], parts, note, form, resumeModalEl }
    $title.innerHTML = options.title || $title.innerHTML;
    $intro.innerHTML = options.introHtml || $intro.innerHTML;
    $note.textContent = options.note || '';

    renderBadges(options.badges || []);
    renderPartsTable(options.parts || [], options.labelFn);

    pending = {
      form: options.form,
      resumeModalEl: options.resumeModalEl || null,
      resumeModalInstance: options.resumeModalEl ? bootstrap.Modal.getOrCreateInstance(options.resumeModalEl) : null
    };

    // Fshih modali i formës (për të shmangur stacking issues)
    if (pending.resumeModalInstance){
      pending.resumeModalInstance.hide();
    }

    splitModal.show();
    return true;
  }

  // Cancel: hap prap modali i formës (nëse ishte)
  splitModalEl?.addEventListener('hidden.bs.modal', () => {
    if (pending && pending.resumeModalInstance && pending._proceeded !== true){
      pending.resumeModalInstance.show();
    }
    pending = null;
  });

  btnOk?.addEventListener('click', () => {
    if (!pending || !pending.form) return;

    pending._proceeded = true;
    splitModal.hide();

    // lejo submit pa e rishfaqur modal-in
    pending.form.dataset.splitConfirmed = '1';

    // submit pa “browser confirm”; server-side do bëjë ndarjen siç e ke tani
    if (typeof pending.form.requestSubmit === 'function') pending.form.requestSubmit();
    else pending.form.submit();
  });

  // ====== (A) CREATE GROUP: para ndarjes ======
  (function attachCreateGroupSplitModal(){
    const createModal = document.getElementById('createGroupModal');
    if (!createModal) return;

    const form = createModal.querySelector('form');
    if (!form) return;

    form.addEventListener('submit', function(e){
      if (!EDIT_MODE) return;
      if (form.dataset.splitConfirmed === '1'){ form.dataset.splitConfirmed = '0'; return; }

      const ta = form.querySelector('textarea[name="amze_spec"]');
      if (!ta) return;

      const nums = parseAmzeRangesClient(ta.value);
      if (nums.length <= 10) return; // s’ka ndarje

      const parts = buildSplitParts(nums);

      // NEW: Nëse do krijohen më shumë se 2 grupe, mos shfaq asnjë modal/alert — lëre të vazhdojë submit normal
      if (parts.length > 2) return;

      e.preventDefault();
      e.stopPropagation();

      showSplitPreviewModal({
        form,
        resumeModalEl: createModal,
        title: `<i class="bi bi-exclamation-triangle me-2 text-warning"></i> Konfirmo krijimin dhe ndarjen e grupeve`,
        introHtml: `
          Do të krijohen <strong>${parts.length}</strong> grupe (maks. 10 studentë për grup),
          sipas rendit të AMZË. A dëshiron të vazhdosh?
        `,
        badges: [
          { type:'primary', text:`Totali AMZË: ${nums.length}` },
          { type:'secondary', text:`Grupe: ${parts.length}` }
        ],
        parts,
        labelFn: (p)=> `Grupi ${p.idx+1} (krijohet)`,
        note: 'Nëse anulon, asnjë grup nuk krijohet.'
      });
    });
  })();


  // ====== (B) EDIT MEMBERS: para ndarjes ======
  (function attachEditMembersSplitModal(){
    document.querySelectorAll('form').forEach(form => {
      const act = form.querySelector('input[name="action"]')?.value || '';
      if (act !== 'edit_members') return;

      // Hiq “onsubmit=confirmIfCompleted(...)” që të mos dalë confirm i shfletuesit
      form.removeAttribute('onsubmit');
      form.onsubmit = null;

      form.addEventListener('submit', function(e){
        if (!EDIT_MODE) return;
        if (form.dataset.splitConfirmed === '1'){ form.dataset.splitConfirmed = '0'; return; }

        // Konfirmimi për “completed” (siç e ke logjikën)
        const gid = parseInt(form.querySelector('input[name="group_id"]')?.value || '0', 10);
        if (gid > 0) {
          const okCompleted = confirmIfCompleted(form, gid); // përdor logjikën ekzistuese të force=1
          if (!okCompleted){
            e.preventDefault();
            e.stopPropagation();
            return;
          }
        }

        const ta = form.querySelector('textarea[name="amze_spec_members"]');
        if (!ta) return;

        const nums = parseAmzeRangesClient(ta.value);
        if (nums.length <= 10) return; // s’ka ndarje

        // Diferenca (shtime/hiqje) nga original
        const original = parseAmzeRangesClient(ta.dataset.originalAmze || '');
        const { added, removed } = diffCounts(original, nums);

        const parts = buildSplitParts(nums);

        e.preventDefault();
        e.stopPropagation();

        const hostModalEl = form.closest('.modal'); // editMembersModal_XX

        showSplitPreviewModal({
          form,
          resumeModalEl: hostModalEl,
          title: `<i class="bi bi-exclamation-triangle me-2 text-warning"></i> Ky veprim do ta ndajë Grupin #${gid}`,
          introHtml: `
            Po modifikon anëtarët e <strong>Grupit #${gid}</strong>. Pas ruajtjes, ky grup do të ndahet automatikisht në
            <strong>${parts.length}</strong> grupe (maks. 10 studentë për grup). A je i sigurt?
          `,
          badges: [
            { type:'primary', text:`Totali AMZË: ${nums.length}` },
            { type:'secondary', text:`Grupe: ${parts.length}` },
            ...(added ? [{ type:'success', text:`Shtohen: ${added}` }] : []),
            ...(removed ? [{ type:'danger', text:`Hiqen: ${removed}` }] : [])
          ],
          parts,
          labelFn: (p)=> (p.idx === 0 ? `Grupi #${gid} (aktual)` : `Grup i ri (krijohet)`),
          note: 'Nëse anulon, ndryshimet nuk ruhen dhe grupi nuk ndahet.'
        });
      });
    });
  })();
});
</script>
</body>
</html>
