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
  $_SESSION['edit_mode'] = filter_var($_GET['edit'], FILTER_VALIDATE_BOOLEAN);
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
/* Pranon dd.mm.vvvv, dd-mm-vvvv, dd/mm/vvvv ose vvvv-mm-dd; kthen vvvv-mm-dd ose null */
function dmy_to_iso(?string $s): ?string {
  if ($s === null) return null;
  $s = trim($s);
  if ($s === '') return null;
  if (preg_match('/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})$/', $s, $m)) {
    $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
  }
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : null;
  }
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
    header('Location: groups.php'); exit;
  }
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf'])) {
    http_response_code(400); $_SESSION['flash_err'] = 'Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.'; header('Location: groups.php'); exit;
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
        throw new RuntimeException('Shkruaje datën e fillimit si dd.mm.vvvv, p.sh. 05.03.2026.');
      }
      if (!$end_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        throw new RuntimeException('Shkruaje datën e mbarimit si dd.mm.vvvv, p.sh. 30.03.2026.');
      }
      if ($end_date < $start_date) {
        throw new RuntimeException('Data e mbarimit nuk mund të jetë para datës së fillimit.');
      }

      // ---------------------------------------------------
      // Përgatit listën e AMZË-ve -> student IDs (të renditura sipas AMZË)
      // ---------------------------------------------------
      $amzeList   = [];
      $studentIds = [];

      if ($amze_spec !== '') {
        $nums = parseAmzeRanges($amze_spec); // tashmë të renditura dhe unike
        if (!$nums) {
          throw new RuntimeException('Nuk gjeta asnjë numër amze. Shkruaji si 3400-3403, 3409.');
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
            'Grupi nuk u krijua: këta numra amze janë tashmë në një grup: ' . implode(', ', $items)
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

        $_SESSION['flash_ok'] = 'Grupi u krijua pa kursantë. Shtoji kur të jenë gati.';
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
            'Disa persona e kanë ndjekur tashmë këtë modul (sipas numrit personal): ' . implode(', ', $items)
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
          throw new RuntimeException('Ndarja në grupe nuk doli e saktë. Asgjë nuk u ruajt — provo sërish.');
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
        $_SESSION['flash_ok'] = 'Grupi u krijua me ' . $c['count'] . ' kursantë' . $range . '.';
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
      if ($group_id<=0 || $course_id<=0) throw new RuntimeException('Zgjidh një modul për grupin.');

      $gRow = $pdo->prepare("SELECT is_completed FROM course_groups WHERE id=:g");
      $gRow->execute([':g'=>$group_id]);
      $is_completed = (int)($gRow->fetchColumn() ?? 0);
      if ($is_completed && !$force) { throw new RuntimeException('Ky grup është i mbyllur. Konfirmo që do ta ndryshosh.'); }

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
            throw new RuntimeException('Moduli nuk u ndryshua: disa persona e kanë ndjekur tashmë modulin e ri: '.implode(', ', $items));
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

      $_SESSION['flash_ok'] = 'Moduli i grupit u ndryshua.';
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
        throw new RuntimeException('Grupi nuk u gjet.');
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
        throw new RuntimeException('Ky grup është i mbyllur. Konfirmo që do ta ndryshosh.');
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

        $_SESSION['flash_ok'] = 'Të gjithë kursantët u hoqën nga grupi.';
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
            'Asgjë nuk ndryshoi: këta kursantë janë tashmë në një grup tjetër: ' . implode(', ', $items)
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
              'Disa persona e kanë ndjekur tashmë këtë modul (sipas numrit personal): ' . implode(', ', $items)
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

        $_SESSION['flash_ok'] = 'Kursantët e grupit u ruajtën.';
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
            throw new RuntimeException('Asgjë nuk ndryshoi: i njëjti kursant del dy herë në listë.');
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
              throw new RuntimeException('Ndarja në grupe nuk doli e saktë. Asgjë nuk u ruajt — provo sërish.');
            }

            $chunks[] = [
              'ids'      => $chunkIds,
              'amze_min' => $chunkAmze ? min($chunkAmze) : null,
              'amze_max' => $chunkAmze ? max($chunkAmze) : null,
            ];
          }

          if (!$chunks) {
            throw new RuntimeException('Kursantët nuk u ndanë dot në grupe. Asgjë nuk u ruajt — provo sërish.');
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

          $_SESSION['flash_ok_list'][] = 'Grupi #' . $group_id . ' u nda në ' . count($created) . ' grupe të barabarta.';

          foreach ($created as $r) {
            $range = ($r['amze_min'] !== null)
              ? ' [AMZË ' . $r['amze_min'] . ($r['amze_max'] && $r['amze_max'] !== $r['amze_min'] ? '–' . $r['amze_max'] : '') . ']'
              : '';
            $_SESSION['flash_ok_list'][] = 'Grupi #' . $r['group_id'] . ' ka tani ' . $r['count'] . ' kursantë' . $range . '.';
          }

          if ($toAdd) {
            $_SESSION['flash_ok_list'][] = 'U shtuan ' . count($toAdd) . ' kursantë të rinj.';
          }
          if ($toRemove) {
            $_SESSION['flash_ok_list'][] = 'U hoqën ' . count($toRemove) . ' kursantë nga grupi.';
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
      if ($group_id<=0) throw new RuntimeException('Grupi nuk u gjet.');

      $gRow = $pdo->prepare("SELECT is_completed FROM course_groups WHERE id=:g");
      $gRow->execute([':g'=>$group_id]);
      $is_completed = (int)($gRow->fetchColumn() ?? 0);
      if ($is_completed && !$force) { throw new RuntimeException('Ky grup është i mbyllur. Konfirmo që do ta fshish.'); }

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

      $_SESSION['flash_ok'] = 'Grupi u fshi. Kursantët e tij janë tani te "Kursantët pa grup".';
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
    cg.is_completed
  FROM course_groups cg
  ORDER BY cg.id ASC
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
$NAV_ACTIVE = 'register_groups';
$HELP_TOPIC = 'groups';
if ($role === 'administrator') require __DIR__ . '/inc/navbar.php';
else require __DIR__ . '/inc/navbar4.php';

$openCreate = $EDIT_MODE && isset($_GET['create']);
$createHref = 'groups.php?' . http_build_query(['edit' => '1', 'create' => '1']);
$hasFilters = ($q !== '' || $courseFilter !== '');
$today = date('Y-m-d');

$pageTitle = 'Grupet';
require __DIR__ . '/../shared/app_head.php';

/* Grupimi i rreshtave: një grup me kursantët e vet, renditur sipas amzës së parë */
$groups = [];
foreach ($rows as $r) {
  $gid = (int)$r['group_id'];
  if (!isset($groups[$gid])) {
    $groups[$gid] = [
      'header' => [
        'group_id' => $gid,
        'course_id' => $r['course_id'],
        'course_name' => $r['course_name'],
        'start_date' => $r['start_date'],
        'end_date' => $r['end_date'],
        'is_completed' => (int)$r['is_completed'],
      ],
      'students' => [],
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
uasort($groups, function ($A, $B) { return ($A['min_amze'] <=> $B['min_amze']); });

/* Gjendja e grupit me fjalë */
$groupStatus = static function (array $h) use ($today): string {
  if ((int)$h['is_completed'] === 1) return qta_status('I mbyllur', 'success', 'bi-lock-fill');
  $start = (string)$h['start_date']; $end = (string)$h['end_date'];
  if ($start > $today) return qta_status('Nis ' . qta_when_label($start), 'info', 'bi-calendar-event');
  if ($end >= $today) return qta_status('Në mësim', 'accent', 'bi-easel');
  return qta_status('Pret mbylljen', 'warning', 'bi-hourglass-split');
};
?>

<main class="app-main is-wide" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title">Grupet</h1>
      <p class="page-lead">Çdo grup ndjek një modul në data të caktuara, me deri në 10 kursantë. Hap një grup për provimet, pikët dhe dokumentet.</p>
    </div>
    <div class="page-actions">
      <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>
      <?= qta_help_button() ?>
      <button class="btn btn-secondary" type="button" data-bs-toggle="modal" data-bs-target="#qklReportModal">
        <i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i>Raporti për QKL
      </button>
      <?php if ($EDIT_MODE): ?>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#createGroupModal">
          <i class="bi bi-plus-lg" aria-hidden="true"></i>Krijo grup
        </button>
      <?php else: ?>
        <a class="btn btn-primary" href="<?= h($createHref) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i>Krijo grup</a>
      <?php endif; ?>
    </div>
  </header>

  <form class="filters" method="get" action="groups.php" role="search" aria-label="Kërko grupe">
    <div class="filter-field is-grow">
      <label class="form-label" for="fQ">Kërko një kursant</label>
      <div class="search-field">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="form-control" id="fQ" type="search" name="q" value="<?= h($q) ?>" placeholder="Emri, numri personal ose nr. i amzës">
      </div>
    </div>
    <div class="filter-field">
      <label class="form-label" for="fCourse">Moduli</label>
      <select class="form-select" id="fCourse" name="course_id">
        <option value="">Të gjitha modulet</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= ($courseFilter !== '' && (int)$courseFilter === (int)$c['id']) ? 'selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-actions">
      <?php if ($hasFilters): ?>
        <a class="btn btn-ghost" href="groups.php"><i class="bi bi-x-lg" aria-hidden="true"></i>Pastro kërkimin</a>
      <?php endif; ?>
      <button class="btn btn-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i>Kërko</button>
    </div>
  </form>

  <?php if ($countNoGroup > 0): ?>
    <div class="notice mb-4">
      <i class="bi bi-people" aria-hidden="true"></i>
      <span><b><?= h(qta_plural($countNoGroup, 'kursant pret', 'kursantë presin')) ?> një grup.</b>
        <a href="students_without_groups.php">Caktoji në grup</a> ose shto numrat e tyre të amzës kur krijon një grup të ri.</span>
    </div>
  <?php endif; ?>

  <?php require __DIR__ . '/../shared/partials/edit_mode_off_banner.php'; ?>

  <section class="section" aria-labelledby="groupsTitle">
    <div class="section-head">
      <h2 class="section-title" id="groupsTitle">
        <?= $hasFilters ? 'Grupet që përputhen' : 'Të gjitha grupet' ?>
        <span class="count"><?= number_format(count($groups), 0, ',', '.') ?></span>
      </h2>
      <?php if ($groups): ?>
        <button id="toggleAllBtn" class="btn btn-ghost" type="button" aria-pressed="false">
          <i class="bi bi-arrows-expand" id="toggleAllIcon" aria-hidden="true"></i><span id="toggleAllText">Hap të gjitha</span>
        </button>
      <?php endif; ?>
    </div>

    <?php if ($groups): ?>
      <?php
        $tfTarget = '#groupsTable';
        $tfPlaceholder = 'Filtro — modul, amzë ose datë';
        $tfChips = [['label' => 'Të mbyllura', 'match' => 'I mbyllur'], ['label' => 'Në mësim', 'match' => 'Në mësim'], ['label' => 'Presin mbylljen', 'match' => 'Pret mbylljen']];
        $tfNoun = 'grupe';
        require __DIR__ . '/../shared/partials/table_filter.php';
      ?>
      <div class="table-responsive">
        <table class="table" id="groupsTable" data-sortable>
          <thead>
            <tr>
              <th scope="col" class="col-wide" data-sort="text">Moduli</th>
              <th scope="col" class="nowrap" data-sort="text">Nr. i amzës</th>
              <th scope="col" class="nowrap" data-sort="date">Fillimi</th>
              <th scope="col" class="nowrap" data-sort="date">Mbarimi</th>
              <th scope="col" class="nowrap num-col" data-sort="num">Kursantë</th>
              <th scope="col" class="nowrap num-col" data-sort="num">Me pikë</th>
              <th scope="col" data-sort="text">Gjendja</th>
              <th scope="col" class="nowrap" data-sort="none">Mbyllur</th>
              <th scope="col" class="col-actions" data-sort="none"><span class="visually-hidden">Veprime</span></th>
            </tr>
          </thead>

          <?php foreach ($groups as $gid => $g):
            $h0 = $g['header'];
            $completed = (int)$h0['is_completed'] === 1;
            $minLbl = ($g['min_amze'] === PHP_INT_MAX) ? '—' : (string)$g['min_amze'];
            $maxLbl = ($g['max_amze'] === null || $g['max_amze'] === $g['min_amze']) ? '' : '–' . $g['max_amze'];
            $nStud = count($g['students']);
            $nScored = 0;
            foreach ($g['students'] as $srow) {
              if ($srow['final_score'] !== null && $srow['final_score'] !== '') { $nScored++; }
            }
            $groupLabel = 'Grupi #' . (int)$gid . ' · ' . $h0['course_name'];
          ?>
          <tbody class="grp" data-gid="<?= (int)$gid ?>">
            <tr>
              <td class="col-wide">
                <button class="row-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#gBody_<?= (int)$gid ?>"
                        aria-expanded="false" aria-controls="gBody_<?= (int)$gid ?>">
                  <i class="bi bi-chevron-right" aria-hidden="true"></i>
                  <span>
                    <span class="person-name"><?= h((string)$h0['course_name']) ?></span>
                    <span class="cell-sub">Grupi #<?= (int)$gid ?></span>
                  </span>
                </button>
              </td>
              <td class="nowrap"><span class="id-code"><?= h($minLbl . $maxLbl) ?></span></td>
              <td class="nowrap cell" data-student="0" data-group="<?= (int)$gid ?>" data-field="start_date" title="Data e fillimit (dd.mm.vvvv)">
                <span class="editable cell-inline" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"
                      data-field="start_date" data-group="<?= (int)$gid ?>" data-student="0"><?= h(qta_date($h0['start_date'])) ?></span>
              </td>
              <td class="nowrap cell" data-student="0" data-group="<?= (int)$gid ?>" data-field="end_date" title="Data e mbarimit (dd.mm.vvvv)">
                <span class="editable cell-inline" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"
                      data-field="end_date" data-group="<?= (int)$gid ?>" data-student="0"><?= h(qta_date($h0['end_date'])) ?></span>
              </td>
              <td class="nowrap num-col"><?= $nStud ?><span class="text-subtle">/10</span></td>
              <td class="nowrap num-col<?= ($nStud > 0 && $nScored < $nStud) ? ' text-warning' : '' ?>"><?= $nScored ?></td>
              <td data-group-status="<?= (int)$gid ?>"><?= $groupStatus($h0) ?></td>
              <td class="nowrap">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input toggle-completed" type="checkbox" role="switch" id="gDone_<?= (int)$gid ?>"
                         data-group="<?= (int)$gid ?>" <?= $completed ? 'checked' : '' ?> <?= $EDIT_MODE ? '' : 'disabled' ?>>
                  <label class="form-check-label group-badge" for="gDone_<?= (int)$gid ?>" data-group="<?= (int)$gid ?>"><?= $completed ? 'Po' : 'Jo' ?></label>
                </div>
              </td>
              <td class="col-actions">
                <?php if ($EDIT_MODE): ?>
                  <div class="dropdown">
                    <button class="btn btn-ghost btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                      Ndrysho<i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                      <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#editMembersModal_<?= (int)$gid ?>"><i class="bi bi-people" aria-hidden="true"></i>Kursantët e grupit</button></li>
                      <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#editCourseModal_<?= (int)$gid ?>"><i class="bi bi-book" aria-hidden="true"></i>Ndrysho modulin</button></li>
                      <li><hr class="dropdown-divider"></li>
                      <li><button class="dropdown-item text-danger" type="button" data-bs-toggle="modal" data-bs-target="#deleteGroupModal_<?= (int)$gid ?>"><i class="bi bi-trash" aria-hidden="true"></i>Fshi grupin</button></li>
                    </ul>
                  </div>
                <?php endif; ?>
              </td>
            </tr>

            <tr class="row-details">
              <td colspan="9">
                <div id="gBody_<?= (int)$gid ?>" class="collapse group-body">
                  <div class="row-details-inner">

                    <div id="statusAlert_<?= (int)$gid ?>" class="notice mb-3">
                      <i class="bi <?= $completed ? 'bi-lock' : 'bi-unlock' ?>" aria-hidden="true"></i>
                      <span><?= $completed
                        ? '<b>Grupi është i mbyllur.</b> Ndryshimet kërkojnë konfirmim, sepse dokumentet mund të jenë lëshuar.'
                        : '<b>Grupi është i hapur.</b> Kur të mbarojnë provimet, shëno "Mbyllur".' ?></span>
                    </div>

                    <?php if ($g['students']): ?>
                      <div class="table-responsive">
                        <table class="table table-sm">
                          <thead>
                            <tr>
                              <th scope="col" class="nowrap">Nr. i amzës</th>
                              <th scope="col">Kursanti</th>
                              <th scope="col" class="nowrap">Data e provimit</th>
                              <th scope="col" class="nowrap num-col">Pikët</th>
                              <th scope="col">Gjendja</th>
                              <th scope="col" class="nowrap num-col">Mosha</th>
                              <th scope="col" class="nowrap">Arsimi</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($g['students'] as $r):
                              $full = qta_full_name($r['first_name'] ?? '', $r['father_name'] ?? '', $r['last_name'] ?? ''); ?>
                              <tr data-student-row>
                                <td class="nowrap"><span class="id-code"><?= h((string)$r['nr_amze']) ?></span></td>
                                <td>
                                  <a class="person-name" href="student_card.php?sid=<?= (int)$r['student_id'] ?>"><?= h($full !== '' ? $full : 'Pa emër') ?></a>
                                  <?php if (!empty($r['personal_number'])): ?><span class="cell-sub code"><?= h((string)$r['personal_number']) ?></span><?php endif; ?>
                                </td>
                                <td class="cell nowrap" data-student="<?= (int)$r['student_id'] ?>" data-group="<?= (int)$gid ?>" data-field="exam_date" title="Jo para mbarimit të grupit (dd.mm.vvvv)">
                                  <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= h(qta_date($r['exam_date'])) ?></span>
                                </td>
                                <td class="cell nowrap num-col" data-student="<?= (int)$r['student_id'] ?>" data-group="<?= (int)$gid ?>" data-field="final_score" title="Pikët 0–100. Kalon me 50 e lart.">
                                  <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= $r['final_score'] !== null ? h(rtrim(rtrim((string)$r['final_score'], '0'), '.')) : '—' ?></span>
                                </td>
                                <td data-status><?= qta_enrollment_status(['start_date' => $h0['start_date'], 'end_date' => $h0['end_date'], 'exam_date' => $r['exam_date'], 'final_score' => $r['final_score']]) ?></td>
                                <td class="nowrap num-col"><?= $r['age'] !== null ? (int)$r['age'] : '—' ?></td>
                                <td class="nowrap"><?= h((string)(($r['edu_label'] ?? '') ?: '—')) ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php else: ?>
                      <?= qta_empty('Grupi është bosh', $EDIT_MODE ? 'Shto kursantë nga "Ndrysho → Kursantët e grupit".' : 'Për të shtuar kursantë, shtyp "Lejo ndryshimet".', 'bi-people', '', 'is-compact') ?>
                    <?php endif; ?>

                    <div class="doc-actions">
                      <span class="doc-actions-label">Dokumentet e grupit:</span>
                      <?php foreach ([
                        'proces_verbal' => ['bi-file-earmark-check', 'Procesverbali'],
                        'lista_emerore' => ['bi-list-ol', 'Lista emërore'],
                        'praktika_profesionale' => ['bi-tools', 'Praktika profesionale'],
                        'rregullat_sigurimi_teknik' => ['bi-shield-check', 'Rregullat e sigurisë'],
                      ] as $docKey => [$docIcon, $docLabel]): ?>
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#groupDocModal"
                                data-doc="<?= h($docKey) ?>" data-group-id="<?= (int)$gid ?>" data-group-label="<?= h($groupLabel) ?>">
                          <i class="bi <?= h($docIcon) ?>" aria-hidden="true"></i><?= h($docLabel) ?>
                        </button>
                      <?php endforeach; ?>
                    </div>

                  </div>
                </div>
              </td>
            </tr>
          </tbody>
          <?php endforeach; ?>
        </table>
      </div>
      <p class="text-muted small mt-2 mb-0">Kliko emrin e modulit për të parë kursantët e grupit.</p>
    <?php else: ?>
      <?= qta_empty(
            $hasFilters ? 'Asnjë grup nuk përputhet' : 'Ende nuk ka grupe',
            $hasFilters ? 'Provo një modul tjetër ose pastro kërkimin.' : 'Krijo grupin e parë dhe shto numrat e amzës së kursantëve.',
            'bi-collection',
            $hasFilters ? '<a class="btn btn-secondary" href="groups.php">Pastro kërkimin</a>' : '<a class="btn btn-primary" href="' . h($createHref) . '">Krijo grup</a>'
          ) ?>
    <?php endif; ?>
  </section>
</main>

<?php foreach ($groups as $gid => $g):
  $h0 = $g['header'];
  $prefillAmze = [];
  foreach ($g['students'] as $stRow) { $prefillAmze[] = (string)((int)$stRow['nr_amze']); }
  $prefillAmzeStr = implode(', ', $prefillAmze);
?>
  <!-- Dialog: kursantët e grupit #<?= (int)$gid ?> -->
  <div class="modal fade" id="editMembersModal_<?= (int)$gid ?>" tabindex="-1" aria-labelledby="emTitle_<?= (int)$gid ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="post" action="groups.php" data-group-form>
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="action" value="edit_members">
        <input type="hidden" name="group_id" value="<?= (int)$gid ?>">
        <input type="hidden" name="force" value="0">
        <div class="modal-header">
          <div>
            <span class="eyebrow mb-0">Grupi #<?= (int)$gid ?> · <?= h((string)$h0['course_name']) ?></span>
            <h2 class="modal-title" id="emTitle_<?= (int)$gid ?>"><i class="bi bi-people" aria-hidden="true"></i>Kursantët e grupit</h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
        </div>
        <div class="modal-body">
          <label class="form-label" for="emAmze_<?= (int)$gid ?>">Numrat e amzës në këtë grup</label>
          <textarea id="emAmze_<?= (int)$gid ?>" name="amze_spec_members" class="form-control input-code" rows="3"
                    aria-describedby="emHelp_<?= (int)$gid ?>" <?= $EDIT_MODE ? '' : 'disabled' ?>
                    data-original-amze="<?= h($prefillAmzeStr) ?>" placeholder="p.sh. 3400-3403, 3409"><?= h($prefillAmzeStr) ?></textarea>
          <div class="form-text" id="emHelp_<?= (int)$gid ?>">
            Shkruaj numra të ndarë me presje ose intervale me vizë, p.sh. <span class="code">3400-3403, 3409</span>.
            Shto ose hiq numra për të shtuar ose hequr kursantë.
          </div>
          <ul class="text-muted small mt-3 mb-0 ps-3">
            <li>Më shumë se 10 kursantë? Grupi ndahet vetë në grupe të barabarta (para ruajtjes të tregohet si).</li>
            <li>Një person nuk mund ta ndjekë dy herë të njëjtin modul.</li>
            <li>Një numër amze që nuk ekziston krijon një kursant të ri pa të dhëna, për t'u plotësuar më vonë.</li>
          </ul>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
          <button class="btn btn-primary" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>><i class="bi bi-check-lg" aria-hidden="true"></i>Ruaj ndryshimet</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Dialog: moduli i grupit #<?= (int)$gid ?> -->
  <div class="modal fade" id="editCourseModal_<?= (int)$gid ?>" tabindex="-1" aria-labelledby="ecTitle_<?= (int)$gid ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="post" action="groups.php" data-group-form>
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="action" value="update_group_course">
        <input type="hidden" name="group_id" value="<?= (int)$gid ?>">
        <input type="hidden" name="force" value="0">
        <div class="modal-header">
          <div>
            <span class="eyebrow mb-0">Grupi #<?= (int)$gid ?></span>
            <h2 class="modal-title" id="ecTitle_<?= (int)$gid ?>"><i class="bi bi-book" aria-hidden="true"></i>Ndrysho modulin</h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
        </div>
        <div class="modal-body">
          <label class="form-label" for="ecCourse_<?= (int)$gid ?>">Moduli i grupit</label>
          <select id="ecCourse_<?= (int)$gid ?>" name="course_id" class="form-select" required <?= $EDIT_MODE ? '' : 'disabled' ?>>
            <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === (int)$h0['course_id']) ? 'selected' : '' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="form-text">Përdore vetëm për të korrigjuar një gabim — të gjithë kursantët e grupit kalojnë në modulin e ri.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
          <button class="btn btn-primary" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>>Ruaj modulin</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Dialog: fshirja e grupit #<?= (int)$gid ?> -->
  <div class="modal fade" id="deleteGroupModal_<?= (int)$gid ?>" tabindex="-1" aria-labelledby="dgTitle_<?= (int)$gid ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="post" action="groups.php" data-group-form>
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="action" value="delete_group">
        <input type="hidden" name="group_id" value="<?= (int)$gid ?>">
        <input type="hidden" name="force" value="0">
        <div class="modal-body pt-4">
          <span class="confirm-icon is-danger"><i class="bi bi-trash" aria-hidden="true"></i></span>
          <h2 class="modal-title mb-2" id="dgTitle_<?= (int)$gid ?>">Të fshihet Grupi #<?= (int)$gid ?>?</h2>
          <p class="mb-2"><b><?= h((string)$h0['course_name']) ?></b> · <?= h(qta_date($h0['start_date'])) ?> – <?= h(qta_date($h0['end_date'])) ?></p>
          <p class="text-muted mb-0">Kursantët <b>nuk fshihen</b> — ata kthehen te "Kursantët pa grup". Fshihen vetëm datat e provimit dhe pikët e ruajtura në këtë grup. Kjo nuk mund të kthehet mbrapsht.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
          <button class="btn btn-danger" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>><i class="bi bi-trash" aria-hidden="true"></i>Po, fshije grupin</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>

<?php require __DIR__ . '/../shared/partials/qkl_report_modal.php'; ?>
<?php require __DIR__ . '/../shared/partials/group_documents_modal.php'; ?>

<!-- Dialog: krijo grup -->
<div class="modal fade" id="createGroupModal" tabindex="-1" aria-labelledby="createGroupTitle" aria-hidden="true"<?= $openCreate ? ' data-open-on-load="create"' : '' ?>>
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form class="modal-content" method="post" action="groups.php" data-create-group-form>
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="create_group">
      <div class="modal-header">
        <h2 class="modal-title" id="createGroupTitle"><i class="bi bi-plus-lg" aria-hidden="true"></i>Krijo një grup</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="cgCourse">Moduli <span class="req" aria-hidden="true">*</span></label>
            <select id="cgCourse" name="course_id" class="form-select" required <?= $EDIT_MODE ? '' : 'disabled' ?>>
              <option value="">— Zgjidh modulin —</option>
              <?php foreach ($courses as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" for="cgStart">Fillimi <span class="req" aria-hidden="true">*</span></label>
            <input id="cgStart" type="text" name="start_date" class="form-control dmy" required placeholder="dd.mm.vvvv" inputmode="numeric" autocomplete="off" <?= $EDIT_MODE ? '' : 'disabled' ?>>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" for="cgEnd">Mbarimi <span class="req" aria-hidden="true">*</span></label>
            <input id="cgEnd" type="text" name="end_date" class="form-control dmy" required placeholder="dd.mm.vvvv" inputmode="numeric" autocomplete="off" <?= $EDIT_MODE ? '' : 'disabled' ?>>
          </div>
          <div class="col-12">
            <label class="form-label" for="cgAmze">Numrat e amzës së kursantëve <span class="optional">(mund t'i shtosh edhe më vonë)</span></label>
            <textarea id="cgAmze" name="amze_spec" class="form-control input-code" rows="2" placeholder="p.sh. 3400-3403, 3409" aria-describedby="cgAmzeHelp" <?= $EDIT_MODE ? '' : 'disabled' ?>></textarea>
            <div class="form-text" id="cgAmzeHelp">
              Numra të ndarë me presje ose intervale me vizë. Nëse janë më shumë se 10, krijohen vetë disa grupe të barabarta — të tregohet si para se të ruhen.
            </div>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="is_completed" name="is_completed" value="1" <?= $EDIT_MODE ? '' : 'disabled' ?>>
              <label class="form-check-label" for="is_completed">Grupi ka mbaruar tashmë (shënoje si të mbyllur)</label>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>><i class="bi bi-check-lg" aria-hidden="true"></i>Krijo grupin</button>
      </div>
    </form>
  </div>
</div>

<!-- Dialog: parashikimi i ndarjes në grupe -->
<div class="modal fade" id="splitPreviewModal" tabindex="-1" aria-labelledby="splitPreviewTitle" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="splitPreviewTitle"><i class="bi bi-diagram-3" aria-hidden="true"></i>Kursantët do të ndahen në grupe</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Anulo"></button>
      </div>
      <div class="modal-body">
        <p id="splitPreviewIntro">Një grup mban deri në 10 kursantë. Ja si do të ndahen, sipas rendit të numrit të amzës:</p>
        <div class="d-flex flex-wrap gap-2 mb-3" id="splitPreviewBadges"></div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th scope="col">Grupi</th><th scope="col" class="num-col">Kursantë</th><th scope="col">Nr. i amzës</th></tr></thead>
            <tbody id="splitPreviewTbody"></tbody>
          </table>
        </div>
        <p class="form-text mt-2" id="splitPreviewNote"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="splitPreviewCancelBtn" data-bs-dismiss="modal">Kthehu mbrapa</button>
        <button type="button" class="btn btn-primary" id="splitPreviewOkBtn">Po, vazhdo</button>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<?php require __DIR__ . '/../shared/partials/download_generation_toast.php'; ?>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const ENDPOINT = 'groups_inline_update.php';
const EDIT_MODE = <?= $EDIT_MODE ? 'true' : 'false' ?>;

/* groupId -> i mbyllur (0/1) */
const GROUP_COMPLETED = <?= json_encode(array_column($groupInfo, 'is_completed', 'id')) ?>;

function clean(s){ return (s||'').replace(/\s+/g,' ').trim(); }

/* Njoftimet: sistemi i përbashkët (app.js) */
function notify(type, text, opts={}){
  return window.qtaToast ? window.qtaToast(text, type, opts.title, opts) : null;
}
function showMsg(type, text){ notify(type, text); }
function ask(opts){ return window.qtaConfirm ? window.qtaConfirm(opts) : Promise.resolve(window.confirm(opts.message || opts.title)); }

/* Mesazhet nga serveri pas ruajtjes (pas ringarkimit) */
const FLASH = <?= json_encode($flash_js, JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('DOMContentLoaded', ()=>{
  (FLASH.ok_list || []).forEach(m => { if (m) notify('success', m); });
  (FLASH.err_list || []).forEach(m => { if (m) notify('danger', m); });
  if (FLASH.ok)  notify('success', FLASH.ok);
  if (FLASH.err) notify('danger',  FLASH.err);
});

/* dd.mm.vvvv (ose me viza) -> vvvv-mm-dd */
function normalizeDateForServer(v){
  const s = clean(v);
  if (s === '' || s === '—') return '';
  const m = s.match(/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})$/);
  if (!m) throw new Error('Shkruaje datën si dd.mm.vvvv, p.sh. 05.03.2026.');
  return `${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}`;
}
function isoToDmy(iso){
  const m = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : '—';
}
function getGroupEndIso(gid){
  const span = document.querySelector(`.editable[data-field="end_date"][data-group="${gid}"]`);
  if (!span) return '';
  const txt = clean(span.textContent);
  if (!txt || txt==='—') return '';
  try { return normalizeDateForServer(txt); } catch(e) { return ''; }
}

async function postJSON(payload){
  const res = await fetch(ENDPOINT, {
    method: 'POST',
    headers: {'Content-Type':'application/json', 'Accept':'application/json'},
    body: JSON.stringify(payload)
  });
  let json = null;
  try{ json = await res.json(); }catch(_){ /* bosh */ }
  if (!res.ok || !json || json.ok === false){
    throw new Error((json && json.error) ? json.error : 'Ndryshimi nuk u ruajt.');
  }
  return json;
}

/* Grup i mbyllur: kërko konfirmim para çdo ndryshimi */
async function confirmCompleted(gid){
  if (Number(GROUP_COMPLETED[gid] || 0) !== 1) return { ok: true, force: false };
  const ok = await ask({
    title: 'Ky grup është i mbyllur',
    message: 'Dokumentet e këtij grupi mund të jenë lëshuar tashmë. Je i sigurt që do ta ndryshosh?',
    confirm: 'Po, ndryshoje', danger: false
  });
  return { ok, force: ok };
}

/* ===== Redaktimi në tabelë ===== */
function flashCell(el, cls){ el.classList.remove('cell-ok','cell-err'); el.classList.add(cls); setTimeout(()=>el.classList.remove(cls), 900); }

function getCellCtx(editable){
  const cell = editable.closest('.cell');
  return {
    group_id: parseInt((cell || editable).dataset.group,10)||0,
    student_id: parseInt((cell || editable).dataset.student,10)||0,
    field: String((cell || editable).dataset.field||'')
  };
}

function normalizeValueForField(field, rawValue, ctx){
  const s = clean(rawValue);
  if (s === '' || s === '—') return { value: '', display:'—' };
  if (field === 'final_score'){
    const num = Number(String(s).replace(',', '.'));
    if (!Number.isFinite(num)) throw new Error('Pikët duhet të jenë numër.');
    if (num < 0 || num > 100) throw new Error('Pikët duhet të jenë nga 0 deri në 100.');
    return { value: num, display: String(num) };
  }
  if (field === 'exam_date' || field === 'start_date' || field === 'end_date'){
    const iso = normalizeDateForServer(s);
    if (field === 'exam_date'){
      const endIso = getGroupEndIso(ctx.group_id);
      if (endIso && iso < endIso) throw new Error('Provimi nuk mund të jetë para mbarimit të grupit (' + isoToDmy(endIso) + ').');
    }
    return { value: iso, display: isoToDmy(iso) };
  }
  return { value: s, display: s };
}

function refreshStudentStatus(row, gid){
  const cell = row && row.querySelector('[data-status]');
  if (!cell) return;
  const score = clean(row.querySelector('td[data-field="final_score"] .editable')?.textContent);
  const exam = clean(row.querySelector('td[data-field="exam_date"] .editable')?.textContent);
  const mk = (label, variant, icon) => '<span class="status status-' + variant + '"><i class="bi ' + icon + '" aria-hidden="true"></i>' + label.replace(/</g,'&lt;') + '</span>';
  if (score && score !== '—' && !isNaN(score.replace(',','.'))) {
    cell.innerHTML = parseFloat(score.replace(',','.')) >= 50 ? mk('Kaloi · ' + score, 'success', 'bi-check-circle-fill') : mk('Nuk kaloi · ' + score, 'danger', 'bi-x-circle-fill');
  } else if (exam && exam !== '—') {
    let iso = ''; try { iso = normalizeDateForServer(exam); } catch(e){}
    const today = new Date().toISOString().slice(0,10);
    cell.innerHTML = (iso && iso >= today) ? mk('Provimi më ' + exam, 'info', 'bi-calendar-event') : mk('Pret rezultatin', 'warning', 'bi-clock-fill');
  }
}

async function saveEditable(editable){
  if (!EDIT_MODE) return;
  const ctx = getCellCtx(editable);
  const prev = editable.dataset.prev ?? clean(editable.textContent);
  const nowRaw = clean(editable.textContent);
  editable.textContent = nowRaw;

  let norm;
  try { norm = normalizeValueForField(ctx.field, nowRaw, ctx); }
  catch(err){ editable.textContent = prev || '—'; flashCell(editable, 'cell-err'); notify('danger', err.message); return; }
  if (clean(prev) === norm.display) { editable.textContent = prev || '—'; return; }

  const guard = await confirmCompleted(ctx.group_id);
  if (!guard.ok){ editable.textContent = prev || '—'; return; }

  const cell = editable.closest('.cell') || editable;
  try{
    cell.classList.add('cell-saving');
    const json = await postJSON({
      csrf: CSRF, action: 'update_cell',
      group_id: ctx.group_id, student_id: ctx.student_id,
      field: ctx.field, value: norm.value, force: guard.force ? 1 : 0
    });
    editable.textContent = (json && json.display) ? json.display : (norm.display || '—');
    editable.dataset.prev = editable.textContent;
    cell.classList.remove('cell-saving');
    flashCell(cell, 'cell-ok');
    refreshStudentStatus(editable.closest('tr[data-student-row]'), ctx.group_id);
    notify('success','Ndryshimi u ruajt.');
  }catch(err){
    cell.classList.remove('cell-saving');
    flashCell(cell, 'cell-err');
    editable.textContent = prev || '—';
    notify('danger', err.message || 'Ndryshimi nuk u ruajt.');
  }
}

document.querySelectorAll('.editable').forEach(ed=>{
  ed.addEventListener('keydown', (e)=>{
    if (!EDIT_MODE) return;
    if (e.key === 'Enter'){ e.preventDefault(); ed.blur(); }
    if (e.key === 'Escape'){ e.preventDefault(); ed.textContent = ed.dataset.prev ?? ed.textContent; ed.blur(); }
  });
  ed.addEventListener('focus', ()=>{ ed.dataset.prev = clean(ed.textContent); });
  ed.addEventListener('paste', (e)=>{
    e.preventDefault();
    const text = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
    document.execCommand('insertText', false, clean(text));
  });
  ed.addEventListener('blur', ()=>{ if (EDIT_MODE) saveEditable(ed); });
});

/* ===== Mbyllja e grupit ===== */
function paintGroupState(gid, done){
  const badge = document.querySelector(`.group-badge[data-group="${gid}"]`);
  if (badge) badge.textContent = done ? 'Po' : 'Jo';
  const statusCell = document.querySelector(`[data-group-status="${gid}"]`);
  if (statusCell && done) statusCell.innerHTML = '<span class="status status-success"><i class="bi bi-lock-fill" aria-hidden="true"></i>I mbyllur</span>';
  if (statusCell && !done) statusCell.innerHTML = '<span class="status status-neutral"><i class="bi bi-unlock" aria-hidden="true"></i>I hapur</span>';
  const alertBox = document.getElementById(`statusAlert_${gid}`);
  if (alertBox){
    alertBox.innerHTML = done
      ? '<i class="bi bi-lock" aria-hidden="true"></i><span><b>Grupi është i mbyllur.</b> Ndryshimet kërkojnë konfirmim, sepse dokumentet mund të jenë lëshuar.</span>'
      : '<i class="bi bi-unlock" aria-hidden="true"></i><span><b>Grupi është i hapur.</b> Kur të mbarojnë provimet, shëno "Mbyllur".</span>';
  }
}

document.querySelectorAll('.toggle-completed').forEach(sw=>{
  sw.addEventListener('change', async ()=>{
    if (!EDIT_MODE){ sw.checked = !sw.checked; return; }
    const gid = parseInt(sw.dataset.group,10)||0;
    const want = sw.checked ? 1 : 0;
    const ok = await ask(want
      ? { title: 'Të mbyllet grupi?', message: 'Mbylle kur provimet dhe pikët janë të plota. Pas mbylljes, çdo ndryshim do të kërkojë konfirmim.', confirm: 'Po, mbylle', danger: false }
      : { title: 'Të rihapet grupi?', message: 'Ky grup është i mbyllur dhe dokumentet mund të jenë lëshuar. E rihap vetëm për të korrigjuar një gabim.', confirm: 'Po, rihape', danger: true });
    if (!ok){ sw.checked = !sw.checked; return; }
    try{
      await postJSON({ csrf:CSRF, action:'set_group_completed', group_id:gid, is_completed:want });
      GROUP_COMPLETED[gid] = want;
      paintGroupState(gid, !!want);
      notify('success', want ? 'Grupi u mbyll.' : 'Grupi u rihap.');
    }catch(err){
      sw.checked = !sw.checked;
      notify('danger', err.message || 'Gjendja e grupit nuk u ndryshua.');
    }
  });
});

/* ===== Hap/mbyll të gjitha grupet ===== */
(function(){
  let allOpen = false;
  const btn = document.getElementById('toggleAllBtn');
  if (!btn) return;
  btn.addEventListener('click', ()=>{
    allOpen = !allOpen;
    document.querySelectorAll('.group-body').forEach(el=>{
      const c = bootstrap.Collapse.getOrCreateInstance(el, {toggle:false});
      allOpen ? c.show() : c.hide();
    });
    btn.setAttribute('aria-pressed', allOpen ? 'true' : 'false');
    document.getElementById('toggleAllIcon').className = allOpen ? 'bi bi-arrows-collapse' : 'bi bi-arrows-expand';
    document.getElementById('toggleAllText').textContent = allOpen ? 'Mbyll të gjitha' : 'Hap të gjitha';
  });
})();

/* Rrotullimi i shigjetës sipas gjendjes së grupit */
document.querySelectorAll('.group-body').forEach(el=>{
  const opener = document.querySelector(`[data-bs-target="#${el.id}"]`);
  el.addEventListener('show.bs.collapse', ()=> opener && opener.setAttribute('aria-expanded','true'));
  el.addEventListener('hide.bs.collapse', ()=> opener && opener.setAttribute('aria-expanded','false'));
});

/* ===== Maska e datave në formularin e krijimit ===== */
function maskToDDMMYYYY(input){
  const digits = String(input||'').replace(/\D/g,'').slice(0,8);
  let out = digits.slice(0,2);
  if (digits.length > 2) out += '.' + digits.slice(2,4);
  if (digits.length > 4) out += '.' + digits.slice(4,8);
  return out;
}
document.querySelectorAll('input.dmy').forEach(inp=>{
  inp.addEventListener('input', ()=>{ inp.value = maskToDDMMYYYY(inp.value); });
});

/* ===== Parashikimi i ndarjes dhe konfirmimet e formularëve ===== */
function parseAmzeRangesClient(s){
  const out = new Set();
  (s || '').split(',').forEach(raw => {
    const tok = raw.trim();
    if (!tok) return;
    const r = tok.match(/^(\d+)\s*-\s*(\d+)$/);
    if (r) {
      let a = parseInt(r[1],10), b = parseInt(r[2],10);
      if (a > b){ const t=a; a=b; b=t; }
      for (let i=a; i<=b; i++) out.add(i);
      return;
    }
    if (/^\d+$/.test(tok)) out.add(parseInt(tok,10));
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
    const chunk = nums.slice(cursor, cursor + size);
    cursor += size;
    if (chunk.length) parts.push({ idx: g, count: chunk.length, min: chunk[0], max: chunk[chunk.length-1] });
  }
  return parts;
}

const splitModalEl = document.getElementById('splitPreviewModal');
let splitPending = null;

function showSplitPreview(opts){
  const tbody = document.getElementById('splitPreviewTbody');
  document.getElementById('splitPreviewIntro').textContent = opts.intro;
  document.getElementById('splitPreviewNote').textContent = opts.note || '';
  const badges = document.getElementById('splitPreviewBadges');
  badges.innerHTML = '';
  (opts.badges || []).forEach(b => {
    const s = document.createElement('span');
    s.className = 'status status-' + (b.variant || 'neutral');
    s.textContent = b.text;
    badges.appendChild(s);
  });
  tbody.innerHTML = '';
  opts.parts.forEach(p => {
    const tr = document.createElement('tr');
    [opts.label(p), String(p.count), p.min + (p.max !== p.min ? '–' + p.max : '')].forEach((txt, i) => {
      const td = document.createElement('td');
      if (i === 1) td.className = 'num-col';
      if (i === 2) td.className = 'code';
      td.textContent = txt;
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });
  splitPending = { form: opts.form, host: opts.host, proceeded: false };
  if (opts.host) bootstrap.Modal.getOrCreateInstance(opts.host).hide();
  bootstrap.Modal.getOrCreateInstance(splitModalEl).show();
}

splitModalEl?.addEventListener('hidden.bs.modal', ()=>{
  if (splitPending && !splitPending.proceeded && splitPending.host) {
    bootstrap.Modal.getOrCreateInstance(splitPending.host).show();
  }
  splitPending = null;
});
document.getElementById('splitPreviewOkBtn')?.addEventListener('click', ()=>{
  if (!splitPending) return;
  const form = splitPending.form;
  splitPending.proceeded = true;
  bootstrap.Modal.getOrCreateInstance(splitModalEl).hide();
  submitNow(form);
});

function submitNow(form){
  form.dataset.ready = '1';
  const btn = form.querySelector('button[type="submit"]');
  if (btn) btn.classList.add('is-loading');
  if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
}

/* Krijimi i grupit */
document.querySelector('[data-create-group-form]')?.addEventListener('submit', (e)=>{
  const form = e.currentTarget;
  if (!EDIT_MODE || form.dataset.ready === '1') { form.dataset.ready = '0'; return; }
  const nums = parseAmzeRangesClient(form.querySelector('textarea[name="amze_spec"]')?.value);
  if (nums.length <= 10) return;
  e.preventDefault();
  const parts = buildSplitParts(nums);
  showSplitPreview({
    form, host: document.getElementById('createGroupModal'), parts,
    intro: `Ke shkruar ${nums.length} numra amze. Një grup mban deri në 10 kursantë, prandaj do të krijohen ${parts.length} grupe:`,
    badges: [{ text: nums.length + ' kursantë', variant: 'neutral' }, { text: parts.length + ' grupe', variant: 'info' }],
    label: p => 'Grupi i ri ' + (p.idx + 1),
    note: 'Nëse kthehesh mbrapa, asnjë grup nuk krijohet.'
  });
});

/* Formularët e një grupi ekzistues (kursantët, moduli, fshirja) */
document.querySelectorAll('form[data-group-form]').forEach(form => {
  form.addEventListener('submit', async (e)=>{
    if (!EDIT_MODE) return;
    if (form.dataset.ready === '1') { form.dataset.ready = '0'; return; }
    e.preventDefault();
    const gid = parseInt(form.querySelector('input[name="group_id"]')?.value || '0', 10);
    const forceInput = form.querySelector('input[name="force"]');
    if (forceInput) forceInput.value = '0';
    const guard = await confirmCompleted(gid);
    if (!guard.ok) return;
    if (guard.force && forceInput) forceInput.value = '1';

    const ta = form.querySelector('textarea[name="amze_spec_members"]');
    if (ta) {
      const nums = parseAmzeRangesClient(ta.value);
      if (nums.length > 10) {
        const original = new Set(parseAmzeRangesClient(ta.dataset.originalAmze || ''));
        const added = nums.filter(n => !original.has(n)).length;
        const removed = Array.from(original).filter(n => !nums.includes(n)).length;
        const parts = buildSplitParts(nums);
        const badges = [{ text: nums.length + ' kursantë', variant: 'neutral' }, { text: parts.length + ' grupe', variant: 'info' }];
        if (added) badges.push({ text: 'Shtohen ' + added, variant: 'success' });
        if (removed) badges.push({ text: 'Hiqen ' + removed, variant: 'danger' });
        showSplitPreview({
          form, host: form.closest('.modal'), parts, badges,
          intro: `Grupi #${gid} do të ketë ${nums.length} kursantë. Një grup mban deri në 10, prandaj do të ndahet në ${parts.length} grupe:`,
          label: p => p.idx === 0 ? `Grupi #${gid} (ky grup)` : 'Grup i ri',
          note: 'Nëse kthehesh mbrapa, asgjë nuk ndryshon.'
        });
        return;
      }
    }
    submitNow(form);
  });
});
</script>
</body>
</html>
