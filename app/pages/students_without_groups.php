<?php
declare(strict_types=1);

/**
 * students_without_groups.php — Adresë e vjetër, vetëm për lidhjet e ruajtura.
 *
 * "Kursantët pa grup" nuk është më faqe më vete: puna e caktimit në grup është te
 * "Kursantët" me çipin "Pa grup" (students.php?status=no_group). Ruajtjet e
 * kursit dhe të grupit janë te app/actions/student_assignment.php.
 */

$qs = ['status' => 'no_group'];
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
  $qs['q'] = $q;
}
$course = trim((string)($_GET['course_id'] ?? ''));
if ($course !== '' && ctype_digit($course)) {
  $qs['course_id'] = $course;
}
header('Location: students.php?' . http_build_query($qs), true, 302);
exit;
