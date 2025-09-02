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
  $out=[]; foreach (preg_split('/\s*,\s*/', trim($s)) as $tok) {
    if ($tok==='') continue;
    if (preg_match('/^(\d+)\s*-\s*(\d+)$/',$tok,$m)) { $a=(int)$m[1]; $b=(int)$m[2]; if($a>$b) [$a,$b]=[$b,$a]; for($i=$a;$i<=$b;$i++) $out[$i]=true; }
    elseif (preg_match('/^\d+$/',$tok)) { $out[(int)$tok]=true; }
  }
  $nums=array_keys($out); sort($nums,SORT_NUMERIC); return $nums;
}

try {
  if ($action==='list_assigned') {
    if ($agency_id<=0) throw new RuntimeException('Agjencia e pavlefshme.');
    $q = $pdo->prepare("
      SELECT s.id, s.nr_amze, s.first_name, s.father_name, s.last_name, s.personal_number
      FROM agency_students ajs
      JOIN students s ON s.id=ajs.student_id
      WHERE ajs.agency_id=:a
      ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
    ");
    $q->execute([':a'=>$agency_id]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'students'=>$rows]); exit;
  }

  if ($action==='assign_by_amze') {
    if ($agency_id<=0) throw new RuntimeException('Agjencia e pavlefshme.');
    $spec = trim((string)($in['amze_spec'] ?? ''));
    if ($spec==='') throw new RuntimeException('Shkruaj AMZË (p.sh. 3400-3403, 3409).');
    $nums = parseAmzeRanges($spec);
    if (!$nums) throw new RuntimeException('Formati i AMZË-ve është i pavlefshëm.');

    // Gjej studentët ekzistues me këto AMZË
    $inQ = implode(',', array_fill(0, count($nums), '?'));
    $st  = $pdo->prepare("SELECT id, nr_amze FROM students WHERE CAST(nr_amze AS UNSIGNED) IN ($inQ)");
    foreach ($nums as $i=>$v) $st->bindValue($i+1,$v,PDO::PARAM_INT);
    $st->execute();
    $found = $st->fetchAll(PDO::FETCH_KEY_PAIR); // nr_amze=>id (jo direkt, do riorg.)

    // Harto: numër -> student_id
    $nr2id = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $nr2id[(int)$r['nr_amze']] = (int)$r['id']; }
    // Por më thjeshtë, rigjejmë si më poshtë:
    $st2 = $pdo->prepare("SELECT id, nr_amze FROM students WHERE CAST(nr_amze AS UNSIGNED) IN ($inQ)");
    foreach ($nums as $i=>$v) $st2->bindValue($i+1,$v,PDO::PARAM_INT);
    $st2->execute();
    $nr2id = [];
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) $nr2id[(int)$r['nr_amze']] = (int)$r['id'];

    $missing = array_values(array_diff($nums, array_keys($nr2id)));
    if ($missing) {
      $missStr = implode(', ', $missing);
      throw new RuntimeException("Këto AMZË nuk u gjetën: $missStr");
    }

    // Kontrollo nëse ndonjëri është i lidhur me agjenci tjetër
    $inS = implode(',', array_fill(0, count($nr2id), '?'));
    $chk = $pdo->prepare("SELECT student_id FROM agency_students WHERE student_id IN ($inS)");
    $i=1; foreach ($nr2id as $sid) $chk->bindValue($i++,$sid,PDO::PARAM_INT);
    $chk->execute();
    $already = $chk->fetchAll(PDO::FETCH_COLUMN, 0);
    if ($already) {
      // gjej AMZË-t e përplasuara
      $sidSet = array_flip($already);
      $bad = [];
      foreach ($nr2id as $amz=>$sid) if (isset($sidSet[$sid])) $bad[] = $amz;
      $badStr = implode(', ', $bad);
      throw new RuntimeException("Disa AMZË tashmë janë të lidhura me një agjenci tjetër: $badStr");
    }

    // Lidh
    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO agency_students (agency_id, student_id) VALUES (:a,:s)");
    foreach ($nr2id as $sid) $ins->execute([':a'=>$agency_id, ':s'=>$sid]);
    $pdo->commit();

    echo json_encode(['ok'=>true,'added'=>count($nr2id)]); exit;
  }

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
