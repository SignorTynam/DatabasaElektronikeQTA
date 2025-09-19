<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

function out($x){ echo json_encode($x); exit; }
function err($m){ out(['ok'=>false,'error'=>$m]); }

/* Guard */
if (!isset($_SESSION['user_id'])) err('Nuk jeni i autentikuar.');
$u = $pdo->prepare("
  SELECT u.id, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$me = $u->fetch(PDO::FETCH_ASSOC);
$role = strtolower((string)($me['role_name'] ?? ''));
if (!$me || !in_array($role, ['administrator','editor'], true)) err('S’keni të drejta.');

$raw = file_get_contents('php://input');
$in = json_decode($raw, true) ?: [];
if (empty($in['csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$in['csrf'])) err('CSRF mismatch.');

/* Veprime */
$action = (string)($in['action'] ?? '');

function qta_audit_event(string $type, array $payload): void {
  try { if (function_exists('qta_audit_log')) { qta_audit_log($GLOBALS['pdo'] ?? null, $type, $payload); } }
  catch(Throwable $e){ /* ignore */ }
}

/* Helpers validation */
function groupCapacity(PDO $pdo, int $group_id): int {
  $q = $pdo->prepare("SELECT COUNT(*) FROM course_group_students WHERE group_id=:g");
  $q->execute([':g'=>$group_id]);
  return (int)$q->fetchColumn();
}
function groupCourseId(PDO $pdo, int $group_id): int {
  $q = $pdo->prepare("SELECT course_id FROM course_groups WHERE id=:g");
  $q->execute([':g'=>$group_id]);
  return (int)($q->fetchColumn() ?: 0);
}
function studentHasTakenCourse(PDO $pdo, int $student_id, int $course_id): bool {
  $q = $pdo->prepare("
    SELECT 1
    FROM course_group_students cgs
    JOIN course_groups cg ON cg.id = cgs.group_id
    WHERE cgs.student_id = :s AND cg.course_id = :c
    LIMIT 1
  ");
  $q->execute([':s'=>$student_id, ':c'=>$course_id]);
  return (bool)$q->fetchColumn();
}
function anyPersonWithSamePNHasTakenCourse(PDO $pdo, int $student_id, int $course_id): ?array {
  // kthe AMZE që konfliktuan sipas personal_number
  $q = $pdo->prepare("
    SELECT DISTINCT p.personal_number
    FROM students s
    JOIN persons p ON p.id = s.person_id
    WHERE s.id = :sid
      AND p.personal_number IS NOT NULL AND p.personal_number <> ''
  ");
  $q->execute([':sid'=>$student_id]);
  $pn = (string)($q->fetchColumn() ?: '');
  if ($pn === '') return null;

  $q2 = $pdo->prepare("
    SELECT DISTINCT s.nr_amze
    FROM students s
    JOIN persons p ON p.id = s.person_id
    JOIN course_group_students cgs ON cgs.student_id = s.id
    JOIN course_groups cg ON cg.id = cgs.group_id
    WHERE p.personal_number = :pn
      AND cg.course_id = :c
  ");
  $q2->execute([':pn'=>$pn, ':c'=>$course_id]);
  $hits = $q2->fetchAll(PDO::FETCH_COLUMN);
  return $hits ?: null;
}

/* ====== assign_to_group ====== */
if ($action === 'assign_to_group') {
  $student_id = (int)($in['student_id'] ?? 0);
  $group_id   = (int)($in['group_id'] ?? 0);
  if ($student_id<=0 || $group_id<=0) err('Të dhëna të pavlefshme.');

  // ekzistenca e grupit
  $cid = groupCourseId($pdo, $group_id);
  if ($cid<=0) err('Grupi nuk ekziston.');

  // student s’duhet të jetë tashmë në ndonjë grup (faqja targeton pa grup)
  $q = $pdo->prepare("SELECT 1 FROM course_group_students WHERE student_id=:s LIMIT 1");
  $q->execute([':s'=>$student_id]);
  if ($q->fetchColumn()) err('Ky student tashmë ka një grup.');

  // kapaciteti
  if (groupCapacity($pdo, $group_id) >= 10) err('Ky grup është i mbushur (10/10).');

  // nuk lejohet njëjtin modul dy herë
  if (studentHasTakenCourse($pdo, $student_id, $cid)) err('Ky student e ka ndjekur tashmë këtë modul.');

  // kontrollo sipas personal_number
  $hits = anyPersonWithSamePNHasTakenCourse($pdo, $student_id, $cid);
  if ($hits) err('Persona me të njëjtin ID personal e kanë ndjekur tashmë këtë modul: '.implode(', ', $hits));

  // vendos
  $ins = $pdo->prepare("INSERT INTO course_group_students (group_id, student_id) VALUES (:g,:s)");
  $ins->execute([':g'=>$group_id, ':s'=>$student_id]);

  // nqs kishte “plan”, opsionale: ta shënojmë si “assigned”
  try{
    $pdo->prepare("UPDATE student_course_plans SET status='assigned' WHERE student_id=:s AND course_id=:c AND status='planned'")
        ->execute([':s'=>$student_id, ':c'=>$cid]);
  } catch(Throwable $e){ /* nëse mungon tabela, ignore */ }

  qta_audit_event('student.assign_group', [
    'student_id'=>$student_id,
    'group_id'=>$group_id,
    'course_id'=>$cid,
    'actor_user_id'=>$_SESSION['user_id'] ?? null
  ]);

  out(['ok'=>true]);
}

/* ====== set_student_plan (ndrysho/cakto modul) ====== */
if ($action === 'set_student_plan') {
  $student_id = (int)($in['student_id'] ?? 0);
  $course_id  = (int)($in['course_id'] ?? 0);
  if ($student_id<=0 || $course_id<=0) err('Të dhëna të pavlefshme.');

  // siguro që s’ka plan duplicate: fshi planned e vjetër dhe vendos të riun
  try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM student_course_plans WHERE student_id=:s AND status='planned'")
        ->execute([':s'=>$student_id]);
    $pdo->prepare("INSERT INTO student_course_plans (student_id, course_id, status, created_at)
                   VALUES (:s,:c,'planned', NOW())")
        ->execute([':s'=>$student_id, ':c'=>$course_id]);
    $pdo->commit();
  } catch(Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    err('S’u ruajt moduli (plan).');
  }

  qta_audit_event('student.update_plan', [
    'student_id'=>$student_id,
    'course_id'=>$course_id,
    'actor_user_id'=>$_SESSION['user_id'] ?? null
  ]);

  out(['ok'=>true]);
}

err('Veprim i panjohur.');
