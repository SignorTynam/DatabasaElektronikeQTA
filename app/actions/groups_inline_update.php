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
$u = $pdo->prepare("
  SELECT u.id, r.name AS role_name
  FROM users u JOIN roles r ON r.id = u.role_id
  WHERE u.id = :id
  LIMIT 1
");
$u->execute([':id' => $_SESSION['user_id']]);
$me = $u->fetch(PDO::FETCH_ASSOC);

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

/* Input */
$raw  = file_get_contents('php://input');
$data = json_decode($raw,true);
if(!is_array($data)) $data = $_POST;

$csrf = $data['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$csrf)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'CSRF token mismatch.']); exit;
}

$action     = $data['action'] ?? '';
$student_id = (int)($data['student_id'] ?? 0);
$group_id   = isset($data['group_id']) && $data['group_id']!=='' ? (int)$data['group_id'] : null;

if ($group_id === null || $group_id <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'ID grupi i pavlefshëm.']); exit;
}

/* Helpers */
function fmt_dMY(?string $iso): string {
  if (!$iso) return '—';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return $iso;
  $ts = strtotime($iso);
  return $ts ? date('d-m-Y', $ts) : '—';
}
function to_iso_date(?string $v): ?string {
  if ($v === null) return null;
  $v = trim((string)$v);
  if ($v === '') return null;
  if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) {
    $yy=$m[1]; $mm=str_pad($m[2],2,'0',STR_PAD_LEFT); $dd=str_pad($m[3],2,'0',STR_PAD_LEFT);
    return "{$yy}-{$mm}-{$dd}";
  }
  if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $v, $m)) {
    $dd=str_pad($m[1],2,'0',STR_PAD_LEFT); $mm=str_pad($m[2],2,'0',STR_PAD_LEFT); $yy=$m[3];
    return "{$yy}-{$mm}-{$dd}";
  }
  throw new RuntimeException('Formati i datës duhet të jetë DD-MM-YYYY.');
}

/* Lexo grupin (start/end/completed) */
$ginfo = $pdo->prepare("SELECT start_date, end_date, is_completed FROM course_groups WHERE id=:gid");
$ginfo->execute([':gid'=>$group_id]);
$G = $ginfo->fetch(PDO::FETCH_ASSOC);
if (!$G) { echo json_encode(['ok'=>false,'error'=>'Grupi nuk u gjet.']); exit; }

$completed = (int)($G['is_completed'] ?? 0) === 1;
$forced = !empty($data['force']);

/* Nëse i përfunduar dhe s’ka 'force', kërko konfirmim (UI e bën; këtu e zbatojmë si rregull) */
if ($completed && !$forced && $action !== 'set_group_completed') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Ky grup është i përfunduar. Konfirmo veprimin.']); exit;
}

try {

  /* === Toggle/Set Completed === */
  if ($action === 'set_group_completed') {
    $is_completed = (int)($data['is_completed'] ?? 0) === 1 ? 1 : 0;
    $st = $pdo->prepare("UPDATE course_groups SET is_completed=:v WHERE id=:gid");
    $st->execute([':v'=>$is_completed, ':gid'=>$group_id]);
    echo json_encode([
      'ok'=>true,
      'is_completed'=>$is_completed,
      'badge_text'=> ($is_completed? 'I përfunduar' : 'Jo i përfunduar'),
      'badge_class'=> ($is_completed? 'bg-success' : 'bg-warning text-dark')
    ]); exit;
  }

  /* === Generic: update_cell (për inline editing nga JS) === */
  if ($action === 'update_cell') {
    $field = (string)($data['field'] ?? '');
    $value = $data['value'] ?? null;

    switch ($field) {
      case 'start_date': {
        $start = to_iso_date(($value === '' ? null : (string)$value));
        if ($start === null) throw new RuntimeException('Data e fillimit s’mund të jetë bosh.');
        if (!empty($G['end_date']) && $G['end_date'] < $start) {
          throw new RuntimeException('Data e mbarimit duhet të jetë ≥ datës së fillimit.');
        }
        $st = $pdo->prepare("UPDATE course_groups SET start_date=:s WHERE id=:gid");
        $st->execute([':s'=>$start, ':gid'=>$group_id]);
        echo json_encode(['ok'=>true,'display'=>fmt_dMY($start)]); exit;
      }

      case 'end_date': {
        $end = to_iso_date(($value === '' ? null : (string)$value));
        if ($end === null) throw new RuntimeException('Data e mbarimit s’mund të jetë bosh.');

        $start = $G['start_date'];
        if (!empty($start) && $end < $start) {
          throw new RuntimeException('Data e mbarimit duhet të jetë ≥ datës së fillimit.');
        }

        // Guard: asnjë student të mos ketë exam_date < end_date e re
        $q = $pdo->prepare("SELECT COUNT(*) FROM course_group_students WHERE group_id=:g AND exam_date IS NOT NULL AND exam_date < :e");
        $q->execute([':g'=>$group_id, ':e'=>$end]);
        if ((int)$q->fetchColumn() > 0) {
          throw new RuntimeException('Ka studentë me datë provimi më herët se mbarimi i grupit. Përditëso fillimisht datat e provimit të atyre studentëve.');
        }

        $st = $pdo->prepare("UPDATE course_groups SET end_date=:e WHERE id=:gid");
        $st->execute([':e'=>$end, ':gid'=>$group_id]);
        echo json_encode(['ok'=>true,'display'=>fmt_dMY($end)]); exit;
      }

      case 'exam_date': {
        if ($student_id <= 0) throw new RuntimeException('ID studenti e pavlefshme.');
        // Sigurohu që studenti i përket grupit dhe lexo notën aktuale
        $chk = $pdo->prepare("SELECT final_score FROM course_group_students WHERE group_id=:g AND student_id=:s LIMIT 1");
        $chk->execute([':g'=>$group_id, ':s'=>$student_id]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Ky student nuk i përket këtij grupi.');

        if ($value === '' || $value === null) {
          if ($row['final_score'] !== null) throw new RuntimeException('S’mund të hiqet data e testit kur nota ekziston. Hiqe notën fillimisht.');
          $st = $pdo->prepare("UPDATE course_group_students SET exam_date=NULL WHERE group_id=:g AND student_id=:s");
          $st->execute([':g'=>$group_id, ':s'=>$student_id]);
          echo json_encode(['ok'=>true,'display'=>'—']); exit;
        }

        $iso = to_iso_date((string)$value);
        if (!empty($G['end_date']) && $iso < $G['end_date']) {
          throw new RuntimeException('Data e testit duhet të jetë ≥ datës së mbarimit të grupit.');
        }

        $st = $pdo->prepare("UPDATE course_group_students SET exam_date=:d WHERE group_id=:g AND student_id=:s");
        $st->execute([':d'=>$iso, ':g'=>$group_id, ':s'=>$student_id]);
        echo json_encode(['ok'=>true,'display'=>fmt_dMY($iso)]); exit;
      }

      case 'final_score': {
        if ($student_id <= 0) throw new RuntimeException('ID studenti e pavlefshme.');
        // Sigurohu që studenti i përket këtij grupi dhe lexo exam_date
        $chk = $pdo->prepare("SELECT exam_date FROM course_group_students WHERE group_id=:g AND student_id=:s");
        $chk->execute([':g'=>$group_id, ':s'=>$student_id]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Ky student nuk i përket këtij grupi.');

        if ($value === '' || $value === null) {
          $st = $pdo->prepare("UPDATE course_group_students SET final_score=NULL WHERE group_id=:g AND student_id=:s");
          $st->execute([':g'=>$group_id, ':s'=>$student_id]);
          echo json_encode(['ok'=>true,'display'=>'—']); exit;
        }

        $val = str_replace(',', '.', trim((string)$value));
        if (!is_numeric($val)) throw new RuntimeException('Nota duhet të jetë numër.');
        $num = (float)$val;
        if ($num < 0 || $num > 100) throw new RuntimeException('Nota duhet në intervalin 0–100.');

        if (empty($row['exam_date'])) throw new RuntimeException('S’lejohet nota pa caktuar datën e testit për studentin.');
        if (!empty($G['end_date']) && $row['exam_date'] < $G['end_date']) {
          throw new RuntimeException('Data e testit e studentit është para datës së mbarimit të grupit. Përditëso datat.');
        }

        $st = $pdo->prepare("UPDATE course_group_students SET final_score=:v WHERE group_id=:g AND student_id=:s");
        $st->execute([':v'=>$num, ':g'=>$group_id, ':s'=>$student_id]);

        $disp = rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
        echo json_encode(['ok'=>true,'display'=>$disp]); exit;
      }

      default:
        throw new RuntimeException('Fushë e panjohur për përditësim.');
    }
  }

  /* === START DATE I GRUPIT === */
  if ($action === 'update_group_start') {
    $start = to_iso_date($data['start_date'] ?? null);
    if ($start === null) throw new RuntimeException('Data e fillimit s’mund të jetë bosh.');
    if (!empty($G['end_date']) && $G['end_date'] < $start) throw new RuntimeException('Data e mbarimit duhet të jetë ≥ datës së fillimit.');

    $st = $pdo->prepare("UPDATE course_groups SET start_date=:s WHERE id=:gid");
    $st->execute([':s'=>$start, ':gid'=>$group_id]);
    echo json_encode(['ok'=>true,'display'=>fmt_dMY($start)]); exit;
  }

  /* === END DATE I GRUPIT === */
  if ($action === 'update_group_end') {
    $end = to_iso_date($data['end_date'] ?? null);
    if ($end === null) throw new RuntimeException('Data e mbarimit s’mund të jetë bosh.');

    $start = $G['start_date'];
    if (!empty($start) && $end < $start) throw new RuntimeException('Data e mbarimit duhet të jetë ≥ datës së fillimit.');

    // Guard: asnjë student të mos ketë exam_date < end_date e re
    $q = $pdo->prepare("SELECT COUNT(*) FROM course_group_students WHERE group_id=:g AND exam_date IS NOT NULL AND exam_date < :e");
    $q->execute([':g'=>$group_id, ':e'=>$end]);
    if ((int)$q->fetchColumn() > 0) {
      throw new RuntimeException('Ka studentë me datë provimi më herët se mbarimi i grupit. Përditëso fillimisht datat e provimit të atyre studentëve.');
    }

    $st = $pdo->prepare("UPDATE course_groups SET end_date=:e WHERE id=:gid");
    $st->execute([':e'=>$end, ':gid'=>$group_id]);
    echo json_encode(['ok'=>true,'display'=>fmt_dMY($end)]); exit;
  }

  /* === EXAM DATE PER-STUDENT === */
  if ($action === 'update_student_exam_date') {
    if ($student_id <= 0) throw new RuntimeException('ID studenti e pavlefshme.');

    $chk = $pdo->prepare("
      SELECT final_score FROM course_group_students
      WHERE group_id = :gid AND student_id = :sid
      LIMIT 1
    ");
    $chk->execute([':gid'=>$group_id, ':sid'=>$student_id]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Ky student nuk i përket këtij grupi.');

    $exam = $data['exam_date'] ?? null;

    if ($exam === '' || $exam === null) {
      if ($row['final_score'] !== null) {
        throw new RuntimeException('S’mund të hiqet data e testit kur nota ekziston. Hiqe notën fillimisht.');
      }
      $st = $pdo->prepare("UPDATE course_group_students SET exam_date=NULL WHERE group_id=:g AND student_id=:s");
      $st->execute([':g'=>$group_id, ':s'=>$student_id]);
      echo json_encode(['ok'=>true,'display'=>'—']); exit;
    }

    $iso = to_iso_date($exam);
    if (!empty($G['end_date']) && $iso < $G['end_date']) {
      throw new RuntimeException('Data e testit duhet të jetë ≥ datës së mbarimit të grupit.');
    }

    $st = $pdo->prepare("UPDATE course_group_students SET exam_date=:d WHERE group_id=:g AND student_id=:s");
    $st->execute([':d'=>$iso, ':g'=>$group_id, ':s'=>$student_id]);
    echo json_encode(['ok'=>true,'display'=>fmt_dMY($iso)]); exit;
  }

  /* === NOTA PER-STUDENT === */
  if ($action === 'update_final_score') {
    if ($student_id <= 0) throw new RuntimeException('ID studenti e pavlefshme.');

    $chk = $pdo->prepare("SELECT exam_date FROM course_group_students WHERE group_id=:g AND student_id=:s");
    $chk->execute([':g'=>$group_id, ':s'=>$student_id]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Ky student nuk i përket këtij grupi.');

    $score = $data['final_score'] ?? null;

    if ($score === '' || $score === null) {
      $st = $pdo->prepare("UPDATE course_group_students SET final_score=NULL WHERE group_id=:g AND student_id=:s");
      $st->execute([':g'=>$group_id, ':s'=>$student_id]);
      echo json_encode(['ok'=>true,'display'=>'—']); exit;
    }

    $val = str_replace(',', '.', trim((string)$score));
    if (!is_numeric($val)) throw new RuntimeException('Nota duhet të jetë numër.');
    $num = (float)$val;
    if ($num < 0 || $num > 100) throw new RuntimeException('Nota duhet në intervalin 0–100.');

    if (empty($row['exam_date'])) throw new RuntimeException('S’lejohet nota pa caktuar datën e testit për studentin.');
    if (!empty($G['end_date']) && $row['exam_date'] < $G['end_date']) {
      throw new RuntimeException('Data e testit e studentit është para datës së mbarimit të grupit. Përditëso datat.');
    }

    $st = $pdo->prepare("UPDATE course_group_students SET final_score=:v WHERE group_id=:g AND student_id=:s");
    $st->execute([':v'=>$num, ':g'=>$group_id, ':s'=>$student_id]);

    $disp = rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
    echo json_encode(['ok'=>true,'display'=>$disp]); exit;
  }

  echo json_encode(['ok'=>false,'error'=>'Veprim i panjohur.']);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
