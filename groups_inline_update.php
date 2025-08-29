<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

header('Content-Type: application/json; charset=UTF-8');

$pdo = getPDO();

/* Guard admin */
if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'Nuk jeni i autentikuar.']);
  exit;
}
$u = $pdo->prepare("
  SELECT u.id, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$me = $u->fetch(PDO::FETCH_ASSOC);
if (!$me || $me['role_name']!=='administrator') {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Lejohet vetëm për administrator.']);
  exit;
}

/* Input */
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$csrf = $data['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'CSRF token mismatch.']);
  exit;
}

$action     = $data['action'] ?? '';
$student_id = (int)($data['student_id'] ?? 0);
$group_id   = isset($data['group_id']) && $data['group_id']!=='' ? (int)$data['group_id'] : null;

if ($group_id === null || $group_id <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'ID grupi i pavlefshëm.']);
  exit;
}

/* Lexo grupin (start/end) */
$ginfo = $pdo->prepare("SELECT start_date, end_date FROM course_groups WHERE id=:gid");
$ginfo->execute([':gid'=>$group_id]);
$G = $ginfo->fetch(PDO::FETCH_ASSOC);
if (!$G) {
  echo json_encode(['ok'=>false,'error'=>'Grupi nuk u gjet.']);
  exit;
}

try {

  /* === Përditëso datën e fillimit të grupit === */
  if ($action === 'update_group_start') {
    $start = $data['start_date'] ?? null;
    if ($start === '' || $start === null) throw new RuntimeException('Data e fillimit s’mund të jetë bosh.');
    $start = trim((string)$start);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) throw new RuntimeException('Formati i datës së fillimit është i pavlefshëm (YYYY-MM-DD).');
    if (!empty($G['end_date']) && $G['end_date'] < $start) throw new RuntimeException('Data e mbarimit duhet të jetë ≥ datës së fillimit.');

    $st = $pdo->prepare("UPDATE course_groups SET start_date=:s WHERE id=:gid");
    $st->execute([':s'=>$start, ':gid'=>$group_id]);
    echo json_encode(['ok'=>true,'display'=>$start]); exit;
  }

  /* === Përditëso datën e mbarimit të grupit === */
  if ($action === 'update_group_end') {
    $end = $data['end_date'] ?? null;
    if ($end === '' || $end === null) throw new RuntimeException('Data e mbarimit s’mund të jetë bosh.');
    $end = trim((string)$end);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) throw new RuntimeException('Formati i datës së mbarimit është i pavlefshëm (YYYY-MM-DD).');

    $start = $G['start_date'];
    if (!empty($start) && $end < $start) throw new RuntimeException('Data e mbarimit duhet të jetë ≥ datës së fillimit.');

    // Kontrollo që asnjë student në këtë grup të mos ketë exam_date < end
    $q = $pdo->prepare("
      SELECT COUNT(*) FROM course_group_students
      WHERE group_id = :gid AND exam_date IS NOT NULL AND exam_date < :end
    ");
    $q->execute([':gid'=>$group_id, ':end'=>$end]);
    $violations = (int)$q->fetchColumn();
    if ($violations > 0) {
      throw new RuntimeException('Ka '.$violations.' student(ë) me datë testimi para datës së re të mbarimit. Ndrysho/zbraz ato data më parë.');
    }

    $st = $pdo->prepare("UPDATE course_groups SET end_date=:e WHERE id=:gid");
    $st->execute([':e'=>$end, ':gid'=>$group_id]);
    echo json_encode(['ok'=>true,'display'=>$end]); exit;
  }

  /* === (Legacy) update_exam_date në nivel grupi – JO i mbështetur më === */
  if ($action === 'update_exam_date') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Datat e testit tani vendosen për çdo student. Përdor veprimin update_student_exam_date.']);
    exit;
  }

  /* === Përditëso datën e testit për studentin === */
  if ($action === 'update_student_exam_date') {
    if ($student_id <= 0) throw new RuntimeException('ID studenti e pavlefshme.');

    // Sigurohu që studenti i përket grupit dhe lexo notën aktuale
    $chk = $pdo->prepare("
      SELECT final_score FROM course_group_students
      WHERE group_id = :gid AND student_id = :sid
      LIMIT 1
    ");
    $chk->execute([':gid'=>$group_id, ':sid'=>$student_id]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Ky student nuk i përket këtij grupi.');

    $exam = $data['exam_date'] ?? null; // mund të vijë '' / null / 'YYYY-MM-DD'

    if ($exam === '' || $exam === null) {
      // nëse ka notë, nuk lejojmë heqjen e datës së testit
      if ($row['final_score'] !== null) {
        throw new RuntimeException('S’mund të hiqet data e testit kur nota ekziston. Hiqe notën fillimisht.');
      }
      $st = $pdo->prepare("
        UPDATE course_group_students SET exam_date = NULL
        WHERE group_id = :gid AND student_id = :sid
      ");
      $st->execute([':gid'=>$group_id, ':sid'=>$student_id]);
      echo json_encode(['ok'=>true,'display'=>'—']); exit;
    }

    $exam = trim((string)$exam);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exam)) throw new RuntimeException('Formati i datës së testit është i pavlefshëm (YYYY-MM-DD).');

    // exam_date duhet të jetë ≥ end_date, nëse end_date ekziston
    if (!empty($G['end_date']) && $exam < $G['end_date']) {
      throw new RuntimeException('Data e testit duhet të jetë ≥ datës së mbarimit të grupit.');
    }

    $st = $pdo->prepare("
      UPDATE course_group_students SET exam_date = :d
      WHERE group_id = :gid AND student_id = :sid
    ");
    $st->execute([':d'=>$exam, ':gid'=>$group_id, ':sid'=>$student_id]);
    echo json_encode(['ok'=>true,'display'=>$exam]); exit;
  }

  /* === Përditëso notën për studentin === */
  if ($action === 'update_final_score') {
    if ($student_id <= 0) throw new RuntimeException('ID studenti e pavlefshme.');

    // Sigurohu që studenti i përket këtij grupi dhe lexo exam_date per-student
    $chk = $pdo->prepare("
      SELECT exam_date FROM course_group_students
      WHERE group_id = :gid AND student_id = :sid
      LIMIT 1
    ");
    $chk->execute([':gid'=>$group_id, ':sid'=>$student_id]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Ky student nuk i përket këtij grupi.');

    $score = $data['final_score'] ?? null;

    if ($score === '' || $score === null) {
      $st = $pdo->prepare("
        UPDATE course_group_students SET final_score=NULL
        WHERE group_id=:gid AND student_id=:sid
      ");
      $st->execute([':gid'=>$group_id, ':sid'=>$student_id]);
      echo json_encode(['ok'=>true,'display'=>'—']); exit;
    }

    $val = str_replace(',', '.', trim((string)$score));
    if (!is_numeric($val)) throw new RuntimeException('Nota duhet të jetë numër.');
    $num = (float)$val;
    if ($num < 0 || $num > 100) throw new RuntimeException('Nota duhet në intervalin 0–100.');

    // Nota kërkon exam_date per-student
    if (empty($row['exam_date'])) {
      throw new RuntimeException('S’lejohet nota pa caktuar datën e testit për studentin.');
    }
    // Siguri shtesë: exam_date ≥ end_date
    if (!empty($G['end_date']) && $row['exam_date'] < $G['end_date']) {
      throw new RuntimeException('Data e testit e studentit është para datës së mbarimit të grupit. Përditëso datat.');
    }

    $st = $pdo->prepare("
      UPDATE course_group_students SET final_score=:v
      WHERE group_id=:gid AND student_id=:sid
    ");
    $st->execute([':v'=>$num, ':gid'=>$group_id, ':sid'=>$student_id]);

    $disp = rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
    echo json_encode(['ok'=>true,'display'=>$disp]); exit;
  }

  echo json_encode(['ok'=>false,'error'=>'Veprim i panjohur.']);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
