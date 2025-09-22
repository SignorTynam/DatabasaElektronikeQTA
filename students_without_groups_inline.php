<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

function out($x){ echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }
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

$action = (string)($in['action'] ?? '');

function qta_audit_event(string $type, array $payload): void {
  try { if (function_exists('qta_audit_log')) { qta_audit_log($GLOBALS['pdo'] ?? null, $type, $payload); } }
  catch(Throwable $e){ /* ignore */ }
}

/* Helpers */
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
function studentInAnyGroup(PDO $pdo, int $student_id): bool {
  $q = $pdo->prepare("SELECT 1 FROM course_group_students WHERE student_id=:s LIMIT 1");
  $q->execute([':s'=>$student_id]);
  return (bool)$q->fetchColumn();
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

  // ekzistenca e grupit dhe kursit
  $cid = groupCourseId($pdo, $group_id);
  if ($cid<=0) err('Grupi nuk ekziston.');

  // një AMZË s’mund të jetë njëkohësisht “me modul (plan)” dhe “në grup”
  if (studentInAnyGroup($pdo, $student_id)) err('Ky student tashmë ka një grup.');

  // kapaciteti
  if (groupCapacity($pdo, $group_id) >= 10) err('Ky grup është i mbushur (10/10).');

  // nuk lejohet njëjtin modul dy herë
  if (studentHasTakenCourse($pdo, $student_id, $cid)) err('Ky student e ka ndjekur tashmë këtë modul.');
  $hits = anyPersonWithSamePNHasTakenCourse($pdo, $student_id, $cid);
  if ($hits) err('Persona me të njëjtin ID personal e kanë ndjekur tashmë këtë modul: '.implode(', ', $hits));

  try{
    $pdo->beginTransaction();

    // Hiq ÇDO plan 'planned' për këtë student (për të shmangur rastin “modul X + grup Z”)
    $pdo->prepare("DELETE FROM student_course_plans WHERE student_id=:s AND status='planned'")
        ->execute([':s'=>$student_id]);

    // Vendos në grup
    $ins = $pdo->prepare("INSERT INTO course_group_students (group_id, student_id) VALUES (:g,:s)");
    $ins->execute([':g'=>$group_id, ':s'=>$student_id]);

    $pdo->commit();
  } catch(Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    err('Nuk u krye veprimi (assign).');
  }

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

  // S’lejohet plan nëse studenti është në ndonjë grup
  if (studentInAnyGroup($pdo, $student_id)) {
    err('Ky student është në një grup. Hiqe nga grupi përpara ndryshimit të modulit.');
  }

  // S’lejohet plan nëse (ai vetë) e ka ndjekur më parë këtë modul
  if (studentHasTakenCourse($pdo, $student_id, $course_id)) {
    err('Ky student e ka ndjekur më parë këtë modul — nuk lejohet plan për të njëjtin modul.');
  }

  // Dhe po ashtu s’lejohet nëse dikush me të njëjtin personal_number e ka ndjekur modulim
  $hits = anyPersonWithSamePNHasTakenCourse($pdo, $student_id, $course_id);
  if ($hits) {
    err('Persona me të njëjtin ID personal e kanë ndjekur tashmë këtë modul: '.implode(', ', $hits));
  }

  try{
    $pdo->beginTransaction();

    // Lejo vetëm 1 plan aktiv: fshi planet e tjerë (kurse të tjerë), por ruaj target-in
    $pdo->prepare("
      DELETE FROM student_course_plans
      WHERE student_id=:s AND status='planned' AND course_id<>:c
    ")->execute([':s'=>$student_id, ':c'=>$course_id]);

    // UPSERT mbi (student_id, course_id) => status='planned'
    $up = $pdo->prepare("
      INSERT INTO student_course_plans (student_id, course_id, status, created_at)
      VALUES (:s, :c, 'planned', NOW())
      ON DUPLICATE KEY UPDATE status=VALUES(status), created_at=VALUES(created_at)
    ");
    $up->execute([':s'=>$student_id, ':c'=>$course_id]);

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
