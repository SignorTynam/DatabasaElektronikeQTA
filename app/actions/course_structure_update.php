<?php
declare(strict_types=1);

/**
 * course_structure_update.php — Ndryshimet e strukturës së kursit (JSON).
 *
 * Veprimet: add_module, update_module, delete_module, add_topic, update_topic,
 * delete_topic, move (module|topic, lart/poshtë), normalize, set_course_hours,
 * set_module_hours, update_course (emri, kodi, orët).
 *
 * Vetëm administrator/editor, me ndryshimet të hapura dhe tokenin e faqes.
 * Çdo përgjigje e suksesshme kthen strukturën e rivizatuar dhe gatishmërinë.
 */

session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/staff_guard.php';
require_once __DIR__ . '/../shared/curriculum.php';
require_once __DIR__ . '/../shared/partials/course_structure.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  qta_json_out(['ok' => false, 'error' => 'Kjo adresë pranon vetëm ruajtje nga faqja e kursit.'], 405);
}
qta_json_require_staff($pdo);
$data = qta_json_input();
qta_json_require_csrf($data);
qta_json_require_edit_mode();

$action = (string)($data['action'] ?? '');
$courseId = (int)($data['course_id'] ?? 0);

try {
  $message = '';
  $focus = null;

  switch ($action) {
    case 'add_module': {
      $id = qta_curriculum_add_module($pdo, $courseId, $data['title'] ?? '', $data['hours'] ?? '', $data['position'] ?? null);
      $m = qta_module_find($pdo, $id);
      $message = 'Moduli "' . $m['title'] . '" u shtua në vendin ' . $m['position'] . '.';
      $focus = ['kind' => 'module', 'id' => $id, 'target' => 'quick'];
      break;
    }
    case 'update_module': {
      $m = qta_module_find($pdo, (int)($data['module_id'] ?? 0));
      if (!$m || $m['course_id'] !== $courseId) throw new QtaUserError('Moduli nuk u gjet. Rifresko faqen.');
      $fields = array_intersect_key($data, array_flip(['title', 'hours', 'position']));
      $m = qta_curriculum_update_module($pdo, $m['id'], $fields);
      $message = 'Ndryshimet e modulit "' . $m['title'] . '" u ruajtën.';
      $focus = ['kind' => 'module', 'id' => $m['id'], 'target' => 'edit'];
      break;
    }
    case 'delete_module': {
      $m = qta_module_find($pdo, (int)($data['module_id'] ?? 0));
      if (!$m || $m['course_id'] !== $courseId) throw new QtaUserError('Moduli nuk u gjet. Ndoshta u fshi tashmë — rifresko faqen.');
      $r = qta_curriculum_delete_module($pdo, $m['id']);
      $message = 'Moduli "' . $r['title'] . '" u fshi' . ($r['topics'] ? ' bashkë me ' . qta_plural((int)$r['topics'], 'temë', 'tema') : '') . '.';
      $focus = ['kind' => 'heading'];
      break;
    }
    case 'add_topic': {
      $m = qta_module_find($pdo, (int)($data['module_id'] ?? 0));
      if (!$m || $m['course_id'] !== $courseId) throw new QtaUserError('Moduli nuk u gjet. Rifresko faqen.');
      $id = qta_curriculum_add_topic($pdo, $m['id'], $data['title'] ?? '', $data['hours'] ?? '', $data['position'] ?? null);
      $t = qta_topic_find($pdo, $id);
      $message = 'Tema "' . $t['title'] . '" u shtua te "' . $m['title'] . '".';
      $focus = ['kind' => 'module', 'id' => $m['id'], 'target' => 'quick'];
      break;
    }
    case 'update_topic': {
      $t = qta_topic_find($pdo, (int)($data['topic_id'] ?? 0));
      if (!$t || $t['course_id'] !== $courseId) throw new QtaUserError('Tema nuk u gjet. Rifresko faqen.');
      $fields = array_intersect_key($data, array_flip(['title', 'hours', 'position']));
      $t = qta_curriculum_update_topic($pdo, $t['id'], $fields);
      $message = 'Ndryshimet e temës "' . $t['title'] . '" u ruajtën.';
      $focus = ['kind' => 'topic', 'id' => $t['id'], 'target' => 'edit'];
      break;
    }
    case 'delete_topic': {
      $t = qta_topic_find($pdo, (int)($data['topic_id'] ?? 0));
      if (!$t || $t['course_id'] !== $courseId) throw new QtaUserError('Tema nuk u gjet. Ndoshta u fshi tashmë — rifresko faqen.');
      qta_curriculum_delete_topic($pdo, $t['id']);
      $message = 'Tema "' . $t['title'] . '" u fshi nga "' . $t['module_title'] . '".';
      $focus = ['kind' => 'module', 'id' => $t['module_id'], 'target' => 'quick'];
      break;
    }
    case 'move': {
      $kind = (string)($data['kind'] ?? '') === 'topic' ? 'topic' : 'module';
      $id = (int)($data['id'] ?? 0);
      $owner = $kind === 'topic' ? qta_topic_find($pdo, $id) : qta_module_find($pdo, $id);
      if (!$owner || (int)$owner['course_id'] !== $courseId) throw new QtaUserError(($kind === 'topic' ? 'Tema' : 'Moduli') . ' nuk u gjet. Rifresko faqen.');
      $dir = (int)($data['dir'] ?? 0) < 0 ? -1 : 1;
      $r = qta_curriculum_move($pdo, $kind, $id, $dir);
      $message = ($kind === 'topic' ? 'Tema' : 'Moduli') . ' "' . $r['title'] . '" është tani në vendin ' . $r['position'] . ' nga ' . $r['count'] . '.';
      $focus = ['kind' => $kind, 'id' => $id, 'target' => 'move', 'dir' => $dir];
      break;
    }
    case 'normalize': {
      qta_curriculum_normalize($pdo, $courseId);
      $message = 'Radha u rregullua: modulet dhe temat janë numëruar 1, 2, 3 … siç shfaqen.';
      $focus = ['kind' => 'heading'];
      break;
    }
    case 'set_course_hours': {
      qta_curriculum_set_course_hours($pdo, $courseId, $data['value'] ?? '');
      $message = 'Orët e kursit u bënë ' . (int)$data['value'] . '.';
      $focus = ['kind' => 'heading'];
      break;
    }
    case 'set_module_hours': {
      $m = qta_module_find($pdo, (int)($data['module_id'] ?? 0));
      if (!$m || $m['course_id'] !== $courseId) throw new QtaUserError('Moduli nuk u gjet. Rifresko faqen.');
      $m = qta_curriculum_update_module($pdo, $m['id'], ['hours' => $data['value'] ?? '']);
      $message = 'Orët e modulit "' . $m['title'] . '" u bënë ' . $m['hours'] . '.';
      $focus = ['kind' => 'heading'];
      break;
    }
    case 'update_course': {
      qta_tx($pdo, function () use ($pdo, $courseId, $data): void {
        $course = qta_curriculum_lock_course($pdo, $courseId);
        $name = qta_clean_text($data['name'] ?? '', 200);
        $code = qta_clean_text($data['code'] ?? '', 50);
        if ($name === '') throw new QtaUserError('Shkruaj emrin e kursit.');
        if ($code === '') throw new QtaUserError('Shkruaj kodin e kursit, p.sh. MSO-01.');
        $hours = qta_curriculum_hours($data['hours'] ?? '', 'course');
        qta_curriculum_assert_course_hours($pdo, $course, $hours);
        $dup = $pdo->prepare('SELECT COUNT(*) FROM courses WHERE code = ? AND id <> ?');
        $dup->execute([$code, $courseId]);
        if ((int)$dup->fetchColumn() > 0) throw new QtaUserError('Ky kod i përket një kursi tjetër. Zgjidh një kod tjetër.');
        if ($name !== $course['name'] || $code !== $course['code'] || $hours !== (int)$course['hours']) {
          $pdo->prepare('UPDATE courses SET name = ?, code = ?, hours = ? WHERE id = ?')->execute([$name, $code, $hours, $courseId]);
        }
      });
      $message = 'Ndryshimet e kursit u ruajtën.';
      $focus = ['kind' => 'heading'];
      break;
    }
    default:
      throw new QtaUserError('Ky veprim nuk njihet. Rifresko faqen dhe provo sërish.');
  }

  $course = qta_course_find($pdo, $courseId);
  if (!$course) throw new QtaUserError('Kursi nuk u gjet. Rifresko faqen.');
  $modules = qta_course_modules($pdo, $courseId);
  $check = qta_course_check($course, $modules);
  qta_json_out([
    'ok' => true,
    'message' => $message,
    'focus' => $focus,
    'ready' => $check['ready'],
    'course' => ['name' => $course['name'], 'code' => $course['code'], 'hours' => $course['hours']],
    'html' => qta_render_course_structure($course, $modules, $check, true, qta_course_usage($pdo, $courseId)),
  ]);
} catch (Throwable $e) {
  qta_json_fail($e);
}
