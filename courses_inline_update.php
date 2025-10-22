<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

header('Content-Type: application/json; charset=UTF-8');

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* Guard: vetëm admin ose editor i loguar */
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Nuk jeni i autentikuar.']); exit;
}
$userStmt = $pdo->prepare("
    SELECT u.id, r.name AS role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id = :uid LIMIT 1
");
$userStmt->execute([':uid'=>$_SESSION['user_id']]);
$me = $userStmt->fetch(PDO::FETCH_ASSOC);

$meRole = strtolower((string)($me['role_name'] ?? ''));
if (!in_array($meRole, ['administrator','editor'], true)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Lejohet vetëm për administrator ose editor.']); exit;
}

/* Guard: Edit Mode duhet të jetë ON */
if (empty($_SESSION['courses_edit_mode'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Aktivizo mënyrën e redaktimit.']); exit;
}

/* Lexo input (JSON ose form) */
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$action    = $data['action'] ?? '';   // 'update_field' | 'move_group_course'
$csrf      = $data['csrf'] ?? '';

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'CSRF token mismatch.']); exit;
}

try {

  /* ==========================================================
     1) UPDATE FUSHAT E MODULIT (code, name, hours) – inline
     ========================================================== */
  if ($action === 'update_field') {
      $course_id = (int)($data['course_id'] ?? 0);
      $field     = trim((string)($data['field'] ?? ''));
      $value     = $data['value'] ?? null;

      if ($course_id <= 0) { throw new RuntimeException('ID kursi e pavlefshme.'); }

      /* Verifiko që kursi ekziston */
      $chk = $pdo->prepare("SELECT id FROM courses WHERE id = :id LIMIT 1");
      $chk->execute([':id'=>$course_id]);
      if (!$chk->fetch()) { throw new RuntimeException('Moduli nuk u gjet.'); }

      /* Whitelist fushash */
      $allowed = ['code','name','hours'];
      if (!in_array($field, $allowed, true)) {
          throw new RuntimeException('Fusha nuk lejohet për redaktim.');
      }

      if ($field === 'code') {
          $v = trim((string)$value);
          if ($v === '') throw new RuntimeException('Kodi është i detyrueshëm.');
          $q = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE code=:v AND id<>:id");
          $q->execute([':v'=>$v, ':id'=>$course_id]);
          if ((int)$q->fetchColumn() > 0) throw new RuntimeException('Ky kod kursi përdoret nga modul tjetër.');
          $pdo->prepare("UPDATE courses SET code=:v WHERE id=:id")->execute([':v'=>$v, ':id'=>$course_id]);

          if (function_exists('qta_audit_log')) {
            qta_audit_log($pdo, 'course.update_field', ['course_id'=>$course_id, 'field'=>'code', 'value'=>$v, 'actor'=>$_SESSION['user_id'] ?? null]);
          }
          echo json_encode(['ok'=>true,'display'=>$v]); exit;

      } elseif ($field === 'name') {
          $v = trim((string)$value);
          if ($v === '') throw new RuntimeException('Emri është i detyrueshëm.');
          $pdo->prepare("UPDATE courses SET name=:v WHERE id=:id")->execute([':v'=>$v, ':id'=>$course_id]);

          if (function_exists('qta_audit_log')) {
            qta_audit_log($pdo, 'course.update_field', ['course_id'=>$course_id, 'field'=>'name', 'value'=>$v, 'actor'=>$_SESSION['user_id'] ?? null]);
          }
          echo json_encode(['ok'=>true,'display'=>$v]); exit;

      } elseif ($field === 'hours') {
          $v = trim((string)$value);
          if ($v === '' || !ctype_digit($v) || (int)$v < 1 || (int)$v > 65535) {
              throw new RuntimeException('“Orë” duhet të jetë numër i plotë ≥ 1.');
          }
          $iv = (int)$v;
          $pdo->prepare("UPDATE courses SET hours=:v WHERE id=:id")->execute([':v'=>$iv, ':id'=>$course_id]);

          if (function_exists('qta_audit_log')) {
            qta_audit_log($pdo, 'course.update_field', ['course_id'=>$course_id, 'field'=>'hours', 'value'=>$iv, 'actor'=>$_SESSION['user_id'] ?? null]);
          }
          echo json_encode(['ok'=>true,'display'=>$iv]); exit;
      }

      throw new RuntimeException('Fusha e panjohur.');
  }

  /* ==========================================================
     2) ZHVENDOS GRUPIN TE MODUL TJETËR
     ========================================================== */
  if ($action === 'move_group_course') {
      $group_id = (int)($data['group_id'] ?? 0);
      $new_course_id = (int)($data['new_course_id'] ?? 0);

      if ($group_id <= 0 || $new_course_id <= 0) throw new RuntimeException('Të dhëna të pavlefshme.');
      // ekziston grupi?
      $gq = $pdo->prepare("SELECT cg.id, cg.course_id FROM course_groups cg WHERE cg.id=:g LIMIT 1");
      $gq->execute([':g'=>$group_id]);
      $g = $gq->fetch(PDO::FETCH_ASSOC);
      if (!$g) throw new RuntimeException('Grupi nuk u gjet.');

      // ekziston kursi target?
      $cq = $pdo->prepare("SELECT id FROM courses WHERE id=:id LIMIT 1");
      $cq->execute([':id'=>$new_course_id]);
      if (!$cq->fetch()) throw new RuntimeException('Moduli target nuk u gjet.');

      // bëje zhvendosjen
      $pdo->prepare("UPDATE course_groups SET course_id=:c WHERE id=:g")->execute([':c'=>$new_course_id, ':g'=>$group_id]);

      if (function_exists('qta_audit_log')) {
        qta_audit_log($pdo, 'group.move_course', [
          'group_id'=>$group_id,
          'old_course_id'=>(int)$g['course_id'],
          'new_course_id'=>$new_course_id,
          'actor_user_id'=>$_SESSION['user_id'] ?? null
        ]);
      }

      echo json_encode(['ok'=>true, 'moved'=>true]); exit;
  }

  throw new RuntimeException('Veprim i panjohur.');

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
