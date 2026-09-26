<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/curriculum.php';

header('Content-Type: application/json; charset=UTF-8');

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* Guard: vetëm admin ose editor i loguar */
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Seanca ka mbaruar. Hyr sërish në llogari.']); exit;
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
  echo json_encode(['ok'=>false,'error'=>'Vetëm stafi i QTA mund t\'i ndryshojë kurset.']); exit;
}

/* Guard: ndryshimet duhet të jenë të hapura. Faqja përdor kyçin e përbashkët
   'edit_mode'; 'courses_edit_mode' pranohet ende për pajtueshmëri. */
if (empty($_SESSION['edit_mode']) && empty($_SESSION['courses_edit_mode'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Ndryshimet janë të mbyllura. Shtyp "Lejo ndryshimet" dhe provo sërish.']); exit;
}

/* Lexo input (JSON ose form) */
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$action    = $data['action'] ?? '';   // 'update_field' | 'move_group_course'
$csrf      = $data['csrf'] ?? '';

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.']); exit;
}

try {

  /* ==========================================================
     1) UPDATE FUSHAT E KURSIT (code, name, hours) – inline
     ========================================================== */
  if ($action === 'update_field') {
      $course_id = (int)($data['course_id'] ?? 0);
      $field     = trim((string)($data['field'] ?? ''));
      $value     = $data['value'] ?? null;

      if ($course_id <= 0) { throw new RuntimeException('Kursi nuk u gjet. Rifresko faqen.'); }

      /* Verifiko që kursi ekziston */
      $chk = $pdo->prepare("SELECT id FROM courses WHERE id = :id LIMIT 1");
      $chk->execute([':id'=>$course_id]);
      if (!$chk->fetch()) { throw new RuntimeException('Kursi nuk u gjet. Rifresko faqen.'); }

      /* Whitelist fushash */
      $allowed = ['code','name','hours'];
      if (!in_array($field, $allowed, true)) {
          throw new RuntimeException('Kjo fushë nuk mund të ndryshohet këtu.');
      }

      if ($field === 'code') {
          $v = trim((string)$value);
          if ($v === '') throw new RuntimeException('Shkruaj kodin e kursit.');
          $q = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE code=:v AND id<>:id");
          $q->execute([':v'=>$v, ':id'=>$course_id]);
          if ((int)$q->fetchColumn() > 0) throw new RuntimeException('Ky kod i përket një kursi tjetër. Zgjidh një kod tjetër.');
          $pdo->prepare("UPDATE courses SET code=:v WHERE id=:id")->execute([':v'=>$v, ':id'=>$course_id]);

          if (function_exists('qta_audit_log')) {
            qta_audit_log($pdo, 'course.update_field', ['course_id'=>$course_id, 'field'=>'code', 'value'=>$v, 'actor'=>$_SESSION['user_id'] ?? null]);
          }
          echo json_encode(['ok'=>true,'display'=>$v]); exit;

      } elseif ($field === 'name') {
          $v = trim((string)$value);
          if ($v === '') throw new RuntimeException('Shkruaj emrin e kursit.');
          $pdo->prepare("UPDATE courses SET name=:v WHERE id=:id")->execute([':v'=>$v, ':id'=>$course_id]);

          if (function_exists('qta_audit_log')) {
            qta_audit_log($pdo, 'course.update_field', ['course_id'=>$course_id, 'field'=>'name', 'value'=>$v, 'actor'=>$_SESSION['user_id'] ?? null]);
          }
          echo json_encode(['ok'=>true,'display'=>$v]); exit;

      } elseif ($field === 'hours') {
          $v = trim((string)$value);
          if ($v === '' || !ctype_digit($v) || (int)$v < 1 || (int)$v > 65535) {
              throw new RuntimeException('Orët duhet të jenë një numër i plotë, p.sh. 40.');
          }
          $iv = (int)$v;
          /* Orët e kursit nuk ulen nën shumën e moduleve të tij. */
          qta_tx($pdo, function () use ($pdo, $course_id, $iv): void {
              $course = qta_curriculum_lock_course($pdo, $course_id);
              qta_curriculum_assert_course_hours($pdo, $course, $iv);
              $pdo->prepare("UPDATE courses SET hours=:v WHERE id=:id")->execute([':v'=>$iv, ':id'=>$course_id]);
          });

          if (function_exists('qta_audit_log')) {
            qta_audit_log($pdo, 'course.update_field', ['course_id'=>$course_id, 'field'=>'hours', 'value'=>$iv, 'actor'=>$_SESSION['user_id'] ?? null]);
          }
          echo json_encode(['ok'=>true,'display'=>$iv]); exit;
      }

      throw new RuntimeException('Kjo fushë nuk mund të ndryshohet këtu.');
  }

  /* ==========================================================
     2) ZHVENDOS GRUPIN (E MËPARSHËM) TE KURS TJETËR
     ========================================================== */
  if ($action === 'move_group_course') {
      $group_id = (int)($data['group_id'] ?? 0);
      $new_course_id = (int)($data['new_course_id'] ?? 0);

      $force = (int)($data['force'] ?? 0);
      if ($group_id <= 0 || $new_course_id <= 0) throw new RuntimeException('Zgjidh kursin ku do të kalojë grupi.');
      // ekziston grupi?
      $gq = $pdo->prepare("SELECT cg.id, cg.course_id, cg.is_completed, cg.model FROM course_groups cg WHERE cg.id=:g LIMIT 1");
      $gq->execute([':g'=>$group_id]);
      $g = $gq->fetch(PDO::FETCH_ASSOC);
      if (!$g) throw new RuntimeException('Grupi nuk u gjet. Rifresko faqen.');
      /* Një grup me orar mësimi e ka orarin të ndërtuar nga temat e kursit të vet. */
      if (($g['model'] ?? 'legacy') === 'scheduled') {
          throw new RuntimeException('Grupi #' . $group_id . ' ka orar mësimi të ndërtuar nga temat e këtij kursi, prandaj nuk kalon te një kurs tjetër. Nëse kursi është gabim, fshije grupin dhe krijoje sërish te "Grupet".');
      }
      if ((int)$g['course_id'] === $new_course_id) throw new RuntimeException('Grupi është tashmë në këtë kurs.');

      /* Njësoj si te "Grupet": grupi i mbyllur ndryshohet vetëm me konfirmim */
      if ((int)$g['is_completed'] === 1 && !$force) {
          throw new RuntimeException('Ky grup është i mbyllur. Konfirmo që do ta ndryshosh.');
      }

      // ekziston kursi target?
      $cq = $pdo->prepare("SELECT id FROM courses WHERE id=:id LIMIT 1");
      $cq->execute([':id'=>$new_course_id]);
      if (!$cq->fetch()) throw new RuntimeException('Kursi i zgjedhur nuk u gjet. Rifresko faqen.');

      /* Njësoj si te "Grupet": askush në grup nuk duhet ta ketë ndjekur tashmë kursin e ri */
      $pnStmt = $pdo->prepare("
          SELECT DISTINCT p.personal_number
          FROM course_group_students cgs
          JOIN students s ON s.id = cgs.student_id
          JOIN persons  p ON p.id = s.person_id
          WHERE cgs.group_id = ? AND p.personal_number IS NOT NULL AND p.personal_number <> ''
      ");
      $pnStmt->execute([$group_id]);
      $pnList = $pnStmt->fetchAll(PDO::FETCH_COLUMN);
      if ($pnList) {
          $phPn = implode(',', array_fill(0, count($pnList), '?'));
          $confPN = $pdo->prepare("
              SELECT DISTINCT s.nr_amze, p.personal_number
              FROM course_group_students cgs
              JOIN students s ON s.id = cgs.student_id
              JOIN persons  p ON p.id = s.person_id
              JOIN course_groups cg ON cg.id = cgs.group_id
              WHERE cg.course_id = ? AND cgs.group_id <> ? AND p.personal_number IN ($phPn)
          ");
          $confPN->execute([$new_course_id, $group_id, ...$pnList]);
          $hit = $confPN->fetchAll(PDO::FETCH_ASSOC);
          if ($hit) {
              $items = array_map(fn($r) => (string)($r['nr_amze'] ?: $r['personal_number']), $hit);
              throw new RuntimeException('Grupi nuk u zhvendos: disa persona e kanë ndjekur tashmë kursin e ri (nr. i amzës: ' . implode(', ', $items) . ').');
          }
      }

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

  throw new RuntimeException('Ky veprim nuk njihet. Rifresko faqen dhe provo sërish.');

} catch (Throwable $e) {
    http_response_code(400);
    $out = ['ok'=>false,'error'=>$e->getMessage()];
    if ($e instanceof QtaUserError) {
        $out['code'] = $e->data['code'] ?? null;
        if (isset($e->data['dialog'])) $out['dialog'] = $e->data['dialog'];
    }
    echo json_encode($out);
}
