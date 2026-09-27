<?php
declare(strict_types=1);

/**
 * student_assignment.php — Kursi i zgjedhur dhe caktimi në grup (JSON).
 *
 * Përdoret nga "Kursantët" kur është zgjedhur "Pa grup", "Gati për grup" ose
 * "Pa kurs" (students.php + students.js). Më parë këto ruajtje ishin brenda
 * faqes së vjetër "Kursantët pa grup"; rregullat dhe mesazhet mbeten të njëjtat.
 *
 * Veprimet:
 *   assign_to_group      vendos kursantin në grup; heq kursin që priste grup
 *   set_student_plan     zgjedh (ose ndërron) kursin që pret grup
 *   remove_student_plan  heq kursin e zgjedhur
 *
 * Të gjitha: vetëm administrator/editor, tokeni i faqes dhe ndryshimet të hapura
 * (kontrolli bëhet këtu, jo vetëm te butonat). Historiku i ndryshimeve regjistrohet
 * nga baza (qta_audit_attach).
 */

session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/staff_guard.php';
require_once __DIR__ . '/../shared/domain.php';
require_once __DIR__ . '/../shared/group_members.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  qta_json_out(['ok' => false, 'error' => 'Kjo adresë pranon vetëm ruajtje nga lista e kursantëve.'], 405);
}
qta_json_require_staff($pdo);
$data = qta_json_input();
qta_json_require_csrf($data);
qta_json_require_edit_mode();

$action = (string)($data['action'] ?? '');

/** A e ka ndjekur ky person (sipas numrit personal) këtë kurs në ndonjë grup? */
$attendedCourse = static function (PDO $pdo, int $studentId, int $courseId): bool {
  $pn = $pdo->prepare('SELECT p.personal_number FROM students s JOIN persons p ON p.id = s.person_id WHERE s.id = ?');
  $pn->execute([$studentId]);
  $number = (string)($pn->fetchColumn() ?: '');
  if ($number === '') {
    return false;
  }
  $q = $pdo->prepare('
    SELECT 1
    FROM course_group_students cgs
    JOIN students s ON s.id = cgs.student_id
    JOIN persons p ON p.id = s.person_id
    JOIN course_groups cg ON cg.id = cgs.group_id
    WHERE cg.course_id = ? AND p.personal_number = ?
    LIMIT 1
  ');
  $q->execute([$courseId, $number]);
  return (bool)$q->fetchColumn();
};

try {
  switch ($action) {
    case 'assign_to_group': {
      $studentId = (int)($data['student_id'] ?? 0);
      $groupId = (int)($data['group_id'] ?? 0);
      if ($studentId <= 0 || $groupId <= 0) {
        throw new QtaUserError('Zgjidh kursantin dhe grupin.');
      }
      qta_tx($pdo, static function () use ($pdo, $studentId, $groupId, $attendedCourse): void {
        /* Grupi kyçet gjatë kontrollit, që dy caktime njëkohësisht të mos kalojnë 10 vendet. */
        $gq = $pdo->prepare('SELECT id, course_id FROM course_groups WHERE id = ? FOR UPDATE');
        $gq->execute([$groupId]);
        $group = $gq->fetch(PDO::FETCH_ASSOC);
        if (!$group) {
          throw new QtaUserError('Grupi nuk u gjet. Rifresko listën.');
        }
        $mq = $pdo->prepare('SELECT COUNT(*) FROM course_group_students WHERE group_id = ?');
        $mq->execute([$groupId]);
        if ((int)$mq->fetchColumn() >= QTA_GROUP_MAX_MEMBERS) {
          throw new QtaUserError('Ky grup është plot (10 kursantë). Zgjidh një grup tjetër.');
        }
        $sq = $pdo->prepare('SELECT id FROM students WHERE id = ?');
        $sq->execute([$studentId]);
        if (!$sq->fetchColumn()) {
          throw new QtaUserError('Kursanti nuk u gjet. Rifresko listën.');
        }
        $in = $pdo->prepare('
          SELECT cgs.group_id, c.name
          FROM course_group_students cgs
          JOIN course_groups cg ON cg.id = cgs.group_id
          JOIN courses c ON c.id = cg.course_id
          WHERE cgs.student_id = ?
          LIMIT 1
        ');
        $in->execute([$studentId]);
        if ($other = $in->fetch(PDO::FETCH_ASSOC)) {
          throw new QtaUserError((int)$other['group_id'] === $groupId
            ? 'Ky kursant është tashmë në këtë grup.'
            : 'Ky kursant është tashmë në Grupin #' . (int)$other['group_id'] . ' (' . $other['name'] . '). Një regjistrim mund të jetë vetëm në një grup.');
        }
        if ($attendedCourse($pdo, $studentId, (int)$group['course_id'])) {
          throw new QtaUserError('Ky person e ka ndjekur më parë këtë kurs, prandaj nuk mund ta ndjekë sërish.');
        }
        /* Një regjistrim nuk pret grup dhe është në grup njëkohësisht. */
        $pdo->prepare("DELETE FROM student_course_plans WHERE student_id = ? AND status = 'planned'")->execute([$studentId]);
        $pdo->prepare('INSERT INTO course_group_students (group_id, student_id) VALUES (?, ?)')->execute([$groupId, $studentId]);
      });
      qta_json_out(['ok' => true, 'message' => 'Kursanti u caktua në grup.']);
    }

    case 'set_student_plan': {
      $studentId = (int)($data['student_id'] ?? 0);
      $courseId = (int)($data['course_id'] ?? 0);
      if ($studentId <= 0 || $courseId <= 0) {
        throw new QtaUserError('Zgjidh kursantin dhe kursin.');
      }
      qta_tx($pdo, static function () use ($pdo, $studentId, $courseId, $attendedCourse): void {
        $cq = $pdo->prepare('SELECT 1 FROM courses WHERE id = ?');
        $cq->execute([$courseId]);
        if (!$cq->fetchColumn()) {
          throw new QtaUserError('Kursi nuk u gjet. Rifresko listën.');
        }
        $inGroup = $pdo->prepare('SELECT 1 FROM course_group_students WHERE student_id = ? LIMIT 1');
        $inGroup->execute([$studentId]);
        if ($inGroup->fetchColumn()) {
          throw new QtaUserError('Ky kursant është në një grup. Hiqe nga grupi para se të ndryshosh kursin.');
        }
        if ($attendedCourse($pdo, $studentId, $courseId)) {
          throw new QtaUserError('Ky person e ka ndjekur më parë këtë kurs. Zgjidh një kurs tjetër.');
        }
        /* Kursantit i mbetet vetëm një kurs që pret grup. */
        $pdo->prepare("DELETE FROM student_course_plans WHERE student_id = ? AND status = 'planned' AND course_id <> ?")->execute([$studentId, $courseId]);
        $pdo->prepare("INSERT INTO student_course_plans (student_id, course_id, status) VALUES (?, ?, 'planned')
                       ON DUPLICATE KEY UPDATE status = VALUES(status)")->execute([$studentId, $courseId]);
      });
      qta_json_out(['ok' => true, 'message' => 'Kursi u ruajt. Tani zgjidh grupin.']);
    }

    case 'remove_student_plan': {
      $studentId = (int)($data['student_id'] ?? 0);
      $courseId = (int)($data['course_id'] ?? 0);
      if ($studentId <= 0 || $courseId <= 0) {
        throw new QtaUserError('Zgjidh kursantin dhe kursin.');
      }
      $inGroup = $pdo->prepare('SELECT 1 FROM course_group_students cgs JOIN course_groups cg ON cg.id = cgs.group_id WHERE cgs.student_id = ? AND cg.course_id = ? LIMIT 1');
      $inGroup->execute([$studentId, $courseId]);
      if ($inGroup->fetchColumn()) {
        throw new QtaUserError('Ky kursant është në një grup të këtij kursi. Hiqe nga grupi së pari.');
      }
      $del = $pdo->prepare("DELETE FROM student_course_plans WHERE student_id = ? AND course_id = ? AND status = 'planned'");
      $del->execute([$studentId, $courseId]);
      if ($del->rowCount() < 1) {
        throw new QtaUserError('Ky kursant nuk ka kurs të zgjedhur.');
      }
      qta_json_out(['ok' => true, 'message' => 'Kursi u hoq. Zgjidh një kurs tjetër kur të jesh gati.']);
    }

    default:
      throw new QtaUserError('Ky veprim nuk njihet. Rifresko faqen dhe provo sërish.');
  }
} catch (Throwable $e) {
  qta_json_fail($e);
}
