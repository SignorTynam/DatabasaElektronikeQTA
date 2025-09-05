<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

header('Content-Type: application/json; charset=UTF-8');
$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* Guard: admin OSE editor */
if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'Nuk jeni i autentikuar.']); exit;
}
$me = $pdo->prepare("
  SELECT u.id, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$me->execute([':id'=>$_SESSION['user_id']]);
$u = $me->fetch();

$role = strtolower((string)($u['role_name'] ?? ''));
if (!$u || !in_array($role, ['administrator','editor'], true)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Lejohet vetëm për administrator ose editor.']); exit;
}

/* CSRF + leximi i input */
$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

$csrf = $in['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>'CSRF token mismatch.']); exit;
}

$action    = $in['action'] ?? '';
$agency_id = isset($in['agency_id']) ? (int)$in['agency_id'] : 0;

function parseAmzeRanges(string $s): array {
  $out=[];
  foreach (preg_split('/\s*,\s*/', trim($s)) as $tok) {
    if ($tok==='') continue;
    if (preg_match('/^(\d+)\s*-\s*(\d+)$/',$tok,$m)) {
      $a=(int)$m[1]; $b=(int)$m[2];
      if($a>$b) [$a,$b]=[$b,$a];
      for($i=$a;$i<=$b;$i++) $out[$i]=true;
    } elseif (preg_match('/^\d+$/',$tok)) {
      $out[(int)$tok]=true;
    }
  }
  $nums=array_keys($out);
  sort($nums,SORT_NUMERIC);
  return $nums;
}

try {

  /* =========================
     LISTO STUDENTËT E LIDHUR
     (bashko me persons p)
  ==========================*/
  if ($action==='list_assigned') {
    if ($agency_id<=0) throw new RuntimeException('Agjencia e pavlefshme.');
    $q = $pdo->prepare("
      SELECT
        s.id,
        s.nr_amze,
        p.first_name,
        p.father_name,
        p.last_name,
        p.personal_number
      FROM agency_students ajs
      JOIN students s     ON s.id = ajs.student_id
      LEFT JOIN persons p ON p.id = s.person_id
      WHERE ajs.agency_id = :a
      ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
    ");
    $q->execute([':a'=>$agency_id]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'students'=>$rows]); exit;
  }

  /* =========================
     SHTO LIDHJE NGA AMZË
  ==========================*/
  if ($action==='assign_by_amze') {
    if ($agency_id<=0) throw new RuntimeException('Agjencia e pavlefshme.');
    $spec = trim((string)($in['amze_spec'] ?? ''));
    if ($spec==='') throw new RuntimeException('Shkruaj AMZË (p.sh. 3400-3403, 3409).');
    $nums = parseAmzeRanges($spec);
    if (!$nums) throw new RuntimeException('Formati i AMZË-ve është i pavlefshëm.');

    // gjej studentët ekzistues me këto AMZË (map: amz(int) => student_id)
    $place = implode(',', array_fill(0, count($nums), '?'));
    $st = $pdo->prepare("
      SELECT id, CAST(nr_amze AS UNSIGNED) AS amz
      FROM students
      WHERE CAST(nr_amze AS UNSIGNED) IN ($place)
    ");
    foreach ($nums as $i=>$v) $st->bindValue($i+1, $v, PDO::PARAM_INT);
    $st->execute();
    $nr2id = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $nr2id[(int)$r['amz']] = (int)$r['id'];
    }

    // mungesat
    $foundAmz = array_map('intval', array_keys($nr2id));
    $missing = array_values(array_diff($nums, $foundAmz));
    if ($missing) {
      $missStr = implode(', ', $missing);
      throw new RuntimeException("Këto AMZË nuk u gjetën: $missStr");
    }

    // kontrollo lidhje ekzistuese për këta studentë
    $studentIds = array_values($nr2id);
    $placeS = implode(',', array_fill(0, count($studentIds), '?'));
    $chk = $pdo->prepare("
      SELECT student_id, agency_id
      FROM agency_students
      WHERE student_id IN ($placeS)
    ");
    foreach ($studentIds as $i=>$sid) $chk->bindValue($i+1, $sid, PDO::PARAM_INT);
    $chk->execute();
    $rows = $chk->fetchAll(PDO::FETCH_ASSOC);

    // ndaji në: tashmë në të njëjtën agjenci (ignore) dhe në agjenci tjetër (gabim)
    $alreadySame   = [];
    $alreadyOther  = [];
    $bySidToAmz    = array_flip($nr2id); // student_id => amz

    foreach ($rows as $r) {
      $sid = (int)$r['student_id'];
      $aid = (int)$r['agency_id'];
      if ($aid === $agency_id) {
        $alreadySame[] = $bySidToAmz[$sid] ?? $sid;
      } else {
        $alreadyOther[] = $bySidToAmz[$sid] ?? $sid;
      }
    }

    if ($alreadyOther) {
      $badStr = implode(', ', $alreadyOther);
      throw new RuntimeException("Disa AMZË janë të lidhura me një agjenci tjetër: $badStr");
    }

    // filtro ata që s’janë ende në këtë agjenci
    $toInsertSids = [];
    foreach ($nr2id as $amz=>$sid) {
      if (!in_array($amz, $alreadySame, true)) {
        $toInsertSids[] = $sid;
      }
    }

    if (!$toInsertSids) {
      echo json_encode(['ok'=>true,'added'=>0,'info'=>'Të gjitha AMZË-t ishin tashmë të lidhura me këtë agjenci.']); exit;
    }

    // lidhje në DB
    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO agency_students (agency_id, student_id) VALUES (:a,:s)");
    foreach ($toInsertSids as $sid) {
      $ins->execute([':a'=>$agency_id, ':s'=>$sid]);
    }
    $pdo->commit();

    echo json_encode(['ok'=>true,'added'=>count($toInsertSids)]); exit;
  }

  /* =========================
     HIQ LIDHJEN
  ==========================*/
  if ($action==='unlink') {
    $student_id = (int)($in['student_id'] ?? 0);
    if ($agency_id<=0 || $student_id<=0) throw new RuntimeException('Parametra të pavlefshëm.');
    $del = $pdo->prepare("DELETE FROM agency_students WHERE agency_id=:a AND student_id=:s");
    $del->execute([':a'=>$agency_id, ':s'=>$student_id]);
    echo json_encode(['ok'=>true]); exit;
  }

  echo json_encode(['ok'=>false,'error'=>'Veprim i panjohur.']);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
